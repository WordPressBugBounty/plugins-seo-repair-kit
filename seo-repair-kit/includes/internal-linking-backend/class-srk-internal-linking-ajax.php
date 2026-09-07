<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Internal Linking AJAX controller.
 *
 * Handles all admin/editor AJAX requests for the SEO Repair Kit Internal Linking
 * feature. This class acts only as a secure request/response layer; heavy
 * business logic stays inside dedicated service classes such as the Indexer,
 * Keywords engine, Opportunities engine, URL Changer, Settings, and DB layer.
 */
class SRK_Internal_Linking_Ajax {

	/**
	 * Register Internal Linking AJAX actions.
	 *
	 * @return void
	 */
	public static function init() {
		add_action( 'wp_ajax_srk_il_start_content_index', array( __CLASS__, 'start_content_index' ) );
		add_action( 'wp_ajax_srk_il_run_content_index_batch', array( __CLASS__, 'run_content_index_batch' ) );
		add_action( 'wp_ajax_srk_il_save_settings', array( __CLASS__, 'save_settings' ) );
		add_action( 'wp_ajax_srk_il_reset_settings', array( __CLASS__, 'reset_settings' ) );
		add_action( 'wp_ajax_srk_il_start_keywords_refresh', array( __CLASS__, 'start_keywords_refresh' ) );
		add_action( 'wp_ajax_srk_il_run_keywords_batch', array( __CLASS__, 'run_keywords_batch' ) );
		add_action( 'wp_ajax_srk_il_add_custom_keyword', array( __CLASS__, 'add_custom_keyword' ) );
		add_action( 'wp_ajax_srk_il_get_editor_keywords', array( __CLASS__, 'get_editor_keywords' ) );
		add_action( 'wp_ajax_srk_il_generate_editor_keywords', array( __CLASS__, 'generate_editor_keywords' ) );
		add_action( 'wp_ajax_srk_il_start_opportunities_refresh', array( __CLASS__, 'start_opportunities_refresh' ) );
		add_action( 'wp_ajax_srk_il_run_opportunities_batch', array( __CLASS__, 'run_opportunities_batch' ) );
		add_action( 'wp_ajax_srk_il_ignore_opportunity', array( __CLASS__, 'ignore_opportunity' ) );
		add_action( 'wp_ajax_srk_il_editor_add_custom_keyword', array( __CLASS__, 'editor_add_custom_keyword' ) );
		add_action( 'wp_ajax_srk_il_get_editor_suggestions', array( __CLASS__, 'get_editor_suggestions' ) );
		add_action( 'wp_ajax_srk_il_get_editor_suggestion_status', array( __CLASS__, 'get_editor_suggestion_status', ));
		add_action( 'wp_ajax_srk_il_apply_editor_suggestion', array( __CLASS__, 'apply_editor_suggestion' ) );
		add_action( 'wp_ajax_srk_il_apply_opportunity', array( __CLASS__, 'apply_opportunity' ) );
		add_action( 'wp_ajax_srk_il_remove_inserted_link', array( __CLASS__, 'remove_inserted_link' ) );
		add_action( 'wp_ajax_srk_il_url_changer_dry_run', array( __CLASS__, 'url_changer_dry_run' ) );
		add_action( 'wp_ajax_srk_il_url_changer_replace', array( __CLASS__, 'url_changer_replace' ) );
		add_action( 'wp_ajax_srk_il_url_changer_undo', array( __CLASS__, 'url_changer_undo' ) );
		add_action( 'wp_ajax_srk_il_ignore_orphan_content', array( __CLASS__, 'ignore_orphan_content' ) );
		add_action( 'wp_ajax_srk_il_find_orphan_opportunities', array( __CLASS__, 'find_orphan_opportunities' ) );
		add_action( 'wp_ajax_srk_il_refresh_orphan_content', array( __CLASS__, 'refresh_orphan_content' ) );
		add_action( 'wp_ajax_srk_il_delete_custom_keyword', array( __CLASS__, 'delete_custom_keyword' ) );
		add_action( 'wp_ajax_srk_il_editor_delete_custom_keyword', array( __CLASS__, 'editor_delete_custom_keyword' ) );
		add_action( 'wp_ajax_srk_il_auto_create_rule', array( __CLASS__, 'auto_create_rule' ) );
		add_action( 'wp_ajax_srk_il_auto_get_rules', array( __CLASS__, 'auto_get_rules' ) );
		add_action( 'wp_ajax_srk_il_auto_scan_rule', array( __CLASS__, 'auto_scan_rule' ) );
		add_action( 'wp_ajax_srk_il_auto_apply_selected', array( __CLASS__, 'auto_apply_selected' ) );
		add_action( 'wp_ajax_srk_il_auto_apply_rule', array( __CLASS__, 'auto_apply_rule' ) );
		add_action( 'wp_ajax_srk_il_auto_delete_rule', array( __CLASS__, 'auto_delete_rule' ) );
		add_action( 'wp_ajax_srk_il_auto_update_rule_status', array( __CLASS__, 'auto_update_rule_status' ) );
		add_action( 'wp_ajax_srk_il_auto_remove_post_links', array( __CLASS__, 'auto_remove_post_links' ) );
		add_action( 'wp_ajax_srk_il_auto_remove_all_rule_links', array( __CLASS__, 'auto_remove_all_rule_links' ) );
		add_action( 'wp_ajax_srk_il_auto_save_settings', array( __CLASS__, 'auto_save_settings' ) );
		add_action( 'wp_ajax_srk_il_auto_get_rule', array( __CLASS__, 'auto_get_rule' ) );
		add_action( 'wp_ajax_srk_il_auto_update_rule', array( __CLASS__, 'auto_update_rule' ) );
		add_action( 'wp_ajax_srk_il_get_report', array( __CLASS__, 'get_report' ) );
		add_action( 'wp_ajax_srk_il_get_ai_status', array( __CLASS__, 'get_ai_status' ) );
		add_action( 'wp_ajax_srk_il_start_ai_pipeline', array( __CLASS__, 'start_ai_pipeline' ) );
		add_action( 'wp_ajax_srk_il_test_ai_provider', array( __CLASS__, 'test_ai_provider', ) );
		add_action( 'wp_ajax_srk_il_test_openrouter_key', array( __CLASS__, 'test_ai_provider', ) );
		add_action( 'wp_ajax_srk_il_get_domain_posts', 'SRK_Internal_Linking_Ajax::get_domain_posts' );
		add_action( 'wp_ajax_srk_il_get_domain_links', 'SRK_Internal_Linking_Ajax::get_domain_links' );
		add_action( 'wp_ajax_srk_il_get_dashboard_data', array( __CLASS__, 'get_dashboard_data' ) );
		add_action( 'wp_ajax_srk_il_get_language_stopwords', array( __CLASS__, 'get_language_stopwords', ) );

	}

	/**
	 * Validate admin AJAX requests.
	 *
	 * Used for Internal Linking dashboard actions that require plugin-level
	 * management access.
	 *
	 * @return void
	 */
	private static function validate_request() {
		check_ajax_referer( 'srk_internal_linking_nonce', 'nonce' );

		if ( ! current_user_can( 'manage_options' ) ) {
			wp_send_json_error(
				array(
					'message' => __( 'You do not have permission to run this action.', 'seo-repair-kit' ),
				),
				403 );
		}

		self::ensure_paid_active();

		self::ensure_database_ready();
	}

	/**
	 * Validate editor AJAX requests for a specific post.
	 *
	 * @param int $post_id Post ID being edited.
	 * @return void
	 */
	private static function validate_editor_request( $post_id ) {
		check_ajax_referer( 'srk_internal_linking_nonce', 'nonce' );

		if ( ! $post_id || ! current_user_can( 'edit_post', $post_id ) ) {
			wp_send_json_error(
				array(
					'message' => __( 'You do not have permission to edit this post.', 'seo-repair-kit' ),
				),
				403 );
		}

		self::ensure_paid_active();

		self::ensure_database_ready();
	}

	/**
	 * Validate URL changer requests.
	 *
	 * Kept as a separate method because the URL changer originally used this
	 * request gate. Logic is unchanged.
	 *
	 * @return void
	 */
	private static function verify_request() {
		check_ajax_referer( 'srk_internal_linking_nonce', 'nonce' );

		if ( ! current_user_can( 'manage_options' ) ) {
			wp_send_json_error(
				array(
					'message' => __( 'You do not have permission to perform this action.', 'seo-repair-kit' ),
				),
				403 );
		}

		self::ensure_paid_active();

		self::ensure_database_ready();
	}

	/**
	 * Ensure the paid Internal Linking module is active.
	 *
	 * @return void
	 */
	private static function ensure_paid_active() {
		if ( class_exists( 'SRK_License_Helper' ) && SRK_License_Helper::is_internal_linking_enabled() ) {
			return;
		}

		wp_send_json_error(
			array(
				'message' => __( 'Internal Linking is a paid module. Please upgrade or renew Internal Linking to use this feature.', 'seo-repair-kit' ),
				'code'    => 'srk_il_paid_required',
			),
			403
		);
	}
	/**
	 * Ensure Internal Linking database tables are ready before DB-dependent AJAX work.
	 *
	 * @return void
	 */
	private static function ensure_database_ready() {
		if ( class_exists( 'SRK_Internal_Linking_DB' ) && SRK_Internal_Linking_DB::is_schema_ready() ) {
			return;
		}

		wp_send_json_error(
			array(
				'message' => __( 'Internal Linking database is not ready yet. Please open the WordPress admin area and try again.', 'seo-repair-kit' ),
				'code'    => 'srk_il_database_not_ready',
			),
			503
		);
	}

	/**
	 * Sanitize an array of key-like values.
	 *
	 * @param array $items Raw values.
	 * @return array
	 */
	private static function sanitize_key_array( $items ) {
		$items = is_array( $items ) ? $items : array();

		return array_values( array_filter( array_map( 'sanitize_key', $items ) ) );
	}

	/**
	 * Sanitize an array of text values.
	 *
	 * @param array $items Raw values.
	 * @return array
	 */
	private static function sanitize_text_array( $items ) {
		$items = is_array( $items ) ? $items : array();

		return array_values( array_filter( array_map( 'sanitize_text_field', $items ) ) );
	}

	/**
	 * Read a posted array value, unslash it once, and preserve a fallback.
	 *
	 * @param string $key     Request key.
	 * @param array  $default Default value.
	 * @return array
	 */
	private static function get_post_array( $key, $default = array() ) {
		if ( ! isset( $_POST[ $key ] ) ) {
			return $default;
		}

		$value = wp_unslash( $_POST[ $key ] );

		return is_array( $value ) ? $value : $default;
	}

	/**
	 * Start a content index scan run.
	 *
	 * @return void
	 */
	public static function start_content_index() {
		self::validate_request();

		if ( ! class_exists( 'SRK_Internal_Linking_Service' ) ) {
			wp_send_json_error( array( 'message' => __( 'Service layer is not loaded.', 'seo-repair-kit' ) ), 500 );
		}

		wp_send_json_success( SRK_Internal_Linking_Service::start_scan() );
	}

	/**
	 * Run one batch of the content index scan.
	 *
	 * @return void
	 */
	public static function run_content_index_batch() {
		self::validate_request();

		$scan_id = isset( $_POST['scan_id'] ) ? absint( wp_unslash( $_POST['scan_id'] ) ) : 0;
		$page    = isset( $_POST['page'] ) ? absint( wp_unslash( $_POST['page'] ) ) : 1;

		if ( ! $scan_id ) {
			wp_send_json_error( array( 'message' => __( 'Missing scan ID.', 'seo-repair-kit' ) ), 400 );
		}

		if ( ! class_exists( 'SRK_Internal_Linking_Service' ) ) {
			wp_send_json_error( array( 'message' => __( 'Service layer is not loaded.', 'seo-repair-kit' ) ), 500 );
		}

		wp_send_json_success( SRK_Internal_Linking_Service::run_scan_batch( $scan_id, $page ) );
	}

	/**
	 * Save Internal Linking settings from the settings tab.
	 *
	 * @return void
	 */
	public static function save_settings() {
		self::validate_request();

		$settings = self::get_post_array( 'settings' );

		if ( ! class_exists( 'SRK_Internal_Linking_Settings' ) ) {
			wp_send_json_error(
				array(
					'message' => __(
						'Internal Linking settings class is not loaded.',
						'seo-repair-kit'
					),
				),
				500 );
		}

		$clean = SRK_Internal_Linking_Settings::save( $settings );

		delete_transient( 'srk_il_ai_status' );

		/*
		* Remove indexed rows and generated data that are now outside
		* the selected post-type/status/blacklist scope.
		*/
		if (
			class_exists( 'SRK_Internal_Linking_Indexer' ) && method_exists( 'SRK_Internal_Linking_Indexer', 'synchronize_scope' ) ) {
			SRK_Internal_Linking_Indexer::synchronize_scope();
		}

		/*
		* Stop AI jobs when the complete module is disabled.
		*/
		if ( empty( $clean['enabled'] ) && class_exists( 'SRK_Internal_Linking_Queue' ) ) {
			SRK_Internal_Linking_Queue::clear_scheduled_events();
		}

		wp_send_json_success(
			array(
				'message'  => __(
					'Settings saved successfully.',
					'seo-repair-kit'
				),
				'settings' => $clean,
			)
		);
	}

	/**
	 * Reset Internal Linking settings to defaults.
	 *
	 * @return void
	 */
	public static function reset_settings() {
		self::validate_request();

		if ( ! class_exists( 'SRK_Internal_Linking_Settings' ) ) {
			wp_send_json_error(
				array(
					'message' => __( 'Internal Linking settings class is not loaded.', 'seo-repair-kit' ),
				),
				500 );
		}

		$defaults = SRK_Internal_Linking_Settings::reset();

		wp_send_json_success(
			array(
				'message'  => __( 'Settings reset successfully.', 'seo-repair-kit' ),
				'settings' => $defaults,
			)
		);
	}

	/**
	 * Start a full keyword refresh scan.
	 *
	 * @return void
	 */
	public static function start_keywords_refresh() {
		self::validate_request();

		if ( ! class_exists( 'SRK_Internal_Linking_Keywords' ) ) {
			wp_send_json_error( array( 'message' => __( 'Keywords engine is not loaded.', 'seo-repair-kit' ) ), 500 );
		}

		wp_send_json_success( SRK_Internal_Linking_Keywords::start_refresh() );
	}

	/**
	 * Run one keyword refresh batch.
	 *
	 * @return void
	 */
	public static function run_keywords_batch() {
		self::validate_request();

		$scan_id = isset( $_POST['scan_id'] ) ? absint( wp_unslash( $_POST['scan_id'] ) ) : 0;
		$page    = isset( $_POST['page'] ) ? absint( wp_unslash( $_POST['page'] ) ) : 1;

		if ( ! $scan_id ) {
			wp_send_json_error( array( 'message' => __( 'Missing scan ID.', 'seo-repair-kit' ) ), 400 );
		}

		wp_send_json_success( SRK_Internal_Linking_Keywords::refresh_batch( $scan_id, $page ) );
	}

	/**
	 * Add a custom keyword from the admin target keyword screen.
	 *
	 * @return void
	 */
	public static function add_custom_keyword() {
		self::validate_request();

		$post_id = isset( $_POST['post_id'] ) ? absint( wp_unslash( $_POST['post_id'] ) ) : 0;
		$keyword = isset( $_POST['keyword'] ) ? sanitize_text_field( wp_unslash( $_POST['keyword'] ) ) : '';

		$id = SRK_Internal_Linking_Keywords::add_custom_keyword( $post_id, $keyword );

		if ( ! $id ) {
			wp_send_json_error( array( 'message' => __( 'Unable to add keyword.', 'seo-repair-kit' ) ), 400 );
		}

		wp_send_json_success( array( 'message' => __( 'Custom keyword added.', 'seo-repair-kit' ) ) );
	}

	/**
	 * Add a custom keyword from the post editor sidebar.
	 *
	 * @return void
	 */
	public static function editor_add_custom_keyword() {
		$post_id = isset( $_POST['post_id'] ) ? absint( wp_unslash( $_POST['post_id'] ) ) : 0;
		$keyword = isset( $_POST['keyword'] ) ? sanitize_text_field( wp_unslash( $_POST['keyword'] ) ) : '';

		self::validate_editor_request( $post_id );

		if ( '' === $keyword ) {
			wp_send_json_error(
				array(
					'message' => __( 'Keyword is required.', 'seo-repair-kit' ),
				),
				400 );
		}

		$keyword_id = SRK_Internal_Linking_DB::upsert_keyword(
			array(
				'post_id'      => $post_id,
				'keyword'      => $keyword,
				'source'       => 'custom',
				'keyword_type' => 'custom',
				'is_active'    => 1,
			)
		);

		wp_send_json_success(
			array(
				'keyword_id' => $keyword_id,
				'keywords'   => SRK_Internal_Linking_DB::get_keywords_by_post( $post_id ),
				'message'    => __( 'Custom keyword added.', 'seo-repair-kit' ),
			)
		);
	}

	/**
	 * Get/generate keywords for the current editor post.
	 *
	 * @return void
	 */
	public static function get_editor_keywords() {
		$post_id = isset( $_POST['post_id'] ) ? absint( wp_unslash( $_POST['post_id'] ) ) : 0;

		self::validate_editor_request( $post_id );

		wp_send_json_success(
			array(
				'keywords' => SRK_Internal_Linking_DB::get_keywords_by_post( $post_id ),
			)
		);
	}

	/**
	 * Force keyword generation for the current editor post.
	 *
	 * @return void
	 */
	public static function generate_editor_keywords() {
		$post_id = isset( $_POST['post_id'] ) ? absint( wp_unslash( $_POST['post_id'] ) ) : 0;
		$title   = isset( $_POST['title'] ) ? sanitize_text_field( wp_unslash( $_POST['title'] ) ) : '';

		self::validate_editor_request( $post_id );

		$keywords = SRK_Internal_Linking_Keywords::generate_for_post(
			$post_id,
			array(
				'title' => $title,
			)
		);

		wp_send_json_success(
			array(
				'message'  => __( 'Keywords generated successfully.', 'seo-repair-kit' ),
				'keywords' => $keywords,
			)
		);
	}

	/**
	 * Start a full opportunity refresh scan.
	 *
	 * @return void
	 */
	public static function start_opportunities_refresh() {
		self::validate_request();

		if ( ! class_exists( 'SRK_Internal_Linking_Service' ) ) {
			wp_send_json_error(
				array(
					'message' => __(
						'Service layer is not loaded.',
						'seo-repair-kit'
					),
				),
				500 );
		}

		$result = SRK_Internal_Linking_Service::start_opportunity_generation();

		if ( is_wp_error( $result ) ) {
			wp_send_json_error(
				array(
					'message' => $result->get_error_message(),
					'code'    => $result->get_error_code(),
				),
				400 );
		}

		if ( empty( $result['scan_id'] ) ) {
			wp_send_json_error(
				array(
					'message' => __(
						'Unable to create opportunity scan run.',
						'seo-repair-kit'
					),
				),
				500 );
		}

		wp_send_json_success( $result );
	}

	/**
	 * Run one opportunity-generation batch.
	 *
	 * @return void
	 */
	public static function run_opportunities_batch() {
		self::validate_request();

		$scan_id = isset( $_POST['scan_id'] ) ? absint( wp_unslash( $_POST['scan_id'] ) ) : 0;

		$page = isset( $_POST['page'] ) ? max( 1, absint( wp_unslash( $_POST['page'] ) ) ) : 1;

		if ( ! $scan_id ) {
			wp_send_json_error(
				array(
					'message' => __(
						'Missing scan ID.',
						'seo-repair-kit'
					),
				),
				400 );
		}

		if ( ! class_exists( 'SRK_Internal_Linking_Service' ) ) {
			wp_send_json_error(
				array(
					'message' => __(
						'Service layer is not loaded.',
						'seo-repair-kit'
					),
				),
				500 );
		}

		$result = SRK_Internal_Linking_Service::run_opportunity_batch( $scan_id, $page );

		if ( is_wp_error( $result ) ) {
			wp_send_json_error(
				array(
					'message' => $result->get_error_message(),
					'code'    => $result->get_error_code(),
				),
				400 );
		}

		wp_send_json_success( $result );
	}

	/**
	 * Mark a pending opportunity as ignored.
	 *
	 * @return void
	 */
	public static function ignore_opportunity() {
		self::validate_request();

		$id = isset( $_POST['opportunity_id'] ) ? absint( wp_unslash( $_POST['opportunity_id'] ) ) : 0;

		if ( ! $id ) {
			wp_send_json_error( array( 'message' => __( 'Missing opportunity ID.', 'seo-repair-kit' ) ), 400 );
		}

		SRK_Internal_Linking_DB::update_opportunity_status( $id, 'ignored' );

		wp_send_json_success( array( 'message' => __( 'Opportunity ignored.', 'seo-repair-kit' ) ) );
	}

	/**
	 * Get grouped link suggestions for Gutenberg editor.
	 *
	 * @return void
	 */
	public static function get_editor_suggestions() {
		$post_id = isset( $_POST['post_id'] ) ? absint( wp_unslash( $_POST['post_id'] ) ) : 0;

		self::validate_editor_request( $post_id );

		$title   = isset( $_POST['title'] ) ? sanitize_text_field( wp_unslash( $_POST['title'] ) ) : '';
		/*
		* Preserve serialized Gutenberg blocks during temporary analysis.
		* The content is not saved by this request.
		*/
		$content = isset( $_POST['content'] ) ? wp_unslash( (string) $_POST['content'] ) : '';

		$suggestions = SRK_Internal_Linking_Opportunities::generate_for_editor_post(
			$post_id,
			array(
				'title'   => $title,
				'content' => $content,
			)
		);

		$rule_count = 0;
		$ai_count   = 0;

		foreach ( (array) $suggestions as $suggestion ) {

			$type = sanitize_key( $suggestion['selected_type'] ?? $suggestion['type'] ?? 'rule' );

			if (
				in_array(
					$type,
					array(
						'ai',
						'ai_semantic',
					),
					true ) ) {
				$ai_count++;
			} else {
				$rule_count++;
			}
		}

		$status = class_exists( 'SRK_Internal_Linking_Service' ) ? SRK_Internal_Linking_Service::
					get_editor_suggestion_status(
						$post_id,
						array(
							'available' =>
								count(
									$suggestions
								),

							'rule' =>
								$rule_count,

							'ai' =>
								$ai_count,
						)
					) : array();

		wp_send_json_success(
			array(
				'suggestions' =>
					$suggestions,

				'count' =>
					count(
						$suggestions
					),

				'status' =>
					$status,
			)
		);
	}

	/**
	 * Apply an editor suggestion to the currently open Gutenberg content.
	 *
	 * @return void
	 */
	public static function apply_editor_suggestion() {
		check_ajax_referer( 'srk_internal_linking_nonce', 'nonce' );

		$post_id = isset( $_POST['post_id'] ) ? absint( wp_unslash( $_POST['post_id'] ) ) : 0;

		$opportunity_id = isset( $_POST['opportunity_id'] ) ? absint( wp_unslash( $_POST['opportunity_id'] ) ) : 0;

		if ( ! $post_id || ! current_user_can( 'edit_post', $post_id ) ) {
			wp_send_json_error(
				array(
					'message' => __(
						'You do not have permission to edit this post.',
						'seo-repair-kit'
					),
					'code' => 'srk_editor_permission_denied',
				),
				403 );
		}

		self::ensure_paid_active();

		self::ensure_database_ready();

		if ( ! $opportunity_id ) {
			wp_send_json_error(
				array(
					'message' => __(
						'Missing opportunity ID.',
						'seo-repair-kit'
					),
					'code' => 'srk_missing_opportunity_id',
				),
				400 );
		}

		$anchor_text = isset( $_POST['anchor_text'] ) ? sanitize_text_field( wp_unslash( $_POST['anchor_text'] ) ) : '';

		/*
		* Unslash only once.
		*
		* Do not run wp_kses_post() here because Gutenberg block comments and
		* serialized block attributes must remain unchanged.
		*/
		$content = isset( $_POST['content'] ) ? wp_unslash( $_POST['content'] ) : '';

		$result = SRK_Internal_Linking_Opportunities::apply_to_editor_content( $opportunity_id, $post_id, $anchor_text, $content );

		if ( is_wp_error( $result ) ) {
			wp_send_json_error(
				array(
					'message' => $result->get_error_message(),
					'code'    => $result->get_error_code(),
				),
				400 );
		}

		wp_send_json_success( $result );
	}

	/**
	 * Apply a dashboard opportunity directly to the stored post content.
	 *
	 * @return void
	 */
	public static function apply_opportunity() {
		self::validate_request();

		$opportunity_id = isset( $_POST['opportunity_id'] ) ? absint( wp_unslash( $_POST['opportunity_id'] ) ) : 0;
		$anchor_text    = isset( $_POST['anchor_text'] ) ? sanitize_text_field( wp_unslash( $_POST['anchor_text'] ) ) : '';

		if ( ! $opportunity_id ) {
			wp_send_json_error( array( 'message' => __( 'Missing opportunity ID.', 'seo-repair-kit' ) ), 400 );
		}

		$result = SRK_Internal_Linking_Opportunities::apply_opportunity_to_post( $opportunity_id, true, $anchor_text );

		if ( is_wp_error( $result ) ) {
			wp_send_json_error( array( 'message' => $result->get_error_message() ), 400 );
		}

		wp_send_json_success( $result );
	}

	/**
	 * Remove an inserted internal link through AJAX.
	 *
	 * @return void
	 */
	public static function remove_inserted_link() {
		self::validate_request();

		$opportunity_id = isset( $_POST['opportunity_id'] ) ? absint( wp_unslash( $_POST['opportunity_id'] ) ) : 0;

		if ( ! $opportunity_id ) {
			wp_send_json_error(
				array(
					'message' => __(
						'Missing inserted link ID.',
						'seo-repair-kit'
					),
				),
				400 );
		}

		$result = self::perform_remove_inserted_link( $opportunity_id );

		if ( is_wp_error( $result ) ) {
			wp_send_json_error(
				array(
					'message' => $result->get_error_message(),
				),
				400 );
		}

		wp_send_json_success( $result );
	}

	/**
	 * Remove an inserted internal link from source post content.
	 *
	 * The anchor text remains in the content; only the <a> wrapper is removed.
	 *
	 * @param int $opportunity_id Opportunity ID.
	 * @return array|WP_Error
	 */
	private static function perform_remove_inserted_link( $opportunity_id ) {
		$opportunity_id = absint( $opportunity_id );

		if ( ! $opportunity_id ) {
			return new WP_Error( 'srk_invalid_opportunity', __( 'Invalid inserted link ID.', 'seo-repair-kit' ) );
		}

		$opportunity = SRK_Internal_Linking_DB::get_opportunity_by_id( $opportunity_id );

		if ( ! $opportunity ) {
			return new WP_Error( 'srk_missing_opportunity', __( 'Inserted link record was not found.', 'seo-repair-kit' ) );
		}

		if ( 'inserted' !== sanitize_key( $opportunity['status'] ) ) {
			return new WP_Error( 'srk_link_not_inserted', __( 'This link is not currently marked as inserted.', 'seo-repair-kit' ) );
		}

		$source_post_id = absint( $opportunity['source_post_id'] );

		if (
			class_exists(
				'SRK_Internal_Linking_Elementor'
			) &&
			SRK_Internal_Linking_Elementor::
				is_elementor_post(
					$source_post_id
				)
		) {
			return SRK_Internal_Linking_Elementor::
				remove_inserted_opportunity(
					$opportunity_id
				);
		}

		$target_post_id = absint( $opportunity['target_post_id'] );

		$source_post = get_post( $source_post_id );

		if ( ! $source_post ) {
			return new WP_Error( 'srk_missing_source_post', __( 'Source post was not found.', 'seo-repair-kit' ) );
		}

		if ( ! current_user_can( 'edit_post', $source_post_id ) ) {
			return new WP_Error(
				'srk_remove_permission_denied', __( 'You do not have permission to edit the source post.', 'seo-repair-kit' ) );
		}

		$anchor_text = trim( wp_strip_all_tags( (string) $opportunity['anchor_text'] ) );

		$target_url = ! empty( $opportunity['target_url'] )
			? esc_url_raw( $opportunity['target_url'] ) : get_permalink( $target_post_id );

		if ( '' === $anchor_text || ! $target_url ) {
			return new WP_Error(
				'srk_invalid_inserted_link',
				__( 'The inserted link record does not contain a valid anchor or target URL.', 'seo-repair-kit' ) );
		}

		$new_content = self::remove_link_from_content( $source_post->post_content, $anchor_text, $target_url );

		if ( $new_content === $source_post->post_content ) {
			return new WP_Error(
				'srk_inserted_link_not_found',
				__(
					'The inserted link could not be found in the current post content. It may already have been edited or removed manually.',
					'seo-repair-kit' ) );
		}

		$updated = wp_update_post(
			array(
				'ID'           => $source_post_id,
				'post_content' => $new_content,
			),
			true );

		if ( is_wp_error( $updated ) ) {
			return $updated;
		}

		if ( is_wp_error( $updated ) ) {
				return $updated;
			}

			/*
			* Verify that WordPress actually saved the content
			* without the selected internal link.
			*/
			clean_post_cache( $source_post_id );

			$saved_content = (string) get_post_field( 'post_content', $source_post_id, 'raw' );

			/*
			* If remove_link_from_content() can still remove the same
			* link, that means the link still exists in saved content.
			*/
			$verification_content = self::remove_link_from_content( $saved_content, $anchor_text, $target_url );

			if ( $verification_content !== $saved_content ) {
				return new WP_Error(
					'srk_link_removal_verification_failed',
					__( 'WordPress updated the post, but the internal link could not be verified as removed.', 'seo-repair-kit' ) );
			}

			/*
			* Content removal succeeded. The historical "removed"
			* opportunity is not retained in the new lean data model.
			*/
			$deleted = SRK_Internal_Linking_DB::delete_inserted_opportunity( $opportunity_id );

			if ( 1 !== $deleted ) {
				return new WP_Error(
					'srk_opportunity_delete_failed',
					__( 'The internal link was removed, but its opportunity record could not be deleted.', 'seo-repair-kit' ) );
			}

			/*
			* Refresh the actual link graph.
			*/
			if ( class_exists( 'SRK_Internal_Linking_Indexer' ) ) {
				SRK_Internal_Linking_Indexer::index_single_post( $source_post_id );
			}

			SRK_Internal_Linking_DB::recalculate_inbound_counts();

			return array(
				'message' =>
					__(
						'Inserted link removed successfully.',
						'seo-repair-kit'
					),

				'opportunity_id' =>
					$opportunity_id,

				'post_id' =>
					$source_post_id,

				'deleted' =>
					true,
			);

		/*
		* Refresh the source content/link index so reports and orphan counts
		* reflect the actual post content.
		*/
		if ( class_exists( 'SRK_Internal_Linking_Indexer' ) ) {
			SRK_Internal_Linking_Indexer::index_single_post( $source_post_id );
		}

		SRK_Internal_Linking_DB::recalculate_inbound_counts();

		return array(
			'message'        => __(
				'Inserted link removed successfully.',
				'seo-repair-kit'
			),
			'opportunity_id' => $opportunity_id,
			'post_id'        => $source_post_id,
		);
	}

	/**
	 * Remove one matching internal-link wrapper while preserving anchor text.
	 *
	 * @param string $content    Post content.
	 * @param string $anchor     Inserted anchor text.
	 * @param string $target_url Inserted target URL.
	 * @return string
	 */
	private static function remove_link_from_content( $content, $anchor, $target_url ) {
		$content    = (string) $content;
		$anchor     = trim( wp_strip_all_tags( (string) $anchor ) );
		$target_url = esc_url_raw( $target_url );

		if ( '' === $content || '' === $anchor || '' === $target_url ) {
			return $content;
		}

		/*
		* First try the exact HTML generated by insert_link_into_content().
		*/
		$exact_link = '<a href="' . esc_url( $target_url ) . '">' . esc_html( $anchor ) . '</a>';

		$exact_result = preg_replace( '/' . preg_quote( $exact_link, '/' ) . '/u', esc_html( $anchor ), $content, 1 );

		if ( is_string( $exact_result ) && $exact_result !== $content ) {
			return $exact_result;
		}

		/*
		* Fallback for WordPress/editor formatting changes:
		* - attribute order may change;
		* - extra rel/class attributes may be added;
		* - URL entities may be encoded.
		*/
		$url_pattern = preg_quote( html_entity_decode( $target_url, ENT_QUOTES | ENT_HTML5, get_bloginfo( 'charset' ) ), '/' );

		$anchor_pattern = preg_quote( $anchor, '/' );

		$pattern = '/
			<a\b
				(?=[^>]*\bhref\s*=\s*(["\'])' . $url_pattern . '\1)
				[^>]*
			>
				\s*' . $anchor_pattern . '\s*
			<\/a>
		/ixu';

		$result = preg_replace( $pattern, esc_html( $anchor ), $content, 1 );

		return is_string( $result ) ? $result : $content;
	}

	/**
	 * Preview URL changer replacements without modifying content.
	 *
	 * @return void
	 */
	public static function url_changer_dry_run() {
		self::verify_request();

		$old_url = isset( $_POST['old_url'] ) ? esc_url_raw( wp_unslash( $_POST['old_url'] ) ) : '';
		$new_url = isset( $_POST['new_url'] ) ? esc_url_raw( wp_unslash( $_POST['new_url'] ) ) : '';

		$result = SRK_Internal_Linking_URL_Changer::dry_run( $old_url, $new_url );

		if ( is_wp_error( $result ) ) {
			wp_send_json_error( array( 'message' => $result->get_error_message() ) );
		}

		wp_send_json_success( $result );
	}

	/**
	 * Replace matching URLs in stored post content.
	 *
	 * @return void
	 */
	public static function url_changer_replace() {
		self::verify_request();

		$old_url = isset( $_POST['old_url'] ) ? esc_url_raw( wp_unslash( $_POST['old_url'] ) ) : '';
		$new_url = isset( $_POST['new_url'] ) ? esc_url_raw( wp_unslash( $_POST['new_url'] ) ) : '';

		$result = SRK_Internal_Linking_URL_Changer::replace( $old_url, $new_url );

		if ( is_wp_error( $result ) ) {
			wp_send_json_error( array( 'message' => $result->get_error_message() ) );
		}

		wp_send_json_success( $result );
	}

	/**
	 * Undo a previous URL changer replacement batch.
	 *
	 * @return void
	 */
	public static function url_changer_undo() {
		self::verify_request();

		$change_id = isset( $_POST['change_id'] ) ? absint( wp_unslash( $_POST['change_id'] ) ) : 0;

		$result = SRK_Internal_Linking_URL_Changer::undo( $change_id );

		if ( is_wp_error( $result ) ) {
			wp_send_json_error( array( 'message' => $result->get_error_message() ) );
		}

		wp_send_json_success( $result );
	}

	/**
	 * Ignore a post from orphan content reporting.
	 *
	 * @return void
	 */
	public static function ignore_orphan_content() {
		self::validate_request();

		$post_id = isset( $_POST['post_id'] ) ? absint( wp_unslash( $_POST['post_id'] ) ) : 0;

		if ( ! $post_id ) {
			wp_send_json_error( array( 'message' => __( 'Missing post ID.', 'seo-repair-kit' ) ), 400 );
		}

		SRK_Internal_Linking_DB::ignore_orphan_content( $post_id );

		wp_send_json_success(
			array(
				'message' => __( 'Orphan content ignored.', 'seo-repair-kit' ),
				'summary' => SRK_Internal_Linking_DB::get_orphan_summary(),
			)
		);
	}

	/**
	 * Return existing opportunities for an orphan target or generate them.
	 *
	 * @return void
	 */
	public static function find_orphan_opportunities() {
		check_ajax_referer( 'srk_internal_linking_nonce', 'nonce' );

		if ( ! current_user_can( 'manage_options' ) ) {
			wp_send_json_error(
				array(
					'message' => __(
						'You do not have permission to perform this action.',
						'seo-repair-kit'
					),
				),
				403 );
		}

		$post_id = isset( $_POST['post_id'] ) ? absint( wp_unslash( $_POST['post_id'] ) ) : 0;

		if ( ! $post_id ) {
			wp_send_json_error(
				array(
					'message' => __(
						'Missing orphan content post ID.',
						'seo-repair-kit'
					),
				),
				400 );
		}

		if ( ! class_exists( 'SRK_Internal_Linking_Opportunities' ) ) {
			wp_send_json_error(
				array(
					'message' => __(
						'The opportunity engine is unavailable.',
						'seo-repair-kit'
					),
				),
				500 );
		}

		$result = SRK_Internal_Linking_Opportunities::
			get_or_generate_for_orphan_target( $post_id, 20 );

		if ( is_wp_error( $result ) ) {
			wp_send_json_error(
				array(
					'message' => $result->get_error_message(),
				),
				400 );
		}

		wp_send_json_success(
			array(
				'post_id'       => $post_id,
				'generated'     => ! empty(
					$result['generated']
				),
				'from_existing' => ! empty(
					$result['from_existing']
				),
				'processing'    => ! empty(
					$result['processing']
				),
				'count'         => absint(
					$result['count'] ?? 0
				),
				'opportunities' => ! empty(
					$result['opportunities']
				) && is_array(
					$result['opportunities']
				)
					? $result['opportunities']
					: array(),
				'message'       => sanitize_text_field(
					$result['message'] ?? ''
				),
			)
		);
	}

	/**
	 * Recalculate orphan content metrics.
	 *
	 * @return void
	 */
	public static function refresh_orphan_content() {
		self::validate_request();

		SRK_Internal_Linking_DB::recalculate_inbound_counts();

		wp_send_json_success(
			array(
				'message' => __( 'Orphan content refreshed successfully.', 'seo-repair-kit' ),
				'summary' => SRK_Internal_Linking_DB::get_orphan_summary(),
			)
		);
	}

	/**
	 * Delete a custom keyword.
	 *
	 * @return void
	 */
	public static function delete_custom_keyword() {
		self::validate_request();

		$keyword_id = isset( $_POST['keyword_id'] ) ? absint( wp_unslash( $_POST['keyword_id'] ) ) : 0;
		$post_id    = isset( $_POST['post_id'] ) ? absint( wp_unslash( $_POST['post_id'] ) ) : 0;

		if ( ! $keyword_id ) {
			wp_send_json_error( array( 'message' => __( 'Missing keyword ID.', 'seo-repair-kit' ) ), 400 );
		}

		$deleted = SRK_Internal_Linking_DB::delete_custom_keyword( $keyword_id, $post_id );

		if ( ! $deleted ) {
			wp_send_json_error( array( 'message' => __( 'Unable to delete custom keyword.', 'seo-repair-kit' ) ), 400 );
		}

		wp_send_json_success(
			array(
				'message'  => __( 'Custom keyword deleted.', 'seo-repair-kit' ),
				'keywords' => SRK_Internal_Linking_DB::get_keywords_by_post( $post_id ),
			)
		);
	}

	/**
	 * Validate auto-linking AJAX request.
	 */
	public static function auto_validate_request() {
		check_ajax_referer( 'srk_internal_linking_nonce', 'nonce' );

		if ( ! current_user_can( 'manage_options' ) ) {
			wp_send_json_error(
				array(
					'message' => __( 'Permission denied.', 'seo-repair-kit' ),
				),
				403 );
		}

		self::ensure_paid_active();

		self::ensure_database_ready();
	}

	/**
	 * Create a new auto-linking rule and scan matching content.
	 */
	public static function auto_create_rule() {
		self::auto_validate_request();

		$data   = self::auto_request_data();
		$result = SRK_Internal_Linking_Auto_Linker::create_rule( $data );

		if ( is_wp_error( $result ) ) {
			wp_send_json_error(
				array(
					'message' => $result->get_error_message(),
				),
				400 );
		}

		wp_send_json_success(
			array(
				'message' => __( 'Rule created and scanned.', 'seo-repair-kit' ),
				'rule_id' => absint( $result['rule_id'] ),
				'matches' => $result['matches'] ?? array(),
			)
		);
	}

	/**
	 * Scan an existing rule for keyword matches.
	 */
	public static function auto_scan_rule() {
		self::auto_validate_request();

		$rule_id = absint( wp_unslash( $_POST['rule_id'] ?? 0 ) );
		$result  = SRK_Internal_Linking_Auto_Linker::scan_rule( $rule_id );

		if ( is_wp_error( $result ) ) {
			wp_send_json_error(
				array(
					'message' => $result->get_error_message(),
				),
				400 );
		}

		wp_send_json_success( $result );
	}

	/**
	 * Apply auto links only to selected matched posts.
	 */
	public static function auto_apply_selected() {
		self::auto_validate_request();

		$rule_id  = absint( wp_unslash( $_POST['rule_id'] ?? 0 ) );
		$post_ids = isset( $_POST['post_ids'] ) ? array_map( 'absint', (array) wp_unslash( $_POST['post_ids'] ) ) : array();

		$result = SRK_Internal_Linking_Auto_Linker::apply_rule_to_matched_posts( $rule_id, $post_ids );

		if ( is_wp_error( $result ) ) {
			wp_send_json_error(
				array(
					'message' => $result->get_error_message(),
				),
				400 );
		}

		wp_send_json_success( $result );
	}

	/**
	 * Apply an auto-linking rule to all matched posts.
	 */
	public static function auto_apply_rule() {
		self::auto_validate_request();

		$rule_id = absint( wp_unslash( $_POST['rule_id'] ?? 0 ) );

		SRK_Internal_Linking_Auto_Linker::scan_rule( $rule_id );

		$result = SRK_Internal_Linking_Auto_Linker::apply_rule_to_matched_posts( $rule_id, array() );

		if ( is_wp_error( $result ) ) {
			wp_send_json_error(
				array(
					'message' => $result->get_error_message(),
				),
				400 );
		}

		wp_send_json_success( $result );
	}

	/**
	 * Get auto-linking rules table rows.
	 */
	public static function auto_get_rules() {
		self::auto_validate_request();

		$rules = SRK_Internal_Linking_DB::get_auto_rules(
			array(
				'limit'  => 50,
				'offset' => 0,
			)
		);

		ob_start();

		if ( empty( $rules ) ) {
			echo '<tr><td colspan="6" class="srk-il-table__empty">';
			echo esc_html__( 'No auto-link rules created yet.', 'seo-repair-kit' );
			echo '</td></tr>';
		}

		foreach ( $rules as $rule ) {
			$status = sanitize_key( $rule['status'] );
			?>

			<tr data-rule-id="<?php echo esc_attr( absint( $rule['id'] ) ); ?>">

				<td class="srk-auto-keyword">
					&ldquo;<?php echo esc_html( $rule['keyword'] ); ?>&rdquo;
				</td>

				<td class="srk-auto-url-cell">
					<?php echo esc_html( $rule['target_url'] ); ?>
				</td>

				<?php
				$active_links = max(
					0, absint( $rule['links_created'] ) - absint( $rule['removed_count'] ) );
				?>

				<td>
					<strong>
						<?php echo esc_html( $active_links ); ?>
					</strong>
				</td>

				<td>
					<span class="srk-auto-status <?php echo esc_attr( $status ); ?>">
						● <?php echo esc_html( ucfirst( $status ) ); ?>
					</span>
				</td>

				<td>
					<div class="srk-auto-actions">

						<button
							type="button"
							class="button-link srk-auto-edit-rule"
							title="<?php esc_attr_e( 'Edit rule', 'seo-repair-kit' ); ?>">

							<span class="dashicons dashicons-edit"></span>

						</button>

						<button
							type="button"
							class="button-link srk-auto-scan-rule"
							title="<?php esc_attr_e( 'Find matches', 'seo-repair-kit' ); ?>">

							<span class="dashicons dashicons-search"></span>

						</button>

						<button
							type="button"
							class="button-link srk-auto-refresh-rule"
							title="<?php esc_attr_e( 'Refresh rule', 'seo-repair-kit' ); ?>">

							<span class="dashicons dashicons-update"></span>

						</button>

						<button
							type="button"
							class="button-link srk-auto-delete"
							title="<?php esc_attr_e( 'Delete rule', 'seo-repair-kit' ); ?>">

							<span class="dashicons dashicons-trash"></span>

						</button>

					</div>
				</td>

			</tr>

			<?php
		}

		$html = ob_get_clean();

		wp_send_json_success(
			array(
				'html'       => $html,
				'count_text' => sprintf(
					__( 'Showing %d rules', 'seo-repair-kit' ),
					count( $rules )
				),
			)
		);
	}

	/**
	 * Delete a rule.
	 *
	 * Optionally removes all links inserted by this rule.
	 */
	public static function auto_delete_rule() {
		self::auto_validate_request();

		$rule_id = absint( wp_unslash( $_POST['rule_id'] ?? 0 ) );

		$remove_links = isset( $_POST['remove_links'] ) ? absint( wp_unslash( $_POST['remove_links'] ) ) : 0;

		if ( ! $rule_id ) {
			wp_send_json_error(
				array(
					'message' => __( 'Missing rule ID.', 'seo-repair-kit' ),
				),
				400
			);
		}

		if ( $remove_links ) {
			SRK_Internal_Linking_Auto_Linker::remove_all_rule_links( $rule_id );
		}

		SRK_Internal_Linking_DB::delete_auto_rule( $rule_id );

		wp_send_json_success(
			array(
				'message' => __( 'Rule deleted.', 'seo-repair-kit' ),
			)
		);
	}

	/**
	 * Update rule status.
	 */
	public static function auto_update_rule_status() {
		self::auto_validate_request();

		$rule_id = absint( wp_unslash( $_POST['rule_id'] ?? 0 ) );
		$status  = sanitize_key( wp_unslash( $_POST['status'] ?? 'active' ) );

		if ( ! $rule_id || ! in_array( $status, array( 'active', 'paused' ), true ) ) {
			wp_send_json_error(
				array(
					'message' => __( 'Invalid rule status request.', 'seo-repair-kit' ),
				),
				400
			);
		}

		$updated = SRK_Internal_Linking_DB::update_auto_rule_status( $rule_id, $status );

		if ( false === $updated ) {
			wp_send_json_error(
				array(
					'message' => __( 'Unable to update rule status.', 'seo-repair-kit' ),
				),
				400
			);
		}

		wp_send_json_success();
	}

	/**
	 * Remove auto links from a single post.
	 *
	 * @return void
	 */
	public static function auto_remove_post_links() {
		self::auto_validate_request();

		$rule_id = isset( $_POST['rule_id'] ) ? absint( wp_unslash( $_POST['rule_id'] ) ) : 0;

		$post_id = isset( $_POST['post_id'] ) ? absint( wp_unslash( $_POST['post_id'] ) ) : 0;

		if ( ! $rule_id || ! $post_id ) {
			wp_send_json_error(
				array(
					'message' => __(
						'Missing rule or post ID.',
						'seo-repair-kit'
					),
				),
				400 );
		}

		$result = SRK_Internal_Linking_Auto_Linker::remove_rule_links_from_post( $rule_id, $post_id );

		if ( is_wp_error( $result ) ) {
			wp_send_json_error(
				array(
					'message' => $result->get_error_message(),
				),
				400 );
		}

		/*
		* Scan again so the UI receives the latest Applied/Matched states.
		*/
		$scan = SRK_Internal_Linking_Auto_Linker::scan_rule( $rule_id );

		if ( is_wp_error( $scan ) ) {
			wp_send_json_error(
				array(
					'message' => $scan->get_error_message(),
				),
				400 );
		}

		wp_send_json_success(
			array(
				'message' => sprintf(
					/* translators: %d: number of removed links */
					_n(
						'%d auto link removed.',
						'%d auto links removed.',
						absint( $result['removed'] ),
						'seo-repair-kit'
					),
					absint( $result['removed'] )
				),
				'removed' => absint(
					$result['removed']
				),
				'rule_id' => $rule_id,
				'post_id' => $post_id,
				'matches' => $scan['matches'] ?? array(),
				'summary' => SRK_Internal_Linking_DB::get_auto_linking_summary(),
			)
		);
	}
	/**
	 * Remove all links inserted by a rule.
	 *
	 * @return void
	 */
	public static function auto_remove_all_rule_links() {
		self::auto_validate_request();

		$rule_id = isset( $_POST['rule_id'] ) ? absint( wp_unslash( $_POST['rule_id'] ) ) : 0;

		if ( ! $rule_id ) {
			wp_send_json_error(
				array(
					'message' => __(
						'Missing rule ID.',
						'seo-repair-kit'
					),
				),
				400 );
		}

		$result = SRK_Internal_Linking_Auto_Linker::remove_all_rule_links( $rule_id );

		if ( is_wp_error( $result ) ) {
			wp_send_json_error(
				array(
					'message' => $result->get_error_message(),
				),
				400 );
		}

		$scan = SRK_Internal_Linking_Auto_Linker::scan_rule( $rule_id );

		if ( is_wp_error( $scan ) ) {
			wp_send_json_error(
				array(
					'message' => $scan->get_error_message(),
				),
				400 );
		}

		wp_send_json_success(
			array(
				'message' => sprintf(
					/* translators: %d: number of removed links */
					_n(
						'%d rule link removed.',
						'%d rule links removed.',
						absint( $result['removed'] ),
						'seo-repair-kit'
					),
					absint( $result['removed'] )
				),
				'removed' => absint(
					$result['removed']
				),
				'failed' => absint(
					$result['failed'] ?? 0
				),
				'rule_id' => $rule_id,
				'matches' => $scan['matches'] ?? array(),
				'summary' => SRK_Internal_Linking_DB::get_auto_linking_summary(),
			)
		);
	}
	/**
	 * Save auto-linking settings.
	 */
	public static function auto_save_settings() {
		self::auto_validate_request();

		SRK_Internal_Linking_DB::save_auto_linking_settings( self::auto_request_data() );

		wp_send_json_success(
			array(
				'message' => __( 'Settings saved.', 'seo-repair-kit' ),
			)
		);
	}

	/**
	 * Collect and sanitize auto-linking request data.
	 */
	private static function auto_request_data() {
		return array(
			'keyword'                  => sanitize_text_field( wp_unslash( $_POST['keyword'] ?? '' ) ),
			'target_url'               => esc_url_raw( wp_unslash( $_POST['target_url'] ?? '' ) ),
			'selection_mode'           => sanitize_key( wp_unslash( $_POST['selection_mode'] ?? 'manual' ) ),
			'post_types'               => self::sanitize_key_array( self::get_post_array( 'post_types', array( 'post', 'page' ) ) ),
			'max_links_per_post'       => absint( wp_unslash( $_POST['max_links_per_post'] ?? 1 ) ),
			'max_links_per_keyword'    => absint( wp_unslash( $_POST['max_links_per_keyword'] ?? 1 ) ),
			'case_sensitive'           => absint( wp_unslash( $_POST['case_sensitive'] ?? 0 ) ),
			'allow_duplicate_target'   => absint( wp_unslash( $_POST['allow_duplicate_target'] ?? 0 ) ),
			'require_target_published' => absint( wp_unslash( $_POST['require_target_published'] ?? 1 ) ),
			'default_max_links_post'   => absint( wp_unslash( $_POST['default_max_links_post'] ?? 1 ) ),
			'default_max_keyword'      => absint( wp_unslash( $_POST['default_max_keyword'] ?? 1 ) ),
			'default_post_types'       => self::sanitize_key_array( self::get_post_array( 'default_post_types', array( 'post', 'page' ) ) ),
			'manual_review'            => absint( wp_unslash( $_POST['manual_review'] ?? 1 ) ),
			'internal_only'            => absint( wp_unslash( $_POST['internal_only'] ?? 1 ) ),
			'priority'                 => absint( wp_unslash( $_POST['priority'] ?? 10 ) ),
			'apply_after_date'         => sanitize_text_field( wp_unslash( $_POST['apply_after_date'] ?? '' ) ),
			'categories'               => array_values( array_filter( array_map( 'absint', self::get_post_array( 'categories' ) ) ) ),
			'tags'                     => array_values( array_filter( array_map( 'absint', self::get_post_array( 'tags' ) ) ) ),
		);
	}

	/**
	 * Get one rule for editing.
	 */
	public static function auto_get_rule() {
		self::auto_validate_request();

		$rule_id = absint( wp_unslash( $_POST['rule_id'] ?? 0 ) );
		$rule    = SRK_Internal_Linking_DB::get_auto_rule( $rule_id );

		if ( ! $rule ) {
			wp_send_json_error(
				array(
					'message' => __( 'Rule not found.', 'seo-repair-kit' ),
				),
				404 );
		}

		wp_send_json_success(
			array(
				'rule' => array(
					'id'                       => absint( $rule['id'] ),
					'keyword'                  => $rule['keyword'],
					'target_url'               => $rule['target_url'],
					'selection_mode'           => $rule['selection_mode'],
					'case_sensitive'           => absint( $rule['case_sensitive'] ),
					'max_links_per_post'       => absint( $rule['max_links_per_post'] ),
					'max_links_per_keyword'    => absint( $rule['max_links_per_keyword'] ),
					'priority'                 => absint( $rule['priority'] ),
					'apply_after_date'         => $rule['apply_after_date'],
					'allow_duplicate_target'   => absint( $rule['allow_duplicate_target'] ),
					'require_target_published' => absint( $rule['require_target_published'] ),
					'post_types'               => json_decode( $rule['post_types_json'], true ) ?: array(),
					'categories'               => json_decode( $rule['categories_json'], true ) ?: array(),
					'tags'                     => json_decode( $rule['tags_json'], true ) ?: array(),
				),
			)
		);
	}

	/**
	 * Update an existing auto-link rule.
	 */
	public static function auto_update_rule() {
		self::auto_validate_request();

		$rule_id = absint( wp_unslash( $_POST['rule_id'] ?? 0 ) );
		$data    = self::auto_request_data();

		$result = SRK_Internal_Linking_DB::update_auto_rule( $rule_id, $data );

		if ( is_wp_error( $result ) ) {
			wp_send_json_error(
				array(
					'message' => $result->get_error_message(),
				),
				400 );
		}

		$scan = SRK_Internal_Linking_Auto_Linker::scan_rule( $rule_id );

		wp_send_json_success(
			array(
				'message' => __( 'Rule updated and refreshed.', 'seo-repair-kit' ),
				'rule_id' => $rule_id,
				'matches' => is_wp_error( $scan )
					? array()
					: ( $scan['matches'] ?? array() ),
			)
		);
	}

	/**
	 * Get Internal Linking report data.
	 *
	 * @return void
	 */
	public static function get_report() {

		check_ajax_referer( 'srk_internal_linking_nonce', 'nonce' );

		if ( ! current_user_can( 'manage_options' ) ) {

			wp_send_json_error(
				array(
					'message' => __( 'Permission denied.', 'seo-repair-kit' ),
				),
				403 );

		}

		self::ensure_paid_active();

		self::ensure_database_ready();

		$type = isset( $_POST['report_type'] ) ? sanitize_key( wp_unslash( $_POST['report_type'] ) ) : '';

		if ( ! class_exists( 'SRK_Internal_Linking_Reports' ) ) {

			wp_send_json_error(
				array(
					'message' => __( 'Reports service is missing.', 'seo-repair-kit' ),
				),
				500 );

		}

		$data = SRK_Internal_Linking_Reports::get_report( $type );

		if ( empty( $data ) ) {

			wp_send_json_error(
				array(
					'message'=> __( 'No report data found.', 'seo-repair-kit' )
				)
			);

		}

		ob_start();

		?>

		<div class="srk-report-result-card">

			<h3>
				<?php echo esc_html( $data['title'] ); ?>
			</h3>


			<?php if ( empty( $data['rows'] ) ) : ?>

				<p class="srk-report-empty">
					<?php esc_html_e(
						'No data available yet.',
						'seo-repair-kit'
					); ?>
				</p>


			<?php else : ?>


			<div class="srk-report-table-wrapper">

				<table class="widefat striped srk-report-data-table">

					<thead>

					<tr>

					<?php foreach ( $data['columns'] as $column ) : ?>

						<th>
							<?php echo esc_html( $column ); ?>
						</th>

					<?php endforeach; ?>

					</tr>

					</thead>


					<tbody>


					<?php foreach ( $data['rows'] as $row ) : ?>

					<tr>

						<?php foreach ( $row as $value ) : ?>

						<td>
							<?php echo esc_html( $value ); ?>
						</td>

						<?php endforeach; ?>


					</tr>

					<?php endforeach; ?>


					</tbody>


				</table>


			</div>


			<?php endif; ?>


		</div>


		<?php


		$html = ob_get_clean();

		wp_send_json_success(
			array(
				'html'=>$html
			)
		);

	}

	/**
	 * Get AI pipeline status (no AI calls — reads DB/cache only).
	 *
	 * @return void
	 */
	public static function get_ai_status() {
		self::validate_request();

		if ( ! class_exists( 'SRK_Internal_Linking_Service' ) ) {
			wp_send_json_error(
				array(
					'message' => __(
						'Service layer is not loaded.',
						'seo-repair-kit'
					),
				),
				500
			);
		}

		/*
		* AJAX polling must bypass the one-minute status cache.
		*/
		wp_send_json_success(
			SRK_Internal_Linking_Service::get_ai_status(
				true
			)
		);
	}

	/**
	 * Get current Internal Linking dashboard data.
	 *
	 * @return void
	 */
	public static function get_dashboard_data() {
		self::validate_request();

		if ( ! class_exists( 'SRK_Internal_Linking_DB' ) ) {
			wp_send_json_error(
				array(
					'message' => __(
						'Internal Linking database layer is not loaded.',
						'seo-repair-kit'
					),
				),
				500 );
		}

		wp_send_json_success( SRK_Internal_Linking_DB::get_dashboard_data() );
	}

	/**
	 * Queue full AI semantic pipeline (background only).
	 *
	 * @return void
	 */
	public static function start_ai_pipeline() {
		self::validate_request();

		if ( ! class_exists( 'SRK_Internal_Linking_Service' ) ) {
			wp_send_json_error( array( 'message' => __( 'Service layer is not loaded.', 'seo-repair-kit' ) ), 500 );
		}

		$result = SRK_Internal_Linking_Service::start_ai_pipeline();

		if ( is_wp_error( $result ) ) {
			wp_send_json_error( array( 'message' => $result->get_error_message() ), 400 );
		}

		wp_send_json_success( $result );
	}

	/**
	 * Test OpenRouter API key from settings form.
	 *
	 * @return void
	 */
	public static function test_ai_provider() {

		self::validate_request();

		if (
			! class_exists(
				'SRK_Internal_Linking_AI_Provider'
			)
		) {
			wp_send_json_error(
				array(
					'message' => __(
						'AI provider layer is not loaded.',
						'seo-repair-kit'
					),
				),
				500
			);
		}

		$api_key =
			isset( $_POST['api_key'] )
				? sanitize_text_field(
					wp_unslash(
						$_POST['api_key']
					)
				)
				: '';

		if (
			class_exists(
				'SRK_Internal_Linking_Settings'
			)
		) {
			$api_key =
				SRK_Internal_Linking_Settings::
					sanitize_api_key(
						$api_key
					);
		}

		$result =
			SRK_Internal_Linking_AI_Provider::
				test_connection(
					$api_key
				);

		if ( is_wp_error( $result ) ) {

			wp_send_json_error(
				array(
					'message' =>
						$result->get_error_message(),

					'code' =>
						$result->get_error_code(),
				),
				400
			);
		}

		wp_send_json_success(
			array(
				'message' => sprintf(
					/* translators: %s: detected AI provider */
					__(
						'AI connection successful. SEO Repair Kit detected %s and selected a compatible embedding model automatically.',
						'seo-repair-kit'
					),
					ucfirst(
						sanitize_key(
							$result['provider'] ?? ''
						)
					)
				),

				'provider' =>
					sanitize_key(
						$result['provider'] ?? ''
					),

				'dimensions' =>
					absint(
						$result['dimensions'] ?? 0
					),
			)
		);
	}

	public static function get_domain_posts() {
		self::validate_request();

		$domain = isset( $_POST['domain'] ) ? sanitize_text_field( wp_unslash( $_POST['domain'] ) ) : '';
		if(!$domain){
			wp_send_json_error(['message'=>'Domain missing']);
		}

		if(!class_exists('SRK_Internal_Linking_DB')){
			wp_send_json_error(['message'=>'DB layer missing']);
		}

		$posts = SRK_Internal_Linking_DB::get_posts_by_domain($domain);

		ob_start();
		if(!empty($posts)){
			echo '<table class="srk-il-data-table"><thead><tr><th>Post</th><th>Published</th><th>Domain Link Count</th><th>Actions</th></tr></thead><tbody>';
			foreach($posts as $post){
				echo '<tr>';
				echo '<td>'.esc_html($post['post_title']).'</td>';
				echo '<td>'.esc_html($post['post_date'] ? $post['post_date'] : '-').'</td>';
				echo '<td>'.esc_html($post['link_count']).'</td>';
				echo '<td><a href="'.esc_url(get_edit_post_link($post['post_id'])).'" target="_blank" class="button">Edit Post</a></td>';
				echo '</tr>';
			}
			echo '</tbody></table>';
		}else{
			echo '<p>No posts found for this domain.</p>';
		}
		$html = ob_get_clean();
		wp_send_json_success(['html'=>$html]);
	}

	public static function get_domain_links() {
		self::validate_request();

		$domain = isset( $_POST['domain'] ) ? sanitize_text_field( wp_unslash( $_POST['domain'] ) ) : '';
		if(!$domain){
			wp_send_json_error(['message'=>'Domain missing']);
		}

		if(!class_exists('SRK_Internal_Linking_DB')){
			wp_send_json_error(['message'=>'DB layer missing']);
		}

		$links = SRK_Internal_Linking_DB::get_links_by_domain($domain);

		ob_start();
		if(!empty($links)){
			echo '<table class="srk-il-data-table"><thead><tr><th>Post</th><th>Anchor Text</th><th>URL</th></tr></thead><tbody>';
			foreach($links as $link){
				echo '<tr>';
				echo '<td>'.esc_html($link['source_post_title']).'</td>';
				echo '<td>'.esc_html($link['anchor_text']).'</td>';
				echo '<td><a href="'.esc_url($link['target_url']).'" target="_blank">'.esc_html($link['target_url']).'</a></td>';
				echo '</tr>';
			}
			echo '</tbody></table>';
		}else{
			echo '<p>No links found for this domain.</p>';
		}
		$html = ob_get_clean();
		wp_send_json_success(['html'=>$html]);
	}

	/**
	 * Return active stopwords for one selected language.
	 *
	 * A language-specific saved override takes priority. Otherwise, the matching
	 * UTF-8 TXT file is returned.
	 *
	 * @return void
	 */
	public static function get_language_stopwords() {
		self::validate_request();

		if ( ! class_exists( 'SRK_Internal_Linking_Stopwords' ) || ! class_exists( 'SRK_Internal_Linking_Settings' ) ) {
			wp_send_json_error(
				array(
					'message' => __(
						'Language stopword services are not loaded.',
						'seo-repair-kit'
					),
				),
				500 );
		}

		$language = isset( $_POST['language'] ) ? sanitize_key( wp_unslash( $_POST['language'] ) ) : '';

		$language = SRK_Internal_Linking_Stopwords::sanitize_language( $language );

		$settings = SRK_Internal_Linking_Settings::get();

		$overrides =
			! empty(
				$settings['ignore_words_by_language']
			) && is_array( $settings['ignore_words_by_language'] ) ? $settings['ignore_words_by_language'] : array();

		$has_override = array_key_exists( $language, $overrides );

		$words = SRK_Internal_Linking_Settings::get_stopwords( $language );

		wp_send_json_success(
			array(
				'language' => $language,

				'words' => implode(
					PHP_EOL,
					(array) $words
				),

				'word_count' =>
					count( (array) $words ),

				'has_override' =>
					$has_override,

				'source' =>
					$has_override
						? 'override'
						: 'file',
			)
		);
	}

	/**
	 * Get current editor suggestion/AI status without regenerating suggestions.
	 *
	 * @return void
	 */
	public static function get_editor_suggestion_status() {
		$post_id = isset( $_POST['post_id'] ) ? absint( wp_unslash( $_POST['post_id'] ) ) : 0;

		self::validate_editor_request( $post_id );

		if ( ! class_exists( 'SRK_Internal_Linking_Service' ) ) {
			wp_send_json_error(
				array(
					'message' => __(
						'Internal Linking service is unavailable.',
						'seo-repair-kit'
					),
				),
				500 );
		}

		wp_send_json_success( SRK_Internal_Linking_Service::get_editor_suggestion_status( $post_id ) );
	}

	/**
	 * Delete a custom keyword from the post editor.
	 *
	 * @return void
	 */
	public static function editor_delete_custom_keyword() {
		$keyword_id = isset( $_POST['keyword_id'] )
			? absint( wp_unslash( $_POST['keyword_id'] ) )
			: 0;

		$post_id = isset( $_POST['post_id'] )
			? absint( wp_unslash( $_POST['post_id'] ) )
			: 0;

		self::validate_editor_request( $post_id );

		if ( ! $keyword_id ) {
			wp_send_json_error(
				array(
					'message' => __( 'Missing keyword ID.', 'seo-repair-kit' ),
				),
				400
			);
		}

		$deleted = SRK_Internal_Linking_DB::delete_custom_keyword(
			$keyword_id,
			$post_id
		);

		if ( ! $deleted ) {
			wp_send_json_error(
				array(
					'message' => __( 'Unable to delete custom keyword.', 'seo-repair-kit' ),
				),
				400
			);
		}

		wp_send_json_success(
			array(
				'message'  => __( 'Custom keyword deleted.', 'seo-repair-kit' ),
				'keyword_id' => $keyword_id,
				'post_id'    => $post_id,
				'keywords' => SRK_Internal_Linking_DB::get_keywords_by_post( $post_id ),
			)
		);
	}
}
