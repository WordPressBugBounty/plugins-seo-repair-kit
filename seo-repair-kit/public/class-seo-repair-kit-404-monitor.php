<?php
if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/**
 * SEO Repair Kit 404 Error Monitor
 *
 * Monitors and logs 404 errors automatically on the frontend.
 * Integrated with redirection feature to allow converting 404s to redirects.
 *
 * @link       https://seorepairkit.com
 * @since      2.1.0
 * @author     TorontoDigits <support@torontodigits.com>
 */
class SeoRepairKit_404_Monitor {

    const SUMMARY_COUNTS_OPTION = 'srk_404_summary_counts';

    private $db_404;
    private $is_monitoring_enabled;
    
    /**
     * Cache for 404 statistics to prevent duplicate queries within same request
     * @var array|null
     */
    private static $cached_404_statistics = null;

    /**
     * Request-level cache for the 404 logs table availability check.
     *
     * @var bool|null
     */
    private static $cached_table_exists = null;

    /**
     * Quote a local SQL identifier.
     *
     * WordPress 5.0 compatibility prevents using the newer %i placeholder.
     *
     * @param string $identifier Identifier.
     * @return string
     */
    private static function quote_identifier( $identifier ) {
        return '`' . str_replace( '`', '``', $identifier ) . '`';
    }

    /**
     * Get the plugin-owned 404 logs table name.
     *
     * @return string
     */
    private static function get_404_logs_table_name() {
        global $wpdb;

        return $wpdb->prefix . 'srkit_404_logs';
    }

    /**
     * Get the escaped plugin-owned 404 logs table identifier.
     *
     * @return string
     */
    private static function get_404_logs_table_identifier() {
        return self::quote_identifier( self::get_404_logs_table_name() );
    }

    /**
     * Constructor
     */
    public function __construct() {
        global $wpdb;
        $this->db_404 = $wpdb;
        $this->is_monitoring_enabled = (bool) get_option( 'srk_404_monitoring_enabled', true );

        // Only hook if monitoring is enabled
        if ( $this->is_monitoring_enabled ) {
            // Hook after template_redirect to catch 404s (after redirects are processed)
            add_action( 'template_redirect', array( $this, 'log_404_error' ), 999 );
        }
    }

    /**
     * Log 404 errors when they occur
     *
     * @since 2.1.0
     */
    public function log_404_error() {
        // Don't log in admin area
        if ( is_admin() ) {
            return;
        }

        // Only log if it's actually a 404
        if ( ! is_404() ) {
            return;
        }

        // Check monitoring status dynamically (in case it was changed)
        $monitoring_enabled = (bool) get_option( 'srk_404_monitoring_enabled', true );
        if ( ! $monitoring_enabled ) {
            return;
        }

        // Ensure database table exists before logging
        if ( ! $this->ensure_table_exists() ) {
            return;
        }

        // Get request details
        $url = $this->get_request_url();
        $user_agent = isset( $_SERVER['HTTP_USER_AGENT'] ) ? sanitize_text_field( $_SERVER['HTTP_USER_AGENT'] ) : '';
        $ip_address = $this->get_client_ip();
        $method = isset( $_SERVER['REQUEST_METHOD'] ) ? sanitize_text_field( $_SERVER['REQUEST_METHOD'] ) : 'GET';
        $domain = $this->get_request_domain();
        $referrer = isset( $_SERVER['HTTP_REFERER'] ) ? esc_url_raw( $_SERVER['HTTP_REFERER'] ) : '';

        // Check if we should log this URL (skip admin, login, etc.)
        if ( $this->should_skip_url( $url ) ) {
            return;
        }

        // Log the 404 error
        $this->insert_404_log( $url, $user_agent, $ip_address, $method, $domain, $referrer );
    }

    /**
     * Get current request URL
     *
     * @return string
     */
    private function get_request_url() {
        $request_uri = isset( $_SERVER['REQUEST_URI'] ) ? esc_url_raw( $_SERVER['REQUEST_URI'] ) : '';
        
        // Remove query string for logging
        $url_path = parse_url( $request_uri, PHP_URL_PATH );
        
        // Ensure we have a valid path
        if ( empty( $url_path ) ) {
            $url_path = '/';
        }

        return $url_path;
    }

    /**
     * Get client IP address
     *
     * @return string
     */
    private function get_client_ip() {
        $ip = '';
        
        // Check for various IP headers (handles proxies, load balancers, etc.)
        $ip_headers = array(
            'HTTP_CF_CONNECTING_IP',     // Cloudflare
            'HTTP_CLIENT_IP',
            'HTTP_X_FORWARDED_FOR',
            'HTTP_X_FORWARDED',
            'HTTP_X_CLUSTER_CLIENT_IP',
            'HTTP_FORWARDED_FOR',
            'HTTP_FORWARDED',
            'REMOTE_ADDR',
        );

        foreach ( $ip_headers as $header ) {
            if ( ! empty( $_SERVER[ $header ] ) ) {
                $ip_list = explode( ',', sanitize_text_field( $_SERVER[ $header ] ) );
                $ip = trim( $ip_list[0] );
                
                // Validate IP (allow private ranges for local development)
                if ( filter_var( $ip, FILTER_VALIDATE_IP ) ) {
                    break;
                }
            }
        }

        // Fallback to REMOTE_ADDR (even if it's a private IP for local development)
        if ( empty( $ip ) && ! empty( $_SERVER['REMOTE_ADDR'] ) ) {
            $ip = sanitize_text_field( $_SERVER['REMOTE_ADDR'] );
        }

        return $ip;
    }

    /**
     * Get request domain
     *
     * @return string
     */
    private function get_request_domain() {
        if ( isset( $_SERVER['HTTP_HOST'] ) ) {
            return sanitize_text_field( $_SERVER['HTTP_HOST'] );
        }
        
        $site_url = site_url();
        $parsed = parse_url( $site_url );
        
        return isset( $parsed['host'] ) ? $parsed['host'] : '';
    }

    /**
     * Check if URL should be skipped from logging
     *
     * @param string $url URL to check
     * @return bool True if should skip, false otherwise
     */
    private function should_skip_url( $url ) {
        // Skip admin URLs
        if ( strpos( $url, '/wp-admin' ) === 0 ) {
            return true;
        }

        // Skip login URLs
        if ( strpos( $url, '/wp-login.php' ) === 0 ) {
            return true;
        }

        // Skip AJAX URLs
        if ( strpos( $url, '/wp-json' ) === 0 || strpos( $url, '/admin-ajax.php' ) !== false ) {
            return true;
        }

        // Skip cron URLs
        if ( strpos( $url, '/wp-cron.php' ) === 0 ) {
            return true;
        }

        // Allow filtering
        $should_skip = apply_filters( 'srk_404_should_skip_url', false, $url );
        
        return $should_skip;
    }

    /**
     * Ensure database table exists
     *
     * @return bool True if table exists or was created, false otherwise
     */
    private function ensure_table_exists() {
        $table_name = self::get_404_logs_table_name();

        if ( self::is_404_logs_table_available() ) {
            return true;
        }
        
        // Table doesn't exist, try to create it
        $activator_path = plugin_dir_path( dirname( dirname( __FILE__ ) ) ) . 'includes/class-seo-repair-kit-activator.php';
        if ( file_exists( $activator_path ) ) {
            require_once $activator_path;
            if ( class_exists( 'SeoRepairKit_Activator' ) ) {
                $reflection = new ReflectionClass( 'SeoRepairKit_Activator' );
                if ( $reflection->hasMethod( 'create_404_logs_table' ) ) {
                    $method = $reflection->getMethod( 'create_404_logs_table' );
                    $method->setAccessible( true );
                    $method->invoke( null );
                    self::$cached_table_exists = null;
                    return self::is_404_logs_table_available( true );
                }
            }
        }
        
        return false;
    }

    /**
     * Insert or update 404 log entry
     *
     * @param string $url Requested URL
     * @param string $user_agent User agent
     * @param string $ip_address IP address
     * @param string $method HTTP method
     * @param string $domain Domain name
     * @param string $referrer HTTP referrer
     * @return int|false Log ID or false on failure
     */
    private function insert_404_log( $url, $user_agent, $ip_address, $method, $domain, $referrer = '' ) {
        // phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- Real-time 404 log read/write against plugin-owned table; writes update maintained counters and clear caches.
        $table_name = self::get_404_logs_table_name();
        
        if ( ! self::is_404_logs_table_available() ) {
            return false;
        }

        // Check if this URL already exists
        $existing_sql = $this->db_404->prepare(
            "SELECT id, count FROM `{$this->db_404->prefix}srkit_404_logs` WHERE url = %s LIMIT 1",
            $url
        );
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,WordPress.DB.PreparedSQL.NotPrepared -- Real-time duplicate check before updating or inserting one 404 log row; identifier is plugin-owned.
        $existing = $this->db_404->get_row( $existing_sql, OBJECT );

        if ( $existing ) {
            // Update existing entry - increment count and update last_accessed
            $update_data = array(
                'count' => (int) $existing->count + 1,
                'last_accessed' => current_time( 'mysql' ),
                'ip_address' => $ip_address,
                'user_agent' => $user_agent,
                'method' => $method,
            );
            
            $update_format = array( '%d', '%s', '%s', '%s', '%s' );
            
            // Update referrer if provided
            if ( ! empty( $referrer ) ) {
                $update_data['referrer'] = $referrer;
                $update_format[] = '%s';
            }
            
            $result = $this->db_404->update( // phpcs:ignore WordPress.DB.DirectDatabaseQuery -- Intentional write to plugin-owned 404 log table; write operations are not cached.
                $table_name,
                $update_data,
                array( 'id' => $existing->id ),
                $update_format,
                array( '%d' )
            );

            if ( false !== $result ) {
                self::adjust_404_summary_counts( 0, 1 );
                self::clear_404_statistics_cache();
                return $existing->id;
            }

            return false;
        } else {
            // Insert new entry
            $insert_data = array(
                'url' => $url,
                'user_agent' => $user_agent,
                'ip_address' => $ip_address,
                'method' => $method,
                'domain' => $domain,
                'referrer' => $referrer,
                'count' => 1,
                'first_accessed' => current_time( 'mysql' ),
                'last_accessed' => current_time( 'mysql' ),
            );
            $insert_format = array( '%s', '%s', '%s', '%s', '%s', '%s', '%d', '%s', '%s' );
            // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- Intentional write to plugin-owned 404 log table; write operations are not cached.
            $result = $this->db_404->insert( $table_name, $insert_data, $insert_format );

            if ( $result !== false ) {
                self::adjust_404_summary_counts( 1, 1 );
                self::clear_404_statistics_cache();
                return $this->db_404->insert_id;
            }
        }

        // phpcs:enable WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
        return false;
    }

    /**
     * Get 404 statistics
     *
     * @return array Statistics
     */
    public static function get_404_statistics() {
        // phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- Cached 404 statistics from plugin-owned table; request cache and transient cache are checked before SQL.
        global $wpdb;
        
        // Return instance-level cached statistics if already fetched in this request
        if ( null !== self::$cached_404_statistics ) {
            return self::$cached_404_statistics;
        }
        
        // Cache statistics to avoid duplicate queries
        $cache_key = 'srk_404_statistics';
        $cached_stats = get_transient( $cache_key );
        
        // Return cached stats if available (cache for 2 minutes)
        if ( false !== $cached_stats ) {
            // Store in instance cache as well
            self::$cached_404_statistics = $cached_stats;
            return $cached_stats;
        }
        
        $stats = array(
            'total_404s' => 0,
            'unique_urls' => 0,
            'total_hits' => 0,
            'most_hit' => null,
            'recent_404s' => array(),
        );

        if ( ! self::is_404_logs_table_available() ) {
            set_transient( $cache_key, $stats, MINUTE_IN_SECONDS );
            self::$cached_404_statistics = $stats;
            return $stats;
        }

        $summary = self::get_404_summary_counts();

        // One row is stored per URL and repeat hits increment the `count` column.
        $stats['total_404s'] = (int) $summary['rows'];
        $stats['unique_urls'] = (int) $summary['rows'];
        $stats['total_hits'] = (int) $summary['hits'];
        
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,WordPress.DB.PreparedSQL.NotPrepared -- Cached by srk_404_statistics transient and request cache; identifier is plugin-owned.
        $stats['most_hit'] = $wpdb->get_row( "SELECT url, count, last_accessed FROM `{$wpdb->prefix}srkit_404_logs` ORDER BY count DESC, last_accessed DESC LIMIT 1" );

        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,WordPress.DB.PreparedSQL.NotPrepared -- Cached by srk_404_statistics transient and request cache; identifier is plugin-owned.
        $stats['recent_404s'] = $wpdb->get_results( "SELECT url, count, last_accessed FROM `{$wpdb->prefix}srkit_404_logs` ORDER BY last_accessed DESC LIMIT 10" );

        // Store in instance-level cache for this request
        self::$cached_404_statistics = $stats;
        
        // Cache stats for 2 minutes (cross-request)
        set_transient( $cache_key, $stats, 2 * MINUTE_IN_SECONDS );

        // phpcs:enable WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
        return $stats;
    }

    /**
     * Get maintained 404 summary counters.
     *
     * @return array{rows:int,hits:int}
     */
    public static function get_404_summary_counts() {
        $summary = get_option( self::SUMMARY_COUNTS_OPTION, false );

        if ( is_array( $summary ) && isset( $summary['rows'], $summary['hits'] ) ) {
            return array(
                'rows' => max( 0, absint( $summary['rows'] ) ),
                'hits' => max( 0, absint( $summary['hits'] ) ),
            );
        }

        return self::rebuild_404_summary_counts();
    }

    /**
     * Rebuild 404 summary counters from the table.
     *
     * Used only when the persistent summary option is missing or needs recovery.
     *
     * @return array{rows:int,hits:int}
     */
    public static function rebuild_404_summary_counts() {
        // phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- Recovery-only summary rebuild from plugin-owned 404 logs table.
        global $wpdb;

        $summary = array(
            'rows' => 0,
            'hits' => 0,
        );

        if ( self::is_404_logs_table_available() ) {
            // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,WordPress.DB.PreparedSQL.NotPrepared -- Recovery-only rebuild for maintained 404 summary counters; identifier is plugin-owned.
            $summary['rows'] = (int) $wpdb->get_var( "SELECT COUNT(*) FROM `{$wpdb->prefix}srkit_404_logs`" );
            // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,WordPress.DB.PreparedSQL.NotPrepared -- Recovery-only rebuild for maintained 404 summary counters; identifier is plugin-owned.
            $summary['hits'] = (int) $wpdb->get_var( "SELECT COALESCE(SUM(count), 0) FROM `{$wpdb->prefix}srkit_404_logs`" );
        }

        self::update_404_summary_counts( $summary );

        // phpcs:enable WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
        return $summary;
    }

    /**
     * Adjust 404 summary counters after a write.
     *
     * @param int $row_delta Row-count delta.
     * @param int $hit_delta Hit-count delta.
     * @return void
     */
    public static function adjust_404_summary_counts( $row_delta = 0, $hit_delta = 0 ) {
        $summary = self::get_404_summary_counts();

        $summary['rows'] = max( 0, (int) $summary['rows'] + (int) $row_delta );
        $summary['hits'] = max( 0, (int) $summary['hits'] + (int) $hit_delta );

        self::update_404_summary_counts( $summary );
    }

    /**
     * Save 404 summary counters without autoloading.
     *
     * @param array $summary Summary counters.
     * @return void
     */
    private static function update_404_summary_counts( $summary ) {
        $summary = array(
            'rows' => max( 0, absint( isset( $summary['rows'] ) ? $summary['rows'] : 0 ) ),
            'hits' => max( 0, absint( isset( $summary['hits'] ) ? $summary['hits'] : 0 ) ),
        );

        if ( false === get_option( self::SUMMARY_COUNTS_OPTION, false ) ) {
            add_option( self::SUMMARY_COUNTS_OPTION, $summary, '', 'no' );
            return;
        }

        update_option( self::SUMMARY_COUNTS_OPTION, $summary, false );
    }
    
    /**
     * Clear cached 404 statistics
     * Call this after any operation that modifies 404 data
     * 
     * @since 2.1.0
     */
    public static function clear_404_statistics_cache() {
        self::$cached_404_statistics = null;
        delete_transient( 'srk_404_statistics' );
    }

    /**
     * Check whether the 404 logs table is available.
     *
     * Current schema installations are trusted to avoid runtime SHOW TABLES calls.
     *
     * @param bool $force_check Force a physical check, used only after recovery creation.
     * @return bool
     */
    public static function is_404_logs_table_available( $force_check = false ) {
        global $wpdb;

        if ( ! $force_check && null !== self::$cached_table_exists ) {
            return self::$cached_table_exists;
        }

        if (
            ! $force_check
            && class_exists( 'SeoRepairKit_Activator' )
            && SeoRepairKit_Activator::is_database_current()
        ) {
            self::$cached_table_exists = true;
            return true;
        }

        $table_name = self::get_404_logs_table_name();

        self::$cached_table_exists = ( $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $table_name ) ) === $table_name ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- Recovery-only physical table check; current schema path returns before SQL.

        return self::$cached_table_exists;
    }

    /**
     * Clear 404 logs
     *
     * @param int $days Number of days to keep (0 = delete all)
     * @return int Number of records deleted
     */
    public static function clear_404_logs( $days = 0 ) {
        global $wpdb;
        
        $days = absint( $days );
        // phpcs:disable WordPress.DB.DirectDatabaseQuery -- Cleanup writes target the plugin-owned 404 logs table.

        if ( $days > 0 ) {
            // Delete logs older than specified days
            $date_threshold = date( 'Y-m-d H:i:s', strtotime( "-$days days" ) );
            $result = $wpdb->query( // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- Intentional 404 log cleanup write; write operations are not cached.
                $wpdb->prepare(
                    "DELETE FROM `{$wpdb->prefix}srkit_404_logs` WHERE last_accessed < %s",
                    $date_threshold
                )
            );
        } else {
            // Delete all logs
            $result = $wpdb->query( "TRUNCATE TABLE `{$wpdb->prefix}srkit_404_logs`" ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared,WordPress.DB.DirectDatabaseQuery.SchemaChange -- Explicit admin cleanup action for plugin-owned 404 logs.
        }
        // phpcs:enable WordPress.DB.DirectDatabaseQuery
        
        // Clear cached statistics after data modification
        if ( $result !== false ) {
            self::rebuild_404_summary_counts();
            self::clear_404_statistics_cache();
        }

        return $result !== false ? (int) $result : 0;
    }
}
