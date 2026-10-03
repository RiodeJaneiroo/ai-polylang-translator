<?php
// WPML Translation Management and WooCommerce Multilingual side of AIPT_Lang_WPML:
// the API availability check, pending-work detection, marking a written translation complete, the original's
// translation-editor preference, and the WCML product sync. Only AIPT_Lang_WPML uses it.
// References are to WPML 5.1.0 (sitepress-multilingual-cms) and WCML 5.6.2.

if (!defined('ABSPATH')) {
	exit;
}

class AIPT_Lang_WPML_TM {

	// icl_translation_status.status values (inc/constants.php:53-67, inc/constants-since-5-0.php:73)
	// that mean WPML has translation work still running for a pair whose translation post
	// exists, work that would later overwrite our text (or be marked complete by
	// wpml_tm_save_post): waiting for translator (1), in progress (2), ready to download
	// (4), needs review (30), ATE needs retry (40), pending at a translation service (102).
	// Not active: not translated (0), needs update (3), duplicate (9), complete (10).
	const ACTIVE_STATUSES = array(1, 2, 4, 30, 40, 102);

	// The same for a pair without a translation post, where an ATE job that is unsolvable
	// (41) or cancelled (42) also counts.
	const PENDING_STATUSES = array(...self::ACTIVE_STATUSES, 41, 42);

	// WPML's duplicate marker on a target post (holds the master post ID); WPML's
	// "Translate independently" removes exactly this meta
	// (inc/translation-management/translation-management.class.php:632).
	const DUPLICATE_META = '_icl_lang_duplicate_of';

	// WCML's programmatic sync action, WCML\Synchronization\Hooks::HOOK_SYNCHRONIZE_PRODUCT_TRANSLATIONS
	// (classes/Synchronization/Hooks.php:11), registered in every context (Hooks.php:58).
	const WCML_SYNC_HOOK = 'wcml_synchronize_product_translations';

	/**
	 * The WPML globals and hooks a write relies on. A WPML update that renames any of
	 * them must stop the write, not let it run under WPML's default language assignment.
	 *
	 * @return true|WP_Error
	 */
	public static function api_ready() {
		global $sitepress, $wpml_post_translations, $wpml_term_translations;
		$missing = array();
		if (!class_exists('SitePress', false) || !is_object($sitepress)) {
			$missing[] = 'SitePress';
		}
		if (!is_object($wpml_post_translations) || !method_exists($wpml_post_translations, 'save_post_actions')) {
			$missing[] = '$wpml_post_translations';
		}
		if (!is_object($wpml_term_translations) || !method_exists($wpml_term_translations, 'reload')) {
			$missing[] = '$wpml_term_translations';
		}
		// sitepress.class.php:325; inc/functions-load-tm.php:889 (or classes/plugins/Plugins.php:201).
		foreach (array('wpml_set_element_language_details', 'wpml_tm_save_post') as $hook) {
			if (has_action($hook) === false) {
				$missing[] = $hook;
			}
		}
		if (!$missing) {
			return true;
		}
		return new WP_Error('aipt_wpml_api', sprintf(
			/* translators: %s: comma-separated WPML API names */
			__('AI Translator cannot write with this WPML version: %s not available.', 'ai-polylang-translator'),
			implode(', ', $missing)
		));
	}

	/**
	 * WPML's translation rows (+ TM status) for $lang in the group $trid. There is no
	 * public hook for this, so it is a read-only query on WPML's tables.
	 *
	 * @return array|null Rows with element_id and status; null on a query error (tables or
	 *                    columns renamed by a WPML update).
	 */
	public static function pair_rows(int $trid, string $element_type, string $lang): ?array {
		global $wpdb;
		if ($trid <= 0) {
			return array();
		}
		$suppress = $wpdb->suppress_errors(true);
		$rows     = $wpdb->get_results($wpdb->prepare(
			"SELECT t.element_id, s.status
			FROM {$wpdb->prefix}icl_translations t
			LEFT JOIN {$wpdb->prefix}icl_translation_status s ON s.translation_id = t.translation_id
			WHERE t.trid = %d AND t.language_code = %s AND t.element_type = %s",
			$trid,
			$lang,
			$element_type
		));
		$error = (string) $wpdb->last_error;
		$wpdb->suppress_errors($suppress);
		return ($error !== '' || !is_array($rows)) ? null : $rows;
	}

	/**
	 * Whether the pair's rows show WPML translation work. A query error (null) counts as
	 * pending: refuse, never write.
	 *
	 * @param bool $has_target The pair already has a translation post.
	 */
	public static function rows_pending(?array $rows, bool $has_target): bool {
		if ($rows === null) {
			return true;
		}
		$statuses = $has_target ? self::ACTIVE_STATUSES : self::PENDING_STATUSES;
		foreach ($rows as $row) {
			// A row without element_id is a placeholder for a TM/ATE job; linking into it
			// would fill it (classes/core-abstract-classes/class-wpml-set-language.php:107-127,410-424).
			if (!$has_target && $row->element_id === null) {
				return true;
			}
			if ($row->status !== null && in_array((int) $row->status, $statuses, true)) {
				return true;
			}
		}
		return false;
	}

	// ICL_TM_DUPLICATE (9, inc/constants.php:58). WPML can leave a target with this status
	// but without the duplicate meta (seen on axio), so either one marks a duplicate: the
	// post is still an untranslated copy as far as WPML's TM is concerned.
	public static function rows_duplicate(?array $rows): bool {
		foreach ((array) $rows as $row) {
			if ($row->status !== null && (int) $row->status === 9) {
				return true;
			}
		}
		return false;
	}


	/**
	 * What WPML does for a translation edited in the WordPress editor: drop the duplicate
	 * marker, then wpml_tm_save_post (inc/functions-load-tm.php:872-889) writes the
	 * icl_translation_status row as complete / local with the original's md5 and marks
	 * the job as WP-editor (inc/actions/wpml-tm-post-actions.class.php:21-121). It reads
	 * the duplicate meta first and would set ICL_TM_DUPLICATE while it is present.
	 */
	public static function mark_complete(int $post_id): void {
		delete_post_meta($post_id, self::DUPLICATE_META);
		$post = get_post($post_id);
		if ($post) {
			do_action('wpml_tm_save_post', $post_id, $post, false);
		}
	}

	/**
	 * Sets the group's original post to the WordPress (native) editor, so opening our
	 * translation in wp-admin does not redirect to an empty ATE job. Stored as per-post
	 * meta on the original; WPML's own "this post" switch does the same update_post_meta
	 * (classes/post-edit-screen/endpoints/SetEditorMode.php:80-85), there is no setter API.
	 * Left alone when the setting is off, when the original already has a per-post choice,
	 * or when the post type / global setting already resolves to native. Cosmetic, so a
	 * missing WPML class skips it instead of failing the write.
	 */
	public static function prefer_native_editor(int $original_id): void {
		if (!$original_id || !self::native_editor_enabled()) {
			return;
		}
		$mode = 'WPML_TM_Post_Edit_TM_Editor_Mode';
		if (!class_exists($mode) || !defined($mode . '::POST_META_KEY_EDITOR') || !defined($mode . '::EDITOR_NATIVE')) {
			return;
		}
		$meta_key = constant($mode . '::POST_META_KEY_EDITOR');
		$native   = constant($mode . '::EDITOR_NATIVE');

		if ((string) get_post_meta($original_id, $meta_key, true) !== '') {
			return;
		}
		// classes/post-edit-screen/class-wpml-tm-post-edit-tm-editor-mode.php:30-32.
		if (is_callable(array($mode, 'get_translation_editor'))
			&& call_user_func(array($mode, 'get_translation_editor'), null, $original_id, false) === $native) {
			return;
		}
		update_post_meta($original_id, $meta_key, $native);
	}

	// Settings > AI Translator > Advanced (AIPT_Settings_Advanced), default on.
	private static function native_editor_enabled(): bool {
		return (bool) AIPT_Settings::get()['native_editor'];
	}

	public static function wcml_active(): bool {
		return defined('WCML_VERSION');
	}

	/**
	 * With WCML active, its sync hook must have a handler: without it a product translation
	 * would miss variations, prices and stock. Checked by suspend_sync() before any write,
	 * for every post type.
	 *
	 * @return true|WP_Error
	 */
	public static function wcml_api_ready() {
		if (!self::wcml_active() || has_action(self::WCML_SYNC_HOOK) !== false) {
			return true;
		}
		return new WP_Error('aipt_wpml_api', sprintf(
			/* translators: %s: hook name */
			__('WooCommerce Multilingual is active but its product sync (%s) is not available.', 'ai-polylang-translator'),
			self::WCML_SYNC_HOOK
		));
	}

	/**
	 * WCML creates variations and syncs prices, stock, attributes, media and taxonomies
	 * once the translation is linked (classes/Synchronization/Manager.php:54-75; it resolves
	 * the original itself). Same call shape as WCML's own translation editor
	 * (inc/translation-editor/class-wcml-synchronize-product-data.php:48).
	 * Gotcha: Component/Variations.php:57,165 removes WPML's save_post handler and re-adds
	 * it unconditionally, so the caller must re-apply its suspension afterwards.
	 */
	public static function wcml_sync(int $source_id, int $new_id, string $lang): void {
		if (get_post_type($new_id) !== 'product' || !self::wcml_active()) {
			return;
		}
		$source = get_post($source_id);
		if ($source) {
			do_action(self::WCML_SYNC_HOOK, $source, array($new_id), array($new_id => $lang));
		}
	}

	/**
	 * WPML Page Builders regenerates a translation's body from the original's string
	 * package unless the translation's last edit mode is "native editor"
	 * (addons/wpml-page-builders/classes/Shared/st/class-wpml-pb-update-post.php:77-79,
	 * class-wpml-pb-integration.php:64; setter in class-wpml-pb-last-translation-edit-mode.php:43-45).
	 * Cosmetic if the add-on is missing.
	 */
	public static function mark_pb_native(int $post_id): void {
		$class = 'WPML_PB_Last_Translation_Edit_Mode';
		if (class_exists($class) && method_exists($class, 'set_native_editor')) {
			call_user_func(array($class, 'set_native_editor'), $post_id);
		}
	}

	/**
	 * WPML Media's image translation runs on save_post (PHP_INT_MAX), while our new post has
	 * no language yet, so it finds nothing to do
	 * (wpml-media-translation/classes/media-translation/class-wpml-media-post-images-translation.php:38,58-94).
	 * Run it once more for the linked post, with an instance built the way WPML Media builds
	 * its own (class-wpml-media-post-images-translation-factory.php:5-27, used likewise by
	 * batch-media-url-translation/wpml-media-post-batch-media-url-translation-factory.php:9-11).
	 * create() returns null when post media localization is off. Cosmetic if missing.
	 */
	public static function translate_images(int $post_id): void {
		$factory = 'WPML_Media_Post_Images_Translation_Factory';
		if (!class_exists($factory) || !method_exists($factory, 'create')) {
			return;
		}
		try {
			$translator = (new $factory())->create();
			if (is_object($translator) && method_exists($translator, 'translate_images')) {
				$translator->translate_images($post_id);
			}
		} catch (Throwable $e) {
			error_log('AI Translator: WPML Media image translation failed for post #' . $post_id . ': ' . $e->getMessage());
		}
	}
}
