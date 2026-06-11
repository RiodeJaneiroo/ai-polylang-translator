<?php

if (!defined('ABSPATH')) {
	exit;
}

class AIPT_Settings {

	const PAGE = 'ai-polylang-translator';

	// Single source of truth for supported models: select labels, the
	// client-facing cheat sheet and sanitization all derive from this list.
	// Prices are approximate USD per ~3,000-character article.
	public static function model_catalog(): array {
		return array(
			'google/gemini-2.5-flash-lite' => array(
				'name'  => 'Gemini 2.5 Flash Lite',
				'label' => __('Gemini 2.5 Flash Lite — ultra cheap, fastest', 'ai-polylang-translator'),
				'desc'  => __('The fastest and cheapest. A good fit when you translate a lot of pages at once.', 'ai-polylang-translator'),
				'price' => '$0.001',
			),
			'google/gemini-2.5-flash' => array(
				'name'  => 'Gemini 2.5 Flash',
				'label' => __('Gemini 2.5 Flash — recommended, best value for translation', 'ai-polylang-translator'),
				'desc'  => __('Recommended default: excellent quality at a low price.', 'ai-polylang-translator'),
				'price' => '$0.004',
			),
			'openai/gpt-4.1-mini' => array(
				'name'  => 'GPT-4.1 mini',
				'label' => __('GPT-4.1 mini — balanced price and quality', 'ai-polylang-translator'),
				'desc'  => __('A solid alternative with balanced price and quality.', 'ai-polylang-translator'),
				'price' => '$0.003',
			),
			'anthropic/claude-sonnet-4.6' => array(
				'name'  => 'Claude Sonnet 4.6',
				'label' => __('Claude Sonnet 4.6 — premium quality (most expensive)', 'ai-polylang-translator'),
				'desc'  => __('Maximum accuracy — for important pages where every word matters.', 'ai-polylang-translator'),
				'price' => '$0.03',
			),
		);
	}

	public static function models(): array {
		return array_map(static fn(array $model): string => $model['label'], self::model_catalog());
	}

	// Sub-dollar amounts keep 4 decimals (typical per-article costs are fractions
	// of a cent); larger totals read better with 2.
	private static function format_cost(float $val): string {
		return '$' . number_format_i18n($val, $val > 0 && $val < 1 ? 4 : 2);
	}

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
		$settings = array_merge(self::defaults(), $saved);
		// A model removed from the catalog must not silently keep being used or mislead the UI.
		if (!array_key_exists($settings['model'], self::model_catalog())) {
			$settings['model'] = self::defaults()['model'];
		}
		return $settings;
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
			__('AI Translator', 'ai-polylang-translator'),
			__('AI Translator', 'ai-polylang-translator'),
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

		$out['model'] = isset($value['model']) && array_key_exists($value['model'], self::model_catalog())
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
				'testing' => __('Testing…', 'ai-polylang-translator'),
				'ok'      => __('Key is valid and saved', 'ai-polylang-translator'),
			),
		));
	}

	public function ajax_test_key(): void {
		check_ajax_referer('aipt_test_key');
		if (!current_user_can('manage_options')) {
			wp_send_json_error(array('message' => __('Insufficient permissions.', 'ai-polylang-translator')));
		}
		$submitted_key = trim((string) wp_unslash($_POST['key'] ?? ''));
		$key           = $submitted_key;
		if ($key === '') {
			$key = self::api_key();
		}
		if ($key === '') {
			wp_send_json_error(array('message' => __('No API key set.', 'ai-polylang-translator')));
		}
		$result = AIPT_Gateway::test_key($key);
		if (is_wp_error($result)) {
			wp_send_json_error(array('message' => $result->get_error_message()));
		}
		if ($submitted_key !== '') {
			update_option('aipt_api_key', $submitted_key, false);
		}
		wp_send_json_success();
	}

	public function render_page(): void {
		$settings = self::get();
		$key      = self::api_key();
		?>
		<div class="wrap aipt-settings">
			<h1><?php esc_html_e('AI Translator (Polylang)', 'ai-polylang-translator'); ?></h1>
			<form method="post" action="options.php">
				<?php settings_fields('aipt'); ?>

				<div class="aipt-panel">
					<h2 class="aipt-panel-title"><?php esc_html_e('API', 'ai-polylang-translator'); ?></h2>
					<table class="form-table" role="presentation">
						<tr>
							<th scope="row"><label for="aipt-api-key"><?php esc_html_e('Vercel AI Gateway API key', 'ai-polylang-translator'); ?></label></th>
							<td>
								<input type="password" id="aipt-api-key" name="aipt_api_key" value="" class="regular-text" autocomplete="new-password"
									placeholder="<?php echo esc_attr($key !== '' ? self::mask_key($key) : __('Paste your key here', 'ai-polylang-translator')); ?>">
								<span id="aipt-key-saved" class="dashicons dashicons-yes-alt aipt-key-saved"
									title="<?php esc_attr_e('Key saved', 'ai-polylang-translator'); ?>"
									aria-label="<?php esc_attr_e('Key saved', 'ai-polylang-translator'); ?>"
									<?php echo $key === '' ? 'hidden' : ''; ?>></span>
								<button type="button" class="button" id="aipt-test-key"><?php esc_html_e('Test key', 'ai-polylang-translator'); ?></button>
								<span id="aipt-test-result"></span>
								<p class="description">
									<?php if ($key !== '') : ?>
										<?php esc_html_e('Key saved. Leave the field empty to keep the existing key. A successful test with a new key also saves it.', 'ai-polylang-translator'); ?>
									<?php else : ?>
										<?php echo wp_kses_post(__('Get your key from <a href="https://vercel.com/ai-gateway" target="_blank" rel="noopener">Vercel AI Gateway</a>. A successful test will save the entered key.', 'ai-polylang-translator')); ?>
									<?php endif; ?>
								</p>
							</td>
						</tr>
						<tr>
							<th scope="row"><label for="aipt-model"><?php esc_html_e('Model', 'ai-polylang-translator'); ?></label></th>
							<td>
								<select id="aipt-model" name="aipt_settings[model]">
									<?php foreach (self::models() as $id => $label) : ?>
										<option value="<?php echo esc_attr($id); ?>" <?php selected($settings['model'], $id); ?>><?php echo esc_html($label); ?></option>
									<?php endforeach; ?>
								</select>
							</td>
						</tr>
					</table>
				</div>

				<?php $this->render_usage_panel(); ?>

				<details class="aipt-panel aipt-advanced" <?php echo empty($settings['field_overrides']) ? '' : 'open'; ?>>
					<summary><h2 class="aipt-panel-title"><?php esc_html_e('Advanced settings', 'ai-polylang-translator'); ?></h2></summary>

					<table class="form-table" role="presentation">
						<tr>
							<th scope="row"><?php esc_html_e('Post types', 'ai-polylang-translator'); ?></th>
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
								<p class="description"><?php esc_html_e('The translation metabox will only appear on the selected post types.', 'ai-polylang-translator'); ?></p>
							</td>
						</tr>
						<tr>
							<th scope="row"><label for="aipt-context"><?php esc_html_e('Site context for the translator', 'ai-polylang-translator'); ?></label></th>
							<td>
								<textarea id="aipt-context" name="aipt_settings[site_context]" rows="2" class="large-text"
									placeholder="<?php esc_attr_e('E.g.: furniture online store — friendly tone, keep brand names untranslated', 'ai-polylang-translator'); ?>"><?php echo esc_textarea($settings['site_context']); ?></textarea>
								<p class="description"><?php esc_html_e('Appended to the prompt — helps the model maintain topic and tone.', 'ai-polylang-translator'); ?></p>
							</td>
						</tr>
						<?php if (aipt_yoast_active()) : ?>
							<tr>
								<th scope="row"><?php esc_html_e('Yoast SEO', 'ai-polylang-translator'); ?></th>
								<td>
									<label>
										<input type="checkbox" name="aipt_settings[translate_yoast]" value="1" <?php checked($settings['translate_yoast']); ?>>
										<?php esc_html_e('Translate SEO title, description and focus keyphrase', 'ai-polylang-translator'); ?>
									</label>
								</td>
							</tr>
						<?php endif; ?>
						<tr>
							<th scope="row"><label for="aipt-timeout"><?php esc_html_e('Request timeout (seconds)', 'ai-polylang-translator'); ?></label></th>
							<td><input type="number" id="aipt-timeout" name="aipt_settings[timeout]" value="<?php echo esc_attr($settings['timeout']); ?>" min="30" max="300" step="5"></td>
						</tr>
						<?php if (aipt_acf_active()) : ?>
							<tr>
								<th scope="row"><?php esc_html_e('ACF fields to translate', 'ai-polylang-translator'); ?></th>
								<td>
									<p class="description"><?php esc_html_e('Checked text fields are translated; unchecked fields (images, numbers, relationships, etc.) are copied as-is. New fields are translated by default.', 'ai-polylang-translator'); ?></p>
									<?php echo AIPT_ACF_Schema::render_tree_html($settings['field_overrides']); // phpcs:ignore WordPress.Security.EscapeOutput ?>
								</td>
							</tr>
						<?php endif; ?>
					</table>
				</details>

				<?php submit_button(__('Save settings', 'ai-polylang-translator')); ?>
			</form>

			<?php $this->render_models_info_panel(); ?>
		</div>
		<?php
	}

	// Client-facing cheat sheet: which model to pick and roughly what it costs.
	private function render_models_info_panel(): void {
		?>
			<div class="aipt-panel">
				<h2 class="aipt-panel-title"><?php esc_html_e('Which model to choose?', 'ai-polylang-translator'); ?></h2>
				<table class="widefat striped">
					<thead>
						<tr>
							<th><?php esc_html_e('Model', 'ai-polylang-translator'); ?></th>
							<th><?php esc_html_e('Best for', 'ai-polylang-translator'); ?></th>
							<th><?php esc_html_e('Price per article', 'ai-polylang-translator'); ?></th>
						</tr>
					</thead>
					<tbody>
						<?php foreach (self::model_catalog() as $model) : ?>
						<tr>
							<td><strong><?php echo esc_html($model['name']); ?></strong></td>
							<td><?php echo esc_html($model['desc']); ?></td>
							<td>~<?php echo esc_html($model['price']); ?></td>
						</tr>
						<?php endforeach; ?>
					</tbody>
				</table>
				<p class="description">
					<?php esc_html_e('Prices are approximate, for a typical article of about 3,000 characters. Longer pages cost proportionally more; the exact amount for every translation is shown in the cost log above.', 'ai-polylang-translator'); ?>
				</p>
			</div>
		<?php
	}

	private function render_usage_panel(): void {
		if (!class_exists('AIPT_Usage')) {
			return;
		}
		?>
			<div class="aipt-panel">
				<h2 class="aipt-panel-title"><?php esc_html_e('Translation costs', 'ai-polylang-translator'); ?></h2>
				<?php
				$totals = AIPT_Usage::totals();
				$log    = AIPT_Usage::log();
				?>
				<div class="aipt-stats">
					<div class="aipt-stat">
						<span class="aipt-stat-label"><?php esc_html_e('Total spent', 'ai-polylang-translator'); ?></span>
						<span class="aipt-stat-value"><?php echo esc_html(self::format_cost($totals['total_cost'])); ?></span>
					</div>
					<div class="aipt-stat">
						<span class="aipt-stat-label"><?php esc_html_e('Spent today', 'ai-polylang-translator'); ?></span>
						<span class="aipt-stat-value"><?php echo esc_html(self::format_cost($totals['day_cost'])); ?></span>
					</div>
					<div class="aipt-stat">
						<span class="aipt-stat-label"><?php esc_html_e('Translations', 'ai-polylang-translator'); ?></span>
						<span class="aipt-stat-value"><?php echo esc_html(number_format_i18n($totals['jobs'])); ?></span>
					</div>
				</div>

				<?php if (empty($log)) : ?>
					<p><?php esc_html_e('No translations yet. Costs will appear here after the first translation.', 'ai-polylang-translator'); ?></p>
				<?php else : ?>
					<table class="widefat striped">
						<thead>
							<tr>
								<th><?php esc_html_e('Date', 'ai-polylang-translator'); ?></th>
								<th><?php esc_html_e('Post', 'ai-polylang-translator'); ?></th>
								<th><?php esc_html_e('Language', 'ai-polylang-translator'); ?></th>
								<th><?php esc_html_e('Model', 'ai-polylang-translator'); ?></th>
								<th><?php esc_html_e('Tokens', 'ai-polylang-translator'); ?></th>
								<th><?php esc_html_e('Cost', 'ai-polylang-translator'); ?></th>
							</tr>
						</thead>
						<tbody>
							<?php
							$date_format = get_option('date_format') . ' H:i';
							$catalog     = self::model_catalog();
							foreach ($log as $entry) :
								$date      = wp_date($date_format, $entry['time']);
								$edit_link = get_edit_post_link($entry['post_id']) ?: '';
								$model_short = $catalog[$entry['model']]['name']
									?? (strpos($entry['model'], '/') !== false
										? substr($entry['model'], strrpos($entry['model'], '/') + 1)
										: $entry['model']);
							?>
							<tr>
								<td><?php echo esc_html($date); ?></td>
								<td>
									<?php if ($edit_link) : ?>
										<a href="<?php echo esc_url($edit_link); ?>"><?php echo esc_html($entry['title']); ?></a>
									<?php else : ?>
										<?php echo esc_html($entry['title']); ?>
									<?php endif; ?>
								</td>
								<td><?php echo esc_html(strtoupper($entry['target'])); ?></td>
								<td><?php echo esc_html($model_short); ?></td>
								<td><?php echo esc_html(number_format_i18n($entry['tokens_in']) . ' → ' . number_format_i18n($entry['tokens_out'])); ?></td>
								<td><?php echo esc_html(self::format_cost($entry['cost'])); ?></td>
							</tr>
							<?php endforeach; ?>
						</tbody>
					</table>
				<?php endif; ?>
			</div>
		<?php
	}
}
