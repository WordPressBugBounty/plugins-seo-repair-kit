<?php
/**
 * SRK Intelligence Cloud client for Spam Monitor.
 *
 * @package Seo_Repair_Kit
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Centralizes SRK Cloud endpoint and request handling.
 */
class SRK_Spam_Monitor_Cloud_Client {

	const DEFAULT_BASE_URL = 'https://cloud.seorepairkit.com';
	const LEGACY_ENDPOINT_OPTION = 'srk_spam_monitor_python_serp_endpoint';

	/**
	 * Resolve SRK Cloud base URL.
	 *
	 * @return string
	 */
	public function get_base_url() {
		$base = defined( 'SRK_CLOUD_API_BASE_URL' ) ? SRK_CLOUD_API_BASE_URL : self::DEFAULT_BASE_URL;

		if (
			defined( 'SRK_SPAM_MONITOR_ALLOW_ENDPOINT_OPTION_OVERRIDE' )
			&& SRK_SPAM_MONITOR_ALLOW_ENDPOINT_OPTION_OVERRIDE
		) {
			$option = get_option( self::LEGACY_ENDPOINT_OPTION, '' );
			if ( is_string( $option ) && '' !== trim( $option ) ) {
				$base = $option;
			}
		}

		$base = $this->normalize_base_url( $base );
		$base = (string) apply_filters( 'srk_spam_monitor_cloud_api_base_url', $base );

		return esc_url_raw( untrailingslashit( $base ) );
	}

	/**
	 * Build official /v1 endpoint URL.
	 *
	 * @param string $path API path.
	 * @return string
	 */
	public function get_api_url( $path ) {
		return esc_url_raw( trailingslashit( $this->get_base_url() ) . 'v1/' . ltrim( $path, '/' ) );
	}

	/**
	 * Build hidden legacy endpoint URL for backward-compatible retries.
	 *
	 * @param string $path API path.
	 * @return string
	 */
	public function get_legacy_api_url( $path ) {
		return esc_url_raw( trailingslashit( $this->get_base_url() ) . ltrim( $path, '/' ) );
	}

	/**
	 * Build the official scan endpoint.
	 *
	 * @return string
	 */
	public function get_scan_url() {
		return $this->get_api_url( 'scan/google-serp' );
	}

	/**
	 * Build request headers.
	 *
	 * @param bool   $json Whether JSON body is being sent.
	 * @param string $site_id Site ID.
	 * @param string $body_json JSON body string.
	 * @return array
	 */
	public function get_headers( $json = false, $site_id = '', $body_json = '' ) {
		$headers = array(
			'Accept' => 'application/json',
		);

		if ( $json ) {
			$headers['Content-Type'] = 'application/json';
		}

		if ( $this->is_ngrok_endpoint() ) {
			$headers['ngrok-skip-browser-warning'] = '1';
		}

		$site_id = sanitize_text_field( (string) $site_id );
		$secret  = $this->get_signing_secret();
		if ( '' !== $site_id && '' !== $secret ) {
			$timestamp = (string) time();
			$payload   = $site_id . '|' . $timestamp . '|' . (string) $body_json;

			$headers['X-SRK-Site-ID']    = $site_id;
			$headers['X-SRK-Timestamp']  = $timestamp;
			$headers['X-SRK-Signature']  = hash_hmac( 'sha256', $payload, $secret );
			$headers['X-SRK-Plugin']     = 'seo-repair-kit';
			$headers['X-SRK-Version']    = SEO_REPAIR_KIT_VERSION;
		}

		return $headers;
	}

	/**
	 * Normalize a base URL or accidentally pasted endpoint to a base URL.
	 *
	 * @param string $base Raw base.
	 * @return string
	 */
	private function normalize_base_url( $base ) {
		$base = untrailingslashit( trim( (string) $base ) );
		if ( '' === $base ) {
			return self::DEFAULT_BASE_URL;
		}

		$parts  = wp_parse_url( $base );
		$scheme = isset( $parts['scheme'] ) ? strtolower( $parts['scheme'] ) : '';
		$host   = isset( $parts['host'] ) ? strtolower( $parts['host'] ) : '';

		if ( ! in_array( $scheme, array( 'http', 'https' ), true ) || '' === $host ) {
			return self::DEFAULT_BASE_URL;
		}

		$path = isset( $parts['path'] ) ? untrailingslashit( $parts['path'] ) : '';
		$path = preg_replace( '#/(?:v1/)?scan/google-serp$#', '', $path );
		$path = preg_replace( '#/v1$#', '', $path );
		$port = isset( $parts['port'] ) ? ':' . absint( $parts['port'] ) : '';

		return esc_url_raw( $scheme . '://' . $host . $port . $path );
	}

	/**
	 * Check whether the active endpoint is an ngrok URL.
	 *
	 * @return bool
	 */
	private function is_ngrok_endpoint() {
		$host = strtolower( (string) wp_parse_url( $this->get_base_url(), PHP_URL_HOST ) );
		return false !== strpos( $host, 'ngrok' );
	}

	/**
	 * Get signing secret when configured.
	 *
	 * @return string
	 */
	private function get_signing_secret() {
		if ( defined( 'SRK_CLOUD_SHARED_SECRET' ) && SRK_CLOUD_SHARED_SECRET ) {
			return (string) SRK_CLOUD_SHARED_SECRET;
		}

		if ( defined( 'SRK_SHARED_SECRET' ) && SRK_SHARED_SECRET ) {
			return (string) SRK_SHARED_SECRET;
		}

		return '';
	}
}
