<?php
// Field type policy and settings tree. acf_get_field_groups()/acf_get_fields()
// cover both PHP-registered and admin-created field groups.

if (!defined('ABSPATH')) {
	exit;
}

class AIPT_ACF_Schema {

	// Canonical ACF container types. Two structural families:
	//   row containers  — value is a list of rows (repeater, flexible_content)
	//   group containers — value is a single sub_fields set (group, clone)
	const ROW_CONTAINERS   = array('repeater', 'flexible_content');
	const GROUP_CONTAINERS = array('group', 'clone');

	/**
	 * A field that wraps nested sub-fields and must be recursed into rather than
	 * translated as a scalar. Covers the four canonical ACF types plus any
	 * third-party container that exposes a non-empty 'sub_fields'/'layouts' shape.
	 */
	public static function is_container(array $field): bool {
		$type = $field['type'] ?? '';
		if (in_array($type, self::ROW_CONTAINERS, true) || in_array($type, self::GROUP_CONTAINERS, true)) {
			return true;
		}
		return !empty($field['sub_fields']) || !empty($field['layouts']);
	}

	/**
	 * Row container: value is iterated as a list of rows. Canonical repeater and
	 * flexible_content, plus generic types that carry 'layouts' (flexible-like) or
	 * 'sub_fields' without being a known group/clone. Unknown sub_fields-only
	 * containers map to repeater-style iteration — the safe choice, since it
	 * recurses per row instead of leaving nested text on the source language.
	 */
	public static function is_row_container(array $field): bool {
		$type = $field['type'] ?? '';
		if (in_array($type, self::ROW_CONTAINERS, true)) {
			return true;
		}
		if (in_array($type, self::GROUP_CONTAINERS, true)) {
			return false;
		}
		return !empty($field['layouts']) || !empty($field['sub_fields']);
	}

	/**
	 * Group container: a single sub_fields set with no rows (group, clone).
	 * Generic sub_fields-only types are treated as row containers, so this only
	 * matches the canonical group/clone types.
	 */
	public static function is_group_container(array $field): bool {
		return in_array($field['type'] ?? '', self::GROUP_CONTAINERS, true);
	}

	/**
	 * Row-shaped value: a non-empty list whose every element is an array (a row).
	 * Group values are associative (key => value) and fail array_is_list(); a flat
	 * list of scalars has non-array elements. Used to disambiguate non-canonical
	 * containers whose field description alone cannot tell row from group apart.
	 */
	public static function is_row_value($value): bool {
		if (!is_array($value) || $value === array() || !array_is_list($value)) {
			return false;
		}
		foreach ($value as $row) {
			if (!is_array($row)) {
				return false;
			}
		}
		return true;
	}

	/**
	 * Decide row-vs-group iteration for a container, consulting the value only for
	 * non-canonical types. Canonical types decide by type alone (value ignored):
	 * repeater/flexible_content are rows, group/clone are groups — unchanged.
	 * A non-canonical container takes the row branch only when it carries 'layouts'
	 * (flexible-like) or its value is row-shaped; otherwise the group branch, so a
	 * group-shaped associative value is no longer misread as a list of rows.
	 */
	public static function is_row_container_value(array $field, $value): bool {
		$type = $field['type'] ?? '';
		if (in_array($type, self::ROW_CONTAINERS, true)) {
			return true;
		}
		if (in_array($type, self::GROUP_CONTAINERS, true)) {
			return false;
		}
		return !empty($field['layouts']) || self::is_row_value($value);
	}

	public static function default_action(array $field): string {
		switch ($field['type'] ?? '') {
			case 'text':
			case 'textarea':
			case 'wysiwyg':
				return 'translate';

			case 'message':
			case 'tab':
			case 'accordion':
				return 'skip';
		}

		if (self::is_container($field)) {
			return 'recurse';
		}

		return 'copy';
	}

	public static function action(array $field, array $overrides): string {
		$action   = self::default_action($field);
		$override = $overrides[$field['key'] ?? ''] ?? '';
		if ($action === 'translate' && in_array($override, array('copy', 'skip'), true)) {
			return $override;
		}
		return $action;
	}

	public static function get_tree(): array {
		$tree = array();
		foreach (acf_get_field_groups() as $group) {
			$fields = acf_get_fields($group);
			if (!$fields) {
				continue;
			}
			$tree[] = array(
				'title'    => $group['title'],
				'children' => self::walk_fields($fields),
			);
		}
		return $tree;
	}

	private static function walk_fields(array $fields): array {
		$nodes = array();
		foreach ($fields as $field) {
			$action = self::default_action($field);
			if ($action === 'skip') {
				continue;
			}
			$node = array(
				'key'      => $field['key'],
				'label'    => $field['label'] !== '' ? $field['label'] : $field['name'],
				'type'     => $field['type'],
				'action'   => $action,
				'children' => array(),
			);
			if (!empty($field['sub_fields'])) {
				$node['children'] = self::walk_fields($field['sub_fields']);
			} elseif ($field['type'] === 'flexible_content' && !empty($field['layouts'])) {
				foreach ($field['layouts'] as $layout) {
					$node['children'][] = array(
						'key'      => '',
						'label'    => $layout['label'],
						'type'     => 'layout',
						'action'   => 'recurse',
						'children' => self::walk_fields($layout['sub_fields'] ?? array()),
					);
				}
			}
			$nodes[] = $node;
		}
		return $nodes;
	}

	public static function render_tree_html(array $overrides): string {
		$html = '';
		foreach (self::get_tree() as $group) {
			$inner = self::render_nodes($group['children'], $overrides);
			if ($inner === '') {
				continue;
			}
			$html .= '<div class="aipt-acf-group"><strong>' . esc_html($group['title']) . '</strong>'
				. '<ul class="aipt-acf-tree">' . $inner . '</ul></div>';
		}
		if ($html === '') {
			return '<p>' . esc_html__('No ACF field groups found.', 'ai-polylang-translator') . '</p>';
		}
		return $html;
	}

	private static function render_nodes(array $nodes, array $overrides): string {
		$html = '';
		foreach ($nodes as $node) {
			$children = $node['children'] ? '<ul>' . self::render_nodes($node['children'], $overrides) . '</ul>' : '';
			if ($node['action'] === 'translate') {
				$mode = $overrides[$node['key']] ?? 'translate';
				if (!in_array($mode, array('copy', 'skip'), true)) {
					$mode = 'translate';
				}
				$html .= '<li><label>'
					. esc_html($node['label']) . ' <code>' . esc_html($node['type']) . '</code>'
					. ' <select name="aipt_settings[acf_mode][' . esc_attr($node['key']) . ']">'
					. '<option value="translate" ' . selected($mode, 'translate', false) . '>'
					. esc_html__('Translate', 'ai-polylang-translator') . '</option>'
					. '<option value="copy" ' . selected($mode, 'copy', false) . '>'
					. esc_html__('Copy', 'ai-polylang-translator') . '</option>'
					. '<option value="skip" ' . selected($mode, 'skip', false) . '>'
					. esc_html__("Don't touch", 'ai-polylang-translator') . '</option>'
					. '</select>'
					. '</label>' . $children . '</li>';
			} elseif ($node['action'] === 'recurse' || $node['type'] === 'layout') {
				if ($children === '') {
					continue;
				}
				$html .= '<li><span class="aipt-acf-branch">' . esc_html($node['label'])
					. ' <code>' . esc_html($node['type']) . '</code></span>' . $children . '</li>';
			} else {
				$html .= '<li><span class="aipt-acf-copy" title="' . esc_attr__('Copied without translation', 'ai-polylang-translator') . '">'
					. esc_html($node['label']) . ' <code>' . esc_html($node['type']) . '</code></span>' . $children . '</li>';
			}
		}
		return $html;
	}
}
