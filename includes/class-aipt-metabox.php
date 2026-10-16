<?php
// "AI translate" metabox and its AJAX layer over AIPT_Pipeline: prepare → translate_batch (loop) → finalize.

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
		add_action('wp_ajax_aipt_auto_retry', array($this, 'ajax_auto_retry'));
	}

	public function register(): void {
		if (!AIPT_Settings::enabled()) {
			return;
		}
		$settings = AIPT_Settings::get();
		foreach ($settings['post_types'] as $post_type) {
			add_meta_box('aipt_metabox', __('AI Translation', 'ai-polylang-translator'), array($this, 'render'), $post_type, 'side');
		}
	}

	public function enqueue(string $hook): void {
		if (!in_array($hook, array('post.php', 'post-new.php'), true) || !AIPT_Settings::enabled()) {
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

		$source_lang = (string) aipt_lang()->post_language($post->ID);
		if ($source_lang === '') {
			echo '<p>' . esc_html(sprintf(
				/* translators: %s: multilingual plugin name, e.g. Polylang */
				__('Set the post language (%s) and save the post first.', 'ai-polylang-translator'),
				AIPT_Lang_Loader::label()
			)) . '</p>';
			return;
		}

		$languages = aipt_lang()->languages();
		$this->render_auto($post, $languages);

		echo '<div class="aipt-rows">';
		foreach ($languages as $language) {
			if ($language['code'] === $source_lang) {
				continue;
			}
			$state    = aipt_lang()->translation_state($post->ID, $language['code']);
			$existing = AIPT_Record::existing_target($state);

			echo '<div class="aipt-row" data-lang="' . esc_attr($language['code']) . '" data-existing="' . esc_attr($existing ?: '') . '">';
			echo '<strong>' . esc_html($language['name']) . '</strong>';
			echo '<div class="aipt-actions">';
			if ($state['state'] === 'pending') {
				echo esc_html(AIPT_Record::pending_error()->get_error_message());
			} else {
				$edit_link = $existing ? get_edit_post_link($existing) : '';
				if ($edit_link) {
					echo '<a href="' . esc_url($edit_link) . '">' . esc_html__('Open translation', 'ai-polylang-translator') . '</a> ';
				}
				// A duplicate is an untranslated copy: overwrite only (metabox.js confirms
				// it), no safe mode, so it gets the same button as a new translation.
				if ($state['state'] === 'translated') {
					echo '<label class="aipt-safe-option" title="'
						. esc_attr__('Filled fields are kept. New flexible content sections are added and translated, existing sections are kept; repeater blocks with a different number of rows are replaced in full.', 'ai-polylang-translator')
						. '"><input type="checkbox" class="aipt-safe-mode" value="1" checked> '
						. esc_html__('Safe translation', 'ai-polylang-translator')
						. '</label>';
					echo '<button type="button" class="button button-primary aipt-translate">'
						. esc_html__('Update translation', 'ai-polylang-translator')
						. '</button>';
				} else {
					echo '<button type="button" class="button button-primary aipt-translate">'
						. esc_html(sprintf(/* translators: %s: language name */ __('Translate into %s', 'ai-polylang-translator'), $language['name']))
						. '</button>';
				}
			}
			echo '</div>';
			echo '<div class="aipt-status" aria-live="polite"></div>';
			echo '</div>';
		}
		echo '</div>';
	}

	// Status of the automatic translation (AIPT_Auto_Status), with Retry when it failed,
	// was lost or stalled (AIPT_Auto::retry_blocker()). The language rows below link to
	// the translations.
	private function render_auto(WP_Post $post, array $languages): void {
		$status  = AIPT_Auto_Status::get($post->ID);
		$lines   = $status !== null ? AIPT_Auto_Status::lines($status, $languages) : null;
		$blocker = AIPT_Auto::retry_blocker($post, $status, $lines['retryable'] ?? null);
		// Without a status only a legacy post (marked before 1.8.0, which kept no status, and
		// still missing a language) shows a neutral line.
		if ($status === null && $blocker) {
			return;
		}

		echo '<div class="aipt-auto">';
		$label = __('Retry automatic translation', 'ai-polylang-translator');
		if ($status === null) {
			$label = __('Translate missing languages automatically', 'ai-polylang-translator');
			echo '<div class="aipt-auto-line is-pending">' . esc_html(sprintf(
				/* translators: %s: comma-separated language codes */
				__('No automatic translation status (translated before 1.8); languages without a translation: %s', 'ai-polylang-translator'),
				implode(', ', AIPT_Auto::missing_languages($post->ID, AIPT_Settings::get()))
			)) . '</div>';
		} else {
			if ($lines['post'] !== '') {
				echo '<div class="aipt-auto-line is-' . esc_attr($status['status'] === 'failed' ? 'failed' : 'pending') . '">' . esc_html($lines['post']) . '</div>';
			}
			foreach ($lines['langs'] as $line) {
				$name = $languages[$line['code']]['name'] ?? $line['code'];
				echo '<div class="aipt-auto-line is-' . esc_attr($line['state']) . '" data-lang="' . esc_attr($line['code']) . '">'
					. '<b>' . esc_html($name) . ':</b> ' . esc_html($line['text']) . '</div>';
			}
			// Why a failed run cannot be retried now (setting off, post ineligible).
			if ($blocker && $blocker->get_error_code() !== 'aipt_auto_not_retryable' && $status['status'] === 'failed') {
				echo '<div class="aipt-auto-line">' . esc_html($blocker->get_error_message()) . '</div>';
			}
		}
		if (!$blocker) {
			echo '<button type="button" class="button button-small aipt-auto-retry">' . esc_html($label) . '</button>';
			echo '<span class="aipt-auto-status" aria-live="polite"></span>';
		}
		echo '</div>';
	}

	private function guard(): int {
		$post_id = absint($_POST['post_id'] ?? 0);
		check_ajax_referer('aipt_translate_' . $post_id);
		if (!$post_id || !current_user_can('edit_post', $post_id)) {
			wp_send_json_error(array('message' => __('Insufficient permissions.', 'ai-polylang-translator')));
		}
		// Parked: prepare, batch and finalize all refuse, also from an editor opened earlier.
		if (!AIPT_Settings::enabled()) {
			wp_send_json_error(array('message' => __('AI Translator is disabled in Settings.', 'ai-polylang-translator')));
		}
		return $post_id;
	}

	private function load_job(int $post_id): array {
		$job_id = sanitize_text_field(wp_unslash($_POST['job_id'] ?? ''));
		// Bulk jobs (durable ids) only go through the bulk layer: its revalidation before
		// the write and its recovery journal (AIPT_Bulk_Ajax).
		if (AIPT_Job_Store::is_durable($job_id)) {
			wp_send_json_error(array('message' => __('Translation job not found or expired. Start over.', 'ai-polylang-translator')));
		}
		$job = AIPT_Job::get($job_id);
		if (!AIPT_Pipeline::job_belongs($job, $post_id)) {
			wp_send_json_error(array('message' => __('Translation job not found or expired. Start over.', 'ai-polylang-translator')));
		}
		return array($job_id, $job);
	}

	public function ajax_prepare(): void {
		$post_id = $this->guard();
		$target  = sanitize_key(wp_unslash($_POST['target'] ?? ''));
		$mode    = sanitize_key(wp_unslash($_POST['mode'] ?? 'overwrite'));

		$this->send(AIPT_Pipeline::prepare($post_id, $target, array(
			'mode'    => $mode,
			'confirm' => !empty($_POST['confirm']),
		)));
	}

	public function ajax_translate_batch(): void {
		$post_id = $this->guard();
		list($job_id, $job) = $this->load_job($post_id);

		$this->send(AIPT_Pipeline::translate_batch($job_id, $job, absint($_POST['batch'] ?? 0)));
	}

	public function ajax_finalize(): void {
		$post_id = $this->guard();
		list($job_id, $job) = $this->load_job($post_id);

		$result = AIPT_Pipeline::finalize($job_id, $job);
		// The job's stage after finalize is for the bulk layer; metabox.js does not get it.
		if (is_array($result)) {
			unset($result['stage']);
		}
		$this->send($result);
	}

	// Schedules a failed, lost or stalled automatic translation again (AIPT_Auto::retry()).
	public function ajax_auto_retry(): void {
		$post_id = $this->guard();
		$result  = AIPT_Auto::retry($post_id, get_current_user_id());
		if (!is_wp_error($result)) {
			$result = array('message' => __('Automatic translation is scheduled; reload the post in a moment to see the result.', 'ai-polylang-translator'));
		}
		$this->send($result);
	}

	// metabox.js reads data.code only for needs_confirm; every other error is {message}.
	private function send($result): void {
		if (!is_wp_error($result)) {
			wp_send_json_success($result);
		}
		if ($result->get_error_code() === 'needs_confirm') {
			wp_send_json_error(array(
				'code'    => 'needs_confirm',
				'message' => $result->get_error_message(),
			));
		}
		wp_send_json_error(array('message' => $result->get_error_message()));
	}
}
