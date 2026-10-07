<?php
// Which block attributes hold text (AIPT_Block_Attrs), from generic sources only, merged
// per block name into one config in the shape of the `key` part of WPML's
// 'wpml-gutenberg-config' option: blockName => [attribute key => [type?, search-method?,
// encoding?, label?, children?]]. Sources, in this order:
// 1. wpml-config.xml <gutenberg-block> definitions that block plugins and themes ship: on
//    WPML its parsed option (aipt_lang()->block_text_attributes()), the files themselves
//    (from_config_files(), parsed like WPML_Gutenberg_Config_Option) elsewhere and when
//    that option is empty (missing or not built yet);
// 2. block.json attributes with "role": "content", type string, no `source` (a sourced
//    attribute lives in the block HTML, which is translated with the content) and a name
//    that does not look like a URL, type, target or other setting (is_non_text_name()); keys
//    only this source defines carry SOURCE_KEY => SOURCE_BLOCK_JSON (AIPT_Block_Attrs then
//    also skips identifier-like values), a definition from 1 replaces them;
// 3. the 'aipt_block_text_attributes' filter ($config, backend name).
// Per block the keys are a union; a `type` from any source wins (the key is not text).

if (!defined('ABSPATH')) {
	exit;
}

class AIPT_Block_Attrs_Config {

	const TRANSIENT = 'aipt_block_attrs';

	// Marker of keys only block.json defines (WPML ignores unknown key-config entries).
	const SOURCE_KEY        = 'aipt_source';
	const SOURCE_BLOCK_JSON = 'block_json';

	// Since WordPress 6.7 "role": "content" marks every attribute editable in contentOnly
	// mode, not only human text: URLs, link targets/rel, media and embed types, provider
	// slugs, icons, dates, LaTeX. is_non_text_name() splits a name into words (camelCase,
	// `_`, `-`; a run of capitals is one word) and drops it when any word is in
	// NON_TEXT_WORDS, unless its last word is in TEXT_WORDS (`linkText`, `iconLabel`).
	const NON_TEXT_WORDS = array(
		'url', 'href', 'link', 'target', 'rel', 'type', 'slug', 'icon', 'date', 'time', 'datetime', 'latex',
		'class', 'classname', 'anchor', 'format', 'mime', 'lang', 'id', 'ids', 'size', 'width', 'height',
		'color', 'align', 'alignment', 'ratio', 'scale',
	);
	const TEXT_WORDS     = array('text', 'label', 'title', 'caption', 'heading', 'placeholder', 'description', 'content', 'name');

	/**
	 * blockName => key config. Memoized once block types are registered (init).
	 */
	public static function get(): array {
		static $memo = null;
		if ($memo !== null) {
			return $memo;
		}
		$config = aipt_lang()->name() === 'wpml' ? aipt_lang()->block_text_attributes() : array();
		if (!$config) {
			$config = self::from_config_files();
		}
		$config = self::merge($config, self::from_block_types());
		$config = (array) apply_filters('aipt_block_text_attributes', $config, aipt_lang()->name());
		if (did_action('init')) {
			$memo = $config;
		}
		return $config;
	}

	/**
	 * Key configs parsed from the wpml-config.xml files of the active plugins (also
	 * network-active), mu-plugins, the active theme and its parent, and
	 * wp-content/polylang/wpml-config.xml. Cached for a day, invalidated when the file
	 * list or a file's modification time changes.
	 */
	public static function from_config_files(): array {
		$files = array();
		$roots = array();
		$plugins = (array) get_option('active_plugins', array());
		if (is_multisite()) {
			$plugins = array_merge($plugins, array_keys((array) get_site_option('active_sitewide_plugins', array())));
		}
		foreach ($plugins as $plugin) {
			$dir = dirname((string) $plugin);
			if ($dir !== '.' && $dir !== '') {
				$roots[] = WP_PLUGIN_DIR . '/' . $dir;
			}
		}
		$mu    = defined('WPMU_PLUGIN_DIR') ? glob(WPMU_PLUGIN_DIR . '/*/wpml-config.xml') : array();
		$roots = array_merge($roots, array(get_template_directory(), get_stylesheet_directory(), WP_CONTENT_DIR . '/polylang'));
		foreach (array_unique($roots) as $root) {
			$files[] = $root . '/wpml-config.xml';
		}
		$files = array_merge($files, is_array($mu) ? $mu : array());

		$stamp = array();
		foreach ($files as $file) {
			if (is_readable($file)) {
				$stamp[$file] = (int) filemtime($file);
			}
		}
		$hash   = md5(serialize($stamp));
		$cached = get_transient(self::TRANSIENT);
		if (is_array($cached) && ($cached['hash'] ?? '') === $hash && is_array($cached['config'] ?? null)) {
			return $cached['config'];
		}
		$config = array();
		foreach (array_keys($stamp) as $file) {
			$config = self::merge($config, self::parse_config_file($file));
		}
		set_transient(self::TRANSIENT, array('hash' => $hash, 'config' => $config), DAY_IN_SECONDS);
		return $config;
	}

	/**
	 * blockName => key config of one wpml-config.xml (translate="1" blocks with keys), as
	 * WPML_Gutenberg_Config_Option::get_keys_recursively() builds it. [] when unreadable.
	 */
	public static function parse_config_file(string $file): array {
		$errors = libxml_use_internal_errors(true);
		$xml    = simplexml_load_file($file, 'SimpleXMLElement', LIBXML_NONET);
		libxml_clear_errors();
		libxml_use_internal_errors($errors);
		if (!$xml || !isset($xml->{'gutenberg-blocks'})) {
			return array();
		}
		$config = array();
		foreach ($xml->{'gutenberg-blocks'} as $section) {
			foreach ($section->{'gutenberg-block'} as $block) {
				$name = (string) $block['type'];
				if ($name === '' || (string) $block['translate'] !== '1') {
					continue;
				}
				$keys = self::strip_include(self::xml_keys($block));
				if ($keys) {
					$config = self::merge($config, array($name => $keys));
				}
			}
		}
		return $config;
	}

	// block.json "role": "content" (or the older "__experimentalRole") string attributes
	// without a source whose name does not look non-textual (is_non_text_name()).
	private static function from_block_types(): array {
		$config = array();
		foreach (WP_Block_Type_Registry::get_instance()->get_all_registered() as $name => $type) {
			foreach ((array) $type->attributes as $key => $attr) {
				$role = $attr['role'] ?? ($attr['__experimentalRole'] ?? '');
				if ($role === 'content' && in_array('string', (array) ($attr['type'] ?? ''), true) && !isset($attr['source'])
					&& !self::is_non_text_name((string) $key)) {
					$config[$name][(string) $key] = array(self::SOURCE_KEY => self::SOURCE_BLOCK_JSON);
				}
			}
		}
		return $config;
	}

	// linkTarget, mediaType, feedURL, imageID: a word of NON_TEXT_WORDS; linkText,
	// iconLabel, dateLabel: the same, but the last word says it is text.
	private static function is_non_text_name(string $key): bool {
		$spaced = preg_replace('/(?<=[a-z0-9])(?=[A-Z])|(?<=[A-Z])(?=[A-Z][a-z])|[_\-\s]+/', ' ', $key);
		$words  = array_values(array_filter(explode(' ', strtolower((string) $spaced)), 'strlen'));
		if (!$words) {
			return false;
		}
		// Compound words the split cuts apart (className, class_name, dateTime).
		if (in_array(implode('', $words), self::NON_TEXT_WORDS, true)) {
			return true;
		}
		if (in_array(end($words), self::TEXT_WORDS, true)) {
			return false;
		}
		return (bool) array_intersect($words, self::NON_TEXT_WORDS);
	}

	// WPML_Gutenberg_Config_Option::get_keys_recursively(): post-ids/taxonomy-ids keys are
	// not strings ('to_include' unset); a parent key keeps only string children.
	private static function xml_keys(SimpleXMLElement $parent): array {
		$keys = array();
		foreach ($parent->key as $key) {
			$type    = (string) $key['type'];
			$partial = array();
			if (!in_array($type, array('post-ids', 'taxonomy-ids'), true)) {
				$partial['to_include'] = true;
				if ($type !== '') {
					$partial['type'] = $type;
				}
			}
			foreach (array('search-method', 'label', 'encoding') as $attr) {
				if (isset($key[$attr])) {
					$partial[$attr] = (string) $key[$attr];
				}
			}
			if (isset($key->key)) {
				$children = array_filter(self::xml_keys($key), static fn(array $child): bool => !empty($child['to_include']));
				if ($children) {
					$partial['children'] = $children;
				} else {
					unset($partial['to_include']);
				}
			}
			if ($partial) {
				$keys[(string) $key['name']] = $partial;
			}
		}
		return $keys;
	}

	private static function strip_include(array $keys): array {
		foreach ($keys as &$key) {
			unset($key['to_include']);
			if (isset($key['children'])) {
				$key['children'] = self::strip_include($key['children']);
			}
		}
		return $keys;
	}

	// Per block a union of keys; a `type` from either side wins (the key is not text).
	private static function merge(array $into, array $from): array {
		foreach ($from as $name => $keys) {
			$into[$name] = isset($into[$name]) ? self::merge_keys((array) $into[$name], (array) $keys) : (array) $keys;
		}
		return $into;
	}

	private static function merge_keys(array $into, array $from): array {
		foreach ($from as $key => $config) {
			if (!isset($into[$key])) {
				$into[$key] = $config;
				continue;
			}
			// A wpml-config.xml definition replaces a block.json-only one.
			if (($into[$key][self::SOURCE_KEY] ?? '') === self::SOURCE_BLOCK_JSON && ($config[self::SOURCE_KEY] ?? '') !== self::SOURCE_BLOCK_JSON) {
				$into[$key] = $config;
				continue;
			}
			if (!empty($config['type']) && empty($into[$key]['type'])) {
				$into[$key]['type'] = $config['type'];
			}
			if (isset($into[$key]['children'], $config['children'])) {
				$into[$key]['children'] = self::merge_keys((array) $into[$key]['children'], (array) $config['children']);
			}
		}
		return $into;
	}
}
