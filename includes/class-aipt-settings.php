<?php

if (!defined('ABSPATH')) {
	exit;
}

class AIPT_Settings {

	const PAGE = 'ai-polylang-translator';

	const MODELS = array(
		'google/gemini-2.5-flash'    => 'Gemini 2.5 Flash — самая дешёвая, оптимальна для перевода',
		'openai/gpt-4.1-mini'        => 'GPT-4.1 mini — баланс цены и качества',
		'anthropic/claude-haiku-4.5' => 'Claude Haiku 4.5 — максимум качества (дороже)',
	);

	public function __construct() {
		add_action('admin_menu', array($this, 'add_page'));
		add_action('admin_init', array($this, 'register'));
		add_action('admin_enqueue_scripts', array($this, 'enqueue'));
		add_action('wp_ajax_aipt_test_key', array($this, 'ajax_test_key'));
	}

	public static function defaults(): array {
		return array(
			'model'           => 'google/gemini-2.5-flash',
			'post_types'      => array('page', 'post'),
			'field_overrides' => array(),
			'site_context'    => '',
			'translate_yoast' => true,
			'timeout'         => 90,
		);
	}

	public static function get(): array {
		$saved = get_option('aipt_settings', array());
		if (!is_array($saved)) {
			$saved = array();
		}
		return array_merge(self::defaults(), $saved);
	}

	public static function api_key(): string {
		return (string) get_option('aipt_api_key', '');
	}

	public static function mask_key(string $key): string {
		if (strlen($key) < 9) {
			return '••••••••';
		}
		return substr($key, 0, 4) . '…' . substr($key, -4);
	}

	public function add_page(): void {
		add_options_page(
			__('AI Переводчик', 'ai-polylang-translator'),
			__('AI Переводчик', 'ai-polylang-translator'),
			'manage_options',
			self::PAGE,
			array($this, 'render_page')
		);
	}

	public function register(): void {
		register_setting('aipt', 'aipt_api_key', array(
			'type'              => 'string',
			'sanitize_callback' => array($this, 'sanitize_api_key'),
		));
		register_setting('aipt', 'aipt_settings', array(
			'type'              => 'array',
			'sanitize_callback' => array($this, 'sanitize_settings'),
		));
	}

	// Empty input keeps the stored key: the key is never echoed back into the form.
	public function sanitize_api_key($value): string {
		$value = trim((string) $value);
		if ($value === '') {
			return self::api_key();
		}
		return $value;
	}

	public function sanitize_settings($value): array {
		$old = self::get();
		$out = self::defaults();
		if (!is_array($value)) {
			return $old;
		}

		$out['model'] = isset($value['model']) && isset(self::MODELS[$value['model']])
			? $value['model']
			: $out['model'];

		$out['post_types'] = array();
		if (!empty($value['post_types']) && is_array($value['post_types'])) {
			$public = get_post_types(array('public' => true));
			foreach ($value['post_types'] as $pt) {
				$pt = sanitize_key($pt);
				if (isset($public[$pt]) && $pt !== 'attachment') {
					$out['post_types'][] = $pt;
				}
			}
		}

		$out['site_context']    = sanitize_textarea_field($value['site_context'] ?? '');
		$out['translate_yoast'] = aipt_yoast_active()
			? !empty($value['translate_yoast'])
			: $old['translate_yoast'];
		$out['timeout']         = max(30, min(300, (int) ($value['timeout'] ?? 90)));

		// Only deviations from the default policy are stored, so new ACF fields translate by default.
		if (isset($value['acf_all']) && is_array($value['acf_all'])) {
			$checked   = array_map('sanitize_text_field', (array) ($value['acf_translate'] ?? array()));
			$overrides = array();
			foreach ($value['acf_all'] as $field_key) {
				$field_key = sanitize_text_field($field_key);
				if (!in_array($field_key, $checked, true)) {
					$overrides[$field_key] = 'copy';
				}
			}
			$out['field_overrides'] = $overrides;
		} else {
			$out['field_overrides'] = $old['field_overrides'];
		}

		return $out;
	}

	public function enqueue(string $hook): void {
		if ($hook !== 'settings_page_' . self::PAGE) {
			return;
		}
		wp_enqueue_style('aipt-admin', AIPT_URL . 'assets/admin.css', array(), AIPT_VERSION);
		wp_enqueue_script('aipt-settings', AIPT_URL . 'assets/settings.js', array(), AIPT_VERSION, true);
		wp_localize_script('aipt-settings', 'aiptSettings', array(
			'ajaxUrl' => admin_url('admin-ajax.php'),
			'nonce'   => wp_create_nonce('aipt_test_key'),
			'i18n'    => array(
				'testing' => __('Проверяю…', 'ai-polylang-translator'),
				'ok'      => __('✓ Ключ работает', 'ai-polylang-translator'),
			),
		));
	}

	public function ajax_test_key(): void {
		check_ajax_referer('aipt_test_key');
		if (!current_user_can('manage_options')) {
			wp_send_json_error(array('message' => __('Недостаточно прав.', 'ai-polylang-translator')));
		}
		$key = trim((string) ($_POST['key'] ?? ''));
		if ($key === '') {
			$key = self::api_key();
		}
		if ($key === '') {
			wp_send_json_error(array('message' => __('Ключ не задан.', 'ai-polylang-translator')));
		}
		$result = AIPT_Gateway::test_key($key);
		if (is_wp_error($result)) {
			wp_send_json_error(array('message' => $result->get_error_message()));
		}
		wp_send_json_success();
	}

	public function render_page(): void {
		$settings = self::get();
		$key      = self::api_key();
		?>
		<div class="wrap aipt-settings">
			<h1><?php esc_html_e('AI Переводчик (Polylang)', 'ai-polylang-translator'); ?></h1>
			<form method="post" action="options.php">
				<?php settings_fields('aipt'); ?>

				<h2><?php esc_html_e('API', 'ai-polylang-translator'); ?></h2>
				<table class="form-table" role="presentation">
					<tr>
						<th scope="row"><label for="aipt-api-key"><?php esc_html_e('API-ключ Vercel AI Gateway', 'ai-polylang-translator'); ?></label></th>
						<td>
							<input type="password" id="aipt-api-key" name="aipt_api_key" value="" class="regular-text" autocomplete="new-password"
								placeholder="<?php echo esc_attr($key !== '' ? self::mask_key($key) : __('Вставьте ключ', 'ai-polylang-translator')); ?>">
							<button type="button" class="button" id="aipt-test-key"><?php esc_html_e('Проверить ключ', 'ai-polylang-translator'); ?></button>
							<span id="aipt-test-result"></span>
							<p class="description">
								<?php if ($key !== '') : ?>
									<?php esc_html_e('Ключ сохранён. Оставьте поле пустым, чтобы не менять его.', 'ai-polylang-translator'); ?>
								<?php else : ?>
									<?php echo wp_kses_post(__('Получите ключ в <a href="https://vercel.com/ai-gateway" target="_blank" rel="noopener">Vercel AI Gateway</a>.', 'ai-polylang-translator')); ?>
								<?php endif; ?>
							</p>
						</td>
					</tr>
					<tr>
						<th scope="row"><label for="aipt-model"><?php esc_html_e('Модель', 'ai-polylang-translator'); ?></label></th>
						<td>
							<select id="aipt-model" name="aipt_settings[model]">
								<?php foreach (self::MODELS as $id => $label) : ?>
									<option value="<?php echo esc_attr($id); ?>" <?php selected($settings['model'], $id); ?>><?php echo esc_html($label); ?></option>
								<?php endforeach; ?>
							</select>
						</td>
					</tr>
				</table>

				<details class="aipt-advanced" <?php echo empty($settings['field_overrides']) ? '' : 'open'; ?>>
					<summary><h2><?php esc_html_e('Расширенные настройки', 'ai-polylang-translator'); ?></h2></summary>

					<table class="form-table" role="presentation">
						<tr>
							<th scope="row"><?php esc_html_e('Типы записей', 'ai-polylang-translator'); ?></th>
							<td>
								<?php foreach (get_post_types(array('public' => true), 'objects') as $pt) :
									if ($pt->name === 'attachment') {
										continue;
									} ?>
									<label class="aipt-cpt">
										<input type="checkbox" name="aipt_settings[post_types][]" value="<?php echo esc_attr($pt->name); ?>"
											<?php checked(in_array($pt->name, $settings['post_types'], true)); ?>>
										<?php echo esc_html($pt->labels->name); ?> <code><?php echo esc_html($pt->name); ?></code>
									</label><br>
								<?php endforeach; ?>
								<p class="description"><?php esc_html_e('Метабокс перевода появится только у выбранных типов.', 'ai-polylang-translator'); ?></p>
							</td>
						</tr>
						<tr>
							<th scope="row"><label for="aipt-context"><?php esc_html_e('Контекст сайта для переводчика', 'ai-polylang-translator'); ?></label></th>
							<td>
								<textarea id="aipt-context" name="aipt_settings[site_context]" rows="2" class="large-text"
									placeholder="<?php esc_attr_e('Например: медицинская клиника в Киеве, терминологию переводить аккуратно', 'ai-polylang-translator'); ?>"><?php echo esc_textarea($settings['site_context']); ?></textarea>
								<p class="description"><?php esc_html_e('Добавляется в промпт — помогает модели выдерживать тематику и тон.', 'ai-polylang-translator'); ?></p>
							</td>
						</tr>
						<?php if (aipt_yoast_active()) : ?>
							<tr>
								<th scope="row"><?php esc_html_e('Yoast SEO', 'ai-polylang-translator'); ?></th>
								<td>
									<label>
										<input type="checkbox" name="aipt_settings[translate_yoast]" value="1" <?php checked($settings['translate_yoast']); ?>>
										<?php esc_html_e('Переводить SEO-заголовок, описание и фокусное слово', 'ai-polylang-translator'); ?>
									</label>
								</td>
							</tr>
						<?php endif; ?>
						<tr>
							<th scope="row"><label for="aipt-timeout"><?php esc_html_e('Таймаут запроса, сек', 'ai-polylang-translator'); ?></label></th>
							<td><input type="number" id="aipt-timeout" name="aipt_settings[timeout]" value="<?php echo esc_attr($settings['timeout']); ?>" min="30" max="300" step="5"></td>
						</tr>
						<?php if (aipt_acf_active()) : ?>
							<tr>
								<th scope="row"><?php esc_html_e('Переводимые поля ACF', 'ai-polylang-translator'); ?></th>
								<td>
									<p class="description"><?php esc_html_e('Отмеченные текстовые поля переводятся, остальные (картинки, числа, связи и т.д.) копируются как есть. Новые поля переводятся по умолчанию.', 'ai-polylang-translator'); ?></p>
									<?php echo AIPT_ACF_Schema::render_tree_html($settings['field_overrides']); // phpcs:ignore WordPress.Security.EscapeOutput ?>
								</td>
							</tr>
						<?php endif; ?>
					</table>
				</details>

				<?php submit_button(__('Сохранить настройки', 'ai-polylang-translator')); ?>
			</form>
		</div>
		<?php
	}
}
