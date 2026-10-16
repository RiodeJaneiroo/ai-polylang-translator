<?php
// Verbatim copy of post meta and term meta from the source to its translation. Posts get
// two passes (post(), called by the writer after the translated meta, for new and updated
// translations): first backend(), the keys the multilingual plugin would copy, then the
// "Copy meta keys" setting for the keys backend() did not list. Terms get the settings
// pass only (term()). Values are copied without kses.

if (!defined('ABSPATH')) {
	exit;
}

class AIPT_Meta_Copy {

	// Written by the writer itself or meaningless on another post.
	const POST_SKIP = array('_edit_lock', '_edit_last', '_wp_old_slug', '_wp_old_date', '_thumbnail_id', '_wp_page_template');

	/**
	 * Called by AIPT_Writer after the translated meta, for new and updated translations.
	 * The settings pass copies a key that matches the "Copy meta keys" list (exact, or a
	 * prefix when the line ends with *) and that the source has, replacing every target
	 * value; keys translated (every key of aipt_translated_meta_keys and extra meta keys,
	 * whether or not the source has a value), written by the writer, listed by backend(),
	 * ACF keys and handled keys (AIPT_Settings_Advanced::is_handled()) are never copied.
	 *
	 * On an update in overwrite mode, a translated key that follows the source
	 * (AIPT_Yoast::follows_source(): Yoast's SEO title, description, focus keyphrase …)
	 * is deleted from the translation when the job did not carry it (the source has no
	 * text for it any more). Extra meta keys and safe mode never clear a value.
	 *
	 * @param string[] $translated Meta keys the job translated or kept (Yoast, extra meta keys).
	 * @param bool     $created    A new translation (false: an existing one is updated).
	 */
	public static function post(int $source_id, int $target_id, string $lang, array $translated, bool $safe, bool $created): void {
		$settings = AIPT_Settings::get();
		$listed   = array_filter((array) apply_filters('aipt_translated_meta_keys', array(), $source_id), 'is_string');
		if (!$created && !$safe) {
			foreach (array_diff($listed, $translated) as $key) {
				if (AIPT_Yoast::follows_source($key)) {
					delete_metadata('post', $target_id, $key);
				}
			}
		}
		$skip     = array_merge(self::POST_SKIP, $translated, $listed, (array) ($settings['extra_meta_keys'] ?? array()));
		$meta     = get_post_meta($source_id);
		$meta     = is_array($meta) ? $meta : array();
		$acf      = aipt_acf_active() ? self::acf_keys($meta) : array();
		// The backend's keys follow its rules (remap, safe mode), not the settings pass.
		$skip     = array_merge($skip, self::backend($source_id, $target_id, $lang, $meta, $skip, $acf, $safe, $created));
		$patterns = (array) ($settings['copy_meta_keys'] ?? array());
		if (!$patterns) {
			return;
		}
		foreach (self::matching_keys(array_keys($meta), $patterns) as $key) {
			if (in_array($key, $skip, true) || isset($acf[$key]) || AIPT_Settings_Advanced::is_handled($key)) {
				continue;
			}
			self::replace('post', $target_id, $key, get_post_meta($source_id, $key, false));
		}
	}

	/**
	 * Copies the keys the multilingual plugin would copy: aipt_lang()->copy_meta_keys()
	 * (Yoast SEO primary terms, wpml-config.xml custom fields), through the
	 * aipt_copied_meta_keys filter (AIPT_Yoast adds and removes its keys there). Each
	 * non-empty source value is adjusted by aipt_lang()->translate_meta_value() (a primary
	 * term becomes its translation); a key whose adjusted value is empty is not copied (no
	 * translated term: no primary term is better than a wrong one). A new translation gets
	 * every key; an update keeps filled target values (per-language values), except keys
	 * that follow the source in overwrite mode (AIPT_Yoast::follows_source()): they are
	 * replaced, and deleted when the source has no value or its adjusted value is empty.
	 * Otherwise an empty or missing source value never clears the target.
	 *
	 * @param array<string, string[]> $meta get_post_meta() of the source, raw values.
	 * @param string[]                $skip Keys never copied here (translated, written by the writer).
	 * @param array<string, true>     $acf  ACF keys of the source (acf_keys()).
	 * @return string[] Every key listed (after aipt_copied_meta_keys), copied or not.
	 */
	private static function backend(int $source_id, int $target_id, string $lang, array $meta, array $skip, array $acf, bool $safe, bool $created): array {
		$keys = aipt_lang()->copy_meta_keys($source_id, $target_id, $lang);
		$keys = (array) apply_filters('aipt_copied_meta_keys', $keys, $source_id, $target_id, $lang);
		$keys = array_values(array_unique(array_filter($keys, static fn($key): bool => is_string($key) && $key !== '')));
		$target_meta = $created ? array() : get_post_meta($target_id);
		$target_meta = is_array($target_meta) ? $target_meta : array();
		$source_keys = array_flip(array_map('strval', array_keys($meta)));
		foreach ($keys as $key) {
			// Yoast keys are governed by the filter (AIPT_Yoast), not by is_handled().
			if (in_array($key, $skip, true) || isset($acf[$key]) || self::is_service_key($key)
				|| (AIPT_Settings_Advanced::is_handled($key) && !AIPT_Yoast::is_key($key))) {
				continue;
			}
			// Overwrite update: the key mirrors the source, absent values included.
			$follow = !$created && !$safe && AIPT_Yoast::follows_source($key);
			if (!$follow && !isset($source_keys[$key])) {
				continue;
			}
			if (!$created && !$follow && AIPT_Safe_Merge::has_value(maybe_unserialize($target_meta[$key][0] ?? ''))) {
				continue;
			}
			$values = isset($source_keys[$key]) ? self::copied_values($key, $lang, $source_id, $target_id) : array();
			if ($values) {
				self::replace('post', $target_id, $key, $values);
			} elseif ($follow && isset($target_meta[$key])) {
				delete_metadata('post', $target_id, $key);
			}
		}
		return $keys;
	}

	/**
	 * The source's non-empty values of $key adjusted by translate_meta_value(); none when
	 * an adjusted value is empty (e.g. a primary term without a translation).
	 *
	 * @return array
	 */
	private static function copied_values(string $key, string $lang, int $source_id, int $target_id): array {
		$values = array();
		foreach (get_post_meta($source_id, $key, false) as $value) {
			if ($value === '' || $value === null) {
				continue;
			}
			$mapped = aipt_lang()->translate_meta_value($value, $key, $lang, $source_id, $target_id);
			if ($mapped !== $value && in_array($mapped, array('', 0, '0', null, false, array()), true)) {
				return array();
			}
			$values[] = $mapped;
		}
		return $values;
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
			self::replace('term', $target_id, $key, get_term_meta($source_id, $key, false));
		}
	}

	// Replaces the target's values of $key with $values: delete, then add each value, so
	// multi-value keys survive.
	private static function replace(string $type, int $target_id, string $key, array $values): void {
		delete_metadata($type, $target_id, $key);
		foreach ($values as $value) {
			add_metadata($type, $target_id, $key, wp_slash($value));
		}
	}

	/**
	 * Keys among $keys that match a pattern and no backend-owned prefix.
	 *
	 * @param string[] $patterns Exact keys, or prefixes ending with '*'.
	 * @return string[]
	 */
	private static function matching_keys(array $keys, array $patterns): array {
		$out = array();
		foreach ($keys as $key) {
			$key = (string) $key;
			if (self::matches($key, $patterns) && !self::is_service_key($key)) {
				$out[] = $key;
			}
		}
		return $out;
	}

	// The multilingual plugin's own keys (aipt_lang()->service_meta_prefixes()).
	private static function is_service_key(string $key): bool {
		foreach (aipt_lang()->service_meta_prefixes() as $prefix) {
			if (str_starts_with($key, $prefix)) {
				return true;
			}
		}
		return false;
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
