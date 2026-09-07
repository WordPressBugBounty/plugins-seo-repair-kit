<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class SeoRepairKit_InternalLinking_URL_Changer {

	public function render() {
		$page = isset( $_GET['url_change_page'] )
			? max( 1, absint( wp_unslash( $_GET['url_change_page'] ) ) )
			: 1;

		$per_page = 10;
		$rows     = SRK_Internal_Linking_DB::get_url_change_rows( $page, $per_page );
		$total    = SRK_Internal_Linking_DB::count_url_changes();
		?>

		<div class="srk-il-page srk-il-url-changer">

			<div class="srk-il-page-title">
				<h2><?php esc_html_e( 'Global URL Changer', 'seo-repair-kit' ); ?></h2>
				<p><?php esc_html_e( 'Safely replace old URLs with new URLs across your website content.', 'seo-repair-kit' ); ?></p>
			</div>

			<div class="srk-url-safety-notice">
				<span class="dashicons dashicons-shield"></span>

				<div>
					<strong><?php esc_html_e( 'Safe replacement workflow', 'seo-repair-kit' ); ?></strong>

					<p>
						<?php
						esc_html_e(
							'Run a dry scan first, review affected posts, then confirm replacement.',
							'seo-repair-kit'
						);
						?>
					</p>
				</div>
			</div>

			<div class="srk-url-form-card">

				<div class="srk-il-panel-header">
					<h3><?php esc_html_e( 'URL Replacement', 'seo-repair-kit' ); ?></h3>
				</div>

				<div class="srk-url-form-body">

					<label>
						<span><?php esc_html_e( 'Old URL or Path', 'seo-repair-kit' ); ?></span>

						<input
							type="text"
							class="regular-text srk-url-old"
							placeholder="https://example.com/old-page/ or /old-page/"
						/>
					</label>

					<label>
						<span><?php esc_html_e( 'New URL or Path', 'seo-repair-kit' ); ?></span>

						<input
							type="text"
							class="regular-text srk-url-new"
							placeholder="https://example.com/new-page/ or /new-page/"
						/>
					</label>

					<div class="srk-url-action-grid">
						<button type="button" class="button srk-url-dry-scan">
							<?php esc_html_e( 'Run Dry Scan', 'seo-repair-kit' ); ?>
						</button>

						<button
							type="button"
							class="button button-primary srk-url-replace"
							disabled
						>
							<?php esc_html_e( 'Replace URL', 'seo-repair-kit' ); ?>
						</button>
					</div>

					<div class="srk-url-preview" style="display:none;"></div>
				</div>

			</div>

			<div class="srk-il-table-card">

				<div class="srk-il-panel-header">
					<h3><?php esc_html_e( 'URL Change Logs', 'seo-repair-kit' ); ?></h3>
				</div>

				<table class="srk-il-data-table srk-il-url-table">

					<thead>
						<tr>
							<th><?php esc_html_e( 'Old URL', 'seo-repair-kit' ); ?></th>
							<th><?php esc_html_e( 'New URL', 'seo-repair-kit' ); ?></th>
							<th><?php esc_html_e( 'Affected Posts', 'seo-repair-kit' ); ?></th>
							<th><?php esc_html_e( 'Links Changed', 'seo-repair-kit' ); ?></th>
							<th><?php esc_html_e( 'Failed', 'seo-repair-kit' ); ?></th>
							<th><?php esc_html_e( 'Status', 'seo-repair-kit' ); ?></th>
							<th><?php esc_html_e( 'Actions', 'seo-repair-kit' ); ?></th>
						</tr>
					</thead>

					<tbody>

						<?php if ( empty( $rows ) ) : ?>

							<tr>
								<td colspan="7">
									<?php esc_html_e( 'No URL changes yet.', 'seo-repair-kit' ); ?>
								</td>
							</tr>

						<?php else : ?>

							<?php foreach ( $rows as $row ) : ?>

								<?php
								$status       = sanitize_key( $row['status'] ?? '' );
								$has_rollback = ! empty( $row['has_rollback'] );

								$can_undo = $has_rollback && in_array(
									$status,
									array(
										'completed',
										'completed_with_failures',
										'undo_partial',
										'undo_failed',
									),
									true
								);

								$status_class = 'warning';

								if ( in_array( $status, array( 'completed', 'undone' ), true ) ) {
									$status_class = 'success';
								} elseif (
									in_array(
										$status,
										array( 'failed', 'undo_failed', 'legacy_no_undo' ),
										true
									)
								) {
									$status_class = 'danger';
								}
								?>

								<tr>

									<td>
										<code><?php echo esc_html( $row['old_url'] ); ?></code>
									</td>

									<td>
										<code><?php echo esc_html( $row['new_url'] ); ?></code>
									</td>

									<td>
										<?php echo esc_html( absint( $row['affected_posts'] ) ); ?>
									</td>

									<td>
										<strong>
											<?php echo esc_html( absint( $row['changed_links'] ) ); ?>
										</strong>
									</td>

									<td>
										<?php echo esc_html( absint( $row['failed_count'] ) ); ?>
									</td>

									<td>
										<span class="srk-il-status <?php echo esc_attr( $status_class ); ?>">
											<?php
											echo esc_html(
												ucwords(
													str_replace( '_', ' ', $status )
												)
											);
											?>
										</span>
									</td>

									<td>
										<?php if ( $can_undo ) : ?>

											<button
												type="button"
												class="button-link srk-url-undo-row"
												data-change-id="<?php echo esc_attr( absint( $row['id'] ) ); ?>"
											>
												<?php esc_html_e( 'Undo', 'seo-repair-kit' ); ?>
											</button>

										<?php else : ?>

											—

										<?php endif; ?>
									</td>

								</tr>

							<?php endforeach; ?>

						<?php endif; ?>

					</tbody>

				</table>

				<?php
				if ( class_exists( 'SeoRepairKit_InternalLinking' ) ) {
					SeoRepairKit_InternalLinking::render_pagination(
						'url-changer',
						'url_change_page',
						$page,
						$per_page,
						$total
					);
				}
				?>

			</div>

			<div class="srk-il-info-box">
				<strong>
					<span class="dashicons dashicons-info-outline"></span>
					<?php esc_html_e( 'What this does', 'seo-repair-kit' ); ?>
				</strong>

				<p>
					<?php
					esc_html_e(
						'URL Changer searches post content, previews affected posts, replaces only matching href URLs, stores compact rollback positions, re-indexes changed posts, and supports conflict-safe Undo.',
						'seo-repair-kit'
					);
					?>
				</p>
			</div>

		</div>

		<?php
	}
}