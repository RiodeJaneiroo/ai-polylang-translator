<?php
// Writes the translation. Order matters: insert → pll_set_post_language → meta →
// ACF → taxonomies → pll_save_post_translations last, so no Polylang sync-on-save
// can touch a half-built post. Only a new translation then gets its final status and
// slug (finish_new_post), on the complete post and still with sync suspended.

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
			return new WP_Error('aipt_no_source', __('Source post not found.', 'ai-polylang-translator'));
		}

		$target          = (string) $job['target'];
		$prepared        = (int) ($job['existing'] ?? 0);
		$existing        = $prepared;
		// Re-check the target the user actually confirmed at prepare time. Never silently
		// overwrite a post that the confirmation screen never showed.
		if (!$prepared) {
			if ((int) (pll_get_post($source->ID, $target) ?: 0)) {
				return new WP_Error('aipt_target_changed', __('A translation into this language was created after the job was prepared. Please start the translation again.', 'ai-polylang-translator'));
			}
		} elseif (!get_post($prepared)) {
			return new WP_Error('aipt_target_changed', __('The prepared translation post was deleted. Please start the translation again.', 'ai-polylang-translator'));
		}

		if ($existing && !current_user_can('edit_post', $existing)) {
			return new WP_Error('aipt_cannot_edit_translation', __('You do not have permission to edit the existing translation.', 'ai-polylang-translator'));
		}
		if (!$existing) {
			$post_type = get_post_type_object($source->post_type);
			if (!$post_type || !current_user_can($post_type->cap->create_posts)) {
				return new WP_Error('aipt_cannot_create_translation', __('You do not have permission to create a translation.', 'ai-polylang-translator'));
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
		$results      = $job['results'];
		$tree         = $job['tree'];
		// post_content goes through wp_filter_post_kses on wp_update_post/wp_insert_post
		// for users without unfiltered_html, but ACF and meta writes do not — sanitize
		// model output ourselves unless the user may post raw HTML. Apply this only to
		// strings that actually came back from the model (the translated $text below),
		// never to values copied verbatim from the source tree/meta — running wp_kses_post
		// over untouched copies entity-encodes URL query strings (a&b → a&amp;b) and can
		// strip markup the source author saved with unfiltered_html.
		$kses         = !current_user_can('unfiltered_html');
		$safe         = $existing && ($job['mode'] ?? '') === 'safe';
		$target_post  = $safe ? get_post($existing) : null;
		$preserve_map = self::preserve_map((array) ($job['preserve'] ?? array()));

		$title          = $source->post_title;
		$excerpt        = $source->post_excerpt;
		$content_chunks = array();
		$meta           = (array) ($job['meta'] ?? array());
		$acf_chunks     = array();

		foreach ($job['items'] as $id => $item) {
			if (!array_key_exists($id, $results)) {
				return new WP_Error('aipt_incomplete', __('Translation is incomplete — some text parts are missing.', 'ai-polylang-translator'));
			}
			$text = (string) $results[$id];
			if ($kses) {
				$text = self::sanitize_translated($text);
			}
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

		if ($target_post) {
			$title = self::preserved_value(
				array('post', 'title'),
				$target_post->post_title,
				$title,
				$preserve_map
			);
			$excerpt = self::preserved_value(
				array('post', 'excerpt'),
				$target_post->post_excerpt,
				$excerpt,
				$preserve_map
			);
			$content = self::preserved_value(
				array('post', 'content'),
				$target_post->post_content,
				$content,
				$preserve_map
			);
		}

		$keep_date = !empty($job['keep_date']);
		$slug      = '';
		$parent    = 0;
		if ($existing) {
			$postarr = array(
				'ID'           => $existing,
				'post_title'   => $title,
				'post_content' => $content,
				'post_excerpt' => $excerpt,
			);
			// Status and slug of an existing translation are kept — its URLs may be indexed.
			if ($keep_date) {
				$postarr['post_date']     = $source->post_date;
				$postarr['post_date_gmt'] = $source->post_date_gmt;
				$postarr['edit_date']     = true;
			}
			$new_id = wp_update_post(wp_slash($postarr), true);
		} else {
			if ($source->post_parent) {
				$parent = (int) (pll_get_post($source->post_parent, $target) ?: 0);
			}
			$slug    = AIPT_Slug::for_post(AIPT_Slug::from_title($title), $target, 0, $source->post_type, $parent);
			$postarr = array(
				'post_title'     => $title,
				'post_content'   => $content,
				'post_excerpt'   => $excerpt,
				// Always created as a draft; a requested other status is applied by
				// finish_new_post() once the translation is complete (see there).
				'post_status'    => 'draft',
				'post_type'      => $source->post_type,
				'post_parent'    => $parent,
				'menu_order'     => $source->menu_order,
				'comment_status' => $source->comment_status,
				'ping_status'    => $source->ping_status,
				'post_name'      => $slug,
			);
			if ($keep_date) {
				$postarr['post_date']     = $source->post_date;
				$postarr['post_date_gmt'] = $source->post_date_gmt;
			}
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

		$template         = get_post_meta($source->ID, '_wp_page_template', true);
		$current_template = $safe ? get_post_meta($new_id, '_wp_page_template', true) : '';
		if ($template && (!$safe || !AIPT_Safe_Merge::has_value($current_template))) {
			update_post_meta($new_id, '_wp_page_template', $template);
		}
		$thumb_id         = get_post_thumbnail_id($source->ID);
		$current_thumb_id = $safe ? get_post_thumbnail_id($new_id) : 0;
		if ($thumb_id && (!$safe || !$current_thumb_id)) {
			set_post_thumbnail($new_id, $thumb_id);
		}
		foreach ($meta as $meta_key => $meta_value) {
			if ($safe) {
				$meta_value = self::preserved_value(
					array('meta', $meta_key),
					get_post_meta($new_id, $meta_key, true),
					$meta_value,
					$preserve_map
				);
			}
			if ($meta_value === '') {
				delete_post_meta($new_id, $meta_key);
			} else {
				update_post_meta($new_id, $meta_key, wp_slash($meta_value));
			}
		}

		if (aipt_acf_active()) {
			$target_tree = array();
			$flex_paths  = array();
			if ($safe) {
				$fields          = get_field_objects($source->ID, false);
				$target_tree     = self::get_acf_tree($new_id);
				$overwrite_paths = (array) ($job['overwrite'] ?? array());
				$flex_paths      = (array) ($job['flex'] ?? array());
				if (is_array($fields)) {
					$current = AIPT_Safe_Merge::collect_paths(
						array_values($fields),
						$tree,
						$target_tree
					);
					$overwrite_paths = self::unique_paths(array_merge($overwrite_paths, $current['overwrite']));
					$flex_paths      = self::unique_paths(array_merge($flex_paths, $current['flex']));
					$tree = AIPT_Safe_Merge::merge_acf(
						array_values($fields),
						$tree,
						$target_tree,
						$overwrite_paths,
						$flex_paths
					);
				}
				$tree = self::restore_preserved_acf(
					$tree,
					$target_tree,
					(array) ($job['preserve'] ?? array()),
					$overwrite_paths
				);
			} elseif ($existing) {
				$target_tree = self::get_acf_tree($new_id);
			}
			$tree = self::apply_skipped_acf(
				$tree,
				$target_tree,
				(array) ($job['skip'] ?? array()),
				$flex_paths,
				$existing > 0
			);
			foreach ($tree as $field_key => $value) {
				update_field($field_key, $value, $new_id);
			}
			if (!$existing) {
				foreach (self::top_level_skipped_fields((array) ($job['skip'] ?? array())) as $field_key) {
					// An omitted field can expose a non-empty ACF default_value. Store
					// an explicit empty value so "Don't touch" is empty on new posts.
					update_field($field_key, '', $new_id);
				}
			}
		}

		self::copy_taxonomies($source, $new_id, $target, $safe);

		$translations = pll_get_post_translations($source->ID);
		$source_lang  = pll_get_post_language($source->ID);
		if ($source_lang) {
			$translations[$source_lang] = $source->ID;
		}
		$translations[$target] = $new_id;
		pll_save_post_translations($translations);

		if (!$existing) {
			$finished = self::finish_new_post($new_id, $source, $target, $title, $parent, (string) ($job['post_status'] ?? 'draft'));
			if (is_wp_error($finished)) {
				return $finished;
			}
		}

		return $new_id;
	}

	/**
	 * A new translation is inserted as a draft and gets a requested non-draft status only
	 * here, once it is complete (language, meta, ACF, terms and translation group saved),
	 * so publish-time hooks — SEO indexables, sitemaps, notifications — see the finished
	 * post in its real language. Polylang sync is still suspended. The slug is re-checked
	 * now that the language is known: setups that share slugs across languages (e.g.
	 * Polylang Pro) can only tell at this point that no language suffix is needed.
	 *
	 * @return true|WP_Error 'aipt_finish_failed' carries the written post ID as
	 *                       ['post_id' => ID] error data: the translation exists and is linked.
	 */
	private static function finish_new_post(int $post_id, WP_Post $source, string $target, string $title, int $parent, string $status) {
		$postarr = array();
		// Compare with what WordPress actually stored, not with the slug we asked for.
		$stored = (string) get_post_field('post_name', $post_id);
		if ($stored !== '') {
			$final = AIPT_Slug::for_post(AIPT_Slug::from_title($title), $target, $post_id, $source->post_type, $parent);
			if ($final !== '' && $final !== $stored) {
				$postarr['post_name'] = $final;
			}
		}
		if ($status !== 'draft') {
			$postarr['post_status'] = $status;
		}
		if (!$postarr) {
			return true;
		}

		$postarr['ID'] = $post_id;
		$updated = wp_update_post(wp_slash($postarr), true);
		if (is_wp_error($updated)) {
			return new WP_Error('aipt_finish_failed', sprintf(
				/* translators: 1: translation post ID, 2: error message */
				__('Translation #%1$d was saved as a draft, but its status or slug could not be updated: %2$s', 'ai-polylang-translator'),
				$post_id,
				$updated->get_error_message()
			), array('post_id' => $post_id));
		}
		return true;
	}

	private static function copy_taxonomies(WP_Post $source, int $new_id, string $target, bool $safe): void {
		foreach (get_object_taxonomies($source->post_type) as $taxonomy) {
			// Polylang service taxonomies must never be copied.
			if (in_array($taxonomy, array('language', 'post_translations', 'term_language', 'term_translations'), true)) {
				continue;
			}
			if ($safe) {
				$current_terms = wp_get_object_terms($new_id, $taxonomy, array('fields' => 'ids'));
				if (!is_wp_error($current_terms) && $current_terms) {
					continue;
				}
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

	private static function preserve_map(array $entries): array {
		$map = array();
		foreach ($entries as $entry) {
			if (!is_array($entry)
				|| !isset($entry['path'])
				|| !is_array($entry['path'])
				|| !array_key_exists('value', $entry)) {
				continue;
			}
			$map[wp_json_encode($entry['path'])] = $entry['value'];
		}
		return $map;
	}

	private static function preserved_value(array $path, $current, $fallback, array $preserve_map) {
		if (AIPT_Safe_Merge::has_value($current)) {
			return $current;
		}
		$key = wp_json_encode($path);
		return array_key_exists($key, $preserve_map) ? $preserve_map[$key] : $fallback;
	}

	/**
	 * Run a single translated string through wp_kses_post before it lands in the tree/meta,
	 * mirroring the filtering wp_update_post applies to post_content for users without
	 * unfiltered_html. Applied only to model output at substitution time, never to values
	 * copied verbatim from the source.
	 */
	private static function sanitize_translated($value) {
		if (is_array($value)) {
			return array_map(array(__CLASS__, 'sanitize_translated'), $value);
		}
		return is_string($value) ? wp_kses_post($value) : $value;
	}

	private static function get_acf_tree(int $post_id): array {
		$tree   = array();
		$fields = get_field_objects($post_id, false);
		if (!is_array($fields)) {
			return $tree;
		}
		foreach ($fields as $field) {
			if (!empty($field['key'])) {
				$tree[$field['key']] = $field['value'];
			}
		}
		return $tree;
	}

	private static function unique_paths(array $paths): array {
		$unique = array();
		foreach ($paths as $path) {
			if (is_array($path)) {
				$unique[wp_json_encode($path)] = $path;
			}
		}
		return array_values($unique);
	}

	private static function restore_preserved_acf(
		array $tree,
		array $target_tree,
		array $preserve,
		array $overwrite_paths
	): array {
		foreach ($preserve as $entry) {
			if (!is_array($entry)) {
				continue;
			}
			$path = $entry['path'] ?? array();
			if (($path[0] ?? '') !== 'acf' || !array_key_exists('value', $entry)) {
				continue;
			}
			if (AIPT_Safe_Merge::is_overwritten($path, $overwrite_paths)) {
				continue;
			}
			$acf_path = array_slice($path, 1);
			$current  = self::get_path($target_tree, $acf_path);
			$value    = AIPT_Safe_Merge::has_value($current) ? $current : $entry['value'];
			self::set_path($tree, $acf_path, $value);
		}
		return $tree;
	}

	private static function apply_skipped_acf(
		array $tree,
		array $target_tree,
		array $skip,
		array $flex_paths,
		bool $existing_translation
	): array {
		foreach ($skip as $path) {
			if (!is_array($path) || ($path[0] ?? '') !== 'acf' || count($path) < 2) {
				continue;
			}

			$acf_path = array_slice($path, 1);
			if (count($path) === 2) {
				unset($tree[$acf_path[0]]);
			} elseif ($existing_translation) {
				$target_path  = self::aligned_target_path($acf_path, $tree, $target_tree, $flex_paths);
				$target_value = $target_path === null ? '' : self::get_path($target_tree, $target_path);
				self::set_path($tree, $acf_path, $target_value);
			} else {
				self::set_path($tree, $acf_path, '');
			}
		}
		return $tree;
	}

	private static function top_level_skipped_fields(array $skip): array {
		$fields = array();
		foreach ($skip as $path) {
			if (is_array($path) && count($path) === 2 && ($path[0] ?? '') === 'acf') {
				$fields[(string) $path[1]] = true;
			}
		}
		return array_keys($fields);
	}

	private static function aligned_target_path(array $source_path, array $tree, array $target_tree, array $flex_paths): ?array {
		usort($flex_paths, static fn(array $a, array $b): int => count($a) <=> count($b));
		$target_path = $source_path;

		foreach ($flex_paths as $flex_path) {
			if (!is_array($flex_path) || ($flex_path[0] ?? '') !== 'acf') {
				continue;
			}
			$flex_path = array_slice($flex_path, 1);
			$row_depth = count($flex_path);
			if (count($source_path) <= $row_depth
				|| array_slice($source_path, 0, $row_depth) !== $flex_path) {
				continue;
			}

			$source_index = $source_path[$row_depth];
			if (!is_int($source_index)) {
				continue;
			}

			$source_rows = self::get_path($tree, $flex_path);
			$target_rows = self::get_path($target_tree, array_slice($target_path, 0, $row_depth));
			if (!is_array($source_rows) || !is_array($target_rows) || !isset($source_rows[$source_index])) {
				return null;
			}

			$source_row = $source_rows[$source_index];
			$layout     = is_array($source_row) ? (string) ($source_row['acf_fc_layout'] ?? '') : '';
			if ($layout === '') {
				return null;
			}

			$matches = AIPT_Safe_Merge::align_flexible_rows(
				array_values($source_rows),
				array_values($target_rows)
			);
			if (!isset($matches[$source_index])) {
				return null;
			}
			$target_path[$row_depth] = $matches[$source_index];
		}

		return $target_path;
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

		$sync     = $polylang->sync;
		$priority = has_action('pll_save_post', array($sync, 'pll_save_post'));
		if ($priority !== false) {
			remove_action('pll_save_post', array($sync, 'pll_save_post'), $priority);
			$state['post']          = $sync;
			$state['post_priority'] = $priority;
		}

		if (!empty($sync->post_metas) && is_object($sync->post_metas)) {
			$post_metas = $sync->post_metas;
			foreach (array('add', 'update', 'delete') as $operation) {
				$hook     = $operation . '_post_metadata';
				$priority = has_filter($hook, array($post_metas, 'can_synchronize_metadata'));
				if ($priority !== false) {
					remove_filter($hook, array($post_metas, 'can_synchronize_metadata'), $priority);
					$state['post_meta_guards'][$hook] = array($post_metas, $priority);
				}
			}
			$priority = has_action('pll_save_post', array($post_metas, 'save_object'));
			if ($priority !== false) {
				remove_action('pll_save_post', array($post_metas, 'save_object'), $priority);
				$state['post_metas_save']          = $post_metas;
				$state['post_metas_save_priority'] = $priority;
			}
			if (has_action('added_post_meta', array($post_metas, 'add_meta')) !== false
				&& is_callable(array($post_metas, 'remove_all_meta_actions'))) {
				$post_metas->remove_all_meta_actions();
				$state['post_metas'] = $post_metas;
			}
		}

		if (!empty($sync->taxonomies) && is_object($sync->taxonomies)) {
			$priority = has_action('set_object_terms', array($sync->taxonomies, 'set_object_terms'));
			if ($priority !== false) {
				remove_action('set_object_terms', array($sync->taxonomies, 'set_object_terms'), $priority);
				$state['taxonomies']          = $sync->taxonomies;
				$state['taxonomies_priority'] = $priority;
			}
		}

		return $state;
	}

	private static function restore_polylang_sync(array $state): void {
		if (isset($state['post'])) {
			add_action('pll_save_post', array($state['post'], 'pll_save_post'), $state['post_priority'], 3);
		}
		if (isset($state['post_metas_save'])) {
			add_action('pll_save_post', array($state['post_metas_save'], 'save_object'), $state['post_metas_save_priority'], 3);
		}
		if (isset($state['post_metas']) && is_callable(array($state['post_metas'], 'add_all_meta_actions'))) {
			$state['post_metas']->add_all_meta_actions();
		}
		foreach ($state['post_meta_guards'] ?? array() as $hook => $guard) {
			list($post_metas, $priority) = $guard;
			add_filter($hook, array($post_metas, 'can_synchronize_metadata'), $priority, 3);
		}
		if (isset($state['taxonomies'])) {
			add_action('set_object_terms', array($state['taxonomies'], 'set_object_terms'), $state['taxonomies_priority'], 5);
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
