<?php
// Translation job state, transient-backed (TTL 1 hour).

if (!defined('ABSPATH')) {
	exit;
}

class AIPT_Job {

	const PREFIX = 'aipt_job_';
	const TTL    = HOUR_IN_SECONDS;
	const FINALIZE_LOCK_TTL = 300;

	public static function create(array $data): string {
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

	// Persist one batch's results in its own transient so concurrent batch
	// requests never read-modify-write a shared blob (last-write-wins data loss).
	public static function save_batch(string $id, int $index, array $results): bool {
		if (!wp_is_uuid($id)) {
			return false;
		}
		$key = self::batch_key($id, $index);
		if (set_transient($key, $results, self::TTL)) {
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
		return get_transient(self::batch_key($id, $index));
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
			$results += $batch;
		}
		return $results;
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

	public static function acquire_finalize_lock(string $id): bool {
		if (!wp_is_uuid($id)) {
			return false;
		}

		$key = self::lock_key($id);
		$now = time();

		// Atomic test-and-set. add_option() is not atomic (get_option check +
		// INSERT ... ON DUPLICATE KEY UPDATE), so two concurrent finalizes can both
		// "win" it. INSERT IGNORE wins only when it actually inserts the row, mirroring
		// WP_Upgrader::create_lock() in core.
		if (self::insert_lock($key, $now)) {
			return true;
		}

		// Stale takeover. Read the stored timestamp straight from the DB (the object
		// cache may not know about our raw INSERT). If it is still fresh, somebody else
		// holds the lock; otherwise drop it and try the atomic insert one more time.
		global $wpdb;
		$locked_at = (int) $wpdb->get_var($wpdb->prepare(
			"SELECT option_value FROM {$wpdb->options} WHERE option_name = %s LIMIT 1",
			$key
		));
		if ($locked_at && $locked_at > $now - self::FINALIZE_LOCK_TTL) {
			return false;
		}

		delete_option($key);
		return self::insert_lock($key, $now);
	}

	private static function insert_lock(string $key, int $now): bool {
		global $wpdb;
		$inserted = $wpdb->query($wpdb->prepare(
			"INSERT IGNORE INTO {$wpdb->options} (option_name, option_value, autoload) VALUES (%s, %s, 'off')",
			$key,
			(string) $now
		));
		// Drop any stale cached "option does not exist" entry so later reads see the row.
		wp_cache_delete($key, 'options');
		wp_cache_delete('notoptions', 'options');
		return $inserted === 1;
	}

	public static function release_finalize_lock(string $id): void {
		if (wp_is_uuid($id)) {
			delete_option(self::lock_key($id));
		}
	}

	private static function lock_key(string $id): string {
		return self::PREFIX . 'finalize_' . $id;
	}

	private static function batch_key(string $id, int $index): string {
		return self::PREFIX . $id . '_batch_' . $index;
	}
}
