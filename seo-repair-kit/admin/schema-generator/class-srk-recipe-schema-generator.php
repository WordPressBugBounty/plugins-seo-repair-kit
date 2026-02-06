<?php
/**
 * Recipe Schema Generator for SEO Repair Kit
 *
 * @package     SEO_Repair_Kit
 * @subpackage  Schema
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Generates Recipe schema markup for recipe content.
 *
 * This class handles the generation of structured data for Recipe schema.org
 * markup, supporting ingredients, instructions, cooking times, and other
 * recipe-specific properties with dynamic field mapping.
 *
 * @since 2.1.0
 */
class SRK_Recipe_Schema_Generator {

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
	 * Initialize recipe schema functionality.
	 *
	 * @since 2.1.0
	 */
	public function __construct() {
		add_action( 'wp_head', array( $this, 'output_schema' ) );
	}

	/**
	 * Output recipe schema markup.
	 *
	 * Generates JSON-LD structured data for recipes based on mapped field values.
	 * Supports ingredients, instructions, and cooking time formatting.
	 *
	 * @since 2.1.0
	 *
	 * @return void
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

		$option_key = 'srk_schema_assignment_recipe';
		$saved_data = get_option( $option_key, array() );
		if ( empty( $saved_data ) ) {
			return;
		}

		// Get the field mappings and enabled fields.
		$this->field_map = isset( $saved_data['meta_map'] ) ? $saved_data['meta_map'] : array();
		$this->enabled_fields = isset( $saved_data['enabled_fields'] ) ? $saved_data['enabled_fields'] : array();
		
		if ( empty( $this->field_map ) ) {
			return;
		}

		$schema = $this->build_recipe_schema( $post, $this->field_map );

		// ✅ NEW: Validate required fields before output
		if ( ! class_exists( 'SeoRepairKit_SchemaValidator' ) ) {
			require_once plugin_dir_path( dirname( dirname( __FILE__ ) ) ) . 'includes/class-seo-repair-kit-schema-validator.php';
		}

		// Check if schema has all required fields (Recipe requires 'name')
		if ( ! SeoRepairKit_SchemaValidator::should_output_schema( $schema, 'recipe' ) ) {
			// Schema is missing required fields - do not output
			return;
		}

		// ✅ NEW: Check for conflicts before output
		if ( ! class_exists( 'SeoRepairKit_SchemaConflictDetector' ) ) {
			require_once plugin_dir_path( dirname( dirname( __FILE__ ) ) ) . 'includes/class-seo-repair-kit-schema-conflict-detector.php';
		}

		if ( ! SeoRepairKit_SchemaConflictDetector::can_output_schema( $schema, 'recipe', 'recipe-schema-generator' ) ) {
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
 
        $option_key = 'srk_schema_assignment_recipe';
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
	 * Build complete recipe schema array.
	 *
	 * @since 2.1.0
	 *
	 * @param WP_Post $post      Post object.
	 * @param array   $field_map Field mapping configuration.
	 * @return array Recipe schema data.
	 */
	private function build_recipe_schema( $post, $field_map ) {
		// Core schema.
		$schema = array(
			'@context'   => 'https://schema.org',
			'@type'      => 'Recipe',
			'url'        => get_permalink( $post->ID ),
		);

		// Define which fields to process based on enabled_fields
		$fields_to_process = array(
			'name' => function() use ($post) { 
				return $this->resolve_field_value( $this->field_map['name'], $post ); 
			},
			'description' => function() use ($post) { 
				return $this->resolve_field_value( $this->field_map['description'], $post ); 
			},
			'image' => function() use ($post) { 
				return $this->resolve_field_value( $this->field_map['image'], $post ); 
			},
			'recipeIngredient' => function() use ($post) { 
				return $this->resolve_field_value( $this->field_map['recipeIngredient'], $post ); 
			},
			'recipeInstructions' => function() use ($post) { 
				return $this->resolve_field_value( $this->field_map['recipeInstructions'], $post ); 
			},
			'prepTime' => function() use ($post) { 
				return $this->resolve_field_value( $this->field_map['prepTime'], $post ); 
			},
			'cookTime' => function() use ($post) { 
				return $this->resolve_field_value( $this->field_map['cookTime'], $post ); 
			},
			'totalTime' => function() use ($post) { 
				return $this->resolve_field_value( $this->field_map['totalTime'], $post ); 
			},
			'recipeYield' => function() use ($post) { 
				return $this->resolve_field_value( $this->field_map['recipeYield'], $post ); 
			},
			
			'author' => function() use ($post) { 
				return $this->resolve_field_value( $this->field_map['author'], $post ); 
			},
			'datePublished' => function() use ($post) { 
				return $this->resolve_field_value( $this->field_map['datePublished'], $post ); 
			},
			'aggregateRating' => function() use ($post) { 
				return $this->resolve_field_value( $this->field_map['aggregateRating'], $post ); 
			},
			'review' => function() use ($post) { 
				return $this->resolve_field_value( $this->field_map['review'], $post ); 
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
				$schema = $this->process_recipe_field( $schema, $field, $value );
			}
		}

		// Add fallback for basic fields if they're enabled but don't have specific mappings
		if ( in_array( 'name', $this->enabled_fields ) && ! isset( $schema['name'] ) ) {
			$title = get_the_title( $post->ID );
			if ( ! empty( $title ) ) {
				$schema['name'] = $title;
			}
		}

		if ( in_array( 'description', $this->enabled_fields ) && ! isset( $schema['description'] ) ) {
			$excerpt = get_the_excerpt( $post->ID );
			if ( ! empty( $excerpt ) ) {
				$schema['description'] = $excerpt;
			}
		}

		if ( in_array( 'image', $this->enabled_fields ) && ! isset( $schema['image'] ) ) {
			$image_url = get_the_post_thumbnail_url( $post->ID, 'full' );
			if ( $image_url ) {
				$schema['image'] = $image_url;
			}
		}

		return array_filter( $schema );
	}

	/**
	 * Process recipe-specific field values.
	 *
	 * @since 2.1.0
	 *
	 * @param array  $schema     Schema data.
	 * @param string $schema_key Schema field key.
	 * @param mixed  $value      Field value.
	 * @return array Modified schema data.
	 */
	private function process_recipe_field( $schema, $schema_key, $value ) {
		switch ( $schema_key ) {
			case 'recipeIngredient':
				if ( is_string( $value ) ) {
					$schema['recipeIngredient'] = array_filter( array_map( 'trim', explode( "\n", $value ) ) );
				} else {
					$schema['recipeIngredient'] = $value;
				}
				break;

			case 'recipeInstructions':
				if ( is_string( $value ) ) {
					$steps = array_filter( array_map( 'trim', explode( "\n", $value ) ) );
					$schema['recipeInstructions'] = array_map(
						function( $step, $index ) {
							return array(
								'@type'    => 'HowToStep',
								'position' => $index + 1,
								'text'     => $step,
							);
						},
						$steps,
						array_keys( $steps )
					);
				} else {
					$schema['recipeInstructions'] = $value;
				}
				break;

			case 'prepTime':
			case 'cookTime':
			case 'totalTime':
				if ( ! preg_match( '/^PT\d+[HM]$/i', $value ) ) {
					if ( preg_match( '/(\d+)\s*(minute|min|hour|hr|h)/i', $value, $matches ) ) {
						$num  = (int) $matches[1];
						$unit = strtolower( $matches[2] );
						$value = in_array( $unit, array( 'minute', 'min' ), true ) ? "PT{$num}M" : "PT{$num}H";
					}
				}
				$schema[ $schema_key ] = $value;
				break;

			case 'aggregateRating':
				if ( is_array( $value ) ) {
					$schema['aggregateRating'] = $value;
				} else {
					$schema['aggregateRating'] = array(
						'@type'       => 'AggregateRating',
						'ratingValue' => $value,
						'bestRating'  => '5',
						'worstRating' => '1',
					);
				}
				break;

			case 'author':
				$schema['author'] = array(
					'@type' => 'Person',
					'name'  => $value,
				);
				break;

			default:
				$schema[ $schema_key ] = $value;
				break;
		}

		return $schema;
	}

	/**
	 * Resolve field value based on mapping configuration.
	 *
	 * @since 2.1.0
	 *
	 * @param mixed   $mapping Field mapping configuration.
	 * @param WP_Post $post    Post object.
	 * @return mixed Resolved field value.
	 */
	private function resolve_field_value( $mapping, $post ) {
		if ( empty( $mapping ) ) {
			return null;
		}

		// Handle the mapping format (e.g., "meta:recipe_instructions", "post:post_title").
		if ( strpos( $mapping, ':' ) !== false ) {
			list( $source_type, $field_name ) = explode( ':', $mapping, 2 );

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

		// Fallback: try to get the value directly from post meta.
		return get_post_meta( $post->ID, $mapping, true );
	}

	/**
	 * Get post field value.
	 *
	 * @since 2.1.0
	 *
	 * @param string  $field_name Field name.
	 * @param WP_Post $post       Post object.
	 * @return mixed Field value.
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
				// For other post fields or meta fields.
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
 * Initialize the Recipe Schema Generator.
 *
 * @since 2.1.0
 */
function srk_init_recipe_schema_generator() {
	new SRK_Recipe_Schema_Generator();
}
add_action( 'init', 'srk_init_recipe_schema_generator' );