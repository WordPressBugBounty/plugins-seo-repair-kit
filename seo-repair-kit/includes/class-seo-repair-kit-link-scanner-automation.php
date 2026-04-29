<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Links Manager automation, history, cron, and alert notifications.
 *
 * @since 2.1.7
 */
class SeoRepairKit_LinkScanner_Automation {

	const EVENT_HOOK              = 'srk_link_scanner_cron_scan';
	const SETTINGS_OPTION         = 'srk_link_scanner_schedule_settings';
	const SNAPSHOT_OPTION         = 'srk_last_links_snapshot';
	const CURSOR_OPTION           = 'srk_link_scanner_cursor';
	const LAST_ALERT_HASH         = 'srk_link_scanner_last_alert_hash';
	const LOCK_TRANSIENT          = 'srk_link_scanner_run_lock';
	const LOCK_TTL_SECONDS        = 20 * MINUTE_IN_SECONDS;
	const MAX_DETAIL_RECORDS      = 500;
	const ALERT_COOLDOWN_HOUR     = HOUR_IN_SECONDS;
	const URL_CACHE_OPTION        = 'srk_link_scanner_url_status_cache';
	const URL_CACHE_TTL           = 12 * HOUR_IN_SECONDS;
	const DASHBOARD_ALERT_OPTION  = 'srk_link_scanner_dashboard_alert';
	const DASHBOARD_UNREAD_OPTION = 'srk_link_scanner_unread_alerts';
	const MAX_SCAN_RECORDS        = 7;
	const STORAGE_CHECK_TRANSIENT = 'srk_link_scanner_storage_checked';

	/**
	 * Singleton instance.
	 *
	 * @var SeoRepairKit_LinkScanner_Automation|null
	 */
	private static $instance = null;

	/**
	 * Database instance.
	 *
	 * @var wpdb
	 */
	private $db;

	/**
	 * Get singleton instance.
	 *
	 * @return SeoRepairKit_LinkScanner_Automation
	 */
	public static function get_instance() {
		if ( null === self::$instance ) {
			self::$instance = new self();
		}

		return self::$instance;
	}

	/**
	 * Constructor.
	 */
	private function __construct() {
		global $wpdb;

		$this->db = $wpdb;

		$this->load_dependencies();
		$this->maybe_bootstrap_storage();

		add_filter( 'cron_schedules', array( $this, 'register_custom_intervals' ) );
		add_action( self::EVENT_HOOK, array( $this, 'run_scheduled_scan' ) );

		add_action( 'wp_ajax_srk_ajax_run_scan_now', array( $this, 'ajax_run_scan_now' ) );
		add_action( 'wp_ajax_srk_ajax_reset_scan_table', array( $this, 'ajax_reset_scan_table' ) );

		$settings = self::get_settings();

		if ( ! empty( $settings['enabled'] ) && ! wp_next_scheduled( self::EVENT_HOOK ) ) {
			self::reschedule_event( $settings );
		}
	}

	/**
	 * Load supporting classes used by automation.
	 *
	 * @return void
	 */
	private function load_dependencies() {
		$email_report_file = plugin_dir_path( __FILE__ ) . 'class-seo-repair-kit-link-scanner-email-report.php';
		$smart_helper_file = plugin_dir_path( __FILE__ ) . 'class-seo-repair-kit-smart-redirect-helper.php';
		$smart_gen_file    = plugin_dir_path( __FILE__ ) . 'class-seo-repair-kit-smart-redirect-generator.php';

		if ( file_exists( $email_report_file ) && ! class_exists( 'SeoRepairKit_LinkScanner_Email_Report' ) ) {
			require_once $email_report_file;
		}

		if ( file_exists( $smart_helper_file ) && ! class_exists( 'SeoRepairKit_SmartRedirect_Helper' ) ) {
			require_once $smart_helper_file;
		}

		if ( file_exists( $smart_gen_file ) && ! class_exists( 'SeoRepairKit_SmartRedirect_Generator' ) ) {
			require_once $smart_gen_file;
		}
	}

	/**
	 * Run storage bootstrap/check only occasionally.
	 *
	 * @return void
	 */
	private function maybe_bootstrap_storage() {
		$checked = get_transient( self::STORAGE_CHECK_TRANSIENT );

		if ( false !== $checked ) {
			return;
		}

		$this->ensure_history_tables();
		set_transient( self::STORAGE_CHECK_TRANSIENT, 1, 12 * HOUR_IN_SECONDS );
	}

	/**
	 * Register custom cron intervals.
	 *
	 * @param array $schedules Existing schedules.
	 * @return array
	 */
	public function register_custom_intervals( $schedules ) {
		$custom_intervals = array(
			'daily'           => array(
				'interval' => DAY_IN_SECONDS,
				'display'  => __( 'Every 24 Hours', 'seo-repair-kit' ),
			),
			'srk_every_3_days'    => array(
				'interval' => 3 * DAY_IN_SECONDS,
				'display'  => __( 'Every 3 Days', 'seo-repair-kit' ),
			),
			'srk_biweekly'        => array(
				'interval' => 14 * DAY_IN_SECONDS,
				'display'  => __( 'Bi-Weekly', 'seo-repair-kit' ),
			),
			'srk_monthly'         => array(
				'interval' => 30 * DAY_IN_SECONDS,
				'display'  => __( 'Monthly', 'seo-repair-kit' ),
			),
		);

		foreach ( $custom_intervals as $key => $config ) {
			if ( ! isset( $schedules[ $key ] ) ) {
				$schedules[ $key ] = $config;
			}
		}

		return $schedules;
	}

	/**
	 * Get default settings.
	 *
	 * @return array
	 */
	public static function get_default_settings() {
		return array(
			'enabled'            => 0,
			'interval'           => 'daily',
			'link_scope'         => 'both',
			'scan_coverage'      => 'selected',
			'post_types'         => array( 'post', 'page' ),
			'max_posts_per_run'  => 30,
			'max_links_per_post' => 0,
			'request_timeout'    => 8,
			'email_enabled'      => 1,
			'email_recipients'   => get_option( 'admin_email', '' ),
		);
	}

	/**
	 * Get all public post types.
	 *
	 * @return array
	 */
	private static function get_all_public_post_types() {
		return get_post_types( array( 'public' => true ), 'names' );
	}

	/**
	 * Get sanitized settings.
	 *
	 * @return array
	 */
	public static function get_settings() {
		$saved    = get_option( self::SETTINGS_OPTION, array() );
		$defaults = self::get_default_settings();
		$settings = wp_parse_args( is_array( $saved ) ? $saved : array(), $defaults );

		$public_post_types = self::get_all_public_post_types();
		$allowed_intervals = array(
			'daily',
			'srk_every_3_days',
			'weekly',
			'srk_biweekly',
			'srk_monthly',
		);
		$allowed_scopes   = array( 'both', 'internal', 'external' );
		$allowed_coverage = array( 'selected', 'whole_site' );

		$settings['enabled']            = empty( $settings['enabled'] ) ? 0 : 1;
		$settings['interval']           = in_array( $settings['interval'], $allowed_intervals, true ) ? $settings['interval'] : 'daily';
		$settings['link_scope']         = in_array( $settings['link_scope'], $allowed_scopes, true ) ? $settings['link_scope'] : 'both';
		$settings['scan_coverage']      = in_array( $settings['scan_coverage'], $allowed_coverage, true ) ? $settings['scan_coverage'] : 'selected';
		$settings['post_types']         = array_values(
			array_intersect(
				array_map( 'sanitize_key', (array) $settings['post_types'] ),
				$public_post_types
			)
		);
		$settings['max_posts_per_run']  = max( 5, min( 1000, absint( $settings['max_posts_per_run'] ) ) );
		$settings['max_links_per_post'] = max( 0, min( 10000, intval( $settings['max_links_per_post'] ) ) );
		$settings['request_timeout']    = max( 3, min( 30, absint( $settings['request_timeout'] ) ) );
		$settings['email_enabled']      = empty( $settings['email_enabled'] ) ? 0 : 1;
		$settings['email_recipients']   = sanitize_text_field( (string) $settings['email_recipients'] );

		if ( 'whole_site' === $settings['scan_coverage'] ) {
			$settings['post_types'] = $public_post_types;
		}

		if ( empty( $settings['post_types'] ) ) {
			$settings['post_types'] = array( 'post' );
		}

		return $settings;
	}

	/**
	 * Save settings and reschedule cron.
	 *
	 * @param array $input Raw input.
	 * @return array
	 */
	public static function save_settings( $input ) {
		$defaults = self::get_default_settings();
		$raw      = wp_parse_args( is_array( $input ) ? $input : array(), $defaults );

		$settings = array(
			'enabled'            => empty( $raw['enabled'] ) ? 0 : 1,
			'interval'           => sanitize_key( (string) $raw['interval'] ),
			'link_scope'         => sanitize_key( (string) $raw['link_scope'] ),
			'scan_coverage'      => sanitize_key( (string) $raw['scan_coverage'] ),
			'post_types'         => isset( $raw['post_types'] ) ? (array) $raw['post_types'] : array(),
			'max_posts_per_run'  => absint( $raw['max_posts_per_run'] ),
			'max_links_per_post' => intval( $raw['max_links_per_post'] ),
			'request_timeout'    => absint( $raw['request_timeout'] ),
			'email_enabled'      => empty( $raw['email_enabled'] ) ? 0 : 1,
			'email_recipients'   => sanitize_text_field( (string) $raw['email_recipients'] ),
		);

		update_option( self::SETTINGS_OPTION, $settings, false );

		delete_option( self::SNAPSHOT_OPTION );
		delete_option( self::CURSOR_OPTION );
		delete_option( self::LAST_ALERT_HASH );
		delete_option( self::DASHBOARD_ALERT_OPTION );
		delete_option( self::DASHBOARD_UNREAD_OPTION );

		delete_transient( self::STORAGE_CHECK_TRANSIENT );

		$clean = self::get_settings();
		self::reschedule_event( $clean );

		return $clean;
	}

	/**
	 * Reschedule recurring event.
	 *
	 * @param array|null $settings Settings.
	 * @return void
	 */
	public static function reschedule_event( $settings = null ) {
		if ( null === $settings ) {
			$settings = self::get_settings();
		}

		wp_clear_scheduled_hook( self::EVENT_HOOK );
		update_option( 'srk_links_schedule', empty( $settings['enabled'] ) ? 'manual' : $settings['interval'], false );

		if ( empty( $settings['enabled'] ) ) {
			return;
		}

		wp_schedule_event( time() + 60, $settings['interval'], self::EVENT_HOOK );
	}

	/**
	 * Run scan manually.
	 *
	 * @return array
	 */
	public function run_manual_scan_now() {
		if ( get_transient( self::LOCK_TRANSIENT ) ) {
			return array(
				'status'  => 'locked',
				'message' => __( 'A scan is already running. Please wait for it to finish.', 'seo-repair-kit' ),
			);
		}

		set_transient( self::LOCK_TRANSIENT, 1, self::LOCK_TTL_SECONDS );
		$this->ensure_history_tables();

		try {
			$settings = self::get_settings();

			$result = $this->scan_links(
				$settings['post_types'],
				$settings['max_posts_per_run'],
				$settings['max_links_per_post'],
				$settings['request_timeout'],
				'manual',
				$settings['link_scope'],
				$settings['scan_coverage']
			);

			$this->maybe_send_alert_email( $result, $settings );
			$this->update_dashboard_notification_state( $result );

			return array(
				'status' => 'success',
				'data'   => $result,
			);
		} catch ( Exception $e ) {
			return array(
				'status'  => 'error',
				'message' => $e->getMessage(),
			);
		} finally {
			delete_transient( self::LOCK_TRANSIENT );
		}
	}

	/**
	 * AJAX run scan now.
	 *
	 * @return void
	 */
	public function ajax_run_scan_now() {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_send_json_error( array( 'message' => __( 'Permission denied.', 'seo-repair-kit' ) ), 403 );
		}

		check_ajax_referer( 'srk_run_scan_now_nonce', 'nonce' );

		$result = $this->run_manual_scan_now();

		if ( ! empty( $result['status'] ) && 'success' === $result['status'] ) {
			$broken = isset( $result['data']['brokenLinks'] ) ? (int) $result['data']['brokenLinks'] : 0;

			wp_send_json_success(
				array(
					'broken'  => $broken,
					'total'   => isset( $result['data']['totalLinks'] ) ? (int) $result['data']['totalLinks'] : 0,
					'message' => sprintf(
						__( 'Scan complete. Broken links found: %d.', 'seo-repair-kit' ),
						$broken
					),
				)
			);
		}

		if ( ! empty( $result['status'] ) && 'locked' === $result['status'] ) {
			wp_send_json_error(
				array(
					'message' => ! empty( $result['message'] ) ? $result['message'] : __( 'A scan is already running.', 'seo-repair-kit' ),
				),
				423
			);
		}

		wp_send_json_error(
			array(
				'message' => ! empty( $result['message'] ) ? $result['message'] : __( 'Unable to run scan.', 'seo-repair-kit' ),
			),
			500
		);
	}

	/**
	 * AJAX reset scan history.
	 *
	 * @return void
	 */
	public function ajax_reset_scan_table() {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_send_json_error( array( 'message' => __( 'Permission denied.', 'seo-repair-kit' ) ), 403 );
		}

		check_ajax_referer( 'srk_reset_scan_table_nonce', 'nonce' );

		$this->reset_records( false );

		wp_send_json_success( array( 'message' => __( 'Scan records reset successfully.', 'seo-repair-kit' ) ) );
	}

	/**
	 * Run scheduled scan.
	 *
	 * @return void
	 */
	public function run_scheduled_scan() {
		if ( get_transient( self::LOCK_TRANSIENT ) ) {
			return;
		}

		set_transient( self::LOCK_TRANSIENT, 1, self::LOCK_TTL_SECONDS );
		$this->ensure_history_tables();

		try {
			$settings = self::get_settings();

			if ( empty( $settings['enabled'] ) ) {
				return;
			}

			$result = $this->scan_links(
				$settings['post_types'],
				$settings['max_posts_per_run'],
				$settings['max_links_per_post'],
				$settings['request_timeout'],
				'scheduled',
				$settings['link_scope'],
				$settings['scan_coverage']
			);

			$this->maybe_send_alert_email( $result, $settings );
			$this->update_dashboard_notification_state( $result );
		} catch ( Exception $e ) {
			// Keep scheduled runs fail-safe without writing temporary debug logs.
		} finally {
			delete_transient( self::LOCK_TRANSIENT );
		}
	}

	/**
	 * Scan links and store run record.
	 *
	 * @param array  $post_types Post types.
	 * @param int    $chunk_size Batch size.
	 * @param int    $max_links_per_post Max links per post. 0 = unlimited.
	 * @param int    $timeout Request timeout.
	 * @param string $trigger Trigger source.
	 * @param string $link_scope internal|external|both.
	 * @param string $scan_coverage selected|whole_site.
	 * @return array
	 */
	public function scan_links( $post_types, $chunk_size, $max_links_per_post, $timeout, $trigger = 'scheduled', $link_scope = 'both', $scan_coverage = 'selected' ) {
		$start_time = current_time( 'mysql' );

		$status_map    = array();
		$records       = array();
		$checked       = 0;
		$broken        = 0;
		$scanned_posts = 0;
		$breakdown     = array();

		$scope_breakdown = array(
			'internal' => array(
				'checked' => 0,
				'broken'  => 0,
			),
			'external' => array(
				'checked' => 0,
				'broken'  => 0,
			),
		);

		$error_breakdown = array(
			'404'           => 0,
			'http_error'    => 0,
			'request_error' => 0,
		);

		if ( 'whole_site' === $scan_coverage ) {
			$post_types = self::get_all_public_post_types();
		}

		$post_types = array_values( array_filter( array_map( 'sanitize_key', (array) $post_types ) ) );

		if ( empty( $post_types ) ) {
			$post_types = array( 'post' );
		}

		$chunk_size         = max( 5, min( 1000, absint( $chunk_size ) ) );
		$max_links_per_post = max( 0, min( 10000, intval( $max_links_per_post ) ) );
		$timeout            = max( 3, min( 30, absint( $timeout ) ) );
		$link_scope         = in_array( $link_scope, array( 'both', 'internal', 'external' ), true ) ? $link_scope : 'both';
		$scan_coverage      = in_array( $scan_coverage, array( 'selected', 'whole_site' ), true ) ? $scan_coverage : 'selected';

		foreach ( $post_types as $post_type ) {
			$last_id = 0;

			if ( ! isset( $breakdown[ $post_type ] ) ) {
				$breakdown[ $post_type ] = array(
					'checked' => 0,
					'broken'  => 0,
				);
			}

			while ( true ) {
				$posts = $this->get_next_post_batch( $post_type, $last_id, $chunk_size );

				if ( empty( $posts ) ) {
					break;
				}

				foreach ( $posts as $post_id ) {
					$content = (string) get_post_field( 'post_content', $post_id );
					$title   = get_the_title( $post_id );
					$links   = $this->extract_links( $content, $max_links_per_post );

					$scanned_posts++;

					foreach ( $links as $link ) {
						$scope = $this->is_internal_link( $link ) ? 'internal' : 'external';

						if ( 'internal' === $link_scope && 'external' === $scope ) {
							continue;
						}

						if ( 'external' === $link_scope && 'internal' === $scope ) {
							continue;
						}

						$checked++;
						$breakdown[ $post_type ]['checked']++;
						$scope_breakdown[ $scope ]['checked']++;

						if ( ! isset( $status_map[ $link ] ) ) {
							$status_map[ $link ] = $this->fetch_status( $link, $timeout );
						}

						$status_data = $status_map[ $link ];
						$is_broken   = ! empty( $status_data['broken'] );

						if ( $is_broken ) {
							$broken++;
							$breakdown[ $post_type ]['broken']++;
							$scope_breakdown[ $scope ]['broken']++;

							if ( ! empty( $status_data['status_type'] ) && isset( $error_breakdown[ $status_data['status_type'] ] ) ) {
								$error_breakdown[ $status_data['status_type'] ]++;
							}
						}

						if ( count( $records ) < self::MAX_DETAIL_RECORDS ) {
							$records[] = array(
								'post_id'    => (int) $post_id,
								'post_title' => $title,
								'post_type'  => $post_type,
								'link'       => $link,
								'scope'      => $scope,
								'http_code'  => isset( $status_data['code'] ) ? (int) $status_data['code'] : 0,
								'is_broken'  => $is_broken ? 1 : 0,
								'error'      => isset( $status_data['error'] ) ? $status_data['error'] : '',
							);
						}
					}
				}

				$last_id = (int) end( $posts );
			}
		}

		$working = max( 0, $checked - $broken );

		$snapshot = array(
			'timestamp'       => current_time( 'timestamp' ),
			'trigger'         => $trigger,
			'link_scope'      => $link_scope,
			'scan_coverage'   => $scan_coverage,
			'totalLinks'      => (int) $checked,
			'brokenLinks'     => (int) $broken,
			'workingLinks'    => (int) $working,
			'scannedCount'    => (int) $checked,
			'postTypes'       => $post_types,
			'breakdown'       => $breakdown,
			'scope_breakdown' => $scope_breakdown,
			'error_breakdown' => $error_breakdown,
			'records'         => $records,
		);

		update_option( self::SNAPSHOT_OPTION, $snapshot, false );

		$run_id = $this->insert_scan_run(
			array(
				'trigger_type'        => $trigger,
				'link_scope'          => $link_scope,
				'scan_coverage'       => $scan_coverage,
				'post_types'          => $post_types,
				'post_type_breakdown' => $breakdown,
				'scanned_posts_count' => $scanned_posts,
				'total_links_count'   => $checked,
				'broken_links_count'  => $broken,
				'working_links_count' => $working,
				'records_json'        => $records,
				'status'              => 'success',
				'started_at'          => $start_time,
				'ended_at'            => current_time( 'mysql' ),
				'email_sent'          => 0,
			)
		);

		$snapshot['run_id'] = $run_id;

		update_option( self::SNAPSHOT_OPTION, $snapshot, false );

		if ( class_exists( 'SeoRepairKit_SmartRedirect_Generator' ) ) {
			SeoRepairKit_SmartRedirect_Generator::generate_from_scan_records( $records );
		}

		return $snapshot;
	}

	/**
	 * Get cached URL status.
	 *
	 * @param string $url URL.
	 * @return array|null
	 */
	private function get_cached_status( $url ) {
		$cache = get_option( self::URL_CACHE_OPTION, array() );

		if ( ! is_array( $cache ) || empty( $cache[ $url ] ) ) {
			return null;
		}

		$entry = $cache[ $url ];

		if ( empty( $entry['checked_at'] ) || ( current_time( 'timestamp' ) - absint( $entry['checked_at'] ) ) > self::URL_CACHE_TTL ) {
			return null;
		}

		return isset( $entry['data'] ) && is_array( $entry['data'] ) ? $entry['data'] : null;
	}

	/**
	 * Store cached URL status.
	 *
	 * @param string $url URL.
	 * @param array  $data Status data.
	 * @return void
	 */
	private function set_cached_status( $url, $data ) {
		$cache = get_option( self::URL_CACHE_OPTION, array() );

		if ( ! is_array( $cache ) ) {
			$cache = array();
		}

		$cache[ $url ] = array(
			'checked_at' => current_time( 'timestamp' ),
			'data'       => $data,
		);

		if ( count( $cache ) > 5000 ) {
			$cache = array_slice( $cache, -5000, null, true );
		}

		update_option( self::URL_CACHE_OPTION, $cache, false );
	}

	/**
	 * Get next published post batch.
	 *
	 * @param string $post_type Post type.
	 * @param int    $last_id Last ID.
	 * @param int    $limit Limit.
	 * @return array
	 */
	private function get_next_post_batch( $post_type, $last_id, $limit ) {
		$limit = max( 1, absint( $limit ) );

		$sql = $this->db->prepare(
			"SELECT ID
			FROM {$this->db->posts}
			WHERE post_type = %s
			AND post_status = 'publish'
			AND ID > %d
			ORDER BY ID ASC
			LIMIT %d",
			$post_type,
			absint( $last_id ),
			$limit
		);

		return array_map( 'intval', (array) $this->db->get_col( $sql ) );
	}

	/**
	 * Get recent scan runs.
	 *
	 * @param int $limit Limit.
	 * @return array
	 */
	public function get_recent_runs( $limit = 20 ) {
		$this->ensure_history_tables();

		$table = $this->db->prefix . 'srk_link_scan_runs';
		$limit = max( 1, min( 1000, absint( $limit ) ) );

		return (array) $this->db->get_results(
			$this->db->prepare( "SELECT * FROM $table ORDER BY id DESC LIMIT %d", $limit ),
			ARRAY_A
		);
	}

	/**
	 * Get recent alerts.
	 *
	 * @param int $limit Limit.
	 * @return array
	 */
	public function get_recent_alerts( $limit = 20 ) {
		$this->ensure_history_tables();

		$table = $this->db->prefix . 'srk_link_scan_alerts';
		$limit = max( 1, min( 1000, absint( $limit ) ) );

		return (array) $this->db->get_results(
			$this->db->prepare( "SELECT * FROM $table ORDER BY id DESC LIMIT %d", $limit ),
			ARRAY_A
		);
	}

	/**
	 * Reset history and optionally settings.
	 *
	 * @param bool $reset_settings Reset settings too.
	 * @return void
	 */
		public function reset_records( $reset_settings = false ) {
		$this->ensure_history_tables();

		$runs_table   = $this->db->prefix . 'srk_link_scan_runs';
		$alerts_table = $this->db->prefix . 'srk_link_scan_alerts';

		$this->db->query( "TRUNCATE TABLE $runs_table" ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
		$this->db->query( "TRUNCATE TABLE $alerts_table" ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared

		delete_option( self::SNAPSHOT_OPTION );
		delete_option( self::CURSOR_OPTION );
		delete_option( self::LAST_ALERT_HASH );
		delete_option( self::URL_CACHE_OPTION );
		delete_option( self::DASHBOARD_ALERT_OPTION );
		delete_option( self::DASHBOARD_UNREAD_OPTION );
		delete_transient( self::STORAGE_CHECK_TRANSIENT );

		if ( $reset_settings ) {
			delete_option( self::SETTINGS_OPTION );
			self::reschedule_event( self::get_default_settings() );
			return;
		}

		self::reschedule_event( self::get_settings() );
	}

	/**
	 * Ensure history tables exist and old installs are upgraded safely.
	 *
	 * @return void
	 */
	public function ensure_history_tables() {
		$runs_table   = $this->db->prefix . 'srk_link_scan_runs';
		$alerts_table = $this->db->prefix . 'srk_link_scan_alerts';
		$charset      = $this->db->get_charset_collate();

		require_once ABSPATH . 'wp-admin/includes/upgrade.php';

		$sql_runs = "CREATE TABLE {$runs_table} (
			id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
			trigger_type VARCHAR(20) NOT NULL DEFAULT 'scheduled',
			link_scope VARCHAR(20) NOT NULL DEFAULT 'both',
			scan_coverage VARCHAR(20) NOT NULL DEFAULT 'selected',
			post_types LONGTEXT NULL,
			post_type_breakdown LONGTEXT NULL,
			scanned_posts_count INT UNSIGNED NOT NULL DEFAULT 0,
			total_links_count INT UNSIGNED NOT NULL DEFAULT 0,
			broken_links_count INT UNSIGNED NOT NULL DEFAULT 0,
			working_links_count INT UNSIGNED NOT NULL DEFAULT 0,
			records_json LONGTEXT NULL,
			status VARCHAR(20) NOT NULL DEFAULT 'success',
			email_sent TINYINT(1) NOT NULL DEFAULT 0,
			started_at DATETIME NOT NULL,
			ended_at DATETIME NOT NULL,
			PRIMARY KEY  (id),
			KEY idx_ended_at (ended_at),
			KEY idx_trigger (trigger_type),
			KEY idx_link_scope (link_scope)
		) {$charset};";

		$sql_alerts = "CREATE TABLE {$alerts_table} (
			id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
			scan_run_id BIGINT UNSIGNED NOT NULL,
			sent_at DATETIME NOT NULL,
			recipients TEXT NULL,
			subject TEXT NULL,
			broken_links_count INT UNSIGNED NOT NULL DEFAULT 0,
			post_type_breakdown LONGTEXT NULL,
			payload_snapshot LONGTEXT NULL,
			PRIMARY KEY  (id),
			KEY idx_scan_run_id (scan_run_id),
			KEY idx_sent_at (sent_at)
		) {$charset};";

		dbDelta( $sql_runs );
		dbDelta( $sql_alerts );

		$this->maybe_upgrade_history_schema();

	}

	/**
	 * Explicitly add missing columns/indexes for older installs.
	 *
	 * @return void
	 */
	private function maybe_upgrade_history_schema() {
		$runs_table = $this->db->prefix . 'srk_link_scan_runs';

		$columns = array();
		$results = (array) $this->db->get_results( "SHOW COLUMNS FROM {$runs_table}", ARRAY_A ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared

		foreach ( $results as $row ) {
			if ( ! empty( $row['Field'] ) ) {
				$columns[] = $row['Field'];
			}
		}

		if ( ! in_array( 'link_scope', $columns, true ) ) {
			$this->db->query( "ALTER TABLE {$runs_table} ADD COLUMN link_scope VARCHAR(20) NOT NULL DEFAULT 'both' AFTER trigger_type" ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
		}

		if ( ! in_array( 'scan_coverage', $columns, true ) ) {
			$this->db->query( "ALTER TABLE {$runs_table} ADD COLUMN scan_coverage VARCHAR(20) NOT NULL DEFAULT 'selected' AFTER link_scope" ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
		}

		$indexes     = (array) $this->db->get_results( "SHOW INDEX FROM {$runs_table}", ARRAY_A ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
		$index_names = wp_list_pluck( $indexes, 'Key_name' );

		if ( ! in_array( 'idx_link_scope', $index_names, true ) ) {
			$this->db->query( "ALTER TABLE {$runs_table} ADD KEY idx_link_scope (link_scope)" ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
		}
	}

	/**
	 * Extract links from content.
	 *
	 * @param string $content Post content.
	 * @param int    $max_links_per_post Limit. 0 = unlimited.
	 * @return array
	 */
	private function extract_links( $content, $max_links_per_post ) {
		if ( '' === trim( $content ) ) {
			return array();
		}

		$links = array();

		if ( class_exists( 'DOMDocument' ) ) {
			$internal_errors = libxml_use_internal_errors( true );

			$dom  = new DOMDocument();
			$html = '<?xml encoding="utf-8" ?>' . $content;

			if ( $dom->loadHTML( $html, LIBXML_HTML_NOIMPLIED | LIBXML_HTML_NODEFDTD ) ) {
				$anchors = $dom->getElementsByTagName( 'a' );

				foreach ( $anchors as $anchor ) {
					$href = trim( (string) $anchor->getAttribute( 'href' ) );

					if ( '' === $href ) {
						continue;
					}

					$normalized = $this->normalize_link_url( $href );

					if ( $normalized ) {
						$links[] = $normalized;
					}
				}
			}

			libxml_clear_errors();
			libxml_use_internal_errors( $internal_errors );
		}

		preg_match_all( '/\bhttps?:\/\/[^\s<>"\'\]]+/i', $content, $matches );

		if ( ! empty( $matches[0] ) ) {
			foreach ( $matches[0] as $raw_url ) {
				$normalized = $this->normalize_link_url( $raw_url );

				if ( $normalized ) {
					$links[] = $normalized;
				}
			}
		}

		$links = array_values( array_unique( $links ) );

		if ( $max_links_per_post > 0 && count( $links ) > $max_links_per_post ) {
			$links = array_slice( $links, 0, $max_links_per_post );
		}

		return $links;
	}

	/**
	 * Normalize link URL.
	 *
	 * @param string $url Raw URL.
	 * @return string
	 */
	private function normalize_link_url( $url ) {
		$url = trim( $url );

		if ( '' === $url ) {
			return '';
		}

		if (
			0 === strpos( $url, '#' ) ||
			0 === strpos( $url, 'mailto:' ) ||
			0 === strpos( $url, 'tel:' ) ||
			0 === strpos( $url, 'javascript:' )
		) {
			return '';
		}

		if ( 0 === strpos( $url, '//' ) ) {
			$scheme = is_ssl() ? 'https:' : 'http:';
			$url    = $scheme . $url;
		}

		if ( 0 === strpos( $url, '/' ) ) {
			$url = home_url( $url );
		}

		$url = esc_url_raw( $url );

		if ( ! $url || ! wp_http_validate_url( $url ) ) {
			return '';
		}

		return $url;
	}

	/**
	 * Fetch URL status.
	 *
	 * @param string $url URL.
	 * @param int    $timeout Timeout.
	 * @return array
	 */
	private function fetch_status( $url, $timeout ) {
		$cached = $this->get_cached_status( $url );

		if ( null !== $cached ) {
			return $cached;
		}

		$args = array(
			'timeout'     => $timeout,
			'redirection' => 5,
			'sslverify'   => true,
			'user-agent'  => 'SEO Repair Kit Links Manager/' . ( defined( 'SEO_REPAIR_KIT_VERSION' ) ? SEO_REPAIR_KIT_VERSION : '2.1.7' ) . '; ' . home_url(),
		);

		$response = wp_remote_head( $url, $args );

		if ( is_wp_error( $response ) || 405 === (int) wp_remote_retrieve_response_code( $response ) ) {
			$response = wp_remote_get(
				$url,
				array_merge(
					$args,
					array(
						'limit_response_size' => 1024,
					)
				)
			);
		}

		if ( is_wp_error( $response ) ) {
			$result = array(
				'code'        => 0,
				'broken'      => true,
				'error'       => $response->get_error_message(),
				'status_type' => 'request_error',
			);

			$this->set_cached_status( $url, $result );
			return $result;
		}

		$code = (int) wp_remote_retrieve_response_code( $response );

		$result = array(
			'code'        => $code,
			'broken'      => ( $code >= 400 || 0 === $code ),
			'error'       => '',
			'status_type' => ( 404 === $code ? '404' : ( $code >= 400 ? 'http_error' : 'ok' ) ),
		);

		$this->set_cached_status( $url, $result );

		return $result;
	}

	/**
	 * Check if URL is internal.
	 *
	 * @param string $url URL.
	 * @return bool
	 */
	private function is_internal_link( $url ) {
		$home_host = (string) wp_parse_url( home_url(), PHP_URL_HOST );
		$link_host = (string) wp_parse_url( $url, PHP_URL_HOST );

		return ! empty( $home_host ) && strtolower( $home_host ) === strtolower( $link_host );
	}

	/**
	 * Send alert email and save alert row.
	 *
	 * @param array $result Scan result.
	 * @param array $settings Settings.
	 * @return void
	 */
		private function maybe_send_alert_email( $result, $settings ) {
		if ( empty( $settings['email_enabled'] ) ) {
			return;
		}

		if ( empty( $settings['email_recipients'] ) ) {
			return;
		}

		$has_broken_links = ! empty( $result['brokenLinks'] );

		$current_hash = md5(
			wp_json_encode(
				array(
					isset( $result['brokenLinks'] ) ? $result['brokenLinks'] : 0,
					isset( $result['totalLinks'] ) ? $result['totalLinks'] : 0,
					isset( $result['breakdown'] ) ? $result['breakdown'] : array(),
					isset( $result['scope_breakdown'] ) ? $result['scope_breakdown'] : array(),
					isset( $result['error_breakdown'] ) ? $result['error_breakdown'] : array(),
					isset( $result['link_scope'] ) ? $result['link_scope'] : 'both',
					isset( $result['scan_coverage'] ) ? $result['scan_coverage'] : 'selected',
					$has_broken_links ? 'broken_report' : 'healthy_report',
				)
			)
		);

		$last_payload = get_option( self::LAST_ALERT_HASH, array() );
		$last_payload = is_array( $last_payload ) ? $last_payload : array();
		$last_hash    = isset( $last_payload['hash'] ) ? (string) $last_payload['hash'] : '';
		$last_time    = isset( $last_payload['time'] ) ? absint( $last_payload['time'] ) : 0;

		$now            = current_time( 'timestamp' );
		$under_cooldown = ( $last_time > 0 && ( $now - $last_time ) < self::ALERT_COOLDOWN_HOUR );

		if ( $under_cooldown && $current_hash === $last_hash ) {
			return;
		}

		if ( ! class_exists( 'SeoRepairKit_LinkScanner_Email_Report' ) ) {
			return;
		}

		$send_result = SeoRepairKit_LinkScanner_Email_Report::send_report( $result, $settings );

		if ( empty( $send_result['sent'] ) ) {
			return;
		}

		update_option(
			self::LAST_ALERT_HASH,
			array(
				'hash' => $current_hash,
				'time' => $now,
			),
			false
		);

		$run_id = isset( $result['run_id'] ) ? absint( $result['run_id'] ) : 0;

		if ( $run_id > 0 ) {
			$runs_table = $this->db->prefix . 'srk_link_scan_runs';

			$this->db->update(
				$runs_table,
				array( 'email_sent' => 1 ),
				array( 'id' => $run_id ),
				array( '%d' ),
				array( '%d' )
			);
		}

		$this->insert_alert(
			array(
				'scan_run_id'         => $run_id,
				'sent_at'             => current_time( 'mysql' ),
				'recipients'          => isset( $send_result['recipients_string'] ) ? $send_result['recipients_string'] : '',
				'subject'             => isset( $send_result['subject'] ) ? $send_result['subject'] : '',
				'broken_links_count'  => isset( $result['brokenLinks'] ) ? (int) $result['brokenLinks'] : 0,
				'post_type_breakdown' => wp_json_encode( isset( $result['breakdown'] ) ? $result['breakdown'] : array() ),
				'payload_snapshot'    => wp_json_encode( $result ),
			)
		);
	}

	/**
	 * Update dashboard alert state.
	 *
	 * @param array $result Scan result.
	 * @return void
	 */
	private function update_dashboard_notification_state( $result ) {
		if ( empty( $result['brokenLinks'] ) ) {
			return;
		}

		update_option(
			self::DASHBOARD_ALERT_OPTION,
			array(
				'time'          => current_time( 'timestamp' ),
				'broken_links'  => (int) $result['brokenLinks'],
				'trigger'       => isset( $result['trigger'] ) ? $result['trigger'] : 'scheduled',
				'link_scope'    => isset( $result['link_scope'] ) ? $result['link_scope'] : 'both',
				'scan_coverage' => isset( $result['scan_coverage'] ) ? $result['scan_coverage'] : 'selected',
				'scope'         => isset( $result['scope_breakdown'] ) ? $result['scope_breakdown'] : array(),
				'post_types'    => isset( $result['breakdown'] ) ? $result['breakdown'] : array(),
			),
			false
		);

		$unread = (int) get_option( self::DASHBOARD_UNREAD_OPTION, 0 );
		update_option( self::DASHBOARD_UNREAD_OPTION, $unread + 1, false );
	}

	/**
	 * Insert scan run row.
	 *
	 * @param array $data Data.
	 * @return int
	 */
	private function insert_scan_run( $data ) {
		$this->ensure_history_tables();

		$table = $this->db->prefix . 'srk_link_scan_runs';

		$inserted = $this->db->insert(
			$table,
			array(
				'trigger_type'        => (string) $data['trigger_type'],
				'link_scope'          => isset( $data['link_scope'] ) ? (string) $data['link_scope'] : 'both',
				'scan_coverage'       => isset( $data['scan_coverage'] ) ? (string) $data['scan_coverage'] : 'selected',
				'post_types'          => wp_json_encode( (array) $data['post_types'] ),
				'post_type_breakdown' => wp_json_encode( (array) $data['post_type_breakdown'] ),
				'scanned_posts_count' => (int) $data['scanned_posts_count'],
				'total_links_count'   => (int) $data['total_links_count'],
				'broken_links_count'  => (int) $data['broken_links_count'],
				'working_links_count' => (int) $data['working_links_count'],
				'records_json'        => wp_json_encode( (array) $data['records_json'] ),
				'status'              => (string) $data['status'],
				'email_sent'          => (int) $data['email_sent'],
				'started_at'          => (string) $data['started_at'],
				'ended_at'            => (string) $data['ended_at'],
			),
			array( '%s', '%s', '%s', '%s', '%s', '%d', '%d', '%d', '%d', '%s', '%s', '%d', '%s', '%s' )
		);

		if ( false === $inserted ) {
			return 0;
		}

		$new_id = (int) $this->db->insert_id;

		$this->prune_old_scan_runs();

		return $new_id;
	}

	/**
	 * Keep only latest scan run records.
	 *
	 * @return void
	 */
	private function prune_old_scan_runs() {
		$table = $this->db->prefix . 'srk_link_scan_runs';
		$limit = self::MAX_SCAN_RECORDS;

		$keep_ids = $this->db->get_col(
			$this->db->prepare(
				"SELECT id FROM $table ORDER BY id DESC LIMIT %d",
				$limit
			)
		);

		if ( empty( $keep_ids ) ) {
			return;
		}

		$keep_ids     = array_map( 'absint', $keep_ids );
		$placeholders = implode( ',', array_fill( 0, count( $keep_ids ), '%d' ) );

		$this->db->query(
			$this->db->prepare(
				"DELETE FROM $table WHERE id NOT IN ($placeholders)",
				$keep_ids
			)
		); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
	}

	/**
	 * Keep only latest alert history records.
	 *
	 * @return void
	 */
	private function prune_old_alerts() {
		$table = $this->db->prefix . 'srk_link_scan_alerts';
		$limit = self::MAX_SCAN_RECORDS;

		$keep_ids = $this->db->get_col(
			$this->db->prepare(
				"SELECT id FROM $table ORDER BY id DESC LIMIT %d",
				$limit
			)
		);

		if ( empty( $keep_ids ) ) {
			return;
		}

		$keep_ids     = array_map( 'absint', $keep_ids );
		$placeholders = implode( ',', array_fill( 0, count( $keep_ids ), '%d' ) );

		$this->db->query(
			$this->db->prepare(
				"DELETE FROM $table WHERE id NOT IN ($placeholders)",
				$keep_ids
			)
		); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
	}

	/**
	 * Insert alert row.
	 *
	 * @param array $data Alert data.
	 * @return void
	 */
	private function insert_alert( $data ) {
		$table = $this->db->prefix . 'srk_link_scan_alerts';

		$inserted = $this->db->insert(
			$table,
			array(
				'scan_run_id'         => (int) $data['scan_run_id'],
				'sent_at'             => (string) $data['sent_at'],
				'recipients'          => (string) $data['recipients'],
				'subject'             => (string) $data['subject'],
				'broken_links_count'  => (int) $data['broken_links_count'],
				'post_type_breakdown' => (string) $data['post_type_breakdown'],
				'payload_snapshot'    => (string) $data['payload_snapshot'],
			),
			array( '%d', '%s', '%s', '%s', '%d', '%s', '%s' )
		);

		if ( false === $inserted ) {
			return;
		}

		$this->prune_old_alerts();
	}
}