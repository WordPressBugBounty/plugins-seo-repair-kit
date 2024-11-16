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

        <div class="seo-repair-kit-broken-link-table">
        <h3><?php esc_html_e( 'Broken Links', 'seo-repair-kit' ); ?></h3>
        </div>
        <div class="progress-bar-container">
            <div class="progress-label"></div>
            <div class="blue-bar"></div>
        </div>
        <div class="seo-repair-kit-loader-container">
            <div class="seo-repair-kit-loader"></div>
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
        <div id="scan-row-counter"></div>
        <table class="wp-broken-links-list-table widefat fixed striped" id="scan-table">
        <thead>
            <tr>
                <th><?php esc_html_e( 'ID', 'seo-repair-kit' ); ?></th>
                <th><?php esc_html_e( 'Title', 'seo-repair-kit' ); ?></th>
                <th><?php esc_html_e( 'Post Type', 'seo-repair-kit' ); ?></th>
                <th><?php esc_html_e( 'Status', 'seo-repair-kit' ); ?></th>
                <th><?php esc_html_e( 'Link', 'seo-repair-kit' ); ?></th>
                <th><?php esc_html_e( 'Redirection', 'seo-repair-kit' ); ?></th>
                <th><?php esc_html_e( 'Link Text', 'seo-repair-kit' ); ?></th>
                <th><?php esc_html_e( 'Edit', 'seo-repair-kit' ); ?></th>
                <th><?php esc_html_e( 'HTTP Status', 'seo-repair-kit' ); ?></th>
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
                        <td>' . esc_html( $srkit_postid ) . '</td>
                        <td>' . esc_html( $srkit_posttitle ) . '</td>
                        <td>' . esc_html( get_post_type() ) . '</td>
                        <td>' . esc_html( get_post_status() ) . '</td>
                        <td><a href="' . esc_url( $srkit_link ) . '" target="_blank">' . esc_url( $srkit_link ) . '</a></td>';
                        echo '<td>';

                        if ( $srkit_isinternal ) {
                            echo '<a href="' . esc_url( admin_url( 'admin.php?page=seo-repair-kit-redirection' ) ) . '" class="button button-primary button-small srk-internal-link-button" target="_blank">' . esc_html__( 'Redirection', 'seo-repair-kit' ) . '</a>';
                        }
                        echo '</td>';
                        echo '<td>' . esc_html( $srkit_linktext ) . '</td>
                        <td><a href="' . esc_url( $srkit_editlink ) . '" target="_blank">' . esc_html__( 'Edit', 'seo-repair-kit' ) . '</a></td>
                        <td><span class="scan-http-status" data-link="' . esc_url( $srkit_link ) . '">' . esc_html__( 'Loading...', 'seo-repair-kit' ) . '</span></td>
                    </tr>';
                        $this->srklinksArray[] = esc_url( $srkit_link );
                        $srkit_indexed++;
                    }
                }
            }
        }

        wp_reset_postdata();
        echo '</tbody>
         </table>';
         echo '<p><a href="#" id="download-links-csv" class="button button-primary csv-download-button ">' . esc_html__( 'Download Links CSV', 'seo-repair-kit' ) . '</a></p>';

        // Add nonce to the JavaScript
        $srkit_httpstatusnonce = wp_create_nonce( 'scan_http_status_nonce' );
        echo '<script>var ajaxUrlsrkscan = "' . esc_url( admin_url( 'admin-ajax.php' ) ) . '"; var scanHttpStatusNonce = "' . esc_attr( $srkit_httpstatusnonce ) . '";</script>';
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

        $srkit_link = isset( $_POST['link'] ) ? sanitize_text_field( $_POST['link'] ) : '';
        $srkit_httpstatus = $this->srkit_get_http_status_code( esc_url( $srkit_link ) );
        echo esc_html( $srkit_httpstatus );
        wp_die();
    }
}
// Instantiate the class
$srkitscannig_links = new SeoRepairKit_ScanLinks();
