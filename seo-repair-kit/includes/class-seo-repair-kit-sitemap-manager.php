<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * SEO Repair Kit Sitemap Manager
 *
 * Controls WordPress core sitemap dynamically:
 * - Post type sitemap sections
 * - Taxonomy sitemap sections
 *
 * Works with:
 * /wp-sitemap.xml
 *
 * @since 2.1.5
 */
class SeoRepairKit_Sitemap_Manager {

	/**
	 * Option key.
	 */
	const OPTION_KEY = 'srk_sitemap_manager_settings';

	/**
	 * Singleton instance.
	 *
	 * @var SeoRepairKit_Sitemap_Manager|null
	 */
	private static $instance = null;

	/**
	 * Get instance.
	 *
	 * @return SeoRepairKit_Sitemap_Manager
	 */
	public static function get_instance() {
		if ( null === self::$instance ) {
			self::$instance = new self();
		}

		return self::$instance;
	}

	/**
	 * Constructor.
	 */
	private function __construct() {
		add_action( 'admin_init', array( $this, 'register_settings' ) );

		/**
		 * Important:
		 * These hooks must run on every request, including frontend sitemap requests.
		 */
		add_filter( 'wp_sitemaps_post_types', array( $this, 'filter_wp_core_sitemap_post_types' ), 100 );
		add_filter( 'wp_sitemaps_taxonomies', array( $this, 'filter_wp_core_sitemap_taxonomies' ), 100 );
	}

	/**
	 * Register settings.
	 */
	public function register_settings() {
		register_setting(
			'srk_sitemap_manager_group',
			self::OPTION_KEY,
			array( $this, 'sanitize_settings' )
		);
	}

	/**
	 * Sanitize settings before save.
	 *
	 * @param array $input Raw input.
	 * @return array
	 */
	public function sanitize_settings( $input ) {
		$existing_settings = $this->get_settings();

		$output = array(
			'enabled'             => 0,
			'included_post_types' => array(),
			'included_taxonomies' => array(),
		);

		if ( ! is_array( $input ) ) {
			return $existing_settings;
		}

		$output['enabled'] = ! empty( $input['enabled'] ) ? 1 : 0;

		if ( ! empty( $input['included_post_types'] ) && is_array( $input['included_post_types'] ) ) {
			$output['included_post_types'] = array_values(
				array_unique(
					array_filter(
						array_map( 'sanitize_key', $input['included_post_types'] )
					)
				)
			);
		}

		if ( ! empty( $input['included_taxonomies'] ) && is_array( $input['included_taxonomies'] ) ) {
			$output['included_taxonomies'] = array_values(
				array_unique(
					array_filter(
						array_map( 'sanitize_key', $input['included_taxonomies'] )
					)
				)
			);
		}

		/**
		 * Prevent saving sitemap settings if user did not enable the main toggle.
		 */
		if ( 0 === (int) $output['enabled'] ) {
			add_settings_error(
				self::OPTION_KEY,
				'srk_sitemap_manager_enable_required',
				__( 'Please enable "Enable Sitemap Manager" before saving sitemap settings.', 'seo-repair-kit' ),
				'error'
			);

			return $existing_settings;
		}

		return $output;
	}

	/**
	 * Get settings with defaults.
	 *
	 * @return array
	 */
	public function get_settings() {
		$defaults = array(
			'enabled'             => 0,
			'included_post_types' => array(),
			'included_taxonomies' => array(),
		);

		$saved = get_option( self::OPTION_KEY, array() );

		if ( ! is_array( $saved ) ) {
			$saved = array();
		}

		return wp_parse_args( $saved, $defaults );
	}

	/**
	 * Dynamically get available post types for current website.
	 *
	 * @return array
	 */
	public function get_available_post_types() {
		$post_types = get_post_types(
			array(
				'public' => true,
			),
			'objects'
		);

		// Ensure Media is available if registered.
		if ( ! isset( $post_types['attachment'] ) ) {
			$attachment_object = get_post_type_object( 'attachment' );
			if ( $attachment_object ) {
				$post_types['attachment'] = $attachment_object;
			}
		}

		$exclude_from_ui = array(
			'revision',
			'nav_menu_item',
			'custom_css',
			'customize_changeset',
			'oembed_cache',
			'user_request',
			'wp_block',
			'wp_template',
			'wp_template_part',
			'wp_global_styles',
			'wp_navigation',
			'wp_font_family',
			'wp_font_face',
		);

		foreach ( $exclude_from_ui as $post_type_key ) {
			if ( isset( $post_types[ $post_type_key ] ) ) {
				unset( $post_types[ $post_type_key ] );
			}
		}

		uasort(
			$post_types,
			function( $a, $b ) {
				$a_label = isset( $a->labels->name ) ? $a->labels->name : $a->name;
				$b_label = isset( $b->labels->name ) ? $b->labels->name : $b->name;
				return strcasecmp( $a_label, $b_label );
			}
		);

		return $post_types;
	}

	/**
	 * Dynamically get available taxonomies for current website.
	 *
	 * @return array
	 */
	public function get_available_taxonomies() {
		$taxonomies = get_taxonomies(
			array(
				'public' => true,
			),
			'objects'
		);

		$exclude_from_ui = array(
			'post_format',
			'nav_menu',
			'link_category',
		);

		foreach ( $exclude_from_ui as $taxonomy_key ) {
			if ( isset( $taxonomies[ $taxonomy_key ] ) ) {
				unset( $taxonomies[ $taxonomy_key ] );
			}
		}

		uasort(
			$taxonomies,
			function( $a, $b ) {
				$a_label = isset( $a->labels->name ) ? $a->labels->name : $a->name;
				$b_label = isset( $b->labels->name ) ? $b->labels->name : $b->name;
				return strcasecmp( $a_label, $b_label );
			}
		);

		return $taxonomies;
	}

	/**
	 * Filter WordPress core sitemap post types.
	 *
	 * Include-only logic:
	 * If feature enabled, only selected post types remain.
	 *
	 * @param array $post_types Post type objects.
	 * @return array
	 */
	public function filter_wp_core_sitemap_post_types( $post_types ) {
		$settings = $this->get_settings();

		if ( empty( $settings['enabled'] ) ) {
			return $post_types;
		}

		$included_post_types = isset( $settings['included_post_types'] ) && is_array( $settings['included_post_types'] )
			? $settings['included_post_types']
			: array();

		// If enabled and nothing selected, exclude all post type sitemap sections.
		if ( empty( $included_post_types ) ) {
			return array();
		}

		foreach ( $post_types as $post_type_key => $post_type_object ) {
			if ( ! in_array( $post_type_key, $included_post_types, true ) ) {
				unset( $post_types[ $post_type_key ] );
			}
		}

		return $post_types;
	}

	/**
	 * Filter WordPress core sitemap taxonomies.
	 *
	 * Include-only logic:
	 * If feature enabled, only selected taxonomies remain.
	 *
	 * @param array $taxonomies Taxonomy objects.
	 * @return array
	 */
	public function filter_wp_core_sitemap_taxonomies( $taxonomies ) {
		$settings = $this->get_settings();

		if ( empty( $settings['enabled'] ) ) {
			return $taxonomies;
		}

		$included_taxonomies = isset( $settings['included_taxonomies'] ) && is_array( $settings['included_taxonomies'] )
			? $settings['included_taxonomies']
			: array();

		// If enabled and nothing selected, exclude all taxonomy sitemap sections.
		if ( empty( $included_taxonomies ) ) {
			return array();
		}

		foreach ( $taxonomies as $taxonomy_key => $taxonomy_object ) {
			if ( ! in_array( $taxonomy_key, $included_taxonomies, true ) ) {
				unset( $taxonomies[ $taxonomy_key ] );
			}
		}

		return $taxonomies;
	}

	/**
	 * Get dynamic sitemap URLs for the current site.
	 *
	 * @return array
	 */
	private function get_sitemap_urls() {
		return array(
			'core'       => home_url( '/wp-sitemap.xml' ),
			'permalinks' => admin_url( 'options-permalink.php' ),
		);
	}

	/**
	 * Check whether WordPress core sitemap support exists.
	 *
	 * @return bool
	 */
	private function is_core_sitemap_enabled() {
		return function_exists( 'wp_sitemaps_get_server' );
	}

	/**
	 * Get selected count safely.
	 *
	 * @param array $items Selected items.
	 * @return int
	 */
	private function get_selected_count( $items ) {
		return is_array( $items ) ? count( $items ) : 0;
	}

	/**
	 * Render a top info box.
	 *
	 * @param string $title Box title.
	 * @param string $description Box description.
	 * @param string $url Optional URL.
	 */
	private function render_info_box( $title, $description, $url = '' ) {
		?>
		<div class="srk-sitemap-top-box">
			<h3><?php echo esc_html( $title ); ?></h3>
			<p><?php echo esc_html( $description ); ?></p>
			<?php if ( ! empty( $url ) ) : ?>
				<a class="srk-sitemap-url" href="<?php echo esc_url( $url ); ?>" target="_blank" rel="noopener noreferrer">
					<?php echo esc_html( $url ); ?>
				</a>
			<?php endif; ?>
		</div>
		<?php
	}

	/**
	 * Render a styled notice block.
	 *
	 * @param string $type info|warning|success
	 * @param string $title Notice title.
	 * @param string $message Notice message.
	 * @param string $extra_html Optional extra HTML.
	 */
	private function render_notice( $type, $title, $message, $extra_html = '' ) {
		$allowed_types = array( 'info', 'warning', 'success' );
		$type          = in_array( $type, $allowed_types, true ) ? $type : 'info';

		$icon = 'info';
		if ( 'warning' === $type ) {
			$icon = 'warning';
		} elseif ( 'success' === $type ) {
			$icon = 'yes-alt';
		}
		?>
		<div class="srk-sitemap-notice srk-sitemap-notice-<?php echo esc_attr( $type ); ?>">
			<span class="dashicons dashicons-<?php echo esc_attr( $icon ); ?>"></span>
			<div class="srk-sitemap-notice-content">
				<strong><?php echo esc_html( $title ); ?></strong>
				<p><?php echo esc_html( $message ); ?></p>
				<?php
				if ( ! empty( $extra_html ) ) {
					echo wp_kses_post( $extra_html );
				}
				?>
			</div>
		</div>
		<?php
	}

	/**
	 * Render checkbox grid items.
	 *
	 * @param array  $items Available objects.
	 * @param array  $selected Selected keys.
	 * @param string $field_name Field name.
	 * @param string $empty_text Empty text.
	 */
	private function render_checkbox_grid( $items, $selected, $field_name, $empty_text ) {
		if ( empty( $items ) ) {
			?>
			<p><?php echo esc_html( $empty_text ); ?></p>
			<?php
			return;
		}

		foreach ( $items as $item_key => $item_object ) {
			$label = isset( $item_object->labels->name ) ? $item_object->labels->name : $item_key;
			?>
			<label class="srk-sitemap-checkbox-item">
				<input
					type="checkbox"
					name="<?php echo esc_attr( self::OPTION_KEY . '[' . $field_name . '][]' ); ?>"
					value="<?php echo esc_attr( $item_key ); ?>"
					<?php checked( in_array( $item_key, $selected, true ) ); ?>
				/>
				<span class="srk-sitemap-checkbox-custom"></span>
				<span class="srk-sitemap-checkbox-label">
					<span class="srk-sitemap-checkbox-title"><?php echo esc_html( $label ); ?></span>
					<span class="srk-sitemap-checkbox-slug"><?php echo esc_html( $item_key ); ?></span>
				</span>
			</label>
			<?php
		}
	}

	/**
	 * Render admin page.
	 */
	public function srk_render_sitemap_manager_page() {
		if ( ! current_user_can( 'manage_options' ) ) {
			return;
		}

		$settings            = $this->get_settings();
		$enabled             = ! empty( $settings['enabled'] );
		$included_post_types = isset( $settings['included_post_types'] ) ? (array) $settings['included_post_types'] : array();
		$included_taxonomies = isset( $settings['included_taxonomies'] ) ? (array) $settings['included_taxonomies'] : array();

		$post_types = $this->get_available_post_types();
		$taxonomies = $this->get_available_taxonomies();
		$urls       = $this->get_sitemap_urls();

		$post_type_count = $this->get_selected_count( $included_post_types );
		$taxonomy_count  = $this->get_selected_count( $included_taxonomies );
		$core_enabled    = $this->is_core_sitemap_enabled();
		?>
		<div class="wrap srk-sitemap-wrapper">
			<?php settings_errors( self::OPTION_KEY ); ?>

			<div class="srk-sitemap-hero">
				<div class="srk-sitemap-hero-content">
					<div class="srk-sitemap-hero-icon">
						<span class="dashicons dashicons-networking"></span>
					</div>

					<div class="srk-sitemap-hero-text">
						<h1><?php esc_html_e( 'Sitemap Manager', 'seo-repair-kit' ); ?></h1>
						<p><?php esc_html_e( 'Choose exactly which content types and taxonomies should appear in your WordPress core XML sitemap. This helps you include important content and leave out unnecessary items such as templates or internal content types.', 'seo-repair-kit' ); ?></p>
					</div>
				</div>

				<div class="srk-sitemap-top-grid">
					<?php
					$this->render_notice(
						'warning',
						__( 'If your sitemap is not opening', 'seo-repair-kit' ),
						__( 'Open Settings → Permalinks in WordPress and click Save Changes once. This refreshes permalink rules and often restores the core sitemap.', 'seo-repair-kit' ),
						'<p><a href="' . esc_url( $urls['permalinks'] ) . '">' . esc_html__( 'Open Permalink Settings', 'seo-repair-kit' ) . '</a></p>'
					);
					
					$this->render_info_box(
						__( 'WordPress Core Sitemap URL', 'seo-repair-kit' ),
						__( 'This is the sitemap controlled by this feature.', 'seo-repair-kit' ),
						$urls['core']
					);

					$this->render_info_box(
						__( 'Current Selection', 'seo-repair-kit' ),
						sprintf(
							/* translators: 1: number of selected post types, 2: number of selected taxonomies */
							__( '%1$d post types selected and %2$d taxonomies selected.', 'seo-repair-kit' ),
							(int) $post_type_count,
							(int) $taxonomy_count
						)
					);
					?>
				</div>

				<?php

				$this->render_notice(
					'info',
					__( 'How this works', 'seo-repair-kit' ),
					__( 'Check the items you want to keep in the sitemap. Unchecked items will be excluded from the WordPress core sitemap.', 'seo-repair-kit' )
				);

				if ( ! $core_enabled ) {
					$this->render_notice(
						'warning',
						__( 'Your website may not be displaying the WordPress core sitemap right now.', 'seo-repair-kit' ),
						__( 'Go to Settings → Permalinks and click Save Changes once. This usually refreshes WordPress rewrite rules and enables the core sitemap properly.', 'seo-repair-kit' ),
						'<p><a href="' . esc_url( $urls['permalinks'] ) . '">' . esc_html__( 'Open Permalink Settings', 'seo-repair-kit' ) . '</a></p>'
					);
				} else {
					$this->render_notice(
						'success',
						__( 'Good news', 'seo-repair-kit' ),
						__( 'WordPress core sitemap support is available on this site. You can control what is included or excluded below.', 'seo-repair-kit' )
					);
				}

				$this->render_notice(
					'warning',
					__( 'Important: Sitemap Scope', 'seo-repair-kit' ),
					__( 'This feature controls ONLY the default WordPress sitemap (wp-sitemap.xml). If you are using another SEO plugin, those plugins generate their own sitemaps, which are NOT affected by this setting.', 'seo-repair-kit' )
				);
				?>
			</div>

			<form method="post" action="options.php" class="srk-sitemap-form" id="srk-sitemap-manager-form">
				<?php settings_fields( 'srk_sitemap_manager_group' ); ?>

				<div class="srk-sitemap-grid">

					<div class="srk-sitemap-card">
						<div class="srk-sitemap-card-header">
							<div class="srk-sitemap-card-icon">
								<span class="dashicons dashicons-admin-settings"></span>
							</div>

							<div class="srk-sitemap-card-title">
								<h2><?php esc_html_e( 'Main Setting', 'seo-repair-kit' ); ?></h2>
								<p><?php esc_html_e( 'Turn this feature on to let SEO Repair Kit control which WordPress core sitemap sections are included.', 'seo-repair-kit' ); ?></p>
							</div>
						</div>

						<div class="srk-sitemap-card-body">
							<div class="srk-sitemap-toggle">
								<label class="srk-sitemap-toggle-switch">
									<input
										type="checkbox"
										name="<?php echo esc_attr( self::OPTION_KEY ); ?>[enabled]"
										value="1"
										<?php checked( $enabled ); ?>
									/>
									<span class="srk-sitemap-toggle-slider"></span>
								</label>

								<div class="srk-sitemap-toggle-content">
									<span class="srk-sitemap-toggle-title"><?php esc_html_e( 'Enable Sitemap Manager', 'seo-repair-kit' ); ?></span>
									<span class="srk-sitemap-toggle-description"><?php esc_html_e( 'When enabled, SEO Repair Kit will include only the items you select below in the WordPress core sitemap.', 'seo-repair-kit' ); ?></span>
								</div>
							</div>
						</div>
					</div>

					<div class="srk-sitemap-card">
						<div class="srk-sitemap-card-header">
							<div class="srk-sitemap-card-icon">
								<span class="dashicons dashicons-media-document"></span>
							</div>

							<div class="srk-sitemap-card-title">
								<h2><?php esc_html_e( 'Included Post Types', 'seo-repair-kit' ); ?></h2>
								<p><?php esc_html_e( 'Select the post types you want to keep in the sitemap. Example: Posts, Pages, Jobs, Products, Templates, or any public custom post types registered on this website.', 'seo-repair-kit' ); ?></p>
							</div>
						</div>

						<div class="srk-sitemap-card-body">
							<div class="srk-sitemap-selection-meta">
								<?php
								echo esc_html(
									sprintf(
										/* translators: %d: number of selected post types */
										_n( '%d post type selected.', '%d post types selected.', $post_type_count, 'seo-repair-kit' ),
										(int) $post_type_count
									)
								);
								?>
							</div>

							<div class="srk-sitemap-checkbox-grid">
								<?php
								$this->render_checkbox_grid(
									$post_types,
									$included_post_types,
									'included_post_types',
									__( 'No public post types were found on this website.', 'seo-repair-kit' )
								);
								?>
							</div>

							<div class="srk-sitemap-card-footer">
								<div class="srk-sitemap-footer-note">
									<span class="dashicons dashicons-info-outline"></span>
									<p><?php esc_html_e( 'Only the selected post types will remain in the WordPress core sitemap.', 'seo-repair-kit' ); ?></p>
								</div>
							</div>
						</div>
					</div>

					<div class="srk-sitemap-card">
						<div class="srk-sitemap-card-header">
							<div class="srk-sitemap-card-icon">
								<span class="dashicons dashicons-tag"></span>
							</div>

							<div class="srk-sitemap-card-title">
								<h2><?php esc_html_e( 'Included Taxonomies', 'seo-repair-kit' ); ?></h2>
								<p><?php esc_html_e( 'Select the taxonomies you want to keep in the sitemap. Example: Categories, Tags, Product Categories, Job Types, Locations, or any public custom taxonomies registered on this website.', 'seo-repair-kit' ); ?></p>
							</div>
						</div>

						<div class="srk-sitemap-card-body">
							<div class="srk-sitemap-selection-meta">
								<?php
								echo esc_html(
									sprintf(
										/* translators: %d: number of selected taxonomies */
										_n( '%d taxonomy selected.', '%d taxonomies selected.', $taxonomy_count, 'seo-repair-kit' ),
										(int) $taxonomy_count
									)
								);
								?>
							</div>

							<div class="srk-sitemap-checkbox-grid">
								<?php
								$this->render_checkbox_grid(
									$taxonomies,
									$included_taxonomies,
									'included_taxonomies',
									__( 'No public taxonomies were found on this website.', 'seo-repair-kit' )
								);
								?>
							</div>

							<div class="srk-sitemap-card-footer">
								<div class="srk-sitemap-footer-note">
									<span class="dashicons dashicons-info-outline"></span>
									<p><?php esc_html_e( 'Only the selected taxonomies will remain in the WordPress core sitemap.', 'seo-repair-kit' ); ?></p>
								</div>
							</div>
						</div>
					</div>

				</div>

				<div class="srk-sitemap-actions">
					<button type="submit" class="srk-sitemap-save-btn">
						<span class="dashicons dashicons-saved"></span>
						<?php esc_html_e( 'Save Sitemap Settings', 'seo-repair-kit' ); ?>
					</button>
				</div>
			</form>

			<script>
			document.addEventListener('DOMContentLoaded', function () {
				var form = document.getElementById('srk-sitemap-manager-form');

				if (!form) {
					return;
				}

				form.addEventListener('submit', function (event) {
					var enableField = form.querySelector('input[name="<?php echo esc_js( self::OPTION_KEY ); ?>[enabled]"]');

					if (!enableField || !enableField.checked) {
						event.preventDefault();
						alert('<?php echo esc_js( __( 'Please enable SEO Repair Kit Main Settings of Sitemap Manager before saving sitemap settings.', 'seo-repair-kit' ) ); ?>');
						return false;
					}
				});
			});
			</script>

		</div>
		<?php
	}
}