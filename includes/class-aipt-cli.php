<?php
// WP-CLI commands: `wp aipt translate` (bulk post translation through AIPT_Pipeline)
// and `wp aipt translate-terms` (taxonomy names/descriptions through AIPT_Gateway).
// Output is English only; the TSV log gets one line per record plus a summary.

if (!defined('ABSPATH')) {
	exit;
}

/**
 * AI translation of posts and taxonomy terms into other Polylang languages.
 */
class AIPT_CLI {

	private $log      = null;
	private $counts   = array();
	private $cost     = 0.0;
	private $chars    = 0;
	private $started  = 0;
	private $dry_run  = false;
	// Dry run: target language => source IDs a real run would create, so their
	// children are not reported as skipped_parent_missing.
	private $planned  = array();

	/**
	 * Translate posts into other Polylang languages.
	 *
	 * Works on one record (source post × target language) at a time: prepare, every
	 * batch and the write run in-process, and costs go to the plugin's cost log.
	 * Existing translations are skipped unless --mode is given, so re-running the
	 * same command resumes an interrupted run. Hierarchical post types are processed
	 * parents first; a child whose parent has no translation yet is skipped.
	 *
	 * Requires --user=<login> (WP-CLI global flag): the writer checks capabilities.
	 * Exits with status 1 when any record ended in error.
	 *
	 * ## OPTIONS
	 *
	 * [--post_type=<types>]
	 * : Comma-separated post types. Each must be enabled in Settings > AI Translator. Required unless --ids is given.
	 *
	 * [--ids=<ids>]
	 * : Comma-separated source post IDs (filtered by the other options just like --post_type). Required unless --post_type is given.
	 *
	 * [--from=<lang>]
	 * : Source language slug. Defaults to the Polylang default language.
	 *
	 * --to=<langs>
	 * : Comma-separated target language slugs.
	 *
	 * [--post_status=<statuses>]
	 * : Comma-separated statuses of the source posts to select.
	 * ---
	 * default: publish
	 * ---
	 *
	 * [--after=<date>]
	 * : Only source posts with post_date on or after this date (Y-m-d).
	 *
	 * [--limit=<n>]
	 * : Stop after this many translations that actually called the API. Skipped records do not count.
	 *
	 * [--skip-existing]
	 * : Skip records that already have a translation in the target language. Default unless --mode is given.
	 *
	 * [--mode=<mode>]
	 * : Update existing translations in this mode instead of skipping them. The flag is the overwrite confirmation.
	 * ---
	 * options:
	 *   - safe
	 *   - overwrite
	 * ---
	 *
	 * [--publish]
	 * : Publish new translations of published sources instead of saving them as drafts. Never exposes a non-public source: a private source gives a private translation, a scheduled one a scheduled translation with --keep-date (else a draft), any other status a draft; the log notes the status used. Existing translations keep their status and slug.
	 *
	 * [--keep-date]
	 * : Copy post_date and post_date_gmt from the source post (new and updated translations).
	 *
	 * [--shard=<shard>]
	 * : Shard as i/n (e.g. 0/4): process only source posts with ID % n == i, so n processes can run in parallel on disjoint sets. A child whose parent is in another shard is skipped until the parent is translated; re-run to pick it up.
	 *
	 * [--log=<path>]
	 * : Append one TSV line per record (time, source_id, target_lang, target_id, status, cost, message) and a summary. Required unless --dry-run. Must be outside ABSPATH and WP_CONTENT_DIR (and the web root when WordPress lives in a subdirectory).
	 *
	 * [--dry-run]
	 * : No API calls and no writes. Lists each record with its text size, estimated cost and what would be skipped.
	 *
	 * ## EXAMPLES
	 *
	 *     # Estimate the cost of translating published posts into Ukrainian and English.
	 *     $ wp aipt translate --post_type=post --to=uk,en --dry-run --user=admin
	 *
	 *     # Translate pages (parents first) and publish them with the source dates.
	 *     $ wp aipt translate --post_type=page --to=uk,en --publish --keep-date --log=/var/log/aipt/pages.tsv --user=admin
	 *
	 *     # Four parallel workers on disjoint sets of posts.
	 *     $ for i in 0 1 2 3; do wp aipt translate --post_type=post --to=uk,en --shard=$i/4 --log=/var/log/aipt/posts-$i.tsv --user=admin & done; wait
	 *
	 *     # Refresh existing translations but keep fields editors already filled.
	 *     $ wp aipt translate --ids=12,34 --to=en --mode=safe --log=/var/log/aipt/refresh.tsv --user=admin
	 */
	public function translate($args, $assoc_args) {
		$this->dry_run = (bool) \WP_CLI\Utils\get_flag_value($assoc_args, 'dry-run', false);
		list($from, $targets) = $this->languages($assoc_args);
		$this->require_user();

		$enabled = array_values(array_filter(
			AIPT_Settings::get()['post_types'],
			static fn(string $type): bool => post_type_exists($type) && pll_is_translated_post_type($type)
		));
		$has_types = isset($assoc_args['post_type']) && $assoc_args['post_type'] !== '';
		$has_ids   = isset($assoc_args['ids']) && $assoc_args['ids'] !== '';
		if ($has_types === $has_ids) {
			WP_CLI::error('Pass either --post_type or --ids (exactly one of them).');
		}

		$ids   = array();
		$types = $enabled;
		if ($has_types) {
			$types = self::csv($assoc_args['post_type']);
			foreach ($types as $type) {
				if (!in_array($type, $enabled, true)) {
					WP_CLI::error(sprintf("Post type '%s' is not enabled in Settings > AI Translator (or not translated by Polylang).", $type));
				}
			}
		} else {
			$ids = array_values(array_filter(array_map('absint', self::csv($assoc_args['ids']))));
			if (!$ids) {
				WP_CLI::error('--ids contains no valid post IDs.');
			}
		}
		if (!$types) {
			WP_CLI::error('No post types are enabled in Settings > AI Translator.');
		}

		$mode = (string) ($assoc_args['mode'] ?? '');
		if ($mode !== '' && isset($assoc_args['skip-existing'])) {
			WP_CLI::error('--skip-existing and --mode are mutually exclusive.');
		}
		if ($mode !== '' && !in_array($mode, array('safe', 'overwrite'), true)) {
			WP_CLI::error('--mode must be safe or overwrite.');
		}

		$publish = (bool) \WP_CLI\Utils\get_flag_value($assoc_args, 'publish', false);
		if ($publish) {
			foreach ($types as $type) {
				$object = get_post_type_object($type);
				if (!$object || !current_user_can($object->cap->publish_posts)) {
					WP_CLI::error(sprintf("The current user cannot publish '%s' posts.", $type));
				}
			}
		}

		$opts = array(
			'mode'        => $mode,
			'post_status' => $publish ? 'publish' : 'draft',
			'keep_date'   => (bool) \WP_CLI\Utils\get_flag_value($assoc_args, 'keep-date', false),
		);
		$statuses = array_map('sanitize_key', self::csv($assoc_args['post_status'] ?? 'publish'));
		$after    = self::date_arg($assoc_args, 'after');
		$shard    = self::shard_arg($assoc_args);
		$limit    = isset($assoc_args['limit']) ? absint($assoc_args['limit']) : 0;
		$price    = $this->price_per_char();

		if (!$this->dry_run && AIPT_Settings::api_key() === '') {
			WP_CLI::error('API key is not set (Settings > AI Translator).');
		}
		$this->open_log($assoc_args, 'translate');

		$selected = $this->select_posts($types, $statuses, $from, $after, $ids, $shard);
		if ($ids && count($selected) < count($ids)) {
			WP_CLI::warning(sprintf(
				'%d of the given IDs are ignored: not found, not in %s, filtered by status/date/shard, or post type not enabled.',
				count($ids) - count($selected),
				$from
			));
		}

		$total     = count($selected) * count($targets);
		$position  = 0;
		$processed = 0;
		WP_CLI::log(sprintf('%d source posts × %d languages = %d records.', count($selected), count($targets), $total));

		foreach ($selected as $post_id) {
			foreach ($targets as $target) {
				if ($limit && $processed >= $limit) {
					WP_CLI::log(sprintf('Limit of %d reached.', $limit));
					break 2;
				}
				$position++;
				$outcome = $this->dry_run
					? $this->dry_run_post($post_id, $target, $from, $opts, $price)
					: $this->process_post($post_id, $target, $from, $opts);
				if ($outcome['api']) {
					$processed++;
				}
				$this->record(sprintf('[%d/%d] post', $position, $total), $post_id, $target, $outcome);
			}
			$this->free_memory();
		}

		$this->finish();
	}

	/**
	 * Translate taxonomy terms into other Polylang languages.
	 *
	 * Source terms are the terms in the --from language. Names and descriptions are
	 * translated in batches, parents are created first, and each new term is linked
	 * to its source term in Polylang. Terms that already have a translation in the
	 * target language are skipped, so the command can be re-run safely.
	 *
	 * Requires --user=<login> (WP-CLI global flag). Exits with status 1 when any
	 * term ended in error.
	 *
	 * ## OPTIONS
	 *
	 * --taxonomy=<taxonomies>
	 * : Comma-separated taxonomies. Each must be translated by Polylang.
	 *
	 * --to=<langs>
	 * : Comma-separated target language slugs.
	 *
	 * [--from=<lang>]
	 * : Source language slug. Defaults to the Polylang default language.
	 *
	 * [--only-used-since=<date>]
	 * : Only terms attached to posts with post_date on or after this date (Y-m-d), plus their ancestors.
	 *
	 * [--dry-run]
	 * : No API calls and no writes. Lists each term with its text size and estimated cost.
	 *
	 * [--log=<path>]
	 * : Append one TSV line per term (time, source_id, target_lang, target_id, status, cost, message) and a summary. Required unless --dry-run. Must be outside ABSPATH and WP_CONTENT_DIR (and the web root when WordPress lives in a subdirectory).
	 *
	 * ## EXAMPLES
	 *
	 *     # Estimate the cost of translating categories and tags used since 2024.
	 *     $ wp aipt translate-terms --taxonomy=category,post_tag --to=uk,en --only-used-since=2024-01-01 --dry-run --user=admin
	 *
	 *     # Translate all categories before running `wp aipt translate`.
	 *     $ wp aipt translate-terms --taxonomy=category --to=uk,en --log=/var/log/aipt/terms.tsv --user=admin
	 *
	 * @subcommand translate-terms
	 */
	public function translate_terms($args, $assoc_args) {
		$this->dry_run = (bool) \WP_CLI\Utils\get_flag_value($assoc_args, 'dry-run', false);
		list($from, $targets) = $this->languages($assoc_args);
		$this->require_user();

		$taxonomies = self::csv($assoc_args['taxonomy'] ?? '');
		if (!$taxonomies) {
			WP_CLI::error('--taxonomy is required.');
		}
		foreach ($taxonomies as $taxonomy) {
			if (!taxonomy_exists($taxonomy) || !pll_is_translated_taxonomy($taxonomy)) {
				WP_CLI::error(sprintf("Taxonomy '%s' does not exist or is not translated by Polylang.", $taxonomy));
			}
			if (!current_user_can(get_taxonomy($taxonomy)->cap->edit_terms)) {
				WP_CLI::error(sprintf("The current user cannot edit '%s' terms.", $taxonomy));
			}
		}

		$since = self::date_arg($assoc_args, 'only-used-since');
		$price = $this->price_per_char();
		if (!$this->dry_run && AIPT_Settings::api_key() === '') {
			WP_CLI::error('API key is not set (Settings > AI Translator).');
		}
		$this->open_log($assoc_args, 'translate-terms');
		if (function_exists('set_time_limit')) {
			set_time_limit(0);
		}

		foreach ($taxonomies as $taxonomy) {
			$terms = $this->source_terms($taxonomy, $from, $since);
			WP_CLI::log(sprintf('%s: %d source terms in %s.', $taxonomy, count($terms), $from));
			foreach ($targets as $target) {
				$this->translate_terms_into($taxonomy, $terms, $from, $target, $price);
			}
			$this->free_memory();
		}

		$this->finish();
	}

	// ---- Posts ----------------------------------------------------------------

	private function select_posts(array $types, array $statuses, string $from, string $after, array $ids, ?array $shard): array {
		$query_args = array(
			'post_type'              => $types,
			'post_status'            => $statuses,
			// Explicit language: never depend on the CLI user's admin language filter.
			'lang'                   => $from,
			'fields'                 => 'ids',
			'posts_per_page'         => -1,
			'orderby'                => 'ID',
			'order'                  => 'ASC',
			'no_found_rows'          => true,
			'ignore_sticky_posts'    => true,
			'update_post_meta_cache' => false,
			'update_post_term_cache' => false,
			'cache_results'          => false,
		);
		if ($ids) {
			$query_args['post__in'] = $ids;
		}
		if ($after !== '') {
			$query_args['date_query'] = array(array('column' => 'post_date', 'after' => $after, 'inclusive' => true));
		}

		$query = new WP_Query($query_args);
		$found = array_map('intval', $query->posts);
		if ($shard) {
			$found = array_values(array_filter($found, static fn(int $id): bool => $id % $shard[1] === $shard[0]));
		}
		return $this->parents_first($found, $types);
	}

	// Stable order: depth, then ID — parents are translated before their children.
	private function parents_first(array $ids, array $types): array {
		global $wpdb;
		$hierarchical = array_values(array_filter($types, 'is_post_type_hierarchical'));
		if (!$hierarchical || !$ids) {
			return $ids;
		}

		$placeholders = implode(',', array_fill(0, count($hierarchical), '%s'));
		$rows         = $wpdb->get_results($wpdb->prepare(
			"SELECT ID, post_parent FROM {$wpdb->posts} WHERE post_parent > 0 AND post_type IN ($placeholders)",
			$hierarchical
		));
		$parents = array();
		foreach ($rows as $row) {
			$parents[(int) $row->ID] = (int) $row->post_parent;
		}

		$depth = array();
		foreach ($ids as $id) {
			$level   = 0;
			$current = $id;
			$seen    = array();
			while (isset($parents[$current]) && !isset($seen[$current])) {
				$seen[$current] = true;
				$current        = $parents[$current];
				$level++;
			}
			$depth[$id] = $level;
		}

		usort($ids, static fn(int $a, int $b): int => array($depth[$a], $a) <=> array($depth[$b], $b));
		return $ids;
	}

	private function process_post(int $post_id, string $target, string $from, array $opts): array {
		if (function_exists('set_time_limit')) {
			set_time_limit(0);
		}
		try {
			$check = $this->precheck_post($post_id, $target, $from, $opts['mode']);
			if ($check['status'] !== '') {
				return $check;
			}

			if (!AIPT_Job::acquire_pair_lock($post_id, $target, AIPT_Pipeline::CLI_PAIR_LOCK_TTL, 'cli')) {
				$holder = AIPT_Job::pair_lock_holder($post_id, $target);
				return self::outcome('skipped_locked', 0, 0.0, 'pair is locked' . ($holder !== '' ? ' by ' . $holder : ''));
			}
			try {
				// Re-check under the lock: the editor or another run may have created it.
				$this->free_memory();
				$existing = (int) (pll_get_post($post_id, $target) ?: 0);
				if ($existing && $opts['mode'] === '') {
					return self::outcome('skipped_existing', $existing);
				}

				$status = $this->new_post_status($post_id, $opts);
				$note   = !$existing && $status !== $opts['post_status']
					? sprintf('status %s instead of %s (source is %s)', $status, $opts['post_status'], get_post_status($post_id))
					: '';

				$result = AIPT_Pipeline::translate_post($post_id, $target, array(
					'mode'        => $opts['mode'] !== '' ? $opts['mode'] : 'overwrite',
					'post_status' => $status,
					'keep_date'   => $opts['keep_date'],
					'pair_locked' => true,
				));
				if (is_wp_error($result)) {
					$usage = (array) $result->get_error_data('aipt_usage');
					return self::outcome(
						'error',
						0,
						(float) ($usage['cost'] ?? 0),
						$result->get_error_message(),
						!empty($usage['api_calls'])
					);
				}
				$message = trim($note . ($result['warning'] !== '' ? ' ' . $result['warning'] : ''));
				return self::outcome($result['status'], (int) $result['target_id'], (float) $result['cost'], $message, $result['api_calls'] > 0);
			} finally {
				AIPT_Job::release_pair_lock($post_id, $target);
			}
		} catch (Throwable $e) {
			return self::outcome('error', 0, 0.0, get_class($e) . ': ' . $e->getMessage(), true);
		}
	}

	// Status for a new translation. --publish never exposes a source that is not public:
	// private stays private, scheduled stays scheduled only with its copied date, and
	// anything else (draft, pending, …) becomes a draft.
	private function new_post_status(int $post_id, array $opts): string {
		if ($opts['post_status'] !== 'publish') {
			return 'draft';
		}
		switch (get_post_status($post_id)) {
			case 'publish':
				return 'publish';
			case 'private':
				return 'private';
			case 'future':
				return $opts['keep_date'] ? 'future' : 'draft';
			default:
				return 'draft';
		}
	}

	// Status '' means the record should be translated; target_id holds the existing translation.
	private function precheck_post(int $post_id, string $target, string $from, string $mode): array {
		$post = get_post($post_id);
		if (!$post) {
			return self::outcome('error', 0, 0.0, 'source post not found');
		}
		$language = (string) pll_get_post_language($post_id);
		if ($language !== $from) {
			return self::outcome('error', 0, 0.0, sprintf("source language is '%s', expected '%s'", $language, $from));
		}

		$existing = (int) (pll_get_post($post_id, $target) ?: 0);
		if ($existing && $mode === '') {
			return self::outcome('skipped_existing', $existing);
		}
		// The writer would otherwise create the child at the root.
		if (!$existing && $post->post_parent && !pll_get_post($post->post_parent, $target)
			&& !isset($this->planned[$target][(int) $post->post_parent])) {
			return self::outcome('skipped_parent_missing', 0, 0.0, sprintf('parent #%d has no %s translation', $post->post_parent, $target));
		}

		$missing = $this->missing_terms($post, $target, $existing, $mode);
		if ($missing) {
			return self::outcome('skipped_missing_terms', $existing, 0.0, 'untranslated terms: ' . implode(',', $missing));
		}
		return self::outcome('', $existing);
	}

	// Terms the writer would silently drop (copy_taxonomies maps them via pll_get_term).
	private function missing_terms(WP_Post $post, string $target, int $existing, string $mode): array {
		$missing = array();
		foreach (get_object_taxonomies($post->post_type) as $taxonomy) {
			if (!pll_is_translated_taxonomy($taxonomy)) {
				continue;
			}
			// Safe mode keeps the terms of a translation that already has some.
			if ($existing && $mode === 'safe') {
				$current = wp_get_object_terms($existing, $taxonomy, array('fields' => 'ids'));
				if (!is_wp_error($current) && $current) {
					continue;
				}
			}
			$terms = wp_get_object_terms($post->ID, $taxonomy, array('fields' => 'ids'));
			if (is_wp_error($terms)) {
				continue;
			}
			foreach ($terms as $term_id) {
				if (!pll_get_term((int) $term_id, $target)) {
					$missing[] = $taxonomy . ':' . (int) $term_id;
				}
			}
		}
		return $missing;
	}

	private function dry_run_post(int $post_id, string $target, string $from, array $opts, float $price): array {
		$mode = $opts['mode'];
		try {
			$check = $this->precheck_post($post_id, $target, $from, $mode);
			if ($check['status'] !== '') {
				return $check;
			}
			$existing = (int) $check['target_id'];
			$safe     = $existing && $mode === 'safe';
			$extract  = AIPT_Extractor::extract($post_id, $existing, $safe);
			if (!$extract['items'] && !$safe) {
				return self::outcome('error', 0, 0.0, 'no text to translate');
			}

			$chars = 0;
			foreach ($extract['items'] as $item) {
				$chars += mb_strlen($item['text'], 'UTF-8');
			}
			$this->chars += $chars;
			if (!$existing) {
				$this->planned[$target][$post_id] = true;
			}
			$outcome = self::outcome(
				$existing ? 'would_update' : 'would_create',
				$existing,
				$price * $chars,
				sprintf('%d chars (%s)', $chars, $existing ? $mode . ' mode' : 'new, status ' . $this->new_post_status($post_id, $opts)),
				$chars > 0
			);
			$holder = AIPT_Job::pair_lock_holder($post_id, $target);
			if ($holder !== '') {
				$outcome['message'] .= ', pair currently locked by ' . $holder;
			}
			return $outcome;
		} catch (Throwable $e) {
			return self::outcome('error', 0, 0.0, get_class($e) . ': ' . $e->getMessage());
		}
	}

	// ---- Terms ----------------------------------------------------------------

	/**
	 * @return WP_Term[] Source-language terms, parents first.
	 */
	private function source_terms(string $taxonomy, string $from, string $since): array {
		$terms = get_terms(array(
			'taxonomy'   => $taxonomy,
			'hide_empty' => false,
			'lang'       => $from,
			'orderby'    => 'term_id',
			'order'      => 'ASC',
		));
		if (is_wp_error($terms)) {
			WP_CLI::warning(sprintf('%s: %s', $taxonomy, $terms->get_error_message()));
			return array();
		}

		$by_id = array();
		foreach ($terms as $term) {
			if (pll_get_term_language($term->term_id) === $from) {
				$by_id[(int) $term->term_id] = $term;
			}
		}

		if ($since !== '') {
			$keep = array();
			foreach ($this->terms_used_since($taxonomy, $since) as $term_id) {
				if (!isset($by_id[$term_id])) {
					continue;
				}
				$keep[$term_id] = true;
				// Ancestors keep the hierarchy intact even when only a child is used.
				foreach (get_ancestors($term_id, $taxonomy, 'taxonomy') as $ancestor) {
					$keep[(int) $ancestor] = true;
				}
			}
			$by_id = array_intersect_key($by_id, $keep);
		}

		$depth = array();
		foreach ($by_id as $term_id => $term) {
			$depth[$term_id] = $term->parent ? count(get_ancestors($term_id, $taxonomy, 'taxonomy')) : 0;
		}
		uksort($by_id, static fn(int $a, int $b): int => array($depth[$a], $a) <=> array($depth[$b], $b));
		return array_values($by_id);
	}

	// Source-language terms are attached only to source-language posts, so the
	// caller's intersection with source terms keeps this to the source language.
	private function terms_used_since(string $taxonomy, string $since): array {
		global $wpdb;
		$ids = $wpdb->get_col($wpdb->prepare(
			"SELECT DISTINCT tt.term_id
			FROM {$wpdb->term_relationships} tr
			INNER JOIN {$wpdb->term_taxonomy} tt ON tt.term_taxonomy_id = tr.term_taxonomy_id
			INNER JOIN {$wpdb->posts} p ON p.ID = tr.object_id
			WHERE tt.taxonomy = %s
				AND p.post_date >= %s
				AND p.post_status NOT IN ('trash', 'auto-draft', 'inherit')",
			$taxonomy,
			$since . ' 00:00:00'
		));
		return array_map('intval', $ids);
	}

	private function translate_terms_into(string $taxonomy, array $terms, string $from, string $target, float $price): void {
		$pending = array();
		foreach ($terms as $term) {
			$existing = (int) (pll_get_term($term->term_id, $target) ?: 0);
			if ($existing) {
				$this->record_term($taxonomy, $term, $target, self::outcome('skipped_existing', $existing));
				continue;
			}
			$pending[(int) $term->term_id] = $term;
		}
		if (!$pending) {
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

		if ($this->dry_run) {
			$this->dry_run_terms($taxonomy, $pending, $items, $target, $price);
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
			if ($usage['tokens_in'] || $usage['tokens_out'] || $usage['cost']) {
				AIPT_Usage::record_job_usage($job_meta, $job_id, $usage);
			}
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
				$outcome = self::outcome('error', 0, 0.0, 'translation failed: ' . $failed[$term_id]);
			} else {
				$name        = (string) ($translated['t' . $term_id . 'n'] ?? $term->name);
				$description = (string) ($translated['t' . $term_id . 'd'] ?? $term->description);
				$outcome     = $this->create_term($term, $taxonomy, $from, $target, $name, $description);
			}
			$outcome['cost'] = $term_cost[$term_id] ?? 0.0;
			$this->record_term($taxonomy, $term, $target, $outcome);
		}
	}

	private function dry_run_terms(string $taxonomy, array $pending, array $items, string $target, float $price): void {
		foreach ($pending as $term_id => $term) {
			if ($term->parent && !isset($pending[(int) $term->parent]) && !pll_get_term($term->parent, $target)) {
				$this->record_term($taxonomy, $term, $target, self::outcome('skipped_parent_missing', 0, 0.0, sprintf('parent term #%d has no %s translation', $term->parent, $target)));
				continue;
			}
			$chars = 0;
			foreach (array('n', 'd') as $part) {
				if (isset($items['t' . $term_id . $part])) {
					$chars += mb_strlen($items['t' . $term_id . $part]['text'], 'UTF-8');
				}
			}
			$this->chars += $chars;
			$this->record_term($taxonomy, $term, $target, self::outcome('would_create', 0, $price * $chars, sprintf('%d chars', $chars)));
		}
	}

	private function create_term(WP_Term $term, string $taxonomy, string $from, string $target, string $name, string $description): array {
		try {
			$parent = 0;
			if ($term->parent) {
				$parent = (int) (pll_get_term($term->parent, $target) ?: 0);
				if (!$parent) {
					return self::outcome('skipped_parent_missing', 0, 0.0, sprintf('parent term #%d has no %s translation', $term->parent, $target));
				}
			}
			// Another run may have created it since the list was built.
			$existing = (int) (pll_get_term($term->term_id, $target) ?: 0);
			if ($existing) {
				return self::outcome('skipped_existing', $existing);
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
						return self::outcome('error', 0, 0.0, sprintf('%s (existing term #%d)', $result->get_error_message(), $found));
					}
				}
			}
			if (!$new_id) {
				if (is_wp_error($result)) {
					return self::outcome('error', 0, 0.0, $result->get_error_message());
				}
				$new_id = (int) $result['term_id'];
			}

			pll_set_term_language($new_id, $target);
			$translations          = pll_get_term_translations($term->term_id);
			$translations[$from]   = (int) $term->term_id;
			$translations[$target] = $new_id;
			pll_save_term_translations($translations);

			return self::outcome($status, $new_id, 0.0, $name);
		} catch (Throwable $e) {
			return self::outcome('error', 0, 0.0, get_class($e) . ': ' . $e->getMessage());
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

	private function record_term(string $taxonomy, WP_Term $term, string $target, array $outcome): void {
		$label = $taxonomy . ' "' . html_entity_decode($term->name, ENT_QUOTES | ENT_HTML5, 'UTF-8') . '"';
		$outcome['message'] = trim($label . ' ' . $outcome['message']);
		$this->record('term', (int) $term->term_id, $target, $outcome);
	}

	// ---- Shared ---------------------------------------------------------------

	private function languages(array $assoc_args): array {
		$languages = pll_languages_list();
		$from      = (string) ($assoc_args['from'] ?? pll_default_language());
		if (!in_array($from, $languages, true)) {
			WP_CLI::error(sprintf("Unknown source language '%s'. Available: %s.", $from, implode(', ', $languages)));
		}
		$targets = array_values(array_unique(self::csv($assoc_args['to'] ?? '')));
		if (!$targets) {
			WP_CLI::error('--to is required.');
		}
		foreach ($targets as $target) {
			if (!in_array($target, $languages, true)) {
				WP_CLI::error(sprintf("Unknown target language '%s'. Available: %s.", $target, implode(', ', $languages)));
			}
			if ($target === $from) {
				WP_CLI::error(sprintf("Target language '%s' is the source language.", $target));
			}
		}
		return array($from, $targets);
	}

	private function require_user(): void {
		if (!get_current_user_id()) {
			WP_CLI::error('No current user. Pass --user=<login>: capabilities are checked when writing translations.');
		}
	}

	// Catalog prices are USD per ~3,000-character article.
	private function price_per_char(): float {
		$settings = AIPT_Settings::get();
		$catalog  = AIPT_Settings::model_catalog();
		$price    = (string) ($catalog[$settings['model']]['price'] ?? '0');
		return (float) preg_replace('/[^0-9.]/', '', $price) / 3000;
	}

	private function open_log(array $assoc_args, string $command): void {
		$path = trim((string) ($assoc_args['log'] ?? ''));
		if ($path === '') {
			if (!$this->dry_run) {
				WP_CLI::error('--log=<path> is required (unless --dry-run).');
			}
			$this->begin($command, $assoc_args);
			return;
		}

		$dir = realpath(dirname($path));
		if ($dir === false || !is_dir($dir)) {
			WP_CLI::error(sprintf('Log directory does not exist: %s', dirname($path)));
		}
		$file = wp_normalize_path($dir) . '/' . basename($path);
		$real = file_exists($file) ? wp_normalize_path((string) realpath($file)) : $file;
		foreach (self::public_roots() as $root) {
			foreach (array($file, $real) as $candidate) {
				if ($candidate === $root || str_starts_with($candidate, $root . '/')) {
					WP_CLI::error(sprintf('--log must point outside %s (the log could be publicly readable there).', $root));
				}
			}
		}

		$handle = fopen($file, 'ab');
		if (!$handle) {
			WP_CLI::error(sprintf('Cannot open the log file for writing: %s', $file));
		}
		$this->log = $handle;
		$this->begin($command, $assoc_args);
	}

	// Directories that may be web-served: ABSPATH, WP_CONTENT_DIR and, when WordPress
	// lives in a subdirectory (Bedrock-style content dir outside ABSPATH, or a home URL
	// that differs from the site URL), the directory above ABSPATH too.
	private static function public_roots(): array {
		$abspath = self::real_dir(ABSPATH);
		$content = defined('WP_CONTENT_DIR') ? self::real_dir(WP_CONTENT_DIR) : '';
		$roots   = array($abspath, $content);

		$content_outside = $content !== '' && $abspath !== ''
			&& $content !== $abspath && !str_starts_with($content, $abspath . '/');
		$home_differs    = untrailingslashit((string) wp_parse_url(home_url(), PHP_URL_PATH))
			!== untrailingslashit((string) wp_parse_url(site_url(), PHP_URL_PATH));
		if ($abspath !== '' && ($content_outside || $home_differs)) {
			$roots[] = self::real_dir(dirname($abspath));
		}
		return array_values(array_unique(array_filter($roots, static fn(string $root): bool => $root !== '' && $root !== '/')));
	}

	private static function real_dir(string $path): string {
		$real = realpath($path);
		return $real === false ? '' : untrailingslashit(wp_normalize_path($real));
	}

	private function begin(string $command, array $assoc_args): void {
		$this->started = time();
		$flags         = array();
		foreach ($assoc_args as $key => $value) {
			$flags[] = $value === true ? '--' . $key : '--' . $key . '=' . $value;
		}
		$this->write_log_line(sprintf(
			'# %s wp aipt %s %s (user #%d, pid %d)',
			wp_date('c'),
			$command,
			implode(' ', $flags),
			get_current_user_id(),
			getmypid()
		));
	}

	private function record(string $prefix, int $source_id, string $target, array $outcome): void {
		$status = $outcome['status'];
		$this->counts[$status] = ($this->counts[$status] ?? 0) + 1;
		$this->cost           += $outcome['cost'];

		$line = sprintf('%s #%d -> %s: %s', $prefix, $source_id, $target, $status);
		if ($outcome['target_id']) {
			$line .= ' #' . $outcome['target_id'];
		}
		if ($outcome['cost'] > 0) {
			$line .= sprintf(' ($%.4f)', $outcome['cost']);
		}
		if ($outcome['message'] !== '') {
			$line .= ' — ' . $outcome['message'];
		}
		if (in_array($status, array('error', 'skipped_parent_missing', 'created_with_warning', 'updated_with_warning'), true)) {
			WP_CLI::warning($line);
		} else {
			WP_CLI::log($line);
		}

		$fields = array(
			wp_date('c'),
			$source_id,
			$target,
			$outcome['target_id'] ?: '',
			$status,
			sprintf('%.6f', $outcome['cost']),
			$outcome['message'],
		);
		$fields = array_map(static fn($field): string => str_replace(array("\t", "\r", "\n"), ' ', (string) $field), $fields);
		$this->write_log_line(implode("\t", $fields));
	}

	private function finish(): void {
		$elapsed = time() - $this->started;
		$counts  = array();
		foreach ($this->counts as $status => $count) {
			$counts[] = $status . '=' . $count;
		}
		$summary = sprintf(
			'Summary: %s; %s $%.4f; elapsed %d:%02d:%02d',
			$counts ? implode(', ', $counts) : 'nothing to do',
			$this->dry_run ? sprintf('%d chars, estimated cost', $this->chars) : 'cost',
			$this->cost,
			intdiv($elapsed, 3600),
			intdiv($elapsed % 3600, 60),
			$elapsed % 60
		);
		if ($this->dry_run) {
			$summary .= sprintf(' (model %s; catalog price per ~3,000 characters)', AIPT_Settings::get()['model']);
		}

		$this->write_log_line('# ' . wp_date('c') . ' ' . $summary);
		if ($this->log) {
			fclose($this->log);
			$this->log = null;
		}

		// Non-zero exit when any record failed, so scripts and schedulers notice.
		$errors = (int) ($this->counts['error'] ?? 0);
		if ($errors) {
			WP_CLI::warning($summary);
			WP_CLI::warning(sprintf('%d record(s) ended in error; see the log for details.', $errors));
			WP_CLI::halt(1);
		}
		WP_CLI::success($summary);
	}

	// Several shards may append to the same file.
	private function write_log_line(string $line): void {
		if (!$this->log) {
			return;
		}
		flock($this->log, LOCK_EX);
		fwrite($this->log, $line . "\n");
		fflush($this->log);
		flock($this->log, LOCK_UN);
	}

	private function free_memory(): void {
		global $wpdb;
		if (function_exists('wp_cache_supports') && wp_cache_supports('flush_runtime')) {
			wp_cache_flush_runtime();
		}
		$wpdb->queries = array();
		gc_collect_cycles();
	}

	private static function outcome(string $status, int $target_id = 0, float $cost = 0.0, string $message = '', bool $api = false): array {
		return array(
			'status'    => $status,
			'target_id' => $target_id,
			'cost'      => $cost,
			'message'   => $message,
			'api'       => $api,
		);
	}

	private static function csv($value): array {
		return array_values(array_filter(array_map('trim', explode(',', (string) $value)), 'strlen'));
	}

	private static function date_arg(array $assoc_args, string $key): string {
		if (!isset($assoc_args[$key]) || $assoc_args[$key] === '') {
			return '';
		}
		$value = (string) $assoc_args[$key];
		$date  = DateTime::createFromFormat('!Y-m-d', $value);
		if (!$date || $date->format('Y-m-d') !== $value) {
			WP_CLI::error(sprintf('--%s must be a date in Y-m-d format.', $key));
		}
		return $value;
	}

	private static function shard_arg(array $assoc_args): ?array {
		if (!isset($assoc_args['shard']) || $assoc_args['shard'] === '') {
			return null;
		}
		if (!preg_match('~^(\d+)/(\d+)$~', (string) $assoc_args['shard'], $matches)
			|| (int) $matches[2] < 1
			|| (int) $matches[1] >= (int) $matches[2]) {
			WP_CLI::error('--shard must be i/n with 0 <= i < n, e.g. --shard=0/4.');
		}
		return array((int) $matches[1], (int) $matches[2]);
	}
}
