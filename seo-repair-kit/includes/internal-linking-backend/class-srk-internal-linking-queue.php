<?php

/**
 * Background queue for Internal Linking heavy tasks.
 *
 * Uses Action Scheduler when available, otherwise WP-Cron.
 *
 * @package SEO_Repair_Kit
 */
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Dispatches async jobs for AI and batch processing.
 */
class SRK_Internal_Linking_Queue {
	const HOOK_SINGLE_OPPORTUNITY = 'srk_il_queue_single_opportunity';
	const HOOK_EMBEDDING_BATCH = 'srk_il_queue_embedding_batch';
	const HOOK_SEMANTIC_GRAPH = 'srk_il_queue_semantic_graph';
	const HOOK_HYBRID_OPPORTUNITIES = 'srk_il_queue_hybrid_opportunities';
	const HOOK_AI_PIPELINE = 'srk_il_queue_ai_pipeline';
	const RULE_REFRESH_LOCK_PREFIX = 'srk_il_rule_refresh_lock_';
	const RULE_REFRESH_DIRTY_PREFIX = 'srk_il_rule_refresh_dirty_';

	/**
	 * Per-post background AI processing state.
	 */
	const AI_POST_PROCESSING_PREFIX = 'srk_il_ai_post_processing_';
	const AI_POST_DIRTY_PREFIX = 'srk_il_ai_post_dirty_';

	/**
	 * Register queue hooks.
	 *
	 * @return void
	 */
	public static function init() {
		add_action( self::HOOK_SINGLE_OPPORTUNITY, array( __CLASS__, 'handle_single_opportunity' ), 10, 1 );
		add_action( self::HOOK_EMBEDDING_BATCH, array( __CLASS__, 'handle_embedding_batch' ), 10, 1 );
		add_action( self::HOOK_SEMANTIC_GRAPH, array( __CLASS__, 'handle_semantic_graph' ), 10, 1 );
		add_action( self::HOOK_HYBRID_OPPORTUNITIES, array( __CLASS__, 'handle_hybrid_opportunities' ), 10, 1 );
		add_action( self::HOOK_AI_PIPELINE, array( __CLASS__, 'handle_ai_pipeline' ), 10, 1 );
	}

	/**
	 * Check whether Internal Linking tables are ready for queue processing.
	 *
	 * @return bool
	 */
	private static function is_database_ready() {
		return class_exists( 'SRK_License_Helper' ) && SRK_License_Helper::is_internal_linking_enabled() && class_exists( 'SRK_Internal_Linking_DB' ) && SRK_Internal_Linking_DB::is_schema_ready();
	}

	/**
	 * Process one debounced saved-post opportunity refresh.
	 *
	 * The scan row is created only when the worker actually starts.
	 *
	 * @param array $args Queue arguments.
	 *
	 * @return void
	 */
	public static function handle_single_opportunity( $args = array() ) {
		if ( ! self::is_database_ready() || ! class_exists( 'SRK_Internal_Linking_Service' ) ) {
			return;
		}
		$args = is_array( $args ) ? $args : array();
		$post_id = absint( $args['post_id'] ?? 0 );
		if ( ! $post_id ) {
			return;
		}
		$lock_key = self::RULE_REFRESH_LOCK_PREFIX . $post_id;
		$dirty_key = self::RULE_REFRESH_DIRTY_PREFIX . $post_id;

		/*
		* Capture the actual version this worker is about to process.
		*
		* If Auto Linking or another nested save already happened
		* before this delayed worker starts, this will already be
		* the final/newest content hash.
		*/
		$start_hash = self::get_current_content_hash( $post_id );

		/*
		* Create the scan only now that a real worker exists.
		*/
		$start = SRK_Internal_Linking_Service::start_opportunity_generation( $post_id );
		if ( is_wp_error( $start ) ) {
			delete_transient( $lock_key );
			delete_transient( $dirty_key );
			return;
		}
		$scan_id = absint( $start['scan_id'] ?? 0 );
		if ( ! $scan_id ) {
			delete_transient( $lock_key );
			delete_transient( $dirty_key );
			return;
		}
		$result = SRK_Internal_Linking_Service::run_opportunity_batch( $scan_id, 1, $post_id );
		if ( is_array( $result ) && ! empty( $result['complete'] ) ) {
			delete_post_meta( $post_id, '_srk_il_opportunities_stale' );
		}

		/*
		* Detect a genuine content change that happened WHILE
		* this worker was processing.
		*/
		$end_hash = self::get_current_content_hash( $post_id );
		$dirty = (bool) get_transient( $dirty_key );
		delete_transient( $lock_key );
		delete_transient( $dirty_key );

		/*
		* Nested saves that happened before this worker started
		* are already included in $start_hash.
		*
		* Only queue another run if the content changed during
		* actual processing.
		*/
		if ( $dirty && '' !== $start_hash && '' !== $end_hash && $start_hash !== $end_hash ) {
			self::queue_single_opportunity_refresh( $post_id, 5 );
		}
	}

	/**
	 * Enqueue a background job.
	 *
	 * @param string $hook  Queue hook name.
	 * @param array  $args  Job arguments.
	 * @param int    $delay Delay in seconds.
	 * @return void
	 */
	public static function enqueue( $hook, $args = array(), $delay = 0 ) {
		$hook = sanitize_key( $hook );
		$args = is_array( $args ) ? $args : array();
		$delay = max( 0, absint( $delay ) );
		$payload = array( $args );
		$group = 'srk-internal-linking';
		if ( $delay > 0 && function_exists( 'as_schedule_single_action' ) ) {
			as_schedule_single_action( time() + $delay, $hook, $payload, $group );
			return;
		}
		if ( function_exists( 'as_enqueue_async_action' ) ) {
			as_enqueue_async_action( $hook, $payload, $group );
			return;
		}
		if ( function_exists( 'as_schedule_single_action' ) ) {
			as_schedule_single_action( time() + max( 1, $delay ), $hook, $payload, $group );
			return;
		}
		wp_schedule_single_event( time() + max( 1, $delay ), $hook, $payload );
	}

	/**
	 * Queue one debounced rule-opportunity refresh for a saved post.
	 *
	 * Multiple save_post events occurring during one logical WordPress update
	 * are collapsed into one actual opportunity scan.
	 *
	 * @param int $post_id Post ID.
	 * @param int $delay   Delay in seconds.
	 *
	 * @return array<string,mixed>
	 */
	public static function queue_single_opportunity_refresh( $post_id, $delay = 5 ) {
		if ( ! class_exists( 'SRK_License_Helper' ) || ! SRK_License_Helper::is_internal_linking_enabled() ) {
			return array( 'queued' => false, 'reason' => 'internal_linking_paid_inactive' );
		}

		$post_id = absint( $post_id );
		$delay = max( 1, absint( $delay ) );
		if ( ! $post_id ) {
			return array( 'queued' => false, );
		}
		$lock_key = self::RULE_REFRESH_LOCK_PREFIX . $post_id;
		$dirty_key = self::RULE_REFRESH_DIRTY_PREFIX . $post_id;

		/*
		* Another save happened while this post already has a
		* queued/running rule refresh.
		*
		* Do not create another scan now.
		*/
		if ( get_transient( $lock_key ) ) {
			set_transient( $dirty_key, 1, 2 * MINUTE_IN_SECONDS );
			update_post_meta( $post_id, '_srk_il_opportunities_stale', 1 );
			return array( 'queued' => false, 'coalesced' => true, );
		}

		/*
		* Recovery-safe lock.
		*
		* If a worker never executes, the transient expires
		* automatically and cannot permanently block the post.
		*/
		set_transient( $lock_key, 1, 2 * MINUTE_IN_SECONDS );
		delete_transient( $dirty_key );
		update_post_meta( $post_id, '_srk_il_opportunities_stale', 1 );
		self::enqueue( self::HOOK_SINGLE_OPPORTUNITY, array( 'post_id' => $post_id, ), $delay );
		return array( 'queued' => true, 'coalesced' => false, );
	}

	/**
	 * Get the latest indexed content hash for one post.
	 *
	 * Falls back to the live WordPress post if the content index row
	 * is temporarily unavailable.
	 *
	 * @param int $post_id Post ID.
	 *
	 * @return string
	 */
	private static function get_current_content_hash( $post_id ) {
		$post_id = absint( $post_id );
		if ( ! $post_id ) {
			return '';
		}
		if ( class_exists( 'SRK_Internal_Linking_DB' ) ) {
			$row = SRK_Internal_Linking_DB::get_content_index_by_post_id( $post_id );
			if ( is_array( $row ) && ! empty( $row['content_hash'] ) ) {
				return sanitize_text_field( $row['content_hash'] );
			}
		}
		$post = get_post( $post_id );
		if ( ! $post ) {
			return '';
		}
		return hash( 'sha256', (string) $post->post_title . '|' . (string) $post->post_content );
	}

	/**
	 * Queue embedding generation for one post.
	 *
	 * @param int $post_id Post ID.
	 * @return void
	 */
	public static function queue_embedding( $post_id ) {
		if ( ! class_exists( 'SRK_License_Helper' ) || ! SRK_License_Helper::is_internal_linking_enabled() ) {
			return false;
		}

		$post_id = absint( $post_id );
		if ( ! $post_id || ! class_exists( 'SRK_Internal_Linking_AI_Engine' ) || ! SRK_Internal_Linking_AI_Engine::is_enabled() ) {
			return false;
		}
		$processing_key = self::AI_POST_PROCESSING_PREFIX . $post_id;
		$dirty_key = self::AI_POST_DIRTY_PREFIX . $post_id;

		/*
		* An AI job is already queued/running.
		*
		* Don't duplicate expensive provider calls.
		* Record that the post changed so the worker can decide
		* whether one follow-up run is necessary.
		*/
		if ( get_transient( $processing_key ) ) {
			set_transient( $dirty_key, 1, 15 * MINUTE_IN_SECONDS );
			return false;
		}
		set_transient( $processing_key, 1, 15 * MINUTE_IN_SECONDS );
		delete_transient( $dirty_key );
		delete_transient( 'srk_il_ai_status' );
		self::enqueue( self::HOOK_EMBEDDING_BATCH, array( 'post_ids' => array( $post_id, ), ) );
		return true;
	}

	/**
	 * Check whether this post currently has queued/running  AI-Powered .
	 *
	 * @param int $post_id Post ID.
	 *
	 * @return bool
	 */
	public static function is_post_ai_processing( $post_id ) {
		$post_id = absint( $post_id );
		if ( ! $post_id ) {
			return false;
		}
		return (bool) get_transient( self::AI_POST_PROCESSING_PREFIX . $post_id );
	}

	/**
	 * Queue full AI pipeline after rule-based opportunity generation.
	 *
	 * @return void
	 */
	public static function queue_ai_pipeline( $scan_run_id = 0 ) {
		if ( ! class_exists( 'SRK_License_Helper' ) || ! SRK_License_Helper::is_internal_linking_enabled() ) {
			return;
		}

		if ( ! class_exists( 'SRK_Internal_Linking_AI_Engine' ) || ! SRK_Internal_Linking_AI_Engine::is_enabled() || ! SRK_Internal_Linking_AI_Engine::semantic_matching_enabled() ) {
			return;
		}
		self::enqueue( self::HOOK_AI_PIPELINE, array( 'scan_run_id' => absint( $scan_run_id ), 'offset' => 0, ) );
	}

	/**
	 * Handle embedding batch job.
	 *
	 * @param array $args Job args.
	 * @return void
	 */
	public static function handle_embedding_batch( $args = array() ) {
		if ( ! self::is_database_ready() || ! class_exists( 'SRK_Internal_Linking_AI_Engine' ) ) {
			return;
		}
		$args = is_array( $args ) ? $args : array();
		$scan_run_id = absint( $args['scan_run_id'] ?? 0 );
		$post_ids = ! empty( $args['post_ids'] ) ? array_values( array_filter( array_map( 'absint', (array) $args['post_ids'] ) ) ) : array();
		$limit = self::batch_size();

		/*
		* Single-post embedding flow.
		*/
		if ( ! empty( $post_ids ) ) {
			foreach ( $post_ids as $post_id ) {
				$post_id = absint( $post_id );
				if ( ! $post_id ) {
					continue;
				}
				$processing_key = self::AI_POST_PROCESSING_PREFIX . $post_id;
				$dirty_key = self::AI_POST_DIRTY_PREFIX . $post_id;
				$start_hash = self::get_current_content_hash( $post_id );
				$result = SRK_Internal_Linking_AI_Engine::generate_embedding( $post_id );

				/*
				* AI failure must not touch or hide rule suggestions.
				*/
				if ( ! is_wp_error( $result ) ) {
					SRK_Internal_Linking_AI_Engine::generate_semantic_links_for_post( $post_id, $scan_run_id );
				}
				$end_hash = self::get_current_content_hash( $post_id );
				$dirty = (bool) get_transient( $dirty_key );
				delete_transient( $processing_key );
				delete_transient( $dirty_key );

				/*
				* Content genuinely changed while AI was executing.
				*
				* Queue exactly one new enhancement for the newest
				* saved version.
				*/
				if ( $dirty && '' !== $start_hash && '' !== $end_hash && $start_hash !== $end_hash ) {
					self::queue_embedding( $post_id );
				}
			}
			delete_transient( 'srk_il_ai_status' );
			return;
		}

		/*
		* Pending embedding rows are a shrinking dataset.
		*/
		$offset = absint( $args['offset'] ?? 0 );
		$rows = SRK_Internal_Linking_DB::get_ai_source_posts( $limit, $offset );
		foreach ( $rows as $row ) {
			SRK_Internal_Linking_AI_Engine::generate_embedding( absint( $row['post_id'] ) );
		}
		if ( count( $rows ) >= $limit ) {
			self::enqueue( self::HOOK_AI_PIPELINE, array( 'scan_run_id' => $scan_run_id, 'offset' => $offset + $limit, ), 5 );
			return;
		}
		self::enqueue( self::HOOK_SEMANTIC_GRAPH, array( 'scan_run_id' => $scan_run_id, 'offset' => 0, ), 5 );
	}

	/**
	 * Handle semantic graph batch job.
	 *
	 * @param array $args Job args.
	 * @return void
	 */
	public static function handle_semantic_graph( $args = array() ) {

		if ( ! self::is_database_ready() || ! class_exists( 'SRK_Internal_Linking_AI_Engine' ) ) {
			return;
		}

		$args = is_array( $args )
			? $args
			: array();

		$scan_run_id = absint(
			$args['scan_run_id'] ?? 0
		);

		$offset = absint(
			$args['offset'] ?? 0
		);

		$limit =
			self::batch_size();

		if ( $scan_run_id ) {

			SRK_Internal_Linking_DB::update_scan_run(
				$scan_run_id,
				array(
					'status'  => 'running',
					'message' => __(
						'Generating AI link opportunities.',
						'seo-repair-kit'
					),
				)
			);
		}

		$result =
			SRK_Internal_Linking_AI_Engine::
				generate_semantic_graph_batch(
					$limit,
					$offset,
					$scan_run_id
				);

		if ( is_wp_error( $result ) ) {

			if ( $scan_run_id ) {

				SRK_Internal_Linking_DB::update_scan_run(
					$scan_run_id,
					array(
						'status'  => 'failed',
						'message' => $result->get_error_message(),
					)
				);
			}

			delete_transient(
				'srk_il_ai_status'
			);

			return;
		}

		/*
		* The AI engine already tells us how many source posts this
		* semantic batch processed. Reuse that value rather than
		* creating another count/query.
		*/
		$batch_processed = absint(
			$result['processed'] ?? 0
		);

		$processed =
			$offset +
			$batch_processed;

		if ( $scan_run_id ) {

			$scan =
				SRK_Internal_Linking_DB::get_scan_run(
					$scan_run_id
				);

			/*
			* success_items contains posts with usable embeddings.
			* Those are the posts eligible for semantic opportunity
			* discovery.
			*/
			$total = absint(
				$scan['success_items'] ?? 0
			);

			if ( $total > 0 ) {
				$processed = min(
					$total,
					$processed
				);
			}

			SRK_Internal_Linking_DB::update_scan_run(
				$scan_run_id,
				array(
					'processed_items' =>
						$processed,

					'current_batch' =>
						$processed > 0
							? max(
								1,
								absint(
									ceil(
										$processed /
										$limit
									)
								)
							)
							: 0,

					'message' => __(
						'Generating AI link opportunities.',
						'seo-repair-kit'
					),
				)
			);
		}

		delete_transient(
			'srk_il_ai_status'
		);

		/*
		* More analyzed source posts remain.
		*/
		if ( empty( $result['complete'] ) ) {

			self::enqueue(
				self::HOOK_SEMANTIC_GRAPH,
				array(
					'scan_run_id' =>
						$scan_run_id,

					'offset' =>
						$offset +
						$limit,
				),
				5
			);

			return;
		}

		/*
		* Opportunity discovery completed.
		*/
		if ( $scan_run_id ) {

			$scan =
				SRK_Internal_Linking_DB::get_scan_run(
					$scan_run_id
				);

			$total = absint(
				$scan['success_items'] ?? 0
			);

			SRK_Internal_Linking_DB::update_scan_run(
				$scan_run_id,
				array(
					'status' =>
						'completed',

					'processed_items' =>
						$total > 0
							? min(
								$total,
								$processed
							)
							: 0,

					'completed_at' =>
						SRK_Internal_Linking_DB::get_now(),

					'message' => __(
						'AI link opportunity discovery completed.',
						'seo-repair-kit'
					),
				)
			);
		}

		delete_transient(
			'srk_il_ai_status'
		);
	}

	/**
	 * Re-run rule engine on AI-filtered targets (hybrid scoring applied inline).
	 *
	 * @param array $args Job args.
	 * @return void
	 */
	public static function handle_hybrid_opportunities( $args = array() ) {
		if ( ! self::is_database_ready() || ! class_exists( 'SRK_Internal_Linking_Opportunities' ) ) {
			return;
		}
		$args = is_array( $args ) ? $args : array();
		$offset = absint( $args['offset'] ?? 0 );
		$limit = self::batch_size();
		$rows = SRK_Internal_Linking_DB::get_indexed_content_batch( $limit, $offset );
		foreach ( $rows as $row ) {
			SRK_Internal_Linking_Opportunities::generate_for_source_post( absint( $row['post_id'] ) );
		}
		if ( count( $rows ) >= $limit ) {
			self::enqueue( self::HOOK_HYBRID_OPPORTUNITIES, array( 'offset' => $offset + $limit ), 5 );
			return;
		}
	}

	/**
	 * Convert pending semantic links into opportunities.
	 *
	 * Pending rows form a shrinking queue.
	 *
	 * @param array $args Queue arguments.
	 * @return void
	 */
	public static function handle_merge_semantic( $args = array() ) {
		if ( ! self::is_database_ready() ) {
			return;
		}

		$args = is_array( $args ) ? $args : array();
		$scan_run_id = absint( $args['scan_run_id'] ?? 0 );
		if ( $scan_run_id ) {
			SRK_Internal_Linking_DB::finalize_building_opportunities( $scan_run_id );
			SRK_Internal_Linking_DB::update_scan_run( $scan_run_id, array( 'status' => 'completed', 'completed_at' => SRK_Internal_Linking_DB::get_now(), 'message' => __( 'Canonical opportunities completed.',
			'seo-repair-kit' ), ) );
		}
		delete_transient( 'srk_il_ai_status' );
	}

	/**
	 * Run complete AI pipeline directly from WordPress posts.
	 *
	 * AI content discovery does not depend on the Internal Linking Content Index.
	 *
	 * @param array $args Job args.
	 * @return void
	 */
	public static function handle_ai_pipeline( $args = array() ) {
		if ( ! self::is_database_ready() || ! class_exists( 'SRK_Internal_Linking_AI_Engine' ) ) {
			return;
		}
		$args = is_array( $args ) ? $args : array();
		$scan_run_id = absint( $args['scan_run_id'] ?? 0 );
		$offset = absint( $args['offset'] ?? 0 );
		$limit = self::batch_size();
		if ( $scan_run_id ) {
			SRK_Internal_Linking_DB::update_scan_run( $scan_run_id, array( 'status' => 'running', 'current_batch' => absint( floor( $offset / $limit ) + 1 ), 'message' => __( 'Generating AI embeddings from WordPress content.',
			'seo-repair-kit' ), ) );
		}

		/*
		* AI reads its source IDs directly from wp_posts.
		*/
		$rows = SRK_Internal_Linking_DB::get_ai_source_posts( $limit, $offset );

		/*
		* No more WordPress posts.
		* Move to semantic relationship generation.
		*/
		if ( empty( $rows ) ) {
			self::enqueue( self::HOOK_SEMANTIC_GRAPH, array( 'scan_run_id' => $scan_run_id, 'offset' => 0, ), 5 );
			return;
		}
		$success = 0;
		$failed  = 0;

		foreach ( $rows as $row ) {

			$post_id = absint(
				$row['post_id'] ?? 0
			);

			if ( ! $post_id ) {
				continue;
			}

			$result =
				SRK_Internal_Linking_AI_Engine::generate_embedding(
					$post_id
				);

			if ( is_wp_error( $result ) ) {
				$failed++;
				continue;
			}

			$success++;
		}

		if ( $scan_run_id ) {

			$scan =
				SRK_Internal_Linking_DB::get_scan_run(
					$scan_run_id
				);

			SRK_Internal_Linking_DB::update_scan_run(
				$scan_run_id,
				array(
					/*
					* processed_items is reserved for the semantic
					* opportunity-discovery stage.
					*/
					'success_items' =>
						absint(
							$scan['success_items'] ?? 0
						) + $success,

					'failed_items' =>
						absint(
							$scan['failed_items'] ?? 0
						) + $failed,
				)
			);
		}
		/*
		* Continue to next WordPress post batch.
		*/
		if ( count( $rows ) >= $limit ) {
			self::enqueue( self::HOOK_AI_PIPELINE, array( 'scan_run_id' => $scan_run_id, 'offset' => $offset + $limit, ), 5 );
			return;
		}

		/*
		* Last batch completed.
		*/
		self::enqueue( self::HOOK_SEMANTIC_GRAPH, array( 'scan_run_id' => $scan_run_id, 'offset' => 0, ), 5 );
	}

	/**
	 * Get configured AI batch size (5–10).
	 *
	 * @return int
	 */
	private static function batch_size() {
		$settings = SRK_Internal_Linking_Settings::get();
		$size = absint( $settings['ai_batch_size'] ?? 5 );
		return min( 10, max( 5, $size ) );
	}

	/**
	 * Clear all scheduled internal linking queue events.
	 *
	 * @return void
	 */
	public static function clear_scheduled_events() {
		$hooks = array( self::HOOK_EMBEDDING_BATCH, self::HOOK_SEMANTIC_GRAPH, self::HOOK_HYBRID_OPPORTUNITIES, self::HOOK_AI_PIPELINE );
		foreach ( $hooks as $hook ) {
			wp_clear_scheduled_hook( $hook );
		}
	}
}
