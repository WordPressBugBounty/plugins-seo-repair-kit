<?php
/**
 * Shared Google SERP scan application service.
 *
 * @package Seo_Repair_Kit
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Runs manual and scheduled SERP scans through one canonical pipeline.
 */
class SRK_Spam_Monitor_Scan_Service {
	const LOCK_OPTION = 'srk_spam_monitor_scan_lock';
	const LOCK_TTL    = 10 * MINUTE_IN_SECONDS;
	/** @var object|null SERP provider dependency. */
	private $provider;

	/**
	 * Supported indexed-result limits retained from the existing manual scanner.
	 *
	 * @var int[]
	 */
	const ALLOWED_RESULTS = array( 10, 20, 30, 40, 50, 60, 70, 80, 90, 100, 200, 300, 400, 500, 600, 700, 800, 900, 1000, 2000 );

	/**
	 * Accept an optional provider for isolated service testing.
	 *
	 * @param object|null $provider Object exposing scan( array $args ).
	 */
	public function __construct( $provider = null ) {
		$this->provider = is_object( $provider ) && is_callable( array( $provider, 'scan' ) ) ? $provider : null;
	}

	/**
	 * Execute, persist, and report one SERP scan.
	 *
	 * @param array  $raw_args Raw scan arguments.
	 * @param string $source   Scan source: manual or scheduled.
	 * @return array|WP_Error
	 */
	public function run_scan( array $raw_args, $source = 'manual' ) {
		if ( ( ! $this->provider && ! class_exists( 'SRK_Spam_Monitor_SERP_Provider' ) ) || ! class_exists( 'SRK_Spam_Monitor_DB' ) ) {
			return new WP_Error( 'srk_sm_scan_dependencies_missing', __( 'Spam Monitor scan services are not available.', 'seo-repair-kit' ) );
		}

		$args = $this->normalize_args( $raw_args );
		if ( is_wp_error( $args ) ) {
			return $args;
		}

		$source   = in_array( sanitize_key( $source ), array( 'manual', 'scheduled' ), true ) ? sanitize_key( $source ) : 'manual';
		$provider = $this->provider ? $this->provider : new SRK_Spam_Monitor_SERP_Provider();
		if ( ! self::acquire_scan_lock() ) {
			return new WP_Error( 'srk_sm_scan_locked', __( 'Another Spam Monitor scan is already running.', 'seo-repair-kit' ) );
		}
		try {
			$result = $provider->scan( $args );
		} finally {
			delete_option( self::LOCK_OPTION );
		}
		if ( is_wp_error( $result ) ) {
			return $result;
		}

		$risk_counts                = self::count_result_risks( $result['results'] ?? array() );
		$result['clean_count']      = absint( $result['clean_count'] ?? $risk_counts['clean'] );
		$result['suspicious_count'] = absint( $result['suspicious_count'] ?? $risk_counts['suspicious'] );
		$result['spam_count']       = absint( $result['spam_count'] ?? $risk_counts['spam'] );
		$result['critical_count']   = absint( $result['critical_count'] ?? $risk_counts['critical'] );
		$args['scan_source']        = $source;

		$scan_id = SRK_Spam_Monitor_DB::insert_serp_scan( $result, $args );
		if ( ! $scan_id ) {
			return new WP_Error( 'srk_sm_scan_storage_failed', __( 'Scan completed, but WordPress could not save the scan summary.', 'seo-repair-kit' ) );
		}

		SRK_Spam_Monitor_DB::insert_serp_results( $scan_id, $result['results'] ?? array(), $result['domain'] ?? $args['domain'] );

		if ( class_exists( 'SRK_Spam_Monitor_Alerts' ) ) {
			$result['scan_source'] = $source;
			$result['scan_args']   = $args;
			SRK_Spam_Monitor_Alerts::maybe_send_serp_scan_alert( $scan_id, $result );
		}

		return array(
			'scan_id'     => absint( $scan_id ),
			'result'      => $result,
			'risk_counts' => $risk_counts,
			'args'        => $args,
			'source'      => $source,
		);
	}

	/**
	 * Normalize scan arguments while retaining the current manual request contract.
	 *
	 * @param array $raw_args Raw arguments.
	 * @return array|WP_Error
	 */
	public function normalize_args( array $raw_args ) {
		$max_results = absint( $raw_args['max_results'] ?? 100 );
		if ( ! in_array( $max_results, self::ALLOWED_RESULTS, true ) ) {
			return new WP_Error( 'srk_sm_invalid_result_limit', __( 'Please select a valid indexed results amount.', 'seo-repair-kit' ) );
		}

		$max_serp_requests = (int) ceil( $max_results / 10 );
		if ( $max_serp_requests > 200 ) {
			return new WP_Error( 'srk_sm_invalid_request_limit', __( 'The selected request limit is too high.', 'seo-repair-kit' ) );
		}

		$domain = sanitize_text_field( (string) ( $raw_args['domain'] ?? '' ) );
		if ( '' === trim( $domain ) ) {
			return new WP_Error( 'srk_serp_invalid_domain', __( 'Please enter a valid domain.', 'seo-repair-kit' ) );
		}

		return array(
			'domain'             => $domain,
			'max_results'        => $max_results,
			'max_serp_requests'  => $max_serp_requests,
			'include_subdomains' => ! array_key_exists( 'include_subdomains', $raw_args ) || ! empty( $raw_args['include_subdomains'] ),
			'developer_mode'     => ! empty( $raw_args['developer_mode'] ),
		);
	}

	/**
	 * Count returned result risk labels.
	 *
	 * @param array $results Result rows.
	 * @return array
	 */
	public static function count_result_risks( array $results ) {
		$counts = array(
			'clean'      => 0,
			'suspicious' => 0,
			'spam'       => 0,
			'critical'   => 0,
		);

		foreach ( $results as $result ) {
			$level = sanitize_key( $result['risk_level'] ?? 'clean' );
			if ( isset( $counts[ $level ] ) ) {
				$counts[ $level ]++;
			} else {
				$counts['clean']++;
			}
		}

		return $counts;
	}

	/**
	 * Acquire a shared manual/scheduled provider lock.
	 *
	 * @return bool
	 */
	private static function acquire_scan_lock() {
		$expires = absint( get_option( self::LOCK_OPTION, 0 ) );
		if ( $expires > 0 && $expires <= time() ) {
			delete_option( self::LOCK_OPTION );
		}
		return add_option( self::LOCK_OPTION, time() + self::LOCK_TTL, '', 'no' );
	}
}
