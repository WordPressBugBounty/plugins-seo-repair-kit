<?php
if ( ! defined( 'ABSPATH' ) ) { exit; }

/**
 * SRK Chatbot — Admin Screen
 */
class SeoRepairKit_Chatbot {

    public function render_chatbot_page() {

        if ( ! current_user_can('manage_options') ) {
            wp_die( esc_html__('You do not have permission to view this page.', 'seo-repair-kit') );
        }

        // License check (unchanged)
        $srk_admin       = new SeoRepairKit_Admin('', '');
        $current_domain  = site_url();
        $license_info    = $srk_admin->get_license_status($current_domain);
        $is_active_chat  = ($license_info['status'] === 'active' && !empty($license_info['has_chatbot_feature']));

        // Subscribe URL
        $subscribe_url = SRK_API_Client::get_api_url(
            SRK_API_Client::ENDPOINT_SUBSCRIBE,
            [ 'domain' => $current_domain ]
        );

        // Styles
        wp_enqueue_style(
            'n8n-chat-style',
            'https://cdn.jsdelivr.net/npm/@n8n/chat/dist/style.css',
            [],
            null
        );
        wp_enqueue_style(
            'seo-repair-kit-chatbot',
            plugin_dir_url(__FILE__) . './css/seo-repair-kit-chatbot.css',
            array(),
            '2.1.0'
        );
        wp_enqueue_style('dashicons');

        // If unlocked, fetch the webhook
        $webhook_url = '';
        $fetch_error = '';
        if ( $is_active_chat ) {
            $cfg = SRK_API_Client::fetch_chatbot_config( $current_domain );
            if ( !empty($cfg['ok']) && !empty($cfg['webhook_url']) ) {
                $webhook_url = $cfg['webhook_url'];
            } else {
                $fetch_error = isset($cfg['reason']) ? $cfg['reason'] : '';
            }
        }

        // Register module JS and pass config
        wp_register_script(
            'srk-chatbot-js',
            plugins_url('admin/js/seo-repair-kit-chatbot.js', dirname(__FILE__)),
            [],
            '1.0.1',
            true
        );

        // Force type="module" for this handle
        add_filter('script_loader_tag', function($tag, $handle) {
            if ( $handle === 'srk-chatbot-js' ) {
                if ( strpos($tag, 'type="module"') === false ) {
                    $tag = str_replace( ' src', ' type="module" src', $tag );
                }
            }
            return $tag;
        }, 10, 2);

        wp_localize_script('srk-chatbot-js', 'srkChatbot', [
            'webhookUrl'       => $webhook_url,
            'mode'             => 'fullscreen',
            'target'           => '#n8n-chat',
            'showWelcome'      => true,
            'initialMessages'  => [
                'Hey there! 👋 I\'m your SEO Assistant.',
                'Ask me anything about SEO, WordPress optimization, broken links, redirects, schema markup, or site performance. I\'m here to help!'
            ],
            'i18n' => [
                'en' => [
                    'title'            => 'SEO AI Assistant 🚀',
                    'subtitle'         => 'Your intelligent helper for SEO, WordPress & technical optimization.',
                    'getStarted'       => 'Start Conversation',
                    'inputPlaceholder' => 'Type your SEO question here...',
                ],
            ],
        ]);

        ?>

        <div class="srk-chatbot-wrapper">
            <!-- Hero Section -->
            <div class="srk-chatbot-hero">
                <div class="srk-chatbot-hero-content">
                    <div class="srk-chatbot-hero-icon">
                        <span class="dashicons dashicons-format-chat"></span>
                    </div>
                    <div class="srk-chatbot-hero-text">
                        <h1><?php esc_html_e('SEO AI Assistant', 'seo-repair-kit'); ?></h1>
                        <p><?php esc_html_e('Get instant, actionable SEO guidance. Ask questions about schema, sitemaps, Core Web Vitals, redirects, and more.', 'seo-repair-kit'); ?></p>
                        <div class="srk-chatbot-hero-badge">
                            <span class="dashicons dashicons-admin-plugins"></span>
                            <?php esc_html_e('AI POWERED', 'seo-repair-kit'); ?>
                        </div>
                    </div>
                </div>
            </div>

            <!-- Anchor where all WP admin notices will appear (below hero) -->
            <div id="srk-admin-notices" class="srk-chatbot-notices" aria-live="polite"></div>

            <script>
            (function(){
              // Robust relocation: pull notices from the whole admin content area and append to our anchor.
              const anchor = document.getElementById('srk-admin-notices');
              if (!anchor) return;

              // WP admin content root
              const wpBodyContent = document.getElementById('wpbody-content') || document.body;

              const SELECTOR = '.notice, .updated, .error, .update-nag, .notice-error, .notice-warning, .notice-success';

              function moveNotices(scope) {
                const container = scope || wpBodyContent;
                if (!container) return;

                // Collect all notices that are not already inside our anchor
                const notices = container.querySelectorAll(SELECTOR);
                notices.forEach(function(n) {
                  if (!anchor.contains(n)) {
                    // Ensure notices are full-width in our area
                    n.style.marginTop = '12px';
                    n.style.marginBottom = '0';
                    anchor.appendChild(n);
                  }
                });
              }

              // Initial move on DOM ready (covers server-rendered notices)
              if (document.readyState === 'loading') {
                document.addEventListener('DOMContentLoaded', function(){ moveNotices(); });
              } else {
                moveNotices();
              }

              // Observe future changes: some plugins inject notices after load
              const obs = new MutationObserver(function(muts){
                for (const m of muts) {
                  m.addedNodes && m.addedNodes.forEach(function(node){
                    if (node.nodeType === 1) {
                      if (node.matches && node.matches(SELECTOR)) {
                        moveNotices(node.parentNode || node);
                      } else if (node.querySelector) {
                        const has = node.querySelector(SELECTOR);
                        if (has) moveNotices(node);
                      }
                    }
                  });
                }
              });
              try { obs.observe(wpBodyContent, { childList: true, subtree: true }); } catch(e){}
            })();
            </script>

            <div class="srk-chatbot-grid">
                <!-- Main Chat Area -->
                <div class="srk-chatbot-main">
                    <div class="srk-chatbot-card">
                        <div class="srk-chatbot-card-header">
                            <div class="srk-chatbot-card-title">
                                <span class="dashicons dashicons-admin-comments"></span>
                                <h2><?php echo $is_active_chat ? esc_html__('Chat Console','seo-repair-kit') : esc_html__('Chat Preview', 'seo-repair-kit'); ?></h2>
                            </div>
                            <?php if ( $is_active_chat ) : ?>
                                <span class="srk-chatbot-status srk-status-active">
                                    <span class="srk-status-dot"></span>
                                    <?php esc_html_e('Licensed & Active', 'seo-repair-kit'); ?>
                                </span>
                            <?php else: ?>
                                <span class="srk-chatbot-status srk-status-locked">
                                    <span class="dashicons dashicons-lock"></span>
                                    <?php esc_html_e('Premium Feature', 'seo-repair-kit'); ?>
                                </span>
                            <?php endif; ?>
                        </div>

                        <div class="srk-chatbot-card-body">
                            <?php if ( $is_active_chat ) : ?>
                                <?php if ( empty($webhook_url) ): ?>
                                    <div class="srk-chatbot-error">
                                        <span class="dashicons dashicons-warning"></span>
                                        <div>
                                            <strong><?php esc_html_e('Configuration Required', 'seo-repair-kit'); ?></strong>
                                            <p><?php esc_html_e('Chatbot webhook URL is not available. Please contact support.', 'seo-repair-kit'); ?></p>
                                            <?php if (!empty($fetch_error)): ?>
                                                <p class="srk-error-detail"><?php echo esc_html('Details: ' . $fetch_error); ?></p>
                                            <?php endif; ?>
                                        </div>
                                    </div>
                                <?php endif; ?>

                                <!-- Loader (persists until chat mounts OR a notice exists) -->
                                <div id="srk-chat-loader" class="srk-chatbot-loader" role="status" aria-live="polite" aria-busy="true">
                                    <div class="srk-chatbot-loader-content">
                                        <div class="srk-chatbot-spinner"></div>
                                        <div class="srk-chatbot-loader-text">
                                            <?php esc_html_e('Preparing chat interface…', 'seo-repair-kit'); ?>
                                        </div>
                                        <div class="srk-chatbot-skeleton"></div>
                                    </div>
                                </div>

                                <!-- Chat target (hidden until ready) -->
                                <div
                                    id="n8n-chat"
                                    class="srk-chat-container"
                                    aria-busy="true"
                                    aria-label="<?php esc_attr_e('SRK Chatbot Window','seo-repair-kit'); ?>">
                                </div>

                                <div class="srk-chatbot-footer-note">
                                    <span class="dashicons dashicons-shield"></span>
                                    <?php esc_html_e('Conversations are processed securely. Do not share sensitive credentials.', 'seo-repair-kit'); ?>
                                </div>

                                <?php
                                // Enqueue module in admin footer and attach loader controller
                                add_action('admin_footer', function () {
                                    wp_enqueue_script('srk-chatbot-js');
                                    ?>
                                    <script>
                                    (function(){
                                        const chatEl = document.getElementById('n8n-chat');
                                        const loader = document.getElementById('srk-chat-loader');
                                        const cardBody = loader ? loader.closest('.srk-chatbot-card-body') : null;

                                        function hideLoader(){
                                            if (loader) loader.remove();
                                            if (chatEl){
                                                chatEl.removeAttribute('aria-busy');
                                                chatEl.style.visibility = 'visible';
                                            }
                                        }

                                        function hasNotices(scope){
                                            if (!scope) return false;
                                            return !!scope.querySelector('.notice, .updated, .error, .update-nag, .notice-error, .notice-warning, .notice-success');
                                        }

                                        window.addEventListener('srk:chat:ready', hideLoader, { once:true });
                                        window.addEventListener('srk:chat:error', function(){ hideLoader(); }, { once:true });

                                        if (chatEl){
                                            chatEl.style.visibility = 'hidden';
                                            const obs = new MutationObserver(function(){
                                                if (chatEl.childElementCount > 0){
                                                    hideLoader();
                                                    obs.disconnect();
                                                }
                                            });
                                            try { obs.observe(chatEl, { childList:true, subtree:true }); } catch(e){}
                                        }

                                        if (cardBody){
                                            if (hasNotices(cardBody)){ hideLoader(); }
                                            const noticeObs = new MutationObserver(function(muts){
                                                for (const m of muts){
                                                    if ([...m.addedNodes].some(n => n.nodeType === 1 && hasNotices(n))){
                                                        hideLoader();
                                                        noticeObs.disconnect();
                                                        break;
                                                    }
                                                }
                                            });
                                            try { noticeObs.observe(cardBody, { childList:true, subtree:true }); } catch(e){}
                                        }
                                    })();
                                    </script>
                                    <?php
                                });
                                ?>

                            <?php else: ?>
                                <!-- Locked / Upsell -->
                                <div class="srk-chatbot-upsell" role="region" aria-label="<?php esc_attr_e('Upgrade to unlock Chatbot','seo-repair-kit'); ?>">
                                    <div class="srk-upsell-icon">
                                        <span class="dashicons dashicons-lock"></span>
                                    </div>
                                    <h3><?php esc_html_e('Unlock the SEO AI Assistant', 'seo-repair-kit'); ?></h3>
                                    <p><?php esc_html_e('Chat in plain English and get instant, tailored guidance for your site—schema, sitemaps, Core Web Vitals, redirects, and more.', 'seo-repair-kit'); ?></p>

                                    <div class="srk-upsell-features">
                                        <div class="srk-upsell-feature">
                                            <span class="dashicons dashicons-yes-alt"></span>
                                            <span><?php esc_html_e('Schema & CTR improvements', 'seo-repair-kit'); ?></span>
                                        </div>
                                        <div class="srk-upsell-feature">
                                            <span class="dashicons dashicons-yes-alt"></span>
                                            <span><?php esc_html_e('404s, sitemaps & robots.txt fixes', 'seo-repair-kit'); ?></span>
                                        </div>
                                        <div class="srk-upsell-feature">
                                            <span class="dashicons dashicons-yes-alt"></span>
                                            <span><?php esc_html_e('Keyword & On-Page recommendations', 'seo-repair-kit'); ?></span>
                                        </div>
                                        <div class="srk-upsell-feature">
                                            <span class="dashicons dashicons-yes-alt"></span>
                                            <span><?php esc_html_e('Priority support & updates', 'seo-repair-kit'); ?></span>
                                        </div>
                                    </div>

                                    <a class="srk-upsell-button" href="<?php echo esc_url($subscribe_url); ?>" target="_blank" rel="noopener">
                                        <span class="dashicons dashicons-star-filled"></span>
                                        <?php esc_html_e('Get Premium Now', 'seo-repair-kit'); ?>
                                    </a>
                                    
                                    <div class="srk-upsell-legal">
                                        <span class="dashicons dashicons-shield"></span>
                                        <?php esc_html_e('Secure payments via Stripe', 'seo-repair-kit'); ?>
                                    </div>

                                    <?php if (!empty($license_info['message'])): ?>
                                        <p class="srk-upsell-message"><?php echo esc_html( $license_info['message'] ); ?></p>
                                    <?php endif; ?>
                                </div>
                            <?php endif; ?>
                        </div>
                    </div>
                </div>

                <!-- Sidebar -->
                <aside class="srk-chatbot-sidebar">
                    <div class="srk-chatbot-sidebar-card">
                        <div class="srk-sidebar-header">
                            <span class="dashicons dashicons-editor-help"></span>
                            <h4><?php esc_html_e('What can I ask?', 'seo-repair-kit'); ?></h4>
                        </div>
                        <ul class="srk-sidebar-list">
                            <li>
                                <span class="srk-list-icon"><span class="dashicons dashicons-format-chat"></span></span>
                                <span><?php esc_html_e('"Why are my product pages not indexing?"', 'seo-repair-kit'); ?></span>
                            </li>
                            <li>
                                <span class="srk-list-icon"><span class="dashicons dashicons-format-chat"></span></span>
                                <span><?php esc_html_e('"Generate FAQ schema for this page."', 'seo-repair-kit'); ?></span>
                            </li>
                            <li>
                                <span class="srk-list-icon"><span class="dashicons dashicons-format-chat"></span></span>
                                <span><?php esc_html_e('"Fix render-blocking resources."', 'seo-repair-kit'); ?></span>
                            </li>
                            <li>
                                <span class="srk-list-icon"><span class="dashicons dashicons-format-chat"></span></span>
                                <span><?php esc_html_e('"Help me create a redirect map."', 'seo-repair-kit'); ?></span>
                            </li>
                        </ul>
                    </div>

                    <div class="srk-chatbot-sidebar-card">
                        <div class="srk-sidebar-header">
                            <span class="dashicons dashicons-lightbulb"></span>
                            <h4><?php esc_html_e('Tips for best answers', 'seo-repair-kit'); ?></h4>
                        </div>
                        <ul class="srk-sidebar-list srk-tips-list">
                            <li>
                                <span class="srk-tip-number">1</span>
                                <span><?php esc_html_e('Ask one focused question at a time.', 'seo-repair-kit'); ?></span>
                            </li>
                            <li>
                                <span class="srk-tip-number">2</span>
                                <span><?php esc_html_e('Share context like CMS, plugin, or theme.', 'seo-repair-kit'); ?></span>
                            </li>
                            <li>
                                <span class="srk-tip-number">3</span>
                                <span><?php esc_html_e('Paste short error snippets when relevant.', 'seo-repair-kit'); ?></span>
                            </li>
                        </ul>
                    </div>

                    <div class="srk-chatbot-sidebar-card srk-sidebar-cta">
                        <span class="dashicons dashicons-megaphone"></span>
                        <p><?php esc_html_e('Need help? Our support team is ready to assist you.', 'seo-repair-kit'); ?></p>
                        <a href="https://seorepairkit.com/support" target="_blank" rel="noopener" class="srk-sidebar-link">
                            <?php esc_html_e('Contact Support', 'seo-repair-kit'); ?>
                            <span class="dashicons dashicons-external"></span>
                        </a>
                    </div>
                </aside>
            </div>
        </div>
        <?php
    }
}
