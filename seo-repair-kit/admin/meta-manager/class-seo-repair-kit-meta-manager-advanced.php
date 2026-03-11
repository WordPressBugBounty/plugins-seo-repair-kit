<?php
if (!defined('ABSPATH')) {
    exit;
}

/**
 * Advanced Meta Manager Settings - ROBOTS META ONLY
 * 
 * @package SEO_Repair_Kit
 * @since 2.1.3 - Added caching system, optimized database queries
 * @version 2.1.3
 */
class SRK_Meta_Manager_Advanced {
    
    /** Allowed keys only - no index/follow (derive at output). */
    private $default_robots = array(
        'noindex'             => '0',
        'nofollow'            => '0',
        'noarchive'           => '0',
        'notranslate'         => '0',
        'noimageindex'        => '0',
        'nosnippet'           => '0',
        'noodp'               => '0',
        'max_snippet'         => -1,
        'max_video_preview'   => -1,
        'max_image_preview'   => 'large',
    );

    public function __construct() {
        $this->init_hooks();
    }
    
    private function init_hooks() {
        add_action('admin_enqueue_scripts', array($this, 'enqueue_scripts'));
        add_action('admin_init', array($this, 'register_settings'));
    }
    
    /**
     * Register advanced settings
     */
    public function register_settings() {
        register_setting(
            'srk_meta_advanced_settings',
            'srk_meta',
            array(
                'type'              => 'array',
                'sanitize_callback' => array($this, 'sanitize_advanced_settings'),
                'default'           => array(),
            )
        );
    }
    
    /**
     * Sanitize advanced settings - SIMPLIFIED VERSION
     */
    public function sanitize_advanced_settings($value) {
        if (!is_array($value)) {
            $value = array();
        }
        
        // Ensure advanced key exists
        if (!isset($value['advanced']) || !is_array($value['advanced'])) {
            $value['advanced'] = array();
        }
        
        $advanced = &$value['advanced'];
        
        // Get existing settings
        $existing_settings = get_option('srk_meta', array());
        $existing_advanced = isset($existing_settings['advanced']) ? $existing_settings['advanced'] : array();
        
        // Handle "Use Default Settings" toggle. When ON: SKIP this level — do NOT inject robots_meta.
        $use_default = isset( $_POST['srk_use_default_settings'] ) && '1' === sanitize_text_field( wp_unslash( $_POST['srk_use_default_settings'] ) ) ? '1' : '0';
        $advanced['use_default_settings'] = $use_default;

        if ( $use_default === '1' ) {
            // Use Default = SKIP: store only the flag. No robots_meta, no index/follow injection.
            if ( isset( $advanced['robots_meta'] ) ) {
                unset( $advanced['robots_meta'] );
            }
            if ( isset( $existing_advanced['robots_meta'] ) && is_array( $existing_advanced['robots_meta'] ) ) {
                $advanced['custom_robots_backup'] = $existing_advanced['robots_meta'];
            }
        } else {
        // When NOT using defaults
        $robots_meta = $this->default_robots;

        // ✅ FIX: RESTORE LOGIC IMPROVED
        if (isset($existing_advanced['custom_robots_backup']) && is_array($existing_advanced['custom_robots_backup'])) {
            $robots_meta = $existing_advanced['custom_robots_backup'];
        } elseif (isset($existing_advanced['robots_meta']) && is_array($existing_advanced['robots_meta'])) {
            $robots_meta = wp_parse_args($existing_advanced['robots_meta'], $this->default_robots);
        }

        // ✅ CRITICAL FIX: Only override with POST data if form was submitted
        if ( isset( $_POST['srk_robots_meta'] ) ) {
        $post_data = wp_unslash( $_POST['srk_robots_meta'] );
            
        // ✅ FIX: Checkbox values - only set if present in POST
        $robots_meta['noindex']  = !empty($post_data['noindex']) ? '1' : '0';
        $robots_meta['nofollow'] = !empty($post_data['nofollow']) ? '1' : '0';
        $robots_meta['noarchive'] = !empty($post_data['noarchive']) ? '1' : '0';
        $robots_meta['notranslate'] = !empty($post_data['notranslate']) ? '1' : '0';
        $robots_meta['noimageindex'] = !empty($post_data['noimageindex']) ? '1' : '0';
        $robots_meta['nosnippet'] = !empty($post_data['nosnippet']) ? '1' : '0';
        $robots_meta['noodp'] = !empty($post_data['noodp']) ? '1' : '0';

        // Handle conditional fields (mutual exclusivity)
        // If noimageindex is checked, remove max_image_preview
        if ($robots_meta['noimageindex'] === '1') {
            $robots_meta['max_image_preview'] = ''; // Empty value
        } else {
            // Only set if provided in POST
            if (isset($post_data['max_image_preview'])) {
                $robots_meta['max_image_preview'] = sanitize_text_field($post_data['max_image_preview']);
            }
        }
        
        // If nosnippet is checked, remove max_snippet
        if ($robots_meta['nosnippet'] === '1') {
            $robots_meta['max_snippet'] = '-1';
        } else {
            // Only set if provided in POST
            if (isset($post_data['max_snippet'])) {
                $robots_meta['max_snippet'] = intval($post_data['max_snippet']);
            }
        }
        
        // Max video preview (always applicable)
        if (isset($post_data['max_video_preview'])) {
            $robots_meta['max_video_preview'] = intval($post_data['max_video_preview']);
        }
    }

        // Keep only allowed keys
        $allowed = array('noindex','nofollow','noarchive','notranslate','noimageindex','nosnippet','noodp','max_snippet','max_video_preview','max_image_preview');
        $robots_meta = array_intersect_key($robots_meta, array_flip($allowed));
        $robots_meta = wp_parse_args($robots_meta, $this->default_robots);
        $advanced['robots_meta'] = $robots_meta;
            
            // Remove backup since we're using custom settings now
            if (isset($advanced['custom_robots_backup'])) {
                unset($advanced['custom_robots_backup']);
            }
        }
        
        return $value;
    }
        
    /**
     * Generate robots meta content string - FIXED
     */
    private function generate_robots_content($robots_meta) {
        $directives = array();
        
        // Ensure we have all required keys
        $robots_meta = wp_parse_args($robots_meta, $this->default_robots);
        
        // Index/NoIndex
        if ($robots_meta['noindex'] === '1' || $robots_meta['noindex'] === 1) {
            $directives[] = 'noindex';
        } else {
            $directives[] = 'index';
        }
        
        // Follow/NoFollow
        if ($robots_meta['nofollow'] === '1' || $robots_meta['nofollow'] === 1) {
            $directives[] = 'nofollow';
        } else {
            $directives[] = 'follow';
        }
        
        // Other directives
        if ($robots_meta['noarchive'] === '1' || $robots_meta['noarchive'] === 1) $directives[] = 'noarchive';
        if ($robots_meta['notranslate'] === '1' || $robots_meta['notranslate'] === 1) $directives[] = 'notranslate';
        if ($robots_meta['noimageindex'] === '1' || $robots_meta['noimageindex'] === 1) {
            $directives[] = 'noimageindex';
        // ✅ FIX: Don't add max-image-preview when noimageindex is checked
        } else {
            // Only add max-image-preview if noimageindex is NOT checked
            if ($robots_meta['max_image_preview'] === 'none') {
                $directives[] = 'max-image-preview:none';
            } elseif ($robots_meta['max_image_preview']) {
                $directives[] = 'max-image-preview:' . $robots_meta['max_image_preview'];
            } else {
                $directives[] = 'max-image-preview:large';
            }
        }
        
        if ($robots_meta['nosnippet'] === '1' || $robots_meta['nosnippet'] === 1) {
            $directives[] = 'nosnippet';
            // ✅ FIX: Don't add max-snippet when nosnippet is checked
        } else {
            // Only add max-snippet if nosnippet is NOT checked AND has a valid value
            if ($robots_meta['max_snippet'] != '-1') {
                $directives[] = 'max-snippet:' . $robots_meta['max_snippet'];
            }
        }
        
        if ($robots_meta['noodp'] === '1' || $robots_meta['noodp'] === 1) $directives[] = 'noodp';
        
        // Max Video Preview (always applicable unless specifically disabled)
        if ($robots_meta['max_video_preview'] != '-1') {
            $directives[] = 'max-video-preview:' . $robots_meta['max_video_preview'];
        }
        
        return implode(', ', $directives);
    }
    
    /**
     * Enqueue scripts
     */
    public function enqueue_scripts($hook) {
        if ($hook !== 'seo-repair-kit_page_seo-repair-kit-meta-manager') {
            return;
        }
        
        if ( ! isset( $_GET['tab'] ) || 'advanced' !== sanitize_key( wp_unslash( $_GET['tab'] ) ) ) {
            return;
        }

        // Get current settings
        $srk_meta = get_option('srk_meta', array());
        $advanced_settings = isset($srk_meta['advanced']) ? $srk_meta['advanced'] : array();
        
        $use_default = isset($advanced_settings['use_default_settings']) ? $advanced_settings['use_default_settings'] : '1'; // Default to ON
        $robots_meta = isset($advanced_settings['robots_meta']) ? $advanced_settings['robots_meta'] : $this->default_robots;
        
        // For display in form - when default is ON, show default values
        if ($use_default === '1') {
            $display_robots_meta = $this->default_robots;
        } else {
            $display_robots_meta = $robots_meta;
        }
        
        // Merge with defaults for any missing values in display
        $display_robots_meta = wp_parse_args($display_robots_meta, $this->default_robots);
        
        // Localize script
        wp_localize_script(
            'srk-meta-advanced-js',
            'srkAdvancedData',
            array(
                'ajax_url'          => admin_url('admin-ajax.php'),
                'nonce'            => wp_create_nonce('srk_advanced_nonce'),
                'discourage_search' => get_option('blog_public') == '0', // NEW
                'use_default_settings' => $use_default,
                'current_robots_meta' => $display_robots_meta,
                'default_robots_meta' => $this->default_robots,
                'separator'        => isset($srk_meta['global']['title_separator']) ? $srk_meta['global']['title_separator'] : '-',
                'strings'          => array(
                    'saving'        => __('Saving...', 'seo-repair-kit'),
                    'saved'         => __('Settings saved!', 'seo-repair-kit'),
                    'error'         => __('Error saving settings', 'seo-repair-kit'),
                    'confirm_reset' => __('Are you sure you want to reset all settings to defaults?', 'seo-repair-kit'),
                    'defaults_on'   => __('Default settings are ON', 'seo-repair-kit'),
                    'defaults_off'  => __('Default settings are OFF', 'seo-repair-kit'),
                )
            )
        );
    }
    
    /**
     * Render Advanced settings tab - ROBOTS META ONLY
     */
    public function render() {
        // Get current settings
        $srk_meta = get_option('srk_meta', array());
        $advanced_settings = isset($srk_meta['advanced']) ? $srk_meta['advanced'] : array();
        
        // Get settings with defaults
        $use_default = isset($advanced_settings['use_default_settings']) ? $advanced_settings['use_default_settings'] : '1'; // Default to ON
        
        // For display - when default is ON, show default values
        if ($use_default === '1') {
            $display_robots_meta = $this->default_robots;
        } else {
            $display_robots_meta = isset($advanced_settings['robots_meta']) ? $advanced_settings['robots_meta'] : $this->default_robots;
        }
        
        // Merge with defaults for any missing values in display
        $display_robots_meta = wp_parse_args($display_robots_meta, $this->default_robots);
        
        // Generate robots preview (use actual settings, not display settings)
        $actual_robots_meta = isset($advanced_settings['robots_meta']) ? $advanced_settings['robots_meta'] : $this->default_robots;
        $robots_preview = $this->generate_robots_content($actual_robots_meta);
        
        // Check for success message
        $settings_updated = isset( $_GET['settings-updated'] ) && 'true' === sanitize_text_field( wp_unslash( $_GET['settings-updated'] ) );
        ?>
        
        <div class="wrap srk-advanced-settings">
            <h1><?php esc_html_e('Advanced Settings', 'seo-repair-kit'); ?></h1>

            <?php if ( get_option('blog_public') == '0' ) : ?>
            <div class="notice notice-warning">
                <p>
                    <strong><?php esc_html_e('Search Engine Indexing Disabled by WordPress', 'seo-repair-kit'); ?></strong><br>
                    <?php esc_html_e('WordPress Reading Settings currently discourage search engines from indexing this website.', 'seo-repair-kit'); ?><br>
                    <?php esc_html_e('Because of this, SEO Repair Kit Meta Manager will NOT override WordPress robots directives.', 'seo-repair-kit'); ?><br>
                    <?php esc_html_e('All pages will output:', 'seo-repair-kit'); ?>
                    <code>&lt;meta name="robots" content="noindex, nofollow" /&gt;</code>
                    <br><br>
                    <?php esc_html_e('To allow indexing, disable "Discourage search engines from indexing this site" in Settings → Reading.', 'seo-repair-kit'); ?>
                </p>
            </div>
            <?php endif; ?>
            
            <?php if ($settings_updated): ?>
                <div class="notice notice-success is-dismissible">
                    <p><?php esc_html_e('Settings saved successfully!', 'seo-repair-kit'); ?></p>
                </div>
            <?php endif; ?>
            
            <form method="post" action="options.php" id="srk-advanced-form">
                <?php settings_fields('srk_meta_advanced_settings'); ?>
                
                <!-- ==================== GLOBAL ROBOTS META ==================== -->
                <div class="srk-section">
                    <div class="srk-section-header">
                        <h2 class="srk-section-title">
                            <?php esc_html_e('Global Robots Meta (Applies Site-Wide)', 'seo-repair-kit'); ?>
                        </h2>
                        <button type="button" class="srk-section-toggle" aria-label="<?php esc_attr_e('Toggle section', 'seo-repair-kit'); ?>">
                            <span class="dashicons dashicons-arrow-up-alt2"></span>
                        </button>
                    </div>
                    
                    <div class="srk-section-content">
                        <div class="srk-toggle-setting">
                            <label class="srk-toggle-switch">
                                <input type="checkbox" 
                                       id="srk_use_default_settings" 
                                       name="srk_use_default_settings" 
                                       value="1" 
                                       <?php checked($use_default, '1'); ?>>
                                <span class="srk-toggle-slider"></span>
                            </label>
                            <div class="srk-toggle-content">
                                <span class="srk-toggle-title"><?php esc_html_e('Use Recommended Default Robots Settings', 'seo-repair-kit'); ?></span>
                                <span class="srk-toggle-description">
                                    <?php esc_html_e('These robots meta settings will be applied to the entire website', 'seo-repair-kit'); ?>
                                    <span class="srk-premium-badge">
                                        <span class="dashicons dashicons-star-filled"></span>
                                        <?php esc_html_e('Recommended', 'seo-repair-kit'); ?>
                                    </span>
                                </span>
                            </div>
                        </div>

                        <!-- Default Mode Message -->
                        <div class="srk-default-mode-message <?php echo $use_default === '1' ? 'show' : ''; ?>">
                            <strong><?php esc_html_e('✓ Recommended Robots Settings Active', 'seo-repair-kit'); ?></strong>
                            <?php esc_html_e('Your website is currently using recommended, SEO-optimized robots directives that apply globally across your entire website. These settings control how search engines crawl and index all pages, including posts, pages, archives, and search results.', 'seo-repair-kit'); ?>
                            <div class="srk-robots-preview">
                                <code><?php echo esc_html($robots_preview); ?></code>
                            </div>
                            <p style="margin-top: 10px; font-size: 13px; color: #666;">
                                <?php esc_html_e('These settings will be output as:', 'seo-repair-kit'); ?>
                                <code>&lt;meta name="robots" content="<?php echo esc_html($robots_preview); ?>" /&gt;</code>
                            </p>
                        </div>

                        <!-- Robots Meta Container -->
                        <div class="srk-robots-meta-container" 
                             id="srk-robots-meta-container"
                             style="<?php echo $use_default === '1' ? 'display: none;' : ''; ?>">
                            
                            <div class="srk-robots-label">
                                <strong><?php esc_html_e('Custom Global Robots Settings', 'seo-repair-kit'); ?></strong>
                                <span class="srk-tooltip">
                                    <span class="dashicons dashicons-info"></span>
                                    <span class="srk-tooltip-text">
                                        <?php esc_html_e('Customize robots meta settings for ALL non-singular pages (archives, search, taxonomies). Singular pages use their own settings.', 'seo-repair-kit'); ?>
                                    </span>
                                </span>
                            </div>
                            
                            <div class="srk-robots-checkboxes">
                                
                                <div class="srk-robots-divider"></div>
                                
                                <?php
                                $robots_options = array(
                                    'noindex'      => __('No Index', 'seo-repair-kit'),
                                    'nofollow'     => __('No Follow', 'seo-repair-kit'),
                                    'noarchive'    => __('No Archive', 'seo-repair-kit'),
                                    'notranslate'  => __('No Translate', 'seo-repair-kit'),
                                    'noimageindex' => __('No Image Index', 'seo-repair-kit'),
                                    'nosnippet'    => __('No Snippet', 'seo-repair-kit'),
                                    'noodp'        => __('No ODP', 'seo-repair-kit'),
                                );
                                
                                foreach ($robots_options as $key => $label): ?>
                                <label class="srk-robots-checkbox">
                                    <input type="checkbox" 
                                           name="srk_robots_meta[<?php echo esc_attr($key); ?>]" 
                                           value="1" 
                                           id="srk_robots_<?php echo esc_attr($key); ?>"
                                           <?php checked($display_robots_meta[$key], '1'); ?>>
                                    <span><?php echo esc_html($label); ?></span>
                                </label>
                                <?php endforeach; ?>
                            </div>

                            <!-- Numeric fields -->
                            <div class="srk-robots-preview-fields">
                                <!-- Max Snippet -->
                                <div class="srk-preview-field" id="srk-max-snippet-field">
                                    <label for="srk_max_snippet">
                                        <?php esc_html_e('Max Snippet', 'seo-repair-kit'); ?>
                                    </label>
                                    <input type="number" 
                                           id="srk_max_snippet" 
                                           name="srk_robots_meta[max_snippet]" 
                                           value="<?php echo esc_attr($display_robots_meta['max_snippet']); ?>" 
                                           class="small-text"
                                           min="-1"
                                           step="1">
                                    <span class="srk-field-description"><?php esc_html_e('-1 for unlimited snippet length', 'seo-repair-kit'); ?></span>
                                </div>
                                
                                <!-- Max Video Preview -->
                                <div class="srk-preview-field" id="srk-max-video-preview-field">
                                    <label for="srk_max_video_preview">
                                        <?php esc_html_e('Max Video Preview', 'seo-repair-kit'); ?>
                                    </label>
                                    <input type="number" 
                                           id="srk_max_video_preview" 
                                           name="srk_robots_meta[max_video_preview]" 
                                           value="<?php echo esc_attr($display_robots_meta['max_video_preview']); ?>" 
                                           class="small-text"
                                           min="-1"
                                           step="1">
                                    <span class="srk-field-description"><?php esc_html_e('Seconds, -1 for unlimited video preview', 'seo-repair-kit'); ?></span>
                                </div>
                                
                                <!-- Max Image Preview -->
                                <div class="srk-preview-field" id="srk-max-image-preview-field">
                                    <label for="srk_max_image_preview">
                                        <?php esc_html_e('Max Image Preview', 'seo-repair-kit'); ?>
                                    </label>
                                    <select id="srk_max_image_preview" 
                                            name="srk_robots_meta[max_image_preview]" 
                                            class="srk-select">
                                        <option value="none" <?php selected($display_robots_meta['max_image_preview'], 'none'); ?>>
                                            <?php esc_html_e('None', 'seo-repair-kit'); ?>
                                        </option>
                                        <option value="standard" <?php selected($display_robots_meta['max_image_preview'], 'standard'); ?>>
                                            <?php esc_html_e('Standard', 'seo-repair-kit'); ?>
                                        </option>
                                        <option value="large" <?php selected($display_robots_meta['max_image_preview'], 'large'); ?>>
                                            <?php esc_html_e('Large', 'seo-repair-kit'); ?>
                                        </option>
                                    </select>
                                </div>
                            </div>
                            
                            <!-- Current Robots Meta Preview -->
                            <div class="srk-robots-preview">
                                <strong><?php esc_html_e('Live Robots Meta Preview:', 'seo-repair-kit'); ?></strong>
                                <code id="srk-current-robots-preview"><?php echo esc_html($robots_preview); ?></code>
                                <p style="margin-top: 10px; font-size: 13px; color: #666;">
                                    <?php esc_html_e('This will be output on ALL non-singular pages (archives, search, taxonomies) as:', 'seo-repair-kit'); ?>
                                    <code>&lt;meta name="robots" content="<?php echo esc_html($robots_preview); ?>" /&gt;</code>
                                </p>
                            </div>
                        </div>
                    </div>
                </div>

                <!-- Submit Button -->
                <div class="srk-submit-section">
                    <button type="button" class="srk-reset-button">
                        <?php esc_html_e('Reset to Defaults', 'seo-repair-kit'); ?>
                    </button>
                    <?php submit_button(__('Save Changes', 'seo-repair-kit'), 'primary large', 'submit', false); ?>
                </div>
            </form>
        </div>
        <?php
    }
}

// Initialize
new SRK_Meta_Manager_Advanced();