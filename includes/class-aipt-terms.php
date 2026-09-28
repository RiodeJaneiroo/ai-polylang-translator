<?php
// Taxonomy term translation, shared by `wp aipt translate-terms` and auto-translation:
// names and descriptions go to AIPT_Gateway in batches, new terms are created parents
// first with AIPT_Slug slugs and linked to their source terms in Polylang. New terms
// need the taxonomy's edit_terms capability (wp_insert_term does not check it).

if (!defined('ABSPATH')) {
	exit;
}

class AIPT_Terms {

	/**
	 * Rows are streamed to $on_row as they are produced: existing translations first
	 * (before any API call), then the other terms in input order, each right after it
	 * is created. Each row is an AIPT_Record::outcome() plus 'term' (WP_Term) and
	 * 'chars' (dry run only). $on_row returning false stops further rows.
	 *
	 * @param WP_Term[]  $terms         Source-language terms, parents first.
	 * @param float|null $dry_run_price USD per character: no API calls and no writes,
	 *                                  rows estimate the cost instead.
	 */
	public static function translate(string $taxonomy, array $terms, string $from, string $target, ?float $dry_run_price = null, ?callable $on_row = null): void {
		$pending = array();
		foreach ($terms as $term) {
			$existing = (int) (pll_get_term($term->term_id, $target) ?: 0);
			if ($existing) {
				if (!self::emit($on_row, $term, AIPT_Record::outcome('skipped_existing', $existing))) {
					return;
				}
				continue;
			}
			$pending[(int) $term->term_id] = $term;
		}
		if (!$pending) {
			return;
		}

		if (!current_user_can(get_taxonomy($taxonomy)->cap->edit_terms)) {
			$denied = AIPT_Record::outcome('skipped_no_permission', 0, 0.0, sprintf('current user cannot edit %s terms', $taxonomy));
			foreach ($pending as $term) {
				if (!self::emit($on_row, $term, $denied)) {
					return;
				}
			}
			return;
		}

		$items = array();
		foreach ($pending as $term_id => $term) {
			if (AIPT_Extractor::is_translatable($term->name)) {
				$items['t' . $term_id . 'n'] = array('id' => 't' . $term_id . 'n', 'text' => $term->name, 'term_id' => $term_id);
			}
			if (AIPT_Extractor::is_translatable($term->description)) {
				$items['t' . $term_id . 'd'] = array('id' => 't' . $term_id . 'd', 'text' => $term->description, 'term_id' => $term_id);
			}
		}

		if ($dry_run_price !== null) {
			self::dry_run($pending, $items, $target, $dry_run_price, $on_row);
			return;
		}

		$translated = array();
		$failed     = array();
		$term_cost  = array();
		$model      = AIPT_Settings::get()['model'];
		$job_id     = wp_generate_uuid4();
		$job_meta   = array(
			'post_id' => 0,
			'target'  => $target,
			'model'   => $model,
			/* translators: %s: taxonomy name, e.g. "Categories" */
			'title'   => sprintf(__('Terms: %s', 'ai-polylang-translator'), get_taxonomy($taxonomy)->labels->name),
		);
		$source_name = AIPT_Pipeline::language_name($from);
		$target_name = AIPT_Pipeline::language_name($target);

		foreach (AIPT_Extractor::build_batches(array_values($items)) as $keys) {
			$map = array();
			$ids = array();
			foreach ($keys as $key) {
				$map[$key] = $items[$key]['text'];
				$ids[$items[$key]['term_id']] = true;
			}

			$result = AIPT_Gateway::translate_map($map, $source_name, $target_name, $model);
			// Billed even when the reply is rejected: record before looking at the result.
			$usage = AIPT_Gateway::get_usage();
			AIPT_Usage::record_job_usage($job_meta, $job_id, $usage);
			foreach (array_keys($ids) as $term_id) {
				$term_cost[$term_id] = ($term_cost[$term_id] ?? 0.0) + (float) $usage['cost'] / count($ids);
			}

			if (is_wp_error($result)) {
				foreach (array_keys($ids) as $term_id) {
					$failed[$term_id] = $result->get_error_message();
				}
				continue;
			}
			$translated += $result;
		}

		foreach ($pending as $term_id => $term) {
			if (isset($failed[$term_id])) {
				$outcome = AIPT_Record::outcome('error', 0, 0.0, 'translation failed: ' . $failed[$term_id]);
			} else {
				$name        = (string) ($translated['t' . $term_id . 'n'] ?? $term->name);
				$description = (string) ($translated['t' . $term_id . 'd'] ?? $term->description);
				$outcome     = self::create_term($term, $taxonomy, $from, $target, $name, $description);
			}
			$outcome['cost'] = $term_cost[$term_id] ?? 0.0;
			if (!self::emit($on_row, $term, $outcome)) {
				return;
			}
		}
	}

	/**
	 * Source-language terms among $term_ids plus their source-language ancestors,
	 * parents first.
	 *
	 * @return WP_Term[]
	 */
	public static function with_ancestors(string $taxonomy, array $term_ids, string $from): array {
		$by_id = array();
		foreach ($term_ids as $term_id) {
			foreach (array_merge(array((int) $term_id), get_ancestors((int) $term_id, $taxonomy, 'taxonomy')) as $id) {
				$id = (int) $id;
				if (isset($by_id[$id]) || pll_get_term_language($id) !== $from) {
					continue;
				}
				$term = get_term($id, $taxonomy);
				if ($term instanceof WP_Term) {
					$by_id[$id] = $term;
				}
			}
		}
		return self::parents_first($taxonomy, $by_id);
	}

	/**
	 * @param WP_Term[] $by_id term_id => term.
	 * @return WP_Term[] Stable order: depth, then ID.
	 */
	public static function parents_first(string $taxonomy, array $by_id): array {
		$depth = array();
		foreach ($by_id as $term_id => $term) {
			$depth[$term_id] = $term->parent ? count(get_ancestors($term_id, $taxonomy, 'taxonomy')) : 0;
		}
		uksort($by_id, static fn(int $a, int $b): int => array($depth[$a], $a) <=> array($depth[$b], $b));
		return array_values($by_id);
	}

	private static function dry_run(array $pending, array $items, string $target, float $price, ?callable $on_row): void {
		foreach ($pending as $term_id => $term) {
			if ($term->parent && !isset($pending[(int) $term->parent]) && !pll_get_term($term->parent, $target)) {
				$outcome = AIPT_Record::outcome('skipped_parent_missing', 0, 0.0, sprintf('parent term #%d has no %s translation', $term->parent, $target));
				if (!self::emit($on_row, $term, $outcome)) {
					return;
				}
				continue;
			}
			$chars = 0;
			foreach (array('n', 'd') as $part) {
				if (isset($items['t' . $term_id . $part])) {
					$chars += mb_strlen($items['t' . $term_id . $part]['text'], 'UTF-8');
				}
			}
			$outcome          = AIPT_Record::outcome('would_create', 0, $price * $chars, sprintf('%d chars', $chars));
			$outcome['chars'] = $chars;
			if (!self::emit($on_row, $term, $outcome)) {
				return;
			}
		}
	}

	private static function create_term(WP_Term $term, string $taxonomy, string $from, string $target, string $name, string $description): array {
		try {
			$parent = 0;
			if ($term->parent) {
				$parent = (int) (pll_get_term($term->parent, $target) ?: 0);
				if (!$parent) {
					return AIPT_Record::outcome('skipped_parent_missing', 0, 0.0, sprintf('parent term #%d has no %s translation', $term->parent, $target));
				}
			}
			// Another run may have created it since the list was built.
			$existing = (int) (pll_get_term($term->term_id, $target) ?: 0);
			if ($existing) {
				return AIPT_Record::outcome('skipped_existing', $existing);
			}

			$desired = AIPT_Slug::from_title($name);
			if ($desired === '') {
				$desired = $term->slug;
			}
			$slug   = AIPT_Slug::for_term($desired, $target, $taxonomy, $parent);
			$result = self::insert_term($name, $taxonomy, $slug, $parent, $description);
			$status = 'created';
			$new_id = 0;

			if (is_wp_error($result) && $result->get_error_code() === 'term_exists') {
				$found = (int) $result->get_error_data();
				// Link a same-named term only if it is an unlinked term of the target
				// language — never the source term or a term of another language.
				if ($found
					&& $found !== (int) $term->term_id
					&& pll_get_term_language($found) === $target
					&& !pll_get_term($found, $from)) {
					$new_id = $found;
					$status = 'linked';
				} else {
					$suffixed = AIPT_Slug::with_lang($desired, $target);
					if ($suffixed !== $slug) {
						$result = self::insert_term($name, $taxonomy, $suffixed, $parent, $description);
					}
					if (is_wp_error($result)) {
						return AIPT_Record::outcome('error', 0, 0.0, sprintf('%s (existing term #%d)', $result->get_error_message(), $found));
					}
				}
			}
			if (!$new_id) {
				if (is_wp_error($result)) {
					return AIPT_Record::outcome('error', 0, 0.0, $result->get_error_message());
				}
				$new_id = (int) $result['term_id'];
			}

			pll_set_term_language($new_id, $target);
			$translations          = pll_get_term_translations($term->term_id);
			$translations[$from]   = (int) $term->term_id;
			$translations[$target] = $new_id;
			pll_save_term_translations($translations);

			return AIPT_Record::outcome($status, $new_id, 0.0, $name);
		} catch (Throwable $e) {
			return AIPT_Record::outcome('error', 0, 0.0, get_class($e) . ': ' . $e->getMessage());
		}
	}

	/**
	 * @return array|WP_Error
	 */
	private static function insert_term(string $name, string $taxonomy, string $slug, int $parent, string $description) {
		$term_args = array(
			'parent'      => $parent,
			'description' => wp_slash($description),
		);
		if ($slug !== '') {
			$term_args['slug'] = $slug;
		}
		return wp_insert_term(wp_slash($name), $taxonomy, $term_args);
	}

	// False when $on_row asked to stop.
	private static function emit(?callable $on_row, WP_Term $term, array $outcome): bool {
		$outcome['term'] = $term;
		return !$on_row || $on_row($outcome) !== false;
	}
}
