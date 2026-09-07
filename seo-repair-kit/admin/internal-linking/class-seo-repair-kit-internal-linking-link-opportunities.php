<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class SeoRepairKit_InternalLinking_Link_Opportunities {

	public function render() {
		$page         = isset( $_GET['srk_lo_page'] ) ? max( 1, absint( wp_unslash( $_GET['srk_lo_page'] ) ) ) : 1;
		$search       = isset( $_GET['srk_lo_search'] ) ? sanitize_text_field( wp_unslash( $_GET['srk_lo_search'] ) ) : '';
		$type         = isset( $_GET['srk_lo_type'] ) ? sanitize_key( wp_unslash( $_GET['srk_lo_type'] ) ) : 'all';
		$per_page_raw = isset( $_GET['srk_lo_per_page'] ) ? sanitize_text_field( wp_unslash( $_GET['srk_lo_per_page'] ) ) : '10';

		if ( ! in_array( $type, array( 'all', 'rule', 'ai' ), true ) ) {
			$type = 'all';
		}

		$per_page = 'all' === $per_page_raw ? 9999 : max( 5, absint( $per_page_raw ) );
		$summary  = class_exists( 'SRK_Internal_Linking_DB' ) ? SRK_Internal_Linking_DB::get_link_opportunities_summary() : array();
		$total    = class_exists( 'SRK_Internal_Linking_DB' ) ? SRK_Internal_Linking_DB::count_grouped_opportunities( $search, $type ) : 0;
		$rows     = class_exists( 'SRK_Internal_Linking_DB' ) ? SRK_Internal_Linking_DB::get_grouped_opportunities_rows( $page, $per_page, $search, $type ) : array();

		$total_pages = max( 1, (int) ceil( $total / $per_page ) );
		$from        = $total ? ( ( $page - 1 ) * $per_page ) + 1 : 0;
		$to          = min( $page * $per_page, $total );
		$filter_query_args = array(
			'page'            => 'seo-repair-kit-internal-linking',
			'srk_il_tab'      => 'link-opportunities',
			'srk_lo_per_page' => $per_page_raw,
		);
		?>

		<div class="srk-il-page srk-il-link-opportunities">

			<div class="srk-il-page-title srk-il-page-title-row">
				<div>
					<h2><?php esc_html_e( 'Link Opportunities', 'seo-repair-kit' ); ?></h2>
					<p><?php esc_html_e( 'Review and approve internal link suggestions generated from your indexed content and active target keywords.', 'seo-repair-kit' ); ?></p>
				</div>

				<div class="srk-il-title-actions">
					<span class="srk-lo-refresh-status"></span>
					<button type="button" class="button button-primary srk-lo-refresh-opportunities">
						<?php esc_html_e( 'Generate Opportunities', 'seo-repair-kit' ); ?>
					</button>
				</div>
			</div>

			<div class="srk-il-stats-grid">
				<div class="srk-il-stat-card srk-lo-stat-muted"><p><?php esc_html_e( 'Pending', 'seo-repair-kit' ); ?></p><span><?php echo esc_html( $summary['pending'] ?? 0 ); ?></span></div>
				<div class="srk-il-stat-card srk-lo-stat-blue">
					<p><?php esc_html_e( 'Highest Score', 'seo-repair-kit' ); ?></p>

					<span>
						<?php echo esc_html( absint( $summary['high_score'] ?? 0 ) ); ?>/100
					</span>
				</div>
				<div class="srk-il-stat-card srk-lo-stat-green"><p><?php esc_html_e( 'Approved Today', 'seo-repair-kit' ); ?></p><span><?php echo esc_html( $summary['approved_today'] ?? 0 ); ?></span></div>
				<div class="srk-il-stat-card srk-lo-stat-red"><p><?php esc_html_e( 'Ignored', 'seo-repair-kit' ); ?></p><span><?php echo esc_html( $summary['ignored'] ?? 0 ); ?></span></div>
			    </div>

				<div class="srk-il-filter-bar" style="margin-bottom: 16px;">
					<form method="get" class="srk-tk-search-form" style="flex: 1 1 430px; margin: 0;">
						<input type="hidden" name="page" value="seo-repair-kit-internal-linking" />
						<input type="hidden" name="srk_il_tab" value="link-opportunities" />
						<input type="hidden" name="srk_lo_type" value="<?php echo esc_attr( $type ); ?>" />
						<input type="hidden" name="srk_lo_per_page" value="<?php echo esc_attr( $per_page_raw ); ?>" />
						<label for="srk-lo-search"><?php esc_html_e( 'Search', 'seo-repair-kit' ); ?></label>
						<input type="search" id="srk-lo-search" name="srk_lo_search" value="<?php echo esc_attr( $search ); ?>" placeholder="<?php esc_attr_e( 'Search source, target, anchor...', 'seo-repair-kit' ); ?>" style="min-width: 280px;" />
						<button type="submit" class="button"><?php esc_html_e( 'Search', 'seo-repair-kit' ); ?></button>
						<?php if ( '' !== $search ) : ?>
							<?php
							$clear_url = add_query_arg(
								array_merge(
									$filter_query_args,
									array(
										'srk_lo_type' => $type,
									)
								),
								admin_url( 'admin.php' )
							);
							?>
							<a href="<?php echo esc_url( $clear_url ); ?>" class="button">
								<?php esc_html_e( 'Clear', 'seo-repair-kit' ); ?>
							</a>
						<?php endif; ?>
					</form>

					<div class="srk-il-source-filters">
						<span><?php esc_html_e( 'Suggestion Type:', 'seo-repair-kit' ); ?></span>

						<?php
						$type_filters = array(
							'all'  => __( 'All', 'seo-repair-kit' ),
							'rule' => __( 'Rule-Based', 'seo-repair-kit' ),
							'ai'   => __( 'AI Suggestion', 'seo-repair-kit' ),
						);

						foreach ( $type_filters as $type_key => $type_filter_label ) :
							$filter_url = add_query_arg(
								array_merge(
									$filter_query_args,
									array(
										'srk_lo_search' => $search,
										'srk_lo_type'   => $type_key,
									)
								),
								admin_url( 'admin.php' )
							);
							?>

							<a
								class="srk-il-chip <?php echo esc_attr( $type === $type_key ? 'is-active' : '' ); ?>"
								href="<?php echo esc_url( $filter_url ); ?>"
							>
								<?php echo esc_html( $type_filter_label ); ?>
							</a>

						<?php endforeach; ?>
					</div>
				</div>

				<div class="srk-il-table-card">
				<table class="srk-il-data-table srk-il-opportunities-table">
					<thead>
						<tr>
							<th><?php esc_html_e( 'Source Post', 'seo-repair-kit' ); ?></th>
							<th><?php esc_html_e( 'Anchor', 'seo-repair-kit' ); ?></th>
							<th><?php esc_html_e( 'Target Post', 'seo-repair-kit' ); ?></th>
							<th><?php esc_html_e( 'Type', 'seo-repair-kit' ); ?></th>
							<th><?php esc_html_e( 'Score', 'seo-repair-kit' ); ?></th>
							<th><?php esc_html_e( 'Actions', 'seo-repair-kit' ); ?></th>
						</tr>
					</thead>

					<tbody>
						<?php if ( empty( $rows ) ) : ?>
							<tr>
								<td colspan="6">
									<?php esc_html_e( 'No pending opportunities found. Run Content Index and Target Keywords first, then refresh opportunities.', 'seo-repair-kit' ); ?>
								</td>
							</tr>
						<?php else : ?>
							<?php foreach ( $rows as $row ) : ?>
								<?php
								$targets = ! empty( $row['targets'] ) && is_array( $row['targets'] )
									? $row['targets']
									: array();

								$first_target = ! empty( $targets[0] )
									? $targets[0]
									: array();

								$selected_type = sanitize_key(
									$first_target['type']
										?? $first_target['selected_type']
										?? $row['type']
										?? $row['selected_type']
										?? 'rule'
								);

								if ( ! in_array( $selected_type, array( 'rule', 'ai' ), true ) ) {
									$selected_type = 'rule';
								}

								$type_label = 'ai' === $selected_type
									? __( 'AI Semantic', 'seo-repair-kit' )
									: __( 'Rule-Based', 'seo-repair-kit' );
								?>
								<tr>
									<td class="srk-il-title-cell">
										<a href="<?php echo esc_url( get_edit_post_link( $row['source_post_id'] ) ); ?>" target="_blank" style="text-decoration: none; font-weight: 700;">
											<?php echo esc_html( $row['source_title'] ?: '#' . $row['source_post_id'] ); ?>
											<!-- <span class="dashicons dashicons-external" style="font-size: 13px; width: 13px; height: 13px; vertical-align: middle; color: #8a9ab8;"></span> -->
										</a>
									</td>

									<td><span class="srk-lo-anchor">&ldquo;<?php echo esc_html( $row['anchor_text'] ); ?>&rdquo;</span></td>

									<td>
                                    <?php if ( count( $targets ) > 1 ) : ?>
                                        <div style="display: flex; align-items: center; gap: 8px;">
                                            <select class="srk-lo-target-select">

                                                <?php foreach ( $targets as $target ) : ?>

                                                    <?php
                                                    $target_type = sanitize_key(
                                                        $target['type']
                                                            ?? $target['selected_type']
                                                            ?? 'rule'
                                                    );

                                                    if ( ! in_array( $target_type, array( 'rule', 'ai' ), true ) ) {
                                                        $target_type = 'rule';
                                                    }
                                                    ?>

                                                    <option
                                                        value="<?php echo esc_attr( absint( $target['opportunity_id'] ) ); ?>"
                                                        data-type="<?php echo esc_attr( $target_type ); ?>"
                                                        data-score="<?php echo esc_attr( absint( $target['score'] ?? 0 ) ); ?>"
                                                        data-reason="<?php echo esc_attr( $target['reason'] ?? '' ); ?>"
                                                        data-edit-url="<?php echo esc_url( get_edit_post_link( absint( $target['target_post_id'] ) ) ); ?>"
                                                    >
														<?php
														echo esc_html(
															( ! empty( $target['target_title'] )
																? $target['target_title']
																: __( 'Untitled target', 'seo-repair-kit' )
															) .
															' — ' .
															absint( $target['score'] ?? 0 ) .
															'/100'
														);
														?>
													</option>

												<?php endforeach; ?>

                                            </select>
                                            <a href="<?php echo esc_url( get_edit_post_link( absint( $first_target['target_post_id'] ) ) ); ?>" class="srk-lo-target-link" target="_blank" title="<?php esc_attr_e( 'View target post', 'seo-repair-kit' ); ?>" style="color: #2271b1; text-decoration: none; flex-shrink: 0;">
                                                <span class="dashicons dashicons-external" style="font-size: 16px; width: 16px; height: 16px;"></span>
                                            </a>
                                        </div>

                                        <?php elseif ( ! empty( $targets[0] ) ) : ?>

                                        <a
											href="<?php echo esc_url( get_edit_post_link( absint( $targets[0]['target_post_id'] ) ) ); ?>"
											target="_blank"
											style="text-decoration: none; font-weight: 600;"
											data-opportunity-id="<?php echo esc_attr( absint( $targets[0]['opportunity_id'] ) ); ?>"
										>
                                            <?php
                                            echo esc_html(
                                                ! empty( $targets[0]['target_title'] )
                                                    ? $targets[0]['target_title']
                                                    : __( 'Untitled target', 'seo-repair-kit' )
                                            );
                                            ?>
                                        </a>

										<?php endif; ?>
									</td>

									<td class="srk-lo-type-cell">
										<span
											class="srk-il-type-badge <?php echo esc_attr( 'ai' === $selected_type ? 'is-ai' : 'is-rule' ); ?>"
										>
											<?php echo esc_html( $type_label ); ?>
										</span>
									</td>

									<td><span class="srk-lo-score"><?php echo esc_html( $row['best_score'] ); ?></span></td>

									<td>
										<div class="srk-lo-actions">
											<button type="button" class="button-link srk-lo-insert" title="<?php esc_attr_e( 'Apply Link', 'seo-repair-kit' ); ?>">
												<span class="dashicons dashicons-yes"></span>
											</button>
											<button type="button" class="button-link srk-lo-ignore" title="<?php esc_attr_e( 'Ignore', 'seo-repair-kit' ); ?>">
												<span class="dashicons dashicons-no-alt"></span>
											</button>
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
							esc_html__( 'Showing %1$d-%2$d of %3$d opportunities', 'seo-repair-kit' ),
							absint( $from ),
							absint( $to ),
							absint( $total )
						);
						?>
					</span>

					<form method="get" class="srk-il-per-page-form">
						<input type="hidden" name="page" value="seo-repair-kit-internal-linking" />
						<input type="hidden" name="srk_il_tab" value="link-opportunities" />
						<?php if ( $search ) : ?>
							<input type="hidden" name="srk_lo_search" value="<?php echo esc_attr( $search ); ?>" />
						<?php endif; ?>
						<input type="hidden" name="srk_lo_type" value="<?php echo esc_attr( $type ); ?>" />
						<label>
							<?php esc_html_e( 'Rows per page', 'seo-repair-kit' ); ?>
							<select name="srk_lo_per_page" onchange="this.form.submit()">
								<?php foreach ( array( 5, 10, 20, 50, 100, 'all' ) as $size ) : ?>
									<option value="<?php echo esc_attr( $size ); ?>" <?php selected( $per_page_raw, (string) $size ); ?>><?php echo 'all' === $size ? esc_html__( 'All', 'seo-repair-kit' ) : esc_html( $size ); ?></option>
								<?php endforeach; ?>
							</select>
						</label>
					</form>

					<?php
					if ( class_exists( 'SeoRepairKit_InternalLinking' ) ) {
						SeoRepairKit_InternalLinking::render_pagination(
							'link-opportunities',
							'srk_lo_page',
							$page,
							$per_page,
							$total,
							array(
								'srk_lo_search'   => $search,
								'srk_lo_type'     => $type,
								'srk_lo_per_page' => $per_page_raw,
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