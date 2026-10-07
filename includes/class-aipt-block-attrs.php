<?php
// Text stored in block attributes (the JSON of a block delimiter comment, e.g. a dynamic
// block's button label), which AIPT_Blocks masks away from the model. Which attribute keys
// are text comes from generic sources only, merged per block name into one config in the
// shape of WPML's 'wpml-gutenberg-config' `key` part (AIPT_Block_Attrs_Config).
// Key semantics mirror WPML\PB\Gutenberg\StringsInBlock\Attributes: exact, wildcard (`*`)
// or regex (search-method="regex") key match; any `type` (link, media-url, media-ids, …)
// is not text; arrays recurse with `children` (none = every key); encoding="json" values
// are URL-encoded JSON. Items: ['blockattr', chunk N, delimiter n, ...key path] ('#json'
// marks a decoded JSON-encoded value), only for content chunks that are sent. apply()
// rebuilds those delimiters in the job's block maps before AIPT_Blocks::restore(); the
// rest of the JSON stays byte-identical, and a value that cannot be written back exactly
// keeps its source text (silently, no fallback warning).

if (!defined('ABSPATH')) {
	exit;
}

class AIPT_Block_Attrs {

	const JSON = '#json';

	// An opening or self-closing delimiter with attributes: 1 = everything before the JSON,
	// 2 = namespace with slash, 3 = name, 4 = JSON, 5 = the closing part.
	const OPENER = '~^(<!--\s+wp:((?:[a-z][a-z0-9_-]*/)?)([a-z][a-z0-9_-]*)\s+)(\{.*\})(\s+/?-->)$~s';

	/**
	 * Adds one item per text value in the attributes of the chunk's delimiters.
	 *
	 * @param array    $masked AIPT_Blocks::mask() result of a chunk that is sent.
	 * @param callable $add    AIPT_Extractor's add(path, text).
	 */
	public static function extract(array $masked, int $chunk, callable $add): void {
		if (empty($masked['map'])) {
			return;
		}
		$config = AIPT_Block_Attrs_Config::get();
		foreach (array_values($masked['map']) as $n => $delimiter) {
			$parsed = self::parse((string) $delimiter);
			if (!$parsed || empty($config[$parsed['name']])) {
				continue;
			}
			$attrs = json_decode($parsed['json'], true);
			if (!is_array($attrs)) {
				continue;
			}
			$found = array();
			self::walk($attrs, (array) $config[$parsed['name']], array(), $found);
			foreach ($found as list($path, $text)) {
				$add(array_merge(array('blockattr', $chunk, $n), $path), $text);
			}
		}
	}

	/**
	 * The job's 'blocks' with every delimiter that has translated attribute items rebuilt.
	 * Model output is sanitized like other translated strings when $kses; an empty
	 * translation keeps the source value.
	 */
	public static function apply(array $job, array $results, bool $kses): array {
		$blocks = (array) ($job['blocks'] ?? array());
		$edits  = array();
		foreach ((array) ($job['items'] ?? array()) as $id => $item) {
			$path = (array) ($item['path'] ?? array());
			if (($path[0] ?? '') !== 'blockattr' || count($path) < 4 || !isset($results[$id])) {
				continue;
			}
			$text = $kses ? AIPT_Writer::sanitize_translated((string) $results[$id]) : (string) $results[$id];
			if ($kses) {
				// Attribute values are plain text: kses turns "&" into "&amp;", which would
				// be stored (and shown) literally.
				$text = str_replace('&amp;', '&', $text);
			}
			if (trim($text) !== '') {
				$edits[(int) $path[1]][(int) $path[2]][] = array(array_slice($path, 3), $text);
			}
		}
		foreach ($edits as $chunk => $delimiters) {
			$entry = $blocks[$chunk] ?? null;
			if (!is_array($entry) || !empty($entry['fallback'])) {
				continue;
			}
			$wrapped = isset($entry['prefix']);
			$map     = $wrapped ? (array) ($entry['map'] ?? array()) : $entry;
			foreach ($delimiters as $n => $leaves) {
				if (isset($map[$n]) && is_string($map[$n])) {
					$map[$n] = self::rebuild($map[$n], $leaves);
				}
			}
			if ($wrapped) {
				$entry['map'] = $map;
			} else {
				$entry = $map;
			}
			$blocks[$chunk] = $entry;
		}
		return $blocks;
	}

	/**
	 * @param array $found [[key path, text], ...]
	 */
	private static function walk(array $attrs, array $keys, array $path, array &$found): void {
		foreach ($attrs as $key => $value) {
			$config = self::match((string) $key, $keys);
			if ($config === null || !empty($config['type'])) {
				continue;
			}
			$sub = array_merge($path, array($key));
			if (($config['encoding'] ?? '') === 'json') {
				if (!is_string($value) || self::json_codec($value) === null) {
					continue; // could not be written back byte-identically
				}
				$value = json_decode(urldecode($value), true);
				$sub[] = self::JSON;
			}
			if (is_array($value)) {
				self::walk($value, (array) ($config['children'] ?? array('*' => array())), $sub, $found);
			} elseif (self::is_text($value) && !self::is_identifier($value, $config)) {
				$found[] = array($sub, $value);
			}
		}
	}

	private static function match(string $key, array $keys): ?array {
		if (isset($keys[$key]) && is_array($keys[$key])) {
			return $keys[$key];
		}
		foreach ($keys as $pattern => $config) {
			if (!is_array($config)) {
				continue;
			}
			$regex = ($config['search-method'] ?? '') === 'regex'
				? (string) $pattern
				: '/^' . str_replace('\*', '.*', preg_quote((string) $pattern, '/')) . '$/';
			if (@preg_match($regex, $key) === 1) { // a broken regex from a config file is no match
				return $config;
			}
		}
		return null;
	}

	// Text with letters that is not a URL, path, anchor, e-mail or phone link (some
	// configs list URL keys without a type). A value starting with `#`, `?` or `/` is a
	// fragment, query or path only without whitespace (`#footer-modal-form`, `/contacts/`);
	// "#1 Best choice" and "/ 2 pcs." are text.
	private static function is_text($value): bool {
		if (!is_string($value) || !AIPT_Extractor::is_translatable($value)) {
			return false;
		}
		$value = trim($value);
		return !preg_match('~^(?:[/#?]\S*$|mailto:|tel:|www\.\S+$|[a-z][a-z0-9+.-]*://\S*$)~i', $value);
	}

	// For keys only block.json marks as content: a lowercase ASCII token without spaces
	// (`_blank`, `image`, `youtube`, `nofollow`) is a setting, not text.
	private static function is_identifier(string $value, array $config): bool {
		return ($config[AIPT_Block_Attrs_Config::SOURCE_KEY] ?? '') === AIPT_Block_Attrs_Config::SOURCE_BLOCK_JSON
			&& preg_match('/^[a-z0-9_.:\-]+$/', trim($value)) === 1;
	}

	/**
	 * @return array{name: string, json: string, head: string, tail: string}|null
	 */
	private static function parse(string $delimiter): ?array {
		if (!preg_match(self::OPENER, $delimiter, $m)) {
			return null;
		}
		return array('name' => ($m[2] !== '' ? $m[2] : 'core/') . $m[3], 'json' => $m[4], 'head' => $m[1], 'tail' => $m[5]);
	}

	/**
	 * One delimiter with its translated leaves, or the source delimiter when the result
	 * would not be the same block with exactly those values changed.
	 *
	 * @param array $leaves [[key path, text], ...]
	 */
	private static function rebuild(string $delimiter, array $leaves): string {
		$parsed = self::parse($delimiter);
		$tree   = $parsed ? json_decode($parsed['json']) : null;
		if (!is_object($tree)) {
			return $delimiter;
		}
		$source = json_decode($parsed['json']);
		$same   = self::encode($tree) === $parsed['json'];
		$outer  = array();
		foreach ($leaves as list($path, $text)) {
			if (self::set($tree, $path, (string) $text)) {
				$top = array_search(self::JSON, $path, true);
				$top = $top === false ? $path : array_slice($path, 0, $top);
				$outer[serialize($top)] = $top;
			}
		}

		$json    = $same ? self::encode($tree) : $parsed['json'];
		$applied = array();
		foreach ($outer as $path) {
			$old = self::get($source, $path);
			$new = self::get($tree, $path);
			if (!is_string($old) || !is_string($new) || $old === $new) {
				continue;
			}
			if (!$same) {
				$replaced = self::replace_leaf($json, end($path), $old, $new);
				if ($replaced === null) {
					continue;
				}
				$json = $replaced;
			}
			$applied[] = array($path, $new);
		}
		if (!$applied || $json === '') {
			return $delimiter;
		}

		// The rebuilt delimiter must parse as the same block with only those values changed.
		$rebuilt = $parsed['head'] . $json . $parsed['tail'];
		$blocks  = preg_match(AIPT_Blocks::DELIMITER, $rebuilt, $m) && $m[0] === $rebuilt ? parse_blocks($rebuilt) : array();
		$block   = $blocks[0] ?? null;
		$expect  = json_decode($parsed['json'], true);
		foreach ($applied as list($path, $new)) {
			$ref =& $expect;
			foreach ($path as $segment) {
				$ref =& $ref[$segment];
			}
			$ref = $new;
			unset($ref);
		}
		if (!is_array($block) || ($block['blockName'] ?? '') !== $parsed['name'] || ($block['attrs'] ?? null) !== $expect) {
			return $delimiter;
		}
		return $rebuilt;
	}

	// Core's attribute serializer (serialize_block_attributes() is untyped and also takes
	// the stdClass tree, so `{}` stays `{}`). '' when the value cannot be encoded.
	private static function encode($attrs): string {
		return (string) serialize_block_attributes($attrs);
	}

	/**
	 * Replace `"key":<old>` (exactly one occurrence, old encoded as the source encodes it)
	 * with `"key":<new>` encoded like core. Null when it is not found exactly once.
	 *
	 * @param int|string $key
	 */
	private static function replace_leaf(string $json, $key, string $old, string $new): ?string {
		if (!is_string($key)) {
			return null; // a list item has no key to anchor on
		}
		$encoders = array(
			array(__CLASS__, 'encode'),
			static fn($v) => wp_json_encode($v),
			static fn($v) => wp_json_encode($v, JSON_UNESCAPED_UNICODE),
			static fn($v) => wp_json_encode($v, JSON_UNESCAPED_SLASHES),
			static fn($v) => wp_json_encode($v, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE),
		);
		foreach ($encoders as $encoder) {
			$k = (string) call_user_func($encoder, $key);
			$v = (string) call_user_func($encoder, $old);
			if ($k === '' || $v === '') {
				continue;
			}
			$pattern = '~(' . preg_quote($k, '~') . '\s*:\s*)' . preg_quote($v, '~') . '~';
			if (preg_match_all($pattern, $json) === 1) {
				$encoded = self::encode($new);
				$result  = $encoded === '' ? null : preg_replace_callback($pattern, static fn(array $m): string => $m[1] . $encoded, $json, 1);
				return is_string($result) ? $result : null;
			}
		}
		return null;
	}

	/**
	 * Set a string leaf in a json_decode() object tree. '#json' decodes a URL-encoded JSON
	 * string and encodes it again with the codec that reproduced it.
	 *
	 * @param mixed $node
	 */
	private static function set(&$node, array $path, string $value): bool {
		if (!$path) {
			if (!is_string($node)) {
				return false;
			}
			$node = $value;
			return true;
		}
		$segment = array_shift($path);
		if ($segment === self::JSON) {
			$codec = is_string($node) ? self::json_codec($node) : null;
			$inner = $codec === null ? null : json_decode(urldecode($node));
			if ($codec === null || !self::set($inner, $path, $value)) {
				return false;
			}
			$node = self::json_encode_attr($inner, $codec[0], $codec[1]);
			return true;
		}
		if (is_object($node) && property_exists($node, (string) $segment)) {
			return self::set($node->{$segment}, $path, $value);
		}
		if (is_array($node) && array_key_exists($segment, $node)) {
			return self::set($node[$segment], $path, $value);
		}
		return false;
	}

	/**
	 * @param mixed $node
	 * @return mixed Null when the path does not resolve.
	 */
	private static function get($node, array $path) {
		foreach ($path as $segment) {
			if (is_object($node) && property_exists($node, (string) $segment)) {
				$node = $node->{$segment};
			} elseif (is_array($node) && array_key_exists($segment, $node)) {
				$node = $node[$segment];
			} else {
				return null;
			}
		}
		return $node;
	}

	/**
	 * [json flags, JS encodeURIComponent style] that turn the decoded value back into
	 * exactly $raw (WPML writes rawurlencode(wp_json_encode()), editors write
	 * encodeURIComponent(JSON.stringify())), or null.
	 */
	private static function json_codec(string $raw): ?array {
		$decoded = json_decode(urldecode($raw));
		if ($decoded === null) {
			return null;
		}
		// Editor style first: an ASCII-only value matches several codecs.
		foreach (array(JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE, 0, JSON_UNESCAPED_UNICODE, JSON_UNESCAPED_SLASHES) as $flags) {
			foreach (array(true, false) as $js) {
				if (self::json_encode_attr($decoded, $flags, $js) === $raw) {
					return array($flags, $js);
				}
			}
		}
		return null;
	}

	private static function json_encode_attr($value, int $flags, bool $js): string {
		$encoded = rawurlencode((string) wp_json_encode($value, $flags));
		return $js ? strtr($encoded, array('%21' => '!', '%2A' => '*', '%27' => "'", '%28' => '(', '%29' => ')')) : $encoded;
	}
}
