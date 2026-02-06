<?php
if ( ! defined( 'ABSPATH' ) ) {
    exit;
}
/**
 * SeoRepairKit_ScanLinks class
 *
 * The SeoRepairKit_ScanLinks class manages link scanning functionality.
 * It checks for broken links, displays results, and provides a CSV download option.
 *
 * @link       https://seorepairkit.com
 * @since      1.0.1
 * @author     TorontoDigits <support@torontodigits.com>
 */

// Class for managing link scanning
class SeoRepairKit_ScanLinks {

    private $db_srkitscan;
    private $srkSelectedPostType;
    private $srklinksArray = array();

    // Constructor
    public function __construct() {

        global $wpdb;
        $this->db_srkitscan = $wpdb;
        $this->srkSelectedPostType = isset( $_POST['srkSelectedPostType'] ) ? sanitize_text_field( $_POST['srkSelectedPostType'] ) : '';

        if ( isset( $_POST['srkSelectedPostType_nonce'] ) && wp_verify_nonce( sanitize_text_field( wp_unslash( $_POST['srkSelectedPostType_nonce'] ) ), 'srkSelectedPostType' ) ) {
            $this->srkSelectedPostType = isset( $_POST['srkSelectedPostType'] ) ? sanitize_text_field( $_POST['srkSelectedPostType'] ) : '';
        }

        // Ajax action for getting HTTP status code
        add_action( 'wp_ajax_get_scan_http_status', array( $this, 'srkit_get_scan_http_status_callback' ) );
        add_action( 'wp_ajax_nopriv_get_scan_http_status', array( $this, 'srkit_get_scan_http_status_callback' ) );

    }

    // Method to get HTTP status code for a given link
    public function srkit_get_http_status_code( $srkit_link ) {

        $srkit_response = wp_remote_get( $srkit_link, array( 'timeout' => 30 ) );
        
        if ( is_wp_error( $srkit_response ) ) {
            return esc_html__( 'Error: ', 'seo-repair-kit' ) . $srkit_response->get_error_message();
        }
        return wp_remote_retrieve_response_code( $srkit_response );
    }
    
    // Main method for initiating link scanning
    public function seorepairkit_scanning_link() {

        // Enqueue Style
        wp_enqueue_style( 'srk-scan-links-style' );

        ?>
        <!-- Enqueue JavaScript -->
        <script>
            <?php include plugin_dir_path( __FILE__ ) . 'js/seo-repair-kit-scan-links.js'; ?>
        </script>

        <!-- Scan Progress Section -->
        <div class="srk-scan-progress-container">
            <div class="srk-scan-progress-header">
                <div class="srk-scan-progress-info">
                    <span class="dashicons dashicons-update srk-spin"></span>
                    <span class="srk-scan-status-text"><?php esc_html_e( 'Scanning links...', 'seo-repair-kit' ); ?></span>
                </div>
                <div class="srk-scan-progress-stats">
                    <span class="srk-scanned-count">0</span>
                    <span class="srk-scan-divider">/</span>
                    <span class="srk-total-count">0</span>
                    <span class="srk-scan-label"><?php esc_html_e( 'links', 'seo-repair-kit' ); ?></span>
                </div>
            </div>
            <div class="srk-progress-bar-wrapper">
                <div class="srk-progress-bar-track">
                    <div class="srk-progress-bar-fill blue-bar"></div>
                </div>
                <div class="srk-progress-percentage progress-label">0%</div>
            </div>
        </div>
        <?php 

        // Query to get posts based on selected post type
        $srkit_args = array( 
            'post_type' => $this->srkSelectedPostType,
            'post_status' => 'publish',
            'posts_per_page' => -1, 
        );
        $srkit_scanposts = new WP_Query( $srkit_args );

        // Output HTML table header ?>
        <div class="srk-table-container srk-scan-table-container">
            <div class="srk-table-header">
                <div class="srk-table-title">
                    <span class="dashicons dashicons-admin-links"></span>
                    <h3><?php esc_html_e( 'Broken Links Found', 'seo-repair-kit' ); ?></h3>
                </div>
                <span class="srk-table-count" id="scan-row-counter"></span>
            </div>
            <div class="srk-table-scroll">
                <table class="srk-404-table srk-scan-links-table" id="scan-table">
                    <thead>
                        <tr>
                            <th class="srk-col-id"><?php esc_html_e( 'ID', 'seo-repair-kit' ); ?></th>
                            <th class="srk-col-title"><?php esc_html_e( 'Title', 'seo-repair-kit' ); ?></th>
                            <th class="srk-col-type"><?php esc_html_e( 'Type', 'seo-repair-kit' ); ?></th>
                            <th class="srk-col-status"><?php esc_html_e( 'Status', 'seo-repair-kit' ); ?></th>
                            <th class="srk-col-link"><?php esc_html_e( 'Link', 'seo-repair-kit' ); ?></th>
                            <th class="srk-col-redirect"><?php esc_html_e( 'Redirect', 'seo-repair-kit' ); ?></th>
                            <th class="srk-col-text"><?php esc_html_e( 'Link Text', 'seo-repair-kit' ); ?></th>
                            <th class="srk-col-edit"><?php esc_html_e( 'Edit', 'seo-repair-kit' ); ?></th>
                            <th class="srk-col-http"><?php esc_html_e( 'HTTP', 'seo-repair-kit' ); ?></th>
                        </tr>
                    </thead>
                    <tbody>
        <?php

        $srkit_indexed = 0;
        while ( $srkit_scanposts->have_posts() ) {
            $srkit_scanposts->the_post();
            $srkit_postid = get_the_ID();
            $srkit_posttitle = get_the_title();
            $srkit_linkscontent = get_the_content();
            $srkit_linkspattern = '/\b(https?:\/\/[^[\]\s<>"]+)\b/i';
            if ( preg_match_all( $srkit_linkspattern, $srkit_linkscontent, $srkit_linksmatches ) ) {
                foreach ( $srkit_linksmatches[1] as $srkit_link ) {
                    $srkit_linktext = $this->srkit_get_link_text( $srkit_link, $srkit_linkscontent );
                    $srkit_editlink = get_edit_post_link( $srkit_postid );
                    $srkit_isinternal = $this->is_internal_link( home_url(), $srkit_link );
                    if ( ! empty( $srkit_link ) ) {

                        // Output table row
                        echo '<tr data-indexed="' . esc_attr( $srkit_indexed ) . '">
                        <td class="srk-col-id"><span class="srk-id-badge">' . esc_html( $srkit_postid ) . '</span></td>
                        <td class="srk-col-title"><span class="srk-title-text">' . esc_html( $srkit_posttitle ) . '</span></td>
                        <td class="srk-col-type"><span class="srk-type-badge">' . esc_html( get_post_type() ) . '</span></td>
                        <td class="srk-col-status"><span class="srk-status-badge">' . esc_html( get_post_status() ) . '</span></td>
                        <td class="srk-col-link">
                            <div class="srk-url-cell">
                                <code>' . esc_url( $srkit_link ) . '</code>
                                <a href="' . esc_url( $srkit_link ) . '" target="_blank" class="srk-url-external" title="' . esc_attr__( 'Open URL', 'seo-repair-kit' ) . '"><span class="dashicons dashicons-external"></span></a>
                            </div>
                        </td>';
                        echo '<td class="srk-col-redirect">';
                        if ( $srkit_isinternal ) {
                            echo '<a href="' . esc_url( admin_url( 'admin.php?page=seo-repair-kit-redirection&source_url=' . urlencode( $srkit_link ) ) ) . '" class="srk-action-btn srk-btn-redirect" target="_blank" title="' . esc_attr__( 'Create Redirect', 'seo-repair-kit' ) . '"><span class="dashicons dashicons-migrate"></span></a>';
                        } else {
                            echo '<span class="srk-text-muted">—</span>';
                        }
                        echo '</td>';
                        echo '<td class="srk-col-text"><span class="srk-link-text">' . esc_html( $srkit_linktext ) . '</span></td>
                        <td class="srk-col-edit"><a href="' . esc_url( $srkit_editlink ) . '" target="_blank" class="srk-action-btn" title="' . esc_attr__( 'Edit Post', 'seo-repair-kit' ) . '"><span class="dashicons dashicons-edit"></span></a></td>
                        <td class="srk-col-http"><span class="scan-http-status" data-link="' . esc_url( $srkit_link ) . '">' . esc_html__( 'Loading...', 'seo-repair-kit' ) . '</span></td>
                    </tr>';
                        $this->srklinksArray[] = esc_url( $srkit_link );
                        $srkit_indexed++;
                    }
                }
            }
        }

        wp_reset_postdata();
        echo '</tbody>
                </table>
            </div>
        </div>';
        echo '<p class="srk-csv-download"><a href="#" id="download-links-csv" class="srk-dashboard-button srk-button-with-icon"><span class="dashicons dashicons-download"></span>' . esc_html__( 'Download CSV', 'seo-repair-kit' ) . '</a></p>';

        // Add nonce to the JavaScript
        $srkit_httpstatusnonce = wp_create_nonce( 'scan_http_status_nonce' );
        $summary_nonce         = wp_create_nonce( 'srk_scan_summary' );
        echo '<script>var ajaxUrlsrkscan = "' . esc_url( admin_url( 'admin-ajax.php' ) ) . '"; var scanHttpStatusNonce = "' . esc_attr( $srkit_httpstatusnonce ) . '"; var scanSummaryNonce = "' . esc_attr( $summary_nonce ) . '"; var srkScanPostType = "' . esc_js( $this->srkSelectedPostType ) . '";</script>';
    }

    // Method to check if a link is internal
    private function is_internal_link( $srkit_homeurl, $srkit_link ) {

        $srkit_homehost = wp_parse_url( $srkit_homeurl, PHP_URL_HOST );
        $srkit_linkhost = wp_parse_url( $srkit_link, PHP_URL_HOST );
        return ( $srkit_homehost === $srkit_linkhost );
    }

    // Function to extract link text from the content
    private function srkit_get_link_text( $srkit_link, $srkit_linkscontent ) {

        $srkit_linkspattern = '/<a\s[^>]*href=[\'"]?' . preg_quote( $srkit_link, '/' ) . '[\'"]?[^>]*>(.*?)<\/a>/i';
        preg_match( $srkit_linkspattern, $srkit_linkscontent, $srkit_linksmatches );

        if ( isset( $srkit_linksmatches[1] ) ) {
            return wp_strip_all_tags( $srkit_linksmatches[1] );
        }
        return '';
    }

    // Ajax callback to get HTTP status code
    public function srkit_get_scan_http_status_callback() {
        
        if ( ! isset( $_POST['srk_scan_nonce'] ) || ! wp_verify_nonce( sanitize_text_field( wp_unslash( $_POST['srk_scan_nonce'] ) ), 'scan_http_status_nonce' ) ) {
            wp_die( esc_html__( 'Invalid nonce', 'seo-repair-kit' ) );
        }

        $srkit_link = isset( $_POST['link'] ) ? esc_url_raw( $_POST['link'] ) : '';
        $srkit_httpstatus = $this->srkit_get_http_status_code( $srkit_link );
        echo esc_html( $srkit_httpstatus );
        wp_die();
    }
}
// Instantiate the class
$srkitscannig_links = new SeoRepairKit_ScanLinks();
