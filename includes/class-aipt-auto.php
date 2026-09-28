<?php
// Auto-translation on first publish. A post of an enabled type in the default language
// that becomes 'publish' for the first time gets one single wp-cron event (a short
// delay after the save); the event translates the post into every selected language
// that has no translation yet — its missing terms first — published with the source
// date. Deliberately simple: no retries, no admin notices, existing translations are
// never updated. Failures go to the PHP error log and stop the post.
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
// - If the Writer throws after inserting a post, that post gets no marker.

if (!defined('ABSPATH')) {
	exit;
}

class AIPT_Auto {

	const HOOK      = 'aipt_auto_translate';
	// Set once the post was scheduled, and on every post the plugin writes itself.
	const MARKER    = '_aipt_auto_scheduled';
	// Who scheduled a future post: wp_publish_post() later runs in cron without a user.
	const USER_META = '_aipt_auto_user';
	// The block editor saves legacy metaboxes (ACF, Yoast, Polylang) in a second request
	// after the REST save; the event must not race it.
	const DELAY     = 2 * MINUTE_IN_SECONDS;

	public static function register(): void {
		// Runs after save_post and after REST terms/meta, so Polylang has set the
		// language (block editor, classic editor, and wp_publish_post() for future posts).
		add_action('wp_after_insert_post', array(__CLASS__, 'on_after_insert_post'), 10, 4);
		add_action(self::HOOK, array(__CLASS__, 'run'), 10, 2);
		add_filter('pll_copy_post_metas', array(__CLASS__, 'exclude_meta'));
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
		$post_id  = (int) $post_id;
		$previous = get_current_user_id();
		try {
			self::translate($post_id, (int) $user_id);
		} catch (Throwable $e) {
			self::log($post_id, '', get_class($e) . ': ' . $e->getMessage());
		} finally {
			// wp-cron runs every due event in one process: other jobs must not inherit
			// this user (and its unfiltered_html, which turns kses off).
			wp_set_current_user($previous);
		}
	}

	// Polylang's custom-field sync must not copy the markers to other languages: a
	// marker copied onto an unpublished source would block its auto-translation.
	public static function exclude_meta($keys) {
		return is_array($keys) ? array_values(array_diff($keys, array(self::MARKER, self::USER_META))) : $keys;
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
		if ((defined('WP_IMPORTING') && WP_IMPORTING) || AIPT_Pipeline::is_writing() || !AIPT_Settings::auto_enabled()) {
			return;
		}
		$settings = AIPT_Settings::get();
		$post_id  = (int) $post->ID;
		if (!in_array($post->post_type, $settings['post_types'], true)
			|| (string) pll_get_post_language($post_id) !== (string) pll_default_language()
			|| metadata_exists('post', $post_id, self::MARKER)) {
			return;
		}

		if ($post->post_status === 'future') {
			if (current_user_can('edit_post', $post_id)) {
				update_post_meta($post_id, self::USER_META, get_current_user_id());
			}
			return;
		}

		// Unique add: a concurrent save that got here too loses.
		if (!self::missing_languages($post_id, $settings) || !add_post_meta($post_id, self::MARKER, time(), true)) {
			return;
		}
		$scheduled = wp_schedule_single_event(time() + self::DELAY, self::HOOK, array($post_id, self::user_for($post)), true);
		if (is_wp_error($scheduled)) {
			self::log($post_id, '', 'scheduling failed: ' . $scheduled->get_error_message());
		}
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

	private static function translate(int $post_id, int $user_id): void {
		$post     = get_post($post_id);
		$settings = AIPT_Settings::get();
		$from     = (string) pll_default_language();

		if (!$post || $post->post_status !== 'publish') {
			self::log($post_id, '', 'skipped: the post no longer exists or is not published');
			return;
		}
		if ((string) pll_get_post_language($post_id) !== $from) {
			self::log($post_id, '', 'skipped: the post is no longer in the default language');
			return;
		}
		if (empty($settings['auto_translate'])) {
			self::log($post_id, '', 'skipped: automatic translation is turned off');
			return;
		}
		if (AIPT_Settings::api_key() === '') {
			self::log($post_id, '', 'skipped: API key is not set');
			return;
		}

		// The writer checks capabilities against the current user.
		wp_set_current_user($user_id);
		if (!$user_id || !current_user_can('edit_post', $post_id)) {
			self::log($post_id, '', sprintf('skipped: user #%d cannot edit the post', $user_id));
			return;
		}

		$terms = AIPT_Record::translated_terms($post_id, $post->post_type);
		foreach (self::missing_languages($post_id, $settings) as $lang) {
			if (!self::translate_into($post, $from, $lang, $terms)) {
				return;
			}
		}
	}

	/**
	 * Post-level checks first, so a record that cannot be translated costs nothing; then
	 * terms, then the post.
	 *
	 * @param array<string, int[]> $terms AIPT_Record::translated_terms() of the source.
	 * @return bool False stops processing the post.
	 */
	private static function translate_into(WP_Post $post, string $from, string $lang, array $terms): bool {
		if (function_exists('set_time_limit') && !(defined('WP_CLI') && WP_CLI)) {
			set_time_limit(900);
		}

		$check = AIPT_Record::check_source($post->ID, $lang, $from, true);
		if ($check['status'] === 'skipped_existing') {
			return true;
		}
		if ($check['status'] !== '') {
			self::log($post->ID, $lang, $check['status'] . ': ' . $check['message']);
			return false;
		}
		$parent = AIPT_Record::missing_parent($post, $lang);
		if ($parent) {
			self::log($post->ID, $lang, sprintf('skipped_parent_missing: parent #%d has no %s translation', $parent, $lang));
			return true;
		}

		$missing = AIPT_Record::missing_terms($terms, $lang);
		foreach ($missing as $taxonomy => $term_ids) {
			$failure = self::translate_terms($taxonomy, $term_ids, $from, $lang);
			if ($failure) {
				self::log($post->ID, $lang, sprintf('%s: term %s #%d: %s', $failure['status'], $taxonomy, $failure['term']->term_id, $failure['message']));
				return $failure['status'] !== 'error';
			}
		}
		$missing = $missing ? AIPT_Record::missing_terms($terms, $lang) : array();
		if ($missing) {
			self::log($post->ID, $lang, 'skipped_missing_terms: untranslated terms: ' . AIPT_Record::describe_terms($missing));
			return true;
		}

		$result = AIPT_Pipeline::translate_post($post->ID, $lang, array(
			'mode'          => 'overwrite',
			'skip_existing' => true,
			'holder'        => 'auto',
			'post_status'   => 'publish',
			'keep_date'     => true,
		));
		if (is_wp_error($result)) {
			$locked = $result->get_error_code() === 'aipt_pair_locked';
			self::log($post->ID, $lang, ($locked ? 'skipped_locked: ' : $result->get_error_code() . ': ') . $result->get_error_message());
			return $locked;
		}
		if ($result['warning'] !== '') {
			self::log($post->ID, $lang, sprintf('translation #%d written with a warning: %s', $result['target_id'], $result['warning']));
		}
		return true;
	}

	/**
	 * Translates the terms and their ancestors, parents first.
	 *
	 * @return array|null The first row that failed ('error') or was not allowed
	 *                    ('skipped_no_permission'), else null.
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

	private static function missing_languages(int $post_id, array $settings): array {
		return array_values(array_filter(
			AIPT_Settings_Auto::languages($settings),
			static fn(string $lang): bool => !pll_get_post($post_id, $lang)
		));
	}

	private static function log(int $post_id, string $lang, string $message): void {
		error_log(sprintf('AIPT auto-translate: post #%d%s: %s', $post_id, $lang !== '' ? ' -> ' . $lang : '', $message));
	}
}
