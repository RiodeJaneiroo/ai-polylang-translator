<?php
// Finalize step of the stored-job pipeline (AIPT_Pipeline::finalize): merge the saved
// batches and write the translation under the finalize and pair locks, then store the
// outcome (status 'complete'). A durable job also records stage 'finalizing' before the
// writer runs, so a request that died inside the writer is recognisable
// (AIPT_Pipeline::job_stage()) and is never written twice; it keeps only a small
// completion marker (MARKER_KEYS) and never expires: the bulk layer calls
// AIPT_Job::delete() once it has read the outcome. run() says which stage it left the job
// at ('stage' in its result, 'aipt_stage' error data), so the caller need not read the job
// again.

if (!defined('ABSPATH')) {
	exit;
}

class AIPT_Finalize {

	// What a durable job keeps once complete: ownership and backend checks, the outcome.
	const MARKER_KEYS = array(
		'user_id', 'post_id', 'target', 'backend', 'schema', 'model', 'existing', 'mode',
		'status', 'finalized_id', 'warning', 'outcome',
	);

	/**
	 * @return array|WP_Error {post_id, edit_link, warning, outcome, stage: 'complete', or
	 *                        'finalizing' when the completion marker could not be saved}.
	 *                        The first-time 'aipt_written_with_warning' carries the same
	 *                        'aipt_stage' error data; any other error returned while the job
	 *                        is left at stage 'finalizing' carries aipt_stage 'finalizing'.
	 */
	public static function run(string $job_id, array $job) {
		$backend = AIPT_Pipeline::backend_error($job);
		if ($backend) {
			return $backend;
		}
		// A repeated finalize after a successful write returns the saved translation.
		if (($job['status'] ?? '') === 'complete') {
			return self::result($job);
		}

		$post_id = (int) ($job['post_id'] ?? 0);
		if (!AIPT_Job::acquire_finalize_lock($job_id)) {
			return self::locked_error();
		}

		$error = null;
		try {
			// The copy the request loaded before the lock may be stale (finalized, discarded
			// or interrupted meanwhile): every check below runs on a fresh read.
			$job = AIPT_Job::get_fresh($job_id);
			if (!AIPT_Pipeline::job_belongs($job, $post_id)) {
				$error = new WP_Error('aipt_job_expired', __('Translation job not found or expired. Start over.', 'ai-polylang-translator'));
			} elseif (self::stage($job) === 'finalizing') {
				// An earlier finalize started writing and died (its lock went stale): a
				// partial translation may exist, never write it a second time. Only durable
				// jobs are ever saved at this stage.
				$error = self::interrupted_error();
			} elseif (($job['status'] ?? '') !== 'complete') {
				// A 'complete' status here means a concurrent finalize won while we waited.
				$total   = count($job['batches'] ?? array());
				$results = AIPT_Job::get_batches($job_id, $total);
				$written = $results === null
					? new WP_Error('aipt_incomplete_batches', __('Not all blocks are translated — finalization is not possible.', 'ai-polylang-translator'))
					: self::write_job($job_id, $job, $results, $total);
				if (is_wp_error($written)) {
					// Batches stay in place so the user can retry finalize — unless the
					// translation was written with a warning: then the job is already
					// complete and the retry returns its link and the warning.
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

		return self::result($job);
	}

	// none (not finalized yet, or the last attempt failed before writing anything),
	// finalizing (durable jobs: a write was started and has no outcome; it may have left
	// a partial translation, see the _aipt_job post meta) or complete (status).
	public static function stage(array $job): string {
		if (($job['status'] ?? '') === 'complete') {
			return 'complete';
		}
		return ($job['stage'] ?? '') === 'finalizing' ? 'finalizing' : 'none';
	}

	// Another finalize of the job is writing right now.
	public static function locked_error(): WP_Error {
		return new WP_Error('aipt_finalize_locked', __('The translation is already being saved. Try again in a few seconds.', 'ai-polylang-translator'));
	}

	// An earlier finalize of the job started writing and did not finish.
	public static function interrupted_error(): WP_Error {
		return new WP_Error('aipt_finalize_interrupted', __('A previous run started writing this translation and did not finish. Check the translation, then discard the job or translate again.', 'ai-polylang-translator'));
	}

	/**
	 * @return array|WP_Error Updated job with the completion marker and 'stage' (complete,
	 *                        or finalizing when the marker could not be saved), or the
	 *                        writer's warning (same 'aipt_stage' error data) when the
	 *                        translation was written but not finished; other errors after the
	 *                        'finalizing' stage was saved and not reset carry aipt_stage.
	 */
	private static function write_job(string $job_id, array $job, array $results, int $total) {
		$post_id = (int) $job['post_id'];
		$target  = (string) $job['target'];
		if (!AIPT_Job::acquire_pair_lock($post_id, $target, AIPT_Pipeline::EDITOR_PAIR_LOCK_TTL, 'editor')) {
			return AIPT_Pipeline::pair_busy_error($post_id, $target);
		}
		// The writer re-checks the target state against the job: read it fresh.
		aipt_lang()->refresh();

		$durable = AIPT_Job_Store::is_durable($job_id);
		try {
			// Intent first: a durable job never enters the writer without it on record.
			if ($durable) {
				$job['stage'] = 'finalizing';
				if (!AIPT_Job::save($job_id, $job)) {
					return new WP_Error('aipt_job_too_large', __('Could not save the translation job — the payload is too large. Contact an administrator.', 'ai-polylang-translator'));
				}
			}

			$job['results'] = $results;
			$new_id         = AIPT_Pipeline::write($job, $job_id);
			unset($job['results']);
			$warning = null;
			$written = AIPT_Pipeline::finish_failure($new_id);
			if ($written) {
				// The translation exists and is linked; only its status/slug update failed or
				// some content kept its source text. The editor shows one message: join them.
				$warning = new WP_Error($new_id->get_error_code(), AIPT_Pipeline::warning_text($new_id), $new_id->get_error_data());
				$new_id  = $written;
			} elseif (is_wp_error($new_id)) {
				// Nothing on disk: back to 'none', the job can be finalized again. A partial
				// write (an updated post that could not be linked) stays 'finalizing'.
				$reset = false;
				if ($durable && !AIPT_Pipeline::written_id($new_id)) {
					unset($job['stage']);
					$reset = AIPT_Job::save($job_id, $job);
				}
				if ($durable && !$reset) {
					$new_id->add_data('finalizing', 'aipt_stage');
				}
				return $new_id;
			}

			// Keep a small completion marker with the outcome so a duplicate finalize
			// returns the link and the warning; drop the per-batch results (the large blobs).
			// status + outcome are authoritative; 'stage' exists only while finalizing.
			unset($job['stage']);
			$job['status']       = 'complete';
			$job['finalized_id'] = (int) $new_id;
			$job['warning']      = $warning ? $warning->get_error_message() : '';
			$job['outcome']      = AIPT_Pipeline::outcome_status($job, (bool) $warning);
			// Transient (editor) jobs expire with their full payload, as before.
			$marker = $durable ? array_intersect_key($job, array_flip(self::MARKER_KEYS)) : $job;
			$saved  = AIPT_Job::save($job_id, $marker);

			// Jobs prepared before per-request accounting was introduced have
			// usage only in their batch entries. Aggregate it before cleanup.
			if (empty($job['usage_recorded_per_batch'])) {
				AIPT_Usage::record_job_usage(
					$job,
					$job_id,
					AIPT_Job::get_usage_total($job_id, $total)
				);
			}

			if ($saved) {
				AIPT_Job::delete_batches($job_id, $total);
			} else {
				// Without the marker a retry would write again; keep the paid batches for it.
				error_log(sprintf('AI Translator: job %s wrote translation #%d but its completion marker could not be saved; the batches are kept.', $job_id, (int) $new_id));
			}
			// What the stored job says now: a durable job without its marker is still at
			// 'finalizing' (a transient one at 'none').
			$stage = $saved ? 'complete' : ($durable ? 'finalizing' : 'none');
			if ($warning) {
				$warning->add_data($stage, 'aipt_stage');
				return $warning;
			}
			$job['stage'] = $stage;
			return $job;
		} finally {
			AIPT_Job::release_pair_lock($post_id, $target);
		}
	}

	/**
	 * @return array|WP_Error
	 */
	private static function result(array $job) {
		$post_id = (int) ($job['finalized_id'] ?? 0);
		if (!$post_id || !get_post($post_id)) {
			return new WP_Error('aipt_finalized_missing', __('The saved translation was not found. Start the translation over.', 'ai-polylang-translator'));
		}

		return array(
			'post_id'   => $post_id,
			'edit_link' => get_edit_post_link($post_id, 'raw'),
			// Absent on jobs completed by older versions.
			'warning'   => (string) ($job['warning'] ?? ''),
			'outcome'   => (string) ($job['outcome'] ?? ''),
			// write_job() sets it for its own write; a job read back complete has its marker.
			'stage'     => (string) ($job['stage'] ?? 'complete'),
		);
	}
}
