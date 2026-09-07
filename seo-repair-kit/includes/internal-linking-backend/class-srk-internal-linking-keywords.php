<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Internal Linking target keyword builder.
 *
 * Builds a clean keyword pool for each indexed post. The class intentionally
 * keeps user-facing keywords readable and stores only full keyword phrases
 * such as the post title, slug phrase, taxonomy terms, and custom keywords.
 *
 */
class SRK_Internal_Linking_Keywords {

	/**
	 * Fallback keyword limit if settings class is unavailable.
	 *
	 * @var int
	 */
	const DEFAULT_MAX_KEYWORDS_PER_POST = 30;

	/**
	 * Generate or refresh generated keywords for one post.
	 *
	 * Custom/manual keywords are preserved. Only generated keywords are replaced.
	 * The UI receives complete readable phrases, while the opportunity engine can
	 * still tokenize these phrases internally for exact and partial matching.
	 *
	 * @param int   $post_id WordPress post ID.
	 * @param array $args    Optional override data. Supports title.
	 * @return array
	 */
	public static function generate_for_post( $post_id, $args = array() ) {
		$post_id = absint( $post_id );
		$post    = get_post( $post_id );

		if ( ! $post || 'publish' !== $post->post_status ) {
			return array();
		}

		$title   = ! empty( $args['title'] ) ? sanitize_text_field( $args['title'] ) : get_the_title( $post_id );
		$sources = self::get_enabled_sources();
		$items   = array();

		if ( in_array( 'title', $sources, true ) ) {
			foreach ( self::generate_title_keywords( $title ) as $keyword ) {
				$items[] = self::make_keyword( $keyword, 'title', 85 );
			}
		}

		if ( in_array( 'slug', $sources, true ) ) {
			foreach ( self::generate_slug_keywords( $post->post_name ) as $keyword ) {
				$items[] = self::make_keyword( $keyword, 'slug', 75 );
			}
		}

		if ( in_array( 'taxonomy', $sources, true ) ) {
			foreach ( self::generate_taxonomy_keywords( $post_id ) as $keyword ) {
				$items[] = self::make_keyword( $keyword, 'taxonomy', 20 );
			}
		}

		$items = self::unique_keywords( $items );
		$items = self::sort_keywords_by_quality( $items );
		$items = array_slice( $items, 0, self::get_max_keywords_per_post() );

		$keyword_rows = array();

		foreach ( $items as $item ) {
			if ( self::is_weak_keyword( $item['keyword'] ) ) {
				continue;
			}

			$keyword_rows[] = array(
				'post_id'               => $post_id,
				'keyword'               => $item['keyword'],
				'source'                => $item['source'],
				'keyword_type'          => 'auto',
				'is_active'             => 1,
				'quality_score'         => $item['quality_score'],
				'meaningful_words_json' => wp_json_encode( self::meaningful_words( $item['keyword'] ) ),
			);
		}

		if ( ! self::auto_keywords_are_current( $post_id, $keyword_rows ) ) {
			// Keep custom keywords intact and rebuild only changed automatic keywords.
			SRK_Internal_Linking_DB::delete_auto_keywords_for_post( $post_id );

			foreach ( $keyword_rows as $keyword_row ) {
				SRK_Internal_Linking_DB::upsert_keyword( $keyword_row );
			}
		}

		return SRK_Internal_Linking_DB::get_keywords_by_post( $post_id );
	}

	/**
	 * Check whether stored auto keywords already match the generated keyword set.
	 *
	 * @param int   $post_id      WordPress post ID.
	 * @param array $keyword_rows Generated keyword rows.
	 * @return bool
	 */
	private static function auto_keywords_are_current( $post_id, $keyword_rows ) {
		$existing_rows = SRK_Internal_Linking_DB::get_auto_keywords_by_post( $post_id );

		if ( count( $existing_rows ) !== count( $keyword_rows ) ) {
			return false;
		}

		return self::build_keyword_signature( $existing_rows ) === self::build_keyword_signature( $keyword_rows );
	}

	/**
	 * Build a stable comparison signature for generated auto keyword rows.
	 *
	 * @param array $keyword_rows Keyword rows.
	 * @return array
	 */
	private static function build_keyword_signature( $keyword_rows ) {
		$signature = array();

		foreach ( $keyword_rows as $row ) {
			$keyword = isset( $row['keyword'] ) ? sanitize_text_field( $row['keyword'] ) : '';
			$keyword = trim( preg_replace( '/\s+/u', ' ', $keyword ) );

			if ( '' === $keyword ) {
				continue;
			}

			$words = array();

			if ( ! empty( $row['meaningful_words_json'] ) ) {
				$decoded_words = json_decode( $row['meaningful_words_json'], true );

				if ( is_array( $decoded_words ) ) {
					$words = array_values( array_map( 'sanitize_text_field', $decoded_words ) );
				}
			}

			$signature[] = array(
				'keyword'            => $keyword,
				'keyword_hash'       => ! empty( $row['keyword_hash'] ) ? sanitize_text_field( $row['keyword_hash'] ) : SRK_Internal_Linking_DB::get_keyword_hash( $keyword ),
				'normalized_keyword' => ! empty( $row['normalized_keyword'] ) ? sanitize_text_field( $row['normalized_keyword'] ) : SRK_Internal_Linking_DB::normalize_keyword_text( $keyword ),
				'source'             => sanitize_key( isset( $row['source'] ) ? $row['source'] : 'title' ),
				'keyword_type'       => sanitize_key( isset( $row['keyword_type'] ) ? $row['keyword_type'] : 'auto' ),
				'is_active'          => isset( $row['is_active'] ) ? absint( $row['is_active'] ) : 1,
				'quality_score'      => isset( $row['quality_score'] ) ? absint( $row['quality_score'] ) : 0,
				'meaningful_words'   => $words,
			);
		}

		usort(
			$signature,
			static function ( $a, $b ) {
				return strcmp( wp_json_encode( $a ), wp_json_encode( $b ) );
			}
		);

		return $signature;
	}

	/**
	 * Add a custom target keyword for a post.
	 *
	 * @param int    $post_id Post ID.
	 * @param string $keyword User-entered keyword.
	 * @return int Inserted/updated keyword ID or 0 on failure.
	 */
	public static function add_custom_keyword( $post_id, $keyword ) {
		$post_id = absint( $post_id );
		$keyword = self::clean_phrase( $keyword );

		if ( ! $post_id || '' === $keyword ) {
			return 0;
		}

		return SRK_Internal_Linking_DB::upsert_keyword(
			array(
				'post_id'               => $post_id,
				'keyword'               => $keyword,
				'source'                => 'custom',
				'keyword_type'          => 'custom',
				'is_active'             => 1,
				'quality_score'         => 100,
				'meaningful_words_json' => wp_json_encode( self::meaningful_words( $keyword ) ),
			)
		);
	}
	
	/**
	 * Get stored keywords for a post.
	 *
	 * @param int $post_id Post ID.
	 * @return array
	 */
	public static function get_post_keywords( $post_id ) {
		return SRK_Internal_Linking_DB::get_keywords_by_post( absint( $post_id ) );
	}

	/**
	 * Create a keyword refresh scan run.
	 *
	 * @return array
	 */
	public static function start_refresh() {
		$total   = SRK_Internal_Linking_DB::count_indexed_content();
		$scan_id = SRK_Internal_Linking_DB::insert_scan_run(
			array(
				'scan_type'   => 'keywords',
				'status'      => 'running',
				'total_items' => $total,
				'message'     => __( 'Refreshing target keywords.', 'seo-repair-kit' ),
			)
		);

		return array(
			'scan_id'     => $scan_id,
			'total_items' => $total,
			'page'        => 1,
		);
	}

	/**
	 * Process one keyword refresh batch.
	 *
	 * @param int $scan_id Scan run ID.
	 * @param int $page    Current batch page.
	 * @return array
	 */
	public static function refresh_batch( $scan_id, $page = 1 ) {
		$batch  = self::get_batch_size();
		$page   = max( 1, absint( $page ) );
		$offset = ( $page - 1 ) * $batch;
		$rows   = SRK_Internal_Linking_DB::get_indexed_content_batch( $batch, $offset );
		$done   = 0;

		foreach ( $rows as $row ) {
			self::generate_for_post( absint( $row['post_id'] ) );
			$done++;
		}

		$scan      = SRK_Internal_Linking_DB::get_scan_run( $scan_id );
		$total     = absint( $scan['total_items'] ?? 0 );
		$processed = min( $total, $offset + $done );
		$complete  = $done < $batch || $processed >= $total;
		$percent   = $total > 0 ? min( 100, absint( floor( ( $processed / $total ) * 100 ) ) ) : 100;

		SRK_Internal_Linking_DB::update_scan_run(
			$scan_id,
			array(
				'status'          => $complete ? 'completed' : 'running',
				'processed_items' => $processed,
				'success_items'   => $processed,
				'current_batch'   => $page,
				'completed_at'    => $complete ? SRK_Internal_Linking_DB::get_now() : null,
				'message'         => $complete ? __( 'Target keywords refreshed.', 'seo-repair-kit' ) : __( 'Refreshing target keywords...', 'seo-repair-kit' ),
			)
		);

		return array(
			'scan_id'         => absint( $scan_id ),
			'page'            => $page + 1,
			'next_page'       => $page + 1,
			'processed_items' => $processed,
			'total_items'     => $total,
			'percent'         => $percent,
			'progress'        => $percent,
			'complete'        => $complete,
		);
	}

	/**
	 * Build title keywords. Keeps the full title as one UI keyword.
	 *
	 * @param string $title Post title.
	 * @return array
	 */
	private static function generate_title_keywords( $title ) {
		$clean = self::clean_phrase( $title );

		return '' !== $clean ? array( $clean ) : array();
	}

	/**
	 * Build slug keyword phrase. Keeps the full slug phrase as one UI keyword.
	 *
	 * @param string $slug Post slug.
	 * @return array
	 */
	private static function generate_slug_keywords( $slug ) {
		$clean = self::clean_phrase( str_replace( array( '-', '_' ), ' ', $slug ) );

		return '' !== $clean ? array( $clean ) : array();
	}

	/**
	 * Build taxonomy keywords from configured taxonomies only.
	 *
	 * Taxonomy terms are kept as weak topic hints. The opportunity engine should
	 * still decide whether taxonomy keywords are eligible for anchor matching.
	 *
	 * @param int $post_id Post ID.
	 * @return array
	 */
	private static function generate_taxonomy_keywords( $post_id ) {
		$output     = array();
		$taxonomies = self::get_enabled_taxonomies( $post_id );

		foreach ( $taxonomies as $taxonomy ) {
			$terms = get_the_terms( $post_id, $taxonomy );

			if ( is_wp_error( $terms ) || empty( $terms ) ) {
				continue;
			}

			foreach ( $terms as $term ) {
				if ( 'uncategorized' === sanitize_title( $term->name ) ) {
					continue;
				}

				$output[] = $term->name;
			}
		}

		return $output;
	}

	/**
	 * Normalize keyword item structure.
	 *
	 * @param string $keyword Keyword phrase.
	 * @param string $source  Keyword source.
	 * @param int    $score   Quality score.
	 * @return array
	 */
	private static function make_keyword( $keyword, $source, $score ) {
		return array(
			'keyword'       => self::clean_phrase( $keyword ),
			'source'        => sanitize_key( $source ),
			'quality_score' => absint( $score ),
		);
	}

	/**
	 * Remove duplicate keywords using normalized keyword text.
	 *
	 * @param array $items Keyword items.
	 * @return array
	 */
	private static function unique_keywords( $items ) {
		$seen   = array();
		$output = array();

		foreach ( $items as $item ) {
			$keyword = self::clean_phrase( $item['keyword'] ?? '' );
			$norm    = SRK_Internal_Linking_DB::normalize_keyword_text( $keyword );

			if ( '' === $norm || isset( $seen[ $norm ] ) ) {
				continue;
			}

			$seen[ $norm ]   = true;
			$item['keyword'] = $keyword;
			$output[]        = $item;
		}

		return $output;
	}

	/**
	 * Sort keyword items by quality score descending.
	 *
	 * @param array $items Keyword items.
	 * @return array
	 */
	private static function sort_keywords_by_quality( $items ) {
		usort(
			$items,
			static function ( $a, $b ) {
				return absint( $b['quality_score'] ?? 0 ) <=> absint( $a['quality_score'] ?? 0 );
			}
		);

		return $items;
	}

	/**
	 * Clean a human-readable keyword phrase without aggressive token splitting.
	 *
	 * @param string $phrase Raw phrase.
	 * @return string
	 */
	public static function clean_phrase( $phrase ) {
		$phrase = wp_strip_all_tags( (string) $phrase );
		$phrase = html_entity_decode( $phrase, ENT_QUOTES | ENT_HTML5, get_bloginfo( 'charset' ) );
		$phrase = preg_replace( '/[\x{2018}\x{2019}]/u', "'", $phrase );
		$phrase = preg_replace( '/[\x{201C}\x{201D}]/u', '"', $phrase );
		$phrase = preg_replace( '/\s+/u', ' ', trim( $phrase ) );

		return trim( $phrase, " \t\n\r\0\x0B-–—:|,.;" );
	}

	/**
	 * Tokenize text using the centralized DB normalizer.
	 *
	 * @param string $text Text to tokenize.
	 * @return array
	 */
	public static function tokenize( $text ) {
		$text = SRK_Internal_Linking_DB::normalize_keyword_text( $text );

		if ( '' === $text ) {
			return array();
		}

		return preg_split( '/\s+/u', $text, -1, PREG_SPLIT_NO_EMPTY );
	}

	/**
	 * Extract meaningful words using Internal Linking language settings.
	 *
	 * @param string    $text Text to process.
	 * @param bool|null $ignore_numbers Null uses the saved setting.
	 * @return array<int,string>
	 */
	public static function meaningful_words( $text, $ignore_numbers = null ) {
		if (
			class_exists(
				'SRK_Internal_Linking_Settings'
			)
		) {
			return SRK_Internal_Linking_Settings::meaningful_words(
				$text,
				$ignore_numbers
			);
		}

		return array();
	}

	/**
	 * Compatibility wrapper used by the opportunity/scoring engine.
	 *
	 * Previously this method removed a hardcoded broad-topic word list. That made
	 * the engine SEO/WordPress specific. It now returns meaningful_words() so all
	 * filtering remains settings-driven.
	 *
	 * @param string $text Text to analyze.
	 * @return array
	 */
	public static function specific_words( $text ) {
		return self::meaningful_words( $text );
	}

	/**
	 * Count meaningful words in a phrase.
	 *
	 * @param string $text Text to analyze.
	 * @return int
	 */
	public static function count_meaningful_words( $text ) {
		return count( self::meaningful_words( $text ) );
	}

	/**
	 * Determine whether a keyword is too weak to store.
	 *
	 * This method only checks generic structural weakness: empty text, no
	 * meaningful words, or very short one-token keyword. It does not use any
	 * hardcoded industry-specific blocked words.
	 *
	 * @param string $phrase Keyword phrase.
	 * @return bool
	 */
	public static function is_weak_keyword( $phrase ) {
		$norm = SRK_Internal_Linking_DB::normalize_keyword_text( $phrase );

		if ( '' === $norm ) {
			return true;
		}

		$meaningful = self::meaningful_words( $phrase );

		if ( empty( $meaningful ) ) {
			return true;
		}

		if ( 1 === count( $meaningful ) && strlen( $meaningful[0] ) < 5 && ! preg_match( '/[^\x00-\x7F]/u', $meaningful[0] ) ) {
			return true;
		}

		return false;
	}

	/**
	 * Backward-compatible generic phrase checker.
	 *
	 * Kept because other files may call this method. It now uses only the
	 * stopword/meaningful-word result instead of hardcoded phrase lists.
	 *
	 * @param string $phrase Phrase to check.
	 * @return bool
	 */
	public static function is_generic_phrase( $phrase ) {
		return empty( self::meaningful_words( $phrase ) );
	}

	/**
	 * Backward-compatible broad topic checker.
	 *
	 * Hardcoded topic blocking has been removed. If a site owner wants to ignore
	 * a broad term, they should add it to Internal Linking ignored words.
	 *
	 * @param string $word Word to check.
	 * @return bool
	 */
	public static function is_broad_topic_word( $word ) {
		$word      = self::normalize_word( $word );
		$stopwords = self::get_stopword_map();

		return isset( $stopwords[ $word ] );
	}

	/**
	 * Normalize a single token using settings if available.
	 *
	 * @param string $word Word to normalize.
	 * @return string
	 */
	private static function normalize_word( $word ) {
		if ( class_exists( 'SRK_Internal_Linking_Settings' ) && method_exists( 'SRK_Internal_Linking_Settings', 'normalize_word' ) ) {
			return SRK_Internal_Linking_Settings::normalize_word( $word );
		}

		$word = remove_accents( wp_strip_all_tags( (string) $word ) );
		$word = function_exists( 'mb_strtolower' ) ? mb_strtolower( $word, 'UTF-8' ) : strtolower( $word );
		$word = preg_replace( '/[^\p{L}\p{N}\-]+/u', '', $word );

		return trim( sanitize_text_field( $word ) );
	}

	/**
	 * Get active stopword map from settings.
	 *
	 * @return array
	 */
	private static function get_stopword_map() {
		if ( class_exists( 'SRK_Internal_Linking_Settings' ) && method_exists( 'SRK_Internal_Linking_Settings', 'get_stopword_map' ) ) {
			return SRK_Internal_Linking_Settings::get_stopword_map();
		}

		return array();
	}

	/**
	 * Get enabled keyword sources from settings.
	 *
	 * @return array
	 */
	private static function get_enabled_sources() {
		$settings = class_exists( 'SRK_Internal_Linking_Settings' ) ? SRK_Internal_Linking_Settings::get() : array();
		$sources  = ! empty( $settings['keyword_sources'] ) ? (array) $settings['keyword_sources'] : array( 'custom', 'title', 'slug' );
		$sources  = array_values( array_intersect( array_map( 'sanitize_key', $sources ), array( 'custom', 'title', 'slug', 'taxonomy', 'gsc' ) ) );

		return ! empty( $sources ) ? $sources : array( 'custom', 'title', 'slug' );
	}

	/**
	 * Get enabled taxonomies for the current post type.
	 *
	 * @param int $post_id Post ID.
	 * @return array
	 */
	private static function get_enabled_taxonomies( $post_id ) {
		$settings          = class_exists( 'SRK_Internal_Linking_Settings' ) ? SRK_Internal_Linking_Settings::get() : array();
		$allowed_taxonomies = ! empty( $settings['taxonomies'] ) ? array_map( 'sanitize_key', (array) $settings['taxonomies'] ) : array();
		$post_taxonomies    = get_object_taxonomies( get_post_type( $post_id ), 'names' );

		if ( empty( $allowed_taxonomies ) ) {
			return $post_taxonomies;
		}

		return array_values( array_intersect( $post_taxonomies, $allowed_taxonomies ) );
	}

	/**
	 * Get max keywords per post from settings.
	 *
	 * @return int
	 */
	private static function get_max_keywords_per_post() {
		$settings = class_exists( 'SRK_Internal_Linking_Settings' ) ? SRK_Internal_Linking_Settings::get() : array();

		return ! empty( $settings['max_keywords_per_post'] )
			? min( 100, max( 5, absint( $settings['max_keywords_per_post'] ) ) )
			: self::DEFAULT_MAX_KEYWORDS_PER_POST;
	}

	/**
	 * Get keyword refresh batch size from settings.
	 *
	 * @return int
	 */
	private static function get_batch_size() {
		$settings = class_exists( 'SRK_Internal_Linking_Settings' ) ? SRK_Internal_Linking_Settings::get() : array();

		return ! empty( $settings['batch_size'] )
			? min( 150, max( 1, absint( $settings['batch_size'] ) ) )
			: 25;
	}
}
