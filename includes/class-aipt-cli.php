<?php
// WP-CLI commands: `wp aipt translate` (bulk post translation through AIPT_Pipeline)
// and `wp aipt translate-terms` (taxonomy names/descriptions through AIPT_Terms).
// Output is English only; the TSV log gets one line per record plus a summary.

if (!defined('ABSPATH')) {
	exit;
}

/**
 * AI translation of posts and taxonomy terms into other Polylang languages.
 */
class AIPT_CLI {

	private $log     = null;
	// Dry run: target language => source IDs a real run would create, so their
	// children are not reported as skipped_parent_missing.
	private $planned = array();

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
		$dry_run = (bool) \WP_CLI\Utils\get_flag_value($assoc_args, 'dry-run', false);
		list($from, $targets) = AIPT_CLI_Args::languages($assoc_args);
		AIPT_CLI_Args::require_user();
		list($types, $ids) = AIPT_CLI_Args::post_selection($assoc_args);
		$mode = AIPT_CLI_Args::mode($assoc_args);

		$publish = (bool) \WP_CLI\Utils\get_flag_value($assoc_args, 'publish', false);
		if ($publish) {
			AIPT_CLI_Args::require_publish_caps($types);
		}

		$opts = array(
			'mode'          => $mode,
			'skip_existing' => $mode === '',
			'post_status'   => $publish ? 'publish' : 'draft',
			'keep_date'     => (bool) \WP_CLI\Utils\get_flag_value($assoc_args, 'keep-date', false),
		);
		$statuses = array_map('sanitize_key', AIPT_CLI_Args::csv($assoc_args['post_status'] ?? 'publish'));
		$after    = AIPT_CLI_Args::date($assoc_args, 'after');
		$shard    = AIPT_CLI_Args::shard($assoc_args);
		$limit    = isset($assoc_args['limit']) ? absint($assoc_args['limit']) : 0;
		$price    = AIPT_Settings::price_per_char();

		AIPT_CLI_Args::require_api_key($dry_run);
		$this->log = new AIPT_CLI_Log($assoc_args, 'translate', $dry_run);

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
				$outcome = $dry_run
					? $this->dry_run_post($post_id, $target, $from, $opts, $price)
					: $this->process_post($post_id, $target, $from, $opts);
				if ($outcome['api']) {
					$processed++;
				}
				$this->log->record(sprintf('[%d/%d] post', $position, $total), $post_id, $target, $outcome);
			}
			$this->free_memory();
		}

		$this->log->finish();
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
		$dry_run = (bool) \WP_CLI\Utils\get_flag_value($assoc_args, 'dry-run', false);
		list($from, $targets) = AIPT_CLI_Args::languages($assoc_args);
		AIPT_CLI_Args::require_user();
		$taxonomies = AIPT_CLI_Args::taxonomies($assoc_args);

		$since = AIPT_CLI_Args::date($assoc_args, 'only-used-since');
		$price = AIPT_Settings::price_per_char();
		AIPT_CLI_Args::require_api_key($dry_run);
		$this->log = new AIPT_CLI_Log($assoc_args, 'translate-terms', $dry_run);
		if (function_exists('set_time_limit')) {
			set_time_limit(0);
		}

		foreach ($taxonomies as $taxonomy) {
			$terms = $this->source_terms($taxonomy, $from, $since);
			WP_CLI::log(sprintf('%s: %d source terms in %s.', $taxonomy, count($terms), $from));
			foreach ($targets as $target) {
				AIPT_Terms::translate($taxonomy, $terms, $from, $target, $dry_run ? $price : null, function (array $row) use ($taxonomy, $target): void {
					$this->record_term($taxonomy, $row['term'], $target, $row);
				});
			}
			$this->free_memory();
		}

		$this->log->finish();
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
			$check = $this->precheck($post_id, $target, $from, $opts);
			if ($check['status'] !== '') {
				return $check;
			}

			$this->free_memory();
			$status = $this->new_post_status($post_id, $opts);
			// Takes the pair lock and re-checks the existing translation under it.
			$result = AIPT_Pipeline::translate_post($post_id, $target, array(
				'mode'          => $opts['mode'] !== '' ? $opts['mode'] : 'overwrite',
				'skip_existing' => $opts['skip_existing'],
				'holder'        => 'cli',
				'post_status'   => $status,
				'keep_date'     => $opts['keep_date'],
			));
			if (is_wp_error($result)) {
				if ($result->get_error_code() === 'aipt_pair_locked') {
					$holder = (string) ($result->get_error_data()['holder'] ?? '');
					return AIPT_Record::outcome('skipped_locked', 0, 0.0, 'pair is locked' . ($holder !== '' ? ' by ' . $holder : ''));
				}
				$usage = (array) $result->get_error_data('aipt_usage');
				return AIPT_Record::outcome(
					'error',
					0,
					(float) ($usage['cost'] ?? 0),
					$result->get_error_message(),
					!empty($usage['api_calls'])
				);
			}
			$note    = str_starts_with($result['status'], 'created') && $status !== $opts['post_status']
				? sprintf('status %s instead of %s (source is %s)', $status, $opts['post_status'], get_post_status($post_id))
				: '';
			$message = trim($note . ($result['warning'] !== '' ? ' ' . $result['warning'] : ''));
			return AIPT_Record::outcome($result['status'], (int) $result['target_id'], (float) $result['cost'], $message, $result['api_calls'] > 0);
		} catch (Throwable $e) {
			return AIPT_Record::outcome('error', 0, 0.0, get_class($e) . ': ' . $e->getMessage(), true);
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
	private function precheck(int $post_id, string $target, string $from, array $opts): array {
		$check = AIPT_Record::check_source($post_id, $target, $from, $opts['skip_existing']);
		if ($check['status'] !== '') {
			return $check;
		}
		$existing = (int) $check['target_id'];
		$post     = get_post($post_id);

		// A dry run counts the parents it would create as translated.
		$parent = $existing ? 0 : AIPT_Record::missing_parent($post, $target);
		if ($parent && !isset($this->planned[$target][$parent])) {
			return AIPT_Record::outcome('skipped_parent_missing', 0, 0.0, sprintf('parent #%d has no %s translation', $parent, $target));
		}

		$terms = AIPT_Record::translated_terms($post_id, $post->post_type);
		// Safe mode keeps the terms of a translation that already has some.
		if ($existing && $opts['mode'] === 'safe') {
			$terms = array_diff_key($terms, AIPT_Record::translated_terms($existing, $post->post_type));
		}
		$missing = AIPT_Record::missing_terms($terms, $target);
		if ($missing) {
			return AIPT_Record::outcome('skipped_missing_terms', $existing, 0.0, 'untranslated terms: ' . AIPT_Record::describe_terms($missing));
		}
		return AIPT_Record::outcome('', $existing);
	}

	private function dry_run_post(int $post_id, string $target, string $from, array $opts, float $price): array {
		$mode = $opts['mode'];
		try {
			$check = $this->precheck($post_id, $target, $from, $opts);
			if ($check['status'] !== '') {
				return $check;
			}
			$existing = (int) $check['target_id'];
			$safe     = $existing && $mode === 'safe';
			$extract  = AIPT_Extractor::extract($post_id, $existing, $safe);
			if (!$extract['items'] && !$safe) {
				return AIPT_Record::outcome('error', 0, 0.0, 'no text to translate');
			}

			$chars = 0;
			foreach ($extract['items'] as $item) {
				$chars += mb_strlen($item['text'], 'UTF-8');
			}
			if (!$existing) {
				$this->planned[$target][$post_id] = true;
			}
			$outcome = AIPT_Record::outcome(
				$existing ? 'would_update' : 'would_create',
				$existing,
				$price * $chars,
				sprintf('%d chars (%s)', $chars, $existing ? $mode . ' mode' : 'new, status ' . $this->new_post_status($post_id, $opts)),
				$chars > 0
			);
			$outcome['chars'] = $chars;
			$holder           = AIPT_Job::pair_lock_holder($post_id, $target);
			if ($holder !== '') {
				$outcome['message'] .= ', pair currently locked by ' . $holder;
			}
			return $outcome;
		} catch (Throwable $e) {
			return AIPT_Record::outcome('error', 0, 0.0, get_class($e) . ': ' . $e->getMessage());
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
			// Ancestors keep the hierarchy intact even when only a child is used.
			$used = array_intersect(array_keys($by_id), $this->terms_used_since($taxonomy, $since));
			return AIPT_Terms::with_ancestors($taxonomy, $used, $from);
		}
		return AIPT_Terms::parents_first($taxonomy, $by_id);
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

	private function record_term(string $taxonomy, WP_Term $term, string $target, array $outcome): void {
		$label = $taxonomy . ' "' . html_entity_decode($term->name, ENT_QUOTES | ENT_HTML5, 'UTF-8') . '"';
		$outcome['message'] = trim($label . ' ' . $outcome['message']);
		$this->log->record('term', (int) $term->term_id, $target, $outcome);
	}

	// ---- Shared ---------------------------------------------------------------

	private function free_memory(): void {
		global $wpdb;
		AIPT_Pipeline::flush_runtime_cache();
		$wpdb->queries = array();
		gc_collect_cycles();
	}
}
