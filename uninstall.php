<?php

if (!defined('WP_UNINSTALL_PLUGIN')) {
	exit;
}

delete_option('aipt_api_key');
delete_option('aipt_settings');
delete_option('aipt_usage_log');
delete_option('aipt_usage_totals');

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
