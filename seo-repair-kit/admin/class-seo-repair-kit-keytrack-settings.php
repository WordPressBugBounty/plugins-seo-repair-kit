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
            // Enqueue Chosen library (or alternative) to handle the multi-select functionality
            wp_enqueue_script( 'chosen-js', 'https://cdnjs.cloudflare.com/ajax/libs/chosen/1.8.7/chosen.jquery.min.js', [ 'jquery' ], '1.8.7', true );
            wp_enqueue_style( 'chosen-css', 'https://cdnjs.cloudflare.com/ajax/libs/chosen/1.8.7/chosen.min.css', [], '1.8.7' );
            
            // Initialize Chosen on the multi-select field
            wp_add_inline_script( 'chosen-js', 'jQuery(document).ready(function($) { $("#selected_keywords").chosen({ width: "100%" }); });' );

            // Enqueue custom JavaScript for keytrack form
            wp_enqueue_script( 'seo-repair-kit-keytrack', plugin_dir_url( __FILE__ ) . 'js/seo-repair-kit-keytrack.js', array( 'jquery' ), '2.0.0', true );
    
        }

        /**
        * Adds custom cron schedules for SEO Repair Kit.
        *
        * @param array $schedules Existing cron schedules.
        * @return array Modified cron schedules with custom intervals.
        */
        public function add_cron_schedule( $srkit_kt_schedules ) {
            // Add custom schedules for cron jobs
            $srkit_kt_schedules['two_minutes'] = [
                'srkit_kt_interval' => 2 * MINUTE_IN_SECONDS, // 2 minutes in seconds
                'srkit_kt_display'  => __( 'Every 2 Minutes', 'seorepairkit' ),
            ];

            $srkit_kt_schedules['seven_days'] = [
                'srkit_kt_interval' => 7 * DAY_IN_SECONDS, // 7 days in seconds
                'srkit_kt_display'  => __( 'Every 7 Days', 'seorepairkit' ),
            ];

            $srkit_kt_schedules['fourteen_days'] = [
                'srkit_kt_interval' => 14 * DAY_IN_SECONDS, // 14 days in seconds
                'srkit_kt_display'  => __( 'Every 14 Days', 'seorepairkit' ),
            ];

            $srkit_kt_schedules['twenty_eight_days'] = [
                'srkit_kt_interval' => 28 * DAY_IN_SECONDS, // 28 days in seconds
                'srkit_kt_display'  => __( 'Every 28 Days', 'seorepairkit' ),
            ];

            $srkit_kt_schedules['ninety_days'] = [
                'srkit_kt_interval' => 90 * DAY_IN_SECONDS, // 90 days in seconds
                'srkit_kt_display'  => __( 'Every 90 Days', 'seorepairkit' ),
            ];

            return $srkit_kt_schedules;
        }

        /**
         * Schedule GSC data cron job based on user selection.
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
                if ( ! wp_next_scheduled( 'fetch_gsc_data_cronjob' ) ) {
                    wp_schedule_single_event( time() + wp_get_schedules()[$srkit_kt_intervals[$srkit_kt_schedule_options]]['srkit_kt_interval'], 'fetch_gsc_data_cronjob' );
                }
            }
        }

        /**
         * Fetch GSC scheduled data.
         * Retrieves data from Google Search Console based on user settings, processes the data,
         * and saves it into the database. Also sends an email report to the administrator.
         */
        public function fetch_gsc_scheduled_data() {
            global $wpdb;
            
            // Query the 'srkit_keytrack_settings' table to get the most recent record
            $srkit_kt_settings = $wpdb->get_row( $wpdb->prepare( 
                "SELECT keytrack_name, selected_keywords, date_range FROM {$wpdb->prefix}srkit_keytrack_settings ORDER BY updated_at DESC LIMIT %d", 
                1 
            ) );
            
            if ( empty( $srkit_kt_settings ) ) {
                error_log( 'No settings found in the srkit_keytrack_settings table.' );
                return;
            }
        
            $srkit_keytrack_name        = $srkit_kt_settings->keytrack_name;
            $srkit_th_selected_keywords = maybe_unserialize( $srkit_kt_settings->selected_keywords );
            if ( empty( $srkit_th_selected_keywords ) ) {
                error_log( 'No valid keywords found.' );
                return;
            }

            // Determine the date range based on user selection
            $srkit_th_form_date_range = $srkit_kt_settings->date_range ? sanitize_text_field( $srkit_kt_settings->date_range ) : 'Demo For Next 2 Minutes';
            $srkit_th_end_date = date( 'Y-m-d' );
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
                    $srkit_th_start_date = date( 'Y-m-d', strtotime( '-90 days' ) );
                    break;
                default:
                    $srkit_th_start_date = date( 'Y-m-d', strtotime( '-90 days' ) );
            }

            // Get an administrator user dynamically
            $srkit_kt_admin_users = get_users( ['role' => 'administrator'] );

            if ( ! empty( $srkit_kt_admin_users ) ) {
                $srkit_kt_admin_user = $srkit_kt_admin_users[0]; 
                wp_set_current_user( $srkit_kt_admin_user->ID ); 
                error_log( 'Switched to admin user: ' . esc_html( $srkit_kt_admin_user->user_email ) );
            } else {
                error_log( 'No administrator user found.' );
                return;
            }

            // Check if the Google Site Kit plugin is installed and active
            if ( ! class_exists( 'Google\Site_Kit\Plugin' ) ) {
                error_log( 'Google Site Kit is not available.' );
                return;
            }

            $srkit_th_sitekit_instance       = \Google\Site_Kit\Plugin::instance();
            $srkit_th_sitekit_context        = $srkit_th_sitekit_instance->context();
            $srkit_th_sitekit_options        = new \Google\Site_Kit\Core\Storage\Options( $srkit_th_sitekit_context );
            $srkit_th_sitekit_user_options   = new \Google\Site_Kit\Core\Storage\User_Options( $srkit_th_sitekit_context );
            $srkit_th_sitekit_authentication = new \Google\Site_Kit\Core\Authentication\Authentication( $srkit_th_sitekit_context, $srkit_th_sitekit_options, $srkit_th_sitekit_user_options );
            $srkit_th_sitekit_modules        = new \Google\Site_Kit\Core\Modules\Modules( $srkit_th_sitekit_context, $srkit_th_sitekit_options, $srkit_th_sitekit_user_options, $srkit_th_sitekit_authentication );
            $srkit_th_sitekit_search_console = $srkit_th_sitekit_modules->get_module( 'search-console' );

            // Check if the Search Console module is connected
            if ( ! $srkit_th_sitekit_search_console->is_connected() ) {
                error_log( 'Search Console is not connected.' );
                return;
            }

            // Fetch overall performance data 
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

            if ( ! empty( $srkit_th_gsc_overall_data ) ) {
                foreach ( $srkit_th_gsc_overall_data as $srkit_th_row ) {
                    $srkit_th_gsc_total_clicks      += $srkit_th_row['clicks'];
                    $srkit_th_gsc_total_impressions += $srkit_th_row['impressions'];
                    $srkit_th_gsc_total_ctr         += $srkit_th_row['ctr'];
                    $srkit_th_gsc_total_position    += $srkit_th_row['position'];
                    $srkit_th_gsc_data_count++;
                }

                $srkit_th_gsc_average_ctr      = $srkit_th_gsc_data_count ? ( $srkit_th_gsc_total_ctr / $srkit_th_gsc_data_count ) : 0;
                $srkit_th_gsc_average_position = $srkit_th_gsc_data_count ? ( $srkit_th_gsc_total_position / $srkit_th_gsc_data_count ) : 0;
            }

            // Fetch Google Search Console data for all selected keywords in a single call
            $srkit_th_gsc_data = $srkit_th_sitekit_search_console->get_data(
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
                        ]
                    ],
                ]
            );

            // Filter out duplicates by using keyword as a unique key
            $srkit_th_gsc_unique_data = [];
            foreach ( $srkit_th_gsc_data as $srkit_th_gsc_data_row ) {
                $srkit_th_gsc_keyword = $srkit_th_gsc_data_row['keys'][0];
                if ( in_array( $srkit_th_gsc_keyword, $srkit_th_selected_keywords ) && ! isset( $srkit_th_gsc_unique_data[ $srkit_th_gsc_keyword ] ) ) {
                    $srkit_th_gsc_unique_data[ $srkit_th_gsc_keyword ] = $srkit_th_gsc_data_row;
                }
            }

            // Prepare the overall summary to include with the selected keywords
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

            $wpdb->insert(
                "{$wpdb->prefix}srkit_gsc_data",
                [
                    'gsc_data' => wp_json_encode($srkit_th_gsc_data),
                    'keytrack_name' => sanitize_text_field($srkit_keytrack_name),
                ],
                ['%s', '%s']
            );            

            // Update next_run_at after job execution based on date_range
            $srkit_kt_interval_map = [
                'Demo For Next 2 Minutes' => '+2 minutes',
                '7 days'                  => '+7 days',
                '14 days'                 => '+14 days',
                '28 days'                 => '+28 days',
                '90 days'                 => '+90 days',
            ];
            $next_run_at = date( 'Y-m-d H:i:s', strtotime( $srkit_kt_interval_map[$srkit_kt_settings->date_range] ?? '+2 minutes' ) );

            $wpdb->update(
                "{$wpdb->prefix}srkit_keytrack_settings",
                [ 'next_run_at' => $next_run_at ],
                [ 'id' => $srkit_kt_settings->id ],
                [ '%s' ],
                [ '%d' ]
            );

            // Send an email report
            $this->srkit_kt_process_and_send_email( $srkit_th_gsc_data, $srkit_admin_email_report, implode( ', ', $srkit_th_selected_keywords ) );

            // Clear the cron job after fetching the data
            wp_clear_scheduled_hook( 'fetch_gsc_data_cronjob' );
        }

        private function srkit_kt_process_and_send_email($srkit_th_gsc_data, $srkit_admin_email_report, $srkit_th_selected_keywords) {

            // Fetch admin email dynamically
            $srkit_admin_email_report = get_bloginfo('admin_email');

            // Log admin email for debugging
            if (!is_email($srkit_admin_email_report)) {
                error_log('Invalid admin email: ' . $srkit_admin_email_report);
                return;
            }
        
            // Get the website name dynamically
            $srkit_th_email_website_name = get_bloginfo( 'name' );

            // Set the email subject with dynamic website name
            $srkit_th_email_subject = sprintf( __( '📊 ' . $srkit_th_email_website_name . ' GSC Insights Are In! 🚀 Keyword Performance & Growth Report ' . implode(', ', $srkit_th_selected_keywords) ) );
            
            // Generate the URL for the "View Dashboard" button
            $srkit_kt_dashboard_url = admin_url('admin.php?page=seo-repair-kit-keytrack');

            // Build the email message content with inline styles and structure
            $srkit_th_email_message = '<div style="width: 720px; margin: 0 auto; padding:15px 0px 0px 0px; font-family: \'Poppins\', sans-serif;">
            <header style="width: 100%; display: flex; justify-content: space-between; align-items: center;">
                <h1 style="font-size: 28px; color: #ff7a00; margin: 0; padding: 0 0 15px 0;">SEO Repair Kit</h1>
            </header>

            <section style="text-align: center; margin: 20px 0 0 0;">
                <h2 style="font-size: 45px; font-weight: 700; color: #202124; line-height: 55px; margin: 0 0;">Latest insights into your <br> site\'s performance</h2>
                <a href="' . esc_url($srkit_kt_dashboard_url) . '" style="display: inline-block; margin: 20px 0 0 0; padding: 10px 10px; background-color: #ff7a00; color: #ffffff; text-align: center; text-decoration: none; border-radius: 5px; font-size: 16px;">View Dashboard</a>
                <p style="font-size: 16px; color: #202124; margin:20px 0 0 0">Below, you’ll find a detailed report covering essential metrics like clicks, <br> impressions, and your average search positions.</p>
            </section>

            <section style="display: flex; margin: 20px 0 0 0;">';

            // Summary section with key metrics
            $srkit_gsc_summary_data = [
                'Total Clicks'      => $srkit_th_gsc_data['summary']['total_clicks'],
                'Total Impressions' => $srkit_th_gsc_data['summary']['total_impressions'],
                'Average CTR'       => number_format($srkit_th_gsc_data['summary']['average_ctr'] * 100, 2) . '%',
                'Average Position'  => number_format($srkit_th_gsc_data['summary']['average_position'], 2)
            ];
            $srkit_kt_report_count  = 1;
            $srkit_kt_total_items   = count($srkit_gsc_summary_data);

            foreach ($srkit_gsc_summary_data as $srkit_kt_report_title => $srkit_kt_report_value) {
                $margin_style = ($srkit_kt_report_count < $srkit_kt_total_items) ? 'margin-right: 8px;' : '';
                $srkit_th_email_message .= '<div style="flex: 1; background: #ffffff; border-radius: 10px; border: 2px solid #ff7a00; width: 60%; padding: 10px; text-align: center; ' . $margin_style . '">
                    <h3 style="font-size: 15px; color: #333; margin: 0;">' . esc_html($srkit_kt_report_title) . '</h3>
                    <p style="font-size: 23px; color: #333; font-weight: bold; margin: 10px 0 0;">' . esc_html($srkit_kt_report_value) . '</p>
                </div>';
                $srkit_kt_report_count++;
            }

            $srkit_th_email_message .= '</section><section>
            <div style="margin: 20px 0 0 0;">
                <h4 style="font-size: 15px; color: #000; text-align: left; margin:0 0 0 5px; line-height: 24px;">Following Table is going to help you understand the performance of each individual keyword.</h4>
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

            // Keyword data rows
            $srkit_kt_report_count = 1;
            foreach ($srkit_th_gsc_data['keywords'] as $keyword) {
            $srkit_kt_data_position = number_format($keyword['position'], 2);
            $srkit_th_email_message .= '<tr style="background-color: #ffffff; border-radius: 10px; box-shadow: 0 4px 8px rgba(0, 0, 0, 0.1); margin-bottom: 10px;">
                <td style="padding: 13px; color: #333; text-align: center; font-size: 14px; border-top-left-radius: 10px; border-bottom-left-radius: 10px; border-left: 1px solid #F28500; border-top: 1px solid #F28500; border-bottom: 1px solid #F28500;">' . esc_html($srkit_kt_report_count) . '</td>
                <td style="padding: 13px; color: #333; text-align: left; font-size: 14px; border-top: 1px solid #F28500; border-bottom: 1px solid #F28500;">' . esc_html($keyword['keyword_name']) . '</td>
                <td style="padding: 13px; color: #333; text-align: center; font-size: 14px; border-top: 1px solid #F28500; border-bottom: 1px solid #F28500;">' . esc_html($keyword['clicks']) . '</td>
                <td style="padding: 13px; color: #333; text-align: center; font-size: 14px; border-top: 1px solid #F28500; border-bottom: 1px solid #F28500;">' . esc_html($keyword['impressions']) . '</td>
                <td style="padding: 13px; color: #333; text-align: center; font-size: 14px; border-top-right-radius: 10px; border-bottom-right-radius: 10px; border-right: 1px solid #F28500; border-top: 1px solid #F28500; border-bottom: 1px solid #F28500;">' . esc_html($srkit_kt_data_position) . ' ' . '</td>
            </tr>';
            $srkit_kt_report_count++;
            }

            $srkit_th_email_message .= '</tbody></table></section>';

            // Footer section
            $srkit_th_email_message .= '
            <div style="background: linear-gradient(to right, #ffb84d, #ff7a00,); height: 15px;"></div>
            <footer style="background-color: #ffffff; padding-top: 20px; font-family: Arial, sans-serif;">
            <div style="max-height: 100px; height: 100%; border-radius: 5px; display: flex; align-items: center; background: linear-gradient(to right, #ffb84d, #ff7a00); padding: 30px; color: #ffffff; text-align: center;">
                <div style="flex: 0 0 auto; margin-right: 20px; text-align: center;">
                <img src="' . plugin_dir_url(__FILE__) . 'images/seorepairkit-logo.png" alt="SeoRepairKit" style="max-height: 70px; width: 60px; box-shadow: 0px 0px 10px 2px rgba(255, 255, 255, 0.8); border-radius: 4px;">
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
            $srkit_report_email_headers = ['Content-Type: text/html; charset=UTF-8'];
            wp_mail($srkit_admin_email_report, $srkit_th_email_subject, $srkit_th_email_message, $srkit_report_email_headers);
        }

        /**
         * Display keytrack settings page.
         */
        public function srkit_keytrack_settings_page() {
            if ( ! class_exists( 'Google\Site_Kit\Plugin' ) ) {
                echo '<p>' . __( 'Google Site Kit plugin is required for this plugin to work. Please install and activate it.', 'seorepairkit' ) . '</p>';
                return;
            }

            if ( $_SERVER['REQUEST_METHOD'] === 'POST' && check_admin_referer( 'srkit_keytrack_settings' ) ) {
                $this->srkit_save_keytrack_settings();
            }

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

            if ( $_SERVER['REQUEST_METHOD'] !== 'POST' || ! isset( $_POST['_wpnonce'] ) || ! wp_verify_nonce( $_POST['_wpnonce'], 'srkit_keytrack_settings' ) ) {
                wp_die(__('Invalid request or missing nonce verification.', 'seorepairkit'));
            }

            $srkit_keytrack_name        = sanitize_text_field( $_POST['keytrack_name'] );
            $srkit_th_selected_keywords = isset( $_POST['selected_keywords'] ) ? array_map( 'sanitize_text_field', (array)$_POST['selected_keywords'] ) : [];
            $srkit_th_form_date_range   = sanitize_text_field( $_POST['date_range'] );
        
            // Calculate next_run_at based on the selected schedule
            $srkit_kt_interval_map = [
                'Demo For Next 2 Minutes'   => '+2 minutes',
                '7 days'                    => '+7 days',
                '14 days'                   => '+14 days',
                '28 days'                   => '+28 days',
                '90 days'                   => '+90 days',
            ];
            $next_run_at = date( 'Y-m-d H:i:s', strtotime( $srkit_kt_interval_map[$srkit_th_form_date_range] ?? '+2 minutes' ) );

            $wpdb->insert(
                "{$wpdb->prefix}srkit_keytrack_settings",
                [
                    'keytrack_name'         => sanitize_text_field($srkit_keytrack_name),
                    'selected_keywords'     => maybe_serialize(array_map('sanitize_text_field', $srkit_th_selected_keywords)),
                    'date_range'            => sanitize_text_field($srkit_th_form_date_range),
                    'setting_value'         => wp_json_encode([
                        'selected_keywords' => $srkit_th_selected_keywords,
                        'date_range'        => $srkit_th_form_date_range,
                    ]),
                    'created_at'  => current_time('mysql'),
                    'updated_at'  => current_time('mysql'),
                    'next_run_at' => $next_run_at,
                ],
                ['%s', '%s', '%s', '%s', '%s', '%s']
            );
            
            $this->srkit_kt_form_schedule_gsc_data( $srkit_th_form_date_range );
        
            // echo '<div class="updated notice"><p>' . __( 'Settings saved and cron job scheduled successfully!', 'seorepairkit' ) . '</p></div>';
            echo '<div class="updated notice"><p>' . esc_html__('Settings saved and cron job scheduled successfully!', 'seorepairkit') . '</p></div>';
        }

        /**
         * Display the keytrack settings form.
         */
        private function srkit_display_keytrack_settings_form() {
            // Fetch top keywords from Google Site Kit's Search Console module
            global $wpdb;
            $srkit_th_last_saved_settings = $wpdb->get_row(
                $wpdb->prepare("SELECT * FROM {$wpdb->prefix}srkit_keytrack_settings ORDER BY updated_at DESC LIMIT %d", 1),
                ARRAY_A
            );            
            $srkit_keytrack_name        = $srkit_th_last_saved_settings['keytrack_name'] ?? '';
            $srkit_th_selected_keywords = maybe_unserialize( $srkit_th_last_saved_settings['selected_keywords'] ?? [] );
            $srkit_th_form_date_range   = $srkit_th_last_saved_settings['date_range'] ?? 'Demo For Next 2 Minutes';
            $next_run_at                = $srkit_th_last_saved_settings['next_run_at'] ?? null;

            // Determine if the submit button should be disabled
            $srkit_kt_disable_submit         = $next_run_at && strtotime( $next_run_at ) > time();
            $next_run_at_formatted           = $srkit_kt_disable_submit ? date( 'Y-m-d H:i:s', strtotime( $next_run_at ) ) : '';

            if ( class_exists( 'Google\Site_Kit\Plugin' ) ) {
                $srkit_th_sitekit_instance       = \Google\Site_Kit\Plugin::instance();
                $srkit_th_sitekit_context        = $srkit_th_sitekit_instance->context();
                $srkit_th_sitekit_options        = new \Google\Site_Kit\Core\Storage\Options( $srkit_th_sitekit_context );
                $srkit_th_sitekit_user_options   = new \Google\Site_Kit\Core\Storage\User_Options( $srkit_th_sitekit_context );
                $srkit_th_sitekit_authentication = new \Google\Site_Kit\Core\Authentication\Authentication( $srkit_th_sitekit_context, $srkit_th_sitekit_options, $srkit_th_sitekit_user_options );
                $srkit_th_sitekit_modules        = new \Google\Site_Kit\Core\Modules\Modules( $srkit_th_sitekit_context, $srkit_th_sitekit_options, $srkit_th_sitekit_user_options, $srkit_th_sitekit_authentication );
                $srkit_th_sitekit_search_console = $srkit_th_sitekit_modules->get_module( 'search-console' );

                // Fetch all indexed keywords without restricting to a specific date range
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
                    if ( ! empty( $srkit_th_top_keywords ) ) {
                        foreach ( $srkit_th_top_keywords as $srkit_th_row ) {
                            $srkit_th_query = esc_html( $srkit_th_row['keys'][0] );
                            $srkit_th_selected = in_array( $srkit_th_query, $srkit_th_selected_keywords ) ? 'selected' : '';
                            // echo "<option value='$srkit_th_query' $srkit_th_selected>$srkit_th_query</option>";
                            echo "<option value='" . esc_attr( $srkit_th_query ) . "' " . selected( in_array( $srkit_th_query, $srkit_th_selected_keywords ), true, false ) . ">" . esc_html( $srkit_th_query ) . "</option>";
                        }
                    } else {
                        echo '<option>' . esc_html__( 'No keywords found.', 'seorepairkit' ) . '</option>';
                    }
                    ?>
                </select>

                <label for="date_range"><?php esc_html_e( 'Performance Tracking Period:', 'seorepairkit' ); ?></label>
                <select name="date_range" id="date_range" required>
                    <option value="Demo For Next 2 Minutes" <?php selected( $srkit_th_form_date_range, 'Demo For Next 2 Minutes' ); ?>><?php esc_html_e( 'Demo For Next 2 Minutes', 'seorepairkit' ); ?></option>
                    <option value="7 days" <?php selected( $srkit_th_form_date_range, '7 days' ); ?>><?php esc_html_e( 'Next 7 Days', 'seorepairkit' ); ?></option>
                    <option value="14 days" <?php selected( $srkit_th_form_date_range, '14 days' ); ?>><?php esc_html_e( 'Next 14 Days', 'seorepairkit' ); ?></option>
                    <option value="28 days" <?php selected( $srkit_th_form_date_range, '28 days' ); ?>><?php esc_html_e( 'Next 28 Days', 'seorepairkit' ); ?></option>
                    <option value="90 days" <?php selected( $srkit_th_form_date_range, '90 days' ); ?>><?php esc_html_e( 'Next 3 Months', 'seorepairkit' ); ?></option>
                </select>

                <input type="submit" id="submit-keytrack-settings" class="srk-keytrack-settings-form-button" value="<?php esc_attr_e( 'Submit', 'seorepairkit' ); ?>" <?php disabled( $srkit_kt_disable_submit ); ?>>
            </form>

            <?php if ( $srkit_kt_disable_submit ): ?>
                <p class="srkit-th-disable-button" style="font-size: 16px; color: #555; color: #0B1D51; margin: 1em 0.5em;">
                    <?php esc_html_e( 'Settings form is temporarily disabled until the next scheduled run at:', 'seorepairkit' ); ?>
                    <strong style="font-weight: bold; color: #0B1D51;"><?php echo esc_html( $next_run_at_formatted ); ?></strong>
                </p>
                <p class="srkit-th-email-message" style="font-size: 16px; font-weight: bold; color: #0B1D51; margin: 1em 0.5em;">
                    ✨ <?php esc_html_e( 'Make sure your admin email is set up to receive reports!', 'seorepairkit' ); ?> 📧
                </p>
            <?php endif; ?>
            </div>
            <?php
        }

        /**
        * Display recent GSC data in a WordPress table.
        */
        private function srkit_display_recent_gsc_data() {
            global $wpdb;
            $srkit_th_table_name  = $wpdb->prefix . 'srkit_gsc_data';
        
            // Fetch the most recent GSC data and its associated keytrack name
            $srkit_th_recent_data = $wpdb->get_row( "SELECT gsc_data, keytrack_name FROM $srkit_th_table_name ORDER BY id DESC LIMIT 1", ARRAY_A );
        
            if ( $srkit_th_recent_data ) {
                $srkit_th_gsc_data = json_decode( $srkit_th_recent_data['gsc_data'], true );
                $srkit_keytrack_name = $srkit_th_recent_data['keytrack_name']; // Get relevant keytrack_name
        
                echo '<div class="srkit-right">';
                echo '<h3 class="srk-form-heading-style">' . esc_html__( '', 'seorepairkit' ) . ' ' . esc_html( $srkit_keytrack_name ) . '</h3>';
                
                // Display overall data
                echo '<div class="srkit-gsc-stats-container">';
                echo '<div class="srkit-gsc-stat-box"><h3>' . esc_html__( 'Total Clicks', 'seorepairkit' ) . '</h3><p>' . esc_html( $srkit_th_gsc_data['summary']['total_clicks'] ) . '</p></div>';
                echo '<div class="srkit-gsc-stat-box"><h3>' . esc_html__( 'Total Impressions', 'seorepairkit' ) . '</h3><p>' . esc_html( $srkit_th_gsc_data['summary']['total_impressions'] ) . '</p></div>';
                echo '<div class="srkit-gsc-stat-box"><h3>' . esc_html__( 'Average CTR', 'seorepairkit' ) . '</h3><p>' . number_format( $srkit_th_gsc_data['summary']['average_ctr'] * 100, 2 ) . '%</p></div>';
                echo '<div class="srkit-gsc-stat-box"><h3>' . esc_html__( 'Average Position', 'seorepairkit' ) . '</h3><p>' . number_format( $srkit_th_gsc_data['summary']['average_position'], 2 ) . '</p></div>';
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

                $srkit_kt_counter = 1; // Initialize the counter

                foreach ( $srkit_th_gsc_data['keywords'] as $srkit_th_gsc_keywords_data ) {
                    $srkit_th_gsc_position = number_format( $srkit_th_gsc_keywords_data['position'], 2 );
                    echo '<tr>
                            <td class="srkit-center"><span class="counter">' . esc_html( $srkit_kt_counter ) . '.</span> <span class="keywordname">' . esc_html( $srkit_th_gsc_keywords_data['keyword_name'] ) . '</span></td>
                            <td class="center">' . esc_html( $srkit_th_gsc_keywords_data['clicks'] ) . '</td>
                            <td class="center">' . esc_html( $srkit_th_gsc_keywords_data['impressions'] ) . '</td>
                            <td class="center">' . esc_html( $srkit_th_gsc_position ) . ' ' . '</td>
                        </tr>';
                    
                    $srkit_kt_counter++; // Increment the counter
                }
                echo '</tbody> <tfoot>
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
                </table>';

            } else {
                echo '<p class="srk-general-message-style">' . esc_html__( '🚀 Set Up Your Thresholds to Track Website Content Performance Like a Pro! 📈 ✨ Get reports straight to your inbox! 📧', 'seorepairkit' ) . '</p>';
            }
        }
 
    }
    // Instantiate the SEORepairKit_KeyTrack_Settings class.
    new SEORepairKit_KeyTrack_Settings();
}
