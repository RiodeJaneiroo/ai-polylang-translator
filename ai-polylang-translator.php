<?php
/**
 * Plugin Name: AI Polylang Translator
 * Description: AI-перевод записей и ACF-полей на другие языки Polylang через Vercel AI Gateway.
 * Version: 1.1.1
 * Author: Vadym Zm
 * Author URI: https://artzm.dev/
 * Text Domain: ai-polylang-translator
 * Requires at least: 6.0
 * Tested up to: 7.0
 * Requires PHP: 8.1
 */

if (!defined('ABSPATH')) {
	exit;
}

define('AIPT_VERSION', '1.1.1');
define('AIPT_FILE', __FILE__);
define('AIPT_DIR', plugin_dir_path(__FILE__));
define('AIPT_URL', plugin_dir_url(__FILE__));

register_activation_hook(__FILE__, function () {
	add_option('aipt_api_key', '', '', 'no');
	add_option('aipt_settings', array(), '', 'no');
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
				. esc_html__('AI Polylang Translator: для работы плагина требуется активный Polylang.', 'ai-polylang-translator')
				. '</p></div>';
		});
		return;
	}

	require_once AIPT_DIR . 'includes/class-aipt-settings.php';
	require_once AIPT_DIR . 'includes/class-aipt-acf-schema.php';
	require_once AIPT_DIR . 'includes/class-aipt-safe-merge.php';
	require_once AIPT_DIR . 'includes/class-aipt-gateway.php';
	require_once AIPT_DIR . 'includes/class-aipt-extractor.php';
	require_once AIPT_DIR . 'includes/class-aipt-job.php';
	require_once AIPT_DIR . 'includes/class-aipt-writer.php';
	require_once AIPT_DIR . 'includes/class-aipt-metabox.php';

	if (is_admin()) {
		new AIPT_Settings();
		new AIPT_Metabox();
	}
}, 20);
