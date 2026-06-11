<?php
// Writes the translation. Order matters: insert → pll_set_post_language → meta →
// ACF → taxonomies → pll_save_post_translations last, so no Polylang sync-on-save
// can touch a half-built post.

if (!defined('ABSPATH')) {
	exit;
}

class AIPT_Writer {

	/**
	 * @return int|WP_Error Translation post ID.
	 */
	public static function write(array $job) {
		$source = get_post((int) $job['post_id']);
		if (!$source) {
			return new WP_Error('aipt_no_source', __('Исходная запись не найдена.', 'ai-polylang-translator'));
		}

		$target   = (string) $job['target'];
		$existing = (int) ($job['existing'] ?? 0);
		if (!$existing || !get_post($existing)) {
			$existing = (int) (pll_get_post($source->ID, $target) ?: 0);
		}

		if ($existing && !current_user_can('edit_post', $existing)) {
			return new WP_Error('aipt_cannot_edit_translation', __('Недостаточно прав для изменения существующего перевода.', 'ai-polylang-translator'));
		}
		if (!$existing) {
			$post_type = get_post_type_object($source->post_type);
			if (!$post_type || !current_user_can($post_type->cap->create_posts)) {
				return new WP_Error('aipt_cannot_create_translation', __('Недостаточно прав для создания перевода.', 'ai-polylang-translator'));
			}
		}

		$sync_state = self::suspend_polylang_sync();
		try {
			return self::write_translation($job, $source, $target, $existing);
		} finally {
			self::restore_polylang_sync($sync_state);
		}
	}

	/**
	 * @return int|WP_Error Translation post ID.
	 */
	private static function write_translation(array $job, WP_Post $source, string $target, int $existing) {
		$results = $job['results'];
		$tree    = $job['tree'];

		$title          = $source->post_title;
		$excerpt        = $source->post_excerpt;
		$content_chunks = array();
		$meta           = (array) ($job['meta'] ?? array());
		$acf_chunks     = array();

		foreach ($job['items'] as $id => $item) {
			if (!array_key_exists($id, $results)) {
				return new WP_Error('aipt_incomplete', __('Перевод не завершён — отсутствуют части текста.', 'ai-polylang-translator'));
			}
			$text = (string) $results[$id];
			$path = $item['path'];

			switch ($path[0]) {
				case 'post':
					if ($path[1] === 'title') {
						$title = $text;
					} elseif ($path[1] === 'excerpt') {
						$excerpt = $text;
					} elseif ($path[1] === 'content') {
						$content_chunks[(int) $path[2]] = $text;
					}
					break;

				case 'meta':
					$meta[$path[1]] = $text;
					break;

				case 'acf':
					$count = count($path);
					if ($count >= 3 && $path[$count - 2] === '#chunk') {
						$base = array_slice($path, 1, $count - 3);
						$acf_chunks[wp_json_encode($base)][(int) $path[$count - 1]] = $text;
					} else {
						self::set_path($tree, array_slice($path, 1), $text);
					}
					break;
			}
		}

		foreach ($acf_chunks as $base_json => $chunks) {
			ksort($chunks);
			self::set_path($tree, json_decode($base_json, true), implode('', $chunks));
		}

		ksort($content_chunks);
		$content = $content_chunks ? implode('', $content_chunks) : $source->post_content;

		if ($existing) {
			$postarr = array(
				'ID'           => $existing,
				'post_title'   => $title,
				'post_content' => $content,
				'post_excerpt' => $excerpt,
			);
			// Status and slug of an existing translation are kept — its URLs may be indexed.
			$new_id = wp_update_post(wp_slash($postarr), true);
		} else {
			$parent = 0;
			if ($source->post_parent) {
				$parent = (int) (pll_get_post($source->post_parent, $target) ?: 0);
			}
			$postarr = array(
				'post_title'     => $title,
				'post_content'   => $content,
				'post_excerpt'   => $excerpt,
				'post_status'    => 'draft',
				'post_type'      => $source->post_type,
				'post_parent'    => $parent,
				'menu_order'     => $source->menu_order,
				'comment_status' => $source->comment_status,
				'ping_status'    => $source->ping_status,
				'post_name'      => sanitize_title($title),
			);
			$new_id = wp_insert_post(wp_slash($postarr), true);
		}

		if (is_wp_error($new_id)) {
			return $new_id;
		}
		$new_id = (int) $new_id;

		pll_set_post_language($new_id, $target);

		foreach ($job['remap'] as $entry) {
			$path  = array_slice($entry['path'], 1);
			$value = self::get_path($tree, $path);
			if ($value === null) {
				continue;
			}
			self::set_path(
				$tree,
				$path,
				self::remap_ids($value, $entry['kind'], $target, $source->ID, $new_id)
			);
		}

		$template = get_post_meta($source->ID, '_wp_page_template', true);
		if ($template) {
			update_post_meta($new_id, '_wp_page_template', $template);
		}
		$thumb_id = get_post_thumbnail_id($source->ID);
		if ($thumb_id) {
			set_post_thumbnail($new_id, $thumb_id);
		}
		foreach ($meta as $meta_key => $meta_value) {
			if ($meta_value === '') {
				delete_post_meta($new_id, $meta_key);
			} else {
				update_post_meta($new_id, $meta_key, wp_slash($meta_value));
			}
		}

		if (aipt_acf_active()) {
			foreach ($tree as $field_key => $value) {
				update_field($field_key, $value, $new_id);
			}
		}

		self::copy_taxonomies($source, $new_id, $target);

		$translations = pll_get_post_translations($source->ID);
		$source_lang  = pll_get_post_language($source->ID);
		if ($source_lang) {
			$translations[$source_lang] = $source->ID;
		}
		$translations[$target] = $new_id;
		pll_save_post_translations($translations);

		return $new_id;
	}

	private static function copy_taxonomies(WP_Post $source, int $new_id, string $target): void {
		foreach (get_object_taxonomies($source->post_type) as $taxonomy) {
			// Polylang service taxonomies must never be copied.
			if (in_array($taxonomy, array('language', 'post_translations', 'term_language', 'term_translations'), true)) {
				continue;
			}
			$terms = wp_get_object_terms($source->ID, $taxonomy, array('fields' => 'ids'));
			if (is_wp_error($terms)) {
				continue;
			}
			if (function_exists('pll_is_translated_taxonomy') && pll_is_translated_taxonomy($taxonomy)) {
				$mapped = array();
				foreach ($terms as $term_id) {
					$translated = pll_get_term((int) $term_id, $target);
					if ($translated) {
						$mapped[] = (int) $translated;
					}
				}
				wp_set_object_terms($new_id, $mapped, $taxonomy);
			} else {
				wp_set_object_terms($new_id, array_map('intval', $terms), $taxonomy);
			}
		}
	}

	private static function remap_ids($value, string $kind, string $target, int $source_id, int $new_id) {
		$map = static function ($id) use ($kind, $target, $source_id, $new_id) {
			if (!is_numeric($id)) {
				return $id; // page_link may hold a URL
			}
			if ($kind === 'post' && (int) $id === $source_id) {
				return $new_id;
			}
			$translated = ($kind === 'term') ? pll_get_term((int) $id, $target) : pll_get_post((int) $id, $target);
			return $translated ?: (int) $id;
		};
		return is_array($value) ? array_map($map, $value) : $map($value);
	}

	private static function suspend_polylang_sync(): array {
		$state = array();
		if (!function_exists('PLL')) {
			return $state;
		}

		$polylang = PLL();
		if (!is_object($polylang) || empty($polylang->sync) || !is_object($polylang->sync)) {
			return $state;
		}

		$sync = $polylang->sync;
		if (has_action('pll_save_post', array($sync, 'pll_save_post')) !== false) {
			remove_action('pll_save_post', array($sync, 'pll_save_post'), 10);
			$state['post'] = $sync;
		}

		if (!empty($sync->post_metas) && is_object($sync->post_metas)) {
			$post_metas = $sync->post_metas;
			foreach (array('add', 'update', 'delete') as $operation) {
				$hook = $operation . '_post_metadata';
				if (has_filter($hook, array($post_metas, 'can_synchronize_metadata')) !== false) {
					remove_filter($hook, array($post_metas, 'can_synchronize_metadata'), 1);
					$state['post_meta_guards'][$hook] = $post_metas;
				}
			}
			if (has_action('pll_save_post', array($post_metas, 'save_object')) !== false) {
				remove_action('pll_save_post', array($post_metas, 'save_object'), 10);
				$state['post_metas_save'] = $post_metas;
			}
			if (has_action('added_post_meta', array($post_metas, 'add_meta')) !== false
				&& is_callable(array($post_metas, 'remove_all_meta_actions'))) {
				$post_metas->remove_all_meta_actions();
				$state['post_metas'] = $post_metas;
			}
		}

		if (!empty($sync->taxonomies) && is_object($sync->taxonomies)
			&& has_action('set_object_terms', array($sync->taxonomies, 'set_object_terms')) !== false) {
			remove_action('set_object_terms', array($sync->taxonomies, 'set_object_terms'), 10);
			$state['taxonomies'] = $sync->taxonomies;
		}

		return $state;
	}

	private static function restore_polylang_sync(array $state): void {
		if (isset($state['post'])) {
			add_action('pll_save_post', array($state['post'], 'pll_save_post'), 10, 3);
		}
		if (isset($state['post_metas_save'])) {
			add_action('pll_save_post', array($state['post_metas_save'], 'save_object'), 10, 3);
		}
		if (isset($state['post_metas']) && is_callable(array($state['post_metas'], 'add_all_meta_actions'))) {
			$state['post_metas']->add_all_meta_actions();
		}
		foreach ($state['post_meta_guards'] ?? array() as $hook => $post_metas) {
			add_filter($hook, array($post_metas, 'can_synchronize_metadata'), 1, 3);
		}
		if (isset($state['taxonomies'])) {
			add_action('set_object_terms', array($state['taxonomies'], 'set_object_terms'), 10, 5);
		}
	}

	private static function set_path(array &$tree, array $path, $value): void {
		$ref  =& $tree;
		$last = count($path) - 1;
		foreach ($path as $i => $segment) {
			if ($i === $last) {
				$ref[$segment] = $value;
				return;
			}
			if (!isset($ref[$segment]) || !is_array($ref[$segment])) {
				return;
			}
			$ref =& $ref[$segment];
		}
	}

	private static function get_path(array $tree, array $path) {
		$current = $tree;
		foreach ($path as $segment) {
			if (!is_array($current) || !array_key_exists($segment, $current)) {
				return null;
			}
			$current = $current[$segment];
		}
		return $current;
	}
}
