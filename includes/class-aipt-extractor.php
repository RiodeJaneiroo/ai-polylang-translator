<?php
// Collects translatable strings from a post. Item paths:
//   ['post', 'title' | 'excerpt'] | ['post', 'content', N] (block delimiters masked per
//   chunk by AIPT_Blocks; maps in 'blocks', keyed by N, only for chunks that had any)
//   ['meta', meta_key] (Yoast keys and the extra_meta_keys setting)
//   ['woo', 'attr', key, 'name' | 'value', i] (see AIPT_Woo)
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
	 * @param int    $target_id Existing translation (0 = none).
	 * @param string $target    Target language code (labels kept by safe mode are stored per language).
	 * @return array{items: array, tree: array, remap: array, meta: array, preserve: array, overwrite: array, flex: array, skip: array, woo: array, blocks: array}
	 */
	public static function extract(int $post_id, int $target_id, bool $safe, string $target): array {
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
			&& AIPT_Safe_Merge::preserve_value(array('post', 'title'), $target_post->post_title, array(), $preserve);
		if (!$preserve_title && self::is_translatable($post->post_title)) {
			$add(array('post', 'title'), $post->post_title);
		}
		$preserve_excerpt = $target_post
			&& AIPT_Safe_Merge::preserve_value(array('post', 'excerpt'), $target_post->post_excerpt, array(), $preserve);
		if (!$preserve_excerpt && self::is_translatable($post->post_excerpt)) {
			$add(array('post', 'excerpt'), $post->post_excerpt);
		}
		$preserve_content = $target_post
			&& AIPT_Safe_Merge::preserve_value(array('post', 'content'), $target_post->post_content, array(), $preserve);
		$blocks = array();
		if (!$preserve_content && self::is_translatable($post->post_content)) {
			// Masked after chunking, so chunk boundaries stay those of the raw content.
			foreach (self::chunk_html($post->post_content) as $i => $chunk) {
				$masked = AIPT_Blocks::mask($chunk);
				$entry  = AIPT_Blocks::job_entry($masked, $chunk);
				if ($entry !== null) {
					$blocks[$i] = $entry;
				}
				// A chunk that could not be masked is never sent; the writer keeps its source.
				if (!$masked['fallback']) {
					$add(array('post', 'content', $i), $masked['text']);
				}
			}
		}

		$meta      = array();
		$meta_keys = $settings['translate_yoast'] && aipt_yoast_active() ? self::YOAST_KEYS : array();
		// Extra keys travel only with a non-empty string value, so an empty or missing
		// source value never clears the translation's own value.
		foreach ((array) $settings['extra_meta_keys'] as $meta_key) {
			$value = is_string($meta_key) ? get_post_meta($post_id, $meta_key, true) : null;
			if (is_string($value) && trim($value) !== '' && !in_array($meta_key, $meta_keys, true)) {
				$meta_keys[] = $meta_key;
			}
		}
		foreach ($meta_keys as $meta_key) {
			$value = (string) get_post_meta($post_id, $meta_key, true);
			$meta[$meta_key] = $value;
			$target_value = $target_post ? get_post_meta($target_id, $meta_key, true) : null;
			if ($target_post && AIPT_Safe_Merge::preserve_value(array('meta', $meta_key), $target_value, array(), $preserve)) {
				continue;
			}
			if (self::is_translatable($value)) {
				$add(array('meta', $meta_key), $value);
			}
		}

		$woo = AIPT_Woo::extract($post, $target_post ? $target_id : 0, $target);
		foreach ($woo['items'] as $woo_item) {
			$add($woo_item['path'], $woo_item['text']);
		}
		$preserve = array_merge($preserve, $woo['preserve']);

		$tree        = array();
		$target_tree = array();
		$remap       = array();
		$overwrite   = array();
		$flex        = array();
		$skip        = array();
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
					$paths     = AIPT_Safe_Merge::collect_paths(array_values($fields), $tree, $target_tree);
					$overwrite = $paths['overwrite'];
					$flex      = $paths['flex'];
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
						$skip,
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
			'flex'      => $flex,
			'skip'      => $skip,
			'woo'       => $woo['snapshot'],
			'blocks'    => $blocks,
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
		array &$skip,
		array $overwrite,
		bool $safe
	): void {
		$type   = $field['type'] ?? '';
		$action = AIPT_ACF_Schema::action($field, $overrides);

		if ($action === 'skip') {
			// Only a "Don't touch" override on a translatable field is recorded;
			// structurally skipped fields (message/tab/accordion) carry no value.
			if (AIPT_ACF_Schema::default_action($field) === 'translate') {
				$skip[] = $path;
			}
			return;
		}

		if (AIPT_ACF_Schema::is_container($field)) {
			if (!is_array($value)) {
				return;
			}

			if (AIPT_ACF_Schema::is_row_container_value($field, $value) && empty($field['layouts'])) {
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
								$skip,
								$overwrite,
								$safe
							);
						}
					}
				}
			} elseif (AIPT_ACF_Schema::is_row_container_value($field, $value)) {
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
								$skip,
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
							$skip,
							$overwrite,
							$safe
						);
					}
				}
			}
			return;
		}

		if ($safe && AIPT_Safe_Merge::preserve_value($path, $target_value, $overwrite, $preserve)) {
			return;
		}

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
		$pieces = self::join_split_delimiters($html, $pieces);

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
	 * A closing tag inside a block delimiter's JSON (hand-written or imported content may
	 * not escape '<') must not end a piece: pieces split inside a delimiter are joined
	 * again. Delimiter matches are ordered and do not overlap.
	 *
	 * @param string[] $pieces Consecutive pieces of $html.
	 * @return string[]
	 */
	private static function join_split_delimiters(string $html, array $pieces): array {
		if (count($pieces) < 2 || !str_contains($html, '<!--')
			|| !preg_match_all(AIPT_Blocks::DELIMITER, $html, $matches, PREG_OFFSET_CAPTURE)) {
			return $pieces;
		}
		$spans  = $matches[0];
		$count  = count($spans);
		$next   = 0;
		$joined = array();
		$pos    = 0;
		foreach ($pieces as $piece) {
			// First delimiter that ends after this boundary.
			while ($next < $count && $spans[$next][1] + strlen($spans[$next][0]) <= $pos) {
				$next++;
			}
			if ($joined && $next < $count && $spans[$next][1] < $pos) {
				$joined[count($joined) - 1] .= $piece;
			} else {
				$joined[] = $piece;
			}
			$pos += strlen($piece);
		}
		return $joined;
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

			// Guarantee forward progress: on malformed UTF-8 the rewinds above can
			// drive $cut to 0, which would emit an empty chunk and leave $piece
			// unchanged (infinite loop). Fall back to a hard cut at the limit.
			if ($cut <= 0) {
				$cut = self::CHUNK_SIZE;
			}
			$cut = self::delimiter_safe_cut($piece, $cut);

			$chunks[] = substr($piece, 0, $cut);
			$piece    = substr($piece, $cut);
		}

		if ($piece !== '') {
			$chunks[] = $piece;
		}
		return $chunks;
	}

	// A cut inside a block delimiter would send both halves to the model unmasked: move
	// it to the delimiter's start (its end when the delimiter opens the piece). Both are
	// ASCII boundaries, so UTF-8 stays intact, and the cut still moves forward. Matches
	// come in order and do not overlap, so the scan stops at the first one at or after $cut.
	private static function delimiter_safe_cut(string $piece, int $cut): int {
		if (!str_contains($piece, '<!--')) {
			return $cut;
		}
		$offset = 0;
		while (preg_match(AIPT_Blocks::DELIMITER, $piece, $match, PREG_OFFSET_CAPTURE, $offset)) {
			$start = (int) $match[0][1];
			if ($start >= $cut) {
				break;
			}
			$end = $start + strlen($match[0][0]);
			if ($cut < $end) {
				return $start > 0 ? $start : $end;
			}
			$offset = $end;
		}
		return $cut;
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
