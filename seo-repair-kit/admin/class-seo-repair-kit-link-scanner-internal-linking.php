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
		echo '<div class="srk-card">';
		echo '<h3>' . esc_html__( 'Internal Linking (Planned v2.1.9)', 'seo-repair-kit' ) . '</h3>';
		echo '<p>' . esc_html__( 'This section will include internal link opportunities, orphan content detection, and contextual link recommendations.', 'seo-repair-kit' ) . '</p>';
		echo '</div>';
	}
}