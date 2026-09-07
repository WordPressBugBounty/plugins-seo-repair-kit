<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class SeoRepairKit_InternalLinking_Reports {

	public function render() {
		$reports = array(
			array(
				'id'          => 'internal_links',
				'title'       => 'Internal Links Report',
				'description' => 'Full count and distribution across categories.',
				'icon'        => 'dashicons-chart-bar',
				'color'       => 'blue',
				'button'      => 'View Report',
			),
			array(
				'id'          => 'external_links',
				'title'       => 'External Links Report',
				'description' => 'Monitor your outbound link profile & health.',
				'icon'        => 'dashicons-chart-bar',
				'color'       => 'blue',
				'button'      => 'View Report',
			),
			array(
				'id'          => 'domains',
				'title'       => 'Domains Report',
				'description' => 'Analyze outbound domain usage discovered during indexing.',
				'icon'        => 'dashicons-chart-bar',
				'color'       => 'blue',
				'button'      => 'Go To Report',
			),
			array(
				'id'          => 'anchor_text',
				'title'       => 'Anchor Text Report',
				'description' => 'Analyze diversity of your internal link anchors.',
				'icon'        => 'dashicons-chart-bar',
				'color'       => 'blue',
				'button'      => 'View Report',
			),
			array(
				'id'          => 'top_linked_pages',
				'title'       => 'Top Linked Pages',
				'description' => 'See which pages are getting the most link juice.',
				'icon'        => 'dashicons-chart-bar',
				'color'       => 'blue',
				'button'      => 'View Report',
			),
			array(
				'id'          => 'orphan_content',
				'title'       => 'Orphan Content Report',
				'description' => 'Deep dive into unlinked content segments.',
				'icon'        => 'dashicons-chart-bar',
				'color'       => 'blue',
				'button'      => 'View Report',
			),
			array(
				'id'          => 'auto_link',
				'title'       => 'Auto-Link Report',
				'description' => 'Performance and hit counts for active rules.',
				'icon'        => 'dashicons-chart-bar',
				'color'       => 'blue',
				'button'      => 'View Report',
			),
			array(
				'id'          => 'url_changer',
				'title'       => 'URL Changer History',
				'description' => 'Logs of all global path replacements.',
				'icon'        => 'dashicons-chart-bar',
				'color'       => 'blue',
				'button'      => 'View Report',
			),
		);
		?>

		<div class="srk-il-page srk-il-reports">

			<div class="srk-il-page-title">
				<h2><?php esc_html_e( 'Smart Reports', 'seo-repair-kit' ); ?></h2>
				<p><?php esc_html_e( 'Visual insights into your internal and external linking profile.', 'seo-repair-kit' ); ?></p>
			</div>

			<div class="srk-reports-grid">

				<?php foreach ( $reports as $report ) : ?>

					<div class="srk-report-card">

						<div class="srk-report-top">
							<span class="dashicons <?php echo esc_attr( $report['icon'] . ' ' . $report['color'] ); ?>"></span>

						</div>

						<h3><?php echo esc_html( $report['title'] ); ?></h3>

						<p><?php echo esc_html( $report['description'] ); ?></p>

						<button
							type="button"
							class="button srk-report-open"
							data-report="<?php echo esc_attr( $report['id'] ); ?>">

							<?php echo esc_html( $report['button'] ); ?>

						</button>

					</div>

				<?php endforeach; ?>

			</div>
			<div class="srk-il-info-box">
				<strong>
					<span class="dashicons dashicons-info-outline"></span>
					<?php esc_html_e( 'Explanation', 'seo-repair-kit' ); ?>
				</strong>

				<p>
					<?php esc_html_e( 'Reports help you understand how your website pages connect with each other and where improvements are needed.', 'seo-repair-kit' ); ?>
				</p>
			</div>

		</div>

		<?php
	}
}