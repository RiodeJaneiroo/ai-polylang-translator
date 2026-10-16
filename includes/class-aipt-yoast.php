<?php
// Yoast SEO post meta rules, applied while Yoast SEO is active and "Translate Yoast SEO
// fields" is ticked (enabled()): TRANSLATE_KEYS are translated (AIPT_Extractor, through
// aipt_translated_meta_keys), COPY_KEYS and the source's primary terms are copied
// (AIPT_Meta_Copy, through aipt_copied_meta_keys), every other Yoast key (the canonical
// URL and redirect, which belong to one URL; keys a backend lists in translate mode such
// as focuskeywords, which would stay in the source language) is never copied. On an
// update in overwrite mode these keys follow the source (follows_source()). Otherwise
// no Yoast key is touched.

if (!defined('ABSPATH')) {
	exit;
}

class AIPT_Yoast {

	const PREFIX         = '_yoast_wpseo_';
	const PRIMARY_PREFIX = '_yoast_wpseo_primary_';

	// Polylang's list of translatable Yoast SEO keys (integrations/wpseo/wpseo.php).
	const TRANSLATE_KEYS = array(
		'_yoast_wpseo_title',
		'_yoast_wpseo_metadesc',
		'_yoast_wpseo_bctitle',
		'_yoast_wpseo_focuskw',
		'_yoast_wpseo_opengraph-title',
		'_yoast_wpseo_opengraph-description',
		'_yoast_wpseo_twitter-title',
		'_yoast_wpseo_twitter-description',
	);

	// Keys Polylang's Yoast SEO integration copies (src/integrations/wpseo/wpseo.php:437-442).
	// Yoast's wpml-config.xml marks the robots keys "copy_once", which neither Polylang
	// accepts (it expects "copy-once" and drops other actions from its copy list,
	// src/modules/wpml/wpml-config.php:997) nor WPML (imported as ignore,
	// class-wpml-custom-field-xml-settings-import.php:76), so both adapters miss them.
	const COPY_KEYS = array(
		'_yoast_wpseo_opengraph-image',
		'_yoast_wpseo_twitter-image',
		'_yoast_wpseo_meta-robots-noindex',
		'_yoast_wpseo_meta-robots-nofollow',
		'_yoast_wpseo_meta-robots-adv',
	);

	public static function register(): void {
		add_filter('aipt_translated_meta_keys', array(__CLASS__, 'add_translated_keys'), 5);
		add_filter('aipt_copied_meta_keys', array(__CLASS__, 'add_copied_keys'), 5, 2);
		// After the site's own filters: the exclusions always win.
		add_filter('aipt_copied_meta_keys', array(__CLASS__, 'remove_excluded_keys'), PHP_INT_MAX);
	}

	public static function enabled(): bool {
		return !empty(AIPT_Settings::get()['translate_yoast']) && aipt_yoast_active();
	}

	public static function is_key(string $key): bool {
		return str_starts_with($key, self::PREFIX);
	}

	public static function is_primary_key(string $key): bool {
		return str_starts_with($key, self::PRIMARY_PREFIX);
	}

	// Keys that follow the source on an update in overwrite mode, like the terms do: a
	// copied key is replaced even when the translation has a value, and every such key
	// (translated ones too) is deleted when the source has no value for it.
	public static function follows_source(string $key): bool {
		return in_array($key, self::COPY_KEYS, true) || in_array($key, self::TRANSLATE_KEYS, true) || self::is_primary_key($key);
	}

	public static function add_translated_keys($keys) {
		return self::enabled() ? array_merge((array) $keys, self::TRANSLATE_KEYS) : $keys;
	}

	// COPY_KEYS and the source's primary-term keys, whatever the backend's config says.
	public static function add_copied_keys($keys, $source_id = 0) {
		if (!self::enabled()) {
			return $keys;
		}
		$source  = get_post_meta((int) $source_id);
		$primary = array_filter(array_map('strval', array_keys(is_array($source) ? $source : array())), array(__CLASS__, 'is_primary_key'));
		return array_merge((array) $keys, self::COPY_KEYS, $primary);
	}

	// Yoast keys other than COPY_KEYS and primary terms are never copied (all of them
	// while not enabled()).
	public static function remove_excluded_keys($keys) {
		$enabled = self::enabled();
		return array_values(array_filter((array) $keys, static fn($key): bool => !is_string($key) || !self::is_key($key)
			|| ($enabled && (in_array($key, self::COPY_KEYS, true) || self::is_primary_key($key)))));
	}
}
