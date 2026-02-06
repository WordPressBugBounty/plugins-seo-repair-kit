<?php

/**
 * The plugin bootstrap file
 *
 * This file is read by WordPress to generate the plugin information in the plugin
 * admin area. This file also includes all of the dependencies used by the plugin,
 * registers the activation and deactivation functions, and defines a function
 * that starts the plugin.
 *
 * @link              https://seorepairkit.com
 * @since             1.0.1
 * @package           Seo_Repair_Kit
 *
 * @wordpress-plugin
 * Plugin Name:       SEO Repair Kit
 * Plugin URI:        https://seorepairkit.com
 * Description:       SEO friendly AI assistant with Schema Manager, link repair, and keyword tracking tools.
 * Version:           2.1.2
 * Author:            TorontoDigits
 * Author URI:        https://torontodigits.com/
 * License:           GPL-2.0+
 * License URI:       http://www.gnu.org/licenses/gpl-2.0.txt
 * Text Domain:       seo-repair-kit
 * Domain Path:       /languages
 */

// If this file is called directly, abort.
if ( ! defined( 'WPINC' ) ) {
	die;
}

/**
 * Currently plugin version.
 * Start at version 1.0.1 and use SemVer - https://semver.org
 */
define( 'SEO_REPAIR_KIT_VERSION', '2.1.2' );

/**
 * Secret Key
 * To change the key, you only need to update this line.
 */
define( 'SRK_SHARED_SECRET', 'S/1nezDK+azLBl1Mo5vvTYX0HieBnG5fJn6H9/r0ZrI=' );

/**
 * This key is used for authentication with the Laravel API.
 */
define( 'SRK_API_APP_KEY', 'base64:' . SRK_SHARED_SECRET );

/**
 * Define the base URL for the Laravel API.
 */
define( 'SRK_API_BASE_URL', 'https://crm.seorepairkit.com' );

/**
 * The code that runs during plugin activation.
 * This action is documented in includes/class-seo-repair-kit-activator.php
 */
function activate_seorepairkit_plugin() {
	require_once plugin_dir_path( __FILE__ ) . 'includes/class-seo-repair-kit-activator.php';
	SeoRepairKit_Activator::activate();
}
add_action('admin_init', function() {
    // Only run table checks once per day to avoid duplicate queries
    $last_check = get_transient('srk_table_creation_check');
    if ( false !== $last_check ) {
        return; // Already checked recently
    }
    
    require_once plugin_dir_path(__FILE__) . 'includes/class-seo-repair-kit-activator.php';

    // Fix main redirect table structure (only if needed)
    SeoRepairKit_Activator::manual_migrate_redirection_table();

    // Ensure redirection logs table exists
    $create_logs = new ReflectionMethod('SeoRepairKit_Activator', 'create_redirection_logs_table');
    $create_logs->setAccessible(true);
    $create_logs->invoke(null);

    // Ensure 404 logs table exists
    $create_404 = new ReflectionMethod('SeoRepairKit_Activator', 'create_404_logs_table');
    $create_404->setAccessible(true);
    $create_404->invoke(null);
    
    // Mark as checked for 24 hours
    set_transient('srk_table_creation_check', time(), DAY_IN_SECONDS);
    
    // Clear related caches after table creation
    delete_transient('srk_required_tables_check');
    delete_transient('srk_404_table_exists');
    delete_transient('srk_404_statistics');
});

/**
 * The code that runs during plugin deactivation.
 * This action is documented in includes/class-seo-repair-kit-deactivator.php
 */
function deactivate_seorepairkit_plugin() {
	require_once plugin_dir_path( __FILE__ ) . 'includes/class-seo-repair-kit-deactivator.php';
	SeoRepairKit_Deactivator::deactivate();
}

register_activation_hook( __FILE__, 'activate_seorepairkit_plugin' );
register_deactivation_hook( __FILE__, 'deactivate_seorepairkit_plugin' );

/**
 * Detect plugin update/downgrade via ZIP upload and flag it.
 */
add_action('upgrader_process_complete', function($upgrader, $options) {

    if (!isset($options['type']) || $options['type'] !== 'plugin') {
        return;
    }

    if (empty($options['plugins']) || !is_array($options['plugins'])) {
        return;
    }

    foreach ($options['plugins'] as $plugin) {

        if ($plugin === 'seo-repair-kit/seo-repair-kit.php') {

            // FORCE WP to reload plugin header metadata IMMEDIATELY
            wp_clean_plugins_cache(true);

            // Set flag for update processing
            update_option('srk_update_pending', 'pending_update');
            
            // Trigger onboarding modal on plugin update (will be processed in admin_init)
            // Check if user has previously explicitly denied consent before setting flag
            require_once plugin_dir_path( __FILE__ ) . 'includes/class-seo-repair-kit-activator.php';
            $consent_option = get_option( 'srk_site_info_consent' );
            // Only trigger onboarding if consent was not explicitly denied (0)
            if ( $consent_option !== 0 ) {
                update_option('srk_should_run_modal_onboarding', 'yes');
            }
        }
    }

}, 10, 2);

/**
 * Require our new API client to centralize all API endpoint management.
 * This class will make your code much more maintainable.
 */
require_once plugin_dir_path( __FILE__ ) . 'admin/class-seo-repair-kit-admin.php';

/**
 * The core plugin class that is used to define internationalization,
 * admin-specific hooks, and public-facing site hooks.
 */
require_once plugin_dir_path( __FILE__ ) . 'includes/class-seo-repair-kit.php';
require_once plugin_dir_path( __FILE__ ) . 'includes/class-srk-api-client.php';
require_once plugin_dir_path( __FILE__ ) . 'includes/class-license-sync.php';
require_once plugin_dir_path( __FILE__ ) . 'includes/class-srk-onboarding-applier.php';
require_once plugin_dir_path( __FILE__ ) . 'includes/class-srk-admin-notices.php';
require_once plugin_dir_path( __FILE__ ) . 'includes/class-srk-license-helper.php';

/**
 * These files are responsile for Schema Integration and build.
 */
require_once plugin_dir_path( __FILE__ ) . 'public/class-seo-repair-kit-schema-integration.php';	
require_once plugin_dir_path( __FILE__ ) . 'public/class-seo-repair-kit-build-schema.php';

/**
 * Checks if the current user is a Pro user by validating the license.
 *
 * @return bool True if the license is active, false otherwise.
 */
function is_pro_user() {
	$domain = site_url();
	$license = SRK_License_Sync::fetch_license_info( $domain );
	return isset( $license['status'] ) && $license['status'] === 'active';
}

/**
 * Begins execution of the plugin.
 *
 * Since everything within the plugin is registered via hooks,
 * then this function is responsible for kicking off the hooks and pulling
 * the plugin into the WordPress ecosystem.
 *
 * @since    1.0.0
 */
function run_seorepairkit_plugin() {
	if ( !function_exists( 'get_bloginfo' ) ) {
		return;
	}
	$plugin = new Seo_Repair_Kit();
	$plugin->run();
}

/**
 * We'll use the 'plugins_loaded' hook, which is fired after all active plugins have been loaded.
 * This ensures all dependencies are available.
 */
add_action( 'plugins_loaded', 'run_seorepairkit_plugin' );
add_action( 'admin_enqueue_scripts', function ( $hook ) {
    if ( $hook !== 'post.php' && $hook !== 'post-new.php' ) return;
} );

// Define plugin file constant
if (!defined('SEOREPAIRKIT_PLUGIN_FILE')) {
    define('SEOREPAIRKIT_PLUGIN_FILE', __FILE__);
}

// Include the weekly summary service
require_once plugin_dir_path(__FILE__) . 'admin/class-seo-repair-kit-weekly-summary.php';

// Initialize the service
// Initialize the service
add_action('plugins_loaded', function() {
    SeoRepairKit_WeeklySummaryService::get_instance();
});

/**
 * Run version update AFTER plugin is fully reloaded (correct version loaded).
 */
add_action('plugins_loaded', function() {

    if (get_option('srk_update_pending') !== 'pending_update') {
        return;
    }

    if (!function_exists('get_plugin_data')) {
        require_once ABSPATH . 'wp-admin/includes/plugin.php';
    }

    // Force plugin header refresh
    wp_clean_plugins_cache(true);

    require_once plugin_dir_path(__FILE__) . 'includes/class-seo-repair-kit-activator.php';

    try {
        SeoRepairKit_Activator::update();
    } catch (Throwable $e) {
        // Silent catch - update will retry on admin_init if needed
    }

    delete_option('srk_update_pending');
}, 50);

/**
 * Extra safety: run deferred update again at admin_init
 * because WordPress refreshes plugin headers AFTER plugins_loaded.
 */
add_action('admin_init', function() {

    if (get_option('srk_update_pending') !== 'pending_update') {
        return;
    }

    if (!function_exists('get_plugin_data')) {
        require_once ABSPATH . 'wp-admin/includes/plugin.php';
    }

    wp_clean_plugins_cache(true);

    require_once plugin_dir_path(__FILE__) . 'includes/class-seo-repair-kit-activator.php';

    try {
        SeoRepairKit_Activator::update();
    } catch (Throwable $e) {
        // Silent catch - update will be retried if needed
    }

    delete_option('srk_update_pending');
});
