<?php
/**
 * Medical Condition Schema Generator for SEO Repair Kit
 *
 * @package     SEO_Repair_Kit
 * @subpackage  Schema
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Generates MedicalCondition schema markup for medical content.
 *
 * This class handles the generation of structured data for MedicalCondition
 * schema.org markup, supporting symptoms, treatments, risk factors, and other
 * medical-specific properties with proper medical schema formatting.
 *
 * @since 2.1.0
 */
class SRK_MedicalCondition_Schema_Generator {

	/**
	 * Initialize medical condition schema functionality.
	 *
	 * @since 2.1.0
	 */
	public function __construct() {
		add_action( 'wp_head', array( $this, 'output_schema' ) );
	}

	/**
	 * Output medical condition schema markup.
	 *
	 * Generates JSON-LD structured data for medical conditions based on mapped
	 * field values. Supports symptoms, treatments, and medical terminology.
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

		// Dynamic mapping from schema assignment options.
		$option_key = 'srk_schema_assignment_medical_condition';
		$saved_data = get_option( $option_key, array() );

		if ( empty( $saved_data ) || ! isset( $saved_data['meta_map'] ) ) {
			return;
		}

		$field_map = $saved_data['meta_map'];
		$enabled_fields = isset( $saved_data['enabled_fields'] ) ? $saved_data['enabled_fields'] : array();
		
		$schema    = $this->build_medical_condition_schema( $post, $field_map, $enabled_fields );

		// ✅ NEW: Validate required fields before output
		if ( ! class_exists( 'SeoRepairKit_SchemaValidator' ) ) {
			require_once plugin_dir_path( dirname( dirname( __FILE__ ) ) ) . 'includes/class-seo-repair-kit-schema-validator.php';
		}

		// Check if schema has all required fields (MedicalCondition requires 'name')
		if ( ! SeoRepairKit_SchemaValidator::should_output_schema( $schema, 'medical_condition' ) ) {
			// Schema is missing required fields - do not output
			return;
		}

		// ✅ NEW: Check for conflicts before output
		if ( ! class_exists( 'SeoRepairKit_SchemaConflictDetector' ) ) {
			require_once plugin_dir_path( dirname( dirname( __FILE__ ) ) ) . 'includes/class-seo-repair-kit-schema-conflict-detector.php';
		}

		if ( ! SeoRepairKit_SchemaConflictDetector::can_output_schema( $schema, 'medical_condition', 'medicalcondition-schema-generator' ) ) {
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
 
        $option_key = 'srk_schema_assignment_medical_condition';
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
	 * Build complete medical condition schema array.
	 *
	 * @since 2.1.0
	 *
	 * @param WP_Post $post      Post object.
	 * @param array   $field_map Field mapping configuration.
	 * @param array   $enabled_fields Enabled fields configuration.
	 * @return array Medical condition schema data.
	 */
	private function build_medical_condition_schema( $post, $field_map, $enabled_fields ) {
		$schema = array(
			'@context' => 'https://schema.org',
			'@type'    => 'MedicalCondition',
			'url'      => get_permalink( $post->ID ),
		);

		// Add basic post info only if fields are enabled
		if ( empty( $enabled_fields ) || in_array( 'name', $enabled_fields ) ) {
			$title = get_the_title( $post->ID );
			if ( ! empty( $title ) ) {
				$schema['name'] = $title;
			}
		}

		if ( empty( $enabled_fields ) || in_array( 'description', $enabled_fields ) ) {
			$excerpt = get_the_excerpt( $post->ID );
			if ( ! empty( $excerpt ) ) {
				$schema['description'] = $excerpt;
			}
		}

		if ( empty( $enabled_fields ) || in_array( 'image', $enabled_fields ) ) {
			$image_url = get_the_post_thumbnail_url( $post->ID, 'full' );
			if ( $image_url ) {
				$schema['image'] = $image_url;
			}
		}

		// Process mapped fields only if they are enabled
		foreach ( $field_map as $schema_key => $mapping_source ) {
			// Skip if field is not enabled and enabled_fields array exists
			if ( ! empty( $enabled_fields ) && ! in_array( $schema_key, $enabled_fields ) ) {
				continue;
			}

			$value = $this->resolve_field_value( $mapping_source, $post );

			if ( ! empty( $value ) ) {
				$schema = $this->process_medical_field( $schema, $schema_key, $value );
			}
		}

		return array_filter( $schema );
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

		// Handle the mapping format (e.g., "meta:condition_symptoms", "post:post_title").
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

			default:
				// For other post fields or meta fields.
				if ( isset( $post->$field_name ) ) {
					return $post->$field_name;
				}
				return get_post_meta( $post->ID, $field_name, true );
		}
	}

	/**
	 * Process medical-specific field values.
	 *
	 * @since 2.1.0
	 *
	 * @param array  $schema     Schema data.
	 * @param string $schema_key Schema field key.
	 * @param mixed  $value      Field value.
	 * @return array Modified schema data.
	 */
	private function process_medical_field( $schema, $schema_key, $value ) {
		// If value is a string with newlines, split into array.
		if ( is_string( $value ) && strpos( $value, "\n" ) !== false ) {
			$lines = array_filter( array_map( 'trim', explode( "\n", $value ) ) );
		} else {
			$lines = (array) $value;
		}

		switch ( $schema_key ) {
			case 'signOrSymptom':
				$schema['signOrSymptom'] = array_map(
					function( $item ) {
						return array(
							'@type' => 'MedicalSignOrSymptom',
							'name'  => $item,
						);
					},
					$lines
				);
				break;

			case 'possibleTreatment':
				$schema['possibleTreatment'] = array_map(
					function( $item ) {
						return array(
							'@type' => 'MedicalTherapy',
							'name'  => $item,
						);
					},
					$lines
				);
				break;

			case 'riskFactor':
				$schema['riskFactor'] = array_map(
					function( $item ) {
						return array(
							'@type' => 'MedicalRiskFactor',
							'name'  => $item,
						);
					},
					$lines
				);
				break;

			case 'primaryPrevention':
				$schema['primaryPrevention'] = array_map(
					function( $item ) {
						return array(
							'@type' => 'MedicalTherapy',
							'name'  => $item,
						);
					},
					$lines
				);
				break;

			case 'possibleComplication':
			case 'pathophysiology':
			case 'epidemiology':
				// For these fields, use single value if only one line, or array if multiple.
				$schema[ $schema_key ] = count( $lines ) === 1 ? $lines[0] : $lines;
				break;

			default:
				$schema[ $schema_key ] = count( $lines ) === 1 ? $lines[0] : $lines;
				break;
		}

		return $schema;
	}
}

new SRK_MedicalCondition_Schema_Generator();