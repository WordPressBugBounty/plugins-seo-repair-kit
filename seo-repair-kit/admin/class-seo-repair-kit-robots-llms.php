<?php
if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/**
 * Bot Manager (Robots.txt & LLMs.txt files)
 * 
 * Handles the admin interface for managing robots.txt and llms.txt files.
 * 
 * @since    2.1.1
 * @package  Seo_Repair_Kit
 */
class SeoRepairKit_Robots_LLMs {

    /**
     * The version of this plugin.
     *
     * @since    2.1.1
     * @access   private
     * @var      string    $version    The current version of this plugin.
     */
    private $version;

    /**
     * Initialize the class and set its properties.
     *
     * @since    2.1.1
     */
    public function __construct() {
        $this->version = defined( 'SEO_REPAIR_KIT_VERSION' ) ? SEO_REPAIR_KIT_VERSION : '2.1.2';
        
        // Enqueue scripts and styles
        add_action( 'admin_enqueue_scripts', array( $this, 'enqueue_scripts' ) );
        
        // Handle AJAX requests
        add_action( 'wp_ajax_srk_save_robots_txt', array( $this, 'ajax_save_robots_txt' ) );
        add_action( 'wp_ajax_srk_validate_robots_txt', array( $this, 'ajax_validate_robots_txt' ) );
        add_action( 'wp_ajax_srk_delete_robots_txt', array( $this, 'ajax_delete_robots_txt' ) );
        add_action( 'wp_ajax_srk_apply_enhanced_robots', array( $this, 'ajax_apply_enhanced_robots' ) );
        add_action( 'wp_ajax_srk_generate_llms_txt', array( $this, 'ajax_generate_llms_txt' ) );
        add_action( 'wp_ajax_srk_save_llms_txt', array( $this, 'ajax_save_llms_txt' ) );
        add_action( 'wp_ajax_srk_delete_llms_txt', array( $this, 'ajax_delete_llms_txt' ) );
        add_action( 'wp_ajax_srk_save_llms_settings', array( $this, 'ajax_save_llms_settings' ) );
        add_action( 'wp_ajax_srk_reset_llms_options', array( $this, 'ajax_reset_llms_options' ) );
        add_action( 'wp_ajax_srk_get_content_list', array( $this, 'ajax_get_content_list' ) );
    }

    /**
     * Enqueue scripts and styles for the admin page.
     *
     * @since    2.1.1
     */
    public function enqueue_scripts( $hook ) {
        // Only load on our page
        if ( empty( $_GET['page'] ) || 'seo-repair-kit-robots-llms' !== sanitize_key( $_GET['page'] ) ) {
            return;
        }

        // Enqueue CSS
        wp_enqueue_style(
            'srk-robots-llms-style',
            plugin_dir_url( __FILE__ ) . 'css/seo-repair-kit-robots-llms.css',
            array(),
            $this->version
        );

        // Enqueue JavaScript
        wp_enqueue_script(
            'srk-robots-llms-script',
            plugin_dir_url( __FILE__ ) . 'js/seo-repair-kit-robots-llms.js',
            array( 'jquery' ),
            $this->version,
            true
        );

        // Localize script with data
        wp_localize_script(
            'srk-robots-llms-script',
            'srkRobotsLLMs',
            array(
                'ajaxUrl' => admin_url( 'admin-ajax.php' ),
                'nonce'   => wp_create_nonce( 'srk_robots_llms_nonce' ),
                'strings' => array(
                    'saving'     => esc_html__( 'Saving...', 'seo-repair-kit' ),
                    'saved'      => esc_html__( 'Saved successfully!', 'seo-repair-kit' ),
                    'error'      => esc_html__( 'An error occurred.', 'seo-repair-kit' ),
                    'valid'      => esc_html__( 'Valid', 'seo-repair-kit' ),
                    'invalid'    => esc_html__( 'Invalid', 'seo-repair-kit' ),
                    'validating' => esc_html__( 'Validating...', 'seo-repair-kit' ),
                ),
            )
        );
    }

    /**
     * Render the Robots & LLMs admin page.
     *
     * @since    2.1.1
     */
    public function render_admin_page() {
        // Check user capabilities
        if ( ! current_user_can( 'manage_options' ) ) {
            wp_die( esc_html__( 'You do not have permission to access this page.', 'seo-repair-kit' ) );
        }

        // Get current content
        $robots_content = get_option( 'srk_robots_txt_content', '' );
        $llms_content   = get_option( 'srk_llms_txt_content', '' );
        
        // Get last updated timestamp for robots.txt
        $robots_last_updated = get_option( 'srk_robots_txt_last_updated', '' );
        $robots_last_updated_display = '';
        if ( $robots_last_updated ) {
            $robots_last_updated_display = human_time_diff( strtotime( $robots_last_updated ), current_time( 'timestamp' ) ) . ' ' . __( 'ago', 'seo-repair-kit' );
        }
        
        // Get last updated timestamp for LLMs.txt
        $llms_last_updated = get_option( 'srk_llms_txt_last_updated', '' );
        $llms_last_updated_display = '';
        if ( $llms_last_updated ) {
            $llms_last_updated_display = human_time_diff( strtotime( $llms_last_updated ), current_time( 'timestamp' ) ) . ' ' . __( 'ago', 'seo-repair-kit' );
        }
    
        // If robots.txt is empty, show WordPress recommended default in editor
        // Always show full recommended version in editor, regardless of blog_public setting
        if ( empty( $robots_content ) ) {
            $robots_content = $this->get_recommended_robots_txt();
        }

        // Get content for LLMs.txt generator
        $posts      = $this->get_public_posts();
        $pages      = $this->get_public_pages();
        $categories = $this->get_public_categories();
        
        // Get all public post types for advanced generator
        $all_post_types = get_post_types( array( 'public' => true ), 'objects' );
        
        // Get all public taxonomies for advanced generator
        $all_taxonomies = get_taxonomies( array( 'public' => true ), 'objects' );
        
        // Get saved LLMs.txt generator settings
        $llms_settings = get_option( 'srk_llms_generator_settings', array() );
        $selected_post_types = isset( $llms_settings['post_types'] ) ? $llms_settings['post_types'] : array( 'post', 'page' );
        $selected_taxonomies = isset( $llms_settings['taxonomies'] ) ? $llms_settings['taxonomies'] : array();
        $posts_limit = isset( $llms_settings['posts_limit'] ) ? absint( $llms_settings['posts_limit'] ) : 50;
        $additional_content = isset( $llms_settings['additional_content'] ) ? $llms_settings['additional_content'] : '';
        
        // Get saved AI bot access control settings
        // Important: Check if the key exists to distinguish between "never saved" vs "saved with empty array"
        // If key exists (even if empty), respect user's choice. Only default to all bots if key doesn't exist.
        if ( array_key_exists( 'allowed_bots', $llms_settings ) ) {
            // Settings have been saved before - respect the saved value (even if empty array)
            $allowed_bots = is_array( $llms_settings['allowed_bots'] ) ? $llms_settings['allowed_bots'] : array();
        } else {
            // Settings never saved - default to all popular bots
            $allowed_bots = array_keys( $this->get_popular_ai_bots() );
        }

        ?>
        <div class="wrap srk-wrap srk-robots-llms-wrap">
            <!-- Hero Section -->
            <div class="srk-hero">
                <div class="srk-hero-content">
                    <div class="srk-hero-icon">
                        <span class="dashicons dashicons-admin-tools"></span>
                    </div>
                    <div class="srk-hero-text">
                        <h2><?php esc_html_e( 'Bot Manager', 'seo-repair-kit' ); ?></h2>
                        <p><?php esc_html_e( 'Control how search engines and AI crawlers access your site with robots.txt and help AI models discover your content with llms.txt.', 'seo-repair-kit' ); ?></p>
                        <div class="srk-hero-features">
                            <span class="srk-hero-badge">
                                <span class="dashicons dashicons-search"></span>
                                <?php esc_html_e( 'Search Engine Control', 'seo-repair-kit' ); ?>
                            </span>
                            <span class="srk-hero-badge">
                                <span class="dashicons dashicons-admin-generic"></span>
                                <?php esc_html_e( 'AI Content Discovery', 'seo-repair-kit' ); ?>
                            </span>
                        </div>
                    </div>
                </div>
            </div>

            <div class="srk-tabs-container">
                <nav class="srk-tabs-nav">
                    <button class="srk-tab-button active" data-tab="llms">
                        <?php esc_html_e( 'LLMs.txt', 'seo-repair-kit' ); ?>
                    </button>
                    <button class="srk-tab-button" data-tab="robots">
                        <?php esc_html_e( 'Robots.txt', 'seo-repair-kit' ); ?>
                    </button>
                </nav>

                <!-- LLMs.txt Tab -->
                <div class="srk-tab-content active" id="llms-tab">
                    <div class="srk-schema-card">
                        <div class="srk-schema-card-header">
                            <div>
                                <h2 class="srk-section-title"><?php esc_html_e( 'LLMs.txt Generator', 'seo-repair-kit' ); ?></h2>
                                <p class="srk-section-description">
                                    <?php esc_html_e( 'Generate an LLMs.txt file to help AI models discover and cite your best content.', 'seo-repair-kit' ); ?>
                                </p>
                            </div>
                        </div>
                        <div class="srk-schema-selection">
                            <!-- LLMs.txt URL Banner -->
                            <div class="srk-llms-url-banner">
                                <p>
                                    <span class="dashicons dashicons-admin-links"></span>
                                    <?php esc_html_e( 'Your llms.txt file is available at:', 'seo-repair-kit' ); ?>
                                    <a href="<?php echo esc_url( home_url( '/llms.txt' ) ); ?>" target="_blank" rel="noopener">
                                        <?php echo esc_url( home_url( '/llms.txt' ) ); ?>
                                    </a>
                                </p>
                            </div>

                            <!-- Advanced Generator Options -->
                            <div class="srk-llms-advanced-generator">
                                <!-- AI Bot Access Control -->
                                <div class="srk-llms-option-section">
                                    <div class="srk-option-header">
                                        <h3><?php esc_html_e( 'AI Bot Access Control', 'seo-repair-kit' ); ?></h3>
                                        <button type="button" class="srk-btn srk-btn-small" id="srk-toggle-ai-bots">
                                            <?php esc_html_e( 'Select / Deselect All', 'seo-repair-kit' ); ?>
                                        </button>
                                    </div>
                                    <p class="srk-help-text" style="margin-bottom: 15px;">
                                        <?php esc_html_e( 'Control which AI bots can access your LLMs.txt file. Unchecked bots will be blocked via server-level checks and robots.txt rules.', 'seo-repair-kit' ); ?>
                                    </p>
                                    <div class="srk-checkbox-grid">
                                        <?php 
                                        $popular_bots = $this->get_popular_ai_bots();
                                        foreach ( $popular_bots as $bot_key => $bot_info ) : 
                                        ?>
                                            <label class="srk-checkbox-item">
                                                <input 
                                                    type="checkbox" 
                                                    name="srk_allowed_bots[]" 
                                                    value="<?php echo esc_attr( $bot_key ); ?>"
                                                    class="srk-ai-bot-checkbox"
                                                    <?php checked( in_array( $bot_key, $allowed_bots, true ) ); ?>
                                                >
                                                <span class="srk-checkbox-custom"></span>
                                                <span class="srk-checkbox-label">
                                                    <strong><?php echo esc_html( $bot_info['name'] ); ?></strong>
                                                    <?php if ( ! empty( $bot_info['description'] ) ) : ?>
                                                        <span class="srk-bot-description"><?php echo esc_html( $bot_info['description'] ); ?></span>
                                                    <?php endif; ?>
                                                </span>
                                            </label>
                                        <?php endforeach; ?>
                                    </div>
                                    <p class="srk-help-text">
                                        <?php esc_html_e( 'Note: Blocked bots will receive a 403 Forbidden response when accessing /llms.txt, and blocking rules will be added to robots.txt. Bots not listed here will be allowed by default.', 'seo-repair-kit' ); ?>
                                    </p>
                                </div>

                                <!-- Select Post Types -->
                                <div class="srk-llms-option-section">
                                    <div class="srk-option-header">
                                        <h3><?php esc_html_e( 'Select Post Types', 'seo-repair-kit' ); ?></h3>
                                        <button type="button" class="srk-btn srk-btn-small" id="srk-toggle-post-types">
                                            <?php esc_html_e( 'Select / Deselect All', 'seo-repair-kit' ); ?>
                                        </button>
                                    </div>
                                    <div class="srk-checkbox-grid">
                                        <?php foreach ( $all_post_types as $post_type ) : ?>
                                            <label class="srk-checkbox-item">
                                                <input 
                                                    type="checkbox" 
                                                    name="srk_post_types[]" 
                                                    value="<?php echo esc_attr( $post_type->name ); ?>"
                                                    class="srk-post-type-checkbox"
                                                    <?php checked( in_array( $post_type->name, $selected_post_types, true ) ); ?>
                                                >
                                                <span class="srk-checkbox-custom"></span>
                                                <span class="srk-checkbox-label">
                                                    <?php echo esc_html( $post_type->label ); ?>
                                                </span>
                                            </label>
                                        <?php endforeach; ?>
                                    </div>
                                    <p class="srk-help-text">
                                        <?php esc_html_e( 'Select the post types to be included in the llms.txt file.', 'seo-repair-kit' ); ?>
                                    </p>
                                </div>

                                <!-- Select Taxonomies -->
                                <div class="srk-llms-option-section">
                                    <div class="srk-option-header">
                                        <h3><?php esc_html_e( 'Select Taxonomies', 'seo-repair-kit' ); ?></h3>
                                        <button type="button" class="srk-btn srk-btn-small" id="srk-toggle-taxonomies">
                                            <?php esc_html_e( 'Select / Deselect All', 'seo-repair-kit' ); ?>
                                        </button>
                                    </div>
                                    <div class="srk-checkbox-grid">
                                        <?php foreach ( $all_taxonomies as $taxonomy ) : ?>
                                            <label class="srk-checkbox-item">
                                                <input 
                                                    type="checkbox" 
                                                    name="srk_taxonomies[]" 
                                                    value="<?php echo esc_attr( $taxonomy->name ); ?>"
                                                    class="srk-taxonomy-checkbox"
                                                    <?php checked( in_array( $taxonomy->name, $selected_taxonomies, true ) ); ?>
                                                >
                                                <span class="srk-checkbox-custom"></span>
                                                <span class="srk-checkbox-label">
                                                    <?php echo esc_html( $taxonomy->label ); ?>
                                                </span>
                                            </label>
                                        <?php endforeach; ?>
                                    </div>
                                    <p class="srk-help-text">
                                        <?php esc_html_e( 'Select the taxonomies to be included in the llms.txt file.', 'seo-repair-kit' ); ?>
                                    </p>
                                </div>

                                <!-- Posts/Terms Limit -->
                                <div class="srk-llms-option-section">
                                    <div class="srk-option-header">
                                        <h3><?php esc_html_e( 'Posts/Terms Limit', 'seo-repair-kit' ); ?></h3>
                                    </div>
                                    <input 
                                        type="number" 
                                        id="srk-posts-limit" 
                                        name="srk_posts_limit" 
                                        value="<?php echo esc_attr( $posts_limit ); ?>"
                                        min="1" 
                                        max="1000" 
                                        class="srk-number-input"
                                    >
                                    <p class="srk-help-text">
                                        <?php esc_html_e( 'Maximum number of links to include for each post type.', 'seo-repair-kit' ); ?>
                                    </p>
                                </div>

                                <!-- Additional Content -->
                                <div class="srk-llms-option-section">
                                    <div class="srk-option-header">
                                        <h3><?php esc_html_e( 'Additional Content', 'seo-repair-kit' ); ?></h3>
                                    </div>
                                    <textarea 
                                        id="srk-additional-content" 
                                        name="srk_additional_content" 
                                        class="srk-textarea-input" 
                                        rows="5"
                                        placeholder="<?php esc_attr_e( 'Add any extra text or links you\'d like to include in your llms.txt file.', 'seo-repair-kit' ); ?>"
                                    ><?php echo esc_textarea( $additional_content ); ?></textarea>
                                    <p class="srk-help-text">
                                        <?php esc_html_e( 'Add any extra text or links you\'d like to include in your llms.txt file.', 'seo-repair-kit' ); ?>
                                    </p>
                                </div>

                                <!-- Action Buttons -->
                                <div class="srk-llms-generator-actions">
                                    <button type="button" class="srk-btn srk-btn-primary" id="srk-generate-llms">
                                        <span class="dashicons dashicons-update"></span>
                                        <?php esc_html_e( 'Generate LLMs.txt', 'seo-repair-kit' ); ?>
                                    </button>
                                    <button type="button" class="srk-btn srk-btn-secondary" id="srk-save-llms-settings">
                                        <span class="dashicons dashicons-admin-generic"></span>
                                        <?php esc_html_e( 'Save Settings', 'seo-repair-kit' ); ?>
                                    </button>
                                    <button type="button" class="srk-btn srk-btn-secondary" id="srk-reset-llms-options">
                                        <span class="dashicons dashicons-undo"></span>
                                        <?php esc_html_e( 'Reset Options', 'seo-repair-kit' ); ?>
                                    </button>
                                </div>
                            </div>

                            <!-- Current LLMs.txt Configuration Display -->
                            <?php 
                            $has_custom_llms = ! empty( trim( get_option( 'srk_llms_txt_content', '' ) ) );
                            if ( $has_custom_llms ) : 
                                $current_llms = get_option( 'srk_llms_txt_content', '' );
                            ?>
                            <div class="srk-current-llms-section">
                                <div class="srk-current-llms-message">
                                    <div class="srk-notice srk-notice-info">
                                        <span class="dashicons dashicons-yes-alt"></span>
                                        <p>
                                            <?php esc_html_e( 'You have a custom LLMs.txt configured. The content below is what AI models will see when they discover your site.', 'seo-repair-kit' ); ?>
                                        </p>
                                    </div>
                                </div>
                                <div class="srk-current-llms-content">
                                    <div class="srk-llms-preview-header">
                                        <h4><?php esc_html_e( 'Current Configuration', 'seo-repair-kit' ); ?></h4>
                                        <?php if ( $llms_last_updated_display ) : ?>
                                        <div class="srk-last-updated">
                                            <span class="dashicons dashicons-clock"></span>
                                            <span><?php esc_html_e( 'Last updated:', 'seo-repair-kit' ); ?> <strong><?php echo esc_html( $llms_last_updated_display ); ?></strong></span>
                                        </div>
                                        <?php endif; ?>
                                    </div>
                                    <pre class="srk-llms-preview"><code><?php echo esc_html( $current_llms ); ?></code></pre>
                                </div>
                            </div>
                            <?php endif; ?>

                            <!-- LLMs.txt Editor -->
                            <div class="srk-llms-editor-section">
                                <h3><?php esc_html_e( 'LLMs.txt Content', 'seo-repair-kit' ); ?></h3>
                                <textarea 
                                    id="srk-llms-editor" 
                                    class="srk-text-editor" 
                                    rows="20"
                                    placeholder="<?php esc_attr_e( '# LLMs.txt for ' . get_bloginfo( 'name' ) . '&#10;# This file helps AI models understand our content&#10;&#10;## Primary Content', 'seo-repair-kit' ); ?>"
                                ><?php echo esc_textarea( $llms_content ); ?></textarea>
                                <div class="srk-editor-actions">
                                    <button type="button" class="srk-btn srk-btn-primary" id="srk-save-llms">
                                        <span class="dashicons dashicons-yes"></span>
                                        <?php esc_html_e( 'Save LLMs.txt', 'seo-repair-kit' ); ?>
                                    </button>
                                    <button type="button" class="srk-btn srk-btn-secondary" id="srk-preview-llms">
                                        <span class="dashicons dashicons-visibility"></span>
                                        <?php esc_html_e( 'Preview', 'seo-repair-kit' ); ?>
                                    </button>
                                    <button type="button" class="srk-btn srk-btn-danger" id="srk-delete-llms">
                                        <span class="dashicons dashicons-trash"></span>
                                        <?php esc_html_e( 'Delete LLMs.txt', 'seo-repair-kit' ); ?>
                                    </button>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>

                <!-- Robots.txt Tab -->
                <div class="srk-tab-content" id="robots-tab">
                    <div class="srk-schema-card">
                        <div class="srk-schema-card-header">
                            <div>
                                <h2 class="srk-section-title"><?php esc_html_e( 'Robots.txt Editor', 'seo-repair-kit' ); ?></h2>
                                <p class="srk-section-description">
                                    <?php esc_html_e( 'Edit your robots.txt file to control how search engines crawl your site.', 'seo-repair-kit' ); ?>
                                </p>
                            </div>
                        </div>
                        <div class="srk-schema-selection">
                            <!-- Robots.txt URL Banner -->
                            <div class="srk-llms-url-banner">
                                <p>
                                    <span class="dashicons dashicons-admin-links"></span>
                                    <?php esc_html_e( 'Your robots.txt file is available at:', 'seo-repair-kit' ); ?>
                                    <a href="<?php echo esc_url( home_url( '/robots.txt' ) ); ?>" target="_blank" rel="noopener">
                                        <?php echo esc_url( home_url( '/robots.txt' ) ); ?>
                                    </a>
                                </p>
                            </div>

                            <!-- Current Robots.txt Configuration Display -->
                            <?php 
                            $has_custom_robots = ! empty( trim( get_option( 'srk_robots_txt_content', '' ) ) );
                            if ( $has_custom_robots ) : 
                                $current_robots = get_option( 'srk_robots_txt_content', '' );
                            ?>
                            <div class="srk-current-robots-section">
                                <div class="srk-current-robots-message">
                                    <div class="srk-notice srk-notice-info">
                                        <span class="dashicons dashicons-yes-alt"></span>
                                        <p>
                                            <?php esc_html_e( 'You have a custom robots.txt configured. The content below is what search engines will see when they crawl your site.', 'seo-repair-kit' ); ?>
                                        </p>
                                    </div>
                                </div>
                                <div class="srk-current-robots-content">
                                    <div class="srk-robots-preview-header">
                                        <h4><?php esc_html_e( 'Current Configuration', 'seo-repair-kit' ); ?></h4>
                                        <?php if ( $robots_last_updated_display ) : ?>
                                        <div class="srk-last-updated">
                                            <span class="dashicons dashicons-clock"></span>
                                            <span><?php esc_html_e( 'Last updated:', 'seo-repair-kit' ); ?> <strong><?php echo esc_html( $robots_last_updated_display ); ?></strong></span>
                                        </div>
                                        <?php endif; ?>
                                    </div>
                                    <pre class="srk-robots-preview"><code><?php echo esc_html( $current_robots ); ?></code></pre>
                                </div>
                            </div>
                            <?php endif; ?>

                            <div class="srk-editor-container">
                                <div class="srk-editor-toolbar">
                                    <button type="button" class="srk-btn srk-btn-primary" id="srk-save-robots">
                                        <span class="dashicons dashicons-yes"></span>
                                        <?php esc_html_e( 'Save Robots.txt', 'seo-repair-kit' ); ?>
                                    </button>
                                    <button type="button" class="srk-btn srk-btn-secondary" id="srk-validate-robots">
                                        <span class="dashicons dashicons-search"></span>
                                        <?php esc_html_e( 'Validate', 'seo-repair-kit' ); ?>
                                    </button>
                                    <button type="button" class="srk-btn srk-btn-secondary" id="srk-reset-robots">
                                        <span class="dashicons dashicons-undo"></span>
                                        <?php esc_html_e( 'Reset to Default', 'seo-repair-kit' ); ?>
                                    </button>
                                    <button type="button" class="srk-btn srk-btn-secondary" id="srk-enhanced-robots">
                                        <span class="dashicons dashicons-shield"></span>
                                        <?php esc_html_e( 'Apply Enhanced', 'seo-repair-kit' ); ?>
                                    </button>
                                    <button type="button" class="srk-btn srk-btn-danger" id="srk-delete-robots">
                                        <span class="dashicons dashicons-trash"></span>
                                        <?php esc_html_e( 'Delete Custom Robots.txt', 'seo-repair-kit' ); ?>
                                    </button>
                                </div>
                                <div class="srk-validation-status" id="srk-robots-validation-status"></div>
                                <textarea 
                                    id="srk-robots-editor" 
                                    class="srk-text-editor" 
                                    rows="20" 
                                    placeholder="<?php esc_attr_e( 'User-agent: *&#10;Disallow: /wp-admin/&#10;Allow: /wp-admin/admin-ajax.php&#10;Disallow: /wp-includes/&#10;&#10;Sitemap: ' . esc_url( home_url( '/sitemap.xml' ) ), 'seo-repair-kit' ); ?>"
                                ><?php echo esc_textarea( $robots_content ); ?></textarea>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
        </div>
        <?php
    }

    /**
     * Get WordPress recommended robots.txt content for editor.
     * 
     * Always returns the full WordPress recommended robots.txt regardless of blog_public setting.
     * This is used in the admin editor to show users the recommended default.
     *
     * @since    2.1.1
     * @return   string
     */
    private function get_recommended_robots_txt() {
        // WordPress recommended default robots.txt
        $default = "User-agent: *\n";
        $default .= "Disallow: /wp-admin/\n";
        $default .= "Allow: /wp-admin/admin-ajax.php\n";
        $default .= "Disallow: /wp-includes/\n";
        
        // Get all sitemaps
        $sitemaps = $this->get_all_sitemaps();
        
        // Add sitemaps if available
        if ( ! empty( $sitemaps ) ) {
            $default .= "\n";
            foreach ( $sitemaps as $sitemap_url ) {
                $default .= "Sitemap: " . esc_url( $sitemap_url ) . "\n";
            }
        }

        return apply_filters( 'srk_recommended_robots_txt', $default );
    }

    /**
     * Get enhanced robots.txt content with additional security and SEO rules.
     * 
     * Includes WordPress recommended rules plus additional security and SEO best practices:
     * - Blocks xmlrpc.php (security)
     * - Blocks search queries (SEO)
     * - Blocks trackback/feed URLs (SEO)
     * - Blocks cgi-bin and cdn-cgi (security)
     *
     * @since    2.1.1
     * @return   string
     */
    private function get_enhanced_robots_txt() {
        // Start with WordPress recommended rules
        $enhanced = "User-agent: *\n";
        $enhanced .= "Disallow: /wp-admin/\n";
        $enhanced .= "Allow: /wp-admin/admin-ajax.php\n";
        $enhanced .= "Disallow: /wp-includes/\n";
        
        // Additional security and SEO rules
        $enhanced .= "Disallow: /xmlrpc.php\n";
        $enhanced .= "Disallow: /?s=\n";
        $enhanced .= "Disallow: /search/\n";
        $enhanced .= "Disallow: /trackback/\n";
        $enhanced .= "Disallow: /feed/\n";
        $enhanced .= "Disallow: /?feed=\n";
        $enhanced .= "Disallow: /cgi-bin/\n";
        $enhanced .= "Disallow: /cdn-cgi/\n";
        
        // Get all sitemaps
        $sitemaps = $this->get_all_sitemaps();
        
        // Add sitemaps if available
        if ( ! empty( $sitemaps ) ) {
            $enhanced .= "\n";
            foreach ( $sitemaps as $sitemap_url ) {
                $enhanced .= "Sitemap: " . esc_url( $sitemap_url ) . "\n";
            }
        }

        return apply_filters( 'srk_enhanced_robots_txt', $enhanced );
    }

    /**
     * Get default WordPress robots.txt content.
     * 
     * Generates WordPress recommended default robots.txt following WordPress core standards.
     * This respects the blog_public setting and is used when serving robots.txt.
     *
     * @since    2.1.1
     * @return   string
     */
    private function get_default_robots_txt() {
        // Check if site is set to discourage search engines
        $public = get_option( 'blog_public' );
        
        if ( ! $public ) {
            return "User-agent: *\nDisallow: /";
        }

        // WordPress recommended default robots.txt
        $default = "User-agent: *\n";
        $default .= "Disallow: /wp-admin/\n";
        $default .= "Allow: /wp-admin/admin-ajax.php\n";
        $default .= "Disallow: /wp-includes/\n";
        
        // Get all sitemaps
        $sitemaps = $this->get_all_sitemaps();
        
        // Add sitemaps if available
        if ( ! empty( $sitemaps ) ) {
            $default .= "\n";
            foreach ( $sitemaps as $sitemap_url ) {
                $default .= "Sitemap: " . esc_url( $sitemap_url ) . "\n";
            }
        }

        return apply_filters( 'srk_default_robots_txt', $default );
    }

    /**
     * Get all available sitemaps for the site.
     * 
     * Detects sitemaps from various SEO plugins and WordPress core.
     *
     * @since    2.1.1
     * @return   array Array of sitemap URLs
     */
    private function get_all_sitemaps() {
        $site_url = home_url();
        $sitemaps = array();
        
        // Check for Yoast SEO sitemaps
        if ( class_exists( 'WPSEO_Sitemaps' ) ) {
            $sitemaps[] = $site_url . '/sitemap_index.xml';
            
            // Check for additional Yoast sitemaps (if enabled)
            if ( class_exists( 'WPSEO_Options' ) ) {
                $yoast_options = WPSEO_Options::get_instance();
                if ( method_exists( $yoast_options, 'get' ) && $yoast_options->get( 'enable_xml_sitemap' ) ) {
                    // Add specific sitemaps if they might exist
                    // Note: We add them as potential sitemaps, actual existence depends on content
                    $sitemaps[] = $site_url . '/news-sitemap.xml';
                    $sitemaps[] = $site_url . '/video-sitemap.xml';
                    $sitemaps[] = $site_url . '/author-sitemap.xml';
                }
            }
        }
        // Check for Rank Math sitemaps
        elseif ( class_exists( 'RankMath' ) ) {
            $sitemaps[] = $site_url . '/sitemap_index.xml';
        }
        // Check for AIOSEO sitemaps
        elseif ( class_exists( 'AIOSEO\Plugin' ) ) {
            $sitemaps[] = $site_url . '/sitemap.xml';
        }
        // Check for WordPress 5.5+ built-in sitemap
        elseif ( function_exists( 'wp_sitemaps_get_server' ) ) {
            $sitemaps[] = $site_url . '/wp-sitemap.xml';
        }
        // Fallback to common sitemap locations
        else {
            // Try sitemap_index.xml first (most common)
            $sitemaps[] = $site_url . '/sitemap_index.xml';
        }
        
        // Remove duplicates and filter empty values
        $sitemaps = array_unique( array_filter( $sitemaps ) );
        
        return apply_filters( 'srk_robots_txt_sitemaps', $sitemaps );
    }

    /**
     * Get public posts for LLMs.txt generator.
     *
     * @since    2.1.1
     * @return   array
     */
    private function get_public_posts( $limit = 50 ) {
        $posts = get_posts( array(
            'post_type'      => 'post',
            'post_status'    => 'publish',
            'posts_per_page' => $limit,
            'orderby'        => 'date',
            'order'          => 'DESC',
        ) );

        return $posts;
    }

    /**
     * Get public pages for LLMs.txt generator.
     *
     * @since    2.1.1
     * @return   array
     */
    private function get_public_pages( $limit = 50 ) {
        $pages = get_pages( array(
            'post_status' => 'publish',
            'number'      => $limit,
            'sort_column' => 'post_date',
            'sort_order'  => 'DESC',
        ) );

        return $pages;
    }

    /**
     * Get public categories for LLMs.txt generator.
     *
     * @since    2.1.1
     * @return   array
     */
    private function get_public_categories() {
        $categories = get_categories( array(
            'hide_empty' => false,
        ) );

        return $categories;
    }

    /**
     * AJAX handler to save robots.txt content.
     *
     * @since    2.1.1
     */
    public function ajax_save_robots_txt() {
        // Check nonce
        if ( ! isset( $_POST['nonce'] ) || ! wp_verify_nonce( sanitize_text_field( wp_unslash( $_POST['nonce'] ) ), 'srk_robots_llms_nonce' ) ) {
            wp_send_json_error( array( 'message' => __( 'Security check failed. Please refresh the page and try again.', 'seo-repair-kit' ) ) );
            return;
        }

        if ( ! current_user_can( 'manage_options' ) ) {
            wp_send_json_error( array( 'message' => __( 'Permission denied.', 'seo-repair-kit' ) ) );
            return;
        }

        $content = isset( $_POST['content'] ) ? sanitize_textarea_field( wp_unslash( $_POST['content'] ) ) : '';

        // Save to option
        update_option( 'srk_robots_txt_content', $content );
        
        // Save last updated timestamp
        update_option( 'srk_robots_txt_last_updated', current_time( 'mysql' ) );
        
        // Format last updated time for display
        $last_updated = get_option( 'srk_robots_txt_last_updated', '' );
        $last_updated_display = '';
        if ( $last_updated ) {
            $last_updated_display = human_time_diff( strtotime( $last_updated ), current_time( 'timestamp' ) ) . ' ' . __( 'ago', 'seo-repair-kit' );
        }
        
        wp_send_json_success( array(
            'message' => __( 'Robots.txt saved successfully!', 'seo-repair-kit' ),
            'last_updated' => $last_updated_display,
            'last_updated_raw' => $last_updated,
        ) );
    }

    /**
     * AJAX handler to validate robots.txt content.
     *
     * @since    2.1.1
     */
    public function ajax_validate_robots_txt() {
        // Check nonce
        if ( ! isset( $_POST['nonce'] ) || ! wp_verify_nonce( sanitize_text_field( wp_unslash( $_POST['nonce'] ) ), 'srk_robots_llms_nonce' ) ) {
            wp_send_json_error( array( 'message' => __( 'Security check failed. Please refresh the page and try again.', 'seo-repair-kit' ) ) );
            return;
        }

        if ( ! current_user_can( 'manage_options' ) ) {
            wp_send_json_error( array( 'message' => __( 'Permission denied.', 'seo-repair-kit' ) ) );
            return;
        }

        $content = isset( $_POST['content'] ) ? sanitize_textarea_field( wp_unslash( $_POST['content'] ) ) : '';

        // Load validator class
        require_once plugin_dir_path( dirname( __FILE__ ) ) . 'includes/class-srk-robots-validator.php';
        $validator = new SRK_Robots_Validator();
        $result    = $validator->validate( $content );

        wp_send_json_success( $result );
    }

    /**
     * AJAX handler to apply enhanced robots.txt.
     * 
     * Applies enhanced robots.txt with additional security and SEO rules.
     *
     * @since    2.1.1
     */
    public function ajax_apply_enhanced_robots() {
        // Check nonce
        if ( ! isset( $_POST['nonce'] ) || ! wp_verify_nonce( sanitize_text_field( wp_unslash( $_POST['nonce'] ) ), 'srk_robots_llms_nonce' ) ) {
            wp_send_json_error( array( 'message' => __( 'Security check failed. Please refresh the page and try again.', 'seo-repair-kit' ) ) );
            return;
        }

        if ( ! current_user_can( 'manage_options' ) ) {
            wp_send_json_error( array( 'message' => __( 'Permission denied.', 'seo-repair-kit' ) ) );
            return;
        }

        // Get enhanced robots.txt content
        $enhanced_content = $this->get_enhanced_robots_txt();

        wp_send_json_success( array(
            'content' => $enhanced_content,
            'message' => __( 'Enhanced robots.txt applied successfully! Review and save to apply changes.', 'seo-repair-kit' ),
            'last_updated' => '',
        ) );
    }

    /**
     * AJAX handler to generate LLMs.txt content.
     *
     * @since    2.1.1
     */
    public function ajax_generate_llms_txt() {
        // Check nonce
        if ( ! isset( $_POST['nonce'] ) || ! wp_verify_nonce( sanitize_text_field( wp_unslash( $_POST['nonce'] ) ), 'srk_robots_llms_nonce' ) ) {
            wp_send_json_error( array( 'message' => __( 'Security check failed. Please refresh the page and try again.', 'seo-repair-kit' ) ) );
            return;
        }

        if ( ! current_user_can( 'manage_options' ) ) {
            wp_send_json_error( array( 'message' => __( 'Permission denied.', 'seo-repair-kit' ) ) );
            return;
        }

        // Get settings from POST or saved options
        $post_types = isset( $_POST['post_types'] ) && is_array( $_POST['post_types'] ) ? array_map( 'sanitize_text_field', $_POST['post_types'] ) : array();
        $taxonomies = isset( $_POST['taxonomies'] ) && is_array( $_POST['taxonomies'] ) ? array_map( 'sanitize_text_field', $_POST['taxonomies'] ) : array();
        $posts_limit = isset( $_POST['posts_limit'] ) ? absint( $_POST['posts_limit'] ) : 50;
        $additional_content = isset( $_POST['additional_content'] ) ? sanitize_textarea_field( wp_unslash( $_POST['additional_content'] ) ) : '';
        $allowed_bots = isset( $_POST['allowed_bots'] ) && is_array( $_POST['allowed_bots'] ) ? array_map( 'sanitize_text_field', $_POST['allowed_bots'] ) : array();

        // If no post types/taxonomies in POST, get from saved settings
        if ( empty( $post_types ) && empty( $taxonomies ) ) {
            $saved_settings = get_option( 'srk_llms_generator_settings', array() );
            $post_types = isset( $saved_settings['post_types'] ) ? $saved_settings['post_types'] : array( 'post', 'page' );
            $taxonomies = isset( $saved_settings['taxonomies'] ) ? $saved_settings['taxonomies'] : array();
            $posts_limit = isset( $saved_settings['posts_limit'] ) ? absint( $saved_settings['posts_limit'] ) : 50;
            $additional_content = isset( $saved_settings['additional_content'] ) ? $saved_settings['additional_content'] : '';
            
            // Get allowed_bots from saved settings
            // Important: Check if the key exists to distinguish between "never saved" vs "saved with empty array"
            // If key exists (even if empty), respect user's choice. Only default to all bots if key doesn't exist.
            if ( array_key_exists( 'allowed_bots', $saved_settings ) ) {
                // Settings have been saved before - respect the saved value (even if empty array)
                $allowed_bots = is_array( $saved_settings['allowed_bots'] ) ? $saved_settings['allowed_bots'] : array();
            } else {
                // Settings never saved - default to all popular bots
                $popular_bots = $this->get_popular_ai_bots();
                $allowed_bots = array_keys( $popular_bots );
            }
        }

        $content = $this->generate_llms_content_advanced( $post_types, $taxonomies, $posts_limit, $additional_content, $allowed_bots );

        wp_send_json_success( array(
            'content' => $content,
            'message' => __( 'LLMs.txt generated successfully!', 'seo-repair-kit' ),
        ) );
    }

    /**
     * AJAX handler to save LLMs.txt generator settings.
     *
     * @since    2.1.1
     */
    public function ajax_save_llms_settings() {
        // Check nonce
        if ( ! isset( $_POST['nonce'] ) || ! wp_verify_nonce( sanitize_text_field( wp_unslash( $_POST['nonce'] ) ), 'srk_robots_llms_nonce' ) ) {
            wp_send_json_error( array( 'message' => __( 'Security check failed. Please refresh the page and try again.', 'seo-repair-kit' ) ) );
            return;
        }

        if ( ! current_user_can( 'manage_options' ) ) {
            wp_send_json_error( array( 'message' => __( 'Permission denied.', 'seo-repair-kit' ) ) );
            return;
        }

        $post_types = isset( $_POST['post_types'] ) && is_array( $_POST['post_types'] ) ? array_map( 'sanitize_text_field', $_POST['post_types'] ) : array();
        $taxonomies = isset( $_POST['taxonomies'] ) && is_array( $_POST['taxonomies'] ) ? array_map( 'sanitize_text_field', $_POST['taxonomies'] ) : array();
        $posts_limit = isset( $_POST['posts_limit'] ) ? absint( $_POST['posts_limit'] ) : 50;
        $additional_content = isset( $_POST['additional_content'] ) ? sanitize_textarea_field( wp_unslash( $_POST['additional_content'] ) ) : '';
        $allowed_bots = isset( $_POST['allowed_bots'] ) && is_array( $_POST['allowed_bots'] ) ? array_map( 'sanitize_text_field', $_POST['allowed_bots'] ) : array();

        // Save settings
        $settings = array(
            'post_types'        => $post_types,
            'taxonomies'        => $taxonomies,
            'posts_limit'       => $posts_limit,
            'additional_content' => $additional_content,
            'allowed_bots'      => $allowed_bots,
        );

        update_option( 'srk_llms_generator_settings', $settings );
        
        // Clear robots.txt cache so bot blocking rules are updated
        // The robots.txt filter will automatically add bot blocking rules
        delete_transient( 'srk_robots_txt_content' );

        wp_send_json_success( array(
            'message' => __( 'Settings saved successfully! Bot blocking rules will be applied to robots.txt automatically.', 'seo-repair-kit' ),
        ) );
    }

    /**
     * AJAX handler to reset LLMs.txt generator options.
     *
     * @since    2.1.1
     */
    public function ajax_reset_llms_options() {
        // Check nonce
        if ( ! isset( $_POST['nonce'] ) || ! wp_verify_nonce( sanitize_text_field( wp_unslash( $_POST['nonce'] ) ), 'srk_robots_llms_nonce' ) ) {
            wp_send_json_error( array( 'message' => __( 'Security check failed. Please refresh the page and try again.', 'seo-repair-kit' ) ) );
            return;
        }

        if ( ! current_user_can( 'manage_options' ) ) {
            wp_send_json_error( array( 'message' => __( 'Permission denied.', 'seo-repair-kit' ) ) );
            return;
        }

        // Reset to defaults
        $popular_bots = $this->get_popular_ai_bots();
        $default_settings = array(
            'post_types'        => array( 'post', 'page' ),
            'taxonomies'        => array(),
            'posts_limit'       => 50,
            'additional_content' => '',
            'allowed_bots'      => array_keys( $popular_bots ), // Default: allow all bots
        );

        update_option( 'srk_llms_generator_settings', $default_settings );

        wp_send_json_success( array(
            'message' => __( 'Options reset to defaults successfully!', 'seo-repair-kit' ),
            'settings' => $default_settings,
        ) );
    }

    /**
     * AJAX handler to save LLMs.txt content.
     *
     * @since    2.1.1
     */
    public function ajax_save_llms_txt() {
        // Check nonce
        if ( ! isset( $_POST['nonce'] ) || ! wp_verify_nonce( sanitize_text_field( wp_unslash( $_POST['nonce'] ) ), 'srk_robots_llms_nonce' ) ) {
            wp_send_json_error( array( 'message' => __( 'Security check failed. Please refresh the page and try again.', 'seo-repair-kit' ) ) );
            return;
        }

        if ( ! current_user_can( 'manage_options' ) ) {
            wp_send_json_error( array( 'message' => __( 'Permission denied.', 'seo-repair-kit' ) ) );
            return;
        }

        $content = isset( $_POST['content'] ) ? sanitize_textarea_field( wp_unslash( $_POST['content'] ) ) : '';

        // Save to option
        update_option( 'srk_llms_txt_content', $content );
        
        // Save last updated timestamp
        update_option( 'srk_llms_txt_last_updated', current_time( 'mysql' ) );
        
        // Format last updated time for display
        $last_updated = get_option( 'srk_llms_txt_last_updated', '' );
        $last_updated_display = '';
        if ( $last_updated ) {
            $last_updated_display = human_time_diff( strtotime( $last_updated ), current_time( 'timestamp' ) ) . ' ' . __( 'ago', 'seo-repair-kit' );
        }

        wp_send_json_success( array(
            'message' => __( 'LLMs.txt saved successfully!', 'seo-repair-kit' ),
            'last_updated' => $last_updated_display,
            'last_updated_raw' => $last_updated,
        ) );
    }

    /**
     * AJAX handler to delete robots.txt custom content.
     * 
     * This safely deletes only the custom robots.txt content stored in WordPress options.
     * After deletion, WordPress will use its default robots.txt behavior.
     *
     * @since    2.1.1
     */
    public function ajax_delete_robots_txt() {
        // Check nonce
        if ( ! isset( $_POST['nonce'] ) || ! wp_verify_nonce( sanitize_text_field( wp_unslash( $_POST['nonce'] ) ), 'srk_robots_llms_nonce' ) ) {
            wp_send_json_error( array( 'message' => __( 'Security check failed. Please refresh the page and try again.', 'seo-repair-kit' ) ) );
            return;
        }

        if ( ! current_user_can( 'manage_options' ) ) {
            wp_send_json_error( array( 'message' => __( 'Permission denied.', 'seo-repair-kit' ) ) );
            return;
        }

        // Delete the custom robots.txt option
        // This is safe - it only removes our custom content, WordPress defaults will be used
        delete_option( 'srk_robots_txt_content' );
        delete_option( 'srk_robots_txt_last_updated' );

        wp_send_json_success( array(
            'message' => __( 'Custom robots.txt deleted successfully! WordPress will now use default robots.txt behavior.', 'seo-repair-kit' ),
            'default_content' => $this->get_recommended_robots_txt(),
            'last_updated' => '',
        ) );
    }

    /**
     * AJAX handler to delete LLMs.txt content.
     * 
     * This safely deletes the LLMs.txt content stored in WordPress options.
     * After deletion, the /llms.txt URL will return a 404 (as expected when no file exists).
     *
     * @since    2.1.1
     */
    public function ajax_delete_llms_txt() {
        // Check nonce
        if ( ! isset( $_POST['nonce'] ) || ! wp_verify_nonce( sanitize_text_field( wp_unslash( $_POST['nonce'] ) ), 'srk_robots_llms_nonce' ) ) {
            wp_send_json_error( array( 'message' => __( 'Security check failed. Please refresh the page and try again.', 'seo-repair-kit' ) ) );
            return;
        }

        if ( ! current_user_can( 'manage_options' ) ) {
            wp_send_json_error( array( 'message' => __( 'Permission denied.', 'seo-repair-kit' ) ) );
            return;
        }

        // Delete the LLMs.txt option
        // This is safe - it only removes our custom content
        delete_option( 'srk_llms_txt_content' );
        delete_option( 'srk_llms_txt_last_updated' );

        wp_send_json_success( array(
            'message' => __( 'LLMs.txt deleted successfully!', 'seo-repair-kit' ),
        ) );
    }

    /**
     * AJAX handler to get content list (for dynamic loading).
     *
     * @since    2.1.1
     */
    public function ajax_get_content_list() {
        // Check nonce
        if ( ! isset( $_POST['nonce'] ) || ! wp_verify_nonce( sanitize_text_field( wp_unslash( $_POST['nonce'] ) ), 'srk_robots_llms_nonce' ) ) {
            wp_send_json_error( array( 'message' => __( 'Security check failed. Please refresh the page and try again.', 'seo-repair-kit' ) ) );
            return;
        }

        if ( ! current_user_can( 'manage_options' ) ) {
            wp_send_json_error( array( 'message' => __( 'Permission denied.', 'seo-repair-kit' ) ) );
            return;
        }

        $type = isset( $_POST['type'] ) ? sanitize_text_field( $_POST['type'] ) : 'posts';

        $data = array();
        if ( 'posts' === $type ) {
            $items = $this->get_public_posts( 100 );
            foreach ( $items as $item ) {
                $data[] = array(
                    'id'    => $item->ID,
                    'title' => $item->post_title,
                    'url'   => get_permalink( $item->ID ),
                );
            }
        } elseif ( 'pages' === $type ) {
            $items = $this->get_public_pages( 100 );
            foreach ( $items as $item ) {
                $data[] = array(
                    'id'    => $item->ID,
                    'title' => $item->post_title,
                    'url'   => get_permalink( $item->ID ),
                );
            }
        } elseif ( 'categories' === $type ) {
            $items = $this->get_public_categories();
            foreach ( $items as $item ) {
                $data[] = array(
                    'id'    => $item->term_id,
                    'title' => $item->name,
                    'url'   => get_category_link( $item->term_id ),
                );
            }
        }

        wp_send_json_success( array( 'items' => $data ) );
    }

    /**
     * Generate LLMs.txt content from selected items (legacy method for backward compatibility).
     * 
     * @since    2.1.1
     * @param    array $posts      Post IDs
     * @param    array $pages      Page IDs
     * @param    array $categories Category IDs
     * @return   string Generated LLMs.txt content
     */
    private function generate_llms_content( $posts = array(), $pages = array(), $categories = array() ) {
        // Convert to new format for backward compatibility
        $post_types = array();
        if ( ! empty( $posts ) ) {
            $post_types[] = 'post';
        }
        if ( ! empty( $pages ) ) {
            $post_types[] = 'page';
        }
        
        $taxonomies = array();
        if ( ! empty( $categories ) ) {
            $taxonomies[] = 'category';
        }
        
        // Get allowed bots from settings or default to all
        $saved_settings = get_option( 'srk_llms_generator_settings', array() );
        // Important: Check if the key exists to distinguish between "never saved" vs "saved with empty array"
        // If key exists (even if empty), respect user's choice. Only default to all bots if key doesn't exist.
        if ( array_key_exists( 'allowed_bots', $saved_settings ) ) {
            // Settings have been saved before - respect the saved value (even if empty array)
            $allowed_bots = is_array( $saved_settings['allowed_bots'] ) ? $saved_settings['allowed_bots'] : array();
        } else {
            // Settings never saved - default to all popular bots
            $popular_bots = $this->get_popular_ai_bots();
            $allowed_bots = array_keys( $popular_bots );
        }
        
        return $this->generate_llms_content_advanced( $post_types, $taxonomies, 50, '', $allowed_bots );
    }

    /**
     * Generate LLMs.txt content from post types, taxonomies, and settings.
     * 
     * This method follows the proposed LLMs.txt standard format with enhanced features:
     * - Plain text file with UTF-8 encoding
     * - Section headers using markdown format (##)
     * - Markdown link format: [Title](URL): Description
     * - Sitemap inclusion
     * - Organized by post type and taxonomy
     * - Comments for metadata
     * 
     * Standards Compliance:
     * - ✅ Absolute URLs (required by proposed standard)
     * - ✅ Markdown link format (enhanced readability)
     * - ✅ Section organization (best practice)
     * - ✅ Comments for clarity (recommended)
     * - ✅ Only published content (security best practice)
     * - ✅ Sitemap inclusion (helps LLMs discover content)
     * 
     * Reference: https://llmstxt.org/ (proposed standard)
     *
     * @since    2.1.1
     * @param    array  $post_types        Selected post type slugs
     * @param    array  $taxonomies        Selected taxonomy slugs
     * @param    int    $posts_limit       Maximum posts/terms per type
     * @param    string $additional_content Additional custom content
     * @return   string Generated LLMs.txt content
     */
    private function generate_llms_content_advanced( $post_types = array(), $taxonomies = array(), $posts_limit = 50, $additional_content = '', $allowed_bots = array() ) {
        $site_name = get_bloginfo( 'name' );
        $site_url  = home_url();

        // Header section with metadata (follows proposed standard)
        $content = "Generated by SEO Repair Kit v" . esc_html( $this->version ) . ", this is an llms.txt file designed to help LLMs better understand and index this website.\n\n";
        $content .= "# " . esc_html( $site_name ) . "\n\n";
        
        // Note: Bot access control is handled at the server level (in serve_llms_txt())
        // and via robots.txt rules. LLMs.txt itself is just a content discovery file.

        // Sitemap Section (important for LLMs to discover content)
        $sitemap_url = $this->get_sitemap_url();
        if ( $sitemap_url ) {
            $content .= "## Sitemaps\n";
            $content .= "[XML Sitemap](" . esc_url( $sitemap_url ) . "): Includes all crawlable and indexable pages.\n\n";
        }

        // Process Post Types - Group by post type (like Rank Math)
        if ( ! empty( $post_types ) ) {
            foreach ( $post_types as $post_type_slug ) {
                if ( ! post_type_exists( $post_type_slug ) ) {
                    continue;
                }
                
                $post_type_obj = get_post_type_object( $post_type_slug );
                if ( ! $post_type_obj ) {
                    continue;
                }
                
                // Get posts for this post type
                $posts = get_posts( array(
                    'post_type'      => $post_type_slug,
                    'post_status'    => 'publish',
                    'posts_per_page' => $posts_limit,
                    'orderby'        => 'date',
                    'order'          => 'DESC',
                ) );
                
                if ( ! empty( $posts ) ) {
                    // Section header with post type label
                    $content .= "## " . esc_html( $post_type_obj->labels->name ) . "\n";
                    
                    foreach ( $posts as $post ) {
                        $url = get_permalink( $post->ID );
                        if ( ! $url ) {
                            continue;
                        }
                        
                        // Get post title
                        $title = get_the_title( $post->ID );
                        if ( empty( $title ) ) {
                            $title = __( '(No title)', 'seo-repair-kit' );
                        }
                        
                        // Only use existing post excerpt - DO NOT auto-generate from content
                        // Use get_post_field to get the raw excerpt field (manual excerpt only)
                        // This works for all post types including WooCommerce products (short description)
                        $raw_excerpt = get_post_field( 'post_excerpt', $post->ID );
                        $description = '';
                        
                        if ( ! empty( $raw_excerpt ) ) {
                            // Clean existing excerpt - decode HTML entities and strip tags
                            $description = html_entity_decode( $raw_excerpt, ENT_QUOTES | ENT_HTML5, 'UTF-8' );
                            $description = wp_strip_all_tags( $description );
                            $description = trim( $description );
                            
                            // Only include if not empty after cleaning
                            if ( ! empty( $description ) ) {
                                $description = esc_html( $description );
                            } else {
                                $description = '';
                            }
                        }
                        
                        // For WooCommerce products, also check for short description in meta (if post_excerpt is empty)
                        if ( empty( $description ) && class_exists( 'WooCommerce' ) && $post_type_slug === 'product' ) {
                            $short_desc = get_post_meta( $post->ID, '_product_short_description', true );
                            if ( empty( $short_desc ) ) {
                                // Try the standard WooCommerce method
                                if ( function_exists( 'wc_get_product' ) ) {
                                    $product = wc_get_product( $post->ID );
                                    if ( $product ) {
                                        $short_desc = $product->get_short_description();
                                    }
                                }
                            }
                            
                            if ( ! empty( $short_desc ) ) {
                                $description = html_entity_decode( $short_desc, ENT_QUOTES | ENT_HTML5, 'UTF-8' );
                                $description = wp_strip_all_tags( $description );
                                $description = trim( $description );
                                if ( ! empty( $description ) ) {
                                    $description = esc_html( $description );
                                } else {
                                    $description = '';
                                }
                            }
                        }
                        
                        // Format: [Title](URL): Description (only if description exists)
                        $content .= "- [" . esc_html( $title ) . "](" . esc_url( $url ) . ")";
                        if ( ! empty( $description ) ) {
                            $content .= ": " . $description;
                        }
                        $content .= "\n";
                    }
                    $content .= "\n";
                }
            }
        }

        // Process Taxonomies - Group by taxonomy (like Rank Math)
        if ( ! empty( $taxonomies ) ) {
            foreach ( $taxonomies as $taxonomy_slug ) {
                if ( ! taxonomy_exists( $taxonomy_slug ) ) {
                    continue;
                }
                
                $taxonomy_obj = get_taxonomy( $taxonomy_slug );
                if ( ! $taxonomy_obj ) {
                    continue;
                }
                
                // Get terms for this taxonomy
                $terms = get_terms( array(
                    'taxonomy'   => $taxonomy_slug,
                    'hide_empty' => false,
                    'number'     => $posts_limit,
                ) );
                
                if ( ! empty( $terms ) && ! is_wp_error( $terms ) ) {
                    // Section header with taxonomy label
                    $content .= "## " . esc_html( $taxonomy_obj->labels->name ) . "\n";
                    
                    foreach ( $terms as $term ) {
                        $url = get_term_link( $term );
                        if ( ! $url || is_wp_error( $url ) ) {
                            continue;
                        }
                        
                        // Get term name
                        $term_name = $term->name;
                        
                        // Only use existing term description - DO NOT auto-generate
                        $description = '';
                        if ( ! empty( $term->description ) ) {
                            // Clean existing description - decode HTML entities and strip tags
                            $description = html_entity_decode( $term->description, ENT_QUOTES | ENT_HTML5, 'UTF-8' );
                            $description = wp_strip_all_tags( $description );
                            $description = trim( $description );
                            
                            // Only include if not empty after cleaning
                            if ( ! empty( $description ) ) {
                                $description = esc_html( $description );
                            } else {
                                $description = '';
                            }
                        }
                        
                        // Format: [Term Name](URL): Description (only if description exists)
                        $content .= "- [" . esc_html( $term_name ) . "](" . esc_url( $url ) . ")";
                        if ( ! empty( $description ) ) {
                            $content .= ": " . $description;
                        }
                        $content .= "\n";
                    }
                    $content .= "\n";
                }
            }
        }

        // Additional Content Section
        if ( ! empty( trim( $additional_content ) ) ) {
            $content .= "## Additional Content\n\n";
            $content .= esc_textarea( $additional_content ) . "\n\n";
        }

        // Return content (plain text, UTF-8 encoded)
        return $content;
    }

    /**
     * Get list of popular AI bots with their User-Agent strings.
     *
     * @since    2.1.1
     * @return   array Array of bot information with keys: name, user_agent, description
     */
    private function get_popular_ai_bots() {
        return array(
            'gptbot' => array(
                'name'        => __( 'ChatGPT (GPTBot)', 'seo-repair-kit' ),
                'user_agent'  => 'GPTBot',
                'description' => __( 'OpenAI\'s ChatGPT crawler', 'seo-repair-kit' ),
            ),
            'chatgpt_user' => array(
                'name'        => __( 'ChatGPT User', 'seo-repair-kit' ),
                'user_agent'  => 'ChatGPT-User',
                'description' => __( 'ChatGPT browsing feature', 'seo-repair-kit' ),
            ),
            'claude' => array(
                'name'        => __( 'Claude (Anthropic)', 'seo-repair-kit' ),
                'user_agent'  => 'Claude-Web',
                'description' => __( 'Anthropic\'s Claude AI', 'seo-repair-kit' ),
            ),
            'google_bard' => array(
                'name'        => __( 'Google Bard/Gemini', 'seo-repair-kit' ),
                'user_agent'  => 'Google-Extended',
                'description' => __( 'Google\'s Bard and Gemini AI', 'seo-repair-kit' ),
            ),
            'perplexity' => array(
                'name'        => __( 'Perplexity AI', 'seo-repair-kit' ),
                'user_agent'  => 'PerplexityBot',
                'description' => __( 'Perplexity AI search engine', 'seo-repair-kit' ),
            ),
            'bing_chat' => array(
                'name'        => __( 'Bing Chat/Copilot', 'seo-repair-kit' ),
                'user_agent'  => 'Bingbot',
                'description' => __( 'Microsoft Bing Chat and Copilot', 'seo-repair-kit' ),
            ),
            'you_com' => array(
                'name'        => __( 'You.com', 'seo-repair-kit' ),
                'user_agent'  => 'YouBot',
                'description' => __( 'You.com AI search', 'seo-repair-kit' ),
            ),
            'character_ai' => array(
                'name'        => __( 'Character.AI', 'seo-repair-kit' ),
                'user_agent'  => 'Character-AI',
                'description' => __( 'Character.AI crawler', 'seo-repair-kit' ),
            ),
            'ccbot' => array(
                'name'        => __( 'CCBot (Common Crawl)', 'seo-repair-kit' ),
                'user_agent'  => 'CCBot',
                'description' => __( 'Common Crawl bot (used by many AI models)', 'seo-repair-kit' ),
            ),
            'anthropic_ai' => array(
                'name'        => __( 'Anthropic AI', 'seo-repair-kit' ),
                'user_agent'  => 'anthropic-ai',
                'description' => __( 'Anthropic AI crawler', 'seo-repair-kit' ),
            ),
            'deepseek' => array(
                'name'        => __( 'DeepSeek', 'seo-repair-kit' ),
                'user_agent'  => 'DeepSeekBot',
                'description' => __( 'DeepSeek AI crawler', 'seo-repair-kit' ),
            ),
            'grok' => array(
                'name'        => __( 'Grok (xAI)', 'seo-repair-kit' ),
                'user_agent'  => 'GrokBot',
                'description' => __( 'xAI\'s Grok AI', 'seo-repair-kit' ),
            ),
            'qwen_ai' => array(
                'name'        => __( 'Qwen AI (Alibaba)', 'seo-repair-kit' ),
                'user_agent'  => 'QwenBot',
                'description' => __( 'Alibaba\'s Qwen AI', 'seo-repair-kit' ),
            ),
            'meta_llama' => array(
                'name'        => __( 'Meta Llama', 'seo-repair-kit' ),
                'user_agent'  => 'Meta-Llama',
                'description' => __( 'Meta\'s Llama AI model', 'seo-repair-kit' ),
            ),
            'cohere' => array(
                'name'        => __( 'Cohere', 'seo-repair-kit' ),
                'user_agent'  => 'CohereBot',
                'description' => __( 'Cohere AI crawler', 'seo-repair-kit' ),
            ),
            'mistral_ai' => array(
                'name'        => __( 'Mistral AI', 'seo-repair-kit' ),
                'user_agent'  => 'MistralBot',
                'description' => __( 'Mistral AI crawler', 'seo-repair-kit' ),
            ),
            'huggingface' => array(
                'name'        => __( 'Hugging Face', 'seo-repair-kit' ),
                'user_agent'  => 'HuggingFaceBot',
                'description' => __( 'Hugging Face AI models', 'seo-repair-kit' ),
            ),
        );
    }

    /**
     * Get sitemap URL for the site (for LLMs.txt).
     * Returns the primary sitemap URL.
     * 
     * For robots.txt, use get_all_sitemaps() instead to get all sitemaps.
     *
     * @since    2.1.1
     * @return   string|false Sitemap URL or false if not found
     */
    private function get_sitemap_url() {
        $sitemaps = $this->get_all_sitemaps();
        
        if ( ! empty( $sitemaps ) ) {
            // Return the first (primary) sitemap
            return esc_url( $sitemaps[0] );
        }
        
        return false;
    }
}
