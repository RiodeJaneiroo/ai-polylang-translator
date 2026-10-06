<?php
// Bulk-translation policy shared by WP-CLI and the bulk admin page: pre-checks of one
// record (source post × target language), the status of a new translation, the dry-run
// estimate, and the re-check of a stored job right before it is written. Outcomes are
// AIPT_Record::outcome() arrays; their messages are English (CLI log format).

if (!defined('ABSPATH')) {
	exit;
}

class AIPT_Runner {

	// estimate(): [chars, has items] of the last extraction outside safe mode, keyed by post
	// ID (one entry: records come post-major, so only the previous post is ever reused).
	private static $extract_memo = array();

	/**
	 * The options precheck(), new_post_status() and estimate() take.
	 *
	 * @param string $mode '' (skip existing translations) | 'safe' | 'overwrite'.
	 * @return array{mode: string, skip_existing: bool, post_status: string, keep_date: bool}
	 */
	public static function opts(string $mode, bool $publish, bool $keep_date): array {
		return array(
			'mode'          => $mode,
			'skip_existing' => $mode === '',
			'post_status'   => $publish ? 'publish' : 'draft',
			'keep_date'     => $keep_date,
		);
	}

	/**
	 * Status '' means the record should be translated; target_id holds the existing
	 * translation and mode the mode the write will use (a duplicate is always overwritten).
	 *
	 * @param array                    $opts mode ('' | 'safe' | 'overwrite'), skip_existing (bool).
	 * @param AIPT_Runner_Context|null $ctx  Estimate only: posts and terms planned earlier
	 *                                       in the run count as translated.
	 */
	public static function precheck(int $post_id, string $target, string $from, array $opts, ?AIPT_Runner_Context $ctx = null): array {
		$check = AIPT_Record::check_source($post_id, $target, $from, $opts['skip_existing'], $opts['mode']);
		if ($check['status'] !== '') {
			return $check;
		}
		$existing = (int) $check['target_id'];
		$mode     = $check['mode'];
		$post     = get_post($post_id);

		$parent = self::missing_parent($post, $target, $existing, $ctx);
		if ($parent) {
			return AIPT_Record::outcome('skipped_parent_missing', 0, 0.0, sprintf('parent #%d has no %s translation', $parent, $target));
		}
		$missing = self::missing_terms($post, $target, $existing, $mode, $ctx);
		if ($missing) {
			return AIPT_Record::outcome('skipped_missing_terms', $existing, 0.0, 'untranslated terms: ' . AIPT_Record::describe_terms($missing));
		}
		$outcome         = AIPT_Record::outcome('', $existing);
		$outcome['mode'] = $mode;
		return $outcome;
	}

	/**
	 * Status for a new translation. Publishing ($opts['post_status'] 'publish') never
	 * exposes a source that is not public: private stays private, scheduled stays
	 * scheduled only with its copied date (keep_date), anything else (draft, pending, …)
	 * becomes a draft.
	 */
	public static function new_post_status(int $post_id, array $opts): string {
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

	/**
	 * Dry run of one record: no API call, no write. A record that would create a
	 * translation is added to $ctx, so its children are not reported as missing a parent
	 * (AIPT_Record::missing_parent() checks post_parent for every type). Outside safe mode the extraction does not depend on the target, so
	 * its character count is extracted once per source post and request (memo) and reused
	 * for the post's other languages.
	 *
	 * @param array $opts  See precheck(), plus post_status and keep_date (new_post_status()).
	 * @param float $price USD per character.
	 * @return array AIPT_Record::outcome() (would_create|would_update|skipped_*|error),
	 *               plus chars for would_*.
	 */
	public static function estimate(int $post_id, string $target, string $from, array $opts, float $price, AIPT_Runner_Context $ctx): array {
		try {
			$check = self::precheck($post_id, $target, $from, $opts, $ctx);
			if ($check['status'] !== '') {
				return $check;
			}
			$mode     = $check['mode'];
			$existing = (int) $check['target_id'];
			$safe     = $existing && $mode === 'safe';
			if (!$safe && isset(self::$extract_memo[$post_id])) {
				list($chars, $has_items) = self::$extract_memo[$post_id];
			} else {
				$extract   = AIPT_Extractor::extract($post_id, $existing, $safe, $target);
				$has_items = (bool) $extract['items'];
				$chars     = 0;
				foreach ($extract['items'] as $item) {
					$chars += mb_strlen($item['text'], 'UTF-8');
				}
				if (!$safe) {
					self::$extract_memo = array($post_id => array($chars, $has_items));
				}
			}
			if (!$has_items && !$safe) {
				return AIPT_Record::outcome('error', 0, 0.0, 'no text to translate');
			}

			if (!$existing) {
				$ctx->plan_post($post_id, $target);
			}
			$outcome = AIPT_Record::outcome(
				$existing ? 'would_update' : 'would_create',
				$existing,
				$price * $chars,
				sprintf('%d chars (%s)', $chars, $existing ? $mode . ' mode' : 'new, status ' . self::new_post_status($post_id, $opts)),
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

	/**
	 * Re-check a stored job right before finalize writes it (the batches may have taken
	 * minutes or a restarted run): the source still exists, is unchanged and eligible,
	 * its parent and terms still have translations, and the current user may still edit
	 * the source and create, edit or publish the target. The post type is still enabled,
	 * the target language still exists, and a new translation would still get the status
	 * the job carries (new_post_status() recomputed; a scheduled source that has been
	 * published meanwhile is compatible). The target's own state is re-checked by
	 * AIPT_Writer. Snapshot checks are skipped for jobs prepared without the snapshot
	 * (source_lang, source_modified, requested_status).
	 *
	 * @return true|WP_Error 'aipt_source_changed' when the source was edited after prepare.
	 */
	public static function revalidate_before_write(array $job) {
		$post_id  = (int) ($job['post_id'] ?? 0);
		$target   = (string) ($job['target'] ?? '');
		$existing = (int) ($job['existing'] ?? 0);

		$source = self::source_error($post_id);
		if ($source) {
			return $source;
		}
		$post = get_post($post_id);
		if (!isset(aipt_lang()->languages()[$target])) {
			return new WP_Error('aipt_invalid_target', __('Invalid target language.', 'ai-polylang-translator'));
		}
		if (!$existing && isset($job['requested_status']) && !self::same_new_status($post_id, $job)) {
			return new WP_Error('aipt_source_status_changed', __('The status of the source post changed after the translation was prepared.', 'ai-polylang-translator'));
		}
		if (isset($job['source_lang']) && (string) aipt_lang()->post_language($post_id) !== $job['source_lang']) {
			return new WP_Error('aipt_source_language_changed', __('The language of the source post changed after the translation was prepared.', 'ai-polylang-translator'));
		}
		if (isset($job['source_modified']) && $post->post_modified_gmt !== $job['source_modified']) {
			return new WP_Error('aipt_source_changed', __('The source post was edited after the translation was prepared. Review it and translate it again.', 'ai-polylang-translator'));
		}

		if (self::missing_parent($post, $target, $existing, null)) {
			return new WP_Error('aipt_parent_missing', __('The parent of the source post has no translation into this language.', 'ai-polylang-translator'));
		}
		$missing = self::missing_terms($post, $target, $existing, (string) ($job['mode'] ?? 'overwrite'), null);
		if ($missing) {
			return new WP_Error('aipt_missing_terms', sprintf(
				/* translators: %s: list of taxonomy:term_id pairs, e.g. "category:12,post_tag:5" */
				__('Some terms of the source post have no translation into this language: %s', 'ai-polylang-translator'),
				AIPT_Record::describe_terms($missing)
			));
		}

		if ($existing) {
			if (!current_user_can('edit_post', $existing)) {
				return new WP_Error('aipt_cannot_edit_translation', __('Insufficient permissions to modify the existing translation.', 'ai-polylang-translator'));
			}
			return true;
		}
		if (!AIPT_Record::can_create_translation($post)) {
			return new WP_Error('aipt_cannot_create_translation', __('Insufficient permissions to create a translation.', 'ai-polylang-translator'));
		}
		// The status applies only to a new translation; private and scheduled ones need
		// the publish capability too, like publishing.
		if (in_array((string) ($job['post_status'] ?? 'draft'), array('publish', 'private', 'future'), true)
			&& !current_user_can(get_post_type_object($post->post_type)->cap->publish_posts)) {
			return new WP_Error('aipt_cannot_publish', __('Insufficient permissions to publish the translation.', 'ai-polylang-translator'));
		}
		return true;
	}

	/**
	 * The source post can be translated by the current user: it exists and is not in the
	 * trash (aipt_source_missing), the user may edit it (aipt_source_forbidden) and its
	 * type is enabled (aipt_post_type_disabled). Null when all hold.
	 */
	public static function source_error(int $post_id): ?WP_Error {
		$post = $post_id ? get_post($post_id) : null;
		if (!$post || $post->post_status === 'trash') {
			return new WP_Error('aipt_source_missing', __('The source post no longer exists or is in the trash.', 'ai-polylang-translator'));
		}
		if (!current_user_can('edit_post', $post_id)) {
			return new WP_Error('aipt_source_forbidden', __('Insufficient permissions.', 'ai-polylang-translator'));
		}
		if (!in_array($post->post_type, AIPT_Settings::get()['post_types'], true)) {
			return new WP_Error('aipt_post_type_disabled', __('This post type is not enabled in the translation settings.', 'ai-polylang-translator'));
		}
		return null;
	}

	// Whether new_post_status() still gives the job's post_status. A scheduled source that
	// went live keeps the job valid: 'future' with a past date is inserted as published.
	private static function same_new_status(int $post_id, array $job): bool {
		$now = self::new_post_status($post_id, array(
			'post_status' => (string) $job['requested_status'],
			'keep_date'   => !empty($job['keep_date']),
		));
		$prepared = (string) ($job['post_status'] ?? 'draft');
		return $now === $prepared || ($prepared === 'future' && $now === 'publish');
	}

	// Parent ID without a $target translation, else 0. Only a new translation is placed
	// under the parent's translation; an estimate counts parents it plans as translated.
	private static function missing_parent(WP_Post $post, string $target, int $existing, ?AIPT_Runner_Context $ctx): int {
		$parent = $existing ? 0 : AIPT_Record::missing_parent($post, $target);
		return $parent && !($ctx && $ctx->has_post($parent, $target)) ? $parent : 0;
	}

	/**
	 * @return array<string, int[]> Terms of the source the writer would drop, see
	 *                              AIPT_Record::missing_terms().
	 */
	private static function missing_terms(WP_Post $post, string $target, int $existing, string $mode, ?AIPT_Runner_Context $ctx): array {
		$terms = AIPT_Record::translated_terms($post->ID, $post->post_type);
		// Safe mode keeps the terms of a translation that already has some.
		if ($existing && $mode === 'safe') {
			$terms = array_diff_key($terms, AIPT_Record::translated_terms($existing, $post->post_type));
		}
		$missing = AIPT_Record::missing_terms($terms, $target);
		if (!$ctx) {
			return $missing;
		}
		foreach ($missing as $taxonomy => $ids) {
			$ids = array_values(array_filter($ids, static fn(int $id): bool => !$ctx->has_term($id, $target)));
			if ($ids) {
				$missing[$taxonomy] = $ids;
			} else {
				unset($missing[$taxonomy]);
			}
		}
		return $missing;
	}
}
