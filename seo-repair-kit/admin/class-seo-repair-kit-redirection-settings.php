<?php
if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/**
 * SEO Repair Kit Redirection Settings
 *
 * Handles registration and management of redirection settings.
 *
 * @link       https://seorepairkit.com
 * @since      2.1.0
 * @author     TorontoDigits <support@torontodigits.com>
 */
class SeoRepairKit_Redirection_Settings
{
    /**
     * Initialize settings
     */
    public function __construct()
    {
        add_action('admin_init', array($this, 'register_settings'));
        add_action('admin_menu', array($this, 'add_settings_page'));
    }

    /**
     * Register settings
     */
    public function register_settings()
    {
        wp_enqueue_style( 'srk-redirection-style', plugin_dir_url( __FILE__ ) . 'css/seo-repair-kit-redirection.css', array(), '2.1.0' );

        // Register settings with sanitization callbacks
        register_setting('srk_redirection_settings', 'srk_enable_logging', array(
            'type' => 'boolean',
            'sanitize_callback' => array($this, 'sanitize_boolean'),
            'default' => 1
        ));
        register_setting('srk_redirection_settings', 'srk_log_retention', array(
            'type' => 'integer',
            'sanitize_callback' => array($this, 'sanitize_log_retention'),
            'default' => 30
        ));
        register_setting('srk_redirection_settings', 'srk_auto_redirect', array(
            'type' => 'boolean',
            'sanitize_callback' => array($this, 'sanitize_boolean'),
            'default' => 1
        ));
        register_setting('srk_redirection_settings', 'srk_monitor_404s', array(
            'type' => 'boolean',
            'sanitize_callback' => array($this, 'sanitize_boolean'),
            'default' => 1
        ));
        register_setting('srk_redirection_settings', 'srk_redirect_cache_time', array(
            'type' => 'integer',
            'sanitize_callback' => array($this, 'sanitize_cache_time'),
            'default' => 3600
        ));
        register_setting('srk_redirection_settings', 'srk_ip_collection', array(
            'type' => 'string',
            'sanitize_callback' => array($this, 'sanitize_ip_collection'),
            'default' => 'full'
        ));
        register_setting('srk_redirection_settings', 'srk_geolocation_enabled', array(
            'type' => 'boolean',
            'sanitize_callback' => array($this, 'sanitize_boolean'),
            'default' => 0
        ));
        register_setting('srk_redirection_settings', 'srk_enable_detailed_logging', array(
            'type' => 'boolean',
            'sanitize_callback' => array($this, 'sanitize_boolean'),
            'default' => 1
        ));
        register_setting('srk_redirection_settings', 'srk_enable_htaccess_sync', array(
            'type' => 'boolean',
            'sanitize_callback' => array($this, 'sanitize_boolean'),
            'default' => 1
        ));
        register_setting('srk_redirection_settings', 'srk_htaccess_write_all', array(
            'type' => 'boolean',
            'sanitize_callback' => array($this, 'sanitize_boolean'),
            'default' => 0
        ));
    }

    /**
     * Sanitize boolean values
     */
    public function sanitize_boolean($value)
    {
        return ($value == '1' || $value === 1 || $value === true) ? 1 : 0;
    }

    /**
     * Sanitize log retention value
     */
    public function sanitize_log_retention($value)
    {
        $valid_values = array(0, 7, 30, 90, 365);
        $value = intval($value);
        return in_array($value, $valid_values) ? $value : 30;
    }

    /**
     * Sanitize cache time value
     */
    public function sanitize_cache_time($value)
    {
        $valid_values = array(0, 300, 900, 1800, 3600, 86400);
        $value = intval($value);
        return in_array($value, $valid_values) ? $value : 3600;
    }

    /**
     * Sanitize IP collection value
     */
    public function sanitize_ip_collection($value)
    {
        $valid_values = array('full', 'partial', 'none');
        return in_array($value, $valid_values) ? $value : 'full';
    }

    /**
     * Add settings page
     */
    public function add_settings_page()
    {
        add_options_page(
            __('SEO Repair Kit Redirection Settings', 'seo-repair-kit'),
            __('SRK Redirections', 'seo-repair-kit'),
            'manage_options',
            'srk-redirection-settings',
            array($this, 'render_settings_page')
        );
    }

    /**
     * Render settings page
     */
    public function render_settings_page()
    {
        ?>
        <div class="wrap">
            <h1><?php esc_html_e('SEO Repair Kit Redirection Settings', 'seo-repair-kit'); ?></h1>
            
            <form method="post" action="options.php">
                <?php
                settings_fields('srk_redirection_settings');
                do_settings_sections('srk_redirection_settings');
                ?>
                
                <table class="form-table">
                    <tr>
                        <th scope="row"><?php esc_html_e('Enable Logging', 'seo-repair-kit'); ?></th>
                        <td>
                            <label>
                                <input type="checkbox" name="srk_enable_logging" value="1" <?php checked(get_option('srk_enable_logging', 1)); ?> />
                                <?php esc_html_e('Log all redirections for analytics and debugging', 'seo-repair-kit'); ?>
                            </label>
                            <p class="description"><?php esc_html_e('Disable this if you want to improve performance and reduce database usage.', 'seo-repair-kit'); ?></p>
                        </td>
                    </tr>
                    
                    <tr>
                        <th scope="row"><?php esc_html_e('Log Retention Period', 'seo-repair-kit'); ?></th>
                        <td>
                            <select name="srk_log_retention">
                                <option value="7" <?php selected(get_option('srk_log_retention', 30), 7); ?>><?php esc_html_e('7 days', 'seo-repair-kit'); ?></option>
                                <option value="30" <?php selected(get_option('srk_log_retention', 30), 30); ?>><?php esc_html_e('30 days', 'seo-repair-kit'); ?></option>
                                <option value="90" <?php selected(get_option('srk_log_retention', 30), 90); ?>><?php esc_html_e('90 days', 'seo-repair-kit'); ?></option>
                                <option value="365" <?php selected(get_option('srk_log_retention', 30), 365); ?>><?php esc_html_e('1 year', 'seo-repair-kit'); ?></option>
                                <option value="0" <?php selected(get_option('srk_log_retention', 30), 0); ?>><?php esc_html_e('Never delete', 'seo-repair-kit'); ?></option>
                            </select>
                            <p class="description"><?php esc_html_e('How long to keep redirect logs. Older logs will be automatically deleted.', 'seo-repair-kit'); ?></p>
                        </td>
                    </tr>
                    
                    <tr>
                        <th scope="row"><?php esc_html_e('Auto-redirect Changed URLs', 'seo-repair-kit'); ?></th>
                        <td>
                            <label>
                                <input type="checkbox" name="srk_auto_redirect" value="1" <?php checked(get_option('srk_auto_redirect', 1)); ?> />
                                <?php esc_html_e('Automatically create redirects when post/page URLs change', 'seo-repair-kit'); ?>
                            </label>
                            <p class="description"><?php esc_html_e('This will automatically create 301 redirects when you change post or page slugs.', 'seo-repair-kit'); ?></p>
                        </td>
                    </tr>
                    
                    <tr>
                        <th scope="row"><?php esc_html_e('Monitor 404 Errors', 'seo-repair-kit'); ?></th>
                        <td>
                            <label>
                                <input type="checkbox" name="srk_monitor_404s" value="1" <?php checked(get_option('srk_monitor_404s', 1)); ?> />
                                <?php esc_html_e('Monitor and log 404 errors for analysis', 'seo-repair-kit'); ?>
                            </label>
                            <p class="description"><?php esc_html_e('Track 404 errors to identify broken links and create appropriate redirects.', 'seo-repair-kit'); ?></p>
                        </td>
                    </tr>
                    
                    <tr>
                        <th scope="row"><?php esc_html_e('Redirect Cache Time', 'seo-repair-kit'); ?></th>
                        <td>
                            <select name="srk_redirect_cache_time">
                                <option value="0" <?php selected(get_option('srk_redirect_cache_time', 3600), 0); ?>><?php esc_html_e('No caching', 'seo-repair-kit'); ?></option>
                                <option value="300" <?php selected(get_option('srk_redirect_cache_time', 3600), 300); ?>><?php esc_html_e('5 minutes', 'seo-repair-kit'); ?></option>
                                <option value="900" <?php selected(get_option('srk_redirect_cache_time', 3600), 900); ?>><?php esc_html_e('15 minutes', 'seo-repair-kit'); ?></option>
                                <option value="1800" <?php selected(get_option('srk_redirect_cache_time', 3600), 1800); ?>><?php esc_html_e('30 minutes', 'seo-repair-kit'); ?></option>
                                <option value="3600" <?php selected(get_option('srk_redirect_cache_time', 3600), 3600); ?>><?php esc_html_e('1 hour', 'seo-repair-kit'); ?></option>
                                <option value="86400" <?php selected(get_option('srk_redirect_cache_time', 3600), 86400); ?>><?php esc_html_e('24 hours', 'seo-repair-kit'); ?></option>
                            </select>
                            <p class="description"><?php esc_html_e('How long to cache redirect rules for better performance.', 'seo-repair-kit'); ?></p>
                        </td>
                    </tr>
                    
                    <tr>
                        <th scope="row"><?php esc_html_e('IP Address Collection', 'seo-repair-kit'); ?></th>
                        <td>
                            <select name="srk_ip_collection">
                                <option value="full" <?php selected(get_option('srk_ip_collection', 'full'), 'full'); ?>><?php esc_html_e('Full IP addresses', 'seo-repair-kit'); ?></option>
                                <option value="partial" <?php selected(get_option('srk_ip_collection', 'full'), 'partial'); ?>><?php esc_html_e('Partial IP (anonymized)', 'seo-repair-kit'); ?></option>
                                <option value="none" <?php selected(get_option('srk_ip_collection', 'full'), 'none'); ?>><?php esc_html_e('No IP collection', 'seo-repair-kit'); ?></option>
                            </select>
                            <p class="description"><?php esc_html_e('Choose IP collection level for privacy compliance (GDPR, etc.).', 'seo-repair-kit'); ?></p>
                        </td>
                    </tr>
                    
                    <tr>
                        <th scope="row"><?php esc_html_e('Geolocation Tracking', 'seo-repair-kit'); ?></th>
                        <td>
                            <label>
                                <input type="checkbox" name="srk_geolocation_enabled" value="1" <?php checked(get_option('srk_geolocation_enabled', 0)); ?> />
                                <?php esc_html_e('Enable country tracking for redirect analytics', 'seo-repair-kit'); ?>
                            </label>
                            <p class="description"><?php esc_html_e('Track visitor countries for geographic redirect analysis. Requires IP collection.', 'seo-repair-kit'); ?></p>
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
                
                <?php submit_button(); ?>
            </form>
            
            <div class="srk-settings-info">
                <h3><?php esc_html_e('Performance Tips', 'seo-repair-kit'); ?></h3>
                <ul>
                    <li><?php esc_html_e('Disable logging on high-traffic sites to improve performance', 'seo-repair-kit'); ?></li>
                    <li><?php esc_html_e('Use shorter log retention periods to reduce database size', 'seo-repair-kit'); ?></li>
                    <li><?php esc_html_e('Enable caching for better redirect performance', 'seo-repair-kit'); ?></li>
                    <li><?php esc_html_e('Regularly clean up old logs using the Clear Logs button', 'seo-repair-kit'); ?></li>
                </ul>
                
                <h3><?php esc_html_e('Privacy Compliance', 'seo-repair-kit'); ?></h3>
                <ul>
                    <li><?php esc_html_e('Choose appropriate IP collection level for your region', 'seo-repair-kit'); ?></li>
                    <li><?php esc_html_e('Disable geolocation if not needed for compliance', 'seo-repair-kit'); ?></li>
                    <li><?php esc_html_e('Consider shorter log retention for privacy', 'seo-repair-kit'); ?></li>
                </ul>
            </div>
        </div>
        
        <style>
        .srk-settings-info {
            margin-top: 30px;
            padding: 20px;
            background: #f8f9fa;
            border-radius: 8px;
            border-left: 4px solid #0b1d51;
        }
        
        .srk-settings-info h3 {
            color: #0b1d51;
            margin-top: 0;
        }
        
        .srk-settings-info ul {
            margin: 10px 0;
        }
        
        .srk-settings-info li {
            margin: 5px 0;
        }
        </style>
        <?php
    }
}

// Initialize settings
new SeoRepairKit_Redirection_Settings();