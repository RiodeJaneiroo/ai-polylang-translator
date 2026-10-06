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

	// Post meta on every post the pipeline writes: the job (or, for translate_post(), the
	// cost-log key) that wrote it, so a partial write can be traced to its job.
	const JOB_META = '_aipt_job';

	// Depth of AIPT_Writer calls in progress, so save hooks can tell the plugin's own
	// writes from other saves.
	private static $writing = 0;

	/**
	 * @param array $opts mode ('overwrite'|'safe'), confirm (bool), post_status (new
	 *                    translations only, default 'draft'), requested_status (the status
	 *                    asked for before AIPT_Runner::new_post_status(), default post_status),
	 *                    keep_date (bool), durable (bool: job stored in options instead of
	 *                    transients, AIPT_Job_Store).
	 * @return array|WP_Error {job_id, total}
	 */
	public static function prepare(int $post_id, string $target, array $opts = array()) {
		$job = AIPT_Job_Builder::build($post_id, $target, $opts);
		if (is_wp_error($job)) {
			return $job;
		}

		// Payload written once here; only finalize adds its stage and outcome. Per-batch
		// results live in their own entries (AIPT_Job_Store) so concurrent batch requests
		// cannot clobber a shared blob (last-write-wins data loss).
		$job_id = AIPT_Job::create($job, !empty($opts['durable']));
		if ($job_id === '') {
			return new WP_Error('aipt_job_too_large', __('Could not save the translation job — the payload is too large. Contact an administrator.', 'ai-polylang-translator'));
		}

		return array('job_id' => $job_id, 'total' => count($job['batches']));
	}

	/**
	 * Translate one batch. Idempotent: a finished batch is never re-translated. The
	 * lookup, the API call and the save run under a per-batch lock, so two requests for
	 * the same batch (a retry overlapping a slow request, a reopened tab) never both pay;
	 * under it the batch and the job are read again past the request's caches: a job that
	 * is gone is 'aipt_job_expired', one already finalizing or complete is reported as
	 * reused (no API call).
	 *
	 * @param array|null $usage Receives this call's token usage (zeros when no API call was made).
	 * @return array|WP_Error {done, total, reused (batch was already saved), usage
	 *                        {tokens_in, tokens_out, cost}}. 'aipt_batch_busy' when another
	 *                        request holds the batch; errors after an API call carry
	 *                        {cost, api_calls} as 'aipt_usage' error data. Gateway errors
	 *                        keep their codes (aipt_http, aipt_http_<status>, aipt_bad_json…).
	 */
	public static function translate_batch(string $job_id, array $job, int $index, ?array &$usage = null) {
		$usage   = array('tokens_in' => 0, 'tokens_out' => 0, 'cost' => 0.0);
		$backend = self::backend_error($job);
		if ($backend) {
			return $backend;
		}
		$total = count($job['batches']);
		if (!isset($job['batches'][$index])) {
			return new WP_Error('aipt_unknown_batch', __('Unknown translation block.', 'ai-polylang-translator'));
		}

		if (AIPT_Job::get_batch($job_id, $index) !== false) {
			return self::batch_done($index, $total, true, $usage);
		}

		return AIPT_Job::with_named_lock(
			AIPT_Job::batch_lock_key($job_id, $index),
			self::batch_lock_ttl(),
			static function () use ($job_id, $index, $total, &$usage) {
				// The request that held the lock may have saved it meanwhile.
				if (AIPT_Job::get_batch($job_id, $index, true) !== false) {
					return self::batch_done($index, $total, true, $usage);
				}
				// The payload loaded before the lock may be stale: the job may have been
				// finalized, discarded or replaced meanwhile. Never pay for such a job.
				$job = AIPT_Job::get_fresh($job_id);
				if (!$job) {
					return new WP_Error('aipt_job_expired', __('Translation job not found or expired. Start over.', 'ai-polylang-translator'));
				}
				if (self::job_stage($job) !== 'none') {
					return self::batch_done($index, $total, true, $usage);
				}

				$result = self::request_batch($job_id, $job, $index, $usage);
				if (is_wp_error($result)) {
					return self::with_usage($result, (float) $usage['cost'], 1);
				}

				if (!AIPT_Job::save_batch($job_id, $index, $result, $usage)) {
					return self::with_usage(
						new WP_Error('aipt_batch_too_large', __('Could not save the translation result — the payload is too large. Contact an administrator.', 'ai-polylang-translator')),
						(float) $usage['cost'],
						1
					);
				}
				return self::batch_done($index, $total, false, $usage);
			},
			new WP_Error('aipt_batch_busy', __('This block is already being translated by another request. Try again in a few minutes.', 'ai-polylang-translator'))
		);
	}

	private static function batch_done(int $index, int $total, bool $reused, array $usage): array {
		return array('done' => $index + 1, 'total' => $total, 'reused' => $reused, 'usage' => $usage);
	}

	// Long enough for one translate_map() at the configured gateway timeout (up to four
	// sequential posts: the HTTP 400 retry, twice with the missing-keys retry), so a live
	// request's lock is never taken over as stale.
	public static function batch_lock_ttl(): int {
		return max(240, (int) AIPT_Settings::get()['timeout'] * 4 + 60);
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
	 * Merge the batch results and write the translation (editor path; AIPT_Finalize).
	 * $job must already be verified to belong to the current user; it is re-read and
	 * re-checked under the finalize lock.
	 *
	 * @return array|WP_Error {post_id, edit_link, warning, outcome (created|updated, with a
	 *                        '_with_warning' suffix)}. A write with a warning returns the
	 *                        warning as an error the first time; a repeated finalize
	 *                        returns the link with the warning text. A durable job whose
	 *                        earlier write died midway: 'aipt_finalize_interrupted'.
	 */
	public static function finalize(string $job_id, array $job) {
		return AIPT_Finalize::run($job_id, $job);
	}

	// none | finalizing | complete, see AIPT_Finalize::stage().
	public static function job_stage(array $job): string {
		return AIPT_Finalize::stage($job);
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
	 *                        suffix when the final status/slug update failed or a content
	 *                        chunk kept its source text (AIPT_Blocks), or
	 *                        skipped_existing), cost, api_calls, warning}. A busy pair is
	 *                        'aipt_pair_locked' with {holder} error data, a target with
	 *                        backend work in progress 'aipt_target_pending'; other errors
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
		aipt_lang()->refresh();

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

	// Long in-process runs (WP-CLI, cron) would keep stale cached data (Polylang keeps its
	// translation groups there). A persistent cache without runtime flush is left alone
	// (it is site-wide).
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

	// The job exists and was started by the current user.
	public static function job_owned(?array $job): bool {
		return $job && (int) ($job['user_id'] ?? 0) === get_current_user_id();
	}

	public static function job_belongs(?array $job, int $post_id): bool {
		return self::job_owned($job) && (int) ($job['post_id'] ?? 0) === $post_id;
	}

	// created|updated (the job updates an existing translation), with '_with_warning'.
	public static function outcome_status(array $job, bool $warning): string {
		return (!empty($job['existing']) ? 'updated' : 'created') . ($warning ? '_with_warning' : '');
	}

	/**
	 * Jobs carry the adapter they were prepared with. A job from another backend (the
	 * site switched between Polylang and WPML within the job's TTL) is refused and left
	 * intact; a job without 'backend' was prepared before 1.6, when only Polylang existed.
	 */
	public static function backend_error(array $job): ?WP_Error {
		if (($job['backend'] ?? 'polylang') === aipt_lang()->name()) {
			return null;
		}
		return new WP_Error('aipt_job_backend', sprintf(
			/* translators: %s: multilingual plugin name, e.g. Polylang */
			__('This translation job was not prepared with %s. Start the translation again.', 'ai-polylang-translator'),
			AIPT_Lang_Loader::label()
		));
	}

	/**
	 * @return array|WP_Error
	 */
	private static function run_post(int $post_id, string $target, array $opts) {
		$job = AIPT_Job_Builder::build($post_id, $target, $opts);
		if (is_wp_error($job)) {
			return $job;
		}

		// Never stored; it keys this record's row in the cost log and the JOB_META of the post.
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
		aipt_lang()->refresh();
		$job['results'] = $results;
		$new_id         = self::write($job, $job_id);
		$warning        = '';
		$written        = self::finish_failure($new_id);
		if ($written) {
			$warning = self::warning_text($new_id);
			$new_id  = $written;
		} elseif (is_wp_error($new_id)) {
			return self::with_usage($new_id, $cost, $api_calls);
		}

		return array(
			'target_id' => (int) $new_id,
			'status'    => self::outcome_status($job, $warning !== ''),
			'cost'      => $cost,
			'api_calls' => $api_calls,
			'warning'   => $warning,
		);
	}

	/**
	 * Every write goes through here. The writer tags the written post (new or updated)
	 * as soon as it exists with the post meta in $job['tag_meta']: the auto-translation
	 * marker, so a plugin-written post in the default language that is published later —
	 * future → publish, or a draft published by a person — is never auto-translated; and
	 * the job id (JOB_META).
	 *
	 * The caller must hold the pair lock (AIPT_Job::acquire_pair_lock) and, for a durable
	 * job, have saved its 'finalizing' stage (AIPT_Finalize).
	 *
	 * @return int|WP_Error
	 */
	public static function write(array $job, string $job_id) {
		self::$writing++;
		try {
			$job['tag_meta'] = array(AIPT_Auto::MARKER => time(), self::JOB_META => $job_id);
			return AIPT_Writer::write($job);
		} finally {
			self::$writing--;
		}
	}

	// Post ID of a translation that was written with a warning ('aipt_written_with_warning':
	// its final status/slug update failed, or a content chunk kept its source text because
	// the block tokens came back changed), else 0.
	public static function finish_failure($written): int {
		if (!is_wp_error($written) || $written->get_error_code() !== 'aipt_written_with_warning') {
			return 0;
		}
		return self::written_id($written);
	}

	// Post ID a writer error says exists on disk, else 0: a warning, or a link failure of
	// an updated post (a created post is deleted again by the writer; only a failed
	// deletion leaves it, with its ID). Such a partial write still gets the marker.
	public static function written_id(WP_Error $error): int {
		if (!in_array($error->get_error_code(), array('aipt_written_with_warning', 'aipt_link_failed'), true)) {
			return 0;
		}
		$data = $error->get_error_data();
		return is_array($data) ? (int) ($data['post_id'] ?? 0) : 0;
	}

	// A warning may carry several messages (finish failure plus block fallback): one line.
	public static function warning_text(WP_Error $error): string {
		return implode(' ', $error->get_error_messages());
	}

	public static function pair_busy_error(int $post_id, string $target): WP_Error {
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
}
