<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Handles Auto Scan admin UI and exports for Links Manager.
 *
 * @since 2.1.7
 */
class SeoRepairKit_LinkScanner_Automation_Admin {

	/**
	 * Constructor.
	 */
	public function __construct() {
		add_action( 'admin_post_srk_save_scan_schedule', array( $this, 'srk_save_scan_schedule' ) );
		add_action( 'admin_post_srk_reset_scan_records_full', array( $this, 'srk_reset_scan_records_full' ) );
		add_action( 'admin_post_srk_export_scan_runs_csv', array( $this, 'srk_export_scan_runs_csv' ) );
		add_action( 'admin_post_srk_export_alerts_csv', array( $this, 'srk_export_alerts_csv' ) );
		add_action( 'admin_post_srk_export_single_run_csv', array( $this, 'srk_export_single_run_csv' ) );
		add_action( 'admin_post_srk_export_single_alert_csv', array( $this, 'srk_export_single_alert_csv' ) );
	}

	/**
	 * Render automation tab.
	 *
	 * @return void
	 */
	public function render_automation_scan_tab() {
		if ( ! class_exists( 'SeoRepairKit_LinkScanner_Automation' ) ) {
			echo '<div class="notice notice-error"><p>' . esc_html__( 'Automation service is not available.', 'seo-repair-kit' ) . '</p></div>';
			return;
		}

		$settings          = SeoRepairKit_LinkScanner_Automation::get_settings();
		$public_post_types = get_post_types( array( 'public' => true ), 'objects' );
		$scanner_unlimited = class_exists( 'SRK_License_Helper' ) ? SRK_License_Helper::is_link_scanner_unlimited() : false;
		$scanner_free_limit = class_exists( 'SRK_License_Helper' ) ? SRK_License_Helper::get_link_scanner_limit() : 100;
		$allowed_post_types = class_exists( 'SRK_License_Helper' )
			? SRK_License_Helper::get_allowed_link_scanner_post_types()
			: array_values( array_filter( array( 'page' ), 'post_type_exists' ) );
		$next_run_ts       = wp_next_scheduled( SeoRepairKit_LinkScanner_Automation::EVENT_HOOK );
		?>
		<div class="srk-card">
			<div class="srk-automation-header">
				<h3><?php esc_html_e( 'Auto Scan Settings', 'seo-repair-kit' ); ?></h3>
				<p><?php esc_html_e( 'Configure scheduled link scanning for internal links, external links, or both. Scan selected content types or the whole website, store history, and send email alerts when broken links are found.', 'seo-repair-kit' ); ?></p>
			</div>

			<?php if ( ! $scanner_unlimited ) : ?>
				<div class="srk-scanner-access-note">
					<div class="srk-scanner-access-icon"><span class="dashicons dashicons-lock"></span></div>
					<div class="srk-scanner-access-copy">
						<strong><?php esc_html_e( 'Free Auto Scan scope', 'seo-repair-kit' ); ?></strong>
						<p>
							<?php
							printf(
								esc_html__( 'Free Link Scanner can scan Pages only up to %d links per run. Posts, whole website scans, and public custom post types require the £1.49 unlimited Broken Links + 404 Monitor add-on.', 'seo-repair-kit' ),
								absint( $scanner_free_limit )
							);
							?>
						</p>
					</div>
				</div>
			<?php endif; ?>

			<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" id="srk-automation-settings-form">
				<input type="hidden" name="action" value="srk_save_scan_schedule" />
				<?php wp_nonce_field( 'srk_save_scan_schedule', 'srk_save_scan_schedule_nonce' ); ?>

				<table class="form-table srk-form-table">
					<tr>
						<th scope="row"><?php esc_html_e( 'Enable Automation', 'seo-repair-kit' ); ?></th>
						<td>
							<label class="srk-toggle-label">
								<input type="checkbox" name="enabled" value="1" <?php checked( ! empty( $settings['enabled'] ) ); ?> />
								<span><?php esc_html_e( 'Automatically scan links on a schedule', 'seo-repair-kit' ); ?></span>
							</label>
						</td>
					</tr>

					<tr>
						<th scope="row"><?php esc_html_e( 'Scan Interval', 'seo-repair-kit' ); ?></th>
						<td>
							<select name="interval" class="srk-select">
								<option value="daily" <?php selected( $settings['interval'], 'daily' ); ?>><?php esc_html_e( 'Every 24 hours', 'seo-repair-kit' ); ?></option>
								<option value="srk_every_3_days" <?php selected( $settings['interval'], 'srk_every_3_days' ); ?>><?php esc_html_e( 'Every 3 days', 'seo-repair-kit' ); ?></option>
								<option value="weekly" <?php selected( $settings['interval'], 'weekly' ); ?>><?php esc_html_e( 'Weekly', 'seo-repair-kit' ); ?></option>
								<option value="srk_biweekly" <?php selected( $settings['interval'], 'srk_biweekly' ); ?>><?php esc_html_e( 'Bi-weekly', 'seo-repair-kit' ); ?></option>
								<option value="srk_monthly" <?php selected( $settings['interval'], 'srk_monthly' ); ?>><?php esc_html_e( 'Monthly', 'seo-repair-kit' ); ?></option>
							</select>
						</td>
					</tr>

					<tr>
						<th scope="row"><?php esc_html_e( 'Link Scope', 'seo-repair-kit' ); ?></th>
						<td>
							<select name="link_scope" class="srk-select">
								<option value="both" <?php selected( $settings['link_scope'], 'both' ); ?>><?php esc_html_e( 'Both Internal and External Links', 'seo-repair-kit' ); ?></option>
								<option value="internal" <?php selected( $settings['link_scope'], 'internal' ); ?>><?php esc_html_e( 'Internal Links Only', 'seo-repair-kit' ); ?></option>
								<option value="external" <?php selected( $settings['link_scope'], 'external' ); ?>><?php esc_html_e( 'External Links Only', 'seo-repair-kit' ); ?></option>
							</select>
							<p class="description"><?php esc_html_e( 'Choose whether to scan only internal links, only external links, or both.', 'seo-repair-kit' ); ?></p>
						</td>
					</tr>

					<tr>
						<th scope="row"><?php esc_html_e( 'Scan Coverage', 'seo-repair-kit' ); ?></th>
						<td>
							<select name="scan_coverage" id="srk-scan-coverage" class="srk-select">
								<option value="selected" <?php selected( $settings['scan_coverage'], 'selected' ); ?>><?php esc_html_e( 'Selected Post Types', 'seo-repair-kit' ); ?></option>
								<option value="whole_site" <?php selected( $settings['scan_coverage'], 'whole_site' ); ?> <?php disabled( ! $scanner_unlimited ); ?>><?php esc_html_e( 'Whole Website (all public post types)', 'seo-repair-kit' ); ?></option>
							</select>
							<p class="description">
								<?php
								echo $scanner_unlimited
									? esc_html__( 'Use Whole Website to automatically include posts, pages, and public custom post types.', 'seo-repair-kit' )
									: esc_html__( 'Whole Website coverage is available with the Unlimited Broken Links + 404 Monitor add-on.', 'seo-repair-kit' );
								?>
							</p>
						</td>
					</tr>

					<tr id="srk-post-types-row">
						<th scope="row"><?php esc_html_e( 'Post Types to Scan', 'seo-repair-kit' ); ?></th>
						<td>
							<div class="srk-checkbox-group">
								<?php foreach ( $public_post_types as $pt_slug => $pt_obj ) : ?>
									<?php $is_allowed = in_array( $pt_slug, $allowed_post_types, true ); ?>
									<label class="srk-checkbox-label <?php echo $is_allowed ? '' : 'srk-checkbox-label-disabled'; ?>">
										<input type="checkbox" name="post_types[]" value="<?php echo esc_attr( $pt_slug ); ?>" <?php checked( $is_allowed && in_array( $pt_slug, (array) $settings['post_types'], true ) ); ?> <?php disabled( ! $is_allowed ); ?> />
										<?php echo esc_html( $pt_obj->labels->name ); ?>
										<?php if ( ! $is_allowed ) : ?>
											<span class="srk-plan-lock"><?php esc_html_e( '£1.49 add-on', 'seo-repair-kit' ); ?></span>
										<?php endif; ?>
									</label>
								<?php endforeach; ?>
							</div>
							<p class="description"><?php esc_html_e( 'Used only when Scan Coverage is set to Selected Post Types.', 'seo-repair-kit' ); ?></p>
						</td>
					</tr>

					<tr>
						<th scope="row">
							<?php esc_html_e( 'Batch Size', 'seo-repair-kit' ); ?>
							<p class="description"><?php esc_html_e( 'Number of posts fetched per database batch. All matching content is still scanned.', 'seo-repair-kit' ); ?></p>
						</th>
						<td><input type="number" min="5" max="1000" name="max_posts_per_run" value="<?php echo esc_attr( (int) $settings['max_posts_per_run'] ); ?>" class="small-text" /></td>
					</tr>

					<tr>
						<th scope="row">
							<?php esc_html_e( 'Max Links per Post', 'seo-repair-kit' ); ?>
							<p class="description"><?php esc_html_e( 'Set to 0 to scan all links found in each item.', 'seo-repair-kit' ); ?></p>
						</th>
						<td><input type="number" min="0" max="10000" name="max_links_per_post" value="<?php echo esc_attr( (int) $settings['max_links_per_post'] ); ?>" class="small-text" /></td>
					</tr>

					<tr>
						<th scope="row"><?php esc_html_e( 'HTTP Timeout (seconds)', 'seo-repair-kit' ); ?></th>
						<td><input type="number" min="3" max="30" name="request_timeout" value="<?php echo esc_attr( (int) $settings['request_timeout'] ); ?>" class="small-text" /></td>
					</tr>

					<tr>
						<th scope="row"><?php esc_html_e( 'Email Alerts', 'seo-repair-kit' ); ?></th>
						<td>
							<label class="srk-toggle-label">
								<input type="checkbox" name="email_enabled" value="1" <?php checked( ! empty( $settings['email_enabled'] ) ); ?> />
								<span><?php esc_html_e( 'Send email alerts when broken links are found', 'seo-repair-kit' ); ?></span>
							</label>
							<p class="description"><?php esc_html_e( 'Recipients (comma-separated emails):', 'seo-repair-kit' ); ?></p>
							<input type="text" class="regular-text" name="email_recipients" value="<?php echo esc_attr( (string) $settings['email_recipients'] ); ?>" placeholder="<?php esc_attr_e( 'admin@example.com, other@example.com', 'seo-repair-kit' ); ?>" />
						</td>
					</tr>
				</table>

				<?php
				$next_local = '';
				if ( $next_run_ts ) {
					$next_local = wp_date( 'M j, Y g:i a', $next_run_ts, wp_timezone() );
				}
				?>

				<div class="srk-automation-next-run">
					<span class="dashicons dashicons-clock"></span>
					<strong><?php esc_html_e( 'Next scheduled run:', 'seo-repair-kit' ); ?></strong>
					<?php echo $next_local ? esc_html( $next_local ) : esc_html__( 'Not scheduled', 'seo-repair-kit' ); ?>
				</div>

				<div class="srk-automation-actions">
					<button type="submit" class="button button-primary srk-btn-save-settings">
						<span class="dashicons dashicons-saved"></span>
						<?php esc_html_e( 'Save Settings', 'seo-repair-kit' ); ?>
					</button>

					<button type="button" class="button button-primary srk-btn-run-scan" id="srk-run-scan-btn">
						<span class="dashicons dashicons-search srk-btn-icon"></span>
						<span class="srk-btn-text"><?php esc_html_e( 'Run Scan', 'seo-repair-kit' ); ?></span>
					</button>

					<button type="button" class="button button-secondary srk-btn-reset-settings" id="srk-reset-settings-btn">
						<span class="dashicons dashicons-undo"></span>
						<?php esc_html_e( 'Reset Settings', 'seo-repair-kit' ); ?>
					</button>

					<button type="button" class="button button-link-delete srk-btn-reset-scan" id="srk-reset-scan-btn">
						<span class="dashicons dashicons-trash"></span>
						<?php esc_html_e( 'Reset Scan', 'seo-repair-kit' ); ?>
					</button>
				</div>
			</form>

			<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" id="srk-reset-settings-form" style="display:none;">
				<input type="hidden" name="action" value="srk_reset_scan_records_full" />
				<?php wp_nonce_field( 'srk_reset_scan_records_full', 'srk_reset_scan_records_full_nonce' ); ?>
			</form>

			<div id="srk-run-scan-result" style="display:none;margin-top:16px;"></div>

		</div>

		<script>
		jQuery(document).ready(function($) {
			function togglePostTypesRow() {
				if ($('#srk-scan-coverage').val() === 'whole_site') {
					$('#srk-post-types-row').hide();
				} else {
					$('#srk-post-types-row').show();
				}
			}

			togglePostTypesRow();
			$('#srk-scan-coverage').on('change', togglePostTypesRow);

			$('#srk-automation-settings-form').on('submit', function(e) {
                var isEnabled = $(this).find('input[name="enabled"]').is(':checked');

                if (!isEnabled) {
                    e.preventDefault();
                    alert('<?php echo esc_js( __( 'Please turn ON Enable Automation option before saving auto scan settings.', 'seo-repair-kit' ) ); ?>');
                    return;
                }

                if (!confirm('<?php echo esc_js( __( 'Save auto scan settings? This will reschedule the cron job and clear previous scan state.', 'seo-repair-kit' ) ); ?>')) {
                    e.preventDefault();
                }
            });

			$('#srk-reset-settings-btn').on('click', function() {
				if (confirm('<?php echo esc_js( __( 'Reset all automation settings to defaults and clear all scan records? This cannot be undone.', 'seo-repair-kit' ) ); ?>')) {
					$('#srk-reset-settings-form').submit();
				}
			});

			$('#srk-run-scan-btn').on('click', function() {
				if (!confirm('<?php echo esc_js( __( 'Run a full link scan now? This may take a few minutes depending on your site size.', 'seo-repair-kit' ) ); ?>')) {
					return;
				}

				var $btn = $(this);
				var $icon = $btn.find('.srk-btn-icon');
				var $text = $btn.find('.srk-btn-text');
				var $result = $('#srk-run-scan-result');

				$btn.prop('disabled', true);
				$icon.removeClass('dashicons-search').addClass('dashicons-update srk-spin');
				$text.text('<?php echo esc_js( __( 'Scanning...', 'seo-repair-kit' ) ); ?>');
				$result.hide();

				$.ajax({
					url: ajaxurl,
					type: 'POST',
					data: {
						action: 'srk_ajax_run_scan_now',
						nonce: '<?php echo esc_js( wp_create_nonce( 'srk_run_scan_now_nonce' ) ); ?>'
					},
					success: function(response) {
						if (response.success) {
							$result.html('<div class="notice notice-success inline"><p>' + response.data.message + '</p></div>').show();
						} else {
							$result.html('<div class="notice notice-error inline"><p>' + (response.data ? response.data.message : '<?php echo esc_js( __( 'Scan failed.', 'seo-repair-kit' ) ); ?>') + '</p></div>').show();
						}
					},
					error: function(xhr) {
						var message = '<?php echo esc_js( __( 'Request failed. Please try again.', 'seo-repair-kit' ) ); ?>';
						if (xhr.responseJSON && xhr.responseJSON.data && xhr.responseJSON.data.message) {
							message = xhr.responseJSON.data.message;
						}
						$result.html('<div class="notice notice-error inline"><p>' + message + '</p></div>').show();
					},
					complete: function() {
						$btn.prop('disabled', false);
						$icon.removeClass('dashicons-update srk-spin').addClass('dashicons-search');
						$text.text('<?php echo esc_js( __( 'Run Scan', 'seo-repair-kit' ) ); ?>');
					}
				});
			});

			$('#srk-reset-scan-btn').on('click', function() {
				if (!confirm('<?php echo esc_js( __( 'Reset all scan records and alert history? Settings will be kept. This cannot be undone.', 'seo-repair-kit' ) ); ?>')) {
					return;
				}

				var $btn = $(this);
				var $result = $('#srk-run-scan-result');

				$btn.prop('disabled', true);
				$result.hide();

				$.ajax({
					url: ajaxurl,
					type: 'POST',
					data: {
						action: 'srk_ajax_reset_scan_table',
						nonce: '<?php echo esc_js( wp_create_nonce( 'srk_reset_scan_table_nonce' ) ); ?>'
					},
					success: function(response) {
						if (response.success) {
							$result.html('<div class="notice notice-success inline"><p>' + response.data.message + '</p></div>').show();
						} else {
							$result.html('<div class="notice notice-error inline"><p>' + (response.data ? response.data.message : '<?php echo esc_js( __( 'Reset failed.', 'seo-repair-kit' ) ); ?>') + '</p></div>').show();
						}
					},
					error: function(xhr) {
						var message = '<?php echo esc_js( __( 'Request failed. Please try again.', 'seo-repair-kit' ) ); ?>';
						if (xhr.responseJSON && xhr.responseJSON.data && xhr.responseJSON.data.message) {
							message = xhr.responseJSON.data.message;
						}
						$result.html('<div class="notice notice-error inline"><p>' + message + '</p></div>').show();
					},
					complete: function() {
						$btn.prop('disabled', false);
					}
				});
			});
		});
		</script>
		<?php
	}

	/**
	 * Render alerts tab.
	 *
	 * @return void
	 */
	public function render_alerts_notifications_tab() {
		if ( ! class_exists( 'SeoRepairKit_LinkScanner_Automation' ) || ! class_exists( 'SeoRepairKit_LinksAlerts' ) ) {
			echo '<div class="notice notice-error"><p>' . esc_html__( 'Alerts module is not available.', 'seo-repair-kit' ) . '</p></div>';
			return;
		}

		$automation = SeoRepairKit_LinkScanner_Automation::get_instance();
		SeoRepairKit_LinksAlerts::render_history_tables( $automation );
	}

	/**
	 * Save scan schedule settings.
	 *
	 * @return void
	 */
	public function srk_save_scan_schedule() {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'Permission denied.', 'seo-repair-kit' ) );
		}

		check_admin_referer( 'srk_save_scan_schedule', 'srk_save_scan_schedule_nonce' );

        $enabled = isset( $_POST['enabled'] ) ? 1 : 0;

		if ( ! $enabled ) {
			if ( function_exists( 'srk_add_admin_notice' ) ) {
				srk_add_admin_notice(
					__( 'Please enable automation first. Settings were not saved.', 'seo-repair-kit' ),
					'error'
				);
			}

			wp_safe_redirect( admin_url( 'admin.php?page=seo-repair-kit-link-scanner&tab=automation-scan' ) );
			exit;
		}

		$scan_coverage = isset( $_POST['scan_coverage'] ) ? sanitize_key( wp_unslash( $_POST['scan_coverage'] ) ) : 'selected';
		$post_types    = isset( $_POST['post_types'] ) ? array_map( 'sanitize_key', (array) wp_unslash( $_POST['post_types'] ) ) : array();

		if ( class_exists( 'SRK_License_Helper' ) && ! SRK_License_Helper::is_link_scanner_unlimited() ) {
			$scan_coverage = 'selected';
			$post_types    = SRK_License_Helper::normalize_link_scanner_post_types( $post_types );
		}

		if ( class_exists( 'SeoRepairKit_LinkScanner_Automation' ) ) {
			SeoRepairKit_LinkScanner_Automation::save_settings(
				array(
					'enabled'            => $enabled,
					'interval'           => isset( $_POST['interval'] ) ? sanitize_key( wp_unslash( $_POST['interval'] ) ) : 'daily',
					'link_scope'         => isset( $_POST['link_scope'] ) ? sanitize_key( wp_unslash( $_POST['link_scope'] ) ) : 'both',
					'scan_coverage'      => $scan_coverage,
					'post_types'         => $post_types,
					'max_posts_per_run'  => isset( $_POST['max_posts_per_run'] ) ? absint( wp_unslash( $_POST['max_posts_per_run'] ) ) : 30,
					'max_links_per_post' => isset( $_POST['max_links_per_post'] ) ? intval( wp_unslash( $_POST['max_links_per_post'] ) ) : 0,
					'request_timeout'    => isset( $_POST['request_timeout'] ) ? absint( wp_unslash( $_POST['request_timeout'] ) ) : 8,
					'email_enabled'      => isset( $_POST['email_enabled'] ) ? 1 : 0,
					'email_recipients'   => isset( $_POST['email_recipients'] ) ? sanitize_text_field( wp_unslash( $_POST['email_recipients'] ) ) : '',
				)
			);
		}

		if ( function_exists( 'srk_add_admin_notice' ) ) {
			srk_add_admin_notice( __( 'Auto scan settings saved successfully.', 'seo-repair-kit' ), 'success' );
		}

		wp_safe_redirect( admin_url( 'admin.php?page=seo-repair-kit-link-scanner&tab=automation-scan' ) );
		exit;
	}

	/**
	 * Reset scan settings and records.
	 *
	 * @return void
	 */
	public function srk_reset_scan_records_full() {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'Permission denied.', 'seo-repair-kit' ) );
		}

		check_admin_referer( 'srk_reset_scan_records_full', 'srk_reset_scan_records_full_nonce' );

		if ( class_exists( 'SeoRepairKit_LinkScanner_Automation' ) ) {
			SeoRepairKit_LinkScanner_Automation::get_instance()->reset_records( true );
		}

		if ( function_exists( 'srk_add_admin_notice' ) ) {
			srk_add_admin_notice( __( 'Links Manager settings and alert history were reset successfully.', 'seo-repair-kit' ), 'success' );
		}

		wp_safe_redirect( admin_url( 'admin.php?page=seo-repair-kit-link-scanner&tab=automation-scan' ) );
		exit;
	}

	/**
	 * Export scan run history to CSV.
	 *
	 * @return void
	 */
	public function srk_export_scan_runs_csv() {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'Permission denied.', 'seo-repair-kit' ) );
		}

		check_admin_referer( 'srk_export_scan_runs_csv', 'srk_export_scan_runs_csv_nonce' );

		if ( ! class_exists( 'SeoRepairKit_LinkScanner_Automation' ) ) {
			wp_die( esc_html__( 'Automation service is not available.', 'seo-repair-kit' ) );
		}

		$runs = SeoRepairKit_LinkScanner_Automation::get_instance()->get_recent_runs( 1000 );

		nocache_headers();
		header( 'Content-Type: text/csv; charset=utf-8' );
		header( 'Content-Disposition: attachment; filename=srk-scan-run-history-' . gmdate( 'Y-m-d-H-i-s' ) . '.csv' );

		$output = fopen( 'php://output', 'w' );
		fprintf( $output, chr( 0xEF ) . chr( 0xBB ) . chr( 0xBF ) );

		fputcsv(
			$output,
			array(
				'Run Time',
				'Trigger',
				'Link Scope',
				'Scan Coverage',
				'Scanned Posts',
				'Total Links',
				'Broken Links',
				'Email Sent',
				'Post Type Breakdown',
			)
		);

		foreach ( $runs as $run ) {
			$breakdown_text = '';
			$breakdown      = json_decode( isset( $run['post_type_breakdown'] ) ? $run['post_type_breakdown'] : '', true );

			if ( is_array( $breakdown ) ) {
				$parts = array();

				foreach ( $breakdown as $post_type => $row ) {
					$parts[] = sprintf(
						'%1$s: %2$d broken / %3$d checked',
						$post_type,
						isset( $row['broken'] ) ? (int) $row['broken'] : 0,
						isset( $row['checked'] ) ? (int) $row['checked'] : 0
					);
				}

				$breakdown_text = implode( ' | ', $parts );
			}

			fputcsv(
				$output,
				array(
					isset( $run['ended_at'] ) ? $run['ended_at'] : '',
					isset( $run['trigger_type'] ) ? $run['trigger_type'] : '',
					isset( $run['link_scope'] ) ? $run['link_scope'] : 'both',
					isset( $run['scan_coverage'] ) ? $run['scan_coverage'] : 'selected',
					isset( $run['scanned_posts_count'] ) ? (int) $run['scanned_posts_count'] : 0,
					isset( $run['total_links_count'] ) ? (int) $run['total_links_count'] : 0,
					isset( $run['broken_links_count'] ) ? (int) $run['broken_links_count'] : 0,
					! empty( $run['email_sent'] ) ? 'Yes' : 'No',
					$breakdown_text,
				)
			);
		}

		fclose( $output );
		exit;
	}

	/**
	 * Export alert history to CSV.
	 *
	 * @return void
	 */
	public function srk_export_alerts_csv() {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'Permission denied.', 'seo-repair-kit' ) );
		}

		check_admin_referer( 'srk_export_alerts_csv', 'srk_export_alerts_csv_nonce' );

		if ( ! class_exists( 'SeoRepairKit_LinkScanner_Automation' ) ) {
			wp_die( esc_html__( 'Automation service is not available.', 'seo-repair-kit' ) );
		}

		$alerts = SeoRepairKit_LinkScanner_Automation::get_instance()->get_recent_alerts( 1000 );

		nocache_headers();
		header( 'Content-Type: text/csv; charset=utf-8' );
		header( 'Content-Disposition: attachment; filename=srk-alert-history-' . gmdate( 'Y-m-d-H-i-s' ) . '.csv' );

		$output = fopen( 'php://output', 'w' );
		fprintf( $output, chr( 0xEF ) . chr( 0xBB ) . chr( 0xBF ) );

		fputcsv( $output, array( 'Sent At', 'Subject', 'Recipients', 'Broken Links', 'Post Type Breakdown' ) );

		foreach ( $alerts as $alert ) {
			$breakdown_text = '';
			$breakdown      = json_decode( isset( $alert['post_type_breakdown'] ) ? $alert['post_type_breakdown'] : '', true );

			if ( is_array( $breakdown ) ) {
				$parts = array();

				foreach ( $breakdown as $post_type => $row ) {
					$parts[] = sprintf(
						'%1$s: %2$d broken / %3$d checked',
						$post_type,
						isset( $row['broken'] ) ? (int) $row['broken'] : 0,
						isset( $row['checked'] ) ? (int) $row['checked'] : 0
					);
				}

				$breakdown_text = implode( ' | ', $parts );
			}

			fputcsv(
				$output,
				array(
					isset( $alert['sent_at'] ) ? $alert['sent_at'] : '',
					isset( $alert['subject'] ) ? $alert['subject'] : '',
					isset( $alert['recipients'] ) ? $alert['recipients'] : '',
					isset( $alert['broken_links_count'] ) ? (int) $alert['broken_links_count'] : 0,
					$breakdown_text,
				)
			);
		}

		fclose( $output );
		exit;
	}

	/**
	 * Export a single scan run record as CSV.
	 *
	 * @return void
	 */
	public function srk_export_single_run_csv() {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'Permission denied.', 'seo-repair-kit' ) );
		}

		check_admin_referer( 'srk_export_single_run_csv', 'srk_export_single_run_csv_nonce' );

		if ( ! class_exists( 'SeoRepairKit_LinkScanner_Automation' ) ) {
			wp_die( esc_html__( 'Automation service is not available.', 'seo-repair-kit' ) );
		}

		$run_id = isset( $_POST['run_id'] ) ? absint( $_POST['run_id'] ) : 0;
		if ( ! $run_id ) {
			wp_die( esc_html__( 'Invalid run ID.', 'seo-repair-kit' ) );
		}

		global $wpdb;
		$table = $wpdb->prefix . 'srk_link_scan_runs';
		$run   = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$table} WHERE id = %d", $run_id ), ARRAY_A );

		if ( ! $run ) {
			wp_die( esc_html__( 'Record not found.', 'seo-repair-kit' ) );
		}

		nocache_headers();
		header( 'Content-Type: text/csv; charset=utf-8' );
		header( 'Content-Disposition: attachment; filename=srk-scan-run-' . $run_id . '-' . gmdate( 'Y-m-d' ) . '.csv' );

		$output = fopen( 'php://output', 'w' );
		fprintf( $output, chr( 0xEF ) . chr( 0xBB ) . chr( 0xBF ) );

		fputcsv( $output, array( 'Run ID', 'Run Time', 'Trigger', 'Link Scope', 'Scan Coverage', 'Scanned Posts', 'Total Links', 'Broken Links', 'Email Sent' ) );

		fputcsv(
			$output,
			array(
				$run_id,
				isset( $run['ended_at'] ) ? $run['ended_at'] : '',
				isset( $run['trigger_type'] ) ? $run['trigger_type'] : '',
				isset( $run['link_scope'] ) ? $run['link_scope'] : 'both',
				isset( $run['scan_coverage'] ) ? $run['scan_coverage'] : 'selected',
				isset( $run['scanned_posts_count'] ) ? (int) $run['scanned_posts_count'] : 0,
				isset( $run['total_links_count'] ) ? (int) $run['total_links_count'] : 0,
				isset( $run['broken_links_count'] ) ? (int) $run['broken_links_count'] : 0,
				! empty( $run['email_sent'] ) ? 'Yes' : 'No',
			)
		);

		fputcsv( $output, array() );

		$records = json_decode( isset( $run['records_json'] ) ? $run['records_json'] : '[]', true );
		if ( ! empty( $records ) && is_array( $records ) ) {
			fputcsv( $output, array( 'Post ID', 'Post Title', 'Post Type', 'Link', 'Scope', 'HTTP Code', 'Broken', 'Error' ) );

			foreach ( $records as $rec ) {
				fputcsv(
					$output,
					array(
						isset( $rec['post_id'] ) ? (int) $rec['post_id'] : '',
						isset( $rec['post_title'] ) ? $rec['post_title'] : '',
						isset( $rec['post_type'] ) ? $rec['post_type'] : '',
						isset( $rec['link'] ) ? $rec['link'] : '',
						isset( $rec['scope'] ) ? $rec['scope'] : '',
						isset( $rec['http_code'] ) ? (int) $rec['http_code'] : '',
						! empty( $rec['is_broken'] ) ? 'Yes' : 'No',
						isset( $rec['error'] ) ? $rec['error'] : '',
					)
				);
			}
		}

		fclose( $output );
		exit;
	}

	/**
	 * Export a single alert record as CSV.
	 *
	 * @return void
	 */
	public function srk_export_single_alert_csv() {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'Permission denied.', 'seo-repair-kit' ) );
		}

		check_admin_referer( 'srk_export_single_alert_csv', 'srk_export_single_alert_csv_nonce' );

		if ( ! class_exists( 'SeoRepairKit_LinkScanner_Automation' ) ) {
			wp_die( esc_html__( 'Automation service is not available.', 'seo-repair-kit' ) );
		}

		$alert_id = isset( $_POST['alert_id'] ) ? absint( $_POST['alert_id'] ) : 0;
		if ( ! $alert_id ) {
			wp_die( esc_html__( 'Invalid alert ID.', 'seo-repair-kit' ) );
		}

		global $wpdb;
		$table = $wpdb->prefix . 'srk_link_scan_alerts';
		$alert = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$table} WHERE id = %d", $alert_id ), ARRAY_A );

		if ( ! $alert ) {
			wp_die( esc_html__( 'Record not found.', 'seo-repair-kit' ) );
		}

		nocache_headers();
		header( 'Content-Type: text/csv; charset=utf-8' );
		header( 'Content-Disposition: attachment; filename=srk-alert-' . $alert_id . '-' . gmdate( 'Y-m-d' ) . '.csv' );

		$output = fopen( 'php://output', 'w' );
		fprintf( $output, chr( 0xEF ) . chr( 0xBB ) . chr( 0xBF ) );

		$breakdown_text = '';
		$breakdown      = json_decode( isset( $alert['post_type_breakdown'] ) ? $alert['post_type_breakdown'] : '', true );

		if ( is_array( $breakdown ) ) {
			$parts = array();

			foreach ( $breakdown as $pt => $row ) {
				$parts[] = sprintf( '%s: %d broken / %d checked', $pt, (int) $row['broken'], (int) $row['checked'] );
			}

			$breakdown_text = implode( ' | ', $parts );
		}

		fputcsv( $output, array( 'Alert ID', 'Sent At', 'Subject', 'Recipients', 'Broken Links', 'Post Type Breakdown' ) );
		fputcsv(
			$output,
			array(
				$alert_id,
				isset( $alert['sent_at'] ) ? $alert['sent_at'] : '',
				isset( $alert['subject'] ) ? $alert['subject'] : '',
				isset( $alert['recipients'] ) ? $alert['recipients'] : '',
				isset( $alert['broken_links_count'] ) ? (int) $alert['broken_links_count'] : 0,
				$breakdown_text,
			)
		);

		$payload = json_decode( isset( $alert['payload_snapshot'] ) ? $alert['payload_snapshot'] : '{}', true );
		if ( ! empty( $payload['records'] ) && is_array( $payload['records'] ) ) {
			fputcsv( $output, array() );
			fputcsv( $output, array( 'Post ID', 'Post Title', 'Post Type', 'Link', 'Scope', 'HTTP Code', 'Broken', 'Error' ) );

			foreach ( $payload['records'] as $rec ) {
				if ( empty( $rec['is_broken'] ) ) {
					continue;
				}

				fputcsv(
					$output,
					array(
						isset( $rec['post_id'] ) ? (int) $rec['post_id'] : '',
						isset( $rec['post_title'] ) ? $rec['post_title'] : '',
						isset( $rec['post_type'] ) ? $rec['post_type'] : '',
						isset( $rec['link'] ) ? $rec['link'] : '',
						isset( $rec['scope'] ) ? $rec['scope'] : '',
						isset( $rec['http_code'] ) ? (int) $rec['http_code'] : '',
						'Yes',
						isset( $rec['error'] ) ? $rec['error'] : '',
					)
				);
			}
		}

		fclose( $output );
		exit;
	}
}
