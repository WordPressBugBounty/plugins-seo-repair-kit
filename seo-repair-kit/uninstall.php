<?php
/**
 * Fired when the plugin is uninstalled.
 *
 * @link       https://seorepairkit.com
 * @since      1.0.1
 * @version    2.0.0
 * @package    Seo_Repair_Kit
 */

// If uninstall not called from WordPress, then exit.
if ( ! defined( 'WP_UNINSTALL_PLUGIN' ) ) {
    exit;
}

global $wpdb;

// Drop the Keytrack settings table
$srkit_keytrack_table = $wpdb->prefix . 'srkit_keytrack_settings';
$wpdb->query("DROP TABLE IF EXISTS $srkit_keytrack_table");

// Drop the Google Search Console data table
$gsc_data_table = $wpdb->prefix . 'srkit_gsc_data';
$wpdb->query("DROP TABLE IF EXISTS $gsc_data_table");

// Drop the Redirection table
$srkit_redirection_table = $wpdb->prefix . 'srkit_redirection_table';
$wpdb->query("DROP TABLE IF EXISTS $srkit_redirection_table");
