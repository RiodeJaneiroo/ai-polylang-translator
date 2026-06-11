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
		set_transient(self::PREFIX . $id, $data, self::TTL);
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

	public static function delete(string $id): void {
		if (wp_is_uuid($id)) {
			delete_transient(self::PREFIX . $id);
			delete_option(self::lock_key($id));
		}
	}

	public static function acquire_finalize_lock(string $id): bool {
		if (!wp_is_uuid($id)) {
			return false;
		}

		$key = self::lock_key($id);
		$now = time();
		if (add_option($key, $now, '', 'no')) {
			return true;
		}

		$locked_at = (int) get_option($key, 0);
		if ($locked_at && $locked_at > $now - self::FINALIZE_LOCK_TTL) {
			return false;
		}

		delete_option($key);
		return add_option($key, $now, '', 'no');
	}

	public static function release_finalize_lock(string $id): void {
		if (wp_is_uuid($id)) {
			delete_option(self::lock_key($id));
		}
	}

	private static function lock_key(string $id): string {
		return self::PREFIX . 'finalize_' . $id;
	}
}
