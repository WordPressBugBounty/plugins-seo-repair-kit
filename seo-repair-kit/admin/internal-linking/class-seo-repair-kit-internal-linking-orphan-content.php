<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class SeoRepairKit_InternalLinking_Orphan_Content {

	public function render() {
		$page = isset( $_GET['oc_paged'] )
			? max( 1, absint( wp_unslash( $_GET['oc_paged'] ) ) )
			: 1;

		$per_page_raw = isset( $_GET['oc_per_page'] )
			? sanitize_text_field( wp_unslash( $_GET['oc_per_page'] ) )
			: '10';

		$status = isset( $_GET['oc_status'] )
			? sanitize_key( wp_unslash( $_GET['oc_status'] ) )
			: 'all';

		$allowed_per_page = array(
			'5',
			'10',
			'20',
			'50',
			'100',
			'all',
		);

		if ( ! in_array( $per_page_raw, $allowed_per_page, true ) ) {
			$per_page_raw = '10';
		}

		if ( ! in_array( $status, array( 'all', 'critical', 'low', 'healthy', 'ignored' ), true ) ) {
			$status = 'all';
		}

		if ( ! in_array( $status, array( 'all', 'critical', 'low', 'healthy', 'ignored' ), true ) ) {
			$status = 'all';
		}

		if ( class_exists( 'SRK_Internal_Linking_DB' ) ) {

			$summary = SRK_Internal_Linking_DB::get_orphan_summary();

			$total = SRK_Internal_Linking_DB::count_orphan_content_rows(
				$status
			);

			/*
			* Keep the selected UI value separate from
			* the numeric database limit.
			*/
			if ( 'all' === $per_page_raw ) {
				$per_page = max( 1, $total );
				$page     = 1;
			} else {
				$per_page = absint( $per_page_raw );
			}

			$rows = SRK_Internal_Linking_DB::get_orphan_content_rows(
				$page,
				$per_page,
				$status
			);

		} else {

			$summary = array(
				'critical' => 0,
				'low'      => 0,
				'healthy'  => 0,
				'ignored'  => 0,
			);

			$rows     = array();
			$total    = 0;
			$per_page = 10;
		}
		if ( 'all' === $per_page_raw ) {

			$from = $total > 0 ? 1 : 0;
			$to   = $total;

		} else {

			$from = $total > 0
				? ( ( $page - 1 ) * $per_page ) + 1
				: 0;

			$to = min(
				$page * $per_page,
				$total
			);
		}
		?>
		<div class="srk-il-page srk-il-orphan-content">

			<div class="srk-il-page-title srk-oc-page-header">
				<div class="srk-oc-page-heading">
					<h2>
						<?php esc_html_e( 'Orphan Content Discovery', 'seo-repair-kit' ); ?>
					</h2>

					<p>
						<?php esc_html_e(
							'Detect pages that have no inbound links, making them hard for users and Google to find.',
							'seo-repair-kit'
						); ?>
					</p>
				</div>

				<button
					type="button"
					class="button button-primary srk-oc-refresh srk-oc-refresh-button"
				>
					<span class="srk-oc-refresh-label">
						<?php esc_html_e(
							'Refresh Orphan Status',
							'seo-repair-kit'
						); ?>
					</span>
				</button>
			</div>

			<div class="srk-il-stats-grid">
				<div class="srk-il-stat-card srk-oc-stat-red">
					<p><?php esc_html_e( 'Critical Orphans', 'seo-repair-kit' ); ?></p>
					<span data-srk-oc-summary="critical">⚠ <?php echo esc_html( $summary['critical'] ); ?></span>
				</div>

				<div class="srk-il-stat-card srk-oc-stat-orange">
					<p><?php esc_html_e( 'Low Inbound', 'seo-repair-kit' ); ?></p>
					<span data-srk-oc-summary="low"><?php echo esc_html( $summary['low'] ); ?></span>
				</div>

				<div class="srk-il-stat-card srk-oc-stat-green">
					<p><?php esc_html_e( 'Healthy Content', 'seo-repair-kit' ); ?></p>
					<span data-srk-oc-summary="healthy"><?php echo esc_html( $summary['healthy'] ); ?></span>
				</div>

				<div class="srk-il-stat-card srk-oc-stat-muted">
					<p><?php esc_html_e( 'Ignored Items', 'seo-repair-kit' ); ?></p>
					<span data-srk-oc-summary="ignored"><?php echo esc_html( $summary['ignored'] ); ?></span>
				</div>
			</div>

			<div class="srk-il-card srk-oc-filter-card">
				<form method="get" class="srk-il-filter-row srk-oc-filter-row">
					<input
						type="hidden"
						name="page"
						value="seo-repair-kit-internal-linking"
					/>

					<input
						type="hidden"
						name="srk_il_tab"
						value="orphan-content"
					/>

					<div class="srk-oc-filter-field">
						<label for="srk-oc-status">
							<?php esc_html_e(
								'Risk Level',
								'seo-repair-kit'
							); ?>
						</label>

						<select
							id="srk-oc-status"
							name="oc_status"
							class="srk-oc-status-select"
						>
							<option
								value="all"
								<?php selected( $status, 'all' ); ?>
							>
								<?php esc_html_e(
									'All Active',
									'seo-repair-kit'
								); ?>
							</option>

							<option
								value="critical"
								<?php selected( $status, 'critical' ); ?>
							>
								<?php esc_html_e(
									'Critical',
									'seo-repair-kit'
								); ?>
							</option>

							<option
								value="low"
								<?php selected( $status, 'low' ); ?>
							>
								<?php esc_html_e(
									'Low',
									'seo-repair-kit'
								); ?>
							</option>

							<option
								value="healthy"
								<?php selected( $status, 'healthy' ); ?>
							>
								<?php esc_html_e(
									'Healthy',
									'seo-repair-kit'
								); ?>
							</option>

							<option
								value="ignored"
								<?php selected( $status, 'ignored' ); ?>
							>
								<?php esc_html_e(
									'Ignored',
									'seo-repair-kit'
								); ?>
							</option>
						</select>
					</div>

					<button
						type="submit"
						class="button button-primary srk-oc-filter-button"
					>
						<span>
							<?php esc_html_e(
								'Filter',
								'seo-repair-kit'
							); ?>
						</span>
					</button>
				</form>
			</div>
			<div class="srk-il-table-card">
				<table class="srk-il-data-table srk-il-orphan-table">
					<thead>
						<tr>
							<th><?php esc_html_e( 'Post/Page Title', 'seo-repair-kit' ); ?></th>
							<th><?php esc_html_e( 'Type', 'seo-repair-kit' ); ?></th>
							<th><?php esc_html_e( 'Inbound Links', 'seo-repair-kit' ); ?></th>
							<th><?php esc_html_e( 'Risk Level', 'seo-repair-kit' ); ?></th>
							<th><?php esc_html_e( 'Actions', 'seo-repair-kit' ); ?></th>
						</tr>
					</thead>

					<tbody>
						<?php if ( ! empty( $rows ) ) : ?>
							<?php foreach ( $rows as $row ) : ?>
								<?php
								$post_id       = absint( $row['post_id'] );
								$orphan_status = sanitize_key( $row['orphan_status'] );
								$risk_label    = $this->get_risk_label( $orphan_status );
								?>
								<tr data-post-id="<?php echo esc_attr( $post_id ); ?>">
									<td class="srk-il-title-cell">
										<a href="<?php echo esc_url( get_edit_post_link( $post_id ) ); ?>">
											<?php echo esc_html( $row['post_title'] ); ?>
										</a>
										<?php if ( ! empty( $row['post_url'] ) ) : ?>
										<?php endif; ?>
									</td>

									<td><?php echo esc_html( ucfirst( $row['post_type'] ) ); ?></td>

									<td><strong><?php echo esc_html( absint( $row['internal_inbound_count'] ) ); ?></strong></td>

									<td>
										<span class="srk-oc-risk <?php echo esc_attr( $orphan_status ); ?>">
											<?php echo esc_html( $risk_label ); ?>
										</span>
									</td>

									<td>
										<div class="srk-oc-actions">
											<?php if ( 'ignored' !== $orphan_status ) : ?>
												<button type="button" class="button-link srk-oc-find" data-post-id="<?php echo esc_attr( $post_id ); ?>">
													<?php esc_html_e( 'Find Opportunities', 'seo-repair-kit' ); ?>
												</button>

												<button type="button" class="button-link srk-oc-ignore" data-post-id="<?php echo esc_attr( $post_id ); ?>">
													<?php esc_html_e( 'Ignore', 'seo-repair-kit' ); ?>
												</button>
											<?php else : ?>
												<span class="srk-il-muted-text"><?php esc_html_e( 'Ignored', 'seo-repair-kit' ); ?></span>
											<?php endif; ?>
										</div>
									</td>
								</tr>
							<?php endforeach; ?>
						<?php else : ?>
							<tr>
								<td colspan="5">
									<?php esc_html_e( 'No orphan content found. Run Content Index first or refresh orphan status.', 'seo-repair-kit' ); ?>
								</td>
							</tr>
						<?php endif; ?>
					</tbody>
				</table>

				<div class="srk-il-pagination">

					<span>
						<?php
						printf(
							esc_html__(
								'Showing %1$d-%2$d of %3$d orphan items',
								'seo-repair-kit'
							),
							absint( $from ),
							absint( $to ),
							absint( $total )
						);
						?>
					</span>

					<form
						method="get"
						class="srk-il-per-page-form"
					>
						<input
							type="hidden"
							name="page"
							value="seo-repair-kit-internal-linking"
						/>

						<input
							type="hidden"
							name="srk_il_tab"
							value="orphan-content"
						/>

						<input
							type="hidden"
							name="oc_status"
							value="<?php echo esc_attr( $status ); ?>"
						/>

						<label>
							<?php esc_html_e(
								'Rows per page',
								'seo-repair-kit'
							); ?>

							<select
								name="oc_per_page"
								onchange="this.form.submit();"
							>
								<?php
								foreach (
									array( 5, 10, 20, 50, 100, 'all' )
									as $option
								) :
									?>

									<option
										value="<?php echo esc_attr( $option ); ?>"
										<?php selected(
											$per_page_raw,
											(string) $option
										); ?>
									>
										<?php
										echo 'all' === $option
											? esc_html__(
												'All',
												'seo-repair-kit'
											)
											: esc_html( $option );
										?>
									</option>

								<?php endforeach; ?>
							</select>
						</label>
					</form>

					<?php
					if ( class_exists( 'SeoRepairKit_InternalLinking' ) ) {

						SeoRepairKit_InternalLinking::render_pagination(
							'orphan-content',
							'oc_paged',
							$page,
							$per_page,
							$total,
							array(
								'oc_status'   => $status,
								'oc_per_page' => $per_page_raw,
							)
						);
					}
					?>

				</div>
			</div>

			<div class="srk-il-info-box">
				<strong><span class="dashicons dashicons-info-outline"></span><?php esc_html_e( 'Layman Explanation', 'seo-repair-kit' ); ?></strong>
				<p><?php esc_html_e( 'Orphan Content means pages that no other page links to. These pages are harder for users and search engines to discover.', 'seo-repair-kit' ); ?></p>
			</div>

			<div class="srk-oc-modal" style="display:none;">
				<div class="srk-oc-modal-panel">
					<div class="srk-oc-modal-header">
						<div>
							<h2><?php esc_html_e( 'Orphan Link Opportunities', 'seo-repair-kit' ); ?></h2>
							<p class="srk-oc-modal-subtitle"></p>
						</div>

						<button type="button" class="button button-secondary srk-oc-modal-close">
							<?php esc_html_e( 'Close', 'seo-repair-kit' ); ?>
						</button>
					</div>

					<div class="srk-oc-modal-body">
						<table class="srk-il-data-table">
							<thead>
								<tr>
									<th><?php esc_html_e( 'Source Page', 'seo-repair-kit' ); ?></th>
									<th><?php esc_html_e( 'Anchor Text', 'seo-repair-kit' ); ?></th>
									<th><?php esc_html_e( 'Sentence', 'seo-repair-kit' ); ?></th>
									<th><?php esc_html_e( 'Score', 'seo-repair-kit' ); ?></th>
									<th><?php esc_html_e( 'Actions', 'seo-repair-kit' ); ?></th>
								</tr>
							</thead>
							<tbody class="srk-oc-modal-results">
								<tr>
									<td colspan="5"><?php esc_html_e( 'No opportunities loaded.', 'seo-repair-kit' ); ?></td>
								</tr>
							</tbody>
						</table>
					</div>
				</div>
			</div>

		</div>
		<?php
	}

	private function get_risk_label( $status ) {
		$labels = array(
			'critical' => __( 'Critical', 'seo-repair-kit' ),
			'low'      => __( 'Low', 'seo-repair-kit' ),
			'healthy'  => __( 'Healthy', 'seo-repair-kit' ),
			'ignored'  => __( 'Ignored', 'seo-repair-kit' ),
			'unknown'  => __( 'Unknown', 'seo-repair-kit' ),
		);

		return isset( $labels[ $status ] ) ? $labels[ $status ] : $labels['unknown'];
	}
}