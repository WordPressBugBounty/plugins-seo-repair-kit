<?php
if ( ! defined( 'ABSPATH' ) ) { exit; }

/**
 * SRK Admin Notices helper
 *
 * Goal:
 * - Show all WP notices right after our navbar/hero.
 * - Prevent duplicates by hiding the DEFAULT notice placement ONLY on the current SRK screen.
 *
 * Usage:
 *   require_once plugin_dir_path( dirname( __FILE__ ) ) . 'includes/class-srk-admin-notices.php';
 *   if ( function_exists('srk_render_notices_after_navbar') ) { srk_render_notices_after_navbar(); }
 */
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
		 * We must hide BOTH for THIS screen only, otherwise you see duplicates
		 * (default slot + our re-print).
		 */
		if ( $screen_id ) {
			$body_class = esc_attr( $screen_id );

			echo '<style id="srk-hide-default-notices">
				/* Hide default notice placement ONLY on this SRK screen */
				body.' . $body_class . ' #wpbody-content > .notice,
				body.' . $body_class . ' #wpbody-content > .updated,
				body.' . $body_class . ' #wpbody-content > .error,
				body.' . $body_class . ' #wpbody-content > .update-nag,
				body.' . $body_class . ' .wrap > .notice,
				body.' . $body_class . ' .wrap > .updated,
				body.' . $body_class . ' .wrap > .error,
				body.' . $body_class . ' .wrap > .update-nag {
					display: none !important;
				}
			</style>';
		}

		// Place where we actually want notices to appear.
		echo '<div class="srk-notices-area" aria-live="polite">';

		// Options API messages (e.g., add_settings_error)
		if ( function_exists( 'settings_errors' ) ) {
			settings_errors();
		}

		// Core hooks (buffer and reprint here)
		foreach ( array( 'admin_notices', 'network_admin_notices', 'user_admin_notices', 'all_admin_notices' ) as $hook ) {
			if ( has_action( $hook ) ) {
				ob_start();
				do_action( $hook );
				$out = trim( ob_get_clean() );
				if ( $out !== '' ) {
					echo '<div class="srk-notices-proxy">' . $out . '</div>';
				}
			}
		}

		echo '</div>';
	}
}
