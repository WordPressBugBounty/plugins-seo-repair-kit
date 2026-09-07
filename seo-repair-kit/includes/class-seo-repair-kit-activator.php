<?php
if ( ! defined( 'ABSPATH' ) ) {
    exit;
}
/**
 * Fired during plugin activation.
 *
 * This class defines all code necessary to run during the plugin's activation.
 *
 * @link       https://seorepairkit.com
 * @since      1.0.1
 * @version    2.1.0
 * @author     TorontoDigits <support@torontodigits.com>
 */
class SeoRepairKit_Activator {

    const DB_VERSION = '2.1.11';
    const DB_VERSION_OPTION = 'srkit_db_version';
    const DB_UPDATE_LOCK_OPTION = 'srkit_db_update_lock';
    const DB_UPDATE_LOCK_TTL = 900;
    private static $schema_update_current = false;
    private static $schema_cache_invalidation_deferred = false;
    private static $schema_cache_invalidation_needed = false;

    /**
     * Function to run during activation.
     * Initializes tables and versioning.
     *
     * @since  1.0.1
     * @version 2.1.0
     * @access public
     * @return void
     */
    public static function activate() {

        // One-time onboarding auto-run after activation (first admin view)
        // ONLY if onboarding has never been completed before
        $srk_setup = get_option( 'srk_setup', array() );
        if ( empty( $srk_setup['completed'] ) || empty( $srk_setup['completed_at'] ) ) {
            add_option( 'srk_should_run_modal_onboarding', 'yes' );
        }
        
        // Manage DB updates once during activation.
        self::run_updates( true );

        // Call the activation notification function.
        self::srkit_activation_activity();

        // Call the API activation function.
        self::srk_send_data_to_api();

        update_option( 'seo_repair_kit_version', SEO_REPAIR_KIT_VERSION );
    }

    /**
     * Function to manage plugin updates.
     * Checks if version has changed and applies updates if necessary.
     *
     * @since  2.0.0
     * @access public
     * @return void
     */
    public static function update() {

	// Ensure get_plugin_data() exists.
	if ( ! function_exists( 'get_plugin_data' ) ) {
		require_once ABSPATH . 'wp-admin/includes/plugin.php';
	}

	$plugin_file = WP_PLUGIN_DIR . '/seo-repair-kit/seo-repair-kit.php';

	if ( ! file_exists( $plugin_file ) ) {
		return;
	}

	$plugin_data  = get_plugin_data( $plugin_file );
	$file_version = trim( $plugin_data['Version'] );

	if ( empty( $file_version ) ) {
		return;
	}

	$stored_version = get_option( 'seo_repair_kit_version' );

    if ( ! $stored_version ) {
        self::run_updates( true );
    } elseif ( version_compare( $stored_version, $file_version, '==' ) ) {
        self::run_updates( false );
    } else {
        self::run_updates( true );
    }

	$plugin_id = get_option( 'srk_plugin_id' );

	if ( ! $plugin_id ) {
		self::srk_send_data_to_api();
		$plugin_id = get_option( 'srk_plugin_id' );

		if ( ! $plugin_id ) {
			return;
		}
	}

	if ( ! $stored_version ) {
		update_option( 'seo_repair_kit_version', $file_version );
		return;
	}

	if ( version_compare( $stored_version, $file_version, '==' ) ) {
		return;
	}

	$event = version_compare( $file_version, $stored_version, '>' ) ? 'update' : 'downgrade';

	self::send_status_to_crm( $event );
	self::send_version_to_crm( $stored_version, $file_version );

	update_option( 'seo_repair_kit_version', $file_version );

	if ( 'update' === $event ) {
		$srk_setup = get_option( 'srk_setup', array() );

		if ( ! empty( $srk_setup['completed'] ) && ! empty( $srk_setup['completed_at'] ) ) {
			return;
		}

		$consent_option = get_option( 'srk_site_info_consent' );
		if ( 0 !== $consent_option ) {
			update_option( 'srk_should_run_modal_onboarding', 'yes' );
		}
	}
}

    private static function send_version_to_crm($old_version, $new_version) {

        $plugin_id = get_option('srk_plugin_id');
        if (!$plugin_id) return;

        // Send to the correct endpoint ONLY
        $api_url = SRK_API_Client::get_api_url(SRK_API_Client::ENDPOINT_PLUGIN_VERSION);

        $data = [
            'plugin_id'      => $plugin_id,
            'pluginversion'  => $new_version,
        ];

        wp_remote_post($api_url, [
            'body'    => wp_json_encode($data),
            'headers' => ['Content-Type' => 'application/json'],
        ]);
    }

    /**
     * Runs updates or creates tables if they don't exist.
     *
     * @since  2.0.0
     * @version 2.1.0
     * @access private
     * @return void
     */
    private static function run_updates( $force = false, $target_version = null ) {
        $target_version = self::DB_VERSION;
        $stored_db_version = get_option( self::DB_VERSION_OPTION, '' );

        if ( ! $force && self::$schema_update_current ) {
            return;
        }

        if ( version_compare( (string) $stored_db_version, $target_version, '>=' ) ) {
            self::$schema_update_current = true;
            return;
        }

        $lock_token = self::acquire_db_update_lock();
        if ( ! $lock_token ) {
            return;
        }

        try {
            $stored_db_version = get_option( self::DB_VERSION_OPTION, '' );
            if ( version_compare( (string) $stored_db_version, $target_version, '>=' ) ) {
                self::$schema_update_current = true;
                return;
            }

            $current_version = get_option( 'seo_repair_kit_version', '1.0.0' );
            self::$schema_cache_invalidation_deferred = true;
            self::$schema_cache_invalidation_needed = false;

            if ( version_compare( $current_version, '2.1.0', '<' ) ) {
                self::migrate_to_v2_1_0();
            }

            self::srkit_create_log_table();
            self::srkit_create_keytrack_table();
            self::create_gsc_data_table();
            self::create_redirection_logs_table();
            self::create_404_logs_table();
            self::create_smart_redirects_table();
            self::create_plugin_settings_table();
            self::create_link_scanner_history_tables();

            // Create or upgrade Spam Monitor tables with idempotent dbDelta checks.
            // Supports current SERP rules, alerts, SERP scans, and SERP results.
            self::maybe_create_spam_monitor_tables();

            if ( ! self::verify_required_schema() ) {
                self::$schema_update_current = false;
                return;
            }

            self::update_db_version( $target_version );
            self::$schema_update_current = true;
        } finally {
            self::$schema_cache_invalidation_deferred = false;
            if ( self::$schema_cache_invalidation_needed ) {
                self::invalidate_schema_caches();
                self::$schema_cache_invalidation_needed = false;
            }
            self::release_db_update_lock( $lock_token );
        }
    }

    /**
     * Persist the non-autoloaded schema version.
     *
     * @param string $version Schema version.
     * @return void
     */
    private static function update_db_version( $version ) {
        if ( false === get_option( self::DB_VERSION_OPTION, false ) ) {
            add_option( self::DB_VERSION_OPTION, (string) $version, '', 'no' );
            return;
        }

        update_option( self::DB_VERSION_OPTION, (string) $version, false );
    }

    /**
     * Verify that required plugin-owned tables and critical columns exist before
     * marking the main schema version as current.
     *
     * @return bool True when the schema is ready.
     */
    private static function verify_required_schema() {
        global $wpdb;

        $required_tables = array(
            $wpdb->prefix . 'srkit_redirection_table',
            $wpdb->prefix . 'srkit_keytrack_settings',
            $wpdb->prefix . 'srkit_gsc_data',
            $wpdb->prefix . 'srkit_redirection_logs',
            $wpdb->prefix . 'srkit_404_logs',
            $wpdb->prefix . 'srkit_smart_redirects',
            $wpdb->prefix . 'srkit_plugin_settings',
            $wpdb->prefix . 'srk_link_scan_runs',
            $wpdb->prefix . 'srk_link_scan_alerts',
            $wpdb->prefix . 'srk_spam_monitor_rules',
            $wpdb->prefix . 'srk_spam_monitor_alerts',
            $wpdb->prefix . 'srk_spam_monitor_serp_scans',
            $wpdb->prefix . 'srk_spam_monitor_serp_results',
        );

        foreach ( $required_tables as $table_name ) {
            if ( ! self::table_exists( $table_name ) ) {
                return false;
            }
        }

        $required_columns = array(
            $wpdb->prefix . 'srkit_redirection_table' => array(
                'id',
                'source_url',
                'target_url',
                'redirect_type',
                'status',
                'is_regex',
                'position',
                'hits',
            ),
            $wpdb->prefix . 'srkit_smart_redirects' => array(
                'id',
                'redirection_id',
                'post_type',
                'source_url',
                'target_url',
                'status',
            ),
            $wpdb->prefix . 'srkit_plugin_settings' => array(
                'id',
                'setting_key',
                'setting_value',
                'site_info_consent',
            ),
            $wpdb->prefix . 'srk_link_scan_runs' => array(
                'id',
                'trigger_type',
                'link_scope',
                'scan_coverage',
                'status',
            ),
        );

        foreach ( $required_columns as $table_name => $columns ) {
            $existing_columns = self::get_table_columns( $table_name );

            foreach ( $columns as $column ) {
                if ( ! in_array( $column, $existing_columns, true ) ) {
                    return false;
                }
            }
        }

        return true;
    }

    /**
     * Invalidate migration-related caches once per migration.
     *
     * @return void
     */
    private static function maybe_invalidate_schema_caches() {
        if ( self::$schema_cache_invalidation_deferred ) {
            self::$schema_cache_invalidation_needed = true;
            return;
        }

        self::invalidate_schema_caches();
    }

    /**
     * Delete schema-related transients.
     *
     * @return void
     */
    private static function invalidate_schema_caches() {
        delete_transient( 'srk_required_tables_check' );
        delete_transient( 'srk_table_creation_check' );
        delete_transient( 'srk_link_scanner_storage_checked' );
        delete_transient( 'srk_404_table_exists' );
        delete_transient( 'srk_404_statistics' );
    }

    /**
     * Prevent concurrent admin/ajax requests from running schema updates together.
     *
     * @return bool
     */
    private static function acquire_db_update_lock() {
        $now        = time();
        $token      = self::generate_db_update_lock_token();
        $lock_value = $token . '|' . ( $now + self::DB_UPDATE_LOCK_TTL );

        if ( add_option( self::DB_UPDATE_LOCK_OPTION, $lock_value, '', 'no' ) ) {
            return $token;
        }

        $existing_value = (string) get_option( self::DB_UPDATE_LOCK_OPTION, '' );
        $existing_lock  = self::parse_db_update_lock( $existing_value );

        if ( ! empty( $existing_lock['expires'] ) && $existing_lock['expires'] > $now ) {
            return false;
        }

        if ( '' === $existing_value || ! self::delete_db_update_lock_value( $existing_value ) ) {
            return false;
        }

        return add_option( self::DB_UPDATE_LOCK_OPTION, $lock_value, '', 'no' ) ? $token : false;
    }

    /**
     * Release the schema update lock only when this request owns it.
     *
     * @param string $token Lock owner token.
     * @return void
     */
    private static function release_db_update_lock( $token ) {
        $existing_value = (string) get_option( self::DB_UPDATE_LOCK_OPTION, '' );
        $existing_lock  = self::parse_db_update_lock( $existing_value );

        if ( empty( $existing_lock['token'] ) || ! hash_equals( (string) $token, (string) $existing_lock['token'] ) ) {
            return;
        }

        self::delete_db_update_lock_value( $existing_value );
    }

    /**
     * Parse the schema update lock value.
     *
     * Supports the previous timestamp-only value so stale locks from older builds
     * can expire naturally and be replaced safely.
     *
     * @param string $value Stored lock value.
     * @return array{token:string,expires:int}
     */
    private static function parse_db_update_lock( $value ) {
        $value = (string) $value;

        if ( false === strpos( $value, '|' ) ) {
            return array(
                'token'   => '',
                'expires' => absint( $value ),
            );
        }

        $parts = explode( '|', $value, 2 );

        return array(
            'token'   => sanitize_text_field( $parts[0] ),
            'expires' => isset( $parts[1] ) ? absint( $parts[1] ) : 0,
        );
    }

    /**
     * Delete a lock row by exact stored value.
     *
     * @param string $value Stored lock value.
     * @return bool
     */
    private static function delete_db_update_lock_value( $value ) {
        global $wpdb;

        return (bool) $wpdb->query( // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- Atomic option-lock cleanup for plugin migration lifecycle.
            $wpdb->prepare(
                "DELETE FROM {$wpdb->options} WHERE option_name = %s AND option_value = %s",
                self::DB_UPDATE_LOCK_OPTION,
                (string) $value
            )
        );
    }

    /**
     * Generate a request-owned schema lock token.
     *
     * @return string
     */
    private static function generate_db_update_lock_token() {
        if ( function_exists( 'wp_generate_uuid4' ) ) {
            return wp_generate_uuid4();
        }

        return md5( uniqid( 'srkit_db_update_', true ) );
    }

    /**
     * Ensure all current custom database tables exist and are upgraded.
     *
     * Safe to call from activation, version updates, and admin-side repair checks
     * because each table creator is idempotent and preserves existing data.
     *
     * @since  2.1.9
     * @access public
     * @return void
     */
    public static function ensure_database_tables() {
        self::run_updates();
    }

    /**
     * Check whether the recorded SEO Repair Kit schema version is current.
     *
     * @return bool
     */
    public static function is_database_current() {
        return version_compare( (string) get_option( self::DB_VERSION_OPTION, '' ), self::DB_VERSION, '>=' );
    }

    /**
     * Create or upgrade SERP-only Spam Monitor database tables.
     *
     * Delegates to SRK_Spam_Monitor_DB::maybe_create_tables() which uses dbDelta()
     * and runs when the stored DB version is behind or an active table is missing.
     * Safe to call on every activation and version upgrade; never drops existing data.
     *
     * Tables managed:
     *   - srk_spam_monitor_rules
     *   - srk_spam_monitor_alerts
     *   - srk_spam_monitor_serp_scans
     *   - srk_spam_monitor_serp_results
     *
     * @since  2.1.8
     * @access private
     * @return void
     */
    private static function maybe_create_spam_monitor_tables() {
        $db_class = plugin_dir_path( dirname( __FILE__ ) ) . 'includes/spam-monitor-backend/class-srk-spam-monitor-db.php';

        if ( ! class_exists( 'SRK_Spam_Monitor_DB' ) && file_exists( $db_class ) ) {
            require_once $db_class;
        }

        if ( class_exists( 'SRK_Spam_Monitor_DB' ) ) {
            SRK_Spam_Monitor_DB::maybe_create_tables();
        }
    }

    /**
     * Create / upgrade Links Manager Auto Scan history tables.
     *
     * @since 2.1.7
     * @access private
     * @return void
     */
    private static function create_link_scanner_history_tables() {
        $automation_file = plugin_dir_path( dirname( __FILE__ ) ) . 'includes/class-seo-repair-kit-link-scanner-automation.php';

        if ( ! class_exists( 'SeoRepairKit_LinkScanner_Automation' ) && file_exists( $automation_file ) ) {
            require_once $automation_file;
        }

        if ( class_exists( 'SeoRepairKit_LinkScanner_Automation' ) ) {
            SeoRepairKit_LinkScanner_Automation::maybe_upgrade_history_tables();
        }

        self::maybe_invalidate_schema_caches();
    }

    /**
     * Checks if a table exists in the database.
     *
     * @since 2.0.0
     * @access private
     * @param  string $table_name The name of the table to check.
     * @return bool   True if table exists, false otherwise.
     */
    private static function table_exists( $table_name ) {
        global $wpdb;

        return $wpdb->get_var( $wpdb->prepare( "SHOW TABLES LIKE %s", $table_name ) ) === $table_name; // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,PluginCheck.Security.DirectDB.UnescapedDBParameter -- Migration/recovery table existence check for plugin-owned custom table.
    }

    /**
     * Get column names for a trusted custom table.
     *
     * @param string $table_name Trusted custom table name.
     * @return string[]
     */
    private static function get_table_columns( $table_name ) {
        global $wpdb;

        $table_name = str_replace( '`', '', $table_name );
        $rows       = $wpdb->get_results( "SHOW COLUMNS FROM `{$table_name}`", ARRAY_A ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,WordPress.DB.PreparedSQL.InterpolatedNotPrepared,PluginCheck.Security.DirectDB.UnescapedDBParameter -- Migration verification query for plugin-owned table name generated from the trusted WP prefix.
        $columns    = array();

        foreach ( (array) $rows as $row ) {
            if ( ! empty( $row['Field'] ) ) {
                $columns[] = (string) $row['Field'];
            }
        }

        return $columns;
    }

    /**
     * Check whether an index exists on a custom table.
     *
     * @param string $table_name Trusted custom table name.
     * @param string $index_name Index name.
     * @return bool
     */
    private static function index_exists( $table_name, $index_name ) {
        global $wpdb;

        $table_name = str_replace( '`', '', $table_name );
        $index_name = sanitize_key( $index_name );
        $index      = $wpdb->get_var( // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,PluginCheck.Security.DirectDB.UnescapedDBParameter -- Migration/recovery index existence check for plugin-owned custom table.
            $wpdb->prepare(
                "SHOW INDEX FROM `{$table_name}` WHERE Key_name = %s",
                $index_name
            )
        ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared,PluginCheck.Security.DirectDB.UnescapedDBParameter -- Table name is generated from trusted WP prefix.

        return ! empty( $index );
    }

    /**
     * Ensure active/inactive status columns use a dbDelta-friendly type.
     *
     * This never drops, rebuilds, truncates, or rewrites redirection URL records.
     * Existing status values are preserved, then invalid/empty values are normalized.
     *
     * @param string $table_name Trusted custom table name.
     * @return void
     */
    private static function ensure_active_inactive_status_column( $table_name ) {
        global $wpdb;

        if ( ! self::table_exists( $table_name ) ) {
            return;
        }

        $table_name = str_replace( '`', '', $table_name );
        $column     = $wpdb->get_row( "SHOW COLUMNS FROM `{$table_name}` LIKE 'status'" ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,WordPress.DB.PreparedSQL.InterpolatedNotPrepared,PluginCheck.Security.DirectDB.UnescapedDBParameter -- Migration-only schema check; table name is generated from trusted WP prefix.

        if ( empty( $column ) ) {
            $wpdb->query( "ALTER TABLE `{$table_name}` ADD COLUMN status VARCHAR(20) NOT NULL DEFAULT 'active'" ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,WordPress.DB.DirectDatabaseQuery.SchemaChange,WordPress.DB.PreparedSQL.InterpolatedNotPrepared,PluginCheck.Security.DirectDB.UnescapedDBParameter -- Additive schema repair only.
        } elseif (
            ( isset( $column->Type ) && 'varchar(20)' !== strtolower( $column->Type ) )
            || ( isset( $column->Null ) && 'NO' !== strtoupper( $column->Null ) )
            || ( isset( $column->Default ) && 'active' !== $column->Default )
        ) {
        $wpdb->query( "ALTER TABLE `{$table_name}` CHANGE COLUMN `status` status VARCHAR(20) NOT NULL DEFAULT 'active'" ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,WordPress.DB.DirectDatabaseQuery.SchemaChange,WordPress.DB.PreparedSQL.InterpolatedNotPrepared,PluginCheck.Security.DirectDB.UnescapedDBParameter -- Non-destructive type normalization for active/inactive values.
        }

        $has_invalid_status = $wpdb->get_var( "SELECT 1 FROM `{$table_name}` WHERE status IS NULL OR status = '' OR status NOT IN ('active', 'inactive') LIMIT 1" ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,WordPress.DB.PreparedSQL.InterpolatedNotPrepared,PluginCheck.Security.DirectDB.UnescapedDBParameter -- Migration-only data check; table name is generated from trusted WP prefix.
        if ( $has_invalid_status ) {
            $wpdb->query( "UPDATE `{$table_name}` SET status = 'active' WHERE status IS NULL OR status = '' OR status NOT IN ('active', 'inactive')" ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,WordPress.DB.PreparedSQL.InterpolatedNotPrepared,PluginCheck.Security.DirectDB.UnescapedDBParameter -- One-time migration normalization of invalid status values only.
        }

        if ( ! self::index_exists( $table_name, 'idx_status' ) ) {
            $wpdb->query( "ALTER TABLE `{$table_name}` ADD INDEX idx_status (status)" ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,WordPress.DB.DirectDatabaseQuery.SchemaChange,WordPress.DB.PreparedSQL.InterpolatedNotPrepared,PluginCheck.Security.DirectDB.UnescapedDBParameter -- Additive index repair only.
        }
    }

    /**
     * Create or update redirection table in the database.
     * Updated for v2.1.0 with enhanced schema.
     *
     * @since 1.0.1
     * @version 2.1.0
     * @access private
     *
     * @return void
     */
    private static function srkit_create_log_table() {

        global $wpdb;
        $charset_collate = $wpdb->get_charset_collate();
        
        // Output redirection table name.
        $srkit_tablename = $wpdb->prefix . 'srkit_redirection_table';

        // Define the enhanced table schema query for v2.1.0.
        $srkit_tablequery = "CREATE TABLE $srkit_tablename ( 
            id BIGINT NOT NULL AUTO_INCREMENT,
            source_url VARCHAR(512) NOT NULL DEFAULT '',
            target_url VARCHAR(512) NOT NULL DEFAULT '',
            redirect_type INT NOT NULL DEFAULT 301,
            status VARCHAR(20) NOT NULL DEFAULT 'active',
            is_regex TINYINT(1) NOT NULL DEFAULT 0,
            position INT NOT NULL DEFAULT 0,
            hits INT NOT NULL DEFAULT 0,
            last_hit DATETIME NULL,
            created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
            updated_at DATETIME DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            PRIMARY KEY (id),
            INDEX idx_source_url (source_url),
            INDEX idx_status (status),
            INDEX idx_hits (hits)
        ) $charset_collate;";

        // Handle DB upgrades in the proper WordPress way.
        require_once( ABSPATH . 'wp-admin/includes/upgrade.php' );

        // Update or create the table in the database.
        dbDelta( $srkit_tablequery );
        self::ensure_active_inactive_status_column( $srkit_tablename );
        
        self::maybe_invalidate_schema_caches();
    }

    /**
     * Create keytrack settings table in the database.
     *
     * @since 2.0.0
     * @access private
     * @return void
     */
    private static function srkit_create_keytrack_table() {
        global $wpdb;

        $keytrack_tablename = $wpdb->prefix . 'srkit_keytrack_settings';
        $charset_collate    = $wpdb->get_charset_collate();

        $keytrack_tablequery = "CREATE TABLE $keytrack_tablename ( 
            id BIGINT NOT NULL AUTO_INCREMENT,
            keytrack_name VARCHAR(255) NOT NULL,
            selected_keywords LONGTEXT NOT NULL,
            date_range VARCHAR(50) NOT NULL,
            setting_value LONGTEXT,
            created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
            updated_at DATETIME DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            next_run_at DATETIME NULL,
            PRIMARY KEY (id)
        ) $charset_collate;";

        require_once( ABSPATH . 'wp-admin/includes/upgrade.php' );
        dbDelta( $keytrack_tablequery );
        
        self::maybe_invalidate_schema_caches();
    }

    /**
     * Create a table to save both box summary data and individual keyword records.
     *
     * @since 2.0.0
     * @access private
     * @return void
     */
    private static function create_gsc_data_table() {
        global $wpdb;

        $tablename       = $wpdb->prefix . 'srkit_gsc_data';
        $charset_collate = $wpdb->get_charset_collate();

        $table_query = "CREATE TABLE $tablename ( 
            id BIGINT NOT NULL AUTO_INCREMENT,
            gsc_data LONGTEXT NOT NULL,
            keytrack_name VARCHAR(255) NOT NULL,
            created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY (id)
        ) $charset_collate;";

        require_once( ABSPATH . 'wp-admin/includes/upgrade.php' );
        dbDelta( $table_query );
        
        self::maybe_invalidate_schema_caches();
    }

    /**
     * Function to send activation notification email.
     *
     * @since 1.0.1
     * @access private
     *
     * @return bool Whether the email was sent successfully.
     */
    private static function srkit_activation_activity() {
        
        $srkit_mailto      = 'support@torontodigits.com';
        $srkit_mailsubject = __( 'SEO Repair Kit Installation Notification', 'seo-repair-kit' );
        $srkit_mailheaders = array(
            'Content-Type: text/html; charset=UTF-8',
            'Reply-To: SEO Repair Kit <ab@seorepairkit.com>',
        );
        $srkit_mailmessage = '<html><body>';
        $srkit_mailmessage .= '<p>' . sprintf( __( 'Hello TorontoDigits, a new website has activated your plugin. Please find the details below:', 'seo-repair-kit' ) ) . '</p>';
        $srkit_mailmessage .= '<p>' . sprintf( __( 'Website Title:', 'seo-repair-kit' ) ) . ' ' . esc_html( get_bloginfo( 'name' ) ) . '</p>';
        $srkit_mailmessage .= '<p>' . sprintf( __( 'Website URL:', 'seo-repair-kit' ) ) . ' ' . esc_url( get_bloginfo( 'url' ) ) . '</p>';
        $srkit_mailmessage .= '<p>' . sprintf( __( 'Admin Email:', 'seo-repair-kit' ) ) . ' ' . sanitize_email( get_bloginfo( 'admin_email' ) ) . '</p>';
        $srkit_mailmessage .= '</body></html>';
        
        // Send the email and return whether it was successful.
        return wp_mail( $srkit_mailto, $srkit_mailsubject, $srkit_mailmessage, $srkit_mailheaders );
    }
    
    /**
     * Function to send basic plugin data to the API on activation.
     * Always sends basic data (name, email, version, status) regardless of consent.
     * Site health info is NOT included here - it's only sent when user grants consent.
     *
     * @since 1.1.0
     * @access private
     * @return void
     */
    private static function srk_send_data_to_api() {

        $api_url            = SRK_API_Client::get_api_url( SRK_API_Client::ENDPOINT_PLUGIN_DATA );
        $site_title         = sanitize_text_field( get_bloginfo( 'name' ) );
        $post_count         = wp_count_posts();
        $admin_email        = sanitize_email( get_option( 'admin_email' ) );
        $site_url           = esc_url_raw( get_bloginfo( 'url' )  );
        $plugin_instance_id = get_option('srk_plugin_instance_id');

        // Generate new UUID if not stored yet
        if ( ! $plugin_instance_id ) {
            $plugin_instance_id = wp_generate_uuid4();
            update_option( 'srk_plugin_instance_id', $plugin_instance_id );
        }

        // On activation, always send basic data WITHOUT site health info
        // NEVER call get_site_health_info() on activation - only when user explicitly checks checkbox
        // CRM requires site_information to be a string, so send empty JSON object
        $data = array(
            'websitename'        => $site_title,
            'admin_email'        => $admin_email,
            'pluginversion'      => SEO_REPAIR_KIT_VERSION,
            'noofposts'          => absint( $post_count->publish ),
            'site_url'           => $site_url,
            'site_information'   => '{}', // Always empty on activation - never collect site health info here
            'plugin_instance_id' => $plugin_instance_id,
        );

        $response = wp_remote_post( esc_url_raw( $api_url ), array(
            'body'    => wp_json_encode( $data ),
            'headers' => array( 'Content-Type' => 'application/json' ),
            'timeout' => 30,
        ) );

        if ( is_wp_error( $response ) ) {
            return;
        }

        $body               = wp_remote_retrieve_body( $response );
        $decoded_response   = json_decode( $body );

        if ( isset( $decoded_response->plugin_id ) ) {
            update_option( 'srk_plugin_id', $decoded_response->plugin_id );
            // Now send status to CRM after we have plugin_id
            self::send_status_to_crm( 'activated' );
        }
    }
    /**
     * Send site health info to API when user grants consent.
     * This is called when user checks the consent checkbox.
     * Only sends site health info - basic plugin data is already sent on activation.
     *
     * @since 2.1.0
     * @access public
     * @return void
     */
    public static function srk_send_site_health_info() {
        // Prevent duplicate sends within 5 seconds (debounce)
        $lock_key = 'srk_sending_site_health_info';
        if ( get_transient( $lock_key ) ) {
            return;
        }
        
        // Set lock for 5 seconds
        set_transient( $lock_key, true, 5 );
        
        // Check consent again to ensure it's still granted
        $consent = self::get_site_info_consent();
        
        if ( ! $consent ) {
            delete_transient( $lock_key );
            return;
        }

        $plugin_id = get_option( 'srk_plugin_id' );
        
        if ( ! $plugin_id ) {
            // If plugin_id doesn't exist, send basic data first to get plugin_id
            self::srk_send_data_to_api();
            $plugin_id = get_option( 'srk_plugin_id' );
            if ( ! $plugin_id ) {
                delete_transient( $lock_key );
                return;
            }
        }

        $api_url = SRK_API_Client::get_api_url( SRK_API_Client::ENDPOINT_PLUGIN_DATA );
        $site_info = self::get_site_health_info();
        
        $plugin_instance_id = get_option( 'srk_plugin_instance_id' );

        if ( ! $plugin_instance_id ) {
            $plugin_instance_id = wp_generate_uuid4();
            update_option( 'srk_plugin_instance_id', $plugin_instance_id );
        }

        // Get basic plugin data to include in the request (CRM requires all fields)
        $site_title = sanitize_text_field( get_bloginfo( 'name' ) );
        $post_count = wp_count_posts();
        $admin_email = sanitize_email( get_option( 'admin_email' ) );
        $site_url = esc_url_raw( get_bloginfo( 'url' ) );
        
        // Send complete data with site health info (CRM validation requires all fields)
        // Note: Do NOT include plugin_id - CRM finds records by plugin_instance_id or site_url
        $data = array(
            'plugin_instance_id' => $plugin_instance_id,
            'websitename'        => $site_title,
            'admin_email'        => $admin_email,
            'pluginversion'      => SEO_REPAIR_KIT_VERSION,
            'noofposts'          => absint( $post_count->publish ),
            'site_url'           => $site_url,
            'site_information'   => wp_json_encode( $site_info ),
        );
        
        $response = wp_remote_post( esc_url_raw( $api_url ), array(
            'body'    => wp_json_encode( $data ),
            'headers' => array( 'Content-Type' => 'application/json' ),
            'timeout' => 30,
        ) );

        // Handle errors if request fails
        if ( is_wp_error( $response ) ) {
            delete_transient( $lock_key );
            return;
        }

        $response_code = wp_remote_retrieve_response_code( $response );
        
        if ( $response_code !== 200 ) {
            delete_transient( $lock_key );
            return;
        }
        
        // Clear the lock after sending
        delete_transient( $lock_key );
    }
    /**
     * Send plugin status to CRM
     *
     * @since  2.0.0
     * @access private
     */
    private static function send_status_to_crm($status) {

        // CRM ONLY accepts these statuses
        $allowed = ['activated', 'deactivated', 'deleted'];

        // block update/downgrade from going to /plugin-status
        if (!in_array($status, $allowed, true)) {
            return;
        }

        $api_url   = SRK_API_Client::get_api_url(SRK_API_Client::ENDPOINT_PLUGIN_STATUS);
        $plugin_id = get_option('srk_plugin_id');

        if (!$plugin_id) {
            return;
        }

        $data = [
            'plugin_id' => $plugin_id,
            'status'    => $status
        ];

        $response = wp_remote_post($api_url, [
            'body'    => wp_json_encode($data),
            'headers' => ['Content-Type' => 'application/json']
        ]);
    }

    /**
    * Function to fetch site health info.
    *
    * @since 1.1.0
    * @access private
    * @return array
    */
    private static function get_site_health_info() {
        
        global $wpdb;

        $info = array();

        // WordPress Info
        $info['WordPress'] = array(
            'Version' => sanitize_text_field( get_bloginfo( 'version' ) ),
            'Site URL' => esc_url( get_bloginfo( 'url' ) ),
            'Home URL' => esc_url( get_bloginfo( 'wpurl' ) ),
            'Is this a multisite?' => is_multisite() ? 'Yes' : 'No',
            'Site Language' => sanitize_text_field( get_bloginfo( 'language' ) ),
            'User Language' => sanitize_text_field( get_user_locale() ),
            'Timezone' => sanitize_text_field( get_option( 'timezone_string' ) ?: 'Not Set' ),
            'Permalink Structure' => sanitize_text_field( get_option( 'permalink_structure' ) ),
            'Is HTTPS' => is_ssl() ? 'Yes' : 'No',
            'Discourage Search Engines' => get_option( 'blog_public' ) ? 'No' : 'Yes',
            'Default Comment Status' => sanitize_text_field( get_option( 'default_comment_status' ) ),
            'Environment Type' => sanitize_text_field( wp_get_environment_type() ),
            'User Count' => absint( count_users()['total_users'] ),
            'Communication with WordPress.org' => wp_http_supports( array( 'ssl' ) ) ? 'Yes' : 'No',
        );

        // Active Theme
        $active_theme = wp_get_theme();
        $info['Active Theme'] = array(
            'Name' => sanitize_text_field( $active_theme->get( 'Name' ) ),
            'Version' => sanitize_text_field( $active_theme->get( 'Version' ) ),
            'Author' => sanitize_text_field( $active_theme->get( 'Author' ) ),
            'Author URI' => esc_url( $active_theme->get( 'AuthorURI' ) ),
            'Auto Update' => $active_theme->get( 'Auto-Update' ) ? 'Enabled' : 'Disabled',
        );

        // Parent Theme (if applicable)
        if ( $active_theme->parent() ) {
            $parent_theme = $active_theme->parent();
            $info['Parent Theme'] = array(
                'Name' => sanitize_text_field( $parent_theme->get( 'Name' ) ),
                'Version' => sanitize_text_field( $parent_theme->get( 'Version' ) ),
                'Author' => sanitize_text_field( $parent_theme->get( 'Author' ) ),
                'Author URI' => esc_url( $parent_theme->get( 'AuthorURI' ) ),
                'Theme URI' => esc_url( $parent_theme->get( 'ThemeURI' ) ),
                'Auto Update' => $parent_theme->get( 'Auto-Update' ) ? 'Enabled' : 'Disabled',
            );
        }
        // Active Plugins
        // Load get_plugin_data() if not already available
        if ( ! function_exists( 'get_plugin_data' ) ) {
            require_once ABSPATH . 'wp-admin/includes/plugin.php';
        }

        $active_plugins = array_map( 'sanitize_text_field', get_option( 'active_plugins' ) );
        $info['Active Plugins'] = array();

        foreach ( $active_plugins as $plugin_path ) {

            // Full path to plugin file
            $full_path = WP_PLUGIN_DIR . '/' . $plugin_path;

            // Safeguard: plugin file might not exist (prevents warnings)
            if ( ! file_exists( $full_path ) ) {
                continue;
            }

            // Now safely get plugin data
            $plugin_data = get_plugin_data( $full_path );

            $info['Active Plugins'][] = array(
                'Name'    => sanitize_text_field( $plugin_data['Name'] ?? '' ),
                'Version' => sanitize_text_field( $plugin_data['Version'] ?? '' ),
                'Author'  => sanitize_text_field( $plugin_data['Author'] ?? '' ),
            );
        }

        // Inactive Plugins
        $all_plugins        = get_plugins();
        $inactive_plugins   = array_diff_key( $all_plugins, array_flip( $active_plugins ) );
        $info['Inactive Plugins'] = array();
        foreach ( $inactive_plugins as $plugin_path => $plugin_data ) {
            $info['Inactive Plugins'][] = array(
                'Name' => sanitize_text_field( $plugin_data['Name'] ),
                'Version' => sanitize_text_field( $plugin_data['Version'] ),
            );
        }

        // Must Use Plugins
        $must_use_plugins = function_exists( 'get_mu_plugins' ) ? get_mu_plugins() : array();
        $info['Must Use Plugins'] = array();
        foreach ( $must_use_plugins as $plugin_path => $plugin_data ) {
            $info['Must Use Plugins'][] = array(
                'Name' => sanitize_text_field( $plugin_data['Name'] ),
                'Version' => sanitize_text_field( $plugin_data['Version'] ),
            );
        }

        // Media Info
        $info['Media Info'] = array(
            'Active Editor' => wp_image_editor_supports( ['methods' => ['resize']] ),
            'Imagick Version' => extension_loaded( 'imagick' ) ? phpversion( 'imagick' ) : 'Not available',
            'File Uploads' => ini_get( 'file_uploads' ) ? 'Enabled' : 'Disabled',
            'Max Size of Post Data Allowed' => ini_get( 'post_max_size' ),
            'Max Size of an Uploaded File' => ini_get( 'upload_max_filesize' ),
            'Max Effective File Size' => min( ini_get( 'post_max_size' ), ini_get( 'upload_max_filesize' ) ),
            'Max Number of Files Allowed' => ini_get( 'max_file_uploads' ),
            'GD Version' => function_exists( 'gd_info' ) ? gd_info()['GD Version'] : 'Not available',
        );

        // Server Info
        $info['Server Info'] = array(
            'Server Architecture' => php_uname( 'm' ),
            'Web Server' => sanitize_text_field( $_SERVER['SERVER_SOFTWARE'] ),
            'PHP Version' => sanitize_text_field( phpversion() ),
            'PHP SAPI' => sanitize_text_field( php_sapi_name() ),
            'PHP Max Input Variables' => absint( ini_get('max_input_vars') ),
            'PHP Time Limit' => absint( ini_get( 'max_execution_time' ) ),
            'PHP Memory Limit' => sanitize_text_field( ini_get( 'memory_limit' ) ),
            'Max Input Time' => absint( ini_get( 'max_input_time' ) ),
            'Upload Max Filesize' => sanitize_text_field( ini_get( 'upload_max_filesize' ) ),
            'PHP Post Max Size' => sanitize_text_field( ini_get( 'post_max_size' ) ),
            'cURL Version' => function_exists( 'curl_version' ) ? sanitize_text_field( curl_version()['version'] ) : 'Not available',
            'Is SUHOSIN Installed' => extension_loaded( 'suhosin' ) ? 'Yes' : 'No',
            'Is the Imagick Library Available' => extension_loaded( 'imagick' ) ? 'Yes' : 'No',
            'Are Pretty Permalinks Supported' => get_option( 'permalink_structure' ) ? 'Yes' : 'No',
            'Current Time' => sanitize_text_field( date( 'Y-m-d H:i:s' ) ),
            'Current UTC Time' => sanitize_text_field( gmdate( 'Y-m-d H:i:s' ) ),
            'Current Server Time' => sanitize_text_field( date( 'Y-m-d H:i:s', $_SERVER['REQUEST_TIME'] ) ),
        );
        
        // Database Info
        $info['Database Info'] = array(
            'Extension' => $wpdb->use_mysqli ? 'MySQLi' : 'MySQL',
            'Server Version' => sanitize_text_field( $wpdb->db_version() ),
            'Client Version' => sanitize_text_field( mysqli_get_client_info() ),
            'Database Username' => sanitize_text_field( DB_USER ),
            'Database Host' => sanitize_text_field( DB_HOST ),
            'Database Name' => sanitize_text_field( DB_NAME ),
            'Table Prefix' => sanitize_text_field( $wpdb->prefix ),
            'Database Charset' => sanitize_text_field( $wpdb->charset ),
            'Database Collation' => sanitize_text_field( $wpdb->collate ),
        );

        return $info;

    }

    /**
     * Comprehensive migration from v2.0.0 to v2.1.0
     * Handles redirection table migration and ensures all data is preserved
     *
     * @since 2.1.0
     * @access private
     * @return void
     */
    private static function migrate_to_v2_1_0() {
        global $wpdb;
        
        $migration_log = array();
        
        // 1. Migrate Redirection Table
        $redirection_result = self::migrate_redirection_table();
        if ( $redirection_result['migrated'] && $redirection_result['success'] ) {
            $migration_log[] = sprintf( 
                'Redirection table: %s (%d records migrated)', 
                $redirection_result['message'],
                $redirection_result['records_migrated']
            );
        } elseif ( $redirection_result['migrated'] && !$redirection_result['success'] ) {
            $migration_log[] = 'Redirection table migration failed: ' . $redirection_result['message'];
        }
        
        // 2. Migrate KeyTrack Table (if needed)
        $keytrack_migrated = self::migrate_keytrack_table();
        if ( $keytrack_migrated ) {
            $migration_log[] = 'KeyTrack table migrated successfully';
        }
        
        // 3. Set migration notices
        if ( $redirection_result['migrated'] || $keytrack_migrated ) {
            set_transient( 'srk_redirection_migration_notice', true, 300 ); // 5 minutes
            set_transient( 'srk_migration_log', $migration_log, 3600 ); // 1 hour
        }
        
        // Migration completed
    }

    /**
     * Migrate redirection table from v2.0.0 to v2.1.0 schema
     * Enhanced version that safely migrates all records without data loss
     *
     * @since 2.1.0
     * @access private
     * @return array Migration result with status and details
     */
    private static function migrate_redirection_table() {
        global $wpdb;
        
        $table_name = $wpdb->prefix . 'srkit_redirection_table';
        $migration_result = array(
            'success' => false,
            'migrated' => false,
            'records_migrated' => 0,
            'records_failed' => 0,
            'message' => ''
        );
        
        // Check if table exists
        if ( !self::table_exists( $table_name ) ) {
            $migration_result['message'] = 'Table does not exist - no migration needed';
            return $migration_result;
        }
        
        // Get table columns using a plugin-controlled escaped identifier.
        $table_name_sql = '`' . str_replace( '`', '``', $table_name ) . '`';
        $columns = $wpdb->get_results( "SHOW COLUMNS FROM {$table_name_sql}" ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,WordPress.DB.PreparedSQL.NotPrepared,PluginCheck.Security.DirectDB.UnescapedDBParameter -- Migration-only schema check for plugin-owned table.
        if ( empty( $columns ) ) {
            $migration_result['message'] = 'Could not read table structure';
            return $migration_result;
        }
        
        $column_names = array_column( $columns, 'Field' );
        
        // Check if we have old schema (old_url, new_url) but missing new fields
        $has_old_schema = in_array( 'old_url', $column_names ) && in_array( 'new_url', $column_names );
        $has_new_schema = in_array( 'source_url', $column_names ) && in_array( 'target_url', $column_names );
        
        // If already has new schema, no migration needed
        if ( $has_new_schema && !$has_old_schema ) {
            $migration_result['message'] = 'Table already has new schema - no migration needed';
            return $migration_result;
        }
        
        // If has both old and new schema, update old records to new schema
        if ( $has_old_schema && $has_new_schema ) {
            // Migrate old records that haven't been migrated yet
            $old_records = $wpdb->get_results( // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,WordPress.DB.PreparedSQL.NotPrepared,PluginCheck.Security.DirectDB.UnescapedDBParameter -- One-time migration read from plugin-owned redirection table.
                "SELECT id, old_url, new_url FROM {$table_name_sql}
                     WHERE (source_url IS NULL OR source_url = '') 
                     AND old_url IS NOT NULL AND old_url != ''"
            );
            
            if ( !empty( $old_records ) ) {
                $migrated = 0;
                $failed = 0;
                
                foreach ( $old_records as $row ) {
                    $update_result = $wpdb->update( // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- One-time migration write to plugin-owned redirection table.
                        $table_name,
                        array(
                            'source_url' => $row->old_url,
                            'target_url' => $row->new_url,
                            'redirect_type' => 301,
                            'status' => 'active',
                            'is_regex' => 0,
                            'position' => 0,
                            'hits' => 0,
                            'updated_at' => current_time( 'mysql' )
                        ),
                        array( 'id' => $row->id ),
                        array( '%s', '%s', '%d', '%s', '%d', '%d', '%d', '%s' ),
                        array( '%d' )
                    );
                    
                    if ( $update_result !== false ) {
                        $migrated++;
                    } else {
                        $failed++;
                    }
                }
                
                $migration_result['success'] = true;
                $migration_result['migrated'] = true;
                $migration_result['records_migrated'] = $migrated;
                $migration_result['records_failed'] = $failed;
                $migration_result['message'] = sprintf( 'Migrated %d records, %d failed', $migrated, $failed );
                
                return $migration_result;
            }
        }
        
        // If only has old schema, add the new schema in place and preserve all old records.
        if ( $has_old_schema && !$has_new_schema ) {
            // dbDelta adds the new columns/indexes where possible and does not drop old columns.
            self::srkit_create_log_table();

            $columns = $wpdb->get_results( "SHOW COLUMNS FROM {$table_name_sql}" ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,WordPress.DB.PreparedSQL.NotPrepared,PluginCheck.Security.DirectDB.UnescapedDBParameter -- Migration-only schema check for plugin-owned table.
            $column_names = is_array( $columns ) ? array_column( $columns, 'Field' ) : array();

            if ( ! in_array( 'source_url', $column_names, true ) || ! in_array( 'target_url', $column_names, true ) ) {
                $migration_result['message'] = 'Could not add new redirection columns without altering existing data.';
                return $migration_result;
            }

            $existing_data = $wpdb->get_results( "SELECT id, old_url, new_url FROM {$table_name_sql} ORDER BY id" ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,WordPress.DB.PreparedSQL.NotPrepared,PluginCheck.Security.DirectDB.UnescapedDBParameter -- One-time migration read from plugin-owned redirection table.
            $migrated = 0;
            $failed = 0;

            foreach ( $existing_data as $row ) {
                if ( empty( $row->old_url ) || empty( $row->new_url ) ) {
                    $failed++;
                    continue;
                }

                $update_result = $wpdb->update( // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- One-time migration write to plugin-owned redirection table.
                    $table_name,
                    array(
                        'source_url' => $row->old_url,
                        'target_url' => $row->new_url,
                        'redirect_type' => 301,
                        'status' => 'active',
                        'is_regex' => 0,
                        'position' => 0,
                        'hits' => 0,
                        'updated_at' => current_time( 'mysql' )
                    ),
                    array( 'id' => $row->id ),
                    array( '%s', '%s', '%d', '%s', '%d', '%d', '%d', '%s' ),
                    array( '%d' )
                );

                if ( $update_result !== false ) {
                    $migrated++;
                } else {
                    $failed++;
                }
            }

            $migration_result['success'] = true;
            $migration_result['migrated'] = true;
            $migration_result['records_migrated'] = $migrated;
            $migration_result['records_failed'] = $failed;
            $migration_result['message'] = sprintf( 'Migrated %d records in place, %d skipped or failed. Existing old columns/data were preserved.', $migrated, $failed );

            return $migration_result;
        }
        
        $migration_result['message'] = 'No migration needed - table structure is unknown';
        return $migration_result;
    }

    /**
     * Migrate KeyTrack table if needed
     *
     * @since 2.1.0
     * @access private
     * @return bool True if migration occurred, false if not needed
     */
    private static function migrate_keytrack_table() {
        global $wpdb;
        
        $table_name = $wpdb->prefix . 'srkit_keytrack_settings';
        
        // Check if table exists
        if ( self::table_exists( $table_name ) ) {
            // Table already exists, no migration needed
            return false;
        }
        
        // Create the table (this will be done by srkit_create_keytrack_table anyway)
        return false; // No actual migration needed, just creation
    }

    /**
     * Public method to manually trigger redirection table migration
     * Can be called from admin interface or AJAX handler
     *
     * @since 2.1.0
     * @access public
     * @return array Migration result with status and details
     */
    public static function manual_migrate_redirection_table() {
        return self::migrate_redirection_table();
    }

    /**
     * Create redirection logs table for v2.1.0
     *
     * @since 2.1.0
     * @access private
     * @return void
     */
    private static function create_redirection_logs_table() {
        global $wpdb;
        
        $logs_table = $wpdb->prefix . 'srkit_redirection_logs';
        $charset_collate = $wpdb->get_charset_collate();
        
        $logs_table_query = "CREATE TABLE $logs_table ( 
            id BIGINT NOT NULL AUTO_INCREMENT,
            redirection_id BIGINT NULL,
            action VARCHAR(50) NOT NULL DEFAULT 'redirect',
            url TEXT NOT NULL,
            user_agent TEXT,
            ip_address VARCHAR(45),
            referrer TEXT,
            created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY (id),
            INDEX idx_redirection_id (redirection_id),
            INDEX idx_action (action),
            INDEX idx_created_at (created_at)
        ) $charset_collate;";
        
        require_once( ABSPATH . 'wp-admin/includes/upgrade.php' );
        dbDelta( $logs_table_query );
        
        self::maybe_invalidate_schema_caches();
    }

    /**
     * Create 404 error logs table
     *
     * @since 2.1.0
     * @access private
     * @return void
     */
    private static function create_404_logs_table() {
        global $wpdb;
        
        $table_name = $wpdb->prefix . 'srkit_404_logs';
        $charset_collate = $wpdb->get_charset_collate();
        
        $table_query = "CREATE TABLE $table_name ( 
            id BIGINT NOT NULL AUTO_INCREMENT,
            url TEXT NOT NULL,
            referrer TEXT,
            user_agent TEXT,
            ip_address VARCHAR(45),
            method VARCHAR(10) NOT NULL DEFAULT 'GET',
            domain VARCHAR(255),
            count INT NOT NULL DEFAULT 1,
            last_accessed DATETIME DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            first_accessed DATETIME DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY (id),
            INDEX idx_url (url(255)),
            INDEX idx_ip_address (ip_address),
            INDEX idx_last_accessed (last_accessed),
            INDEX idx_count (count),
            INDEX idx_domain (domain)
        ) $charset_collate;";
        
        require_once( ABSPATH . 'wp-admin/includes/upgrade.php' );
        dbDelta( $table_query );
        
        self::maybe_invalidate_schema_caches();
    }

    /**
     * Create smart redirects tracking table.
     *
     * @since 2.1.7
     * @access private
     * @return void
     */
    private static function create_smart_redirects_table() {
        global $wpdb;

        $table_name = $wpdb->prefix . 'srkit_smart_redirects';
        $charset_collate = $wpdb->get_charset_collate();

        $table_query = "CREATE TABLE $table_name (
            id BIGINT NOT NULL AUTO_INCREMENT,
            redirection_id BIGINT NULL,
            post_type VARCHAR(100) NOT NULL,
            source_url VARCHAR(512) NOT NULL,
            target_url VARCHAR(512) NOT NULL,
            status VARCHAR(20) NOT NULL DEFAULT 'active',
            last_detected_at DATETIME DEFAULT CURRENT_TIMESTAMP,
            created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
            updated_at DATETIME DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            PRIMARY KEY (id),
            UNIQUE KEY uniq_source_post_type (source_url, post_type),
            KEY idx_redirection_id (redirection_id),
            KEY idx_post_type (post_type),
            KEY idx_status (status)
        ) $charset_collate;";

        require_once( ABSPATH . 'wp-admin/includes/upgrade.php' );
        dbDelta( $table_query );
        self::ensure_active_inactive_status_column( $table_name );

        self::maybe_invalidate_schema_caches();
    }

    /**
     * Create plugin settings table with consent tracking column.
     *
     * @since 2.1.0
     * @access private
     * @return void
     */
    private static function create_plugin_settings_table() {
        global $wpdb;
        
        $table_name = $wpdb->prefix . 'srkit_plugin_settings';
        $charset_collate = $wpdb->get_charset_collate();
        
        $table_query = "CREATE TABLE $table_name ( 
            id BIGINT NOT NULL AUTO_INCREMENT,
            setting_key VARCHAR(255) NOT NULL UNIQUE,
            setting_value LONGTEXT,
            site_info_consent TINYINT(1) NOT NULL DEFAULT 0,
            created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
            updated_at DATETIME DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            PRIMARY KEY (id),
            INDEX idx_setting_key (setting_key),
            INDEX idx_consent (site_info_consent)
        ) $charset_collate;";
        
        require_once( ABSPATH . 'wp-admin/includes/upgrade.php' );
        dbDelta( $table_query );
        
        self::maybe_invalidate_schema_caches();
    }

    /**
     * Get site info consent from database table.
     * Falls back to WordPress option for backward compatibility.
     *
     * @since 2.1.0
     * @access public
     * @return bool True if consent is granted, false otherwise
     */
    public static function get_site_info_consent() {
        global $wpdb;
        
        $table_name = $wpdb->prefix . 'srkit_plugin_settings';

        if ( ! self::is_database_current() ) {
            $consent = get_option( 'srk_site_info_consent' );
            return $consent === 1;
        }
        
        // Get consent from database
        $result = $wpdb->get_var( $wpdb->prepare( // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,PluginCheck.Security.DirectDB.UnescapedDBParameter,WordPress.DB.PreparedSQL.NotPrepared -- Activation-time plugin settings lookup from plugin-owned settings table.
            "SELECT site_info_consent FROM $table_name WHERE setting_key = %s",
            'main_settings'
        ) );

        if ( $result !== null ) {
            return (bool) $result;
        }
        
        // Fall back to WordPress option
        $consent = get_option( 'srk_site_info_consent' );
        return $consent === 1;
    }

    /**
     * Update site info consent in database table.
     * Also updates WordPress option for backward compatibility.
     *
     * @since 2.1.0
     * @access public
     * @param bool $consent True to grant consent, false to deny
     * @return void
     */
    public static function update_site_info_consent( $consent ) {
        global $wpdb;
        
        $table_name = $wpdb->prefix . 'srkit_plugin_settings';
        $consent_value = $consent ? 1 : 0;
        
        // Update WordPress option for backward compatibility
        update_option( 'srk_site_info_consent', $consent_value );
        
        if ( ! self::is_database_current() ) {
            self::create_plugin_settings_table();
        }
        
        // Insert or update consent in database
        $wpdb->replace( // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- Activation-time plugin settings upsert.
            $table_name,
            array(
                'setting_key' => 'main_settings',
                'site_info_consent' => $consent_value,
            ),
            array( '%s', '%d' )
        );
    }
}

if ( ! get_option( 'seo_repair_kit_version' ) ) {
    add_option( 'seo_repair_kit_version', SEO_REPAIR_KIT_VERSION );
}
