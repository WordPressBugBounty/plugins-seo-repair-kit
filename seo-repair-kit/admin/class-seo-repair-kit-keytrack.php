<?php
if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

require_once 'class-seo-repair-kit-keytrack-settings.php';

/**
 * SeoRepairKit_KeyTrack class.
 *
 * This class manages the integration of Google Search Console data with the SEO Repair Kit plugin,
 * including enqueuing scripts, handling plugin activation redirects, and displaying notifications.
 *
 * @link       https://seorepairkit.com
 * @since      2.0.0
 * @author     TorontoDigits <support@torontodigits.com>
 */

class SeoRepairKit_KeyTrack {

    /**
     * Constructor.
     *
     * Registers hooks for enqueuing scripts, handling redirects, setting transients, 
     * and AJAX actions.
     */
    public function __construct() {
        // Hook to enqueue scripts for the admin interface.
        add_action( 'admin_enqueue_scripts', array( $this, 'enqueue_scripts' ) );

        // Hook for redirecting to a specific page after plugin activation.
        add_action( 'admin_init', array( $this, 'handle_plugin_activation_redirect' ) );

        // Hook to set a transient indicating plugin activation.
        add_action( 'activated_plugin', array( $this, 'set_activation_transient' ), 10, 2 );

        // Hook to display a setup completion message in the admin footer.
        add_action( 'admin_footer', array( $this, 'check_sitekit_setup_success' ) );

        // Hook for AJAX action to set a transient for plugin installation tracking.
        add_action( 'wp_ajax_set_srk_transient_for_install', array( $this, 'set_srk_transient_for_install' ) );
    }

    /**
     * Enqueues necessary scripts and styles for the admin interface.
     *
     * This includes external libraries like Chart.js, Chosen.js, and Font Awesome.
     */
    public function enqueue_scripts() {
        // Enqueue Chart.js library only if it hasn't been registered.
        $srkit_kt_chart_js_url = 'https://cdn.jsdelivr.net/npm/chart.js';
        if ( filter_var( $srkit_kt_chart_js_url, FILTER_VALIDATE_URL ) ) {
            wp_enqueue_script( 'chart-js', $srkit_kt_chart_js_url, array(), '2.9.4', true );
        }

        // Enqueue Chosen.js for enhanced select elements.
        if ( ! wp_script_is( 'chosen-js', 'registered' ) ) {
            wp_enqueue_script( 'chosen-js', 'https://cdnjs.cloudflare.com/ajax/libs/chosen/1.8.7/chosen.jquery.min.js', array( 'jquery' ), '1.8.7', true );
            wp_enqueue_style( 'chosen-css', 'https://cdnjs.cloudflare.com/ajax/libs/chosen/1.8.7/chosen.min.css', array(), '1.8.7' );
        }

    }

    /**
     * Renders the keytrack settings page.
     *
     * This page displays Google Search Console data and checks if the Google Site Kit plugin
     * is active. It also provides options to install or activate Site Kit if not active.
     */
    public function SeoRepairKit_keytrack_page() {
        wp_enqueue_style( 'srkit-keytrack-style' );

        if ( ! current_user_can( 'manage_options' ) ) {
            wp_die( __( 'Unauthorized user.', 'seo-repair-kit' ) );
        }

        // Check if Google Site Kit plugin is active; if not, show instructions.
        if ( ! class_exists( 'Google\Site_Kit\Plugin' ) ) {
        // Start a main div for the notice section
        ?>
        <div class="srkit-kt-notice-message">
        <div class="srk-notification-header">
            <div class="srk-logo-header">
                <img class="srk-logo-dashboard" src="<?php echo esc_url( plugin_dir_url( __FILE__ ) . 'images/SEO-Repair-Kit-logo.svg' ); ?>" alt="<?php esc_attr_e( 'SEO Repair Kit', 'seo-repair-kit' ); ?>" style="max-width: auto; max-height: 50px; margin-right: 15px;">
                </div>
            <h1 class="srk-notification-header-text"><?php esc_html_e( 'SEO Repair Kit', 'seo-repair-kit' ); ?></h1>
        </div>
            
        <!-- Display the error notice with a link to install Google Site Kit -->
        <p class="srk-notice-message">
            <?php echo esc_html__( 'SEO Repair Kit plugin requires the Google Site Kit plugin to function properly. Please install and activate.', 'seo-repair-kit' ); ?>
        </p>
            
        <!-- Add bullet points explaining the steps -->
        <ul class="srkit-kt-steps">
            <li><?php esc_html_e( 'Install the Google Site Kit plugin.', 'seo-repair-kit' ); ?></li>
            <li><?php esc_html_e( 'Activate the Google Site Kit plugin.', 'seo-repair-kit' ); ?></li>
            <li><?php esc_html_e( 'Set up Google Site Kit to integrate with SEO Repair Kit.', 'seo-repair-kit' ); ?></li>
        </ul>

        <?php
        // Add Install and Activate button for Google Site Kit
        $srkit_sitekit_slug   = 'google-site-kit/google-site-kit.php';

        // Validate URLs with esc_url()
        $srkit_sitekit_install_url = esc_url( wp_nonce_url( self_admin_url( 'update.php?action=install-plugin&plugin=google-site-kit' ), 'install-plugin_google-site-kit' ) );
        $srkit_sitekit_activate_url  = wp_nonce_url( self_admin_url( 'plugins.php?action=activate&plugin=' . $srkit_sitekit_slug . '&plugin_status=all&paged=1&s' ), 'activate-plugin_' . $srkit_sitekit_slug );

        // Check if the plugin is not installed, show the install button
        if ( ! file_exists( WP_PLUGIN_DIR . '/google-site-kit/google-site-kit.php' ) ) {
            ?>
            <p>
                <a href="<?php echo esc_url( $srkit_sitekit_install_url ); ?>" class="srkit-kt-install-sitekit-btn"
                onclick="setSRKInstallAndRedirect('<?php echo esc_js( $srkit_sitekit_install_url ); ?>'); return false;">
                    <?php esc_html_e( 'Install & Activate Google Site Kit', 'seo-repair-kit' ); ?>
                </a>
            </p>
            <?php
        } elseif ( ! is_plugin_active( 'google-site-kit/google-site-kit.php' ) ) {
            ?>
            <p>
                <a href="<?php echo esc_url( $srkit_sitekit_activate_url ); ?>" class="srkit-kt-install-sitekit-btn"
                onclick="setSRKInstallAndRedirect('<?php echo esc_js( $srkit_sitekit_activate_url ); ?>'); return false;">
                    <?php esc_html_e( 'Activate Google Site Kit', 'seo-repair-kit' ); ?>
                </a>
            </p>
            <?php
        }
        ?>
        <script type="text/javascript">
            function setSRKInstallAndRedirect(srkitredirectUrl) {
                jQuery.ajax({
                    url: ajaxurl,
                    type: 'POST',
                    data: {
                        action: 'set_srk_transient_for_install',
                    },
                    success: function() {
                        // Redirect to the install or activate URL after setting the transient
                        window.location.href = srkitredirectUrl;
                    }
                });
            }
        </script>
        </div>
        <?php
        return;
        }

        // Sanitize the date range parameter from the URL or set a default value.
        $srkit_allowed_date_ranges = array( 'weekly', 'bi-weekly', '28days', '3months' );
        $srkit_overview_tab_date_range = isset( $_GET['date_range'] ) && in_array( $_GET['date_range'], $srkit_allowed_date_ranges, true ) ? sanitize_text_field( $_GET['date_range'] ) : '28days';

        // Determine the start date based on the selected date range.
        switch ( $srkit_overview_tab_date_range ) {
            case 'weekly':
                 $srkit_overview_tab_start_date = date( 'Y-m-d', strtotime( '-7 days' ) );
                break;
            case 'bi-weekly':
                 $srkit_overview_tab_start_date = date( 'Y-m-d', strtotime( '-14 days' ) );
                break;
            case '3months':
                 $srkit_overview_tab_start_date = date( 'Y-m-d', strtotime( '-3 months' ) );
                break;
            case '6months':
                 $srkit_overview_tab_start_date = date( 'Y-m-d', strtotime( '-6 months' ) );
                break;
            case '28days':
            default:
                 $srkit_overview_tab_start_date = date( 'Y-m-d', strtotime( '-28 days' ) );
                break;
        }

        // Set the end date to the current date.
        $srkit_overview_tab_end_date = date( 'Y-m-d' );

        // Fetch Google Site Kit plugin instances for data access.
        $srkit_sitekit_instance       = \Google\Site_Kit\Plugin::instance();
        $srkit_sitekit_context        = $srkit_sitekit_instance->context();
        $srkit_sitekit_options        = new \Google\Site_Kit\Core\Storage\Options( $srkit_sitekit_context );
        $srkit_sitekit_user_options   = new \Google\Site_Kit\Core\Storage\User_Options( $srkit_sitekit_context );
        $srkit_sitekit_authentication = new \Google\Site_Kit\Core\Authentication\Authentication( $srkit_sitekit_context, $srkit_sitekit_options, $srkit_sitekit_user_options );
        $srkit_sitekit_modules        = new \Google\Site_Kit\Core\Modules\Modules( $srkit_sitekit_context, $srkit_sitekit_options, $srkit_sitekit_user_options, $srkit_sitekit_authentication );
        $srkit_sitekit_search_console = $srkit_sitekit_modules->get_module( 'search-console' );

        // Initialize  $srkit_pages_data to null
        $srkit_pages_data = null;

        // Initialize the  $srkit_selected_days_data,  $srkit_pages_data, $srkit_queries_data and $srkit_settings_data to an empty array
        $srkit_selected_days_data = array();
        $srkit_pages_data         = array();
        $srkit_queries_data       = array();
        $srkit_settings_data      = array();

        // Initialize data arrays for overall performance, pages, queries, and settings
        $srkit_selected_days_data = $srkit_sitekit_search_console->get_data( 'searchanalytics', array(
            'dimensions' => array( 'date' ),
            'startDate'  =>  $srkit_overview_tab_start_date,
            'endDate'    =>  $srkit_overview_tab_end_date,
            'rowLimit'   => 100,
        ) );

        $srkit_pages_data = $srkit_sitekit_search_console->get_data( 'searchanalytics', array(
            'dimensions' => array( 'page' ),
            'startDate'  =>  $srkit_overview_tab_start_date,
            'endDate'    =>  $srkit_overview_tab_end_date,
            'rowLimit'   => 100,
        ) );

        $srkit_queries_data = $srkit_sitekit_search_console->get_data( 'searchanalytics', array(
            'dimensions' => array( 'query' ),
            'startDate'  =>  $srkit_overview_tab_start_date,
            'endDate'    =>  $srkit_overview_tab_end_date,
            'rowLimit'   => 100,
        ) );

        $srkit_settings_data = $srkit_sitekit_search_console->get_data( 'searchanalytics', array(
                'dimensions' => array( 'settings' ),
                'startDate'  => date( 'Y-m-d', strtotime( '-28 days' ) ),
                'endDate'    => date( 'Y-m-d' ),
                'rowLimit'   => 100,
            )
        );

        // Check if either $srkit_pages_data or $srkit_queries_data is an error before processing further
        if ( is_wp_error( $srkit_pages_data ) || is_wp_error( $srkit_queries_data ) ) {
            ?>
            <div class="srkit-kt-notice-message">
                <div class="srk-notification-header">
                    <div class="srk-logo-header">
                    <img class="srk-logo-dashboard" src="<?php echo esc_url( plugin_dir_url( __FILE__ ) . 'images/SEO-Repair-Kit-logo.svg' ); ?>" alt="<?php echo esc_attr__( 'SEO Repair Kit', 'seo-repair-kit' ); ?>" style="max-width: auto; max-height: 50px; margin-right: 15px;">
                    </div>
                    <h1 class="srk-notification-header-text"><?php esc_html_e( 'SEO Repair Kit', 'seo-repair-kit' ); ?></h1>
                </div>
                <div class="srk-message-notificationa">
                    <p class="srk-notice-message">
                       <?php esc_html_e( 'Search Console module is not connected. Please connect it through the Google Site Kit plugin.', 'seo-repair-kit' ); ?>
                    </p>
            
                    <!-- Add bullet points explaining the steps -->
                    <ul class="srkit-kt-steps">
                        <li><?php esc_html_e( 'Go to Google Site Kit Settings.', 'seo-repair-kit' ); ?></li>
                        <li><?php esc_html_e( 'Create/Connect Google Account.', 'seo-repair-kit' ); ?></li>
                        <li><?php esc_html_e( 'Once your account is connected, you can quickly use the SEO Repair Kit KeyTrack feature.', 'seo-repair-kit' ); ?></li>
                    </ul>
                    <p class="srk-notice-message">
                       <?php esc_html_e( '⚠️ Additional Message:', 'seo-repair-kit' ); ?>
                    </p>

                    <p class="srkit-kt-steps">
                        <?php esc_html_e( '🔄 If the Search Console settings are already configured but the KeyTrack feature is still not working, try disconnecting and then reconnecting your Google Site Kit account.', 'seo-repair-kit' ); ?>
                    </p>

                   <?php
                        // Add a button that links to the Google Site Kit dashboard and sets a transient
                        echo '<p><a href="' . esc_url( admin_url( 'admin.php?page=googlesitekit-dashboard' ) ) . '" class="srkit-kt-install-sitekit-btn" onclick="setSRKTransient()">' . esc_html__( 'Go to Site Kit Settings', 'seo-repair-kit' ) . '</a></p>';
                    ?>

                    <script type="text/javascript">
                        function setSRKTransient() {
                            jQuery.ajax({
                                url: ajaxurl,
                                type: 'POST',
                                data: {
                                    action: 'set_srk_transient_for_install'
                                }
                            });
                        }
                    </script>

                </div>
            </div>
            <?php
            return;
        }

        // Proceed with sorting pages data if Google Site Kit is connected
        if (  $srkit_pages_data ) {
            usort(
                 $srkit_pages_data,
                function ( $srkit_sort_a, $srkit_sort_b ) {
                    if ( $srkit_sort_a['clicks'] === $srkit_sort_b['clicks'] ) {
                        return $srkit_sort_b['impressions'] <=> $srkit_sort_a['impressions'];
                    }
                    return $srkit_sort_b['clicks'] <=> $srkit_sort_a['clicks'];
                }
            );
        }

        // Sort queries data similarly if Google Site Kit is connected
        if ( $srkit_queries_data ) {
            usort(
                $srkit_queries_data,
                function ( $srkit_sort_a, $srkit_sort_b ) {
                    if ( $srkit_sort_a['clicks'] === $srkit_sort_b['clicks'] ) {
                        return $srkit_sort_b['impressions'] <=> $srkit_sort_a['impressions'];
                    }
                    return $srkit_sort_b['clicks'] <=> $srkit_sort_a['clicks'];
                }
            );
        }

        // Get top 5 pages and queries.
        $srkit_top_pages   = array_slice(  $srkit_pages_data, 0, 5 );
        $srkit_top_queries = array_slice( $srkit_queries_data, 0, 5 );

        // Prepare data for display.
        $srkit_kt_data_array         = array();
        $srkit_kt_total_clicks       = 0;
        $srkit_kt_total_impressions  = 0;
        $srkit_kt_total_ctr          = 0;
        $srkit_kt_total_position     = 0;

        foreach ( $srkit_selected_days_data as $srkit_kt_row ) {
            $srkit_kt_total_clicks       += $srkit_kt_row['clicks'];
            $srkit_kt_total_impressions  += $srkit_kt_row['impressions'];
            $srkit_kt_total_ctr          += $srkit_kt_row['ctr'];
            $srkit_kt_total_position     += $srkit_kt_row['position'];

            $srkit_kt_data_array[] = array(
                'date'          => $srkit_kt_row['keys'][0],
                'clicks'        => $srkit_kt_row['clicks'],
                'impressions'   => $srkit_kt_row['impressions'],
                'ctr'           => $srkit_kt_row['ctr'],
                'position'      => $srkit_kt_row['position'],
            );
        }

        $srkit_kt_average_ctr      = count( $srkit_selected_days_data ) ? ( $srkit_kt_total_ctr / count( $srkit_selected_days_data ) ) : 0;
        $srkit_kt_average_position = count( $srkit_selected_days_data ) ? ( $srkit_kt_total_position / count( $srkit_selected_days_data ) ) : 0;

        // Encode data for JavaScript
        $srkit_kt_data_json      = json_encode( $srkit_kt_data_array );
        $srkit_kt_pages_json     = json_encode(  $srkit_pages_data );
        $srkit_kt_queries_json   = json_encode( $srkit_queries_data );
        ?>

        <div class="wrap srkit-gsc-wrap">
            <div id="search-form-container" class="srk-gsc-section">
                <div class="srk-gsc-header">
                    <div class="srk-gsc-logo">KeyTrack</div>
                    <div class="srk-gsc-controls">
                        <div class="srk-gsc-date-selector">
                            <!-- Date range filter -->
                            <form method="GET" action="">
                                <input type="hidden" name="page" value="seo-repair-kit-keytrack">
                                <select name="date_range" onchange="this.form.submit()">
                                    <option value="weekly" <?php selected( $srkit_overview_tab_date_range, 'weekly' ); ?>>Last 7 days</option>
                                    <option value="bi-weekly" <?php selected( $srkit_overview_tab_date_range, 'bi-weekly' ); ?>>Last 14 days</option>
                                    <option value="28days" <?php selected( $srkit_overview_tab_date_range, '28days' ); ?>>Last 28 days</option>
                                    <option value="3months" <?php selected( $srkit_overview_tab_date_range, '3months' ); ?>>Last 3 months</option>
                                    <option value="6months" <?php selected( $srkit_overview_tab_date_range, '6months' ); ?>>Last 6 months</option>
                                </select>
                            </form>
                        </div>
                    </div>
                </div>
            </div>

            <!-- Tabs -->
            <div class="tabs srk-gsc-navigation">
                <button class="tablink nav-item active" onclick="openTab( event, 'Overview' )">Overview</button>
                <button class="tablink nav-item" onclick="openTab( event, 'Pages' )">Pages</button>
                <button class="tablink nav-item" onclick="openTab( event, 'Queries' )">Queries</button>
                <button class="tablink nav-item settings-tab" onclick="openTab(event, 'Settings')">Threshold</button>
            </div>

            <!-- Tab content -->
            <div id="Overview" class="srkittabcontent">
                <!-- Stats boxes -->
                <div class="srkit-gsc-stats-container">
                    <div class="srkit-gsc-stat-box">
                        <h3>Total Clicks</h3>
                        <p><?php echo esc_html( $srkit_kt_total_clicks ); ?></p>
                    </div>

                    <div class="srkit-gsc-stat-box">
                        <h3>Total Impressions</h3>
                        <p><?php echo esc_html( $srkit_kt_total_impressions ); ?></p>
                    </div>

                    <div class="srkit-gsc-stat-box">
                        <h3>Average CTR</h3>
                        <p><?php echo esc_html( number_format( $srkit_kt_average_ctr * 100, 2 ) ) . '%'; ?></p>
                    </div>

                    <div class="srkit-gsc-stat-box">
                        <h3>Average Position</h3>
                        <p><?php echo esc_html( number_format( $srkit_kt_average_position, 2 ) ); ?></p>
                    </div>
                </div>

                <!-- Chart Container -->
                <canvas id="srkit-gsc-chart" style="width: 100% !important; height: 500px !important; margin-top: 28px;"></canvas>

                <!-- Top 5 Pages and Queries - Displayed only on Overview tab -->
                <h2 class="srkit-th-tab-title">Top 5 Pages</h2>
                    <table class="srkit-general-custom-table">
                        <thead>
                            <tr> 
                                <th class="center-align">Page</th>
                                <th>Clicks</th>
                                <th>Impressions</th>
                                <th>Position</th>
                            </tr>
                        </thead>
                        <tbody>
                        <?php 
                        $srkit_kt_counter = 1;
                        foreach ($srkit_top_pages as $srkit_kt_row) { ?>
                            <tr>
                                <td><?php echo esc_html( $srkit_kt_counter ); ?>. 
                                    <a href="<?php echo esc_url( 'https://www.google.com/search?q=' . urlencode( $srkit_kt_row['keys'][0] ) ); ?>" target="_blank">
                                        <?php echo esc_html( $srkit_kt_row['keys'][0] ); ?>
                                    </a>
                                </td>
                                <td class="center"><?php echo esc_html( $srkit_kt_row['clicks'] ); ?></td>
                                <td class="center"><?php echo esc_html( $srkit_kt_row['impressions'] ); ?></td>
                                <?php 
                                    $srkit_kt_position = number_format($srkit_kt_row['position'], 2);
                                ?>
                                <td class="center"><?php echo esc_html($srkit_kt_position); ?></td>
                            </tr>
                        <?php 
                            $srkit_kt_counter++;
                        } ?>
                        </tbody>
                        <tfoot>
                    <tr>
                    <td colspan="4">Source:
                            <span class="srkit-google-search-console">
                                <a href="https://search.google.com/search-console/about" target="_blank">
                                    Google Search Console <i class="fas fa-external-link-alt"></i>
                                </a>
                            </span>
                        </td>

                    </tr>
                </tfoot>
                    </table>

                    <h2 class="srkit-th-tab-title">Top 5 Queries</h2>
                        <table class="srkit-general-custom-table">
                    <thead>
                        <tr> 
                            <th class="center-align">Query</th>
                            <th>Clicks</th>
                            <th>Impressions</th>
                            <th>Position</th>
                        </tr>
                    </thead>
                    <tbody>
                    <?php 
                    $srkit_kt_counter = 1; // Initialize counter
                    foreach ($srkit_queries_data as $srkit_kt_row) { 
                        if ($srkit_kt_counter > 5) break; // Stop the loop after 5 rows
                    ?>
                        <tr>
                            <td><?php echo $srkit_kt_counter; ?>. <a href="<?php echo esc_url( 'https://www.google.com/search?q=' . urlencode( $srkit_kt_row['keys'][0] ) ); ?>" target="_blank"><?php echo esc_html( $srkit_kt_row['keys'][0] ); ?></a></td>
                            <td class="center"><?php echo esc_html( $srkit_kt_row['clicks'] ); ?></td>
                            <td class="center"><?php echo esc_html( $srkit_kt_row['impressions'] ); ?></td>
                            <?php 
                                $srkit_kt_position = number_format($srkit_kt_row['position'], 2);
                            ?>
                            <td class="center"><?php echo esc_html($srkit_kt_position); ?></td>
                        </tr>
                    <?php 
                        $srkit_kt_counter++; // Increment counter after each row
                    } ?>
                    </tbody>
                    <tfoot>
                    <tr>
                    <td colspan="4">Source:
                            <span class="srkit-google-search-console">
                                <a href="https://search.google.com/search-console/about" target="_blank">
                                    Google Search Console <i class="fas fa-external-link-alt"></i>
                                </a>
                            </span>
                        </td>
                    </tr>
                </tfoot>
                </table>
            </div>

            <!-- Pages Tab -->
            <div id="Pages" class="srkittabcontent">
            <h2 class="srkit-th-tab-title">Pages Data</h2>
            <table class="srkit-general-custom-table">
                <thead>
                    <tr>
                        <th class="center-align">Page</th>
                        <th>Clicks</th>
                        <th>Impressions</th>
                        <th>Position</th>
                        <th>Details</th>
                    </tr>
                </thead>
                <tbody>
                    <?php 
                    $srkit_kt_counter = 1; 
                    foreach ($srkit_pages_data as $srkit_kt_row) { 
                        $srkit_kt_page_url = esc_url($srkit_kt_row['keys'][0]);
                        $srkit_kt_encoded_permalink = urlencode($srkit_kt_page_url);
                    ?>
                        <tr>
                            <td><?php echo $srkit_kt_counter . '.'; ?> <a href="<?php echo esc_url($srkit_kt_page_url); ?>" target="_blank"><?php echo esc_html($srkit_kt_row['keys'][0]); ?></a></td>
                            <td class="center"><?php echo esc_html($srkit_kt_row['clicks']); ?></td>
                            <td class="center"><?php echo esc_html($srkit_kt_row['impressions']); ?></td>
                            <?php 
                                $srkit_kt_position = number_format($srkit_kt_row['position'], 2);
                            ?>
                            <td class="center"><?php echo esc_html($srkit_kt_position); ?></td>
                            <td>
                                <button class="view-details-btn" data-permalink="<?php echo esc_attr($srkit_kt_encoded_permalink); ?>">
                                    View Details
                                </button>
                            </td>
                        </tr>
                    <?php 
                        $srkit_kt_counter++; // Increment counter after each row
                    } ?>
                </tbody>
                <tfoot>
                  <tr>
                  <td colspan="5">Source:
                            <span class="srkit-google-search-console">
                                <a href="https://search.google.com/search-console/about" target="_blank">
                                    Google Search Console <i class="fas fa-external-link-alt"></i>
                                </a>
                            </span>
                        </td>
                </tr>
             </tfoot>
            </table>
            </div>

            <!-- AJAX Script -->
            <script type="text/javascript">
            jQuery(document).ready(function($) {
                $('.view-details-btn').on('click', function() {
                    var permaLink = $(this).data('permalink');
                    var dashboardUrl = 'https://' + window.location.hostname + '/wp-admin/admin.php?page=googlesitekit-dashboard&permaLink=' + permaLink + '#traffic';
                    // Open the link in a new tab
                    window.open(dashboardUrl, '_blank');
                });
            });
            </script>

                <!-- Queries Tab -->
                <div id="Queries" class="srkittabcontent">
                <h2 class="srkit-th-tab-title">Queries Data</h2>
                <table class="srkit-general-custom-table">
                    <thead>
                        <tr>
                            <th class="center-align">Query</th>
                            <th>Clicks</th>
                            <th>Impressions</th>
                            <th>Position</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php 
                        $srkit_kt_counter = 1; 
                        foreach ($srkit_queries_data as $srkit_kt_row) { ?>
                            <tr>
                                <td><?php echo $srkit_kt_counter . '.'; ?> 
                                    <a href="https://www.google.com/search?q=<?php echo urlencode( $srkit_kt_row['keys'][0] ); ?>" target="_blank">
                                        <?php echo esc_html( $srkit_kt_row['keys'][0] ); ?>
                                    </a>
                                </td>
                                <td class="center"><?php echo esc_html( $srkit_kt_row['clicks'] ); ?></td>
                                <td class="center"><?php echo esc_html( $srkit_kt_row['impressions'] ); ?></td>
                                <?php 
                                    $srkit_kt_position = number_format($srkit_kt_row['position'], 2);
                                ?>
                                <td class="center"><?php echo esc_html($srkit_kt_position); ?></td>
                            </tr>
                        <?php 
                            $srkit_kt_counter++; 
                        } ?>
                    </tbody>
                    <tfoot>
                  <tr>
                  <td colspan="4">Source:
                            <span class="srkit-google-search-console">
                                <a href="https://search.google.com/search-console/about" target="_blank">
                                    Google Search Console <i class="fas fa-external-link-alt"></i>
                                </a>
                            </span>
                        </td>
                </tr>
             </tfoot>
                </table>
            </div>

            <!-- KeyTrack Settings Tab Content -->
            <div id="Settings" class="srkittabcontent">
                <?php
                // Include the settings file only once to prevent redeclaration errors
                if ( file_exists( plugin_dir_path( __FILE__ ) . 'class-seo-repair-kit-keytrack-settings.php' ) ) {
                    include_once plugin_dir_path( __FILE__ ) . 'class-seo-repair-kit-keytrack-settings.php';
                    // Check if the class exists to avoid errors
                    if ( class_exists( 'SEORepairKit_KeyTrack_Settings' ) ) {
                        // Instantiate the class
                        $seo_repair_kit_gsc_settings = new SEORepairKit_KeyTrack_Settings();
                        // Call the method to display settings
                        $seo_repair_kit_gsc_settings->srkit_keytrack_settings_page();
                    } else {
                        echo '<p>' . esc_html__( 'Settings class not found.', 'seo-repair-kit' ) . '</p>';
                    }
                } else {
                    echo '<p>' . esc_html__( 'Settings file not found.', 'seo-repair-kit' ) . '</p>';
                }
                ?>
            </div>
        </div>

        <script>
            // Default open tab
            document.addEventListener('DOMContentLoaded', function () {
                    document.querySelector('.tablink').click();
                    
                    var ctx = document.getElementById('srkit-gsc-chart').getContext('2d');
                    var jsonData = <?php echo $srkit_kt_data_json; ?>;
                    var labels = jsonData.map(function(row) { return row.date; });
                    var clicksData = jsonData.map(function(row) { return row.clicks; });
                    var impressionsData = jsonData.map(function(row) { return row.impressions; });
                    var ctrData = jsonData.map(function(row) { return (row.ctr * 100).toFixed(2); });
                    var positionData = jsonData.map(function(row) { return row.position.toFixed(2); });

                    var chart = new Chart(ctx, {
                        type: 'line',
                        data: {
                            labels: labels,
                            datasets: [
                                {
                                    label: 'Clicks',
                                    data: clicksData,
                                    borderColor: '#C084FC',
                                    borderWidth: 2,
                                    fill: false,
                                    tension: 0.4
                                },
                                {
                                    label: 'Impressions',
                                    data: impressionsData,
                                    borderColor: '#FF6F00',
                                    borderWidth: 2,
                                    fill: false,
                                    tension: 0.4
                                },
                                {
                                    label: 'CTR (%)',
                                    data: ctrData,
                                    borderColor: '#f51362',
                                    borderWidth: 2,
                                    fill: false,
                                    tension: 0.4
                                },
                                {
                                    label: 'Position',
                                    data: positionData,
                                    borderColor: '#5321CA',
                                    borderWidth: 2,
                                    fill: false,
                                    tension: 0.4
                                }
                            ]
                        },
                        options: {
                            responsive: true,
                            title: {
                                display: true,
                                text: 'Search Console Data'
                            },
                            tooltips: {
                                mode: 'index',
                                intersect: false
                            },
                            hover: {
                                mode: 'nearest',
                                intersect: true
                            },
                            scales: {
                                xAxes: [{
                                    display: true,
                                    scaleLabel: {
                                        display: true,
                                        labelString: 'Date'
                                    },
                                    gridLines: {
                                        display: false
                                    }
                                }],
                                yAxes: [{
                                    display: true,
                                    scaleLabel: {
                                        display: true,
                                        labelString: 'Value'
                                    },
                                    gridLines: {
                                        display: false
                                    }
                                }]
                            }
                        }
                    });
                });

            function openTab(evt, tabName) {
                // Declare all variables
                var i, srkittabcontent, tablinks;

                // Get all elements with class="srkittabcontent" and hide them
                srkittabcontent = document.getElementsByClassName("srkittabcontent");
                for (i = 0; i < srkittabcontent.length; i++) {
                    srkittabcontent[i].style.display = "none";
                }

                // Get all elements with class="tablink" and remove the class "active"
                tablinks = document.getElementsByClassName("tablink");
                for (i = 0; i < tablinks.length; i++) {
                    tablinks[i].className = tablinks[i].className.replace(" active", "");
                }

                // Show the current tab, and add an "active" class to the button that opened the tab
                document.getElementById(tabName).style.display = "block";
                evt.currentTarget.className += " active";

                // Hide the date range selector on the Settings tab
                if (tabName === 'Settings') {
                document.querySelector('.srk-gsc-header .srk-gsc-date-selector').style.display = 'none';
                } else {
                document.querySelector('.srk-gsc-header .srk-gsc-date-selector').style.display = 'block';
                }
            }
        </script>

        <?php
    }

    /**
     * Redirects to the Google Site Kit dashboard after plugin activation.
     *
     * Checks for the 'srk_sitekit_activated' transient and redirects the user if it exists.
     */
    public function handle_plugin_activation_redirect() {
        if ( get_transient( 'srk_sitekit_activated' ) ) {
            delete_transient( 'srk_sitekit_activated' );
            wp_safe_redirect( admin_url( 'admin.php?page=googlesitekit-dashboard' ) );
            exit;
        }
    }

    /**
     * Sets a transient on plugin activation.
     *
     * This transient is used to trigger a redirect to the Site Kit dashboard after activation.
     *
     * @param string $srkit_kt_plugin The plugin being activated.
     * @param bool   $srkit_kt_network_activation Whether the plugin is network activated.
     */
    public function set_activation_transient( $srkit_kt_plugin, $srkit_kt_network_activation ) {
        if ( $srkit_kt_plugin === 'google-site-kit/google-site-kit.php' ) {
            // When setting a transient
            set_transient( 'srk_sitekit_activated', sanitize_text_field( true ), HOUR_IN_SECONDS );
        }
    }

    /**
     * Checks if Site Kit setup is complete and injects a success modal.
     *
     * This function checks if the setup is complete and injects a congratulatory message modal.
     */
    public function check_sitekit_setup_success() {
        if ( get_transient( 'srk_redirected_from_repair_kit' ) && isset( $_GET['page'] ) && $_GET['page'] === 'googlesitekit-dashboard' && isset( $_GET['notification'] ) && $_GET['notification'] === 'authentication_success' ) {
            delete_transient( 'srk_redirected_from_repair_kit' );
    
            // Inject the modal code
            ?>
           <div id="seo-repair-kit-modal" style="display:none; position: fixed; top: 0; left: 0; width: 100%; height: 100%; background: rgba(0, 0, 0, 0.5); z-index: 9999; justify-content: center; align-items: center; display: flex;">
            <div class="srkit-modal-content" style="background: #fff; padding: 20px; box-shadow: 0 5px 15px rgba(0, 0, 0, 0.3); width: 650px; text-align: center; border-radius: 5px; position: relative; border: 1px solid #f28500;">
                
                <!-- Header with logo and title -->
                <div class="srk-notification-header" style="display: flex; align-items: center; margin-bottom: 20px;">
                    <div class="srk-logo-header" style="margin-right: 15px;">
                        <img class="srk-logo-dashboard" src="<?php echo esc_url( plugin_dir_url( __FILE__ ) . 'images/SEO-Repair-Kit-logo.svg' ); ?>" alt="<?php esc_attr_e( 'SEO Repair Kit', 'seo-repair-kit' ); ?>" style="max-width: auto; max-height: 50px;">
                    </div>
                    <h1 class="srk-poup-header-text" style="color: #f28500; font-weight: 700; font-size: 30px;">
                        <?php esc_html_e( 'SEO ', 'seo-repair-kit' ); ?>
                        <span class="srk-repair-kit-head-text" style="color: #0b1d51;"><?php esc_html_e( 'Repair Kit', 'seo-repair-kit' ); ?></span>
                    </h1>
                    <!-- Close button -->
                    <button class="srk-modal-close-button" style="background-color: #0b1d51; color: #fff; border: none; font-size: 25px; width: 30px; height: 29px; border-radius: 50%; display: flex; justify-content: center; align-items: center; position: absolute; top: 10px; right: 10px; cursor: pointer; padding-bottom: 5px;">&times;</button>
                </div>

                <!-- Congratulations message -->
                <h2 class="congratulations-message-heading" style="font-weight: 600; font-size: 22px;"><?php esc_html_e( 'Congratulations Google Site Kit Configured!', 'seo-repair-kit' ); ?><span class="party-popper" style="font-size: 40px; margin-left: 10px;">🎉</span></h2>
                <p class="congratulations-message-text" style="font-size: 14px; padding: 10px 20px;"><?php esc_html_e( 'You can now enjoy the powerful KeyTrack feature of the SEO Repair Kit. This feature, integrated with Google Search Console.', 'seo-repair-kit' ); ?></p>
                
                <!-- Button to go to SEO Repair Kit KeyTrack page -->
                <div class="srk-poup-keytrack-btn" style="display: flex; flex-direction: row; justify-content: center;">
                    <a href="<?php echo esc_url( admin_url( 'admin.php?page=seo-repair-kit-keytrack' ) ); ?>" class="srkit-kt-install-sitekit-btn" style="background-color: #0b1d51; color: #fff; padding: 6px 20px; border: none; border-radius: 5px; cursor: pointer; font-size: 14px; height: 30px; line-height: 10px; width: fit-content; text-decoration: none; transition-duration: 0.2s; margin: 20px 0px 10px 0px; display: flex; align-items: center;"><?php esc_html_e( 'Go to SEO Repair Kit KeyTrack', 'seo-repair-kit' ); ?></a>
                </div>
            </div>
        </div>      
        <script type="text/javascript">
            jQuery(document).ready(function($) {
                setTimeout(function() {
                    $('#seo-repair-kit-modal').fadeIn();
                }, 2000); 

                // Close modal
                $('.srk-modal-close-button').click(function() {
                    $('#seo-repair-kit-modal').fadeOut();
                });
            });
        </script>
            <?php
        }
    }
}

/**
* AJAX callback to set a transient for installation.
*
* Sets a transient to indicate the user is coming from the SEO Repair Kit plugin for tracking purposes.
*/
add_action( 'wp_ajax_set_srk_transient_for_install', 'set_srk_transient_for_install' );

function set_srk_transient_for_install() {

    if ( ! current_user_can( 'manage_options' ) ) {
        wp_die( __( 'Unauthorized user.', 'seo-repair-kit' ) );
    }
    
    set_transient( 'srk_redirected_from_repair_kit', true, 5 * MINUTE_IN_SECONDS );
    wp_die();
}

// Instantiate the SeoRepairKit_KeyTrack class.
$srkitkeytrack = new SeoRepairKit_KeyTrack();
