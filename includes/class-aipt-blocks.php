<?php
// Block-comment masking for post content chunks. Gutenberg/Kadence delimiters
// (`<!-- wp:ns/name {json} -->`, `<!-- wp:name /-->`, `<!-- /wp:name -->`) are replaced
// with plain-text tokens `[[AIPT-B<n>]]` before a chunk goes to the model, so block JSON
// is kept out of the model's input and restored from the source afterwards. Tokens are
// plain text, so they also survive wp_kses_post. A chunk whose tokens come back changed
// keeps its source text, and a new translation is then left as a draft (AIPT_Writer).
// Entries travel in the job under 'blocks', keyed by content chunk index (only chunks
// that had delimiters; jobs of posts without blocks carry no 'blocks' key), see
// job_entry(). Masking never fails open: a chunk that already contains `[[AIPT-B` gets
// tokens with a suffix it does not contain, and a chunk the delimiter pattern cannot
// scan (PCRE error) is not sent at all but kept in the source language (a fallback).

if (!defined('ABSPATH')) {
	exit;
}

class AIPT_Blocks {

	// The delimiter grammar of WP_Block_Parser::next_token().
	const DELIMITER = '~<!--\s+/?wp:(?:[a-z][a-z0-9_-]*/)?[a-z][a-z0-9_-]*\s+(?:\{(?:(?:[^}]+|}+(?=})|(?!}\s+/?-->).)*+)?}\s+)?/?-->~s';

	// Every token starts with this. The default token is PREFIX . n . ']]'; a chunk that
	// contains PREFIX uses PREFIX . <letters> . '-' instead (stored in its job entry).
	const PREFIX = '[[AIPT-B';

	// Added to the system prompt when a batch carries tokens (AIPT_Gateway).
	const PROMPT_RULE = 'Keep every token that starts with ' . self::PREFIX . ' and ends with ]] (for example ' . self::PREFIX . '0]]) exactly as it is, in the same place relative to the surrounding text; never translate, remove, merge or reorder them.';

	/**
	 * @return array{text: string, map: string[], prefix: string, fallback: bool} map[n] is
	 *         the delimiter that token n replaced. fallback: the chunk could not be scanned
	 *         and must not be sent (text is the unmasked source).
	 */
	public static function mask(string $html): array {
		$out = array('text' => $html, 'map' => array(), 'prefix' => self::PREFIX, 'fallback' => false);
		$has = preg_match('~<!--\s+/?wp:~', $html);
		if ($has === 0) {
			return $out;
		}
		// Unmasking must be able to tell our tokens from text that looks like them.
		$prefix = self::PREFIX;
		for ($salt = 0; str_contains($html, $prefix); $salt++) {
			$prefix = self::PREFIX . strtr(substr(md5($salt . $html), 0, 6), '0123456789', 'ghijklmnop') . '-';
		}

		$map  = array();
		$text = $has === false ? null : preg_replace_callback(
			self::DELIMITER,
			static function (array $match) use (&$map, $prefix): string {
				$token = self::token(count($map), $prefix);
				$map[] = $match[0];
				return $token;
			},
			$html
		);
		if (!is_string($text)) { // PCRE failure (backtrack limit): never send it unmasked.
			$out['fallback'] = true;
			return $out;
		}
		return array('text' => $text, 'map' => $map, 'prefix' => $prefix, 'fallback' => false);
	}

	/**
	 * What the job keeps for one masked chunk: null (nothing to restore), the delimiter
	 * list (default prefix), ['prefix' => ..., 'map' => [...]], or ['fallback' => true,
	 * 'source' => chunk] for a chunk that is not sent and keeps its source text.
	 *
	 * @param array $masked mask() result for $chunk.
	 */
	public static function job_entry(array $masked, string $chunk): ?array {
		if (!empty($masked['fallback'])) {
			return array('fallback' => true, 'source' => $chunk);
		}
		if (!$masked['map']) {
			return null;
		}
		return $masked['prefix'] === self::PREFIX ? $masked['map'] : array('prefix' => $masked['prefix'], 'map' => $masked['map']);
	}

	/**
	 * Whether any of the values (model input) carries a token.
	 *
	 * @param string|string[] $values
	 */
	public static function has_tokens(string|array $values): bool {
		return str_contains(implode("\n", array_map('strval', (array) $values)), self::PREFIX);
	}

	public static function unmask(string $text, array $map, string $prefix = self::PREFIX): string {
		$pairs = array();
		foreach (array_values($map) as $n => $delimiter) {
			$pairs[self::token($n, $prefix)] = (string) $delimiter;
		}
		return $pairs ? strtr($text, $pairs) : $text;
	}

	/**
	 * Every token of the map appears exactly once, in order, and no other token does.
	 * No leading zeros: `[[AIPT-B01]]` is not token 1.
	 */
	public static function tokens_intact(string $text, array $map, string $prefix = self::PREFIX): bool {
		preg_match_all('~' . preg_quote($prefix, '~') . '(0|[1-9]\d*)\]\]~', $text, $matches);
		$found = array_map('intval', $matches[1]);
		return $found === ($map ? range(0, count($map) - 1) : array());
	}

	/**
	 * Restore the delimiters in one translated item (writer, after wp_kses_post: the
	 * delimiters are source text and never pass through it). Items without a map are
	 * returned unchanged. If the model dropped, duplicated, reordered or mangled a token,
	 * the original source chunk is used instead and the item ID is added to $fallbacks.
	 */
	public static function restore(array $job, array $item, string $text, array &$fallbacks): string {
		$path = $item['path'] ?? array();
		if (($path[0] ?? '') !== 'post' || ($path[1] ?? '') !== 'content') {
			return $text;
		}
		list($map, $prefix) = self::entry_map($job['blocks'][(int) ($path[2] ?? 0)] ?? null);
		if (!$map) {
			return $text;
		}
		if (self::tokens_intact($text, $map, $prefix)) {
			return self::unmask($text, $map, $prefix);
		}
		$fallbacks[] = (string) ($item['id'] ?? '');
		return self::unmask((string) ($item['text'] ?? ''), $map, $prefix);
	}

	/**
	 * Content chunks that were never sent (job_entry() fallbacks): chunk index => source
	 * text, each added to $fallbacks, so the writer's chunk join stays complete.
	 *
	 * @return array<int, string>
	 */
	public static function unsent_chunks(array $job, array &$fallbacks): array {
		$chunks = array();
		foreach ((array) ($job['blocks'] ?? array()) as $index => $entry) {
			if (is_array($entry) && !empty($entry['fallback'])) {
				$chunks[(int) $index] = (string) ($entry['source'] ?? '');
				$fallbacks[]          = 'chunk' . $index;
			}
		}
		return $chunks;
	}

	/**
	 * The writer's result when some chunks fell back to the source: an
	 * 'aipt_written_with_warning' error carrying ['post_id' => ID] (AIPT_Pipeline: written,
	 * reported with a warning). When $written already is that warning (the final
	 * status/slug update failed), the message is added to it as a second message.
	 *
	 * @param int|WP_Error $written Post ID, or the writer's 'aipt_written_with_warning' error.
	 * @param bool         $is_new  A new translation, left as a draft by the writer.
	 * @return int|WP_Error
	 */
	public static function result($written, array $fallbacks, int $post_id, bool $is_new) {
		if (!$fallbacks) {
			return $written;
		}
		$message = sprintf(
			$is_new
				/* translators: 1: translation post ID, 2: number of content parts */
				? __('Translation #%1$d was saved and left as a draft: some content parts (%2$d) kept the original text because their block markup could not be protected or came back changed. Translate the post again or edit those parts manually.', 'ai-polylang-translator')
				/* translators: 1: translation post ID, 2: number of content parts */
				: __('Translation #%1$d was saved, but some content parts (%2$d) kept the original text because their block markup could not be protected or came back changed. Translate the post again or edit those parts manually.', 'ai-polylang-translator'),
			$post_id,
			count($fallbacks)
		);
		if (is_wp_error($written)) {
			$written->add('aipt_written_with_warning', $message, array('post_id' => $post_id));
			return $written;
		}
		return new WP_Error('aipt_written_with_warning', $message, array('post_id' => $post_id));
	}

	/**
	 * @param mixed $entry A job_entry() value (or a delimiter list from older jobs).
	 * @return array{0: string[], 1: string} [delimiter map, token prefix]; empty map when
	 *         there is nothing to restore.
	 */
	private static function entry_map($entry): array {
		if (!is_array($entry) || !empty($entry['fallback'])) {
			return array(array(), self::PREFIX);
		}
		if (isset($entry['prefix'])) {
			return array((array) ($entry['map'] ?? array()), (string) $entry['prefix']);
		}
		return array($entry, self::PREFIX);
	}

	private static function token(int $n, string $prefix): string {
		return $prefix . $n . ']]';
	}
}
