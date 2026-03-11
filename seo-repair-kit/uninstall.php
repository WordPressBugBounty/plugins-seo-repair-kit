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
// Escape table name by removing backticks and wrapping in backticks
$wpdb->query( "DROP TABLE IF EXISTS `" . esc_sql( str_replace( '`', '', $srkit_keytrack_table ) ) . "`" );

// Drop the Google Search Console data table
$gsc_data_table = $wpdb->prefix . 'srkit_gsc_data';
$wpdb->query( "DROP TABLE IF EXISTS `" . esc_sql( str_replace( '`', '', $gsc_data_table ) ) . "`" );

// Drop the Redirection table
$srkit_redirection_table = $wpdb->prefix . 'srkit_redirection_table';
$wpdb->query( "DROP TABLE IF EXISTS `" . esc_sql( str_replace( '`', '', $srkit_redirection_table ) ) . "`" );

// Drop the Redirection logs table
$srkit_redirection_logs_table = $wpdb->prefix . 'srkit_redirection_logs';
$wpdb->query( "DROP TABLE IF EXISTS `" . esc_sql( str_replace( '`', '', $srkit_redirection_logs_table ) ) . "`" );

// Drop the 404 logs table
$srkit_404_logs_table = $wpdb->prefix . 'srkit_404_logs';
$wpdb->query( "DROP TABLE IF EXISTS `" . esc_sql( str_replace( '`', '', $srkit_404_logs_table ) ) . "`" );

// Drop the plugin settings table (consent & settings)
$srkit_plugin_settings_table = $wpdb->prefix . 'srkit_plugin_settings';
$wpdb->query( "DROP TABLE IF EXISTS `" . esc_sql( str_replace( '`', '', $srkit_plugin_settings_table ) ) . "`" );

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

// Delete ALL srk_meta related options
$wpdb->query( "DELETE FROM {$wpdb->options} WHERE option_name LIKE 'srk_meta%'" );

// Delete ALL migration flags
$wpdb->query( "DELETE FROM {$wpdb->options} WHERE option_name LIKE '%_migrated'" );
$wpdb->query( "DELETE FROM {$wpdb->options} WHERE option_name LIKE 'srk_%_migration_%'" );
$wpdb->query( "DELETE FROM {$wpdb->options} WHERE option_name = 'srk_meta_migration_done'" );
$wpdb->query( "DELETE FROM {$wpdb->options} WHERE option_name = 'srk_settings_migrated'" );

// Delete all individual settings (just to be safe)
$wpdb->query( "DELETE FROM {$wpdb->options} WHERE option_name LIKE 'srk_%'" );


// ============================================================================
// 4. DELETE ANY ADDITIONAL POSTMETA (ENHANCE EXISTING SECTION)
// ============================================================================

// Also add specific meta keys to be safe:
$wpdb->query( "DELETE FROM {$wpdb->postmeta} WHERE meta_key IN (
    '_srk_meta_title',
    '_srk_meta_description',
    '_srk_focus_keyword',
    '_srk_meta_keywords',
    '_srk_canonical_url',
    '_srk_advanced_settings'
)" );

// Delete any other possible srk postmeta patterns
$wpdb->query( "DELETE FROM {$wpdb->postmeta} WHERE meta_key LIKE '_srk_%'" );