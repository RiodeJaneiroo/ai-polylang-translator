<?php
// AJAX layer of the bulk admin page (AIPT_Bulk_Page, assets/bulk.js): plan, estimate,
// terms, then per record prepare-or-reuse → batch (loop) → finalize over AIPT_Pipeline
// with durable jobs (AIPT_Bulk_Jobs). The browser tab only drives the requests; the
// policy (pre-checks, skip_existing, publish rule, revalidation before the write) is
// enforced here. Every action first runs AIPT_Bulk_Request::guard() (page nonce,
// manage_options, the global switch, an API key); input and responses: AIPT_Bulk_Request.

if (!defined('ABSPATH')) {
	exit;
}

class AIPT_Bulk_Ajax {

	const ACTIONS = array('taxonomies', 'plan', 'estimate', 'terms', 'prepare', 'batch', 'finalize', 'discard', 'pending', 'reset');
	// Seconds of work per estimate request; the page sends the rest in the next one.
	const ESTIMATE_BUDGET = 8.0;

	public function __construct() {
		foreach (self::ACTIONS as $action) {
			add_action('wp_ajax_aipt_bulk_' . $action, array($this, 'ajax_' . $action));
		}
	}

	/**
	 * Input from. Output {counts: {taxonomy: number of terms in from}} for every taxonomy
	 * the form offers (AIPT_Bulk_Terms::choices()).
	 */
	public function ajax_taxonomies(): void {
		AIPT_Bulk_Request::guard();
		$from   = AIPT_Bulk_Request::from();
		$counts = array();
		foreach (array_keys(AIPT_Bulk_Terms::choices()) as $taxonomy) {
			$counts[$taxonomy] = AIPT_Bulk_Terms::count($taxonomy, $from);
		}
		wp_send_json_success(array('counts' => $counts));
	}

	/**
	 * Input: post_types[], taxonomies[], from, targets[], mode, confirm, publish, keep_date.
	 * Output: {terms: [[taxonomy, lang]], posts: [{id, parent}]} (parent: post_parent, for
	 * every type: AIPT_Record::missing_parent() checks it for every type).
	 */
	public function ajax_plan(): void {
		AIPT_Bulk_Request::guard();
		$opts    = AIPT_Bulk_Request::run_opts();
		$from    = AIPT_Bulk_Request::from();
		$targets = AIPT_Bulk_Request::targets($from);

		$types   = AIPT_Bulk_Request::keys('post_types');
		$allowed = AIPT_Settings::get()['post_types'];
		foreach ($types as $type) {
			if (!in_array($type, $allowed, true)) {
				AIPT_Bulk_Request::fail('aipt_post_type_disabled', __('This post type is not enabled in the translation settings.', 'ai-polylang-translator'));
			}
			$object = get_post_type_object($type);
			if ($opts['post_status'] === 'publish' && (!$object || !current_user_can($object->cap->publish_posts))) {
				AIPT_Bulk_Request::fail('aipt_cannot_publish', __('Insufficient permissions to publish the translation.', 'ai-polylang-translator'));
			}
		}
		$taxonomies = AIPT_Bulk_Request::keys('taxonomies');
		foreach ($taxonomies as $taxonomy) {
			AIPT_Bulk_Request::check_taxonomy($taxonomy);
		}
		if (!$types && !$taxonomies) {
			AIPT_Bulk_Request::fail('aipt_nothing_selected', __('Select at least one post type or taxonomy.', 'ai-polylang-translator'));
		}

		$terms = array();
		foreach ($taxonomies as $taxonomy) {
			foreach ($targets as $target) {
				$terms[] = array($taxonomy, $target);
			}
		}
		$ids = $types ? AIPT_Select::posts($types, array('publish'), $from, '', array(), null) : array();

		wp_send_json_success(array(
			'terms' => $terms,
			'posts' => $this->post_rows($ids),
		));
	}

	/**
	 * Dry run, split by time. items (JSON): records [{id, lang}] → rows [{id, target,
	 * status, chars, cost, message}]; with terms=1, pairs [[taxonomy, lang]] → rows
	 * [{taxonomy, target, counts, chars, cost, message}]. Output {rows, context, next}:
	 * context (JSON, AIPT_Runner_Context::to_array()) carries what the estimate plans to
	 * create; next = number of items handled, the page sends the rest.
	 */
	public function ajax_estimate(): void {
		AIPT_Bulk_Request::guard();
		$opts    = AIPT_Bulk_Request::run_opts();
		$from    = AIPT_Bulk_Request::from();
		$context = AIPT_Runner_Context::from_array(AIPT_Bulk_Request::json('context'));
		$price   = AIPT_Settings::price_per_char();
		$start   = microtime(true);
		$results = array();
		$next    = 0;

		$terms = !empty($_POST['terms']);
		foreach (array_values(AIPT_Bulk_Request::json('items')) as $index => $item) {
			if ($index > 0 && microtime(true) - $start > self::ESTIMATE_BUDGET) {
				break;
			}
			$results[] = $terms
				? AIPT_Bulk_Terms::estimate((array) $item, $from, $price, $context)
				: $this->estimate_post((array) $item, $from, $opts, $price, $context);
			$next = $index + 1;
		}

		wp_send_json_success(array(
			'rows'    => $results,
			'context' => $context->to_array(),
			'next'    => $next,
		));
	}

	/**
	 * One slice of a taxonomy × language, in-process (no dry run): input taxonomy, target,
	 * from, offset; output AIPT_Bulk_Terms::slice(). The page sends the next slice from
	 * 'next' until it is null.
	 */
	public function ajax_terms(): void {
		AIPT_Bulk_Request::guard();
		$from     = AIPT_Bulk_Request::from();
		$taxonomy = AIPT_Bulk_Request::key('taxonomy');
		AIPT_Bulk_Request::check_taxonomy($taxonomy);
		$lang = AIPT_Bulk_Request::target($from);
		AIPT_Bulk_Request::send(AIPT_Bulk_Terms::slice($taxonomy, $from, $lang, absint($_POST['offset'] ?? 0)));
	}

	/**
	 * Input post_id, target, from, mode, confirm, publish, keep_date, since (run token from
	 * aipt_bulk_reset, 0 = none). Output (plus post_id, target, title): what the locator
	 * says first (AIPT_Bulk_Jobs::locate(): skipped_done for a record finished in this
	 * run, a finished job's outcome, needs_check), else {status: skipped_*, message,
	 * target_id} from the pre-check without a job, else AIPT_Bulk_Jobs::prepare_or_reuse():
	 * {status: prepared|reuse, job_id, total, next_index}, {status: needs_check, …}.
	 */
	public function ajax_prepare(): void {
		AIPT_Bulk_Request::guard();
		$opts    = AIPT_Bulk_Request::run_opts();
		$from    = AIPT_Bulk_Request::from();
		$lang    = AIPT_Bulk_Request::target($from);
		$post_id = AIPT_Bulk_Request::source_post();
		$base    = array(
			'post_id' => $post_id,
			'target'  => $lang,
			'title'   => AIPT_Slug::decode(get_the_title($post_id)),
		);

		// Before the pre-checks: a finished or half-written job must not be skipped as
		// "translation exists" nor prepared again.
		$since = absint($_POST['since'] ?? 0);
		$found = AIPT_Bulk_Jobs::locate($post_id, $lang, $since);
		if (is_wp_error($found)) {
			AIPT_Bulk_Request::send($found);
		}
		if ($found !== null) {
			wp_send_json_success($found + $base);
		}

		$check = AIPT_Runner::precheck($post_id, $lang, $from, $opts);
		if ($check['status'] === 'error') {
			AIPT_Bulk_Request::fail('aipt_record_error', $check['message']);
		}
		if ($check['status'] !== '') {
			$this->skipped($base, $check['status'], $check['message'], (int) $check['target_id']);
		}

		$result = AIPT_Bulk_Jobs::prepare_or_reuse($post_id, $lang, array(
			'mode'             => $check['mode'] ?: 'overwrite',
			'skip_existing'    => $opts['skip_existing'],
			'post_status'      => AIPT_Runner::new_post_status($post_id, $opts),
			'requested_status' => $opts['post_status'],
			'keep_date'        => $opts['keep_date'],
		), $since);
		if (is_wp_error($result)) {
			$skipped = AIPT_Record::skip_status($result);
			if ($skipped !== null) {
				$this->skipped($base, $skipped, $result->get_error_message(), 0);
			}
			AIPT_Bulk_Request::send($result);
		}
		wp_send_json_success($result + $base);
	}

	/**
	 * Input job_id, index (post_id, target: see AIPT_Bulk_Request::load_job()). Output
	 * AIPT_Pipeline::translate_batch(): {done, total, reused, usage {tokens_in, tokens_out,
	 * cost}}; 'aipt_batch_busy' comes with retry: true. A job that is no longer at the
	 * batch stage returns {status: …} instead: its outcome, or needs_check.
	 */
	public function ajax_batch(): void {
		AIPT_Bulk_Request::guard();
		list($job_id, $job) = AIPT_Bulk_Request::load_job();
		if (($settled = AIPT_Bulk_Jobs::settled($job_id, $job)) !== null) {
			AIPT_Bulk_Request::send($settled);
		}
		AIPT_Bulk_Request::send(AIPT_Pipeline::translate_batch($job_id, $job, absint($_POST['index'] ?? 0)));
	}

	/**
	 * Input job_id (post_id, target: see AIPT_Bulk_Request::load_job()). Output {status:
	 * created|updated[_with_warning], post_id, edit_link, warning}, also for a job finished
	 * earlier; {status: review, message} when the revalidation refused the write (job and
	 * locator kept, listed as needing a manual check); 'aipt_finalize_locked' (retry) while
	 * another finalize of the job is writing; {status: needs_check, …} when an earlier
	 * write of the job did not finish; {status: skipped_locked|skipped_pending, message,
	 * post_id, target} when the pair is busy or the target has backend work in progress
	 * (job and locator kept: the next Start reuses the job and its paid batches).
	 */
	public function ajax_finalize(): void {
		AIPT_Bulk_Request::guard();
		list($job_id, $job) = AIPT_Bulk_Request::load_job();

		// A retried finalize whose first attempt is still writing waits; one whose attempt
		// died inside the writer is never written twice.
		if (($settled = AIPT_Bulk_Jobs::settled($job_id, $job)) !== null) {
			AIPT_Bulk_Request::send($settled);
		}

		$valid = AIPT_Runner::revalidate_before_write($job);
		if (is_wp_error($valid)) {
			AIPT_Bulk_Jobs::mark_review((int) $job['post_id'], (string) $job['target'], $job_id, $valid->get_error_message());
			wp_send_json_success(array(
				'status'  => 'review',
				'message' => $valid->get_error_message(),
			));
		}

		// AIPT_Finalize says which stage it left the job at ('stage', or 'aipt_stage' error
		// data; the job's post_id and target never change, so $job is enough here).
		$result = AIPT_Pipeline::finalize($job_id, $job);
		$source = (int) $job['post_id'];
		$lang   = (string) $job['target'];
		if (is_wp_error($result)) {
			// Another finalize took over a stale lock and found the write interrupted.
			if ($result->get_error_code() === 'aipt_finalize_interrupted') {
				wp_send_json_success(AIPT_Bulk_Jobs::interrupted($job_id, $job));
			}
			// A busy pair or a pending target is a skip like in ajax_prepare(), not an error;
			// nothing was written, the job and its locator stay for the next Start.
			$skipped = AIPT_Record::skip_status($result);
			if ($skipped !== null) {
				wp_send_json_success(array(
					'status'  => $skipped,
					'message' => $result->get_error_message(),
					'post_id' => $source,
					'target'  => $lang,
				));
			}
			// Any other error is sent with its stage ('finalizing' after a partial write).
			if ($result->get_error_code() !== 'aipt_written_with_warning') {
				AIPT_Bulk_Request::send($result);
			}
			$reply = array(
				'status'  => AIPT_Pipeline::outcome_status($job, true),
				'post_id' => AIPT_Pipeline::written_id($result),
				'warning' => AIPT_Pipeline::warning_text($result),
			);
			$stage = $result->get_error_data('aipt_stage');
		} else {
			$reply = array(
				'status'  => (string) $result['outcome'],
				'post_id' => (int) $result['post_id'],
				'warning' => (string) $result['warning'],
			);
			$stage = $result['stage'];
		}

		if ($stage === 'complete') {
			AIPT_Bulk_Request::send(AIPT_Bulk_Jobs::complete($job_id, $source, $lang, $reply));
		}
		// Written, but the completion marker could not be saved: the job stays in the
		// journal (the next run reports it as needing a check instead of writing again).
		wp_send_json_success(array(
			'status'    => $reply['status'],
			'post_id'   => $reply['post_id'],
			'edit_link' => is_wp_error($result) ? '' : (string) $result['edit_link'],
			'warning'   => $reply['warning'],
		));
	}

	// Input job_id (durable), optional post_id and target for a job that no longer exists.
	public function ajax_discard(): void {
		AIPT_Bulk_Request::guard();
		$job_id = AIPT_Bulk_Request::job_id();
		if (!AIPT_Job_Store::is_durable($job_id)) {
			AIPT_Bulk_Request::fail('aipt_job_expired', __('Translation job not found or expired. Start over.', 'ai-polylang-translator'));
		}
		// Any administrator may discard any job, so stale entries can always be cleared.
		$discarded = AIPT_Bulk_Jobs::discard($job_id, AIPT_Job::get($job_id), absint($_POST['post_id'] ?? 0), AIPT_Bulk_Request::key('target'));
		if (is_wp_error($discarded)) {
			AIPT_Bulk_Request::send($discarded);
		}
		wp_send_json_success(array('job_id' => $job_id));
	}

	// Output {pending: AIPT_Bulk_Jobs::pending()}.
	public function ajax_pending(): void {
		AIPT_Bulk_Request::guard();
		wp_send_json_success(array('pending' => AIPT_Bulk_Jobs::pending()));
	}

	/**
	 * A new run starts (or the last one finished): the tombstones of finished records are
	 * removed. Output {since: run token (server time)}.
	 */
	public function ajax_reset(): void {
		AIPT_Bulk_Request::guard();
		AIPT_Bulk_Locator::reset();
		wp_send_json_success(array('since' => time()));
	}

	// ---- Work ----------------------------------------------------------------

	// A record skipped without a job: the pre-check or a pipeline skip code.
	private function skipped(array $base, string $status, string $message, int $target_id): void {
		wp_send_json_success($base + array(
			'status'    => $status,
			'message'   => $message,
			'target_id' => $target_id,
		));
	}

	private function estimate_post(array $item, string $from, array $opts, float $price, AIPT_Runner_Context $context): array {
		$post_id = absint($item['id'] ?? 0);
		$lang    = sanitize_key((string) ($item['lang'] ?? ''));
		if (!AIPT_Bulk_Request::valid_target($lang, $from)) {
			$outcome = AIPT_Record::outcome('error', 0, 0.0, 'invalid target language');
		} elseif (!in_array(get_post_type($post_id), AIPT_Settings::get()['post_types'], true)) {
			$outcome = AIPT_Record::outcome('error', 0, 0.0, 'post not found or post type not enabled');
		} else {
			$outcome = AIPT_Runner::estimate($post_id, $lang, $from, $opts, $price, $context);
		}
		return array(
			'id'      => $post_id,
			'target'  => $lang,
			'status'  => $outcome['status'],
			'chars'   => (int) ($outcome['chars'] ?? 0),
			'cost'    => (float) $outcome['cost'],
			'message' => (string) $outcome['message'],
		);
	}

	// [{id, parent}] in the order of $ids (parent: post_parent, whatever the type, since a
	// child is skipped while its parent has no translation), from one query per 500 IDs.
	private function post_rows(array $ids): array {
		global $wpdb;
		$found = array();
		foreach (array_chunk($ids, 500) as $chunk) {
			$placeholders = implode(',', array_fill(0, count($chunk), '%d'));
			$rows         = $wpdb->get_results($wpdb->prepare(
				"SELECT ID, post_parent FROM {$wpdb->posts} WHERE ID IN ($placeholders)",
				$chunk
			));
			foreach ($rows as $row) {
				$found[(int) $row->ID] = $row;
			}
		}
		$list = array();
		foreach ($ids as $id) {
			if (isset($found[$id])) {
				$list[] = array(
					'id'     => (int) $id,
					'parent' => (int) $found[$id]->post_parent,
				);
			}
		}
		return $list;
	}
}
