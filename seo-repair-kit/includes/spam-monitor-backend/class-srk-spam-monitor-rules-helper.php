<?php
/**
 * Spam Monitor rules helpers.
 *
 * @package Seo_Repair_Kit
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Maps saved WordPress Spam Rules into external SERP engine payloads.
 */
class SRK_Spam_Monitor_Rules_Helper {

	/**
	 * Get saved Spam Rules in the Python SERP scan payload format.
	 *
	 * @return array
	 */
	public static function get_rules_for_serp_scan() {
		$defaults = class_exists( 'SRK_Spam_Monitor_DB' ) ? SRK_Spam_Monitor_DB::get_default_rules() : array();
		$saved    = class_exists( 'SRK_Spam_Monitor_DB' ) ? SRK_Spam_Monitor_DB::get_all_rules() : array();
		$rules    = wp_parse_args( is_array( $saved ) ? $saved : array(), $defaults );

		$allowed_languages       = self::normalize_language_list( $rules['lang_allowed'] ?? array( 'en' ), array( 'en' ) );
		$expected_spam_languages = self::normalize_expected_spam_languages( $rules['lang_expected'] ?? array(), $allowed_languages );

		return array(
			'language_rules'     => array(
				'expected_spam_languages'  => $expected_spam_languages,
				'allowed_languages'        => $allowed_languages,
				'language_mismatch_score'  => absint( $rules['lang_mismatch_score'] ?? 45 ),
				'flag_unexpected_languages'=> ! empty( $rules['lang_flag_unexpected'] ),
			),
			'spam_keyword_rules' => array(
				'enabled_categories'       => self::map_keyword_categories( $rules['keyword_categories'] ?? array() ),
				'category_keywords'        => self::map_keyword_category_terms( $rules['keyword_category_terms'] ?? array() ),
				'custom_blocked_keywords'  => self::normalize_string_list( $rules['custom_blocked_keywords'] ?? array() ),
				'spam_keyword_score'       => absint( $rules['spam_keyword_score'] ?? 35 ),
			),
			'url_pattern_rules'  => array(
				'detect_fake_product_urls'    => ! empty( $rules['url_detect_fake_product'] ),
				'detect_suspicious_url_slugs' => ! empty( $rules['url_detect_suspicious_slugs'] ),
				'blocked_url_patterns'        => self::normalize_string_list( $rules['url_blocked_patterns'] ?? array() ),
				'url_pattern_score'           => absint( $rules['url_pattern_score'] ?? 35 ),
			),
			'score_thresholds'   => array(
				'clean_max'       => absint( $rules['score_clean_max'] ?? 24 ),
				'suspicious_max'  => absint( $rules['score_suspicious_max'] ?? 49 ),
				'spam_starts'     => absint( $rules['score_spam_min'] ?? 50 ),
				'critical_starts' => absint( $rules['score_critical_min'] ?? 75 ),
			),
		);
	}

	/**
	 * Convert local keyword category keys to Python payload keys.
	 *
	 * @param mixed $saved Saved category list.
	 * @return array
	 */
	private static function map_keyword_categories( $saved ) {
		$active = array_fill_keys( self::normalize_string_list( $saved ), true );

		$map = array(
			'gambling'             => 'gambling',
			'adult'                => 'adult',
			'pharma'               => 'pharma',
			'vape'                 => 'vape_tobacco_shisha',
			'counterfeit'          => 'counterfeit_luxury',
			'jp_cn_spam'           => 'jp_cn_ecommerce',
			'vape_tobacco_shisha'  => 'vape_tobacco_shisha',
			'counterfeit_luxury'   => 'counterfeit_luxury',
			'jp_cn_ecommerce'      => 'jp_cn_ecommerce',
		);

		$payload = array(
			'gambling'             => false,
			'adult'                => false,
			'pharma'               => false,
			'vape_tobacco_shisha'  => false,
			'counterfeit_luxury'   => false,
			'jp_cn_ecommerce'      => false,
		);

		foreach ( $map as $local => $python ) {
			if ( ! empty( $active[ $local ] ) ) {
				$payload[ $python ] = true;
			}
		}

		return $payload;
	}

	/**
	 * Convert editable local category keyword lists to Python category keys.
	 *
	 * @param mixed $terms Saved terms by local category.
	 * @return array
	 */
	private static function map_keyword_category_terms( $terms ) {
		$map = array(
			'gambling'    => 'gambling',
			'adult'       => 'adult',
			'pharma'      => 'pharma',
			'vape'        => 'vape_tobacco_shisha',
			'counterfeit' => 'counterfeit_luxury',
			'jp_cn_spam'  => 'jp_cn_ecommerce',
		);

		$payload = array();
		if ( ! is_array( $terms ) ) {
			return $payload;
		}

		foreach ( $map as $local => $python ) {
			if ( array_key_exists( $local, $terms ) ) {
				$payload[ $python ] = self::normalize_string_list( $terms[ $local ] );
			}
		}

		return $payload;
	}

	/**
	 * Normalize language codes from arrays, comma text, or newline text.
	 *
	 * @param mixed $value Raw value.
	 * @param array $fallback Fallback list.
	 * @return array
	 */
	private static function normalize_language_list( $value, array $fallback ) {
		$items = self::normalize_string_list( $value );
		$items = array_values( array_filter( array_map( 'sanitize_key', $items ) ) );

		return ! empty( $items ) ? $items : $fallback;
	}

	/**
	 * Normalize expected spam language values for the Python payload.
	 *
	 * @param mixed $value Raw saved expected language value.
	 * @param array $allowed_languages Allowed site languages.
	 * @return array
	 */
	private static function normalize_expected_spam_languages( $value, array $allowed_languages ) {
		$fallback = array( 'zh', 'ko', 'ja', 'ru', 'fr', 'de', 'ur', 'th', 'vi', 'id' );
		$items    = self::normalize_language_list( $value, $fallback );

		if ( 1 === count( $items ) && in_array( $items[0], $allowed_languages, true ) ) {
			return $fallback;
		}

		return $items;
	}

	/**
	 * Normalize arrays, comma strings, and newline strings into a string list.
	 *
	 * @param mixed $value Raw value.
	 * @return array
	 */
	private static function normalize_string_list( $value ) {
		if ( is_string( $value ) ) {
			$value = preg_split( '/[\r\n,]+/', $value );
		}

		if ( ! is_array( $value ) ) {
			return array();
		}

		$items = array();
		foreach ( $value as $item ) {
			$item = sanitize_text_field( trim( (string) $item ) );
			if ( '' !== $item ) {
				$items[] = $item;
			}
		}

		return array_values( array_unique( $items ) );
	}
}
