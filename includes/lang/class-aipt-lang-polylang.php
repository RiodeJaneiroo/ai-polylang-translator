<?php
// Polylang implementation of AIPT_Lang: a 1:1 mapping of the pll_* calls the plugin
// used before the adapter existed. Polylang has no pending work and no duplicates, so
// translation_state() only returns 'none' or 'translated'.

if (!defined('ABSPATH')) {
	exit;
}

class AIPT_Lang_Polylang implements AIPT_Lang {

	public function name(): string {
		return 'polylang';
	}

	public function label(): string {
		return 'Polylang';
	}

	public function languages(): array {
		$languages = array();
		foreach (pll_languages_list(array('fields' => '')) as $language) {
			$languages[(string) $language->slug] = array(
				'code' => (string) $language->slug,
				'name' => (string) $language->name,
			);
		}
		return $languages;
	}

	public function default_language(): string {
		return (string) pll_default_language();
	}

	// The name set in Polylang's language settings (not necessarily English), as the
	// prompt has always used it.
	public function language_name(string $code): string {
		$languages = $this->languages();
		return isset($languages[$code]) ? $languages[$code]['name'] : $code;
	}

	public function post_language(int $post_id): ?string {
		$language = pll_get_post_language($post_id);
		return $language ? (string) $language : null;
	}

	public function term_language(int $term_id): ?string {
		$language = pll_get_term_language($term_id);
		return $language ? (string) $language : null;
	}

	public function post_translation(int $post_id, string $lang): int {
		return (int) (pll_get_post($post_id, $lang) ?: 0);
	}

	public function term_translation(int $term_id, string $lang): int {
		return (int) (pll_get_term($term_id, $lang) ?: 0);
	}

	public function translation_state(int $post_id, string $lang): array {
		$id = $this->post_translation($post_id, $lang);
		return array('state' => $id ? 'translated' : 'none', 'id' => $id);
	}

	// Polylang keeps translation groups in the WordPress object cache; long in-process
	// runs (WP-CLI, cron) and re-checks under the pair lock need fresh data.
	public function refresh(): void {
		AIPT_Pipeline::flush_runtime_cache();
	}

	// The group's smallest post ID: two members of one group translated into the same
	// language write the same target.
	public function group_id(int $post_id): int {
		$ids = $this->post_translations($post_id);
		return $ids ? min(min($ids), $post_id) : $post_id;
	}

	public function is_translated_post_type(string $type): bool {
		return function_exists('pll_is_translated_post_type') && pll_is_translated_post_type($type);
	}

	public function is_translated_taxonomy(string $taxonomy): bool {
		return function_exists('pll_is_translated_taxonomy') && pll_is_translated_taxonomy($taxonomy);
	}

	// Results are re-checked like on WPML, so only terms whose language is $lang come back.
	public function terms_in_language(string $taxonomy, string $lang, array $args = array()) {
		$terms  = get_terms(array_merge($args, array(
			'taxonomy' => $taxonomy,
			'lang'     => $lang,
		)));
		$fields = (string) ($args['fields'] ?? 'all');
		if (!is_array($terms) || ($fields !== 'all' && $fields !== 'ids')) {
			return $terms;
		}
		return array_values(array_filter($terms, function ($term) use ($lang): bool {
			$id = $term instanceof WP_Term ? (int) $term->term_id : (int) $term;
			return $this->term_language($id) === $lang;
		}));
	}

	// Explicit language: never depend on the current user's admin language filter.
	public function posts_in_language(string $lang, array $args): array {
		$query = new WP_Query(array_merge($args, array('lang' => $lang)));
		return $query->posts;
	}

	// Polylang takes the language from explicit arguments; nothing to switch.
	public function in_language(string $lang, callable $callback) {
		return $callback();
	}

	public function begin_post(int $new_id, int $source_id, string $lang) {
		pll_set_post_language($new_id, $lang);
		return true;
	}

	public function commit_post(int $new_id, int $source_id, string $lang) {
		$translations = pll_get_post_translations($source_id);
		$source_lang  = pll_get_post_language($source_id);
		if ($source_lang) {
			$translations[$source_lang] = $source_id;
		}
		$translations[$lang] = $new_id;
		pll_save_post_translations($translations);

		if ($this->post_translation($source_id, $lang) !== $new_id) {
			return new WP_Error('aipt_link_failed', sprintf(
				/* translators: 1: translation post ID, 2: source post ID, 3: language code */
				__('Polylang did not link post #%1$d as the %3$s translation of post #%2$d.', 'ai-polylang-translator'),
				$new_id,
				$source_id,
				$lang
			));
		}
		return true;
	}

	public function link_term(int $new_id, int $source_id, string $taxonomy, string $lang) {
		pll_set_term_language($new_id, $lang);
		$translations = pll_get_term_translations($source_id);
		$source_lang  = pll_get_term_language($source_id);
		if ($source_lang) {
			$translations[$source_lang] = $source_id;
		}
		$translations[$lang] = $new_id;
		pll_save_term_translations($translations);

		if ($this->term_translation($source_id, $lang) !== $new_id) {
			return new WP_Error('aipt_link_failed', sprintf(
				/* translators: 1: translation term ID, 2: source term ID, 3: language code */
				__('Polylang did not link term #%1$d as the %3$s translation of term #%2$d.', 'ai-polylang-translator'),
				$new_id,
				$source_id,
				$lang
			));
		}
		return true;
	}

	public function suspend_sync(): array {
		$state = array();
		if (!function_exists('PLL')) {
			return $state;
		}

		$polylang = PLL();
		if (!is_object($polylang) || empty($polylang->sync) || !is_object($polylang->sync)) {
			return $state;
		}

		$sync     = $polylang->sync;
		$priority = has_action('pll_save_post', array($sync, 'pll_save_post'));
		if ($priority !== false) {
			remove_action('pll_save_post', array($sync, 'pll_save_post'), $priority);
			$state['post']          = $sync;
			$state['post_priority'] = $priority;
		}

		if (!empty($sync->post_metas) && is_object($sync->post_metas)) {
			$post_metas = $sync->post_metas;
			foreach (array('add', 'update', 'delete') as $operation) {
				$hook     = $operation . '_post_metadata';
				$priority = has_filter($hook, array($post_metas, 'can_synchronize_metadata'));
				if ($priority !== false) {
					remove_filter($hook, array($post_metas, 'can_synchronize_metadata'), $priority);
					$state['post_meta_guards'][$hook] = array($post_metas, $priority);
				}
			}
			$priority = has_action('pll_save_post', array($post_metas, 'save_object'));
			if ($priority !== false) {
				remove_action('pll_save_post', array($post_metas, 'save_object'), $priority);
				$state['post_metas_save']          = $post_metas;
				$state['post_metas_save_priority'] = $priority;
			}
			if (has_action('added_post_meta', array($post_metas, 'add_meta')) !== false
				&& is_callable(array($post_metas, 'remove_all_meta_actions'))) {
				$post_metas->remove_all_meta_actions();
				$state['post_metas'] = $post_metas;
			}
		}

		if (!empty($sync->taxonomies) && is_object($sync->taxonomies)) {
			$priority = has_action('set_object_terms', array($sync->taxonomies, 'set_object_terms'));
			if ($priority !== false) {
				remove_action('set_object_terms', array($sync->taxonomies, 'set_object_terms'), $priority);
				$state['taxonomies']          = $sync->taxonomies;
				$state['taxonomies_priority'] = $priority;
			}
		}

		return $state;
	}

	// Idempotent: a hook that is already back (restored twice, or re-added by someone
	// else meanwhile) is never added a second time.
	public function restore_sync(array $state): void {
		if (isset($state['post'])
			&& has_action('pll_save_post', array($state['post'], 'pll_save_post')) === false) {
			add_action('pll_save_post', array($state['post'], 'pll_save_post'), $state['post_priority'], 3);
		}
		if (isset($state['post_metas_save'])
			&& has_action('pll_save_post', array($state['post_metas_save'], 'save_object')) === false) {
			add_action('pll_save_post', array($state['post_metas_save'], 'save_object'), $state['post_metas_save_priority'], 3);
		}
		if (isset($state['post_metas'])
			&& has_action('added_post_meta', array($state['post_metas'], 'add_meta')) === false
			&& is_callable(array($state['post_metas'], 'add_all_meta_actions'))) {
			$state['post_metas']->add_all_meta_actions();
		}
		foreach ($state['post_meta_guards'] ?? array() as $hook => $guard) {
			list($post_metas, $priority) = $guard;
			if (has_filter($hook, array($post_metas, 'can_synchronize_metadata')) === false) {
				add_filter($hook, array($post_metas, 'can_synchronize_metadata'), $priority, 3);
			}
		}
		if (isset($state['taxonomies'])
			&& has_action('set_object_terms', array($state['taxonomies'], 'set_object_terms')) === false) {
			add_action('set_object_terms', array($state['taxonomies'], 'set_object_terms'), $state['taxonomies_priority'], 5);
		}
	}

	// Polylang's custom-field sync must not copy the auto-translation markers to other
	// languages (see AIPT_Auto::exclude_meta()).
	public function register_hooks(): void {
		add_filter('pll_copy_post_metas', array('AIPT_Auto', 'exclude_meta'));
	}

	/**
	 * @return array<string, int> lang => id (existing posts only).
	 */
	private function post_translations(int $post_id): array {
		return array_filter(array_map('intval', (array) pll_get_post_translations($post_id)));
	}
}
