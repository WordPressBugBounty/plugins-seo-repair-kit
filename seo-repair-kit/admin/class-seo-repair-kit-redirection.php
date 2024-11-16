<?php
if ( ! defined( 'ABSPATH' ) ) {
    exit;
}
/**
 * SeoRepairKit_Redirection class.
 *
 * The SeoRepairKit_Redirection class manages URL redirection functionality.
 * It handles the storage of redirection rules, AJAX requests, and template redirects.
 *
 * @link       https://seorepairkit.com
 * @since      1.0.1
 * @author     TorontoDigits <support@torontodigits.com>
 */
class SeoRepairKit_Redirection {

    private $db_srkitredirection;
    /**
     * Constructor for the SeoRepairKit_Redirection class.
     * Sets up the database and registers AJAX and template redirect actions.
     */
    public function __construct() {

        global $wpdb;
        $this->db_srkitredirection = $wpdb;
        add_action( 'wp_ajax_srk_save_new_url', array( $this, 'srk_save_new_url' ) );
        add_action( 'wp_ajax_srk_delete_redirection_record', array( $this, 'srk_delete_redirection_record') );
        add_action( 'template_redirect', array( $this, 'seo_repair_kit_redirects') );
    }

    /**
     * Activates the plugin and checks if the redirection table exists.
     * Displays an admin notice if the table is not created successfully.
     */
    public function activate_plugin() {

        // Check if the table exists after attempted creation
        if ( $this->db_srkitredirection->get_var( "SHOW TABLES LIKE '{$this->db_srkitredirection->prefix}srkit_redirection_table'" ) !== $this->db_srkitredirection->prefix . 'srkit_redirection_table' ) {
            // Table not created successfully
            add_action( 'admin_notices', array( $this, 'srkit_display_activation_error' ) );
        }
    }

    /**
     * Displays the Redirection settings page.
     * Includes JavaScript file for handling form submission.
     * Displays records from the redirection table in a table.
     */
    public function seorepairkit_redirection_page() {

        // Enqueue Script
        wp_enqueue_script( 'srk-redirection-script', plugin_dir_url( __FILE__ ) . 'js/seo-repair-kit-redirection.js', array( 'jquery' ), '1.0.1', true );

        wp_localize_script( 'srk-redirection-script', 'srk_ajax_obj', array( 
            'srkit_redirection_ajax' => admin_url( 'admin-ajax.php' ),
            'srk_save_url_nonce' => wp_create_nonce( 'srk_save_new_url_nonce' ),
            'srkit_redirection_messages' => array( 
                'srk_fill_fields' => esc_html__( 'Please fill both the Old and New URL fields.', 'seo-repair-kit' ),
                'srkit_redirection_save_error' => esc_html__( 'Error: Unable to save the new URL.', 'seo-repair-kit' ),
                'srk_confirm_delete' => esc_html__( 'Are you sure you want to delete this redirection?', 'seo-repair-kit' ),
                'srk_delete_error' => esc_html__( 'Error: Unable to delete the record.', 'seo-repair-kit' )
            )
        ));

        // Enqueue Style
        wp_enqueue_style( 'srk-redirection-style' );

        // HTML for the redirection page
        ?>
        <div class="seo-repair-kit-redirection">
            <h1 class="seo-repair-kit-redirection-heading">
                <?php esc_html_e( 'Redirection', 'seo-repair-kit' ); ?>
            </h1>
            <label for="old_url"><?php esc_html_e( 'Old URL:', 'seo-repair-kit' ); ?></label>
            <input type="text" id="old_url" name="old_url" maxlength="255" /><br />
            <br>
            <label for="new_url"><?php esc_html_e( 'New URL:', 'seo-repair-kit' ); ?></label>
            <input type="text" id="new_url" name="new_url" maxlength="255" /><br />
            <br>
            <input type="submit" value="<?php esc_attr_e( 'Save', 'seo-repair-kit' ); ?>" class="srk-redirection-button" id="srk_save_new_url">
        </div>
        <?php

        // Display the records of the "srkit_redirection_table" in table
        $redirection_table = $this->db_srkitredirection->prefix . 'srkit_redirection_table';
        $srkit_redirectionrecords = $this->db_srkitredirection->get_results( "SELECT * FROM $redirection_table" );
        if ( $srkit_redirectionrecords ) {
            echo '<table class="wp-redirection-list-table widefat fixed striped">';
            echo '<thead>';
            echo '<tr>';
            echo '<th>' . esc_html__( 'NO', 'seo-repair-kit' ) . '</th>';
            echo '<th>' . esc_html__( 'Old URL', 'seo-repair-kit' ) . '</th>';
            echo '<th>' . esc_html__( 'New URL', 'seo-repair-kit' ) . '</th>';
            echo '<th>' . esc_html__( 'Action', 'seo-repair-kit' ) . '</th>';
            echo '</thead>';
            echo '<tbody>';
            // Loop through redirection records and display them in the table
            foreach ( $srkit_redirectionrecords as $srkit_record ) {
                echo '<tr>';
                echo '<td>' . esc_html( $srkit_record->id ) . '</td>';
                echo '<td><a href="' . esc_url( $srkit_record->old_url ) . '" target="_blank">' . esc_html( $srkit_record->old_url ) . '</a></td>';
                echo '<td><a href="' . esc_url( $srkit_record->new_url ) . '" target="_blank">' . esc_html( $srkit_record->new_url ) . '</a></td>';
                echo '<td><button class="srk-delete-record" data-record-id="' . esc_attr( $srkit_record->id ) . '">' . esc_html__( 'Delete', 'seo-repair-kit' ) . '</button></td>';
                echo '</tr>';
            }
            echo '</tbody>';
            echo '</table>';
        } else {
            echo '<div class="seo-repair-kit-no-records-found">' . esc_html__( 'No records found.', 'seo-repair-kit' ) . '</div>';
        }
    }
    
    /**
     * Handles the AJAX request to save new redirection URLs.
     * Inserts the URLs into the redirection table.
     */
    public function srk_save_new_url() {

        // Verify the nonce
        if ( ! isset( $_POST['srkit_redirection_nonce'] ) || ! wp_verify_nonce( sanitize_text_field( wp_unslash( $_POST['srkit_redirection_nonce'] ) ), 'srk_save_new_url_nonce' ) ) {
            echo esc_html__( 'Error: Nonce verification failed.', 'seo-repair-kit' );
            wp_die();
        }
        if ( isset( $_POST['old_url'] ) && isset( $_POST['new_url'] ) ) {
            $srkit_oldurl = esc_url_raw( $_POST['old_url'] );
            $srkit_newurl = esc_url_raw( $_POST['new_url'] );
            $this->db_srkitredirection->insert( $this->db_srkitredirection->prefix . 'srkit_redirection_table',
                array(
                    'old_url' => $srkit_oldurl,
                    'new_url' => $srkit_newurl,
                ),
                array( '%s', '%s' )
            );
            echo esc_html__( 'Saved successfully!', 'seo-repair-kit' );
        } else {
            echo esc_html__( 'Error: Not saved successfully!', 'seo-repair-kit' );
        }
        die();
    }

    /**
     * Handles the redirection logic based on old and new URLs.
     * Checks if the current URL matches any old URL in the database.
     * Redirects to the corresponding new URL if a match is found.
     */
    function seo_repair_kit_redirects() {

        // Get the current page URL
        $srkit_currenturl = esc_url( home_url( sanitize_text_field( wp_unslash( $_SERVER['REQUEST_URI'] ) ) ) );

        // Query the database for a matching old URL
        $srkit_redirect = $this->db_srkitredirection->get_row( 
            $this->db_srkitredirection->prepare( 
                "SELECT new_url FROM {$this->db_srkitredirection->prefix}srkit_redirection_table WHERE old_url = %s",
                $srkit_currenturl
            )
        );

        // If a matching redirect is found, redirect to the new URL
        if ( $srkit_redirect ) {
            wp_redirect( $srkit_redirect->new_url );
            exit;
        }
    }

    public function srk_delete_redirection_record() {

        // Verify the nonce
        if ( ! isset( $_POST['srkit_redirection_nonce'] ) || ! wp_verify_nonce( sanitize_text_field( wp_unslash( $_POST['srkit_redirection_nonce'] ) ), 'srk_save_new_url_nonce' ) ) {
            echo esc_html__( 'Error: Nonce verification failed.', 'seo-repair-kit' );
            wp_die();
        }

        // Get the record ID from the AJAX request
        $srkitrecordId = isset( $_POST['record_id'] ) ? intval( $_POST['record_id'] ) : 0;

        if ( $srkitrecordId <= 0 ) {
            echo esc_html__( 'Error: Invalid record ID.', 'seo-repair-kit' );
            wp_die();
        }

        // Delete the record from the database
        $srkitdeleted = $this->db_srkitredirection->delete(
            $this->db_srkitredirection->prefix . 'srkit_redirection_table',
            array( 'id' => $srkitrecordId ),
            array( '%d' )
        );

        // Send response based on the deletion result
        if ( $srkitdeleted ) {
            echo esc_html__( 'success', 'seo-repair-kit' );
        } else {
            echo esc_html__( 'error', 'seo-repair-kit' );
        }
        wp_die();
    }

    /**
     * Displays an admin notice in case of activation error.
     */
    public function srkit_display_activation_error() {
        
        echo '<div class="error"><p>' . esc_html__( 'Error: Redirection table not created successfully.', 'seo-repair-kit' ) . '</p></div>';
    }
}
// Instantiate the SeoRepairKit_Redirection class on plugin load
$seorepairkit_redirect = new SeoRepairKit_Redirection();
