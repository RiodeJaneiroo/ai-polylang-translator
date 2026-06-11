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
			add_meta_box('aipt_metabox', __('AI-перевод', 'ai-polylang-translator'), array($this, 'render'), $post_type, 'side');
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
				'confirmOverwrite' => __('Перевод уже существует. Текущий контент перевода будет заменён. Продолжить?', 'ai-polylang-translator'),
				'preparing'        => __('Подготовка…', 'ai-polylang-translator'),
				'batch'            => __('Перевод… блок %1$d из %2$d', 'ai-polylang-translator'),
				'finalizing'       => __('Сохранение перевода…', 'ai-polylang-translator'),
				'done'             => __('Готово:', 'ai-polylang-translator'),
				'openDraft'        => __('Открыть перевод', 'ai-polylang-translator'),
				'retry'            => __('Повторить', 'ai-polylang-translator'),
				'error'            => __('Ошибка:', 'ai-polylang-translator'),
			),
		));
	}

	public function render(WP_Post $post): void {
		if (in_array($post->post_status, array('auto-draft', 'trash'), true)) {
			echo '<p>' . esc_html__('Сначала сохраните запись.', 'ai-polylang-translator') . '</p>';
			return;
		}

		if (AIPT_Settings::api_key() === '') {
			echo '<p>' . wp_kses_post(sprintf(
				/* translators: %s: settings page URL */
				__('Укажите API-ключ на <a href="%s">странице настроек</a>.', 'ai-polylang-translator'),
				esc_url(admin_url('options-general.php?page=' . AIPT_Settings::PAGE))
			)) . '</p>';
			return;
		}

		$source_lang = pll_get_post_language($post->ID);
		if (!$source_lang) {
			echo '<p>' . esc_html__('Сначала задайте язык записи (Polylang) и сохраните её.', 'ai-polylang-translator') . '</p>';
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
					echo '<a href="' . esc_url($edit_link) . '">' . esc_html__('Открыть перевод', 'ai-polylang-translator') . '</a> ';
				}
				echo '<label class="aipt-safe-option" title="'
					. esc_attr__('Заполненные поля сохраняются; несовпавшие repeater и flexible content заменяются целиком.', 'ai-polylang-translator')
					. '"><input type="checkbox" class="aipt-safe-mode" value="1" checked> '
					. esc_html__('Безопасный перевод', 'ai-polylang-translator')
					. '</label>';
				echo '<button type="button" class="button button-primary aipt-translate">'
					. esc_html__('Обновить перевод', 'ai-polylang-translator')
					. '</button>';
			} else {
				echo '<button type="button" class="button button-primary aipt-translate">'
					. esc_html(sprintf(/* translators: %s: language name */ __('Перевести на %s', 'ai-polylang-translator'), $language->name))
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
			wp_send_json_error(array('message' => __('Недостаточно прав.', 'ai-polylang-translator')));
		}
		return $post_id;
	}

	private function load_job(int $post_id): array {
		$job_id = sanitize_text_field(wp_unslash($_POST['job_id'] ?? ''));
		$job    = AIPT_Job::get($job_id);
		if (!$this->job_belongs_to_request($job, $post_id)) {
			wp_send_json_error(array('message' => __('Задача перевода не найдена или устарела. Начните заново.', 'ai-polylang-translator')));
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
			wp_send_json_error(array('message' => __('Этот тип записи не включён в настройках перевода.', 'ai-polylang-translator')));
		}
		if (AIPT_Settings::api_key() === '') {
			wp_send_json_error(array('message' => __('API-ключ не задан.', 'ai-polylang-translator')));
		}

		$target      = sanitize_key(wp_unslash($_POST['target'] ?? ''));
		$mode        = sanitize_key(wp_unslash($_POST['mode'] ?? 'overwrite'));
		$source_lang = pll_get_post_language($post_id);
		if ($mode !== 'safe') {
			$mode = 'overwrite';
		}

		if (!$source_lang) {
			wp_send_json_error(array('message' => __('У записи не задан язык Polylang.', 'ai-polylang-translator')));
		}
		if (!in_array($target, pll_languages_list(), true) || $target === $source_lang) {
			wp_send_json_error(array('message' => __('Некорректный целевой язык.', 'ai-polylang-translator')));
		}

		$existing = (int) (pll_get_post($post_id, $target) ?: 0);
		if ($existing && !current_user_can('edit_post', $existing)) {
			wp_send_json_error(array('message' => __('Недостаточно прав для изменения существующего перевода.', 'ai-polylang-translator')));
		}
		if (!$existing && !$this->can_create_translation($post)) {
			wp_send_json_error(array('message' => __('Недостаточно прав для создания перевода.', 'ai-polylang-translator')));
		}
		if (!$existing) {
			$mode = 'overwrite';
		}
		if ($existing && $mode === 'overwrite' && empty($_POST['confirm'])) {
			wp_send_json_error(array(
				'code'    => 'needs_confirm',
				'message' => __('Перевод уже существует — требуется подтверждение перезаписи.', 'ai-polylang-translator'),
			));
		}

		$extract = AIPT_Extractor::extract($post_id, $existing, $mode === 'safe');
		if (!$extract['items'] && $mode !== 'safe') {
			wp_send_json_error(array('message' => __('В записи нет текста для перевода.', 'ai-polylang-translator')));
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
			wp_send_json_error(array('message' => __('Не удалось сохранить задачу перевода — слишком большой объём данных. Обратитесь к администратору.', 'ai-polylang-translator')));
		}

		wp_send_json_success(array('job_id' => $job_id, 'total' => count($batches)));
	}

	public function ajax_translate_batch(): void {
		$post_id = $this->guard();
		list($job_id, $job) = $this->load_job($post_id);

		$total = count($job['batches']);
		$index = absint($_POST['batch'] ?? 0);
		if (!isset($job['batches'][$index])) {
			wp_send_json_error(array('message' => __('Неизвестный блок перевода.', 'ai-polylang-translator')));
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

		$result = AIPT_Gateway::translate_map($map, $job['source_name'], $job['target_name']);

		if (is_wp_error($result)) {
			wp_send_json_error(array('message' => $result->get_error_message()));
		}

		if (!AIPT_Job::save_batch($job_id, $index, $result)) {
			wp_send_json_error(array('message' => __('Не удалось сохранить результат перевода — слишком большой объём данных. Обратитесь к администратору.', 'ai-polylang-translator')));
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
			wp_send_json_error(array('message' => __('Не все блоки переведены — завершение невозможно.', 'ai-polylang-translator')));
		}

		if (!AIPT_Job::acquire_finalize_lock($job_id)) {
			wp_send_json_error(array('message' => __('Перевод уже сохраняется. Повторите запрос через несколько секунд.', 'ai-polylang-translator')));
		}

		$error = null;
		try {
			$job = AIPT_Job::get($job_id);
			if (!$this->job_belongs_to_request($job, $post_id)) {
				$error = new WP_Error('aipt_job_expired', __('Задача перевода не найдена или устарела. Начните заново.', 'ai-polylang-translator'));
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
			wp_send_json_error(array('message' => __('Сохранённый перевод не найден. Начните перевод заново.', 'ai-polylang-translator')));
		}

		wp_send_json_success(array(
			'post_id'   => $post_id,
			'edit_link' => get_edit_post_link($post_id, 'raw'),
		));
	}
}
