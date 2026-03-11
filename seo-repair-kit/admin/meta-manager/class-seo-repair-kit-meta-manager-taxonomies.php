<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Taxonomies Meta Manager - SEO Meta Manager
 *
 * @package SEO_Repair_Kit
 * @since 2.1.3 - Added caching system, optimized database queries
 * @version 2.1.3
 */
class SRK_Meta_Manager_Taxonomies {

	public function __construct() {
		add_action( 'admin_enqueue_scripts', array( $this, 'enqueue_scripts' ) );
		add_action( 'init', array( $this, 'register_term_seo_hooks' ), 20 );
	}

	public function register_term_seo_hooks() {
		static $registered = false;
		if ( $registered ) {
			return;
		}
		$registered = true;

		$taxonomies = $this->get_taxonomies_to_manage();

		if ( ! is_array( $taxonomies ) || empty( $taxonomies ) ) {
			return;
		}

		foreach ( array_keys( $taxonomies ) as $tax_slug ) {
			add_action( $tax_slug . '_add_form_fields', array( $this, 'term_add_form_fields' ), 10, 1 );
			add_action( $tax_slug . '_edit_form_fields', array( $this, 'term_edit_form_fields' ), 10, 2 );
			add_action( 'created_' . $tax_slug, array( $this, 'term_save_settings' ), 10, 2 );
			add_action( 'edited_' . $tax_slug, array( $this, 'term_save_settings' ), 10, 2 );
		}
	}

	public function register_term_seo_hooks_late() {
		$taxonomies = $this->get_taxonomies_to_manage();
		$this->register_hooks_for_taxonomies( $taxonomies );
	}

	private function register_hooks_for_taxonomies( $taxonomies ) {
		foreach ( array_keys( $taxonomies ) as $tax_slug ) {
			add_action( $tax_slug . '_add_form_fields', array( $this, 'term_add_form_fields' ), 10, 1 );
			add_action( $tax_slug . '_edit_form_fields', array( $this, 'term_edit_form_fields' ), 10, 2 );
			add_action( 'created_' . $tax_slug, array( $this, 'term_save_settings' ), 10, 2 );
			add_action( 'edited_' . $tax_slug, array( $this, 'term_save_settings' ), 10, 2 );
		}
	}

	public function enqueue_scripts( $hook ) {
		$is_meta_manager = ( strpos( $hook, 'seo-repair-kit' ) !== false );
		$is_term_edit    = in_array( $hook, array( 'term.php', 'edit-tags.php' ), true );

		if ( ! $is_meta_manager && ! $is_term_edit ) {
			return;
		}

		$current_taxonomy = '';
		if ( $is_term_edit ) {
			if ( isset( $_GET['taxonomy'] ) ) {
				$current_taxonomy = sanitize_text_field( wp_unslash( $_GET['taxonomy'] ) );
			} elseif ( isset( $_POST['taxonomy'] ) ) {
				$current_taxonomy = sanitize_text_field( wp_unslash( $_POST['taxonomy'] ) );
			}
		}

		$global    = get_option( 'srk_meta_global_settings', array() );
		$separator = isset( $global['title_separator'] ) ? $global['title_separator'] : get_option( 'srk_title_separator', '-' );
		$base_url  = plugin_dir_url( dirname( dirname( __FILE__ ) ) );

		if ( $is_term_edit ) {
			wp_enqueue_style(
				'srk-meta-manager-css',
				$base_url . 'admin/css/seo-repair-kit-meta-manager.css',
				array(),
				defined( 'SEO_REPAIR_KIT_VERSION' ) ? SEO_REPAIR_KIT_VERSION : '2.1.3'
			);
			wp_enqueue_script(
				'srk-meta-taxonomies',
				$base_url . 'admin/js/meta-manager-js/srk-meta-taxonomies.js',
				array( 'jquery' ),
				defined( 'SEO_REPAIR_KIT_VERSION' ) ? SEO_REPAIR_KIT_VERSION : '2.1.3',
				true
			);
		}

		wp_localize_script(
			'srk-meta-taxonomies',
			'srkTaxonomyData',
			array(
				'separator' => $separator,
				'siteName'  => get_bloginfo( 'name' ),
				'siteUrl'   => home_url(),
				'taxonomy'  => $current_taxonomy,
			)
		);
	}

	private function get_taxonomies_to_manage() {
		$taxonomies = get_taxonomies(
			array(
				'public' => true,
			),
			'objects'
		);

		if ( ! is_array( $taxonomies ) ) {
			$taxonomies = array();
		}

		$core_taxonomies = array( 'category', 'post_tag' );

		foreach ( $core_taxonomies as $tax_name ) {
			if ( taxonomy_exists( $tax_name ) && ! isset( $taxonomies[ $tax_name ] ) ) {
				$taxonomy = get_taxonomy( $tax_name );
				if ( $taxonomy ) {
					$taxonomies[ $tax_name ] = $taxonomy;
				}
			}
		}

		$filtered_taxonomies = array();

		foreach ( $taxonomies as $tax_name => $taxonomy ) {
			if ( ! is_object( $taxonomy ) ) {
				continue;
			}

			$excluded = array(
				'nav_menu',
				'link_category',
				'post_format',
			);

			if ( in_array( $tax_name, $excluded, true ) ) {
				continue;
			}

			$filtered_taxonomies[ $tax_name ] = $taxonomy;
		}

		return $filtered_taxonomies;
	}

	private function get_taxonomy_settings( $taxonomy_slug ) {
		$all_settings = get_option( 'srk_meta_taxonomies_settings', array() );

		$defaults = array(
			'use_default_advanced'   => '1',
			'robots_custom_backup'   => array(),
			'title_template'         => '%term% %sep% %site_title%',
			'description_template'   => '%term_description%',
			'robots'                 => array(
				'noindex'           => false,
				'nofollow'          => false,
				'noarchive'         => false,
				'noimageindex'      => false,
				'nosnippet'         => false,
				'max_snippet'       => -1,
				'max_image_preview' => 'large',
				'max_video_preview' => -1,
			),
		);

		if ( 'post_tag' === $taxonomy_slug ) {
			$defaults['title_template'] = '%term% %sep% %site_title%';
		}

		if ( isset( $all_settings[ $taxonomy_slug ] ) && is_array( $all_settings[ $taxonomy_slug ] ) ) {
			$saved_settings = $all_settings[ $taxonomy_slug ];
			$result         = array_replace_recursive( $defaults, $saved_settings );

			if ( isset( $saved_settings['robots'] ) && is_array( $saved_settings['robots'] ) ) {
				$result['robots'] = array_replace_recursive( $defaults['robots'], $saved_settings['robots'] );
			}

			return $result;
		}

		return $defaults;
	}

	private function get_smart_tags_for_taxonomy( $taxonomy_slug, $field_type = 'title' ) {
		$taxonomy_obj = get_taxonomy( $taxonomy_slug );
		$tax_label    = $taxonomy_obj ? $taxonomy_obj->labels->singular_name : ucfirst( str_replace( '_', ' ', $taxonomy_slug ) );

		$smart_tags = array(
			'term_title'       => array(
				'tag'         => '%term%',
				'label'       => $tax_label . ' Title',
				'description' => 'The title of the current ' . strtolower( $tax_label ) . '.',
			),
			'term_description' => array(
				'tag'         => '%term_description%',
				'label'       => $tax_label . ' Description',
				'description' => 'The description of the current ' . strtolower( $tax_label ) . '.',
			),
			'site_title'       => array(
				'tag'         => '%site_title%',
				'label'       => 'Site Title',
				'description' => 'Your site title.',
			),
			'separator'        => array(
				'tag'         => '%sep%',
				'label'       => 'Separator',
				'description' => 'The separator defined in SEO settings.',
			),
			'current_date'     => array(
				'tag'         => '%current_date%',
				'label'       => 'Current Date',
				'description' => 'The current date, localized.',
			),
			'current_month'    => array(
				'tag'         => '%month%',
				'label'       => 'Current Month',
				'description' => 'The current month, localized.',
			),
			'current_year'     => array(
				'tag'         => '%year%',
				'label'       => 'Current Year',
				'description' => 'The current year, localized.',
			),
			'custom_field'     => array(
				'tag'         => '%custom_field%',
				'label'       => 'Custom Field',
				'description' => 'A custom field from the current page/post.',
			),
			'parent_term'      => array(
				'tag'         => '%parent_categories%',
				'label'       => 'Parent ' . $tax_label,
				'description' => 'The name of the parent ' . strtolower( $tax_label ) . ' of the current term.',
			),
			'permalink'        => array(
				'tag'         => '%permalink%',
				'label'       => 'Permalink',
				'description' => 'The permalink for the current page/post.',
			),
			'tagline'          => array(
				'tag'         => '%tagline%',
				'label'       => 'Tagline',
				'description' => 'The tagline for your site, set in the general settings.',
			),
			'taxonomy_name'    => array(
				'tag'         => '%taxonomy%',
				'label'       => 'Taxonomy Name',
				'description' => 'The name of the current taxonomy.',
			),
			'post_count'       => array(
				'tag'         => '%post_count%',
				'label'       => 'Post Count',
				'description' => 'Number of posts in this term.',
			),
			'page_number'      => array(
				'tag'         => '%page%',
				'label'       => 'Page Number',
				'description' => 'Current page number for paginated archives.',
			),
		);

		if ( 'description' === $field_type ) {
			$desc_tags       = array( 'term_description', 'term_title', 'site_title', 'tagline', 'current_date', 'post_count' );
			$filtered_tags   = array();
			foreach ( $smart_tags as $key => $tag ) {
				if ( in_array( $key, $desc_tags, true ) ) {
					$filtered_tags[ $key ] = $tag;
				}
			}
			return $filtered_tags;
		}

		return $smart_tags;
	}

	private function render_smart_tags_ui( $field_type = 'title', $taxonomy_slug = 'category', $field_id = '' ) {
		$smart_tags = $this->get_smart_tags_for_taxonomy( $taxonomy_slug, $field_type );

		$quick_keys  = ( 'title' === $field_type ) ? array( 'term_title', 'site_title', 'separator' ) : array( 'term_title', 'term_description', 'site_title' );
		$first_tags  = array();
		foreach ( $quick_keys as $k ) {
			if ( isset( $smart_tags[ $k ] ) ) {
				$first_tags[ $k ] = $smart_tags[ $k ];
			}
		}

		?>
		<div class="srk-smart-tags-ui" data-field-type="<?php echo esc_attr( $field_type ); ?>" data-taxonomy="<?php echo esc_attr( $taxonomy_slug ); ?>">
			<div class="srk-quick-tags">
				<?php foreach ( $first_tags as $tag_key => $tag_info ) : ?>
					<button type="button" 
							class="button button-small srk-quick-tag-btn" 
							data-tag="<?php echo esc_attr( $tag_info['tag'] ); ?>"
							data-field-id="<?php echo esc_attr( $field_id ); ?>"
							title="<?php echo esc_attr( $tag_info['description'] ); ?>">
						+ <?php echo esc_html( $tag_info['label'] ); ?>
					</button>
				<?php endforeach; ?>
				
				<button type="button" 
						class="button button-small srk-view-all-tags"
						data-field-id="<?php echo esc_attr( $field_id ); ?>">
					View all tags...
				</button>
			</div>
			
			<div class="srk-all-tags-modal" id="srk-tags-modal-<?php echo esc_attr( $field_id ); ?>" style="display: none;">
				<div class="srk-modal-header">
					<h3>Smart Tags</h3>
					<button type="button" class="srk-modal-close">&times;</button>
				</div>
				
				<div class="srk-modal-search">
					<input type="text" 
						   class="srk-tag-search" 
						   placeholder="Search for an item..."
						   autocomplete="off">
				</div>
				
				<div class="srk-modal-body">
					<div class="srk-tags-list">
						<?php foreach ( $smart_tags as $tag_key => $tag_info ) : ?>
							<div class="srk-modal-tag-item" 
								 data-tag="<?php echo esc_attr( $tag_info['tag'] ); ?>"
								 data-field-id="<?php echo esc_attr( $field_id ); ?>">
								<div class="srk-tag-icon">+</div>
								<div class="srk-tag-info">
									<strong><?php echo esc_html( $tag_info['label'] ); ?></strong>
									<span class="srk-tag-code"><?php echo esc_html( $tag_info['tag'] ); ?></span>
									<span class="srk-tag-description"><?php echo esc_html( $tag_info['description'] ); ?></span>
								</div>
							</div>
						<?php endforeach; ?>
					</div>
				</div>
				
				<div class="srk-modal-footer">
					<a href="#" class="srk-learn-more">Learn more about Smart Tags →</a>
				</div>
			</div>
		</div>
		<?php
	}

	private function render_title_template( $taxonomy_slug, $settings ) {
		$title_template = isset( $settings['title_template'] ) && ! empty( $settings['title_template'] )
			? $settings['title_template']
			: ( 'post_tag' === $taxonomy_slug ? '%term% %sep% %site_title%' : '%term% %sep% %site_title%' );
		$taxonomy_obj   = get_taxonomy( $taxonomy_slug );
		$field_id       = 'srk_title_' . $taxonomy_slug;
		?>
		<div class="srk-field-group">
			<label class="srk-field-title"><h3><?php $taxonomy_label = $taxonomy_obj ? $taxonomy_obj->labels->singular_name : ucfirst( str_replace( '_', ' ', $taxonomy_slug ) ); printf( esc_html__( 'Meta Title for %s', 'seo-repair-kit' ), esc_html( $taxonomy_label ) ); ?></h3></label>
			<div class="srk-field-example srk-important">
				<?php
				$taxonomy_label = $taxonomy_obj ? $taxonomy_obj->labels->singular_name : ucfirst( str_replace( '_', ' ', $taxonomy_slug ) );
				?>
				<p class="srk-example-desc">
					<?php
					printf(
						esc_html__( 'Default title format for archive pages of %s.', 'seo-repair-kit' ),
						esc_html( strtolower( $taxonomy_label ) )
					);
					?>
				</p>
			</div>
			
			<?php $this->render_smart_tags_ui( 'title', $taxonomy_slug, $field_id ); ?>
			
			<input type="text" 
				   id="<?php echo esc_attr( $field_id ); ?>"
				   class="srk-taxonomy-title-input large-text" 
				   name="srk_meta_taxonomies_settings[<?php echo esc_attr( $taxonomy_slug ); ?>][title_template]" 
				   value="<?php echo esc_attr( $title_template ); ?>" 
				   placeholder="<?php echo esc_attr( 'post_tag' === $taxonomy_slug ? '%term% %sep% %site_title%' : '%term% %sep% %site_title%' ); ?>">
		</div>
		<?php
	}

	private function render_meta_description( $taxonomy_slug, $settings ) {
		$desc_template = isset( $settings['description_template'] ) && ! empty( $settings['description_template'] )
			? $settings['description_template']
			: '%term_description%';
		$taxonomy_obj  = get_taxonomy( $taxonomy_slug );
		$field_id      = 'srk_desc_' . $taxonomy_slug;
		?>
		<div class="srk-field-group">
			<label class="srk-field-title"><h3><?php $taxonomy_label = $taxonomy_obj ? $taxonomy_obj->labels->singular_name : ucfirst( str_replace( '_', ' ', $taxonomy_slug ) ); printf( esc_html__( 'Meta Description for %s', 'seo-repair-kit' ), esc_html( $taxonomy_label ) ); ?></h3></label>
			<div class="srk-field-example srk-important">
				<?php
				$taxonomy_label = $taxonomy_obj ? $taxonomy_obj->labels->singular_name : ucfirst( str_replace( '_', ' ', $taxonomy_slug ) );
				?>
				<p class="srk-example-desc">
					<?php
					printf(
						esc_html__( 'Default meta description for archive pages of %s.', 'seo-repair-kit' ),
						esc_html( strtolower( $taxonomy_label ) )
					);
					?>
				</p>
			</div>

			<?php $this->render_smart_tags_ui( 'description', $taxonomy_slug, $field_id ); ?>
			
			<div class="srk-textarea-with-counter">
				<textarea id="<?php echo esc_attr( $field_id ); ?>"
						  class="srk-taxonomy-desc-input large-text" 
						  name="srk_meta_taxonomies_settings[<?php echo esc_attr( $taxonomy_slug ); ?>][description_template]" 
						  rows="3" 
						  placeholder="<?php echo esc_attr( '%term_description%' ); ?>"><?php echo esc_textarea( $desc_template ); ?></textarea>
			</div>
		</div>
		<?php
	}

	private function render_robots_meta( $taxonomy_slug, $settings ) {
		$use_default = isset( $settings['use_default_advanced'] )
			? (string) $settings['use_default_advanced']
			: '1';

		if ( '' === $use_default || '0' === $use_default ) {
			$use_default = '1';
		}

		$disabled_class = ( '1' === $use_default ) ? 'srk-disabled-section' : '';

		$default_robots = array(
			'noindex'           => false,
			'nofollow'          => false,
			'noarchive'         => false,
			'notranslate'       => false,
			'noimageindex'      => false,
			'nosnippet'         => false,
			'noodp'             => false,
			'max_snippet'       => -1,
			'max_image_preview' => 'large',
			'max_video_preview' => -1,
		);

		$robots = isset( $settings['robots'] ) && is_array( $settings['robots'] )
			? wp_parse_args( $settings['robots'], $default_robots )
			: $default_robots;

		?>

		<div class="srk-section srk-advanced-section">

			<div class="srk-section-header">
				<div class="srk-toggle-setting">

					<label class="srk-toggle-switch">
						<input type="hidden"
							   name="srk_meta_taxonomies_settings[<?php echo esc_attr( $taxonomy_slug ); ?>][use_default_advanced]"
							   value="0">

						<input type="checkbox"
							   class="srk-use-default-advanced"
							   name="srk_meta_taxonomies_settings[<?php echo esc_attr( $taxonomy_slug ); ?>][use_default_advanced]"
							   value="1"
							<?php checked( $use_default, '1' ); ?>>
						<span class="srk-toggle-slider"></span>
					</label>

					<div class="srk-toggle-content">
						<span class="srk-toggle-title">
							<?php esc_html_e( 'Sync Advanced Settings', 'seo-repair-kit' ); ?>
						</span>
						<span class="srk-toggle-description">
							<?php esc_html_e( 'When enabled, this taxonomy uses robots settings from Advanced Settings.', 'seo-repair-kit' ); ?>
						</span>
					</div>

				</div>

			</div>

			<div class="srk-section-content">
				<div class="srk-default-mode-message">
					<strong><?php esc_html_e( 'How These Settings Work', 'seo-repair-kit' ); ?></strong>
					<p>
						<?php esc_html_e( 'These robots meta settings apply only to this taxonomy archive (e.g., Categories, Tags, or custom taxonomies).', 'seo-repair-kit' ); ?>
					</p>
					<ul>
						<li>
							<?php esc_html_e( 'If "Use Default Settings" is enabled, the global robots directives from Advanced Settings will apply.', 'seo-repair-kit' ); ?>
						</li>
						<li>
							<?php esc_html_e( 'If disabled, the custom robots directives below will override the global settings for this taxonomy only.', 'seo-repair-kit' ); ?>
						</li>
					</ul>
				</div>

				<div class="srk-robots-meta-container <?php echo esc_attr( $disabled_class ); ?>">
					<div class="srk-robots-label">
						<strong><?php esc_html_e( 'Custom Robots Settings', 'seo-repair-kit' ); ?></strong>
						<p class="description" style="color:#dc3545;">
							<?php esc_html_e( 'Overrides global robots settings for all archive pages of this taxonomy', 'seo-repair-kit' ); ?>
						</p>
					</div>

					<div class="srk-robots-checkboxes">

						<label class="srk-robots-checkbox">
							<input type="checkbox"
								   name="srk_meta_taxonomies_settings[<?php echo esc_attr( $taxonomy_slug ); ?>][robots][noindex]"
								   value="1"
								<?php checked( $robots['noindex'], true ); ?>>
							<span>No Index</span>
						</label>

						<label class="srk-robots-checkbox">
							<input type="checkbox"
								   name="srk_meta_taxonomies_settings[<?php echo esc_attr( $taxonomy_slug ); ?>][robots][nofollow]"
								   value="1"
								<?php checked( $robots['nofollow'], true ); ?>>
							<span>No Follow</span>
						</label>

						<label class="srk-robots-checkbox">
							<input type="checkbox"
								   name="srk_meta_taxonomies_settings[<?php echo esc_attr( $taxonomy_slug ); ?>][robots][noarchive]"
								   value="1"
								<?php checked( $robots['noarchive'], true ); ?>>
							<span>No Archive</span>
						</label>

						<label class="srk-robots-checkbox">
							<input type="checkbox"
								   name="srk_meta_taxonomies_settings[<?php echo esc_attr( $taxonomy_slug ); ?>][robots][nosnippet]"
								   value="1"
								<?php checked( $robots['nosnippet'], true ); ?>>
							<span>No Snippet</span>
						</label>

						<label class="srk-robots-checkbox">
							<input type="checkbox"
								   name="srk_meta_taxonomies_settings[<?php echo esc_attr( $taxonomy_slug ); ?>][robots][noimageindex]"
								   value="1"
								<?php checked( $robots['noimageindex'], true ); ?>>
							<span>No Image Index</span>
						</label>

					</div>

					<div class="srk-robots-preview-fields">

						<div class="srk-preview-field">
							<label><?php esc_html_e( 'Max Snippet Length', 'seo-repair-kit' ); ?></label>
							<input type="number"
								   name="srk_meta_taxonomies_settings[<?php echo esc_attr( $taxonomy_slug ); ?>][robots][max_snippet]"
								   value="<?php echo esc_attr( $robots['max_snippet'] ); ?>">
						</div>

						<div class="srk-preview-field">
							<label><?php esc_html_e( 'Max Video Preview', 'seo-repair-kit' ); ?></label>
							<input type="number"
								   name="srk_meta_taxonomies_settings[<?php echo esc_attr( $taxonomy_slug ); ?>][robots][max_video_preview]"
								   value="<?php echo esc_attr( $robots['max_video_preview'] ); ?>">
						</div>

						<div class="srk-preview-field">
							<label><?php esc_html_e( 'Max Image Preview', 'seo-repair-kit' ); ?></label>
							<select name="srk_meta_taxonomies_settings[<?php echo esc_attr( $taxonomy_slug ); ?>][robots][max_image_preview]">

								<option value="large" <?php selected( $robots['max_image_preview'], 'large' ); ?>>Large</option>
								<option value="standard" <?php selected( $robots['max_image_preview'], 'standard' ); ?>>Standard</option>
								<option value="none" <?php selected( $robots['max_image_preview'], 'none' ); ?>>None</option>
							</select>
						</div>

					</div>

				</div>

			</div>
		</div>

		<?php
	}

	private function render_serp_preview( $taxonomy_slug ) {
		?>
		<div class="srk-field-group">
			<h3><?php esc_html_e( 'SERP Preview', 'seo-repair-kit' ); ?></h3>
			
			<div class="srk-taxonomy-preview">
				<div class="srk-preview-loading">
					<?php esc_html_e( 'Loading preview...', 'seo-repair-kit' ); ?>
				</div>
			</div>
		</div>
		<?php
	}

	private function render_reset_button( $taxonomy_slug ) {
		?>
		<div class="srk-field-group">
			<button type="button" 
					class="button srk-reset-taxonomy" 
					data-taxonomy="<?php echo esc_attr( $taxonomy_slug ); ?>">
				<?php esc_html_e( 'Reset to Defaults', 'seo-repair-kit' ); ?>
			</button>
			<p class="description">
				<?php esc_html_e( 'Reset all settings for this taxonomy to their default values.', 'seo-repair-kit' ); ?>
			</p>
		</div>
		<?php
	}

	public function term_add_form_fields( $taxonomy ) {
		wp_nonce_field( 'srk_term_seo_save', 'srk_term_seo_nonce' );
		?>
		<?php
	}

	public function term_edit_form_fields( $term, $taxonomy ) {
		wp_nonce_field( 'srk_term_seo_save', 'srk_term_seo_nonce' );

		$data = get_term_meta( $term->term_id, '_srk_term_settings', true );
		if ( ! is_array( $data ) ) {
			$data = array();
		}

		$title       = isset( $data['title'] ) ? $data['title'] : '';
		$description = isset( $data['description'] ) ? $data['description'] : '';
		$advanced    = isset( $data['advanced'] ) && is_array( $data['advanced'] ) ? $data['advanced'] : array();
		$use_default = isset( $advanced['use_default_settings'] ) && '1' === $advanced['use_default_settings'];
		$robots      = isset( $advanced['robots_meta'] ) && is_array( $advanced['robots_meta'] ) ? $advanced['robots_meta'] : array();
		$robots      = wp_parse_args(
			$robots,
			array(
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
			)
		);

		$preview_title = ! empty( $title ) ? $this->replace_term_preview_vars( $title, $term ) : $term->name;
		$preview_desc  = ! empty( $description ) ? $this->replace_term_preview_vars( $description, $term ) : wp_trim_words( term_description( $term->term_id ), 25 );
		$preview_url   = get_term_link( $term );
		$preview_url   = ( ! is_wp_error( $preview_url ) ) ? str_replace( array( 'http://', 'https://' ), '', $preview_url ) : '';

		$all_smart_tags  = $this->get_smart_tags_for_taxonomy( $taxonomy, 'title' );
		$desc_smart_tags = $this->get_smart_tags_for_taxonomy( $taxonomy, 'description' );

		$title_quick_tags = array_intersect_key( $all_smart_tags, array_flip( array( 'term_title', 'site_title', 'separator' ) ) );
		$desc_quick_tags  = array_intersect_key( $desc_smart_tags, array_flip( array( 'term_title', 'term_description', 'site_title' ) ) );
		?>
		<tr class="form-field srk-term-seo-row">
			<td colspan="2">
				<?php
				$term_link = get_term_link( $term );
				$term_link = ( ! is_wp_error( $term_link ) ) ? $term_link : '';

				$parent_term = '';
				if ( $term->parent ) {
					$parent = get_term( $term->parent, $taxonomy );
					if ( $parent && ! is_wp_error( $parent ) ) {
						$parent_term = $parent->name;
					}
				}

				$global    = get_option( 'srk_meta_global_settings', array() );
				$separator = isset( $global['title_separator'] ) ? $global['title_separator'] : '-';

				$current_date  = date_i18n( get_option( 'date_format' ) );
				$current_day   = date_i18n( 'l' );
				$current_month = date_i18n( 'F' );
				$current_year  = date_i18n( 'Y' );
				?>

				<div class="srk-meta-box-wrapper srk-term-seo-wrapper" 
					 data-taxonomy="<?php echo esc_attr( $taxonomy ); ?>" 
					 data-term-id="<?php echo esc_attr( $term->term_id ); ?>"
					 data-term-name="<?php echo esc_attr( $term->name ); ?>"
					 data-term-description="<?php echo esc_attr( wp_strip_all_tags( $term->description ) ); ?>"
					 data-term-count="<?php echo esc_attr( $term->count ); ?>"
					 data-term-link="<?php echo esc_url( $term_link ); ?>"
					 data-parent-term="<?php echo esc_attr( $parent_term ); ?>"
					 data-site-name="<?php echo esc_attr( get_bloginfo( 'name' ) ); ?>"
					 data-site-url="<?php echo esc_url( home_url() ); ?>"
					 data-tagline="<?php echo esc_attr( get_bloginfo( 'description' ) ); ?>"
					 data-separator="<?php echo esc_attr( $separator ); ?>"
					 data-current-date="<?php echo esc_attr( $current_date ); ?>"
					 data-current-day="<?php echo esc_attr( $current_day ); ?>"
					 data-current-month="<?php echo esc_attr( $current_month ); ?>"
					 data-current-year="<?php echo esc_attr( $current_year ); ?>">
					<h3 style="margin-top:0;"><?php esc_html_e( 'SEO Repair Kit', 'seo-repair-kit' ); ?></h3>
					
					<nav class="srk-tabs-nav">
						<button type="button" class="srk-tab-btn active" data-tab="general"><?php esc_html_e( 'Title & Description', 'seo-repair-kit' ); ?></button>
						<button type="button" class="srk-tab-btn" data-tab="advanced"><?php esc_html_e( 'Advanced', 'seo-repair-kit' ); ?></button>
					</nav>
					
					<div class="srk-tab-pane active" data-tab="general">
						<table class="form-table">
							<tr>
								<th scope="row">
									<label for="srk_term_title"><?php esc_html_e( 'SEO Title', 'seo-repair-kit' ); ?></label>
								</th>
								<td>
									<?php if ( ! empty( $title_quick_tags ) ) : ?>
										<div class="srk-quick-tags" style="margin-bottom:8px;">
											<?php foreach ( $title_quick_tags as $tag_info ) : ?>
												<button type="button" 
														class="button button-small srk-insert-tag" 
														data-tag="<?php echo esc_attr( $tag_info['tag'] ); ?>" 
														data-field-id="srk_term_title"
														title="<?php echo esc_attr( $tag_info['description'] ); ?>">
													+ <?php echo esc_html( $tag_info['label'] ); ?>
												</button>
											<?php endforeach; ?>
											
											<button type="button" 
													class="button button-small srk-view-all-tags" 
													data-field-id="srk_term_title"
													data-taxonomy="<?php echo esc_attr( $taxonomy ); ?>"
													data-field-type="title">
												<?php esc_html_e( 'View all tags...', 'seo-repair-kit' ); ?>
											</button>
										</div>
									<?php endif; ?>
									
									<input type="text" 
										   name="srk_term_title" 
										   id="srk_term_title" 
										   value="<?php echo esc_attr( $title ); ?>" 
										   class="large-text" 
										   placeholder="<?php esc_attr_e( 'Leave empty to use template', 'seo-repair-kit' ); ?>" />
									
									<?php $this->render_term_smart_tags_modal( 'srk_term_title', $taxonomy, 'title', $all_smart_tags ); ?>
								</td>
							</tr>
							
							<tr>
								<th scope="row">
									<label for="srk_term_description"><?php esc_html_e( 'Meta Description', 'seo-repair-kit' ); ?></label>
								</th>
								<td>
									<?php if ( ! empty( $desc_quick_tags ) ) : ?>
										<div class="srk-quick-tags" style="margin-bottom:8px;">
											<?php foreach ( $desc_quick_tags as $tag_info ) : ?>
												<button type="button" 
														class="button button-small srk-insert-tag" 
														data-tag="<?php echo esc_attr( $tag_info['tag'] ); ?>" 
														data-field-id="srk_term_description"
														title="<?php echo esc_attr( $tag_info['description'] ); ?>">
													+ <?php echo esc_html( $tag_info['label'] ); ?>
												</button>
											<?php endforeach; ?>
											
											<button type="button" 
													class="button button-small srk-view-all-tags" 
													data-field-id="srk_term_description"
													data-taxonomy="<?php echo esc_attr( $taxonomy ); ?>"
													data-field-type="description">
												<?php esc_html_e( 'View all tags...', 'seo-repair-kit' ); ?>
											</button>
										</div>
									<?php endif; ?>
									
									<textarea name="srk_term_description" 
											  id="srk_term_description" 
											  rows="3" 
											  class="large-text" 
											  placeholder="<?php esc_attr_e( 'Leave empty to use template', 'seo-repair-kit' ); ?>"><?php echo esc_textarea( $description ); ?></textarea>
									
									<?php $this->render_term_smart_tags_modal( 'srk_term_description', $taxonomy, 'description', $desc_smart_tags ); ?>
								</td>
							</tr>
							
							<tr>
								<th scope="row"><?php esc_html_e( 'SERP Preview', 'seo-repair-kit' ); ?></th>
								<td>
									<div class="srk-preview-wrapper">
										<div class="srk-meta-preview">
											<div class="url"><?php echo esc_html( $preview_url ); ?></div>
											<div class="title srk-term-preview-title"><?php echo esc_html( $preview_title ); ?></div>
											<div class="desc srk-term-preview-desc"><?php echo esc_html( $preview_desc ); ?></div>
										</div>
									</div>
								</td>
							</tr>
						</table>
					</div>
					
					<div class="srk-tab-pane" data-tab="advanced" style="display:none;">
						<div class="srk-advanced-section">
							<?php
							$use_default = true;

							if ( isset( $data['advanced']['use_default_settings'] ) ) {
								$saved_value = $data['advanced']['use_default_settings'];
								$use_default = ( '1' === $saved_value || true === $saved_value || 1 === $saved_value );
							} else {
								$use_default = true;
							}
							?>
							<div class="srk-toggle-setting">
								<label class="srk-toggle-switch">
									<input type="hidden" name="srk_term_use_default" value="0">
									<input type="checkbox" 
										   name="srk_term_use_default" 
										   id="srk_term_use_default" 
										   value="1" 
										   <?php checked( $use_default, true ); ?> />
									<span class="srk-toggle-slider"></span>
								</label>
								<div class="srk-toggle-content">
									<span class="srk-toggle-label"><?php esc_html_e( 'Use Default Settings', 'seo-repair-kit' ); ?></span>
									<span class="srk-toggle-description <?php echo $use_default ? 'srk-default-active' : ''; ?>" style="display: block; margin-top: 4px; font-size: 12px; color: #646970;">
										<?php esc_html_e( 'If enabled, global robots directives from taxonomy settings will apply.', 'seo-repair-kit' ); ?>
									</span>
								</div>
							</div>
							
							<div class="srk-robots-meta-container srk-term-robots-row" style="<?php echo $use_default ? 'display:none;' : ''; ?>">
								<div class="srk-robots-label">
									<strong><?php esc_html_e( 'Robots Meta', 'seo-repair-kit' ); ?></strong>
								</div>
								<div class="srk-robots-checkboxes">
									<label class="srk-robots-checkbox">
										<input type="checkbox" name="srk_term_robots[noindex]" value="1" <?php checked( $robots['noindex'], '1' ); ?> /> 
										<?php esc_html_e( 'No Index', 'seo-repair-kit' ); ?>
									</label>
									<label class="srk-robots-checkbox">
										<input type="checkbox" name="srk_term_robots[nofollow]" value="1" <?php checked( $robots['nofollow'], '1' ); ?> /> 
										<?php esc_html_e( 'No Follow', 'seo-repair-kit' ); ?>
									</label>
									<label class="srk-robots-checkbox">
										<input type="checkbox" name="srk_term_robots[noarchive]" value="1" <?php checked( $robots['noarchive'], '1' ); ?> /> 
										<?php esc_html_e( 'No Archive', 'seo-repair-kit' ); ?>
									</label>
									<label class="srk-robots-checkbox">
										<input type="checkbox" name="srk_term_robots[notranslate]" value="1" <?php checked( $robots['notranslate'], '1' ); ?> /> 
										<?php esc_html_e( 'No Translate', 'seo-repair-kit' ); ?>
									</label>
									<label class="srk-robots-checkbox">
										<input type="checkbox" name="srk_term_robots[noimageindex]" value="1" <?php checked( $robots['noimageindex'], '1' ); ?> /> 
										<?php esc_html_e( 'No Image Index', 'seo-repair-kit' ); ?>
									</label>
									<label class="srk-robots-checkbox">
										<input type="checkbox" name="srk_term_robots[nosnippet]" value="1" <?php checked( $robots['nosnippet'], '1' ); ?> /> 
										<?php esc_html_e( 'No Snippet', 'seo-repair-kit' ); ?>
									</label>
									<label class="srk-robots-checkbox">
										<input type="checkbox" name="srk_term_robots[noodp]" value="1" <?php checked( $robots['noodp'], '1' ); ?> /> 
										<?php esc_html_e( 'No ODP', 'seo-repair-kit' ); ?>
									</label>
								</div>
							</div>
							
						</div>
					</div>
				</div>
			</td>
		</tr>
		<?php
	}

	private function render_term_smart_tags_modal( $field_id, $taxonomy, $field_type, $smart_tags ) {
		?>
		<div class="srk-all-tags-modal" id="srk-tags-modal-<?php echo esc_attr( $field_id ); ?>" style="display: none;">
			<div class="srk-modal-header">
				<h3><?php esc_html_e( 'Smart Tags', 'seo-repair-kit' ); ?></h3>
				<button type="button" class="srk-modal-close">&times;</button>
			</div>
			
			<div class="srk-modal-search">
				<input type="text" 
					   class="srk-tag-search" 
					   placeholder="<?php esc_attr_e( 'Search for an item...', 'seo-repair-kit' ); ?>"
					   autocomplete="off">
			</div>
			
			<div class="srk-modal-body">
				<div class="srk-tags-list">
					<?php foreach ( $smart_tags as $tag_key => $tag_info ) : ?>
						<div class="srk-modal-tag-item" 
							 data-tag="<?php echo esc_attr( $tag_info['tag'] ); ?>"
							 data-field-id="<?php echo esc_attr( $field_id ); ?>">
							<div class="srk-tag-icon">+</div>
							<div class="srk-tag-info">
								<strong><?php echo esc_html( $tag_info['label'] ); ?></strong>
								<span class="srk-tag-code"><?php echo esc_html( $tag_info['tag'] ); ?></span>
								<span class="srk-tag-description"><?php echo esc_html( $tag_info['description'] ); ?></span>
							</div>
						</div>
					<?php endforeach; ?>
				</div>
			</div>
			
			<div class="srk-modal-footer">
				<a href="#" class="srk-learn-more">
					<?php esc_html_e( 'Learn more about Smart Tags →', 'seo-repair-kit' ); ?>
				</a>
			</div>
		</div>
		<?php
	}

	private function replace_term_preview_vars( $template, $term ) {
		if ( empty( $template ) || ! $term || ! is_object( $term ) ) {
			return $template;
		}

		$taxonomy_obj = get_taxonomy( $term->taxonomy );

		$term_link = get_term_link( $term );
		if ( is_wp_error( $term_link ) ) {
			$term_link = '';
		}

		$parent_term = '';
		if ( $term->parent ) {
			$parent = get_term( $term->parent, $term->taxonomy );
			if ( $parent && ! is_wp_error( $parent ) ) {
				$parent_term = $parent->name;
			}
		}

		$global = get_option( 'srk_meta_global_settings', array() );
		$sep    = isset( $global['title_separator'] ) ? $global['title_separator'] : '-';

		$replacements = array(
			'%term%'             => $term->name,
			'%term_description%' => ! empty( $term->description ) ? wp_strip_all_tags( $term->description ) : '',
			'%site_title%'       => get_bloginfo( 'name', 'display' ),
			'%sep%'              => $sep,
			'%taxonomy%'         => $taxonomy_obj ? $taxonomy_obj->labels->singular_name : ucfirst( str_replace( '_', ' ', $term->taxonomy ) ),
			'%post_count%'       => $term->count,
			'%permalink%'        => $term_link,
			'%parent_categories%' => $parent_term,
			'%parent_term%'      => $parent_term,
			'%tagline%'          => get_bloginfo( 'description', 'display' ),
			'%sitedesc%'         => get_bloginfo( 'description', 'display' ),
			'%current_date%'     => date_i18n( get_option( 'date_format' ) ),
			'%date%'             => date_i18n( get_option( 'date_format' ) ),
			'%month%'            => date_i18n( 'F' ),
			'%year%'             => date_i18n( 'Y' ),
			'%custom_field%'     => '',
			'%page%'             => '',
		);

		$result = str_replace( array_keys( $replacements ), array_values( $replacements ), $template );

		$result = preg_replace( '/\s+/', ' ', $result );
		$result = trim( $result );

		return $result;
	}

	public function term_save_settings( $term_id, $tt_id = 0 ) {
		if ( ! isset( $_POST['srk_term_seo_nonce'] ) || ! wp_verify_nonce( sanitize_text_field( wp_unslash( $_POST['srk_term_seo_nonce'] ) ), 'srk_term_seo_save' ) ) {
			return;
		}
		$term = get_term( $term_id );
		if ( ! $term || is_wp_error( $term ) ) {
			return;
		}
		$tax_obj = get_taxonomy( $term->taxonomy );
		if ( ! $tax_obj || ! current_user_can( $tax_obj->cap->edit_terms ) ) {
			return;
		}

		$title       = isset( $_POST['srk_term_title'] ) ? sanitize_text_field( wp_unslash( $_POST['srk_term_title'] ) ) : '';
		$description = isset( $_POST['srk_term_description'] ) ? sanitize_textarea_field( wp_unslash( $_POST['srk_term_description'] ) ) : '';

		$use_default = '1';

		if ( isset( $_POST['srk_term_use_default'] ) ) {
			$posted_value = wp_unslash( $_POST['srk_term_use_default'] );
			if ( is_array( $posted_value ) ) {
				$posted_value = end( $posted_value );
			}
			$use_default = ( '1' === $posted_value || 'on' === $posted_value || true === $posted_value ) ? '1' : '0';
		}

		$out = array(
			'title'       => $title,
			'description' => $description,
			'advanced'    => array(
				'use_default_settings' => $use_default,
			),
		);

		if ( '1' !== $use_default && isset( $_POST['srk_term_robots'] ) && is_array( $_POST['srk_term_robots'] ) ) {
			$r = array_map(
				function ( $v ) {
					return ( '1' === $v || 1 === $v ) ? '1' : '0';
				},
				wp_unslash( $_POST['srk_term_robots'] )
			);

			$out['advanced']['robots_meta'] = array(
				'noindex'           => isset( $r['noindex'] ) ? $r['noindex'] : '0',
				'nofollow'          => isset( $r['nofollow'] ) ? $r['nofollow'] : '0',
				'noarchive'         => isset( $r['noarchive'] ) ? $r['noarchive'] : '0',
				'notranslate'       => isset( $r['notranslate'] ) ? $r['notranslate'] : '0',
				'noimageindex'      => isset( $r['noimageindex'] ) ? $r['noimageindex'] : '0',
				'nosnippet'         => isset( $r['nosnippet'] ) ? $r['nosnippet'] : '0',
				'noodp'             => isset( $r['noodp'] ) ? $r['noodp'] : '0',
				'max_snippet'       => isset( $_POST['srk_term_robots']['max_snippet'] ) ? (int) wp_unslash( $_POST['srk_term_robots']['max_snippet'] ) : -1,
				'max_video_preview' => isset( $_POST['srk_term_robots']['max_video_preview'] ) ? (int) wp_unslash( $_POST['srk_term_robots']['max_video_preview'] ) : -1,
				'max_image_preview' => isset( $_POST['srk_term_robots']['max_image_preview'] ) ? sanitize_text_field( wp_unslash( $_POST['srk_term_robots']['max_image_preview'] ) ) : 'large',
			);
		}

		update_term_meta( $term_id, '_srk_term_settings', $out );
	}

	public function render() {
		$taxonomies = $this->get_taxonomies_to_manage();

		?>
		<div class="wrap srk-taxonomy-meta-manager">
			<h2><?php esc_html_e( 'Taxonomy Meta Settings', 'seo-repair-kit' ); ?></h2>
			
			<?php if ( isset( $_GET['settings-updated'] ) && filter_var( wp_unslash( $_GET['settings-updated'] ), FILTER_VALIDATE_BOOLEAN ) ) : ?>
				<div class="notice notice-success is-dismissible">
					<p><?php esc_html_e( 'Taxonomy settings saved successfully!', 'seo-repair-kit' ); ?></p>
				</div>
			<?php endif; ?>
			
			<form method="post" action="options.php">
				<?php
				settings_fields( 'srk_meta_taxonomies_group' );
				?>
				
				<nav class="nav-tab-wrapper srk-taxonomy-tabs">
					<?php foreach ( $taxonomies as $tax_slug => $taxonomy ) : ?>
						<a href="+" 
						   class="nav-tab srk-taxonomy-tab <?php echo 'category' === $tax_slug ? 'nav-tab-active' : ''; ?>" 
						   data-taxonomy="<?php echo esc_attr( $tax_slug ); ?>">
							<?php echo esc_html( $taxonomy->labels->name ); ?>
						</a>
					<?php endforeach; ?>
				</nav>

				<?php foreach ( $taxonomies as $tax_slug => $taxonomy ) :
					$settings = $this->get_taxonomy_settings( $tax_slug );
					?>
					<div class="srk-taxonomy-content <?php echo 'category' === $tax_slug ? 'srk-active-content' : 'srk-hidden-content'; ?>"
						data-taxonomy="<?php echo esc_attr( $tax_slug ); ?>">

						<div class="srk-taxonomy-header">
							<h3><?php printf( esc_html__( '%s Settings', 'seo-repair-kit' ), esc_html( $taxonomy->labels->name ) ); ?></h3>
							<p class="description">
								<?php
								printf(
									esc_html__( 'SEO settings for all %s terms', 'seo-repair-kit' ),
									esc_html( strtolower( $taxonomy->labels->name ) )
								);
								?>
							</p>
						</div>

						<div class="srk-inner-tabs">
							<button type="button" class="srk-inner-tab active" data-tab="basic">
								Title & Description
							</button>
							<button type="button" class="srk-inner-tab" data-tab="advanced">
								Advanced Robots Meta
							</button>
						</div>

						<div class="srk-inner-tab-content active" data-tab="basic">

							<?php $this->render_serp_preview( $tax_slug ); ?>

							<?php $this->render_title_template( $tax_slug, $settings ); ?>

							<?php $this->render_meta_description( $tax_slug, $settings ); ?>

						</div>

						<div class="srk-inner-tab-content" data-tab="advanced">

							<?php $this->render_robots_meta( $tax_slug, $settings ); ?>

						</div>

						<hr class="srk-section-divider">

					</div>
				<?php endforeach; ?>

				
				<div class="srk-submit-section" style="display:flex;justify-content:space-between;align-items:center;margin-top:20px;">

					<button type="button" class="button srk-reset-taxonomies">
						<?php esc_html_e( 'Reset to Defaults', 'seo-repair-kit' ); ?>
					</button>

					<?php submit_button( __( 'Save Changes', 'seo-repair-kit' ), 'primary large', 'submit', false ); ?>

				</div>
			</form>
		</div>
		<?php
	}
}