<?php
// Translation job state: the immutable payload, per-batch results and the completion
// marker, stored as transients (TTL 1 hour) or durably (see AIPT_Job_Store) — plus the
// DB-row locks (finalize, named, pair) and the raw option-row API they share with the
// bulk locator and the durable job store (option_*: read straight from the database,
// compare-and-swap writes).

if (!defined('ABSPATH')) {
	exit;
}

class AIPT_Job {

	const FINALIZE_LOCK_TTL = 300;
	// Per-batch lock rows: aipt_batch_<job_id>_<index> (AIPT_Pipeline::translate_batch).
	const BATCH_LOCK_PREFIX = 'aipt_batch_';
	// Batch parts of a job: <job key>_batch_<index>.
	const BATCH_SUFFIX = '_batch_';

	private static $lock_owners = array();
	// "<post_id>|<target>" => pair lock key taken by this process. The translation group
	// can change while the lock is held (the write adds the new translation), so refresh
	// and release use the key computed at acquire time.
	private static $pair_keys = array();

	/**
	 * @param array $data    Job payload.
	 * @param bool  $durable Store it (and its batches and completion marker) in
	 *                       non-autoloaded options instead of transients, kept until delete()
	 *                       (the bulk layer deletes it after reading the outcome of
	 *                       AIPT_Finalize).
	 * @return string Job id ("d-<uuid>" when durable), '' when it could not be stored.
	 */
	public static function create(array $data, bool $durable = false): string {
		// The adapter the job was prepared with (AIPT_Pipeline refuses it under another)
		// and the payload version: 2 since 1.6 (target_state, backend).
		$data['backend'] = aipt_lang()->name();
		$data['schema']  = 2;
		$id = AIPT_Job_Store::new_id($durable);
		// A brand-new uuid key never matches an existing value, so a false here is a
		// real storage failure (oversized payload, memcached/packet limit) rather than
		// the "value unchanged" case. Bubble it up so prepare can warn the user.
		if (!AIPT_Job_Store::set($id, '', $data)) {
			return '';
		}
		return $id;
	}

	public static function get(string $id): ?array {
		$data = AIPT_Job_Store::get($id);
		return is_array($data) ? $data : null;
	}

	// get() read past the request's caches (AIPT_Job_Store::get_fresh()).
	public static function get_fresh(string $id): ?array {
		$data = AIPT_Job_Store::get_fresh($id);
		return is_array($data) ? $data : null;
	}

	public static function save(string $id, array $data): bool {
		return AIPT_Job_Store::set($id, '', $data);
	}

	// Persist one batch's results AND its token usage in a single entry so concurrent
	// batch requests never read-modify-write a shared blob (last-write-wins data loss),
	// and so usage can never drift out of sync with the saved map: one atomic write per
	// batch, eviction-symmetric (lose the payload, lose the usage, and the idempotent
	// retry rewrites both).
	public static function save_batch(string $id, int $index, array $results, array $usage = array()): bool {
		$payload = array(
			'map'   => $results,
			'usage' => array(
				'tokens_in'  => (int) ($usage['tokens_in'] ?? 0),
				'tokens_out' => (int) ($usage['tokens_out'] ?? 0),
				'cost'       => (float) ($usage['cost'] ?? 0),
			),
		);
		if (AIPT_Job_Store::set($id, self::batch_suffix($index), $payload)) {
			return true;
		}
		// set() already counts an equal stored value as saved; a false here is a failed
		// write over a different stored value (another request saved this batch first).
		// An existing readable entry counts as saved.
		return AIPT_Job_Store::get($id, self::batch_suffix($index)) !== false;
	}

	// $fresh: read past the request's caches (AIPT_Job_Store::get_fresh()).
	public static function get_batch(string $id, int $index, bool $fresh = false) {
		$batch = $fresh ? AIPT_Job_Store::get_fresh($id, self::batch_suffix($index)) : AIPT_Job_Store::get($id, self::batch_suffix($index));
		return is_array($batch) ? self::batch_map($batch) : $batch;
	}

	// Collect every batch for a job. Returns the merged item_id => text map, or null if
	// any batch in [0, $total) is missing.
	public static function get_batches(string $id, int $total): ?array {
		if (!AIPT_Job_Store::is_valid($id)) {
			return null;
		}
		$results = array();
		for ($index = 0; $index < $total; $index++) {
			$batch = AIPT_Job_Store::get($id, self::batch_suffix($index));
			if (!is_array($batch)) {
				return null;
			}
			$results += self::batch_map($batch);
		}
		return $results;
	}

	// Extract the translation map from a batch transient. In-flight jobs written by the
	// old code (1-hour TTL) stored the bare map without a 'map' key — treat those as a
	// legacy payload so older batches keep finalizing during the upgrade window.
	private static function batch_map(array $batch): array {
		return array_key_exists('map', $batch) ? (array) $batch['map'] : $batch;
	}

	// A durable job loses every option it has (payload, batches, completion marker) and
	// its finalize lock row: a finalize still holding it finds the job gone and leaves.
	public static function delete(string $id): void {
		if (!AIPT_Job_Store::is_valid($id)) {
			return;
		}
		AIPT_Job_Store::purge($id);
		delete_option(self::lock_key($id));
	}

	public static function delete_batches(string $id, int $total): void {
		for ($index = 0; $index < $total; $index++) {
			AIPT_Job_Store::delete($id, self::batch_suffix($index));
		}
	}

	// Sum token usage across every batch, reading the same entries save_batch() wrote.
	// A missing entry (or a legacy bare-map payload from older code) counts as 0 so
	// that cost tracking never blocks finalize.
	public static function get_usage_total(string $id, int $total): array {
		$totals = array('tokens_in' => 0, 'tokens_out' => 0, 'cost' => 0.0);
		for ($index = 0; $index < $total; $index++) {
			$batch = AIPT_Job_Store::get($id, self::batch_suffix($index));
			if (is_array($batch) && isset($batch['usage']) && is_array($batch['usage'])) {
				$usage = $batch['usage'];
				$totals['tokens_in']  += (int) ($usage['tokens_in'] ?? 0);
				$totals['tokens_out'] += (int) ($usage['tokens_out'] ?? 0);
				$totals['cost']       += (float) ($usage['cost'] ?? 0);
			}
		}
		return $totals;
	}

	// Indexes of the batches saved so far, from the option names alone (durable jobs only:
	// a transient job's parts are not listed).
	public static function saved_batch_indexes(string $id): array {
		$indexes = array();
		foreach (AIPT_Job_Store::durable_names($id, self::BATCH_SUFFIX) as $name) {
			if (preg_match('/_batch_(\d+)$/', $name, $m)) {
				$indexes[] = (int) $m[1];
			}
		}
		return $indexes;
	}

	public static function batch_lock_key(string $id, int $index): string {
		return self::BATCH_LOCK_PREFIX . $id . '_' . $index;
	}

	/**
	 * Run $fn under the named lock $key (acquire_named_lock()); $busy when it stays taken.
	 * The lock is released after $fn, and on shutdown when a fatal error or the execution
	 * time limit skips finally.
	 *
	 * @return mixed $fn's return value, or $busy.
	 */
	public static function with_named_lock(string $key, int $ttl, callable $fn, WP_Error $busy, int $tries = 50) {
		if (!self::acquire_named_lock($key, $ttl, $tries)) {
			return $busy;
		}
		register_shutdown_function(array(__CLASS__, 'release_named_lock'), $key);
		try {
			return $fn();
		} finally {
			self::release_named_lock($key);
		}
	}

	// Generic named lock backed by a DB row (same INSERT IGNORE mechanic as the
	// finalize lock). Used for usage-write serialisation across concurrent batch
	// requests. $key must be a stable, globally unique option name (e.g. 'aipt_usage_lock').
	// $ttl: seconds before a held lock is considered stale and can be stolen.
	// Spins up to $tries times with $usleep_us microseconds between attempts.
	// Returns false only after exhausting all tries — callers should degrade gracefully.
	public static function acquire_named_lock(string $key, int $ttl, int $tries = 50, int $usleep_us = 20000): bool {
		for ($attempt = 0; $attempt < $tries; $attempt++) {
			$now        = time();
			$lock_value = self::new_lock_value($now);
			if (self::option_insert($key, $lock_value)) {
				self::$lock_owners[$key] = $lock_value;
				return true;
			}

			// Read the stored timestamp directly from the DB — the object cache may
			// still reflect our own failed INSERT or a previous holder's write.
			$stored_value = self::option_raw($key);
			if (self::is_fresh($stored_value, $ttl)) {
				// Lock is fresh — somebody else holds it; wait and retry.
				usleep($usleep_us);
				continue;
			}

			// Delete only the stale value we actually observed. If another contender
			// replaced it first, leave that new owner's lock intact.
			if ($stored_value !== '' && !self::option_delete_if($key, $stored_value)) {
				usleep($usleep_us);
				continue;
			}

			$lock_value = self::new_lock_value($now);
			if (self::option_insert($key, $lock_value)) {
				self::$lock_owners[$key] = $lock_value;
				return true;
			}

			usleep($usleep_us);
		}

		return false;
	}

	// Release a lock acquired via acquire_named_lock().
	public static function release_named_lock(string $key): void {
		if (!isset(self::$lock_owners[$key])) {
			return;
		}
		$lock_value = self::$lock_owners[$key];
		unset(self::$lock_owners[$key]);
		self::option_delete_if($key, $lock_value);
	}

	public static function acquire_finalize_lock(string $id): bool {
		if (!AIPT_Job_Store::is_valid($id)) {
			return false;
		}

		$key = self::lock_key($id);
		$now = time();
		$lock_value = self::new_lock_value($now);

		// Atomic test-and-set. add_option() is not atomic (get_option check +
		// INSERT ... ON DUPLICATE KEY UPDATE), so two concurrent finalizes can both
		// "win" it. INSERT IGNORE wins only when it actually inserts the row, mirroring
		// WP_Upgrader::create_lock() in core.
		if (self::option_insert($key, $lock_value)) {
			self::$lock_owners[$key] = $lock_value;
			return true;
		}

		// Stale takeover. Read the stored timestamp straight from the DB (the object
		// cache may not know about our raw INSERT). If it is still fresh, somebody else
		// holds the lock; otherwise drop it and try the atomic insert one more time.
		$stored_value = self::option_raw($key);
		if (self::is_fresh($stored_value, self::FINALIZE_LOCK_TTL)) {
			return false;
		}

		if ($stored_value !== '' && !self::option_delete_if($key, $stored_value)) {
			return false;
		}

		$lock_value = self::new_lock_value($now);
		if (!self::option_insert($key, $lock_value)) {
			return false;
		}
		self::$lock_owners[$key] = $lock_value;
		return true;
	}

	// Non-blocking lock on one (source post, target language) pair, so a CLI run and
	// the editor never write the same translation at once. Unlike the named lock, the
	// holder stores its own TTL and role in the row: every contender judges staleness by
	// the holder's TTL (a 30-minute CLI lock is not stolen by a 5-minute editor check),
	// and the editor can tell who is busy. Row value: "<time>:<uuid>:<ttl>:<holder>".
	public static function acquire_pair_lock(int $post_id, string $target, int $ttl, string $holder): bool {
		$key = self::pair_lock_key($post_id, $target);
		$now = time();

		$lock_value = self::new_lock_value($now) . ':' . $ttl . ':' . $holder;
		if (self::option_insert($key, $lock_value)) {
			self::$lock_owners[$key]                   = $lock_value;
			self::$pair_keys[$post_id . '|' . $target] = $key;
			return true;
		}

		$stored_value = self::option_raw($key);
		if (self::pair_lock_is_fresh($stored_value, $now)) {
			return false;
		}
		if ($stored_value !== '' && !self::option_delete_if($key, $stored_value)) {
			return false;
		}

		$lock_value = self::new_lock_value($now) . ':' . $ttl . ':' . $holder;
		if (!self::option_insert($key, $lock_value)) {
			return false;
		}
		self::$lock_owners[$key]                   = $lock_value;
		self::$pair_keys[$post_id . '|' . $target] = $key;
		return true;
	}

	// Role of the current fresh holder ('cli', 'editor', 'auto'), or '' when the pair is free.
	public static function pair_lock_holder(int $post_id, string $target): string {
		$stored_value = self::option_raw(self::pair_lock_key($post_id, $target));
		if (!self::pair_lock_is_fresh($stored_value, time())) {
			return '';
		}
		$parts = explode(':', $stored_value);
		return (string) ($parts[3] ?? '');
	}

	// Heartbeat for long records: move our own lock's timestamp forward so the TTL
	// counts from the last finished batch. No-op when this process does not hold it.
	public static function refresh_pair_lock(int $post_id, string $target): void {
		$key = self::$pair_keys[$post_id . '|' . $target] ?? '';
		if ($key === '' || !isset(self::$lock_owners[$key])) {
			return;
		}
		$parts    = explode(':', self::$lock_owners[$key]);
		$parts[0] = (string) time();
		$value    = implode(':', $parts);
		if (self::option_replace_if($key, self::$lock_owners[$key], $value)) {
			self::$lock_owners[$key] = $value;
		}
	}

	public static function release_pair_lock(int $post_id, string $target): void {
		$pair = $post_id . '|' . $target;
		if (!isset(self::$pair_keys[$pair])) {
			return;
		}
		self::release_named_lock(self::$pair_keys[$pair]);
		unset(self::$pair_keys[$pair]);
	}

	private static function pair_lock_is_fresh(string $stored_value, int $now): bool {
		if ($stored_value === '') {
			return false;
		}
		$parts     = explode(':', $stored_value);
		$locked_at = (int) $parts[0];
		$ttl       = (int) ($parts[2] ?? 0);
		return $locked_at && $ttl > 0 && $locked_at > $now - $ttl;
	}

	// Keyed per translation group (AIPT_Lang::group_id()), not per source post: two
	// members of one group translated into the same language write the same target.
	private static function pair_lock_key(int $post_id, string $target): string {
		return 'aipt_pair_lock_' . aipt_lang()->group_id($post_id) . '_' . sanitize_key($target);
	}

	private static function new_lock_value(int $now): string {
		return $now . ':' . wp_generate_uuid4();
	}

	// A "<time>:…" lock row taken less than $ttl seconds ago.
	private static function is_fresh(string $stored_value, int $ttl): bool {
		$locked_at = (int) $stored_value;
		return $locked_at && $locked_at > time() - $ttl;
	}

	// ---- Raw option rows -------------------------------------------------------
	// Rows other requests insert, swap and delete concurrently (locks, bulk locators,
	// durable job parts): read straight from the database (the object cache may not know
	// about a raw INSERT or another request's compare-and-swap), written only with a
	// compare on the value read; every write drops the key and 'notoptions' from the
	// object cache so later reads see the row as it is.

	// Raw stored value, '' when there is no row.
	public static function option_raw(string $key): string {
		global $wpdb;
		return (string) $wpdb->get_var($wpdb->prepare(
			"SELECT option_value FROM {$wpdb->options} WHERE option_name = %s LIMIT 1",
			$key
		));
	}

	// Insert a non-autoloaded row; false when a row with this name exists.
	public static function option_insert(string $key, string $value): bool {
		global $wpdb;
		$inserted = $wpdb->query($wpdb->prepare(
			"INSERT IGNORE INTO {$wpdb->options} (option_name, option_value, autoload) VALUES (%s, %s, 'off')",
			$key,
			$value
		));
		self::option_forget($key);
		return $inserted === 1;
	}

	// Replace the row's value only while it still holds $raw.
	public static function option_replace_if(string $key, string $raw, string $new): bool {
		global $wpdb;
		$updated = $wpdb->query($wpdb->prepare(
			"UPDATE {$wpdb->options} SET option_value = %s WHERE option_name = %s AND option_value = %s",
			$new,
			$key,
			$raw
		));
		self::option_forget($key);
		return $updated === 1;
	}

	// Delete the row only while it still holds $raw.
	public static function option_delete_if(string $key, string $raw): bool {
		global $wpdb;
		$deleted = $wpdb->query($wpdb->prepare(
			"DELETE FROM {$wpdb->options} WHERE option_name = %s AND option_value = %s",
			$key,
			$raw
		));
		self::option_forget($key);
		return $deleted === 1;
	}

	public static function option_forget(string $key): void {
		wp_cache_delete($key, 'options');
		wp_cache_delete('notoptions', 'options');
	}

	// Whether a finalize of this job holds its lock right now (a fresh row).
	public static function finalize_lock_held(string $id): bool {
		if (!AIPT_Job_Store::is_valid($id)) {
			return false;
		}
		return self::is_fresh(self::option_raw(self::lock_key($id)), self::FINALIZE_LOCK_TTL);
	}

	// Whether a batch request of this job holds its lock right now (batch_lock_key() rows).
	public static function batch_lock_held(string $id, int $ttl): bool {
		global $wpdb;
		if (!AIPT_Job_Store::is_valid($id)) {
			return false;
		}
		$values = $wpdb->get_col($wpdb->prepare(
			"SELECT option_value FROM {$wpdb->options} WHERE option_name LIKE %s",
			$wpdb->esc_like(self::BATCH_LOCK_PREFIX . $id . '_') . '%'
		));
		foreach ($values as $value) {
			if (self::is_fresh((string) $value, $ttl)) {
				return true;
			}
		}
		return false;
	}

	public static function release_finalize_lock(string $id): void {
		if (AIPT_Job_Store::is_valid($id)) {
			self::release_named_lock(self::lock_key($id));
		}
	}

	private static function lock_key(string $id): string {
		return AIPT_Job_Store::PREFIX . 'finalize_' . $id;
	}

	private static function batch_suffix(int $index): string {
		return self::BATCH_SUFFIX . $index;
	}
}
