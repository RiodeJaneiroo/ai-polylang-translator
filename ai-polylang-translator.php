<?php
/**
 * Plugin Name: AI Translator for Polylang & WPML
 * Description: AI translation of posts, WooCommerce products, terms and ACF fields into other Polylang or WPML languages via Vercel AI Gateway.
 * Version: 1.8.0
 * Author: Vadym Zm
 * Author URI: https://artzm.dev/
 * Text Domain: ai-polylang-translator
 * Domain Path: /languages
 * Requires at least: 6.0
 * Tested up to: 7.0
 * Requires PHP: 8.1
 */

if (!defined('ABSPATH')) {
	exit;
}

define('AIPT_VERSION', '1.8.0');
define('AIPT_FILE', __FILE__);
define('AIPT_DIR', plugin_dir_path(__FILE__));
define('AIPT_URL', plugin_dir_url(__FILE__));

add_action('init', function () {
	load_plugin_textdomain('ai-polylang-translator', false, dirname(plugin_basename(__FILE__)) . '/languages');
});

register_activation_hook(__FILE__, function () {
	add_option('aipt_api_key', '', '', 'no');
	add_option('aipt_settings', array(), '', 'no');
});

register_deactivation_hook(__FILE__, function () {
	// AIPT_Auto events carry args (post, user): clear every instance of the hook.
	wp_unschedule_hook('aipt_auto_translate');
});

function aipt_acf_active(): bool {
	return function_exists('acf_get_field_groups') && function_exists('update_field') && function_exists('get_field_objects');
}

function aipt_yoast_active(): bool {
	return defined('WPSEO_VERSION');
}

// $message is called inside the notice: translations load on init, after plugins_loaded.
function aipt_admin_notice(callable $message): void {
	add_action('admin_notices', function () use ($message) {
		if (!current_user_can('activate_plugins')) {
			return;
		}
		echo '<div class="notice notice-error"><p>' . esc_html($message()) . '</p></div>';
	});
}

add_action('plugins_loaded', function () {
	require_once AIPT_DIR . 'includes/lang/class-aipt-lang-loader.php';
	$backend = AIPT_Lang_Loader::detect();
	if ($backend === null) {
		aipt_admin_notice(static fn(): string => __('AI Translator for Polylang & WPML requires the Polylang or WPML plugin to be active.', 'ai-polylang-translator'));
		return;
	}

	require_once AIPT_DIR . 'includes/class-aipt-settings.php';
	require_once AIPT_DIR . 'includes/class-aipt-settings-auto.php';
	require_once AIPT_DIR . 'includes/class-aipt-settings-advanced.php';
	require_once AIPT_DIR . 'includes/class-aipt-acf-schema.php';
	require_once AIPT_DIR . 'includes/class-aipt-safe-merge.php';
	require_once AIPT_DIR . 'includes/class-aipt-gateway.php';
	require_once AIPT_DIR . 'includes/class-aipt-blocks.php';
	require_once AIPT_DIR . 'includes/class-aipt-block-attrs-config.php';
	require_once AIPT_DIR . 'includes/class-aipt-block-attrs.php';
	require_once AIPT_DIR . 'includes/class-aipt-block-refs.php';
	require_once AIPT_DIR . 'includes/class-aipt-extractor.php';
	require_once AIPT_DIR . 'includes/class-aipt-woo.php';
	require_once AIPT_DIR . 'includes/class-aipt-job-store.php';
	require_once AIPT_DIR . 'includes/class-aipt-job.php';
	require_once AIPT_DIR . 'includes/class-aipt-usage.php';
	require_once AIPT_DIR . 'includes/class-aipt-slug.php';
	require_once AIPT_DIR . 'includes/class-aipt-yoast.php';
	require_once AIPT_DIR . 'includes/class-aipt-meta-copy.php';
	require_once AIPT_DIR . 'includes/class-aipt-writer.php';
	require_once AIPT_DIR . 'includes/class-aipt-record.php';
	require_once AIPT_DIR . 'includes/class-aipt-job-builder.php';
	require_once AIPT_DIR . 'includes/class-aipt-pipeline.php';
	require_once AIPT_DIR . 'includes/class-aipt-finalize.php';
	require_once AIPT_DIR . 'includes/class-aipt-terms.php';
	require_once AIPT_DIR . 'includes/class-aipt-strings.php';
	require_once AIPT_DIR . 'includes/class-aipt-select.php';
	require_once AIPT_DIR . 'includes/class-aipt-runner-context.php';
	require_once AIPT_DIR . 'includes/class-aipt-runner.php';
	require_once AIPT_DIR . 'includes/class-aipt-auto-status.php';
	require_once AIPT_DIR . 'includes/class-aipt-auto-run.php';
	require_once AIPT_DIR . 'includes/class-aipt-auto.php';
	require_once AIPT_DIR . 'includes/class-aipt-cache.php';
	require_once AIPT_DIR . 'includes/class-aipt-metabox.php';

	// Without an adapter only the settings page (API key, cost log) loads: no metabox,
	// no auto-translation hooks, no `wp aipt` command.
	$lang = AIPT_Lang_Loader::load();
	if (!$lang) {
		aipt_admin_notice(static fn(): string => __('Polylang and WPML are both active; AI Translator cannot translate until one of them is deactivated.', 'ai-polylang-translator'));
		if (is_admin()) {
			new AIPT_Settings();
		}
		return;
	}

	$lang->register_hooks();
	AIPT_Auto::register();
	AIPT_Cache::register();
	AIPT_Yoast::register();
	AIPT_Block_Refs::register();

	// The bulk page and its AJAX actions (admin-ajax.php is admin too).
	if (is_admin()) {
		require_once AIPT_DIR . 'includes/class-aipt-bulk-locator.php';
		require_once AIPT_DIR . 'includes/class-aipt-bulk-jobs.php';
		require_once AIPT_DIR . 'includes/class-aipt-bulk-terms.php';
		require_once AIPT_DIR . 'includes/class-aipt-bulk-request.php';
		require_once AIPT_DIR . 'includes/class-aipt-bulk-ajax.php';
		require_once AIPT_DIR . 'includes/class-aipt-bulk-strings.php';
		require_once AIPT_DIR . 'includes/class-aipt-bulk-page.php';
		new AIPT_Settings();
		new AIPT_Metabox();
		new AIPT_Bulk_Ajax();
		new AIPT_Bulk_Strings();
		new AIPT_Bulk_Page();
	}

	if (defined('WP_CLI') && WP_CLI) {
		require_once AIPT_DIR . 'includes/class-aipt-cli-args.php';
		require_once AIPT_DIR . 'includes/class-aipt-cli-log.php';
		require_once AIPT_DIR . 'includes/class-aipt-cli.php';
		WP_CLI::add_command('aipt', 'AIPT_CLI');
	}
}, 20);
