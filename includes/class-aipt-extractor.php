<?php
// Collects translatable strings from a post. Item paths:
//   ['post', 'title' | 'excerpt'] | ['post', 'content', N]
//   ['meta', meta_key]
//   ['acf', field_key, row, sub_key, ...] (+ ['#chunk', N] suffix for long wysiwyg)
// Raw ACF values (format_value = false) keep repeater/flexible rows indexed by
// sub-field keys with 'acf_fc_layout' — the exact shape update_field() accepts.

if (!defined('ABSPATH')) {
	exit;
}

class AIPT_Extractor {

	const CHUNK_SIZE  = 8192;
	const BATCH_BYTES = 8192;
	const BATCH_ITEMS = 60;

	const YOAST_KEYS = array('_yoast_wpseo_title', '_yoast_wpseo_metadesc', '_yoast_wpseo_focuskw');

	/**
	 * @return array{items: array, tree: array, remap: array, meta: array, preserve: array, overwrite: array}
	 */
	public static function extract(int $post_id, int $target_id = 0, bool $safe = false): array {
		$post        = get_post($post_id);
		$target_post = $safe && $target_id ? get_post($target_id) : null;
		$settings    = AIPT_Settings::get();

		$items    = array();
		$preserve = array();
		$add      = static function (array $path, string $text) use (&$items): void {
			$id          = 's' . count($items);
			$items[$id] = array('id' => $id, 'path' => $path, 'text' => $text);
		};

		$preserve_title = $target_post
			&& self::preserve_value(array('post', 'title'), $target_post->post_title, array(), $preserve);
		if (!$preserve_title && self::is_translatable($post->post_title)) {
			$add(array('post', 'title'), $post->post_title);
		}
		$preserve_excerpt = $target_post
			&& self::preserve_value(array('post', 'excerpt'), $target_post->post_excerpt, array(), $preserve);
		if (!$preserve_excerpt && self::is_translatable($post->post_excerpt)) {
			$add(array('post', 'excerpt'), $post->post_excerpt);
		}
		$preserve_content = $target_post
			&& self::preserve_value(array('post', 'content'), $target_post->post_content, array(), $preserve);
		if (!$preserve_content && self::is_translatable($post->post_content)) {
			foreach (self::chunk_html($post->post_content) as $i => $chunk) {
				$add(array('post', 'content', $i), $chunk);
			}
		}

		$meta = array();
		if ($settings['translate_yoast'] && aipt_yoast_active()) {
			foreach (self::YOAST_KEYS as $meta_key) {
				$value = (string) get_post_meta($post_id, $meta_key, true);
				$meta[$meta_key] = $value;
				$target_value = $target_post ? get_post_meta($target_id, $meta_key, true) : null;
				if ($target_post && self::preserve_value(array('meta', $meta_key), $target_value, array(), $preserve)) {
					continue;
				}
				if (self::is_translatable($value)) {
					$add(array('meta', $meta_key), $value);
				}
			}
		}

		$tree        = array();
		$target_tree = array();
		$remap       = array();
		$overwrite   = array();
		if (aipt_acf_active()) {
			$fields = get_field_objects($post_id, false);
			if (is_array($fields)) {
				foreach ($fields as $field) {
					if (empty($field['key'])) {
						continue;
					}
					$tree[$field['key']] = $field['value'];
				}

				if ($target_post) {
					$target_fields = get_field_objects($target_id, false);
					if (is_array($target_fields)) {
						foreach ($target_fields as $target_field) {
							if (!empty($target_field['key'])) {
								$target_tree[$target_field['key']] = $target_field['value'];
							}
						}
					}
					$overwrite = AIPT_Safe_Merge::collect_overwrite_paths(array_values($fields), $tree, $target_tree);
				}

				foreach ($fields as $field) {
					if (empty($field['key'])) {
						continue;
					}
					self::walk(
						$field,
						$field['value'],
						$target_tree[$field['key']] ?? null,
						array('acf', $field['key']),
						$settings['field_overrides'],
						$add,
						$remap,
						$preserve,
						$overwrite,
						(bool) $target_post
					);
				}
			}
		}

		return array(
			'items'     => $items,
			'tree'      => $tree,
			'remap'     => $remap,
			'meta'      => $meta,
			'preserve'  => $preserve,
			'overwrite' => $overwrite,
		);
	}

	private static function walk(
		array $field,
		$value,
		$target_value,
		array $path,
		array $overrides,
		callable $add,
		array &$remap,
		array &$preserve,
		array $overwrite,
		bool $safe
	): void {
		$type = $field['type'] ?? '';

		if (in_array($type, array('repeater', 'group', 'flexible_content', 'clone'), true)) {
			if (!is_array($value)) {
				return;
			}

			if ($type === 'repeater') {
				foreach ($value as $i => $row) {
					if (!is_array($row)) {
						continue;
					}
					foreach ($field['sub_fields'] ?? array() as $sub) {
						if (array_key_exists($sub['key'], $row)) {
							$target_row = is_array($target_value) && isset($target_value[$i]) && is_array($target_value[$i])
								? $target_value[$i]
								: array();
							self::walk(
								$sub,
								$row[$sub['key']],
								$target_row[$sub['key']] ?? null,
								array_merge($path, array($i, $sub['key'])),
								$overrides,
								$add,
								$remap,
								$preserve,
								$overwrite,
								$safe
							);
						}
					}
				}
			} elseif ($type === 'flexible_content') {
				$layouts = array();
				foreach ($field['layouts'] ?? array() as $layout) {
					$layouts[$layout['name']] = $layout['sub_fields'] ?? array();
				}
				foreach ($value as $i => $row) {
					if (!is_array($row)) {
						continue;
					}
					$layout_name = (string) ($row['acf_fc_layout'] ?? '');
					foreach ($layouts[$layout_name] ?? array() as $sub) {
						if (array_key_exists($sub['key'], $row)) {
							$target_row = is_array($target_value) && isset($target_value[$i]) && is_array($target_value[$i])
								? $target_value[$i]
								: array();
							self::walk(
								$sub,
								$row[$sub['key']],
								$target_row[$sub['key']] ?? null,
								array_merge($path, array($i, $sub['key'])),
								$overrides,
								$add,
								$remap,
								$preserve,
								$overwrite,
								$safe
							);
						}
					}
				}
			} else { // group, clone
				foreach ($field['sub_fields'] ?? array() as $sub) {
					if (array_key_exists($sub['key'], $value)) {
						self::walk(
							$sub,
							$value[$sub['key']],
							is_array($target_value) ? ($target_value[$sub['key']] ?? null) : null,
							array_merge($path, array($sub['key'])),
							$overrides,
							$add,
							$remap,
							$preserve,
							$overwrite,
							$safe
						);
					}
				}
			}
			return;
		}

		if ($safe && self::preserve_value($path, $target_value, $overwrite, $preserve)) {
			return;
		}

		$action = AIPT_ACF_Schema::action($field, $overrides);

		if ($action === 'translate' && is_string($value) && self::is_translatable($value)) {
			if (strlen($value) > self::CHUNK_SIZE) {
				foreach (self::chunk_html($value) as $i => $chunk) {
					$add(array_merge($path, array('#chunk', $i)), $chunk);
				}
			} else {
				$add($path, $value);
			}
			return;
		}

		if (in_array($type, array('relationship', 'post_object', 'page_link'), true) && !empty($value)) {
			$remap[] = array('path' => $path, 'kind' => 'post');
		} elseif ($type === 'taxonomy' && !empty($value)) {
			$remap[] = array('path' => $path, 'kind' => 'term');
		}
	}

	private static function preserve_value(array $path, $value, array $overwrite, array &$preserve): bool {
		if (AIPT_Safe_Merge::is_overwritten($path, $overwrite) || !AIPT_Safe_Merge::has_value($value)) {
			return false;
		}
		$preserve[] = array('path' => $path, 'value' => $value);
		return true;
	}

	public static function is_translatable($value): bool {
		if (!is_string($value)) {
			return false;
		}
		$trimmed = trim($value);
		if ($trimmed === '' || is_numeric($trimmed)) {
			return false;
		}
		if (preg_match('~^https?://\S+$~i', $trimmed) || is_email($trimmed)) {
			return false;
		}
		return (bool) preg_match('/\p{L}/u', $trimmed);
	}

	/**
	 * Split long HTML on closing block tags; concatenating the chunks restores the original.
	 *
	 * @return string[]
	 */
	public static function chunk_html(string $html): array {
		if (strlen($html) <= self::CHUNK_SIZE) {
			return array($html);
		}

		$pieces = preg_split(
			'~(?<=</p>|</h1>|</h2>|</h3>|</h4>|</h5>|</h6>|</ul>|</ol>|</li>|</table>|</blockquote>|</div>|</section>|</article>)~i',
			$html
		);
		if (!is_array($pieces)) {
			$pieces = array($html);
		}

		$bounded = array();
		foreach ($pieces as $piece) {
			$bounded = array_merge($bounded, self::split_piece($piece));
		}

		$chunks  = array();
		$current = '';
		foreach ($bounded as $piece) {
			if ($current !== '' && strlen($current) + strlen($piece) > self::CHUNK_SIZE) {
				$chunks[] = $current;
				$current  = '';
			}
			$current .= $piece;
		}
		if ($current !== '') {
			$chunks[] = $current;
		}
		return $chunks;
	}

	/**
	 * Split one oversized block without changing its bytes or breaking UTF-8.
	 *
	 * @return string[]
	 */
	private static function split_piece(string $piece): array {
		$chunks = array();
		while (strlen($piece) > self::CHUNK_SIZE) {
			$cut = self::CHUNK_SIZE;
			while ($cut > 0 && (ord($piece[$cut]) & 0xC0) === 0x80) {
				$cut--;
			}

			$head = substr($piece, 0, $cut);
			if (preg_match_all('/\s+/u', $head, $matches, PREG_OFFSET_CAPTURE)) {
				$last       = end($matches[0]);
				$whitespace = (int) $last[1] + strlen($last[0]);
				if ($whitespace >= (int) (self::CHUNK_SIZE / 2)) {
					$cut = $whitespace;
				}
			}

			$chunks[] = substr($piece, 0, $cut);
			$piece    = substr($piece, $cut);
		}

		if ($piece !== '') {
			$chunks[] = $piece;
		}
		return $chunks;
	}

	/**
	 * Greedy-pack items into batches (lists of item ids) per API request.
	 *
	 * @return string[][]
	 */
	public static function build_batches(array $items): array {
		$batches = array();
		$current = array();
		$bytes   = 0;

		foreach ($items as $item) {
			$len = strlen($item['text']);

			if ($len > self::BATCH_BYTES) {
				if ($current) {
					$batches[] = $current;
					$current   = array();
					$bytes     = 0;
				}
				$batches[] = array($item['id']);
				continue;
			}

			if ($current && ($bytes + $len > self::BATCH_BYTES || count($current) >= self::BATCH_ITEMS)) {
				$batches[] = $current;
				$current   = array();
				$bytes     = 0;
			}

			$current[] = $item['id'];
			$bytes    += $len;
		}

		if ($current) {
			$batches[] = $current;
		}
		return $batches;
	}
}
