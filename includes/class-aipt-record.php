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
	 * existing translation (0 = none) and mode = effective_mode() of the requested $mode.
	 * A target with backend work in progress is 'skipped_pending'; a duplicate is never
	 * skipped as existing (it is overwritten).
	 */
	public static function check_source(int $post_id, string $target, string $from, bool $skip_existing, string $mode = 'overwrite'): array {
		if (!get_post($post_id)) {
			return self::outcome('error', 0, 0.0, 'source post not found');
		}
		$language = (string) aipt_lang()->post_language($post_id);
		if ($language !== $from) {
			return self::outcome('error', 0, 0.0, sprintf("source language is '%s', expected '%s'", $language, $from));
		}

		$state = aipt_lang()->translation_state($post_id, $target);
		if ($state['state'] === 'pending') {
			return self::outcome('skipped_pending', 0, 0.0, self::pending_error()->get_error_message());
		}
		$existing = self::existing_target($state);
		if ($skip_existing && $state['state'] === 'translated') {
			return self::outcome('skipped_existing', $existing);
		}
		$outcome         = self::outcome('', $existing);
		$outcome['mode'] = self::effective_mode($state, $mode);
		return $outcome;
	}

	/**
	 * The mode a write will use: a new translation, and a backend duplicate (an
	 * untranslated copy: safe mode would keep the source text), are always overwritten.
	 *
	 * @param array $state AIPT_Lang::translation_state().
	 */
	public static function effective_mode(array $state, string $requested): string {
		return self::existing_target($state) && $state['state'] !== 'duplicate' ? $requested : 'overwrite';
	}

	/**
	 * Status of a translate_post() error that skips the record instead of failing it, else
	 * null: a busy pair, or backend work in progress on the target.
	 */
	public static function skip_status(WP_Error $error): ?string {
		$skipped = array('aipt_pair_locked' => 'skipped_locked', 'aipt_target_pending' => 'skipped_pending');
		return $skipped[$error->get_error_code()] ?? null;
	}

	/**
	 * Target post a write would update: a real translation or a backend duplicate.
	 *
	 * @param array $state AIPT_Lang::translation_state().
	 */
	public static function existing_target(array $state): int {
		return in_array($state['state'] ?? '', array('translated', 'duplicate'), true) ? (int) $state['id'] : 0;
	}

	// translation_state() 'pending': never written, treated like a locked pair.
	public static function pending_error(): WP_Error {
		return new WP_Error('aipt_target_pending', sprintf(
			/* translators: %s: multilingual plugin name, e.g. WPML */
			__('A translation of this post is already in progress in %s.', 'ai-polylang-translator'),
			AIPT_Lang_Loader::label()
		));
	}

	// Parent ID without a $target translation, else 0: the writer would create a new
	// translation of the child at the root.
	public static function missing_parent(WP_Post $post, string $target): int {
		$parent = (int) $post->post_parent;
		return $parent && !aipt_lang()->post_translation($parent, $target) ? $parent : 0;
	}

	/**
	 * @return array<string, int[]> taxonomy => term IDs of the object, for translated
	 *                              taxonomies where it has terms.
	 */
	public static function translated_terms(int $object_id, string $post_type): array {
		$terms = array();
		foreach (get_object_taxonomies($post_type) as $taxonomy) {
			if (!aipt_lang()->is_translated_taxonomy($taxonomy)) {
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
	 * Terms the writer would silently drop (copy_taxonomies maps them via term_translation()).
	 *
	 * @param array<string, int[]> $terms From translated_terms().
	 * @return array<string, int[]> Same shape, only terms without a $target translation.
	 */
	public static function missing_terms(array $terms, string $target): array {
		$missing = array();
		foreach ($terms as $taxonomy => $ids) {
			foreach ($ids as $term_id) {
				if (!aipt_lang()->term_translation($term_id, $target)) {
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
