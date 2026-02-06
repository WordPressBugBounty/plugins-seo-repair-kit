<?php
if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/**
 * Public-facing functionality for Bot Manager (Robots.txt and LLMs.txt files)
 * 
 * Handles serving robots.txt and llms.txt files to visitors and crawlers.
 * 
 * @since    2.1.1
 * @package  Seo_Repair_Kit
 */
class SeoRepairKit_Robots_Public {

    /**
     * The version of this plugin.
     *
     * @since    2.1.1
     * @access   private
     * @var      string    $version    The current version of this plugin.
     */
    private $version;

    /**
     * Initialize the class and set its properties.
     *
     * @since    2.1.1
     */
    public function __construct() {
        $this->version = defined( 'SEO_REPAIR_KIT_VERSION' ) ? SEO_REPAIR_KIT_VERSION : '2.1.2';

        // Override WordPress default robots.txt
        add_filter( 'robots_txt', array( $this, 'custom_robots_txt' ), 10, 2 );

        // Serve LLMs.txt file
        add_action( 'init', array( $this, 'add_llms_txt_rewrite' ) );
        add_action( 'template_redirect', array( $this, 'serve_llms_txt' ) );
    }

    /**
     * Override WordPress default robots.txt with custom content.
     * Also adds bot blocking rules for LLMs.txt if configured.
     *
     * @since    2.1.1
     * @param    string $output The default robots.txt output
     * @param    bool   $public Whether the site is public
     * @return   string Custom robots.txt content
     */
    public function custom_robots_txt( $output, $public ) {
        // Get custom robots.txt content from option
        $custom_robots = get_option( 'srk_robots_txt_content', '' );

        // Get bot access control settings for LLMs.txt
        $llms_settings = get_option( 'srk_llms_generator_settings', array() );
        $allowed_bots = isset( $llms_settings['allowed_bots'] ) ? $llms_settings['allowed_bots'] : array();
        
        // Add bot blocking rules to robots.txt if bots are configured
        $bot_rules = '';
        if ( ! empty( $allowed_bots ) && is_array( $allowed_bots ) ) {
            $popular_bots = $this->get_popular_ai_bots();
            $all_bots_keys = array_keys( $popular_bots );
            
            // Only add rules if user has customized (not all bots allowed)
            if ( count( $allowed_bots ) !== count( $all_bots_keys ) || ! empty( array_diff( $all_bots_keys, $allowed_bots ) ) ) {
                // Add rules to block deselected bots from accessing /llms.txt
                foreach ( $popular_bots as $bot_key => $bot_info ) {
                    if ( ! in_array( $bot_key, $allowed_bots, true ) ) {
                        $bot_rules .= "\nUser-agent: " . esc_html( $bot_info['user_agent'] ) . "\n";
                        $bot_rules .= "Disallow: /llms.txt\n";
                    }
                }
            }
        }

        // If custom content exists, append bot rules to it
        if ( ! empty( trim( $custom_robots ) ) ) {
            return $custom_robots . $bot_rules . "\n";
        }

        // Otherwise, add bot rules to default WordPress output
        if ( ! empty( $bot_rules ) ) {
            return $output . $bot_rules . "\n";
        }

        // Return default WordPress output
        return $output;
    }

    /**
     * Add rewrite rule for LLMs.txt.
     *
     * @since    2.1.1
     */
    public function add_llms_txt_rewrite() {
        add_rewrite_rule( '^llms\.txt$', 'index.php?srk_llms_txt=1', 'top' );
        
        // Flush rewrite rules if needed (only once)
        if ( ! get_option( 'srk_llms_rewrite_flushed' ) ) {
            flush_rewrite_rules( false );
            update_option( 'srk_llms_rewrite_flushed', true );
        }
    }

    /**
     * Serve LLMs.txt file when requested.
     *
     * @since    2.1.1
     */
    public function serve_llms_txt() {
        // Check if this is an LLMs.txt request
        $is_llms_request = false;
        
        if ( isset( $_GET['srk_llms_txt'] ) && '1' === $_GET['srk_llms_txt'] ) {
            $is_llms_request = true;
        } else {
            // Alternative method: Check URL directly
            $request_uri = isset( $_SERVER['REQUEST_URI'] ) ? esc_url_raw( wp_unslash( $_SERVER['REQUEST_URI'] ) ) : '';
            if ( '/llms.txt' === $request_uri || strpos( $request_uri, '/llms.txt' ) !== false ) {
                $is_llms_request = true;
            }
        }
        
        if ( ! $is_llms_request ) {
            return;
        }
        
        // ✅ BOT ACCESS CONTROL: Check if requesting bot is blocked
        $user_agent = isset( $_SERVER['HTTP_USER_AGENT'] ) ? sanitize_text_field( $_SERVER['HTTP_USER_AGENT'] ) : '';
        if ( $this->is_bot_blocked( $user_agent ) ) {
            // Blocked bot - return 403 Forbidden
            status_header( 403 );
            header( 'Content-Type: text/plain; charset=utf-8' );
            echo "Access denied. This bot is not allowed to access this file.\n";
            exit;
        }
        
        $llms_content = get_option( 'srk_llms_txt_content', '' );

        // If no content, return 404
        if ( empty( trim( $llms_content ) ) ) {
            status_header( 404 );
            exit;
        }

        // Set proper headers
        header( 'Content-Type: text/plain; charset=utf-8' );
        header( 'X-Robots-Tag: noindex' );

        // ✅ SECURITY FIX: Sanitize output for plain text (defense in depth)
        // Strip any HTML/script tags while preserving plain text formatting
        // Content is already sanitized on save, but we sanitize again for security
        $llms_content = wp_strip_all_tags( $llms_content );
        // Ensure valid UTF-8 encoding for plain text output
        $llms_content = wp_check_invalid_utf8( $llms_content, true );
        
        // Output content
        echo $llms_content;
        exit;
    }
    
    /**
     * Check if a bot is blocked based on User-Agent and saved settings.
     *
     * @since    2.1.1
     * @param    string $user_agent The User-Agent string from the request
     * @return   bool True if bot is blocked, false if allowed
     */
    private function is_bot_blocked( $user_agent ) {
        if ( empty( $user_agent ) ) {
            return false; // Allow if no User-Agent (browsers, etc.)
        }
        
        // Get bot access control settings
        $llms_settings = get_option( 'srk_llms_generator_settings', array() );
        $allowed_bots = isset( $llms_settings['allowed_bots'] ) ? $llms_settings['allowed_bots'] : array();
        
        // If no settings or all bots allowed (default), don't block
        if ( empty( $allowed_bots ) ) {
            return false;
        }
        
        // Get popular bots mapping
        $popular_bots = $this->get_popular_ai_bots();
        $all_bots_keys = array_keys( $popular_bots );
        
        // If all bots are allowed, don't block
        if ( count( $allowed_bots ) === count( $all_bots_keys ) && empty( array_diff( $all_bots_keys, $allowed_bots ) ) ) {
            return false;
        }
        
        // Check if this User-Agent matches any blocked bot
        foreach ( $popular_bots as $bot_key => $bot_info ) {
            $bot_user_agent = $bot_info['user_agent'];
            
            // Check if User-Agent contains the bot identifier
            if ( stripos( $user_agent, $bot_user_agent ) !== false ) {
                // This is one of our tracked bots
                // Block if NOT in allowed_bots list
                if ( ! in_array( $bot_key, $allowed_bots, true ) ) {
                    return true; // Bot is blocked
                }
            }
        }
        
        // If User-Agent doesn't match any known bot, allow access (default behavior)
        return false;
    }
    
    /**
     * Get list of popular AI bots with their User-Agent strings.
     * This method is duplicated from admin class for public access.
     *
     * @since    2.1.1
     * @return   array Array of bot information with keys: name, user_agent, description
     */
    private function get_popular_ai_bots() {
        return array(
            'gptbot' => array(
                'name'        => __( 'ChatGPT (GPTBot)', 'seo-repair-kit' ),
                'user_agent'  => 'GPTBot',
                'description' => __( 'OpenAI\'s ChatGPT crawler', 'seo-repair-kit' ),
            ),
            'chatgpt_user' => array(
                'name'        => __( 'ChatGPT User', 'seo-repair-kit' ),
                'user_agent'  => 'ChatGPT-User',
                'description' => __( 'ChatGPT browsing feature', 'seo-repair-kit' ),
            ),
            'claude' => array(
                'name'        => __( 'Claude (Anthropic)', 'seo-repair-kit' ),
                'user_agent'  => 'Claude-Web',
                'description' => __( 'Anthropic\'s Claude AI', 'seo-repair-kit' ),
            ),
            'google_bard' => array(
                'name'        => __( 'Google Bard/Gemini', 'seo-repair-kit' ),
                'user_agent'  => 'Google-Extended',
                'description' => __( 'Google\'s Bard and Gemini AI', 'seo-repair-kit' ),
            ),
            'perplexity' => array(
                'name'        => __( 'Perplexity AI', 'seo-repair-kit' ),
                'user_agent'  => 'PerplexityBot',
                'description' => __( 'Perplexity AI search engine', 'seo-repair-kit' ),
            ),
            'bing_chat' => array(
                'name'        => __( 'Bing Chat/Copilot', 'seo-repair-kit' ),
                'user_agent'  => 'Bingbot',
                'description' => __( 'Microsoft Bing Chat and Copilot', 'seo-repair-kit' ),
            ),
            'you_com' => array(
                'name'        => __( 'You.com', 'seo-repair-kit' ),
                'user_agent'  => 'YouBot',
                'description' => __( 'You.com AI search', 'seo-repair-kit' ),
            ),
            'character_ai' => array(
                'name'        => __( 'Character.AI', 'seo-repair-kit' ),
                'user_agent'  => 'Character-AI',
                'description' => __( 'Character.AI crawler', 'seo-repair-kit' ),
            ),
            'ccbot' => array(
                'name'        => __( 'CCBot (Common Crawl)', 'seo-repair-kit' ),
                'user_agent'  => 'CCBot',
                'description' => __( 'Common Crawl bot (used by many AI models)', 'seo-repair-kit' ),
            ),
            'anthropic_ai' => array(
                'name'        => __( 'Anthropic AI', 'seo-repair-kit' ),
                'user_agent'  => 'anthropic-ai',
                'description' => __( 'Anthropic AI crawler', 'seo-repair-kit' ),
            ),
            'deepseek' => array(
                'name'        => __( 'DeepSeek', 'seo-repair-kit' ),
                'user_agent'  => 'DeepSeekBot',
                'description' => __( 'DeepSeek AI crawler', 'seo-repair-kit' ),
            ),
            'grok' => array(
                'name'        => __( 'Grok (xAI)', 'seo-repair-kit' ),
                'user_agent'  => 'GrokBot',
                'description' => __( 'xAI\'s Grok AI', 'seo-repair-kit' ),
            ),
            'qwen_ai' => array(
                'name'        => __( 'Qwen AI (Alibaba)', 'seo-repair-kit' ),
                'user_agent'  => 'QwenBot',
                'description' => __( 'Alibaba\'s Qwen AI', 'seo-repair-kit' ),
            ),
            'meta_llama' => array(
                'name'        => __( 'Meta Llama', 'seo-repair-kit' ),
                'user_agent'  => 'Meta-Llama',
                'description' => __( 'Meta\'s Llama AI model', 'seo-repair-kit' ),
            ),
            'cohere' => array(
                'name'        => __( 'Cohere', 'seo-repair-kit' ),
                'user_agent'  => 'CohereBot',
                'description' => __( 'Cohere AI crawler', 'seo-repair-kit' ),
            ),
            'mistral_ai' => array(
                'name'        => __( 'Mistral AI', 'seo-repair-kit' ),
                'user_agent'  => 'MistralBot',
                'description' => __( 'Mistral AI crawler', 'seo-repair-kit' ),
            ),
            'huggingface' => array(
                'name'        => __( 'Hugging Face', 'seo-repair-kit' ),
                'user_agent'  => 'HuggingFaceBot',
                'description' => __( 'Hugging Face AI models', 'seo-repair-kit' ),
            ),
        );
    }
}

