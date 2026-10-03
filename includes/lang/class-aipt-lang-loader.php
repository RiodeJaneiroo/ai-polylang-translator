<?php
// Detects the active multilingual plugin and loads exactly one AIPT_Lang
// implementation. The bootstrap calls detect()/load() on plugins_loaded; everything
// else reaches the adapter through aipt_lang().

if (!defined('ABSPATH')) {
	exit;
}

require_once __DIR__ . '/interface-aipt-lang.php';

class AIPT_Lang_Loader {

	// name => [implementation file, class].
	const IMPLEMENTATIONS = array(
		'polylang' => array('class-aipt-lang-polylang.php', 'AIPT_Lang_Polylang'),
		'wpml'     => array('class-aipt-lang-wpml.php', 'AIPT_Lang_WPML'),
	);

	private static $instance = null;

	/**
	 * @return string|null 'polylang' | 'wpml' | 'both' | null (neither is active).
	 */
	public static function detect(): ?string {
		$polylang = function_exists('pll_languages_list');
		$wpml     = defined('ICL_SITEPRESS_VERSION') || class_exists('SitePress', false);
		if ($polylang && $wpml) {
			return 'both';
		}
		if ($polylang) {
			return 'polylang';
		}
		return $wpml ? 'wpml' : null;
	}

	/**
	 * Loads the adapter for the detected plugin once. Null when there is none to use:
	 * no multilingual plugin, both active, or a plugin without an implementation.
	 */
	public static function load(): ?AIPT_Lang {
		if (self::$instance) {
			return self::$instance;
		}
		$name = self::detect();
		if ($name === null || !isset(self::IMPLEMENTATIONS[$name])) {
			return null;
		}
		list($file, $class) = self::IMPLEMENTATIONS[$name];
		require_once __DIR__ . '/' . $file;
		self::$instance = new $class();
		return self::$instance;
	}

	public static function active(): ?AIPT_Lang {
		return self::$instance;
	}

	/**
	 * Display name of the loaded adapter for UI text ('Polylang', 'WPML'); '' when none
	 * is loaded.
	 */
	public static function label(): string {
		return self::$instance ? self::$instance->label() : '';
	}
}

/**
 * The loaded adapter. Only call it after the bootstrap decided an adapter exists
 * (AIPT_Lang_Loader::active() is not null); anything else is a programming error.
 */
function aipt_lang(): AIPT_Lang {
	$lang = AIPT_Lang_Loader::active();
	if (!$lang) {
		throw new LogicException('AI Translator: no multilingual adapter is loaded.');
	}
	return $lang;
}
