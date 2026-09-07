<?php
/**
 * Schema Integration Handler for SEO Repair Kit
 *
 * @package     SEO_Repair_Kit
 * @subpackage  Schema
 */

// If this file is called directly, abort.
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

if ( ! class_exists( 'Seo_Repair_Kit_Schema_Integration' ) ) {

	/**
	 * Handles schema markup injection for posts and global schemas.
	 */
	class Seo_Repair_Kit_Schema_Integration {

	/**
	 * Output a JSON-LD script tag (extensible).
	 *
	 * @param array  $schema      JSON-LD schema as associative array.
	 * @param string $schema_type Lowercase schema type used for conflict detection.
	 * @param string $source      Unique source identifier for conflict detection.
	 * @param array  $context     Extra context for filters/actions.
	 *
	 * @return void
	 */
	private function output_json_ld( array $schema, string $schema_type, string $source, array $context = array() ): void {
		/**
		 * Filter the schema array right before conflict detection and output.
		 *
		 * Return a modified array to continue, or return null/false to skip output.
		 *
		 * @since  v2.1.7
		 * @param array       $schema      JSON-LD schema array.
		 * @param string      $schema_type Lowercase schema type used for conflict detection.
		 * @param string      $source      Unique source identifier for conflict detection.
		 * @param array       $context     Extra context (schema_key, post_id, is_global, etc).
		 */
		$schema = apply_filters( 'srk_schema_pre_output_schema', $schema, $schema_type, $source, $context );
		if ( empty( $schema ) || ! is_array( $schema ) ) {
			return;
		}

		/**
		 * Action fired immediately before SRK prints a JSON-LD script tag.
		 *
		 * @since  v2.1.7
		 * @param array  $schema      JSON-LD schema array (after filters).
		 * @param string $schema_type Lowercase schema type used for conflict detection.
		 * @param string $source      Unique source identifier for conflict detection.
		 * @param array  $context     Extra context (schema_key, post_id, is_global, etc).
		 */
		do_action( 'srk_schema_before_output', $schema, $schema_type, $source, $context );

		$encoded = wp_json_encode( $schema, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE );

		/**
		 * Filter the JSON string right before printing.
		 *
		 * @since  v2.1.7
		 * @param string $encoded     JSON encoded string (may be false on failure).
		 * @param array  $schema      Schema array.
		 * @param string $schema_type Lowercase schema type.
		 * @param string $source      Source identifier.
		 * @param array  $context     Extra context.
		 */
		$encoded = apply_filters( 'srk_schema_jsonld_string', $encoded, $schema, $schema_type, $source, $context );
		if ( empty( $encoded ) || ! is_string( $encoded ) ) {
			return;
		}

		echo '<script type="application/ld+json">' . wp_kses( $encoded, array() ) . '</script>';

		/**
		 * Action fired immediately after SRK prints a JSON-LD script tag.
		 *
		 * @since  v2.1.7
		 * @param array  $schema      JSON-LD schema array.
		 * @param string $schema_type Lowercase schema type.
		 * @param string $source      Source identifier.
		 * @param array  $context     Extra context.
		 */
		do_action( 'srk_schema_after_output', $schema, $schema_type, $source, $context );
	}

	/**
	 * Initialize schema integration hooks.
	 */
	public function __construct() {
		add_action( 'wp_head', array( $this, 'inject_schema_markup' ) );
		add_action( 'wp_head', array( $this, 'inject_global_schema' ) );
		// Log conflicts at the end of head output
		add_action( 'wp_head', array( $this, 'log_schema_conflicts' ), 999 );
	}

	/**
	 * Inject schema markup for singular posts.
	 */
	public function inject_schema_markup() {
		// ✅ Check if license plan is expired - block schema output if expired
		if ( class_exists( 'SRK_License_Helper' ) && ! SRK_License_Helper::is_schema_manager_enabled() ) {
			return;
		}

		if ( ! is_singular() ) {
			return;
		}

		global $post;
		$supported_schema_keys = array( 'FAQs', 'howto', 'author' );

		/**
		 * Filter the list of schema keys SRK will attempt to build for singular content.
		 *
		 * Note: These keys are "assignment keys" (used in option names), not necessarily @type values.
		 *
		 * @param array   $supported_schema_keys Default schema keys.
		 * @param WP_Post $post                 Current post object.
		 */
		$supported_schema_keys = apply_filters( 'srk_schema_supported_schema_keys', $supported_schema_keys, $post );
		$supported_schema_keys = is_array( $supported_schema_keys ) ? array_values( $supported_schema_keys ) : array();

			foreach ( $supported_schema_keys as $schema_key ) {
				$option_key = 'srk_schema_assignment_' . $schema_key;
				$settings   = get_option( $option_key );

				if ( empty( $settings ) ) {
					continue;
				}

				if ( ! class_exists( 'Seo_Repair_Kit_Build_Schema' ) ) {
					require_once plugin_dir_path( __FILE__ ) . 'class-seo-repair-kit-build-schema.php';
				}

				$builder = new Seo_Repair_Kit_Build_Schema();
				$schema  = $builder->build_schema( $schema_key, $post );

				if ( $schema ) {
					/**
					 * Filter a built singular schema before conflict detection / output.
					 *
					 * Return a modified schema array, or null/false to skip output.
					 *
					 * @param array|null $schema     Built schema.
					 * @param string     $schema_key Assignment key used by SRK.
					 * @param WP_Post    $post       Current post object.
					 */
					$schema = apply_filters( 'srk_schema_built_singular_schema', $schema, $schema_key, $post );
					if ( empty( $schema ) || ! is_array( $schema ) ) {
						continue;
					}

					// ✅ NEW: Check for conflicts before output
					if ( ! class_exists( 'SeoRepairKit_SchemaConflictDetector' ) ) {
						require_once plugin_dir_path( dirname( __FILE__ ) ) . 'includes/class-seo-repair-kit-schema-conflict-detector.php';
					}

					// ✅ For author schema, use @type from schema (Person or Organization) for conflict detection
					if ( 'author' === $schema_key && isset( $schema['@type'] ) ) {
						$schema_type = strtolower( $schema['@type'] );
						// ✅ Use a distinct source identifier for author schemas to distinguish from regular schemas
						$source = 'schema-integration-author-' . strtolower( $schema['@type'] );
					} else {
						$schema_type = isset( $schema['@type'] ) ? strtolower( $schema['@type'] ) : $schema_key;
						$source      = 'schema-integration-' . strtolower( $schema_key );
					}

					if ( SeoRepairKit_SchemaConflictDetector::can_output_schema( $schema, $schema_type, $source ) ) {
						$this->output_json_ld(
							$schema,
							(string) $schema_type,
							(string) $source,
							array(
								'is_global'  => false,
								'post_id'    => (int) $post->ID,
								'schema_key' => (string) $schema_key,
							)
						);
					}
				}
			}

			/**
			 * Filter to provide additional custom schemas for the current singular post.
			 *
			 * Return an array of schemas. Each schema can be:
			 * - an associative array (recommended), or
			 * - a JSON string (will be decoded), or
			 * - an array with key 'schema' holding the schema array/string, plus optional 'schema_type'/'source'.
			 *
			 * @since  v2.1.7
			 * @param array   $schemas List of custom schemas to output.
			 * @param WP_Post $post    Current post object.
			 */
			$custom_schemas = apply_filters( 'srk_schema_custom_schemas', array(), $post );
			if ( ! empty( $custom_schemas ) && is_array( $custom_schemas ) ) {
				if ( ! class_exists( 'SeoRepairKit_SchemaConflictDetector' ) ) {
					require_once plugin_dir_path( dirname( __FILE__ ) ) . 'includes/class-seo-repair-kit-schema-conflict-detector.php';
				}

				foreach ( $custom_schemas as $index => $custom_item ) {
					$schema      = null;
					$schema_type = null;
					$source      = null;

					if ( is_array( $custom_item ) && isset( $custom_item['schema'] ) ) {
						$schema      = $custom_item['schema'];
						$schema_type = $custom_item['schema_type'] ?? null;
						$source      = $custom_item['source'] ?? null;
					} else {
						$schema = $custom_item;
					}

					if ( is_string( $schema ) ) {
						$decoded = json_decode( $schema, true );
						if ( JSON_ERROR_NONE === json_last_error() && is_array( $decoded ) ) {
							$schema = $decoded;
						}
					}

					if ( empty( $schema ) || ! is_array( $schema ) ) {
						continue;
					}

					// Infer type if not provided.
					if ( empty( $schema_type ) && isset( $schema['@type'] ) ) {
						$schema_type = strtolower( (string) $schema['@type'] );
					}
					if ( empty( $schema_type ) ) {
						$schema_type = 'custom';
					}

					if ( empty( $source ) ) {
						$source = 'schema-integration-custom-' . (int) $post->ID . '-' . (int) $index;
					}

					/**
					 * Filter a custom schema before SRK outputs it.
					 *
					 * @since  v2.1.7
					 * @param array   $schema Built/loaded schema array.
					 * @param WP_Post $post   Current post object.
					 * @param int     $index  Index of schema in the provided list.
					 */
					$schema = apply_filters( 'srk_schema_custom_schema', $schema, $post, (int) $index );
					if ( empty( $schema ) || ! is_array( $schema ) ) {
						continue;
					}

					if ( SeoRepairKit_SchemaConflictDetector::can_output_schema( $schema, (string) $schema_type, (string) $source ) ) {
						$this->output_json_ld(
							$schema,
							(string) $schema_type,
							(string) $source,
							array(
								'is_global'   => false,
								'post_id'     => (int) $post->ID,
								'schema_key'  => 'custom',
								'custom_index'=> (int) $index,
							)
						);
					}
				}
			}
		}

	/**
	 * Inject global schema markup.
	 */
	public function inject_global_schema() {
		// ✅ Check if license plan is expired - block schema output if expired
		if ( class_exists( 'SRK_License_Helper' ) && ! SRK_License_Helper::is_schema_manager_enabled() ) {
			return;
		}

		$global_keys = array( 'organization', 'local_business', 'website', 'corporation', 'author' );

		/**
		 * Filter the list of schema keys SRK will attempt to build globally.
		 *
		 * @param array $global_keys Default global schema keys.
		 */
		$global_keys = apply_filters( 'srk_schema_global_schema_keys', $global_keys );
		$global_keys = is_array( $global_keys ) ? array_values( $global_keys ) : array();

			if ( ! class_exists( 'Seo_Repair_Kit_Build_Schema' ) ) {
				require_once plugin_dir_path( __FILE__ ) . 'class-seo-repair-kit-build-schema.php';
			}

			$builder = new Seo_Repair_Kit_Build_Schema();

			foreach ( $global_keys as $schema_key ) {
				$option_key = 'srk_schema_assignment_' . $schema_key;
				$settings   = get_option( $option_key );

				if ( empty( $settings ) ) {
					continue;
				}

				$schema = $builder->build_global_schema( $schema_key );

				if ( $schema ) {
					/**
					 * Filter a built global schema before conflict detection / output.
					 *
					 * Return a modified schema array, or null/false to skip output.
					 *
					 * @param array|null $schema     Built schema.
					 * @param string     $schema_key Assignment key used by SRK.
					 */
					$schema = apply_filters( 'srk_schema_built_global_schema', $schema, $schema_key );
					if ( empty( $schema ) || ! is_array( $schema ) ) {
						continue;
					}

					// ✅ NEW: Check for conflicts before output
					if ( ! class_exists( 'SeoRepairKit_SchemaConflictDetector' ) ) {
						require_once plugin_dir_path( dirname( __FILE__ ) ) . 'includes/class-seo-repair-kit-schema-conflict-detector.php';
					}

					// ✅ For author schema, use @type from schema (Person or Organization)
					// This ensures proper conflict detection
					if ( 'author' === $schema_key && isset( $schema['@type'] ) ) {
						$schema_type = strtolower( $schema['@type'] );
						// ✅ Use a distinct source identifier for author schemas to distinguish from regular schemas
						$source = 'schema-integration-global-author-' . strtolower( $schema['@type'] );
					} else {
						$schema_type = isset( $schema['@type'] ) ? strtolower( $schema['@type'] ) : $schema_key;
						$source = 'schema-integration-global-' . strtolower( $schema_key );
					}

					if ( SeoRepairKit_SchemaConflictDetector::can_output_schema( $schema, $schema_type, $source ) ) {
						$this->output_json_ld(
							$schema,
							(string) $schema_type,
							(string) $source,
							array(
								'is_global'  => true,
								'post_id'    => 0,
								'schema_key' => (string) $schema_key,
							)
						);
					}
				}
			}

			/**
			 * Filter to provide additional custom global schemas.
			 *
			 * Same shape as `srk_schema_custom_schemas`, but intended for sitewide output.
			 *
			 * @since  v2.1.7
			 * @param array $schemas List of custom global schemas to output.
			 */
			$custom_global_schemas = apply_filters( 'srk_schema_custom_global_schemas', array() );
			if ( ! empty( $custom_global_schemas ) && is_array( $custom_global_schemas ) ) {
				if ( ! class_exists( 'SeoRepairKit_SchemaConflictDetector' ) ) {
					require_once plugin_dir_path( dirname( __FILE__ ) ) . 'includes/class-seo-repair-kit-schema-conflict-detector.php';
				}

				foreach ( $custom_global_schemas as $index => $custom_item ) {
					$schema      = null;
					$schema_type = null;
					$source      = null;

					if ( is_array( $custom_item ) && isset( $custom_item['schema'] ) ) {
						$schema      = $custom_item['schema'];
						$schema_type = $custom_item['schema_type'] ?? null;
						$source      = $custom_item['source'] ?? null;
					} else {
						$schema = $custom_item;
					}

					if ( is_string( $schema ) ) {
						$decoded = json_decode( $schema, true );
						if ( JSON_ERROR_NONE === json_last_error() && is_array( $decoded ) ) {
							$schema = $decoded;
						}
					}

					if ( empty( $schema ) || ! is_array( $schema ) ) {
						continue;
					}

					if ( empty( $schema_type ) && isset( $schema['@type'] ) ) {
						$schema_type = strtolower( (string) $schema['@type'] );
					}
					if ( empty( $schema_type ) ) {
						$schema_type = 'custom';
					}

					if ( empty( $source ) ) {
						$source = 'schema-integration-custom-global-' . (int) $index;
					}

					$schema = apply_filters( 'srk_schema_custom_global_schema', $schema, (int) $index );
					if ( empty( $schema ) || ! is_array( $schema ) ) {
						continue;
					}

					if ( SeoRepairKit_SchemaConflictDetector::can_output_schema( $schema, (string) $schema_type, (string) $source ) ) {
						$this->output_json_ld(
							$schema,
							(string) $schema_type,
							(string) $source,
							array(
								'is_global'    => true,
								'post_id'      => 0,
								'schema_key'   => 'custom',
								'custom_index' => (int) $index,
							)
						);
					}
				}
			}
		}

	/**
	 * Log schema conflicts at the end of head output
	 *
	 * @since 2.1.0
	 *
	 * @return void
	 */
	public function log_schema_conflicts() {
		if ( ! class_exists( 'SeoRepairKit_SchemaConflictDetector' ) ) {
			require_once plugin_dir_path( dirname( __FILE__ ) ) . 'includes/class-seo-repair-kit-schema-conflict-detector.php';
		}

		SeoRepairKit_SchemaConflictDetector::log_conflicts();
	}
}

	new Seo_Repair_Kit_Schema_Integration();
}