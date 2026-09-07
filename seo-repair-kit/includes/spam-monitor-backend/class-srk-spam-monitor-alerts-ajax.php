<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * AJAX handlers for SERP alert settings.
 */
class SRK_Spam_Monitor_Alerts_Ajax {

	/**
	 * Constructor.
	 */
	public function __construct() {
		add_action( 'wp_ajax_srk_sm_save_alert_settings', array( $this, 'handle_save_settings' ) );
		add_action( 'wp_ajax_srk_sm_test_alert_email', array( $this, 'handle_test_email' ) );
	}

	/**
	 * Save alert settings.
	 *
	 * @return void
	 */
	public function handle_save_settings() {
		$this->verify();

		$saved = SRK_Spam_Monitor_Alerts::save_settings( $_POST );
		if ( ! $saved ) {
			wp_send_json_error( array( 'message' => __( 'Failed to save alert settings.', 'seo-repair-kit' ) ) );
		}

		wp_send_json_success(
			array(
				'message'  => __( 'Alert settings saved.', 'seo-repair-kit' ),
				'settings' => SRK_Spam_Monitor_Alerts::get_settings(),
			)
		);
	}

	/**
	 * Send a test email.
	 *
	 * @return void
	 */
	public function handle_test_email() {
		$this->verify();

		$recipient = sanitize_email( wp_unslash( $_POST['recipient'] ?? '' ) );
		if ( ! SRK_Spam_Monitor_Alerts::send_test_email( $recipient ) ) {
			wp_send_json_error( array( 'message' => __( 'Failed to send test email. Check recipient email settings.', 'seo-repair-kit' ) ) );
		}

		wp_send_json_success( array( 'message' => __( 'Test email sent successfully.', 'seo-repair-kit' ) ) );
	}

	/**
	 * Verify capability and nonce.
	 *
	 * @return void
	 */
	private function verify() {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_send_json_error( array( 'message' => __( 'Permission denied.', 'seo-repair-kit' ) ), 403 );
		}

		check_ajax_referer( 'srk_sm_alerts_nonce', 'nonce' );
	}
}
