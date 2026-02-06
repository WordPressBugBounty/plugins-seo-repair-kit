<?php
if ( ! defined( 'ABSPATH' ) ) { exit; }

/**
 * SeoRepairKit_Dashboard class
 */
class SeoRepairKit_Dashboard {

    public function __construct() {
        add_action( 'admin_enqueue_scripts', array( $this, 'enqueue_styles_and_scripts' ) );

        // AJAX endpoint for dynamically refreshing the "Site SEO Analysis" issues table.
        add_action( 'wp_ajax_srk_dashboard_refresh_seo_issues', array( $this, 'ajax_refresh_seo_issues' ) );

        // Ensure notices helper is available when the dashboard renders.
        require_once plugin_dir_path( dirname( __FILE__ ) ) . 'includes/class-srk-admin-notices.php';
		// Ensure license helper is available for plan status widgets.
		if ( ! class_exists( 'SRK_License_Helper' ) ) {
			require_once plugin_dir_path( dirname( __FILE__ ) ) . 'includes/class-srk-license-helper.php';
		}
    }

    /**
     * Enqueue assets specific to the overview dashboard.
     *
     * @param string $hook Current admin page hook suffix.
     */
    public function enqueue_styles_and_scripts( $hook ) {
        if ( empty( $_GET['page'] ) || 'seo-repair-kit-dashboard' !== $_GET['page'] ) {
            return;
        }

        wp_enqueue_style( 'srk-dashboard-style' );

        wp_enqueue_script(
            'srk-dashboard-status-refresh',
            plugin_dir_url( __FILE__ ) . 'js/srk-dashboard-status-refresh.js',
            array( 'jquery' ),
            defined( 'SEO_REPAIR_KIT_VERSION' ) ? SEO_REPAIR_KIT_VERSION : '1.0.0',
            true
        );

        wp_enqueue_script(
            'srk-lifetime-deal-countdown',
            plugin_dir_url( __FILE__ ) . 'js/srk-lifetime-deal-countdown.js',
            array( 'jquery' ),
            defined( 'SEO_REPAIR_KIT_VERSION' ) ? SEO_REPAIR_KIT_VERSION : '1.0.0',
            true
        );

        wp_localize_script(
            'srk-dashboard-status-refresh',
            'SRK_DASHBOARD_STATUS',
            array(
                'ajaxUrl' => admin_url( 'admin-ajax.php' ),
                'nonce'   => wp_create_nonce( 'srk_dashboard_refresh_nonce' ),
            )
        );
    }

    /**
     * Displays the SEO Repair Kit dashboard overview page.
     */
    public function seorepairkit_dashboard_page() {
        $link_snapshot = get_option( 'srk_last_links_snapshot', array() );
        if ( ! is_array( $link_snapshot ) ) {
            $link_snapshot = array();
        }

        $total_links   = isset( $link_snapshot['totalLinks'] ) ? (int) $link_snapshot['totalLinks'] : 0;
        $broken_links  = isset( $link_snapshot['brokenLinks'] ) ? (int) $link_snapshot['brokenLinks'] : 0;
        $working_links = isset( $link_snapshot['workingLinks'] ) ? (int) $link_snapshot['workingLinks'] : max( 0, $total_links - $broken_links );
        $last_scan_ts  = isset( $link_snapshot['timestamp'] ) ? (int) $link_snapshot['timestamp'] : 0;
        $scan_count    = isset( $link_snapshot['scannedCount'] ) ? (int) $link_snapshot['scannedCount'] : 0;

        $now              = current_time( 'timestamp' );
        $last_scan_label  = $last_scan_ts ? sprintf( esc_html__( 'Last scan %s ago', 'seo-repair-kit' ), human_time_diff( $last_scan_ts, $now ) ) : esc_html__( 'No scans yet', 'seo-repair-kit' );

		// Automation / schedule.
		$links_schedule = get_option( 'srk_links_schedule', 'manual' );

        // Check if KeyTrack is enabled via option OR if there are active configurations
        $keytrack_option_enabled = (bool) get_option( 'srk_keytrack_enabled', false );
        $keytrack_has_configs = false;
        
        // Check if KeyTrack has configurations in the database
        global $wpdb;
        $keytrack_config_count = $wpdb->get_var(
            "SELECT COUNT(*) FROM {$wpdb->prefix}srkit_keytrack_settings"
        );
        $keytrack_has_configs = ( $keytrack_config_count > 0 );
        
        $keytrack_enabled = $keytrack_option_enabled || $keytrack_has_configs;
        
        // Count how many schema types are actually configured and assigned.
        // Use cached value to avoid expensive database query on every page load.
        $cache_key = 'srk_schema_count';
        $schema_count = get_transient( $cache_key );
        
        if ( false === $schema_count ) {
            // Cache miss - query database and cache result for 5 minutes
            global $wpdb;
            $schema_options = $wpdb->get_results(
                $wpdb->prepare(
                    "SELECT option_name, option_value FROM {$wpdb->options} WHERE option_name LIKE %s",
                    'srk_schema_assignment_%'
                ),
                OBJECT
            );
            
            $schema_count = 0;
            foreach ( $schema_options as $option ) {
                $schema_data = maybe_unserialize( $option->option_value );
                // Count if it's a valid array structure (has schema_type) - this means it's configured.
                // Some schemas might have minimal or empty meta_map but still be valid.
                if ( is_array( $schema_data ) && ! empty( $schema_data['schema_type'] ) ) {
                    $schema_count++;
                }
            }
            
            // Cache for 5 minutes to improve performance
            set_transient( $cache_key, $schema_count, 5 * MINUTE_IN_SECONDS );
        }

        // Additional feature flags for widgets.
        $alt_scan_enabled      = (bool) get_option( 'srk_alt_scan_enabled', false );
		$redirection_enabled   = (bool) get_option( 'srk_redirection_enabled', false );
        $alt_missing_count     = null;

        // Always fetch the current number of images missing alt text dynamically
        // using the same data source as the weekly summary service.
        if ( class_exists( 'SeoRepairKit_WeeklySummaryService' ) ) {
            $weekly_service = SeoRepairKit_WeeklySummaryService::get_instance();
            if ( method_exists( $weekly_service, 'srk_get_alt_text_data' ) ) {
                $alt_stats = $weekly_service->srk_get_alt_text_data();
                if ( is_array( $alt_stats ) && isset( $alt_stats['missing_count'] ) ) {
                    $alt_missing_count = (int) $alt_stats['missing_count'];
                }
            }
        }
		$onboarding_setup      = get_option( 'srk_setup', array() );
		$onboarding_completed  = ! empty( $onboarding_setup['completed'] );

		// License / plan information for "Your Plan" widget.
		$license_active   = class_exists( 'SRK_License_Helper' ) ? SRK_License_Helper::is_license_active() : false;
		$plan_label       = $license_active ? esc_html__( 'Pro Plan', 'seo-repair-kit' ) : esc_html__( 'Free Plan', 'seo-repair-kit' );
		$plan_status      = $license_active ? esc_html__( 'License active', 'seo-repair-kit' ) : esc_html__( 'Upgrade to unlock all features', 'seo-repair-kit' );
        // Treat AI Assistant as enabled when license is active (matches chatbot screen logic).
        $chatbot_enabled = $license_active;

        // URLs
        $link_scanner_url  = admin_url( 'admin.php?page=seo-repair-kit-link-scanner' );
        $keytrack_url      = admin_url( 'admin.php?page=seo-repair-kit-keytrack' );
        $schema_url        = admin_url( 'admin.php?page=srk-schema-manager' );
        $chatbot_url       = admin_url( 'admin.php?page=srk-ai-chatbot' );
        $bot_manager_url   = admin_url( 'admin.php?page=seo-repair-kit-robots-llms' );
        $settings_url      = admin_url( 'admin.php?page=seo-repair-kit-settings' );
        $redirection_url   = admin_url( 'admin.php?page=seo-repair-kit-redirection' );
        $monitor_404_url   = admin_url( 'admin.php?page=seo-repair-kit-link-scanner' );
        $alt_text_url      = admin_url( 'admin.php?page=alt-image-missing' );
		$upgrade_url       = admin_url( 'admin.php?page=seo-repair-kit-upgrade-pro' );
		
		// Build subscribe URL for direct upgrade (same as Upgrade Now button)
		$domain = site_url();
		$subscribe_url = '';
		if ( class_exists( 'SRK_API_Client' ) ) {
			$subscribe_url = SRK_API_Client::get_api_url( SRK_API_Client::ENDPOINT_SUBSCRIBE, [ 'domain' => $domain ] );
		} else {
			// Fallback to upgrade page if API client not available
			$subscribe_url = $upgrade_url;
		}

        // Get SEO checks/issues, covering all major features
        $seo_issues = $this->get_seo_issues(
            $broken_links,
            $keytrack_enabled,
            $schema_count,
            $chatbot_enabled,
            $alt_scan_enabled,
            $redirection_enabled,
            $onboarding_completed,
            $license_active,
            $alt_missing_count
        );

        // Count issues by severity
        $critical_count = 0;
        $warning_count = 0;
        $suggestion_count = 0;
        foreach ( $seo_issues as $issue ) {
            if ( $issue['status'] === 'error' ) {
                $critical_count++;
            } elseif ( $issue['status'] === 'warning' ) {
                $warning_count++;
            } elseif ( $issue['status'] === 'suggestion' ) {
                $suggestion_count++;
            }
        }
        $total_issues = $critical_count + $warning_count;

        ?>
        <?php
        if ( function_exists( 'srk_render_notices_after_navbar' ) ) {
            echo '<div class="srk-notices-area">';
            srk_render_notices_after_navbar();
            echo '</div>';
        }
        ?>

        <div id="srk-dashboard" class="srk-wrap srk-dashboard-overview">
            <div class="srk-dashboard-container">
                <!-- Main Content Area (Left) -->
                <div class="srk-dashboard-main">
                    <!-- Site SEO Analysis Section -->
                    <div class="srk-seo-analysis-card">
                        <div class="srk-seo-analysis-header">
                            <h3 class="srk-seo-analysis-title"><?php esc_html_e( 'Site SEO Analysis', 'seo-repair-kit' ); ?></h3>
                            <button type="button" class="srk-btn-sm">
                                <span class="dashicons dashicons-update"></span>
                                <?php esc_html_e( 'Re-check Status', 'seo-repair-kit' ); ?>
                            </button>
                        </div>
                        
                        <?php if ( ! empty( $seo_issues ) ) : ?>
                            <div class="srk-seo-issues-table">
                                <table class="srk-issues-table">
                                    <thead>
                                        <tr>
                                            <th><?php esc_html_e( 'Issue', 'seo-repair-kit' ); ?></th>
                                            <th class="srk-action-column"><?php esc_html_e( 'Action', 'seo-repair-kit' ); ?></th>
                                        </tr>
                                    </thead>
                                    <tbody>
                                        <?php foreach ( $seo_issues as $issue ) : ?>
                                            <tr class="srk-issue-row srk-issue-<?php echo esc_attr( $issue['status'] ); ?>">
                                                <td>
                                                    <div class="srk-issue-content">
                                                        <span class="srk-badge srk-badge-<?php echo esc_attr( $issue['status'] ); ?>">
                                                            <?php echo esc_html( $issue['label'] ); ?>
                                                        </span>
                                                        <span class="srk-issue-message"><?php echo esc_html( $issue['message'] ); ?></span>
                                                    </div>
                                                </td>
                                                <td class="srk-action-column">
                                                    <div class="srk-action-buttons">
                                                        <?php if ( $issue['status'] !== 'success' ) : ?>
                                                            <a href="<?php echo esc_url( $issue['action_url'] ); ?>" class="srk-btn-xs">
                                                                <?php echo esc_html( $issue['action_text'] ); ?>
                                                            </a>
                                                        <?php endif; ?>
                                                        <a href="<?php echo esc_url( $issue['action_url'] ); ?>" class="srk-icon-btn" title="<?php esc_attr_e( 'View Details', 'seo-repair-kit' ); ?>">
                                                            <span class="dashicons dashicons-arrow-right-alt"></span>
                                                        </a>
                                                    </div>
                                                </td>
                                            </tr>
                                        <?php endforeach; ?>
                                    </tbody>
                                </table>
                            </div>
                        <?php else : ?>
                            <div class="srk-empty-state">
                                <p><?php esc_html_e( 'No SEO issues detected. Your site looks great!', 'seo-repair-kit' ); ?></p>
                            </div>
                        <?php endif; ?>
                    </div>

					<!-- Summary Widgets -->
					<div class="srk-summary-grid">
						<!-- Your Plan Widget -->
						<div class="srk-summary-card srk-summary-plan <?php echo $license_active ? 'srk-plan-card-pro' : 'srk-plan-card-free'; ?>">
							<h4 class="srk-summary-title"><?php esc_html_e( 'Your Plan', 'seo-repair-kit' ); ?></h4>

							<div class="srk-plan-row">
								<div class="srk-plan-info">
									<div class="srk-plan-pill <?php echo $license_active ? 'srk-plan-pill-pro' : 'srk-plan-pill-free'; ?>">
										<span class="srk-plan-dot"></span>
										<span><?php echo esc_html( $plan_label ); ?></span>
									</div>
									<p class="srk-summary-text">
										<?php echo esc_html( $plan_status ); ?>
									</p>
								</div>
							</div>

							<ul class="srk-summary-list">
								<li><?php esc_html_e( 'Health check for links, schema, and redirects.', 'seo-repair-kit' ); ?></li>
								<li><?php esc_html_e( 'One place to launch all SEO Repair Kit tools.', 'seo-repair-kit' ); ?></li>
							</ul>

							<div class="srk-summary-footer">
								<a href="<?php echo esc_url( $upgrade_url ); ?>" class="srk-btn-xs">
									<?php
									echo $license_active
										? esc_html__( 'Manage License', 'seo-repair-kit' )
										: esc_html__( 'Upgrade to Pro', 'seo-repair-kit' );
									?>
								</a>
							</div>
						</div>
					</div>
                </div>

                <!-- Sidebar (Right) -->
                <div class="srk-dashboard-sidebar">
                    <!-- Lifetime Deal Banner -->
                    <div class="srk-lifetime-deal-banner">
                        <div class="srk-lifetime-deal-content">
                            <div class="srk-lifetime-deal-badge">
                                <span class="dashicons dashicons-awards"></span>
                                <span class="srk-lifetime-deal-label"><?php esc_html_e( 'Lifetime Deal', 'seo-repair-kit' ); ?></span>
                            </div>
                            <h4 class="srk-lifetime-deal-title"><?php esc_html_e( 'Get Lifetime Access', 'seo-repair-kit' ); ?></h4>
                            <p class="srk-lifetime-deal-description">
                                <?php esc_html_e( 'Pay once, use forever! Get all Pro features with lifetime updates and support.', 'seo-repair-kit' ); ?>
                            </p>
                            <!-- Countdown Timer -->
                            <div class="srk-lifetime-deal-countdown">
                                <div class="srk-countdown-label">
                                    <span class="dashicons dashicons-clock"></span>
                                    <span><?php esc_html_e( 'Limited Time Offer', 'seo-repair-kit' ); ?></span>
                                </div>
                                <div class="srk-countdown-timer" data-days="15" data-hours="0" data-minutes="0" data-seconds="0">
                                    <div class="srk-countdown-item">
                                        <span class="srk-countdown-value" id="srk-countdown-days">15</span>
                                        <span class="srk-countdown-label-unit"><?php esc_html_e( 'Days', 'seo-repair-kit' ); ?></span>
                                    </div>
                                    <span class="srk-countdown-separator">:</span>
                                    <div class="srk-countdown-item">
                                        <span class="srk-countdown-value" id="srk-countdown-hours">00</span>
                                        <span class="srk-countdown-label-unit"><?php esc_html_e( 'Hours', 'seo-repair-kit' ); ?></span>
                                    </div>
                                    <span class="srk-countdown-separator">:</span>
                                    <div class="srk-countdown-item">
                                        <span class="srk-countdown-value" id="srk-countdown-minutes">00</span>
                                        <span class="srk-countdown-label-unit"><?php esc_html_e( 'Minutes', 'seo-repair-kit' ); ?></span>
                                    </div>
                                    <span class="srk-countdown-separator">:</span>
                                    <div class="srk-countdown-item">
                                        <span class="srk-countdown-value" id="srk-countdown-seconds">00</span>
                                        <span class="srk-countdown-label-unit"><?php esc_html_e( 'Seconds', 'seo-repair-kit' ); ?></span>
                                    </div>
                                </div>
                            </div>
                            <div class="srk-lifetime-deal-pricing">
                                <div class="srk-lifetime-deal-price-wrapper">
                                    <span class="srk-lifetime-deal-price-icon">
                                        <span class="dashicons dashicons-money-alt"></span>
                                    </span>
                                    <span class="srk-lifetime-deal-price"><?php esc_html_e( 'Starting from $199', 'seo-repair-kit' ); ?></span>
                                </div>
                                <span class="srk-lifetime-deal-savings"><?php esc_html_e( 'Save 67% vs Annual', 'seo-repair-kit' ); ?></span>
                                <div class="srk-lifetime-deal-no-renewal">
                                    <span class="dashicons dashicons-yes-alt"></span>
                                    <span><?php esc_html_e( 'One Time Payment - NO Renewal', 'seo-repair-kit' ); ?></span>
                                </div>
                            </div>
                            <a href="<?php echo esc_url( $subscribe_url ); ?>" target="_blank" rel="noopener noreferrer" class="srk-lifetime-deal-button">
                                <span class="srk-lifetime-deal-button-icon">
                                    <span class="dashicons dashicons-cart"></span>
                                </span>
                                <span><?php esc_html_e( 'Claim Lifetime Deal', 'seo-repair-kit' ); ?></span>
                                <span class="dashicons dashicons-arrow-right-alt"></span>
                            </a>
                        </div>
                    </div>

                    <!-- Key Tools Section (reordered quick access) -->
                    <div class="srk-sidebar-card">
                        <h4 class="srk-sidebar-title"><?php esc_html_e( 'Key Tools', 'seo-repair-kit' ); ?></h4>
                        <div class="srk-plugin-grid">
                            <!-- Link Scanner -->
                            <a href="<?php echo esc_url( $link_scanner_url ); ?>" class="srk-plugin-item srk-tool-link-scanner">
                                <div class="srk-plugin-icon">
                                    <span class="dashicons dashicons-admin-links"></span>
                                </div>
                                <div class="srk-plugin-info">
                                    <h5><?php esc_html_e( 'Link Scanner', 'seo-repair-kit' ); ?></h5>
                                    <p><?php esc_html_e( 'Scan your site for broken links and fix them fast.', 'seo-repair-kit' ); ?></p>
                                </div>
                            </a>
                            <!-- KeyTrack (now available in free version) -->
                            <a href="<?php echo esc_url( $keytrack_url ); ?>" class="srk-plugin-item srk-tool-keytrack">
                                <div class="srk-plugin-icon">
                                    <span class="dashicons dashicons-chart-line"></span>
                                </div>
                                <div class="srk-plugin-info">
                                    <h5><?php esc_html_e( 'KeyTrack', 'seo-repair-kit' ); ?></h5>
                                    <p><?php esc_html_e( 'Monitor keyword rankings and search performance.', 'seo-repair-kit' ); ?></p>
                                </div>
                            </a>
                            <!-- Schema Manager -->
                            <a href="<?php echo esc_url( $schema_url ); ?>" class="srk-plugin-item srk-tool-schema <?php echo ! $license_active ? 'srk-plugin-item-pro' : ''; ?>">
                                <?php if ( ! $license_active ) : ?>
                                    <span class="srk-pro-pill"><?php esc_html_e( 'Pro', 'seo-repair-kit' ); ?></span>
                                <?php endif; ?>
                                <div class="srk-plugin-icon">
                                    <span class="dashicons dashicons-admin-settings"></span>
                                </div>
                                <div class="srk-plugin-info">
                                    <h5><?php esc_html_e( 'Schema Manager', 'seo-repair-kit' ); ?></h5>
                                    <p><?php esc_html_e( 'Add and manage rich schema markup for content.', 'seo-repair-kit' ); ?></p>
                                </div>
                            </a>
                            <!-- AI Chatbot -->
                            <a href="<?php echo esc_url( $chatbot_url ); ?>" class="srk-plugin-item srk-tool-chatbot <?php echo ! $license_active ? 'srk-plugin-item-pro' : ''; ?>">
                                <?php if ( ! $license_active ) : ?>
                                    <span class="srk-pro-pill"><?php esc_html_e( 'Pro', 'seo-repair-kit' ); ?></span>
                                <?php endif; ?>
                                <div class="srk-plugin-icon">
                                    <span class="dashicons dashicons-format-chat"></span>
                                </div>
                                <div class="srk-plugin-info">
                                    <h5><?php esc_html_e( 'AI Chatbot', 'seo-repair-kit' ); ?></h5>
                                    <p><?php esc_html_e( 'Get real-time SEO assistance and answers.', 'seo-repair-kit' ); ?></p>
                                </div>
                            </a>
                            <!-- 404 Monitor -->
                            <a href="<?php echo esc_url( $monitor_404_url ); ?>" class="srk-plugin-item srk-tool-404">
                                <div class="srk-plugin-icon">
                                    <span class="dashicons dashicons-warning"></span>
                                </div>
                                <div class="srk-plugin-info">
                                    <h5><?php esc_html_e( '404 Monitor', 'seo-repair-kit' ); ?></h5>
                                    <p><?php esc_html_e( 'Track 404 errors and convert them into redirects.', 'seo-repair-kit' ); ?></p>
                                </div>
                            </a>
                            <!-- Bot Manager -->
                            <a href="<?php echo esc_url( $bot_manager_url ); ?>" class="srk-plugin-item srk-tool-bot-manager">
                                <div class="srk-plugin-icon">
                                    <span class="dashicons dashicons-admin-tools"></span>
                                </div>
                                <div class="srk-plugin-info">
                                    <h5><?php esc_html_e( 'Bot Manager', 'seo-repair-kit' ); ?></h5>
                                    <p><?php esc_html_e( 'Control search engines and AI crawlers with robots.txt and llms.txt.', 'seo-repair-kit' ); ?></p>
                                </div>
                            </a>
                            <!-- Redirections -->
                            <a href="<?php echo esc_url( $redirection_url ); ?>" class="srk-plugin-item srk-tool-redirection">
                                <div class="srk-plugin-icon">
                                    <span class="dashicons dashicons-migrate"></span>
                                </div>
                                <div class="srk-plugin-info">
                                    <h5><?php esc_html_e( 'Redirections', 'seo-repair-kit' ); ?></h5>
                                    <p><?php esc_html_e( 'Create and manage redirects to recover SEO value.', 'seo-repair-kit' ); ?></p>
                                </div>
                            </a>
                            <!-- Image Alt Missing -->
                            <a href="<?php echo esc_url( $alt_text_url ); ?>" class="srk-plugin-item srk-tool-alt-text">
                                <div class="srk-plugin-icon">
                                    <span class="dashicons dashicons-format-image"></span>
                                </div>
                                <div class="srk-plugin-info">
                                    <h5><?php esc_html_e( 'Image Alt Missing', 'seo-repair-kit' ); ?></h5>
                                    <p><?php esc_html_e( 'Find images missing alt text and improve accessibility.', 'seo-repair-kit' ); ?></p>
                                </div>
                            </a>
                            <!-- Settings -->
                            <a href="<?php echo esc_url( $settings_url ); ?>" class="srk-plugin-item srk-tool-settings">
                                <div class="srk-plugin-icon">
                                    <span class="dashicons dashicons-admin-generic"></span>
                                </div>
                                <div class="srk-plugin-info">
                                    <h5><?php esc_html_e( 'Settings', 'seo-repair-kit' ); ?></h5>
                                    <p><?php esc_html_e( 'Configure how SEO Repair Kit works on your site.', 'seo-repair-kit' ); ?></p>
                                </div>
                            </a>
                            <!-- Get Support -->
                            <a href="https://support.seorepairkit.com/" target="_blank" rel="noopener" class="srk-plugin-item srk-tool-support">
                                <div class="srk-plugin-icon">
                                    <span class="dashicons dashicons-admin-users"></span>
                                </div>
                                <div class="srk-plugin-info">
                                    <h5><?php esc_html_e( 'Get Support', 'seo-repair-kit' ); ?></h5>
                                    <p><?php esc_html_e( 'Contact our team if you need help or have questions.', 'seo-repair-kit' ); ?></p>
                                </div>
                            </a>
                        </div>
                    </div>
                </div>
            </div>
        </div>
        <?php
    }

    /**
     * AJAX handler to refresh the "Site SEO Analysis" issues dynamically.
     * Recomputes the current issues server-side and returns them as JSON.
     */
    public function ajax_refresh_seo_issues() {
        if ( ! current_user_can( 'manage_options' ) ) {
            wp_send_json_error( array( 'message' => __( 'Permission denied.', 'seo-repair-kit' ) ), 403 );
        }

        check_ajax_referer( 'srk_dashboard_refresh_nonce', 'nonce' );

        // Mirror the data loading logic from seorepairkit_dashboard_page().
        $link_snapshot = get_option( 'srk_last_links_snapshot', array() );
        if ( ! is_array( $link_snapshot ) ) {
            $link_snapshot = array();
        }

        $total_links   = isset( $link_snapshot['totalLinks'] ) ? (int) $link_snapshot['totalLinks'] : 0;
        $broken_links  = isset( $link_snapshot['brokenLinks'] ) ? (int) $link_snapshot['brokenLinks'] : 0;
        $working_links = isset( $link_snapshot['workingLinks'] ) ? (int) $link_snapshot['workingLinks'] : max( 0, $total_links - $broken_links );

        $links_schedule = get_option( 'srk_links_schedule', 'manual' );

        // Check if KeyTrack is enabled via option OR if there are active configurations
        $keytrack_option_enabled = (bool) get_option( 'srk_keytrack_enabled', false );
        $keytrack_has_configs = false;
        
        // Check if KeyTrack has configurations in the database
        global $wpdb;
        $keytrack_config_count = $wpdb->get_var(
            "SELECT COUNT(*) FROM {$wpdb->prefix}srkit_keytrack_settings"
        );
        $keytrack_has_configs = ( $keytrack_config_count > 0 );
        
        $keytrack_enabled = $keytrack_option_enabled || $keytrack_has_configs;
        
        // Count how many schema types are actually configured and assigned.
        // Use cached value to avoid expensive database query on every page load.
        $cache_key = 'srk_schema_count';
        $schema_count = get_transient( $cache_key );
        
        if ( false === $schema_count ) {
            // Cache miss - query database and cache result for 5 minutes
            global $wpdb;
            $schema_options = $wpdb->get_results(
                $wpdb->prepare(
                    "SELECT option_name, option_value FROM {$wpdb->options} WHERE option_name LIKE %s",
                    'srk_schema_assignment_%'
                ),
                OBJECT
            );
            
            $schema_count = 0;
            foreach ( $schema_options as $option ) {
                $schema_data = maybe_unserialize( $option->option_value );
                // Count if it's a valid array structure (has schema_type) - this means it's configured.
                // Some schemas might have minimal or empty meta_map but still be valid.
                if ( is_array( $schema_data ) && ! empty( $schema_data['schema_type'] ) ) {
                    $schema_count++;
                }
            }
            
            // Cache for 5 minutes to improve performance
            set_transient( $cache_key, $schema_count, 5 * MINUTE_IN_SECONDS );
        }

        // Additional feature flags.
        $alt_scan_enabled    = (bool) get_option( 'srk_alt_scan_enabled', false );
        $redirection_enabled = (bool) get_option( 'srk_redirection_enabled', false );

        // Always fetch alt text stats dynamically (if the weekly summary service is available).
        $alt_missing_count = null;
        if ( class_exists( 'SeoRepairKit_WeeklySummaryService' ) ) {
            $weekly_service = SeoRepairKit_WeeklySummaryService::get_instance();
            if ( method_exists( $weekly_service, 'srk_get_alt_text_data' ) ) {
                $alt_stats = $weekly_service->srk_get_alt_text_data();
                if ( is_array( $alt_stats ) && isset( $alt_stats['missing_count'] ) ) {
                    $alt_missing_count = (int) $alt_stats['missing_count'];
                }
            }
        }

        $onboarding_setup     = get_option( 'srk_setup', array() );
        $onboarding_completed = ! empty( $onboarding_setup['completed'] );

        $license_active = class_exists( 'SRK_License_Helper' ) ? SRK_License_Helper::is_license_active() : false;
        $chatbot_enabled = $license_active;

        $seo_issues = $this->get_seo_issues(
            $broken_links,
            $keytrack_enabled,
            $schema_count,
            $chatbot_enabled,
            $alt_scan_enabled,
            $redirection_enabled,
            $onboarding_completed,
            $license_active,
            $alt_missing_count
        );

        wp_send_json_success(
            array(
                'issues' => $seo_issues,
            )
        );
    }

    /**
     * Get SEO issues/checks for the dashboard.
     *
     * This aggregates status for all major plugin features so the
     * "Site SEO Analysis" section gives a complete overview.
     *
     * @param int  $broken_links        Number of broken links found.
     * @param bool $keytrack_enabled    Whether KeyTrack is enabled.
     * @param int  $schema_count        Number of enabled schema types.
     * @param bool $chatbot_enabled     Whether AI Assistant is enabled.
     * @param bool     $alt_scan_enabled    Whether Alt Text scanner is enabled.
     * @param bool     $redirection_enabled Whether Redirections are enabled.
     * @param bool     $onboarding_completed Whether guided setup has been completed.
     * @param bool     $license_active      Whether a Pro license is active.
     * @param int|null $alt_missing_count   Number of images currently missing alt text (or null if not calculated).
     *
     * @return array[]
     */
    private function get_seo_issues( $broken_links, $keytrack_enabled, $schema_count, $chatbot_enabled, $alt_scan_enabled, $redirection_enabled, $onboarding_completed, $license_active, $alt_missing_count ) {
        $issues = array();
        
        $link_scanner_url = admin_url( 'admin.php?page=seo-repair-kit-link-scanner' );
        $keytrack_url     = admin_url( 'admin.php?page=seo-repair-kit-keytrack' );
        $schema_url       = admin_url( 'admin.php?page=srk-schema-manager' );
        $settings_url     = admin_url( 'admin.php?page=seo-repair-kit-settings' );
        $redirection_url  = admin_url( 'admin.php?page=seo-repair-kit-redirection' );
        $monitor_404_url  = admin_url( 'admin.php?page=seo-repair-kit-link-scanner' );
        $alt_text_url     = admin_url( 'admin.php?page=alt-image-missing' );
        $upgrade_url      = admin_url( 'admin.php?page=seo-repair-kit-upgrade-pro' );
        
        // ──────────────────────────────────────────────────────────────
        // Link Scanner & broken links
        // ──────────────────────────────────────────────────────────────
        // Critical: Broken links found
        if ( $broken_links > 0 ) {
            $issues[] = array(
                'status'     => 'error',
                'label'      => esc_html__( 'Critical', 'seo-repair-kit' ),
                'message'    => sprintf( _n( '%d broken link found on your site.', '%d broken links found on your site.', $broken_links, 'seo-repair-kit' ), $broken_links ),
                'action_text' => esc_html__( 'Help Me Fix', 'seo-repair-kit' ),
                'action_url' => $link_scanner_url,
            );
        }
        
        // Success: No broken links
        if ( $broken_links === 0 ) {
            $issues[] = array(
                'status'     => 'success',
                'label'      => esc_html__( 'Passed', 'seo-repair-kit' ),
                'message'    => esc_html__( 'No broken links detected. Your internal and external links look healthy.', 'seo-repair-kit' ),
                'action_text' => '',
                'action_url' => $link_scanner_url,
            );
        }
        
        // ──────────────────────────────────────────────────────────────
        // KeyTrack
        // ──────────────────────────────────────────────────────────────
        
        // Warning: KeyTrack not enabled
        if ( ! $keytrack_enabled ) {
            $issues[] = array(
                'status'     => 'warning',
                'label'      => esc_html__( 'Warning', 'seo-repair-kit' ),
                'message'    => esc_html__( 'KeyTrack is not enabled. Enable it to track search performance.', 'seo-repair-kit' ),
                'action_text' => esc_html__( 'Help Me Fix', 'seo-repair-kit' ),
                'action_url' => $keytrack_url,
            );
        }
        
        if ( $keytrack_enabled ) {
            $issues[] = array(
                'status'     => 'success',
                'label'      => esc_html__( 'Passed', 'seo-repair-kit' ),
                'message'    => esc_html__( 'KeyTrack is enabled. Your keyword performance is being monitored.', 'seo-repair-kit' ),
                'action_text' => '',
                'action_url' => $keytrack_url,
            );
        }
        
        // ──────────────────────────────────────────────────────────────
        // Schema Manager
        // ──────────────────────────────────────────────────────────────
        //
        // Map schema status to actual configuration:
        // - If Pro license is not active, surface this as a Pro-gated feature.
        // - If Pro is active, reflect how many schema types are configured and active.
        if ( ! $license_active ) {
            $issues[] = array(
                'status'      => 'suggestion',
                'label'       => esc_html__( 'Suggestion', 'seo-repair-kit' ),
                'message'     => esc_html__( 'Schema Manager is a Pro feature. Activate a Pro license to enable schema markup and rich results.', 'seo-repair-kit' ),
                'action_text' => esc_html__( 'View Plans', 'seo-repair-kit' ),
                'action_url'  => $upgrade_url,
            );
        } else {
            // Warning: Pro is active but no schema types are configured.
            if ( $schema_count === 0 ) {
                $issues[] = array(
                    'status'      => 'warning',
                    'label'       => esc_html__( 'Warning', 'seo-repair-kit' ),
                    'message'     => esc_html__( 'No schema markup is enabled. Turn on at least one schema type to improve rich results.', 'seo-repair-kit' ),
                    'action_text' => esc_html__( 'Help Me Fix', 'seo-repair-kit' ),
                    'action_url'  => $schema_url,
                );
            } else {
                $issues[] = array(
                    'status'      => 'success',
                    'label'       => esc_html__( 'Passed', 'seo-repair-kit' ),
                    'message'     => sprintf(
                        _n(
                            '%d schema type is configured and active on your site.',
                            '%d schema types are configured and active on your site.',
                            $schema_count,
                            'seo-repair-kit'
                        ),
                        $schema_count
                    ),
                    'action_text' => '',
                    'action_url'  => $schema_url,
                );
            }
        }
        
        // ──────────────────────────────────────────────────────────────
        // AI Assistant (Chatbot)
        // ──────────────────────────────────────────────────────────────
        // Suggestion: AI Assistant not enabled
        if ( ! $chatbot_enabled ) {
            $issues[] = array(
                'status'     => 'suggestion',
                'label'      => esc_html__( 'Suggestion', 'seo-repair-kit' ),
                'message'    => esc_html__( 'AI Assistant is not enabled. Turn it on to get real-time SEO guidance inside WordPress.', 'seo-repair-kit' ),
                'action_text' => esc_html__( 'Help Me Fix', 'seo-repair-kit' ),
                'action_url' => admin_url( 'admin.php?page=srk-ai-chatbot' ),
            );
        }
        
        if ( $chatbot_enabled ) {
            $issues[] = array(
                'status'     => 'success',
                'label'      => esc_html__( 'Passed', 'seo-repair-kit' ),
                'message'    => esc_html__( 'AI Assistant is active. You can ask SEO questions and get instant answers.', 'seo-repair-kit' ),
                'action_text' => '',
                'action_url' => admin_url( 'admin.php?page=srk-ai-chatbot' ),
            );
        }
        
        // ──────────────────────────────────────────────────────────────
        // 404 Monitor & Redirections
        // ──────────────────────────────────────────────────────────────
        // Always show "Passed" status by default for Redirections
        $issues[] = array(
            'status'     => 'success',
            'label'      => esc_html__( 'Passed', 'seo-repair-kit' ),
            'message'    => esc_html__( 'Redirections are available. You can convert 404s into SEO-friendly redirects.', 'seo-repair-kit' ),
            'action_text' => '',
            'action_url' => $redirection_url,
        );

        // 404 Monitor is always available; surface it as a suggestion to review.
        $issues[] = array(
            'status'      => 'suggestion',
            'label'       => esc_html__( 'Suggestion', 'seo-repair-kit' ),
            'message'     => esc_html__( 'Review your 404 Error Monitor regularly to catch and fix high-traffic 404 pages.', 'seo-repair-kit' ),
            'action_text' => esc_html__( 'Open 404 Monitor', 'seo-repair-kit' ),
            'action_url'  => $monitor_404_url,
        );

        // ──────────────────────────────────────────────────────────────
        // Alt Text Scanner
        // ──────────────────────────────────────────────────────────────
        // Dynamically show status based on actual missing alt text count
        if ( is_int( $alt_missing_count ) && $alt_missing_count > 0 ) {
            // Warning: Images are missing alt text
            $issues[] = array(
                'status'      => 'warning',
                'label'       => esc_html__( 'Warning', 'seo-repair-kit' ),
                'message'     => sprintf(
                    _n(
                        '%d image is missing alt text. Fill in descriptive alt attributes to improve accessibility and image SEO.',
                        '%d images are missing alt text. Fill in descriptive alt attributes to improve accessibility and image SEO.',
                        $alt_missing_count,
                        'seo-repair-kit'
                    ),
                    $alt_missing_count
                ),
                'action_text' => esc_html__( 'Help Me Fix', 'seo-repair-kit' ),
                'action_url'  => $alt_text_url,
            );
        } else {
            // Passed: No images missing alt text (or count not available)
            $issues[] = array(
                'status'      => 'success',
                'label'       => esc_html__( 'Passed', 'seo-repair-kit' ),
                'message'     => esc_html__( 'No images are currently missing alt text. Your image accessibility looks good.', 'seo-repair-kit' ),
                'action_text' => '',
                'action_url'  => $alt_text_url,
            );
        }

        // ──────────────────────────────────────────────────────────────
        // License & Guided Setup
        // ──────────────────────────────────────────────────────────────
        if ( ! $license_active ) {
            $issues[] = array(
                'status'     => 'warning',
                'label'      => esc_html__( 'Warning', 'seo-repair-kit' ),
                'message'    => esc_html__( 'Your Pro license is not active. Some advanced features may be limited.', 'seo-repair-kit' ),
                'action_text' => esc_html__( 'View Plans', 'seo-repair-kit' ),
                'action_url' => $upgrade_url,
            );
        } else {
            $issues[] = array(
                'status'     => 'success',
                'label'      => esc_html__( 'Passed', 'seo-repair-kit' ),
                'message'    => esc_html__( 'Your Pro license is active. All premium SEO features are available.', 'seo-repair-kit' ),
                'action_text' => '',
                'action_url' => $upgrade_url,
            );
        }

        if ( ! $onboarding_completed ) {
            $issues[] = array(
                'status'     => 'suggestion',
                'label'      => esc_html__( 'Suggestion', 'seo-repair-kit' ),
                'message'    => esc_html__( 'Complete the Guided Setup to make sure all SEO Repair Kit features are configured for your site.', 'seo-repair-kit' ),
                'action_text' => esc_html__( 'Open Settings', 'seo-repair-kit' ),
                'action_url' => $settings_url,
            );
        }

        return $issues;
    }
}

global $seoRepairKitDashboard;
$seoRepairKitDashboard = new SeoRepairKit_Dashboard();
