<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Search Console preparation and sitemap recovery helpers.
 *
 * This service intentionally does not implement OAuth yet. It prepares
 * sitemap, URL inspection, and recovery data for future GSC integration.
 */
class SRK_Search_Console_Service {

	/**
	 * Detect sitemap and robots.txt status for the current site.
	 *
	 * @return array
	 */
	public function get_sitemap_health( $base_url = '', $force = false ) {
		$base_url   = $this->normalize_base_url( $base_url ? $base_url : home_url( '/' ) );
		$cache_key  = $this->get_sitemap_cache_key( $base_url );

		if ( ! $force ) {
			$cached = get_transient( $cache_key );
			if ( is_array( $cached ) ) {
				return $cached;
			}

			return $this->get_unchecked_sitemap_health( $base_url );
		}

		if ( $this->should_skip_sitemap_network( $base_url ) ) {
			$skipped = $this->get_unchecked_sitemap_health( $base_url );
			$skipped['skipped'] = true;
			$skipped['message'] = __( 'Sitemap check skipped for local, test, private, or non-HTTPS sites. Use a live HTTPS domain for cleanup checks.', 'seo-repair-kit' );
			set_transient( $cache_key, $skipped, HOUR_IN_SECONDS );
			return $skipped;
		}

		$candidates = $this->get_sitemap_candidates( $base_url );
		$detected = null;

		foreach ( $candidates as $url ) {
			$status = $this->get_http_status( $url );
			if ( $status >= 200 && $status < 400 ) {
				$detected = array(
					'url'         => $url,
					'reachable'   => true,
					'http_status' => $status,
				);
				break;
			}
		}

		if ( ! $detected ) {
			$first = reset( $candidates );
			$detected = array(
				'url'         => $first,
				'reachable'   => false,
				'http_status' => $first ? $this->get_http_status( $first ) : 0,
			);
		}

		$robots_url = trailingslashit( $base_url ) . 'robots.txt';
		$health = array(
			'sitemap_url'    => $detected['url'],
			'reachable'      => $detected['reachable'],
			'http_status'    => $detected['http_status'],
			'last_checked'   => current_time( 'mysql' ),
			'robots_url'     => $robots_url,
			'robots_status'  => $this->get_http_status( $robots_url ),
			'gsc_links'      => $this->get_gsc_links( $base_url ),
		);

		set_transient( $cache_key, $health, HOUR_IN_SECONDS );
		return $health;
	}

	/**
	 * Analyze one cleanup URL.
	 *
	 * @param string $url URL.
	 * @return array
	 */
	public function analyze_url( $url ) {
		$url = esc_url_raw( $url );
		$status_data = $this->request_url_status( $url );
		$sitemap = $this->get_sitemap_health( $url, true );
		$sitemap_presence = $this->find_url_in_sitemaps( $url, $sitemap['sitemap_url'] );

		$analysis = array(
			'http_status'        => absint( $status_data['http_status'] ),
			'redirect_status'    => absint( $status_data['redirect_status'] ),
			'url_exists'         => $status_data['http_status'] >= 200 && $status_data['http_status'] < 400,
			'canonical_url'      => $status_data['canonical_url'],
			'sitemap_url'        => $sitemap_presence['matched_sitemap'] ? $sitemap_presence['matched_sitemap'] : $sitemap['sitemap_url'],
			'in_sitemap'         => $sitemap_presence['found'] ? 1 : 0,
			'sitemap_checked_at' => current_time( 'mysql' ),
			'recommendation'     => '',
		);

		$analysis['recommendation'] = $this->get_recommendation( $analysis );
		return $analysis;
	}

	/**
	 * Get Search Console action links.
	 *
	 * @param string $property Property URL.
	 * @return array
	 */
	public function get_gsc_links( $property ) {
		$encoded = rawurlencode( $property );
		return array(
			'removals'   => 'https://search.google.com/search-console/removals?resource_id=' . $encoded,
			'inspection' => 'https://search.google.com/search-console/inspect?resource_id=' . $encoded,
			'sitemaps'   => 'https://search.google.com/search-console/sitemaps?resource_id=' . $encoded,
		);
	}

	/**
	 * Get URL Inspection deep link.
	 *
	 * @param string $property Property URL.
	 * @param string $url URL.
	 * @return string
	 */
	public function get_inspection_url( $property, $url ) {
		return 'https://search.google.com/search-console/inspect?resource_id=' . rawurlencode( $property ) . '&url=' . rawurlencode( $url );
	}

	/**
	 * Generate a compact recommendation from a stored cleanup row.
	 *
	 * @param array $row Cleanup row.
	 * @return string
	 */
	public function get_recommendation_from_row( array $row ) {
		if ( null === ( $row['http_status'] ?? null ) || 0 === absint( $row['http_status'] ?? 0 ) ) {
			return __( 'Click Check URL before taking action.', 'seo-repair-kit' );
		}

		return $this->get_recommendation(
			array(
				'http_status' => absint( $row['http_status'] ?? 0 ),
				'in_sitemap'  => ! empty( $row['in_sitemap'] ),
			)
		);
	}

	/**
	 * Get sitemap candidates.
	 *
	 * @return array
	 */
	private function get_sitemap_candidates( $base_url ) {
		$base_url = trailingslashit( $this->normalize_base_url( $base_url ) );
		$candidates = array(
			$base_url . 'sitemap.xml',
			$base_url . 'sitemap_index.xml',
			$base_url . 'wp-sitemap.xml',
		);

		$robots = wp_remote_get( $base_url . 'robots.txt', array( 'timeout' => 8 ) );
		if ( ! is_wp_error( $robots ) ) {
			$body = wp_remote_retrieve_body( $robots );
			if ( preg_match_all( '/^\s*Sitemap:\s*(\S+)/mi', $body, $matches ) ) {
				$candidates = array_merge( $matches[1], $candidates );
			}
		}

		return array_values( array_unique( array_filter( array_map( 'esc_url_raw', $candidates ) ) ) );
	}

	/**
	 * Get unchecked sitemap health without making HTTP requests.
	 *
	 * @param string $base_url Base URL.
	 * @return array
	 */
	private function get_unchecked_sitemap_health( $base_url ) {
		$base_url = trailingslashit( $this->normalize_base_url( $base_url ) );

		return array(
			'sitemap_url'   => $base_url . 'sitemap.xml',
			'reachable'     => null,
			'http_status'   => 0,
			'last_checked'  => '',
			'robots_url'    => $base_url . 'robots.txt',
			'robots_status' => 0,
			'gsc_links'     => $this->get_gsc_links( $base_url ),
			'message'       => __( 'Sitemap health has not been checked yet. Click Refresh when you need a live check.', 'seo-repair-kit' ),
		);
	}

	/**
	 * Get sitemap cache key.
	 *
	 * @param string $base_url Base URL.
	 * @return string
	 */
	private function get_sitemap_cache_key( $base_url ) {
		return 'srk_sm_sitemap_health_' . md5( $this->normalize_base_url( $base_url ) );
	}

	/**
	 * Skip live sitemap checks for local, private, or non-HTTPS sites.
	 *
	 * @param string $base_url Base URL.
	 * @return bool
	 */
	private function should_skip_sitemap_network( $base_url ) {
		$scheme = strtolower( (string) wp_parse_url( $base_url, PHP_URL_SCHEME ) );
		$host   = strtolower( (string) wp_parse_url( $base_url, PHP_URL_HOST ) );

		if ( 'https' !== $scheme ) {
			return true;
		}
		if ( in_array( $host, array( 'localhost', '127.0.0.1', '::1' ), true ) ) {
			return true;
		}
		if ( preg_match( '/\.(test|local|localhost|invalid)$/i', $host ) ) {
			return true;
		}
		if ( filter_var( $host, FILTER_VALIDATE_IP ) ) {
			return ! filter_var( $host, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE );
		}

		return false;
	}

	/**
	 * Normalize any URL to scheme + host base URL.
	 *
	 * @param string $url URL.
	 * @return string
	 */
	private function normalize_base_url( $url ) {
		$url = esc_url_raw( $url );
		$scheme = wp_parse_url( $url, PHP_URL_SCHEME );
		$host = wp_parse_url( $url, PHP_URL_HOST );

		if ( ! $host ) {
			return home_url( '/' );
		}

		$scheme = in_array( strtolower( (string) $scheme ), array( 'http', 'https' ), true ) ? strtolower( (string) $scheme ) : 'https';

		return $scheme . '://' . strtolower( $host ) . '/';
	}

	/**
	 * Get basic HTTP status.
	 *
	 * @param string $url URL.
	 * @return int
	 */
	private function get_http_status( $url ) {
		$response = wp_remote_head( $url, array( 'timeout' => 8, 'redirection' => 3 ) );
		if ( is_wp_error( $response ) ) {
			$response = wp_remote_get( $url, array( 'timeout' => 8, 'redirection' => 3 ) );
		}
		return is_wp_error( $response ) ? 0 : absint( wp_remote_retrieve_response_code( $response ) );
	}

	/**
	 * Request URL status and canonical.
	 *
	 * @param string $url URL.
	 * @return array
	 */
	private function request_url_status( $url ) {
		$head = wp_remote_head( $url, array( 'timeout' => 8, 'redirection' => 0 ) );
		$redirect_status = is_wp_error( $head ) ? 0 : absint( wp_remote_retrieve_response_code( $head ) );
		$response = wp_remote_get( $url, array( 'timeout' => 12, 'redirection' => 3 ) );
		if ( is_wp_error( $response ) ) {
			return array( 'http_status' => 0, 'redirect_status' => $redirect_status, 'canonical_url' => '' );
		}

		$body = wp_remote_retrieve_body( $response );
		$canonical = '';
		if ( preg_match( '/<link[^>]+rel=["\']canonical["\'][^>]+href=["\']([^"\']+)["\']/i', $body, $match ) ) {
			$canonical = esc_url_raw( html_entity_decode( $match[1], ENT_QUOTES ) );
		}

		return array(
			'http_status'     => absint( wp_remote_retrieve_response_code( $response ) ),
			'redirect_status' => $redirect_status >= 300 && $redirect_status < 400 ? $redirect_status : 0,
			'canonical_url'   => $canonical,
		);
	}

	/**
	 * Check if a URL is present in the detected sitemap.
	 *
	 * @param string $url URL.
	 * @param string $sitemap_url Sitemap URL.
	 * @return bool
	 */
	private function find_url_in_sitemaps( $url, $sitemap_url ) {
		$result = array(
			'found'           => false,
			'matched_sitemap' => '',
		);

		if ( empty( $url ) || empty( $sitemap_url ) ) {
			return $result;
		}

		$checked = array();
		$queue   = array( esc_url_raw( $sitemap_url ) );
		$target  = $this->normalize_url_for_sitemap_compare( $url );

		while ( ! empty( $queue ) && count( $checked ) < 25 ) {
			$current = array_shift( $queue );
			if ( isset( $checked[ $current ] ) ) {
				continue;
			}
			$checked[ $current ] = true;

			$body = $this->fetch_sitemap_body( $current );
			if ( '' === $body ) {
				continue;
			}

			if ( false !== strpos( $body, $url ) || false !== strpos( $body, esc_url_raw( untrailingslashit( $url ) ) ) ) {
				return array(
					'found'           => true,
					'matched_sitemap' => $current,
				);
			}

			if ( false !== strpos( $body, '<sitemapindex' ) && preg_match_all( '/<loc>\s*([^<]+)\s*<\/loc>/i', $body, $matches ) ) {
				foreach ( $matches[1] as $child ) {
					$child = esc_url_raw( html_entity_decode( trim( $child ), ENT_QUOTES ) );
					if ( $child && ! isset( $checked[ $child ] ) ) {
						$queue[] = $child;
					}
				}
				continue;
			}

			if ( preg_match_all( '/<loc>\s*([^<]+)\s*<\/loc>/i', $body, $matches ) ) {
				foreach ( $matches[1] as $loc ) {
					if ( $target === $this->normalize_url_for_sitemap_compare( html_entity_decode( trim( $loc ), ENT_QUOTES ) ) ) {
						return array(
							'found'           => true,
							'matched_sitemap' => $current,
						);
					}
				}
			}
		}

		return $result;
	}

	/**
	 * Fetch sitemap XML body.
	 *
	 * @param string $sitemap_url Sitemap URL.
	 * @return string
	 */
	private function fetch_sitemap_body( $sitemap_url ) {
		$response = wp_remote_get( $sitemap_url, array( 'timeout' => 12 ) );
		if ( is_wp_error( $response ) || absint( wp_remote_retrieve_response_code( $response ) ) >= 400 ) {
			return '';
		}

		return (string) wp_remote_retrieve_body( $response );
	}

	/**
	 * Normalize URL for sitemap comparison.
	 *
	 * @param string $url URL.
	 * @return string
	 */
	private function normalize_url_for_sitemap_compare( $url ) {
		$url = esc_url_raw( $url );
		$parts = wp_parse_url( $url );
		if ( empty( $parts['host'] ) ) {
			return untrailingslashit( strtolower( $url ) );
		}

		$scheme = strtolower( $parts['scheme'] ?? 'https' );
		$host   = strtolower( $parts['host'] );
		$path   = isset( $parts['path'] ) ? '/' . ltrim( $parts['path'], '/' ) : '/';

		return untrailingslashit( $scheme . '://' . $host . $path );
	}

	/**
	 * Generate recommendation from analysis.
	 *
	 * @param array $analysis Analysis.
	 * @return string
	 */
	private function get_recommendation( array $analysis ) {
		$status = absint( $analysis['http_status'] ?? 0 );
		$in_sitemap = ! empty( $analysis['in_sitemap'] );

		if ( 404 === $status || 410 === $status ) {
			return $in_sitemap
				? __( 'URL is removed but still appears in sitemap. Remove it from the sitemap source, regenerate sitemap, submit sitemap, then monitor Google removal.', 'seo-repair-kit' )
				: __( 'URL already removed. Submit sitemap and monitor Google removal.', 'seo-repair-kit' );
		}

		if ( $status >= 200 && $status < 300 ) {
			return $in_sitemap
				? __( 'URL is live and still in sitemap. Clean content first, update sitemap lastmod or remove URL, then request reindex from Search Console.', 'seo-repair-kit' )
				: __( 'URL is live. Clean content first before requesting reindex or using removals.', 'seo-repair-kit' );
		}

		if ( $status >= 300 && $status < 400 ) {
			return __( 'URL redirects. Verify the redirect target is clean, update sitemap to the final URL, then monitor Google.', 'seo-repair-kit' );
		}

		if ( $status >= 500 ) {
			return __( 'The website returned a server error. Fix the page, hosting, firewall, or security rule first, then recheck before using Search Console removal or reindex actions.', 'seo-repair-kit' );
		}

		return __( 'Could not confirm URL status. Manually check hosting, security rules, and Search Console before requesting removal or reindexing.', 'seo-repair-kit' );
	}
}
