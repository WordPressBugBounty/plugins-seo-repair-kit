<?php
/**
 * Internal Linking database service.
 *
 * @package SEO_Repair_Kit
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}
// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery
// phpcs:disable WordPress.DB.DirectDatabaseQuery.NoCaching

require_once __DIR__ . '/class-srk-internal-linking-db-operations.php';

/**
 * Handles all database operations for SEO Repair Kit Internal Linking.
 *
 * This class is intentionally responsible only for database schema and data access.
 * It does not generate keywords, scan content, apply links, or render admin UI.
 *
 * Data flow supported by this layer:
 * 1. Content Index stores clean post/page content and link counts.
 * 2. Keywords stores the target keyword pool for each indexed post.
 * 3. Links stores discovered or inserted links.
 * 5. Opportunities stores generated link suggestions.
 * 6. URL Changes stores safe URL replacement history and rollback data.
 * 7. Scan Runs stores background/batch process progress.
 *
 * @since 2.1.12
 */
class SRK_Internal_Linking_DB {

	use SRK_Internal_Linking_DB_Operations;

	/**
	 * Request-local cache for read-only admin summary data.
	 *
	 * This is intentionally not a persistent WordPress object cache.
	 * It exists only for the current PHP request and prevents the
	 * Dashboard and individual Internal Linking tabs from executing
	 * identical summary queries more than once.
	 *
	 * @var array<string,mixed>
	 */
	private static $request_cache = array();

	/**
	 * Get request-local cached data.
	 *
	 * AJAX requests intentionally bypass this cache so actions that modify
	 * Internal Linking data always return fresh summary values.
	 *
	 * @param string $key Cache key.
	 * @return mixed|null
	 */
	private static function get_request_cache( $key ) {
		if (
			! is_admin() ||
			(
				function_exists( 'wp_doing_ajax' ) &&
				wp_doing_ajax()
			)
		) {
			return null;
		}

		$key = sanitize_key( $key );

		return array_key_exists(
			$key,
			self::$request_cache
		)
			? self::$request_cache[ $key ]
			: null;
	}

	/**
	 * Store request-local cached data.
	 *
	 * @param string $key   Cache key.
	 * @param mixed  $value Cached value.
	 * @return mixed
	 */
	private static function set_request_cache( $key, $value ) {
		if (
			is_admin() &&
			!(
				function_exists( 'wp_doing_ajax' ) &&
				wp_doing_ajax()
			)
		) {
			self::$request_cache[ sanitize_key( $key ) ] = $value;
		}

		return $value;
	}

	/**
	 * Current Internal Linking database schema version.
	 */
	const DB_VERSION = '2.1.12';

	/**
	 * Option key used to store installed DB version.
	 *
	 * @var string
	 */
	const OPTION_DB_VERSION = 'srk_internal_linking_db_version';

	/**
	 * Option key used as an atomic migration lock.
	 *
	 * @var string
	 */
	const OPTION_DB_MIGRATION_LOCK = 'srk_internal_linking_db_migration_lock';

	/**
	 * Migration lock timeout in seconds.
	 *
	 * @var int
	 */
	const MIGRATION_LOCK_TTL = 300;

	/**
	 * Maximum rows touched by one data-migration batch.
	 *
	 * @var int
	 */
	const DATA_MIGRATION_BATCH_SIZE = 1000;

	/**
	 * Request-local schema readiness cache.
	 *
	 * @var bool|null
	 */
	private static $schema_ready = null;

	/**
	 * Install or upgrade Internal Linking database tables.
	 *
	 * @return true|WP_Error
	 */
	public static function install() {
		return self::run_migrations();
	}

	/**
	 * Run database upgrade when stored schema version is older.
	 *
	 * @return true|WP_Error
	 */
	public static function maybe_upgrade() {
		$installed_version = get_option( self::OPTION_DB_VERSION, '' );

		if ( version_compare( (string) $installed_version, self::DB_VERSION, '>=' ) ) {
			return true;
		}

		return self::run_migrations();
	}

	/**
	 * Run required schema and data migrations under an atomic lock.
	 *
	 * @return true|WP_Error
	 */
	private static function run_migrations() {
		$installed_version = get_option( self::OPTION_DB_VERSION, '' );

		if ( version_compare( (string) $installed_version, self::DB_VERSION, '>=' ) ) {
			return true;
		}

		if ( ! self::acquire_migration_lock() ) {
			return new WP_Error(
				'srk_il_migration_locked',
				__( 'Internal Linking database migration is already running.', 'seo-repair-kit' )
			);
		}

		try {
			$installed_version = get_option( self::OPTION_DB_VERSION, '' );

			if ( version_compare( (string) $installed_version, self::DB_VERSION, '>=' ) ) {
				self::release_migration_lock();
				return true;
			}

			self::$schema_ready = null;

			require_once ABSPATH . 'wp-admin/includes/upgrade.php';

			foreach ( self::get_schema( self::get_charset_collate() ) as $sql ) {
				dbDelta( $sql );
			}

			$migration_result = self::run_data_migrations( $installed_version );

			if ( is_wp_error( $migration_result ) ) {
				self::log_migration_failure( $migration_result->get_error_message() );
				self::release_migration_lock();
				return $migration_result;
			}

			$verification = self::verify_schema();

			if ( is_wp_error( $verification ) ) {
				self::log_migration_failure( $verification->get_error_message() );
				self::release_migration_lock();
				return $verification;
			}

			update_option( self::OPTION_DB_VERSION, self::DB_VERSION );
			update_option( 'srk_il_activation_scan_pending', 1, false );
			self::$schema_ready = true;
			self::release_migration_lock();

			return true;
		} catch ( Throwable $throwable ) {
			self::log_migration_failure( $throwable->getMessage() );
			self::release_migration_lock();

			return new WP_Error(
				'srk_il_migration_failed',
				__( 'Internal Linking database migration failed.', 'seo-repair-kit' )
			);
		}
	}

	/**
	 * Acquire the migration lock using atomic add_option().
	 *
	 * @return bool
	 */
	private static function acquire_migration_lock() {
		$now  = time();
		$lock = get_option( self::OPTION_DB_MIGRATION_LOCK, false );

		if ( false !== $lock ) {
			$locked_at = absint( $lock );

			if ( $locked_at && ( $now - $locked_at ) <= self::MIGRATION_LOCK_TTL ) {
				return false;
			}

			delete_option( self::OPTION_DB_MIGRATION_LOCK );
		}

		return add_option( self::OPTION_DB_MIGRATION_LOCK, (string) $now, '', 'no' );
	}

	/**
	 * Release the migration lock.
	 *
	 * @return void
	 */
	private static function release_migration_lock() {
		delete_option( self::OPTION_DB_MIGRATION_LOCK );
	}

	/**
	 * Log migration failures without exposing SQL to administrators.
	 *
	 * @param string $message Failure message.
	 * @return void
	 */
	private static function log_migration_failure( $message ) {
		if ( defined( 'WP_DEBUG' ) && WP_DEBUG ) {
			error_log( 'SEO Repair Kit Internal Linking migration failed: ' . sanitize_text_field( $message ) ); // phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log
		}
	}

	/**
	 * Remove all Internal Linking database tables and related options.
	 *
	 * Use only from plugin uninstall flow.
	 *
	 * @return void
	 */
	public static function uninstall() {
		global $wpdb;

		foreach ( array_keys( self::tables() ) as $table_key ) {
			$table_name = self::get_table_name( $table_key );

			if ( empty( $table_name ) ) {
				continue;
			}

			// Table names are generated internally from a fixed whitelist.
			$wpdb->query( 'DROP TABLE IF EXISTS `' . esc_sql( $table_name ) . '`' ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
		}

		delete_option( self::OPTION_DB_VERSION );
		delete_option( self::OPTION_DB_MIGRATION_LOCK );
		delete_option( 'srk_il_activation_scan_pending' );
		delete_option( 'srk_il_activation_scan_id' );
		delete_option( 'srk_il_activation_scan_page' );
	}

	/**
	 * Return Internal Linking logical table map.
	 *
	 * @return array<string,string> Table key => unprefixed table name.
	 */
	private static function tables() {
		return array(
			'content_index' => 'srk_il_content_index',
			'keywords'      => 'srk_il_keywords',
			'links'         => 'srk_il_links',
			'opportunities' => 'srk_il_opportunities',
			'auto_rules'    => 'srk_il_auto_rules',
			'url_changes'   => 'srk_il_url_changes',
			'scan_runs'       => 'srk_il_scan_runs',
			'embeddings'      => 'srk_il_embeddings',
		);
	}

	/**
	 * Get a prefixed table name from a known table key.
	 *
	 * @param string $table_key Logical table key.
	 * @return string Prefixed table name or empty string when invalid.
	 */
	public static function get_table_name( $table_key ) {
		global $wpdb;

		$tables    = self::tables();
		$table_key = sanitize_key( $table_key );

		return isset( $tables[ $table_key ] ) ? $wpdb->prefix . $tables[ $table_key ] : '';
	}

	/**
	 * Get WordPress charset/collation string.
	 *
	 * @return string
	 */
	private static function get_charset_collate() {
		global $wpdb;

		return $wpdb->get_charset_collate();
	}

	/**
	 * Build SQL schema used by dbDelta().
	 *
	 * @param string $charset_collate WordPress charset/collation.
	 * @return string[] SQL CREATE TABLE statements.
	 */
	private static function get_schema( $charset_collate ) {
		$content_index = self::get_table_name( 'content_index' );
		$keywords      = self::get_table_name( 'keywords' );
		$links         = self::get_table_name( 'links' );
		$opportunities = self::get_table_name( 'opportunities' );
		$auto_rules    = self::get_table_name( 'auto_rules' );
		$url_changes   = self::get_table_name( 'url_changes' );
		$scan_runs       = self::get_table_name( 'scan_runs' );
		$embeddings      = self::get_table_name( 'embeddings' );

		return array(
			"CREATE TABLE {$content_index} (
				id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
				post_id BIGINT UNSIGNED NOT NULL,
				post_type VARCHAR(50) NOT NULL,
				post_status VARCHAR(30) NOT NULL,
				post_title TEXT NULL,
				post_url TEXT NULL,
				content_hash VARCHAR(64) NULL,
				plain_content LONGTEXT NULL,
				word_count INT UNSIGNED NOT NULL DEFAULT 0,
				taxonomy_json LONGTEXT NULL,
				internal_outbound_count INT UNSIGNED NOT NULL DEFAULT 0,
				internal_inbound_count INT UNSIGNED NOT NULL DEFAULT 0,
				external_outbound_count INT UNSIGNED NOT NULL DEFAULT 0,
				orphan_status VARCHAR(30) NOT NULL DEFAULT 'unknown',
				last_indexed DATETIME NULL,
				created_at DATETIME NOT NULL,
				updated_at DATETIME NOT NULL,
				PRIMARY KEY  (id),
				UNIQUE KEY post_id (post_id),
				KEY post_type (post_type),
				KEY post_status (post_status),
				KEY orphan_status (orphan_status),
				KEY last_indexed (last_indexed)
			) {$charset_collate};",

			"CREATE TABLE {$keywords} (
				id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
				post_id BIGINT UNSIGNED NOT NULL,
				keyword VARCHAR(255) NOT NULL,
				keyword_hash VARCHAR(64) NOT NULL,
				normalized_keyword VARCHAR(255) NOT NULL,
				meaningful_words_json LONGTEXT NULL,
				source VARCHAR(30) NOT NULL,
				keyword_type VARCHAR(30) NOT NULL DEFAULT 'auto',
				is_active TINYINT(1) NOT NULL DEFAULT 1,
				quality_score INT UNSIGNED NOT NULL DEFAULT 0,
				clicks INT UNSIGNED NOT NULL DEFAULT 0,
				impressions INT UNSIGNED NOT NULL DEFAULT 0,
				ctr DECIMAL(8,4) NOT NULL DEFAULT 0,
				avg_position DECIMAL(8,2) NOT NULL DEFAULT 0,
				created_at DATETIME NOT NULL,
				updated_at DATETIME NOT NULL,
				PRIMARY KEY  (id),
				UNIQUE KEY post_keyword_source (post_id, keyword_hash, source),
				KEY post_id (post_id),
				KEY keyword_hash (keyword_hash),
				KEY source (source),
				KEY is_active (is_active),
				KEY quality_score (quality_score)
			) {$charset_collate};",

			"CREATE TABLE {$links} (
				id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
				source_post_id BIGINT UNSIGNED NOT NULL,
				target_post_id BIGINT UNSIGNED NULL,
				target_url TEXT NOT NULL,
				anchor_text VARCHAR(255) NULL,
				is_internal TINYINT(1) NOT NULL DEFAULT 1,
				PRIMARY KEY  (id),
				KEY source_post_id (source_post_id),
				KEY target_post_id (target_post_id),
				KEY is_internal (is_internal)
			) {$charset_collate};",

			"CREATE TABLE {$opportunities} (
				id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
				scan_run_id BIGINT UNSIGNED NULL,
				source_post_id BIGINT UNSIGNED NOT NULL,
				target_post_id BIGINT UNSIGNED NOT NULL,
				anchor_text VARCHAR(255) NOT NULL,
				sentence TEXT NULL,
				reason VARCHAR(255) NULL,
				score INT UNSIGNED NOT NULL DEFAULT 0,
				final_score INT UNSIGNED NOT NULL DEFAULT 0,
				selected_type VARCHAR(20) NOT NULL DEFAULT 'rule',
				rule_score INT UNSIGNED NULL,
				ai_score INT UNSIGNED NULL,
				ai_similarity DECIMAL(8,4) NULL,
				status VARCHAR(30) NOT NULL DEFAULT 'pending',
				inserted_at DATETIME NULL,
				created_at DATETIME NOT NULL,
				updated_at DATETIME NOT NULL,
				PRIMARY KEY  (id),
				KEY scan_run_id (scan_run_id),
				KEY source_post_id (source_post_id),
				KEY target_post_id (target_post_id),
				KEY source_target (source_post_id, target_post_id),
				KEY final_score (final_score),
				KEY selected_type (selected_type),
				KEY status (status),
				KEY created_at (created_at)
			) {$charset_collate};",

			"CREATE TABLE {$auto_rules} (
				id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
				keyword VARCHAR(255) NOT NULL,
				keyword_hash VARCHAR(64) NOT NULL,
				target_post_id BIGINT UNSIGNED NULL,
				target_url TEXT NOT NULL,
				status VARCHAR(30) NOT NULL DEFAULT 'active',
				priority INT UNSIGNED NOT NULL DEFAULT 10,
				selection_mode VARCHAR(30) NOT NULL DEFAULT 'manual',
				manual_review TINYINT(1) NOT NULL DEFAULT 1,
				auto_apply TINYINT(1) NOT NULL DEFAULT 0,
				case_sensitive TINYINT(1) NOT NULL DEFAULT 0,
				only_once_per_post TINYINT(1) NOT NULL DEFAULT 1,
				max_links_per_post INT UNSIGNED NOT NULL DEFAULT 1,
				max_links_per_keyword INT UNSIGNED NOT NULL DEFAULT 1,
				allow_duplicate_target TINYINT(1) NOT NULL DEFAULT 0,
				add_if_existing_link TINYINT(1) NOT NULL DEFAULT 0,
				require_target_published TINYINT(1) NOT NULL DEFAULT 1,
				override_one_link_per_sentence TINYINT(1) NOT NULL DEFAULT 0,
				prioritize_long_tail TINYINT(1) NOT NULL DEFAULT 1,
				apply_after_date DATE NULL,
				post_types_json LONGTEXT NULL,
				taxonomies_json LONGTEXT NULL,
				categories_json LONGTEXT NULL,
				tags_json LONGTEXT NULL,
				excluded_posts_json LONGTEXT NULL,
				excluded_categories_json LONGTEXT NULL,
				excluded_tags_json LONGTEXT NULL,
				matched_posts_json LONGTEXT NULL,
				applied_links_json LONGTEXT NULL,
				scan_log_json LONGTEXT NULL,
				links_created INT UNSIGNED NOT NULL DEFAULT 0,
				removed_count INT UNSIGNED NOT NULL DEFAULT 0,
				failed_count INT UNSIGNED NOT NULL DEFAULT 0,
				last_scan_at DATETIME NULL,
				last_scan_duration DECIMAL(10,4) NOT NULL DEFAULT 0,
				created_at DATETIME NOT NULL,
				updated_at DATETIME NOT NULL,
				PRIMARY KEY  (id),
				KEY keyword_hash (keyword_hash),
				KEY target_post_id (target_post_id),
				KEY status (status),
				KEY priority (priority),
				KEY last_scan_at (last_scan_at),
				KEY created_at (created_at)
			) {$charset_collate};",

			"CREATE TABLE {$url_changes} (
				id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
				old_url TEXT NOT NULL,
				new_url TEXT NOT NULL,
				affected_posts INT UNSIGNED NOT NULL DEFAULT 0,
				changed_links INT UNSIGNED NOT NULL DEFAULT 0,
				failed_count INT UNSIGNED NOT NULL DEFAULT 0,
				status VARCHAR(30) NOT NULL DEFAULT 'pending',
				rollback_json LONGTEXT NULL,
				created_at DATETIME NOT NULL,
				PRIMARY KEY  (id),
				KEY created_at (created_at)
			) {$charset_collate};",

			"CREATE TABLE {$scan_runs} (
				id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
				scan_type VARCHAR(50) NOT NULL,
				status VARCHAR(30) NOT NULL DEFAULT 'pending',
				total_items INT UNSIGNED NOT NULL DEFAULT 0,
				processed_items INT UNSIGNED NOT NULL DEFAULT 0,
				success_items INT UNSIGNED NOT NULL DEFAULT 0,
				failed_items INT UNSIGNED NOT NULL DEFAULT 0,
				current_batch INT UNSIGNED NOT NULL DEFAULT 0,
				message TEXT NULL,
				started_at DATETIME NULL,
				completed_at DATETIME NULL,
				created_at DATETIME NOT NULL,
				updated_at DATETIME NOT NULL,
				PRIMARY KEY  (id),
				KEY scan_type (scan_type),
				KEY status (status)
			) {$charset_collate};",

			"CREATE TABLE {$embeddings} (
				id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
				post_id BIGINT UNSIGNED NOT NULL,
				embedding_json LONGTEXT NOT NULL,
				model VARCHAR(100) NOT NULL DEFAULT 'openai/text-embedding-3-small',
				content_hash VARCHAR(64) NULL,
				status VARCHAR(30) NOT NULL DEFAULT 'pending',
				created_at DATETIME NOT NULL,
				updated_at DATETIME NOT NULL,
				PRIMARY KEY  (id),
				UNIQUE KEY post_id (post_id),
				KEY status (status),
				KEY content_hash (content_hash)
			) {$charset_collate};",
		);
	}

	/**
	 * Check whether the Internal Linking database is usable.
	 *
	 * This is read-only and never attempts to create or repair tables.
	 *
	 * @return bool
	 */
	public static function is_schema_ready() {
		if ( null !== self::$schema_ready ) {
			return self::$schema_ready;
		}

		self::$schema_ready = true === self::verify_schema();

		return self::$schema_ready;
	}

	/**
	 * Verify required Internal Linking schema elements.
	 *
	 * @return true|WP_Error
	 */
	public static function verify_schema() {
		$requirements = self::schema_requirements();

		foreach ( $requirements as $table_key => $requirement ) {
			$table_name = self::get_table_name( $table_key );

			if ( '' === $table_name || ! self::table_exists( $table_name ) ) {
				return new WP_Error(
					'srk_il_missing_table',
					sprintf(
						/* translators: %s: table key. */
						__( 'Internal Linking database table is missing: %s', 'seo-repair-kit' ),
						$table_key
					)
				);
			}

			$columns = self::get_table_columns( $table_name );

			foreach ( $requirement['columns'] as $column ) {
				if ( ! isset( $columns[ $column ] ) ) {
					return new WP_Error(
						'srk_il_missing_column',
						sprintf(
							/* translators: 1: table key, 2: column name. */
							__( 'Internal Linking database column is missing: %1$s.%2$s', 'seo-repair-kit' ),
							$table_key,
							$column
						)
					);
				}
			}

			$indexes = self::get_table_indexes( $table_name );

			foreach ( $requirement['indexes'] as $index ) {
				if ( ! isset( $indexes[ $index ] ) ) {
					return new WP_Error(
						'srk_il_missing_index',
						sprintf(
							/* translators: 1: table key, 2: index name. */
							__( 'Internal Linking database index is missing: %1$s.%2$s', 'seo-repair-kit' ),
							$table_key,
							$index
						)
					);
				}
			}
		}

		return true;
	}

	/**
	 * Get read-only schema requirements used after migration and before DB use.
	 *
	 * @return array<string,array<string,array<int,string>>>
	 */
	private static function schema_requirements() {
		return array(
			'content_index' => array(
				'columns' => array( 'id', 'post_id', 'post_type', 'post_status', 'post_title', 'post_url', 'content_hash', 'plain_content', 'word_count', 'taxonomy_json', 'internal_outbound_count', 'internal_inbound_count', 'external_outbound_count', 'orphan_status', 'last_indexed', 'created_at', 'updated_at' ),
				'indexes' => array( 'PRIMARY', 'post_id', 'post_type', 'post_status', 'orphan_status', 'last_indexed' ),
			),
			'keywords'      => array(
				'columns' => array( 'id', 'post_id', 'keyword', 'keyword_hash', 'normalized_keyword', 'meaningful_words_json', 'source', 'keyword_type', 'is_active', 'quality_score', 'clicks', 'impressions', 'ctr', 'avg_position', 'created_at', 'updated_at' ),
				'indexes' => array( 'PRIMARY', 'post_keyword_source', 'post_id', 'keyword_hash', 'source', 'is_active', 'quality_score' ),
			),
			'links'         => array(
				'columns' => array( 'id', 'source_post_id', 'target_post_id', 'target_url', 'anchor_text', 'is_internal' ),
				'indexes' => array( 'PRIMARY', 'source_post_id', 'target_post_id', 'is_internal' ),
			),
			'opportunities' => array(
				'columns' => array( 'id', 'scan_run_id', 'source_post_id', 'target_post_id', 'anchor_text', 'sentence', 'reason', 'score', 'final_score', 'selected_type', 'rule_score', 'ai_score', 'ai_similarity', 'status', 'inserted_at', 'created_at', 'updated_at' ),
				'indexes' => array( 'PRIMARY', 'scan_run_id', 'source_post_id', 'target_post_id', 'source_target', 'final_score', 'selected_type', 'status', 'created_at' ),
			),
			'auto_rules'    => array(
				'columns' => array( 'id', 'keyword', 'keyword_hash', 'target_post_id', 'target_url', 'status', 'priority', 'selection_mode', 'manual_review', 'auto_apply', 'case_sensitive', 'only_once_per_post', 'max_links_per_post', 'max_links_per_keyword', 'allow_duplicate_target', 'add_if_existing_link', 'require_target_published', 'override_one_link_per_sentence', 'prioritize_long_tail', 'apply_after_date', 'post_types_json', 'taxonomies_json', 'categories_json', 'tags_json', 'excluded_posts_json', 'excluded_categories_json', 'excluded_tags_json', 'matched_posts_json', 'applied_links_json', 'scan_log_json', 'links_created', 'removed_count', 'failed_count', 'last_scan_at', 'last_scan_duration', 'created_at', 'updated_at' ),
				'indexes' => array( 'PRIMARY', 'keyword_hash', 'target_post_id', 'status', 'priority', 'last_scan_at', 'created_at' ),
			),
			'url_changes'   => array(
				'columns' => array( 'id', 'old_url', 'new_url', 'affected_posts', 'changed_links', 'failed_count', 'status', 'rollback_json', 'created_at' ),
				'indexes' => array( 'PRIMARY', 'created_at' ),
			),
			'scan_runs'     => array(
				'columns' => array( 'id', 'scan_type', 'status', 'total_items', 'processed_items', 'success_items', 'failed_items', 'current_batch', 'message', 'started_at', 'completed_at', 'created_at', 'updated_at' ),
				'indexes' => array( 'PRIMARY', 'scan_type', 'status' ),
			),
			'embeddings'    => array(
				'columns' => array( 'id', 'post_id', 'embedding_json', 'model', 'content_hash', 'status', 'created_at', 'updated_at' ),
				'indexes' => array( 'PRIMARY', 'post_id', 'status', 'content_hash' ),
			),
		);
	}

	/**
	 * Check whether a table exists.
	 *
	 * @param string $table_name Full table name.
	 * @return bool
	 */
	private static function table_exists( $table_name ) {
		global $wpdb;

		return $table_name === $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $table_name ) );
	}

	/**
	 * Get table columns keyed by column name.
	 *
	 * @param string $table_name Full table name.
	 * @return array<string,array>
	 */
	private static function get_table_columns( $table_name ) {
		global $wpdb;

		$columns = array();
		$rows    = $wpdb->get_results( 'SHOW COLUMNS FROM `' . esc_sql( $table_name ) . '`', ARRAY_A ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared

		foreach ( (array) $rows as $row ) {
			if ( ! empty( $row['Field'] ) ) {
				$columns[ $row['Field'] ] = $row;
			}
		}

		return $columns;
	}

	/**
	 * Get table indexes keyed by index name.
	 *
	 * @param string $table_name Full table name.
	 * @return array<string,array>
	 */
	private static function get_table_indexes( $table_name ) {
		global $wpdb;

		$indexes = array();
		$rows    = $wpdb->get_results( 'SHOW INDEX FROM `' . esc_sql( $table_name ) . '`', ARRAY_A ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared

		foreach ( (array) $rows as $row ) {
			if ( ! empty( $row['Key_name'] ) ) {
				$indexes[ $row['Key_name'] ] = $row;
			}
		}

		return $indexes;
	}

	/*
	|--------------------------------------------------------------------------
	| Scan Runs
	|--------------------------------------------------------------------------
	*/

	/**
	 * Insert a scan run record.
	 *
	 * Keeps only the latest 20 scan-run records.
	 *
	 * @param array $data Scan data.
	 * @return int Inserted scan ID.
	 */
	public static function insert_scan_run( $data = array() ) {
		global $wpdb;

		$now = self::get_now();

		$data = wp_parse_args(
			$data,
			array(
				'scan_type'       => 'manual',
				'status'          => 'pending',
				'total_items'     => 0,
				'processed_items' => 0,
				'success_items'   => 0,
				'failed_items'    => 0,
				'current_batch'   => 0,
				'message'         => '',
				'started_at'      => $now,
				'completed_at'    => null,
				'created_at'      => $now,
				'updated_at'      => $now,
			)
		);

		$inserted = $wpdb->insert( self::get_table_name( 'scan_runs' ), self::sanitize_scan_run_data( $data ) );

		if ( false === $inserted ) {
			
			return 0;
		}

		$scan_id = absint( $wpdb->insert_id );

		if ( $scan_id ) {
			self::cleanup_old_scan_runs( 20 );
		}

		return $scan_id;
	}

	/**
	 * Update an existing scan run.
	 *
	 * @param int   $scan_id Scan run ID.
	 * @param array $data    Fields to update.
	 * @return int|false Number of rows updated, or false on error.
	 */
	public static function update_scan_run( $scan_id, $data ) {
		global $wpdb;

		$data               = self::sanitize_scan_run_data( $data );
		$data['updated_at'] = self::get_now();

		return $wpdb->update( self::get_table_name( 'scan_runs' ), $data, array( 'id' => absint( $scan_id ) ) );
	}

	/**
	 * Fetch a scan run by ID.
	 *
	 * @param int $scan_id Scan run ID.
	 * @return array|null
	 */
	public static function get_scan_run( $scan_id ) {
		global $wpdb;

		$table = self::get_table_name( 'scan_runs' );

		return $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$table} WHERE id = %d", absint( $scan_id ) ), ARRAY_A ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
	}

	/**
	 * Get the latest scan run for a specific workflow.
	 *
	 * @param string $scan_type Scan type.
	 * @return array|null
	 */
	public static function get_latest_scan_run_by_type( $scan_type ) {
		global $wpdb;

		$scan_type = sanitize_key( $scan_type );

		if ( '' === $scan_type ) {
			return null;
		}

		$table = self::get_table_name( 'scan_runs' );

		return $wpdb->get_row(
			$wpdb->prepare(
				"SELECT *
				FROM {$table}
				WHERE scan_type = %s
				ORDER BY id DESC
				LIMIT 1",
				$scan_type
			),
			ARRAY_A
		); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
	}

	/**
	 * Sanitize scan run data before insert/update.
	 *
	 * @param array $data Raw scan data.
	 * @return array Sanitized scan data.
	 */
	private static function sanitize_scan_run_data( $data ) {
		$clean = array();

		foreach ( (array) $data as $key => $value ) {
			switch ( $key ) {
				case 'scan_type': case 'status': $clean[ $key ] = sanitize_key( $value );
					break;

				case 'total_items':
				case 'processed_items':
				case 'success_items':
				case 'failed_items':
				case 'current_batch':
					$clean[ $key ] = absint( $value );
					break;

				case 'message': $clean[ $key ] = sanitize_text_field( $value );
					break;

				case 'started_at':
				case 'completed_at':
				case 'created_at':
				case 'updated_at':
					$clean[ $key ] = null === $value ? null : sanitize_text_field( $value );
					break;
			}
		}

		return $clean;
	}

	/**
	 * Keep only the latest scan run records.
	 *
	 * Older scan history is automatically deleted to prevent the
	 * scan_runs table from growing indefinitely.
	 *
	 * @param int $limit Maximum number of records to keep.
	 * @return int|false Number of deleted rows, or false on failure.
	 */
	private static function cleanup_old_scan_runs( $limit = 20 ) {
		global $wpdb;

		$table = self::get_table_name(
			'scan_runs'
		);

		$limit = max(
			1,
			absint( $limit )
		);

		if ( '' === $table ) {
			return false;
		}

		return $wpdb->query(
			$wpdb->prepare(
				"DELETE FROM %i
				WHERE id NOT IN (
					SELECT id
					FROM (
						SELECT id
						FROM %i
						ORDER BY id DESC
						LIMIT %d
					) AS latest_scan_runs
				)",
				$table,
				$table,
				$limit
			)
		);
	}

	/*
	|--------------------------------------------------------------------------
	| Content Index
	|--------------------------------------------------------------------------
	*/

	/**
	 * Fetch indexed content by WordPress post ID.
	 *
	 * @param int $post_id WordPress post ID.
	 * @return array|null
	 */
	public static function get_content_index_by_post_id( $post_id ) {
		global $wpdb;

		$table = self::get_table_name( 'content_index' );

		return $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$table} WHERE post_id = %d LIMIT 1", absint( $post_id ) ), ARRAY_A ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
	}

	/**
	 * Insert or update a content index row.
	 *
	 * @param array $data Content index data.
	 * @return bool True on success.
	 */
	public static function upsert_content_index( $data ) {
		global $wpdb;

		$now  = self::get_now();
		$data = wp_parse_args(
			$data,
			array(
				'post_id'                 => 0,
				'post_type'               => '',
				'post_status'             => '',
				'post_title'              => '',
				'post_url'                => '',
				'content_hash'            => '',
				'plain_content'           => '',
				'word_count'              => 0,
				'taxonomy_json'           => '[]',

				'internal_outbound_count' => 0,
				'internal_inbound_count'  => 0,
				'external_outbound_count' => 0,

				'orphan_status'           => 'unknown',
				'last_indexed'            => $now,
			)
		);
		$data   = self::sanitize_content_index_data( $data );
		$table  = self::get_table_name( 'content_index' );
		$exists = self::get_content_index_by_post_id( $data['post_id'] );

		if ( $exists ) {
			return false !== $wpdb->update( $table, $data, array( 'post_id' => absint( $data['post_id'] ), ) );
		}

		return false !== $wpdb->insert( $table, $data );
	}

	/**
	 * Sanitize content index data.
	 *
	 * @param array $data Raw data.
	 * @return array Sanitized data.
	 */
	private static function sanitize_content_index_data( $data ) {
		$data['post_id']                 = absint( $data['post_id'] );
		$data['post_type']               = sanitize_key( $data['post_type'] );
		$data['post_status']             = sanitize_key( $data['post_status'] );
		$data['post_title']              = sanitize_text_field( $data['post_title'] );
		$data['post_url']                = esc_url_raw( $data['post_url'] );
		$data['content_hash']            = sanitize_text_field( $data['content_hash'] );
		$data['plain_content']           = sanitize_textarea_field( $data['plain_content'] );
		$data['word_count']              = absint( $data['word_count'] );
		$data['taxonomy_json']           = self::safe_json_string( $data['taxonomy_json'] );
		$data['internal_outbound_count'] = absint( $data['internal_outbound_count'] );
		$data['internal_inbound_count']  = absint( $data['internal_inbound_count'] );
		$data['external_outbound_count'] = absint( $data['external_outbound_count'] );
		$data['orphan_status']           = sanitize_key( $data['orphan_status'] );
		$data['last_indexed']            = sanitize_text_field( $data['last_indexed'] );

		return $data;
	}

	/**
	 * Get summary counts for the content index screen.
	 *
	 * @return array<string,int>
	 */
	public static function get_content_index_summary() {
		global $wpdb;

		$cached = self::get_request_cache(
			'content_index_summary'
		);

		if ( null !== $cached ) {
			return $cached;
		}

		$table = self::get_table_name(
			'content_index'
		);

		if ( '' === $table ) {
			return array(
				'total' => 0,
				'pages' => 0,
				'posts' => 0,
				'cpt'   => 0,
			);
		}

		$row = $wpdb->get_row(
			$wpdb->prepare(
				"SELECT
					COUNT(*) AS total,

					SUM(
						CASE
							WHEN post_type = %s THEN 1
							ELSE 0
						END
					) AS pages,

					SUM(
						CASE
							WHEN post_type = %s THEN 1
							ELSE 0
						END
					) AS posts,

					SUM(
						CASE
							WHEN post_type NOT IN (%s, %s) THEN 1
							ELSE 0
						END
					) AS cpt

				FROM %i",
				'page',
				'post',
				'post',
				'page',
				$table
			),
			ARRAY_A
		);

		$summary = array(
			'total' => absint( $row['total'] ?? 0 ),
			'pages' => absint( $row['pages'] ?? 0 ),
			'posts' => absint( $row['posts'] ?? 0 ),
			'cpt'   => absint( $row['cpt'] ?? 0 ),
		);

		return self::set_request_cache(
			'content_index_summary',
			$summary
		);
	}

	/**
	 * Get paginated content index rows.
	 *
	 * @param int $page Current page.
	 * @param int $per_page Rows per page.
	 * @return array<int,array>
	 */
	public static function get_content_index_rows( $page = 1, $per_page = 10 ) {
		global $wpdb;

		$page     = max( 1, absint( $page ) );
		$per_page = max( 1, absint( $per_page ) );
		$offset   = ( $page - 1 ) * $per_page;
		$table    = self::get_table_name( 'content_index' );

		return $wpdb->get_results(
			$wpdb->prepare(
				"SELECT
					post_id,
					post_type,
					post_status,
					post_title,
					word_count,
					internal_inbound_count,
					internal_outbound_count,
					external_outbound_count,
					last_indexed
				FROM {$table}
				ORDER BY last_indexed DESC
				LIMIT %d OFFSET %d",
				$per_page,
				$offset
			),
			ARRAY_A
		);
	}

	/**
	 * Count indexed content rows.
	 *
	 * @return int
	 */
	public static function count_indexed_content() {
		global $wpdb;

		$table = self::get_table_name( 'content_index' );

		return absint(
			$wpdb->get_var(
				"SELECT COUNT(*)
				FROM {$table}"
			)
		);
	}

	/**
	 * Get a batch of indexed content rows for background processing.
	 *
	 * @param int $limit  Batch size.
	 * @param int $offset Offset.
	 * @return array<int,array>
	 */
	public static function get_indexed_content_batch( $limit = 10, $offset = 0 ) {
		global $wpdb;

		$table = self::get_table_name( 'content_index' );

		return $wpdb->get_results(
			$wpdb->prepare(
				"SELECT post_id
				FROM {$table}
				ORDER BY post_id ASC
				LIMIT %d OFFSET %d",
				max( 1, absint( $limit ) ),
				max( 0, absint( $offset ) )
			),
			ARRAY_A
		); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
	}

	/**
	 * Get likely source posts for one orphan target.
	 *
	 * This is a lightweight pre-filter only. It uses already indexed plain content
	 * to avoid running the full opportunity scorer against every indexed post.
	 *
	 * Final sentence matching, anchor validation and scoring are still performed
	 * by the normal opportunity engine.
	 *
	 * @param int      $target_post_id Orphan target post ID.
	 * @param string[] $terms          Meaningful target terms.
	 * @param int      $limit          Maximum source candidates to return.
	 *
	 * @return int[]
	 */
	public static function get_orphan_candidate_source_ids( $target_post_id, $terms, $limit = 500 ) {
		global $wpdb;

		$target_post_id = absint( $target_post_id );
		$limit          = min( 1000, max( 1, absint( $limit ) ) );

		if ( ! $target_post_id ) {
			return array();
		}

		/*
		* Normalize and deduplicate meaningful target words.
		*/
		$terms = array_values(
			array_unique(
				array_filter(
					array_map(
						static function ( $term ) {
							$term = sanitize_text_field( (string) $term );

							$term = trim( $term );

							return '' !== $term ? $term : null;
						}, (array) $terms ) ) ) );

		/*
		* Keep this query compact.
		*
		* Twelve meaningful words are more than enough for the
		* initial candidate-source lookup. The real opportunity
		* engine performs the final relevance checks afterwards.
		*/
		$terms = array_slice( $terms, 0, 12 );

		if ( empty( $terms ) ) {
			return array();
		}

		$table = self::get_table_name( 'content_index' );

		if ( '' === $table ) {
			return array();
		}

		/*
		* Prepare a fixed 12-slot term set.
		*
		* The method already limits target terms to 12 above, so using a fixed
		* prepared term table preserves the existing candidate-matching behavior
		* while avoiding dynamically assembled SQL fragments.
		*/
		$term_patterns = array();

		foreach ( $terms as $term ) {
			$term_patterns[] = $wpdb->esc_like( $term );
		}

		/*
		* Always provide exactly 12 term values to the prepared query.
		*
		* Empty values are ignored by the JOIN condition.
		*/
		$term_patterns = array_pad(
			$term_patterns,
			12,
			''
		);

		/*
		* Current opportunity matching requires useful term overlap.
		*
		* With multiple target words require at least two hits.
		* With a single available meaningful word, allow one hit and
		* let the full scoring engine make the final decision.
		*/
		$minimum_hits =
			count( $terms ) >= 2
				? 2
				: 1;

		$query_args = array_merge(
			array(
				$table,
			),
			$term_patterns,
			array(
				$target_post_id,
				'publish',
				$minimum_hits,
				$limit,
			)
		);

		$ids = $wpdb->get_col(
			$wpdb->prepare(
				"SELECT
					ci.post_id,
					COUNT(matched_terms.term) AS target_term_hits,
					MAX(ci.last_indexed) AS last_indexed
				FROM %i AS ci
				INNER JOIN (
					SELECT %s AS term
					UNION ALL SELECT %s
					UNION ALL SELECT %s
					UNION ALL SELECT %s
					UNION ALL SELECT %s
					UNION ALL SELECT %s
					UNION ALL SELECT %s
					UNION ALL SELECT %s
					UNION ALL SELECT %s
					UNION ALL SELECT %s
					UNION ALL SELECT %s
					UNION ALL SELECT %s
				) AS matched_terms
					ON matched_terms.term <> ''
					AND ci.plain_content LIKE CONCAT(
						'%%',
						matched_terms.term,
						'%%'
					)
				WHERE ci.post_id <> %d
					AND ci.post_status = %s
					AND ci.plain_content IS NOT NULL
					AND ci.plain_content <> ''
				GROUP BY ci.post_id
				HAVING target_term_hits >= %d
				ORDER BY
					target_term_hits DESC,
					last_indexed DESC,
					ci.post_id ASC
				LIMIT %d",
				$query_args
			)
		);

		return array_values(
			array_filter(
				array_map(
					'absint',
					(array) $ids
				)
			)
		);
	}

	/**
	 * Compatibility alias for keyword generator pagination.
	 *
	 * @param int $page Current page.
	 * @param int $per_page Rows per page.
	 * @return array<int,array>
	 */
	public static function get_indexed_content_rows_for_keywords( $page = 1, $per_page = 25 ) {
		$page     = max( 1, absint( $page ) );
		$per_page = max( 1, absint( $per_page ) );

		return self::get_indexed_content_batch( $per_page, ( $page - 1 ) * $per_page );
	}

	/**
	 * Fetch content index rows by post IDs.
	 *
	 * @param int[] $post_ids Post IDs.
	 * @return array<int,array>
	 */
	public static function get_content_index_rows_by_post_ids( $post_ids ) {
		global $wpdb;

		$post_ids = array_values( array_filter( array_map( 'absint', (array) $post_ids ) ) );

		if ( empty( $post_ids ) ) {
			return array();
		}

		$table = self::get_table_name( 'content_index' );

		$placeholders = implode( ', ', array_fill( 0, count( $post_ids ), '%d' ) );

		$query_args = array_merge( array( $table ), $post_ids );

		return $wpdb->get_results(
			$wpdb->prepare(
				"SELECT *
				FROM %i
				WHERE post_id IN ({$placeholders})",
				$query_args
			),
			ARRAY_A
		);
	}

	/*
	|--------------------------------------------------------------------------
	| Link Records and Reports
	|--------------------------------------------------------------------------
	*/

	/**
	 * Delete discovered/inserted links for a source post.
	 *
	 * @param int $source_post_id Source post ID.
	 * @return int|false Number of rows deleted, or false on error.
	 */
	public static function delete_links_by_source_post( $source_post_id ) {
		global $wpdb;

		return $wpdb->delete( self::get_table_name( 'links' ), array( 'source_post_id' => absint( $source_post_id ) ) );
	}

	/**
	 * Insert one currently discovered link.
	 *
	 * wp_srk_il_links is a live snapshot of links found
	 * inside indexed WordPress content.
	 *
	 * @param array $data Link data.
	 * @return int Inserted link ID, or 0 on failure.
	 */
	public static function insert_link( $data ) {
		global $wpdb;

		$data = wp_parse_args(
			$data,
			array(
				'source_post_id' => 0,
				'target_post_id' => null,
				'target_url'     => '',
				'anchor_text'    => '',
				'is_internal'    => 1,
			)
		);

		$data['source_post_id'] = absint( $data['source_post_id'] );

		$data['target_post_id'] = ! empty( $data['target_post_id'] ) ? absint( $data['target_post_id'] ) : null;

		$data['target_url'] = esc_url_raw( $data['target_url'] );

		$data['anchor_text'] = sanitize_text_field( $data['anchor_text'] );

		$data['is_internal'] = ! empty( $data['is_internal'] ) ? 1 : 0;

		$inserted = $wpdb->insert( self::get_table_name( 'links' ), $data );

		if ( false === $inserted ) {

			return 0;
		}

		return absint( $wpdb->insert_id );
	}

	/**
	 * Recalculate inbound counts and orphan status from the links table.
	 *
	 * @return void
	 */
	public static function recalculate_inbound_counts() {
		global $wpdb;

		$content_table = self::get_table_name( 'content_index' );
		$links_table   = self::get_table_name( 'links' );

		$wpdb->query( "UPDATE {$content_table} SET internal_inbound_count = 0" ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$wpdb->query(
			"UPDATE {$content_table} ci
			LEFT JOIN (
				SELECT target_post_id, COUNT(*) AS link_count
				FROM {$links_table}
				WHERE is_internal = 1
					AND target_post_id IS NOT NULL
				GROUP BY target_post_id
			) l ON ci.post_id = l.target_post_id
			SET ci.internal_inbound_count = IFNULL(l.link_count, 0),
				ci.orphan_status = CASE
					WHEN ci.orphan_status = 'ignored' THEN 'ignored'
					WHEN IFNULL(l.link_count, 0) = 0 THEN 'critical'
					WHEN IFNULL(l.link_count, 0) = 1 THEN 'low'
					ELSE 'healthy'
				END"
		); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
	}

	/**
	 * Check whether source already links to target.
	 *
	 * @param int $source_post_id Source post ID.
	 * @param int $target_post_id Target post ID.
	 * @return bool
	 */
	public static function source_has_active_link_to_target( $source_post_id, $target_post_id ) {
		global $wpdb;

		$table = self::get_table_name( 'links' );

		return (bool) $wpdb->get_var(
			$wpdb->prepare(
				"SELECT id
				FROM {$table}
				WHERE source_post_id = %d
					AND target_post_id = %d
				LIMIT 1",
				absint( $source_post_id ),
				absint( $target_post_id )
			)
		); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
	}

	/**
	 * Determine if a source post has reached max outbound link setting.
	 *
	 * @param int $source_post_id Source post ID.
	 * @param int $max_outbound Max outbound links. 0 disables limit.
	 * @return bool
	 */
	public static function source_outbound_count_reached( $source_post_id, $max_outbound ) {
		global $wpdb;

		if ( ! $max_outbound ) {
			return false;
		}

		$table = self::get_table_name( 'links' );
		$count = $wpdb->get_var(
			$wpdb->prepare(
				"SELECT COUNT(*)
				FROM {$table}
				WHERE source_post_id = %d
					AND is_internal = 1",
				absint( $source_post_id )
			)
		); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared

		return absint( $count ) >= absint( $max_outbound );
	}

	/**
	 * Fetch domain-level external link report.
	 *
	 * @since 2.2.0
	 * @return array<int,array>
	 */
	public static function get_domains_report() {
		global $wpdb;

		$links_table = self::get_table_name( 'links' );

		if ( '' === $links_table ) {
			return array();
		}

		return $wpdb->get_results(
			$wpdb->prepare(
				"SELECT
					LOWER(
						REPLACE(
							SUBSTRING_INDEX(
								SUBSTRING_INDEX(
									target_url,
									'//',
									-1
								),
								'/',
								1
							),
							'www.',
							''
						)
					) AS domain,

					COUNT(
						DISTINCT source_post_id
					) AS posts_count,

					COUNT(*) AS links_count

				FROM %i

				WHERE is_internal = %d
					AND target_url <> ''

				GROUP BY domain

				HAVING domain <> ''

				ORDER BY links_count DESC",
				$links_table,
				0
			),
			ARRAY_A
		);
	}

	/**
	 * Fetch all links for a specific domain.
	 *
	 * @since 2.2.0
	 * @param string $domain Domain name.
	 * @return array<int,array>
	 */
	public static function get_links_by_domain( $domain ) {
		global $wpdb;

		$links_table   = self::get_table_name( 'links' );
		$content_table = self::get_table_name( 'content_index' );
		$domain        = sanitize_text_field( $domain );

		if ( '' === $links_table || '' === $content_table || '' === $domain ) {
			return array();
		}

		$like = '%' . $wpdb->esc_like( $domain ) . '%';

		return $wpdb->get_results(
			$wpdb->prepare(
				"SELECT
					l.*,
					ci.post_title AS source_post_title
				FROM %i AS l
				LEFT JOIN %i AS ci
					ON ci.post_id = l.source_post_id
				WHERE l.is_internal = %d
					AND l.target_url LIKE %s
				ORDER BY ci.post_title ASC",
				$links_table,
				$content_table,
				0,
				$like
			),
			ARRAY_A
		);
	}

	/**
	 * Fetch posts containing external links to a domain.
	 *
	 * @since 2.2.0
	 * @param string $domain Domain name.
	 * @return array<int,array>
	 */
	public static function get_posts_by_domain( $domain ) {
		global $wpdb;

		$links_table   = self::get_table_name( 'links' );
		$content_table = self::get_table_name( 'content_index' );
		$posts_table   = $wpdb->posts;
		$domain        = sanitize_text_field( $domain );

		if ( '' === $links_table || '' === $content_table || '' === $domain ) {
			return array();
		}

		$like = '%' . $wpdb->esc_like( $domain ) . '%';

		return $wpdb->get_results(
			$wpdb->prepare(
				"SELECT
					ci.post_id,
					ci.post_title,
					ci.post_type,
					p.post_date AS post_date,
					COUNT(l.id) AS link_count
				FROM %i AS ci
				INNER JOIN %i AS l
					ON l.source_post_id = ci.post_id
					AND l.is_internal = %d
					AND l.target_url LIKE %s
				LEFT JOIN %i AS p
					ON p.ID = ci.post_id
				GROUP BY
					ci.post_id,
					ci.post_title,
					ci.post_type,
					p.post_date
				ORDER BY ci.post_title ASC",
				$content_table,
				$links_table,
				0,
				$like,
				$posts_table
			),
			ARRAY_A
		);
	}

	/*
	|--------------------------------------------------------------------------
	| Keywords
	|--------------------------------------------------------------------------
	*/

	/**
	 * Delete auto-generated keywords for a post.
	 *
	 * Manual/custom keywords remain intact.
	 *
	 * @param int $post_id Post ID.
	 * @return int|false
	 */
	public static function delete_auto_keywords_for_post( $post_id ) {
		global $wpdb;

		return $wpdb->delete( self::get_table_name( 'keywords' ), array( 'post_id' => absint( $post_id ), 'keyword_type' => 'auto', ) );
	}

	/**
	 * Insert or update a target keyword.
	 *
	 * @param array $data Keyword data.
	 * @return int Keyword row ID.
	 */
	public static function upsert_keyword( $data ) {
		global $wpdb;

		$keyword = isset( $data['keyword'] ) ? sanitize_text_field( $data['keyword'] ) : '';
		$keyword = trim( preg_replace( '/\s+/u', ' ', $keyword ) );

		if ( '' === $keyword ) {
			return 0;
		}

		$now    = self::get_now();
		$source = sanitize_key( isset( $data['source'] ) ? $data['source'] : 'title' );
		$row    = array(
			'post_id'               => absint( isset( $data['post_id'] ) ? $data['post_id'] : 0 ),
			'keyword'               => $keyword,
			'keyword_hash'          => self::get_keyword_hash( $keyword ),
			'normalized_keyword'    => self::normalize_keyword_text( $keyword ),
			'meaningful_words_json' => ! empty( $data['meaningful_words_json'] ) ? self::safe_json_string( $data['meaningful_words_json'] ) : '[]',
			'source'                => $source,
			'keyword_type'          => sanitize_key( isset( $data['keyword_type'] ) ? $data['keyword_type'] : 'auto' ),
			'is_active'             => isset( $data['is_active'] ) ? absint( $data['is_active'] ) : 1,
			'quality_score'         => absint( isset( $data['quality_score'] ) ? $data['quality_score'] : 0 ),
			'clicks'                => absint( isset( $data['clicks'] ) ? $data['clicks'] : 0 ),
			'impressions'           => absint( isset( $data['impressions'] ) ? $data['impressions'] : 0 ),
			'ctr'                   => floatval( isset( $data['ctr'] ) ? $data['ctr'] : 0 ),
			'avg_position'          => floatval( isset( $data['avg_position'] ) ? $data['avg_position'] : 0 ),
			'created_at'            => $now,
			'updated_at'            => $now,
		);

		$table       = self::get_table_name( 'keywords' );
		$existing_id = $wpdb->get_var(
			$wpdb->prepare(
				"SELECT id FROM {$table} WHERE post_id = %d AND keyword_hash = %s AND source = %s LIMIT 1",
				$row['post_id'],
				$row['keyword_hash'],
				$source
			)
		); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared

		if ( $existing_id ) {
			unset( $row['created_at'] );
			$wpdb->update( $table, $row, array( 'id' => absint( $existing_id ) ) );

			return absint( $existing_id );
		}

		$wpdb->insert( $table, $row );

		return absint( $wpdb->insert_id );
	}

	/**
	 * Get stored auto-generated keywords for a post.
	 *
	 * @param int $post_id Post ID.
	 * @return array<int,array>
	 */
	public static function get_auto_keywords_by_post( $post_id ) {
		global $wpdb;

		$table = self::get_table_name( 'keywords' );

		return $wpdb->get_results(
			$wpdb->prepare(
				"SELECT keyword, keyword_hash, normalized_keyword, meaningful_words_json, source, keyword_type, is_active, quality_score
				FROM {$table}
				WHERE post_id = %d AND keyword_type = 'auto'
				ORDER BY source ASC, keyword_hash ASC, id ASC",
				absint( $post_id )
			),
			ARRAY_A
		); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
	}
	/**
	 * Get active keywords for a post.
	 *
	 * @param int $post_id Post ID.
	 * @return array<int,array>
	 */
	public static function get_keywords_by_post( $post_id ) {
		global $wpdb;

		$table = self::get_table_name( 'keywords' );

		return $wpdb->get_results(
			$wpdb->prepare(
				"SELECT * FROM {$table}
				WHERE post_id = %d AND is_active = 1
				ORDER BY FIELD(source, 'custom', 'gsc', 'seo', 'title', 'slug', 'taxonomy'), quality_score DESC, id ASC",
				absint( $post_id )
			),
			ARRAY_A
		); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
	}

	/**
	 * Get active custom keywords for a post.
	 *
	 * @param int $post_id Post ID.
	 * @return array<int,array>
	 */
	public static function get_post_custom_keywords( $post_id ) {
		global $wpdb;

		$table = self::get_table_name( 'keywords' );

		return $wpdb->get_results(
			$wpdb->prepare(
				"SELECT * FROM {$table}
				WHERE post_id = %d AND source = 'custom' AND is_active = 1
				ORDER BY id DESC",
				absint( $post_id )
			),
			ARRAY_A
		); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
	}

	/**
	 * Get active target keywords, excluding the current source post.
	 *
	 * Used by the opportunity engine to compare source phrases against target
	 * keyword pools.
	 *
	 * @param int      $source_post_id Source post ID.
	 * @param string[] $sources Optional keyword sources.
	 * @param int      $target_post_id Optional target post filter.
	 * @return array<int,array>
	 */
	public static function get_active_target_keywords_except_post( $source_post_id, $sources = array(), $target_post_id = 0 ) {
		global $wpdb;

		$source_post_id = absint( $source_post_id );
		$target_post_id = absint( $target_post_id );

		$sources = array_values( array_filter( array_map( 'sanitize_key', (array) $sources ) ) );

		$keyword_table = self::get_table_name( 'keywords' );
		$content_table = self::get_table_name( 'content_index' );

		if ( '' === $keyword_table || '' === $content_table ) {
			return array();
		}

		$where = 'k.is_active = %d
			AND k.post_id <> %d
			AND ci.post_status = %s';

		$query_args = array( $keyword_table, $content_table, 1, $source_post_id, 'publish', );

		if ( $target_post_id ) {
			$where       .= ' AND k.post_id = %d';
			$query_args[] = $target_post_id;
		}

		if ( ! empty( $sources ) ) {
			$source_placeholders = implode( ',', array_fill( 0, count( $sources ), '%s' ) );

			$where       .= " AND k.source IN ({$source_placeholders})";
			$query_args   = array_merge( $query_args, $sources );
		}

		return $wpdb->get_results(
			$wpdb->prepare(
				"SELECT
					k.*,
					ci.post_title AS target_title,
					ci.post_url AS target_url,
					ci.taxonomy_json,
					ci.internal_inbound_count
				FROM %i AS k
				INNER JOIN %i AS ci
					ON ci.post_id = k.post_id
				WHERE {$where}
				ORDER BY
					k.quality_score DESC,
					FIELD(
						k.source,
						'custom',
						'gsc',
						'seo',
						'title',
						'slug',
						'taxonomy'
					),
					LENGTH(k.keyword) DESC
				LIMIT 5000",
				$query_args
			),
			ARRAY_A
		);
	}

	/**
	 * Get reusable full-site active target keyword pool.
	 *
	 * Unlike get_active_target_keywords_except_post(), this method does not
	 * exclude a source post. The opportunity engine skips the current source
	 * post in PHP, allowing the same pool to be reused across a batch.
	 *
	 * @param array $sources Enabled keyword sources.
	 * @return array
	 */
	public static function get_active_target_keywords_pool( $sources = array() ) {
		global $wpdb;

		$keyword_table = self::get_table_name( 'keywords' );
		$content_table = self::get_table_name( 'content_index' );

		if ( '' === $keyword_table || '' === $content_table ) {
			return array();
		}

		$sources = array_values( array_filter( array_map( 'sanitize_key', (array) $sources ) ) );

		/*
		* Source-filtered query.
		*/
		if ( ! empty( $sources ) ) {
			$source_placeholders = implode( ',', array_fill( 0, count( $sources ), '%s' ) );

			$query_args = array_merge( array( $keyword_table, $content_table, 'publish', ), $sources );

			$sql = $wpdb->prepare(
				"SELECT
					k.*,
					ci.post_title AS target_title,
					ci.post_url AS target_url,
					ci.taxonomy_json,
					ci.internal_inbound_count
				FROM %i AS k
				INNER JOIN %i AS ci
					ON ci.post_id = k.post_id
				WHERE k.is_active = 1
					AND ci.post_status = %s
					AND k.source IN ({$source_placeholders})
				ORDER BY
					k.quality_score DESC,
					FIELD(
						k.source,
						'custom',
						'gsc',
						'ai',
						'title',
						'slug',
						'taxonomy'
					),
					LENGTH(k.keyword) DESC
				LIMIT 5000",
				$query_args
			);

			return $wpdb->get_results( $sql, ARRAY_A );
		}

		/*
		* No source filter.
		*/
		$sql = $wpdb->prepare(
			"SELECT
				k.*,
				ci.post_title AS target_title,
				ci.post_url AS target_url,
				ci.taxonomy_json,
				ci.internal_inbound_count
			FROM %i AS k
			INNER JOIN %i AS ci
				ON ci.post_id = k.post_id
			WHERE k.is_active = 1
				AND ci.post_status = %s
			ORDER BY
				k.quality_score DESC,
				FIELD(
					k.source,
					'custom',
					'gsc',
					'ai',
					'title',
					'slug',
					'taxonomy'
				),
				LENGTH(k.keyword) DESC
			LIMIT 5000",
			$keyword_table,
			$content_table,
			'publish'
		);

		return $wpdb->get_results( $sql, ARRAY_A );
	}

	/**
	 * Get all currently linked target post IDs for one source post.
	 *
	 * @param int $source_post_id Source post ID.
	 * @return array<int,bool>
	 */
	public static function get_linked_target_map( $source_post_id ) {
		global $wpdb;

		$source_post_id = absint( $source_post_id );

		if ( ! $source_post_id ) {
			return array();
		}

		$table = self::get_table_name( 'links' );

		$ids = $wpdb->get_col(
			$wpdb->prepare(
				"SELECT DISTINCT target_post_id
				FROM {$table}
				WHERE source_post_id = %d
					AND is_internal = 1
					AND target_post_id IS NOT NULL",
				$source_post_id
			)
		);

		$map = array();

		foreach ( (array) $ids as $id ) {
			$id = absint( $id );

			if ( $id ) {
				$map[ $id ] = true;
			}
		}

		return $map;
	}

	/**
	 * Delete one custom keyword.
	 *
	 * @param int $keyword_id Keyword row ID.
	 * @param int $post_id Optional post ID guard.
	 * @return int|false
	 */
	public static function delete_custom_keyword( $keyword_id, $post_id = 0 ) {
		global $wpdb;

		$where = array( 'id' => absint( $keyword_id ), 'source' => 'custom', );

		if ( $post_id ) {
			$where['post_id'] = absint( $post_id );
		}

		return $wpdb->delete( self::get_table_name( 'keywords' ), $where );
	}

	/**
	 * Get keyword coverage summary for admin UI.
	 *
	 * @return array<string,int|float>
	 */
	public static function get_target_keyword_summary() {
		global $wpdb;

		$cached = self::get_request_cache(
			'target_keyword_summary'
		);

		if ( null !== $cached ) {
			return $cached;
		}

		$keyword_table = self::get_table_name(
			'keywords'
		);

		if ( '' === $keyword_table ) {
			return array(
				'indexed_posts'   => 0,
				'covered_posts'   => 0,
				'total_keywords'  => 0,
				'custom_keywords' => 0,
				'gsc_clicks'      => 0,
				'gsc_impressions' => 0,
			);
		}

		/*
		* Reuse the already calculated Content Index summary.
		*
		* On the normal Internal Linking admin request this is returned from
		* request memory instead of executing another COUNT(*) query.
		*/
		$content_summary =
			self::get_content_index_summary();

		$row = $wpdb->get_row(
			$wpdb->prepare(
				"SELECT
					COUNT(
						DISTINCT CASE
							WHEN is_active = %d THEN post_id
							ELSE NULL
						END
					) AS covered_posts,

					SUM(
						CASE
							WHEN is_active = %d THEN 1
							ELSE 0
						END
					) AS total_keywords,

					SUM(
						CASE
							WHEN is_active = %d
								AND source = %s
							THEN 1
							ELSE 0
						END
					) AS custom_keywords,

					COALESCE(
						SUM(
							CASE
								WHEN is_active = %d
								THEN impressions
								ELSE 0
							END
						),
						0
					) AS gsc_impressions

				FROM %i",
				1,
				1,
				1,
				'custom',
				1,
				$keyword_table
			),
			ARRAY_A
		);

		$summary = array(
			'indexed_posts' =>
				absint(
					$content_summary['total'] ?? 0
				),

			'covered_posts' =>
				absint(
					$row['covered_posts'] ?? 0
				),

			'total_keywords' =>
				absint(
					$row['total_keywords'] ?? 0
				),

			'custom_keywords' =>
				absint(
					$row['custom_keywords'] ?? 0
				),

			'gsc_clicks' => 0,

			'gsc_impressions' =>
				absint(
					$row['gsc_impressions'] ?? 0
				),
		);

		return self::set_request_cache(
			'target_keyword_summary',
			$summary
		);
	}

	/**
	 * Count grouped target keyword rows.
	 *
	 * @param string $search Search text.
	 * @param string $source Keyword source filter.
	 * @return int
	 */
	public static function count_target_keyword_rows( $search = '', $source = 'all' ) {
		global $wpdb;

		$content_table = self::get_table_name( 'content_index' );
		$keyword_table = self::get_table_name( 'keywords' );
		$where = 'WHERE 1=1';
		$args          = array();

		if ( $source && 'all' !== $source ) {
			$where .= ' AND k.source = %s';
			$args[] = sanitize_key( $source );
		}

		if ( '' !== $search ) {
			$like   = '%' . $wpdb->esc_like( sanitize_text_field( $search ) ) . '%';
			$where .= ' AND (ci.post_title LIKE %s OR k.keyword LIKE %s)';
			$args[] = $like;
			$args[] = $like;
		}

		$sql = "SELECT COUNT(DISTINCT ci.post_id)
			FROM {$content_table} ci
			LEFT JOIN {$keyword_table} k ON ci.post_id = k.post_id AND k.is_active = 1
			{$where}";

		return absint( empty( $args ) ? $wpdb->get_var( $sql ) : $wpdb->get_var( $wpdb->prepare( $sql, $args ) ) ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
	}

	/**
	 * Get grouped target keyword rows for admin UI.
	 *
	 * @param int    $page Current page.
	 * @param int    $per_page Rows per page.
	 * @param string $search Search text.
	 * @param string $source Keyword source filter.
	 * @return array<int,array>
	 */
	public static function get_target_keyword_rows( $page = 1, $per_page = 10, $search = '', $source = 'all' ) {
		global $wpdb;

		$page          = max( 1, absint( $page ) );
		$per_page      = max( 1, absint( $per_page ) );
		$offset        = ( $page - 1 ) * $per_page;
		$content_table = self::get_table_name( 'content_index' );
		$keyword_table = self::get_table_name( 'keywords' );
		$where         = 'WHERE 1=1';
		$args          = array();

		if ( $source && 'all' !== $source ) {
			$where .= ' AND k.source = %s';
			$args[] = sanitize_key( $source );
		}

		if ( '' !== $search ) {
			$like   = '%' . $wpdb->esc_like( sanitize_text_field( $search ) ) . '%';
			$where .= ' AND (ci.post_title LIKE %s OR k.keyword LIKE %s)';
			$args[] = $like;
			$args[] = $like;
		}

		$args[] = $per_page;
		$args[] = $offset;

		$sql = "SELECT ci.post_id, ci.post_title, ci.post_type,
				COUNT(k.id) AS keyword_count,
				SUM(CASE WHEN k.source = 'custom' THEN 1 ELSE 0 END) AS custom_keywords,
				COALESCE(SUM(k.clicks), 0) AS clicks,
				COALESCE(SUM(k.impressions), 0) AS impressions,
				COALESCE(AVG(k.avg_position), 0) AS avg_position,
				GROUP_CONCAT(DISTINCT k.source ORDER BY k.source SEPARATOR ',') AS sources,
				GROUP_CONCAT(k.keyword ORDER BY k.quality_score DESC, k.id ASC SEPARATOR '||') AS keywords
			FROM {$content_table} ci
			LEFT JOIN {$keyword_table} k ON ci.post_id = k.post_id AND k.is_active = 1
			{$where}
			GROUP BY ci.post_id
			ORDER BY keyword_count DESC, ci.post_title ASC
			LIMIT %d OFFSET %d";

		return $wpdb->get_results( $wpdb->prepare( $sql, $args ), ARRAY_A ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
	}

	/**
	 * Run lightweight, idempotent data migrations after dbDelta().
	 *
	 * Existing inserted, ignored and removed opportunity history is preserved.
	 *
	 * @param string $from_version Previously installed DB version.
	 * @return true|WP_Error
	 */
	private static function run_data_migrations( $from_version = '' ) {
		if ( version_compare( (string) $from_version, self::DB_VERSION, '>=' ) ) {
			return true;
		}

		$result = self::run_opportunity_batch_update(
			"final_score = 0
				AND score > 0",
			'final_score = score'
		);

		if ( is_wp_error( $result ) ) {
			return $result;
		}

		$result = self::run_opportunity_batch_update(
			"reason = 'AI semantic relevance match'",
			"selected_type = 'ai',
				ai_score = score,
				final_score = score"
		);

		if ( is_wp_error( $result ) ) {
			return $result;
		}

		$result = self::run_opportunity_batch_update(
			"(
					selected_type IS NULL
					OR selected_type = ''
					OR selected_type = 'rule'
				)
				AND rule_score IS NULL",
			"selected_type = 'rule',
				rule_score = score,
				final_score = score"
		);

		if ( is_wp_error( $result ) ) {
			return $result;
		}

		return self::deduplicate_active_opportunities();
	}

	/**
	 * Run a bounded opportunity UPDATE for trusted migration-only SQL fragments.
	 *
	 * @param string $where_sql Fixed WHERE fragment.
	 * @param string $set_sql   Fixed SET fragment.
	 * @return true|WP_Error
	 */
	private static function run_opportunity_batch_update( $where_sql, $set_sql ) {
		global $wpdb;

		$table   = self::get_table_name( 'opportunities' );
		$last_id = 0;

		do {
			$ids = $wpdb->get_col(
				$wpdb->prepare(
					"SELECT id
					FROM {$table}
					WHERE id > %d
						AND {$where_sql}
					ORDER BY id ASC
					LIMIT %d",
					$last_id,
					self::DATA_MIGRATION_BATCH_SIZE
				)
			); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared

			$ids = array_values( array_filter( array_map( 'absint', (array) $ids ) ) );

			if ( empty( $ids ) ) {
				return true;
			}

			$last_id = max( $ids );

			$result = $wpdb->query(
				$wpdb->prepare(
					"UPDATE {$table}
					SET {$set_sql}
					WHERE id IN (" . implode( ',', array_fill( 0, count( $ids ), '%d' ) ) . ')',
					$ids
				)
			); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared

			if ( false === $result ) {
				return new WP_Error(
					'srk_il_data_migration_failed',
					__( 'Internal Linking opportunity data migration failed.', 'seo-repair-kit' )
				);
			}
		} while ( count( $ids ) >= self::DATA_MIGRATION_BATCH_SIZE );

		return true;
	}

	/**
	 * Remove duplicate active opportunities for the same source-target pair.
	 *
	 * Only building and pending rows are deduplicated. Inserted, ignored and removed
	 * history is intentionally preserved.
	 *
	 * @return true|WP_Error
	 */
	private static function deduplicate_active_opportunities() {
		global $wpdb;

		$table = self::get_table_name( 'opportunities' );

		do {
			$deleted_any = false;

			$groups = $wpdb->get_results(
				$wpdb->prepare(
					"SELECT source_post_id, target_post_id, COUNT(*) AS total_rows
					FROM {$table}
					WHERE status IN ('building', 'pending')
					GROUP BY source_post_id, target_post_id
					HAVING COUNT(*) > 1
					LIMIT %d",
					100
				),
				ARRAY_A
			); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared

			foreach ( (array) $groups as $group ) {
				$rows = $wpdb->get_results(
					$wpdb->prepare(
						"SELECT id
						FROM {$table}
						WHERE source_post_id = %d
							AND target_post_id = %d
							AND status IN ('building', 'pending')
						ORDER BY
							CASE
								WHEN selected_type = 'ai' THEN 0
								ELSE 1
							END ASC,

							CASE
								WHEN selected_type = 'ai'
									THEN COALESCE(
										NULLIF(ai_score, 0),
										NULLIF(final_score, 0),
										score
									)
								ELSE COALESCE(
									NULLIF(rule_score, 0),
									NULLIF(final_score, 0),
									score
								)
							END DESC,

							CHAR_LENGTH(anchor_text) DESC,
							CHAR_LENGTH(sentence) DESC,
							id ASC",
						absint( $group['source_post_id'] ),
						absint( $group['target_post_id'] )
					),
					ARRAY_A
				); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared

				if ( count( $rows ) <= 1 ) {
					continue;
				}

				array_shift( $rows );

				$delete_ids = array_values( array_filter( array_map( static function ( $row ) { return absint( $row['id'] ?? 0 ); }, $rows ) ) );

				if ( empty( $delete_ids ) ) {
					continue;
				}

				$result = $wpdb->query(
					$wpdb->prepare(
						"DELETE FROM {$table}
						WHERE id IN (" . implode( ',', array_fill( 0, count( $delete_ids ), '%d' ) ) . ')',
						$delete_ids
					)
				); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared

				if ( false === $result ) {
					return new WP_Error(
						'srk_il_deduplicate_failed',
						__( 'Internal Linking opportunity deduplication failed.', 'seo-repair-kit' )
					);
				}

				if ( $result > 0 ) {
					$deleted_any = true;
				}
			}

			if ( ! empty( $groups ) && ! $deleted_any ) {
				return true;
			}
		} while ( ! empty( $groups ) );

		return true;
	}

	/**
	 * Get current WordPress-local MySQL datetime.
	 *
	 * @return string
	 */
	public static function get_now() {
		return current_time( 'mysql' );
	}

	/**
	 * Convert a keyword into a stable hash for uniqueness checks.
	 *
	 * @param string $keyword Raw keyword.
	 * @return string SHA-256 hash.
	 */
	public static function get_keyword_hash( $keyword ) {
		return hash( 'sha256', self::normalize_keyword_text( $keyword ) );
	}

	/**
	 * Normalize keyword text for matching and duplicate detection.
	 *
	 * @param string $keyword Raw keyword.
	 * @return string Normalized keyword.
	 */
	public static function normalize_keyword_text( $keyword ) {
		$keyword = wp_strip_all_tags( (string) $keyword );
		$keyword = html_entity_decode( $keyword, ENT_QUOTES, get_bloginfo( 'charset' ) );
		$keyword = strtolower( remove_accents( $keyword ) );
		$keyword = preg_replace( '/[\x{2018}\x{2019}\x{201C}\x{201D}]/u', "'", $keyword );
		$keyword = preg_replace( '/[^\p{L}\p{N}\s\-]+/u', ' ', $keyword );
		$keyword = preg_replace( '/[\-\_]+/u', ' ', $keyword );

		return trim( preg_replace( '/\s+/u', ' ', $keyword ) );
	}

	/**
	 * Check if target has low/no inbound internal links.
	 *
	 * @param int $post_id Post ID.
	 * @return bool
	 */
	public static function is_orphan_target( $post_id ) {
		return self::get_target_inbound_count( $post_id ) <= 1;
	}

	/**
	 * Get inbound internal link count for a target post.
	 *
	 * @param int $post_id Post ID.
	 * @return int
	 */
	public static function get_target_inbound_count( $post_id ) {
		global $wpdb;

		$table = self::get_table_name( 'content_index' );

		return absint(
			$wpdb->get_var( $wpdb->prepare( "SELECT internal_inbound_count FROM {$table} WHERE post_id = %d", absint( $post_id ) ) )
		); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
	}

	/**
	 * Get orphan content summary counts.
	 *
	 * @return array<string,int>
	 */
	public static function get_orphan_summary() {
		global $wpdb;

		$cached = self::get_request_cache(
			'orphan_summary'
		);

		if ( null !== $cached ) {
			return $cached;
		}

		$table = self::get_table_name(
			'content_index'
		);

		if ( '' === $table ) {
			return array(
				'critical' => 0,
				'low'      => 0,
				'healthy'  => 0,
				'ignored'  => 0,
			);
		}

		$row = $wpdb->get_row(
			$wpdb->prepare(
				"SELECT
					SUM(
						CASE
							WHEN orphan_status = %s THEN 1
							ELSE 0
						END
					) AS critical,

					SUM(
						CASE
							WHEN orphan_status = %s THEN 1
							ELSE 0
						END
					) AS low,

					SUM(
						CASE
							WHEN orphan_status = %s THEN 1
							ELSE 0
						END
					) AS healthy,

					SUM(
						CASE
							WHEN orphan_status = %s THEN 1
							ELSE 0
						END
					) AS ignored

				FROM %i",
				'critical',
				'low',
				'healthy',
				'ignored',
				$table
			),
			ARRAY_A
		);

		$summary = array(
			'critical' => absint( $row['critical'] ?? 0 ),
			'low'      => absint( $row['low'] ?? 0 ),
			'healthy'  => absint( $row['healthy'] ?? 0 ),
			'ignored'  => absint( $row['ignored'] ?? 0 ),
		);

		return self::set_request_cache(
			'orphan_summary',
			$summary
		);
	}

	/**
	 * Count orphan content rows by status.
	 *
	 * @param string $status Orphan status filter.
	 * @return int
	 */
	public static function count_orphan_content_rows( $status = 'all' ) {
		global $wpdb;

		$table  = self::get_table_name( 'content_index' );
		$status = sanitize_key( $status );

		if ( '' === $table ) {
			return 0;
		}

		$allowed_statuses = array( 'critical', 'low', 'healthy', 'ignored', 'unknown', );

		if ( 'all' !== $status && in_array( $status, $allowed_statuses, true ) ) {
			return absint(
				$wpdb->get_var(
					$wpdb->prepare(
						"SELECT COUNT(*)
						FROM %i
						WHERE orphan_status = %s",
						$table,
						$status
					)
				)
			);
		}

		return absint(
			$wpdb->get_var(
				$wpdb->prepare(
					"SELECT COUNT(*)
					FROM %i
					WHERE orphan_status IN (%s, %s)",
					$table,
					'critical',
					'low'
				)
			)
		);
	}

	/**
	 * Get orphan content rows by status.
	 *
	 * @param int    $page Current page.
	 * @param int    $per_page Rows per page.
	 * @param string $status Orphan status filter.
	 * @return array<int,array>
	 */
	public static function get_orphan_content_rows( $page = 1, $per_page = 10, $status = 'all' ) {
		global $wpdb;

		$page     = max( 1, absint( $page ) );
		$per_page = max( 1, absint( $per_page ) );
		$offset   = ( $page - 1 ) * $per_page;
		$table    = self::get_table_name( 'content_index' );
		$where    = "WHERE orphan_status IN ('critical', 'low')";
		$args     = array();

		if ( 'all' !== $status ) {
			$where  = 'WHERE orphan_status = %s';
			$args[] = sanitize_key( $status );
		}

		$args[] = $per_page;
		$args[] = $offset;

		$sql = "SELECT * FROM {$table} {$where} ORDER BY internal_inbound_count ASC, post_title ASC LIMIT %d OFFSET %d";

		return $wpdb->get_results( $wpdb->prepare( $sql, $args ), ARRAY_A ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
	}

	/**
	 * Mark a post as ignored from orphan content reporting.
	 *
	 * @param int $post_id Post ID.
	 * @return int|false
	 */
	public static function ignore_orphan_content( $post_id ) {
		global $wpdb;

		return $wpdb->update(
			self::get_table_name( 'content_index' ),
			array( 'orphan_status' => 'ignored', 'updated_at' => self::get_now(), ),
			array( 'post_id' => absint( $post_id ) )
		);
	}

	/**
	 * Insert a URL change history row.
	 *
	 * @since 2.1.12
	 *
	 * @param array $data URL change data.
	 * @return int URL change ID.
	 */
	public static function insert_url_change( $data ) {
		global $wpdb;

		$data = wp_parse_args(
			(array) $data,
			array(
				'old_url'        => '',
				'new_url'        => '',
				'affected_posts' => 0,
				'changed_links'  => 0,
				'failed_count'   => 0,
				'status'         => 'pending',
				'rollback_json'  => array(),
				'created_at'     => self::get_now(),
			)
		);

		$row = array(
			'old_url' =>
				esc_url_raw( $data['old_url'] ),

			'new_url' =>
				esc_url_raw( $data['new_url'] ),

			'affected_posts' =>
				absint( $data['affected_posts'] ),

			'changed_links' =>
				absint( $data['changed_links'] ),

			'failed_count' =>
				absint( $data['failed_count'] ),

			'status' =>
				sanitize_key( $data['status'] ),

			'rollback_json' =>
				self::safe_json_string( $data['rollback_json'] ),

			'created_at' =>
				sanitize_text_field( $data['created_at'] ),
		);

		$result = $wpdb->insert( self::get_table_name( 'url_changes' ), $row );

		return false === $result ? 0 : absint( $wpdb->insert_id );
	}

	/**
	 * Update URL change history row.
	 *
	 * @since 2.1.12
	 *
	 * @param int   $id   URL change ID.
	 * @param array $data Fields to update.
	 * @return int|false
	 */
	public static function update_url_change( $id, $data ) {
		global $wpdb;

		$id    = absint( $id );
		$data  = (array) $data;
		$clean = array();

		if ( ! $id ) {
			return false;
		}

		foreach ( array( 'affected_posts', 'changed_links', 'failed_count', ) as $key ) {
			if ( array_key_exists( $key, $data ) ) {
				$clean[ $key ] = absint( $data[ $key ] );
			}
		}

		if ( array_key_exists( 'status', $data ) ) {
			$clean['status'] = sanitize_key( $data['status'] );
		}

		if ( array_key_exists( 'rollback_json', $data ) ) {
			$clean['rollback_json'] = self::safe_json_string( $data['rollback_json'] );
		}

		if ( empty( $clean ) ) {
			return 0;
		}

		return $wpdb->update( self::get_table_name( 'url_changes' ), $clean, array( 'id' => $id, ) );
	}

	/**
	 * Fetch URL change row by ID.
	 *
	 * @param int $id URL change ID.
	 * @return array|null
	 */
	public static function get_url_change( $id ) {
		global $wpdb;

		$table = self::get_table_name( 'url_changes' );

		return $wpdb->get_row(
			$wpdb->prepare(
				"SELECT *
				FROM {$table}
				WHERE id = %d",
				absint( $id )
			),
			ARRAY_A
		);
	}

	/**
	 * Count URL change rows.
	 *
	 * @return int
	 */
	public static function count_url_changes() {
		global $wpdb;

		$table = self::get_table_name( 'url_changes' );

		return absint( $wpdb->get_var( "SELECT COUNT(*) FROM {$table}" ) ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
	}

	/**
	 * Delete a URL change history row.
	 *
	 * Used after a URL change has been completely undone.
	 *
	 * @param int $id URL change ID.
	 * @return bool
	 */
	public static function delete_url_change( $id ) {
		global $wpdb;

		$id = absint( $id );

		if ( ! $id ) {
			return false;
		}

		$deleted = $wpdb->delete( self::get_table_name( 'url_changes' ), array( 'id' => $id ), array( '%d' ) );

		return false !== $deleted && $deleted > 0;
	}

	/**
	 * Get paginated URL change rows.
	 *
	 * @param int $page Current page.
	 * @param int $per_page Rows per page.
	 * @return array<int,array>
	 */
	public static function get_url_change_rows( $page = 1, $per_page = 10 ) {
		global $wpdb;

		$page = max( 1, absint( $page ) );

		$per_page = max( 1, absint( $per_page ) );

		$offset = ( $page - 1 ) * $per_page;

		$table = self::get_table_name( 'url_changes' );

		return $wpdb->get_results(
			$wpdb->prepare(
				"SELECT
					id,
					old_url,
					new_url,
					affected_posts,
					changed_links,
					failed_count,
					status,
					created_at,
					CASE
						WHEN rollback_json IS NULL
							OR rollback_json = ''
							OR rollback_json = '[]'
						THEN 0
						ELSE 1
					END AS has_rollback
				FROM {$table}
				ORDER BY created_at DESC
				LIMIT %d
				OFFSET %d",
				$per_page,
				$offset
			),
			ARRAY_A
		);
	}

	/**
	 * Find published posts containing a URL in post content.
	 *
	 * Used by URL Changer before applying a safe replacement.
	 *
	 * @param string $old_url URL to search for.
	 * @param int    $limit Batch size.
	 * @param int    $offset Offset.
	 * @return array<int,array>
	 */
	public static function find_posts_containing_url( $old_url, $limit = 200, $offset = 0 ) {
		global $wpdb;

		$limit = max( 1, absint( $limit ) );

		$offset = max( 0, absint( $offset ) );

		$fetch_limit = $limit + $offset;

		$links_table = self::get_table_name( 'links' );

		$old_url = esc_url_raw( trim( (string) $old_url ) );

		$normalized_old = untrailingslashit( $old_url );

		if ( '' === $normalized_old ) {
			$normalized_old = $old_url;
		}

		$rows_by_id = array();

		/*
		* First use the Internal Linking link index.
		*/
		if ( '' !== $links_table && '' !== $normalized_old ) {

			$link_post_ids =
				$wpdb->get_col(
					$wpdb->prepare(
						"SELECT DISTINCT
							source_post_id
						FROM %i
						WHERE
							TRIM(
								TRAILING '/'
								FROM target_url
							) = %s
						ORDER BY
							source_post_id ASC
						LIMIT %d",
						$links_table,
						$normalized_old,
						$fetch_limit
					)
				);

			$link_post_ids = array_values( array_filter( array_map( 'absint', (array) $link_post_ids ) ) );

			if ( ! empty( $link_post_ids ) ) {

				$placeholders = implode( ',', array_fill( 0, count( $link_post_ids ), '%d' ) );

				$indexed_rows =
					$wpdb->get_results(
						$wpdb->prepare(
							"SELECT
								ID,
								post_title,
								post_type,
								post_status,
								post_content
							FROM {$wpdb->posts}
							WHERE
								post_status = 'publish'
								AND ID IN (
									{$placeholders}
								)",
							$link_post_ids
						),
						ARRAY_A
					);

				foreach ( (array) $indexed_rows as $row ) {
					$rows_by_id[ absint( $row['ID'] ) ] = $row;
				}
			}
		}

		/*
		* Raw-content fallback.
		*
		* This protects the URL Changer when:
		* - Content Index has not been run.
		* - The links table is stale.
		* - A post was edited before re-indexing.
		*/
		$like =
			'%' .
			$wpdb->esc_like( $normalized_old ) .
			'%';

		$raw_rows =
			$wpdb->get_results(
				$wpdb->prepare(
					"SELECT
						ID,
						post_title,
						post_type,
						post_status,
						post_content
					FROM {$wpdb->posts}
					WHERE
						post_status = 'publish'
						AND post_content LIKE %s
					ORDER BY ID ASC
					LIMIT %d",
					$like,
					$fetch_limit
				),
				ARRAY_A
			);

		foreach ( (array) $raw_rows as $row ) {
			$rows_by_id[ absint( $row['ID'] ) ] = $row;
		}

		ksort( $rows_by_id, SORT_NUMERIC );

		return array_slice( array_values( $rows_by_id ), $offset, $limit );
	}

	/**
	 * Normalize a value into a safe JSON string.
	 *
	 * @param mixed $value Raw value.
	 * @return string JSON string.
	 */
	private static function safe_json_string( $value ) {
		if ( is_array( $value ) || is_object( $value ) ) {
			$encoded = wp_json_encode( $value );
			return $encoded ? $encoded : '[]';
		}

		$value = (string) $value;
		json_decode( $value, true );

		if ( JSON_ERROR_NONE === json_last_error() ) {
			return $value;
		}

		return '[]';
	}

	public static function get_auto_linking_settings() {
		$defaults = array(
			'enabled' => 1,
			'internal_only' => 1,
			'default_max_links_post' => 1,
			'default_max_keyword' => 1,
			'default_post_types' => array( 'post', 'page' ),
			'manual_review' => 1,
			'case_sensitive' => 0,
			'allow_duplicate_target' => 0,
			'require_target_published' => 1,
			'prioritize_long_tail' => 1,
		);
		$stored = get_option( 'srk_il_auto_linking_settings', array() );
		return wp_parse_args( is_array( $stored ) ? $stored : array(), $defaults );
	}

	public static function save_auto_linking_settings( $settings ) {
		$clean = array(
			'enabled' => ! empty( $settings['enabled'] ) ? 1 : 0,
			'internal_only' => ! empty( $settings['internal_only'] ) ? 1 : 0,
			'default_max_links_post' => max( 1, absint( $settings['default_max_links_post'] ?? 1 ) ),
			'default_max_keyword' => max( 1, absint( $settings['default_max_keyword'] ?? 1 ) ),
			'default_post_types' => array_values( array_filter( array_map( 'sanitize_key', (array) ( $settings['default_post_types'] ?? array( 'post', 'page' ) ) ) ) ),
			'manual_review' => ! empty( $settings['manual_review'] ) ? 1 : 0,
			'case_sensitive' => ! empty( $settings['case_sensitive'] ) ? 1 : 0,
			'allow_duplicate_target' => ! empty( $settings['allow_duplicate_target'] ) ? 1 : 0,
			'require_target_published' => ! empty( $settings['require_target_published'] ) ? 1 : 0,
			'prioritize_long_tail' => ! empty( $settings['prioritize_long_tail'] ) ? 1 : 0,
		);
		update_option( 'srk_il_auto_linking_settings', $clean, false );
		return $clean;
	}

	public static function auto_rule_conflict_exists( $keyword, $target_url, $exclude_rule_id = 0 ) {
		global $wpdb;

		$table = self::get_table_name( 'auto_rules' );

		if ( '' === $table ) {
			return false;
		}

		$keyword_hash = self::get_keyword_hash( $keyword );

		$target_url = esc_url_raw( $target_url );

		$exclude_rule_id = absint( $exclude_rule_id );

		/*
		* Updating an existing rule:
		* ignore that rule itself while detecting conflicts.
		*/
		if ( $exclude_rule_id ) {
			return (bool) $wpdb->get_var(
				$wpdb->prepare(
					"SELECT id
					FROM %i
					WHERE keyword_hash = %s
						AND target_url = %s
						AND id <> %d
					LIMIT 1",
					$table,
					$keyword_hash,
					$target_url,
					$exclude_rule_id
				)
			);
		}

		/*
		* Creating a new rule:
		* detect any existing keyword + target combination.
		*/
		return (bool) $wpdb->get_var(
			$wpdb->prepare(
				"SELECT id
				FROM %i
				WHERE keyword_hash = %s
					AND target_url = %s
				LIMIT 1",
				$table,
				$keyword_hash,
				$target_url
			)
		);
	}

	public static function insert_auto_rule( $data ) {
		global $wpdb;
		$settings = self::get_auto_linking_settings();
		$now      = self::get_now();
		$keyword = trim( preg_replace( '/\s+/u', ' ', sanitize_text_field( $data['keyword'] ?? '' ) ) );
		$url = esc_url_raw( $data['target_url'] ?? '' );
		if ( '' === $keyword || '' === $url ) { return 0; }
		$mode = isset( $data['selection_mode'] ) && 'auto' === $data['selection_mode'] ? 'auto' : 'manual';
		$row = array(
			'keyword' => $keyword,
			'keyword_hash' => self::get_keyword_hash( $keyword ),
			'target_post_id' => url_to_postid( $url ) ?: null,
			'target_url' => $url,
			'status' => 'active',
			'priority' => absint( $data['priority'] ?? 10 ),
			'selection_mode' => $mode,
			'manual_review' => 'manual' === $mode ? 1 : 0,
			'auto_apply' => 'auto' === $mode ? 1 : 0,
			'case_sensitive' => isset( $data['case_sensitive'] ) ? absint( $data['case_sensitive'] ) : absint( $settings['case_sensitive'] ),
			'only_once_per_post' => 1,
			'max_links_per_post' => max( 1, absint( $data['max_links_per_post'] ?? $settings['default_max_links_post'] ) ),
			'max_links_per_keyword' => max( 1, absint( $data['max_links_per_keyword'] ?? $settings['default_max_keyword'] ) ),
			'allow_duplicate_target' => isset( $data['allow_duplicate_target'] ) ? absint( $data['allow_duplicate_target'] ) : absint( $settings['allow_duplicate_target'] ),
			'add_if_existing_link' => 0,
			'require_target_published' => isset( $data['require_target_published'] ) ? absint( $data['require_target_published'] ) : absint( $settings['require_target_published'] ),
			'override_one_link_per_sentence' => 0,
			'prioritize_long_tail' => absint( $settings['prioritize_long_tail'] ),
			'apply_after_date' => ! empty( $data['apply_after_date'] ) ? sanitize_text_field( $data['apply_after_date'] ) : null,
			'post_types_json' => self::safe_json_string( ! empty( $data['post_types'] ) ? array_values( array_map( 'sanitize_key', (array) $data['post_types'] ) ) : $settings['default_post_types'] ),
			'taxonomies_json' => self::safe_json_string( array() ),
			'categories_json' => self::safe_json_string( ! empty( $data['categories'] ) ? array_values( array_map( 'absint', (array) $data['categories'] ) ) : array() ),
			'tags_json' => self::safe_json_string( ! empty( $data['tags'] ) ? array_values( array_map( 'absint', (array) $data['tags'] ) ) : array() ),
			'excluded_posts_json' => '[]', 'excluded_categories_json' => '[]', 'excluded_tags_json' => '[]',
			'matched_posts_json' => '[]', 'applied_links_json' => '[]', 'scan_log_json' => '[]',
			'links_created' => 0, 'removed_count' => 0, 'failed_count' => 0, 'last_scan_at' => null, 'last_scan_duration' => 0,
			'created_at' => $now, 'updated_at' => $now,
		);
		$wpdb->insert( self::get_table_name( 'auto_rules' ), $row );
		return absint( $wpdb->insert_id );
	}

	public static function update_auto_rule_scan_data( $rule_id, $data ) {
		global $wpdb;
		$row = array( 'updated_at' => self::get_now() );
		if ( isset( $data['matched_posts_json'] ) ) { $row['matched_posts_json'] = self::safe_json_string( $data['matched_posts_json'] ); }
		if ( isset( $data['last_scan_at'] ) ) { $row['last_scan_at'] = sanitize_text_field( $data['last_scan_at'] ); }
		if ( isset( $data['last_scan_duration'] ) ) { $row['last_scan_duration'] = floatval( $data['last_scan_duration'] ); }
		return $wpdb->update( self::get_table_name( 'auto_rules' ), $row, array( 'id' => absint( $rule_id ) ) );
	}

	public static function update_auto_rule_tracking( $rule_id, $tracking, $links_added = 0, $links_removed = 0 ) {
		global $wpdb;
		$table = self::get_table_name( 'auto_rules' );
		return $wpdb->query( $wpdb->prepare( "UPDATE {$table} SET applied_links_json=%s, links_created=links_created+%d, removed_count=removed_count+%d, updated_at=%s WHERE id=%d", self::safe_json_string( $tracking ), absint( $links_added ), absint( $links_removed ), self::get_now(), absint( $rule_id ) ) );
	}

	/**
	 * Replace auto-rule tracking data and increment removed count.
	 *
	 * This method is used when existing auto links are removed from post content.
	 *
	 * @param int   $rule_id       Auto-link rule ID.
	 * @param array $tracking      Updated tracking records.
	 * @param int   $removed_count Number of links removed.
	 * @return int|false Number of affected rows or false on error.
	 */
	public static function update_auto_rule_removed_tracking( $rule_id, $tracking, $removed_count ) {
		global $wpdb;

		$rule_id       = absint( $rule_id );
		$removed_count = absint( $removed_count );

		if ( ! $rule_id ) {
			return false;
		}

		$table = self::get_table_name( 'auto_rules' );

		return $wpdb->query(
			$wpdb->prepare(
				"UPDATE {$table}
				SET applied_links_json = %s,
					removed_count = removed_count + %d,
					updated_at = %s
				WHERE id = %d",
				self::safe_json_string( $tracking ),
				$removed_count,
				self::get_now(),
				$rule_id
			)
		); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
	}

	public static function get_auto_rule( $rule_id ) {
		global $wpdb;

		$table = self::get_table_name( 'auto_rules' );

		return $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$table} WHERE id=%d LIMIT 1", absint( $rule_id ) ), ARRAY_A );
	}

	public static function get_auto_rules( $args = array() ) {
		global $wpdb;

		if ( is_string( $args ) ) {
			$args = array( 'status' => $args );
		}

		$args = wp_parse_args( $args, array( 'status' => 'all', 'limit' => 50, 'offset' => 0, ) );

		$table    = self::get_table_name( 'auto_rules' );
		$where    = 'WHERE 1=1';
		$sql_args = array();

		if ( 'all' !== $args['status'] ) {
			$where      .= ' AND status=%s';
			$sql_args[] = sanitize_key( $args['status'] );
		}

		$sql_args[] = max( 1, absint( $args['limit'] ) );
		$sql_args[] = max( 0, absint( $args['offset'] ) );

		return $wpdb->get_results(
			$wpdb->prepare( "SELECT * FROM {$table} {$where} ORDER BY priority DESC, id DESC LIMIT %d OFFSET %d", $sql_args ),
			ARRAY_A
		);
	}

	public static function delete_auto_rule( $rule_id ) {
		global $wpdb;

		return $wpdb->delete( self::get_table_name( 'auto_rules' ), array( 'id' => absint( $rule_id ), ) );
	}

	public static function update_auto_rule_status( $rule_id, $status ) {
		global $wpdb;

		$rule_id = absint( $rule_id );
		$status  = sanitize_key( $status );

		if ( ! $rule_id || ! in_array( $status, array( 'active', 'paused' ), true ) ) {
			return false;
		}

		return $wpdb->update(
			self::get_table_name( 'auto_rules' ),
			array( 'status' => $status, 'updated_at' => self::get_now(), ),
			array( 'id' => $rule_id, )
		);
	}

	/**
	 * Get Auto Linking summary counts.
	 *
	 * links_created represents the historical number of inserted links.
	 * removed_links represents the historical number of removed links.
	 * active_links represents links that should currently remain active.
	 *
	 * @return array<string,int>
	 */
	public static function get_auto_linking_summary() {
		global $wpdb;

		$cached = self::get_request_cache(
			'auto_linking_summary'
		);

		if ( null !== $cached ) {
			return $cached;
		}

		$table = self::get_table_name(
			'auto_rules'
		);

		if ( '' === $table ) {
			return array(
				'total_rules'         => 0,
				'active_rules'        => 0,
				'active_links'        => 0,
				'total_links_created' => 0,
				'links_created'       => 0,
				'removed_links'       => 0,
			);
		}

		$row = $wpdb->get_row(
			$wpdb->prepare(
				"SELECT
					COUNT(*) AS total_rules,

					SUM(
						CASE
							WHEN status = %s THEN 1
							ELSE 0
						END
					) AS active_rules,

					COALESCE(
						SUM(links_created),
						0
					) AS total_created,

					COALESCE(
						SUM(removed_count),
						0
					) AS total_removed

				FROM %i",
				'active',
				$table
			),
			ARRAY_A
		);

		$total_created =
			absint(
				$row['total_created'] ?? 0
			);

		$total_removed =
			absint(
				$row['total_removed'] ?? 0
			);

		$summary = array(
			'total_rules' =>
				absint(
					$row['total_rules'] ?? 0
				),

			'active_rules' =>
				absint(
					$row['active_rules'] ?? 0
				),

			'active_links' =>
				max(
					0,
					$total_created - $total_removed
				),

			'total_links_created' =>
				$total_created,

			'links_created' =>
				$total_created,

			'removed_links' =>
				$total_removed,
		);

		return self::set_request_cache(
			'auto_linking_summary',
			$summary
		);
	}

	/**
	 * Update auto-link rule.
	 */
	public static function update_auto_rule( $rule_id, $data ) {
		global $wpdb;

		$rule_id = absint( $rule_id );

		if ( ! $rule_id ) {
			return new WP_Error( 'srk_auto_invalid_rule', __( 'Invalid rule ID.', 'seo-repair-kit' ) );
		}

		$settings = self::get_auto_linking_settings();
		$keyword  = trim( preg_replace( '/\s+/u', ' ', sanitize_text_field( $data['keyword'] ?? '' ) ) );
		$url      = esc_url_raw( $data['target_url'] ?? '' );

		if ( '' === $keyword || '' === $url ) {
			return new WP_Error( 'srk_auto_missing_data', __( 'Keyword and destination URL are required.', 'seo-repair-kit' ) );
		}

		$mode = isset( $data['selection_mode'] ) && 'auto' === $data['selection_mode'] ? 'auto' : 'manual';

		$row = array(
			'keyword'                  => $keyword,
			'keyword_hash'             => self::get_keyword_hash( $keyword ),
			'target_post_id'           => url_to_postid( $url ) ?: null,
			'target_url'               => $url,
			'priority'                 => absint( $data['priority'] ?? 10 ),
			'selection_mode'           => $mode,
			'manual_review'            => 'manual' === $mode ? 1 : 0,
			'auto_apply'               => 'auto' === $mode ? 1 : 0,
			'case_sensitive'           => isset( $data['case_sensitive'] ) ? absint( $data['case_sensitive'] ) : absint( $settings['case_sensitive'] ),
			'max_links_per_post'       => max( 1, absint( $data['max_links_per_post'] ?? $settings['default_max_links_post'] ) ),
			'max_links_per_keyword'    => max( 1, absint( $data['max_links_per_keyword'] ?? $settings['default_max_keyword'] ) ),
			'allow_duplicate_target'   => isset( $data['allow_duplicate_target'] ) ? absint( $data['allow_duplicate_target'] ) : absint( $settings['allow_duplicate_target'] ),
			'require_target_published' => isset( $data['require_target_published'] ) ? absint( $data['require_target_published'] ) : absint( $settings['require_target_published'] ),
			'apply_after_date'         => ! empty( $data['apply_after_date'] ) ? sanitize_text_field( $data['apply_after_date'] ) : null,
			'post_types_json'          => self::safe_json_string( ! empty( $data['post_types'] ) ? array_values( array_map( 'sanitize_key', (array) $data['post_types'] ) ) : $settings['default_post_types'] ),
			'categories_json'          => self::safe_json_string( ! empty( $data['categories'] ) ? array_values( array_map( 'absint', (array) $data['categories'] ) ) : array() ),
			'tags_json'                => self::safe_json_string( ! empty( $data['tags'] ) ? array_values( array_map( 'absint', (array) $data['tags'] ) ) : array() ),
			'updated_at'               => self::get_now(),
		);

		return $wpdb->update( self::get_table_name( 'auto_rules' ), $row, array( 'id' => $rule_id, ) );
	}

	/**
	 * Insert or update a post embedding.
	 *
	 * @param array $data Embedding data.
	 * @return int|WP_Error Embedding row ID on success.
	 */
	public static function upsert_embedding( $data ) {
		global $wpdb;

		$now = self::get_now();

		$row = wp_parse_args(
			$data,
			array(
				'post_id'        => 0,
				'embedding_json' => '[]',
				'model' 		 => 'nvidia/nemotron-3-embed-1b:free',
				'content_hash'   => '',
				'status'         => 'ready',
				'created_at'     => $now,
				'updated_at'     => $now,
			)
		);

		$row['post_id']        = absint( $row['post_id'] );
		$row['embedding_json'] = self::safe_json_string( $row['embedding_json'] );
		$row['model'] = sanitize_text_field( $row['model'] );
		$row['content_hash'] = sanitize_text_field( $row['content_hash'] );
		$row['status'] = sanitize_key( $row['status'] );

		if ( ! $row['post_id'] ) {
			return new WP_Error( 'srk_embedding_invalid_post', __( 'Invalid post ID for embedding.', 'seo-repair-kit' ) );
		}

		$table = self::get_table_name( 'embeddings' );

		$existing_id = $wpdb->get_var(
			$wpdb->prepare(
				"SELECT id
				FROM {$table}
				WHERE post_id = %d
				LIMIT 1",
				$row['post_id']
			)
		);

		if ( $existing_id ) {

			unset( $row['created_at'] );

			$row['updated_at'] = $now;

			$result = $wpdb->update( $table, $row, array( 'id' => absint( $existing_id ), ) );

			if ( false === $result ) {

				return new WP_Error(
					'srk_embedding_db_update_failed',
					$wpdb->last_error
						? $wpdb->last_error
						: __( 'Unable to update the embedding record.', 'seo-repair-kit' )
				);
			}

			return absint( $existing_id );
		}

		$result = $wpdb->insert( $table, $row );

		if ( false === $result ) {

			return new WP_Error(
				'srk_embedding_db_insert_failed',
				$wpdb->last_error
					? $wpdb->last_error
					: __( 'Unable to insert the embedding record.', 'seo-repair-kit' )
			);
		}

		$embedding_id = absint( $wpdb->insert_id );

		if ( ! $embedding_id ) {
			return new WP_Error(
				'srk_embedding_missing_insert_id',
				__( 'Embedding was inserted but no database ID was returned.', 'seo-repair-kit' )
			);
		}

		return $embedding_id;
	}

	/**
	 * Get embedding by post ID.
	 *
	 * @param int $post_id Post ID.
	 * @return array|null
	 */
	public static function get_embedding_by_post_id( $post_id ) {
		global $wpdb;

		$table = self::get_table_name( 'embeddings' );

		return $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$table} WHERE post_id = %d LIMIT 1", absint( $post_id ) ), ARRAY_A ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
	}

	/**
	 * Get embeddings batch for semantic processing.
	 *
	 * @param int $limit  Batch size.
	 * @param int $offset Offset.
	 * @return array<int,array>
	 */
	public static function get_embeddings_batch( $limit = 10, $offset = 0 ) {
		global $wpdb;

		$table = self::get_table_name( 'embeddings' );

		return $wpdb->get_results(
			$wpdb->prepare(
				"SELECT * FROM {$table} WHERE status = 'ready' ORDER BY post_id ASC LIMIT %d OFFSET %d",
				max( 1, absint( $limit ) ),
				max( 0, absint( $offset ) )
			),
			ARRAY_A
		); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
	}

	/**
	 * Count posts missing embeddings.
	 *
	 * @return int
	 */
	public static function count_posts_missing_embeddings() {
		global $wpdb;

		$content_table = self::get_table_name( 'content_index' );

		$embeddings_table = self::get_table_name( 'embeddings' );

		$config =
			SRK_Internal_Linking_AI_Provider::
				resolve_embedding_config();

		if ( is_wp_error( $config ) ) {

			return absint(
				$wpdb->get_var(
					"SELECT COUNT(*)
					FROM {$content_table}"
				)
			);
		}

		$current_profile =
			SRK_Internal_Linking_AI_Provider::
				get_embedding_profile(
					sanitize_text_field(
						$config['model']
					),
					sanitize_key(
						$config['provider']
					),
					esc_url_raw(
						$config['base_url']
							?? ''
					)
				);

		return absint(
			$wpdb->get_var(
				$wpdb->prepare(
					"
					SELECT COUNT(*)

					FROM {$content_table} ci

					LEFT JOIN {$embeddings_table} e
						ON e.post_id = ci.post_id

					WHERE
						e.id IS NULL

						OR e.status <> 'ready'

						OR e.model <> %s
					",
					$current_profile
				)
			)
		);
	}
	
	/**
	 * Get WordPress posts eligible for AI processing.
	 *
	 * AI source content comes directly from wp_posts.
	 *
	 * @param int $limit  Batch size.
	 * @param int $offset Offset.
	 * @return array
	 */
	public static function get_ai_source_posts( $limit = 10, $offset = 0 ) {
		global $wpdb;

		$settings = class_exists( 'SRK_Internal_Linking_Settings' ) ? SRK_Internal_Linking_Settings::get() : array();

		$post_types = ! empty( $settings['post_types'] )
			? array_values( array_filter( array_map( 'sanitize_key', (array) $settings['post_types'] ) ) )
			: array( 'post', 'page' );

		if ( empty( $post_types ) ) {
			return array();
		}

		$placeholders = implode( ',', array_fill( 0, count( $post_types ), '%s' ) );

		$sql = "
			SELECT
				ID AS post_id
			FROM {$wpdb->posts}
			WHERE post_status = 'publish'
				AND post_type IN ({$placeholders})
				AND post_password = ''
			ORDER BY ID ASC
			LIMIT %d
			OFFSET %d
		";

		$params = array_merge( $post_types, array( max( 1, absint( $limit ) ), max( 0, absint( $offset ) ), ) );

		return $wpdb->get_results( $wpdb->prepare( $sql, $params ), ARRAY_A );
	}

	/**
	 * Check whether the source already has an active AI
	 * opportunity using this anchor.
	 *
	 * @param int    $source_post_id Source post ID.
	 * @param string $anchor Anchor text.
	 * @return bool
	 */
	public static function ai_opportunity_anchor_exists( $source_post_id, $anchor ) {
		global $wpdb;

		$source_post_id = absint( $source_post_id );

		$anchor = sanitize_text_field( $anchor );

		if ( ! $source_post_id || '' === $anchor ) {
			return false;
		}

		$table = self::get_table_name( 'opportunities' );

		return (bool) $wpdb->get_var(
			$wpdb->prepare(
				"SELECT id
				FROM {$table}
				WHERE source_post_id = %d
					AND anchor_text = %s
					AND selected_type = 'ai'
					AND status IN (
						'building',
						'pending',
						'inserted'
					)
				LIMIT 1",
				$source_post_id,
				$anchor
			)
		); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
	}

	/**
	 * Get AI pipeline summary counts.
	 *
	 * Semantic links displayed in the UI are canonical-merge-ready semantic
	 * candidates. A semantic candidate becomes available after its staging
	 * status changes to "merged".
	 *
	 * @return array<string,int>
	 */
	public static function get_ai_pipeline_summary() {
		global $wpdb;

		$embeddings_table  = self::get_table_name( 'embeddings' );
		$opportunity_table = self::get_table_name( 'opportunities' );

		if (
			'' === $embeddings_table ||
			'' === $opportunity_table
		) {
			return array(
				'embeddings_ready'           => 0,
				'embeddings_pending'         => 0,
				'ai_opportunities'           => 0,

				/*
				* Backward-compatible aliases.
				*
				* These keys no longer represent a separate semantic table.
				* Keep them temporarily so older consumers do not break.
				*/
				'semantic_links'             => 0,
				'semantic_pending'           => 0,
				'semantic_failed'            => 0,
				'semantic_skipped'           => 0,
				'canonical_ai_opportunities' => 0,
			);
		}

		$embeddings_ready = absint(
			$wpdb->get_var(
				$wpdb->prepare(
					"SELECT COUNT(*)
					FROM %i
					WHERE status = %s",
					$embeddings_table,
					'ready'
				)
			)
		);

		$embeddings_pending =
			self::count_posts_missing_embeddings();

		/*
		* Canonical AI opportunities.
		*
		* building = currently being generated by an AI pipeline
		* pending  = available for review/application
		* inserted = previously accepted/applied AI opportunity
		*
		* ignored/removed rows are intentionally excluded.
		*/
		$ai_opportunities = absint(
			$wpdb->get_var(
				$wpdb->prepare(
					"SELECT COUNT(*)
					FROM %i
					WHERE selected_type = %s
						AND status IN (%s, %s, %s)",
					$opportunity_table,
					'ai',
					'building',
					'pending',
					'inserted'
				)
			)
		);

		return array(
			'embeddings_ready'   => $embeddings_ready,
			'embeddings_pending' => $embeddings_pending,
			'ai_opportunities'   => $ai_opportunities,

			/*
			* Compatibility only.
			*
			* Nothing here queries srk_il_semantic_links anymore.
			*/
			'semantic_links'             => $ai_opportunities,
			'semantic_pending'           => 0,
			'semantic_failed'            => 0,
			'semantic_skipped'           => 0,
			'canonical_ai_opportunities' => $ai_opportunities,
		);
	}

	/**
	 * Get complete real-time dashboard data.
	 *
	 * @return array<string,mixed>
	 */
	public static function get_dashboard_data() {
		global $wpdb;

		$content_table     = self::get_table_name( 'content_index' );
		$links_table       = self::get_table_name( 'links' );
		$opportunity_table = self::get_table_name( 'opportunities' );
		$auto_rules_table  = self::get_table_name( 'auto_rules' );
		$scan_runs_table   = self::get_table_name( 'scan_runs' );

		$content_summary = self::get_content_index_summary();
		$op_summary      = self::get_link_opportunities_summary();
		$orphan_summary  = self::get_orphan_summary();
		$auto_summary    = self::get_auto_linking_summary();

		$indexed_total = absint( $content_summary['total'] ?? 0 );

		$internal_links = absint(
			$wpdb->get_var(
				$wpdb->prepare(
					"SELECT COUNT(*)
					FROM %i
					WHERE is_internal = %d",
					$links_table,
					1
				)
			)
		);

		$pending_opportunities = absint( $op_summary['pending'] ?? 0 );

		$auto_links = absint( $auto_summary['active_links'] ?? 0 );

		$critical_orphans = absint( $orphan_summary['critical'] ?? 0 );

		$low_orphans = absint( $orphan_summary['low'] ?? 0 );

		$healthy_content = absint( $orphan_summary['healthy'] ?? 0 );

		$orphan_total = $critical_orphans + $low_orphans;

		/*
		* Health calculation:
		* - Healthy content contributes 100%.
		* - Low inbound content contributes 50%.
		* - Critical orphan content contributes 0%.
		* - Ignored content is excluded.
		*/
		$health_total = ( $healthy_content + $low_orphans + $critical_orphans );

		$health_score = $health_total > 0
			? absint( round( ( $healthy_content + ( $low_orphans * 0.5 ) ) / $health_total * 100 ) )
			: 0;

		$last_index = $wpdb->get_var(
			"SELECT MAX(last_indexed)
			FROM {$content_table}"
		); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared

		$latest_opportunity_activity = $wpdb->get_var(
			"SELECT COALESCE(completed_at, updated_at)
			FROM {$scan_runs_table}
			WHERE scan_type IN (
				'opportunities',
				'ai_pipeline',
				'single_opportunities'
			)
				AND status = 'completed'
			ORDER BY COALESCE(completed_at, updated_at) DESC, id DESC
			LIMIT 1"
		); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared

		if ( ! $latest_opportunity_activity ) {
			$latest_opportunity_activity = $wpdb->get_var(
				"SELECT MAX(updated_at)
				FROM {$opportunity_table}"
			); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		}

		$latest_content_scan = $wpdb->get_var(
			"SELECT COALESCE(completed_at, updated_at)
			FROM {$scan_runs_table}
			WHERE scan_type = 'content_index'
				AND status = 'completed'
			ORDER BY COALESCE(completed_at, updated_at) DESC, id DESC
			LIMIT 1"
		); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared

		if ( ! $latest_content_scan ) {
			$latest_content_scan = $last_index;
		}

		$latest_auto_activity = $wpdb->get_var(
			"SELECT MAX(
				COALESCE(
					last_scan_at,
					updated_at
				)
			)
			FROM {$auto_rules_table}"
		); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared

		if ( $critical_orphans > 0 && $low_orphans > 0 ) {
			$health_message = sprintf(
				/* translators: 1: critical pages, 2: low inbound pages */
				__( '%1$d pages have no inbound links and %2$d pages have only one inbound link.', 'seo-repair-kit' ),
				$critical_orphans,
				$low_orphans
			);
		} elseif ( $critical_orphans > 0 ) {
			$health_message = sprintf(
				/* translators: %d: critical orphan pages */
				_n( '%d page has no inbound links.', '%d pages have no inbound links.', $critical_orphans, 'seo-repair-kit' ),
				$critical_orphans
			);
		} elseif ( $low_orphans > 0 ) {
			$health_message = sprintf(
				/* translators: %d: low inbound pages */
				_n(
					'%d page has only one inbound link.',
					'%d pages have only one inbound link.',
					$low_orphans,
					'seo-repair-kit'
				),
				$low_orphans
			);
		} else {
			$health_message = __( 'All indexed content has healthy inbound link coverage.', 'seo-repair-kit' );
		}

		return array(
			'summary' => array(
				'indexed_total'        => $indexed_total,
				'internal_links'       => $internal_links,
				'pending_opportunities'=> $pending_opportunities,
				'auto_links'           => $auto_links,
				'orphan_total'         => $orphan_total,
				'health_score'         => $health_score,
				'health_message'       => $health_message,
				'last_index'           => $last_index
					? mysql2date( get_option( 'date_format' ) . ' ' . get_option( 'time_format' ), $last_index )
					: __( 'Never', 'seo-repair-kit' ),
			),

			'activity' => array(
				array(
					'type' => 'opportunities',
					'text' => sprintf(
						_n( '%d current link opportunity', '%d current link opportunities', $pending_opportunities, 'seo-repair-kit' ),
						$pending_opportunities
					),
					'time' => self::format_dashboard_relative_time( $latest_opportunity_activity ),
				),
				array(
					'type' => 'orphans',
					'text' => sprintf(
						_n( '%d orphan page detected', '%d orphan pages detected', $orphan_total, 'seo-repair-kit' ),
						$orphan_total
					),
					'time' => self::format_dashboard_relative_time( $last_index ),
				),
				array(
					'type' => 'auto',
					'text' => sprintf( _n( '%d active auto link', '%d active auto links', $auto_links, 'seo-repair-kit' ), $auto_links ),
					'time' => self::format_dashboard_relative_time( $latest_auto_activity ),
				),
				array(
					'type' => 'index',
					'text' => __( 'Content index refreshed', 'seo-repair-kit' ),
					'time' => self::format_dashboard_relative_time( $latest_content_scan ),
				),
			),
		);
	}

	/**
	 * Format a stored MySQL datetime as relative dashboard time.
	 *
	 * @param string|null $datetime MySQL datetime.
	 * @return string
	 */
	private static function format_dashboard_relative_time( $datetime ) {
		if ( empty( $datetime ) ) {
			return __( 'No activity yet', 'seo-repair-kit' );
		}

		$timestamp = mysql2date( 'U', $datetime );

		if ( ! $timestamp ) {
			return __( 'No activity yet', 'seo-repair-kit' );
		}

		return sprintf(
			/* translators: %s: human-readable time difference */
			__( '%s ago', 'seo-repair-kit' ),
			human_time_diff( $timestamp, current_time( 'timestamp' ) )
		);
	}

	public static function count_ai_source_posts() {
		global $wpdb;

		$settings = class_exists( 'SRK_Internal_Linking_Settings' ) ? SRK_Internal_Linking_Settings::get() : array();

		$post_types = ! empty( $settings['post_types'] )
			? array_values( array_filter( array_map( 'sanitize_key', (array) $settings['post_types'] ) ) )
			: array( 'post', 'page' );

		if ( empty( $post_types ) ) {
			return 0;
		}

		$placeholders = implode( ',', array_fill( 0, count( $post_types ), '%s' ) );

		$sql = "
			SELECT COUNT(ID)
			FROM {$wpdb->posts}
			WHERE post_status = 'publish'
				AND post_type IN ({$placeholders})
				AND post_password = ''
		";

		return absint( $wpdb->get_var( $wpdb->prepare( $sql, $post_types ) ) );
	}

}
// phpcs:enable WordPress.DB.DirectDatabaseQuery.DirectQuery
// phpcs:enable WordPress.DB.DirectDatabaseQuery.NoCaching
