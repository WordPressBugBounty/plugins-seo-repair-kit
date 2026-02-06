<?php
if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/**
 * SEO Repair Kit 404 Error Manager
 *
 * Admin UI for managing and viewing 404 error logs.
 * Integrated with redirection feature to convert 404s to redirects.
 *
 * @link       https://seorepairkit.com
 * @since      2.1.0
 * @author     TorontoDigits <support@torontodigits.com>
 */
class SeoRepairKit_404_Manager {

    private $db_404;

    /**
     * Constructor
     */
    public function __construct() {
        global $wpdb;
        $this->db_404 = $wpdb;

        // AJAX actions
        add_action( 'wp_ajax_srk_delete_404', array( $this, 'srk_delete_404' ) );
        add_action( 'wp_ajax_srk_bulk_action_404', array( $this, 'srk_bulk_action_404' ) );
        add_action( 'wp_ajax_srk_clear_404_logs', array( $this, 'srk_clear_404_logs' ) );
        add_action( 'wp_ajax_srk_convert_404_to_redirect', array( $this, 'srk_convert_404_to_redirect' ) );
        add_action( 'wp_ajax_srk_export_404_logs', array( $this, 'srk_export_404_logs' ) );
        add_action( 'wp_ajax_srk_get_404_stats', array( $this, 'srk_get_404_stats' ) );

        // Enqueue scripts and styles
        add_action( 'admin_enqueue_scripts', array( $this, 'enqueue_styles_and_scripts' ) );
    }

    /**
     * Enqueue assets for 404 management page
     *
     * @param string $hook Current admin page hook suffix
     */
    public function enqueue_styles_and_scripts( $hook ) {
        if ( empty( $_GET['page'] ) || 'srk-404-monitor' !== $_GET['page'] ) {
            return;
        }

        wp_enqueue_style( 'srk-404-manager-style' );
        wp_enqueue_script( 'srk-404-manager-script' );

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
     * Display 404 monitoring page
     */
    public function seorepairkit_404_monitor_page() {
        // Ensure notices helper is available
        $notices_path = plugin_dir_path( dirname( __FILE__ ) ) . 'includes/class-srk-admin-notices.php';
        if ( file_exists( $notices_path ) ) {
            require_once $notices_path;
        }
        
        // Load 404 monitor class
        $monitor_path = plugin_dir_path( dirname( __FILE__ ) ) . '../public/class-seo-repair-kit-404-monitor.php';
        if ( ! file_exists( $monitor_path ) ) {
            // Try alternative path
            $monitor_path = plugin_dir_path( __FILE__ ) . '../../public/class-seo-repair-kit-404-monitor.php';
        }
        if ( file_exists( $monitor_path ) ) {
            require_once $monitor_path;
        } else {
            // If class file not found, try to ensure table exists and show error message
            if ( ! class_exists( 'SeoRepairKit_404_Monitor' ) ) {
                $this->ensure_404_table_exists();
                if ( ! class_exists( 'SeoRepairKit_404_Monitor' ) ) {
                    wp_die( esc_html__( 'Error: 404 Monitor class file not found. Please deactivate and reactivate the plugin.', 'seo-repair-kit' ) );
                }
            }
        }

        // Ensure database table exists
        $this->ensure_404_table_exists();

        // Get statistics
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
        
        // Check if table exists before querying
        $table_exists = ( $this->db_404->get_var( "SHOW TABLES LIKE '$table_name'" ) === $table_name );
        
        if ( ! $table_exists ) {
            // Table doesn't exist, create it
            $this->ensure_404_table_exists();
            $table_exists = ( $this->db_404->get_var( "SHOW TABLES LIKE '$table_name'" ) === $table_name );
        }
        
        $per_page_raw = isset( $_GET['srk_404_per_page'] ) ? sanitize_text_field( $_GET['srk_404_per_page'] ) : 25;
        $show_all = ( $per_page_raw === 'all' || $per_page_raw === '-1' );

        if ( $show_all ) {
            $per_page = -1;
            $current_page = 1;
            $offset = 0;
        } else {
            $per_page = intval( $per_page_raw );
            $per_page = max( 10, min( 200, $per_page ) );
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
            $allowed_orderby = array( 'url', 'count', 'last_accessed', 'first_accessed', 'ip_address' );
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
        <?php
        if ( function_exists( 'srk_render_notices_after_navbar' ) ) {
            echo '<div class="srk-notices-area">';
            srk_render_notices_after_navbar();
            echo '</div>';
        }
        ?>

        <div class="wrap seo-repair-kit-404-manager">
            <h1 class="srk-page-title">
                <?php esc_html_e( '404 Error Monitor', 'seo-repair-kit' ); ?>
                <span class="srk-version-badge">v2.1.0</span>
            </h1>

            <!-- Statistics Dashboard -->
            <div class="srk-404-stats-dashboard">
                <div class="srk-stats-grid">
                    <div class="srk-stat-card">
                        <h3><?php esc_html_e( 'Total 404 Errors', 'seo-repair-kit' ); ?></h3>
                        <div class="srk-stat-number"><?php echo number_format_i18n( $stats['total_hits'] ); ?></div>
                        <p class="srk-stat-desc"><?php esc_html_e( 'Total occurrences', 'seo-repair-kit' ); ?></p>
                    </div>
                    <div class="srk-stat-card">
                        <h3><?php esc_html_e( 'Unique URLs', 'seo-repair-kit' ); ?></h3>
                        <div class="srk-stat-number"><?php echo number_format_i18n( $stats['unique_urls'] ); ?></div>
                        <p class="srk-stat-desc"><?php esc_html_e( 'Distinct 404 pages', 'seo-repair-kit' ); ?></p>
                    </div>
                    <div class="srk-stat-card">
                        <h3><?php esc_html_e( 'Total Log Entries', 'seo-repair-kit' ); ?></h3>
                        <div class="srk-stat-number"><?php echo number_format_i18n( $stats['total_404s'] ); ?></div>
                        <p class="srk-stat-desc"><?php esc_html_e( 'Logged entries', 'seo-repair-kit' ); ?></p>
                    </div>
                    <div class="srk-stat-card">
                        <h3><?php esc_html_e( 'Most Hit 404', 'seo-repair-kit' ); ?></h3>
                        <div class="srk-stat-number">
                            <?php if ( $stats['most_hit'] ) : ?>
                                <?php echo number_format_i18n( $stats['most_hit']->count ); ?>
                                <small><?php echo esc_html( substr( $stats['most_hit']->url, 0, 50 ) . ( strlen( $stats['most_hit']->url ) > 50 ? '...' : '' ) ); ?></small>
                            <?php else : ?>
                                0
                            <?php endif; ?>
                        </div>
                        <p class="srk-stat-desc"><?php esc_html_e( 'Highest count', 'seo-repair-kit' ); ?></p>
                    </div>
                </div>
            </div>

            <!-- Filters and Actions -->
            <div class="srk-404-filters">
                <form method="get" action="" class="srk-filter-form">
                    <input type="hidden" name="page" value="srk-404-monitor" />
                    
                    <div class="srk-filter-row">
                        <div class="srk-filter-group">
                            <label for="srk_filter_url"><?php esc_html_e( 'Filter by URL:', 'seo-repair-kit' ); ?></label>
                            <input type="text" id="srk_filter_url" name="srk_filter_url" value="<?php echo esc_attr( $filter_url ); ?>" placeholder="<?php esc_attr_e( 'Search URL...', 'seo-repair-kit' ); ?>" />
                        </div>
                        
                        <div class="srk-filter-group">
                            <label for="srk_filter_ip"><?php esc_html_e( 'Filter by IP:', 'seo-repair-kit' ); ?></label>
                            <input type="text" id="srk_filter_ip" name="srk_filter_ip" value="<?php echo esc_attr( $filter_ip ); ?>" placeholder="<?php esc_attr_e( 'Search IP...', 'seo-repair-kit' ); ?>" />
                        </div>

                        <div class="srk-filter-group">
                            <label for="srk_orderby"><?php esc_html_e( 'Order by:', 'seo-repair-kit' ); ?></label>
                            <select id="srk_orderby" name="srk_orderby">
                                <option value="last_accessed" <?php selected( $orderby, 'last_accessed' ); ?>><?php esc_html_e( 'Last Accessed', 'seo-repair-kit' ); ?></option>
                                <option value="count" <?php selected( $orderby, 'count' ); ?>><?php esc_html_e( 'Hit Count', 'seo-repair-kit' ); ?></option>
                                <option value="url" <?php selected( $orderby, 'url' ); ?>><?php esc_html_e( 'URL', 'seo-repair-kit' ); ?></option>
                                <option value="first_accessed" <?php selected( $orderby, 'first_accessed' ); ?>><?php esc_html_e( 'First Accessed', 'seo-repair-kit' ); ?></option>
                            </select>
                            <select name="srk_order">
                                <option value="DESC" <?php selected( $order, 'DESC' ); ?>><?php esc_html_e( 'Descending', 'seo-repair-kit' ); ?></option>
                                <option value="ASC" <?php selected( $order, 'ASC' ); ?>><?php esc_html_e( 'Ascending', 'seo-repair-kit' ); ?></option>
                            </select>
                        </div>

                        <div class="srk-filter-actions">
                            <button type="submit" class="button button-primary"><?php esc_html_e( 'Filter', 'seo-repair-kit' ); ?></button>
                            <a href="<?php echo esc_url( admin_url( 'admin.php?page=srk-404-monitor' ) ); ?>" class="button"><?php esc_html_e( 'Reset', 'seo-repair-kit' ); ?></a>
                        </div>
                    </div>
                </form>
            </div>

            <!-- Bulk Actions -->
            <div class="srk-404-bulk-actions">
                <select id="srk_bulk_action_404">
                    <option value=""><?php esc_html_e( 'Bulk Actions', 'seo-repair-kit' ); ?></option>
                    <option value="delete"><?php esc_html_e( 'Delete', 'seo-repair-kit' ); ?></option>
                    <option value="ignore"><?php esc_html_e( 'Ignore', 'seo-repair-kit' ); ?></option>
                </select>
                <button type="button" class="button" id="srk_apply_bulk_404"><?php esc_html_e( 'Apply', 'seo-repair-kit' ); ?></button>
                <button type="button" class="button button-secondary" id="srk_clear_all_404"><?php esc_html_e( 'Clear All Logs', 'seo-repair-kit' ); ?></button>
                <button type="button" class="button button-secondary" id="srk_export_404"><?php esc_html_e( 'Export CSV', 'seo-repair-kit' ); ?></button>
                <button type="button" class="button button-secondary" id="srk_refresh_stats"><?php esc_html_e( 'Refresh Stats', 'seo-repair-kit' ); ?></button>
            </div>

            <!-- 404 Logs Table -->
            <div class="srk-404-logs-table">
                <?php if ( ! empty( $logs ) || $total_items > 0 ) : ?>
                    <table class="wp-list-table widefat fixed striped srk-404-table">
                        <thead>
                            <tr>
                                <th class="check-column"><input type="checkbox" id="srk-select-all-404" /></th>
                                <th><?php esc_html_e( 'URL', 'seo-repair-kit' ); ?></th>
                                <th><?php esc_html_e( 'Count', 'seo-repair-kit' ); ?></th>
                                <th><?php esc_html_e( 'Referrer', 'seo-repair-kit' ); ?></th>
                                <th><?php esc_html_e( 'IP Address', 'seo-repair-kit' ); ?></th>
                                <th><?php esc_html_e( 'User Agent', 'seo-repair-kit' ); ?></th>
                                <th><?php esc_html_e( 'First Accessed', 'seo-repair-kit' ); ?></th>
                                <th><?php esc_html_e( 'Last Accessed', 'seo-repair-kit' ); ?></th>
                                <th class="srk-actions-column"><?php esc_html_e( 'Actions', 'seo-repair-kit' ); ?></th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php if ( ! empty( $logs ) ) : ?>
                                <?php foreach ( $logs as $log ) : ?>
                                    <tr data-log-id="<?php echo esc_attr( $log->id ); ?>">
                                        <th scope="row" class="check-column">
                                            <input type="checkbox" class="srk-404-checkbox" value="<?php echo esc_attr( $log->id ); ?>" />
                                        </th>
                                        <td class="srk-url-column">
                                            <code><?php echo esc_html( $log->url ); ?></code>
                                        </td>
                                        <td>
                                            <span class="srk-badge-count"><?php echo number_format_i18n( $log->count ); ?></span>
                                        </td>
                                        <td>
                                            <?php if ( ! empty( $log->referrer ) ) : ?>
                                                <a href="<?php echo esc_url( $log->referrer ); ?>" target="_blank" rel="noopener">
                                                    <?php echo esc_html( substr( $log->referrer, 0, 50 ) . ( strlen( $log->referrer ) > 50 ? '...' : '' ) ); ?>
                                                </a>
                                            <?php else : ?>
                                                <span class="srk-muted">-</span>
                                            <?php endif; ?>
                                        </td>
                                        <td><?php echo esc_html( $log->ip_address ?: '-' ); ?></td>
                                        <td class="srk-user-agent-column">
                                            <?php if ( ! empty( $log->user_agent ) ) : ?>
                                                <span title="<?php echo esc_attr( $log->user_agent ); ?>">
                                                    <?php echo esc_html( substr( $log->user_agent, 0, 40 ) . ( strlen( $log->user_agent ) > 40 ? '...' : '' ) ); ?>
                                                </span>
                                            <?php else : ?>
                                                <span class="srk-muted">-</span>
                                            <?php endif; ?>
                                        </td>
                                        <td><?php echo esc_html( date_i18n( get_option( 'date_format' ) . ' ' . get_option( 'time_format' ), strtotime( $log->first_accessed ) ) ); ?></td>
                                        <td><?php echo esc_html( date_i18n( get_option( 'date_format' ) . ' ' . get_option( 'time_format' ), strtotime( $log->last_accessed ) ) ); ?></td>
                                        <td class="srk-actions-column">
                                            <div class="srk-action-buttons">
                                                <a href="<?php echo esc_url( admin_url( 'admin.php?page=seo-repair-kit-redirection&source_url=' . urlencode( $log->url ) ) ); ?>" class="srk-action-btn srk-btn-redirect" target="_blank" title="<?php esc_attr_e( 'Create Redirect', 'seo-repair-kit' ); ?>">
                                                    <span class="dashicons dashicons-migrate"></span>
                                                </a>
                                            </div>
                                        </td>
                                    </tr>
                                <?php endforeach; ?>
                            <?php else : ?>
                                <tr>
                                    <td colspan="9" class="srk-empty-state">
                                        <?php esc_html_e( 'No 404 errors found.', 'seo-repair-kit' ); ?>
                                    </td>
                                </tr>
                            <?php endif; ?>
                        </tbody>
                    </table>

                    <!-- Pagination -->
                    <?php if ( ! $show_all && $total_pages > 1 ) : ?>
                        <div class="srk-pagination">
                            <div class="srk-pagination-info">
                                <?php
                                printf(
                                    esc_html__( 'Showing %1$d-%2$d of %3$d entries', 'seo-repair-kit' ),
                                    $offset + 1,
                                    min( $offset + $per_page, $total_items ),
                                    $total_items
                                );
                                ?>
                            </div>
                            <div class="srk-pagination-links">
                                <?php
                                $pagination_url = admin_url( 'admin.php?page=srk-404-monitor' );
                                $pagination_url .= ! empty( $filter_url ) ? '&srk_filter_url=' . urlencode( $filter_url ) : '';
                                $pagination_url .= ! empty( $filter_ip ) ? '&srk_filter_ip=' . urlencode( $filter_ip ) : '';
                                $pagination_url .= '&srk_orderby=' . urlencode( $orderby );
                                $pagination_url .= '&srk_order=' . urlencode( $order );
                                $pagination_url .= '&srk_404_per_page=' . urlencode( $per_page_raw );

                                if ( $current_page > 1 ) {
                                    echo '<a href="' . esc_url( $pagination_url . '&srk_404_paged=' . ( $current_page - 1 ) ) . '" class="button">' . esc_html__( '&laquo; Previous', 'seo-repair-kit' ) . '</a>';
                                }

                                for ( $i = 1; $i <= $total_pages; $i++ ) {
                                    if ( $i === 1 || $i === $total_pages || ( $i >= $current_page - 2 && $i <= $current_page + 2 ) ) {
                                        $class = $i === $current_page ? 'button button-primary' : 'button';
                                        echo '<a href="' . esc_url( $pagination_url . '&srk_404_paged=' . $i ) . '" class="' . esc_attr( $class ) . '">' . esc_html( $i ) . '</a>';
                                    } elseif ( $i === $current_page - 3 || $i === $current_page + 3 ) {
                                        echo '<span class="dots">...</span>';
                                    }
                                }

                                if ( $current_page < $total_pages ) {
                                    echo '<a href="' . esc_url( $pagination_url . '&srk_404_paged=' . ( $current_page + 1 ) ) . '" class="button">' . esc_html__( 'Next &raquo;', 'seo-repair-kit' ) . '</a>';
                                }
                                ?>
                            </div>
                            <div class="srk-per-page-selector">
                                <label>
                                    <?php esc_html_e( 'Per page:', 'seo-repair-kit' ); ?>
                                    <select name="srk_404_per_page" onchange="window.location.href = '<?php echo esc_js( $pagination_url ); ?>&srk_404_paged=1&srk_404_per_page=' + this.value;">
                                        <option value="25" <?php selected( $per_page_raw, '25' ); ?>>25</option>
                                        <option value="50" <?php selected( $per_page_raw, '50' ); ?>>50</option>
                                        <option value="100" <?php selected( $per_page_raw, '100' ); ?>>100</option>
                                        <option value="200" <?php selected( $per_page_raw, '200' ); ?>>200</option>
                                        <option value="all" <?php selected( $show_all, true ); ?>><?php esc_html_e( 'All', 'seo-repair-kit' ); ?></option>
                                    </select>
                                </label>
                            </div>
                        </div>
                    <?php endif; ?>
                <?php else : ?>
                    <div class="srk-empty-state">
                        <h3><?php esc_html_e( 'No 404 Errors Yet', 'seo-repair-kit' ); ?></h3>
                        <p><?php esc_html_e( '404 error monitoring is active. Any 404 errors will be logged here automatically.', 'seo-repair-kit' ); ?></p>
                    </div>
                <?php endif; ?>
            </div>

            <!-- Convert to Redirect Modal -->
            <div id="srk-convert-modal" class="srk-modal" style="display: none;">
                <div class="srk-modal-content">
                    <div class="srk-modal-header">
                        <h2><?php esc_html_e( 'Create Redirect from 404', 'seo-repair-kit' ); ?></h2>
                        <button type="button" class="srk-modal-close">&times;</button>
                    </div>
                    <div class="srk-modal-body">
                        <form id="srk-convert-form">
                            <div class="srk-form-group">
                                <label for="srk_source_url_404"><?php esc_html_e( 'Source URL (404 URL):', 'seo-repair-kit' ); ?></label>
                                <input type="text" id="srk_source_url_404" name="source_url" readonly class="readonly" />
                                <small><?php esc_html_e( 'This is the URL that resulted in a 404 error.', 'seo-repair-kit' ); ?></small>
                            </div>
                            
                            <div class="srk-form-group">
                                <label for="srk_target_url_404"><?php esc_html_e( 'Target URL:', 'seo-repair-kit' ); ?> <span class="required">*</span></label>
                                <input type="text" id="srk_target_url_404" name="target_url" required placeholder="/new-page/" />
                                <small><?php esc_html_e( 'Enter the URL to redirect to. Use relative path (e.g., /new-page/) or full URL.', 'seo-repair-kit' ); ?></small>
                            </div>

                            <div class="srk-form-group">
                                <label for="srk_redirect_type_404"><?php esc_html_e( 'Redirect Type:', 'seo-repair-kit' ); ?></label>
                                <select id="srk_redirect_type_404" name="redirect_type">
                                    <option value="301">301 - Moved Permanently</option>
                                    <option value="302">302 - Found</option>
                                    <option value="307">307 - Temporary Redirect</option>
                                    <option value="308">308 - Permanent Redirect</option>
                                </select>
                            </div>

                            <div class="srk-form-group">
                                <label>
                                    <input type="checkbox" id="srk_delete_404_after_convert" name="delete_404" checked />
                                    <?php esc_html_e( 'Delete 404 log entry after creating redirect', 'seo-repair-kit' ); ?>
                                </label>
                            </div>

                            <input type="hidden" id="srk_log_id_for_convert" name="log_id" />
                        </form>
                    </div>
                    <div class="srk-modal-footer">
                        <button type="button" class="button srk-modal-cancel"><?php esc_html_e( 'Cancel', 'seo-repair-kit' ); ?></button>
                        <button type="button" class="button button-primary" id="srk_confirm_convert"><?php esc_html_e( 'Create Redirect', 'seo-repair-kit' ); ?></button>
                    </div>
                </div>
            </div>
        </div>
        <?php
    }

    /**
     * AJAX: Delete 404 log entry
     */
    public function srk_delete_404() {
        if ( ! current_user_can( 'manage_options' ) ) {
            wp_send_json_error( array( 'message' => __( 'Permission denied.', 'seo-repair-kit' ) ), 403 );
        }

        check_ajax_referer( 'srk_404_manager_nonce', 'nonce' );

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
            wp_send_json_success( array( 'message' => __( '404 log deleted successfully.', 'seo-repair-kit' ) ) );
        } else {
            wp_send_json_error( array( 'message' => __( 'Failed to delete 404 log.', 'seo-repair-kit' ) ) );
        }
    }

    /**
     * AJAX: Bulk action on 404 logs
     */
    public function srk_bulk_action_404() {
        if ( ! current_user_can( 'manage_options' ) ) {
            wp_send_json_error( array( 'message' => __( 'Permission denied.', 'seo-repair-kit' ) ), 403 );
        }

        check_ajax_referer( 'srk_404_manager_nonce', 'nonce' );

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
        
        $ids_placeholder = implode( ',', array_fill( 0, count( $log_ids ), '%d' ) );

        if ( $action === 'delete' ) {
            $result = $this->db_404->query(
                $this->db_404->prepare(
                    "DELETE FROM $table_name WHERE id IN ($ids_placeholder)",
                    ...$log_ids
                )
            );

            if ( $result !== false ) {
                wp_send_json_success( array( 
                    'message' => sprintf( _n( '%d 404 log deleted.', '%d 404 logs deleted.', count( $log_ids ), 'seo-repair-kit' ), count( $log_ids ) ),
                    'deleted' => count( $log_ids )
                ) );
            } else {
                wp_send_json_error( array( 'message' => __( 'Failed to delete 404 logs.', 'seo-repair-kit' ) ) );
            }
        } elseif ( $action === 'ignore' ) {
            // For now, ignore means delete (can be enhanced later)
            $result = $this->db_404->query(
                $this->db_404->prepare(
                    "DELETE FROM $table_name WHERE id IN ($ids_placeholder)",
                    ...$log_ids
                )
            );

            if ( $result !== false ) {
                wp_send_json_success( array( 
                    'message' => sprintf( _n( '%d 404 log ignored.', '%d 404 logs ignored.', count( $log_ids ), 'seo-repair-kit' ), count( $log_ids ) ),
                    'ignored' => count( $log_ids )
                ) );
            } else {
                wp_send_json_error( array( 'message' => __( 'Failed to ignore 404 logs.', 'seo-repair-kit' ) ) );
            }
        } else {
            wp_send_json_error( array( 'message' => __( 'Invalid action.', 'seo-repair-kit' ) ) );
        }
    }

    /**
     * AJAX: Clear all 404 logs
     */
    public function srk_clear_404_logs() {
        if ( ! current_user_can( 'manage_options' ) ) {
            wp_send_json_error( array( 'message' => __( 'Permission denied.', 'seo-repair-kit' ) ), 403 );
        }

        check_ajax_referer( 'srk_404_manager_nonce', 'nonce' );

        // Ensure table exists
        $this->ensure_404_table_exists();

        $days = isset( $_POST['days'] ) ? intval( $_POST['days'] ) : 0;
        $deleted = 0;
        
        if ( class_exists( 'SeoRepairKit_404_Monitor' ) ) {
            $deleted = SeoRepairKit_404_Monitor::clear_404_logs( $days );
        } else {
            // Fallback: Clear directly
            $table_name = $this->db_404->prefix . 'srkit_404_logs';
            if ( $this->db_404->get_var( "SHOW TABLES LIKE '$table_name'" ) === $table_name ) {
                if ( $days > 0 ) {
                    $date_threshold = date( 'Y-m-d H:i:s', strtotime( "-$days days" ) );
                    $deleted = $this->db_404->query(
                        $this->db_404->prepare(
                            "DELETE FROM $table_name WHERE last_accessed < %s",
                            $date_threshold
                        )
                    );
                } else {
                    $deleted = $this->db_404->query( "TRUNCATE TABLE $table_name" );
                }
                $deleted = $deleted !== false ? (int) $deleted : 0;
            }
        }

        wp_send_json_success( array( 
            'message' => sprintf( _n( '%d log entry cleared.', '%d log entries cleared.', $deleted, 'seo-repair-kit' ), $deleted ),
            'deleted' => $deleted
        ) );
    }

    /**
     * AJAX: Convert 404 to redirect
     */
    public function srk_convert_404_to_redirect() {
        if ( ! current_user_can( 'manage_options' ) ) {
            wp_send_json_error( array( 'message' => __( 'Permission denied.', 'seo-repair-kit' ) ), 403 );
        }

        check_ajax_referer( 'srk_404_manager_nonce', 'nonce' );

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
     */
    public function srk_export_404_logs() {
        if ( ! current_user_can( 'manage_options' ) ) {
            wp_send_json_error( array( 'message' => __( 'Permission denied.', 'seo-repair-kit' ) ), 403 );
        }

        check_ajax_referer( 'srk_404_manager_nonce', 'nonce' );

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
     */
    public function srk_get_404_stats() {
        if ( ! current_user_can( 'manage_options' ) ) {
            wp_send_json_error( array( 'message' => __( 'Permission denied.', 'seo-repair-kit' ) ), 403 );
        }

        check_ajax_referer( 'srk_404_manager_nonce', 'nonce' );

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
        
        // Check if table exists
        if ( $wpdb->get_var( "SHOW TABLES LIKE '$table_name'" ) === $table_name ) {
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
                } else {
                    // Fallback: Create table directly
                    $this->create_404_table_directly();
                }
            } else {
                $this->create_404_table_directly();
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