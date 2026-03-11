<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Main Meta Manager Controller
 */
class SRK_Meta_Manager_Main {

	/**
	 * Plugin base path
	 *
	 * @var string
	 */
	private $srk_plugin_path;

	/**
	 * Plugin base URL
	 *
	 * @var string
	 */
	private $srk_plugin_url;

	/**
	 * Meta manager tabs
	 *
	 * @var array
	 */
	private $srk_tabs = array();

	/**
	 * Constructor
	 */
	public function __construct() {
		$this->srk_plugin_path = plugin_dir_path( __FILE__ );
		$this->srk_plugin_url  = plugin_dir_url( __FILE__ );
		$this->srk_init_tabs();
		$this->srk_init_hooks();
	}

	/**
	 * Register hooks
	 */
	private function srk_init_hooks() {
		add_action( 'admin_menu', array( $this, 'srk_register_meta_manager_page' ) );
		add_action( 'admin_init', array( $this, 'srk_register_settings' ) );
		add_action( 'admin_enqueue_scripts', array( $this, 'srk_enqueue_admin_scripts' ) );
		add_action( 'admin_init', array( $this, 'srk_save_global_settings_to_meta' ), 20 );
		add_action( 'admin_init', array( $this, 'srk_migrate_global_settings_to_meta' ) );
		add_action( 'wp_ajax_srk_calculate_seo_score', 'your_calculation_function' );
		add_action( 'admin_init', array( $this, 'srk_save_archives_settings' ) );
		add_action( 'admin_init', array( $this, 'srk_force_clean_meta' ) );
		add_action( 'admin_init', array( $this, 'srk_migrate_content_types_tags' ), 15 );
		add_action( 'wp_ajax_srk_calculate_seo_score', array( $this, 'srk_calculate_seo_score' ) );
		add_action(
			'plugins_loaded',
			function () {
				if ( did_action( 'elementor/loaded' ) || class_exists( '\Elementor\Plugin' ) ) {
					require_once plugin_dir_path( __FILE__ ) . 'includes/class-seo-repair-kit-elementor-integration.php';
				}
			}
		);
	}

	/**
	 * AJAX: Calculate SEO score (placeholder-safe).
	 */
	public function srk_calculate_seo_score() {
		if ( ! current_user_can( 'edit_posts' ) ) {
			wp_send_json_error(
				array( 'message' => __( 'Permission denied.', 'seo-repair-kit' ) ),
				403
			);
		}

		if ( isset( $_POST['nonce'] ) && ! wp_verify_nonce( sanitize_text_field( wp_unslash( $_POST['nonce'] ) ), 'srk_unified_nonce' ) ) {
			wp_send_json_error(
				array( 'message' => __( 'Security check failed.', 'seo-repair-kit' ) ),
				400
			);
		}

		wp_send_json_success(
			array(
				'score'   => 0,
				'status'  => 'not_implemented',
				'message' => __( 'SEO score calculation is not implemented yet.', 'seo-repair-kit' ),
			)
		);
	}

	/**
	 * Force clean meta
	 */
	public function srk_force_clean_meta() {
		$srk_meta = get_option( 'srk_meta', array() );

		if ( ! isset( $srk_meta['advanced'] ) ) {
			return;
		}

		$remove_keys = array(
			'use_meta_keywords',
			'run_shortcodes',
			'paged_format',
			'paged_separator',
			'paged_format_type',
		);

		$changed = false;

		foreach ( $remove_keys as $key ) {
			if ( isset( $srk_meta['advanced'][ $key ] ) ) {
				unset( $srk_meta['advanced'][ $key ] );
				$changed = true;
			}
		}

		if ( $changed ) {
			update_option( 'srk_meta', $srk_meta );
		}
	}

	/**
	 * Enqueue admin scripts and styles
	 */
	public function srk_enqueue_admin_scripts( $hook ) {
		$page = isset( $_GET['page'] ) ? sanitize_key( wp_unslash( $_GET['page'] ) ) : '';
		if ( 'seo-repair-kit-meta-manager' !== $page ) {
			return;
		}

		wp_enqueue_media();

		wp_enqueue_style(
			'srk-meta-manager-css',
			$this->srk_plugin_url . 'css/seo-repair-kit-meta-manager.css',
			array(),
			'2.1.3'
		);

		$plugin_root_url = plugin_dir_url( dirname( dirname( __FILE__ ) ) );

		wp_enqueue_script(
			'srk-meta-global-js',
			$plugin_root_url . 'admin/js/meta-manager-js/srk-meta-global.js',
			array( 'jquery' ),
			'2.1.3',
			true
		);

		wp_enqueue_script(
			'srk-meta-content-js',
			$plugin_root_url . 'admin/js/meta-manager-js/srk-meta-content.js',
			array( 'jquery' ),
			'2.1.3',
			true
		);

		wp_enqueue_script(
			'srk-meta-advanced-js',
			$plugin_root_url . 'admin/js/meta-manager-js/srk-meta-advanced.js',
			array( 'jquery' ),
			'2.1.3',
			true
		);

		wp_enqueue_script(
			'srk-meta-taxonomies-js',
			$plugin_root_url . 'admin/js/meta-manager-js/srk-meta-taxonomies.js',
			array( 'jquery' ),
			'2.1.3',
			true
		);

		wp_enqueue_script(
			'srk-meta-archives-js',
			$plugin_root_url . 'admin/js/meta-manager-js/srk-meta-archives.js',
			array( 'jquery' ),
			'2.1.3',
			true
		);

		$srk_active_tab = isset( $_GET['tab'] ) ? sanitize_key( wp_unslash( $_GET['tab'] ) ) : 'global';

		if ( 'archives' === $srk_active_tab ) {
			wp_enqueue_script(
				'srk-meta-archives-js',
				$plugin_root_url . 'admin/js/meta-manager/srk-meta-archives.js',
				array( 'jquery' ),
				'2.1.3',
				true
			);
		}

		if ( 'archives' === $srk_active_tab ) {
			$current_user = wp_get_current_user();
			$author_name  = $current_user->display_name ?: ( $current_user->user_login ?: 'Admin' );
			$author_bio   = get_the_author_meta( 'description', $current_user->ID ) ?: 'Author biography text...';

			$srk_meta          = get_option( 'srk_meta', array() );
			$archives_settings = isset( $srk_meta['archives'] ) && is_array( $srk_meta['archives'] )
				? $srk_meta['archives']
				: array();

			$global_settings = isset( $srk_meta['global'] ) && is_array( $srk_meta['global'] )
				? $srk_meta['global']
				: array();
			$separator       = isset( $global_settings['title_separator'] )
				? $global_settings['title_separator']
				: get_option( 'srk_title_separator', '-' );

			$current_date = date_i18n( 'F Y' );
			$full_date    = date_i18n( 'F j, Y' );
			$day          = date_i18n( 'j' );
			$month        = date_i18n( 'F' );
			$year         = date_i18n( 'Y' );

			wp_localize_script(
				'srk-meta-archives-js',
				'srkArchivesData',
				array(
					'ajax_url'         => admin_url( 'admin-ajax.php' ),
					'nonce'            => wp_create_nonce( 'srk_archives_nonce' ),
					'current_settings' => $archives_settings,
					'separator'        => $separator,
					'site_name'        => get_bloginfo( 'name' ) ?: 'Site Name',
					'site_description' => get_bloginfo( 'description' ) ?: 'Site Description',
					'author_name'      => $author_name,
					'author_bio'       => $author_bio,
					'current_date'     => $current_date,
					'full_date'        => $full_date,
					'day'              => $day,
					'month'            => $month,
					'year'             => $year,
					'strings'          => array(
						'saving'        => __( 'Saving...', 'seo-repair-kit' ),
						'saved'         => __( 'Settings saved!', 'seo-repair-kit' ),
						'error'         => __( 'Error saving settings', 'seo-repair-kit' ),
						'confirm_reset' => __( 'Are you sure you want to reset all settings to defaults?', 'seo-repair-kit' ),
					),
				)
			);
		}

		$current_separator = get_option( 'srk_title_separator', '-' );
		$current_template  = get_option(
			'srk_meta_site_title_template',
			'%title% | %site_title%'
		);

		$preview_title = str_replace(
			array( '%title%', '%site_title%', '%sitedesc%' ),
			array(
				__( 'Sample Page Title', 'seo-repair-kit' ),
				get_bloginfo( 'name' ),
				get_bloginfo( 'description' ),
			),
			$current_template
		);

		$preview_title = str_replace( '|', $current_separator, $preview_title );

		$site_author = get_users(
			array(
				'role'   => 'administrator',
				'number' => 1,
			)
		);

		$author_data = array(
			'first_name'   => '',
			'last_name'    => '',
			'display_name' => '',
		);

		if ( ! empty( $site_author ) ) {
			$author_id = $site_author[0]->ID;

			$author_data = array(
				'first_name'   => get_user_meta( $author_id, 'first_name', true ),
				'last_name'    => get_user_meta( $author_id, 'last_name', true ),
				'display_name' => get_the_author_meta( 'display_name', $author_id ),
			);
		}

		wp_localize_script(
			'srk-meta-global-js',
			'srkMetaPreview',
			array(
				'site_name'          => get_bloginfo( 'name' ),
				'site_description'   => get_bloginfo( 'description' ),
				'default_page_title' => __( 'Sample Page Title', 'seo-repair-kit' ),
				'initial_preview'    => $preview_title,
				'initial_template'   => $current_template,
				'initial_separator'  => $current_separator,
				'site_author'        => $author_data,
			)
		);

		wp_localize_script(
			'srk-meta-global-js',
			'srkMetaGlobal',
			array(
				'nonce'         => wp_create_nonce( 'srk_meta_reset_all' ),
				'confirm_reset' => __( 'Are you sure? This action cannot be undone.', 'seo-repair-kit' ),
				'reset_success' => __( 'Settings reset successfully!', 'seo-repair-kit' ),
			)
		);

		$current_user        = wp_get_current_user();
		$author_first_name   = $current_user->first_name ?: 'John';
		$author_last_name    = $current_user->last_name ?: 'Doe';
		$author_display_name = $current_user->display_name ?: 'John Doe';

		$sample_post = get_posts(
			array(
				'numberposts' => 1,
				'post_status' => 'publish',
				'post_type'   => 'post',
			)
		);

		$sample_categories     = 'Technology, SEO';
		$sample_category_title = 'Technology';

		if ( ! empty( $sample_post ) ) {
			$post_id    = $sample_post[0]->ID;
			$categories = get_the_category( $post_id );
			if ( ! empty( $categories ) ) {
				$cat_names             = wp_list_pluck( $categories, 'name' );
				$sample_categories     = implode( ', ', $cat_names );
				$sample_category_title = $categories[0]->name;
			}
		}

		wp_localize_script(
			'srk-meta-content-js',
			'srkMetaData',
			array(
				'siteUrl'         => esc_url( get_site_url() ),
				'siteName'        => get_bloginfo( 'name' ),
				'siteDesc'        => get_bloginfo( 'description' ),
				'defaultTitle'    => '%title% | %site_title%',
				'defaultDesc'     => '%excerpt%',
				'ajax_url'        => admin_url( 'admin-ajax.php' ),
				'nonce'           => wp_create_nonce( 'srk_content_nonce' ),
				'separator'       => get_option( 'srk_title_separator', '-' ),
				'authorFirstName' => $author_first_name,
				'authorLastName'  => $author_last_name,
				'authorName'      => $author_display_name,
				'categoryTitle'   => $sample_category_title,
				'currentDate'     => date_i18n( 'F j, Y' ),
				'currentDay'      => date_i18n( 'd' ),
				'currentMonth'    => date_i18n( 'F' ),
				'currentYear'     => date_i18n( 'Y' ),
				'customField'     => 'Custom Field Value',
				'permalink'       => esc_url( get_site_url() . '/sample-post/' ),
				'postContent'     => 'Sample post content text...',
				'postDate'        => date_i18n( 'F j, Y', strtotime( '-7 days' ) ),
				'postDay'         => date_i18n( 'd', strtotime( '-7 days' ) ),
			)
		);

		wp_localize_script(
			'srk-meta-taxonomies-js',
			'srkTaxonomyData',
			array(
				'siteUrl'   => esc_url( get_site_url() ),
				'siteName'  => get_bloginfo( 'name' ),
				'siteDesc'  => get_bloginfo( 'description' ),
				'separator' => get_option( 'srk_title_separator', '-' ),
				'ajax_url'  => admin_url( 'admin-ajax.php' ),
				'nonce'     => wp_create_nonce( 'srk_taxonomy_nonce' ),
			)
		);
	}

	/**
	 * Save global settings to separate option
	 */
	public function srk_save_global_settings_to_meta() {
		if ( ! isset( $_POST['option_page'] ) || sanitize_key( wp_unslash( $_POST['option_page'] ) ) !== 'srk_meta_global_settings' ) {
			return;
		}

		if ( ! current_user_can( 'manage_options' ) ) {
			return;
		}

		check_admin_referer( 'srk_meta_global_settings-options' );

		$global_settings = array();

		if ( isset( $_POST['srk_title_separator'] ) ) {
			if ( 'custom' === sanitize_text_field( wp_unslash( $_POST['srk_title_separator'] ) ) && isset( $_POST['srk_custom_separator'] ) ) {
				$global_settings['title_separator'] = sanitize_text_field( substr( wp_unslash( $_POST['srk_custom_separator'] ), 0, 3 ) );
				$global_settings['custom_separator'] = '';
			} else {
				$global_settings['title_separator'] = sanitize_text_field( wp_unslash( $_POST['srk_title_separator'] ) );
				$global_settings['custom_separator'] = '';
			}
		}

		$fields = array(
			'srk_home_title'              => 'home_title',
			'srk_home_desc'               => 'home_desc',
			'srk_title_template'          => 'title_template',
			'srk_desc_template'           => 'desc_template',
			'srk_meta_description_length' => 'meta_description_length',
			'srk_website_name'            => 'website_name',
			'srk_alt_website_name'        => 'alt_website_name',
			'srk_noindex_search'          => 'noindex_search',
			'srk_noindex_attachment'      => 'noindex_attachment',
			'srk_noindex_pagination'      => 'noindex_pagination',
			'srk_global_robots_meta'      => 'global_robots_meta',
			'srk_enable_xml_sitemap'      => 'enable_xml_sitemap',
			'srk_enable_open_graph'       => 'enable_open_graph',
			'srk_enable_twitter_cards'    => 'enable_twitter_cards',
			'srk_enable_json_ld'          => 'enable_json_ld',
		);

		foreach ( $fields as $post_key => $meta_key ) {
			if ( isset( $_POST[ $post_key ] ) ) {
				$posted_value = wp_unslash( $_POST[ $post_key ] );
				if ( in_array( $meta_key, array( 'home_desc', 'desc_template' ), true ) ) {
					$global_settings[ $meta_key ] = sanitize_textarea_field( $posted_value );
				} elseif ( 'contact_email' === $meta_key ) {
					$global_settings[ $meta_key ] = sanitize_email( $posted_value );
				} elseif ( 'org_logo' === $meta_key ) {
					$global_settings[ $meta_key ] = esc_url_raw( $posted_value );
				} elseif ( in_array( $meta_key, array( 'meta_description_length' ), true ) ) {
					$global_settings[ $meta_key ] = absint( $posted_value );
					if ( 'meta_description_length' === $meta_key ) {
						if ( $global_settings[ $meta_key ] < 70 ) {
							$global_settings[ $meta_key ] = 70;
						}
						if ( $global_settings[ $meta_key ] > 320 ) {
							$global_settings[ $meta_key ] = 320;
						}
					}
				} elseif ( in_array(
					$meta_key,
					array(
						'noindex_search',
						'noindex_attachment',
						'noindex_pagination',
						'global_robots_meta',
						'use_meta_keywords',
						'run_shortcodes',
						'enable_xml_sitemap',
						'enable_open_graph',
						'enable_twitter_cards',
						'enable_json_ld',
					),
					true
				) ) {
					$global_settings[ $meta_key ] = ! empty( $_POST[ $post_key ] ) ? 1 : 0;
				} else {
					$global_settings[ $meta_key ] = sanitize_text_field( $posted_value );
				}
			}
		}

		update_option( 'srk_meta_global_settings', $global_settings );

		$srk_meta = get_option( 'srk_meta', array() );
		if ( ! is_array( $srk_meta ) ) {
			$srk_meta = array();
		}

		if ( isset( $srk_meta['global'] ) && is_array( $srk_meta['global'] ) ) {
			$remove_schema_keys = array(
				'org_type',
				'org_name',
				'org_desc',
				'contact_email',
				'contact_phone',
				'country_code',
				'founding_date',
				'employee_range',
				'org_logo',
				'person_id',
			);

			foreach ( $remove_schema_keys as $key ) {
				if ( isset( $srk_meta['global'][ $key ] ) ) {
					unset( $srk_meta['global'][ $key ] );
				}
			}
		}

		$srk_meta['global'] = $global_settings;
		update_option( 'srk_meta', $srk_meta );

		if ( isset( $srk_meta['advanced'] ) && is_array( $srk_meta['advanced'] ) ) {
			$remove_keys = array(
				'use_meta_keywords',
				'run_shortcodes',
				'paged_format',
				'paged_separator',
				'paged_format_type',
			);

			foreach ( $remove_keys as $key ) {
				if ( isset( $srk_meta['advanced'][ $key ] ) ) {
					unset( $srk_meta['advanced'][ $key ] );
				}
			}
			update_option( 'srk_meta', $srk_meta );
		}
	}

	/**
	 * Migrate global settings from individual options to consolidated srk_meta option
	 */
	public function srk_migrate_global_settings_to_meta() {
		$migration_done = get_option( 'srk_meta_migration_done', false );
		if ( $migration_done ) {
			return;
		}

		$srk_meta = get_option( 'srk_meta', array() );

		if ( isset( $srk_meta['global'] ) && is_array( $srk_meta['global'] ) ) {
			$remove_schema_keys = array(
				'org_type',
				'org_name',
				'org_desc',
				'contact_email',
				'contact_phone',
				'country_code',
				'founding_date',
				'employee_range',
				'org_logo',
				'person_id',
			);

			foreach ( $remove_schema_keys as $key ) {
				if ( isset( $srk_meta['global'][ $key ] ) ) {
					unset( $srk_meta['global'][ $key ] );
				}
			}

			update_option( 'srk_meta', $srk_meta );
		}

		$global_settings = array();

		$option_map = array(
			'srk_title_separator'         => 'title_separator',
			'srk_home_title'              => 'home_title',
			'srk_home_desc'               => 'home_desc',
			'srk_title_template'          => 'title_template',
			'srk_desc_template'           => 'desc_template',
			'srk_meta_description_length' => 'meta_description_length',
			'srk_website_name'            => 'website_name',
			'srk_alt_website_name'        => 'alt_website_name',
		);

		foreach ( $option_map as $old_option => $meta_key ) {
			$value = get_option( $old_option );
			if ( false !== $value ) {
				$global_settings[ $meta_key ] = $value;
				delete_option( $old_option );
			}
		}

		$robots_options = array(
			'srk_meta_default_index'   => 'meta_default_index',
			'srk_meta_default_noindex' => 'meta_default_noindex',
			'srk_meta_default_follow'  => 'meta_default_follow',
			'srk_meta_default_nofollow' => 'meta_default_nofollow',
			'srk_noindex_search'       => 'noindex_search',
			'srk_noindex_attachment'   => 'noindex_attachment',
			'srk_noindex_pagination'   => 'noindex_pagination',
		);

		foreach ( $robots_options as $old_option => $meta_key ) {
			$value = get_option( $old_option );
			if ( false !== $value ) {
				$global_settings[ $meta_key ] = $value;
				delete_option( $old_option );
			}
		}

		if ( ! empty( $global_settings ) ) {
			if ( ! is_array( $srk_meta ) ) {
				$srk_meta = array();
			}

			if ( isset( $srk_meta['global'] ) && is_array( $srk_meta['global'] ) ) {
				$srk_meta['global'] = array_merge( $srk_meta['global'], $global_settings );
			} else {
				$srk_meta['global'] = $global_settings;
			}

			update_option( 'srk_meta', $srk_meta );
		}

		$old_schema_options = array(
			'srk_org_type',
			'srk_org_name',
			'srk_org_desc',
			'srk_contact_email',
			'srk_contact_phone',
			'srk_country_code',
			'srk_founding_date',
			'srk_employee_range',
			'srk_org_logo',
			'srk_person_id',
		);

		foreach ( $old_schema_options as $option ) {
			delete_option( $option );
		}

		update_option( 'srk_meta_migration_done', true );
	}

	/**
	 * Clean up database on plugin update - remove all Knowledge Graph schema data
	 */
	public function srk_cleanup_knowledge_graph_data() {
		$current_version = get_option( 'srk_plugin_version', '2.1.3' );
		$new_version     = '2.1.5';

		if ( version_compare( $current_version, $new_version, '<' ) ) {
			$srk_meta = get_option( 'srk_meta', array() );
			if ( isset( $srk_meta['global'] ) && is_array( $srk_meta['global'] ) ) {
				$remove_schema_keys = array(
					'org_type',
					'org_name',
					'org_desc',
					'contact_email',
					'contact_phone',
					'country_code',
					'founding_date',
					'employee_range',
					'org_logo',
					'person_id',
				);

				foreach ( $remove_schema_keys as $key ) {
					if ( isset( $srk_meta['global'][ $key ] ) ) {
						unset( $srk_meta['global'][ $key ] );
					}
				}
				update_option( 'srk_meta', $srk_meta );
			}

			$global_settings = get_option( 'srk_meta_global_settings', array() );
			if ( ! empty( $global_settings ) ) {
				$remove_schema_keys = array(
					'org_type',
					'org_name',
					'org_desc',
					'contact_email',
					'contact_phone',
					'country_code',
					'founding_date',
					'employee_range',
					'org_logo',
					'person_id',
				);

				foreach ( $remove_schema_keys as $key ) {
					if ( isset( $global_settings[ $key ] ) ) {
						unset( $global_settings[ $key ] );
					}
				}
				update_option( 'srk_meta_global_settings', $global_settings );
			}

			$old_schema_options = array(
				'srk_org_type',
				'srk_org_name',
				'srk_org_desc',
				'srk_contact_email',
				'srk_contact_phone',
				'srk_country_code',
				'srk_founding_date',
				'srk_employee_range',
				'srk_org_logo',
				'srk_person_id',
			);

			foreach ( $old_schema_options as $option ) {
				delete_option( $option );
			}

			update_option( 'srk_plugin_version', $new_version );
		}
	}

	/**
	 * Initialize tabs
	 */
	private function srk_init_tabs() {
		$this->srk_tabs = array(
			'global'        => array(
				'title' => __( 'Global Meta', 'seo-repair-kit' ),
				'class' => 'SRK_Meta_Manager_Global',
				'file'  => 'class-seo-repair-kit-meta-manager-global.php',
			),
			'content-types' => array(
				'title' => __( 'Content Types', 'seo-repair-kit' ),
				'class' => 'SRK_Meta_Manager_Content_Types',
				'file'  => 'class-seo-repair-kit-meta-manager-content-types.php',
			),
			'taxonomies'    => array(
				'title' => __( 'Taxonomies', 'seo-repair-kit' ),
				'class' => 'SRK_Meta_Manager_Taxonomies',
				'file'  => 'class-seo-repair-kit-meta-manager-taxonomies.php',
			),
			'image-seo'     => array(
				'title' => __( 'Image SEO', 'seo-repair-kit' ),
				'class' => 'SRK_Meta_Manager_Image_SEO',
				'file'  => 'class-seo-repair-kit-meta-manager-image-seo.php',
			),
			'archives'      => array(
				'title' => __( 'Archives', 'seo-repair-kit' ),
				'class' => 'SRK_Meta_Manager_Archives',
				'file'  => 'class-seo-repair-kit-meta-manager-archives.php',
			),
			'advanced'      => array(
				'title' => __( 'Advance Settings', 'seo-repair-kit' ),
				'class' => 'SRK_Meta_Manager_Advanced',
				'file'  => 'class-seo-repair-kit-meta-manager-advanced.php',
			),
		);
	}

	/**
	 * Register submenu page
	 */
	public function srk_register_meta_manager_page() {
		add_submenu_page(
			'seo-repair-kit',
			__( 'Meta Manager', 'seo-repair-kit' ),
			__( 'Meta Manager', 'seo-repair-kit' ),
			'manage_options',
			'seo-repair-kit-meta-manager',
			array( $this, 'srk_render_meta_manager_page' )
		);
	}

	/**
	 * Register settings
	 */
	public function srk_register_settings() {
		register_setting(
			'srk_meta_global_settings',
			'srk_title_separator',
			array(
				'type'              => 'string',
				'sanitize_callback' => array( $this, 'srk_sanitize_title_separator' ),
				'default'           => '-',
			)
		);

		register_setting(
			'srk_meta_global_settings',
			'srk_custom_separator',
			array(
				'type'              => 'string',
				'sanitize_callback' => 'sanitize_text_field',
				'default'           => '',
			)
		);

		register_setting( 'srk_meta_global_settings', 'srk_home_title' );
		register_setting( 'srk_meta_global_settings', 'srk_home_desc' );
		register_setting( 'srk_meta_global_settings', 'srk_title_template' );
		register_setting( 'srk_meta_global_settings', 'srk_desc_template' );
		register_setting( 'srk_meta_global_settings', 'srk_meta_description_length' );
		register_setting( 'srk_meta_global_settings', 'srk_meta_default_index' );
		register_setting( 'srk_meta_global_settings', 'srk_meta_default_follow' );
		register_setting(
			'srk_meta_global_settings',
			'srk_meta_global_settings',
			array(
				'type'              => 'array',
				'sanitize_callback' => array( $this, 'srk_sanitize_global_settings' ),
				'default'           => array(),
			)
		);

		register_setting( 'srk_meta_global_settings', 'srk_website_name' );
		register_setting( 'srk_meta_global_settings', 'srk_alt_website_name' );
		register_setting( 'srk_meta_global_settings', 'srk_org_type' );
		register_setting( 'srk_meta_global_settings', 'srk_org_name' );
		register_setting( 'srk_meta_global_settings', 'srk_org_desc' );
		register_setting( 'srk_meta_global_settings', 'srk_contact_email' );
		register_setting( 'srk_meta_global_settings', 'srk_contact_phone' );
		register_setting( 'srk_meta_global_settings', 'srk_country_code' );
		register_setting( 'srk_meta_global_settings', 'srk_founding_date' );
		register_setting( 'srk_meta_global_settings', 'srk_employee_range' );
		register_setting( 'srk_meta_global_settings', 'srk_org_logo' );
		register_setting( 'srk_meta_global_settings', 'srk_noindex_search' );
		register_setting( 'srk_meta_global_settings', 'srk_noindex_attachment' );
		register_setting( 'srk_meta_global_settings', 'srk_noindex_pagination' );

		register_setting(
			'srk_meta_archives_settings',
			'srk_meta_archives_settings',
			array(
				'type'              => 'array',
				'sanitize_callback' => array( $this, 'srk_sanitize_archives_settings' ),
				'default'           => array(),
			)
		);

		register_setting(
			'srk_meta_content_types_group',
			'srk_meta_content_types_settings'
		);

		register_setting(
			'srk_meta_taxonomies_group',
			'srk_meta_taxonomies_settings',
			array(
				'type'              => 'array',
				'sanitize_callback' => 'srk_sanitize_taxonomy_settings_callback',
				'default'           => array(),
			)
		);

		register_setting( 'srk_meta_archives_settings', 'srk_enable_author_archive' );
		register_setting( 'srk_meta_archives_settings', 'srk_author_archive_title' );
		register_setting( 'srk_meta_archives_settings', 'srk_enable_date_archive' );
		register_setting( 'srk_meta_archives_settings', 'srk_date_archive_title' );
		register_setting( 'srk_meta_archives_settings', 'srk_search_title' );

		register_setting( 'srk_meta_advanced_settings', 'srk_global_robots_meta' );
	}

	/**
	 * Migrate content types settings to remove srk prefixes
	 */
	public function srk_migrate_content_types_tags() {
		$migration_done = get_option( 'srk_content_tags_migration_done', false );
		if ( $migration_done ) {
			return;
		}

		$settings = get_option( 'srk_meta_content_types_settings', array() );
		if ( empty( $settings ) ) {
			update_option( 'srk_content_tags_migration_done', true );
			return;
		}

		$updated = false;
		$search  = array(
			'%site_title%',
			'%sitedesc%',
			'%title%',
			'%title%',
			'%excerpt%',
			'%author_first_name%',
			'%author_last_name%',
			'%author_name%',
			'%categories%',
			'%term_title%',
			'%date%',
			'%month%',
			'%year%',
			'%custom_field%',
			'%permalink%',
			'%content%',
			'%post_date%',
			'%post_day%',
		);

		$replace = array(
			'%site_title%',
			'%sitedesc%',
			'%title%',
			'%title%',
			'%excerpt%',
			'%author_first_name%',
			'%author_last_name%',
			'%author_name%',
			'%categories%',
			'%term_title%',
			'%date%',
			'%month%',
			'%year%',
			'%custom_field%',
			'%permalink%',
			'%content%',
			'%post_date%',
			'%post_day%',
		);

		foreach ( $settings as $post_type => $data ) {
			if ( isset( $data['title'] ) ) {
				$new_title = str_replace( $search, $replace, $data['title'] );
				$new_title = preg_replace( '/%' . $post_type . '_(title|excerpt|date|day|content)%/', '%' . $post_type . '_$1%', $new_title );

				if ( $new_title !== $data['title'] ) {
					$settings[ $post_type ]['title'] = $new_title;
					$updated                         = true;
				}
			}

			if ( isset( $data['desc'] ) ) {
				$new_desc = str_replace( $search, $replace, $data['desc'] );
				$new_desc = preg_replace( '/%' . $post_type . '_(title|excerpt|date|day|content)%/', '%' . $post_type . '_$1%', $new_desc );

				if ( $new_desc !== $data['desc'] ) {
					$settings[ $post_type ]['desc'] = $new_desc;
					$updated                        = true;
				}
			}
		}

		if ( $updated ) {
			update_option( 'srk_meta_content_types_settings', $settings );

			$srk_meta = get_option( 'srk_meta', array() );
			if ( ! is_array( $srk_meta ) ) {
				$srk_meta = array();
			}
			$srk_meta['content_types'] = $settings;
			update_option( 'srk_meta', $srk_meta );
		}

		update_option( 'srk_content_tags_migration_done', true );
	}

	/**
	 * Render main page
	 */
	public function srk_render_meta_manager_page() {
		$srk_active_tab = isset( $_GET['tab'] )
			? sanitize_key( wp_unslash( $_GET['tab'] ) )
			: 'global';
		?>
		<div class="wrap srk-meta-manager">
			<h2 class="nav-tab-wrapper">
				<?php foreach ( $this->srk_tabs as $srk_key => $srk_tab ) : ?>
					<a href="?page=seo-repair-kit-meta-manager&tab=<?php echo esc_attr( $srk_key ); ?>"
					   class="nav-tab <?php echo $srk_active_tab === $srk_key ? 'nav-tab-active' : ''; ?>">
						<?php echo esc_html( $srk_tab['title'] ); ?>
					</a>
				<?php endforeach; ?>
			</h2>

			<div class="srk-tab-content">
				<?php $this->srk_load_tab( $srk_active_tab ); ?>
			</div>
		</div>
		<?php
	}

	/**
	 * Load tab class
	 *
	 * @param string $srk_tab
	 */
	private function srk_load_tab( $srk_tab ) {
		if ( ! isset( $this->srk_tabs[ $srk_tab ] ) ) {
			return;
		}

		$srk_tab_data  = $this->srk_tabs[ $srk_tab ];
		$srk_file_path = $this->srk_plugin_path . $srk_tab_data['file'];

		if ( file_exists( $srk_file_path ) ) {
			require_once $srk_file_path;

			if ( class_exists( $srk_tab_data['class'] ) ) {
				$srk_tab_instance = new $srk_tab_data['class']();
				$srk_tab_instance->render();
			}
		} else {
			echo '<div class="notice notice-error"><p><strong>' .
				esc_html__( 'Tab file not found:', 'seo-repair-kit' ) .
				'</strong> ' . esc_html( $srk_file_path ) . '</p></div>';
		}
	}

	/**
	 * Sanitize title separator - handles custom separator
	 *
	 * @param string $value The separator value
	 * @return string Sanitized separator value
	 */
	public function srk_sanitize_title_separator( $value ) {
		if ( 'custom' === $value && isset( $_POST['srk_custom_separator'] ) ) {
			$custom_value = sanitize_text_field( wp_unslash( $_POST['srk_custom_separator'] ) );
			$custom_value = substr( $custom_value, 0, 3 );
			return ! empty( $custom_value ) ? $custom_value : '-';
		}

		return sanitize_text_field( $value );
	}

	/**
	 * Save archives settings
	 */
	public function srk_save_archives_settings() {
		if ( ! isset( $_POST['srk_archives_submitted'] ) || '1' !== sanitize_text_field( wp_unslash( $_POST['srk_archives_submitted'] ) ) ) {
			return;
		}

		if ( ! isset( $_POST['srk_archives_nonce'] ) || ! wp_verify_nonce( sanitize_text_field( wp_unslash( $_POST['srk_archives_nonce'] ) ), 'srk_save_archives_settings' ) ) {
			wp_die( esc_html__( 'Security check failed.', 'seo-repair-kit' ) );
		}

		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'You do not have sufficient permissions.', 'seo-repair-kit' ) );
		}

		$srk_meta = get_option( 'srk_meta', array() );
		if ( ! is_array( $srk_meta ) ) {
			$srk_meta = array();
		}

		$archives      = array();
		$archive_types = array( 'author', 'date', 'search' );

		foreach ( $archive_types as $archive_key ) {
			$show              = '1';
			$title             = '';
			$description       = '';
			$nofollow          = '0';
			$noindex           = '0';
			$noarchive         = '0';
			$nosnippet         = '0';
			$noimageindex      = '0';
			$max_snippet       = '-1';
			$max_image_preview = 'large';
			$max_video_preview = '-1';
			$use_default       = '0';

			if ( isset( $_POST['srk_archives'][ $archive_key ] ) ) {
				$data = wp_unslash( $_POST['srk_archives'][ $archive_key ] );

				$show              = isset( $data['show_in_search'] ) && '1' === $data['show_in_search'] ? '1' : '0';
				$title             = isset( $data['title'] ) ? sanitize_text_field( $data['title'] ) : '';
				$description       = isset( $data['description'] ) ? sanitize_textarea_field( $data['description'] ) : '';
				$nofollow          = isset( $data['nofollow'] ) && '1' === $data['nofollow'] ? '1' : '0';
				$noindex           = isset( $data['noindex'] ) && '1' === $data['noindex'] ? '1' : '0';
				$noarchive         = isset( $data['noarchive'] ) && '1' === $data['noarchive'] ? '1' : '0';
				$nosnippet         = isset( $data['nosnippet'] ) && '1' === $data['nosnippet'] ? '1' : '0';
				$noimageindex      = isset( $data['noimageindex'] ) && '1' === $data['noimageindex'] ? '1' : '0';
				$max_snippet       = isset( $data['max_snippet'] ) ? sanitize_text_field( $data['max_snippet'] ) : '-1';
				$max_image_preview = isset( $data['max_image_preview'] ) ? sanitize_text_field( $data['max_image_preview'] ) : 'large';
				$max_video_preview = isset( $data['max_video_preview'] ) ? sanitize_text_field( $data['max_video_preview'] ) : '-1';
				$use_default       = isset( $data['use_default'] ) && '1' === $data['use_default'] ? '1' : '0';
			}

			$title       = preg_replace( '/(%[a-z_]+%)(?=\1)/i', '$1', $title );
			$description = preg_replace( '/(%[a-z_]+%)(?=\1)/i', '$1', $description );

			if ( '1' === $use_default ) {
				$noindex = '1' === $show ? '0' : '1';
			}

			$archives[ $archive_key ] = array(
				'enable'            => $show,
				'noindex'           => $noindex,
				'title'             => $title,
				'description'       => $description,
				'nofollow'          => $nofollow,
				'noarchive'         => $noarchive,
				'nosnippet'         => $nosnippet,
				'noimageindex'      => $noimageindex,
				'max_snippet'       => $max_snippet,
				'max_image_preview' => $max_image_preview,
				'max_video_preview' => $max_video_preview,
				'use_default'       => $use_default,
			);
		}

		$srk_meta['archives'] = $archives;
		update_option( 'srk_meta', $srk_meta );

		set_transient( 'srk_archives_saved', '1', 30 );

		wp_safe_redirect(
			add_query_arg(
				array(
					'page'             => 'seo-repair-kit-meta-manager',
					'tab'              => 'archives',
					'settings-updated' => 'true',
				),
				admin_url( 'admin.php' )
			)
		);
		exit;
	}

	/**
	 * Sanitize archives settings (WordPress callback)
	 */
	public function srk_sanitize_archives_settings( $input ) {
		if ( empty( $_POST ) ) {
			return $input;
		}

		if ( ! check_admin_referer( 'srk_meta_archives_settings-options', '_wpnonce', false ) ) {
			return $input;
		}

		$srk_meta = get_option( 'srk_meta', array() );
		if ( ! is_array( $srk_meta ) ) {
			$srk_meta = array();
		}

		$archives = array();

		$archive_types = array( 'author', 'date', 'search' );

		foreach ( $archive_types as $archive_key ) {
			if ( isset( $_POST[ "srk_archive_{$archive_key}_show_in_search" ] ) ) {
				$show = '1' === sanitize_text_field( wp_unslash( $_POST[ "srk_archive_{$archive_key}_show_in_search" ] ) ) ? '1' : '0';
			} else {
				$show = '1';
			}

			$noindex = '1' === $show ? '0' : '1';

			$nofollow = isset( $_POST[ "srk_archive_{$archive_key}_nofollow" ] ) ? '1' : '0';

			$archives[ $archive_key ] = array(
				'enable'      => $show,
				'noindex'     => $noindex,
				'title'       => isset( $_POST[ "srk_archive_{$archive_key}_title" ] ) ? sanitize_text_field( wp_unslash( $_POST[ "srk_archive_{$archive_key}_title" ] ) ) : '',
				'description' => isset( $_POST[ "srk_archive_{$archive_key}_description" ] ) ? sanitize_textarea_field( wp_unslash( $_POST[ "srk_archive_{$archive_key}_description" ] ) ) : '',
				'nofollow'    => $nofollow,
			);
		}

		$post_types = get_post_types( array( 'public' => true, '_builtin' => false, 'has_archive' => true ), 'names' );

		foreach ( $post_types as $pt ) {
			if ( isset( $_POST[ "srk_archive_pt_{$pt}_show_in_search" ] ) ) {
				$show = '1' === sanitize_text_field( wp_unslash( $_POST[ "srk_archive_pt_{$pt}_show_in_search" ] ) ) ? '1' : '0';
			} else {
				$show = '1';
			}

			$noindex = '1' === $show ? '0' : '1';

			$nofollow = isset( $_POST[ "srk_archive_pt_{$pt}_nofollow" ] ) ? sanitize_text_field( wp_unslash( $_POST[ "srk_archive_pt_{$pt}_nofollow" ] ) ) : '0';

			$archives['post_types'][ $pt ] = array(
				'enable'      => $show,
				'noindex'     => $noindex,
				'title'       => isset( $_POST[ "srk_archive_pt_{$pt}_title" ] ) ? sanitize_text_field( wp_unslash( $_POST[ "srk_archive_pt_{$pt}_title" ] ) ) : '',
				'description' => isset( $_POST[ "srk_archive_pt_{$pt}_description" ] ) ? sanitize_textarea_field( wp_unslash( $_POST[ "srk_archive_pt_{$pt}_description" ] ) ) : '',
				'nofollow'    => $nofollow,
			);
		}

		$srk_meta['archives'] = $archives;

		update_option( 'srk_meta', $srk_meta );

		return $archives;
	}

	/**
	 * Sanitize global settings callback
	 *
	 * @param array $input Raw input data
	 * @return array Sanitized data
	 */
	public function srk_sanitize_global_settings( $input ) {
		$output = array();

		if ( ! is_array( $input ) ) {
			return $output;
		}

		if ( isset( $input['title_separator'] ) ) {
			$output['title_separator'] = sanitize_text_field( $input['title_separator'] );
		}

		if ( isset( $input['home_title'] ) ) {
			$output['home_title'] = sanitize_text_field( $input['home_title'] );
		}
		if ( isset( $input['home_desc'] ) ) {
			$output['home_desc'] = sanitize_textarea_field( $input['home_desc'] );
		}

		if ( isset( $input['title_template'] ) ) {
			$output['title_template'] = sanitize_text_field( $input['title_template'] );
		}
		if ( isset( $input['desc_template'] ) ) {
			$output['desc_template'] = sanitize_textarea_field( $input['desc_template'] );
		}

		if ( isset( $input['meta_description_length'] ) ) {
			$output['meta_description_length'] = absint( $input['meta_description_length'] );
			if ( $output['meta_description_length'] < 70 ) {
				$output['meta_description_length'] = 70;
			} elseif ( $output['meta_description_length'] > 320 ) {
				$output['meta_description_length'] = 320;
			}
		}

		if ( isset( $input['website_name'] ) ) {
			$output['website_name'] = sanitize_text_field( $input['website_name'] );
		}
		if ( isset( $input['alt_website_name'] ) ) {
			$output['alt_website_name'] = sanitize_text_field( $input['alt_website_name'] );
		}
		if ( isset( $input['org_type'] ) ) {
			$allowed_types          = array( 'person', 'organization' );
			$output['org_type']     = in_array( $input['org_type'], $allowed_types, true ) ? $input['org_type'] : 'organization';
		}
		if ( isset( $input['org_name'] ) ) {
			$output['org_name'] = sanitize_text_field( $input['org_name'] );
		}
		if ( isset( $input['org_desc'] ) ) {
			$output['org_desc'] = sanitize_textarea_field( $input['org_desc'] );
		}
		if ( isset( $input['contact_email'] ) ) {
			$output['contact_email'] = sanitize_email( $input['contact_email'] );
		}
		if ( isset( $input['contact_phone'] ) ) {
			$output['contact_phone'] = sanitize_text_field( $input['contact_phone'] );
		}
		if ( isset( $input['country_code'] ) ) {
			$output['country_code'] = sanitize_text_field( $input['country_code'] );
		}
		if ( isset( $input['founding_date'] ) ) {
			$output['founding_date'] = sanitize_text_field( $input['founding_date'] );
		}
		if ( isset( $input['employee_range'] ) ) {
			$allowed_ranges         = array( '1-10', '11-50', '51-200', '201-500', '501-1000', '1000+', '' );
			$output['employee_range'] = in_array( $input['employee_range'], $allowed_ranges, true ) ? $input['employee_range'] : '';
		}
		if ( isset( $input['org_logo'] ) ) {
			$output['org_logo'] = esc_url_raw( $input['org_logo'] );
		}

		if ( isset( $input['meta_default_index'] ) ) {
			$output['meta_default_index'] = ! empty( $input['meta_default_index'] ) ? 1 : 0;
		}
		if ( isset( $input['meta_default_follow'] ) ) {
			$output['meta_default_follow'] = ! empty( $input['meta_default_follow'] ) ? 1 : 0;
		}

		if ( isset( $input['noindex_search'] ) ) {
			$output['noindex_search'] = ! empty( $input['noindex_search'] ) ? 1 : 0;
		}
		if ( isset( $input['noindex_attachment'] ) ) {
			$output['noindex_attachment'] = ! empty( $input['noindex_attachment'] ) ? 1 : 0;
		}
		if ( isset( $input['noindex_pagination'] ) ) {
			$output['noindex_pagination'] = ! empty( $input['noindex_pagination'] ) ? 1 : 0;
		}

		$srk_meta = get_option( 'srk_meta', array() );
		if ( ! is_array( $srk_meta ) ) {
			$srk_meta = array();
		}
		$srk_meta['global'] = $output;
		update_option( 'srk_meta', $srk_meta );

		if ( isset( $output['title_separator'] ) ) {
			update_option( 'srk_title_separator', $output['title_separator'] );
		}
		if ( isset( $output['home_title'] ) ) {
			update_option( 'srk_home_title', $output['home_title'] );
		}
		if ( isset( $output['home_desc'] ) ) {
			update_option( 'srk_home_desc', $output['home_desc'] );
		}
		if ( isset( $output['title_template'] ) ) {
			update_option( 'srk_title_template', $output['title_template'] );
		}
		if ( isset( $output['desc_template'] ) ) {
			update_option( 'srk_desc_template', $output['desc_template'] );
		}
		if ( isset( $output['meta_description_length'] ) ) {
			update_option( 'srk_meta_description_length', $output['meta_description_length'] );
		}
		if ( isset( $output['website_name'] ) ) {
			update_option( 'srk_website_name', $output['website_name'] );
		}
		if ( isset( $output['alt_website_name'] ) ) {
			update_option( 'srk_alt_website_name', $output['alt_website_name'] );
		}
		if ( isset( $output['org_type'] ) ) {
			update_option( 'srk_org_type', $output['org_type'] );
		}
		if ( isset( $output['org_name'] ) ) {
			update_option( 'srk_org_name', $output['org_name'] );
		}
		if ( isset( $output['org_desc'] ) ) {
			update_option( 'srk_org_desc', $output['org_desc'] );
		}
		if ( isset( $output['contact_email'] ) ) {
			update_option( 'srk_contact_email', $output['contact_email'] );
		}
		if ( isset( $output['contact_phone'] ) ) {
			update_option( 'srk_contact_phone', $output['contact_phone'] );
		}
		if ( isset( $output['country_code'] ) ) {
			update_option( 'srk_country_code', $output['country_code'] );
		}
		if ( isset( $output['founding_date'] ) ) {
			update_option( 'srk_founding_date', $output['founding_date'] );
		}
		if ( isset( $output['employee_range'] ) ) {
			update_option( 'srk_employee_range', $output['employee_range'] );
		}
		if ( isset( $output['org_logo'] ) ) {
			update_option( 'srk_org_logo', $output['org_logo'] );
		}

		return $output;
	}
}

/**
 * Sanitize taxonomy settings callback
 *
 * @param array $input Raw input data
 * @return array Sanitized data
 */
function srk_sanitize_taxonomy_settings_callback( $input ) {
	$output = array();

	if ( ! is_array( $input ) ) {
		return $output;
	}

	foreach ( $input as $taxonomy => $settings ) {
		$output[ $taxonomy ]['search_visibility'] =
			! empty( $settings['search_visibility'] ) ? 1 : 0;

		$output[ $taxonomy ]['use_default_advanced'] =
			isset( $settings['use_default_advanced'] ) && '1' === $settings['use_default_advanced']
				? '1'
				: '0';

		$output[ $taxonomy ]['title_template'] =
			sanitize_text_field( $settings['title_template'] ?? '' );

		$output[ $taxonomy ]['description_template'] =
			sanitize_textarea_field( $settings['description_template'] ?? '' );

		if ( isset( $settings['robots'] ) && is_array( $settings['robots'] ) ) {
			$r = $settings['robots'];

			$output[ $taxonomy ]['robots'] = array(
				'noindex'           => ! empty( $r['noindex'] ) ? 1 : 0,
				'nofollow'          => ! empty( $r['nofollow'] ) ? 1 : 0,
				'noarchive'         => ! empty( $r['noarchive'] ) ? 1 : 0,
				'noimageindex'      => ! empty( $r['noimageindex'] ) ? 1 : 0,
				'nosnippet'         => ! empty( $r['nosnippet'] ) ? 1 : 0,
				'max_snippet'       => isset( $r['max_snippet'] ) ? (int) $r['max_snippet'] : -1,
				'max_video_preview' => isset( $r['max_video_preview'] ) ? (int) $r['max_video_preview'] : -1,
				'max_image_preview' => sanitize_text_field( $r['max_image_preview'] ?? 'large' ),
			);
		}
	}

	return $output;
}

/**
 * Enhanced template parsing function
 *
 * @param string $template Template string
 * @param int    $post_id  Post ID
 * @return string Parsed template
 */
function srk_parse_template( $template, $post_id = null ) {
	$srk_meta        = get_option( 'srk_meta', array() );
	$global_settings = isset( $srk_meta['global'] ) && is_array( $srk_meta['global'] ) ? $srk_meta['global'] : array();
	$separator       = isset( $global_settings['title_separator'] ) ? $global_settings['title_separator'] : get_option( 'srk_title_separator', '-' );

	$post      = get_post( $post_id );
	$author_id = $post ? $post->post_author : 0;

	$replacements = array(
		'%site_title%'        => get_bloginfo( 'name' ),
		'%sitedesc%'          => get_bloginfo( 'description' ),
		'%title%'             => get_the_title( $post_id ),
		'%excerpt%'           => wp_strip_all_tags(
			get_the_excerpt( $post_id ) ?: wp_trim_words( get_post_field( 'post_content', $post_id ), 30 )
		),
		'%sep%'               => $separator,
		'%author_first_name%' => $author_id ? get_the_author_meta( 'first_name', $author_id ) : '',
		'%author_last_name%'  => $author_id ? get_the_author_meta( 'last_name', $author_id ) : '',
		'%author_name%'       => $author_id ? get_the_author_meta( 'display_name', $author_id ) : '',
		'%categories%'        => $post_id ? implode( ', ', wp_get_post_categories( $post_id, array( 'fields' => 'names' ) ) ) : '',
		'%term_title%'        => $post_id ? ( ( $cats = wp_get_post_categories( $post_id, array( 'fields' => 'names' ) ) ) ? $cats[0] : '' ) : '',
		'%date%'              => date_i18n( 'F j, Y' ),
		'%month%'             => date_i18n( 'F' ),
		'%year%'              => date_i18n( 'Y' ),
		'%custom_field%'      => '',
		'%permalink%'         => $post_id ? get_permalink( $post_id ) : '',
		'%content%'           => $post_id ? wp_trim_words( wp_strip_all_tags( get_post_field( 'post_content', $post_id ) ), 30 ) : '',
		'%post_date%'         => $post_id ? get_the_date( 'F j, Y', $post_id ) : '',
		'%post_day%'          => $post_id ? get_the_date( 'd', $post_id ) : '',
	);

	$result = str_replace( array_keys( $replacements ), array_values( $replacements ), $template );

	$result = str_replace( '|', $separator, $result );

	return $result;
}

add_action(
	'admin_init',
	function () {
		if ( get_option( 'srk_settings_migrated' ) ) {
			return;
		}

		$old = get_option( 'srk_meta', array() );

		if ( ! empty( $old['global'] ) ) {
			update_option( 'srk_global_settings', $old['global'], false );
		}

		if ( ! empty( $old['archives'] ) ) {
			update_option( 'srk_archives_settings', $old['archives'], false );
		}

		update_option( 'srk_settings_migrated', 1 );
	}
);

/**
 * Boot Meta Manager
 */
add_action(
	'plugins_loaded',
	function () {
		new SRK_Meta_Manager_Main();
	}
);