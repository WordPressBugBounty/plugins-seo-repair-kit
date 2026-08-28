<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Smart Redirect Runtime.
 *
 * Handles frontend 404 requests and redirects broken internal singular URLs
 * to their enabled post-type archive page.
 *
 * @since 2.1.7
 */
class SeoRepairKit_SmartRedirect_Runtime {

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
	 * Register frontend hook.
	 */
	public function __construct() {
		add_action( 'template_redirect', array( $this, 'maybe_redirect_broken_singular_url' ), 1 );
	}

	/**
	 * Maybe redirect current 404 URL to post-type archive.
	 *
	 * @return void
	 */
	public function maybe_redirect_broken_singular_url() {
		global $wpdb;

		if ( is_admin() || wp_doing_ajax() || wp_doing_cron() ) {
			return;
		}

		if ( ! is_404() ) {
			return;
		}

		if ( ! class_exists( 'SeoRepairKit_SmartRedirect_Helper' ) || ! class_exists( 'SeoRepairKit_SmartRedirect_Generator' ) ) {
			return;
		}

		if ( ! SeoRepairKit_SmartRedirect_Helper::is_feature_enabled() ) {
			return;
		}

		$current_url = $this->get_current_url();

		if ( '' === $current_url ) {
			return;
		}

		$source_url = SeoRepairKit_SmartRedirect_Helper::normalize_url( $current_url );

		if ( '' === $source_url ) {
			return;
		}

		$post_type = SeoRepairKit_SmartRedirect_Helper::detect_post_type_from_url( $source_url );

		if ( ! $post_type || ! SeoRepairKit_SmartRedirect_Helper::is_post_type_enabled( $post_type ) ) {
			return;
		}

		if ( ! SeoRepairKit_SmartRedirect_Helper::is_singular_style_url_for_post_type( $source_url, $post_type ) ) {
			return;
		}

		$target_url = SeoRepairKit_SmartRedirect_Helper::get_post_type_archive_url( $post_type );

		if ( '' === $target_url ) {
			return;
		}

		$target_url = SeoRepairKit_SmartRedirect_Helper::normalize_url( $target_url );

		if ( '' === $target_url || $target_url === $source_url ) {
			return;
		}

		$smart_table     = self::get_table_name( 'smart_redirects' );
		$smart_table_sql = self::get_table_identifier( 'smart_redirects' );

		// phpcs:disable PluginCheck.Security.DirectDB.UnescapedDBParameter -- $smart_table_sql is a plugin-owned identifier from get_table_identifier(); request values are prepared.
		$smart_exists = ( $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $smart_table ) ) === $smart_table ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- Defensive recovery check before frontend Smart Redirect lookup.

		if ( ! $smart_exists ) {
			return;
		}

		$row = $wpdb->get_row( // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- Frontend redirect lookup must be real-time to respect active/inactive changes.
			$wpdb->prepare(
				"SELECT * FROM {$smart_table_sql} WHERE source_url = %s AND post_type = %s LIMIT 1",
				$source_url,
				$post_type
			),
			ARRAY_A
		);
		// phpcs:enable PluginCheck.Security.DirectDB.UnescapedDBParameter

		if ( empty( $row ) ) {
			$row = SeoRepairKit_SmartRedirect_Generator::create_redirect_for_url( $source_url, $post_type, true );
		}

		if ( empty( $row ) || empty( $row['target_url'] ) ) {
			return;
		}

		if ( ! empty( $row['status'] ) && 'inactive' === $row['status'] ) {
			return;
		}

		$redirect_to = SeoRepairKit_SmartRedirect_Helper::normalize_url( $row['target_url'] );

		if ( '' === $redirect_to || $redirect_to === $source_url ) {
			return;
		}

		$this->increase_hit_count( $row );

		wp_safe_redirect( $redirect_to, 301, 'SEO Repair Kit Smart Redirect' );
		exit;
	}

	/**
	 * Get current request URL.
	 *
	 * @return string
	 */
	private function get_current_url() {
		if ( empty( $_SERVER['HTTP_HOST'] ) || empty( $_SERVER['REQUEST_URI'] ) ) {
			return '';
		}

		$scheme      = is_ssl() ? 'https' : 'http';
		$host        = sanitize_text_field( wp_unslash( $_SERVER['HTTP_HOST'] ) );
		$request_uri = esc_url_raw( wp_unslash( $_SERVER['REQUEST_URI'] ) );

		if ( '' === $host || '' === $request_uri ) {
			return '';
		}

		return $scheme . '://' . $host . $request_uri;
	}

	/**
	 * Increase hit count for linked Redirection Manager record.
	 *
	 * @param array $row Smart redirect row.
	 * @return void
	 */
	private function increase_hit_count( $row ) {
		global $wpdb;

		if ( empty( $row['redirection_id'] ) ) {
			return;
		}

		$redir_table     = self::get_table_name( 'redirections' );
		$redir_table_sql = self::get_table_identifier( 'redirections' );

		// phpcs:disable PluginCheck.Security.DirectDB.UnescapedDBParameter -- $redir_table_sql is a plugin-owned identifier from get_table_identifier(); row ID is prepared.
		$redir_exists = ( $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $redir_table ) ) === $redir_table ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- Defensive recovery check before hit-count update.

		if ( ! $redir_exists ) {
			return;
		}

		$wpdb->query( // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- Intentional hit-count write to plugin-owned redirect table; write operations are not cached.
			$wpdb->prepare(
				"UPDATE {$redir_table_sql}
				SET hits = hits + 1, last_hit = %s, updated_at = %s
				WHERE id = %d",
				current_time( 'mysql' ),
				current_time( 'mysql' ),
				(int) $row['redirection_id']
			)
		);
		// phpcs:enable PluginCheck.Security.DirectDB.UnescapedDBParameter
	}
}
