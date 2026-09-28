<?php
// Translation pipeline shared by the editor metabox (AJAX), WP-CLI and auto-translation.
// The editor runs prepare → translate_batch (per batch) → finalize across requests;
// WP-CLI and AIPT_Auto run the same steps in-process through translate_post(). Every
// step returns data or a WP_Error; callers own request parsing, job-ownership checks
// and the response format.

if (!defined('ABSPATH')) {
	exit;
}

class AIPT_Pipeline {

	// Pair lock TTLs (see AIPT_Job::acquire_pair_lock). The editor holds it only around
	// the write; translate_post() holds it for the whole record, refreshed after every batch.
	const EDITOR_PAIR_LOCK_TTL = 300;
	const RECORD_PAIR_LOCK_TTL = 1800;

	const POST_STATUSES = array('draft', 'pending', 'private', 'future', 'publish');

	// Depth of AIPT_Writer calls in progress, so save hooks can tell the plugin's own
	// writes from other saves.
	private static $writing = 0;

	/**
	 * @param array $opts mode ('overwrite'|'safe'), confirm (bool), post_status (new
	 *                    translations only, default 'draft'), keep_date (bool).
	 * @return array|WP_Error {job_id, total}
	 */
	public static function prepare(int $post_id, string $target, array $opts = array()) {
		$job = self::build_job($post_id, $target, $opts);
		if (is_wp_error($job)) {
			return $job;
		}

		// Immutable payload: written once here and never rewritten. Per-batch results
		// live in their own transients so concurrent batch requests cannot clobber a
		// shared blob (last-write-wins data loss).
		$job_id = AIPT_Job::create($job);
		if ($job_id === '') {
			return new WP_Error('aipt_job_too_large', __('Could not save the translation job — the payload is too large. Contact an administrator.', 'ai-polylang-translator'));
		}

		return array('job_id' => $job_id, 'total' => count($job['batches']));
	}

	/**
	 * Validate the request and build the job payload (not stored).
	 *
	 * @return array|WP_Error
	 */
	private static function build_job(int $post_id, string $target, array $opts) {
		// Checked here, under translate_post()'s pair lock, so a translation that appeared
		// since the caller's pre-checks is never updated.
		if (!empty($opts['skip_existing'])) {
			$found = (int) (pll_get_post($post_id, $target) ?: 0);
			if ($found) {
				return new WP_Error('aipt_translation_exists', __('The translation already exists.', 'ai-polylang-translator'), array('target_id' => $found));
			}
		}

		$post     = get_post($post_id);
		$settings = AIPT_Settings::get();

		if (!$post || !in_array($post->post_type, $settings['post_types'], true)) {
			return new WP_Error('aipt_post_type_disabled', __('This post type is not enabled in the translation settings.', 'ai-polylang-translator'));
		}
		if (AIPT_Settings::api_key() === '') {
			return new WP_Error('aipt_no_key', __('API key is not set.', 'ai-polylang-translator'));
		}

		$mode        = ($opts['mode'] ?? 'overwrite') === 'safe' ? 'safe' : 'overwrite';
		$source_lang = pll_get_post_language($post_id);

		if (!$source_lang) {
			return new WP_Error('aipt_no_language', __('The post has no Polylang language set.', 'ai-polylang-translator'));
		}
		if (!in_array($target, pll_languages_list(), true) || $target === $source_lang) {
			return new WP_Error('aipt_invalid_target', __('Invalid target language.', 'ai-polylang-translator'));
		}

		$existing = (int) (pll_get_post($post_id, $target) ?: 0);
		if ($existing && !current_user_can('edit_post', $existing)) {
			return new WP_Error('aipt_cannot_edit_translation', __('Insufficient permissions to modify the existing translation.', 'ai-polylang-translator'));
		}
		if (!$existing && !self::can_create_translation($post)) {
			return new WP_Error('aipt_cannot_create_translation', __('Insufficient permissions to create a translation.', 'ai-polylang-translator'));
		}
		if (!$existing) {
			$mode = 'overwrite';
		}
		if ($existing && $mode === 'overwrite' && empty($opts['confirm'])) {
			return new WP_Error('needs_confirm', __('A translation already exists — overwrite confirmation is required.', 'ai-polylang-translator'));
		}

		$extract = AIPT_Extractor::extract($post_id, $existing, $mode === 'safe');
		if (!$extract['items'] && $mode !== 'safe') {
			return new WP_Error('aipt_no_text', __('The post has no text to translate.', 'ai-polylang-translator'));
		}

		$source_name = (string) pll_get_post_language($post_id, 'name');
		$target_name = self::language_name($target);

		$batches = array();
		foreach (AIPT_Extractor::build_batches($extract['items']) as $keys) {
			$batches[] = array('keys' => $keys);
		}

		$post_status = (string) ($opts['post_status'] ?? 'draft');
		if (!in_array($post_status, self::POST_STATUSES, true)) {
			$post_status = 'draft';
		}

		return array(
			'user_id'     => get_current_user_id(),
			'post_id'     => $post_id,
			'target'      => $target,
			// Snapshot the model at job-creation time: the cost log must reflect
			// the model actually used even if the setting changes before finalize.
			'model'       => $settings['model'],
			'existing'    => $existing,
			'mode'        => $mode,
			// Apply only when the writer creates the translation.
			'post_status' => $post_status,
			'keep_date'   => !empty($opts['keep_date']),
			'source_name' => $source_name ?: $source_lang,
			'target_name' => $target_name,
			'items'       => $extract['items'],
			'tree'        => $extract['tree'],
			'remap'       => $extract['remap'],
			'meta'        => $extract['meta'],
			'preserve'    => $extract['preserve'],
			'overwrite'   => $extract['overwrite'],
			'flex'        => $extract['flex'],
			'skip'        => $extract['skip'],
			// Jobs without this marker were created by 1.2 and still rely on
			// finalize-time aggregation of usage stored in batch transients.
			'usage_recorded_per_batch' => true,
			'batches'     => $batches,
		);
	}

	/**
	 * Translate one batch. Idempotent: a finished batch is never re-translated.
	 *
	 * @param array|null $usage Receives this call's token usage (zeros when no API call was made).
	 * @return array|WP_Error {done, total}
	 */
	public static function translate_batch(string $job_id, array $job, int $index, ?array &$usage = null) {
		$usage = array('tokens_in' => 0, 'tokens_out' => 0, 'cost' => 0.0);
		$total = count($job['batches']);
		if (!isset($job['batches'][$index])) {
			return new WP_Error('aipt_unknown_batch', __('Unknown translation block.', 'ai-polylang-translator'));
		}

		if (AIPT_Job::get_batch($job_id, $index) !== false) {
			return array('done' => $index + 1, 'total' => $total);
		}

		$result = self::request_batch($job_id, $job, $index, $usage);
		if (is_wp_error($result)) {
			return $result;
		}

		if (!AIPT_Job::save_batch($job_id, $index, $result, $usage)) {
			return new WP_Error('aipt_batch_too_large', __('Could not save the translation result — the payload is too large. Contact an administrator.', 'ai-polylang-translator'));
		}

		return array('done' => $index + 1, 'total' => $total);
	}

	/**
	 * Send one batch to the model and record its usage.
	 *
	 * @return array|WP_Error item_id => translated text
	 */
	private static function request_batch(string $job_id, array $job, int $index, ?array &$usage = null) {
		// WP-CLI runs have no execution time limit; do not impose one per batch.
		if (function_exists('set_time_limit') && !(defined('WP_CLI') && WP_CLI)) {
			set_time_limit(180);
		}

		$map = array();
		foreach ($job['batches'][$index]['keys'] as $item_id) {
			if (isset($job['items'][$item_id])) {
				$map[$item_id] = $job['items'][$item_id]['text'];
			}
		}

		$result = AIPT_Gateway::translate_map($map, $job['source_name'], $job['target_name'], (string) ($job['model'] ?? ''));

		// Record usage immediately after the API call — tokens are billed even when
		// the reply is rejected (truncated, empty, bad JSON), so we must not defer
		// this to finalize where the error path would exit first. Pre-upgrade jobs
		// keep their old finalize-time accounting to avoid double-counting.
		$usage = AIPT_Gateway::get_usage();
		if (!empty($job['usage_recorded_per_batch'])
			&& ($usage['tokens_in'] || $usage['tokens_out'] || $usage['cost'])) {
			AIPT_Usage::record_job_usage(
				$job,
				$job_id,
				$usage
			);
		}

		return $result;
	}

	/**
	 * Merge the batch results and write the translation (editor path). $job must already
	 * be verified to belong to the current user; it is re-read and re-checked under the
	 * finalize lock.
	 *
	 * @return array|WP_Error {post_id, edit_link}
	 */
	public static function finalize(string $job_id, array $job) {
		// A repeated finalize after a successful write returns the saved translation.
		if (($job['status'] ?? '') === 'complete') {
			return self::finalized_result($job);
		}

		$post_id = (int) ($job['post_id'] ?? 0);
		$total   = count($job['batches']);
		$results = AIPT_Job::get_batches($job_id, $total);
		if ($results === null) {
			return new WP_Error('aipt_incomplete_batches', __('Not all blocks are translated — finalization is not possible.', 'ai-polylang-translator'));
		}

		if (!AIPT_Job::acquire_finalize_lock($job_id)) {
			return new WP_Error('aipt_finalize_locked', __('The translation is already being saved. Try again in a few seconds.', 'ai-polylang-translator'));
		}

		$error = null;
		try {
			$job = AIPT_Job::get($job_id);
			if (!self::job_belongs($job, $post_id)) {
				$error = new WP_Error('aipt_job_expired', __('Translation job not found or expired. Start over.', 'ai-polylang-translator'));
			} elseif (($job['status'] ?? '') !== 'complete') {
				// A 'complete' status here means a concurrent finalize won while we waited.
				$written = self::write_job($job_id, $job, $results, $total);
				if (is_wp_error($written)) {
					// Batch transients stay in place so the user can retry finalize — unless
					// the translation was written with a warning: then the job is already
					// complete and the retry returns its link.
					$error = $written;
				} else {
					$job = $written;
				}
			}
		} finally {
			AIPT_Job::release_finalize_lock($job_id);
		}

		if ($error) {
			return $error;
		}

		return self::finalized_result($job);
	}

	/**
	 * One record in-process (WP-CLI and auto-translation): pair lock → build → all
	 * batches → write → unlock. The lock is held for the whole record and refreshed
	 * after every batch. Batch results stay in memory (no job or batch transients), so
	 * a long record never depends on their 1-hour TTL; usage is still recorded after
	 * every API call. Passing a mode is the overwrite confirmation.
	 *
	 * @param array $opts See prepare(), plus holder ('cli'|'auto': pair-lock role, default
	 *                    'cli') and skip_existing (bool: a translation found under the
	 *                    lock is left alone and reported as skipped_existing).
	 * @return array|WP_Error {target_id, status (created|updated, with a '_with_warning'
	 *                        suffix when the final status/slug update failed, or
	 *                        skipped_existing), cost, api_calls, warning}. A busy pair is
	 *                        'aipt_pair_locked' with {holder} error data; other errors
	 *                        carry {cost, api_calls} as 'aipt_usage' error data.
	 */
	public static function translate_post(int $post_id, string $target, array $opts = array()) {
		if (!current_user_can('edit_post', $post_id)) {
			return new WP_Error('aipt_forbidden', __('Insufficient permissions.', 'ai-polylang-translator'));
		}

		$holder = (string) ($opts['holder'] ?? 'cli');
		if (!AIPT_Job::acquire_pair_lock($post_id, $target, self::RECORD_PAIR_LOCK_TTL, $holder)) {
			return self::pair_busy_error($post_id, $target);
		}
		// The lock key read the translation group from cache: re-check against fresh data.
		self::flush_runtime_cache();

		$opts['confirm'] = true;
		try {
			$result = self::run_post($post_id, $target, $opts);
		} finally {
			AIPT_Job::release_pair_lock($post_id, $target);
		}

		if (is_wp_error($result) && $result->get_error_code() === 'aipt_translation_exists') {
			return array(
				'target_id' => (int) $result->get_error_data()['target_id'],
				'status'    => 'skipped_existing',
				'cost'      => 0.0,
				'api_calls' => 0,
				'warning'   => '',
			);
		}
		return $result;
	}

	// Long in-process runs (WP-CLI, cron) would keep stale Polylang translation groups.
	// A persistent cache without runtime flush is left alone (it is site-wide).
	public static function flush_runtime_cache(): void {
		if (function_exists('wp_cache_supports') && wp_cache_supports('flush_runtime')) {
			wp_cache_flush_runtime();
		} elseif (!wp_using_ext_object_cache()) {
			wp_cache_flush();
		}
	}

	public static function is_writing(): bool {
		return self::$writing > 0;
	}

	public static function job_belongs(?array $job, int $post_id): bool {
		return $job
			&& (int) ($job['user_id'] ?? 0) === get_current_user_id()
			&& (int) ($job['post_id'] ?? 0) === $post_id;
	}

	public static function language_name(string $slug): string {
		foreach (pll_languages_list(array('fields' => '')) as $language) {
			if ($language->slug === $slug) {
				return (string) $language->name;
			}
		}
		return $slug;
	}

	/**
	 * @return array|WP_Error
	 */
	private static function run_post(int $post_id, string $target, array $opts) {
		$job = self::build_job($post_id, $target, $opts);
		if (is_wp_error($job)) {
			return $job;
		}

		// Never stored; it only keys this record's row in the cost log.
		$job_id    = wp_generate_uuid4();
		$results   = array();
		$cost      = 0.0;
		$api_calls = 0;
		foreach (array_keys($job['batches']) as $index) {
			$result = self::request_batch($job_id, $job, $index, $usage);
			$api_calls++;
			$cost += (float) $usage['cost'];
			if (is_wp_error($result)) {
				return self::with_usage($result, $cost, $api_calls);
			}
			$results += $result;
			AIPT_Job::refresh_pair_lock($post_id, $target);
		}

		// A stale group after minutes of batches would unlink a translation saved meanwhile.
		self::flush_runtime_cache();
		$job['results'] = $results;
		$new_id         = self::write($job);
		$warning        = '';
		$written        = self::finish_failure($new_id);
		if ($written) {
			$warning = $new_id->get_error_message();
			$new_id  = $written;
		} elseif (is_wp_error($new_id)) {
			return self::with_usage($new_id, $cost, $api_calls);
		}

		$status = !empty($job['existing']) ? 'updated' : 'created';
		return array(
			'target_id' => (int) $new_id,
			'status'    => $warning !== '' ? $status . '_with_warning' : $status,
			'cost'      => $cost,
			'api_calls' => $api_calls,
			'warning'   => $warning,
		);
	}

	/**
	 * @return array|WP_Error Updated job with the completion marker, or the writer's
	 *                        warning when the translation was written but not finished.
	 */
	private static function write_job(string $job_id, array $job, array $results, int $total) {
		$post_id = (int) $job['post_id'];
		$target  = (string) $job['target'];
		if (!AIPT_Job::acquire_pair_lock($post_id, $target, self::EDITOR_PAIR_LOCK_TTL, 'editor')) {
			return self::pair_busy_error($post_id, $target);
		}

		try {
			$job['results'] = $results;

			$new_id  = self::write($job);
			$warning = null;
			$written = self::finish_failure($new_id);
			if ($written) {
				// The translation exists and is linked; only its status/slug update failed.
				$warning = $new_id;
				$new_id  = $written;
			} elseif (is_wp_error($new_id)) {
				return $new_id;
			}

			// Keep a small completion marker so a duplicate finalize returns the
			// link; drop the per-batch result transients (the large blobs).
			unset($job['results']);
			$job['status']       = 'complete';
			$job['finalized_id'] = (int) $new_id;
			AIPT_Job::save($job_id, $job);

			// Jobs prepared before per-request accounting was introduced have
			// usage only in their batch transients. Aggregate it before cleanup.
			if (empty($job['usage_recorded_per_batch'])) {
				AIPT_Usage::record_job_usage(
					$job,
					$job_id,
					AIPT_Job::get_usage_total($job_id, $total)
				);
			}

			AIPT_Job::delete_batches($job_id, $total);
			return $warning ?: $job;
		} finally {
			AIPT_Job::release_pair_lock($post_id, $target);
		}
	}

	/**
	 * Every write goes through here. The written post (new or updated, also when only
	 * its final status/slug update failed) gets the auto-translation marker, so a
	 * plugin-written post in the default language that is published later — future →
	 * publish, or a draft published by a person — is never auto-translated.
	 *
	 * @return int|WP_Error
	 */
	private static function write(array $job) {
		self::$writing++;
		try {
			$written = AIPT_Writer::write($job);
			$post_id = is_wp_error($written) ? self::finish_failure($written) : (int) $written;
			if ($post_id) {
				update_post_meta($post_id, AIPT_Auto::MARKER, time());
			}
			return $written;
		} finally {
			self::$writing--;
		}
	}

	// Post ID of a translation that was written but whose final status/slug update
	// failed (AIPT_Writer 'aipt_finish_failed'), else 0.
	private static function finish_failure($written): int {
		if (!is_wp_error($written) || $written->get_error_code() !== 'aipt_finish_failed') {
			return 0;
		}
		$data = $written->get_error_data();
		return is_array($data) ? (int) ($data['post_id'] ?? 0) : 0;
	}

	/**
	 * @return array|WP_Error
	 */
	private static function finalized_result(array $job) {
		$post_id = (int) ($job['finalized_id'] ?? 0);
		if (!$post_id || !get_post($post_id)) {
			return new WP_Error('aipt_finalized_missing', __('The saved translation was not found. Start the translation over.', 'ai-polylang-translator'));
		}

		return array(
			'post_id'   => $post_id,
			'edit_link' => get_edit_post_link($post_id, 'raw'),
		);
	}

	private static function pair_busy_error(int $post_id, string $target): WP_Error {
		$holder   = AIPT_Job::pair_lock_holder($post_id, $target);
		$messages = array(
			'cli'  => __('This translation is being written by a WP-CLI bulk translation right now. Try again in a few minutes.', 'ai-polylang-translator'),
			'auto' => __('This translation is being created automatically right now. Try again in a few minutes.', 'ai-polylang-translator'),
		);
		$message = $messages[$holder] ?? __('The translation is already being saved. Try again in a few seconds.', 'ai-polylang-translator');
		return new WP_Error('aipt_pair_locked', $message, array('holder' => $holder));
	}

	private static function with_usage(WP_Error $error, float $cost, int $api_calls): WP_Error {
		$error->add_data(array('cost' => $cost, 'api_calls' => $api_calls), 'aipt_usage');
		return $error;
	}

	private static function can_create_translation(WP_Post $post): bool {
		$post_type = get_post_type_object($post->post_type);
		return $post_type && current_user_can($post_type->cap->create_posts);
	}
}
