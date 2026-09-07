<?php
/**
 * Spam Monitor Search Console Cleanup tab.
 *
 * @package Seo_Repair_Kit
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Renders a simple indexed-spam cleanup workflow.
 */
class SeoRepairKit_SpamMonitor_GSC_Cleanup {

	const NONCE_ACTION = 'srk_sm_serp_nonce';

	/**
	 * Constructor.
	 */
	public function __construct() {
		add_action( 'wp_ajax_srk_sm_cleanup_update_status', array( $this, 'ajax_update_status' ) );
		add_action( 'wp_ajax_srk_sm_cleanup_analyze_url', array( $this, 'ajax_analyze_url' ) );
	}

	/**
	 * Render the tab.
	 *
	 * @return void
	 */
	public function render() {
		$candidate_page  = SRK_Spam_Monitor_Admin_Pagination::get_page( 'srk_cleanup_page' );
		$candidate_per_page = SRK_Spam_Monitor_Admin_Pagination::get_per_page( 'srk_cleanup_page' );
		$candidate_total = class_exists( 'SRK_Spam_Monitor_DB' ) ? SRK_Spam_Monitor_DB::count_gsc_cleanup_candidates() : 0;
		$candidates = class_exists( 'SRK_Spam_Monitor_DB' ) ? SRK_Spam_Monitor_DB::get_gsc_cleanup_candidates( $candidate_per_page, SRK_Spam_Monitor_Admin_Pagination::get_offset( $candidate_page, $candidate_per_page ) ) : array();
		$service    = $this->get_service();
		$summary    = class_exists( 'SRK_Spam_Monitor_DB' ) ? SRK_Spam_Monitor_DB::get_gsc_cleanup_candidate_summary() : $this->summarize_candidates( $candidates );
		$url_list   = implode( "\n", $this->get_candidate_urls( $candidates ) );
		$property   = home_url( '/' );
		$force_sitemap_refresh = isset( $_GET['srk_sm_refresh_sitemap'] ) && '1' === sanitize_text_field( wp_unslash( $_GET['srk_sm_refresh_sitemap'] ) );
		$sitemap    = $service->get_sitemap_health( home_url( '/' ), $force_sitemap_refresh );
		$gsc_links  = $service->get_gsc_links( $property );
		$results_export_url = wp_nonce_url( add_query_arg( array( 'action' => 'srk_sm_export_records', 'dataset' => 'serp_results' ), admin_url( 'admin-post.php' ) ), 'srk_sm_export_serp_results' );
		?>
		<div class="srk-gsc-wrap">

			<!-- Page header -->
			<div class="srk-gsc-page-header">
				<h2><?php esc_html_e( 'Search Console Cleanup', 'seo-repair-kit' ); ?></h2>
				<p><?php esc_html_e( 'Review spam found in Google, clean the website, update the sitemap, then monitor Google until the result is gone or clean.', 'seo-repair-kit' ); ?></p>
			</div>

			<div id="srk-gsc-cleanup-notices" class="srk-sm-notices" style="display:none;" aria-live="polite"></div>

			<!-- KPI row -->
			<div class="srk-gsc-kpi-row">
				<div class="srk-gsc-kpi">
					<div class="srk-gsc-kpi__head">
						<span class="srk-gsc-kpi__label"><?php esc_html_e( 'Cleanup Queue', 'seo-repair-kit' ); ?></span>
						<div class="srk-gsc-kpi__icon"><span class="dashicons dashicons-list-view"></span></div>
					</div>
					<div class="srk-gsc-kpi__value"><?php echo esc_html( number_format_i18n( $candidate_total ) ); ?></div>
				</div>
				<div class="srk-gsc-kpi srk-gsc-kpi--critical">
					<span class="srk-gsc-kpi__label"><?php esc_html_e( 'Critical', 'seo-repair-kit' ); ?></span>
					<div class="srk-gsc-kpi__value"><?php echo esc_html( number_format_i18n( $summary['critical'] ) ); ?></div>
				</div>
				<div class="srk-gsc-kpi srk-gsc-kpi--spam">
					<span class="srk-gsc-kpi__label"><?php esc_html_e( 'Spam', 'seo-repair-kit' ); ?></span>
					<div class="srk-gsc-kpi__value"><?php echo esc_html( number_format_i18n( $summary['spam'] ) ); ?></div>
				</div>
				<div class="srk-gsc-kpi srk-gsc-kpi--resolved">
					<span class="srk-gsc-kpi__label"><?php esc_html_e( 'Resolved', 'seo-repair-kit' ); ?></span>
					<div class="srk-gsc-kpi__value"><?php echo esc_html( number_format_i18n( $summary['resolved'] ) ); ?></div>
				</div>
				<div class="srk-gsc-kpi srk-gsc-kpi--sitemap">
					<span class="srk-gsc-kpi__label"><?php esc_html_e( 'In Sitemap', 'seo-repair-kit' ); ?></span>
					<div class="srk-gsc-kpi__value"><?php echo esc_html( number_format_i18n( $summary['sitemap_issues'] ) ); ?></div>
				</div>
			</div>

			<!-- Middle row: steps + sitemap health -->
			<div class="srk-gsc-middle-row">
				<?php $this->render_simple_steps(); ?>
				<?php $this->render_sitemap_health( $sitemap ); ?>
			</div>

			<!-- Cleanup Actions bar -->
			<div class="srk-sm-card srk-gsc-actions-bar">
				<div class="srk-gsc-actions-bar__text">
					<h3><span class="dashicons dashicons-admin-site-alt3" style="vertical-align:middle;margin-right:6px;color:var(--srk-sm-warn);"></span><?php esc_html_e( 'Cleanup Actions', 'seo-repair-kit' ); ?></h3>
					<p><?php esc_html_e( 'Quick links to Google Search Console tools', 'seo-repair-kit' ); ?></p>
				</div>
				<div class="srk-gsc-actions-btns">
					<button type="button" class="button button-primary" id="srk-gsc-copy-urls" data-target="#srk-gsc-url-list">
						<span class="dashicons dashicons-clipboard"></span>
						<?php esc_html_e( 'Copy URL List', 'seo-repair-kit' ); ?>
					</button>
					<a class="button" href="<?php echo esc_url( $gsc_links['removals'] ); ?>" target="_blank" rel="noopener noreferrer">
						<span class="dashicons dashicons-external"></span>
						<?php esc_html_e( 'Open Removals', 'seo-repair-kit' ); ?>
					</a>
					<a class="button" href="<?php echo esc_url( $gsc_links['inspection'] ); ?>" target="_blank" rel="noopener noreferrer">
						<span class="dashicons dashicons-external"></span>
						<?php esc_html_e( 'Open Inspection', 'seo-repair-kit' ); ?>
					</a>
					<a class="button" href="<?php echo esc_url( $gsc_links['sitemaps'] ); ?>" target="_blank" rel="noopener noreferrer">
						<span class="dashicons dashicons-external"></span>
						<?php esc_html_e( 'Open Sitemaps', 'seo-repair-kit' ); ?>
					</a>
				</div>
			</div>

			<!-- URL List textarea -->
			<div class="srk-sm-card srk-gsc-url-card">
				<div class="srk-gsc-url-card__head">
					<div>
						<h3><?php esc_html_e( 'URL List', 'seo-repair-kit' ); ?></h3>
						<p><?php esc_html_e( 'Selected risky URLs to clean or submit', 'seo-repair-kit' ); ?></p>
					</div>
					<button type="button" class="button" id="srk-gsc-copy-urls-btn" data-target="#srk-gsc-url-list" onclick="document.getElementById('srk-gsc-copy-urls').click();">
						<span class="dashicons dashicons-clipboard"></span>
						<?php esc_html_e( 'Copy', 'seo-repair-kit' ); ?>
					</button>
				</div>
				<textarea id="srk-gsc-url-list" class="srk-gsc-url-textarea" readonly><?php echo esc_textarea( $url_list ); ?></textarea>
				<div class="srk-gsc-url-note">
					<strong><?php esc_html_e( 'Note:', 'seo-repair-kit' ); ?></strong>
					<?php esc_html_e( 'Only submit URLs after review. Fake spam URLs should return 404/410. Real pages should be cleaned before reindex requests.', 'seo-repair-kit' ); ?>
				</div>
			</div>

			<!-- Spam URL Review card grid -->
			<div class="srk-sm-card srk-gsc-review-card" id="srk-spam-url-review">
				<div class="srk-gsc-review-card__head">
					<h3><?php esc_html_e( 'Spam URL Review', 'seo-repair-kit' ); ?></h3>
					<div class="srk-dash-table-card__toolbar">
						<a class="srk-dash-filter-btn srk-sm-record-export" href="<?php echo esc_url( $results_export_url ); ?>"><span class="dashicons dashicons-download"></span><?php esc_html_e( 'Export CSV', 'seo-repair-kit' ); ?></a>
						<button type="button" class="srk-dash-filter-btn srk-sm-clear-records" data-dataset="serp"><span class="dashicons dashicons-trash"></span><?php esc_html_e( 'Clear SERP Data', 'seo-repair-kit' ); ?></button>
					</div>
				</div>
				<?php $this->render_candidate_cards( $candidates, $property, $service ); ?>
				<?php SRK_Spam_Monitor_Admin_Pagination::render( 'srk_cleanup_page', $candidate_page, $candidate_total, 'srk-spam-url-review', 'gsc-cleanup', $candidate_per_page ); ?>
			</div>

		</div><!-- /.srk-gsc-wrap -->
		<?php
	}

	/**
	 * AJAX: update cleanup status.
	 *
	 * @return void
	 */
	public function ajax_update_status() {
		$this->verify_ajax();

		$result_id = absint( $_POST['result_id'] ?? 0 );
		$status    = $this->normalize_status( sanitize_text_field( wp_unslash( $_POST['status'] ?? '' ) ) );
		if ( ! in_array( $status, $this->get_statuses(), true ) ) {
			wp_send_json_error( array( 'message' => __( 'Invalid cleanup status.', 'seo-repair-kit' ) ), 400 );
		}

		$updated = SRK_Spam_Monitor_DB::update_cleanup_result( $result_id, array( 'cleanup_status' => $status ) );
		if ( 'Resolved' === $status ) {
			$this->maybe_send_cleanup_alert( $result_id, 'cleanup_completed' );
		}

		wp_send_json_success( array( 'updated' => (bool) $updated, 'message' => __( 'Cleanup status updated.', 'seo-repair-kit' ) ) );
	}

	/**
	 * AJAX: analyze URL and sitemap presence.
	 *
	 * @return void
	 */
	public function ajax_analyze_url() {
		$this->verify_ajax();

		$result_id = absint( $_POST['result_id'] ?? 0 );
		$row       = SRK_Spam_Monitor_DB::get_serp_result( $result_id );
		if ( ! $row || empty( $row['url'] ) ) {
			wp_send_json_error( array( 'message' => __( 'Cleanup item not found.', 'seo-repair-kit' ) ), 404 );
		}

		$analysis = $this->get_service()->analyze_url( $row['url'] );
		SRK_Spam_Monitor_DB::update_cleanup_result(
			$result_id,
			array(
				'http_status'        => $analysis['http_status'],
				'redirect_status'    => $analysis['redirect_status'],
				'url_exists'         => $analysis['url_exists'] ? 1 : 0,
				'canonical_url'      => esc_url_raw( $analysis['canonical_url'] ),
				'sitemap_url'        => esc_url_raw( $analysis['sitemap_url'] ),
				'in_sitemap'         => $analysis['in_sitemap'] ? 1 : 0,
				'sitemap_checked_at' => $analysis['sitemap_checked_at'],
			)
		);

		if ( ! empty( $analysis['in_sitemap'] ) ) {
			$this->maybe_send_cleanup_alert( $result_id, 'sitemap_issue' );
		}

		wp_send_json_success( array( 'analysis' => $analysis, 'message' => __( 'URL checked. Refresh the tab to see the latest analysis values.', 'seo-repair-kit' ) ) );
	}

	/**
	 * Verify AJAX request.
	 *
	 * @return void
	 */
	private function verify_ajax() {
		check_ajax_referer( self::NONCE_ACTION, 'nonce' );
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_send_json_error( array( 'message' => __( 'Permission denied.', 'seo-repair-kit' ) ), 403 );
		}
		if ( ! class_exists( 'SRK_Spam_Monitor_DB' ) ) {
			wp_send_json_error( array( 'message' => __( 'Database helper is not available.', 'seo-repair-kit' ) ), 500 );
		}
	}

	/**
	 * Render simple recovery steps.
	 *
	 * @return void
	 */
	private function render_simple_steps() {
		?>
		<div class="srk-sm-card srk-gsc-status-card">
			<div class="srk-gsc-status-card__head">
				<div class="srk-gsc-status-card__icon"><span class="dashicons dashicons-info"></span></div>
				<div>
					<h3><?php esc_html_e( 'Cleanup Status', 'seo-repair-kit' ); ?></h3>
					<p><?php esc_html_e( 'Clean the real page, or make fake spam URLs return 404/410.', 'seo-repair-kit' ); ?></p>
				</div>
			</div>
			<div class="srk-gsc-steps">
				<div class="srk-gsc-step">
					<div class="srk-gsc-step__num">1</div>
					<div class="srk-gsc-step__text"><?php esc_html_e( 'Review the risky Google result and confirm whether it is real spam.', 'seo-repair-kit' ); ?></div>
				</div>
				<div class="srk-gsc-step">
					<div class="srk-gsc-step__num">2</div>
					<div class="srk-gsc-step__text"><?php esc_html_e( 'Update the sitemap, then use Search Console Inspection, Removals, or Sitemaps as needed.', 'seo-repair-kit' ); ?></div>
				</div>
				<div class="srk-gsc-step">
					<div class="srk-gsc-step__num">3</div>
					<div class="srk-gsc-step__text"><?php esc_html_e( 'Run SERP Scan again later and mark the item Resolved only when Google is clean.', 'seo-repair-kit' ); ?></div>
				</div>
				<div class="srk-gsc-step">
					<div class="srk-gsc-step__num">4</div>
					<div class="srk-gsc-step__text"><?php esc_html_e( 'If the URL is a real page, remove injected content, fix the title/snippet source, and request reindexing after cleanup.', 'seo-repair-kit' ); ?></div>
				</div>
				<div class="srk-gsc-step">
					<div class="srk-gsc-step__num">5</div>
					<div class="srk-gsc-step__text"><?php esc_html_e( 'If the URL is fake spam, keep it returning 404/410 and avoid redirecting it to important pages.', 'seo-repair-kit' ); ?></div>
				</div>
			</div>
		</div>
		<?php
	}

	/**
	 * Render sitemap health.
	 *
	 * @param array $sitemap Sitemap data.
	 * @return void
	 */
	private function render_sitemap_health( array $sitemap ) {
		?>
		<div class="srk-sm-card srk-gsc-sitemap-card">
			<div class="srk-gsc-sitemap-card__head">
				<h3><?php esc_html_e( 'Sitemap Health', 'seo-repair-kit' ); ?></h3>
				<button type="button" class="button" id="srk-gsc-refresh-sitemap-health">
					<span class="dashicons dashicons-update"></span>
					<?php esc_html_e( 'Refresh', 'seo-repair-kit' ); ?>
				</button>
			</div>
			<div class="srk-gsc-sitemap-rows">
				<div class="srk-gsc-sitemap-row">
					<span class="srk-gsc-sitemap-row__label"><?php esc_html_e( 'Sitemap', 'seo-repair-kit' ); ?></span>
					<span class="srk-gsc-sitemap-row__value">
						<?php if ( ! empty( $sitemap['sitemap_url'] ) ) : ?>
							<a href="<?php echo esc_url( $sitemap['sitemap_url'] ); ?>" target="_blank" rel="noopener noreferrer"><?php echo esc_html( $sitemap['sitemap_url'] ); ?></a>
						<?php else : ?>—<?php endif; ?>
					</span>
				</div>
				<div class="srk-gsc-sitemap-row">
					<span class="srk-gsc-sitemap-row__label"><?php esc_html_e( 'Last Checked', 'seo-repair-kit' ); ?></span>
					<span class="srk-gsc-sitemap-row__value"><?php echo esc_html( $sitemap['last_checked'] ?? '—' ); ?></span>
				</div>
				<div class="srk-gsc-sitemap-row">
					<span class="srk-gsc-sitemap-row__label"><?php esc_html_e( 'Reachable', 'seo-repair-kit' ); ?></span>
					<span class="srk-gsc-sitemap-row__value">
						<?php
						if ( null === ( $sitemap['reachable'] ?? null ) ) { echo '—'; }
						elseif ( ! empty( $sitemap['reachable'] ) ) { echo '<span style="color:var(--srk-sm-accent);font-weight:700;">✓ Yes</span>'; }
						else { echo '<span style="color:var(--srk-sm-err);font-weight:700;">✗ No</span>'; }
						?>
					</span>
				</div>
				<div class="srk-gsc-sitemap-row">
					<span class="srk-gsc-sitemap-row__label"><?php esc_html_e( 'Robots.txt', 'seo-repair-kit' ); ?></span>
					<span class="srk-gsc-sitemap-row__value"><?php echo esc_html( absint( $sitemap['robots_status'] ?? 0 ) ?: '—' ); ?></span>
				</div>
				<?php if ( ! empty( $sitemap['message'] ) ) : ?>
					<div class="srk-gsc-sitemap-row">
						<span class="srk-gsc-sitemap-row__label"><?php esc_html_e( 'Note', 'seo-repair-kit' ); ?></span>
						<span class="srk-gsc-sitemap-row__value"><?php echo esc_html( $sitemap['message'] ); ?></span>
					</div>
				<?php endif; ?>
			</div>
		</div>
		<?php
	}

	/**
	 * Render candidate rows.
	 *
	 * @param array                      $candidates Candidates.
	 * @param string                     $property GSC property.
	 * @param SRK_Search_Console_Service $service Service.
	 * @return void
	 */
	/**
	 * Render candidate URL cards (2-column grid layout).
	 *
	 * @param array                      $candidates Candidates.
	 * @param string                     $property GSC property.
	 * @param SRK_Search_Console_Service $service Service.
	 * @return void
	 */
	private function render_candidate_cards( array $candidates, $property, $service ) {
		if ( empty( $candidates ) ) {
			echo '<div class="srk-gsc-empty"><span class="dashicons dashicons-yes-alt"></span>' . esc_html__( 'No Spam or Critical SERP URLs found yet. Run a Google SERP Scan first.', 'seo-repair-kit' ) . '</div>';
			return;
		}
		echo '<div class="srk-gsc-url-grid">';
		foreach ( $candidates as $row ) {
			$url = esc_url_raw( $row['url'] ?? '' );
			if ( '' === $url ) { continue; }

			$inspection_url = $service->get_inspection_url( $property, $url );
			$search_url     = 'https://www.google.com/search?q=' . rawurlencode( 'site:' . $url );
			$status         = $this->normalize_status( $row['cleanup_status'] ?? 'Detected' );
			$result_id      = absint( $row['id'] ?? 0 );
			$recommendation = $service->get_recommendation_from_row( $row );
			$needs_check    = null === ( $row['url_exists'] ?? null ) || 0 === absint( $row['http_status'] ?? 0 );
			$risk           = sanitize_key( $row['risk_level'] ?? 'clean' );
			$score          = absint( $row['risk_score'] ?? 0 );
			$detected       = $row['first_seen_at'] ?? ( $row['created_at'] ?? '' );
			$http_val       = absint( $row['http_status'] ?? 0 ) ?: '-';
			$url_state      = $this->format_url_state( $row );
			$sitemap_state  = $this->format_sitemap_state( $row );
			/* colour for URL status */
			$url_cls = 'live' === strtolower( $url_state ) ? 'srk-gsc-status-group__val--live' : ( 'removed' === strtolower( $url_state ) ? 'srk-gsc-status-group__val--danger' : '' );
			$sitemap_cls = 'yes' === strtolower( $sitemap_state ) ? 'srk-gsc-status-group__val--warn' : '';
			?>
			<div class="srk-gsc-url-item" data-cleanup-result-id="<?php echo esc_attr( $result_id ); ?>" data-needs-check="<?php echo esc_attr( $needs_check ? '1' : '0' ); ?>">

				<!-- Risk badge + score + detected -->
				<div class="srk-gsc-url-item__meta">
					<span class="srk-dash-risk-badge srk-dash-risk-badge--<?php echo esc_attr( $risk ); ?>"><?php echo esc_html( ucfirst( $risk ) ); ?></span>
					<span class="srk-gsc-url-item__score"><?php esc_html_e( 'Score', 'seo-repair-kit' ); ?> <?php echo esc_html( $score ); ?></span>
					<?php if ( $detected ) : ?>
						<span class="srk-gsc-url-item__detected"><?php esc_html_e( 'detected', 'seo-repair-kit' ); ?> <?php echo esc_html( $detected ); ?></span>
					<?php endif; ?>
				</div>

				<!-- Google title + snippet -->
				<div>
					<div class="srk-gsc-url-item__title"><?php echo esc_html( wp_trim_words( $row['google_title'] ?? '', 10, '...' ) ); ?></div>
					<div class="srk-gsc-url-item__snippet"><?php echo esc_html( wp_trim_words( $row['google_snippet'] ?? '', 18, '...' ) ); ?></div>
				</div>

				<!-- URL + domain -->
				<div>
					<a class="srk-gsc-url-item__url" href="<?php echo esc_url( $url ); ?>" target="_blank" rel="noopener noreferrer"><?php echo esc_html( $url ); ?></a>
					<div class="srk-gsc-url-item__domain"><?php echo esc_html( $row['domain'] ?? '' ); ?></div>
				</div>

				<!-- URL / HTTP / Sitemap status row -->
				<div class="srk-gsc-url-item__status-row">
					<div class="srk-gsc-status-group">
						<span class="srk-gsc-status-group__key"><?php esc_html_e( 'URL Status', 'seo-repair-kit' ); ?></span>
						<span class="srk-gsc-status-group__val <?php echo esc_attr( $url_cls ); ?> srk-gsc-url-state"><?php echo esc_html( $url_state ?: '-' ); ?></span>
					</div>
					<div class="srk-gsc-status-group">
						<span class="srk-gsc-status-group__key"><?php esc_html_e( 'HTTP', 'seo-repair-kit' ); ?></span>
						<span class="srk-gsc-status-group__val srk-gsc-http-status"><?php echo esc_html( $http_val ); ?></span>
					</div>
					<div class="srk-gsc-status-group">
						<span class="srk-gsc-status-group__key"><?php esc_html_e( 'Sitemap', 'seo-repair-kit' ); ?></span>
						<span class="srk-gsc-status-group__val <?php echo esc_attr( $sitemap_cls ); ?> srk-gsc-sitemap-state"><?php echo esc_html( $sitemap_state ?: '-' ); ?></span>
					</div>
				</div>

				<!-- Status select -->
				<div class="srk-gsc-url-item__select-row">
					<span class="srk-gsc-url-item__select-label"><?php esc_html_e( 'Status', 'seo-repair-kit' ); ?></span>
					<select class="srk-gsc-cleanup-status">
						<?php foreach ( $this->get_statuses() as $option ) : ?>
							<option value="<?php echo esc_attr( $option ); ?>" <?php selected( $status, $option ); ?>><?php echo esc_html( $option ); ?></option>
						<?php endforeach; ?>
					</select>
				</div>

				<!-- Recommendation -->
				<?php if ( $recommendation ) : ?>
					<div class="srk-gsc-url-item__recommendation srk-gsc-recommendation"><?php echo esc_html( $recommendation ); ?></div>
				<?php endif; ?>

				<!-- Action buttons -->
				<div class="srk-gsc-url-item__actions">
					<button type="button" class="srk-dash-action-btn srk-dash-action-btn--ghost srk-gsc-analyze-url">
						<span class="dashicons dashicons-update" style="font-size:13px;width:13px;height:13px;line-height:1;"></span>
						<?php esc_html_e( 'Recheck', 'seo-repair-kit' ); ?>
					</button>
					<a class="srk-dash-action-btn srk-dash-action-btn--ghost" href="<?php echo esc_url( $inspection_url ); ?>" target="_blank" rel="noopener noreferrer">
						<span class="dashicons dashicons-search" style="font-size:13px;width:13px;height:13px;line-height:1;"></span>
						<?php esc_html_e( 'Inspect', 'seo-repair-kit' ); ?>
					</a>
					<a class="srk-dash-action-btn srk-dash-action-btn--ghost" href="<?php echo esc_url( $search_url ); ?>" target="_blank" rel="noopener noreferrer">
						<?php esc_html_e( 'SERP Check', 'seo-repair-kit' ); ?>
					</a>
					<button type="button" class="button srk-gsc-resolve-btn srk-gsc-analyze-url" onclick="this.closest('[data-cleanup-result-id]').querySelector('.srk-gsc-cleanup-status').value='Resolved';this.closest('[data-cleanup-result-id]').querySelector('.srk-gsc-cleanup-status').dispatchEvent(new Event('change'));">
						<?php esc_html_e( 'Mark Resolved', 'seo-repair-kit' ); ?>
					</button>
				</div>

			</div><!-- /.srk-gsc-url-item -->
			<?php
		}
		echo '</div><!-- /.srk-gsc-url-grid -->';
	}

	/**
	 * render_candidate_rows — kept for backward compatibility.
	 *
	 * @deprecated replaced by render_candidate_cards
	 */
	private function render_candidate_rows( array $candidates, $property, $service ) {
		$this->render_candidate_cards( $candidates, $property, $service );
	}

	/**
	 * Render KPI card.
	 *
	 * @param string $label Label.
	 * @param int    $value Value.
	 * @return void
	 */
	private function render_kpi( $label, $value ) {
		?>
		<div class="srk-sm-kpi-card">
			<div class="srk-sm-kpi-label"><?php echo esc_html( $label ); ?></div>
			<div class="srk-sm-kpi-value"><?php echo esc_html( number_format_i18n( absint( $value ) ) ); ?></div>
		</div>
		<?php
	}

	/**
	 * Get simplified cleanup statuses.
	 *
	 * @return array
	 */
	private function get_statuses() {
		return array(
			'Detected',
			'Needs Review',
			'Confirmed Spam',
			'Cleaned',
			'Monitoring Google',
			'Resolved',
			'False Positive',
		);
	}

	/**
	 * Normalize old detailed statuses into the simplified client-facing flow.
	 *
	 * @param string $status Status.
	 * @return string
	 */
	private function normalize_status( $status ) {
		$status = trim( (string) $status );
		$map    = array(
			'Cleaned On Website'    => 'Cleaned',
			'Removed From Sitemap'  => 'Cleaned',
			'404 Confirmed'         => 'Cleaned',
			'410 Confirmed'         => 'Cleaned',
			'Sitemap Resubmitted'   => 'Monitoring Google',
			'Waiting For Google'    => 'Monitoring Google',
		);

		if ( isset( $map[ $status ] ) ) {
			return $map[ $status ];
		}

		return in_array( $status, $this->get_statuses(), true ) ? $status : 'Detected';
	}

	/**
	 * Format URL state from stored analysis values.
	 *
	 * @param array $row Result row.
	 * @return string
	 */
	private function format_url_state( array $row ) {
		$http_status = absint( $row['http_status'] ?? 0 );
		if ( 0 === $http_status || null === ( $row['url_exists'] ?? null ) ) {
			return '-';
		}
		if ( $http_status >= 500 ) {
			return __( 'Server Error', 'seo-repair-kit' );
		}
		if ( 404 === $http_status || 410 === $http_status ) {
			return __( 'Removed', 'seo-repair-kit' );
		}
		if ( $http_status >= 300 && $http_status < 400 ) {
			return __( 'Redirects', 'seo-repair-kit' );
		}
		if ( $http_status >= 200 && $http_status < 300 ) {
			return __( 'Exists', 'seo-repair-kit' );
		}

		return __( 'Check Failed', 'seo-repair-kit' );
	}

	/**
	 * Format sitemap state from stored analysis values.
	 *
	 * @param array $row Result row.
	 * @return string
	 */
	private function format_sitemap_state( array $row ) {
		if ( null === ( $row['in_sitemap'] ?? null ) ) {
			return '-';
		}

		return ! empty( $row['in_sitemap'] ) ? __( 'Yes', 'seo-repair-kit' ) : __( 'No', 'seo-repair-kit' );
	}

	/**
	 * Summarize candidates.
	 *
	 * @param array $candidates Candidates.
	 * @return array
	 */
	private function summarize_candidates( array $candidates ) {
		$summary = array( 'critical' => 0, 'spam' => 0, 'resolved' => 0, 'sitemap_issues' => 0 );
		foreach ( $candidates as $row ) {
			$risk = sanitize_key( $row['risk_level'] ?? '' );
			if ( isset( $summary[ $risk ] ) ) {
				$summary[ $risk ]++;
			}
			if ( 'Resolved' === $this->normalize_status( $row['cleanup_status'] ?? '' ) ) {
				$summary['resolved']++;
			}
			if ( ! empty( $row['in_sitemap'] ) ) {
				$summary['sitemap_issues']++;
			}
		}
		return $summary;
	}

	/**
	 * Get unique candidate URLs.
	 *
	 * @param array $candidates Candidates.
	 * @return array
	 */
	private function get_candidate_urls( array $candidates ) {
		$urls = array();
		foreach ( $candidates as $row ) {
			$url = esc_url_raw( $row['url'] ?? '' );
			if ( '' !== $url ) {
				$urls[] = $url;
			}
		}
		return array_values( array_unique( $urls ) );
	}

	/**
	 * Get risk badge class.
	 *
	 * @param string $risk Risk level.
	 * @return string
	 */
	private function get_risk_class( $risk ) {
		$key = sanitize_key( $risk );
		return 'srk-sm-risk-badge srk-sm-risk-' . ( $key ? $key : 'clean' );
	}

	/**
	 * Get Search Console service.
	 *
	 * @return SRK_Search_Console_Service
	 */
	private function get_service() {
		return class_exists( 'SRK_Search_Console_Service' ) ? new SRK_Search_Console_Service() : null;
	}

	/**
	 * Send cleanup-related alert through existing alert settings.
	 *
	 * @param int    $result_id Result ID.
	 * @param string $event Event.
	 * @return void
	 */
	private function maybe_send_cleanup_alert( $result_id, $event ) {
		// Product rule: manual cleanup workflow changes should not send email notifications.
		// Scan-based alerts are handled centrally by SRK_Spam_Monitor_Alerts.
		return;
	}
}
