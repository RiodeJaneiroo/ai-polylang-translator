<?php
// Flag parsing and validation for `wp aipt` commands. Every invalid flag ends the
// command with WP_CLI::error() before anything is logged or written.

if (!defined('ABSPATH')) {
	exit;
}

class AIPT_CLI_Args {

	/**
	 * @return array{0: string, 1: string[]} Source language and target languages.
	 */
	public static function languages(array $assoc_args): array {
		$languages = array_column(aipt_lang()->languages(), 'code');
		$from      = (string) ($assoc_args['from'] ?? aipt_lang()->default_language());
		if (!in_array($from, $languages, true)) {
			WP_CLI::error(sprintf("Unknown source language '%s'. Available: %s.", $from, implode(', ', $languages)));
		}
		$targets = array_values(array_unique(self::csv($assoc_args['to'] ?? '')));
		if (!$targets) {
			WP_CLI::error('--to is required.');
		}
		foreach ($targets as $target) {
			if (!in_array($target, $languages, true)) {
				WP_CLI::error(sprintf("Unknown target language '%s'. Available: %s.", $target, implode(', ', $languages)));
			}
			if ($target === $from) {
				WP_CLI::error(sprintf("Target language '%s' is the source language.", $target));
			}
		}
		return array($from, $targets);
	}

	// The global switch: checked at the start of every command (AIPT_CLI also re-reads it
	// before each record of a bulk run).
	public static function require_enabled(): void {
		if (!AIPT_Settings::enabled()) {
			WP_CLI::error('AI Translator is disabled in Settings > AI Translator.');
		}
	}

	public static function require_user(): void {
		if (!get_current_user_id()) {
			WP_CLI::error('No current user. Pass --user=<login>: capabilities are checked when writing translations.');
		}
	}

	/**
	 * --post_type or --ids (exactly one). Without --post_type every enabled type applies.
	 *
	 * @return array{0: string[], 1: int[]} Post types and source IDs.
	 */
	public static function post_selection(array $assoc_args): array {
		$enabled = array_values(array_filter(
			AIPT_Settings::get()['post_types'],
			static fn(string $type): bool => post_type_exists($type) && aipt_lang()->is_translated_post_type($type)
		));
		$has_types = isset($assoc_args['post_type']) && $assoc_args['post_type'] !== '';
		$has_ids   = isset($assoc_args['ids']) && $assoc_args['ids'] !== '';
		if ($has_types === $has_ids) {
			WP_CLI::error('Pass either --post_type or --ids (exactly one of them).');
		}

		$ids   = array();
		$types = $enabled;
		if ($has_types) {
			$types = self::csv($assoc_args['post_type']);
			foreach ($types as $type) {
				if (!in_array($type, $enabled, true)) {
					WP_CLI::error(sprintf("Post type '%s' is not enabled in Settings > AI Translator (or not translated by %s).", $type, AIPT_Lang_Loader::label()));
				}
			}
		} else {
			$ids = array_values(array_filter(array_map('absint', self::csv($assoc_args['ids']))));
			if (!$ids) {
				WP_CLI::error('--ids contains no valid post IDs.');
			}
		}
		if (!$types) {
			WP_CLI::error('No post types are enabled in Settings > AI Translator.');
		}
		return array($types, $ids);
	}

	// '' = skip existing translations.
	public static function mode(array $assoc_args): string {
		$mode = (string) ($assoc_args['mode'] ?? '');
		if ($mode !== '' && isset($assoc_args['skip-existing'])) {
			WP_CLI::error('--skip-existing and --mode are mutually exclusive.');
		}
		if ($mode !== '' && !in_array($mode, array('safe', 'overwrite'), true)) {
			WP_CLI::error('--mode must be safe or overwrite.');
		}
		return $mode;
	}

	public static function require_publish_caps(array $types): void {
		foreach ($types as $type) {
			$object = get_post_type_object($type);
			if (!$object || !current_user_can($object->cap->publish_posts)) {
				WP_CLI::error(sprintf("The current user cannot publish '%s' posts.", $type));
			}
		}
	}

	public static function taxonomies(array $assoc_args): array {
		$taxonomies = self::csv($assoc_args['taxonomy'] ?? '');
		if (!$taxonomies) {
			WP_CLI::error('--taxonomy is required.');
		}
		foreach ($taxonomies as $taxonomy) {
			if (!taxonomy_exists($taxonomy) || !aipt_lang()->is_translated_taxonomy($taxonomy)) {
				WP_CLI::error(sprintf("Taxonomy '%s' does not exist or is not translated by %s.", $taxonomy, AIPT_Lang_Loader::label()));
			}
			if (!current_user_can(get_taxonomy($taxonomy)->cap->edit_terms)) {
				WP_CLI::error(sprintf("The current user cannot edit '%s' terms.", $taxonomy));
			}
		}
		return $taxonomies;
	}

	public static function require_api_key(bool $dry_run): void {
		if (!$dry_run && AIPT_Settings::api_key() === '') {
			WP_CLI::error('API key is not set (Settings > AI Translator).');
		}
	}

	public static function csv($value): array {
		return array_values(array_filter(array_map('trim', explode(',', (string) $value)), 'strlen'));
	}

	public static function date(array $assoc_args, string $key): string {
		if (!isset($assoc_args[$key]) || $assoc_args[$key] === '') {
			return '';
		}
		$value = (string) $assoc_args[$key];
		$date  = DateTime::createFromFormat('!Y-m-d', $value);
		if (!$date || $date->format('Y-m-d') !== $value) {
			WP_CLI::error(sprintf('--%s must be a date in Y-m-d format.', $key));
		}
		return $value;
	}

	public static function shard(array $assoc_args): ?array {
		if (!isset($assoc_args['shard']) || $assoc_args['shard'] === '') {
			return null;
		}
		if (!preg_match('~^(\d+)/(\d+)$~', (string) $assoc_args['shard'], $matches)
			|| (int) $matches[2] < 1
			|| (int) $matches[1] >= (int) $matches[2]) {
			WP_CLI::error('--shard must be i/n with 0 <= i < n, e.g. --shard=0/4.');
		}
		return array((int) $matches[1], (int) $matches[2]);
	}
}
