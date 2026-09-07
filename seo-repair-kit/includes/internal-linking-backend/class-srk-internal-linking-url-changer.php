<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class SRK_Internal_Linking_URL_Changer {

	const LOG_PREFIX = '[SRK URL Changer] ';

	/**
	 * Preview URL replacement without modifying content.
	 *
	 * @param string $old_url Old URL/path.
	 * @param string $new_url New URL/path.
	 * @return array|WP_Error
	 */
	public static function dry_run( $old_url, $new_url ) {
		$old_url = trim( wp_unslash( (string) $old_url ) );
		$new_url = trim( wp_unslash( (string) $new_url ) );
		$valid   = self::validate_urls( $old_url, $new_url );

		if ( is_wp_error( $valid ) ) {
			return $valid;
		}

		$posts         = SRK_Internal_Linking_DB::find_posts_containing_url( $old_url, 500, 0 );
		$preview       = array();
		$changed_links = 0;

		foreach ( $posts as $post ) {
			$count = self::count_href_matches( $post['post_content'], $old_url );

			if ( $count < 1 ) {
				continue;
			}

			$changed_links += $count;

			$preview[] = array(
				'post_id'     => absint( $post['ID'] ),
				'post_title'  => get_the_title( $post['ID'] ),
				'post_type'   => sanitize_key( $post['post_type'] ),
				'post_status' => sanitize_key( $post['post_status'] ),
				'edit_url'    => get_edit_post_link( $post['ID'], 'raw' ),
				'count'       => absint( $count ),
			);
		}

		return array(
			'old_url'        => $old_url,
			'new_url'        => $new_url,
			'affected_posts' => count( $preview ),
			'changed_links'  => absint( $changed_links ),
			'posts'          => $preview,
		);
	}

	/**
	 * Replace matching href URLs.
	 *
	 * @param string $old_url Old URL/path.
	 * @param string $new_url New URL/path.
	 * @return array|WP_Error
	 */
	public static function replace( $old_url, $new_url ) {
		$dry_run = self::dry_run( $old_url, $new_url );

		if ( is_wp_error( $dry_run ) ) {
			return $dry_run;
		}

		if ( empty( $dry_run['affected_posts'] ) ) {
			return new WP_Error(
				'no_matches',
				__( 'No matching href links were found.', 'seo-repair-kit' )
			);
		}

		$old_url = $dry_run['old_url'];
		$new_url = $dry_run['new_url'];
		$posts   = SRK_Internal_Linking_DB::find_posts_containing_url( $old_url, 500, 0 );

		$rollback    = array();
		$failed_rows = array();
		$total_links = 0;

		$change_id = SRK_Internal_Linking_DB::insert_url_change(
			array(
				'old_url'       => $old_url,
				'new_url'       => $new_url,
				'status'        => 'running',
				'rollback_json' => array(),
			)
		);

		if ( ! $change_id ) {
			global $wpdb;

			return new WP_Error(
				'log_failed',
				__( 'Could not create URL change log.', 'seo-repair-kit' )
			);
		}

		foreach ( $posts as $post ) {
			$post_id          = absint( $post['ID'] );
			$original_content = (string) $post['post_content'];

			if ( ! current_user_can( 'edit_post', $post_id ) ) {
				$failed_rows[] = array(
					'post_id' => $post_id,
					'error'   => __( 'You do not have permission to edit this post.', 'seo-repair-kit' ),
				);

				continue;
			}

			$replacement = self::build_replacement_manifest(
				$original_content,
				$old_url,
				$new_url
			);

			if ( empty( $replacement['count'] ) || empty( $replacement['patches'] ) ) {
				continue;
			}

			$result = wp_update_post(
				array(
					'ID'           => $post_id,
					'post_content' => $replacement['content'],
				),
				true
			);

			if ( is_wp_error( $result ) ) {
				$failed_rows[] = array(
					'post_id' => $post_id,
					'error'   => $result->get_error_message(),
				);

				continue;
			}

			/*
			 * Read actual content after WordPress save hooks run.
			 */
			$saved_content = (string) get_post_field(
				'post_content',
				$post_id,
				'raw'
			);

			/*
			 * Ensure our recorded href positions still exist.
			 */
			if (
				'' === $saved_content ||
				! self::manifest_matches_content(
					$saved_content,
					$replacement['patches'],
					$new_url
				)
			) {
				$restore_result = self::restore_original_after_failed_tracking(
					$post_id,
					$original_content
				);

				$failed_rows[] = array(
					'post_id' => $post_id,
					'error'   => is_wp_error( $restore_result )
						? $restore_result->get_error_message()
						: __(
							'Saved content did not match the expected URL replacement, so the post was restored.',
							'seo-repair-kit'
						),
				);

				continue;
			}

			/*
			 * Store only compact rollback information.
			 */
			$candidate_rollback = $rollback;

			$candidate_rollback[] = array(
				'post_id'    => $post_id,
				'after_hash' => hash( 'sha256', $saved_content ),
				'patches'    => $replacement['patches'],
			);

			/*
			 * Save rollback metadata after every successful post.
			 */
			$persisted = SRK_Internal_Linking_DB::update_url_change(
				$change_id,
				array(
					'rollback_json' => $candidate_rollback,
				)
			);

			/*
			 * Never leave modified content without Undo metadata.
			 */
			if ( false === $persisted ) {
				$restore_result = self::restore_original_after_failed_tracking(
					$post_id,
					$original_content
				);

				$failed_rows[] = array(
					'post_id' => $post_id,
					'error'   => is_wp_error( $restore_result )
						? $restore_result->get_error_message()
						: __(
							'Rollback metadata could not be saved, so the post was restored.',
							'seo-repair-kit'
						),
				);

				continue;
			}

			$rollback     = $candidate_rollback;
			$total_links += absint( $replacement['count'] );

			self::safe_reindex_post( $post_id );
		}

		SRK_Internal_Linking_DB::recalculate_inbound_counts();

		if ( empty( $rollback ) ) {
			$status = 'failed';
		} elseif ( empty( $failed_rows ) ) {
			$status = 'completed';
		} else {
			$status = 'completed_with_failures';
		}

		SRK_Internal_Linking_DB::update_url_change(
			$change_id,
			array(
				'affected_posts' => count( $rollback ),
				'changed_links'  => absint( $total_links ),
				'failed_count'   => count( $failed_rows ),
				'status'         => $status,
				'rollback_json'  => $rollback,
			)
		);

		delete_transient( 'srk_il_report_url_changer' );

		return array(
			'change_id'      => absint( $change_id ),
			'status'         => $status,
			'affected_posts' => count( $rollback ),
			'changed_links'  => absint( $total_links ),
			'failed_count'   => count( $failed_rows ),
		);
	}

	/**
	 * Undo a URL replacement.
	 *
	 * Complete Undo deletes the history row.
	 * Partial/failed Undo keeps unresolved rollback records.
	 *
	 * @param int $change_id Change ID.
	 * @return array|WP_Error
	 */
	public static function undo( $change_id ) {
		$change_id = absint( $change_id );
		$row       = SRK_Internal_Linking_DB::get_url_change( $change_id );

		if ( empty( $row ) ) {
			return new WP_Error(
				'missing_change',
				__( 'URL change log not found.', 'seo-repair-kit' )
			);
		}

		$status = sanitize_key( $row['status'] ?? '' );

		if ( 'legacy_no_undo' === $status ) {
			return new WP_Error(
				'legacy_no_undo',
				__(
					'This legacy URL change cannot be undone safely with the new rollback format.',
					'seo-repair-kit'
				)
			);
		}

		$allowed_statuses = array(
			'completed',
			'completed_with_failures',
			'undo_partial',
			'undo_failed',
		);

		if ( ! in_array( $status, $allowed_statuses, true ) ) {
			return new WP_Error(
				'not_undoable',
				__( 'This URL change is not currently available for Undo.', 'seo-repair-kit' )
			);
		}

		$rollback = json_decode(
			(string) ( $row['rollback_json'] ?? '[]' ),
			true
		);

		if ( ! is_array( $rollback ) || empty( $rollback ) ) {
			return new WP_Error(
				'missing_rollback',
				__( 'Rollback data is missing for this URL change.', 'seo-repair-kit' )
			);
		}

		$new_url   = (string) ( $row['new_url'] ?? '' );
		$restored  = 0;
		$conflicts = 0;
		$failed    = array();
		$remaining = array();

		foreach ( $rollback as $item ) {
			$post_id    = absint( $item['post_id'] ?? 0 );
			$after_hash = sanitize_text_field( $item['after_hash'] ?? '' );
			$patches    = isset( $item['patches'] ) && is_array( $item['patches'] )
				? $item['patches']
				: array();

			if ( ! $post_id || '' === $after_hash || empty( $patches ) ) {
				$failed[] = array(
					'post_id' => $post_id,
					'error'   => __( 'Rollback metadata is incomplete.', 'seo-repair-kit' ),
				);

				$remaining[] = $item;
				continue;
			}

			$post = get_post( $post_id );

			if ( ! $post ) {
				$failed[] = array(
					'post_id' => $post_id,
					'error'   => __( 'The affected post no longer exists.', 'seo-repair-kit' ),
				);

				$remaining[] = $item;
				continue;
			}

			if ( ! current_user_can( 'edit_post', $post_id ) ) {
				$failed[] = array(
					'post_id' => $post_id,
					'error'   => __( 'You do not have permission to edit this post.', 'seo-repair-kit' ),
				);

				$remaining[] = $item;
				continue;
			}

			$current_content = (string) $post->post_content;
			$current_hash    = hash( 'sha256', $current_content );

			/*
			 * Protect content edited after replacement.
			 */
			if ( ! hash_equals( $after_hash, $current_hash ) ) {
				$conflicts++;
				$remaining[] = $item;
				continue;
			}

			$restored_content = self::reverse_manifest(
				$current_content,
				$patches,
				$new_url
			);

			if ( is_wp_error( $restored_content ) ) {
				$failed[] = array(
					'post_id' => $post_id,
					'error'   => $restored_content->get_error_message(),
				);

				$remaining[] = $item;
				continue;
			}

			$result = wp_update_post(
				array(
					'ID'           => $post_id,
					'post_content' => $restored_content,
				),
				true
			);

			if ( is_wp_error( $result ) ) {
				$failed[] = array(
					'post_id' => $post_id,
					'error'   => $result->get_error_message(),
				);

				$remaining[] = $item;
				continue;
			}

			$restored++;

			self::safe_reindex_post( $post_id );
		}

		SRK_Internal_Linking_DB::recalculate_inbound_counts();

		/*
		 * Successful Undo:
		 * remove history row completely.
		 */
		if ( empty( $remaining ) ) {
			$undo_status = 'undone';
			$deleted     = SRK_Internal_Linking_DB::delete_url_change( $change_id );

			/*
			 * Fallback only if DB deletion fails.
			 */
			if ( ! $deleted ) {
				SRK_Internal_Linking_DB::update_url_change(
					$change_id,
					array(
						'status'        => 'undone',
						'failed_count'  => 0,
						'rollback_json' => array(),
					)
				);
			}
		} else {
			/*
			 * Preserve unresolved items only.
			 */
			$undo_status = $restored > 0
				? 'undo_partial'
				: 'undo_failed';

			SRK_Internal_Linking_DB::update_url_change(
				$change_id,
				array(
					'status'        => $undo_status,
					'failed_count'  => count( $failed ) + $conflicts,
					'rollback_json' => $remaining,
				)
			);
		}

		delete_transient( 'srk_il_report_url_changer' );

		return array(
			'change_id' => $change_id,
			'status'    => $undo_status,
			'restored'  => absint( $restored ),
			'conflicts' => absint( $conflicts ),
			'failed'    => count( $failed ),
			'remaining' => count( $remaining ),
		);
	}

	/**
	 * Count matching href values.
	 *
	 * @param string $content Content.
	 * @param string $old_url Old URL.
	 * @return int
	 */
	private static function count_href_matches( $content, $old_url ) {
		$count = 0;

		preg_match_all(
			'/(\bhref\s*=\s*)(["\'])(.*?)\2/i',
			(string) $content,
			$matches
		);

		foreach ( $matches[3] ?? array() as $href ) {
			if (
				self::normalize_url_for_compare( $href ) ===
				self::normalize_url_for_compare( $old_url )
			) {
				$count++;
			}
		}

		return $count;
	}

	/**
	 * Replace matching href values and create rollback manifest.
	 *
	 * @param string $content Content.
	 * @param string $old_url Old URL.
	 * @param string $new_url New URL.
	 * @return array
	 */
	private static function build_replacement_manifest( $content, $old_url, $new_url ) {
		$href_index = -1;
		$patches    = array();

		$updated = preg_replace_callback(
			'/(\bhref\s*=\s*)(["\'])(.*?)\2/i',
			function ( $matches ) use ( $old_url, $new_url, &$href_index, &$patches ) {
				$href_index++;
				$href = (string) ( $matches[3] ?? '' );

				if (
					self::normalize_url_for_compare( $href ) !==
					self::normalize_url_for_compare( $old_url )
				) {
					return $matches[0];
				}

				$patches[] = array(
					'index'    => $href_index,
					'old_href' => $href,
				);

				return $matches[1] .
					$matches[2] .
					esc_url( $new_url ) .
					$matches[2];
			},
			(string) $content
		);

		if ( ! is_string( $updated ) ) {
			$updated = (string) $content;
			$patches = array();
		}

		return array(
			'content' => $updated,
			'patches' => $patches,
			'count'   => count( $patches ),
		);
	}

	/**
	 * Verify replacement positions after saving.
	 *
	 * @param string $content Content.
	 * @param array  $patches Patches.
	 * @param string $new_url New URL.
	 * @return bool
	 */
	private static function manifest_matches_content( $content, $patches, $new_url ) {
		preg_match_all(
			'/(\bhref\s*=\s*)(["\'])(.*?)\2/i',
			(string) $content,
			$matches,
			PREG_SET_ORDER
		);

		foreach ( (array) $patches as $patch ) {
			$index = isset( $patch['index'] )
				? absint( $patch['index'] )
				: -1;

			if ( ! isset( $matches[ $index ][3] ) ) {
				return false;
			}

			if (
				self::normalize_url_for_compare( $matches[ $index ][3] ) !==
				self::normalize_url_for_compare( $new_url )
			) {
				return false;
			}
		}

		return true;
	}

	/**
	 * Reverse stored href patches.
	 *
	 * @param string $content Content.
	 * @param array  $patches Patches.
	 * @param string $new_url Current new URL.
	 * @return string|WP_Error
	 */
	private static function reverse_manifest( $content, $patches, $new_url ) {
		$patch_map = array();

		foreach ( (array) $patches as $patch ) {
			if ( ! isset( $patch['index'], $patch['old_href'] ) ) {
				return new WP_Error(
					'invalid_rollback_patch',
					__( 'Rollback patch data is incomplete.', 'seo-repair-kit' )
				);
			}

			$patch_map[ absint( $patch['index'] ) ] =
				(string) $patch['old_href'];
		}

		if ( empty( $patch_map ) ) {
			return new WP_Error(
				'empty_rollback_patch',
				__( 'Rollback patch data is empty.', 'seo-repair-kit' )
			);
		}

		$href_index = -1;
		$restored   = 0;
		$invalid    = false;

		$updated = preg_replace_callback(
			'/(\bhref\s*=\s*)(["\'])(.*?)\2/i',
			function ( $matches ) use (
				&$href_index,
				&$restored,
				&$invalid,
				$patch_map,
				$new_url
			) {
				$href_index++;

				if ( ! array_key_exists( $href_index, $patch_map ) ) {
					return $matches[0];
				}

				$current_href = (string) ( $matches[3] ?? '' );

				if (
					self::normalize_url_for_compare( $current_href ) !==
					self::normalize_url_for_compare( $new_url )
				) {
					$invalid = true;
					return $matches[0];
				}

				$restored++;

				return $matches[1] .
					$matches[2] .
					$patch_map[ $href_index ] .
					$matches[2];
			},
			(string) $content
		);

		if (
			! is_string( $updated ) ||
			$invalid ||
			$restored !== count( $patch_map )
		) {
			return new WP_Error(
				'rollback_content_mismatch',
				__(
					'The current post content no longer matches the stored rollback positions.',
					'seo-repair-kit'
				)
			);
		}

		return $updated;
	}

	/**
	 * Restore in-memory original content if rollback tracking fails.
	 *
	 * @param int    $post_id Post ID.
	 * @param string $original_content Original content.
	 * @return int|WP_Error
	 */
	private static function restore_original_after_failed_tracking( $post_id, $original_content ) {
		$result = wp_update_post(
			array(
				'ID'           => absint( $post_id ),
				'post_content' => (string) $original_content,
			),
			true
		);

		self::safe_reindex_post( $post_id );

		return $result;
	}

	/**
	 * Normalize URL/path for matching.
	 *
	 * @param string $url URL.
	 * @return string
	 */
	private static function normalize_url_for_compare( $url ) {
		$url = html_entity_decode(
			trim( (string) $url ),
			ENT_QUOTES | ENT_HTML5,
			get_bloginfo( 'charset' )
		);

		return untrailingslashit( $url );
	}

	/**
	 * Validate replacement URLs.
	 *
	 * @param string $old_url Old URL.
	 * @param string $new_url New URL.
	 * @return true|WP_Error
	 */
	private static function validate_urls( $old_url, $new_url ) {
		if ( '' === $old_url || '' === $new_url ) {
			return new WP_Error(
				'missing_url',
				__( 'Old URL and New URL are required.', 'seo-repair-kit' )
			);
		}

		if (
			self::normalize_url_for_compare( $old_url ) ===
			self::normalize_url_for_compare( $new_url )
		) {
			return new WP_Error(
				'same_url',
				__( 'Old URL and New URL cannot be the same.', 'seo-repair-kit' )
			);
		}

		if (
			! self::is_valid_url_or_path( $old_url ) ||
			! self::is_valid_url_or_path( $new_url )
		) {
			return new WP_Error(
				'invalid_url',
				__(
					'Only valid full URLs or root-relative paths are allowed.',
					'seo-repair-kit'
				)
			);
		}

		return true;
	}

	/**
	 * Validate full URL or root-relative path.
	 *
	 * @param string $url URL/path.
	 * @return bool
	 */
	private static function is_valid_url_or_path( $url ) {
		if ( preg_match( '/[\r\n\t]/', $url ) ) {
			return false;
		}

		if ( 0 === strpos( $url, '/' ) && 0 !== strpos( $url, '//' ) ) {
			return true;
		}

		return (bool) wp_http_validate_url( $url );
	}

	/**
	 * Re-index changed post.
	 *
	 * @param int $post_id Post ID.
	 * @return void
	 */
	private static function safe_reindex_post( $post_id ) {
		$post_id = absint( $post_id );

		if ( ! $post_id ) {
			return;
		}

		try {
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
		} catch ( Throwable $e ) {
			/*
			* Re-index failure is intentionally non-fatal here.
			*
			* The URL replacement itself has already been saved and its rollback
			* information is preserved, so a secondary index refresh failure must
			* not invalidate the completed content update.
			*/
		}
	}
}
