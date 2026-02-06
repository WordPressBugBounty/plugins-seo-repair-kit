<?php
/**
* SEO Repair Kit - Review Schema Generator
* Handles dynamic field mapping with aggregate rating for review schemas
*
* @package SEO_Repair_Kit
*/

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
* Review Schema Generator Class
*/
class SRK_Review_Schema_Generator {

	/**
	 * Field mapping configuration
	 *
	 * @var array
	 */
	private $field_map = array();

	/**
	 * Enabled fields configuration
	 *
	 * @var array
	 */
	private $enabled_fields = array();

	/**
	 * Constructor
	 */
	public function __construct() {
		add_action( 'wp_head', array( $this, 'output_schema' ), 5 );
	}

	/**
	 * Output schema markup
	 */
	public function output_schema() {
		// ✅ Check if license plan is expired - block schema output if expired
		if ( class_exists( 'SRK_License_Helper' ) && SRK_License_Helper::is_license_expired() ) {
			return;
		}

		if ( ! $this->should_output_schema() ) {
		  return;
		}

		global $post;

		$option_key = 'srk_schema_assignment_review';
		$saved_data = get_option( $option_key, array() );
		if ( empty( $saved_data ) ) {
			return;
		}
		
		$this->field_map = isset( $saved_data['meta_map'] ) ? $saved_data['meta_map'] : array();
		$this->enabled_fields = isset( $saved_data['enabled_fields'] ) ? $saved_data['enabled_fields'] : array();
		
		if ( empty( $this->field_map ) ) {
			return;
		}

		$schema = array(
			'@context' => 'https://schema.org',
			'@type'    => 'Review',
			'url'      => get_permalink( $post->ID ),
		);

		// Define which fields to process based on enabled_fields
		$fields_to_process = array(
			'name' => function() use ($post) { 
				return $this->resolve_field_value( $this->field_map['name'], $post ); 
			},
			'author' => function() use ($post) { 
				return $this->resolve_field_value( $this->field_map['author'], $post ); 
			},
			'itemReviewed' => function() use ($post) { 
				return $this->resolve_field_value( $this->field_map['itemReviewed'], $post ); 
			},
			'reviewRating' => function() use ($post) { 
				return $this->resolve_field_value( $this->field_map['reviewRating'], $post ); 
			},
			'datePublished' => function() use ($post) { 
				return $this->resolve_field_value( $this->field_map['datePublished'], $post ); 
			},
			'reviewBody' => function() use ($post) { 
				return $this->resolve_field_value( $this->field_map['reviewBody'], $post ); 
			},
			'description' => function() use ($post) { 
				return $this->resolve_field_value( $this->field_map['description'], $post ); 
			},
			'image' => function() use ($post) { 
				return $this->resolve_field_value( $this->field_map['image'], $post ); 
			}
		);

		// Process only enabled fields
		foreach ( $fields_to_process as $field => $value_callback ) {
			// Skip if field is not enabled and enabled_fields array exists
			if ( ! empty( $this->enabled_fields ) && ! in_array( $field, $this->enabled_fields ) ) {
				continue;
			}

			// Skip if field mapping doesn't exist
			if ( ! isset( $this->field_map[ $field ] ) ) {
				continue;
			}

			$value = $value_callback();
			
			if ( ! empty( $value ) ) {
				$schema = $this->process_review_field( $schema, $field, $value, $post );
			}
		}

		// REMOVED the fallback behavior for description and image
		// These fields will now only appear if they are enabled and have mappings

		// ✅ NEW: Validate required fields before output
		if ( ! class_exists( 'SeoRepairKit_SchemaValidator' ) ) {
			require_once plugin_dir_path( dirname( dirname( __FILE__ ) ) ) . 'includes/class-seo-repair-kit-schema-validator.php';
		}

		// Check if schema has all required fields (Review requires 'itemReviewed', 'reviewRating', 'author')
		if ( ! SeoRepairKit_SchemaValidator::should_output_schema( $schema, 'review' ) ) {
			// Schema is missing required fields - do not output
			return;
		}

		// ✅ NEW: Check for conflicts before output
		if ( ! class_exists( 'SeoRepairKit_SchemaConflictDetector' ) ) {
			require_once plugin_dir_path( dirname( dirname( __FILE__ ) ) ) . 'includes/class-seo-repair-kit-schema-conflict-detector.php';
		}

		if ( ! SeoRepairKit_SchemaConflictDetector::can_output_schema( $schema, 'review', 'review-schema-generator' ) ) {
			// Schema conflicts with another schema - do not output
			return;
		}

		echo '<script type="application/ld+json">' .
			wp_json_encode( $schema, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT ) .
			'</script>';
	}

	private function should_output_schema() {
        if ( ! is_singular() ) {
            return false;
        }

        global $post;

        $option_key = 'srk_schema_assignment_review';
        $saved_data = get_option( $option_key, array() );

        // Agar schema configured nahi hai
        if ( empty( $saved_data ) || ! isset( $saved_data['meta_map'] ) ) {
            return false;
        }

        $assigned_post_type = isset( $saved_data['post_type'] ) ? $saved_data['post_type'] : '';
        $current_post_type = get_post_type( $post->ID );

        // Global schema - sab par apply hoga
        if ( $assigned_post_type === 'global' ) {
            return true;
        }

        // Specific post type assigned - sirf usi par apply hoga
        if ( $assigned_post_type && $current_post_type !== $assigned_post_type ) {
            return false;
        }

        // Specific post selected - sirf usi post par apply hoga
        if ( isset( $saved_data['selected_post'] ) && $saved_data['selected_post'] > 0 ) {
            return $post->ID == $saved_data['selected_post'];
        }

        return true;
    }

	/**
	 * Process review field
	 *
	 * @param array  $schema Schema array.
	 * @param string $schema_key Field key.
	 * @param mixed  $value Field value.
	 * @param object $post WordPress post object.
	 * @return array Modified schema
	 */
	private function process_review_field( $schema, $schema_key, $value, $post ) {
		global $wpdb;

		switch ( $schema_key ) {
			case 'name':
				$schema['name'] = $value;
				break;

			case 'author':
				$schema['author'] = array(
					'@type' => 'Person',
					'name'  => $value,
				);
				break;

			case 'itemReviewed':
				if ( is_array( $value ) ) {
					$value = implode( ', ', $value );
				}
				$schema['itemReviewed'] = array(
					'@type' => 'Product',
					'name'  => $value,
				);

				// ✅ Find dynamically mapped meta key for itemReviewed
				$item_reviewed_mapping = isset( $this->field_map['itemReviewed'] ) ? $this->field_map['itemReviewed'] : '';
				if ( $item_reviewed_mapping && strpos( $item_reviewed_mapping, 'meta:' ) === 0 ) {
					$item_reviewed_meta_key = str_replace( 'meta:', '', $item_reviewed_mapping );

					// ✅ Fetch all ratings for same itemReviewed
					$ratings = $wpdb->get_col(
						$wpdb->prepare(
							"SELECT pm.meta_value
							 FROM $wpdb->postmeta pm
							 INNER JOIN $wpdb->posts p ON pm.post_id = p.ID
							 WHERE pm.meta_key = 'rating'
							 AND p.post_type = %s
							 AND p.post_status = 'publish'
							 AND EXISTS (
								SELECT 1 FROM $wpdb->postmeta
								WHERE post_id = pm.post_id
								AND meta_key = %s
								AND meta_value = %s
							 )",
							get_post_type( $post->ID ),
							$item_reviewed_meta_key,
							$value
						)
					);

					if ( ! empty( $ratings ) ) {
						$ratings = array_map( 'floatval', $ratings );
						$avg     = array_sum( $ratings ) / count( $ratings );

						$schema['itemReviewed']['aggregateRating'] = array(
							'@type'       => 'AggregateRating',
							'ratingValue' => round( $avg, 1 ),
							'reviewCount' => count( $ratings ),
							'bestRating'  => '5',
							'worstRating' => '1',
						);
					}
				}
				break;

			case 'reviewRating':
				$rating_value = is_numeric( $value ) ? (float) $value : 0;
				$schema['reviewRating'] = array(
					'@type'       => 'Rating',
					'ratingValue' => $rating_value,
				);
				break;

			case 'datePublished':
				try {
					$date                     = new DateTime( $value );
					$schema['datePublished'] = $date->format( DateTime::W3C );
				} catch ( Exception $e ) {
					$schema['datePublished'] = $value;
				}
				break;

			case 'reviewBody':
				$schema['reviewBody'] = wp_strip_all_tags( $value );
				break;

			case 'description':
				$schema['description'] = $value;
				break;

			case 'image':
				$schema['image'] = $value;
				break;

			default:
				$schema[ $schema_key ] = $value;
				break;
		}

		return $schema;
	}

	/**
	 * Resolve field value from mapping
	 *
	 * @param string $mapping Field mapping.
	 * @param object $post WordPress post object.
	 * @return mixed Field value
	 */
	private function resolve_field_value( $mapping, $post ) {
		if ( empty( $mapping ) ) {
			return null;
		}

		if ( strpos( $mapping, ':' ) !== false ) {
			list($source_type, $field_name) = explode( ':', $mapping, 2 );

			switch ( $source_type ) {
				case 'post':
					return $this->get_post_field_value( $field_name, $post );

				case 'meta':
					return get_post_meta( $post->ID, $field_name, true );

				case 'user':
					$author_id = get_post_field( 'post_author', $post->ID );
					return get_user_meta( $author_id, $field_name, true );

				case 'tax':
					$terms = wp_get_post_terms( $post->ID, $field_name, array( 'fields' => 'names' ) );
					return ! empty( $terms ) ? $terms : array();

				case 'site':
					return $this->get_site_value( $field_name );

				default:
					return null;
			}
		}

		return get_post_meta( $post->ID, $mapping, true );
	}

	/**
	 * Get post field value
	 *
	 * @param string $field_name Field name.
	 * @param object $post WordPress post object.
	 * @return mixed Field value
	 */
	private function get_post_field_value( $field_name, $post ) {
		switch ( $field_name ) {
			case 'post_title':
				return get_the_title( $post->ID );

			case 'post_excerpt':
				$excerpt = get_the_excerpt( $post->ID );
				return ! empty( $excerpt ) ? $excerpt : wp_trim_words( $post->post_content, 30 );

			case 'post_content':
				return wp_strip_all_tags( $post->post_content );

			case 'featured_image':
				return get_the_post_thumbnail_url( $post->ID, 'full' );

			case 'post_date':
				return get_the_date( 'c', $post->ID );

			case 'post_modified':
				return get_the_modified_date( 'c', $post->ID );

			case 'post_author':
				$author_id = get_post_field( 'post_author', $post->ID );
				return get_the_author_meta( 'display_name', $author_id );

			default:
				if ( isset( $post->$field_name ) ) {
					return $post->$field_name;
				}
				return get_post_meta( $post->ID, $field_name, true );
		}
	}

	/**
	 * Get site value
	 *
	 * @param string $field_name Field name.
	 * @return mixed Site value
	 */
	private function get_site_value( $field_name ) {
		switch ( $field_name ) {
			case 'site_name':
				return get_bloginfo( 'name' );

			case 'site_url':
				return home_url();

			case 'site_description':
				return get_bloginfo( 'description' );

			case 'admin_email':
				return get_option( 'admin_email' );

			default:
				return get_option( $field_name, '' );
		}
	}
}

/**
* Initialize review schema generator
*/
function srk_init_review_schema_generator() {
	new SRK_Review_Schema_Generator();
}
add_action( 'init', 'srk_init_review_schema_generator' );