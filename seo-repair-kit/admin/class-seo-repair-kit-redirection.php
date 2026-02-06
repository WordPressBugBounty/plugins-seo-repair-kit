<?php
if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/**
 * SEO Repair Kit Redirection Class
 *
 * Handles URL redirections with hits tracking.
 *
 * @link       https://seorepairkit.com
 * @since      2.1.0
 * @author     TorontoDigits <support@torontodigits.com>
 */
class SeoRepairKit_Redirection
{
    private $db_srkitredirection;
    private $redirect_types = array(
        301 => 'Moved Permanently',
        302 => 'Found', 
        303 => 'See Other',
        304 => 'Not Modified',
        307 => 'Temporary Redirect',
        308 => 'Permanent Redirect',
        410 => 'Gone'
    );
    private $htaccess_marker = 'SEO Repair Kit Redirections';
    private $htaccess_signature_option = 'srk_redirection_rules_signature';
    
    /**
     * Cache for hit statistics to prevent duplicate queries within same request
     * Static property ensures cache is shared across all instances
     * @var array|null
     */
    private static $cached_hit_statistics = null;
    
    /**
     * Cache for total redirections count to prevent duplicate queries
     * @var int|null
     */
    private static $cached_total_count = null;

    /**
     * Constructor
     */
    public function __construct()
    {
        global $wpdb;
        $this->db_srkitredirection = $wpdb;
        
        // CRITICAL: Always register AJAX handlers - they need to work even if plugin check fails
        // AJAX actions
        add_action( 'wp_ajax_srk_save_redirection', array( $this, 'srk_save_redirection' ) );
        add_action( 'wp_ajax_srk_delete_redirection', array( $this, 'srk_delete_redirection' ) );
        add_action( 'wp_ajax_srk_bulk_action', array( $this, 'srk_bulk_action' ) );
        add_action( 'wp_ajax_srk_reset_hits', array( $this, 'srk_reset_hits' ) );
        add_action( 'wp_ajax_srk_get_hit_stats', array( $this, 'srk_get_hit_stats' ) );
        add_action( 'wp_ajax_srk_export_redirections', array( $this, 'srk_export_redirections' ) );
        add_action( 'wp_ajax_srk_clear_logs', array( $this, 'srk_clear_logs' ) );
        add_action( 'wp_ajax_srk_import_redirections', array( $this, 'srk_import_redirections' ) );
        add_action( 'wp_ajax_srk_migrate_redirections', array( $this, 'srk_migrate_redirections' ) );
        add_action( 'init', array( $this, 'handle_file_download' ) );
        
        // Frontend redirect handling - only if plugin is active
        if ($this->is_plugin_active()) {
            add_action( 'template_redirect', array( $this, 'handle_redirections' ), 1 );
        }
        
        // Admin notices
        add_action( 'admin_notices', array( $this, 'show_migration_notice' ) );
        
        // Register settings for redirection options
        add_action( 'admin_init', array( $this, 'register_redirection_settings' ), 20 );
        add_action( 'admin_init', array( $this, 'ensure_htaccess_rules_seeded' ), 30 );
    }

    /**
     * Ensure .htaccess has the latest media-specific redirection rules
     *
     * @param bool $force
     */
    private function refresh_server_rules($force = false)
    {
        // Only update .htaccess if sync is enabled
        if (get_option('srk_enable_htaccess_sync', 1)) {
            $this->update_htaccess_rules($force);
        }
    }

    /**
     * Build and persist Apache rewrite rules for file-based redirects
     *
     * @param bool $force
     */
    private function update_htaccess_rules($force = false)
    {
        $rules = $this->generate_htaccess_rules();
        $signature = md5(wp_json_encode($rules));
        $stored_signature = get_option($this->htaccess_signature_option, '');

        if (!$force && $signature === $stored_signature) {
            return;
        }

        if ($this->write_htaccess_rules($rules)) {
            update_option($this->htaccess_signature_option, $signature);
        }
    }

    /**
     * Write rewrite rules inside plugin marker
     *
     * @param array $rules
     * @return bool
     */
    private function write_htaccess_rules($rules)
    {
        if (!function_exists('get_home_path')) {
            require_once ABSPATH . 'wp-admin/includes/file.php';
        }
        if (!function_exists('insert_with_markers')) {
            require_once ABSPATH . 'wp-admin/includes/misc.php';
        }

        if (!function_exists('get_home_path') || !function_exists('insert_with_markers')) {
            return false;
        }

        $home_path = get_home_path();
        if (empty($home_path)) {
            $home_path = ABSPATH;
        }

        $htaccess_file = trailingslashit($home_path) . '.htaccess';

        if (!file_exists($htaccess_file)) {
            $handle = @fopen($htaccess_file, 'a');
            if ($handle === false) {
                return false;
            }
            fclose($handle);
        }

        if (!is_writable($htaccess_file)) {
            return false;
        }

        insert_with_markers($htaccess_file, $this->htaccess_marker, $rules);
        return true;
    }

    /**
     * Build rewrite rules array
     *
     * @return array
     */
    private function generate_htaccess_rules()
    {
        $table = $this->db_srkitredirection->prefix . 'srkit_redirection_table';
        $redirections = $this->db_srkitredirection->get_results("SELECT source_url, target_url, redirect_type, is_regex FROM $table WHERE status = 'active'");

        if (empty($redirections)) {
            return array();
        }

        $rules = array();
        foreach ($redirections as $redirection) {
            if (!$this->should_force_server_redirect($redirection->source_url, (int) $redirection->is_regex)) {
                continue;
            }
            $rule = $this->build_htaccess_rule($redirection);
            if ($rule) {
                $rules[] = $rule;
            }
        }

        if (empty($rules)) {
            return array();
        }

        array_unshift($rules, '# Automatically generated by SEO Repair Kit - do not edit manually.');
        return $rules;
    }

    /**
     * Build single rewrite line
     *
     * @param object $redirection
     * @return string
     */
    private function build_htaccess_rule($redirection)
    {
        $pattern = $this->build_htaccess_pattern($redirection->source_url, (int) $redirection->is_regex);
        if (!$pattern) {
            return '';
        }

        $flags = $this->build_htaccess_flags((int) $redirection->redirect_type);
        if (!$flags) {
            return '';
        }

        if ((int) $redirection->redirect_type === 410) {
            return 'RewriteRule ' . $pattern . ' - ' . $flags;
        }

        $target = $this->build_htaccess_target($redirection->target_url);
        if (!$target) {
            return '';
        }

        return 'RewriteRule ' . $pattern . ' ' . $target . ' ' . $flags;
    }

    /**
     * Build .htaccess rule for export purposes (includes all redirects)
     *
     * @param array $redirection
     * @return string
     */
    private function build_htaccess_rule_for_export($redirection)
    {
        $source_url = isset($redirection['source_url']) ? $redirection['source_url'] : '';
        $target_url = isset($redirection['target_url']) ? $redirection['target_url'] : '';
        $redirect_type = isset($redirection['redirect_type']) ? intval($redirection['redirect_type']) : 301;
        $is_regex = isset($redirection['is_regex']) ? intval($redirection['is_regex']) : 0;

        $pattern = $this->build_htaccess_pattern($source_url, $is_regex);
        if (!$pattern) {
            return '';
        }

        if ($redirect_type === 410) {
            return 'RewriteRule ' . $pattern . ' - [G,L,NC]';
        }

        if ($redirect_type === 304) {
            return 'RewriteRule ' . $pattern . ' - [L,NC]';
        }

        $flags = $this->build_htaccess_flags($redirect_type);
        if (!$flags) {
            // Default to 301 if unsupported code provided
            $flags = $this->build_htaccess_flags(301);
        }

        $target = $this->build_htaccess_target($target_url);
        if (!$target) {
            return '';
        }

        return 'RewriteRule ' . $pattern . ' ' . $target . ' ' . $flags;
    }

    /**
     * Build export filename using site name and formatted date
     *
     * @param string $extension Extension without leading dot
     * @return string
     */
    private function build_export_filename($extension)
    {
        $site_name = get_bloginfo('name');
        $site_slug = sanitize_title($site_name);
        if (empty($site_slug)) {
            $site_slug = 'website';
        }

        $date_part = strtolower(str_replace(' ', '-', date_i18n('jS F Y')));
        $date_part = preg_replace('/-+/', '-', $date_part);

        $extension = ltrim($extension, '.');

        return sprintf('srk_redirection-%s-%s.%s', $site_slug, $date_part, $extension);
    }

    /**
     * Build rewrite flags for status code
     *
     * @param int $redirect_type
     * @return string
     */
    private function build_htaccess_flags($redirect_type)
    {
        if ($redirect_type === 410) {
            return '[G,L,NC]';
        }

        $allowed = array(301, 302, 303, 307, 308);
        if (!in_array($redirect_type, $allowed, true)) {
            return '';
        }

        return '[R=' . $redirect_type . ',L,NC]';
    }

    /**
     * Build pattern fragment for rewrite rule
     *
     * @param string $source_url
     * @param int $is_regex
     * @return string
     */
    private function build_htaccess_pattern($source_url, $is_regex)
    {
        if ($is_regex) {
            $pattern = ltrim(trim($source_url), '/');
            if (strpos($pattern, 'http://') === 0 || strpos($pattern, 'https://') === 0 || strpos($pattern, '//') === 0) {
                $parsed = wp_parse_url($pattern);
                if ($parsed && !empty($parsed['path'])) {
                    $pattern = ltrim($parsed['path'], '/');
                }
            }
            return $pattern;
        }

        $path = $this->normalize_source_path($source_url);
        if (!$path) {
            return '';
        }
        $path = $this->strip_query_and_fragment($path);

        return '^' . preg_quote(ltrim($path, '/'), '#') . '$';
    }

    /**
     * Prepare target URL for rewrite output
     *
     * @param string $target_url
     * @return string
     */
    private function build_htaccess_target($target_url)
    {
        if (empty($target_url)) {
            return '';
        }

        $target_url = trim($target_url);

        if (strpos($target_url, 'http://') === 0 || strpos($target_url, 'https://') === 0 || strpos($target_url, '//') === 0) {
            return $target_url;
        }

        return home_url('/' . ltrim($target_url, '/'));
    }

    /**
     * Determine whether we need an .htaccess rule for this source
     *
     * @param string $source_url
     * @param int $is_regex
     * @return bool
     */
    private function should_force_server_redirect($source_url, $is_regex)
    {
        // If "write all" is enabled, write all redirects to .htaccess
        if (get_option('srk_htaccess_write_all', 0)) {
            return true;
        }
        
        // Otherwise, only write regex and media files
        if ($is_regex) {
            return true;
        }

        $path = $this->normalize_source_path($source_url);
        if (!$path) {
            return false;
        }
        $path = $this->strip_query_and_fragment($path);

        $extension = strtolower(pathinfo($path, PATHINFO_EXTENSION));
        if (!$extension) {
            return false;
        }

        $media_extensions = array(
            'jpg','jpeg','jpe','png','gif','bmp','svg','webp','avif',
            'pdf','doc','docx','ppt','pptx','xls','xlsx','csv','txt',
            'mp3','wav','ogg','m4a','flac','aac','mp4','m4v','mov','avi','mkv','webm','ogv','wmv','3gp',
            'zip','rar','7z','tar','gz'
        );

        return in_array($extension, $media_extensions, true);
    }

    /**
     * Normalize source path for pattern comparisons
     *
     * @param string $source_url
     * @return string
     */
    private function normalize_source_path($source_url)
    {
        $source_url = trim($source_url);
        if ($source_url === '') {
            return '';
        }

        if (strpos($source_url, 'http://') === 0 || strpos($source_url, 'https://') === 0 || strpos($source_url, '//') === 0) {
            $parsed = wp_parse_url($source_url);
            if (!$parsed || empty($parsed['path'])) {
                return '';
            }

            $site_host = wp_parse_url(home_url(), PHP_URL_HOST);
            if (!empty($parsed['host']) && !empty($site_host) && strcasecmp($parsed['host'], $site_host) !== 0) {
                return '';
            }

            $path = $parsed['path'];
        } else {
            $path = $source_url;
        }

        $path = urldecode($path);
        $path = preg_replace('#//+#', '/', $path);
        return '/' . ltrim($path, '/');
    }

    /**
     * Remove query string and fragments from a path
     *
     * @param string $path
     * @return string
     */
    private function strip_query_and_fragment($path)
    {
        if (false !== strpos($path, '?')) {
            $path = substr($path, 0, strpos($path, '?'));
        }

        if (false !== strpos($path, '#')) {
            $path = substr($path, 0, strpos($path, '#'));
        }

        return $path;
    }

    /**
     * Register redirection settings
     */
    public function register_redirection_settings()
    {
        // Register the detailed logging setting with sanitization callback
        register_setting('srk_redirection_settings', 'srk_enable_detailed_logging', array(
            'type' => 'boolean',
            'sanitize_callback' => array($this, 'sanitize_boolean_setting'),
            'default' => 1
        ));
        register_setting('srk_redirection_settings', 'srk_enable_htaccess_sync', array(
            'type' => 'boolean',
            'sanitize_callback' => array($this, 'sanitize_boolean_setting'),
            'default' => 1
        ));
        register_setting('srk_redirection_settings', 'srk_htaccess_write_all', array(
            'type' => 'boolean',
            'sanitize_callback' => array($this, 'sanitize_boolean_setting'),
            'default' => 0
        ));
    }

    /**
     * Ensure .htaccess rules are generated at least once after update
     */
    public function ensure_htaccess_rules_seeded()
    {
        if (false === get_option($this->htaccess_signature_option, false)) {
            $this->update_htaccess_rules(true);
        }
    }

    /**
     * Sanitize boolean setting value
     */
    public function sanitize_boolean_setting($value)
    {
        return ($value == '1' || $value === 1 || $value === true || $value === 'on') ? 1 : 0;
    }

    /**
     * Check if plugin is active
     */
    private function is_plugin_active()
    {
        // Always return true since we're inside the plugin
        // This check was preventing AJAX handlers from registering
        return true;
    }

    /**
     * Show migration notice
     */
    public function show_migration_notice()
    {
        if (get_transient('srk_redirection_migration_notice')) {
            $migration_log = get_transient('srk_migration_log');
            $log_text = '';
            
            if ($migration_log && is_array($migration_log)) {
                $log_text = '<br><small>' . esc_html__('Migration details:', 'seo-repair-kit') . ' ' . esc_html(implode(', ', $migration_log)) . '</small>';
            }
            
            echo '<div class="notice notice-success is-dismissible">';
            echo '<p><strong>' . esc_html__('SEO Repair Kit v2.1.0:', 'seo-repair-kit') . '</strong> ';
            echo esc_html__('Your existing data has been successfully migrated to the new enhanced system!', 'seo-repair-kit');
            echo $log_text;
            echo '</p>';
            echo '<p><strong>' . esc_html__('New Features Available:', 'seo-repair-kit') . '</strong></p>';
            echo '<ul style="margin-left: 20px;">';
            echo '<li>' . esc_html__('Enhanced redirection management with hit tracking', 'seo-repair-kit') . '</li>';
            echo '<li>' . esc_html__('Advanced redirect logs and analytics', 'seo-repair-kit') . '</li>';
            echo '<li>' . esc_html__('Import/Export functionality', 'seo-repair-kit') . '</li>';
            echo '<li>' . esc_html__('Regex support for complex redirects', 'seo-repair-kit') . '</li>';
            echo '</ul>';
            echo '</div>';
            
            delete_transient('srk_redirection_migration_notice');
            delete_transient('srk_migration_log');
        }
    }

    /**
     * Enhanced redirection page
     */
    public function seorepairkit_redirection_page()
    {
        // Ensure settings are registered (they should be via admin_init hook, but double-check)
        $this->register_redirection_settings();

        // Enqueue scripts and styles
        wp_enqueue_script( 'srk-redirection-script', plugin_dir_url( __FILE__ ) . 'js/seo-repair-kit-redirection.js', array( 'jquery' ), '2.1.0', true );
        wp_enqueue_style( 'srk-redirection-style', plugin_dir_url( __FILE__ ) . 'css/seo-repair-kit-redirection.css', array(), '2.1.0' );

        // Localize script
        wp_localize_script( 'srk-redirection-script', 'srk_ajax_obj', array( 
            'srkit_redirection_ajax' => admin_url( 'admin-ajax.php' ),
            'srk_save_url_nonce' => wp_create_nonce( 'srk_save_redirection_nonce' ),
            'redirect_types' => $this->redirect_types,
            'srkit_redirection_messages' => array( 
                'srk_fill_fields' => esc_html__( 'Please fill in all required fields.', 'seo-repair-kit' ),
                'srkit_redirection_save_error' => esc_html__( 'Error: Unable to save the redirection.', 'seo-repair-kit' ),
                'srk_confirm_delete' => esc_html__( 'Are you sure you want to delete this redirection?', 'seo-repair-kit' ),
                'srk_delete_error' => esc_html__( 'Error: Unable to delete the record.', 'seo-repair-kit' ),
                'srk_export_success' => esc_html__( 'Export generated successfully.', 'seo-repair-kit' ),
                'srk_import_success' => esc_html__( 'Import completed successfully.', 'seo-repair-kit' )
            )
        ));
        
        ?>
        <div class="seo-repair-kit-redirection">
            <!-- Hero Section -->
            <div class="srk-redirection-hero">
                <div class="srk-redirection-hero-content">
                    <div class="srk-redirection-hero-icon">
                        <span class="dashicons dashicons-migrate"></span>
                    </div>
                    <div class="srk-redirection-hero-text">
                        <h1><?php esc_html_e( 'Advanced Redirections', 'seo-repair-kit' ); ?></h1>
                        <p><?php esc_html_e( 'Create and manage redirects to recover SEO value from 404 errors. Track redirect performance with detailed analytics and preserve your search rankings.', 'seo-repair-kit' ); ?></p>
                        <div class="srk-redirection-hero-badge">
                            <span class="dashicons dashicons-chart-line"></span>
                            <?php esc_html_e( 'SEO OPTIMIZED', 'seo-repair-kit' ); ?>
                        </div>
                    </div>
                </div>
            </div>
            
            <!-- Tabs -->
            <div class="srk-tabs">
                <button type="button" class="srk-tab-btn active" data-tab="srk-tab-redirections"><?php esc_html_e('Redirections', 'seo-repair-kit'); ?></button>
                <button type="button" class="srk-tab-btn" data-tab="srk-tab-logs"><?php esc_html_e('Redirect Logs', 'seo-repair-kit'); ?></button>
                <button type="button" class="srk-tab-btn" data-tab="srk-tab-import-export"><?php esc_html_e('Import/Export', 'seo-repair-kit'); ?></button>
                <button type="button" class="srk-tab-btn" data-tab="srk-tab-settings"><?php esc_html_e('Settings', 'seo-repair-kit'); ?></button>
            </div>

            <!-- Tab: Redirections -->
            <div id="srk-tab-redirections" class="srk-tab-panel active">
            <!-- Hits Statistics Dashboard -->
            <div class="srk-redirection-stats">
                <div class="srk-redirection-stats-header">
                    <h3><?php esc_html_e('Redirect Analytics', 'seo-repair-kit'); ?></h3>
                    <button type="button" class="srk-btn srk-btn-small srk-refresh-stats">
                        <span class="dashicons dashicons-update"></span>
                        <?php esc_html_e('Refresh', 'seo-repair-kit'); ?>
                    </button>
                </div>
                <?php $stats = $this->get_hit_statistics(); ?>
                <div class="srk-redirection-stats-grid">
                    <div class="srk-redirection-stat-card srk-stat-hits">
                        <span class="srk-stat-label"><?php esc_html_e('Total Hits', 'seo-repair-kit'); ?></span>
                        <div class="srk-stat-value"><?php echo number_format($stats['total_hits']); ?></div>
                    </div>
                    <div class="srk-redirection-stat-card srk-stat-total">
                        <span class="srk-stat-label"><?php esc_html_e('Total Redirections', 'seo-repair-kit'); ?></span>
                        <div class="srk-stat-value"><?php echo number_format($stats['total_redirections']); ?></div>
                    </div>
                    <div class="srk-redirection-stat-card srk-stat-active">
                        <span class="srk-stat-label"><?php esc_html_e('Active Redirections', 'seo-repair-kit'); ?></span>
                        <div class="srk-stat-value"><?php echo number_format($stats['active_redirections']); ?></div>
                    </div>
                    <div class="srk-redirection-stat-card srk-stat-popular">
                        <span class="srk-stat-label"><?php esc_html_e('Most Hit Redirect', 'seo-repair-kit'); ?></span>
                        <div class="srk-stat-value">
                            <?php if($stats['most_hit']): ?>
                                <?php echo number_format($stats['most_hit']->hits); ?>
                                <small><?php echo esc_html($stats['most_hit']->source_url); ?></small>
                            <?php else: ?>
                                0
                            <?php endif; ?>
                        </div>
                    </div>
                </div>
            </div>

            <!-- Quick Add Form -->
            <div class="srk-redirection-form-card">
                <div class="srk-redirection-form-header">
                    <div class="srk-redirection-form-title">
                        <span class="dashicons dashicons-plus-alt"></span>
                        <h2><?php esc_html_e('Add New Redirection', 'seo-repair-kit'); ?></h2>
                    </div>
                </div>
                <div class="srk-redirection-form-body">
                <div class="srk-form-row">
                    <div class="srk-form-group">
                        <label for="source_url"><?php esc_html_e('Source URL:', 'seo-repair-kit'); ?></label>
                        <input type="text" id="source_url" name="source_url" placeholder="/old-page/" value="<?php echo isset( $_GET['source_url'] ) ? esc_attr( urldecode( $_GET['source_url'] ) ) : ''; ?>" />
                        <small class="srk-help-text"><?php esc_html_e('Enter the URL to redirect from', 'seo-repair-kit'); ?></small>
                    </div>
                    <div class="srk-form-group">
                        <label for="target_url"><?php esc_html_e('Target URL:', 'seo-repair-kit'); ?></label>
                        <input type="text" id="target_url" name="target_url" placeholder="/new-page/" />
                        <small class="srk-help-text"><?php esc_html_e('Enter the URL to redirect to', 'seo-repair-kit'); ?></small>
                    </div>
                </div>
                
                <div class="srk-form-row">
                    <div class="srk-form-group">
                        <label for="redirect_type"><?php esc_html_e('Redirect Type:', 'seo-repair-kit'); ?></label>
                        <select id="redirect_type" name="redirect_type">
                            <?php foreach($this->redirect_types as $code => $name): ?>
                                <option value="<?php echo $code; ?>" <?php selected($code, 301); ?>>
                                    <?php echo $code . ' - ' . $name; ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="srk-form-group">
                        <label>
                            <input type="checkbox" id="is_regex" name="is_regex" />
                            <?php esc_html_e('Use Regular Expression', 'seo-repair-kit'); ?>
                        </label>
                    </div>
                </div>

                    <div class="srk-form-actions">
                        <button type="button" class="srk-btn srk-btn-primary" id="srk_save_redirection">
                            <?php esc_html_e('Add Redirection', 'seo-repair-kit'); ?>
                        </button>
                    </div>
                </div>
            </div>

            <!-- Redirections List -->
            <div class="srk-redirections-list">
                <div class="srk-list-header">
                    <h3><?php esc_html_e('Existing Redirections', 'seo-repair-kit'); ?></h3>
                    <div class="srk-bulk-actions">
                        <select id="bulk_action">
                            <option value=""><?php esc_html_e('Bulk Actions', 'seo-repair-kit'); ?></option>
                            <option value="activate"><?php esc_html_e('Activate', 'seo-repair-kit'); ?></option>
                            <option value="deactivate"><?php esc_html_e('Deactivate', 'seo-repair-kit'); ?></option>
                            <option value="delete"><?php esc_html_e('Delete', 'seo-repair-kit'); ?></option>
                            <option value="reset_hits"><?php esc_html_e('Reset Hits', 'seo-repair-kit'); ?></option>
                        </select>
                        <button type="button" class="srk-btn srk-btn-secondary" id="srk_apply_bulk"><?php esc_html_e('Apply', 'seo-repair-kit'); ?></button>
                        <button type="button" class="srk-btn srk-btn-danger srk-reset-hits" data-id="">
                            <?php esc_html_e('Reset All Hits', 'seo-repair-kit'); ?>
                        </button>
                    </div>
                </div>
                
                <?php $this->render_redirections_table(); ?>
            </div>
        </div>

            <!-- Tab: Redirect Logs -->
            <div id="srk-tab-logs" class="srk-tab-panel" style="display:none;">
                <div class="srk-list-header">
                    <h3><?php esc_html_e('Redirect Logs', 'seo-repair-kit'); ?></h3>
                    <button type="button" class="srk-btn srk-btn-danger" id="srk_clear_logs"><?php esc_html_e('Clear Logs', 'seo-repair-kit'); ?></button>
                </div>
                <table class="wp-list-table widefat fixed striped">
                    <thead>
                        <tr>
                            <th><?php esc_html_e('Date', 'seo-repair-kit'); ?></th>
                            <th><?php esc_html_e('Action', 'seo-repair-kit'); ?></th>
                            <th><?php esc_html_e('Source URL', 'seo-repair-kit'); ?></th>
                            <th><?php esc_html_e('Target URL', 'seo-repair-kit'); ?></th>
                            <th><?php esc_html_e('IP Address', 'seo-repair-kit'); ?></th>
                            <th><?php esc_html_e('User Agent', 'seo-repair-kit'); ?></th>
                            <th><?php esc_html_e('Referrer', 'seo-repair-kit'); ?></th>
                        </tr>
                    </thead>
                    <tbody>
                    <?php
                        $logs_table = $this->db_srkitredirection->prefix . 'srkit_redirection_logs';
                        $redirections_table = $this->db_srkitredirection->prefix . 'srkit_redirection_table';
                        
                        // Pagination settings for logs
                        $logs_per_page_raw = isset($_GET['srk_logs_per_page']) ? $_GET['srk_logs_per_page'] : 20;
                        $logs_show_all = ($logs_per_page_raw === 'all' || $logs_per_page_raw === '-1');
                        
                        if ($logs_show_all) {
                            $logs_per_page = -1; // Special value for "all"
                            $logs_current_page = 1;
                            $logs_offset = 0;
                        } else {
                            $logs_per_page = intval($logs_per_page_raw);
                            $logs_per_page = max(10, min(100, $logs_per_page)); // Limit between 10 and 100
                            $logs_current_page = isset($_GET['srk_logs_paged']) ? max(1, intval($_GET['srk_logs_paged'])) : 1;
                            $logs_offset = ($logs_current_page - 1) * $logs_per_page;
                        }
                        
                        // Get total count of logs
                        $logs_total_items = $this->db_srkitredirection->get_var("SELECT COUNT(*) FROM $logs_table");
                        $logs_total_pages = $logs_show_all ? 1 : ceil($logs_total_items / $logs_per_page);
                        
                        // Get paginated logs
                        if ($logs_show_all) {
                            // Get all logs without LIMIT
                            $logs = $this->db_srkitredirection->get_results(
                                "SELECT l.created_at, l.action, l.user_agent, l.ip_address, l.referrer, l.url as accessed_url,
                                        r.source_url, r.target_url, l.redirection_id
                                 FROM $logs_table l 
                                 LEFT JOIN $redirections_table r ON l.redirection_id = r.id 
                                 ORDER BY l.created_at DESC"
                            );
                        } else {
                            // Get paginated logs
                            $logs = $this->db_srkitredirection->get_results(
                                $this->db_srkitredirection->prepare(
                                    "SELECT l.created_at, l.action, l.user_agent, l.ip_address, l.referrer, l.url as accessed_url,
                                            r.source_url, r.target_url, l.redirection_id
                                     FROM $logs_table l 
                                     LEFT JOIN $redirections_table r ON l.redirection_id = r.id 
                                     ORDER BY l.created_at DESC LIMIT %d OFFSET %d",
                                    $logs_per_page,
                                    $logs_offset
                                )
                            );
                        }
                        
                        if ($logs) {
                            foreach ($logs as $l) {
                                echo '<tr>';
                                echo '<td>' . esc_html($l->created_at) . '</td>';
                                echo '<td><span class="srk-action-badge srk-action-' . esc_attr($l->action) . '">' . esc_html(ucfirst($l->action)) . '</span></td>';
                                
                                // Handle source URL display
                                if ($l->action === 'redirect') {
                                    // For redirects, show source URL from redirection rule or accessed URL
                                    $source_url = $l->source_url ?: $l->accessed_url;
                                    if (!empty($source_url) && $source_url !== '-') {
                                        echo '<td>' . $this->format_url_as_link($source_url, true) . '</td>';
                                    } else {
                                        echo '<td>' . esc_html($source_url) . '</td>';
                                    }
                                } else {
                                    // For admin actions, parse JSON data
                                    $admin_data = json_decode($l->accessed_url, true);
                                    if ($admin_data && isset($admin_data['source_url'])) {
                                        $source_url = $admin_data['source_url'];
                                        if (!empty($source_url) && $source_url !== '-') {
                                            echo '<td>' . $this->format_url_as_link($source_url, true) . '</td>';
                                        } else {
                                            echo '<td>' . esc_html($source_url) . '</td>';
                                        }
                                    } else {
                                        $accessed_url = $l->accessed_url;
                                        if (!empty($accessed_url) && $accessed_url !== '-' && filter_var($accessed_url, FILTER_VALIDATE_URL)) {
                                            echo '<td>' . $this->format_url_as_link($accessed_url, true) . '</td>';
                                        } else {
                                            echo '<td>' . esc_html($accessed_url) . '</td>';
                                        }
                                    }
                                }
                                
                                // Handle target URL display
                                if ($l->action === 'redirect') {
                                    // For redirects, show target URL from redirection rule
                                    $target_url = $l->target_url ?: '-';
                                    if (!empty($target_url) && $target_url !== '-') {
                                        echo '<td>' . $this->format_url_as_link($target_url, false) . '</td>';
                                    } else {
                                        echo '<td>' . esc_html($target_url) . '</td>';
                                    }
                                } else {
                                    // For admin actions, parse JSON data
                                    $admin_data = json_decode($l->accessed_url, true);
                                    if ($admin_data && isset($admin_data['target_url'])) {
                                        $target_url = $admin_data['target_url'];
                                        if (!empty($target_url) && $target_url !== '-') {
                                            echo '<td>' . $this->format_url_as_link($target_url, false) . '</td>';
                                        } else {
                                            echo '<td>' . esc_html($target_url) . '</td>';
                                        }
                                    } else {
                                        echo '<td>-</td>';
                                    }
                                }
                                
                                echo '<td>' . esc_html($l->ip_address) . '</td>';
                                echo '<td>' . esc_html(substr($l->user_agent, 0, 50)) . (strlen($l->user_agent) > 50 ? '...' : '') . '</td>';
                                echo '<td>' . esc_html($l->referrer) . '</td>';
                                echo '</tr>';
                            }
                        } else {
                            echo '<tr><td colspan="7">' . esc_html__('No redirect logs yet', 'seo-repair-kit') . '</td></tr>';
                        }
                    ?>
                    </tbody>
                </table>
                
                <?php if ($logs_total_items > 0 && ($logs_total_pages > 1 || $logs_show_all)): ?>
                    <div class="srk-pagination-wrapper">
                        <div class="srk-pagination-info">
                            <?php
                            if ($logs_show_all) {
                                printf(
                                    esc_html__('Showing all %1$d log entries', 'seo-repair-kit'),
                                    $logs_total_items
                                );
                            } else {
                                $logs_start = $logs_offset + 1;
                                $logs_end = min($logs_offset + $logs_per_page, $logs_total_items);
                                printf(
                                    esc_html__('Showing %1$d to %2$d of %3$d log entries', 'seo-repair-kit'),
                                    $logs_start,
                                    $logs_end,
                                    $logs_total_items
                                );
                            }
                            ?>
                        </div>
                        
                        <?php if (!$logs_show_all && $logs_total_pages > 1): ?>
                        <div class="srk-pagination">
                            <?php
                            // Build pagination links for logs
                            $logs_base_url = remove_query_arg(array('srk_logs_paged', 'srk_logs_per_page', 'srk_paged', 'srk_per_page'));
                            $logs_base_url = add_query_arg('srk_logs_per_page', $logs_per_page, $logs_base_url);
                            // Add tab parameter to maintain active tab
                            $logs_base_url = add_query_arg('tab', 'logs', $logs_base_url);
                            
                            // Previous button
                            if ($logs_current_page > 1):
                                $logs_prev_url = add_query_arg('srk_logs_paged', $logs_current_page - 1, $logs_base_url);
                                ?>
                                <a href="<?php echo esc_url($logs_prev_url); ?>" class="srk-pagination-link srk-pagination-prev" title="<?php esc_attr_e('Previous page', 'seo-repair-kit'); ?>">
                                    <span class="srk-pagination-arrow">‹</span>
                                    <?php esc_html_e('Previous', 'seo-repair-kit'); ?>
                                </a>
                            <?php else: ?>
                                <span class="srk-pagination-link srk-pagination-disabled">
                                    <span class="srk-pagination-arrow">‹</span>
                                    <?php esc_html_e('Previous', 'seo-repair-kit'); ?>
                                </span>
                            <?php endif; ?>
                            
                            <div class="srk-pagination-pages">
                                <?php
                                // Calculate page range to show
                                $logs_range = 2;
                                
                                // Show first page
                                if ($logs_current_page > $logs_range + 1):
                                    $logs_first_url = add_query_arg('srk_logs_paged', 1, $logs_base_url);
                                    ?>
                                    <a href="<?php echo esc_url($logs_first_url); ?>" class="srk-pagination-page">1</a>
                                    <?php if ($logs_current_page > $logs_range + 2): ?>
                                        <span class="srk-pagination-dots">...</span>
                                    <?php endif; ?>
                                <?php endif; ?>
                                
                                <?php
                                // Show page numbers around current page
                                for ($i = max(1, $logs_current_page - $logs_range); $i <= min($logs_total_pages, $logs_current_page + $logs_range); $i++) {
                                    if ($i == $logs_current_page) {
                                        echo '<span class="srk-pagination-page srk-pagination-current">' . $i . '</span>';
                                    } else {
                                        $logs_page_url = add_query_arg('srk_logs_paged', $i, $logs_base_url);
                                        echo '<a href="' . esc_url($logs_page_url) . '" class="srk-pagination-page">' . $i . '</a>';
                                    }
                                }
                                ?>
                                
                                <?php
                                // Show last page
                                if ($logs_current_page < $logs_total_pages - $logs_range):
                                    if ($logs_current_page < $logs_total_pages - $logs_range - 1):
                                        ?>
                                        <span class="srk-pagination-dots">...</span>
                                    <?php endif;
                                    $logs_last_url = add_query_arg('srk_logs_paged', $logs_total_pages, $logs_base_url);
                                    ?>
                                    <a href="<?php echo esc_url($logs_last_url); ?>" class="srk-pagination-page"><?php echo $logs_total_pages; ?></a>
                                <?php endif; ?>
                            </div>
                            
                            <?php
                            // Next button
                            if ($logs_current_page < $logs_total_pages):
                                $logs_next_url = add_query_arg('srk_logs_paged', $logs_current_page + 1, $logs_base_url);
                                ?>
                                <a href="<?php echo esc_url($logs_next_url); ?>" class="srk-pagination-link srk-pagination-next" title="<?php esc_attr_e('Next page', 'seo-repair-kit'); ?>">
                                    <?php esc_html_e('Next', 'seo-repair-kit'); ?>
                                    <span class="srk-pagination-arrow">›</span>
                                </a>
                            <?php else: ?>
                                <span class="srk-pagination-link srk-pagination-disabled">
                                    <?php esc_html_e('Next', 'seo-repair-kit'); ?>
                                    <span class="srk-pagination-arrow">›</span>
                                </span>
                            <?php endif; ?>
                        </div>
                        <?php endif; ?>
                        
                        <div class="srk-pagination-per-page">
                            <label for="srk_logs_per_page_select"><?php esc_html_e('Per page:', 'seo-repair-kit'); ?></label>
                            <select id="srk_logs_per_page_select" class="srk-per-page-select">
                                <option value="10" <?php selected($logs_per_page, 10); ?>>10</option>
                                <option value="20" <?php selected($logs_per_page, 20); ?>>20</option>
                                <option value="50" <?php selected($logs_per_page, 50); ?>>50</option>
                                <option value="100" <?php selected($logs_per_page, 100); ?>>100</option>
                                <option value="all" <?php selected($logs_show_all, true); ?>><?php esc_html_e('All', 'seo-repair-kit'); ?></option>
                            </select>
                        </div>
                    </div>
                <?php endif; ?>
            </div>

            <!-- Tab: Import/Export -->
            <div id="srk-tab-import-export" class="srk-tab-panel" style="display:none;">
                <div class="srk-import-export-container">
                    
                    <!-- Export Section -->
                    <div class="srk-export-section">
                        <div class="srk-section-header">
                            <div class="srk-section-icon">
                                <svg width="24" height="24" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                                    <path d="M21 15v4a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2v-4"></path>
                                    <polyline points="7,10 12,15 17,10"></polyline>
                                    <line x1="12" y1="15" x2="12" y2="3"></line>
                                </svg>
                            </div>
                            <div class="srk-section-content">
                                <h3><?php esc_html_e('Export Redirections', 'seo-repair-kit'); ?></h3>
                                <p class="srk-section-description"><?php esc_html_e('Download your redirections in CSV, JSON, Nginx, or Apache .htaccess format for backup, migration, or server configuration.', 'seo-repair-kit'); ?></p>
                            </div>
                        </div>
                        
                        <div class="srk-export-form">
                            <div class="srk-form-group">
                                <label for="export_format" class="srk-form-label">
                                    <span class="srk-label-text"><?php esc_html_e('Export Format', 'seo-repair-kit'); ?></span>
                                    <span class="srk-label-description"><?php esc_html_e('Choose the file format for your export', 'seo-repair-kit'); ?></span>
                                </label>
                                <div class="srk-format-selector">
                                    <label class="srk-format-option">
                                        <input type="radio" name="export_format" value="csv" checked>
                                        <div class="srk-format-card">
                                            <div class="srk-format-icon">📋</div>
                                            <div class="srk-format-info">
                                                <span class="srk-format-name">CSV</span>
                                                <span class="srk-format-desc"><?php esc_html_e('Simple text format', 'seo-repair-kit'); ?></span>
                                            </div>
                                        </div>
                                    </label>
                                    <label class="srk-format-option">
                                        <input type="radio" name="export_format" value="json">
                                        <div class="srk-format-card">
                                            <div class="srk-format-icon">🔧</div>
                                            <div class="srk-format-info">
                                                <span class="srk-format-name">JSON</span>
                                                <span class="srk-format-desc"><?php esc_html_e('Developer friendly', 'seo-repair-kit'); ?></span>
                                            </div>
                                        </div>
                                    </label>
                                    <label class="srk-format-option">
                                        <input type="radio" name="export_format" value="nginx">
                                        <div class="srk-format-card">
                                            <div class="srk-format-icon">⚙️</div>
                                            <div class="srk-format-info">
                                                <span class="srk-format-name">Nginx</span>
                                                <span class="srk-format-desc"><?php esc_html_e('Nginx config file', 'seo-repair-kit'); ?></span>
                                            </div>
                                        </div>
                                    </label>
                                    <label class="srk-format-option">
                                        <input type="radio" name="export_format" value="htaccess">
                                        <div class="srk-format-card">
                                            <div class="srk-format-icon">🛡️</div>
                                            <div class="srk-format-info">
                                                <span class="srk-format-name"><?php esc_html_e('Apache .htaccess', 'seo-repair-kit'); ?></span>
                                                <span class="srk-format-desc"><?php esc_html_e('Drop-in rewrite rules', 'seo-repair-kit'); ?></span>
                                            </div>
                                        </div>
                                    </label>
                                </div>
                            </div>
                            
                            <div class="srk-export-actions">
                                <button type="button" class="srk-btn srk-btn-primary srk-btn-export" id="srk_export_redirections">
                                    <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                                        <path d="M21 15v4a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2v-4"></path>
                                        <polyline points="7,10 12,15 17,10"></polyline>
                                        <line x1="12" y1="15" x2="12" y2="3"></line>
                                    </svg>
                                    <?php esc_html_e('Export Redirections', 'seo-repair-kit'); ?>
                                </button>
                            </div>
                        </div>
                    </div>

                    <!-- Divider -->
                    <div class="srk-section-divider">
                        <span class="srk-divider-text"><?php esc_html_e('OR', 'seo-repair-kit'); ?></span>
                    </div>

                    <!-- Import Section -->
                    <div class="srk-import-section">
                        <div class="srk-section-header">
                            <div class="srk-section-icon">
                                <svg width="24" height="24" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                                    <path d="M21 2l-2 2m-7.61 7.61a5.5 5.5 0 1 1-7.778 7.778 5.5 5.5 0 0 1 7.777-7.777zm0 0L15.5 7.5m0 0l3 3L22 7l-3-3m-3.5 3.5L19 4"></path>
                                </svg>
                            </div>
                            <div class="srk-section-content">
                                <h3><?php esc_html_e('Import Redirections', 'seo-repair-kit'); ?></h3>
                                <p class="srk-section-description"><?php esc_html_e('Upload a CSV, JSON, or Apache .htaccess file to import redirections. Existing redirections can be updated or skipped.', 'seo-repair-kit'); ?></p>
                            </div>
                        </div>
                        
                        <div class="srk-import-form">
                            <div class="srk-file-upload-area" id="srk_file_upload_area" onclick="document.getElementById('import_file').click();">
                                <div class="srk-upload-content">
                                    <div class="srk-upload-icon">
                                        <svg width="48" height="48" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5">
                                            <path d="M21 15v4a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2v-4"></path>
                                            <polyline points="17,8 12,3 7,8"></polyline>
                                            <line x1="12" y1="3" x2="12" y2="15"></line>
                                        </svg>
                                    </div>
                                    <div class="srk-upload-text">
                                        <h4><?php esc_html_e('Choose Files to Upload', 'seo-repair-kit'); ?></h4>
                                        <p><?php esc_html_e('Drag and drop your files here, or click to browse. You can select multiple files.', 'seo-repair-kit'); ?></p>
                                        <div class="srk-file-types">
                                            <span class="srk-file-type">CSV</span>
                                            <span class="srk-file-type">JSON</span>
                                        </div>
                                    </div>
                                </div>
                                <input type="file" id="import_file" accept=".csv,.json,.htaccess,.conf,.txt" multiple style="display: none;" onchange="handleFileSelection(this);">
                            </div>
                            
                            <div class="srk-file-info" id="srk_file_info" style="display: none;">
                                <div id="srk_file_list"></div>
                                <button type="button" class="srk-btn-remove" id="srk_remove_file">×</button>
                            </div>
                            
                            <div class="srk-import-options">
                                <div class="srk-form-group">
                                    <label class="srk-checkbox-label">
                                        <input type="checkbox" id="import_overwrite">
                                        <span class="srk-checkbox-custom"></span>
                                        <div class="srk-checkbox-content">
                                            <span class="srk-checkbox-title"><?php esc_html_e('Update Existing Redirections', 'seo-repair-kit'); ?></span>
                                            <span class="srk-checkbox-description"><?php esc_html_e('If checked, existing redirections with matching IDs will be updated. Otherwise, they will be skipped.', 'seo-repair-kit'); ?></span>
                                        </div>
                                    </label>
                                </div>
                            </div>
                            
                            <div class="srk-import-actions">
                                <button type="button" class="srk-btn srk-btn-primary srk-btn-import" id="srk_import_redirections" disabled>
                                    <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                                        <path d="M21 2l-2 2m-7.61 7.61a5.5 5.5 0 1 1-7.778 7.778 5.5 5.5 0 0 1 7.777-7.777zm0 0L15.5 7.5m0 0l3 3L22 7l-3-3m-3.5 3.5L19 4"></path>
                                    </svg>
                                    <?php esc_html_e('Import Redirections', 'seo-repair-kit'); ?>
                                </button>
                            </div>
                        </div>
                    </div>

                    <!-- Help Section -->
                    <div class="srk-help-section">
                        <div class="srk-help-header">
                            <svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                                <circle cx="12" cy="12" r="10"></circle>
                                <path d="M9.09 9a3 3 0 0 1 5.83 1c0 2-3 3-3 3"></path>
                                <line x1="12" y1="17" x2="12.01" y2="17"></line>
                            </svg>
                            <h4><?php esc_html_e('Need Help?', 'seo-repair-kit'); ?></h4>
                        </div>
                        <div class="srk-help-content">
                            <div class="srk-help-item">
                                <strong><?php esc_html_e('Supported Formats:', 'seo-repair-kit'); ?></strong>
                                <p><?php esc_html_e('CSV (.csv), JSON (.json), and Apache .htaccess (.htaccess, .conf, .txt) files are supported. CSV files should have columns: source_url, target_url, redirect_type, status, is_regex.', 'seo-repair-kit'); ?></p>
                            </div>
                            <div class="srk-help-item">
                                <strong><?php esc_html_e('JSON Format:', 'seo-repair-kit'); ?></strong>
                                <p><?php esc_html_e('JSON files should contain an array of redirection objects with the same field names.', 'seo-repair-kit'); ?></p>
                            </div>
                            <div class="srk-help-item">
                                <strong><?php esc_html_e('.htaccess Format:', 'seo-repair-kit'); ?></strong>
                                <p><?php esc_html_e('You can import standard Apache rules such as Redirect, RedirectMatch, and RewriteRule statements. Each rule will be converted into an SRK redirection entry.', 'seo-repair-kit'); ?></p>
                            </div>
                            <div class="srk-help-item">
                                <strong><?php esc_html_e('File Size Limit:', 'seo-repair-kit'); ?></strong>
                                <p><?php esc_html_e('Maximum file size is 10MB. For larger files, consider splitting into multiple imports.', 'seo-repair-kit'); ?></p>
                            </div>
                        </div>
                    </div>
                </div>
            </div>

            <!-- Tab: Settings -->
            <div id="srk-tab-settings" class="srk-tab-panel" style="display:none;">
                <h3><?php esc_html_e('Redirection Settings', 'seo-repair-kit'); ?></h3>
                
                <form method="post" action="options.php">
                    <?php settings_fields('srk_redirection_settings'); ?>
                    
                    <table class="form-table">
                        <tr>
                            <th scope="row"><?php esc_html_e('Detailed Logging', 'seo-repair-kit'); ?></th>
                            <td>
                                <label>
                                    <input type="checkbox" name="srk_enable_detailed_logging" value="1" <?php checked(get_option('srk_enable_detailed_logging', 1)); ?> />
                                    <?php esc_html_e('Log every redirect hit with visitor details', 'seo-repair-kit'); ?>
                                </label>
                                <p class="description"><?php esc_html_e('When enabled, logs every redirect with IP, User Agent, and Referrer. Admin changes (create, update, delete, import) are always logged regardless of this setting.', 'seo-repair-kit'); ?></p>
                            </td>
                        </tr>
                        
                        <tr>
                            <th scope="row"><?php esc_html_e('.htaccess Sync', 'seo-repair-kit'); ?></th>
                            <td>
                                <label>
                                    <input type="checkbox" name="srk_enable_htaccess_sync" value="1" <?php checked(get_option('srk_enable_htaccess_sync', 1)); ?> />
                                    <?php esc_html_e('Enable automatic .htaccess rule generation', 'seo-repair-kit'); ?>
                                </label>
                                <p class="description"><?php esc_html_e('When enabled, automatically writes redirect rules to .htaccess file for Apache/LiteSpeed servers. Disable if you prefer PHP-only redirects or use Nginx.', 'seo-repair-kit'); ?></p>
                            </td>
                        </tr>
                        
                        <tr>
                            <th scope="row"><?php esc_html_e('Write All Redirects to .htaccess', 'seo-repair-kit'); ?></th>
                            <td>
                                <label>
                                    <input type="checkbox" name="srk_htaccess_write_all" value="1" <?php checked(get_option('srk_htaccess_write_all', 0)); ?> />
                                    <?php esc_html_e('Write all redirects to .htaccess (not just regex and media files)', 'seo-repair-kit'); ?>
                                </label>
                                <p class="description"><?php esc_html_e('By default, only regex patterns and media file redirects are written to .htaccess. Enable this to write all redirects to .htaccess for better performance.', 'seo-repair-kit'); ?></p>
                            </td>
                        </tr>
                    </table>
                    
                    <button type="submit" class="srk-btn srk-btn-primary">
                        <?php esc_html_e('Save Settings', 'seo-repair-kit'); ?>
                    </button>
                </form>
                
                <hr style="margin: 20px 0;">
                
                <h4><?php esc_html_e('Current Logging Status', 'seo-repair-kit'); ?></h4>
                <p>
                    <strong><?php esc_html_e('Detailed Logging:', 'seo-repair-kit'); ?></strong> 
                    <?php echo get_option('srk_enable_detailed_logging', 1) ? '<span style="color: green;">' . esc_html__('Enabled', 'seo-repair-kit') . '</span>' : '<span style="color: red;">' . esc_html__('Disabled', 'seo-repair-kit') . '</span>'; ?>
                </p>
                <p class="description">
                    <?php if (get_option('srk_enable_detailed_logging', 1)): ?>
                        <?php esc_html_e('All redirect hits are being logged with visitor details.', 'seo-repair-kit'); ?>
                    <?php else: ?>
                        <?php esc_html_e('Redirect hit logging is disabled. Admin changes are logged separately.', 'seo-repair-kit'); ?>
                    <?php endif; ?>
                </p>
                
                <hr style="margin: 30px 0;">
                
                <!-- Migration Section -->
                <div class="srk-migration-section" style="background: #f8f9fa; border: 1px solid #e1e5e9; border-radius: 8px; padding: 20px; margin-top: 30px;">
                    <h4><?php esc_html_e('Database Migration', 'seo-repair-kit'); ?></h4>
                    <p><?php esc_html_e('If you have redirection records from a previous version of the plugin, you can migrate them to the new Advanced Redirection system.', 'seo-repair-kit'); ?></p>
                    
                    <?php
                    // Check if migration is needed
                    global $wpdb;
                    $table_name = $wpdb->prefix . 'srkit_redirection_table';
                    $needs_migration = false;
                    $migration_status = '';
                    
                    if ( $wpdb->get_var( $wpdb->prepare( "SHOW TABLES LIKE %s", $table_name ) ) === $table_name ) {
                        $columns = $wpdb->get_results( "SHOW COLUMNS FROM $table_name" );
                        if ( $columns ) {
                            $column_names = array_column( $columns, 'Field' );
                            $has_old_schema = in_array( 'old_url', $column_names ) && in_array( 'new_url', $column_names );
                            $has_new_schema = in_array( 'source_url', $column_names ) && in_array( 'target_url', $column_names );
                            
                            if ( $has_old_schema && !$has_new_schema ) {
                                $needs_migration = true;
                                $old_count = $wpdb->get_var( "SELECT COUNT(*) FROM $table_name" );
                                $migration_status = sprintf( esc_html__( 'Found %d records from old version that need migration.', 'seo-repair-kit' ), $old_count );
                            } elseif ( $has_old_schema && $has_new_schema ) {
                                $unmigrated = $wpdb->get_var( 
                                    "SELECT COUNT(*) FROM $table_name 
                                     WHERE (source_url IS NULL OR source_url = '') 
                                     AND old_url IS NOT NULL AND old_url != ''"
                                );
                                if ( $unmigrated > 0 ) {
                                    $needs_migration = true;
                                    $migration_status = sprintf( esc_html__( 'Found %d unmigrated records.', 'seo-repair-kit' ), $unmigrated );
                                } else {
                                    $migration_status = esc_html__( 'All records have been migrated successfully.', 'seo-repair-kit' );
                                }
                            } else {
                                $migration_status = esc_html__( 'No migration needed - database is up to date.', 'seo-repair-kit' );
                            }
                        }
                    } else {
                        $migration_status = esc_html__( 'No redirection table found - no migration needed.', 'seo-repair-kit' );
                    }
                    ?>
                    
                    <div class="srk-migration-status" style="margin: 15px 0;">
                        <strong><?php esc_html_e('Status:', 'seo-repair-kit'); ?></strong> 
                        <span id="srk_migration_status_text"><?php echo esc_html( $migration_status ); ?></span>
                    </div>
                    
                    <button type="button" class="srk-btn srk-btn-primary" id="srk_manual_migrate" <?php echo $needs_migration ? '' : 'disabled'; ?>>
                        <?php esc_html_e('Run Migration Now', 'seo-repair-kit'); ?>
                    </button>
                    <span id="srk_migration_spinner" class="spinner" style="float: none; margin-left: 10px; display: none;"></span>
                    
                    <div id="srk_migration_result" style="margin-top: 15px; display: none;"></div>
                </div>
            </div>

            <style>
            .srk-action-badge {
                display: inline-block;
                padding: 2px 8px;
                border-radius: 3px;
                font-size: 11px;
                font-weight: bold;
                text-transform: uppercase;
            }
            .srk-action-redirect {
                background-color: #0073aa;
                color: white;
            }
            .srk-action-created {
                background-color: #46b450;
                color: white;
            }
            .srk-action-updated {
                background-color: #ffb900;
                color: white;
            }
            .srk-action-deleted {
                background-color: #dc3232;
                color: white;
            }
            </style>
            <script>
            (function(){
                var buttons = document.querySelectorAll('.srk-tab-btn');
                var panels = document.querySelectorAll('.srk-tab-panel');
                function showTab(id){
                    panels.forEach(function(p){ p.style.display = (p.id === id ? '' : 'none'); p.classList.toggle('active', p.id === id); });
                    buttons.forEach(function(b){ b.classList.toggle('active', b.getAttribute('data-tab') === id); });
                }
                buttons.forEach(function(btn){
                    btn.addEventListener('click', function(){ showTab(btn.getAttribute('data-tab')); });
                });
            })();
            </script>
            
            <!-- Simple Import/Export JavaScript -->
            <script>
            // Simple file selection handler - supports multiple files
            function handleFileSelection(input) {
                if (input.files.length > 0) {
                    var files = Array.from(input.files);
                    var validFiles = [];
                    var invalidFiles = [];
                    var validTypes = ['.csv', '.json', '.htaccess', '.conf', '.txt'];
                    var maxSize = 10 * 1024 * 1024; // 10MB
                    
                    // Validate each file
                    files.forEach(function(file) {
                        var fileExtension = '.' + file.name.split('.').pop().toLowerCase();
                        
                    if (!validTypes.includes(fileExtension)) {
                            invalidFiles.push(file.name + ' (invalid type - must be CSV, JSON, or .htaccess)');
                            return;
                        }
                        
                        if (file.size > maxSize) {
                            invalidFiles.push(file.name + ' (exceeds 10MB limit)');
                            return;
                        }
                        
                        validFiles.push(file);
                    });
                    
                    // Show errors for invalid files
                    if (invalidFiles.length > 0) {
                        alert('Some files were invalid:\n' + invalidFiles.join('\n'));
                    }
                    
                    // If no valid files, reset
                    if (validFiles.length === 0) {
                        input.value = '';
                        return;
                    }
                    
                    // Show file list
                    var fileListHtml = '';
                    validFiles.forEach(function(file, index) {
                        fileListHtml += '<div class="srk-file-details" style="margin-bottom: 8px;">';
                        fileListHtml += '<div class="srk-file-icon">📄</div>';
                        fileListHtml += '<div style="flex: 1;">';
                        fileListHtml += '<div class="srk-file-name">' + file.name + '</div>';
                        fileListHtml += '<div class="srk-file-size">' + formatFileSize(file.size) + '</div>';
                        fileListHtml += '</div>';
                        fileListHtml += '</div>';
                    });
                    
                    document.getElementById('srk_file_list').innerHTML = fileListHtml;
                    document.getElementById('srk_file_upload_area').style.display = 'none';
                    document.getElementById('srk_file_info').style.display = 'flex';
                    document.getElementById('srk_import_redirections').disabled = false;
                }
            }

            // Format file size
            function formatFileSize(bytes) {
                if (bytes === 0) return '0 Bytes';
                var k = 1024;
                var sizes = ['Bytes', 'KB', 'MB', 'GB'];
                var i = Math.floor(Math.log(bytes) / Math.log(k));
                return parseFloat((bytes / Math.pow(k, i)).toFixed(2)) + ' ' + sizes[i];
            }

            // Remove file(s)
            function removeFile() {
                document.getElementById('import_file').value = '';
                document.getElementById('srk_file_info').style.display = 'none';
                document.getElementById('srk_file_upload_area').style.display = 'block';
                document.getElementById('srk_import_redirections').disabled = true;
            }

            // Note: Export and Import functions are handled by seo-repair-kit-redirection.js
            // No need to duplicate event handlers here to prevent double execution
            </script>
            
        <style>
        .srk-tabs{display:flex;gap:10px;margin:10px 0 20px}
        .srk-tab-btn{background:#eef1f7;border:1px solid #d8deea;color:#0b1d51;padding:8px 14px;border-radius:6px;cursor:pointer}
        .srk-tab-btn.active{background:#0b1d51;color:#fff;border-color:#0b1d51}
        .srk-tab-panel{animation:fadeIn .15s ease-in}
        @keyframes fadeIn{from{opacity:0}to{opacity:1}}
        
        /* Import/Export Professional Styles */
        .srk-import-export-container {
            max-width: 60%;
            margin: 0 auto;
        }
        
        .srk-export-section, .srk-import-section {
            background: #fff;
            border: 1px solid #e1e5e9;
            border-radius: 12px;
            padding: 24px;
            margin-bottom: 24px;
            box-shadow: 0 2px 8px rgba(0,0,0,0.04);
        }
        
        .srk-section-header {
            display: flex;
            align-items: flex-start;
            gap: 16px;
            margin-bottom: 24px;
        }
        
        .srk-section-icon {
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
            color: white;
            width: 48px;
            height: 48px;
            border-radius: 12px;
            display: flex;
            align-items: center;
            justify-content: center;
            flex-shrink: 0;
        }
        
        .srk-section-content h3 {
            margin: 0 0 8px 0;
            font-size: 20px;
            font-weight: 600;
            color: #1a202c;
        }
        
        .srk-section-description {
            margin: 0;
            color: #64748b;
            font-size: 14px;
            line-height: 1.5;
        }
        
        .srk-format-selector {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(180px, 1fr));
            gap: 16px;
            margin-top: 16px;
        }
        
        .srk-format-option {
            cursor: pointer;
        }
        
        .srk-format-option input[type="radio"] {
            display: none;
        }
        
        .srk-format-card {
            border: 2px solid #e2e8f0;
            border-radius: 8px;
            padding: 16px;
            text-align: center;
            transition: all 0.2s ease;
            background: #fff;
        }
        
        .srk-format-option input[type="radio"]:checked + .srk-format-card {
            border-color: #3b82f6;
            background: #eff6ff;
            box-shadow: 0 0 0 3px rgba(59, 130, 246, 0.1);
        }
        
        .srk-format-icon {
            font-size: 24px;
            margin-bottom: 8px;
        }
        
        .srk-format-name {
            display: block;
            font-weight: 600;
            color: #1e293b;
            margin-bottom: 4px;
        }
        
        .srk-format-desc {
            font-size: 12px;
            color: #64748b;
        }
        
        .srk-file-upload-area {
            border: 2px dashed #cbd5e1;
            border-radius: 12px;
            padding: 40px 20px;
            text-align: center;
            background: #f8fafc;
            transition: all 0.3s ease;
            cursor: pointer;
            margin-bottom: 20px;
        }
        
        .srk-file-upload-area:hover {
            border-color: #3b82f6;
            background: #eff6ff;
        }
        
        .srk-file-upload-area.dragover {
            border-color: #3b82f6;
            background: #eff6ff;
            transform: scale(1.02);
        }
        
        .srk-upload-icon {
            color: #94a3b8;
            margin-bottom: 16px;
        }
        
        .srk-upload-text h4 {
            margin: 0 0 8px 0;
            color: #1e293b;
            font-size: 16px;
        }
        
        .srk-upload-text p {
            margin: 0 0 12px 0;
            color: #64748b;
            font-size: 14px;
        }
        
        .srk-file-types {
            display: flex;
            gap: 8px;
            justify-content: center;
        }
        
        .srk-file-type {
            background: #e2e8f0;
            color: #475569;
            padding: 4px 8px;
            border-radius: 4px;
            font-size: 12px;
            font-weight: 500;
        }
        
        .srk-file-info {
            background: #f1f5f9;
            border: 1px solid #e2e8f0;
            border-radius: 8px;
            padding: 16px;
            display: flex;
            flex-direction: column;
            margin-bottom: 20px;
            max-height: 300px;
            overflow-y: auto;
        }
        
        .srk-file-info #srk_file_list {
            flex: 1;
            min-width: 0;
        }
        
        .srk-file-info #srk_remove_file {
            align-self: flex-end;
            margin-top: 10px;
        }
        
        .srk-file-details {
            display: flex;
            align-items: center;
            gap: 12px;
        }
        
        .srk-file-icon {
            font-size: 20px;
        }
        
        .srk-file-name {
            font-weight: 500;
            color: #1e293b;
        }
        
        .srk-file-size {
            font-size: 12px;
            color: #64748b;
        }
        
        .srk-btn-remove {
            background: #ef4444;
            color: white;
            border: none;
            border-radius: 50%;
            width: 24px;
            height: 24px;
            cursor: pointer;
            font-size: 16px;
            line-height: 1;
        }
        
        .srk-checkbox-label {
            display: flex;
            align-items: flex-start;
            gap: 12px;
            cursor: pointer;
            padding: 16px;
            border: 1px solid #e2e8f0;
            border-radius: 8px;
            background: #fff;
            transition: all 0.2s ease;
        }
        
        .srk-checkbox-label:hover {
            border-color: #3b82f6;
            background: #f8fafc;
        }
        
        .srk-checkbox-label input[type="checkbox"] {
            display: none;
        }
        
        .srk-checkbox-custom {
            width: 20px;
            height: 20px;
            border: 2px solid #d1d5db;
            border-radius: 4px;
            display: flex;
            align-items: center;
            justify-content: center;
            flex-shrink: 0;
            margin-top: 2px;
            transition: all 0.2s ease;
        }
        
        .srk-checkbox-label input[type="checkbox"]:checked + .srk-checkbox-custom {
            background: #3b82f6;
            border-color: #3b82f6;
        }
        
        .srk-checkbox-label input[type="checkbox"]:checked + .srk-checkbox-custom::after {
            content: '✓';
            color: white;
            font-size: 12px;
            font-weight: bold;
        }
        
        .srk-checkbox-title {
            display: block;
            font-weight: 500;
            color: #1e293b;
            margin-bottom: 4px;
        }
        
        .srk-checkbox-description {
            font-size: 13px;
            color: #64748b;
            line-height: 1.4;
        }
        
        .srk-export-actions, .srk-import-actions {
            margin-top: 24px;
        }
        
        /* Import/Export specific button styles - match Add Redirection button */
        .srk-import-export-container .srk-btn {
            background: linear-gradient(135deg, #0b1d51, #1e3a8a);
            color: white;
            border: none;
            padding: 12px 24px;
            border-radius: 8px;
            font-weight: 500;
            cursor: pointer;
            display: inline-flex;
            align-items: center;
            gap: 8px;
            transition: all 0.2s ease;
            text-decoration: none;
        }
        
        .srk-import-export-container .srk-btn:hover {
            background: linear-gradient(135deg, #1e3a8a, #0b1d51);
            transform: translateY(-1px);
            box-shadow: 0 4px 8px rgba(11, 29, 81, 0.3);
        }
        
        .srk-import-export-container .srk-btn:disabled {
            background: #94a3b8;
            cursor: not-allowed;
            transform: none;
            box-shadow: none;
        }
        
        .srk-section-divider {
            text-align: center;
            margin: 32px 0;
            position: relative;
        }
        
        .srk-section-divider::before {
            content: '';
            position: absolute;
            top: 50%;
            left: 0;
            right: 0;
            height: 1px;
            background: #e2e8f0;
        }
        
        .srk-divider-text {
            background: #fff;
            color: #64748b;
            padding: 0 16px;
            font-weight: 500;
            font-size: 14px;
        }
        
        .srk-help-section {
            background: #f8fafc;
            border: 1px solid #e2e8f0;
            border-radius: 12px;
            padding: 20px;
            margin-top: 24px;
        }
        
        .srk-help-header {
            display: flex;
            align-items: center;
            gap: 8px;
            margin-bottom: 16px;
        }
        
        .srk-help-header svg {
            color: #3b82f6;
        }
        
        .srk-help-header h4 {
            margin: 0;
            color: #1e293b;
            font-size: 16px;
        }
        
        .srk-help-item {
            margin-bottom: 12px;
        }
        
        .srk-help-item:last-child {
            margin-bottom: 0;
        }
        
        .srk-help-item strong {
            color: #1e293b;
            font-size: 13px;
        }
        
        .srk-help-item p {
            margin: 4px 0 0 0;
            color: #64748b;
            font-size: 13px;
            line-height: 1.4;
        }
        
        /* Table layout fixes */
        .srk-redirections-table {
            table-layout: fixed;
            width: 100%;
            word-wrap: break-word;
        }
        
        /* URL column constraints */
        .srk-redirections-table th:nth-child(2),
        .srk-redirections-table td:nth-child(2),
        .srk-redirections-table th:nth-child(3),
        .srk-redirections-table td:nth-child(3) {
            min-width: 200px;
            word-wrap: break-word;
            word-break: break-all;
            overflow-wrap: break-word;
        }
        
        /* Clickable URL links in table */
        .srk-source-url-link,
        .srk-target-url-link {
            color: #3b82f6;
            text-decoration: none;
            font-weight: 500;
            transition: color 0.2s ease;
            display: inline-block;
            word-wrap: break-word;
            word-break: break-all;
            overflow-wrap: break-word;
            vertical-align: middle;
            line-height: 1.4;
        }
        
        .srk-source-url-link:hover,
        .srk-target-url-link:hover {
            color: #2563eb;
            text-decoration: underline;
        }
        
        .srk-source-url-link:visited,
        .srk-target-url-link:visited {
            color: #7c3aed;
        }
        
        /* Table cell styling for URL columns */
        .srk-redirections-table td:nth-child(2),
        .srk-redirections-table td:nth-child(3) {
            position: relative;
            padding: 8px 12px;
            vertical-align: top;
            word-wrap: break-word;
            word-break: break-all;
            overflow-wrap: break-word;
        }
        
        /* Ensure table cells handle overflow properly */
        .srk-redirections-table td {
            word-wrap: break-word;
            overflow-wrap: break-word;
        }
        
        /* Actions column should stay narrow */
        .srk-redirections-table th:last-child,
        .srk-redirections-table td:last-child {
            width: 200px;
            min-width: 200px;
            white-space: nowrap;
        }
        
        /* Checkbox column */
        .srk-redirections-table th:first-child,
        .srk-redirections-table td:first-child {
            width: 40px;
            min-width: 40px;
            max-width: 40px;
        }
        
        /* Type and Status columns */
        .srk-redirections-table th:nth-child(4),
        .srk-redirections-table td:nth-child(4),
        .srk-redirections-table th:nth-child(5),
        .srk-redirections-table td:nth-child(5) {
            width: 80px;
            min-width: 80px;
            max-width: 80px;
            text-align: center;
        }
        
        /* Hits column */
        .srk-redirections-table th:nth-child(6),
        .srk-redirections-table td:nth-child(6) {
            width: 60px;
            min-width: 60px;
            max-width: 60px;
            text-align: center;
        }
        </style>
        </div>
        <?php
    }

    /**
     * Format URL as clickable link
     */
    private function format_url_as_link($url, $is_source = true)
    {
        if (empty($url) || $url === '-') {
            return '<strong>' . esc_html($url) . '</strong>';
        }
        
        // Determine if it's a full URL
        $is_full_url = (strpos($url, 'http://') === 0 || strpos($url, 'https://') === 0 || strpos($url, '//') === 0);
        
        // Build the full URL
        if ($is_full_url) {
            $full_url = $url;
        } else {
            // Relative URL - convert to full URL
            $full_url = home_url($url);
        }
        
        // Escape the URL for href attribute
        $escaped_url = esc_url($full_url);
        
        // Create clickable link with full URL in title for accessibility
        $link_text = esc_html($url);
        $link_class = $is_source ? 'srk-source-url-link' : 'srk-target-url-link';
        $title_text = esc_attr($url) . ' - ' . esc_attr__('Click to open in new window', 'seo-repair-kit');
        
        return '<a href="' . $escaped_url . '" target="_blank" rel="noopener noreferrer" class="' . $link_class . '" title="' . $title_text . '">' . $link_text . '</a>';
    }

    /**
     * Render redirections table with pagination
     */
    private function render_redirections_table()
    {
        $redirections_table = $this->db_srkitredirection->prefix . 'srkit_redirection_table';
        
        // Pagination settings
        $per_page_raw = isset($_GET['srk_per_page']) ? $_GET['srk_per_page'] : 20;
        $show_all = ($per_page_raw === 'all' || $per_page_raw === '-1');
        
        if ($show_all) {
            $per_page = -1; // Special value for "all"
            $current_page = 1;
            $offset = 0;
        } else {
            $per_page = intval($per_page_raw);
            $per_page = max(10, min(100, $per_page)); // Limit between 10 and 100
            $current_page = isset($_GET['srk_paged']) ? max(1, intval($_GET['srk_paged'])) : 1;
            $offset = ($current_page - 1) * $per_page;
        }
        
        // Get total count (use cached value if available to prevent duplicate query)
        if ( null !== self::$cached_total_count ) {
            $total_items = self::$cached_total_count;
        } else {
            $total_items = $this->db_srkitredirection->get_var("SELECT COUNT(*) FROM $redirections_table");
            self::$cached_total_count = $total_items;
        }
        $total_pages = $show_all ? 1 : ceil($total_items / $per_page);
        
        // Get paginated results
        if ($show_all) {
            // Get all records without LIMIT
            $redirections = $this->db_srkitredirection->get_results(
                "SELECT * FROM $redirections_table ORDER BY created_at DESC"
            );
        } else {
            // Get paginated results
            $redirections = $this->db_srkitredirection->get_results(
                $this->db_srkitredirection->prepare(
                    "SELECT * FROM $redirections_table ORDER BY created_at DESC LIMIT %d OFFSET %d",
                    $per_page,
                    $offset
                )
            );
        }
        
        if($redirections || $total_items > 0): ?>
            <table class="wp-list-table widefat fixed striped srk-redirections-table">
                <thead>
                    <tr>
                        <th><input type="checkbox" id="select_all_redirections" /></th>
                        <th><?php esc_html_e('Source URL', 'seo-repair-kit'); ?></th>
                        <th><?php esc_html_e('Target URL', 'seo-repair-kit'); ?></th>
                        <th><?php esc_html_e('Type', 'seo-repair-kit'); ?></th>
                        <th><?php esc_html_e('Status', 'seo-repair-kit'); ?></th>
                        <th><?php esc_html_e('Hits', 'seo-repair-kit'); ?></th>
                        <th><?php esc_html_e('Actions', 'seo-repair-kit'); ?></th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach($redirections as $redirection): ?>
                        <tr data-redirection-id="<?php echo $redirection->id; ?>" 
                            data-source-url="<?php echo esc_attr($redirection->source_url); ?>"
                            data-target-url="<?php echo esc_attr($redirection->target_url); ?>"
                            data-redirect-type="<?php echo esc_attr($redirection->redirect_type); ?>"
                            data-is-regex="<?php echo $redirection->is_regex ? '1' : '0'; ?>"
                            data-status="<?php echo esc_attr($redirection->status); ?>">
                            <td><input type="checkbox" class="srk-redirection-checkbox" value="<?php echo $redirection->id; ?>" /></td>
                            <td>
                                <?php 
                                $source_url = $redirection->source_url;
                                if ($redirection->is_regex) {
                                    // For regex URLs, don't make them clickable
                                    echo '<strong>' . esc_html($source_url) . '</strong>';
                                    echo '<span class="srk-regex-badge">' . esc_html__('Regex', 'seo-repair-kit') . '</span>';
                                } else {
                                    // Make source URL clickable
                                    echo $this->format_url_as_link($source_url, true);
                                }
                                ?>
                            </td>
                            <td>
                                <?php 
                                if ($redirection->redirect_type == 410) {
                                    echo '<span style="color: #6c757d; font-style: italic;">' . esc_html__('N/A (410 Gone)', 'seo-repair-kit') . '</span>';
                                } else {
                                    $target_url = $redirection->target_url ?: '-';
                                    if ($target_url !== '-') {
                                        // Make target URL clickable
                                        echo $this->format_url_as_link($target_url, false);
                                    } else {
                                        echo esc_html($target_url);
                                    }
                                }
                                ?>
                            </td>
                            <td>
                                <span class="srk-redirect-type srk-type-<?php echo $redirection->redirect_type; ?>">
                                    <?php echo $redirection->redirect_type; ?>
                                </span>
                            </td>
                            <td>
                                <span class="srk-status srk-status-<?php echo $redirection->status; ?>">
                                    <?php echo ucfirst($redirection->status); ?>
                                </span>
                            </td>
                            <td><?php echo $redirection->hits; ?></td>
                            <td>
                                <button type="button" class="srk-btn srk-btn-small srk-edit-redirection" data-id="<?php echo $redirection->id; ?>">
                                    <?php esc_html_e('Edit', 'seo-repair-kit'); ?>
                                </button>
                                <button type="button" class="srk-btn srk-btn-small srk-delete-redirection" data-id="<?php echo $redirection->id; ?>">
                                    <?php esc_html_e('Delete', 'seo-repair-kit'); ?>
                                </button>
                                <button type="button" class="srk-btn srk-btn-small srk-reset-hits" data-id="<?php echo $redirection->id; ?>">
                                    <?php esc_html_e('Reset Hits', 'seo-repair-kit'); ?>
                                </button>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
            
            <?php if ($total_pages > 1 || $show_all): ?>
                <div class="srk-pagination-wrapper">
                    <div class="srk-pagination-info">
                        <?php
                        if ($show_all) {
                            printf(
                                esc_html__('Showing all %1$d redirections', 'seo-repair-kit'),
                                $total_items
                            );
                        } else {
                            $start = $offset + 1;
                            $end = min($offset + $per_page, $total_items);
                            printf(
                                esc_html__('Showing %1$d to %2$d of %3$d redirections', 'seo-repair-kit'),
                                $start,
                                $end,
                                $total_items
                            );
                        }
                        ?>
                    </div>
                    
                    <?php if (!$show_all && $total_pages > 1): ?>
                    <div class="srk-pagination">
                        <?php
                        // Build pagination links
                        $base_url = remove_query_arg(array('srk_paged', 'srk_per_page'));
                        $base_url = add_query_arg('srk_per_page', $per_page, $base_url);
                        
                        // Previous button
                        if ($current_page > 1):
                            $prev_url = add_query_arg('srk_paged', $current_page - 1, $base_url);
                            ?>
                            <a href="<?php echo esc_url($prev_url); ?>" class="srk-pagination-link srk-pagination-prev" title="<?php esc_attr_e('Previous page', 'seo-repair-kit'); ?>">
                                <span class="srk-pagination-arrow">‹</span>
                                <?php esc_html_e('Previous', 'seo-repair-kit'); ?>
                            </a>
                        <?php else: ?>
                            <span class="srk-pagination-link srk-pagination-disabled">
                                <span class="srk-pagination-arrow">‹</span>
                                <?php esc_html_e('Previous', 'seo-repair-kit'); ?>
                            </span>
                        <?php endif; ?>
                        
                        <div class="srk-pagination-pages">
                            <?php
                            // Calculate page range to show
                            $range = 2;
                            $show_dots = true;
                            
                            // Show first page
                            if ($current_page > $range + 1):
                                $first_url = add_query_arg('srk_paged', 1, $base_url);
                                ?>
                                <a href="<?php echo esc_url($first_url); ?>" class="srk-pagination-page">1</a>
                                <?php if ($current_page > $range + 2): ?>
                                    <span class="srk-pagination-dots">...</span>
                                <?php endif; ?>
                            <?php endif; ?>
                            
                            <?php
                            // Show page numbers around current page
                            for ($i = max(1, $current_page - $range); $i <= min($total_pages, $current_page + $range); $i++) {
                                if ($i == $current_page) {
                                    echo '<span class="srk-pagination-page srk-pagination-current">' . $i . '</span>';
                                } else {
                                    $page_url = add_query_arg('srk_paged', $i, $base_url);
                                    echo '<a href="' . esc_url($page_url) . '" class="srk-pagination-page">' . $i . '</a>';
                                }
                            }
                            ?>
                            
                            <?php
                            // Show last page
                            if ($current_page < $total_pages - $range):
                                if ($current_page < $total_pages - $range - 1):
                                    ?>
                                    <span class="srk-pagination-dots">...</span>
                                <?php endif;
                                $last_url = add_query_arg('srk_paged', $total_pages, $base_url);
                                ?>
                                <a href="<?php echo esc_url($last_url); ?>" class="srk-pagination-page"><?php echo $total_pages; ?></a>
                            <?php endif; ?>
                        </div>
                        
                        <?php
                        // Next button
                        if ($current_page < $total_pages):
                            $next_url = add_query_arg('srk_paged', $current_page + 1, $base_url);
                            ?>
                            <a href="<?php echo esc_url($next_url); ?>" class="srk-pagination-link srk-pagination-next" title="<?php esc_attr_e('Next page', 'seo-repair-kit'); ?>">
                                <?php esc_html_e('Next', 'seo-repair-kit'); ?>
                                <span class="srk-pagination-arrow">›</span>
                            </a>
                        <?php else: ?>
                            <span class="srk-pagination-link srk-pagination-disabled">
                                <?php esc_html_e('Next', 'seo-repair-kit'); ?>
                                <span class="srk-pagination-arrow">›</span>
                            </span>
                        <?php endif; ?>
                    </div>
                    <?php endif; ?>
                    
                    <div class="srk-pagination-per-page">
                        <label for="srk_per_page_select"><?php esc_html_e('Per page:', 'seo-repair-kit'); ?></label>
                        <select id="srk_per_page_select" class="srk-per-page-select">
                            <option value="10" <?php selected($per_page, 10); ?>>10</option>
                            <option value="20" <?php selected($per_page, 20); ?>>20</option>
                            <option value="50" <?php selected($per_page, 50); ?>>50</option>
                            <option value="100" <?php selected($per_page, 100); ?>>100</option>
                            <option value="all" <?php selected($show_all, true); ?>><?php esc_html_e('All', 'seo-repair-kit'); ?></option>
                        </select>
                    </div>
                </div>
            <?php endif; ?>
        <?php else: ?>
            <p><?php esc_html_e('No redirections found. Create your first redirection above.', 'seo-repair-kit'); ?></p>
        <?php endif;
    }

    /**
     * Enhanced redirect handling with hits tracking
     */
    public function handle_redirections()
    {
        // CRITICAL: Only work if plugin is active
        if (!$this->is_plugin_active()) {
            return;
        }

        if (is_admin()) {
            return;
        }

        $current_url = $this->get_current_url();
        $redirections_table = $this->db_srkitredirection->prefix . 'srkit_redirection_table';
        
        // Get all active redirections
        $redirections = $this->db_srkitredirection->get_results(
            "SELECT * FROM $redirections_table WHERE status = 'active' ORDER BY position ASC"
        );

        foreach ($redirections as $redirection) {
            if ($this->matches_redirection($current_url, $redirection)) {
                // Increment hits BEFORE redirecting
                $this->increment_redirection_hits($redirection->id);
                
                // Log redirect events only if detailed logging is enabled
                if (get_option('srk_enable_detailed_logging', 1)) {
                    $this->log_redirect_event($redirection->id, $current_url);
                }
                
                // Perform redirect
                $this->perform_redirect($redirection, $current_url);
                exit;
            }
        }
    }

    /**
     * Check if current URL matches redirection
     */
    private function matches_redirection($current_url, $redirection)
    {
        $source_url = $redirection->source_url;
        
        if ($redirection->is_regex) {
            // Regex matching - test pattern validity first
            $pattern = '#' . $source_url . '#i';
            if (@preg_match($pattern, $current_url)) {
                return true;
            }
        } else {
            // Exact matching - compare full URLs
            if ($current_url === $source_url) {
                return true;
            }
            
            // Path matching - compare URL paths (ignoring query strings and fragments)
            if ($this->url_path_matches($current_url, $source_url)) {
                return true;
            }
            
            // Relative URL matching - if source_url is relative, check against current path
            if (strpos($source_url, 'http') !== 0) {
                $current_path = parse_url($current_url, PHP_URL_PATH);
                $source_path = $source_url;
                
                // Remove leading slash for comparison if needed
                $current_path = ltrim($current_path, '/');
                $source_path = ltrim($source_path, '/');
                
                if ($current_path === $source_path || '/' . $current_path === '/' . $source_path) {
                    return true;
                }
            }
        }
        
        return false;
    }

    /**
     * Check URL path matching (ignoring query strings and fragments)
     */
    private function url_path_matches($current_url, $source_url)
    {
        $current_path = parse_url($current_url, PHP_URL_PATH);
        $source_path = parse_url($source_url, PHP_URL_PATH);
        
        // Normalize paths
        $current_path = rtrim($current_path ?: '/', '/');
        $source_path = rtrim($source_path ?: '/', '/');
        
        // Exact path match
        if ($current_path === $source_path) {
            return true;
        }
        
        // Handle both with and without leading slash
        if ('/' . ltrim($current_path, '/') === '/' . ltrim($source_path, '/')) {
            return true;
        }
        
        return false;
    }

    /**
     * Perform the actual redirect
     */
    private function perform_redirect($redirection, $current_url)
    {
        $target_url = $redirection->target_url;
        $redirect_code = intval($redirection->redirect_type);
        
        // Special handling for 304 (Not Modified): do not send Location header
        if ($redirect_code === 304) {
            status_header(304);
            nocache_headers();
            exit;
        }
        
        // Special handling for 410 (Gone): return 410 status without redirecting
        if ($redirect_code === 410) {
            status_header(410);
            nocache_headers();
            // Return a simple 410 response
            header('Content-Type: text/html; charset=UTF-8');
            echo '<!DOCTYPE html><html><head><title>410 Gone</title></head><body><h1>Gone</h1><p>The requested resource is no longer available.</p></body></html>';
            exit;
        }
        
        // For redirects that need a target URL, validate it exists
        if (empty($target_url)) {
            // If no target URL and not 410/304, return 404
            status_header(404);
            exit;
        }
        
        // Handle regex replacements
        if ($redirection->is_regex) {
            // Use a delimiter that's unlikely to be in the pattern
            // If source_url contains '#', use '~' as delimiter
            $delimiter = (strpos($redirection->source_url, '#') !== false) ? '~' : '#';
            $pattern = $delimiter . $redirection->source_url . $delimiter . 'i';
            $target_url = preg_replace($pattern, $target_url, $current_url);
        }
        
        // Make URL absolute if relative
        if (strpos($target_url, 'http') !== 0 && strpos($target_url, '//') !== 0) {
            $target_url = home_url($target_url);
        }
        
        // WordPress wp_redirect supports: 301, 302, 303, 307
        // For 308, we need to set headers manually
        if ($redirect_code === 308) {
            // 308 Permanent Redirect - preserve method
            status_header(308);
            header('Location: ' . $target_url, true, 308);
            nocache_headers();
            exit;
        }
        
        // Use WordPress wp_redirect for standard redirect codes
        wp_redirect($target_url, $redirect_code);
        exit;
    }

    /**
     * Increment redirection hit count
     */
    private function increment_redirection_hits($redirection_id)
    {
        $redirections_table = $this->db_srkitredirection->prefix . 'srkit_redirection_table';
        
        $this->db_srkitredirection->query(
            $this->db_srkitredirection->prepare(
                "UPDATE $redirections_table 
                 SET hits = hits + 1, last_hit = NOW() 
                 WHERE id = %d",
                $redirection_id
            )
        );
    }

    /**
     * Log redirect event into srkit_redirection_logs
     */
    private function log_redirect_event($redirection_id, $url)
    {
        $logs_table = $this->db_srkitredirection->prefix . 'srkit_redirection_logs';
        
        $result = $this->db_srkitredirection->insert(
            $logs_table,
            array(
                'redirection_id' => intval($redirection_id),
                'action' => 'redirect',
                'url' => $url,
                'user_agent' => isset($_SERVER['HTTP_USER_AGENT']) ? sanitize_text_field($_SERVER['HTTP_USER_AGENT']) : '',
                'ip_address' => isset($_SERVER['REMOTE_ADDR']) ? sanitize_text_field($_SERVER['REMOTE_ADDR']) : '',
                'referrer' => isset($_SERVER['HTTP_REFERER']) ? sanitize_text_field($_SERVER['HTTP_REFERER']) : '',
                'created_at' => current_time('mysql')
            )
        );
    }

    /**
     * Log redirection change event (create/update/delete/import) into srkit_redirection_logs
     * CRUD operations are always logged regardless of logging setting
     */
    private function log_redirection_change_event($data, $action)
    {
        $logs_table = $this->db_srkitredirection->prefix . 'srkit_redirection_logs';
        
        // For admin actions, store both source and target URLs as JSON
        $log_data = array(
            'source_url' => isset($data['source_url']) ? $data['source_url'] : 'N/A',
            'target_url' => isset($data['target_url']) ? $data['target_url'] : 'N/A'
        );
        
        // Determine referrer based on action
        $referrer = 'WordPress Admin';
        if ($action === 'imported' || $action === 'import_updated') {
            $referrer = 'Import Operation';
        }
        
        $this->db_srkitredirection->insert(
            $logs_table,
            array(
                'redirection_id' => null, // No specific redirect ID for admin changes
                'action' => $action,
                'url' => json_encode($log_data), // Store both URLs as JSON
                'user_agent' => 'Admin Action',
                'ip_address' => isset($_SERVER['REMOTE_ADDR']) ? sanitize_text_field($_SERVER['REMOTE_ADDR']) : '',
                'referrer' => $referrer,
                'created_at' => current_time('mysql')
            )
        );
    }


    /**
     * Get current URL
     */
    private function get_current_url()
    {
        $protocol = is_ssl() ? 'https://' : 'http://';
        return $protocol . $_SERVER['HTTP_HOST'] . $_SERVER['REQUEST_URI'];
    }

    /**
     * AJAX: Save redirection
     */
    public function srk_save_redirection()
    {
        // Check user permissions
        if (!current_user_can('manage_options')) {
            wp_send_json_error('Insufficient permissions');
            return;
        }

        // Check nonce
        if (!isset($_POST['srkit_redirection_nonce'])) {
            wp_send_json_error('Security nonce missing');
            return;
        }

        if (!wp_verify_nonce($_POST['srkit_redirection_nonce'], 'srk_save_redirection_nonce')) {
            wp_send_json_error('Security check failed - nonce verification failed');
            return;
        }

        // Validate required fields
        if (!isset($_POST['source_url']) || trim($_POST['source_url']) === '') {
            wp_send_json_error('Source URL is required');
            return;
        }

        $redirections_table = $this->db_srkitredirection->prefix . 'srkit_redirection_table';
        
        // Verify table exists
        $table_exists = $this->db_srkitredirection->get_var($this->db_srkitredirection->prepare("SHOW TABLES LIKE %s", $redirections_table));
        if ($table_exists !== $redirections_table) {
            wp_send_json_error('Redirection table does not exist. Please deactivate and reactivate the plugin.');
            return;
        }
        
        $redirect_type = isset($_POST['redirect_type']) ? intval($_POST['redirect_type']) : 301;
        $target_url = isset($_POST['target_url']) ? trim(sanitize_text_field($_POST['target_url'])) : '';
        
        // For 410 (Gone), target_url is optional - use empty string if not provided
        if ($redirect_type === 410 && empty($target_url)) {
            $target_url = '';
        } elseif ($redirect_type !== 410 && empty($target_url)) {
            // For other redirect types, target_url is required
            wp_send_json_error('Target URL is required');
            return;
        }
        
        $source_url = trim(sanitize_text_field($_POST['source_url']));
        $is_regex = isset($_POST['is_regex']) && ($_POST['is_regex'] == '1' || $_POST['is_regex'] === 1 || $_POST['is_regex'] === true) ? 1 : 0;
        
        // Get status from POST, default to 'active' if not provided
        $status = isset($_POST['status']) ? sanitize_text_field($_POST['status']) : 'active';
        if (!in_array($status, array('active', 'inactive'))) {
            $status = 'active';
        }
        
        // Server-side regex validation
        if ($is_regex) {
            // Test if the regex pattern is valid
            $test_pattern = '#' . $source_url . '#i';
            if (@preg_match($test_pattern, '') === false) {
                $error = error_get_last();
                wp_send_json_error('Invalid regular expression pattern: ' . ($error ? $error['message'] : 'Pattern syntax error'));
                return;
            }
        }
        
        // Validate redirect type is in allowed list
        $allowed_types = array(301, 302, 303, 304, 307, 308, 410);
        if (!in_array($redirect_type, $allowed_types)) {
            wp_send_json_error('Invalid redirect type. Allowed types: ' . implode(', ', $allowed_types));
            return;
        }
        
        // Clear any previous database errors
        $this->db_srkitredirection->last_error = '';
        
        // Check if this is an update operation - get redirection_id from POST
        $redirection_id = isset($_POST['redirection_id']) ? $_POST['redirection_id'] : '';
        // Convert to integer, ensuring we get a valid number
        $redirection_id = intval($redirection_id);
        
        if ($redirection_id > 0) {
            // Verify the redirection exists before updating
            $existing_redirection = $this->db_srkitredirection->get_row(
                $this->db_srkitredirection->prepare(
                    "SELECT id, source_url FROM $redirections_table WHERE id = %d",
                    $redirection_id
                )
            );
            
            if (!$existing_redirection) {
                wp_send_json_error('Redirection not found. Cannot update non-existent redirection.');
                return;
            }
            
            // Check if source_url is already used by another redirection (prevent duplicates)
            $duplicate_check = $this->db_srkitredirection->get_var(
                $this->db_srkitredirection->prepare(
                    "SELECT id FROM $redirections_table WHERE source_url = %s AND id != %d LIMIT 1",
                    $source_url,
                    $redirection_id
                )
            );
            
            if ($duplicate_check) {
                wp_send_json_error('Source URL is already used by another redirection. Please use a different source URL.');
                return;
            }
            
            // Update existing - don't include created_at
            $data = array(
                'source_url' => $source_url,
                'target_url' => $target_url,
                'redirect_type' => $redirect_type,
                'status' => $status,
                'is_regex' => $is_regex,
                'updated_at' => current_time('mysql')
            );

            $result = $this->db_srkitredirection->update(
                $redirections_table,
                $data,
                array('id' => $redirection_id),
                array('%s', '%s', '%d', '%s', '%d', '%s'),
                array('%d')
            );
            
            // Check for database errors
            if ($result === false || !empty($this->db_srkitredirection->last_error)) {
                $error_msg = !empty($this->db_srkitredirection->last_error) 
                    ? $this->db_srkitredirection->last_error 
                    : 'Update operation failed';
                wp_send_json_error('Database error: ' . $error_msg);
                return;
            }
            
            // Verify the update actually changed rows (result should be > 0 for successful update)
            if ($result === 0) {
                // No rows were updated - this could mean the data is the same or the ID doesn't exist
                // Check if the record still exists
                $still_exists = $this->db_srkitredirection->get_var(
                    $this->db_srkitredirection->prepare(
                        "SELECT COUNT(*) FROM $redirections_table WHERE id = %d",
                        $redirection_id
                    )
                );
                
                if ($still_exists > 0) {
                    // Record exists but no changes were made - still consider it success
                    $this->refresh_server_rules();
                    wp_send_json_success('Redirection updated successfully (no changes detected)');
                    return;
                } else {
                    wp_send_json_error('Redirection was not found during update. Please refresh and try again.');
                    return;
                }
            }
            
            // Clear cached statistics after data modification
            self::clear_hit_statistics_cache();
            
            // Log redirection update event (always log CRUD operations)
            $this->log_redirection_change_event($data, 'updated');
            
            $this->refresh_server_rules();
            wp_send_json_success('Redirection updated successfully');
        } else {
            // Insert new - check for duplicate source_url first
            $duplicate_check = $this->db_srkitredirection->get_var(
                $this->db_srkitredirection->prepare(
                    "SELECT id FROM $redirections_table WHERE source_url = %s LIMIT 1",
                    $source_url
                )
            );
            
            if ($duplicate_check) {
                wp_send_json_error('Source URL already exists. Please use a different source URL or edit the existing redirection.');
                return;
            }
            
            // Insert new - include created_at
            $data = array(
                'source_url' => $source_url,
                'target_url' => $target_url,
                'redirect_type' => $redirect_type,
                'status' => $status,
                'is_regex' => $is_regex,
                'position' => 0,
                'hits' => 0,
                'created_at' => current_time('mysql'),
                'updated_at' => current_time('mysql')
            );
            
            $result = $this->db_srkitredirection->insert($redirections_table, $data);
            
            // Check for database errors
            if ($result === false || !empty($this->db_srkitredirection->last_error)) {
                $error_msg = !empty($this->db_srkitredirection->last_error) 
                    ? $this->db_srkitredirection->last_error 
                    : 'Insert operation failed';
                wp_send_json_error('Database error: ' . $error_msg);
                return;
            }
            
            // Clear cached statistics after data modification
            self::clear_hit_statistics_cache();
            
            // Log redirection creation event (always log CRUD operations)
            $this->log_redirection_change_event($data, 'created');
            
            $this->refresh_server_rules();
            wp_send_json_success('Redirection created successfully');
        }
    }

    /**
     * AJAX: Delete redirection
     */
    public function srk_delete_redirection()
    {
        // Check user permissions
        if (!current_user_can('manage_options')) {
            wp_send_json_error('Insufficient permissions');
            return;
        }
        
        if (!wp_verify_nonce($_POST['srkit_redirection_nonce'], 'srk_save_redirection_nonce')) {
            wp_die('Security check failed');
        }

        $redirections_table = $this->db_srkitredirection->prefix . 'srkit_redirection_table';
        $redirection_id = intval($_POST['redirection_id']);

        $result = $this->db_srkitredirection->delete(
            $redirections_table,
            array('id' => $redirection_id),
            array('%d')
        );

        if ($result) {
            // Clear cached statistics after data modification
            self::clear_hit_statistics_cache();
            
            // Log redirection deletion event
            $this->log_redirection_change_event(array('source_url' => 'DELETED', 'target_url' => 'DELETED'), 'deleted');
            
            $this->refresh_server_rules();
            wp_send_json_success('Redirection deleted successfully');
        } else {
            wp_send_json_error('Failed to delete redirection');
        }
    }

    /**
     * AJAX: Bulk actions
     */
    public function srk_bulk_action()
    {
        // Check user permissions
        if (!current_user_can('manage_options')) {
            wp_send_json_error('Insufficient permissions');
            return;
        }
        
        if (!wp_verify_nonce($_POST['srkit_redirection_nonce'], 'srk_save_redirection_nonce')) {
            wp_die('Security check failed');
        }

        // Validate input
        if (!isset($_POST['bulk_action']) || empty($_POST['bulk_action'])) {
            wp_send_json_error('Invalid action');
            return;
        }
        
        if (!isset($_POST['redirection_ids']) || !is_array($_POST['redirection_ids'])) {
            wp_send_json_error('Invalid redirection IDs');
            return;
        }

        $action = sanitize_text_field($_POST['bulk_action']);
        $redirection_ids = array_map('intval', $_POST['redirection_ids']);
        
        // Filter out invalid IDs and ensure they're positive
        $redirection_ids = array_filter($redirection_ids, function($id) {
            return $id > 0;
        });
        
        // Check if array is empty after filtering
        if (empty($redirection_ids)) {
            wp_send_json_error('No valid redirections selected');
            return;
        }
        
        $redirections_table = $this->db_srkitredirection->prefix . 'srkit_redirection_table';
        
        // Initialize result
        $result = false;
        
        switch ($action) {
            case 'activate':
                $result = $this->db_srkitredirection->query(
                    "UPDATE $redirections_table SET status = 'active' WHERE id IN (" . implode(',', $redirection_ids) . ")"
                );
                break;
            case 'deactivate':
                $result = $this->db_srkitredirection->query(
                    "UPDATE $redirections_table SET status = 'inactive' WHERE id IN (" . implode(',', $redirection_ids) . ")"
                );
                break;
            case 'delete':
                $result = $this->db_srkitredirection->query(
                    "DELETE FROM $redirections_table WHERE id IN (" . implode(',', $redirection_ids) . ")"
                );
                break;
            case 'reset_hits':
                $result = $this->db_srkitredirection->query(
                    "UPDATE $redirections_table SET hits = 0, last_hit = NULL WHERE id IN (" . implode(',', $redirection_ids) . ")"
                );
                break;
            default:
                wp_send_json_error('Invalid action');
                return;
        }

        if ($result !== false) {
            // Clear cached statistics after data modification
            self::clear_hit_statistics_cache();
            
            if (in_array($action, array('activate', 'deactivate', 'delete'), true)) {
                $this->refresh_server_rules();
            }
            wp_send_json_success('Bulk action completed successfully');
        } else {
            wp_send_json_error('Failed to perform bulk action');
        }
    }

    /**
     * AJAX: Reset hit counts
     */
    public function srk_reset_hits()
    {
        // Check user permissions
        if (!current_user_can('manage_options')) {
            wp_send_json_error('Insufficient permissions');
            return;
        }
        
        if (!wp_verify_nonce($_POST['srkit_redirection_nonce'], 'srk_save_redirection_nonce')) {
            wp_die('Security check failed');
        }

        $redirections_table = $this->db_srkitredirection->prefix . 'srkit_redirection_table';
        
        if (isset($_POST['redirection_id']) && $_POST['redirection_id']) {
            // Reset specific redirection hits
            $redirection_id = intval($_POST['redirection_id']);
            $result = $this->db_srkitredirection->update(
                $redirections_table,
                array('hits' => 0, 'last_hit' => null),
                array('id' => $redirection_id),
                array('%d', '%s'),
                array('%d')
            );
        } else {
            // Reset all redirection hits
            $result = $this->db_srkitredirection->query(
                "UPDATE $redirections_table SET hits = 0, last_hit = NULL"
            );
        }

        if ($result !== false) {
            // Clear cached statistics after data modification
            self::clear_hit_statistics_cache();
            
            wp_send_json_success('Hit counts reset successfully');
        } else {
            wp_send_json_error('Failed to reset hit counts');
        }
    }

    /**
     * AJAX: Get hit statistics
     */
    public function srk_get_hit_stats()
    {
        // Check user permissions
        if (!current_user_can('manage_options')) {
            wp_send_json_error('Insufficient permissions');
            return;
        }
        
        if (!wp_verify_nonce($_POST['srkit_redirection_nonce'], 'srk_save_redirection_nonce')) {
            wp_die('Security check failed');
        }

        $stats = $this->get_hit_statistics();
        wp_send_json_success($stats);
    }

    /**
     * Get hit statistics for dashboard
     */
    public function get_hit_statistics()
    {
        // Return cached statistics if already fetched in this request
        if ( null !== self::$cached_hit_statistics ) {
            return self::$cached_hit_statistics;
        }
        
        $redirections_table = $this->db_srkitredirection->prefix . 'srkit_redirection_table';
        
        $recent_hits = $this->db_srkitredirection->get_results(
            "SELECT source_url, hits, last_hit FROM $redirections_table WHERE last_hit IS NOT NULL ORDER BY last_hit DESC LIMIT 5"
        );
        
        // Get total count and cache it separately for reuse in pagination
        $total_redirections = $this->db_srkitredirection->get_var("SELECT COUNT(*) FROM $redirections_table") ?: 0;
        self::$cached_total_count = $total_redirections;
        
        // Store results in static cache for this request (shared across all instances)
        self::$cached_hit_statistics = array(
            'total_hits' => $this->db_srkitredirection->get_var("SELECT SUM(hits) FROM $redirections_table") ?: 0,
            'total_redirections' => $total_redirections,
            'active_redirections' => $this->db_srkitredirection->get_var("SELECT COUNT(*) FROM $redirections_table WHERE status = 'active'") ?: 0,
            'most_hit' => $this->db_srkitredirection->get_row(
                "SELECT source_url, target_url, hits FROM $redirections_table ORDER BY hits DESC LIMIT 1"
            ),
            'recent_hits' => $recent_hits
        );
        
        return self::$cached_hit_statistics;
    }
    
    /**
     * Clear cached hit statistics and total count
     * Call this after any operation that modifies redirection data
     * Static method to clear static caches shared across all instances
     * 
     * @since 2.1.0
     */
    private static function clear_hit_statistics_cache()
    {
        self::$cached_hit_statistics = null;
        self::$cached_total_count = null;
    }

    /**
     * Handle file downloads with proper headers (fallback method)
     * Primary method now uses direct blob download via AJAX
     */
    public function handle_file_download()
    {
        if (isset($_GET['srk_download']) && isset($_GET['file'])) {
            $file = sanitize_text_field($_GET['file']);
            
            // Security check
            if (!current_user_can('manage_options')) {
                wp_die('Access denied');
            }
            
            $uploads = wp_upload_dir();
            $filepath = trailingslashit($uploads['basedir']) . 'srk-exports/' . $file;
            
            // Validate file exists
            if (!file_exists($filepath) || !is_readable($filepath)) {
                wp_die('File not found or not readable');
            }
            
            // Validate file path to prevent directory traversal
            $real_filepath = realpath($filepath);
            $real_export_dir = realpath(trailingslashit($uploads['basedir']) . 'srk-exports/');
            if (strpos($real_filepath, $real_export_dir) !== 0) {
                wp_die('Invalid file path');
            }
            
            // Set proper headers based on file type
            $extension = strtolower(pathinfo($file, PATHINFO_EXTENSION));
            switch ($extension) {
                case 'csv':
                    header('Content-Type: text/csv; charset=utf-8');
                    break;
                case 'json':
                    header('Content-Type: application/json; charset=utf-8');
                    break;
                case 'conf':
                    header('Content-Type: text/plain; charset=utf-8');
                    break;
                default:
                    header('Content-Type: application/octet-stream');
            }
            
            header('Content-Disposition: attachment; filename="' . $file . '"');
            header('Content-Length: ' . filesize($filepath));
            header('Cache-Control: no-cache, must-revalidate');
            header('Pragma: no-cache');
            header('Expires: 0');
            
            // Clear any output buffers
            while (ob_get_level()) {
                ob_end_clean();
            }
            
            readfile($filepath);
            exit;
        }
    }

    /**
     * AJAX: Export redirections to CSV, JSON, Nginx, or Apache format and return file data directly
     * This method streams the file directly instead of creating a physical file
     * Works better on live servers with file permission issues
     */
    public function srk_export_redirections()
    {
        if (!wp_verify_nonce($_POST['srkit_redirection_nonce'], 'srk_save_redirection_nonce')) {
            wp_die('Security check failed');
        }

        if (!current_user_can('manage_options')) {
            wp_send_json_error('Permission denied');
        }

        $format = isset($_POST['format']) ? sanitize_text_field($_POST['format']) : 'csv';
        
        // Validate format
        if (!in_array($format, array('csv', 'json', 'nginx', 'htaccess'))) {
            wp_send_json_error('Invalid export format. Only CSV, JSON, Nginx, and .htaccess are supported.');
        }

        $table = $this->db_srkitredirection->prefix . 'srkit_redirection_table';
        $rows = $this->db_srkitredirection->get_results("SELECT id, source_url, target_url, redirect_type, status, is_regex, position, hits, last_hit, created_at, updated_at FROM $table WHERE status = 'active'", ARRAY_A);

        // Define standard headers for export
        $headers = array('id', 'source_url', 'target_url', 'redirect_type', 'status', 'is_regex', 'position', 'hits', 'last_hit', 'created_at', 'updated_at');
        
        $filename = '';
        $file_content = '';
        
        try {
            if ($format === 'nginx') {
                $filename = $this->build_export_filename('conf');
                $file_content = $this->generate_nginx_rules($rows);
            } elseif ($format === 'htaccess') {
                $filename = $this->build_export_filename('htaccess');
                $file_content = $this->generate_htaccess_export_rules($rows);
            } elseif ($format === 'json') {
                $filename = $this->build_export_filename('json');
                
                // Structure JSON with metadata and headers
                $json_structure = array(
                    'export_info' => array(
                        'plugin' => 'SEO Repair Kit',
                        'version' => '2.1.0',
                        'export_date' => current_time('mysql'),
                        'record_count' => count($rows),
                        'headers' => $headers,
                        'description' => 'Advanced Redirection Export - Each redirection object contains all fields as defined in headers'
                    ),
                    'redirections' => $rows
                );
                
                $file_content = wp_json_encode($json_structure, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
                if ($file_content === false) {
                    wp_send_json_error('Failed to encode JSON data');
                }
            } else { // CSV
                $filename = $this->build_export_filename('csv');
                
                // Use output buffering to capture CSV content
                ob_start();
                $output = fopen('php://output', 'w');
                
                // Write headers
                fputcsv($output, $headers);
                
                // Write data rows
                if (!empty($rows)) {
                    foreach ($rows as $r) {
                        // Ensure row data matches header order
                        $ordered_row = array();
                        foreach ($headers as $header) {
                            $ordered_row[] = isset($r[$header]) ? $r[$header] : '';
                        }
                        fputcsv($output, $ordered_row);
                    }
                }
                
                fclose($output);
                $file_content = ob_get_clean();
            }

            if (empty($file_content)) {
                wp_send_json_error('Export file content is empty');
            }

            // Return file content as base64 encoded data for direct download
            wp_send_json_success(array(
                'file_content' => base64_encode($file_content),
                'filename' => $filename,
                'format' => $format,
                'file_size' => strlen($file_content),
                'record_count' => count($rows)
            ));
            
        } catch (Exception $e) {
            wp_send_json_error('Export failed: ' . $e->getMessage());
        }
    }

    /**
     * Generate Apache .htaccess rules for export
     *
     * @param array $redirections
     * @return string
     */
    private function generate_htaccess_export_rules($redirections)
    {
        if (empty($redirections)) {
            return "# SEO Repair Kit - .htaccess Redirect Rules\n# No active redirections found.\n";
        }

        $rules = array();
        $rules[] = "# SEO Repair Kit - .htaccess Redirect Rules";
        $rules[] = "# Generated: " . current_time('mysql');
        $rules[] = "# Total redirects: " . count($redirections);
        $rules[] = "#";
        $rules[] = "RewriteEngine On";
        $rules[] = "";

        foreach ($redirections as $redirection) {
            $line = $this->build_htaccess_rule_for_export($redirection);
            if ($line) {
                $rules[] = "# Redirect ID: {$redirection['id']}";
                $rules[] = $line;
                $rules[] = "";
            }
        }

        return implode("\n", $rules);
    }

    /**
     * Generate Nginx rewrite rules from redirections
     *
     * @param array $redirections Array of redirection data
     * @return string Nginx configuration content
     */
    private function generate_nginx_rules($redirections)
    {
        if (empty($redirections)) {
            return "# SEO Repair Kit - Nginx Redirect Rules\n# No active redirections found.\n";
        }

        $rules = array();
        $rules[] = "# SEO Repair Kit - Nginx Redirect Rules";
        $rules[] = "# Generated: " . current_time('mysql');
        $rules[] = "# Total redirects: " . count($redirections);
        $rules[] = "#";
        $rules[] = "# Add these rules to your Nginx server block (usually in /etc/nginx/sites-available/your-site)";
        $rules[] = "# or include this file in your server block with: include /path/to/srk_nginx_redirects.conf;";
        $rules[] = "#";
        $rules[] = "";

        foreach ($redirections as $redirection) {
            $source_url = $redirection['source_url'];
            $target_url = $redirection['target_url'];
            $redirect_type = intval($redirection['redirect_type']);
            $is_regex = intval($redirection['is_regex']);

            // Skip directives that don't have a target
            if (empty($target_url) && $redirect_type !== 410) {
                continue;
            }

            // Normalize source URL
            $source_path = $this->normalize_source_path($source_url);
            if (!$source_path) {
                continue;
            }

            // Remove query string and fragment for pattern
            $source_path = $this->strip_query_and_fragment($source_path);
            $source_path = ltrim($source_path, '/');

            // Normalize target URL
            if ($redirect_type !== 410) {
                if (strpos($target_url, 'http://') === 0 || strpos($target_url, 'https://') === 0 || strpos($target_url, '//') === 0) {
                    $target = $target_url;
                } else {
                    $target = home_url('/' . ltrim($target_url, '/'));
                }
            } else {
                $target = '';
            }

            if ($redirect_type === 410) {
                $escaped_path = preg_quote($source_path, '/');
                $rules[] = "    # Redirect ID: {$redirection['id']} - 410 Gone";
                $rules[] = "    location = /" . $escaped_path . " {";
                $rules[] = "        return 410;";
                $rules[] = "    }";
                $rules[] = "";
                continue;
            }

            $return_code = in_array($redirect_type, array(301, 302, 303, 307, 308), true) ? $redirect_type : 301;

            if ($is_regex) {
                $pattern = trim($source_path, '/^$');
                $rules[] = "    # Redirect ID: {$redirection['id']} - Regex pattern";
                $rules[] = "    location ~ ^/" . $pattern . "$ {";
                $rules[] = "        return $return_code $target;";
                $rules[] = "    }";
            } else {
                $escaped_path = preg_quote($source_path, '/');
                $rules[] = "    # Redirect ID: {$redirection['id']} - {$redirection['source_url']} -> {$redirection['target_url']}";
                $rules[] = "    location = /" . $escaped_path . " {";
                $rules[] = "        return $return_code $target;";
                $rules[] = "    }";
            }
            
            $rules[] = "";
        }

        $rules[] = "# End of SEO Repair Kit Nginx Redirect Rules";

        return implode("\n", $rules);
    }


    /**
     * Create CSV file (fallback method)
     */
    private function create_csv_file($filepath, $rows)
    {
        $headers = array('ID', 'Source URL', 'Target URL', 'Redirect Type', 'Status', 'Is Regex', 'Position', 'Hits', 'Last Hit', 'Created At', 'Updated At');
        $header_keys = array('id', 'source_url', 'target_url', 'redirect_type', 'status', 'is_regex', 'position', 'hits', 'last_hit', 'created_at', 'updated_at');
        
        $fh = fopen($filepath, 'w');
        if ($fh === false) {
            throw new Exception('Cannot create CSV file');
        }
        
        // Add BOM for UTF-8
        fwrite($fh, "\xEF\xBB\xBF");
        
        // Add headers
        fputcsv($fh, $headers);
        
        // Add data rows
        foreach ($rows as $row) {
            $row_data = array();
            foreach ($header_keys as $key) {
                $row_data[] = isset($row[$key]) ? $row[$key] : '';
            }
            fputcsv($fh, $row_data);
        }
        
        fclose($fh);
    }

    /**
     * AJAX: Clear redirect logs (truncate logs table and reset hits)
     */
    public function srk_clear_logs()
    {
        if (!wp_verify_nonce($_POST['srkit_redirection_nonce'], 'srk_save_redirection_nonce')) {
            wp_die('Security check failed');
        }

        if (!current_user_can('manage_options')) {
            wp_send_json_error('Permission denied');
        }

        $logs_table = $this->db_srkitredirection->prefix . 'srkit_redirection_logs';
        
        // Clear all logs (both redirect hits and admin actions) without modifying hit counts
        $result = $this->db_srkitredirection->query("TRUNCATE TABLE $logs_table");
        
        if ($result !== false) {
            wp_send_json_success('All logs cleared successfully');
        }
        wp_send_json_error('Failed to clear logs');
    }

    /**
     * AJAX: Import redirections from uploaded file
     */
    public function srk_import_redirections()
    {
        if (!wp_verify_nonce($_POST['srkit_redirection_nonce'], 'srk_save_redirection_nonce')) {
            wp_die('Security check failed');
        }

        if (!current_user_can('manage_options')) {
            wp_send_json_error('Permission denied');
        }

        // Handle multiple files
        $files = array();
        if (isset($_FILES['import_file']) && is_array($_FILES['import_file']['tmp_name'])) {
            // Multiple files
            foreach ($_FILES['import_file']['tmp_name'] as $key => $tmp_name) {
                if (!empty($tmp_name) && $_FILES['import_file']['error'][$key] === UPLOAD_ERR_OK) {
                    // Check file size (max 10MB per file)
                    if ($_FILES['import_file']['size'][$key] > 10 * 1024 * 1024) {
                        wp_send_json_error('File "' . $_FILES['import_file']['name'][$key] . '" exceeds 10MB limit');
                        return;
                    }
                    $files[] = array(
                        'tmp_name' => $tmp_name,
                        'name' => $_FILES['import_file']['name'][$key],
                        'size' => $_FILES['import_file']['size'][$key]
                    );
                }
            }
        } elseif (isset($_FILES['import_file']) && !empty($_FILES['import_file']['tmp_name'])) {
            // Single file (backward compatibility)
            if ($_FILES['import_file']['size'] > 10 * 1024 * 1024) {
                wp_send_json_error('File size exceeds 10MB limit');
                return;
            }
            $files[] = array(
                'tmp_name' => $_FILES['import_file']['tmp_name'],
                'name' => $_FILES['import_file']['name'],
                'size' => $_FILES['import_file']['size']
            );
        }
        
        if (empty($files)) {
            wp_send_json_error('No files uploaded');
            return;
        }

        $overwrite = !empty($_POST['import_overwrite']);
        $table = $this->db_srkitredirection->prefix . 'srkit_redirection_table';
        
        // Process all files and combine rows
        $all_rows = array();
        $file_processing_errors = array();
        
        foreach ($files as $file_index => $file_data) {
            $tmp = $file_data['tmp_name'];
            $name = $file_data['name'];
            $ext = strtolower(pathinfo($name, PATHINFO_EXTENSION));
            $rows = array();

            // Block Excel files
            if (in_array($ext, array('xlsx', 'xls'))) {
                $file_processing_errors[] = 'File "' . $name . '" is an Excel file. Excel files are not supported. Please export as CSV, JSON, or .htaccess.';
                continue;
            }

            // Parse file based on extension
            if ($ext === 'json') {
            $content = file_get_contents($tmp);
            // Remove UTF-8 BOM if present
            if (substr($content, 0, 3) === "\xEF\xBB\xBF") {
                $content = substr($content, 3);
            }
                $decoded = json_decode($content, true);
                if (!is_array($decoded)) {
                    $file_processing_errors[] = 'File "' . $name . '" has invalid JSON format';
                    continue;
                }
                
                // Handle new format with export_info and redirections
                if (isset($decoded['redirections']) && is_array($decoded['redirections'])) {
                    $rows = $decoded['redirections'];
                } elseif (isset($decoded['export_info'])) {
                    // New format but redirections key missing
                    $file_processing_errors[] = 'File "' . $name . '" has invalid JSON format: redirections array not found';
                    continue;
                } else {
                    // Old format - direct array of redirections
                    $rows = $decoded;
                }
                
                if (empty($rows) || !is_array($rows)) {
                    $file_processing_errors[] = 'File "' . $name . '" contains no valid redirection data';
                    continue;
                }
            } elseif ($ext === 'csv') { // CSV
                $rows = $this->parse_csv_file($tmp);
                if (empty($rows)) {
                    // Check if file has content
                    $file_size = filesize($tmp);
                    if ($file_size > 0) {
                        // Try to provide more specific error
                        $content = file_get_contents($tmp);
                        $lines = explode("\n", $content);
                        $non_empty_lines = array_filter($lines, function($line) { return trim($line) !== ''; });
                        $line_count = count($non_empty_lines);
                        
                        if ($line_count === 0) {
                            $file_processing_errors[] = 'File "' . $name . '" is empty. Please ensure it contains data.';
                        } elseif ($line_count === 1) {
                            $file_processing_errors[] = 'File "' . $name . '" only contains headers. Please ensure it contains at least one data row.';
                        } else {
                            $file_processing_errors[] = 'File "' . $name . '" failed to parse. Please ensure it has proper headers and data rows. Make sure the file is saved as UTF-8 encoding.';
                        }
                    } else {
                        $file_processing_errors[] = 'File "' . $name . '" is empty or could not be read.';
                    }
                    continue;
                }
            } elseif (in_array($ext, array('htaccess', 'conf', 'txt'))) {
                $rows = $this->parse_htaccess_rules($tmp);
                if (empty($rows)) {
                    $file_processing_errors[] = 'File "' . $name . '" does not contain any valid .htaccess redirect rules.';
                    continue;
                }
            } else {
                $file_processing_errors[] = 'File "' . $name . '" has unsupported format. Supported formats: CSV, JSON, and .htaccess.';
                continue;
            }
            
            // Add rows from this file to all_rows
            if (!empty($rows)) {
                $all_rows = array_merge($all_rows, $rows);
            }
        }
        
        // If we had file processing errors, include them but continue if we have some valid data
        if (!empty($file_processing_errors) && empty($all_rows)) {
            wp_send_json_error('All files failed to process. Errors: ' . implode('; ', $file_processing_errors));
            return;
        }
        
        if (empty($all_rows)) {
            wp_send_json_error('No data found in any file');
            return;
        }
        
        $rows = $all_rows; // Use combined rows from all files

        // Import statistics
        $stats = array(
            'processed' => 0,
            'imported' => 0,
            'updated' => 0,
            'skipped' => 0,
            'skipped_duplicates' => 0, // Track duplicates separately from errors
            'errors' => array()
        );

        // Track processed source URLs across ALL files in this import session to prevent duplicates
        $processed_source_urls = array();
        // Track processed row indices to ensure each row is only processed once
        $processed_row_indices = array();

        // Normalize header keys (handle case variations and spaces)
        // Also deduplicate rows at this stage - duplicates across ALL files will be tracked
        $normalized_rows = array();
        $seen_source_urls = array(); // Track source URLs during normalization to prevent duplicates across ALL files
        
        foreach ($rows as $original_index => $row) {
            // Skip if this row index was already processed
            if (isset($processed_row_indices[$original_index])) {
                continue;
            }
            
            $row_num = $original_index + 2; // +2 because array is 0-indexed and we skip header
            
            // Debug: Log row keys for troubleshooting
            $row_keys = array_keys($row);
            
            $normalized_row = $this->normalize_row_keys($row);
            
            // Check if normalization found source_url - if not, provide detailed error
            if (empty($normalized_row) || !isset($normalized_row['source_url'])) {
                // Track normalization failure with proper error message
                $stats['skipped']++;
                $available_keys = implode(', ', $row_keys);
                $first_few_values = array_slice($row, 0, 3);
                $sample_data = '';
                foreach ($first_few_values as $key => $val) {
                    $sample_data .= "$key=" . (strlen($val) > 30 ? substr($val, 0, 30) . '...' : $val) . ', ';
                }
                $stats['errors'][] = "Row $row_num: Could not find 'source_url' column. Available columns: [$available_keys]. Sample data: [$sample_data]. Expected column names: source_url, source url, old_url, old url, from, from_url, or from url";
                continue;
            }
            
            // Get source_url early to check for duplicates during normalization
            $temp_source_url = isset($normalized_row['source_url']) ? trim($normalized_row['source_url']) : '';
            
            // Skip if source_url is empty or already seen in this import
            if (empty($temp_source_url)) {
                $stats['skipped']++;
                $stats['errors'][] = "Row $row_num: Source URL is missing or empty. Found column but value is empty. Please ensure your file has a valid source URL in the 'source_url' column (or 'old_url', 'from') for this row.";
                continue;
            }
            
            // Check for duplicates across ALL files in this import session (not just within one file)
            // This prevents duplicates when importing multiple files
            if (isset($seen_source_urls[$temp_source_url])) {
                $stats['skipped']++;
                $stats['skipped_duplicates']++; // Track duplicates separately
                // Don't add to errors array - duplicates are expected and handled automatically
                continue;
            }
            
            // Mark this row as seen (tracked across ALL files in the batch)
            $seen_source_urls[$temp_source_url] = $original_index;
            $processed_row_indices[$original_index] = true;
            $normalized_rows[] = $normalized_row;
        }
        
        // Add file processing errors to stats if any
        if (!empty($file_processing_errors)) {
            foreach ($file_processing_errors as $error) {
                $stats['errors'][] = $error;
            }
        }

        // Process each row
        foreach ($normalized_rows as $index => $row) {
            $stats['processed']++;
            $row_num = $index + 2; // +2 because array is 0-indexed and we skip header

            // Validate required fields
            $source_url = isset($row['source_url']) ? trim($row['source_url']) : '';
            $target_url = isset($row['target_url']) ? trim($row['target_url']) : '';
            $redirect_type = isset($row['redirect_type']) ? intval($row['redirect_type']) : 301;

            // Validate source_url
            if (empty($source_url)) {
                $stats['errors'][] = "Row $row_num: Source URL is required";
                $stats['skipped']++;
                continue;
            }

            // Validate redirect_type
            if (!in_array($redirect_type, array_keys($this->redirect_types))) {
                $redirect_type = 301; // Default to 301 if invalid
            }

            // For 410 redirects, target_url is optional
            if ($redirect_type !== 410 && empty($target_url)) {
                $stats['errors'][] = "Row $row_num: Target URL is required for redirect type $redirect_type";
                $stats['skipped']++;
                continue;
            }

            // Sanitize URLs
            $source_url = sanitize_text_field($source_url);
            $target_url = !empty($target_url) ? sanitize_text_field($target_url) : '';

            // Double-check for duplicates within the same import file (safety check)
            if (isset($processed_source_urls[$source_url])) {
                $stats['skipped']++;
                $stats['skipped_duplicates']++; // Track as duplicate, not error
                // Don't add to errors - duplicates are expected and handled automatically
                continue;
            }

            // Check for duplicates in database
            $existing = $this->db_srkitredirection->get_row(
                $this->db_srkitredirection->prepare(
                    "SELECT id FROM $table WHERE source_url = %s",
                    $source_url
                )
            );

            // Prepare data
            $data = array(
                'source_url' => $source_url,
                'target_url' => $target_url,
                'redirect_type' => $redirect_type,
                'status' => isset($row['status']) ? sanitize_text_field($row['status']) : 'active',
                'is_regex' => isset($row['is_regex']) ? intval($row['is_regex']) : 0,
                'position' => isset($row['position']) ? intval($row['position']) : 0,
                'updated_at' => current_time('mysql')
            );

            // Validate regex if enabled
            if ($data['is_regex']) {
                // Use consistent pattern delimiter for validation
                $delimiter = (strpos($source_url, '#') !== false) ? '~' : '#';
                $test_pattern = $delimiter . $source_url . $delimiter . 'i';
                $regex_test = @preg_match($test_pattern, '');
                if ($regex_test === false) {
                    $stats['errors'][] = "Row $row_num: Invalid regex pattern in Source URL";
                    $stats['skipped']++;
                    continue;
                }
            }

            if ($existing) {
                if ($overwrite) {
                    // Update existing record
                    $result = $this->db_srkitredirection->update(
                        $table,
                        $data,
                        array('id' => $existing->id),
                        array('%s', '%s', '%d', '%s', '%d', '%d', '%s'),
                        array('%d')
                    );
                    if ($result !== false) {
                        $stats['updated']++;
                        // Log import update event (always log CRUD operations)
                        // Use 'import_updated' to distinguish from manual updates
                        $this->log_redirection_change_event($data, 'import_updated');
                        // Mark as processed to prevent duplicates in same import
                        $processed_source_urls[$source_url] = $row_num;
                    } else {
                        $stats['errors'][] = "Row $row_num: Failed to update existing redirection";
                        $stats['skipped']++;
                    }
                } else {
                    // Skip duplicate - mark as duplicate, not error if overwrite is not enabled
                    $stats['skipped']++;
                    $stats['skipped_duplicates']++; // Track as duplicate, not error
                    // Don't add to errors array - duplicates with database are expected when overwrite is off
                    // Mark as processed to prevent duplicates in same import
                    $processed_source_urls[$source_url] = $row_num;
                }
            } else {
                // Insert new record - but first check again if it was just inserted (race condition protection)
                $recheck_existing = $this->db_srkitredirection->get_row(
                    $this->db_srkitredirection->prepare(
                        "SELECT id FROM $table WHERE source_url = %s",
                        $source_url
                    )
                );
                
                if ($recheck_existing) {
                    // Record was just inserted by another process or duplicate in same import
                    $stats['skipped']++;
                    $stats['skipped_duplicates']++; // Track as duplicate, not error
                    // Don't add to errors - duplicates are expected and handled automatically
                    $processed_source_urls[$source_url] = $row_num;
                    continue;
                }
                
                $data['hits'] = isset($row['hits']) ? intval($row['hits']) : 0;
                $data['last_hit'] = !empty($row['last_hit']) ? sanitize_text_field($row['last_hit']) : null;
                $data['created_at'] = current_time('mysql');

                // Mark as being processed BEFORE insert to prevent race conditions
                $processed_source_urls[$source_url] = $row_num;
                
                $result = $this->db_srkitredirection->insert(
                    $table,
                    $data,
                    array('%s', '%s', '%d', '%s', '%d', '%d', '%d', '%s', '%s', '%s')
                );

                if ($result !== false) {
                    $stats['imported']++;
                    // Log import creation event (always log CRUD operations)
                    $this->log_redirection_change_event($data, 'imported');
                } else {
                    // Remove from processed if insert failed
                    unset($processed_source_urls[$source_url]);
                    $stats['errors'][] = "Row $row_num: Failed to import - " . $this->db_srkitredirection->last_error;
                    $stats['skipped']++;
                }
            }
        }

        // Calculate actual errors (excluding duplicates which are expected)
        $actual_errors = array();
        foreach ($stats['errors'] as $error) {
            // Don't count duplicates as errors - they're handled automatically
            if (stripos($error, 'duplicate') === false && 
                stripos($error, 'already exists') === false && 
                stripos($error, 'already seen') === false &&
                stripos($error, 'already processed') === false) {
                $actual_errors[] = $error;
            }
        }
        
        // Categorize only actual errors (not duplicates)
        $error_categories = array(
            'missing_source' => 0,
            'missing_target' => 0,
            'invalid_regex' => 0,
            'database_errors' => 0,
            'column_mismatch' => 0,
            'other' => 0
        );
        
        foreach ($actual_errors as $error) {
            // Only categorize actual errors (no duplicates)
            if (stripos($error, 'source url') !== false && (stripos($error, 'missing') !== false || stripos($error, 'empty') !== false)) {
                $error_categories['missing_source']++;
            } elseif (stripos($error, 'target url') !== false && (stripos($error, 'missing') !== false || stripos($error, 'required') !== false)) {
                $error_categories['missing_target']++;
            } elseif (stripos($error, 'regex') !== false || stripos($error, 'invalid pattern') !== false) {
                $error_categories['invalid_regex']++;
            } elseif (stripos($error, 'column') !== false || stripos($error, 'could not find') !== false) {
                $error_categories['column_mismatch']++;
            } elseif (stripos($error, 'failed to import') !== false || stripos($error, 'database') !== false) {
                $error_categories['database_errors']++;
            } else {
                $error_categories['other']++;
            }
        }
        
        // Build response message with more details
        $files_processed = count($files);
        $files_failed = count($file_processing_errors);
        $files_successful = $files_processed - $files_failed;
        
        // Calculate actual skipped (excluding duplicates)
        $actual_skipped = $stats['skipped'] - $stats['skipped_duplicates'];
        
        // Build message based on results
        if ($stats['imported'] > 0 || $stats['updated'] > 0) {
            // Something was imported/updated
            $message = sprintf(
                'Import completed: %d imported, %d updated',
                $stats['imported'],
                $stats['updated']
            );
            
            // Add skipped information - distinguish between duplicates and actual skipped
            if ($stats['skipped'] > 0) {
                $skip_parts = array();
                if ($stats['skipped_duplicates'] > 0) {
                    $skip_parts[] = $stats['skipped_duplicates'] . ' duplicate' . ($stats['skipped_duplicates'] > 1 ? 's' : '') . ' automatically skipped';
                }
                if ($actual_skipped > 0) {
                    $skip_parts[] = $actual_skipped . ' skipped';
                }
                if (!empty($skip_parts)) {
                    $message .= ', ' . implode(', ', $skip_parts);
                }
            }
        } else {
            // Nothing was imported/updated
            if ($stats['skipped_duplicates'] > 0 && $actual_skipped == 0) {
                // Only duplicates were skipped
                $message = sprintf(
                    'Import completed: %d duplicate' . ($stats['skipped_duplicates'] > 1 ? 's' : '') . ' automatically skipped',
                    $stats['skipped_duplicates']
                );
                if (!$overwrite) {
                    $message .= ' (enable overwrite to update existing database records)';
                }
            } elseif ($actual_skipped > 0) {
                // Actual skipped items
                $message = sprintf(
                    'Import completed: %d skipped',
                    $actual_skipped
                );
                if ($stats['skipped_duplicates'] > 0) {
                    $message .= sprintf(', %d duplicate' . ($stats['skipped_duplicates'] > 1 ? 's' : '') . ' automatically skipped', $stats['skipped_duplicates']);
                }
            } else {
                // Nothing processed
                $message = 'Import completed: No records to import';
            }
        }
        
        // Add file processing summary if multiple files
        if ($files_processed > 1) {
            $message .= sprintf(' (%d file%s processed, %d successful, %d failed)', 
                $files_processed, 
                $files_processed > 1 ? 's' : '',
                $files_successful,
                $files_failed
            );
        }

        // Only show actual errors (not duplicates)
        if (!empty($actual_errors)) {
            $error_count = count($actual_errors);
            $message .= sprintf(' (%d error%s)', $error_count, $error_count > 1 ? 's' : '');
            
            // Add error category summary (only actual errors, not duplicates)
            $category_summary = array();
            // Note: Duplicates are shown separately, not as errors
            if ($error_categories['missing_source'] > 0) {
                $category_summary[] = $error_categories['missing_source'] . ' missing source URLs';
            }
            if ($error_categories['missing_target'] > 0) {
                $category_summary[] = $error_categories['missing_target'] . ' missing target URLs';
            }
            if ($error_categories['column_mismatch'] > 0) {
                $category_summary[] = $error_categories['column_mismatch'] . ' column name mismatches';
            }
            if ($error_categories['invalid_regex'] > 0) {
                $category_summary[] = $error_categories['invalid_regex'] . ' invalid regex patterns';
            }
            if ($error_categories['database_errors'] > 0) {
                $category_summary[] = $error_categories['database_errors'] . ' database errors';
            }
            if ($error_categories['other'] > 0) {
                $category_summary[] = $error_categories['other'] . ' other errors';
            }
            
            // Add duplicate information separately (not as error)
            if ($stats['skipped_duplicates'] > 0) {
                $duplicate_note = $stats['skipped_duplicates'] . ' duplicates automatically skipped';
                if (!$overwrite && $stats['skipped_duplicates'] > 0) {
                    $duplicate_note .= ' (enable overwrite to update existing database records)';
                }
                $category_summary[] = $duplicate_note;
            }
            
            if (!empty($category_summary)) {
                $message .= '. Error breakdown: ' . implode(', ', $category_summary);
            }
            
            // Include first 10 actual errors in message for debugging
            if ($error_count > 0) {
                $first_errors = array_slice($actual_errors, 0, 10);
                $message .= '. ' . esc_html__('First errors:', 'seo-repair-kit') . ' ' . implode(' | ', $first_errors);
                if ($error_count > 10) {
                    $message .= sprintf(' ... and %d more errors', $error_count - 10);
                }
            }
        } elseif ($stats['skipped_duplicates'] > 0 && empty($actual_errors)) {
            // If only duplicates were skipped (no actual errors), show success message
            if ($stats['imported'] > 0 || $stats['updated'] > 0) {
                $message .= '. All duplicates were automatically skipped - only unique redirects were imported.';
            } else {
                // Nothing imported because everything was duplicate
                $message .= '. All rows were duplicates - nothing new to import. Enable overwrite to update existing records.';
            }
        }

        $this->refresh_server_rules();
        wp_send_json_success(array(
            'message' => $message,
            'stats' => $stats,
            'error_details' => $actual_errors, // Only include actual errors, not duplicates
            'error_categories' => $error_categories, // Include error category breakdown
            'skipped_duplicates' => $stats['skipped_duplicates'] // Show duplicate count separately
        ));
    }

    /**
     * Parse .htaccess style files and return rows
     */
    private function parse_htaccess_rules($filepath)
    {
        $lines = @file($filepath, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
        if ($lines === false) {
            return array();
        }

        $rows = array();

        foreach ($lines as $line) {
            $line = trim($line);
            if ($line === '' || strpos($line, '#') === 0) {
                continue;
            }

            // Normalize spaces
            $line = preg_replace('/\s+/', ' ', $line);

            // RedirectMatch
            if (preg_match('/^RedirectMatch\s+(?:([0-9]{3})\s+)?(\S+)\s+(\S+)/i', $line, $match)) {
                $code = isset($match[1]) && $match[1] !== '' ? intval($match[1]) : 302;
                $rows[] = array(
                    'source_url' => $match[2],
                    'target_url' => $match[3],
                    'redirect_type' => $code,
                    'status' => 'active',
                    'is_regex' => 1
                );
                continue;
            }

            // Redirect
            if (preg_match('/^Redirect\s+(?:([0-9]{3})\s+)?(\S+)\s+(\S+)/i', $line, $match)) {
                $code = isset($match[1]) && $match[1] !== '' ? intval($match[1]) : 302;
                $rows[] = array(
                    'source_url' => $match[2],
                    'target_url' => $match[3],
                    'redirect_type' => $code,
                    'status' => 'active',
                    'is_regex' => 0
                );
                continue;
            }

            // RewriteRule
            if (preg_match('/^RewriteRule\s+(\S+)\s+(\S+)(?:\s+\[(.+)\])?/i', $line, $match)) {
                $pattern = $match[1];
                $target = $match[2];
                $flags = isset($match[3]) ? $match[3] : '';

                $code = 302;
                $is_gone = false;

                if ($target === '-') {
                    $code = 410;
                    $is_gone = true;
                }

                if (preg_match('/R=([0-9]{3})/i', $flags, $flag_match)) {
                    $code = intval($flag_match[1]);
                } elseif (stripos($flags, 'R') !== false && !$is_gone) {
                    $code = 302;
                } elseif (stripos($flags, 'G') !== false) {
                    $code = 410;
                    $is_gone = true;
                }

                $rows[] = array(
                    'source_url' => $pattern,
                    'target_url' => $is_gone ? '' : $target,
                    'redirect_type' => $code,
                    'status' => 'active',
                    'is_regex' => 1
                );
            }
        }

        return $rows;
    }

    /**
     * Parse CSV file with proper header handling
     */
    private function parse_csv_file($filepath)
    {
        $rows = array();
        $handle = fopen($filepath, 'r');
        
        if ($handle === false) {
            return $rows;
        }

        // Check for UTF-8 BOM and skip it
        $bom_check = fread($handle, 3);
        if ($bom_check === "\xEF\xBB\xBF") {
            // BOM found, continue reading
        } else {
            // No BOM, rewind to start
            rewind($handle);
        }

        // Read headers
        $headers = fgetcsv($handle);
        if ($headers === false || empty($headers)) {
            fclose($handle);
            return $rows;
        }

        // Filter out empty headers
        $headers = array_filter($headers, function($header) {
            return trim($header) !== '';
        });
        
        // Re-index array after filtering
        $headers = array_values($headers);
        
        if (empty($headers)) {
            fclose($handle);
            return $rows;
        }

        // Normalize headers (trim, lowercase, replace spaces with underscores, handle various separators)
        $normalized_headers = array();
        $original_header_map = array(); // Keep mapping of normalized to original
        foreach ($headers as $header) {
            $original = $header;
            $normalized = strtolower(trim($header));
            // Replace spaces, dashes, and other separators with underscores
            $normalized = preg_replace('/[\s\-]+/', '_', $normalized);
            // Remove empty normalized headers
            if (!empty($normalized)) {
                $normalized_headers[] = $normalized;
                $original_header_map[$normalized] = $original;
            }
        }
        
        if (empty($normalized_headers)) {
            fclose($handle);
            return $rows;
        }

        // Read data rows
        $row_count = 0;
        while (($data = fgetcsv($handle)) !== false) {
            // Skip completely empty rows
            if (empty(array_filter($data, function($val) { return trim($val) !== ''; }))) {
                continue;
            }
            
            // Pad or trim data to match header count
            $data_count = count($data);
            $header_count = count($normalized_headers);
            
            if ($data_count < $header_count) {
                // Pad with empty strings
                $data = array_pad($data, $header_count, '');
            } elseif ($data_count > $header_count) {
                // Trim to match header count
                $data = array_slice($data, 0, $header_count);
            }
            
            $row = array_combine($normalized_headers, $data);
            if ($row !== false) {
                $rows[] = $row;
                $row_count++;
            }
        }

        fclose($handle);
        return $rows;
    }

    /**
     * Normalize row keys to match expected column names
     */
    private function normalize_row_keys($row)
    {
        $key_mappings = array(
            // Source URL variations
            'source_url' => array('source_url', 'source url', 'source', 'old_url', 'old url', 'from', 'from_url', 'from url', 'sourceurl', 'oldurl', 'fromurl'),
            // Target URL variations
            'target_url' => array('target_url', 'target url', 'target', 'new_url', 'new url', 'to', 'to_url', 'to url', 'targeturl', 'newurl', 'tourl'),
            // Redirect Type variations
            'redirect_type' => array('redirect_type', 'redirect type', 'type', 'status_code', 'status code', 'code', 'redirecttype', 'statuscode', 'redirect_code'),
            // Status variations
            'status' => array('status', 'active', 'enabled', 'state'),
            // Is Regex variations
            'is_regex' => array('is_regex', 'is regex', 'regex', 'regexp', 'isregex', 'is_regexp'),
            // Position variations
            'position' => array('position', 'order', 'priority', 'pos'),
            // Hits variations
            'hits' => array('hits', 'hit_count', 'hit count', 'count', 'hitcount', 'visits'),
            // Last Hit variations
            'last_hit' => array('last_hit', 'last hit', 'last_hit_date', 'last hit date', 'lasthit', 'last_hit_date', 'lastaccess'),
            // Created At variations
            'created_at' => array('created_at', 'created at', 'created', 'date_created', 'date created', 'createdat', 'datecreated', 'created_date'),
            // Updated At variations
            'updated_at' => array('updated_at', 'updated at', 'updated', 'date_updated', 'date updated', 'updatedat', 'dateupdated', 'updated_date')
        );

        $normalized = array();
        
        // First, check if keys are already in expected format (common case after CSV parsing)
        // This handles the case where CSV parser already normalized headers to lowercase_with_underscores
        foreach ($key_mappings as $target_key => $variations) {
            // Direct match check (most common case)
            if (isset($row[$target_key])) {
                $normalized[$target_key] = $row[$target_key];
            }
        }
        
        // If we already found all required fields, return early
        if (isset($normalized['source_url']) && isset($normalized['target_url'])) {
            return $normalized;
        }
        
        // Normalize all row keys first for easier matching
        $normalized_row_keys = array();
        foreach ($row as $key => $value) {
            // Create multiple normalized versions for matching
            $key_lower = strtolower(trim($key));
            $key_no_spaces = str_replace(' ', '_', $key_lower);
            $key_no_underscores = str_replace('_', '', $key_lower);
            $key_no_spaces_no_underscores = str_replace(array(' ', '_'), '', $key_lower);
            
            $normalized_row_keys[$key] = array(
                'original' => $key,
                'lower' => $key_lower,
                'no_spaces' => $key_no_spaces,
                'no_underscores' => $key_no_underscores,
                'no_spaces_no_underscores' => $key_no_spaces_no_underscores
            );
        }
        
        foreach ($key_mappings as $target_key => $variations) {
            // Skip if already found in direct match
            if (isset($normalized[$target_key])) {
                continue;
            }
            
            $found = false;
            foreach ($variations as $variation) {
                // Normalize variation for comparison
                $variation_lower = strtolower(trim($variation));
                $variation_no_spaces = str_replace(' ', '_', $variation_lower);
                $variation_no_underscores = str_replace('_', '', $variation_lower);
                $variation_no_spaces_no_underscores = str_replace(array(' ', '_'), '', $variation_lower);
                
                // Try to match against normalized row keys
                foreach ($normalized_row_keys as $original_key => $normalized_versions) {
                    // Try multiple matching strategies
                    if ($normalized_versions['lower'] === $variation_lower ||
                        $normalized_versions['no_spaces'] === $variation_no_spaces ||
                        $normalized_versions['no_underscores'] === $variation_no_underscores ||
                        $normalized_versions['no_spaces_no_underscores'] === $variation_no_spaces_no_underscores ||
                        $normalized_versions['lower'] === $variation_no_spaces ||
                        $normalized_versions['no_spaces'] === $variation_lower ||
                        $normalized_versions['lower'] === $variation_no_underscores ||
                        $normalized_versions['no_underscores'] === $variation_lower) {
                        $normalized[$target_key] = $row[$original_key];
                        $found = true;
                        break 2; // Break out of both loops
                    }
                }
            }
            // If not found and this is a required field, try direct key matching as fallback
            if (!$found && in_array($target_key, array('source_url', 'target_url'))) {
                // Try direct key matching (exact match case-insensitive)
                foreach ($row as $key => $value) {
                    $key_normalized = strtolower(trim(str_replace(array(' ', '_', '-'), '', $key)));
                    $target_normalized = strtolower(trim(str_replace(array(' ', '_', '-'), '', $target_key)));
                    if ($key_normalized === $target_normalized || 
                        strpos($key_normalized, $target_normalized) !== false ||
                        strpos($target_normalized, $key_normalized) !== false) {
                        $normalized[$target_key] = $value;
                        break;
                    }
                }
            }
        }

        return $normalized;
    }

    /**
     * Legacy method for backward compatibility
     */
    public function srk_save_new_url()
    {
        // Convert old format to new format
        $_POST['source_url'] = $_POST['old_url'];
        $_POST['target_url'] = $_POST['new_url'];
        $_POST['redirect_type'] = 301;
        $_POST['status'] = 'active';
        $_POST['is_regex'] = 0;
        $_POST['group_id'] = 1;
        
        $this->srk_save_redirection();
    }

    /**
     * Legacy method for backward compatibility
     */
    public function srk_delete_redirection_record()
    {
        $_POST['redirection_id'] = $_POST['record_id'];
        $this->srk_delete_redirection();
    }

    /**
     * Legacy method for backward compatibility
     */
    public function seo_repair_kit_redirects()
    {
        $this->handle_redirections();
    }

    /**
     * AJAX: Manual migration of redirection records
     *
     * @since 2.1.0
     * @access public
     * @return void
     */
    public function srk_migrate_redirections()
    {
        // Check user permissions
        if (!current_user_can('manage_options')) {
            wp_send_json_error('Insufficient permissions');
            return;
        }

        // Check nonce
        if (!isset($_POST['srkit_redirection_nonce'])) {
            wp_send_json_error('Security nonce missing');
            return;
        }

        if (!wp_verify_nonce($_POST['srkit_redirection_nonce'], 'srk_save_redirection_nonce')) {
            wp_send_json_error('Security check failed');
            return;
        }

        // Load activator class to access migration method
        require_once plugin_dir_path( dirname( __FILE__ ) ) . 'includes/class-seo-repair-kit-activator.php';
        
        // Run migration
        $migration_result = SeoRepairKit_Activator::manual_migrate_redirection_table();
        
        if ($migration_result['migrated'] && $migration_result['success']) {
            // Set migration notice
            set_transient('srk_redirection_migration_notice', true, 300);
            set_transient('srk_migration_log', array($migration_result['message']), 3600);
            
            $response_message = sprintf(
                esc_html__('Migration completed successfully! %d records migrated.', 'seo-repair-kit'),
                $migration_result['records_migrated']
            );
            
            if ($migration_result['records_failed'] > 0) {
                $response_message .= ' ' . sprintf(
                    esc_html__('%d records failed to migrate.', 'seo-repair-kit'),
                    $migration_result['records_failed']
                );
            }
            
            $this->refresh_server_rules(true);
            wp_send_json_success(array(
                'message' => $response_message,
                'details' => $migration_result
            ));
        } elseif ($migration_result['migrated'] && !$migration_result['success']) {
            wp_send_json_error(array(
                'message' => esc_html__('Migration failed: ', 'seo-repair-kit') . $migration_result['message'],
                'details' => $migration_result
            ));
        } else {
            wp_send_json_error(array(
                'message' => $migration_result['message'],
                'details' => $migration_result
            ));
        }
    }
}

// CRITICAL: Always instantiate for AJAX handlers to work
// Frontend redirects are controlled by is_plugin_active() check in handle_redirections()
$seorepairkit_redirect = new SeoRepairKit_Redirection();