<?php
// Advanced settings rows kept out of AIPT_Settings: "Extra meta keys to translate"
// (plain-text post meta translated like the Yoast keys, item path ['meta', key], see
// AIPT_Extractor), "Copy meta keys" / "Copy term meta keys" (copied verbatim to
// translations by AIPT_Meta_Copy; a trailing * matches a prefix) and, on WPML sites,
// the native-editor switch (AIPT_Lang_WPML_TM).

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
		return array_merge(
			self::sanitize_meta_keys($value, $old),
			self::sanitize_copy_keys($value, $old, 'copy_meta_keys'),
			self::sanitize_copy_keys($value, $old, 'copy_term_meta_keys'),
			array('native_editor' => $native)
		);
	}

	private static function on_wpml(): bool {
		$lang = AIPT_Lang_Loader::active();
		return $lang && $lang->name() === 'wpml';
	}

	private static function sanitize_meta_keys(array $value, array $old): array {
		$keys = self::parse_keys($value, $old, 'extra_meta_keys', '/^[A-Za-z0-9_\-]+$/');
		return array('extra_meta_keys' => array_values(array_filter($keys, static fn(string $key): bool => !self::is_handled($key))));
	}

	// Copy lists keep handled keys: AIPT_Meta_Copy skips them at copy time, when the set
	// of translated keys is known.
	private static function sanitize_copy_keys(array $value, array $old, string $field): array {
		return array($field => self::parse_keys($value, $old, $field, '/^[A-Za-z0-9_\-]*\*?$/'));
	}

	/**
	 * One key per line, valid against $pattern, without duplicates; the stored list is
	 * kept when the form did not post the field.
	 *
	 * @return string[]
	 */
	private static function parse_keys(array $value, array $old, string $field, string $pattern): array {
		if (!array_key_exists($field, $value)) {
			return array_values(array_filter((array) ($old[$field] ?? array()), 'is_string'));
		}
		$raw = $value[$field];
		// A list arrives when a sanitized value is sanitized again (first save goes through add_option).
		if (is_array($raw)) {
			$raw = implode("\n", array_filter($raw, 'is_string'));
		}

		$keys = array();
		foreach (preg_split('/\R/', (string) $raw) as $line) {
			$key = trim($line);
			if ($key === '' || !preg_match($pattern, $key) || in_array($key, $keys, true)) {
				continue;
			}
			$keys[] = $key;
		}
		return $keys;
	}

	// Meta keys translated or written by the plugin itself (also used by AIPT_Meta_Copy).
	public static function is_handled(string $key): bool {
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
		<?php
		/* translators: %s: example meta key pattern */
		self::render_copy_row($settings, 'copy_meta_keys', 'aipt-copy-meta', __('Copy meta keys', 'ai-polylang-translator'), '_kad_post_*', __('Custom fields copied to translations as they are (not translated), one meta key per line; a trailing * matches every key that starts with the text before it (e.g. %s). Copied when a translation is created or updated; the translation\'s values of a matching key are replaced with the source values. Keys that are translated (extra meta keys, Yoast SEO, ACF, WooCommerce attributes) and internal keys (featured image, page template, edit lock, old slugs, the plugin\'s and the multilingual plugin\'s own keys) are never copied.', 'ai-polylang-translator'));
		/* translators: %s: example term meta key */
		self::render_copy_row($settings, 'copy_term_meta_keys', 'aipt-copy-term-meta', __('Copy term meta keys', 'ai-polylang-translator'), 'thumbnail_id', __('Term meta copied to translated terms as it is, one meta key per line; a trailing * matches a prefix (e.g. %s). Copied when a translated term is created or linked; the multilingual plugin\'s own keys are never copied.', 'ai-polylang-translator'));
		?>
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

	private static function render_copy_row(array $settings, string $field, string $id, string $label, string $example, string $description): void {
		$keys = array_filter((array) ($settings[$field] ?? array()), 'is_string');
		?>
		<tr>
			<th scope="row"><label for="<?php echo esc_attr($id); ?>"><?php echo esc_html($label); ?></label></th>
			<td>
				<textarea id="<?php echo esc_attr($id); ?>" name="aipt_settings[<?php echo esc_attr($field); ?>]" rows="3" class="large-text code"
					placeholder="<?php echo esc_attr($example); ?>"><?php echo esc_textarea(implode("\n", $keys)); ?></textarea>
				<p class="description"><?php
					echo wp_kses(sprintf(esc_html($description), '<code>' . esc_html($example) . '</code>'), array('code' => array()));
				?></p>
			</td>
		</tr>
		<?php
	}
}
