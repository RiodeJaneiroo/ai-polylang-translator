<?php
// Builds the immutable job payload for AIPT_Pipeline (prepare and translate_post):
// validates the request, decides from the adapter's translation_state() whether the
// target is created or updated, extracts the strings and packs the batches.

if (!defined('ABSPATH')) {
	exit;
}

class AIPT_Job_Builder {

	/**
	 * Validate the request and build the job payload (not stored).
	 *
	 * @param array $opts See AIPT_Pipeline::prepare(), plus skip_existing.
	 * @return array|WP_Error
	 */
	public static function build(int $post_id, string $target, array $opts) {
		$lang  = aipt_lang();
		$state = $lang->translation_state($post_id, $target);
		if ($state['state'] === 'pending') {
			return AIPT_Record::pending_error();
		}
		// Checked here, under translate_post()'s pair lock, so a translation that appeared
		// since the caller's pre-checks is never updated. A duplicate is not skipped.
		if (!empty($opts['skip_existing']) && $state['state'] === 'translated') {
			return new WP_Error('aipt_translation_exists', __('The translation already exists.', 'ai-polylang-translator'), array('target_id' => (int) $state['id']));
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
		$source_lang = (string) $lang->post_language($post_id);

		if ($source_lang === '') {
			/* translators: %s: multilingual plugin name, e.g. Polylang */
			return new WP_Error('aipt_no_language', sprintf(__('The post has no language set in %s.', 'ai-polylang-translator'), AIPT_Lang_Loader::label()));
		}
		$languages = $lang->languages();
		if (!isset($languages[$target]) || $target === $source_lang) {
			return new WP_Error('aipt_invalid_target', __('Invalid target language.', 'ai-polylang-translator'));
		}

		$existing = AIPT_Record::existing_target($state);
		if ($existing && !current_user_can('edit_post', $existing)) {
			return new WP_Error('aipt_cannot_edit_translation', __('Insufficient permissions to modify the existing translation.', 'ai-polylang-translator'));
		}
		if (!$existing && !self::can_create_translation($post)) {
			return new WP_Error('aipt_cannot_create_translation', __('Insufficient permissions to create a translation.', 'ai-polylang-translator'));
		}
		$mode = AIPT_Record::effective_mode($state, $mode);
		if ($existing && $mode === 'overwrite' && empty($opts['confirm'])) {
			return new WP_Error('needs_confirm', __('A translation already exists — overwrite confirmation is required.', 'ai-polylang-translator'));
		}

		$extract = AIPT_Extractor::extract($post_id, $existing, $mode === 'safe', $target);
		if (!$extract['items'] && $mode !== 'safe') {
			return new WP_Error('aipt_no_text', __('The post has no text to translate.', 'ai-polylang-translator'));
		}

		$batches = array();
		foreach (AIPT_Extractor::build_batches($extract['items']) as $keys) {
			$batches[] = array('keys' => $keys);
		}

		$post_status = (string) ($opts['post_status'] ?? 'draft');
		if (!in_array($post_status, AIPT_Pipeline::POST_STATUSES, true)) {
			$post_status = 'draft';
		}

		$job = array(
			'user_id'      => get_current_user_id(),
			'post_id'      => $post_id,
			'target'       => $target,
			// Snapshot the model at job-creation time: the cost log must reflect
			// the model actually used even if the setting changes before finalize.
			'model'        => $settings['model'],
			'existing'     => $existing,
			// The translation_state() the job was prepared against; the writer re-checks it.
			'target_state' => (string) $state['state'],
			'mode'         => $mode,
			// Apply only when the writer creates the translation.
			'post_status'  => $post_status,
			'keep_date'    => !empty($opts['keep_date']),
			'source_name'  => $lang->language_name($source_lang),
			'target_name'  => $lang->language_name($target),
			'items'        => $extract['items'],
			'tree'         => $extract['tree'],
			'remap'        => $extract['remap'],
			'meta'         => $extract['meta'],
			'preserve'     => $extract['preserve'],
			'overwrite'    => $extract['overwrite'],
			'flex'         => $extract['flex'],
			'skip'         => $extract['skip'],
			// WooCommerce attribute snapshot: [] or ['attributes' => raw _product_attributes].
			'woo'          => $extract['woo'],
			// Jobs without this marker were created by 1.2 and still rely on
			// finalize-time aggregation of usage stored in batch transients.
			'usage_recorded_per_batch' => true,
			'batches'      => $batches,
		);
		// Block-delimiter maps per content chunk (AIPT_Blocks). Absent for posts without
		// blocks, so their jobs are unchanged.
		if ($extract['blocks']) {
			$job['blocks'] = $extract['blocks'];
		}
		return $job;
	}

	private static function can_create_translation(WP_Post $post): bool {
		$post_type = get_post_type_object($post->post_type);
		return $post_type && current_user_can($post_type->cap->create_posts);
	}
}
