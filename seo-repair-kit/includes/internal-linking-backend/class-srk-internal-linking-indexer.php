<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * SEO Repair Kit Internal Linking content indexer.
 *
 * Scans indexable posts/pages, stores cleaned paragraph content, extracts existing
 * links, updates link counts, and re-indexes changed posts automatically when
 * published content is saved.
 *
 * @package SEO_Repair_Kit
 */
class SRK_Internal_Linking_Indexer {

	/**
	 * Register indexer hooks.
	 *
	 * @return void
	 */
	public static function init() {
		add_action( 'save_post', array( __CLASS__, 'handle_post_save' ), 20, 3 );
		add_action( 'admin_init', array( __CLASS__, 'maybe_run_activation_scan' ) );
	}

	/**
	 * Continue the delayed activation scan from the WordPress admin.
	 *
	 * The activation hook should only mark the scan as pending. This method then
	 * processes one batch per admin request so activation does not timeout on large
	 * websites.
	 *
	 * @return void
	 */
	public static function maybe_run_activation_scan() {
		if ( ! class_exists( 'SRK_License_Helper' ) || ! SRK_License_Helper::is_internal_linking_enabled() ) {
			return;
		}

		if ( ! current_user_can( 'manage_options' ) || ! get_option( 'srk_il_activation_scan_pending' ) ) {
			return;
		}

		if ( ! class_exists( 'SRK_Internal_Linking_DB' ) || ! SRK_Internal_Linking_DB::is_schema_ready() ) {
			return;
		}

		$scan_id = absint( get_option( 'srk_il_activation_scan_id' ) );
		$page    = max( 1, absint( get_option( 'srk_il_activation_scan_page', 1 ) ) );

		if ( ! $scan_id ) {
			$start   = self::start_scan();
			$scan_id = absint( $start['scan_id'] );

			update_option( 'srk_il_activation_scan_id', $scan_id, false );
		}

		$result = self::index_batch( $scan_id, $page );

		if ( ! empty( $result['complete'] ) ) {
			delete_option( 'srk_il_activation_scan_pending' );
			delete_option( 'srk_il_activation_scan_id' );
			delete_option( 'srk_il_activation_scan_page' );
		} else {
			update_option( 'srk_il_activation_scan_page', absint( $result['page'] ), false );
		}
	}

	/**
	 * Re-index a published post when it is saved.
	 *
	 * Also refreshes target keywords and regenerates opportunities where this post
	 * is either the source or target.
	 *
	 * @param int      $post_id Post ID.
	 * @param WP_Post  $post    Saved post object.
	 * @param bool     $update  Whether this is an existing post update.
	 * @return void
	 */
	public static function handle_post_save( $post_id, $post, $update ) {
		if ( ! class_exists( 'SRK_License_Helper' ) || ! SRK_License_Helper::is_internal_linking_enabled() ) {
			return;
		}

		if ( wp_is_post_revision( $post_id ) || wp_is_post_autosave( $post_id ) || ! $post || 'publish' !== $post->post_status ) {
			return;
		}

		if ( ! class_exists( 'SRK_Internal_Linking_DB' ) || ! SRK_Internal_Linking_DB::is_schema_ready() ) {
			return;
		}

		if ( ! in_array( $post->post_type, self::get_indexable_post_types(), true ) ) {
			return;
		}

		$is_elementor_post =
			class_exists( 'SRK_Internal_Linking_Elementor' ) &&
			SRK_Internal_Linking_Elementor::is_elementor_post(
				$post_id
			);

		$can_confirm_editor_links =
			class_exists( 'SRK_Internal_Linking_Opportunities' ) &&
			method_exists(
				'SRK_Internal_Linking_Opportunities',
				'confirm_editor_links_after_save'
			);

		/*
		* Elementor:
		*
		* Apply any staged Internal Linking mutations first.
		* Elementor content is stored separately from normal post_content,
		* so the real Elementor document must be updated before indexing it.
		*/
		if (
			$is_elementor_post &&
			$can_confirm_editor_links
		) {
			SRK_Internal_Linking_Opportunities::confirm_editor_links_after_save(
				$post_id,
				''
			);
		}

		/*
		* Synchronize the actual saved content/link graph.
		*
		* For Elementor, index_single_post() automatically reads real Elementor
		* document content through the Elementor adapter.
		*
		* For Gutenberg, it continues reading saved post_content.
		*/
		self::index_single_post(
			$post_id
		);

		/*
		* Gutenberg:
		*
		* The editor already placed the link into the block content.
		* After WordPress saves that content, verify the exact anchor + target URL
		* before changing the opportunity to inserted.
		*/
		if (
			! $is_elementor_post &&
			$can_confirm_editor_links
		) {
			clean_post_cache(
				$post_id
			);

			$saved_content =
				(string) get_post_field(
					'post_content',
					$post_id,
					'raw'
				);

			SRK_Internal_Linking_Opportunities::confirm_editor_links_after_save(
				$post_id,
				$saved_content
			);
		}

		/*
		* Recalculate inbound counts after the saved link graph is current.
		*/
		if (
			$is_elementor_post &&
			class_exists( 'SRK_Internal_Linking_DB' )
		) {
			SRK_Internal_Linking_DB::recalculate_inbound_counts();
		}

		/*
		* Refresh keywords only after actual content/link state is synchronized.
		*/
		if ( class_exists( 'SRK_Internal_Linking_Keywords' ) ) {
			SRK_Internal_Linking_Keywords::generate_for_post(
				$post_id
			);
		}

		/*
		* Content indexing and keyword storage may continue while suggestion
		* generation is disabled. No opportunity or AI job should be queued.
		*/
		if (
			class_exists( 'SRK_Internal_Linking_Settings' ) &&
			method_exists(
				'SRK_Internal_Linking_Settings',
				'is_enabled'
			) &&
			! SRK_Internal_Linking_Settings::is_enabled()
		) {
			delete_post_meta(
				$post_id,
				'_srk_il_opportunities_stale'
			);

			return;
		}

		// Do not generate all opportunities during post save.
		// This prevents editor/admin slowness on large websites.
		if ( class_exists( 'SRK_Internal_Linking_DB' ) ) {
			$scan_id = 0;

			if (
				class_exists( 'SRK_Internal_Linking_DB' ) &&
				class_exists( 'SRK_Internal_Linking_Queue' )
			) {
				$scan_id = SRK_Internal_Linking_DB::insert_scan_run(
					array(
						'scan_type'   => 'single_opportunities',
						'status'      => 'pending',
						'total_items' => 1,
						'message'     => sprintf(
							/* translators: %d: post ID */
							__(
								'Opportunity refresh queued for post #%d.',
								'seo-repair-kit'
							),
							absint( $post_id )
						),
					)
				);

				if ( $scan_id ) {
					SRK_Internal_Linking_Queue::enqueue(
						SRK_Internal_Linking_Queue::HOOK_SINGLE_OPPORTUNITY,
						array(
							'scan_id' => $scan_id,
							'post_id' => absint( $post_id ),
						)
					);
				}
			}

			/*
			* Mark current suggestions as awaiting a background refresh.
			*/
			update_post_meta(
				$post_id,
				'_srk_il_opportunities_stale',
				1
			);
			if (
				class_exists(
					'SRK_Internal_Linking_Queue'
				) &&
				method_exists(
					'SRK_Internal_Linking_Queue',
					'queue_single_opportunity_refresh'
				)
			) {
				SRK_Internal_Linking_Queue::
					queue_single_opportunity_refresh(
						$post_id,
						5
					);
			}
		}

		update_post_meta( $post_id, '_srk_il_opportunities_stale', 1 );

	}

	/**
	 * Create a new content index scan run.
	 *
	 * @return array Scan payload for AJAX/background processing.
	 */
	public static function start_scan() {
		if ( ! class_exists( 'SRK_License_Helper' ) || ! SRK_License_Helper::is_internal_linking_enabled() ) {
			return array(
				'scan_id'     => 0,
				'total_items' => 0,
				'page'        => 1,
				'status'      => 'disabled',
				'message'     => __( 'Internal Linking is a paid module. Please upgrade or renew Internal Linking to use this feature.', 'seo-repair-kit' ),
			);
		}

		$total = self::count_indexable_posts();
		$id    = SRK_Internal_Linking_DB::insert_scan_run(
			array(
				'scan_type'   => 'content_index',
				'status'      => 'running',
				'total_items' => $total,
				'message'     => __( 'Content indexing started.', 'seo-repair-kit' ),
			)
		);

		return array(
			'scan_id'     => $id,
			'total_items' => $total,
			'page'        => 1,
		);
	}

	/**
	 * Process one content-index batch.
	 *
	 * @param int $scan_id Scan run ID.
	 * @param int $page    Batch page number.
	 * @return array Batch progress payload.
	 */
	public static function index_batch( $scan_id, $page = 1 ) {
		$batch  = self::get_batch_size();
		$page   = max( 1, absint( $page ) );
		$offset = ( $page - 1 ) * $batch;
		$ids    = self::get_indexable_post_ids( $batch, $offset );
		$done   = 0;

		foreach ( $ids as $id ) {
			self::index_post( $id );

			if ( class_exists( 'SRK_Internal_Linking_Keywords' ) ) {
				SRK_Internal_Linking_Keywords::generate_for_post( $id );
			}

			$done++;
		}

		SRK_Internal_Linking_DB::recalculate_inbound_counts();

		$scan      = SRK_Internal_Linking_DB::get_scan_run( $scan_id );
		$total     = absint( $scan['total_items'] ?? 0 );
		$processed = min( $total, $offset + $done );
		$complete  = $done < $batch || $processed >= $total;

		SRK_Internal_Linking_DB::update_scan_run(
			$scan_id,
			array(
				'status'          => $complete ? 'completed' : 'running',
				'processed_items' => $processed,
				'success_items'   => $processed,
				'current_batch'   => $page,
				'completed_at'    => $complete ? SRK_Internal_Linking_DB::get_now() : null,
				'message'         => $complete ? __( 'Content indexing completed.', 'seo-repair-kit' ) : __( 'Indexing content...', 'seo-repair-kit' ),
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
		);
	}

	/**
	 * Index one post by ID.
	 *
	 * @param int $post_id Post ID.
	 * @return bool|int Database result from content-index upsert.
	 */
	public static function index_single_post( $post_id ) {
		return self::index_post( absint( $post_id ) );
	}

	/**
	 * Build and store the content-index record for one post.
	 *
	 * @param int $post_id Post ID.
	 * @return bool|int Database result from content-index upsert.
	 */
	private static function index_post( $post_id ) {
		$post = get_post( $post_id );

		if ( ! $post ) {
			return false;
		}

		$source_content =
			(string) $post->post_content;

		$content_hash = hash(
			'sha256',
			(string) $post->post_title .
			'|' .
			$source_content
		);

		if (
			class_exists(
				'SRK_Internal_Linking_Elementor'
			) &&
			SRK_Internal_Linking_Elementor::
				is_elementor_post(
					$post_id
				)
		) {
			/*
			* Elementor owns the real document content.
			*
			* Do not fall back to WP_Post::post_content because that can contain a
			* plain-text representation of Elementor content that SRK cannot safely
			* write back to the actual Elementor document.
			*/
			$source_content =
				(string) SRK_Internal_Linking_Elementor::
					get_analysis_content(
						$post_id,
						true
					);

			$content_hash =
				SRK_Internal_Linking_Elementor::
					get_content_hash(
						$post_id
					);
		}

		$clean =
			self::clean_content(
				$source_content
			);

		$plain =
			self::plain_text(
				$clean
			);

		$links =
			self::extract_links(
				$source_content,
				$post_id
			);

		SRK_Internal_Linking_DB::delete_links_by_source_post( $post_id );

		$internal = 0;
		$external = 0;

		foreach ( $links as $link ) {
			if ( ! empty( $link['is_internal'] ) ) {
				$internal++;
			} else {
				$external++;
			}

			SRK_Internal_Linking_DB::insert_link( $link );
		}

		$tax = self::get_post_taxonomy_data( $post_id );

		return SRK_Internal_Linking_DB::upsert_content_index(
			array(
				'post_id'                 => $post_id,
				'post_type'               => $post->post_type,
				'post_status'             => $post->post_status,
				'post_title'              => get_the_title( $post_id ),
				'post_url'                => get_permalink( $post_id ),
				'content_hash'            => $content_hash,
				'plain_content'           => $plain,
				'word_count'              => str_word_count( wp_strip_all_tags( $plain ) ),
				'taxonomy_json'           => wp_json_encode( $tax ),
				'internal_outbound_count' => $internal,
				'external_outbound_count' => $external,
				'last_indexed'            => SRK_Internal_Linking_DB::get_now(),
			)
		);
	}

	/**
	 * Remove non-linkable or noisy markup before sentence/anchor analysis.
	 *
	 * @param string $content Raw post content.
	 * @return string Cleaned HTML-ish content.
	 */
	public static function clean_content( $content ) {
		$content = do_shortcode( (string) $content );
		$content = preg_replace( '/<!--.*?-->/s', ' ', $content );
		$content = preg_replace( '/<script\b[^>]*>.*?<\/script>/is', ' ', $content );
		$content = preg_replace( '/<style\b[^>]*>.*?<\/style>/is', ' ', $content );
		$content = preg_replace( '/<nav\b[^>]*>.*?<\/nav>/is', ' ', $content );
		$content = preg_replace( '/<header\b[^>]*>.*?<\/header>/is', ' ', $content );
		$content = preg_replace( '/<footer\b[^>]*>.*?<\/footer>/is', ' ', $content );
		$content = preg_replace( '/<a\b[^>]*>.*?<\/a>/is', ' ', $content );
		$content = preg_replace( '/<button\b[^>]*>.*?<\/button>/is', ' ', $content );
		$content = preg_replace( '/<h[1-6]\b[^>]*>.*?<\/h[1-6]>/is', ' ', $content );
		$content = preg_replace( '/\[[^\]]+\]/', ' ', $content );

		return trim( $content );
	}

	/**
	 * Convert cleaned content to normalized plain text.
	 *
	 * @param string $content Content to normalize.
	 * @return string Plain text.
	 */
	public static function plain_text( $content ) {
		$content = wp_strip_all_tags( $content );
		$content = html_entity_decode( $content, ENT_QUOTES, get_bloginfo( 'charset' ) );

		return trim( preg_replace( '/\s+/u', ' ', $content ) );
	}

	/**
	 * Split content into sentence-level chunks for opportunity discovery.
	 *
	 * @param string $content Content to split.
	 * @return array<int,string> Sentences.
	 */
	public static function split_sentences( $content ) {
		$plain = self::plain_text( $content );

		if ( '' === $plain ) {
			return array();
		}

		$parts = preg_split( '/(?<=[\.\!\?])\s+/u', $plain, -1, PREG_SPLIT_NO_EMPTY );

		return array_values( array_filter( array_map( 'trim', $parts ) ) );
	}

	/**
	 * Extract headings from raw post content.
	 *
	 * @param string $content Raw post content.
	 * @return array<int,array<string,mixed>> Heading records.
	 */
	private static function extract_headings( $content ) {
		$out = array();

		if ( preg_match_all( '/<h([1-6])[^>]*>(.*?)<\/h\1>/is', $content, $matches, PREG_SET_ORDER ) ) {
			foreach ( $matches as $heading ) {
				$out[] = array(
					'level' => absint( $heading[1] ),
					'text'  => wp_strip_all_tags( $heading[2] ),
				);
			}
		}

		return $out;
	}

	/**
	 * Extract existing content links from a post.
	 *
	 * @param string $content Raw post content.
	 * @param int    $post_id Source post ID.
	 * @return array<int,array<string,mixed>> Link records.
	 */
	private static function extract_links( $content, $post_id ) {
		$out = array();

		if ( ! preg_match_all( '/<a\s[^>]*href=["\']([^"\']+)["\'][^>]*>(.*?)<\/a>/is', $content, $matches, PREG_SET_ORDER ) ) {
			return $out;
		}

		foreach ( $matches as $anchor ) {
			$url      = esc_url_raw( html_entity_decode( $anchor[1] ) );
			$target   = self::url_to_post_id( $url );
			$internal = self::is_internal_url( $url );

			$out[] = array(
				'source_post_id' => $post_id,
				'target_post_id' => $target ?: null,
				'target_url'     => $url,
				'anchor_text'    => wp_strip_all_tags( $anchor[2] ),
				'is_internal'    => $internal ? 1 : 0,
			);
		}

		return $out;
	}

	/**
	 * Check whether a URL points to the current site.
	 *
	 * @param string $url URL to check.
	 * @return bool Whether the URL is internal.
	 */
	private static function is_internal_url( $url ) {
		$host = wp_parse_url( home_url(), PHP_URL_HOST );
		$url_host = wp_parse_url( $url, PHP_URL_HOST );

		return empty( $url_host ) || $url_host === $host;
	}

	/**
	 * Resolve an internal URL to a WordPress post ID.
	 *
	 * @param string $url URL to resolve.
	 * @return int Post ID or 0.
	 */
	private static function url_to_post_id( $url ) {
		$id = url_to_postid( $url );

		return $id ? absint( $id ) : 0;
	}

	/**
	 * Get taxonomy metadata for an indexed post.
	 *
	 * @param int $post_id Post ID.
	 * @return array<int,array<string,mixed>> Taxonomy records.
	 */
	private static function get_post_taxonomy_data( $post_id ) {
		$out = array();

		foreach ( get_object_taxonomies( get_post_type( $post_id ), 'names' ) as $taxonomy ) {
			$terms = get_the_terms( $post_id, $taxonomy );

			if ( is_wp_error( $terms ) || empty( $terms ) ) {
				continue;
			}

			foreach ( $terms as $term ) {
				$out[] = array(
					'taxonomy' => $taxonomy,
					'term_id'  => $term->term_id,
					'name'     => $term->name,
					'slug'     => $term->slug,
				);
			}
		}

		return $out;
	}

	/**
	 * Get a paged list of indexable post IDs.
	 *
	 * @param int $limit  Number of posts to fetch.
	 * @param int $offset Offset for the query.
	 * @return array<int,int> Post IDs.
	 */
	private static function get_indexable_post_ids( $limit, $offset ) {
		$query = new WP_Query(
			array(
				'post_type'      => self::get_indexable_post_types(),
				'post_status'    => 'publish',
				'fields'         => 'ids',
				'posts_per_page' => absint( $limit ),
				'offset'         => absint( $offset ),
				'orderby'        => 'ID',
				'order'          => 'ASC',
				'no_found_rows'  => true,
			)
		);

		return array_map( 'absint', $query->posts );
	}

	/**
	 * Count all published posts that should be indexed.
	 *
	 * @return int Total indexable posts.
	 */
	private static function count_indexable_posts() {
		$query = new WP_Query(
			array(
				'post_type'      => self::get_indexable_post_types(),
				'post_status'    => 'publish',
				'fields'         => 'ids',
				'posts_per_page' => 1,
				'no_found_rows'  => false,
			)
		);

		return absint( $query->found_posts );
	}

	/**
	 * Get post types enabled for internal-linking indexing.
	 *
	 * @return array<int,string> Post type names.
	 */
	private static function get_indexable_post_types() {

		$settings = class_exists( 'SRK_Internal_Linking_Settings' )
			? SRK_Internal_Linking_Settings::get()
			: array();

		/**
		 * STEP 1: ONLY USER ALLOWED TYPES (WHITELIST CONTROLLED)
		 */
		$allowed = ! empty( $settings['post_types'] ) && is_array( $settings['post_types'] )
			? array_map( 'sanitize_key', $settings['post_types'] )
			: array( 'post', 'page' );

		/**
		 * STEP 2: GET ALL PUBLIC CPTs
		 */
		$public_post_types = get_post_types(
			array( 'public' => true ),
			'names'
		);

		/**
		 * STEP 3: HARD BLOCKLIST (CRITICAL FOR ELEMENTOR / BUILDERS)
		 */
		$blocked = array(
			'attachment',
			'revision',
			'nav_menu_item',

			// Elementor / Builders
			'elementor_library',
			'elementskit_content',
			'elementskit_template',
			'wp_block',
			'wp_template',
			'wp_template_part',
			'kadence_element',
			'ct_template',
			'oxy_template',
			'bricks_template',
		);

		/**
		 * STEP 4: REMOVE BLOCKED TYPES FROM PUBLIC LIST
		 */
		$clean_public = array_diff( $public_post_types, $blocked );

		/**
		 * STEP 5: FINAL SAFE INTERSECTION
		 */
		$final = array_values(
			array_intersect( $allowed, $clean_public )
		);

		/**
		 * STEP 6: GUARANTEE SAFETY FALLBACK
		 */
		if ( empty( $final ) ) {
			return array( 'post', 'page' );
		}

		return $final;
	}
	/**
	 * Get the number of posts indexed per request.
	 *
	 * @return int Batch size.
	 */
	private static function get_batch_size() {
		$settings = class_exists( 'SRK_Internal_Linking_Settings' ) ? SRK_Internal_Linking_Settings::get() : array();

		return ! empty( $settings['batch_size'] )
			? min( 150, max( 1, absint( $settings['batch_size'] ) ) )
			: 20;
	}
}

SRK_Internal_Linking_Indexer::init();
