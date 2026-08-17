<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Internal Linking admin tab renderer for Links Manager.
 *
 * @since 2.1.7
 */
class SeoRepairKit_LinkScanner_InternalLinking {

	/**
	 * Render internal linking tab.
	 *
	 * @return void
	 */
	public function render_tab() {
		$enabled = class_exists( 'SRK_License_Helper' ) && SRK_License_Helper::is_internal_linking_enabled();

		echo '<div class="srk-card">';
		if ( ! $enabled ) {
			echo '<h3>' . esc_html__( 'Internal Linking requires the paid module (Coming Soon)', 'seo-repair-kit' ) . '</h3>';
			echo '<p>' . esc_html__( 'Add Internal Linking to this website from your SEO Repair Kit custom plan, then clear the license cache.', 'seo-repair-kit' ) . '</p>';
			echo '</div>';
			return;
		}

		echo '<h3>' . esc_html__( 'Internal Linking module active', 'seo-repair-kit' ) . '</h3>';
		echo '<p>' . esc_html__( 'Your CRM license includes Internal Linking. Internal-link opportunities, orphan content detection, and contextual recommendations can run for this licensed site.', 'seo-repair-kit' ) . '</p>';
		echo '</div>';
	}
}
