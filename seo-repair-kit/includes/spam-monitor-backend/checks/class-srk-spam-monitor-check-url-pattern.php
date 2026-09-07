<?php
/**
 * Spam Monitor — URL Pattern Check
 *
 * @package    Seo_Repair_Kit
 * @subpackage Seo_Repair_Kit/includes/spam-monitor-backend/checks
 * @since      2.1.8
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class SRK_Spam_Monitor_Check_URL_Pattern {

	/** @var string[] Built-in suspicious slug segments. */
	private static $suspicious_slugs = array(
		'/detail/', '/buy/', '/order/', '/cheap-', '/discount-', '/free-',
		'/replica-', '/fake-', '/casino/', '/poker/', '/slots/', '/betting/',
		'/viagra/', '/cialis/', '/pharmacy/', '/payday-loan/', '/credit-card/',
	);

	/** @var string[] Product-like slugs that are suspicious on non-shop sites. */
	private static $product_slugs = array( '/product/', '/products/', '/shop/', '/store/' );

	/**
	 * Run the URL pattern check.
	 *
	 * @param array $snapshot Current snapshot.
	 * @param array $rules    All rules.
	 * @return array Finding array.
	 */
	public static function run( array $snapshot, array $rules ) {
		$url = strtolower( $snapshot['url'] ?? '' );
		if ( ! $url ) {
			return self::clean();
		}

		$detect_fake    = ! empty( $rules['url_detect_fake_product'] );
		$detect_slugs   = ! empty( $rules['url_detect_suspicious_slugs'] );
		$blocked        = self::get_blocked_patterns( $rules );
		$pattern_score  = max( 0, absint( $rules['url_pattern_score'] ?? 35 ) );

		$reasons  = array();
		$evidence = array();

		if ( $detect_slugs ) {
			foreach ( self::$suspicious_slugs as $slug ) {
				if ( false !== strpos( $url, $slug ) ) {
					$reasons[] = "Suspicious URL slug: {$slug}";
					$evidence[] = array( 'type' => 'suspicious_slug', 'slug' => $slug );
				}
			}
		}

		if ( $detect_fake ) {
			$post_type = $snapshot['post_type'] ?? '';
			foreach ( self::$product_slugs as $slug ) {
				if ( false !== strpos( $url, $slug ) && ! in_array( $post_type, array( 'product', 'shop' ), true ) ) {
					$reasons[] = "Fake product URL pattern: {$slug} on post type '{$post_type}'";
					$evidence[] = array( 'type' => 'fake_product', 'slug' => $slug, 'post_type' => $post_type );
				}
			}
		}

		foreach ( $blocked as $pattern ) {
			$pattern = trim( $pattern );
			if ( ! $pattern ) {
				continue;
			}
			$matched = false;
			if ( str_starts_with( $pattern, '/' ) ) {
				$matched = (bool) @preg_match( $pattern, $url );
			} else {
				$matched = false !== strpos( $url, strtolower( $pattern ) );
			}
			if ( $matched ) {
				$reasons[] = "URL matches blocked pattern: {$pattern}";
				$evidence[] = array( 'type' => 'blocked_pattern', 'pattern' => $pattern );
			}
		}

		if ( empty( $reasons ) ) {
			return self::clean();
		}

		$score = min( $pattern_score + max( 0, count( $evidence ) - 1 ) * 10, 60 );

		return array(
			'rule_id'  => 'url_pattern',
			'score'    => $score,
			'severity' => $score >= 60 ? 'high' : 'medium',
			'reason'   => implode( ' ', array_slice( $reasons, 0, 3 ) ),
			'evidence' => $evidence,
			'field'    => 'url',
			// Legacy.
			'issues'   => $reasons,
		);
	}

	private static function get_blocked_patterns( array $rules ) {
		$raw = $rules['url_blocked_patterns'] ?? '';
		if ( is_array( $raw ) ) {
			return $raw;
		}
		return array_filter( array_map( 'trim', explode( "\n", (string) $raw ) ) );
	}

	private static function clean() {
		return array(
			'rule_id'  => 'url_pattern',
			'score'    => 0,
			'severity' => 'low',
			'reason'   => '',
			'evidence' => array(),
			'field'    => 'url',
			'issues'   => array(),
		);
	}
}
