<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class SeoRepairKit_InternalLinking_Content_Index {

	public function render() {
		$summary = class_exists( 'SRK_Internal_Linking_DB' ) ? SRK_Internal_Linking_DB::get_content_index_summary() : array(
			'total' => 0,
			'pages' => 0,
			'posts' => 0,
			'cpt'   => 0,
		);

		$page = isset( $_GET['srk_ci_page'] )
			? max( 1, absint( wp_unslash( $_GET['srk_ci_page'] ) ) )
			: 1;

		$per_page_raw = isset( $_GET['srk_ci_per_page'] )
			? sanitize_text_field( wp_unslash( $_GET['srk_ci_per_page'] ) )
			: '10';

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

		$total = ! empty( $summary['total'] )
			? absint( $summary['total'] )
			: 0;

		/*
		* Numeric value used for calculations and pagination.
		*/
		if ( 'all' === $per_page_raw ) {
			$per_page = max( 1, $total );
			$page     = 1;
		} else {
			$per_page = absint( $per_page_raw );
		}

		$rows = class_exists( 'SRK_Internal_Linking_DB' )
			? SRK_Internal_Linking_DB::get_content_index_rows(
				$page,
				$per_page
			)
			: array();
		
		$domains = (
			class_exists( 'SRK_Internal_Linking_DB' ) &&
			method_exists( 'SRK_Internal_Linking_DB', 'get_domains_report' )
		)
			? SRK_Internal_Linking_DB::get_domains_report()
			: array();

			/*
			* Content Index inner view.
			*
			* Preserve the Domains Report sub-tab after pagination
			* or rows-per-page changes.
			*/
			$current_view = isset( $_GET['srk_ci_view'] )
				? sanitize_key( wp_unslash( $_GET['srk_ci_view'] ) )
				: 'indexed-content';

			if ( ! in_array( $current_view, array( 'indexed-content', 'domains-report' ), true ) ) {
				$current_view = 'indexed-content';
			}

			/*
			* Domains Report pagination.
			*
			* Keep one source of truth for:
			* - current page;
			* - rows per page;
			* - sliced rows;
			* - pagination totals.
			*/
			$domain_page = isset( $_GET['srk_dom_page'] )
				? max( 1, absint( wp_unslash( $_GET['srk_dom_page'] ) ) )
				: 1;

			$domain_per_page_raw = isset( $_GET['srk_dom_per_page'] )
				? sanitize_text_field( wp_unslash( $_GET['srk_dom_per_page'] ) )
				: '10';

			$domain_allowed = array( '5', '10', '20', '50', '100', 'all' );

			if ( ! in_array( $domain_per_page_raw, $domain_allowed, true ) ) {
				$domain_per_page_raw = '10';
			}

			$domain_total = count( $domains );

			if ( 'all' === $domain_per_page_raw ) {
				$domain_limit = max( 1, $domain_total );
			} else {
				$domain_limit = absint( $domain_per_page_raw );
			}

			$domain_total_pages = max(
				1,
				(int) ceil( $domain_total / $domain_limit )
			);

			/*
			* Prevent requesting a page that no longer exists,
			* for example after reducing the number of domains.
			*/
			$domain_page = min( $domain_page, $domain_total_pages );

			$domain_offset = ( $domain_page - 1 ) * $domain_limit;

			$domain_paginated = array_slice(
				$domains,
				$domain_offset,
				$domain_limit
			);

			$domain_from = $domain_total > 0
				? $domain_offset + 1
				: 0;

			$domain_to = min(
				$domain_offset + $domain_limit,
				$domain_total
			);
	
		?>

		<div class="srk-il-page srk-il-content-index">

			<div class="srk-il-subtabs">
				<button
					type="button"
					class="srk-il-subtab <?php echo 'indexed-content' === $current_view ? 'is-active' : ''; ?>"
					data-srk-ci-view="indexed-content"
				>
					<?php esc_html_e( 'Indexed Content', 'seo-repair-kit' ); ?>
				</button>

				<button
					type="button"
					class="srk-il-subtab <?php echo 'domains-report' === $current_view ? 'is-active' : ''; ?>"
					data-srk-ci-view="domains-report"
				>
					<?php esc_html_e( 'Domains Report', 'seo-repair-kit' ); ?>
				</button>
			</div>

			<div
				class="srk-il-ci-panel <?php echo 'indexed-content' === $current_view ? 'is-active' : ''; ?>"
				id="srk-ci-indexed-content"
			>
				<div class="srk-il-ci-stats">
					<div class="srk-il-ci-stat">
						<span><?php esc_html_e( 'Total Indexed', 'seo-repair-kit' ); ?></span>
						<strong><?php echo esc_html( $summary['total'] ); ?></strong>
					</div>
					<div class="srk-il-ci-stat">
						<span><?php esc_html_e( 'Pages', 'seo-repair-kit' ); ?></span>
						<strong><?php echo esc_html( $summary['pages'] ); ?></strong>
					</div>
					<div class="srk-il-ci-stat">
						<span><?php esc_html_e( 'Posts', 'seo-repair-kit' ); ?></span>
						<strong><?php echo esc_html( $summary['posts'] ); ?></strong>
					</div>
					<div class="srk-il-ci-stat">
						<span><?php esc_html_e( 'CPT Items', 'seo-repair-kit' ); ?></span>
						<strong><?php echo esc_html( $summary['cpt'] ); ?></strong>
					</div>
				</div>

				<div class="srk-il-ci-scan-box">
					<div class="srk-il-ci-scan-main">
						<h3><?php esc_html_e( 'Website Content Scan', 'seo-repair-kit' ); ?></h3>
						<span class="srk-il-ci-pill srk-ci-scan-status"><?php esc_html_e( 'System idle', 'seo-repair-kit' ); ?></span>
					</div>

					<div class="srk-il-ci-progress-area">
						<span class="srk-ci-progress-label">0% <?php esc_html_e( 'Complete', 'seo-repair-kit' ); ?></span>
						<div class="srk-il-ci-progress">
							<div class="srk-ci-progress-bar" style="width: 0%;"></div>
						</div>
					</div>

					<div class="srk-il-ci-scan-actions">
						<button type="button" class="button button-primary srk-ci-start-indexing">
							<?php esc_html_e( 'Start Scanning', 'seo-repair-kit' ); ?>
						</button>
						<button type="button" class="button srk-ci-pause-indexing" disabled>
							<?php esc_html_e( 'Pause', 'seo-repair-kit' ); ?>
						</button>
					</div>
				</div>

				<div class="srk-il-table-card">
					<table class="srk-il-data-table">
						<thead>
							<tr>
								<th><?php esc_html_e( 'Post Title', 'seo-repair-kit' ); ?></th>
								<th><?php esc_html_e( 'Type', 'seo-repair-kit' ); ?></th>
								<th><?php esc_html_e( 'Status', 'seo-repair-kit' ); ?></th>
								<th><?php esc_html_e( 'Word Count', 'seo-repair-kit' ); ?></th>
								<th>
									<?php esc_html_e( 'Inbound Internal', 'seo-repair-kit' ); ?>
									<span class="srk-il-help-tip" aria-label="<?php esc_attr_e( 'Inbound Internal Links are links on other pages on this site that are pointing to this page.', 'seo-repair-kit' ); ?>">
										<span class="dashicons dashicons-editor-help"></span>
									</span>
								</th>
								<th>
									<?php esc_html_e( 'Outbound Internal', 'seo-repair-kit' ); ?>
									<span class="srk-il-help-tip" aria-label="<?php esc_attr_e( 'Outbound Internal Links are links on this page that point to other pages on this site.', 'seo-repair-kit' ); ?>">
										<span class="dashicons dashicons-editor-help"></span>
									</span>
								</th>
																<th>
									<?php esc_html_e( 'Outbound External', 'seo-repair-kit' ); ?>
									<span class="srk-il-help-tip" aria-label="<?php esc_attr_e( 'Outbound External Links are links on this page that point to other websites.', 'seo-repair-kit' ); ?>">
										<span class="dashicons dashicons-editor-help"></span>
									</span>
								</th>
								<th><?php esc_html_e( 'Last Indexed', 'seo-repair-kit' ); ?></th>
								<th><?php esc_html_e( 'Actions', 'seo-repair-kit' ); ?></th>
							</tr>
						</thead>
						<tbody>
							<?php if ( ! empty( $rows ) ) : ?>
								<?php foreach ( $rows as $row ) : ?>
									<tr>
										<td class="srk-il-title-cell">
											<?php echo esc_html( $row['post_title'] ?? '' ); ?>
										</td>

										<td>
											<?php echo esc_html( ucfirst( $row['post_type'] ?? '' ) ); ?>
										</td>

										<td>
											<span class="srk-il-status success">
												<?php echo esc_html( ucfirst( $row['post_status'] ?? '' ) ); ?>
											</span>
										</td>

										<td>
											<?php echo esc_html( absint( $row['word_count'] ?? 0 ) ); ?>
										</td>

										<td>
											<?php echo esc_html( absint( $row['internal_inbound_count'] ?? 0 ) ); ?>
										</td>

										<td>
											<?php echo esc_html( absint( $row['internal_outbound_count'] ?? 0 ) ); ?>
										</td>

										<td>
											<?php echo esc_html( absint( $row['external_outbound_count'] ?? 0 ) ); ?>
										</td>

										<td>
											<?php
											echo esc_html(
												! empty( $row['last_indexed'] )
													? human_time_diff(
														strtotime( $row['last_indexed'] ),
														current_time( 'timestamp' )
													) . ' ago'
													: '-'
											);
											?>
										</td>

										<td>
											<div class="srk-ci-actions">
												<a
													class="button-link srk-il-table-action srk-ci-view-action"
													href="<?php echo esc_url( get_permalink( absint( $row['post_id'] ?? 0 ) ) ); ?>"
													target="_blank"
													rel="noopener noreferrer"
													title="<?php esc_attr_e( 'View post', 'seo-repair-kit' ); ?>"
													aria-label="<?php esc_attr_e( 'View post', 'seo-repair-kit' ); ?>"
												>
													<span class="dashicons dashicons-visibility"></span>
												</a>

												<a
													class="button-link srk-il-table-action srk-ci-edit-action"
													href="<?php echo esc_url( get_edit_post_link( absint( $row['post_id'] ?? 0 ) ) ); ?>"
													target="_blank"
													rel="noopener noreferrer"
													title="<?php esc_attr_e( 'Edit post', 'seo-repair-kit' ); ?>"
													aria-label="<?php esc_attr_e( 'Edit post', 'seo-repair-kit' ); ?>"
												>
													<span class="dashicons dashicons-edit"></span>
												</a>
											</div>
										</td>
									</tr>
								<?php endforeach; ?>
							<?php else : ?>
								<tr>
									<td colspan="9"><?php esc_html_e( 'No indexed content found yet. Click Start Indexing to build the content index.', 'seo-repair-kit' ); ?></td>
								</tr>
							<?php endif; ?>
						</tbody>
					</table>

					<div class="srk-il-pagination">
						<span>
							<?php
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
							
							printf(
								esc_html__( 'Showing %1$d-%2$d of %3$d indexed items', 'seo-repair-kit' ),
								absint( $from ),
								absint( $to ),
								absint( $total )
							);
							?>
						</span>

						<form method="get" class="srk-il-per-page-form">
							<input type="hidden" name="page" value="seo-repair-kit-internal-linking" />
							<input type="hidden" name="srk_il_tab" value="content-index" />
							
							<label>
								<?php esc_html_e( 'Rows per page', 'seo-repair-kit' ); ?>
								<select name="srk_ci_per_page" onchange="this.form.submit();">
									<?php foreach ( array( 5, 10, 20, 50, 100, 'all' ) as $option ) : ?>
										<option value="<?php echo esc_attr( $option ); ?>" <?php selected( $per_page_raw, (string) $option ); ?>>
											<?php echo 'all' === $option ? esc_html__( 'All', 'seo-repair-kit' ) : esc_html( $option ); ?>
										</option>
									<?php endforeach; ?>
								</select>
							</label>
						</form>

						<?php
						if ( class_exists( 'SeoRepairKit_InternalLinking' ) ) {
							SeoRepairKit_InternalLinking::render_pagination(
								'content-index',
								'srk_ci_page',
								$page,
								$per_page,
								$total,
								array(
									'srk_ci_per_page' => $per_page_raw,
								)
							);
						}
						?>
					</div>

				</div>

			</div>

			<div
				class="srk-il-ci-panel <?php echo 'domains-report' === $current_view ? 'is-active' : ''; ?>"
				id="srk-ci-domains-report"
			>
				<div class="srk-il-card">
					<h4><?php esc_html_e( 'Domains Report', 'seo-repair-kit' ); ?></h4>
					<p><?php esc_html_e( 'Domain-level external link reporting will use indexed external links from the content index.', 'seo-repair-kit' ); ?></p>

					<table class="srk-il-data-table srk-il-domains-table">
						<thead>
							<tr>
								<th><?php esc_html_e( 'Domain', 'seo-repair-kit' ); ?></th>
								<th><?php esc_html_e( 'Posts', 'seo-repair-kit' ); ?></th>
								<th><?php esc_html_e( 'Links', 'seo-repair-kit' ); ?></th>
								<th><?php esc_html_e( 'Actions', 'seo-repair-kit' ); ?></th>
							</tr>
						</thead>
						<tbody id="srk-domains-table-body">
							<?php if ( ! empty( $domain_paginated ) ) : ?>
								<?php foreach ( $domain_paginated as $domain ) : ?>
									<?php
									$domain = wp_parse_args(
										(array) $domain,
										array(
											'domain'           => '',
											'posts_count'      => 0,
											'links_count'      => 0,
										)
									);

									$domain_name = sanitize_text_field(
										$domain['domain']
									);

									$domain_url = 'https://' . ltrim(
										$domain_name,
										'/'
									);
									?>
									<tr data-domain="<?php echo esc_attr( $domain_name ); ?>">
										<td>
											<a href="<?php echo esc_url( $domain_url ); ?>" target="_blank" rel="noopener noreferrer">
												<?php echo esc_html( $domain_name ); ?>
											</a>
										</td>
										<td>
											<?php echo esc_html( absint( $domain['posts_count'] ) ); ?>
										</td>
										<td>
											<?php echo esc_html( absint( $domain['links_count'] ) ); ?>
										</td>
										<td>
											<button type="button" class="button srk-domain-view-posts" data-domain="<?php echo esc_attr( $domain_name ); ?>">
												<?php esc_html_e( 'View Posts', 'seo-repair-kit' ); ?>
											</button>
											<button type="button" class="button srk-domain-view-links" data-domain="<?php echo esc_attr( $domain_name ); ?>">
												<?php esc_html_e( 'View Links', 'seo-repair-kit' ); ?>
											</button>
										</td>
									</tr>
								<?php endforeach; ?>
							<?php else : ?>
								<tr>
									<td colspan="4">
										<?php esc_html_e( 'No domains found.', 'seo-repair-kit' ); ?>
									</td>
								</tr>
							<?php endif; ?>
						</tbody>
					</table>

					<div class="srk-il-pagination">
						<span>
							<?php if ( $domain_total > 0 ) : ?>
								<?php
								printf(
									esc_html__( 'Showing %1$d-%2$d of %3$d domains', 'seo-repair-kit' ),
									absint( $domain_from ),
									absint( $domain_to ),
									absint( $domain_total )
								);
								?>
							<?php else : ?>
								<?php esc_html_e( 'No domains found', 'seo-repair-kit' ); ?>
							<?php endif; ?>
						</span>

						<form method="get" class="srk-il-per-page-form">
							<input
								type="hidden"
								name="page"
								value="seo-repair-kit-internal-linking"
							/>

							<input
								type="hidden"
								name="srk_il_tab"
								value="content-index"
							/>

							<input
								type="hidden"
								name="srk_ci_view"
								value="domains-report"
							/>

							<label>
								<?php esc_html_e( 'Rows per page', 'seo-repair-kit' ); ?>

								<select
									name="srk_dom_per_page"
									onchange="this.form.submit();"
								>
									<?php foreach ( array( 5, 10, 20, 50, 100, 'all' ) as $option ) : ?>

										<option
											value="<?php echo esc_attr( $option ); ?>"
											<?php selected( $domain_per_page_raw, (string) $option ); ?>
										>
											<?php
											echo 'all' === $option
												? esc_html__( 'All', 'seo-repair-kit' )
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
								'content-index',
								'srk_dom_page',
								$domain_page,
								$domain_limit,
								$domain_total,
								array(
									'srk_dom_per_page' => $domain_per_page_raw,
									'srk_ci_view'      => 'domains-report',
								)
							);
						}
						?>
					</div>

				</div>

				<!-- Popup container -->
				<div id="srk-domain-modal" class="srk-domain-modal">
					<div class="srk-domain-modal-content">
						<div class="srk-domain-modal-header">
							<h3 class="srk-domain-modal-title"></h3>
							<div class="srk-domain-modal-actions">
								<button class="button srk-domain-download"><?php esc_html_e( 'Download CSV', 'seo-repair-kit' ); ?></button>
								<button class="button srk-domain-close">&times;</button>
							</div>
						</div>
						<div class="srk-domain-modal-body">
							<p><?php esc_html_e( 'Loading...', 'seo-repair-kit' ); ?></p>
						</div>
					</div>
				</div>
			</div>

		</div>
		<?php
	}
}