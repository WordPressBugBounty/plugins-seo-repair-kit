<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * SERP-only Spam Monitor Dashboard tab.
 */
class SeoRepairKit_SpamMonitor_Dashboard {

	/**
	 * Render the dashboard.
	 *
	 * @return void
	 */
	public function render() {
		if ( ! current_user_can( 'manage_options' ) ) {
			return;
		}

		$risky_page = SRK_Spam_Monitor_Admin_Pagination::get_page( 'srk_risky_page' );
		$alert_page = SRK_Spam_Monitor_Admin_Pagination::get_page( 'srk_recent_alert_page' );
		$risky_per_page = SRK_Spam_Monitor_Admin_Pagination::get_per_page( 'srk_risky_page' );
		$alert_per_page = SRK_Spam_Monitor_Admin_Pagination::get_per_page( 'srk_recent_alert_page' );
		$risky_search = sanitize_text_field( wp_unslash( $_GET['srk_risky_search'] ?? '' ) ); // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		$risky_risk   = sanitize_key( wp_unslash( $_GET['srk_risky_risk'] ?? '' ) ); // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		$summary  = class_exists( 'SRK_Spam_Monitor_DB' ) ? SRK_Spam_Monitor_DB::get_serp_dashboard_summary( $risky_per_page, SRK_Spam_Monitor_Admin_Pagination::get_offset( $risky_page, $risky_per_page ), $risky_search, $risky_risk ) : array();
		$alerts   = class_exists( 'SRK_Spam_Monitor_DB' ) ? SRK_Spam_Monitor_DB::get_alert_history( $alert_per_page, SRK_Spam_Monitor_Admin_Pagination::get_offset( $alert_page, $alert_per_page ) ) : array();
		$alerts_total = class_exists( 'SRK_Spam_Monitor_DB' ) ? SRK_Spam_Monitor_DB::count_alert_history() : 0;
		$status   = $this->get_rules_status_labels();
		$scan_url = admin_url( 'admin.php?page=seo-repair-kit-spam-monitor&tab=google-serp-scan' );
		$rules_url = admin_url( 'admin.php?page=seo-repair-kit-spam-monitor&tab=spam-rules' );
		$cleanup_url = admin_url( 'admin.php?page=seo-repair-kit-spam-monitor&tab=gsc-cleanup' );
		$results_export_url = wp_nonce_url( add_query_arg( array( 'action' => 'srk_sm_export_records', 'dataset' => 'serp_results' ), admin_url( 'admin-post.php' ) ), 'srk_sm_export_serp_results' );
		$alerts_export_url  = wp_nonce_url( add_query_arg( array( 'action' => 'srk_sm_export_records', 'dataset' => 'alerts' ), admin_url( 'admin-post.php' ) ), 'srk_sm_export_alerts' );

		/* ---- computed values ---- */
		$total_scans    = absint( $summary['total_scans'] ?? 0 );
		$total_checked  = absint( $summary['total_results_checked'] ?? 0 );
		$critical_res   = absint( $summary['critical_results'] ?? 0 );
		$spam_res       = absint( $summary['spam_results'] ?? 0 );
		$suspicious_res = absint( $summary['suspicious_results'] ?? 0 );
		$requests_used  = absint( $summary['serp_requests_used'] ?? 0 );
		$last_scan      = ! empty( $summary['last_scan_date'] ) ? mysql2date( 'Y-m-d H:i', $summary['last_scan_date'] ) : '-';

		$cleanup_queue  = absint( $summary['cleanup']['cleanup_queue'] ?? 0 );
		$cleaned        = absint( $summary['cleanup']['spam_urls_removed'] ?? 0 );
		$monitoring     = absint( $summary['cleanup']['waiting_for_google'] ?? 0 );
		$resolved       = absint( $summary['cleanup']['resolved_cases'] ?? 0 );
		$sitemap_issues = absint( $summary['cleanup']['sitemap_issues'] ?? 0 );
		$critical_idx   = absint( $summary['cleanup']['critical_indexed_spam'] ?? 0 );
		$needs_review   = absint( $summary['cleanup']['needs_review'] ?? max( 0, $critical_idx - $monitoring - $resolved ) );

		/* free trial */
		$trial_remaining = '-';
		if ( class_exists( 'SRK_Spam_Monitor_SERP_Provider' ) ) {
			$trial_local = ( new SRK_Spam_Monitor_SERP_Provider() )->get_local_trial_status();
			$trial_remaining = $trial_local['remaining_requests'] ?? '-';
		}

		$trial_numeric = is_numeric( $trial_remaining ) ? absint( $trial_remaining ) : 0;
		$dashboard_chart_metrics = array(
			array( 'label' => __( 'SERP Scans', 'seo-repair-kit' ), 'value' => $total_scans, 'description' => __( 'Across all domains', 'seo-repair-kit' ), 'class' => 'primary' ),
			array( 'label' => __( 'Results Checked', 'seo-repair-kit' ), 'value' => $total_checked, 'description' => __( 'Last 30 days', 'seo-repair-kit' ), 'class' => 'primary' ),
			array( 'label' => __( 'Indexed Critical', 'seo-repair-kit' ), 'value' => $critical_idx, 'description' => __( 'Needs immediate review', 'seo-repair-kit' ), 'class' => 'critical' ),
			array( 'label' => __( 'Cleanup Queue', 'seo-repair-kit' ), 'value' => $cleanup_queue, 'description' => __( 'URLs pending action', 'seo-repair-kit' ), 'class' => 'warning' ),
			array( 'label' => __( 'Critical SERP', 'seo-repair-kit' ), 'value' => $critical_res, 'description' => __( 'URLs', 'seo-repair-kit' ), 'class' => 'critical' ),
			array( 'label' => __( 'Spam SERP', 'seo-repair-kit' ), 'value' => $spam_res, 'description' => __( 'URLs', 'seo-repair-kit' ), 'class' => 'error' ),
			array( 'label' => __( 'Suspicious', 'seo-repair-kit' ), 'value' => $suspicious_res, 'description' => __( 'Alerts', 'seo-repair-kit' ), 'class' => 'warning' ),
			array( 'label' => __( 'Requests Used', 'seo-repair-kit' ), 'value' => $requests_used, 'description' => __( 'This month', 'seo-repair-kit' ), 'class' => 'primary' ),
			array( 'label' => __( 'Cleaned URLs', 'seo-repair-kit' ), 'value' => $cleaned, 'description' => __( 'Cleared', 'seo-repair-kit' ), 'class' => 'success' ),
			array( 'label' => __( 'Monitoring', 'seo-repair-kit' ), 'value' => $monitoring, 'description' => __( 'In progress', 'seo-repair-kit' ), 'class' => 'primary' ),
			array( 'label' => __( 'Resolved', 'seo-repair-kit' ), 'value' => $resolved, 'description' => __( 'Clean', 'seo-repair-kit' ), 'class' => 'success' ),
			array( 'label' => __( 'Sitemap Issues', 'seo-repair-kit' ), 'value' => $sitemap_issues, 'description' => __( 'URLs', 'seo-repair-kit' ), 'class' => 'error' ),
			array( 'label' => __( 'Free Trial', 'seo-repair-kit' ), 'value' => $trial_numeric, 'description' => __( 'Requests left of 5', 'seo-repair-kit' ), 'class' => 'warning' ),
		);
		$dashboard_chart_max = max( 1, max( array_column( $dashboard_chart_metrics, 'value' ) ) );
		?>
		<div class="srk-sm-dashboard" id="srk-sm-dashboard-wrap">
			<div id="srk-dash-notices" class="srk-sm-notices" style="display:none;" aria-live="polite"></div>
			<div class="srk-dash-overview-grid">
				<div class="srk-dash-overview-cards">

			<!-- ── ROW 1 — 4 HERO KPI CARDS ──────────────────────────────────── -->
			<div class="srk-dash-hero-kpis">

				<div class="srk-dash-kpi srk-dash-kpi--blue">
					<div class="srk-dash-kpi__icon">
						<span class="dashicons dashicons-search"></span>
					</div>
					<div class="srk-dash-kpi__body">
						<div class="srk-dash-kpi__label"><?php esc_html_e( 'Total SERP Scans', 'seo-repair-kit' ); ?></div>
						<div class="srk-dash-kpi__value"><?php echo esc_html( $total_scans ); ?></div>
						<div class="srk-dash-kpi__sub"><?php esc_html_e( 'Across all domains', 'seo-repair-kit' ); ?></div>
					</div>
				</div>

				<div class="srk-dash-kpi srk-dash-kpi--orange">
					<div class="srk-dash-kpi__icon">
						<span class="dashicons dashicons-visibility"></span>
					</div>
					<div class="srk-dash-kpi__body">
						<div class="srk-dash-kpi__label"><?php esc_html_e( 'Google Results Checked', 'seo-repair-kit' ); ?></div>
						<div class="srk-dash-kpi__value"><?php echo esc_html( $total_checked ); ?></div>
						<div class="srk-dash-kpi__sub"><?php esc_html_e( 'Last 30 days', 'seo-repair-kit' ); ?></div>
					</div>
				</div>

				<div class="srk-dash-kpi srk-dash-kpi--red">
					<div class="srk-dash-kpi__icon">
						<span class="dashicons dashicons-warning"></span>
					</div>
					<div class="srk-dash-kpi__body">
						<div class="srk-dash-kpi__label"><?php esc_html_e( 'Critical Indexed Spam', 'seo-repair-kit' ); ?></div>
						<div class="srk-dash-kpi__value"><?php echo esc_html( $critical_idx ); ?></div>
						<div class="srk-dash-kpi__sub"><?php esc_html_e( 'Needs immediate review', 'seo-repair-kit' ); ?></div>
					</div>
				</div>

				<div class="srk-dash-kpi srk-dash-kpi--orange">
					<div class="srk-dash-kpi__icon">
						<span class="dashicons dashicons-list-view"></span>
					</div>
					<div class="srk-dash-kpi__body">
						<div class="srk-dash-kpi__label"><?php esc_html_e( 'Cleanup Queue', 'seo-repair-kit' ); ?></div>
						<div class="srk-dash-kpi__value"><?php echo esc_html( $cleanup_queue ); ?></div>
						<div class="srk-dash-kpi__sub"><?php esc_html_e( 'URLs pending action', 'seo-repair-kit' ); ?></div>
					</div>
				</div>

			</div><!-- /.srk-dash-hero-kpis -->

			<!-- ── ROW 2 — 6-COLUMN MINI KPIs ──────────────────────────────────── -->
			<div class="srk-dash-mini-kpis">

				<div class="srk-dash-mini-kpi">
					<div class="srk-dash-mini-kpi__label"><?php esc_html_e( 'Last Scan', 'seo-repair-kit' ); ?></div>
					<div class="srk-dash-mini-kpi__value"><?php echo esc_html( $last_scan ); ?></div>
					<div class="srk-dash-mini-kpi__sub"><?php esc_html_e( 'Most recent completed scan', 'seo-repair-kit' ); ?></div>
				</div>

				<div class="srk-dash-mini-kpi srk-dash-mini-kpi--critical">
					<div class="srk-dash-mini-kpi__label"><?php esc_html_e( 'Critical SERP', 'seo-repair-kit' ); ?></div>
					<div class="srk-dash-mini-kpi__value"><?php echo esc_html( $critical_res ); ?></div>
					<div class="srk-dash-mini-kpi__sub"><?php esc_html_e( 'URLs', 'seo-repair-kit' ); ?></div>
				</div>

				<div class="srk-dash-mini-kpi srk-dash-mini-kpi--spam">
					<div class="srk-dash-mini-kpi__label"><?php esc_html_e( 'Spam SERP', 'seo-repair-kit' ); ?></div>
					<div class="srk-dash-mini-kpi__value"><?php echo esc_html( $spam_res ); ?></div>
					<div class="srk-dash-mini-kpi__sub"><?php esc_html_e( 'URLs', 'seo-repair-kit' ); ?></div>
				</div>

				<div class="srk-dash-mini-kpi srk-dash-mini-kpi--suspicious">
					<div class="srk-dash-mini-kpi__label"><?php esc_html_e( 'Suspicious SERP', 'seo-repair-kit' ); ?></div>
					<div class="srk-dash-mini-kpi__value"><?php echo esc_html( $suspicious_res ); ?></div>
					<div class="srk-dash-mini-kpi__sub"><?php esc_html_e( 'alerts', 'seo-repair-kit' ); ?></div>
				</div>

				<div class="srk-dash-mini-kpi">
					<div class="srk-dash-mini-kpi__label"><?php esc_html_e( 'Requests Used', 'seo-repair-kit' ); ?></div>
					<div class="srk-dash-mini-kpi__value"><?php echo esc_html( $requests_used ); ?></div>
					<div class="srk-dash-mini-kpi__sub"><?php esc_html_e( 'this month', 'seo-repair-kit' ); ?></div>
				</div>

				<div class="srk-dash-mini-kpi srk-dash-mini-kpi--clean">
					<div class="srk-dash-mini-kpi__label"><?php esc_html_e( 'Cleaned URLs', 'seo-repair-kit' ); ?></div>
					<div class="srk-dash-mini-kpi__value"><?php echo esc_html( $cleaned ); ?></div>
					<div class="srk-dash-mini-kpi__sub"><?php esc_html_e( 'cleared', 'seo-repair-kit' ); ?></div>
				</div>


			<!-- ── ROW 3 — 6-COLUMN STATUS KPIs ────────────────────────────────── -->
				<div class="srk-dash-mini-kpi">
					<div class="srk-dash-mini-kpi__label"><?php esc_html_e( 'Monitoring', 'seo-repair-kit' ); ?></div>
					<div class="srk-dash-mini-kpi__value"><?php echo esc_html( $monitoring ); ?></div>
					<div class="srk-dash-mini-kpi__sub"><?php esc_html_e( 'in progress', 'seo-repair-kit' ); ?></div>
				</div>

				<div class="srk-dash-mini-kpi srk-dash-mini-kpi--clean">
					<div class="srk-dash-mini-kpi__label"><?php esc_html_e( 'Resolved', 'seo-repair-kit' ); ?></div>
					<div class="srk-dash-mini-kpi__value"><?php echo esc_html( $resolved ); ?></div>
					<div class="srk-dash-mini-kpi__sub"><?php esc_html_e( 'clean', 'seo-repair-kit' ); ?></div>
				</div>

				<div class="srk-dash-mini-kpi <?php echo $sitemap_issues > 0 ? 'srk-dash-mini-kpi--spam' : ''; ?>">
					<div class="srk-dash-mini-kpi__label"><?php esc_html_e( 'Sitemap Issues', 'seo-repair-kit' ); ?></div>
					<div class="srk-dash-mini-kpi__value"><?php echo esc_html( $sitemap_issues ); ?></div>
					<div class="srk-dash-mini-kpi__sub"><?php esc_html_e( 'URLs', 'seo-repair-kit' ); ?></div>
				</div>

				<div class="srk-dash-mini-kpi">
					<div class="srk-dash-mini-kpi__label"><?php esc_html_e( 'Python Engine', 'seo-repair-kit' ); ?></div>
					<div class="srk-dash-mini-kpi__value srk-dash-mini-kpi__value--badge <?php echo esc_attr( 'Synced' === $status['engine'] ? 'srk-dash-status-badge--synced' : 'srk-dash-status-badge--unsynced' ); ?>">
						<?php echo esc_html( $status['engine'] ); ?>
					</div>
					<div class="srk-dash-mini-kpi__sub"><?php esc_html_e( 'Engine status', 'seo-repair-kit' ); ?></div>
				</div>

				<div class="srk-dash-mini-kpi">
					<div class="srk-dash-mini-kpi__label"><?php esc_html_e( 'Spam Rules', 'seo-repair-kit' ); ?></div>
					<div class="srk-dash-mini-kpi__value srk-dash-mini-kpi__value--badge <?php echo esc_attr( 'Synced' === $status['rules'] ? 'srk-dash-status-badge--synced' : 'srk-dash-status-badge--unsynced' ); ?>">
						<?php echo esc_html( $status['rules'] ); ?>
					</div>
					<div class="srk-dash-mini-kpi__sub"><?php esc_html_e( 'Up-to-date', 'seo-repair-kit' ); ?></div>
				</div>

				<div class="srk-dash-mini-kpi srk-dash-mini-kpi--trial">
					<div class="srk-dash-mini-kpi__label"><?php esc_html_e( 'Free Trial', 'seo-repair-kit' ); ?></div>
					<div class="srk-dash-mini-kpi__value"><?php echo esc_html( $trial_remaining ); ?> <span class="srk-dash-mini-kpi__unit"><?php esc_html_e( 'left', 'seo-repair-kit' ); ?></span></div>
					<div class="srk-dash-mini-kpi__sub"><?php esc_html_e( 'of 5', 'seo-repair-kit' ); ?></div>
				</div>

			</div><!-- /.srk-dash-mini-kpis -->
				</div><!-- /.srk-dash-overview-cards -->

				<div class="srk-sm-card srk-dash-stats-chart-card">
					<div class="srk-dash-risk-chart-card__header">
						<div class="srk-dash-chart-card__icon srk-dash-chart-card__icon--blue">
							<span class="dashicons dashicons-chart-bar"></span>
						</div>
						<div>
							<h3><?php esc_html_e( 'Spam Monitor Stats', 'seo-repair-kit' ); ?></h3>
							<p><?php esc_html_e( 'Every numeric dashboard card on one shared scale. Hover or focus a bar for its exact value and meaning.', 'seo-repair-kit' ); ?></p>
						</div>
					</div>
					<div class="srk-dash-vchart-legend" aria-label="<?php esc_attr_e( 'Chart color legend', 'seo-repair-kit' ); ?>">
						<span><i class="is-primary"></i><?php esc_html_e( 'Activity', 'seo-repair-kit' ); ?></span>
						<span><i class="is-success"></i><?php esc_html_e( 'Completed', 'seo-repair-kit' ); ?></span>
						<span><i class="is-warning"></i><?php esc_html_e( 'Attention', 'seo-repair-kit' ); ?></span>
						<span><i class="is-error"></i><?php esc_html_e( 'Issue', 'seo-repair-kit' ); ?></span>
						<span><i class="is-critical"></i><?php esc_html_e( 'Critical', 'seo-repair-kit' ); ?></span>
					</div>

					<div class="srk-dash-vchart-area">
					<div class="srk-dash-vchart-scale" aria-hidden="true">
						<span><?php echo esc_html( $dashboard_chart_max ); ?></span>
						<span><?php echo esc_html( round( $dashboard_chart_max * 3 / 4 ) ); ?></span>
						<span><?php echo esc_html( round( $dashboard_chart_max / 2 ) ); ?></span>
						<span><?php echo esc_html( round( $dashboard_chart_max / 4 ) ); ?></span>
						<span>0</span>
					</div>

					<div class="srk-dash-vchart" role="list" aria-label="<?php esc_attr_e( 'Spam Monitor dashboard statistics', 'seo-repair-kit' ); ?>">
						<?php foreach ( $dashboard_chart_metrics as $metric ) :
							$metric_value  = absint( $metric['value'] );
							$metric_height = $metric_value > 0 ? max( 5, round( ( $metric_value / $dashboard_chart_max ) * 100 ) ) : 2;
							$metric_help   = sprintf(
								/* translators: 1: metric label, 2: metric value, 3: metric explanation. */
								__( '%1$s: %2$d. %3$s', 'seo-repair-kit' ),
								$metric['label'],
								$metric_value,
								$metric['description']
							);
						?>
							<div class="srk-dash-vchart-item" role="listitem" tabindex="0" aria-label="<?php echo esc_attr( $metric_help ); ?>">
								<div class="srk-dash-vchart-plot">
									<div class="srk-dash-vchart-tooltip" role="tooltip">
										<strong><?php echo esc_html( $metric_value ); ?></strong>
										<span><?php echo esc_html( $metric['description'] ); ?></span>
									</div>
									<div class="srk-dash-vchart-bar srk-dash-vchart-bar--<?php echo esc_attr( $metric['class'] ); ?>" style="height:<?php echo esc_attr( $metric_height ); ?>%;"></div>
								</div>
								<span class="srk-dash-vchart-label"><?php echo esc_html( $metric['label'] ); ?></span>
							</div>
						<?php endforeach; ?>
					</div>
					</div><!-- /.srk-dash-vchart-area -->

				</div><!-- /.srk-dash-stats-chart-card -->
			</div><!-- /.srk-dash-overview-grid -->

			<!-- ── LATEST RISKY URLS ──────────────────────────────────────────── -->
			<div class="srk-sm-card srk-dash-table-card" id="srk-latest-risky-urls">
				<div class="srk-dash-table-card__header">
					<div>
						<h3><?php esc_html_e( 'Latest Risky URLs', 'seo-repair-kit' ); ?></h3>
						<p><?php esc_html_e( 'Most recent indexed results flagged by SERP scan', 'seo-repair-kit' ); ?></p>
					</div>
					<form class="srk-dash-table-card__toolbar" method="get" action="<?php echo esc_url( admin_url( 'admin.php' ) ); ?>">
						<input type="hidden" name="page" value="seo-repair-kit-spam-monitor">
						<input type="hidden" name="srk_risky_per_page" value="<?php echo esc_attr( $risky_per_page ); ?>">
						<input type="search" name="srk_risky_search" id="srk-dash-url-search" class="srk-dash-search" value="<?php echo esc_attr( $risky_search ); ?>" placeholder="<?php esc_attr_e( 'Search all risky URLs...', 'seo-repair-kit' ); ?>" autocomplete="off">
						<select name="srk_risky_risk" class="srk-dash-search" aria-label="<?php esc_attr_e( 'Filter by risk', 'seo-repair-kit' ); ?>">
							<option value=""><?php esc_html_e( 'All risk', 'seo-repair-kit' ); ?></option>
							<option value="critical" <?php selected( $risky_risk, 'critical' ); ?>><?php esc_html_e( 'Critical', 'seo-repair-kit' ); ?></option>
							<option value="spam" <?php selected( $risky_risk, 'spam' ); ?>><?php esc_html_e( 'Spam', 'seo-repair-kit' ); ?></option>
							<option value="suspicious" <?php selected( $risky_risk, 'suspicious' ); ?>><?php esc_html_e( 'Suspicious', 'seo-repair-kit' ); ?></option>
						</select>
						<button type="submit" class="srk-dash-filter-btn">
							<span class="dashicons dashicons-filter"></span>
							<?php esc_html_e( 'Filter', 'seo-repair-kit' ); ?>
						</button>
						<a class="srk-dash-filter-btn srk-sm-record-export" href="<?php echo esc_url( $results_export_url ); ?>"><span class="dashicons dashicons-download"></span><?php esc_html_e( 'Export CSV', 'seo-repair-kit' ); ?></a>
						<button type="button" class="srk-dash-filter-btn srk-sm-clear-records" data-dataset="serp"><span class="dashicons dashicons-trash"></span><?php esc_html_e( 'Clear SERP Data', 'seo-repair-kit' ); ?></button>
						<?php if ( '' !== $risky_search || '' !== $risky_risk ) : ?>
							<a class="srk-dash-filter-btn" href="<?php echo esc_url( admin_url( 'admin.php?page=seo-repair-kit-spam-monitor' ) ); ?>"><?php esc_html_e( 'Clear', 'seo-repair-kit' ); ?></a>
						<?php endif; ?>
					</form>
				</div>

				<div class="srk-sm-table-scroll">
					<table class="srk-dash-url-table" id="srk-dash-risky-url-table">
						<thead>
							<tr>
								<th><?php esc_html_e( 'Domain', 'seo-repair-kit' ); ?></th>
								<th><?php esc_html_e( 'URL', 'seo-repair-kit' ); ?></th>
								<th><?php esc_html_e( 'Google Title', 'seo-repair-kit' ); ?></th>
								<th><?php esc_html_e( 'Risk', 'seo-repair-kit' ); ?></th>
								<th><?php esc_html_e( 'Score', 'seo-repair-kit' ); ?></th>
								<th><?php esc_html_e( 'Action', 'seo-repair-kit' ); ?></th>
							</tr>
						</thead>
						<tbody>
							<?php if ( ! empty( $summary['risky_urls'] ) ) : ?>
								<?php foreach ( $summary['risky_urls'] as $row ) :
									$risk  = strtolower( $row['risk_level'] ?? 'clean' );
									$score = absint( $row['risk_score'] ?? 0 );
									$url    = $row['url'] ?? '';
									$domain = $row['domain'] ?? '';
									$title  = wp_trim_words( $row['google_title'] ?? '', 8, '...' );
								?>
									<tr class="srk-dash-url-row" data-risk="<?php echo esc_attr( $risk ); ?>">
										<td class="srk-dash-url-table__domain"><?php echo esc_html( $domain ); ?></td>
										<td class="srk-dash-url-table__url">
											<a href="<?php echo esc_url( $url ); ?>" target="_blank" rel="noopener noreferrer"><?php echo esc_html( $row['url'] ?? '' ); ?></a>
										</td>
										<td class="srk-dash-url-table__title"><?php echo esc_html( $title ); ?></td>
										<td><span class="srk-dash-risk-badge srk-dash-risk-badge--<?php echo esc_attr( $risk ); ?>"><?php echo esc_html( ucfirst( $risk ) ); ?></span></td>
										<td class="srk-dash-url-table__score"><?php echo esc_html( $score ); ?></td>
										<td class="srk-dash-url-table__actions">
											<a href="<?php echo esc_url( $cleanup_url ); ?>" class="srk-dash-action-btn srk-dash-action-btn--ghost"><?php esc_html_e( 'Review', 'seo-repair-kit' ); ?></a>
											<a href="https://www.google.com/search?q=site:<?php echo rawurlencode( $row['url'] ?? '' ); ?>" target="_blank" rel="noopener noreferrer" class="srk-dash-action-btn srk-dash-action-btn--ghost"><?php esc_html_e( 'SERP', 'seo-repair-kit' ); ?></a>
											<a href="<?php echo esc_url( $cleanup_url ); ?>" class="srk-dash-action-btn srk-dash-action-btn--orange"><?php esc_html_e( 'Cleanup', 'seo-repair-kit' ); ?></a>
										</td>
									</tr>
								<?php endforeach; ?>
							<?php else : ?>
								<tr>
									<td colspan="6" class="srk-dash-url-table__empty">
										<span class="dashicons dashicons-yes-alt"></span>
										<?php esc_html_e( 'No risky SERP URLs stored yet. Run a Google SERP Scan first.', 'seo-repair-kit' ); ?>
									</td>
								</tr>
							<?php endif; ?>
						</tbody>
					</table>
				</div>
				<?php SRK_Spam_Monitor_Admin_Pagination::render( 'srk_risky_page', $risky_page, $summary['risky_urls_total'] ?? 0, 'srk-latest-risky-urls', 'dashboard', $risky_per_page, array( 'srk_risky_search' => $risky_search, 'srk_risky_risk' => $risky_risk ) ); ?>
			</div><!-- /.srk-dash-table-card risky urls -->

			<!-- ── RECENT ALERTS ──────────────────────────────────────────────── -->
			<div class="srk-sm-card srk-dash-table-card" id="srk-recent-alerts">
				<div class="srk-dash-table-card__header">
					<div>
						<h3><?php esc_html_e( 'Recent Alerts', 'seo-repair-kit' ); ?></h3>
						<p><?php esc_html_e( 'Most recent alert deliveries', 'seo-repair-kit' ); ?></p>
					</div>
					<div class="srk-dash-table-card__toolbar">
						<a class="srk-dash-filter-btn srk-sm-record-export" href="<?php echo esc_url( $alerts_export_url ); ?>"><span class="dashicons dashicons-download"></span><?php esc_html_e( 'Export CSV', 'seo-repair-kit' ); ?></a>
						<button type="button" class="srk-dash-filter-btn srk-sm-clear-records" data-dataset="alerts"><span class="dashicons dashicons-trash"></span><?php esc_html_e( 'Clear History', 'seo-repair-kit' ); ?></button>
					</div>
				</div>

				<div class="srk-sm-table-scroll">
					<table class="srk-dash-url-table" id="srk-dash-alerts-table">
						<thead>
							<tr>
								<th><?php esc_html_e( 'Date', 'seo-repair-kit' ); ?></th>
								<th><?php esc_html_e( 'Domain', 'seo-repair-kit' ); ?></th>
								<th><?php esc_html_e( 'Type', 'seo-repair-kit' ); ?></th>
								<th><?php esc_html_e( 'Risk', 'seo-repair-kit' ); ?></th>
								<th><?php esc_html_e( 'Score', 'seo-repair-kit' ); ?></th>
								<th><?php esc_html_e( 'Status', 'seo-repair-kit' ); ?></th>
							</tr>
						</thead>
						<tbody>
							<?php if ( ! empty( $alerts ) ) : ?>
								<?php foreach ( $alerts as $alert ) :
									$risk       = strtolower( $alert['risk_level'] ?? 'clean' );
									$sent_status = strtolower( $alert['status'] ?? 'sent' );
								?>
									<tr>
										<td class="srk-dash-url-table__date"><?php echo esc_html( $alert['sent_at'] ?? '' ); ?></td>
										<td class="srk-dash-url-table__domain"><?php echo esc_html( $alert['domain'] ?? '' ); ?></td>
										<td><?php echo esc_html( $alert['alert_type'] ?? 'Spam Detected' ); ?></td>
										<td><span class="srk-dash-risk-badge srk-dash-risk-badge--<?php echo esc_attr( $risk ); ?>"><?php echo esc_html( ucfirst( $risk ) ); ?></span></td>
										<td class="srk-dash-url-table__score"><?php echo esc_html( absint( $alert['score'] ?? 0 ) ); ?></td>
										<td>
											<span class="srk-dash-sent-badge srk-dash-sent-badge--<?php echo esc_attr( $sent_status ); ?>">
												<?php echo esc_html( strtoupper( $alert['status'] ?? 'SENT' ) ); ?>
											</span>
										</td>
									</tr>
								<?php endforeach; ?>
							<?php else : ?>
								<tr>
									<td colspan="6" class="srk-dash-url-table__empty">
										<span class="dashicons dashicons-bell"></span>
										<?php esc_html_e( 'No alerts sent yet.', 'seo-repair-kit' ); ?>
									</td>
								</tr>
							<?php endif; ?>
						</tbody>
					</table>
				</div>
				<?php SRK_Spam_Monitor_Admin_Pagination::render( 'srk_recent_alert_page', $alert_page, $alerts_total, 'srk-recent-alerts', 'dashboard', $alert_per_page ); ?>
			</div><!-- /.srk-dash-table-card recent alerts -->

		</div><!-- /#srk-sm-dashboard-wrap -->

		<?php
	}

	/**
	 * Render one KPI card (kept for backward compatibility — not used in new layout).
	 *
	 * @param string $label Label.
	 * @param mixed  $value Value.
	 * @param string $icon  Dashicon class.
	 * @return void
	 */
	private function render_kpi( $label, $value, $icon ) {
		?>
		<div class="srk-sm-kpi-card">
			<div class="srk-sm-kpi-icon"><span class="dashicons <?php echo esc_attr( $icon ); ?>"></span></div>
			<div class="srk-sm-kpi-value"><?php echo esc_html( $value ); ?></div>
			<div class="srk-sm-kpi-label"><?php echo esc_html( $label ); ?></div>
		</div>
		<?php
	}

	/**
	 * Get the locally cached engine and rules synchronization labels.
	 *
	 * @return array
	 */
	private function get_rules_status_labels() {
		if ( ! class_exists( 'SRK_Spam_Monitor_SERP_Provider' ) ) {
			return array(
				'engine' => __( 'Unavailable', 'seo-repair-kit' ),
				'rules'  => __( 'Not Synced', 'seo-repair-kit' ),
			);
		}

		$status = ( new SRK_Spam_Monitor_SERP_Provider() )->get_local_sync_status();

		return array(
			'engine' => ! empty( $status['checked_at'] ) ? __( 'Synced', 'seo-repair-kit' ) : __( 'Not Checked', 'seo-repair-kit' ),
			'rules'  => ! empty( $status['synced'] ) ? __( 'Synced', 'seo-repair-kit' ) : __( 'Not Synced', 'seo-repair-kit' ),
		);
	}
}
