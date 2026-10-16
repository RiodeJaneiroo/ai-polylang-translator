<?php
// Custom-field copy side of AIPT_Lang_WPML (copy_meta_keys(), translate_meta_value(), the
// copied-value filter hooked during a write). References are to WPML 5.1.0
// (sitepress-multilingual-cms). WPML copies fields set to copy / copy once, with raw source
// values, from two places: WPML_Post_Synchronization::sync_with_translations()
// (inc/post-translation/wpml-post-synchronization.class.php:459, sync_custom_fields :593 ->
// SitePress::copy_custom_fields(), sitepress.class.php:1689), run by the save_post handler
// the writer suspends, and WPML_Post_Translation_Metadata_Initializer::initialize_if_source_changed()
// (classes/custom-field-translation/class-wpml-post-translation-metadata-initializer.php:66-84,
// copy_custom_fields() + WPML_Copy_Once_Custom_Field::copy_from()), run by
// SitePress::set_element_language_details() (sitepress.class.php:1516, call at :1572)
// whenever a post's source changes, i.e. when commit_post() links a new translation.
// The writer's own copy pass (AIPT_Meta_Copy::backend()) runs before the link, for the keys
// listed here (translate-mode keys too) and on updates, where nothing is relinked; the
// filter hooked for the write keeps WPML's own copy of Yoast primary terms remapped.

if (!defined('ABSPATH')) {
	exit;
}

trait AIPT_Lang_WPML_Meta {

	// Stored preferences (SitePress::get_custom_fields_translation_settings(),
	// sitepress.class.php:1790) and the source's keys whose preference comes from
	// wpml-config.xml and is not stored yet (wpml_resolve_custom_field_preferences(),
	// inc/custom-field-preference-resolution.php:7), modes copy, copy once and translate
	// (translate keys we do not translate are copied verbatim, like Polylang does); never
	// ignore. Read-only: an unavailable API gives an empty list.
	public function copy_meta_keys(int $from, int $to, string $lang): array {
		$modes = array(
			defined('WPML_COPY_CUSTOM_FIELD') ? (int) WPML_COPY_CUSTOM_FIELD : 1,
			defined('WPML_TRANSLATE_CUSTOM_FIELD') ? (int) WPML_TRANSLATE_CUSTOM_FIELD : 2,
			defined('WPML_COPY_ONCE_CUSTOM_FIELD') ? (int) WPML_COPY_ONCE_CUSTOM_FIELD : 3,
		);
		$keys = array();
		global $sitepress;
		if (is_object($sitepress) && method_exists($sitepress, 'get_custom_fields_translation_settings')) {
			foreach ($modes as $mode) {
				$keys = array_merge($keys, (array) $sitepress->get_custom_fields_translation_settings($mode));
			}
		}
		if (function_exists('wpml_resolve_custom_field_preferences')) {
			$source = get_post_meta($from);
			$source = is_array($source) ? array_keys($source) : array();
			foreach ((array) wpml_resolve_custom_field_preferences($source) as $key => $mode) {
				if (is_numeric($mode) && in_array((int) $mode, $modes, true)) {
					$keys[] = $key;
				}
			}
		}
		return array_filter(array_map('strval', $keys));
	}

	// WPML's copied-value filter first, like WPML_Sync_Custom_Fields::filter_copied_value()
	// (classes/custom-field-translation/class-wpml-sync-custom-fields.php:364-368); then
	// Yoast SEO's primary term id becomes its translation in $lang, 0 when there is none,
	// which the caller treats as not copyable. While filter_copied_value() is hooked the
	// remap runs twice, which is idempotent (a term in $lang maps to itself).
	public function translate_meta_value($value, string $key, string $lang, int $from, int $to) {
		$value  = apply_filters('wpml_sync_custom_field_copied_value', $value, $from, $to, $key, array());
		$mapped = $this->remap_primary($value, $key, $lang);
		return $mapped === null ? $value : $mapped;
	}

	// Callback of wpml_sync_custom_field_copied_value while we write: WPML's own copy of a
	// Yoast primary term (raw source term id) becomes the target-language term, '' when it
	// has none (no primary term rather than a source-language one, as Polylang's Yoast
	// integration stores pll_get_term()'s empty result). Must not apply the filter itself.
	public function filter_copied_value($value, $post_id_from, $post_id_to, $meta_key) {
		if (!is_string($meta_key) || !AIPT_Yoast::is_primary_key($meta_key) || !is_numeric($value)) {
			return $value;
		}
		$lang = $this->post_language((int) $post_id_to);
		if ($lang === null) {
			return $value;
		}
		$mapped = $this->remap_primary($value, $meta_key, $lang);
		return $mapped ? $mapped : '';
	}

	// suspend_sync() passes its result through here: the filter is hooked only after a
	// successful suspension and removed by restore_sync(), so WPML's sync outside our writes
	// is never changed.
	public function hook_copied_value_filter($state) {
		if (!is_wp_error($state)) {
			add_filter('wpml_sync_custom_field_copied_value', array($this, 'filter_copied_value'), 10, 4);
		}
		return $state;
	}

	public function unhook_copied_value_filter(): void {
		remove_filter('wpml_sync_custom_field_copied_value', array($this, 'filter_copied_value'), 10);
	}

	// Null when $key is not a Yoast primary-term key or $value is not numeric; else the
	// term's translation in $lang, 0 when there is none.
	private function remap_primary($value, string $key, string $lang): ?int {
		if (!AIPT_Yoast::is_primary_key($key) || !is_numeric($value)) {
			return null;
		}
		return $this->term_translation((int) $value, $lang);
	}
}
