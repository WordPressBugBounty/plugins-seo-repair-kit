<?php
/**
 * Elementor Integration for SEO Repair Kit Meta Manager
 * 
 * @package SEO_Repair_Kit
 * @since 2.1.3 - Added caching system, removed debug logs, optimized database queries
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

use Elementor\Controls_Manager;

// Make sure resolver is loaded
if ( ! class_exists( 'SRK_Meta_Resolver' ) ) {
    require_once dirname( __FILE__ ) . '/class-seo-repair-kit-meta-resolver.php';
}

class SRK_Elementor_Integration {

    private $post_id;
    private $post_type;

    public function __construct() {
        error_log( 'SRK Elementor Integration Loaded (SYNCED VERSION)' );

        // Add meta panel to Elementor document settings
        add_action( 'elementor/documents/register_controls', [ $this, 'add_seo_meta_panel' ], 20 );
        
        // Save meta data when Elementor saves
        add_action( 'elementor/document/after_save', [ $this, 'save_elementor_meta' ], 10, 2 );
        
        // Enqueue editor scripts
        add_action( 'elementor/editor/after_enqueue_scripts', [ $this, 'enqueue_editor_scripts' ] );
        
        // Sync check hook
        add_action( 'elementor/editor/after_save', [ $this, 'update_sync_timestamp' ], 10, 2 );
        
        // Real-time sync listener
        add_action( 'updated_post_meta', [ $this, 'handle_meta_update' ], 10, 4 );
        // AJAX handler for reset functionality
        add_action( 'wp_ajax_srk_reset_elementor_meta', [ $this, 'ajax_reset_elementor_meta' ] );
    }

    /**
     * Save Elementor meta data to database
     */
    public function save_elementor_meta( $document, $data ) {
        $post_id = $document->get_main_id();
        $settings = $data['settings'] ?? [];
        
        error_log( "SRK Elementor: Saving meta for post {$post_id}" );
        
        // Save basic meta fields - THESE ARE THE CORRECT META KEYS
        if ( isset( $settings['srk_meta_title'] ) ) {
            $title = sanitize_text_field( $settings['srk_meta_title'] );
            update_post_meta( $post_id, '_srk_meta_title', $title );
            error_log( "SRK Elementor: Saved title: {$title}" );
        }
        
        if ( isset( $settings['srk_meta_description'] ) ) {
            $desc = sanitize_textarea_field( $settings['srk_meta_description'] );
            update_post_meta( $post_id, '_srk_meta_description', $desc );
        }
        
        if ( isset( $settings['srk_focus_keyword'] ) ) {
            update_post_meta( $post_id, '_srk_focus_keyword', sanitize_text_field( $settings['srk_focus_keyword'] ) );
        }
        
        if ( isset( $settings['srk_meta_keywords'] ) ) {
            update_post_meta( $post_id, '_srk_meta_keywords', sanitize_text_field( $settings['srk_meta_keywords'] ) );
        }
        
        if ( isset( $settings['srk_canonical_url']['url'] ) ) {
            update_post_meta( $post_id, '_srk_canonical_url', esc_url_raw( $settings['srk_canonical_url']['url'] ) );
        }
        
        // Get use default settings value
        $use_default = isset( $settings['srk_use_default_settings'] ) 
            ? ( $settings['srk_use_default_settings'] === 'yes' ? '1' : '0' )
            : '1';
        
        // Build advanced settings array
        $advanced = [
            'use_default_settings' => $use_default,
            'robots_meta' => [
                'noindex' => isset( $settings['srk_robots_noindex'] ) && $settings['srk_robots_noindex'] === '1' ? '1' : '0',
                'nofollow' => isset( $settings['srk_robots_nofollow'] ) && $settings['srk_robots_nofollow'] === '1' ? '1' : '0',
                'noarchive' => isset( $settings['srk_robots_noarchive'] ) && $settings['srk_robots_noarchive'] === '1' ? '1' : '0',
                'notranslate' => isset( $settings['srk_robots_notranslate'] ) && $settings['srk_robots_notranslate'] === '1' ? '1' : '0',
                'noimageindex' => isset( $settings['srk_robots_noimageindex'] ) && $settings['srk_robots_noimageindex'] === '1' ? '1' : '0',
                'nosnippet' => isset( $settings['srk_robots_nosnippet'] ) && $settings['srk_robots_nosnippet'] === '1' ? '1' : '0',
                'noodp' => isset( $settings['srk_robots_noodp'] ) && $settings['srk_robots_noodp'] === '1' ? '1' : '0',
                'max_snippet' => intval( $settings['srk_max_snippet'] ?? -1 ),
                'max_video_preview' => intval( $settings['srk_max_video_preview'] ?? -1 ),
                'max_image_preview' => $settings['srk_max_image_preview'] ?? 'large',
            ]
        ];

        // Save to database
        update_post_meta( $post_id, '_srk_advanced_settings', $advanced );
        update_post_meta( $post_id, '_srk_use_default_robots', $use_default );
        
        // Update sync timestamp
        update_post_meta( $post_id, '_srk_last_sync', current_time('timestamp') );
        
        error_log( "SRK Elementor: Saved all meta for post {$post_id}" );
    }

    public function add_seo_meta_panel( $document ) {
        // Only add to posts/pages/custom post types
        if ( ! $document instanceof \Elementor\Core\DocumentTypes\PageBase && 
             ! $document instanceof \Elementor\Modules\Library\Documents\Page ) {
            return;
        }

        $this->post_id = $document->get_main_id();
        $this->post_type = get_post_type( $this->post_id );

        // Get saved meta values
        $meta_title = get_post_meta( $this->post_id, '_srk_meta_title', true );
        $meta_description = get_post_meta( $this->post_id, '_srk_meta_description', true );
        $canonical_url = get_post_meta( $this->post_id, '_srk_canonical_url', true );
        $meta_keywords = get_post_meta( $this->post_id, '_srk_meta_keywords', true );
        $focus_keyword = get_post_meta( $this->post_id, '_srk_focus_keyword', true );
        $last_sync = get_post_meta( $this->post_id, '_srk_last_sync', true );

        error_log( "SRK Elementor: Loading meta for post {$this->post_id} - Title: {$meta_title}" );

        // Get content type settings
        $content_type_settings = $this->get_content_type_settings();
        
        // Get advanced settings
        $advanced_settings = get_post_meta( $this->post_id, '_srk_advanced_settings', true );
        if ( empty( $advanced_settings ) ) {
            $advanced_settings = [
                'use_default_settings' => '1',
                'robots_meta' => [
                    'noindex' => '0',
                    'nofollow' => '0',
                    'noarchive' => '0',
                    'notranslate' => '0',
                    'noimageindex' => '0',
                    'nosnippet' => '0',
                    'noodp' => '0',
                    'max_snippet' => -1,
                    'max_video_preview' => -1,
                    'max_image_preview' => 'large'
                ]
            ];
        }

        // Default templates from content type settings
        $default_title = isset( $content_type_settings['title'] ) && ! empty( $content_type_settings['title'] ) 
            ? $content_type_settings['title'] 
            : '%title% %sep% %site_title%';
            
        $default_desc = isset( $content_type_settings['desc'] ) && ! empty( $content_type_settings['desc'] )
            ? $content_type_settings['desc']
            : '%excerpt%';

        // Parse the default templates to show actual values
        $display_title = ! empty( $meta_title ) ? $meta_title : $default_title;
        $display_desc = ! empty( $meta_description ) ? $meta_description : $default_desc;

        // Main SEO Meta Manager section
        $document->start_controls_section(
            'srk_seo_meta_section',
            [
                'label' => __( 'SEO Repair Kit', 'seo-repair-kit' ),
                'tab'   => Controls_Manager::TAB_SETTINGS,
            ]
        );

        // ==================== TABS SECTION ====================
        $document->start_controls_tabs( 'srk_seo_tabs' );

        // ================= TAB 1 — GENERAL =================
        $document->start_controls_tab(
            'srk_general_tab',
            [
                'label' => __( 'General', 'seo-repair-kit' ),
            ]
        );
        
        // ==================== SERP PREVIEW SECTION ====================
        $document->add_control(
            'srk_preview_heading',
            [
                'label' => __( 'SERP Preview', 'seo-repair-kit' ),
                'type'  => Controls_Manager::HEADING,
                'separator' => 'none',
            ]
        );
        
        $preview_html = $this->generate_serp_preview( $display_title, $display_desc );
        $document->add_control(
            'srk_serp_preview',
            [
                'type'        => Controls_Manager::RAW_HTML,
                'raw'         => $preview_html,
                'separator'   => 'none',
            ]
        );
        
        // Edit Snippet Button
        $document->add_control(
            'srk_edit_snippet_btn',
            [
                'type' => Controls_Manager::RAW_HTML,
                'raw' => '<button type="button" class="srk-edit-snippet-btn">
                    <i class="eicon-edit"></i> Edit Snippet
                </button>',
            ]
        );
        
        $document->add_control(
            'srk_snippet_modal_html',
            [
                'type' => Controls_Manager::RAW_HTML,
                'raw'  => $this->generate_snippet_editor_modal(),
            ]
        );

        
        $document->end_controls_tab();

        // Tab 2: Advanced SEO
        $document->start_controls_tab(
            'srk_advanced_tab',
            [
                'label' => __( 'Advanced', 'seo-repair-kit' ),
            ]
        );

        // Canonical URL
        $document->add_control(
            'srk_canonical_url_heading',
            [
                'label' => __( 'Canonical URL', 'seo-repair-kit' ),
                'type'  => Controls_Manager::HEADING,
                'separator' => 'none',
            ]
        );

        $document->add_control(
            'srk_canonical_url',
            [
                'label'       => '',
                'type'        => Controls_Manager::URL,
                'default'     => [
                    'url' => $canonical_url,
                    'is_external' => false,
                    'nofollow' => false,
                ],
                'description' => __( 'Override default canonical URL', 'seo-repair-kit' ),
                'show_external' => false,
                'label_block' => true,
            ]
        );

        // Use Default Settings Toggle
        $document->add_control(
            'srk_use_default_settings',
            [
                'label'       => __( 'Use Default Settings', 'seo-repair-kit' ),
                'type'        => Controls_Manager::SWITCHER,
                'default'     => $advanced_settings['use_default_settings'] === '1' ? 'yes' : 'no',
                'label_on'    => __( 'Yes', 'seo-repair-kit' ),
                'label_off'   => __( 'No', 'seo-repair-kit' ),
                'return_value' => 'yes',
                'separator'   => 'before',
            ]
        );

        // Apply Content Type Settings Button
        $document->add_control(
            'srk_apply_content_type_btn',
            [
                'type' => Controls_Manager::BUTTON,
                'text' => __( 'Apply Content Type Settings', 'seo-repair-kit' ),
                'event' => 'srk:apply_content_type',
                'separator' => 'before',
                'condition' => [
                    'srk_use_default_settings' => 'no',
                ],
            ]
        );

        $document->add_control(
            'srk_custom_robots_heading',
            [
                'label' => __( 'Custom Robots Meta Settings', 'seo-repair-kit' ),
                'type'  => Controls_Manager::HEADING,
                'separator' => 'before',
                'condition' => [
                    'srk_use_default_settings!' => 'yes',
                ],
            ]
        );

        // No Index
        $document->add_control(
            'srk_robots_noindex',
            [
                'label' => 'No Index',
                'type' => Controls_Manager::SWITCHER,
                'return_value' => '1',
                'default' => $advanced_settings['robots_meta']['noindex'] ?? '0',
                'condition' => [
                    'srk_use_default_settings!' => 'yes',
                ],
            ]
        );

        // No Follow
        $document->add_control(
            'srk_robots_nofollow',
            [
                'label' => 'No Follow',
                'type' => Controls_Manager::SWITCHER,
                'return_value' => '1',
                'default' => $advanced_settings['robots_meta']['nofollow'] ?? '0',
                'condition' => [
                    'srk_use_default_settings!' => 'yes',
                ],
            ]
        );

        // No Archive
        $document->add_control(
            'srk_robots_noarchive',
            [
                'label' => 'No Archive',
                'type' => Controls_Manager::SWITCHER,
                'return_value' => '1',
                'default' => $advanced_settings['robots_meta']['noarchive'] ?? '0',
                'condition' => [
                    'srk_use_default_settings!' => 'yes',
                ],
            ]
        );

        // No Translate
        $document->add_control(
            'srk_robots_notranslate',
            [
                'label' => 'No Translate',
                'type' => Controls_Manager::SWITCHER,
                'return_value' => '1',
                'default' => $advanced_settings['robots_meta']['notranslate'] ?? '0',
                'condition' => [
                    'srk_use_default_settings!' => 'yes',
                ],
            ]
        );

        // No Image Index
        $document->add_control(
            'srk_robots_noimageindex',
            [
                'label' => 'No Image Index',
                'type' => Controls_Manager::SWITCHER,
                'return_value' => '1',
                'default' => $advanced_settings['robots_meta']['noimageindex'] ?? '0',
                'condition' => [
                    'srk_use_default_settings!' => 'yes',
                ],
            ]
        );

        // No Snippet
        $document->add_control(
            'srk_robots_nosnippet',
            [
                'label' => 'No Snippet',
                'type' => Controls_Manager::SWITCHER,
                'return_value' => '1',
                'default' => $advanced_settings['robots_meta']['nosnippet'] ?? '0',
                'condition' => [
                    'srk_use_default_settings!' => 'yes',
                ],
            ]
        );

        // No ODP
        $document->add_control(
            'srk_robots_noodp',
            [
                'label' => 'No ODP',
                'type' => Controls_Manager::SWITCHER,
                'return_value' => '1',
                'default' => $advanced_settings['robots_meta']['noodp'] ?? '0',
                'condition' => [
                    'srk_use_default_settings!' => 'yes',
                ],
            ]
        );

        // Max Snippet
        $document->add_control(
            'srk_max_snippet',
            [
                'label' => 'Max Snippet',
                'type' => Controls_Manager::NUMBER,
                'default' => $advanced_settings['robots_meta']['max_snippet'] ?? -1,
                'min' => -1,
                'max' => 600,
                'condition' => [
                    'srk_use_default_settings!' => 'yes',
                ],
            ]
        );

        // Max Video Preview
        $document->add_control(
            'srk_max_video_preview',
            [
                'label' => 'Max Video Preview',
                'type' => Controls_Manager::NUMBER,
                'default' => $advanced_settings['robots_meta']['max_video_preview'] ?? -1,
                'min' => -1,
                'max' => 600,
                'condition' => [
                    'srk_use_default_settings!' => 'yes',
                ],
            ]
        );

        // Max Image Preview
        $document->add_control(
            'srk_max_image_preview',
            [
                'label' => 'Max Image Preview',
                'type' => Controls_Manager::SELECT,
                'options' => [
                    'none' => 'None',
                    'standard' => 'Standard',
                    'large' => 'Large',
                ],
                'default' => $advanced_settings['robots_meta']['max_image_preview'] ?? 'large',
                'condition' => [
                    'srk_use_default_settings!' => 'yes',
                ],
            ]
        );

        // Robots Preview
        $document->add_control(
            'srk_robots_preview',
            [
                'type' => Controls_Manager::RAW_HTML,
                'raw' => '<div class="srk-robots-preview-box" style="margin-top: 15px; padding: 15px; background: #f8f9fa; border: 1px solid #e2e4e7; border-radius: 4px;">
                            <div style="font-size: 12px; font-weight: 600; color: #1d2327; margin-bottom: 5px;">Current Robots Meta:</div>
                            <div class="srk-robots-preview" style="font-family: monospace; font-size: 12px; color: #2271b1;"></div>
                         </div>',
                'condition' => [
                    'srk_use_default_settings!' => 'yes',
                ],
            ]
        );

        $document->end_controls_tab();
        $document->end_controls_tabs();

        // Hidden field for advanced settings JSON
        $document->add_control(
            'srk_advanced_settings_json',
            [
                'type' => Controls_Manager::HIDDEN,
                'default' => wp_json_encode( $advanced_settings ),
            ]
        );

        // Hidden fields for JS
        $document->add_control(
            'srk_hidden_data',
            [
                'type' => Controls_Manager::HIDDEN,
                'default' => wp_json_encode([
                    'post_id' => $this->post_id,
                    'post_type' => $this->post_type,
                    'default_title' => $default_title,
                    'default_desc' => $default_desc,
                    'site_url' => get_site_url(),
                    'site_name' => get_bloginfo('name'),
                    'separator' => get_option('srk_title_separator', '-'),
                    'last_sync' => $last_sync
                ]),
            ]
        );

        $document->end_controls_section();
    }

    /**
     * Get content type settings
     */
    private function get_content_type_settings() {
        $content_type_settings = get_option('srk_meta_content_types_settings', []);
        return isset($content_type_settings[$this->post_type]) ? $content_type_settings[$this->post_type] : [];
    }

    /**
     * Generate SERP preview HTML WITH ACTUAL VALUES using resolver
     */
    private function generate_serp_preview( $title, $description ) {
        $site_url = get_site_url();
        $site_display = preg_replace('/^https?:\/\/(www\.)?/', '', $site_url);
        
        // Use resolver to parse the templates
        $preview_title = SRK_Meta_Resolver::parse_template( $title, $this->post_id );
        $preview_desc = SRK_Meta_Resolver::parse_template( $description, $this->post_id );
        
        // Trim for display
        if ( strlen( $preview_title ) > 60 ) {
            $preview_title = substr( $preview_title, 0, 57 ) . '...';
        }
        
        if ( strlen( $preview_desc ) > 160 ) {
            $preview_desc = substr( $preview_desc, 0, 157 ) . '...';
        }
        
        $post_type_obj = get_post_type_object( $this->post_type );
        $post_type_label = $post_type_obj ? $post_type_obj->labels->singular_name : $this->post_type;
        
        return '
        <div class="srk-serp-preview-card" style="margin-bottom: 24px; padding: 16px; background: #fff; border: 1px solid #f0f0f0 !important; border-radius: 4px; box-shadow: 0 1px 2px rgba(0,0,0,0.1);">
            <div class="srk-serp-url">
                ' . esc_html( $site_display ) . ' › ' . esc_html( $post_type_label ) . ' › ...
            </div>
            <div class="srk-serp-title">' . esc_html( $preview_title ) . '</div>
            <div class="srk-serp-desc">' . esc_html( $preview_desc ) . '</div>
        </div>';
    }

    /**
     * Generate snippet editor modal HTML
     */
    private function generate_snippet_editor_modal() {
        // Get current post data
        $post_id = $this->post_id;
        $post_title = get_the_title( $post_id ) ?: 'Sample Post';
        $post_excerpt = get_the_excerpt( $post_id ) ?: wp_trim_words( wp_strip_all_tags( get_post_field( 'post_content', $post_id ) ), 20 );
        
        // Get separator
        $separator = get_option( 'srk_title_separator', '-' );
        
        // Current preview values
        $meta_title = get_post_meta( $post_id, '_srk_meta_title', true );
        $meta_description = get_post_meta( $post_id, '_srk_meta_description', true );
        
        // Get content type settings
        $content_type_settings = $this->get_content_type_settings();
        $default_title = isset( $content_type_settings['title'] ) ? $content_type_settings['title'] : '%title% %sep% %site_title%';
        $default_desc = isset( $content_type_settings['desc'] ) ? $content_type_settings['desc'] : '%excerpt%';
        
        // Initial preview values
        $initial_title = ! empty( $meta_title ) ? $meta_title : $default_title;
        $initial_desc = ! empty( $meta_description ) ? $meta_description : $default_desc;
        
        // Use resolver to parse the templates
        $parsed_title = SRK_Meta_Resolver::parse_template( $initial_title, $post_id );
        $parsed_desc = SRK_Meta_Resolver::parse_template( $initial_desc, $post_id );
        
        // Trim for display
        if ( strlen( $parsed_title ) > 60 ) {
            $parsed_title = substr( $parsed_title, 0, 57 ) . '...';
        }
        
        if ( strlen( $parsed_desc ) > 160 ) {
            $parsed_desc = substr( $parsed_desc, 0, 157 ) . '...';
        }
        
        $site_url = get_site_url();
        $site_display = preg_replace('/^https?:\/\/(www\.)?/', '', $site_url);
        
        // Get all template tags
        $template_tags = $this->get_template_tags();
        
        // Generate HTML for Title Tags Section
        $title_tags_html = '';
        $title_all_tags_html = '';
        foreach ( $template_tags as $name => $data ) {
            // Relevant tags for title
            if ( in_array( $name, ['Site Name', 'Post Title', 'Separator'] ) ) {
                $title_tags_html .= '
                <button type="button" class="srk-tag-btn srk-modal-tag" data-tag="' . esc_attr( $data['tag'] ) . '" data-section="title"
                        style="display: inline-flex; align-items: center; gap: 4px; padding: 4px 8px; background: #f0f6fc; border: 1px solid #c5d9ed; border-radius: 3px; font-size: 11px; color: #2271b1; cursor: pointer;">
                    <span style="font-weight: bold; font-size: 10px;">+</span>
                    ' . esc_html( $name ) . '
                </button>';
            }
            
            // All tags for dropdown
            $title_all_tags_html .= '
            <button type="button" class="srk-all-tag-btn" data-tag="' . esc_attr( $data['tag'] ) . '" data-section="title"
                    style="display: flex; align-items: flex-start; width: 100%; padding: 8px 12px; background: transparent; border: none; border-bottom: 1px solid #f0f0f0; cursor: pointer; text-align: left;">
                <span style="display: inline-flex; align-items: center; justify-content: center; width: 20px; height: 20px; border-radius: 50%; background: #2271b1; color: white; margin-right: 10px; font-size: 12px; font-weight: bold; flex-shrink: 0;">+</span>
                <div style="flex: 1;">
                    <div style="font-weight: 600; font-size: 13px; color: #1d2327; margin-bottom: 2px;">' . esc_html( $name ) . '</div>
                    <div style="font-size: 11px; color: #646970; line-height: 1.3; margin-bottom: 4px;">' . esc_html( $data['description'] ) . '</div>
                    <div style="font-size: 10px; color: #8c8f94; font-family: monospace; background: #f8f9fa; padding: 2px 6px; border-radius: 3px; display: inline-block;">' . esc_html( $data['current_value'] ) . '</div>
                </div>
            </button>';
        }

        // Generate HTML for Description Tags Section
        $desc_tags_html = '';
        $desc_all_tags_html = '';
        foreach ( $template_tags as $name => $data ) {
            // Relevant tags for description
            if ( in_array( $name, ['Post Excerpt', 'Site Description', 'Post Title', 'Post Content'] ) ) {
                $desc_tags_html .= '
                <button type="button" class="srk-tag-btn srk-modal-tag" data-tag="' . esc_attr( $data['tag'] ) . '" data-section="desc"
                        style="display: inline-flex; align-items: center; gap: 4px; padding: 4px 8px; background: #f0f6fc; border: 1px solid #c5d9ed; border-radius: 3px; font-size: 11px; color: #2271b1; cursor: pointer;">
                    <span style="font-weight: bold; font-size: 10px;">+</span>
                    ' . esc_html( $name ) . '
                </button>';
            }
            
            // All tags for dropdown
            $desc_all_tags_html .= '
            <button type="button" class="srk-all-tag-btn" data-tag="' . esc_attr( $data['tag'] ) . '" data-section="desc"
                    style="display: flex; align-items: flex-start; width: 100%; padding: 8px 12px; background: transparent; border: none; border-bottom: 1px solid #f0f0f0; cursor: pointer; text-align: left;">
                <span style="display: inline-flex; align-items: center; justify-content: center; width: 20px; height: 20px; border-radius: 50%; background: #2271b1; color: white; margin-right: 10px; font-size: 12px; font-weight: bold; flex-shrink: 0;">+</span>
                <div style="flex: 1;">
                    <div style="font-weight: 600; font-size: 13px; color: #1d2327; margin-bottom: 2px;">' . esc_html( $name ) . '</div>
                    <div style="font-size: 11px; color: #646970; line-height: 1.3; margin-bottom: 4px;">' . esc_html( $data['description'] ) . '</div>
                    <div style="font-size: 10px; color: #8c8f94; font-family: monospace; background: #f8f9fa; padding: 2px 6px; border-radius: 3px; display: inline-block;">' . esc_html( $data['current_value'] ) . '</div>
                </div>
            </button>';
        }
        
        return '
        <div id="srk-snippet-modal" style="display: none; position: fixed; top: 0; left: 0; width: 100%; height: 100%; background: rgba(0,0,0,0.5); z-index: 999999; align-items: center; justify-content: center;">
            <div class="srk-modal-box" style="background: white; width: 750px; max-width: 95%; max-height: 95vh; border-radius: 8px; overflow: hidden; display: flex; flex-direction: column; box-shadow: 0 8px 30px rgba(0,0,0,0.3);">
                <!-- Modal Header -->
                <div class="srk-modal-header" style="padding: 20px 24px; border-bottom: 1px solid #e2e4e7; background: #fff; display: flex; justify-content: space-between; align-items: center;">
                    <h2 style="margin: 0; font-size: 18px; font-weight: 600; color: #1d2327; display: flex; align-items: center; gap: 10px;">
                        <span style="color: #0B1D51;">📝</span>
                        <span>Preview Snippet Editor</span>
                    </h2>
                    <button type="button" class="srk-modal-close" style="background: none; border: none; font-size: 24px; cursor: pointer; color: #646970; line-height: 1; padding: 0; width: 30px; height: 30px; display: flex; align-items: center; justify-content: center; border-radius: 50%;">
                        &times;
                    </button>
                </div>
                
                <!-- Modal Body -->
                <div class="srk-modal-body" style="padding: 24px; overflow-y: auto; flex: 1; background: #f8f9fa;">
                    <!-- SERP Preview Section -->
                    <div class="srk-snippet-preview" style="margin-bottom: 30px; padding: 20px; background: #fff; border: 2px solid #4f94d4; border-radius: 8px; box-shadow: 0 2px 10px rgba(79, 148, 212, 0.1);">
                        <div class="srk-preview-url" style="color: #70757a; font-size: 12px; line-height: 1.4; margin-bottom: 10px; font-family: Arial, sans-serif;">
                            ' . esc_html( $site_display ) . ' › ...
                        </div>
                        <div class="srk-preview-title" style="color: #1a0dab; font-size: 18px; line-height: 1.3; margin-bottom: 8px; font-family: Arial, sans-serif; font-weight: 400;">
                            ' . esc_html( $parsed_title ) . '
                        </div>
                        <div class="srk-preview-desc" style="color: #3c4043; font-size: 14px; line-height: 1.5; font-family: Arial, sans-serif;">
                            ' . esc_html( $parsed_desc ) . '
                        </div>
                    </div>
                    
                    <!-- SEO Title Section -->
                    <div class="srk-title-section" style="margin-bottom: 30px; background: #fff; padding: 20px; border-radius: 8px; border: 1px solid #e2e4e7;">
                        <div style="margin-bottom: 12px; display: flex; justify-content: space-between; align-items: flex-start;">
                            <div>
                                <label style="font-size: 14px; font-weight: 600; color: #1d2327; display: block; margin-bottom: 8px;">
                                    SEO Title
                                </label>

                                <p style="margin: 0; font-size: 12px; color: #646970;">
                                    Title shown in search results
                                </p>

                                <p style="margin: 4px 0 0 0; font-size: 12px; color: #d63638; font-weight: 700;">
                                    For best performance, please reset all tags, refresh the page, then select the tags again and save your changes.
                                </p>
                            </div>

                            <button type="button" class="srk-reset-section-btn" data-section="title"
                                style="font-size: 12px; padding: 4px 10px; background: #f0f0f1; border: 1px solid #dcdcde; border-radius: 4px; cursor: pointer; color: #50575e;">
                                Reset
                            </button>
                        </div>

                        <div class="srk-tags-section" style="margin-bottom: 15px; padding: 12px; background: #f8f9fa; border-radius: 6px;">
                            <div style="display: flex; align-items: center; justify-content: space-between; margin-bottom: 8px;">
                                <span style="font-size: 12px; font-weight: 500; color: #646970;">
                                    Quick Tags:
                                </span>
                                <button type="button" class="srk-view-all-tags-btn" data-section="title"
                                        style="font-size: 11px; padding: 4px 10px; background: #f0f0f1; border: 1px solid #dcdcde; border-radius: 4px; cursor: pointer; color: #50575e; display: flex; align-items: center; gap: 4px;">
                                    <span>All Tags</span>
                                    <span style="font-size: 10px;">▼</span>
                                </button>
                            </div>
                            
                            <div class="srk-tags-selected" style="display: flex; flex-wrap: wrap; gap: 6px; margin-bottom: 8px;">
                                ' . $title_tags_html . '
                            </div>
                        </div>
                        
                        <!-- Title Input -->
                        <input type="text" id="srk-modal-title" class="srk-snippet-input" 
                               value="' . esc_attr( $initial_title ) . '"
                               style="width: 100%; padding: 12px 16px; border: 1px solid #dcdcde; border-radius: 6px; font-size: 14px; line-height: 1.5; background: #fff;"
                               placeholder="' . esc_attr( $post_title . ' ' . $separator . ' ' . get_bloginfo('name') ) . '">
                        
                        <!-- Character Counter -->
                        <div class="srk-title-counter" style="display: flex; justify-content: space-between; align-items: center; margin-top: 8px;">
                            <span style="font-size: 12px; color: #646970;">
                                Recommended: 50-60 characters
                            </span>
                            <span class="srk-char-count" style="font-size: 12px; font-weight: 600; color: #1d2327;">' . strlen( $initial_title ) . '/60</span>
                        </div>
                        
                        <!-- All Title Tags -->
                        <div id="srk-all-title-tags" class="srk-all-tags-container" 
                             style="display: none; margin-top: 15px; padding: 16px; background: #fff; border: 1px solid #e2e4e7; border-radius: 6px; box-shadow: 0 4px 12px rgba(0,0,0,0.08);">
                            <div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 12px;">
                                <div>
                                    <span style="font-size: 13px; font-weight: 600; color: #1d2327;">
                                        All Available Title Tags
                                    </span>
                                    <div style="font-size: 11px; color: #646970; margin-top: 2px;">
                                        Click to insert any tag
                                    </div>
                                </div>
                                <button type="button" class="srk-hide-tags-btn" data-section="title"
                                        style="font-size: 11px; padding: 4px 10px; background: #f0f0f1; border: 1px solid #dcdcde; border-radius: 4px; cursor: pointer; color: #50575e; display: flex; align-items: center; gap: 4px;">
                                    <span>Hide tags</span>
                                    <span style="font-size: 10px;">↑</span>
                                </button>
                            </div>
                            
                            <!-- Search Input -->
                            <div style="margin-bottom: 12px;">
                                <input type="text" class="srk-tags-search" data-section="title"
                                       placeholder="Search for an item..."
                                       style="width: 100%; padding: 8px 12px; border: 1px solid #dcdcde; border-radius: 4px; font-size: 13px; background: #f8f9fa;">
                            </div>
                            
                            <!-- Tags List -->
                            <div class="srk-tags-list" style="max-height: 250px; overflow-y: auto; padding-right: 5px;">
                                ' . $title_all_tags_html . '
                            </div>
                        </div>
                    </div>
                    
                    <!-- Meta Description Section -->
                    <div class="srk-desc-section" style="background: #fff; padding: 20px; border-radius: 8px; border: 1px solid #e2e4e7;">
                        <div style="margin-bottom: 12px; display: flex; justify-content: space-between; align-items: flex-start;">
                            <div>
                                <label style="font-size: 14px; font-weight: 600; color: #1d2327; display: block; margin-bottom: 8px;">
                                    Meta Description
                                </label>
                                <p style="margin: 0; font-size: 12px; color: #646970;">
                                    Description shown in search results
                                </p>
                                <p style="margin: 4px 0 0 0; font-size: 12px; color: #d63638; font-weight: 700;">
                                    For best performance, please reset all tags, refresh the page, then select the tags again and save your changes.
                                </p>
                            </div>
                            <button type="button" class="srk-reset-section-btn" data-section="desc"
                                    style="font-size: 12px; padding: 4px 10px; background: #f0f0f1; border: 1px solid #dcdcde; border-radius: 4px; cursor: pointer; color: #50575e;">
                                Reset
                            </button>
                        </div>
                        
                        <!-- Relevant Tags -->
                        <div class="srk-tags-section" style="margin-bottom: 15px; padding: 12px; background: #f8f9fa; border-radius: 6px;">
                            <div style="display: flex; align-items: center; justify-content: space-between; margin-bottom: 8px;">
                                <span style="font-size: 12px; font-weight: 500; color: #646970;">
                                    Quick Tags:
                                </span>
                                <button type="button" class="srk-view-all-tags-btn" data-section="desc"
                                        style="font-size: 11px; padding: 4px 10px; background: #f0f0f1; border: 1px solid #dcdcde; border-radius: 4px; cursor: pointer; color: #50575e; display: flex; align-items: center; gap: 4px;">
                                    <span>All Tags</span>
                                    <span style="font-size: 10px;">▼</span>
                                </button>
                            </div>
                            <div class="srk-relevant-tags" style="display: flex; flex-wrap: wrap; gap: 6px;">
                                ' . $desc_tags_html . '
                            </div>
                        </div>
                        
                        <!-- Description Textarea -->
                        <textarea id="srk-modal-desc" class="srk-snippet-textarea" rows="4" 
                                  style="width: 100%; padding: 12px 16px; border: 1px solid #dcdcde; border-radius: 6px; font-size: 14px; line-height: 1.5; resize: vertical; min-height: 100px; background: #fff;"
                                  placeholder="' . esc_attr( $post_excerpt ) . '">' . esc_textarea( $initial_desc ) . '</textarea>
                        
                        <!-- Character Counter -->
                        <div class="srk-desc-counter" style="display: flex; justify-content: space-between; align-items: center; margin-top: 8px;">
                            <span style="font-size: 12px; color: #646970;">
                                Recommended: 150-160 characters
                            </span>
                            <span class="srk-char-count" style="font-size: 12px; font-weight: 600; color: #1d2327;">' . strlen( $initial_desc ) . '/160</span>
                        </div>
                        
                        <!-- All Description Tags -->
                        <div id="srk-all-desc-tags" class="srk-all-tags-container" 
                            style="display: none; margin-top: 15px; padding: 16px; background: #fff; border: 1px solid #e2e4e7; border-radius: 6px; box-shadow: 0 4px 12px rgba(0,0,0,0.08);">
                            <div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 12px;">
                                <div>
                                    <span style="font-size: 13px; font-weight: 600; color: #1d2327;">
                                        All Available Description Tags
                                    </span>
                                    <div style="font-size: 11px; color: #646970; margin-top: 2px;">
                                        Click to insert any tag
                                    </div>
                                </div>
                                <button type="button" class="srk-hide-tags-btn" data-section="desc"
                                        style="font-size: 11px; padding: 4px 10px; background: #f0f0f1; border: 1px solid #dcdcde; border-radius: 4px; cursor: pointer; color: #50575e; display: flex; align-items: center; gap: 4px;">
                                    <span>Hide tags</span>
                                    <span style="font-size: 10px;">↑</span>
                                </button>
                            </div>
                            
                            <!-- Search Input -->
                            <div style="margin-bottom: 12px;">
                                <input type="text" class="srk-tags-search" data-section="desc"
                                    placeholder="Search for an item..."
                                    style="width: 100%; padding: 8px 12px; border: 1px solid #dcdcde; border-radius: 4px; font-size: 13px; background: #f8f9fa;">
                            </div>
                            
                            <!-- Tags List -->
                            <div class="srk-tags-list" style="max-height: 250px; overflow-y: auto; padding-right: 5px;">
                                ' . $desc_all_tags_html . '
                            </div>
                        </div>
                    </div>
                </div>
                
                <!-- Modal Footer -->
                <div class="srk-modal-footer" style="padding: 20px 24px; border-top: 1px solid #e2e4e7; background: #fff; display: flex; justify-content: space-between; gap: 12px;">
                    <div style="display: flex; gap: 12px;">
                        <button type="button" class="srk-modal-reset"
                                style="padding: 8px 16px; font-size: 13px; line-height: 1.5; background: #f0f0f1; border: 1px solid #dcdcde; border-radius: 4px; cursor: pointer; color: #2c3338; display: flex; align-items: center; gap: 6px;">
                            <span style="font-size: 16px;">🔄</span>
                            Reset All
                        </button>
                        <button type="button" class="srk-modal-cancel"
                                style="padding: 8px 16px; font-size: 13px; line-height: 1.5; background: #f0f0f1; border: 1px solid #dcdcde; border-radius: 4px; cursor: pointer; color: #2c3338;">
                            Cancel
                        </button>
                    </div>
                    <div style="display: flex; gap: 12px; align-items: center;">
                        <span id="srk-saving-text" style="display: none; font-size: 12px; color: #007cba;">Saving...</span>
                        <button type="button" class="srk-modal-save"
                                style="padding: 8px 20px; font-size: 13px; line-height: 1.5; background: #0B1D51; border: 1px solid #0B1D51; border-radius: 4px; cursor: pointer; color: #fff; font-weight: 600; display: flex; align-items: center; gap: 6px;">
                            <span style="font-size: 16px;">💾</span>
                            Save Changes
                        </button>
                    </div>
                </div>
            </div>
        </div>';
    }

    private function get_template_tags() {
        $post_id = $this->post_id;
        $post = get_post( $post_id );
        
        // Get current values using resolver
        $current_values = $this->get_current_tag_values();
        
        return array(
            'Site Name' => array(
                'tag' => '%site_title%',
                'description' => 'The name of your website.',
                'current_value' => $current_values['site_name']
            ),
            'Post Title' => array(
                'tag' => '%title%',
                'description' => 'The original title of the current post.',
                'current_value' => $current_values['post_title']
            ),
            'Post Excerpt' => array(
                'tag' => '%excerpt%',
                'description' => 'A short summary of the post content.',
                'current_value' => $current_values['post_excerpt']
            ),
            'Site Description' => array(
                'tag' => '%sitedesc%',
                'description' => 'The tagline of your website.',
                'current_value' => $current_values['site_description']
            ),
            'Separator' => array(
                'tag' => '%sep%',
                'description' => 'The title separator (e.g., -, |, >)',
                'current_value' => $current_values['separator']
            ),
            'Author Name' => array(
                'tag' => '%author_name%',
                'description' => 'The display name of the post author.',
                'current_value' => $current_values['author_name']
            ),
            'Author First Name' => array(
                'tag' => '%author_first_name%',
                'description' => 'The first name of the post author.',
                'current_value' => $current_values['author_first_name']
            ),
            'Author Last Name' => array(
                'tag' => '%author_last_name%',
                'description' => 'The last name of the post author.',
                'current_value' => $current_values['author_last_name']
            ),
            'Category Title' => array(
                'tag' => '%term_title%',
                'description' => 'The primary category of the post.',
                'current_value' => $current_values['category_title']
            ),
            'Current Year' => array(
                'tag' => '%year%',
                'description' => 'The current year.',
                'current_value' => $current_values['current_year']
            ),
            'Post Date' => array(
                'tag' => '%post_date%',
                'description' => 'The publication date of the post.',
                'current_value' => $current_values['post_date']
            ),
            'Post Content' => array(
                'tag' => '%content%',
                'description' => 'A trimmed excerpt of the post content.',
                'current_value' => $current_values['post_content']
            ),
            'Permalink' => array(
                'tag' => '%permalink%',
                'description' => 'The permanent URL of the post.',
                'current_value' => $current_values['permalink']
            ),
        );
    }

    /**
     * Get current values for all tags
     */
    private function get_current_tag_values() {
        $post_id = $this->post_id;
        $post = get_post( $post_id );
        
        // Get author info
        $author_id = $post->post_author;
        
        // Get categories
        $categories = get_the_category( $post_id );
        $primary_category = ! empty( $categories ) ? $categories[0]->name : 'Uncategorized';
        $all_categories = ! empty( $categories ) ? implode( ', ', wp_list_pluck( $categories, 'name' ) ) : 'Uncategorized';
        
        // Get post content
        $post_content = wp_strip_all_tags( $post->post_content );
        
        return array(
            'site_name' => get_bloginfo('name'),
            'post_title' => get_the_title( $post_id ) ?: '(No title)',
            'post_excerpt' => $post->post_excerpt ?: wp_trim_words( $post_content, 20 ),
            'site_description' => get_bloginfo('description') ?: 'No site description set',
            'separator' => get_option( 'srk_title_separator', '-' ),
            'author_name' => get_the_author_meta( 'display_name', $author_id ) ?: 'Unknown',
            'author_first_name' => get_the_author_meta( 'first_name', $author_id ) ?: 'Author',
            'author_last_name' => get_the_author_meta( 'last_name', $author_id ) ?: '',
            'category_title' => $primary_category,
            'categories' => $all_categories,
            'current_date' => date_i18n( get_option( 'date_format' ) ),
            'current_year' => date_i18n( 'Y' ),
            'post_date' => get_the_date( '', $post_id ) ?: date_i18n( get_option( 'date_format' ) ),
            'post_content' => wp_trim_words( $post_content, 20 ),
            'permalink' => get_permalink( $post_id ) ?: site_url()
        );
    }

    /**
     * Update sync timestamp after save
     */
    public function update_sync_timestamp( $post_id, $editor_data ) {
        update_post_meta( $post_id, '_srk_last_sync', current_time('timestamp') );
    }

    /**
     * Handle meta updates for real-time sync
     */
    public function handle_meta_update( $meta_id, $post_id, $meta_key, $meta_value ) {
        $srk_meta_keys = [
            '_srk_meta_title',
            '_srk_meta_description',
            '_srk_canonical_url',
            '_srk_meta_keywords',
            '_srk_focus_keyword',
            '_srk_advanced_settings',
            '_srk_last_sync'
        ];
        
        if ( in_array( $meta_key, $srk_meta_keys ) ) {
            error_log( "SRK Elementor: Meta updated from Gutenberg - {$meta_key} for post {$post_id}" );
        }
    }

    public function enqueue_editor_scripts() {
        global $post;
        
        if ( ! $post ) return;
        
        // CSS for Elementor panel
        wp_enqueue_style(
            'srk-elementor-editor',
            plugin_dir_url( __FILE__ ) . '../admin/css/srk-elementor-meta-panel.css',
            [],
            '1.1.0'
        );

        // Get sync data
        $last_sync = get_post_meta( $post->ID, '_srk_last_sync', true );
        $post_type = $post->post_type;
        
        // Get content type settings
        $content_type_settings = get_option( 'srk_meta_content_types_settings', [] );
        $current_settings = isset( $content_type_settings[ $post_type ] ) ? $content_type_settings[ $post_type ] : [];
        
        // Default templates
        $default_title = isset( $current_settings['title'] ) && ! empty( $current_settings['title'] ) 
            ? $current_settings['title'] 
            : '%title% %sep% %site_title%';
            
        $default_desc = isset( $current_settings['desc'] ) && ! empty( $current_settings['desc'] )
            ? $current_settings['desc']
            : '%excerpt%';

        // Get advanced settings
        $advanced_settings = get_post_meta( $post->ID, '_srk_advanced_settings', true );
        if ( empty( $advanced_settings ) ) {
            $advanced_settings = [
                'use_default_settings' => '1',
                'robots_meta' => [
                    'noindex' => '0',
                    'nofollow' => '0',
                    'noarchive' => '0',
                    'notranslate' => '0',
                    'noimageindex' => '0',
                    'nosnippet' => '0',
                    'noodp' => '0',
                    'max_snippet' => -1,
                    'max_video_preview' => -1,
                    'max_image_preview' => 'large'
                ]
            ];
        }

        // Get post content
        $post_content = $post ? wp_strip_all_tags( get_post_field( 'post_content', $post->ID ) ) : '';
        $post_content = substr( $post_content, 0, 300 );
        
        // Get post excerpt
        $post_excerpt = $post->post_excerpt;
        if ( empty( $post_excerpt ) ) {
            $post_excerpt = wp_trim_words( wp_strip_all_tags( $post_content ), 20 );
        }
        
        // Get author data
        $author_id = $post->post_author;
        $author_name = get_the_author_meta( 'display_name', $author_id );
        $author_first_name = get_the_author_meta( 'first_name', $author_id );
        $author_last_name = get_the_author_meta( 'last_name', $author_id );
        
        // Get category data
        $categories = get_the_category( $post->ID );
        $primary_category = ! empty( $categories ) ? $categories[0]->name : 'Uncategorized';
        $all_categories = ! empty( $categories ) ? wp_strip_all_tags( get_the_category_list( ', ', '', $post->ID ) ) : 'Uncategorized';
        
        // Get post date
        $post_date = get_the_date( '', $post->ID );
        
        // JS for Elementor panel
        wp_enqueue_script(
            'srk-elementor-editor',
            plugin_dir_url( __FILE__ ) . '../admin/js/srk-elementor-meta-panel.js',
            [ 'jquery', 'elementor-editor' ],
            '1.3.0',
            true
        );

        // Localize script with COMPLETE DATA
        wp_localize_script( 'srk-elementor-editor', 'srkElementorData', [
            'ajax_url' => admin_url( 'admin-ajax.php' ),
            'nonce' => wp_create_nonce( 'srk_elementor_nonce' ),
            'post_id' => $post->ID,
            'post_type' => $post_type,
            
            // Site data
            'site_url' => get_site_url(),
            'site_name' => get_bloginfo( 'name' ),
            'site_desc' => get_bloginfo( 'description' ),
            'separator' => get_option( 'srk_title_separator', '-' ),
            
            // Post data
            'post_title' => get_the_title( $post->ID ),
            'post_excerpt' => $post_excerpt,
            'post_content' => $post_content,
            'post_date' => $post_date,
            'permalink' => get_permalink( $post->ID ),
            
            // Author data
            'author_name' => $author_name,
            'author_first_name' => $author_first_name,
            'author_last_name' => $author_last_name,
            
            // Category data
            'categories' => $all_categories,
            'primary_category' => $primary_category,
            
            // Date data
            'current_date' => date_i18n( get_option( 'date_format' ) ),
            'current_year' => date_i18n( 'Y' ),
            
            // Settings
            'default_title' => $default_title,
            'default_desc' => $default_desc,
            'last_sync' => $last_sync ?: 0,
            'sync_interval' => 5000,
            'content_type_settings' => $current_settings,
            'advanced_settings' => $advanced_settings,
            
            // Meta values
            'meta_title' => get_post_meta( $post->ID, '_srk_meta_title', true ),
            'meta_description' => get_post_meta( $post->ID, '_srk_meta_description', true ),
            'focus_keyword' => get_post_meta( $post->ID, '_srk_focus_keyword', true ),
            'meta_keywords' => get_post_meta( $post->ID, '_srk_meta_keywords', true ),
            'canonical_url' => get_post_meta( $post->ID, '_srk_canonical_url', true ),
            
            // Debug info
            'debug' => [
                'post_exists' => ! empty( $post ),
                'post_content_length' => strlen( $post_content ),
                'categories_count' => count( $categories )
            ],
            
            'i18n' => [
                'saving' => __( 'Saving...', 'seo-repair-kit' ),
                'saved' => __( 'Saved successfully!', 'seo-repair-kit' ),
                'error' => __( 'Error saving', 'seo-repair-kit' ),
                'synced' => __( 'Settings updated from other editor', 'seo-repair-kit' ),
                'reset' => __( 'Reset to Default', 'seo-repair-kit' ),
                'resetAll' => __( 'Reset All to Defaults', 'seo-repair-kit' ),
                'saveChanges' => __( 'Save Changes', 'seo-repair-kit' ),
                'editSnippet' => __( 'Edit Snippet', 'seo-repair-kit' ),
                'preview' => __( 'Preview:', 'seo-repair-kit' ),
                'seoTitle' => __( 'SEO Title', 'seo-repair-kit' ),
                'metaDescription' => __( 'Meta Description', 'seo-repair-kit' ),
                'canonicalUrl' => __( 'Canonical URL', 'seo-repair-kit' ),
                'titleHelp' => __( 'Title shown in search results', 'seo-repair-kit' ),
                'descHelp' => __( 'Description shown in search results', 'seo-repair-kit' ),
                'canonicalHelp' => __( 'Preferred URL for this content', 'seo-repair-kit' ),
                'viewAllTags' => __( 'View all tags →', 'seo-repair-kit' ),
                'selectTag' => __( 'Select a Tag', 'seo-repair-kit' ),
                'replaceDeleteTag' => __( 'Replace or Delete Tag', 'seo-repair-kit' ),
                'searchTags' => __( 'Search for an item...', 'seo-repair-kit' ),
                'deleteTag' => __( 'Delete Tag', 'seo-repair-kit' ),
                'cancel' => __( 'Cancel', 'seo-repair-kit' ),
                'noTitle' => __( '(No title)', 'seo-repair-kit' ),
                'noDescription' => __( '(No description)', 'seo-repair-kit' ),
                'autoSync' => __( 'Auto-sync enabled', 'seo-repair-kit' ),
                'uncategorized' => __( 'Uncategorized', 'seo-repair-kit' ),
                'unknownAuthor' => __( 'Unknown Author', 'seo-repair-kit' )
            ]
        ] );
        
        // Also pass authors data if needed
        $authors = [];
        $all_authors = get_users( [ 'fields' => 'all' ] );

        foreach ( $all_authors as $author ) {
            $authors[ $author->ID ] = [
                'name'       => $author->display_name,
                'first_name' => $author->first_name ?: '',
                'last_name'  => $author->last_name ?: '',
            ];
        }

        wp_add_inline_script( 'srk-elementor-editor', 'window.srkAuthors = ' . wp_json_encode( $authors ) . ';', 'before' );
    }
    /**
     * AJAX handler to reset Elementor meta to content type defaults
     * Deletes post meta so content type templates take over
     */
    public function ajax_reset_elementor_meta() {
        // Verify nonce
        if ( ! wp_verify_nonce( $_POST['nonce'] ?? '', 'srk_elementor_nonce' ) ) {
            wp_send_json_error( 'Invalid security token' );
        }
        
        $post_id = intval( $_POST['post_id'] ?? 0 );
        $field = sanitize_text_field( $_POST['field'] ?? '' );
        
        if ( ! $post_id || ! current_user_can( 'edit_post', $post_id ) ) {
            wp_send_json_error( 'Permission denied' );
        }
        
        // Delete specific meta or all
        if ( $field === 'all' ) {
            // Delete all SRK meta for this post
            delete_post_meta( $post_id, '_srk_meta_title' );
            delete_post_meta( $post_id, '_srk_meta_description' );
            delete_post_meta( $post_id, '_srk_focus_keyword' );
            delete_post_meta( $post_id, '_srk_meta_keywords' );
            delete_post_meta( $post_id, '_srk_canonical_url' );
            delete_post_meta( $post_id, '_srk_advanced_settings' );
            delete_post_meta( $post_id, '_srk_use_default_robots' );
            delete_post_meta( $post_id, '_srk_last_sync' );
            
            wp_send_json_success( 'All SEO meta deleted - now using content type defaults' );
            
        } elseif ( $field === 'title' ) {
            delete_post_meta( $post_id, '_srk_meta_title' );
            wp_send_json_success( 'Title meta deleted - now using content type template' );
            
        } elseif ( $field === 'desc' ) {
            delete_post_meta( $post_id, '_srk_meta_description' );
            wp_send_json_success( 'Description meta deleted - now using content type template' );
            
        } else {
            wp_send_json_error( 'Invalid field specified' );
        }
    }

}  // ← This closes the class

// Initialize if Elementor is active
add_action( 'elementor/loaded', function() {
    if ( class_exists( 'Elementor\Plugin' ) ) {
        new SRK_Elementor_Integration();
        error_log( 'SRK Elementor Integration: Plugin initialized successfully' );
    }
} );