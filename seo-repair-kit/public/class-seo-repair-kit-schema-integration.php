<?php
/**
 * Schema Integration Handler for SEO Repair Kit
 *
 * @package     SEO_Repair_Kit
 * @subpackage  Schema
 */

if ( ! class_exists( 'Seo_Repair_Kit_Schema_Integration' ) ) {

	/**
	 * Handles schema markup injection for posts and global schemas.
	 */
	class Seo_Repair_Kit_Schema_Integration {

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
		if ( class_exists( 'SRK_License_Helper' ) && SRK_License_Helper::is_license_expired() ) {
			return;
		}

		if ( ! is_singular() ) {
			return;
		}

		global $post;
		$supported_schema_keys = array( 'FAQs', 'howto', 'author' );

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
						echo '<script type="application/ld+json">' .
							wp_json_encode( $schema, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE ) .
							'</script>';
					}
				}
			}
		}

	/**
	 * Inject global schema markup.
	 */
	public function inject_global_schema() {
		// ✅ Check if license plan is expired - block schema output if expired
		if ( class_exists( 'SRK_License_Helper' ) && SRK_License_Helper::is_license_expired() ) {
			return;
		}

		$global_keys = array( 'organization', 'local_business', 'website', 'corporation', 'author' );

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
						echo '<script type="application/ld+json">' .
							wp_json_encode( $schema, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE ) .
							'</script>';
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