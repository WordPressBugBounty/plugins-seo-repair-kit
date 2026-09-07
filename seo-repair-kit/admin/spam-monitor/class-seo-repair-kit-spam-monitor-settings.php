<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Production-focused Spam Monitor Settings tab.
 */
class SeoRepairKit_SpamMonitor_Settings {

	/**
	 * Render settings.
	 *
	 * @return void
	 */
	public function render() {
		if ( ! current_user_can( 'manage_options' ) ) {
			return;
		}

		$provider        = class_exists( 'SRK_Spam_Monitor_SERP_Provider' ) ? new SRK_Spam_Monitor_SERP_Provider() : null;
		$sync_status     = $provider ? $provider->get_local_sync_status() : array();
		$provider_status = $provider ? $provider->get_local_serp_provider_status() : array();
		$trial_status    = $provider ? $provider->get_local_trial_status() : array();
		$alerts          = $this->get_alert_settings();
		$scan_url        = admin_url( 'admin.php?page=seo-repair-kit-spam-monitor&tab=google-serp-scan' );
		$alerts_url      = admin_url( 'admin.php?page=seo-repair-kit-spam-monitor&tab=alerts' );
		$schedule        = class_exists( 'SRK_Spam_Monitor_Scheduler' ) ? SRK_Spam_Monitor_Scheduler::get_status() : array();

		/* pre-compute badge values */
		$cloud_connected   = true; /* always managed */
		$provider_connected = ! empty( $provider_status['connected'] );
		$alerts_enabled    = ! empty( $alerts['alerts_enabled'] );
		?>
		<div class="srk-stg-wrap">
			<div id="srk-sm-schedule-notice" class="srk-sm-schedule-notice" aria-live="polite" hidden></div>

			<!-- Page header -->
			<div class="srk-stg-header">
				<h2><?php esc_html_e( 'Spam Monitor Settings', 'seo-repair-kit' ); ?></h2>
				<p><?php esc_html_e( 'Configure automatic Spam Monitor scans, scheduled website testing, provider status, scan behavior, and alert delivery from one production-ready settings area.', 'seo-repair-kit' ); ?></p>
			</div>

			<!-- 2×2 status cards grid -->
			<!-- Automatic monitoring -->
			<?php $this->render_schedule_card( $schedule ); ?>

			<!-- Status cards grid -->
			<div class="srk-stg-cards-grid">

				<!-- SRK Cloud Status -->
				<div class="srk-sm-card srk-stg-card">
					<div class="srk-stg-card__head">
						<div class="srk-stg-card__icon srk-stg-card__icon--orange">
							<span class="dashicons dashicons-cloud"></span>
						</div>
						<h3><?php esc_html_e( 'SRK Cloud Status', 'seo-repair-kit' ); ?></h3>
						<span class="srk-stg-pill srk-stg-pill--connected"><?php esc_html_e( 'Connected', 'seo-repair-kit' ); ?></span>
					</div>
					<div class="srk-stg-card__rows">
						<?php foreach ( $this->get_cloud_rows( $sync_status, $provider_status, $trial_status ) as $label => $value ) : ?>
							<div class="srk-stg-row">
								<span class="srk-stg-row__label"><?php echo esc_html( $label ); ?></span>
								<span class="srk-stg-row__value"><?php echo wp_kses_post( $value ); ?></span>
							</div>
						<?php endforeach; ?>
					</div>
				</div>

				<!-- Default Scan Behavior -->
				<div class="srk-sm-card srk-stg-card">
					<div class="srk-stg-card__head">
						<div class="srk-stg-card__icon srk-stg-card__icon--blue">
							<span class="dashicons dashicons-controls-play"></span>
						</div>
						<h3><?php esc_html_e( 'Default Scan Behavior', 'seo-repair-kit' ); ?></h3>
					</div>
					<div class="srk-stg-card__rows">
						<?php foreach ( $this->get_scan_rows() as $label => $value ) : ?>
							<div class="srk-stg-row">
								<span class="srk-stg-row__label"><?php echo esc_html( $label ); ?></span>
								<span class="srk-stg-row__value"><?php echo wp_kses_post( $value ); ?></span>
							</div>
						<?php endforeach; ?>
					</div>
				</div>

				<!-- SERP Provider -->
				<div class="srk-sm-card srk-stg-card">
					<div class="srk-stg-card__head">
						<div class="srk-stg-card__icon srk-stg-card__icon--orange">
							<span class="dashicons dashicons-admin-settings"></span>
						</div>
						<h3><?php esc_html_e( 'SERP Provider', 'seo-repair-kit' ); ?></h3>
						<?php if ( $provider_connected ) : ?>
							<span class="srk-stg-pill srk-stg-pill--connected"><?php esc_html_e( 'Connected', 'seo-repair-kit' ); ?></span>
						<?php else : ?>
							<span class="srk-stg-pill srk-stg-pill--disconnected"><?php esc_html_e( 'Not Connected', 'seo-repair-kit' ); ?></span>
						<?php endif; ?>
					</div>
					<div class="srk-stg-card__rows">
						<?php foreach ( $this->get_provider_rows( $provider_status ) as $label => $value ) : ?>
							<div class="srk-stg-row">
								<span class="srk-stg-row__label"><?php echo esc_html( $label ); ?></span>
								<span class="srk-stg-row__value"><?php echo wp_kses_post( $value ); ?></span>
							</div>
						<?php endforeach; ?>
					</div>
				</div>

				<!-- Alerts Summary -->
				<div class="srk-sm-card srk-stg-card">
					<div class="srk-stg-card__head">
						<div class="srk-stg-card__icon srk-stg-card__icon--orange">
							<span class="dashicons dashicons-bell"></span>
						</div>
						<h3><?php esc_html_e( 'Alerts Summary', 'seo-repair-kit' ); ?></h3>
						<?php if ( $alerts_enabled ) : ?>
							<span class="srk-stg-pill srk-stg-pill--enabled"><?php esc_html_e( 'Enabled', 'seo-repair-kit' ); ?></span>
						<?php else : ?>
							<span class="srk-stg-pill srk-stg-pill--disabled"><?php esc_html_e( 'Disabled', 'seo-repair-kit' ); ?></span>
						<?php endif; ?>
					</div>
					<div class="srk-stg-card__rows">
						<?php foreach ( $this->get_alert_rows( $alerts ) as $label => $value ) : ?>
							<div class="srk-stg-row">
								<span class="srk-stg-row__label"><?php echo esc_html( $label ); ?></span>
								<span class="srk-stg-row__value"><?php echo wp_kses_post( $value ); ?></span>
							</div>
						<?php endforeach; ?>
					</div>
				</div>

			</div><!-- /.srk-stg-cards-grid -->

			<!-- CTA buttons row -->
			<div class="srk-stg-actions">
				<a class="button button-primary srk-stg-cta" href="<?php echo esc_url( $scan_url ); ?>">
					<span class="dashicons dashicons-external"></span>
					<?php esc_html_e( 'Open Google SERP Scan', 'seo-repair-kit' ); ?>
				</a>
				<a class="button srk-stg-cta" href="<?php echo esc_url( $alerts_url ); ?>">
					<span class="dashicons dashicons-external"></span>
					<?php esc_html_e( 'Open Alerts', 'seo-repair-kit' ); ?>
				</a>
			</div>

		</div><!-- /.srk-stg-wrap -->
		<?php
	}

	/**
	 * render_status_card() removed — cards are now inline in render() using .srk-stg-* classes.
	 *
	 * @deprecated replaced by direct card markup in render()
	 */

	/**
	 * Cloud summary rows.
	 *
	 * @param array $sync_status Sync status.
	 * @param array $provider_status Provider status.
	 * @param array $trial_status Trial status.
	 * @return array
	 */
	private function get_cloud_rows( array $sync_status, array $provider_status, array $trial_status ) {
		return array(
			__( 'Connection', 'seo-repair-kit' ) => esc_html__( 'Managed by SEO Repair Kit', 'seo-repair-kit' ),
			__( 'Rules Sync', 'seo-repair-kit' ) => ! empty( $sync_status['synced'] ) ? esc_html__( 'Synced', 'seo-repair-kit' ) : esc_html__( 'Not Synced', 'seo-repair-kit' ),
			__( 'Last Rules Sync', 'seo-repair-kit' ) => esc_html( $sync_status['last_synced_at'] ?? $sync_status['checked_at'] ?? '-' ),
			__( 'Free Trial Remaining', 'seo-repair-kit' ) => esc_html( $trial_status['remaining_requests'] ?? '-' ),
			__( 'Provider Connection', 'seo-repair-kit' ) => ! empty( $provider_status['connected'] ) ? esc_html__( 'Connected', 'seo-repair-kit' ) : esc_html__( 'Not Connected', 'seo-repair-kit' ),
		);
	}

	/**
	 * Scan summary rows.
	 *
	 * @return array
	 */
	private function get_scan_rows() {
		return array(
			__( 'Default Indexed Results', 'seo-repair-kit' ) => esc_html__( '10 results', 'seo-repair-kit' ),
			__( 'Estimated Requests', 'seo-repair-kit' ) => esc_html__( 'Calculated automatically from results selected', 'seo-repair-kit' ),
			__( 'Include Subdomains', 'seo-repair-kit' ) => esc_html__( 'Enabled by default', 'seo-repair-kit' ),
			__( 'Manual Request Limit', 'seo-repair-kit' ) => esc_html__( 'Hidden for production safety', 'seo-repair-kit' ),
		);
	}

	/**
	 * Provider summary rows.
	 *
	 * @param array $status Provider status.
	 * @return array
	 */
	private function get_provider_rows( array $status ) {
		$provider = sanitize_key( $status['provider'] ?? 'serper' );
		$labels   = array(
			'serper'     => __( 'Serper.dev (Recommended)', 'seo-repair-kit' ),
			'serpapi'    => __( 'SerpApi', 'seo-repair-kit' ),
			'dataforseo' => __( 'DataForSEO', 'seo-repair-kit' ),
		);

		return array(
			__( 'Recommended Provider', 'seo-repair-kit' ) => esc_html__( 'Serper.dev', 'seo-repair-kit' ),
			__( 'Current Provider', 'seo-repair-kit' ) => esc_html( $labels[ $provider ] ?? $labels['serper'] ),
			__( 'Provider Mode', 'seo-repair-kit' ) => esc_html( $status['provider_mode'] ?? 'internal_trial_key' ),
			__( 'Credentials', 'seo-repair-kit' ) => esc_html( $status['masked_credentials'] ?? $status['masked_key'] ?? '-' ),
			__( 'Last Tested', 'seo-repair-kit' ) => esc_html( $status['last_tested_at'] ?? '-' ),
		);
	}

	/**
	 * Alert summary rows.
	 *
	 * @param array $alerts Alert settings.
	 * @return array
	 */
	private function get_alert_rows( array $alerts ) {
		$recipients = array_filter( array_map( 'sanitize_email', (array) ( $alerts['recipient_emails'] ?? array() ) ), 'is_email' );
		$email      = implode( ', ', $recipients );
		if ( '' === $email ) {
			$email = get_option( 'admin_email' );
		}
		$levels = wp_parse_args( (array) ( $alerts['alert_risk_levels'] ?? array() ), array( 'clean' => 0, 'suspicious' => 1, 'spam' => 1, 'critical' => 1 ) );

		return array(
			__( 'Email Alerts', 'seo-repair-kit' ) => ! empty( $alerts['alerts_enabled'] ) ? esc_html__( 'Enabled', 'seo-repair-kit' ) : esc_html__( 'Disabled', 'seo-repair-kit' ),
			__( 'Alert Email', 'seo-repair-kit' ) => esc_html( $email ),
			__( 'Suspicious Alerts', 'seo-repair-kit' ) => ! empty( $levels['suspicious'] ) ? esc_html__( 'Enabled', 'seo-repair-kit' ) : esc_html__( 'Disabled', 'seo-repair-kit' ),
			__( 'Spam Alerts', 'seo-repair-kit' ) => ! empty( $levels['spam'] ) ? esc_html__( 'Enabled', 'seo-repair-kit' ) : esc_html__( 'Disabled', 'seo-repair-kit' ),
			__( 'Critical Alerts', 'seo-repair-kit' ) => ! empty( $levels['critical'] ) ? esc_html__( 'Enabled', 'seo-repair-kit' ) : esc_html__( 'Disabled', 'seo-repair-kit' ),
		);
	}

	/**
	 * Get alert settings with defaults.
	 *
	 * @return array
	 */
	private function get_alert_settings() {
		if ( class_exists( 'SRK_Spam_Monitor_Alerts' ) && method_exists( 'SRK_Spam_Monitor_Alerts', 'get_settings' ) ) {
			return SRK_Spam_Monitor_Alerts::get_settings();
		}

		if ( class_exists( 'SRK_Spam_Monitor_DB' ) && method_exists( 'SRK_Spam_Monitor_DB', 'get_alert_settings' ) ) {
			return SRK_Spam_Monitor_DB::get_alert_settings();
		}

		$settings = get_option( 'srk_spam_monitor_alert_settings', array() );
		return is_array( $settings ) ? $settings : array();
	}

	/**
	 * Render the simple scheduled-scan settings card.
	 *
	 * @param array $schedule Schedule status.
	 * @return void
	 */
	private function render_schedule_card( array $schedule ) {
		$frequency_labels = SRK_Spam_Monitor_Scheduler::get_frequency_labels();
		$scan_url = admin_url( 'admin.php?page=seo-repair-kit-spam-monitor&tab=google-serp-scan#srk-recent-serp-scans' );
		?>
		<section class="srk-sm-card srk-sm-schedule-card" aria-labelledby="srk-sm-schedule-title">
			<div class="srk-sm-schedule-card__header">
				<div>
					<div class="srk-sm-schedule-card__eyebrow"><?php esc_html_e( 'Automatic monitoring', 'seo-repair-kit' ); ?></div>
					<h3 id="srk-sm-schedule-title"><?php esc_html_e( 'Scheduled Spam Monitoring', 'seo-repair-kit' ); ?></h3>
					<p><?php esc_html_e( 'Run the existing Google SERP scan automatically using the same Spam Rules, provider, saved reports, and Alerts settings.', 'seo-repair-kit' ); ?></p>
				</div>
				<span class="srk-stg-pill <?php echo ! empty( $schedule['enabled'] ) ? 'srk-stg-pill--enabled' : 'srk-stg-pill--disabled'; ?>" data-schedule-field="enabled_label">
					<?php echo ! empty( $schedule['enabled'] ) ? esc_html__( 'Enabled', 'seo-repair-kit' ) : esc_html__( 'Disabled', 'seo-repair-kit' ); ?>
				</span>
			</div>

			<form id="srk-sm-schedule-form" class="srk-sm-schedule-form">
				<div class="srk-sm-schedule-grid">
					<div class="srk-sm-schedule-field srk-sm-schedule-field--toggle">
						<label class="srk-sm-toggle">
							<input type="checkbox" name="enabled" value="1" <?php checked( ! empty( $schedule['enabled'] ) ); ?>>
							<span class="srk-sm-toggle-slider" style="margin-top: 1px;"></span>
						</label>
						<div>
							<strong><?php esc_html_e( 'Enable Scheduled Scans', 'seo-repair-kit' ); ?></strong>
							<span><?php esc_html_e( 'Disabled by default. No provider requests are used until you enable and save it.', 'seo-repair-kit' ); ?></span>
						</div>
					</div>

					<label class="srk-sm-schedule-field">
						<span><?php esc_html_e( 'Frequency', 'seo-repair-kit' ); ?></span>
						<select name="frequency">
							<?php foreach ( $frequency_labels as $value => $label ) : ?>
								<option value="<?php echo esc_attr( $value ); ?>" <?php selected( $schedule['frequency'] ?? 'daily', $value ); ?>><?php echo esc_html( $label ); ?></option>
							<?php endforeach; ?>
						</select>
					</label>

					<label class="srk-sm-schedule-field">
						<span><?php esc_html_e( 'Scan Depth', 'seo-repair-kit' ); ?></span>
						<select name="serp_requests" id="srk-sm-schedule-depth">
							<option value="1" <?php selected( absint( $schedule['serp_requests'] ?? 3 ), 1 ); ?>><?php esc_html_e( '1 request — up to 10 records', 'seo-repair-kit' ); ?></option>
							<option value="3" <?php selected( absint( $schedule['serp_requests'] ?? 3 ), 3 ); ?>><?php esc_html_e( '3 requests — up to 30 records (Recommended)', 'seo-repair-kit' ); ?></option>
							<option value="5" <?php selected( absint( $schedule['serp_requests'] ?? 3 ), 5 ); ?>><?php esc_html_e( '5 requests — up to 50 records', 'seo-repair-kit' ); ?></option>
							<option value="10" <?php selected( absint( $schedule['serp_requests'] ?? 3 ), 10 ); ?>><?php esc_html_e( '10 requests — up to 100 records', 'seo-repair-kit' ); ?></option>
							<option value="100" <?php selected( absint( $schedule['serp_requests'] ?? 3 ), 100 ); ?>><?php esc_html_e( '100 requests — up to 1,000 records', 'seo-repair-kit' ); ?></option>
							<option value="200" <?php selected( absint( $schedule['serp_requests'] ?? 3 ), 200 ); ?>><?php esc_html_e( '200 requests — up to 2,000 records', 'seo-repair-kit' ); ?></option>
						</select>
					</label>

					<label class="srk-sm-schedule-field" id="srk-sm-schedule-run-time-field">
						<span><?php esc_html_e( 'Run Time', 'seo-repair-kit' ); ?></span>
						<input type="time" name="run_time" value="<?php echo esc_attr( $schedule['run_time'] ?? '03:00' ); ?>">
						<small data-default-timezone="<?php echo esc_attr( $schedule['timezone'] ?? 'UTC' ); ?>"><?php echo esc_html( $schedule['timezone'] ?? 'UTC' ); ?></small>
					</label>

					<div class="srk-sm-schedule-field srk-sm-schedule-field--toggle">
						<label class="srk-sm-toggle">
							<input type="checkbox" name="include_subdomains" value="1" <?php checked( ! empty( $schedule['include_subdomains'] ) ); ?>>
							<span class="srk-sm-toggle-slider" style="margin-top: 1px;"></span>
						</label>
						<div>
							<strong><?php esc_html_e( 'Include Subdomains', 'seo-repair-kit' ); ?></strong>
							<span><?php esc_html_e( 'Include indexed results from this site’s subdomains.', 'seo-repair-kit' ); ?></span>
						</div>
					</div>

					<div class="srk-sm-schedule-field srk-sm-schedule-field--toggle">
						<label class="srk-sm-toggle">
							<input type="checkbox" name="developer_mode" value="1" <?php checked( ! empty( $schedule['developer_mode'] ) ); ?>>
							<span class="srk-sm-toggle-slider" style="margin-top: 1px;"></span>
						</label>
						<div>
							<strong><?php esc_html_e( 'Developer testing mode', 'seo-repair-kit' ); ?></strong>
							<span><?php esc_html_e( 'Allow scheduled automation to scan a public test website while using this site’s Spam Rules and provider settings.', 'seo-repair-kit' ); ?></span>
						</div>
					</div>

					<label class="srk-sm-schedule-field" id="srk-sm-schedule-domain-field">
						<span><?php esc_html_e( 'Scheduled Website', 'seo-repair-kit' ); ?></span>
						<input type="text" name="domain" value="<?php echo esc_attr( $schedule['domain'] ?? home_url( '/' ) ); ?>" placeholder="https://example.com" <?php disabled( empty( $schedule['developer_mode'] ) ); ?>>
						<small><?php esc_html_e( 'Editable only in Developer testing mode. Normal scheduled scans use this WordPress site.', 'seo-repair-kit' ); ?></small>
					</label>
				</div>

				<div class="srk-sm-schedule-summary">
					<div><span><?php esc_html_e( 'Website', 'seo-repair-kit' ); ?></span><strong data-schedule-field="domain"><?php echo esc_html( $schedule['domain'] ?? home_url( '/' ) ); ?></strong></div>
					<div><span><?php esc_html_e( 'Next Run', 'seo-repair-kit' ); ?></span><strong data-schedule-field="next_run_display"><?php echo esc_html( $schedule['next_run_display'] ?? '-' ); ?></strong></div>
					<div><span><?php esc_html_e( 'Last Run', 'seo-repair-kit' ); ?></span><strong data-schedule-field="last_run_display"><?php echo esc_html( $schedule['last_run_display'] ?? '-' ); ?></strong></div>
					<div><span><?php esc_html_e( 'Last Status', 'seo-repair-kit' ); ?></span><strong data-schedule-field="last_status_label"><?php echo esc_html( $schedule['last_status_label'] ?? '-' ); ?></strong></div>
					<div><span><?php esc_html_e( 'Estimated Monthly Requests', 'seo-repair-kit' ); ?></span><strong data-schedule-field="estimated_monthly"><?php echo esc_html( absint( $schedule['estimated_monthly'] ?? 13 ) ); ?></strong></div>
					<div><span><?php esc_html_e( 'Last Scan', 'seo-repair-kit' ); ?></span><strong><a href="<?php echo esc_url( $scan_url ); ?>" data-schedule-field="last_scan_link"><?php echo ! empty( $schedule['last_scan_id'] ) ? esc_html( 'SCN-' . absint( $schedule['last_scan_id'] ) ) : esc_html__( 'No scheduled scan yet', 'seo-repair-kit' ); ?></a></strong></div>
				</div>

				<div class="srk-sm-schedule-help">
					<span class="dashicons dashicons-clock"></span>
					<p><?php esc_html_e( 'WordPress Cron runs due tasks on the next site request. The displayed time is the intended site-local run time and may be delayed on low-traffic websites.', 'seo-repair-kit' ); ?></p>
				</div>

				<div class="srk-sm-schedule-actions">
					<button type="submit" class="button button-primary" id="srk-sm-save-schedule"><?php esc_html_e( 'Save Schedule', 'seo-repair-kit' ); ?></button>
					<button type="button" class="button" id="srk-sm-run-schedule-now" <?php disabled( empty( $schedule['enabled'] ) ); ?>><?php esc_html_e( 'Run Now', 'seo-repair-kit' ); ?></button>
					<button type="button" class="button" id="srk-sm-reset-schedule"><?php esc_html_e( 'Reset Schedule Settings', 'seo-repair-kit' ); ?></button>
				</div>
			</form>
		</section>
		<?php
	}

}
