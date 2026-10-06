<?php
// Recovery journal of the bulk admin page (AIPT_Bulk_Ajax): one locator option per
// record (aipt_bulk_job_<post_id>_<lang>, non-autoloaded) points to the durable job of
// that source post × language, so a restarted run reuses the job and its paid batches
// instead of preparing (and paying for) the record again. A job whose write was started
// and has no outcome (stage 'finalizing'), whose target changed meanwhile, or that
// belongs to another user or other options, is never reused silently: it is reported as
// needs_check for the admin. Once a job is finished its locator becomes a tombstone
// {job_id, done: {status, post_id}, time}: records finished since the run token (the
// server time of the run's first Start) are skipped in every mode, so Stop → Start never
// pays for them again. AIPT_Bulk_Locator::reset() clears the tombstones when a new run
// starts. Locators are stored by AIPT_Bulk_Locator (compare-and-swap on the row value).
// Crash order: a finished job is deleted only after its tombstone is stored, and a job
// is discarded or replaced only under the record's prepare lock while no batch or
// finalize request of it holds its lock.

if (!defined('ABSPATH')) {
	exit;
}

class AIPT_Bulk_Jobs {

	// Named lock around lookup → prepare → locator write, and around discard:
	// aipt_bulk_prep_<post_id>_<lang>.
	const PREPARE_LOCK_PREFIX = 'aipt_bulk_prep_';
	const PREPARE_LOCK_TTL    = 120;

	/**
	 * What the locator alone says about a record, before any pre-check: a tombstone of
	 * this run (time ≥ $since) is skipped_done, a complete job returns its outcome, a job
	 * that is being written is busy, one whose write did not finish or whose target
	 * changed is needs_check. Null: go on with the pre-checks and prepare_or_reuse().
	 *
	 * @return array|WP_Error|null
	 */
	public static function locate(int $post_id, string $lang, int $since) {
		$value = AIPT_Bulk_Locator::value($post_id, $lang);
		if (AIPT_Bulk_Locator::is_tombstone($value)) {
			return self::in_run($value, $since) ? self::done_result($value, true) : null;
		}
		list($job_id, $job) = self::live_job($value);
		if (!$job) {
			return null;
		}
		$settled = self::settled($job_id, $job);
		if ($settled !== null) {
			return $settled;
		}
		if (self::target_changed($job)) {
			return self::needs_check($job_id, $job, self::target_changed_message());
		}
		return null;
	}

	/**
	 * Prepare the record's durable job, or reuse the unfinished one its locator points to.
	 * The caller has run locate(), AIPT_Runner::precheck() and checked edit_post; the
	 * locator is read again under the prepare lock (a record finished meanwhile in this
	 * run, $since, is skipped_done like in locate()).
	 *
	 * @param array $opts mode ('overwrite'|'safe', the effective mode), skip_existing,
	 *                    post_status (new_post_status()), requested_status, keep_date.
	 * @return array|WP_Error {status: prepared|reuse, job_id, total, next_index (first batch
	 *                        not saved yet)}, {status: needs_check|skipped_done, …}, or the
	 *                        outcome of a job finalized earlier.
	 */
	public static function prepare_or_reuse(int $post_id, string $lang, array $opts, int $since) {
		return self::with_prepare_lock($post_id, $lang, static function () use ($post_id, $lang, $opts, $since) {
			$raw   = AIPT_Bulk_Locator::raw($post_id, $lang);
			$value = AIPT_Bulk_Locator::decode($raw);
			if (AIPT_Bulk_Locator::is_tombstone($value) && self::in_run($value, $since)) {
				return self::done_result($value, true);
			}
			list($job_id, $job) = self::live_job($value);
			if ($job) {
				$found = self::existing_job($job_id, $job, $post_id, $lang, $opts);
				if ($found !== null) {
					return $found;
				}
				$raw = AIPT_Bulk_Locator::raw($post_id, $lang);
			} elseif ($job_id !== '') {
				AIPT_Bulk_Locator::delete_raw(AIPT_Bulk_Locator::key($post_id, $lang), $raw);
				$raw = AIPT_Bulk_Locator::raw($post_id, $lang);
			}
			// A tombstone of an earlier run is replaced, only while it is still the value read.
			return self::prepare_new($post_id, $lang, $opts, $raw);
		});
	}

	// $fn under the record's prepare lock; aipt_prepare_busy (retry) while it is taken.
	private static function with_prepare_lock(int $post_id, string $lang, callable $fn) {
		return AIPT_Job::with_named_lock(
			self::PREPARE_LOCK_PREFIX . $post_id . '_' . $lang,
			self::PREPARE_LOCK_TTL,
			$fn,
			new WP_Error('aipt_prepare_busy', __('This record is being prepared by another request. Try again in a few seconds.', 'ai-polylang-translator'))
		);
	}

	// A tombstone written since the run token: finished in this run.
	private static function in_run(array $value, int $since): bool {
		return $since && (int) ($value['time'] ?? 0) >= $since;
	}

	/**
	 * What to do with the job a locator points to; null = discarded, prepare a new one.
	 *
	 * @return array|WP_Error|null
	 */
	private static function existing_job(string $job_id, array $job, int $post_id, string $lang, array $opts) {
		$settled = self::settled($job_id, $job);
		if ($settled !== null) {
			return $settled;
		}

		if (!AIPT_Pipeline::job_belongs($job, $post_id)
			|| AIPT_Pipeline::backend_error($job)
			|| (string) ($job['target'] ?? '') !== $lang) {
			return self::needs_check($job_id, $job, __('An unfinished job of this record was started by another user or under another multilingual plugin.', 'ai-polylang-translator'));
		}
		if (self::target_changed($job)) {
			return self::needs_check($job_id, $job, self::target_changed_message());
		}
		$progress = self::batch_progress($job_id, $job);
		if (!self::same_options($job, $opts)) {
			if ($progress['saved'] > 0) {
				return self::needs_check($job_id, $job, __('An unfinished job of this record was prepared with other options (mode, publishing or dates).', 'ai-polylang-translator'));
			}
			// Nothing paid yet: a job with other options is simply replaced.
			$discarded = self::discard_locked($job_id, $job, $post_id, $lang);
			return is_wp_error($discarded) ? $discarded : null;
		}
		return array(
			'status'     => 'reuse',
			'job_id'     => $job_id,
			'total'      => count($job['batches'] ?? array()),
			'next_index' => $progress['next_index'],
		);
	}

	/**
	 * @return array|WP_Error
	 */
	private static function prepare_new(int $post_id, string $lang, array $opts, string $raw) {
		$prepared = AIPT_Pipeline::prepare($post_id, $lang, $opts + array('confirm' => true, 'durable' => true));
		if (is_wp_error($prepared)) {
			return $prepared;
		}
		// Before the first paid batch: a restarted run finds the job through it.
		if (!AIPT_Bulk_Locator::put($post_id, $lang, $raw, array('job_id' => $prepared['job_id'], 'time' => time()))) {
			AIPT_Job::delete($prepared['job_id']);
			return new WP_Error('aipt_locator_failed', __('Could not save the bulk job reference. Try again.', 'ai-polylang-translator'));
		}
		return array(
			'status'     => 'prepared',
			'job_id'     => $prepared['job_id'],
			'total'      => (int) $prepared['total'],
			'next_index' => 0,
		);
	}

	// The options a reused job must have been prepared with. post_status applies to new
	// translations only (an update keeps the target's status), so it is compared only then.
	private static function same_options(array $job, array $opts): bool {
		return (string) ($job['mode'] ?? '') === (string) $opts['mode']
			&& (!empty($job['existing']) || (string) ($job['post_status'] ?? '') === (string) $opts['post_status'])
			&& !empty($job['keep_date']) === !empty($opts['keep_date']);
	}

	// The target the job was prepared against is no longer the target (someone translated
	// the record elsewhere, or deleted its translation, after the job was prepared).
	private static function target_changed(array $job): bool {
		$state = aipt_lang()->translation_state((int) ($job['post_id'] ?? 0), (string) ($job['target'] ?? ''));
		return $state['state'] !== 'pending' && AIPT_Record::target_changed($job, $state);
	}

	private static function target_changed_message(): string {
		return __('The translation of this record changed after its unfinished job was prepared (it was translated or removed elsewhere). Check the translation, then discard the job.', 'ai-polylang-translator');
	}

	/**
	 * A job past the batch stage: a complete job's outcome (complete()), a 'finalizing'
	 * one busy or needs_check (finalizing()); null while it is still at the batch stage.
	 *
	 * @return array|WP_Error|null
	 */
	public static function settled(string $job_id, array $job) {
		switch (AIPT_Pipeline::job_stage($job)) {
			case 'complete':
				return self::complete_marker($job_id, $job);
			case 'finalizing':
				return self::finalizing($job_id, $job);
			default:
				return null;
		}
	}

	// A job in stage 'finalizing': busy while its finalize holds the lock, else its write
	// died midway and needs a manual check.
	private static function finalizing(string $job_id, array $job) {
		if (AIPT_Job::finalize_lock_held($job_id)) {
			return AIPT_Finalize::locked_error();
		}
		return self::interrupted($job_id, $job);
	}

	/**
	 * needs_check for a job whose write died midway (stage 'finalizing'), also when
	 * finalize found it so (AIPT_Finalize::interrupted_error()) and $job is the copy loaded
	 * before (its post_id and target never change).
	 */
	public static function interrupted(string $job_id, array $job): array {
		$job['stage'] = 'finalizing';
		return self::needs_check($job_id, $job, AIPT_Finalize::interrupted_error()->get_error_message());
	}

	/**
	 * Outcome of a finished job. Its locator becomes a tombstone first; the job is deleted
	 * only once the tombstone is stored (or when no locator points to the job any more).
	 * While the locator still points to it, the job's completion marker keeps answering.
	 *
	 * @param int    $source  Source post of the job.
	 * @param string $lang    Target language of the job.
	 * @param array  $outcome status (created|updated[_with_warning]), post_id (the
	 *                        translation), warning.
	 */
	public static function complete(string $job_id, int $source, string $lang, array $outcome): array {
		$post_id = (int) ($outcome['post_id'] ?? 0);
		$status  = (string) ($outcome['status'] ?? '');
		$result  = array(
			'status'    => $status,
			'post_id'   => $post_id,
			'edit_link' => self::edit_link($post_id),
			'warning'   => (string) ($outcome['warning'] ?? ''),
		);
		$match     = AIPT_Bulk_Locator::live_match($job_id);
		$tombstone = array('job_id' => $job_id, 'done' => array('status' => $status, 'post_id' => $post_id), 'time' => time());
		// The swap is a compare-and-swap that changed exactly one row: the tombstone is stored.
		if (AIPT_Bulk_Locator::swap($source, $lang, $match, $tombstone)) {
			AIPT_Job::delete($job_id);
			return $result;
		}
		$value = AIPT_Bulk_Locator::value($source, $lang);
		if (!$value || !$match($value)) {
			AIPT_Job::delete($job_id);
		}
		return $result;
	}

	// complete() with the outcome a job's completion marker records.
	private static function complete_marker(string $job_id, array $job): array {
		return self::complete($job_id, (int) ($job['post_id'] ?? 0), (string) ($job['target'] ?? ''), array(
			'status'  => (string) ($job['outcome'] ?? ''),
			'post_id' => (int) ($job['finalized_id'] ?? 0),
			'warning' => (string) ($job['warning'] ?? ''),
		));
	}

	/**
	 * The outcome a tombstone records for $job_id (a finalize retried after the job was
	 * finished and deleted), else null.
	 */
	public static function tombstone(int $post_id, string $lang, string $job_id): ?array {
		$value = AIPT_Bulk_Locator::value($post_id, $lang);
		return AIPT_Bulk_Locator::is_tombstone($value) && ($value['job_id'] ?? '') === $job_id ? self::done_result($value, false) : null;
	}

	// skipped_done for a record finished earlier in this run, else the recorded outcome.
	private static function done_result(array $value, bool $skipped): array {
		$post_id = (int) ($value['done']['post_id'] ?? 0);
		$status  = (string) ($value['done']['status'] ?? '');
		return array(
			'status'    => $skipped ? 'skipped_done' : $status,
			'post_id'   => $post_id,
			'edit_link' => self::edit_link($post_id),
			'message'   => $skipped ? $status : '',
		);
	}

	/**
	 * Delete the job and its locator (only while the locator still points to this job and
	 * is not a tombstone), under the record's prepare lock. Refused (aipt_job_busy, retry)
	 * while a batch or finalize request of the job holds its lock.
	 *
	 * @return true|WP_Error
	 */
	public static function discard(string $job_id, ?array $job, int $post_id = 0, string $lang = '') {
		if ($job) {
			$post_id = (int) ($job['post_id'] ?? 0);
			$lang    = (string) ($job['target'] ?? '');
		}
		if (!$post_id || $lang === '') {
			return self::discard_locked($job_id, $job, 0, '');
		}
		return self::with_prepare_lock($post_id, $lang, static fn() => self::discard_locked($job_id, $job, $post_id, $lang));
	}

	// discard() with the prepare lock held (or no record to lock). The job is read again:
	// one finished meanwhile is settled (tombstone) instead of being thrown away.
	private static function discard_locked(string $job_id, ?array $job, int $post_id, string $lang) {
		$fresh = $job ? AIPT_Job::get_fresh($job_id) : null;
		if ($fresh) {
			if (AIPT_Job::finalize_lock_held($job_id)) {
				return new WP_Error('aipt_job_busy', __('The translation is already being saved. Try again in a few seconds.', 'ai-polylang-translator'));
			}
			if (AIPT_Job::batch_lock_held($job_id, AIPT_Pipeline::batch_lock_ttl())) {
				return new WP_Error('aipt_job_busy', __('A block of this job is being translated right now. Try again in a minute.', 'ai-polylang-translator'));
			}
			if (AIPT_Pipeline::job_stage($fresh) === 'complete') {
				self::complete_marker($job_id, $fresh);
				return true;
			}
		}
		AIPT_Job::delete($job_id);
		if ($post_id && $lang !== '') {
			AIPT_Bulk_Locator::swap($post_id, $lang, AIPT_Bulk_Locator::live_match($job_id), null);
		}
		return true;
	}

	// Only a job that entered the writer (stage 'finalizing') can have written a post.
	private static function needs_check(string $job_id, array $job, string $message): array {
		$target_id = AIPT_Pipeline::job_stage($job) === 'finalizing' ? self::written_target($job_id) : 0;
		return array(
			'status'      => 'needs_check',
			'job_id'      => $job_id,
			'post_id'     => (int) ($job['post_id'] ?? 0),
			'target'      => (string) ($job['target'] ?? ''),
			'source_link' => self::edit_link((int) ($job['post_id'] ?? 0)),
			'target_id'   => $target_id,
			'target_link' => self::edit_link($target_id),
			'message'     => $message,
		);
	}

	// A post the job wrote (AIPT_Pipeline::JOB_META), also a partial write; 0 if none.
	// A direct postmeta query: get_posts() with 'any' skips post types excluded from
	// search and statuses such as trash or auto-draft, and the multilingual plugin may
	// filter it by language; a half-written post can be in any of those.
	private static function written_target(string $job_id): int {
		global $wpdb;
		return (int) $wpdb->get_var($wpdb->prepare( // phpcs:ignore WordPress.DB.DirectDatabaseQuery
			"SELECT post_id FROM {$wpdb->postmeta} WHERE meta_key = %s AND meta_value = %s ORDER BY post_id DESC LIMIT 1",
			AIPT_Pipeline::JOB_META,
			$job_id
		));
	}

	/**
	 * Batches of the job saved so far and the first index without a saved result (= total
	 * when all are saved), from one names-only query.
	 *
	 * @return array{saved: int, next_index: int}
	 */
	private static function batch_progress(string $job_id, array $job): array {
		$total  = count($job['batches'] ?? array());
		$saved  = array_flip(array_filter(AIPT_Job::saved_batch_indexes($job_id), static fn(int $index): bool => $index < $total));
		$next   = 0;
		while ($next < $total && isset($saved[$next])) {
			$next++;
		}
		return array('saved' => count($saved), 'next_index' => $next);
	}

	/**
	 * Every unfinished job, for the "needs manual check" panel. Tombstones are ignored,
	 * locators whose job no longer exists are removed (only while still the value seen),
	 * and complete jobs are finished here (listed once with their outcome, then tombstones).
	 *
	 * @return array[] {post_id, target, from (the job's source language), job_id, stage, title, source_link, target_id,
	 *                 target_link, owner, mine, done, total, review, target_changed, outcome}
	 */
	public static function pending(): array {
		$list = array();
		foreach (AIPT_Bulk_Locator::all(false) as $locator) {
			list($post_id, $lang, $value, $name, $raw) = $locator;
			list($job_id, $job) = self::live_job($value);
			if (!$job) {
				AIPT_Bulk_Locator::delete_raw($name, $raw);
				continue;
			}
			$stage   = AIPT_Pipeline::job_stage($job);
			$outcome = $stage === 'complete' ? self::complete_marker($job_id, $job) : null;
			$target  = $outcome ? (int) $outcome['post_id'] : ($stage === 'finalizing' ? self::written_target($job_id) : 0);
			$owner   = get_userdata((int) ($job['user_id'] ?? 0));
			$list[]  = array(
				'post_id'        => $post_id,
				'target'         => $lang,
				'from'           => (string) ($job['source_lang'] ?? ''),
				'job_id'         => $job_id,
				'stage'          => $stage,
				'title'          => AIPT_Slug::decode(get_the_title($post_id)),
				'source_link'    => self::edit_link($post_id),
				'target_id'      => $target,
				'target_link'    => self::edit_link($target),
				'owner'          => $owner ? $owner->display_name : '',
				'mine'           => AIPT_Pipeline::job_owned($job),
				'done'           => $outcome ? 0 : self::batch_progress($job_id, $job)['saved'],
				'total'          => count($job['batches'] ?? array()),
				'review'         => (string) ($value['review'] ?? ''),
				'target_changed' => $stage === 'none' && self::target_changed($job),
				'outcome'        => $outcome ? $outcome['status'] : '',
			);
		}
		return $list;
	}

	// The revalidation message of a job finalize refused, shown in the pending list.
	public static function mark_review(int $post_id, string $lang, string $job_id, string $message): void {
		$value = AIPT_Bulk_Locator::value($post_id, $lang);
		$match = AIPT_Bulk_Locator::live_match($job_id);
		if ($value && $match($value)) {
			$value['review'] = $message;
			AIPT_Bulk_Locator::swap($post_id, $lang, $match, $value);
		}
	}

	/**
	 * Job id and job of a locator that is not a tombstone: ['', null] for none or a
	 * tombstone, [job_id, null] when the job no longer exists.
	 *
	 * @return array{0: string, 1: ?array}
	 */
	private static function live_job(?array $value): array {
		$job_id = $value && !isset($value['done']) ? (string) ($value['job_id'] ?? '') : '';
		return array($job_id, $job_id !== '' ? AIPT_Job::get_fresh($job_id) : null);
	}

	private static function edit_link(int $post_id): string {
		return $post_id ? (string) get_edit_post_link($post_id, 'raw') : '';
	}
}
