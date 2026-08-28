<?php
/**
 * Fired during plugin deactivation.
 *
 * This class defines all code necessary to run during the plugin's deactivation.
 *
 * @link       https://seorepairkit.com
 * @since      1.0.1
 * @author     TorontoDigits <support@torontodigits.com>
 */
class SeoRepairKit_Deactivator {

	/**
	 * Short Description. (use period)
	 *
	 * Long Description.
	 *
	 * @since    1.0.1
	 */
	public static function deactivate() {

		// Send status to CRM
		self::send_status_to_crm( 'deactivated' );

		wp_clear_scheduled_hook( 'srk_spam_monitor_scheduled_scan' );
		wp_clear_scheduled_hook( 'srk_spam_monitor_scheduled_serp_scan' );
		delete_option( 'srk_spam_monitor_schedule_lock' );
		delete_option( 'srk_spam_monitor_scan_lock' );
	}

	/**
     * Send plugin status to CRM
     * 
     * * @since    2.1.0
     */
    private static function send_status_to_crm( $status ) {
        $api_url = SRK_API_Client::get_api_url( SRK_API_Client::ENDPOINT_PLUGIN_STATUS );

        $plugin_id = get_option( 'srk_plugin_id' );
        $plugin_version = SEO_REPAIR_KIT_VERSION;

        // Prepare the data payload
        $data = array( 
            'plugin_id' => $plugin_id,
            'status' => $status, 
        );

        // Send the status update
        $response = wp_remote_post( $api_url, array( 
            'body' => wp_json_encode( $data ),
            'headers' => array( 'Content-Type' => 'application/json' ), 
        ) );

        // Silently handle errors
        if ( is_wp_error( $response ) ) {
            // Request failed
        }
    }
}
