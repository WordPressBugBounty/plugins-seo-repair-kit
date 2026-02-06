<?php
if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/**
 * SeoRepairKit_WeeklySummaryService class.
 *
 * Handles weekly summary report data collection and email sending
 * 
 * @link       https://seorepairkit.com
 * @since      1.0.1
 * @author     TorontoDigits <support@torontodigits.com>
 */
class SeoRepairKit_WeeklySummaryService {

    private static $instance = null;

    /**
     * Get instance
     */
    public static function get_instance() {
        if ( null === self::$instance ) {
            self::$instance = new self();
        }
        return self::$instance;
    }

    /**
     * Initialize the class
     */
    public function __construct() {
        // Register cron schedule
        add_filter('cron_schedules', array($this, 'srk_add_cron_schedules'));
        
        // Register cron hook
        add_action('srk_weekly_seo_summary_cron', array($this, 'srk_send_complete_seo_summary'));
        
        // Initialize on plugins_loaded to ensure hooks are set
        add_action('plugins_loaded', array($this, 'srk_init_cron'));
        
        // Schedule cron on plugin activation
        register_activation_hook(SEOREPAIRKIT_PLUGIN_FILE, array($this, 'srk_schedule_weekly_cron'));
        
        // Clear cron on plugin deactivation
        register_deactivation_hook(SEOREPAIRKIT_PLUGIN_FILE, array($this, 'srk_clear_weekly_cron'));
    }

    /**
     * Get dynamic admin URL for a specific page
     */
    private function srk_get_admin_page_url($page_slug) {
        return admin_url('admin.php?page=' . $page_slug);
    }

    /**
     * Initialize cron
     */
    public function srk_init_cron() {
        // Ensure cron is scheduled when plugin loads
        if (!wp_next_scheduled('srk_weekly_seo_summary_cron')) {
            $this->srk_schedule_weekly_cron();
        }
    }

    /**
     * Add custom cron schedules
     */
    public function srk_add_cron_schedules($schedules) {
        // Add 7 days schedule for testing
        $schedules['srk_seven_days'] = array(
            'interval' => 7 * DAY_IN_SECONDS,
            'display'  => __('Every 7 Days (SEO Repair Kit)'),
        );
        
        return $schedules;
    }

    /**
     * Schedule the weekly cron job
     */
    public function srk_schedule_weekly_cron() {
        // Clear any existing schedule first
        wp_clear_scheduled_hook('srk_weekly_seo_summary_cron');
        
        // Schedule for testing - every 7 days
        $scheduled = wp_schedule_event(time(), 'srk_seven_days', 'srk_weekly_seo_summary_cron');
        
        return $scheduled;
    }

    /**
     * Clear the cron job on deactivation
     */
    public function srk_clear_weekly_cron() {
        wp_clear_scheduled_hook('srk_weekly_seo_summary_cron');
    }

    /**
     * Send complete SEO summary email
     */
    public function srk_send_complete_seo_summary() {
        // Check if weekly reports are enabled
        $weekly_report_enabled = get_option('srk_weekly_report_enabled', true);
        
        if (!$weekly_report_enabled) {
            $this->srk_update_last_status('disabled', 'Weekly reports are disabled in settings');
            return false;
        }
        
        $srk_admin_email = get_option('admin_email');
        $srk_site_name   = get_bloginfo('name');

        if (!is_email($srk_admin_email)) {
            $this->srk_update_last_status('failed', 'Invalid admin email address');
            return false;
        }

        // Collect all data
        $srk_alt_text_data = $this->srk_get_alt_text_data();
        $srk_redirections_data = $this->srk_get_redirections_summary_data();
        $srk_pro_plan_data = $this->srk_get_pro_plan_status();
        $srk_broken_links_data = $this->srk_get_broken_links_data();
        $srk_keytrack_data = $this->srk_get_keytrack_data();

        // Get dynamic URLs
        $dynamic_urls = array(
            'upgrade_pro' => $this->srk_get_admin_page_url('seo-repair-kit-upgrade-pro'),
            'dashboard' => $this->srk_get_admin_page_url('seo-repair-kit-dashboard'),
            'link_scanner' => $this->srk_get_admin_page_url('seo-repair-kit-link-scanner'),
            'alt_image' => $this->srk_get_admin_page_url('alt-image-missing'),
            'redirection' => $this->srk_get_admin_page_url('seo-repair-kit-redirection')
        );

        // Build and send email
        $srk_message = $this->srk_build_email_template(array(
            'site_name' => $srk_site_name,
            'alt_text_data' => $srk_alt_text_data,
            'redirections_data' => $srk_redirections_data,
            'pro_plan_data' => $srk_pro_plan_data,
            'broken_links_data' => $srk_broken_links_data,
            'keytrack_data' => $srk_keytrack_data,
            'urls' => $dynamic_urls
        ));
        
        $srk_subject = "📊 Weekly SEO Report: Search Performance, Links Scan, Alt Text & Redirections - " . esc_html($srk_site_name) . " - " . date('M j, Y');
        $srk_headers = array('Content-Type: text/html; charset=UTF-8');

        $srk_sent = wp_mail($srk_admin_email, $srk_subject, $srk_message, $srk_headers);
        
        if ($srk_sent) {
            // Update last status
            $this->srk_update_last_status('success', 'Email sent successfully');
        } else {
            // Update last status
            $this->srk_update_last_status('failed', 'Failed to send email - check server mail configuration');
        }
        
        return $srk_sent;
    }

    /**
     * Update the last weekly report status
     */
    private function srk_update_last_status($status, $message = '') {
        $last_status = array(
            'status' => $status,
            'message' => $message,
            'timestamp' => current_time('mysql')
        );
        
        update_option('srk_weekly_report_last_status', $last_status);
    }

    /**
     * Get alt text data (ALL TIME - no date filtering)
     */
    public function srk_get_alt_text_data() {
        $srk_args = array(
            'post_type'      => 'attachment',
            'post_mime_type' => 'image',
            'numberposts'    => -1,
            'fields'         => 'ids',
        );
        
        $srk_images = get_posts($srk_args);
        $srk_missing_count = 0;
        $srk_total_images = count($srk_images);

        foreach ($srk_images as $srk_image_id) {
            $srk_alt = get_post_meta($srk_image_id, '_wp_attachment_image_alt', true);
            if (empty($srk_alt)) {
                $srk_missing_count++;
            }
        }

        return array(
            'total_images' => $srk_total_images,
            'missing_count' => $srk_missing_count,
            'period' => 'All Time'
        );
    }

    /**
     * Get Pro Plan status information
     */
    public function srk_get_pro_plan_status() {
        $srk_domain = site_url();
        $srk_cache_key = 'srk_license_status_' . md5( $srk_domain );
        
        // Try to get cached license info first
        $srk_cached_license = get_transient( $srk_cache_key );
        
        if ( $srk_cached_license !== false ) {
            $srk_license_info = $srk_cached_license;
        } else {
            // If not cached, create admin instance and get fresh data
            if ( class_exists( 'SeoRepairKit_Admin' ) ) {
                $srk_admin = new SeoRepairKit_Admin( '', '' );
                $srk_license_info = $srk_admin->get_license_status( $srk_domain );
            } else {
                // Fallback if Admin class not available
                $srk_license_info = array(
                    'status' => 'inactive',
                    'expires_at' => null,
                    'has_chatbot_feature' => false,
                    'message' => 'Free version',
                    'license_key' => 'N/A',
                    'plan_id' => 'free'
                );
            }
        }
        
        $srk_license_status = $srk_license_info['status'] ?? 'inactive';
        $srk_expiration = $srk_license_info['expires_at'] ?? null;
        $srk_has_chatbot = ! empty( $srk_license_info['has_chatbot_feature'] );
        $srk_license_message = $srk_license_info['message'] ?? 'Free version';
        $srk_plan_id = $srk_license_info['plan_id'] ?? 'free';
        
        // Calculate days remaining
        $srk_expires_ts = $srk_expiration ? strtotime( $srk_expiration ) : 0;
        $srk_days_left = $srk_expires_ts ? $this->srk_days_until( $srk_expires_ts ) : null;
        $srk_is_expired = ( $srk_expires_ts && $srk_days_left !== null && $srk_days_left < 0 );
        
        return array(
            'status' => $srk_license_status,
            'expires_at' => $srk_expiration,
            'days_left' => $srk_days_left,
            'is_expired' => $srk_is_expired,
            'has_chatbot' => $srk_has_chatbot,
            'message' => $srk_license_message,
            'plan_id' => $srk_plan_id,
            'is_pro' => ( $srk_license_status === 'active' && ! $srk_is_expired )
        );
    }

    /**
     * Get redirections summary data from database (ALL TIME - no date filtering)
     */
    public function srk_get_redirections_summary_data() {
        global $wpdb;
        
        $srk_redirections_table = $wpdb->prefix . 'srkit_redirection_table';
        
        // Check if table exists
        $srk_table_exists = $wpdb->get_var( $wpdb->prepare( 
            "SHOW TABLES LIKE %s", 
            $srk_redirections_table 
        ) );
        
        if ( $srk_table_exists !== $srk_redirections_table ) {
            return array(
                'total_redirections' => 0,
                'total_hits' => 0,
                'active_redirections' => 0,
                'inactive_redirections' => 0,
                'most_hit_redirect' => array(
                    'from' => 'N/A',
                    'to' => 'N/A', 
                    'hits' => 0
                ),
                'period' => 'All Time'
            );
        }
        
        // Get total redirections count (all time)
        $srk_total_redirections = $wpdb->get_var( "SELECT COUNT(*) FROM $srk_redirections_table" );
        
        // Get total hits (all time)
        $srk_total_hits = $wpdb->get_var( "SELECT SUM(hits) FROM $srk_redirections_table" );
        
        // Get active redirections count
        $srk_active_redirections = $wpdb->get_var( 
            "SELECT COUNT(*) FROM $srk_redirections_table WHERE status = 'active'" 
        );
        
        // Get inactive redirections count  
        $srk_inactive_redirections = $wpdb->get_var(
            "SELECT COUNT(*) FROM $srk_redirections_table WHERE status = 'inactive'"
        );
        
        // Get most hit redirect (all time)
        $srk_most_hit_redirect = $wpdb->get_row(
            "SELECT source_url, target_url, hits 
             FROM $srk_redirections_table 
             ORDER BY hits DESC 
             LIMIT 1"
        );
        
        return array(
            'total_redirections' => $srk_total_redirections ? intval($srk_total_redirections) : 0,
            'total_hits' => $srk_total_hits ? intval($srk_total_hits) : 0,
            'active_redirections' => $srk_active_redirections ? intval($srk_active_redirections) : 0,
            'inactive_redirections' => $srk_inactive_redirections ? intval($srk_inactive_redirections) : 0,
            'most_hit_redirect' => $srk_most_hit_redirect ? array(
                'from' => $srk_most_hit_redirect->source_url,
                'to' => $srk_most_hit_redirect->target_url,
                'hits' => intval($srk_most_hit_redirect->hits)
            ) : array(
                'from' => 'N/A',
                'to' => 'N/A',
                'hits' => 0
            ),
            'period' => 'All Time'
        );
    }

    /**
     * Get broken links scanning data from stored scan results
     */
    public function srk_get_broken_links_data() {
        // Get stored scan snapshot data (accurate results from actual link scanning)
        $link_snapshot = get_option('srk_last_links_snapshot', array());
        
        if (!is_array($link_snapshot)) {
            $link_snapshot = array();
        }
        
        // Get accurate data from stored scan results
        $srk_total_links = isset($link_snapshot['totalLinks']) ? (int) $link_snapshot['totalLinks'] : 0;
        $srk_broken_links = isset($link_snapshot['brokenLinks']) ? (int) $link_snapshot['brokenLinks'] : 0;
        $srk_working_links = isset($link_snapshot['workingLinks']) ? (int) $link_snapshot['workingLinks'] : max(0, $srk_total_links - $srk_broken_links);
        $srk_last_scan_ts = isset($link_snapshot['timestamp']) ? (int) $link_snapshot['timestamp'] : 0;
        
        // Get total posts count for reference
        $srk_args = array(
            'post_type' => array('post', 'page'),
            'post_status' => 'publish',
            'posts_per_page' => -1,
            'fields' => 'ids',
        );
        
        $srk_posts = get_posts($srk_args);
        $srk_total_posts = count($srk_posts);
        
        // Count posts with links (posts that were scanned)
        $srk_posts_with_links = 0;
        if (!empty($link_snapshot['postTypes']) && is_array($link_snapshot['postTypes'])) {
            $srk_posts_with_links = count($link_snapshot['postTypes']);
        }
        
        // Calculate broken percentage
        $srk_broken_percentage = $srk_total_links > 0 ? round(($srk_broken_links / $srk_total_links) * 100, 1) : 0;
        
        // Determine period label based on last scan
        $srk_period = 'All Time';
        if ($srk_last_scan_ts > 0) {
            $srk_scan_date = date('M j, Y', $srk_last_scan_ts);
            $srk_period = 'Last scan: ' . $srk_scan_date;
        }
        
        return array(
            'total_links' => $srk_total_links,
            'broken_links' => $srk_broken_links,
            'total_posts' => $srk_total_posts,
            'posts_with_links' => $srk_posts_with_links,
            'healthy_links' => $srk_working_links,
            'broken_percentage' => $srk_broken_percentage,
            'period' => $srk_period,
            'last_scan_timestamp' => $srk_last_scan_ts
        );
    }

    /**
     * Get KeyTrack data - Fetch fresh GSC data for last 7 days for weekly email
     */
    public function srk_get_keytrack_data() {
        // For weekly email, always use last 7 days period
        // Note: GSC data has 2-3 day delay, so we use yesterday as end date
        // Using -6 days from yesterday gives us exactly 7 days of data (including yesterday)
        $srk_end_date = date('Y-m-d', strtotime('-1 day')); // Yesterday (accounting for GSC delay)
        $srk_start_date = date('Y-m-d', strtotime('-6 days', strtotime($srk_end_date))); // 6 days before yesterday = 7 days total
        
        // Try to fetch fresh GSC data for last 7 days
        $srk_fresh_data = $this->srk_fetch_fresh_gsc_data($srk_start_date, $srk_end_date);
        
        if ($srk_fresh_data && $srk_fresh_data['status'] === 'success') {
            // Use fresh data with "Last 7 Days" period
            $srk_fresh_data['data']['period'] = 'Last 7 Days';
            return $srk_fresh_data;
        }
        
        // Fallback: Return empty data structure instead of misleading stored data
        // Stored data might be from different date range (90 days, 28 days, etc.)
        return array(
            'status' => 'no_data',
            'message' => 'Unable to fetch fresh GSC data for last 7 days. Please ensure Google Site Kit is connected.',
            'data' => array(
                'total_clicks' => 0,
                'total_impressions' => 0,
                'average_ctr' => '0.00',
                'average_position' => '0.00',
                'top_pages' => array(),
                'top_queries' => array(),
                'period' => 'Last 7 Days',
                'keytrack_name' => 'Weekly Report',
                'last_updated' => date('Y-m-d H:i:s')
            )
        );
    }
    
    /**
     * Fetch fresh GSC data from Google Search Console for specified date range
     */
    private function srk_fetch_fresh_gsc_data($start_date, $end_date) {
        // Check if Google Site Kit is available
        if (!class_exists('Google\Site_Kit\Plugin')) {
            return false;
        }
        
        // Set up user context for Site Kit authentication
        $srk_admin_email = sanitize_email(get_bloginfo('admin_email'));
        $srk_admin_user = false;
        
        if (is_email($srk_admin_email)) {
            $srk_admin_user = get_user_by('email', $srk_admin_email);
        }
        
        // Fallback: first administrator by role
        if (!$srk_admin_user) {
            $srk_admins = get_users(array('role' => 'administrator'));
            if (!empty($srk_admins)) {
                $srk_admin_user = $srk_admins[0];
            }
        }
        
        if (!$srk_admin_user) {
            return false;
        }
        
        wp_set_current_user($srk_admin_user->ID);
        
        // Build Site Kit objects
        try {
            $srk_sitekit_instance = \Google\Site_Kit\Plugin::instance();
            $srk_sitekit_context = $srk_sitekit_instance->context();
            $srk_sitekit_options = new \Google\Site_Kit\Core\Storage\Options($srk_sitekit_context);
            $srk_sitekit_user_options = new \Google\Site_Kit\Core\Storage\User_Options($srk_sitekit_context);
            $srk_sitekit_authentication = new \Google\Site_Kit\Core\Authentication\Authentication(
                $srk_sitekit_context,
                $srk_sitekit_options,
                $srk_sitekit_user_options
            );
            $srk_sitekit_modules = new \Google\Site_Kit\Core\Modules\Modules(
                $srk_sitekit_context,
                $srk_sitekit_options,
                $srk_sitekit_user_options,
                $srk_sitekit_authentication
            );
            $srk_sitekit_search_console = $srk_sitekit_modules->get_module('search-console');
            
            // Check if Search Console is connected
            if (!$srk_sitekit_search_console || !$srk_sitekit_search_console->is_connected()) {
                return false;
            }
            
            // Fetch overall performance data
            $srk_gsc_overall_data = $srk_sitekit_search_console->get_data(
                'searchanalytics',
                array(
                    'dimensions' => array(),
                    'startDate' => $start_date,
                    'endDate' => $end_date,
                    'rowLimit' => 10000,
                )
            );
            
            // Check for WP_Error
            if (is_wp_error($srk_gsc_overall_data)) {
                return false;
            }
            
            $srk_total_clicks = 0;
            $srk_total_impressions = 0;
            $srk_total_ctr = 0;
            $srk_total_position = 0;
            $srk_data_count = 0;
            
            if (!empty($srk_gsc_overall_data) && is_array($srk_gsc_overall_data)) {
                foreach ($srk_gsc_overall_data as $srk_row) {
                    $srk_total_clicks += isset($srk_row['clicks']) ? (int) $srk_row['clicks'] : 0;
                    $srk_total_impressions += isset($srk_row['impressions']) ? (int) $srk_row['impressions'] : 0;
                    $srk_total_ctr += isset($srk_row['ctr']) ? (float) $srk_row['ctr'] : 0;
                    $srk_total_position += isset($srk_row['position']) ? (float) $srk_row['position'] : 0;
                    $srk_data_count++;
                }
            }
            
            $srk_average_ctr = $srk_data_count > 0 ? ($srk_total_ctr / $srk_data_count) : 0;
            $srk_average_position = $srk_data_count > 0 ? ($srk_total_position / $srk_data_count) : 0;
            
            // Fetch top pages data
            $srk_gsc_pages_data = $srk_sitekit_search_console->get_data(
                'searchanalytics',
                array(
                    'dimensions' => array('page'),
                    'startDate' => $start_date,
                    'endDate' => $end_date,
                    'rowLimit' => 10,
                )
            );
            
            // Check for WP_Error
            if (is_wp_error($srk_gsc_pages_data)) {
                $srk_gsc_pages_data = array();
            }
            
            $srk_top_pages = array();
            if (!empty($srk_gsc_pages_data) && is_array($srk_gsc_pages_data)) {
                // Sort pages by clicks (descending), then by impressions if clicks are equal
                usort($srk_gsc_pages_data, function($a, $b) {
                    $a_clicks = isset($a['clicks']) ? $a['clicks'] : 0;
                    $b_clicks = isset($b['clicks']) ? $b['clicks'] : 0;
                    if ($a_clicks === $b_clicks) {
                        $a_impressions = isset($a['impressions']) ? $a['impressions'] : 0;
                        $b_impressions = isset($b['impressions']) ? $b['impressions'] : 0;
                        return $b_impressions <=> $a_impressions;
                    }
                    return $b_clicks <=> $a_clicks;
                });
                
                // Get top 3 pages after sorting
                foreach (array_slice($srk_gsc_pages_data, 0, 3) as $srk_page) {
                    $srk_top_pages[] = array(
                        'url' => isset($srk_page['keys'][0]) ? $srk_page['keys'][0] : 'N/A',
                        'clicks' => isset($srk_page['clicks']) ? (int) $srk_page['clicks'] : 0,
                        'impressions' => isset($srk_page['impressions']) ? (int) $srk_page['impressions'] : 0,
                        'position' => number_format(isset($srk_page['position']) ? $srk_page['position'] : 0, 2)
                    );
                }
            }
            
            // Fetch top queries
            $srk_gsc_queries_data = $srk_sitekit_search_console->get_data(
                'searchanalytics',
                array(
                    'dimensions' => array('query'),
                    'startDate' => $start_date,
                    'endDate' => $end_date,
                    'rowLimit' => 10,
                )
            );
            
            // Check for WP_Error
            if (is_wp_error($srk_gsc_queries_data)) {
                $srk_gsc_queries_data = array();
            }
            
            $srk_top_queries = array();
            if (!empty($srk_gsc_queries_data) && is_array($srk_gsc_queries_data)) {
                // Sort queries by clicks (descending), then by impressions if clicks are equal
                usort($srk_gsc_queries_data, function($a, $b) {
                    $a_clicks = isset($a['clicks']) ? $a['clicks'] : 0;
                    $b_clicks = isset($b['clicks']) ? $b['clicks'] : 0;
                    if ($a_clicks === $b_clicks) {
                        $a_impressions = isset($a['impressions']) ? $a['impressions'] : 0;
                        $b_impressions = isset($b['impressions']) ? $b['impressions'] : 0;
                        return $b_impressions <=> $a_impressions;
                    }
                    return $b_clicks <=> $a_clicks;
                });
                
                // Get top 3 queries after sorting
                foreach (array_slice($srk_gsc_queries_data, 0, 3) as $srk_query) {
                    $srk_top_queries[] = array(
                        'query' => isset($srk_query['keys'][0]) ? $srk_query['keys'][0] : 'N/A',
                        'clicks' => isset($srk_query['clicks']) ? (int) $srk_query['clicks'] : 0,
                        'impressions' => isset($srk_query['impressions']) ? (int) $srk_query['impressions'] : 0,
                        'position' => number_format(isset($srk_query['position']) ? $srk_query['position'] : 0, 2)
                    );
                }
            }
            
            return array(
                'status' => 'success',
                'message' => 'Data fetched for last 7 days',
                'data' => array(
                    'total_clicks' => $srk_total_clicks,
                    'total_impressions' => $srk_total_impressions,
                    'average_ctr' => number_format($srk_average_ctr * 100, 2),
                    'average_position' => number_format($srk_average_position, 2),
                    'top_pages' => $srk_top_pages,
                    'top_queries' => $srk_top_queries,
                    'period' => 'Last 7 Days',
                    'keytrack_name' => 'Weekly Report',
                    'last_updated' => date('Y-m-d H:i:s')
                )
            );
            
        } catch (Exception $e) {
            return false;
        }
    }
    
    /**
     * Get stored GSC data from database (fallback method)
     */
    private function srk_get_stored_gsc_data() {
        global $wpdb;
        
        $srk_gsc_table = $wpdb->prefix . 'srkit_gsc_data';
        
        // Check if table exists
        $srk_table_exists = $wpdb->get_var($wpdb->prepare(
            "SHOW TABLES LIKE %s", 
            $srk_gsc_table
        ));
        
        if ($srk_table_exists !== $srk_gsc_table) {
            return array(
                'status' => 'not_available',
                'message' => 'KeyTrack data table not found',
                'data' => array()
            );
        }
        
        // Get the most recent GSC data from database
        $srk_recent_data = $wpdb->get_row(
            "SELECT gsc_data, keytrack_name, created_at 
             FROM $srk_gsc_table 
             ORDER BY created_at DESC 
             LIMIT 1",
            ARRAY_A
        );
        
        if (!$srk_recent_data || empty($srk_recent_data['gsc_data'])) {
            return array(
                'status' => 'no_data',
                'message' => 'No KeyTrack data available in database',
                'data' => array()
            );
        }
        
        try {
            $srk_gsc_data = json_decode($srk_recent_data['gsc_data'], true);
            
            if (json_last_error() !== JSON_ERROR_NONE) {
                throw new Exception('Invalid JSON data in database');
            }
            
            // Extract data from the stored structure
            $srk_summary = $srk_gsc_data['summary'] ?? array();
            $srk_keywords = $srk_gsc_data['keywords'] ?? array();
            
            // Note: Stored GSC data only contains keywords (queries), not page-level data
            // Top pages section will be empty if no pages data exists in the structure
            $srk_top_pages = array();
            if (!empty($srk_gsc_data['pages']) && is_array($srk_gsc_data['pages'])) {
                // If pages data exists in the stored structure, use it
                foreach (array_slice($srk_gsc_data['pages'], 0, 3) as $srk_page) {
                    $srk_top_pages[] = array(
                        'url' => $srk_page['url'] ?? $srk_page['page'] ?? 'N/A',
                        'clicks' => $srk_page['clicks'] ?? 0,
                        'impressions' => $srk_page['impressions'] ?? 0,
                        'position' => number_format($srk_page['position'] ?? 0, 2)
                    );
                }
            }
            
            // Get top queries from keywords data
            $srk_top_queries = array();
            if (!empty($srk_keywords)) {
                foreach (array_slice($srk_keywords, 0, 3) as $srk_keyword) {
                    $srk_top_queries[] = array(
                        'query' => $srk_keyword['keyword_name'] ?? 'N/A',
                        'clicks' => $srk_keyword['clicks'] ?? 0,
                        'impressions' => $srk_keyword['impressions'] ?? 0,
                        'position' => number_format($srk_keyword['position'] ?? 0, 2)
                    );
                }
            }
            
            return array(
                'status' => 'success',
                'message' => 'Data retrieved from database',
                'data' => array(
                    'total_clicks' => $srk_summary['total_clicks'] ?? 0,
                    'total_impressions' => $srk_summary['total_impressions'] ?? 0,
                    'average_ctr' => number_format(($srk_summary['average_ctr'] ?? 0) * 100, 2),
                    'average_position' => number_format($srk_summary['average_position'] ?? 0, 2),
                    'top_pages' => $srk_top_pages,
                    'top_queries' => $srk_top_queries,
                    'period' => 'Last 7 Days',
                    'keytrack_name' => $srk_recent_data['keytrack_name'] ?? 'N/A',
                    'last_updated' => $srk_recent_data['created_at'] ?? 'Unknown'
                )
            );
            
        } catch (Exception $e) {
            return array(
                'status' => 'error',
                'message' => $e->getMessage(),
                'data' => array()
            );
        }
    }

    /**
     * Helper: days remaining until a timestamp
     */
    private function srk_days_until( $srk_ts ) {
        if ( ! $srk_ts ) return null;
        $srk_now = current_time( 'timestamp' );
        return (int) floor( ( $srk_ts - $srk_now ) / DAY_IN_SECONDS );
    }

    /**
     * Build email template - Complete version with dynamic URLs
     */
    private function srk_build_email_template($srk_data) {
        // Extract variables
        $site_name = isset($srk_data['site_name']) ? $srk_data['site_name'] : get_bloginfo('name');
        $alt_text_data = isset($srk_data['alt_text_data']) ? $srk_data['alt_text_data'] : array();
        $redirections_data = isset($srk_data['redirections_data']) ? $srk_data['redirections_data'] : array();
        $pro_plan_data = isset($srk_data['pro_plan_data']) ? $srk_data['pro_plan_data'] : array();
        $broken_links_data = isset($srk_data['broken_links_data']) ? $srk_data['broken_links_data'] : array();
        $keytrack_data = isset($srk_data['keytrack_data']) ? $srk_data['keytrack_data'] : array();
        $urls = isset($srk_data['urls']) ? $srk_data['urls'] : array(
            'upgrade_pro' => $this->srk_get_admin_page_url('seo-repair-kit-upgrade-pro'),
            'dashboard' => $this->srk_get_admin_page_url('seo-repair-kit'),
            'link_scanner' => $this->srk_get_admin_page_url('seo-repair-kit-link-scanner'),
            'alt_image' => $this->srk_get_admin_page_url('alt-image-missing'),
            'redirection' => $this->srk_get_admin_page_url('seo-repair-kit-redirection')
        );
        
        ob_start();
        ?>
        <div style="font-family: 'Segoe UI', Arial, sans-serif; color: #333; padding: 20px; max-width: 700px; margin: auto; background-color: #ffffff;">
            <!-- Enhanced Header with Dark Blue Background -->
            <div style="background: linear-gradient(135deg, #0b1d51 0%, #1a2b6d 100%); color: white; padding: 25px 0 20px 0; border-radius: 12px 12px 0 0; box-shadow: 0 4px 12px rgba(11, 29, 81, 0.2);">
                <table width="100%" cellpadding="0" cellspacing="0" style="padding: 0 25px;">
                    <tr>
                        <td style="text-align: left; padding-left: 15px;">
                            <h1 style="margin: 0; font-size: 28px; font-weight: 700; color: white; letter-spacing: -0.5px;">📊 SEO Report</h1>
                            <p style="margin: 8px 0 0 0; font-size: 14px; color: rgba(255, 255, 255, 0.9);">
                                <?php echo esc_html($site_name); ?> • <?php echo date('F j, Y'); ?>
                            </p>
                        </td>
                        <td style="text-align: right; width: 120px; padding-right: 15px;">
                            <div style="background: rgba(255, 255, 255, 0.15); padding: 10px 15px; border-radius: 8px; display: inline-block; border: 1px solid rgba(255, 255, 255, 0.2);">
                                <span style="font-size: 12px; color: white; font-weight: 600; letter-spacing: 0.5px;">WEEKLY</span>
                            </div>
                        </td>
                    </tr>
                </table>
            </div>

            <!-- Body Container -->
            <div style="background: #f8fafc; padding: 35px 30px; border-radius: 0 0 12px 12px;">

                <!-- Welcome Message -->
                <div style="background: white; padding: 25px; border-radius: 10px; margin-bottom: 25px; box-shadow: 0 3px 15px rgba(0,0,0,0.05); border-left: 4px solid #F28500;">
                    <p style="font-size: 16px; line-height: 1.6; color: #555; margin: 0;">
                        Hello! Here's your <strong style="color: #0b1d51;">weekly SEO performance report</strong> for 
                        <strong style="color: #F28500;"><?php echo esc_html($site_name); ?></strong>. Below is a comprehensive overview of your site's search performance and technical health.
                    </p>
                </div>

                <!-- Pro Plan Status Card -->
                <div style="background: linear-gradient(135deg, #f8f9fa 0%, #ffffff 100%); padding: 25px; border-radius: 10px; margin: 25px 0; text-align: center; border: 2px solid #e9ecef; box-shadow: 0 4px 12px rgba(0,0,0,0.06);">
                    <p style="margin: 0 0 15px 0; font-size: 17px; color: #0b1d51; font-weight: 600;">
                        You're on the <span style="color: #F28500;">Free Plan</span>!<br>
                        <span style="font-size: 15px; color: #666; font-weight: normal;">Unlock advanced features and get priority support by upgrading to Pro.</span>
                    </p>
                    <a href="<?php echo esc_url($urls['upgrade_pro']); ?>" style="display: inline-block; background: linear-gradient(135deg, #F28500 0%, #ff9a3c 100%); color: white; padding: 14px 28px; text-decoration: none; border-radius: 8px; font-weight: bold; font-size: 15px; margin-top: 10px; box-shadow: 0 4px 12px rgba(242, 133, 0, 0.25); transition: all 0.3s ease; border: none;">
                        🚀 Upgrade to Pro
                    </a>
                </div>
                
                <!-- Divider -->
                <hr style="border: none; height: 1px; background: linear-gradient(to right, transparent, #e0e0e0, transparent); margin: 30px 0;">

                <!-- KeyTrack / Search Performance -->
                <div style="margin-bottom: 35px;">
                    <h2 style="margin: 0 0 25px 0; color: #0b1d51; font-size: 22px; font-weight: 700; border-bottom: 3px solid #F28500; padding-bottom: 10px; display: flex; align-items: center; gap: 10px;">
                        Search Performance Keytrack
                    </h2>
                    
                    <?php if(isset($keytrack_data['status']) && $keytrack_data['status'] === 'success' && !empty($keytrack_data['data'])): 
                        $srk_kt = $keytrack_data['data'];
                    ?>
                    <!-- Main Metrics Grid -->
                    <div style="background: white; border-radius: 12px; padding: 15px; box-shadow: 0 5px 20px rgba(0,0,0,0.08); margin-bottom: 30px; border: 1px solid #e5e7eb;">
                        <!-- 4 Metrics Grid -->
                        <div style="display: grid; grid-template-columns: repeat(2, 1fr); gap: 20px; width:100%;">
                            <!-- Clicks -->
                            <div style="text-align: center; padding: 25px; border-radius: 10px; background: linear-gradient(135deg, #f8f9fa 0%, #ffffff 100%); border: 2px solid #e5e7eb; box-shadow: 0 4px 8px rgba(0,0,0,0.04);">
                                <div style="color: #666; font-size: 14px; margin-bottom: 12px; font-weight: 600; letter-spacing: 0.5px;">CLICKS</div>
                                <div style="font-size: 34px; font-weight: 800; color: #0b1d51; line-height: 1;">
                                    <?php echo number_format(isset($srk_kt['total_clicks']) ? $srk_kt['total_clicks'] : 0); ?>
                                </div>
                                <div style="color: #F28500; font-size: 12px; font-weight: 600; margin-top: 10px; background: rgba(242, 133, 0, 0.1); padding: 4px 8px; border-radius: 4px; display: inline-block;">
                                    <?php echo isset($srk_kt['period']) ? $srk_kt['period'] : 'N/A'; ?>
                                </div>
                            </div>

                            <!-- Impressions -->
                            <div style="text-align: center; padding: 25px; border-radius: 10px; background: linear-gradient(135deg, #f8f9fa 0%, #ffffff 100%); border: 2px solid #e5e7eb; box-shadow: 0 4px 8px rgba(0,0,0,0.04);">
                                <div style="color: #666; font-size: 14px; margin-bottom: 12px; font-weight: 600; letter-spacing: 0.5px;">IMPRESSIONS</div>
                                <div style="font-size: 34px; font-weight: 800; color: #0b1d51; line-height: 1;">
                                    <?php echo number_format(isset($srk_kt['total_impressions']) ? $srk_kt['total_impressions'] : 0); ?>
                                </div>
                                <div style="color: #F28500; font-size: 12px; font-weight: 600; margin-top: 10px; background: rgba(242, 133, 0, 0.1); padding: 4px 8px; border-radius: 4px; display: inline-block;">
                                    <?php echo isset($srk_kt['period']) ? $srk_kt['period'] : 'N/A'; ?>
                                </div>
                            </div>

                            <!-- Avg CTR -->
                            <div style="text-align: center; padding: 25px; border-radius: 10px; background: linear-gradient(135deg, #f8f9fa 0%, #ffffff 100%); border: 2px solid #e5e7eb; box-shadow: 0 4px 8px rgba(0,0,0,0.04);">
                                <div style="color: #666; font-size: 14px; margin-bottom: 12px; font-weight: 600; letter-spacing: 0.5px;">AVG CTR</div>
                                <div style="font-size: 34px; font-weight: 800; color: #F28500; line-height: 1;">
                                    <?php echo isset($srk_kt['average_ctr']) ? $srk_kt['average_ctr'] : '0.00'; ?>%
                                </div>
                                <div style="color: #666; font-size: 12px; margin-top: 10px;">Click-through Rate</div>
                            </div>

                            <!-- Avg Position -->
                            <div style="text-align: center; padding: 25px; border-radius: 10px; background: linear-gradient(135deg, #f8f9fa 0%, #ffffff 100%); border: 2px solid #e5e7eb; box-shadow: 0 4px 8px rgba(0,0,0,0.04);">
                                <div style="color: #666; font-size: 14px; margin-bottom: 12px; font-weight: 600; letter-spacing: 0.5px;">AVG POSITION</div>
                                <div style="font-size: 34px; font-weight: 800; color: #0b1d51; line-height: 1;">
                                    <?php echo isset($srk_kt['average_position']) ? $srk_kt['average_position'] : '0.00'; ?>
                                </div>
                                <div style="color: #666; font-size: 12px; margin-top: 10px;">Search Ranking</div>
                            </div>
                        </div>
                    </div>

                    <!-- Top Performing Pages -->
                    <?php if(!empty($srk_kt['top_pages'])): ?>
                    <div style="margin-bottom: 25px;">
                        <h3 style="margin: 0 0 18px 0; color: #0b1d51; font-size: 18px; font-weight: 700; display: flex; align-items: center; gap: 8px;">
                            <span style="color: white; width: 30px; height: 30px; border-radius: 6px; display: inline-flex; align-items: center; justify-content: center; font-size: 14px;"></span>
                            Top Performing Pages
                        </h3>
                        <div style="background: white; padding: 0; border-radius: 10px; border: 2px solid #f0f0f0; overflow: hidden; box-shadow: 0 3px 12px rgba(0,0,0,0.05);">
                            <?php foreach ($srk_kt['top_pages'] as $srk_index => $srk_page): ?>
                            <div style="padding: 18px 20px; border-bottom: <?php echo ($srk_index < count($srk_kt['top_pages']) - 1) ? '1px solid #f0f0f0' : 'none'; ?>; background: <?php echo $srk_index % 2 === 0 ? '#ffffff' : '#fafafa'; ?>;">
                                <div style="color: #0b1d51; font-size: 15px; font-weight: 600; margin-bottom: 5px;">
                                    <?php echo esc_html(wp_trim_words($srk_page['url'], 8)); ?>
                                </div>
                                <div style="display: flex; gap: 20px; font-size: 13px; color: #666;">
                                    <span>👁️ <strong><?php echo number_format($srk_page['impressions']); ?></strong> impressions</span>
                                    <span>👆 <strong><?php echo number_format($srk_page['clicks']); ?></strong> clicks</span>
                                    <span>📈 <strong><?php echo $srk_page['position']; ?></strong> position</span>
                                </div>
                            </div>
                            <?php endforeach; ?>
                        </div>
                    </div>
                    <?php endif; ?>

                    <!-- Top Search Queries -->
                    <?php if(!empty($srk_kt['top_queries'])): ?>
                    <div style="margin-bottom: 25px;">
                        <h3 style="margin: 0 0 18px 0; color: #0b1d51; font-size: 18px; font-weight: 700; display: flex; align-items: center; gap: 8px;">
                            <span style="color: white; width: 30px; height: 30px; border-radius: 6px; display: inline-flex; align-items: center; justify-content: center; font-size: 14px;"></span>
                            Top Search Queries
                        </h3>
                        <div style="background: white; padding: 0; border-radius: 10px; border: 2px solid #f0f0f0; overflow: hidden; box-shadow: 0 3px 12px rgba(0,0,0,0.05);">
                            <?php foreach ($srk_kt['top_queries'] as $srk_index => $srk_query): ?>
                            <div style="padding: 18px 20px; border-bottom: <?php echo ($srk_index < count($srk_kt['top_queries']) - 1) ? '1px solid #f0f0f0' : 'none'; ?>; background: <?php echo $srk_index % 2 === 0 ? '#ffffff' : '#fafafa'; ?>;">
                                <div style="color: #0b1d51; font-size: 15px; font-weight: 600; margin-bottom: 5px; font-style: italic;">
                                    "<?php echo esc_html($srk_query['query']); ?>"
                                </div>
                                <div style="display: flex; gap: 20px; font-size: 13px; color: #666;">
                                    <span>👁️ <strong><?php echo number_format($srk_query['impressions']); ?></strong> impressions</span>
                                    <span>👆 <strong><?php echo number_format($srk_query['clicks']); ?></strong> clicks</span>
                                    <span>📈 <strong><?php echo $srk_query['position']; ?></strong> position</span>
                                </div>
                            </div>
                            <?php endforeach; ?>
                        </div>
                    </div>
                    <?php endif; ?>

                    <?php else: ?>
                        <div style="background: #fffaf0; padding: 25px; border-radius: 10px; text-align: center; border: 2px solid #F28500; margin-bottom: 25px;">
                            <p style="margin: 0; color: #0b1d51; font-size: 15px; font-weight: 600;">
                                ⚠️ Search Console data is currently unavailable.
                            </p>
                            <p style="margin: 10px 0 0 0; color: #666; font-size: 14px;">
                                Please ensure Google Site Kit is connected and configured properly.
                            </p>
                        </div>
                    <?php endif; ?>
                </div>

                <!-- Technical Health Check Section - UPDATED DESIGN -->
                <div style="margin-bottom: 60px;">
                    <!-- <h2 style="margin: 0 0 30px 0; color: #0b1d51; font-size: 24px; font-weight: 700; border-bottom: 3px solid #F28500; padding-bottom: 12px; display: flex; align-items: center; gap: 10px;">
                        Technical Health Check
                    </h2> -->
                    
                    <!-- Section 1: Broken Links -->
                    <div style="margin-bottom: 40px; padding-bottom: 25px; border-bottom: 1px solid #eee;">
                        <h3 style="margin: 0 0 20px 0; color: #0b1d51; font-size: 18px; font-weight: 700; display: flex; align-items: center; gap: 8px;">
                            Broken Links Analysis
                        </h3>
                        
                        <div style="background: white; border-radius: 12px; padding: 25px; box-shadow: 0 4px 15px rgba(0, 0, 0, 0.07); border-top: 4px solid #dc3545;">
                            <div style="display: flex; align-items: center; margin-bottom: 20px;">
                                <h4 style="margin: 0; color: #0b1d51; font-size: 16px; font-weight: 700;">
                                    Broken Links Status
                                </h4>
                            </div>
                            
                            <div style="text-align: center; margin-bottom: 20px;">
                                <div style="font-size: 36px; font-weight: 700; color: #0b1d51; line-height: 1; margin-bottom: 10px;">
                                    <?php echo isset($broken_links_data['broken_links']) ? $broken_links_data['broken_links'] : 0; ?> / <?php echo isset($broken_links_data['total_links']) ? $broken_links_data['total_links'] : 0; ?>
                                </div>
                                <div style="font-size: 14px; color: #666;">
                                    <?php echo isset($broken_links_data['broken_links']) ? $broken_links_data['broken_links'] : 0; ?> broken links found out of <?php echo isset($broken_links_data['total_links']) ? $broken_links_data['total_links'] : 0; ?> total links
                                </div>
                            </div>
                            
                            <?php 
                            $broken_percentage = (isset($broken_links_data['total_links']) && $broken_links_data['total_links'] > 0) ? 
                                round((isset($broken_links_data['broken_links']) ? $broken_links_data['broken_links'] : 0) / $broken_links_data['total_links'] * 100) : 0;
                            $health_score = 100 - $broken_percentage;
                            ?>
                            <div style="background: #f8f9fa; padding: 15px; border-radius: 8px;">
                                <div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 8px;">
                                    <div style="font-size: 14px; color: #666;">
                                        Health Score: <strong style="color: <?php echo $health_score >= 80 ? '#28a745' : ($health_score >= 60 ? '#ffc107' : '#dc3545'); ?>;"><?php echo $health_score; ?>%</strong>
                                    </div>
                                    <div style="font-size: 12px; color: #999;">
                                        <?php echo $broken_percentage; ?>% broken rate
                                    </div>
                                </div>
                                <div style="height: 8px; background: #e9ecef; border-radius: 4px; overflow: hidden;">
                                    <div style="height: 100%; width: <?php echo $health_score; ?>%; background: linear-gradient(90deg, <?php echo $health_score >= 80 ? '#28a745' : ($health_score >= 60 ? '#ffc107' : '#dc3545'); ?> 0%, <?php echo $health_score >= 80 ? '#34d058' : ($health_score >= 60 ? '#ffd54f' : '#ff6b6b'); ?> 100%);"></div>
                                </div>
                            </div>
                        </div>
                    </div>
                    
                    <!-- Section 2: Alt Text -->
                    <div style="margin-bottom: 30px; padding-bottom: 25px; border-bottom: 1px solid #eee;">
                        <h3 style="margin: 0 0 20px 0; color: #0b1d51; font-size: 18px; font-weight: 700; display: flex; align-items: center; gap: 8px;">
                            Image Alt Text Analysis
                        </h3>
                        
                        <div style="background: white; border-radius: 12px; padding: 25px; box-shadow: 0 4px 15px rgba(0, 0, 0, 0.07); border-top: 4px solid #17a2b8;">
                            <div style="display: flex; align-items: center; margin-bottom: 20px;">
                                <h4 style="margin: 0; color: #0b1d51; font-size: 16px; font-weight: 700;">
                                    Alt Text Status
                                </h4>
                            </div>
                            
                            <?php 
                            $total_images = isset($alt_text_data['total_images']) ? $alt_text_data['total_images'] : 0;
                            $images_with_alt = $total_images - (isset($alt_text_data['missing_count']) ? $alt_text_data['missing_count'] : 0);
                            $alt_percentage = $total_images > 0 ? round(($images_with_alt / $total_images) * 100) : 0;
                            ?>
                            
                            <div style="text-align: center; margin-bottom: 20px;">
                                <div style="font-size: 36px; font-weight: 700; color: #17a2b8; line-height: 1; margin-bottom: 10px;">
                                    <?php echo isset($alt_text_data['missing_count']) ? $alt_text_data['missing_count'] : 0; ?> / <?php echo $total_images; ?>
                                </div>
                                <div style="font-size: 14px; color: #666;">
                                    <span style="color: #28a745; font-weight: 500;"><?php echo $images_with_alt; ?></span> images with alt text • 
                                    <span style="color: #dc3545; font-weight: 500;"><?php echo isset($alt_text_data['missing_count']) ? $alt_text_data['missing_count'] : 0; ?></span> missing alt text
                                </div>
                            </div>
                            
                            <div style="background: #f8f9fa; padding: 15px; border-radius: 8px;">
                                <div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 8px;">
                                    <div style="font-size: 14px; color: #666;">
                                        Optimization: <strong style="color: <?php echo $alt_percentage >= 80 ? '#28a745' : ($alt_percentage >= 50 ? '#ffc107' : '#dc3545'); ?>;"><?php echo $alt_percentage; ?>%</strong>
                                    </div>
                                    <div style="font-size: 12px; color: #999;">
                                        <?php echo (100 - $alt_percentage); ?>% improvement needed
                                    </div>
                                </div>
                                <div style="height: 8px; background: #e9ecef; border-radius: 4px; overflow: hidden;">
                                    <div style="height: 100%; width: <?php echo $alt_percentage; ?>%; background: linear-gradient(90deg, <?php echo $alt_percentage >= 80 ? '#28a745' : ($alt_percentage >= 50 ? '#ffc107' : '#dc3545'); ?> 0%, <?php echo $alt_percentage >= 80 ? '#34d058' : ($alt_percentage >= 50 ? '#ffd54f' : '#ff6b6b'); ?> 100%);"></div>
                                </div>
                            </div>
                        </div>
                    </div>
                    
                    <!-- Section 3: Redirections -->
                    <div style="margin-bottom: 20px;">
                        <h3 style="margin: 0 0 15px 0; color: #0b1d51; font-size: 18px; font-weight: 700; display: flex; align-items: center; gap: 8px;">
                        Advanced Redirections</h3>
                        
                        <div style="background: white; border-radius: 12px; padding: 25px; box-shadow: 0 4px 15px rgba(0, 0, 0, 0.07); border-top: 4px solid #F28500;">
                            <div style="display: flex; align-items: center; margin-bottom: 20px;">
                                <h4 style="margin: 0; color: #0b1d51; font-size: 16px; font-weight: 700;">
                                    Redirections Status
                                </h4>
                            </div>
                            
                            <div style="text-align: center; margin-bottom: 20px;">
                                <div style="font-size: 36px; font-weight: 600; color: #F28500; line-height: 1; margin-bottom: 10px;">
                                    <?php echo isset($redirections_data['total_redirections']) ? $redirections_data['total_redirections'] : 0; ?>
                                </div>
                                <div style="font-size: 14px; color: #666;">
                                    <span style="color: #28a745; font-weight: 500;"><?php echo isset($redirections_data['active_redirections']) ? $redirections_data['active_redirections'] : 0; ?></span> active • 
                                    <span style="color: #dc3545; font-weight: 500;"><?php echo (isset($redirections_data['total_redirections']) ? $redirections_data['total_redirections'] : 0) - (isset($redirections_data['active_redirections']) ? $redirections_data['active_redirections'] : 0); ?></span> inactive •
                                    <span style="color: #17a2b8; font-weight: 500;"><?php echo number_format(isset($redirections_data['total_hits']) ? $redirections_data['total_hits'] : 0); ?></span> total hits
                                </div>
                            </div>
                            
                            <?php if(isset($redirections_data['most_hit_redirect']['hits']) && $redirections_data['most_hit_redirect']['hits'] > 0): ?>
                            <div style="background: #f8f9fa; padding: 15px; border-radius: 8px;">
                                <div style="font-size: 14px; color: #666; margin-bottom: 10px; font-weight: 600;">
                                    Most Active Redirect
                                </div>
                                <div style="display: flex; align-items: center; gap: 10px; margin-bottom: 8px;">
                                    <div style="font-size: 24px; font-weight: 800; color: #F28500;">
                                        <?php echo number_format($redirections_data['most_hit_redirect']['hits']); ?>
                                    </div>
                                    <div style="font-size: 12px; color: #999;">
                                        hits
                                    </div>
                                </div>
                                <div style="font-size: 12px; color: #666;">
                                    <div style="margin-bottom: 4px; overflow: hidden; text-overflow: ellipsis; white-space: nowrap;">
                                        <strong>From:</strong> <?php echo esc_html(wp_trim_words($redirections_data['most_hit_redirect']['from'], 8)); ?>
                                    </div>
                                    <div style="overflow: hidden; text-overflow: ellipsis; white-space: nowrap;">
                                        <strong>To:</strong> <?php echo esc_html(wp_trim_words($redirections_data['most_hit_redirect']['to'], 8)); ?>
                                    </div>
                                </div>
                            </div>
                            <?php else: ?>
                            <div style="background: #f8f9fa; padding: 15px; border-radius: 8px; text-align: center;">
                                <div style="font-size: 14px; color: #666;">
                                    No redirects currently being tracked
                                </div>
                            </div>
                            <?php endif; ?>
                        </div>
                    </div>
                </div>
                <!-- Dashboard CTA -->
                <div style="text-align: center; margin: 40px 0 30px 0;">
                    <div style="background: linear-gradient(135deg, #f8f9fa 0%, #ffffff 100%); padding: 25px; border-radius: 12px; border: 2px dashed #F28500;">
                        <h3 style="margin: 0 0 15px 0; color: #0b1d51; font-size: 20px; font-weight: 700;">
                            Ready to Improve Your SEO?
                        </h3>
                        <p style="margin: 0 0 20px 0; color: #666; font-size: 15px; max-width: 500px; margin-left: auto; margin-right: auto;">
                            Access detailed analytics, advanced tools, and personalized recommendations in your dashboard.
                        </p>
                        <a href="<?php echo esc_url($urls['dashboard']); ?>" style="display: inline-block; background-color: #F28500; padding: 10px 16px; text-decoration: none; color:white ; border-radius: 8px solid #F28500; font-weight: bold; font-size: 16px; box-shadow: 0 6px 15px rgba(242, 133, 0, 0.3); transition: all 0.3s ease; border: none; letter-spacing: 0.5px;">
                             Dashboard
                        </a>
                    </div>
                </div>
                <!-- Footer -->
                <div style="text-align: center; padding-top: 25px; border-top: 2px solid #e9ecef; margin-top: 30px;">
                    <p style="margin: 0 0 15px 0; color: #6c757d; font-size: 13px; line-height: 1.5; max-width: 500px; margin-left: auto; margin-right: auto;">
                        You are receiving this email because you have signed up for SEO Repair Kit.<br>
                        This automated SEO report was generated by <strong style="color: #0b1d51;">SEO Repair Kit</strong>
                    </p>
                    <p style="margin: 0; color: #999; font-size: 12px;">
                        © <?php echo date('Y'); ?> SEO Repair Kit. All rights reserved.
                    </p>
                </div>
            </div>
        </div>
        <?php
        return ob_get_clean();
    }

    /**
     * Debug cron status
     */
    public function srk_debug_cron_status() {
        $next_cron = wp_next_scheduled('srk_weekly_seo_summary_cron');
        $schedule = wp_get_schedule('srk_weekly_seo_summary_cron');
        $weekly_report_enabled = get_option('srk_weekly_report_enabled', true);
        
        return array(
            'weekly_report_enabled' => $weekly_report_enabled,
            'next_scheduled' => $next_cron ? date('Y-m-d H:i:s', $next_cron) : 'Not scheduled',
            'schedule' => $schedule ? $schedule : 'No schedule',
            'timestamp' => $next_cron,
            'time_now' => time(),
            'human_readable' => $next_cron ? human_time_diff(time(), $next_cron) . ' from now' : 'N/A'
        );
    }

    /**
     * Force reschedule cron
     */
    public function srk_force_reschedule_cron() {
        return $this->srk_schedule_weekly_cron();
    }
}

// Initialize the class
SeoRepairKit_WeeklySummaryService::get_instance();