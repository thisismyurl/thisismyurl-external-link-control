<?php
/**
 * Uninstaller for External Link Control.
 *
 * Removes every option the plugin creates, clears its scheduled cron
 * event, and sweeps transients so an uninstall leaves no cruft in the
 * database.
 *
 * @package TIMU_ELC
 */

// If uninstall not called from WordPress, exit.
if ( ! defined( 'WP_UNINSTALL_PLUGIN' ) ) {
	exit;
}

// Delete every option the plugin stores.
delete_option( 'timu_elc_options' );              // Global toggles.
delete_option( 'timu_elc_domain_rules' );         // Per-domain rules table.
delete_option( 'timu_elc_broken_link_results' );  // Last broken-link scan.
delete_option( 'timu_elc_broken_link_ignored' );  // Owner's ignore list.

// Clear the weekly broken-link crawler cron event (all scheduled instances).
wp_clear_scheduled_hook( 'timu_elc_broken_link_check' );

// Delete REST inventory transients (keyed by md5 of query params).
// We cannot enumerate all md5 keys directly, so we use a $wpdb sweep.
global $wpdb;
$wpdb->query(
	"DELETE FROM {$wpdb->options}
	 WHERE option_name LIKE '_transient_timu_elc_inventory_%'
	    OR option_name LIKE '_transient_timeout_timu_elc_inventory_%'"
);

// Delete GitHub Updater site transients (keyed by md5 of repo slug).
$wpdb->query(
	"DELETE FROM {$wpdb->sitemeta}
	 WHERE meta_key LIKE '_site_transient_timu_gh_release_%'
	    OR meta_key LIKE '_site_transient_timeout_timu_gh_release_%'"
);

// Clear the cache to ensure a clean state.
wp_cache_flush();
