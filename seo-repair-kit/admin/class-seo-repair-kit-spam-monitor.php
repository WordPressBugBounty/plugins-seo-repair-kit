<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Coordinates the SERP-only Spam Monitor admin screen.
 */
class SeoRepairKit_SpamMonitor {

	/**
	 * Active tab renderer instances.
	 *
	 * @var array
	 */
	private $tabs = array();

	/**
	 * Constructor.
	 */
	public function __construct() {
		$this->load_tab_classes();
		$this->init_tab_handlers();

		add_action( 'admin_enqueue_scripts', array( $this, 'enqueue_assets' ) );
		add_action( 'wp_ajax_srk_sm_clear_records', array( $this, 'ajax_clear_records' ) );
		add_action( 'admin_post_srk_sm_export_records', array( $this, 'export_records_csv' ) );
	}

	/**
	 * Enqueue Spam Monitor assets only on the Spam Monitor page.
	 *
	 * @param string $hook Current admin hook.
	 * @return void
	 */
	public function enqueue_assets( $hook ) {
		$page = isset( $_GET['page'] ) ? sanitize_key( wp_unslash( $_GET['page'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Read-only admin routing.
		if ( 'seo-repair-kit-spam-monitor' !== $page ) {
			return;
		}

		$plugin_dir = plugin_dir_path( dirname( __FILE__ ) );
		$plugin_url = plugin_dir_url( dirname( __FILE__ ) );
		$css_path   = $plugin_dir . 'admin/css/seo-repair-kit-spam-monitor.css';
		$js_path    = $plugin_dir . 'admin/js/seo-repair-kit-spam-monitor.js';
		$serp_path  = $plugin_dir . 'admin/js/spam-monitor-js/srk-spam-monitor-google-serp-scan.js';
		$schedule_js_path  = $plugin_dir . 'admin/js/spam-monitor-js/srk-spam-monitor-schedule.js';
		$schedule_css_path = $plugin_dir . 'admin/css/spam-monitor-css/srk-spam-monitor-schedule.css';

		wp_enqueue_style(
			'srk-spam-monitor-style',
			$plugin_url . 'admin/css/seo-repair-kit-spam-monitor.css',
			array( 'srk-admin-style' ),
			file_exists( $css_path ) ? filemtime( $css_path ) : SEO_REPAIR_KIT_VERSION,
			'all'
		);

		wp_enqueue_style(
			'srk-spam-monitor-schedule',
			$plugin_url . 'admin/css/spam-monitor-css/srk-spam-monitor-schedule.css',
			array( 'srk-spam-monitor-style' ),
			file_exists( $schedule_css_path ) ? filemtime( $schedule_css_path ) : SEO_REPAIR_KIT_VERSION,
			'all'
		);

		wp_enqueue_script(
			'srk-spam-monitor',
			$plugin_url . 'admin/js/seo-repair-kit-spam-monitor.js',
			array( 'jquery' ),
			file_exists( $js_path ) ? filemtime( $js_path ) : SEO_REPAIR_KIT_VERSION,
			true
		);

		wp_localize_script(
			'srk-spam-monitor',
			'srkSpamMonitor',
			array(
				'ajaxUrl'     => admin_url( 'admin-ajax.php' ),
				'currentTab'  => $this->get_current_spam_monitor_tab(),
				'alertsNonce' => wp_create_nonce( 'srk_sm_alerts_nonce' ),
				'serpNonce'   => wp_create_nonce( 'srk_sm_serp_nonce' ),
				'scheduleNonce' => wp_create_nonce( 'srk_sm_schedule_nonce' ),
				'recordsNonce' => wp_create_nonce( 'srk_sm_records_nonce' ),
				'strings'     => array(
					'completed' => __( 'Completed.', 'seo-repair-kit' ),
					'failed'    => __( 'Something went wrong. Please try again.', 'seo-repair-kit' ),
					'scheduleSaveError'=> __( 'Scheduled scan settings could not be saved.', 'seo-repair-kit' ),
					'scheduleRunError' => __( 'The scheduled scan could not be completed.', 'seo-repair-kit' ),
					'scheduleResetError'=> __( 'Scheduled scan settings could not be reset.', 'seo-repair-kit' ),
					'scheduleResetConfirm'=> __( 'Reset Scheduled Spam Monitoring to its defaults? This disables the schedule and preserves all existing scan records.', 'seo-repair-kit' ),
					'testingFrequencyHelp'=> __( 'Testing mode: runs about every 10 minutes when WP-Cron receives traffic. The Run Time field is not used.', 'seo-repair-kit' ),
					'enabled'          => __( 'Enabled', 'seo-repair-kit' ),
					'disabled'         => __( 'Disabled', 'seo-repair-kit' ),
					'noScheduledScan'  => __( 'No scheduled scan yet', 'seo-repair-kit' ),
					'scheduleSaving'   => __( 'Saving scheduled scan settings…', 'seo-repair-kit' ),
					'scheduleRunning'  => __( 'Running the saved scheduled scan…', 'seo-repair-kit' ),
					'scheduleResetting'=> __( 'Resetting scheduled scan settings…', 'seo-repair-kit' ),
				),
			)
		);

		wp_enqueue_script(
			'srk-spam-monitor-google-serp-scan',
			$plugin_url . 'admin/js/spam-monitor-js/srk-spam-monitor-google-serp-scan.js',
			array( 'jquery', 'srk-spam-monitor' ),
			file_exists( $serp_path ) ? filemtime( $serp_path ) : SEO_REPAIR_KIT_VERSION,
			true
		);

		wp_enqueue_script(
			'srk-spam-monitor-schedule',
			$plugin_url . 'admin/js/spam-monitor-js/srk-spam-monitor-schedule.js',
			array( 'jquery', 'srk-spam-monitor' ),
			file_exists( $schedule_js_path ) ? filemtime( $schedule_js_path ) : SEO_REPAIR_KIT_VERSION,
			true
		);
	}

	public function ajax_clear_records() {
		check_ajax_referer( 'srk_sm_records_nonce', 'nonce' );
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_send_json_error( array( 'message' => __( 'Permission denied.', 'seo-repair-kit' ) ), 403 );
		}
		$dataset = sanitize_key( wp_unslash( $_POST['dataset'] ?? '' ) );
		if ( ! in_array( $dataset, array( 'serp', 'alerts' ), true ) || ! class_exists( 'SRK_Spam_Monitor_DB' ) ) {
			wp_send_json_error( array( 'message' => __( 'Invalid records dataset.', 'seo-repair-kit' ) ), 400 );
		}
		$deleted = SRK_Spam_Monitor_DB::clear_record_dataset( $dataset );
		if ( false === $deleted ) {
			wp_send_json_error( array( 'message' => __( 'Records could not be cleared.', 'seo-repair-kit' ) ), 500 );
		}
		if ( 'serp' === $dataset && class_exists( 'SRK_Spam_Monitor_Scheduler' ) ) {
			SRK_Spam_Monitor_Scheduler::clear_last_scan_reference();
		}
		wp_send_json_success( array(
			'message' => sprintf( __( '%d records cleared successfully.', 'seo-repair-kit' ), absint( $deleted ) ),
			'deleted' => absint( $deleted ),
		) );
	}

	public function export_records_csv() {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'Permission denied.', 'seo-repair-kit' ) );
		}
		$dataset = sanitize_key( wp_unslash( $_GET['dataset'] ?? '' ) );
		check_admin_referer( 'srk_sm_export_' . $dataset );
		$columns = SRK_Spam_Monitor_DB::get_export_columns( $dataset );
		if ( empty( $columns ) ) {
			wp_die( esc_html__( 'Invalid export dataset.', 'seo-repair-kit' ) );
		}
		nocache_headers();
		header( 'Content-Type: text/csv; charset=utf-8' );
		header( 'Content-Disposition: attachment; filename=srk-spam-monitor-' . str_replace( '_', '-', $dataset ) . '-' . gmdate( 'Y-m-d-His' ) . '.csv' );
		$output = fopen( 'php://output', 'w' );
		if ( false === $output ) {
			wp_die( esc_html__( 'Could not create the CSV export.', 'seo-repair-kit' ) );
		}
		fputcsv( $output, array_values( $columns ) );
		$offset = 0;
		do {
			$rows = SRK_Spam_Monitor_DB::get_export_records( $dataset, 500, $offset );
			foreach ( $rows as $row ) {
				$csv_row = array();
				foreach ( array_keys( $columns ) as $column ) {
					if ( 'issues' === $column && class_exists( 'SRK_Spam_Monitor_Alerts' ) ) {
						$csv_row[] = SRK_Spam_Monitor_Alerts::format_issue_text_for_display( $row[ $column ] ?? '' );
						continue;
					}

					$csv_row[] = isset( $row[ $column ] ) && is_scalar( $row[ $column ] ) ? $row[ $column ] : '';
				}
				fputcsv( $output, $csv_row );
			}
			$offset += 500;
		} while ( count( $rows ) === 500 );
		fclose( $output ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fclose -- Required to close the php://output CSV stream.
		exit;
	}

	/**
	 * Load active SERP-only tab class files.
	 *
	 * @return void
	 */
	private function load_tab_classes() {
		$base_path = plugin_dir_path( __FILE__ ) . 'spam-monitor/';
		$files     = array(
			'class-srk-spam-monitor-admin-pagination.php',
			'class-seo-repair-kit-spam-monitor-dashboard.php',
			'class-seo-repair-kit-spam-monitor-rules.php',
			'class-srk-spam-monitor-google-serp-scan.php',
			'class-seo-repair-kit-spam-monitor-gsc-cleanup.php',
			'class-seo-repair-kit-spam-monitor-alerts.php',
			'class-seo-repair-kit-spam-monitor-settings.php',
		);

		foreach ( $files as $file ) {
			$path = $base_path . $file;
			if ( file_exists( $path ) ) {
				require_once $path;
			}
		}
	}

	/**
	 * Initialize active tab handlers.
	 *
	 * @return void
	 */
	private function init_tab_handlers() {
		$this->tabs = array();

		if ( class_exists( 'SeoRepairKit_SpamMonitor_Dashboard' ) ) {
			$this->tabs['dashboard'] = new SeoRepairKit_SpamMonitor_Dashboard();
		}
		if ( class_exists( 'SeoRepairKit_SpamMonitor_Rules' ) ) {
			$this->tabs['spam-rules'] = new SeoRepairKit_SpamMonitor_Rules();
		}
		if ( class_exists( 'SeoRepairKit_SpamMonitor_Google_SERP_Scan' ) ) {
			$this->tabs['google-serp-scan'] = new SeoRepairKit_SpamMonitor_Google_SERP_Scan();
		}
		if ( class_exists( 'SeoRepairKit_SpamMonitor_GSC_Cleanup' ) ) {
			$this->tabs['gsc-cleanup'] = new SeoRepairKit_SpamMonitor_GSC_Cleanup();
		}
		if ( class_exists( 'SeoRepairKit_SpamMonitor_Alerts' ) ) {
			$this->tabs['alerts'] = new SeoRepairKit_SpamMonitor_Alerts();
		}
		if ( class_exists( 'SeoRepairKit_SpamMonitor_Settings' ) ) {
			$this->tabs['settings'] = new SeoRepairKit_SpamMonitor_Settings();
		}
	}

	/**
	 * Get active tab slugs.
	 *
	 * @return array
	 */
	private function get_allowed_tabs() {
		return array( 'dashboard', 'spam-rules', 'google-serp-scan', 'gsc-cleanup', 'alerts', 'settings' );
	}

	/**
	 * Get the current tab slug.
	 *
	 * @return string
	 */
	private function get_current_spam_monitor_tab() {
		$tab = isset( $_GET['tab'] ) ? sanitize_key( wp_unslash( $_GET['tab'] ) ) : 'dashboard'; // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Read-only tab routing.
		return in_array( $tab, $this->get_allowed_tabs(), true ) ? $tab : 'dashboard';
	}

	/**
	 * Render Spam Monitor admin page.
	 *
	 * @return void
	 */
	public function seorepairkit_spam_monitor_page() {
		$current_tab = $this->get_current_spam_monitor_tab();
		$tabs        = array(
			'dashboard'        => array( 'label' => __( 'Dashboard', 'seo-repair-kit' ), 'icon' => 'dashicons-dashboard' ),
			'spam-rules'       => array( 'label' => __( 'Spam Rules', 'seo-repair-kit' ), 'icon' => 'dashicons-shield' ),
			'google-serp-scan' => array( 'label' => __( 'Google SERP Scan', 'seo-repair-kit' ), 'icon' => 'dashicons-search' ),
			'gsc-cleanup'      => array( 'label' => __( 'Search Console Cleanup', 'seo-repair-kit' ), 'icon' => 'dashicons-admin-site-alt3' ),
			'alerts'           => array( 'label' => __( 'Alerts', 'seo-repair-kit' ), 'icon' => 'dashicons-bell' ),
			'settings'         => array( 'label' => __( 'Settings', 'seo-repair-kit' ), 'icon' => 'dashicons-admin-settings' ),
		);
		$scan_url    = admin_url( 'admin.php?page=seo-repair-kit-spam-monitor&tab=google-serp-scan' );
		$rules_url   = admin_url( 'admin.php?page=seo-repair-kit-spam-monitor&tab=spam-rules' );
		$cleanup_url = admin_url( 'admin.php?page=seo-repair-kit-spam-monitor&tab=gsc-cleanup' );
		?>
		<div id="srk-spam-monitor-dashboard" class="srk-wrap srk-spam-monitor-wrap">

			<!-- ── HERO ──────────────────────────────────────────────────────── -->
			<div class="srk-hero">
				<div class="srk-hero-content">
					<div class="srk-hero-icon">
						<span class="dashicons dashicons-shield-alt"></span>
					</div>
					<div class="srk-hero-text">
						<h2><?php esc_html_e( 'Google SERP Scan Dashboard', 'seo-repair-kit' ); ?></h2>
						<p><?php esc_html_e( 'Indexed Google result health, spam risk, Python engine status, and Spam Rules sync status.', 'seo-repair-kit' ); ?></p>
						<div class="srk-hero-features">
							<a class="srk-hero-badge" href="<?php echo esc_url( $scan_url ); ?>">
								<span class="dashicons dashicons-search"></span>
								<?php esc_html_e( 'Google SERP Scan', 'seo-repair-kit' ); ?>
							</a>
							<a class="srk-hero-badge" href="<?php echo esc_url( $rules_url ); ?>">
								<span class="dashicons dashicons-shield-alt"></span>
								<?php esc_html_e( 'Spam Rules', 'seo-repair-kit' ); ?>
							</a>
							<a class="srk-hero-badge" href="<?php echo esc_url( $cleanup_url ); ?>">
								<span class="dashicons dashicons-admin-site-alt3"></span>
								<?php esc_html_e( 'Search Console Cleanup', 'seo-repair-kit' ); ?>
							</a>
						</div>
					</div>
				</div>
			</div>

			<!-- ── TAB NAV ───────────────────────────────────────────────────── -->
			<?php $this->render_spam_monitor_module_status_card(); ?>

			<div class="srk-spam-monitor-tabs srk-link-scanner-tabs">
				<nav class="srk-tab-nav">
					<?php foreach ( $tabs as $tab_slug => $tab_data ) : ?>
						<button type="button" class="srk-tab-button <?php echo ( $tab_slug === $current_tab ) ? 'active' : ''; ?>" data-tab="<?php echo esc_attr( $tab_slug ); ?>" data-tab-url="<?php echo esc_url( $this->get_tab_url( $tab_slug ) ); ?>">
							<span class="dashicons <?php echo esc_attr( $tab_data['icon'] ); ?>"></span>
							<?php echo esc_html( $tab_data['label'] ); ?>
						</button>
					<?php endforeach; ?>
				</nav>
			</div>

			<!-- ── TAB CONTENT ───────────────────────────────────────────────── -->
			<?php foreach ( $tabs as $tab_slug => $tab_data ) : ?>
				<div id="srk-tab-<?php echo esc_attr( $tab_slug ); ?>" class="srk-tab-content <?php echo ( $tab_slug === $current_tab ) ? 'active' : ''; ?>" data-loaded="<?php echo ( $tab_slug === $current_tab ) ? '1' : '0'; ?>" style="<?php echo ( $tab_slug === $current_tab ) ? '' : 'display:none;'; ?>">
					<?php
					if ( $tab_slug === $current_tab ) {
						$this->render_tab_content( $tab_slug );
					}
					?>
				</div>
			<?php endforeach; ?>

		</div>
		<?php
	}

	/**
	 * Render Spam Monitor module access status card.
	 *
	 * @return void
	 */
	private function render_spam_monitor_module_status_card() {
		$spam_monitor_enabled = class_exists( 'SRK_License_Helper' ) && SRK_License_Helper::is_spam_monitor_enabled();
		$subscribe_url        = admin_url( 'admin.php?page=seo-repair-kit-upgrade-pro' );

		if ( class_exists( 'SRK_API_Client' ) ) {
			$subscribe_url = SRK_API_Client::get_api_url(
				SRK_API_Client::ENDPOINT_SUBSCRIBE,
				array(
					'domain' => site_url(),
				)
			);
		}
		?>
		<?php if ( ! $spam_monitor_enabled ) : ?>
			<div class="srk-sm-module-access-card">
				<div class="srk-sm-module-access-icon"><span class="dashicons dashicons-info-outline"></span></div>
				<div class="srk-sm-module-access-copy">
					<strong><?php esc_html_e( 'Free Spam Monitor access', 'seo-repair-kit' ); ?></strong>
					<p><?php esc_html_e( 'Free sites can scan with the SEO Repair Kit trial provider. Add the Spam Monitor module to connect your own SERP provider API key and unlock the full Spam Monitor workflow.', 'seo-repair-kit' ); ?></p>
					<div class="srk-sm-module-access-chips">
						<span><?php esc_html_e( 'Unlock integrations:', 'seo-repair-kit' ); ?></span>
						<em><?php esc_html_e( 'Serper.dev', 'seo-repair-kit' ); ?></em>
						<em><?php esc_html_e( 'SERP API', 'seo-repair-kit' ); ?></em>
						<em><?php esc_html_e( 'Data for SEO', 'seo-repair-kit' ); ?></em>
					</div>
				</div>
				<a class="srk-sm-module-access-button" target="_blank" rel="noopener noreferrer" href="<?php echo esc_url( $subscribe_url ); ?>">
					<?php esc_html_e( 'Buy Spam Monitor Module', 'seo-repair-kit' ); ?>
				</a>
			</div>
		<?php else : ?>
			<div class="srk-sm-module-access-card srk-sm-module-access-card--active">
				<div class="srk-sm-module-access-icon"><span class="dashicons dashicons-yes-alt"></span></div>
				<div class="srk-sm-module-access-copy">
					<strong><?php esc_html_e( 'Spam Monitor module active', 'seo-repair-kit' ); ?></strong>
					<p><?php esc_html_e( 'Your site can use Spam Monitor module features, including custom SERP provider connections where supported by your plan.', 'seo-repair-kit' ); ?></p>
					<div class="srk-sm-module-access-chips">
						<span><?php esc_html_e( 'Active features:', 'seo-repair-kit' ); ?></span>
						<em><?php esc_html_e( 'Custom SERP providers', 'seo-repair-kit' ); ?></em>
						<em><?php esc_html_e( 'Spam Rules sync', 'seo-repair-kit' ); ?></em>
						<em><?php esc_html_e( 'Scheduled monitoring', 'seo-repair-kit' ); ?></em>
					</div>
				</div>
			</div>
		<?php endif; ?>
		<?php
	}

	/**
	 * Build a URL for a Spam Monitor tab.
	 *
	 * @param string $tab_slug Tab slug.
	 * @return string
	 */
	private function get_tab_url( $tab_slug ) {
		$tab_slug = sanitize_key( $tab_slug );

		if ( 'dashboard' === $tab_slug ) {
			return admin_url( 'admin.php?page=seo-repair-kit-spam-monitor' );
		}

		return admin_url( 'admin.php?page=seo-repair-kit-spam-monitor&tab=' . $tab_slug );
	}

	/**
	 * Render selected tab content.
	 *
	 * @param string $tab_slug Tab slug.
	 * @return void
	 */
	private function render_tab_content( $tab_slug ) {
		if ( isset( $this->tabs[ $tab_slug ] ) && method_exists( $this->tabs[ $tab_slug ], 'render' ) ) {
			$this->tabs[ $tab_slug ]->render();
			return;
		}

		echo '<div class="srk-card srk-empty"><h3>' . esc_html__( 'Tab module not available', 'seo-repair-kit' ) . '</h3></div>';
	}
}
