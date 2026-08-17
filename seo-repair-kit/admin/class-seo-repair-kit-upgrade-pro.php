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
            '2.1.9'
        );

        // ─────────────────────────────────────────────────────────────────────
        // License cache controls (unchanged core behavior)
        // ─────────────────────────────────────────────────────────────────────
        $domain     = site_url();
        $message    = '';
        $status     = isset( $_GET['srk_license_refresh'] ) ? sanitize_key( wp_unslash( $_GET['srk_license_refresh'] ) ) : '';

        if ( 'success' === $status ) {
            $message = __( 'License status refreshed from CRM successfully.', 'seo-repair-kit' );
        } elseif ( 'error' === $status ) {
            $message = __( 'License status could not be refreshed from CRM. Please check connectivity or CRM configuration.', 'seo-repair-kit' );
        }

        // Get license info through the lightweight shared helper.
        $license_info       = class_exists( 'SRK_License_Helper' ) ? SRK_License_Helper::get_license_info() : array();
        $license_info       = is_array( $license_info ) ? $license_info : array();
        $license_active     = ( 'active' === ( $license_info['status'] ?? '' ) );
        $license_info       = wp_parse_args(
            $license_info,
            array(
                'status'              => $license_active ? 'active' : 'inactive',
                'expires_at'          => null,
                'plan_id'             => null,
                'has_chatbot_feature' => false,
                'license_key'         => null,
                'message'             => $license_active ? 'License is active.' : 'License is inactive.',
                'last_checked_at'     => null,
                'cache_expires_at'    => null,
            )
        );
        $license_status     = $license_info['status'];
        $expiration         = $license_info['expires_at'];
        $has_chatbot        = ! empty( $license_info['has_chatbot_feature'] );
        $license_message    = $license_info['message'];
        $license_key_masked = $license_info['license_key'] ?? 'N/A';
        $plan_id            = $license_info['plan_id'] ?? 'N/A';
        $last_checked_at    = $license_info['last_checked_at'] ?? null;
        $cache_expires_at   = $license_info['cache_expires_at'] ?? null;

        $expires_ts         = $expiration ? strtotime( $expiration ) : 0;
        $days_left          = $expires_ts ? $this->srk_days_until( $expires_ts ) : null;
        $is_expired         = ( $expires_ts && $days_left !== null && $days_left < 0 );

        // Build subscribe URL (kept as-is – change if you move your CRM host)
        $subscribe_url = SRK_API_Client::get_api_url( SRK_API_Client::ENDPOINT_SUBSCRIBE, [ 'domain' => $domain ] );

        ?>
        <div class="wrap srk-upgrade-wrap">
            <h1 class="srk-title"><strong><?php esc_html_e( 'Customize Your SEO Repair Kit Plan', 'seo-repair-kit' ); ?></strong></h1>
            <p class="srk-subtitle"><?php esc_html_e( 'Choose the website plan that fits your SEO workflow.', 'seo-repair-kit' ); ?></p>

            <!-- Hero Section (Links Manager Style) -->
            <div class="srk-upgrade-hero">
                <div class="srk-upgrade-hero-content">
                    <div class="srk-upgrade-hero-icon">
                        <span class="dashicons dashicons-star-filled"></span>
                    </div>
                    <div class="srk-upgrade-hero-text">
                        <h1><?php esc_html_e( 'Simple, Flexible SEO Pricing', 'seo-repair-kit' ); ?></h1>
                        <p><?php esc_html_e( 'Pick a plan by website count, then manage your SEO tools from one plugin.', 'seo-repair-kit' ); ?></p>
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
                                ⚠️ <?php printf( esc_html__( 'Your license will expire in %d day(s).', 'seo-repair-kit' ), (int) $days_left ); ?>
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
                        <div class="srk-plan-table-wrap" aria-label="<?php esc_attr_e( 'SEO Repair Kit plan pricing', 'seo-repair-kit' ); ?>">
                            <table class="srk-plan-table">
                                <thead>
                                    <tr>
                                        <th><?php esc_html_e( 'Plan', 'seo-repair-kit' ); ?></th>
                                        <th><?php esc_html_e( 'Websites', 'seo-repair-kit' ); ?></th>
                                        <th><?php esc_html_e( 'Monthly', 'seo-repair-kit' ); ?></th>
                                        <th><?php esc_html_e( 'Yearly', 'seo-repair-kit' ); ?></th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <tr>
                                        <td><?php esc_html_e( 'Individual', 'seo-repair-kit' ); ?></td>
                                        <td><?php esc_html_e( '1', 'seo-repair-kit' ); ?></td>
                                        <td><?php esc_html_e( '£7.49/month', 'seo-repair-kit' ); ?></td>
                                        <td><?php esc_html_e( '£36.99/year', 'seo-repair-kit' ); ?></td>
                                    </tr>
                                    <tr>
                                        <td><?php esc_html_e( 'Freelancer', 'seo-repair-kit' ); ?></td>
                                        <td><?php esc_html_e( '10', 'seo-repair-kit' ); ?></td>
                                        <td><?php esc_html_e( '£11.49/month', 'seo-repair-kit' ); ?></td>
                                        <td><?php esc_html_e( '£88.99/year', 'seo-repair-kit' ); ?></td>
                                    </tr>
                                    <tr>
                                        <td><?php esc_html_e( 'Studio', 'seo-repair-kit' ); ?></td>
                                        <td><?php esc_html_e( '25', 'seo-repair-kit' ); ?></td>
                                        <td><?php esc_html_e( '£22.49/month', 'seo-repair-kit' ); ?></td>
                                        <td><?php esc_html_e( '£179/year', 'seo-repair-kit' ); ?></td>
                                    </tr>
                                    <tr>
                                        <td><?php esc_html_e( 'Agency', 'seo-repair-kit' ); ?></td>
                                        <td><?php esc_html_e( '100', 'seo-repair-kit' ); ?></td>
                                        <td><?php esc_html_e( '£44.99/month', 'seo-repair-kit' ); ?></td>
                                        <td><?php esc_html_e( '£359/year', 'seo-repair-kit' ); ?></td>
                                    </tr>
                                    <tr>
                                        <td><?php esc_html_e( 'Enterprise', 'seo-repair-kit' ); ?></td>
                                        <td><?php esc_html_e( '300', 'seo-repair-kit' ); ?></td>
                                        <td><?php esc_html_e( '£111.99/month', 'seo-repair-kit' ); ?></td>
                                        <td><?php esc_html_e( '£899/year', 'seo-repair-kit' ); ?></td>
                                    </tr>
                                </tbody>
                            </table>
                        </div>
                    </div>

                    <div class="srk-cta-footer">
                        <?php if ( $license_status !== 'active' || ! $has_chatbot || $is_expired ) : ?>
                            <a class="srk-btn srk-btn-primary" target="_blank" href="<?php echo esc_url( $subscribe_url ); ?>">
                                <?php echo $is_expired ? esc_html__( 'Renew License', 'seo-repair-kit' ) : esc_html__( 'Customize Plan', 'seo-repair-kit' ); ?>
                            </a>
                            <?php if ( 'active' === $license_status && ! $is_expired ) : ?>
                                <a class="srk-btn srk-btn-secondary" target="_blank" href="<?php echo esc_url( $subscribe_url ); ?>">
                                    <?php esc_html_e( 'Customize Your Plan', 'seo-repair-kit' ); ?>
                                </a>
                            <?php endif; ?>
                            <form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
                                <?php wp_nonce_field( 'srk_refresh_license_status' ); ?>
                                <input type="hidden" name="action" value="srk_refresh_license_status" />
                                <button type="submit" class="srk-btn srk-btn-secondary" aria-label="<?php esc_attr_e('Refresh License Status', 'seo-repair-kit'); ?>">
                                    <?php esc_html_e( 'Refresh License Status', 'seo-repair-kit' ); ?>
                                </button>
                            </form>
                            <p class="srk-license-message"><?php echo esc_html( $license_message ); ?></p>
                        <?php else : ?>
                            <div class="srk-license-active-status"><?php esc_html_e( 'License Active', 'seo-repair-kit' ); ?></div>
                            <?php if ( $expiration ) : ?>
                                <p class="srk-license-expiry-info">
                                    <?php esc_html_e( 'Expires on', 'seo-repair-kit' ); ?>:
                                    <?php echo esc_html( date( 'F j, Y', $expires_ts ) ); ?>
                                    <?php if ( $days_left !== null ) : ?>
                                        (<?php printf( esc_html__( '%d day(s) left', 'seo-repair-kit' ), (int) max( 0, $days_left ) ); ?>)
                                    <?php endif; ?>
                                </p>
                            <?php endif; ?>
                            <a class="srk-btn srk-btn-primary" target="_blank" href="<?php echo esc_url( $subscribe_url ); ?>">
                                <?php esc_html_e( 'Customize Your Plan', 'seo-repair-kit' ); ?>
                            </a>
                            <form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
                                <?php wp_nonce_field( 'srk_refresh_license_status' ); ?>
                                <input type="hidden" name="action" value="srk_refresh_license_status" />
                                <button type="submit" class="srk-btn srk-btn-secondary">
                                    <?php esc_html_e( 'Refresh License Status', 'seo-repair-kit' ); ?>
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
                        <h2 class="srk-panel-title"><?php esc_html_e('Choose the plan that fits your workflow', 'seo-repair-kit'); ?></h2>
                        <p class="srk-panel-subtitle"><?php esc_html_e('Plans scale by website count, from an individual site to agency and enterprise portfolios.', 'seo-repair-kit'); ?></p>
                        <div class="srk-benefits" role="list">
                            <div class="srk-benefit srk-benefit-spam-monitor" role="listitem">
                                <div class="srk-benefit-icon">
                                    <span class="dashicons dashicons-shield-alt"></span>
                                </div>
                                <div class="srk-benefit-content">
                                    <h4><?php esc_html_e('Spam Monitor', 'seo-repair-kit'); ?></h4>
                                    <p><?php esc_html_e('Monitor what search engines see, detect suspicious indexed URLs, schedule scans, and connect supported SERP providers when available in your plan.', 'seo-repair-kit'); ?></p>
                                </div>
                            </div>
                            <div class="srk-benefit srk-benefit-link-scanner" role="listitem">
                                <div class="srk-benefit-icon">
                                    <span class="dashicons dashicons-admin-links"></span>
                                </div>
                                <div class="srk-benefit-content">
                                    <h4><?php esc_html_e('Unlimited Broken Links + 404 Monitor', 'seo-repair-kit'); ?></h4>
                                    <p><?php esc_html_e('Find broken links, check internal and external URLs, monitor 404 errors, view source pages, and keep scan history.', 'seo-repair-kit'); ?></p>
                                </div>
                            </div>
                            <div class="srk-benefit srk-benefit-redirection" role="listitem">
                                <div class="srk-benefit-icon">
                                    <span class="dashicons dashicons-randomize"></span>
                                </div>
                                <div class="srk-benefit-content">
                                    <h4><?php esc_html_e('Smart Redirects', 'seo-repair-kit'); ?></h4>
                                    <p><?php esc_html_e('Create and manage redirects to keep changed URLs organized safely inside WordPress.', 'seo-repair-kit'); ?></p>
                                </div>
                            </div>
                            <div class="srk-benefit srk-benefit-schema" role="listitem">
                                <div class="srk-benefit-icon">
                                    <span class="dashicons dashicons-editor-code"></span>
                                </div>
                                <div class="srk-benefit-content">
                                    <h4><?php esc_html_e('Schema Manager', 'seo-repair-kit'); ?></h4>
                                    <p><?php esc_html_e('Create, manage, validate, and deploy structured data without manually writing complex Schema markup.', 'seo-repair-kit'); ?></p>
                                </div>
                            </div>
                            <div class="srk-benefit srk-benefit-keytrack" role="listitem">
                                <div class="srk-benefit-icon">
                                    <span class="dashicons dashicons-chart-line"></span>
                                </div>
                                <div class="srk-benefit-content">
                                    <h4><?php esc_html_e('KeyTrack', 'seo-repair-kit'); ?></h4>
                                    <p><?php esc_html_e('Review SEO health signals, receive alerts, and keep visibility into issues that need attention.', 'seo-repair-kit'); ?></p>
                                </div>
                            </div>
                            <div class="srk-benefit srk-benefit-support" role="listitem">
                                <div class="srk-benefit-icon">
                                    <span class="dashicons dashicons-admin-users"></span>
                                </div>
                                <div class="srk-benefit-content">
                                    <h4><?php esc_html_e('Priority Support', 'seo-repair-kit'); ?></h4>
                                    <p>
                                        <?php esc_html_e('Need help choosing the right plan or comparing freelancer and agency packages?', 'seo-repair-kit'); ?>
                                        <a class="srk-support-email" href="<?php echo esc_url( 'mailto:support@seorepairkit.com' ); ?>">support@seorepairkit.com</a>
                                    </p>
                                </div>
                            </div>
                        </div>
                    </div>

                    <!-- License panel -->
                    <div class="srk-panel srk-license">
                        <h2 class="srk-panel-title"><?php esc_html_e('License Status', 'seo-repair-kit'); ?></h2>
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

                            <span class="key"><?php esc_html_e('Last Checked', 'seo-repair-kit'); ?></span>
                            <span class="val"><?php echo esc_html( $last_checked_at ?: __( 'Not checked yet', 'seo-repair-kit' ) ); ?></span>

                            <span class="key"><?php esc_html_e('Cache Expires', 'seo-repair-kit'); ?></span>
                            <span class="val"><?php echo esc_html( $cache_expires_at ?: __( 'N/A', 'seo-repair-kit' ) ); ?></span>
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
