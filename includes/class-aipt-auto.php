<?php
// Auto-translation on publish: scheduling, hooks and the wp-cron entry point (the run
// itself is AIPT_Auto_Run). A post of an enabled type in the default language that
// becomes 'publish' gets one single wp-cron event; the event translates the post into
// every selected language that has no real translation yet — its missing terms first —
// published with the source date. Existing translations are never updated.
//
// Status: AIPT_Auto_Status keeps one post meta on the source (scheduled / running /
// done / failed, plus a row per language) that the AI Translation box shows, with a
// Retry button when the post is retryable. Every failure and skip also goes to the PHP
// error log (successes are not logged).
//
// Retries: transient errors (connection, timeout, HTTP 408/429/5xx, a pair another
// process is writing) leave the language pending and reschedule the event for those
// languages, once per BACKOFF entry (5, then 30 minutes). A non-transient error
// fails that language and the next language runs. A post whose last run failed, whose
// event was lost (gone from the cron queue: the plugin was deactivated with it queued,
// or a fatal error before the run started) or whose run stalled (no progress for an
// hour) is scheduled again when it is re-published (draft → publish) or from the box's
// Retry button; only missing languages are translated then. An overdue event that is
// still queued (WP-Cron has not run it: DISABLE_WP_CRON without a server cron, no
// traffic) is only reported: a retry would queue a second event.
//
// Delay: the block editor saves legacy metaboxes (ACF, Yoast, the multilingual plugin's)
// in a second request after its REST save, so a REST save waits DELAY (2 minutes) for it.
// The classic editor, Quick Edit, WP-CLI, code calling wp_update_post() and scheduled
// posts published by cron save everything in one request: SHORT_DELAY (10 seconds).
//
// The marker (MARKER) is set when a post is scheduled and on every post the plugin writes
// (AIPT_Pipeline::write() tags it right after insert/update): a marked post is scheduled
// again only while its status is retryable, so posts the plugin wrote since 1.7 (no
// status, the job meta AIPT_Pipeline::JOB_META) are never auto-translated. Exception,
// legacy_retryable(): a marked post without a status and without the job meta that still
// lacks a selected language was marked by a version before 1.8.0 (which kept no status;
// also a default-language post the plugin itself wrote before 1.7). It is scheduled again
// on re-publish and from the box's button ("Translate missing languages automatically"),
// and the run translates only languages without a real translation (skip_existing),
// which also covers a language selected later.
//
// Known limits, accepted on purpose:
// - The language is checked on the save that publishes. The block editor saves the
//   Polylang metabox in a later request, so switching the language *to* the default
//   one there during the first publish is not detected (a switch away from it is: the
//   event re-checks the language).
// - An old post that never got the marker (published before 1.5.0, or while the
//   setting was off) is scheduled when it is re-published; only missing languages
//   are translated.
// - No lock around term creation: two cron runners translating posts that share a new
//   term at the same moment may both create it.
// - The box shows the status as of the page load; reload the post to see a run's result.
// - Two users re-publishing a retryable post at the same moment can queue two events (the
//   unique marker add guards only the first schedule); the pair lock keeps writes safe.
// - A default-language post the plugin itself wrote before 1.7 (marker, no job meta)
//   counts as legacy and is auto-translated on re-publish into the languages it lacks.

if (!defined('ABSPATH')) {
	exit;
}

class AIPT_Auto {

	const HOOK        = 'aipt_auto_translate';
	// Set once the post was scheduled, and on every post the plugin writes itself.
	const MARKER      = '_aipt_auto_scheduled';
	// Who scheduled a future post: wp_publish_post() later runs in cron without a user.
	const USER_META   = '_aipt_auto_user';
	// Block editor saves only (see the header).
	const DELAY       = 2 * MINUTE_IN_SECONDS;
	const SHORT_DELAY = 10;
	// Delay of automatic retry n + 1 (index = attempts so far); one retry per entry.
	const BACKOFF     = array(5 * MINUTE_IN_SECONDS, 30 * MINUTE_IN_SECONDS);

	public static function register(): void {
		// Runs after save_post and after REST terms/meta, so the multilingual plugin has
		// set the language (block editor, classic editor, and wp_publish_post() for
		// future posts). The marker meta filter is the adapter's (register_hooks()).
		add_action('wp_after_insert_post', array(__CLASS__, 'on_after_insert_post'), 10, 4);
		add_action(self::HOOK, array(__CLASS__, 'run'), 10, 2);
	}

	/**
	 * Schedule, never translate: the save request must stay fast.
	 *
	 * @param int          $post_id
	 * @param WP_Post      $post
	 * @param bool         $update
	 * @param WP_Post|null $post_before
	 */
	public static function on_after_insert_post($post_id, $post, $update, $post_before): void {
		try {
			self::maybe_schedule($post, $post_before);
		} catch (Throwable $e) {
			self::log((int) $post_id, '', 'scheduling failed: ' . get_class($e) . ': ' . $e->getMessage());
		}
	}

	/**
	 * wp-cron handler.
	 */
	public static function run($post_id, $user_id = 0): void {
		$post_id = (int) $post_id;
		// Parked: events queued before the switch was turned off are dropped.
		if (!AIPT_Settings::enabled()) {
			$log = 'skipped: AI Translator is disabled in Settings';
			if (AIPT_Auto_Status::get($post_id) === null) {
				self::log($post_id, '', $log);
				return;
			}
			self::fail($post_id, $log, __('AI Translator is disabled in Settings.', 'ai-polylang-translator'));
			return;
		}
		$previous = get_current_user_id();
		try {
			AIPT_Auto_Run::run($post_id, (int) $user_id);
		} catch (Throwable $e) {
			$error = get_class($e) . ': ' . $e->getMessage();
			if (get_post($post_id)) {
				self::fail($post_id, $error, $error);
			} else {
				self::log($post_id, '', $error);
			}
		} finally {
			// wp-cron runs every due event in one process: other jobs must not inherit
			// this user (and its unfiltered_html, which turns kses off).
			wp_set_current_user($previous);
		}
	}

	/**
	 * Schedules the event and records the status (the marker is added when missing).
	 *
	 * @param int $attempt 0 for a new schedule (every selected language pending), else the
	 *                     automatic retry number (the status keeps its language rows).
	 */
	public static function schedule(int $post_id, int $user_id, int $delay, int $attempt = 0): bool {
		// Unique add: the value is never read (only whether the marker exists).
		add_post_meta($post_id, self::MARKER, time(), true);
		$when = time() + $delay;
		$args = array($post_id, $user_id);

		$scheduled = wp_schedule_single_event($when, self::HOOK, $args, true);
		// The same event within 10 minutes already exists (a concurrent save): use it.
		if (is_wp_error($scheduled) && $scheduled->get_error_code() === 'duplicate_event') {
			$existing  = wp_next_scheduled(self::HOOK, $args);
			$scheduled = $existing ? true : $scheduled;
			$when      = $existing ?: $when;
		}
		if ($scheduled !== true) {
			$reason = is_wp_error($scheduled) ? $scheduled->get_error_message() : 'wp_schedule_single_event() returned false';
			/* translators: %s: error message */
			self::fail($post_id, 'scheduling failed: ' . $reason, sprintf(__('could not schedule the WP-Cron event: %s', 'ai-polylang-translator'), $reason));
			return false;
		}
		AIPT_Auto_Status::scheduled($post_id, (int) $when, $attempt, $user_id);
		return true;
	}

	/**
	 * The AI Translation box's Retry button.
	 *
	 * @return true|WP_Error
	 */
	public static function retry(int $post_id, int $user_id) {
		$post    = get_post($post_id);
		$blocker = $post ? self::retry_blocker($post, AIPT_Auto_Status::get($post_id)) : self::ineligible(null);
		if ($blocker) {
			return $blocker;
		}
		if (!self::schedule($post_id, $user_id, self::SHORT_DELAY)) {
			return new WP_Error('aipt_auto_schedule', __('Could not schedule the WP-Cron event.', 'ai-polylang-translator'));
		}
		return true;
	}

	/**
	 * Why Retry may not schedule the post now, else null: ineligible() first (cheap
	 * checks), then aipt_auto_not_retryable unless the status is retryable
	 * (AIPT_Auto_Status::retryable()) or, without a status, the post is a legacy one.
	 *
	 * @param array|null $status    AIPT_Auto_Status::get().
	 * @param bool|null  $retryable AIPT_Auto_Status::retryable() of $status when the caller
	 *                              has it (AIPT_Auto_Status::lines()).
	 */
	public static function retry_blocker(WP_Post $post, ?array $status, ?bool $retryable = null): ?WP_Error {
		$ineligible = self::ineligible($post);
		if ($ineligible) {
			return $ineligible;
		}
		if ($status !== null ? ($retryable ?? AIPT_Auto_Status::retryable($status)) : self::legacy_retryable($post->ID, $status)) {
			return null;
		}
		return new WP_Error('aipt_auto_not_retryable', __('Automatic translation of this post is scheduled, running or done. Reload the post to see its status.', 'ai-polylang-translator'));
	}

	// Why the post cannot be translated automatically now (no post, setting off; not
	// published, not in the default language, or a type that is not enabled), else null.
	public static function ineligible(?WP_Post $post): ?WP_Error {
		if ($post && !AIPT_Settings::auto_enabled()) {
			return new WP_Error('aipt_auto_off', __('Automatic translation is turned off in Settings.', 'ai-polylang-translator'));
		}
		if (!$post || $post->post_status !== 'publish'
			|| !in_array($post->post_type, AIPT_Settings::get()['post_types'], true)
			|| (string) aipt_lang()->post_language($post->ID) !== aipt_lang()->default_language()) {
			return new WP_Error('aipt_auto_ineligible', __('Only published posts of an enabled post type in the default language are translated automatically.', 'ai-polylang-translator'));
		}
		return null;
	}

	// Log, then fail the status (post-level).
	public static function fail(int $post_id, string $log, string $message): void {
		self::log($post_id, '', $log);
		AIPT_Auto_Status::fail($post_id, $message);
	}

	// A post left untranslated by a version before 1.8.0: marked, no status, not written by
	// the plugin (no job meta), and a selected language still lacks a real translation.
	private static function legacy_retryable(int $post_id, ?array $status): bool {
		return self::legacy_candidate($post_id, $status)
			&& metadata_exists('post', $post_id, self::MARKER)
			&& self::has_missing_language($post_id, AIPT_Settings::get());
	}

	// The cheap part of legacy_retryable() without the marker check.
	private static function legacy_candidate(int $post_id, ?array $status): bool {
		return $status === null && !metadata_exists('post', $post_id, AIPT_Pipeline::JOB_META);
	}

	// The multilingual plugin's custom-field sync must not copy the plugin's own post meta
	// (every key starting with _aipt_ is private to one post: the marker, the auto status,
	// the job id) to other languages: a marker copied onto an unpublished source would
	// block its auto-translation, and a copied job id would point at the wrong post.
	// Hooked by the adapter (AIPT_Lang::register_hooks()).
	public static function exclude_meta($keys) {
		if (!is_array($keys)) {
			return $keys;
		}
		return array_values(array_filter($keys, static fn($key): bool => !str_starts_with((string) $key, '_aipt_')));
	}

	public static function log(int $post_id, string $lang, string $message): void {
		error_log(sprintf('AIPT auto-translate: post #%d%s: %s', $post_id, $lang !== '' ? ' -> ' . $lang : '', $message));
	}

	// Runs on every save site-wide: cheapest checks first.
	private static function maybe_schedule($post, $post_before): void {
		if (!$post instanceof WP_Post || ($post->post_status !== 'publish' && $post->post_status !== 'future')) {
			return;
		}
		// Only the transition into the status, not later saves in it.
		if ($post_before instanceof WP_Post && $post_before->post_status === $post->post_status) {
			return;
		}
		if ((defined('WP_IMPORTING') && WP_IMPORTING) || AIPT_Pipeline::is_writing()
			|| !AIPT_Settings::auto_enabled() || !AIPT_Settings::enabled()) {
			return;
		}
		$settings = AIPT_Settings::get();
		$post_id  = (int) $post->ID;
		if (!in_array($post->post_type, $settings['post_types'], true)
			|| (string) aipt_lang()->post_language($post_id) !== aipt_lang()->default_language()) {
			return;
		}
		// A marked post is scheduled again only after a failed, lost or stalled run, or as
		// a legacy post (legacy_retryable(); its has_missing_language() check runs below).
		$marked = metadata_exists('post', $post_id, self::MARKER);
		if ($marked) {
			$status = AIPT_Auto_Status::get($post_id);
			if (!AIPT_Auto_Status::retryable($status) && !self::legacy_candidate($post_id, $status)) {
				return;
			}
		}

		if ($post->post_status === 'future') {
			if (current_user_can('edit_post', $post_id)) {
				update_post_meta($post_id, self::USER_META, get_current_user_id());
			}
			return;
		}

		// Unique add: a concurrent first save that got here too loses.
		if (!self::has_missing_language($post_id, $settings)
			|| (!$marked && !add_post_meta($post_id, self::MARKER, time(), true))) {
			return;
		}
		self::schedule($post_id, self::user_for($post), self::delay());
	}

	// See the header.
	private static function delay(): int {
		$rest = (defined('REST_REQUEST') && REST_REQUEST) || wp_is_json_request();
		return $rest ? self::DELAY : self::SHORT_DELAY;
	}

	// The current user if they can edit the post, else whoever scheduled it, else the author.
	private static function user_for(WP_Post $post): int {
		$scheduler = (int) get_post_meta($post->ID, self::USER_META, true);
		if ($scheduler) {
			delete_post_meta($post->ID, self::USER_META);
		}
		if (current_user_can('edit_post', $post->ID)) {
			return get_current_user_id();
		}
		return $scheduler ?: (int) $post->post_author;
	}

	// Scheduling only: whether a selected language has no real translation.
	private static function has_missing_language(int $post_id, array $settings): bool {
		return (bool) self::missing_languages($post_id, $settings, 1);
	}

	/**
	 * Selected languages without a real translation (none, a duplicate that is translated
	 * over, or pending work that the event skips and logs).
	 *
	 * @param int $limit Stop after this many (0: all).
	 * @return string[]
	 */
	public static function missing_languages(int $post_id, array $settings, int $limit = 0): array {
		$missing = array();
		foreach (AIPT_Settings_Auto::languages($settings) as $lang) {
			if (aipt_lang()->translation_state($post_id, $lang)['state'] !== 'translated') {
				$missing[] = (string) $lang;
				if (count($missing) === $limit) {
					break;
				}
			}
		}
		return $missing;
	}
}
