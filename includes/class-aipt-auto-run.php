<?php
// One run of an automatic-translation event (AIPT_Auto::run() calls it as the event's
// user and restores the previous user). Post-level checks first; then each selected
// language that is pending in the status (all of them on the first attempt): its
// missing terms, then the post. Every outcome is logged to error_log and recorded in
// AIPT_Auto_Status; a hard error fails that language and the next one runs, unless it
// would repeat for every language (stops_post(): API key rejected or missing, the
// multilingual backend's API or link failing): then the remaining languages are skipped
// as not attempted and the post fails (retryable). Transient errors
// (AIPT_Gateway::is_transient_error(), a busy pair) leave the language pending and
// reschedule the event for those languages, once per AIPT_Auto::BACKOFF entry. Status
// writes carry the run token of AIPT_Auto_Status::running(): a run whose status was taken
// over (a new schedule or run) stops writing and stops.

if (!defined('ABSPATH')) {
	exit;
}

class AIPT_Auto_Run {

	public static function run(int $post_id, int $user_id): void {
		$post     = get_post($post_id);
		$settings = AIPT_Settings::get();
		$from     = aipt_lang()->default_language();

		if (!$post) {
			AIPT_Auto::log($post_id, '', 'skipped: the post no longer exists');
			return;
		}
		if ($post->post_status !== 'publish') {
			AIPT_Auto::fail($post_id, 'skipped: the post is not published', __('The post is not published.', 'ai-polylang-translator'));
			return;
		}
		if ((string) aipt_lang()->post_language($post_id) !== $from) {
			AIPT_Auto::fail($post_id, 'skipped: the post is no longer in the default language', __('The post is no longer in the default language.', 'ai-polylang-translator'));
			return;
		}
		if (empty($settings['auto_translate'])) {
			AIPT_Auto::fail($post_id, 'skipped: automatic translation is turned off', __('Automatic translation is turned off in Settings.', 'ai-polylang-translator'));
			return;
		}
		if (AIPT_Settings::api_key() === '') {
			AIPT_Auto::fail($post_id, 'skipped: API key is not set', __('API key is not set. Enter it on the settings page.', 'ai-polylang-translator'));
			return;
		}

		// The writer checks capabilities against the current user.
		wp_set_current_user($user_id);
		if (!$user_id || !current_user_can('edit_post', $post_id)) {
			AIPT_Auto::fail(
				$post_id,
				sprintf('skipped: user #%d cannot edit the post', $user_id),
				/* translators: %d: user ID */
				sprintf(__('User #%d cannot edit the post.', 'ai-polylang-translator'), $user_id)
			);
			return;
		}

		$status    = AIPT_Auto_Status::get($post_id);
		$attempt   = $status ? $status['attempt'] : 0;
		$languages = AIPT_Settings_Auto::languages($settings);
		$run       = AIPT_Auto_Status::running($post_id, $user_id, $languages);

		// A language that already has a real translation is recorded as done (existing).
		$terms   = AIPT_Record::translated_terms($post_id, $post->post_type);
		$retry   = false;
		$stopped = '';
		foreach ($languages as $lang) {
			// Finished in an earlier attempt of this schedule (or done before a manual retry).
			$row = $status['langs'][$lang] ?? null;
			if ($row !== null && $row['state'] !== 'pending') {
				continue;
			}
			if ($stopped !== '') {
				AIPT_Auto::log($post_id, $lang, 'skipped: not attempted after the error in ' . $stopped);
				/* translators: %s: language code */
				AIPT_Auto_Status::lang($post_id, $run, $lang, 'skipped', sprintf(__('Not attempted after the error in %s.', 'ai-polylang-translator'), $stopped));
				continue;
			}
			if (!AIPT_Auto_Status::lang($post_id, $run, $lang, 'running')) {
				AIPT_Auto::log($post_id, '', 'stopped: the status was taken over by another schedule or run');
				return;
			}
			$outcome = self::translate_into($post, $from, $lang, $terms);
			AIPT_Auto_Status::lang($post_id, $run, $lang, $outcome['state'], $outcome['message'], $outcome['existing']);
			$retry   = $retry || $outcome['state'] === 'pending';
			$stopped = $outcome['stop'] ? $lang : '';
		}

		if ($stopped === '' && $retry && $attempt < count(AIPT_Auto::BACKOFF)) {
			$delay = AIPT_Auto::BACKOFF[$attempt];
			// The pending rows are kept with their messages; finished rows stay as they are.
			if (AIPT_Auto::schedule($post_id, $user_id, $delay, $attempt + 1)) {
				AIPT_Auto::log($post_id, '', sprintf('retry %d scheduled in %d s', $attempt + 1, $delay));
			}
			return;
		}
		AIPT_Auto_Status::finish($post_id, $run);
	}

	/**
	 * Errors that would repeat for every language, so the remaining ones are not tried:
	 * the API key rejected (aipt_http_401, aipt_http_403, also a term row's gateway code)
	 * or missing (aipt_no_key), the writer's sync suspension failing (aipt_sync_missing),
	 * the adapter's own API errors (aipt_<adapter name>_*, e.g. a WPML API that is
	 * missing), the translation link failing (aipt_link_failed) and a job of another
	 * backend (aipt_job_backend). Errors about one reply (aipt_bad_json, aipt_incomplete,
	 * other 4xx …) only fail their language.
	 */
	public static function stops_post(string $code): bool {
		if (in_array($code, array('aipt_http_401', 'aipt_http_403', 'aipt_no_key', 'aipt_job_backend'), true)) {
			return true;
		}
		foreach (array('aipt_sync_', 'aipt_link_', 'aipt_' . aipt_lang()->name() . '_') as $prefix) {
			if (str_starts_with($code, $prefix)) {
				return true;
			}
		}
		return false;
	}

	/**
	 * Post-level checks first, so a record that cannot be translated costs nothing; then
	 * terms, then the post.
	 *
	 * @param array<string, int[]> $terms AIPT_Record::translated_terms() of the source.
	 * @return array{state: string, message: string, existing: bool, stop: bool}
	 *         The language's AIPT_Auto_Status row; pending = transient, retried;
	 *         existing = a real translation already existed (left alone); stop = the
	 *         error stops the post (stops_post()).
	 */
	private static function translate_into(WP_Post $post, string $from, string $lang, array $terms): array {
		if (function_exists('set_time_limit') && !(defined('WP_CLI') && WP_CLI)) {
			set_time_limit(900);
		}

		$check = AIPT_Record::check_source($post->ID, $lang, $from, true);
		if ($check['status'] === 'skipped_existing') {
			return self::outcome('done', '', true);
		}
		if ($check['status'] !== '') {
			AIPT_Auto::log($post->ID, $lang, $check['status'] . ': ' . $check['message']);
			return self::outcome($check['status'] === 'skipped_pending' ? 'skipped' : 'failed', $check['message']);
		}
		$parent = AIPT_Record::missing_parent($post, $lang);
		if ($parent) {
			AIPT_Auto::log($post->ID, $lang, sprintf('skipped_parent_missing: parent #%d has no %s translation', $parent, $lang));
			/* translators: %1$d: parent post ID, %2$s: language code */
			return self::outcome('skipped', sprintf(__('The parent #%1$d has no %2$s translation yet.', 'ai-polylang-translator'), $parent, $lang));
		}

		$missing = AIPT_Record::missing_terms($terms, $lang);
		foreach ($missing as $taxonomy => $term_ids) {
			$failure = self::translate_terms($taxonomy, $term_ids, $from, $lang);
			if ($failure) {
				AIPT_Auto::log($post->ID, $lang, sprintf('%s: term %s #%d: %s', $failure['status'], $taxonomy, $failure['term']->term_id, $failure['message']));
				$message = sprintf('%s #%d: %s', $taxonomy, $failure['term']->term_id, $failure['message']);
				if ($failure['status'] !== 'error') {
					return self::outcome('skipped', $message);
				}
				$code = (string) ($failure['code'] ?? '');
				return self::outcome(AIPT_Gateway::is_transient_error($code) ? 'pending' : 'failed', $message, false, self::stops_post($code));
			}
		}
		$missing = $missing ? AIPT_Record::missing_terms($terms, $lang) : array();
		if ($missing) {
			$described = AIPT_Record::describe_terms($missing);
			AIPT_Auto::log($post->ID, $lang, 'skipped_missing_terms: untranslated terms: ' . $described);
			/* translators: %s: list of taxonomy:term ID pairs */
			return self::outcome('skipped', sprintf(__('Terms without a translation: %s', 'ai-polylang-translator'), $described));
		}

		$result = AIPT_Pipeline::translate_post($post->ID, $lang, array(
			'mode'          => 'overwrite',
			'skip_existing' => true,
			'holder'        => 'auto',
			'post_status'   => 'publish',
			'keep_date'     => true,
		));
		if (is_wp_error($result)) {
			$code    = (string) $result->get_error_code();
			$skipped = AIPT_Record::skip_status($result);
			$message = $result->get_error_message();
			AIPT_Auto::log($post->ID, $lang, ($skipped ?? $code) . ': ' . $message);
			// A busy pair is transient too.
			if (AIPT_Gateway::is_transient_error($code) || $skipped === 'skipped_locked') {
				return self::outcome('pending', $message);
			}
			return self::outcome($skipped !== null ? 'skipped' : 'failed', $message, false, self::stops_post($code));
		}
		if ($result['warning'] !== '') {
			AIPT_Auto::log($post->ID, $lang, sprintf('translation #%d written with a warning: %s', $result['target_id'], $result['warning']));
		}
		// skipped_existing: found under the pair lock.
		return self::outcome('done', (string) $result['warning'], $result['status'] === 'skipped_existing');
	}

	/**
	 * Translates the terms and their ancestors, parents first.
	 *
	 * @return array|null The first row that failed ('error', with the gateway's 'code')
	 *                    or was not allowed ('skipped_no_permission'), else null.
	 */
	private static function translate_terms(string $taxonomy, array $term_ids, string $from, string $lang): ?array {
		$failure = null;
		$terms   = AIPT_Terms::with_ancestors($taxonomy, $term_ids, $from);
		AIPT_Terms::translate($taxonomy, $terms, $from, $lang, null, static function (array $row) use (&$failure): bool {
			if ($row['status'] !== 'error' && $row['status'] !== 'skipped_no_permission') {
				return true;
			}
			$failure = $row;
			return false;
		});
		return $failure;
	}

	private static function outcome(string $state, string $message = '', bool $existing = false, bool $stop = false): array {
		return array('state' => $state, 'message' => $message, 'existing' => $existing, 'stop' => $stop);
	}
}
