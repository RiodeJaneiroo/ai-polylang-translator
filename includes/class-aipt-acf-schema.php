<?php
// Field type policy and settings tree. acf_get_field_groups()/acf_get_fields()
// cover both PHP-registered and admin-created field groups.

if (!defined('ABSPATH')) {
	exit;
}

class AIPT_ACF_Schema {

	public static function default_action(array $field): string {
		switch ($field['type'] ?? '') {
			case 'text':
			case 'textarea':
			case 'wysiwyg':
				return 'translate';

			case 'repeater':
			case 'group':
			case 'flexible_content':
			case 'clone':
				return 'recurse';

			case 'message':
			case 'tab':
			case 'accordion':
				return 'skip';

			default:
				return 'copy';
		}
	}

	public static function action(array $field, array $overrides): string {
		$action = self::default_action($field);
		if ($action === 'translate' && ($overrides[$field['key'] ?? ''] ?? '') === 'copy') {
			return 'copy';
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
			return '<p>' . esc_html__('Группы полей ACF не найдены.', 'ai-polylang-translator') . '</p>';
		}
		return $html;
	}

	private static function render_nodes(array $nodes, array $overrides): string {
		$html = '';
		foreach ($nodes as $node) {
			$children = $node['children'] ? '<ul>' . self::render_nodes($node['children'], $overrides) . '</ul>' : '';
			if ($node['action'] === 'translate') {
				$checked = (($overrides[$node['key']] ?? '') !== 'copy');
				$html .= '<li><label>'
					. '<input type="hidden" name="aipt_settings[acf_all][]" value="' . esc_attr($node['key']) . '">'
					. '<input type="checkbox" name="aipt_settings[acf_translate][]" value="' . esc_attr($node['key']) . '" ' . checked($checked, true, false) . '>'
					. esc_html($node['label']) . ' <code>' . esc_html($node['type']) . '</code>'
					. '</label>' . $children . '</li>';
			} elseif ($node['action'] === 'recurse' || $node['type'] === 'layout') {
				if ($children === '') {
					continue;
				}
				$html .= '<li><span class="aipt-acf-branch">' . esc_html($node['label'])
					. ' <code>' . esc_html($node['type']) . '</code></span>' . $children . '</li>';
			} else {
				$html .= '<li><span class="aipt-acf-copy" title="' . esc_attr__('Копируется без перевода', 'ai-polylang-translator') . '">'
					. esc_html($node['label']) . ' <code>' . esc_html($node['type']) . '</code></span>' . $children . '</li>';
			}
		}
		return $html;
	}
}
