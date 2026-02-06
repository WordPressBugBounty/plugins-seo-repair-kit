<?php
if ( ! defined( 'ABSPATH' ) ) {
    exit;
}
/**
 * SeoRepairKit_Settings class.
 *
 * The SeoRepairKit_Settings class manages the settings page for selecting post types.
 *
 * @link       https://seorepairkit.com
 * @since      1.0.1
 * @author     TorontoDigits <support@torontodigits.com>
 */
class SeoRepairKit_Settings {

    /**
     * Initialize the class.
     * Hooks into the 'admin_init' action to register post types settings.
     */
    public function __construct() {

        add_action( 'admin_init', array( $this, 'srkit_register_posttypes_settings' ) );
        add_action( 'admin_init', array( $this, 'srk_register_404_settings' ) );
        add_action( 'admin_init', array( $this, 'srk_register_weekly_report_settings' ) );
    }
    
    /**
     * Display settings page.
     * Outputs HTML for the settings page, including checkboxes for selecting post types.
     */
    public function seo_repair_kit_settings() {

        // Enqueue Style - only settings style needed for this page
        wp_enqueue_style( 'srk-settings-style' );

        $srkit_savedposttypes = get_option( 'td_blc_saved_post_types', array() );
        $srkit_publicposttypes = get_post_types( array( 'public' => true ), 'objects' );
        $monitoring_enabled   = get_option( 'srk_404_monitoring_enabled', true );
        $weekly_report_enabled = (bool) get_option( 'srk_weekly_report_enabled', true );
        $weekly_last_status    = get_option( 'srk_weekly_report_last_status', array() );

        // If no post types are selected, default to standard content types (Posts & Pages)
        if ( empty( $srkit_savedposttypes ) ) {
            $default_post_types = array( 'post', 'page' );
            foreach ( $default_post_types as $pt_slug ) {
                if ( post_type_exists( $pt_slug ) ) {
                    $srkit_savedposttypes[] = $pt_slug;
                }
            }
        }

        ?>
        <div class="srk-settings-wrapper">
            <!-- Hero Section -->
            <div class="srk-settings-hero">
                <div class="srk-settings-hero-content">
                    <div class="srk-settings-hero-icon">
                        <span class="dashicons dashicons-admin-generic"></span>
                    </div>
                    <div class="srk-settings-hero-text">
                        <h1><?php esc_html_e( 'Settings', 'seo-repair-kit' ); ?></h1>
                        <p><?php esc_html_e( 'Configure your SEO Repair Kit preferences. Choose which post types to scan and manage 404 error monitoring.', 'seo-repair-kit' ); ?></p>
                    </div>
                </div>
            </div>

            <div class="srk-settings-grid">
                <!-- Post Types Settings Card -->
                <div class="srk-settings-card">
                    <div class="srk-settings-card-header">
                        <div class="srk-settings-card-icon">
                            <span class="dashicons dashicons-admin-post"></span>
                        </div>
                        <div class="srk-settings-card-title">
                            <h2><?php esc_html_e( 'Post Types', 'seo-repair-kit' ); ?></h2>
                            <p><?php esc_html_e( 'Select which post types should be scanned for broken links.', 'seo-repair-kit' ); ?></p>
                        </div>
                    </div>
                    <div class="srk-settings-card-body">
                        <form method="post" action="options.php" class="srk-settings-form">
                            <?php settings_fields( 'srk_post_types_settings' ); ?>
                            <?php do_settings_sections( 'post_types_menu' ); ?>
                            
                            <div class="srk-checkbox-grid">
                                <?php foreach ( $srkit_publicposttypes as $srkit_settingsposttype ) : ?>
                                    <label class="srk-checkbox-item">
                                        <input type="checkbox" 
                                               name="td_blc_saved_post_types[]"
                                               value="<?php echo esc_attr( $srkit_settingsposttype->name ); ?>" 
                                               <?php checked( in_array( $srkit_settingsposttype->name, $srkit_savedposttypes ) ); ?>>
                                        <span class="srk-checkbox-custom"></span>
                                        <span class="srk-checkbox-label">
                                            <span class="srk-checkbox-title"><?php echo esc_html( $srkit_settingsposttype->label ); ?></span>
                                            <span class="srk-checkbox-slug"><?php echo esc_html( $srkit_settingsposttype->name ); ?></span>
                                        </span>
                                    </label>
                                <?php endforeach; ?>
                            </div>
                            
                            <?php wp_nonce_field( 'save_post_types', 'post_types_nonce' ); ?>
                            
                            <div class="srk-settings-card-footer">
                                <button type="submit" class="srk-settings-save-btn">
                                    <span class="dashicons dashicons-saved"></span>
                                    <?php esc_html_e( 'Save Post Types', 'seo-repair-kit' ); ?>
                                </button>
                            </div>
                        </form>
                    </div>
                </div>

                <!-- 404 Monitoring Settings Card -->
                <div class="srk-settings-card">
                    <div class="srk-settings-card-header">
                        <div class="srk-settings-card-icon srk-icon-warning">
                            <span class="dashicons dashicons-warning"></span>
                        </div>
                        <div class="srk-settings-card-title">
                            <h2><?php esc_html_e( '404 Error Monitoring', 'seo-repair-kit' ); ?></h2>
                            <p><?php esc_html_e( 'Automatically track and log 404 errors on your website.', 'seo-repair-kit' ); ?></p>
                        </div>
                    </div>
                    <div class="srk-settings-card-body">
                        <form method="post" action="options.php" class="srk-settings-form">
                            <?php settings_fields( 'srk_404_settings' ); ?>
                            <?php do_settings_sections( 'srk_404_settings_section' ); ?>
                            
                            <div class="srk-toggle-setting">
                                <label class="srk-toggle-switch">
                                    <input type="checkbox" 
                                           name="srk_404_monitoring_enabled" 
                                           id="srk_404_monitoring_enabled" 
                                           value="1" 
                                           <?php checked( $monitoring_enabled, true ); ?>>
                                    <span class="srk-toggle-slider"></span>
                                </label>
                                <div class="srk-toggle-content">
                                    <span class="srk-toggle-title"><?php esc_html_e( 'Enable 404 Monitoring', 'seo-repair-kit' ); ?></span>
                                    <span class="srk-toggle-description"><?php esc_html_e( 'When enabled, all 404 errors will be logged automatically for review in the 404 Monitor.', 'seo-repair-kit' ); ?></span>
                                </div>
                            </div>

                            <div class="srk-info-box">
                                <span class="dashicons dashicons-info-outline"></span>
                                <div class="srk-info-content">
                                    <strong><?php esc_html_e( 'How it works:', 'seo-repair-kit' ); ?></strong>
                                    <p><?php esc_html_e( 'The 404 Monitor tracks visitors who land on non-existent pages. You can then create redirects to guide them to the right content and preserve your SEO rankings.', 'seo-repair-kit' ); ?></p>
                                </div>
                            </div>
                            
                            <div class="srk-settings-card-footer">
                                <button type="submit" class="srk-settings-save-btn">
                                    <span class="dashicons dashicons-saved"></span>
                                    <?php esc_html_e( 'Save 404 Settings', 'seo-repair-kit' ); ?>
                                </button>
                            </div>
                        </form>
                    </div>
                </div>

                <!-- Weekly Report Email Settings Card -->
                <div class="srk-settings-card">
                    <div class="srk-settings-card-header">
                        <div class="srk-settings-card-icon">
                            <span class="dashicons dashicons-email"></span>
                        </div>
                        <div class="srk-settings-card-title">
                            <h2><?php esc_html_e( 'Weekly SEO Report Email', 'seo-repair-kit' ); ?></h2>
                            <p><?php esc_html_e( 'Enable or disable the automated SEO summary email and view the last send status.', 'seo-repair-kit' ); ?></p>
                        </div>
                    </div>
                    <div class="srk-settings-card-body">
                        <form method="post" action="options.php" class="srk-settings-form">
                            <?php settings_fields( 'srk_weekly_report_settings' ); ?>

                            <div class="srk-toggle-setting">
                                <label class="srk-toggle-switch">
                                    <input type="checkbox"
                                           name="srk_weekly_report_enabled"
                                           id="srk_weekly_report_enabled"
                                           value="1"
                                           <?php checked( $weekly_report_enabled, true ); ?>>
                                    <span class="srk-toggle-slider"></span>
                                </label>
                                <div class="srk-toggle-content">
                                    <span class="srk-toggle-title"><?php esc_html_e( 'Enable Weekly Report Email', 'seo-repair-kit' ); ?></span>
                                    <span class="srk-toggle-description">
                                        <?php esc_html_e( 'When enabled, SEO Repair Kit will send a summary email on the configured schedule.', 'seo-repair-kit' ); ?>
                                    </span>
                                </div>
                            </div>

                            <?php
                            $status_label = __( 'No weekly report has been sent yet.', 'seo-repair-kit' );
                            if ( ! empty( $weekly_last_status ) && is_array( $weekly_last_status ) ) {
                                $status  = isset( $weekly_last_status['status'] ) ? $weekly_last_status['status'] : '';
                                $message = isset( $weekly_last_status['message'] ) ? $weekly_last_status['message'] : '';
                                $time    = isset( $weekly_last_status['timestamp'] ) ? $weekly_last_status['timestamp'] : '';

                                $status_human = ucfirst( $status );
                                $time_text    = '';
                                if ( $time ) {
                                    $ts       = strtotime( $time );
                                    $time_ago = $ts ? human_time_diff( $ts, current_time( 'timestamp' ) ) : '';
                                    if ( $time_ago ) {
                                        /* translators: %s: human time diff */
                                        $time_text = sprintf( __( '%s ago', 'seo-repair-kit' ), $time_ago );
                                    }
                                }

                                $parts = array();
                                if ( $status_human ) {
                                    $parts[] = sprintf( __( 'Status: %s', 'seo-repair-kit' ), $status_human );
                                }
                                if ( $time_text ) {
                                    $parts[] = sprintf( __( 'Last sent: %s', 'seo-repair-kit' ), $time_text );
                                }
                                if ( $message ) {
                                    $parts[] = $message;
                                }

                                if ( ! empty( $parts ) ) {
                                    $status_label = implode( ' • ', array_map( 'esc_html', $parts ) );
                                }
                            }
                            ?>

                            <div class="srk-info-box">
                                <span class="dashicons dashicons-info-outline"></span>
                                <div class="srk-info-content">
                                    <strong><?php esc_html_e( 'Last weekly report status:', 'seo-repair-kit' ); ?></strong>
                                    <p><?php echo $status_label; // Already escaped above ?></p>
                                </div>
                            </div>

                            <div class="srk-settings-card-footer">
                                <button type="submit" class="srk-settings-save-btn">
                                    <span class="dashicons dashicons-saved"></span>
                                    <?php esc_html_e( 'Save Weekly Report Settings', 'seo-repair-kit' ); ?>
                                </button>
                            </div>
                        </form>
                    </div>
                </div>
            </div>
        </div>
        <?php
    }

    /**
     * Register settings.
     * Registers the post types settings to WordPress.
     */
    public function srkit_register_posttypes_settings() {

        register_setting( 
            'srk_post_types_settings',
            'td_blc_saved_post_types', 
            array( 
                'sanitize_callback' => array( $this, 'srkit_sanitize_posttypes' ), 
            )
        );
    }

    /**
     * Sanitize selected post types.
     * Ensures that only valid post types are saved.
     *
     * @param array $srkit_input Input values.
     * @return array Sanitized input values.
     */
    public function srkit_sanitize_posttypes( $srkit_input ) {
        
        $srkit_allposttypes = get_post_types( array( 'public' => true ), 'objects' );
        $srkit_allowedposttypes = wp_list_pluck( $srkit_allposttypes, 'name' );
        $srkit_selectedposttypes = is_array( $srkit_input ) ? $srkit_input : array();

        // Only allow post types that are in the list of all public post types
        $srkit_sanitizedposttypes = array_intersect( $srkit_selectedposttypes, $srkit_allowedposttypes );
        return $srkit_sanitizedposttypes;
    }

    /**
     * Register 404 monitoring settings.
     *
     * @since 2.1.0
     */
    public function srk_register_404_settings() {
        register_setting(
            'srk_404_settings',
            'srk_404_monitoring_enabled',
            array(
                'type' => 'boolean',
                'default' => true,
                'sanitize_callback' => 'rest_sanitize_boolean',
            )
        );

        add_settings_section(
            'srk_404_settings_section',
            '',
            null,
            'srk_404_settings_section'
        );
    }

    /**
     * Register weekly report email settings.
     *
     * @since 2.1.0
     */
    public function srk_register_weekly_report_settings() {
        register_setting(
            'srk_weekly_report_settings',
            'srk_weekly_report_enabled',
            array(
                'type'              => 'boolean',
                'default'           => true,
                'sanitize_callback' => 'rest_sanitize_boolean',
            )
        );
    }
}
