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
        wp_enqueue_style( 'dashicons' );

        // Generate a new nonce value
        $srkit_alttextnonce = wp_create_nonce( 'alt_image_missing_nonce' );
        echo '<form method="post">';
        echo '<input type="hidden" name="_wpnonce" value="' . esc_attr( $srkit_alttextnonce ) . '">';
        echo '</form>';
        if ( ! wp_verify_nonce( $srkit_alttextnonce, 'alt_image_missing_nonce' ) ) {
            die( 'Security check failed!' );
        }

        // Pagination settings
        $per_page_raw = isset( $_GET['srk_per_page'] ) ? sanitize_text_field( $_GET['srk_per_page'] ) : ( isset( $_GET['number'] ) ? absint( $_GET['number'] ) : '20' );
        $show_all = ( $per_page_raw === 'all' || $per_page_raw === '-1' );
        $srkit_noperpage = $show_all ? -1 : absint( $per_page_raw );
        $srkit_currentpage = isset( $_GET['paged'] ) ? absint( $_GET['paged'] ) : 1;
        
        // Filter setting
        $current_filter = isset( $_GET['filter'] ) ? sanitize_text_field( $_GET['filter'] ) : 'all';

        // Get ALL images first for stats
        $srkit_all_images_args = array(
            'post_type' => 'attachment',
            'post_mime_type' => 'image',
            'numberposts' => -1,
            'orderby' => 'date',
            'order' => 'DESC',
        );
        $srkit_all_images = get_posts( $srkit_all_images_args );
        
        // Calculate stats
        $total_images = count( $srkit_all_images );
        $missing_alt_count = 0;
        $has_alt_count = 0;
        
        foreach ( $srkit_all_images as $image ) {
            $alt_text = get_post_meta( $image->ID, '_wp_attachment_image_alt', true );
            if ( empty( $alt_text ) ) {
                $missing_alt_count++;
            } else {
                $has_alt_count++;
            }
        }

        // Filter images based on selection
        $filtered_images = array();
        if ( $current_filter === 'missing' ) {
            foreach ( $srkit_all_images as $image ) {
                $alt_text = get_post_meta( $image->ID, '_wp_attachment_image_alt', true );
                if ( empty( $alt_text ) ) {
                    $filtered_images[] = $image;
                }
            }
        } elseif ( $current_filter === 'has_alt' ) {
            foreach ( $srkit_all_images as $image ) {
                $alt_text = get_post_meta( $image->ID, '_wp_attachment_image_alt', true );
                if ( ! empty( $alt_text ) ) {
                    $filtered_images[] = $image;
                }
            }
        } else {
            $filtered_images = $srkit_all_images;
        }

        $srkit_countposts = count( $filtered_images );
        $srkit_totalpages = $show_all ? 1 : ( $srkit_noperpage > 0 ? ceil( $srkit_countposts / $srkit_noperpage ) : 1 );
        
        // Paginate the filtered results
        $offset = $show_all ? 0 : ( ( $srkit_currentpage - 1 ) * $srkit_noperpage );
        $srkit_alttextposts = array_slice( $filtered_images, $offset, $srkit_noperpage );

        // Calculate health score
        $health_score = $total_images > 0 ? round( ( $has_alt_count / $total_images ) * 100 ) : 100;
        ?>

        <div class="srk-alt-wrapper">
            <!-- Hero Section -->
            <div class="srk-alt-hero">
                <div class="srk-alt-hero-content">
                    <div class="srk-alt-hero-icon">
                        <span class="dashicons dashicons-format-image"></span>
                    </div>
                    <div class="srk-alt-hero-text">
                        <h1><?php esc_html_e( 'Image Alt Text Manager', 'seo-repair-kit' ); ?></h1>
                        <p><?php esc_html_e( 'Improve accessibility and SEO by adding descriptive alt text to your images. Search engines use alt text to understand image content.', 'seo-repair-kit' ); ?></p>
                        <div class="srk-alt-hero-badge">
                            <span class="dashicons dashicons-visibility"></span>
                            <?php esc_html_e( 'Accessibility & SEO', 'seo-repair-kit' ); ?>
                        </div>
                    </div>
                </div>
            </div>

            <!-- Stats Cards -->
            <div class="srk-alt-stats">
                <div class="srk-alt-stat-card srk-stat-total">
                    <span class="srk-stat-label"><?php esc_html_e( 'Total Images', 'seo-repair-kit' ); ?></span>
                    <span class="srk-stat-number"><?php echo esc_html( $total_images ); ?></span>
                    <p class="srk-stat-subtext"><?php esc_html_e( 'Images in media library', 'seo-repair-kit' ); ?></p>
                </div>
                
                <div class="srk-alt-stat-card srk-stat-missing">
                    <span class="srk-stat-label"><?php esc_html_e( 'Missing Alt Text', 'seo-repair-kit' ); ?></span>
                    <span class="srk-stat-number"><?php echo esc_html( $missing_alt_count ); ?></span>
                    <p class="srk-stat-subtext"><?php esc_html_e( 'Need optimization', 'seo-repair-kit' ); ?></p>
                </div>
                
                <div class="srk-alt-stat-card srk-stat-complete">
                    <span class="srk-stat-label"><?php esc_html_e( 'With Alt Text', 'seo-repair-kit' ); ?></span>
                    <span class="srk-stat-number"><?php echo esc_html( $has_alt_count ); ?></span>
                    <p class="srk-stat-subtext"><?php esc_html_e( 'Fully optimized', 'seo-repair-kit' ); ?></p>
                </div>
                
                <div class="srk-alt-stat-card srk-stat-score">
                    <span class="srk-stat-label"><?php esc_html_e( 'Health Score', 'seo-repair-kit' ); ?></span>
                    <span class="srk-stat-number"><?php echo esc_html( $health_score ); ?>%</span>
                    <div class="srk-stat-progress">
                        <div class="srk-stat-progress-bar" style="width: <?php echo esc_attr( $health_score ); ?>%"></div>
                    </div>
                </div>
            </div>

            <!-- Main Content Card -->
            <div class="srk-alt-card">
                <div class="srk-alt-card-header">
                    <div class="srk-alt-card-title">
                        <span class="dashicons dashicons-admin-media"></span>
                        <h2><?php esc_html_e( 'Image Library', 'seo-repair-kit' ); ?></h2>
                    </div>
                    
                    <!-- Filter Tabs -->
                    <div class="srk-alt-filters">
                        <?php
                        $base_url = admin_url( 'admin.php?page=alt-image-missing' );
                        ?>
                        <a href="<?php echo esc_url( $base_url ); ?>" class="srk-filter-tab <?php echo $current_filter === 'all' ? 'active' : ''; ?>">
                            <span class="dashicons dashicons-images-alt2"></span>
                            <?php esc_html_e( 'All', 'seo-repair-kit' ); ?>
                            <span class="srk-filter-count"><?php echo esc_html( $total_images ); ?></span>
                        </a>
                        <a href="<?php echo esc_url( add_query_arg( 'filter', 'missing', $base_url ) ); ?>" class="srk-filter-tab srk-filter-warning <?php echo $current_filter === 'missing' ? 'active' : ''; ?>">
                            <span class="dashicons dashicons-warning"></span>
                            <?php esc_html_e( 'Missing Alt', 'seo-repair-kit' ); ?>
                            <span class="srk-filter-count"><?php echo esc_html( $missing_alt_count ); ?></span>
                        </a>
                        <a href="<?php echo esc_url( add_query_arg( 'filter', 'has_alt', $base_url ) ); ?>" class="srk-filter-tab srk-filter-success <?php echo $current_filter === 'has_alt' ? 'active' : ''; ?>">
                            <span class="dashicons dashicons-yes-alt"></span>
                            <?php esc_html_e( 'Has Alt', 'seo-repair-kit' ); ?>
                            <span class="srk-filter-count"><?php echo esc_html( $has_alt_count ); ?></span>
                        </a>
                    </div>
                </div>

                <div class="srk-alt-card-body">
                    <?php if ( empty( $srkit_alttextposts ) ) : ?>
                        <div class="srk-alt-empty">
                            <span class="dashicons dashicons-format-image"></span>
                            <h3><?php esc_html_e( 'No images found', 'seo-repair-kit' ); ?></h3>
                            <p><?php esc_html_e( 'There are no images matching your current filter.', 'seo-repair-kit' ); ?></p>
                        </div>
                    <?php else : ?>
                        <!-- Image Grid -->
                        <div class="srk-alt-grid">
                            <?php foreach ( $srkit_alttextposts as $srkit_alttextpost ):
                                setup_postdata( $srkit_alttextpost );
                                $srkit_alttext = get_post_meta( $srkit_alttextpost->ID, '_wp_attachment_image_alt', true );
                                $has_alt = ! empty( $srkit_alttext );
                                $image_url = wp_get_attachment_url( $srkit_alttextpost->ID );
                                $image_title = get_the_title( $srkit_alttextpost->ID );
                                $image_date = get_the_date( 'M j, Y', $srkit_alttextpost->ID );
                                $media_link = admin_url( 'upload.php?item=' . $srkit_alttextpost->ID );
                            ?>
                                <div class="srk-alt-item <?php echo $has_alt ? 'has-alt' : 'missing-alt'; ?>">
                                    <div class="srk-alt-item-image">
                                        <img src="<?php echo esc_url( $image_url ); ?>" alt="<?php echo esc_attr( $srkit_alttext ); ?>">
                                        <div class="srk-alt-item-overlay">
                                            <a href="<?php echo esc_url( $image_url ); ?>" target="_blank" class="srk-alt-action" title="<?php esc_attr_e( 'View Full Size', 'seo-repair-kit' ); ?>">
                                                <span class="dashicons dashicons-external"></span>
                                            </a>
                                            <a href="<?php echo esc_url( $media_link ); ?>" target="_blank" class="srk-alt-action" title="<?php esc_attr_e( 'Edit in Media Library', 'seo-repair-kit' ); ?>">
                                                <span class="dashicons dashicons-edit"></span>
                                            </a>
                                        </div>
                                        <div class="srk-alt-status <?php echo $has_alt ? 'status-ok' : 'status-warning'; ?>">
                                            <span class="dashicons <?php echo $has_alt ? 'dashicons-yes' : 'dashicons-warning'; ?>"></span>
                                        </div>
                                    </div>
                                    <div class="srk-alt-item-info">
                                        <h4 class="srk-alt-item-title" title="<?php echo esc_attr( $image_title ); ?>">
                                            <?php echo esc_html( wp_trim_words( $image_title, 5, '...' ) ); ?>
                                        </h4>
                                        <span class="srk-alt-item-date">
                                            <span class="dashicons dashicons-calendar-alt"></span>
                                            <?php echo esc_html( $image_date ); ?>
                                        </span>
                                        <div class="srk-alt-item-text">
                                            <?php if ( $has_alt ) : ?>
                                                <span class="srk-alt-label"><?php esc_html_e( 'Alt:', 'seo-repair-kit' ); ?></span>
                                                <span class="srk-alt-value" title="<?php echo esc_attr( $srkit_alttext ); ?>">
                                                    <?php echo esc_html( wp_trim_words( $srkit_alttext, 8, '...' ) ); ?>
                                                </span>
                                            <?php else : ?>
                                                <a href="<?php echo esc_url( $media_link ); ?>" target="_blank" class="srk-alt-add-btn">
                                                    <span class="dashicons dashicons-plus-alt"></span>
                                                    <?php esc_html_e( 'Add Alt Text', 'seo-repair-kit' ); ?>
                                                </a>
                                            <?php endif; ?>
                                        </div>
                                    </div>
                                </div>
                            <?php endforeach; ?>
                        </div>
                        <?php wp_reset_postdata(); ?>
                    <?php endif; ?>
                </div>

                <!-- Pagination (Redirection Style) -->
                <?php if ( $srkit_totalpages > 1 || $srkit_countposts > 0 ) : 
                    $base_url = remove_query_arg( array( 'paged', 'srk_per_page' ) );
                    if ( $current_filter !== 'all' ) {
                        $base_url = add_query_arg( 'filter', $current_filter, $base_url );
                    }
                    $current_per_page = isset( $_GET['srk_per_page'] ) ? sanitize_text_field( $_GET['srk_per_page'] ) : $srkit_noperpage;
                ?>
                    <div class="srk-alt-pagination-wrapper">
                        <div class="srk-alt-pagination-info">
                            <?php
                            if ( $show_all ) {
                                printf(
                                    esc_html__( 'Showing all %1$d images', 'seo-repair-kit' ),
                                    $srkit_countposts
                                );
                            } else {
                                $start = $offset + 1;
                                $end = min( $offset + $srkit_noperpage, $srkit_countposts );
                                printf(
                                    esc_html__( 'Showing %1$d to %2$d of %3$d', 'seo-repair-kit' ),
                                    $start,
                                    $end,
                                    $srkit_countposts
                                );
                            }
                            ?>
                        </div>
                        
                        <?php if ( ! $show_all && $srkit_totalpages > 1 ) : ?>
                        <div class="srk-alt-pagination">
                            <?php
                            // Previous button
                            if ( $srkit_currentpage > 1 ) :
                                $prev_url = add_query_arg( 'paged', $srkit_currentpage - 1, $base_url );
                            ?>
                                <a href="<?php echo esc_url( $prev_url ); ?>" class="srk-alt-pagination-link">
                                    <span class="srk-alt-pagination-arrow">&lsaquo;</span>
                                    <?php esc_html_e( 'Previous', 'seo-repair-kit' ); ?>
                                </a>
                            <?php else : ?>
                                <span class="srk-alt-pagination-link srk-alt-pagination-disabled">
                                    <span class="srk-alt-pagination-arrow">&lsaquo;</span>
                                    <?php esc_html_e( 'Previous', 'seo-repair-kit' ); ?>
                                </span>
                            <?php endif; ?>

                            <!-- Page Numbers -->
                            <div class="srk-alt-pagination-pages">
                                <?php
                                $range = 2;
                                
                                // First page
                                if ( $srkit_currentpage > $range + 1 ) :
                                    $first_url = add_query_arg( 'paged', 1, $base_url );
                                ?>
                                    <a href="<?php echo esc_url( $first_url ); ?>" class="srk-alt-pagination-page">1</a>
                                    <?php if ( $srkit_currentpage > $range + 2 ) : ?>
                                        <span class="srk-alt-pagination-dots">...</span>
                                    <?php endif; ?>
                                <?php endif;
                                
                                // Page numbers around current
                                for ( $i = 1; $i <= $srkit_totalpages; $i++ ) :
                                    if ( $i >= $srkit_currentpage - $range && $i <= $srkit_currentpage + $range ) :
                                        if ( $i == $srkit_currentpage ) : ?>
                                            <span class="srk-alt-pagination-page srk-alt-pagination-current"><?php echo esc_html( $i ); ?></span>
                                        <?php else :
                                            $page_url = add_query_arg( 'paged', $i, $base_url );
                                        ?>
                                            <a href="<?php echo esc_url( $page_url ); ?>" class="srk-alt-pagination-page"><?php echo esc_html( $i ); ?></a>
                                        <?php endif;
                                    endif;
                                endfor;
                                
                                // Last page
                                if ( $srkit_currentpage < $srkit_totalpages - $range ) : ?>
                                    <?php if ( $srkit_currentpage < $srkit_totalpages - $range - 1 ) : ?>
                                        <span class="srk-alt-pagination-dots">...</span>
                                    <?php endif;
                                    $last_url = add_query_arg( 'paged', $srkit_totalpages, $base_url );
                                    ?>
                                    <a href="<?php echo esc_url( $last_url ); ?>" class="srk-alt-pagination-page"><?php echo esc_html( $srkit_totalpages ); ?></a>
                                <?php endif; ?>
                            </div>

                            <?php
                            // Next button
                            if ( $srkit_currentpage < $srkit_totalpages ) :
                                $next_url = add_query_arg( 'paged', $srkit_currentpage + 1, $base_url );
                            ?>
                                <a href="<?php echo esc_url( $next_url ); ?>" class="srk-alt-pagination-link">
                                    <?php esc_html_e( 'Next', 'seo-repair-kit' ); ?>
                                    <span class="srk-alt-pagination-arrow">&rsaquo;</span>
                                </a>
                            <?php else : ?>
                                <span class="srk-alt-pagination-link srk-alt-pagination-disabled">
                                    <?php esc_html_e( 'Next', 'seo-repair-kit' ); ?>
                                    <span class="srk-alt-pagination-arrow">&rsaquo;</span>
                                </span>
                            <?php endif; ?>
                        </div>
                        <?php endif; ?>
                        
                        <div class="srk-alt-pagination-per-page">
                            <label for="srk_alt_per_page_select"><?php esc_html_e( 'Per page:', 'seo-repair-kit' ); ?></label>
                            <select id="srk_alt_per_page_select" class="srk-alt-per-page-select">
                                <option value="10" <?php selected( $current_per_page, '10' ); ?>>10</option>
                                <option value="20" <?php selected( $current_per_page, '20' ); ?>>20</option>
                                <option value="50" <?php selected( $current_per_page, '50' ); ?>>50</option>
                                <option value="100" <?php selected( $current_per_page, '100' ); ?>>100</option>
                                <option value="all" <?php selected( $current_per_page, 'all' ); ?>><?php esc_html_e( 'All', 'seo-repair-kit' ); ?></option>
                            </select>
                        </div>
                    </div>
                    
                    <script>
                    jQuery(document).ready(function($) {
                        $('#srk_alt_per_page_select').on('change', function() {
                            var perPage = $(this).val();
                            var url = new URL(window.location.href);
                            url.searchParams.set('srk_per_page', perPage);
                            url.searchParams.delete('paged');
                            window.location.href = url.toString();
                        });
                    });
                    </script>
                <?php endif; ?>
            </div>

            <!-- Help Section -->
            <div class="srk-alt-help">
                <div class="srk-alt-help-card">
                    <div class="srk-help-icon">
                        <span class="dashicons dashicons-lightbulb"></span>
                    </div>
                    <div class="srk-help-content">
                        <h4><?php esc_html_e( 'Why Alt Text Matters', 'seo-repair-kit' ); ?></h4>
                        <ul>
                            <li><?php esc_html_e( 'Screen readers use alt text to describe images to visually impaired users', 'seo-repair-kit' ); ?></li>
                            <li><?php esc_html_e( 'Search engines index alt text to understand image content', 'seo-repair-kit' ); ?></li>
                            <li><?php esc_html_e( 'Alt text displays when images fail to load', 'seo-repair-kit' ); ?></li>
                        </ul>
                    </div>
                </div>
                <div class="srk-alt-help-card">
                    <div class="srk-help-icon">
                        <span class="dashicons dashicons-editor-help"></span>
                    </div>
                    <div class="srk-help-content">
                        <h4><?php esc_html_e( 'Best Practices', 'seo-repair-kit' ); ?></h4>
                        <ul>
                            <li><?php esc_html_e( 'Be descriptive but concise (under 125 characters)', 'seo-repair-kit' ); ?></li>
                            <li><?php esc_html_e( 'Include relevant keywords naturally', 'seo-repair-kit' ); ?></li>
                            <li><?php esc_html_e( 'Avoid starting with "Image of" or "Picture of"', 'seo-repair-kit' ); ?></li>
                        </ul>
                    </div>
                </div>
            </div>
        </div>
        <?php
    }
}
