<?php
// Storage of the bulk recovery journal (AIPT_Bulk_Jobs): one non-autoloaded option per
// record, aipt_bulk_job_<post_id>_<lang>, holding {job_id, time, review?} while the
// record's durable job is unfinished and the tombstone {job_id, done: {status, post_id},
// time} once it is finished. Other requests write these rows concurrently, so every read
// goes straight to the database and every write is a compare-and-swap on the row value
// read (never a blind update_option()), through AIPT_Job's raw option-row API (option_*).

if (!defined('ABSPATH')) {
	exit;
}

class AIPT_Bulk_Locator {

	const PREFIX = 'aipt_bulk_job_';

	public static function key(int $post_id, string $lang): string {
		return self::PREFIX . $post_id . '_' . sanitize_key($lang);
	}

	// Raw stored value, '' when there is no row.
	public static function raw(int $post_id, string $lang): string {
		return AIPT_Job::option_raw(self::key($post_id, $lang));
	}

	public static function value(int $post_id, string $lang): ?array {
		return self::decode(self::raw($post_id, $lang));
	}

	public static function decode(string $raw): ?array {
		$value = $raw === '' ? null : maybe_unserialize($raw);
		return is_array($value) ? $value : null;
	}

	public static function is_tombstone(?array $value): bool {
		return $value !== null && isset($value['done']);
	}

	// Matches a locator that points to $job_id and is not a tombstone.
	public static function live_match(string $job_id): callable {
		return static fn(array $value): bool => ($value['job_id'] ?? '') === $job_id && !isset($value['done']);
	}

	/**
	 * @param bool $done true: only tombstones, false: only live locators (the query tells
	 *                   them apart by a serialized 'done' key).
	 * @return array[] [post_id, lang, value, option_name, raw option_value] of every
	 *                 matching locator option.
	 */
	public static function all(bool $done): array {
		global $wpdb;
		$rows = $wpdb->get_results($wpdb->prepare(
			"SELECT option_name, option_value FROM {$wpdb->options} WHERE option_name LIKE %s AND option_value " . ($done ? 'LIKE' : 'NOT LIKE') . ' %s ORDER BY option_id',
			$wpdb->esc_like(self::PREFIX) . '%',
			'%' . $wpdb->esc_like('s:4:"done"') . '%'
		));
		$list = array();
		foreach ($rows as $row) {
			$value = self::decode((string) $row->option_value);
			if ($value !== null && preg_match('/^' . preg_quote(self::PREFIX, '/') . '(\d+)_(.+)$/', $row->option_name, $m)) {
				$list[] = array((int) $m[1], $m[2], $value, $row->option_name, (string) $row->option_value);
			}
		}
		return $list;
	}

	/**
	 * Replace ($new) or delete (null) the locator only if the stored value matches $match
	 * and is still exactly the value read.
	 */
	public static function swap(int $post_id, string $lang, callable $match, ?array $new): bool {
		$raw = self::raw($post_id, $lang);
		$old = self::decode($raw);
		if ($old === null || !$match($old)) {
			return false;
		}
		return $new === null ? self::delete_raw(self::key($post_id, $lang), $raw) : self::put($post_id, $lang, $raw, $new);
	}

	/**
	 * Store $new only if the row still holds $raw: inserted when $raw is '' (no row),
	 * else replaced.
	 */
	public static function put(int $post_id, string $lang, string $raw, array $new): bool {
		$key = self::key($post_id, $lang);
		return $raw === ''
			? AIPT_Job::option_insert($key, maybe_serialize($new))
			: AIPT_Job::option_replace_if($key, $raw, maybe_serialize($new));
	}

	// Delete the row only while it still holds $raw.
	public static function delete_raw(string $name, string $raw): bool {
		return AIPT_Job::option_delete_if($name, $raw);
	}

	// Remove every tombstone (a new run starts), each only if still the value read
	// (compare on name and value, ~200 rows per DELETE).
	public static function reset(): void {
		global $wpdb;
		$rows = array();
		foreach (self::all(true) as $locator) {
			$rows[] = array($locator[3], $locator[4]);
		}
		foreach (array_chunk($rows, 200) as $chunk) {
			$pairs = implode(',', array_fill(0, count($chunk), '(%s, %s)'));
			$wpdb->query($wpdb->prepare(
				"DELETE FROM {$wpdb->options} WHERE (option_name, option_value) IN ($pairs)",
				array_merge(...$chunk)
			));
			foreach ($chunk as $row) {
				wp_cache_delete($row[0], 'options');
			}
		}
		if ($rows) {
			wp_cache_delete('notoptions', 'options');
		}
	}
}
