<?php
// WPML implementation of AIPT_Lang. WPML owns languages, groups, TM status and WCML
// sync; we reach them only through WPML's public hooks, plus read-only queries for
// pending TM work (AIPT_Lang_WPML_TM). References are to WPML 5.1.0
// (sitepress-multilingual-cms) and WooCommerce Multilingual 5.6.2.
//
// Write flow (see AIPT_Writer): suspend_sync removes WPML's save_post handler, so the
// inserted post stays without a language (WPML would otherwise give it the current
// language and a new trid: inc/post-translation/wpml-post-translation.class.php:39,219-228,
// wpml-admin-post-actions.class.php:38-100);
// commit_post then links it into the source's trid, marks TM complete, runs the WCML
// product sync and re-applies the suspension. Anything missing fails with a WP_Error.

if (!defined('ABSPATH')) {
	exit;
}

require_once __DIR__ . '/class-aipt-lang-wpml-tm.php';
require_once __DIR__ . '/class-aipt-lang-wpml-sync.php';

class AIPT_Lang_WPML implements AIPT_Lang {

	/** @var array<string, array{code: string, name: string}>|null */
	private $languages = null;

	/** @var AIPT_Lang_WPML_Sync */
	private $sync;

	/** @var int Depth of without_term_adjust() calls. */
	private $adjust_off = 0;

	public function __construct() {
		$this->sync = new AIPT_Lang_WPML_Sync();
	}

	public function name(): string {
		return 'wpml';
	}

	public function label(): string {
		return 'WPML';
	}

	// SitePress::get_languages($display, true): every active language, hidden ones included
	// (get_active_languages() drops hidden languages outside wp-admin, sitepress.class.php:881),
	// rows keyed by code with code and english_name (sitepress.class.php:923-929,
	// inc/setup/wpml-installation.class.php:268-335). Not the wpml_active_languages filter: its
	// language-switcher output drops english_name (inc/utilities/wpml-languages.class.php:229)
	// and can omit languages on singular/untranslated queries (sitepress.class.php:2765-2890,4447).
	public function languages(): array {
		if ($this->languages !== null) {
			return $this->languages;
		}
		global $sitepress;
		$rows = (is_object($sitepress) && method_exists($sitepress, 'get_languages') && method_exists($sitepress, 'get_default_language'))
			? (array) $sitepress->get_languages($sitepress->get_default_language(), true)
			: (array) apply_filters('wpml_active_languages', null, array('skip_missing' => 0));

		$languages = array();
		foreach ($rows as $code => $row) {
			$code = (string) ($row['code'] ?? $row['language_code'] ?? $code);
			if ($code === '') {
				continue;
			}
			$languages[$code] = array(
				'code' => $code,
				'name' => (string) ($row['english_name'] ?? $row['translated_name'] ?? $row['native_name'] ?? $code),
			);
		}
		$this->languages = $languages;
		return $languages;
	}

	public function default_language(): string {
		return (string) apply_filters('wpml_default_language', null);
	}

	// English name for the prompt.
	public function language_name(string $code): string {
		$languages = $this->languages();
		return isset($languages[$code]) ? $languages[$code]['name'] : $code;
	}

	public function post_language(int $post_id): ?string {
		$details = $this->post_details($post_id);
		return $details && !empty($details->language_code) ? (string) $details->language_code : null;
	}

	public function term_language(int $term_id): ?string {
		$term = $this->raw_term($term_id);
		if (!$term) {
			return null;
		}
		$details = $this->term_details($term);
		return $details && !empty($details->language_code) ? (string) $details->language_code : null;
	}

	// wpml_object_id (inc/template-functions.php:235, SitePress::get_object_id at
	// sitepress.class.php:4359) returns the element itself for untranslated types and for
	// its own language, so a self-result in another language means "none".
	public function post_translation(int $post_id, string $lang): int {
		$type = get_post_type($post_id);
		if (!$type) {
			return 0;
		}
		$id = (int) apply_filters('wpml_object_id', $post_id, $type, false, $lang);
		if ($id === $post_id && $this->post_language($post_id) !== $lang) {
			return 0;
		}
		return max(0, $id);
	}

	// Term IDs here; WPML maps them to term_taxonomy_id internally
	// (inc/taxonomy-term-translation/wpml-term-translation.class.php:140-145).
	public function term_translation(int $term_id, string $lang): int {
		$term = $this->raw_term($term_id);
		if (!$term) {
			return 0;
		}
		$id = (int) apply_filters('wpml_object_id', $term_id, $term->taxonomy, false, $lang);
		if ($id === $term_id && $this->term_language($term_id) !== $lang) {
			return 0;
		}
		return max(0, $id);
	}


	// Pending work wins over an existing post: overwriting a translation while a TM/ATE
	// job runs would be undone by the job, and wpml_tm_save_post would mark it complete.
	public function translation_state(int $post_id, string $lang): array {
		$id   = $this->post_translation($post_id, $lang);
		$rows = AIPT_Lang_WPML_TM::pair_rows($this->post_trid($post_id), 'post_' . get_post_type($post_id), $lang);
		if (AIPT_Lang_WPML_TM::rows_pending($rows, $id > 0)) {
			return array('state' => 'pending', 'id' => $id);
		}
		if (!$id) {
			return array('state' => 'none', 'id' => 0);
		}
		wp_cache_delete($id, 'post_meta'); // "Translate independently" may have removed the meta since.
		if (get_post_meta($id, AIPT_Lang_WPML_TM::DUPLICATE_META, true) || AIPT_Lang_WPML_TM::rows_duplicate($rows)) {
			return array('state' => 'duplicate', 'id' => $id);
		}
		return array('state' => 'translated', 'id' => $id);
	}

	// WPML caches groups in object properties, not in the object cache
	// (classes/core-abstract-classes/class-wpml-element-translation.php:4-27,218-243), plus
	// its element-translations cache group (sitepress.class.php:1849-1852). Post and meta
	// data it reads (e.g. the duplicate marker) come from the WordPress runtime cache.
	public function refresh(): void {
		global $wpml_post_translations;
		AIPT_Pipeline::flush_runtime_cache();
		if (is_object($wpml_post_translations) && method_exists($wpml_post_translations, 'reload')) {
			$wpml_post_translations->reload();
		}
		$this->refresh_terms();
		$this->languages = null;
	}

	private function refresh_terms(): void { // refresh() minus post groups and languages.
		global $wpml_term_translations, $sitepress;
		if (is_object($wpml_term_translations) && method_exists($wpml_term_translations, 'reload')) {
			$wpml_term_translations->reload();
		}
		if (is_object($sitepress) && method_exists($sitepress, 'get_translations_cache')) {
			$sitepress->get_translations_cache()->clear();
		}
		if (class_exists('WPML_WP_Cache') && defined('WPML_ELEMENT_TRANSLATIONS_CACHE_GROUP')) {
			(new WPML_WP_Cache(WPML_ELEMENT_TRANSLATIONS_CACHE_GROUP))->flush_group_cache();
		}
	}

	// The trid identifies the group; a post without one has no group to share a lock with.
	public function group_id(int $post_id): int {
		return $this->post_trid($post_id) ?: $post_id;
	}

	public function is_translated_post_type(string $type): bool {
		return (bool) apply_filters('wpml_is_translated_post_type', false, $type);
	}

	public function is_translated_taxonomy(string $taxonomy): bool {
		return (bool) apply_filters('wpml_is_translated_taxonomy', false, $taxonomy);
	}

	// WPML filters get_terms by the current language. Results are re-checked, in the same
	// language and without term ID adjusting: a "display as translated" taxonomy also
	// returns default-language fallbacks.
	public function terms_in_language(string $taxonomy, string $lang, array $args = array()) {
		return $this->in_language($lang, fn() => $this->without_term_adjust(function () use ($taxonomy, $lang, $args) {
			$terms  = get_terms(array_merge($args, array('taxonomy' => $taxonomy)));
			$fields = (string) ($args['fields'] ?? 'all');
			if (!is_array($terms) || ($fields !== 'all' && $fields !== 'ids')) {
				return $terms;
			}
			return array_values(array_filter($terms, function ($term) use ($lang): bool {
				$id = $term instanceof WP_Term ? (int) $term->term_id : (int) $term;
				return $this->term_language($id) === $lang;
			}));
		}));
	}

	// Same for WP_Query (WPML_Query_Filter needs suppress_filters off); the re-check loads only ID/post_type.
	public function posts_in_language(string $lang, array $args): array {
		$posts = $this->in_language($lang, static function () use ($args): array {
			$query = new WP_Query(array_merge($args, array('suppress_filters' => false)));
			return $query->posts;
		});

		$ids = array_map(static fn($post): int => is_object($post) ? (int) $post->ID : (int) $post, $posts);
		if (!$ids) {
			return array();
		}
		global $wpdb, $wpml_post_translations;
		if (is_object($wpml_post_translations) && method_exists($wpml_post_translations, 'prefetch_ids')) {
			$wpml_post_translations->prefetch_ids($ids);
		}
		$types = array();
		foreach (array_chunk($ids, 1000) as $chunk) {
			foreach ((array) $wpdb->get_results("SELECT ID, post_type FROM {$wpdb->posts} WHERE ID IN (" . implode(',', $chunk) . ')') as $row) {
				$types[(int) $row->ID] = (string) $row->post_type;
			}
		}
		return array_values(array_filter($posts, function ($post) use ($lang, $types): bool {
			$id      = is_object($post) ? (int) $post->ID : (int) $post;
			$details = isset($types[$id]) ? $this->post_details($id, $types[$id]) : null;
			return $details && !empty($details->language_code) && (string) $details->language_code === $lang;
		}));
	}

	/**
	 * Nothing to set: the post stays without a language until commit_post(). Refuses when
	 * WPML's save handler is hooked again — then WPML has already (or will on the next
	 * save) assigned the post to the current language, so the writer aborts right after
	 * the insert and reports the post ID as a partial write. The WPML/WCML API checks ran
	 * in suspend_sync() earlier in the same request.
	 */
	public function begin_post(int $new_id, int $source_id, string $lang) {
		if ($this->sync->blocked()) {
			return new WP_Error('aipt_wpml_api', __('WPML\'s save handler could not be suspended, so WPML may have assigned the translation to the wrong language. The translation was not linked.', 'ai-polylang-translator'));
		}
		return true;
	}

	// The WPML/WCML API checks ran in suspend_sync() earlier in the same request.
	public function commit_post(int $new_id, int $source_id, string $lang) {
		$this->refresh();
		$element = 'post_' . get_post_type($source_id);
		$group   = self::source_group($this->post_details($source_id), $lang);
		if (!$group) {
			return $this->link_error($new_id, $source_id, $lang, __('the source post has no WPML language or is already in the target language', 'ai-polylang-translator'));
		}

		$linked = $this->post_translation($source_id, $lang);
		if ($linked !== $new_id) {
			$refused = $this->may_link($new_id, $source_id, $linked, $element, $group['trid'], $lang);
			if (is_wp_error($refused)) {
				return $refused;
			}
			// Checked before TM status and WCML touch the post.
			if (!$this->link_element($new_id, $element, $group, $lang, fn(): bool => $this->post_translation($source_id, $lang) === $new_id)) {
				return $this->link_error($new_id, $source_id, $lang, __('the post does not resolve as the translation after linking', 'ai-polylang-translator'));
			}
		}

		// Also for an already-linked target: mark_complete() would complete a TM/ATE job
		// started since the writer's check with our text.
		if (AIPT_Lang_WPML_TM::rows_pending(AIPT_Lang_WPML_TM::pair_rows($group['trid'], $element, $lang), true)) {
			return new WP_Error('aipt_target_pending', __('WPML started translation work for this language while the translation was being written; its WPML translation status was not changed.', 'ai-polylang-translator'));
		}
		AIPT_Lang_WPML_TM::mark_complete($new_id);
		AIPT_Lang_WPML_TM::prefer_native_editor((int) apply_filters('wpml_original_element_id', null, $source_id, $element));
		AIPT_Lang_WPML_TM::mark_pb_native($new_id);
		AIPT_Lang_WPML_TM::translate_images($new_id);
		AIPT_Lang_WPML_TM::wcml_sync($source_id, $new_id, $lang);
		// WCML's post component writes date/menu_order/parent with raw $wpdb; finish_new_post
		// must not merge a stale cached post.
		clean_post_cache($new_id);
		$this->sync->reapply();

		$this->refresh();
		if ($this->post_translation($source_id, $lang) !== $new_id) {
			return $this->link_error($new_id, $source_id, $lang, __('the link did not survive the WPML/WCML sync', 'ai-polylang-translator'));
		}
		return true;
	}

	// WPML's create_term handler (sitepress.class.php:523,2547; WPML_Term_Actions::save_term_actions,
	// classes/taxonomy-term-translation/class-wpml-term-actions.php:58-75) already gave the
	// new term the current language and its own trid; linking moves that row into the
	// source's trid (WPML_Set_Language::change_translation_of). Term elements are
	// term_taxonomy_ids (wpml-term-translation.class.php:180-187).
	public function link_term(int $new_id, int $source_id, string $taxonomy, string $lang) {
		$ready = AIPT_Lang_WPML_TM::api_ready();
		if (is_wp_error($ready)) {
			return $ready;
		}
		$source = $this->raw_term($source_id, $taxonomy);
		$term   = $this->raw_term($new_id, $taxonomy);
		if (!$source || !$term) {
			return $this->term_link_error($new_id, $source_id, $lang);
		}

		$this->refresh_terms();
		$element = 'tax_' . $taxonomy;
		$group   = self::source_group($this->term_details($source), $lang);
		if (!$group) {
			return $this->term_link_error($new_id, $source_id, $lang);
		}

		$linked = $this->term_translation($source_id, $lang);
		if ($linked === $new_id) {
			return true;
		}
		if ($linked || $this->group_size((int) apply_filters('wpml_element_trid', null, (int) $term->term_taxonomy_id, $element), $element) > 1) {
			return $this->term_link_error($new_id, $source_id, $lang);
		}
		if (!$this->link_element((int) $term->term_taxonomy_id, $element, $group, $lang, fn(): bool => $this->term_translation($source_id, $lang) === $new_id)) {
			return $this->term_link_error($new_id, $source_id, $lang);
		}
		return true;
	}

	// See AIPT_Lang_WPML_Sync::suspend(): the API checks run first; on failure nothing is
	// removed and a WP_Error is returned.
	public function suspend_sync() {
		return $this->sync->suspend();
	}

	public function restore_sync(array $state): void {
		$this->sync->restore($state);
	}

	// Nothing: wpml-config.xml in the plugin root keeps the auto-translation markers
	// (AIPT_Auto::MARKER, AIPT_Auto::USER_META) out of WPML's custom-field sync.
	public function register_hooks(): void {
	}

	/**
	 * Never take over a pair that already has another post, never fill a TM placeholder
	 * (that would complete WPML's job with our text), and never move a post that is a
	 * member of a group (this one in another language, or another group with translations,
	 * e.g. some group's original); a solitary post WPML assigned on its own may be moved.
	 *
	 * @return true|WP_Error
	 */
	private function may_link(int $new_id, int $source_id, int $linked, string $element, int $trid, string $lang) {
		if ($linked) {
			return $this->link_error($new_id, $source_id, $lang, sprintf(
				/* translators: %d: post ID */
				__('post #%d is already the translation in this language', 'ai-polylang-translator'),
				$linked
			));
		}
		if (AIPT_Lang_WPML_TM::rows_pending(AIPT_Lang_WPML_TM::pair_rows($trid, $element, $lang), false)) {
			return $this->link_error($new_id, $source_id, $lang, __('WPML has translation work in progress for this language', 'ai-polylang-translator'));
		}
		$own = $this->post_trid($new_id);
		if ($own && ($own === $trid || $this->group_size($own, $element) > 1)) {
			return $this->link_error($new_id, $source_id, $lang, __('the post already belongs to a translation group', 'ai-polylang-translator'));
		}
		return true;
	}

	private function group_size(int $trid, string $element): int {
		return count($this->element_ids($trid, $element));
	}

	/**
	 * All statuses and no cache (sitepress.class.php:1803,1839): outside wp-admin WPML
	 * otherwise filters the group by the current user's read access
	 * (classes/translations/class-wpml-translations.php:123-129). Placeholders have no element_id.
	 *
	 * @return array<string, int> lang => element_id.
	 */
	private function element_ids(int $trid, string $element): array {
		if (!$trid) {
			return array();
		}
		$ids = array();
		foreach ((array) apply_filters('wpml_get_element_translations', null, $trid, $element, false, true, true) as $lang => $row) {
			if (is_object($row) && !empty($row->element_id)) {
				$ids[(string) $lang] = (int) $row->element_id;
			}
		}
		return $ids;
	}

	// The source's group as ['trid' => int, 'from' => language], null when it has none or is in $lang.
	private static function source_group(?object $details, string $lang): ?array {
		$trid = $details ? (int) $details->trid : 0;
		$from = $details ? (string) $details->language_code : '';
		return $trid && $from !== '' && $from !== $lang ? array('trid' => $trid, 'from' => $from) : null;
	}

	// Moves $element_id (post ID or term_taxonomy_id) into the source_group() $group as its
	// $lang translation: WPML_Set_Language::set() inserts or moves the row
	// (classes/core-abstract-classes/class-wpml-set-language.php:27-160, 178-208). The action
	// reports nothing and may refuse silently (e.g. a paused language, :442-486), so after a
	// reload $linked() checks the link. Callers run their own guards first.
	private function link_element(int $element_id, string $element_type, array $group, string $lang, callable $linked): bool {
		do_action('wpml_set_element_language_details', array(
			'element_id'           => $element_id,
			'element_type'         => $element_type,
			'trid'                 => $group['trid'],
			'language_code'        => $lang,
			'source_language_code' => $group['from'],
		));
		if (str_starts_with($element_type, 'tax_')) {
			$this->refresh_terms();
		} else {
			$this->refresh();
		}
		return (bool) $linked();
	}

	/**
	 * Runs $callback with WPML's current language switched to $lang; the switch is a stack
	 * and a null switch pops it (sitepress.class.php:1106-1150, inc/template-functions.php:832).
	 */
	public function in_language(string $lang, callable $callback) {
		do_action('wpml_switch_language', $lang);
		try {
			return $callback();
		} finally {
			do_action('wpml_switch_language', null);
		}
	}

	/**
	 * SitePress::get_term_adjust_id (on get_term outside wp-admin and in AJAX,
	 * sitepress.class.php:531-539,3171-3186) swaps a term for its current-language
	 * translation; wpml_disable_term_adjust_id opts out, as WPML_Create_Post_Helper does
	 * (inc/post-translation/wpml-create-post-helper.class.php:23-25).
	 */
	private function without_term_adjust(callable $callback) {
		// Nested calls (terms_in_language → term_language) keep the outer opt-out in place.
		if ($this->adjust_off++ === 0) {
			add_filter('wpml_disable_term_adjust_id', '__return_true', 99);
		}
		try {
			return $callback();
		} finally {
			if (--$this->adjust_off === 0) {
				remove_filter('wpml_disable_term_adjust_id', '__return_true', 99);
			}
		}
	}

	private function raw_term(int $term_id, string $taxonomy = ''): ?WP_Term {
		$term = $this->without_term_adjust(static fn() => $taxonomy === '' ? get_term($term_id) : get_term($term_id, $taxonomy));
		return $term instanceof WP_Term ? $term : null;
	}

	// wpml_element_language_details (inc/template-functions.php:798): trid, language_code,
	// source_language_code. $type spares the post lookup when the caller knows it.
	private function post_details(int $post_id, string $type = ''): ?object {
		$type = $type !== '' ? $type : (string) get_post_type($post_id);
		if ($type === '') {
			return null;
		}
		$details = apply_filters('wpml_element_language_details', null, array('element_id' => $post_id, 'element_type' => 'post_' . $type));
		return is_object($details) ? $details : null;
	}

	private function term_details(WP_Term $term): ?object {
		$details = apply_filters('wpml_element_language_details', null, array(
			'element_id'   => (int) $term->term_taxonomy_id,
			'element_type' => 'tax_' . $term->taxonomy,
		));
		return is_object($details) ? $details : null;
	}

	// wpml_element_trid (sitepress.class.php:291,1814).
	private function post_trid(int $post_id): int {
		$type = get_post_type($post_id);
		return $type ? (int) apply_filters('wpml_element_trid', null, $post_id, 'post_' . $type) : 0;
	}


	private function link_error(int $new_id, int $source_id, string $lang, string $reason): WP_Error {
		return new WP_Error('aipt_link_failed', sprintf(
			/* translators: 1: translation post ID, 2: language code, 3: reason */
			__('WPML did not link post #%1$d as the %2$s translation: %3$s.', 'ai-polylang-translator'),
			$new_id,
			$lang,
			$reason
		), array('post_id' => $new_id, 'source_id' => $source_id, 'lang' => $lang));
	}

	private function term_link_error(int $new_id, int $source_id, string $lang): WP_Error {
		return new WP_Error('aipt_link_failed', sprintf(
			/* translators: 1: translation term ID, 2: source term ID, 3: language code */
			__('WPML did not link term #%1$d as the %3$s translation of term #%2$d.', 'ai-polylang-translator'),
			$new_id,
			$source_id,
			$lang
		));
	}
}
