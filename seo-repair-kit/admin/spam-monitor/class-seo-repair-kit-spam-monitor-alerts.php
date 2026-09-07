<?php
/**
 * Spam Monitor Alerts Admin Tab.
 *
 * @package Seo_Repair_Kit
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Class SeoRepairKit_SpamMonitor_Alerts
 */
class SeoRepairKit_SpamMonitor_Alerts {

	/**
	 * Render the Alerts tab.
	 *
	 * @return void
	 */
	public function render() {
		if ( ! current_user_can( 'manage_options' ) ) {
			return;
		}

		$settings      = class_exists( 'SRK_Spam_Monitor_Alerts' ) ? SRK_Spam_Monitor_Alerts::get_settings() : array();
		$levels        = wp_parse_args( (array) ( $settings['alert_risk_levels'] ?? array() ), array( 'clean' => 0, 'suspicious' => 1, 'spam' => 1, 'critical' => 1 ) );
		$thresholds    = class_exists( 'SRK_Spam_Monitor_Alerts' ) ? SRK_Spam_Monitor_Alerts::get_thresholds() : array( 'clean_max' => 30, 'suspicious_max' => 60, 'spam_starts' => 61, 'critical_starts' => 81 );
		$history_limit = max( 25, min( 1000, absint( $settings['alert_history_limit'] ?? 100 ) ) );
		$history_page  = SRK_Spam_Monitor_Admin_Pagination::get_page( 'srk_alert_history_page' );
		$history_per_page = SRK_Spam_Monitor_Admin_Pagination::get_per_page( 'srk_alert_history_page' );
		$history_total = class_exists( 'SRK_Spam_Monitor_DB' ) ? min( $history_limit, SRK_Spam_Monitor_DB::count_alert_history() ) : 0;
		$history       = class_exists( 'SRK_Spam_Monitor_DB' ) ? SRK_Spam_Monitor_DB::get_alert_history( $history_per_page, SRK_Spam_Monitor_Admin_Pagination::get_offset( $history_page, $history_per_page ) ) : array();
		$recipients    = implode( "\n", array_filter( array_map( 'sanitize_email', (array) ( $settings['recipient_emails'] ?? array() ) ), 'is_email' ) );
		$admin_email   = sanitize_email( get_option( 'admin_email' ) );
		$alerts_export_url = wp_nonce_url( add_query_arg( array( 'action' => 'srk_sm_export_records', 'dataset' => 'alerts' ), admin_url( 'admin-post.php' ) ), 'srk_sm_export_alerts' );

		/* risk counts for bar chart */
		$risk_counts = class_exists( 'SRK_Spam_Monitor_DB' ) ? SRK_Spam_Monitor_DB::get_alert_risk_counts( 30 ) : array( 'clean' => 0, 'suspicious' => 0, 'spam' => 0, 'critical' => 0 );
		$chart_max = max( 1, max( $risk_counts ) );
		?>
		<div class="srk-alerts-wrap">
			<div id="srk-alerts-notices" class="srk-sm-notices" style="display:none;" aria-live="polite"></div>

			<!-- Page header -->
			<div class="srk-alerts-page-header">
				<h2><?php esc_html_e( 'Google SERP Alerts', 'seo-repair-kit' ); ?></h2>
				<p><?php esc_html_e( 'Email the site owner when Google-indexed results look suspicious, spammy, or critical.', 'seo-repair-kit' ); ?></p>
			</div>

			<!-- 2-col: form + volume chart -->
			<div class="srk-alerts-main-row">

				<!-- Alert Settings form card -->
				<form id="srk-alerts-settings-form" class="srk-sm-card srk-alerts-form-card">

					<div class="srk-alerts-form-card__head">
						<div class="srk-alerts-form-card__icon">
							<span class="dashicons dashicons-bell"></span>
						</div>
						<div>
							<h3><?php esc_html_e( 'Alert Settings', 'seo-repair-kit' ); ?></h3>
							<p><?php esc_html_e( 'Configure email alerts for SERP events', 'seo-repair-kit' ); ?></p>
						</div>
					</div>

					<!-- Enable alerts toggle -->
					<div class="srk-alerts-toggle-row">
						<div>
							<div class="srk-alerts-toggle-label"><?php esc_html_e( 'Enable Email Alerts', 'seo-repair-kit' ); ?></div>
							<div class="srk-alerts-toggle-desc"><?php esc_html_e( 'Master switch for all alert emails', 'seo-repair-kit' ); ?></div>
						</div>
						<label class="srk-sm-toggle">
							<input type="checkbox" name="alerts_enabled" value="1" <?php checked( ! empty( $settings['alerts_enabled'] ) ); ?>>
							<span class="srk-sm-toggle-slider"></span>
						</label>
					</div>

					<!-- History limit + email -->
					<div class="srk-alerts-inputs-row">
						<div class="srk-alerts-input-group">
							<label class="srk-alerts-input-label" for="srk-alert-history-limit"><?php esc_html_e( 'Maximum Alert History Records', 'seo-repair-kit' ); ?></label>
							<input type="number" id="srk-alert-history-limit" name="alert_history_limit" min="25" max="1000" value="<?php echo esc_attr( $history_limit ); ?>" class="srk-sm-text-input">
						</div>
						<div class="srk-alerts-input-group">
							<label class="srk-alerts-input-label" for="srk-alert-recipients"><?php esc_html_e( 'Alert Email Address', 'seo-repair-kit' ); ?></label>
							<input type="email" id="srk-alert-recipients" name="recipient_emails" value="<?php echo esc_attr( $recipients ); ?>" class="srk-sm-text-input" placeholder="<?php echo esc_attr( $admin_email ); ?>">
						</div>
					</div>

					<!-- Alert On Risk Levels pills -->
					<div class="srk-alerts-risk-row">
						<span class="srk-alerts-risk-label"><?php esc_html_e( 'Alert On Risk Levels', 'seo-repair-kit' ); ?></span>
						<div class="srk-alerts-risk-pills">
							<?php
							$pill_classes = array( 'clean' => '', 'suspicious' => 'srk-alerts-risk-pill--active-suspicious', 'spam' => 'srk-alerts-risk-pill--active-spam', 'critical' => 'srk-alerts-risk-pill--active-critical' );
							foreach ( $this->get_risk_level_labels() as $key => $label ) :
								$active     = ! empty( $levels[ $key ] );
								$extra_cls  = $active ? $pill_classes[ $key ] : '';
							?>
								<label class="srk-alerts-risk-pill <?php echo esc_attr( $extra_cls ); ?>">
									<input type="checkbox" name="<?php echo esc_attr( 'alert_on_' . $key ); ?>" value="1" <?php checked( $active ); ?>>
									<?php echo esc_html( $label ); ?>
								</label>
							<?php endforeach; ?>
						</div>
					</div>

					<!-- Send summary toggle -->
					<div class="srk-alerts-summary-row">
						<div>
							<div class="srk-alerts-toggle-label"><?php esc_html_e( 'Send Scan Summary Email', 'seo-repair-kit' ); ?></div>
							<div class="srk-alerts-toggle-desc"><?php esc_html_e( 'Digest after each completed scan', 'seo-repair-kit' ); ?></div>
						</div>
						<label class="srk-sm-toggle">
							<input type="checkbox" name="send_scan_summary" value="1" <?php checked( ! empty( $settings['send_scan_summary'] ) ); ?>>
							<span class="srk-sm-toggle-slider"></span>
						</label>
					</div>

					<!-- Recommendation notice -->
					<div class="srk-alerts-notice">
						<strong><?php esc_html_e( 'Recommended:', 'seo-repair-kit' ); ?></strong>
						<?php esc_html_e( 'enable Suspicious, Spam, and Critical. Keep Clean disabled to avoid notification fatigue.', 'seo-repair-kit' ); ?>
					</div>

					<!-- Detection thresholds -->
					<div>
						<div class="srk-alerts-input-label" style="margin-bottom:8px;"><?php esc_html_e( 'Current Detection Thresholds', 'seo-repair-kit' ); ?></div>
						<div class="srk-alerts-thresh-row">
							<div class="srk-alerts-thresh-chip">
								<div class="srk-alerts-thresh-chip__name">
									<span class="srk-alerts-thresh-chip__dot" style="background:#10B981;"></span>
									<?php esc_html_e( 'Clean', 'seo-repair-kit' ); ?>
								</div>
								<div class="srk-alerts-thresh-chip__range">0–<?php echo esc_html( absint( $thresholds['clean_max'] ) ); ?></div>
							</div>
							<div class="srk-alerts-thresh-chip">
								<div class="srk-alerts-thresh-chip__name">
									<span class="srk-alerts-thresh-chip__dot" style="background:#F59E0B;"></span>
									<?php esc_html_e( 'Suspicious', 'seo-repair-kit' ); ?>
								</div>
								<div class="srk-alerts-thresh-chip__range"><?php echo esc_html( absint( $thresholds['clean_max'] ) + 1 ); ?>–<?php echo esc_html( absint( $thresholds['suspicious_max'] ) ); ?></div>
							</div>
							<div class="srk-alerts-thresh-chip">
								<div class="srk-alerts-thresh-chip__name">
									<span class="srk-alerts-thresh-chip__dot" style="background:#EF4444;"></span>
									<?php esc_html_e( 'Spam', 'seo-repair-kit' ); ?>
								</div>
								<div class="srk-alerts-thresh-chip__range"><?php echo esc_html( absint( $thresholds['spam_starts'] ) ); ?>–<?php echo esc_html( absint( $thresholds['critical_starts'] ) - 1 ); ?></div>
							</div>
							<div class="srk-alerts-thresh-chip">
								<div class="srk-alerts-thresh-chip__name">
									<span class="srk-alerts-thresh-chip__dot" style="background:#7C3AED;"></span>
									<?php esc_html_e( 'Critical', 'seo-repair-kit' ); ?>
								</div>
								<div class="srk-alerts-thresh-chip__range"><?php echo esc_html( absint( $thresholds['critical_starts'] ) ); ?>–100</div>
							</div>
						</div>
					</div>

					<!-- Actions -->
					<div class="srk-alerts-form-actions">
						<button type="submit" id="srk-alerts-save-btn" class="button button-primary">
							<span class="dashicons dashicons-saved"></span>
							<?php esc_html_e( 'Save Alert Settings', 'seo-repair-kit' ); ?>
						</button>
						<button type="button" id="srk-alerts-test-btn" class="button">
							<span class="dashicons dashicons-email-alt"></span>
							<?php esc_html_e( 'Send Test Email', 'seo-repair-kit' ); ?>
						</button>
					</div>

				</form><!-- /#srk-alerts-settings-form -->

				<!-- Alert Volume chart card -->
				<div class="srk-sm-card srk-alerts-chart-card">
					<h3><?php esc_html_e( 'Alert Volume', 'seo-repair-kit' ); ?></h3>
					<p><?php esc_html_e( 'By risk level, last 30 days', 'seo-repair-kit' ); ?></p>

					<div class="srk-alerts-hbar-chart">
						<?php
						$bar_levels = array(
							'clean'      => array( 'label' => __( 'Clean', 'seo-repair-kit' ),      'cls' => 'srk-alerts-hbar-fill--clean' ),
							'suspicious' => array( 'label' => __( 'Suspicious', 'seo-repair-kit' ), 'cls' => 'srk-alerts-hbar-fill--suspicious' ),
							'spam'       => array( 'label' => __( 'Spam', 'seo-repair-kit' ),       'cls' => 'srk-alerts-hbar-fill--spam' ),
							'critical'   => array( 'label' => __( 'Critical', 'seo-repair-kit' ),   'cls' => 'srk-alerts-hbar-fill--critical' ),
						);
						foreach ( $bar_levels as $key => $meta ) :
							$count = $risk_counts[ $key ] ?? 0;
							$pct   = round( ( $count / $chart_max ) * 100 );
						?>
							<div class="srk-alerts-hbar-row">
								<span class="srk-alerts-hbar-label"><?php echo esc_html( $meta['label'] ); ?></span>
								<div class="srk-alerts-hbar-track">
									<div class="srk-alerts-hbar-fill <?php echo esc_attr( $meta['cls'] ); ?>" style="width:<?php echo esc_attr( $pct ); ?>%;"></div>
								</div>
								<span class="srk-alerts-hbar-val"><?php echo esc_html( $count ); ?></span>
							</div>
						<?php endforeach; ?>
					</div>

					<div class="srk-alerts-hbar-axis">
						<span>0</span>
						<span><?php echo esc_html( round( $chart_max / 4 ) ); ?></span>
						<span><?php echo esc_html( round( $chart_max / 2 ) ); ?></span>
						<span><?php echo esc_html( round( $chart_max * 3 / 4 ) ); ?></span>
						<span><?php echo esc_html( $chart_max ); ?></span>
					</div>
				</div>

			</div><!-- /.srk-alerts-main-row -->

			<!-- Alert History table -->
			<div class="srk-sm-card srk-alerts-history-card" id="srk-alert-history">
				<div class="srk-alerts-history-card__head">
					<div>
						<h3><?php esc_html_e( 'Alert History', 'seo-repair-kit' ); ?></h3>
						<p><?php esc_html_e( 'Most recent alert deliveries', 'seo-repair-kit' ); ?></p>
					</div>
					<div class="srk-alerts-history-card__filters">
						<a class="srk-dash-filter-btn srk-sm-record-export" href="<?php echo esc_url( $alerts_export_url ); ?>"><span class="dashicons dashicons-download"></span><?php esc_html_e( 'Export CSV', 'seo-repair-kit' ); ?></a>
						<button type="button" class="srk-dash-filter-btn srk-sm-clear-records" data-dataset="alerts"><span class="dashicons dashicons-trash"></span><?php esc_html_e( 'Clear History', 'seo-repair-kit' ); ?></button>
						<select class="srk-dash-search" id="srk-alerts-filter-risk" style="width:110px;">
							<option value=""><?php esc_html_e( 'All risk', 'seo-repair-kit' ); ?></option>
							<option value="critical"><?php esc_html_e( 'Critical', 'seo-repair-kit' ); ?></option>
							<option value="spam"><?php esc_html_e( 'Spam', 'seo-repair-kit' ); ?></option>
							<option value="suspicious"><?php esc_html_e( 'Suspicious', 'seo-repair-kit' ); ?></option>
							<option value="clean"><?php esc_html_e( 'Clean', 'seo-repair-kit' ); ?></option>
						</select>
						<input type="text" class="srk-dash-search" id="srk-alerts-filter-domain" placeholder="<?php esc_attr_e( 'Domain', 'seo-repair-kit' ); ?>" style="width:140px;">
						<select class="srk-dash-search" id="srk-alerts-filter-status" style="width:110px;">
							<option value=""><?php esc_html_e( 'All status', 'seo-repair-kit' ); ?></option>
							<option value="sent"><?php esc_html_e( 'Sent', 'seo-repair-kit' ); ?></option>
							<option value="skipped"><?php esc_html_e( 'Skipped', 'seo-repair-kit' ); ?></option>
						</select>
					</div>
				</div>

				<div class="srk-sm-table-scroll">
					<table class="srk-dash-url-table" id="srk-alerts-history-table">
						<thead>
							<tr>
								<th><?php esc_html_e( 'Date', 'seo-repair-kit' ); ?></th>
								<th><?php esc_html_e( 'Domain', 'seo-repair-kit' ); ?></th>
								<th><?php esc_html_e( 'Type', 'seo-repair-kit' ); ?></th>
								<th><?php esc_html_e( 'Risk', 'seo-repair-kit' ); ?></th>
								<th><?php esc_html_e( 'Score', 'seo-repair-kit' ); ?></th>
								<th><?php esc_html_e( 'URLs', 'seo-repair-kit' ); ?></th>
								<th><?php esc_html_e( 'Status', 'seo-repair-kit' ); ?></th>
							</tr>
						</thead>
						<tbody>
							<?php $this->render_history_rows( $history ); ?>
						</tbody>
					</table>
				</div>
				<?php SRK_Spam_Monitor_Admin_Pagination::render( 'srk_alert_history_page', $history_page, $history_total, 'srk-alert-history', 'alerts', $history_per_page ); ?>
			</div>

		</div><!-- /.srk-alerts-wrap -->

		<script>
		(function($){
			/* Live filter on alerts history table */
			function filterAlerts() {
				var risk   = $('#srk-alerts-filter-risk').val().toLowerCase();
				var domain = $('#srk-alerts-filter-domain').val().toLowerCase();
				var status = $('#srk-alerts-filter-status').val().toLowerCase();
				$('#srk-alerts-history-table tbody tr.srk-alerts-row').each(function(){
					var $r = $(this);
					var show = true;
					if ( risk   && $r.data('risk')   !== risk )   { show = false; }
					if ( domain && $r.data('domain').toLowerCase().indexOf(domain) === -1 ) { show = false; }
					if ( status && $r.data('status') !== status ) { show = false; }
					$r.toggle(show);
				});
			}
			$('#srk-alerts-filter-risk,#srk-alerts-filter-domain,#srk-alerts-filter-status').on('input change', filterAlerts);
		}(jQuery));
		</script>
		<?php
	}

	/**
	 * Risk labels.
	 *
	 * @return array
	 */
	private function get_risk_level_labels() {
		return array(
			'clean'      => __( 'Clean', 'seo-repair-kit' ),
			'suspicious' => __( 'Suspicious', 'seo-repair-kit' ),
			'spam'       => __( 'Spam', 'seo-repair-kit' ),
			'critical'   => __( 'Critical', 'seo-repair-kit' ),
		);
	}

	/**
	 * Render history rows with new badge classes.
	 *
	 * @param array $history Rows.
	 * @return void
	 */
	private function render_history_rows( array $history ) {
		if ( empty( $history ) ) {
			echo '<tr><td colspan="7" class="srk-dash-url-table__empty"><span class="dashicons dashicons-bell"></span>' . esc_html__( 'No alerts sent yet.', 'seo-repair-kit' ) . '</td></tr>';
			return;
		}

		foreach ( $history as $row ) {
			$risk        = sanitize_key( $row['risk_level'] ?? '' );
			$status_raw  = sanitize_key( strtolower( $row['status'] ?? 'sent' ) );
			$badge_class = 'srk-alerts-status-badge srk-alerts-status-badge--' . $status_raw;
			?>
			<tr class="srk-alerts-row"
				data-risk="<?php echo esc_attr( $risk ); ?>"
				data-domain="<?php echo esc_attr( $row['domain'] ?? '' ); ?>"
				data-status="<?php echo esc_attr( $status_raw ); ?>">
				<td class="srk-dash-url-table__date"><?php echo esc_html( $row['sent_at'] ?? '' ); ?></td>
				<td><?php echo esc_html( $row['domain'] ?? '' ); ?></td>
				<td><?php echo esc_html( $row['alert_type'] ?? 'Spam Detected' ); ?></td>
				<td><span class="srk-dash-risk-badge srk-dash-risk-badge--<?php echo esc_attr( $risk ); ?>"><?php echo esc_html( ucfirst( $risk ) ); ?></span></td>
				<td class="srk-dash-url-table__score"><?php echo esc_html( absint( $row['score'] ?? 0 ) ); ?></td>
				<td><?php echo esc_html( absint( $row['url_count'] ?? 0 ) ); ?></td>
				<td><span class="<?php echo esc_attr( $badge_class ); ?>"><?php echo esc_html( strtoupper( $status_raw ) ); ?></span></td>
			</tr>
			<?php
		}
	}

}
