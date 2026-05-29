<?php
/**
 * Uninstaller for External Link Control.
 *
 * Removes every option the plugin creates and clears its scheduled cron
 * event so an uninstall leaves no cruft in the database.
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

// Clear the cache to ensure a clean state.
wp_cache_flush();
