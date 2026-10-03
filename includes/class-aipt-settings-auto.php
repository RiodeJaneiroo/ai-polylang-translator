<?php
// "Automatic translation" section of Settings > AI Translator: the auto_translate and
// auto_languages settings (rendering, sanitizing, and the effective language list).

if (!defined('ABSPATH')) {
	exit;
}

class AIPT_Settings_Auto {

	// Languages auto-translation can target: every site language but the default, code =>
	// name. None while no multilingual adapter is loaded (settings-only mode).
	public static function candidates(): array {
		if (!AIPT_Lang_Loader::active()) {
			return array();
		}
		$default    = aipt_lang()->default_language();
		$candidates = array();
		foreach (aipt_lang()->languages() as $language) {
			if ($language['code'] !== $default) {
				$candidates[$language['code']] = $language['name'];
			}
		}
		return $candidates;
	}

	// Selected target languages. 'all' (every box ticked) and null (never saved, e.g.
	// settings from 1.4.0) mean every non-default language, including ones added to
	// the multilingual plugin later; [] means none.
	public static function languages(array $settings): array {
		$candidates = array_keys(self::candidates());
		if (!is_array($settings['auto_languages'] ?? null)) {
			return $candidates;
		}
		return array_values(array_intersect($candidates, $settings['auto_languages']));
	}

	/**
	 * @return array auto_translate and auto_languages ('all', a list, or null) for the
	 *               sanitized settings.
	 */
	public static function sanitize(array $value, array $old): array {
		$languages = $old['auto_languages'] ?? null;
		// The form always posts the key (a hidden empty entry) when it shows languages;
		// without it (no second language yet, or no adapter) the stored choice is kept.
		if (array_key_exists('auto_languages', $value) && AIPT_Lang_Loader::active()) {
			$candidates = array_keys(self::candidates());
			$posted     = $value['auto_languages'];
			// 'all' arrives when a sanitized value is sanitized again (first save goes through add_option).
			if ($posted === 'all') {
				$posted = $candidates;
			}
			$posted    = is_array($posted) ? array_filter($posted, 'is_string') : array();
			$languages = array_values(array_intersect($candidates, $posted));
			if (count($languages) === count($candidates)) {
				$languages = 'all';
			}
		}
		return array(
			'auto_translate' => !empty($value['auto_translate']),
			'auto_languages' => $languages,
		);
	}

	public static function render(array $settings): void {
		$candidates = self::candidates();
		$selected   = self::languages($settings);
		?>
			<div class="aipt-panel">
				<h2 class="aipt-panel-title"><?php esc_html_e('Automatic translation', 'ai-polylang-translator'); ?></h2>
				<table class="form-table" role="presentation">
					<tr>
						<th scope="row"><?php esc_html_e('On publish', 'ai-polylang-translator'); ?></th>
						<td>
							<label>
								<input type="checkbox" name="aipt_settings[auto_translate]" value="1" <?php checked(!empty($settings['auto_translate'])); ?>>
								<?php esc_html_e('Translate automatically on publish', 'ai-polylang-translator'); ?>
							</label>
							<p class="description"><?php esc_html_e('New posts of the enabled post types in the default language are translated in the background (WP-Cron) into the selected languages and published with the source date. Later edits of the source are not re-translated — use “Update translation” in the AI Translation box of the post.', 'ai-polylang-translator'); ?></p>
						</td>
					</tr>
					<?php if ($candidates) : ?>
						<tr>
							<th scope="row"><?php esc_html_e('Languages', 'ai-polylang-translator'); ?></th>
							<td>
								<input type="hidden" name="aipt_settings[auto_languages][]" value="">
								<?php foreach ($candidates as $slug => $name) : ?>
									<label class="aipt-cpt">
										<input type="checkbox" name="aipt_settings[auto_languages][]" value="<?php echo esc_attr($slug); ?>"
											<?php checked(in_array($slug, $selected, true)); ?>>
										<?php echo esc_html($name); ?> <code><?php echo esc_html($slug); ?></code>
									</label><br>
								<?php endforeach; ?>
							</td>
						</tr>
					<?php endif; ?>
				</table>
			</div>
		<?php
	}
}
