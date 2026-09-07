<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class SeoRepairKit_InternalLinking_Target_Keywords {

	public function render() {
		$summary = class_exists( 'SRK_Internal_Linking_DB' ) ? SRK_Internal_Linking_DB::get_target_keyword_summary() : array();
		$page = isset( $_GET['srk_tk_page'] )
			? max( 1, absint( wp_unslash( $_GET['srk_tk_page'] ) ) )
			: 1;

		$per_page_raw = isset( $_GET['srk_tk_per_page'] )
			? sanitize_text_field( wp_unslash( $_GET['srk_tk_per_page'] ) )
			: '10';

		$search = isset( $_GET['srk_tk_search'] )
			? sanitize_text_field( wp_unslash( $_GET['srk_tk_search'] ) )
			: '';

		$source = isset( $_GET['srk_tk_source'] )
			? sanitize_key( wp_unslash( $_GET['srk_tk_source'] ) )
			: 'all';

		$allowed_per_page = array(
			'5',
			'10',
			'20',
			'50',
			'100',
			'all',
		);

		$allowed_sources = array(
			'all',
			'title',
			'slug',
			'taxonomy',
			'custom',
		);

		if ( ! in_array( $per_page_raw, $allowed_per_page, true ) ) {
			$per_page_raw = '10';
		}

		if ( ! in_array( $source, $allowed_sources, true ) ) {
			$source = 'all';
		}

		$total = (
			class_exists( 'SRK_Internal_Linking_DB' ) &&
			method_exists(
				'SRK_Internal_Linking_DB',
				'count_target_keyword_rows'
			)
		)
			? SRK_Internal_Linking_DB::count_target_keyword_rows(
				$search,
				$source
			)
			: 0;

		/*
		* Keep the UI value separate from the numeric DB limit.
		*/
		if ( 'all' === $per_page_raw ) {
			$per_page = max( 1, $total );
			$page     = 1;
		} else {
			$per_page = absint( $per_page_raw );
		}

		$rows = class_exists( 'SRK_Internal_Linking_DB' )
			? SRK_Internal_Linking_DB::get_target_keyword_rows(
				$page,
				$per_page,
				$search,
				$source
			)
			: array();

		/*
		* Pagination range.
		*/
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

		$summary = wp_parse_args(
			$summary,
			array(
				'indexed_posts'   => 0,
				'covered_posts'   => 0,
				'total_keywords'  => 0,
				'custom_keywords' => 0,
				'gsc_impressions' => 0,
			)
		);
		?>
		<div class="srk-il-page srk-il-target-keywords">

			<div class="srk-il-page-title srk-il-page-title-row">
				<div>
					<h2><?php esc_html_e( 'Target Keywords Engine', 'seo-repair-kit' ); ?></h2>
				</div>

				<div class="srk-il-title-actions">
					<button
						type="button"
						class="button button-primary srk-oc-refresh srk-oc-refresh-button"
					>
						<?php esc_html_e( 'Refresh Keywords', 'seo-repair-kit' ); ?>
					</button>
				</div>
			</div>

			<div class="srk-il-stats-grid">
				<div class="srk-il-stat-card srk-tk-stat-purple">
					<p><?php esc_html_e( 'Post Coverage', 'seo-repair-kit' ); ?></p>
					<span><?php echo esc_html( $summary['covered_posts'] ); ?> <small>/ <?php echo esc_html( $summary['indexed_posts'] ); ?></small></span>
				</div>
				<div class="srk-il-stat-card srk-tk-stat-green">
					<p><?php esc_html_e( 'Total Keywords', 'seo-repair-kit' ); ?></p>
					<span><?php echo esc_html( $summary['total_keywords'] ); ?></span>
				</div>
				<div class="srk-il-stat-card srk-tk-stat-purple">
					<p><?php esc_html_e( 'Custom/Manual', 'seo-repair-kit' ); ?></p>
					<span><?php echo esc_html( $summary['custom_keywords'] ); ?></span>
				</div>
				<div class="srk-il-stat-card srk-tk-stat-orange">
					<p><?php esc_html_e( 'GSC Impressions', 'seo-repair-kit' ); ?></p>
					<span><?php echo esc_html( number_format_i18n( $summary['gsc_impressions'] ) ); ?></span>
				</div>
			</div>

			<div class="srk-il-card srk-tk-toolbar">
				<form method="get" class="srk-tk-search-form">
					<input type="hidden" name="page" value="seo-repair-kit-internal-linking" />
					<input type="hidden" name="srk_il_tab" value="target-keywords" />
					<input type="hidden" name="srk_tk_source" value="<?php echo esc_attr( $source ); ?>" />
					<input
						type="hidden"
						name="srk_tk_per_page"
						value="<?php echo esc_attr( $per_page_raw ); ?>"
					/>

					<label for="srk-tk-search"><?php esc_html_e( 'Search', 'seo-repair-kit' ); ?></label>

					<input
						type="search"
						id="srk-tk-search"
						name="srk_tk_search"
						value="<?php echo esc_attr( $search ); ?>"
						placeholder="<?php esc_attr_e( 'Search post title or keyword...', 'seo-repair-kit' ); ?>"
					/>

					<button type="submit" class="button"><?php esc_html_e( 'Filter', 'seo-repair-kit' ); ?></button>

					<?php if ( '' !== $search || 'all' !== $source ) : ?>
						<a class="button button-secondary" href="<?php echo esc_url( admin_url( 'admin.php?page=seo-repair-kit-internal-linking&srk_il_tab=target-keywords' ) ); ?>">
							<?php esc_html_e( 'Reset', 'seo-repair-kit' ); ?>
						</a>
					<?php endif; ?>
				</form>

				<div class="srk-il-source-filters srk-tk-source-right">
					<span><?php esc_html_e( 'Active Sources:', 'seo-repair-kit' ); ?></span>

					<?php
					$sources = array(
						'all'      => __( 'All', 'seo-repair-kit' ),
						'title'    => __( 'Title', 'seo-repair-kit' ),
						'slug'     => __( 'Slug', 'seo-repair-kit' ),
						'taxonomy' => __( 'Taxonomy', 'seo-repair-kit' ),
						'custom'   => __( 'Custom', 'seo-repair-kit' ),
					);

					foreach ( $sources as $source_key => $source_label ) :
						$url = add_query_arg(
							array_filter(
								array(
									'page'            => 'seo-repair-kit-internal-linking',
									'srk_il_tab'      => 'target-keywords',
									'srk_tk_search'   => $search,
									'srk_tk_source'   => $source_key,
									'srk_tk_per_page' => $per_page_raw,
								)
							),
							admin_url( 'admin.php' )
						);
						?>
						<a class="srk-il-chip <?php echo esc_attr( $source === $source_key ? 'is-active' : '' ); ?>" href="<?php echo esc_url( $url ); ?>">
							<?php echo esc_html( $source_label ); ?>
						</a>
					<?php endforeach; ?>
				</div>

				<span class="srk-tk-refresh-status"></span>
			</div>

			<div class="srk-il-table-card">
				<table class="srk-il-data-table srk-il-keywords-table">
					<thead>
						<tr>
							<th><?php esc_html_e( 'Target Post', 'seo-repair-kit' ); ?></th>
							<th><?php esc_html_e( 'Active Keywords', 'seo-repair-kit' ); ?></th>
							<th><?php esc_html_e( 'Clicks', 'seo-repair-kit' ); ?></th>
							<th><?php esc_html_e( 'Impr.', 'seo-repair-kit' ); ?></th>
							<th><?php esc_html_e( 'CTR', 'seo-repair-kit' ); ?></th>
							<th><?php esc_html_e( 'Avg. Pos', 'seo-repair-kit' ); ?></th>
							<th><?php esc_html_e( 'Actions', 'seo-repair-kit' ); ?></th>
						</tr>
					</thead>
					<tbody>
						<?php if ( ! empty( $rows ) ) : ?>
							<?php foreach ( $rows as $row ) : ?>
								<?php
								$keywords = ! empty( $row['keywords'] ) ? explode( '||', $row['keywords'] ) : array();

								$keywords = array_values(
									array_filter(
										array_unique( array_map( 'trim', $keywords ) ),
										function ( $keyword ) {
											return 'uncategorized' !== sanitize_title( $keyword );
										}
									)
								);

								$keywords = array_slice( $keywords, 0, 6 );
								$sources  = ! empty( $row['sources'] ) ? explode( ',', $row['sources'] ) : array();
								$clicks   = absint( $row['clicks'] );
								$impr     = absint( $row['impressions'] );
								$ctr      = $impr > 0 ? round( ( $clicks / $impr ) * 100, 2 ) : 0;
								?>
								<tr>
									<td class="srk-il-title-cell">
										<?php echo esc_html( $row['post_title'] ); ?>
										<div class="srk-tk-source-tags">
											<?php foreach ( $sources as $source ) : ?>
												<span><?php echo esc_html( ucfirst( $source ) ); ?></span>
											<?php endforeach; ?>
										</div>
									</td>
									<td>
										<div class="srk-tk-keyword-list" data-post-id="<?php echo esc_attr( absint( $row['post_id'] ) ); ?>">
											<?php foreach ( $keywords as $keyword ) : ?>
												<span><?php echo esc_html( $keyword ); ?></span>
											<?php endforeach; ?>
										</div>
									</td>
									<td><?php echo esc_html( number_format_i18n( $clicks ) ); ?></td>
									<td><?php echo esc_html( number_format_i18n( $impr ) ); ?></td>
									<td><strong><?php echo esc_html( $ctr ); ?>%</strong></td>
									<td><span class="srk-tk-position"><?php echo esc_html( round( floatval( $row['avg_position'] ), 1 ) ); ?></span></td>
									<td>
										<button
											type="button"
											class="button button-small srk-tk-manage-keywords"
											data-post-id="<?php echo esc_attr( absint( $row['post_id'] ) ); ?>"
											data-post-title="<?php echo esc_attr( $row['post_title'] ); ?>"
										>
											<?php esc_html_e( 'Manage', 'seo-repair-kit' ); ?>
										</button>
									</td>
								</tr>
							<?php endforeach; ?>
						<?php else : ?>
							<tr>
								<td colspan="7"><?php esc_html_e( 'No keywords found. First run Content Index, then click Refresh Keywords.', 'seo-repair-kit' ); ?></td>
							</tr>
						<?php endif; ?>
					</tbody>
				</table>

				<div class="srk-il-pagination">
					<span>
						<?php
						printf(
							esc_html__( 'Showing %1$d-%2$d of %3$d keyword groups', 'seo-repair-kit' ),
							absint( $from ),
							absint( $to ),
							absint( $total )
						);
						?>
					</span>

					<form method="get" class="srk-il-per-page-form">
						<input type="hidden" name="page" value="seo-repair-kit-internal-linking" />
						<input type="hidden" name="srk_il_tab" value="target-keywords" />
						<input type="hidden" name="srk_tk_search" value="<?php echo esc_attr( $search ); ?>" />
						<input type="hidden" name="srk_tk_source" value="<?php echo esc_attr( $source ); ?>" />
						
						<label>
							<?php esc_html_e( 'Rows per page', 'seo-repair-kit' ); ?>
							<select name="srk_tk_per_page" onchange="this.form.submit();">
								<?php foreach ( array( 5, 10, 20, 50, 100, 'all' ) as $option ) : ?>
									<option
										value="<?php echo esc_attr( $option ); ?>"
										<?php selected( $per_page_raw, (string) $option ); ?>
									>
										<?php echo 'all' === $option ? esc_html__( 'All', 'seo-repair-kit' ) : esc_html( $option ); ?>
									</option>
								<?php endforeach; ?>
							</select>
						</label>
					</form>

					<?php
					if ( class_exists( 'SeoRepairKit_InternalLinking' ) ) {
						SeoRepairKit_InternalLinking::render_pagination(
							'target-keywords',
							'srk_tk_page',
							$page,
							$per_page,
							$total,
							array(
								'srk_tk_search'   => $search,
								'srk_tk_source'   => $source,
								'srk_tk_per_page' => $per_page_raw,
							)
						);
					}
					?>
				</div>
			</div>

			<div class="srk-tk-modal" hidden>
				<div class="srk-tk-modal-backdrop"></div>

				<div class="srk-tk-modal-panel">
					<div class="srk-tk-modal-header">
						<div>
							<h3 class="srk-tk-modal-title"></h3>
							<p><?php esc_html_e( 'Manage detected and custom keywords for this target post.', 'seo-repair-kit' ); ?></p>
						</div>

						<button type="button" class="button-link srk-tk-modal-close">
							<span class="dashicons dashicons-no-alt"></span>
						</button>
					</div>

					<div class="srk-tk-modal-body">
						<h4><?php esc_html_e( 'Detected Keywords', 'seo-repair-kit' ); ?></h4>
						<div class="srk-tk-modal-detected"></div>

						<h4><?php esc_html_e( 'Custom Keywords', 'seo-repair-kit' ); ?></h4>
						<div class="srk-tk-modal-custom"></div>

						<div class="srk-tk-modal-add">
							<input type="text" class="srk-tk-modal-input" placeholder="<?php esc_attr_e( 'Add custom keyword...', 'seo-repair-kit' ); ?>">
							<button type="button" class="button button-primary srk-tk-modal-add-button">
								<?php esc_html_e( 'Add Keyword', 'seo-repair-kit' ); ?>
							</button>
						</div>
					</div>
				</div>
			</div>

		</div>
		<?php
	}
}