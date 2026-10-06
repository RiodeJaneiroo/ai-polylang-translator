<?php
// Request side of the bulk AJAX layer (AIPT_Bulk_Ajax): the guard every action runs,
// sanitized and validated input, and the JSON response. Errors are {code, message} plus
// aipt_usage {cost, api_calls} when an API call was billed, stage 'finalizing' when a
// failed write left the job mid-write, and retry: true when the same request may simply
// be sent again later. fail() and send() end the request.

if (!defined('ABSPATH')) {
	exit;
}

class AIPT_Bulk_Request {

	const NONCE = 'aipt_bulk';
	// Errors after which the same request can be sent again unchanged.
	const RETRY_CODES = array('aipt_batch_busy', 'aipt_prepare_busy', 'aipt_finalize_locked', 'aipt_terms_busy', 'aipt_job_busy', 'aipt_strings_busy');

	public static function guard(): void {
		if (!check_ajax_referer(self::NONCE, '_ajax_nonce', false)) {
			self::fail('aipt_bad_nonce', __('The page has expired. Reload it and start again.', 'ai-polylang-translator'));
		}
		if (!current_user_can('manage_options')) {
			self::fail('aipt_forbidden', __('Insufficient permissions.', 'ai-polylang-translator'));
		}
		if (!AIPT_Settings::enabled()) {
			self::fail('aipt_disabled', __('AI Translator is disabled in Settings.', 'ai-polylang-translator'));
		}
		if (AIPT_Settings::api_key() === '') {
			self::fail('aipt_no_key', __('API key is not set.', 'ai-polylang-translator'));
		}
		// A request whose tab went away still reaches its checkpoint (best effort).
		ignore_user_abort(true);
	}

	/**
	 * mode (skip|safe|overwrite; overwrite needs confirm), publish, keep_date.
	 *
	 * @return array AIPT_Runner::opts() (mode '' for skip).
	 */
	public static function run_opts(): array {
		$mode = self::key('mode') ?: 'skip';
		if (!in_array($mode, array('skip', 'safe', 'overwrite'), true)) {
			self::fail('aipt_invalid_mode', __('Invalid translation mode.', 'ai-polylang-translator'));
		}
		if ($mode === 'overwrite' && empty($_POST['confirm'])) {
			self::fail('needs_confirm', __('A translation already exists — overwrite confirmation is required.', 'ai-polylang-translator'));
		}
		return AIPT_Runner::opts($mode === 'skip' ? '' : $mode, !empty($_POST['publish']), !empty($_POST['keep_date']));
	}

	// Source language (the default language when empty).
	public static function from(): string {
		$from = self::key('from') ?: aipt_lang()->default_language();
		if (!isset(aipt_lang()->languages()[$from])) {
			self::fail('aipt_invalid_source', __('Invalid source language.', 'ai-polylang-translator'));
		}
		return $from;
	}

	/**
	 * @return string[] Target languages (targets[]), at least one, none equal to $from.
	 */
	public static function targets(string $from): array {
		$list = array_values(array_unique(self::keys('targets')));
		if (!$list) {
			self::fail('aipt_invalid_target', __('Select at least one target language.', 'ai-polylang-translator'));
		}
		foreach ($list as $target) {
			self::check_target($target, $from);
		}
		return $list;
	}

	public static function target(string $from): string {
		$lang = self::key('target');
		self::check_target($lang, $from);
		return $lang;
	}

	public static function valid_target(string $lang, string $from): bool {
		return isset(aipt_lang()->languages()[$lang]) && $lang !== $from;
	}

	public static function valid_taxonomy(string $taxonomy): bool {
		return taxonomy_exists($taxonomy) && aipt_lang()->is_translated_taxonomy($taxonomy);
	}

	public static function check_target(string $lang, string $from): void {
		if (!self::valid_target($lang, $from)) {
			self::fail('aipt_invalid_target', __('Invalid target language.', 'ai-polylang-translator'));
		}
	}

	public static function check_taxonomy(string $taxonomy): void {
		if (!self::valid_taxonomy($taxonomy)) {
			self::fail('aipt_invalid_taxonomy', __('This taxonomy is not translated by the multilingual plugin.', 'ai-polylang-translator'));
		}
		if (!current_user_can(get_taxonomy($taxonomy)->cap->edit_terms)) {
			self::fail('aipt_cannot_edit_terms', __('Insufficient permissions to create terms in this taxonomy.', 'ai-polylang-translator'));
		}
	}

	// An existing source post (not in the trash) of an enabled type the current user may
	// edit (AIPT_Runner::source_error()). Not being allowed to edit one source fails that
	// record only (not the guard's aipt_forbidden).
	public static function source_post(): int {
		$post_id = absint($_POST['post_id'] ?? 0);
		$error   = AIPT_Runner::source_error($post_id);
		if ($error) {
			self::send($error);
		}
		return $post_id;
	}

	// The durable job of job_id, started by the current user. A job already finished and
	// deleted whose tombstone (post_id, lang) still names it ends the request with its outcome.
	public static function load_job(): array {
		$job_id = self::job_id();
		$job    = AIPT_Job_Store::is_durable($job_id) ? AIPT_Job::get($job_id) : null;
		if (!$job) {
			$done = AIPT_Bulk_Jobs::tombstone(absint($_POST['post_id'] ?? 0), self::key('target'), $job_id);
			if ($done) {
				wp_send_json_success($done);
			}
			self::fail('aipt_job_expired', __('Translation job not found or expired. Start over.', 'ai-polylang-translator'));
		}
		if (!AIPT_Pipeline::job_owned($job)) {
			self::fail('aipt_job_forbidden', __('This translation job was started by another user.', 'ai-polylang-translator'));
		}
		return array($job_id, $job);
	}

	public static function job_id(): string {
		return sanitize_text_field(wp_unslash($_POST['job_id'] ?? ''));
	}

	public static function key(string $name): string {
		return sanitize_key(wp_unslash((string) ($_POST[$name] ?? '')));
	}

	public static function keys(string $name): array {
		$values = isset($_POST[$name]) ? (array) wp_unslash($_POST[$name]) : array();
		return array_values(array_filter(array_map(static fn($value): string => sanitize_key((string) $value), $values), 'strlen'));
	}

	public static function json(string $name): array {
		$value = json_decode(wp_unslash((string) ($_POST[$name] ?? '')), true);
		return is_array($value) ? $value : array();
	}

	public static function send($result): void {
		if (!is_wp_error($result)) {
			wp_send_json_success($result);
		}
		$data = array(
			'code'    => $result->get_error_code(),
			'message' => $result->get_error_message(),
		);
		$usage = $result->get_error_data('aipt_usage');
		if (is_array($usage)) {
			$data['aipt_usage'] = $usage;
		}
		$stage = $result->get_error_data('aipt_stage');
		if (is_string($stage)) {
			$data['stage'] = $stage;
		}
		if (in_array($data['code'], self::RETRY_CODES, true)) {
			$data['retry'] = true;
		}
		wp_send_json_error($data);
	}

	public static function fail(string $code, string $message): void {
		self::send(new WP_Error($code, $message));
	}
}
