<?php
// Terms step of the bulk admin page (AIPT_Bulk_Ajax): one taxonomy × language in short
// slices, and its dry-run estimate. The source terms are ordered parents first
// (AIPT_Select::terms); a slice takes the next terms without a target translation from
// an offset in that order, as many as fit in MAX_BATCHES gateway batches (names and
// descriptions packed like AIPT_Terms does: 8 KB / 60 strings per batch), and creates
// them right away through AIPT_Terms, so a parent is always created in an earlier slice
// or earlier in the same one. An interrupted request re-pays at most one slice.

if (!defined('ABSPATH')) {
	exit;
}

class AIPT_Bulk_Terms {

	// Gateway batches per request; the slice lock TTL is one batch-lock TTL per batch.
	const MAX_BATCHES = 2;
	// Terms per request at most (terms without translatable text pack into no batch).
	const MAX_TERMS = 120;
	// Lock rows aipt_bulk_terms_<taxonomy>_<lang>: one slice of a pair at a time.
	const LOCK_PREFIX = 'aipt_bulk_terms_';

	/**
	 * @return array|WP_Error {rows: [{term_id, name, status, target_id, cost, message}],
	 *                        existing (terms passed over that already had a translation),
	 *                        next (offset of the next slice, null when done), remaining
	 *                        (terms still missing after this slice), cost, error_code (the
	 *                        gateway code of a failed row, '' if none)}.
	 */
	public static function slice(string $taxonomy, string $from, string $lang, int $offset) {
		$terms = AIPT_Select::terms($taxonomy, $from, '');
		if (is_wp_error($terms)) {
			return $terms;
		}
		// At most MAX_BATCHES gateway batches, each within the batch-lock budget.
		$ttl = self::MAX_BATCHES * AIPT_Pipeline::batch_lock_ttl();
		if (function_exists('set_time_limit')) {
			set_time_limit($ttl);
		}
		// A retry sent while an earlier request still runs (its client timed out) waits
		// instead of creating the same terms twice.
		return AIPT_Job::with_named_lock(
			self::LOCK_PREFIX . $taxonomy . '_' . $lang,
			$ttl,
			static fn(): array => self::run(array_values($terms), $taxonomy, $from, $lang, $offset),
			new WP_Error('aipt_terms_busy', __('These terms are being translated by another request. Try again in a minute.', 'ai-polylang-translator')),
			1
		);
	}

	private static function run(array $terms, string $taxonomy, string $from, string $lang, int $offset): array {
		$missing = aipt_lang()->in_language($from, static function () use ($terms, $lang, $offset): array {
			$missing = array();
			for ($index = $offset; $index < count($terms); $index++) {
				$missing[$index] = !aipt_lang()->term_translation((int) $terms[$index]->term_id, $lang);
			}
			return $missing;
		});

		$slice    = array();
		$existing = 0;
		$next     = null;
		foreach ($missing as $index => $is_missing) {
			if (!$is_missing) {
				$existing++;
				continue;
			}
			if ($slice && (count($slice) === self::MAX_TERMS || self::batch_count(array_merge($slice, array($terms[$index]))) > self::MAX_BATCHES)) {
				$next = $index;
				break;
			}
			$slice[] = $terms[$index];
		}
		$remaining = $next === null ? 0 : count(array_filter(array_slice($missing, $next - $offset)));

		$result = array('rows' => array(), 'existing' => $existing, 'next' => $next, 'remaining' => $remaining, 'cost' => 0.0, 'error_code' => '');
		if (!$slice) {
			return $result;
		}
		AIPT_Terms::translate($taxonomy, $slice, $from, $lang, null, static function (array $row) use (&$result): void {
			$result['cost']  += (float) $row['cost'];
			$result['rows'][] = array(
				'term_id'   => (int) $row['term']->term_id,
				'name'      => AIPT_Slug::decode($row['term']->name),
				'status'    => $row['status'],
				'target_id' => (int) $row['target_id'],
				'cost'      => (float) $row['cost'],
				'message'   => (string) $row['message'],
			);
			if (!empty($row['code']) && $result['error_code'] === '') {
				$result['error_code'] = (string) $row['code'];
			}
		});
		return $result;
	}

	// Gateway batches AIPT_Terms::translate() would send for $terms.
	private static function batch_count(array $terms): int {
		return count(AIPT_Extractor::build_batches(array_values(AIPT_Terms::items($terms))));
	}

	/**
	 * Translated taxonomies of the enabled post types in which the current user may create
	 * terms, for the form (term counts per source language: count()).
	 *
	 * @return array<string, string> name => label
	 */
	public static function choices(): array {
		$list = array();
		foreach (AIPT_Settings::get()['post_types'] as $type) {
			foreach (get_object_taxonomies($type, 'objects') as $taxonomy) {
				if (!isset($list[$taxonomy->name]) && aipt_lang()->is_translated_taxonomy($taxonomy->name)
					&& current_user_can($taxonomy->cap->edit_terms)) {
					$list[$taxonomy->name] = $taxonomy->labels->name;
				}
			}
		}
		return $list;
	}

	// Terms of $taxonomy in language $from.
	public static function count(string $taxonomy, string $from): int {
		$ids = aipt_lang()->terms_in_language($taxonomy, $from, array('hide_empty' => false, 'fields' => 'ids'));
		return is_wp_error($ids) ? 0 : count($ids);
	}

	/**
	 * Dry run of one [taxonomy, lang] pair; terms it would create are planned in $context.
	 *
	 * @return array {taxonomy, target, counts, chars, cost, message}
	 */
	public static function estimate(array $pair, string $from, float $price, AIPT_Runner_Context $context): array {
		$taxonomy = sanitize_key((string) ($pair[0] ?? ''));
		$lang     = sanitize_key((string) ($pair[1] ?? ''));
		$summary  = array('taxonomy' => $taxonomy, 'target' => $lang, 'counts' => array(), 'chars' => 0, 'cost' => 0.0, 'message' => '');
		if (!AIPT_Bulk_Request::valid_taxonomy($taxonomy) || !AIPT_Bulk_Request::valid_target($lang, $from)) {
			$summary['message'] = 'invalid taxonomy or language';
			return $summary;
		}
		$terms = AIPT_Select::terms($taxonomy, $from, '');
		if (is_wp_error($terms)) {
			$summary['message'] = $terms->get_error_message();
			return $summary;
		}
		AIPT_Terms::translate($taxonomy, $terms, $from, $lang, $price, static function (array $row) use (&$summary, $context, $lang): void {
			$summary['counts'][$row['status']] = ($summary['counts'][$row['status']] ?? 0) + 1;
			$summary['chars']                 += (int) ($row['chars'] ?? 0);
			$summary['cost']                  += (float) $row['cost'];
			if ($row['status'] === 'would_create') {
				$context->plan_term((int) $row['term']->term_id, $lang);
			} elseif ($row['status'] !== 'skipped_existing' && $summary['message'] === '') {
				$summary['message'] = $row['message'];
			}
		});
		return $summary;
	}
}
