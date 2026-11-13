<?php
if ( ! defined( 'ABSPATH' ) ) { exit; }

/**
 * Applies Onboarding choices that need WP to know about globally (cron, hooks).
 */
class SRK_Onboarding_Applier {

    public static function init() {
        // Add a weekly schedule (core has hourly, twicedaily, daily).
        add_filter( 'cron_schedules', [ __CLASS__, 'add_weekly' ] );

        // Our scheduled hook; keep it harmless if the scanner isn't available.
        add_action( 'srk_broken_links_scan_event', [ __CLASS__, 'maybe_run_broken_links_scan' ] );
    }

    public static function add_weekly( $schedules ) {
        if ( ! isset( $schedules['weekly'] ) ) {
            $schedules['weekly'] = [
                'interval' => 7 * DAY_IN_SECONDS,
                'display'  => __( 'Once Weekly', 'seo-repair-kit' ),
            ];
        }
        return $schedules;
    }

    /**
     * Run your existing scan if available; otherwise do nothing.
     * You can replace this with a direct call to your scanner.
     */
    public static function maybe_run_broken_links_scan() {
        /**
         * If your plugin exposes a function like:
         *    SeoRepairKit_Scan_Links::run_cron();
         * you can call it here safely:
         */
        if ( class_exists( 'SeoRepairKit_Scan_Links' ) && method_exists( 'SeoRepairKit_Scan_Links', 'run_cron' ) ) {
            try { \SeoRepairKit_Scan_Links::run_cron(); } catch ( \Throwable $e ) { /* fail silent */ }
        } else {
            /**
             * Otherwise, let advanced users hook in:
             * add_action('srk_run_broken_links_scan', function(){ ... });
             */
            do_action( 'srk_run_broken_links_scan' );
        }
    }
}
SRK_Onboarding_Applier::init();