<?php
if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/**
 * SeoRepairKit_Upgrade class.
 *
 * Manages the Upgrade to Pro screen & purchase CTA.
 *
 * @link        https://seorepairkit.com
 * @since       2.1.0
 * @author      TorontoDigits <support@torontodigits.com>
 */
class SeoRepairKit_Upgrade {

    /**
     * Helper: days remaining until a timestamp (can be negative if past)
     */
    private function srk_days_until( $ts ) {
        if ( ! $ts ) return null;
        $now = current_time( 'timestamp' );
        return (int) floor( ( $ts - $now ) / DAY_IN_SECONDS );
    }

    /**
     * Display Upgrade page.
     * Outputs HTML for the Upgrade page, including Buy Subscription Button.
     */
    public function seo_repair_kit_upgrade_pro() {

        // Enqueue global plugin settings stylesheet if you have one registered
        wp_enqueue_style( 'srk-settings-style' );
        // Upgrade Pro CSS enqueue (contains expiry styles as well)
        wp_enqueue_style(
            'seo-repair-kit-upgrade-pro',
            plugin_dir_url(__FILE__) . './css/seo-repair-kit-upgrade-pro.css',
            array(),
            '2.1.0'
        );

        // ─────────────────────────────────────────────────────────────────────
        // License cache controls (unchanged core behavior)
        // ─────────────────────────────────────────────────────────────────────
        $domain     = site_url();
        $cache_key  = 'srk_license_status_' . md5( $domain ); // must match Admin class
        $message    = '';
        $status     = isset( $_GET['status'] ) ? sanitize_text_field( $_GET['status'] ) : '';

        if (
            is_admin() &&
            current_user_can( 'manage_options' ) &&
            isset( $_POST['srk_clear_cache'] ) &&
            $_POST['srk_clear_cache'] === '1'
        ) {
            delete_transient( $cache_key );

            if ( false === get_transient( $cache_key ) ) {
                $message = '✅ License cache cleared successfully.';
                $status  = 'success';
            } else {
                $message = '⚠️ Failed to clear license cache.';
                $status  = 'error';
            }
        }

        // Get license info (unchanged)
        $srk_admin          = new SeoRepairKit_Admin( '', '' );
        $license_info       = $srk_admin->get_license_status( $domain );
        $license_status     = $license_info['status'];
        $expiration         = $license_info['expires_at'];
        $has_chatbot        = ! empty( $license_info['has_chatbot_feature'] );
        $license_message    = $license_info['message'];
        $license_key_masked = $license_info['license_key'] ?? 'N/A';
        $plan_id            = $license_info['plan_id'] ?? 'N/A';

        $expires_ts         = $expiration ? strtotime( $expiration ) : 0;
        $days_left          = $expires_ts ? $this->srk_days_until( $expires_ts ) : null;
        $is_expired         = ( $expires_ts && $days_left !== null && $days_left < 0 );

        // Build subscribe URL (kept as-is – change if you move your CRM host)
        $subscribe_url = SRK_API_Client::get_api_url( SRK_API_Client::ENDPOINT_SUBSCRIBE, [ 'domain' => $domain ] );

        ?>
        <div class="wrap srk-upgrade-wrap">
            <h1 class="srk-title"><strong><?php esc_html_e( 'Upgrade to Pro', 'seo-repair-kit' ); ?></strong></h1>
            <p class="srk-subtitle"><?php esc_html_e( 'Unlock powerful features like Schema Manager and the AI SEO Chatbot to supercharge your workflow.', 'seo-repair-kit' ); ?></p>

            <!-- Hero Section (Link Scanner Style) -->
            <div class="srk-upgrade-hero">
                <div class="srk-upgrade-hero-content">
                    <div class="srk-upgrade-hero-icon">
                        <span class="dashicons dashicons-star-filled"></span>
                    </div>
                    <div class="srk-upgrade-hero-text">
                        <h1><?php esc_html_e( 'Upgrade to Pro', 'seo-repair-kit' ); ?></h1>
                        <p><?php esc_html_e( 'Unlock powerful features like Schema Manager, AI SEO Chatbot, and advanced keyword tracking to supercharge your SEO workflow.', 'seo-repair-kit' ); ?></p>
                        <div class="srk-upgrade-hero-badge">
                            <span class="dashicons dashicons-awards"></span>
                            <?php esc_html_e( 'PREMIUM FEATURES', 'seo-repair-kit' ); ?>
                        </div>
                    </div>
                </div>
            </div>

            <?php
            // Top ribbon: expiry awareness (non-blocking, purely informative)
            if ( 'active' === $license_status && $expiration ) :
                if ( $is_expired ) : ?>
                    <div class="notice notice-error srk-expiry-ribbon">
                        <p>❌ <?php esc_html_e( 'Your license has expired', 'seo-repair-kit' ); ?>
                            (<?php echo esc_html( date( 'F j, Y', $expires_ts ) ); ?>).
                            <a class="button button-primary" target="_blank" href="<?php echo esc_url( $subscribe_url ); ?>">
                                <?php esc_html_e( 'Renew Now', 'seo-repair-kit' ); ?>
                            </a>
                        </p>
                    </div>
                <?php elseif ( $days_left !== null && $days_left <= 15 ) : ?>
                    <div class="notice <?php echo ( $days_left <= 5 ? 'notice-error' : 'notice-warning' ); ?> srk-expiry-ribbon">
                        <p>
                            <?php if ( $days_left <= 0 ) : ?>
                                ⚠️ <?php esc_html_e( 'Your license expires today.', 'seo-repair-kit' ); ?>
                            <?php else : ?>
                                ⚠️ <?php printf( esc_html__( 'Your license will expire in %d day(s).', 'seo-repair-kit' ), $days_left ); ?>
                            <?php endif; ?>
                            <a class="button button-primary" target="_blank" href="<?php echo esc_url( $subscribe_url ); ?>">
                                <?php esc_html_e( 'Renew Now', 'seo-repair-kit' ); ?>
                            </a>
                        </p>
                    </div>
                <?php endif;
            endif;
            ?>

            <div class="srk-upgrade-grid">
                <!-- LEFT: CTA CARD -->
                <div class="srk-cta-card" role="complementary" aria-label="<?php esc_attr_e('Upgrade Card', 'seo-repair-kit'); ?>">
                    <div class="srk-cta-head">
                        <div class="srk-icon" aria-hidden="true">⚡</div>
                        <div>
                            <h3 class="srk-cta-title"><?php esc_html_e('Take SEO to the Next Level!', 'seo-repair-kit'); ?></h3>
                            <p class="srk-cta-sub"><?php esc_html_e('Everything you need to grow – in one toolkit.', 'seo-repair-kit'); ?></p>
                        </div>
                    </div>

                    <?php
                    // Small status pill
                    if ( 'active' === $license_status ) :
                        $pill_class = $is_expired ? 'srk-pill-danger' : ( $days_left !== null && $days_left <= 15 ? 'srk-pill-warning' : 'srk-pill-ok' );
                        $pill_text  = $is_expired
                            ? __( 'Expired', 'seo-repair-kit' )
                            : ( $days_left !== null ? sprintf( __( 'Active · %d day(s) left', 'seo-repair-kit' ), max( 0, $days_left ) ) : __( 'Active', 'seo-repair-kit' ) );
                        echo '<div class="srk-license-pill ' . esc_attr( $pill_class ) . '">' . esc_html( $pill_text ) . '</div>';
                    else :
                        echo '<div class="srk-license-pill srk-pill-danger">' . esc_html__( 'Inactive', 'seo-repair-kit' ) . '</div>';
                    endif;
                    ?>

                    <div class="srk-cta-body">
                        <div class="srk-feature"><i class="dashicons dashicons-yes"></i><span><?php esc_html_e('Unlimited personal websites', 'seo-repair-kit'); ?></span></div>
                        <div class="srk-feature"><i class="dashicons dashicons-yes"></i><span><?php esc_html_e('Free 15 Content AI credits', 'seo-repair-kit'); ?></span></div>
                        <div class="srk-feature"><i class="dashicons dashicons-yes"></i><span><?php esc_html_e('Track 500 keywords', 'seo-repair-kit'); ?></span></div>
                        <div class="srk-feature"><i class="dashicons dashicons-yes"></i><span><?php esc_html_e('Powerful Schema Generator', 'seo-repair-kit'); ?></span></div>
                        <div class="srk-feature"><i class="dashicons dashicons-yes"></i><span><?php esc_html_e('24/7 Priority Support', 'seo-repair-kit'); ?></span></div>
                    </div>

                    <div class="srk-cta-footer">
                        <?php if ( $license_status !== 'active' || ! $has_chatbot || $is_expired ) : ?>
                            <a class="srk-btn srk-btn-primary" target="_blank" href="<?php echo esc_url( $subscribe_url ); ?>">
                                <?php echo $is_expired ? esc_html__( '🔄 Renew License', 'seo-repair-kit' ) : esc_html__( '⚡ Upgrade Now', 'seo-repair-kit' ); ?>
                            </a>
                            <form method="post">
                                <input type="hidden" name="srk_clear_cache" value="1" />
                                <button type="submit" class="srk-btn srk-btn-secondary" aria-label="<?php esc_attr_e('Clear License Cache', 'seo-repair-kit'); ?>">
                                    <?php esc_html_e( 'Clear License Cache', 'seo-repair-kit' ); ?>
                                </button>
                            </form>
                            <p class="srk-license-message"><?php echo esc_html( $license_message ); ?></p>
                        <?php else : ?>
                            <div class="srk-license-active-status">✅ <?php esc_html_e( 'License Active – AI Chatbot Included', 'seo-repair-kit' ); ?></div>
                            <?php if ( $expiration ) : ?>
                                <p class="srk-license-expiry-info">
                                    <?php esc_html_e( 'Expires on', 'seo-repair-kit' ); ?>:
                                    <?php echo esc_html( date( 'F j, Y', $expires_ts ) ); ?>
                                    <?php if ( $days_left !== null ) : ?>
                                        (<?php printf( esc_html__( '%d day(s) left', 'seo-repair-kit' ), max( 0, $days_left ) ); ?>)
                                    <?php endif; ?>
                                </p>
                            <?php endif; ?>
                            <form method="post">
                                <input type="hidden" name="srk_clear_cache" value="1" />
                                <button type="submit" class="srk-btn srk-btn-secondary">
                                    <?php esc_html_e( 'Clear License Cache', 'seo-repair-kit' ); ?>
                                </button>
                            </form>
                            <p class="srk-license-message"><?php echo esc_html( $license_message ); ?></p>
                        <?php endif; ?>
                    </div>
                </div>

                <!-- RIGHT: DETAILS / BENEFITS / LICENSE -->
                <div class="srk-upgrade-content">
                    <!-- Benefits grid -->
                    <div class="srk-panel">
                        <h2 class="srk-panel-title"><?php esc_html_e('Why Upgrade to Pro?', 'seo-repair-kit'); ?></h2>
                        <div class="srk-benefits" role="list">
                            <div class="srk-benefit srk-benefit-schema" role="listitem">
                                <div class="srk-benefit-icon">
                                    <span class="dashicons dashicons-editor-code"></span>
                                </div>
                                <div class="srk-benefit-content">
                                    <h4><?php esc_html_e('Schema Manager', 'seo-repair-kit'); ?></h4>
                                    <p><?php esc_html_e('Generate rich, valid schema for posts, pages, and custom types with a few clicks.', 'seo-repair-kit'); ?></p>
                                </div>
                            </div>
                            <div class="srk-benefit srk-benefit-chatbot" role="listitem">
                                <div class="srk-benefit-icon">
                                    <span class="dashicons dashicons-format-chat"></span>
                                </div>
                                <div class="srk-benefit-content">
                                    <h4><?php esc_html_e('AI SEO Chatbot', 'seo-repair-kit'); ?></h4>
                                    <p><?php esc_html_e('Ask SEO questions in plain English and get actionable guidance, instantly.', 'seo-repair-kit'); ?></p>
                                </div>
                            </div>
                            <div class="srk-benefit srk-benefit-keytrack" role="listitem">
                                <div class="srk-benefit-icon">
                                    <span class="dashicons dashicons-chart-line"></span>
                                </div>
                                <div class="srk-benefit-content">
                                    <h4><?php esc_html_e('Keyword Tracking', 'seo-repair-kit'); ?></h4>
                                    <p><?php esc_html_e('Monitor up to 500 keywords with trends and quick insights.', 'seo-repair-kit'); ?></p>
                                </div>
                            </div>
                            <div class="srk-benefit srk-benefit-audit" role="listitem">
                                <div class="srk-benefit-icon">
                                    <span class="dashicons dashicons-admin-tools"></span>
                                </div>
                                <div class="srk-benefit-content">
                                    <h4><?php esc_html_e('Audit & Fixes', 'seo-repair-kit'); ?></h4>
                                    <p><?php esc_html_e('Auto-detect critical issues, get step-by-step fixes, and track progress.', 'seo-repair-kit'); ?></p>
                                </div>
                            </div>
                            <div class="srk-benefit srk-benefit-credits" role="listitem">
                                <div class="srk-benefit-icon">
                                    <span class="dashicons dashicons-edit"></span>
                                </div>
                                <div class="srk-benefit-text">
                                    <h4><?php esc_html_e('Content Credits', 'seo-repair-kit'); ?></h4>
                                    <p><?php esc_html_e('Get 15 AI content credits to boost titles, descriptions & FAQs.', 'seo-repair-kit'); ?></p>
                                </div>
                            </div>
                            <div class="srk-benefit srk-benefit-support" role="listitem">
                                <div class="srk-benefit-icon">
                                    <span class="dashicons dashicons-admin-users"></span>
                                </div>
                                <div class="srk-benefit-content">
                                    <h4><?php esc_html_e('Priority Support', 'seo-repair-kit'); ?></h4>
                                    <p><?php esc_html_e('Email us at support@seorepairkit.com for direct access to our support team for fast response.', 'seo-repair-kit'); ?></p>
                                </div>
                            </div>
                        </div>
                    </div>

                    <!-- License panel -->
                    <div class="srk-panel srk-license">
                        <h2 class="srk-panel-title">🔐 <?php esc_html_e('License Status ( Clear License Cache )', 'seo-repair-kit'); ?></h2>
                        <div class="srk-kv">
                            <span class="key"><?php esc_html_e('License Key', 'seo-repair-kit'); ?></span>
                            <span class="val"><?php echo esc_html( $license_key_masked ); ?></span>

                            <span class="key"><?php esc_html_e('Plan ID', 'seo-repair-kit'); ?></span>
                            <span class="val"><?php echo esc_html( $plan_id ); ?></span>

                            <span class="key"><?php esc_html_e('Status', 'seo-repair-kit'); ?></span>
                            <span class="val"><?php echo esc_html( $license_status ?: 'unknown' ); ?></span>

                            <span class="key"><?php esc_html_e('Expires At', 'seo-repair-kit'); ?></span>
                            <span class="val"><?php echo esc_html( $expiration ?: 'N/A' ); ?></span>

                            <span class="key"><?php esc_html_e('Chatbot Access', 'seo-repair-kit'); ?></span>
                            <span class="val"><?php echo $has_chatbot ? '' . esc_html__('Enabled','seo-repair-kit') : '' . esc_html__('Not included','seo-repair-kit'); ?></span>
                        </div>

                        <div class="srk-notice">
                            <?php if ( $message ) : ?>
                                <div class="notice notice-<?php echo $status === 'success' ? 'success' : 'error'; ?>">
                                    <p><?php echo esc_html( $message ); ?></p>
                                </div>
                            <?php endif; ?>
                        </div>
                    </div>

                    <!-- Security note -->
                    <div class="srk-panel srk-security-note">
                        <span class="dashicons dashicons-lock"></span>
                        <p><?php esc_html_e('All payments are securely processed through Stripe. You will be redirected to complete your purchase.', 'seo-repair-kit'); ?></p>
                    </div>
                </div>
            </div>
        </div>
        <?php
    }
}
