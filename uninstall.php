<?php

if (!defined('WP_UNINSTALL_PLUGIN')) {
	exit;
}

delete_option('aipt_api_key');
delete_option('aipt_settings');
