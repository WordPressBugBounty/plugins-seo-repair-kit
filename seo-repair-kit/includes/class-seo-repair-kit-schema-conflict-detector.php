<?php
/**
 * Schema Conflict Detector for SEO Repair Kit
 *
 * Detects and prevents conflicting schemas from being output on the same page.
 * Google may ignore or penalize sites with conflicting structured data.
 *
 * @package     SEO_Repair_Kit
 * @subpackage  Schema
 * @since       2.1.0
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Schema Conflict Detector Class
 *
 * Tracks all schemas being output on a page and detects conflicts.
 *
 * @since 2.1.0
 */
class SeoRepairKit_SchemaConflictDetector {

	/**
	 * Registry of schemas being output on current page
	 *
	 * @var array
	 */
	private static $schema_registry = array();

	/**
	 * Conflicts detected on current page
	 *
	 * @var array
	 */
	private static $conflicts = array();

	/**
	 * Schema type groups that conflict with each other
	 *
	 * @var array
	 */
	private static $conflict_groups = array(
		// Article types - only one should be output per page
		'article_types' => array( 'article', 'blogposting', 'newsarticle' ),
		// Organization types - LocalBusiness extends Organization, shouldn't have both
		'organization_types' => array( 'organization', 'localbusiness', 'corporation' ),
		// Product-related - Review should reference Product, not be separate
		'product_review' => array( 'product', 'review' ),
	);

	/**
	 * Check if a schema can be output without conflicts
	 *
	 * @since 2.1.0
	 *
	 * @param array  $schema      Schema array.
	 * @param string $schema_type Schema type key.
	 * @param string $source      Source identifier (e.g., 'product-schema', 'faq-manager').
	 * @return bool True if schema can be output, false if there's a conflict.
	 */
	public static function can_output_schema( $schema, $schema_type, $source = '' ) {
		if ( empty( $schema ) || ! is_array( $schema ) ) {
			return false;
		}

		// Normalize schema type
		$schema_type = strtolower( $schema_type );

		// Get @type from schema if not provided
		if ( empty( $schema_type ) && isset( $schema['@type'] ) ) {
			$schema_type = strtolower( $schema['@type'] );
		}

		if ( empty( $schema_type ) ) {
			return false;
		}

		// Check for conflicts
		$conflict = self::detect_conflict( $schema_type, $source );

		if ( $conflict ) {
			// Log the conflict
			self::$conflicts[] = array(
				'schema_type' => $schema_type,
				'source'      => $source,
				'conflict'    => $conflict,
			);

			// Option to prevent output (can be filtered)
			$prevent_output = apply_filters( 'srk_prevent_conflicting_schema_output', true, $schema_type, $conflict );

			if ( $prevent_output ) {
				return false;
			}
		}

		// Register this schema
		self::register_schema( $schema_type, $source );

		return true;
	}

	/**
	 * Detect if a schema type conflicts with already registered schemas
	 *
	 * @since 2.1.0
	 *
	 * @param string $schema_type Schema type.
	 * @param string $source      Source identifier.
	 * @return array|false Conflict details or false if no conflict.
	 */
	private static function detect_conflict( $schema_type, $source ) {
		// ✅ Special handling: Author schema with Organization/Person type should not conflict
		// with regular organization/person schemas - they serve different purposes
		$is_author_schema = ( 
			strpos( $source, 'author' ) !== false || 
			strpos( $source, 'schema-integration-global-author' ) !== false ||
			strpos( $source, 'schema-integration-global-author-' ) !== false
		);
		
		// Check for duplicate schema types
		foreach ( self::$schema_registry as $registered ) {
			// ✅ Allow author schemas to coexist with regular schemas of the same type
			// Author Organization is different from regular Organization schema
			$is_registered_author = ( 
				strpos( $registered['source'], 'author' ) !== false || 
				strpos( $registered['source'], 'schema-integration-global-author' ) !== false ||
				strpos( $registered['source'], 'schema-integration-global-author-' ) !== false
			);
			
			if ( $registered['type'] === $schema_type && $registered['source'] !== $source ) {
				// ✅ If one is author and the other is not, allow both (they serve different purposes)
				if ( ( $is_author_schema && ! $is_registered_author ) || ( ! $is_author_schema && $is_registered_author ) ) {
					continue; // Skip conflict check - author schemas can coexist
				}
				
				return array(
					'type'        => 'duplicate',
					'conflicting' => $registered['source'],
					'message'     => sprintf(
						/* translators: %1$s: Schema type, %2$s: Conflicting source */
						__( 'Duplicate %1$s schema detected. Already output by %2$s.', 'seo-repair-kit' ),
						ucfirst( $schema_type ),
						$registered['source']
					),
				);
			}
		}

		// Check conflict groups
		foreach ( self::$conflict_groups as $group_name => $group_types ) {
			if ( in_array( $schema_type, $group_types, true ) ) {
				// Check if any schema in this group is already registered
				foreach ( self::$schema_registry as $registered ) {
					// ✅ Allow author Organization to coexist with regular Organization
					$is_registered_author = ( 
						strpos( $registered['source'], 'author' ) !== false || 
						strpos( $registered['source'], 'schema-integration-global-author' ) !== false ||
						strpos( $registered['source'], 'schema-integration-global-author-' ) !== false
					);
					
					if ( in_array( $registered['type'], $group_types, true ) && $registered['type'] !== $schema_type ) {
						// ✅ If one is author and the other is not, allow both
						if ( ( $is_author_schema && ! $is_registered_author ) || ( ! $is_author_schema && $is_registered_author ) ) {
							continue; // Skip conflict check
						}
						
						return array(
							'type'        => 'group_conflict',
							'group'       => $group_name,
							'conflicting' => $registered['source'],
							'conflicting_type' => $registered['type'],
							'message'     => sprintf(
								/* translators: %1$s: Schema type, %2$s: Conflicting schema type, %3$s: Source */
								__( '%1$s schema conflicts with %2$s schema already output by %3$s. Only one should be output per page.', 'seo-repair-kit' ),
								ucfirst( $schema_type ),
								ucfirst( $registered['type'] ),
								$registered['source']
							),
						);
					}
				}
			}
		}

		// Special case: Product and Review
		if ( 'product' === $schema_type ) {
			foreach ( self::$schema_registry as $registered ) {
				if ( 'review' === $registered['type'] ) {
					return array(
						'type'        => 'product_review',
						'conflicting' => $registered['source'],
						'message'     => sprintf(
							/* translators: %s: Source */
							__( 'Product schema conflicts with Review schema already output by %s. Review should reference Product, not be separate.', 'seo-repair-kit' ),
							$registered['source']
						),
					);
				}
			}
		}

		if ( 'review' === $schema_type ) {
			foreach ( self::$schema_registry as $registered ) {
				if ( 'product' === $registered['type'] ) {
					return array(
						'type'        => 'product_review',
						'conflicting' => $registered['source'],
						'message'     => sprintf(
							/* translators: %s: Source */
							__( 'Review schema conflicts with Product schema already output by %s. Review should reference Product, not be separate.', 'seo-repair-kit' ),
							$registered['source']
						),
					);
				}
			}
		}

		return false;
	}

	/**
	 * Register a schema that will be output
	 *
	 * @since 2.1.0
	 *
	 * @param string $schema_type Schema type.
	 * @param string $source      Source identifier.
	 * @return void
	 */
	private static function register_schema( $schema_type, $source ) {
		self::$schema_registry[] = array(
			'type'   => $schema_type,
			'source' => $source,
		);
	}

	/**
	 * Get all conflicts detected on current page
	 *
	 * @since 2.1.0
	 *
	 * @return array Array of conflicts.
	 */
	public static function get_conflicts() {
		return self::$conflicts;
	}

	/**
	 * Get all registered schemas for current page
	 *
	 * @since 2.1.0
	 *
	 * @return array Array of registered schemas.
	 */
	public static function get_registered_schemas() {
		return self::$schema_registry;
	}

	/**
	 * Clear registry (useful for testing or page reload)
	 *
	 * @since 2.1.0
	 *
	 * @return void
	 */
	public static function clear_registry() {
		self::$schema_registry = array();
		self::$conflicts       = array();
	}

	/**
	 * Log conflicts to WordPress debug log (if WP_DEBUG is enabled)
	 *
	 * @since 2.1.0
	 *
	 * @return void
	 */
	public static function log_conflicts() {
		$conflicts = self::get_conflicts();
		if ( empty( $conflicts ) ) {
			return;
		}

		// Store conflicts in transient for admin display
		$current_url = is_admin() ? admin_url() : ( is_singular() ? get_permalink() : home_url() );
		$transient_key = 'srk_schema_conflicts_' . md5( $current_url );
		set_transient( $transient_key, $conflicts, HOUR_IN_SECONDS );

		// Log to debug log if WP_DEBUG is enabled
		if ( defined( 'WP_DEBUG' ) && WP_DEBUG ) {
			error_log( sprintf( '[SRK Schema Conflicts] Page: %s', $current_url ) );
			foreach ( $conflicts as $conflict ) {
				error_log( sprintf( '[SRK Schema Conflicts] %s', $conflict['conflict']['message'] ) );
			}
		}
	}

	/**
	 * Get stored conflicts for a URL
	 *
	 * @since 2.1.0
	 *
	 * @param string $url URL to check conflicts for.
	 * @return array Array of conflicts.
	 */
	public static function get_stored_conflicts( $url = '' ) {
		if ( empty( $url ) ) {
			$url = is_admin() ? admin_url() : ( is_singular() ? get_permalink() : home_url() );
		}

		$transient_key = 'srk_schema_conflicts_' . md5( $url );
		return get_transient( $transient_key ) ?: array();
	}

	/**
	 * Clear stored conflicts for a URL
	 *
	 * @since 2.1.0
	 *
	 * @param string $url URL to clear conflicts for.
	 * @return void
	 */
	public static function clear_stored_conflicts( $url = '' ) {
		if ( empty( $url ) ) {
			$url = is_admin() ? admin_url() : ( is_singular() ? get_permalink() : home_url() );
		}

		$transient_key = 'srk_schema_conflicts_' . md5( $url );
		delete_transient( $transient_key );
	}
}
