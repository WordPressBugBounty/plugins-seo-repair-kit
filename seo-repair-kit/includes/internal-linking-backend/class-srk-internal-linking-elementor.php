<?php
/**
 * Elementor content adapter for SEO Repair Kit Internal Linking.
 *
 * Reads and writes actual Elementor document data through Elementor's
 * Document API instead of directly modifying _elementor_data post meta.
 *
 * @package SEO_Repair_Kit
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Elementor adapter for Internal Linking.
 */
class SRK_Internal_Linking_Elementor {

	/**
	 * Prevent our own Elementor document save from triggering another
	 * Internal Linking refresh through elementor/editor/after_save.
	 *
	 * @var bool
	 */
	private static $saving = false;

	/**
	 * Register Elementor integration hooks.
	 *
	 * @return void
	 */
	public static function init() {
		add_action(
			'elementor/editor/after_save',
			array(
				__CLASS__,
				'handle_elementor_after_save',
			),
			20,
			2
		);
	}

	/**
	 * Determine whether Elementor runtime is available.
	 *
	 * @return bool
	 */
	public static function is_available() {
		if (
			! class_exists(
				'\Elementor\Plugin'
			)
		) {
			return false;
		}

		if (
			! isset(
				\Elementor\Plugin::$instance
			) ||
			! \Elementor\Plugin::$instance
		) {
			return false;
		}

		return isset(
			\Elementor\Plugin::$instance->documents
		);
	}

	/**
	 * Determine whether one post is actually built with Elementor.
	 *
	 * @param int $post_id Post ID.
	 *
	 * @return bool
	 */
	public static function is_elementor_post(
		$post_id
	) {
		$post_id = absint(
			$post_id
		);

		if (
			! $post_id ||
			! self::is_available()
		) {
			return false;
		}

		$document =
			self::get_document(
				$post_id
			);

		if (
			$document &&
			method_exists(
				$document,
				'is_built_with_elementor'
			)
		) {
			return (bool)
				$document->is_built_with_elementor();
		}

		/*
		 * Safe compatibility fallback for older Elementor versions.
		 */
		return 'builder' ===
			get_post_meta(
				$post_id,
				'_elementor_edit_mode',
				true
			);
	}

	/**
	 * Get Elementor elements for a post.
	 *
	 * @param int  $post_id Post ID.
	 * @param bool $fresh   Bypass Elementor document cache.
	 *
	 * @return array
	 */
	public static function get_elements_data(
		$post_id,
		$fresh = false
	) {
		$post_id = absint(
			$post_id
		);

		$document =
			self::get_document(
				$post_id,
				false,
				$fresh
			);

		if (
			! $document ||
			! method_exists(
				$document,
				'get_elements_data'
			)
		) {
			return array();
		}

		$elements =
			$document->get_elements_data();

		return is_array( $elements )
			? $elements
			: array();
	}

	/**
	 * Return the real linkable Elementor HTML used by the SRK analyzer.
	 *
	 * Only fields that this adapter knows how to safely write are included.
	 * This prevents SRK from suggesting an anchor inside a widget that it
	 * cannot later modify correctly.
	 *
	 * @param int  $post_id Post ID.
	 * @param bool $fresh   Bypass Elementor document cache.
	 *
	 * @return string
	 */
	public static function get_analysis_content(
		$post_id,
		$fresh = false
	) {
		$elements =
			self::get_elements_data(
				$post_id,
				$fresh
			);

		if ( empty( $elements ) ) {
			return '';
		}

		$fragments = array();

		self::collect_fragments(
			$elements,
			$fragments
		);

		if ( empty( $fragments ) ) {
			return '';
		}

		$content = array();

		foreach ( $fragments as $fragment ) {
			$html = isset(
				$fragment['html']
			)
				? (string) $fragment['html']
				: '';

			if ( '' !== trim( $html ) ) {
				$content[] = $html;
			}
		}

		return implode(
			"\n\n",
			$content
		);
	}

	/**
	 * Build stable text-content hash for an Elementor document.
	 *
	 * Design-only changes do not unnecessarily invalidate embeddings.
	 *
	 * @param int $post_id Post ID.
	 *
	 * @return string
	 */
	public static function get_content_hash(
		$post_id
	) {
		$post_id = absint(
			$post_id
		);

		if ( ! $post_id ) {
			return '';
		}

		$content =
			self::get_analysis_content(
				$post_id,
				true
			);

		return hash(
			'sha256',
			(string) get_the_title(
				$post_id
			) .
			'|' .
			$content
		);
	}

	/**
	 * Apply one canonical SRK opportunity directly to real Elementor data.
	 *
	 * @param int    $opportunity_id Opportunity ID.
	 * @param int    $source_post_id Optional expected source post ID.
	 * @param string $anchor_override Optional edited anchor.
	 *
	 * @return array|WP_Error
	 */
	public static function apply_opportunity(
		$opportunity_id,
		$source_post_id = 0,
		$anchor_override = '',
		$defer_until_save = false,
		$sync_after = true
	) {
		$opportunity_id = absint(
			$opportunity_id
		);

		$source_post_id = absint(
			$source_post_id
		);

		if ( ! $opportunity_id ) {
			return new WP_Error(
				'srk_elementor_invalid_opportunity',
				__(
					'Invalid opportunity ID.',
					'seo-repair-kit'
				)
			);
		}

		$opportunity =
			SRK_Internal_Linking_DB::
			get_opportunity_by_id(
				$opportunity_id
			);

		if ( ! $opportunity ) {
			return new WP_Error(
				'srk_elementor_missing_opportunity',
				__(
					'Opportunity not found.',
					'seo-repair-kit'
				)
			);
		}

		if (
			'pending' !==
			sanitize_key(
				$opportunity['status']
					?? ''
			)
		) {
			return new WP_Error(
				'srk_elementor_opportunity_unavailable',
				__(
					'This opportunity is no longer pending.',
					'seo-repair-kit'
				)
			);
		}

		$actual_source_id = absint(
			$opportunity['source_post_id']
				?? 0
		);

		if (
			$source_post_id &&
			$actual_source_id !==
				$source_post_id
		) {
			return new WP_Error(
				'srk_elementor_invalid_source',
				__(
					'This opportunity does not belong to the current post.',
					'seo-repair-kit'
				)
			);
		}

		$source_post_id =
			$actual_source_id;

		if (
			! $source_post_id ||
			! current_user_can(
				'edit_post',
				$source_post_id
			)
		) {
			return new WP_Error(
				'srk_elementor_permission_denied',
				__(
					'You do not have permission to edit this Elementor post.',
					'seo-repair-kit'
				)
			);
		}

		if (
			! self::is_elementor_post(
				$source_post_id
			)
		) {
			return new WP_Error(
				'srk_not_elementor_post',
				__(
					'The source post is not an Elementor document.',
					'seo-repair-kit'
				)
			);
		}

		$target_post_id = absint(
			$opportunity['target_post_id']
				?? 0
		);

		$target = get_post(
			$target_post_id
		);

		if (
			! $target ||
			'publish' !==
				$target->post_status
		) {
			return new WP_Error(
				'srk_elementor_target_unavailable',
				__(
					'The target post is not available.',
					'seo-repair-kit'
				)
			);
		}

		$anchor =
			'' !== trim(
				(string) $anchor_override
			)
				? sanitize_text_field(
					$anchor_override
				)
				: sanitize_text_field(
					$opportunity['anchor_text']
						?? ''
				);

		$anchor = trim(
			wp_strip_all_tags(
				$anchor
			)
		);

		$target_url =
			get_permalink(
				$target_post_id
			);

		if (
			'' === $anchor ||
			! $target_url
		) {
			return new WP_Error(
				'srk_elementor_invalid_link',
				__(
					'The anchor or target URL is invalid.',
					'seo-repair-kit'
				)
			);
		}

		/*
		 * Always inspect the real Elementor document.
		 */
		$current_content =
			self::get_analysis_content(
				$source_post_id,
				true
			);

		/*
		* Editor-side Apply:
		*
		* Validate against the real Elementor content now, but do not mutate
		* Elementor data and do not mark the opportunity inserted.
		*
		* The actual document modification happens only after WordPress Update/Save.
		*/
		if ( $defer_until_save ) {
			$exact_link_exists =
				self::html_contains_anchor_link(
					$current_content,
					$anchor,
					$target_url
				);

			if (
				! $exact_link_exists &&
				! self::plain_contains_anchor(
					$current_content,
					$anchor
				)
			) {
				return new WP_Error(
					'srk_elementor_anchor_missing',
					__(
						'The suggested anchor was not found in supported Elementor content. Regenerate the suggestion or edit the anchor.',
						'seo-repair-kit'
					)
				);
			}

			if (
				! $exact_link_exists &&
				self::html_contains_target_url(
					$current_content,
					$target_url
				)
			) {
				return new WP_Error(
					'srk_elementor_target_already_linked',
					__(
						'This Elementor page already links to the selected target.',
						'seo-repair-kit'
					)
				);
			}

			return array(
				'message' => __(
					'Link staged for Elementor. Save or update the post to apply and confirm it.',
					'seo-repair-kit'
				),

				'opportunity_id' =>
					$opportunity_id,

				'post_id' =>
					$source_post_id,

				'anchor_text' =>
					$anchor,

				'target_url' =>
					esc_url_raw( $target_url ),

				'status' =>
					'pending',

				'editor_type' =>
					'elementor',

				'elementor_saved' =>
					false,

				'pending_save' =>
					true,
			);
		}	

		/*
		 * Recovery-safe behaviour:
		 * link may already exist but DB status may not have been synchronized.
		 */
		if (
			self::html_contains_anchor_link(
				$current_content,
				$anchor,
				$target_url
			)
		) {
			SRK_Internal_Linking_DB::
				mark_opportunity_inserted(
					$opportunity_id
				);

			self::sync_after_mutation(
				$source_post_id
			);

			return array(
				'message' => __(
					'The link already exists in Elementor content and has been synchronized.',
					'seo-repair-kit'
				),

				'opportunity_id' =>
					$opportunity_id,

				'post_id' =>
					$source_post_id,

				'status' =>
					'inserted',

				'editor_type' =>
					'elementor',

				'elementor_saved' =>
					true,
			);
		}

		/*
		 * Respect the same source -> target duplicate protection
		 * used by the normal Internal Linking engine.
		 */
		if (
			self::html_contains_target_url(
				$current_content,
				$target_url
			)
		) {
			return new WP_Error(
				'srk_elementor_target_already_linked',
				__(
					'This Elementor page already links to the selected target.',
					'seo-repair-kit'
				)
			);
		}

		$elements =
			self::get_elements_data(
				$source_post_id,
				true
			);

		if ( empty( $elements ) ) {
			return new WP_Error(
				'srk_elementor_empty_document',
				__(
					'No supported Elementor content was found.',
					'seo-repair-kit'
				)
			);
		}

		$fragments = array();

		self::collect_fragments(
			$elements,
			$fragments
		);

		$candidates = array();

		foreach ( $fragments as $fragment ) {
			$html = (string) (
				$fragment['html']
					?? ''
			);

			if (
				'' === $html ||
				! self::plain_contains_anchor(
					$html,
					$anchor
				)
			) {
				continue;
			}

			$fragment['score'] =
				self::context_score(
					$html,
					(string) (
						$opportunity['sentence']
							?? ''
					),
					$anchor
				);

			$candidates[] =
				$fragment;
		}

		if ( empty( $candidates ) ) {
			return new WP_Error(
				'srk_elementor_anchor_missing',
				__(
					'The suggested anchor was not found in supported Elementor text content. Regenerate the suggestion or edit the anchor.',
					'seo-repair-kit'
				)
			);
		}

		usort(
			$candidates,
			static function (
				$a,
				$b
			) {
				return absint(
					$b['score'] ?? 0
				)
					<=>
					absint(
						$a['score'] ?? 0
					);
			}
		);

		$changed = false;

		foreach ( $candidates as $candidate ) {
			$old_html = (string) (
				$candidate['html']
					?? ''
			);

			$new_html =
				self::insert_link_into_html(
					$old_html,
					$anchor,
					$target_url
				);

			if (
				$new_html === $old_html
			) {
				continue;
			}

			$updated =
				self::update_locator_value(
					$elements,
					$candidate['locator'],
					$new_html
				);

			if ( $updated ) {
				$changed = true;
				break;
			}
		}

		if ( ! $changed ) {
			return new WP_Error(
				'srk_elementor_anchor_not_insertable',
				__(
					'The anchor exists, but it is inside protected or unsupported Elementor markup.',
					'seo-repair-kit'
				)
			);
		}

		$saved =
			self::save_elements(
				$source_post_id,
				$elements
			);

		if ( is_wp_error( $saved ) ) {
			return $saved;
		}

		/*
		 * Never claim success until Elementor's saved document
		 * contains the exact anchor + destination URL.
		 */
		$verified_content =
			self::get_analysis_content(
				$source_post_id,
				true
			);

		if (
			! self::html_contains_anchor_link(
				$verified_content,
				$anchor,
				$target_url
			)
		) {
			return new WP_Error(
				'srk_elementor_verification_failed',
				__(
					'Elementor saved the document, but the inserted link could not be verified.',
					'seo-repair-kit'
				)
			);
		}

		$marked =
			SRK_Internal_Linking_DB::
			mark_opportunity_inserted(
				$opportunity_id
			);

		if ( false === $marked ) {
			return new WP_Error(
				'srk_elementor_status_sync_failed',
				__(
					'The link was saved in Elementor, but the opportunity status could not be synchronized.',
					'seo-repair-kit'
				)
			);
		}

		self::sync_after_mutation(
			$source_post_id
		);

		return array(
			'message' => __(
				'Link inserted into Elementor content successfully.',
				'seo-repair-kit'
			),

			'opportunity_id' =>
				$opportunity_id,

			'post_id' =>
				$source_post_id,

			'status' =>
				'inserted',

			'editor_type' =>
				'elementor',

			'elementor_saved' =>
				true,
		);
	}

	/**
	 * Remove an SRK inserted link from Elementor.
	 *
	 * @param int $opportunity_id Opportunity ID.
	 *
	 * @return array|WP_Error
	 */
	public static function remove_inserted_opportunity(
		$opportunity_id
	) {
		$opportunity_id = absint(
			$opportunity_id
		);

		$opportunity =
			SRK_Internal_Linking_DB::
			get_opportunity_by_id(
				$opportunity_id
			);

		if ( ! $opportunity ) {
			return new WP_Error(
				'srk_elementor_missing_opportunity',
				__(
					'Inserted opportunity not found.',
					'seo-repair-kit'
				)
			);
		}

		if (
			'inserted' !==
			sanitize_key(
				$opportunity['status']
					?? ''
			)
		) {
			return new WP_Error(
				'srk_elementor_not_inserted',
				__(
					'This opportunity is not currently inserted.',
					'seo-repair-kit'
				)
			);
		}

		$post_id = absint(
			$opportunity['source_post_id']
				?? 0
		);

		if (
			! $post_id ||
			! current_user_can(
				'edit_post',
				$post_id
			)
		) {
			return new WP_Error(
				'srk_elementor_permission_denied',
				__(
					'You do not have permission to edit this Elementor post.',
					'seo-repair-kit'
				)
			);
		}

		$anchor = sanitize_text_field(
			$opportunity['anchor_text']
				?? ''
		);

		$target_url = get_permalink(
			absint(
				$opportunity['target_post_id']
					?? 0
			)
		);

		if (
			'' === $anchor ||
			! $target_url
		) {
			return new WP_Error(
				'srk_elementor_invalid_link',
				__(
					'The stored link information is incomplete.',
					'seo-repair-kit'
				)
			);
		}

		$elements =
			self::get_elements_data(
				$post_id,
				true
			);

		$fragments = array();

		self::collect_fragments(
			$elements,
			$fragments
		);

		$changed = false;

		foreach ( $fragments as $fragment ) {
			$html = (string) (
				$fragment['html']
					?? ''
			);

			if (
				! self::html_contains_anchor_link(
					$html,
					$anchor,
					$target_url
				)
			) {
				continue;
			}

			$new_html =
				self::remove_link_from_html(
					$html,
					$anchor,
					$target_url
				);

			if ( $new_html === $html ) {
				continue;
			}

			if (
				self::update_locator_value(
					$elements,
					$fragment['locator'],
					$new_html
				)
			) {
				$changed = true;
				break;
			}
		}

		if ( ! $changed ) {
			return new WP_Error(
				'srk_elementor_link_missing',
				__(
					'The inserted link could not be found in supported Elementor content.',
					'seo-repair-kit'
				)
			);
		}

		$saved =
			self::save_elements(
				$post_id,
				$elements
			);

		if ( is_wp_error( $saved ) ) {
			return $saved;
		}

		$verified_content =
			self::get_analysis_content(
				$post_id,
				true
			);

		if (
			self::html_contains_anchor_link(
				$verified_content,
				$anchor,
				$target_url
			)
		) {
			return new WP_Error(
				'srk_elementor_remove_verification_failed',
				__(
					'Elementor saved the document, but the link removal could not be verified.',
					'seo-repair-kit'
				)
			);
		}

		/*
		 * Current SRK data model removes the inserted opportunity row
		 * after the real content link is successfully removed.
		 */
		$deleted =
			SRK_Internal_Linking_DB::
			delete_inserted_opportunity(
				$opportunity_id
			);

		if ( false === $deleted ) {
			return new WP_Error(
				'srk_elementor_remove_sync_failed',
				__(
					'The Elementor link was removed, but the Internal Linking record could not be deleted.',
					'seo-repair-kit'
				)
			);
		}

		self::sync_after_mutation(
			$post_id
		);

		return array(
			'message' => __(
				'Elementor link removed successfully.',
				'seo-repair-kit'
			),

			'opportunity_id' =>
				$opportunity_id,

			'post_id' =>
				$post_id,

			'deleted' =>
				true,

			'editor_type' =>
				'elementor',
		);
	}

	/**
	 * Synchronize SRK after the user saves directly in Elementor.
	 *
	 * @param int   $post_id     Post ID.
	 * @param array $editor_data Elementor editor data.
	 *
	 * @return void
	 */
	public static function handle_elementor_after_save(
		$post_id,
		$editor_data
	) {
		unset(
			$editor_data
		);

		if ( self::$saving ) {
			return;
		}

		$post_id = absint(
			$post_id
		);

		if (
			! $post_id ||
			'publish' !==
				get_post_status(
					$post_id
				)
		) {
			return;
		}

		if (
			class_exists(
				'SRK_Internal_Linking_Indexer'
			)
		) {
			SRK_Internal_Linking_Indexer::
				index_single_post(
					$post_id
				);
		}

		if (
			class_exists(
				'SRK_Internal_Linking_Keywords'
			)
		) {
			SRK_Internal_Linking_Keywords::
				generate_for_post(
					$post_id
				);
		}

		if (
			class_exists(
				'SRK_Internal_Linking_Settings'
			) &&
			! SRK_Internal_Linking_Settings::
				is_enabled()
		) {
			return;
		}

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

	/**
	 * Supported Elementor fields.
	 *
	 * Only safe rich-text fields are included.
	 *
	 * Third-party integrations can add their own mappings through:
	 * srk_internal_linking_elementor_widget_fields
	 *
	 * @return array
	 */
	private static function get_widget_field_map() {
		$map = array(
			'text-editor' => array(
				array(
					'type' => 'setting',
					'key'  => 'editor',
				),
			),

			'accordion' => array(
				array(
					'type'  => 'repeater',
					'key'   => 'tabs',
					'field' => 'tab_content',
				),
			),

			'toggle' => array(
				array(
					'type'  => 'repeater',
					'key'   => 'tabs',
					'field' => 'tab_content',
				),
			),

			'tabs' => array(
				array(
					'type'  => 'repeater',
					'key'   => 'tabs',
					'field' => 'tab_content',
				),
			),

			'testimonial' => array(
				array(
					'type' => 'setting',
					'key'  => 'testimonial_content',
				),
			),

			'icon-box' => array(
				array(
					'type' => 'setting',
					'key'  => 'description_text',
				),
			),

			'image-box' => array(
				array(
					'type' => 'setting',
					'key'  => 'description_text',
				),
			),

			'alert' => array(
				array(
					'type' => 'setting',
					'key'  => 'alert_description',
				),
			),
		);

		/**
		 * Filter Elementor widget fields SRK can safely analyze/write.
		 *
		 * Example mapping:
		 *
		 * 'my-widget' => array(
		 *     array(
		 *         'type' => 'setting',
		 *         'key'  => 'content',
		 *     ),
		 * );
		 */
		$map = apply_filters(
			'srk_internal_linking_elementor_widget_fields',
			$map
		);

		return is_array( $map )
			? $map
			: array();
	}

	/**
	 * Recursively collect supported Elementor content fragments.
	 *
	 * @param array $elements  Elementor elements.
	 * @param array $fragments Output fragments.
	 *
	 * @return void
	 */
	private static function collect_fragments(
		$elements,
		&$fragments
	) {
		if ( ! is_array( $elements ) ) {
			return;
		}

		$field_map =
			self::get_widget_field_map();

		foreach ( $elements as $element ) {
			if ( ! is_array( $element ) ) {
				continue;
			}

			$element_id =
				sanitize_text_field(
					$element['id'] ?? ''
				);

			$widget_type =
				sanitize_key(
					$element['widgetType']
						?? ''
				);

			$settings =
				isset(
					$element['settings']
				) &&
				is_array(
					$element['settings']
				)
					? $element['settings']
					: array();

			if (
				'' !== $widget_type &&
				isset(
					$field_map[
						$widget_type
					]
				)
			) {
				foreach (
					$field_map[
						$widget_type
					] as $definition
				) {
					$type =
						sanitize_key(
							$definition['type']
								?? ''
						);

					$key =
						sanitize_key(
							$definition['key']
								?? ''
						);

					if (
						'setting' === $type &&
						$key &&
						isset(
							$settings[
								$key
							]
						) &&
						is_string(
							$settings[
								$key
							]
						)
					) {
						$fragments[] = array(
							'html' =>
								$settings[
									$key
								],

							'widget_type' =>
								$widget_type,

							'locator' => array(
								'element_id' =>
									$element_id,

								'type' =>
									'setting',

								'key' =>
									$key,
							),
						);
					}

					if (
						'repeater' === $type &&
						$key &&
						! empty(
							$definition[
								'field'
							]
						) &&
						isset(
							$settings[
								$key
							]
						) &&
						is_array(
							$settings[
								$key
							]
						)
					) {
						$field =
							sanitize_key(
								$definition[
									'field'
								]
							);

						foreach (
							$settings[
								$key
							] as $row_index => $row
						) {
							if (
								! is_array(
									$row
								) ||
								! isset(
									$row[
										$field
									]
								) ||
								! is_string(
									$row[
										$field
									]
								)
							) {
								continue;
							}

							$fragments[] = array(
								'html' =>
									$row[
										$field
									],

								'widget_type' =>
									$widget_type,

								'locator' => array(
									'element_id' =>
										$element_id,

									'type' =>
										'repeater',

									'key' =>
										$key,

									'row_index' =>
										absint(
											$row_index
										),

									'field' =>
										$field,
								),
							);
						}
					}
				}
			}

			/*
			 * Elementor page content is recursive:
			 * containers, columns and nested widgets can contain children.
			 */
			if (
				! empty(
					$element['elements']
				) &&
				is_array(
					$element['elements']
				)
			) {
				self::collect_fragments(
					$element['elements'],
					$fragments
				);
			}
		}
	}

	/**
	 * Replace one exact mapped field in the Elementor tree.
	 *
	 * @param array  $elements Elementor elements, by reference.
	 * @param array  $locator  Field locator.
	 * @param string $new_html New HTML value.
	 *
	 * @return bool
	 */
	private static function update_locator_value(
		&$elements,
		$locator,
		$new_html
	) {
		if (
			! is_array( $elements ) ||
			! is_array( $locator )
		) {
			return false;
		}

		$wanted_id =
			(string) (
				$locator['element_id']
					?? ''
			);

		foreach ( $elements as &$element ) {
			if ( ! is_array( $element ) ) {
				continue;
			}

			if (
				$wanted_id &&
				$wanted_id ===
					(string) (
						$element['id']
							?? ''
					)
			) {
				if (
					! isset(
						$element['settings']
					) ||
					! is_array(
						$element['settings']
					)
				) {
					$element['settings'] =
						array();
				}

				$type =
					sanitize_key(
						$locator['type']
							?? ''
					);

				$key =
					sanitize_key(
						$locator['key']
							?? ''
					);

				if (
					'setting' === $type &&
					$key
				) {
					$element['settings'][
						$key
					] = $new_html;

					unset( $element );

					return true;
				}

				if (
					'repeater' === $type &&
					$key
				) {
					$row_index = absint(
						$locator[
							'row_index'
						] ?? 0
					);

					$field =
						sanitize_key(
							$locator[
								'field'
							] ?? ''
						);

					if (
						$field &&
						isset(
							$element['settings'][
								$key
							][
								$row_index
							]
						) &&
						is_array(
							$element['settings'][
								$key
							][
								$row_index
							]
						)
					) {
						$element['settings'][
							$key
						][
							$row_index
						][
							$field
						] = $new_html;

						unset( $element );

						return true;
					}
				}
			}

			if (
				! empty(
					$element['elements']
				) &&
				is_array(
					$element['elements']
				)
			) {
				$updated =
					self::update_locator_value(
						$element['elements'],
						$locator,
						$new_html
					);

				if ( $updated ) {
					unset( $element );

					return true;
				}
			}
		}

		unset( $element );

		return false;
	}

	/**
	 * Save Elementor elements through Elementor's Document API.
	 *
	 * @param int   $post_id  Post ID.
	 * @param array $elements Elementor elements.
	 *
	 * @return true|WP_Error
	 */
	private static function save_elements(
		$post_id,
		$elements
	) {
		$post_id = absint( $post_id );

		if (
			! $post_id ||
			! is_array( $elements ) ||
			! self::is_available()
		) {
			return new WP_Error(
				'srk_elementor_invalid_save_data',
				__(
					'Invalid Elementor document data.',
					'seo-repair-kit'
				)
			);
		}

		$document =
			self::get_document(
				$post_id,
				true,
				true
			);

		if ( ! $document ) {
			return new WP_Error(
				'srk_elementor_document_unavailable',
				__(
					'The Elementor document could not be loaded or edited.',
					'seo-repair-kit'
				)
			);
		}

		$documents_manager =
			\Elementor\Plugin::$instance->documents;

		$elementor_db =
			isset( \Elementor\Plugin::$instance->db )
				? \Elementor\Plugin::$instance->db
				: null;

		$document_switched = false;
		$post_switched     = false;
		$save_result       = false;
		$save_exception    = null;

		try {
			self::$saving = true;

			/*
			* Match Elementor's normal document-save context where supported.
			*/
			if (
				$documents_manager &&
				method_exists(
					$documents_manager,
					'switch_to_document'
				)
			) {
				$documents_manager->switch_to_document(
					$document
				);

				$document_switched = true;
			}

			if (
				$elementor_db &&
				method_exists(
					$elementor_db,
					'switch_to_post'
				)
			) {
				$elementor_db->switch_to_post(
					$post_id
				);

				$post_switched = true;
			}

			$save_result =
				$document->save(
					array(
						'elements' =>
							$elements,
					)
				);

		} catch ( Throwable $e ) {
			$save_exception = $e;

		} finally {

			if (
				$post_switched &&
				$elementor_db &&
				method_exists(
					$elementor_db,
					'restore_current_post'
				)
			) {
				$elementor_db->restore_current_post();
			}

			if (
				$document_switched &&
				$documents_manager &&
				method_exists(
					$documents_manager,
					'restore_document'
				)
			) {
				$documents_manager->restore_document();
			}

			self::$saving = false;
		}

		if ( $save_exception ) {
			return new WP_Error(
				'srk_elementor_save_failed',
				sprintf(
					/* translators: %s: Elementor error message */
					__(
						'Elementor could not save the link: %s',
						'seo-repair-kit'
					),
					sanitize_text_field(
						$save_exception->getMessage()
					)
				)
			);
		}

		/*
		* Elementor Document::save() can reject the save without throwing
		* an exception. Never report success in that situation.
		*/
		if ( true !== $save_result ) {
			return new WP_Error(
				'srk_elementor_save_rejected',
				__(
					'Elementor rejected the document save. The internal link was not stored.',
					'seo-repair-kit'
				)
			);
		}

		clean_post_cache(
			$post_id
		);

		return true;
	}

	/**
	 * Get Elementor document object.
	 *
	 * @param int  $post_id             Post ID.
	 * @param bool $require_permissions Require current-user editability.
	 * @param bool $fresh               Bypass Elementor document cache.
	 *
	 * @return object|false
	 */
	private static function get_document(
		$post_id,
		$require_permissions = false,
		$fresh = false
	) {
		$post_id = absint(
			$post_id
		);

		if (
			! $post_id ||
			! self::is_available()
		) {
			return false;
		}

		try {
			$document =
				\Elementor\Plugin::$instance
					->documents
					->get(
						$post_id,
						! $fresh
					);
		} catch ( Throwable $e ) {
			return false;
		}

		if ( ! $document ) {
			return false;
		}

		if ( $require_permissions ) {
			if (
				! current_user_can(
					'edit_post',
					$post_id
				)
			) {
				return false;
			}

			if (
				method_exists(
					$document,
					'is_editable_by_current_user'
				) &&
				! $document->
					is_editable_by_current_user()
			) {
				return false;
			}
		}

		return $document;
	}

	/**
	 * Insert a link into text HTML while protecting existing links
	 * and protected HTML elements.
	 *
	 * @param string $html       HTML fragment.
	 * @param string $anchor     Anchor.
	 * @param string $target_url Destination URL.
	 *
	 * @return string
	 */
	private static function insert_link_into_html(
		$html,
		$anchor,
		$target_url
	) {
		$html = (string) $html;

		$anchor = trim(
			wp_strip_all_tags(
				(string) $anchor
			)
		);

		if (
			'' === $html ||
			'' === $anchor ||
			'' === trim(
				(string) $target_url
			)
		) {
			return $html;
		}

		$words = preg_split(
			'/\s+/u',
			$anchor,
			-1,
			PREG_SPLIT_NO_EMPTY
		);

		if ( empty( $words ) ) {
			return $html;
		}

		$escaped_words = array_map(
			static function ( $word ) {
				return preg_quote(
					$word,
					'/'
				);
			},
			$words
		);

		$pattern =
			'/(?<![\p{L}\p{N}_])' .
			implode(
				'(?:\s|&nbsp;|\x{00A0})+',
				$escaped_words
			) .
			'(?![\p{L}\p{N}_])/iu';

		$parts = preg_split(
			'/(<[^>]+>)/u',
			$html,
			-1,
			PREG_SPLIT_DELIM_CAPTURE
		);

		if ( ! is_array( $parts ) ) {
			return $html;
		}

		$protected_tags = array(
			'a',
			'script',
			'style',
			'code',
			'pre',
			'textarea',
			'button',
			'h1',
			'h2',
			'h3',
			'h4',
			'h5',
			'h6',
		);

		$protected_depth = 0;
		$inserted        = false;
		$output          = '';

		foreach ( $parts as $part ) {
			if (
				isset( $part[0] ) &&
				'<' === $part[0]
			) {
				if (
					preg_match(
						'/^<\s*\/\s*([a-z0-9:-]+)/i',
						$part,
						$close_match
					)
				) {
					$tag = strtolower(
						$close_match[1]
					);

					if (
						in_array(
							$tag,
							$protected_tags,
							true
						)
					) {
						$protected_depth =
							max(
								0,
								$protected_depth - 1
							);
					}
				} elseif (
					preg_match(
						'/^<\s*([a-z0-9:-]+)/i',
						$part,
						$open_match
					)
				) {
					$tag = strtolower(
						$open_match[1]
					);

					if (
						in_array(
							$tag,
							$protected_tags,
							true
						) &&
						! preg_match(
							'/\/\s*>$/',
							$part
						)
					) {
						$protected_depth++;
					}
				}

				$output .= $part;

				continue;
			}

			if (
				! $inserted &&
				0 === $protected_depth
			) {
				$part =
					preg_replace_callback(
						$pattern,
						static function (
							$matches
						) use (
							$target_url,
							&$inserted
						) {
							if ( $inserted ) {
								return $matches[0];
							}

							$inserted = true;

							return '<a href="' .
								esc_url(
									$target_url
								) .
								'">' .
								$matches[0] .
								'</a>';
						},
						$part,
						1
					);
			}

			$output .= $part;
		}

		return $inserted
			? $output
			: $html;
	}

	/**
	 * Remove exact anchor+URL link while keeping its inner content.
	 *
	 * @param string $html       HTML.
	 * @param string $anchor     Anchor.
	 * @param string $target_url URL.
	 *
	 * @return string
	 */
	private static function remove_link_from_html(
		$html,
		$anchor,
		$target_url
	) {
		$removed = false;

		$expected_anchor =
			self::normalize_plain_text(
				$anchor
			);

		$expected_url =
			self::normalize_comparable_url(
				$target_url
			);

		return preg_replace_callback(
			'/<a\b([^>]*)\bhref\s*=\s*(["\'])(.*?)\2([^>]*)>(.*?)<\/a>/is',
			static function (
				$match
			) use (
				$expected_anchor,
				$expected_url,
				&$removed
			) {
				if ( $removed ) {
					return $match[0];
				}

				$current_url =
					self::normalize_comparable_url(
						html_entity_decode(
							(string) $match[3],
							ENT_QUOTES | ENT_HTML5,
							get_bloginfo(
								'charset'
							)
						)
					);

				$current_anchor =
					self::normalize_plain_text(
						$match[5]
					);

				if (
					$current_url ===
						$expected_url &&
					$current_anchor ===
						$expected_anchor
				) {
					$removed = true;

					return $match[5];
				}

				return $match[0];
			},
			(string) $html
		);
	}

	/**
	 * Check exact anchor+URL link.
	 *
	 * @param string $html       HTML.
	 * @param string $anchor     Anchor.
	 * @param string $target_url URL.
	 *
	 * @return bool
	 */
	private static function html_contains_anchor_link(
		$html,
		$anchor,
		$target_url
	) {
		$expected_anchor =
			self::normalize_plain_text(
				$anchor
			);

		$expected_url =
			self::normalize_comparable_url(
				$target_url
			);

		if (
			'' === $expected_anchor ||
			'' === $expected_url
		) {
			return false;
		}

		if (
			! preg_match_all(
				'/<a\b[^>]*\bhref\s*=\s*(["\'])(.*?)\1[^>]*>(.*?)<\/a>/is',
				(string) $html,
				$matches,
				PREG_SET_ORDER
			)
		) {
			return false;
		}

		foreach ( $matches as $match ) {
			$current_url =
				self::normalize_comparable_url(
					html_entity_decode(
						(string) $match[2],
						ENT_QUOTES | ENT_HTML5,
						get_bloginfo(
							'charset'
						)
					)
				);

			$current_anchor =
				self::normalize_plain_text(
					$match[3]
				);

			if (
				$current_url ===
					$expected_url &&
				$current_anchor ===
					$expected_anchor
			) {
				return true;
			}
		}

		return false;
	}

	/**
	 * Check if HTML already links to a target.
	 *
	 * @param string $html HTML.
	 * @param string $url  Target URL.
	 *
	 * @return bool
	 */
	private static function html_contains_target_url(
		$html,
		$url
	) {
		$expected =
			self::normalize_comparable_url(
				$url
			);

		if ( '' === $expected ) {
			return false;
		}

		if (
			! preg_match_all(
				'/<a\b[^>]*\bhref\s*=\s*(["\'])(.*?)\1/is',
				(string) $html,
				$matches,
				PREG_SET_ORDER
			)
		) {
			return false;
		}

		foreach ( $matches as $match ) {
			if (
				self::normalize_comparable_url(
					html_entity_decode(
						(string) $match[2],
						ENT_QUOTES | ENT_HTML5,
						get_bloginfo(
							'charset'
						)
					)
				) === $expected
			) {
				return true;
			}
		}

		return false;
	}

	/**
	 * Check plain rendered text for an anchor.
	 *
	 * @param string $html   HTML.
	 * @param string $anchor Anchor.
	 *
	 * @return bool
	 */
	private static function plain_contains_anchor(
		$html,
		$anchor
	) {
		$text =
			self::normalize_plain_text(
				$html
			);

		$anchor =
			self::normalize_plain_text(
				$anchor
			);

		if (
			'' === $text ||
			'' === $anchor
		) {
			return false;
		}

		return false !== strpos(
			$text,
			$anchor
		);
	}

	/**
	 * Rank same-anchor Elementor widgets using opportunity sentence context.
	 *
	 * @param string $html     Widget HTML.
	 * @param string $sentence Opportunity sentence.
	 * @param string $anchor   Anchor.
	 *
	 * @return int
	 */
	private static function context_score(
		$html,
		$sentence,
		$anchor
	) {
		$fragment =
			self::normalize_plain_text(
				$html
			);

		$sentence =
			self::normalize_plain_text(
				str_replace(
					array(
						'…',
						'...',
					),
					' ',
					$sentence
				)
			);

		$anchor =
			self::normalize_plain_text(
				$anchor
			);

		$score = 0;

		if (
			$anchor &&
			false !== strpos(
				$fragment,
				$anchor
			)
		) {
			$score += 40;
		}

		if (
			strlen( $sentence ) >= 20 &&
			false !== strpos(
				$fragment,
				$sentence
			)
		) {
			$score += 100;
		}

		if (
			'' !== $sentence &&
			class_exists(
				'SRK_Internal_Linking_Keywords'
			)
		) {
			$sentence_words =
				SRK_Internal_Linking_Keywords::
				meaningful_words(
					$sentence
				);

			$fragment_words =
				SRK_Internal_Linking_Keywords::
				meaningful_words(
					$fragment
				);

			$overlap = array_intersect(
				array_unique(
					$sentence_words
				),
				array_unique(
					$fragment_words
				)
			);

			$score += min(
				60,
				count( $overlap ) * 8
			);
		}

		return $score;
	}

	/**
	 * Normalize human-readable text.
	 *
	 * @param string $value Text/HTML.
	 *
	 * @return string
	 */
	private static function normalize_plain_text(
		$value
	) {
		$value = html_entity_decode(
			(string) $value,
			ENT_QUOTES | ENT_HTML5,
			get_bloginfo(
				'charset'
			)
		);

		$value = wp_strip_all_tags(
			$value
		);

		$value = preg_replace(
			'/\s+/u',
			' ',
			$value
		);

		$value = trim(
			(string) $value
		);

		return function_exists(
			'mb_strtolower'
		)
			? mb_strtolower(
				$value,
				'UTF-8'
			)
			: strtolower(
				$value
			);
	}

	/**
	 * Normalize URL for link comparison.
	 *
	 * @param string $url URL.
	 *
	 * @return string
	 */
	private static function normalize_comparable_url(
		$url
	) {
		$url = trim(
			html_entity_decode(
				(string) $url,
				ENT_QUOTES | ENT_HTML5,
				get_bloginfo(
					'charset'
				)
			)
		);

		if ( '' === $url ) {
			return '';
		}

		if ( 0 === strpos( $url, '/' ) ) {
			$url = home_url(
				$url
			);
		}

		$parts = wp_parse_url(
			$url
		);

		if ( ! is_array( $parts ) ) {
			return '';
		}

		$host = strtolower(
			(string) (
				$parts['host']
					?? ''
			)
		);

		$host = preg_replace(
			'/^www\./i',
			'',
			$host
		);

		$path = (string) (
			$parts['path']
				?? '/'
		);

		$path = '/' .
			ltrim(
				$path,
				'/'
			);

		if ( '/' !== $path ) {
			$path = rtrim(
				$path,
				'/'
			);
		}

		$query = ! empty(
			$parts['query']
		)
			? '?' .
				$parts['query']
			: '';

		return $host .
			$path .
			$query;
	}

	/**
	 * Refresh SRK's live link snapshot after an Elementor mutation.
	 *
	 * @param int $post_id Post ID.
	 *
	 * @return void
	 */
	private static function sync_after_mutation(
		$post_id
	) {
		$post_id = absint(
			$post_id
		);

		if (
			$post_id &&
			class_exists(
				'SRK_Internal_Linking_Indexer'
			)
		) {
			SRK_Internal_Linking_Indexer::
				index_single_post(
					$post_id
				);
		}

		if (
			class_exists(
				'SRK_Internal_Linking_DB'
			)
		) {
			SRK_Internal_Linking_DB::
				recalculate_inbound_counts();
		}

		delete_transient(
			'srk_il_report_internal_links'
		);

		delete_transient(
			'srk_il_report_orphan_content'
		);

		delete_transient(
			'srk_il_report_top_linked_pages'
		);
	}
}