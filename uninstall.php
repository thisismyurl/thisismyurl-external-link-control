<?php
/**
 * Uninstaller for external links support
 * .
 * 
 * Updated: 1.251229
 * 
 */

// If uninstall not called from WordPress, exit.
if ( ! defined( 'WP_UNINSTALL_PLUGIN' ) ) {
	exit;
}

// Delete the plugin options from the database.
delete_option( 'timu_elc_options' );

// Clear the cache to ensure a clean state.
wp_cache_flush();