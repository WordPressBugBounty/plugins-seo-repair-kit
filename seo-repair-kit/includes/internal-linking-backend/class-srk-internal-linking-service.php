<?php
/**
 * Internal Linking Service Layer — sole orchestrator.
 *
 * AJAX and admin UI must call this class only. It decides which engine runs:
 * Rule Engine (fast), Auto Engine (rule execution), AI Engine (background).
 *
 * @package SEO_Repair_Kit
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Orchestrates scan, opportunity generation, and AI background pipelines.
 */
class SRK_Internal_Linking_Service {

	const BATCH_SIZE = 10;

	/**
	 * Initialize content index scan.
	 *
	 * @return array
	 */
	public static function start_scan() {
		if ( ! self::feature_enabled() ) {
			return array(
				'status'  => 'disabled',
				'message' => __( 'Internal Linking is a paid module. Please upgrade or renew Internal Linking to use this feature.', 'seo-repair-kit' ),
			);
		}

		if ( ! class_exists( 'SRK_Internal_Linking_Indexer' ) ) {
			return array(
				'message' => __( 'Indexer is not loaded.', 'seo-repair-kit' ),
			);
		}

		return SRK_Internal_Linking_Indexer::start_scan();
	}

	/**
	 * Process one content index batch (max 10 posts).
	 *
	 * @param int $scan_id Scan run ID.
	 * @param int $page    Batch page.
	 * @return array
	 */
	public static function run_scan_batch( $scan_id, $page = 1 ) {
		if ( ! self::feature_enabled() ) {
			return array(
				'complete' => true,
				'status'   => 'disabled',
				'message'  => __( 'Internal Linking is a paid module. Please upgrade or renew Internal Linking to use this feature.', 'seo-repair-kit' ),
			);
		}

		if (
			! class_exists(
				'SRK_Internal_Linking_Indexer'
			)
		) {
			return array(
				'complete' => true,
			);
		}

		/*
		* Content indexing does not independently start AI.
		* AI starts only after rule suggestion generation completes.
		*/
		return SRK_Internal_Linking_Indexer::index_batch(
			$scan_id,
			$page
		);
	}

	/**
	 * Start opportunity generation.
	 *
	 * Rule suggestions are blocked when the main Internal Linking setting
	 * is disabled.
	 *
	 * @param int $single_post_id Optional single post.
	 * @return array|WP_Error
	 */
	public static function start_opportunity_generation(
		$single_post_id = 0
	) {
		if ( ! self::feature_enabled() ) {
			return new WP_Error(
				'srk_internal_linking_disabled',
				__(
					'Internal Linking is disabled. Enable it from Internal Linking Settings before generating suggestions.',
					'seo-repair-kit'
				)
			);
		}

		if (
			! class_exists(
				'SRK_Internal_Linking_Opportunities'
			)
		) {
			return new WP_Error(
				'srk_opportunities_engine_missing',
				__(
					'Opportunities engine is not loaded.',
					'seo-repair-kit'
				)
			);
		}

		return SRK_Internal_Linking_Opportunities::start_scan(
			absint( $single_post_id )
		);
	}

	/**
	 * Run one rule-based opportunity batch (max 10 posts).
	 *
	 * @param int $scan_id        Scan run ID.
	 * @param int $page           Batch page.
	 * @param int $single_post_id Optional single post.
	 * @return array
	 */
	public static function run_opportunity_batch( $scan_id, $page = 1, $single_post_id = 0 ) {
		if ( ! self::feature_enabled() ) {
			$scan_id = absint( $scan_id );

			if (
				$scan_id &&
				class_exists( 'SRK_Internal_Linking_DB' )
			) {
				SRK_Internal_Linking_DB::update_scan_run(
					$scan_id,
					array(
						'status'         => 'completed',
						'completed_at'   => SRK_Internal_Linking_DB::get_now(),
						'message'        => __(
							'Opportunity generation stopped because Internal Linking is disabled.',
							'seo-repair-kit'
						),
					)
				);
			}

			return array(
				'scan_id'         => $scan_id,
				'page'            => absint( $page ),
				'next_page'       => absint( $page ),
				'processed_items' => 0,
				'total_items'     => 0,
				'percent'         => 100,
				'progress'        => 100,
				'complete'        => true,
				'stopped'         => true,
				'message'         => __(
					'Internal Linking is disabled.',
					'seo-repair-kit'
				),
			);
		}

		if (
			! class_exists(
				'SRK_Internal_Linking_Opportunities'
			)
		) {
			return array(
				'complete' => true,
			);
		}

		$result =
			SRK_Internal_Linking_Opportunities::run_batch(
				$scan_id,
				$page,
				$single_post_id
			);

		if ( ! empty( $result['complete'] ) ) {
			self::on_opportunity_scan_complete(
				$single_post_id,
				$scan_id
			);
		}

		return $result;
	}

	/**
	 * Queue AI embedding generation for one post (background only).
	 *
	 * @param int $post_id Post ID.
	 * @return array
	 */
	public static function trigger_ai_embedding( $post_id ) {
		if ( ! self::feature_enabled() ) {
			return array(
				'queued' => false,
				'reason' => 'internal_linking_disabled',
			);
		}
		$post_id = absint( $post_id );

		if ( ! $post_id ) {
			return array( 'queued' => false );
		}

		if ( ! class_exists( 'SRK_Internal_Linking_AI_Engine' ) || ! SRK_Internal_Linking_AI_Engine::is_enabled() ) {
			return array( 'queued' => false, 'reason' => 'ai_disabled' );
		}

		SRK_Internal_Linking_Queue::queue_embedding( $post_id );

		return array(
			'queued'  => true,
			'post_id' => $post_id,
		);
	}

	/**
	 * Queue semantic graph generation (background).
	 *
	 * @return array
	 */
	public static function generate_semantic_graph() {
		if ( ! self::feature_enabled() ) {
			return array(
				'queued' => false,
				'reason' => 'internal_linking_disabled',
			);
		}
		if ( ! class_exists( 'SRK_Internal_Linking_AI_Engine' ) || ! SRK_Internal_Linking_AI_Engine::is_enabled() ) {
			return array( 'queued' => false );
		}

		SRK_Internal_Linking_Queue::enqueue(
			SRK_Internal_Linking_Queue::HOOK_SEMANTIC_GRAPH,
			array( 'offset' => 0 )
		);

		return array( 'queued' => true );
	}

	/**
	 * Start full AI pipeline manually from settings/dashboard.
	 *
	 * @return array
	 */
	public static function start_ai_pipeline() {
		if ( ! self::feature_enabled() ) {
			return new WP_Error(
				'srk_internal_linking_disabled',
				__(
					'Internal Linking is disabled. Enable it before running the AI pipeline.',
					'seo-repair-kit'
				)
			);
		}
		if (
			! class_exists(
				'SRK_Internal_Linking_AI_Engine'
			) ||
			! SRK_Internal_Linking_AI_Engine::is_enabled() ||
			! SRK_Internal_Linking_AI_Engine::semantic_matching_enabled()
		) {
			return new WP_Error(
				'srk_ai_disabled',
				__(
					'Enable the AI Engine and save a valid AI API key before running the AI pipeline.',
					'seo-repair-kit'
				)
			);
		}

		$scan_id = SRK_Internal_Linking_DB::insert_scan_run(
			array(
				'scan_type'   => 'ai_pipeline',
				'status'      => 'running',
				'total_items' => SRK_Internal_Linking_DB::count_ai_source_posts(),
				'message'     => __(
					'AI semantic pipeline queued.',
					'seo-repair-kit'
				),
			)
		);

		SRK_Internal_Linking_Queue::queue_ai_pipeline(
			$scan_id
		);

		return array(
			'queued'  => true,
			'scan_id' => $scan_id,
			'message' => __(
				'AI semantic pipeline queued. Processing runs in the background.',
				'seo-repair-kit'
			),
		);
	}

	/**
	 * Get AI pipeline status for the admin UI.
	 *
	 * Normal PHP rendering may use the cached value. AJAX polling can pass
	 * $force_refresh = true so live counters come directly from the database.
	 *
	 * @param bool $force_refresh Whether to bypass the cached status.
	 * @return array
	 */
	public static function get_ai_status( $force_refresh = false ) {

		if ( ! $force_refresh ) {

			$cached = get_transient(
				'srk_il_ai_status'
			);

			if ( is_array( $cached ) ) {
				return $cached;
			}
		}

		$summary =
			SRK_Internal_Linking_DB::get_ai_pipeline_summary();

		$embeddings_ready = absint(
			$summary['embeddings_ready']
				?? 0
		);

		$embeddings_pending = absint(
			$summary['embeddings_pending']
				?? 0
		);

		$ai_opportunities = absint(
			$summary['ai_opportunities']
				?? $summary['canonical_ai_opportunities']
				?? 0
		);

		$analysis_total =
			$embeddings_ready +
			$embeddings_pending;

		$analysis_percent =
			$analysis_total > 0
				? min(
					100,
					max(
						0,
						absint(
							round(
								(
									$embeddings_ready /
									$analysis_total
								) * 100
							)
						)
					)
				)
				: 0;

		/*
		* AI opportunity-discovery progress.
		*
		* This is different from the number of opportunities found.
		* It measures how many successfully analyzed source posts have
		* already been checked for semantic link opportunities.
		*/
		$opportunity_processed = 0;
		$opportunity_total     = 0;
		$opportunity_percent   = 0;

		if (
			class_exists(
				'SRK_Internal_Linking_DB'
			) &&
			method_exists(
				'SRK_Internal_Linking_DB',
				'get_latest_scan_run_by_type'
			)
		) {

			$ai_scan =
				SRK_Internal_Linking_DB::
					get_latest_scan_run_by_type(
						'ai_pipeline'
					);

			if ( is_array( $ai_scan ) ) {

				$opportunity_total = absint(
					$ai_scan['success_items'] ?? 0
				);

				$opportunity_processed = absint(
					$ai_scan['processed_items'] ?? 0
				);

				if ( $opportunity_total > 0 ) {

					$opportunity_processed =
						min(
							$opportunity_total,
							$opportunity_processed
						);

					$opportunity_percent =
						min(
							100,
							max(
								0,
								absint(
									round(
										(
											$opportunity_processed /
											$opportunity_total
										) * 100
									)
								)
							)
						);
				}
			}
		}
		
		/*
		* Preserve your existing background queue status behavior.
		*/
		$pipeline_active = false;

		if (
			class_exists(
				'SRK_Internal_Linking_Queue'
			)
		) {

			$pipeline_active =
				(bool) wp_next_scheduled(
					SRK_Internal_Linking_Queue::HOOK_AI_PIPELINE
				)
				||
				(bool) wp_next_scheduled(
					SRK_Internal_Linking_Queue::HOOK_EMBEDDING_BATCH
				)
				||
				(bool) wp_next_scheduled(
					SRK_Internal_Linking_Queue::HOOK_SEMANTIC_GRAPH
				)
				||
				(bool) wp_next_scheduled(
					SRK_Internal_Linking_Queue::HOOK_HYBRID_OPPORTUNITIES
				);
		}

		$status = array(
			'enabled' =>
				class_exists(
					'SRK_Internal_Linking_AI_Engine'
				) &&
				SRK_Internal_Linking_AI_Engine::is_enabled(),

			'embeddings_ready' =>
				$embeddings_ready,

			'embeddings_pending' =>
				$embeddings_pending,

			'ai_opportunities' =>
				$ai_opportunities,

			'analysis_total' =>
				$analysis_total,

			'analysis_percent' =>
				$analysis_percent,

			'pipeline_active' =>
				$pipeline_active,

			'opportunity_processed' =>
				$opportunity_processed,

			'opportunity_total' =>
				$opportunity_total,

			'opportunity_percent' =>
				$opportunity_percent,	

			/*
			* Temporary compatibility alias.
			*/
			'semantic_links' =>
				$ai_opportunities,
		);

		/*
		* Keep the transient useful for non-AJAX consumers.
		*
		* AJAX polling bypasses it using $force_refresh = true.
		*/
		set_transient(
			'srk_il_ai_status',
			$status,
			MINUTE_IN_SECONDS
		);

		return $status;
	}

	/**
	 * Get non-blocking suggestion status for the Gutenberg editor.
	 *
	 * This method performs no external AI calls.
	 *
	 * @param int   $post_id Post ID.
	 * @param array $counts  Current editor suggestion counts.
	 *
	 * @return array<string,mixed>
	 */
	public static function get_editor_suggestion_status( $post_id, $counts = array() ) {
		$post_id = absint(
			$post_id
		);

		$counts = wp_parse_args(
			(array) $counts,
			array(
				'available' => 0,
				'rule'      => 0,
				'ai'        => 0,
			)
		);

		$summary =
			class_exists(
				'SRK_Internal_Linking_DB'
			)
				? SRK_Internal_Linking_DB::
					get_ai_pipeline_summary()
				: array();

		$embeddings_ready = absint(
			$summary['embeddings_ready']
				?? 0
		);

		$embeddings_pending = absint(
			$summary['embeddings_pending']
				?? 0
		);

		$ai_total =
			$embeddings_ready +
			$embeddings_pending;

		$coverage = $ai_total > 0
			? min(
				100,
				absint(
					round(
						(
							$embeddings_ready /
							$ai_total
						) * 100
					)
				)
			)
			: 0;

		$indexed_total =
			class_exists(
				'SRK_Internal_Linking_DB'
			)
				? SRK_Internal_Linking_DB::
					count_indexed_content()
				: 0;

		$ai_enabled = (
			class_exists(
				'SRK_Internal_Linking_AI_Engine'
			) &&
			SRK_Internal_Linking_AI_Engine::is_enabled() &&
			SRK_Internal_Linking_AI_Engine::
				semantic_matching_enabled()
		);

		$processing = (
			$ai_enabled &&
			class_exists(
				'SRK_Internal_Linking_Queue'
			) &&
			method_exists(
				'SRK_Internal_Linking_Queue',
				'is_post_ai_processing'
			) &&
			SRK_Internal_Linking_Queue::
				is_post_ai_processing(
					$post_id
				)
		);

		$embedding =
			$post_id &&
			class_exists(
				'SRK_Internal_Linking_DB'
			)
				? SRK_Internal_Linking_DB::
					get_embedding_by_post_id(
						$post_id
					)
				: null;

		$post = get_post(
			$post_id
		);

		$current_hash = '';

		if ( $post ) {
			$current_hash = hash(
				'sha256',
				(string) $post->post_title .
				'|' .
				(string) $post->post_content
			);
		}

		$embedding_hash =
			is_array( $embedding )
				? sanitize_text_field(
					$embedding['content_hash']
						?? ''
				)
				: '';

		$embedding_status =
			is_array( $embedding )
				? sanitize_key(
					$embedding['status']
						?? ''
				)
				: '';

		$hash_matches = (
			'' !== $current_hash &&
			'' !== $embedding_hash &&
			hash_equals(
				$current_hash,
				$embedding_hash
			)
		);

		$ai_state = 'not_processed';
		$ai_label = __(
			'Not Processed',
			'seo-repair-kit'
		);

		$ai_message = __(
			'Rule-based suggestions are available now.  AI-Powered will appear after background processing completes.',
			'seo-repair-kit'
		);

		if ( ! $ai_enabled ) {

			$ai_state = 'disabled';

			$ai_label = __(
				'Disabled',
				'seo-repair-kit'
			);

			$ai_message = __(
				'Rule-based suggestions are fully usable. AI semantic enhancement is optional and currently disabled.',
				'seo-repair-kit'
			);

		} elseif ( $processing ) {

			$ai_state = 'processing';

			$ai_label = __(
				'Processing',
				'seo-repair-kit'
			);

			$ai_message = __(
				'Rule-based suggestions are ready to use. AI is enhancing this post in the background and does not block linking.',
				'seo-repair-kit'
			);

		} elseif (
			$hash_matches &&
			'ready' === $embedding_status
		) {

			$ai_state = 'ready';

			$ai_label = __(
				'Ready',
				'seo-repair-kit'
			);

			$ai_message = __(
				'AI semantic analysis is ready for the current saved version of this post.',
				'seo-repair-kit'
			);

		} elseif (
			$hash_matches &&
			'failed' === $embedding_status
		) {

			$ai_state = 'failed';

			$ai_label = __(
				'Needs Attention',
				'seo-repair-kit'
			);

			$ai_message = __(
				' AI-Powered  could not complete for this post. Rule-based suggestions remain available and can still be applied.',
				'seo-repair-kit'
			);

		} elseif (
			is_array( $embedding ) &&
			! $hash_matches
		) {

			$ai_state = 'stale';

			$ai_label = __(
				'Needs Refresh',
				'seo-repair-kit'
			);

			$ai_message = __(
				'This post changed after its last AI analysis. Rule-based suggestions remain available while AI is refreshed.',
				'seo-repair-kit'
			);
		}

		return array(
			'rule_ready' =>
				true,

			'available_count' =>
				absint(
					$counts['available']
				),

			'rule_count' =>
				absint(
					$counts['rule']
				),

			'ai_count' =>
				absint(
					$counts['ai']
				),

			'ai_enabled' =>
				$ai_enabled,

			'ai_state' =>
				$ai_state,

			'ai_label' =>
				$ai_label,

			'ai_message' =>
				$ai_message,

			'indexed_total' =>
				absint(
					$indexed_total
				),

			'embeddings_ready' =>
				$embeddings_ready,

			'embeddings_pending' =>
				$embeddings_pending,

			'ai_total' =>
				$ai_total,

			'coverage_percent' =>
				$coverage,
		);
	}

	/**
	 * Handle incremental post save — index, keywords, rule opportunities, queue AI.
	 *
	 * @param int $post_id Post ID.
	 * @return void
	 */
	public static function handle_post_update( $post_id ) {
		$post_id = absint( $post_id );

		if ( ! $post_id ) {
			return;
		}

		if ( class_exists( 'SRK_Internal_Linking_Indexer' ) ) {
			SRK_Internal_Linking_Indexer::index_single_post(
				$post_id
			);
		}

		if ( class_exists( 'SRK_Internal_Linking_Keywords' ) ) {
			SRK_Internal_Linking_Keywords::generate_for_post(
				$post_id
			);
		}

		if ( ! self::feature_enabled() ) {
			return;
		}

		if ( class_exists( 'SRK_Internal_Linking_Opportunities' ) ) {
			SRK_Internal_Linking_Opportunities::generate_for_source_post(
				$post_id
			);
		}

		self::trigger_ai_embedding(
			$post_id
		);
	}

	/**
	 * Actions after rule-based opportunity scan completes.
	 *
	 * @param int $single_post_id Optional single post context.
	 * @return void
	 */
	private static function on_opportunity_scan_complete( $single_post_id = 0, $scan_id = 0 ) {
		$single_post_id = absint(
			$single_post_id
		);

		$scan_id = absint(
			$scan_id
		);

		/*
		* SINGLE POST
		* ---------------------------------------------------------
		*
		* Preserve the existing single-post behavior.
		*
		* This flow is separate from the Link Opportunities
		* "Generate Opportunities" button.
		*/
		if ( $single_post_id ) {

			$ai_available = (
				class_exists(
					'SRK_Internal_Linking_AI_Engine'
				) &&
				SRK_Internal_Linking_AI_Engine::is_enabled() &&
				SRK_Internal_Linking_AI_Engine::semantic_matching_enabled() &&
				class_exists(
					'SRK_Internal_Linking_Queue'
				)
			);

			if ( $scan_id ) {
				SRK_Internal_Linking_DB::update_scan_run(
					$scan_id,
					array(
						'status'       => 'completed',
						'completed_at' =>
							SRK_Internal_Linking_DB::get_now(),
						'message'      => __(
							'Rule suggestions completed.',
							'seo-repair-kit'
						),
					)
				);
			}

			/*
			* Preserve existing per-post AI refresh behavior.
			*/
			if ( $ai_available ) {
				SRK_Internal_Linking_Queue::queue_embedding(
					$single_post_id
				);
			}

			return;
		}

		/*
		* FULL-SITE / GENERATE OPPORTUNITIES
		* ---------------------------------------------------------
		*
		* RULE ENGINE ONLY.
		*
		* Never start or queue AI from this flow.
		* AI can only be explicitly started through
		* "Run AI Pipeline Now".
		*/

		if (
			$scan_id &&
			class_exists(
				'SRK_Internal_Linking_DB'
			)
		) {

			/*
			* Safety for any building rows created by an older/in-progress
			* rule scan. New rule scans should already create pending rows.
			*/
			SRK_Internal_Linking_DB::finalize_building_opportunities(
				$scan_id
			);

			SRK_Internal_Linking_DB::update_scan_run(
				$scan_id,
				array(
					'status'       => 'completed',
					'completed_at' =>
						SRK_Internal_Linking_DB::get_now(),
					'message'      => __(
						'Rule-based opportunities completed.',
						'seo-repair-kit'
					),
				)
			);
		}

		/*
		* IMPORTANT:
		*
		* Do NOT call:
		*
		* SRK_Internal_Linking_Queue::queue_ai_pipeline( $scan_id );
		*
		* AI is intentionally started only from
		* SRK_Internal_Linking_Service::start_ai_pipeline().
		*/
	}

	/**
	 * Determine whether Internal Linking functionality is enabled.
	 *
	 * @return bool
	 */
	private static function feature_enabled() {
		if ( ! class_exists( 'SRK_License_Helper' ) || ! SRK_License_Helper::is_internal_linking_enabled() ) {
			return false;
		}

		if (
			! class_exists( 'SRK_Internal_Linking_Settings' ) ||
			! method_exists(
				'SRK_Internal_Linking_Settings',
				'is_enabled'
			)
		) {
			return true;
		}

		return SRK_Internal_Linking_Settings::is_enabled();
	}

	/**
	 * Rebuild keywords and opportunities for one saved post.
	 *
	 * This method must be called by the explicit "Scan Current Post" action.
	 *
	 * @param int $post_id Post ID.
	 * @return array|WP_Error
	 */
	public static function rescan_current_post( $post_id ) {
		$post_id = absint( $post_id );

		if (
			! class_exists(
				'SRK_Internal_Linking_Settings'
			) ||
			! SRK_Internal_Linking_Settings::is_enabled()
		) {
			return new WP_Error(
				'srk_il_disabled',
				__(
					'Internal Linking is disabled in Settings.',
					'seo-repair-kit'
				)
			);
		}

		$post = get_post( $post_id );

		if ( ! $post ) {
			return new WP_Error(
				'srk_il_missing_post',
				__(
					'The selected post could not be found.',
					'seo-repair-kit'
				)
			);
		}

		if ( ! current_user_can( 'edit_post', $post_id ) ) {
			return new WP_Error(
				'srk_il_permission_denied',
				__(
					'You do not have permission to scan this post.',
					'seo-repair-kit'
				)
			);
		}

		/*
		* Refresh the indexed source content.
		*/
		SRK_Internal_Linking_Indexer::index_single_post(
			$post_id
		);

		/*
		* Generated keywords may have been created using the previous stopword list.
		* Custom keywords remain preserved.
		*/
		SRK_Internal_Linking_DB::delete_auto_keywords_for_post(
			$post_id
		);

		SRK_Internal_Linking_Keywords::generate_for_post(
			$post_id,
			array(
				'title' => $post->post_title,
			)
		);

		/*
		* Remove only active generated suggestions. Inserted, ignored and removed
		* history remains preserved.
		*/
		SRK_Internal_Linking_DB::delete_pending_opportunities_by_source(
			$post_id
		);

		$result =
			SRK_Internal_Linking_Opportunities::generate_for_source_post(
				$post_id,
				array(
					'preserve_existing' => false,
					'defer_until_ai'    => false,
				)
			);

		return array(
			'post_id' => $post_id,
			'created' => absint(
				$result['created'] ?? 0
			),
			'candidates' => absint(
				$result['candidates'] ?? 0
			),
			'opportunities' =>
				SRK_Internal_Linking_DB::get_editor_grouped_opportunities(
					$post_id,
					10
				),
		);
	}

}
