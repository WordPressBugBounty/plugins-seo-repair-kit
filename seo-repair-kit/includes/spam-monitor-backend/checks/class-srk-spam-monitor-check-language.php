<?php
/**
 * Spam Monitor — Language Check
 *
 * @package    Seo_Repair_Kit
 * @subpackage Seo_Repair_Kit/includes/spam-monitor-backend/checks
 * @since      2.1.8
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class SRK_Spam_Monitor_Check_Language {

	/**
	 * Languages that are commonly associated with spam injection.
	 * Flagged at higher severity when detected on an English-only site.
	 *
	 * @var string[]
	 */
	private static $high_risk_langs = array( 'ja', 'zh', 'ru', 'ar', 'ko', 'th', 'vi', 'tr' );

	/**
	 * Run the language check.
	 *
	 * @param array $snapshot Current snapshot.
	 * @param array $rules    All rules.
	 * @return array Finding array.
	 */
	public static function run( array $snapshot, array $rules ) {
		if ( empty( $rules['lang_flag_unexpected'] ) ) {
			return self::clean();
		}

		$expected       = self::normalize_language_list( $rules['lang_expected'] ?? 'en', array( 'en' ) );
		$allowed        = self::normalize_language_list( $rules['lang_allowed'] ?? $expected, $expected );
		$mismatch_score = absint( $rules['lang_mismatch_score'] ?? 45 );
		$detected       = sanitize_key( $snapshot['language'] ?? '' );

		if ( ! $detected || in_array( $detected, $allowed, true ) ) {
			return self::clean();
		}

		$is_high_risk = in_array( $detected, self::$high_risk_langs, true );
		$score        = $is_high_risk ? min( $mismatch_score + 15, 60 ) : $mismatch_score;
		$severity     = $score >= 60 ? 'high' : ( $score > 0 ? 'medium' : 'low' );
		$detected_label = class_exists( 'SRK_Spam_Monitor_Alerts' ) ? SRK_Spam_Monitor_Alerts::format_language_code_for_display( $detected ) : strtoupper( $detected );
		$allowed_labels = array();
		foreach ( $allowed as $allowed_code ) {
			$allowed_labels[] = class_exists( 'SRK_Spam_Monitor_Alerts' ) ? SRK_Spam_Monitor_Alerts::format_language_code_for_display( $allowed_code ) : strtoupper( $allowed_code );
		}
		$reason       = sprintf(
			'Language mismatch: detected "%s", expected one of [%s].',
			$detected_label,
			implode( ', ', $allowed_labels )
		);

		return array(
			'rule_id'  => 'language',
			'score'    => $score,
			'severity' => $severity,
			'reason'   => $reason,
			'evidence' => array( 'detected' => $detected_label, 'allowed' => $allowed_labels ),
			'field'    => 'content',
			// Legacy.
			'issues'   => array( $reason ),
		);
	}

	private static function clean() {
		return array(
			'rule_id'  => 'language',
			'score'    => 0,
			'severity' => 'low',
			'reason'   => '',
			'evidence' => array(),
			'field'    => 'content',
			'issues'   => array(),
		);
	}

	/**
	 * Normalize language settings from arrays or comma/newline strings.
	 *
	 * @param mixed $value Raw language setting.
	 * @param array $fallback Fallback language list.
	 * @return string[]
	 */
	private static function normalize_language_list( $value, array $fallback ) {
		if ( is_string( $value ) ) {
			$value = preg_split( '/[\r\n,]+/', $value );
		}

		if ( ! is_array( $value ) ) {
			return $fallback;
		}

		$items = array_filter( array_map( 'sanitize_key', array_map( 'trim', $value ) ) );
		return ! empty( $items ) ? array_values( array_unique( $items ) ) : $fallback;
	}
}
