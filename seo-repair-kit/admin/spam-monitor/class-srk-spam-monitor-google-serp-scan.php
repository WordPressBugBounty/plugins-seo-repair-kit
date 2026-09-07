<?php
/**
 * Spam Monitor Google SERP Scan admin tab.
 *
 * @package Seo_Repair_Kit
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Renders and handles the Google SERP Scan demo tab.
 */
class SeoRepairKit_SpamMonitor_Google_SERP_Scan {

	const NONCE_ACTION = 'srk_sm_serp_nonce';

	/**
	 * Constructor.
	 */
	public function __construct() {
		add_action( 'wp_ajax_srk_spam_monitor_run_serp_scan', array( $this, 'ajax_run_scan' ) );
		add_action( 'wp_ajax_srk_spam_monitor_get_serp_scan_results', array( $this, 'ajax_get_scan_results' ) );
		add_action( 'wp_ajax_srk_spam_monitor_sync_serp_rules', array( $this, 'ajax_sync_rules' ) );
		add_action( 'wp_ajax_srk_spam_monitor_get_serp_rules_status', array( $this, 'ajax_get_rules_status' ) );
		add_action( 'wp_ajax_srk_spam_monitor_activate_serp_trial', array( $this, 'ajax_activate_trial' ) );
		add_action( 'wp_ajax_srk_spam_monitor_get_serp_trial_status', array( $this, 'ajax_get_trial_status' ) );
		add_action( 'wp_ajax_srk_spam_monitor_test_serp_provider', array( $this, 'ajax_test_serp_provider' ) );
		add_action( 'wp_ajax_srk_spam_monitor_save_serp_provider', array( $this, 'ajax_save_serp_provider' ) );
		add_action( 'wp_ajax_srk_spam_monitor_remove_serp_provider', array( $this, 'ajax_remove_serp_provider' ) );
		add_action( 'wp_ajax_srk_spam_monitor_get_serp_provider_status', array( $this, 'ajax_get_serp_provider_status' ) );
		add_action( 'wp_ajax_srk_spam_monitor_test_serpapi_key', array( $this, 'ajax_test_serpapi_key' ) );
		add_action( 'wp_ajax_srk_spam_monitor_save_serpapi_key', array( $this, 'ajax_save_serpapi_key' ) );
		add_action( 'wp_ajax_srk_spam_monitor_remove_serpapi_key', array( $this, 'ajax_remove_serpapi_key' ) );
		add_action( 'wp_ajax_srk_spam_monitor_get_serpapi_key_status', array( $this, 'ajax_get_serpapi_key_status' ) );
	}

	/**
	 * Render the tab.
	 *
	 * @return void
	 */
	public function render() {
		$home_host  = wp_parse_url( home_url(), PHP_URL_HOST );
		$domain     = $home_host ? preg_replace( '#^www\.#i', '', strtolower( $home_host ) ) : '';
		$recent_page  = SRK_Spam_Monitor_Admin_Pagination::get_page( 'srk_serp_scans_page' );
		$recent_per_page = SRK_Spam_Monitor_Admin_Pagination::get_per_page( 'srk_serp_scans_page' );
		$recent_filters = array(
			'domain'  => sanitize_text_field( wp_unslash( $_GET['srk_serp_filter_domain'] ?? '' ) ), // phpcs:ignore WordPress.Security.NonceVerification.Recommended
			'risk'    => sanitize_key( wp_unslash( $_GET['srk_serp_filter_risk'] ?? '' ) ), // phpcs:ignore WordPress.Security.NonceVerification.Recommended
			'scan_id' => sanitize_text_field( wp_unslash( $_GET['srk_serp_filter_id'] ?? '' ) ), // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		);
		$recent_total = class_exists( 'SRK_Spam_Monitor_DB' ) ? SRK_Spam_Monitor_DB::count_serp_scans( $recent_filters ) : 0;
		$recent       = class_exists( 'SRK_Spam_Monitor_DB' ) ? SRK_Spam_Monitor_DB::get_recent_serp_scans( $recent_per_page, SRK_Spam_Monitor_Admin_Pagination::get_offset( $recent_page, $recent_per_page ), $recent_filters ) : array();
		$provider   = new SRK_Spam_Monitor_SERP_Provider();
		$site_id    = $provider->get_site_id();
		$sync       = $provider->get_local_sync_status();
		$trial      = $provider->get_local_trial_status();
		$key_status = $provider->get_local_serp_provider_status();
		$schedule   = class_exists( 'SRK_Spam_Monitor_Scheduler' ) ? SRK_Spam_Monitor_Scheduler::get_status() : array();
		$results_export_url = wp_nonce_url( add_query_arg( array( 'action' => 'srk_sm_export_records', 'dataset' => 'serp_results' ), admin_url( 'admin-post.php' ) ), 'srk_sm_export_serp_results' );
		$scans_export_url   = wp_nonce_url( add_query_arg( array( 'action' => 'srk_sm_export_records', 'dataset' => 'serp_scans' ), admin_url( 'admin-post.php' ) ), 'srk_sm_export_serp_scans' );
		?>
		<div class="srk-sm-serp-wrap">

			<!-- Page header -->
			<div class="srk-serp-page-header">
				<h2><?php esc_html_e( 'Google SERP Scan', 'seo-repair-kit' ); ?></h2>
				<p><?php esc_html_e( 'Fetch indexed Google results, score them with synced Spam Rules, and store the findings for review.', 'seo-repair-kit' ); ?></p>
			</div>

			<!-- Status notice -->
			<div id="srk-serp-status" class="srk-sm-serp-status" aria-live="polite"></div>

			<?php $this->render_schedule_status( $schedule ); ?>

			<!-- Unified control panel -->
			<section class="srk-serp-control-panel" aria-labelledby="srk-serp-control-title">
				<div class="srk-serp-control-panel__head">
					<div>
						<h3 id="srk-serp-control-title"><?php esc_html_e( 'Scan Controls', 'seo-repair-kit' ); ?></h3>
						<p><?php esc_html_e( 'Confirm provider connectivity and available credits before scanning.', 'seo-repair-kit' ); ?></p>
					</div>
				</div>
				<div class="srk-serp-dashboard-grid">
					<div class="srk-serp-dashboard-main">
						<!-- Connection layer -->
						<?php $this->render_serpapi_key_card( $key_status ); ?>

				<!-- Scan form card -->
				<div class="srk-sm-card srk-serp-form-card">
					<div class="srk-serp-form-header">
						<div class="srk-serp-form-icon">
							<span class="dashicons dashicons-controls-play"></span>
						</div>
						<div>
							<h3><?php esc_html_e( 'Run Scan', 'seo-repair-kit' ); ?></h3>
							<p><?php esc_html_e( 'Configure and run a Google SERP scan for this domain.', 'seo-repair-kit' ); ?></p>
						</div>
					</div>

					<!-- Developer mode toggle -->
					<div class="srk-serp-devmode-row">
						<label class="srk-sm-toggle" style="flex-shrink:0;">
							<input type="checkbox" id="srk-serp-developer-mode" value="1">
							<span class="srk-sm-toggle-slider"></span>
						</label>
						<span class="srk-serp-devmode-label"><?php esc_html_e( 'Developer testing mode', 'seo-repair-kit' ); ?></span>
					</div>
					<div id="srk-serp-dev-mode-message" class="srk-sm-serp-dev-mode-message" data-site-id="<?php echo esc_attr( $site_id ); ?>" hidden>
						<p><?php esc_html_e( 'Developer testing mode is enabled. The current WordPress site trial and Spam Rules will be used to scan external public test domains.', 'seo-repair-kit' ); ?></p>
						<ul>
							<li><strong><?php esc_html_e( 'WordPress Site ID:', 'seo-repair-kit' ); ?></strong> <code data-field="site_id"><?php echo esc_html( $site_id ); ?></code></li>
							<li><strong><?php esc_html_e( 'Scan Domain:', 'seo-repair-kit' ); ?></strong> <code data-field="scan_domain"><?php echo esc_html( $domain ); ?></code></li>
							<li><strong><?php esc_html_e( 'Developer Testing Mode:', 'seo-repair-kit' ); ?></strong> <span data-field="developer_mode"><?php esc_html_e( 'Disabled', 'seo-repair-kit' ); ?></span></li>
						</ul>
					</div>

					<!-- Domain + Results inputs -->
					<div class="srk-serp-inputs-row">
						<div class="srk-serp-input-group">
							<label class="srk-serp-input-label" for="srk-serp-domain"><?php esc_html_e( 'Domain', 'seo-repair-kit' ); ?></label>
							<input type="text" id="srk-serp-domain" value="<?php echo esc_attr( $domain ); ?>" placeholder="example.com" class="srk-sm-text-input">
						</div>
						<div class="srk-serp-input-group">
							<label class="srk-serp-input-label" for="srk-serp-max-results"><?php esc_html_e( 'Indexed Results to Scan', 'seo-repair-kit' ); ?></label>
							<select id="srk-serp-max-results" class="srk-sm-text-input">
								<?php foreach ( array( 10, 20, 30, 40, 50, 60, 70, 80, 90, 100, 200, 300, 400, 500, 600, 700, 800, 900, 1000 ) as $value ) : ?>
									<option value="<?php echo esc_attr( $value ); ?>" <?php selected( 10, $value ); ?>><?php echo esc_html( $value ); ?></option>
								<?php endforeach; ?>
							</select>
						</div>
					</div>

					<!-- Estimate boxes -->
					<div class="srk-serp-estimates-row">
						<div class="srk-serp-estimate-box">
							<span class="srk-serp-estimate-label"><?php esc_html_e( 'Estimated SERP Requests', 'seo-repair-kit' ); ?></span>
							<strong id="srk-serp-estimated-requests" class="srk-serp-estimate-val">1</strong>
							<input type="hidden" id="srk-serp-max-requests" value="1">
						</div>
						<div class="srk-serp-estimate-box">
							<span class="srk-serp-estimate-label"><?php esc_html_e( 'Estimated Credits Usage', 'seo-repair-kit' ); ?></span>
							<strong id="srk-serp-estimated-credits" class="srk-serp-estimate-val">1</strong>
						</div>
					</div>

					<!-- Include subdomains toggle -->
					<div class="srk-serp-subdomains-row">
						<span><?php esc_html_e( 'Include Subdomains', 'seo-repair-kit' ); ?></span>
						<label class="srk-sm-toggle">
							<input type="checkbox" id="srk-serp-include-subdomains" value="1" checked>
							<span class="srk-sm-toggle-slider"></span>
						</label>
					</div>

					<!-- Tags + Run button row -->
					<div class="srk-serp-run-row">
						<div class="srk-serp-scan-tags">
							<span class="srk-serp-tag srk-serp-tag--neutral">
								<span class="dashicons dashicons-search"></span>
								<span id="srk-serp-scan-depth"><?php esc_html_e( 'Fast Scan', 'seo-repair-kit' ); ?></span>
							</span>
							<span class="srk-serp-tag srk-serp-tag--neutral"><?php esc_html_e( '1 credit / SERP request', 'seo-repair-kit' ); ?></span>
							<span class="srk-serp-tag srk-serp-tag--warn"><?php esc_html_e( 'Rules must be synced first', 'seo-repair-kit' ); ?></span>
						</div>
						<button type="button" id="srk-serp-run-scan" class="button button-primary srk-serp-run-btn">
							<span class="dashicons dashicons-controls-play"></span>
							<?php esc_html_e( 'Run Scan', 'seo-repair-kit' ); ?>
						</button>
					</div>
				</div><!-- /.srk-serp-form-card -->

					</div><!-- /.srk-serp-dashboard-main -->

					<aside class="srk-serp-dashboard-utility" aria-label="<?php esc_attr_e( 'Scan status and credits', 'seo-repair-kit' ); ?>">
						<!-- Connection status -->
						<?php $this->render_rules_sync_card( $sync ); ?>
						<!-- Credits / trial -->
						<?php $this->render_trial_card( $trial ); ?>
					</aside>
				</div><!-- /.srk-serp-dashboard-grid -->
			</section><!-- /.srk-serp-control-panel -->

			<!-- Scan summary (injected by JS after scan) -->
			<div id="srk-serp-summary" class="srk-sm-serp-summary-grid" hidden></div>

			<!-- Returned Google SERP Records table -->
			<div class="srk-sm-card srk-serp-results-card">
				<div class="srk-dash-table-card__header">
					<div>
						<h3><?php esc_html_e( 'Returned Google SERP Records', 'seo-repair-kit' ); ?></h3>
						<p><?php esc_html_e( 'Live results from the most recent scan', 'seo-repair-kit' ); ?></p>
					</div>
					<div class="srk-dash-table-card__toolbar">
						<a class="srk-dash-filter-btn srk-sm-record-export" href="<?php echo esc_url( $results_export_url ); ?>"><span class="dashicons dashicons-download"></span><?php esc_html_e( 'Export CSV', 'seo-repair-kit' ); ?></a>
						<button type="button" class="srk-dash-filter-btn srk-sm-clear-records" data-dataset="serp"><span class="dashicons dashicons-trash"></span><?php esc_html_e( 'Clear SERP Data', 'seo-repair-kit' ); ?></button>
					</div>
				</div>
				<div class="srk-sm-table-scroll">
					<table class="srk-dash-url-table" id="srk-serp-results-table">
						<thead>
							<tr>
								<th style="width:36px;">#</th>
								<th><?php esc_html_e( 'URL', 'seo-repair-kit' ); ?></th>
								<th><?php esc_html_e( 'Google Title', 'seo-repair-kit' ); ?></th>
								<th><?php esc_html_e( 'Snippet', 'seo-repair-kit' ); ?></th>
								<th><?php esc_html_e( 'Risk', 'seo-repair-kit' ); ?></th>
								<th><?php esc_html_e( 'Score', 'seo-repair-kit' ); ?></th>
								<th><?php esc_html_e( 'Issues', 'seo-repair-kit' ); ?></th>
								<th><?php esc_html_e( 'Action', 'seo-repair-kit' ); ?></th>
							</tr>
						</thead>
						<tbody>
							<tr><td colspan="8" class="srk-dash-url-table__empty">
								<span class="dashicons dashicons-search"></span>
								<?php esc_html_e( 'Run a scan to display Google SERP records.', 'seo-repair-kit' ); ?>
							</td></tr>
						</tbody>
					</table>
				</div>
				<div id="srk-serp-results-pagination"></div>
			</div>

			<!-- Recent SERP Scans table -->
			<div class="srk-sm-card srk-serp-recent-card" id="srk-recent-serp-scans">
				<div class="srk-dash-table-card__header" style="margin-bottom:14px;">
					<div>
						<h3><?php esc_html_e( 'Recent SERP Scans', 'seo-repair-kit' ); ?></h3>
						<p><?php esc_html_e( 'Scan history with filters', 'seo-repair-kit' ); ?></p>
					</div>
					<form class="srk-dash-table-card__toolbar srk-serp-history-filters" method="get" action="<?php echo esc_url( admin_url( 'admin.php' ) ); ?>">
						<input type="hidden" name="page" value="seo-repair-kit-spam-monitor">
						<input type="hidden" name="tab" value="google-serp-scan">
						<input type="hidden" name="srk_serp_scans_per_page" value="<?php echo esc_attr( $recent_per_page ); ?>">
						<input type="text" name="srk_serp_filter_domain" value="<?php echo esc_attr( $recent_filters['domain'] ); ?>" class="srk-dash-search" id="srk-recent-filter-domain" placeholder="<?php esc_attr_e( 'Domain', 'seo-repair-kit' ); ?>">
						<select name="srk_serp_filter_risk" class="srk-dash-search" id="srk-recent-filter-risk">
							<option value=""><?php esc_html_e( 'All risk', 'seo-repair-kit' ); ?></option>
							<option value="clean" <?php selected( $recent_filters['risk'], 'clean' ); ?>><?php esc_html_e( 'Clean', 'seo-repair-kit' ); ?></option>
							<option value="suspicious" <?php selected( $recent_filters['risk'], 'suspicious' ); ?>><?php esc_html_e( 'Suspicious', 'seo-repair-kit' ); ?></option>
							<option value="spam" <?php selected( $recent_filters['risk'], 'spam' ); ?>><?php esc_html_e( 'Spam', 'seo-repair-kit' ); ?></option>
							<option value="critical" <?php selected( $recent_filters['risk'], 'critical' ); ?>><?php esc_html_e( 'Critical', 'seo-repair-kit' ); ?></option>
						</select>
						<input type="text" name="srk_serp_filter_id" value="<?php echo esc_attr( $recent_filters['scan_id'] ); ?>" class="srk-dash-search" id="srk-recent-filter-id" placeholder="<?php esc_attr_e( 'Scan ID', 'seo-repair-kit' ); ?>">
						<button type="submit" class="srk-dash-filter-btn"><span class="dashicons dashicons-filter"></span><?php esc_html_e( 'Filter', 'seo-repair-kit' ); ?></button>
						<a class="srk-dash-filter-btn srk-sm-record-export" href="<?php echo esc_url( $scans_export_url ); ?>"><span class="dashicons dashicons-download"></span><?php esc_html_e( 'Export CSV', 'seo-repair-kit' ); ?></a>
						<button type="button" class="srk-dash-filter-btn srk-sm-clear-records" data-dataset="serp"><span class="dashicons dashicons-trash"></span><?php esc_html_e( 'Clear SERP Data', 'seo-repair-kit' ); ?></button>
						<?php if ( array_filter( $recent_filters ) ) : ?>
							<a class="srk-dash-filter-btn" href="<?php echo esc_url( admin_url( 'admin.php?page=seo-repair-kit-spam-monitor&tab=google-serp-scan#srk-recent-serp-scans' ) ); ?>"><?php esc_html_e( 'Clear', 'seo-repair-kit' ); ?></a>
						<?php endif; ?>
					</form>
				</div>
				<div class="srk-sm-table-scroll">
					<table class="srk-dash-url-table" id="srk-serp-recent-table">
						<thead>
							<tr>
								<th><?php esc_html_e( 'Scan ID', 'seo-repair-kit' ); ?></th>
								<th><?php esc_html_e( 'Domain', 'seo-repair-kit' ); ?></th>
								<th><?php esc_html_e( 'Source', 'seo-repair-kit' ); ?></th>
								<th><?php esc_html_e( 'Results', 'seo-repair-kit' ); ?></th>
								<th><?php esc_html_e( 'Requests', 'seo-repair-kit' ); ?></th>
								<th><?php esc_html_e( 'Risk Score', 'seo-repair-kit' ); ?></th>
								<th><?php esc_html_e( 'Date', 'seo-repair-kit' ); ?></th>
								<th><?php esc_html_e( 'Action', 'seo-repair-kit' ); ?></th>
							</tr>
						</thead>
						<tbody>
							<?php $this->render_recent_rows( $recent ); ?>
						</tbody>
					</table>
				</div>
				<?php SRK_Spam_Monitor_Admin_Pagination::render( 'srk_serp_scans_page', $recent_page, $recent_total, 'srk-recent-serp-scans', 'google-serp-scan', $recent_per_page, array( 'srk_serp_filter_domain' => $recent_filters['domain'], 'srk_serp_filter_risk' => $recent_filters['risk'], 'srk_serp_filter_id' => $recent_filters['scan_id'] ) ); ?>
			</div>

		</div><!-- /.srk-sm-serp-wrap -->
		<?php
	}

	/**
	 * Run scan AJAX handler.
	 *
	 * @return void
	 */
	public function ajax_run_scan() {
		check_ajax_referer( self::NONCE_ACTION, 'nonce' );

		if ( ! current_user_can( 'manage_options' ) ) {
			wp_send_json_error( array( 'message' => __( 'Permission denied.', 'seo-repair-kit' ) ), 403 );
		}

		$raw_max_results       = absint( $_POST['max_results'] ?? 100 );
		$raw_max_serp_requests = absint( $_POST['max_serp_requests'] ?? 1 );

		$allowed_results = array( 10, 20, 30, 40, 50, 60, 70, 80, 90, 100, 200, 300, 400, 500, 600, 700, 800, 900, 1000 );
		if ( ! in_array( $raw_max_results, $allowed_results, true ) ) {
			wp_send_json_error( array( 'message' => __( 'Please select a valid indexed results amount.', 'seo-repair-kit' ) ), 400 );
		}

		$expected_serp_requests = (int) ceil( $raw_max_results / 10 );
		if ( $raw_max_serp_requests !== $expected_serp_requests ) {
			$raw_max_serp_requests = $expected_serp_requests;
		}

		if ( $raw_max_serp_requests > 100 || $raw_max_results > 1000 ) {
			wp_send_json_error( array( 'message' => __( 'The selected request limit is too high.', 'seo-repair-kit' ) ), 400 );
		}

		$max_results       = max( 10, $raw_max_results );
		$max_serp_requests = max( 1, $raw_max_serp_requests );
		$recent_per_page   = absint( $_POST['recent_per_page'] ?? 10 );
		$recent_per_page   = in_array( $recent_per_page, array( 10, 20, 30, 50, 100 ), true ) ? $recent_per_page : 10;

		$args     = array(
			'domain'             => sanitize_text_field( wp_unslash( $_POST['domain'] ?? '' ) ),
			'max_results'        => $max_results,
			'max_serp_requests'  => $max_serp_requests,
			'include_subdomains' => ! empty( $_POST['include_subdomains'] ),
			'developer_mode'     => ! empty( $_POST['developer_mode'] ),
		);

		$service = new SRK_Spam_Monitor_Scan_Service();
		$scan    = $service->run_scan( $args, 'manual' );
		if ( is_wp_error( $scan ) ) {
			wp_send_json_error( array( 'message' => $scan->get_error_message(), 'code' => $scan->get_error_code() ), 500 );
		}

		$scan_id = absint( $scan['scan_id'] );
		$result  = $scan['result'];

		wp_send_json_success(
			array(
				'message'      => __( 'Scan completed and saved successfully.', 'seo-repair-kit' ),
				'scan_id'      => $scan_id,
				'result'       => $result,
				'recent_scans' => SRK_Spam_Monitor_DB::get_recent_serp_scans( $recent_per_page ),
			)
		);
	}

	/**
	 * Sync Spam Rules to Python.
	 *
	 * @return void
	 */
	public function ajax_sync_rules() {
		check_ajax_referer( self::NONCE_ACTION, 'nonce' );

		if ( ! current_user_can( 'manage_options' ) ) {
			wp_send_json_error( array( 'message' => __( 'Permission denied.', 'seo-repair-kit' ) ), 403 );
		}

		$provider = new SRK_Spam_Monitor_SERP_Provider();
		$status   = $provider->sync_rules();

		if ( is_wp_error( $status ) ) {
			wp_send_json_error( array( 'message' => $status->get_error_message(), 'code' => $status->get_error_code() ), 500 );
		}

		wp_send_json_success( $status );
	}

	/**
	 * Get Spam Rules sync status from Python.
	 *
	 * @return void
	 */
	public function ajax_get_rules_status() {
		check_ajax_referer( self::NONCE_ACTION, 'nonce' );

		if ( ! current_user_can( 'manage_options' ) ) {
			wp_send_json_error( array( 'message' => __( 'Permission denied.', 'seo-repair-kit' ) ), 403 );
		}

		$provider = new SRK_Spam_Monitor_SERP_Provider();
		$status   = $provider->get_rules_status();

		if ( is_wp_error( $status ) ) {
			wp_send_json_error( array( 'message' => $status->get_error_message(), 'code' => $status->get_error_code() ), 500 );
		}

		wp_send_json_success( $status );
	}

	/**
	 * Activate one-click free trial.
	 *
	 * @return void
	 */
	public function ajax_activate_trial() {
		check_ajax_referer( self::NONCE_ACTION, 'nonce' );

		if ( ! current_user_can( 'manage_options' ) ) {
			wp_send_json_error( array( 'message' => __( 'Permission denied.', 'seo-repair-kit' ) ), 403 );
		}

		$provider = new SRK_Spam_Monitor_SERP_Provider();
		$status   = $provider->activate_trial();

		if ( is_wp_error( $status ) ) {
			wp_send_json_error( array( 'message' => $status->get_error_message(), 'code' => $status->get_error_code() ), 500 );
		}

		wp_send_json_success( $status );
	}

	/**
	 * Refresh free trial status from Python.
	 *
	 * @return void
	 */
	public function ajax_get_trial_status() {
		check_ajax_referer( self::NONCE_ACTION, 'nonce' );

		if ( ! current_user_can( 'manage_options' ) ) {
			wp_send_json_error( array( 'message' => __( 'Permission denied.', 'seo-repair-kit' ) ), 403 );
		}

		$provider = new SRK_Spam_Monitor_SERP_Provider();
		$status   = $provider->get_trial_status();

		if ( is_wp_error( $status ) ) {
			wp_send_json_error( array( 'message' => $status->get_error_message(), 'code' => $status->get_error_code() ), 500 );
		}

		wp_send_json_success( $status );
	}

	/**
	 * Test user-owned SERP provider credentials without storing them in WordPress.
	 *
	 * @return void
	 */
	public function ajax_test_serp_provider() {
		check_ajax_referer( self::NONCE_ACTION, 'nonce' );

		if ( ! current_user_can( 'manage_options' ) ) {
			wp_send_json_error( array( 'message' => __( 'Permission denied.', 'seo-repair-kit' ) ), 403 );
		}
		if ( ! $this->can_manage_serp_provider_credentials() ) {
			$this->send_serp_provider_locked_response();
		}

		$provider_slug = sanitize_key( wp_unslash( $_POST['provider'] ?? 'serpapi' ) );
		$credentials   = $this->get_provider_credentials_from_request( $provider_slug );
		$provider      = new SRK_Spam_Monitor_SERP_Provider();
		$status        = $provider->test_serp_provider( $provider_slug, $credentials );

		if ( is_wp_error( $status ) ) {
			wp_send_json_error( array( 'message' => $status->get_error_message(), 'code' => $status->get_error_code() ), 500 );
		}

		wp_send_json_success( $status );
	}

	/**
	 * Save validated user-owned SERP provider credentials in SRK Cloud.
	 *
	 * @return void
	 */
	public function ajax_save_serp_provider() {
		check_ajax_referer( self::NONCE_ACTION, 'nonce' );

		if ( ! current_user_can( 'manage_options' ) ) {
			wp_send_json_error( array( 'message' => __( 'Permission denied.', 'seo-repair-kit' ) ), 403 );
		}
		if ( ! $this->can_manage_serp_provider_credentials() ) {
			$this->send_serp_provider_locked_response();
		}

		$provider_slug = sanitize_key( wp_unslash( $_POST['provider'] ?? 'serpapi' ) );
		$credentials   = $this->get_provider_credentials_from_request( $provider_slug );
		$provider      = new SRK_Spam_Monitor_SERP_Provider();
		$status        = $provider->save_serp_provider( $provider_slug, $credentials );

		if ( is_wp_error( $status ) ) {
			wp_send_json_error( array( 'message' => $status->get_error_message(), 'code' => $status->get_error_code() ), 500 );
		}

		wp_send_json_success( $status );
	}

	/**
	 * Remove user-owned SERP provider credentials from SRK Cloud.
	 *
	 * @return void
	 */
	public function ajax_remove_serp_provider() {
		check_ajax_referer( self::NONCE_ACTION, 'nonce' );

		if ( ! current_user_can( 'manage_options' ) ) {
			wp_send_json_error( array( 'message' => __( 'Permission denied.', 'seo-repair-kit' ) ), 403 );
		}
		if ( ! $this->can_manage_serp_provider_credentials() ) {
			$this->send_serp_provider_locked_response();
		}

		$provider = new SRK_Spam_Monitor_SERP_Provider();
		$status   = $provider->remove_serp_provider();

		if ( is_wp_error( $status ) ) {
			wp_send_json_error( array( 'message' => $status->get_error_message(), 'code' => $status->get_error_code() ), 500 );
		}

		wp_send_json_success( $status );
	}

	/**
	 * Refresh user-owned SERP provider status from SRK Cloud.
	 *
	 * @return void
	 */
	public function ajax_get_serp_provider_status() {
		check_ajax_referer( self::NONCE_ACTION, 'nonce' );

		if ( ! current_user_can( 'manage_options' ) ) {
			wp_send_json_error( array( 'message' => __( 'Permission denied.', 'seo-repair-kit' ) ), 403 );
		}

		$provider = new SRK_Spam_Monitor_SERP_Provider();
		$status   = $provider->get_serp_provider_status();

		if ( is_wp_error( $status ) ) {
			wp_send_json_error( array( 'message' => $status->get_error_message(), 'code' => $status->get_error_code() ), 500 );
		}

		wp_send_json_success( $status );
	}

	/**
	 * Test a user-owned SERP provider key without storing it in WordPress.
	 *
	 * @return void
	 */
	public function ajax_test_serpapi_key() {
		check_ajax_referer( self::NONCE_ACTION, 'nonce' );

		if ( ! current_user_can( 'manage_options' ) ) {
			wp_send_json_error( array( 'message' => __( 'Permission denied.', 'seo-repair-kit' ) ), 403 );
		}
		if ( ! $this->can_manage_serp_provider_credentials() ) {
			$this->send_serp_provider_locked_response();
		}

		$api_key  = sanitize_text_field( wp_unslash( $_POST['api_key'] ?? '' ) );
		$provider = new SRK_Spam_Monitor_SERP_Provider();
		$status   = $provider->test_serpapi_key( $api_key );

		if ( is_wp_error( $status ) ) {
			wp_send_json_error( array( 'message' => $status->get_error_message(), 'code' => $status->get_error_code() ), 500 );
		}

		wp_send_json_success( $status );
	}

	/**
	 * Save validated user-owned SERP provider credentials in SRK Cloud.
	 *
	 * @return void
	 */
	public function ajax_save_serpapi_key() {
		check_ajax_referer( self::NONCE_ACTION, 'nonce' );

		if ( ! current_user_can( 'manage_options' ) ) {
			wp_send_json_error( array( 'message' => __( 'Permission denied.', 'seo-repair-kit' ) ), 403 );
		}
		if ( ! $this->can_manage_serp_provider_credentials() ) {
			$this->send_serp_provider_locked_response();
		}

		$api_key  = sanitize_text_field( wp_unslash( $_POST['api_key'] ?? '' ) );
		$provider = new SRK_Spam_Monitor_SERP_Provider();
		$status   = $provider->save_serpapi_key( $api_key );

		if ( is_wp_error( $status ) ) {
			wp_send_json_error( array( 'message' => $status->get_error_message(), 'code' => $status->get_error_code() ), 500 );
		}

		wp_send_json_success( $status );
	}

	/**
	 * Remove user-owned SERP provider credentials from SRK Cloud.
	 *
	 * @return void
	 */
	public function ajax_remove_serpapi_key() {
		check_ajax_referer( self::NONCE_ACTION, 'nonce' );

		if ( ! current_user_can( 'manage_options' ) ) {
			wp_send_json_error( array( 'message' => __( 'Permission denied.', 'seo-repair-kit' ) ), 403 );
		}
		if ( ! $this->can_manage_serp_provider_credentials() ) {
			$this->send_serp_provider_locked_response();
		}

		$provider = new SRK_Spam_Monitor_SERP_Provider();
		$status   = $provider->remove_serpapi_key();

		if ( is_wp_error( $status ) ) {
			wp_send_json_error( array( 'message' => $status->get_error_message(), 'code' => $status->get_error_code() ), 500 );
		}

		wp_send_json_success( $status );
	}

	/**
	 * Refresh user-owned SERP provider status from SRK Cloud.
	 *
	 * @return void
	 */
	public function ajax_get_serpapi_key_status() {
		check_ajax_referer( self::NONCE_ACTION, 'nonce' );

		if ( ! current_user_can( 'manage_options' ) ) {
			wp_send_json_error( array( 'message' => __( 'Permission denied.', 'seo-repair-kit' ) ), 403 );
		}

		$provider = new SRK_Spam_Monitor_SERP_Provider();
		$status   = $provider->get_serpapi_key_status();

		if ( is_wp_error( $status ) ) {
			wp_send_json_error( array( 'message' => $status->get_error_message(), 'code' => $status->get_error_code() ), 500 );
		}

		wp_send_json_success( $status );
	}

	/**
	 * Get stored scan results AJAX handler.
	 *
	 * @return void
	 */
	public function ajax_get_scan_results() {
		check_ajax_referer( self::NONCE_ACTION, 'nonce' );

		if ( ! current_user_can( 'manage_options' ) ) {
			wp_send_json_error( array( 'message' => __( 'Permission denied.', 'seo-repair-kit' ) ), 403 );
		}

		$scan_id = absint( $_POST['scan_id'] ?? 0 );
		if ( ! $scan_id ) {
			wp_send_json_error( array( 'message' => __( 'Invalid scan ID.', 'seo-repair-kit' ) ), 400 );
		}

		$page = max( 1, absint( $_POST['page'] ?? 1 ) );
		$per_page = absint( $_POST['per_page'] ?? 10 );
		$per_page = in_array( $per_page, array( 10, 20, 30, 50, 100 ), true ) ? $per_page : 10;
		$data = SRK_Spam_Monitor_DB::get_serp_scan_results( $scan_id, $per_page, ( $page - 1 ) * $per_page );
		if ( ! $data ) {
			wp_send_json_error( array( 'message' => __( 'SERP scan not found.', 'seo-repair-kit' ) ), 404 );
		}

		$data['pagination'] = array(
			'page'        => $page,
			'per_page'    => $per_page,
			'total'       => absint( $data['total'] ?? 0 ),
			'total_pages' => (int) ceil( absint( $data['total'] ?? 0 ) / $per_page ),
		);
		wp_send_json_success( $data );
	}

	/**
	 * Render recent scans rows.
	 *
	 * @param array $recent Recent scans.
	 * @return void
	 */
	private function render_recent_rows( array $recent ) {
		if ( empty( $recent ) ) {
			echo '<tr><td colspan="8" class="srk-dash-url-table__empty"><span class="dashicons dashicons-chart-bar"></span>' . esc_html__( 'No SERP scans saved yet.', 'seo-repair-kit' ) . '</td></tr>';
			return;
		}
		foreach ( $recent as $scan ) {
			$score = absint( $scan['overall_risk_score'] ?? 0 );
			$score_class = $score >= 81 ? 'srk-serp-score--critical' : ( $score >= 61 ? 'srk-serp-score--spam' : ( $score > 30 ? 'srk-serp-score--suspicious' : '' ) );
			$source = 'scheduled' === sanitize_key( $scan['scan_source'] ?? 'manual' ) ? 'scheduled' : 'manual';
			?>
			<tr data-scan-id="<?php echo esc_attr( absint( $scan['id'] ?? 0 ) ); ?>">
				<td style="font-weight:700;color:var(--srk-sm-muted);">SCN-<?php echo esc_html( absint( $scan['id'] ?? 0 ) ); ?></td>
				<td><?php echo esc_html( $scan['domain'] ?? '' ); ?></td>
				<td><span class="srk-sm-source-badge srk-sm-source-badge--<?php echo esc_attr( $source ); ?>"><?php echo esc_html( ucfirst( $source ) ); ?></span></td>
				<td><?php echo esc_html( absint( $scan['received_results'] ?? 0 ) ); ?></td>
				<td><?php echo esc_html( absint( $scan['serp_requests_used'] ?? 0 ) ); ?></td>
				<td><span class="srk-serp-score <?php echo esc_attr( $score_class ); ?>"><?php echo esc_html( $score ); ?></span></td>
				<td class="srk-dash-url-table__date"><?php echo esc_html( $scan['created_at'] ?? '' ); ?></td>
				<td>
					<button type="button" class="srk-dash-action-btn srk-dash-action-btn--ghost srk-serp-view-scan" data-scan-id="<?php echo esc_attr( absint( $scan['id'] ?? 0 ) ); ?>"><?php esc_html_e( 'View', 'seo-repair-kit' ); ?></button>
				</td>
			</tr>
			<?php
		}
	}

	/**
	 * Render compact automatic-scan status.
	 *
	 * @param array $schedule Schedule status.
	 * @return void
	 */
	private function render_schedule_status( array $schedule ) {
		$settings_url = admin_url( 'admin.php?page=seo-repair-kit-spam-monitor&tab=settings#srk-sm-schedule-title' );
		?>
		<div class="srk-sm-schedule-compact">
			<div>
				<strong><?php esc_html_e( 'Automatic Scanning', 'seo-repair-kit' ); ?></strong>
				<div class="srk-sm-schedule-compact__details">
					<span><?php echo ! empty( $schedule['enabled'] ) ? esc_html__( 'Enabled', 'seo-repair-kit' ) : esc_html__( 'Disabled', 'seo-repair-kit' ); ?></span>
					<span><?php echo esc_html( $schedule['frequency_label'] ?? __( 'Every day', 'seo-repair-kit' ) ); ?></span>
					<span><?php echo esc_html( sprintf( __( '%1$d requests / up to %2$d records', 'seo-repair-kit' ), absint( $schedule['serp_requests'] ?? 3 ), absint( $schedule['max_results'] ?? 30 ) ) ); ?></span>
					<span><?php echo esc_html( sprintf( __( 'Next: %s', 'seo-repair-kit' ), $schedule['next_run_display'] ?? '-' ) ); ?></span>
				</div>
			</div>
			<a class="button" href="<?php echo esc_url( $settings_url ); ?>"><?php esc_html_e( 'Manage Schedule', 'seo-repair-kit' ); ?></a>
		</div>
		<?php
	}

	/**
	 * Render rules sync status card.
	 *
	 * @param array $sync Local sync status.
	 * @return void
	 */
	private function render_rules_sync_card( array $sync ) {
		$synced       = ! empty( $sync['synced'] );
		$hash_matches = ! array_key_exists( 'hash_matches', $sync ) || ! empty( $sync['hash_matches'] );
		$ok           = $synced && $hash_matches;
		$label        = $ok ? __( 'Synced', 'seo-repair-kit' ) : __( 'Not Synced', 'seo-repair-kit' );
		$pill_class   = $ok ? 'srk-serp-status-pill--synced' : 'srk-serp-status-pill--unsynced';
		?>
		<div class="srk-sm-card srk-serp-status-card">
			<div class="srk-serp-status-card__head">
				<div class="srk-serp-status-card__title">
					<span class="dashicons dashicons-shield-alt" style="color:var(--srk-sm-muted);"></span>
					<?php esc_html_e( 'Spam Rules Sync', 'seo-repair-kit' ); ?>
				</div>
				<span class="srk-serp-status-pill <?php echo esc_attr( $pill_class ); ?>" id="srk-serp-rules-sync-status" data-status="<?php echo $ok ? 'is-synced' : 'is-not-synced'; ?>">
					<span data-field="status"><?php echo esc_html( $label ); ?></span>
				</span>
			</div>
			<div class="srk-serp-status-card__meta">
				<div><span><?php esc_html_e( 'Last Synced', 'seo-repair-kit' ); ?></span><strong data-field="last_synced_at"><?php echo esc_html( $sync['last_synced_at'] ?? '-' ); ?></strong></div>
				<?php if ( defined( 'WP_DEBUG' ) && WP_DEBUG ) : ?>
					<div><span><?php esc_html_e( 'Rules Hash', 'seo-repair-kit' ); ?></span><code data-field="rules_hash"><?php echo esc_html( $sync['rules_hash'] ?? '-' ); ?></code></div>
				<?php endif; ?>
			</div>
			<div class="srk-serp-status-card__actions">
				<button type="button" class="button" id="srk-serp-refresh-rules-status"><?php esc_html_e( 'Refresh', 'seo-repair-kit' ); ?></button>
				<button type="button" class="button button-primary" id="srk-serp-sync-rules"><?php esc_html_e( 'Sync Now', 'seo-repair-kit' ); ?></button>
			</div>
		</div>
		<?php
	}

	/**
	 * Render free trial status card.
	 *
	 * @param array $trial Local trial status.
	 * @return void
	 */
	private function render_trial_card( array $trial ) {
		$active    = ! empty( $trial['trial_active'] );
		$label     = $active ? __( 'Active', 'seo-repair-kit' ) : __( 'Available', 'seo-repair-kit' );
		$pill_cls  = $active ? 'srk-serp-status-pill--synced' : 'srk-serp-status-pill--neutral';
		$allocated = absint( $trial['allocated_requests'] ?? $trial['trial_requests'] ?? 5 );
		$used      = absint( $trial['used_requests'] ?? 0 );
		$remaining = absint( $trial['remaining_requests'] ?? ( $active ? max( 0, $allocated - $used ) : $allocated ) );
		?>
		<div class="srk-sm-card srk-serp-status-card">
			<div class="srk-serp-status-card__head">
				<div class="srk-serp-status-card__title">
					<span class="dashicons dashicons-star-filled" style="color:var(--srk-sm-warn);"></span>
					<?php esc_html_e( 'Free Trial', 'seo-repair-kit' ); ?>
				</div>
				<span class="srk-serp-status-pill <?php echo esc_attr( $pill_cls ); ?>" id="srk-serp-trial-status" data-status="<?php echo $active ? 'is-synced' : 'is-not-synced'; ?>">
					<span data-field="status"><?php echo esc_html( $label ); ?></span>
				</span>
			</div>
			<div class="srk-serp-trial-usage">
				<span class="srk-serp-trial-big" data-field="remaining_requests"><?php echo esc_html( $remaining ); ?></span>
				<span class="srk-serp-trial-of">/ <?php echo esc_html( $allocated ); ?></span>
			</div>
			<div class="srk-serp-trial-bar-wrap">
				<div class="srk-serp-trial-bar">
					<div class="srk-serp-trial-bar__fill" style="width:<?php echo esc_attr( $allocated > 0 ? round( ( $used / $allocated ) * 100 ) : 0 ); ?>%;"></div>
				</div>
				<span class="srk-serp-trial-used">used <span data-field="used_requests"><?php echo esc_html( $used ); ?></span></span>
			</div>
			<p class="srk-sm-field-help" data-field="message"><?php esc_html_e( 'Activate for 5 free SERP requests. No registration required.', 'seo-repair-kit' ); ?></p>
			<div class="srk-serp-status-card__actions">
				<button type="button" class="button" id="srk-serp-refresh-trial-status"><?php esc_html_e( 'Refresh Trial', 'seo-repair-kit' ); ?></button>
				<button type="button" class="button button-primary" id="srk-serp-activate-trial"><?php esc_html_e( 'Activate Trial', 'seo-repair-kit' ); ?></button>
			</div>
		</div>
		<?php
	}

	/**
	 * Render user-owned SERP provider connection card.
	 *
	 * @param array $status Local provider status.
	 * @return void
	 */
	private function render_serpapi_key_card( array $status ) {
		$can_manage       = $this->can_manage_serp_provider_credentials();
		$connected        = $can_manage && ! empty( $status['connected'] );
		$pill_cls         = $connected ? 'srk-serp-status-pill--synced' : 'srk-serp-status-pill--unsynced';
		$label            = $connected ? __( 'Connected', 'seo-repair-kit' ) : __( 'Not Connected', 'seo-repair-kit' );
		$current_provider = $can_manage ? sanitize_key( $status['provider'] ?? 'serper' ) : 'serper';
		$provider_mode    = $can_manage ? ( $status['provider_mode'] ?? 'internal_trial_key' ) : 'internal_trial_key';
		$subscribe_url    = admin_url( 'admin.php?page=seo-repair-kit-upgrade-pro' );
		if ( class_exists( 'SRK_API_Client' ) ) {
			$subscribe_url = SRK_API_Client::get_api_url(
				SRK_API_Client::ENDPOINT_SUBSCRIBE,
				array( 'domain' => site_url() )
			);
		}
		?>
		<div class="srk-sm-card srk-serp-status-card">
			<div class="srk-serp-status-card__head">
				<div class="srk-serp-status-card__title">
					<span class="dashicons dashicons-admin-plugins" style="color:var(--srk-sm-muted);"></span>
					<?php esc_html_e( 'SERP Provider', 'seo-repair-kit' ); ?>
				</div>
				<span class="srk-serp-status-pill <?php echo esc_attr( $pill_cls ); ?>" id="srk-serpapi-key-status" data-status="<?php echo $connected ? 'is-synced' : 'is-not-synced'; ?>">
					<span data-field="status"><?php echo esc_html( $label ); ?></span>
				</span>
			</div>
			<div class="srk-serp-status-card__meta">
				<div><span><?php esc_html_e( 'Provider', 'seo-repair-kit' ); ?></span><strong data-field="provider"><?php echo esc_html( $this->get_provider_label( $current_provider ) ); ?></strong></div>
				<div><span><?php esc_html_e( 'Mode', 'seo-repair-kit' ); ?></span><span data-field="provider_mode"><?php echo esc_html( $provider_mode ); ?></span></div>
				<div><span><?php esc_html_e( 'Last Tested', 'seo-repair-kit' ); ?></span><span data-field="last_tested_at"><?php echo esc_html( $can_manage ? ( $status['last_tested_at'] ?? '-' ) : '-' ); ?></span></div>
			</div>

			<?php if ( ! $can_manage ) : ?>
				<div class="srk-serp-provider-lock-notice">
					<span class="dashicons dashicons-lock"></span>
					<div>
						<strong><?php esc_html_e( 'Free trial provider is active.', 'seo-repair-kit' ); ?></strong>
						<p><?php esc_html_e( 'Free users can scan with the SEO Repair Kit trial provider. Add the Spam Monitor module to connect your own SERP provider API key.', 'seo-repair-kit' ); ?></p>
						<div class="srk-serp-provider-education" aria-label="<?php esc_attr_e( 'Supported SERP providers', 'seo-repair-kit' ); ?>">
							<span><?php esc_html_e( 'Supported providers:', 'seo-repair-kit' ); ?></span>
							<em><?php esc_html_e( 'Serper.dev', 'seo-repair-kit' ); ?></em>
							<em><?php esc_html_e( 'SERP API', 'seo-repair-kit' ); ?></em>
							<em><?php esc_html_e( 'Data for SEO', 'seo-repair-kit' ); ?></em>
						</div>
						<a class="button button-primary srk-serp-provider-lock-cta" target="_blank" rel="noopener noreferrer" href="<?php echo esc_url( $subscribe_url ); ?>">
							<?php esc_html_e( 'Buy Spam Monitor Module', 'seo-repair-kit' ); ?>
						</a>
					</div>
				</div>
			<?php endif; ?>

			<!-- Provider selector + key input -->
			<div class="srk-serp-provider-select-row">
				<select id="srk-serp-provider-select" class="srk-sm-text-input" style="font-size:12px;min-height:34px;" <?php disabled( ! $can_manage ); ?>>
					<option value="serper" <?php selected( $current_provider, 'serper' ); ?>><?php esc_html_e( 'Serper.dev (Recommended)', 'seo-repair-kit' ); ?></option>
					<option value="serpapi" <?php selected( $current_provider, 'serpapi' ); ?>><?php esc_html_e( 'SerpApi', 'seo-repair-kit' ); ?></option>
					<option value="dataforseo" <?php selected( $current_provider, 'dataforseo' ); ?>><?php esc_html_e( 'DataForSEO', 'seo-repair-kit' ); ?></option>
				</select>
			</div>
			<div class="srk-serp-provider-fields" data-provider-fieldset="api_key">
				<input type="password" id="srk-serpapi-key-input" class="srk-sm-text-input" style="font-size:12px;min-height:34px;" autocomplete="off" placeholder="<?php esc_attr_e( 'Enter provider API key', 'seo-repair-kit' ); ?>" <?php disabled( ! $can_manage ); ?>>
			</div>
			<div class="srk-serp-provider-fields" data-provider-fieldset="dataforseo" hidden>
				<input type="text" id="srk-serp-provider-login" class="srk-sm-text-input" style="font-size:12px;min-height:34px;margin-bottom:6px;" autocomplete="off" placeholder="<?php esc_attr_e( 'DataForSEO login/email', 'seo-repair-kit' ); ?>" <?php disabled( ! $can_manage ); ?>>
				<input type="password" id="srk-serp-provider-password" class="srk-sm-text-input" style="font-size:12px;min-height:34px;" autocomplete="off" placeholder="<?php esc_attr_e( 'DataForSEO API password', 'seo-repair-kit' ); ?>" <?php disabled( ! $can_manage ); ?>>
			</div>
			<?php foreach ( array( 'serpapi' => 'https://serpapi.com/', 'serper' => 'https://serper.dev/', 'dataforseo' => 'https://dataforseo.com/' ) as $prov => $url ) : ?>
				<p class="srk-serp-provider-guide" data-provider-guide="<?php echo esc_attr( $prov ); ?>" <?php echo $prov !== $current_provider ? 'hidden' : ''; ?>>
					<a href="<?php echo esc_url( $url ); ?>" target="_blank" rel="noopener noreferrer"><?php echo esc_html( $this->get_provider_label( $prov ) ); ?> ↗</a>
				</p>
			<?php endforeach; ?>
			<div class="srk-serp-status-card__actions">
				<button type="button" class="button" id="srk-serpapi-test-key" <?php disabled( ! $can_manage ); ?>><?php esc_html_e( 'Test', 'seo-repair-kit' ); ?></button>
				<button type="button" class="button button-primary" id="srk-serpapi-save-key" <?php disabled( ! $can_manage ); ?>><?php esc_html_e( 'Save', 'seo-repair-kit' ); ?></button>
				<button type="button" class="button" id="srk-serpapi-remove-key" <?php disabled( ! $can_manage ); ?>><?php esc_html_e( 'Remove', 'seo-repair-kit' ); ?></button>
			</div>
		</div>
		<?php
	}

	/**
	 * Check whether this site can manage user-owned SERP provider credentials.
	 *
	 * @return bool
	 */
	private function can_manage_serp_provider_credentials() {
		return class_exists( 'SRK_License_Helper' ) && SRK_License_Helper::is_spam_monitor_enabled();
	}

	/**
	 * Send a consistent response for locked provider credential actions.
	 *
	 * @return void
	 */
	private function send_serp_provider_locked_response() {
		wp_send_json_error(
			array(
				'message' => __( 'Custom SERP provider keys require the paid Spam Monitor module. Free users can continue with the SEO Repair Kit trial provider.', 'seo-repair-kit' ),
				'code'    => 'srk_serp_provider_paid_required',
			),
			403
		);
	}

	/**
	 * Build provider credentials from the current AJAX request.
	 *
	 * @param string $provider Provider slug.
	 * @return array
	 */
	private function get_provider_credentials_from_request( $provider ) {
		if ( 'dataforseo' === $provider ) {
			return array(
				'login'    => sanitize_text_field( wp_unslash( $_POST['login'] ?? '' ) ),
				'password' => sanitize_text_field( wp_unslash( $_POST['password'] ?? '' ) ),
			);
		}

		return array(
			'api_key' => sanitize_text_field( wp_unslash( $_POST['api_key'] ?? '' ) ),
		);
	}

	/**
	 * Human label for supported SERP providers.
	 *
	 * @param string $provider Provider slug.
	 * @return string
	 */
	private function get_provider_label( $provider ) {
		$labels = array(
			'serpapi'    => __( 'SerpApi', 'seo-repair-kit' ),
			'dataforseo' => __( 'DataForSEO', 'seo-repair-kit' ),
			'serper'     => __( 'Serper.dev', 'seo-repair-kit' ),
		);

		return $labels[ $provider ] ?? $labels['serpapi'];
	}

}
