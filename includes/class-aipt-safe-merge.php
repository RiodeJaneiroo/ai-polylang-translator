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
		$paths = array();
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
				$paths
			);
		}
		return $paths;
	}

	public static function merge_acf(array $fields, array $translated, array $target, array $overwrite_paths): array {
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
				$overwrite_paths
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

	private static function collect_field_paths(array $field, $source, $target, array $path, array &$paths): void {
		$type = (string) ($field['type'] ?? '');

		if (in_array($type, array('repeater', 'flexible_content'), true)) {
			$source_rows = is_array($source) ? array_values($source) : array();
			$target_rows = is_array($target) ? array_values($target) : array();
			if (!self::rows_match($field, $source_rows, $target_rows)) {
				$paths[] = $path;
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
						$paths
					);
				}
			}
			return;
		}

		if (in_array($type, array('group', 'clone'), true)) {
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
					$paths
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

	private static function merge_field(array $field, $translated, $target, array $path, array $overwrite_paths) {
		if (self::is_overwritten($path, $overwrite_paths)) {
			return $translated;
		}

		$type = (string) ($field['type'] ?? '');
		if (in_array($type, array('repeater', 'flexible_content'), true)) {
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
						$overwrite_paths
					);
				}
			}
			unset($translated_row);
			return $translated_rows;
		}

		if (in_array($type, array('group', 'clone'), true)) {
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
					$overwrite_paths
				);
			}
			return $translated;
		}

		return self::has_value($target) ? $target : $translated;
	}
}
