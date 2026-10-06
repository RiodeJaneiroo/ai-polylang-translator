<?php
// Translation of string-translation strings (WPML String Translation domains such as
// Yoast SEO title templates), used by `wp aipt translate-strings` (a whole domain at once)
// and the bulk page (AIPT_Bulk_Strings, slice() by slice()). The adapter lists a
// domain's strings (AIPT_Lang::strings) and saves each translation
// (AIPT_Lang::save_string_translation); values go to AIPT_Gateway in batches like term
// names. A translation whose placeholders differ from the source is not saved.

if (!defined('ABSPATH')) {
	exit;
}

class AIPT_Strings {

	// Appended to the gateway's system prompt for string batches.
	const PROMPT_RULE = 'The values are interface strings and SEO title/description templates. Keep every placeholder exactly as it is: variables of the form %%name%%, printf placeholders such as %s, %d or %1$s, and anything in double curly braces such as {{name}}. Keep HTML tags and their attributes unchanged.';

	// Placeholders that must come back unchanged (the same multiset as in the source).
	const PLACEHOLDERS = '/%%[A-Za-z0-9_\-]+%%|%(?:\d+\$)?[sdfFu]|\{\{.*?\}\}/u';

	// Hard cap on strings to translate in one slice(), whatever the batch packing.
	const SLICE_MAX_STRINGS = 120;

	/**
	 * The part of $strings (AIPT_Lang::strings() rows, ID order) after string ID $after
	 * whose strings to translate fit in $max_batches gateway batches (and at most
	 * SLICE_MAX_STRINGS of them), with the skipped strings in between. 'next' = the last
	 * string ID it covers (pass it as $after for the next part), null when it reaches the
	 * end; 'remaining' = strings to translate after it; 'batches' = gateway batches
	 * translate() sends for it.
	 *
	 * @return array{strings: array, next: int|null, remaining: int, batches: int}
	 */
	public static function slice(array $strings, bool $update, int $after, int $max_batches): array {
		$max_batches = max(1, $max_batches);
		$slice       = array();
		$items       = array();
		$next        = null;
		$remaining   = 0;
		foreach ($strings as $string) {
			if ((int) $string['id'] <= $after) {
				continue;
			}
			$todo = self::skip_status($string, $update) === '';
			$item = array('id' => 's' . $string['id'], 'text' => $string['value']);
			if ($next === null && $todo && $items
				&& (count($items) >= self::SLICE_MAX_STRINGS || count(AIPT_Extractor::build_batches(array_merge($items, array($item)))) > $max_batches)) {
				$next = (int) $slice[count($slice) - 1]['id'];
			}
			if ($next !== null) {
				$remaining += $todo ? 1 : 0;
				continue;
			}
			$slice[] = $string;
			if ($todo) {
				$items[] = $item;
			}
		}
		return array(
			'strings'   => $slice,
			'next'      => $next,
			'remaining' => $remaining,
			'batches'   => count(AIPT_Extractor::build_batches($items)),
		);
	}

	/**
	 * Rows are streamed to $on_row: skipped strings as they are found, the others after
	 * their batch is translated and saved. Each row is an AIPT_Record::outcome() plus
	 * 'string' (the adapter row), 'chars' (dry run only) and 'code' (the gateway error
	 * code of a failed batch). Statuses: skipped_empty, skipped_no_text (only placeholders
	 * or markup), skipped_existing (finished translation, without $update), would_create /
	 * would_update (dry run), created / updated, error. $on_row returning false stops.
	 *
	 * @param array      $strings       AIPT_Lang::strings() rows of $domain for $target.
	 * @param float|null $dry_run_price USD per character: no API calls and no writes.
	 */
	public static function translate(string $domain, array $strings, string $from, string $target, bool $update, ?float $dry_run_price = null, ?callable $on_row = null): void {
		$pending = array();
		foreach ($strings as $string) {
			$skip = self::skip_status($string, $update);
			if ($skip !== '') {
				if (!self::emit($on_row, $string, AIPT_Record::outcome($skip))) {
					return;
				}
				continue;
			}
			$pending['s' . $string['id']] = $string;
		}
		if (!$pending) {
			return;
		}

		if ($dry_run_price !== null) {
			foreach ($pending as $string) {
				$chars            = mb_strlen($string['value'], 'UTF-8');
				$outcome          = AIPT_Record::outcome($string['translated'] ? 'would_update' : 'would_create', 0, $dry_run_price * $chars, sprintf('%d chars', $chars));
				$outcome['chars'] = $chars;
				if (!self::emit($on_row, $string, $outcome)) {
					return;
				}
			}
			return;
		}

		$job_meta = array(
			'post_id' => 0,
			'target'  => $target,
			'model'   => AIPT_Settings::get()['model'],
			/* translators: %s: string domain, e.g. admin_texts_wpseo_titles */
			'title'   => sprintf(__('Strings: %s', 'ai-polylang-translator'), $domain),
		);
		// Each batch is saved and its rows streamed before the next one is sent; a batch's
		// cost is split evenly over its strings.
		$items = array_map(static fn(array $string): string => $string['value'], $pending);
		AIPT_Gateway::translate_items($items, $from, $target, $job_meta, self::PROMPT_RULE, static function (array $keys, $result, float $cost) use ($pending, $target, $on_row): bool {
			$cost = $cost / count($keys);
			foreach ($keys as $key) {
				if (is_wp_error($result)) {
					$outcome         = AIPT_Record::outcome('error', 0, $cost, 'translation failed: ' . $result->get_error_message(), true);
					$outcome['code'] = $result->get_error_code();
				} else {
					$outcome = self::save($pending[$key], $target, (string) ($result[$key] ?? ''), $cost);
				}
				if (!self::emit($on_row, $pending[$key], $outcome)) {
					return false;
				}
			}
			return true;
		});
	}

	// '' = translate it.
	private static function skip_status(array $string, bool $update): string {
		$value = trim($string['value']);
		if ($value === '') {
			return 'skipped_empty';
		}
		$text = wp_strip_all_tags((string) preg_replace(self::PLACEHOLDERS, ' ', $value));
		if (!AIPT_Extractor::is_translatable($text)) {
			return 'skipped_no_text';
		}
		return $string['translated'] && !$update ? 'skipped_existing' : '';
	}

	private static function save(array $string, string $target, string $text, float $cost): array {
		// Model output, sanitized like the writer does for users without unfiltered_html.
		if (!current_user_can('unfiltered_html')) {
			$text = wp_kses_post($text);
		}
		if (trim($text) === '') {
			return AIPT_Record::outcome('error', 0, $cost, 'the model returned an empty translation', true);
		}
		if (self::placeholders($text) !== self::placeholders($string['value'])) {
			return AIPT_Record::outcome('error', 0, $cost, sprintf(
				'placeholders changed, not saved: %s -> %s',
				implode(' ', self::placeholders($string['value'])),
				implode(' ', self::placeholders($text))
			), true);
		}
		$saved = aipt_lang()->save_string_translation((int) $string['id'], $target, $text);
		if (is_wp_error($saved)) {
			return AIPT_Record::outcome('error', 0, $cost, $saved->get_error_message(), true);
		}
		return AIPT_Record::outcome($string['translated'] ? 'updated' : 'created', 0, $cost, $text, true);
	}

	/**
	 * @return string[] Sorted placeholders of $text.
	 */
	private static function placeholders(string $text): array {
		preg_match_all(self::PLACEHOLDERS, $text, $matches);
		$found = $matches[0];
		sort($found);
		return $found;
	}

	// False when $on_row asked to stop.
	private static function emit(?callable $on_row, array $string, array $outcome): bool {
		$outcome['string'] = $string;
		return !$on_row || $on_row($outcome) !== false;
	}
}
