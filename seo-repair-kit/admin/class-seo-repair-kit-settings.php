<?php
if ( ! defined( 'ABSPATH' ) ) {
    exit;
}
/**
 * SeoRepairKit_Settings class.
 *
 * The SeoRepairKit_Settings class manages the settings page for selecting post types.
 *
 * @link       https://seorepairkit.com
 * @since      1.0.1
 * @author     TorontoDigits <support@torontodigits.com>
 */
class SeoRepairKit_Settings {

    /**
     * Initialize the class.
     * Hooks into the 'admin_init' action to register post types settings.
     */
    public function __construct() {

        add_action( 'admin_init', array( $this, 'srkit_register_posttypes_settings' ) );
    }
    
    /**
     * Display settings page.
     * Outputs HTML for the settings page, including checkboxes for selecting post types.
     */
    public function seo_repair_kit_settings() {

        // Enqueue Style
        wp_enqueue_style( 'srk-settings-style' );

        ?>
        <div class="seo-repair-kit-settings-background">
            <h1 class="srk-settings-page-title"> <?php esc_html_e( 'Settings', 'seo-repair-kit'  ); ?> </h1>
            <form method="post" action="options.php">
                <?php settings_fields( 'srk_post_types_settings' ); ?>
                <?php do_settings_sections( 'post_types_menu' ); ?>
                <h2 class="seo-repair-kit-settings-post-type"><?php esc_html_e( 'Select Post Types:', 'seo-repair-kit' ); ?></h2>
                <?php
                $srkit_savedposttypes = get_option( 'td_blc_saved_post_types', array() );
                $srkit_publicposttypes = get_post_types( array( 'public' => true ), 'objects' );

                // If no post types are selected, default to 'post'
                if (empty($srkit_savedposttypes)) {
                    $srkit_savedposttypes[] = 'post';
                }
                
                foreach ( $srkit_publicposttypes as $srkit_settingsposttype ) {
                    ?>
                    <input type="checkbox" name="td_blc_saved_post_types[]"
                        value="<?php echo esc_attr( $srkit_settingsposttype->name ); ?>" <?php checked( in_array( $srkit_settingsposttype->name, $srkit_savedposttypes ) ); ?>>
                    <label>
                        <?php echo esc_html( $srkit_settingsposttype->label ); ?>
                    </label><br>
                    <?php
                }
                ?>
                <input type="submit" class="srk-settings-button" value="<?php esc_attr_e( 'Save', 'seo-repair-kit' ); ?>">
                <?php wp_nonce_field( 'save_post_types', 'post_types_nonce' ); ?>
            </form>
        </div>
        <?php
    }

    /**
     * Register settings.
     * Registers the post types settings to WordPress.
     */
    public function srkit_register_posttypes_settings() {

        register_setting( 
            'srk_post_types_settings',
            'td_blc_saved_post_types', 
            array( 
                'sanitize_callback' => array( $this, 'srkit_sanitize_posttypes' ), 
            )
        );
    }

    /**
     * Sanitize selected post types.
     * Ensures that only valid post types are saved.
     *
     * @param array $srkit_input Input values.
     * @return array Sanitized input values.
     */
    public function srkit_sanitize_posttypes( $srkit_input ) {
        
        $srkit_allposttypes = get_post_types( array( 'public' => true ), 'objects' );
        $srkit_allowedposttypes = wp_list_pluck( $srkit_allposttypes, 'name' );
        $srkit_selectedposttypes = is_array( $srkit_input ) ? $srkit_input : array();

        // Only allow post types that are in the list of all public post types
        $srkit_sanitizedposttypes = array_intersect( $srkit_selectedposttypes, $srkit_allowedposttypes );
        return $srkit_sanitizedposttypes;
    }
}
