<?php
// Contract between the shared pipeline and the multilingual plugin (Polylang or
// WPML). Every multilingual call goes through aipt_lang(); nothing outside
// includes/lang/ may call the multilingual plugin's API directly (see the boundary
// grep in CLAUDE.md). Mutating methods return true|WP_Error.

if (!defined('ABSPATH')) {
	exit;
}

interface AIPT_Lang {

	/**
	 * 'polylang' | 'wpml' (UI text, logs, job payload).
	 */
	public function name(): string;

	/**
	 * Display name for UI text ('Polylang', 'WPML').
	 */
	public function label(): string;

	/**
	 * @return array<string, array{code: string, name: string}> code => language.
	 */
	public function languages(): array;

	public function default_language(): string;

	/**
	 * Language name for the prompt (WPML: the English name; Polylang: the name set in
	 * Polylang's language settings).
	 */
	public function language_name(string $code): string;

	public function post_language(int $post_id): ?string;

	public function term_language(int $term_id): ?string;

	/**
	 * @return int 0 if none.
	 */
	public function post_translation(int $post_id, string $lang): int;

	/**
	 * @return int 0 if none.
	 */
	public function term_translation(int $term_id, string $lang): int;


	/**
	 * ['state' => 'none'|'pending'|'duplicate'|'translated', 'id' => int]
	 *   pending   = backend has in-progress translation work for this pair (WPML placeholder/TM job); never write
	 *   duplicate = target post exists but is a backend-managed copy of the source (WPML duplicate); overwrite in place
	 */
	public function translation_state(int $post_id, string $lang): array;

	/**
	 * Drop backend caches; called under the pair lock before every re-check.
	 */
	public function refresh(): void;

	/**
	 * Pair-lock key; trid on WPML, min id on Polylang.
	 */
	public function group_id(int $post_id): int;

	public function is_translated_post_type(string $type): bool;

	public function is_translated_taxonomy(string $taxonomy): bool;

	/**
	 * Terms of one language (get_terms() arguments in $args).
	 *
	 * @return WP_Term[]|WP_Error get_terms() result.
	 */
	public function terms_in_language(string $taxonomy, string $lang, array $args = array());

	/**
	 * Posts of one language (WP_Query arguments in $args): AIPT_CLI selects source posts
	 * with it instead of a backend-specific query arg.
	 *
	 * @return array WP_Query::$posts (IDs with 'fields' => 'ids').
	 */
	public function posts_in_language(string $lang, array $args): array;

	/**
	 * Runs $callback with $lang as the backend's current language and returns its result.
	 * Polylang: no switch; WPML: wpml_switch_language, restored afterwards. Used where the
	 * backend applies the current language implicitly (new terms: slug checks, insert, link).
	 *
	 * @return mixed
	 */
	public function in_language(string $lang, callable $callback);

	/**
	 * Right after the translation post is inserted/updated. Polylang: set language; WPML:
	 * nothing to set, only checks that its save handler is still suspended.
	 *
	 * @return true|WP_Error
	 */
	public function begin_post(int $new_id, int $source_id, string $lang);

	/**
	 * Late group commit, after meta/ACF/taxonomies: links $new_id as the source's
	 * translation in $lang, then verifies post_translation($source_id, $lang) === $new_id.
	 * Leaves the sync hook state exactly as it found it.
	 *
	 * @return true|WP_Error
	 */
	public function commit_post(int $new_id, int $source_id, string $lang);

	/**
	 * Sets the term's language and links it to its source term, then verifies the link.
	 *
	 * @return true|WP_Error
	 */
	public function link_term(int $new_id, int $source_id, string $taxonomy, string $lang);

	/**
	 * Suspend the backend's sync-on-save for the duration of a write.
	 *
	 * @return array|WP_Error State for restore_sync(), or a WP_Error when the backend's
	 *                        save handler or API is not usable: nothing was suspended and
	 *                        the writer aborts before any insert/update ('aipt_sync_missing').
	 */
	public function suspend_sync();

	/**
	 * Idempotent: re-adds only hooks that are absent.
	 */
	public function restore_sync(array $state): void;

	/**
	 * Polylang: pll_copy_post_metas; WPML: nothing (wpml-config.xml).
	 */
	public function register_hooks(): void;
}
