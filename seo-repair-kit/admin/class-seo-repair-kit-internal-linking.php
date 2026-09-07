<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Internal Linking admin coordinator.
 *
 * Responsibilities:
 * - Register Internal Linking tabs.
 * - Load tab UI classes.
 * - Conditionally load Internal Linking assets.
 * - Prepare AJAX nonce/localized object for later phases.
 *
 */
class SeoRepairKit_InternalLinking {

	private static $instance = null;

	/**
	 * Prevent backend classes/hooks from loading twice in one request.
	 *
	 * @var bool
	 */
	private static $backend_loaded = false;

	public static function get_instance() {
		if ( null === self::$instance ) {
			self::$instance = new self();
		}

		return self::$instance;
	}

	private function __construct() {
		add_action( 'admin_enqueue_scripts', array( $this, 'enqueue_assets' ) );
		add_action( 'admin_init', array( $this, 'maybe_upgrade_database' ) );
		add_action( 'enqueue_block_editor_assets', array( $this, 'enqueue_gutenberg_editor_assets' ) );
		add_action( 'admin_head-post.php', array( $this, 'preload_gutenberg_editor_icon' ) );
		add_action( 'admin_head-post-new.php', array( $this, 'preload_gutenberg_editor_icon' ) );

		self::bootstrap_runtime();
	}

	/**
	 * Register allowed Internal Linking tabs.
	 *
	 * @return array
	 */
	private function get_tabs() {
		return array(
			'dashboard' => array(
				'label' => esc_html__( 'Dashboard', 'seo-repair-kit' ),
				'icon'  => 'dashicons-chart-area',
				'class' => 'SeoRepairKit_InternalLinking_Dashboard',
				'file'  => 'class-seo-repair-kit-internal-linking-dashboard.php',
			),
			'content-index' => array(
				'label' => esc_html__( 'Content Index', 'seo-repair-kit' ),
				'icon'  => 'dashicons-database',
				'class' => 'SeoRepairKit_InternalLinking_Content_Index',
				'file'  => 'class-seo-repair-kit-internal-linking-content-index.php',
			),
			'target-keywords' => array(
				'label' => esc_html__( 'Target Keywords', 'seo-repair-kit' ),
				'icon'  => 'dashicons-tag',
				'class' => 'SeoRepairKit_InternalLinking_Target_Keywords',
				'file'  => 'class-seo-repair-kit-internal-linking-target-keywords.php',
			),
			'link-opportunities' => array(
				'label' => esc_html__( 'Link Opportunities', 'seo-repair-kit' ),
				'icon'  => 'dashicons-networking',
				'class' => 'SeoRepairKit_InternalLinking_Link_Opportunities',
				'file'  => 'class-seo-repair-kit-internal-linking-link-opportunities.php',
			),
			'auto-linking' => array(
				'label' => esc_html__( 'Auto Linking', 'seo-repair-kit' ),
				'icon'  => 'dashicons-admin-links',
				'class' => 'SeoRepairKit_InternalLinking_Auto_Linking',
				'file'  => 'class-seo-repair-kit-internal-linking-auto-linking.php',
			),
			'url-changer' => array(
				'label' => esc_html__( 'URL Changer', 'seo-repair-kit' ),
				'icon'  => 'dashicons-randomize',
				'class' => 'SeoRepairKit_InternalLinking_URL_Changer',
				'file'  => 'class-seo-repair-kit-internal-linking-url-changer.php',
			),
			'approved-links' => array(
				'label' => esc_html__( 'Approved Links', 'seo-repair-kit' ),
				'icon'  => 'dashicons-yes-alt',
				'class' => 'SeoRepairKit_InternalLinking_Approved_Links',
				'file'  => 'class-seo-repair-kit-internal-linking-approved-links.php',
			),
			'orphan-content' => array(
				'label' => esc_html__( 'Orphan Content', 'seo-repair-kit' ),
				'icon'  => 'dashicons-editor-unlink',
				'class' => 'SeoRepairKit_InternalLinking_Orphan_Content',
				'file'  => 'class-seo-repair-kit-internal-linking-orphan-content.php',
			),
			'reports' => array(
				'label' => esc_html__( 'Reports', 'seo-repair-kit' ),
				'icon'  => 'dashicons-analytics',
				'class' => 'SeoRepairKit_InternalLinking_Reports',
				'file'  => 'class-seo-repair-kit-internal-linking-reports.php',
			),
			'settings' => array(
				'label' => esc_html__( 'Settings', 'seo-repair-kit' ),
				'icon'  => 'dashicons-admin-generic',
				'class' => 'SeoRepairKit_InternalLinking_Settings',
				'file'  => 'class-seo-repair-kit-internal-linking-settings.php',
			),
		);
	}

	/**
	 * Check if the Internal Linking paid module is enabled.
	 *
	 * @return bool
	 */
	private function is_internal_linking_feature_enabled() {
		return class_exists( 'SRK_License_Helper' ) && SRK_License_Helper::is_internal_linking_enabled();
	}
	/**
	 * Preload the SEO Repair Kit Gutenberg toolbar icon.
	 *
	 * The toolbar plugin is rendered by JavaScript later in the editor lifecycle.
	 * Preloading the local logo allows the browser to fetch it before React
	 * renders the toolbar button.
	 *
	 * @return void
	 */
	public function preload_gutenberg_editor_icon() {
		$screen = function_exists( 'get_current_screen' )
			? get_current_screen()
			: null;

		if (
			! $screen ||
			'post' !== $screen->base
		) {
			return;
		}
		$icon_url = plugins_url(
			'images/seorepairkit-logo.png',
			__FILE__
		);

		?>
		<link
			rel="preload"
			href="<?php echo esc_url( $icon_url ); ?>"
			as="image"
			fetchpriority="high"
		>
		<?php
	}

	/**
	 * Check if current admin page is the Internal Linking page.
	 *
	 * @return bool
	 */
	private function is_internal_linking_screen() {
		$page = isset( $_GET['page'] ) ? sanitize_key( wp_unslash( $_GET['page'] ) ) : '';
		$tab  = isset( $_GET['tab'] ) ? sanitize_key( wp_unslash( $_GET['tab'] ) ) : '';

		if ( 'seo-repair-kit-internal-linking' === $page ) {
			return true;
		}

		if ( 'seo-repair-kit-link-scanner' === $page && 'internal-linking' === $tab ) {
			return true;
		}

		return false;
	}

	/**
	 * Run Internal Linking DB upgrades safely.
	 *
	 * Prevents missing-table issues after plugin updates.
	 * Runs lightweight version check only.
	 *
	 * @return void
	 */
	public function maybe_upgrade_database() {

		/*
		* Only continue if DB class exists.
		*/
		if ( ! class_exists( 'SRK_Internal_Linking_DB' ) ) {
			return;
		}

		if (
			! is_admin() ||
			( function_exists( 'wp_doing_ajax' ) && wp_doing_ajax() ) ||
			( function_exists( 'wp_doing_cron' ) && wp_doing_cron() ) ||
			( defined( 'REST_REQUEST' ) && REST_REQUEST )
		) {
			return;
		}

		/*
		* Run version comparison.
		*/
		SRK_Internal_Linking_DB::maybe_upgrade();
	}

	/**
	 * Get safe current Internal Linking tab.
	 *
	 * @return string
	 */
	private function get_current_sub_tab() {
		$tabs        = $this->get_tabs();
		$current_tab = isset( $_GET['srk_il_tab'] ) ? sanitize_key( wp_unslash( $_GET['srk_il_tab'] ) ) : 'dashboard';

		return isset( $tabs[ $current_tab ] ) ? $current_tab : 'dashboard';
	}

	/**
	 * Enqueue only Internal Linking assets on Internal Linking screen.
	 *
	 * Uses one shared CSS file and separate JS files per tab.
	 *
	 * @return void
	 */
	public function enqueue_assets( $force = false ) {
		if ( ! $force && ! $this->is_internal_linking_screen() ) {
			return;
		}

		$current_tab = $this->get_current_sub_tab();

		$css_file = plugin_dir_path( __FILE__ ) . 'css/internal-linking-css/seo-repair-kit-internal-linking.css';

		wp_enqueue_style(
			'srk-internal-linking',
			plugin_dir_url( __FILE__ ) . 'css/internal-linking-css/seo-repair-kit-internal-linking.css',
			array(),
			null
		);

		$auto_css_file = plugin_dir_path( __FILE__ ) . 'css/internal-linking-css/seo-repair-kit-internal-linking-auto-linking.css';

		wp_enqueue_style(
			'srk-internal-linking-auto-linking',
			plugin_dir_url( __FILE__ ) . 'css/internal-linking-css/seo-repair-kit-internal-linking-auto-linking.css',
			array( 'srk-internal-linking' ),
			null
		);

		$scripts = array(
			'dashboard',
			'content-index',
			'target-keywords',
			'link-opportunities',
			'auto-linking',
			'url-changer',
			'approved-links',
			'orphan-content',
			'reports',
			'settings',
		);

		foreach ( $scripts as $script ) {
			$script_path = plugin_dir_path( __FILE__ ) . 'js/internal-linking-js/seo-repair-kit-internal-linking-' . $script . '.js';

			if ( ! file_exists( $script_path ) ) {
				continue;
			}

			wp_enqueue_script(
				'srk-internal-linking-' . $script,
				plugin_dir_url( __FILE__ ) . 'js/internal-linking-js/seo-repair-kit-internal-linking-' . $script . '.js',
				array( 'jquery' ),
				filemtime( $script_path ),
				true
			);
		}

		$localized_data = array(
			'ajaxUrl'    => admin_url( 'admin-ajax.php' ),
			'nonce'      => wp_create_nonce( 'srk_internal_linking_nonce' ),
			'currentTab' => $current_tab,
		);

		/*
		* General Internal Linking scripts.
		*/
		if (
			wp_script_is(
				'srk-internal-linking-dashboard',
				'enqueued'
			)
		) {
			wp_localize_script(
				'srk-internal-linking-dashboard',
				'srkInternalLinking',
				$localized_data
			);
		}

		if (
			wp_script_is(
				'srk-internal-linking-settings',
				'enqueued'
			)
		) {
			wp_localize_script(
				'srk-internal-linking-settings',
				'srkInternalLinkingSettings',
				$localized_data
			);
		}

	}

	/**
	 * Load all registered tab UI files.
	 *
	 * @return void
	 */
	private function load_all_tab_files() {
		$tabs      = $this->get_tabs();
		$base_path = plugin_dir_path( __FILE__ ) . 'internal-linking/';

		foreach ( $tabs as $tab ) {
			if ( empty( $tab['file'] ) || empty( $tab['class'] ) ) {
				continue;
			}

			$file_path = $base_path . $tab['file'];

			if ( file_exists( $file_path ) && ! class_exists( $tab['class'] ) ) {
				require_once $file_path;
			}
		}
	}

	/**
	 * Load Internal Linking runtime backend.
	 *
	 * This runtime is required for:
	 * - normal admin screens;
	 * - Gutenberg REST post saves;
	 * - Internal Linking AJAX;
	 * - Action Scheduler;
	 * - WP-Cron.
	 *
	 * It intentionally does not load tab UI files.
	 *
	 * @return void
	 */
	public static function bootstrap_runtime() {

		if ( self::$backend_loaded ) {
			return;
		}

		self::$backend_loaded = true;

		$base_path =
			plugin_dir_path( dirname( __FILE__ ) ) .
			'includes/internal-linking-backend/';

		/*
		* Load dependency classes first.
		*
		*/
		$files = array(
			'class-srk-internal-linking-stopwords.php',
			'class-srk-internal-linking-settings.php',

			'class-srk-internal-linking-db.php',

			'class-srk-internal-linking-keywords.php',
			'class-srk-internal-linking-scoring.php',

			'class-srk-internal-linking-elementor.php',
			'class-srk-internal-linking-indexer.php',
			'class-srk-internal-linking-opportunities.php',

			'class-srk-internal-linking-ai-engine.php',
			'class-srk-internal-linking-queue.php',
			'class-srk-internal-linking-service.php',

			'class-srk-internal-linking-url-changer.php',
			'class-srk-internal-linking-auto-linker.php',
			'class-srk-internal-linking-reports.php',

			'class-srk-internal-linking-ajax.php',
		);

		foreach ( $files as $file ) {
			$file_path =
				$base_path .
				$file;

			if ( file_exists( $file_path ) ) {
				require_once $file_path;
			}
		}

		if (
			class_exists(
				'SRK_Internal_Linking_Elementor'
			)
		) {
			SRK_Internal_Linking_Elementor::init();
		}

		/*
		* Queue does not initialize itself,
		* therefore initialize it here.
		*/
		if (
			class_exists(
				'SRK_Internal_Linking_Queue'
			)
		) {
			SRK_Internal_Linking_Queue::init();
		}

		/*
		* AJAX controller does not initialize itself,
		* therefore initialize it here.
		*/
		if (
			class_exists(
				'SRK_Internal_Linking_Ajax'
			)
		) {
			SRK_Internal_Linking_Ajax::init();
		}
	}

	public function enqueue_gutenberg_editor_assets() {
		$screen = function_exists( 'get_current_screen' ) ? get_current_screen() : null;
		
		$srk_icon_url = plugins_url( 'images/seorepairkit-logo.png', __FILE__ );

		if ( ! $screen || 'post' !== $screen->base ) {
			return;
		}
		$script_path = plugin_dir_path( __FILE__ ) . 'js/internal-linking-js/seo-repair-kit-internal-linking-gutenberg.js';

		if ( ! file_exists( $script_path ) ) {
			return;
		}

		wp_enqueue_script(
			'srk-internal-linking-gutenberg',
			plugin_dir_url( __FILE__ ) . 'js/internal-linking-js/seo-repair-kit-internal-linking-gutenberg.js',
			array(
				'jquery',
				'wp-plugins',
				'wp-edit-post',
				'wp-editor',
				'wp-element',
				'wp-components',
				'wp-data',
			),
			filemtime( $script_path ),
			true
		);

		$style_path = plugin_dir_path( __FILE__ ) . 'css/internal-linking-css/seo-repair-kit-internal-linking-gutenberg.css';

		wp_enqueue_style(
			'seo-repair-kit-internal-linking-gutenberg',
			plugin_dir_url( __FILE__ ) . 'css/internal-linking-css/seo-repair-kit-internal-linking-gutenberg.css',
			array(),
			file_exists( $style_path ) ? filemtime( $style_path ) : time()
		);

		wp_add_inline_style(
			'seo-repair-kit-internal-linking-gutenberg',
			'.srk-il-editor-suggestion-card{border:1px solid #d7dee5;background:#fff;padding:14px;margin-bottom:14px;box-shadow:0 1px 0 rgba(0,0,0,.03)}.srk-il-editor-scan-button{width:100%;justify-content:center;margin-bottom:14px}.srk-il-preview-anchor,.srk-il-editor-highlight-anchor{background:#dbeafe;color:#111827;font-weight:800;padding:1px 4px;text-decoration:underline;text-decoration-color:#0b56d9}.srk-il-match-badge{background:#eaf4ff;color:#075c9f;font-size:11px;font-weight:800;text-transform:uppercase;letter-spacing:.5px;padding:4px 8px}.srk-il-score-text{color:#075c9f;font-size:13px;font-weight:800;border-bottom:3px solid #075c9f;padding-bottom:2px}.srk-il-card-header,.srk-il-card-actions{display:flex;align-items:center;justify-content:space-between;gap:10px}.srk-il-editor-keyword-list{display:flex;flex-wrap:wrap;gap:6px;margin-bottom:14px}.srk-il-editor-keyword-chip,.srk-il-editor-custom-chip{display:inline-flex;border-radius:4px;padding:5px 8px;font-size:12px;font-weight:600}.srk-il-editor-keyword-chip{background:#eef4ff;color:#0b56d9}.srk-il-editor-custom-chip{background:#ecfdf3;color:#067647}'
		);

		wp_localize_script(
			'srk-internal-linking-gutenberg',
			'srkInternalLinkingEditor',
			array(
				'ajaxUrl' =>
					admin_url(
						'admin-ajax.php'
					),

				'nonce' =>
					wp_create_nonce(
						'srk_internal_linking_nonce'
					),

				'iconUrl' =>
					esc_url_raw(
						$srk_icon_url
					),

				'settingsUrl' =>
					esc_url_raw(
						admin_url(
							'admin.php?page=seo-repair-kit-internal-linking&srk_il_tab=settings'
						)
					),

				'canManageSettings' =>
					current_user_can(
						'manage_options'
					)
						? 1
						: 0,

				'internalLinkingEnabled' =>
					$this->is_internal_linking_feature_enabled()
						? 1
						: 0,

				'internalLinkingPaidRequiredMessage' =>
					__(
						'Internal Linking is a paid module. Please upgrade or renew Internal Linking to use this feature.',
						'seo-repair-kit'
					),
			)
		);
		
	}

	public function render_admin_page() {
		$this->render_tab();
	}

	private function print_internal_linking_assets_now() {
		$this->enqueue_assets( true );

		wp_print_styles( array(
			'srk-internal-linking',
			'srk-internal-linking-auto-linking',
		) );

		wp_print_scripts(
			array(
				'srk-internal-linking-dashboard',
				'srk-internal-linking-auto-linking',
				'srk-internal-linking-settings',
			)
		);
	}

	/**
	 * Render Internal Linking admin page.
	 *
	 * @return void
	 */
	public function render_tab() {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'You do not have permission to access this page.', 'seo-repair-kit' ) );
		}

		$this->print_internal_linking_assets_now();


		if ( ! $this->is_internal_linking_feature_enabled() ) {
			$this->render_paid_locked_panel();
			return;
		}
		$this->load_all_tab_files();

		$tabs        = $this->get_tabs();
		$current_tab = $this->get_current_sub_tab();
		?>
		<div class="srk-il-wrap">
			<div class="srk-il-hero">
				<div>
					<h2><?php esc_html_e( 'Internal Linking', 'seo-repair-kit' ); ?></h2>
					<p><?php esc_html_e( 'Find link opportunities, index content, detect orphan pages, review approved links, manage target keywords, auto linking rules, and URL changes.', 'seo-repair-kit' ); ?></p>
				</div>
			</div>

			<nav class="srk-il-tabs" aria-label="<?php esc_attr_e( 'Internal Linking Tabs', 'seo-repair-kit' ); ?>">
				<?php foreach ( $tabs as $tab_key => $tab ) : ?>
					<button
						type="button"
						class="srk-il-tab <?php echo esc_attr( $current_tab === $tab_key ? 'is-active' : '' ); ?>"
						data-srk-il-tab="<?php echo esc_attr( $tab_key ); ?>"
					>
						<span class="dashicons <?php echo esc_attr( $tab['icon'] ); ?>"></span>
						<span><?php echo esc_html( $tab['label'] ); ?></span>
					</button>
				<?php endforeach; ?>
			</nav>

			<div class="srk-il-content">
				<?php foreach ( $tabs as $tab_key => $tab ) : ?>
					<div
						id="srk-il-tab-<?php echo esc_attr( $tab_key ); ?>"
						class="srk-il-tab-panel <?php echo esc_attr( $current_tab === $tab_key ? 'is-active' : '' ); ?>"
						style="<?php echo esc_attr( $current_tab === $tab_key ? '' : 'display:none;' ); ?>"
					>
						<?php
						try {
							$this->render_sub_tab_content( $tab_key );
						} catch ( Throwable $e ) {
							echo '<div class="srk-il-card">';
							echo '<p><strong>' . esc_html__( 'This tab failed to load.', 'seo-repair-kit' ) . '</strong></p>';
							echo '<p>' . esc_html( $e->getMessage() ) . '</p>';
							echo '</div>';
						}
						?>
					</div>
				<?php endforeach; ?>
			</div>
		</div>
		<?php
	}

	/**
	 * Render selected tab content.
	 *
	 * @param string $tab_key Current tab key.
	 * @return void
	 */
	private function render_sub_tab_content( $tab_key ) {
	$tabs       = $this->get_tabs();
	$class_name = isset( $tabs[ $tab_key ]['class'] ) ? $tabs[ $tab_key ]['class'] : '';

	if ( empty( $class_name ) ) {
		echo '<div class="srk-il-card"><p>' . esc_html__( 'Missing tab class configuration.', 'seo-repair-kit' ) . '</p></div>';
		return;
	}

	if ( ! class_exists( $class_name ) ) {
			echo '<div class="srk-il-card">';
			echo '<p><strong>' . esc_html__( 'Class not found.', 'seo-repair-kit' ) . '</strong></p>';
			echo '<p>' . esc_html( $class_name ) . '</p>';
			echo '</div>';
			return;
		}

		$handler = new $class_name();

		if ( ! method_exists( $handler, 'render' ) ) {
			echo '<div class="srk-il-card"><p>' . esc_html__( 'Render method missing.', 'seo-repair-kit' ) . '</p></div>';
			return;
		}

		$handler->render();
	}

	/**
	 * Render the locked Internal Linking paid-module state.
	 *
	 * @return void
	 */
	private function render_paid_locked_panel() {
		$subscribe_url = '#';

		if ( class_exists( 'SRK_API_Client' ) ) {
			$subscribe_url = SRK_API_Client::get_api_url(
				SRK_API_Client::ENDPOINT_SUBSCRIBE,
				array( 'domain' => site_url() )
			);
		}
		?>
		<div class="srk-il-wrap">
			<div class="srk-il-hero">
				<div>
					<h2><?php esc_html_e( 'Internal Linking', 'seo-repair-kit' ); ?></h2>
					<p><?php esc_html_e( 'Internal Linking is available with the paid Internal Linking module.', 'seo-repair-kit' ); ?></p>
				</div>
			</div>

			<div class="srk-il-card">
				<h3><?php esc_html_e( 'Premium Required', 'seo-repair-kit' ); ?></h3>
				<p><?php esc_html_e( 'Internal Linking is part of a paid SEO Repair Kit module. Upgrade or renew this module to unlock indexing, link opportunities, Auto Linking, reports, and editor suggestions.', 'seo-repair-kit' ); ?></p>
				<p><?php esc_html_e( 'Your existing Internal Linking data and already inserted links are preserved.', 'seo-repair-kit' ); ?></p>
				<p>
					<a class="button button-primary" target="_blank" rel="noopener noreferrer" href="<?php echo esc_url( $subscribe_url ); ?>">
						<?php esc_html_e( 'Upgrade Internal Linking', 'seo-repair-kit' ); ?>
					</a>
				</p>
				<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
					<?php wp_nonce_field( 'srk_refresh_license_status' ); ?>
					<input type="hidden" name="action" value="srk_refresh_license_status" />
					<button type="submit" class="button">
						<?php esc_html_e( 'Refresh License Status', 'seo-repair-kit' ); ?>
					</button>
				</form>
			</div>
		</div>
		<?php
	}

	/**
	 * Render Internal Linking pagination.
	 *
	 * Keeps the active Internal Linking tab and explicitly supplied
	 * query arguments while moving between pagination pages.
	 *
	 * @param string $tab_key    Internal Linking tab key.
	 * @param string $page_arg   Query argument used for current page.
	 * @param int    $page       Current page.
	 * @param int    $per_page   Items per page.
	 * @param int    $total      Total number of items.
	 * @param array  $extra_args Additional query arguments to preserve.
	 *
	 * @return void
	 */
	public static function render_pagination( $tab_key, $page_arg, $page, $per_page, $total, $extra_args = array() ) {
		$page       = max( 1, absint( $page ) );
		$per_page   = max( 1, absint( $per_page ) );
		$total      = absint( $total );
		$total_page = max(
			1,
			(int) ceil( $total / $per_page )
		);

		if ( $total_page <= 1 ) {
			return;
		}

		$base_args = array(
			'page'       => 'seo-repair-kit-internal-linking',
			'srk_il_tab' => sanitize_key( $tab_key ),
		);

		/*
		* Preserve existing rows-per-page values.
		*/
		foreach ( $_GET as $key => $value ) { // phpcs:ignore WordPress.Security.NonceVerification.Recommended

			if (
				false === strpos(
					(string) $key,
					'per_page'
				)
			) {
				continue;
			}

			if ( ! is_scalar( $value ) ) {
				continue;
			}

			$safe_key = sanitize_key( $key );
			$raw      = sanitize_text_field(
				wp_unslash( $value )
			);

			$base_args[ $safe_key ] =
				'all' === $raw
					? 'all'
					: absint( $raw );
		}

		/*
		* Preserve explicitly requested tab/filter state.
		*/
		if ( is_array( $extra_args ) ) {

			foreach ( $extra_args as $key => $value ) {

				if ( ! is_scalar( $value ) ) {
					continue;
				}

				$safe_key = sanitize_key( $key );

				if ( '' === $safe_key ) {
					continue;
				}

				$base_args[ $safe_key ] =
					sanitize_text_field(
						(string) $value
					);
			}
		}

		echo '<div class="srk-il-pagination-actions">';

		if ( $page > 1 ) {

			$previous_url = add_query_arg(
				array_merge(
					$base_args,
					array(
						$page_arg => $page - 1,
					)
				),
				admin_url( 'admin.php' )
			);

			echo '<a class="button-link srk-il-page-arrow" href="' .
				esc_url( $previous_url ) .
				'"><span class="dashicons dashicons-arrow-left-alt2"></span></a>';
		}

		echo '<span class="srk-il-page-status">' .
			esc_html(
				sprintf(
					__(
						'Page %1$d of %2$d',
						'seo-repair-kit'
					),
					$page,
					$total_page
				)
			) .
			'</span>';

		if ( $page < $total_page ) {

			$next_url = add_query_arg(
				array_merge(
					$base_args,
					array(
						$page_arg => $page + 1,
					)
				),
				admin_url( 'admin.php' )
			);

			echo '<a class="button-link srk-il-page-arrow" href="' .
				esc_url( $next_url ) .
				'"><span class="dashicons dashicons-arrow-right-alt2"></span></a>';
		}

		echo '</div>';
	}
	
}
	/**
	 * Bootstrap Internal Linking runtime on operational requests.
	 *
	 * Admin UI still uses SeoRepairKit_InternalLinking::get_instance(),
	 * while REST/AJAX/Cron requests need backend services without
	 * rendering the admin coordinator.
	 */
	add_action(
		'init',
		static function () {

			$is_ajax = function_exists( 'wp_doing_ajax' )
				&& wp_doing_ajax();

			$is_cron = function_exists( 'wp_doing_cron' )
				&& wp_doing_cron();

			if (
				is_admin() ||
				$is_ajax ||
				$is_cron
			) {
				SeoRepairKit_InternalLinking::bootstrap_runtime();
			}
		},
		1
	);

	/**
	 * Gutenberg saves posts through the WordPress REST API.
	 *
	 * REST_REQUEST is established later than normal plugin loading,
	 * therefore bootstrap specifically on rest_api_init.
	 */
	add_action(
		'rest_api_init',
		array(
			'SeoRepairKit_InternalLinking',
			'bootstrap_runtime',
		),
		1
	);
