<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Smart Redirects — Auto-Redirect Broken Internal Links.
 *
 * Stores its own option: srk_smart_redirect_post_types (array of post type slugs).
 *
 * @since 2.1.7
 */
class SeoRepairKit_SmartRedirects {

	/**
	 * Legacy settings option key.
	 */
	const OPTION_KEY = 'srk_smart_redirect_post_types';
	const SETTINGS_OPTION = 'srk_smart_archive_redirect_settings';
	const CACHE_GROUP = 'seo_repair_kit';
	const RECORDS_CACHE_KEY = 'srk_smart_redirect_records';
	const RECORDS_CACHE_VERSION_OPTION = 'srk_smart_redirect_records_cache_version';

	/**
	 * Clear cached Smart Redirect admin data.
	 *
	 * @return void
	 */
	private static function clear_records_cache() {
		$version = (string) microtime( true );
		if ( false === get_option( self::RECORDS_CACHE_VERSION_OPTION, false ) ) {
			add_option( self::RECORDS_CACHE_VERSION_OPTION, $version, '', 'no' );
			return;
		}

		update_option( self::RECORDS_CACHE_VERSION_OPTION, $version, false );
	}

	/**
	 * Get a plugin-owned database table name.
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
	 * Get an escaped SQL identifier for a plugin-owned table.
	 *
	 * WordPress 5.0 compatibility prevents relying on the newer %i placeholder.
	 * The table key is allowlisted above and the final identifier is backtick-escaped.
	 *
	 * @param string $key Table key.
	 * @return string
	 */
	private static function get_table_identifier( $key ) {
		$table_name = self::get_table_name( $key );

		if ( '' === $table_name ) {
			return '';
		}

		return '`' . str_replace( '`', '``', $table_name ) . '`';
	}

	/**
	 * Register hooks.
	 */
	public function __construct() {
		add_action( 'admin_post_srk_save_smart_redirects', array( $this, 'handle_save' ) );
		add_action( 'wp_ajax_srk_save_smart_redirects', array( $this, 'ajax_save_settings' ) );
		add_action( 'wp_ajax_srk_toggle_smart_redirect_status', array( $this, 'ajax_toggle_redirect_status' ) );
		add_action( 'wp_ajax_srk_reset_smart_redirects', array( $this, 'ajax_reset_redirects' ) );
		add_action( 'wp_ajax_srk_delete_smart_redirect', array( $this, 'ajax_delete_redirect_record' ) );
	}

	/**
	 * Get settings.
	 *
	 * @return array
	 */
	private static function get_settings() {
		$saved = get_option( self::SETTINGS_OPTION, array() );

		if ( ! is_array( $saved ) || ! isset( $saved['post_types'] ) ) {
			$saved = array(
				'enabled'    => ! empty( get_option( self::OPTION_KEY, array() ) ) ? 1 : 0,
				'post_types' => get_option( self::OPTION_KEY, array() ),
			);
		}

		$enabled            = empty( $saved['enabled'] ) ? 0 : 1;
		$public_post_types = get_post_types( array( 'public' => true ), 'names' );
		$post_types         = array_values(
			array_intersect(
				array_map( 'sanitize_key', (array) $saved['post_types'] ),
				$public_post_types
			)
		);

		return array(
			'enabled'    => $enabled,
			'post_types' => $post_types,
		);
	}

	/**
	 * Get enabled post types.
	 *
	 * @return array
	 */
	public static function get_enabled_post_types() {
		$settings = self::get_settings();
		return $settings['post_types'];
	}

	/**
	 * Is smart redirect feature enabled.
	 *
	 * @return bool
	 */
	public static function is_enabled() {
		$settings = self::get_settings();
		return ! empty( $settings['enabled'] );
	}

	/**
	 * Normalize and save settings.
	 *
	 * @param array $input Raw input.
	 * @return array
	 */
	private static function sanitize_and_save_settings( $input ) {
		$public_post_types = get_post_types( array( 'public' => true ), 'names' );
		$post_types        = isset( $input['post_types'] ) ? (array) $input['post_types'] : array();
		$post_types        = array_values(
			array_intersect(
				array_map( 'sanitize_key', $post_types ),
				$public_post_types
			)
		);

		$settings = array(
			'enabled'    => empty( $input['enabled'] ) ? 0 : 1,
			'post_types' => $post_types,
		);

		update_option( self::SETTINGS_OPTION, $settings, false );
		update_option( self::OPTION_KEY, $post_types, false );

		return $settings;
	}

	/**
	 * Backfill smart redirect records from existing scan history after settings save.
	 *
	 * @param array $settings Saved settings.
	 * @return int
	 */
	private static function maybe_backfill_from_scan_history( $settings ) {
		if ( empty( $settings['enabled'] ) || empty( $settings['post_types'] ) ) {
			return 0;
		}

		if ( ! class_exists( 'SeoRepairKit_SmartRedirect_Generator' ) || ! method_exists( 'SeoRepairKit_SmartRedirect_Generator', 'backfill_from_scan_history' ) ) {
			return 0;
		}

		return (int) SeoRepairKit_SmartRedirect_Generator::backfill_from_scan_history( 7 );
	}

	/**
	 * Save settings handler.
	 *
	 * @return void
	 */
	public function handle_save() {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'Permission denied.', 'seo-repair-kit' ) );
		}

		check_admin_referer( 'srk_save_smart_redirects', 'srk_save_smart_redirects_nonce' );

		$settings = self::sanitize_and_save_settings(
			array(
				'enabled'    => ! empty( $_POST['smart_redirect_enabled'] ) ? 1 : 0,
				'post_types' => isset( $_POST['auto_redirect_post_types'] ) ? (array) wp_unslash( $_POST['auto_redirect_post_types'] ) : array(),
			)
		);
		$created_count = self::maybe_backfill_from_scan_history( $settings );

		if ( function_exists( 'srk_add_admin_notice' ) ) {
			$message = __( 'Smart Redirect settings saved successfully.', 'seo-repair-kit' );
			if ( $created_count > 0 ) {
				$message = sprintf(
					/* translators: %d = created smart redirect count */
					__( 'Smart Redirect settings saved successfully. %d redirect record(s) were created from recent scan history.', 'seo-repair-kit' ),
					$created_count
				);
			}
			srk_add_admin_notice( $message, 'success' );
		}

		wp_safe_redirect( admin_url( 'admin.php?page=seo-repair-kit-link-scanner&tab=smart-redirects' ) );
		exit;
	}

	/**
	 * AJAX save settings.
	 *
	 * @return void
	 */
	public function ajax_save_settings() {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_send_json_error( array( 'message' => __( 'Permission denied.', 'seo-repair-kit' ) ) );
		}

		check_ajax_referer( 'srk_smart_redirect_ajax_nonce', 'nonce' );

		$settings = self::sanitize_and_save_settings(
			array(
				'enabled'    => ! empty( $_POST['enabled'] ) ? 1 : 0,
				'post_types' => isset( $_POST['post_types'] ) ? (array) wp_unslash( $_POST['post_types'] ) : array(),
			)
		);
		$created_count = self::maybe_backfill_from_scan_history( $settings );

		wp_send_json_success(
			array(
				'message'       => $created_count > 0
					? sprintf(
						/* translators: %d = created smart redirect count */
						__( 'Smart Redirect settings saved successfully. %d redirect record(s) were created from recent scan history.', 'seo-repair-kit' ),
						$created_count
					)
					: __( 'Smart Redirect settings saved successfully.', 'seo-repair-kit' ),
				'settings'      => $settings,
				'created_count' => $created_count,
			)
		);
	}

	/**
	 * AJAX toggle smart redirect status.
	 *
	 * @return void
	 */
	public function ajax_toggle_redirect_status() {
		global $wpdb;

		if ( ! current_user_can( 'manage_options' ) ) {
			wp_send_json_error( array( 'message' => __( 'Permission denied.', 'seo-repair-kit' ) ) );
		}

		check_ajax_referer( 'srk_smart_redirect_ajax_nonce', 'nonce' );

		$id = isset( $_POST['id'] ) ? absint( $_POST['id'] ) : 0;
		if ( ! $id ) {
			wp_send_json_error( array( 'message' => __( 'Invalid record ID.', 'seo-repair-kit' ) ) );
		}

		$smart_table     = self::get_table_name( 'smart_redirects' );
		$redir_table     = self::get_table_name( 'redirections' );
		$smart_table_sql = esc_sql( self::get_table_name( 'smart_redirects' ) );
		$row             = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$smart_table_sql} WHERE id = %d", $id ), ARRAY_A ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery,PluginCheck.Security.DirectDB.UnescapedDBParameter,WordPress.DB.PreparedSQL.NotPrepared -- Real-time lookup before toggling one Smart Redirect row; identifier comes from get_table_identifier().

		if ( empty( $row ) ) {
			wp_send_json_error( array( 'message' => __( 'Record not found.', 'seo-repair-kit' ) ) );
		}

		$new_status = ( 'active' === $row['status'] ) ? 'inactive' : 'active';
		$wpdb->update( // phpcs:ignore WordPress.DB.DirectDatabaseQuery -- Intentional write to plugin-owned Smart Redirect table; affected caches are cleared below.
			$smart_table,
			array(
				'status'     => $new_status,
				'updated_at' => current_time( 'mysql' ),
			),
			array( 'id' => $id ),
			array( '%s', '%s' ),
			array( '%d' )
		);

		if ( ! empty( $row['redirection_id'] ) ) {
			$wpdb->update( // phpcs:ignore WordPress.DB.DirectDatabaseQuery -- Intentional write to linked plugin-owned redirection table; affected caches are cleared below.
				$redir_table,
				array(
					'status'     => $new_status,
					'updated_at' => current_time( 'mysql' ),
				),
				array( 'id' => (int) $row['redirection_id'] ),
				array( '%s', '%s' ),
				array( '%d' )
			);
		}

		if ( class_exists( 'SeoRepairKit_SmartRedirect_Generator' ) && method_exists( 'SeoRepairKit_SmartRedirect_Generator', 'refresh_server_rules' ) ) {
			SeoRepairKit_SmartRedirect_Generator::refresh_server_rules();
		}
		self::clear_records_cache();

		wp_send_json_success( array( 'message' => __( 'Smart redirect status updated.', 'seo-repair-kit' ) ) );
	}

	/**
	 * AJAX reset smart redirects.
	 *
	 * @return void
	 */
	public function ajax_reset_redirects() {
		global $wpdb;

		if ( ! current_user_can( 'manage_options' ) ) {
			wp_send_json_error( array( 'message' => __( 'Permission denied.', 'seo-repair-kit' ) ) );
		}

		check_ajax_referer( 'srk_smart_redirect_ajax_nonce', 'nonce' );

		$post_type   = isset( $_POST['post_type'] ) ? sanitize_key( wp_unslash( $_POST['post_type'] ) ) : '';
		$smart_table     = self::get_table_name( 'smart_redirects' );
		$redir_table     = self::get_table_name( 'redirections' );
		$smart_table_sql = esc_sql( self::get_table_name( 'smart_redirects' ) );

		if ( '' !== $post_type ) {
			$rows = $wpdb->get_results( // phpcs:ignore WordPress.DB.DirectDatabaseQuery,PluginCheck.Security.DirectDB.UnescapedDBParameter,WordPress.DB.PreparedSQL.NotPrepared -- Real-time lookup before resetting selected Smart Redirect records; identifier comes from get_table_identifier().
				$wpdb->prepare( "SELECT id, redirection_id FROM {$smart_table_sql} WHERE post_type = %s", $post_type ),
				ARRAY_A
			);
		} else {
			$rows = $wpdb->get_results( // phpcs:ignore WordPress.DB.DirectDatabaseQuery,PluginCheck.Security.DirectDB.UnescapedDBParameter,WordPress.DB.PreparedSQL.NotPrepared -- Real-time lookup before resetting all Smart Redirect records; identifier comes from get_table_identifier().
				"SELECT id, redirection_id FROM {$smart_table_sql}",
				ARRAY_A
			);
		}

		if ( ! empty( $rows ) ) {
			foreach ( $rows as $row ) {
				if ( ! empty( $row['redirection_id'] ) ) {
					$wpdb->delete( // phpcs:ignore WordPress.DB.DirectDatabaseQuery -- Intentional delete from linked plugin-owned redirection table; affected caches are cleared below.
						$redir_table,
						array( 'id' => (int) $row['redirection_id'] ),
						array( '%d' )
					);
				}
			}
		}

		if ( '' === $post_type ) {
			$wpdb->query( "TRUNCATE TABLE {$smart_table_sql}" ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery,WordPress.DB.DirectDatabaseQuery.SchemaChange,WordPress.DB.PreparedSQL.NotPrepared,PluginCheck.Security.DirectDB.UnescapedDBParameter -- Intentional reset of plugin-owned Smart Redirect table; identifier comes from get_table_identifier().
		} else {
			$wpdb->delete( // phpcs:ignore WordPress.DB.DirectDatabaseQuery -- Intentional delete from plugin-owned Smart Redirect table; affected caches are cleared below.
				$smart_table,
				array( 'post_type' => $post_type ),
				array( '%s' )
			);
		}

		if ( class_exists( 'SeoRepairKit_SmartRedirect_Generator' ) && method_exists( 'SeoRepairKit_SmartRedirect_Generator', 'refresh_server_rules' ) ) {
			SeoRepairKit_SmartRedirect_Generator::refresh_server_rules();
		}

		// Clear link-scanner URL status cache so the next auto scan can re-detect
		// actual HTTP codes and regenerate Smart Redirects when needed.
		delete_option( 'srk_link_scanner_url_status_cache' );
		self::clear_records_cache();

		wp_send_json_success( array( 'message' => __( 'Smart redirect records reset successfully.', 'seo-repair-kit' ) ) );
	}

	/**
	 * AJAX delete smart redirect row.
	 *
	 * delete_mode:
	 * - metadata: remove smart row only.
	 * - full: remove smart row and linked redirect.
	 *
	 * @return void
	 */
	public function ajax_delete_redirect_record() {
		global $wpdb;

		if ( ! current_user_can( 'manage_options' ) ) {
			wp_send_json_error( array( 'message' => __( 'Permission denied.', 'seo-repair-kit' ) ) );
		}

		check_ajax_referer( 'srk_smart_redirect_ajax_nonce', 'nonce' );

		$id          = isset( $_POST['id'] ) ? absint( $_POST['id'] ) : 0;
		$delete_mode = isset( $_POST['delete_mode'] ) ? sanitize_key( wp_unslash( $_POST['delete_mode'] ) ) : 'metadata';

		if ( ! $id || ! in_array( $delete_mode, array( 'metadata', 'full' ), true ) ) {
			wp_send_json_error( array( 'message' => __( 'Invalid request.', 'seo-repair-kit' ) ) );
		}

		$smart_table     = self::get_table_name( 'smart_redirects' );
		$redir_table     = self::get_table_name( 'redirections' );
		$smart_table_sql = esc_sql( self::get_table_name( 'smart_redirects' ) );

		$row = $wpdb->get_row( // phpcs:ignore WordPress.DB.DirectDatabaseQuery,PluginCheck.Security.DirectDB.UnescapedDBParameter,WordPress.DB.PreparedSQL.NotPrepared -- Real-time lookup before deleting one Smart Redirect row; identifier comes from get_table_identifier().
			$wpdb->prepare( "SELECT id, redirection_id FROM {$smart_table_sql} WHERE id = %d", $id ),
			ARRAY_A
		);

		if ( empty( $row ) ) {
			wp_send_json_error( array( 'message' => __( 'Smart redirect record not found.', 'seo-repair-kit' ) ) );
		}

		// Per requirement: deleting from Smart Redirect table must also delete from Redirection table.
		// So we always remove the linked redirection record if it exists.
		if ( ! empty( $row['redirection_id'] ) ) {
			$wpdb->delete( // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- Intentional delete from linked plugin-owned redirection table; affected caches are cleared below.
				$redir_table,
				array( 'id' => (int) $row['redirection_id'] ),
				array( '%d' )
			);
		}

		$wpdb->delete( // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- Intentional delete from plugin-owned Smart Redirect table; affected caches are cleared below.
			$smart_table,
			array( 'id' => $id ),
			array( '%d' )
		);

		if ( class_exists( 'SeoRepairKit_SmartRedirect_Generator' ) && method_exists( 'SeoRepairKit_SmartRedirect_Generator', 'refresh_server_rules' ) ) {
			SeoRepairKit_SmartRedirect_Generator::refresh_server_rules();
		}

		// Clear link-scanner URL status cache so future scans don't keep seeing stale 200 responses.
		delete_option( 'srk_link_scanner_url_status_cache' );
		self::clear_records_cache();

		wp_send_json_success(
			array(
				'message' => __( 'Smart redirect and linked redirect deleted.', 'seo-repair-kit' ),
			)
		);
	}

	/**
	 * Format URL as clickable link (Smart Redirect table).
	 *
	 * @param string $url Raw/normalized URL.
	 * @param bool   $is_source Source vs target styling.
	 * @return string HTML.
	 */
	private static function format_url_as_link( $url, $is_source = true ) {
		$url = (string) $url;
		if ( '' === $url || '-' === $url ) {
			return '<strong>' . esc_html( $url ) . '</strong>';
		}

		$escaped_url = esc_url( $url );
		if ( '' === $escaped_url ) {
			return '<strong>' . esc_html( $url ) . '</strong>';
		}

		$link_text  = esc_html( $url );
		$link_class = $is_source ? 'srk-source-url-link' : 'srk-target-url-link';
		$title_text = esc_attr( $url ) . ' - ' . esc_attr__( 'Click to open in new window', 'seo-repair-kit' );

		return '<a href="' . $escaped_url . '" target="_blank" rel="noopener noreferrer" class="' . esc_attr( $link_class ) . '" title="' . $title_text . '">' . $link_text . '</a>';
	}

	/**
	 * Render tab content.
	 *
	 * @return void
	 */
	public static function render_tab() {
		// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery -- The Smart Redirect tab renders cached plugin-owned custom-table rows through render_smart_redirects_table(); this scope covers PHPCS attribution across the mixed PHP/HTML template.
		$settings           = self::get_settings();
		$enabled_post_types = $settings['post_types'];
		$public_post_types  = get_post_types( array( 'public' => true ), 'objects' );
		$last_summary       = get_option( 'srk_smart_redirect_last_generation_summary', array() );
		?>
		<div class="srk-card">
			<div class="srk-automation-header">
				<h3>
					<span class="dashicons dashicons-migrate" style="vertical-align:middle;margin-right:6px;color:var(--srk-primary,#6b4eff);"></span>
					<?php esc_html_e( 'Auto-Redirect Broken Internal Links', 'seo-repair-kit' ); ?>
				</h3>
				<p><?php esc_html_e( 'Automatically create 301 redirects for broken internal singular pages to their post-type archive (listing) page. This gives visitors a useful landing page instead of a 404 error.', 'seo-repair-kit' ); ?></p>
			</div>

			<?php if ( ! empty( $last_summary ) && is_array( $last_summary ) ) : ?>
				<div class="notice notice-info" style="margin:0 0 16px;">
					<p style="margin:8px 12px;">
						<strong><?php esc_html_e( 'Last Smart Redirect generation:', 'seo-repair-kit' ); ?></strong>
						<?php
						$time    = ! empty( $last_summary['time'] ) ? (string) $last_summary['time'] : '';
						$created = isset( $last_summary['created'] ) ? (int) $last_summary['created'] : 0;
						echo esc_html( $time ? $time : '-' );
						echo ' — ';
						printf(
							/* translators: %d = created count */
							esc_html__( '%d record(s) created.', 'seo-repair-kit' ),
							esc_html( (string) $created )
						);
						?>
					</p>
				</div>
			<?php endif; ?>

			<div class="srk-smart-redirect-example">
				<span class="dashicons dashicons-info-outline"></span>
				<div>
					<strong><?php esc_html_e( 'How it works:', 'seo-repair-kit' ); ?></strong>
					<p><?php esc_html_e( 'Select which post types should have their broken singular pages auto-redirected to the archive listing. Each post type is independent — enable only the ones you want.', 'seo-repair-kit' ); ?></p>
					<ul class="srk-smart-redirect-list">
						<li><code>/case-studies/broken-slug/</code> &rarr; <code>/case-studies/</code></li>
						<li><code>/blog/deleted-post/</code> &rarr; <code>/blog/</code></li>
						<li><code>/products/old-item/</code> &rarr; <code>/products/</code></li>
					</ul>
				</div>
			</div>

			<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" id="srk-smart-redirect-form">
				<input type="hidden" name="action" value="srk_save_smart_redirects" />
				<?php wp_nonce_field( 'srk_save_smart_redirects', 'srk_save_smart_redirects_nonce' ); ?>
				<?php wp_nonce_field( 'srk_smart_redirect_ajax_nonce', 'srk_smart_redirect_ajax_nonce' ); ?>

				<p style="margin-bottom: 14px;">
					<label>
						<input type="checkbox" name="smart_redirect_enabled" value="1" <?php checked( ! empty( $settings['enabled'] ) ); ?> />
						<?php esc_html_e( 'Enable Smart Redirect automation', 'seo-repair-kit' ); ?>
					</label>
				</p>

				<div class="srk-redirect-pt-grid">
					<?php foreach ( $public_post_types as $pt_slug => $pt_obj ) : ?>
						<?php
						$archive_url = '';

						if ( class_exists( 'SeoRepairKit_SmartRedirect_Helper' ) ) {
							$archive_url = SeoRepairKit_SmartRedirect_Helper::get_post_type_archive_url( $pt_slug, $pt_obj );
						} else {
							$archive_url = get_post_type_archive_link( $pt_slug );
						}

						$has_archive  = ! empty( $archive_url );
						$is_checked   = in_array( $pt_slug, $enabled_post_types, true );
						$card_class   = 'srk-redirect-pt-card' . ( ! $has_archive ? ' srk-redirect-pt-no-archive' : '' ) . ( $is_checked ? ' srk-redirect-pt-active' : '' );
						$display_path = $has_archive ? str_replace( home_url(), '', $archive_url ) : '';

						if ( '' === $display_path ) {
							$display_path = '/';
						}
						?>
						<label class="<?php echo esc_attr( $card_class ); ?>">
							<div class="srk-redirect-pt-card-top">
								<input
									type="checkbox"
									name="auto_redirect_post_types[]"
									value="<?php echo esc_attr( $pt_slug ); ?>"
									<?php checked( $is_checked ); ?>
									<?php disabled( ! $has_archive ); ?>
									class="srk-redirect-pt-checkbox"
								/>
								<span class="srk-redirect-pt-name"><?php echo esc_html( $pt_obj->labels->name ); ?></span>
								<span class="srk-redirect-pt-slug"><?php echo esc_html( $pt_slug ); ?></span>
							</div>
							<div class="srk-redirect-pt-card-bottom">
								<?php if ( $has_archive ) : ?>
									<span class="srk-redirect-pt-arrow">
										<span class="dashicons dashicons-arrow-right-alt"></span>
									</span>
									<code class="srk-redirect-pt-archive"><?php echo esc_html( $display_path ); ?></code>
								<?php else : ?>
									<span class="srk-redirect-pt-no-archive-msg">
										<span class="dashicons dashicons-minus"></span>
										<?php esc_html_e( 'No archive page', 'seo-repair-kit' ); ?>
									</span>
								<?php endif; ?>
							</div>
						</label>
					<?php endforeach; ?>
				</div>

				<p class="description" style="margin-top:14px;">
					<?php esc_html_e( 'Redirects are created in the Redirection Manager as 301 (permanent) redirects and only apply to broken internal links found during a scan. Post types without an archive page cannot be enabled.', 'seo-repair-kit' ); ?>
				</p>

				<div class="srk-automation-actions" style="margin-top:20px;">
					<button type="button" class="button button-primary srk-btn-save-settings" id="srk-smart-save-settings">
						<span class="dashicons dashicons-saved"></span>
						<?php esc_html_e( 'Save Settings', 'seo-repair-kit' ); ?>
					</button>
				</div>
			</form>

			<?php self::render_smart_redirects_table(); ?>

			<script>
			jQuery(function($) {
				var ajaxUrl = '<?php echo esc_js( admin_url( 'admin-ajax.php' ) ); ?>';
				var nonce = $('#srk_smart_redirect_ajax_nonce').val();

				$('#srk-smart-save-settings').on('click', function(e) {
					e.preventDefault();
					var postTypes = [];
					$('input[name="auto_redirect_post_types[]"]:checked').each(function() {
						postTypes.push($(this).val());
					});

					$.post(ajaxUrl, {
						action: 'srk_save_smart_redirects',
						nonce: nonce,
						enabled: $('input[name="smart_redirect_enabled"]').is(':checked') ? 1 : 0,
						post_types: postTypes
					}).done(function(resp) {
						alert((resp && resp.data && resp.data.message) ? resp.data.message : 'Saved');
						window.location.reload();
					});
				});

				$('.srk-smart-toggle-status').on('click', function() {
					$.post(ajaxUrl, {
						action: 'srk_toggle_smart_redirect_status',
						nonce: nonce,
						id: $(this).data('id')
					}).done(function() {
						window.location.reload();
					});
				});

				$('.srk-smart-reset-records').on('click', function() {
					if (!confirm('<?php echo esc_js( __( 'Are you sure you want to reset smart redirect records?', 'seo-repair-kit' ) ); ?>')) {
						return;
					}
					$.post(ajaxUrl, {
						action: 'srk_reset_smart_redirects',
						nonce: nonce,
						post_type: $(this).data('post-type') || ''
					}).done(function() {
						window.location.reload();
					});
				});

				$('.srk-smart-reset-selected-post-type').on('click', function(e) {
					e.preventDefault();
					var selectedType = $('select[name="srk_smart_post_type"]').val() || '';
					if (!selectedType) {
						alert('<?php echo esc_js( __( 'Please select a Post Type from the filter dropdown first.', 'seo-repair-kit' ) ); ?>');
						return;
					}
					if (!confirm('<?php echo esc_js( __( 'Reset Smart Redirect records for the selected post type?', 'seo-repair-kit' ) ); ?>')) {
						return;
					}
					$.post(ajaxUrl, {
						action: 'srk_reset_smart_redirects',
						nonce: nonce,
						post_type: selectedType
					}).done(function() {
						window.location.reload();
					});
				});

				$('.srk-smart-delete-record').on('click', function(e) {
					e.preventDefault();
					var deleteMode = $(this).data('mode') || 'metadata';
					var msg = '<?php echo esc_js( __( 'Delete smart record and linked redirect?', 'seo-repair-kit' ) ); ?>';
					if (!confirm(msg)) {
						return;
					}
					$.post(ajaxUrl, {
						action: 'srk_delete_smart_redirect',
						nonce: nonce,
						id: $(this).data('id'),
						delete_mode: deleteMode
					}).done(function() {
						window.location.reload();
					});
				});

				$(document).on('change', '#srk_smart_per_page_select', function() {
					var url = new URL(window.location.href);
					url.searchParams.set('srk_smart_per_page', $(this).val());
					url.searchParams.set('srk_smart_paged', '1');
					window.location.href = url.toString();
				});
			});
			</script>
		</div>
		<?php
		// phpcs:enable WordPress.DB.DirectDatabaseQuery.DirectQuery
	}

	/**
	 * Render active internal redirects table.
	 *
	 * @return void
	 */
	private static function render_smart_redirects_table() {
		// phpcs:disable WordPress.DB.DirectDatabaseQuery,PluginCheck.Security.DirectDB.UnescapedDBParameter -- This renderer reads cached, plugin-owned Smart Redirect custom-table data; identifiers are allowlisted and values are prepared.
		global $wpdb;

		$smart_table     = self::get_table_name( 'smart_redirects' );
		$smart_table_sql = esc_sql( self::get_table_name( 'smart_redirects' ) );
		$table_exists    = (
			class_exists( 'SeoRepairKit_Activator' )
			&& SeoRepairKit_Activator::is_database_current()
		);

		if ( ! $table_exists ) {
			$table_exists = ( $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $smart_table ) ) === $smart_table ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- Recovery check only when schema version is not current.
		}

		if ( ! $table_exists ) {
			return;
		}

		$post_type = isset( $_GET['srk_smart_post_type'] ) ? sanitize_key( wp_unslash( $_GET['srk_smart_post_type'] ) ) : '';
		$status    = isset( $_GET['srk_smart_status'] ) ? sanitize_key( wp_unslash( $_GET['srk_smart_status'] ) ) : '';
		$status    = in_array( $status, array( 'active', 'inactive' ), true ) ? $status : '';

		$per_page_raw = isset( $_GET['srk_smart_per_page'] ) ? sanitize_text_field( wp_unslash( $_GET['srk_smart_per_page'] ) ) : '20';
		$show_all     = ( 'all' === $per_page_raw || '-1' === $per_page_raw );
		$per_page     = $show_all ? -1 : max( 10, min( 100, (int) $per_page_raw ) );
		$current_page = isset( $_GET['srk_smart_paged'] ) ? max( 1, (int) $_GET['srk_smart_paged'] ) : 1;
		$offset       = $show_all ? 0 : ( $current_page - 1 ) * $per_page;
		$cache_version = (string) get_option( self::RECORDS_CACHE_VERSION_OPTION, '1' );
		$cache_key     = self::RECORDS_CACHE_KEY . '_' . md5( wp_json_encode( array( $cache_version, $post_type, $status, $per_page_raw, $current_page ) ) );
		$cached_data  = wp_cache_get( $cache_key, self::CACHE_GROUP );

		if ( false !== $cached_data && is_array( $cached_data ) ) {
			$total_items          = isset( $cached_data['total_items'] ) ? absint( $cached_data['total_items'] ) : 0;
			$rows                 = isset( $cached_data['rows'] ) && is_array( $cached_data['rows'] ) ? $cached_data['rows'] : array();
			$available_post_types = isset( $cached_data['available_post_types'] ) && is_array( $cached_data['available_post_types'] ) ? $cached_data['available_post_types'] : array();
		} else {
		// phpcs:disable WordPress.DB.DirectDatabaseQuery,PluginCheck.Security.DirectDB.UnescapedDBParameter -- Cached Smart Redirect admin table reads; $smart_table_sql is plugin-owned from get_table_identifier(), and all user values below are prepared.
		if ( '' !== $post_type && '' !== $status ) {
			$total_items = (int) $wpdb->get_var( // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- Cached Smart Redirect admin list aggregate.
				$wpdb->prepare(
					"SELECT COUNT(*) FROM {$smart_table_sql} WHERE post_type = %s AND status = %s",
					$post_type,
					$status
				)
			);
		} elseif ( '' !== $post_type ) {
			$total_items = (int) $wpdb->get_var( // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- Cached Smart Redirect admin list aggregate.
				$wpdb->prepare(
					"SELECT COUNT(*) FROM {$smart_table_sql} WHERE post_type = %s",
					$post_type
				)
			);
		} elseif ( '' !== $status ) {
			$total_items = (int) $wpdb->get_var( // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- Cached Smart Redirect admin list aggregate.
				$wpdb->prepare(
					"SELECT COUNT(*) FROM {$smart_table_sql} WHERE status = %s",
					$status
				)
			);
		} else {
			$total_items = (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$smart_table_sql}" ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,WordPress.DB.PreparedSQL.NotPrepared -- Cached Smart Redirect admin list aggregate.
		}

		$total_pages = $show_all ? 1 : ( $per_page > 0 ? (int) ceil( $total_items / $per_page ) : 1 );

		if ( '' !== $post_type && '' !== $status ) {
			$rows = $show_all
				? $wpdb->get_results( // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- Cached Smart Redirect admin list query.
					$wpdb->prepare(
						"SELECT * FROM {$smart_table_sql} WHERE post_type = %s AND status = %s ORDER BY created_at DESC",
						$post_type,
						$status
					),
					ARRAY_A
				)
				: $wpdb->get_results( // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- Cached Smart Redirect admin list query.
					$wpdb->prepare(
						"SELECT * FROM {$smart_table_sql} WHERE post_type = %s AND status = %s ORDER BY created_at DESC LIMIT %d OFFSET %d",
						$post_type,
						$status,
						$per_page,
						$offset
					),
					ARRAY_A
				);
		} elseif ( '' !== $post_type ) {
			$rows = $show_all
				? $wpdb->get_results( // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- Cached Smart Redirect admin list query.
					$wpdb->prepare(
						"SELECT * FROM {$smart_table_sql} WHERE post_type = %s ORDER BY created_at DESC",
						$post_type
					),
					ARRAY_A
				)
				: $wpdb->get_results( // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- Cached Smart Redirect admin list query.
					$wpdb->prepare(
						"SELECT * FROM {$smart_table_sql} WHERE post_type = %s ORDER BY created_at DESC LIMIT %d OFFSET %d",
						$post_type,
						$per_page,
						$offset
					),
					ARRAY_A
				);
		} elseif ( '' !== $status ) {
			$rows = $show_all
				? $wpdb->get_results( // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- Cached Smart Redirect admin list query.
					$wpdb->prepare(
						"SELECT * FROM {$smart_table_sql} WHERE status = %s ORDER BY created_at DESC",
						$status
					),
					ARRAY_A
				)
				: $wpdb->get_results( // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- Cached Smart Redirect admin list query.
					$wpdb->prepare(
						"SELECT * FROM {$smart_table_sql} WHERE status = %s ORDER BY created_at DESC LIMIT %d OFFSET %d",
						$status,
						$per_page,
						$offset
					),
					ARRAY_A
				);
		} else {
			$rows = $show_all
				? $wpdb->get_results( "SELECT * FROM {$smart_table_sql} ORDER BY created_at DESC", ARRAY_A ) // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,WordPress.DB.PreparedSQL.NotPrepared -- Cached Smart Redirect admin list query.
				: $wpdb->get_results( // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- Cached Smart Redirect admin list query.
					$wpdb->prepare(
						"SELECT * FROM {$smart_table_sql} ORDER BY created_at DESC LIMIT %d OFFSET %d",
						$per_page,
						$offset
					),
					ARRAY_A
				);
		}

		$available_post_types = (array) $wpdb->get_col( "SELECT DISTINCT post_type FROM {$smart_table_sql} ORDER BY post_type ASC" ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,WordPress.DB.PreparedSQL.NotPrepared -- Cached Smart Redirect filter values.
		// phpcs:enable WordPress.DB.DirectDatabaseQuery,PluginCheck.Security.DirectDB.UnescapedDBParameter
		wp_cache_set(
			$cache_key,
			array(
				'total_items'          => $total_items,
				'rows'                 => $rows,
				'available_post_types' => $available_post_types,
			),
			self::CACHE_GROUP,
			MINUTE_IN_SECONDS
		);
		}
		?>
		<div class="srk-redirect-existing srk-smart-records-card">
			<div class="srk-smart-records-header">
				<div class="srk-alerts-card-title">
					<span class="dashicons dashicons-randomize"></span>
					<div>
						<h3><?php esc_html_e( 'Smart Redirect Records', 'seo-repair-kit' ); ?></h3>
						<p><?php esc_html_e( 'Review, filter, reset, enable/disable, or delete generated Smart Redirect records.', 'seo-repair-kit' ); ?></p>
					</div>
				</div>

				<a href="<?php echo esc_url( admin_url( 'admin.php?page=seo-repair-kit-redirection' ) ); ?>" class="button srk-history-action-btn srk-manage-redirects-btn">
					<span class="dashicons dashicons-migrate"></span>
					<?php esc_html_e( 'Manage All Redirects', 'seo-repair-kit' ); ?>
				</a>
			</div>

			<form method="get" action="" class="srk-smart-filter-form">
				<input type="hidden" name="page" value="seo-repair-kit-link-scanner" />
				<input type="hidden" name="tab" value="smart-redirects" />

				<select name="srk_smart_post_type" class="srk-select srk-smart-filter-select">
					<option value=""><?php esc_html_e( 'All Post Types', 'seo-repair-kit' ); ?></option>
					<?php foreach ( $available_post_types as $pt ) : ?>
						<option value="<?php echo esc_attr( $pt ); ?>" <?php selected( $post_type, $pt ); ?>>
							<?php echo esc_html( $pt ); ?>
						</option>
					<?php endforeach; ?>
				</select>

				<select name="srk_smart_status" class="srk-select srk-smart-filter-select">
					<option value=""><?php esc_html_e( 'All Statuses', 'seo-repair-kit' ); ?></option>
					<option value="active" <?php selected( $status, 'active' ); ?>><?php esc_html_e( 'Active', 'seo-repair-kit' ); ?></option>
					<option value="inactive" <?php selected( $status, 'inactive' ); ?>><?php esc_html_e( 'Inactive', 'seo-repair-kit' ); ?></option>
				</select>

				<button type="submit" class="button srk-history-action-btn srk-filter-btn">
					<span class="dashicons dashicons-filter"></span>
					<?php esc_html_e( 'Filter', 'seo-repair-kit' ); ?>
				</button>

				<a class="button srk-history-action-btn" href="<?php echo esc_url( admin_url( 'admin.php?page=seo-repair-kit-link-scanner&tab=smart-redirects' ) ); ?>">
					<span class="dashicons dashicons-update"></span>
					<?php esc_html_e( 'Reset', 'seo-repair-kit' ); ?>
				</a>
			</form>

			<div class="srk-smart-bulk-actions">
				<a
					href="#"
					class="button srk-history-action-btn srk-danger-soft-btn srk-smart-reset-records"
					data-post-type=""
					title="<?php echo esc_attr__( 'Delete all Smart Redirect records (and linked Redirection records). The next scan can rebuild them.', 'seo-repair-kit' ); ?>"
				>
					<span class="dashicons dashicons-trash"></span>
					<?php esc_html_e( 'Reset All Smart Records', 'seo-repair-kit' ); ?>
				</a>

				<a
					href="#"
					class="button srk-history-action-btn srk-warning-soft-btn srk-smart-reset-selected-post-type"
					title="<?php echo esc_attr__( 'Delete Smart Redirect records (and linked Redirection records) only for the currently selected Post Type filter.', 'seo-repair-kit' ); ?>"
				>
					<span class="dashicons dashicons-category"></span>
					<?php esc_html_e( 'Reset This Post Type', 'seo-repair-kit' ); ?>
				</a>
			</div>
			<div class="srk-table-scroll">
				<table class="widefat striped srk-history-table">
					<thead>
						<tr>
							<th><?php esc_html_e( 'Post Type', 'seo-repair-kit' ); ?></th>
							<th><?php esc_html_e( 'Source URL', 'seo-repair-kit' ); ?></th>
							<th><?php esc_html_e( 'Redirects To', 'seo-repair-kit' ); ?></th>
							<th><?php esc_html_e( 'Status', 'seo-repair-kit' ); ?></th>
							<th><?php esc_html_e( 'Actions', 'seo-repair-kit' ); ?></th>
							<th><?php esc_html_e( 'Created', 'seo-repair-kit' ); ?></th>
						</tr>
					</thead>
					<tbody>
						<?php if ( ! empty( $rows ) ) : ?>
							<?php foreach ( $rows as $r ) : ?>
								<tr>
									<td><code><?php echo esc_html( $r['post_type'] ); ?></code></td>
									<td><?php echo wp_kses_post( self::format_url_as_link( $r['source_url'], true ) ); ?></td>
									<td><?php echo wp_kses_post( self::format_url_as_link( $r['target_url'], false ) ); ?></td>
									<td>
										<span class="srk-status srk-status-<?php echo esc_attr( $r['status'] ); ?>">
											<?php echo esc_html( ucfirst( $r['status'] ) ); ?>
										</span>
									</td>
									<td>
										<div class="srk-smart-actions">
											<button
												type="button"
												class="button button-small srk-smart-toggle-status"
												data-id="<?php echo (int) $r['id']; ?>"
												title="<?php echo esc_attr__( 'Turn this Smart Redirect on/off. When disabled, this redirect will not run on the front-end.', 'seo-repair-kit' ); ?>"
											>
											<span class="dashicons dashicons-controls-repeat"></span>
											<?php esc_html_e( 'Enable / Disable', 'seo-repair-kit' ); ?>
											</button>
											<a
												href="#"
												class="button button-small srk-smart-delete-record"
												data-id="<?php echo (int) $r['id']; ?>"
												data-mode="full"
												title="<?php echo esc_attr__( 'Delete this Smart Redirect record and its linked Redirection record. This will stop redirecting immediately.', 'seo-repair-kit' ); ?>"
											>
											<span class="dashicons dashicons-trash"></span>
											<?php esc_html_e( 'Delete', 'seo-repair-kit' ); ?>
											</a>
										</div>
									</td>
									<td><?php echo esc_html( wp_date( 'M j, Y g:i a', strtotime( $r['created_at'] ), wp_timezone() ) ); ?></td>
								</tr>
							<?php endforeach; ?>
						<?php else : ?>
							<tr>
								<td colspan="6"><?php esc_html_e( 'No smart redirect records found for this filter.', 'seo-repair-kit' ); ?></td>
							</tr>
						<?php endif; ?>
					</tbody>
				</table>
			</div>
			<?php if ( $total_items > 0 ) : ?>
				<div class="srk-pagination-wrapper" style="margin-top:10px;">
					<div class="srk-pagination-info">
						<?php
						if ( $show_all ) {
							printf( esc_html__( 'Showing all %d smart redirect records', 'seo-repair-kit' ), (int) $total_items );
						} else {
							$start = $offset + 1;
							$end   = min( $offset + $per_page, $total_items );
							printf( esc_html__( 'Showing %1$d to %2$d of %3$d smart redirect records', 'seo-repair-kit' ), (int) $start, (int) $end, (int) $total_items );
						}
						?>
					</div>
					<div class="srk-pagination-per-page">
						<label for="srk_smart_per_page_select"><?php esc_html_e( 'Per page:', 'seo-repair-kit' ); ?></label>
						<select id="srk_smart_per_page_select">
							<option value="10" <?php selected( (string) $per_page_raw, '10' ); ?>>10</option>
							<option value="20" <?php selected( (string) $per_page_raw, '20' ); ?>>20</option>
							<option value="50" <?php selected( (string) $per_page_raw, '50' ); ?>>50</option>
							<option value="100" <?php selected( (string) $per_page_raw, '100' ); ?>>100</option>
							<option value="all" <?php selected( $show_all, true ); ?>><?php esc_html_e( 'All', 'seo-repair-kit' ); ?></option>
						</select>
					</div>
					<?php if ( ! $show_all && $total_pages > 1 ) : ?>
						<div class="srk-pagination">
							<?php
							$base = add_query_arg(
								array(
									'page'               => 'seo-repair-kit-link-scanner',
									'tab'                => 'smart-redirects',
									'srk_smart_per_page' => $per_page_raw,
									'srk_smart_post_type'=> $post_type,
									'srk_smart_status'   => $status,
								),
								admin_url( 'admin.php' )
							);
							?>
							<?php if ( $current_page > 1 ) : ?>
								<a class="srk-pagination-link" href="<?php echo esc_url( add_query_arg( 'srk_smart_paged', $current_page - 1, $base ) ); ?>"><?php esc_html_e( 'Previous', 'seo-repair-kit' ); ?></a>
							<?php endif; ?>
							<span class="srk-pagination-page srk-pagination-current"><?php echo esc_html( $current_page ); ?></span>
							<?php if ( $current_page < $total_pages ) : ?>
								<a class="srk-pagination-link" href="<?php echo esc_url( add_query_arg( 'srk_smart_paged', $current_page + 1, $base ) ); ?>"><?php esc_html_e( 'Next', 'seo-repair-kit' ); ?></a>
							<?php endif; ?>
						</div>
					<?php endif; ?>
				</div>
			<?php endif; ?>
		</div>
		<?php
		// phpcs:enable WordPress.DB.DirectDatabaseQuery,PluginCheck.Security.DirectDB.UnescapedDBParameter
	}
}
