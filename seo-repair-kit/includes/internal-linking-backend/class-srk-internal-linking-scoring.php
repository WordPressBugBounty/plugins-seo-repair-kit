<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Strict opportunity scoring.
 *
 * Rules:
 * - Taxonomy never creates an anchor.
 * - Broad-only matches are rejected.
 * - Exact target keyword and close partial keyword windows are allowed.
 * - Generic editorial phrases are rejected before scoring.
 */
class SRK_Internal_Linking_Scoring {
	const MIN_SCORE      = 68;
	const RULE_WEIGHT    = 0.7;
	const AI_WEIGHT      = 0.3;
	const AI_FLOOR_SCORE = 68;

	/**
	 * Convert cosine similarity (0–1) to a 0–100 AI score.
	 *
	 * @param float $similarity Cosine similarity.
	 * @return int
	 */
	public static function similarity_to_score( $similarity ) {
		return min( 100, max( 0, absint( round( floatval( $similarity ) * 100 ) ) ) );
	}

	/**
	 * Combine rule engine score with AI semantic score.
	 *
	 * final_score = rule_score * 0.7 + ai_score * 0.3
	 *
	 * @param int  $rule_score   Rule engine score.
	 * @param int  $ai_score     AI semantic score (0–100).
	 * @param bool $ai_approved  Whether target passed AI gatekeeper.
	 * @return int
	 */
	public static function calculate_hybrid( $rule_score, $ai_score, $ai_approved = false ) {
		$hybrid = ( absint( $rule_score ) * self::RULE_WEIGHT ) + ( absint( $ai_score ) * self::AI_WEIGHT );
		$hybrid = absint( round( $hybrid ) );

		if ( $ai_approved ) {
			$hybrid = max( self::AI_FLOOR_SCORE, $hybrid );
		}

		return min( 100, max( 0, $hybrid ) );
	}

	/**
	 * Build display reason including AI gatekeeper context when active.
	 *
	 * @param string $rule_reason  Rule-based reason text.
	 * @param float  $similarity   Cosine similarity (0–1).
	 * @param bool   $ai_approved  Whether AI filter approved this target.
	 * @return string
	 */
	public static function reason_hybrid( $rule_reason, $similarity, $ai_approved = false ) {
		if ( ! $ai_approved ) {
			return $rule_reason;
		}

		$pct = absint( round( floatval( $similarity ) * 100 ) );

		return sprintf(
			/* translators: 1: semantic similarity percentage, 2: rule-based reason */
			__( 'AI semantic gate (%1$d%%) + %2$s', 'seo-repair-kit' ),
			$pct,
			$rule_reason
		);
	}

	/**
	 * Append GPT validation reasoning to opportunity reason text.
	 *
	 * @param string $reason            Existing reason.
	 * @param string $validation_reason GPT validation explanation.
	 * @return string
	 */
	public static function append_validation_reason( $reason, $validation_reason ) {
		$validation_reason = trim( sanitize_text_field( (string) $validation_reason ) );

		if ( '' === $validation_reason ) {
			return $reason;
		}

		return sprintf(
			/* translators: 1: existing reason, 2: GPT validation note */
			__( '%1$s | GPT: %2$s', 'seo-repair-kit' ),
			$reason,
			$validation_reason
		);
	}

	public static function calculate( $args = array() ) {
		$args = wp_parse_args(
			$args,
			array(
				'anchor_text'          => '',
				'sentence'             => '',
				'keyword'              => '',
				'keyword_source'       => '',
				'match_type'           => '',
				'matched_terms'        => array(),
				'matched_specific'     => array(),
				'relationship_score'   => 0,
				'ai_score'             => 0,
				'ai_match'             => false,
				'shared_taxonomy'      => false,
				'target_inbound_count' => 0,
				'already_linked'       => false,
			)
		);

		$anchor = trim(
			wp_strip_all_tags(
				(string) $args['anchor_text']
			)
		);

		$source = sanitize_key(
			$args['keyword_source']
		);

		/*
		* Hard rejection rules.
		*/
		if ( '' === $anchor || ! empty( $args['already_linked'] ) ) {
			return 0;
		}

		if ( 'taxonomy' === $source ) {
			return 0;
		}

		if (
			SRK_Internal_Linking_Keywords::is_generic_phrase(
				$anchor
			)
		) {
			return 0;
		}

		$anchor_words = array_values(
			array_unique(
				SRK_Internal_Linking_Keywords::meaningful_words(
					$anchor
				)
			)
		);

		$keyword_words = array_values(
			array_unique(
				SRK_Internal_Linking_Keywords::meaningful_words(
					$args['keyword']
				)
			)
		);

		$anchor_word_count = count(
			preg_split(
				'/\s+/u',
				$anchor,
				-1,
				PREG_SPLIT_NO_EMPTY
			)
		);

		$matched = array_values(
			array_intersect(
				$anchor_words,
				$keyword_words
			)
		);

		$specific_match = ! empty( $args['matched_specific'] )
			? array_values(
				array_unique(
					(array) $args['matched_specific']
				)
			)
			: array_values(
				array_intersect(
					$anchor_words,
					SRK_Internal_Linking_Keywords::specific_words(
						$args['keyword']
					)
				)
			);

		if (
			count( $anchor_words ) < 2 ||
			$anchor_word_count < 2 ||
			$anchor_word_count > 8
		) {
			return 0;
		}

		if ( count( $matched ) < 2 ) {
			return 0;
		}

		/*
		* Broad terms such as WordPress, SEO, website, and content are
		* not enough on their own.
		*/
		if ( empty( $specific_match ) ) {
			return 0;
		}

		$score = 0;

		/*
		* Anchor matching quality: maximum 34 points.
		*/
		switch ( $args['match_type'] ) {
			case 'exact_keyword':
				$score += 34;
				break;

			case 'consecutive_keyword_terms':
				$score += 29;
				break;

			case 'keyword_window':
				$score += 23;
				break;

			default:
				$score += 17;
				break;
		}

		/*
		* Keyword source quality: maximum 18 points.
		*/
		switch ( $source ) {
			case 'custom':
				$score += 18;
				break;

			case 'gsc':
				$score += 17;
				break;

			case 'title':
				$score += 14;
				break;

			case 'slug':
				$score += 10;
				break;

			case 'heading':
				$score += 7;
				break;
		}

		/*
		* Keyword overlap: maximum 15 points.
		*/
		$score += min(
			9,
			count( $matched ) * 3
		);

		$score += min(
			6,
			count( $specific_match ) * 3
		);

		/*
		* Rule-based topical relationship: maximum 12 points.
		*/
		$relationship_score = min(
			100,
			absint( $args['relationship_score'] )
		);

		$score += absint(
			round(
				$relationship_score * 0.12
			)
		);

		/*
		* Natural anchor length: maximum 8 points.
		*/
		if ( $anchor_word_count >= 2 && $anchor_word_count <= 4 ) {
			$score += 8;
		} elseif ( 5 === $anchor_word_count ) {
			$score += 6;
		} elseif ( 6 === $anchor_word_count ) {
			$score += 4;
		} else {
			$score += 2;
		}

		/*
		* Shared taxonomy is only a supporting signal.
		*/
		if ( ! empty( $args['shared_taxonomy'] ) ) {
			$score += 3;
		}

		/*
		* Low inbound targets receive a small opportunity bonus.
		*/
		$target_inbound_count = absint(
			$args['target_inbound_count']
		);

		if ( 0 === $target_inbound_count ) {
			$score += 5;
		} elseif ( 1 === $target_inbound_count ) {
			$score += 3;
		}

		/*
		* Optional semantic score.
		*
		* Keep this bonus small because the dedicated hybrid pipeline
		* already has calculate_hybrid().
		*/
		if ( ! empty( $args['ai_match'] ) ) {
			$ai_score = min(
				100,
				absint( $args['ai_score'] )
			);

			$score += absint(
				round(
					$ai_score * 0.05
				)
			);
		}

		return min(
			100,
			max(
				0,
				absint(
					round( $score )
				)
			)
		);
	}

	public static function reason( $args = array() ) {
		$args = wp_parse_args(
			$args,
			array(
				'match_type'           => '',
				'keyword_source'       => '',
				'shared_taxonomy'      => false,
				'target_inbound_count' => 0,
			)
		);

		$parts = array();

		if ( 'exact_keyword' === $args['match_type'] ) {
			$parts[] = __( 'Exact target keyword found', 'seo-repair-kit' );
		} elseif ( 'consecutive_keyword_terms' === $args['match_type'] ) {
			$parts[] = __( 'Target keyword phrase found in content', 'seo-repair-kit' );
		} else {
			$parts[] = __( 'Specific target keyword terms found close together', 'seo-repair-kit' );
		}

		$source = sanitize_key( $args['keyword_source'] );

		if ( in_array( $source, array( 'custom', 'gsc' ), true ) ) {
			$parts[] = __( 'strong target keyword source', 'seo-repair-kit' );
		} elseif ( 'title' === $source ) {
			$parts[] = __( 'target title match', 'seo-repair-kit' );
		} elseif ( 'slug' === $source ) {
			$parts[] = __( 'target slug match', 'seo-repair-kit' );
		}

		$parts[] = __( 'natural anchor length', 'seo-repair-kit' );

		if ( ! empty( $args['shared_taxonomy'] ) ) {
			$parts[] = __( 'same topic bucket', 'seo-repair-kit' );
		}

		if ( absint( $args['target_inbound_count'] ) <= 1 ) {
			$parts[] = __( 'low inbound target', 'seo-repair-kit' );
		}

		return implode( ' + ', $parts );
	}
}
