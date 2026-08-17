<?php
/**
 * Fired when the plugin is uninstalled.
 *
 * Removes only SEO Repair Kit-owned data when the plugin is deleted, including:
 * - Custom database tables
 * - Options from wp_options
 * - Transients
 * - Post meta
 * - Term meta
 * - Scheduled cron events
 *
 * @link       https://seorepairkit.com
 * @since      1.0.1
 * @version    2.1.9
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

	// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared, PluginCheck.Security.DirectDB.UnescapedDBParameter -- Exact table names come from the owned allowlist below.
	$wpdb->query( "DROP TABLE IF EXISTS `{$table_name}`" ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared, PluginCheck.Security.DirectDB.UnescapedDBParameter -- Exact owned table allowlist.
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
 * Delete options with tightly scoped SEO Repair Kit-owned prefixes.
 *
 * This intentionally does not delete broad patterns such as srk_%.
 *
 * @param array $prefixes Option-name prefixes.
 * @return void
 */
function srk_uninstall_delete_options_by_prefix( $prefixes ) {
	global $wpdb;

	foreach ( (array) $prefixes as $prefix ) {
		$like = $wpdb->esc_like( (string) $prefix ) . '%';

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Uninstall cleanup only; table name is the core options table.
		$option_names = $wpdb->get_col(
			$wpdb->prepare(
				"SELECT option_name FROM {$wpdb->options} WHERE option_name LIKE %s",
				$like
			)
		);

		foreach ( (array) $option_names as $option_name ) {
			delete_option( $option_name );
		}
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
 * Delete only explicitly owned SEO Repair Kit transients.
 *
 * @param array $transient_names Transient names.
 * @return void
 */
function srk_uninstall_delete_transients( $transient_names ) {
	foreach ( (array) $transient_names as $transient_name ) {
		delete_transient( $transient_name );
		delete_site_transient( $transient_name );
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
		'srk_broken_links_scan_event',
		'srk_weekly_summary_email_event',
		'srk_keytrack_weekly_summary_event',
		'srk_spam_monitor_scheduled_scan',
		'srk_spam_monitor_scheduled_serp_scan',
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
| 2b. DROP SPAM MONITOR TABLES
|
| Delegates to SRK_Spam_Monitor_DB::drop_tables() which handles all Spam
| Monitor tables and deletes the DB version option in one call.
| Only runs if the DB class is available (safe for fresh installs where
| the Spam Monitor was never activated).
|--------------------------------------------------------------------------
*/
$srk_sm_db_file = plugin_dir_path( __FILE__ ) . 'includes/spam-monitor-backend/class-srk-spam-monitor-db.php';

if ( ! class_exists( 'SRK_Spam_Monitor_DB' ) && file_exists( $srk_sm_db_file ) ) {
	require_once $srk_sm_db_file;
}

if ( class_exists( 'SRK_Spam_Monitor_DB' ) ) {
	// Drops: srk_spam_monitor_urls, srk_spam_monitor_baselines, srk_spam_monitor_scans,
	//        srk_spam_monitor_scan_results, srk_spam_monitor_issues,
	//        srk_spam_monitor_fix_actions, srk_spam_monitor_google_actions,
	//        srk_spam_monitor_rules, srk_spam_monitor_alerts,
	//        srk_spam_monitor_serp_scans, srk_spam_monitor_serp_results
	// Also deletes: srk_spam_monitor_db_version option.
	SRK_Spam_Monitor_DB::drop_tables();
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
		'srk_plugin_version',
		'srk_plugin_id',
		'srk_plugin_instance_id',
		'srk_update_pending',
		'srk_setup',
		'srk_should_run_modal_onboarding',
		'srk_onboarding_has_run',
		'srk_site_info_consent',
		'srk_site_health_info_sent',
		'srk_last_successful_check',
		'srk_notification_email',
		'td_blc_saved_post_types',
		'srk_keytrack_enabled',
		'srk_keytrack_alerts_enabled',
		'srk_broken_links_notify_enabled',
		'srk_weekly_report_enabled',
		'srk_weekly_report_last_status',
		'srk_alt_scan_enabled',
		'srk_sitemap_manager_settings',
		'srk_sitemap_control_settings',
		'srk_schema_defaults',
		'srk_last_links_snapshot',
		'srk_last_links_scan_at',
		'srk_links_schedule',
		'srk_link_scanner_schedule_settings',
		'srk_link_scanner_cursor',
		'srk_link_scanner_last_alert_hash',
		'srk_link_scanner_url_status_cache',
		'srk_link_scanner_dashboard_alert',
		'srk_link_scanner_unread_alerts',
		'srk_link_scanner_storage_checked',
		'srk_redirection_enabled',
		'srk_redirection_default_code',
		'srk_enable_logging',
		'srk_log_retention',
		'srk_auto_redirect',
		'srk_monitor_404s',
		'srk_404_monitoring_enabled',
		'srk_redirect_cache_time',
		'srk_ip_collection',
		'srk_geolocation_enabled',
		'srk_enable_detailed_logging',
		'srk_enable_htaccess_sync',
		'srk_htaccess_write_all',
		'srk_redirection_rules_signature',
		'srk_smart_redirect_post_types',
		'srk_smart_archive_redirect_settings',
		'srk_smart_redirect_last_generation_summary',
		'srk_pro_intent',
		'srk_meta',
		'srk_meta_global_settings',
		'srk_meta_archives_settings',
		'srk_meta_content_types_settings',
		'srk_meta_taxonomies_settings',
		'srk_title_separator',
		'srk_custom_separator',
		'srk_home_title',
		'srk_home_desc',
		'srk_title_template',
		'srk_desc_template',
		'srk_meta_description_length',
		'srk_meta_default_index',
		'srk_meta_default_follow',
		'srk_website_name',
		'srk_alt_website_name',
		'srk_org_type',
		'srk_org_name',
		'srk_org_desc',
		'srk_contact_email',
		'srk_contact_phone',
		'srk_country_code',
		'srk_founding_date',
		'srk_employee_range',
		'srk_org_logo',
		'srk_noindex_search',
		'srk_noindex_attachment',
		'srk_noindex_pagination',
		'srk_enable_author_archive',
		'srk_author_archive_title',
		'srk_enable_date_archive',
		'srk_date_archive_title',
		'srk_search_title',
		'srk_global_robots_meta',
		'srk_use_default_settings',
		'srk_use_meta_keywords',
		'srk_run_shortcodes',
		'srk_paged_format',
		'srk_paged_separator',
		'srk_paged_format_type',
		'srk_robots_meta',
		'srk_meta_migration_done',
		'srk_settings_migrated',
		'srk_content_tags_migration_done',
		'srk_global_settings',
		'srk_archives_settings',
		'srk_robots_txt_content',
		'srk_robots_txt_last_updated',
		'srk_llms_generator_settings',
		'srk_llms_txt_content',
		'srk_llms_txt_last_updated',
		'srk_llms_rewrite_flushed',
		'srk_spam_monitor_db_version',
		'srk_spam_monitor_alert_settings',
		'srk_spam_monitor_serp_rules_sync_status',
		'srk_spam_monitor_serp_trial_status',
		'srk_spam_monitor_serp_provider_status',
		'srk_spam_monitor_serpapi_key_status',
		'srk_spam_monitor_schedule_settings',
		'srk_spam_monitor_schedule_lock',
		'srk_spam_monitor_scan_lock',
	)
);

/*
|--------------------------------------------------------------------------
| 4. DELETE TIGHTLY SCOPED DYNAMIC OPTIONS
|--------------------------------------------------------------------------
*/
srk_uninstall_delete_options_by_prefix(
	array(
		'srk_schema_assignment_',
		'srk_license_info_',
		'srk_google_indexing_',
		'srk_google_integrity_',
		'_transient_srk_license_',
		'_transient_timeout_srk_license_',
		'_transient_srk_license_status_',
		'_transient_timeout_srk_license_status_',
		'_transient_srk_fields_',
		'_transient_timeout_srk_fields_',
		'_transient_srk_posts_',
		'_transient_timeout_srk_posts_',
		'_transient_srk_schema_conflicts_',
		'_transient_timeout_srk_schema_conflicts_',
		'_transient_srk_user_meta_keys',
		'_transient_timeout_srk_user_meta_keys',
		'_transient_srk_sm_sitemap_health_',
		'_transient_timeout_srk_sm_sitemap_health_',
	)
);

/*
|--------------------------------------------------------------------------
| 5. DELETE EXACT OWNED TRANSIENTS
|--------------------------------------------------------------------------
*/
srk_uninstall_delete_transients(
	array(
		'srk_404_statistics',
		'srk_404_table_exists',
		'srk_archives_saved',
		'srk_link_scanner_run_lock',
		'srk_link_scanner_storage_checked',
		'srk_migration_log',
		'srk_pro_license_status',
		'srk_redirected_from_repair_kit',
		'srk_redirection_migration_notice',
		'srk_required_tables_check',
		'srk_robots_txt_content',
		'srk_schema_count',
		'srk_sending_site_health_info',
		'srk_sitekit_activated',
		'srk_table_creation_check',
		'srk_user_meta_keys',
	)
);

/*
|--------------------------------------------------------------------------
| 6. DELETE EXACT OWNED POST META
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

foreach ( $meta_keys_exact as $meta_key ) {
	delete_post_meta_by_key( $meta_key );
}

/*
|--------------------------------------------------------------------------
| 7. DELETE EXACT OWNED TERM META
|--------------------------------------------------------------------------
*/
delete_metadata( 'term', 0, '_srk_term_settings', '', true );
