<?php
// Translation job state, transient-backed (TTL 1 hour).

if (!defined('ABSPATH')) {
	exit;
}

class AIPT_Job {

	const PREFIX = 'aipt_job_';
	const TTL    = HOUR_IN_SECONDS;
	const FINALIZE_LOCK_TTL = 300;

	private static $lock_owners = array();
	// "<post_id>|<target>" => pair lock key taken by this process. The translation group
	// can change while the lock is held (the write adds the new translation), so refresh
	// and release use the key computed at acquire time.
	private static $pair_keys = array();

	public static function create(array $data): string {
		// The adapter the job was prepared with (AIPT_Pipeline refuses it under another)
		// and the payload version: 2 since 1.6 (target_state, backend).
		$data['backend'] = aipt_lang()->name();
		$data['schema']  = 2;
		$id = wp_generate_uuid4();
		// A brand-new uuid key never matches an existing value, so a false here is a
		// real storage failure (oversized payload, memcached/packet limit) rather than
		// the "value unchanged" case. Bubble it up so prepare can warn the user.
		if (!set_transient(self::PREFIX . $id, $data, self::TTL)) {
			return '';
		}
		return $id;
	}

	public static function get(string $id): ?array {
		if (!wp_is_uuid($id)) {
			return null;
		}
		$data = get_transient(self::PREFIX . $id);
		return is_array($data) ? $data : null;
	}

	public static function save(string $id, array $data): void {
		if (wp_is_uuid($id)) {
			set_transient(self::PREFIX . $id, $data, self::TTL);
		}
	}

	// Persist one batch's results AND its token usage in a single transient so
	// concurrent batch requests never read-modify-write a shared blob (last-write-wins
	// data loss), and so usage can never drift out of sync with the saved map: one
	// atomic write per batch, eviction-symmetric (lose the payload, lose the usage, and
	// the idempotent retry rewrites both).
	public static function save_batch(string $id, int $index, array $results, array $usage = array()): bool {
		if (!wp_is_uuid($id)) {
			return false;
		}
		$key     = self::batch_key($id, $index);
		$payload = array(
			'map'   => $results,
			'usage' => array(
				'tokens_in'  => (int) ($usage['tokens_in'] ?? 0),
				'tokens_out' => (int) ($usage['tokens_out'] ?? 0),
				'cost'       => (float) ($usage['cost'] ?? 0),
			),
		);
		if (set_transient($key, $payload, self::TTL)) {
			return true;
		}
		// set_transient returns false when the value is unchanged too (idempotent
		// retry of the same batch). Treat an existing readable transient as success.
		return get_transient($key) !== false;
	}

	public static function get_batch(string $id, int $index) {
		if (!wp_is_uuid($id)) {
			return false;
		}
		$batch = get_transient(self::batch_key($id, $index));
		return is_array($batch) ? self::batch_map($batch) : $batch;
	}

	// Collect every batch transient for a job. Returns the merged item_id => text
	// map, or null if any batch in [0, $total) is missing.
	public static function get_batches(string $id, int $total): ?array {
		if (!wp_is_uuid($id)) {
			return null;
		}
		$results = array();
		for ($index = 0; $index < $total; $index++) {
			$batch = get_transient(self::batch_key($id, $index));
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

	public static function delete(string $id): void {
		if (wp_is_uuid($id)) {
			delete_transient(self::PREFIX . $id);
			delete_option(self::lock_key($id));
		}
	}

	public static function delete_batches(string $id, int $total): void {
		if (!wp_is_uuid($id)) {
			return;
		}
		for ($index = 0; $index < $total; $index++) {
			delete_transient(self::batch_key($id, $index));
		}
	}

	// Sum token usage across every batch, reading the same transients save_batch() wrote.
	// A missing transient (or a legacy bare-map payload from older code) counts as 0 so
	// that cost tracking never blocks finalize.
	public static function get_usage_total(string $id, int $total): array {
		$totals = array('tokens_in' => 0, 'tokens_out' => 0, 'cost' => 0.0);
		if (!wp_is_uuid($id)) {
			return $totals;
		}
		for ($index = 0; $index < $total; $index++) {
			$batch = get_transient(self::batch_key($id, $index));
			if (is_array($batch) && isset($batch['usage']) && is_array($batch['usage'])) {
				$usage = $batch['usage'];
				$totals['tokens_in']  += (int) ($usage['tokens_in'] ?? 0);
				$totals['tokens_out'] += (int) ($usage['tokens_out'] ?? 0);
				$totals['cost']       += (float) ($usage['cost'] ?? 0);
			}
		}
		return $totals;
	}

	// Generic named lock backed by a DB row (same INSERT IGNORE mechanic as the
	// finalize lock). Used for usage-write serialisation across concurrent batch
	// requests. $key must be a stable, globally unique option name (e.g. 'aipt_usage_lock').
	// $ttl: seconds before a held lock is considered stale and can be stolen.
	// Spins up to $tries times with $usleep_us microseconds between attempts.
	// Returns false only after exhausting all tries — callers should degrade gracefully.
	public static function acquire_named_lock(string $key, int $ttl, int $tries = 50, int $usleep_us = 20000): bool {
		global $wpdb;

		for ($attempt = 0; $attempt < $tries; $attempt++) {
			$now        = time();
			$lock_value = self::new_lock_value($now);
			if (self::insert_lock($key, $lock_value)) {
				self::$lock_owners[$key] = $lock_value;
				return true;
			}

			// Read the stored timestamp directly from the DB — the object cache may
			// still reflect our own failed INSERT or a previous holder's write.
			$stored_value = (string) $wpdb->get_var($wpdb->prepare(
				"SELECT option_value FROM {$wpdb->options} WHERE option_name = %s LIMIT 1",
				$key
			));
			$locked_at = (int) $stored_value;

			if ($locked_at && $locked_at > $now - $ttl) {
				// Lock is fresh — somebody else holds it; wait and retry.
				usleep($usleep_us);
				continue;
			}

			// Delete only the stale value we actually observed. If another contender
			// replaced it first, leave that new owner's lock intact.
			if ($stored_value !== '' && !self::delete_lock_value($key, $stored_value)) {
				usleep($usleep_us);
				continue;
			}

			$lock_value = self::new_lock_value($now);
			if (self::insert_lock($key, $lock_value)) {
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
		self::delete_lock_value($key, $lock_value);
	}

	public static function acquire_finalize_lock(string $id): bool {
		if (!wp_is_uuid($id)) {
			return false;
		}

		$key = self::lock_key($id);
		$now = time();
		$lock_value = self::new_lock_value($now);

		// Atomic test-and-set. add_option() is not atomic (get_option check +
		// INSERT ... ON DUPLICATE KEY UPDATE), so two concurrent finalizes can both
		// "win" it. INSERT IGNORE wins only when it actually inserts the row, mirroring
		// WP_Upgrader::create_lock() in core.
		if (self::insert_lock($key, $lock_value)) {
			self::$lock_owners[$key] = $lock_value;
			return true;
		}

		// Stale takeover. Read the stored timestamp straight from the DB (the object
		// cache may not know about our raw INSERT). If it is still fresh, somebody else
		// holds the lock; otherwise drop it and try the atomic insert one more time.
		global $wpdb;
		$stored_value = (string) $wpdb->get_var($wpdb->prepare(
			"SELECT option_value FROM {$wpdb->options} WHERE option_name = %s LIMIT 1",
			$key
		));
		$locked_at = (int) $stored_value;
		if ($locked_at && $locked_at > $now - self::FINALIZE_LOCK_TTL) {
			return false;
		}

		if ($stored_value !== '' && !self::delete_lock_value($key, $stored_value)) {
			return false;
		}

		$lock_value = self::new_lock_value($now);
		if (!self::insert_lock($key, $lock_value)) {
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
		if (self::insert_lock($key, $lock_value)) {
			self::$lock_owners[$key]                   = $lock_value;
			self::$pair_keys[$post_id . '|' . $target] = $key;
			return true;
		}

		$stored_value = self::stored_lock_value($key);
		if (self::pair_lock_is_fresh($stored_value, $now)) {
			return false;
		}
		if ($stored_value !== '' && !self::delete_lock_value($key, $stored_value)) {
			return false;
		}

		$lock_value = self::new_lock_value($now) . ':' . $ttl . ':' . $holder;
		if (!self::insert_lock($key, $lock_value)) {
			return false;
		}
		self::$lock_owners[$key]                   = $lock_value;
		self::$pair_keys[$post_id . '|' . $target] = $key;
		return true;
	}

	// Role of the current fresh holder ('cli', 'editor', 'auto'), or '' when the pair is free.
	public static function pair_lock_holder(int $post_id, string $target): string {
		$stored_value = self::stored_lock_value(self::pair_lock_key($post_id, $target));
		if (!self::pair_lock_is_fresh($stored_value, time())) {
			return '';
		}
		$parts = explode(':', $stored_value);
		return (string) ($parts[3] ?? '');
	}

	// Heartbeat for long records: move our own lock's timestamp forward so the TTL
	// counts from the last finished batch. No-op when this process does not hold it.
	public static function refresh_pair_lock(int $post_id, string $target): void {
		global $wpdb;
		$key = self::$pair_keys[$post_id . '|' . $target] ?? '';
		if ($key === '' || !isset(self::$lock_owners[$key])) {
			return;
		}
		$parts    = explode(':', self::$lock_owners[$key]);
		$parts[0] = (string) time();
		$value    = implode(':', $parts);
		$updated  = $wpdb->query($wpdb->prepare(
			"UPDATE {$wpdb->options} SET option_value = %s WHERE option_name = %s AND option_value = %s",
			$value,
			$key,
			self::$lock_owners[$key]
		));
		self::clear_lock_cache($key);
		if ($updated === 1) {
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

	// Read straight from the DB — the object cache may not know about a raw INSERT.
	private static function stored_lock_value(string $key): string {
		global $wpdb;
		return (string) $wpdb->get_var($wpdb->prepare(
			"SELECT option_value FROM {$wpdb->options} WHERE option_name = %s LIMIT 1",
			$key
		));
	}

	// Keyed per translation group (AIPT_Lang::group_id()), not per source post: two
	// members of one group translated into the same language write the same target.
	private static function pair_lock_key(int $post_id, string $target): string {
		return 'aipt_pair_lock_' . aipt_lang()->group_id($post_id) . '_' . sanitize_key($target);
	}

	private static function new_lock_value(int $now): string {
		return $now . ':' . wp_generate_uuid4();
	}

	private static function insert_lock(string $key, string $lock_value): bool {
		global $wpdb;
		$inserted = $wpdb->query($wpdb->prepare(
			"INSERT IGNORE INTO {$wpdb->options} (option_name, option_value, autoload) VALUES (%s, %s, 'off')",
			$key,
			$lock_value
		));
		// Drop any stale cached "option does not exist" entry so later reads see the row.
		self::clear_lock_cache($key);
		return $inserted === 1;
	}

	private static function delete_lock_value(string $key, string $lock_value): bool {
		global $wpdb;
		$deleted = $wpdb->query($wpdb->prepare(
			"DELETE FROM {$wpdb->options} WHERE option_name = %s AND option_value = %s",
			$key,
			$lock_value
		));
		self::clear_lock_cache($key);
		return $deleted === 1;
	}

	private static function clear_lock_cache(string $key): void {
		wp_cache_delete($key, 'options');
		wp_cache_delete('notoptions', 'options');
	}

	public static function release_finalize_lock(string $id): void {
		if (wp_is_uuid($id)) {
			self::release_named_lock(self::lock_key($id));
		}
	}

	private static function lock_key(string $id): string {
		return self::PREFIX . 'finalize_' . $id;
	}

	private static function batch_key(string $id, int $index): string {
		return self::PREFIX . $id . '_batch_' . $index;
	}
}
