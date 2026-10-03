<?php
// Safe overwrite policy for existing translations, including nested ACF structures.

if (!defined('ABSPATH')) {
	exit;
}

class AIPT_Safe_Merge {

	public static function has_value($value): bool {
		if (is_string($value)) {
			return trim($value) !== '';
		}
		if (is_array($value)) {
			return $value !== array();
		}
		return $value !== null && $value !== false;
	}

	public static function collect_overwrite_paths(array $fields, array $source, array $target): array {
		return self::collect_paths($fields, $source, $target)['overwrite'];
	}

	/**
	 * @return array{overwrite: array, flex: array} overwrite drives full-field
	 * extraction/replacement; flex marks flexible_content fields whose sections
	 * are merged row-aligned instead of replaced wholesale.
	 */
	public static function collect_paths(array $fields, array $source, array $target): array {
		$overwrite = array();
		$flex      = array();
		foreach ($fields as $field) {
			$key = (string) ($field['key'] ?? '');
			if ($key === '') {
				continue;
			}
			self::collect_field_paths(
				$field,
				$source[$key] ?? null,
				$target[$key] ?? null,
				array('acf', $key),
				$overwrite,
				$flex
			);
		}
		return array('overwrite' => $overwrite, 'flex' => $flex);
	}

	public static function merge_acf(array $fields, array $translated, array $target, array $overwrite_paths, array $flex_paths): array {
		foreach ($fields as $field) {
			$key = (string) ($field['key'] ?? '');
			if ($key === '') {
				continue;
			}
			if (!array_key_exists($key, $translated) && array_key_exists($key, $target)) {
				$translated[$key] = $target[$key];
				continue;
			}
			if (!array_key_exists($key, $translated)) {
				continue;
			}
			$translated[$key] = self::merge_field(
				$field,
				$translated[$key],
				$target[$key] ?? null,
				array('acf', $key),
				$overwrite_paths,
				$flex_paths
			);
		}
		return $translated;
	}

	public static function is_overwritten(array $path, array $overwrite_paths): bool {
		foreach ($overwrite_paths as $overwrite_path) {
			if (count($overwrite_path) > count($path)) {
				continue;
			}
			if (array_slice($path, 0, count($overwrite_path)) === $overwrite_path) {
				return true;
			}
		}
		return false;
	}

	/**
	 * Pair source sections with existing-translation sections by matching the Nth
	 * occurrence of a layout on each side. Extra source occurrences stay unmatched
	 * (translated as new), extra target occurrences are left for the caller to keep.
	 *
	 * @return array<int, int> Source row index => target row index.
	 */
	/**
	 * Extract side: a filled target value (on a path that is not overwritten) is recorded
	 * in $preserve as ['path' => ..., 'value' => ...]. True means the path is preserved
	 * and must not be extracted.
	 */
	public static function preserve_value(array $path, $value, array $overwrite, array &$preserve): bool {
		if (self::is_overwritten($path, $overwrite) || !self::has_value($value)) {
			return false;
		}
		$preserve[] = array('path' => $path, 'value' => $value);
		return true;
	}

	/**
	 * The job's 'preserve' entries as wp_json_encode(path) => value, for preserved_value().
	 */
	public static function preserve_map(array $entries): array {
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

	/**
	 * Write side: the target's current value when filled, else the value preserved at
	 * extract time, else $fallback. A preserved path was never extracted, so it has no
	 * translation that could take precedence.
	 */
	public static function preserved_value(array $path, $current, $fallback, array $preserve_map) {
		if (self::has_value($current)) {
			return $current;
		}
		$key = wp_json_encode($path);
		return array_key_exists($key, $preserve_map) ? $preserve_map[$key] : $fallback;
	}

	public static function align_flexible_rows(array $source_rows, array $target_rows): array {
		$target_by_layout = array();
		foreach ($target_rows as $index => $row) {
			$layout = is_array($row) ? (string) ($row['acf_fc_layout'] ?? '') : '';
			if ($layout !== '') {
				$target_by_layout[$layout][] = $index;
			}
		}

		$matches = array();
		$cursor  = array();
		foreach ($source_rows as $source_index => $row) {
			$layout = is_array($row) ? (string) ($row['acf_fc_layout'] ?? '') : '';
			if ($layout === '' || empty($target_by_layout[$layout])) {
				continue;
			}
			$nth = $cursor[$layout] ?? 0;
			if (!isset($target_by_layout[$layout][$nth])) {
				continue;
			}
			$matches[$source_index]  = $target_by_layout[$layout][$nth];
			$cursor[$layout]         = $nth + 1;
		}
		return $matches;
	}

	private static function collect_field_paths(array $field, $source, $target, array $path, array &$overwrite, array &$flex): void {
		$row_container = AIPT_ACF_Schema::is_row_container_value($field, $source)
			|| AIPT_ACF_Schema::is_row_container_value($field, $target);
		if ($row_container) {
			$source_rows = is_array($source) ? array_values($source) : array();
			$target_rows = is_array($target) ? array_values($target) : array();
			if (!self::rows_match($field, $source_rows, $target_rows)) {
				$overwrite[] = $path;
				// flexible_content sections are merged row-aligned at write time, so
				// the field also enters the flex list; repeater/generic stay overwrite-only.
				if (($field['type'] ?? '') === 'flexible_content') {
					$flex[] = $path;
					self::collect_aligned_flexible_paths(
						$field,
						$source_rows,
						$target_rows,
						$path,
						$overwrite,
						$flex
					);
				}
				return;
			}

			foreach ($source_rows as $index => $source_row) {
				$target_row = $target_rows[$index] ?? array();
				foreach (self::row_fields($field, $source_row) as $sub_field) {
					$key = (string) ($sub_field['key'] ?? '');
					if ($key === '') {
						continue;
					}
					self::collect_field_paths(
						$sub_field,
						is_array($source_row) ? ($source_row[$key] ?? null) : null,
						is_array($target_row) ? ($target_row[$key] ?? null) : null,
						array_merge($path, array($index, $key)),
						$overwrite,
						$flex
					);
				}
			}
			return;
		}

		if (AIPT_ACF_Schema::is_group_container($field) || !empty($field['sub_fields'])) {
			$source = is_array($source) ? $source : array();
			$target = is_array($target) ? $target : array();
			foreach ($field['sub_fields'] ?? array() as $sub_field) {
				$key = (string) ($sub_field['key'] ?? '');
				if ($key === '') {
					continue;
				}
				self::collect_field_paths(
					$sub_field,
					$source[$key] ?? null,
					$target[$key] ?? null,
					array_merge($path, array($key)),
					$overwrite,
					$flex
				);
			}
		}
	}

	private static function collect_aligned_flexible_paths(
		array $field,
		array $source_rows,
		array $target_rows,
		array $path,
		array &$overwrite,
		array &$flex
	): void {
		$matches = self::align_flexible_rows($source_rows, $target_rows);
		foreach ($source_rows as $source_index => $source_row) {
			if (!is_array($source_row) || !isset($matches[$source_index])) {
				continue;
			}

			$target_row = $target_rows[$matches[$source_index]];
			foreach (self::row_fields($field, $source_row) as $sub_field) {
				$key = (string) ($sub_field['key'] ?? '');
				if ($key === '') {
					continue;
				}
				self::collect_field_paths(
					$sub_field,
					$source_row[$key] ?? null,
					$target_row[$key] ?? null,
					array_merge($path, array($source_index, $key)),
					$overwrite,
					$flex
				);
			}
		}
	}

	private static function rows_match(array $field, array $source_rows, array $target_rows): bool {
		if (count($source_rows) !== count($target_rows)) {
			return false;
		}

		if (($field['type'] ?? '') !== 'flexible_content') {
			return true;
		}

		foreach ($source_rows as $index => $source_row) {
			$source_layout = is_array($source_row) ? (string) ($source_row['acf_fc_layout'] ?? '') : '';
			$target_row    = $target_rows[$index] ?? null;
			$target_layout = is_array($target_row) ? (string) ($target_row['acf_fc_layout'] ?? '') : '';
			if ($source_layout === '' || $source_layout !== $target_layout) {
				return false;
			}
		}
		return true;
	}

	private static function row_fields(array $field, $row): array {
		if (($field['type'] ?? '') !== 'flexible_content') {
			return (array) ($field['sub_fields'] ?? array());
		}

		$layout_name = is_array($row) ? (string) ($row['acf_fc_layout'] ?? '') : '';
		foreach ($field['layouts'] ?? array() as $layout) {
			if (($layout['name'] ?? '') === $layout_name) {
				return (array) ($layout['sub_fields'] ?? array());
			}
		}
		return array();
	}

	private static function merge_field(array $field, $translated, $target, array $path, array $overwrite_paths, array $flex_paths) {
		if (($field['type'] ?? '') === 'flexible_content' && in_array($path, $flex_paths, true)) {
			return self::merge_flexible_aligned($field, $translated, $target, $path, $overwrite_paths, $flex_paths);
		}

		if (self::is_overwritten($path, $overwrite_paths)) {
			return $translated;
		}

		$row_container = AIPT_ACF_Schema::is_row_container_value($field, $translated)
			|| AIPT_ACF_Schema::is_row_container_value($field, $target);
		if ($row_container) {
			$translated_rows = is_array($translated) ? array_values($translated) : array();
			$target_rows     = is_array($target) ? array_values($target) : array();
			foreach ($translated_rows as $index => &$translated_row) {
				if (!is_array($translated_row)) {
					continue;
				}
				$target_row = isset($target_rows[$index]) && is_array($target_rows[$index])
					? $target_rows[$index]
					: array();
				foreach (self::row_fields($field, $translated_row) as $sub_field) {
					$key = (string) ($sub_field['key'] ?? '');
					if ($key === '') {
						continue;
					}
					if (!array_key_exists($key, $translated_row) && array_key_exists($key, $target_row)) {
						$translated_row[$key] = $target_row[$key];
						continue;
					}
					if (!array_key_exists($key, $translated_row)) {
						continue;
					}
					$translated_row[$key] = self::merge_field(
						$sub_field,
						$translated_row[$key],
						$target_row[$key] ?? null,
						array_merge($path, array($index, $key)),
						$overwrite_paths,
						$flex_paths
					);
				}
			}
			unset($translated_row);
			return $translated_rows;
		}

		if (AIPT_ACF_Schema::is_group_container($field) || !empty($field['sub_fields'])) {
			$translated = is_array($translated) ? $translated : array();
			$target     = is_array($target) ? $target : array();
			foreach ($field['sub_fields'] ?? array() as $sub_field) {
				$key = (string) ($sub_field['key'] ?? '');
				if ($key === '') {
					continue;
				}
				if (!array_key_exists($key, $translated) && array_key_exists($key, $target)) {
					$translated[$key] = $target[$key];
					continue;
				}
				if (!array_key_exists($key, $translated)) {
					continue;
				}
				$translated[$key] = self::merge_field(
					$sub_field,
					$translated[$key],
					$target[$key] ?? null,
					array_merge($path, array($key)),
					$overwrite_paths,
					$flex_paths
				);
			}
			return $translated;
		}

		return self::has_value($target) ? $target : $translated;
	}

	/**
	 * Section-aware merge for a flexible_content field whose section structure
	 * differs between source and existing translation. Source-order sections come
	 * first (matched ones merged sub-field-wise with the filled translation, new
	 * ones kept translated), then any target-only sections are appended in place.
	 */
	private static function merge_flexible_aligned(array $field, $translated, $target, array $path, array $overwrite_paths, array $flex_paths): array {
		$translated_rows = is_array($translated) ? array_values($translated) : array();
		$target_rows     = is_array($target) ? array_values($target) : array();
		if (!$translated_rows) {
			return array_values($target_rows);
		}
		if (!$target_rows) {
			return array_values($translated_rows);
		}

		// Sub-merge must not re-trigger the wholesale-overwrite branch for this same
		// field, so its own path is stripped from the overwrite list passed down.
		$sub_overwrite = array();
		foreach ($overwrite_paths as $overwrite_path) {
			if ($overwrite_path !== $path) {
				$sub_overwrite[] = $overwrite_path;
			}
		}

		$matches = self::align_flexible_rows($translated_rows, $target_rows);
		$used    = array();
		$result  = array();
		foreach ($translated_rows as $source_index => $translated_row) {
			if (!is_array($translated_row)) {
				continue;
			}
			if (!isset($matches[$source_index])) {
				$result[] = $translated_row;
				continue;
			}

			$match_index         = $matches[$source_index];
			$used[$match_index] = true;
			$target_row         = $target_rows[$match_index];
			foreach (self::row_fields($field, $translated_row) as $sub_field) {
				$key = (string) ($sub_field['key'] ?? '');
				if ($key === '') {
					continue;
				}
				if (!array_key_exists($key, $translated_row) && array_key_exists($key, $target_row)) {
					$translated_row[$key] = $target_row[$key];
					continue;
				}
				if (!array_key_exists($key, $translated_row)) {
					continue;
				}
				$translated_row[$key] = self::merge_field(
					$sub_field,
					$translated_row[$key],
					$target_row[$key] ?? null,
					array_merge($path, array($source_index, $key)),
					$sub_overwrite,
					$flex_paths
				);
			}
			$result[] = $translated_row;
		}

		foreach ($target_rows as $i => $target_row) {
			if (!isset($used[$i]) && is_array($target_row)) {
				$result[] = $target_row;
			}
		}

		return array_values($result);
	}
}
