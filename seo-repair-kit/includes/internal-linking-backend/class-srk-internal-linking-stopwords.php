<?php
/**
 * Multilingual stopword service for Internal Linking.
 *
 * @package SEO_Repair_Kit
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class SRK_Internal_Linking_Stopwords {

	/**
	 * Cached stopword lists, indexed by language.
	 *
	 * @var array<string,array>
	 */
	private static $word_cache = array();

	/**
	 * Cached lookup maps, indexed by language.
	 *
	 * @var array<string,array>
	 */
	private static $map_cache = array();

	/**
	 * Cached multi-word phrases, indexed by language.
	 *
	 * @var array<string,array>
	 */
	private static $phrase_cache = array();

	/**
	 * Supported language definitions.
	 *
	 * @return array<string,array<string,string>>
	 */
	public static function get_supported_languages() {
		return array(
			'english' => array(
				'label' => 'English',
				'file'  => 'EN.txt',
			),
			'spanish' => array(
				'label' => 'Español',
				'file'  => 'ES.txt',
			),
			'french' => array(
				'label' => 'Français',
				'file'  => 'FR.txt',
			),
			'german' => array(
				'label' => 'Deutsch',
				'file'  => 'DE.txt',
			),
			'russian' => array(
				'label' => 'Русский',
				'file'  => 'RU.txt',
			),
			'portuguese' => array(
				'label' => 'Português',
				'file'  => 'PT.txt',
			),
			'dutch' => array(
				'label' => 'Nederlands',
				'file'  => 'NL.txt',
			),
			'danish' => array(
				'label' => 'Dansk',
				'file'  => 'DA.txt',
			),
			'italian' => array(
				'label' => 'Italiano',
				'file'  => 'IT.txt',
			),
			'polish' => array(
				'label' => 'Polski',
				'file'  => 'PL.txt',
			),
			'norwegian' => array(
				'label' => 'Norsk',
				'file'  => 'NO.txt',
			),
			'arabic' => array(
				'label' => 'العربية',
				'file'  => 'AR.txt',
			),
			'finnish' => array(
				'label' => 'Suomi',
				'file'  => 'FI.txt',
			),
			'hebrew' => array(
				'label' => 'עברית',
				'file'  => 'HE.txt',
			),
			'hindi' => array(
				'label' => 'हिन्दी',
				'file'  => 'HI.txt',
			),
			'hungarian' => array(
				'label' => 'Magyar',
				'file'  => 'HU.txt',
			),
			'romanian' => array(
				'label' => 'Română',
				'file'  => 'RO.txt',
			),
			'indonesian' => array(
				'label' => 'Bahasa Indonesia',
				'file'  => 'ID.txt',
			),
			'czech' => array(
				'label' => 'Čeština',
				'file'  => 'CS.txt',
			),
			'bulgarian' => array(
				'label' => 'Български',
				'file'  => 'BG.txt',
			),
		);
	}

	/**
	 * Detect the default language from the WordPress locale.
	 *
	 * @return string
	 */
	public static function detect_site_language() {
		$locale = function_exists( 'determine_locale' )
			? determine_locale()
			: get_locale();

		$locale = strtolower(
			str_replace( '-', '_', (string) $locale )
		);

		$language_code = substr( $locale, 0, 2 );

		$map = array(
			'en' => 'english',
			'es' => 'spanish',
			'fr' => 'french',
			'de' => 'german',
			'ru' => 'russian',
			'pt' => 'portuguese',
			'nl' => 'dutch',
			'da' => 'danish',
			'it' => 'italian',
			'pl' => 'polish',
			'no' => 'norwegian',
			'nb' => 'norwegian',
			'ar' => 'arabic',
			'fi' => 'finnish',
			'he' => 'hebrew',
			'hi' => 'hindi',
			'hu' => 'hungarian',
			'ro' => 'romanian',
			'id' => 'indonesian',
			'cs' => 'czech',
			'bg' => 'bulgarian',
		);

		return isset( $map[ $language_code ] )
			? $map[ $language_code ]
			: 'english';
	}

	/**
	 * Validate a language identifier.
	 *
	 * @param string $language Language identifier.
	 * @return string
	 */
	public static function sanitize_language( $language ) {
		$language = sanitize_key( $language );
		$supported = self::get_supported_languages();

		return isset( $supported[ $language ] )
			? $language
			: 'english';
	}

	/**
	 * Get the language file path.
	 *
	 * @param string $language Language identifier.
	 * @return string
	 */
	public static function get_file_path( $language ) {
		$language  = self::sanitize_language( $language );
		$supported = self::get_supported_languages();

		return trailingslashit( __DIR__ ) .
			'ignore-word-lists/' .
			$supported[ $language ]['file'];
	}

	/**
	 * Clean one stopword entry while preserving its original punctuation.
	 *
	 * This method is used for file loading, settings storage, and UI display.
	 * Apostrophes, accents, and hyphens are intentionally preserved.
	 *
	 * @param string $entry Raw stopword entry.
	 * @return string
	 */
	private static function normalize_entry( $entry ) {
		$entry = wp_strip_all_tags(
			(string) $entry
		);

		$entry = html_entity_decode(
			$entry,
			ENT_QUOTES | ENT_HTML5,
			get_bloginfo( 'charset' )
		);

		/*
		* Remove a possible UTF-8 BOM from the first TXT-file line.
		*/
		$entry = preg_replace(
			'/^\xEF\xBB\xBF/',
			'',
			$entry
		);

		/*
		* Normalize surrounding and repeated whitespace only.
		*
		* Do not remove:
		* - apostrophes;
		* - accented characters;
		* - hyphens;
		* - language-specific punctuation.
		*/
		$entry = preg_replace(
			'/\s+/u',
			' ',
			$entry
		);

		return trim( (string) $entry );
	}

	/**
	 * Sanitize stopword entries without destroying language punctuation.
	 *
	 * @param string|array $words Raw stopword entries.
	 * @return array
	 */
	public static function sanitize_entries( $words ) {
		if ( is_string( $words ) ) {
			$words = preg_split(
				'/[\r\n,]+/u',
				$words,
				-1,
				PREG_SPLIT_NO_EMPTY
			);
		}

		$clean = array();
		$seen  = array();

		foreach ( (array) $words as $entry ) {
			$entry = self::normalize_entry(
				$entry
			);

			if ( '' === $entry ) {
				continue;
			}

			/*
			* Deduplicate through a matching key while preserving the first
			* original display value.
			*/
			$key = self::normalize_word(
				$entry
			);

			if (
				'' === $key ||
				isset( $seen[ $key ] )
			) {
				continue;
			}

			$seen[ $key ] = true;
			$clean[]      = $entry;
		}

		/*
		* Do not sort here. Keep the same order as the TXT file or textarea.
		*/
		return array_values( $clean );
	}

	/**
	 * Load the default stopword file.
	 *
	 * @param string $language Language identifier.
	 * @return array<int,string>
	 */
	public static function load_file_words( $language ) {
		$language = self::sanitize_language( $language );
		$file     = self::get_file_path( $language );

		if ( is_readable( $file ) ) {
			$lines = file(
				$file,
				FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES
			);

			if ( is_array( $lines ) ) {
				return self::sanitize_entries( $lines );
			}
		}

		/*
		 * Non-English files safely fall back to English.
		 */
		if ( 'english' !== $language ) {
			return self::load_file_words( 'english' );
		}

		/*
		 * Final fallback if EN.txt is also unavailable.
		 */
		if (
			class_exists( 'SRK_Internal_Linking_Settings' ) &&
			is_callable(
				array(
					'SRK_Internal_Linking_Settings',
					'default_stopwords',
				)
			)
		) {
			return self::sanitize_entries(
				SRK_Internal_Linking_Settings::default_stopwords()
			);
		}

		return array();
	}

	/**
	 * Return active stopwords for one language.
	 *
	 * A per-language custom override takes precedence over the file.
	 *
	 * @param string     $language Language identifier.
	 * @param array|null $settings Optional settings.
	 * @return array<int,string>
	 */
	public static function get_words( $language = '', $settings = null ) {
		if ( null === $settings ) {
			$settings = class_exists( 'SRK_Internal_Linking_Settings' )
				? SRK_Internal_Linking_Settings::get()
				: array();
		}

		if ( '' === $language ) {
			$language = $settings['selected_language']
				?? self::detect_site_language();
		}

		$language = self::sanitize_language( $language );

		if ( isset( self::$word_cache[ $language ] ) ) {
			return self::$word_cache[ $language ];
		}

		$overrides = isset( $settings['ignore_words_by_language'] )
			&& is_array( $settings['ignore_words_by_language'] )
				? $settings['ignore_words_by_language']
				: array();

		if ( array_key_exists( $language, $overrides ) ) {
			$words = self::sanitize_entries(
				$overrides[ $language ]
			);
		} else {
			$words = self::load_file_words( $language );
		}

		self::$word_cache[ $language ] = $words;

		return $words;
	}

	/**
	 * Return single-word stopwords as a normalized lookup map.
	 *
	 * @param string $language Language code.
	 * @param array  $settings Internal Linking settings.
	 * @return array<string,bool>
	 */
	public static function get_word_map( $language = '', $settings = array() ) {
		if ( '' === $language ) {
			$language = ! empty( $settings['selected_language'] )
				? $settings['selected_language']
				: self::detect_site_language();
		}

		$language = self::sanitize_language( $language );

		if ( isset( self::$map_cache[ $language ] ) ) {
			return self::$map_cache[ $language ];
		}

		$map = array();

		foreach (
			self::get_words(
				$language,
				$settings
			) as $entry
		) {
			$normalized =
				self::normalize_word(
					$entry
				);

			if (
				'' === $normalized ||
				false !== strpos(
					$normalized,
					' '
				)
			) {
				continue;
			}

			$map[ $normalized ] = true;
		}

		self::$map_cache[ $language ] = $map;

		return $map;
	}

	/**
	 * Return normalized multi-word stopword phrases.
	 *
	 * @param string $language Language code.
	 * @param array  $settings Internal Linking settings.
	 * @return array
	 */
	public static function get_phrases(
		$language = '',
		$settings = array()
	) {
		$phrases = array();

		foreach (
			self::get_words(
				$language,
				$settings
			) as $entry
		) {
			$normalized = self::normalize_word(
				$entry
			);

			if (
				'' === $normalized ||
				false === strpos( $normalized, ' ' )
			) {
				continue;
			}

			$phrases[] = $normalized;
		}

		usort(
			$phrases,
			static function ( $first, $second ) {
				return strlen( $second )
					<=> strlen( $first );
			}
		);

		return array_values(
			array_unique( $phrases )
		);
	}

	/**
	 * Extract meaningful words using the active language list.
	 *
	 * @param string    $text           Text to process.
	 * @param bool|null $ignore_numbers Null means use the saved setting.
	 * @param string    $language       Optional language.
	 * @return array<int,string>
	 */
	public static function meaningful_words(
		$text,
		$ignore_numbers = null,
		$language = ''
	) {
		$settings = class_exists( 'SRK_Internal_Linking_Settings' )
			? SRK_Internal_Linking_Settings::get()
			: array();

		if ( '' === $language ) {
			$language = $settings['selected_language']
				?? self::detect_site_language();
		}

		$language = self::sanitize_language( $language );

		if ( null === $ignore_numbers ) {
			$ignore_numbers = ! empty(
				$settings['ignore_numbers']
			);
		}

		$text = wp_strip_all_tags( (string) $text );

		$text = html_entity_decode(
			$text,
			ENT_QUOTES | ENT_HTML5,
			get_bloginfo( 'charset' )
		);

		$text = remove_accents( $text );

		$text = function_exists( 'mb_strtolower' )
			? mb_strtolower( $text, 'UTF-8' )
			: strtolower( $text );

		$text = preg_replace(
			'/[^\p{L}\p{N}\s\-]+/u',
			' ',
			$text
		);

		$text = preg_replace(
			'/\s+/u',
			' ',
			$text
		);

		/*
		 * Remove complete ignored phrases before splitting the text.
		 */
		foreach ( self::get_phrases( $language ) as $phrase ) {
			$pattern = '/(?<![\p{L}\p{N}])' .
				preg_quote( $phrase, '/' ) .
				'(?![\p{L}\p{N}])/iu';

			$text = preg_replace(
				$pattern,
				' ',
				$text
			);
		}

		$tokens = preg_split(
			'/\s+/u',
			trim( $text ),
			-1,
			PREG_SPLIT_NO_EMPTY
		);

		if ( ! is_array( $tokens ) ) {
			return array();
		}

		$stopword_map = self::get_word_map( $language );
		$output       = array();

		foreach ( $tokens as $token ) {
			$token = self::normalize_entry( $token );

			if ( '' === $token ) {
				continue;
			}

			if (
				$ignore_numbers &&
				is_numeric( $token )
			) {
				continue;
			}

			/*
			 * Keep short non-Latin words, but still apply their language
			 * stopword list. The previous implementation incorrectly retained
			 * every non-ASCII stopword.
			 */
			if (
				strlen( $token ) < 3 &&
				! preg_match( '/[^\x00-\x7F]/u', $token )
			) {
				continue;
			}

			if ( isset( $stopword_map[ $token ] ) ) {
				continue;
			}

			$output[] = $token;
		}

		return array_values(
			array_unique( $output )
		);
	}

	/**
	 * Clear request-level caches.
	 *
	 * @return void
	 */
	public static function clear_cache() {
		self::$word_cache   = array();
		self::$map_cache    = array();
		self::$phrase_cache = array();
	}

	/**
	 * Normalize a stopword for internal matching.
	 *
	 * Apostrophes are preserved so contractions such as "can't" do not become
	 * "can t".
	 *
	 * @param string $word Raw stopword.
	 * @return string
	 */
	private static function normalize_word( $word ) {
		$word = wp_strip_all_tags(
			(string) $word
		);

		$word = html_entity_decode(
			$word,
			ENT_QUOTES | ENT_HTML5,
			get_bloginfo( 'charset' )
		);

		/*
		* Make curly and straight apostrophes equivalent.
		*/
		$word = str_replace(
			array(
				'‘',
				'’',
				'ʼ',
				'`',
			),
			"'",
			$word
		);

		$word = function_exists( 'mb_strtolower' )
			? mb_strtolower( $word, 'UTF-8' )
			: strtolower( $word );

		/*
		* Preserve Unicode letters, numbers, apostrophes and hyphens.
		*/
		$word = preg_replace(
			"/[^\p{L}\p{M}\p{N}'\-]+/u",
			' ',
			$word
		);

		return trim(
			preg_replace(
				'/\s+/u',
				' ',
				(string) $word
			)
		);
	}
}