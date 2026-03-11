<?php
if ( ! defined( 'ABSPATH' ) ) {
    exit;
}
/**
 * SEO Repair Kit - Gutenberg & Classic Editor Integration with Real-time Sync
 * 
 * @package SEO_Repair_Kit
 * @since 2.1.3 - Removed duplicate asset hooks, optimized database queries
 * @version 2.1.3
 */
class SRK_Gutenberg_Integration {

    private static $instance = null;

    /** Excluded post types (same as content types manager). */
    private static $excluded_post_types = array(
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
     * Get allowed post types for SEO panel: public, show_ui, optionally show_in_rest.
     * WooCommerce product and other common CPTs are included even without show_in_rest.
     * Used by Gutenberg and Elementor so both use the same list. No duplicate logic.
     *
     * @return array List of post type names.
     */
    public static function get_allowed_seo_post_types() {
        $all = get_post_types(
            array(
                'public'       => true,
                'show_ui'      => true,
                'show_in_rest' => true,
            ),
            'names'
        );
        $list = array_values( array_diff( $all, self::$excluded_post_types ) );
        $ensure = array( 'product', 'page', 'post' );
        foreach ( $ensure as $pt ) {
            if ( post_type_exists( $pt ) && ! in_array( $pt, $list, true ) ) {
                $obj = get_post_type_object( $pt );
                if ( $obj && ! empty( $obj->public ) && ! empty( $obj->show_ui ) ) {
                    $list[] = $pt;
                }
            }
        }
        return $list;
    }

    public static function get_instance() {
        if ( null === self::$instance ) {
            self::$instance = new self();
        }
        return self::$instance;
    }

    public function __construct() {
        // Common hooks for both editors
        add_action('add_meta_boxes', array($this, 'add_seo_metabox'));
        add_action('save_post', array($this, 'save_metabox_data'));
        add_action('admin_enqueue_scripts', array($this, 'enqueue_metabox_assets'));
        add_action('wp_ajax_srk_get_post_data', array($this, 'ajax_get_post_data'));
        add_action('wp_ajax_srk_save_meta_data', array($this, 'ajax_save_meta_data'));
        add_action('wp_ajax_srk_sync_meta_data', array($this, 'ajax_sync_meta_data'));
        add_action('wp_ajax_srk_save_advanced_settings', array($this, 'ajax_save_advanced_settings'));
        
        // Gutenberg specific
        add_action('enqueue_block_editor_assets', array($this, 'enqueue_gutenberg_assets'));
        
        // REST API for real-time sync
        add_action('rest_api_init', array($this, 'register_rest_routes'));
        
        // Meta update hook for sync
        add_action('updated_post_meta', array($this, 'on_meta_update'), 10, 4);
        
        // Register meta fields
        add_action('init', array($this, 'register_post_meta'));
        
        // Force custom-fields support
        add_action('init', array($this, 'force_custom_fields_support'), 99);
        
        add_action('wp_ajax_srk_reset_to_content_type', array($this, 'ajax_reset_to_content_type'));

        
    }
    /**
     * Output meta tags in frontend header
     */
    public function output_frontend_meta_tags() {
        // Only for single posts/pages
        if (!is_singular() || is_feed()) {
            return;
        }

        global $post;
        if (!$post) {
            return;
        }

        $post_id = $post->ID;
        
        // Output meta title if set
        $meta_title = get_post_meta($post_id, '_srk_meta_title', true);
        if (!empty($meta_title)) {
            $processed_title = $this->process_template($meta_title, $post_id);
            echo '<meta name="title" content="' . esc_attr($processed_title) . '" />' . "\n";
        }
        
        // Output meta description if set
        $meta_description = get_post_meta($post_id, '_srk_meta_description', true);
        if (!empty($meta_description)) {
            $processed_description = $this->process_template($meta_description, $post_id);
            echo '<meta name="description" content="' . esc_attr($processed_description) . '" />' . "\n";
        }
        
        // Output canonical URL if set
        $canonical_url = get_post_meta($post_id, '_srk_canonical_url', true);
        if (!empty($canonical_url)) {
            echo '<link rel="canonical" href="' . esc_url($canonical_url) . '" />' . "\n";
        }
    }

    /**
     * Process template tags for frontend output - FIXED VERSION
     */
    private function process_template($template, $post_id, $for_preview = false) {
        if (empty($template)) {
            return '';
        }
    
        $post = get_post($post_id);
        if (!$post) {
            return '';
        }
        
        $post_title = $post->post_title;
        $post_excerpt = get_the_excerpt($post);
        $post_type = $post->post_type;
        
        // Get the actual post type label
        $post_type_obj = get_post_type_object($post_type);
        $post_type_label = $post_type_obj ? $post_type_obj->labels->singular_name : $post_type;
        
        $author_id = $post->post_author;
        $author_data = get_userdata($author_id);
        $site_name = get_bloginfo('name');
        $site_description = get_bloginfo('description');
        $separator = get_option('srk_title_separator', '-');
        
        $post_categories = get_the_category($post_id);
        $categories = '';
        $category_title = '';
        if (!empty($post_categories)) {
            $cat_names = wp_list_pluck($post_categories, 'name');
            $categories = implode(', ', $cat_names);
            $category_title = $post_categories[0]->name;
        }
        
        $processed = $template;
        
        // First, replace post-type specific tags (e.g., %title%, %product_title%)
        $processed = str_replace(
            [
                '%title%',
                '%excerpt%',
            ],
            [
                $post_title,
                $post_excerpt
            ],
            $processed
        );
        
        // Then replace all other generic tags (fallback for templates using %title% on custom post types)
        $processed = str_replace(
            [
                '%site_title%',
                '%sitedesc%',
                '%title%',  // Generic fallback
                '%excerpt%', // Generic fallback
                '%sep%',
                '%author_first_name%',
                '%author_last_name%',
                '%author_name%',
                '%categories%',
                '%term_title%',
                '%month%',
                '%year%',
                '%custom_field%',
                '%permalink%',
                '%content%',
                '%post_date%',
                '%post_day%'
            ],
            [
                $site_name,
                $site_description,
                $post_title,  // Use actual post title
                $post_excerpt, // Use actual post excerpt
                $separator,
                $author_data ? $author_data->first_name : '',
                $author_data ? $author_data->last_name : '',
                $author_data ? $author_data->display_name : '',
                $categories,
                $category_title,
                date_i18n('F j, Y'),
                date_i18n('d'),
                date_i18n('F'),
                date_i18n('Y'),
                '', // Custom field - will need separate implementation
                get_permalink($post_id),
                wp_trim_words($post->post_content, 20, '...'),
                get_the_date('F j, Y', $post_id),
                get_the_date('d', $post_id)
            ],
            $processed
        );
        
        // For preview in admin, return with proper escaping
        if ($for_preview) {
            return esc_html($processed);
        }
        
        // For frontend, return as is
        return $processed;
    }

    /**
     * Add SEO metabox to all allowed post types. Respects content type enable and show_meta_box.
     */
    public function add_seo_metabox() {
        $post_types = self::get_allowed_seo_post_types();
        $content_type_settings = get_option( 'srk_meta_content_types_settings', array() );

        foreach ( $post_types as $post_type ) {
            $enable = isset( $content_type_settings[ $post_type ]['enable'] ) ? (int) $content_type_settings[ $post_type ]['enable'] : 1;
            if ( $enable === 0 ) {
                continue;
            }
            $show_meta_box = isset( $content_type_settings[ $post_type ]['advanced']['show_meta_box'] ) ? ( $content_type_settings[ $post_type ]['advanced']['show_meta_box'] === '1' ) : true;
            if ( ! $show_meta_box ) {
                continue;
            }
            add_meta_box(
                'srk_meta_box',
                __( 'SEO Repair Kit', 'seo-repair-kit' ),
                array( $this, 'render_metabox' ),
                $post_type,
                'normal',
                'high'
            );
        }
    }
    
    /**
     * Render metabox - SAME FOR BOTH EDITORS
     */
    public function render_metabox($post) {
        // Get saved meta values
        $meta_title = get_post_meta($post->ID, '_srk_meta_title', true);
        $meta_description = get_post_meta($post->ID, '_srk_meta_description', true);
        $canonical_url = get_post_meta($post->ID, '_srk_canonical_url', true);
        $last_sync = get_post_meta($post->ID, '_srk_last_sync', true);
        
        // Get advanced settings
        $advanced_settings = get_post_meta($post->ID, '_srk_advanced_settings', true);
        if (empty($advanced_settings)) {
            $advanced_settings = $this->get_default_advanced_settings();
        }
        
        // Process the actual values for preview
        $processed_title = $this->process_template($meta_title, $post->ID, true);
        $processed_description = $this->process_template($meta_description, $post->ID, true);
        
        // Check if it's Gutenberg
        $is_gutenberg = function_exists('use_block_editor_for_post') && use_block_editor_for_post($post);
        
        // Nonce field for security
        wp_nonce_field('srk_metabox_save', 'srk_metabox_nonce');
        ?>
        <div id="srk-metabox-container" 
            data-post-id="<?php echo esc_attr($post->ID); ?>" 
            data-editor-type="<?php echo $is_gutenberg ? 'gutenberg' : 'classic'; ?>"
            data-last-sync="<?php echo esc_attr($last_sync ? $last_sync : 0); ?>">
            
            <?php if (!$is_gutenberg): ?>
                <?php $this->render_classic_metabox_form( $post, $meta_title, $meta_description, $canonical_url, $advanced_settings, $processed_title, $processed_description ); ?>
            <?php else: ?>
                <!-- Gutenberg will handle its own UI -->
                <div id="srk-gutenberg-placeholder" style="display: none;"></div>
                <!-- Hidden fields for Gutenberg (JS updates these) -->
                <input type="hidden" id="srk_meta_title" name="srk_meta_title" value="<?php echo esc_attr($meta_title); ?>">
                <input type="hidden" id="srk_meta_description" name="srk_meta_description" value="<?php echo esc_attr($meta_description); ?>">
                <input type="hidden" id="srk_canonical_url" name="srk_canonical_url" value="<?php echo esc_url($canonical_url); ?>">
                <input type="hidden" id="srk_advanced_settings" name="srk_advanced_settings" value="<?php echo esc_attr(wp_json_encode($advanced_settings)); ?>">
                
                <!-- Preview values for meta box -->
                <div class="srk-meta-preview">
                    <div class="url"><?php echo esc_html( str_replace( array( 'http://', 'https://' ), '', get_permalink( $post->ID ) ) ); ?></div>
                    <div class="title"><?php echo esc_html( $processed_title ?: __( '(No title)', 'seo-repair-kit' ) ); ?></div>
                    <div class="desc"><?php echo esc_html( $processed_description ?: __( '(No description)', 'seo-repair-kit' ) ); ?></div>
                </div>
            <?php endif; ?>
            
            <input type="hidden" id="srk_last_sync" name="srk_last_sync" value="<?php echo esc_attr($last_sync ? $last_sync : 0); ?>">
            
            <!-- Sync status indicator -->
            <div id="srk-sync-status" style="display: none; position: fixed; top: 32px; right: 20px; z-index: 99999; 
                padding: 10px 15px; background: #f0f6fc; border: 1px solid #c3d4e9; border-radius: 4px; 
                font-size: 13px; box-shadow: 0 2px 5px rgba(0,0,0,0.1); max-width: 300px;">
                <span class="srk-sync-text"></span>
                <button type="button" class="srk-sync-close" style="margin-left: 10px; background: none; border: none; 
                        color: #666; cursor: pointer; font-size: 16px; line-height: 1;">×</button>
            </div>
        </div>
        <?php
    }
    
    /**
     * Render full Classic Editor form (no JS required for initial display).
     * Uses srk_classic_* fields so save can build advanced_settings server-side.
     */
    private function render_classic_metabox_form( $post, $meta_title, $meta_description, $canonical_url, $advanced_settings ) {
        $use_default = isset( $advanced_settings['use_default_settings'] ) && $advanced_settings['use_default_settings'] === '1';
        $robots = isset( $advanced_settings['robots_meta'] ) && is_array( $advanced_settings['robots_meta'] )
            ? wp_parse_args( $advanced_settings['robots_meta'], $this->get_default_robots_structure() )
            : $this->get_default_robots_structure();
        $preview_title = ! empty( $meta_title ) ? SRK_Meta_Resolver::parse_template( $meta_title, $post->ID ) : $post->post_title;
        $preview_desc  = ! empty( $meta_description ) ? SRK_Meta_Resolver::parse_template( $meta_description, $post->ID ) : wp_trim_words( get_the_excerpt( $post ), 25 );
        ?>
        <div class="srk-meta-box-wrapper">
            <nav class="srk-tabs-nav">
                <button type="button" class="srk-tab-btn active" data-tab="general"><?php esc_html_e( 'Title & Description', 'seo-repair-kit' ); ?></button>
                <button type="button" class="srk-tab-btn" data-tab="advanced"><?php esc_html_e( 'Advanced', 'seo-repair-kit' ); ?></button>
            </nav>
            <div class="srk-tab-pane active" data-tab="general">
                <table class="form-table">
                    <tr>
                        <th scope="row"><label for="srk_meta_title"><?php esc_html_e( 'SEO Title', 'seo-repair-kit' ); ?></label></th>
                        <td>
                            <input type="text" id="srk_meta_title" name="srk_meta_title" value="<?php echo esc_attr( $meta_title ); ?>" class="large-text" placeholder="<?php esc_attr_e( 'Leave empty to use template', 'seo-repair-kit' ); ?>" />
                            <p class="description"><?php esc_html_e( 'Custom meta title. Leave empty to use content type or global template.', 'seo-repair-kit' ); ?></p>
                        </td>
                    </tr>
                    <tr>
                        <th scope="row"><label for="srk_meta_description"><?php esc_html_e( 'Meta Description', 'seo-repair-kit' ); ?></label></th>
                        <td>
                            <textarea id="srk_meta_description" name="srk_meta_description" rows="3" class="large-text" placeholder="<?php esc_attr_e( 'Leave empty to use template', 'seo-repair-kit' ); ?>"><?php echo esc_textarea( $meta_description ); ?></textarea>
                            <p class="description"><?php esc_html_e( 'Custom meta description.', 'seo-repair-kit' ); ?></p>
                        </td>
                    </tr>
                    <tr>
                        <th scope="row"><?php esc_html_e( 'SERP Preview', 'seo-repair-kit' ); ?></th>
                        <td>
                            <div class="srk-preview-wrapper">
                                <div class="srk-meta-preview">
                                    <div class="url"><?php echo esc_html( str_replace( array( 'http://', 'https://' ), '', get_permalink( $post->ID ) ) ); ?></div>
                                    <div class="title"><?php echo esc_html( $preview_title ?: __( '(No title)', 'seo-repair-kit' ) ); ?></div>
                                    <div class="desc"><?php echo esc_html( $preview_desc ?: __( '(No description)', 'seo-repair-kit' ) ); ?></div>
                                </div>
                            </div>
                        </td>
                    </tr>
                    <tr>
                        <th scope="row"><label for="srk_canonical_url"><?php esc_html_e( 'Canonical URL', 'seo-repair-kit' ); ?></label></th>
                        <td>
                            <input type="url" id="srk_canonical_url" name="srk_canonical_url" value="<?php echo esc_url( $canonical_url ); ?>" class="large-text" placeholder="<?php esc_attr_e( 'Leave empty to use permalink', 'seo-repair-kit' ); ?>" />
                        </td>
                    </tr>
                </table>
            </div>
            <div class="srk-tab-pane" data-tab="advanced" style="display:none;">
                <div class="srk-advanced-section">
                    <div class="srk-toggle-setting">
                        <label class="srk-toggle-switch">
                            <input type="checkbox" name="srk_classic_use_default" id="srk_classic_use_default" value="1" <?php checked( $use_default, true ); ?> />
                            <span class="srk-toggle-slider"></span>
                        </label>
                        <span class="srk-toggle-label"><?php esc_html_e( 'Use Default Settings', 'seo-repair-kit' ); ?></span>
                    </div>
                    <div class="srk-robots-meta-container srk-classic-robots-row" style="<?php echo $use_default ? 'display:none;' : ''; ?>">
                        <div class="srk-robots-label"><strong><?php esc_html_e( 'Robots Meta', 'seo-repair-kit' ); ?></strong></div>
                        <div class="srk-robots-checkboxes">
                            <label class="srk-robots-checkbox"><input type="checkbox" name="srk_classic_robots[noindex]" value="1" <?php checked( $robots['noindex'], '1' ); ?> /> <?php esc_html_e( 'No Index', 'seo-repair-kit' ); ?></label>
                            <label class="srk-robots-checkbox"><input type="checkbox" name="srk_classic_robots[nofollow]" value="1" <?php checked( $robots['nofollow'], '1' ); ?> /> <?php esc_html_e( 'No Follow', 'seo-repair-kit' ); ?></label>
                            <label class="srk-robots-checkbox"><input type="checkbox" name="srk_classic_robots[noarchive]" value="1" <?php checked( $robots['noarchive'], '1' ); ?> /> <?php esc_html_e( 'No Archive', 'seo-repair-kit' ); ?></label>
                            <label class="srk-robots-checkbox"><input type="checkbox" name="srk_classic_robots[notranslate]" value="1" <?php checked( $robots['notranslate'], '1' ); ?> /> <?php esc_html_e( 'No Translate', 'seo-repair-kit' ); ?></label>
                            <label class="srk-robots-checkbox"><input type="checkbox" name="srk_classic_robots[noimageindex]" value="1" <?php checked( $robots['noimageindex'], '1' ); ?> /> <?php esc_html_e( 'No Image Index', 'seo-repair-kit' ); ?></label>
                            <label class="srk-robots-checkbox"><input type="checkbox" name="srk_classic_robots[nosnippet]" value="1" <?php checked( $robots['nosnippet'], '1' ); ?> /> <?php esc_html_e( 'No Snippet', 'seo-repair-kit' ); ?></label>
                            <label class="srk-robots-checkbox"><input type="checkbox" name="srk_classic_robots[noodp]" value="1" <?php checked( $robots['noodp'], '1' ); ?> /> <?php esc_html_e( 'No ODP', 'seo-repair-kit' ); ?></label>
                        </div>
                    </div>
                </div>
            </div>
        </div>
        <script>
        (function(){ var nav=document.querySelectorAll('.srk-meta-box-wrapper .srk-tab-btn'); var panes=document.querySelectorAll('.srk-meta-box-wrapper .srk-tab-pane'); var r=document.querySelector('.srk-classic-robots-row'); var d=document.getElementById('srk_classic_use_default'); if(nav.length){ nav.forEach(function(b){ b.addEventListener('click',function(){ var t=this.getAttribute('data-tab'); panes.forEach(function(p){ var show=p.getAttribute('data-tab')===t; p.style.display=show?'block':'none'; p.classList.toggle('active',show); }); nav.forEach(function(x){ x.classList.toggle('active',x===b); }); }); }); } if(d&&r){ d.addEventListener('change',function(){ r.style.display=this.checked?'none':'block'; }); }
        })();
        </script>
        <?php
    }
    
    /**
     * Get default robots structure (allowed keys only - no index/follow).
     *
     * @return array
     */
    private function get_default_robots_structure() {
        return [
            'noindex' => '0',
            'nofollow' => '0',
            'noarchive' => '0',
            'notranslate' => '0',
            'noimageindex' => '0',
            'nosnippet' => '0',
            'noodp' => '0',
            'max_snippet' => -1,
            'max_video_preview' => -1,
            'max_image_preview' => 'large',
        ];
    }

    /**
     * Minimal default when no advanced settings saved. No robots_meta — resolver handles fallback.
     */
    private function get_default_advanced_settings() {
        return array(
            'use_default_settings' => '1',
            'show_meta_box'        => '1',
        );
    }
    
    /**
     * Generate robots meta preview (mutual exclusivity: noimageindex hides max_image_preview, nosnippet hides max_snippet).
     */
    private function generate_robots_preview( $robots_meta ) {
        $robots_meta = wp_parse_args( is_array( $robots_meta ) ? $robots_meta : [], $this->get_default_robots_structure() );

        $directives = [];

        $directives[] = ( ! empty( $robots_meta['noindex'] ) && $robots_meta['noindex'] === '1' ) ? 'noindex' : 'index';
        $directives[] = ( ! empty( $robots_meta['nofollow'] ) && $robots_meta['nofollow'] === '1' ) ? 'nofollow' : 'follow';

        if ( ! empty( $robots_meta['noarchive'] ) && $robots_meta['noarchive'] === '1' ) $directives[] = 'noarchive';
        if ( ! empty( $robots_meta['notranslate'] ) && $robots_meta['notranslate'] === '1' ) $directives[] = 'notranslate';
        if ( ! empty( $robots_meta['noimageindex'] ) && $robots_meta['noimageindex'] === '1' ) {
            $directives[] = 'noimageindex';
        } elseif ( ! empty( $robots_meta['max_image_preview'] ) && $robots_meta['max_image_preview'] !== '' ) {
            $directives[] = 'max-image-preview:' . sanitize_text_field( $robots_meta['max_image_preview'] );
        } else {
            $directives[] = 'max-image-preview:large';
        }
        if ( ! empty( $robots_meta['nosnippet'] ) && $robots_meta['nosnippet'] === '1' ) {
            $directives[] = 'nosnippet';
        } elseif ( isset( $robots_meta['max_snippet'] ) && (int) $robots_meta['max_snippet'] > -1 ) {
            $directives[] = 'max-snippet:' . (int) $robots_meta['max_snippet'];
        }
        if ( ! empty( $robots_meta['noodp'] ) && $robots_meta['noodp'] === '1' ) $directives[] = 'noodp';

        if ( isset( $robots_meta['max_video_preview'] ) && (int) $robots_meta['max_video_preview'] > -1 ) {
            $directives[] = 'max-video-preview:' . (int) $robots_meta['max_video_preview'];
        }

        return implode( ', ', $directives );
    }
    
    /**
     * Enqueue metabox assets for Classic Editor. Loads once, only for allowed post types when meta box is shown.
     */
    public function enqueue_metabox_assets( $hook ) {
        global $post;

        if ( ! in_array( $hook, array( 'post.php', 'post-new.php' ), true ) || ! $post ) {
            return;
        }

        if ( ! in_array( $post->post_type, self::get_allowed_seo_post_types(), true ) ) {
            return;
        }

        $content_type_settings = get_option( 'srk_meta_content_types_settings', array() );
        $settings              = isset( $content_type_settings[ $post->post_type ] ) ? $content_type_settings[ $post->post_type ] : array();
        $enable                = isset( $settings['enable'] ) ? (int) $settings['enable'] : 1;
        if ( $enable === 0 ) {
            return;
        }
        $show_meta_box = isset( $settings['advanced']['show_meta_box'] ) ? ( $settings['advanced']['show_meta_box'] === '1' ) : true;
        if ( ! $show_meta_box ) {
            return;
        }

        if ( function_exists( 'use_block_editor_for_post' ) && use_block_editor_for_post( $post ) ) {
            return;
        }

        // Get plugin paths
        $plugin_dir = WP_PLUGIN_DIR . '/seo-repair-kit';
        $plugin_url = WP_PLUGIN_URL . '/seo-repair-kit';
        
        // Ensure directories exist
        $js_dir = $plugin_dir . '/admin/js';
        $css_dir = $plugin_dir . '/admin/css';
        
        if (!file_exists($js_dir)) mkdir($js_dir, 0755, true);
        if (!file_exists($css_dir)) mkdir($css_dir, 0755, true);
        
        // 1. Enqueue CORE JavaScript (Shared)
        $core_js_path = $js_dir . '/srk-core.js';
        $core_js_url = $plugin_url . '/admin/js/srk-core.js';
        
        if (!file_exists($core_js_path)) {
            file_put_contents($core_js_path, "// SRK Core JS\n('SRK Core loaded');");
        }
        
        wp_enqueue_script(
            'srk-core',
            $core_js_url,
            array('wp-api-fetch', 'jquery'),
            filemtime($core_js_path),
            true
        );
        
        // 2. Localize core data
        $this->localize_core_data($post, 'classic');
        
        // 3. Enqueue Classic Editor JS
        $classic_js_path = $js_dir . '/srk-classic.js';
        $classic_js_url = $plugin_url . '/admin/js/srk-classic.js';
        
        if (!file_exists($classic_js_path)) {
            file_put_contents($classic_js_path, "// SRK Classic JS\n('SRK Classic loaded');");
        }
        
        wp_enqueue_script(
            'srk-metabox-script',
            $classic_js_url,
            array('srk-core', 'jquery', 'wp-i18n'),
            filemtime($classic_js_path),
            true
        );
        
        // 4. Enqueue CSS (unified + meta manager for shared components)
        $css_path = $css_dir . '/srk-gutenberg-meta-panel.css';
        $css_url = $plugin_url . '/admin/css/srk-gutenberg-meta-panel.css';
        if (!file_exists($css_path)) {
            file_put_contents($css_path, "/* SRK Unified CSS */");
        }
        wp_enqueue_style( 'srk-metabox-styles', $css_url, array(), filemtime($css_path) );
        wp_enqueue_style(
            'srk-meta-manager-css',
            $plugin_url . '/admin/css/seo-repair-kit-meta-manager.css',
            array( 'srk-metabox-styles' ),
            defined( 'SEO_REPAIR_KIT_VERSION' ) ? SEO_REPAIR_KIT_VERSION : '2.1.3'
        );
    }
    
    /**
     * Enqueue Gutenberg assets
     */
    public function enqueue_gutenberg_assets() {
        global $post;
        $post_type = ( $post && isset( $post->post_type ) ) ? $post->post_type : ( isset( $_GET['post_type'] ) ? sanitize_key( $_GET['post_type'] ) : 'post' );
        if ( ! in_array( $post_type, self::get_allowed_seo_post_types(), true ) ) {
            return;
        }
        $post_for_data = $post;
        if ( ! $post_for_data && isset( $_GET['post'] ) && (int) $_GET['post'] ) {
            $post_for_data = get_post( (int) $_GET['post'] );
        }
        if ( ! $post_for_data ) {
            $post_for_data = (object) array( 'ID' => 0, 'post_type' => $post_type, 'post_title' => '', 'post_excerpt' => '', 'post_author' => get_current_user_id(), 'post_content' => '' );
        }
        
        // Get plugin paths
        $plugin_dir = WP_PLUGIN_DIR . '/seo-repair-kit';
        $plugin_url = WP_PLUGIN_URL . '/seo-repair-kit';
        
        // Ensure directories exist
        $js_dir = $plugin_dir . '/admin/js';
        $css_dir = $plugin_dir . '/admin/css';
        
        if (!file_exists($js_dir)) mkdir($js_dir, 0755, true);
        if (!file_exists($css_dir)) mkdir($css_dir, 0755, true);
        
        // 1. Enqueue CORE JavaScript (Shared)
        $core_js_path = $js_dir . '/srk-core.js';
        $core_js_url = $plugin_url . '/admin/js/srk-core.js';
        
        if (!file_exists($core_js_path)) {
            file_put_contents($core_js_path, "// SRK Core JS\n('SRK Core loaded');");
        }
        
        wp_enqueue_script(
            'srk-core',
            $core_js_url,
            array('wp-api-fetch', 'jquery'),
            filemtime($core_js_path),
            true
        );
        
        // 2. Localize core data for Gutenberg
        $this->localize_core_data( $post_for_data, 'gutenberg' );
        
        // 3. Enqueue Gutenberg-specific JS
        $gutenberg_js_path = $js_dir . '/srk-gutenberg-meta-panel.js';
        $gutenberg_js_url = $plugin_url . '/admin/js/srk-gutenberg-meta-panel.js';
        
        if (!file_exists($gutenberg_js_path)) {
            file_put_contents($gutenberg_js_path, "// SRK Gutenberg JS\n('SRK Gutenberg loaded');");
        }
        
        wp_enqueue_script(
            'srk-gutenberg-meta-panel',
            $gutenberg_js_url,
            array('srk-core', 'wp-plugins', 'wp-edit-post', 'wp-element', 'wp-components', 'wp-data', 'wp-i18n'),
            filemtime($gutenberg_js_path),
            true
        );
        
        // 4. Enqueue CSS
        $css_path = $css_dir . '/srk-gutenberg-meta-panel.css';
        $css_url = $plugin_url . '/admin/css/srk-gutenberg-meta-panel.css';
        
        if (!file_exists($css_path)) {
            file_put_contents($css_path, "/* SRK Unified CSS */");
        }
        
        wp_enqueue_style(
            'srk-gutenberg-meta-panel',
            $css_url,
            array(),
            filemtime($css_path)
        );
    }
    
    /**
     * Localize core data for JavaScript
     */
    private function localize_core_data($post, $editor_type) {
        
        $post_id = $post->ID;
        $post_type = $post->post_type;
        // Add dynamic post type tags
        $post_type_object = get_post_type_object($post_type);
        $post_type_label = $post_type_object ? $post_type_object->labels->singular_name : ucfirst($post_type);
        
        // Get saved meta
        $meta_title = get_post_meta($post_id, '_srk_meta_title', true);
        $meta_description = get_post_meta($post_id, '_srk_meta_description', true);
        $canonical_url = get_post_meta($post_id, '_srk_canonical_url', true);
        $last_sync = get_post_meta($post_id, '_srk_last_sync', true);
        $advanced_settings = get_post_meta($post_id, '_srk_advanced_settings', true);
        
        if (empty($advanced_settings)) {
            $advanced_settings = $this->get_default_advanced_settings();
        }
        
        // Get content type settings for this post type
        $content_type_settings = get_option('srk_meta_content_types_settings', []);
        $current_settings = isset($content_type_settings[$post_type]) ? $content_type_settings[$post_type] : [];
        
        // Default templates - use dynamic CPT tags, NEVER hardcoded post_title/post_excerpt
        $default_title = isset($current_settings['title']) && !empty(trim($current_settings['title'])) 
            ? $current_settings['title'] 
            : '%title% %sep% %site_title%';
            
        $default_desc = isset($current_settings['desc']) && !empty(trim($current_settings['desc']))
            ? $current_settings['desc']
            : '%excerpt%';
        
        // Get content type advanced settings (for UI preview only; resolver handles actual output)
        $content_type_advanced = isset( $current_settings['advanced'] ) ? $current_settings['advanced'] : array();
        $content_type_robots   = isset( $content_type_advanced['robots_meta'] ) && is_array( $content_type_advanced['robots_meta'] ) ? $content_type_advanced['robots_meta'] : $this->get_default_robots_structure();
        
        // Get post data for preview
        $post_excerpt = get_the_excerpt($post);
        $author_id = $post->post_author;
        $author_data = get_userdata($author_id);
        
        // Get categories
        $categories = '';
        $category_title = '';
        $post_categories = get_the_category($post_id);
        if (!empty($post_categories)) {
            $cat_names = wp_list_pluck($post_categories, 'name');
            $categories = implode(', ', $cat_names);
            $category_title = $post_categories[0]->name;
        }
        
        // Template tags (UNIFIED for both editors)
        $template_tags = array(
            'Site Name' => array(
                'tag' => '%site_title%',
                'description' => 'The name of your website.'
            ),
            'Site Description' => array(
                'tag' => '%sitedesc%',
                'description' => 'The tagline or description of your website.'
            ),
            'Post Title' => array(
                'tag' => '%title%',
                'description' => 'The title of the current post or page.'
            ),
            'Post Excerpt' => array(
                'tag' => '%excerpt%',
                'description' => 'The excerpt or summary of the post.'
            ),
            'Separator' => array(
                'tag' => '%sep%',
                'description' => 'The title separator defined in global settings.'
            ),
            'Author First Name' => array(
                'tag' => '%author_first_name%',
                'description' => 'The first name of the post author.'
            ),
            'Author Last Name' => array(
                'tag' => '%author_last_name%',
                'description' => 'The last name of the post author.'
            ),
            'Author Name' => array(
                'tag' => '%author_name%',
                'description' => 'The full display name of the post author.'
            ),
            'Category Title' => array(
                'tag' => '%term_title%',
                'description' => 'The primary category of the post.'
            ),
            'Current Month' => array(
                'tag' => '%month%',
                'description' => 'The current month name.'
            ),
            'Current Year' => array(
                'tag' => '%year%',
                'description' => 'The current year.'
            ),
            'Custom Field' => array(
                'tag' => '%custom_field%',
                'description' => 'Custom field value (advanced feature).'
            ),
            'Permalink' => array(
                'tag' => '%permalink%',
                'description' => 'The permanent URL of the post.'
            ),
            'Post Content' => array(
                'tag' => '%content%',
                'description' => 'A trimmed excerpt of the post content.'
            ),
            'Post Date' => array(
                'tag' => '%post_date%',
                'description' => 'The publication date of the post.'
            ),
            'Post Day' => array(
                'tag' => '%post_day%',
                'description' => 'The day when the post was published.'
            )
        );
        
        // Add CPT-specific tags (Product Title, Product Excerpt, etc.) - merged so View All Tags shows context-aware tags
        $template_tags[ $post_type_label . ' Title' ] = array(
            'tag'   => '%title%',
            'description' => 'The title of the current ' . strtolower( $post_type_label ) . '.',
        );
        $template_tags[ $post_type_label . ' Excerpt' ] = array(
            'tag'   => '%excerpt%',
            'description' => 'The excerpt or summary of the ' . strtolower( $post_type_label ) . '.',
        );
        
        // Relevant tags for quick buttons - CPT-aware (Product Title, Product Excerpt, etc.)
        $template_tags_relevant = [
            'title' => [
                $post_type_label . ' Title' => '%title%',
                'Site Name' => '%site_title%',
                'Separator' => '%sep%',
            ],
            'description' => [
                $post_type_label . ' Excerpt' => '%excerpt%',
                $post_type_label . ' Title' => '%title%',
                'Site Description' => '%sitedesc%',
            ]
        ];
        
        // Prepare UNIFIED data array
        $data = array(
            // Post info
            'postId' => $post_id,
            'postType' => $post_type,
            'editorType' => $editor_type,
            'isGutenberg' => ($editor_type === 'gutenberg'),
            
            // Site info
            'siteName' => get_bloginfo('name'),
            'siteDescription' => get_bloginfo('description'),
            'siteUrl' => get_site_url(),
            'separator' => get_option('srk_title_separator', '-'),
            
            // Current meta values
            'metaTitle' => $meta_title,
            'metaDescription' => $meta_description,
            'canonicalUrl' => $canonical_url,
            'lastSync' => $last_sync ? intval($last_sync) : 0,
            'advancedSettings' => $advanced_settings,
            
            // Content type settings (for defaults)
            'contentTypeSettings' => $current_settings,
            'contentTypeRobots' => $content_type_robots,
            
            // Default templates
            'defaultTitleTemplate' => $default_title,
            'defaultDescTemplate' => $default_desc,
            
            // Template tags
            'templateTags' => $template_tags,
            'templateTagsRelevant' => $template_tags_relevant,
            
            // Preview data
            'authorFirstName' => $author_data ? $author_data->first_name : 'John',
            'authorLastName' => $author_data ? $author_data->last_name : 'Doe',
            'authorName' => $author_data ? $author_data->display_name : 'John Doe',
            'categories' => $categories,
            'categoryTitle' => $category_title,
            'currentDate' => date_i18n('F j, Y'),
            'currentDay' => date_i18n('d'),
            'currentMonth' => date_i18n('F'),
            'currentYear' => date_i18n('Y'),
            'customField' => 'Custom Field Value',
            'permalink' => get_permalink($post_id),
            'postContent' => wp_trim_words($post->post_content, 20, '...'),
            'postDate' => get_the_date('F j, Y', $post_id),
            'postDay' => get_the_date('d', $post_id),
            'postExcerpt' => $post_excerpt,
            'postTitle' => $post->post_title,
            
            // URLs and nonce
            'ajaxUrl' => admin_url('admin-ajax.php'),
            'restUrl' => rest_url('srk/v1/'),
            'nonce' => wp_create_nonce('srk_unified_nonce'),
            'syncInterval' => 3000,
            
            // Translation strings
            'i18n' => array(
                'saving' => __('Saving...', 'seo-repair-kit'),
                'saved' => __('Saved successfully!', 'seo-repair-kit'),
                'error' => __('Error saving', 'seo-repair-kit'),
                'synced' => __('Settings updated from other editor', 'seo-repair-kit'),
                'reset' => __('Reset to Default', 'seo-repair-kit'),
                'resetAll' => __('Reset All to Defaults', 'seo-repair-kit'),
                'saveChanges' => __('Save Changes', 'seo-repair-kit'),
                'editSnippet' => __('Edit Snippet', 'seo-repair-kit'),
                'preview' => __('Preview:', 'seo-repair-kit'),
                'seoTitle' => __('SEO Title', 'seo-repair-kit'),
                'metaDescription' => __('Meta Description', 'seo-repair-kit'),
                'canonicalUrl' => __('Canonical URL', 'seo-repair-kit'),
                'titleHelp' => __('Title shown in search results', 'seo-repair-kit'),
                'descHelp' => __('Description shown in search results', 'seo-repair-kit'),
                'canonicalHelp' => __('Preferred URL for this content', 'seo-repair-kit'),
                'viewAllTags' => __('View all tags →', 'seo-repair-kit'),
                'selectTag' => __('Select a Tag', 'seo-repair-kit'),
                'replaceDeleteTag' => __('Replace or Delete Tag', 'seo-repair-kit'),
                'searchTags' => __('Search for an item...', 'seo-repair-kit'),
                'deleteTag' => __('Delete Tag', 'seo-repair-kit'),
                'cancel' => __('Cancel', 'seo-repair-kit'),
                'noTitle' => __('(No title)', 'seo-repair-kit'),
                'noDescription' => __('(No description)', 'seo-repair-kit'),
                'autoSync' => __('Auto-sync enabled', 'seo-repair-kit'),
                'advanced' => __('Advanced', 'seo-repair-kit'),
                'titleDescription' => __('Title & Description', 'seo-repair-kit'),
                'useDefaultSettings' => __('Use Default Settings', 'seo-repair-kit'),
                'robotsMeta' => __('Robots Meta', 'seo-repair-kit'),
                'noIndex' => __('No Index', 'seo-repair-kit'),
                'noFollow' => __('No Follow', 'seo-repair-kit'),
                'noArchive' => __('No Archive', 'seo-repair-kit'),
                'noTranslate' => __('No Translate', 'seo-repair-kit'),
                'noImageIndex' => __('No Image Index', 'seo-repair-kit'),
                'noSnippet' => __('No Snippet', 'seo-repair-kit'),
                'noOdp' => __('No ODP', 'seo-repair-kit'),
                'maxSnippet' => __('Max Snippet', 'seo-repair-kit'),
                'maxVideoPreview' => __('Max Video Preview', 'seo-repair-kit'),
                'maxImagePreview' => __('Max Image Preview', 'seo-repair-kit'),
                'none' => __('None', 'seo-repair-kit'),
                'standard' => __('Standard', 'seo-repair-kit'),
                'large' => __('Large', 'seo-repair-kit'),
            )
        );
        
        // Localize for CORE script
        $data_key = ($editor_type === 'gutenberg') ? 'srkGutenbergData' : 'srkMetaboxData';
        wp_localize_script('srk-core', $data_key, $data);
        
        // Also localize for editor-specific scripts
        if ($editor_type === 'gutenberg') {
            wp_localize_script('srk-gutenberg-meta-panel', 'srkGutenbergConfig', array(
                'postId'           => $post_id,
                'isGutenberg'      => true,
                'allowedPostTypes' => self::get_allowed_seo_post_types(),
            ));
            $data['allowedPostTypes'] = self::get_allowed_seo_post_types();
        } else {
            wp_localize_script('srk-metabox-script', 'srkMetaboxConfig', array(
                'postId' => $post_id,
                'isGutenberg' => false
            ));
        }
    }
    
    /**
     * Save metabox data
     */
    public function save_metabox_data($post_id) {
        // Check nonce
        if (!isset($_POST['srk_metabox_nonce']) || !wp_verify_nonce($_POST['srk_metabox_nonce'], 'srk_metabox_save')) {
            return;
        }

        // Check autosave
        if (defined('DOING_AUTOSAVE') && DOING_AUTOSAVE) {
            return;
        }

        // Check permissions
        if (!current_user_can('edit_post', $post_id)) {
            return;
        }

        // Save meta fields
        $this->update_post_meta_fields($post_id, $_POST);
    }
    
    /**
     * Update post meta fields
     */
    private function update_post_meta_fields($post_id, $data) {
        $fields = array(
            'srk_meta_title' => '_srk_meta_title',
            'srk_meta_description' => '_srk_meta_description',
            'srk_canonical_url' => '_srk_canonical_url'
        );
        
        foreach ($fields as $field => $meta_key) {
            if (isset($data[$field])) {
                $value = sanitize_text_field($data[$field]);
                update_post_meta($post_id, $meta_key, $value);
            }
        }
        
        // Save advanced settings: when use_default=1 store only use_default_settings and show_meta_box (no robots_meta).
        $advanced_settings = null;
        if ( isset( $data['srk_advanced_settings'] ) && ! empty( $data['srk_advanced_settings'] ) ) {
            $advanced_settings = json_decode( stripslashes( $data['srk_advanced_settings'] ), true );
        }
        // Classic editor: build from srk_classic_* fields.
        if ( ( ! $advanced_settings || ! is_array( $advanced_settings ) ) && isset( $data['srk_classic_use_default'] ) ) {
            $use_default = ( $data['srk_classic_use_default'] === '1' || $data['srk_classic_use_default'] === true );
            $advanced_settings = array(
                'use_default_settings' => $use_default ? '1' : '0',
                'show_meta_box'        => '1',
            );
            if ( ! $use_default && isset( $data['srk_classic_robots'] ) && is_array( $data['srk_classic_robots'] ) ) {
                $raw = isset( $_POST['srk_classic_robots'] ) ? wp_unslash( $_POST['srk_classic_robots'] ) : array();
                $r   = array_map( function ( $v ) { return ( $v === '1' || $v === 1 ) ? '1' : '0'; }, wp_parse_args( is_array( $raw ) ? $raw : array(), array() ) );
                $advanced_settings['robots_meta'] = wp_parse_args( $r, $this->get_default_robots_structure() );
            }
        }
        if ( $advanced_settings && is_array( $advanced_settings ) ) {
            $advanced_settings = $this->prepare_advanced_settings_for_save( $advanced_settings );
            update_post_meta( $post_id, '_srk_advanced_settings', $advanced_settings );
        }

        update_post_meta( $post_id, '_srk_last_sync', current_time( 'timestamp' ) );
    }

    /**
     * When use_default_settings=1, store minimal data but PRESERVE content type sync info.
     * The key insight: use_default_settings=1 means "follow content type", not "no settings"
     *
     * @param array $advanced_settings Raw advanced settings.
     * @return array Sanitized for DB.
     */
    private function prepare_advanced_settings_for_save( $advanced_settings ) {
        // Normalize use_default_settings: '1' = follow content type (default), '0' = custom
        $use_default_raw = isset( $advanced_settings['use_default_settings'] ) ? $advanced_settings['use_default_settings'] : '1';
        $use_default = ( $use_default_raw === '0' || $use_default_raw === 0 || $use_default_raw === false ) ? '0' : '1';
        
        $show_meta_box = ! isset( $advanced_settings['show_meta_box'] ) || 
                        $advanced_settings['show_meta_box'] === '1' || 
                        $advanced_settings['show_meta_box'] === 1 || 
                        $advanced_settings['show_meta_box'] === true;
        
        // ALWAYS save full structure, but mark if using defaults
        $out = array(
            'use_default_settings' => $use_default,
            'show_meta_box'        => $show_meta_box ? '1' : '0',
        );
        
        // If using custom settings, save the robots_meta
        // If using defaults, still save content type robots as reference for display
        if ( isset( $advanced_settings['robots_meta'] ) && is_array( $advanced_settings['robots_meta'] ) ) {
            $robots = wp_parse_args( $advanced_settings['robots_meta'], $this->get_default_robots_structure() );
            $allowed = array( 'noindex', 'nofollow', 'noarchive', 'notranslate', 'noimageindex', 'nosnippet', 'noodp', 'max_snippet', 'max_video_preview', 'max_image_preview' );
            $out['robots_meta'] = array_intersect_key( array_merge( $this->get_default_robots_structure(), $robots ), array_flip( $allowed ) );
        } else {
            // Always include default robots structure for reference
            $out['robots_meta'] = $this->get_default_robots_structure();
        }
        
        return $out;
    }
    
    /**
     * AJAX: Get post data for preview
     */
    public function ajax_get_post_data() {
        if (!wp_verify_nonce($_POST['nonce'], 'srk_unified_nonce')) {
            wp_send_json_error('Security check failed');
        }

        $post_id = intval($_POST['post_id']);
        $post = get_post($post_id);

        if (!$post) {
            wp_send_json_error('Post not found');
        }

        $advanced_settings = get_post_meta($post_id, '_srk_advanced_settings', true);
        if (empty($advanced_settings)) {
            $advanced_settings = $this->get_default_advanced_settings();
        }

        $data = array(
            'post_title' => $post->post_title,
            'post_excerpt' => get_the_excerpt($post),
            'post_content' => wp_trim_words($post->post_content, 50, '...'),
            'permalink' => get_permalink($post_id),
            'post_date' => get_the_date('F j, Y', $post_id),
            'post_day' => get_the_date('d', $post_id),
            
            // Meta values
            'meta_title' => get_post_meta($post_id, '_srk_meta_title', true),
            'meta_description' => get_post_meta($post_id, '_srk_meta_description', true),
            'canonical_url' => get_post_meta($post_id, '_srk_canonical_url', true),
            'advanced_settings' => $advanced_settings,
            'last_sync' => get_post_meta($post_id, '_srk_last_sync', true)
        );

        wp_send_json_success($data);
    }
    /**
     * AJAX: Save meta data - FIXED VERSION (Handles Follow Mode)
     */
    public function ajax_save_meta_data() {
        if (!wp_verify_nonce($_POST['nonce'], 'srk_unified_nonce')) {
            wp_send_json_error('Security check failed');
        }

        $post_id = intval($_POST['post_id']);
        
        if (!current_user_can('edit_post', $post_id)) {
            wp_send_json_error('Permission denied');
        }

        // CHECK: Is this a Follow Mode save?
        $is_follow_mode = isset($_POST['follow_mode']) && $_POST['follow_mode'] === '1';
        
        if ($is_follow_mode) {
            // FOLLOW MODE: Delete local meta, only save marker
            delete_post_meta($post_id, '_srk_meta_title');
            delete_post_meta($post_id, '_srk_meta_description');
            delete_post_meta($post_id, '_srk_canonical_url');
            delete_post_meta($post_id, '_srk_template_title');
            delete_post_meta($post_id, '_srk_template_description');
            
            // Save only the marker
            $marker = array(
                'use_default_settings' => '1',
                'follow_mode' => '1',
                'show_meta_box' => '1'
            );
            update_post_meta($post_id, '_srk_advanced_settings', $marker);
            update_post_meta($post_id, '_srk_last_sync', current_time('timestamp'));
            
            wp_send_json_success([
                'message' => 'Following Content Type settings',
                'mode' => 'follow',
                'last_sync' => current_time('timestamp'),
                'post_id' => $post_id
            ]);
            return;
        }

        // NORMAL MODE: Save actual values
        $meta_title = isset($_POST['meta_title']) ? sanitize_text_field($_POST['meta_title']) : '';
        $meta_description = isset($_POST['meta_description']) ? sanitize_text_field($_POST['meta_description']) : '';
        $canonical_url = isset($_POST['canonical_url']) ? esc_url_raw($_POST['canonical_url']) : '';
        
        // Only save if values are not empty
        if (!empty($meta_title)) {
            update_post_meta($post_id, '_srk_meta_title', $meta_title);
        } else {
            delete_post_meta($post_id, '_srk_meta_title');
        }
        
        if (!empty($meta_description)) {
            update_post_meta($post_id, '_srk_meta_description', $meta_description);
        } else {
            delete_post_meta($post_id, '_srk_meta_description');
        }
        
        if (!empty($canonical_url)) {
            update_post_meta($post_id, '_srk_canonical_url', $canonical_url);
        } else {
            delete_post_meta($post_id, '_srk_canonical_url');
        }
        
        // Save templates if provided
        if (isset($_POST['template_title'])) {
            update_post_meta($post_id, '_srk_template_title', sanitize_text_field($_POST['template_title']));
        }
        if (isset($_POST['template_description'])) {
            update_post_meta($post_id, '_srk_template_description', sanitize_text_field($_POST['template_description']));
        }
        
        // Handle advanced settings
        if (isset($_POST['advanced_settings'])) {
            $advanced_settings = is_array($_POST['advanced_settings'])
                ? $_POST['advanced_settings']
                : json_decode(stripslashes($_POST['advanced_settings']), true);
                
            if ($advanced_settings && is_array($advanced_settings)) {
                $advanced_settings = $this->prepare_advanced_settings_for_save($advanced_settings);
                update_post_meta($post_id, '_srk_advanced_settings', $advanced_settings);
            }
        }
        
        $sync_time = current_time('timestamp');
        update_post_meta($post_id, '_srk_last_sync', $sync_time);
        
        wp_send_json_success([
            'message' => 'Data saved successfully',
            'last_sync' => $sync_time,
            'post_id' => $post_id
        ]);
    }
    /**
     * AJAX: True Reset - Delete all post meta and enter Follow Mode
     * This ensures post follows Content Type dynamically without local override
     */
    public function ajax_reset_to_content_type() {
        // Security check
        if (!wp_verify_nonce($_POST['nonce'], 'srk_unified_nonce')) {
            wp_send_json_error('Security check failed');
        }

        $post_id = intval($_POST['post_id']);
        
        // Check permissions
        if (!current_user_can('edit_post', $post_id)) {
            wp_send_json_error('Permission denied');
        }

        // STEP 1: Delete ALL SEO post meta (this is the key!)
        $meta_keys_to_delete = array(
            '_srk_meta_title',
            '_srk_meta_description', 
            '_srk_canonical_url',
            '_srk_template_title',
            '_srk_template_description',
            '_srk_advanced_settings',
            '_srk_last_sync'
        );
        
        foreach ($meta_keys_to_delete as $meta_key) {
            delete_post_meta($post_id, $meta_key);
        }

        // STEP 2: Create minimal "marker" meta to indicate Follow Mode
        // This stores ONLY use_default_settings='1', no actual values
        // This tells the system: "I exist but I'm empty, follow Content Type"
        $follow_mode_marker = array(
            'use_default_settings' => '1',
            'follow_mode' => '1',  // Special flag to indicate true follow
            'show_meta_box' => '1'
        );
        
        // Save minimal marker (not full data)
        update_post_meta($post_id, '_srk_advanced_settings', $follow_mode_marker);
        update_post_meta($post_id, '_srk_last_sync', current_time('timestamp'));

        // Clear caches
        clean_post_cache($post_id);
        wp_cache_delete($post_id, 'post_meta');

        wp_send_json_success(array(
            'message' => 'Reset to Content Type - Following dynamically',
            'mode' => 'follow',
            'last_sync' => current_time('timestamp'),
            'deleted_meta' => $meta_keys_to_delete
        ));
    }
    
    /**
     * AJAX: Save advanced settings
     */
    public function ajax_save_advanced_settings() {
        if (!wp_verify_nonce($_POST['nonce'], 'srk_unified_nonce')) {
            wp_send_json_error('Security check failed');
        }

        $post_id = intval($_POST['post_id']);
        
        // Check permissions
        if (!current_user_can('edit_post', $post_id)) {
            wp_send_json_error('Permission denied');
        }

        $advanced_settings = isset($_POST['advanced_settings']) ? $_POST['advanced_settings'] : [];
        
        // Sanitize robots meta - only allowed keys
        if (isset($advanced_settings['robots_meta'])) {
            $robots_meta = wp_parse_args(
                is_array($advanced_settings['robots_meta']) ? $advanced_settings['robots_meta'] : [],
                $this->get_default_robots_structure()
            );
            $allowed = ['noindex','nofollow','noarchive','notranslate','noimageindex','nosnippet','noodp','max_snippet','max_video_preview','max_image_preview'];
            $robots_meta = array_intersect_key($robots_meta, array_flip($allowed));
            $robots_meta = wp_parse_args($robots_meta, $this->get_default_robots_structure());
            $robots_meta['noindex'] = !empty($robots_meta['noindex']) && $robots_meta['noindex'] === '1' ? '1' : '0';
            $robots_meta['nofollow'] = !empty($robots_meta['nofollow']) && $robots_meta['nofollow'] === '1' ? '1' : '0';
            $robots_meta['noarchive'] = !empty($robots_meta['noarchive']) && $robots_meta['noarchive'] === '1' ? '1' : '0';
            $robots_meta['notranslate'] = !empty($robots_meta['notranslate']) && $robots_meta['notranslate'] === '1' ? '1' : '0';
            $robots_meta['noimageindex'] = !empty($robots_meta['noimageindex']) && $robots_meta['noimageindex'] === '1' ? '1' : '0';
            $robots_meta['nosnippet'] = !empty($robots_meta['nosnippet']) && $robots_meta['nosnippet'] === '1' ? '1' : '0';
            $robots_meta['noodp'] = !empty($robots_meta['noodp']) && $robots_meta['noodp'] === '1' ? '1' : '0';
            $robots_meta['max_snippet'] = isset($robots_meta['max_snippet']) ? intval($robots_meta['max_snippet']) : -1;
            $robots_meta['max_video_preview'] = isset($robots_meta['max_video_preview']) ? intval($robots_meta['max_video_preview']) : -1;
            $allowed_preview = ['none','standard','large'];
            $robots_meta['max_image_preview'] = isset($robots_meta['max_image_preview']) && in_array($robots_meta['max_image_preview'], $allowed_preview, true) ? $robots_meta['max_image_preview'] : 'large';
            if ($robots_meta['noimageindex'] === '1') $robots_meta['max_image_preview'] = '';
            if ($robots_meta['nosnippet'] === '1') $robots_meta['max_snippet'] = -1;
            $advanced_settings['robots_meta'] = $robots_meta;
        }
        
        $advanced_settings['use_default_settings'] = !empty($advanced_settings['use_default_settings']) ? '1' : '0';
        
        // Update meta
        update_post_meta($post_id, '_srk_advanced_settings', $advanced_settings);
        
        // Update sync timestamp
        update_post_meta($post_id, '_srk_last_sync', current_time('timestamp'));
        
        wp_send_json_success(array(
            'message' => 'Advanced settings saved successfully',
            'advanced_settings' => $advanced_settings,
            'last_sync' => current_time('timestamp')
        ));
    }
    
    /**
     * AJAX: Sync meta data
     */
    public function ajax_sync_meta_data() {
        if (!wp_verify_nonce($_POST['nonce'], 'srk_unified_nonce')) {
            wp_send_json_error('Security check failed');
        }
        
        $post_id = intval($_POST['post_id']);
        
        $advanced_settings = get_post_meta($post_id, '_srk_advanced_settings', true);
        if (empty($advanced_settings)) {
            $advanced_settings = $this->get_default_advanced_settings();
        }

        $data = array(
            'meta_title' => get_post_meta($post_id, '_srk_meta_title', true),
            'meta_description' => get_post_meta($post_id, '_srk_meta_description', true),
            'canonical_url' => get_post_meta($post_id, '_srk_canonical_url', true),
            'advanced_settings' => $advanced_settings,
            'last_sync' => get_post_meta($post_id, '_srk_last_sync', true),
            'timestamp' => current_time('timestamp')
        );
        
        wp_send_json_success($data);
    }
    
    /**
     * Register REST API routes
     */
    public function register_rest_routes() {
        register_rest_route('srk/v1', '/meta/(?P<id>\d+)', array(
            'methods' => 'GET',
            'callback' => array($this, 'rest_get_meta'),
            'permission_callback' => function() {
                return current_user_can('edit_posts');
            }
        ));
        
        register_rest_route('srk/v1', '/meta/(?P<id>\d+)', array(
            'methods' => 'POST',
            'callback' => array($this, 'rest_update_meta'),
            'permission_callback' => function() {
                return current_user_can('edit_posts');
            }
        ));
    }
    
    /**
     * REST: Get meta
     */
    public function rest_get_meta($request) {
        $post_id = $request['id'];
        
        $advanced_settings = get_post_meta($post_id, '_srk_advanced_settings', true);
        if (empty($advanced_settings)) {
            $advanced_settings = $this->get_default_advanced_settings();
        }

        return array(
            'meta_title' => get_post_meta($post_id, '_srk_meta_title', true),
            'meta_description' => get_post_meta($post_id, '_srk_meta_description', true),
            'canonical_url' => get_post_meta($post_id, '_srk_canonical_url', true),
            'advanced_settings' => $advanced_settings,
            'last_sync' => get_post_meta($post_id, '_srk_last_sync', true)
        );
    }
    
    /**
     * REST: Update meta
     */
    public function rest_update_meta($request) {
        $post_id = $request['id'];
        $params = $request->get_params();
        
        if (isset($params['meta_title'])) {
            update_post_meta($post_id, '_srk_meta_title', sanitize_text_field($params['meta_title']));
        }
        
        if (isset($params['meta_description'])) {
            update_post_meta($post_id, '_srk_meta_description', sanitize_text_field($params['meta_description']));
        }
        
        if (isset($params['canonical_url'])) {
            update_post_meta($post_id, '_srk_canonical_url', esc_url_raw($params['canonical_url']));
        }
        
        if ( isset( $params['advanced_settings'] ) && is_array( $params['advanced_settings'] ) ) {
            $sanitized = $this->sanitize_advanced_settings_meta( $params['advanced_settings'] );
            update_post_meta( $post_id, '_srk_advanced_settings', $sanitized );
        }

        update_post_meta( $post_id, '_srk_last_sync', current_time( 'timestamp' ) );

        return array(
            'success'    => true,
            'last_sync'  => get_post_meta( $post_id, '_srk_last_sync', true ),
        );
    }
    
    /**
     * Hook into meta updates for sync
     */
    public function on_meta_update($meta_id, $post_id, $meta_key, $meta_value) {
        // Only listen to our meta keys
        if (!in_array($meta_key, array('_srk_meta_title', '_srk_meta_description', '_srk_canonical_url', '_srk_advanced_settings'))) {
            return;
        }
        
        // Update sync timestamp
        update_post_meta($post_id, '_srk_last_sync', current_time('timestamp'));
    }
    
    /**
     * Register post meta fields with full REST schema for all allowed post types.
     */
    public function register_post_meta() {
        $post_types = self::get_allowed_seo_post_types();

        $robots_meta_schema = array(
            'type'       => 'object',
            'properties' => array(
                'noindex'           => array( 'type' => 'string', 'enum' => array( '0', '1' ) ),
                'nofollow'          => array( 'type' => 'string', 'enum' => array( '0', '1' ) ),
                'noarchive'         => array( 'type' => 'string', 'enum' => array( '0', '1' ) ),
                'notranslate'       => array( 'type' => 'string', 'enum' => array( '0', '1' ) ),
                'noimageindex'      => array( 'type' => 'string', 'enum' => array( '0', '1' ) ),
                'nosnippet'         => array( 'type' => 'string', 'enum' => array( '0', '1' ) ),
                'noodp'             => array( 'type' => 'string', 'enum' => array( '0', '1' ) ),
                'max_snippet'       => array( 'type' => 'integer', 'default' => -1 ),
                'max_video_preview' => array( 'type' => 'integer', 'default' => -1 ),
                'max_image_preview' => array( 'type' => 'string', 'enum' => array( 'none', 'standard', 'large' ), 'default' => 'large' ),
            ),
        );

        $advanced_settings_schema = array(
            'type'       => 'object',
            'properties' => array(
                'use_default_settings' => array( 'type' => 'boolean', 'default' => true ),
                'show_meta_box'        => array( 'type' => 'boolean', 'default' => true ),
                'robots_meta'          => $robots_meta_schema,
            ),
        );

        foreach ( $post_types as $post_type ) {
            register_post_meta(
                $post_type,
                '_srk_advanced_settings',
                array(
                    'type'              => 'object',
                    'single'            => true,
                    'show_in_rest'      => array( 'schema' => $advanced_settings_schema ),
                    'sanitize_callback' => array( $this, 'sanitize_advanced_settings_meta' ),
                    'auth_callback'     => function () {
                        return current_user_can( 'edit_posts' );
                    },
                )
            );
            register_post_meta(
                $post_type,
                '_srk_meta_title',
                array(
                    'type'              => 'string',
                    'single'            => true,
                    'show_in_rest'      => true,
                    'sanitize_callback' => 'sanitize_text_field',
                    'auth_callback'     => function () {
                        return current_user_can( 'edit_posts' );
                    },
                )
            );
            register_post_meta(
                $post_type,
                '_srk_meta_description',
                array(
                    'type'              => 'string',
                    'single'            => true,
                    'show_in_rest'      => true,
                    'sanitize_callback' => 'sanitize_text_field',
                    'auth_callback'     => function () {
                        return current_user_can( 'edit_posts' );
                    },
                )
            );
            register_post_meta(
                $post_type,
                '_srk_canonical_url',
                array(
                    'type'              => 'string',
                    'single'            => true,
                    'show_in_rest'      => true,
                    'sanitize_callback' => 'esc_url_raw',
                    'auth_callback'     => function () {
                        return current_user_can( 'edit_posts' );
                    },
                )
            );
            register_post_meta(
                $post_type,
                '_srk_last_sync',
                array(
                    'type'          => 'number',
                    'single'        => true,
                    'show_in_rest'  => true,
                    'auth_callback' => function () {
                        return current_user_can( 'edit_posts' );
                    },
                )
            );
        }
    }

    /**
     * Sanitize _srk_advanced_settings for REST and save: only allowed keys; when use_default=1 do not store robots_meta.
     *
     * @param mixed $meta_value Incoming value.
     * @return array Sanitized object.
     */
    public function sanitize_advanced_settings_meta( $meta_value ) {
        if ( ! is_array( $meta_value ) ) {
            return array( 'use_default_settings' => '1', 'show_meta_box' => '1' );
        }
        $use_default = isset( $meta_value['use_default_settings'] ) && ( $meta_value['use_default_settings'] === '1' || $meta_value['use_default_settings'] === true );
        $show_meta_box = ! isset( $meta_value['show_meta_box'] ) || $meta_value['show_meta_box'] === '1' || $meta_value['show_meta_box'] === true;
        $out = array(
            'use_default_settings' => $use_default ? '1' : '0',
            'show_meta_box'        => $show_meta_box ? '1' : '0',
        );
        if ( $use_default ) {
            return $out;
        }
        $allowed = array( 'noindex', 'nofollow', 'noarchive', 'notranslate', 'noimageindex', 'nosnippet', 'noodp', 'max_snippet', 'max_video_preview', 'max_image_preview' );
        $robots = array();
        if ( isset( $meta_value['robots_meta'] ) && is_array( $meta_value['robots_meta'] ) ) {
            foreach ( $allowed as $key ) {
                if ( ! array_key_exists( $key, $meta_value['robots_meta'] ) ) {
                    continue;
                }
                $v = $meta_value['robots_meta'][ $key ];
                if ( in_array( $key, array( 'max_snippet', 'max_video_preview' ), true ) ) {
                    $robots[ $key ] = (int) $v;
                } elseif ( $key === 'max_image_preview' ) {
                    $robots[ $key ] = in_array( $v, array( 'none', 'standard', 'large' ), true ) ? $v : 'large';
                } else {
                    $robots[ $key ] = ( $v === '1' || $v === 1 || $v === true ) ? '1' : '0';
                }
            }
        }
        $robots = wp_parse_args( $robots, $this->get_default_robots_structure() );
        $out['robots_meta'] = $robots;
        return $out;
    }
    /**
     * Apply content type settings to new posts - FIXED VERSION
     */
    public function apply_content_type_settings($post_id, $post, $update) {
        // Only apply to new posts
        if ($update) {
            return;
        }
        
        $post_type = $post->post_type;
        $content_type_settings = get_option('srk_meta_content_types_settings', []);
        
        if (isset($content_type_settings[$post_type])) {
            $settings = $content_type_settings[$post_type];
            
            // Apply title and description templates from content type
            if (!empty($settings['title'])) {
                update_post_meta($post_id, '_srk_meta_title', $settings['title']);
            }
            
            if (!empty($settings['desc'])) {
                update_post_meta($post_id, '_srk_meta_description', $settings['desc']);
            }
            
            // CRITICAL: Apply advanced settings with use_default_settings = '1'
            // This means: follow content type settings (sync mode)
            if (isset($settings['advanced'])) {
                $advanced = $settings['advanced'];
                
                // Default to using content type settings (ON/sync mode)
                $use_default = !isset($advanced['use_default_settings']) || 
                    $advanced['use_default_settings'] === '1' || 
                    $advanced['use_default_settings'] === true;
                
                // Build advanced settings
                $advanced_settings = array(
                    'use_default_settings' => $use_default ? '1' : '0',
                    'show_meta_box'        => isset($advanced['show_meta_box']) ? $advanced['show_meta_box'] : '1',
                );
                
                // If custom settings, include robots_meta
                if (!$use_default && isset($advanced['robots_meta']) && is_array($advanced['robots_meta'])) {
                    $advanced_settings['robots_meta'] = wp_parse_args($advanced['robots_meta'], $this->get_default_robots_structure());
                } else {
                    // Even in default mode, save content type robots as reference
                    $content_type_robots = isset($advanced['robots_meta']) ? $advanced['robots_meta'] : $this->get_default_robots_structure();
                    $advanced_settings['robots_meta'] = $content_type_robots;
                }
                
                update_post_meta($post_id, '_srk_advanced_settings', $advanced_settings);
            } else {
                // No advanced settings defined - default to following content type
                update_post_meta($post_id, '_srk_advanced_settings', array(
                    'use_default_settings' => '1', // ON - follow content type
                    'show_meta_box'        => '1',
                    'robots_meta'          => $this->get_default_robots_structure()
                ));
            }
            
            // Set initial sync timestamp
            update_post_meta($post_id, '_srk_last_sync', current_time('timestamp'));
        }
    }
    /**
     * Force custom-fields support for all allowed SEO post types.
     */
    public function force_custom_fields_support() {
        $post_types = self::get_allowed_seo_post_types();
        foreach ( $post_types as $post_type ) {
            if ( ! post_type_supports( $post_type, 'custom-fields' ) ) {
                add_post_type_support( $post_type, 'custom-fields' );
            }
        }
    }
}

// Initialize the integration
SRK_Gutenberg_Integration::get_instance();

// Force custom-fields support for all allowed SEO post types
add_action( 'init', function () {
    $post_types = SRK_Gutenberg_Integration::get_allowed_seo_post_types();
    foreach ( $post_types as $post_type ) {
        if ( ! post_type_supports( $post_type, 'custom-fields' ) ) {
            add_post_type_support( $post_type, 'custom-fields' );
        }
    }
}, 99 );
