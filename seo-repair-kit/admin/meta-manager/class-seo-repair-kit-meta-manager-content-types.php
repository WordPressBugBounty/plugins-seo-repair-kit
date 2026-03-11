<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Content Types Meta Manager with Tag UI (Single Option Array)
 *
 * @package SEO_Repair_Kit
 * @since   2.1.3
 * @version 2.1.3
 */
class SRK_Meta_Manager_Content_Types {

	/**
	 * Available Template Tags with descriptions
	 *
	 * @var array
	 */
	private $template_tags = array(
		'Site Name'          => array(
			'tag'         => '%site_title%',
			'description' => 'The name of your website.',
		),
		'Page Title'         => array(
			'tag'         => '%title%',
			'description' => 'The title of the current page.',
		),
		'Site Description'   => array(
			'tag'         => '%sitedesc%',
			'description' => 'The tagline or description of your website.',
		),
		'Post Title'         => array(
			'tag'         => '%title%',
			'description' => 'The title of the current post or page.',
		),
		'Post Excerpt'       => array(
			'tag'         => '%excerpt%',
			'description' => 'The excerpt or summary of the post.',
		),
		'Separator'          => array(
			'tag'         => '%sep%',
			'description' => 'The title separator defined in global settings.',
		),
		'Author First Name'  => array(
			'tag'         => '%author_first_name%',
			'description' => 'The first name of the post author.',
		),
		'Author Last Name'   => array(
			'tag'         => '%author_last_name%',
			'description' => 'The last name of the post author.',
		),
		'Author Name'        => array(
			'tag'         => '%author_name%',
			'description' => 'The full display name of the post author.',
		),
		'Category Title'     => array(
			'tag'         => '%term_title%',
			'description' => 'The primary category of the post.',
		),
		'Current Date'       => array(
			'tag'         => '%date%',
			'description' => 'Today\'s date in full format.',
		),
		'Current Month'      => array(
			'tag'         => '%month%',
			'description' => 'The current month name.',
		),
		'Current Year'       => array(
			'tag'         => '%year%',
			'description' => 'The current year.',
		),
		'Custom Field'       => array(
			'tag'         => '%custom_field%',
			'description' => 'Custom field value (advanced feature).',
		),
		'Permalink'          => array(
			'tag'         => '%permalink%',
			'description' => 'The permanent URL of the post.',
		),
		'Post Content'       => array(
			'tag'         => '%content%',
			'description' => 'A trimmed excerpt of the post content.',
		),
		'Post Date'          => array(
			'tag'         => '%post_date%',
			'description' => 'The publication date of the post.',
		),
		'Post Day'           => array(
			'tag'         => '%post_day%',
			'description' => 'The day when the post was published.',
		),
	);

	/**
	 * Template tags relevant for each post type
	 *
	 * @var array
	 */
	private $template_tags_relevant = array();

	/**
	 * Excluded post types
	 *
	 * @var array
	 */
	private $excluded_post_types = array(
		'attachment',
		'revision',
		'nav_menu_item',
		'custom_css',
		'customize_changeset',
		'oembed_cache',
		'user_request',
		'wp_block',
		'acf-field-group',
		'acf-field',
		'elementor_library',
		'e-floating-buttons',
	);

	/**
	 * Constructor
	 */
	public function __construct() {
		$this->srk_meta_init_hooks();
	}

	/**
	 * Initialize hooks
	 */
	private function srk_meta_init_hooks() {
		add_action( 'admin_init', array( $this, 'srk_register_settings' ) );
	}

	/**
	 * Register settings
	 */
	public function srk_register_settings() {
		register_setting(
			'srk_meta_content_types_group',
			'srk_meta_content_types_settings',
			array(
				'type'              => 'array',
				'sanitize_callback' => array( $this, 'srk_sanitize_settings' ),
			)
		);
	}

	/**
	 * Sanitize settings
	 *
	 * @param array $input Input array.
	 * @return array Sanitized output.
	 */
	public function srk_sanitize_settings( $input ) {
		$output = array();
		if ( ! is_array( $input ) ) {
			return $output;
		}

		$all_post_types   = $this->get_allowed_post_types();
		$existing_options = get_option( 'srk_meta_content_types_settings', array() );

		foreach ( $all_post_types as $post_type_object ) {
			$post_type_name = $post_type_object->name;

			if ( isset( $input[ $post_type_name ] ) ) {
				$settings = $input[ $post_type_name ];

				$output[ $post_type_name ]['title'] = isset( $settings['title'] )
					? wp_kses_data( $settings['title'] )
					: '%title% %sep% %site_title%';

				$output[ $post_type_name ]['desc'] = isset( $settings['desc'] )
					? wp_kses_data( $settings['desc'] )
					: '%excerpt%';

				$existing_advanced = $existing_options[ $post_type_name ]['advanced'] ?? array();

				$advanced_input = isset( $settings['advanced'] ) && is_array( $settings['advanced'] )
					? $settings['advanced']
					: array();

				if ( array_key_exists( 'use_default_settings', $advanced_input ) ) {
					$use_default = '1';
				} else {
					if ( ! empty( $advanced_input ) ) {
						$use_default = '0';
					} else {
						$use_default = $existing_advanced['use_default_settings'] ?? '1';
					}
				}

				if ( '1' === $use_default ) {
					$robots_meta = $existing_advanced['robots_meta'] ?? $this->get_default_robots_meta();
				} else {
					if ( isset( $advanced_input['robots_meta'] ) && is_array( $advanced_input['robots_meta'] ) ) {
						$robots_meta = wp_parse_args(
							$advanced_input['robots_meta'],
							$this->get_default_robots_meta()
						);
					} else {
						$robots_meta = $existing_advanced['robots_meta'] ?? $this->get_default_robots_meta();
					}
				}

				$output[ $post_type_name ]['advanced'] = array(
					'use_default_settings' => $use_default,
					'robots_meta'          => $robots_meta,
				);
			} else {
				$output[ $post_type_name ] = array(
					'title'    => '%title% %sep% %site_title%',
					'desc'     => '%excerpt%',
					'advanced' => array(
						'use_default_settings' => '1',
						'show_meta_box'        => '1',
						'robots_meta'          => $this->get_default_robots_meta(),
					),
				);
			}
		}

		return $output;
	}

	/**
	 * Get all public post types except excluded ones
	 *
	 * @return array Allowed post types.
	 */
	private function get_allowed_post_types() {
		$all_post_types = get_post_types(
			array(
				'public'  => true,
				'show_ui' => true,
			),
			'objects'
		);

		$post_types = array();

		foreach ( $all_post_types as $post_type_name => $post_type_object ) {
			if ( in_array( $post_type_name, $this->excluded_post_types, true ) ) {
				continue;
			}

			$post_types[ $post_type_name ] = $post_type_object;
		}

		return $post_types;
	}

	/**
	 * Get default robots meta for post types
	 *
	 * @return array Default robots meta.
	 */
	private function get_default_robots_meta() {
		return array(
			'noindex'           => '0',
			'nofollow'          => '0',
			'noarchive'         => '0',
			'notranslate'       => '0',
			'noimageindex'      => '0',
			'nosnippet'         => '0',
			'noodp'             => '0',
			'max_snippet'       => -1,
			'max_video_preview' => -1,
			'max_image_preview' => 'large',
		);
	}

	/**
	 * Generate robots meta preview
	 *
	 * @param array $robots_meta Robots meta settings.
	 * @return string Robots directives string.
	 */
	private function generate_robots_preview( $robots_meta ) {
		$defaults    = $this->get_default_robots_meta();
		$robots_meta = wp_parse_args( is_array( $robots_meta ) ? $robots_meta : array(), $defaults );

		$directives   = array();
		$directives[] = ( ! empty( $robots_meta['noindex'] ) && '1' === $robots_meta['noindex'] ) ? 'noindex' : 'index';
		$directives[] = ( ! empty( $robots_meta['nofollow'] ) && '1' === $robots_meta['nofollow'] ) ? 'nofollow' : 'follow';

		if ( ! empty( $robots_meta['noarchive'] ) && '1' === $robots_meta['noarchive'] ) {
			$directives[] = 'noarchive';
		}
		if ( ! empty( $robots_meta['notranslate'] ) && '1' === $robots_meta['notranslate'] ) {
			$directives[] = 'notranslate';
		}
		if ( ! empty( $robots_meta['noimageindex'] ) && '1' === $robots_meta['noimageindex'] ) {
			$directives[] = 'noimageindex';
		} elseif ( ! empty( $robots_meta['max_image_preview'] ) ) {
			$directives[] = 'max-image-preview:' . sanitize_text_field( $robots_meta['max_image_preview'] );
		} else {
			$directives[] = 'max-image-preview:large';
		}
		if ( ! empty( $robots_meta['nosnippet'] ) && '1' === $robots_meta['nosnippet'] ) {
			$directives[] = 'nosnippet';
		} elseif ( isset( $robots_meta['max_snippet'] ) && -1 !== (int) $robots_meta['max_snippet'] ) {
			$directives[] = 'max-snippet:' . (int) $robots_meta['max_snippet'];
		}
		if ( ! empty( $robots_meta['noodp'] ) && '1' === $robots_meta['noodp'] ) {
			$directives[] = 'noodp';
		}

		if ( isset( $robots_meta['max_video_preview'] ) && -1 !== (int) $robots_meta['max_video_preview'] ) {
			$directives[] = 'max-video-preview:' . (int) $robots_meta['max_video_preview'];
		}

		return implode( ', ', $directives );
	}

	/**
	 * Get template tags with dynamic post type tags
	 *
	 * @return array Template tags.
	 */
	private function get_template_tags_with_post_types() {
		$template_tags = $this->template_tags;

		$post_types = $this->get_allowed_post_types();

		foreach ( $post_types as $post_type ) {
			$post_type_name  = $post_type->name;
			$post_type_label = $post_type->label;

			$template_tags[ $post_type_label . ' Title' ] = array(
				'tag'         => '%title%',
				'description' => 'The title of the current ' . strtolower( $post_type_label ),
			);

			$template_tags[ $post_type_label . ' Excerpt' ] = array(
				'tag'         => '%excerpt%',
				'description' => 'The excerpt or summary of the ' . strtolower( $post_type_label ),
			);

			$template_tags[ $post_type_label . ' Date' ] = array(
				'tag'         => '%' . $post_type_name . '_date%',
				'description' => 'The publication date of the ' . strtolower( $post_type_label ),
			);

			$template_tags[ $post_type_label . ' Day' ] = array(
				'tag'         => '%' . $post_type_name . '_day%',
				'description' => 'The day when the ' . strtolower( $post_type_label ) . ' was published',
			);

			$template_tags[ $post_type_label . ' Content' ] = array(
				'tag'         => '%content%',
				'description' => 'The content of the ' . strtolower( $post_type_label ),
			);
		}

		return $template_tags;
	}

	/**
	 * Get relevant tags for a specific post type
	 *
	 * @param object $post_type Post type object.
	 * @return array Relevant tags.
	 */
	private function get_relevant_tags_for_post_type( $post_type ) {
		$post_type_name  = $post_type->name;
		$post_type_label = $post_type->label;

		return array(
			'title'       => array(
				$post_type_label . ' Title' => '%title%',
				'Site Name'                   => '%site_title%',
				'Separator'                   => '%sep%',
			),
			'description' => array(
				$post_type_label . ' Excerpt' => '%excerpt%',
				$post_type_label . ' Title'   => '%title%',
				'Site Description'            => '%sitedesc%',
			),
		);
	}

	/**
	 * Generate preview
	 *
	 * @param string $title_template Title template.
	 * @param string $desc_template  Description template.
	 * @param string $post_type      Post type.
	 * @return string Preview HTML.
	 */
	private function generate_preview( $title_template, $desc_template, $post_type = 'post' ) {
		$site_url     = get_site_url();
		$site_name    = get_bloginfo( 'name' );
		$site_desc    = get_bloginfo( 'description' );
		$post_title   = 'Sample ' . ucfirst( $post_type ) . ' Title';
		$post_excerpt = 'Sample excerpt from a ' . $post_type . '.';
		$page_title   = 'Sample Page Title';

		$separator = get_option( 'srk_title_separator', '-' );

		$current_user      = wp_get_current_user();
		$author_first_name = $current_user->first_name ?: 'John';
		$author_last_name  = $current_user->last_name ?: 'Doe';
		$author_name       = $current_user->display_name ?: 'John Doe';

		$sample_post = get_posts(
			array(
				'numberposts' => 1,
				'post_status' => 'publish',
				'post_type'   => $post_type,
			)
		);

		$categories     = 'Uncategorized';
		$category_title = 'Uncategorized';

		if ( ! empty( $sample_post ) ) {
			$post_id = $sample_post[0]->ID;

			if ( taxonomy_exists( 'category' ) && post_type_supports( $post_type, 'category' ) ) {
				$post_categories = get_the_category( $post_id );
				if ( ! empty( $post_categories ) ) {
					$cat_names  = wp_list_pluck( $post_categories, 'name' );
					$categories = implode( ', ', $cat_names );
					$category_title = $post_categories[0]->name;
				}
			} elseif ( taxonomy_exists( $post_type . '_category' ) ) {
				$post_categories = get_the_terms( $post_id, $post_type . '_category' );
				if ( ! empty( $post_categories ) && ! is_wp_error( $post_categories ) ) {
					$cat_names  = wp_list_pluck( $post_categories, 'name' );
					$categories = implode( ', ', $cat_names );
					$category_title = $post_categories[0]->name;
				}
			}
		}

		$current_date = date_i18n( 'F j, Y' );
		$current_month = date_i18n( 'F' );
		$current_year  = date_i18n( 'Y' );
		$custom_field  = 'Custom Field Value';
		$permalink     = $site_url . '/sample-' . $post_type . '/';
		$post_content  = 'Sample ' . $post_type . ' content text...';
		$post_date     = date_i18n( 'F j, Y', strtotime( '-7 days' ) );
		$post_day      = date_i18n( 'd', strtotime( '-7 days' ) );

		$replacements = array(
			'%sep%'                => $separator,
			'%title%'              => $post_title,
			'%excerpt%'            => $post_excerpt,
			'%site_title%'         => $site_name,
			'%sitedesc%'           => $site_desc,
			'%author_first_name%'  => $author_first_name,
			'%author_last_name%'   => $author_last_name,
			'%author_name%'        => $author_name,
			'%term_title%'           => $category_title,
			'%date%'               => $current_date,
			'%month%'              => $current_month,
			'%year%'               => $current_year,
			'%custom_field%'       => $custom_field,
			'%permalink%'          => $permalink,
			'%content%'            => $post_content,
			'%post_date%'          => $post_date,
			'%post_day%'           => $post_day,
		);

		$title_preview = str_replace( array_keys( $replacements ), array_values( $replacements ), $title_template );
		$desc_preview  = str_replace( array_keys( $replacements ), array_values( $replacements ), $desc_template );

		$preview  = "<div style='padding: 10px; background: #f9f9f9; border: 1px solid #ddd; border-radius: 4px; margin-top: 5px;'>";
		$preview .= "<div style='color: #70757a; font-size: 12px; line-height: 1.4; margin-bottom: 5px;'>{$site_url}</div>";
		$preview .= "<div style='color: #1a0dab; font-size: 16px; line-height: 1.4; margin-bottom: 3px;'>{$title_preview}</div>";
		$preview .= "<div style='color: #3c4043; font-size: 13px; line-height: 1.4;'>{$desc_preview}</div>";
		$preview .= '</div>';

		return $preview;
	}

	/**
	 * Render the Content Types tab
	 */
	public function render() {
		$all_options = get_option( 'srk_meta_content_types_settings', array() );
		?>

		<div class="wrap srk-content-meta-manager">
			<h2><?php esc_html_e( 'Content Types Settings', 'seo-repair-kit' ); ?></h2>

			<p><?php esc_html_e( 'Configure SEO settings for your content types.', 'seo-repair-kit' ); ?></p>

			<?php if ( isset( $_GET['settings-updated'] ) && filter_var( wp_unslash( $_GET['settings-updated'] ), FILTER_VALIDATE_BOOLEAN ) ) : ?>
				<div class="notice notice-success is-dismissible">
					<p><?php esc_html_e( 'Settings saved successfully!', 'seo-repair-kit' ); ?></p>
				</div>
			<?php endif; ?>

			<form method="post" action="options.php">
				<?php settings_fields( 'srk_meta_content_types_group' ); ?>

				<table class="form-table">
					<tr>
						<td>
							<fieldset>
								<?php
								$srk_post_types = $this->get_allowed_post_types();

								foreach ( $srk_post_types as $srk_post_type ) :
									$post_type_key = $srk_post_type->name;
									$active_tab    = isset( $_GET[ 'post_type_tab_' . $post_type_key ] )
										? sanitize_key( wp_unslash( $_GET[ 'post_type_tab_' . $post_type_key ] ) )
										: 'title-description';

									$default_title = '%title% %sep% %site_title%';
									$default_desc  = '%excerpt%';

									$title_value  = $all_options[ $post_type_key ]['title'] ?? $default_title;
									$desc_value   = $all_options[ $post_type_key ]['desc'] ?? $default_desc;

									$preview_html = $this->generate_preview( $title_value, $desc_value, $post_type_key );

									$advanced_settings    = $all_options[ $post_type_key ]['advanced'] ?? array();
									$use_default_settings = $advanced_settings['use_default_settings'] ?? '1';

									$robots_meta = isset( $advanced_settings['robots_meta'] ) && is_array( $advanced_settings['robots_meta'] )
										? $advanced_settings['robots_meta']
										: $this->get_default_robots_meta();
									$robots_meta    = wp_parse_args( $robots_meta, $this->get_default_robots_meta() );
									$robots_preview = $this->generate_robots_preview( $robots_meta );

									$relevant_tags = $this->get_relevant_tags_for_post_type( $srk_post_type );
									?>

									<div class="srk-post-type-wrapper" data-post-type="<?php echo esc_attr( $post_type_key ); ?>">
										<div class="srk-post-type-header">
											<h3 class="srk-post-type-title">
												<?php echo esc_html( $srk_post_type->label ); ?>
											</h3>
											<span class="srk-accordion-icon dashicons dashicons-arrow-right-alt2"></span>
										</div>

										<div class="srk-content-tabs-nav">
											<a href="?page=seo-repair-kit-meta-manager&tab=content-types&post_type_tab_<?php echo esc_attr( $post_type_key ); ?>=title-description"
												class="srk-content-tab <?php echo 'title-description' === $active_tab ? 'active' : ''; ?>">
												<?php esc_html_e( 'Title & Description', 'seo-repair-kit' ); ?>
											</a>
											<a href="?page=seo-repair-kit-meta-manager&tab=content-types&post_type_tab_<?php echo esc_attr( $post_type_key ); ?>=advanced"
												class="srk-content-tab <?php echo 'advanced' === $active_tab ? 'active' : ''; ?>">
												<?php esc_html_e( 'Advanced Robots Meta', 'seo-repair-kit' ); ?>
											</a>
										</div>

										<div class="srk-content-tabs-content">
											<div class="srk-tab-pane <?php echo 'title-description' === $active_tab ? 'active' : ''; ?>" data-tab="title-description">
												<div class="srk-preview-wrapper">
													<p><strong><?php esc_html_e( 'Preview:', 'seo-repair-kit' ); ?></strong></p>
													<div id="preview-<?php echo esc_attr( $post_type_key ); ?>" class="srk-meta-preview">
														<?php
														echo wp_kses(
															$preview_html,
															array(
																'div' => array(
																	'class' => true,
																	'id'    => true,
																	'style' => true,
																),
															)
														);
														?>
													</div>
												</div>

												<label class="srk-field-title">
													<strong>
														<?php
														printf(
															esc_html__( 'Meta Title for single %s', 'seo-repair-kit' ),
															esc_html( $srk_post_type->labels->singular_name )
														);
														?>
													</strong>
												</label>
												<div class="srk-field-example srk-important">
													<p class="srk-example-desc">
														<?php
														printf(
															esc_html__( 'Default title format for single %s entries. You can override it per item in the post editor.', 'seo-repair-kit' ),
															esc_html( strtolower( $srk_post_type->labels->singular_name ) )
														);
														?>
													</p>
												</div>
												<div class="srk-tag-input-wrapper">
													<div class="srk-relevant-tags srk-title-tags">
														<?php foreach ( $relevant_tags['title'] as $label => $tag ) : ?>
															<span class="srk-tag srk-tag-btn" data-tag="<?php echo esc_attr( $tag ); ?>">
																<span class="srk-tag-icon">+</span> <?php echo esc_html( $label ); ?>
															</span>
														<?php endforeach; ?>
														<a href="+" class="srk-view-all-tags" data-target="title">
															<?php esc_html_e( 'View all tags →', 'seo-repair-kit' ); ?>
														</a>
														<br><br>
													</div>

													<input type="text"
														name="srk_meta_content_types_settings[<?php echo esc_attr( $post_type_key ); ?>][title]"
														class="srk-title-input regular-text"
														data-post-type="<?php echo esc_attr( $post_type_key ); ?>"
														value="<?php echo esc_attr( $title_value ); ?>" />
												</div>

												<label class="srk-field-title">
													<strong>
														<?php
														printf(
															esc_html__( 'Meta Description for single %s', 'seo-repair-kit' ),
															esc_html( $srk_post_type->labels->singular_name )
														);
														?>
													</strong>
												</label>
												<div class="srk-field-example srk-important">
													<p class="srk-example-desc">
														<?php
														printf(
															esc_html__( 'Default meta description for single %s entries. You can override it per item in the post editor.', 'seo-repair-kit' ),
															esc_html( strtolower( $srk_post_type->labels->singular_name ) )
														);
														?>
													</p>
												</div>
												<div class="srk-tag-input-wrapper">
													<div class="srk-relevant-tags srk-desc-tags" style="margin-top:6px;">
														<?php foreach ( $relevant_tags['description'] as $label => $tag ) : ?>
															<span class="srk-tag srk-tag-btn" data-tag="<?php echo esc_attr( $tag ); ?>" style="cursor:pointer; background:#eee; margin-right:4px; border-radius:3px;">
																<span class="srk-tag-icon">+</span>
																<?php echo esc_html( $label ); ?>
															</span>
														<?php endforeach; ?>
														<a href="+" class="srk-view-all-tags" data-target="description">
															<?php esc_html_e( 'View all tags →', 'seo-repair-kit' ); ?>
														</a>
														<br><br>
													</div>

													<input type="text"
														name="srk_meta_content_types_settings[<?php echo esc_attr( $post_type_key ); ?>][desc]"
														class="srk-desc-input regular-text"
														data-post-type="<?php echo esc_attr( $post_type_key ); ?>"
														value="<?php echo esc_attr( $desc_value ); ?>" />
												</div>
											</div>

											<div class="srk-tab-pane <?php echo 'advanced' === $active_tab ? 'active' : ''; ?>" data-tab="advanced">
												<div class="srk-default-mode-message">
													<strong><?php esc_html_e( 'How These Settings Work', 'seo-repair-kit' ); ?></strong>
													<p>
														<?php esc_html_e( 'These robots meta settings apply only to this content type (e.g., Posts, Pages, or Custom Post Types).', 'seo-repair-kit' ); ?>
													</p>
													<ul>
														<li><?php esc_html_e( 'If "Use Default Settings" is enabled, the global robots directives from Advanced Settings will apply.', 'seo-repair-kit' ); ?></li>
														<li><?php esc_html_e( 'If disabled, the custom robots directives below will override the global settings for this content type only.', 'seo-repair-kit' ); ?></li>
													</ul>
												</div>

												<div class="srk-advanced-section">
													<div class="srk-field-group">
														<div class="srk-toggle-section">
															<label class="srk-toggle-label">
																<div class="srk-toggle-switch">
																	<input type="hidden"
																		name="srk_meta_content_types_settings[<?php echo esc_attr( $post_type_key ); ?>][advanced][use_default_settings]"
																		value="0">

																	<input type="checkbox"
																		class="srk-use-default-toggle"
																		name="srk_meta_content_types_settings[<?php echo esc_attr( $post_type_key ); ?>][advanced][use_default_settings]"
																		value="1"
																		id="srk_use_default_<?php echo esc_attr( $post_type_key ); ?>"
																		<?php checked( $use_default_settings, '1' ); ?>>

																	<span class="srk-toggle-slider"></span>
																</div>

																<div class="srk-toggle-text" style="display:flex; flex-direction:column;">
																	<strong style="margin-bottom:4px;"><?php esc_html_e( 'Sync Advanced Settings', 'seo-repair-kit' ); ?></strong>
																	<p>
																		<?php esc_html_e( 'When enabled, this content type use robots settings from Advanced Settings.', 'seo-repair-kit' ); ?>
																	</p>
																</div>
															</label>
														</div>
													</div>

													<div class="srk-robots-meta-container"
														id="srk-robots-meta-<?php echo esc_attr( $post_type_key ); ?>"
														style="<?php echo '1' === $use_default_settings ? 'display: none;' : ''; ?>">

														<div class="srk-robots-label">
															<strong><?php esc_html_e( 'Custom Robots Settings', 'seo-repair-kit' ); ?></strong>
															<p class="description" style="color: #dc3545;">
																<?php esc_html_e( 'Overrides global robots settings for all single entries of this content type.', 'seo-repair-kit' ); ?>
															</p>
														</div>

														<div class="srk-robots-checkboxes">
															<label class="srk-robots-checkbox">
																<input type="checkbox"
																	name="srk_meta_content_types_settings[<?php echo esc_attr( $post_type_key ); ?>][advanced][robots_meta][noindex]"
																	value="1"
																	id="srk_robots_noindex_<?php echo esc_attr( $post_type_key ); ?>"
																	<?php checked( $robots_meta['noindex'], '1' ); ?>>
																<span><?php esc_html_e( 'No Index', 'seo-repair-kit' ); ?></span>
															</label>
															<label class="srk-robots-checkbox">
																<input type="checkbox"
																	name="srk_meta_content_types_settings[<?php echo esc_attr( $post_type_key ); ?>][advanced][robots_meta][nofollow]"
																	value="1"
																	id="srk_robots_nofollow_<?php echo esc_attr( $post_type_key ); ?>"
																	<?php checked( $robots_meta['nofollow'], '1' ); ?>>
																<span><?php esc_html_e( 'No Follow', 'seo-repair-kit' ); ?></span>
															</label>
															<label class="srk-robots-checkbox">
																<input type="checkbox"
																	name="srk_meta_content_types_settings[<?php echo esc_attr( $post_type_key ); ?>][advanced][robots_meta][noarchive]"
																	value="1"
																	id="srk_robots_noarchive_<?php echo esc_attr( $post_type_key ); ?>"
																	<?php checked( $robots_meta['noarchive'], '1' ); ?>>
																<span><?php esc_html_e( 'No Archive', 'seo-repair-kit' ); ?></span>
															</label>
															<label class="srk-robots-checkbox">
																<input type="checkbox"
																	name="srk_meta_content_types_settings[<?php echo esc_attr( $post_type_key ); ?>][advanced][robots_meta][notranslate]"
																	value="1"
																	id="srk_robots_notranslate_<?php echo esc_attr( $post_type_key ); ?>"
																	<?php checked( $robots_meta['notranslate'], '1' ); ?>>
																<span><?php esc_html_e( 'No Translate', 'seo-repair-kit' ); ?></span>
															</label>
															<label class="srk-robots-checkbox">
																<input type="checkbox"
																	name="srk_meta_content_types_settings[<?php echo esc_attr( $post_type_key ); ?>][advanced][robots_meta][noimageindex]"
																	value="1"
																	id="srk_robots_noimageindex_<?php echo esc_attr( $post_type_key ); ?>"
																	<?php checked( $robots_meta['noimageindex'], '1' ); ?>>
																<span><?php esc_html_e( 'No Image Index', 'seo-repair-kit' ); ?></span>
															</label>
															<label class="srk-robots-checkbox">
																<input type="checkbox"
																	name="srk_meta_content_types_settings[<?php echo esc_attr( $post_type_key ); ?>][advanced][robots_meta][nosnippet]"
																	value="1"
																	id="srk_robots_nosnippet_<?php echo esc_attr( $post_type_key ); ?>"
																	<?php checked( $robots_meta['nosnippet'], '1' ); ?>>
																<span><?php esc_html_e( 'No Snippet', 'seo-repair-kit' ); ?></span>
															</label>
															<label class="srk-robots-checkbox">
																<input type="checkbox"
																	name="srk_meta_content_types_settings[<?php echo esc_attr( $post_type_key ); ?>][advanced][robots_meta][noodp]"
																	value="1"
																	id="srk_robots_noodp_<?php echo esc_attr( $post_type_key ); ?>"
																	<?php checked( $robots_meta['noodp'], '1' ); ?>>
																<span><?php esc_html_e( 'No ODP', 'seo-repair-kit' ); ?></span>
															</label>
														</div>

														<div class="srk-robots-preview-fields">
															<div class="srk-preview-field">
																<label for="srk_max_snippet_<?php echo esc_attr( $post_type_key ); ?>">
																	<?php esc_html_e( 'Max Snippet', 'seo-repair-kit' ); ?>
																</label>
																<input type="number"
																	id="srk_max_snippet_<?php echo esc_attr( $post_type_key ); ?>"
																	name="srk_meta_content_types_settings[<?php echo esc_attr( $post_type_key ); ?>][advanced][robots_meta][max_snippet]"
																	value="<?php echo esc_attr( $robots_meta['max_snippet'] ); ?>"
																	class="small-text"
																	min="-1"
																	step="1">
															</div>

															<div class="srk-preview-field">
																<label for="srk_max_video_preview_<?php echo esc_attr( $post_type_key ); ?>">
																	<?php esc_html_e( 'Max Video Preview', 'seo-repair-kit' ); ?>
																</label>
																<input type="number"
																	id="srk_max_video_preview_<?php echo esc_attr( $post_type_key ); ?>"
																	name="srk_meta_content_types_settings[<?php echo esc_attr( $post_type_key ); ?>][advanced][robots_meta][max_video_preview]"
																	value="<?php echo esc_attr( $robots_meta['max_video_preview'] ); ?>"
																	class="small-text"
																	min="-1"
																	step="1">
															</div>

															<div class="srk-preview-field">
																<label for="srk_max_image_preview_<?php echo esc_attr( $post_type_key ); ?>">
																	<?php esc_html_e( 'Max Image Preview', 'seo-repair-kit' ); ?>
																</label>
																<select id="srk_max_image_preview_<?php echo esc_attr( $post_type_key ); ?>"
																	name="srk_meta_content_types_settings[<?php echo esc_attr( $post_type_key ); ?>][advanced][robots_meta][max_image_preview]">
																	<option value="none" <?php selected( $robots_meta['max_image_preview'], 'none' ); ?>>
																		<?php esc_html_e( 'None', 'seo-repair-kit' ); ?>
																	</option>
																	<option value="standard" <?php selected( $robots_meta['max_image_preview'], 'standard' ); ?>>
																		<?php esc_html_e( 'Standard', 'seo-repair-kit' ); ?>
																	</option>
																	<option value="large" <?php selected( $robots_meta['max_image_preview'], 'large' ); ?>>
																		<?php esc_html_e( 'Large', 'seo-repair-kit' ); ?>
																	</option>
																</select>
															</div>
														</div>
													</div>
												</div>
											</div>
										</div>
										<hr>
									</div>
								<?php endforeach; ?>
							</fieldset>
						</td>
					</tr>
				</table>

				<div class="srk-submit-section">
					<button type="button" class="button srk-reset-content-types-button" style="margin-right: 10px;">
						<?php esc_html_e( 'Reset to Defaults', 'seo-repair-kit' ); ?>
					</button>

					<?php
					submit_button(
						__( 'Save Changes', 'seo-repair-kit' ),
						'primary large',
						'submit',
						false
					);
					?>

					<div id="srk-content-types-status" class="srk-status-message"></div>
				</div>
			</form>

			<div class="srk-tag-modal-overlay" id="srk-tag-modal">
				<div class="srk-tag-modal">
					<div class="srk-modal-header">
						<h3 class="srk-modal-title"><?php esc_html_e( 'Select a Tag for Title', 'seo-repair-kit' ); ?></h3>
						<div class="srk-modal-actions">
							<button type="button" class="srk-modal-delete hidden" aria-label="Delete" title="Delete Tag">
								<span class="dashicons dashicons-trash"></span>
							</button>
							<button type="button" class="srk-modal-close" aria-label="Close">&times;</button>
						</div>
					</div>

					<div class="srk-modal-search">
						<input type="text" class="srk-search-input" placeholder="<?php esc_attr_e( 'Search for an item...', 'seo-repair-kit' ); ?>" />
					</div>

					<div class="srk-modal-body">
						<ul class="srk-tag-list"></ul>
					</div>
				</div>
			</div>

			<?php
			foreach ( $srk_post_types as $srk_post_type ) :
				$post_type_key   = $srk_post_type->name;
				$post_type_label = $srk_post_type->label;

                $display_label = $post_type_label;
                    if ( $post_type_key === 'post' ) {
                    $display_label = 'Post';
                } elseif ( $post_type_key === 'page' ) {
                    $display_label = 'Page';
                } else {
                    $display_label = $post_type_label;
    
                    if ( 's' === substr( $display_label, -1 ) ) {
                        $display_label = rtrim( $display_label, 's' );
                    }
                }

				$global_tags = $this->template_tags;

				$post_type_tags = array(
					$display_label . ' Title'   => array(
						'tag'         => '%title%',
						'description' => 'The title of the current ' . strtolower( $display_label ),
					),
					$display_label . ' Excerpt' => array(
						'tag'         => '%excerpt%',
						'description' => 'The excerpt or summary of the ' . strtolower( $display_label ),
					),
					$display_label . ' Date'   => array(
						'tag'         => '%' . $post_type_key . '_date%',
						'description' => 'The publication date of the ' . strtolower( $display_label ),
					),
					$display_label . ' Day'    => array(
						'tag'         => '%' . $post_type_key . '_day%',
						'description' => 'The day when the ' . strtolower( $display_label ) . ' was published',
					),
					$display_label . ' Content' => array(
						'tag'         => '%content%',
						'description' => 'The content of the ' . strtolower( $display_label ),
					),
				);

				$all_tags_for_post_type = $post_type_tags;

				foreach ( $global_tags as $label => $tag_data ) {
					$tag = $tag_data['tag'];

					if ( 'Posts Title' === $label || 'Posts Excerpt' === $label ||
						'Pages Title' === $label || 'Pages Excerpt' === $label ) {
						continue;
					}

					if ( 'post' === $post_type_key ) {
						if ( false !== strpos( $label, 'Page' ) ) {
							continue;
						}
					}

					if ( 'page' === $post_type_key ) {
						if ( 'Post Title' === $label ) {
							$label                = 'Page Title';
							$tag_data['tag']      = '%title%';
							$tag_data['description'] = 'The title of the current page.';
						} elseif ( 'Post Excerpt' === $label ) {
							$label                = 'Page Excerpt';
							$tag_data['tag']      = '%excerpt%';
							$tag_data['description'] = 'The excerpt or summary of the page.';
						} elseif ( 'Post Date' === $label ) {
							$label                = 'Page Date';
							$tag_data['tag']      = '%post_date%';
							$tag_data['description'] = 'The publication date of the page.';
						} elseif ( 'Post Day' === $label ) {
							$label                = 'Page Day';
							$tag_data['tag']      = '%post_day%';
							$tag_data['description'] = 'The day when the page was published.';
						} elseif ( 'Post Content' === $label ) {
							$label                = 'Page Content';
							$tag_data['tag']      = '%content%';
							$tag_data['description'] = 'The content of the page.';
						}

						if ( false !== strpos( $label, 'Post' ) && 'Page Title' !== $label && 'Page Excerpt' !== $label && 'Page Date' !== $label && 'Page Day' !== $label && 'Page Content' !== $label ) {
							continue;
						}
					}

					if ( ! in_array( $post_type_key, array( 'post', 'page' ), true ) ) {
						if ( 'Post Title' === $label ) {
							$label                = $display_label . ' Title';
							$tag_data['tag']      = '%title%';
							$tag_data['description'] = 'The title of the current ' . strtolower( $display_label ) . '.';
						} elseif ( 'Post Excerpt' === $label ) {
							$label                = $display_label . ' Excerpt';
							$tag_data['tag']      = '%excerpt%';
							$tag_data['description'] = 'The excerpt or summary of the ' . strtolower( $display_label ) . '.';
						} elseif ( 'Post Date' === $label ) {
							$label                = $display_label . ' Date';
							$tag_data['tag']      = '%' . $post_type_key . '_date%';
							$tag_data['description'] = 'The publication date of the ' . strtolower( $display_label ) . '.';
						} elseif ( 'Post Day' === $label ) {
							$label                = $display_label . ' Day';
							$tag_data['tag']      = '%' . $post_type_key . '_day%';
							$tag_data['description'] = 'The day when the ' . strtolower( $display_label ) . ' was published.';
						} elseif ( 'Post Content' === $label ) {
							$label                = $display_label . ' Content';
							$tag_data['tag']      = '%content%';
							$tag_data['description'] = 'The content of the ' . strtolower( $display_label ) . '.';
						}

						if ( false !== strpos( $label, 'Post' ) || false !== strpos( $label, 'Page' ) ) {
							continue;
						}
					}

					if ( ! in_array( $post_type_key, array( 'post', 'page' ), true ) ) {
						if ( 'Post Date' === $label ) {
							$label                = $display_label . ' Date';
							$tag_data['tag']      = '%' . $post_type_key . '_date%';
							$tag_data['description'] = 'The publication date of the ' . strtolower( $display_label ) . '.';
						} elseif ( 'Post Day' === $label ) {
							$label                = $display_label . ' Day';
							$tag_data['tag']      = '%' . $post_type_key . '_day%';
							$tag_data['description'] = 'The day when the ' . strtolower( $display_label ) . ' was published.';
						} elseif ( 'Post Content' === $label ) {
							$label                = $display_label . ' Content';
							$tag_data['tag']      = '%content%';
							$tag_data['description'] = 'The content of the ' . strtolower( $display_label ) . '.';
						}

						if ( false !== strpos( $label, 'Post' ) || false !== strpos( $label, 'Page' ) ||
							false !== strpos( $tag, 'post' ) || false !== strpos( $tag, 'page' ) ) {
							continue;
						}
					}

					$all_tags_for_post_type[ $label ] = $tag_data;
				}
				?>
				<div id="srk-tags-<?php echo esc_attr( $post_type_key ); ?>" style="display: none;">
					<?php foreach ( $all_tags_for_post_type as $label => $tag_data ) : ?>
						<div class="srk-tag-data"
							data-tag="<?php echo esc_attr( $tag_data['tag'] ); ?>"
							data-name="<?php echo esc_attr( strtolower( $label ) ); ?>"
							data-label="<?php echo esc_attr( $label ); ?>"
							data-description="<?php echo esc_attr( $tag_data['description'] ); ?>">
						</div>
					<?php endforeach; ?>
				</div>
			<?php endforeach; ?>
		</div>
		<?php
	}
}

new SRK_Meta_Manager_Content_Types();