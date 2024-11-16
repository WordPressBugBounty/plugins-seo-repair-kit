<?php
if ( ! defined( 'ABSPATH' ) ) {
    exit;
}
/**
 * SeoRepairKit_Dashboard class
 *
 * The SeoRepairKit_Dashboard class manages the SEO Repair Kit dashboard functionality.
 * This class is responsible for displaying the dashboard page, initiating scans, and handling AJAX requests.
 *
 * @link       https://seorepairkit.com
 * @since      1.0.1
 * @author     TorontoDigits <support@torontodigits.com>
 */
class SeoRepairKit_Dashboard {
    
    /**
     * SeoRepairKit_Dashboard constructor.
     */
    public function __construct()
    {
        add_action( 'wp_ajax_get_scan_links_dashboard', array( $this, 'srkit_get_scanlinks_dashboard_callback' ) );
        add_action( 'wp_ajax_nopriv_get_scan_links_dashboard', array( $this, 'srkit_get_scanlinks_dashboard_callback' ) );
    }
    /**
     * Displays the SEO Repair Kit dashboard page.
     * Includes a form to select post types and initiate a scan.
     */
    public function seorepairkit_dashboard_page()
    {
        // Enqueue Styles
        wp_enqueue_style( 'srk-dashboard-style' );
        wp_enqueue_style( 'srk-scan-links-style' );
        
        // Enqueue JavaScript
        wp_enqueue_script( 'seo-repair-kit-dashboard', plugin_dir_url( __FILE__ ) . 'js/seo-repair-kit-dashboard.js', array( 'jquery' ), '1.0.1', true );

        // Localize the script to pass PHP variables to JavaScript
        wp_localize_script( 'seo-repair-kit-dashboard', 'SeoRepairKitDashboardVars', array( 
                'ajaxurlsrkdashboard' => esc_url( admin_url( 'admin-ajax.php' ) ),
                'srkitdashboard_nonce' => wp_create_nonce( 'seorepairkitdashboard_ajaxnonce' ) 
            )
        );
        // Get the selected post types saved in the options
        $srkSelectedPostType = get_option( 'td_blc_saved_post_types', array() );

        // Add the default post type if none are selected
        if ( empty( $srkSelectedPostType ) ) {
            $srkSelectedPostType = array( 'post' );
        }
        ?>
        <div id="srk-dashboard">
            <div class="srk-header">
                <div class="srk-logo">
                <img class="srk-logo-dashboard" src="<?php echo esc_url( plugin_dir_url( __FILE__ ) . 'images/SEO-Repair-Kit-logo.svg' ); ?>" alt="<?php esc_attr_e( 'SEO Repair Kit Logo', 'seo-repair-kit' ); ?>" style="max-width: auto; max-height: 50px; margin-left: 20px;">
                </div>
                <h1 class="srk-logo-text" id="srk-name-heading"><?php esc_html_e( 'SEO Repair Kit', 'seo-repair-kit' ); ?></h1>
            </div>
            <h2 class="srk-dashboard-heading">
                <?php esc_html_e( 'Dashboard', 'seo-repair-kit' ); ?>
            </h2>

            <!-- Form to select post types and start the scan -->
            <form method="post" action="">
                <?php wp_nonce_field( 'srkSelectedPostType', 'srkSelectedPostType_nonce' ); ?>
                <label for="srk-post-type-dropdown">
                    <?php esc_html_e( 'Select Post Type:', 'seo-repair-kit' ); ?>
                </label>
                <select id="srk-post-type-dropdown" name="post_type_dropdown">
                    <?php
                    // Output options for each selected post type
                    foreach ( $srkSelectedPostType as $srkit_PostType ) {

                        // Get the post type object
                        $srkit_PostTypeObject = get_post_type_object( $srkit_PostType );

                        // Output the public name of the post type
                        if ( $srkit_PostTypeObject ) {
                            ?>
                            <option value="<?php echo esc_attr( $srkit_PostType ); ?>">
                                <?php echo esc_html( $srkit_PostTypeObject->labels->name ); ?>
                            </option>
                            <?php
                        }
                    }
                    ?>
                </select>
                <input type="submit" value="<?php esc_attr_e( 'Start Scan', 'seo-repair-kit' ); ?>" class="srk-dashboard-button" id="start-button" name="start_button">
            </form>

            <!-- Dashboard Loader container -->
            <div id="srk-loader-container" style="display: none;">
                <div class="srk-dashboard-loader-container">
                    <div class="srk-dashboard-loader"></div>
                </div>
            </div>
            <div id="scan-results">
                <!-- Results will be displayed here -->
            </div>
        </div>
        <?php
    }

    /**
     * Callback function for handling AJAX request to get scan links for the dashboard.
     * Checks nonce for security and calls the function to display scan results.
     */
    public function srkit_get_scanlinks_dashboard_callback()
    {
        check_ajax_referer( 'seorepairkitdashboard_ajaxnonce', 'srkitdashboard_nonce' );
        $srkit_scanDashboard = new SeoRepairKit_ScanLinks();
        $srkit_scanDashboard->seorepairkit_scanning_link();
        wp_die();
    }
}
// Create an instance of the class to initialize the actions
$seoRepairKitDashboard = new SeoRepairKit_Dashboard();
