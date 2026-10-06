<?php
// Verbatim copy of selected post meta and term meta from the source to its translation
// ("Copy meta keys" / "Copy term meta keys", AIPT_Settings_Advanced). A key that matches
// the list (exact, or a prefix when the line ends with *) is replaced on the target with
// every value of the source: delete, then add each value, so multi-value keys survive.
// Only keys the source has are touched. Keys the plugin translates or writes itself,
// WordPress internals and the multilingual plugin's own keys are never copied.

if (!defined('ABSPATH')) {
	exit;
}

class AIPT_Meta_Copy {

	// Written by the writer itself or meaningless on another post.
	const POST_SKIP = array('_edit_lock', '_edit_last', '_wp_old_slug', '_wp_old_date', '_thumbnail_id', '_wp_page_template');

	/**
	 * Called by AIPT_Writer after the translated meta, for new and updated translations.
	 *
	 * @param string[] $translated Meta keys the job translated (Yoast, extra meta keys).
	 */
	public static function post(int $source_id, int $target_id, array $translated): void {
		$settings = AIPT_Settings::get();
		$patterns = (array) ($settings['copy_meta_keys'] ?? array());
		if (!$patterns) {
			return;
		}
		$skip = array_merge(self::POST_SKIP, $translated, (array) ($settings['extra_meta_keys'] ?? array()));
		$meta = get_post_meta($source_id);
		$meta = is_array($meta) ? $meta : array();
		$acf  = aipt_acf_active() ? self::acf_keys($meta) : array();
		foreach (self::matching_keys(array_keys($meta), $patterns) as $key) {
			if (in_array($key, $skip, true) || isset($acf[$key]) || AIPT_Settings_Advanced::is_handled($key)) {
				continue;
			}
			delete_post_meta($target_id, $key);
			foreach (get_post_meta($source_id, $key, false) as $value) {
				add_post_meta($target_id, $key, wp_slash($value));
			}
		}
	}

	/**
	 * Called by AIPT_Terms once a translated term is created or linked.
	 */
	public static function term(int $source_id, int $target_id): void {
		$patterns = (array) (AIPT_Settings::get()['copy_term_meta_keys'] ?? array());
		if (!$patterns) {
			return;
		}
		$meta = get_term_meta($source_id);
		foreach (self::matching_keys(array_keys(is_array($meta) ? $meta : array()), $patterns) as $key) {
			if (str_starts_with($key, '_aipt_')) {
				continue;
			}
			delete_term_meta($target_id, $key);
			foreach (get_term_meta($source_id, $key, false) as $value) {
				add_term_meta($target_id, $key, wp_slash($value));
			}
		}
	}

	/**
	 * Keys among $keys that match a pattern and no backend-owned prefix.
	 *
	 * @param string[] $patterns Exact keys, or prefixes ending with '*'.
	 * @return string[]
	 */
	private static function matching_keys(array $keys, array $patterns): array {
		$service = aipt_lang()->service_meta_prefixes();
		$out     = array();
		foreach ($keys as $key) {
			$key = (string) $key;
			if (!self::matches($key, $patterns)) {
				continue;
			}
			foreach ($service as $prefix) {
				if (str_starts_with($key, $prefix)) {
					continue 2;
				}
			}
			$out[] = $key;
		}
		return $out;
	}

	private static function matches(string $key, array $patterns): bool {
		foreach ($patterns as $pattern) {
			$pattern = (string) $pattern;
			if (str_ends_with($pattern, '*')
				? str_starts_with($key, substr($pattern, 0, -1))
				: $key === $pattern) {
				return true;
			}
		}
		return false;
	}

	/**
	 * ACF values (written by the writer through update_field) and their reference keys:
	 * ACF stores the field key ("field_…") under "_<name>" for every value, sub-fields included.
	 * A key is recognised by its "field_" prefix alone, like ACF's own acf_is_field_key()
	 * (keys of fields registered in code may contain any characters after it).
	 *
	 * @param array<string, string[]> $meta get_post_meta() of the source, raw values.
	 * @return array<string, true>
	 */
	private static function acf_keys(array $meta): array {
		$keys = array();
		foreach ($meta as $key => $values) {
			$key = (string) $key;
			if ($key !== '_' && str_starts_with($key, '_') && str_starts_with((string) ($values[0] ?? ''), 'field_')) {
				$keys[$key]             = true;
				$keys[substr($key, 1)] = true;
			}
		}
		return $keys;
	}
}
