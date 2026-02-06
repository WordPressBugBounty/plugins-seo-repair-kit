<?php
if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/**
 * Handles the dedicated Link Scanner admin sub-page.
 * Also includes 404 Monitor functionality in a unified interface.
 *
 * @since 2.1.0
 */
class SeoRepairKit_LinkScanner {

    private $db_404;

    public function __construct() {
        global $wpdb;
        $this->db_404 = $wpdb;

        add_action( 'wp_ajax_get_scan_links_dashboard', array( $this, 'srkit_get_scanlinks_dashboard_callback' ) );
        add_action( 'wp_ajax_nopriv_get_scan_links_dashboard', array( $this, 'srkit_get_scanlinks_dashboard_callback' ) );
        add_action( 'admin_enqueue_scripts', array( $this, 'enqueue_styles_and_scripts' ) );

        // 404 Monitor AJAX actions
        add_action( 'wp_ajax_srk_delete_404', array( $this, 'srk_delete_404' ) );
        add_action( 'wp_ajax_srk_bulk_action_404', array( $this, 'srk_bulk_action_404' ) );
        add_action( 'wp_ajax_srk_clear_404_logs', array( $this, 'srk_clear_404_logs' ) );
        add_action( 'wp_ajax_srk_convert_404_to_redirect', array( $this, 'srk_convert_404_to_redirect' ) );
        add_action( 'wp_ajax_srk_export_404_logs', array( $this, 'srk_export_404_logs' ) );
        add_action( 'wp_ajax_srk_get_404_stats', array( $this, 'srk_get_404_stats' ) );

        // Load notices helper (safe if required multiple times).
        require_once plugin_dir_path( dirname( __FILE__ ) ) . 'includes/class-srk-admin-notices.php';
    }

    /**
     * Enqueue assets required for the Link Scanner UI.
     *
     * @param string $hook Current admin page hook suffix.
     */
    public function enqueue_styles_and_scripts( $hook ) {
        if ( empty( $_GET['page'] ) || 'seo-repair-kit-link-scanner' !== $_GET['page'] ) {
            return;
        }

        // Only load styles needed for Link Scanner page
        // Dashboard style needed because page uses srk-dashboard class structure
        wp_enqueue_style( 'srk-dashboard-style' );
        // Scan Links style - main style for this page
        wp_enqueue_style( 'srk-scan-links-style', plugin_dir_url( __FILE__ ) . 'css/seo-repair-kit-scan-links.css', array(), '2.1.0', 'all' );
        // 404 Manager style needed because 404 Monitor is part of this page
        wp_enqueue_style( 'srk-404-manager-style' );

        wp_enqueue_script(
            'seo-repair-kit-dashboard',
            plugin_dir_url( __FILE__ ) . 'js/seo-repair-kit-dashboard.js',
            array( 'jquery' ),
            '1.0.1',
            true
        );

        // Enqueue 404 manager script
        if ( ! wp_script_is( 'srk-404-manager-script', 'registered' ) ) {
            wp_register_script( 'srk-404-manager-script', plugin_dir_url( __FILE__ ) . 'js/seo-repair-kit-404-manager.js', array( 'jquery' ), '2.1.0', true );
        }
        
        wp_enqueue_script( 'srk-404-manager-script' );

        // Localize scripts for 404 Monitor - must be after enqueue
        wp_localize_script( 'srk-404-manager-script', 'srk404Ajax', array(
            'ajaxurl' => admin_url( 'admin-ajax.php' ),
            'nonce' => wp_create_nonce( 'srk_404_manager_nonce' ),
            'messages' => array(
                'confirm_delete' => esc_html__( 'Are you sure you want to delete this 404 log?', 'seo-repair-kit' ),
                'confirm_clear' => esc_html__( 'Are you sure you want to clear all 404 logs? This cannot be undone.', 'seo-repair-kit' ),
                'confirm_bulk_delete' => esc_html__( 'Are you sure you want to delete selected 404 logs?', 'seo-repair-kit' ),
                'delete_error' => esc_html__( 'Error: Unable to delete 404 log.', 'seo-repair-kit' ),
                'clear_success' => esc_html__( '404 logs cleared successfully.', 'seo-repair-kit' ),
                'convert_success' => esc_html__( 'Redirect created successfully from 404.', 'seo-repair-kit' ),
                'convert_error' => esc_html__( 'Error: Unable to create redirect.', 'seo-repair-kit' ),
                'export_success' => esc_html__( 'Export generated successfully.', 'seo-repair-kit' ),
            ),
        ) );
    }

    /**
     * Render the Link Scanner admin page.
     */
    public function seorepairkit_link_scanner_page() {
        wp_localize_script(
            'seo-repair-kit-dashboard',
            'SeoRepairKitDashboardVars',
            array(
                'ajaxurlsrkdashboard'  => esc_url( admin_url( 'admin-ajax.php' ) ),
                'srkitdashboard_nonce' => wp_create_nonce( 'seorepairkitdashboard_ajaxnonce' ),
            )
        );

        $srkSelectedPostType = get_option( 'td_blc_saved_post_types', array() );
        if ( empty( $srkSelectedPostType ) ) {
            $srkSelectedPostType = array( 'post' );
        }

        $link_snapshot = get_option( 'srk_last_links_snapshot', array() );
        if ( ! is_array( $link_snapshot ) ) {
            $link_snapshot = array();
        }

        $total_links   = isset( $link_snapshot['totalLinks'] ) ? (int) $link_snapshot['totalLinks'] : 0;
        $broken_links  = isset( $link_snapshot['brokenLinks'] ) ? (int) $link_snapshot['brokenLinks'] : 0;
        $working_links = isset( $link_snapshot['workingLinks'] ) ? (int) $link_snapshot['workingLinks'] : max( 0, $total_links - $broken_links );
        $scan_count    = isset( $link_snapshot['scannedCount'] ) ? (int) $link_snapshot['scannedCount'] : 0;
        $last_scan_ts  = isset( $link_snapshot['timestamp'] ) ? (int) $link_snapshot['timestamp'] : 0;
        $now           = current_time( 'timestamp' );

        $last_scan_relative = $last_scan_ts ? human_time_diff( $last_scan_ts, $now ) : '';
        $last_scan_label    = $last_scan_ts ? sprintf( esc_html__( '%s ago', 'seo-repair-kit' ), $last_scan_relative ) : esc_html__( 'No scans yet', 'seo-repair-kit' );

        $broken_percentage  = $total_links > 0 ? round( ( $broken_links / max( $total_links, 1 ) ) * 100, 1 ) : 0;
        $working_percentage = $total_links > 0 ? max( 0, 100 - $broken_percentage ) : 0;

        $links_schedule   = get_option( 'srk_links_schedule', 'manual' );
        $schedule_map     = array(
            'manual'  => esc_html__( 'Manual', 'seo-repair-kit' ),
            'daily'   => esc_html__( 'Daily', 'seo-repair-kit' ),
            'weekly'  => esc_html__( 'Weekly', 'seo-repair-kit' ),
            'monthly' => esc_html__( 'Monthly', 'seo-repair-kit' ),
        );
        $schedule_label   = isset( $schedule_map[ $links_schedule ] ) ? $schedule_map[ $links_schedule ] : esc_html__( 'Manual', 'seo-repair-kit' );
        $automation_active = 'manual' !== $links_schedule;
        $schedule_description = $automation_active
            ? sprintf( esc_html__( 'Runs automatically (%s cadence).', 'seo-repair-kit' ), strtolower( $schedule_label ) )
            : esc_html__( 'Launch scans manually whenever you need a health check.', 'seo-repair-kit' );

        $scan_count_label = $scan_count > 0
            ? sprintf( esc_html__( '%s URLs have been checked so far.', 'seo-repair-kit' ), number_format_i18n( $scan_count ) )
            : esc_html__( 'Run your first scan to capture a baseline.', 'seo-repair-kit' );

        $broken_desc = $broken_links > 0
            ? sprintf(
                esc_html__( '%1$s%% of the scanned links need fixes.', 'seo-repair-kit' ),
                number_format_i18n( $broken_percentage )
            )
            : esc_html__( 'Clean slate! No broken links were found.', 'seo-repair-kit' );

        $redirection_url = admin_url( 'admin.php?page=seo-repair-kit-redirection' );
        ?>

        <div id="srk-dashboard" class="srk-wrap">
            <div class="srk-hero">
                <div class="srk-hero-content">
                    <div class="srk-hero-icon">
                        <span class="dashicons dashicons-admin-links"></span>
                    </div>
                    <div class="srk-hero-text">
                        <h2><?php esc_html_e( 'Link Scanner & 404 Monitor', 'seo-repair-kit' ); ?></h2>
                        <p><?php esc_html_e( 'Keep your site healthy by finding broken links before they hurt your SEO. Monitor 404 errors in real-time and convert them into redirects to preserve search rankings.', 'seo-repair-kit' ); ?></p>
                        <div class="srk-hero-features">
                            <span class="srk-hero-badge">
                                <span class="dashicons dashicons-search"></span>
                                <?php esc_html_e( 'AUTOMATED SCANNING', 'seo-repair-kit' ); ?>
                            </span>
                            <span class="srk-hero-badge">
                                <span class="dashicons dashicons-warning"></span>
                                <?php esc_html_e( '404 TRACKING', 'seo-repair-kit' ); ?>
                            </span>
                            <span class="srk-hero-badge">
                                <span class="dashicons dashicons-migrate"></span>
                                <?php esc_html_e( 'SMART REDIRECTS', 'seo-repair-kit' ); ?>
                            </span>
                        </div>
                    </div>
                </div>
            </div>

            <?php
            if ( function_exists( 'srk_render_notices_after_navbar' ) ) {
                srk_render_notices_after_navbar();
            }
            ?>

            <!-- Tab Navigation -->
            <div class="srk-link-scanner-tabs">
                <nav class="srk-tab-nav">
                    <button type="button" class="srk-tab-button active" data-tab="link-scanner">
                        <span class="dashicons dashicons-search"></span>
                        <?php esc_html_e( 'Link Scanner', 'seo-repair-kit' ); ?>
                    </button>
                    <button type="button" class="srk-tab-button" data-tab="404-monitor">
                        <span class="dashicons dashicons-warning"></span>
                        <?php esc_html_e( '404 Monitor', 'seo-repair-kit' ); ?>
                    </button>
                </nav>
            </div>

            <!-- Link Scanner Tab Content -->
            <div id="srk-tab-link-scanner" class="srk-tab-content active">
                <div class="srk-link-summary-grid">
                    <div class="srk-link-summary-card srk-summary-total">
                        <span class="srk-summary-eyebrow"><?php esc_html_e( 'Total links checked', 'seo-repair-kit' ); ?></span>
                        <div class="srk-summary-value"><?php echo number_format_i18n( $total_links ); ?></div>
                        <p class="srk-summary-subtext"><?php echo esc_html( $scan_count_label ); ?></p>
                    </div>
                    <div class="srk-link-summary-card srk-summary-broken">
                        <span class="srk-summary-eyebrow"><?php esc_html_e( 'Broken links detected', 'seo-repair-kit' ); ?></span>
                        <div class="srk-summary-value">
                            <?php echo number_format_i18n( $broken_links ); ?>
                            <span class="srk-summary-pill"><?php echo esc_html( sprintf( __( '%s%%', 'seo-repair-kit' ), number_format_i18n( $broken_percentage ) ) ); ?></span>
                        </div>
                        <p class="srk-summary-subtext"><?php echo esc_html( $broken_desc ); ?></p>
                    </div>
                    <div class="srk-link-summary-card srk-summary-working">
                        <span class="srk-summary-eyebrow"><?php esc_html_e( 'Healthy links', 'seo-repair-kit' ); ?></span>
                        <div class="srk-summary-value">
                            <?php echo number_format_i18n( $working_links ); ?>
                            <span class="srk-summary-pill srk-pill-success"><?php echo esc_html( sprintf( __( '%s%%', 'seo-repair-kit' ), number_format_i18n( $working_percentage ) ) ); ?></span>
                        </div>
                        <p class="srk-summary-subtext"><?php esc_html_e( 'Working links confirmed in your last scan.', 'seo-repair-kit' ); ?></p>
                    </div>
                    <div class="srk-link-summary-card srk-summary-schedule">
                        <span class="srk-summary-eyebrow"><?php esc_html_e( 'Automation', 'seo-repair-kit' ); ?></span>
                        <div class="srk-summary-value srk-summary-value-inline">
                            <span class="srk-automation-pill <?php echo $automation_active ? 'active' : 'inactive'; ?>">
                                <?php echo esc_html( $schedule_label ); ?>
                            </span>
                        </div>
                        <p class="srk-summary-subtext"><?php echo esc_html( $schedule_description ); ?></p>
                    </div>
                </div>
                <div class="srk-toolbar">
                    <form method="post" action="">
                        <?php wp_nonce_field( 'srkSelectedPostType', 'srkSelectedPostType_nonce' ); ?>
                        <label for="srk-post-type-dropdown" class="srk-post-type-selection">
                            <?php esc_html_e( 'Select Post Type:', 'seo-repair-kit' ); ?>
                        </label>
                        <select id="srk-post-type-dropdown" name="post_type_dropdown">
                            <?php
                            foreach ( $srkSelectedPostType as $srkit_PostType ) {
                                $obj = get_post_type_object( $srkit_PostType );
                                if ( $obj ) {
                                    echo '<option value="' . esc_attr( $srkit_PostType ) . '">' . esc_html( $obj->labels->name ) . '</option>';
                                }
                            }
                            ?>
                        </select>
                        <input type="submit" value="<?php esc_attr_e( 'Start Scan', 'seo-repair-kit' ); ?>" class="srk-dashboard-button" id="start-button" name="start_button">
                    </form>
                    <div class="srk-toolbar-meta">
                        <div class="srk-meta-item">
                            <span class="srk-meta-label"><?php esc_html_e( 'Last scan', 'seo-repair-kit' ); ?></span>
                            <span class="srk-meta-value"><?php echo esc_html( $last_scan_label ); ?></span>
                        </div>
                        <div class="srk-meta-item">
                            <span class="srk-meta-label"><?php esc_html_e( 'Automation', 'seo-repair-kit' ); ?></span>
                            <span class="srk-meta-pill <?php echo $automation_active ? 'active' : 'inactive'; ?>">
                                <?php echo esc_html( $schedule_label ); ?>
                            </span>
                        </div>
                    </div>
                </div>

                <div id="srk-loader-container">
                    <div class="srk-dashboard-loader-container">
                        <div class="srk-dashboard-loader"></div>
                        <p style="margin: 0; color: var(--srk-muted); font-size: 14px;"><?php esc_html_e( 'Scanning links...', 'seo-repair-kit' ); ?></p>
                    </div>
                </div>

                <div class="progress-bar-container">
                    <div class="progress-label">0%</div>
                    <div class="blue-bar"></div>
                </div>

                <div id="scan-results">
                    <div class="srk-card srk-empty">
                        <h3><?php esc_html_e( 'No scan yet!', 'seo-repair-kit' ); ?></h3>
                        <p><?php esc_html_e( 'Pick a post type and click "Start Scan" to begin.', 'seo-repair-kit' ); ?></p>
                    </div>
                </div>
            </div>

            <!-- 404 Monitor Tab Content -->
            <div id="srk-tab-404-monitor" class="srk-tab-content">
                <?php $this->render_404_monitor_content(); ?>
            </div>
        </div>

        <script>
            jQuery(document).ready(function($) {
                // Tab switching with smooth transitions
                $('.srk-tab-button').on('click', function() {
                    var tab = $(this).data('tab');
                    var $button = $(this);
                    var $targetContent = $('#srk-tab-' + tab);
                    
                    // Prevent double-clicking
                    if ($button.hasClass('active') || $button.hasClass('switching')) {
                        return;
                    }
                    
                    $button.addClass('switching');
                    
                    // Fade out current content
                    $('.srk-tab-content.active').fadeOut(200, function() {
                        // Update buttons
                        $('.srk-tab-button').removeClass('active');
                        $button.removeClass('switching').addClass('active');
                        
                        // Update content
                        $('.srk-tab-content').removeClass('active');
                        $targetContent.addClass('active').hide().fadeIn(300);
                    });
                });

                // Handle URL hash for direct tab access
                if (window.location.hash) {
                    var hash = window.location.hash.replace('#', '');
                    if (hash === '404-monitor' || hash === 'link-scanner') {
                        $('.srk-tab-button[data-tab="' + hash + '"]').trigger('click');
                    }
                }

            // Handle tab parameter from URL (for pagination/filtering)
            var urlParams = new URLSearchParams(window.location.search);
            if (urlParams.get('tab') === '404-monitor') {
                $('.srk-tab-button[data-tab="404-monitor"]').trigger('click');
            }
        });
        </script>
        <?php
    }

    /**
     * AJAX callback for performing a scan.
     */
    public function srkit_get_scanlinks_dashboard_callback() {
        check_ajax_referer( 'seorepairkitdashboard_ajaxnonce', 'srkitdashboard_nonce' );
        $srkit_scanDashboard = new SeoRepairKit_ScanLinks();
        $srkit_scanDashboard->seorepairkit_scanning_link();
        wp_die();
    }

    /**
     * Render 404 Monitor content (for tab display)
     *
     * @since 2.1.0
     */
    private function render_404_monitor_content() {
        // Load 404 monitor class
        $monitor_path = plugin_dir_path( dirname( __FILE__ ) ) . '../public/class-seo-repair-kit-404-monitor.php';
        if ( ! file_exists( $monitor_path ) ) {
            // Try alternative path
            $monitor_path = plugin_dir_path( __FILE__ ) . '../../public/class-seo-repair-kit-404-monitor.php';
        }
        if ( file_exists( $monitor_path ) ) {
            require_once $monitor_path;
        } else {
            if ( ! class_exists( 'SeoRepairKit_404_Monitor' ) ) {
                $this->ensure_404_table_exists();
                if ( ! class_exists( 'SeoRepairKit_404_Monitor' ) ) {
                    echo '<div class="notice notice-error"><p>' . esc_html__( 'Error: 404 Monitor class file not found. Please deactivate and reactivate the plugin.', 'seo-repair-kit' ) . '</p></div>';
                    return;
                }
            }
        }

        // Ensure database table exists (this method now caches the check)
        $this->ensure_404_table_exists();

        // Get statistics (this method now caches the results)
        if ( class_exists( 'SeoRepairKit_404_Monitor' ) ) {
            $stats = SeoRepairKit_404_Monitor::get_404_statistics();
        } else {
            $stats = array(
                'total_404s' => 0,
                'unique_urls' => 0,
                'total_hits' => 0,
                'most_hit' => null,
                'recent_404s' => array(),
            );
        }

        // Get 404 logs with pagination
        $table_name = $this->db_404->prefix . 'srkit_404_logs';
        
        // Use cached table existence check instead of querying again
        $table_exists_cache = get_transient( 'srk_404_table_exists' );
        $table_exists = ( false !== $table_exists_cache && (bool) $table_exists_cache );
        
        if ( ! $table_exists ) {
            // Table doesn't exist, try to create it (will update cache)
            $this->ensure_404_table_exists();
            // Re-check cache after creation attempt
            $table_exists_cache = get_transient( 'srk_404_table_exists' );
            $table_exists = ( false !== $table_exists_cache && (bool) $table_exists_cache );
        }
        
        $per_page_raw = isset( $_GET['srk_404_per_page'] ) ? sanitize_text_field( $_GET['srk_404_per_page'] ) : 20;
        $show_all = ( $per_page_raw === 'all' || $per_page_raw === '-1' );

        if ( $show_all ) {
            $per_page = -1; // Special value for "all"
            $current_page = 1;
            $offset = 0;
        } else {
            $per_page = intval( $per_page_raw );
            $per_page = max( 10, min( 200, $per_page ) ); // Limit between 10 and 200
            $current_page = isset( $_GET['srk_404_paged'] ) ? max( 1, intval( $_GET['srk_404_paged'] ) ) : 1;
            $offset = ( $current_page - 1 ) * $per_page;
        }

        // Filters
        $filter_url = isset( $_GET['srk_filter_url'] ) ? sanitize_text_field( $_GET['srk_filter_url'] ) : '';
        $filter_ip = isset( $_GET['srk_filter_ip'] ) ? sanitize_text_field( $_GET['srk_filter_ip'] ) : '';
        $orderby = isset( $_GET['srk_orderby'] ) ? sanitize_text_field( $_GET['srk_orderby'] ) : 'last_accessed';
        $order = isset( $_GET['srk_order'] ) && strtoupper( $_GET['srk_order'] ) === 'ASC' ? 'ASC' : 'DESC';

        // Initialize variables
        $logs = array();
        $total_items = 0;
        $total_pages = 0;

        // Only query if table exists
        if ( $table_exists ) {
            // Build WHERE clause
            $where_clauses = array();
            if ( ! empty( $filter_url ) ) {
                $where_clauses[] = $this->db_404->prepare( "url LIKE %s", '%' . $this->db_404->esc_like( $filter_url ) . '%' );
            }
            if ( ! empty( $filter_ip ) ) {
                $where_clauses[] = $this->db_404->prepare( "ip_address LIKE %s", '%' . $this->db_404->esc_like( $filter_ip ) . '%' );
            }
            $where_sql = ! empty( $where_clauses ) ? 'WHERE ' . implode( ' AND ', $where_clauses ) : '';

            // Get total count
            $total_items = (int) $this->db_404->get_var( "SELECT COUNT(*) FROM $table_name $where_sql" );
            $total_pages = $show_all ? 1 : ( $per_page > 0 ? ceil( $total_items / $per_page ) : 1 );

            // Validate orderby
            $allowed_orderby = array( 'last_accessed', 'first_accessed', 'ip_address' );
            if ( ! in_array( $orderby, $allowed_orderby, true ) ) {
                $orderby = 'last_accessed';
            }

            // Get paginated logs
            if ( $show_all ) {
                $logs = $this->db_404->get_results(
                    "SELECT * FROM $table_name $where_sql ORDER BY $orderby $order"
                );
            } else {
                $logs = $this->db_404->get_results(
                    $this->db_404->prepare(
                        "SELECT * FROM $table_name $where_sql ORDER BY $orderby $order LIMIT %d OFFSET %d",
                        $per_page,
                        $offset
                    )
                );
            }
            
            // Ensure logs is an array
            if ( ! is_array( $logs ) ) {
                $logs = array();
            }
        }

        $redirection_url = admin_url( 'admin.php?page=seo-repair-kit-redirection' );
        ?>
        <!-- Statistics Dashboard -->
        <div class="srk-404-summary-grid">
            <div class="srk-link-summary-card srk-summary-404-total">
                <span class="srk-summary-eyebrow"><?php esc_html_e( 'Total 404 Errors', 'seo-repair-kit' ); ?></span>
                <div class="srk-summary-value"><?php echo number_format_i18n( $stats['total_hits'] ); ?></div>
                <p class="srk-summary-subtext"><?php esc_html_e( 'Total occurrences tracked', 'seo-repair-kit' ); ?></p>
            </div>
            <div class="srk-link-summary-card srk-summary-404-unique">
                <span class="srk-summary-eyebrow"><?php esc_html_e( 'Unique URLs', 'seo-repair-kit' ); ?></span>
                <div class="srk-summary-value"><?php echo number_format_i18n( $stats['unique_urls'] ); ?></div>
                <p class="srk-summary-subtext"><?php esc_html_e( 'Distinct 404 pages found', 'seo-repair-kit' ); ?></p>
            </div>
            <div class="srk-link-summary-card srk-summary-404-logs">
                <span class="srk-summary-eyebrow"><?php esc_html_e( 'Log Entries', 'seo-repair-kit' ); ?></span>
                <div class="srk-summary-value"><?php echo number_format_i18n( $stats['total_404s'] ); ?></div>
                <p class="srk-summary-subtext"><?php esc_html_e( 'Total logged entries', 'seo-repair-kit' ); ?></p>
            </div>
            <div class="srk-link-summary-card srk-summary-404-most">
                <span class="srk-summary-eyebrow"><?php esc_html_e( 'Most Hit 404', 'seo-repair-kit' ); ?></span>
                <div class="srk-summary-value">
                    <?php if ( $stats['most_hit'] ) : ?>
                        <?php echo number_format_i18n( $stats['most_hit']->count ); ?>
                        <span class="srk-summary-pill"><?php esc_html_e( 'Hits', 'seo-repair-kit' ); ?></span>
                    <?php else : ?>
                        0
                    <?php endif; ?>
                </div>
                <p class="srk-summary-subtext">
                    <?php if ( $stats['most_hit'] ) : ?>
                        <?php echo esc_html( substr( $stats['most_hit']->url, 0, 50 ) . ( strlen( $stats['most_hit']->url ) > 50 ? '...' : '' ) ); ?>
                    <?php else : ?>
                        <?php esc_html_e( 'No data yet', 'seo-repair-kit' ); ?>
                    <?php endif; ?>
                </p>
            </div>
        </div>

        <!-- Filters and Actions -->
        <div class="srk-toolbar">
            <form method="get" action="" class="srk-filter-form">
                <input type="hidden" name="page" value="seo-repair-kit-link-scanner" />
                <input type="hidden" name="tab" value="404-monitor" />
                <input type="hidden" name="srk_filter_submitted" value="1" />
                
                <div class="srk-filter-controls">
                    <div class="srk-filter-group srk-filter-group-url">
                        <label for="srk_filter_url" class="srk-filter-label">
                            <span class="dashicons dashicons-admin-links"></span>
                            <?php esc_html_e( 'Filter by URL', 'seo-repair-kit' ); ?>
                        </label>
                        <input type="text" id="srk_filter_url" name="srk_filter_url" value="<?php echo esc_attr( $filter_url ); ?>" placeholder="<?php esc_attr_e( 'Search URL...', 'seo-repair-kit' ); ?>" class="srk-filter-input" />
                    </div>
                    
                    <div class="srk-filter-group srk-filter-group-ip">
                        <label for="srk_filter_ip" class="srk-filter-label">
                            <span class="dashicons dashicons-admin-network"></span>
                            <?php esc_html_e( 'Filter by IP', 'seo-repair-kit' ); ?>
                        </label>
                        <input type="text" id="srk_filter_ip" name="srk_filter_ip" value="<?php echo esc_attr( $filter_ip ); ?>" placeholder="<?php esc_attr_e( 'Search IP...', 'seo-repair-kit' ); ?>" class="srk-filter-input" />
                    </div>

                    <div class="srk-filter-group">
                        <label for="srk_orderby" class="srk-filter-label">
                            <span class="dashicons dashicons-sort"></span>
                            <?php esc_html_e( 'Sort by', 'seo-repair-kit' ); ?>
                        </label>
                        <div class="srk-filter-selects">
                            <select id="srk_orderby" name="srk_orderby" class="srk-filter-select">
                                <option value="last_accessed" <?php selected( $orderby, 'last_accessed' ); ?>><?php esc_html_e( 'Last Accessed', 'seo-repair-kit' ); ?></option>
                                <option value="first_accessed" <?php selected( $orderby, 'first_accessed' ); ?>><?php esc_html_e( 'First Accessed', 'seo-repair-kit' ); ?></option>
                            </select>
                            <select name="srk_order" class="srk-filter-select">
                                <option value="DESC" <?php selected( $order, 'DESC' ); ?>><?php esc_html_e( 'Desc', 'seo-repair-kit' ); ?></option>
                                <option value="ASC" <?php selected( $order, 'ASC' ); ?>><?php esc_html_e( 'Asc', 'seo-repair-kit' ); ?></option>
                            </select>
                        </div>
                    </div>

                    <div class="srk-filter-actions">
                        <button type="submit" class="srk-dashboard-button"><?php esc_html_e( 'Apply Filters', 'seo-repair-kit' ); ?></button>
                        <a href="<?php echo esc_url( admin_url( 'admin.php?page=seo-repair-kit-link-scanner#404-monitor' ) ); ?>" class="srk-button-secondary"><?php esc_html_e( 'Reset', 'seo-repair-kit' ); ?></a>
                    </div>
                </div>
            </form>
        </div>

        <!-- Bulk Actions -->
        <div class="srk-404-bulk-actions">
            <div class="srk-bulk-controls">
                <select id="srk_bulk_action_404" class="srk-bulk-select">
                    <option value=""><?php esc_html_e( 'Bulk Actions', 'seo-repair-kit' ); ?></option>
                    <option value="delete"><?php esc_html_e( 'Delete Selected', 'seo-repair-kit' ); ?></option>
                    <option value="ignore"><?php esc_html_e( 'Ignore Selected', 'seo-repair-kit' ); ?></option>
                </select>
                <button type="button" class="srk-dashboard-button" id="srk_apply_bulk_404"><?php esc_html_e( 'Apply', 'seo-repair-kit' ); ?></button>
            </div>
            <div class="srk-action-buttons-group">
                <button type="button" class="srk-button-secondary" id="srk_clear_all_404">
                    <span class="dashicons dashicons-dismiss"></span>
                    <?php esc_html_e( 'Clear All Logs', 'seo-repair-kit' ); ?>
                </button>
                <button type="button" class="srk-button-secondary" id="srk_export_404">
                    <span class="dashicons dashicons-download"></span>
                    <?php esc_html_e( 'Export CSV', 'seo-repair-kit' ); ?>
                </button>
                <button type="button" class="srk-button-secondary" id="srk_refresh_stats">
                    <span class="dashicons dashicons-update"></span>
                    <?php esc_html_e( 'Refresh Stats', 'seo-repair-kit' ); ?>
                </button>
            </div>
        </div>

        <!-- 404 Logs Table -->
        <div class="srk-404-logs-table">
            <?php if ( ! empty( $logs ) || $total_items > 0 ) : ?>
                <div class="srk-table-container">
                    <div class="srk-table-header">
                        <div class="srk-table-title">
                            <span class="dashicons dashicons-warning"></span>
                            <h3><?php esc_html_e( '404 Error Logs', 'seo-repair-kit' ); ?></h3>
                        </div>
                        <span class="srk-table-count"><?php echo esc_html( sprintf( _n( '%s entry', '%s entries', $total_items, 'seo-repair-kit' ), number_format_i18n( $total_items ) ) ); ?></span>
                    </div>
                    <div class="srk-table-scroll">
                        <table class="srk-404-table">
                            <thead>
                                <tr>
                                    <th class="srk-col-check"><input type="checkbox" id="srk-select-all-404" /></th>
                                    <th class="srk-col-url"><?php esc_html_e( 'URL', 'seo-repair-kit' ); ?></th>
                                    <th class="srk-col-count"><?php esc_html_e( 'Hits', 'seo-repair-kit' ); ?></th>
                                    <th class="srk-col-ip"><?php esc_html_e( 'IP Address', 'seo-repair-kit' ); ?></th>
                                    <th class="srk-col-agent"><?php esc_html_e( 'User Agent', 'seo-repair-kit' ); ?></th>
                                    <th class="srk-col-date"><?php esc_html_e( 'First Seen', 'seo-repair-kit' ); ?></th>
                                    <th class="srk-col-date"><?php esc_html_e( 'Last Seen', 'seo-repair-kit' ); ?></th>
                                    <th class="srk-col-actions"><?php esc_html_e( 'Actions', 'seo-repair-kit' ); ?></th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php if ( ! empty( $logs ) ) : ?>
                                    <?php foreach ( $logs as $log ) : ?>
                                        <tr data-log-id="<?php echo esc_attr( $log->id ); ?>">
                                            <td class="srk-col-check">
                                                <input type="checkbox" class="srk-404-checkbox" value="<?php echo esc_attr( $log->id ); ?>" />
                                            </td>
                                            <td class="srk-col-url">
                                                <div class="srk-url-cell">
                                                    <code><?php echo esc_html( $log->url ); ?></code>
                                                    <a href="<?php echo esc_url( home_url( $log->url ) ); ?>" target="_blank" class="srk-url-external" title="<?php esc_attr_e( 'Open URL', 'seo-repair-kit' ); ?>">
                                                        <span class="dashicons dashicons-external"></span>
                                                    </a>
                                                </div>
                                            </td>
                                            <td class="srk-col-count">
                                                <span class="srk-badge-count <?php echo $log->count >= 10 ? 'srk-badge-high' : ( $log->count >= 5 ? 'srk-badge-medium' : '' ); ?>">
                                                    <?php echo number_format_i18n( $log->count ); ?>
                                                </span>
                                            </td>
                                            <td class="srk-col-ip">
                                                <span class="srk-ip-address"><?php echo esc_html( $log->ip_address ?: '—' ); ?></span>
                                            </td>
                                            <td class="srk-col-agent">
                                                <?php if ( ! empty( $log->user_agent ) ) : ?>
                                                    <span class="srk-user-agent" title="<?php echo esc_attr( $log->user_agent ); ?>">
                                                        <?php echo esc_html( substr( $log->user_agent, 0, 30 ) . ( strlen( $log->user_agent ) > 30 ? '...' : '' ) ); ?>
                                                    </span>
                                                <?php else : ?>
                                                    <span class="srk-text-muted">—</span>
                                                <?php endif; ?>
                                            </td>
                                            <td class="srk-col-date">
                                                <span class="srk-date"><?php echo esc_html( date_i18n( 'M j, Y', strtotime( $log->first_accessed ) ) ); ?></span>
                                                <span class="srk-time"><?php echo esc_html( date_i18n( 'g:i a', strtotime( $log->first_accessed ) ) ); ?></span>
                                            </td>
                                            <td class="srk-col-date">
                                                <span class="srk-date"><?php echo esc_html( date_i18n( 'M j, Y', strtotime( $log->last_accessed ) ) ); ?></span>
                                                <span class="srk-time"><?php echo esc_html( date_i18n( 'g:i a', strtotime( $log->last_accessed ) ) ); ?></span>
                                            </td>
                                            <td class="srk-col-actions">
                                                <div class="srk-row-actions">
                                                    <a href="<?php echo esc_url( admin_url( 'admin.php?page=seo-repair-kit-redirection&source_url=' . urlencode( $log->url ) ) ); ?>" class="srk-action-btn srk-btn-redirect" target="_blank" title="<?php esc_attr_e( 'Create Redirect', 'seo-repair-kit' ); ?>">
                                                        <span class="dashicons dashicons-migrate"></span>
                                                    </a>
                                                </div>
                                            </td>
                                        </tr>
                                    <?php endforeach; ?>
                                <?php else : ?>
                                    <tr class="srk-empty-row">
                                        <td colspan="8">
                                            <div class="srk-table-empty">
                                                <span class="dashicons dashicons-warning"></span>
                                                <p class="srk-empty-title"><?php esc_html_e( 'No 404 errors found', 'seo-repair-kit' ); ?></p>
                                                <p class="srk-empty-desc"><?php esc_html_e( 'Try adjusting your filters or check back later.', 'seo-repair-kit' ); ?></p>
                                            </div>
                                        </td>
                                    </tr>
                                <?php endif; ?>
                    </tbody>
                </table>
                </div>

                <!-- Pagination -->
                <?php if ( ! empty( $logs ) || $total_items > 0 ) : ?>
                    <div class="srk-pagination-wrapper">
                        <div class="srk-pagination-info">
                            <?php
                            if ( $show_all ) {
                                printf(
                                    esc_html__( 'Showing all %1$d log entries', 'seo-repair-kit' ),
                                    $total_items
                                );
                            } else {
                                $start = $offset + 1;
                                $end = min( $offset + $per_page, $total_items );
                                printf(
                                    esc_html__( 'Showing %1$d to %2$d of %3$d log entries', 'seo-repair-kit' ),
                                    $start,
                                    $end,
                                    $total_items
                                );
                            }
                            ?>
                        </div>
                        
                        <?php if ( ! $show_all && $total_pages > 1 ) : ?>
                        <div class="srk-pagination">
                            <?php
                            // Build pagination base URL
                            $base_url = remove_query_arg( array( 'srk_404_paged', 'srk_404_per_page', 'srk_paged', 'srk_per_page' ) );
                            $base_url = add_query_arg( 'page', 'seo-repair-kit-link-scanner', $base_url );
                            $base_url = add_query_arg( 'tab', '404-monitor', $base_url );
                            
                            // Add filter parameters
                            if ( ! empty( $filter_url ) ) {
                                $base_url = add_query_arg( 'srk_filter_url', $filter_url, $base_url );
                            }
                            if ( ! empty( $filter_ip ) ) {
                                $base_url = add_query_arg( 'srk_filter_ip', $filter_ip, $base_url );
                            }
                            $base_url = add_query_arg( 'srk_orderby', $orderby, $base_url );
                            $base_url = add_query_arg( 'srk_order', $order, $base_url );
                            $base_url = add_query_arg( 'srk_404_per_page', $per_page_raw, $base_url );
                            
                            // Previous button
                            if ( $current_page > 1 ) :
                                $prev_url = add_query_arg( 'srk_404_paged', $current_page - 1, $base_url );
                                ?>
                                <a href="<?php echo esc_url( $prev_url ); ?>#404-monitor" class="srk-pagination-link srk-pagination-prev" title="<?php esc_attr_e( 'Previous page', 'seo-repair-kit' ); ?>">
                                    <span class="srk-pagination-arrow">‹</span>
                                    <?php esc_html_e( 'Previous', 'seo-repair-kit' ); ?>
                                </a>
                            <?php else : ?>
                                <span class="srk-pagination-link srk-pagination-disabled">
                                    <span class="srk-pagination-arrow">‹</span>
                                    <?php esc_html_e( 'Previous', 'seo-repair-kit' ); ?>
                                </span>
                            <?php endif; ?>
                            
                            <div class="srk-pagination-pages">
                                <?php
                                // Calculate page range to show
                                $range = 2;
                                
                                // Show first page
                                if ( $current_page > $range + 1 ) :
                                    $first_url = add_query_arg( 'srk_404_paged', 1, $base_url );
                                    ?>
                                    <a href="<?php echo esc_url( $first_url ); ?>#404-monitor" class="srk-pagination-page">1</a>
                                    <?php if ( $current_page > $range + 2 ) : ?>
                                        <span class="srk-pagination-dots">...</span>
                                    <?php endif; ?>
                                <?php endif; ?>
                                
                                <?php
                                // Show page numbers around current page
                                for ( $i = max( 1, $current_page - $range ); $i <= min( $total_pages, $current_page + $range ); $i++ ) {
                                    if ( $i === $current_page ) {
                                        echo '<span class="srk-pagination-page srk-pagination-current">' . esc_html( $i ) . '</span>';
                                    } else {
                                        $page_url = add_query_arg( 'srk_404_paged', $i, $base_url );
                                        echo '<a href="' . esc_url( $page_url ) . '#404-monitor" class="srk-pagination-page">' . esc_html( $i ) . '</a>';
                                    }
                                }
                                ?>
                                
                                <?php
                                // Show last page
                                if ( $current_page < $total_pages - $range ) :
                                    if ( $current_page < $total_pages - $range - 1 ) :
                                        ?>
                                        <span class="srk-pagination-dots">...</span>
                                    <?php endif;
                                    $last_url = add_query_arg( 'srk_404_paged', $total_pages, $base_url );
                                    ?>
                                    <a href="<?php echo esc_url( $last_url ); ?>#404-monitor" class="srk-pagination-page"><?php echo esc_html( $total_pages ); ?></a>
                                <?php endif; ?>
                            </div>
                            
                            <?php
                            // Next button
                            if ( $current_page < $total_pages ) :
                                $next_url = add_query_arg( 'srk_404_paged', $current_page + 1, $base_url );
                                ?>
                                <a href="<?php echo esc_url( $next_url ); ?>#404-monitor" class="srk-pagination-link srk-pagination-next" title="<?php esc_attr_e( 'Next page', 'seo-repair-kit' ); ?>">
                                    <?php esc_html_e( 'Next', 'seo-repair-kit' ); ?>
                                    <span class="srk-pagination-arrow">›</span>
                                </a>
                            <?php else : ?>
                                <span class="srk-pagination-link srk-pagination-disabled">
                                    <?php esc_html_e( 'Next', 'seo-repair-kit' ); ?>
                                    <span class="srk-pagination-arrow">›</span>
                                </span>
                            <?php endif; ?>
                        </div>
                        <?php endif; ?>
                        
                        <div class="srk-pagination-per-page">
                            <label for="srk_404_per_page_select"><?php esc_html_e( 'Per page:', 'seo-repair-kit' ); ?></label>
                            <select id="srk_404_per_page_select" class="srk-per-page-select">
                                <option value="10" <?php selected( $per_page_raw, '10' ); ?>>10</option>
                                <option value="20" <?php selected( $per_page_raw, '20' ); ?>>20</option>
                                <option value="25" <?php selected( $per_page_raw, '25' ); ?>>25</option>
                                <option value="50" <?php selected( $per_page_raw, '50' ); ?>>50</option>
                                <option value="100" <?php selected( $per_page_raw, '100' ); ?>>100</option>
                                <option value="200" <?php selected( $per_page_raw, '200' ); ?>>200</option>
                                <option value="all" <?php selected( $show_all, true ); ?>><?php esc_html_e( 'All', 'seo-repair-kit' ); ?></option>
                            </select>
                        </div>
                    </div>
                <?php endif; ?>
            <?php else : ?>
                <div class="srk-card srk-empty">
                    <h3><?php esc_html_e( 'No 404 Errors Yet', 'seo-repair-kit' ); ?></h3>
                    <p><?php esc_html_e( '404 error monitoring is active. Any 404 errors will be logged here automatically.', 'seo-repair-kit' ); ?></p>
                </div>
            <?php endif; ?>
        </div>

        <?php
    }

    /**
     * AJAX: Delete 404 log entry
     *
     * @since 2.1.0
     */
    public function srk_delete_404() {
        // Check if AJAX request
        if ( ! wp_doing_ajax() ) {
            wp_send_json_error( array( 'message' => __( 'Invalid request.', 'seo-repair-kit' ) ) );
        }

        if ( ! current_user_can( 'manage_options' ) ) {
            wp_send_json_error( array( 'message' => __( 'Permission denied.', 'seo-repair-kit' ) ) );
        }

        // Verify nonce - don't sanitize as it can break the nonce
        $nonce = isset( $_POST['nonce'] ) ? $_POST['nonce'] : '';
        if ( empty( $nonce ) ) {
            wp_send_json_error( array( 'message' => __( 'Security token missing. Please refresh the page and try again.', 'seo-repair-kit' ) ) );
        }

        $nonce_check = wp_verify_nonce( $nonce, 'srk_404_manager_nonce' );
        if ( $nonce_check === false ) {
            wp_send_json_error( array( 'message' => __( 'Security check failed. Please refresh the page and try again.', 'seo-repair-kit' ) ) );
        }

        $log_id = isset( $_POST['log_id'] ) ? intval( $_POST['log_id'] ) : 0;
        if ( ! $log_id ) {
            wp_send_json_error( array( 'message' => __( 'Invalid 404 log ID.', 'seo-repair-kit' ) ) );
        }

        // Ensure table exists
        $this->ensure_404_table_exists();

        $table_name = $this->db_404->prefix . 'srkit_404_logs';
        
        // Check if table exists
        if ( $this->db_404->get_var( "SHOW TABLES LIKE '$table_name'" ) !== $table_name ) {
            wp_send_json_error( array( 'message' => __( '404 logs table does not exist.', 'seo-repair-kit' ) ) );
        }
        
        $result = $this->db_404->delete(
            $table_name,
            array( 'id' => $log_id ),
            array( '%d' )
        );

        if ( $result !== false ) {
            // Clear cached 404 statistics after data modification
            if ( class_exists( 'SeoRepairKit_404_Monitor' ) ) {
                SeoRepairKit_404_Monitor::clear_404_statistics_cache();
            }
            
            wp_send_json_success( array( 'message' => __( '404 log deleted successfully.', 'seo-repair-kit' ) ) );
        } else {
            wp_send_json_error( array( 'message' => __( 'Failed to delete 404 log.', 'seo-repair-kit' ) ) );
        }
    }

    /**
     * AJAX: Bulk action on 404 logs
     *
     * @since 2.1.0
     */
    public function srk_bulk_action_404() {
        // Check if AJAX request
        if ( ! wp_doing_ajax() ) {
            wp_send_json_error( array( 'message' => __( 'Invalid request.', 'seo-repair-kit' ) ) );
        }

        if ( ! current_user_can( 'manage_options' ) ) {
            wp_send_json_error( array( 'message' => __( 'Permission denied.', 'seo-repair-kit' ) ) );
        }

        // Verify nonce - don't sanitize as it can break the nonce
        $nonce = isset( $_POST['nonce'] ) ? $_POST['nonce'] : '';
        if ( empty( $nonce ) ) {
            wp_send_json_error( array( 'message' => __( 'Security token missing. Please refresh the page and try again.', 'seo-repair-kit' ) ) );
        }

        $nonce_check = wp_verify_nonce( $nonce, 'srk_404_manager_nonce' );
        if ( $nonce_check === false ) {
            wp_send_json_error( array( 'message' => __( 'Security check failed. Please refresh the page and try again.', 'seo-repair-kit' ) ) );
        }

        $action = isset( $_POST['bulk_action'] ) ? sanitize_text_field( $_POST['bulk_action'] ) : '';
        $log_ids = isset( $_POST['log_ids'] ) && is_array( $_POST['log_ids'] ) ? array_map( 'intval', $_POST['log_ids'] ) : array();

        if ( empty( $action ) || empty( $log_ids ) ) {
            wp_send_json_error( array( 'message' => __( 'Invalid action or IDs.', 'seo-repair-kit' ) ) );
        }

        // Ensure table exists
        $this->ensure_404_table_exists();

        $table_name = $this->db_404->prefix . 'srkit_404_logs';
        
        // Check if table exists
        if ( $this->db_404->get_var( "SHOW TABLES LIKE '$table_name'" ) !== $table_name ) {
            wp_send_json_error( array( 'message' => __( '404 logs table does not exist.', 'seo-repair-kit' ) ) );
        }
        
        // Prepare IDs for IN clause - sanitize and ensure they're integers
        $log_ids = array_map( 'intval', $log_ids );
        $log_ids = array_filter( $log_ids ); // Remove any invalid IDs
        
        if ( empty( $log_ids ) ) {
            wp_send_json_error( array( 'message' => __( 'Invalid log IDs.', 'seo-repair-kit' ) ) );
        }
        
        $ids_placeholder = implode( ',', array_fill( 0, count( $log_ids ), '%d' ) );

        if ( $action === 'delete' ) {
            // Use direct query for IN clause to avoid prepare() issues with array spreading
            $result = $this->db_404->query(
                $this->db_404->prepare(
                    "DELETE FROM $table_name WHERE id IN ($ids_placeholder)",
                    ...$log_ids
                )
            );

            if ( $result !== false && $result > 0 ) {
                // Clear cached 404 statistics after data modification
                if ( class_exists( 'SeoRepairKit_404_Monitor' ) ) {
                    SeoRepairKit_404_Monitor::clear_404_statistics_cache();
                }
                
                wp_send_json_success( array( 
                    'message' => sprintf( _n( '%d 404 log deleted.', '%d 404 logs deleted.', $result, 'seo-repair-kit' ), $result ),
                    'deleted' => $result
                ) );
            } else {
                $error = $this->db_404->last_error;
                wp_send_json_error( array( 
                    'message' => __( 'Failed to delete 404 logs.', 'seo-repair-kit' ) . ( $error ? ' ' . $error : '' )
                ) );
            }
        } elseif ( $action === 'ignore' ) {
            // For now, ignore means delete (can be enhanced later)
            $result = $this->db_404->query(
                $this->db_404->prepare(
                    "DELETE FROM $table_name WHERE id IN ($ids_placeholder)",
                    ...$log_ids
                )
            );

            if ( $result !== false && $result > 0 ) {
                wp_send_json_success( array( 
                    'message' => sprintf( _n( '%d 404 log ignored.', '%d 404 logs ignored.', $result, 'seo-repair-kit' ), $result ),
                    'ignored' => $result
                ) );
            } else {
                $error = $this->db_404->last_error;
                wp_send_json_error( array( 
                    'message' => __( 'Failed to ignore 404 logs.', 'seo-repair-kit' ) . ( $error ? ' ' . $error : '' )
                ) );
            }
        } else {
            wp_send_json_error( array( 'message' => __( 'Invalid action.', 'seo-repair-kit' ) ) );
        }
    }

    /**
     * AJAX: Clear all 404 logs
     *
     * @since 2.1.0
     */
    public function srk_clear_404_logs() {
        // Check if AJAX request
        if ( ! wp_doing_ajax() ) {
            wp_send_json_error( array( 'message' => __( 'Invalid request.', 'seo-repair-kit' ) ) );
        }

        if ( ! current_user_can( 'manage_options' ) ) {
            wp_send_json_error( array( 'message' => __( 'Permission denied.', 'seo-repair-kit' ) ) );
        }

        // Verify nonce - don't sanitize as it can break the nonce
        $nonce = isset( $_POST['nonce'] ) ? $_POST['nonce'] : '';
        if ( empty( $nonce ) ) {
            wp_send_json_error( array( 'message' => __( 'Security token missing. Please refresh the page and try again.', 'seo-repair-kit' ) ) );
        }

        $nonce_check = wp_verify_nonce( $nonce, 'srk_404_manager_nonce' );
        if ( $nonce_check === false ) {
            wp_send_json_error( array( 'message' => __( 'Security check failed. Please refresh the page and try again.', 'seo-repair-kit' ) ) );
        }

        // Ensure table exists
        $this->ensure_404_table_exists();

        $days = isset( $_POST['days'] ) ? intval( $_POST['days'] ) : 0;
        
        $table_name = $this->db_404->prefix . 'srkit_404_logs';
        
        // Check if table exists
        if ( $this->db_404->get_var( "SHOW TABLES LIKE '$table_name'" ) !== $table_name ) {
            wp_send_json_error( array( 'message' => __( '404 logs table does not exist.', 'seo-repair-kit' ) ) );
        }
        
        $deleted = 0;
        
        if ( $days > 0 ) {
            // Delete logs older than specified days
            $date_threshold = date( 'Y-m-d H:i:s', strtotime( "-$days days" ) );
            $result = $this->db_404->query(
                $this->db_404->prepare(
                    "DELETE FROM $table_name WHERE last_accessed < %s",
                    $date_threshold
                )
            );
            
            if ( $result !== false ) {
                $deleted = (int) $result;
            } else {
                $error = $this->db_404->last_error;
                wp_send_json_error( array( 
                    'message' => __( 'Failed to clear 404 logs.', 'seo-repair-kit' ) . ( $error ? ' ' . $error : '' )
                ) );
            }
        } else {
            // Delete all logs
            // First, count how many will be deleted
            $count = (int) $this->db_404->get_var( "SELECT COUNT(*) FROM $table_name" );
            
            // Truncate table (faster than DELETE for all records)
            $result = $this->db_404->query( "TRUNCATE TABLE $table_name" );
            
            if ( $result !== false ) {
                $deleted = $count;
            } else {
                $error = $this->db_404->last_error;
                wp_send_json_error( array( 
                    'message' => __( 'Failed to clear 404 logs.', 'seo-repair-kit' ) . ( $error ? ' ' . $error : '' )
                ) );
            }
        }
        
        // Clear cached 404 statistics after data modification
        if ( $deleted > 0 && class_exists( 'SeoRepairKit_404_Monitor' ) ) {
            SeoRepairKit_404_Monitor::clear_404_statistics_cache();
        }

        wp_send_json_success( array( 
            'message' => sprintf( _n( '%d log entry cleared.', '%d log entries cleared.', $deleted, 'seo-repair-kit' ), $deleted ),
            'deleted' => $deleted
        ) );
    }

    /**
     * AJAX: Convert 404 to redirect
     *
     * @since 2.1.0
     */
    public function srk_convert_404_to_redirect() {
        // Check if AJAX request
        if ( ! wp_doing_ajax() ) {
            wp_send_json_error( array( 'message' => __( 'Invalid request.', 'seo-repair-kit' ) ) );
        }

        if ( ! current_user_can( 'manage_options' ) ) {
            wp_send_json_error( array( 'message' => __( 'Permission denied.', 'seo-repair-kit' ) ) );
        }

        // Verify nonce - don't sanitize as it can break the nonce
        $nonce = isset( $_POST['nonce'] ) ? $_POST['nonce'] : '';
        if ( empty( $nonce ) ) {
            wp_send_json_error( array( 'message' => __( 'Security token missing. Please refresh the page and try again.', 'seo-repair-kit' ) ) );
        }

        $nonce_check = wp_verify_nonce( $nonce, 'srk_404_manager_nonce' );
        if ( $nonce_check === false ) {
            wp_send_json_error( array( 'message' => __( 'Security check failed. Please refresh the page and try again.', 'seo-repair-kit' ) ) );
        }

        $source_url = isset( $_POST['source_url'] ) ? trim( sanitize_text_field( $_POST['source_url'] ) ) : '';
        $target_url = isset( $_POST['target_url'] ) ? trim( sanitize_text_field( $_POST['target_url'] ) ) : '';
        $redirect_type = isset( $_POST['redirect_type'] ) ? intval( $_POST['redirect_type'] ) : 301;
        $delete_404 = isset( $_POST['delete_404'] ) && ( $_POST['delete_404'] === 'true' || $_POST['delete_404'] === '1' );
        $log_id = isset( $_POST['log_id'] ) ? intval( $_POST['log_id'] ) : 0;

        if ( empty( $source_url ) || empty( $target_url ) ) {
            wp_send_json_error( array( 'message' => __( 'Source URL and Target URL are required.', 'seo-repair-kit' ) ) );
        }

        // Validate redirect type
        $allowed_types = array( 301, 302, 303, 304, 307, 308, 410 );
        if ( ! in_array( $redirect_type, $allowed_types, true ) ) {
            $redirect_type = 301;
        }

        // Create redirect using redirection class
        $redirections_table = $this->db_404->prefix . 'srkit_redirection_table';
        
        // Check if redirect already exists
        $existing = $this->db_404->get_var(
            $this->db_404->prepare(
                "SELECT id FROM $redirections_table WHERE source_url = %s",
                $source_url
            )
        );

        if ( $existing ) {
            wp_send_json_error( array( 'message' => __( 'A redirect for this URL already exists.', 'seo-repair-kit' ) ) );
        }

        // Insert new redirect
        $result = $this->db_404->insert(
            $redirections_table,
            array(
                'source_url' => $source_url,
                'target_url' => $target_url,
                'redirect_type' => $redirect_type,
                'status' => 'active',
                'is_regex' => 0,
                'position' => 0,
                'hits' => 0,
                'created_at' => current_time( 'mysql' ),
                'updated_at' => current_time( 'mysql' ),
            ),
            array( '%s', '%s', '%d', '%s', '%d', '%d', '%d', '%s', '%s' )
        );

        if ( $result !== false ) {
            $redirect_id = $this->db_404->insert_id;

            // Delete 404 log if requested
            if ( $delete_404 && $log_id > 0 ) {
                // Ensure table exists before deleting
                $this->ensure_404_table_exists();
                $log_table_name = $this->db_404->prefix . 'srkit_404_logs';
                if ( $this->db_404->get_var( "SHOW TABLES LIKE '$log_table_name'" ) === $log_table_name ) {
                    $this->db_404->delete(
                        $log_table_name,
                        array( 'id' => $log_id ),
                        array( '%d' )
                    );
                }
            }

            // Refresh .htaccess rules if enabled
            $redirection = new SeoRepairKit_Redirection();
            $redirection_reflection = new ReflectionClass( $redirection );
            $refresh_method = $redirection_reflection->getMethod( 'refresh_server_rules' );
            $refresh_method->setAccessible( true );
            $refresh_method->invoke( $redirection, true );

            wp_send_json_success( array( 
                'message' => __( 'Redirect created successfully.', 'seo-repair-kit' ),
                'redirect_id' => $redirect_id,
                'redirect_url' => admin_url( 'admin.php?page=seo-repair-kit-redirection' )
            ) );
        } else {
            wp_send_json_error( array( 'message' => __( 'Failed to create redirect.', 'seo-repair-kit' ) ) );
        }
    }

    /**
     * AJAX: Export 404 logs to CSV
     *
     * @since 2.1.0
     */
    public function srk_export_404_logs() {
        // Check if AJAX request
        if ( ! wp_doing_ajax() ) {
            wp_send_json_error( array( 'message' => __( 'Invalid request.', 'seo-repair-kit' ) ) );
        }

        if ( ! current_user_can( 'manage_options' ) ) {
            wp_send_json_error( array( 'message' => __( 'Permission denied.', 'seo-repair-kit' ) ) );
        }

        // Verify nonce - don't sanitize as it can break the nonce
        $nonce = isset( $_POST['nonce'] ) ? $_POST['nonce'] : '';
        if ( empty( $nonce ) ) {
            wp_send_json_error( array( 'message' => __( 'Security token missing. Please refresh the page and try again.', 'seo-repair-kit' ) ) );
        }

        $nonce_check = wp_verify_nonce( $nonce, 'srk_404_manager_nonce' );
        if ( $nonce_check === false ) {
            wp_send_json_error( array( 'message' => __( 'Security check failed. Please refresh the page and try again.', 'seo-repair-kit' ) ) );
        }

        // Ensure table exists
        $this->ensure_404_table_exists();

        $table_name = $this->db_404->prefix . 'srkit_404_logs';
        
        // Check if table exists
        if ( $this->db_404->get_var( "SHOW TABLES LIKE '$table_name'" ) !== $table_name ) {
            wp_send_json_error( array( 'message' => __( '404 logs table does not exist.', 'seo-repair-kit' ) ) );
        }
        
        $logs = $this->db_404->get_results(
            "SELECT url, count, referrer, ip_address, user_agent, method, first_accessed, last_accessed FROM $table_name ORDER BY last_accessed DESC",
            ARRAY_A
        );

        if ( empty( $logs ) || ! is_array( $logs ) ) {
            wp_send_json_error( array( 'message' => __( 'No 404 logs to export.', 'seo-repair-kit' ) ) );
        }

        // Generate CSV content
        ob_start();
        $output = fopen( 'php://output', 'w' );

        // Add BOM for Excel compatibility
        fprintf( $output, chr( 0xEF ) . chr( 0xBB ) . chr( 0xBF ) );

        // Headers
        $headers = array(
            'URL',
            'Hit Count',
            'Referrer',
            'IP Address',
            'User Agent',
            'Method',
            'First Accessed',
            'Last Accessed',
        );
        fputcsv( $output, $headers );

        // Data rows
        foreach ( $logs as $log ) {
            fputcsv( $output, array(
                $log['url'],
                $log['count'],
                $log['referrer'],
                $log['ip_address'],
                $log['user_agent'],
                $log['method'],
                $log['first_accessed'],
                $log['last_accessed'],
            ) );
        }

        fclose( $output );
        $csv_content = ob_get_clean();

        // Return as base64 encoded for download
        wp_send_json_success( array(
            'file_content' => base64_encode( $csv_content ),
            'filename' => 'srk-404-logs-' . date( 'Y-m-d-H-i-s' ) . '.csv',
            'format' => 'csv',
        ) );
    }

    /**
     * AJAX: Get 404 statistics
     *
     * @since 2.1.0
     */
    public function srk_get_404_stats() {
        // Check if AJAX request
        if ( ! wp_doing_ajax() ) {
            wp_send_json_error( array( 'message' => __( 'Invalid request.', 'seo-repair-kit' ) ) );
        }

        if ( ! current_user_can( 'manage_options' ) ) {
            wp_send_json_error( array( 'message' => __( 'Permission denied.', 'seo-repair-kit' ) ) );
        }

        // Verify nonce - don't sanitize as it can break the nonce
        $nonce = isset( $_POST['nonce'] ) ? $_POST['nonce'] : '';
        if ( empty( $nonce ) ) {
            wp_send_json_error( array( 'message' => __( 'Security token missing. Please refresh the page and try again.', 'seo-repair-kit' ) ) );
        }

        $nonce_check = wp_verify_nonce( $nonce, 'srk_404_manager_nonce' );
        if ( $nonce_check === false ) {
            wp_send_json_error( array( 'message' => __( 'Security check failed. Please refresh the page and try again.', 'seo-repair-kit' ) ) );
        }

        if ( class_exists( 'SeoRepairKit_404_Monitor' ) ) {
            $stats = SeoRepairKit_404_Monitor::get_404_statistics();
        } else {
            $stats = array(
                'total_404s' => 0,
                'unique_urls' => 0,
                'total_hits' => 0,
                'most_hit' => null,
                'recent_404s' => array(),
            );
        }
        wp_send_json_success( $stats );
    }

    /**
     * Ensure 404 logs table exists
     *
     * @since 2.1.0
     */
    private function ensure_404_table_exists() {
        global $wpdb;
        
        $table_name = $wpdb->prefix . 'srkit_404_logs';
        
        // Cache table existence check to avoid duplicate queries
        $cache_key = 'srk_404_table_exists';
        $table_exists = get_transient( $cache_key );
        
        // If cache doesn't exist, check table
        if ( false === $table_exists ) {
            // Check if table exists (use prepared statement for LIKE clause)
            $table_exists = ( $wpdb->get_var( $wpdb->prepare( "SHOW TABLES LIKE %s", $table_name ) ) === $table_name );
            
            // Cache result for 5 minutes
            set_transient( $cache_key, $table_exists ? 1 : 0, 5 * MINUTE_IN_SECONDS );
        } else {
            $table_exists = (bool) $table_exists;
        }
        
        if ( $table_exists ) {
            return true;
        }
        
        // Table doesn't exist, try to create it
        // Include activator class to create table
        $activator_path = plugin_dir_path( dirname( __FILE__ ) ) . 'includes/class-seo-repair-kit-activator.php';
        if ( file_exists( $activator_path ) ) {
            require_once $activator_path;
            // Use reflection to call private method
            if ( class_exists( 'SeoRepairKit_Activator' ) ) {
                $reflection = new ReflectionClass( 'SeoRepairKit_Activator' );
                if ( $reflection->hasMethod( 'create_404_logs_table' ) ) {
                    $method = $reflection->getMethod( 'create_404_logs_table' );
                    $method->setAccessible( true );
                    $method->invoke( null );
                    
                    // Clear cache after table creation
                    delete_transient( $cache_key );
                    delete_transient( 'srk_404_statistics' );
                } else {
                    // Fallback: Create table directly
                    $this->create_404_table_directly();
                    delete_transient( $cache_key );
                    delete_transient( 'srk_404_statistics' );
                }
            } else {
                $this->create_404_table_directly();
                delete_transient( $cache_key );
                delete_transient( 'srk_404_statistics' );
            }
        } else {
            $this->create_404_table_directly();
        }
        
        return ( $wpdb->get_var( "SHOW TABLES LIKE '$table_name'" ) === $table_name );
    }

    /**
     * Create 404 logs table directly
     *
     * @since 2.1.0
     */
    private function create_404_table_directly() {
        global $wpdb;
        
        $table_name = $wpdb->prefix . 'srkit_404_logs';
        $charset_collate = $wpdb->get_charset_collate();
        
        $table_query = "CREATE TABLE IF NOT EXISTS $table_name ( 
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
    }
}

global $seoRepairKitLinkScanner;
$seoRepairKitLinkScanner = new SeoRepairKit_LinkScanner();