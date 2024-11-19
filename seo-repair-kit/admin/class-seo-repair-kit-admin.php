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
 * @version    2.0.0
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

		add_action( 'admin_notices', array( $this, 'display_seo_repair_kit_navbar' ) );

		$this->seo_repair_kit = $seo_repair_kit;
		$this->version = $version;

		/**
		 * The class responsible for Dashboard.
		 */
		require_once plugin_dir_path( dirname( __FILE__ ) ) . 'admin/class-seo-repair-kit-dashboard.php';

		/**
		 * The class responsible for scanning post types links.
		 */
		require_once plugin_dir_path( dirname( __FILE__ ) ) . 'admin/class-seo-repair-kit-scan-links.php';

		/**
		 * The class responsible for keytrack page.
		 */
		require_once plugin_dir_path( dirname( __FILE__ ) ) . 'admin/class-seo-repair-kit-keytrack.php';
		
		/**
		 * The class responsible for Image Alt Missing page.
		 */
		require_once plugin_dir_path( dirname( __FILE__ ) ) . 'admin/class-seo-repair-kit-alt-text.php';

		/**
		 * The class responsible for Redirection page.
		 */
		require_once plugin_dir_path( dirname( __FILE__ ) ) . 'admin/class-seo-repair-kit-redirection.php';

		/**
		 * The class responsible for settings page.
		 */
		require_once plugin_dir_path( dirname( __FILE__ ) ) . 'admin/class-seo-repair-kit-settings.php';

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

		// Register Settings CSS File
        wp_register_style( 'srk-settings-style', plugin_dir_url( __FILE__ ) . 'css/seo-repair-kit-settings.css', array(), $this->version, 'all' );

		// Enqueue Admin CSS File
		wp_enqueue_style( 'srk-admin-style' );
    }

	/**
     * Display SEO Repair Kit Notice on WordPress Dashboard and Plugin Subpages
	 * @since    2.0.0
     */
	public function display_seo_repair_kit_notice() {
		global $wpdb;
		// List of required tables
		$required_tables = [
			$wpdb->prefix . 'srkit_redirection_table',
			$wpdb->prefix . 'srkit_keytrack_settings',
			$wpdb->prefix . 'srkit_gsc_data'
		];
	
		// Check for missing tables
		$missing_tables = [];
		foreach ( $required_tables as $table_name ) {
			if ( $wpdb->get_var( "SHOW TABLES LIKE '{$table_name}'" ) != $table_name ) {
				$missing_tables[] = $table_name;
			}
		}
	
		// If any table is missing, display the notice
		if ( ! empty( $missing_tables ) ) {
			$screen = get_current_screen();
	
			// Check if we are on the dashboard or plugin page before displaying the notice
			if ( $screen->id === 'dashboard' || $screen->parent_base === 'seo-repair-kit-dashboard' ) {
				?>
				<div class="notice notice-info is-dismissible">
					<h2>SEO Repair Kit database update required</h2>
					<p>To keep your website’s SEO in top shape, we need to update your settings to the latest version. This process will run in the background and may take a few moments. Please sit tight, and thank you for your patience as we optimize your site for the best performance.</p>
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

		wp_enqueue_script( $this->seo_repair_kit, plugin_dir_url( __FILE__ ) . 'js/seo-repair-kit-admin.js', array( 'jquery' ), $this->version, false );
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
		// Get the current screen information
        $screen = get_current_screen();

		// Check if the current screen is a submenu page of the plugin
        if ( $screen->parent_base === 'seo-repair-kit-dashboard' ) {

			// Get the current user's information
            $current_user = wp_get_current_user();

			// Get the admin's display name to show in the navbar
            $admin_name = esc_html( $current_user->display_name );

			// Get the URL of the admin's avatar image
            $admin_avatar = esc_url( get_avatar_url( $current_user->ID ) );
            ?>

			<!-- Begin the SEO Repair Kit Navbar HTML -->
            <div class="srkit-gsc-navbar">
                <div class="srkit-gsc-brand">SEO Repair Kit</div>
                <div class="srkit-gsc-user-info">
                    <div class="srkit-gsc-help-icon">?</div>
                    <div class="srkit-gsc-user-icons">
                        <img src="<?php echo esc_url( $admin_avatar ); ?>" alt="Admin Avatar" class="admin-avatar">
                    </div>
                    <span class="srkit-gsc-user-text"><?php echo esc_html( $admin_name ); ?></span>
                </div>
            </div>
            <?php
        }
    }

	/**
	 * seo repair kit menu page.
	 * 
	 * @since    1.0.1
	 */
	public function seo_repair_kit_menu_page() {

		add_menu_page(
			esc_html__( 'SEO Repair Kit', 'seo-repair-kit' ),
			esc_html__( 'SEO Repair Kit', 'seo-repair-kit' ),
			'manage_options',
			'seo-repair-kit-dashboard',
			array( $this, 'seorepairkit_dashboard_page' ),
			plugin_dir_url( __FILE__ ) . 'images/srk-logo-icon.svg',
			7
		);

		// Create an instance of the keytrack Page class from version 2.0.0
		$srkit_keytrack = new SeoRepairKit_KeyTrack();
		add_submenu_page( 
			'seo-repair-kit-dashboard',
			esc_html__( 'KeyTrack', 'seo-repair-kit' ),
			esc_html__( 'KeyTrack', 'seo-repair-kit' ),
			'manage_options',
			'seo-repair-kit-keytrack',
			array( $srkit_keytrack, 'seorepairkit_keytrack_page' )
		);

		// Create an instance of the Alt Text Page class
		$alt_text_page = new SeoRepairKit_AltTextPage();
		add_submenu_page( 
			'seo-repair-kit-dashboard',
			esc_html__( 'Image Alt Missing', 'seo-repair-kit' ),
			esc_html__( 'Image Alt Missing', 'seo-repair-kit' ),
			'manage_options',
			'alt-image-missing',
			array( $alt_text_page, 'alt_image_missing_page' )
		);

		// Create an instance of the Redirection Page class
		$srkit_redirection = new SeoRepairKit_Redirection();
		add_submenu_page(
			'seo-repair-kit-dashboard',
			esc_html__( 'Redirection', 'seo-repair-kit' ),
			esc_html__( 'Redirection', 'seo-repair-kit' ),
			'manage_options',
			'seo-repair-kit-redirection',
			array( $srkit_redirection, 'seorepairkit_redirection_page' )
		);

		// Create an instance of the Alt Text Page class
		$srkit_settingspage = new SeoRepairKit_Settings();
		add_submenu_page(
			'seo-repair-kit-dashboard',
			esc_html__( 'Settings', 'seo-repair-kit' ),
			esc_html__( 'Settings', 'seo-repair-kit' ),
			'manage_options',
			'seo-repair-kit-settings',
			array( $srkit_settingspage, 'seo_repair_kit_settings' )
		);
	}
	public function seorepairkit_dashboard_page() {
		
		$srkit_linksdashboard = new SeoRepairKit_Dashboard();
		$srkit_linksdashboard->seorepairkit_dashboard_page();
	}
}
