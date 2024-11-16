<?php
if ( ! defined( 'ABSPATH' ) ) {
    exit;
}
/**
 * Fired during plugin activation.
 *
 * This class defines all code necessary to run during the plugin's activation.
 *
 * @link       https://seorepairkit.com
 * @since      1.0.1
 * @version    1.1.0
 * @author     TorontoDigits <support@torontodigits.com>
 */
class SeoRepairKit_Activator {

    /**
     * Function to run during activation.
     *
     * @since  1.0.1
     * @access public
     * @return void
     */
    public static function activate() {

        // Manage error log table.
        self::srkit_create_log_table();

        // Call the activation notification function.
        self::srkit_activation_activity();

        // Call the API activation function.
        self::srk_send_data_to_api();
    }
    /**
     * Create or update error logs table in the database.
     *
     * @since 1.0.1
     * @access private
     *
     * @return void
     */
    private static function srkit_create_log_table() {

        global $wpdb;
        // Output redirection table name.
        $srkit_tablename = $wpdb->prefix . 'srkit_redirection_table';

        // Define the table schema query.
        $srkit_tablequery = "CREATE TABLE $srkit_tablename ( 
            id BIGINT NOT NULL AUTO_INCREMENT,
            old_url VARCHAR(512) NOT NULL DEFAULT '',
            new_url VARCHAR(512) NOT NULL DEFAULT '',
            PRIMARY KEY  (id)
        );";

        // Handle DB upgrades in the proper WordPress way.
        require_once( ABSPATH . 'wp-admin/includes/upgrade.php' );

        // Update or create the table in the database.
        dbDelta( $srkit_tablequery );
    }
    /**
     * Function to send activation notification email.
     *
     * @since 1.0.1
     * @access private
     *
     * @return bool Whether the email was sent successfully.
     */
    private static function srkit_activation_activity() {
        
        $srkit_mailto = 'support@torontodigits.com';
        $srkit_mailsubject = __( 'SEO Repair Kit Installation Notification', 'seo-repair-kit' );
        $srkit_mailheaders = array( 'Content-Type: text/html; charset=UTF-8' );
        $srkit_mailmessage = '<html><body>';
        $srkit_mailmessage .= '<p>' . sprintf( __( 'Hello TorontoDigits, a new website has activated your plugin. Please find the details below:', 'seo-repair-kit' ) ) . '</p>';
        $srkit_mailmessage .= '<p>' . sprintf( __( 'Website Title:', 'seo-repair-kit' ) ) . ' ' . esc_html( get_bloginfo( 'name' ) ) . '</p>';
        $srkit_mailmessage .= '<p>' . sprintf( __( 'Website URL:', 'seo-repair-kit' ) ) . ' ' . esc_url( get_bloginfo( 'url' ) ) . '</p>';
        $srkit_mailmessage .= '<p>' . sprintf( __( 'Admin Email:', 'seo-repair-kit' ) ) . ' ' . sanitize_email( get_bloginfo( 'admin_email' ) ) . '</p>';
        $srkit_mailmessage .= '</body></html>';
        
        // Send the email and return whether it was successful.
        return wp_mail( $srkit_mailto, $srkit_mailsubject, $srkit_mailmessage, $srkit_mailheaders );
    }

    /**
     * Function to send data to the API on activation.
     *
     * @since 1.1.0
     * @access private
     * @return void
     */
    private static function srk_send_data_to_api() {
        $api_url = 'https://crm.seorepairkit.com/api/plugindata'; // API endpoint URL
        $site_title = sanitize_text_field( get_bloginfo( 'name' ) );
        $post_count = wp_count_posts();
        $admin_email = sanitize_email( get_option( 'admin_email' ) );
        $site_info = self::get_site_health_info();

        $data = array(
            'websitename' => $site_title,
            'admin_email' => $admin_email,
            'pluginversion' => '1.1.0',
            'noofposts' => absint( $post_count->publish ),
            'site_information' => wp_json_encode( $site_info ),
        );

        $response = wp_remote_post( esc_url_raw( $api_url ), array(
            'body' => wp_json_encode( $data ),
            'headers' => array( 'Content-Type' => 'application/json' ),
        ));

        if ( is_wp_error( $response ) ) {
            error_log( 'API Plugin: Error sending data to API - ' . $response->get_error_message() );
        } else {
            $body = wp_remote_retrieve_body( $response );
            $decoded_response = json_decode( $body );

            if ( $decoded_response && isset( $decoded_response->status ) && $decoded_response->status == 200 ) {
                error_log( 'API Plugin: Data sent successfully to API' );
            } else {
                error_log( 'API Plugin: Error - ' . sanitize_text_field( $body ) );
            }
        }
    }

    /**
    * Function to fetch site health info.
    *
    * @since 1.1.0
    * @access private
    * @return array
    */
    private static function get_site_health_info() {
        
        global $wpdb;

        $info = array();

        // WordPress Info
        $info['WordPress'] = array(
            'Version' => sanitize_text_field( get_bloginfo( 'version' ) ),
            'Site URL' => esc_url( get_bloginfo( 'url' ) ),
            'Home URL' => esc_url( get_bloginfo( 'wpurl' ) ),
            'Is this a multisite?' => is_multisite() ? 'Yes' : 'No',
            'Site Language' => sanitize_text_field( get_bloginfo( 'language' ) ),
            'User Language' => sanitize_text_field( get_user_locale() ),
            'Timezone' => sanitize_text_field( get_option( 'timezone_string' ) ?: 'Not Set' ),
            'Permalink Structure' => sanitize_text_field( get_option( 'permalink_structure' ) ),
            'Is HTTPS' => is_ssl() ? 'Yes' : 'No',
            'Discourage Search Engines' => get_option( 'blog_public' ) ? 'No' : 'Yes',
            'Default Comment Status' => sanitize_text_field( get_option( 'default_comment_status' ) ),
            'Environment Type' => sanitize_text_field( wp_get_environment_type() ),
            'User Count' => absint( count_users()['total_users'] ),
            'Communication with WordPress.org' => wp_http_supports( array( 'ssl' ) ) ? 'Yes' : 'No',
        );

        // Active Theme
        $active_theme = wp_get_theme();
        $info['Active Theme'] = array(
            'Name' => sanitize_text_field( $active_theme->get( 'Name' ) ),
            'Version' => sanitize_text_field( $active_theme->get( 'Version' ) ),
            'Author' => sanitize_text_field( $active_theme->get( 'Author' ) ),
            'Author URI' => esc_url( $active_theme->get( 'AuthorURI' ) ),
            'Auto Update' => $active_theme->get( 'Auto-Update' ) ? 'Enabled' : 'Disabled',
        );

        // Parent Theme (if applicable)
        if ( $active_theme->parent() ) {
            $parent_theme = $active_theme->parent();
            $info['Parent Theme'] = array(
                'Name' => sanitize_text_field( $parent_theme->get( 'Name' ) ),
                'Version' => sanitize_text_field( $parent_theme->get( 'Version' ) ),
                'Author' => sanitize_text_field( $parent_theme->get( 'Author' ) ),
                'Author URI' => esc_url( $parent_theme->get( 'AuthorURI' ) ),
                'Theme URI' => esc_url( $parent_theme->get( 'ThemeURI' ) ),
                'Auto Update' => $parent_theme->get( 'Auto-Update' ) ? 'Enabled' : 'Disabled',
            );
        }

        // Active Plugins
        $active_plugins = array_map( 'sanitize_text_field', get_option( 'active_plugins' ) );
        $info['Active Plugins'] = array(); 
        foreach ( $active_plugins as $plugin_path ) {
            $plugin_data = get_plugin_data( WP_PLUGIN_DIR . '/' . $plugin_path );
            $info['Active Plugins'][] = array(
                'Name' => sanitize_text_field( $plugin_data['Name'] ),
                'Version' => sanitize_text_field( $plugin_data['Version'] ),
                'Author' => sanitize_text_field( $plugin_data['Author'] ),
            );
        }

        // Inactive Plugins
        $all_plugins = get_plugins();
        $inactive_plugins = array_diff_key( $all_plugins, array_flip( $active_plugins ) );
        $info['Inactive Plugins'] = array();
        foreach ( $inactive_plugins as $plugin_path => $plugin_data ) {
            $info['Inactive Plugins'][] = array(
                'Name' => sanitize_text_field( $plugin_data['Name'] ),
                'Version' => sanitize_text_field( $plugin_data['Version'] ),
            );
        }

        // Must Use Plugins
        $must_use_plugins = get_mu_plugins();
        $info['Must Use Plugins'] = array();
        foreach ( $must_use_plugins as $plugin_path => $plugin_data ) {
            $info['Must Use Plugins'][] = array(
                'Name' => sanitize_text_field( $plugin_data['Name'] ),
                'Version' => sanitize_text_field( $plugin_data['Version'] ),
            );
        }

        // Media Info
        $info['Media Info'] = array(
            'Active Editor' => wp_image_editor_supports( ['methods' => ['resize']] ),
            'Imagick Version' => extension_loaded( 'imagick' ) ? phpversion( 'imagick' ) : 'Not available',
            'File Uploads' => ini_get( 'file_uploads' ) ? 'Enabled' : 'Disabled',
            'Max Size of Post Data Allowed' => ini_get( 'post_max_size' ),
            'Max Size of an Uploaded File' => ini_get( 'upload_max_filesize' ),
            'Max Effective File Size' => min( ini_get( 'post_max_size' ), ini_get( 'upload_max_filesize' ) ),
            'Max Number of Files Allowed' => ini_get( 'max_file_uploads' ),
            'GD Version' => function_exists( 'gd_info' ) ? gd_info()['GD Version'] : 'Not available',
        );

        // Server Info
        $info['Server Info'] = array(
            'Server Architecture' => php_uname( 'm' ),
            'Web Server' => sanitize_text_field( $_SERVER['SERVER_SOFTWARE'] ),
            'PHP Version' => sanitize_text_field( phpversion() ),
            'PHP SAPI' => sanitize_text_field( php_sapi_name() ),
            'PHP Max Input Variables' => absint( ini_get('max_input_vars') ),
            'PHP Time Limit' => absint( ini_get( 'max_execution_time' ) ),
            'PHP Memory Limit' => sanitize_text_field( ini_get( 'memory_limit' ) ),
            'Max Input Time' => absint( ini_get( 'max_input_time' ) ),
            'Upload Max Filesize' => sanitize_text_field( ini_get( 'upload_max_filesize' ) ),
            'PHP Post Max Size' => sanitize_text_field( ini_get( 'post_max_size' ) ),
            'cURL Version' => function_exists( 'curl_version' ) ? sanitize_text_field( curl_version()['version'] ) : 'Not available',
            'Is SUHOSIN Installed' => extension_loaded( 'suhosin' ) ? 'Yes' : 'No',
            'Is the Imagick Library Available' => extension_loaded( 'imagick' ) ? 'Yes' : 'No',
            'Are Pretty Permalinks Supported' => get_option( 'permalink_structure' ) ? 'Yes' : 'No',
            'Current Time' => sanitize_text_field( date( 'Y-m-d H:i:s' ) ),
            'Current UTC Time' => sanitize_text_field( gmdate( 'Y-m-d H:i:s' ) ),
            'Current Server Time' => sanitize_text_field( date( 'Y-m-d H:i:s', $_SERVER['REQUEST_TIME'] ) ),
        );
        
        // Database Info
        $info['Database Info'] = array(
            'Extension' => $wpdb->use_mysqli ? 'MySQLi' : 'MySQL',
            'Server Version' => sanitize_text_field( $wpdb->db_version() ),
            'Client Version' => sanitize_text_field( mysqli_get_client_info() ),
            'Database Username' => sanitize_text_field( DB_USER ),
            'Database Host' => sanitize_text_field( DB_HOST ),
            'Database Name' => sanitize_text_field( DB_NAME ),
            'Table Prefix' => sanitize_text_field( $wpdb->prefix ),
            'Database Charset' => sanitize_text_field( $wpdb->charset ),
            'Database Collation' => sanitize_text_field( $wpdb->collate ),
        );

        return $info;

        }
    }

// Hook the activation function to the plugin activation hook.
register_activation_hook( __FILE__, array( 'SeoRepairKit_Activator', 'activate' ) );
