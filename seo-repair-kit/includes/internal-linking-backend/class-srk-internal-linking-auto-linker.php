<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * SEO Repair Kit - Internal Linking Auto Linker.
 */
class SRK_Internal_Linking_Auto_Linker {

	/**
	 * Prevent recursive save_post execution.
	 */
	private static $running = false;

	/**
	 * Register hooks.
	 */
	public static function init() {
		add_action( 'save_post', array( __CLASS__, 'maybe_apply_rules_on_save' ), 30, 3 );
	}

	/**
	 * Determine whether Auto Linking is enabled.
	 *
	 * The main Internal Linking settings screen is the global source of truth.
	 * The separate Auto Linking settings can still provide detailed rule options.
	 *
	 * @return bool
	 */
	private static function auto_linking_enabled() {
		if (
			class_exists( 'SRK_Internal_Linking_Settings' ) &&
			method_exists(
				'SRK_Internal_Linking_Settings',
				'is_auto_linking_enabled'
			)
		) {
			return SRK_Internal_Linking_Settings::is_auto_linking_enabled();
		}

		if ( class_exists( 'SRK_Internal_Linking_Settings' ) ) {
			$settings = SRK_Internal_Linking_Settings::get();

			return (
				! empty( $settings['enabled'] ) &&
				! empty( $settings['auto_linking_enabled'] )
			);
		}

		return true;
	}

	/**
	 * Apply active auto-linking rules when a post is saved.
	 */
	public static function maybe_apply_rules_on_save( $post_id, $post, $update ) {
		if ( self::$running || wp_is_post_autosave( $post_id ) || wp_is_post_revision( $post_id ) || ! $post || 'publish' !== $post->post_status ) {
			return;
		}

		if ( ! self::auto_linking_enabled() ) {
			return;
		}

		$settings = SRK_Internal_Linking_DB::get_auto_linking_settings();

		if ( empty( $settings['enabled'] ) || ! empty( $settings['manual_review'] ) ) {
			return;
		}
		

		$rules = SRK_Internal_Linking_DB::get_auto_rules(
			array(
				'status' => 'active',
				'limit'  => 200,
				'offset' => 0,
			)
		);

		foreach ( $rules as $rule ) {
			if ( empty( $rule['auto_apply'] ) ) {
				continue;
			}

			$post_types = self::decode_json_array( $rule['post_types_json'] );

			if ( ! empty( $post_types ) && ! in_array( $post->post_type, $post_types, true ) ) {
				continue;
			}

			self::apply_rule_to_post( absint( $rule['id'] ), $post_id );
		}
	}

	/**
	 * Create a new rule and scan matching posts.
	 */
	public static function create_rule( $data ) {
		if ( ! self::auto_linking_enabled() ) {
			return new WP_Error(
				'srk_auto_linking_disabled',
				__(
					'Auto Linking is disabled. Enable Auto Linking from Internal Linking Settings before creating a rule.',
					'seo-repair-kit'
				)
			);
		}
		$valid = self::validate_rule_data( $data );

		if ( is_wp_error( $valid ) ) {
			return $valid;
		}

		if ( SRK_Internal_Linking_DB::auto_rule_conflict_exists( $data['keyword'], $data['target_url'] ) ) {
			return new WP_Error(
				'srk_auto_duplicate_rule',
				__( 'This keyword already points to this target URL.', 'seo-repair-kit' )
			);
		}

		$rule_id = SRK_Internal_Linking_DB::insert_auto_rule( $data );

		if ( ! $rule_id ) {
			return new WP_Error(
				'srk_auto_db_failed',
				__( 'Unable to save auto-link rule.', 'seo-repair-kit' )
			);
		}

		$scan = self::scan_rule( $rule_id );
		$rule = SRK_Internal_Linking_DB::get_auto_rule( $rule_id );

		if ( $rule && ! empty( $rule['auto_apply'] ) ) {
			self::apply_rule_to_matched_posts( $rule_id, array() );
			// Re-scan after auto-application so returned matches reflect applied state.
			$scan = self::scan_rule( $rule_id );
		}

		return array(
			'rule_id' => $rule_id,
			'matches' => is_wp_error( $scan ) ? array() : $scan['matches'],
		);
	}

	/**
	 * Validate auto-link rule data.
	 */
	public static function validate_rule_data( $data ) {
		$keyword = isset( $data['keyword'] ) ? trim( preg_replace( '/\s+/u', ' ', sanitize_text_field( $data['keyword'] ) ) ) : '';
		$url     = isset( $data['target_url'] ) ? esc_url_raw( $data['target_url'] ) : '';

		if ( '' === $keyword ) {
			return new WP_Error(
				'srk_auto_keyword_required',
				__( 'Keyword is required.', 'seo-repair-kit' )
			);
		}

		if ( '' === $url ) {
			return new WP_Error(
				'srk_auto_url_required',
				__( 'Destination URL is required.', 'seo-repair-kit' )
			);
		}

		if ( false === wp_http_validate_url( $url ) && 0 !== strpos( $url, '/' ) ) {
			return new WP_Error(
				'srk_auto_invalid_url',
				__( 'Destination URL is invalid.', 'seo-repair-kit' )
			);
		}

		$settings = SRK_Internal_Linking_DB::get_auto_linking_settings();

		if ( ! empty( $settings['internal_only'] ) ) {
			$home = wp_parse_url( home_url(), PHP_URL_HOST );
			$host = wp_parse_url( $url, PHP_URL_HOST );

			if ( $host && $home && preg_replace( '/^www\./', '', strtolower( $host ) ) !== preg_replace( '/^www\./', '', strtolower( $home ) ) ) {
				return new WP_Error(
					'srk_auto_external_url',
					__( 'Only internal URLs are allowed by current settings.', 'seo-repair-kit' )
				);
			}
		}

		return true;
	}

	/**
	 * Scan a rule and collect posts containing safe insertable matches.
	 *
	 * The same HTML parser used during insertion is also used during scanning.
	 * This prevents posts from being shown as matched when their keyword exists
	 * only inside headings, existing links, code, navigation or other protected
	 * HTML elements.
	 *
	 * @param int $rule_id Auto-link rule ID.
	 * @return array|WP_Error
	 */
	public static function scan_rule( $rule_id ) {
		$started = microtime( true );
		$rule_id = absint( $rule_id );

		$rule = SRK_Internal_Linking_DB::get_auto_rule(
			$rule_id
		);

		if ( ! $rule ) {
			return new WP_Error(
				'srk_auto_missing_rule',
				__(
					'Auto-link rule not found.',
					'seo-repair-kit'
				)
			);
		}

		$settings = SRK_Internal_Linking_DB::get_auto_linking_settings();

		$post_types = self::decode_json_array(
			$rule['post_types_json'] ?? '[]'
		);

		if ( empty( $post_types ) ) {
			$post_types = ! empty( $settings['default_post_types'] )
				? (array) $settings['default_post_types']
				: array( 'post', 'page' );
		}

		$post_types = array_values(
			array_filter(
				array_map(
					'sanitize_key',
					$post_types
				)
			)
		);

		if ( empty( $post_types ) ) {
			$post_types = array( 'post', 'page' );
		}

		$args = array(
			'post_type'      => $post_types,
			'post_status'    => 'publish',
			'posts_per_page' => 300,
			'fields'         => 'ids',
			'orderby'        => 'ID',
			'order'          => 'DESC',
			'no_found_rows'  => true,
			'has_password'   => false,
		);

		if ( ! empty( $rule['apply_after_date'] ) ) {
			$args['date_query'] = array(
				array(
					'after'     => sanitize_text_field(
						$rule['apply_after_date']
					),
					'inclusive' => true,
				),
			);
		}

		$post_ids = get_posts(
			$args
		);

		$matches = array();

		foreach ( $post_ids as $post_id ) {
			$post_id = absint( $post_id );

			if (
				! empty( $rule['target_post_id'] ) &&
				absint( $rule['target_post_id'] ) === $post_id
			) {
				continue;
			}

			if (
				! self::passes_taxonomy_scope(
					$post_id,
					$rule
				)
			) {
				continue;
			}

			$content = (string) get_post_field(
				'post_content',
				$post_id
			);

			if ( '' === trim( $content ) ) {
				continue;
			}

			$applied_anchors =
				self::get_rule_link_anchors(
						$content,
						$rule_id,
						$rule['keyword'],
						$rule['target_url']
					);
			$is_applied =
				! empty(
					$applied_anchors
				);

			/*
			* A new match must contain the actual rule keyword
			* in usable post text.
			*
			* This prevents shortcode/configuration markup from
			* appearing as a false content match.
			*/
			if (
				! $is_applied &&
				self::count_keyword_matches(
					$content,
					$rule['keyword'],
					! empty(
						$rule['case_sensitive']
					)
				) < 1
			) {
				continue;
			}	
			if (
				! $is_applied &&
				empty( $rule['allow_duplicate_target'] ) &&
				self::content_has_target_url(
					$content,
					$rule['target_url']
				)
			) {
				continue;
			}

			/*
			* Run the actual insertion engine as a dry scan.
			*
			* Nothing is saved here. We only inspect how many safe replacements
			* would be possible.
			*/
			$preview = self::insert_links_in_html(
				$content,
				$rule['keyword'],
				$rule['target_url'],
				max(
					1,
					absint(
						$rule['max_links_per_post']
					)
				),
				max(
					1,
					absint(
						$rule['max_links_per_keyword']
					)
				),
				! empty( $rule['case_sensitive'] ),
				$rule_id
			);

			$count = absint(
				$preview['count'] ?? 0
			);

			/*
			* Do not display false matches.
			*
			* Already-applied rows are still included for removal controls.
			*/
			if (
				! $is_applied &&
				$count < 1
			) {
				continue;
			}

			$matches[] = array(
				'post_id' =>
					$post_id,

				'title' =>
					get_the_title(
						$post_id
					),

				'edit_link' =>
					get_edit_post_link(
						$post_id,
						''
					),

				'view_link' =>
					get_permalink(
						$post_id
					),

				'url' =>
					get_permalink(
						$post_id
					),

				/*
				* For an existing applied link, display the anchor
				* that actually exists in post content.
				*
				* Do not incorrectly display the rule's current
				* keyword when that rule has been edited later.
				*/
				'matched_keyword' =>
					$is_applied
						? implode(
							', ',
							$applied_anchors
						)
						: sanitize_text_field(
							$rule['keyword']
						),

				'matches' =>
					$is_applied
						? count(
							$applied_anchors
						)
						: $count,

				'is_applied' =>
					$is_applied
						? 1
						: 0,
			);
		}

		SRK_Internal_Linking_DB::update_auto_rule_scan_data(
			$rule_id,
			array(
				'matched_posts_json' => $matches,
				'last_scan_at'       => SRK_Internal_Linking_DB::get_now(),
				'last_scan_duration' => round(
					microtime( true ) - $started,
					4
				),
			)
		);

		return array(
			'rule_id' => $rule_id,
			'matches' => $matches,
			'rows'    => $matches,
		);
	}

	/**
	 * Apply a rule to matched posts.
	 *
	 * @param int   $rule_id  Auto-link rule ID.
	 * @param int[] $post_ids Selected post IDs.
	 * @return array|WP_Error
	 */
	public static function apply_rule_to_matched_posts(
		$rule_id,
		$post_ids = array()
	) {
		if ( ! self::auto_linking_enabled() ) {
			return new WP_Error(
				'srk_auto_linking_disabled',
				__(
					'Auto Linking is disabled. Enable it before applying rules.',
					'seo-repair-kit'
				)
			);
		}

		$rule_id = absint( $rule_id );

		$rule = SRK_Internal_Linking_DB::get_auto_rule(
			$rule_id
		);

		if ( ! $rule ) {
			return new WP_Error(
				'srk_auto_missing_rule',
				__(
					'Auto-link rule not found.',
					'seo-repair-kit'
				)
			);
		}

		if ( empty( $post_ids ) ) {
			$matches = self::decode_json_array(
				$rule['matched_posts_json'] ?? '[]'
			);

			foreach ( $matches as $match ) {
				if (
					! empty( $match['is_applied'] ) ||
					empty( $match['post_id'] )
				) {
					continue;
				}

				$post_ids[] = absint(
					$match['post_id']
				);
			}
		}

		$post_ids = array_values(
			array_unique(
				array_filter(
					array_map(
						'absint',
						(array) $post_ids
					)
				)
			)
		);

		$processed = 0;
		$inserted  = 0;
		$failed    = 0;
		$errors    = array();

		foreach ( $post_ids as $post_id ) {
			$processed++;

			$result = self::apply_rule_to_post(
				$rule_id,
				$post_id
			);

			if ( is_wp_error( $result ) ) {
				$failed++;

				$errors[] = array(
					'post_id'    => absint( $post_id ),
					'post_title' => get_the_title( $post_id ),
					'code'       => $result->get_error_code(),
					'message'    => $result->get_error_message(),
				);

				continue;
			}

			$inserted += absint(
				$result['inserted'] ?? 0
			);
		}

		/*
		* Refresh scan results after application.
		*/
		self::scan_rule(
			$rule_id
		);

		return array(
			'processed' => $processed,
			'inserted'  => $inserted,
			'failed'    => $failed,
			'errors'    => $errors,
		);
	}

	/**
	 * Apply one rule to one post.
	 */
	public static function apply_rule_to_post( $rule_id, $post_id ) {
		if ( ! self::auto_linking_enabled() ) {
			return new WP_Error(
				'srk_auto_linking_disabled',
				__(
					'Auto Linking is disabled. No links were inserted.',
					'seo-repair-kit'
				)
			);
		}
		$rule = SRK_Internal_Linking_DB::get_auto_rule( $rule_id );
		$post = get_post( $post_id );

		if ( ! $rule || ! $post || 'publish' !== $post->post_status ) {
			return new WP_Error(
				'srk_auto_invalid',
				__( 'Rule or post is invalid.', 'seo-repair-kit' )
			);
		}

		if ( ! current_user_can( 'edit_post', $post_id ) ) {
			return new WP_Error(
				'srk_auto_apply_permission',
				__( 'You do not have permission to edit this post.', 'seo-repair-kit' )
			);
		}

		/*
		* Never apply an auto link unless the current post
		* actually contains the rule keyword.
		*/
		if (
			self::count_keyword_matches(
				$post->post_content,
				$rule['keyword'],
				! empty(
					$rule['case_sensitive']
				)
			) < 1
		) {
			return new WP_Error(
				'srk_auto_no_keyword_match',
				__(
					'The keyword is not present in this post.',
					'seo-repair-kit'
				)
			);
		}

		if ( ! empty( $rule['target_post_id'] ) && absint( $rule['target_post_id'] ) === absint( $post_id ) ) {
			return new WP_Error(
				'srk_auto_self_link',
				__( 'Self-linking is not allowed.', 'seo-repair-kit' )
			);
		}

		if ( ! empty( $rule['require_target_published'] ) && ! empty( $rule['target_post_id'] ) ) {
			$target = get_post( absint( $rule['target_post_id'] ) );

			if ( ! $target || 'publish' !== $target->post_status ) {
				return new WP_Error(
					'srk_auto_target_unpublished',
					__( 'Target post is not published.', 'seo-repair-kit' )
				);
			}
		}

		if ( empty( $rule['allow_duplicate_target'] ) && self::content_has_target_url( $post->post_content, $rule['target_url'] ) ) {
			return new WP_Error(
				'srk_auto_duplicate_target',
				__( 'Target URL already exists in this post.', 'seo-repair-kit' )
			);
		}

		$result = self::insert_links_in_html(
			$post->post_content,
			$rule['keyword'],
			$rule['target_url'],
			max( 1, absint( $rule['max_links_per_post'] ) ),
			max( 1, absint( $rule['max_links_per_keyword'] ) ),
			! empty( $rule['case_sensitive'] ),
			$rule_id
		);

		if ( empty( $result['count'] ) || $result['html'] === $post->post_content ) {
			return new WP_Error(
				'srk_auto_no_match',
				__( 'No safe keyword match found.', 'seo-repair-kit' )
			);
		}

		self::$running = true;

		try {
			$updated = wp_update_post(
				array(
					'ID'           => $post_id,
					'post_content' => $result['html'],
				),
				true
			);
		} finally {
			self::$running = false;
		}

		if ( is_wp_error( $updated ) ) {
			return $updated;
		}

		$tracking = self::decode_json_array( $rule['applied_links_json'] );

		for ( $i = 0; $i < $result['count']; $i++ ) {
			$tracking[] = array(
				'post_id'     => absint( $post_id ),
				'anchor_text' => $rule['keyword'],
				'target_url'  => $rule['target_url'],
				'status'      => 'active',
				'created_at'  => SRK_Internal_Linking_DB::get_now(),
				'user_id'     => get_current_user_id(),
			);
		}

		SRK_Internal_Linking_DB::update_auto_rule_tracking(
			$rule_id,
			$tracking,
			absint( $result['count'] ),
			0
		);

		return array(
			'inserted' => absint( $result['count'] ),
		);
	}

	/**
	 * Insert auto links into safe HTML text nodes.
	 */
	private static function insert_links_in_html( $html, $keyword, $url, $max_post, $max_keyword, $case_sensitive, $rule_id ) {
		$html     = (string) $html;
		$keyword  = trim( (string) $keyword );
		$url      = esc_url_raw( $url );
		$rule_id  = absint( $rule_id );
		$max_post = max( 1, absint( $max_post ) );

		$max_keyword = max(
			1,
			absint( $max_keyword )
		);

		if (
			'' === $html ||
			'' === $keyword ||
			'' === $url ||
			! $rule_id
		) {
			return array(
				'html'  => $html,
				'count' => 0,
			);
		}

		/*
		* Text inside these HTML elements must not be changed.
		*/
		$protected_tags = array(
			'a',
			'script',
			'style',
			'textarea',
			'select',
			'option',
			'button',
			'code',
			'pre',
			'h1',
			'h2',
			'h3',
			'h4',
			'h5',
			'h6',
			'nav',
			'header',
			'footer',
			'noscript',
			'svg',
			'math',
		);

		/*
		* HTML void elements do not have closing tags.
		*
		* The previous parser added tags such as <br> and <img> to the stack.
		* A later closing tag then removed the wrong element, corrupting the
		* parser state and causing valid paragraph text to be skipped.
		*/
		$void_tags = array(
			'area',
			'base',
			'br',
			'col',
			'embed',
			'hr',
			'img',
			'input',
			'link',
			'meta',
			'param',
			'source',
			'track',
			'wbr',
		);

		$parts = preg_split(
			'/(<[^>]+>)/u',
			$html,
			-1,
			PREG_SPLIT_DELIM_CAPTURE
		);

		if ( ! is_array( $parts ) ) {
			return array(
				'html'  => $html,
				'count' => 0,
			);
		}

		$flags = $case_sensitive
			? 'u'
			: 'iu';

		$pattern =
			'/(?<![\p{L}\p{N}])(' .
			preg_quote( $keyword, '/' ) .
			')(?![\p{L}\p{N}])/' .
			$flags;

		$count         = 0;
		$keyword_count = 0;
		$stack         = array();

		foreach ( $parts as $index => $part ) {
			if (
				$count >= $max_post ||
				$keyword_count >= $max_keyword
			) {
				break;
			}

			/*
			* Preserve Gutenberg block comments, HTML comments, declarations and
			* processing instructions.
			*/
			if (
				0 === strpos( $part, '<!--' ) ||
				0 === strpos( $part, '<!' ) ||
				0 === strpos( $part, '<?' )
			) {
				continue;
			}

			/*
			* Handle a closing tag.
			*
			* The previous implementation used array_pop(), which removed the
			* latest stack item whether it matched the actual closing tag or not.
			*/
			if (
				preg_match(
					'/^<\s*\/\s*([a-z][a-z0-9:-]*)\b[^>]*>$/i',
					$part,
					$closing_match
				)
			) {
				$closing_tag = strtolower(
					$closing_match[1]
				);

				for (
					$stack_index = count( $stack ) - 1;
					$stack_index >= 0;
					$stack_index--
				) {
					if (
						$stack[ $stack_index ] !==
						$closing_tag
					) {
						continue;
					}

					/*
					* Remove the matching element and any malformed nested
					* elements above it.
					*/
					array_splice(
						$stack,
						$stack_index
					);

					break;
				}

				continue;
			}

			/*
			* Handle an opening tag.
			*/
			if (
				preg_match(
					'/^<\s*([a-z][a-z0-9:-]*)\b[^>]*>$/i',
					$part,
					$opening_match
				)
			) {
				$opening_tag = strtolower(
					$opening_match[1]
				);

				$is_self_closing = (
					(bool) preg_match(
						'/\/\s*>$/',
						$part
					) ||
					in_array(
						$opening_tag,
						$void_tags,
						true
					)
				);

				if ( ! $is_self_closing ) {
					$stack[] = $opening_tag;
				}

				continue;
			}

			if ( '' === trim( $part ) ) {
				continue;
			}

			if (
				! empty(
					array_intersect(
						$stack,
						$protected_tags
					)
				)
			) {
				continue;
			}

			$replaced = preg_replace_callback(
				$pattern,
				static function ( $matches ) use (
					$url,
					$rule_id,
					&$count,
					&$keyword_count,
					$max_post,
					$max_keyword
				) {
					if (
						$count >= $max_post ||
						$keyword_count >= $max_keyword
					) {
						return $matches[0];
					}

					$count++;
					$keyword_count++;

					return sprintf(
						'<a href="%1$s" data-srk-auto-link="1" data-srk-auto-rule="%2$d">%3$s</a>',
						esc_url( $url ),
						$rule_id,
						esc_html( $matches[0] )
					);
				},
				$part
			);

			if ( is_string( $replaced ) ) {
				$parts[ $index ] = $replaced;
			}
		}

		return array(
			'html'  => implode( '', $parts ),
			'count' => $count,
		);
	}

	/**
	 * Remove links inserted by one auto-link rule from one post.
	 *
	 * @param int  $rule_id         Rule ID.
	 * @param int  $post_id         Post ID.
	 * @param bool $update_tracking Whether rule tracking should be updated.
	 * @return array|WP_Error
	 */
	public static function remove_rule_links_from_post(
		$rule_id,
		$post_id,
		$update_tracking = true
	) {
		$rule_id = absint( $rule_id );
		$post_id = absint( $post_id );

		if ( ! $rule_id || ! $post_id ) {
			return new WP_Error(
				'srk_auto_invalid_remove_request',
				__(
					'Invalid rule or post ID.',
					'seo-repair-kit'
				)
			);
		}

		$rule = SRK_Internal_Linking_DB::get_auto_rule(
			$rule_id
		);

		if ( ! $rule ) {
			return new WP_Error(
				'srk_auto_missing_rule',
				__(
					'Auto-link rule was not found.',
					'seo-repair-kit'
				)
			);
		}

		$post = get_post(
			$post_id
		);

		if ( ! $post ) {
			return new WP_Error(
				'srk_auto_invalid_post',
				__(
					'Invalid post.',
					'seo-repair-kit'
				)
			);
		}

		if ( ! current_user_can( 'edit_post', $post_id ) ) {
			return new WP_Error(
				'srk_auto_remove_permission',
				__(
					'You do not have permission to edit this post.',
					'seo-repair-kit'
				)
			);
		}

		$result = self::remove_rule_links_from_html(
			$post->post_content,
			$rule_id
		);

		if ( empty( $result['count'] ) ) {
			return new WP_Error(
				'srk_auto_no_removed',
				__(
					'No auto link from this rule was found in the post.',
					'seo-repair-kit'
				)
			);
		}

		self::$running = true;

		try {
			$updated = wp_update_post(
				array(
					'ID'           => $post_id,
					'post_content' => $result['html'],
				),
				true
			);
		} finally {
			self::$running = false;
		}

		if ( is_wp_error( $updated ) ) {
			return $updated;
		}

		$removed_count = absint(
			$result['count']
		);

		/*
		* Update tracking only after WordPress confirms that post content
		* was successfully updated.
		*/
		if ( $update_tracking ) {
			$tracking = self::decode_json_array(
				$rule['applied_links_json'] ?? '[]'
			);

			$remaining_to_mark = $removed_count;

			foreach ( $tracking as &$tracking_item ) {
				if ( $remaining_to_mark <= 0 ) {
					break;
				}

				$item_post_id = absint(
					$tracking_item['post_id'] ?? 0
				);

				$item_status = sanitize_key(
					$tracking_item['status'] ?? 'active'
				);

				if (
					$post_id !== $item_post_id ||
					'active' !== $item_status
				) {
					continue;
				}

				$tracking_item['status']     = 'removed';
				$tracking_item['removed_at'] = SRK_Internal_Linking_DB::get_now();
				$tracking_item['removed_by'] = get_current_user_id();

				$remaining_to_mark--;
			}

			unset( $tracking_item );

			SRK_Internal_Linking_DB::update_auto_rule_removed_tracking(
				$rule_id,
				$tracking,
				$removed_count
			);
		}

		/*
		* Refresh current live links for this post.
		*/
		if (
			class_exists( 'SRK_Internal_Linking_Indexer' ) &&
			method_exists(
				'SRK_Internal_Linking_Indexer',
				'index_single_post'
			)
		) {
			SRK_Internal_Linking_Indexer::index_single_post(
				$post_id
			);
		}

		if ( class_exists( 'SRK_Internal_Linking_DB' ) ) {
			SRK_Internal_Linking_DB::recalculate_inbound_counts();
		}

		return array(
			'removed' => $removed_count,
			'post_id' => $post_id,
			'rule_id' => $rule_id,
		);
	}

	/**
	* Remove all links inserted by one rule.
	*
	* @param int $rule_id Auto-link rule ID.
	* @return array|WP_Error
	*/
	public static function remove_all_rule_links( $rule_id ) {
		$rule_id = absint( $rule_id );

		$rule = SRK_Internal_Linking_DB::get_auto_rule(
			$rule_id
		);

		if ( ! $rule ) {
			return new WP_Error(
				'srk_auto_missing_rule',
				__(
					'Auto-link rule was not found.',
					'seo-repair-kit'
				)
			);
		}

		$tracking = self::decode_json_array(
			$rule['applied_links_json'] ?? '[]'
		);

		$post_ids = array();

		foreach ( $tracking as $tracking_item ) {
			if (
				'active' !== sanitize_key(
					$tracking_item['status'] ?? 'active'
				)
			) {
				continue;
			}

			$post_id = absint(
				$tracking_item['post_id'] ?? 0
			);

			if ( $post_id ) {
				$post_ids[] = $post_id;
			}
		}

		$post_ids = array_values(
			array_unique( $post_ids )
		);

		$removed        = 0;
		$failed         = 0;
		$removed_by_post = array();

		foreach ( $post_ids as $post_id ) {
			$result = self::remove_rule_links_from_post(
				$rule_id,
				$post_id,
				false
			);

			if ( is_wp_error( $result ) ) {
				$failed++;
				continue;
			}

			$post_removed = absint(
				$result['removed'] ?? 0
			);

			$removed += $post_removed;

			$removed_by_post[ $post_id ] = $post_removed;
		}

		if ( $removed > 0 ) {
			foreach ( $removed_by_post as $post_id => $post_removed ) {
				$remaining_to_mark = absint(
					$post_removed
				);

				foreach ( $tracking as &$tracking_item ) {
					if ( $remaining_to_mark <= 0 ) {
						break;
					}

					if (
						absint( $tracking_item['post_id'] ?? 0 ) !== absint( $post_id ) ||
						'active' !== sanitize_key(
							$tracking_item['status'] ?? 'active'
						)
					) {
						continue;
					}

					$tracking_item['status']     = 'removed';
					$tracking_item['removed_at'] = SRK_Internal_Linking_DB::get_now();
					$tracking_item['removed_by'] = get_current_user_id();

					$remaining_to_mark--;
				}

				unset( $tracking_item );
			}

			SRK_Internal_Linking_DB::update_auto_rule_removed_tracking(
				$rule_id,
				$tracking,
				$removed
			);
		}

		return array(
			'removed' => $removed,
			'failed'  => $failed,
		);
	}

	/**
	 * Remove auto-link anchor tags from HTML.
	 */
	private static function remove_rule_links_from_html( $html, $rule_id ) {
		$count   = 0;
		$pattern = '/<a\s+([^>]*data-srk-auto-rule=("|\')' . preg_quote( (string) absint( $rule_id ), '/' ) . '\2[^>]*)>(.*?)<\/a>/isu';

		$html = preg_replace_callback(
			$pattern,
			function( $m ) use ( &$count ) {
				$count++;
				return $m[3];
			},
			(string) $html
		);

		return array(
			'html'  => $html,
			'count' => $count,
		);
	}

	/**
	 * Count keyword matches inside plain post content.
	 */
	private static function count_keyword_matches( $content, $keyword, $case_sensitive ) {
		$plain   = wp_strip_all_tags( strip_shortcodes( (string) $content ) );
		$pattern = '/(?<![\p{L}\p{N}])' . preg_quote( trim( $keyword ), '/' ) . '(?![\p{L}\p{N}])/u' . ( $case_sensitive ? '' : 'i' );

		return preg_match_all( $pattern, $plain );
	}

	/**
	 * Check whether post content already contains an anchor to the target URL.
	 *
	 * Plain-text URL occurrences and unrelated HTML attributes are ignored.
	 *
	 * @param string $content    Post content.
	 * @param string $target_url Destination URL.
	 * @return bool
	 */
	private static function content_has_target_url(
		$content,
		$target_url
	) {
		$content = (string) $content;

		$target_url = self::normalize_comparable_url(
			$target_url
		);

		if (
			'' === $content ||
			'' === $target_url
		) {
			return false;
		}

		$found = preg_match_all(
			'/<a\b[^>]*\bhref\s*=\s*(["\'])(.*?)\1[^>]*>/isu',
			$content,
			$matches
		);

		if (
			! $found ||
			empty( $matches[2] )
		) {
			return false;
		}

		foreach ( $matches[2] as $href ) {
			$href = self::normalize_comparable_url(
				$href
			);

			if (
				'' !== $href &&
				$href === $target_url
			) {
				return true;
			}
		}

		return false;
	}

	/**
	 * Normalize a URL for internal duplicate-link comparisons.
	 *
	 * The URL scheme, www prefix and trailing slash do not create a separate
	 * destination for this comparison.
	 *
	 * @param string $url URL.
	 * @return string
	 */
	private static function normalize_comparable_url( $url ) {
		$url = html_entity_decode(
			trim( (string) $url ),
			ENT_QUOTES | ENT_HTML5,
			get_bloginfo( 'charset' )
		);

		if ( '' === $url ) {
			return '';
		}

		if ( 0 === strpos( $url, '/' ) ) {
			$url = home_url( $url );
		}

		$parts = wp_parse_url(
			$url
		);

		if ( ! is_array( $parts ) ) {
			return '';
		}

		$host = isset( $parts['host'] )
			? strtolower( $parts['host'] )
			: '';

		$host = preg_replace(
			'/^www\./i',
			'',
			$host
		);

		$port = ! empty( $parts['port'] )
			? ':' . absint( $parts['port'] )
			: '';

		$path = isset( $parts['path'] )
			? '/' . ltrim( $parts['path'], '/' )
			: '/';

		if ( '/' !== $path ) {
			$path = untrailingslashit(
				$path
			);
		}

		$query = isset( $parts['query'] )
			? '?' . $parts['query']
			: '';

		return $host . $port . $path . $query;
	}

	/**
	 * Check if content already has link from this rule.
	 */
	private static function content_has_rule_link( $content, $rule_id ) {
		return false !== strpos(
			(string) $content,
			'data-srk-auto-rule="' . absint( $rule_id ) . '"'
		);
	}

	/**
	 * Get the actual anchor text of links inserted by one Auto Linking rule.
	 *
	 * This reads the current post content rather than assuming that the
	 * rule's current keyword is still the same as the inserted anchor.
	 *
	 * @param string $content Post content.
	 * @param int    $rule_id Auto-link rule ID.
	 *
	 * @return array<int,string>
	 */
	private static function get_rule_link_anchors( $content, $rule_id ) {
		$content = (string) $content;
		$rule_id = absint( $rule_id );

		if (
			'' === $content ||
			! $rule_id
		) {
			return array();
		}

		$pattern =
			'/<a\b[^>]*\bdata-srk-auto-rule\s*=\s*' .
			'(["\'])' .
			preg_quote(
				(string) $rule_id,
				'/'
			) .
			'\1[^>]*>(.*?)<\/a>/isu';

		$found =
			preg_match_all(
				$pattern,
				$content,
				$matches,
				PREG_SET_ORDER
			);

		if (
			! $found ||
			empty( $matches )
		) {
			return array();
		}

		$anchors = array();

		foreach ( $matches as $match ) {
			$anchor =
				html_entity_decode(
					(string) (
						$match[2] ?? ''
					),
					ENT_QUOTES | ENT_HTML5,
					get_bloginfo(
						'charset'
					)
				);

			$anchor =
				trim(
					wp_strip_all_tags(
						$anchor
					)
				);

			$anchor =
				preg_replace(
					'/\s+/u',
					' ',
					$anchor
				);

			$anchor =
				trim(
					(string) $anchor
				);

			if ( '' !== $anchor ) {
				$anchors[] =
					$anchor;
			}
		}

		return array_values(
			array_unique(
				$anchors
			)
		);
	}

	/**
	 * Check whether post passes taxonomy/category/tag scope.
	 */
	private static function passes_taxonomy_scope( $post_id, $rule ) {
		$categories = self::decode_json_array( $rule['categories_json'] ?? '[]' );
		$tags       = self::decode_json_array( $rule['tags_json'] ?? '[]' );

		if ( ! empty( $categories ) && ! has_category( array_map( 'absint', $categories ), $post_id ) ) {
			return false;
		}

		if ( ! empty( $tags ) && ! has_tag( array_map( 'absint', $tags ), $post_id ) ) {
			return false;
		}

		return true;
	}

	/**
	 * Decode JSON safely into array.
	 */
	private static function decode_json_array( $json ) {
		if ( is_array( $json ) ) {
			return $json;
		}

		$data = json_decode( (string) $json, true );

		return is_array( $data ) ? $data : array();
	}
}

SRK_Internal_Linking_Auto_Linker::init();
