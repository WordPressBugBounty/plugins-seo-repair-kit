<?php
/**
 * Spam Monitor scheduled scan orchestration.
 *
 * @package Seo_Repair_Kit
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Owns schedule settings, timing, locking, and background execution.
 */
class SRK_Spam_Monitor_Scheduler {

	const OPTION       = 'srk_spam_monitor_schedule_settings';
	const LOCK_OPTION  = 'srk_spam_monitor_schedule_lock';
	const CRON_HOOK    = 'srk_spam_monitor_scheduled_serp_scan';
	const NONCE_ACTION = 'srk_sm_schedule_nonce';
	const LOCK_TTL     = 10 * MINUTE_IN_SECONDS;

	/**
	 * Register scheduler hooks.
	 */
	public function __construct() {
		add_action( self::CRON_HOOK, array( $this, 'run_scheduled_scan' ) );
		add_action( 'init', array( $this, 'reconcile_schedule' ), 20 );
	}

	/**
	 * Default settings. Scheduling is deliberately opt-in.
	 *
	 * @return array
	 */
	public static function get_defaults() {
		return array(
			'enabled'                  => false,
			'frequency'                => 'daily',
			'serp_requests'            => 3,
			'max_results'              => 30,
			'run_time'                 => '03:00',
			'include_subdomains'       => true,
			'developer_mode'           => false,
			'domain'                   => home_url( '/' ),
			'next_run_utc'             => 0,
			'last_run_utc'             => 0,
			'last_status'              => 'never_run',
			'last_scan_id'             => 0,
			'last_error_code'          => '',
			'consecutive_failures'     => 0,
			'failure_notice_sent'      => false,
			'last_occurrence_key'      => '',
		);
	}

	/**
	 * Get normalized schedule settings.
	 *
	 * @return array
	 */
	public static function get_settings() {
		$saved = get_option( self::OPTION, array() );
		return self::normalize_settings( is_array( $saved ) ? $saved : array(), true );
	}

	/**
	 * Save administrator schedule configuration and reconcile its event.
	 *
	 * @param array $raw Raw settings.
	 * @return array|WP_Error
	 */
	public function save_settings( array $raw ) {
		$current = self::get_settings();
		$input   = array(
			'enabled'            => ! empty( $raw['enabled'] ),
			'frequency'          => sanitize_key( $raw['frequency'] ?? '' ),
			'serp_requests'      => absint( $raw['serp_requests'] ?? 0 ),
			'run_time'           => sanitize_text_field( $raw['run_time'] ?? '' ),
			'include_subdomains' => ! empty( $raw['include_subdomains'] ),
			'developer_mode'     => ! empty( $raw['developer_mode'] ),
			'domain'             => sanitize_text_field( $raw['domain'] ?? '' ),
		);
		$settings = self::normalize_settings( array_merge( $current, $input ), false );

		if ( is_wp_error( $settings ) ) {
			return $settings;
		}

		$settings['next_run_utc'] = 0;
		if ( ! $settings['enabled'] ) {
			$settings['last_status'] = 'disabled';
		}

		$this->persist_settings( $settings );
		wp_clear_scheduled_hook( self::CRON_HOOK );

		if ( $settings['enabled'] ) {
			$scheduled = $this->schedule_next( 0 );
			if ( is_wp_error( $scheduled ) ) {
				$this->record_failure( 'failed_scheduling', $scheduled->get_error_code() );
				return $scheduled;
			}
		}

		return self::get_settings();
	}

	/**
	 * Restore the opt-in schedule defaults without deleting scan history.
	 *
	 * @return array|WP_Error
	 */
	public function reset_settings() {
		$lock_expires = absint( get_option( self::LOCK_OPTION, 0 ) );
		if ( $lock_expires > time() ) {
			return new WP_Error( 'srk_sm_schedule_locked', __( 'Wait for the running scheduled scan to finish before resetting its settings.', 'seo-repair-kit' ) );
		}

		if ( $lock_expires > 0 ) {
			delete_option( self::LOCK_OPTION );
		}

		wp_clear_scheduled_hook( self::CRON_HOOK );
		delete_option( self::OPTION );

		return self::get_settings();
	}

	/**
	 * Ensure an enabled schedule has one canonical future event.
	 *
	 * @return void
	 */
	public function reconcile_schedule() {
		$settings = self::get_settings();
		$next     = wp_next_scheduled( self::CRON_HOOK );

		if ( ! $settings['enabled'] ) {
			if ( false !== $next ) {
				wp_clear_scheduled_hook( self::CRON_HOOK );
			}
			return;
		}

		if ( false === $next ) {
			$this->schedule_next( absint( $settings['next_run_utc'] ) );
			return;
		}

		if ( absint( $settings['next_run_utc'] ) !== absint( $next ) ) {
			$settings['next_run_utc'] = absint( $next );
			$this->persist_settings( $settings );
		}
	}

	/**
	 * Execute the canonical cron occurrence.
	 *
	 * @return void
	 */
	public function run_scheduled_scan() {
		$settings = self::get_settings();
		if ( ! $settings['enabled'] ) {
			return;
		}

		$intended = absint( $settings['next_run_utc'] );
		try {
			$this->execute_scan( $intended, false );
		} catch ( Throwable $throwable ) {
			$this->record_failure( 'failed_provider', 'srk_sm_unexpected_schedule_failure' );
		} finally {
			$scheduled = $this->schedule_next( $intended > 0 ? $intended : time() );
			if ( is_wp_error( $scheduled ) ) {
				$this->record_failure( 'failed_scheduling', $scheduled->get_error_code() );
			}
		}
	}

	/**
	 * Run the saved schedule immediately without moving its next occurrence.
	 *
	 * @return array|WP_Error
	 */
	public function run_now() {
		$settings = self::get_settings();
		if ( ! $settings['enabled'] ) {
			return new WP_Error( 'srk_sm_schedule_disabled', __( 'Enable and save scheduled scans before using Run Now.', 'seo-repair-kit' ) );
		}

		try {
			return $this->execute_scan( time(), true );
		} catch ( Throwable $throwable ) {
			$this->record_failure( 'failed_provider', 'srk_sm_unexpected_schedule_failure' );
			return new WP_Error( 'srk_sm_unexpected_schedule_failure', __( 'The scheduled scan could not be completed.', 'seo-repair-kit' ) );
		}
	}

	/**
	 * Execute a scheduled scan with quota, lock, and idempotency protection.
	 *
	 * @param int  $intended_utc Intended occurrence timestamp.
	 * @param bool $run_now      Whether this is an administrator Run Now request.
	 * @return array|WP_Error
	 */
	private function execute_scan( $intended_utc, $run_now ) {
		$settings       = self::get_settings();
		$occurrence_key = hash( 'sha256', ( $run_now ? 'run-now-' . microtime( true ) : 'cron-' . absint( $intended_utc ) ) );

		if ( ! $run_now && hash_equals( (string) $settings['last_occurrence_key'], $occurrence_key ) ) {
			return new WP_Error( 'srk_sm_schedule_duplicate', __( 'This scheduled occurrence has already completed.', 'seo-repair-kit' ) );
		}

		if ( ! $this->acquire_lock() ) {
			$this->record_failure( 'skipped_locked', 'srk_sm_schedule_locked', false );
			return new WP_Error( 'srk_sm_schedule_locked', __( 'Another Spam Monitor scan is already running.', 'seo-repair-kit' ) );
		}

		try {
			$settings                = self::get_settings();
			$settings['last_status'] = 'running';
			$settings['last_error_code'] = '';
			$settings['last_occurrence_key'] = $occurrence_key;
			$this->persist_settings( $settings );

			$quota_error = $this->get_quota_error( $settings['serp_requests'] );
			if ( is_wp_error( $quota_error ) ) {
				$this->record_failure( 'skipped_insufficient_quota', $quota_error->get_error_code() );
				return $quota_error;
			}

			$service = new SRK_Spam_Monitor_Scan_Service();
			$scan_domain = ! empty( $settings['developer_mode'] ) ? $settings['domain'] : home_url( '/' );
			$scan    = $service->run_scan(
				array(
					'domain'             => $scan_domain,
					'max_results'        => absint( $settings['max_results'] ),
					'max_serp_requests'  => absint( $settings['serp_requests'] ),
					'include_subdomains' => ! empty( $settings['include_subdomains'] ),
					'developer_mode'     => ! empty( $settings['developer_mode'] ),
				),
				'scheduled'
			);

			if ( is_wp_error( $scan ) ) {
				if ( 'srk_sm_scan_locked' === $scan->get_error_code() ) {
					$this->record_failure( 'skipped_locked', $scan->get_error_code(), false );
				} else {
					$status = 'srk_sm_scan_storage_failed' === $scan->get_error_code() ? 'failed_storage' : 'failed_provider';
					$this->record_failure( $status, $scan->get_error_code() );
				}
				return $scan;
			}

			$settings                        = self::get_settings();
			$settings['last_run_utc']         = time();
			$settings['last_status']          = 'completed';
			$settings['last_scan_id']         = absint( $scan['scan_id'] );
			$settings['last_error_code']      = '';
			$settings['consecutive_failures'] = 0;
			$settings['failure_notice_sent']  = false;
			$settings['last_occurrence_key']  = $occurrence_key;
			$this->persist_settings( $settings );

			return $scan;
		} finally {
			$this->release_lock();
		}
	}

	/**
	 * Schedule the next event.
	 *
	 * @param int $from_utc Intended previous occurrence, or zero for initial scheduling.
	 * @return int|WP_Error
	 */
	public function schedule_next( $from_utc = 0 ) {
		$settings = self::get_settings();
		if ( ! $settings['enabled'] ) {
			return new WP_Error( 'srk_sm_schedule_disabled', __( 'Scheduled scans are disabled.', 'seo-repair-kit' ) );
		}

		$timestamp = self::calculate_next_timestamp( $settings, absint( $from_utc ) );
		if ( is_wp_error( $timestamp ) ) {
			return $timestamp;
		}

		wp_clear_scheduled_hook( self::CRON_HOOK );
		$scheduled = wp_schedule_single_event( $timestamp, self::CRON_HOOK, array(), true );
		if ( is_wp_error( $scheduled ) ) {
			return $scheduled;
		}
		if ( ! $scheduled ) {
			return new WP_Error( 'srk_sm_schedule_create_failed', __( 'WordPress could not create the scheduled scan event.', 'seo-repair-kit' ) );
		}

		$settings['next_run_utc'] = $timestamp;
		if ( 'never_run' === $settings['last_status'] || 'disabled' === $settings['last_status'] ) {
			$settings['last_status'] = 'scheduled';
		}
		$this->persist_settings( $settings );

		return $timestamp;
	}

	/**
	 * Calculate the next timezone-aware occurrence.
	 *
	 * @param array $settings Schedule settings.
	 * @param int   $from_utc Previous intended occurrence.
	 * @return int|WP_Error
	 */
	public static function calculate_next_timestamp( array $settings, $from_utc = 0 ) {
		$settings = self::normalize_settings( $settings, false );
		if ( is_wp_error( $settings ) ) {
			return $settings;
		}

		if ( 'every_10_minutes' === $settings['frequency'] ) {
			$next = ( $from_utc > 0 ? absint( $from_utc ) : time() ) + ( 10 * MINUTE_IN_SECONDS );
			while ( $next <= time() ) {
				$next += 10 * MINUTE_IN_SECONDS;
			}
			return $next;
		}

		$timezone = wp_timezone();
		list( $hour, $minute ) = array_map( 'absint', explode( ':', $settings['run_time'] ) );
		$now = new DateTimeImmutable( 'now', $timezone );

		if ( $from_utc > 0 ) {
			$source = ( new DateTimeImmutable( '@' . absint( $from_utc ) ) )->setTimezone( $timezone );
			$source = $source->setTime( $hour, $minute, 0 );
			$next   = self::advance_local_date( $source, $settings['frequency'] );
		} else {
			$next = $now->setTime( $hour, $minute, 0 );
			if ( $next <= $now ) {
				$next = self::advance_local_date( $next, $settings['frequency'] );
			}
		}

		while ( $next->getTimestamp() <= time() ) {
			$next = self::advance_local_date( $next, $settings['frequency'] );
		}

		return $next->getTimestamp();
	}

	/**
	 * Advance one local calendar interval.
	 *
	 * @param DateTimeImmutable $source    Source date.
	 * @param string            $frequency Frequency key.
	 * @return DateTimeImmutable
	 */
	private static function advance_local_date( DateTimeImmutable $source, $frequency ) {
		$days = array(
			'daily'        => 1,
			'every_3_days' => 3,
			'weekly'       => 7,
			'biweekly'     => 14,
		);

		if ( isset( $days[ $frequency ] ) ) {
			return $source->add( new DateInterval( 'P' . $days[ $frequency ] . 'D' ) );
		}

		$day         = absint( $source->format( 'j' ) );
		$target_base = $source->modify( 'first day of next month' );
		$target_day  = min( $day, absint( $target_base->format( 't' ) ) );
		return $target_base->setDate( absint( $target_base->format( 'Y' ) ), absint( $target_base->format( 'n' ) ), $target_day );
	}

	/**
	 * Normalize settings.
	 *
	 * @param array $raw             Raw settings.
	 * @param bool  $fallback_invalid Whether invalid values should fall back to defaults.
	 * @return array|WP_Error
	 */
	private static function normalize_settings( array $raw, $fallback_invalid ) {
		$defaults = self::get_defaults();
		$settings = wp_parse_args( $raw, $defaults );
		$allowed_frequencies = array( 'every_10_minutes', 'daily', 'every_3_days', 'weekly', 'biweekly', 'monthly' );
		$frequency = sanitize_key( $settings['frequency'] );
		$requests  = absint( $settings['serp_requests'] );
		$run_time  = sanitize_text_field( $settings['run_time'] );
		$developer_mode = ! empty( $settings['developer_mode'] );
		$domain = self::normalize_schedule_domain( $settings['domain'] ?? home_url( '/' ), $developer_mode, $fallback_invalid );
		if ( is_wp_error( $domain ) ) {
			return $domain;
		}

		if ( ! in_array( $frequency, $allowed_frequencies, true ) ) {
			if ( ! $fallback_invalid ) {
				return new WP_Error( 'srk_sm_invalid_frequency', __( 'Please select a valid scan frequency.', 'seo-repair-kit' ) );
			}
			$frequency = $defaults['frequency'];
		}

		if ( ! in_array( $requests, array( 1, 3, 5, 10, 100, 200 ), true ) ) {
			if ( ! $fallback_invalid ) {
				return new WP_Error( 'srk_sm_invalid_scan_depth', __( 'Please select 1, 3, 5, 10, 100, or 200 SERP requests.', 'seo-repair-kit' ) );
			}
			$requests = $defaults['serp_requests'];
		}

		if ( ! preg_match( '/^(?:[01]\d|2[0-3]):[0-5]\d$/', $run_time ) ) {
			if ( ! $fallback_invalid ) {
				return new WP_Error( 'srk_sm_invalid_run_time', __( 'Please select a valid schedule time.', 'seo-repair-kit' ) );
			}
			$run_time = $defaults['run_time'];
		}

		$settings['enabled']              = ! empty( $settings['enabled'] );
		$settings['frequency']            = $frequency;
		$settings['serp_requests']        = $requests;
		$settings['max_results']          = $requests * 10;
		$settings['run_time']             = $run_time;
		$settings['include_subdomains']   = ! empty( $settings['include_subdomains'] );
		$settings['developer_mode']       = $developer_mode;
		$settings['domain']               = $domain;
		$settings['next_run_utc']         = absint( $settings['next_run_utc'] );
		$settings['last_run_utc']         = absint( $settings['last_run_utc'] );
		$settings['last_scan_id']         = absint( $settings['last_scan_id'] );
		$settings['consecutive_failures'] = absint( $settings['consecutive_failures'] );
		$settings['failure_notice_sent']  = ! empty( $settings['failure_notice_sent'] );
		$settings['last_status']          = sanitize_key( $settings['last_status'] );
		$settings['last_error_code']      = sanitize_key( $settings['last_error_code'] );
		$settings['last_occurrence_key']  = preg_replace( '/[^a-f0-9]/', '', strtolower( (string) $settings['last_occurrence_key'] ) );

		return $settings;
	}

	/**
	 * Persist a small non-autoloaded settings option.
	 *
	 * @param array $settings Settings.
	 * @return void
	 */
	private function persist_settings( array $settings ) {
		if ( false === get_option( self::OPTION, false ) ) {
			add_option( self::OPTION, $settings, '', 'no' );
			return;
		}
		update_option( self::OPTION, $settings, false );
	}

	/**
	 * Acquire an atomic database-backed lock.
	 *
	 * @return bool
	 */
	private function acquire_lock() {
		$expires = absint( get_option( self::LOCK_OPTION, 0 ) );
		if ( $expires > 0 && $expires <= time() ) {
			delete_option( self::LOCK_OPTION );
		}
		return add_option( self::LOCK_OPTION, time() + self::LOCK_TTL, '', 'no' );
	}

	/**
	 * Release the execution lock.
	 *
	 * @return void
	 */
	private function release_lock() {
		delete_option( self::LOCK_OPTION );
	}

	/**
	 * Check locally known quota before consuming a provider request.
	 *
	 * @param int $required Required requests.
	 * @return true|WP_Error
	 */
	private function get_quota_error( $required ) {
		$provider = new SRK_Spam_Monitor_SERP_Provider();
		$status   = $provider->get_local_serp_provider_status();
		$trial    = $provider->get_local_trial_status();

		if ( 'user_own_key' === sanitize_key( $status['provider_mode'] ?? '' ) ) {
			return true;
		}
		if ( array_key_exists( 'remaining_requests', $trial ) && absint( $trial['remaining_requests'] ) < absint( $required ) ) {
			return new WP_Error( 'srk_sm_schedule_quota_low', __( 'The scheduled scan was skipped because there are not enough SERP requests remaining.', 'seo-repair-kit' ) );
		}

		return true;
	}

	/**
	 * Record a sanitized failure and send one deduplicated operational warning.
	 *
	 * @param string $status        Status key.
	 * @param string $error_code    Stable error code.
	 * @param bool   $count_failure Whether to increment failure count.
	 * @return void
	 */
	private function record_failure( $status, $error_code, $count_failure = true ) {
		$settings                    = self::get_settings();
		$settings['last_run_utc']    = time();
		$settings['last_status']     = sanitize_key( $status );
		$settings['last_error_code'] = sanitize_key( $error_code );
		if ( $count_failure ) {
			$settings['consecutive_failures'] = absint( $settings['consecutive_failures'] ) + 1;
		}
		$this->persist_settings( $settings );

		if ( $count_failure && $settings['consecutive_failures'] >= 3 && empty( $settings['failure_notice_sent'] ) && class_exists( 'SRK_Spam_Monitor_Alerts' ) ) {
			if ( SRK_Spam_Monitor_Alerts::send_schedule_failure_alert( $settings ) ) {
				$settings['failure_notice_sent'] = true;
				$this->persist_settings( $settings );
			}
		}
	}

	/**
	 * Get a presentation-safe status payload.
	 *
	 * @return array
	 */
	public static function get_status() {
		$settings = self::get_settings();
		$timezone = wp_timezone_string();
		if ( '' === $timezone ) {
			$timezone = 'UTC' . wp_date( 'P' );
		}

		return array(
			'enabled'              => ! empty( $settings['enabled'] ),
			'frequency'            => $settings['frequency'],
			'frequency_label'      => self::get_frequency_labels()[ $settings['frequency'] ] ?? $settings['frequency'],
			'serp_requests'        => absint( $settings['serp_requests'] ),
			'max_results'          => absint( $settings['max_results'] ),
			'run_time'             => $settings['run_time'],
			'include_subdomains'   => ! empty( $settings['include_subdomains'] ),
			'developer_mode'       => ! empty( $settings['developer_mode'] ),
			'next_run_utc'         => absint( $settings['next_run_utc'] ),
			'next_run_display'     => $settings['next_run_utc'] ? wp_date( 'Y-m-d H:i:s T', $settings['next_run_utc'], wp_timezone() ) : '-',
			'last_run_display'     => $settings['last_run_utc'] ? wp_date( 'Y-m-d H:i:s T', $settings['last_run_utc'], wp_timezone() ) : '-',
			'last_status'          => $settings['last_status'],
			'last_status_label'    => self::get_status_labels()[ $settings['last_status'] ] ?? __( 'Unknown', 'seo-repair-kit' ),
			'last_scan_id'         => absint( $settings['last_scan_id'] ),
			'last_error_code'      => $settings['last_error_code'],
			'consecutive_failures' => absint( $settings['consecutive_failures'] ),
			'timezone'             => $timezone,
			'estimated_monthly'    => self::estimate_monthly_requests( $settings['frequency'], $settings['serp_requests'] ),
			'domain'               => $settings['domain'],
		);
	}

	/**
	 * Normalize the scheduled scan domain. Custom domains are developer-mode only.
	 *
	 * @param string $raw Raw submitted domain.
	 * @param bool   $developer_mode Whether developer testing mode is enabled.
	 * @param bool   $fallback_invalid Whether invalid values should fall back.
	 * @return string|WP_Error
	 */
	private static function normalize_schedule_domain( $raw, $developer_mode, $fallback_invalid ) {
		if ( ! $developer_mode ) {
			return home_url( '/' );
		}

		if ( ! class_exists( 'SRK_Spam_Monitor_SERP_Provider' ) ) {
			if ( $fallback_invalid ) {
				return home_url( '/' );
			}
			return new WP_Error( 'srk_sm_provider_missing', __( 'Spam Monitor provider is not available.', 'seo-repair-kit' ) );
		}

		$provider = new SRK_Spam_Monitor_SERP_Provider();
		$domain   = $provider->sanitize_domain( $raw );
		$blocked  = $provider->get_scan_block_reason( $domain );

		if ( '' === $domain || '' !== $blocked ) {
			if ( $fallback_invalid ) {
				return home_url( '/' );
			}
			return new WP_Error(
				'srk_sm_invalid_developer_domain',
				$blocked ? $blocked : __( 'Please enter a valid live HTTPS domain for Developer Mode scheduled testing.', 'seo-repair-kit' )
			);
		}

		return $domain;
	}

	/**
	 * Remove a stale last-scan reference after the SERP dataset is explicitly cleared.
	 *
	 * @return void
	 */
	public static function clear_last_scan_reference() {
		if ( false === get_option( self::OPTION, false ) ) {
			return;
		}
		$settings = self::get_settings();
		$settings['last_scan_id'] = 0;
		if ( 'completed' === $settings['last_status'] ) {
			$settings['last_status'] = $settings['enabled'] ? 'scheduled' : 'disabled';
		}
		update_option( self::OPTION, $settings, false );
	}

	/**
	 * Frequency labels.
	 *
	 * @return array
	 */
	public static function get_frequency_labels() {
		return array(
			'every_10_minutes' => __( 'Every 10 minutes (Testing only)', 'seo-repair-kit' ),
			'daily'        => __( 'Every day (Recommended)', 'seo-repair-kit' ),
			'every_3_days' => __( 'Every 3 days', 'seo-repair-kit' ),
			'weekly'       => __( 'Every week', 'seo-repair-kit' ),
			'biweekly'     => __( 'Every 2 weeks', 'seo-repair-kit' ),
			'monthly'      => __( 'Every month', 'seo-repair-kit' ),
		);
	}

	/**
	 * Status labels.
	 *
	 * @return array
	 */
	private static function get_status_labels() {
		return array(
			'never_run'                  => __( 'Never run', 'seo-repair-kit' ),
			'disabled'                   => __( 'Disabled', 'seo-repair-kit' ),
			'scheduled'                  => __( 'Scheduled', 'seo-repair-kit' ),
			'running'                    => __( 'Running', 'seo-repair-kit' ),
			'completed'                  => __( 'Completed', 'seo-repair-kit' ),
			'skipped_insufficient_quota' => __( 'Skipped: insufficient quota', 'seo-repair-kit' ),
			'skipped_locked'             => __( 'Skipped: another scan is running', 'seo-repair-kit' ),
			'failed_validation'          => __( 'Failed validation', 'seo-repair-kit' ),
			'failed_provider'            => __( 'Provider failed', 'seo-repair-kit' ),
			'failed_storage'             => __( 'Storage failed', 'seo-repair-kit' ),
			'failed_scheduling'          => __( 'Scheduling failed', 'seo-repair-kit' ),
		);
	}

	/**
	 * Estimate monthly request consumption.
	 *
	 * @param string $frequency Frequency.
	 * @param int    $requests  Requests per scan.
	 * @return int
	 */
	private static function estimate_monthly_requests( $frequency, $requests ) {
		$multipliers = array( 'every_10_minutes' => 4320, 'daily' => 30, 'every_3_days' => 10, 'weekly' => 4.33, 'biweekly' => 2.17, 'monthly' => 1 );
		return (int) ceil( absint( $requests ) * ( $multipliers[ $frequency ] ?? 4.33 ) );
	}
}
