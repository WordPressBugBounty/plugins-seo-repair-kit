<?php
/**
 * Fired when the plugin is uninstalled.
 *
 * Removes all SEO Repair Kit data including:
 * - Custom database tables
 * - Options from wp_options
 * - Transients
 * - Post meta
 * - Term meta
 * - Scheduled cron events
 *
 * @link       https://seorepairkit.com
 * @since      1.0.1
 * @version    2.1.7
 * @package    Seo_Repair_Kit
 */

if ( ! defined( 'WP_UNINSTALL_PLUGIN' ) ) {
	exit;
}

global $wpdb;

/**
 * Safely drop a custom table.
 *
 * @param string $table_name Full table name.
 * @return void
 */
function srk_uninstall_drop_table( $table_name ) {
	global $wpdb;

	$table_name = str_replace( '`', '', (string) $table_name );

	// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
	$wpdb->query( "DROP TABLE IF EXISTS `{$table_name}`" );
}

/**
 * Safely delete options by exact name.
 *
 * @param array $option_names Option names.
 * @return void
 */
function srk_uninstall_delete_options( $option_names ) {
	foreach ( (array) $option_names as $option_name ) {
		delete_option( $option_name );
		delete_site_option( $option_name );
	}
}

/**
 * Safely clear cron hooks.
 *
 * @param array $hooks Cron hooks.
 * @return void
 */
function srk_uninstall_clear_cron_hooks( $hooks ) {
	foreach ( (array) $hooks as $hook ) {
		wp_clear_scheduled_hook( $hook );
	}
}

/**
 * Delete options using LIKE patterns.
 *
 * @param array $patterns LIKE patterns.
 * @return void
 */
function srk_uninstall_delete_option_patterns( $patterns ) {
	global $wpdb;

	foreach ( (array) $patterns as $pattern ) {
		if ( false === strpos( $pattern, '%' ) && false === strpos( $pattern, '_' ) ) {
			$like = esc_sql( $pattern );
			// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
			$wpdb->query( "DELETE FROM {$wpdb->options} WHERE option_name = '{$like}'" );

			if ( isset( $wpdb->sitemeta ) ) {
				// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
				$wpdb->query( "DELETE FROM {$wpdb->sitemeta} WHERE meta_key = '{$like}'" );
			}
		} else {
			$like = esc_sql( $pattern );
			// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
			$wpdb->query( "DELETE FROM {$wpdb->options} WHERE option_name LIKE '{$like}'" );

			if ( isset( $wpdb->sitemeta ) ) {
				// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
				$wpdb->query( "DELETE FROM {$wpdb->sitemeta} WHERE meta_key LIKE '{$like}'" );
			}
		}
	}
}

/**
 * Delete transients by prefix.
 *
 * @param string $prefix Prefix without transient wrapper.
 * @return void
 */
function srk_uninstall_delete_transients_by_prefix( $prefix ) {
	global $wpdb;

	$prefix = esc_sql( $wpdb->esc_like( $prefix ) );

	// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
	$wpdb->query( "DELETE FROM {$wpdb->options} WHERE option_name LIKE '_transient_{$prefix}%'" );
	// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
	$wpdb->query( "DELETE FROM {$wpdb->options} WHERE option_name LIKE '_transient_timeout_{$prefix}%'" );

	if ( isset( $wpdb->sitemeta ) ) {
		// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
		$wpdb->query( "DELETE FROM {$wpdb->sitemeta} WHERE meta_key LIKE '_site_transient_{$prefix}%'" );
		// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
		$wpdb->query( "DELETE FROM {$wpdb->sitemeta} WHERE meta_key LIKE '_site_transient_timeout_{$prefix}%'" );
	}
}

/*
|--------------------------------------------------------------------------
| 1. CLEAR SCHEDULED CRON EVENTS
|--------------------------------------------------------------------------
*/
srk_uninstall_clear_cron_hooks(
	array(
		'srk_link_scanner_cron_scan',
		'srk_weekly_summary_email_event',
		'srk_keytrack_weekly_summary_event',
	)
);

/*
|--------------------------------------------------------------------------
| 2. DROP ALL CUSTOM DATABASE TABLES
|--------------------------------------------------------------------------
*/
$tables_to_drop = array(
	$wpdb->prefix . 'srkit_keytrack_settings',
	$wpdb->prefix . 'srkit_gsc_data',
	$wpdb->prefix . 'srkit_redirection_table',
	$wpdb->prefix . 'srkit_redirection_logs',
	$wpdb->prefix . 'srkit_404_logs',
	$wpdb->prefix . 'srkit_plugin_settings',
	$wpdb->prefix . 'srk_link_scan_runs',
	$wpdb->prefix . 'srk_link_scan_alerts',
	$wpdb->prefix . 'srkit_smart_redirects',
);

foreach ( $tables_to_drop as $table_name ) {
	srk_uninstall_drop_table( $table_name );
}

/*
|--------------------------------------------------------------------------
| 3. DELETE EXACT OPTIONS
|--------------------------------------------------------------------------
*/
srk_uninstall_delete_options(
	array(
		'seo_repair_kit_version',
		'seo_repair_kit_db_version',
		'srk_sitemap_manager_settings',
		'srk_sitemap_control_settings',
		'srk_last_links_snapshot',
		'srk_links_schedule',
		'srk_link_scanner_schedule_settings',
		'srk_link_scanner_cursor',
		'srk_link_scanner_last_alert_hash',
		'srk_link_scanner_url_status_cache',
		'srk_link_scanner_dashboard_alert',
		'srk_link_scanner_unread_alerts',
		'srk_link_scanner_storage_checked',
		'srk_smart_redirect_post_types',
		'srk_meta_migration_done',
		'srk_settings_migrated',
	)
);

/*
|--------------------------------------------------------------------------
| 4. DELETE OPTION PATTERNS
|--------------------------------------------------------------------------
*/
srk_uninstall_delete_option_patterns(
	array(
		'srk_%',
		'td_blc_%',
		'srk_meta%',
		'%_migrated',
		'srk_%_migration_%',
	)
);

/*
|--------------------------------------------------------------------------
| 5. DELETE TRANSIENTS
|--------------------------------------------------------------------------
*/
srk_uninstall_delete_transients_by_prefix( 'srk_' );

/*
|--------------------------------------------------------------------------
| 6. DELETE POST META
|--------------------------------------------------------------------------
*/
$meta_keys_exact = array(
	'_srk_meta_title',
	'_srk_meta_description',
	'_srk_focus_keyword',
	'_srk_meta_keywords',
	'_srk_canonical_url',
	'_srk_advanced_settings',
);

$meta_keys_exact_sql = "'" . implode( "','", array_map( 'esc_sql', $meta_keys_exact ) ) . "'";

// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
$wpdb->query( "DELETE FROM {$wpdb->postmeta} WHERE meta_key LIKE 'srk_%'" );
// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
$wpdb->query( "DELETE FROM {$wpdb->postmeta} WHERE meta_key LIKE '_srk_%'" );
// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
$wpdb->query( "DELETE FROM {$wpdb->postmeta} WHERE meta_key IN ({$meta_keys_exact_sql})" );

/*
|--------------------------------------------------------------------------
| 7. DELETE TERM META
|--------------------------------------------------------------------------
*/
// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
$wpdb->query( "DELETE FROM {$wpdb->termmeta} WHERE meta_key = '_srk_term_settings'" );
// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
$wpdb->query( "DELETE FROM {$wpdb->termmeta} WHERE meta_key LIKE 'srk_%'" );
// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
$wpdb->query( "DELETE FROM {$wpdb->termmeta} WHERE meta_key LIKE '_srk_%'" );