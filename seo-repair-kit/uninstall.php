<?php
/**
 * Fired when the plugin is uninstalled.
 *
 * @link       https://seorepairkit.com
 * @since      1.0.1
 * @package    Seo_Repair_Kit
 */

// If uninstall not called from WordPress, then exit.
if ( ! defined( 'WP_UNINSTALL_PLUGIN' ) ) {
	exit;
}

// To Drop Redirection table.
global $wpdb;
$srkit_tablename = $wpdb->prefix . "srkit_redirection_table";

// Drop the table if it exists
$wpdb->query( $wpdb->prepare( "DROP TABLE IF EXISTS $srkit_tablename" ) );
