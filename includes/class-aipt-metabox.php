<?php
// "AI translate" metabox and the AJAX flow: prepare → translate_batch (loop) → finalize.

if (!defined('ABSPATH')) {
	exit;
}

class AIPT_Metabox {

	public function __construct() {
		add_action('add_meta_boxes', array($this, 'register'));
		add_action('admin_enqueue_scripts', array($this, 'enqueue'));
		add_action('wp_ajax_aipt_prepare', array($this, 'ajax_prepare'));
		add_action('wp_ajax_aipt_translate_batch', array($this, 'ajax_translate_batch'));
		add_action('wp_ajax_aipt_finalize', array($this, 'ajax_finalize'));
	}

	public function register(): void {
		$settings = AIPT_Settings::get();
		foreach ($settings['post_types'] as $post_type) {
			add_meta_box('aipt_metabox', __('AI Translation', 'ai-polylang-translator'), array($this, 'render'), $post_type, 'side');
		}
	}

	public function enqueue(string $hook): void {
		if (!in_array($hook, array('post.php', 'post-new.php'), true)) {
			return;
		}
		$screen   = get_current_screen();
		$settings = AIPT_Settings::get();
		if (!$screen || !in_array($screen->post_type, $settings['post_types'], true)) {
			return;
		}
		$post_id = isset($_GET['post']) ? absint($_GET['post']) : 0;

		wp_enqueue_style('aipt-admin', AIPT_URL . 'assets/admin.css', array(), AIPT_VERSION);
		wp_enqueue_script('aipt-metabox', AIPT_URL . 'assets/metabox.js', array('wp-i18n'), AIPT_VERSION, true);
		wp_localize_script('aipt-metabox', 'aiptMetabox', array(
			'ajaxUrl' => admin_url('admin-ajax.php'),
			'postId'  => $post_id,
			'nonce'   => wp_create_nonce('aipt_translate_' . $post_id),
			'i18n'    => array(
				'confirmOverwrite' => __('A translation already exists. Its current content will be replaced. Continue?', 'ai-polylang-translator'),
				'preparing'        => __('Preparing…', 'ai-polylang-translator'),
				/* translators: %1$d: current block number, %2$d: total number of blocks */
				'batch'            => __('Translating… block %1$d of %2$d', 'ai-polylang-translator'),
				'finalizing'       => __('Saving translation…', 'ai-polylang-translator'),
				'done'             => __('Done:', 'ai-polylang-translator'),
				'openDraft'        => __('Open translation', 'ai-polylang-translator'),
				'retry'            => __('Retry', 'ai-polylang-translator'),
				'error'            => __('Error:', 'ai-polylang-translator'),
			),
		));
	}

	public function render(WP_Post $post): void {
		if (in_array($post->post_status, array('auto-draft', 'trash'), true)) {
			echo '<p>' . esc_html__('Save the post first.', 'ai-polylang-translator') . '</p>';
			return;
		}

		if (AIPT_Settings::api_key() === '') {
			echo '<p>' . wp_kses_post(sprintf(
				/* translators: %s: settings page URL */
				__('Enter an API key on the <a href="%s">settings page</a>.', 'ai-polylang-translator'),
				esc_url(admin_url('options-general.php?page=' . AIPT_Settings::PAGE))
			)) . '</p>';
			return;
		}

		$source_lang = pll_get_post_language($post->ID);
		if (!$source_lang) {
			echo '<p>' . esc_html__('Set the post language (Polylang) and save the post first.', 'ai-polylang-translator') . '</p>';
			return;
		}

		echo '<div class="aipt-rows">';
		foreach (pll_languages_list(array('fields' => '')) as $language) {
			if ($language->slug === $source_lang) {
				continue;
			}
			$existing = pll_get_post($post->ID, $language->slug);
			$existing = $existing ? (int) $existing : 0;

			echo '<div class="aipt-row" data-lang="' . esc_attr($language->slug) . '" data-existing="' . esc_attr($existing ?: '') . '">';
			echo '<strong>' . esc_html($language->name) . '</strong>';
			echo '<div class="aipt-actions">';
			if ($existing) {
				$edit_link = get_edit_post_link($existing);
				if ($edit_link) {
					echo '<a href="' . esc_url($edit_link) . '">' . esc_html__('Open translation', 'ai-polylang-translator') . '</a> ';
				}
				echo '<label class="aipt-safe-option" title="'
					. esc_attr__('Filled fields are kept; mismatched repeater and flexible content blocks are replaced in full.', 'ai-polylang-translator')
					. '"><input type="checkbox" class="aipt-safe-mode" value="1" checked> '
					. esc_html__('Safe translation', 'ai-polylang-translator')
					. '</label>';
				echo '<button type="button" class="button button-primary aipt-translate">'
					. esc_html__('Update translation', 'ai-polylang-translator')
					. '</button>';
			} else {
				echo '<button type="button" class="button button-primary aipt-translate">'
					. esc_html(sprintf(/* translators: %s: language name */ __('Translate into %s', 'ai-polylang-translator'), $language->name))
					. '</button>';
			}
			echo '</div>';
			echo '<div class="aipt-status" aria-live="polite"></div>';
			echo '</div>';
		}
		echo '</div>';
	}

	private function guard(): int {
		$post_id = absint($_POST['post_id'] ?? 0);
		check_ajax_referer('aipt_translate_' . $post_id);
		if (!$post_id || !current_user_can('edit_post', $post_id)) {
			wp_send_json_error(array('message' => __('Insufficient permissions.', 'ai-polylang-translator')));
		}
		return $post_id;
	}

	private function load_job(int $post_id): array {
		$job_id = sanitize_text_field(wp_unslash($_POST['job_id'] ?? ''));
		$job    = AIPT_Job::get($job_id);
		if (!$this->job_belongs_to_request($job, $post_id)) {
			wp_send_json_error(array('message' => __('Translation job not found or expired. Start over.', 'ai-polylang-translator')));
		}
		return array($job_id, $job);
	}

	private function job_belongs_to_request(?array $job, int $post_id): bool {
		return $job
			&& (int) ($job['user_id'] ?? 0) === get_current_user_id()
			&& (int) ($job['post_id'] ?? 0) === $post_id;
	}

	private function can_create_translation(WP_Post $post): bool {
		$post_type = get_post_type_object($post->post_type);
		return $post_type && current_user_can($post_type->cap->create_posts);
	}

	public function ajax_prepare(): void {
		$post_id  = $this->guard();
		$post     = get_post($post_id);
		$settings = AIPT_Settings::get();

		if (!$post || !in_array($post->post_type, $settings['post_types'], true)) {
			wp_send_json_error(array('message' => __('This post type is not enabled in the translation settings.', 'ai-polylang-translator')));
		}
		if (AIPT_Settings::api_key() === '') {
			wp_send_json_error(array('message' => __('API key is not set.', 'ai-polylang-translator')));
		}

		$target      = sanitize_key(wp_unslash($_POST['target'] ?? ''));
		$mode        = sanitize_key(wp_unslash($_POST['mode'] ?? 'overwrite'));
		$source_lang = pll_get_post_language($post_id);
		if ($mode !== 'safe') {
			$mode = 'overwrite';
		}

		if (!$source_lang) {
			wp_send_json_error(array('message' => __('The post has no Polylang language set.', 'ai-polylang-translator')));
		}
		if (!in_array($target, pll_languages_list(), true) || $target === $source_lang) {
			wp_send_json_error(array('message' => __('Invalid target language.', 'ai-polylang-translator')));
		}

		$existing = (int) (pll_get_post($post_id, $target) ?: 0);
		if ($existing && !current_user_can('edit_post', $existing)) {
			wp_send_json_error(array('message' => __('Insufficient permissions to modify the existing translation.', 'ai-polylang-translator')));
		}
		if (!$existing && !$this->can_create_translation($post)) {
			wp_send_json_error(array('message' => __('Insufficient permissions to create a translation.', 'ai-polylang-translator')));
		}
		if (!$existing) {
			$mode = 'overwrite';
		}
		if ($existing && $mode === 'overwrite' && empty($_POST['confirm'])) {
			wp_send_json_error(array(
				'code'    => 'needs_confirm',
				'message' => __('A translation already exists — overwrite confirmation is required.', 'ai-polylang-translator'),
			));
		}

		$extract = AIPT_Extractor::extract($post_id, $existing, $mode === 'safe');
		if (!$extract['items'] && $mode !== 'safe') {
			wp_send_json_error(array('message' => __('The post has no text to translate.', 'ai-polylang-translator')));
		}

		$source_name = (string) pll_get_post_language($post_id, 'name');
		$target_name = $target;
		foreach (pll_languages_list(array('fields' => '')) as $language) {
			if ($language->slug === $target) {
				$target_name = $language->name;
				break;
			}
		}

		$batches = array();
		foreach (AIPT_Extractor::build_batches($extract['items']) as $keys) {
			$batches[] = array('keys' => $keys);
		}

		// Immutable payload: written once here and never rewritten. Per-batch results
		// live in their own transients so concurrent batch requests cannot clobber a
		// shared blob (last-write-wins data loss).
		$job_id = AIPT_Job::create(array(
			'user_id'     => get_current_user_id(),
			'post_id'     => $post_id,
			'target'      => $target,
			// Snapshot the model at job-creation time: the cost log must reflect
			// the model actually used even if the setting changes before finalize.
			'model'       => $settings['model'],
			'existing'    => $existing,
			'mode'        => $mode,
			'source_name' => $source_name ?: $source_lang,
			'target_name' => $target_name,
			'items'       => $extract['items'],
			'tree'        => $extract['tree'],
			'remap'       => $extract['remap'],
			'meta'        => $extract['meta'],
			'preserve'    => $extract['preserve'],
			'overwrite'   => $extract['overwrite'],
			'batches'     => $batches,
		));

		if ($job_id === '') {
			wp_send_json_error(array('message' => __('Could not save the translation job — the payload is too large. Contact an administrator.', 'ai-polylang-translator')));
		}

		wp_send_json_success(array('job_id' => $job_id, 'total' => count($batches)));
	}

	public function ajax_translate_batch(): void {
		$post_id = $this->guard();
		list($job_id, $job) = $this->load_job($post_id);

		$total = count($job['batches']);
		$index = absint($_POST['batch'] ?? 0);
		if (!isset($job['batches'][$index])) {
			wp_send_json_error(array('message' => __('Unknown translation block.', 'ai-polylang-translator')));
		}

		// Idempotent: a finished batch is never re-translated.
		if (AIPT_Job::get_batch($job_id, $index) !== false) {
			wp_send_json_success(array('done' => $index + 1, 'total' => $total));
		}

		if (function_exists('set_time_limit')) {
			set_time_limit(180);
		}

		$map = array();
		foreach ($job['batches'][$index]['keys'] as $item_id) {
			if (isset($job['items'][$item_id])) {
				$map[$item_id] = $job['items'][$item_id]['text'];
			}
		}

		$result = AIPT_Gateway::translate_map($map, $job['source_name'], $job['target_name'], (string) ($job['model'] ?? ''));

		if (is_wp_error($result)) {
			wp_send_json_error(array('message' => $result->get_error_message()));
		}

		if (!AIPT_Job::save_batch($job_id, $index, $result, AIPT_Gateway::get_usage())) {
			wp_send_json_error(array('message' => __('Could not save the translation result — the payload is too large. Contact an administrator.', 'ai-polylang-translator')));
		}

		wp_send_json_success(array('done' => $index + 1, 'total' => $total));
	}

	public function ajax_finalize(): void {
		$post_id = $this->guard();
		list($job_id, $job) = $this->load_job($post_id);

		// A repeated finalize after a successful write returns the saved translation.
		if (($job['status'] ?? '') === 'complete') {
			$this->send_finalized_job($job);
		}

		$total   = count($job['batches']);
		$results = AIPT_Job::get_batches($job_id, $total);
		if ($results === null) {
			wp_send_json_error(array('message' => __('Not all blocks are translated — finalization is not possible.', 'ai-polylang-translator')));
		}

		if (!AIPT_Job::acquire_finalize_lock($job_id)) {
			wp_send_json_error(array('message' => __('The translation is already being saved. Try again in a few seconds.', 'ai-polylang-translator')));
		}

		$error = null;
		try {
			$job = AIPT_Job::get($job_id);
			if (!$this->job_belongs_to_request($job, $post_id)) {
				$error = new WP_Error('aipt_job_expired', __('Translation job not found or expired. Start over.', 'ai-polylang-translator'));
			} elseif (($job['status'] ?? '') === 'complete') {
				// Won by a concurrent finalize while we waited for the lock.
				$error = null;
			} else {
				$job['results'] = $results;

				$new_id = AIPT_Writer::write($job);
				if (is_wp_error($new_id)) {
					// Leave the batch transients in place so the user can retry finalize.
					$error = $new_id;
				} else {
					// Keep a small completion marker so a duplicate finalize returns the
					// link; drop the per-batch result transients (the large blobs).
					unset($job['results']);
					$job['status']       = 'complete';
					$job['finalized_id'] = (int) $new_id;
					AIPT_Job::save($job_id, $job);

					// Log the token cost for this completed job. Failed/abandoned jobs are
					// intentionally not recorded — only successful translations are billed here.
					$usage = AIPT_Job::get_usage_total($job_id, $total);
					AIPT_Usage::record(
						$post_id,
						(string) ($job['target'] ?? ''),
						// Legacy jobs created before the model snapshot existed (1-hour window)
						// have no 'model' key; they actually ran on the live setting, so log that
						// rather than a blank label — consistent with the gateway's empty-override
						// fallback to AIPT_Settings::get()['model'].
						(string) ($job['model'] ?? AIPT_Settings::get()['model']),
						$usage['tokens_in'],
						$usage['tokens_out'],
						$usage['cost']
					);

					AIPT_Job::delete_batches($job_id, $total);
				}
			}
		} finally {
			AIPT_Job::release_finalize_lock($job_id);
		}

		if ($error) {
			wp_send_json_error(array('message' => $error->get_error_message()));
		}

		$this->send_finalized_job($job);
	}

	private function send_finalized_job(array $job): void {
		$post_id = (int) ($job['finalized_id'] ?? 0);
		if (!$post_id || !get_post($post_id)) {
			wp_send_json_error(array('message' => __('The saved translation was not found. Start the translation over.', 'ai-polylang-translator')));
		}

		wp_send_json_success(array(
			'post_id'   => $post_id,
			'edit_link' => get_edit_post_link($post_id, 'raw'),
		));
	}
}
