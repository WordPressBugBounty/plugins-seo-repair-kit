<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class SeoRepairKit_InternalLinking_Dashboard {

	public function render() {
		$dashboard_data = class_exists( 'SRK_Internal_Linking_DB' )
			? SRK_Internal_Linking_DB::get_dashboard_data()
			: array();

		$summary = wp_parse_args(
			$dashboard_data['summary'] ?? array(),
			array(
				'indexed_total'         => 0,
				'internal_links'        => 0,
				'pending_opportunities' => 0,
				'auto_links'            => 0,
				'orphan_total'          => 0,
				'health_score'          => 0,
				'health_message'        => __(
					'No indexed content data is available yet.',
					'seo-repair-kit'
				),
				'last_index'            => __(
					'Never',
					'seo-repair-kit'
				),
			)
		);

		$activity = ! empty( $dashboard_data['activity'] )
			? $dashboard_data['activity']
			: array();

		$activity_icons = array(
			'opportunities' => array(
				'class' => 'blue',
				'icon'  => 'dashicons-admin-links',
			),
			'orphans' => array(
				'class' => 'orange',
				'icon'  => 'dashicons-warning',
			),
			'auto' => array(
				'class' => 'green',
				'icon'  => 'dashicons-admin-generic',
			),
			'index' => array(
				'class' => 'slate',
				'icon'  => 'dashicons-search',
			),
		);
		?>

		<div class="srk-il-dashboard">

			<div class="srk-il-page-title">
				<h2>
					<?php esc_html_e(
						'Internal Linking Health Dashboard',
						'seo-repair-kit'
					); ?>
				</h2>

				<p>
					<?php esc_html_e(
						"Overview of your website's internal linking structure and opportunities.",
						'seo-repair-kit'
					); ?>
				</p>
			</div>

			<div class="srk-il-kpi-grid">

				<div class="srk-il-kpi-card border-blue">
					<span>
						<?php esc_html_e(
							'Indexed Content',
							'seo-repair-kit'
						); ?>
					</span>

					<strong data-srk-dashboard="indexed_total">
						<?php echo esc_html( $summary['indexed_total'] ); ?>
					</strong>
				</div>

				<div class="srk-il-kpi-card border-green">
					<span>
						<?php esc_html_e(
							'Internal Links',
							'seo-repair-kit'
						); ?>
					</span>

					<strong data-srk-dashboard="internal_links">
						<?php echo esc_html( $summary['internal_links'] ); ?>
					</strong>
				</div>

				<div class="srk-il-kpi-card border-orange">
					<span>
						<?php esc_html_e(
							'Opportunities',
							'seo-repair-kit'
						); ?>
					</span>

					<strong data-srk-dashboard="pending_opportunities">
						<?php echo esc_html( $summary['pending_opportunities'] ); ?>
					</strong>
				</div>

				<div class="srk-il-kpi-card border-indigo">
					<span>
						<?php esc_html_e(
							'Auto Links',
							'seo-repair-kit'
						); ?>
					</span>

					<strong data-srk-dashboard="auto_links">
						<?php echo esc_html( $summary['auto_links'] ); ?>
					</strong>
				</div>

				<div class="srk-il-kpi-card border-red">
					<span>
						<?php esc_html_e(
							'Orphan Content',
							'seo-repair-kit'
						); ?>
					</span>

					<strong data-srk-dashboard="orphan_total">
						<?php echo esc_html( $summary['orphan_total'] ); ?>
					</strong>
				</div>

				<div class="srk-il-kpi-card border-slate">
					<span>
						<?php esc_html_e(
							'Last Index',
							'seo-repair-kit'
						); ?>
					</span>

					<strong
						class="small"
						data-srk-dashboard="last_index"
					>
						<?php echo esc_html( $summary['last_index'] ); ?>
					</strong>
				</div>

			</div>

			<div class="srk-il-dashboard-grid">

				<div class="srk-il-panel srk-il-health-panel">

					<div class="srk-il-panel-header">
						<h3>
							<?php esc_html_e(
								'Health Score ',
								'seo-repair-kit'
							); ?>
						</h3>
					</div>

					<div class="srk-il-score-wrap">

						<?php
						$health_score = min(
							100,
							max(
								0,
								absint( $summary['health_score'] )
							)
						);
						?>

						<div
							class="srk-il-score-circle"
							style="--srk-health-score: <?php echo esc_attr( $health_score ); ?>;"
						>
							<div class="srk-il-score-circle-inner">
								<strong data-srk-dashboard="health_score">
									<?php echo esc_html( $health_score ); ?>
								</strong>

								<span>/100</span>
							</div>
						</div>

						<p data-srk-dashboard="health_message">
							<?php echo esc_html( $summary['health_message'] ); ?>
						</p>

					</div>

				</div>

				<div class="srk-il-panel">

					<div class="srk-il-panel-header">
						<h3>
							<?php esc_html_e(
								'Recent Activity',
								'seo-repair-kit'
							); ?>
						</h3>
					</div>

					<div
						class="srk-il-activity-list"
						data-srk-dashboard-activity
					>
						<?php if ( empty( $activity ) ) : ?>

							<div class="srk-il-activity-item">
								<div>
									<strong>
										<?php esc_html_e(
											'No activity is available yet.',
											'seo-repair-kit'
										); ?>
									</strong>
								</div>
							</div>

						<?php else : ?>

							<?php foreach ( $activity as $item ) : ?>
								<?php
								$type = sanitize_key(
									$item['type'] ?? ''
								);

								$icon = $activity_icons[ $type ]
									?? $activity_icons['index'];
								?>

								<div class="srk-il-activity-item">

									<span
										class="icon <?php echo esc_attr( $icon['class'] ); ?> dashicons <?php echo esc_attr( $icon['icon'] ); ?>"
									></span>

									<div>
										<strong>
											<?php echo esc_html( $item['text'] ?? '' ); ?>
										</strong>

										<small>
											<?php echo esc_html( $item['time'] ?? '' ); ?>
										</small>
									</div>

								</div>

							<?php endforeach; ?>

						<?php endif; ?>
					</div>

				</div>

				<div class="srk-il-panel">

					<div class="srk-il-panel-header">
						<h3>
							<?php esc_html_e(
								'Recommended Actions',
								'seo-repair-kit'
							); ?>
						</h3>
					</div>

					<div class="srk-il-action-list">

						<a
							href="#"
							class="srk-il-action-card"
							data-srk-il-tab-jump="content-index"
						>
							<span class="action-icon blue dashicons dashicons-search"></span>

							<strong>
								<?php esc_html_e(
									'Run Content Index',
									'seo-repair-kit'
								); ?>
							</strong>

							<span class="dashicons dashicons-arrow-right-alt2"></span>
						</a>

						<a
							href="#"
							class="srk-il-action-card"
							data-srk-il-tab-jump="link-opportunities"
						>
							<span class="action-icon orange dashicons dashicons-admin-links"></span>

							<strong>
								<?php esc_html_e(
									'Review Link Opportunities',
									'seo-repair-kit'
								); ?>
							</strong>

							<span class="dashicons dashicons-arrow-right-alt2"></span>
						</a>

						<a
							href="#"
							class="srk-il-action-card"
							data-srk-il-tab-jump="orphan-content"
						>
							<span class="action-icon red dashicons dashicons-warning"></span>

							<strong>
								<?php esc_html_e(
									'Fix Orphan Content',
									'seo-repair-kit'
								); ?>
							</strong>

							<span class="dashicons dashicons-arrow-right-alt2"></span>
						</a>

					</div>

				</div>

			</div>

		</div>

		<?php
	}
}