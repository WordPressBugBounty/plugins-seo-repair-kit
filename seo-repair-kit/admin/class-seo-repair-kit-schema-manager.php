<?php
/**
 * Schema Manager for SEO Repair Kit
 *
 * @package   SEO_Repair_Kit
 * @subpackage Schema
 */
 
if ( ! defined( 'ABSPATH' ) ) {
    exit;
}
 
/**
 * Main Schema Manager class handling license validation and page rendering.
 *
 * - Preserves license-gate behavior
 * - Mirrors Upgrade Pro style (srk-* classes)
 * - Adds "Clear License Cache" for BOTH inactive and active states
 * - Hides left overview card when plan is ACTIVE to reduce congestion
 *
 * @since 2.1.0
 */
class SeoRepairKit_SchemaManager {
 
    public function __construct() {
    }
 
    /**
     * Display schema conflict warnings in admin
     *
     * @since 2.1.0
     *
     * @return void
     */
    private function display_schema_conflict_warnings() {
        if ( ! class_exists( 'SeoRepairKit_SchemaConflictDetector' ) ) {
            require_once plugin_dir_path( dirname( __FILE__ ) ) . 'includes/class-seo-repair-kit-schema-conflict-detector.php';
        }

        // Get conflicts from homepage and current page if singular
        $conflicts = array();
        $home_url  = home_url( '/' );
        $home_conflicts = SeoRepairKit_SchemaConflictDetector::get_stored_conflicts( $home_url );
        if ( ! empty( $home_conflicts ) ) {
            $conflicts = array_merge( $conflicts, $home_conflicts );
        }

        if ( is_singular() ) {
            $current_url = get_permalink();
            $page_conflicts = SeoRepairKit_SchemaConflictDetector::get_stored_conflicts( $current_url );
            if ( ! empty( $page_conflicts ) ) {
                $conflicts = array_merge( $conflicts, $page_conflicts );
            }
        }

        if ( ! empty( $conflicts ) ) {
            add_action( 'admin_notices', function() use ( $conflicts ) {
                $screen = get_current_screen();
                if ( ! $screen || strpos( $screen->id, 'seo-repair-kit' ) === false ) {
                    return;
                }
                ?>
                <div class="notice notice-warning is-dismissible srk-schema-conflict-notice" style="border-left-color: #f59e0b;">
                    <p><strong><?php esc_html_e( '⚠️ Schema Conflicts Detected', 'seo-repair-kit' ); ?></strong></p>
                    <p><?php esc_html_e( 'The following schema conflicts were detected on your site:', 'seo-repair-kit' ); ?></p>
                    <ul style="margin-left: 20px; list-style: disc;">
                        <?php foreach ( $conflicts as $conflict ) : ?>
                            <li><?php echo esc_html( $conflict['conflict']['message'] ); ?></li>
                        <?php endforeach; ?>
                    </ul>
                    <p>
                        <strong><?php esc_html_e( 'Impact:', 'seo-repair-kit' ); ?></strong>
                        <?php esc_html_e( 'Conflicting schemas may be ignored by Google, which can hurt your SEO. Please review your schema assignments and remove conflicting schemas.', 'seo-repair-kit' ); ?>
                    </p>
                    <p>
                        <a href="<?php echo esc_url( admin_url( 'admin.php?page=seo-repair-kit-schema-manager' ) ); ?>" class="button button-primary">
                            <?php esc_html_e( 'Review Schema Assignments', 'seo-repair-kit' ); ?>
                        </a>
                        <button type="button" class="button srk-dismiss-conflicts" style="margin-left: 10px;">
                            <?php esc_html_e( 'Dismiss', 'seo-repair-kit' ); ?>
                        </button>
                    </p>
                </div>
                <script>
                jQuery(document).ready(function($) {
                    $('.srk-dismiss-conflicts').on('click', function() {
                        $(this).closest('.notice').fadeOut();
                    });
                });
                </script>
                <?php
            } );
        }
    }

    /**
     * Check if schema features are enabled based on license status.
     *
     * @since 2.1.0
     * @return bool
     */
    private function is_schema_feature_enabled(): bool {
        if ( ! class_exists( 'SeoRepairKit_Admin' ) ) {
            return false;
        }

        $admin   = new SeoRepairKit_Admin( '', '' );
        $license = $admin->get_license_status( site_url() );

        return ( ! empty( $license['status'] ) && 'active' === $license['status'] );
    }
 
    /**
     * Render the Schema Manager admin page.
     *
     * @since 2.1.0
     * @return void
     */
    public function seo_repair_kit_schema_page(): void {
 
        if ( ! current_user_can( 'manage_options' ) ) {
            wp_die( esc_html__( 'You do not have permission to view this page.', 'seo-repair-kit' ) );
        }
 
        // Only load schema manager style - this page has its own dedicated styles
        wp_enqueue_style(
            'srk-schema-manager',
            plugin_dir_url( __FILE__ ) . 'css/seo-repair-kit-schema-manager.css',
            array(),
            '2.1.0'
        );
 
        $domain    = site_url();
        $cache_key = 'srk_license_status_' . md5( $domain );
        $message   = '';
        $status    = '';
 
        $posted_clear_cache = isset( $_POST['srk_clear_cache'] ) ? sanitize_text_field( wp_unslash( $_POST['srk_clear_cache'] ) ) : '';
 
        if ( is_admin() && '1' === $posted_clear_cache ) {
            check_admin_referer( 'srk_clear_license_cache', 'srk_cc_nonce' );
 
            delete_transient( $cache_key );
 
            if ( false === get_transient( $cache_key ) ) {
                $message = '✅ ' . esc_html__( 'License cache cleared successfully.', 'seo-repair-kit' );
                $status  = 'success';
            } else {
                $message = '⚠️ ' . esc_html__( 'Failed to clear license cache.', 'seo-repair-kit' );
                $status  = 'error';
            }
        }
 
        $enabled      = $this->is_schema_feature_enabled();
        $grid_classes = $enabled ? 'srk-grid srk-grid--single' : 'srk-grid';

        // ✅ NEW: Check for schema conflicts and display warnings
        $this->display_schema_conflict_warnings();
        ?>
        <div class="wrap srk-schema-wrap">
            <?php
            // Display WordPress admin notices before hero section
            settings_errors();
            
            // Display custom message if set (from cache clearing, etc.)
            if ( $message ) :
                $wp_notice_class = ( 'success' === $status ) ? 'notice-success' : 'notice-error';
                ?>
                <div class="srk-notice">
                    <div class="notice <?php echo esc_attr( $wp_notice_class ); ?> is-dismissible">
                        <p><?php echo esc_html( $message ); ?></p>
                    </div>
                </div>
            <?php endif; ?>
            
            <!-- Anchor where all WP admin notices will appear (before hero) -->
            <div id="srk-schema-admin-notices" class="srk-schema-notices" aria-live="polite"></div>
            
            <div class="srk-schema-wrapper">
                <!-- Hero Section -->
                <div class="srk-hero">
                    <div class="srk-hero-content">
                        <div class="srk-hero-icon">
                            <span class="dashicons dashicons-admin-generic"></span>
                        </div>
                        <div class="srk-hero-text">
                            <h2><?php esc_html_e( 'Schema Manager', 'seo-repair-kit' ); ?></h2>
                            <p><?php esc_html_e( 'Add structured data to your content to help search engines understand your pages better. Create valid JSON-LD schema that enhances your search results with rich snippets, ratings, and more.', 'seo-repair-kit' ); ?></p>
                            <div class="srk-hero-features">
                                <span class="srk-hero-badge">
                                    <span class="dashicons dashicons-yes-alt"></span>
                                    <?php esc_html_e( 'JSON-LD FORMAT', 'seo-repair-kit' ); ?>
                                </span>
                                <span class="srk-hero-badge">
                                    <span class="dashicons dashicons-star-filled"></span>
                                    <?php esc_html_e( 'RICH RESULTS', 'seo-repair-kit' ); ?>
                                </span>
                                <span class="srk-hero-badge">
                                    <span class="dashicons dashicons-admin-settings"></span>
                                    <?php esc_html_e( 'SCHEMA.ORG COMPLIANT', 'seo-repair-kit' ); ?>
                                </span>
                            </div>
                        </div>
                    </div>
                </div>
                
                <div class="<?php echo esc_attr( $grid_classes ); ?>">
     
                    <?php if ( ! $enabled ) : ?>
                        <?php
                        $subscribe_url = '#';
                        if ( class_exists( 'SRK_API_Client' ) ) {
                            $subscribe_url = SRK_API_Client::get_api_url(
                                SRK_API_Client::ENDPOINT_SUBSCRIBE,
                                array( 'domain' => site_url() )
                            );
                        }
                        ?>
                        <aside class="srk-cta-card" role="complementary" aria-label="<?php esc_attr_e( 'Schema Manager Overview', 'seo-repair-kit' ); ?>">
                            <div class="srk-cta-head">
                                <div class="srk-icon" aria-hidden="true">🧩</div>
                                <div>
                                    <h3 class="srk-cta-title"><?php esc_html_e( 'Structured data, simplified.', 'seo-repair-kit' ); ?></h3>
                                    <p class="srk-cta-sub"><?php esc_html_e( 'Map fields, preview, and publish valid JSON-LD.', 'seo-repair-kit' ); ?></p>
                                </div>
                            </div>
     
                            <div class="srk-cta-body">
                                <div class="srk-feature"><i class="dashicons dashicons-yes"></i> <span><?php esc_html_e( 'Supports Posts, Pages & CPTs', 'seo-repair-kit' ); ?></span></div>
                                <div class="srk-feature"><i class="dashicons dashicons-yes"></i> <span><?php esc_html_e( 'One-click JSON-LD preview & copy', 'seo-repair-kit' ); ?></span></div>
                                <div class="srk-feature"><i class="dashicons dashicons-yes"></i>
                                    <span><?php esc_html_e( 'Schema types: Article, BlogPosting, NewsArticle, FAQPage, HowTo, Product, JobPosting, Event, Course, Review, Recipe, LocalBusiness, Organization, Corporation, Reservation, MedicalCondition, MedicalWebPage, Website, VideoObject', 'seo-repair-kit' ); ?></span>
                                </div>
                            </div>
     
                            <div class="srk-cta-footer">
                                <div class="srk-cta-actions">
                                    <a class="srk-btn srk-btn-primary" target="_blank" rel="noopener noreferrer" href="<?php echo esc_url( $subscribe_url ); ?>">
                                        <?php esc_html_e( 'Get Premium', 'seo-repair-kit' ); ?>
                                    </a>
                                    <form method="post" style="display:inline-block; margin-left:8px;">
                                        <?php wp_nonce_field( 'srk_clear_license_cache', 'srk_cc_nonce' ); ?>
                                        <input type="hidden" name="srk_clear_cache" value="1" />
                                        <button type="submit" class="srk-btn srk-btn-secondary srk-btn-secondary--on-dark">
                                            <?php esc_html_e( 'Clear License Cache', 'seo-repair-kit' ); ?>
                                        </button>
                                    </form>
                                </div>
                                <p style="margin:10px 2px 0; color:#a5b4fc; font-size:12px;">
                                    <?php esc_html_e( 'If your license status looks outdated, clear the cache and try again.', 'seo-repair-kit' ); ?>
                                </p>
                            </div>
                        </aside>
                    <?php endif; ?>
     
                    <section>
                        <?php
                        if ( ! $enabled ) {
                            $this->render_premium_notice_panel();
                        } else {
                            $this->render_schema_ui_panel_with_clear_cache();
                        }
                        ?>
     
                        <div class="srk-panel" style="display:flex; align-items:center; gap:10px; margin-top:16px;">
                            <span class="dashicons dashicons-yes-alt" style="color:var(--srk-primary);" aria-hidden="true"></span>
                            <p style="margin:0; color:var(--srk-muted);">
                                <?php esc_html_e( 'JSON-LD complies with schema.org vocabulary and follows Google rich-result guidelines where applicable.', 'seo-repair-kit' ); ?>
                            </p>
                        </div>
                    </section>
                </div>
            </div>
        </div>
        
        <script>
        (function(){
            // Move all WordPress admin notices to appear before hero section
            const anchor = document.getElementById('srk-schema-admin-notices');
            if (!anchor) return;

            const schemaWrap = document.querySelector('.srk-schema-wrap');
            if (!schemaWrap) return;

            const hero = schemaWrap.querySelector('.srk-hero');
            if (!hero) return;

            const SELECTOR = '.notice, .updated, .error, .update-nag, .notice-error, .notice-warning, .notice-success, .notice-info';

            function moveNoticeToAnchor(notice) {
                if (!notice || anchor.contains(notice)) return;
                
                // Check if notice is inside hero section
                if (hero.contains(notice)) {
                    notice.style.marginTop = '0';
                    notice.style.marginBottom = '20px';
                    anchor.appendChild(notice);
                    return true;
                }
                return false;
            }

            function collectAndMoveNotices() {
                // First, check inside hero section specifically
                const heroNotices = hero.querySelectorAll(SELECTOR);
                heroNotices.forEach(function(n) {
                    moveNoticeToAnchor(n);
                });

                // Then check the entire schema wrap for any other notices
                const allNotices = schemaWrap.querySelectorAll(SELECTOR);
                allNotices.forEach(function(n) {
                    // Only move if not already in anchor and is inside hero
                    if (!anchor.contains(n) && hero.contains(n)) {
                        moveNoticeToAnchor(n);
                    }
                });

                // Also check wpbody-content for notices that might be outside schema wrap
                const wpBodyContent = document.getElementById('wpbody-content');
                if (wpBodyContent) {
                    const bodyNotices = wpBodyContent.querySelectorAll(SELECTOR);
                    bodyNotices.forEach(function(n) {
                        // Move notices that are not in schema wrap or are in hero
                        if (!schemaWrap.contains(n) || hero.contains(n)) {
                            if (!anchor.contains(n)) {
                                moveNoticeToAnchor(n);
                            }
                        }
                    });
                }
            }

            // Initial move on DOM ready (covers server-rendered notices)
            if (document.readyState === 'loading') {
                document.addEventListener('DOMContentLoaded', function(){ 
                    collectAndMoveNotices();
                });
            } else {
                collectAndMoveNotices();
            }

            // Observe future changes: some plugins inject notices after load
            const obs = new MutationObserver(function(muts){
                let shouldCheck = false;
                
                for (const m of muts) {
                    if (m.addedNodes && m.addedNodes.length > 0) {
                        m.addedNodes.forEach(function(node){
                            if (node.nodeType === 1) {
                                // Check if added node is a notice or contains notices
                                if (node.matches && node.matches(SELECTOR)) {
                                    moveNoticeToAnchor(node);
                                    shouldCheck = true;
                                } else if (node.querySelector && node.querySelector(SELECTOR)) {
                                    // New container with notices inside
                                    const notices = node.querySelectorAll(SELECTOR);
                                    notices.forEach(function(n) {
                                        moveNoticeToAnchor(n);
                                    });
                                    shouldCheck = true;
                                }
                                
                                // Check if node was added inside hero section
                                if (hero.contains(node)) {
                                    const noticesInNode = node.querySelectorAll ? node.querySelectorAll(SELECTOR) : [];
                                    noticesInNode.forEach(function(n) {
                                        moveNoticeToAnchor(n);
                                    });
                                    shouldCheck = true;
                                }
                            }
                        });
                    }
                }
                
                // If we detected changes, do a full check
                if (shouldCheck) {
                    setTimeout(collectAndMoveNotices, 100);
                }
            });

            // Observe the hero section specifically for new notices
            obs.observe(hero, {
                childList: true,
                subtree: true
            });

            // Also observe the schema wrap for any notices
            obs.observe(schemaWrap, {
                childList: true,
                subtree: true
            });

            // Observe wpbody-content for notices added elsewhere
            const wpBodyContent = document.getElementById('wpbody-content');
            if (wpBodyContent) {
                obs.observe(wpBodyContent, {
                    childList: true,
                    subtree: true
                });
            }
        })();
        </script>
        <?php
    }
 
    /**
     * Premium upgrade notice (RIGHT column)
     */
    private function render_premium_notice_panel(): void {
        $subscribe_url = '#';
        if ( class_exists( 'SRK_API_Client' ) ) {
            $subscribe_url = SRK_API_Client::get_api_url(
                SRK_API_Client::ENDPOINT_SUBSCRIBE,
                array( 'domain' => site_url() )
            );
        }
        ?>
 
        <div class="wrap srk-chat-wrap">
            <div class="srk-panel srk-locked">
                <h2 style="margin-top:0; color:var(--srk-primary);">🔒 <?php esc_html_e( 'Premium Required', 'seo-repair-kit' ); ?></h2>
                <p style="color:var(--srk-muted); margin-bottom:14px;">
                    <?php esc_html_e( 'Schema Manager is part of the Premium plan. Upgrade to unlock all schema types, previews, and AI-assisted mapping.', 'seo-repair-kit' ); ?>
                </p>
                <div style="display:flex; gap:8px; flex-wrap:wrap;">
                    <a class="srk-btn srk-btn-primary" target="_blank" rel="noopener noreferrer" href="<?php echo esc_url( $subscribe_url ); ?>">
                        <?php esc_html_e( 'Upgrade to Premium', 'seo-repair-kit' ); ?>
                    </a>
                    <form method="post" class="srk-inline-form">
                        <?php wp_nonce_field( 'srk_clear_license_cache', 'srk_cc_nonce' ); ?>
                        <input type="hidden" name="srk_clear_cache" value="1" />
                        <button type="submit" class="srk-btn srk-btn-secondary">
                            <?php esc_html_e( 'Clear License Cache', 'seo-repair-kit' ); ?>
                        </button>
                    </form>
                </div>
            </div>
        </div>  
        <?php
    }
 
    /**
     * Active UI panel (RIGHT column)
     */
    private function render_schema_ui_panel_with_clear_cache(): void {
        ?>
        <?php if ( class_exists( 'SeoRepairKit_SchemaUI' ) ) : ?>
            <div id="srk-schema-ui-root" class="srk-schema-ui-root">
                <?php
                $schema_ui = new SeoRepairKit_SchemaUI();
                if ( method_exists( $schema_ui, 'render' ) ) {
                    $schema_ui->render();
                } else {
                    ?>
                    <div class="notice notice-error"><p><?php esc_html_e( 'Schema UI render() method not found.', 'seo-repair-kit' ); ?></p></div>
                    <?php
                }
                ?>
            </div>
        <?php else : ?>
            <div class="notice notice-error">
                <p><?php esc_html_e( 'Schema UI class not found. Please ensure SeoRepairKit_SchemaUI is loaded.', 'seo-repair-kit' ); ?></p>
            </div>
        <?php endif; ?>
        <?php
    }
}
new SeoRepairKit_SchemaManager();