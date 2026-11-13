<?php
/**
 * Safe plain-text extraction from post content for meta and schema generation.
 *
 * @package SEO_Repair_Kit
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class SRK_Content_Extractor {

	/**
	 * Plain text of a post's content, with no third-party filters run.
	 *
	 * Deliberately never calls apply_filters( 'the_content' ). Page builders hook
	 * that filter to render a whole document regardless of the string passed in
	 * (Elementor's apply_builder_in_content is one), so calling it from wp_head
	 * renders every widget on the page an extra time. That is slow, and it breaks
	 * plugins whose templates are written to render once per request. Blocks and
	 * shortcodes are handled locally instead.
	 *
	 * @param int $post_id Post ID.
	 * @return string
	 */
	public static function get_plain_content( $post_id ) {
		$raw = (string) get_post_field( 'post_content', (int) $post_id );

		if ( '' === trim( $raw ) ) {
			return '';
		}

		$text = strip_shortcodes( $raw );

		if ( function_exists( 'excerpt_remove_blocks' ) ) {
			$text = excerpt_remove_blocks( $text );
		}

		$text = wp_strip_all_tags( $text );
		$text = str_replace( array( '&nbsp;', "\xc2\xa0" ), ' ', $text );
		$text = preg_replace( "/\r\n|\r/", "\n", $text );

		return trim( preg_replace( '/[ \t]+/', ' ', $text ) );
	}

	/**
	 * First paragraph of a post's content as plain text.
	 *
	 * @param int $post_id Post ID.
	 * @return string
	 */
	public static function get_first_paragraph( $post_id ) {
		$text = self::get_plain_content( $post_id );

		if ( '' === $text ) {
			return '';
		}

		$parts = preg_split( "/\n\s*\n/", $text );
		$first = ( is_array( $parts ) && '' !== trim( (string) $parts[0] ) ) ? (string) $parts[0] : $text;

		return trim( preg_replace( '/\s+/', ' ', $first ) );
	}
}
