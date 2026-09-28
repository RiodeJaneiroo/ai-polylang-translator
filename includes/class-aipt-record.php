<?php
// One record = one source post (or term) × one target language. Shared by WP-CLI and
// auto-translation: the outcome shape every step returns, and composable pre-checks
// that decide whether a post can be translated without the writer dropping links.

if (!defined('ABSPATH')) {
	exit;
}

class AIPT_Record {

	public static function outcome(string $status, int $target_id = 0, float $cost = 0.0, string $message = '', bool $api = false): array {
		return array(
			'status'    => $status,
			'target_id' => $target_id,
			'cost'      => $cost,
			'message'   => $message,
			'api'       => $api,
		);
	}

	/**
	 * Source post and existing translation. Status '' means go on, with target_id = the
	 * existing translation (0 = none).
	 */
	public static function check_source(int $post_id, string $target, string $from, bool $skip_existing): array {
		if (!get_post($post_id)) {
			return self::outcome('error', 0, 0.0, 'source post not found');
		}
		$language = (string) pll_get_post_language($post_id);
		if ($language !== $from) {
			return self::outcome('error', 0, 0.0, sprintf("source language is '%s', expected '%s'", $language, $from));
		}

		$existing = (int) (pll_get_post($post_id, $target) ?: 0);
		if ($existing && $skip_existing) {
			return self::outcome('skipped_existing', $existing);
		}
		return self::outcome('', $existing);
	}

	// Parent ID without a $target translation, else 0: the writer would create a new
	// translation of the child at the root.
	public static function missing_parent(WP_Post $post, string $target): int {
		$parent = (int) $post->post_parent;
		return $parent && !pll_get_post($parent, $target) ? $parent : 0;
	}

	/**
	 * @return array<string, int[]> taxonomy => term IDs of the object, for Polylang-translated
	 *                              taxonomies where it has terms.
	 */
	public static function translated_terms(int $object_id, string $post_type): array {
		$terms = array();
		foreach (get_object_taxonomies($post_type) as $taxonomy) {
			if (!pll_is_translated_taxonomy($taxonomy)) {
				continue;
			}
			$ids = wp_get_object_terms($object_id, $taxonomy, array('fields' => 'ids'));
			if (!is_wp_error($ids) && $ids) {
				$terms[$taxonomy] = array_map('intval', $ids);
			}
		}
		return $terms;
	}

	/**
	 * Terms the writer would silently drop (copy_taxonomies maps them via pll_get_term).
	 *
	 * @param array<string, int[]> $terms From translated_terms().
	 * @return array<string, int[]> Same shape, only terms without a $target translation.
	 */
	public static function missing_terms(array $terms, string $target): array {
		$missing = array();
		foreach ($terms as $taxonomy => $ids) {
			foreach ($ids as $term_id) {
				if (!pll_get_term($term_id, $target)) {
					$missing[$taxonomy][] = $term_id;
				}
			}
		}
		return $missing;
	}

	// "category:12,post_tag:5"
	public static function describe_terms(array $terms): string {
		$parts = array();
		foreach ($terms as $taxonomy => $ids) {
			foreach ($ids as $term_id) {
				$parts[] = $taxonomy . ':' . $term_id;
			}
		}
		return implode(',', $parts);
	}
}
