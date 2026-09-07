<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Smart Redirect Helper
 *
 * @since 2.1.7
 */
class SeoRepairKit_SmartRedirect_Helper {

	/**
	 * Normalize host for internal comparison.
	 *
	 * @param string $host Host.
	 * @return string
	 */
	private static function normalize_host( $host ) {
		$host = strtolower( trim( (string) $host ) );
		return preg_replace( '/^www\./i', '', $host );
	}

	/**
	 * Normalize URL to absolute internal URL without fragment.
	 *
	 * @param string $url URL.
	 * @return string
	 */
	public static function normalize_url( $url ) {
		$url = trim( (string) $url );

		if ( '' === $url ) {
			return '';
		}

		if ( 0 === strpos( $url, '//' ) ) {
			$url = ( is_ssl() ? 'https:' : 'http:' ) . $url;
		} elseif ( 0 !== strpos( $url, 'http://' ) && 0 !== strpos( $url, 'https://' ) ) {
			$url = home_url( '/' . ltrim( $url, '/' ) );
		}

		$parts = wp_parse_url( $url );

		if ( empty( $parts['host'] ) ) {
			return '';
		}

		$site_host = (string) wp_parse_url( home_url(), PHP_URL_HOST );
		if ( '' === $site_host ) {
			return '';
		}
		$input_host = self::normalize_host( $parts['host'] );
		$base_host  = self::normalize_host( $site_host );
		if ( '' === $input_host || $input_host !== $base_host ) {
			return '';
		}

		$home_scheme = (string) wp_parse_url( home_url(), PHP_URL_SCHEME );
		$scheme      = '' !== $home_scheme ? $home_scheme : ( ! empty( $parts['scheme'] ) ? $parts['scheme'] : ( is_ssl() ? 'https' : 'http' ) );
		$path   = ! empty( $parts['path'] ) ? $parts['path'] : '/';
		$query  = ! empty( $parts['query'] ) ? '?' . $parts['query'] : '';

		$path = '/' . ltrim( preg_replace( '#//+#', '/', $path ), '/' );

		// Canonicalize trailing slash so "/slug" and "/slug/" are treated as the same URL.
		// This prevents Smart Redirect duplicates and duplicate Redirection records.
		if ( '/' !== $path ) {
			$path = untrailingslashit( $path );
			if ( '' === $path ) {
				$path = '/';
			}
		}

		return $scheme . '://' . $site_host . $path . $query;
	}

	/**
	 * Check if URL is internal.
	 *
	 * @param string $url URL.
	 * @return bool
	 */
	public static function is_internal_url( $url ) {
		return '' !== self::normalize_url( $url );
	}

	/**
	 * Get archive URL for a post type.
	 *
	 * @param string      $post_type Post type.
	 * @param object|null $pt_obj Optional object.
	 * @return string
	 */
	public static function get_post_type_archive_url( $post_type, $pt_obj = null ) {
		if ( null === $pt_obj ) {
			$pt_obj = get_post_type_object( $post_type );
		}

		if ( ! $pt_obj ) {
			return '';
		}

		// Posts.
		if ( 'post' === $post_type ) {
			$page_for_posts = (int) get_option( 'page_for_posts' );
			if ( $page_for_posts ) {
				$url = get_permalink( $page_for_posts );
				return $url ? $url : '';
			}

			return home_url( '/blog/' );
		}

		// Pages do not have true archive listing pages.
		if ( 'page' === $post_type ) {
			return '';
		}

		$url = get_post_type_archive_link( $post_type );
		return $url ? $url : '';
	}

	/**
	 * Get archive path.
	 *
	 * @param string      $post_type Post type.
	 * @param object|null $pt_obj Optional object.
	 * @return string
	 */
	public static function get_post_type_archive_path( $post_type, $pt_obj = null ) {
		$archive_url = self::get_post_type_archive_url( $post_type, $pt_obj );

		if ( '' === $archive_url ) {
			return '';
		}

		$path = (string) wp_parse_url( $archive_url, PHP_URL_PATH );
		$path = '/' . trim( $path, '/' ) . '/';

		if ( '//' === $path ) {
			$path = '/';
		}

		return $path;
	}

	/**
	 * Get possible URL base prefixes for a post type.
	 *
	 * @param string      $post_type Post type.
	 * @param object|null $pt_obj Post type object.
	 * @return array
	 */
	private static function get_post_type_match_prefixes( $post_type, $pt_obj = null ) {
		if ( null === $pt_obj ) {
			$pt_obj = get_post_type_object( $post_type );
		}

		$prefixes = array();

		$archive_path = self::get_post_type_archive_path( $post_type, $pt_obj );
		if ( '' !== $archive_path && '/' !== $archive_path ) {
			$prefixes[] = $archive_path;
		}

		if ( $pt_obj && ! empty( $pt_obj->rewrite ) && is_array( $pt_obj->rewrite ) && ! empty( $pt_obj->rewrite['slug'] ) ) {
			$rewrite_path = '/' . trim( (string) $pt_obj->rewrite['slug'], '/' ) . '/';
			if ( '//' !== $rewrite_path ) {
				$prefixes[] = $rewrite_path;
			}
		}

		// For standard posts in many setups, a posts page creates /blog/ style URLs.
		if ( 'post' === $post_type ) {
			$page_for_posts = (int) get_option( 'page_for_posts' );
			if ( $page_for_posts ) {
				$posts_page_path = (string) wp_parse_url( get_permalink( $page_for_posts ), PHP_URL_PATH );
				$posts_page_path = '/' . trim( $posts_page_path, '/' ) . '/';
				if ( '//' !== $posts_page_path ) {
					$prefixes[] = $posts_page_path;
				}
			}
		}

		$prefixes = array_values( array_unique( array_filter( $prefixes ) ) );
		usort(
			$prefixes,
			static function( $a, $b ) {
				return strlen( $b ) <=> strlen( $a );
			}
		);

		return $prefixes;
	}

	/**
	 * Detect post type from URL.
	 *
	 * @param string $url URL.
	 * @return string|false
	 */
	public static function detect_post_type_from_url( $url ) {
		$normalized = self::normalize_url( $url );
		if ( '' === $normalized ) {
			return false;
		}

		$path       = (string) wp_parse_url( $normalized, PHP_URL_PATH );
		$path       = '/' . ltrim( $path, '/' );
		$query      = (string) wp_parse_url( $normalized, PHP_URL_QUERY );
		$query_args = array();
		if ( '' !== $query ) {
			parse_str( $query, $query_args );
		}
		$post_types = get_post_types( array( 'public' => true ), 'objects' );

		if ( ! empty( $query_args['post_type'] ) ) {
			$query_post_type = sanitize_key( $query_args['post_type'] );
			if ( isset( $post_types[ $query_post_type ] ) ) {
				return $query_post_type;
			}
		}

		foreach ( $post_types as $pt_slug => $pt_obj ) {
			$prefixes = self::get_post_type_match_prefixes( $pt_slug, $pt_obj );
			foreach ( $prefixes as $prefix ) {
				if ( 0 === strpos( $path, $prefix ) && rtrim( $path, '/' ) !== rtrim( $prefix, '/' ) ) {
					return $pt_slug;
				}
			}
		}

		return false;
	}

	/**
	 * Check singular style URL pattern.
	 *
	 * @param string $url URL.
	 * @param string $post_type Post type.
	 * @return bool
	 */
	public static function is_singular_style_url_for_post_type( $url, $post_type ) {
		$normalized = self::normalize_url( $url );
		if ( '' === $normalized ) {
			return false;
		}

		$path     = (string) wp_parse_url( $normalized, PHP_URL_PATH );
		$path     = '/' . ltrim( $path, '/' );
		$query    = (string) wp_parse_url( $normalized, PHP_URL_QUERY );
		$prefixes = self::get_post_type_match_prefixes( $post_type );

		if ( empty( $prefixes ) ) {
			$query_args = array();
			if ( '' !== $query ) {
				parse_str( $query, $query_args );
			}
			if ( ! empty( $query_args['post_type'] ) && sanitize_key( $query_args['post_type'] ) === sanitize_key( $post_type ) ) {
				return true;
			}
			return false;
		}

		$reject_patterns = array(
			'#/(feed|embed|amp)(/|$)#i',
			'#/(page)/[0-9]+(/|$)#i',
			'#/(tag|category|author|search|wp-json|comments)(/|$)#i',
			'#\.(jpg|jpeg|png|gif|svg|webp|pdf|doc|docx|xls|xlsx|zip|mp4|mp3)$#i',
		);

		foreach ( $reject_patterns as $pattern ) {
			if ( preg_match( $pattern, $path ) ) {
				return false;
			}
		}

		foreach ( $prefixes as $prefix ) {
			if ( 0 !== strpos( $path, $prefix ) ) {
				continue;
			}

			if ( rtrim( $path, '/' ) === rtrim( $prefix, '/' ) ) {
				continue;
			}

			$remaining = trim( substr( $path, strlen( $prefix ) ), '/' );
			if ( '' !== $remaining ) {
				return true;
			}
		}

		return false;
	}

	/**
	 * Get enabled post types.
	 *
	 * @return array
	 */
	public static function get_enabled_post_types() {
		$settings = get_option( 'srk_smart_archive_redirect_settings', array() );

		if ( isset( $settings['post_types'] ) && is_array( $settings['post_types'] ) ) {
			$enabled = array();
			$has_string_keys = count( array_filter( array_keys( $settings['post_types'] ), 'is_string' ) ) > 0;

			if ( $has_string_keys ) {
				foreach ( $settings['post_types'] as $post_type => $enabled_flag ) {
					if ( ! empty( $enabled_flag ) ) {
						$enabled[] = sanitize_key( $post_type );
					}
				}
			} else {
				foreach ( $settings['post_types'] as $post_type ) {
					if ( ! empty( $post_type ) ) {
						$enabled[] = sanitize_key( $post_type );
					}
				}
			}

			return array_values( array_unique( $enabled ) );
		}

		$legacy = get_option( 'srk_smart_redirect_post_types', array() );

		return array_values( array_unique( array_map( 'sanitize_key', (array) $legacy ) ) );
	}

	/**
	 * Is feature enabled.
	 *
	 * @return bool
	 */
	public static function is_feature_enabled() {
		$settings = get_option( 'srk_smart_archive_redirect_settings', array() );

		if ( isset( $settings['enabled'] ) ) {
			return ! empty( $settings['enabled'] );
		}

		$legacy = get_option( 'srk_smart_redirect_post_types', array() );
		return ! empty( $legacy );
	}

	/**
	 * Is post type enabled.
	 *
	 * @param string $post_type Post type.
	 * @return bool
	 */
	public static function is_post_type_enabled( $post_type ) {
		return in_array( sanitize_key( $post_type ), self::get_enabled_post_types(), true );
	}
}