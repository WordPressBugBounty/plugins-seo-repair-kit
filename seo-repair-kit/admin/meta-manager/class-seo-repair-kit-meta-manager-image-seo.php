<?php
if ( ! defined( 'ABSPATH' ) ) {
    exit;
}
 
/**
 * Image SEO Manager - Simple redirect card to Image Alt Missing page
 */
class SRK_Meta_Manager_Image_SEO {
 
    /**
     * Render the Image SEO tab
     */
    public function render() {
        $redirect_url = admin_url( 'admin.php?page=alt-image-missing' );
        ?>
        <div class="wrap srk-image-seo">
            <!-- Modern Redirect Card -->
            <div class="srk-image-seo-card" style="max-width: 600px; margin: 30px auto;">
                <div style="background: linear-gradient(135deg, #667eea 0%, #0B1D51 100%); border-radius: 20px; padding: 50px 40px; box-shadow: 0 20px 40px rgba(0,0,0,0.15); text-align: center; position: relative; overflow: hidden;">
                   
                    <!-- Decorative Elements -->
                    <div style="position: absolute; top: -50px; right: -50px; width: 200px; height: 200px; background: rgba(255,255,255,0.1); border-radius: 50%;"></div>
                    <div style="position: absolute; bottom: -30px; left: -30px; width: 150px; height: 150px; background: rgba(255,255,255,0.1); border-radius: 50%;"></div>
                   
                    <!-- Icon -->
                    <div style="margin-bottom: 25px; position: relative;">
                        <span class="dashicons dashicons-format-image" style="font-size: 80px; width: 80px; height: 80px; color: #fff; background: rgba(255,255,255,0.2); border-radius: 50%; padding: 20px; box-shadow: 0 10px 20px rgba(0,0,0,0.1);"></span>
                    </div>
                   
                    <!-- Title -->
                    <h2 style="margin: 0 0 15px 0; color: #fff; font-size: 32px; font-weight: 600; text-shadow: 0 2px 4px rgba(0,0,0,0.1); position: relative;">
                        <?php esc_html_e( 'Image Alt Missing', 'seo-repair-kit' ); ?>
                    </h2>
                   
                    <!-- Description -->
                    <p style="color: rgba(255,255,255,0.9); font-size: 18px; margin: 0 0 35px 0; line-height: 1.6; max-width: 450px; margin-left: auto; margin-right: auto; position: relative;">
                        <?php esc_html_e( 'Find and fix images with missing alt text to improve SEO and accessibility.', 'seo-repair-kit' ); ?>
                    </p>
                   
                    <!-- Stats Preview (Optional) -->
                    <div style="display: flex; justify-content: center; gap: 30px; margin-bottom: 35px; position: relative;">
                        <div style="text-align: center;">
                            <div style="color: #fff; font-size: 24px; font-weight: 700;"><?php echo esc_html($this->get_missing_alt_count()); ?></div>
                            <div style="color: rgba(255,255,255,0.8); font-size: 14px;"><?php esc_html_e('Images Missing Alt', 'seo-repair-kit'); ?></div>
                        </div>
                        <div style="width: 1px; height: 40px; background: rgba(255,255,255,0.3);"></div>
                        <div style="text-align: center;">
                            <div style="color: #fff; font-size: 24px; font-weight: 700;"><?php echo esc_html($this->get_total_images()); ?></div>
                            <div style="color: rgba(255,255,255,0.8); font-size: 14px;"><?php esc_html_e('Total Images', 'seo-repair-kit'); ?></div>
                        </div>
                    </div>
                   
                    <!-- Button -->
                    <a href="<?php echo esc_url( $redirect_url ); ?>"
                       class="button button-large"
                       style="padding: 15px 40px; font-size: 18px; background: #fff; color: #667eea; border: none; border-radius: 50px; cursor: pointer; font-weight: 600; text-decoration: none; display: inline-flex; align-items: center; gap: 10px; box-shadow: 0 10px 20px rgba(0,0,0,0.1); transition: all 0.3s ease; position: relative;"
                       onmouseover="this.style.transform='translateY(-2px)'; this.style.boxShadow='0 15px 30px rgba(0,0,0,0.15)';"
                       onmouseout="this.style.transform='translateY(0)'; this.style.boxShadow='0 10px 20px rgba(0,0,0,0.1)';">
                        <span><?php esc_html_e( 'Check Missing Alt Text', 'seo-repair-kit' ); ?></span>
                        <span class="dashicons dashicons-arrow-right-alt" style="font-size: 20px; width: 20px; height: 20px;"></span>
                    </a>
                </div>
            </div>
        </div>
 
        <style>
            /* Smooth transitions */
            .srk-image-seo-card .button {
                transition: all 0.3s ease;
            }
           
            /* Responsive adjustments */
            @media (max-width: 600px) {
                .srk-image-seo-card div[style*="padding: 50px 40px"] {
                    padding: 30px 20px !important;
                }
               
                .srk-image-seo-card h2 {
                    font-size: 24px !important;
                }
               
                .srk-image-seo-card p {
                    font-size: 16px !important;
                }
            }
        </style>
        <?php
    }
 
    /**
     * Get count of images missing alt text
     */
    private function get_missing_alt_count() {
        global $wpdb;
       
        $count = $wpdb->get_var("
            SELECT COUNT(*)
            FROM {$wpdb->postmeta} pm
            INNER JOIN {$wpdb->posts} p ON p.ID = pm.post_id
            WHERE pm.meta_key = '_wp_attachment_metadata'
            AND p.post_type = 'attachment'
            AND p.post_mime_type LIKE 'image/%'
            AND NOT EXISTS (
                SELECT 1 FROM {$wpdb->postmeta} pm2
                WHERE pm2.post_id = p.ID
                AND pm2.meta_key = '_wp_attachment_image_alt'
                AND pm2.meta_value != ''
            )
        ");
       
        return $count ? number_format($count) : '0';
    }
 
    /**
     * Get total images count
     */
    private function get_total_images() {
        global $wpdb;
       
        $count = $wpdb->get_var("
            SELECT COUNT(*)
            FROM {$wpdb->posts}
            WHERE post_type = 'attachment'
            AND post_mime_type LIKE 'image/%'
        ");
       
        return $count ? number_format($count) : '0';
    }
}