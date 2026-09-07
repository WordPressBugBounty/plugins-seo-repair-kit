<?php
/**
 * Spam Monitor — Keyword Check
 *
 * Scans page content, title, and meta for spam keyword categories.
 *
 * @package    Seo_Repair_Kit
 * @subpackage Seo_Repair_Kit/includes/spam-monitor-backend/checks
 * @since      2.1.8
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class SRK_Spam_Monitor_Check_Keywords {

	/** @var array<string,string[]> Built-in keyword lists per category. */
	private static $builtin = array(
		'gambling'    => array( 'casino', 'poker', 'slots', 'betting', 'wager', 'jackpot', 'roulette', 'blackjack', 'sportsbook', 'baccarat' ),
		'adult'       => array( 'erotic', 'hentai', 'nsfw', 'porn', 'xxx', 'sex', 'nude', 'escort', 'onlyfans', 'camgirl' ),
		'pharma'      => array( 'viagra', 'cialis', 'levitra', 'tramadol', 'xanax', 'oxycodone', 'adderall', 'valium', 'ambien', 'hydrocodone' ),
		'vape'        => array( 'vape', 'vaping', 'e-cigarette', 'shisha', 'hookah', 'tobacco', 'nicotine', 'juul', 'iqos', 'snus' ),
		'counterfeit' => array( 'replica', 'fake rolex', 'fake gucci', 'counterfeit', 'knockoff', 'imitation handbag', 'fake louis vuitton', 'clone watch', 'fake designer', 'copy bag' ),
		'jp_cn_spam'  => array( '激安', '通販', '送料無料', '最安値', '格安', '代引き', '即日発送', '特価', '割引', '無料配送', '淘宝', '天猫', '京东', '拼多多', '代购', '正品', '假货', '特卖', '秒杀', '优惠券' ),
	);

	/**
	 * Get built-in keyword lists for UI/help surfaces.
	 *
	 * @return array<string,string[]>
	 */
	public static function get_builtin_keywords() {
		return self::$builtin;
	}

	/**
	 * Run the keyword check.
	 *
	 * @param array $snapshot Current snapshot.
	 * @param array $rules    All rules.
	 * @return array Finding array.
	 */
	public static function run( array $snapshot, array $rules ) {
		$matched   = array();
		$score     = 0;
		$cats_hit  = array();

		$active_cats    = self::get_active_categories( $rules );
		$custom_kws     = self::get_custom_keywords( $rules );
		$keyword_score  = max( 0, absint( $rules['spam_keyword_score'] ?? 35 ) );

		$haystack = strtolower(
			( $snapshot['page_title']      ?? '' ) . ' ' .
			( $snapshot['meta_title']      ?? '' ) . ' ' .
			( $snapshot['meta_description']?? '' ) . ' ' .
			( $snapshot['plain_text']      ?? $snapshot['content_sample'] ?? '' )
		);

		foreach ( $active_cats as $cat ) {
			foreach ( self::get_category_keywords( $rules, $cat ) as $kw ) {
				if ( false !== strpos( $haystack, strtolower( $kw ) ) ) {
					$matched[]  = $kw;
					$cats_hit[] = $cat;
				}
			}
		}

		foreach ( $custom_kws as $kw ) {
			$kw = trim( strtolower( $kw ) );
			if ( $kw && false !== strpos( $haystack, $kw ) ) {
				$matched[] = $kw;
				$cats_hit[] = 'custom';
			}
		}

		$matched    = array_unique( $matched );
		$cats_hit   = array_unique( $cats_hit );
		$has_match  = ! empty( $matched );
		$score      = $has_match ? min( $keyword_score + max( 0, count( $cats_hit ) - 1 ) * 10, 60 ) : 0;

		return array(
			'rule_id'  => 'keywords',
			'score'    => $score,
			'severity' => $score >= 60 ? 'high' : ( $score > 0 ? 'medium' : 'low' ),
			'reason'   => $has_match
				? sprintf( 'Spam keywords detected in %s: %s', implode( ', ', $cats_hit ) ?: 'custom', implode( ', ', array_slice( $matched, 0, 5 ) ) )
				: '',
			'evidence' => $matched,
			'field'    => 'content',
			// Legacy keys kept for backward compat with scanner.
			'score'    => $score,
			'issues'   => $has_match ? array( 'Spam keywords detected: ' . implode( ', ', $cats_hit ) ) : array(),
			'matched'  => $matched,
		);
	}

	private static function get_active_categories( array $rules ) {
		$defaults = array( 'gambling', 'adult', 'pharma', 'vape', 'counterfeit', 'jp_cn_spam' );
		$saved    = $rules['keyword_categories'] ?? $defaults;
		return is_array( $saved ) ? $saved : $defaults;
	}

	private static function get_custom_keywords( array $rules ) {
		$raw = $rules['custom_blocked_keywords'] ?? '';
		if ( is_array( $raw ) ) {
			return $raw;
		}
		return array_filter( array_map( 'trim', explode( "\n", (string) $raw ) ) );
	}

	private static function get_category_keywords( array $rules, $category ) {
		$terms = $rules['keyword_category_terms'][ $category ] ?? null;
		if ( is_array( $terms ) ) {
			return $terms;
		}

		return self::$builtin[ $category ] ?? array();
	}
}
