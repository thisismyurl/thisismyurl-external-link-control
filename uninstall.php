<?php
/**
 * Uninstaller for External Link Control by thisismyurl.com
 *
 * Removes all plugin options and transients on uninstall.
 *
 * @package TIMU_ELC
 */

// If uninstall not called from WordPress, exit.
if ( ! defined( 'WP_UNINSTALL_PLUGIN' ) ) {
	exit;
}

// Delete the primary plugin options.
delete_option( 'timu_elc_options' );

// Delete the per-domain rules table.
delete_option( 'timu_elc_domain_rules' );

// Delete the broken-link scan results.
delete_option( 'timu_elc_broken_link_results' );

// Delete REST inventory transients (keyed by md5 of query params).
// We cannot enumerate all md5 keys directly, so we use a global $wpdb sweep.
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
