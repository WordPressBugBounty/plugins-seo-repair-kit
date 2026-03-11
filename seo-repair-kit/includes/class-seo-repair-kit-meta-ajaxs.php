<?php
/**
 * SEO Repair Kit - Meta Manager AJAX & Post Meta Handler
 *
 * @package SEO_Repair_Kit
 * @subpackage Meta_Manager
 * @since 2.1.3
 * @version 2.1.3
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * AJAX Handler for Meta Manager Settings
 */
class SRK_Meta_Ajax_Handler {

	public function __construct() {
		add_action( 'wp_ajax_srk_meta_reset_all_settings', array( $this, 'reset_all_settings' ) );
		add_action( 'wp_ajax_srk_meta_save_settings', array( $this, 'save_settings' ) );
		add_action( 'wp_ajax_srk_meta_get_settings', array( $this, 'get_settings' ) );
		add_action( 'wp_ajax_srk_meta_reset_taxonomy', array( $this, 'reset_taxonomy_settings' ) );
		add_action( 'wp_ajax_srk_sync_meta_data', array( $this, 'srk_sync_meta_data' ) );
		add_action( 'admin_init', array( $this, 'verify_admin_capabilities' ) );
	}

	public function save_elementor_meta() {
		if ( ! isset( $_POST['nonce'] ) || ! wp_verify_nonce( sanitize_text_field( wp_unslash( $_POST['nonce'] ) ), 'srk_elementor_nonce' ) ) {
			wp_send_json_error( array( 'message' => 'Security check failed.' ) );
			return;
		}

		$post_id   = isset( $_POST['post_id'] ) ? intval( wp_unslash( $_POST['post_id'] ) ) : 0;
		$meta_data = isset( $_POST['meta_data'] ) ? json_decode( wp_unslash( $_POST['meta_data'] ), true ) : array();

		if ( ! $post_id || empty( $meta_data ) ) {
			wp_send_json_error( array( 'message' => 'Invalid data.' ) );
			return;
		}

		if ( isset( $meta_data['meta_title'] ) ) {
			update_post_meta( $post_id, '_srk_meta_title', sanitize_text_field( $meta_data['meta_title'] ) );
		}

		if ( isset( $meta_data['meta_description'] ) ) {
			update_post_meta( $post_id, '_srk_meta_description', sanitize_textarea_field( $meta_data['meta_description'] ) );
		}

		if ( isset( $meta_data['focus_keyword'] ) ) {
			update_post_meta( $post_id, '_srk_focus_keyword', sanitize_text_field( $meta_data['focus_keyword'] ) );
		}

		if ( isset( $meta_data['meta_keywords'] ) ) {
			update_post_meta( $post_id, '_srk_meta_keywords', sanitize_text_field( $meta_data['meta_keywords'] ) );
		}

		if ( isset( $meta_data['canonical_url'] ) ) {
			update_post_meta( $post_id, '_srk_canonical_url', esc_url_raw( $meta_data['canonical_url'] ) );
		}

		if ( isset( $meta_data['advanced_settings'] ) ) {
			update_post_meta( $post_id, '_srk_advanced_settings', $meta_data['advanced_settings'] );
		}

		update_post_meta( $post_id, '_srk_last_sync', current_time( 'timestamp' ) );

		wp_send_json_success( array( 'message' => 'Meta data saved successfully.' ) );
	}

	public function srk_sync_meta_data() {
		if ( ! isset( $_POST['nonce'] ) || ! wp_verify_nonce( sanitize_text_field( wp_unslash( $_POST['nonce'] ) ), 'srk_elementor_nonce' ) ) {
			wp_send_json_error( array( 'message' => 'Security check failed.' ) );
			return;
		}

		$post_id = isset( $_POST['post_id'] ) ? intval( wp_unslash( $_POST['post_id'] ) ) : 0;

		if ( ! $post_id ) {
			wp_send_json_error( array( 'message' => 'No post ID provided.' ) );
			return;
		}

		$advanced_settings = get_post_meta( $post_id, '_srk_advanced_settings', true );
		$last_sync         = get_post_meta( $post_id, '_srk_last_sync', true );

		wp_send_json_success(
			array(
				'advanced_settings' => $advanced_settings,
				'last_sync'         => $last_sync ?: 0,
			)
		);
	}

	public function verify_admin_capabilities() {
		if ( defined( 'DOING_AJAX' ) && DOING_AJAX ) {
			$action = isset( $_REQUEST['action'] ) ? sanitize_text_field( wp_unslash( $_REQUEST['action'] ) ) : '';

			if ( 0 === strpos( $action, 'srk_meta_' ) ) {
				if ( ! is_user_logged_in() || ! current_user_can( 'manage_options' ) ) {
					wp_die( esc_html__( 'You do not have sufficient permissions to access this page.', 'seo-repair-kit' ) );
				}
			}
		}
	}

	public function reset_all_settings() {
		if ( ! isset( $_POST['nonce'] ) || ! wp_verify_nonce( sanitize_text_field( wp_unslash( $_POST['nonce'] ) ), 'srk_meta_reset_all' ) ) {
			wp_send_json_error(
				array(
					'message' => __( 'Security check failed. Please refresh the page and try again.', 'seo-repair-kit' ),
				)
			);
			return;
		}

		if ( ! current_user_can( 'manage_options' ) ) {
			wp_send_json_error(
				array(
					'message' => __( 'You do not have permission to perform this action.', 'seo-repair-kit' ),
				)
			);
			return;
		}

		try {
			$settings_to_reset = array(
				'srk_meta_site_title_template' => '%title% > %site_title%',
				'srk_meta_description_length'  => '160',
				'srk_meta_default_index'       => '1',
				'srk_meta_default_follow'      => '1',
				'srk_meta_content_types'       => array(),
				'srk_meta_taxonomies'          => array(),
				'srk_enable_author_archive'    => '1',
				'srk_author_archive_title'     => '',
				'srk_enable_date_archive'      => '1',
				'srk_date_archive_title'       => '',
				'srk_search_title'             => '',
				'srk_enable_xml_sitemap'       => '1',
				'srk_enable_open_graph'        => '1',
				'srk_enable_twitter_cards'     => '1',
				'srk_enable_json_ld'           => '1',
				'srk_noindex_search'           => '1',
				'srk_noindex_attachment'       => '1',
				'srk_noindex_pagination'       => '1',
			);

			foreach ( $settings_to_reset as $key => $value ) {
				update_option( $key, $value );
			}

			$post_types = get_post_types( array( 'public' => true ) );
			foreach ( $post_types as $post_type ) {
				update_option( 'srk_enable_' . $post_type, '1' );
				update_option( 'srk_title_' . $post_type, '' );
			}

			if ( function_exists( 'wp_cache_flush' ) ) {
				wp_cache_flush();
			}

			do_action( 'srk_meta_settings_reset' );

			wp_send_json_success(
				array(
					'message'  => __( 'All settings have been reset to their default values.', 'seo-repair-kit' ),
					'redirect' => admin_url( 'admin.php?page=seo-repair-kit-meta-manager&tab=global&reset=success' ),
				)
			);
		} catch ( Exception $e ) {
			wp_send_json_error(
				array(
					'message' => sprintf( __( 'Error resetting settings: %s', 'seo-repair-kit' ), $e->getMessage() ),
				)
			);
		}
	}

	public function reset_taxonomy_settings() {
		if ( ! isset( $_POST['nonce'] ) || ! wp_verify_nonce( sanitize_text_field( wp_unslash( $_POST['nonce'] ) ), 'srk_taxonomy_reset' ) ) {
			wp_send_json_error( array( 'message' => __( 'Security check failed.', 'seo-repair-kit' ) ) );
			return;
		}

		if ( ! current_user_can( 'manage_options' ) ) {
			wp_send_json_error( array( 'message' => __( 'Permission denied.', 'seo-repair-kit' ) ) );
			return;
		}

		$taxonomy = isset( $_POST['taxonomy'] ) ? sanitize_key( wp_unslash( $_POST['taxonomy'] ) ) : '';

		if ( empty( $taxonomy ) ) {
			wp_send_json_error( array( 'message' => __( 'No taxonomy specified.', 'seo-repair-kit' ) ) );
			return;
		}

		try {
			$all_settings = get_option( 'srk_meta_taxonomies_settings', array() );

			$defaults = array(
				'category' => array(
					'search_visibility'      => true,
					'title_template'         => '%term% Archives %sep% %site_title%',
					'description_template'   => '%term_description%',
					'robots'                 => array(
						'index'             => true,
						'noindex'           => false,
						'follow'            => true,
						'nofollow'          => false,
						'noarchive'         => false,
						'noimageindex'      => false,
						'nosnippet'         => false,
						'max_snippet'       => -1,
						'max_image_preview' => 'large',
						'max_video_preview' => -1,
					),
				),
				'post_tag' => array(
					'search_visibility'      => true,
					'title_template'         => '%term% Tag %sep% %site_title%',
					'description_template'   => '%term_description%',
					'robots'                 => array(
						'index'             => true,
						'noindex'           => false,
						'follow'            => true,
						'nofollow'          => false,
						'noarchive'         => false,
						'noimageindex'      => false,
						'nosnippet'         => false,
						'max_snippet'       => -1,
						'max_image_preview' => 'large',
						'max_video_preview' => -1,
					),
				),
			);

			if ( isset( $defaults[ $taxonomy ] ) ) {
				$all_settings[ $taxonomy ] = $defaults[ $taxonomy ];
				update_option( 'srk_meta_taxonomies_settings', $all_settings );

				wp_send_json_success(
					array(
						'message'  => sprintf( __( '%s settings reset to defaults.', 'seo-repair-kit' ), $taxonomy ),
						'settings' => $defaults[ $taxonomy ],
					)
				);
			} else {
				wp_send_json_error( array( 'message' => __( 'Taxonomy not found.', 'seo-repair-kit' ) ) );
			}
		} catch ( Exception $e ) {
			wp_send_json_error(
				array(
					'message' => sprintf( __( 'Error: %s', 'seo-repair-kit' ), $e->getMessage() ),
				)
			);
		}
	}

	public function save_settings() {
		if ( ! isset( $_POST['nonce'] ) || ! wp_verify_nonce( sanitize_text_field( wp_unslash( $_POST['nonce'] ) ), 'srk_meta_save_settings' ) ) {
			wp_send_json_error(
				array(
					'message' => __( 'Security check failed.', 'seo-repair-kit' ),
				)
			);
			return;
		}

		if ( ! current_user_can( 'manage_options' ) ) {
			wp_send_json_error(
				array(
					'message' => __( 'Permission denied.', 'seo-repair-kit' ),
				)
			);
			return;
		}

		$settings = isset( $_POST['settings'] ) ? wp_unslash( $_POST['settings'] ) : array();

		if ( empty( $settings ) ) {
			wp_send_json_error(
				array(
					'message' => __( 'No settings data received.', 'seo-repair-kit' ),
				)
			);
			return;
		}

		try {
			foreach ( $settings as $key => $value ) {
				if ( is_array( $value ) ) {
					$value = array_map( 'sanitize_text_field', $value );
				} else {
					$value = sanitize_text_field( $value );
				}

				update_option( $key, $value );
			}

			if ( function_exists( 'wp_cache_flush' ) ) {
				wp_cache_flush();
			}

			wp_send_json_success(
				array(
					'message' => __( 'Settings saved successfully.', 'seo-repair-kit' ),
				)
			);
		} catch ( Exception $e ) {
			wp_send_json_error(
				array(
					'message' => sprintf( __( 'Error saving settings: %s', 'seo-repair-kit' ), $e->getMessage() ),
				)
			);
		}
	}

	public function get_settings() {
		if ( ! isset( $_POST['nonce'] ) || ! wp_verify_nonce( sanitize_text_field( wp_unslash( $_POST['nonce'] ) ), 'srk_meta_get_settings' ) ) {
			wp_send_json_error(
				array(
					'message' => __( 'Security check failed.', 'seo-repair-kit' ),
				)
			);
			return;
		}

		if ( ! current_user_can( 'manage_options' ) ) {
			wp_send_json_error(
				array(
					'message' => __( 'Permission denied.', 'seo-repair-kit' ),
				)
			);
			return;
		}

		$setting_keys = isset( $_POST['keys'] ) ? wp_unslash( $_POST['keys'] ) : array();

		if ( ! is_array( $setting_keys ) || empty( $setting_keys ) ) {
			wp_send_json_error(
				array(
					'message' => __( 'No setting keys specified.', 'seo-repair-kit' ),
				)
			);
			return;
		}

		try {
			$settings = array();
			foreach ( $setting_keys as $key ) {
				$settings[ $key ] = get_option( $key );
			}

			wp_send_json_success(
				array(
					'settings' => $settings,
				)
			);
		} catch ( Exception $e ) {
			wp_send_json_error(
				array(
					'message' => sprintf( __( 'Error retrieving settings: %s', 'seo-repair-kit' ), $e->getMessage() ),
				)
			);
		}
	}
}

/**
 * Post Meta Handler - Manages individual post SEO meta fields
 */
class SRK_Post_Meta_Handler {

	const META_KEY_TITLE       = '_srk_meta_title';
	const META_KEY_DESCRIPTION = '_srk_meta_description';
	const META_KEY_ROBOTS      = '_srk_robots_meta';
	const META_KEY_CANONICAL   = '_srk_canonical_url';

	public function __construct() {
		add_action( 'save_post', array( $this, 'save_post_meta' ), 10, 2 );
		add_action( 'admin_init', array( $this, 'srk_save_advanced_settings' ) );
	}

	public function srk_save_advanced_settings() {
		if ( ! isset( $_POST['option_page'] ) || 'srk_meta_advanced_settings' !== sanitize_key( wp_unslash( $_POST['option_page'] ) ) ) {
			return;
		}

		if ( ! current_user_can( 'manage_options' ) ) {
			return;
		}

		check_admin_referer( 'srk_meta_advanced_settings-options' );

		$advanced_settings = array();

		$advanced_settings['use_default_settings'] = isset( $_POST['srk_use_default_settings'] ) ? '1' : '0';

		if ( isset( $_POST['srk_robots_meta'] ) && is_array( $_POST['srk_robots_meta'] ) ) {
			$robots_meta   = array();
			$robots_fields = array(
				'noindex',
				'nofollow',
				'noarchive',
				'notranslate',
				'noimageindex',
				'nosnippet',
				'noodp',
				'nofollow_paginated',
				'noindex_rss_feeds',
				'noindex_paginated',
			);

			foreach ( $robots_fields as $field ) {
				$robots_meta[ $field ] = isset( $_POST['srk_robots_meta'][ $field ] ) ? '1' : '0';
			}

			$robots_meta['max_snippet'] = isset( $_POST['srk_robots_meta']['max_snippet'] )
				? intval( wp_unslash( $_POST['srk_robots_meta']['max_snippet'] ) )
				: '-1';

			$robots_meta['max_video_preview'] = isset( $_POST['srk_robots_meta']['max_video_preview'] )
				? intval( wp_unslash( $_POST['srk_robots_meta']['max_video_preview'] ) )
				: '-1';

			$robots_meta['max_image_preview'] = isset( $_POST['srk_robots_meta']['max_image_preview'] )
				? sanitize_text_field( wp_unslash( $_POST['srk_robots_meta']['max_image_preview'] ) )
				: 'large';

			$advanced_settings['robots_meta'] = $robots_meta;
		}

		$advanced_settings['use_meta_keywords'] = isset( $_POST['srk_use_meta_keywords'] )
			? sanitize_text_field( wp_unslash( $_POST['srk_use_meta_keywords'] ) )
			: '0';

		$advanced_settings['run_shortcodes'] = isset( $_POST['srk_run_shortcodes'] )
			? sanitize_text_field( wp_unslash( $_POST['srk_run_shortcodes'] ) )
			: '0';

		$advanced_settings['paged_format'] = isset( $_POST['srk_paged_format'] )
			? sanitize_text_field( wp_unslash( $_POST['srk_paged_format'] ) )
			: '%page%';

		$advanced_settings['paged_separator'] = isset( $_POST['srk_paged_separator'] )
			? sanitize_text_field( wp_unslash( $_POST['srk_paged_separator'] ) )
			: '-';

		$advanced_settings['paged_format_type'] = isset( $_POST['srk_paged_format_type'] )
			? sanitize_text_field( wp_unslash( $_POST['srk_paged_format_type'] ) )
			: 'page';

		$srk_meta = get_option( 'srk_meta', array() );
		if ( ! is_array( $srk_meta ) ) {
			$srk_meta = array();
		}
		$srk_meta['advanced'] = $advanced_settings;
		update_option( 'srk_meta', $srk_meta );

		update_option( 'srk_use_default_settings', $advanced_settings['use_default_settings'] );
		update_option( 'srk_use_meta_keywords', $advanced_settings['use_meta_keywords'] );
		update_option( 'srk_run_shortcodes', $advanced_settings['run_shortcodes'] );
		update_option( 'srk_paged_format', $advanced_settings['paged_format'] );
		update_option( 'srk_paged_separator', $advanced_settings['paged_separator'] );
		update_option( 'srk_paged_format_type', $advanced_settings['paged_format_type'] );

		if ( isset( $advanced_settings['robots_meta'] ) ) {
			update_option( 'srk_robots_meta', $advanced_settings['robots_meta'] );
		}
	}

	public function add_meta_boxes() {
	}

	public function render_meta_box( $post ) {
		wp_nonce_field( 'srk_save_post_meta', 'srk_post_meta_nonce' );

		$meta_title       = get_post_meta( $post->ID, self::META_KEY_TITLE, true );
		$meta_description = get_post_meta( $post->ID, self::META_KEY_DESCRIPTION, true );
		$robots_meta      = get_post_meta( $post->ID, self::META_KEY_ROBOTS, true );
		if ( ! is_array( $robots_meta ) ) {
			$robots_meta = array();
		}
		$canonical_url = get_post_meta( $post->ID, self::META_KEY_CANONICAL, true );

		$srk_meta        = get_option( 'srk_meta', array() );
		$global_settings = isset( $srk_meta['global'] ) && is_array( $srk_meta['global'] )
			? $srk_meta['global']
			: array();
		$separator       = isset( $global_settings['title_separator'] )
			? $global_settings['title_separator']
			: get_option( 'srk_title_separator', '-' );
		?>
		<div class="srk-post-meta-fields">
			<table class="form-table">
				<tr>
					<th scope="row">
						<label for="srk_meta_title"><?php esc_html_e( 'Meta Title', 'seo-repair-kit' ); ?></label>
					</th>
					<td>
						<input type="text" 
							   id="srk_meta_title" 
							   name="srk_meta_title" 
							   value="<?php echo esc_attr( $meta_title ); ?>" 
							   class="regular-text" 
							   placeholder="<?php esc_attr_e( 'Leave empty to use template', 'seo-repair-kit' ); ?>" />
						<p class="description">
							<?php esc_html_e( 'Custom meta title for this post. Leave empty to use content type or global template.', 'seo-repair-kit' ); ?>
						</p>
						<?php if ( ! empty( $meta_title ) ) : ?>
							<div class="srk-preview" style="margin-top: 10px; padding: 10px; background: #f9f9f9; border: 1px solid #ddd; border-radius: 4px;">
								<div style="color: #70757a; font-size: 12px;"><?php echo esc_url( get_permalink( $post->ID ) ); ?></div>
								<div style="color: #1a0dab; font-size: 16px; margin-top: 3px;">
									<?php echo esc_html( SRK_Meta_Resolver::parse_template( $meta_title, $post->ID ) ); ?>
								</div>
							</div>
						<?php endif; ?>
					</td>
				</tr>

				<tr>
					<th scope="row">
						<label for="srk_meta_description"><?php esc_html_e( 'Meta Description', 'seo-repair-kit' ); ?></label>
					</th>
					<td>
						<textarea id="srk_meta_description" 
								  name="srk_meta_description" 
								  rows="3" 
								  class="large-text" 
								  placeholder="<?php esc_attr_e( 'Leave empty to use template', 'seo-repair-kit' ); ?>"><?php echo esc_textarea( $meta_description ); ?></textarea>
						<p class="description">
							<?php esc_html_e( 'Custom meta description for this post. Leave empty to use content type or global template.', 'seo-repair-kit' ); ?>
						</p>
						<?php if ( ! empty( $meta_description ) ) : ?>
							<div class="srk-preview" style="margin-top: 10px; padding: 10px; background: #f9f9f9; border: 1px solid #ddd; border-radius: 4px;">
								<div style="color: #3c4043; font-size: 13px;">
									<?php echo esc_html( SRK_Meta_Resolver::parse_template( $meta_description, $post->ID ) ); ?>
								</div>
							</div>
						<?php endif; ?>
					</td>
				</tr>

				<tr>
					<th scope="row">
						<label><?php esc_html_e( 'Robots Meta', 'seo-repair-kit' ); ?></label>
					</th>
					<td>
						<fieldset>
							<label>
								<input type="checkbox" 
									   name="srk_robots_meta[index]" 
									   value="1" 
									   <?php checked( ! empty( $robots_meta['index'] ), true ); ?> />
								<?php esc_html_e( 'Index', 'seo-repair-kit' ); ?>
							</label>
							<br>
							<label>
								<input type="checkbox" 
									   name="srk_robots_meta[noindex]" 
									   value="1" 
									   <?php checked( ! empty( $robots_meta['noindex'] ), true ); ?> />
								<?php esc_html_e( 'Noindex', 'seo-repair-kit' ); ?>
							</label>
							<br>
							<label>
								<input type="checkbox" 
									   name="srk_robots_meta[follow]" 
									   value="1" 
									   <?php checked( ! empty( $robots_meta['follow'] ), true ); ?> />
								<?php esc_html_e( 'Follow', 'seo-repair-kit' ); ?>
							</label>
							<br>
							<label>
								<input type="checkbox" 
									   name="srk_robots_meta[nofollow]" 
									   value="1" 
									   <?php checked( ! empty( $robots_meta['nofollow'] ), true ); ?> />
								<?php esc_html_e( 'Nofollow', 'seo-repair-kit' ); ?>
							</label>
						</fieldset>
						<p class="description">
							<?php esc_html_e( 'Override robots meta for this post. Leave unchecked to use content type or global defaults.', 'seo-repair-kit' ); ?>
						</p>
					</td>
				</tr>

				<tr>
					<th scope="row">
						<label for="srk_canonical_url"><?php esc_html_e( 'Canonical URL', 'seo-repair-kit' ); ?></label>
					</th>
					<td>
						<input type="url" 
							   id="srk_canonical_url" 
							   name="srk_canonical_url" 
							   value="<?php echo esc_url( $canonical_url ); ?>" 
							   class="regular-text" 
							   placeholder="<?php esc_attr_e( 'Leave empty to use permalink', 'seo-repair-kit' ); ?>" />
						<p class="description">
							<?php esc_html_e( 'Custom canonical URL for this post. Leave empty to use the post permalink.', 'seo-repair-kit' ); ?>
						</p>
					</td>
				</tr>
			</table>
		</div>
		<?php
	}

	public function save_post_meta( $post_id, $post ) {
		if ( ! isset( $_POST['srk_post_meta_nonce'] ) ||
			! wp_verify_nonce( sanitize_text_field( wp_unslash( $_POST['srk_post_meta_nonce'] ) ), 'srk_save_post_meta' ) ) {
			return;
		}

		if ( defined( 'DOING_AUTOSAVE' ) && DOING_AUTOSAVE ) {
			return;
		}

		if ( ! current_user_can( 'edit_post', $post_id ) ) {
			return;
		}

		if ( isset( $_POST['srk_meta_title'] ) ) {
			$meta_title = sanitize_text_field( wp_unslash( $_POST['srk_meta_title'] ) );
			if ( ! empty( $meta_title ) ) {
				update_post_meta( $post_id, self::META_KEY_TITLE, $meta_title );
			} else {
				delete_post_meta( $post_id, self::META_KEY_TITLE );
			}
		}

		if ( isset( $_POST['srk_meta_description'] ) ) {
			$meta_description = sanitize_textarea_field( wp_unslash( $_POST['srk_meta_description'] ) );
			if ( ! empty( $meta_description ) ) {
				update_post_meta( $post_id, self::META_KEY_DESCRIPTION, $meta_description );
			} else {
				delete_post_meta( $post_id, self::META_KEY_DESCRIPTION );
			}
		}

		if ( isset( $_POST['srk_robots_meta'] ) && is_array( $_POST['srk_robots_meta'] ) ) {
			$robots_meta = array(
				'index'    => ! empty( $_POST['srk_robots_meta']['index'] ) ? 1 : 0,
				'noindex'  => ! empty( $_POST['srk_robots_meta']['noindex'] ) ? 1 : 0,
				'follow'   => ! empty( $_POST['srk_robots_meta']['follow'] ) ? 1 : 0,
				'nofollow' => ! empty( $_POST['srk_robots_meta']['nofollow'] ) ? 1 : 0,
			);

			if ( array_sum( $robots_meta ) > 0 ) {
				update_post_meta( $post_id, self::META_KEY_ROBOTS, $robots_meta );
			} else {
				delete_post_meta( $post_id, self::META_KEY_ROBOTS );
			}
		}

		if ( isset( $_POST['srk_canonical_url'] ) ) {
			$canonical_url = esc_url_raw( wp_unslash( $_POST['srk_canonical_url'] ) );
			if ( ! empty( $canonical_url ) ) {
				update_post_meta( $post_id, self::META_KEY_CANONICAL, $canonical_url );
			} else {
				delete_post_meta( $post_id, self::META_KEY_CANONICAL );
			}
		}
	}

	public static function get_meta_title( $post_id ) {
		return get_post_meta( $post_id, self::META_KEY_TITLE, true );
	}

	public static function get_meta_description( $post_id ) {
		return get_post_meta( $post_id, self::META_KEY_DESCRIPTION, true );
	}

	public static function get_robots_meta( $post_id ) {
		$robots = get_post_meta( $post_id, self::META_KEY_ROBOTS, true );
		return is_array( $robots ) ? $robots : array();
	}

	public static function get_canonical_url( $post_id ) {
		return get_post_meta( $post_id, self::META_KEY_CANONICAL, true );
	}
}

add_action(
	'plugins_loaded',
	function () {
		if ( is_admin() ) {
			new SRK_Meta_Ajax_Handler();
		}
	}
);

if ( ! function_exists( 'srk_meta_is_ajax_handler_loaded' ) ) {
	function srk_meta_is_ajax_handler_loaded() {
		return class_exists( 'SRK_Meta_Ajax_Handler' );
	}
}