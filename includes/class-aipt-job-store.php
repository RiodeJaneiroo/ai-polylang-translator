<?php
// Where AIPT_Job keeps a job's payload, batches and completion marker. The job id says
// which storage it uses, so a lookup never has to load the payload first:
// - "<uuid>": transients (TTL 1 hour), the editor metabox.
// - "d-<uuid>": durable, non-autoloaded options without expiry (a TTL or an object-cache
//   flush must not throw paid batches away); removed only by AIPT_Job::delete().

if (!defined('ABSPATH')) {
	exit;
}

class AIPT_Job_Store {

	// Transient names: aipt_job_<uuid>, aipt_job_<uuid>_batch_<n>.
	const PREFIX = 'aipt_job_';
	// Option names: aipt_djob_<uuid>, aipt_djob_<uuid>_batch_<n>.
	const DURABLE_PREFIX = 'aipt_djob_';
	const DURABLE_ID     = 'd-';
	const TTL            = HOUR_IN_SECONDS;

	public static function new_id(bool $durable): string {
		return ($durable ? self::DURABLE_ID : '') . wp_generate_uuid4();
	}

	public static function is_valid(string $id): bool {
		return self::parse($id) !== null;
	}

	public static function is_durable(string $id): bool {
		$parsed = self::parse($id);
		return $parsed !== null && $parsed['durable'];
	}

	/**
	 * Stored value of the job's payload ($suffix '') or of one of its parts
	 * ('_batch_<n>'), false when missing or the id is not a job id.
	 *
	 * @return mixed
	 */
	public static function get(string $id, string $suffix = '') {
		$parsed = self::parse($id);
		if ($parsed === null) {
			return false;
		}
		$key = self::key($parsed, $suffix);
		return $parsed['durable'] ? get_option($key, false) : get_transient($key);
	}

	/**
	 * get() past the request's caches: a durable part straight from the database
	 * (AIPT_Job::option_raw()), a transient after dropping this request's cached copy (an
	 * external object cache is the transient's store, so it is authoritative). For
	 * decisions another request may have changed meanwhile (the job finished, discarded, a
	 * batch saved).
	 *
	 * @return mixed
	 */
	public static function get_fresh(string $id, string $suffix = '') {
		$parsed = self::parse($id);
		if ($parsed === null) {
			return false;
		}
		$key = self::key($parsed, $suffix);
		if ($parsed['durable']) {
			$raw = AIPT_Job::option_raw($key);
			return $raw === '' ? false : maybe_unserialize($raw);
		}
		if (!wp_using_ext_object_cache()) {
			wp_cache_delete('_transient_' . $key, 'options');
			wp_cache_delete('_transient_timeout_' . $key, 'options');
			wp_cache_delete('notoptions', 'options');
		}
		return get_transient($key);
	}

	// A false from set_transient()/update_option() also means "value unchanged" (a
	// repeated save of the same data), so an equal stored value counts as saved.
	public static function set(string $id, string $suffix, $value): bool {
		$parsed = self::parse($id);
		if ($parsed === null) {
			return false;
		}
		$key   = self::key($parsed, $suffix);
		$saved = $parsed['durable'] ? update_option($key, $value, false) : set_transient($key, $value, self::TTL);
		return $saved || self::get($id, $suffix) === $value;
	}

	public static function delete(string $id, string $suffix = ''): void {
		$parsed = self::parse($id);
		if ($parsed === null) {
			return;
		}
		$key = self::key($parsed, $suffix);
		if ($parsed['durable']) {
			delete_option($key);
		} else {
			delete_transient($key);
		}
	}

	// A durable job loses every option it has (payload, batches, completion marker); a
	// transient job its payload (its parts expire on their own).
	public static function purge(string $id): void {
		if (self::is_durable($id)) {
			self::delete_durable($id);
		} else {
			self::delete($id);
		}
	}

	// Every option of a durable job, in one DELETE (non-autoloaded: only their own cache
	// entries are dropped).
	private static function delete_durable(string $id): void {
		global $wpdb;
		$names = self::durable_names($id);
		if (!$names) {
			return;
		}
		$wpdb->query($wpdb->prepare(
			"DELETE FROM {$wpdb->options} WHERE option_name LIKE %s",
			self::like($id)
		));
		foreach ($names as $name) {
			wp_cache_delete($name, 'options');
		}
	}

	/**
	 * Option names of a durable job whose key continues with $suffix (all of them for '');
	 * names only, no value is loaded. [] for a transient or invalid id.
	 *
	 * @return string[]
	 */
	public static function durable_names(string $id, string $suffix = ''): array {
		global $wpdb;
		if (!self::is_durable($id)) {
			return array();
		}
		return $wpdb->get_col($wpdb->prepare(
			"SELECT option_name FROM {$wpdb->options} WHERE option_name LIKE %s",
			self::like($id, $suffix)
		));
	}

	// LIKE pattern of a durable job's option names starting with its key . $suffix.
	private static function like(string $id, string $suffix = ''): string {
		global $wpdb;
		return $wpdb->esc_like(self::key(self::parse($id), $suffix)) . '%';
	}

	/**
	 * @return array{uuid: string, durable: bool}|null
	 */
	private static function parse(string $id): ?array {
		$durable = str_starts_with($id, self::DURABLE_ID);
		$uuid    = $durable ? substr($id, strlen(self::DURABLE_ID)) : $id;
		return wp_is_uuid($uuid) ? array('uuid' => $uuid, 'durable' => $durable) : null;
	}

	private static function key(array $parsed, string $suffix = ''): string {
		return ($parsed['durable'] ? self::DURABLE_PREFIX : self::PREFIX) . $parsed['uuid'] . $suffix;
	}
}
