<?php
// WooCommerce custom (non-taxonomy) product attributes. Post meta _product_attributes
// holds key => [name, value, position, is_visible, is_variation, is_taxonomy]; taxonomy
// attributes (pa_*) are terms and are copied verbatim. Item paths:
//   ['woo', 'attr', key, 'name']       the attribute label
//   ['woo', 'attr', key, 'value', i]   option i of the WC_DELIMITER-separated value
// The job carries the source's raw array ('woo' => ['attributes' => ...]) and the writer
// rebuilds from it, never from the live source. Names stay the source names (WooCommerce
// Multilingual rebuilds them from the original); translated labels go to the translated
// product's attr_label_translations meta, [lang => [key => label]], which is where
// WooCommerce Multilingual reads them from.
// The attribute key set always follows the source: WooCommerce Multilingual rebuilds the
// translation's attributes from the original's keys (Component/Attributes.php:74-98), so
// attributes only the translation has are not kept, in safe mode either.
// Safe mode keeps filled target values (an option at the same index, a label for the key):
// such paths are not extracted but recorded in 'preserve' like ACF/meta paths, with the
// same AIPT_Safe_Merge helpers (the writer's own preserve handling only covers post, meta
// and ACF paths). Model output here is plain text (sanitize_text_field), never wp_kses_post.

if (!defined('ABSPATH')) {
	exit;
}

class AIPT_Woo {

	const META_ATTRIBUTES = '_product_attributes';
	const META_LABELS     = 'attr_label_translations';

	public static function active(): bool {
		return class_exists('WooCommerce');
	}

	/**
	 * @param int    $target_id Existing translation whose filled values are kept (safe mode), or 0.
	 * @param string $target    Target language code.
	 * @return array{items: array, preserve: array, snapshot: array} items are
	 *         ['path' => ..., 'text' => ...]; snapshot is [] or ['attributes' => raw array].
	 */
	public static function extract(WP_Post $post, int $target_id, string $target): array {
		$out = array('items' => array(), 'preserve' => array(), 'snapshot' => array());
		if (!self::active() || $post->post_type !== 'product') {
			return $out;
		}
		$attributes = get_post_meta($post->ID, self::META_ATTRIBUTES, true);
		if (!is_array($attributes) || !$attributes) {
			return $out;
		}
		$out['snapshot'] = array('attributes' => $attributes);

		$target_attributes = array();
		$target_labels     = array();
		if ($target_id) {
			$target_attributes = get_post_meta($target_id, self::META_ATTRIBUTES, true);
			$target_attributes = is_array($target_attributes) ? $target_attributes : array();
			$target_labels     = self::labels_for(self::label_meta($target_id), $target);
		}

		foreach ($attributes as $key => $attribute) {
			if (!self::is_text_attribute($attribute)) {
				continue;
			}
			$key = (string) $key;

			$path  = array('woo', 'attr', $key, 'name');
			$label = (string) ($target_labels[$key] ?? '');
			$name  = (string) ($attribute['name'] ?? '');
			$kept  = $target_id && AIPT_Safe_Merge::preserve_value($path, $label, array(), $out['preserve']);
			if (!$kept && AIPT_Extractor::is_translatable($name)) {
				$out['items'][] = array('path' => $path, 'text' => $name);
			}

			$target_options = self::text_options($target_attributes[$key] ?? null);
			foreach (self::text_options($attribute) as $i => $option) {
				$path = array('woo', 'attr', $key, 'value', $i);
				if ($target_id && AIPT_Safe_Merge::preserve_value($path, $target_options[$i] ?? '', array(), $out['preserve'])) {
					continue;
				}
				if (AIPT_Extractor::is_translatable($option)) {
					$out['items'][] = array('path' => $path, 'text' => $option);
				}
			}
		}
		return $out;
	}

	/**
	 * Rebuild _product_attributes on the translation from the job snapshot and store the
	 * translated labels. No-op for jobs without a snapshot (prepared before 1.6, or not a
	 * product). $results are raw model output keyed by item id; $preserve_map is the
	 * writer's AIPT_Safe_Merge::preserve_map() of the job.
	 */
	public static function write(int $post_id, array $job, array $results, bool $safe, array $preserve_map): void {
		$attributes = $job['woo']['attributes'] ?? null;
		if (!is_array($attributes) || !$attributes) {
			return;
		}
		$target = (string) $job['target'];

		$translated = array();
		foreach ((array) $job['items'] as $id => $item) {
			$path = $item['path'] ?? array();
			if (($path[0] ?? '') === 'woo' && array_key_exists($id, $results)) {
				$translated[wp_json_encode($path)] = (string) $results[$id];
			}
		}

		$current = $safe ? get_post_meta($post_id, self::META_ATTRIBUTES, true) : array();
		$current = is_array($current) ? $current : array();
		$meta    = self::label_meta($post_id);
		$labels  = self::labels_for($meta, $target);

		$rebuilt = array();
		foreach ($attributes as $key => $attribute) {
			if (!self::is_text_attribute($attribute)) {
				$rebuilt[$key] = $attribute;
				continue;
			}
			$skey = (string) $key;

			// $current is empty outside safe mode.
			$current_options = self::text_options($current[$key] ?? null);
			$options         = array();
			foreach (self::text_options($attribute) as $i => $option) {
				$path      = array('woo', 'attr', $skey, 'value', $i);
				$json      = wp_json_encode($path);
				$text      = isset($translated[$json]) ? self::clean_option($translated[$json]) : '';
				$options[] = (string) AIPT_Safe_Merge::preserved_value($path, $current_options[$i] ?? '', $text !== '' ? $text : $option, $preserve_map);
			}
			$attribute['value'] = implode(' ' . self::delimiter() . ' ', $options);
			$rebuilt[$key]      = $attribute;

			$path  = array('woo', 'attr', $skey, 'name');
			$json  = wp_json_encode($path);
			$label = isset($translated[$json]) ? sanitize_text_field($translated[$json]) : '';
			// Outside safe mode the translation's own labels are replaced.
			$label = AIPT_Safe_Merge::preserved_value($path, $safe ? ($labels[$skey] ?? '') : '', $label, $preserve_map);
			if ($label !== '') {
				$labels[$skey] = $label;
			} elseif (!$safe) {
				unset($labels[$skey]);
			}
		}
		update_post_meta($post_id, self::META_ATTRIBUTES, wp_slash($rebuilt));

		if ($labels) {
			$meta[$target] = $labels;
		} else {
			unset($meta[$target]);
		}
		if ($meta) {
			update_post_meta($post_id, self::META_LABELS, wp_slash($meta));
		} else {
			delete_post_meta($post_id, self::META_LABELS);
		}
	}

	private static function is_text_attribute($attribute): bool {
		return is_array($attribute) && empty($attribute['is_taxonomy']);
	}

	/**
	 * Trimmed, non-empty options of a text attribute (as WooCommerce splits them), [] otherwise.
	 *
	 * @return string[]
	 */
	private static function text_options($attribute): array {
		if (!self::is_text_attribute($attribute) || !is_scalar($attribute['value'] ?? null)) {
			return array();
		}
		$options = array_map('trim', explode(self::delimiter(), (string) $attribute['value']));
		return array_values(array_filter($options, static fn(string $option): bool => $option !== ''));
	}

	// Plain text like WooCommerce stores (wc_clean); the delimiter would split the option.
	private static function clean_option(string $text): string {
		return trim(str_replace(self::delimiter(), '/', sanitize_text_field($text)));
	}

	private static function delimiter(): string {
		return defined('WC_DELIMITER') ? (string) WC_DELIMITER : '|';
	}

	private static function label_meta(int $post_id): array {
		$meta = get_post_meta($post_id, self::META_LABELS, true);
		return is_array($meta) ? $meta : array();
	}

	private static function labels_for(array $meta, string $target): array {
		return isset($meta[$target]) && is_array($meta[$target]) ? $meta[$target] : array();
	}
}
