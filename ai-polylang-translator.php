<?php
/**
 * Plugin Name: AI Polylang Translator
 * Description: AI translation of posts and ACF fields into other Polylang languages via Vercel AI Gateway.
 * Version: 1.5.1
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

define('AIPT_VERSION', '1.5.1');
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

add_action('plugins_loaded', function () {
	if (!function_exists('pll_languages_list')) {
		add_action('admin_notices', function () {
			if (!current_user_can('activate_plugins')) {
				return;
			}
			echo '<div class="notice notice-error"><p>'
				. esc_html__('AI Polylang Translator requires the Polylang plugin to be active.', 'ai-polylang-translator')
				. '</p></div>';
		});
		return;
	}

	require_once AIPT_DIR . 'includes/class-aipt-settings.php';
	require_once AIPT_DIR . 'includes/class-aipt-settings-auto.php';
	require_once AIPT_DIR . 'includes/class-aipt-acf-schema.php';
	require_once AIPT_DIR . 'includes/class-aipt-safe-merge.php';
	require_once AIPT_DIR . 'includes/class-aipt-gateway.php';
	require_once AIPT_DIR . 'includes/class-aipt-extractor.php';
	require_once AIPT_DIR . 'includes/class-aipt-job.php';
	require_once AIPT_DIR . 'includes/class-aipt-usage.php';
	require_once AIPT_DIR . 'includes/class-aipt-slug.php';
	require_once AIPT_DIR . 'includes/class-aipt-writer.php';
	require_once AIPT_DIR . 'includes/class-aipt-pipeline.php';
	require_once AIPT_DIR . 'includes/class-aipt-record.php';
	require_once AIPT_DIR . 'includes/class-aipt-terms.php';
	require_once AIPT_DIR . 'includes/class-aipt-auto.php';
	require_once AIPT_DIR . 'includes/class-aipt-metabox.php';

	AIPT_Auto::register();

	if (is_admin()) {
		new AIPT_Settings();
		new AIPT_Metabox();
	}

	if (defined('WP_CLI') && WP_CLI) {
		require_once AIPT_DIR . 'includes/class-aipt-cli-args.php';
		require_once AIPT_DIR . 'includes/class-aipt-cli-log.php';
		require_once AIPT_DIR . 'includes/class-aipt-cli.php';
		WP_CLI::add_command('aipt', 'AIPT_CLI');
	}
}, 20);
