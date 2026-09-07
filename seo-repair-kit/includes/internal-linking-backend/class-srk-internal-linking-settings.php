<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class SRK_Internal_Linking_Settings {

	const OPTION_NAME = 'srk_internal_linking_settings';

	public static function defaults() {
		return array(
			'enabled'                => 1,
			'auto_linking_enabled'   => 1,
			'selected_language' => class_exists(
				'SRK_Internal_Linking_Stopwords'
			)
				? SRK_Internal_Linking_Stopwords::detect_site_language()
				: 'english',

			/*
			* Compatibility field for the currently displayed language.
			*/
			'ignore_words' => array(),

			/*
			* User-edited lists are stored separately for every language.
			*/
			'ignore_words_by_language' => array(),
			'skip_existing_links'    => 1,
			'skip_headings'          => 1,
			'skip_html_blocks'       => 1,
			'batch_size'             => 150,
			'post_types'  			 => self::get_default_post_types(),
			'taxonomies'             => array( 'category', 'post_tag' ),
			'post_statuses'          => array( 'publish' ),
			'max_outbound_links'     => 0,
			'max_inbound_links'      => 0,
			'suggestions_limit'      => 10,
			'target_older_than'      => '',
			'source_published_after' => '',
			'same_category_only'     => 0,
			'link_orphaned_only'     => 0,
			'ignore_numbers'         => 1,
			'keyword_sources'        => array( 'custom', 'title', 'slug', ),
			'skip_sentences'         => 0,
			'skip_paragraphs'        => 0,
			'min_anchor_words'       => 2,
			'max_anchor_words'       => 9,
			'max_keywords_per_post'  => 40,
			'ai_enabled'           => 0,
			'openrouter_api_key'   => '',
			'ai_semantic_matching' => 1,
			'ai_batch_size'        => 5,
		);
	}

	public static function get() {
		$saved = get_option(
			self::OPTION_NAME,
			array()
		);

		$saved = is_array( $saved )
			? $saved
			: array();

		$settings = wp_parse_args(
			$saved,
			self::defaults()
		);

		$language = class_exists(
			'SRK_Internal_Linking_Stopwords'
		)
			? SRK_Internal_Linking_Stopwords::sanitize_language(
				$settings['selected_language']
					?? SRK_Internal_Linking_Stopwords::detect_site_language()
			)
			: 'english';

		$settings['selected_language'] = $language;

		/*
		* Backward-compatible runtime field.
		*
		* Do not persist ignore_words in wp_options.
		* Build it from the active language instead.
		*/
		if (
			class_exists(
				'SRK_Internal_Linking_Stopwords'
			)
		) {
			$settings['ignore_words'] =
				SRK_Internal_Linking_Stopwords::get_words(
					$language,
					$settings
				);
		} else {
			$settings['ignore_words'] =
				self::sanitize_stopwords(
					$settings['ignore_words']
						?? self::default_stopwords()
				);
		}

		return $settings;
	}

	/**
	 * Determine whether the Internal Linking module is enabled.
	 *
	 * This is the global feature switch. Rule suggestions, editor suggestions,
	 * orphan opportunities and AI processing must respect this value.
	 *
	 * @return bool
	 */
	public static function is_enabled() {
		if ( ! class_exists( 'SRK_License_Helper' ) || ! SRK_License_Helper::is_internal_linking_enabled() ) {
			return false;
		}

		$settings = self::get();

		return ! empty( $settings['enabled'] );
	}

	/**
	 * Determine whether Auto Linking is globally enabled.
	 *
	 * Auto Linking also requires the main Internal Linking module to be enabled.
	 *
	 * @return bool
	 */
	public static function is_auto_linking_enabled() {
		$settings = self::get();

		return (
			self::is_enabled() &&
			! empty( $settings['auto_linking_enabled'] )
		);
	}

	/**
	 * Sanitize and save Internal Linking settings.
	 *
	 * Stopwords are saved as per-language overrides. Other language overrides are
	 * preserved when the administrator edits the currently selected language.
	 *
	 * @param array $settings Raw submitted settings.
	 * @return array
	 */
	public static function save( $settings ) {
		$settings = is_array( $settings )
			? $settings
			: array();

		$existing = get_option(
			self::OPTION_NAME,
			array()
		);

		$existing = is_array( $existing )
			? $existing
			: array();

		$clean = self::sanitize( $settings );

		$language = class_exists(
			'SRK_Internal_Linking_Stopwords'
		)
			? SRK_Internal_Linking_Stopwords::sanitize_language(
				$clean['selected_language'] ?? 'english'
			)
			: 'english';

		$overrides = isset(
			$existing['ignore_words_by_language']
		) && is_array(
			$existing['ignore_words_by_language']
		)
			? $existing['ignore_words_by_language']
			: array();

		/*
		* Migrate the old single-language option into the new language map.
		*/
		$default_language_words =
			class_exists(
				'SRK_Internal_Linking_Stopwords'
			)
				? SRK_Internal_Linking_Stopwords::load_file_words(
					$language
				)
				: self::default_stopwords();

		/*
		* Backward compatibility:
		*
		* Migrate the old ignore_words value only when it actually
		* differs from the default language file.
		*/
		if (
			empty( $overrides ) &&
			! empty( $existing['ignore_words'] )
		) {
			$legacy_words =
				self::sanitize_stopwords(
					$existing['ignore_words']
				);

			if (
				! self::stopword_lists_match(
					$legacy_words,
					$default_language_words
				)
			) {
				$overrides[ $language ] =
					$legacy_words;
			}
		}

		/*
		* Save a per-language override ONLY when the administrator
		* has actually changed the default TXT-file list.
		*/
		if (
			array_key_exists(
				'ignore_words',
				$settings
			)
		) {
			$submitted_words =
				self::sanitize_stopwords(
					$settings['ignore_words']
				);

			if (
				self::stopword_lists_match(
					$submitted_words,
					$default_language_words
				)
			) {
				/*
				* Same as the TXT file.
				*
				* No reason to duplicate the complete default list
				* inside wp_options.
				*/
				unset(
					$overrides[ $language ]
				);
			} else {
				/*
				* Administrator genuinely customized this language.
				*/
				$overrides[ $language ] =
					$submitted_words;
			}
		}

		/*
		* Explicit reset always returns this language to its TXT file.
		*/
		if (
			! empty(
				$settings['reset_language_stopwords']
			)
		) {
			unset(
				$overrides[ $language ]
			);
		}

		$clean['selected_language'] =
			$language;

		$clean['ignore_words_by_language'] =
			self::sanitize_language_stopword_overrides(
				$overrides
			);

		/*
		* IMPORTANT:
		*
		* ignore_words is now runtime-only compatibility data.
		* Do not persist another copy in wp_options.
		*/
		unset(
			$clean['ignore_words']
		);

		update_option(
			self::OPTION_NAME,
			$clean,
			false
		);

		self::clear_runtime_cache();

		return self::get();
	}

	/**
	 * Reset Internal Linking settings.
	 *
	 * @return array
	 */
	public static function reset() {
		$defaults = self::defaults();

		/*
		* ignore_words is runtime-only compatibility data.
		*/
		unset(
			$defaults['ignore_words']
		);

		update_option(
			self::OPTION_NAME,
			$defaults,
			false
		);

		self::clear_runtime_cache();

		return self::get();
	}

	public static function sanitize( $settings ) {
		$settings = is_array( $settings ) ? $settings : array();
		$min_anchor_words = min(
			9,
			max(
				2,
				absint(
					$settings['min_anchor_words'] ?? 2
				)
			)
		);

		$max_anchor_words = min(
			9,
			max(
				$min_anchor_words,
				absint(
					$settings['max_anchor_words'] ?? 9
				)
			)
		);

		return array(
			'enabled'                => ! empty( $settings['enabled'] ) ? 1 : 0,
			'auto_linking_enabled'   => ! empty( $settings['auto_linking_enabled'] ) ? 1 : 0,
			'selected_language' => class_exists(
				'SRK_Internal_Linking_Stopwords'
			)
				? SRK_Internal_Linking_Stopwords::sanitize_language(
					$settings['selected_language']
						?? SRK_Internal_Linking_Stopwords::detect_site_language()
				)
				: 'english',

			'ignore_words' => self::sanitize_stopwords(
				$settings['ignore_words'] ?? array()
			),

			'ignore_words_by_language' =>
				self::sanitize_language_stopword_overrides(
					$settings['ignore_words_by_language']
						?? array()
				),
			'skip_existing_links'    => ! empty( $settings['skip_existing_links'] ) ? 1 : 0,
			'skip_headings'          => ! empty( $settings['skip_headings'] ) ? 1 : 0,
			'skip_html_blocks'       => ! empty( $settings['skip_html_blocks'] ) ? 1 : 0,
			'batch_size'             => min( 150, max( 1, absint( $settings['batch_size'] ?? 150 ) ) ),
			'post_types' 			 => self::sanitize_key_array( $settings['post_types'] ?? self::get_default_post_types() ),
			'taxonomies'             => self::sanitize_key_array( $settings['taxonomies'] ?? array( 'category', 'post_tag' ) ),
			'post_statuses'          => self::sanitize_key_array( $settings['post_statuses'] ?? array( 'publish' ) ),
			'max_outbound_links'     => absint( $settings['max_outbound_links'] ?? 0 ),
			'max_inbound_links'      => absint( $settings['max_inbound_links'] ?? 0 ),
			'suggestions_limit'      => min( 100, max( 1, absint( $settings['suggestions_limit'] ?? 10 ) ) ),
			'target_older_than'      => sanitize_text_field( $settings['target_older_than'] ?? '' ),
			'source_published_after' => sanitize_text_field( $settings['source_published_after'] ?? '' ),
			'same_category_only'     => ! empty( $settings['same_category_only'] ) ? 1 : 0,
			'link_orphaned_only'     => ! empty( $settings['link_orphaned_only'] ) ? 1 : 0,
			'ignore_numbers'         => ! empty( $settings['ignore_numbers'] ) ? 1 : 0,
			'keyword_sources'        => self::sanitize_keyword_sources( $settings['keyword_sources'] ?? array() ),
			'skip_sentences'         => absint( $settings['skip_sentences'] ?? 0 ),
			'skip_paragraphs'        => absint( $settings['skip_paragraphs'] ?? 0 ),
			'min_anchor_words'       => $min_anchor_words,
	    	'max_anchor_words'       => $max_anchor_words,
			'max_keywords_per_post'  => min( 100, max( 5, absint( $settings['max_keywords_per_post'] ?? 40 ) ) ),
			'ai_enabled' => ! empty(
					$settings['ai_enabled']
				)
					? 1
					: 0,
				'openrouter_api_key' =>
					self::sanitize_api_key(
						$settings['openrouter_api_key']
							?? ''
					),
			'ai_semantic_matching' => 1,
			'ai_batch_size'          => min( 10, max( 5, absint( $settings['ai_batch_size'] ?? 5 ) ) ),
		);
	}

	/**
	 * Get default post types for Internal Linking.
	 *
	 * Only standard WordPress Posts and Pages are enabled by default.
	 * Administrators can enable additional public post types from Settings.
	 *
	 * @return array<int,string>
	 */
	public static function get_default_post_types() {
		return array(
			'post',
			'page',
		);
	}
	
	/**
	 * Sanitize all per-language stopword overrides.
	 *
	 * @param array $overrides Raw overrides.
	 * @return array<string,array<int,string>>
	 */
	private static function sanitize_language_stopword_overrides( $overrides ) {
		if ( ! is_array( $overrides ) ) {
			return array();
		}

		$clean = array();

		foreach ( $overrides as $language => $words ) {
			if (
				! class_exists(
					'SRK_Internal_Linking_Stopwords'
				)
			) {
				continue;
			}

			$language =
				SRK_Internal_Linking_Stopwords::sanitize_language(
					$language
				);

			$clean[ $language ] =
				self::sanitize_stopwords( $words );
		}

		return $clean;
	}

	public static function default_stopwords() {
		return array(
			'a', 'an', 'and', 'are', 'as', 'at', 'the',
			'be', 'been', 'being', 'but', 'by',
			'can', 'could',
			'did', 'do', 'does', 'doing', 'done', 'down', 'during',
			'each', 'even',
			'few', 'first', 'for', 'from', 'further',
			'had', 'has', 'have', 'having', 'he', 'her', 'here', 'hers', 'him', 'his', 'how',
			'i', 'if', 'in', 'into', 'is', 'it', 'its', 'itself',
			'just',
			'less', 'like',
			'may', 'me', 'more', 'most', 'much', 'my', 'myself',
			'no', 'nor', 'not', 'now',
			'of', 'off', 'on', 'once', 'one', 'only', 'or', 'other', 'our', 'ours', 'out', 'over',
			'per',
			'same', 'she', 'should', 'since', 'so', 'some', 'such',
			'than',
			'their', 'them', 'then', 'there', 'these', 'they', 'that',
			'this', 'those', 'through',
			'under', 'until', 'up', 'use', 'using',
			'very',
			'was', 'we', 'were', 'what', 'when', 'where', 'which', 'while', 'who', 'why',
			'will', 'with', 'within', 'without', 'would',
			'you', 'your', 'yours', 'yourself', 'need',
            'about', 'above', 'across', 'after', 'afterwards', 'again', 'against',
            'almost', 'alone', 'along', 'already', 'also', 'although', 'always',
            'among', 'amongst', 'another', 'any', 'anybody', 'anyhow', 'anyone',
            'anything', 'anyway', 'anywhere',
            'back', 'before', 'beforehand', 'behind', 'below', 'beside',
            'besides', 'between', 'beyond', 'both',
            'certain', 'certainly', 'come', 'comes', 'coming',
            'else', 'elsewhere', 'enough', 'especially', 'etc',
            'far', 'former', 'formerly',
            'generally', 'given', 'go', 'goes', 'going', 'gone',
            'however',
            'indeed', 'inside', 'instead',
            'keep', 'keeps', 'kept', 'know', 'known',
            'later', 'least', 'let', 'lets',
            'maybe', 'mean', 'means', 'might',
            'near', 'nearly', 'necessary', 'never', 'nevertheless',
            'ok', 'okay',
            'part', 'perhaps', 'please', 'possible', 'possibly',
            'quite',
            'rather', 'really', 'regarding',
            'still', 'suchlike',
            'together', 'toward', 'towards',
            'unless', 'unlike', 'upon', 'usually',
            'via',
            'whether',
            'yes', 'yet', 'best'


		);
	}

	/**
	 * Get active stopwords for the selected or supplied language.
	 *
	 * @param string $language Optional language.
	 * @return array<int,string>
	 */
	public static function get_stopwords( $language = '' ) {
		if (
			! class_exists(
				'SRK_Internal_Linking_Stopwords'
			)
		) {
			$settings = self::get();

			return self::sanitize_stopwords(
				$settings['ignore_words']
					?? self::default_stopwords()
			);
		}

		$settings = self::get();

		if ( '' === $language ) {
			$language = $settings['selected_language']
				?? SRK_Internal_Linking_Stopwords::detect_site_language();
		}

		return SRK_Internal_Linking_Stopwords::get_words(
			$language,
			$settings
		);
	}

	/**
	 * Get active single-word stopwords as a lookup map.
	 *
	 * @param string $language Optional language.
	 * @return array<string,bool>
	 */
	public static function get_stopword_map( $language = '' ) {
		if (
			class_exists(
				'SRK_Internal_Linking_Stopwords'
			)
		) {
			if ( '' === $language ) {
				$settings = self::get();

				$language = $settings['selected_language']
					?? SRK_Internal_Linking_Stopwords::detect_site_language();
			}

			return SRK_Internal_Linking_Stopwords::get_word_map(
				$language
			);
		}

		$map = array();

		foreach ( self::get_stopwords() as $word ) {
			$map[ $word ] = true;
		}

		return $map;
	}

	/**
	 * Sanitize stopwords from an array, comma list, or newline list.
	 *
	 * @param array|string $words Raw words.
	 * @return array<int,string>
	 */
	public static function sanitize_stopwords( $words ) {
		if (
			class_exists(
				'SRK_Internal_Linking_Stopwords'
			)
		) {
			return SRK_Internal_Linking_Stopwords::sanitize_entries(
				$words
			);
		}

		if ( is_string( $words ) ) {
			$words = preg_split(
				'/[\r\n,]+/u',
				$words,
				-1,
				PREG_SPLIT_NO_EMPTY
			);
		}

		$clean = array();

		foreach ( (array) $words as $word ) {
			$word = self::normalize_word( $word );

			if ( '' !== $word ) {
				$clean[] = $word;
			}
		}

		return array_values(
			array_unique( $clean )
		);
	}

	public static function normalize_word( $word ) {
		$word = wp_strip_all_tags( (string) $word );
		$word = html_entity_decode( $word, ENT_QUOTES | ENT_HTML5, get_bloginfo( 'charset' ) );
		$word = remove_accents( $word );
		$word = function_exists( 'mb_strtolower' ) ? mb_strtolower( $word, 'UTF-8' ) : strtolower( $word );
		$word = preg_replace( '/[^\p{L}\p{N}\-]+/u', '', $word );

		return trim( sanitize_text_field( $word ) );
	}

	/**
	 * Remove active stopwords from an existing word array.
	 *
	 * @param array  $words Word array.
	 * @param string $language Optional language.
	 * @return array<int,string>
	 */
	public static function remove_stopwords_from_words( $words, $language = '' ) {
		$stopwords = self::get_stopword_map(
			$language
		);

		return array_values(
			array_filter(
				array_map(
					array(
						__CLASS__,
						'normalize_word',
					),
					(array) $words
				),
				static function ( $word ) use ( $stopwords ) {
					return (
						'' !== $word &&
						! isset( $stopwords[ $word ] )
					);
				}
			)
		);
	}

	/**
	 * Extract meaningful words using the selected language stopword list.
	 *
	 * @param string    $text Text to process.
	 * @param bool|null $ignore_numbers Null uses the saved setting.
	 * @param string    $language Optional language.
	 * @return array<int,string>
	 */
	public static function meaningful_words( $text, $ignore_numbers = null, $language = '' ) {
		if (
			class_exists(
				'SRK_Internal_Linking_Stopwords'
			)
		) {
			return SRK_Internal_Linking_Stopwords::meaningful_words(
				$text,
				$ignore_numbers,
				$language
			);
		}

		return array();
	}

	private static function sanitize_key_array( $items ) {
		$items = is_array( $items ) ? $items : array();

		return array_values(
			array_unique(
				array_filter(
					array_map(
						'sanitize_key',
						$items
					)
				)
			)
		);
	}

	private static function sanitize_keyword_sources( $sources ) {
		$sources = self::sanitize_key_array( $sources );

		$sources = array_values(
			array_intersect(
				$sources,
				array( 'custom', 'title', 'slug', 'taxonomy', 'gsc' )
			)
		);

		return ! empty( $sources ) ? $sources : array( 'custom', 'title', 'slug' );
	}

	/**
	 * Determine whether two stopword lists contain the same entries.
	 *
	 * Order does not matter.
	 *
	 * @param array $first  First stopword list.
	 * @param array $second Second stopword list.
	 * @return bool
	 */
	private static function stopword_lists_match( $first, $second ) {
		$first = self::sanitize_stopwords(
			$first
		);

		$second = self::sanitize_stopwords(
			$second
		);

		sort(
			$first,
			SORT_STRING
		);

		sort(
			$second,
			SORT_STRING
		);

		return $first === $second;
	}

	/**
	 * Sanitize the OpenRouter API key.
	 *
	 * Preserve the current real key when the settings form sends back the
	 * masked display value.
	 *
	 * @param string $key Submitted API key.
	 * @return string
	 */
	public static function sanitize_api_key( $key ) {
		$key = sanitize_text_field(
			(string) $key
		);

		$stored = get_option(
			self::OPTION_NAME,
			array()
		);

		$stored = is_array( $stored )
			? $stored
			: array();

		$existing_key = sanitize_text_field(
			$stored['openrouter_api_key'] ?? ''
		);

		$masked_existing_key = self::mask_api_key(
			$existing_key
		);

		/*
		* Empty input means no replacement was submitted.
		*/
		if ( '' === $key ) {
			return $existing_key;
		}

		/*
		* Preserve the real key when the browser submits the masked value.
		*/
		if (
			'' !== $existing_key &&
			hash_equals(
				$masked_existing_key,
				$key
			)
		) {
			return $existing_key;
		}

		/*
		* Backward compatibility with older placeholders.
		*/
		if (
			'********' === $key ||
			0 === strpos( $key, '****' )
		) {
			return $existing_key;
		}

		return $key;
	}

	/**
	 * Mask API key for display in admin UI.
	 *
	 * @param string $key API key.
	 * @return string
	 */
	public static function mask_api_key( $key ) {
		$key = (string) $key;

		if ( strlen( $key ) < 8 ) {
			return $key ? '********' : '';
		}

		return substr( $key, 0, 4 ) . str_repeat( '*', max( 4, strlen( $key ) - 8 ) ) . substr( $key, -4 );
	}

	/**
	 * Clear cached data affected by settings.
	 *
	 * @return void
	 */
	private static function clear_runtime_cache() {
		delete_transient( 'srk_il_ai_status' );

		if (
			class_exists(
				'SRK_Internal_Linking_Stopwords'
			)
		) {
			SRK_Internal_Linking_Stopwords::clear_cache();
		}
	}
}