<?php
/**
 * SEO Meta Helper - Shared functions for meta field management
 * 
 * Provides helper functions for getting global defaults and managing meta fields
 * 
 * @package SEO_Repair_Kit
 * @since 2.1.3 - Added caching system, removed console logs, optimized database queries
 * @version 2.1.3
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class SRK_Meta_Helper {

    /**
     * Get global default robots meta settings
     * 
     * @return array Default robots meta array
     */
    public static function get_global_robots_defaults() {
        $srk_meta = get_option( 'srk_meta', array() );
        $advanced_settings = isset( $srk_meta['advanced'] ) && is_array( $srk_meta['advanced'] ) 
            ? $srk_meta['advanced'] 
            : array();

        // Check if using default settings
        $use_default_settings = isset( $advanced_settings['use_default_settings'] ) 
            ? $advanced_settings['use_default_settings'] 
            : '0';

        // Get default robots meta
        $default_robots = array(
            'index'               => '1',
            'follow'              => '1',
            'noindex'             => '0',
            'nofollow'            => '0',
            'noarchive'           => '0',
            'notranslate'         => '0',
            'noimageindex'        => '0',
            'nosnippet'           => '0',
            'noodp'               => '0',
            'nofollow_paginated'  => '0',
            'noindex_rss_feeds'   => '1',
            'noindex_paginated'   => '0',
            'max_snippet'         => '-1',
            'max_video_preview'   => '-1',
            'max_image_preview'   => 'large',
        );

        // If using defaults, return default values
        if ( $use_default_settings === '1' ) {
            return $default_robots;
        }

        // Get custom robots meta from advanced settings
        $robots_meta = isset( $advanced_settings['robots_meta'] ) && is_array( $advanced_settings['robots_meta'] )
            ? $advanced_settings['robots_meta']
            : array();

        // Merge with defaults for missing values
        return wp_parse_args( $robots_meta, $default_robots );
    }

    /**
     * Get post meta with global defaults fallback
     * 
     * @param int $post_id Post ID
     * @param string $meta_key Meta key
     * @param mixed $default Default value if not set
     * @return mixed Meta value or default
     */
    public static function get_post_meta_with_default( $post_id, $meta_key, $default = '' ) {
        $value = get_post_meta( $post_id, $meta_key, true );
        return ! empty( $value ) ? $value : $default;
    }

    /**
     * Get robots meta for a post with global defaults
     * 
     * @param int $post_id Post ID
     * @return array Robots meta array
     */
    public static function get_post_robots_meta( $post_id ) {
        $post_robots = get_post_meta( $post_id, '_srk_robots_meta', true );
        
        // If post has custom robots meta, use it
        if ( is_array( $post_robots ) && array_sum( $post_robots ) > 0 ) {
            return $post_robots;
        }

        // Otherwise, use global defaults
        return self::get_global_robots_defaults();
    }

    /**
     * Register all post meta fields with REST API support
     */
    public static function register_post_meta() {
        $post_types = get_post_types( array( 'public' => true ), 'names' );

        foreach ( $post_types as $post_type ) {
            // Register meta title
            register_post_meta(
                $post_type,
                '_srk_meta_title',
                array(
                    'type'         => 'string',
                    'description'  => __( 'SEO Meta Title', 'seo-repair-kit' ),
                    'single'       => true,
                    'show_in_rest' => true,
                    'sanitize_callback' => 'sanitize_text_field',
                    'auth_callback' => function( $allowed, $meta_key, $object_id, $user_id, $cap, $caps ) {
                        return current_user_can( 'edit_post', $object_id );
                    },
                )
            );

            // Register meta description
            register_post_meta(
                $post_type,
                '_srk_meta_description',
                array(
                    'type'         => 'string',
                    'description'  => __( 'SEO Meta Description', 'seo-repair-kit' ),
                    'single'       => true,
                    'show_in_rest' => true,
                    'sanitize_callback' => 'sanitize_textarea_field',
                    'auth_callback' => function( $allowed, $meta_key, $object_id, $user_id, $cap, $caps ) {
                        return current_user_can( 'edit_post', $object_id );
                    },
                )
            );

            // Register robots meta
            register_post_meta(
                $post_type,
                '_srk_robots_meta',
                array(
                    'type'         => 'object',
                    'description'  => __( 'SEO Robots Meta', 'seo-repair-kit' ),
                    'single'       => true,
                    'show_in_rest' => array(
                        'schema' => array(
                            'type'       => 'object',
                            'properties' => array(
                                'index'               => array( 'type' => 'string' ),
                                'noindex'             => array( 'type' => 'string' ),
                                'follow'              => array( 'type' => 'string' ),
                                'nofollow'            => array( 'type' => 'string' ),
                                'noarchive'           => array( 'type' => 'string' ),
                                'notranslate'         => array( 'type' => 'string' ),
                                'noimageindex'        => array( 'type' => 'string' ),
                                'nosnippet'           => array( 'type' => 'string' ),
                                'noodp'               => array( 'type' => 'string' ),
                                'nofollow_paginated'  => array( 'type' => 'string' ),
                                'noindex_rss_feeds'   => array( 'type' => 'string' ),
                                'noindex_paginated'   => array( 'type' => 'string' ),
                                'max_snippet'         => array( 'type' => 'string' ),
                                'max_video_preview'   => array( 'type' => 'string' ),
                                'max_image_preview'   => array( 'type' => 'string' ),
                            ),
                        ),
                    ),
                    'sanitize_callback' => function( $value ) {
                        if ( ! is_array( $value ) ) {
                            return array();
                        }
                        $sanitized = array();
                        $allowed_keys = array(
                            'index', 'noindex', 'follow', 'nofollow', 'noarchive',
                            'notranslate', 'noimageindex', 'nosnippet', 'noodp',
                            'nofollow_paginated', 'noindex_rss_feeds', 'noindex_paginated',
                            'max_snippet', 'max_video_preview', 'max_image_preview'
                        );
                        foreach ( $allowed_keys as $key ) {
                            if ( isset( $value[ $key ] ) ) {
                                if ( in_array( $key, array( 'max_snippet', 'max_video_preview' ) ) ) {
                                    $sanitized[ $key ] = intval( $value[ $key ] );
                                } elseif ( $key === 'max_image_preview' ) {
                                    $allowed = array( 'none', 'standard', 'large' );
                                    $sanitized[ $key ] = in_array( $value[ $key ], $allowed ) ? $value[ $key ] : 'large';
                                } else {
                                    $sanitized[ $key ] = ! empty( $value[ $key ] ) ? '1' : '0';
                                }
                            }
                        }
                        return $sanitized;
                    },
                    'auth_callback' => function( $allowed, $meta_key, $object_id, $user_id, $cap, $caps ) {
                        return current_user_can( 'edit_post', $object_id );
                    },
                )
            );

            // Register canonical URL
            register_post_meta(
                $post_type,
                '_srk_canonical_url',
                array(
                    'type'         => 'string',
                    'description'  => __( 'SEO Canonical URL', 'seo-repair-kit' ),
                    'single'       => true,
                    'show_in_rest' => true,
                    'sanitize_callback' => 'esc_url_raw',
                    'auth_callback' => function( $allowed, $meta_key, $object_id, $user_id, $cap, $caps ) {
                        return current_user_can( 'edit_post', $object_id );
                    },
                )
            );
        }
    }
}

// Register post meta on init
add_action( 'init', array( 'SRK_Meta_Helper', 'register_post_meta' ) );