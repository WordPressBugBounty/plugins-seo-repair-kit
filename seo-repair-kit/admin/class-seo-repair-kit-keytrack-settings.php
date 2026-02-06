<?php
if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/**
 * SEORepairKit_KeyTrack_Settings class.
 *
 * The SEORepairKit_KeyTrack_Settings class manages keytrack settings and its related functionalities.
 *
 * @link       https://seorepairkit.com
 * @since      2.0.0
 * @author     TorontoDigits <support@torontodigits.com>
 */

if ( ! class_exists( 'SEORepairKit_KeyTrack_Settings' ) ) {

    class SEORepairKit_KeyTrack_Settings {

        public function __construct() {
            // Add cron schedule filter
            add_filter( 'cron_schedules', [ $this, 'add_cron_schedule' ] );

            // Register cronjob for fetching GSC data
            add_action( 'fetch_gsc_data_cronjob', [ $this, 'fetch_gsc_scheduled_data' ] );

            // Enqueue admin scripts
            add_action( 'admin_enqueue_scripts', [ $this, 'enqueue_admin_scripts' ] );
        }

        /**
         * Enqueue admin scripts and styles.
         */
        public function enqueue_admin_scripts() {
            // Only load KeyTrack assets on the KeyTrack screen to prevent global overhead.
            if ( empty( $_GET['page'] ) || 'seo-repair-kit-keytrack' !== sanitize_key( $_GET['page'] ) ) {
                return;
            }
            // Enqueue Chosen library (or alternative) to handle the multi-select functionality
            wp_enqueue_script( 'chosen-js', 'https://cdnjs.cloudflare.com/ajax/libs/chosen/1.8.7/chosen.jquery.min.js', [ 'jquery' ], '1.8.7', true );
            wp_enqueue_style( 'chosen-css', 'https://cdnjs.cloudflare.com/ajax/libs/chosen/1.8.7/chosen.min.css', [], '1.8.7' );
            
            // Initialize Chosen on the multi-select field
            wp_add_inline_script(
                'chosen-js',
                'jQuery(document).ready(function($) { $("#selected_keywords").chosen({ width: "100%" }); });'
            );

            // Enqueue custom JavaScript for keytrack form
            wp_enqueue_script(
                'seo-repair-kit-keytrack',
                plugin_dir_url( __FILE__ ) . 'js/seo-repair-kit-keytrack.js',
                array( 'jquery' ),
                '2.0.0',
                true
            );
        }

        /**
         * Adds custom cron schedules for SEO Repair Kit.
         *
         * IMPORTANT FIX:
         * Use standard WordPress keys `interval` and `display` so they are compatible
         * with wp_cron and we can also re-use them safely.
         *
         * @param array $schedules Existing cron schedules.
         * @return array Modified cron schedules with custom intervals.
         */
        public function add_cron_schedule( $schedules ) {

            $schedules['two_minutes'] = [
                'interval' => 2 * MINUTE_IN_SECONDS, // 2 minutes in seconds
                'display'  => __( 'Every 2 Minutes', 'seorepairkit' ),
            ];

            $schedules['seven_days'] = [
                'interval' => 7 * DAY_IN_SECONDS, // 7 days in seconds
                'display'  => __( 'Every 7 Days', 'seorepairkit' ),
            ];

            $schedules['fourteen_days'] = [
                'interval' => 14 * DAY_IN_SECONDS, // 14 days in seconds
                'display'  => __( 'Every 14 Days', 'seorepairkit' ),
            ];

            $schedules['twenty_eight_days'] = [
                'interval' => 28 * DAY_IN_SECONDS, // 28 days in seconds
                'display'  => __( 'Every 28 Days', 'seorepairkit' ),
            ];

            $schedules['ninety_days'] = [
                'interval' => 90 * DAY_IN_SECONDS, // 90 days in seconds
                'display'  => __( 'Every 90 Days', 'seorepairkit' ),
            ];

            return $schedules;
        }

        /**
         * Schedule GSC data cron job based on user selection.
         *
         * IMPORTANT FIX:
         * - Use the standard `interval` key from cron_schedules.
         * - Guard against missing schedule keys.
         * - Clear existing cron before scheduling new one to avoid duplicates.
         * - Use wp_schedule_single_event for one-time events, rescheduling happens in fetch_gsc_scheduled_data.
         */
        public function srkit_kt_form_schedule_gsc_data( $srkit_kt_schedule_options ) {
            $srkit_kt_intervals = [
                'Demo For Next 2 Minutes' => 'two_minutes',
                '7 days'                  => 'seven_days',
                '14 days'                 => 'fourteen_days',
                '28 days'                 => 'twenty_eight_days',
                '90 days'                 => 'ninety_days',
            ];

            if ( isset( $srkit_kt_intervals[ $srkit_kt_schedule_options ] ) ) {

                $all_schedules = wp_get_schedules();
                $schedule_key  = $srkit_kt_intervals[ $srkit_kt_schedule_options ];

                if ( isset( $all_schedules[ $schedule_key ]['interval'] ) ) {
                    $interval = (int) $all_schedules[ $schedule_key ]['interval'];

                    // Clear any existing scheduled event to avoid duplicates
                    $next_scheduled = wp_next_scheduled( 'fetch_gsc_data_cronjob' );
                    if ( $next_scheduled ) {
                        wp_clear_scheduled_hook( 'fetch_gsc_data_cronjob' );
                    }

                    if ( $interval > 0 ) {
                        // Schedule the first run
                        wp_schedule_single_event( time() + $interval, 'fetch_gsc_data_cronjob' );
                    }
                }
            }
        }

        /**
         * Fetch GSC scheduled data.
         * Retrieves data from Google Search Console based on user settings, processes the data,
         * and saves it into the database. Also sends an email report to the administrator.
         *
         * IMPORTANT FIXES:
         * - Fetch `id` from settings table so we can update `next_run_at`.
         * - Run as a real admin (using admin_email) so Site Kit has scopes.
         * - Remove duplicate email call.
         */
        public function fetch_gsc_scheduled_data() {
            global $wpdb;

            // Query the 'srkit_keytrack_settings' table to get the most recent record
            // Note: $wpdb->prefix is safe to use directly as it's a trusted value
            $srkit_kt_settings = $wpdb->get_row(
                "SELECT id, keytrack_name, selected_keywords, date_range FROM {$wpdb->prefix}srkit_keytrack_settings ORDER BY updated_at DESC LIMIT 1"
            );

            if ( empty( $srkit_kt_settings ) ) {
                return;
            }

            $srkit_keytrack_name        = $srkit_kt_settings->keytrack_name;
            $srkit_th_selected_keywords = maybe_unserialize( $srkit_kt_settings->selected_keywords );

            if ( empty( $srkit_th_selected_keywords ) || ! is_array( $srkit_th_selected_keywords ) ) {
                return;
            }

            // Determine the date range based on user selection
            $srkit_th_form_date_range = $srkit_kt_settings->date_range
                ? sanitize_text_field( $srkit_kt_settings->date_range )
                : 'Demo For Next 2 Minutes';

            $srkit_th_end_date = date( 'Y-m-d' );

            // IMPORTANT: For "Demo For Next 2 Minutes" we still want 90 days of data
            switch ( $srkit_th_form_date_range ) {
                case '7 days':
                    $srkit_th_start_date = date( 'Y-m-d', strtotime( '-7 days' ) );
                    break;
                case '14 days':
                    $srkit_th_start_date = date( 'Y-m-d', strtotime( '-14 days' ) );
                    break;
                case '28 days':
                    $srkit_th_start_date = date( 'Y-m-d', strtotime( '-28 days' ) );
                    break;
                case '90 days':
                case 'Demo For Next 2 Minutes':
                default:
                    $srkit_th_start_date = date( 'Y-m-d', strtotime( '-90 days' ) );
                    break;
            }

            // === Choose the correct user to run the cron as (VERY IMPORTANT for Site Kit scopes) ===

            // First try admin_email from site settings (usually the owner / Site Kit setup user)
            $admin_email = sanitize_email( get_bloginfo( 'admin_email' ) );
            $srkit_kt_admin_user = false;

            if ( is_email( $admin_email ) ) {
                $srkit_kt_admin_user = get_user_by( 'email', $admin_email );
            }

            // Fallback: first administrator by role
            if ( ! $srkit_kt_admin_user ) {
                $admins = get_users( ['role' => 'administrator'] );
                if ( ! empty( $admins ) ) {
                    $srkit_kt_admin_user = $admins[0];
                }
            }

            if ( $srkit_kt_admin_user ) {
                wp_set_current_user( $srkit_kt_admin_user->ID );
            } else {
                return;
            }

            // Check if the Google Site Kit plugin is installed and active
            if ( ! class_exists( 'Google\Site_Kit\Plugin' ) ) {
                return;
            }

            // Build Site Kit objects
            $srkit_th_sitekit_instance       = \Google\Site_Kit\Plugin::instance();
            $srkit_th_sitekit_context        = $srkit_th_sitekit_instance->context();
            $srkit_th_sitekit_options        = new \Google\Site_Kit\Core\Storage\Options( $srkit_th_sitekit_context );
            $srkit_th_sitekit_user_options   = new \Google\Site_Kit\Core\Storage\User_Options( $srkit_th_sitekit_context );
            $srkit_th_sitekit_authentication = new \Google\Site_Kit\Core\Authentication\Authentication(
                $srkit_th_sitekit_context,
                $srkit_th_sitekit_options,
                $srkit_th_sitekit_user_options
            );
            $srkit_th_sitekit_modules        = new \Google\Site_Kit\Core\Modules\Modules(
                $srkit_th_sitekit_context,
                $srkit_th_sitekit_options,
                $srkit_th_sitekit_user_options,
                $srkit_th_sitekit_authentication
            );
            $srkit_th_sitekit_search_console = $srkit_th_sitekit_modules->get_module( 'search-console' );

            // Check if the Search Console module is connected
            if ( ! $srkit_th_sitekit_search_console || ! $srkit_th_sitekit_search_console->is_connected() ) {
                return;
            }

            // === Fetch overall performance data (summary) ===
            $srkit_th_gsc_overall_data = $srkit_th_sitekit_search_console->get_data(
                'searchanalytics',
                [
                    'dimensions' => [],
                    'startDate'  => $srkit_th_start_date,
                    'endDate'    => $srkit_th_end_date,
                    'rowLimit'   => 10000,
                ]
            );

            $srkit_th_gsc_total_clicks      = 0;
            $srkit_th_gsc_total_impressions = 0;
            $srkit_th_gsc_total_ctr         = 0;
            $srkit_th_gsc_total_position    = 0;
            $srkit_th_gsc_data_count        = 0;

            if ( ! empty( $srkit_th_gsc_overall_data ) && is_array( $srkit_th_gsc_overall_data ) ) {
                foreach ( $srkit_th_gsc_overall_data as $srkit_th_row ) {
                    $srkit_th_gsc_total_clicks      += isset( $srkit_th_row['clicks'] ) ? $srkit_th_row['clicks'] : 0;
                    $srkit_th_gsc_total_impressions += isset( $srkit_th_row['impressions'] ) ? $srkit_th_row['impressions'] : 0;
                    $srkit_th_gsc_total_ctr         += isset( $srkit_th_row['ctr'] ) ? $srkit_th_row['ctr'] : 0;
                    $srkit_th_gsc_total_position    += isset( $srkit_th_row['position'] ) ? $srkit_th_row['position'] : 0;
                    $srkit_th_gsc_data_count++;
                }
            }

            $srkit_th_gsc_average_ctr      = $srkit_th_gsc_data_count ? ( $srkit_th_gsc_total_ctr / $srkit_th_gsc_data_count ) : 0;
            $srkit_th_gsc_average_position = $srkit_th_gsc_data_count ? ( $srkit_th_gsc_total_position / $srkit_th_gsc_data_count ) : 0;

            // === Fetch keyword-specific data for selected keywords ===
            $srkit_th_gsc_data_rows = $srkit_th_sitekit_search_console->get_data(
                'searchanalytics',
                [
                    'dimensions' => [ 'query' ],
                    'startDate'  => $srkit_th_start_date,
                    'endDate'    => $srkit_th_end_date,
                    'rowLimit'   => 10000,
                    'filters'    => [
                        [
                            'dimension'  => 'query',
                            'operator'   => 'in',
                            'expression' => $srkit_th_selected_keywords,
                        ],
                    ],
                ]
            );

            // Continue processing even if no keyword data returned

            // Filter out duplicates by using keyword as a unique key
            $srkit_th_gsc_unique_data = [];
            if ( ! empty( $srkit_th_gsc_data_rows ) && is_array( $srkit_th_gsc_data_rows ) ) {
                foreach ( $srkit_th_gsc_data_rows as $srkit_th_gsc_data_row ) {
                    if ( empty( $srkit_th_gsc_data_row['keys'][0] ) ) {
                        continue;
                    }
                    $srkit_th_gsc_keyword = $srkit_th_gsc_data_row['keys'][0];

                    if (
                        in_array( $srkit_th_gsc_keyword, $srkit_th_selected_keywords, true ) &&
                        ! isset( $srkit_th_gsc_unique_data[ $srkit_th_gsc_keyword ] )
                    ) {
                        $srkit_th_gsc_unique_data[ $srkit_th_gsc_keyword ] = $srkit_th_gsc_data_row;
                    }
                }
            }

            // Prepare the final data structure
            $srkit_th_gsc_data = [
                'summary'  => [
                    'total_clicks'      => $srkit_th_gsc_total_clicks,
                    'total_impressions' => $srkit_th_gsc_total_impressions,
                    'average_ctr'       => $srkit_th_gsc_average_ctr,
                    'average_position'  => $srkit_th_gsc_average_position,
                ],
                'keywords' => [],
            ];

            // Add filtered keyword data to the final data array
            foreach ( $srkit_th_gsc_unique_data as $srkit_th_gsc_keywords_data ) {
                $srkit_th_gsc_data['keywords'][] = [
                    'keyword_name' => esc_html( $srkit_th_gsc_keywords_data['keys'][0] ),
                    'clicks'       => esc_html( $srkit_th_gsc_keywords_data['clicks'] ),
                    'impressions'  => esc_html( $srkit_th_gsc_keywords_data['impressions'] ),
                    'position'     => esc_html( $srkit_th_gsc_keywords_data['position'] ),
                ];
            }

            // Save into srkit_gsc_data table
            $insert_result = $wpdb->insert(
                "{$wpdb->prefix}srkit_gsc_data",
                [
                    'gsc_data'     => wp_json_encode( $srkit_th_gsc_data ),
                    'keytrack_name'=> sanitize_text_field( $srkit_keytrack_name ),
                ],
                [ '%s', '%s' ]
            );

            // Continue execution to still send email and update next_run_at even if insert fails

            // Update next_run_at after job execution based on date_range
            $srkit_kt_interval_map = [
                'Demo For Next 2 Minutes' => '+2 minutes',
                '7 days'                  => '+7 days',
                '14 days'                 => '+14 days',
                '28 days'                 => '+28 days',
                '90 days'                 => '+90 days',
            ];

            $next_run_at = date(
                'Y-m-d H:i:s',
                strtotime(
                    isset( $srkit_kt_interval_map[ $srkit_kt_settings->date_range ] )
                        ? $srkit_kt_interval_map[ $srkit_kt_settings->date_range ]
                        : '+2 minutes'
                )
            );

            $update_result = $wpdb->update(
                "{$wpdb->prefix}srkit_keytrack_settings",
                [ 'next_run_at' => $next_run_at ],
                [ 'id' => (int) $srkit_kt_settings->id ],
                [ '%s' ],
                [ '%d' ]
            );

            // next_run_at updated

            // Send email report to admin
            $srkit_admin_email_report = sanitize_email( get_bloginfo( 'admin_email' ) );

            if ( is_email( $srkit_admin_email_report ) ) {
                $this->srkit_kt_process_and_send_email(
                    $srkit_th_gsc_data,
                    $srkit_admin_email_report,
                    $srkit_th_selected_keywords
                );
            }

            // Clear the cron job after fetching the data (single event)
            wp_clear_scheduled_hook( 'fetch_gsc_data_cronjob' );
        }

        /**
         * Build the HTML email and send it.
         *
         * IMPORTANT FIX:
         * - Actually use the $srkit_admin_email_report parameter instead of overriding it.
         */
        private function srkit_kt_process_and_send_email( $srkit_th_gsc_data, $srkit_admin_email_report, $srkit_th_selected_keywords ) {

            if ( ! is_email( $srkit_admin_email_report ) ) {
                return;
            }

            // Get the website name dynamically
            $srkit_th_email_website_name = get_bloginfo( 'name' );

            // Build subject line including website name
            $srkit_th_email_subject = sprintf(
                '📊 %s GSC Insights Are In! 🚀 Keyword Performance & Growth Report',
                $srkit_th_email_website_name
            );

            // Generate the URL for the "View Dashboard" button
            $srkit_kt_dashboard_url = admin_url( 'admin.php?page=seo-repair-kit-keytrack' );

            // Start building email HTML
            $srkit_th_email_message  = '<div style="width: 720px; margin: 0 auto; padding:15px 0px 0px 0px; font-family: \'Poppins\', sans-serif;">';
            $srkit_th_email_message .= '<header style="width: 100%; display: flex; justify-content: space-between; align-items: center;">
                <h1 style="font-size: 28px; color: #ff7a00; margin: 0; padding: 0 0 15px 0;">SEO Repair Kit</h1>
            </header>';

            $srkit_th_email_message .= '
            <section style="text-align: center; margin: 20px 0 0 0;">
                <h2 style="font-size: 45px; font-weight: 700; color: #202124; line-height: 55px; margin: 0 0;">
                    Latest insights into your <br> site\'s performance
                </h2>
                <a href="' . esc_url( $srkit_kt_dashboard_url ) . '" style="display: inline-block; margin: 20px 0 0 0; padding: 10px 10px; background-color: #ff7a00; color: #ffffff; text-align: center; text-decoration: none; border-radius: 5px; font-size: 16px;">
                    View Dashboard
                </a>
                <p style="font-size: 16px; color: #202124; margin:20px 0 0 0">
                    Below, you’ll find a detailed report covering essential metrics like clicks, <br> impressions, and your average search positions.
                </p>
            </section>';

            // Summary section
            $srkit_th_email_message .= '<section style="display: flex; margin: 20px 0 0 0;">';

            $srkit_gsc_summary_data = [
                'Total Clicks'      => isset( $srkit_th_gsc_data['summary']['total_clicks'] ) ? $srkit_th_gsc_data['summary']['total_clicks'] : 0,
                'Total Impressions' => isset( $srkit_th_gsc_data['summary']['total_impressions'] ) ? $srkit_th_gsc_data['summary']['total_impressions'] : 0,
                'Average CTR'       => isset( $srkit_th_gsc_data['summary']['average_ctr'] )
                    ? number_format( $srkit_th_gsc_data['summary']['average_ctr'] * 100, 2 ) . '%'
                    : '0.00%',
                'Average Position'  => isset( $srkit_th_gsc_data['summary']['average_position'] )
                    ? number_format( $srkit_th_gsc_data['summary']['average_position'], 2 )
                    : '0.00',
            ];

            $srkit_kt_report_count = 1;
            $srkit_kt_total_items  = count( $srkit_gsc_summary_data );

            foreach ( $srkit_gsc_summary_data as $srkit_kt_report_title => $srkit_kt_report_value ) {
                $margin_style = ( $srkit_kt_report_count < $srkit_kt_total_items ) ? 'margin-right: 8px;' : '';
                $srkit_th_email_message .= '
                    <div style="flex: 1; background: #ffffff; border-radius: 10px; border: 2px solid #ff7a00; width: 60%; padding: 10px; text-align: center; ' . $margin_style . '">
                        <h3 style="font-size: 15px; color: #333; margin: 0;">' . esc_html( $srkit_kt_report_title ) . '</h3>
                        <p style="font-size: 23px; color: #333; font-weight: bold; margin: 10px 0 0;">' . esc_html( $srkit_kt_report_value ) . '</p>
                    </div>';
                $srkit_kt_report_count++;
            }

            $srkit_th_email_message .= '</section>';

            // Keywords table
            $srkit_th_email_message .= '
            <section>
                <div style="margin: 20px 0 0 0;">
                    <h4 style="font-size: 15px; color: #000; text-align: left; margin:0 0 0 5px; line-height: 24px;">
                        Following table is going to help you understand the performance of each individual keyword.
                    </h4>
                    <table style="width: 100%; border-collapse: separate; border-spacing: 0 10px;">
                        <thead>
                            <tr style="background: linear-gradient(to right, #ffb84d, #ff7a00); color: #ffffff;">
                                <th style="padding: 16px; text-align: center; font-weight: bold; border-top-left-radius: 10px; border-bottom-left-radius: 10px;">#</th>
                                <th style="padding: 16px; text-align: left; font-weight: bold;">Keyword</th>
                                <th style="padding: 16px; text-align: center; font-weight: bold;">Clicks</th>
                                <th style="padding: 16px; text-align: center; font-weight: bold;">Impressions</th>
                                <th style="padding: 16px; text-align: center; font-weight: bold; border-top-right-radius: 10px; border-bottom-right-radius: 10px;">Position</th>
                            </tr>
                        </thead>
                        <tbody>';

            $srkit_kt_report_count = 1;

            if ( ! empty( $srkit_th_gsc_data['keywords'] ) && is_array( $srkit_th_gsc_data['keywords'] ) ) {
                foreach ( $srkit_th_gsc_data['keywords'] as $keyword ) {
                    $position = isset( $keyword['position'] ) ? number_format( (float) $keyword['position'], 2 ) : '0.00';

                    $srkit_th_email_message .= '
                    <tr style="background-color: #ffffff; border-radius: 10px; box-shadow: 0 4px 8px rgba(0, 0, 0, 0.1); margin-bottom: 10px;">
                        <td style="padding: 13px; color: #333; text-align: center; font-size: 14px; border-top-left-radius: 10px; border-bottom-left-radius: 10px; border-left: 1px solid #F28500; border-top: 1px solid #F28500; border-bottom: 1px solid #F28500;">' . esc_html( $srkit_kt_report_count ) . '</td>
                        <td style="padding: 13px; color: #333; text-align: left; font-size: 14px; border-top: 1px solid #F28500; border-bottom: 1px solid #F28500;">' . esc_html( $keyword['keyword_name'] ) . '</td>
                        <td style="padding: 13px; color: #333; text-align: center; font-size: 14px; border-top: 1px solid #F28500; border-bottom: 1px solid #F28500;">' . esc_html( $keyword['clicks'] ) . '</td>
                        <td style="padding: 13px; color: #333; text-align: center; font-size: 14px; border-top: 1px solid #F28500; border-bottom: 1px solid #F28500;">' . esc_html( $keyword['impressions'] ) . '</td>
                        <td style="padding: 13px; color: #333; text-align: center; font-size: 14px; border-top-right-radius: 10px; border-bottom-right-radius: 10px; border-right: 1px solid #F28500; border-top: 1px solid #F28500; border-bottom: 1px solid #F28500;">' . esc_html( $position ) . '</td>
                    </tr>';

                    $srkit_kt_report_count++;
                }
            }

            $srkit_th_email_message .= '
                        </tbody>
                    </table>
                </div>
            </section>';

            // Footer section
            $srkit_th_email_message .= '
            <div style="background: linear-gradient(to right, #ffb84d, #ff7a00); height: 15px;"></div>
            <footer style="background-color: #ffffff; padding-top: 20px; font-family: Arial, sans-serif;">
                <div style="max-height: 100px; height: 100%; border-radius: 5px; display: flex; align-items: center; background: linear-gradient(to right, #ffb84d, #ff7a00); padding: 30px; color: #ffffff; text-align: center;">
                    <div style="flex: 0 0 auto; margin-right: 20px; text-align: center;">
                        <img src="' . esc_url( plugin_dir_url( __FILE__ ) . 'images/seorepairkit-logo.png' ) . '" alt="SeoRepairKit" style="max-height: 70px; width: 60px; box-shadow: 0px 0px 10px 2px rgba(255, 255, 255, 0.8); border-radius: 4px;">
                        <p style="margin: 0; font-size: 12px; color: #ffffff;">
                            Powered By: <a href="https://torontodigits.com/" style="color: #0b1d51; text-decoration: none; font-size: 14px; font-weight: bold;">TorontoDigits</a>
                        </p>
                    </div>
                    <div style="flex: 1; text-align: left; padding-top: 6px;">
                        <h2 style="margin: 0; font-size: 28px; font-weight: bold; color: #ffffff;">Streamline Your Site Health</h2>
                        <p style="margin-top: 15px; font-size: 14px; color: #ffffff;">
                            Optimize Your Website Effortlessly: Get Actionable Insights on Your Website’s Performance, CTR, and Keyword Ranking.
                        </p>
                    </div>
                </div>
            </footer>
            </div>';

            // Send email
            $srkit_report_email_headers = [ 'Content-Type: text/html; charset=UTF-8' ];
            wp_mail( $srkit_admin_email_report, $srkit_th_email_subject, $srkit_th_email_message, $srkit_report_email_headers );
        }

        /**
         * Clear scheduled cron job.
         */
        private function srkit_clear_scheduled_cron_job() {
            $cleared = wp_clear_scheduled_hook( 'fetch_gsc_data_cronjob' );
            
            if ( $cleared !== false ) {
                // Also update next_run_at in database to null for the most recent record
                global $wpdb;
                $most_recent_id = $wpdb->get_var(
                    "SELECT id FROM {$wpdb->prefix}srkit_keytrack_settings ORDER BY updated_at DESC LIMIT 1"
                );
                
                if ( $most_recent_id ) {
                    $wpdb->update(
                        "{$wpdb->prefix}srkit_keytrack_settings",
                        [ 'next_run_at' => null ],
                        [ 'id' => (int) $most_recent_id ],
                        [ '%s' ],
                        [ '%d' ]
                    );
                }
                
                add_settings_error(
                    'srkit_keytrack_settings',
                    'cron_cleared',
                    esc_html__( 'Scheduled cron job has been cleared successfully.', 'seorepairkit' ),
                    'updated'
                );
            } else {
                add_settings_error(
                    'srkit_keytrack_settings',
                    'cron_clear_failed',
                    esc_html__( 'No scheduled cron job found to clear.', 'seorepairkit' ),
                    'error'
                );
            }
        }

        /**
         * Display keytrack settings page.
         */
        public function srkit_keytrack_settings_page() {
            if ( ! class_exists( 'Google\Site_Kit\Plugin' ) ) {
                echo '<p>' . esc_html__( 'Google Site Kit plugin is required for this plugin to work. Please install and activate it.', 'seorepairkit' ) . '</p>';
                return;
            }

            // Handle clear cron job action
            if ( isset( $_POST['clear_cron_job'] ) && check_admin_referer( 'srkit_clear_cron_job', 'srkit_clear_cron_nonce' ) ) {
                $this->srkit_clear_scheduled_cron_job();
            }

            if ( $_SERVER['REQUEST_METHOD'] === 'POST' && check_admin_referer( 'srkit_keytrack_settings' ) ) {
                // Only save settings if it's not the clear cron action
                if ( ! isset( $_POST['clear_cron_job'] ) ) {
                    $this->srkit_save_keytrack_settings();
                }
            }

            // Display any settings errors
            settings_errors( 'srkit_keytrack_settings' );
            
            echo '<div class="srkit-container">';
            $this->srkit_display_keytrack_settings_form();
            $this->srkit_display_recent_gsc_data();
            echo '</div>';
        }

        /**
         * Save keytrack settings.
         */
        private function srkit_save_keytrack_settings() {
            global $wpdb;

            if (
                $_SERVER['REQUEST_METHOD'] !== 'POST'
                || ! isset( $_POST['_wpnonce'] )
                || ! wp_verify_nonce( $_POST['_wpnonce'], 'srkit_keytrack_settings' )
            ) {
                wp_die( esc_html__( 'Invalid request or missing nonce verification.', 'seorepairkit' ) );
            }

            $srkit_keytrack_name        = isset( $_POST['keytrack_name'] ) ? sanitize_text_field( $_POST['keytrack_name'] ) : '';
            $srkit_th_selected_keywords = isset( $_POST['selected_keywords'] )
                ? array_map( 'sanitize_text_field', (array) $_POST['selected_keywords'] )
                : [];
            $srkit_th_form_date_range   = isset( $_POST['date_range'] ) ? sanitize_text_field( $_POST['date_range'] ) : 'Demo For Next 2 Minutes';

            // Validate required fields
            if ( empty( $srkit_keytrack_name ) ) {
                add_settings_error(
                    'srkit_keytrack_settings',
                    'empty_keytrack_name',
                    esc_html__( 'Threshold Reference name is required.', 'seorepairkit' )
                );
                return;
            }

            if ( empty( $srkit_th_selected_keywords ) || ! is_array( $srkit_th_selected_keywords ) ) {
                add_settings_error(
                    'srkit_keytrack_settings',
                    'empty_keywords',
                    esc_html__( 'Please select at least one keyword to track.', 'seorepairkit' )
                );
                return;
            }

            // Validate date range
            $allowed_date_ranges = [ 'Demo For Next 2 Minutes', '7 days', '14 days', '28 days', '90 days' ];
            if ( ! in_array( $srkit_th_form_date_range, $allowed_date_ranges, true ) ) {
                $srkit_th_form_date_range = 'Demo For Next 2 Minutes';
            }

            // Calculate next_run_at based on the selected schedule
            $srkit_kt_interval_map = [
                'Demo For Next 2 Minutes' => '+2 minutes',
                '7 days'                  => '+7 days',
                '14 days'                 => '+14 days',
                '28 days'                 => '+28 days',
                '90 days'                 => '+90 days',
            ];

            $next_run_at = date(
                'Y-m-d H:i:s',
                strtotime(
                    isset( $srkit_kt_interval_map[ $srkit_th_form_date_range ] )
                        ? $srkit_kt_interval_map[ $srkit_th_form_date_range ]
                        : '+2 minutes'
                )
            );

            $insert_result = $wpdb->insert(
                "{$wpdb->prefix}srkit_keytrack_settings",
                [
                    'keytrack_name'     => sanitize_text_field( $srkit_keytrack_name ),
                    'selected_keywords' => maybe_serialize( array_map( 'sanitize_text_field', $srkit_th_selected_keywords ) ),
                    'date_range'        => sanitize_text_field( $srkit_th_form_date_range ),
                    'setting_value'     => wp_json_encode(
                        [
                            'selected_keywords' => $srkit_th_selected_keywords,
                            'date_range'        => $srkit_th_form_date_range,
                        ]
                    ),
                    'created_at'        => current_time( 'mysql' ),
                    'updated_at'        => current_time( 'mysql' ),
                    'next_run_at'       => $next_run_at,
                ],
                [ '%s', '%s', '%s', '%s', '%s', '%s', '%s' ]
            );

            if ( false === $insert_result ) {
                add_settings_error(
                    'srkit_keytrack_settings',
                    'db_insert_failed',
                    esc_html__( 'Failed to save settings. Database error: ', 'seorepairkit' ) . $wpdb->last_error
                );
                return;
            }

            // Schedule the cron job
            $this->srkit_kt_form_schedule_gsc_data( $srkit_th_form_date_range );

            add_settings_error(
                'srkit_keytrack_settings',
                'settings_saved',
                esc_html__( 'Settings saved and cron job scheduled successfully!', 'seorepairkit' ),
                'updated'
            );
            
            // Display the success message
            settings_errors( 'srkit_keytrack_settings' );
        }

        /**
         * Display the keytrack settings form.
         */
        private function srkit_display_keytrack_settings_form() {
            global $wpdb;

            $srkit_th_last_saved_settings = $wpdb->get_row(
                "SELECT * FROM {$wpdb->prefix}srkit_keytrack_settings ORDER BY updated_at DESC LIMIT 1",
                ARRAY_A
            );

            $srkit_keytrack_name        = $srkit_th_last_saved_settings['keytrack_name'] ?? '';
            $srkit_th_selected_keywords = maybe_unserialize( $srkit_th_last_saved_settings['selected_keywords'] ?? [] );
            $srkit_th_form_date_range   = $srkit_th_last_saved_settings['date_range'] ?? 'Demo For Next 2 Minutes';
            $next_run_at                = $srkit_th_last_saved_settings['next_run_at'] ?? null;

            if ( ! is_array( $srkit_th_selected_keywords ) ) {
                $srkit_th_selected_keywords = [];
            }

            // Determine if the submit button should be disabled
            $srkit_kt_disable_submit = $next_run_at && strtotime( $next_run_at ) > time();
            $next_run_at_formatted   = $srkit_kt_disable_submit ? date( 'Y-m-d H:i:s', strtotime( $next_run_at ) ) : '';

            // Fetch top keywords from Google Site Kit's Search Console module
            if ( class_exists( 'Google\Site_Kit\Plugin' ) ) {
                $srkit_th_sitekit_instance       = \Google\Site_Kit\Plugin::instance();
                $srkit_th_sitekit_context        = $srkit_th_sitekit_instance->context();
                $srkit_th_sitekit_options        = new \Google\Site_Kit\Core\Storage\Options( $srkit_th_sitekit_context );
                $srkit_th_sitekit_user_options   = new \Google\Site_Kit\Core\Storage\User_Options( $srkit_th_sitekit_context );
                $srkit_th_sitekit_authentication = new \Google\Site_Kit\Core\Authentication\Authentication(
                    $srkit_th_sitekit_context,
                    $srkit_th_sitekit_options,
                    $srkit_th_sitekit_user_options
                );
                $srkit_th_sitekit_modules        = new \Google\Site_Kit\Core\Modules\Modules(
                    $srkit_th_sitekit_context,
                    $srkit_th_sitekit_options,
                    $srkit_th_sitekit_user_options,
                    $srkit_th_sitekit_authentication
                );
                $srkit_th_sitekit_search_console = $srkit_th_sitekit_modules->get_module( 'search-console' );

                if ( $srkit_th_sitekit_search_console && $srkit_th_sitekit_search_console->is_connected() ) {
                    $srkit_th_top_keywords = $srkit_th_sitekit_search_console->get_data(
                        'searchanalytics',
                        [
                            'dimensions' => [ 'query' ],
                            'startDate'  => '2004-01-01',
                            'endDate'    => date( 'Y-m-d' ),
                            'rowLimit'   => 10000,
                            'orderby'    => [ 'clicks' => 'DESC' ],
                        ]
                    );
                } else {
                    $srkit_th_top_keywords = [];
                }
            } else {
                $srkit_th_top_keywords = [];
            }

            ?>
            <div class="srkit-left">
                <h2 class="srk-form-heading-style"><?php esc_html_e( 'Threshold Settings', 'seorepairkit' ); ?></h2>
                <form method="post" action="" id="keytrack-form" class="wrap" onsubmit="return showSubmissionAlert()">
                    <?php wp_nonce_field( 'srkit_keytrack_settings' ); ?>

                    <label for="keytrack_name"><?php esc_html_e( 'Threshold Reference:', 'seorepairkit' ); ?></label>
                    <input type="text" name="keytrack_name" id="keytrack_name" value="<?php echo esc_attr( $srkit_keytrack_name ); ?>" required>

                    <label for="selected_keywords"><?php esc_html_e( 'Target Your Keywords:', 'seorepairkit' ); ?></label>
                    <select name="selected_keywords[]" id="selected_keywords" class="wp-select" multiple="multiple" required>
                        <?php
                        if ( ! empty( $srkit_th_top_keywords ) && is_array( $srkit_th_top_keywords ) ) {
                            foreach ( $srkit_th_top_keywords as $srkit_th_row ) {
                                if ( empty( $srkit_th_row['keys'][0] ) ) {
                                    continue;
                                }
                                $srkit_th_query    = $srkit_th_row['keys'][0];
                                $is_selected       = in_array( $srkit_th_query, $srkit_th_selected_keywords, true );
                                ?>
                                <option value="<?php echo esc_attr( $srkit_th_query ); ?>" <?php selected( $is_selected, true ); ?>>
                                    <?php echo esc_html( $srkit_th_query ); ?>
                                </option>
                                <?php
                            }
                        } else {
                            echo '<option>' . esc_html__( 'No keywords found.', 'seorepairkit' ) . '</option>';
                        }
                        ?>
                    </select>

                    <label for="date_range"><?php esc_html_e( 'Performance Tracking Period:', 'seorepairkit' ); ?></label>
                    <select name="date_range" id="date_range" required>
                        <option value="Demo For Next 2 Minutes" <?php selected( $srkit_th_form_date_range, 'Demo For Next 2 Minutes' ); ?>>
                            <?php esc_html_e( 'Demo For Next 2 Minutes', 'seorepairkit' ); ?>
                        </option>
                        <option value="7 days" <?php selected( $srkit_th_form_date_range, '7 days' ); ?>>
                            <?php esc_html_e( 'Next 7 Days', 'seorepairkit' ); ?>
                        </option>
                        <option value="14 days" <?php selected( $srkit_th_form_date_range, '14 days' ); ?>>
                            <?php esc_html_e( 'Next 14 Days', 'seorepairkit' ); ?>
                        </option>
                        <option value="28 days" <?php selected( $srkit_th_form_date_range, '28 days' ); ?>>
                            <?php esc_html_e( 'Next 28 Days', 'seorepairkit' ); ?>
                        </option>
                        <option value="90 days" <?php selected( $srkit_th_form_date_range, '90 days' ); ?>>
                            <?php esc_html_e( 'Next 3 Months', 'seorepairkit' ); ?>
                        </option>
                    </select>

                    <input
                        type="submit"
                        id="submit-keytrack-settings"
                        class="srk-keytrack-settings-form-button"
                        value="<?php esc_attr_e( 'Submit', 'seorepairkit' ); ?>"
                        <?php disabled( $srkit_kt_disable_submit ); ?>
                    >
                </form>

                <?php if ( $srkit_kt_disable_submit ) : ?>
                    <p class="srkit-th-disable-button" style="font-size: 16px; color: #0B1D51; margin: 1em 0.5em;">
                        <?php esc_html_e( 'Settings form is temporarily disabled until the next scheduled run at:', 'seorepairkit' ); ?>
                        <strong style="font-weight: bold; color: #0B1D51;"><?php echo esc_html( $next_run_at_formatted ); ?></strong>
                    </p>
                    <p class="srkit-th-email-message" style="font-size: 16px; font-weight: bold; color: #0B1D51; margin: 1em 0.5em;">
                        ✨ <?php esc_html_e( 'Make sure your admin email is set up to receive reports!', 'seorepairkit' ); ?> 📧
                    </p>
                <?php endif; ?>

                <?php
                // Check if there's a scheduled cron job
                $has_scheduled_cron = wp_next_scheduled( 'fetch_gsc_data_cronjob' );
                $next_run_timestamp = $has_scheduled_cron ? $has_scheduled_cron : null;
                $next_run_formatted = $next_run_timestamp ? date( 'Y-m-d H:i:s', $next_run_timestamp ) : '';
                ?>
                    <div style="margin-top: 20px; padding: 15px; background-color: #fff3cd; border: 1px solid #ffc107; border-radius: 5px;">
                        <?php if ( $has_scheduled_cron ) : ?>
                            <p style="margin: 0 0 10px 0; font-size: 14px; color: #856404;">
                                <strong><?php esc_html_e( 'Scheduled Cron Job:', 'seorepairkit' ); ?></strong>
                                <?php esc_html_e( 'Next run scheduled for:', 'seorepairkit' ); ?>
                                <strong><?php echo esc_html( $next_run_formatted ); ?></strong>
                            </p>
                        <?php else : ?>
                            <p style="margin: 0 0 10px 0; font-size: 14px; color: #856404;">
                                <strong><?php esc_html_e( 'Scheduled Cron Job:', 'seorepairkit' ); ?></strong>
                                <?php esc_html_e( 'No cron job currently scheduled.', 'seorepairkit' ); ?>
                            </p>
                        <?php endif; ?>
                        <form method="post" action="" style="margin: 0;">
                            <?php wp_nonce_field( 'srkit_clear_cron_job', 'srkit_clear_cron_nonce' ); ?>
                            <input type="hidden" name="clear_cron_job" value="1">
                            <button 
                                type="submit" 
                                class="srk-keytrack-clear-cron-button"
                                onclick="return confirm('<?php echo esc_js( __( 'Are you sure you want to clear the scheduled cron job?', 'seorepairkit' ) ); ?>');"
                                style="background-color: #dc3545; color: #fff; padding: 8px 15px; border: none; border-radius: 5px; cursor: pointer; font-size: 14px;">
                                <?php esc_html_e( 'Clear Scheduled Cron Job', 'seorepairkit' ); ?>
                            </button>
                        </form>
                    </div>
            </div>
            <?php
        }

        /**
         * Display recent GSC data in a WordPress table.
         */
        private function srkit_display_recent_gsc_data() {
            global $wpdb;

            $srkit_th_table_name = $wpdb->prefix . 'srkit_gsc_data';

            // Fetch the most recent GSC data and its associated keytrack name
            $srkit_th_recent_data = $wpdb->get_row(
                "SELECT gsc_data, keytrack_name FROM $srkit_th_table_name ORDER BY id DESC LIMIT 1",
                ARRAY_A
            );

            if ( $srkit_th_recent_data ) {
                $srkit_th_gsc_data    = json_decode( $srkit_th_recent_data['gsc_data'], true );
                $srkit_keytrack_name  = $srkit_th_recent_data['keytrack_name'];

                echo '<div class="srkit-right">';
                echo '<h3 class="srk-form-heading-style">' . esc_html( $srkit_keytrack_name ) . '</h3>';

                // Display overall data
                echo '<div class="srkit-gsc-stats-container">';
                echo '<div class="srkit-gsc-stat-box"><h3>' . esc_html__( 'Total Clicks', 'seorepairkit' ) . '</h3><p>' . esc_html( $srkit_th_gsc_data['summary']['total_clicks'] ?? 0 ) . '</p></div>';
                echo '<div class="srkit-gsc-stat-box"><h3>' . esc_html__( 'Total Impressions', 'seorepairkit' ) . '</h3><p>' . esc_html( $srkit_th_gsc_data['summary']['total_impressions'] ?? 0 ) . '</p></div>';
                $avg_ctr = isset( $srkit_th_gsc_data['summary']['average_ctr'] ) ? number_format( $srkit_th_gsc_data['summary']['average_ctr'] * 100, 2 ) : '0.00';
                echo '<div class="srkit-gsc-stat-box"><h3>' . esc_html__( 'Average CTR', 'seorepairkit' ) . '</h3><p>' . esc_html( $avg_ctr ) . '%</p></div>';
                $avg_pos = isset( $srkit_th_gsc_data['summary']['average_position'] ) ? number_format( $srkit_th_gsc_data['summary']['average_position'], 2 ) : '0.00';
                echo '<div class="srkit-gsc-stat-box"><h3>' . esc_html__( 'Average Position', 'seorepairkit' ) . '</h3><p>' . esc_html( $avg_pos ) . '</p></div>';
                echo '</div>';

                // Display keyword-specific data in a WordPress table
                echo '<table class="srkit-general-custom-table">';
                echo '<thead>
                        <tr>
                            <th class="center-align">' . esc_html__( 'Keyword', 'seorepairkit' ) . '</th>
                            <th>' . esc_html__( 'Clicks', 'seorepairkit' ) . '</th>
                            <th>' . esc_html__( 'Impressions', 'seorepairkit' ) . '</th>
                            <th>' . esc_html__( 'Position', 'seorepairkit' ) . '</th>
                        </tr>
                    </thead>
                    <tbody>';

                $srkit_kt_counter = 1;

                if ( ! empty( $srkit_th_gsc_data['keywords'] ) && is_array( $srkit_th_gsc_data['keywords'] ) ) {
                    foreach ( $srkit_th_gsc_data['keywords'] as $srkit_th_gsc_keywords_data ) {
                        $srkit_th_gsc_position = isset( $srkit_th_gsc_keywords_data['position'] )
                            ? number_format( (float) $srkit_th_gsc_keywords_data['position'], 2 )
                            : '0.00';

                        echo '<tr>
                                <td class="srkit-center">
                                    <span class="counter">' . esc_html( $srkit_kt_counter ) . '.</span>
                                    <span class="keywordname">' . esc_html( $srkit_th_gsc_keywords_data['keyword_name'] ) . '</span>
                                </td>
                                <td class="center">' . esc_html( $srkit_th_gsc_keywords_data['clicks'] ) . '</td>
                                <td class="center">' . esc_html( $srkit_th_gsc_keywords_data['impressions'] ) . '</td>
                                <td class="center">' . esc_html( $srkit_th_gsc_position ) . '</td>
                            </tr>';

                        $srkit_kt_counter++;
                    }
                }

                echo '</tbody>
                    <tfoot>
                        <tr>
                            <td colspan="4">Source:
                                <span class="srkit-google-search-console">
                                    <a href="https://search.google.com/search-console/about" target="_blank" rel="noopener noreferrer">
                                        Google Search Console <i class="fas fa-external-link-alt"></i>
                                    </a>
                                </span>
                            </td>
                        </tr>
                    </tfoot>
                </table>';

                echo '</div>'; // .srkit-right
            } else {
                echo '<p class="srk-general-message-style">' .
                    esc_html__( '🚀 Set Up Your Thresholds to Track Website Content Performance Like a Pro! 📈 ✨ Get reports straight to your inbox! 📧', 'seorepairkit' ) .
                '</p>';
            }
        }

    }

    // Instantiate the SEORepairKit_KeyTrack_Settings class.
    new SEORepairKit_KeyTrack_Settings();
}