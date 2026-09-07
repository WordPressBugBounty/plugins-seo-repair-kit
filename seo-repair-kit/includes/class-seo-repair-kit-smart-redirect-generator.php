<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Smart Redirect Generator.
 *
 * Creates redirect records for broken internal singular URLs and tracks them
 * in a dedicated smart redirects table.
 *
 * @since 2.1.7
 */
class SeoRepairKit_SmartRedirect_Generator {

	/**
	 * Quote a local SQL identifier.
	 *
	 * @param string $identifier Identifier.
	 * @return string
	 */
	private static function quote_identifier( $identifier ) {
		return '`' . str_replace( '`', '``', $identifier ) . '`';
	}

	/**
	 * Get a plugin-owned table name.
	 *
	 * @param string $key Table key.
	 * @return string
	 */
	private static function get_table_name( $key ) {
		global $wpdb;

		$tables = array(
			'link_scan_runs'  => $wpdb->prefix . 'srk_link_scan_runs',
			'smart_redirects' => $wpdb->prefix . 'srkit_smart_redirects',
			'redirections'    => $wpdb->prefix . 'srkit_redirection_table',
		);

		return isset( $tables[ $key ] ) ? $tables[ $key ] : '';
	}

	/**
	 * Get an escaped SQL table identifier.
	 *
	 * @param string $key Table key.
	 * @return string
	 */
	private static function get_table_identifier( $key ) {
		$table_name = self::get_table_name( $key );

		return '' === $table_name ? '' : self::quote_identifier( $table_name );
	}

	/**
	 * Backfill smart redirects from the latest scan snapshot and recent run history.
	 *
	 * This helps when Smart Redirect is enabled after scans already captured broken
	 * internal links, or when older runs need to be reprocessed safely.
	 *
	 * @param int $history_limit Number of recent scan runs to inspect.
	 * @return int
	 */
	public static function backfill_from_scan_history( $history_limit = 7 ) {
		global $wpdb;

		if ( ! class_exists( 'SeoRepairKit_SmartRedirect_Helper' ) ) {
			return 0;
		}

		if ( ! SeoRepairKit_SmartRedirect_Helper::is_feature_enabled() ) {
			return 0;
		}

		$created_count = 0;
		$records_sets  = array();
		$snapshot      = get_option( 'srk_last_links_snapshot', array() );

		if ( ! empty( $snapshot['records'] ) && is_array( $snapshot['records'] ) ) {
			$records_sets[] = $snapshot['records'];
		}

		$history_limit = max( 1, min( 50, absint( $history_limit ) ) );
		$runs_table = self::get_table_name( 'link_scan_runs' );
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- Defensive recovery check before reading plugin-owned scan history.
		$table_exists = ( $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $runs_table ) ) === $runs_table );

		if ( $table_exists ) {
			// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- Bounded Smart Redirect backfill read from plugin-owned scan history.
			$rows = (array) $wpdb->get_results( // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Bounded Smart Redirect backfill read from plugin-owned scan history.
				$wpdb->prepare(
					"SELECT records_json FROM `{$wpdb->prefix}srk_link_scan_runs` WHERE records_json IS NOT NULL AND records_json != '' ORDER BY id DESC LIMIT %d",
					$history_limit
				),
				ARRAY_A
			);
			// phpcs:enable WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching

			foreach ( $rows as $row ) {
				$records = json_decode( isset( $row['records_json'] ) ? $row['records_json'] : '', true );
				if ( is_array( $records ) && ! empty( $records ) ) {
					$records_sets[] = $records;
				}
			}
		}

		foreach ( $records_sets as $records ) {
			$created_count += self::generate_from_scan_records( $records, false );
		}

		if ( $created_count > 0 ) {
			self::maybe_refresh_server_rules();
		}

		return $created_count;
	}

	/**
	 * Generate smart redirects from scan records.
	 *
	 * @param array $records Scan records.
	 * @param bool  $refresh_server_rules Whether to refresh server rules.
	 * @return int
	 */
	public static function generate_from_scan_records( $records, $refresh_server_rules = true ) {
		$created_count = 0;
		$stats         = array(
			'seen'           => 0,
			'skipped_scope'  => 0,
			'skipped_code'   => 0,
			'skipped_url'    => 0,
			'skipped_type'   => 0,
			'skipped_create' => 0,
			'created'        => 0,
		);

		if ( ! class_exists( 'SeoRepairKit_SmartRedirect_Helper' ) ) {
			return 0;
		}

		if ( ! SeoRepairKit_SmartRedirect_Helper::is_feature_enabled() ) {
			return 0;
		}

		$enabled_post_types = SeoRepairKit_SmartRedirect_Helper::get_enabled_post_types();

		if ( empty( $enabled_post_types ) ) {
			return 0;
		}

		foreach ( (array) $records as $record ) {
			$stats['seen']++;
			if ( empty( $record['is_broken'] ) || empty( $record['link'] ) ) {
				$stats['skipped_url']++;
				continue;
			}

			if ( isset( $record['scope'] ) && 'internal' !== $record['scope'] ) {
				$stats['skipped_scope']++;
				continue;
			}

			$http_code = isset( $record['http_code'] ) ? (int) $record['http_code'] : 0;

			// Allow HTTP code 0 (request error/timeouts) to still generate Smart Redirects,
			// but only when the URL can be confidently mapped to an enabled post type
			// and matches a singular-style URL. This avoids "Reset Type stops working"
			// due to cached 200s or intermittent request failures.
			if ( 0 !== $http_code && 404 !== $http_code && $http_code < 400 ) {
				$stats['skipped_code']++;
				continue;
			}

			$source_url = SeoRepairKit_SmartRedirect_Helper::normalize_url( $record['link'] );

			if ( '' === $source_url ) {
				$stats['skipped_url']++;
				continue;
			}

			$post_type = SeoRepairKit_SmartRedirect_Helper::detect_post_type_from_url( $source_url );

			if ( ! $post_type || ! in_array( $post_type, $enabled_post_types, true ) ) {
				$stats['skipped_type']++;
				continue;
			}

			$created = self::create_redirect_for_url( $source_url, $post_type, false );

			if ( ! empty( $created ) && ! empty( $created['srk_created_new'] ) ) {
				$created_count++;
				$stats['created']++;
			} elseif ( false === $created ) {
				$stats['skipped_create']++;
			}
		}

		if ( $refresh_server_rules && $created_count > 0 ) {
			self::maybe_refresh_server_rules();
		}

		// Store a small debug snapshot to help diagnose "scan shows broken but no Smart Redirects".
		update_option(
			'srk_smart_redirect_last_generation_summary',
			array(
				'time'    => current_time( 'mysql' ),
				'created' => (int) $created_count,
				'stats'   => $stats,
			),
			false
		);

		return $created_count;
	}

	/**
	 * Create one smart redirect for a given broken URL.
	 *
	 * Used by both scanner automation and frontend template_redirect runtime.
	 *
	 * @param string $source_url           Broken source URL.
	 * @param string $post_type            Optional post type.
	 * @param bool   $refresh_server_rules Whether to refresh server rules after creation.
	 * @return array|false
	 */
	public static function create_redirect_for_url( $source_url, $post_type = '', $refresh_server_rules = true ) {
		global $wpdb;

		if ( ! class_exists( 'SeoRepairKit_SmartRedirect_Helper' ) ) {
			return false;
		}

		$source_url = SeoRepairKit_SmartRedirect_Helper::normalize_url( $source_url );

		if ( '' === $source_url ) {
			return false;
		}

		if ( '' === $post_type ) {
			$post_type = SeoRepairKit_SmartRedirect_Helper::detect_post_type_from_url( $source_url );
		}

		$post_type = sanitize_key( $post_type );

		if ( ! $post_type || ! SeoRepairKit_SmartRedirect_Helper::is_post_type_enabled( $post_type ) ) {
			return false;
		}

		if ( ! SeoRepairKit_SmartRedirect_Helper::is_singular_style_url_for_post_type( $source_url, $post_type ) ) {
			return false;
		}

		$target_url = SeoRepairKit_SmartRedirect_Helper::get_post_type_archive_url( $post_type );

		if ( '' === $target_url ) {
			return false;
		}

		$target_url = SeoRepairKit_SmartRedirect_Helper::normalize_url( $target_url );

		if ( '' === $target_url || $target_url === $source_url ) {
			return false;
		}

		$smart_table     = self::get_table_name( 'smart_redirects' );
		$redir_table     = self::get_table_name( 'redirections' );
		$smart_table_sql = self::get_table_identifier( 'smart_redirects' );
		$redir_table_sql = self::get_table_identifier( 'redirections' );

		// phpcs:disable PluginCheck.Security.DirectDB.UnescapedDBParameter -- Smart Redirect identifiers are plugin-owned values from get_table_identifier(); URL/post-type values are prepared.
		$smart_exists = ( $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $smart_table ) ) === $smart_table ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- Defensive recovery check before creating Smart Redirect records in plugin-owned tables.
		$redir_exists = ( $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $redir_table ) ) === $redir_table ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- Defensive recovery check before creating Redirect Manager records in plugin-owned tables.

		if ( ! $smart_exists || ! $redir_exists ) {
			return false;
		}

		$existing_smart = $wpdb->get_row( // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- Real-time duplicate check before creating/updating a Smart Redirect.
			$wpdb->prepare(
				"SELECT * FROM {$smart_table_sql} WHERE source_url = %s AND post_type = %s LIMIT 1",
				$source_url,
				$post_type
			),
			ARRAY_A
		);

		if ( ! empty( $existing_smart ) ) {
			$wpdb->update( // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- Intentional update to plugin-owned Smart Redirect table; write operations are not cached.
				$smart_table,
				array(
					'last_detected_at' => current_time( 'mysql' ),
					'updated_at'       => current_time( 'mysql' ),
				),
				array( 'id' => (int) $existing_smart['id'] ),
				array( '%s', '%s' ),
				array( '%d' )
			);

			$existing_smart['srk_created_new'] = false;

			return $existing_smart;
		}

		$existing_redirect = $wpdb->get_row( // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- Real-time duplicate check against plugin-owned Redirect Manager table.
			$wpdb->prepare(
				"SELECT * FROM {$redir_table_sql} WHERE source_url = %s LIMIT 1",
				$source_url
			),
			ARRAY_A
		);

		if ( ! empty( $existing_redirect ) ) {
			$smart_inserted = $wpdb->insert( // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- Intentional insert into plugin-owned Smart Redirect table; write operations are not cached.
				$smart_table,
				array(
					'redirection_id'   => (int) $existing_redirect['id'],
					'post_type'        => $post_type,
					'source_url'       => $source_url,
					'target_url'       => ! empty( $existing_redirect['target_url'] ) ? $existing_redirect['target_url'] : $target_url,
					'status'           => ! empty( $existing_redirect['status'] ) ? $existing_redirect['status'] : 'active',
					'last_detected_at' => current_time( 'mysql' ),
					'created_at'       => current_time( 'mysql' ),
					'updated_at'       => current_time( 'mysql' ),
				),
				array( '%d', '%s', '%s', '%s', '%s', '%s', '%s', '%s' )
			);

			if ( false === $smart_inserted ) {
				return false;
			}

			$row = $wpdb->get_row( // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- Immediate readback of the row just inserted for existing response shape.
				$wpdb->prepare(
					"SELECT * FROM {$smart_table_sql} WHERE id = %d",
					(int) $wpdb->insert_id
				),
				ARRAY_A
			);

			if ( ! empty( $row ) ) {
				$row['srk_created_new'] = true;
			}

			return $row;
		}

		$redirect_inserted = $wpdb->insert( // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- Intentional insert into plugin-owned Redirect Manager table; write operations are not cached.
			$redir_table,
			array(
				'source_url'    => $source_url,
				'target_url'    => $target_url,
				'redirect_type' => 301,
				'status'        => 'active',
				'is_regex'      => 0,
				'position'      => 0,
				'hits'          => 0,
				'created_at'    => current_time( 'mysql' ),
				'updated_at'    => current_time( 'mysql' ),
			),
			array( '%s', '%s', '%d', '%s', '%d', '%d', '%d', '%s', '%s' )
		);

		if ( false === $redirect_inserted || empty( $wpdb->insert_id ) ) {
			return false;
		}

		$redirect_id = (int) $wpdb->insert_id;

		$smart_inserted = $wpdb->insert( // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- Intentional insert into plugin-owned Smart Redirect table; write operations are not cached.
			$smart_table,
			array(
				'redirection_id'   => $redirect_id,
				'post_type'        => $post_type,
				'source_url'       => $source_url,
				'target_url'       => $target_url,
				'status'           => 'active',
				'last_detected_at' => current_time( 'mysql' ),
				'created_at'       => current_time( 'mysql' ),
				'updated_at'       => current_time( 'mysql' ),
			),
			array( '%d', '%s', '%s', '%s', '%s', '%s', '%s', '%s' )
		);

		if ( false === $smart_inserted || empty( $wpdb->insert_id ) ) {
			return false;
		}

		$smart_id = (int) $wpdb->insert_id;

		if ( $refresh_server_rules ) {
			self::maybe_refresh_server_rules();
		}

		$row = $wpdb->get_row( // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- Immediate readback of the row just inserted for existing response shape.
			$wpdb->prepare(
				"SELECT * FROM {$smart_table_sql} WHERE id = %d",
				$smart_id
			),
			ARRAY_A
		);

		if ( ! empty( $row ) ) {
			$row['srk_created_new'] = true;
		}

		// phpcs:enable PluginCheck.Security.DirectDB.UnescapedDBParameter
		return $row;
	}

	/**
	 * Refresh server rules when available and enabled.
	 *
	 * @return void
	 */
	private static function maybe_refresh_server_rules() {
		if ( ! get_option( 'srk_enable_htaccess_sync', 1 ) ) {
			return;
		}

		if ( ! class_exists( 'SeoRepairKit_Redirection' ) ) {
			$redirection_file = plugin_dir_path( __FILE__ ) . '../admin/class-seo-repair-kit-redirection.php';

			if ( file_exists( $redirection_file ) ) {
				require_once $redirection_file;
			}
		}

		if ( ! class_exists( 'SeoRepairKit_Redirection' ) ) {
			return;
		}

		$redirection = new SeoRepairKit_Redirection();

		if ( ! method_exists( $redirection, 'refresh_server_rules' ) ) {
			return;
		}

		$reflection = new ReflectionClass( $redirection );
		$method     = $reflection->getMethod( 'refresh_server_rules' );

		$method->setAccessible( true );
		$method->invoke( $redirection, true );
	}

	/**
	 * Public wrapper to refresh server rules after CRUD operations.
	 *
	 * Smart Redirect actions (toggle/delete/reset) can change the active redirects
	 * set, so we need to re-sync server rules (e.g. .htaccess) to avoid stale
	 * redirects still firing after deletion.
	 *
	 * @since 2.1.7
	 * @return void
	 */
	public static function refresh_server_rules() {
		self::maybe_refresh_server_rules();
	}
}
