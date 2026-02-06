<?php
if ( ! defined( 'ABSPATH' ) ) {
    exit;
}
/**
 * The admin-specific functionality of the plugin.
 *
 * Defines the plugin name, version, and two examples hooks for how to
 * enqueue the admin-specific stylesheet and JavaScript.
 * 
 * @link       https://seorepairkit.com
 * @since      1.0.1
 * @version    2.1.0
 * @author     TorontoDigits <support@torontodigits.com>
 */
class SeoRepairKit_Admin {

	/**
	 * The ID of this plugin.
	 *
	 * @since    1.0.1
	 * @access   private
	 * @var      string    $seo_repair_kit    The ID of this plugin.
	 */
	private $seo_repair_kit;
	/**
	 * The version of this plugin.
	 *
	 * @since    1.0.1
	 * @access   private
	 * @var      string    $version    The current version of this plugin.
	 */
	private $version;
    private $cached_license_info = null;
	private $crm_endpoint;
    // Live key for production
    private $crm_app_key = 'base64:S/1nezDK+azLBl1Mo5vvTYX0HieBnG5fJn6H9/r0ZrI=';

	/**
	 * Initialize the class and set its properties.
	 *
	 * @since    1.0.1
	 * @param      string    $seo_repair_kit       The name of this plugin.
	 * @param      string    $version    The version of this plugin.
	 */
	public function __construct( $seo_repair_kit, $version ) {

		add_action( 'admin_notices', array( $this, 'display_seo_repair_kit_notice' ) );
        add_action( 'admin_post_srkit_update_settings', array( $this, 'handle_update_settings' ) );
		add_action( 'admin_menu', array( $this, 'seo_repair_kit_menu_page' ) );
		add_filter( 'admin_footer_text', array( $this, 'powered_by_torontodigits' ) );

		// ADD THIS INSTEAD (navbar renders before notices)
		add_action( 'in_admin_header', array( $this, 'display_seo_repair_kit_navbar' ), 20 );
		add_action( 'admin_init', [$this, 'srk_check_and_store_license_after_payment'] );
		
		// Add loader HTML early in head for immediate availability
		add_action( 'admin_head', array( $this, 'add_page_loader_html' ) );

        // ──────────────────────────────────────────────────────────────
        // Auto-open the Feature Guide ONCE right after activation
        // (requires an activation hook to set srk_should_run_modal_onboarding = 'yes')
        // Only trigger if user has consented to site info sharing
        // NEVER run if onboarding has already been completed
        // ──────────────────────────────────────────────────────────────
        add_action( 'admin_init', function(){
            // Never auto-run again if onboarding already ran once or completed.
            $has_run  = (bool) get_option( 'srk_onboarding_has_run', false );
            $srk_setup = get_option( 'srk_setup', array() );
            if ( $has_run || ( ! empty( $srk_setup['completed'] ) && ! empty( $srk_setup['completed_at'] ) ) ) {
                delete_option( 'srk_should_run_modal_onboarding' );
                return;
            }

            if ( 'yes' === get_option( 'srk_should_run_modal_onboarding' ) ) {
                // Check if user has previously explicitly denied consent
                require_once plugin_dir_path( dirname( __FILE__ ) ) . 'includes/class-seo-repair-kit-activator.php';
                $consent_option = get_option( 'srk_site_info_consent' );
                // Only skip onboarding if consent was explicitly denied (0)
                // Allow onboarding if: consent is null (first time), consent is 1 (granted), or not explicitly set to 0
                if ( $consent_option !== 0 ) {
                    add_filter( 'srk_run_modal_onboarding_now', '__return_true' );
                    // Mark as run so it never auto-triggers again.
                    update_option( 'srk_onboarding_has_run', 1 );
                }
                delete_option( 'srk_should_run_modal_onboarding' );
            }
        });

        // ──────────────────────────────────────────────────────────────
        // Enqueue the scoped Feature Guide (no global CSS leaks), localize exact links
        // ──────────────────────────────────────────────────────────────
        add_action( 'admin_enqueue_scripts', function(){
            // Check if onboarding is already completed - if so, skip loading all assets
            // This improves performance by not loading unnecessary CSS/JS and heavy data prep
            $srk_setup = get_option( 'srk_setup', array() );
            $onboarding_completed = ! empty( $srk_setup['completed'] ) && ! empty( $srk_setup['completed_at'] );
            
            // If onboarding is completed, don't load assets at all (performance optimization)
            if ( $onboarding_completed ) {
                return;
            }

            // Only load onboarding assets when needed: SRK pages or when the
            // one-time onboarding flag asks to run immediately. This avoids
            // heavy data prep on every admin screen.
            $srk_pages = array(
                'seo-repair-kit-dashboard',
                'seo-repair-kit-link-scanner',
                'seo-repair-kit-keytrack',
                'srk-schema-manager',
                'srk-ai-chatbot',
                'alt-image-missing',
                'seo-repair-kit-redirection',
                'seo-repair-kit-settings',
                'seo-repair-kit-upgrade-pro',
                'seo-repair-kit-robots-llms',
            );
            $current_page = isset( $_GET['page'] ) ? sanitize_key( $_GET['page'] ) : '';
            $run_now = apply_filters( 'srk_run_modal_onboarding_now', false ) ? true : false;
            $should_load = $run_now || ( $current_page && in_array( $current_page, $srk_pages, true ) );

            if ( ! $should_load ) {
                return;
            }

            // CSS
            wp_enqueue_style(
                'srk-setup-onboarding',
                plugin_dir_url( __FILE__ ) . 'css/srk-setup-onboarding.css',
                array(),
                defined('SEO_REPAIR_KIT_VERSION') ? SEO_REPAIR_KIT_VERSION : '1.0'
            );

            // Post types list for the “Your Site” step
            $public = get_post_types( array( 'public' => true ), 'objects' );
            $pt_list = array();
            foreach ( $public as $obj ) {
                $pt_list[] = array( 'name' => $obj->name, 'label' => $obj->label );
            }

            // JS
            wp_enqueue_script(
                'srk-setup-onboarding-modal',
                plugin_dir_url( __FILE__ ) . 'js/srk-setup-onboarding-modal.js',
                array( 'jquery' ),
                defined('SEO_REPAIR_KIT_VERSION') ? SEO_REPAIR_KIT_VERSION : '1.0',
                true
            );

			/**
             * IMPORTANT: The slugs below must match your add_menu_page/add_submenu_page.
             * These are generated dynamically with admin_url() so they work on any domain.
             */
            $links = array(
                'dashboard'   => admin_url( 'admin.php?page=seo-repair-kit-dashboard' ),
                'linkScanner' => admin_url( 'admin.php?page=seo-repair-kit-link-scanner' ),
                'keytrack'    => admin_url( 'admin.php?page=seo-repair-kit-keytrack' ),
                'schema'      => admin_url( 'admin.php?page=srk-schema-manager' ),
                'chatbot'     => admin_url( 'admin.php?page=srk-ai-chatbot' ),
                'alt'         => admin_url( 'admin.php?page=alt-image-missing' ),
                'redirection' => admin_url( 'admin.php?page=seo-repair-kit-redirection' ),
                'settings'    => admin_url( 'admin.php?page=seo-repair-kit-settings' ),
                'upgrade'     => admin_url( 'admin.php?page=seo-repair-kit-upgrade-pro' ),
            );

            $assets = array(
                'keytrackImage' => plugin_dir_url( __FILE__ ) . 'images/KeyTrack-Image.svg',
                'chatbotImage'  => plugin_dir_url( __FILE__ ) . 'images/AI-Chatbot-Image.svg',
                'welcomeImage'  => plugin_dir_url( __FILE__ ) . 'images/SRK-Onboarding-Image.svg',
            );

            $chatbot_status = array(
                'enabled' => (bool) get_option( 'srk_chatbot_enabled', false )
            );

            $post_stats = array();
            $post_totals = array(
                'published' => 0,
                'drafts'    => 0,
                'scheduled' => 0,
                'total'     => 0,
            );

            foreach ( $pt_list as $pt ) {
                $counts     = wp_count_posts( $pt['name'] );
                $published  = isset( $counts->publish ) ? (int) $counts->publish : 0;
                $drafts     = isset( $counts->draft ) ? (int) $counts->draft : 0;
                $scheduled  = isset( $counts->future ) ? (int) $counts->future : 0;
                $total      = $published + $drafts + $scheduled;

                $post_stats[ $pt['name'] ] = array(
                    'label'     => $pt['label'],
                    'published' => $published,
                    'drafts'    => $drafts,
                    'scheduled' => $scheduled,
                    'total'     => $total,
                );

                $post_totals['published'] += $published;
                $post_totals['drafts']    += $drafts;
                $post_totals['scheduled'] += $scheduled;
                $post_totals['total']     += $total;
            }

            $link_snapshot = get_option( 'srk_last_links_snapshot', array() );
            if ( empty( $link_snapshot ) || ! is_array( $link_snapshot ) ) {
                $link_snapshot = array(
                    'totalLinks'   => 0,
                    'brokenLinks'  => 0,
                    'workingLinks' => 0,
                    'timestamp'    => 0,
                    'postTypes'    => array(),
                    'scannedCount' => 0,
                );
            }

            $links_schedule       = get_option( 'srk_links_schedule', 'manual' );
            $last_scan_timestamp  = isset( $link_snapshot['timestamp'] ) ? (int) $link_snapshot['timestamp'] : (int) get_option( 'srk_last_links_scan_at', 0 );
            $last_scan_human_read = $last_scan_timestamp ? human_time_diff( $last_scan_timestamp, current_time( 'timestamp' ) ) : '';

            wp_localize_script( 'srk-setup-onboarding-modal', 'SRK_ONBOARDING_DATA', array(
                'ajaxUrl'   => admin_url( 'admin-ajax.php' ),
                'nonce'     => wp_create_nonce( 'srk_onboarding_nonce' ),
                'runNow'    => $run_now ? true : false,
                'brand'     => array( 'primary' => '#0B1D51', 'accent' => '#F28500' ),
                'pluginUrl' => plugin_dir_url( __FILE__ ),
                'assets'    => $assets,
                'chatbot'   => $chatbot_status,
                'links'     => $links,
                'postTypes' => $pt_list,
                'stats'     => array(
                    'postTypes'     => $post_stats,
                    'totals'        => $post_totals,
                    'linksSchedule' => $links_schedule,
                    'linkSnapshot'  => $link_snapshot,
                    'lastScan'      => array(
                        'timestamp' => $last_scan_timestamp,
                        'human'     => $last_scan_human_read,
                    ),
                ),
                'adminEmail' => sanitize_email( get_option( 'admin_email' ) ),
                'saved'     => array(
                    'postTypes' => get_option( 'td_blc_saved_post_types', array('post') ),
                    'setup'     => get_option( 'srk_setup', array() ),
                ),
            ) );
        });

        // ──────────────────────────────────────────────────────────────
        // Modal shell in admin footer (scoped, no admin UI interference)
        // Only add if onboarding hasn't been completed yet
        // ──────────────────────────────────────────────────────────────
        add_action( 'admin_footer', function(){
            // Check if onboarding is already completed - if so, skip adding modal HTML
            // This improves performance by not adding unnecessary DOM elements
            $srk_setup = get_option( 'srk_setup', array() );
            $onboarding_completed = ! empty( $srk_setup['completed'] ) && ! empty( $srk_setup['completed_at'] );
            
            // If onboarding is completed, don't add modal HTML at all (performance optimization)
            if ( $onboarding_completed ) {
                return;
            }
            ?>
            <div id="srkOnboardingOverlay" class="srk-hidden" aria-hidden="true"></div>
            <div id="srkOnboardingModal" class="srk-hidden" role="dialog" aria-modal="true" aria-labelledby="srkOnboardingTitle">
                <div class="srk-onboarding-container" role="document">
                    <div class="srk-onboarding-header">
                        <div class="srk-onboarding-brand" id="srkOnboardingTitle">
                            <img src="<?php echo esc_url( plugin_dir_url( __FILE__ ) . 'images/SEO-Repair-Kit-logo.svg' ); ?>" alt="SEO Repair Kit" class="srk-brand-logo" />
                            <span class="srk-brand-text">SEO Repair Kit</span>
                        </div>
                            <div class="srk-onboarding-progress" aria-label="<?php esc_attr_e('Onboarding steps','seo-repair-kit'); ?>">
                                <ul class="srk-onboarding-steps"></ul>
                            </div>
                            <button type="button" class="button-link srk-onboarding-close" aria-label="<?php esc_attr_e('Exit guided setup','seo-repair-kit'); ?>" style="display:none;"><?php esc_html_e('Exit Guided Setup','seo-repair-kit'); ?></button>
                        </div>
                        <main class="srk-onboarding-body" id="srkOnboardingBody" role="main"></main>
                    </div>
                </div>
            </div>
            
            <!-- Page Loader Overlay -->
            <div id="srk-page-loader-overlay" class="srk-page-loader-overlay" aria-hidden="true">
                <div class="srk-page-loader-container">
                    <div class="srk-page-loader-spinner"></div>
                    <p class="srk-page-loader-text"><?php esc_html_e( 'Loading...', 'seo-repair-kit' ); ?></p>
                </div>
            </div>
            <?php
        });

        // ── AJAX save (partial + final)
        add_action( 'wp_ajax_srk_setup_onboarding_save', array( $this, 'srk_setup_onboarding_save' ) );
        add_action( 'wp_ajax_srk_store_scan_stats', array( $this, 'srk_store_scan_stats' ) );
        add_action( 'wp_ajax_srk_save_consent', array( $this, 'srk_save_consent' ) );

		// Add REST endpoint for subscription redirection
		add_action('rest_api_init', function() {
			register_rest_route('srk/v1', '/trigger-subscribe', [
				'methods' => 'GET',
				'callback' => function($request) {
					$domain = $request->get_param('domain');
					return [
						'redirect' => home_url('/wp-json/srk/v1/redirect-to-laravel?domain=' . urlencode($domain))
					];
				},
				'permission_callback' => '__return_true'
			]);
			register_rest_route('srk/v1', '/redirect-to-laravel', [
				'methods' => 'GET',
				'callback' => function($request) {
					$domain = $request->get_param('domain');
					$laravel_url = SRK_API_Client::get_api_url( SRK_API_Client::ENDPOINT_TRIGGER_SUBSCRIBE, [ 'domain' => $domain ] );
					wp_redirect($laravel_url);
					exit;
				},
				'permission_callback' => '__return_true'
			]);
		});

        add_action('rest_api_init', function() {
            register_rest_route('srk/v1', '/redirect-to-plans', [
                'methods' => 'GET',
                'callback' => [$this, 'redirect_to_plans'],
                'permission_callback' => '__return_true'
            ]);
        });

		$this->seo_repair_kit = $seo_repair_kit;
		$this->version = $version;

		// The properties are now initialized inside the constructor.
		$this->crm_endpoint = SRK_API_Client::get_api_url( SRK_API_Client::ENDPOINT_LICENSE_VALIDATE );
		$this->crm_app_key  = SRK_API_APP_KEY;

		// CRM shared secret check (silent - admin notices handle missing config)

		require_once plugin_dir_path( dirname( __FILE__ ) ) . 'admin/class-seo-repair-kit-dashboard.php';
		require_once plugin_dir_path( dirname( __FILE__ ) ) . 'admin/class-seo-repair-kit-link-scanner.php';
		require_once plugin_dir_path( dirname( __FILE__ ) ) . 'admin/class-seo-repair-kit-scan-links.php';
		require_once plugin_dir_path( dirname( __FILE__ ) ) . 'admin/class-seo-repair-kit-keytrack.php';
		require_once plugin_dir_path( dirname( __FILE__ ) ) . 'admin/class-seo-repair-kit-alt-text.php';
		require_once plugin_dir_path( dirname( __FILE__ ) ) . 'admin/class-seo-repair-kit-redirection.php';
		require_once plugin_dir_path( dirname( __FILE__ ) ) . 'admin/class-seo-repair-kit-settings.php';
		require_once plugin_dir_path( dirname( __FILE__ ) ) . 'admin/class-seo-repair-kit-chatbot.php';
		require_once plugin_dir_path( dirname( __FILE__ ) ) . 'admin/class-seo-repair-kit-schema-manager.php';
		require_once plugin_dir_path( dirname( __FILE__ ) ) . 'admin/class-seo-repair-kit-schema-ui.php';
		require_once plugin_dir_path( dirname( __FILE__ ) ) . 'admin/class-seo-repair-kit-upgrade-pro.php';
		require_once plugin_dir_path( dirname( __FILE__ ) ) . 'admin/class-seo-repair-kit-404-manager.php';
		require_once plugin_dir_path( dirname( __FILE__ ) ) . 'admin/class-seo-repair-kit-robots-llms.php';
		// Instantiate Robots & LLMs class early to ensure AJAX handlers are registered (Bot Manager
        if ( ! isset( $GLOBALS['srkit_robots_llms'] ) ) {
			$GLOBALS['srkit_robots_llms'] = new SeoRepairKit_Robots_LLMs();
		}
		require_once plugin_dir_path( dirname( __FILE__ ) ) . 'admin/schema-generator/class-seo-repair-kit-faq-manager.php';
        require_once plugin_dir_path( dirname( __FILE__ ) ) . 'admin/schema-generator/class-srk-review-schema-generator.php';
        require_once plugin_dir_path( dirname( __FILE__ ) ) . 'admin/schema-generator/class-srk-recipe-schema-generator.php';
        require_once plugin_dir_path( dirname( __FILE__ ) ) . 'admin/schema-generator/class-srk-medicalcondition-schema-generator.php';
        require_once plugin_dir_path( dirname( __FILE__ ) ) . 'admin/schema-generator/class-srk-event-schema-generator.php';
        require_once plugin_dir_path( dirname( __FILE__ ) ) . 'admin/schema-generator/class-srk-jobposting-schema-generator.php';
        require_once plugin_dir_path( dirname( __FILE__ ) ) . 'admin/schema-generator/class-srk-course-schema-generator.php';
        require_once plugin_dir_path( dirname( __FILE__ ) ) . 'admin/schema-generator/class-seo-repair-kit-article-news-blog-schema.php';
        require_once plugin_dir_path( dirname( __FILE__ ) ) . 'admin/schema-generator/class-srk-product-schema.php';
		require_once plugin_dir_path( dirname( __FILE__ ) ) . 'includes/class-license-sync.php';
		require_once plugin_dir_path( dirname( __FILE__ ) ) . 'includes/class-seo-repair-kit-ajax-handlers.php';
		require_once plugin_dir_path( dirname( __FILE__ ) ) . 'includes/class-seo-repair-kit-schema-validator.php';
        require_once plugin_dir_path( dirname( __FILE__ ) ) . 'admin/class-seo-repair-kit-weekly-summary.php';
		SeoRepairKit_AjaxHandlers::register();
	}

	/**
     * Save onboarding data and APPLY it (called on Next and Finish).
     */
    public function srk_setup_onboarding_save() {
        if ( ! current_user_can( 'manage_options' ) ) {
            wp_send_json_error( array( 'message' => 'Permission denied' ), 403 );
        }
        check_ajax_referer( 'srk_onboarding_nonce', 'nonce' );

        $payload = isset($_POST['data']) ? wp_unslash($_POST['data']) : '';
        $final   = isset($_POST['final']) ? (int) $_POST['final'] : 0;

        $data = json_decode( $payload, true );
        if ( ! is_array( $data ) ) {
            wp_send_json_error( array( 'message' => 'Invalid data' ), 400 );
        }

        // ── Sanitize
        $clean = array();
        $clean['mode'] = in_array( $data['mode'] ?? 'easy', array('easy','advanced'), true ) ? $data['mode'] : 'easy';

        $pt = array();
        if ( ! empty( $data['postTypes'] ) && is_array( $data['postTypes'] ) ) {
            $allowed = wp_list_pluck( get_post_types( array('public'=>true), 'objects' ), 'name' );
            foreach ( $data['postTypes'] as $p ) {
                $p = sanitize_key( $p );
                if ( in_array( $p, $allowed, true ) ) { $pt[] = $p; }
            }
        }
        $clean['post_types'] = $pt;

        $clean['enable_keytrack'] = ! empty( $data['keytrackEnabled'] ) ? 1 : 0;
        $clean['links_schedule']  = in_array( $data['links_schedule'] ?? 'manual', array('manual','weekly','monthly'), true )
            ? $data['links_schedule'] : 'manual';

        $schema_defaults = array();
        if ( ! empty( $data['schemaTypes'] ) && is_array( $data['schemaTypes'] ) ) {
            foreach ( $data['schemaTypes'] as $k ) {
                $k = sanitize_key( $k );
                $schema_defaults[ $k ] = 1;
            }
        }
        // Also check old format for backward compatibility
        foreach ( array('article','faq','howto','product','video','job') as $k ) {
            if ( ! isset( $schema_defaults[ $k ] ) ) {
                $schema_defaults[ $k ] = ! empty( $data['schema_defaults'][ $k ] ) ? 1 : 0;
            }
        }
        $clean['schema_defaults'] = $schema_defaults;
        
        // Handle notifications
        if ( ! empty( $data['notifications'] ) && is_array( $data['notifications'] ) ) {
            $clean['weekly_report'] = ! empty( $data['notifications']['weeklyReport'] ) ? 1 : 0;
            $clean['keytrack_alerts'] = ! empty( $data['notifications']['keytrackAlerts'] ) ? 1 : 0;
            $clean['broken_links_notify'] = ! empty( $data['notifications']['brokenLinks'] ) ? 1 : 0;
        }
        
        // Handle email
        if ( ! empty( $data['email'] ) && is_email( $data['email'] ) ) {
            $clean['notification_email'] = sanitize_email( $data['email'] );
        }
        $clean['pro_intent']      = ! empty( $data['pro_intent'] ) ? 1 : 0;
        $clean['alt_scan']      = ! empty( $data['alt_scan'] ) ? 1 : 0;
        $clean['redir_enabled'] = ! empty( $data['redir_enabled'] ) ? 1 : 0;
        $clean['redir_default'] = in_array( $data['redir_default'] ?? '301', array('301','302'), true ) ? $data['redir_default'] : '301';

        // Site info consent (default ON for first run, otherwise from stored option).
        $previous_consent_option = get_option( 'srk_site_info_consent', 1 );
        $previous_consent = ( (int) $previous_consent_option === 1 );

        // If key present in payload, respect it; otherwise fall back to stored option (no silent flip to 1).
        if ( array_key_exists( 'site_info_consent', $data ) ) {
            $clean['site_info_consent'] = ! empty( $data['site_info_consent'] ) ? 1 : 0;
        } else {
            $clean['site_info_consent'] = $previous_consent ? 1 : 0;
        }

        if ( $final ) {
            $clean['completed']    = 1;
            $clean['completed_at'] = current_time( 'mysql' );
        }

        // ── Apply to individual options your plugin can read right now
        update_option( 'td_blc_saved_post_types',          $clean['post_types'] );
        update_option( 'srk_keytrack_enabled',             $clean['enable_keytrack'] );
        update_option( 'srk_links_schedule',               $clean['links_schedule'] );
        update_option( 'srk_schema_defaults',              $clean['schema_defaults'] );
        update_option( 'srk_pro_intent',                   $clean['pro_intent'] );
        update_option( 'srk_alt_scan_enabled',             $clean['alt_scan'] );
        update_option( 'srk_redirection_enabled',          $clean['redir_enabled'] );
        update_option( 'srk_redirection_default_code',     $clean['redir_default'] );
        update_option( 'srk_site_info_consent',            $clean['site_info_consent'] );

        // Update consent in database table
        require_once plugin_dir_path( dirname( __FILE__ ) ) . 'includes/class-seo-repair-kit-activator.php';
        $new_consent = ( $clean['site_info_consent'] === 1 );
        SeoRepairKit_Activator::update_site_info_consent( $new_consent );

        // On final onboarding step, attempt to send site health info once more.
        // srk_send_site_health_info() will read the latest consent from DB via
        // get_site_info_consent() and will abort if consent is not granted.
        if ( $final ) {
            SeoRepairKit_Activator::srk_send_site_health_info();
        }
        // Save notification settings
        if ( isset( $clean['weekly_report'] ) ) {
            update_option( 'srk_weekly_report_enabled', $clean['weekly_report'] );
        }
        if ( isset( $clean['keytrack_alerts'] ) ) {
            update_option( 'srk_keytrack_alerts_enabled', $clean['keytrack_alerts'] );
        }
        if ( isset( $clean['broken_links_notify'] ) ) {
            update_option( 'srk_broken_links_notify_enabled', $clean['broken_links_notify'] );
        }
        if ( ! empty( $clean['notification_email'] ) ) {
            update_option( 'srk_notification_email', $clean['notification_email'] );
        }

        // ── Keep also a consolidated snapshot
        $existing = get_option( 'srk_setup', array() );
        update_option( 'srk_setup', array_merge( (array) $existing, $clean ) );

        // Ensure onboarding never auto-runs again after a completion save.
        if ( $final ) {
            update_option( 'srk_onboarding_has_run', 1 );
        }

        // ── Apply schedules (create/update/remove cron event)
        $this->apply_links_schedule( $clean['links_schedule'] );

        wp_send_json_success( array( 'ok' => true, 'final' => (bool)$final ) );
    }

    public function srk_store_scan_stats() {
        if ( ! current_user_can( 'manage_options' ) ) {
            wp_send_json_error( array( 'message' => __( 'Permission denied.', 'seo-repair-kit' ) ), 403 );
        }

        check_ajax_referer( 'srk_scan_summary', 'nonce' );

        $total_links   = isset( $_POST['total_links'] ) ? max( 0, (int) $_POST['total_links'] ) : 0;
        $broken_links  = isset( $_POST['broken_links'] ) ? max( 0, (int) $_POST['broken_links'] ) : 0;
        $working_links = isset( $_POST['working_links'] ) ? max( 0, (int) $_POST['working_links'] ) : max( 0, $total_links - $broken_links );
        $post_type_raw = isset( $_POST['post_type'] ) ? wp_unslash( $_POST['post_type'] ) : '';
        $post_type     = $post_type_raw ? sanitize_key( $post_type_raw ) : '';
        $timestamp     = current_time( 'timestamp' );

        $snapshot = get_option( 'srk_last_links_snapshot', array() );
        if ( ! is_array( $snapshot ) ) {
            $snapshot = array();
        }

        $snapshot = array_merge(
            array(
                'totalLinks'   => 0,
                'brokenLinks'  => 0,
                'workingLinks' => 0,
                'timestamp'    => 0,
                'postTypes'    => array(),
                'scannedCount' => 0,
            ),
            $snapshot
        );

        if ( $post_type ) {
            $snapshot['postTypes'][ $post_type ] = array(
                'totalLinks'   => $total_links,
                'brokenLinks'  => $broken_links,
                'workingLinks' => $working_links,
                'timestamp'    => $timestamp,
            );
        }

        $aggregate_total  = 0;
        $aggregate_broken = 0;
        foreach ( $snapshot['postTypes'] as $type_stats ) {
            $aggregate_total  += isset( $type_stats['totalLinks'] ) ? (int) $type_stats['totalLinks'] : 0;
            $aggregate_broken += isset( $type_stats['brokenLinks'] ) ? (int) $type_stats['brokenLinks'] : 0;
        }

        $snapshot['totalLinks']   = $aggregate_total;
        $snapshot['brokenLinks']  = $aggregate_broken;
        $snapshot['workingLinks'] = max( 0, $aggregate_total - $aggregate_broken );
        $snapshot['timestamp']    = $timestamp;
        $snapshot['scannedCount'] = isset( $snapshot['scannedCount'] ) ? (int) $snapshot['scannedCount'] + 1 : 1;
        if ( $post_type ) {
            $snapshot['lastPostType'] = $post_type;
        }

        update_option( 'srk_last_links_snapshot', $snapshot );
        update_option( 'srk_last_links_scan_at', $timestamp );

        wp_send_json_success( $snapshot );
    }

    /**
     * Save consent preference immediately when user unchecks the consent checkbox.
     * This prevents onboarding from triggering on future activations if consent is denied.
     * If consent is granted, sends site health info to API.
     *
     * @since 2.1.0
     * @return void
     */
     public function srk_save_consent() {
        if ( ! current_user_can( 'manage_options' ) ) {
            wp_send_json_error( array( 'message' => 'Permission denied' ), 403 );
        }
        check_ajax_referer( 'srk_onboarding_nonce', 'nonce' );
        require_once plugin_dir_path( dirname( __FILE__ ) ) . 'includes/class-seo-repair-kit-activator.php';

        // Get previous consent from WordPress option (more reliable for comparison)

        $previous_consent_option = get_option( 'srk_site_info_consent' );
        $previous_consent = ( $previous_consent_option === 1 );

        $consent = isset( $_POST['consent'] ) ? (int) $_POST['consent'] : 1;
        $new_consent = ( $consent === 1 );

        // Update consent in both database table and WordPress option
        SeoRepairKit_Activator::update_site_info_consent( $new_consent );
        update_option( 'srk_site_info_consent', $consent ? 1 : 0 );

        // If user grants consent (checks the box), send site health info
        // This ensures site health info is sent when user explicitly checks the checkbox
        if ( $new_consent ) {
            // Only send if consent was just granted (changed from denied to granted)
            // This prevents duplicate sends when checkbox is already checked
            if ( ! $previous_consent ) {
                SeoRepairKit_Activator::srk_send_site_health_info();
                update_option( 'srk_site_health_info_sent', true );
            }
        } else {
            // If consent is denied, clear the sent flag
            delete_option( 'srk_site_health_info_sent' );
        }

        wp_send_json_success( array( 'ok' => true, 'consent' => $consent ) );
    }

    /**
     * Create, update, or remove the Broken Links cron event based on selection.
     */
    private function apply_links_schedule( $schedule ) {
        $hook = 'srk_broken_links_scan_event';

        // Clear any existing
        $timestamp = wp_next_scheduled( $hook );
        if ( $timestamp ) {
            wp_unschedule_event( $timestamp, $hook );
        }

        if ( $schedule === 'manual' ) {
            return; // none scheduled
        }

        $recurrence = 'weekly';
        if ( $schedule === 'weekly' ) {
            $recurrence = 'weekly';
        } elseif ( $schedule === 'monthly' ) {
            if ( ! in_array( 'monthly', array_keys( wp_get_schedules() ), true ) ) {
                add_filter( 'cron_schedules', function( $schedules ) {
                    if ( ! isset( $schedules['monthly'] ) ) {
                        $schedules['monthly'] = array(
                            'interval' => 30 * DAY_IN_SECONDS,
                            'display'  => __( 'Once Monthly', 'seo-repair-kit' ),
                        );
                    }
                    return $schedules;
                } );
            }
            $recurrence = 'monthly';
        }
        if ( ! wp_next_scheduled( $hook ) ) {
            wp_schedule_event( time() + HOUR_IN_SECONDS, $recurrence, $hook );
        }
    }
	
	function srk_check_and_store_license_after_payment() {
		if (isset($_GET['srk_license_synced']) && $_GET['srk_license_synced'] === 'true') {
			add_action('admin_notices', function () {
				echo '<div class="notice notice-success is-dismissible"><p>License synced successfully. Schema Manager is now active!</p></div>';
			});
			$this->get_license_status(site_url());
		}
	}

	/**
     * Validate license against CRM and cache the result.
     * - Keeps existing behavior: verifies HMAC signature, caches success for 1 hour.
     * - Replaces undefined _decode/_encode with WP-safe JSON helpers.
     */
    function srk_validate_and_cache_license() {
        $license_key = get_option( 'srk_pro_license_key' );
        if ( empty( $license_key ) ) {
            return;
        }

        $api_url = SRK_API_Client::get_api_url( SRK_API_Client::ENDPOINT_LICENSE_VALIDATE );

        $response = wp_remote_post(
            $api_url,
            array(
                'body'    => array(
                    'license_key' => $license_key,
                    'domain'      => site_url(),
                ),
                'timeout' => 15,
                'headers' => array(
                    'Accept' => 'application/json',
                ),
            )
        );

        if ( is_wp_error( $response ) ) {
            return;
        }

        $code = (int) wp_remote_retrieve_response_code( $response );
        if ( $code < 200 || $code >= 300 ) {
            return;
        }

        $body = wp_remote_retrieve_body( $response );
        if ( '' === $body ) {
            return;
        }

        // Replaces `_decode($body, true)`
        $data = json_decode( $body, true );
        if ( JSON_ERROR_NONE !== json_last_error() || ! is_array( $data ) ) {
            return;
        }

        // Basic shape guard.
        if (
            empty( $data['data'] ) ||
            ! is_array( $data['data'] ) ||
            empty( $data['signature'] )
        ) {
            return;
        }

        // Must be explicitly active:true.
        if ( empty( $data['data']['active'] ) || true !== $data['data']['active'] ) {
            // Optionally cache a negative result to avoid hammering the API.
            // set_transient( 'srk_pro_license_status', false, HOUR_IN_SECONDS );
            return;
        }

        // Replaces `_encode($data['data'])` – ensure IDENTICAL encoding to server.
        // If your server signs the raw JSON (no spaces), wp_json_encode matches PHP's json_encode with sane defaults.
        $payload         = wp_json_encode( $data['data'], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE );
        $local_signature = hash_hmac( 'sha256', $payload, SRK_SHARED_SECRET );

        if ( hash_equals( (string) $data['signature'], (string) $local_signature ) ) {
            // Save success state for 1 hour.
            set_transient( 'srk_pro_license_status', true, HOUR_IN_SECONDS );
            update_option( 'srk_last_successful_check', time() );
        }
    }


	public function redirect_to_plans($request) {
        $domain = $request->get_param('domain');
		$crm_url = SRK_API_Client::get_api_url( SRK_API_Client::ENDPOINT_SUBSCRIBE, [ 'domain' => $domain ] );
        wp_redirect($crm_url);
        exit;
    }

	/**
     * Register the stylesheets for the admin area.
     *
     * @since    1.0.1
     */
    public function enqueue_styles() {

		// Register Admin CSS File
		wp_register_style( 'srk-admin-style', plugin_dir_url( __FILE__ ) . 'css/seo-repair-kit-admin.css', array(), $this->version, 'all' );
		
		// Register Dashboard CSS File
        wp_register_style( 'srk-dashboard-style', plugin_dir_url( __FILE__ ) . 'css/seo-repair-kit-dashboard.css', array(), $this->version, 'all' );
		
		// Register Scan Links CSS File
        wp_register_style( 'srk-scan-links-style', plugin_dir_url( __FILE__ ) . 'css/seo-repair-kit-scan-links.css', array(), $this->version, 'all' );

		// Register keytrack CSS File
        wp_register_style( 'srkit-keytrack-style', plugin_dir_url( __FILE__ ) . 'css/seo-repair-kit-keytrack.css', array(), $this->version, 'all' );

		// Register Alt Text CSS File
        wp_register_style( 'srk-alt-text-style', plugin_dir_url( __FILE__ ) . 'css/seo-repair-kit-alt-text.css', array(), $this->version, 'all' );

		// Register Redirection CSS File
        wp_register_style( 'srk-redirection-style', plugin_dir_url( __FILE__ ) . 'css/seo-repair-kit-redirection.css', array(), $this->version, 'all' );
        wp_register_style( 'srk-404-manager-style', plugin_dir_url( __FILE__ ) . 'css/seo-repair-kit-404-manager.css', array(), $this->version, 'all' );
        wp_register_script( 'srk-404-manager-script', plugin_dir_url( __FILE__ ) . 'js/seo-repair-kit-404-manager.js', array( 'jquery' ), $this->version, true );

		// Register Settings CSS File
        wp_register_style( 'srk-settings-style', plugin_dir_url( __FILE__ ) . 'css/seo-repair-kit-settings.css', array(), $this->version, 'all' );

		// Register Chatbot CSS File
        wp_register_style( 'srk-chatbot-style', plugin_dir_url( __FILE__ ) . 'css/seo-repair-kit-chatbot.css', array(), $this->version, 'all' );

		// Register Schema Manager CSS File
        wp_register_style( 'srk-schema-manager-style', plugin_dir_url( __FILE__ ) . 'css/seo-repair-kit-schema-manager.css', array(), $this->version, 'all' );
		
		// Register Page Loader CSS File
        wp_register_style( 'srk-page-loader-style', plugin_dir_url( __FILE__ ) . 'css/srk-page-loader.css', array(), $this->version, 'all' );
		
		// Enqueue Admin CSS File
		wp_enqueue_style( 'srk-admin-style' );
		
		// Enqueue Page Loader CSS on SRK pages
		if ( $this->is_srk_admin_page() ) {
			wp_enqueue_style( 'srk-page-loader-style' );
		}
    }

	/**
     * Display SEO Repair Kit Notice on WordPress Dashboard and Plugin Subpages
	 * @since    2.0.0
     */
	public function display_seo_repair_kit_notice() {
		global $wpdb;
		
		// Cache table existence checks to avoid duplicate queries
		$cache_key = 'srk_required_tables_check';
		$missing_tables = get_transient( $cache_key );
		
		// If cache doesn't exist or is false, check tables
		if ( false === $missing_tables ) {
			// List of required tables
			$required_tables = [
				$wpdb->prefix . 'srkit_redirection_table',
				$wpdb->prefix . 'srkit_keytrack_settings',
				$wpdb->prefix . 'srkit_gsc_data'
			];
		
			// Check for missing tables
			$missing_tables = [];
			foreach ( $required_tables as $table_name ) {
				if ( $wpdb->get_var( $wpdb->prepare( "SHOW TABLES LIKE %s", $table_name ) ) != $table_name ) {
					$missing_tables[] = $table_name;
				}
			}
			
			// Cache for 5 minutes to reduce database queries
			set_transient( $cache_key, $missing_tables, 5 * MINUTE_IN_SECONDS );
		}
	
		// If any table is missing, display the notice
		if ( ! empty( $missing_tables ) ) {
			$screen = get_current_screen();
	
			// Check if we are on the dashboard or plugin page before displaying the notice
			if ( $screen->id === 'dashboard' || $screen->parent_base === 'seo-repair-kit-dashboard' ) {
				?>
				<div class="notice notice-info is-dismissible">
					<h2>SEO Repair Kit database update required</h2>
					<p>To keep your website's SEO in top shape, we need to update your settings to the latest version. This process will run in the background and may take a few moments. Please sit tight, and thank you for your patience as we optimize your site for the best performance.</p>
					<p>
						<a href="<?php echo esc_url( admin_url( 'admin-post.php?action=srkit_update_settings' ) ); ?>" class="button button-primary">Update Settings</a>
					</p>
				</div>
				<?php
			}
		}
	}

	/**
     * Handle Update Settings Action
     *
     * This function is triggered when the "Update Settings" button is clicked.
	 * @since    2.0.0
     */
    public function handle_update_settings() {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( __( 'You do not have sufficient permissions to access this page.' ) );
		}

		// Call the public wrapper method, which internally uses private methods.
		require_once plugin_dir_path( dirname( __FILE__ ) ) . 'includes/class-seo-repair-kit-activator.php';
		SeoRepairKit_Activator::activate();

		// Clear all related caches after table creation/update
		delete_transient( 'srk_required_tables_check' );
		delete_transient( 'srk_404_table_exists' );
		delete_transient( 'srk_404_statistics' );
		delete_transient( 'srk_table_creation_check' );

		// Redirect back after completing the action.
		wp_redirect( admin_url() );
		exit;
	}

	/**
	 * Register the JavaScript for the admin area.
	 *
	 * @since    1.0.1
	 */
	public function enqueue_scripts() {
		// Only load admin.js on SRK pages (it's mostly empty but registered for consistency)
		if ( $this->is_srk_admin_page() ) {
			wp_enqueue_script( $this->seo_repair_kit, plugin_dir_url( __FILE__ ) . 'js/seo-repair-kit-admin.js', array( 'jquery' ), $this->version, false );
		}

		// Only enqueue chatbot scripts on chatbot page
		$screen = get_current_screen();
		if ( $screen && strpos($screen->id, 'srk-ai-chatbot') !== false ) {
			wp_enqueue_script( 'srk-chatbot-script', plugin_dir_url( __FILE__ ) . 'js/seo-repair-kit-chatbot.js', array(), $this->version, true );
		}
		
		// Enqueue Page Loader JS on SRK pages only (defer to avoid blocking)
		if ( $this->is_srk_admin_page() ) {
			wp_enqueue_script( 'srk-page-loader-script', plugin_dir_url( __FILE__ ) . 'js/srk-page-loader.js', array(), $this->version, true );
			
			// Add inline script to inject loader HTML early if not present
			$inline_script = "
			(function() {
				if (!document.getElementById('srk-page-loader-overlay')) {
					// ✅ FIX: Wait for body to be available before appending
					function injectLoader() {
						var body = document.body || document.getElementsByTagName('body')[0];
						// ✅ FIX: Double-check body exists and has appendChild method
						if (body && typeof body.appendChild === 'function') {
							try {
								var loader = document.createElement('div');
								loader.id = 'srk-page-loader-overlay';
								loader.className = 'srk-page-loader-overlay';
								loader.setAttribute('aria-hidden', 'true');
								loader.innerHTML = '<div class=\"srk-page-loader-container\"><div class=\"srk-page-loader-spinner\"></div><p class=\"srk-page-loader-text\">Loading...</p></div>';
								document.body.appendChild(loader);
							} catch (e) {
								// Silently fail if appendChild fails
								console.warn('SRK Page Loader: Could not inject loader', e);
							}
						} else {
							// Retry if body not ready yet
							if (document.readyState === 'loading') {
								document.addEventListener('DOMContentLoaded', injectLoader);
							} else {
								setTimeout(injectLoader, 10);
							}
						}
					}
					// Wait for DOM to be ready
					if (document.readyState === 'loading') {
						document.addEventListener('DOMContentLoaded', injectLoader);
					} else {
						injectLoader();
					}
				}
			})();
			";
			wp_add_inline_script( 'srk-page-loader-script', $inline_script, 'before' );
		}
	}
	
	/**
	 * Check if current page is an SRK admin page
	 * 
	 * @since 2.1.0
	 * @return bool
	 */
	private function is_srk_admin_page() {
		$screen = function_exists('get_current_screen') ? get_current_screen() : null;
		if ( ! $screen ) {
			return false;
		}

		$id     = isset($screen->id) ? $screen->id : '';
		$base   = isset($screen->base) ? $screen->base : '';
		$parent = isset($screen->parent_base) ? $screen->parent_base : '';
		$page   = isset($_GET['page']) ? sanitize_key($_GET['page']) : '';

		$srk_page_slugs = array(
			'seo-repair-kit-dashboard',
			'seo-repair-kit-link-scanner',
			'seo-repair-kit-keytrack',
			'srk-schema-manager',
			'srk-ai-chatbot',
			'alt-image-missing',
			'seo-repair-kit-redirection',
			'seo-repair-kit-settings',
			'seo-repair-kit-upgrade-pro',
		);

		$is_srk_by_screen =
			( $id === 'toplevel_page_seo-repair-kit-dashboard' ) ||
			( strpos( $id,  'seo-repair-kit-dashboard' ) !== false ) ||
			( strpos( $base,'seo-repair-kit-dashboard' ) !== false ) ||
			( $parent === 'seo-repair-kit-dashboard' );

		$is_srk_by_page = ( $page && in_array( $page, $srk_page_slugs, true ) );

		return ( $is_srk_by_screen || $is_srk_by_page );
	}

	/**
	 * Powered by Toronto Digits
	 *
	 * @param string $srkit_tdtext
	 * 
	 * @since    1.0.1
	 */
	public function powered_by_torontodigits( $srkit_tdtext ) {

		$srkit_tdscreen = get_current_screen();

		// Check if the current screen is a submenu page of the plugin
		if ( $srkit_tdscreen->parent_base === 'seo-repair-kit-dashboard' ) {
			$srkit_tdtext = sprintf(
				/* translators: %s: TorontoDigits website link and logo */
				'<div class="srk-powered-by-text" style="margin-bottom: -18px;">' . esc_html__( 'Powered By: %s', 'seo-repair-kit' ) . '</div>',
				'<a href="' . esc_url( 'https://www.torontodigits.com/' ) . '" target="_blank"><img style="max-width: 80px; height: 30px; margin-bottom: -8px;" src="' . untrailingslashit( plugins_url(  basename( plugin_dir_path( __DIR__ ) ), basename( __DIR__ ) ) ) . '/admin/images/torontodigits.png" alt="' . esc_html__( 'Powered By: TorontoDigits', 'seo-repair-kit' ) . '"></a>'
			);
		}		
		return $srkit_tdtext;
	}

	/**
	 * Display Custom Admin Navbar for the SEO Repair Kit Plugin
	 *
	 * This function displays a custom navigation bar on the admin pages related to
	 * the SEO Repair Kit plugin. The navbar shows the plugin name, help icon, and
	 * the current admin user's avatar and name.
	 * 
	 * @since    2.0.0
	 */
	public function display_seo_repair_kit_navbar() {
    // get screen safely
    $screen = function_exists('get_current_screen') ? get_current_screen() : null;
    if ( ! $screen ) {
        return;
    }

    $id     = isset($screen->id) ? $screen->id : '';
    $base   = isset($screen->base) ? $screen->base : '';
    $parent = isset($screen->parent_base) ? $screen->parent_base : '';
    $page   = isset($_GET['page']) ? sanitize_key($_GET['page']) : '';

    /**
     * Robust detection of *all* SRK plugin pages.
     * We positively match using any of:
     *  - Top-level dashboard screen id
     *  - Any screen id/base that contains "seo-repair-kit-dashboard"
     *  - Known SRK submenu page slugs in ?page=
     */
    $srk_page_slugs = array(
        'seo-repair-kit-dashboard',
        'seo-repair-kit-link-scanner',
        'seo-repair-kit-keytrack',
        'srk-schema-manager',
        'srk-ai-chatbot',
        'alt-image-missing',
        'seo-repair-kit-redirection',
        'seo-repair-kit-robots-llms',
        'seo-repair-kit-settings',
        'seo-repair-kit-upgrade-pro',
    );

    $is_srk_by_screen =
        ( $id === 'toplevel_page_seo-repair-kit-dashboard' ) ||
        ( strpos( $id,  'seo-repair-kit-dashboard' ) !== false ) ||
        ( strpos( $base,'seo-repair-kit-dashboard' ) !== false ) ||
        ( $parent === 'seo-repair-kit-dashboard' );

    $is_srk_by_page = ( $page && in_array( $page, $srk_page_slugs, true ) );

    if ( ! ( $is_srk_by_screen || $is_srk_by_page ) ) {
        return; // Not our pages — do nothing
    }

    // Navbar markup
    $current_user = wp_get_current_user();
    $admin_name   = esc_html( $current_user->display_name );
    $admin_avatar = esc_url( get_avatar_url( $current_user->ID ) );
    ?>
    <!-- SRK Admin Navbar -->
    <div class="srkit-gsc-navbar">
        <div class="srkit-gsc-brand">SEO Repair Kit</div>
        <div class="srkit-gsc-user-info">
            <div class="srkit-gsc-help-icon">?</div>
            <div class="srkit-gsc-user-icons">
                <img src="<?php echo $admin_avatar; ?>" alt="Admin Avatar" class="admin-avatar">
            </div>
            <span class="srkit-gsc-user-text"><?php echo $admin_name; ?></span>
        </div>
    </div>
    <?php
}


	/**
	 * seo repair kit menu page.
	 * 
	 * @since    1.0.1
	 */
	public function seo_repair_kit_menu_page() {
		/**
		 * SEO Repair Kit page.
		 * 
		 * @since    1.0.1
		 */
		add_menu_page(
			esc_html__( 'SEO Repair Kit', 'seo-repair-kit' ),
			esc_html__( 'SEO Repair Kit', 'seo-repair-kit' ),
			'manage_options',
			'seo-repair-kit-dashboard',
			array( $this, 'seorepairkit_dashboard_page' ),
			plugin_dir_url( __FILE__ ) . 'images/srk-logo-icon.svg',
			7
		);

		add_submenu_page(
			'seo-repair-kit-dashboard',
			esc_html__( 'Link Scanner', 'seo-repair-kit' ),
			esc_html__( 'Link Scanner', 'seo-repair-kit' ),
			'manage_options',
			'seo-repair-kit-link-scanner',
			array( $this, 'seorepairkit_link_scanner_page' )
		);

        /**
		 * Redirection page.
		 * 
		 * @since    1.0.1
		 */
		$srkit_redirection = new SeoRepairKit_Redirection();
		add_submenu_page(
			'seo-repair-kit-dashboard',
			esc_html__( 'Redirection', 'seo-repair-kit' ),
			esc_html__( 'Redirection', 'seo-repair-kit' ),
			'manage_options',
			'seo-repair-kit-redirection',
			array( $srkit_redirection, 'seorepairkit_redirection_page' )
		);

		/**
		 * Image Alt Missing page.
		 * 
		 * @since    1.0.1
		 */
		$alt_text_page = new SeoRepairKit_AltTextPage();
		add_submenu_page( 
			'seo-repair-kit-dashboard',
			esc_html__( 'Image Alt Missing', 'seo-repair-kit' ),
			esc_html__( 'Image Alt Missing', 'seo-repair-kit' ),
			'manage_options',
			'alt-image-missing',
			array( $alt_text_page, 'alt_image_missing_page' )
		);

        /**
		 * Schema Manager page.
		 * 
		 * @since    2.1.0
		 */
		add_submenu_page(
			'seo-repair-kit-dashboard',
			esc_html__( 'Schema Manager', 'seo-repair-kit' ),
			esc_html__( 'Schema Manager', 'seo-repair-kit' ),
			'manage_options',
			'srk-schema-manager',
			[ new SeoRepairKit_SchemaManager(), 'seo_repair_kit_schema_page' ]
		);

        /**
		 * KeyTrack page.
		 * 
		 * @since    2.0.0
		 */
		$srkit_keytrack = new SeoRepairKit_KeyTrack();
		add_submenu_page( 
			'seo-repair-kit-dashboard',
			esc_html__( 'KeyTrack', 'seo-repair-kit' ),
			esc_html__( 'KeyTrack', 'seo-repair-kit' ),
			'manage_options',
			'seo-repair-kit-keytrack',
			array( $srkit_keytrack, 'seorepairkit_keytrack_page' )
		);

        /**
		 * Robots.txt & LLMs.txt Manager page.
		 * 
		 * @since    2.1.1
		 */
		// Use global instance (already instantiated in enqueue_styles method)
		if ( ! isset( $GLOBALS['srkit_robots_llms'] ) ) {
			$GLOBALS['srkit_robots_llms'] = new SeoRepairKit_Robots_LLMs();
		}
		add_submenu_page(
			'seo-repair-kit-dashboard',
			esc_html__( 'Bot Manager', 'seo-repair-kit' ),
			esc_html__( 'Bot Manager', 'seo-repair-kit' ),
			'manage_options',
			'seo-repair-kit-robots-llms',
			array( $GLOBALS['srkit_robots_llms'], 'render_admin_page' )
		);

		/**
		 * AI Chatbot page.
		 * 
		 * @since    2.1.0
		 */
        $srkit_chatbot = new SeoRepairKit_Chatbot();
        add_submenu_page(
            'seo-repair-kit-dashboard',
            esc_html__('AI Chatbot', 'seo-repair-kit'),
            esc_html__('AI Chatbot', 'seo-repair-kit'),
            'manage_options',
            'srk-ai-chatbot',
            array($srkit_chatbot, 'render_chatbot_page')
        );

		/**
		 * Settings page.
		 * 
		 * @since    1.0.1
		 */
		$srkit_settingspage = new SeoRepairKit_Settings();
		add_submenu_page(
			'seo-repair-kit-dashboard',
			esc_html__( 'Settings', 'seo-repair-kit' ),
			esc_html__( 'Settings', 'seo-repair-kit' ),
			'manage_options',
			'seo-repair-kit-settings',
			array( $srkit_settingspage, 'seo_repair_kit_settings' )
		);

		/**
		 * Upgrade to Pro page.
		 * 
		 * @since    2.1.0
		 */
		$srkit_srkupgradepage = new SeoRepairKit_Upgrade();
		add_submenu_page(
			'seo-repair-kit-dashboard',
			esc_html__( 'Upgrade to Pro', 'seo-repair-kit' ),
			esc_html__( 'Upgrade to Pro', 'seo-repair-kit' ),
			'manage_options',
			'seo-repair-kit-upgrade-pro',
			array( $srkit_srkupgradepage, 'seo_repair_kit_upgrade_pro' )
		);

	}
	public function seorepairkit_dashboard_page() {
		global $seoRepairKitDashboard;
		if ( ! $seoRepairKitDashboard instanceof SeoRepairKit_Dashboard ) {
			$seoRepairKitDashboard = new SeoRepairKit_Dashboard();
		}
		$seoRepairKitDashboard->seorepairkit_dashboard_page();
	}

	public function seorepairkit_link_scanner_page() {
		global $seoRepairKitLinkScanner;
		if ( ! $seoRepairKitLinkScanner instanceof SeoRepairKit_LinkScanner ) {
			$seoRepairKitLinkScanner = new SeoRepairKit_LinkScanner();
		}
		$seoRepairKitLinkScanner->seorepairkit_link_scanner_page();
	}

	public function get_license_status($domain) {
        if ($this->cached_license_info !== null) return $this->cached_license_info;

        $cache_key = 'srk_license_status_' . md5($domain);
        $cached = get_transient($cache_key);
        if ($cached !== false) {
            $this->cached_license_info = $cached;
            return $cached;
        }

        $response = wp_remote_post($this->crm_endpoint, [
            'method' 	=> 'POST',
            'timeout' 	=> 15,
            'headers' 	=> [ 'Content-Type' => 'application/json' ],
            'body' 		=> json_encode(['domain' => $domain]),
        ]);

        if (is_wp_error($response)) {
            return $this->default_license_response('Request error: ' . $response->get_error_message());
        }

        $body = json_decode(wp_remote_retrieve_body($response), true);

        if (!isset($body['data'], $body['signature'])) {
            return $this->default_license_response('Invalid response from CRM. Missing data or signature.');
        }

        if (!$this->is_signature_valid($body['data'], $body['signature'])) {
            return $this->default_license_response('Signature mismatch.');
        }

        $license_info = [
            'status' 				=> $body['data']['active'] ? 'active' : 'inactive',
            'expires_at' 			=> $body['data']['expires_at'] ?? null,
            'plan_id' 				=> $body['data']['plan_id'] ?? null,
            'has_chatbot_feature' 	=> $body['data']['has_chatbot_feature'] ?? false,
            'license_key' 			=> $body['data']['license_key'] ?? null,
            'message' 				=> $body['data']['active'] ? 'License is active.' : 'License is inactive.',
        ];

        set_transient($cache_key, $license_info, HOUR_IN_SECONDS);
        $this->cached_license_info = $license_info;
        return $license_info;
    }

    /**
     * Validates the signature using HMAC SHA256.
     */
    private function is_signature_valid($data, $signature) {
        $key = $this->crm_app_key;
        if (strpos($key, 'base64:') === 0) {
            $key = base64_decode(substr($key, 7));
        }
        $calculated = hash_hmac('sha256', json_encode($data), $key);
        return hash_equals($calculated, $signature);
    }

    /**
     * Returns default license array.
     */
    private function default_license_response($message) {
        return [
            'status' 				=> 'inactive',
            'expires_at' 			=> null,
            'plan_id' 				=> null,
            'has_chatbot_feature' 	=> false,
            'license_key' 			=> null,
            'message' 				=> $message,
        ];
    }

	private function cache_and_return_default(bool $active, string $reason, string $cache_key): array
    {
        $default = [
            'status'               => 'inactive',
            'license_key'          => null,
            'plan_id'              => null,
            'expires_at'           => null,
            'has_chatbot_feature'  => false,
            'message'              => '[SRK] ' . $reason,
        ];

        set_transient($cache_key, $default, HOUR_IN_SECONDS);
        return $default;
    }

	/**
	 * Add page loader HTML to admin head for early availability
	 * 
	 * @since 2.1.0
	 */
	public function add_page_loader_html() {
		if ( ! $this->is_srk_admin_page() ) {
			return;
		}
		?>
		<!-- Page Loader Overlay - Early Injection -->
		<div id="srk-page-loader-overlay" class="srk-page-loader-overlay" aria-hidden="true">
			<div class="srk-page-loader-container">
				<div class="srk-page-loader-spinner"></div>
				<p class="srk-page-loader-text"><?php esc_html_e( 'Loading...', 'seo-repair-kit' ); ?></p>
			</div>
		</div>
		<style>
		/* Inline critical CSS for immediate loader display */
		/* Exclude WordPress admin sidebar and header */
		#srk-page-loader-overlay {
			position: fixed;
			top: 32px; /* WordPress admin bar height */
			left: 160px; /* WordPress admin sidebar width (default) */
			width: calc(100% - 160px); /* Full width minus sidebar */
			height: calc(100% - 32px); /* Full height minus admin bar */
			min-height: calc(100vh - 32px);
			background-color: rgba(255, 255, 255, 0.95);
			z-index: 999999;
			display: none;
			align-items: center;
			justify-content: center;
			opacity: 0;
			visibility: hidden;
		}
		/* Adjust for collapsed WordPress admin sidebar */
		body.folded #srk-page-loader-overlay,
		.folded #srk-page-loader-overlay {
			left: 36px;
			width: calc(100% - 36px);
		}
		/* Adjust for mobile/tablet WordPress admin bar */
		@media screen and (max-width: 782px) {
			#srk-page-loader-overlay {
				top: 46px;
				left: 0;
				width: 100%;
				height: calc(100% - 46px);
				min-height: calc(100vh - 46px);
			}
		}
		#srk-page-loader-overlay.active {
			display: flex;
			opacity: 1;
			visibility: visible;
		}
		</style>
		<script>
		// Early loader initialization - start tracking immediately
		(function() {
			if (typeof window.srkLoaderStartTime === 'undefined') {
				window.srkLoaderStartTime = (window.performance && window.performance.timing) ? window.performance.timing.navigationStart : Date.now();
			}
			
			// Don't show loader immediately - let the main script handle it
			// This prevents blinking on fast loads
		})();
		</script>
		<?php
	}

}