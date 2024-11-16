<?php
if ( ! defined( 'ABSPATH' ) ) {
    exit;
}
/**
 * SeoRepairKit_AltTextPage class.
 * 
 * The SeoRepairKit_AltTextPage class manages the page for managing missing alt text for images.
 *
 * @link       https://seorepairkit.com
 * @since      1.0.1
 * @author     TorontoDigits <support@torontodigits.com>
 */
class SeoRepairKit_AltTextPage {

    /**
     * Displays the page for managing missing alt text for images.
     * Lists images without alt text, allowing users to add alt text.
     */
    public function alt_image_missing_page() {
        
        // Enqueue Style
        wp_enqueue_style( 'srk-alt-text-style' );

        // Generate a new nonce value
        $srkit_alttextnonce = wp_create_nonce( 'alt_image_missing_nonce' );
        echo '<form method="post">';
        echo '<input type="hidden" name="_wpnonce" value="' . esc_attr( $srkit_alttextnonce ) . '">';
        echo '</form>';
        if ( ! wp_verify_nonce( $srkit_alttextnonce, 'alt_image_missing_nonce' ) ) {
            die( 'Security check failed!' );
        }
        $srkit_noperpage = isset( $_GET['number'] ) ? absint( $_GET['number'] ) : 15;
        $srkit_currentpage = isset( $_GET['paged'] ) ? absint( $_GET['paged'] ) : 1;
        $srkit_altargs = array(
            'post_type' => 'attachment',
            'post_mime_type' => 'image',
            'numberposts' => -1,
            'orderby' => 'date',
            'order' => 'DSC',
        );
        $srkit_altposts = get_posts( $srkit_altargs );
        $srkit_countposts = count( $srkit_altposts );
        $srkit_totalpages = ceil( $srkit_countposts / $srkit_noperpage );
        $srkit_altargs['posts_per_page'] = $srkit_noperpage;
        $srkit_altargs['paged'] = $srkit_currentpage;
        $srkit_alttextposts = get_posts( $srkit_altargs );
        ?>
        <div class="srk-image-alt">
            <h1 class="image-alt-heading">
                <?php esc_html_e( 'Image Alt', 'seo-repair-kit' ); ?>
            </h1>
            <!-- Table displaying images without alt text -->
            <table class="wp-list-table widefat fixed striped">
                <thead>
                    <tr>
                        <th>
                            <?php esc_html_e( 'Image', 'seo-repair-kit' ); ?>
                        </th>
                        <th>
                            <?php esc_html_e( 'Name', 'seo-repair-kit' ); ?>
                        </th>
                        <th>
                            <?php esc_html_e( 'URL', 'seo-repair-kit' ); ?>
                        </th>
                        <th>
                            <?php esc_html_e( 'Date Created', 'seo-repair-kit' ); ?>
                        </th>
                        <th>
                            <?php esc_html_e( 'Add Alt Text', 'seo-repair-kit' ); ?>
                        </th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ( $srkit_alttextposts as $srkit_alttextpost ):
                        setup_postdata( $srkit_alttextpost ); ?>
                        <tr>
                            <td><img src="<?php echo esc_url( wp_get_attachment_url( $srkit_alttextpost->ID ) ); ?>" width="100"
                                    height="100"></td>
                            <td>
                                <?php echo esc_html( get_the_title( $srkit_alttextpost->ID ) ); ?>
                            </td>
                            <td>
                                <?php echo esc_url( wp_get_attachment_url(  $srkit_alttextpost->ID ) ); ?>
                            </td>
                            <td>
                                <?php echo esc_html( get_the_date( '', $srkit_alttextpost->ID ) ); ?>
                            </td>
                            <td>
                                <?php
                                $srkit_alttext = get_post_meta( $srkit_alttextpost->ID, '_wp_attachment_image_alt', true );
                                if ( $srkit_alttext ) {
                                    echo esc_html( $srkit_alttext );
                                } else {
                                    $srkit_medialibrarylink = admin_url( 'upload.php?item=' . $srkit_alttextpost->ID );
                                    echo '<a href="' . esc_url( $srkit_medialibrarylink ) . '" target="_blank">' . esc_html__( 'Want to add Alt Text', 'seo-repair-kit' ) . '</a>';
                                }
                                ?>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
            <!-- Pagination for image list -->
            <div class="tablenav">
                <div class="tablenav-pages">
                    <span class="displaying-num">
                        <?php echo esc_html( $srkit_countposts ); ?>
                        <?php esc_html_e( 'Items', 'seo-repair-kit' ); ?>
                    </span>
                    <?php
                    $srkit_paginatelinks = paginate_links( 
                        array( 
                            'base' => add_query_arg( 'paged', '%#%' ),
                            'format' => '',
                            'prev_text' => '&laquo;',
                            'next_text' => '&raquo;',
                            'total' => $srkit_totalpages,
                            'current' => $srkit_currentpage,
                        )
                    );
                    echo wp_kses_post( $srkit_paginatelinks );
                    ?>
                </div>
            </div>
        </div>
        <?php
    }
}
