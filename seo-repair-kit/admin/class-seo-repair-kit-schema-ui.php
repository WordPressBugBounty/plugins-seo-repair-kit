<?php
/**
 * Schema UI Renderer for SEO Repair Kit
 *
 * @package     SEO_Repair_Kit
 * @subpackage  Schema
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Schema UI Renderer class for managing schema type configuration interface.
 *
 * This class handles the rendering of the Schema Manager admin interface,
 * including script enqueuing, feature locking based on license status,
 * and generating the interactive schema configuration panels. It provides
 * a comprehensive interface for mapping schema fields to post types and
 * previewing generated JSON-LD markup.
 *
 * @since 2.1.0
 */
class SeoRepairKit_SchemaUI {

	/**
	 * Get file version based on modification time for cache busting.
	 *
	 * @since 2.1.0
	 *
	 * @param string $rel_path Relative path to the file from plugin root.
	 * @return string|false File modification time or false on failure.
	 */
	private function v( $rel_path ) {
		return filemtime( plugin_dir_path( dirname( __FILE__ ) ) . $rel_path );
	}

	/**
	 * Get feature lock flags based on license status.
	 *
	 * Provides defense-in-depth by checking license status independently
	 * and returning granular permissions for various schema operations.
	 * This ensures that even if client-side checks are bypassed, server-side
	 * validations will prevent unauthorized actions.
	 *
	 * @since 2.1.0
	 *
	 * @return array Feature lock configuration with detailed permissions.
	 */
	private function get_feature_lock_flags(): array {
		$is_active = false;
		$is_expired = false;
		$reason = 'License inactive';

		if ( class_exists( 'SeoRepairKit_Admin' ) ) {
			$admin   = new SeoRepairKit_Admin( '', '' );
			$license = $admin->get_license_status( site_url() );
			$is_active = ( ! empty( $license['status'] ) && 'active' === $license['status'] );

			// ✅ Check if plan is expired even if status is active
			if ( $is_active && ! empty( $license['expires_at'] ) ) {
				$expiration = $license['expires_at'];
				$expires_ts = strtotime( $expiration );
				if ( $expires_ts ) {
					$now = current_time( 'timestamp' );
					$days_left = floor( ( $expires_ts - $now ) / DAY_IN_SECONDS );
					if ( $days_left < 0 ) {
						$is_expired = true;
						$is_active = false; // Treat expired as inactive
						$reason = 'License plan expired';
					}
				}
			}
		}

		return array(
			'enabled'     => ! $is_active || $is_expired, // true = locked.
			'can_save'    => $is_active && ! $is_expired,
			'can_preview' => $is_active && ! $is_expired,
			'can_map'     => $is_active && ! $is_expired,
			'can_validate' => $is_active && ! $is_expired, // ✅ New flag for validation
			'reason'      => $reason,
		);
	}

	/**
	 * Render the main Schema Manager interface.
	 *
	 * Enqueues all necessary scripts and styles, sets up JavaScript dependencies,
	 * and outputs the HTML structure for the three-panel schema configuration
	 * interface. Handles both licensed and unlicensed states appropriately.
	 *
	 * @since 2.1.0
	 *
	 * @return void
	 */
	public function render() {
		$this->enqueue_scripts();
		$this->enqueue_styles();
		$this->render_interface();
	}

	/**
	 * Enqueue all required scripts for the Schema Manager.
	 *
	 * Loads the base schema functionality first, then individual schema type modules,
	 * and finally the main manager script with proper dependency management.
	 * Includes localization of PHP data for JavaScript consumption.
	 *
	 * @since 2.1.0
	 *
	 * @return void
	 */
	private function enqueue_scripts() {
		// Enqueue base schema functionality.
		wp_enqueue_script(
			'srk-base-schema',
			plugin_dir_url( dirname( __FILE__ ) ) . 'admin/js/schemas/srk-base-schema.js',
			array( 'jquery' ),
			$this->v( 'admin/js/schemas/srk-base-schema.js' ),
			true
		);

		// Enqueue individual schema type modules.
		$schema_dependencies = $this->enqueue_schema_modules();

		// Enqueue main manager script with all dependencies.
		$this->enqueue_main_manager( $schema_dependencies );
		// Lightweight JSON copy script (depends on manager so it runs after).

		wp_enqueue_script(
			'srk-schema-copy',
			plugin_dir_url( dirname( __FILE__ ) ) . 'admin/js/srk-schema-copy.js',
			array( 'jquery', 'srk-schema-manager-script' ),
			$this->v( 'admin/js/srk-schema-copy.js' ),
			true
		);
	}

	/**
	 * Enqueue individual schema type modules.
	 *
	 * @since 2.1.1
	 *
	 * @return array List of schema script handles for dependency management.
	 */
	private function enqueue_schema_modules(): array {
		$schema_scripts = array(
			'srk-article-schema'           => 'admin/js/schemas/srk-article-news-blog.js',
			'srk-faq-schema'               => 'admin/js/schemas/srk-faq-schema.js',
			'srk-organization-schema'      => 'admin/js/schemas/srk-organization-schema.js',
			'srk-local-business-schema'    => 'admin/js/schemas/srk-local-business-schema.js',
			'srk-product-schema'           => 'admin/js/schemas/srk-product-schema.js',
			'srk-corporation-schema'       => 'admin/js/schemas/srk-corporation-schema.js',
			'srk-website-schema'           => 'admin/js/schemas/srk-website-schema.js',
			'srk-review-schema'            => 'admin/js/schemas/srk-review-schema.js',
			'srk-recipe-schema'            => 'admin/js/schemas/srk-recipe-schema.js',
			'srk-medicalcondition-schema'  => 'admin/js/schemas/srk-medicalcondition-schema.js',
			'srk-coming-soon-schemas'      => 'admin/js/schemas/srk-coming-soon-schemas.js',
			'srk-event-schema'             => 'admin/js/schemas/srk-event-schema.js',
			'srk-jobposting-schema'        => 'admin/js/schemas/srk-jobposting-schema.js',
			'srk-course-schema'            => 'admin/js/schemas/srk-course-schema.js',
			'srk-author-schema'            => 'admin/js/schemas/srk-author-schema.js',
		);

		$handles = array();
		foreach ( $schema_scripts as $handle => $path ) {
			wp_enqueue_script(
				$handle,
				plugin_dir_url( dirname( __FILE__ ) ) . $path,
				array( 'srk-base-schema' ),
				$this->v( $path ),
				true
			);
			$handles[] = $handle;
		}

		return $handles;
	}

	/**
	 * Enqueue the main schema manager script.
	 *
	 * @since 2.1.0
	 *
	 * @param array $dependencies Array of script handles this script depends on.
	 * @return void
	 */
	private function enqueue_main_manager( array $dependencies ) {
		$all_dependencies = array_merge( array( 'jquery', 'srk-base-schema' ), $dependencies );

		wp_enqueue_script(
			'srk-schema-manager-script',
			plugin_dir_url( dirname( __FILE__ ) ) . 'admin/js/seo-repair-kit-schema-manager.js',
			$all_dependencies,
			$this->v( 'admin/js/seo-repair-kit-schema-manager.js' ),
			true
		);

		$this->localize_script_data();
	}

	/**
	 * Localize PHP data for JavaScript consumption.
	 *
	 * Provides WordPress nonces, license status, site data, and configuration
	 * options to the JavaScript environment for secure AJAX operations and
	 * dynamic interface behavior.
	 *
	 * @since 2.1.0
	 *
	 * @return void
	 */
	private function localize_script_data() {
		$assignable_schemas = array(
			// Content & page-level schemas
			'article','blog_posting','news_article','faq','how_to','video_object','product','job_posting',
			'event','course','review','recipe','local_business','organization','corporation','reservation',
			'medical_condition','medical_web_page','website','author',
		);

		$feature_lock = $this->get_feature_lock_flags();
		$schema_nonce = wp_create_nonce( 'srk_schema_nonce' );
		$subscribe_url = class_exists( 'SRK_API_Client' )
			? SRK_API_Client::get_api_url(
				SRK_API_Client::ENDPOINT_SUBSCRIBE,
				array( 'domain' => site_url() )
			)
			: '#';

		wp_localize_script(
			'srk-schema-manager-script',
			'srk_ajax_object',
			array(
				'ajax_url'           => admin_url( 'admin-ajax.php' ),
				'admin_url'          => admin_url(),
				'home_url'           => home_url(),
				'assignable_schemas' => $assignable_schemas,
				'faq_nonce'          => wp_create_nonce( 'srk_faq_nonce' ),
				'schema_nonce'       => $schema_nonce,
				'feature_lock'       => $feature_lock,
				'subscribe_url'      => $subscribe_url,
				'pages'              => $this->get_all_pages(),
				'site_data'          => $this->get_site_data(),
			)
		);
	}

	/**
	 * Enqueue styles for the Schema Manager.
	 *
	 * @since 2.1.0
	 *
	 * @return void
	 */
	private function enqueue_styles() {
		wp_enqueue_style( 'srk-schema-manager-style' );
	}

	/**
	 * Render the main interface HTML structure.
	 *
	 * Outputs the three-column layout with schema selection, configuration,
	 * and preview panels. Includes the modal dialog for configuration conflicts
	 * and the JSON-LD preview container.
	 *
	 * @since 2.1.0
	 *
	 * @return void
	 */
	private function render_interface() {
		$assignable_schemas = array(
			// Content & page-level schemas
			'article','blog_posting','news_article','faq','how_to','video_object','product','job_posting',
			'event','course','review','recipe','local_business','organization','corporation','reservation',
			'medical_condition','medical_web_page','website','author',
		);
		?>
		<div class="srk-schema-manager">
			<!-- Configure Schemas -->
			<div class="srk-tab-content" id="srk-tab-configure">
			<div class="srk-schema-card">
				<div class="srk-schema-selection">
					<!-- Header Section: Keep as is -->
					<div class="srk-schema-card-header">
						<div>
							<h2 class="srk-section-title"><?php esc_html_e( 'Enable Schema Types', 'seo-repair-kit' ); ?></h2>
							<p class="srk-section-description"><?php esc_html_e( 'Select a schema type and map its fields to your post type.', 'seo-repair-kit' ); ?></p>
						</div>
						<form method="post" class="srk-inline-form">
							<?php wp_nonce_field( 'srk_clear_license_cache', 'srk_cc_nonce' ); ?>
							<input type="hidden" name="srk_clear_cache" value="1" />
							<button type="submit" class="srk-btn srk-btn-secondary">
								<?php esc_html_e( 'Clear License Cache', 'seo-repair-kit' ); ?>
							</button>
						</form>
					</div>

					<!-- Schemas Types Section: Full Width with Bootstrap-like Grid -->
					<div class="srk-schema-types-fullwidth">
						<div class="container-fluid">
							<div class="row">
								<div class="col-12">
									<div class="srk-schema-yaxis">
										<h3 class="srk-group-heading"><?php esc_html_e( 'Schemas Types', 'seo-repair-kit' ); ?></h3>  
										<div class="srk-schema-group">
											<ul class="srk-schema-list srk-schema-list-fullwidth">
												<?php
												foreach ( $assignable_schemas as $schema_key ) :
													$option_key      = "srk_schema_assignment_{$schema_key}";
													$existing_option = get_option( $option_key );

													// Normalize potential JSON-encoded options for backward compatibility.
													if ( is_string( $existing_option ) ) {
														$decoded = json_decode( $existing_option, true );
														if ( JSON_ERROR_NONE === json_last_error() && is_array( $decoded ) ) {
															$existing_option = $decoded;
														} else {
															$existing_option = array();
														}
													}

													if ( 'faq' === $schema_key ) {
														$faq_posts = get_posts(
															array(
																'post_type'      => 'any',
																'meta_key'       => 'srk_selected_schema_type',
																'meta_value'     => 'faq',
																'posts_per_page' => 1,
																'fields'         => 'ids',
															)
														);
														$is_done            = ! empty( $faq_posts );
														$post_type_assigned = $is_done ? 'Various' : '';
													} else {
														$is_done            = ! empty( $existing_option );
														$post_type_assigned = $is_done ? ( $existing_option['post_type'] ?? '' ) : '';
													}
													?>
													<li class="srk-schema-item <?php echo $is_done ? 'has-settings' : ''; ?>">
														<label class="srk-schema-checkbox">
															<input type="checkbox"
																   name="enabled_schemas[]"
																   value="<?php echo esc_attr( $schema_key ); ?>"
																   class="srk-schema-input"
																   <?php if ( $is_done ) : ?>
																	   data-configured="true"
																	   data-post-type="<?php echo esc_attr( $post_type_assigned ); ?>"
																   <?php endif; ?>>
															<span class="srk-schema-name"><?php echo esc_html( ucwords( str_replace( '_', ' ', $schema_key ) ) ); ?></span>
															<?php if ( $is_done ) : ?>
																<span class="srk-done-tag" aria-label="<?php esc_attr_e( 'Configured', 'seo-repair-kit' ); ?>">&#10003;</span>
															<?php endif; ?>
															<?php if ( $is_done ) : ?>
																<div class="srk-schema-actions">
																	<button type="button" 
																			class="srk-btn-edit-schema" 
																			data-schema="<?php echo esc_attr( $schema_key ); ?>"
																			aria-label="<?php esc_attr_e( 'Edit schema', 'seo-repair-kit' ); ?>"
																			title="<?php esc_attr_e( 'Edit', 'seo-repair-kit' ); ?>">
																		<span class="dashicons dashicons-edit"></span>
																	</button>
																	<button type="button" 
																			class="srk-btn-delete-schema" 
																			data-schema="<?php echo esc_attr( $schema_key ); ?>"
																			data-post-type="<?php echo esc_attr( $post_type_assigned ); ?>"
																			aria-label="<?php esc_attr_e( 'Delete schema', 'seo-repair-kit' ); ?>"
																			title="<?php esc_attr_e( 'Delete', 'seo-repair-kit' ); ?>">
																		<span class="dashicons dashicons-trash"></span>
																	</button>
																</div>
															<?php endif; ?>
														</label>
													</li>
												<?php endforeach; ?>
											</ul>
										</div>
									</div>
								</div>
							</div>
						</div>
					</div>

					<!-- Two-Column Layout: Quick Schema Setup Steps and JSON-LD Preview -->
					<div class="srk-schema-flex-container">
						<div class="srk-two-column-wrapper">
							<!-- Left Panel: Quick Schema Setup Steps (Expanded) -->
							<div class="srk-schema-config-wrapper" id="srk-schema-config-wrapper">
								<span class="srk-schema-guide">
									<?php esc_html_e( 'Quick Schema Setup Steps', 'seo-repair-kit' ); ?>
								</span>

								<ul class="srk-quick-steps">
									<li>
									<div>
										<p class="srk-step-title"><?php esc_html_e( 'Select a schema type', 'seo-repair-kit' ); ?></p>
										<p class="srk-step-desc"><?php esc_html_e( 'Pick from Article, FAQ, HowTo, Product, and more.', 'seo-repair-kit' ); ?></p>
									</div>
									</li>
									<li>
									<div>
										<p class="srk-step-title"><?php esc_html_e( 'Choose the post type', 'seo-repair-kit' ); ?></p>
										<p class="srk-step-desc"><?php esc_html_e( 'Apply the schema to Posts, Pages, or any CPT.', 'seo-repair-kit' ); ?></p>
									</div>
									</li>
									<li>
									<div>
										<p class="srk-step-title"><?php esc_html_e( 'Map fields to WordPress', 'seo-repair-kit' ); ?></p>
										<p class="srk-step-desc"><?php esc_html_e( 'Connect schema fields with meta fields or content.', 'seo-repair-kit' ); ?></p>
									</div>
									</li>
									<li>
									<div>
										<p class="srk-step-title"><?php esc_html_e( 'Preview & validate', 'seo-repair-kit' ); ?></p>
										<p class="srk-step-desc"><?php esc_html_e( 'Save your settings, preview JSON-LD, and test in Google\'s Rich Results or Schema Validator.', 'seo-repair-kit' ); ?></p>
									</div>
									</li>
								</ul>
							</div>

							<!-- Right Panel: Preview and Actions -->
							<div class="srk-schema-right-panel">
								<!-- Configuration Conflict Modal -->
								<div id="srk-schema-modal" class="srk-modal" style="display:none;">
									<div class="srk-modal-content">
										<div class="srk-modal-header">
											<h3><?php esc_html_e( 'Schema Already Configured', 'seo-repair-kit' ); ?></h3>
											<span class="srk-modal-close" role="button" tabindex="0" aria-label="<?php esc_attr_e( 'Close', 'seo-repair-kit' ); ?>">&times;</span>
										</div>
										<div class="srk-modal-body">
											<p id="srk-modal-message"></p>
										</div>
										<div class="srk-modal-footer">
											<button id="srk-modal-edit" class="button"><?php esc_html_e( 'Edit Configuration', 'seo-repair-kit' ); ?></button>
											<button id="srk-modal-cancel" class="button"><?php esc_html_e( 'Cancel', 'seo-repair-kit' ); ?></button>
										</div>
									</div>
								</div>

								<!-- Preview and Action Section -->
								<div class="srk-preview-wrapper">
									<h3 class="srk-headeing-h3"><?php esc_html_e( 'JSON-LD Preview — Save & Validate', 'seo-repair-kit' ); ?></h3>
									<div id="srk-json-preview-container" style="display:none;">
										<!-- ✅ NEW: Loader for JSON-LD Preview -->
										<div id="srk-json-preview-loader" class="srk-json-preview-loader" style="display: none; text-align: center; padding: 40px 20px; background: #f9fafb; border-radius: 8px; margin-bottom: 15px;">
											<div class="spinner is-active" style="float: none; margin: 0 auto 15px;"></div>
											<p style="margin: 0; color: #64748b; font-size: 14px; font-weight: 500;">
												<?php esc_html_e( 'Generating JSON-LD Preview...', 'seo-repair-kit' ); ?>
											</p>
										</div>
										<pre id="srk-json-preview" aria-live="polite" style="display: none;">{}</pre>
									</div>
									<div class="srk-actions">
										<button id="srk-save-schema-settings" class="button button-save-set"><?php esc_html_e( 'Save Schema', 'seo-repair-kit' ); ?></button>
										<a href="https://validator.schema.org" target="_blank" id="srk-validate-schema" class="button button-validate-schema" rel="noopener noreferrer">
											<?php esc_html_e( 'Validate Schema', 'seo-repair-kit' ); ?>
										</a>
										
										<!-- ✅ NEW: Google Rich Results Test button -->
										<a href="https://search.google.com/test/rich-results" target="_blank" id="srk-test-google-rich-results" class="button button-google-test" rel="noopener noreferrer"
											title="<?php esc_attr_e( 'Test with Google Rich Results Test', 'seo-repair-kit' ); ?>">
											<span class="dashicons dashicons-google" style="vertical-align: middle; margin-right: 4px;"></span>
											<?php esc_html_e( 'Test with Google', 'seo-repair-kit' ); ?>
										</a>
										
										<!-- NEW: Copy JSON button - TEMPORARILY HIDDEN -->
										<button id="srk-copy-json" class="button" disabled
											title="<?php esc_attr_e( 'Copy the JSON-LD preview to clipboard', 'seo-repair-kit' ); ?>"
											style="display:none;">
											<?php esc_html_e( 'Copy JSON', 'seo-repair-kit' ); ?>
										</button>

										<!-- inline status for copy feedback -->
										<span id="srk-copy-json-status" class="srk-inline-status" aria-live="polite" style="margin-left:8px; display:none;"></span>
										<div id="srk-save-status"></div>
									</div>
									
									<!-- ✅ NEW: Google Test Info/Status -->
									<div id="srk-google-test-info" class="srk-google-test-info" style="display:none; margin-top:10px; padding:10px; background:#f0f9ff; border-left:4px solid #4285f4; border-radius:4px;">
										<p style="margin:0; font-size:13px; color:#1a73e8;">
											<strong><?php esc_html_e( 'Google Rich Results Test:', 'seo-repair-kit' ); ?></strong>
											<span id="srk-google-test-message"></span>
										</p>
									</div>

									<!-- Feature Lock Notice -->
									<div id="srk-lock-notice" style="display:none; margin-top:10px;"></div>
								</div>
							</div>
						</div>
					</div>
				</div>
			</div>
			</div>
		</div>
		<?php
	}

	/**
	 * Get all published pages for dropdown selection.
	 *
	 * @since 2.1.0
	 *
	 * @return array List of pages with ID and title.
	 */
	private function get_all_pages() {
		$pages = get_posts(
			array(
				'post_type'   => 'page',
				'post_status' => 'publish',
				'numberposts' => -1,
				'orderby'     => 'title',
				'order'       => 'ASC',
			)
		);

		return array_map(
			function( $page ) {
				return array(
					'ID'         => $page->ID,
					'post_title' => $page->post_title,
				);
			},
			$pages
		);
	}

	/**
	 * Get site data for schema configuration.
	 *
	 * @since 2.1.0
	 *
	 * @return array Site information including name, URL, logo, etc.
	 */
	private function get_site_data() {
		$custom_logo_id = get_theme_mod( 'custom_logo' );
		$logo_url       = $custom_logo_id ? wp_get_attachment_url( $custom_logo_id ) : '';

		return array(
			'site_name'        => get_bloginfo( 'name' ),
			'site_url'         => home_url(),
			'logo_url'         => $logo_url,
			'admin_email'      => get_option( 'admin_email' ),
			'site_description' => get_bloginfo( 'description' ),
		);
	}
}