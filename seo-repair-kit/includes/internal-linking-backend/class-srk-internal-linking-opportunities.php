<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/** link opportunity engine: collect -> score -> sort -> deduplicate -> insert. */
class SRK_Internal_Linking_Opportunities {
	const BATCH_SIZE = 5;
	const MAX_PER_SOURCE = 20;
	const MAX_CANDIDATE_TARGETS_PER_SENTENCE = 8;
	const MAX_KEYWORDS_PER_TARGET = 5;

	/**
	 * Determine whether opportunity generation is enabled.
	 *
	 * @return bool
	 */
	private static function feature_enabled() {
		if ( ! class_exists( 'SRK_Internal_Linking_Settings' ) || ! method_exists( 'SRK_Internal_Linking_Settings', 'is_enabled' ) ) {
			return true;
		}

		return SRK_Internal_Linking_Settings::is_enabled();
	}

	public static function start_scan( $single_post_id = 0 ) {
		if ( ! self::feature_enabled() ) {
			return new WP_Error(
				'srk_internal_linking_disabled',
				__( 'Internal Linking is disabled. Enable it before generating opportunities.', 'seo-repair-kit' )
			);
		}

		$total = $single_post_id ? 1 : SRK_Internal_Linking_DB::count_indexed_content();

		$id = SRK_Internal_Linking_DB::insert_scan_run(
			array(
				'scan_type'   => $single_post_id ? 'single_opportunities' : 'opportunities',
				'status'      => 'running',
				'total_items' => $total,
				'message'     => __( 'Generating  opportunities.', 'seo-repair-kit' ),
			)
		);

		return array(
			'scan_id'     => $id,
			'total_items' => $total,
			'page'        => 1
		);
	}

	public static function run_batch( $scan_id, $page = 1, $single_post_id = 0 ) {
		if ( ! self::feature_enabled() ) {
			return array(
				'scan_id'         => absint( $scan_id ),
				'page'            => absint( $page ),
				'next_page'       => absint( $page ),
				'processed_items' => 0,
				'total_items'     => 0,
				'percent'         => 100,
				'progress'        => 100,
				'complete'        => true,
				'stopped'         => true,
			);
		}

		$page           = max( 1, absint( $page ) );
		$batch          = self::BATCH_SIZE;
		$single_post_id = absint( $single_post_id );

		/*
		* Opportunity generation is rule-based only.
		*
		* AI opportunities are generated exclusively through the
		* dedicated "Run AI Pipeline Now" action.
		*/
		$defer_until_ai = false;

		$rows = $single_post_id ? array( SRK_Internal_Linking_DB::get_content_index_by_post_id( $single_post_id ), )
			: SRK_Internal_Linking_DB::get_indexed_content_batch( $batch, ( $page - 1 ) * $batch );

		/*
		* IMPORTANT PERFORMANCE CHANGE:
		*
		* Load and prepare the full-site target pool only ONCE
		* for this batch instead of once per source post.
		*/
		$target_pool = self::prepare_target_pool();

		$done = 0;

		foreach ( array_filter( $rows ) as $row ) {
			self::generate_for_source_post(
				absint( $row['post_id'] ),
				array(
					'scan_run_id'   => absint( $scan_id ),
					'defer_until_ai' => $defer_until_ai,
					'target_pool'    => $target_pool,
				)
			);

			$done++;
		}

		$scan  = SRK_Internal_Linking_DB::get_scan_run( $scan_id );
		$total = absint( $scan['total_items'] ?? 0 );

		$processed = min( $total, ( ( $page - 1 ) * $batch ) + $done );

		$complete = (
			$done < $batch ||
			$processed >= $total ||
			$single_post_id
		);

		SRK_Internal_Linking_DB::update_scan_run(
			$scan_id,
			array(
				'status' => $complete
					? 'completed'
					: 'running',

				'processed_items' => $processed,
				'success_items'   => $processed,
				'current_batch'   => $page,

				'completed_at' => $complete
					? SRK_Internal_Linking_DB::get_now()
					: null,

				'message' => $complete
					? __(
						'Rule opportunities completed.',
						'seo-repair-kit'
					)
					: __(
						'Generating rule opportunities...',
						'seo-repair-kit'
					),
			)
		);

		$percent = $total > 0 ? min( 100, absint( floor( ( $processed / $total ) * 100 ) ) ) : 100;

		return array(
			'scan_id'         => absint( $scan_id ),
			'page'            => $page + 1,
			'next_page'       => $page + 1,
			'processed_items' => $processed,
			'total_items'     => $total,
			'percent'         => $percent,
			'progress'        => $percent,
			'complete'        => $complete,
			'awaiting_ai'     => false,
		);
	}

	/**
	 * Prepare reusable full-site target data.
	 *
	 * @return array
	 */
	private static function prepare_target_pool() {
		$settings = class_exists( 'SRK_Internal_Linking_Settings' ) ? SRK_Internal_Linking_Settings::get() : array();

		$keyword_sources = ! empty( $settings['keyword_sources'] )
			&& is_array( $settings['keyword_sources'] ) ? $settings['keyword_sources'] : array(
					'custom',
					'gsc',
					'ai',
					'title',
					'slug',
				);

		$keyword_sources = array_values( array_diff( array_map( 'sanitize_key', $keyword_sources ), array( 'taxonomy' ) ) );

		$target_keywords = SRK_Internal_Linking_DB::get_active_target_keywords_pool( $keyword_sources );

		return array(
			'keywords' => $target_keywords,

			'index' => self::build_target_word_index( $target_keywords ),

			'keywords_by_target' => self::group_keywords_by_target( $target_keywords ),
		);
	}

	/**
	 * Build meaningful lookup terms for orphan source pre-filtering.
	 *
	 * These terms are used only to shortlist likely source posts.
	 * Actual opportunity validation still happens through the standard
	 * sentence/anchor/scoring engine.
	 *
	 * @param array   $target_keywords Target keyword rows.
	 * @param WP_Post $target_post     Target post.
	 *
	 * @return string[]
	 */
	private static function get_orphan_prefilter_terms( $target_keywords, $target_post ) {
		$terms = array();

		foreach ( (array) $target_keywords as $keyword ) {
			foreach ( self::keyword_words( $keyword ) as $word ) {
				$word = trim( sanitize_text_field( $word ) );

				if ( '' !== $word ) {
					$terms[] = $word;
				}
			}
		}

		/*
		* Safety fallback for a legacy target that does not yet have
		* generated keyword rows.
		*/
		if ( empty( $terms ) && $target_post ) {
			$terms = SRK_Internal_Linking_Keywords::meaningful_words( $target_post->post_title );
		}

		$terms = array_values( array_unique( array_filter( $terms ) ) );

		/*
		* Prefer more specific/longer terms during the cheap pre-filter.
		*/
		usort(
			$terms,
			static function ( $a, $b ) {
				return strlen( $b )
					<=> strlen( $a );
			}
		);

		return array_slice( $terms, 0, 12 );
	}

	/**
	 * Prepare a target pool for one specific orphan target only.
	 *
	 * Unlike prepare_target_pool(), this method does not load keywords for the
	 * entire website. It is intentionally used only by Orphan Content discovery.
	 *
	 * @param int $target_post_id Target post ID.
	 *
	 * @return array
	 */
	private static function prepare_orphan_target_pool( $target_post_id ) {
		$target_post_id = absint( $target_post_id );

		if ( ! $target_post_id ) {
			return array(
				'keywords'           => array(),
				'index'              => array(),
				'keywords_by_target' => array(),
			);
		}

		$settings = class_exists( 'SRK_Internal_Linking_Settings' ) ? SRK_Internal_Linking_Settings::get() : array();

		$keyword_sources = ! empty( $settings['keyword_sources'] ) &&
			is_array( $settings['keyword_sources'] ) ? $settings['keyword_sources'] : array(
					'custom',
					'gsc',
					'ai',
					'title',
					'slug',
				);

		$keyword_sources = array_values( array_diff( array_map( 'sanitize_key', $keyword_sources ), array( 'taxonomy', ) ) );

		/*
		* Source post ID 0 means:
		* do not exclude any real WordPress post.
		*
		* $target_post_id restricts this query to one selected orphan target.
		*/
		$target_keywords = SRK_Internal_Linking_DB::get_active_target_keywords_except_post( 0, $keyword_sources, $target_post_id );

		return array(
			'keywords' => $target_keywords,

			'index' => self::build_target_word_index( $target_keywords ),

			'keywords_by_target' => self::group_keywords_by_target( $target_keywords ),
		);
	}

	public static function generate_for_editor_post( $post_id, $args = array() ) {
		if ( ! self::feature_enabled() ) {
			return array();
		}

		$post_id = absint( $post_id );
		$post    = get_post( $post_id );

		if ( ! $post ) {
			return array();
		}

		$is_elementor_source = (
			class_exists(
				'SRK_Internal_Linking_Elementor'
			) &&
			SRK_Internal_Linking_Elementor::
				is_elementor_post(
					$post_id
				)
		);

		if ( $is_elementor_source ) {

			/*
			* Elementor is the source of truth.
			*
			* Never fall back to WordPress post_content for an Elementor-built post.
			* Suggestions must only be generated from Elementor content that the
			* Elementor adapter can safely locate and write back.
			*/
			$post->post_content =
				(string) SRK_Internal_Linking_Elementor::get_analysis_content(
					$post_id,
					true
				);

		} elseif (
			isset(
				$args['content']
			)
		) {

			/*
			* Gutenberg continues using current editor content.
			*/
			$post->post_content =
				(string) $args['content'];
		}
		if ( ! empty( $args['title'] ) ) {
			$post->post_title = sanitize_text_field( $args['title'] );
		}

		/*
		* Keep keyword refresh so current title/keyword behavior remains
		* exactly compatible with the existing editor flow.
		*/
		SRK_Internal_Linking_Keywords::generate_for_post(
			$post_id,
			array(
				'title' => $post->post_title,
			)
		);

		/*
		* Prepare full-site targets once.
		*/
		$target_pool = self::prepare_target_pool();

		self::generate_for_source_post(
			$post_id,
			array(
				'content'           => $post->post_content,
				'title'             => $post->post_title,
				'defer_until_ai'    => false,
				'preserve_existing' => true,
				'target_pool'       => $target_pool,
			)
		);

		return SRK_Internal_Linking_DB::get_editor_grouped_opportunities( $post_id, 10 );
	}

	public static function generate_for_source_post( $source_post_id, $args = array() ) {
		if ( ! self::feature_enabled() ) {
			return array(
				'created'    => 0,
				'candidates' => 0,
				'disabled'   => true,
			);
		}

		$source_post_id = absint( $source_post_id );
		$post           = get_post( $source_post_id );

		if ( ! $post ) {
			return array( 'created' => 0 );
		}

		if (
			array_key_exists(
				'content',
				$args
			)
		) {

			/*
			* Explicit content always wins.
			* Gutenberg editor scans use this path.
			*/
			$content =
				wp_kses_post(
					(string) $args['content']
				);

		} elseif (
			class_exists(
				'SRK_Internal_Linking_Elementor'
			) &&
			SRK_Internal_Linking_Elementor::
				is_elementor_post(
					$source_post_id
				)
		) {

			/*
			* Background scans for Elementor sources use the actual
			* Elementor document.
			*/
			$content =
				SRK_Internal_Linking_Elementor::
					get_analysis_content(
						$source_post_id
					);

		} else {

			$content =
				$post->post_content;
		}

		$clean     = SRK_Internal_Linking_Indexer::clean_content( $content );
		$sentences = SRK_Internal_Linking_Indexer::split_sentences( $clean );

		if ( empty( $sentences ) ) {
			return array( 'created' => 0 );
		}

		$settings = class_exists( 'SRK_Internal_Linking_Settings' ) ? SRK_Internal_Linking_Settings::get() : array();

		$scan_run_id = absint( $args['scan_run_id'] ?? 0 );

		$defer_until_ai = array_key_exists( 'defer_until_ai', $args ) ? ! empty( $args['defer_until_ai'] )
			: self::should_defer_until_ai();

		$candidate_status = $defer_until_ai ? 'building' : 'pending';

		$preserve_existing = ! empty( $args['preserve_existing'] );

		$keyword_sources = ! empty( $settings['keyword_sources'] )
		&& is_array( $settings['keyword_sources'] ) ? $settings['keyword_sources'] : array(
				'custom',
				'gsc',
				'ai',
				'title',
				'slug',
			);

		$keyword_sources = array_values( array_diff( array_map( 'sanitize_key', $keyword_sources ), array( 'taxonomy' ) ) );

		$source_keywords = self::source_keyword_terms( $source_post_id );

		$target_pool = ! empty( $args['target_pool'] )
			&& is_array( $args['target_pool'] ) ? $args['target_pool'] : self::prepare_target_pool();

		$target_keywords = ! empty( $target_pool['keywords'] ) ? $target_pool['keywords'] : array();

		$index = ! empty( $target_pool['index'] ) ? $target_pool['index'] : array();

		$keywords_by_target = ! empty( $target_pool['keywords_by_target'] ) ? $target_pool['keywords_by_target'] : array();

		/*
		* One DB query instead of checking links repeatedly inside
		* the deepest candidate loop.
		*/
		$linked_targets = SRK_Internal_Linking_DB::get_linked_target_map( $source_post_id );

		$source_tax       = self::taxonomy_slugs( $source_post_id );
		$target_tax_cache = array();
		$candidates       = array();

		foreach ( $sentences as $sentence ) {

			if ( strlen( $sentence ) < 30 ) {
				continue;
			}

			$words = SRK_Internal_Linking_Keywords::meaningful_words( $sentence );

			if ( count( $words ) < 2 ) {
				continue;
			}

			$target_ids = self::candidate_targets_from_words( $words, $index );

			if ( empty( $target_ids ) ) {
				continue;
			}

			foreach (
				array_slice( $target_ids, 0, self::MAX_CANDIDATE_TARGETS_PER_SENTENCE, true ) as $target_post_id => $hit_words
			) {
				if ( absint( $target_post_id ) === $source_post_id ) {
					continue;
				}

				$target_keyword_rows = isset( $keywords_by_target[ $target_post_id ] ) ? $keywords_by_target[ $target_post_id ] : array();

				foreach ( $target_keyword_rows as $kw ) {
					$match = self::extract_anchor( $sentence, $kw, $source_keywords );

					if ( empty( $match ) ) {
						continue;
					}

					if ( ! self::anchor_is_insertable_in_content( $content, $match['anchor'] ) ) {
						continue;
					}

					if ( ! isset( $target_tax_cache[ $target_post_id ] ) ) {
						$target_tax_cache[ $target_post_id ] = self::taxonomy_slugs_from_json( $kw['taxonomy_json'] ?? '[]' );
					}

					$target_tax = $target_tax_cache[ $target_post_id ];

					$shared_tax = ! empty( array_intersect( $source_tax, $target_tax ) );

					$relationship = self::relationship_score( $words, $kw, $shared_tax );

					$already = isset( $linked_targets[ $target_post_id ] )
						|| self::content_has_target_url( $content, $kw['target_url'] );
					$score_args = array(
						'anchor_text'         => $match['anchor'],
						'sentence'            => $sentence,
						'keyword'             => $kw['keyword'],
						'keyword_source'      => $kw['source'],
						'match_type'          => $match['type'],
						'matched_terms'       => $match['matched_terms'],
						'matched_specific'    => isset( $match['matched_specific'] ) ? $match['matched_specific'] : array(),
						'relationship_score'  => $relationship,
						'shared_taxonomy'    => $shared_tax,
						'target_inbound_count'=> absint( $kw['internal_inbound_count'] ),
						'already_linked'     => $already
					);

					$score = SRK_Internal_Linking_Scoring::calculate( $score_args );

					if ( $score < SRK_Internal_Linking_Scoring::MIN_SCORE ) {
						continue;
					}

					$key = md5( $source_post_id . '|' . $target_post_id . '|' . strtolower( $match['anchor'] ) . '|' . strtolower( $sentence ) );

					if ( ! isset( $candidates[ $key ] ) || $score > $candidates[ $key ]['score'] ) {
						$candidates[ $key ] = array(
							'scan_run_id'    => $scan_run_id ?: null,
							'source_post_id' => $source_post_id,
							'target_post_id' => absint( $target_post_id ),
							'anchor_text'    => $match['anchor'],

							'sentence' => self::context_excerpt( $sentence, $match['anchor'] ),

							'reason' => SRK_Internal_Linking_Scoring::reason( $score_args ),

							'score'         => $score,
							'final_score'   => $score,
							'selected_type' => 'rule',
							'rule_score'    => $score,
							'ai_score'      => null,
							'ai_similarity' => null,
							'status'        => $candidate_status,
						);
					}
				}
			}
		}

		$candidates = self::dedupe_and_sort( $candidates );

		if ( empty( $candidates ) ) {

			/*
			* Editor scans are intentionally non-destructive because they may be
			* running against temporary unsaved Gutenberg content.
			*
			* Full/background scans, however, represent the saved source of truth.
			* If they find no valid candidates, stale pending opportunities must
			* be removed instead of remaining visible forever.
			*/
			if ( ! $preserve_existing ) {
				SRK_Internal_Linking_DB::delete_pending_opportunities_by_source(
					$source_post_id
				);
			}

			return array(
				'created'            => 0,
				'candidates'         => 0,
				'preserved_existing' => $preserve_existing,
			);
		}

		/*
		* Full dashboard scans replace active suggestions.
		*
		* Gutenberg draft scans are non-destructive because existing canonical
		* opportunities may contain AI evidence generated by the background pipeline.
		*/
		if ( ! $preserve_existing ) {
			SRK_Internal_Linking_DB::delete_pending_opportunities_by_source( $source_post_id );
		}

		$created = 0;

		$limit = ! empty( $settings['suggestions_limit'] ) ? absint( $settings['suggestions_limit'] ) : self::MAX_PER_SOURCE;

		foreach ( array_slice( $candidates, 0, $limit ) as $candidate ) {
			if ( SRK_Internal_Linking_DB::upsert_canonical_opportunity( $candidate ) ) {
				$created++;
			}
		}

		return array(
			'created'    => $created,
			'candidates' => count( $candidates )
		);
	}

	/**
	 * Build word => target post lookup index.
	 *
	 * @param array $keywords Target keyword rows.
	 * @return array
	 */
	private static function build_target_word_index( $keywords ) {
		$index = array();

		foreach ( (array) $keywords as $keyword ) {
			$post_id = absint( $keyword['post_id'] ?? 0 );

			if ( ! $post_id ) {
				continue;
			}

			if ( 'taxonomy' === sanitize_key( $keyword['source'] ?? '' ) ) {
				continue;
			}

			foreach ( self::keyword_words( $keyword ) as $word ) {
				$index[ $word ][ $post_id ] = 1;
			}
		}

		return $index;
	}

	/**
	 * Get precomputed meaningful keyword words.
	 *
	 * Falls back to runtime calculation for legacy rows.
	 *
	 * @param array $keyword Keyword row.
	 * @return array
	 */
	private static function keyword_words( $keyword ) {
		$stored = json_decode( $keyword['meaningful_words_json'] ?? '[]', true );

		if ( is_array( $stored ) && ! empty( $stored ) ) {
			return array_values( array_unique( array_filter( array_map( 'sanitize_text_field', $stored ) ) ) );
		}

		return SRK_Internal_Linking_Keywords::meaningful_words( $keyword['keyword'] ?? '' );
	}

	/**
	 * Determine whether rule candidates must wait for AI merging.
	 *
	 * @return bool
	 */
	private static function should_defer_until_ai() {
		return (
			class_exists( 'SRK_Internal_Linking_AI_Engine' ) && SRK_Internal_Linking_AI_Engine::is_enabled() &&
			SRK_Internal_Linking_AI_Engine::semantic_matching_enabled()
		);
	}

	/**
	 * Group and pre-sort target keywords once per source scan.
	 *
	 * @param array $keywords Target keyword rows.
	 * @return array<int,array>
	 */
	private static function group_keywords_by_target( $keywords ) {
		$grouped = array();

		foreach ( (array) $keywords as $keyword ) {
			$post_id = absint( $keyword['post_id'] ?? 0 );

			if ( ! $post_id ) {
				continue;
			}

			if ( 'taxonomy' === sanitize_key( $keyword['source'] ?? '' ) ) {
				continue;
			}

			$grouped[ $post_id ][] = $keyword;
		}

		foreach ( $grouped as $post_id => $rows ) {
			usort(
				$rows,
				static function ( $a, $b ) {
					$score_compare = absint( $b['quality_score'] ?? 0 )
						<=> absint( $a['quality_score'] ?? 0 );

					if ( 0 !== $score_compare ) {
						return $score_compare;
					}

					return strlen( $b['keyword'] ?? '' )
						<=> strlen( $a['keyword'] ?? '' );
				}
			);

			$grouped[ $post_id ] = array_slice( $rows, 0, self::MAX_KEYWORDS_PER_TARGET );
		}

		return $grouped;
	}

	private static function candidate_targets_from_words( $words, $index ) {
		$scores = array();

		foreach ( $words as $word ) {
			if ( empty( $index[ $word ] ) ) {
				continue;
			}

			foreach ( $index[ $word ] as $post_id => $_ ) {
				$scores[ $post_id ][ $word ] = 1;
			}
		}

		foreach ( $scores as $post_id => $hit_words ) {
			// Do not even open a target unless the sentence shares at least two keyword words.
			if ( count( $hit_words ) < 2 ) {
				unset( $scores[ $post_id ] );
			}
		}

		uasort(
			$scores,
			static function ( $a, $b ) {
				return count( $b ) <=> count( $a );
			}
		);

		return $scores;
	}

	private static function unique_words( $words ) {
		return array_values( array_unique( array_filter( (array) $words ) ) );
	}

	/**
	 * Extract the best anchor for a target keyword from a source sentence.
	 *
	 * The anchor must come from target-keyword terms and must also pass a
	 * target-identity relevance check. This prevents weak anchors such as
	 * "improve performance" from linking to unrelated targets like
	 * "Content Strategy for SEO Growth" just because both were present in
	 * a broad candidate set.
	 *
	 * @param string $sentence        Source sentence being scanned.
	 * @param array  $keyword_row     Target keyword row with target metadata.
	 * @param array  $source_keywords Source post keyword phrases.
	 * @return array Matched anchor payload or empty array.
	 */
	private static function extract_anchor( $sentence, $keyword_row, $source_keywords ) {
		$keyword = SRK_Internal_Linking_Keywords::clean_phrase( $keyword_row['keyword'] ?? '' );
		$source  = sanitize_key( $keyword_row['source'] ?? '' );

		if ( '' === $keyword || 'taxonomy' === $source || SRK_Internal_Linking_Keywords::is_generic_phrase( $keyword ) ) {
			return array();
		}

		$sentence_words = self::unique_words( SRK_Internal_Linking_Keywords::meaningful_words( $sentence ) );
		$keyword_words  = self::unique_words( SRK_Internal_Linking_Keywords::meaningful_words( $keyword ) );

		if ( count( $keyword_words ) < 2 ) {
			return array();
		}

		$shared         = array_values( array_intersect( $sentence_words, $keyword_words ) );
		$specific_words = $keyword_words;

		// The source sentence must share at least two actual target-keyword terms.
		if ( count( $shared ) < 2 ) {
			return array();
		}

		$normalized_sentence = SRK_Internal_Linking_DB::normalize_keyword_text( $sentence );
		$normalized_keyword  = SRK_Internal_Linking_DB::normalize_keyword_text( $keyword );

		if ( $normalized_keyword && false !== strpos( $normalized_sentence, $normalized_keyword ) ) {
			$anchor = self::find_original_phrase( $sentence, $keyword );

			if ( $anchor && self::valid_anchor( $anchor, $source_keywords, $keyword ) && self::anchor_matches_target_identity( $anchor, $keyword_row, 'exact_keyword' ) ) {
				$anchor_words = SRK_Internal_Linking_Keywords::meaningful_words( $anchor );

				return array(
					'anchor'           => $anchor,
					'type'             => 'exact_keyword',
					'matched_terms'    => array_values( array_intersect( $anchor_words, $keyword_words ) ),
					'matched_specific' => array_values( array_intersect( $anchor_words, $specific_words ) ),
				);
			}
		}

		$tokens    = self::sentence_tokens_with_positions( $sentence );
		$positions = array();

		foreach ( $tokens as $index => $token ) {
			if ( in_array( $token['norm'], $keyword_words, true ) ) {
				$positions[] = $index;
			}
		}

		if ( count( $positions ) < 2 ) {
			return array();
		}

		$consecutive = self::best_consecutive_keyword_anchor( $tokens, $keyword_words, $specific_words );

		if ( ! empty( $consecutive ) && self::valid_anchor( $consecutive['anchor'], $source_keywords, $keyword ) && self::anchor_matches_target_identity( $consecutive['anchor'], $keyword_row, $consecutive['type'] ) ) {
			return $consecutive;
		}

		$window = self::best_keyword_window_anchor( $tokens, $positions, $keyword_words, $specific_words );

		if ( ! empty( $window ) && self::valid_anchor( $window['anchor'], $source_keywords, $keyword ) && self::anchor_matches_target_identity( $window['anchor'], $keyword_row, $window['type'] ) ) {
			return $window;
		}

		return array();
	}

	/**
	 * Confirm that an extracted anchor is relevant to the actual target page.
	 *
	 * Settings-driven stopwords alone are not enough to separate generic phrases
	 * from real topical anchors. This method compares the proposed anchor with
	 * the target page identity: title, slug, and URL path. Title/slug generated
	 * keywords must overlap the target identity, while manual/GSC keywords are
	 * treated as trusted user/search intent and are allowed with a stricter
	 * target-keyword overlap.
	 *
	 * @param string $anchor      Proposed anchor text.
	 * @param array  $keyword_row Target keyword row.
	 * @param string $match_type  Match type returned by the extractor.
	 * @return bool
	 */
	private static function anchor_matches_target_identity( $anchor, $keyword_row, $match_type = '' ) {
		$anchor_words  = self::unique_words( SRK_Internal_Linking_Keywords::meaningful_words( $anchor ) );
		$keyword_words = self::unique_words( SRK_Internal_Linking_Keywords::meaningful_words( $keyword_row['keyword'] ?? '' ) );
		$source        = sanitize_key( $keyword_row['source'] ?? '' );

		if ( count( $anchor_words ) < 2 || count( array_intersect( $anchor_words, $keyword_words ) ) < 2 ) {
			return false;
		}

		// Manual and GSC keywords are intentional target keywords, so do not force
		// them to appear in the page title. They still need a real keyword overlap.
		if ( in_array( $source, array( 'custom', 'gsc' ), true ) ) {
			return true;
		}

		$identity_words = self::target_identity_words( $keyword_row );

		if ( empty( $identity_words ) ) {
			return false;
		}

		$identity_overlap = array_intersect( $anchor_words, $identity_words );

		if ( count( $identity_overlap ) >= 2 ) {
			return true;
		}

		$anchor_norm   = SRK_Internal_Linking_DB::normalize_keyword_text( $anchor );
		$identity_norm = SRK_Internal_Linking_DB::normalize_keyword_text( self::target_identity_text( $keyword_row ) );

		return '' !== $anchor_norm && '' !== $identity_norm && false !== strpos( $identity_norm, $anchor_norm );
	}

	/**
	 * Build target identity text from target title, slug, and URL path.
	 *
	 * @param array $keyword_row Target keyword row.
	 * @return string
	 */
	private static function target_identity_text( $keyword_row ) {
		$parts = array();

		foreach ( array( 'post_title', 'target_title', 'title' ) as $key ) {
			if ( ! empty( $keyword_row[ $key ] ) ) {
				$parts[] = $keyword_row[ $key ];
			}
		}

		foreach ( array( 'post_slug', 'target_slug', 'slug' ) as $key ) {
			if ( ! empty( $keyword_row[ $key ] ) ) {
				$parts[] = str_replace( array( '-', '_' ), ' ', $keyword_row[ $key ] );
			}
		}

		if ( ! empty( $keyword_row['target_url'] ) ) {
			$path = wp_parse_url( $keyword_row['target_url'], PHP_URL_PATH );

			if ( $path ) {
				$parts[] = str_replace( array( '/', '-', '_' ), ' ', $path );
			}
		}

		return trim( implode( ' ', array_filter( $parts ) ) );
	}

	/**
	 * Get meaningful identity words for the target page.
	 *
	 * @param array $keyword_row Target keyword row.
	 * @return array
	 */
	private static function target_identity_words( $keyword_row ) {
		return SRK_Internal_Linking_Keywords::meaningful_words( self::target_identity_text( $keyword_row ) );
	}

	private static function best_consecutive_keyword_anchor( $tokens, $keyword_words, $specific_words ) {
		$count = count( $tokens );
		$best  = array();

		for ( $start = 0; $start < $count; $start++ ) {
			for ( $end = min( $count - 1, $start + 5 ); $end > $start; $end-- ) {
				$anchor = self::original_from_tokens( $tokens, $start, $end );
				$words  = self::unique_words( SRK_Internal_Linking_Keywords::meaningful_words( $anchor ) );
				$match  = array_values( array_intersect( $words, $keyword_words ) );
				$spec   = array_values( array_intersect( $words, self::unique_words( $specific_words ) ) );

				if ( count( $match ) >= 2 && ! empty( $spec ) && count( $match ) === count( $words ) ) {
					$best = array(
						'anchor'           => $anchor,
						'type'             => 'consecutive_keyword_terms',
						'matched_terms'    => $match,
						'matched_specific' => $spec,
					);
				}
			}
		}

		return $best;
	}

	private static function best_keyword_window_anchor( $tokens, $positions, $keyword_words, $specific_words ) {
		$best      = array();
		$best_span = 99;

		foreach ( $positions as $start ) {
			foreach ( $positions as $end ) {
				if ( $end <= $start ) {
					continue;
				}

				$span = $end - $start + 1;

				if ( $span > 8 || $span >= $best_span ) {
					continue;
				}

				$anchor = self::trim_anchor( self::original_from_tokens( $tokens, $start, $end ), $keyword_words );
				$words  = self::unique_words( SRK_Internal_Linking_Keywords::meaningful_words( $anchor ) );
				$match  = array_values( array_intersect( $words, $keyword_words ) );
				$spec   = array_values( array_intersect( $words, self::unique_words( $specific_words ) ) );

				if ( count( $match ) >= 2 && ! empty( $spec ) ) {
					$best_span = $span;
					$best      = array(
						'anchor'           => $anchor,
						'type'             => 'keyword_window',
						'matched_terms'    => $match,
						'matched_specific' => $spec,
					);
				}
			}
		}

		return $best;
	}

	private static function find_original_phrase( $sentence, $keyword ) {
		$pattern = '/' . preg_quote( $keyword, '/' ) . '/iu';

		if ( preg_match( $pattern, $sentence, $matches ) ) {
			return trim( $matches[0] );
		}

		$words = preg_split( '/\s+/u', $keyword, -1, PREG_SPLIT_NO_EMPTY );
		$parts = array();

		foreach ( $words as $word ) {
			$parts[] = preg_quote( $word, '/' );
		}

		$pattern = '/' . implode( '[\s\-]+', $parts ) . '/iu';

		return preg_match( $pattern, $sentence, $matches ) ? trim( $matches[0] ) : '';
	}

	private static function sentence_tokens_with_positions( $sentence ) {
		$output = array();

		if ( preg_match_all( '/[\p{L}\p{N}\'’\-]+/u', $sentence, $matches, PREG_OFFSET_CAPTURE ) ) {
			foreach ( $matches[0] as $item ) {
				$text     = $item[0];
				$output[] = array(
					'text'   => $text,
					'norm'   => SRK_Internal_Linking_DB::normalize_keyword_text( $text ),
					'offset' => absint( $item[1] ),
					'length' => strlen( $text ),
				);
			}
		}

		return $output;
	}

	private static function original_from_tokens( $tokens, $start, $end ) {
		$parts = array();

		for ( $i = $start; $i <= $end; $i++ ) {
			$parts[] = $tokens[ $i ]['text'];
		}

		return trim( implode( ' ', $parts ) );
	}

	private static function trim_anchor( $anchor, $keyword_words ) {
		$words = preg_split( '/\s+/u', trim( $anchor ), -1, PREG_SPLIT_NO_EMPTY );

		while ( count( $words ) > 2 && ! in_array( SRK_Internal_Linking_DB::normalize_keyword_text( $words[0] ), $keyword_words, true ) ) {
			array_shift( $words );
		}

		while ( count( $words ) > 2 ) {
			$last = end( $words );

			if ( in_array( SRK_Internal_Linking_DB::normalize_keyword_text( $last ), $keyword_words, true ) ) {
				break;
			}

			array_pop( $words );
		}

		return implode( ' ', $words );
	}

	private static function valid_anchor( $anchor, $source_keywords, $target_keyword = '' ) {
		$anchor = trim( wp_strip_all_tags( $anchor ) );

		if ( '' === $anchor || strlen( $anchor ) < 6 ) {
			return false;
		}

		if ( SRK_Internal_Linking_Keywords::is_generic_phrase( $anchor ) ) {
			return false;
		}

		$meaningful = self::unique_words( SRK_Internal_Linking_Keywords::meaningful_words( $anchor ) );
		$specific   = ! empty( $target_keyword ) ? array_values( array_intersect( $meaningful, SRK_Internal_Linking_Keywords::specific_words( $target_keyword ) ) ) : SRK_Internal_Linking_Keywords::specific_words( $anchor );
		$word_count = count( preg_split( '/\s+/u', $anchor, -1, PREG_SPLIT_NO_EMPTY ) );
		$settings = class_exists( 'SRK_Internal_Linking_Settings' ) ? SRK_Internal_Linking_Settings::get() : array();
		$min_words =
			! empty( $settings['min_anchor_words'] )
				? min(
					9,
					max(
						2,
						absint(
							$settings['min_anchor_words']
						)
					)
				)
				: 2;

		$max_words =
			! empty( $settings['max_anchor_words'] )
				? min(
					9,
					max(
						$min_words,
						absint(
							$settings['max_anchor_words']
						)
					)
				)
				: 9;

		if ( count( $meaningful ) < $min_words || empty( $specific ) || $word_count < $min_words || $word_count > $max_words ) {
			return false;
		}

		foreach ( $source_keywords as $source_keyword ) {
			$source_norm = SRK_Internal_Linking_DB::normalize_keyword_text( $source_keyword );
			$anchor_norm = SRK_Internal_Linking_DB::normalize_keyword_text( $anchor );

			if ( $source_norm && $source_norm === $anchor_norm ) {
				return false;
			}
		}

		return true;
	}

	private static function relationship_score( $sentence_words, $keyword_row, $shared_taxonomy ) {
		$sentence_words = self::unique_words( $sentence_words );
		$keyword_words  = self::unique_words( SRK_Internal_Linking_Keywords::meaningful_words( $keyword_row['keyword'] ) );
		$specific       = self::unique_words( SRK_Internal_Linking_Keywords::specific_words( $keyword_row['keyword'] ) );
		$common         = array_values( array_intersect( $sentence_words, $keyword_words ) );
		$common_spec    = array_values( array_intersect( $sentence_words, $specific ) );

		if ( count( $common ) < 2 || empty( $common_spec ) ) {
			return 0;
		}

		$score = count( $common ) * 16;
		$score += count( $common_spec ) * 18;

		if ( count( $common ) >= min( 4, count( $keyword_words ) ) ) {
			$score += 15;
		}

		if ( in_array( sanitize_key( $keyword_row['source'] ?? '' ), array( 'custom' ), true ) ) {
			$score += 15;
		}

		if ( $shared_taxonomy ) {
			$score += 5;
		}

		return min( 100, absint( $score ) );
	}

	private static function dedupe_and_sort( $candidates ) {
		$items = array_values( (array) $candidates );

		usort(
			$items,
			static function ( $a, $b ) {
				if ( absint( $b['score'] ) === absint( $a['score'] ) ) {
					return strlen( $a['anchor_text'] ) <=> strlen( $b['anchor_text'] );
				}

				return absint( $b['score'] ) <=> absint( $a['score'] );
			}
		);

		$used_source_target = array();
		$used_source_anchor = array();
		$used_source_sentence = array();
		$output = array();

		foreach ( $items as $item ) {
			$source_id  = absint( $item['source_post_id'] );
			$target_id  = absint( $item['target_post_id'] );
			$anchor     = strtolower( SRK_Internal_Linking_DB::normalize_keyword_text( $item['anchor_text'] ) );
			$sentence   = strtolower( SRK_Internal_Linking_DB::normalize_keyword_text( $item['sentence'] ) );

			$source_target_key  = $source_id . ':' . $target_id;
			$source_anchor_key  = $source_id . ':' . md5( $anchor );
			$source_sentence_key = $source_id . ':' . md5( $sentence );

			// Main fix: same target post should appear only once for one source post.
			if ( isset( $used_source_target[ $source_target_key ] ) ) {
				continue;
			}

			$used_source_target[ $source_target_key ] = 1;
			$used_source_anchor[ $source_anchor_key ] = 1;
			$used_source_sentence[ $source_sentence_key ] = 1;

			$output[] = $item;
		}

		return $output;
	}

	private static function source_keyword_terms( $post_id ) {
		$output = array();

		foreach ( SRK_Internal_Linking_DB::get_keywords_by_post( $post_id ) as $keyword ) {
			$output[] = $keyword['keyword'];
		}

		return $output;
	}

	private static function taxonomy_slugs( $post_id ) {
		$output = array();

		foreach ( get_object_taxonomies( get_post_type( $post_id ), 'names' ) as $taxonomy ) {
			$terms = get_the_terms( $post_id, $taxonomy );

			if ( is_wp_error( $terms ) || empty( $terms ) ) {
				continue;
			}

			foreach ( $terms as $term ) {
				$output[] = $taxonomy . ':' . $term->slug;
			}
		}

		return $output;
	}

	private static function taxonomy_slugs_from_json( $json ) {
		$data   = json_decode( (string) $json, true );
		$output = array();

		if ( is_array( $data ) ) {
			foreach ( $data as $row ) {
				if ( isset( $row['taxonomy'], $row['slug'] ) ) {
					$output[] = $row['taxonomy'] . ':' . $row['slug'];
				}
			}
		}

		return $output;
	}

	private static function content_has_target_url( $content, $url ) {
		return $url && false !== strpos( (string) $content, (string) $url );
	}

	/**
	 * Build a compact context preview around the selected anchor.
	 *
	 * The editor and dashboard should show useful nearby content, not the whole
	 * paragraph. This mirrors the Link Whisper UX where the anchor is shown inside
	 * a short sentence-level preview.
	 *
	 * @param string $sentence Full sentence or cleaned paragraph fragment.
	 * @param string $anchor   Selected anchor text.
	 * @return string
	 */
	private static function context_excerpt( $sentence, $anchor ) {
		$sentence = trim( preg_replace( '/\s+/u', ' ', wp_strip_all_tags( (string) $sentence ) ) );
		$anchor   = trim( wp_strip_all_tags( (string) $anchor ) );

		if ( '' === $sentence || '' === $anchor ) {
			return wp_html_excerpt( $sentence, 180, '…' );
		}

		$pos = stripos( $sentence, $anchor );

		if ( false === $pos ) {
			return wp_html_excerpt( $sentence, 180, '…' );
		}

		$start   = max( 0, $pos - 90 );
		$length  = strlen( $anchor ) + 180;
		$excerpt = substr( $sentence, $start, $length );

		if ( $start > 0 ) {
			$space = strpos( $excerpt, ' ' );
			if ( false !== $space ) {
				$excerpt = substr( $excerpt, $space + 1 );
			}
			$excerpt = '…' . $excerpt;
		}

		if ( strlen( $sentence ) > $start + $length ) {
			$last_space = strrpos( $excerpt, ' ' );
			if ( false !== $last_space ) {
				$excerpt = substr( $excerpt, 0, $last_space );
			}
			$excerpt .= '…';
		}

		return trim( $excerpt );
	}

	/**
	 * Generate inbound opportunities for one orphan target.
	 *
	 * This path is intentionally target-specific:
	 *
	 * 1. Refresh keywords for the selected target only.
	 * 2. Build a target pool containing only that target.
	 * 3. Cheaply shortlist likely sources from indexed plain content.
	 * 4. Run the existing full scoring engine only on those sources.
	 *
	 * Normal Link Opportunities generation is not affected.
	 *
	 * @param int $target_post_id Target orphan post ID.
	 *
	 * @return array|WP_Error
	 */
	public static function generate_for_target_post( $target_post_id ) {
		if ( ! self::feature_enabled() ) {
			return new WP_Error(
				'srk_internal_linking_disabled',
				__( 'Internal Linking is disabled. Enable it before finding orphan opportunities.', 'seo-repair-kit' )
			);
		}

		$target_post_id = absint( $target_post_id );

		if ( ! $target_post_id ) {
			return new WP_Error( 'srk_il_invalid_orphan_target', __( 'Invalid orphan target post.', 'seo-repair-kit' ) );
		}

		$target_post = get_post( $target_post_id );

		if ( ! $target_post || 'publish' !== $target_post->post_status ) {
			return new WP_Error(
				'srk_il_orphan_target_unavailable',
				__( 'The selected orphan target is not available for opportunity discovery.', 'seo-repair-kit' )
			);
		}

		/*
		* Ensure only this target's automatic keyword data is current.
		*
		* This is a very small operation compared with regenerating
		* opportunities across the complete website.
		*/
		if ( class_exists( 'SRK_Internal_Linking_Keywords' ) ) {
			SRK_Internal_Linking_Keywords::generate_for_post( $target_post_id );
		}

		/*
		* IMPORTANT:
		*
		* This contains only ONE target post.
		*
		* Do not call prepare_target_pool() here because that loads
		* the complete website target pool.
		*/
		$target_pool = self::prepare_orphan_target_pool( $target_post_id );

		$target_keywords = ! empty( $target_pool['keywords'] ) && is_array( $target_pool['keywords'] ) ? $target_pool['keywords']
				: array();

		if ( empty( $target_keywords ) ) {
			return array(
				'created'          => 0,
				'candidates'       => 0,
				'sources_scanned'  => 0,
				'sources_matched'  => 0,
				'target_post_id'   => $target_post_id,
			);
		}

		/*
		* Build cheap lexical terms for source pre-filtering.
		*/
		$prefilter_terms = self::get_orphan_prefilter_terms( $target_keywords, $target_post );

		if ( empty( $prefilter_terms ) ) {
			return array(
				'created'          => 0,
				'candidates'       => 0,
				'sources_scanned'  => 0,
				'sources_matched'  => 0,
				'target_post_id'   => $target_post_id,
			);
		}

		/*
		* Shortlist likely source posts using the existing content index.
		*
		* Instead of:
		*     2000 source posts × all site targets
		*
		* We now do:
		*     relevant source posts × this ONE orphan target
		*/
		$source_ids = SRK_Internal_Linking_DB::get_orphan_candidate_source_ids( $target_post_id, $prefilter_terms, 500 );

		if ( empty( $source_ids ) ) {
			return array(
				'created'          => 0,
				'candidates'       => 0,
				'sources_scanned'  => 0,
				'sources_matched'  => 0,
				'target_post_id'   => $target_post_id,
			);
		}

		$created         = 0;
		$candidates      = 0;
		$sources_scanned = 0;
		$sources_matched = 0;

		foreach ( $source_ids as $source_post_id ) {

			$source_post_id = absint( $source_post_id );

			if ( ! $source_post_id || $source_post_id === $target_post_id ) {
				continue;
			}

			/*
			* Reuse the EXISTING scoring and opportunity engine.
			*
			* Important safeguards:
			*
			* preserve_existing = true
			*     Do not delete or replace other opportunities belonging
			*     to this source post.
			*
			* defer_until_ai = false
			*     Orphan discovery is interactive and should immediately
			*     return rule-based opportunities instead of waiting for
			*     the background AI pipeline.
			*
			* target_pool
			*     Contains ONLY the selected orphan target.
			*/
			$result = self::generate_for_source_post(
					$source_post_id,
					array(
						'preserve_existing' => true,

						'defer_until_ai' => false,

						'target_pool' => $target_pool,
					)
				);

			$sources_scanned++;

			$result_created = absint( $result['created'] ?? 0 );

			$result_candidates = absint( $result['candidates'] ?? 0 );

			$created += $result_created;

			$candidates += $result_candidates;

			if ( $result_candidates > 0 || $result_created > 0 ) {
				$sources_matched++;
			}
		}

		return array(
			'created'         => $created,
			'candidates'      => $candidates,
			'sources_scanned' => $sources_scanned,
			'sources_matched' => $sources_matched,
			'target_post_id'  => $target_post_id,
		);
	}

	/**
	 * Apply a canonical opportunity to current unsaved Gutenberg content.
	 *
	 * The opportunity remains pending until the post is saved and the inserted
	 * anchor link is verified in stored post content.
	 *
	 * @param int    $opportunity_id Opportunity row ID.
	 * @param int    $source_post_id Source post ID.
	 * @param string $anchor_override Optional user-edited anchor.
	 * @param string $content_override Current editor content.
	 * @return array|WP_Error
	 */
	public static function apply_to_editor_content(	$opportunity_id, $source_post_id, $anchor_override = '', $content_override = '' ) {
		$opportunity_id = absint( $opportunity_id );
		$source_post_id = absint( $source_post_id );

		$opportunity = SRK_Internal_Linking_DB::get_opportunity_by_id( $opportunity_id );

		if ( ! $opportunity ) {
			return new WP_Error( 'srk_missing_opportunity', __( 'Opportunity not found.', 'seo-repair-kit' ) );
		}

		if ( absint( $opportunity['source_post_id'] ) !== $source_post_id ) {
			return new WP_Error( 'srk_invalid_source', __( 'This opportunity does not belong to the current post.', 'seo-repair-kit' ) );
		}

		if ( 'pending' !== sanitize_key( $opportunity['status'] ) ) {
			return new WP_Error( 'srk_opportunity_unavailable', __( 'This opportunity is no longer pending.', 'seo-repair-kit' ) );
		}

		$post = get_post( $source_post_id );

		if ( ! $post ) {
			return new WP_Error( 'srk_missing_source', __( 'Source post not found.', 'seo-repair-kit' ) );
		}

		/*
		* Elementor-built posts store their real editable content outside
		* normal WordPress post_content.
		*
		* Apply the link immediately through the Elementor adapter.
		* The adapter saves the real Elementor document, verifies the exact
		* anchor + target URL, updates the opportunity status, and synchronizes
		* the Internal Linking index.
		*/
		if (
			class_exists( 'SRK_Internal_Linking_Elementor' ) &&
			SRK_Internal_Linking_Elementor::is_elementor_post( $source_post_id )
		) {
			return SRK_Internal_Linking_Elementor::apply_opportunity(
				$opportunity_id,
				$source_post_id,
				$anchor_override,
				false,
				true
			);
		}

		/*
		* The AJAX controller already unslashes the content.
		*
		* Running wp_unslash() again can corrupt backslashes inside serialized block
		* attributes. Running wp_kses_post() here can also modify valid Gutenberg
		* block markup before it is returned to the editor.
		*/
		$content = '' !== (string) $content_override ? (string) $content_override : $post->post_content;

		/*
		* The AJAX controller already unslashes the anchor.
		*/
		$anchor = '' !== trim( (string) $anchor_override ) ? sanitize_text_field( $anchor_override )
			: sanitize_text_field( $opportunity['anchor_text'] );
		$target_post_id = absint( $opportunity['target_post_id'] );

		$target = get_post( $target_post_id );

		if ( ! $target || 'publish' !== $target->post_status || $target_post_id === $source_post_id ) {
			return new WP_Error( 'srk_invalid_target', __( 'The target post is unavailable.', 'seo-repair-kit' ) );
		}

		$target_url = get_permalink( $target_post_id );

		if ( ! $target_url ) {
			return new WP_Error( 'srk_missing_target_url', __( 'The target URL could not be resolved.', 'seo-repair-kit' ) );
		}

		if ( self::content_has_target_url( $content, $target_url ) ) {
			return new WP_Error( 'srk_target_already_linked', __( 'The current editor content already links to this target.', 'seo-repair-kit' ) );
		}

		$new_content = self::insert_link_into_content( $content, $anchor, $target_url );

		if ( $new_content === $content ) {
			return new WP_Error(
				'srk_anchor_missing',
				__( 'Anchor text was not found in the current editor content. Update the anchor or regenerate suggestions.', 'seo-repair-kit' )
			);
		}

		self::queue_editor_link_confirmation( $source_post_id, $opportunity_id, $anchor, $target_url );

		return array(
			'message' => __( 'Link added to the editor. Save or update the post to confirm it.', 'seo-repair-kit' ),
			'content'        => $new_content,
			'post_content'   => $new_content,
			'opportunity_id' => $opportunity_id,
			'pending_save'   => true,
		);
	}

	/**
	 * Store an editor-applied opportunity until post save verification.
	 *
	 * @param int    $post_id        Source post ID.
	 * @param int    $opportunity_id Opportunity ID.
	 * @param string $anchor         Applied anchor.
	 * @param string $target_url     Target URL.
	 * @param string $editor_type    Editor type: gutenberg or elementor.
	 * @return void
	 */
	private static function queue_editor_link_confirmation( $post_id, $opportunity_id, $anchor, $target_url, $editor_type = 'gutenberg' ) {
		$post_id        = absint( $post_id );
		$opportunity_id = absint( $opportunity_id );

		if ( ! $post_id || ! $opportunity_id ) {
			return;
		}

		$editor_type = sanitize_key( $editor_type );

		if ( ! in_array( $editor_type, array( 'gutenberg', 'elementor' ), true ) ) {
			$editor_type = 'gutenberg';
		}

		$pending = get_post_meta(
			$post_id,
			'_srk_il_pending_editor_links',
			true
		);

		$pending = is_array( $pending )
			? $pending
			: array();

		$pending[ $opportunity_id ] = array(
			'opportunity_id' => $opportunity_id,
			'anchor_text'    => sanitize_text_field( $anchor ),
			'target_url'     => esc_url_raw( $target_url ),
			'editor_type'    => $editor_type,
			'queued_at'      => time(),
		);

		/*
		* Protect post meta from unlimited growth.
		*/
		if ( count( $pending ) > 50 ) {
			$pending = array_slice(
				$pending,
				-50,
				null,
				true
			);
		}

		update_post_meta(
			$post_id,
			'_srk_il_pending_editor_links',
			$pending
		);
	}

	/**
	 * Confirm editor-applied opportunities after the post is saved.
	 *
	 * Gutenberg links are verified against saved post_content.
	 * Elementor links are applied to the real Elementor document only now,
	 * then verified by the Elementor adapter.
	 *
	 * @param int    $post_id Source post ID.
	 * @param string $content Saved Gutenberg post content.
	 * @return array<string,int>
	 */
	public static function confirm_editor_links_after_save( $post_id, $content ) {
		$post_id = absint( $post_id );

		$pending = get_post_meta(
			$post_id,
			'_srk_il_pending_editor_links',
			true
		);

		if ( ! is_array( $pending ) || empty( $pending ) ) {
			return array(
				'confirmed' => 0,
				'rejected'  => 0,
			);
		}

		$confirmed = 0;
		$rejected  = 0;

		foreach ( $pending as $record ) {
			$opportunity_id = absint(
				$record['opportunity_id'] ?? 0
			);

			if ( ! $opportunity_id ) {
				$rejected++;
				continue;
			}

			$opportunity =
				SRK_Internal_Linking_DB::get_opportunity_by_id(
					$opportunity_id
				);

			if (
				! $opportunity ||
				absint( $opportunity['source_post_id'] ) !== $post_id ||
				'pending' !== sanitize_key( $opportunity['status'] )
			) {
				$rejected++;
				continue;
			}

			$anchor =
				! empty( $record['anchor_text'] )
					? sanitize_text_field( $record['anchor_text'] )
					: sanitize_text_field(
						$opportunity['anchor_text']
					);

			$target_url =
				! empty( $record['target_url'] )
					? esc_url_raw( $record['target_url'] )
					: get_permalink(
						absint( $opportunity['target_post_id'] )
					);

			$editor_type = sanitize_key(
				$record['editor_type'] ?? 'gutenberg'
			);

			/*
			* Elementor:
			*
			* The user has now actually saved the post.
			* Apply to real Elementor data and let the Elementor adapter
			* verify the persisted anchor + target before changing status.
			*/
			if ( 'elementor' === $editor_type ) {
				if (
					! class_exists( 'SRK_Internal_Linking_Elementor' ) ||
					! SRK_Internal_Linking_Elementor::is_elementor_post( $post_id )
				) {
					$rejected++;
					continue;
				}

				$result =
					SRK_Internal_Linking_Elementor::apply_opportunity(
						$opportunity_id,
						$post_id,
						$anchor,
						false,
						false
					);

				if ( is_wp_error( $result ) ) {
					$rejected++;
					continue;
				}

				$confirmed++;
				continue;
			}

			/*
			* Gutenberg:
			*
			* Preserve the current behavior. The editor already placed
			* the link into temporary Gutenberg blocks. Confirm that the
			* exact link made it into saved post_content.
			*/
			if (
				$target_url &&
				self::content_contains_anchor_link(
					$content,
					$anchor,
					$target_url
				)
			) {
				$status_updated =
					SRK_Internal_Linking_DB::mark_opportunity_inserted(
						$opportunity_id
					);

				if ( false !== $status_updated ) {
					$confirmed++;
				} else {
					$rejected++;
				}
			} else {
				/*
				* Link was not found in saved content.
				* Opportunity remains pending.
				*/
				$rejected++;
			}
		}

		/*
		* These records represent this editor save attempt only.
		*
		* Failed opportunities stay "pending" in the opportunities table
		* and can therefore be offered again.
		*/
		delete_post_meta(
			$post_id,
			'_srk_il_pending_editor_links'
		);

		return array(
			'confirmed' => $confirmed,
			'rejected'  => $rejected,
		);
	}

	/**
	 * Check whether content contains the exact target and anchor combination.
	 *
	 * @param string $content    Saved post content.
	 * @param string $anchor     Expected anchor text.
	 * @param string $target_url Expected target URL.
	 * @return bool
	 */
	private static function content_contains_anchor_link( $content, $anchor, $target_url ) {
		$expected_anchor = SRK_Internal_Linking_DB::normalize_keyword_text( $anchor );

		$expected_url = self::normalize_comparable_url( $target_url );

		if ( '' === $expected_anchor || '' === $expected_url ) {
			return false;
		}

		if ( ! preg_match_all( '/<a\b[^>]*\bhref\s*=\s*(["\'])(.*?)\1[^>]*>(.*?)<\/a>/is', (string) $content, $matches, PREG_SET_ORDER ) ) {
			return false;
		}

		foreach ( $matches as $match ) {
			$current_url = self::normalize_comparable_url( html_entity_decode( (string) $match[2], ENT_QUOTES | ENT_HTML5, get_bloginfo( 'charset' ) ) );

			$current_anchor = SRK_Internal_Linking_DB::normalize_keyword_text( wp_strip_all_tags( $match[3] ) );

			if ( $current_url === $expected_url && $current_anchor === $expected_anchor ) {
				return true;
			}
		}

		return false;
	}

	/**
	 * Apply a canonical dashboard opportunity directly to saved post content.
	 *
	 * The opportunity is marked inserted only after the saved content is verified
	 * to contain the expected target URL and anchor text.
	 *
	 * @param int    $opportunity_id        Opportunity row ID.
	 * @param bool   $update_database_content Whether post_content should be updated.
	 * @param string $anchor_override       Optional edited anchor text.
	 * @return array|WP_Error
	 */
	public static function apply_opportunity_to_post( $opportunity_id, $update_database_content = true, $anchor_override = '' ) {
		$opportunity_id = absint( $opportunity_id );

		if ( ! $opportunity_id ) {
			return new WP_Error( 'srk_invalid_opportunity', __( 'Invalid opportunity ID.', 'seo-repair-kit' ) );
		}

		$opportunity = SRK_Internal_Linking_DB::get_opportunity_by_id( $opportunity_id );

		if ( ! $opportunity ) {
			return new WP_Error( 'srk_missing_opportunity', __( 'Opportunity not found.', 'seo-repair-kit' ) );
		}

		if ( 'pending' !== sanitize_key( $opportunity['status'] ) ) {
			return new WP_Error( 'srk_opportunity_unavailable', __( 'This opportunity is no longer pending.', 'seo-repair-kit' ) );
		}

		$source_post_id = absint( $opportunity['source_post_id'] );

		$target_post_id = absint( $opportunity['target_post_id'] );

		$post = get_post( $source_post_id );

		if ( ! $post ) {
			return new WP_Error( 'srk_missing_source', __( 'Source post not found.', 'seo-repair-kit' ) );
		}

		if ( ! current_user_can( 'edit_post', $source_post_id ) ) {
			return new WP_Error( 'srk_opportunity_permission_denied', __( 'You do not have permission to edit the source post.', 'seo-repair-kit' ) );
		}

		$target = get_post( $target_post_id );

		if ( ! $target || 'publish' !== $target->post_status || $source_post_id === $target_post_id ) {
			return new WP_Error( 'srk_invalid_target', __( 'The target post is unavailable.', 'seo-repair-kit' ) );
		}

		$anchor = '' !== trim( (string) $anchor_override ) ? sanitize_text_field( wp_unslash( $anchor_override ) )
			: sanitize_text_field( $opportunity['anchor_text'] );

		$target_url = get_permalink( $target_post_id );

		if ( '' === $anchor || ! $target_url ) {
			return new WP_Error( 'srk_invalid_opportunity_data', __( 'The opportunity does not contain a valid anchor or target URL.', 'seo-repair-kit' ) );
		}

		/*
		* Elementor-built posts must never be modified through WP_Post::post_content.
		*
		* Suggestions for Elementor are generated from the real Elementor document,
		* therefore application must use the same content source.
		*/
		if (
			class_exists( 'SRK_Internal_Linking_Elementor' ) &&
			SRK_Internal_Linking_Elementor::is_elementor_post( $source_post_id )
		) {
			return SRK_Internal_Linking_Elementor::apply_opportunity(
				$opportunity_id,
				$source_post_id,
				$anchor,
				false,
				true
			);
		}

		/*
		* Handle an already-present exact link safely. This makes the dashboard
		* action idempotent when the link exists but the opportunity status was
		* not synchronized by an older implementation.
		*/
		if ( self::content_contains_anchor_link( $post->post_content, $anchor, $target_url ) ) {
			$status_updated = SRK_Internal_Linking_DB::mark_opportunity_inserted( $opportunity_id );

			if ( false === $status_updated ) {
				return new WP_Error( 'srk_status_update_failed', __( 'The link exists, but its opportunity status could not be updated.', 'seo-repair-kit' ) );
			}

			self::sync_post_after_link_change( $source_post_id );

			return array(
				'message'        => __( 'The link already existed and its status has been synchronized.', 'seo-repair-kit' ),
				'content'        => $post->post_content,
				'post_content'   => $post->post_content,
				'opportunity_id' => $opportunity_id,
				'status'         => 'inserted',
			);
		}

		if ( self::content_has_target_url( $post->post_content, $target_url ) ) {
			return new WP_Error(
				'srk_target_already_linked',
				__( 'This source post already links to the selected target using another anchor.', 'seo-repair-kit' )
			);
		}

		$new_content = self::insert_link_into_content( $post->post_content, $anchor, $target_url );

		if ( $new_content === $post->post_content ) {
			return new WP_Error( 'srk_anchor_missing', __( 'Anchor text was not found in current content. Regenerate suggestions.', 'seo-repair-kit' ) );
		}

		if ( $update_database_content ) {
			$result = wp_update_post(
				array(
					'ID'           => $source_post_id,
					'post_content' => $new_content,
				),
				true
			);

			if ( is_wp_error( $result ) ) {
				return $result;
			}

			/*
			* Verify the content that was actually stored by WordPress.
			*/
			clean_post_cache( $source_post_id );

			$saved_post = get_post( $source_post_id );

			if ( ! $saved_post || ! self::content_contains_anchor_link( $saved_post->post_content, $anchor, $target_url ) ) {
				return new WP_Error(
					'srk_saved_link_verification_failed',
					__( 'WordPress updated the post, but the inserted link could not be verified.', 'seo-repair-kit' )
				);
			}
		}

		$status_updated = SRK_Internal_Linking_DB::mark_opportunity_inserted( $opportunity_id );

		if ( false === $status_updated ) {
			return new WP_Error(
				'srk_status_update_failed',
				__( 'The link was inserted, but the opportunity status could not be updated.', 'seo-repair-kit' )
			);
		}

		self::sync_post_after_link_change( $source_post_id );

		return array(
			'message'        => __( 'Link inserted successfully.', 'seo-repair-kit' ),
			'content'        => $new_content,
			'post_content'   => $new_content,
			'opportunity_id' => $opportunity_id,
			'status'         => 'inserted',
		);
	}

	/**
	 * Insert one internal link into visible Gutenberg/HTML text.
	 *
	 * Existing links, headings, scripts, styles, code and block comments remain
	 * untouched. The first eligible case-insensitive anchor occurrence is linked.
	 *
	 * @param string $content    Serialized post content.
	 * @param string $anchor     Anchor text.
	 * @param string $target_url Target URL.
	 * @return string
	 */
	private static function insert_link_into_content( $content, $anchor, $target_url ) {
		$content = (string) $content;

		$anchor = trim( wp_strip_all_tags( html_entity_decode( (string) $anchor, ENT_QUOTES | ENT_HTML5, get_bloginfo( 'charset' ) ) ) );

		$target_url = esc_url( $target_url );

		if ( '' === $content || '' === $anchor || '' === $target_url ) {
			return $content;
		}

		$parts = preg_split( '/(<[^>]+>)/s', $content, -1, PREG_SPLIT_DELIM_CAPTURE );

		if ( ! is_array( $parts ) ) {
			return $content;
		}

		/*
		* Build a whitespace-tolerant anchor regex.
		*
		* Example:
		* "technical and content SEO"
		*
		* becomes roughly:
		*
		* technical(?:space|nbsp)+and(?:space|nbsp)+content(?:space|nbsp)+SEO
		*/
		$anchor_words = preg_split( '/\s+/u', $anchor, -1, PREG_SPLIT_NO_EMPTY );

		if ( empty( $anchor_words ) ) {
			return $content;
		}

		$pattern_parts = array();

		foreach ( $anchor_words as $word ) {
			$pattern_parts[] = preg_quote( $word, '/' );
		}

		$flexible_space = '(?:\s|&nbsp;|&#160;|&#x0*A0;|\x{00A0})+';

		$pattern = '/' . implode( $flexible_space, $pattern_parts ) . '/iu';

		$protected_tags = array(
			'a',
			'script',
			'style',
			'code',
			'pre',
			'textarea',
			'button',
			'h1',
			'h2',
			'h3',
			'h4',
			'h5',
			'h6',
		);

		$protected_depth = 0;
		$inserted        = false;
		$output          = '';

		foreach ( $parts as $part ) {
			if ( '' === $part ) {
				continue;
			}

			if ( '<' === substr( $part, 0, 1 ) ) {
				if ( preg_match( '/^<\s*\/\s*([a-z0-9]+)/i', $part, $closing_match ) ) {
					$tag_name = strtolower( $closing_match[1] );

					if ( in_array( $tag_name, $protected_tags, true ) ) {
						$protected_depth = max( 0, $protected_depth - 1 );
					}
				} elseif ( preg_match( '/^<\s*([a-z0-9]+)/i', $part, $opening_match ) ) {
					$tag_name = strtolower( $opening_match[1] );

					$is_self_closing = false !== strpos( $part, '/>' );

					if ( ! $is_self_closing && in_array( $tag_name, $protected_tags, true ) ) {
						$protected_depth++;
					}
				}

				$output .= $part;
				continue;
			}

			if ( ! $inserted && 0 === $protected_depth ) {
				$updated_part = preg_replace_callback(
					$pattern,
					static function ( $matches ) use ( $target_url ) {
						/*
						* Preserve the exact text as stored by Gutenberg.
						*
						* Do NOT replace it with esc_html( $anchor ), because
						* that could alter NBSP/entity/capitalization formatting.
						*/
						return '<a href="' . esc_url( $target_url ) . '">' . $matches[0] . '</a>';
					},
					$part,
					1,
					$replacement_count
				);

				if ( is_string( $updated_part ) && $replacement_count > 0 ) {
					$part     = $updated_part;
					$inserted = true;
				}
			}

			$output .= $part;
		}

		return $inserted ? $output : $content;
	}

	/**
	 * Remove an inserted internal link from the source post content.
	 *
	 * The anchor text remains in the content; only the surrounding <a> element
	 * pointing to the recorded target URL is removed.
	 *
	 * @param int $opportunity_id Opportunity ID.
	 * @return array|WP_Error
	 */
	public static function remove_inserted_link( $opportunity_id ) {
		$opportunity_id = absint( $opportunity_id );
		$opportunity    = SRK_Internal_Linking_DB::get_opportunity_by_id( $opportunity_id );

		if ( ! $opportunity ) {
			return new WP_Error( 'srk_missing_opportunity', __( 'Inserted link record was not found.', 'seo-repair-kit' ) );
		}

		if ( 'inserted' !== sanitize_key( $opportunity['status'] ) ) {
			return new WP_Error( 'srk_link_not_inserted', __( 'This link is no longer marked as inserted.', 'seo-repair-kit' ) );
		}

		$source_post_id = absint( $opportunity['source_post_id'] );
		$target_post_id = absint( $opportunity['target_post_id'] );
		$post           = get_post( $source_post_id );

		if ( ! $post ) {
			return new WP_Error( 'srk_missing_source', __( 'Source post was not found.', 'seo-repair-kit' ) );
		}

		$target_url = ! empty( $opportunity['target_url'] ) ? esc_url_raw( $opportunity['target_url'] )
			: get_permalink( $target_post_id );

		if ( ! $target_url ) {
			return new WP_Error( 'srk_missing_target_url', __( 'Target URL could not be resolved.', 'seo-repair-kit' ) );
		}

		$new_content = self::remove_link_from_content( $post->post_content, $opportunity['anchor_text'], $target_url );

		if ( $new_content === $post->post_content ) {
			return new WP_Error(
				'srk_inserted_link_not_found',
				__( 'The inserted link could not be found in the current source content. It may already have been edited or removed manually.', 'seo-repair-kit' )
			);
		}

		$result = wp_update_post(
			array(
				'ID'           => $source_post_id,
				'post_content' => $new_content,
			),
			true
		);

		if ( is_wp_error( $result ) ) {
			return $result;
		}

		SRK_Internal_Linking_DB::mark_opportunity_removed( $opportunity_id );

		self::sync_post_after_link_change( $source_post_id );

		return array(
			'message'        => __( 'Inserted internal link removed successfully.', 'seo-repair-kit' ),
			'opportunity_id' => $opportunity_id,
			'post_id'        => $source_post_id,
		);
	}

	/**
	 * Remove one matching anchor link while preserving its inner content.
	 *
	 * @param string $content    Source post content.
	 * @param string $anchor     Expected anchor text.
	 * @param string $target_url Expected target URL.
	 * @return string
	 */
	private static function remove_link_from_content( $content, $anchor, $target_url ) {
		$content          = (string) $content;
		$expected_anchor  = SRK_Internal_Linking_DB::normalize_keyword_text( $anchor );
		$expected_url     = self::normalize_comparable_url( $target_url );
		$link_was_removed = false;

		if ( '' === $expected_anchor || '' === $expected_url ) {
			return $content;
		}

		$updated = preg_replace_callback(
			'/<a\b[^>]*\bhref\s*=\s*(["\'])(.*?)\1[^>]*>(.*?)<\/a>/is',
			static function ( $matches ) use ( $expected_anchor, $expected_url, &$link_was_removed ) {
				if ( $link_was_removed ) {
					return $matches[0];
				}

				$current_url = self::normalize_comparable_url( html_entity_decode( (string) $matches[2], ENT_QUOTES | ENT_HTML5, get_bloginfo( 'charset' ) ) );

				$current_anchor = SRK_Internal_Linking_DB::normalize_keyword_text( wp_strip_all_tags( $matches[3] ) );

				if ( $current_url === $expected_url && $current_anchor === $expected_anchor ) {
					$link_was_removed = true;

					// Preserve formatting inside the anchor, but remove the <a> wrapper.
					return $matches[3];
				}

				return $matches[0];
			},
			$content
		);

		return is_string( $updated ) ? $updated : $content;
	}

	/**
	 * Normalize a URL for internal comparison.
	 *
	 * @param string $url Raw URL.
	 * @return string
	 */
	private static function normalize_comparable_url( $url ) {
		$url = html_entity_decode( trim( (string) $url ), ENT_QUOTES | ENT_HTML5, get_bloginfo( 'charset' ) );

		if ( '' === $url ) {
			return '';
		}

		$url = esc_url_raw( $url );

		if ( '' === $url ) {
			return '';
		}

		return untrailingslashit( strtolower( $url ) );
	}

	/**
	 * Synchronize indexed links and orphan metrics after saved content changes.
	 *
	 * @param int $post_id Source post ID.
	 * @return void
	 */
	private static function sync_post_after_link_change( $post_id ) {
		$post_id = absint( $post_id );

		if (
			$post_id && class_exists( 'SRK_Internal_Linking_Indexer' ) &&
			is_callable( array( 'SRK_Internal_Linking_Indexer', 'index_single_post' ) )
		) {
			SRK_Internal_Linking_Indexer::index_single_post( $post_id );
		}

		SRK_Internal_Linking_DB::recalculate_inbound_counts();
	}

	/**
	 * Merge one AI semantic candidate into the canonical opportunities table.
	 *
	 * @param array $semantic_link Semantic staging row.
	 * @return int|false
	 */
	public static function create_from_ai_candidate( $candidate ) {
		if ( ! self::feature_enabled() ) {
			return false;
		}

		$source_id = absint( $candidate['source_post_id'] ?? 0 );

		$target_id = absint( $candidate['target_post_id'] ?? 0 );

		if ( ! $source_id || ! $target_id || $source_id === $target_id ) {
			return false;
		}

		/*
		* Never suggest a target that is already linked
		* from the source.
		*/
		if ( SRK_Internal_Linking_DB::source_has_active_link_to_target( $source_id, $target_id ) ) {
			return false;
		}

		$source = get_post( $source_id );

		$target = get_post( $target_id );

		if ( ! $source || ! $target || 'publish' !== $target->post_status ) {
			return false;
		}

		$anchor = sanitize_text_field( $candidate['anchor_text'] ?? '' );

		if ( '' === $anchor ) {
			return false;
		}

		/*
		* AI candidates must pass the same anchor and insertion eligibility
		* rules as deterministic opportunities before canonical persistence.
		*/
		if (
			! self::is_candidate_anchor_eligible(
				$source_id,
				$anchor
			)
		) {
			return false;
		}

		$similarity = min( 1, max( 0, floatval( $candidate['ai_similarity'] ?? 0 ) ) );

		$ai_score = min( 100, max( 0, absint( $candidate['ai_score'] ?? round( $similarity * 100 ) ) ) );

		if ( $ai_score <= 0 ) {
			return false;
		}

		$reason = ! empty( $candidate['reason'] ) ? sanitize_text_field( $candidate['reason'] ) : sprintf(
				/* translators: %s: semantic similarity percentage */
				__( 'AI semantic match with %s similarity.', 'seo-repair-kit' ),
				number_format_i18n( $similarity * 100, 1 ) . '%'
			);

		return SRK_Internal_Linking_DB::upsert_canonical_opportunity(
				array(
					'scan_run_id' => ! empty( $candidate['scan_run_id'] ) ? absint( $candidate['scan_run_id'] ) : null,

					'source_post_id' => $source_id,

					'target_post_id' => $target_id,

					'anchor_text' => $anchor,

					'sentence' => sanitize_text_field( $candidate['sentence'] ?? '' ),

					'reason' => $reason,

					'score' => $ai_score,

					'final_score' => $ai_score,

					'selected_type' => 'ai',

					'rule_score' => null,

					'ai_score' => $ai_score,

					'ai_similarity' => $similarity,

					'status' => in_array( sanitize_key( $candidate['status'] ?? 'building' ), array( 'building', 'pending', ), true )
							? sanitize_key( $candidate['status'] ?? 'building' ) : 'building',
				)
			);
	}

	/**
	 * Determine whether a generated anchor is eligible for canonical use.
	 *
	 * This is the shared validation boundary for AI-generated anchors. It
	 * reuses the same anchor and structural insertion rules used by the
	 * deterministic opportunity engine.
	 *
	 * @param int    $source_post_id Source post ID.
	 * @param string $anchor         Proposed anchor text.
	 * @return bool
	 */
	public static function is_candidate_anchor_eligible(
		$source_post_id,
		$anchor
	) {
		$source_post_id = absint(
			$source_post_id
		);

		$anchor = sanitize_text_field(
			$anchor
		);

		if (
			! $source_post_id ||
			'' === trim( $anchor )
		) {
			return false;
		}

		if (
			! self::valid_anchor(
				$anchor,
				self::source_keyword_terms(
					$source_post_id
				)
			)
		) {
			return false;
		}

		$post = get_post(
			$source_post_id
		);

		if ( ! $post ) {
			return false;
		}

		$content =
			(string) $post->post_content;

		/*
		* Use Elementor's actual document when Elementor owns the content.
		*/
		if (
			class_exists(
				'SRK_Internal_Linking_Elementor'
			) &&
			SRK_Internal_Linking_Elementor::
				is_elementor_post(
					$source_post_id
				)
		) {
			$elementor_content =
				SRK_Internal_Linking_Elementor::
					get_analysis_content(
						$source_post_id,
						true
					);

			if (
				'' !== trim(
					$elementor_content
				)
			) {
				$content =
					$elementor_content;
			}
		}

		return self::anchor_is_insertable_in_content(
			$content,
			$anchor
		);
	}

	/**
	 * Return existing orphan-target opportunities or generate them when missing.
	 *
	 * Existing opportunities are always preferred. Generation runs only when
	 * there are no active pending opportunities for the selected target.
	 *
	 * @param int $target_post_id Target orphan post ID.
	 * @param int $limit          Maximum results to return.
	 *
	 * @return array<string,mixed>|WP_Error
	 */
	public static function get_or_generate_for_orphan_target( $target_post_id, $limit = 20 ) {
		$target_post_id = absint( $target_post_id );
		$limit          = min( 50, max( 1, absint( $limit ) ) );

		if ( ! $target_post_id ) {
			return new WP_Error( 'srk_il_invalid_target', __( 'Invalid orphan target post.', 'seo-repair-kit' ) );
		}

		$target_post = get_post( $target_post_id );

		if ( ! $target_post ) {
			return new WP_Error( 'srk_il_target_not_found', __( 'The selected orphan content could not be found.', 'seo-repair-kit' ) );
		}

		/*
		* Step 1: Return already generated canonical opportunities.
		*/
		$existing = SRK_Internal_Linking_DB::get_pending_opportunities_for_target( $target_post_id, $limit );

		if ( ! empty( $existing ) ) {
			return array(
				'generated'     => false,
				'from_existing' => true,
				'processing'    => false,
				'count'         => count( $existing ),
				'opportunities' => $existing,
				'message'       => sprintf(
					/* translators: %d: number of existing opportunities */
					_n( '%d existing opportunity found.', '%d existing opportunities found.', count( $existing ), 'seo-repair-kit' ),
					count( $existing )
				),
			);
		}

		/*
		* Prevent multiple administrators from starting the same target scan
		* at the same time.
		*/
		$lock_key = 'srk_il_orphan_find_' . $target_post_id;

		if ( get_transient( $lock_key ) ) {
			return array(
				'generated'     => false,
				'from_existing' => false,
				'processing'    => true,
				'count'         => 0,
				'opportunities' => array(),
				'message'       => __( 'Opportunities are already being generated for this content.', 'seo-repair-kit' ),
			);
		}

		set_transient( $lock_key, 1, 5 * MINUTE_IN_SECONDS );

		try {
			/*
			* Step 2: Reuse the existing target-oriented opportunity engine.
			*
			* This method should continue using the existing:
			* - indexed source content
			* - target keywords
			* - scoring engine
			* - duplicate protection
			* - canonical opportunity merge
			*/
			self::generate_for_target_post( $target_post_id );
		} finally {
			delete_transient( $lock_key );
		}

		/*
		* Step 3: Retrieve the canonical rows created by the existing generator.
		*/
		$generated = SRK_Internal_Linking_DB::get_pending_opportunities_for_target( $target_post_id, $limit );

		return array(
			'generated'     => true,
			'from_existing' => false,
			'processing'    => false,
			'count'         => count( $generated ),
			'opportunities' => $generated,
			'message'       => ! empty( $generated ) ? sprintf(
					/* translators: %d: number of generated opportunities */
					_n( '%d new opportunity found.', '%d new opportunities found.', count( $generated ), 'seo-repair-kit' ),
					count( $generated )
				) : __( 'No suitable source posts or pages were found for this orphan content.', 'seo-repair-kit' ),
		);
	}

	/**
	 * Check whether an anchor exists inside one eligible text node.
	 *
	 * This uses the same structural restrictions as link insertion so the
	 * opportunity engine does not store anchors that cannot later be applied.
	 *
	 * @param string $content Serialized WordPress content.
	 * @param string $anchor  Proposed anchor.
	 * @return bool
	 */
	private static function anchor_is_insertable_in_content( $content, $anchor ) {
		$content = (string) $content;

		$anchor = trim( wp_strip_all_tags( html_entity_decode( (string) $anchor, ENT_QUOTES | ENT_HTML5, get_bloginfo( 'charset' ) ) ) );

		if ( '' === $content || '' === $anchor ) {
			return false;
		}

		$words = preg_split( '/\s+/u', $anchor, -1, PREG_SPLIT_NO_EMPTY );

		if ( empty( $words ) ) {
			return false;
		}

		$quoted = array_map(
			static function ( $word ) {
				return preg_quote( $word, '/' );
			},
			$words
		);

		$flexible_space = '(?:\s|&nbsp;|&#160;|&#x0*A0;|\x{00A0})+';

		$pattern = '/' . implode( $flexible_space, $quoted ) . '/iu';

		$parts = preg_split( '/(<[^>]+>)/s', $content, -1, PREG_SPLIT_DELIM_CAPTURE );

		if ( ! is_array( $parts ) ) {
			return false;
		}

		$protected_tags = array(
			'a',
			'script',
			'style',
			'code',
			'pre',
			'textarea',
			'button',
			'h1',
			'h2',
			'h3',
			'h4',
			'h5',
			'h6',
		);

		$protected_depth = 0;

		foreach ( $parts as $part ) {
			if ( '' === $part ) {
				continue;
			}

			if ( '<' === substr( $part, 0, 1 ) ) {
				if ( preg_match( '/^<\s*\/\s*([a-z0-9]+)/i', $part, $match ) ) {
					if ( in_array( strtolower( $match[1] ), $protected_tags, true ) ) {
						$protected_depth = max( 0, $protected_depth - 1 );
					}
				} elseif ( preg_match( '/^<\s*([a-z0-9]+)/i', $part, $match ) ) {
					$tag_name = strtolower( $match[1] );

					if ( false === strpos( $part, '/>' ) && in_array( $tag_name, $protected_tags, true ) ) {
						$protected_depth++;
					}
				}

				continue;
			}

			if ( 0 === $protected_depth && preg_match( $pattern, $part ) ) {
				return true;
			}
		}

		return false;
	}

}
