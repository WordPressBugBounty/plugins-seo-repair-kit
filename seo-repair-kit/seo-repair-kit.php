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
 * Description:       The ultimate WordPress plugin for fixing broken links and managing Alt Text quickly.
 * Version:           1.1.0
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
 * Rename this for your plugin and update it as you release new versions.
 */
define( 'SEO_REPAIR_KIT_VERSION', '1.1.0' );

/**
 * The code that runs during plugin activation.
 * This action is documented in includes/class-seo-repair-kit-activator.php
 */
function activate_seorepairkit_plugin() {
	require_once plugin_dir_path( __FILE__ ) . 'includes/class-seo-repair-kit-activator.php';
	SeoRepairKit_Activator::activate();
}

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
 * The core plugin class that is used to define internationalization,
 * admin-specific hooks, and public-facing site hooks.
 */
require plugin_dir_path( __FILE__ ) . 'includes/class-seo-repair-kit.php';

/**
 * Begins execution of the plugin.
 *
 * Since everything within the plugin is registered via hooks,
 * then kicking off the plugin from this point in the file does
 * not affect the page life cycle.
 *
 * @since    1.0.1
 */
function run_seorepairkit_plugin() {

	$plugin = new Seo_Repair_Kit();
	$plugin->run();

}
run_seorepairkit_plugin();
