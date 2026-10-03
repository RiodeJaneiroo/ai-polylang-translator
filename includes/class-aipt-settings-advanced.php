<?php
// Advanced settings rows kept out of AIPT_Settings: "Extra meta keys to translate"
// (plain-text post meta translated like the Yoast keys, item path ['meta', key], see
// AIPT_Extractor) and, on WPML sites, the native-editor switch (AIPT_Lang_WPML_TM).

if (!defined('ABSPATH')) {
	exit;
}

class AIPT_Settings_Advanced {

	// Meta handled elsewhere (Yoast checkbox, WooCommerce attributes, plugin markers).
	const HANDLED_KEYS     = array(AIPT_Woo::META_ATTRIBUTES, AIPT_Woo::META_LABELS);
	const HANDLED_PREFIXES = array('_yoast_wpseo_', '_aipt_');

	/**
	 * @return array extra_meta_keys for the sanitized settings; the stored list is kept
	 *               when the form did not post the field.
	 */
	public static function sanitize(array $value, array $old): array {
		// The checkbox is only rendered on WPML sites; elsewhere the stored value is kept.
		$native = self::on_wpml() ? !empty($value['native_editor']) : (bool) ($old['native_editor'] ?? true);
		return array_merge(self::sanitize_meta_keys($value, $old), array('native_editor' => $native));
	}

	private static function on_wpml(): bool {
		$lang = AIPT_Lang_Loader::active();
		return $lang && $lang->name() === 'wpml';
	}

	private static function sanitize_meta_keys(array $value, array $old): array {
		if (!array_key_exists('extra_meta_keys', $value)) {
			return array('extra_meta_keys' => (array) ($old['extra_meta_keys'] ?? array()));
		}
		$raw = $value['extra_meta_keys'];
		// A list arrives when a sanitized value is sanitized again (first save goes through add_option).
		if (is_array($raw)) {
			$raw = implode("\n", array_filter($raw, 'is_string'));
		}

		$keys = array();
		foreach (preg_split('/\R/', (string) $raw) as $line) {
			$key = trim($line);
			if ($key === '' || !preg_match('/^[A-Za-z0-9_\-]+$/', $key) || self::is_handled($key) || in_array($key, $keys, true)) {
				continue;
			}
			$keys[] = $key;
		}
		return array('extra_meta_keys' => $keys);
	}

	private static function is_handled(string $key): bool {
		if (in_array($key, self::HANDLED_KEYS, true)) {
			return true;
		}
		foreach (self::HANDLED_PREFIXES as $prefix) {
			if (str_starts_with($key, $prefix)) {
				return true;
			}
		}
		return false;
	}

	public static function render(array $settings): void {
		$keys = array_filter((array) ($settings['extra_meta_keys'] ?? array()), 'is_string');
		?>
		<tr>
			<th scope="row"><label for="aipt-extra-meta"><?php esc_html_e('Extra meta keys to translate', 'ai-polylang-translator'); ?></label></th>
			<td>
				<textarea id="aipt-extra-meta" name="aipt_settings[extra_meta_keys]" rows="3" class="large-text code"
					placeholder="_price_unit"><?php echo esc_textarea(implode("\n", $keys)); ?></textarea>
				<p class="description"><?php
					echo wp_kses(sprintf(
						/* translators: %s: example meta key */
						esc_html__('Custom fields with plain text to translate, one meta key per line (e.g. %s). Only text values are translated; Yoast SEO fields are handled separately.', 'ai-polylang-translator'),
						'<code>_price_unit</code>'
					), array('code' => array()));
				?></p>
			</td>
		</tr>
		<?php if (self::on_wpml()) : ?>
			<tr>
				<th scope="row"><?php esc_html_e('WPML translation editor', 'ai-polylang-translator'); ?></th>
				<td>
					<label>
						<input type="checkbox" name="aipt_settings[native_editor]" value="1" <?php checked(!empty($settings['native_editor'])); ?>>
						<?php esc_html_e('Use the WordPress editor for translations written by AI Translator', 'ai-polylang-translator'); ?>
					</label>
					<p class="description"><?php esc_html_e('When the first AI translation of a post is written, the original post is switched to WPML\'s native-editor mode, so opening the translation in wp-admin does not redirect to WPML\'s translation editor.', 'ai-polylang-translator'); ?></p>
				</td>
			</tr>
		<?php endif;
	}
}
