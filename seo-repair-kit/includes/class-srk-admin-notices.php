<?php
if ( ! defined( 'ABSPATH' ) ) { exit; }

/**
 * SRK Admin Notices helper
 *
 * Goal:
 * - Show SRK-owned notices right after our navbar/hero.
 * - Prevent third-party/theme notices from leaking into SRK screens.
 *
 * Usage:
 *   require_once plugin_dir_path( dirname( __FILE__ ) ) . 'includes/class-srk-admin-notices.php';
 *   if ( function_exists('srk_render_notices_after_navbar') ) { srk_render_notices_after_navbar(); }
 */
if ( ! function_exists( 'srk_get_admin_notice_transient_key' ) ) {
	function srk_get_admin_notice_transient_key() {
		$user_id = function_exists( 'get_current_user_id' ) ? (int) get_current_user_id() : 0;
		return 'srk_admin_notices_' . $user_id;
	}
}

if ( ! function_exists( 'srk_add_admin_notice' ) ) {
	function srk_add_admin_notice( $message, $type = 'success', $dismissible = true ) {
		if ( ! is_admin() || '' === trim( wp_strip_all_tags( (string) $message ) ) ) {
			return;
		}

		$allowed_types = array( 'success', 'error', 'warning', 'info' );
		$type          = in_array( $type, $allowed_types, true ) ? $type : 'info';
		$key           = srk_get_admin_notice_transient_key();
		$notices       = get_transient( $key );

		if ( ! is_array( $notices ) ) {
			$notices = array();
		}

		$notices[] = array(
			'message'     => (string) $message,
			'type'        => $type,
			'dismissible' => (bool) $dismissible,
		);

		set_transient( $key, $notices, MINUTE_IN_SECONDS * 5 );
	}
}

if ( ! function_exists( 'srk_render_database_update_notice' ) ) {
	function srk_render_database_update_notice() {
		if ( class_exists( 'SeoRepairKit_Activator' ) && SeoRepairKit_Activator::is_database_current() ) {
			return;
		}
		?>
		<div class="notice notice-info is-dismissible">
			<h2><?php esc_html_e( 'SEO Repair Kit database update required', 'seo-repair-kit' ); ?></h2>
			<p><?php esc_html_e( 'To keep your website SEO in top shape, we need to update your settings to the latest version. This process runs in the background and may take a few moments.', 'seo-repair-kit' ); ?></p>
			<p>
				<a href="<?php echo esc_url( admin_url( 'admin-post.php?action=srkit_update_settings' ) ); ?>" class="button button-primary"><?php esc_html_e( 'Update Settings', 'seo-repair-kit' ); ?></a>
			</p>
		</div>
		<?php
	}
}

if ( ! function_exists('srk_render_notices_after_navbar') ) {

	function srk_render_notices_after_navbar() {
		if ( ! is_admin() ) { return; }

		$screen    = function_exists('get_current_screen') ? get_current_screen() : null;
		$screen_id = $screen ? $screen->id : '';

		/**
		 * IMPORTANT:
		 * WordPress and many plugins print notices in TWO common places:
		 *  1) As direct children of #wpbody-content (BEFORE .wrap)
		 *  2) As children of .wrap (classic placement)
		 *
		 * Hide those default placements on SRK screens so third-party notices
		 * do not break the SRK app layout. SRK-owned notices are rendered below.
		 */
		if ( $screen_id ) {
			$body_class = esc_attr( $screen_id );

			echo '<style id="srk-hide-default-notices">
				/* Hide default notice placement ONLY on this SRK screen */
				body.' . esc_attr( $body_class ) . ' #wpbody-content > .notice,
				body.' . esc_attr( $body_class ) . ' #wpbody-content > .updated,
				body.' . esc_attr( $body_class ) . ' #wpbody-content > .error,
				body.' . esc_attr( $body_class ) . ' #wpbody-content > .update-nag,
				body.' . esc_attr( $body_class ) . ' .wrap > .notice,
				body.' . esc_attr( $body_class ) . ' .wrap > .updated,
				body.' . esc_attr( $body_class ) . ' .wrap > .error,
				body.' . esc_attr( $body_class ) . ' .wrap > .update-nag {
					display: none !important;
				}
			</style>';
		}

		// Place where we actually want notices to appear.
		echo '<div class="srk-notices-area" aria-live="polite">';

		srk_render_database_update_notice();

		// SRK-scoped Options API messages only.
		if ( function_exists( 'settings_errors' ) ) {
			settings_errors( 'seo-repair-kit' );
		}

		$notice_key = srk_get_admin_notice_transient_key();
		$notices    = get_transient( $notice_key );

		if ( is_array( $notices ) ) {
			foreach ( $notices as $notice ) {
				if ( ! is_array( $notice ) || empty( $notice['message'] ) ) {
					continue;
				}

				$type        = isset( $notice['type'] ) ? sanitize_html_class( $notice['type'] ) : 'info';
				$dismissible = ! empty( $notice['dismissible'] ) ? ' is-dismissible' : '';

				printf(
					'<div class="notice notice-%1$s%2$s"><p>%3$s</p></div>',
					esc_attr( $type ),
					esc_attr( $dismissible ),
					wp_kses_post( $notice['message'] )
				);
			}

			delete_transient( $notice_key );
		}

		echo '</div>';
	}
}
