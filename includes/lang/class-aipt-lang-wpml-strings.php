<?php
// String Translation side of AIPT_Lang_WPML (strings(), string_domains(),
// save_string_translation()) and the meta keys WPML owns. References are to WPML String
// Translation 5.1.0 (wpml-string-translation). Strings are read with read-only queries on
// icl_strings / icl_string_translations and written only through icl_add_string_translation().

if (!defined('ABSPATH')) {
	exit;
}

trait AIPT_Lang_WPML_Strings {

	// _icl_lang_duplicate_of (duplicates), _wpml_media_* / _wpml_word_count and the like.
	public function service_meta_prefixes(): array {
		return array('_icl_', '_wpml_');
	}

	public function supports_strings(): bool {
		return !is_wp_error(self::string_api_ready());
	}

	// icl_strings.context is the domain and .language the string's source language;
	// a translation row is finished at ICL_TM_COMPLETE (inc/constants.php) with a value.
	public function strings(string $domain, string $from, string $target) {
		$ready = self::string_api_ready();
		if (is_wp_error($ready)) {
			return $ready;
		}
		global $wpdb;
		$rows = $wpdb->get_results($wpdb->prepare(
			"SELECT s.id, s.name, s.value, t.status AS t_status, t.value AS t_value
			FROM {$wpdb->prefix}icl_strings s
			LEFT JOIN {$wpdb->prefix}icl_string_translations t ON t.string_id = s.id AND t.language = %s
			WHERE s.context = %s AND s.language = %s
			ORDER BY s.id",
			$target,
			$domain,
			$from
		), ARRAY_A);
		if (!is_array($rows) || $wpdb->last_error !== '') {
			return self::strings_read_error();
		}

		$strings = array();
		foreach ($rows as $row) {
			$strings[] = array(
				'id'         => (int) $row['id'],
				'name'       => (string) $row['name'],
				'value'      => (string) $row['value'],
				'translated' => (int) $row['t_status'] === (int) ICL_TM_COMPLETE && trim((string) $row['t_value']) !== '',
			);
		}
		return $strings;
	}

	// Same tables and "finished" rule as strings(), grouped by context; an empty source value
	// is never counted as untranslated. icl_string_translations has one row per string and
	// language (unique key string_language), so COUNT(*) counts strings.
	public function string_domains(string $from, string $target) {
		$ready = self::string_api_ready();
		if (is_wp_error($ready)) {
			return $ready;
		}
		global $wpdb;
		$rows = $wpdb->get_results($wpdb->prepare(
			"SELECT s.context AS domain, COUNT(*) AS total,
				SUM(CASE WHEN TRIM(s.value) = '' OR (t.status = %d AND TRIM(t.value) <> '') THEN 0 ELSE 1 END) AS untranslated
			FROM {$wpdb->prefix}icl_strings s
			LEFT JOIN {$wpdb->prefix}icl_string_translations t ON t.string_id = s.id AND t.language = %s
			WHERE s.language = %s
			GROUP BY s.context
			ORDER BY s.context",
			(int) ICL_TM_COMPLETE,
			$target,
			$from
		), ARRAY_A);
		if (!is_array($rows) || $wpdb->last_error !== '') {
			return self::strings_read_error();
		}

		$domains = array();
		foreach ($rows as $row) {
			$domains[] = array(
				'domain'       => (string) $row['domain'],
				'total'        => (int) $row['total'],
				'untranslated' => (int) $row['untranslated'],
			);
		}
		return $domains;
	}

	// WPML_ST_String::set_translation (classes/class-wpml-st-string.php) returns the
	// translation row ID, or false for a missing string or one reserved for a translator;
	// it updates the string status and flushes WPML's string caches itself.
	public function save_string_translation(int $id, string $lang, string $text) {
		$ready = self::string_api_ready();
		if (is_wp_error($ready)) {
			return $ready;
		}
		if (!icl_add_string_translation($id, $lang, $text, ICL_TM_COMPLETE)) {
			return new WP_Error('aipt_string_not_saved', sprintf(
				/* translators: 1: string ID, 2: language code */
				__('WPML did not save the %2$s translation of string #%1$d (the string no longer exists or is reserved for a translator).', 'ai-polylang-translator'),
				$id,
				$lang
			));
		}
		return true;
	}

	// A failed read of the string tables, with the database error.
	private static function strings_read_error(): WP_Error {
		global $wpdb;
		return new WP_Error('aipt_wpml_api', sprintf(
			/* translators: %s: database error */
			__('WPML strings could not be read: %s', 'ai-polylang-translator'),
			$wpdb->last_error
		));
	}

	/**
	 * @return true|WP_Error
	 */
	private static function string_api_ready() {
		if (!function_exists('icl_add_string_translation') || !defined('ICL_TM_COMPLETE')) {
			return new WP_Error('aipt_strings_unsupported', __('WPML String Translation is not active.', 'ai-polylang-translator'));
		}
		return true;
	}
}
