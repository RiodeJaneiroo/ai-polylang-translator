<?php
// Sync suspension for AIPT_Lang_WPML: removes WPML's and WCML's save_post handlers for
// the duration of a write and puts them back. References are to WPML 5.1.0 and WCML 5.6.2.

if (!defined('ABSPATH')) {
	exit;
}

class AIPT_Lang_WPML_Sync {

	/** @var array[] Hooks removed by the current suspend(), as [hook, callback, priority, accepted_args]. */
	private $suspended = array();

	/**
	 * Removes WPML's save_post handler (save_post_actions at priority 100,
	 * inc/post-translation/wpml-post-translation.class.php:39; global set up in
	 * inc/functions-load.php:76-92) and WCML's sync-on-save. Before removing anything it
	 * checks the WPML API (and WCML's sync hook when WCML is active); on failure, or when
	 * WPML's handler is not there to remove, it returns a WP_Error and the writer aborts
	 * before any insert or update.
	 *
	 * @return array|WP_Error The removed hooks (state for restore()).
	 */
	public function suspend() {
		global $wpml_post_translations;
		$this->suspended = array();

		$ready = AIPT_Lang_WPML_TM::api_ready();
		if (!is_wp_error($ready)) {
			$ready = AIPT_Lang_WPML_TM::wcml_api_ready();
		}
		if (is_wp_error($ready)) {
			return $ready;
		}

		$callback = array($wpml_post_translations, 'save_post_actions');
		$priority = has_action('save_post', $callback);
		if ($priority === false) {
			return new WP_Error('aipt_wpml_api', __('WPML\'s save handler could not be suspended; nothing was written.', 'ai-polylang-translator'));
		}

		$this->suspended = array_merge(array(array('save_post', $callback, (int) $priority, 2)), self::wcml_save_callbacks());
		foreach ($this->suspended as $hook) {
			remove_action($hook[0], $hook[1], $hook[2]);
		}
		return $this->suspended;
	}

	// Idempotent: a hook that is already back is never added twice.
	public function restore(array $state): void {
		foreach ($state as $hook) {
			list($name, $callback, $priority, $args) = $hook;
			if (has_action($name, $callback) === false) {
				add_action($name, $callback, $priority, $args);
			}
		}
		$this->suspended = array();
	}

	// WCML's variation sync re-adds WPML's handler (see AIPT_Lang_WPML_TM::wcml_sync()).
	public function reapply(): void {
		foreach ($this->suspended as $hook) {
			$priority = has_action($hook[0], $hook[1]);
			if ($priority !== false) {
				remove_action($hook[0], $hook[1], $priority);
			}
		}
	}

	// A write must not go on: WPML's handler is hooked.
	public function blocked(): bool {
		global $wpml_post_translations;
		return has_action('save_post', array($wpml_post_translations, 'save_post_actions')) !== false;
	}

	/**
	 * WCML's sync-on-save callbacks (Hooks::synchronizeProductTranslationsOnSave, added on
	 * save_post at PHP_INT_MAX in admin and WP-CLI only, classes/Synchronization/Hooks.php:42-43).
	 * The Hooks instance comes from WPML's DI loader with no accessor, so it is found in
	 * the registered callbacks.
	 *
	 * @return array[] [hook, callback, priority, accepted_args] entries.
	 */
	private static function wcml_save_callbacks(): array {
		global $wp_filter;
		$found = array();
		$class = 'WCML\\Synchronization\\Hooks';
		if (!class_exists($class, false) || empty($wp_filter['save_post']) || !($wp_filter['save_post'] instanceof WP_Hook)) {
			return $found;
		}
		foreach ($wp_filter['save_post']->callbacks as $priority => $callbacks) {
			foreach ($callbacks as $callback) {
				$function = $callback['function'] ?? null;
				if (is_array($function) && isset($function[0], $function[1])
					&& $function[0] instanceof $class
					&& $function[1] === 'synchronizeProductTranslationsOnSave') {
					$found[] = array('save_post', $function, (int) $priority, (int) ($callback['accepted_args'] ?? 2));
				}
			}
		}
		return $found;
	}
}
