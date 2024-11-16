<?php
/**
 * Seo_Repair_Kit_Public Class
 * 
 * The public-facing functionality of the plugin.
 *
 * Defines the plugin name, version, and two examples hooks for how to
 * enqueue the public-facing stylesheet and JavaScript.
 *
 * @link       https://seorepairkit.com
 * @since      1.0.1
 * @author     TorontoDigits <support@torontodigits.com>
 */
class Seo_Repair_Kit_Public {

	/**
	 * The ID of this plugin.
	 *
	 * @since    1.0.1
	 * @access   private
	 * @var      string    $seo_repair_kit    The ID of this plugin.
	 */
	private $seo_repair_kit;

	/**
	 * The version of this plugin.
	 *
	 * @since    1.0.1
	 * @access   private
	 * @var      string    $version    The current version of this plugin.
	 */
	private $version;

	/**
	 * Initialize the class and set its properties.
	 *
	 * @since    1.0.1
	 * @param      string    $seo_repair_kit       The name of the plugin.
	 * @param      string    $version    The version of this plugin.
	 */
	public function __construct( $seo_repair_kit, $version ) {

		$this->seo_repair_kit = $seo_repair_kit;
		$this->version = $version;

	}

	/**
	 * Register the stylesheets for the public-facing side of the site.
	 *
	 * @since    1.0.1
	 */
	public function enqueue_styles() {

		wp_enqueue_style( $this->seo_repair_kit, plugin_dir_url( __FILE__ ) . 'css/seo-repair-kit-public.css', array(), $this->version, 'all' );

	}

	/**
	 * Register the JavaScript for the public-facing side of the site.
	 *
	 * @since    1.0.1
	 */
	public function enqueue_scripts() {

		wp_enqueue_script( $this->seo_repair_kit, plugin_dir_url( __FILE__ ) . 'js/seo-repair-kit-public.js', array( 'jquery' ), $this->version, false );
	}
}
