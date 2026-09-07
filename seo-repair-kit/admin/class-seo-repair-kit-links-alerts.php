<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Render Links Manager alerts and scan history tables.
 *
 * @since 2.1.7
 */
class SeoRepairKit_LinksAlerts {

	/**
	 * Format a site-local MySQL datetime string.
	 *
	 * @param string $datetime MySQL datetime in site timezone.
	 * @return string
	 */
	private static function format_local_time( $datetime ) {
		if ( empty( $datetime ) || '0000-00-00 00:00:00' === $datetime ) {
			return '—';
		}

		$timestamp = strtotime( $datetime );
		if ( ! $timestamp ) {
			return esc_html( $datetime );
		}

		return wp_date( 'M j, Y g:i a', $timestamp, wp_timezone() );
	}

	/**
	 * Render history tables for Notifications tab.
	 *
	 * @param SeoRepairKit_LinkScanner_Automation $automation Automation instance.
	 * @return void
	 */
	public static function render_history_tables( $automation ) {
		$runs   = $automation->get_recent_runs( 100 );
		$alerts = $automation->get_recent_alerts( 100 );
		?>

		<div class="srk-card srk-alerts-card" style="margin-bottom:24px;">
			<div class="srk-alerts-card-header">
				<div class="srk-alerts-card-title">
					<span class="dashicons dashicons-list-view"></span>
					<div>
						<h3><?php esc_html_e( 'Scan Run History', 'seo-repair-kit' ); ?></h3>
						<p><?php esc_html_e( 'Last 7 manual and scheduled scan runs. Older records are pruned automatically.', 'seo-repair-kit' ); ?></p>
					</div>
				</div>

				<div class="srk-alerts-card-actions">
					<button type="button" class="button button-link-delete srk-reset-table-btn" id="srk-reset-runs-table-btn"
						data-nonce="<?php echo esc_attr( wp_create_nonce( 'srk_reset_scan_table_nonce' ) ); ?>">
						<span class="dashicons dashicons-trash"></span>
						<?php esc_html_e( 'Reset Records', 'seo-repair-kit' ); ?>
					</button>
				</div>
			</div>

			<div class="srk-table-scroll" style="margin-top:16px;">
				<table class="widefat striped srk-history-table" id="srk-runs-table">
					<thead>
						<tr>
							<th><?php esc_html_e( 'Run Time', 'seo-repair-kit' ); ?></th>
							<th><?php esc_html_e( 'Trigger', 'seo-repair-kit' ); ?></th>
							<th><?php esc_html_e( 'Scope', 'seo-repair-kit' ); ?></th>
							<th><?php esc_html_e( 'Coverage', 'seo-repair-kit' ); ?></th>
							<th><?php esc_html_e( 'Scanned Posts', 'seo-repair-kit' ); ?></th>
							<th><?php esc_html_e( 'Total Links', 'seo-repair-kit' ); ?></th>
							<th><?php esc_html_e( 'Broken Links', 'seo-repair-kit' ); ?></th>
							<th><?php esc_html_e( 'Email Sent', 'seo-repair-kit' ); ?></th>
							<th><?php esc_html_e( 'Post Type Breakdown', 'seo-repair-kit' ); ?></th>
							<th><?php esc_html_e( 'Export', 'seo-repair-kit' ); ?></th>
						</tr>
					</thead>
					<tbody>
						<?php if ( ! empty( $runs ) ) : ?>
							<?php foreach ( $runs as $run ) : ?>
								<?php
								$breakdown    = json_decode( isset( $run['post_type_breakdown'] ) ? $run['post_type_breakdown'] : '', true );
								$broken_count = isset( $run['broken_links_count'] ) ? (int) $run['broken_links_count'] : 0;
								$trigger      = isset( $run['trigger_type'] ) ? $run['trigger_type'] : '';
								$run_id       = isset( $run['id'] ) ? (int) $run['id'] : 0;
								$run_time     = self::format_local_time( isset( $run['ended_at'] ) ? $run['ended_at'] : '' );
								?>
								<tr>
									<td><?php echo esc_html( $run_time ); ?></td>
									<td><span class="srk-trigger-badge srk-trigger-<?php echo esc_attr( $trigger ); ?>"><?php echo esc_html( ucfirst( $trigger ) ); ?></span></td>
									<td><?php echo esc_html( isset( $run['link_scope'] ) ? $run['link_scope'] : 'both' ); ?></td>
									<td><?php echo esc_html( isset( $run['scan_coverage'] ) ? $run['scan_coverage'] : 'selected' ); ?></td>
									<td><?php echo esc_html( number_format_i18n( isset( $run['scanned_posts_count'] ) ? (int) $run['scanned_posts_count'] : 0 ) ); ?></td>
									<td><?php echo esc_html( number_format_i18n( isset( $run['total_links_count'] ) ? (int) $run['total_links_count'] : 0 ) ); ?></td>
									<td><span class="srk-broken-badge <?php echo $broken_count > 0 ? 'has-broken' : 'no-broken'; ?>"><?php echo esc_html( number_format_i18n( $broken_count ) ); ?></span></td>
									<td>
										<?php if ( ! empty( $run['email_sent'] ) ) : ?>
											<span class="srk-status-yes">&#10003; <?php esc_html_e( 'Yes', 'seo-repair-kit' ); ?></span>
										<?php else : ?>
											<span class="srk-status-no">— <?php esc_html_e( 'No', 'seo-repair-kit' ); ?></span>
										<?php endif; ?>
									</td>
									<td>
										<?php if ( is_array( $breakdown ) && ! empty( $breakdown ) ) : ?>
											<?php foreach ( $breakdown as $post_type => $row ) : ?>
												<div class="srk-breakdown-row">
													<strong><?php echo esc_html( $post_type ); ?>:</strong>
													<?php echo esc_html( sprintf( '%1$d broken / %2$d checked', isset( $row['broken'] ) ? (int) $row['broken'] : 0, isset( $row['checked'] ) ? (int) $row['checked'] : 0 ) ); ?>
												</div>
											<?php endforeach; ?>
										<?php else : ?>
											<span class="srk-text-muted">—</span>
										<?php endif; ?>
									</td>
									<td>
										<?php if ( $run_id > 0 ) : ?>
											<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" style="display:inline;">
												<input type="hidden" name="action" value="srk_export_single_run_csv" />
												<input type="hidden" name="run_id" value="<?php echo esc_attr( $run_id ); ?>" />
												<?php wp_nonce_field( 'srk_export_single_run_csv', 'srk_export_single_run_csv_nonce' ); ?>
												<button type="submit" class="button button-small srk-export-row-btn" title="<?php esc_attr_e( 'Export this run as CSV', 'seo-repair-kit' ); ?>">
													<span class="dashicons dashicons-download"></span>
													<?php esc_html_e( 'CSV', 'seo-repair-kit' ); ?>
												</button>
											</form>
										<?php endif; ?>
									</td>
								</tr>
							<?php endforeach; ?>
						<?php else : ?>
							<tr>
								<td colspan="10" class="srk-empty-row">
									<span class="dashicons dashicons-info-outline"></span>
									<?php esc_html_e( 'No scan history yet. Run a scan from the Auto Scan tab.', 'seo-repair-kit' ); ?>
								</td>
							</tr>
						<?php endif; ?>
					</tbody>
				</table>
			</div>
		</div>

		<div class="srk-card srk-alerts-card">
			<div class="srk-alerts-card-header">
				<div class="srk-alerts-card-title">
					<span class="dashicons dashicons-bell"></span>
					<div>
						<h3><?php esc_html_e( 'Notification History', 'seo-repair-kit' ); ?></h3>
						<p><?php esc_html_e( 'Previously sent email notifications for broken link alerts.', 'seo-repair-kit' ); ?></p>
					</div>
				</div>

				<div class="srk-alerts-card-actions">
					<button type="button" class="button button-link-delete srk-reset-table-btn" id="srk-reset-alerts-table-btn"
						data-nonce="<?php echo esc_attr( wp_create_nonce( 'srk_reset_scan_table_nonce' ) ); ?>">
						<span class="dashicons dashicons-trash"></span>
						<?php esc_html_e( 'Reset Records', 'seo-repair-kit' ); ?>
					</button>
				</div>
			</div>

			<div class="srk-table-scroll" style="margin-top:16px;">
				<table class="widefat striped srk-history-table" id="srk-alerts-table">
					<thead>
						<tr>
							<th><?php esc_html_e( 'Sent At', 'seo-repair-kit' ); ?></th>
							<th><?php esc_html_e( 'Subject', 'seo-repair-kit' ); ?></th>
							<th><?php esc_html_e( 'Recipients', 'seo-repair-kit' ); ?></th>
							<th><?php esc_html_e( 'Broken Links', 'seo-repair-kit' ); ?></th>
							<th><?php esc_html_e( 'Post Type Breakdown', 'seo-repair-kit' ); ?></th>
							<th><?php esc_html_e( 'Export', 'seo-repair-kit' ); ?></th>
						</tr>
					</thead>
					<tbody>
						<?php if ( ! empty( $alerts ) ) : ?>
							<?php foreach ( $alerts as $alert ) : ?>
								<?php
								$breakdown  = json_decode( isset( $alert['post_type_breakdown'] ) ? $alert['post_type_breakdown'] : '', true );
								$alert_id   = isset( $alert['id'] ) ? (int) $alert['id'] : 0;
								$alert_time = self::format_local_time( isset( $alert['sent_at'] ) ? $alert['sent_at'] : '' );
								?>
								<tr>
									<td><?php echo esc_html( $alert_time ); ?></td>
									<td><?php echo esc_html( isset( $alert['subject'] ) ? $alert['subject'] : '' ); ?></td>
									<td class="srk-recipients-cell"><?php echo esc_html( isset( $alert['recipients'] ) ? $alert['recipients'] : '' ); ?></td>
									<td><span class="srk-broken-badge has-broken"><?php echo esc_html( number_format_i18n( isset( $alert['broken_links_count'] ) ? (int) $alert['broken_links_count'] : 0 ) ); ?></span></td>
									<td>
										<?php if ( is_array( $breakdown ) && ! empty( $breakdown ) ) : ?>
											<?php foreach ( $breakdown as $post_type => $row ) : ?>
												<div class="srk-breakdown-row">
													<strong><?php echo esc_html( $post_type ); ?>:</strong>
													<?php echo esc_html( sprintf( '%1$d broken / %2$d checked', isset( $row['broken'] ) ? (int) $row['broken'] : 0, isset( $row['checked'] ) ? (int) $row['checked'] : 0 ) ); ?>
												</div>
											<?php endforeach; ?>
										<?php else : ?>
											<span class="srk-text-muted">—</span>
										<?php endif; ?>
									</td>
									<td>
										<?php if ( $alert_id > 0 ) : ?>
											<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" style="display:inline;">
												<input type="hidden" name="action" value="srk_export_single_alert_csv" />
												<input type="hidden" name="alert_id" value="<?php echo esc_attr( $alert_id ); ?>" />
												<?php wp_nonce_field( 'srk_export_single_alert_csv', 'srk_export_single_alert_csv_nonce' ); ?>
												<button type="submit" class="button button-small srk-export-row-btn" title="<?php esc_attr_e( 'Export this alert as CSV', 'seo-repair-kit' ); ?>">
													<span class="dashicons dashicons-download"></span>
													<?php esc_html_e( 'CSV', 'seo-repair-kit' ); ?>
												</button>
											</form>
										<?php endif; ?>
									</td>
								</tr>
							<?php endforeach; ?>
						<?php else : ?>
							<tr>
								<td colspan="6" class="srk-empty-row">
									<span class="dashicons dashicons-info-outline"></span>
									<?php esc_html_e( 'No alert history yet. Alerts are sent when broken links are found and email alerts are enabled.', 'seo-repair-kit' ); ?>
								</td>
							</tr>
						<?php endif; ?>
					</tbody>
				</table>
			</div>
		</div>

		<div id="srk-alerts-action-result" style="display:none;margin-top:12px;"></div>

		<script>
		jQuery(document).ready(function($) {
			$('#srk-reset-runs-table-btn').on('click', function() {
				if (!confirm('<?php echo esc_js( __( 'Reset all scan run records and alert history? This cannot be undone.', 'seo-repair-kit' ) ); ?>')) {
					return;
				}

				var $btn = $(this);
				var nonce = $btn.data('nonce');
				$btn.prop('disabled', true);

				$.ajax({
					url: ajaxurl,
					type: 'POST',
					data: { action: 'srk_ajax_reset_scan_table', nonce: nonce },
					success: function(response) {
						if (response.success) {
							$('#srk-runs-table tbody').html('<tr><td colspan="10" class="srk-empty-row"><?php echo esc_js( __( 'Records cleared.', 'seo-repair-kit' ) ); ?></td></tr>');
							$('#srk-alerts-table tbody').html('<tr><td colspan="6" class="srk-empty-row"><?php echo esc_js( __( 'Records cleared.', 'seo-repair-kit' ) ); ?></td></tr>');
							$('#srk-alerts-action-result').html('<div class="notice notice-success inline"><p>' + response.data.message + '</p></div>').show();
						}
					},
					complete: function() {
						$btn.prop('disabled', false);
					}
				});
			});

			$('#srk-reset-alerts-table-btn').on('click', function() {
				$('#srk-reset-runs-table-btn').trigger('click');
			});
		});
		</script>
		<?php
	}
}