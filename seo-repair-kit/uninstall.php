<?php
/**
 * Fired when the plugin is uninstalled.
 *
 * This file removes all plugin data including:
 * - All custom database tables
 * - All options from wp_options table
 * - All transients
 * - All postmeta data
 * - All scheduled cron events
 *
 * @link       https://seorepairkit.com
 * @since      1.0.1
 * @version    2.1.0
 * @package    Seo_Repair_Kit
 */

// If uninstall not called from WordPress, then exit.
if ( ! defined( 'WP_UNINSTALL_PLUGIN' ) ) {
    exit;
}

global $wpdb;

// ============================================================================
// 1. DROP ALL CUSTOM DATABASE TABLES
// ============================================================================

// Drop the Keytrack settings table
$srkit_keytrack_table = $wpdb->prefix . 'srkit_keytrack_settings';
$wpdb->query( "DROP TABLE IF EXISTS $srkit_keytrack_table" );

// Drop the Google Search Console data table
$gsc_data_table = $wpdb->prefix . 'srkit_gsc_data';
$wpdb->query( "DROP TABLE IF EXISTS $gsc_data_table" );

// Drop the Redirection table
$srkit_redirection_table = $wpdb->prefix . 'srkit_redirection_table';
$wpdb->query( "DROP TABLE IF EXISTS $srkit_redirection_table" );

// Drop the Redirection logs table
$srkit_redirection_logs_table = $wpdb->prefix . 'srkit_redirection_logs';
$wpdb->query( "DROP TABLE IF EXISTS $srkit_redirection_logs_table" );

// Drop the 404 logs table
$srkit_404_logs_table = $wpdb->prefix . 'srkit_404_logs';
$wpdb->query( "DROP TABLE IF EXISTS $srkit_404_logs_table" );

// Drop the plugin settings table (consent & settings)
$srkit_plugin_settings_table = $wpdb->prefix . 'srkit_plugin_settings';
$wpdb->query( "DROP TABLE IF EXISTS $srkit_plugin_settings_table" );

// ============================================================================
// 2. DELETE ALL OPTIONS FROM wp_options TABLE
// ============================================================================

// Delete all options with 'srk_' prefix
$wpdb->query( "DELETE FROM {$wpdb->options} WHERE option_name LIKE 'srk_%'" );

// Delete all options with 'td_blc_' prefix (legacy options)
$wpdb->query( "DELETE FROM {$wpdb->options} WHERE option_name LIKE 'td_blc_%'" );

// Delete plugin version option
$wpdb->query( "DELETE FROM {$wpdb->options} WHERE option_name = 'seo_repair_kit_version'" );

// ============================================================================
// 3. DELETE ALL TRANSIENTS (temporary cached data)
// ============================================================================

// Delete all transients with 'srk_' prefix
$wpdb->query( "DELETE FROM {$wpdb->options} WHERE option_name LIKE '_transient_srk_%'" );
$wpdb->query( "DELETE FROM {$wpdb->options} WHERE option_name LIKE '_transient_timeout_srk_%'" );

// ============================================================================
// 4. DELETE ALL POSTMETA DATA
// ============================================================================

// Delete schema-related postmeta
$wpdb->query( "DELETE FROM {$wpdb->postmeta} WHERE meta_key LIKE 'srk_%'" );
