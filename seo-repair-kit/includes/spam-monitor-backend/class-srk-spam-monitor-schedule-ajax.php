<?php
/**
 * Secure administrator actions for Spam Monitor scheduling.
 *
 * @package Seo_Repair_Kit
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Handles schedule save and Run Now requests only.
 */
class SRK_Spam_Monitor_Schedule_Ajax {
	/** @var SRK_Spam_Monitor_Scheduler */
	private $scheduler;

	/**
	 * Register AJAX hooks.
	 *
	 * @param SRK_Spam_Monitor_Scheduler $scheduler Scheduler service.
	 */
	public function __construct( SRK_Spam_Monitor_Scheduler $scheduler ) {
		$this->scheduler = $scheduler;
		add_action( 'wp_ajax_srk_sm_save_schedule', array( $this, 'save_schedule' ) );
		add_action( 'wp_ajax_srk_sm_reset_schedule', array( $this, 'reset_schedule' ) );
		add_action( 'wp_ajax_srk_sm_run_schedule_now', array( $this, 'run_now' ) );
		add_action( 'wp_ajax_srk_sm_get_schedule_status', array( $this, 'get_status' ) );
	}

	/**
	 * Restore default schedule settings and remove the pending event.
	 *
	 * @return void
	 */
	public function reset_schedule() {
		$this->authorize();

		$result = $this->scheduler->reset_settings();
		if ( is_wp_error( $result ) ) {
			wp_send_json_error( array( 'message' => $result->get_error_message(), 'code' => $result->get_error_code() ), 409 );
		}

		wp_send_json_success(
			array(
				'message' => __( 'Schedule settings were reset to the recommended defaults. Existing scan records were preserved.', 'seo-repair-kit' ),
				'status'  => SRK_Spam_Monitor_Scheduler::get_status(),
			)
		);
	}

	/**
	 * Save schedule settings.
	 *
	 * @return void
	 */
	public function save_schedule() {
		$this->authorize();

		// Nonce and capability are verified by authorize() immediately above.
		// phpcs:disable WordPress.Security.NonceVerification.Missing
		$result = $this->scheduler->save_settings(
			array(
				'enabled'            => isset( $_POST['enabled'] ) && rest_sanitize_boolean( wp_unslash( $_POST['enabled'] ) ),
				'frequency'          => sanitize_key( wp_unslash( $_POST['frequency'] ?? '' ) ),
				'serp_requests'      => absint( wp_unslash( $_POST['serp_requests'] ?? 0 ) ),
				'run_time'           => sanitize_text_field( wp_unslash( $_POST['run_time'] ?? '' ) ),
				'include_subdomains' => isset( $_POST['include_subdomains'] ) && rest_sanitize_boolean( wp_unslash( $_POST['include_subdomains'] ) ),
				'developer_mode'     => isset( $_POST['developer_mode'] ) && rest_sanitize_boolean( wp_unslash( $_POST['developer_mode'] ) ),
				'domain'             => sanitize_text_field( wp_unslash( $_POST['domain'] ?? '' ) ),
			)
		);
		// phpcs:enable WordPress.Security.NonceVerification.Missing

		if ( is_wp_error( $result ) ) {
			wp_send_json_error( array( 'message' => $result->get_error_message(), 'code' => $result->get_error_code() ), 400 );
		}

		wp_send_json_success(
			array(
				'message' => ! empty( $result['enabled'] ) ? __( 'Scheduled scans saved successfully.', 'seo-repair-kit' ) : __( 'Scheduled scans are paused. Existing scan records were preserved.', 'seo-repair-kit' ),
				'status'  => SRK_Spam_Monitor_Scheduler::get_status(),
			)
		);
	}

	/**
	 * Execute the saved schedule immediately.
	 *
	 * @return void
	 */
	public function run_now() {
		$this->authorize();

		$result = $this->scheduler->run_now();
		if ( is_wp_error( $result ) ) {
			wp_send_json_error( array( 'message' => $result->get_error_message(), 'code' => $result->get_error_code(), 'status' => SRK_Spam_Monitor_Scheduler::get_status() ), 400 );
		}

		wp_send_json_success(
			array(
				'message' => __( 'Scheduled scan completed and saved successfully.', 'seo-repair-kit' ),
				'scan_id' => absint( $result['scan_id'] ),
				'status'  => SRK_Spam_Monitor_Scheduler::get_status(),
			)
		);
	}

	/**
	 * Return current schedule status.
	 *
	 * @return void
	 */
	public function get_status() {
		$this->authorize();
		wp_send_json_success( array( 'status' => SRK_Spam_Monitor_Scheduler::get_status() ) );
	}

	/**
	 * Verify nonce and administrator capability.
	 *
	 * @return void
	 */
	private function authorize() {
		check_ajax_referer( SRK_Spam_Monitor_Scheduler::NONCE_ACTION, 'nonce' );
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_send_json_error( array( 'message' => __( 'Permission denied.', 'seo-repair-kit' ) ), 403 );
		}
	}
}
