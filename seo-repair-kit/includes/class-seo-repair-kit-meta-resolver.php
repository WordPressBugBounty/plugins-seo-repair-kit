<?php
/**
 * Meta Resolver - Handles priority fallback logic for SEO meta fields
 *
 * @package SEO_Repair_Kit
 * @since 2.1.3
 * @version 2.1.3
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class SRK_Meta_Resolver {

	private static $option_cache = array();

	private static function get_option_cached( $option, $default = false ) {
		if ( ! isset( self::$option_cache[ $option ] ) ) {
			self::$option_cache[ $option ] = get_option( $option, $default );
		}
		return self::$option_cache[ $option ];
	}

	public static function get_meta_title( $post_id = null ) {
		if ( ! $post_id ) {
			$post_id = get_the_ID();
		}

		if ( ! $post_id ) {
			return '';
		}

		$post = get_post( $post_id );
		if ( ! $post ) {
			return '';
		}

		$advanced_settings = get_post_meta( $post_id, '_srk_advanced_settings', true );

		$is_follow_mode = false;
		if ( is_array( $advanced_settings ) ) {
			if ( isset( $advanced_settings['follow_mode'] ) && '1' === $advanced_settings['follow_mode'] ) {
				$is_follow_mode = true;
			} elseif ( isset( $advanced_settings['use_default_settings'] ) &&
					'1' === $advanced_settings['use_default_settings'] ) {
				$local_title = get_post_meta( $post_id, '_srk_meta_title', true );
				if ( empty( $local_title ) ) {
					$is_follow_mode = true;
				}
			}
		}

		if ( ! $is_follow_mode ) {
			$post_meta_title = get_post_meta( $post_id, '_srk_meta_title', true );
			if ( ! empty( $post_meta_title ) ) {
				return self::parse_template( $post_meta_title, $post_id );
			}
		}

		$content_type_settings = get_option( 'srk_meta_content_types_settings', array() );
		$post_type             = $post->post_type;

		if ( ! empty( $content_type_settings[ $post_type ]['title'] ) ) {
			return self::parse_template( $content_type_settings[ $post_type ]['title'], $post_id );
		}

		$srk_meta        = get_option( 'srk_meta', array() );
		$global_settings = isset( $srk_meta['global'] ) && is_array( $srk_meta['global'] )
			? $srk_meta['global']
			: array();

		$global_template = isset( $global_settings['title_template'] )
			? $global_settings['title_template']
			: get_option( 'srk_title_template', '%title% %sep% %site_title%' );

		if ( ! empty( $global_template ) ) {
			return self::parse_template( $global_template, $post_id );
		}

		return '';
	}

	function srk_parse_archive_template( $template ) {
		if ( empty( $template ) ) {
			return '';
		}

		$srk_meta = get_option( 'srk_meta', array() );
		$global   = isset( $srk_meta['global'] ) ? $srk_meta['global'] : array();
		$sep      = isset( $global['title_separator'] ) ? $global['title_separator'] : '-';

		$replacements = array(
			'%sep%'        => $sep,
			'%site_title%' => get_bloginfo( 'name' ),
			'%sitedesc%'   => get_bloginfo( 'description' ),
		);

		if ( is_author() ) {
			$author                                    = get_queried_object();
			$replacements['%author%']                    = $author->display_name ?? '';
			$replacements['%author_bio%']                = get_the_author_meta( 'description', $author->ID );
			$replacements['%archive_title%']             = 'Author: ' . ( $author->display_name ?? '' );
			$replacements['%archive_description%']       = get_the_author_meta( 'description', $author->ID );
		} elseif ( is_date() ) {
			$date                                  = get_the_date( 'F Y' );
			$replacements['%date%']                  = $date;
			$replacements['%archive_title%']         = $date;
			$replacements['%archive_description%']     = 'Posts published in ' . $date;
		} elseif ( is_search() ) {
			$search                              = get_search_query();
			$replacements['%search%']              = $search;
			$replacements['%archive_title%']       = 'Search Results for: ' . $search;
			$replacements['%archive_description%'] = 'Search results for ' . $search;
		}

		return str_replace( array_keys( $replacements ), array_values( $replacements ), $template );
	}

	public static function get_meta_description( $post_id = null ) {
		if ( ! $post_id ) {
			$post_id = get_the_ID();
		}

		if ( ! $post_id ) {
			return '';
		}

		$post = get_post( $post_id );
		if ( ! $post ) {
			return '';
		}

		$advanced_settings = get_post_meta( $post_id, '_srk_advanced_settings', true );

		$is_follow_mode = false;
		if ( is_array( $advanced_settings ) ) {
			if ( isset( $advanced_settings['follow_mode'] ) && '1' === $advanced_settings['follow_mode'] ) {
				$is_follow_mode = true;
			} elseif ( isset( $advanced_settings['use_default_settings'] ) &&
					'1' === $advanced_settings['use_default_settings'] ) {
				$local_desc = get_post_meta( $post_id, '_srk_meta_description', true );
				if ( empty( $local_desc ) ) {
					$is_follow_mode = true;
				}
			}
		}

		if ( ! $is_follow_mode ) {
			$post_meta_desc = get_post_meta( $post_id, '_srk_meta_description', true );
			if ( ! empty( $post_meta_desc ) ) {
				return self::parse_template( $post_meta_desc, $post_id );
			}
		}

		$content_type_settings = get_option( 'srk_meta_content_types_settings', array() );
		$post_type             = $post->post_type;

		if ( ! empty( $content_type_settings[ $post_type ]['desc'] ) ) {
			return self::parse_template( $content_type_settings[ $post_type ]['desc'], $post_id );
		}

		$srk_meta        = get_option( 'srk_meta', array() );
		$global_settings = isset( $srk_meta['global'] ) && is_array( $srk_meta['global'] )
			? $srk_meta['global']
			: array();

		$global_template = isset( $global_settings['desc_template'] )
			? $global_settings['desc_template']
			: get_option( 'srk_desc_template', '%tagline%' );

		if ( ! empty( $global_template ) ) {
			return self::parse_template( $global_template, $post_id );
		}

		return '';
	}

	public static function get_robots_meta( $post_id = null ) {
		return self::resolve_robots_for_context( 'singular', $post_id );
	}

	public static function resolve_robots_for_context( $context, $post_id = null, $term = null ) {
		if ( 'singular' === $context ) {
			return self::resolve_singular( $post_id );
		}
		if ( 'taxonomy' === $context && $term ) {
			return self::resolve_taxonomy( $term );
		}
		if ( 'archive' === $context ) {
			return self::resolve_archive();
		}
		return self::get_system_baseline_robots();
	}

	public static function resolve_singular( $post_id = null ) {
		if ( ! $post_id ) {
			$post_id = get_the_ID();
		}
		if ( ! $post_id ) {
			return self::get_system_baseline_robots();
		}

		$post = get_post( $post_id );
		if ( ! $post ) {
			return self::get_system_baseline_robots();
		}

		$base = self::get_system_baseline_robots();

		$advanced = get_post_meta( $post_id, '_srk_advanced_settings', true );
		if ( is_array( $advanced ) && ! empty( $advanced ) ) {
			$use_default = isset( $advanced['use_default_settings'] ) &&
				( '1' === $advanced['use_default_settings'] ||
				1 === $advanced['use_default_settings'] ||
				true === $advanced['use_default_settings'] );

			if ( ! $use_default && isset( $advanced['robots_meta'] ) && is_array( $advanced['robots_meta'] ) ) {
				if ( self::has_custom_robots( $advanced['robots_meta'] ) ) {
					return self::normalize_robots( self::robots_from_settings( $advanced['robots_meta'] ), $base );
				}
			}
		}

		$content_type_settings = self::get_option_cached( 'srk_meta_content_types_settings', array() );
		$post_type             = $post->post_type;

		if ( isset( $content_type_settings[ $post_type ]['advanced'] ) && is_array( $content_type_settings[ $post_type ]['advanced'] ) ) {
			$ct_advanced    = $content_type_settings[ $post_type ]['advanced'];
			$ct_use_default = isset( $ct_advanced['use_default_settings'] ) &&
				( '1' === $ct_advanced['use_default_settings'] ||
				1 === $ct_advanced['use_default_settings'] ||
				true === $ct_advanced['use_default_settings'] );

			if ( ! $ct_use_default && isset( $ct_advanced['robots_meta'] ) && is_array( $ct_advanced['robots_meta'] ) ) {
				return self::normalize_robots( self::robots_from_settings( $ct_advanced['robots_meta'] ), $base );
			}
		}

		return self::resolve_global();
	}

	private static function has_custom_robots( $robots ) {
		if ( ! is_array( $robots ) ) {
			return false;
		}
		foreach ( array( 'noindex', 'nofollow', 'noarchive', 'nosnippet', 'noimageindex', 'noodp', 'notranslate' ) as $key ) {
			if ( isset( $robots[ $key ] ) && ( '1' === $robots[ $key ] || 1 === $robots[ $key ] || true === $robots[ $key ] ) ) {
				return true;
			}
		}
		if ( isset( $robots['max_snippet'] ) && -1 !== (int) $robots['max_snippet'] ) {
			return true;
		}
		if ( isset( $robots['max_video_preview'] ) && -1 !== (int) $robots['max_video_preview'] ) {
			return true;
		}
		if ( isset( $robots['max_image_preview'] ) && '' !== $robots['max_image_preview'] && 'large' !== $robots['max_image_preview'] ) {
			return true;
		}
		return false;
	}

	public static function resolve_taxonomy( $term ) {
		$all_settings = get_option( 'srk_meta_taxonomies_settings', array() );
		if ( ! $term || ! is_object( $term ) ) {
			return self::resolve_global();
		}

		$base     = self::get_system_baseline_robots();
		$term_id  = $term->term_id;
		$taxonomy = $term->taxonomy;

		$term_settings = get_term_meta( $term_id, '_srk_term_settings', true );
		if ( is_array( $term_settings ) && ! empty( $term_settings ) && isset( $term_settings['advanced'] ) && is_array( $term_settings['advanced'] ) ) {
			$term_adv    = $term_settings['advanced'];
			$use_default = isset( $term_adv['use_default_settings'] ) && ( '1' === $term_adv['use_default_settings'] || true === $term_adv['use_default_settings'] );
			if ( ! $use_default && isset( $term_adv['robots_meta'] ) && is_array( $term_adv['robots_meta'] ) && self::has_custom_robots( $term_adv['robots_meta'] ) ) {
				return self::normalize_robots( self::robots_from_settings( $term_adv['robots_meta'] ), $base );
			}
		}

		$all_settings = self::get_option_cached( 'srk_meta_taxonomies_settings', array() );

		if ( ! isset( $all_settings[ $taxonomy ] ) || ! is_array( $all_settings[ $taxonomy ] ) ) {
			return self::resolve_global();
		}

		$s = $all_settings[ $taxonomy ];

		$use_default = isset( $s['use_default_advanced'] ) && ( '1' === $s['use_default_advanced'] || true === $s['use_default_advanced'] );

		if ( ! $use_default && isset( $s['robots'] ) && is_array( $s['robots'] ) ) {
			return self::normalize_robots( self::robots_from_settings( $s['robots'] ), $base );
		}

		return self::resolve_global();
	}

	public static function resolve_archive() {
		$base = self::get_system_baseline_robots();

		$srk_meta = self::get_option_cached( 'srk_meta', array() );
		$archives = isset( $srk_meta['archives'] ) && is_array( $srk_meta['archives'] )
			? $srk_meta['archives']
			: array();

		$archive_type = '';
		$settings     = array();

		if ( is_author() ) {
			$archive_type = 'author';
		} elseif ( is_date() ) {
			$archive_type = 'date';
		} elseif ( is_search() ) {
			$archive_type = 'search';
		} elseif ( is_post_type_archive() ) {
			$post_type = get_query_var( 'post_type' );
			if ( is_array( $post_type ) ) {
				$post_type = reset( $post_type );
			}
			$pt_settings = isset( $archives['post_types'][ $post_type ] )
				? $archives['post_types'][ $post_type ]
				: array();

			if ( ! empty( $pt_settings ) ) {
				$use_default = ! empty( $pt_settings['use_default'] ) && '1' === $pt_settings['use_default'];
				if ( ! $use_default && ( isset( $pt_settings['noindex'] ) || isset( $pt_settings['nofollow'] ) ) ) {
					$robots = self::archive_settings_to_robots( $pt_settings );
					return self::normalize_robots( $robots, $base );
				}
			}
			return self::resolve_global();
		}

		if ( empty( $archive_type ) ) {
			return self::resolve_global();
		}

		$settings = isset( $archives[ $archive_type ] ) && is_array( $archives[ $archive_type ] )
			? $archives[ $archive_type ]
			: array();

		$use_default = ! empty( $settings['use_default'] ) && '1' === $settings['use_default'];

		if ( ! $use_default && ! empty( $settings ) ) {
			$robots = self::archive_settings_to_robots( $settings );
			return self::normalize_robots( $robots, $base );
		}

		return self::resolve_global();
	}

	public static function resolve_global() {
		$base     = self::get_system_baseline_robots();
		$srk_meta = self::get_option_cached( 'srk_meta', array() );
		$advanced = isset( $srk_meta['advanced'] ) && is_array( $srk_meta['advanced'] ) ? $srk_meta['advanced'] : array();

		$use_default = isset( $advanced['use_default_settings'] ) && ( '1' === $advanced['use_default_settings'] || true === $advanced['use_default_settings'] );
		if ( $use_default ) {
			return $base;
		}

		if ( isset( $advanced['robots_meta'] ) && is_array( $advanced['robots_meta'] ) ) {
			return self::normalize_robots( self::robots_from_settings( $advanced['robots_meta'] ), $base );
		}

		$global_settings = isset( $srk_meta['global'] ) && is_array( $srk_meta['global'] ) ? $srk_meta['global'] : array();
		$legacy          = array();
		if ( isset( $global_settings['meta_default_index'] ) ) {
			$legacy['index']   = ! empty( $global_settings['meta_default_index'] ) ? 1 : 0;
			$legacy['noindex'] = empty( $legacy['index'] ) ? 1 : 0;
		}
		if ( isset( $global_settings['meta_default_follow'] ) ) {
			$legacy['follow']   = ! empty( $global_settings['meta_default_follow'] ) ? 1 : 0;
			$legacy['nofollow'] = empty( $legacy['follow'] ) ? 1 : 0;
		}
		if ( ! empty( $legacy ) ) {
			return self::normalize_robots( wp_parse_args( $legacy, $base ), $base );
		}

		return $base;
	}

	public static function build_robots_string( $robots ) {
		if ( ! is_array( $robots ) ) {
			$robots = self::get_system_baseline_robots();
		}

		$parts = array();

		$noindex = isset( $robots['noindex'] ) && ( '1' == $robots['noindex'] || 1 === $robots['noindex'] || true === $robots['noindex'] );
		$parts[] = $noindex ? 'noindex' : 'index';

		$nofollow = isset( $robots['nofollow'] ) && ( '1' == $robots['nofollow'] || 1 === $robots['nofollow'] || true === $robots['nofollow'] );
		$parts[]  = $nofollow ? 'nofollow' : 'follow';

		if ( isset( $robots['noarchive'] ) && ( '1' == $robots['noarchive'] || 1 === $robots['noarchive'] || true === $robots['noarchive'] ) ) {
			$parts[] = 'noarchive';
		}
		if ( isset( $robots['notranslate'] ) && ( '1' == $robots['notranslate'] || 1 === $robots['notranslate'] || true === $robots['notranslate'] ) ) {
			$parts[] = 'notranslate';
		}
		if ( isset( $robots['noimageindex'] ) && ( '1' == $robots['noimageindex'] || 1 === $robots['noimageindex'] || true === $robots['noimageindex'] ) ) {
			$parts[] = 'noimageindex';
		} elseif ( isset( $robots['max_image_preview'] ) && '' !== $robots['max_image_preview'] ) {
			$parts[] = 'max-image-preview:' . sanitize_text_field( $robots['max_image_preview'] );
		} else {
			$parts[] = 'max-image-preview:large';
		}
		if ( isset( $robots['nosnippet'] ) && ( '1' == $robots['nosnippet'] || 1 === $robots['nosnippet'] || true === $robots['nosnippet'] ) ) {
			$parts[] = 'nosnippet';
		} elseif ( isset( $robots['max_snippet'] ) && -1 !== (int) $robots['max_snippet'] ) {
			$parts[] = 'max-snippet:' . (int) $robots['max_snippet'];
		}
		// phpcs:ignore WordPress.NamingConventions.ValidVariableName.VariableNotSnakeCase
		if ( isset( $robots['noodp'] ) && ( '1' == $robots['noodp'] || 1 === $robots['noodp'] || true === $robots['noodp'] ) ) {
			$parts[] = 'noodp';
		}

		if ( isset( $robots['max_video_preview'] ) && -1 !== (int) $robots['max_video_preview'] ) {
			$parts[] = 'max-video-preview:' . (int) $robots['max_video_preview'];
		}

		return implode( ', ', $parts );
	}

	private static function robots_from_settings( $ct ) {
		$merged = array();

		if ( isset( $ct['noindex'] ) && ( '1' === $ct['noindex'] || 1 === $ct['noindex'] || true === $ct['noindex'] ) ) {
			$merged['noindex'] = 1;
			$merged['index']   = 0;
		} else {
			$merged['noindex'] = 0;
			$merged['index']   = 1;
		}

		if ( isset( $ct['nofollow'] ) && ( '1' === $ct['nofollow'] || 1 === $ct['nofollow'] || true === $ct['nofollow'] ) ) {
			$merged['nofollow'] = 1;
			$merged['follow']   = 0;
		} else {
			$merged['nofollow'] = 0;
			$merged['follow']   = 1;
		}

		foreach ( array( 'noarchive', 'notranslate', 'noimageindex', 'nosnippet', 'noodp' ) as $k ) {
			if ( isset( $ct[ $k ] ) ) {
				$merged[ $k ] = ( '1' === $ct[ $k ] || 1 === $ct[ $k ] || true === $ct[ $k ] ) ? 1 : 0;
			}
		}

		foreach ( array( 'max_snippet', 'max_video_preview' ) as $k ) {
			if ( isset( $ct[ $k ] ) ) {
				$merged[ $k ] = intval( $ct[ $k ] );
			}
		}

		if ( isset( $ct['max_image_preview'] ) && ! empty( $ct['max_image_preview'] ) ) {
			$merged['max_image_preview'] = sanitize_text_field( $ct['max_image_preview'] );
		} else {
			$merged['max_image_preview'] = 'large';
		}

		return $merged;
	}

	private static function archive_settings_to_robots( $s ) {
		$r = array();

		$noindex_val   = isset( $s['noindex'] ) ? $s['noindex'] : '0';
		$r['noindex']  = ( '1' === $noindex_val || 1 === $noindex_val || true === $noindex_val ) ? 1 : 0;
		$r['index']    = $r['noindex'] ? 0 : 1;

		$nofollow_val  = isset( $s['nofollow'] ) ? $s['nofollow'] : '0';
		$r['nofollow'] = ( '1' === $nofollow_val || 1 === $nofollow_val || true === $nofollow_val ) ? 1 : 0;
		$r['follow']   = $r['nofollow'] ? 0 : 1;

		$r['noarchive']      = ! empty( $s['noarchive'] ) ? 1 : 0;
		$r['nosnippet']      = ! empty( $s['nosnippet'] ) ? 1 : 0;
		$r['noimageindex']   = ! empty( $s['noimageindex'] ) ? 1 : 0;

		$r['max_snippet']       = isset( $s['max_snippet'] ) ? intval( $s['max_snippet'] ) : -1;
		$r['max_video_preview'] = isset( $s['max_video_preview'] ) ? intval( $s['max_video_preview'] ) : -1;
		$r['max_image_preview'] = isset( $s['max_image_preview'] ) && '' !== $s['max_image_preview']
			? sanitize_text_field( $s['max_image_preview'] )
			: 'large';

		return $r;
	}

	public static function get_canonical_url( $post_id = null ) {
		if ( ! $post_id ) {
			$post_id = get_the_ID();
		}

		if ( ! $post_id ) {
			return '';
		}

		$post = get_post( $post_id );
		if ( ! $post ) {
			return '';
		}

		$post_canonical = get_post_meta( $post_id, '_srk_canonical_url', true );
		if ( ! empty( $post_canonical ) ) {
			return esc_url( $post_canonical );
		}

		return get_permalink( $post_id );
	}

	private static function normalize_robots( $robots, $base ) {
		$out = wp_parse_args( $robots, $base );

		if ( isset( $robots['noindex'] ) ) {
			$out['noindex'] = ( '1' == $robots['noindex'] || 1 === $robots['noindex'] ) ? 1 : 0;
			$out['index']   = ( 1 == $out['noindex'] ) ? 0 : 1;
		} elseif ( isset( $robots['index'] ) ) {
			$out['index']   = ( '1' == $robots['index'] || 1 === $robots['index'] ) ? 1 : 0;
			$out['noindex'] = ( 1 == $out['index'] ) ? 0 : 1;
		}

		if ( isset( $robots['nofollow'] ) ) {
			$out['nofollow'] = ( '1' == $robots['nofollow'] || 1 === $robots['nofollow'] ) ? 1 : 0;
			$out['follow']   = ( 1 == $out['nofollow'] ) ? 0 : 1;
		} elseif ( isset( $robots['follow'] ) ) {
			$out['follow']   = ( '1' == $robots['follow'] || 1 === $robots['follow'] ) ? 1 : 0;
			$out['nofollow'] = ( 1 == $out['follow'] ) ? 0 : 1;
		}

		foreach ( array( 'noarchive', 'notranslate', 'noimageindex', 'nosnippet', 'noodp', 'max_snippet', 'max_video_preview', 'max_image_preview' ) as $key ) {
			if ( isset( $robots[ $key ] ) ) {
				$out[ $key ] = $robots[ $key ];
			}
		}

		return $out;
	}

	public static function get_system_baseline_robots() {
		return array(
			'index'             => 1,
			'noindex'           => 0,
			'follow'            => 1,
			'nofollow'          => 0,
			'noarchive'         => 0,
			'notranslate'       => 0,
			'noimageindex'      => 0,
			'nosnippet'         => 0,
			// phpcs:ignore WordPress.NamingConventions.ValidVariableName.VariableNotSnakeCase
			'noodp'             => 0,
			'max_snippet'       => -1,
			'max_video_preview' => -1,
			'max_image_preview' => 'large',
		);
	}

	private static function get_default_robots() {
		return self::get_system_baseline_robots();
	}

	public static function parse_template( $template, $post_id = null ) {
		if ( empty( $template ) ) {
			return '';
		}

		if ( ! $post_id ) {
			$post_id = get_the_ID();
		}

		$srk_meta        = get_option( 'srk_meta', array() );
		$global_settings = isset( $srk_meta['global'] ) && is_array( $srk_meta['global'] )
			? $srk_meta['global']
			: array();
		$separator       = isset( $global_settings['title_separator'] )
			? $global_settings['title_separator']
			: get_option( 'srk_title_separator', '-' );

		$replacements = array(
			'%sep%'        => $separator,
			'%site_title%' => get_bloginfo( 'name', 'display' ),
			'%tagline%'    => get_bloginfo( 'description', 'display' ),
			'%sitedesc%'   => get_bloginfo( 'description', 'display' ),
			'%date%'       => date_i18n( get_option( 'date_format' ) ),
			'%month%'      => date_i18n( 'F' ),
			'%year%'       => date_i18n( 'Y' ),
		);

		if ( $post_id ) {
			$post = get_post( $post_id );
			if ( $post ) {
				$post_type = $post->post_type;
				$title     = get_the_title( $post_id );
				$excerpt   = get_the_excerpt( $post_id );
				if ( empty( $excerpt ) ) {
					$excerpt = wp_trim_words( wp_strip_all_tags( get_post_field( 'post_content', $post_id ) ), 30 );
				}

				$replacements['%title%']   = $title;
				$replacements['%excerpt%'] = $excerpt;
				$replacements['%content%'] = wp_trim_words( wp_strip_all_tags( get_post_field( 'post_content', $post_id ) ), 30 );
				$replacements['%permalink%'] = get_permalink( $post_id );
				$replacements['%post_date%'] = get_the_date( 'F j, Y', $post_id );
				$replacements['%post_day%']  = get_the_date( 'd', $post_id );

				$replacements[ '%' . $post_type . '_date%' ]    = get_the_date( 'F j, Y', $post_id );
				$replacements[ '%' . $post_type . '_day%' ]     = get_the_date( 'd', $post_id );
				$replacements[ '%content%' ] = wp_trim_words( wp_strip_all_tags( get_post_field( 'post_content', $post_id ) ), 30 );

				$author_id = $post->post_author;
				if ( $author_id ) {
					$replacements['%author%']            = get_the_author_meta( 'display_name', $author_id );
					$replacements['%author_name%']       = get_the_author_meta( 'display_name', $author_id );
					$replacements['%author_first_name%'] = get_the_author_meta( 'first_name', $author_id );
					$replacements['%author_last_name%']  = get_the_author_meta( 'last_name', $author_id );
				}

				$categories = get_the_category( $post_id );
				if ( ! empty( $categories ) ) {
					$cat_names                     = wp_list_pluck( $categories, 'name' );
					$replacements['%term_title%']      = $categories[0]->name;
					$replacements['%term_title%']    = $categories[0]->name;
					$replacements['%term%']        = implode( ', ', $cat_names );
					$replacements['%categories%']  = implode( ', ', $cat_names );
				} else {
					$replacements['%term_title%']     = '';
					$replacements['%term_title%']   = '';
					$replacements['%term%']         = '';
					$replacements['%categories%']   = '';
				}

				$replacements['%custom_field%'] = '';
			}
		}

		return str_replace(
			array_keys( $replacements ),
			array_values( $replacements ),
			$template
		);
	}
}