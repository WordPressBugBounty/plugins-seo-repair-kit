<?php
/**
 * Internal Linking database runtime operations.
 *
 * @package SEO_Repair_Kit
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}
// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery
// phpcs:disable WordPress.DB.DirectDatabaseQuery.NoCaching
/**
 * Provides runtime database operations for SRK_Internal_Linking_DB.
 *
 * This trait becomes part of SRK_Internal_Linking_DB, therefore all existing
 * calls such as SRK_Internal_Linking_DB::upsert_keyword() remain unchanged.
 *
 * @since 2.1.12
 */
trait SRK_Internal_Linking_DB_Operations {

	/*
	|--------------------------------------------------------------------------
	| Opportunities
	|--------------------------------------------------------------------------
	*/
	
	/**
	 * Check if an opportunity already exists for source, target and anchor.
	 *
	 * @param int    $source_post_id Source post ID.
	 * @param int    $target_post_id Target post ID.
	 * @param string $anchor_text Anchor text.
	 * @return bool
	 */
	public static function opportunity_exists( $source_post_id, $target_post_id, $anchor_text = '' ) {
		global $wpdb;

		unset( $anchor_text );

		$table = self::get_table_name( 'opportunities' );

		return (bool) $wpdb->get_var(
			$wpdb->prepare(
				"SELECT id
				FROM {$table}
				WHERE source_post_id = %d
					AND target_post_id = %d
					AND status IN ('building', 'pending', 'inserted')
				LIMIT 1",
				absint( $source_post_id ),
				absint( $target_post_id )
			)
		);  // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared,WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- Live duplicate check against a plugin-owned custom table; caching could return stale opportunity state.
	}

	/**
	 * Delete pending opportunities for a source post.
	 *
	 * Inserted/ignored history is preserved.
	 *
	 * @param int $source_post_id Source post ID.
	 * @param int $target_post_id Optional target post ID.
	 * @return int|false
	 */
	public static function delete_pending_opportunities_by_source( $source_post_id, $target_post_id = 0 ) {
		global $wpdb;

		$table          = self::get_table_name( 'opportunities' );
		$source_post_id = absint( $source_post_id );
		$target_post_id = absint( $target_post_id );

		if ( $target_post_id ) {
			return $wpdb->query(
				$wpdb->prepare(
					"DELETE FROM {$table}
					WHERE source_post_id = %d
						AND target_post_id = %d
						AND status IN ('building', 'pending')",
					$source_post_id,
					$target_post_id
				)
			); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		}

		return $wpdb->query(
			$wpdb->prepare(
				"DELETE FROM {$table}
				WHERE source_post_id = %d
					AND status IN ('building', 'pending')",
				$source_post_id
			)
		); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
	}

	/**
	 * Insert a link opportunity.
	 * @param array $data Opportunity data.
	 * @return int Opportunity row ID.
	 */
	public static function insert_opportunity( $data ) {
		/*
		* Backward-compatible wrapper.
		*
		* Existing rule-engine calls may still send only "score". Treat those calls
		* as rule candidates until they are updated to send canonical fields.
		*/
		if ( ! isset( $data['selected_type'] ) ) {
			$data['selected_type'] = 'rule';
		}

		if ( ! isset( $data['final_score'] ) && isset( $data['score'] ) ) {
			$data['final_score'] = absint( $data['score'] );
		}

		if (
			'rule' === sanitize_key( $data['selected_type'] ) &&
			! isset( $data['rule_score'] )
		) {
			$data['rule_score'] = absint( $data['final_score'] ?? $data['score'] ?? 0 );
		}

		if (
			'ai' === sanitize_key( $data['selected_type'] ) &&
			! isset( $data['ai_score'] )
		) {
			$data['ai_score'] = absint( $data['final_score'] ?? $data['score'] ?? 0 );
		}

		return self::upsert_canonical_opportunity( $data );
	}

	/**
	 * Fetch one active canonical opportunity by source-target identity.
	 *
	 * AI rows receive priority when historical duplicate rows exist for the
	 * same source and target post.
	 *
	 * @param int $source_post_id Source post ID.
	 * @param int $target_post_id Target post ID.
	 * @return array|null
	 */
	public static function get_active_canonical_opportunity( $source_post_id, $target_post_id ) {
		global $wpdb;

		$table = self::get_table_name(
			'opportunities'
		);

		return $wpdb->get_row(
			$wpdb->prepare(
				"SELECT *
				FROM {$table}
				WHERE source_post_id = %d
					AND target_post_id = %d
					AND status IN ('building', 'pending')
				ORDER BY
					CASE
						WHEN selected_type = 'ai' THEN 0
						ELSE 1
					END ASC,
					updated_at DESC,
					id DESC
				LIMIT 1",
				absint( $source_post_id ),
				absint( $target_post_id )
			),
			ARRAY_A
		); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
	}

	/**
	 * Insert or update one canonical source-target opportunity.
	 *
	 * Selection policy:
	 * - AI always wins when AI and rule suggestions exist for the same
	 *   source-target pair.
	 * - Scores are used only to compare candidates from the same engine.
	 * - The losing rule score may remain stored as diagnostic evidence.
	 *
	 * @param array $data Opportunity candidate.
	 * @return int Opportunity ID.
	 */
	public static function upsert_canonical_opportunity( $data ) {
		global $wpdb;

		$now = self::get_now();

		$row = wp_parse_args(
			$data,
			array(
				'scan_run_id'    => null,
				'source_post_id' => 0,
				'target_post_id' => 0,
				'anchor_text'    => '',
				'sentence'       => '',
				'reason'         => '',
				'score'          => 0,
				'final_score'    => 0,
				'selected_type'  => 'rule',
				'rule_score'     => null,
				'ai_score'       => null,
				'ai_similarity'  => null,
				'status'         => 'pending',
				'inserted_at'    => null,
				'created_at'     => $now,
				'updated_at'     => $now,
			)
		);

		$row = self::sanitize_canonical_opportunity_data(
			$row
		);

		if (
			! $row['source_post_id'] ||
			! $row['target_post_id'] ||
			$row['source_post_id'] === $row['target_post_id'] ||
			'' === $row['anchor_text']
		) {
			return 0;
		}

		$table = self::get_table_name(
			'opportunities'
		);

		$existing = self::get_active_canonical_opportunity(
			$row['source_post_id'],
			$row['target_post_id']
		);

		/*
		* No current canonical row exists.
		*/
		if ( ! $existing ) {
			$row['score'] = $row['final_score'];

			$inserted = $wpdb->insert(
				$table,
				$row
			);

			return false === $inserted
				? 0
				: absint( $wpdb->insert_id );
		}

		$existing_type = in_array(
			sanitize_key(
				$existing['selected_type'] ?? ''
			),
			array( 'rule', 'ai' ),
			true
		)
			? sanitize_key( $existing['selected_type'] )
			: 'rule';

		$incoming_type = in_array(
			sanitize_key(
				$row['selected_type'] ?? ''
			),
			array( 'rule', 'ai' ),
			true
		)
			? sanitize_key( $row['selected_type'] )
			: 'rule';

		$existing_final_score = absint(
			! empty( $existing['final_score'] )
				? $existing['final_score']
				: ( $existing['score'] ?? 0 )
		);

		$incoming_final_score = absint(
			! empty( $row['final_score'] )
				? $row['final_score']
				: ( $row['score'] ?? 0 )
		);

		$existing_rule_score = (
			array_key_exists( 'rule_score', $existing ) &&
			null !== $existing['rule_score']
		)
			? absint( $existing['rule_score'] )
			: (
				'rule' === $existing_type
					? $existing_final_score
					: null
			);

		$incoming_rule_score = null !== $row['rule_score']
			? absint( $row['rule_score'] )
			: (
				'rule' === $incoming_type
					? $incoming_final_score
					: null
			);

		$existing_ai_score = (
			array_key_exists( 'ai_score', $existing ) &&
			null !== $existing['ai_score']
		)
			? absint( $existing['ai_score'] )
			: (
				'ai' === $existing_type
					? $existing_final_score
					: null
			);

		$incoming_ai_score = null !== $row['ai_score']
			? absint( $row['ai_score'] )
			: (
				'ai' === $incoming_type
					? $incoming_final_score
					: null
			);

		/*
		* Preserve available evidence from both engines.
		*/
		$rule_score = self::max_nullable_score(
			$existing_rule_score,
			$incoming_rule_score
		);

		$ai_score = self::max_nullable_score(
			$existing_ai_score,
			$incoming_ai_score
		);

		$winner        = $existing;
		$selected_type = $existing_type;

		/*
		* AI versus rule:
		*
		* The AI candidate always becomes the canonical suggestion.
		*/
		if (
			'ai' === $incoming_type &&
			'rule' === $existing_type
		) {
			$winner        = $row;
			$selected_type = 'ai';
		} elseif (
			'ai' === $existing_type &&
			'rule' === $incoming_type
		) {
			$winner        = $existing;
			$selected_type = 'ai';
		} elseif (
			'ai' === $incoming_type &&
			'ai' === $existing_type
		) {
			/*
			* Two AI candidates may still be compared by their own AI scores.
			*/
			$incoming_score = null !== $incoming_ai_score
				? absint( $incoming_ai_score )
				: $incoming_final_score;

			$existing_score = null !== $existing_ai_score
				? absint( $existing_ai_score )
				: $existing_final_score;

			if (
				$incoming_score > $existing_score ||
				(
					$incoming_score === $existing_score &&
					self::canonical_candidate_is_stronger(
						$row,
						$existing
					)
				)
			) {
				$winner = $row;
			}

			$selected_type = 'ai';
		} else {
			/*
			* Both suggestions are rule-based.
			*/
			$incoming_score = null !== $incoming_rule_score
				? absint( $incoming_rule_score )
				: $incoming_final_score;

			$existing_score = null !== $existing_rule_score
				? absint( $existing_rule_score )
				: $existing_final_score;

			if (
				$incoming_score > $existing_score ||
				(
					$incoming_score === $existing_score &&
					self::canonical_candidate_is_stronger(
						$row,
						$existing
					)
				)
			) {
				$winner = $row;
			}

			$selected_type = 'rule';
		}

		$final_score = 'ai' === $selected_type
			? absint(
				null !== $ai_score
					? $ai_score
					: (
						$winner['final_score']
						?? $winner['score']
						?? 0
					)
			)
			: absint(
				null !== $rule_score
					? $rule_score
					: (
						$winner['final_score']
						?? $winner['score']
						?? 0
					)
			);

		$final_score = min(
			100,
			max(
				0,
				$final_score
			)
		);

		$winner_similarity = null;

		if (
			'ai' === $selected_type &&
			isset( $winner['ai_similarity'] ) &&
			'' !== (string) $winner['ai_similarity']
		) {
			$winner_similarity = min(
				1,
				max(
					0,
					floatval(
						$winner['ai_similarity']
					)
				)
			);
		}

		/*
		* Maintain pipeline state.
		*/
		$existing_status = sanitize_key(
			$existing['status'] ?? 'building'
		);

		$incoming_status = sanitize_key(
			$row['status'] ?? 'building'
		);

		$status = (
			'pending' === $existing_status ||
			'pending' === $incoming_status
		)
			? 'pending'
			: 'building';

		$update = array(
			'scan_run_id' => ! empty( $winner['scan_run_id'] )
				? absint( $winner['scan_run_id'] )
				: (
					! empty( $existing['scan_run_id'] )
						? absint( $existing['scan_run_id'] )
						: null
				),

			'keyword_id' => ! empty( $winner['keyword_id'] )
				? absint( $winner['keyword_id'] )
				: null,

			'anchor_text' => sanitize_text_field(
				$winner['anchor_text'] ?? ''
			),

			'sentence' => wp_html_excerpt(
				sanitize_text_field(
					$winner['sentence'] ?? ''
				),
				255,
				'…'
			),

			'reason' => sanitize_text_field(
				$winner['reason'] ?? ''
			),

			'score'          => $final_score,
			'final_score'    => $final_score,
			'selected_type'  => $selected_type,
			'rule_score'     => $rule_score,
			'ai_score'       => $ai_score,
			'ai_similarity'  => $winner_similarity,
			'status'         => $status,
			'updated_at'     => $now,
		);

		$result = $wpdb->update(
			$table,
			$update,
			array(
				'id' => absint( $existing['id'] ),
			)
		);

		return false === $result
			? 0
			: absint( $existing['id'] );
	}

	/**
	 * Sanitize canonical opportunity fields.
	 *
	 * @param array $row Raw row.
	 * @return array
	 */
	private static function sanitize_canonical_opportunity_data( $row ) {
		$row['scan_run_id'] = ! empty( $row['scan_run_id'] )
			? absint( $row['scan_run_id'] )
			: null;

		$row['source_post_id'] = absint( $row['source_post_id'] );
		$row['target_post_id'] = absint( $row['target_post_id'] );

		$row['anchor_text'] = sanitize_text_field( $row['anchor_text'] );

		$row['sentence'] = wp_html_excerpt(
			sanitize_text_field( $row['sentence'] ),
			255,
			'…'
		);

		$row['reason'] = sanitize_text_field( $row['reason'] );

		$row['selected_type'] = in_array(
			sanitize_key( $row['selected_type'] ),
			array( 'rule', 'ai' ),
			true
		)
			? sanitize_key( $row['selected_type'] )
			: 'rule';

		$row['final_score'] = min(
			100,
			max(
				0,
				absint(
					! empty( $row['final_score'] )
						? $row['final_score']
						: $row['score']
				)
			)
		);

		$row['score'] = $row['final_score'];

		$row['rule_score'] = null === $row['rule_score'] || '' === $row['rule_score']
			? null
			: min( 100, max( 0, absint( $row['rule_score'] ) ) );

		$row['ai_score'] = null === $row['ai_score'] || '' === $row['ai_score']
			? null
			: min( 100, max( 0, absint( $row['ai_score'] ) ) );

		$row['ai_similarity'] = null === $row['ai_similarity'] || '' === $row['ai_similarity']
			? null
			: min( 1, max( 0, floatval( $row['ai_similarity'] ) ) );

		if ( 'rule' === $row['selected_type'] && null === $row['rule_score'] ) {
			$row['rule_score'] = $row['final_score'];
		}

		if ( 'ai' === $row['selected_type'] && null === $row['ai_score'] ) {
			$row['ai_score'] = $row['final_score'];
		}

		$allowed_statuses = array(
			'building',
			'pending',
			'inserted',
			'ignored',
			'removed',
		);

		$row['status'] = in_array(
			sanitize_key( $row['status'] ),
			$allowed_statuses,
			true
		)
			? sanitize_key( $row['status'] )
			: 'pending';

		foreach (
			array(
				'inserted_at',
				'created_at',
				'updated_at',
			) as $date_key
		) {
			$row[ $date_key ] = null === $row[ $date_key ]
				? null
				: sanitize_text_field( $row[ $date_key ] );
		}

		return $row;
	}

	/**
	 * Return the greater of two nullable scores.
	 *
	 * @param int|null $first  First score.
	 * @param int|null $second Second score.
	 * @return int|null
	 */
	private static function max_nullable_score( $first, $second ) {
		if ( null === $first ) {
			return null === $second ? null : absint( $second );
		}

		if ( null === $second ) {
			return absint( $first );
		}

		return max(
			absint( $first ),
			absint( $second )
		);
	}

	/**
	 * Determine whether one equal-score candidate is stronger than another.
	 *
	 * @param array $candidate Candidate row.
	 * @param array $current   Current row.
	 * @return bool
	 */
	private static function canonical_candidate_is_stronger( $candidate, $current ) {
		$candidate_score = absint(
			! empty( $candidate['final_score'] )
				? $candidate['final_score']
				: ( $candidate['score'] ?? 0 )
		);

		$current_score = absint(
			! empty( $current['final_score'] )
				? $current['final_score']
				: ( $current['score'] ?? 0 )
		);

		if ( $candidate_score > $current_score ) {
			return true;
		}

		if ( $candidate_score < $current_score ) {
			return false;
		}

		$candidate_anchor_strength = self::canonical_text_strength(
			$candidate['anchor_text'] ?? ''
		);

		$current_anchor_strength = self::canonical_text_strength(
			$current['anchor_text'] ?? ''
		);

		if ( $candidate_anchor_strength > $current_anchor_strength ) {
			return true;
		}

		if ( $candidate_anchor_strength < $current_anchor_strength ) {
			return false;
		}

		$candidate_context_strength = self::canonical_text_strength(
			$candidate['sentence'] ?? ''
		);

		$current_context_strength = self::canonical_text_strength(
			$current['sentence'] ?? ''
		);

		if ( $candidate_context_strength > $current_context_strength ) {
			return true;
		}

		if ( $candidate_context_strength < $current_context_strength ) {
			return false;
		}

		return (
			'rule' === sanitize_key( $candidate['selected_type'] ?? '' ) &&
			'rule' !== sanitize_key( $current['selected_type'] ?? '' )
		);
	}

	/**
	 * Calculate deterministic text strength for tie-breaking.
	 *
	 * @param string $text Text value.
	 * @return int
	 */
	private static function canonical_text_strength( $text ) {
		$normalized = self::normalize_keyword_text( $text );

		if ( '' === $normalized ) {
			return 0;
		}

		$words = preg_split(
			'/\s+/u',
			$normalized,
			-1,
			PREG_SPLIT_NO_EMPTY
		);

		return ( count( $words ) * 100 ) + strlen( $normalized );
	}

	/**
	 * Update opportunity status.
	 *
	 * @param int    $id Opportunity ID.
	 * @param string $status New status.
	 * @return int|false
	 */
	public static function update_opportunity_status( $id, $status ) {
		global $wpdb;

		return $wpdb->update(
			self::get_table_name( 'opportunities' ),
			array(
				'status'     => sanitize_key( $status ),
				'updated_at' => self::get_now(),
			),
			array( 'id' => absint( $id ) )
		);
	}

	/**
	 * Update opportunity reason/score after GPT validation.
	 *
	 * @param int    $id     Opportunity ID.
	 * @param string $reason Updated reason text.
	 * @param int    $score  Updated hybrid score.
	 * @return int|false
	 */
	public static function update_opportunity_after_validation( $id, $reason, $score ) {
		global $wpdb;

		return $wpdb->update(
			self::get_table_name( 'opportunities' ),
			array(
				'reason'     => sanitize_text_field( $reason ),
				'score'      => absint( $score ),
				'updated_at' => self::get_now(),
			),
			array( 'id' => absint( $id ) )
		);
	}

	/**
	 * Mark a pending opportunity as inserted.
	 *
	 * @param int $id Opportunity ID.
	 * @return int|false
	 */
	public static function mark_opportunity_inserted( $id ) {
		global $wpdb;

		$id = absint( $id );

		if ( ! $id ) {
			return false;
		}

		$table = self::get_table_name( 'opportunities' );
		$now   = self::get_now();

		$result = $wpdb->update(
			$table,
			array(
				'status'      => 'inserted',
				'inserted_at' => $now,
				'updated_at'  => $now,
			),
			array(
				'id'     => $id,
				'status' => 'pending',
			)
		);

		if ( false === $result ) {
			return false;
		}

		/*
		* A zero update can also mean the row was already synchronized by another
		* save handler. Treat an existing inserted state as successful.
		*/
		if ( 0 === $result ) {
			$current_status = $wpdb->get_var(
				$wpdb->prepare(
					"SELECT status
					FROM {$table}
					WHERE id = %d
					LIMIT 1",
					$id
				)
			); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared

			return 'inserted' === sanitize_key( $current_status )
				? 0
				: false;
		}

		return $result;
	}

	/**
	 * Delete an inserted opportunity after its link has been
	 * successfully removed from saved WordPress content.
	 *
	 * @param int $id Opportunity ID.
	 * @return int|false Number of deleted rows or false on failure.
	 */
	public static function delete_inserted_opportunity( $id ) {
		global $wpdb;

		$id = absint( $id );

		if ( ! $id ) {
			return false;
		}

		return $wpdb->delete(
			self::get_table_name( 'opportunities' ),
			array(
				'id'     => $id,
				'status' => 'inserted',
			),
			array(
				'%d',
				'%s',
			)
		);
	}

	/**
	 * Fetch one opportunity with source and target metadata.
	 *
	 * @param int $id Opportunity ID.
	 * @return array|null
	 */
	public static function get_opportunity_by_id( $id ) {
		global $wpdb;

		$opportunity_table = self::get_table_name( 'opportunities' );
		$content_table     = self::get_table_name( 'content_index' );

		return $wpdb->get_row(
			$wpdb->prepare(
				"SELECT o.*, target.post_title AS target_title, target.post_url AS target_url, source.post_title AS source_title
				FROM {$opportunity_table} o
				LEFT JOIN {$content_table} target ON target.post_id = o.target_post_id
				LEFT JOIN {$content_table} source ON source.post_id = o.source_post_id
				WHERE o.id = %d",
				absint( $id )
			),
			ARRAY_A
		); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
	}

	/**
	 * Get shared opportunity status metrics.
	 *
	 * Used by both Link Opportunities and Inserted Links summaries so the
	 * opportunities table is aggregated only once during the admin request.
	 *
	 * @return array<string,int>
	 */
	private static function get_opportunity_summary_metrics() {
		global $wpdb;

		$cached = self::get_request_cache(
			'opportunity_summary_metrics'
		);

		if ( null !== $cached ) {
			return $cached;
		}

		$table = self::get_table_name(
			'opportunities'
		);

		if ( '' === $table ) {
			return array(
				'pending'        => 0,
				'high_score'     => 0,
				'approved_today' => 0,
				'ignored'        => 0,
				'inserted'       => 0,
				'removed'        => 0,
			);
		}

		$today = current_time(
			'Y-m-d'
		);

		$row = $wpdb->get_row(
			$wpdb->prepare(
				"SELECT
					SUM(
						CASE
							WHEN status = %s THEN 1
							ELSE 0
						END
					) AS pending,

					COALESCE(
						MAX(
							CASE
								WHEN status = %s
								THEN COALESCE(
									NULLIF(final_score, 0),
									score
								)
								ELSE NULL
							END
						),
						0
					) AS high_score,

					SUM(
						CASE
							WHEN status = %s
								AND DATE(inserted_at) = %s
							THEN 1
							ELSE 0
						END
					) AS approved_today,

					SUM(
						CASE
							WHEN status = %s THEN 1
							ELSE 0
						END
					) AS ignored,

					SUM(
						CASE
							WHEN status = %s THEN 1
							ELSE 0
						END
					) AS inserted,

					SUM(
						CASE
							WHEN status = %s THEN 1
							ELSE 0
						END
					) AS removed

				FROM %i",
				'pending',
				'pending',
				'inserted',
				$today,
				'ignored',
				'inserted',
				'removed',
				$table
			),
			ARRAY_A
		);

		$summary = array(
			'pending' =>
				absint(
					$row['pending'] ?? 0
				),

			'high_score' =>
				absint(
					$row['high_score'] ?? 0
				),

			'approved_today' =>
				absint(
					$row['approved_today'] ?? 0
				),

			'ignored' =>
				absint(
					$row['ignored'] ?? 0
				),

			'inserted' =>
				absint(
					$row['inserted'] ?? 0
				),

			'removed' =>
				absint(
					$row['removed'] ?? 0
				),
		);

		return self::set_request_cache(
			'opportunity_summary_metrics',
			$summary
		);
	}

	/**
	 * Get opportunity summary counts.
	 *
	 * @return array<string,int>
	 */
	public static function get_link_opportunities_summary() {
		$metrics =
			self::get_opportunity_summary_metrics();

		return array(
			'pending' =>
				absint(
					$metrics['pending'] ?? 0
				),

			'high_score' =>
				absint(
					$metrics['high_score'] ?? 0
				),

			'approved_today' =>
				absint(
					$metrics['approved_today'] ?? 0
				),

			'ignored' =>
				absint(
					$metrics['ignored'] ?? 0
				),
		);
	}

	/**
	 * Count pending opportunity rows.
	 *
	 * @return int
	 */
	public static function count_opportunities() {
		global $wpdb;

		$table = self::get_table_name( 'opportunities' );

		return absint( $wpdb->get_var( "SELECT COUNT(*) FROM {$table} WHERE status = 'pending'" ) ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
	}

	/**
	 * Build reusable SQL clauses for the Link Opportunities admin filters.
	 *
	 * @param string $search Search text.
	 * @param string $type   Opportunity type filter: all, rule, or ai.
	 * @return array{joins:string,where:string,args:array}
	 */
	private static function get_opportunity_admin_filter_clauses( $search = '', $type = 'all' ) {
		$content_table = self::get_table_name( 'content_index' );
		$search        = sanitize_text_field( $search );
		$type          = sanitize_key( $type );
		$type          = in_array( $type, array( 'rule', 'ai' ), true ) ? $type : 'all';
		$joins         = '';
		$where         = "WHERE o.status = 'pending'";
		$args          = array();

		if ( 'all' !== $type ) {
			$where .= ' AND o.selected_type = %s';
			$args[] = $type;
		}

		if ( '' !== $search ) {
			global $wpdb;

			$like = '%' . $wpdb->esc_like( $search ) . '%';

			$joins = $wpdb->prepare(
				"
				LEFT JOIN %i AS source_index
					ON source_index.post_id = o.source_post_id
				LEFT JOIN %i AS target_index
					ON target_index.post_id = o.target_post_id
				",
				$content_table,
				$content_table
			);

			$where .= ' AND (
				o.anchor_text LIKE %s
				OR o.sentence LIKE %s
				OR o.reason LIKE %s
				OR source_index.post_title LIKE %s
				OR target_index.post_title LIKE %s
			)';

			$args = array_merge(
				$args,
				array(
					$like,
					$like,
					$like,
					$like,
					$like,
				)
			);
		}

		return array(
			'joins' => $joins,
			'where' => $where,
			'args'  => $args,
		);
	}

	/**
	 * Count grouped pending opportunities displayed in the admin table.
	 *
	 * One group may contain multiple target options for the same source,
	 * anchor and sentence.
	 *
	 * @param string $search Search text.
	 * @param string $type   Opportunity type filter: all, rule, or ai.
	 * @return int
	 */
	public static function count_grouped_opportunities( $search = '', $type = 'all' ) {
		global $wpdb;

		$opportunity_table = self::get_table_name( 'opportunities' );
		$content_table     = self::get_table_name( 'content_index' );

		if (
			'' === $opportunity_table ||
			'' === $content_table
		) {
			return 0;
		}

		$search = sanitize_text_field( $search );
		$type   = sanitize_key( $type );

		if ( ! in_array( $type, array( 'rule', 'ai' ), true ) ) {
			$type = 'all';
		}

		$has_type_filter = 'all' !== $type ? 1 : 0;
		$has_search      = '' !== $search ? 1 : 0;
		$like            = '%' . $wpdb->esc_like( $search ) . '%';

		return absint(
			$wpdb->get_var(
				$wpdb->prepare(
					"SELECT COUNT(*)
					FROM (
						SELECT
							o.source_post_id,
							o.anchor_text,
							o.sentence
						FROM %i AS o
						LEFT JOIN %i AS source_index
							ON source_index.post_id = o.source_post_id
						LEFT JOIN %i AS target_index
							ON target_index.post_id = o.target_post_id
						WHERE o.status = %s
							AND (
								%d = 0
								OR o.selected_type = %s
							)
							AND (
								%d = 0
								OR o.anchor_text LIKE %s
								OR o.sentence LIKE %s
								OR o.reason LIKE %s
								OR source_index.post_title LIKE %s
								OR target_index.post_title LIKE %s
							)
						GROUP BY
							o.source_post_id,
							o.anchor_text,
							o.sentence
					) AS grouped_opportunities",
					$opportunity_table,
					$content_table,
					$content_table,
					'pending',
					$has_type_filter,
					$type,
					$has_search,
					$like,
					$like,
					$like,
					$like,
					$like
				)
			)
		);
	}
	/**
	 * Compatibility alias for opportunities table screen.
	 *
	 * @param int $page Current page.
	 * @param int $per_page Rows per page.
	 * @return array<int,array>
	 */
	public static function get_opportunities_rows( $page = 1, $per_page = 10, $search = '', $type = 'all' ) {
		return self::get_grouped_opportunities_rows(
			$page,
			$per_page,
			$search,
			$type
		);
	}

	/**
	 * Get grouped canonical opportunities for the admin table.
	 *
	 * Groups are paginated first, and their concrete opportunity IDs are then
	 * loaded directly. This avoids fragile sentence-based follow-up queries.
	 *
	 * @param int $page     Current page.
	 * @param int $per_page Rows per page.
	 * @return array
	 */
	public static function get_grouped_opportunities_rows( $page = 1, $per_page = 20, $search = '', $type = 'all' ) {
		global $wpdb;

		$page     = max( 1, absint( $page ) );
		$per_page = max( 1, absint( $per_page ) );
		$offset   = ( $page - 1 ) * $per_page;

		$opportunity_table = self::get_table_name( 'opportunities' );
		$content_table     = self::get_table_name( 'content_index' );
		$filter = self::get_opportunity_admin_filter_clauses(
			$search,
			$type
		);

		$args = $filter['args'];

		$args[] = $per_page;
		$args[] = $offset;

		$sql = "
			SELECT
				o.source_post_id,
				o.anchor_text,
				o.sentence,
				MAX(
					COALESCE(
						NULLIF(o.final_score, 0),
						o.score
					)
				) AS best_score,
				MAX(o.created_at) AS latest_created_at,
				GROUP_CONCAT(
					o.id
					ORDER BY
						COALESCE(
							NULLIF(o.final_score, 0),
							o.score
						) DESC,
						o.created_at DESC,
						o.id DESC
					SEPARATOR ','
				) AS opportunity_ids
			FROM {$opportunity_table} o
			{$filter['joins']}
			{$filter['where']}
			GROUP BY
				o.source_post_id,
				o.anchor_text,
				o.sentence
			ORDER BY
				best_score DESC,
				latest_created_at DESC
			LIMIT %d OFFSET %d
		";

		$groups = $wpdb->get_results(
			$wpdb->prepare(
				$sql,
				$args
			),
			ARRAY_A
		); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared,WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching

		if ( empty( $groups ) ) {
			return array();
		}

		$output = array();

		foreach ( $groups as $group ) {
			$opportunity_ids = array_values(
				array_filter(
					array_map(
						'absint',
						explode(
							',',
							(string) ( $group['opportunity_ids'] ?? '' )
						)
					)
				)
			);

			if ( empty( $opportunity_ids ) ) {
				continue;
			}

			$placeholders = implode(
				',',
				array_fill(
					0,
					count( $opportunity_ids ),
					'%d'
				)
			);

			$query_args = array_merge(
				array(
					$opportunity_table,
					$content_table,
					$content_table,
				),
				$opportunity_ids,
				array(
					'pending',
				)
			);

			$targets = $wpdb->get_results(
				$wpdb->prepare(
					"SELECT
						o.*,
						source_index.post_title AS source_title,
						target_index.post_title AS target_title,
						target_index.post_url AS target_url,
						COALESCE(
							NULLIF(o.final_score, 0),
							o.score
						) AS resolved_score
					FROM %i AS o
					LEFT JOIN %i AS source_index
						ON source_index.post_id = o.source_post_id
					LEFT JOIN %i AS target_index
						ON target_index.post_id = o.target_post_id
					WHERE o.id IN ({$placeholders})
						AND o.status = %s
					ORDER BY
						resolved_score DESC,
						o.created_at DESC,
						o.id DESC",
					$query_args
				),
				ARRAY_A
			);

			if ( empty( $targets ) ) {
				continue;
			}

			$first = $targets[0];

			$selected_type = in_array(
				sanitize_key( $first['selected_type'] ?? '' ),
				array( 'rule', 'ai' ),
				true
			)
				? sanitize_key( $first['selected_type'] )
				: 'rule';

			$output[] = array(
				'id'             => absint( $first['id'] ),
				'source_post_id' => absint( $first['source_post_id'] ),
				'source_title'   => ! empty( $first['source_title'] )
					? $first['source_title']
					: get_the_title( absint( $first['source_post_id'] ) ),
				'anchor_text'    => $group['anchor_text'],
				'sentence'       => $group['sentence'],
				'reason'         => $first['reason'],
				'best_score'     => absint( $group['best_score'] ),
				'type'           => $selected_type,
				'selected_type'  => $selected_type,
				'rule_score'     => null !== $first['rule_score']
					? absint( $first['rule_score'] )
					: null,
				'ai_score'       => null !== $first['ai_score']
					? absint( $first['ai_score'] )
					: null,
				'ai_similarity'  => null !== $first['ai_similarity']
					? floatval( $first['ai_similarity'] )
					: null,
				'targets'        => self::map_opportunity_targets(
					$targets
				),
			);
		}

		return $output;
	}

	/**
	 * Get flat editor opportunities for one source post.
	 *
	 * @param int $post_id Source post ID.
	 * @param int $limit Maximum rows.
	 * @return array<int,array>
	 */
	public static function get_editor_opportunities( $post_id, $limit = 20 ) {
		global $wpdb;

		$opportunity_table = self::get_table_name( 'opportunities' );
		$content_table     = self::get_table_name( 'content_index' );

		return $wpdb->get_results(
			$wpdb->prepare(
				"SELECT o.*, target.post_title AS target_title, target.post_url AS target_url
				FROM {$opportunity_table} o
				LEFT JOIN {$content_table} target ON target.post_id = o.target_post_id
				WHERE o.source_post_id = %d AND o.status = 'pending'
				ORDER BY o.score DESC
				LIMIT %d",
				absint( $post_id ),
				absint( $limit )
			),
			ARRAY_A
		); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
	}

	/**
	 * Get grouped editor opportunities for Gutenberg/sidebar UI.
	 *
	 * @param int $post_id Source post ID.
	 * @param int $limit Maximum groups.
	 * @return array<int,array>
	 */
	public static function get_editor_grouped_opportunities( $post_id, $limit = 10 ) {
		$rows   = self::get_editor_opportunities( $post_id, $limit * 6 );
		$groups = array();

		foreach ( $rows as $row ) {
			$key = md5( strtolower( $row['anchor_text'] . '|' . $row['sentence'] ) );

			if ( ! isset( $groups[ $key ] ) ) {
				$selected_type = in_array(
					sanitize_key( $row['selected_type'] ?? '' ),
					array( 'rule', 'ai' ),
					true
				)
					? sanitize_key( $row['selected_type'] )
					: 'rule';

				$final_score = absint(
					! empty( $row['final_score'] )
						? $row['final_score']
						: $row['score']
				);

				$groups[ $key ] = array(
					'id'            => absint( $row['id'] ),
					'anchor_text'   => $row['anchor_text'],
					'sentence'      => $row['sentence'],
					'reason'        => $row['reason'],
					'score'         => $final_score,
					'final_score'   => $final_score,
					'type'          => $selected_type,
					'selected_type' => $selected_type,
					'rule_score'    => null !== $row['rule_score']
						? absint( $row['rule_score'] )
						: null,
					'ai_score'      => null !== $row['ai_score']
						? absint( $row['ai_score'] )
						: null,
					'ai_similarity' => null !== $row['ai_similarity']
						? floatval( $row['ai_similarity'] )
						: null,
					'targets'       => array(),
				);
			}

			$row_type = in_array(
				sanitize_key( $row['selected_type'] ?? '' ),
				array( 'rule', 'ai' ),
				true
			)
				? sanitize_key( $row['selected_type'] )
				: 'rule';

			$row_score = absint(
				! empty( $row['final_score'] )
					? $row['final_score']
					: $row['score']
			);

			$groups[ $key ]['targets'][] = array(
				'opportunity_id' => absint( $row['id'] ),
				'target_post_id' => absint( $row['target_post_id'] ),
				'target_title'   => $row['target_title'],
				'target_url'     => $row['target_url'],
				'score'          => $row_score,
				'final_score'    => $row_score,
				'type'           => $row_type,
				'selected_type'  => $row_type,
				'rule_score'     => null !== $row['rule_score']
					? absint( $row['rule_score'] )
					: null,
				'ai_score'       => null !== $row['ai_score']
					? absint( $row['ai_score'] )
					: null,
				'ai_similarity'  => null !== $row['ai_similarity']
					? floatval( $row['ai_similarity'] )
					: null,
				'reason'         => $row['reason'],
			);
		}

		return array_slice( array_values( $groups ), 0, absint( $limit ) );
	}

	/**
	 * Get pending opportunities pointing to a target post.
	 *
	 * @param int $target_post_id Target post ID.
	 * @return array<int,array>
	 */
	public static function get_pending_opportunities_by_target( $target_post_id ) {
		global $wpdb;

		$opportunity_table = self::get_table_name( 'opportunities' );
		$content_table     = self::get_table_name( 'content_index' );

		return $wpdb->get_results(
			$wpdb->prepare(
				"SELECT o.*, source.post_title AS source_title
				FROM {$opportunity_table} o
				LEFT JOIN {$content_table} source ON source.post_id = o.source_post_id
				WHERE o.target_post_id = %d AND o.status = 'pending'
				ORDER BY o.score DESC",
				absint( $target_post_id )
			),
			ARRAY_A
		); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
	}

	/**
	 * Get inserted/approved link summary.
	 *
	 * @return array<string,int>
	 */
	public static function get_inserted_links_summary() {
		$metrics =
			self::get_opportunity_summary_metrics();

		return array(
			'inserted' =>
				absint(
					$metrics['inserted'] ?? 0
				),

			'pending' =>
				absint(
					$metrics['pending'] ?? 0
				),

			'ignored' =>
				absint(
					$metrics['ignored'] ?? 0
				),

			'removed' =>
				absint(
					$metrics['removed'] ?? 0
				),
		);
	}

	/**
	 * Count inserted/removed link history rows.
	 *
	 * @return int
	 */
	public static function count_inserted_links() {
		global $wpdb;

		$table = self::get_table_name( 'opportunities' );

		return absint( $wpdb->get_var( "SELECT COUNT(*) FROM {$table} WHERE status IN ('inserted', 'removed')" ) ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
	}

	/**
	 * Get inserted/removed link history rows.
	 *
	 * @param int $page Current page.
	 * @param int $per_page Rows per page.
	 * @return array<int,array>
	 */
	public static function get_inserted_links_rows( $page = 1, $per_page = 10 ) {
		global $wpdb;

		$page              = max( 1, absint( $page ) );
		$per_page          = max( 1, absint( $per_page ) );
		$offset            = ( $page - 1 ) * $per_page;
		$opportunity_table = self::get_table_name( 'opportunities' );
		$content_table     = self::get_table_name( 'content_index' );

		return $wpdb->get_results(
			$wpdb->prepare(
				"SELECT o.*, source.post_title AS source_title, target.post_title AS target_title, target.post_url AS target_url
				FROM {$opportunity_table} o
				LEFT JOIN {$content_table} source ON source.post_id = o.source_post_id
				LEFT JOIN {$content_table} target ON target.post_id = o.target_post_id
				WHERE o.status IN ('inserted', 'removed')
				ORDER BY o.updated_at DESC
				LIMIT %d OFFSET %d",
				$per_page,
				$offset
			),
			ARRAY_A
		); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
	}

    
	/**
	 * Map raw opportunity rows into compact target choice arrays.
	 *
	 * @param array<int,array> $targets Raw opportunity rows.
	 * @return array<int,array>
	 */
	private static function map_opportunity_targets( $targets ) {
		return array_map(
			static function ( $target ) {
				$selected_type = in_array(
					sanitize_key( $target['selected_type'] ?? '' ),
					array( 'rule', 'ai' ),
					true
				)
					? sanitize_key( $target['selected_type'] )
					: 'rule';

				$final_score = absint(
					! empty( $target['final_score'] )
						? $target['final_score']
						: $target['score']
				);

				return array(
					'opportunity_id' => absint( $target['id'] ),
					'target_post_id' => absint( $target['target_post_id'] ),
					'target_title'   => $target['target_title'],
					'target_url'     => $target['target_url'],
					'score'          => $final_score,
					'final_score'    => $final_score,
					'type'           => $selected_type,
					'selected_type'  => $selected_type,
					'rule_score'     => null !== $target['rule_score']
						? absint( $target['rule_score'] )
						: null,
					'ai_score'       => null !== $target['ai_score']
						? absint( $target['ai_score'] )
						: null,
					'ai_similarity'  => null !== $target['ai_similarity']
						? floatval( $target['ai_similarity'] )
						: null,
					'reason'         => $target['reason'],
				);
			},
			(array) $targets
		);
	}

    	
	/**
	 * Get existing pending opportunities for a target post.
	 *
	 * This is primarily used by Orphan Content Discovery. It returns already
	 * generated canonical opportunities instead of regenerating them every time.
	 *
	 * @param int $target_post_id Target post ID.
	 * @param int $limit          Maximum opportunities to return.
	 *
	 * @return array<int,array<string,mixed>>
	 */
	public static function get_pending_opportunities_for_target( $target_post_id,$limit = 20 ) {
		global $wpdb;

		$target_post_id = absint( $target_post_id );
		$limit          = min( 50, max( 1, absint( $limit ) ) );

		if ( ! $target_post_id ) {
			return array();
		}

		$table = self::get_table_name( 'opportunities' );

		/*
		* Load more rows than the final limit so they can be normalized and
		* ordered by their effective score in PHP.
		*/
		$query_limit = max( 50, $limit * 5 );

		$rows = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT *
				FROM {$table}
				WHERE target_post_id = %d
					AND status = %s
				ORDER BY id DESC
				LIMIT %d",
				$target_post_id,
				'pending',
				$query_limit
			),
			ARRAY_A
		);

		if ( empty( $rows ) ) {
			return array();
		}

		$opportunities = array();

		foreach ( $rows as $row ) {
			$source_post_id = absint(
				$row['source_post_id'] ?? 0
			);

			if ( ! $source_post_id ) {
				continue;
			}

			$source_post = get_post( $source_post_id );

			if (
				! $source_post ||
				'publish' !== $source_post->post_status
			) {
				continue;
			}

			$score = 0;

			if ( isset( $row['final_score'] ) ) {
				$score = absint( $row['final_score'] );
			} elseif ( isset( $row['score'] ) ) {
				$score = absint( $row['score'] );
			}

			$type = sanitize_key(
				$row['type']
					?? $row['opportunity_type']
					?? 'rule'
			);

			if ( ! in_array( $type, array( 'rule', 'ai' ), true ) ) {
				$type = 'rule';
			}

			$opportunities[] = array(
				'id'             => absint( $row['id'] ?? 0 ),
				'source_post_id' => $source_post_id,
				'source_title'   => get_the_title( $source_post_id ),
				'source_url'     => get_permalink( $source_post_id ),
				'target_post_id' => $target_post_id,
				'anchor_text'    => sanitize_text_field(
					$row['anchor_text'] ?? ''
				),
				'sentence'       => sanitize_textarea_field(
					$row['sentence']
						?? $row['context_sentence']
						?? ''
				),
				'reason'         => sanitize_textarea_field(
					$row['reason'] ?? ''
				),
				'score'          => $score,
				'type'           => $type,
				'status'         => 'pending',
			);
		}

		usort(
			$opportunities,
			static function ( $first, $second ) {
				return absint( $second['score'] )
					<=> absint( $first['score'] );
			}
		);

		return array_slice(
			$opportunities,
			0,
			$limit
		);
	}

	/**
	 * Finalize deferred canonical opportunities.
	 *
	 * Converts temporary "building" opportunities into normal "pending"
	 * opportunities after AI semantic processing has completed.
	 *
	 * @param int $scan_run_id    Scan run ID.
	 * @param int $source_post_id Optional source post ID.
	 * @return int|false Number of updated rows or false on failure.
	 */
	public static function finalize_building_opportunities( $scan_run_id = 0, $source_post_id = 0 ) {
		global $wpdb;

		$scan_run_id    = absint( $scan_run_id );
		$source_post_id = absint( $source_post_id );

		/*
		* Prevent an accidental site-wide update.
		*/
		if ( ! $scan_run_id && ! $source_post_id ) {
			return 0;
		}

		$table = self::get_table_name( 'opportunities' );

		$where = array(
			"status = 'building'",
		);

		$args = array(
			self::get_now(),
		);

		if ( $scan_run_id ) {
			$where[] = 'scan_run_id = %d';
			$args[]  = $scan_run_id;
		}

		if ( $source_post_id ) {
			$where[] = 'source_post_id = %d';
			$args[]  = $source_post_id;
		}

		$sql = "
			UPDATE {$table}
			SET
				status = 'pending',
				updated_at = %s
			WHERE " . implode( ' AND ', $where );

		return $wpdb->query(
			$wpdb->prepare(
				$sql,
				$args
			)
		); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
	}

}
// phpcs:enable WordPress.DB.DirectDatabaseQuery.DirectQuery
// phpcs:enable WordPress.DB.DirectDatabaseQuery.NoCaching