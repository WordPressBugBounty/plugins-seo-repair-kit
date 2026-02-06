<?php
if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/**
 * Robots.txt Validator
 * 
 * Validates robots.txt content for syntax errors and common issues.
 * 
 * @since    2.1.1
 * @package  Seo_Repair_Kit
 */
class SRK_Robots_Validator {

    /**
     * Validate robots.txt content.
     *
     * @since    2.1.1
     * @param    string $content The robots.txt content to validate
     * @return   array  Validation result with status, errors, and warnings
     */
    public function validate( $content ) {
        $errors   = array();
        $warnings = array();
        $is_valid = true;

        if ( empty( trim( $content ) ) ) {
            $warnings[] = __( 'Robots.txt file is empty. Search engines may not be able to crawl your site properly.', 'seo-repair-kit' );
            return array(
                'valid'    => false,
                'errors'   => array(),
                'warnings' => $warnings,
            );
        }

        $lines = explode( "\n", $content );
        $line_number = 0;
        $has_user_agent = false;
        $user_agents = array();
        $current_user_agent = null;

        foreach ( $lines as $line ) {
            $line_number++;
            $line = trim( $line );

            // Skip empty lines and comments
            if ( empty( $line ) || strpos( $line, '#' ) === 0 ) {
                continue;
            }

            // Check for User-agent directive
            if ( preg_match( '/^User-agent:\s*(.+)$/i', $line, $matches ) ) {
                $has_user_agent = true;
                $user_agent = trim( $matches[1] );
                $current_user_agent = $user_agent;
                
                if ( ! in_array( $user_agent, $user_agents, true ) ) {
                    $user_agents[] = $user_agent;
                }

                // Validate user-agent value
                if ( empty( $user_agent ) ) {
                    $errors[] = sprintf(
                        __( 'Line %d: User-agent directive is empty.', 'seo-repair-kit' ),
                        $line_number
                    );
                    $is_valid = false;
                }
                continue;
            }

            // Check for Disallow directive
            if ( preg_match( '/^Disallow:\s*(.+)$/i', $line, $matches ) ) {
                if ( ! $has_user_agent && empty( $user_agents ) ) {
                    $errors[] = sprintf(
                        __( 'Line %d: Disallow directive found before User-agent. Each Disallow must follow a User-agent.', 'seo-repair-kit' ),
                        $line_number
                    );
                    $is_valid = false;
                }

                $path = trim( $matches[1] );
                
                // Validate path format
                if ( ! empty( $path ) && ! preg_match( '/^(\/|$|\*|\$)/', $path ) && ! preg_match( '/^\/.*/', $path ) ) {
                    $warnings[] = sprintf(
                        __( 'Line %d: Disallow path "%s" should start with "/" for proper formatting.', 'seo-repair-kit' ),
                        $line_number,
                        esc_html( $path )
                    );
                }
                continue;
            }

            // Check for Allow directive
            if ( preg_match( '/^Allow:\s*(.+)$/i', $line, $matches ) ) {
                if ( ! $has_user_agent && empty( $user_agents ) ) {
                    $errors[] = sprintf(
                        __( 'Line %d: Allow directive found before User-agent. Each Allow must follow a User-agent.', 'seo-repair-kit' ),
                        $line_number
                    );
                    $is_valid = false;
                }
                continue;
            }

            // Check for Sitemap directive
            if ( preg_match( '/^Sitemap:\s*(.+)$/i', $line, $matches ) ) {
                $sitemap_url = trim( $matches[1] );
                
                // Validate URL format
                if ( ! filter_var( $sitemap_url, FILTER_VALIDATE_URL ) ) {
                    $errors[] = sprintf(
                        __( 'Line %d: Invalid Sitemap URL format: "%s".', 'seo-repair-kit' ),
                        $line_number,
                        esc_html( $sitemap_url )
                    );
                    $is_valid = false;
                }
                continue;
            }

            // Check for Crawl-delay directive
            if ( preg_match( '/^Crawl-delay:\s*(.+)$/i', $line, $matches ) ) {
                if ( ! $has_user_agent && empty( $user_agents ) ) {
                    $errors[] = sprintf(
                        __( 'Line %d: Crawl-delay directive found before User-agent.', 'seo-repair-kit' ),
                        $line_number
                    );
                    $is_valid = false;
                }

                $delay = trim( $matches[1] );
                if ( ! is_numeric( $delay ) || $delay < 0 ) {
                    $errors[] = sprintf(
                        __( 'Line %d: Crawl-delay must be a positive number.', 'seo-repair-kit' ),
                        $line_number
                    );
                    $is_valid = false;
                }
                continue;
            }

            // Unknown directive
            if ( ! empty( $line ) ) {
                $warnings[] = sprintf(
                    __( 'Line %d: Unknown directive or invalid format: "%s".', 'seo-repair-kit' ),
                    $line_number,
                    esc_html( $line )
                );
            }
        }

        // Check if no User-agent was found
        if ( ! $has_user_agent && ! empty( trim( $content ) ) ) {
            $warnings[] = __( 'No User-agent directive found. Consider adding "User-agent: *" to apply rules to all crawlers.', 'seo-repair-kit' );
        }

        // Check for common WordPress paths
        $wp_admin_disallowed = false;
        $wp_includes_disallowed = false;
        
        if ( strpos( $content, 'Disallow: /wp-admin/' ) !== false ) {
            $wp_admin_disallowed = true;
        }
        if ( strpos( $content, 'Disallow: /wp-includes/' ) !== false ) {
            $wp_includes_disallowed = true;
        }

        if ( ! $wp_admin_disallowed ) {
            $warnings[] = __( 'Consider adding "Disallow: /wp-admin/" to prevent search engines from indexing your admin area.', 'seo-repair-kit' );
        }

        if ( ! $wp_includes_disallowed ) {
            $warnings[] = __( 'Consider adding "Disallow: /wp-includes/" to prevent search engines from indexing WordPress core files.', 'seo-repair-kit' );
        }

        // Check for sitemap
        if ( strpos( $content, 'Sitemap:' ) === false ) {
            $warnings[] = __( 'Consider adding a Sitemap directive to help search engines discover your content.', 'seo-repair-kit' );
        }

        return array(
            'valid'    => $is_valid && empty( $errors ),
            'errors'   => $errors,
            'warnings' => $warnings,
        );
    }

    /**
     * Check if robots.txt syntax is valid.
     *
     * @since    2.1.1
     * @param    string $content The robots.txt content
     * @return   bool   True if syntax is valid
     */
    public function is_valid_syntax( $content ) {
        $result = $this->validate( $content );
        return $result['valid'];
    }

    /**
     * Get validation errors only.
     *
     * @since    2.1.1
     * @param    string $content The robots.txt content
     * @return   array  Array of error messages
     */
    public function get_errors( $content ) {
        $result = $this->validate( $content );
        return $result['errors'];
    }

    /**
     * Get validation warnings only.
     *
     * @since    2.1.1
     * @param    string $content The robots.txt content
     * @return   array  Array of warning messages
     */
    public function get_warnings( $content ) {
        $result = $this->validate( $content );
        return $result['warnings'];
    }
}

