<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Database layer for the SERP-only Spam Monitor.
 */
class SRK_Spam_Monitor_DB {

	const DB_VERSION = '2.0.1';
	const DB_VERSION_OPTION = 'srk_spam_monitor_db_version';
	const ALERT_SETTINGS_OPTION = 'srk_spam_monitor_alert_settings';

	/**
	 * Request-level alert history cache.
	 *
	 * @var array
	 */
	private static $alert_history_cache = array();

	/**
	 * Request-level alert count cache.
	 *
	 * @var int|null
	 */
	private static $alert_history_count = null;

	/**
	 * Quote a local SQL identifier.
	 *
	 * WordPress 5.0 compatibility prevents using the newer %i placeholder.
	 *
	 * @param string $identifier Identifier.
	 * @return string
	 */
	private static function quote_identifier( $identifier ) {
		return '`' . str_replace( '`', '``', $identifier ) . '`';
	}

	/**
	 * Get a plugin-owned table name by allowlisted key.
	 *
	 * @param string $key Table key.
	 * @return string
	 */
	private static function get_table_name( $key ) {
		global $wpdb;

		$tables = array(
			'rules'        => $wpdb->prefix . 'srk_spam_monitor_rules',
			'alerts'       => $wpdb->prefix . 'srk_spam_monitor_alerts',
			'serp_scans'   => $wpdb->prefix . 'srk_spam_monitor_serp_scans',
			'serp_results' => $wpdb->prefix . 'srk_spam_monitor_serp_results',
		);

		return isset( $tables[ $key ] ) ? $tables[ $key ] : '';
	}

	/**
	 * Get an escaped SQL table identifier by allowlisted key.
	 *
	 * @param string $key Table key.
	 * @return string
	 */
	private static function get_table_identifier( $key ) {
		$table_name = self::get_table_name( $key );

		return '' === $table_name ? '' : self::quote_identifier( $table_name );
	}

	/**
	 * Create active custom tables.
	 *
	 * @return void
	 */
	public static function create_tables() {
		global $wpdb;

		require_once ABSPATH . 'wp-admin/includes/upgrade.php';

		$prefix = $wpdb->prefix;
		$charset_collate = $wpdb->get_charset_collate();

		dbDelta(
			"CREATE TABLE {$prefix}srk_spam_monitor_rules (
				id BIGINT(20) UNSIGNED NOT NULL AUTO_INCREMENT,
				rule_key VARCHAR(100) NOT NULL,
				rule_value LONGTEXT DEFAULT NULL,
				created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
				updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
				PRIMARY KEY (id),
				UNIQUE KEY idx_rule_key (rule_key)
			) $charset_collate;"
		);

		dbDelta(
			"CREATE TABLE {$prefix}srk_spam_monitor_alerts (
				id BIGINT(20) UNSIGNED NOT NULL AUTO_INCREMENT,
				issue_id BIGINT(20) UNSIGNED DEFAULT NULL,
				scan_id BIGINT(20) UNSIGNED DEFAULT NULL,
				domain VARCHAR(255) DEFAULT NULL,
				alert_type VARCHAR(80) DEFAULT 'Spam Detected',
				url TEXT NOT NULL,
				score SMALLINT(5) DEFAULT 0,
				risk_level VARCHAR(20) DEFAULT 'unknown',
				url_count INT(10) UNSIGNED DEFAULT 0,
				recipient TEXT DEFAULT NULL,
				subject TEXT DEFAULT NULL,
				status VARCHAR(20) DEFAULT 'sent',
				sent_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
				PRIMARY KEY (id),
				KEY idx_issue_id (issue_id),
				KEY idx_scan_id (scan_id),
				KEY idx_domain (domain),
				KEY idx_risk_level (risk_level),
				KEY idx_status (status),
				KEY idx_sent_at (sent_at),
				KEY idx_sent_at_id (sent_at, id)
			) $charset_collate;"
		);

		dbDelta(
			"CREATE TABLE {$prefix}srk_spam_monitor_serp_scans (
				id BIGINT(20) UNSIGNED NOT NULL AUTO_INCREMENT,
				domain VARCHAR(255) NOT NULL,
				query VARCHAR(500) DEFAULT NULL,
				provider_used VARCHAR(100) DEFAULT NULL,
				engine_used VARCHAR(100) DEFAULT NULL,
				requested_results INT(10) UNSIGNED DEFAULT 0,
				received_results INT(10) UNSIGNED DEFAULT 0,
				unique_results INT(10) UNSIGNED DEFAULT 0,
				serp_requests_used INT(10) UNSIGNED DEFAULT 0,
				overall_risk_score INT(10) UNSIGNED DEFAULT 0,
				clean_count INT(10) UNSIGNED DEFAULT 0,
				changed_count INT(10) UNSIGNED DEFAULT 0,
				suspicious_count INT(10) UNSIGNED DEFAULT 0,
				spam_count INT(10) UNSIGNED DEFAULT 0,
				critical_count INT(10) UNSIGNED DEFAULT 0,
				status VARCHAR(30) DEFAULT 'completed',
				scan_source VARCHAR(20) NOT NULL DEFAULT 'manual',
				provider_metadata LONGTEXT DEFAULT NULL,
				cost_protection LONGTEXT DEFAULT NULL,
				raw_response LONGTEXT DEFAULT NULL,
				created_at DATETIME NOT NULL,
				completed_at DATETIME DEFAULT NULL,
				PRIMARY KEY (id),
				KEY domain (domain),
				KEY created_at (created_at),
				KEY status (status),
				KEY scan_source (scan_source)
			) $charset_collate;"
		);

		dbDelta(
			"CREATE TABLE {$prefix}srk_spam_monitor_serp_results (
				id BIGINT(20) UNSIGNED NOT NULL AUTO_INCREMENT,
				scan_id BIGINT(20) UNSIGNED NOT NULL,
				domain VARCHAR(255) NOT NULL,
				position INT(10) UNSIGNED DEFAULT 0,
				url TEXT NOT NULL,
				normalized_url VARCHAR(500) DEFAULT NULL,
				displayed_link VARCHAR(500) DEFAULT NULL,
				google_title TEXT DEFAULT NULL,
				google_snippet TEXT DEFAULT NULL,
				risk_score INT(10) UNSIGNED DEFAULT 0,
				risk_level VARCHAR(30) DEFAULT 'Clean',
				issues LONGTEXT DEFAULT NULL,
				cleanup_status VARCHAR(40) DEFAULT 'Detected',
				http_status SMALLINT(5) DEFAULT 0,
				redirect_status SMALLINT(5) DEFAULT 0,
				url_exists TINYINT(1) DEFAULT NULL,
				canonical_url TEXT DEFAULT NULL,
				sitemap_url TEXT DEFAULT NULL,
				in_sitemap TINYINT(1) DEFAULT NULL,
				sitemap_checked_at DATETIME DEFAULT NULL,
				cleanup_updated_at DATETIME DEFAULT NULL,
				result_hash CHAR(64) DEFAULT NULL,
				first_seen_at DATETIME NOT NULL,
				last_seen_at DATETIME NOT NULL,
				created_at DATETIME NOT NULL,
				PRIMARY KEY (id),
				KEY scan_id (scan_id),
				KEY domain (domain),
				KEY normalized_url (normalized_url(191)),
				KEY result_hash (result_hash),
				KEY risk_level (risk_level),
				KEY cleanup_status (cleanup_status)
			) $charset_collate;"
		);

		if ( false === get_option( self::DB_VERSION_OPTION, false ) ) {
			add_option( self::DB_VERSION_OPTION, self::DB_VERSION, '', 'no' );
			return;
		}

		update_option( self::DB_VERSION_OPTION, self::DB_VERSION, false );
	}

	/**
	 * Create tables when the stored DB version is old.
	 *
	 * @return void
	 */
	public static function maybe_create_tables() {
		$installed = get_option( self::DB_VERSION_OPTION, '0.0.0' );

		if ( version_compare( (string) $installed, self::DB_VERSION, '>=' ) ) {
			return;
		}

		self::create_tables();
	}

	/**
	 * Get active Spam Monitor custom table names.
	 *
	 * @return string[]
	 */
	public static function get_active_table_names() {
		return array(
			'srk_spam_monitor_rules',
			'srk_spam_monitor_alerts',
			'srk_spam_monitor_serp_scans',
			'srk_spam_monitor_serp_results',
		);
	}

	/**
	 * Ensure SERP tables exist.
	 *
	 * @return void
	 */
	public static function maybe_upgrade_serp_tables() {
		self::maybe_create_tables();
	}

	/**
	 * Drop all current and legacy Spam Monitor tables on uninstall.
	 *
	 * @return void
	 */
	public static function drop_tables() {
		// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- Explicit uninstall lifecycle for plugin-owned Spam Monitor tables; no caching is appropriate for schema deletion.
		global $wpdb;

		$tables = array(
			'srk_spam_monitor_urls',
			'srk_spam_monitor_baselines',
			'srk_spam_monitor_scans',
			'srk_spam_monitor_scan_results',
			'srk_spam_monitor_issues',
			'srk_spam_monitor_fix_actions',
			'srk_spam_monitor_google_actions',
			'srk_spam_monitor_rules',
			'srk_spam_monitor_alerts',
			'srk_spam_monitor_serp_scans',
			'srk_spam_monitor_serp_results',
		);

		foreach ( $tables as $table ) {
			$full_table = $wpdb->prefix . $table;
			$full_table_sql = self::quote_identifier( $full_table );
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,WordPress.DB.DirectDatabaseQuery.SchemaChange,WordPress.DB.PreparedSQL.InterpolatedNotPrepared,PluginCheck.Security.DirectDB.UnescapedDBParameter -- Explicit Spam Monitor uninstall/drop lifecycle for plugin-owned tables.
			$wpdb->query( "DROP TABLE IF EXISTS {$full_table_sql}" );
		}

		delete_option( self::DB_VERSION_OPTION );
		delete_option( self::ALERT_SETTINGS_OPTION );
		// phpcs:enable WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
	}

	/**
	 * Get one rule.
	 *
	 * @param string $key Rule key.
	 * @param mixed  $default Default value.
	 * @return mixed
	 */
	public static function get_rule( $key, $default = null ) {
		global $wpdb;

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,WordPress.DB.PreparedSQL.InterpolatedNotPrepared,PluginCheck.Security.DirectDB.UnescapedDBParameter -- Read from plugin-owned rules table; identifier comes from get_table_identifier().
		$value = $wpdb->get_var( $wpdb->prepare( "SELECT rule_value FROM `{$wpdb->prefix}srk_spam_monitor_rules` WHERE rule_key = %s", sanitize_key( $key ) ) );

		if ( null === $value ) {
			return $default;
		}

		$decoded = json_decode( $value, true );
		return ( JSON_ERROR_NONE === json_last_error() ) ? $decoded : $value;
	}

	/**
	 * Save one rule.
	 *
	 * @param string $key Rule key.
	 * @param mixed  $value Rule value.
	 * @return bool
	 */
	public static function save_rule( $key, $value ) {
		global $wpdb;

		self::maybe_create_tables();

		$table = self::get_table_name( 'rules' );
		$key = sanitize_key( $key );
		$stored = ( is_array( $value ) || is_object( $value ) ) ? wp_json_encode( $value ) : (string) $value;

		return false !== $wpdb->replace( // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- Intentional write to plugin-owned rules table; write operations are not cached.
			$table,
			array(
				'rule_key'   => $key,
				'rule_value' => $stored,
				'updated_at' => current_time( 'mysql' ),
			),
			array( '%s', '%s', '%s' )
		);
	}

	/**
	 * Get all rules merged with defaults.
	 *
	 * @return array
	 */
	public static function get_all_rules() {
		// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- Request-time read from plugin-owned rules table; merged with local defaults.
		global $wpdb;

		self::maybe_create_tables();

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,WordPress.DB.PreparedSQL.NotPrepared -- Read from plugin-owned rules table; identifier comes from get_table_identifier().
		$rows = $wpdb->get_results( "SELECT rule_key, rule_value FROM `{$wpdb->prefix}srk_spam_monitor_rules`", ARRAY_A );
		$rules = self::get_default_rules();

		foreach ( (array) $rows as $row ) {
			$decoded = json_decode( $row['rule_value'], true );
			$rules[ $row['rule_key'] ] = ( JSON_ERROR_NONE === json_last_error() ) ? $decoded : $row['rule_value'];
		}

		// phpcs:enable WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
		return $rules;
	}

	/**
	 * Get default SERP rules.
	 *
	 * @return array
	 */
	public static function get_default_rules() {
		return array(
			'lang_expected'               => array( 'zh', 'ko', 'ja', 'ru', 'fr', 'de', 'ur', 'th', 'vi', 'id' ),
			'lang_allowed'                => array( 'en' ),
			'lang_flag_unexpected'        => 1,
			'lang_mismatch_score'         => 45,
			'keyword_categories'          => array( 'gambling', 'adult', 'pharma', 'vape', 'counterfeit', 'jp_cn_spam' ),
			'keyword_category_terms'      => class_exists( 'SRK_Spam_Monitor_Check_Keywords' ) ? SRK_Spam_Monitor_Check_Keywords::get_builtin_keywords() : array(),
			'custom_blocked_keywords'     => array(),
			'spam_keyword_score'          => 35,
			'url_detect_fake_product'     => 1,
			'url_detect_suspicious_slugs' => 1,
			'url_blocked_patterns'        => array(),
			'url_pattern_score'           => 35,
			'score_clean_max'             => 30,
			'score_suspicious_max'        => 60,
			'score_spam_min'              => 61,
			'score_critical_min'          => 81,
		);
	}

	/**
	 * Get alert settings.
	 *
	 * @return array
	 */
	public static function get_alert_settings() {
		$settings = get_option( self::ALERT_SETTINGS_OPTION, array() );
		return is_array( $settings ) ? $settings : array();
	}

	/**
	 * Save alert settings.
	 *
	 * @param array $settings Settings.
	 * @return bool
	 */
	public static function save_alert_settings( array $settings ) {
		return update_option( self::ALERT_SETTINGS_OPTION, $settings );
	}

	/**
	 * Save an alert log row.
	 *
	 * @param array $data Alert data.
	 * @return int|false
	 */
	public static function save_alert_log( array $data ) {
		global $wpdb;

		self::maybe_create_tables();

		$table = self::get_table_name( 'alerts' );
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- Intentional insert into plugin-owned SERP scan table; write operations are not cached.
		$inserted = $wpdb->insert(
			$table,
			array(
				'issue_id'   => absint( $data['issue_id'] ?? 0 ),
				'scan_id'    => absint( $data['scan_id'] ?? 0 ),
				'domain'     => sanitize_text_field( $data['domain'] ?? '' ),
				'alert_type' => sanitize_text_field( $data['alert_type'] ?? 'Spam Detected' ),
				'url'        => esc_url_raw( $data['url'] ?? '' ),
				'score'      => absint( $data['score'] ?? 0 ),
				'risk_level' => sanitize_text_field( $data['risk_level'] ?? 'unknown' ),
				'url_count'  => absint( $data['url_count'] ?? 0 ),
				'recipient'  => sanitize_text_field( $data['recipient'] ?? '' ),
				'subject'    => sanitize_text_field( $data['subject'] ?? '' ),
				'status'     => sanitize_key( $data['status'] ?? 'sent' ),
				'sent_at'    => current_time( 'mysql' ),
			),
			array( '%d', '%d', '%s', '%s', '%s', '%d', '%s', '%d', '%s', '%s', '%s', '%s' )
		);

		if ( $inserted ) {
			self::clear_alert_history_cache();
			return (int) $wpdb->insert_id;
		}

		return false;
	}

	/**
	 * Get alert history rows.
	 *
	 * @param int $limit Limit.
	 * @return array
	 */
	public static function get_alert_history( $limit = 100, $offset = 0 ) {
		global $wpdb;

		self::maybe_create_tables();

		$limit = max( 1, min( 1000, absint( $limit ) ) );
		$offset = absint( $offset );
		$cache_key = $limit . ':' . $offset;

		if ( isset( self::$alert_history_cache[ $cache_key ] ) ) {
			return self::$alert_history_cache[ $cache_key ];
		}

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Request-cached alert history read from plugin-owned alerts table.
		$rows = $wpdb->get_results(
			$wpdb->prepare( "SELECT id, domain, alert_type, score, risk_level, url_count, status, sent_at FROM `{$wpdb->prefix}srk_spam_monitor_alerts` FORCE INDEX (idx_sent_at_id) ORDER BY sent_at DESC, id DESC LIMIT %d OFFSET %d", $limit, $offset ),
			ARRAY_A
		);

		self::$alert_history_cache[ $cache_key ] = is_array( $rows ) ? $rows : array();

		return self::$alert_history_cache[ $cache_key ];
	}

	public static function count_alert_history() {
		global $wpdb;

		if ( null !== self::$alert_history_count ) {
			return self::$alert_history_count;
		}

		self::maybe_create_tables();
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Request-cached alert count from plugin-owned alerts table.
		self::$alert_history_count = absint( $wpdb->get_var( "SELECT COUNT(*) FROM `{$wpdb->prefix}srk_spam_monitor_alerts`" ) );

		return self::$alert_history_count;
	}

	/**
	 * Clear request-level alert history caches.
	 *
	 * @return void
	 */
	private static function clear_alert_history_cache() {
		self::$alert_history_cache = array();
		self::$alert_history_count = null;
	}

	public static function get_alert_risk_counts( $days = 30 ) {
		// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- Real-time alert risk aggregate from plugin-owned alerts table.
		global $wpdb;
		self::maybe_create_tables();
		$since = gmdate( 'Y-m-d H:i:s', time() - ( max( 1, absint( $days ) ) * DAY_IN_SECONDS ) );
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,WordPress.DB.PreparedSQL.NotPrepared -- Real-time alert risk aggregate from plugin-owned alerts table.
		$rows = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT LOWER(risk_level) risk_level, COUNT(*) total FROM `{$wpdb->prefix}srk_spam_monitor_alerts` WHERE sent_at >= %s GROUP BY LOWER(risk_level)",
				$since
			),
			ARRAY_A
		);
		$counts = array( 'clean' => 0, 'suspicious' => 0, 'spam' => 0, 'critical' => 0 );
		foreach ( (array) $rows as $row ) {
			$key = sanitize_key( $row['risk_level'] ?? '' );
			if ( isset( $counts[ $key ] ) ) {
				$counts[ $key ] = absint( $row['total'] ?? 0 );
			}
		}
		// phpcs:enable WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
		return $counts;
	}

	/**
	 * Trim alert history to retention limit.
	 *
	 * @param int $limit Retention limit.
	 * @return void
	 */
	public static function trim_alert_history( $limit = 100 ) {
		// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- Retention lookup and cleanup for plugin-owned alerts table; cleanup writes are not cached.
		global $wpdb;

		self::maybe_create_tables();

		$limit = max( 25, min( 1000, absint( $limit ) ) );
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,WordPress.DB.PreparedSQL.NotPrepared -- Retention lookup from plugin-owned alerts table.
		$ids = $wpdb->get_col( $wpdb->prepare( "SELECT id FROM `{$wpdb->prefix}srk_spam_monitor_alerts` ORDER BY sent_at DESC, id DESC LIMIT 999999 OFFSET %d", $limit ) );
		if ( empty( $ids ) ) {
			return;
		}

		$ids = array_map( 'absint', $ids );
		$placeholders = implode( ',', array_fill( 0, count( $ids ), '%d' ) );
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,WordPress.DB.PreparedSQL.NotPrepared -- Retention cleanup write for plugin-owned alerts table.
		$wpdb->query( $wpdb->prepare( "DELETE FROM `{$wpdb->prefix}srk_spam_monitor_alerts` WHERE id IN ({$placeholders})", $ids ) );
		self::clear_alert_history_cache();
		// phpcs:enable WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
	}

	/**
	 * Insert SERP scan summary.
	 *
	 * @param array $response Python response.
	 * @param array $request_args Request args.
	 * @return int|false
	 */
	public static function insert_serp_scan( array $response, array $request_args ) {
		// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- Intentional insert into plugin-owned SERP scan table; write operations are not cached.
		global $wpdb;

		self::maybe_upgrade_serp_tables();

		$table = $wpdb->prefix . 'srk_spam_monitor_serp_scans';
		$domain = self::sanitize_serp_domain( $response['domain'] ?? ( $request_args['domain'] ?? '' ) );
		if ( '' === $domain ) {
			return false;
		}

		$counts = self::count_serp_risk_levels( $response['results'] ?? array() );
		$now = current_time( 'mysql' );
		$scan_source = sanitize_key( $request_args['scan_source'] ?? 'manual' );
		$scan_source = in_array( $scan_source, array( 'manual', 'scheduled' ), true ) ? $scan_source : 'manual';

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- Intentional insert into plugin-owned SERP scan table; write operations are not cached.
		$inserted = $wpdb->insert(
			$table,
			array(
				'domain'             => $domain,
				'query'              => sanitize_text_field( $response['query'] ?? '' ),
				'provider_used'      => sanitize_text_field( $response['provider_used'] ?? '' ),
				'engine_used'        => sanitize_text_field( $response['engine_used'] ?? '' ),
				'requested_results'  => absint( $response['requested_results'] ?? ( $request_args['max_results'] ?? 0 ) ),
				'received_results'   => absint( $response['received_results'] ?? 0 ),
				'unique_results'     => absint( $response['unique_results'] ?? 0 ),
				'serp_requests_used' => absint( $response['serp_requests_used'] ?? 0 ),
				'overall_risk_score' => absint( $response['overall_risk_score'] ?? 0 ),
				'clean_count'        => $counts['clean'],
				'changed_count'      => $counts['changed'],
				'suspicious_count'   => $counts['suspicious'],
				'spam_count'         => $counts['spam'],
				'critical_count'     => $counts['critical'],
				'status'             => 'completed',
				'scan_source'        => $scan_source,
				'provider_metadata'  => wp_json_encode( $response['provider_metadata'] ?? array() ),
				'cost_protection'    => wp_json_encode( $response['cost_protection'] ?? array() ),
				'raw_response'       => apply_filters( 'srk_spam_monitor_store_serp_raw_response', true ) ? wp_json_encode( $response ) : null,
				'created_at'         => $now,
				'completed_at'       => $now,
			),
			array( '%s', '%s', '%s', '%s', '%d', '%d', '%d', '%d', '%d', '%d', '%d', '%d', '%d', '%d', '%s', '%s', '%s', '%s', '%s', '%s', '%s' )
		);

		$insert_id = $inserted ? (int) $wpdb->insert_id : false;
		// phpcs:enable WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
		return $insert_id;
	}

	/**
	 * Insert SERP result rows.
	 *
	 * @param int    $scan_id Scan ID.
	 * @param array  $results Results.
	 * @param string $domain Domain.
	 * @return int
	 */
	public static function insert_serp_results( $scan_id, array $results, $domain ) {
		global $wpdb;

		$scan_id = absint( $scan_id );
		$domain = self::sanitize_serp_domain( $domain );
		if ( ! $scan_id || '' === $domain ) {
			return 0;
		}

		$table = $wpdb->prefix . 'srk_spam_monitor_serp_results';
		$now = current_time( 'mysql' );
		$inserted = 0;

		foreach ( $results as $result ) {
			if ( ! is_array( $result ) || empty( $result['url'] ) ) {
				continue;
			}

			$raw_title = (string) ( $result['google_title'] ?? '' );
			$raw_snippet = (string) ( $result['google_snippet'] ?? '' );
			$normalized_url = esc_url_raw( $result['normalized_url'] ?? '' );

			// phpcs:ignore WordPress.DB.DirectDatabaseQuery -- Intentional insert into plugin-owned SERP result table; write operations are not cached.
			$ok = $wpdb->insert(
				$table,
				array(
					'scan_id'        => $scan_id,
					'domain'         => $domain,
					'position'       => absint( $result['position'] ?? 0 ),
					'url'            => esc_url_raw( $result['url'] ),
					'normalized_url' => $normalized_url,
					'displayed_link' => sanitize_text_field( $result['displayed_link'] ?? '' ),
					'google_title'   => sanitize_text_field( $raw_title ),
					'google_snippet' => sanitize_textarea_field( $raw_snippet ),
					'risk_score'     => absint( $result['risk_score'] ?? 0 ),
					'risk_level'     => sanitize_text_field( $result['risk_level'] ?? 'Clean' ),
					'issues'         => wp_json_encode( $result['issues'] ?? array() ),
					'result_hash'    => hash( 'sha256', $normalized_url . $raw_title . $raw_snippet ),
					'first_seen_at'  => $now,
					'last_seen_at'   => $now,
					'created_at'     => $now,
				),
				array( '%d', '%s', '%d', '%s', '%s', '%s', '%s', '%s', '%d', '%s', '%s', '%s', '%s', '%s', '%s' )
			);

			if ( $ok ) {
				$inserted++;
			}
		}

		return $inserted;
	}

	/**
	 * Get SERP dashboard summary.
	 *
	 * @return array
	 */
	public static function get_serp_dashboard_summary( $risky_limit = 10, $risky_offset = 0, $risky_search = '', $risky_level = '' ) {
		global $wpdb;

		self::maybe_upgrade_serp_tables();

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery,WordPress.DB.PreparedSQL.NotPrepared -- Real-time aggregate from plugin-owned SERP scan table.
		$totals = $wpdb->get_row(
			"SELECT COUNT(*) AS total_scans,
				MAX(created_at) AS last_scan_date,
				COALESCE(SUM(received_results), 0) AS total_results_checked,
				COALESCE(SUM(critical_count), 0) AS critical_results,
				COALESCE(SUM(spam_count), 0) AS spam_results,
				COALESCE(SUM(suspicious_count), 0) AS suspicious_results,
				COALESCE(SUM(serp_requests_used), 0) AS serp_requests_used
			FROM `{$wpdb->prefix}srk_spam_monitor_serp_scans`",
			ARRAY_A
		);

		$risky_limit  = max( 1, min( 100, absint( $risky_limit ) ) );
		$risky_offset = absint( $risky_offset );
		$risky_search = sanitize_text_field( $risky_search );
		$risky_level  = sanitize_key( $risky_level );
		if ( ! in_array( $risky_level, array( 'critical', 'spam', 'suspicious' ), true ) ) {
			$risky_level = '';
		}
		$like = '%' . $wpdb->esc_like( $risky_search ) . '%';
		// phpcs:disable WordPress.DB.DirectDatabaseQuery -- Results SQL is built from plugin-owned identifiers, allowlisted SQL fragments, and prepared values.
		$risky_urls = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT domain, url, google_title, risk_level, risk_score, issues, created_at
				FROM `{$wpdb->prefix}srk_spam_monitor_serp_results`
				WHERE LOWER(risk_level) IN ('critical', 'spam', 'suspicious')
					AND ( %s = '' OR domain LIKE %s OR url LIKE %s OR google_title LIKE %s OR issues LIKE %s )
					AND ( %s = '' OR LOWER(risk_level) = %s )
				ORDER BY risk_score DESC, created_at DESC LIMIT %d OFFSET %d",
				$risky_search,
				$like,
				$like,
				$like,
				$like,
				$risky_level,
				$risky_level,
				$risky_limit,
				$risky_offset
			), // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared,WordPress.DB.PreparedSQL.InterpolatedNotPrepared
			ARRAY_A
		);
		// phpcs:enable WordPress.DB.DirectDatabaseQuery
		// phpcs:disable WordPress.DB.DirectDatabaseQuery -- Count SQL is built from plugin-owned identifiers, allowlisted SQL fragments, and prepared values.
		$risky_total = absint(
			$wpdb->get_var(
				$wpdb->prepare(
					"SELECT COUNT(*) FROM `{$wpdb->prefix}srk_spam_monitor_serp_results`
					WHERE LOWER(risk_level) IN ('critical', 'spam', 'suspicious')
						AND ( %s = '' OR domain LIKE %s OR url LIKE %s OR google_title LIKE %s OR issues LIKE %s )
						AND ( %s = '' OR LOWER(risk_level) = %s )",
					$risky_search,
					$like,
					$like,
					$like,
					$like,
					$risky_level,
					$risky_level
				) // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared,WordPress.DB.PreparedSQL.InterpolatedNotPrepared
			)
		);
		// phpcs:enable WordPress.DB.DirectDatabaseQuery

		return array(
			'total_scans'           => absint( $totals['total_scans'] ?? 0 ),
			'last_scan_date'        => $totals['last_scan_date'] ?? '',
			'total_results_checked' => absint( $totals['total_results_checked'] ?? 0 ),
			'critical_results'      => absint( $totals['critical_results'] ?? 0 ),
			'spam_results'          => absint( $totals['spam_results'] ?? 0 ),
			'suspicious_results'    => absint( $totals['suspicious_results'] ?? 0 ),
			'serp_requests_used'    => absint( $totals['serp_requests_used'] ?? 0 ),
			'risky_urls'            => is_array( $risky_urls ) ? $risky_urls : array(),
			'risky_urls_total'      => $risky_total,
			'cleanup'               => self::get_cleanup_dashboard_summary(),
		);
	}

	/**
	 * Get recent SERP scans.
	 *
	 * @param int $limit Limit.
	 * @return array
	 */
	public static function get_recent_serp_scans( $limit = 20, $offset = 0, array $filters = array() ) {
		global $wpdb;

		self::maybe_upgrade_serp_tables();

		$limit = max( 1, min( 100, absint( $limit ) ) );
		$offset = absint( $offset );
		$filter_args = self::get_serp_scan_filter_args( $filters, $wpdb );

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,WordPress.DB.PreparedSQL.NotPrepared -- Prepared at the call site from plugin-owned identifiers, allowlisted SQL fragments, and prepared values.
		return $wpdb->get_results(
			$wpdb->prepare(
				"SELECT * FROM `{$wpdb->prefix}srk_spam_monitor_serp_scans`
				WHERE ( %s = '' OR domain LIKE %s )
					AND ( %d = 0 OR id = %d )
					AND (
						%s = ''
						OR ( %s = 'critical' AND overall_risk_score >= 81 )
						OR ( %s = 'spam' AND overall_risk_score BETWEEN 61 AND 80 )
						OR ( %s = 'suspicious' AND overall_risk_score BETWEEN 31 AND 60 )
						OR ( %s = 'clean' AND overall_risk_score <= 30 )
					)
				ORDER BY created_at DESC LIMIT %d OFFSET %d",
				array_merge( $filter_args, array( $limit, $offset ) )
			), // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
			ARRAY_A
		);
	}

	public static function count_serp_scans( array $filters = array() ) {
		global $wpdb;
		self::maybe_upgrade_serp_tables();
		$filter_args = self::get_serp_scan_filter_args( $filters, $wpdb );
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,WordPress.DB.PreparedSQL.NotPrepared -- Prepared at the call site from plugin-owned identifiers, allowlisted SQL fragments, and prepared values.
		return absint(
			$wpdb->get_var(
				$wpdb->prepare(
					"SELECT COUNT(*) FROM `{$wpdb->prefix}srk_spam_monitor_serp_scans`
					WHERE ( %s = '' OR domain LIKE %s )
						AND ( %d = 0 OR id = %d )
						AND (
							%s = ''
							OR ( %s = 'critical' AND overall_risk_score >= 81 )
							OR ( %s = 'spam' AND overall_risk_score BETWEEN 61 AND 80 )
							OR ( %s = 'suspicious' AND overall_risk_score BETWEEN 31 AND 60 )
							OR ( %s = 'clean' AND overall_risk_score <= 30 )
						)",
					$filter_args
				) // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
			)
		);
	}

	public static function clear_record_dataset( $dataset ) {
		global $wpdb;
		self::maybe_upgrade_serp_tables();
		$dataset = sanitize_key( $dataset );
		if ( 'alerts' === $dataset ) {
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,WordPress.DB.PreparedSQL.NotPrepared -- Intentional cleanup write for plugin-owned alerts table.
			$result = $wpdb->query( "DELETE FROM `{$wpdb->prefix}srk_spam_monitor_alerts`" );
			if ( false !== $result ) {
				self::clear_alert_history_cache();
			}
			return false === $result ? false : absint( $result );
		}
		if ( 'serp' !== $dataset ) {
			return false;
		}
		$wpdb->query( 'START TRANSACTION' );
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,WordPress.DB.PreparedSQL.NotPrepared -- Intentional cleanup write for plugin-owned SERP results table.
		$deleted_results = $wpdb->query( "DELETE FROM `{$wpdb->prefix}srk_spam_monitor_serp_results`" );
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,WordPress.DB.PreparedSQL.NotPrepared -- Intentional cleanup write for plugin-owned SERP scans table.
		$deleted_scans = $wpdb->query( "DELETE FROM `{$wpdb->prefix}srk_spam_monitor_serp_scans`" );
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,WordPress.DB.PreparedSQL.NotPrepared -- Intentional cleanup write for plugin-owned alerts table.
		$deleted_alerts = $wpdb->query( "DELETE FROM `{$wpdb->prefix}srk_spam_monitor_alerts` WHERE scan_id IS NOT NULL AND scan_id > 0" );
		if ( false === $deleted_results || false === $deleted_scans || false === $deleted_alerts ) {
			$wpdb->query( 'ROLLBACK' );
			return false;
		}
		$wpdb->query( 'COMMIT' );
		self::clear_alert_history_cache();
		return absint( $deleted_results ) + absint( $deleted_scans ) + absint( $deleted_alerts );
	}

	public static function get_export_columns( $dataset ) {
		$columns = array(
			'serp_scans' => array( 'id' => 'Scan ID', 'domain' => 'Domain', 'scan_source' => 'Source', 'provider_used' => 'Provider', 'requested_results' => 'Requested Results', 'received_results' => 'Received Results', 'serp_requests_used' => 'Requests Used', 'overall_risk_score' => 'Risk Score', 'clean_count' => 'Clean', 'suspicious_count' => 'Suspicious', 'spam_count' => 'Spam', 'critical_count' => 'Critical', 'status' => 'Status', 'created_at' => 'Created At', 'completed_at' => 'Completed At' ),
			'serp_results' => array( 'id' => 'Result ID', 'scan_id' => 'Scan ID', 'domain' => 'Domain', 'position' => 'Position', 'url' => 'URL', 'google_title' => 'Google Title', 'google_snippet' => 'Google Snippet', 'risk_score' => 'Risk Score', 'risk_level' => 'Risk Level', 'issues' => 'Issues', 'cleanup_status' => 'Cleanup Status', 'http_status' => 'HTTP Status', 'in_sitemap' => 'In Sitemap', 'created_at' => 'Created At' ),
			'alerts' => array( 'id' => 'Alert ID', 'scan_id' => 'Scan ID', 'domain' => 'Domain', 'alert_type' => 'Alert Type', 'url' => 'URL', 'score' => 'Score', 'risk_level' => 'Risk Level', 'url_count' => 'URL Count', 'recipient' => 'Recipient', 'subject' => 'Subject', 'status' => 'Status', 'sent_at' => 'Sent At' ),
		);
		return $columns[ sanitize_key( $dataset ) ] ?? array();
	}

	public static function get_export_records( $dataset, $limit = 500, $offset = 0 ) {
		global $wpdb;
		$dataset = sanitize_key( $dataset );
		$columns = self::get_export_columns( $dataset );
		if ( empty( $columns ) ) {
			return array();
		}
		$limit = max( 1, min( 1000, absint( $limit ) ) );
		$offset = absint( $offset );
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,WordPress.DB.PreparedSQL.NotPrepared -- Paginated export read from allowlisted plugin-owned table and fields.
		if ( 'alerts' === $dataset ) {
			return (array) $wpdb->get_results( $wpdb->prepare( "SELECT `id`, `scan_id`, `domain`, `alert_type`, `url`, `score`, `risk_level`, `url_count`, `recipient`, `subject`, `status`, `sent_at` FROM `{$wpdb->prefix}srk_spam_monitor_alerts` ORDER BY `sent_at` DESC, `id` DESC LIMIT %d OFFSET %d", $limit, $offset ), ARRAY_A );
		}
		if ( 'serp_results' === $dataset ) {
			return (array) $wpdb->get_results( $wpdb->prepare( "SELECT `id`, `scan_id`, `domain`, `position`, `url`, `google_title`, `google_snippet`, `risk_score`, `risk_level`, `issues`, `cleanup_status`, `http_status`, `in_sitemap`, `created_at` FROM `{$wpdb->prefix}srk_spam_monitor_serp_results` ORDER BY `created_at` DESC, `id` DESC LIMIT %d OFFSET %d", $limit, $offset ), ARRAY_A );
		}
		if ( 'serp_scans' === $dataset ) {
			return (array) $wpdb->get_results( $wpdb->prepare( "SELECT `id`, `domain`, `scan_source`, `provider_used`, `requested_results`, `received_results`, `serp_requests_used`, `overall_risk_score`, `clean_count`, `suspicious_count`, `spam_count`, `critical_count`, `status`, `created_at`, `completed_at` FROM `{$wpdb->prefix}srk_spam_monitor_serp_scans` ORDER BY `created_at` DESC, `id` DESC LIMIT %d OFFSET %d", $limit, $offset ), ARRAY_A );
		}

		return array();
	}

	private static function get_serp_scan_filter_args( array $filters, $wpdb ) {
		$domain  = sanitize_text_field( $filters['domain'] ?? '' );
		$risk    = sanitize_key( $filters['risk'] ?? '' );
		$scan_id = absint( preg_replace( '/[^0-9]/', '', (string) ( $filters['scan_id'] ?? '' ) ) );

		if ( ! in_array( $risk, array( 'critical', 'spam', 'suspicious', 'clean' ), true ) ) {
			$risk = '';
		}

		return array(
			$domain,
			'%' . $wpdb->esc_like( $domain ) . '%',
			$scan_id,
			$scan_id,
			$risk,
			$risk,
			$risk,
			$risk,
			$risk,
		);
	}

	/**
	 * Get risky SERP URLs for Search Console cleanup guidance.
	 *
	 * @param int $limit Limit.
	 * @return array
	 */
	public static function get_gsc_cleanup_candidates( $limit = 100, $offset = 0 ) {
		global $wpdb;

		self::maybe_upgrade_serp_tables();

		$limit = max( 1, min( 500, absint( $limit ) ) );
		$offset = absint( $offset );
		$rows = $wpdb->get_results( // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,WordPress.DB.PreparedSQL.NotPrepared -- Real-time paginated cleanup candidate list; identifiers come from get_table_identifier().
			$wpdb->prepare(
				"SELECT results.*, scans.created_at AS scan_created_at
				FROM `{$wpdb->prefix}srk_spam_monitor_serp_results` results
				LEFT JOIN `{$wpdb->prefix}srk_spam_monitor_serp_scans` scans ON scans.id = results.scan_id
				WHERE LOWER(results.risk_level) IN ('critical', 'spam')
				ORDER BY
					CASE LOWER(results.risk_level)
						WHEN 'critical' THEN 3
						WHEN 'spam' THEN 2
						ELSE 0
					END DESC,
					results.risk_score DESC,
					results.last_seen_at DESC
				LIMIT %d OFFSET %d",
				$limit,
				$offset
			),
			ARRAY_A
		);

		return is_array( $rows ) ? $rows : array();
	}

	public static function count_gsc_cleanup_candidates() {
		global $wpdb;
		self::maybe_upgrade_serp_tables();
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,WordPress.DB.PreparedSQL.NotPrepared -- Real-time cleanup candidate count from plugin-owned SERP results table.
		return absint( $wpdb->get_var( "SELECT COUNT(*) FROM `{$wpdb->prefix}srk_spam_monitor_serp_results` WHERE LOWER(risk_level) IN ('critical', 'spam')" ) );
	}

	public static function get_gsc_cleanup_candidate_summary() {
		global $wpdb;
		self::maybe_upgrade_serp_tables();
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,WordPress.DB.PreparedSQL.NotPrepared -- Real-time cleanup candidate summary from plugin-owned SERP results table.
		$row = $wpdb->get_row( "SELECT
			SUM(CASE WHEN LOWER(risk_level) = 'critical' THEN 1 ELSE 0 END) critical,
			SUM(CASE WHEN LOWER(risk_level) = 'spam' THEN 1 ELSE 0 END) spam,
			SUM(CASE WHEN cleanup_status = 'Resolved' THEN 1 ELSE 0 END) resolved,
			SUM(CASE WHEN in_sitemap = 1 THEN 1 ELSE 0 END) sitemap_issues
			FROM `{$wpdb->prefix}srk_spam_monitor_serp_results` WHERE LOWER(risk_level) IN ('critical', 'spam')", ARRAY_A );
		return array_map( 'absint', wp_parse_args( (array) $row, array( 'critical' => 0, 'spam' => 0, 'resolved' => 0, 'sitemap_issues' => 0 ) ) );
	}

	/**
	 * Get cleanup dashboard summary counts.
	 *
	 * @return array
	 */
	public static function get_cleanup_dashboard_summary() {
		global $wpdb;

		self::maybe_upgrade_serp_tables();

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,WordPress.DB.PreparedSQL.NotPrepared -- Real-time cleanup dashboard summary from plugin-owned SERP results table.
		$row = $wpdb->get_row(
			"SELECT
				SUM(CASE WHEN LOWER(risk_level) = 'critical' THEN 1 ELSE 0 END) AS critical_indexed_spam,
				SUM(CASE WHEN LOWER(risk_level) IN ('critical', 'spam') AND COALESCE(cleanup_status, 'Detected') NOT IN ('Resolved', 'False Positive') THEN 1 ELSE 0 END) AS cleanup_queue,
				SUM(CASE WHEN cleanup_status IN ('Cleaned', 'Cleaned On Website', 'Removed From Sitemap', '404 Confirmed', '410 Confirmed') THEN 1 ELSE 0 END) AS spam_urls_removed,
				SUM(CASE WHEN cleanup_status IN ('Monitoring Google', 'Sitemap Resubmitted', 'Waiting For Google') THEN 1 ELSE 0 END) AS waiting_for_google,
				SUM(CASE WHEN cleanup_status = 'Resolved' THEN 1 ELSE 0 END) AS resolved_cases,
				SUM(CASE WHEN in_sitemap = 1 AND COALESCE(cleanup_status, 'Detected') NOT IN ('Resolved', 'False Positive') THEN 1 ELSE 0 END) AS sitemap_issues
			FROM `{$wpdb->prefix}srk_spam_monitor_serp_results`",
			ARRAY_A
		);

		return array(
			'critical_indexed_spam' => absint( $row['critical_indexed_spam'] ?? 0 ),
			'cleanup_queue'         => absint( $row['cleanup_queue'] ?? 0 ),
			'spam_urls_removed'     => absint( $row['spam_urls_removed'] ?? 0 ),
			'waiting_for_google'    => absint( $row['waiting_for_google'] ?? 0 ),
			'resolved_cases'        => absint( $row['resolved_cases'] ?? 0 ),
			'sitemap_issues'        => absint( $row['sitemap_issues'] ?? 0 ),
		);
	}

	/**
	 * Get one SERP result row.
	 *
	 * @param int $result_id Result ID.
	 * @return array|null
	 */
	public static function get_serp_result( $result_id ) {
		global $wpdb;

		self::maybe_upgrade_serp_tables();

		$result_id = absint( $result_id );
		if ( ! $result_id ) {
			return null;
		}

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,WordPress.DB.PreparedSQL.NotPrepared -- Real-time lookup of one plugin-owned SERP result.
		$row = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM `{$wpdb->prefix}srk_spam_monitor_serp_results` WHERE id = %d", $result_id ), ARRAY_A );

		return is_array( $row ) ? $row : null;
	}

	/**
	 * Update cleanup fields for an existing SERP result.
	 *
	 * @param int   $result_id Result ID.
	 * @param array $fields Fields.
	 * @return bool
	 */
	public static function update_cleanup_result( $result_id, array $fields ) {
		global $wpdb;

		self::maybe_upgrade_serp_tables();

		$result_id = absint( $result_id );
		if ( ! $result_id ) {
			return false;
		}

		$allowed = array(
			'cleanup_status'    => '%s',
			'http_status'       => '%d',
			'redirect_status'   => '%d',
			'url_exists'        => '%d',
			'canonical_url'     => '%s',
			'sitemap_url'       => '%s',
			'in_sitemap'        => '%d',
			'sitemap_checked_at'=> '%s',
		);
		$data = array();
		$formats = array();

		foreach ( $allowed as $key => $format ) {
			if ( array_key_exists( $key, $fields ) ) {
				$data[ $key ] = $fields[ $key ];
				$formats[] = $format;
			}
		}

		if ( empty( $data ) ) {
			return false;
		}

		if ( ! array_key_exists( 'cleanup_updated_at', $data ) ) {
			$data['cleanup_updated_at'] = current_time( 'mysql' );
			$formats[] = '%s';
		}

		$table = $wpdb->prefix . 'srk_spam_monitor_serp_results';
		return false !== $wpdb->update( $table, $data, array( 'id' => $result_id ), $formats, array( '%d' ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- Intentional update to plugin-owned SERP result table; write operations are not cached.
	}

	/**
	 * Get a stored SERP scan and result rows.
	 *
	 * @param int $scan_id Scan ID.
	 * @return array|null
	 */
	public static function get_serp_scan_results( $scan_id, $limit = 0, $offset = 0 ) {
		global $wpdb;

		$scan_id = absint( $scan_id );
		if ( ! $scan_id ) {
			return null;
		}

		self::maybe_upgrade_serp_tables();

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery,WordPress.DB.PreparedSQL.NotPrepared -- Real-time lookup of one plugin-owned SERP scan.
		$scan = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM `{$wpdb->prefix}srk_spam_monitor_serp_scans` WHERE id = %d", $scan_id ), ARRAY_A );
		if ( ! $scan ) {
			return null;
		}

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery,WordPress.DB.PreparedSQL.NotPrepared -- Real-time count of plugin-owned SERP results for one scan.
		$total = absint( $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM `{$wpdb->prefix}srk_spam_monitor_serp_results` WHERE scan_id = %d", $scan_id ) ) );
		if ( $limit ) {
			$limit   = max( 1, min( 100, absint( $limit ) ) );
			$offset  = absint( $offset );
			$results = $wpdb->get_results( $wpdb->prepare( "SELECT * FROM `{$wpdb->prefix}srk_spam_monitor_serp_results` WHERE scan_id = %d ORDER BY position ASC, id ASC LIMIT %d OFFSET %d", $scan_id, $limit, $offset ), ARRAY_A ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery,WordPress.DB.PreparedSQL.NotPrepared -- Real-time paginated SERP results for one scan.
		} else {
			$results = $wpdb->get_results( $wpdb->prepare( "SELECT * FROM `{$wpdb->prefix}srk_spam_monitor_serp_results` WHERE scan_id = %d ORDER BY position ASC, id ASC", $scan_id ), ARRAY_A ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery,WordPress.DB.PreparedSQL.NotPrepared -- Real-time full SERP results export/read for one scan.
		}

		return array( 'scan' => $scan, 'results' => $results, 'total' => $total );
	}

	/**
	 * Count SERP risk labels.
	 *
	 * @param array $results Results.
	 * @return array
	 */
	private static function count_serp_risk_levels( array $results ) {
		$counts = array( 'clean' => 0, 'changed' => 0, 'suspicious' => 0, 'spam' => 0, 'critical' => 0 );
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
	 * Sanitize SERP domain for storage.
	 *
	 * @param string $domain Domain.
	 * @return string
	 */
	private static function sanitize_serp_domain( $domain ) {
		$domain = strtolower( trim( (string) $domain ) );
		$domain = preg_replace( '#^https?://#i', '', $domain );
		$domain = preg_replace( '#/.*$#', '', $domain );
		$domain = preg_replace( '#:\d+$#', '', $domain );
		return sanitize_text_field( $domain );
	}
}
