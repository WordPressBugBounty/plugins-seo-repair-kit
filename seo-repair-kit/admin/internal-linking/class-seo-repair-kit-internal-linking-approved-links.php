<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class SeoRepairKit_InternalLinking_Approved_Links {

	public function render() {
		$page         = isset( $_GET['srk_al_page'] ) ? max( 1, absint( wp_unslash( $_GET['srk_al_page'] ) ) ) : 1;
		$per_page_raw = isset( $_GET['srk_al_per_page'] ) ? wp_unslash( $_GET['srk_al_per_page'] ) : '10';
		$per_page     = 'all' === $per_page_raw ? 9999 : max( 5, absint( $per_page_raw ) );
		
		$summary = SRK_Internal_Linking_DB::get_inserted_links_summary();
		$rows    = SRK_Internal_Linking_DB::get_inserted_links_rows( $page, $per_page );
		$total   = absint( SRK_Internal_Linking_DB::count_inserted_links() );

		$from = $total > 0 ? ( ( $page - 1 ) * $per_page ) + 1 : 0;
		$to   = $total > 0 ? min( $page * $per_page, $total ) : 0;
		?>
		<div class="srk-il-page srk-il-approved-links">

			<div class="srk-il-page-title">
				<h2><?php esc_html_e( 'Inserted Links', 'seo-repair-kit' ); ?></h2>
				<p><?php esc_html_e( 'Manage internal links inserted from opportunities or removed from content.', 'seo-repair-kit' ); ?></p>
			</div>

			<div class="srk-il-stats-grid">
				<div class="srk-il-stat-card srk-al-stat-green"><p><?php esc_html_e( 'Inserted', 'seo-repair-kit' ); ?></p><span><?php echo esc_html( $summary['inserted'] ); ?></span></div>
				<div class="srk-il-stat-card srk-al-stat-orange"><p><?php esc_html_e( 'Pending Opportunities', 'seo-repair-kit' ); ?></p><span><?php echo esc_html( $summary['pending'] ); ?></span></div>
				<div class="srk-il-stat-card srk-al-stat-muted"><p><?php esc_html_e( 'Ignored', 'seo-repair-kit' ); ?></p><span><?php echo esc_html( $summary['ignored'] ); ?></span></div>
				<div class="srk-il-stat-card srk-al-stat-red"><p><?php esc_html_e( 'Removed', 'seo-repair-kit' ); ?></p><span><?php echo esc_html( $summary['removed'] ); ?></span></div>
			</div>

			<div class="srk-il-table-card">
				<table class="srk-il-data-table srk-il-approved-table">
					<thead>
						<tr>
							<th><?php esc_html_e( 'Source Post', 'seo-repair-kit' ); ?></th>
							<th><?php esc_html_e( 'Anchor Text', 'seo-repair-kit' ); ?></th>
							<th><?php esc_html_e( 'Target URL', 'seo-repair-kit' ); ?></th>
							<th><?php esc_html_e( 'Status', 'seo-repair-kit' ); ?></th>
							<th><?php esc_html_e( 'Date', 'seo-repair-kit' ); ?></th>
							<th><?php esc_html_e( 'Actions', 'seo-repair-kit' ); ?></th>
						</tr>
					</thead>

					<tbody>
						<?php if ( empty( $rows ) ) : ?>
							<tr>
								<td colspan="6"><?php esc_html_e( 'No approved links found yet.', 'seo-repair-kit' ); ?></td>
							</tr>
						<?php else : ?>
							<?php foreach ( $rows as $row ) : ?>
								<tr data-opportunity-id="<?php echo esc_attr( $row['id'] ); ?>">
									<td class="srk-il-title-cell">
										<a
											href="<?php echo esc_url( get_permalink( absint( $row['source_post_id'] ) ) ); ?>"
											target="_blank"
											rel="noopener noreferrer"
											class="srk-al-post-link"
										>
											<?php echo esc_html( $row['source_title'] ?: '#' . absint( $row['source_post_id'] ) ); ?>
										</a>
									</td>

									<td><strong>&ldquo;<?php echo esc_html( $row['anchor_text'] ); ?>&rdquo;</strong></td>

									<td class="srk-il-title-cell">
										<a
											href="<?php echo esc_url( get_permalink( absint( $row['target_post_id'] ) ) ); ?>"
											target="_blank"
											rel="noopener noreferrer"
											class="srk-al-post-link"
										>
											<?php echo esc_html( $row['target_title'] ?: '#' . absint( $row['target_post_id'] ) ); ?>
										</a>
									</td>
									<td><span class="srk-il-status <?php echo esc_attr( 'inserted' === $row['status'] ? 'success' : ( 'removed' === $row['status'] ? 'danger' : 'warning' ) ); ?>"><?php echo esc_html( ucfirst( $row['status'] ) ); ?></span></td>
									<td><?php echo esc_html( mysql2date( get_option( 'date_format' ), $row['updated_at'] ) ); ?></td>
									<td>
										<div class="srk-ci-actions">
											<a
												class="button-link srk-il-table-action srk-ci-view-action"
												href="<?php echo esc_url( get_permalink( absint( $row['source_post_id'] ) ) ); ?>"
												target="_blank"
												rel="noopener noreferrer"
												title="<?php esc_attr_e( 'View post', 'seo-repair-kit' ); ?>"
												aria-label="<?php esc_attr_e( 'View post', 'seo-repair-kit' ); ?>"
											>
												<span class="dashicons dashicons-visibility"></span>
											</a>

											<?php if ( 'inserted' === $row['status'] ) : ?>
												<button
													type="button"
													class="button-link srk-il-table-action srk-al-remove-link"
													title="<?php esc_attr_e( 'Remove inserted link', 'seo-repair-kit' ); ?>"
													aria-label="<?php esc_attr_e( 'Remove inserted link', 'seo-repair-kit' ); ?>"
													data-opportunity-id="<?php echo esc_attr( $row['id'] ); ?>"
												>
													<span class="dashicons dashicons-undo"></span>
												</button>
											<?php endif; ?>
										</div>
									</td>
								</tr>
							<?php endforeach; ?>
						<?php endif; ?>
					</tbody>
				</table>

				<div class="srk-il-pagination">
					<span>
						<?php
						printf(
							esc_html__( 'Showing %1$d-%2$d of %3$d inserted links', 'seo-repair-kit' ),
							absint( $from ),
							absint( $to ),
							absint( $total )
						);
						?>
					</span>

					<form method="get" class="srk-il-per-page-form">
						<input type="hidden" name="page" value="seo-repair-kit-internal-linking" />
						<input type="hidden" name="srk_il_tab" value="approved-links" />
						
						<label>
							<?php esc_html_e( 'Rows per page', 'seo-repair-kit' ); ?>
							<select name="srk_al_per_page" onchange="this.form.submit();">
								<?php foreach ( array( 5, 10, 20, 50, 100, 'all' ) as $option ) : ?>
									<option value="<?php echo esc_attr( $option ); ?>" <?php selected( $per_page, $option ); ?>>
										<?php echo 'all' === $option ? esc_html__( 'All', 'seo-repair-kit' ) : esc_html( $option ); ?>
									</option>
								<?php endforeach; ?>
							</select>
						</label>
					</form>

					<?php
					if ( class_exists( 'SeoRepairKit_InternalLinking' ) ) {
						SeoRepairKit_InternalLinking::render_pagination(
							'approved-links',
							'srk_al_page',
							$page,
							$per_page,
							$total,
							array(
								'srk_al_per_page' => $per_page,
							)
						);
					}
					?>
				</div>
			</div>
		</div>
		<?php
	}
}
