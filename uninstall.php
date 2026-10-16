<?php

if (!defined('WP_UNINSTALL_PLUGIN')) {
	exit;
}

delete_option('aipt_api_key');
delete_option('aipt_settings');
delete_option('aipt_usage_log');
delete_option('aipt_usage_totals');
delete_option('aipt_usage_lock');
// Block text attributes parsed from wpml-config.xml files (AIPT_Block_Attrs_Config::TRANSIENT).
delete_transient('aipt_block_attrs');

// Pending auto-translation events, their markers and the users stored for scheduled posts (AIPT_Auto).
wp_unschedule_hook('aipt_auto_translate');
delete_post_meta_by_key('_aipt_auto_scheduled');
delete_post_meta_by_key('_aipt_auto_user');
// Status of the last automatic translation of each source post (AIPT_Auto_Status::META).
delete_post_meta_by_key('_aipt_auto_status');
// Job that wrote each translation (AIPT_Pipeline::JOB_META).
delete_post_meta_by_key('_aipt_job');

global $wpdb;

// Remove lock options written by AIPT_Job::acquire_finalize_lock().
// These are plain options (no TTL) that can survive indefinitely after a fatal error.
// Pattern covers: aipt_job_finalize_<uuid>
$wpdb->query(
	$wpdb->prepare(
		"DELETE FROM {$wpdb->options} WHERE option_name LIKE %s",
		$wpdb->esc_like('aipt_job_finalize_') . '%'
	)
);

// Remove durable jobs (AIPT_Job_Store: aipt_djob_<uuid>, aipt_djob_<uuid>_batch_<n>),
// per-batch locks left by a killed request (aipt_batch_<job_id>_<n>), and the bulk page's
// job locators (aipt_bulk_job_<post_id>_<lang>, AIPT_Bulk_Locator) and its
// prepare/terms/strings lock rows (aipt_bulk_prep_*, aipt_bulk_terms_*, aipt_bulk_strings_*).
$wpdb->query(
	$wpdb->prepare(
		"DELETE FROM {$wpdb->options} WHERE option_name LIKE %s OR option_name LIKE %s OR option_name LIKE %s",
		$wpdb->esc_like('aipt_djob_') . '%',
		$wpdb->esc_like('aipt_batch_') . '%',
		$wpdb->esc_like('aipt_bulk_') . '%'
	)
);

// Remove (translation group, target language) pair locks: aipt_pair_lock_<group_id>_<lang>.
$wpdb->query(
	$wpdb->prepare(
		"DELETE FROM {$wpdb->options} WHERE option_name LIKE %s",
		$wpdb->esc_like('aipt_pair_lock_') . '%'
	)
);

// Remove job transients and their timeout companions from the options table.
// Covers main job blobs (aipt_job_<uuid>), batch transients (aipt_job_<uuid>_batch_<n>),
// and any future aipt_job_* transients.
// Sites with an external object cache store transients there, not in the DB;
// this is a best-effort DB cleanup, which is the standard approach for uninstall.
$wpdb->query(
	$wpdb->prepare(
		"DELETE FROM {$wpdb->options} WHERE option_name LIKE %s OR option_name LIKE %s",
		$wpdb->esc_like('_transient_aipt_job_') . '%',
		$wpdb->esc_like('_transient_timeout_aipt_job_') . '%'
	)
);
