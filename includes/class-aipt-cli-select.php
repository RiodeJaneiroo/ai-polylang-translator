<?php
// Source selection for `wp aipt` commands: the source posts of `translate` (parents
// first) and the source terms of `translate-terms`, both restricted to the source
// language through the multilingual adapter.

if (!defined('ABSPATH')) {
	exit;
}

class AIPT_CLI_Select {

	/**
	 * @return int[] Source post IDs, parents first.
	 */
	public static function posts(array $types, array $statuses, string $from, string $after, array $ids, ?array $shard): array {
		$query_args = array(
			'post_type'              => $types,
			'post_status'            => $statuses,
			'fields'                 => 'ids',
			'posts_per_page'         => -1,
			'orderby'                => 'ID',
			'order'                  => 'ASC',
			'no_found_rows'          => true,
			'ignore_sticky_posts'    => true,
			'update_post_meta_cache' => false,
			'update_post_term_cache' => false,
			'cache_results'          => false,
		);
		if ($ids) {
			$query_args['post__in'] = $ids;
		}
		if ($after !== '') {
			$query_args['date_query'] = array(array('column' => 'post_date', 'after' => $after, 'inclusive' => true));
		}

		// Explicit language: never depend on the CLI user's admin language filter.
		$found = array_map('intval', aipt_lang()->posts_in_language($from, $query_args));
		if ($shard) {
			$found = array_values(array_filter($found, static fn(int $id): bool => $id % $shard[1] === $shard[0]));
		}
		return self::parents_first($found, $types);
	}

	// Stable order: depth, then ID — parents are translated before their children.
	private static function parents_first(array $ids, array $types): array {
		global $wpdb;
		$hierarchical = array_values(array_filter($types, 'is_post_type_hierarchical'));
		if (!$hierarchical || !$ids) {
			return $ids;
		}

		$placeholders = implode(',', array_fill(0, count($hierarchical), '%s'));
		$rows         = $wpdb->get_results($wpdb->prepare(
			"SELECT ID, post_parent FROM {$wpdb->posts} WHERE post_parent > 0 AND post_type IN ($placeholders)",
			$hierarchical
		));
		$parents = array();
		foreach ($rows as $row) {
			$parents[(int) $row->ID] = (int) $row->post_parent;
		}

		$depth = array();
		foreach ($ids as $id) {
			$level   = 0;
			$current = $id;
			$seen    = array();
			while (isset($parents[$current]) && !isset($seen[$current])) {
				$seen[$current] = true;
				$current        = $parents[$current];
				$level++;
			}
			$depth[$id] = $level;
		}

		usort($ids, static fn(int $a, int $b): int => array($depth[$a], $a) <=> array($depth[$b], $b));
		return $ids;
	}

	/**
	 * @return WP_Term[] Source-language terms, parents first.
	 */
	public static function terms(string $taxonomy, string $from, string $since): array {
		$terms = aipt_lang()->terms_in_language($taxonomy, $from, array(
			'hide_empty' => false,
			'orderby'    => 'term_id',
			'order'      => 'ASC',
		));
		if (is_wp_error($terms)) {
			WP_CLI::warning(sprintf('%s: %s', $taxonomy, $terms->get_error_message()));
			return array();
		}

		$by_id = array();
		foreach ($terms as $term) {
			$by_id[(int) $term->term_id] = $term;
		}

		if ($since !== '') {
			// Ancestors keep the hierarchy intact even when only a child is used.
			$used = array_intersect(array_keys($by_id), self::terms_used_since($taxonomy, $since));
			return AIPT_Terms::with_ancestors($taxonomy, $used, $from);
		}
		return AIPT_Terms::parents_first($taxonomy, $by_id, $from);
	}

	// Source-language terms are attached only to source-language posts, so the
	// caller's intersection with source terms keeps this to the source language.
	private static function terms_used_since(string $taxonomy, string $since): array {
		global $wpdb;
		$ids = $wpdb->get_col($wpdb->prepare(
			"SELECT DISTINCT tt.term_id
			FROM {$wpdb->term_relationships} tr
			INNER JOIN {$wpdb->term_taxonomy} tt ON tt.term_taxonomy_id = tr.term_taxonomy_id
			INNER JOIN {$wpdb->posts} p ON p.ID = tr.object_id
			WHERE tt.taxonomy = %s
				AND p.post_date >= %s
				AND p.post_status NOT IN ('trash', 'auto-draft', 'inherit')",
			$taxonomy,
			$since . ' 00:00:00'
		));
		return array_map('intval', $ids);
	}
}
