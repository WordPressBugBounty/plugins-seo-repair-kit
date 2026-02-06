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

    private $db_404;
    private $is_monitoring_enabled;
    
    /**
     * Cache for 404 statistics to prevent duplicate queries within same request
     * @var array|null
     */
    private static $cached_404_statistics = null;

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
        $table_name = $this->db_404->prefix . 'srkit_404_logs';
        
        // Check if table exists
        if ( $this->db_404->get_var( "SHOW TABLES LIKE '$table_name'" ) === $table_name ) {
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
                    // Check again if table was created
                    return ( $this->db_404->get_var( "SHOW TABLES LIKE '$table_name'" ) === $table_name );
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
        $table_name = $this->db_404->prefix . 'srkit_404_logs';
        
        // Double-check table exists before attempting operations
        if ( $this->db_404->get_var( "SHOW TABLES LIKE '$table_name'" ) !== $table_name ) {
            return false;
        }

        // Check if this URL already exists
        $existing = $this->db_404->get_row(
            $this->db_404->prepare(
                "SELECT id, count FROM $table_name WHERE url = %s LIMIT 1",
                $url
            ),
            OBJECT
        );

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
            
            $result = $this->db_404->update(
                $table_name,
                $update_data,
                array( 'id' => $existing->id ),
                $update_format,
                array( '%d' )
            );

            return $result !== false ? $existing->id : false;
        } else {
            // Insert new entry
            $result = $this->db_404->insert(
                $table_name,
                array(
                    'url' => $url,
                    'user_agent' => $user_agent,
                    'ip_address' => $ip_address,
                    'method' => $method,
                    'domain' => $domain,
                    'referrer' => $referrer,
                    'count' => 1,
                    'first_accessed' => current_time( 'mysql' ),
                    'last_accessed' => current_time( 'mysql' ),
                ),
                array( '%s', '%s', '%s', '%s', '%s', '%s', '%d', '%s', '%s' )
            );

            if ( $result !== false ) {
                return $this->db_404->insert_id;
            }
        }

        return false;
    }

    /**
     * Get 404 statistics
     *
     * @return array Statistics
     */
    public static function get_404_statistics() {
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
        
        $table_name = $wpdb->prefix . 'srkit_404_logs';
        
        $stats = array(
            'total_404s' => 0,
            'unique_urls' => 0,
            'total_hits' => 0,
            'most_hit' => null,
            'recent_404s' => array(),
        );

        // Check if table exists (use prepared statement for LIKE clause)
        if ( $wpdb->get_var( $wpdb->prepare( "SHOW TABLES LIKE %s", $table_name ) ) !== $table_name ) {
            // Cache empty stats for 1 minute if table doesn't exist
            set_transient( $cache_key, $stats, MINUTE_IN_SECONDS );
            return $stats;
        }

        // Table name is safe (from $wpdb->prefix which is trusted)
        // Execute queries (table name cannot be parameterized in prepare)
        $stats['total_404s'] = (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$table_name}" );
        $stats['unique_urls'] = (int) $wpdb->get_var( "SELECT COUNT(DISTINCT url) FROM {$table_name}" );
        $stats['total_hits'] = (int) $wpdb->get_var( "SELECT SUM(count) FROM {$table_name}" );
        
        $stats['most_hit'] = $wpdb->get_row(
            "SELECT url, count, last_accessed FROM {$table_name} ORDER BY count DESC, last_accessed DESC LIMIT 1"
        );

        $stats['recent_404s'] = $wpdb->get_results(
            "SELECT url, count, last_accessed FROM {$table_name} ORDER BY last_accessed DESC LIMIT 10"
        );

        // Store in instance-level cache for this request
        self::$cached_404_statistics = $stats;
        
        // Cache stats for 2 minutes (cross-request)
        set_transient( $cache_key, $stats, 2 * MINUTE_IN_SECONDS );

        return $stats;
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
     * Clear 404 logs
     *
     * @param int $days Number of days to keep (0 = delete all)
     * @return int Number of records deleted
     */
    public static function clear_404_logs( $days = 0 ) {
        global $wpdb;
        
        $table_name = $wpdb->prefix . 'srkit_404_logs';

        if ( $days > 0 ) {
            // Delete logs older than specified days
            $date_threshold = date( 'Y-m-d H:i:s', strtotime( "-$days days" ) );
            $result = $wpdb->query(
                $wpdb->prepare(
                    "DELETE FROM $table_name WHERE last_accessed < %s",
                    $date_threshold
                )
            );
        } else {
            // Delete all logs
            $result = $wpdb->query( "TRUNCATE TABLE $table_name" );
        }
        
        // Clear cached statistics after data modification
        if ( $result !== false ) {
            self::clear_404_statistics_cache();
        }

        return $result !== false ? (int) $result : 0;
    }
}