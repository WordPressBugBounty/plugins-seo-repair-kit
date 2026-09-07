<?php
/**
 * Spam Monitor Google SERP provider bridge.
 *
 * @package Seo_Repair_Kit
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Sends safe admin-triggered SERP demo requests to the local Python engine.
 */
class SRK_Spam_Monitor_SERP_Provider {

	const DEFAULT_ENDPOINT = 'https://cloud.seorepairkit.com/v1/scan/google-serp';
	const SYNC_STATUS_OPTION = 'srk_spam_monitor_serp_rules_sync_status';
	const TRIAL_STATUS_OPTION = 'srk_spam_monitor_serp_trial_status';
	const SERP_PROVIDER_STATUS_OPTION = 'srk_spam_monitor_serp_provider_status';
	const SERPAPI_STATUS_OPTION = 'srk_spam_monitor_serpapi_key_status';

	// Developer-only testing bypass. Set to false or remove before public release.
	const ALLOW_DEVELOPER_TESTING_RULES = true;

	/**
	 * SRK Cloud client.
	 *
	 * @var SRK_Spam_Monitor_Cloud_Client
	 */
	private $cloud_client;

	/**
	 * Constructor.
	 */
	public function __construct() {
		if ( class_exists( 'SRK_Spam_Monitor_Cloud_Client' ) ) {
			$this->cloud_client = new SRK_Spam_Monitor_Cloud_Client();
		}
	}

	/**
	 * Get configured Python SERP endpoint.
	 *
	 * @return string
	 */
	public function get_endpoint() {
		if ( $this->cloud_client ) {
			return $this->cloud_client->get_scan_url();
		}

		return esc_url_raw( self::DEFAULT_ENDPOINT );
	}

	/**
	 * Get Python service base URL from the configured scan endpoint.
	 *
	 * @return string
	 */
	public function get_base_endpoint() {
		if ( $this->cloud_client ) {
			return $this->cloud_client->get_base_url();
		}

		return esc_url_raw( preg_replace( '#/v1/scan/google-serp$#', '', self::DEFAULT_ENDPOINT ) );
	}

	/**
	 * Build an API URL that follows the configured endpoint version.
	 *
	 * @param string $path API path without leading slash.
	 * @return string
	 */
	public function get_api_endpoint( $path ) {
		if ( $this->cloud_client ) {
			return $this->cloud_client->get_api_url( $path );
		}

		return trailingslashit( $this->get_base_endpoint() ) . 'v1/' . ltrim( $path, '/' );
	}

	/**
	 * Build a hidden legacy API URL for backward-compatible retry checks.
	 *
	 * @param string $path API path without leading slash.
	 * @return string
	 */
	public function get_legacy_api_endpoint( $path ) {
		if ( $this->cloud_client ) {
			return $this->cloud_client->get_legacy_api_url( $path );
		}

		return trailingslashit( $this->get_base_endpoint() ) . ltrim( $path, '/' );
	}

	/**
	 * Run a Google SERP scan through the Python service.
	 *
	 * @param array $args Request args.
	 * @return array|WP_Error
	 */
	public function scan( array $args ) {
		$raw_domain = trim( (string) ( $args['domain'] ?? '' ) );
		$block_reason = $this->get_scan_block_reason( $raw_domain );
		if ( '' !== $block_reason ) {
			return new WP_Error( 'srk_serp_non_public_domain', $block_reason );
		}

		$domain = $this->sanitize_domain( $raw_domain );
		if ( '' === $domain ) {
			return new WP_Error( 'srk_serp_invalid_domain', __( 'Please enter a valid domain.', 'seo-repair-kit' ) );
		}

		$max_results       = max( 10, min( 2000, absint( $args['max_results'] ?? 100 ) ) );
		$max_serp_requests = max( 1, min( 200, absint( $args['max_serp_requests'] ?? 1 ) ) );
		$developer_mode    = self::ALLOW_DEVELOPER_TESTING_RULES && ! empty( $args['developer_mode'] );
		$site_id           = $this->get_site_id();
		$include_subdomains = ! array_key_exists( 'include_subdomains', $args ) || ! empty( $args['include_subdomains'] );

		$body = array_merge(
			$this->get_site_payload(),
			array(
			'site_id'           => $site_id,
			'domain'            => $domain,
			'max_results'       => $max_results,
			'max_serp_requests' => $max_serp_requests,
			'include_subdomains'=> $include_subdomains,
			'developer_testing_mode' => $developer_mode,
			)
		);

		if ( $developer_mode ) {
			$body['developer_note']         = 'Using local WordPress Spam Rules site_id for an external scan domain.';
		}

		$decoded = $this->post_json( $this->get_endpoint(), $body, 90 );
		if ( is_wp_error( $decoded ) ) {
			return $decoded;
		}

		if ( ! isset( $decoded['results'] ) || ! is_array( $decoded['results'] ) ) {
			$decoded['results'] = array();
		}

		if ( ! empty( $decoded['trial_status'] ) && is_array( $decoded['trial_status'] ) ) {
			$this->store_trial_status( $decoded['trial_status'] );
		}

		return $decoded;
	}

	/**
	 * Activate the one-click free trial in the Python engine.
	 *
	 * @return array|WP_Error
	 */
	public function activate_trial() {
		$status = $this->post_json( $this->get_api_endpoint( 'trial/activate' ), $this->get_site_payload(), 30 );
		if ( is_wp_error( $status ) ) {
			$this->store_trial_status(
				array(
					'trial_active' => false,
					'message'      => $status->get_error_message(),
				)
			);
			return $status;
		}

		$this->store_trial_status( $status );
		return $status;
	}

	/**
	 * Get free trial status from the Python engine.
	 *
	 * @return array|WP_Error
	 */
	public function get_trial_status() {
		$url    = add_query_arg( 'site_id', rawurlencode( $this->get_site_id() ), $this->get_api_endpoint( 'trial/status' ) );
		$status = $this->get_json( $url, 15 );
		if ( is_wp_error( $status ) ) {
			return $status;
		}

		$this->store_trial_status( $status );
		return $status;
	}

	/**
	 * Sync current WordPress Spam Rules to the Python engine.
	 *
	 * @return array|WP_Error
	 */
	public function sync_rules() {
		if ( ! $this->ensure_rules_dependencies() ) {
			return new WP_Error( 'srk_serp_rules_helper_missing', __( 'Spam rules helper is not available.', 'seo-repair-kit' ) );
		}

		$rules = SRK_Spam_Monitor_Rules_Helper::get_rules_for_serp_scan();
		$hash  = hash( 'sha256', wp_json_encode( $rules ) );
		$body  = array(
			'site_id'       => $this->get_site_id(),
			'rules_version' => gmdate( 'Y-m-d-His' ) . '-' . substr( $hash, 0, 8 ),
			'rules_hash'    => $hash,
			'rules'         => $rules,
		);

		$status = $this->post_json( $this->get_api_endpoint( 'rules/sync' ), $body, 30 );
		if ( is_wp_error( $status ) ) {
			$this->store_sync_status( array(
				'synced'  => false,
				'message' => $status->get_error_message(),
			) );
			return $status;
		}

		$status['local_rules_hash'] = $hash;
		$status['message']          = __( 'Spam rules synced with the Python SERP engine.', 'seo-repair-kit' );
		$this->store_sync_status( $status );

		return $status;
	}

	/**
	 * Get Python rules sync status for this site.
	 *
	 * @return array|WP_Error
	 */
	public function get_rules_status() {
		$url = add_query_arg( 'site_id', rawurlencode( $this->get_site_id() ), $this->get_api_endpoint( 'rules/status' ) );

		$response = wp_remote_get(
			$url,
			array(
				'timeout' => 15,
				'headers' => $this->get_request_headers( false, $this->get_site_id() ),
				'sslverify' => true,
			)
		);

		if ( is_wp_error( $response ) ) {
			return new WP_Error(
				'srk_serp_python_unreachable',
				$this->get_transport_error_message( $response ),
				array( 'original_error' => $response->get_error_message() )
			);
		}

		$status = (int) wp_remote_retrieve_response_code( $response );
		$raw    = wp_remote_retrieve_body( $response );
		if ( 200 !== $status ) {
			return $this->http_error( $status, $raw );
		}

		$decoded = json_decode( $raw, true );
		if ( JSON_ERROR_NONE !== json_last_error() || ! is_array( $decoded ) ) {
			return new WP_Error(
				'srk_serp_invalid_json',
				__( 'Python SERP engine returned invalid JSON. Please check the Python server output.', 'seo-repair-kit' )
			);
		}

		$decoded['local_rules_hash'] = $this->get_current_rules_hash();
		$decoded['hash_matches']      = ! empty( $decoded['rules_hash'] ) && hash_equals( (string) $decoded['rules_hash'], (string) $decoded['local_rules_hash'] );
		$this->store_sync_status( $decoded );

		return $decoded;
	}

	/**
	 * Get locally stored sync status.
	 *
	 * @return array
	 */
	public function get_local_sync_status() {
		$status = get_option( self::SYNC_STATUS_OPTION, array() );
		return is_array( $status ) ? $status : array();
	}

	/**
	 * Get locally cached free trial status.
	 *
	 * @return array
	 */
	public function get_local_trial_status() {
		$status = get_option( self::TRIAL_STATUS_OPTION, array() );
		return is_array( $status ) ? $status : array();
	}

	/**
	 * Get locally cached user SERP provider status.
	 *
	 * @return array
	 */
	public function get_local_serp_provider_status() {
		if ( ! $this->can_manage_user_serp_provider() ) {
			return $this->get_internal_trial_provider_status();
		}

		$status = get_option( self::SERP_PROVIDER_STATUS_OPTION, array() );
		if ( is_array( $status ) && ! empty( $status ) ) {
			return $status;
		}

		$status = get_option( self::SERPAPI_STATUS_OPTION, array() );
		return is_array( $status ) ? $status : array();
	}

	/**
	 * Backward-compatible local SerpApi status getter.
	 *
	 * @return array
	 */
	public function get_local_serpapi_key_status() {
		return $this->get_local_serp_provider_status();
	}

	/**
	 * Test user-owned SERP provider credentials without saving them.
	 *
	 * @param string $provider Provider slug.
	 * @param array  $credentials Provider credentials.
	 * @return array|WP_Error
	 */
	public function test_serp_provider( $provider, array $credentials ) {
		if ( ! $this->can_manage_user_serp_provider() ) {
			return $this->get_serp_provider_locked_error();
		}

		$provider    = $this->normalize_serp_provider( $provider );
		$credentials = $this->sanitize_provider_credentials( $provider, $credentials );
		if ( is_wp_error( $credentials ) ) {
			return $credentials;
		}

		$status = $this->post_json(
			$this->get_api_endpoint( 'provider/test' ),
			array(
				'site_id'     => $this->get_site_id(),
				'provider'    => $provider,
				'credentials' => $credentials,
			),
			20
		);

		if ( $this->is_route_not_found_error( $status ) ) {
			$status = $this->post_json(
				$this->get_legacy_api_endpoint( 'provider/test' ),
				array(
					'site_id'     => $this->get_site_id(),
					'provider'    => $provider,
					'credentials' => $credentials,
				),
				20,
				'serp_provider_route_missing'
			);
		}

		return $status;
	}

	/**
	 * Validate and save user-owned SERP provider credentials in SRK Cloud.
	 *
	 * @param string $provider Provider slug.
	 * @param array  $credentials Provider credentials.
	 * @return array|WP_Error
	 */
	public function save_serp_provider( $provider, array $credentials ) {
		if ( ! $this->can_manage_user_serp_provider() ) {
			return $this->get_serp_provider_locked_error();
		}

		$provider    = $this->normalize_serp_provider( $provider );
		$credentials = $this->sanitize_provider_credentials( $provider, $credentials );
		if ( is_wp_error( $credentials ) ) {
			return $credentials;
		}

		$status = $this->post_json(
			$this->get_api_endpoint( 'provider/save' ),
			array(
				'site_id'     => $this->get_site_id(),
				'provider'    => $provider,
				'credentials' => $credentials,
			),
			30
		);

		if ( $this->is_route_not_found_error( $status ) ) {
			$status = $this->post_json(
				$this->get_legacy_api_endpoint( 'provider/save' ),
				array(
					'site_id'     => $this->get_site_id(),
					'provider'    => $provider,
					'credentials' => $credentials,
				),
				30,
				'serp_provider_route_missing'
			);
		}

		if ( is_wp_error( $status ) ) {
			return $status;
		}

		$this->store_serp_provider_status( $status );
		return $status;
	}

	/**
	 * Remove user-owned SERP provider credentials from SRK Cloud.
	 *
	 * @return array|WP_Error
	 */
	public function remove_serp_provider() {
		if ( ! $this->can_manage_user_serp_provider() ) {
			return $this->get_serp_provider_locked_error();
		}

		$status = $this->post_json(
			$this->get_api_endpoint( 'provider/remove' ),
			array( 'site_id' => $this->get_site_id() ),
			20
		);

		if ( $this->is_route_not_found_error( $status ) ) {
			$status = $this->post_json(
				$this->get_legacy_api_endpoint( 'provider/remove' ),
				array( 'site_id' => $this->get_site_id() ),
				20,
				'serp_provider_route_missing'
			);
		}

		if ( $this->is_route_not_found_error( $status ) ) {
			$status = $this->get_provider_route_missing_status(
				__( 'Local SERP provider status was cleared, but the remote encrypted credentials could not be removed because the connected SRK Cloud server does not expose the provider remove route. Restart or redeploy the latest srk-cloud project, then click Remove Provider again.', 'seo-repair-kit' )
			);
			$this->store_serp_provider_status( $status );
			return $status;
		}

		if ( is_wp_error( $status ) ) {
			return $status;
		}

		$this->store_serp_provider_status( $status );
		return $status;
	}

	/**
	 * Refresh user-owned SERP provider status from SRK Cloud.
	 *
	 * @return array|WP_Error
	 */
	public function get_serp_provider_status() {
		if ( ! $this->can_manage_user_serp_provider() ) {
			return $this->get_internal_trial_provider_status();
		}

		$url    = add_query_arg( 'site_id', rawurlencode( $this->get_site_id() ), $this->get_api_endpoint( 'provider/status' ) );
		$status = $this->get_json( $url, 15 );
		if ( $this->is_route_not_found_error( $status ) ) {
			$url    = add_query_arg( 'site_id', rawurlencode( $this->get_site_id() ), $this->get_legacy_api_endpoint( 'provider/status' ) );
			$status = $this->get_json( $url, 15, 'serp_provider_route_missing' );
		}

		if ( $this->is_route_not_found_error( $status ) ) {
			$status = $this->get_provider_route_missing_status(
				__( 'SERP provider status is stale because the connected SRK Cloud server does not expose the provider status route. Restart or redeploy the latest srk-cloud project and refresh again.', 'seo-repair-kit' )
			);
			$this->store_serp_provider_status( $status );
			return $status;
		}

		if ( is_wp_error( $status ) ) {
			return $status;
		}

		$this->store_serp_provider_status( $status );
		return $status;
	}

	public function test_serpapi_key( $api_key ) {
		return $this->test_serp_provider( 'serpapi', array( 'api_key' => $api_key ) );
	}

	public function save_serpapi_key( $api_key ) {
		return $this->save_serp_provider( 'serpapi', array( 'api_key' => $api_key ) );
	}

	public function remove_serpapi_key() {
		return $this->remove_serp_provider();
	}

	public function get_serpapi_key_status() {
		return $this->get_serp_provider_status();
	}

	/**
	 * Get stable site identifier for the current WordPress installation.
	 *
	 * @param string $domain Deprecated. Site ID is always based on home_url().
	 * @return string
	 */
	public function get_site_id( $domain = '' ) {
		$host    = wp_parse_url( home_url(), PHP_URL_HOST );
		$site_id = $this->normalize_site_id( $host ? $host : home_url() );
		$site_id = apply_filters( 'srk_spam_monitor_serp_site_id', $site_id );

		return $this->normalize_site_id( $site_id );
	}

	/**
	 * Get hash for the current WordPress SERP rules payload.
	 *
	 * @return string
	 */
	public function get_current_rules_hash() {
		if ( ! $this->ensure_rules_dependencies() ) {
			return '';
		}

		return hash( 'sha256', wp_json_encode( SRK_Spam_Monitor_Rules_Helper::get_rules_for_serp_scan() ) );
	}

	/**
	 * Ensure rule helper dependencies are loaded for direct AJAX/provider calls.
	 *
	 * @return bool
	 */
	private function ensure_rules_dependencies() {
		if ( ! class_exists( 'SRK_Spam_Monitor_DB' ) ) {
			$db_file = __DIR__ . '/class-srk-spam-monitor-db.php';
			if ( file_exists( $db_file ) ) {
				require_once $db_file;
			}
		}

		if ( ! class_exists( 'SRK_Spam_Monitor_Rules_Helper' ) ) {
			$helper_file = __DIR__ . '/class-srk-spam-monitor-rules-helper.php';
			if ( file_exists( $helper_file ) ) {
				require_once $helper_file;
			}
		}

		return class_exists( 'SRK_Spam_Monitor_DB' ) && class_exists( 'SRK_Spam_Monitor_Rules_Helper' );
	}

	/**
	 * POST JSON to Python and decode the response.
	 *
	 * @param string $url URL.
	 * @param array  $body Request body.
	 * @param int    $timeout Timeout.
	 * @return array|WP_Error
	 */
	private function post_json( $url, array $body, $timeout, $context = '' ) {
		$body_json = wp_json_encode( $body );
		$response = wp_remote_post(
			$url,
			array(
				'timeout' => $timeout,
				'headers' => $this->get_request_headers( true, (string) ( $body['site_id'] ?? $this->get_site_id() ), $body_json ),
				'body'    => $body_json,
				'sslverify' => true,
			)
		);

		if ( is_wp_error( $response ) ) {
			return new WP_Error(
				'srk_serp_python_unreachable',
				$this->get_transport_error_message( $response ),
				array( 'original_error' => $response->get_error_message() )
			);
		}

		$status = (int) wp_remote_retrieve_response_code( $response );
		$raw    = wp_remote_retrieve_body( $response );
		if ( 200 !== $status ) {
			return $this->http_error( $status, $raw, $url, $context );
		}

		$decoded = json_decode( $raw, true );
		if ( JSON_ERROR_NONE !== json_last_error() || ! is_array( $decoded ) ) {
			return new WP_Error(
				'srk_serp_invalid_json',
				__( 'Python SERP engine returned invalid JSON. Please check the Python server output.', 'seo-repair-kit' )
			);
		}

		return $decoded;
	}

	/**
	 * GET JSON from Python and decode the response.
	 *
	 * @param string $url URL.
	 * @param int    $timeout Timeout.
	 * @return array|WP_Error
	 */
	private function get_json( $url, $timeout, $context = '' ) {
		$response = wp_remote_get(
			$url,
			array(
				'timeout' => $timeout,
				'headers' => $this->get_request_headers( false, $this->get_site_id() ),
				'sslverify' => true,
			)
		);

		if ( is_wp_error( $response ) ) {
			return new WP_Error(
				'srk_serp_python_unreachable',
				$this->get_transport_error_message( $response ),
				array( 'original_error' => $response->get_error_message() )
			);
		}

		$status = (int) wp_remote_retrieve_response_code( $response );
		$raw    = wp_remote_retrieve_body( $response );
		if ( 200 !== $status ) {
			return $this->http_error( $status, $raw, $url, $context );
		}

		$decoded = json_decode( $raw, true );
		if ( JSON_ERROR_NONE !== json_last_error() || ! is_array( $decoded ) ) {
			return new WP_Error(
				'srk_serp_invalid_json',
				__( 'Python SERP engine returned invalid JSON. Please check the Python server output.', 'seo-repair-kit' )
			);
		}

		return $decoded;
	}

	/**
	 * Build a WP_Error from a non-200 Python response.
	 *
	 * @param int    $status HTTP status.
	 * @param string $raw Raw body.
	 * @return WP_Error
	 */
	private function http_error( $status, $raw, $url = '', $context = '' ) {
		$decoded = json_decode( $raw, true );
		$detail  = is_array( $decoded ) ? ( $decoded['detail'] ?? $decoded ) : array();
		$message = '';
		$code    = 'srk_serp_non_200';

		if ( is_array( $detail ) ) {
			$message = sanitize_text_field( $detail['message'] ?? '' );
			$code    = sanitize_key( $detail['error'] ?? $code );
		}

		if ( '' === $message ) {
			$message = sprintf(
				/* translators: %d: HTTP status code */
				__( 'Python SERP engine returned HTTP %d. Please check the Python server and try again.', 'seo-repair-kit' ),
				$status
			);
		}

		if ( 404 === $status ) {
			$message = __( 'SRK Cloud route was not found. Please try again shortly. Developers should verify the SRK_CLOUD_API_BASE_URL override points to a current SRK Cloud deployment that exposes the official /v1 API.', 'seo-repair-kit' );
		}

		if ( 401 === $status ) {
			$message = __( 'SRK Cloud rejected this request because the secure request signature is missing, expired, or invalid. Please verify the WordPress signing secret and SRK Cloud signing configuration.', 'seo-repair-kit' );
		}

		if ( in_array( $status, array( 429, 503, 504 ), true ) ) {
			if ( 'serp_provider_no_results' === $code && '' !== $message ) {
				$message = $message;
			} else {
				$message = __( 'SRK Cloud is busy or responding slowly. Your scan was not completed. Please wait a moment and try again.', 'seo-repair-kit' );
			}
			if ( is_array( $detail ) && ! empty( $detail['retry_after_seconds'] ) ) {
				$message = sprintf(
					/* translators: %d: retry wait seconds */
					__( 'SRK Cloud is busy processing scans. Please retry in about %d seconds.', 'seo-repair-kit' ),
					absint( $detail['retry_after_seconds'] )
				);
			}
		}

		if ( 404 === $status && in_array( $context, array( 'serpapi_provider_route_missing', 'serp_provider_route_missing' ), true ) ) {
			$message = __( 'The connected SRK Cloud server does not expose the SERP provider routes yet. Restart or redeploy the latest srk-cloud project, then verify /v1/provider/status is available on the same tunnel before saving credentials.', 'seo-repair-kit' );
		}

		if ( 'trial_not_active' === $code ) {
			$site_id = is_array( $detail ) ? sanitize_text_field( $detail['site_id'] ?? $this->get_site_id() ) : $this->get_site_id();
			$message = sprintf(
				/* translators: %s: WordPress site ID */
				__( 'Free trial is not active for this WordPress site_id: %s. Activate Free Trial and try again.', 'seo-repair-kit' ),
				$site_id
			);
		}

		if ( 'rules_not_synced' === $code ) {
			$site_id = is_array( $detail ) ? sanitize_text_field( $detail['site_id'] ?? $this->get_site_id() ) : $this->get_site_id();
			$message = sprintf(
				/* translators: %s: WordPress site ID */
				__( 'Spam Rules are not synced for this WordPress site_id: %s. Sync Rules Now and try again.', 'seo-repair-kit' ),
				$site_id
			);
		}

		return new WP_Error( $code, $message, array( 'status' => $status, 'body' => $raw, 'url' => $url, 'context' => $context ) );
	}

	/**
	 * Check whether a Python request failed because the route was missing.
	 *
	 * @param mixed $response Response value.
	 * @return bool
	 */
	private function is_route_not_found_error( $response ) {
		if ( ! is_wp_error( $response ) ) {
			return false;
		}

		$data = $response->get_error_data();
		return is_array( $data ) && 404 === (int) ( $data['status'] ?? 0 );
	}

	/**
	 * Build a safe local status when the connected SRK Cloud lacks provider routes.
	 *
	 * @param string $message User-facing message.
	 * @return array
	 */
	private function get_provider_route_missing_status( $message ) {
		return array(
			'success'       => false,
			'connected'     => false,
			'provider'      => 'serper',
			'status'        => 'route_missing',
			'provider_mode' => 'unknown',
			'masked_credentials' => '',
			'masked_key'    => '',
			'last_tested_at'=> '',
			'message'       => $message,
		);
	}

	/**
	 * Check whether this site may manage user-owned SERP provider credentials.
	 *
	 * @return bool
	 */
	private function can_manage_user_serp_provider() {
		return class_exists( 'SRK_License_Helper' ) && SRK_License_Helper::is_spam_monitor_enabled();
	}

	/**
	 * Build the free/trial provider status shown when custom keys are locked.
	 *
	 * @return array
	 */
	private function get_internal_trial_provider_status() {
		return array(
			'success'       => true,
			'connected'     => false,
			'provider'      => 'serper',
			'status'        => 'trial_mode',
			'provider_mode' => 'internal_trial_key',
			'masked_credentials' => '',
			'masked_key'    => '',
			'last_tested_at'=> '',
			'message'       => __( 'Free users can scan with the SEO Repair Kit trial provider. Add the Spam Monitor module to connect your own SERP provider API key.', 'seo-repair-kit' ),
		);
	}

	/**
	 * Build an error for locked custom provider actions.
	 *
	 * @return WP_Error
	 */
	private function get_serp_provider_locked_error() {
		return new WP_Error(
			'srk_serp_provider_paid_required',
			__( 'Custom SERP provider keys require the paid Spam Monitor module. Free users can continue with the SEO Repair Kit trial provider.', 'seo-repair-kit' )
		);
	}

	/**
	 * Build headers for Python engine requests.
	 *
	 * @param bool $json Whether the request body is JSON.
	 * @return array
	 */
	private function get_request_headers( $json = false, $site_id = '', $body_json = '' ) {
		if ( $this->cloud_client ) {
			return $this->cloud_client->get_headers( $json, $site_id, $body_json );
		}

		return $json ? array( 'Content-Type' => 'application/json', 'Accept' => 'application/json' ) : array( 'Accept' => 'application/json' );
	}

	/**
	 * Build a clearer unreachable message for local/laptop engine testing.
	 *
	 * @return string
	 */
	private function get_unreachable_message() {
		$endpoint = $this->get_endpoint();

		if ( $this->is_local_endpoint( $endpoint ) && ! $this->is_local_wordpress_site() ) {
			return sprintf(
				/* translators: %s: configured Python engine endpoint */
				__( 'SRK Cloud is not reachable at %s. This WordPress site appears to be hosted/live, so it cannot reach a local laptop service. Developers should set SRK_CLOUD_API_BASE_URL to a public HTTPS tunnel or production deployment.', 'seo-repair-kit' ),
				$endpoint
			);
		}

		return sprintf(
			/* translators: %s: configured Python engine endpoint */
			__( 'SRK Cloud is not reachable at %s. Please try again shortly. Developers can override the endpoint with SRK_CLOUD_API_BASE_URL in wp-config.php.', 'seo-repair-kit' ),
			$endpoint
		);
	}

	/**
	 * Build a user-friendly transport error message.
	 *
	 * @param WP_Error $error Transport error.
	 * @return string
	 */
	private function get_transport_error_message( WP_Error $error ) {
		$message = strtolower( $error->get_error_message() );
		if (
			false !== strpos( $message, 'timed out' )
			|| false !== strpos( $message, 'timeout' )
			|| false !== strpos( $message, 'operation timed' )
		) {
			return __( 'SRK Cloud is responding slowly and the request timed out. Please wait a moment and try again with fewer indexed results if the server is under load.', 'seo-repair-kit' );
		}

		return $this->get_unreachable_message();
	}

	/**
	 * Check whether an endpoint points to a local/private address.
	 *
	 * @param string $url Endpoint URL.
	 * @return bool
	 */
	private function is_local_endpoint( $url ) {
		$host = strtolower( (string) wp_parse_url( $url, PHP_URL_HOST ) );
		if ( '' === $host ) {
			return false;
		}

		if ( in_array( $host, array( 'localhost', '127.0.0.1', '::1' ), true ) ) {
			return true;
		}

		if ( preg_match( '/\.(test|local|localhost|invalid)$/i', $host ) ) {
			return true;
		}

		if ( filter_var( $host, FILTER_VALIDATE_IP ) ) {
			return ! filter_var( $host, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE );
		}

		return false;
	}

	/**
	 * Check whether the current WordPress site itself is local/dev.
	 *
	 * @return bool
	 */
	private function is_local_wordpress_site() {
		$home = home_url();
		$host = strtolower( (string) wp_parse_url( $home, PHP_URL_HOST ) );
		if ( '' === $host ) {
			return false;
		}

		if ( in_array( $host, array( 'localhost', '127.0.0.1', '::1' ), true ) ) {
			return true;
		}

		if ( preg_match( '/\.(test|local|localhost|invalid)$/i', $host ) ) {
			return true;
		}

		if ( filter_var( $host, FILTER_VALIDATE_IP ) ) {
			return ! filter_var( $host, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE );
		}

		return false;
	}

	/**
	 * Store sync status locally.
	 *
	 * @param array $status Status data.
	 * @return void
	 */
	private function store_sync_status( array $status ) {
		$status['checked_at'] = current_time( 'mysql' );
		update_option( self::SYNC_STATUS_OPTION, $status, false );
	}

	/**
	 * Store trial status locally.
	 *
	 * @param array $status Status data.
	 * @return void
	 */
	private function store_trial_status( array $status ) {
		$status['checked_at'] = current_time( 'mysql' );
		update_option( self::TRIAL_STATUS_OPTION, $status, false );
	}

	/**
	 * Store user SERP provider connection status locally.
	 *
	 * @param array $status Status data.
	 * @return void
	 */
	private function store_serp_provider_status( array $status ) {
		unset(
			$status['api_key'],
			$status['password'],
			$status['credentials'],
			$status['provider_credentials_encrypted'],
			$status['serpapi_key_encrypted']
		);
		if ( empty( $status['masked_credentials'] ) && ! empty( $status['masked_key'] ) ) {
			$status['masked_credentials'] = $status['masked_key'];
		}
		if ( empty( $status['masked_key'] ) && ! empty( $status['masked_credentials'] ) ) {
			$status['masked_key'] = $status['masked_credentials'];
		}
		$status['checked_at'] = current_time( 'mysql' );
		update_option( self::SERP_PROVIDER_STATUS_OPTION, $status, false );
		update_option( self::SERPAPI_STATUS_OPTION, $status, false );
	}

	/**
	 * Normalize supported SERP provider slugs.
	 *
	 * @param string $provider Provider slug.
	 * @return string
	 */
	private function normalize_serp_provider( $provider ) {
		$provider = sanitize_key( (string) $provider );
		if ( 'serperdev' === $provider || 'serper_dev' === $provider ) {
			$provider = 'serper';
		}

		return in_array( $provider, array( 'serpapi', 'dataforseo', 'serper' ), true ) ? $provider : 'serper';
	}

	/**
	 * Sanitize provider credentials without storing them locally.
	 *
	 * @param string $provider Provider slug.
	 * @param array  $credentials Raw credentials.
	 * @return array|WP_Error
	 */
	private function sanitize_provider_credentials( $provider, array $credentials ) {
		if ( 'dataforseo' === $provider ) {
			$login    = sanitize_text_field( wp_unslash( $credentials['login'] ?? '' ) );
			$password = sanitize_text_field( wp_unslash( $credentials['password'] ?? '' ) );
			if ( '' === $login || '' === $password ) {
				return new WP_Error( 'srk_serp_provider_credentials_missing', __( 'Please enter both DataForSEO login and API password.', 'seo-repair-kit' ) );
			}
			return array(
				'login'    => $login,
				'password' => $password,
			);
		}

		$api_key = sanitize_text_field( wp_unslash( $credentials['api_key'] ?? '' ) );
		if ( '' === $api_key ) {
			return new WP_Error( 'srk_serp_provider_credentials_missing', __( 'Please enter the provider API key.', 'seo-repair-kit' ) );
		}

		return array( 'api_key' => $api_key );
	}

	/**
	 * Build the site identity payload sent to the Python engine.
	 *
	 * @return array
	 */
	private function get_site_payload() {
		return array(
			'site_id'        => $this->get_site_id(),
			'site_url'       => home_url(),
			'site_name'      => get_bloginfo( 'name' ),
			'admin_email'    => get_option( 'admin_email' ),
			'plugin_version' => SEO_REPAIR_KIT_VERSION,
		);
	}

	/**
	 * Normalize a site identifier into a host-only lowercase value.
	 *
	 * @param string $value Raw host, URL, or accidentally pasted path.
	 * @return string
	 */
	private function normalize_site_id( $value ) {
		$value = trim( strtolower( (string) $value ) );
		if ( '' === $value ) {
			return '';
		}

		if ( false === strpos( $value, '://' ) ) {
			$value = 'https://' . $value;
		}

		$host = wp_parse_url( $value, PHP_URL_HOST );
		if ( ! $host ) {
			return '';
		}

		$host = strtolower( preg_replace( '#^www\.#i', '', $host ) );
		$host = sanitize_text_field( $host );

		return $host;
	}

	/**
	 * Sanitize a submitted domain or URL into the Python endpoint format.
	 *
	 * @param string $domain Domain or URL.
	 * @return string
	 */
	public function sanitize_domain( $domain ) {
		$domain = trim( (string) $domain );
		if ( '' === $domain ) {
			return '';
		}

		if ( false === strpos( $domain, '://' ) ) {
			$domain = 'https://' . $domain;
		}

		$host = wp_parse_url( $domain, PHP_URL_HOST );
		if ( ! $host ) {
			return '';
		}

		$scheme = wp_parse_url( $domain, PHP_URL_SCHEME );
		$scheme = in_array( $scheme, array( 'http', 'https' ), true ) ? $scheme : 'https';
		$host = strtolower( preg_replace( '#^www\.#i', '', $host ) );
		$host = sanitize_text_field( $host );

		if ( ! preg_match( '/^[a-z0-9.-]+\.[a-z]{2,}$/i', $host ) ) {
			return '';
		}

		return esc_url_raw( $scheme . '://' . $host );
	}

	/**
	 * Return a user-facing reason when a domain should not be scanned through SerpApi.
	 *
	 * @param string $domain Raw submitted domain or URL.
	 * @return string Empty string means the scan may continue.
	 */
	public function get_scan_block_reason( $domain ) {
		$domain = trim( (string) $domain );
		if ( '' === $domain ) {
			return __( 'Request not sent to scan. Please enter a valid live HTTPS domain.', 'seo-repair-kit' );
		}

		$has_scheme = false !== strpos( $domain, '://' );
		$url        = $has_scheme ? $domain : 'https://' . $domain;
		$scheme     = strtolower( (string) wp_parse_url( $url, PHP_URL_SCHEME ) );
		$host       = strtolower( (string) wp_parse_url( $url, PHP_URL_HOST ) );
		$host       = preg_replace( '#^www\.#i', '', $host );

		if ( 'http' === $scheme ) {
			return __( 'Request not sent to scan. Google SERP scans are only allowed for live HTTPS websites; please enter an https:// domain.', 'seo-repair-kit' );
		}

		if ( '' === $host ) {
			return __( 'Request not sent to scan. Please enter a valid public domain, for example https://example.com.', 'seo-repair-kit' );
		}

		if ( 'localhost' === $host ) {
			return __( 'Request not sent to scan. This looks like a local or test website, so SerpApi credits were protected. Please enter a live public HTTPS domain.', 'seo-repair-kit' );
		}

		if ( filter_var( $host, FILTER_VALIDATE_IP ) ) {
			$is_public = filter_var( $host, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE );
			if ( ! $is_public ) {
				return __( 'Request not sent to scan. Local, private, or reserved IP addresses cannot be scanned through SerpApi.', 'seo-repair-kit' );
			}

			return '';
		}

		if ( function_exists( 'idn_to_ascii' ) ) {
			$ascii = idn_to_ascii( $host, 0, defined( 'INTL_IDNA_VARIANT_UTS46' ) ? INTL_IDNA_VARIANT_UTS46 : 0 );
			if ( false !== $ascii ) {
				$host = strtolower( $ascii );
			}
		}

		if ( ! preg_match( '/^[a-z0-9.-]+\.[a-z]{2,}$/i', $host ) ) {
			return __( 'Request not sent to scan. Please enter a valid public domain, for example https://example.com.', 'seo-repair-kit' );
		}

		$blocked_tlds = array( 'test', 'local', 'localhost', 'invalid' );
		$parts        = explode( '.', $host );
		$tld          = end( $parts );
		if ( in_array( $tld, $blocked_tlds, true ) || 'localhost' === $host ) {
			return __( 'Request not sent to scan. This looks like a local or test website, so SerpApi credits were protected. Please enter a live public HTTPS domain.', 'seo-repair-kit' );
		}

		return '';
	}
}
