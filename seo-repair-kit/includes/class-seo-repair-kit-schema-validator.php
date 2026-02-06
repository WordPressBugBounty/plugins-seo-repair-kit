<?php
/**
 * Schema Validator for SEO Repair Kit
 *
 * Validates schema.org structured data before saving to prevent invalid schemas
 * from being output to the frontend, which could lead to Google penalties.
 *
 * @package     SEO_Repair_Kit
 * @subpackage  Schema
 * @since       2.1.0
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Schema Validator Class
 *
 * Provides comprehensive validation for schema.org structured data including:
 * - Required field validation
 * - Data type validation
 * - Format validation (URLs, dates, emails, etc.)
 * - Schema.org structure compliance
 *
 * @since 2.1.0
 */
class SeoRepairKit_SchemaValidator {

	/**
	 * Validation errors
	 *
	 * @var array
	 */
	private $errors = array();

	/**
	 * Validation warnings
	 *
	 * @var array
	 */
	private $warnings = array();

	/**
	 * Required fields for each schema type
	 *
	 * Based on schema.org specifications and Google's requirements
	 *
	 * @var array
	 */
	private $required_fields = array(
		'article'          => array( 'headline', 'author', 'publisher' ),
		'blog_posting'     => array( 'headline', 'author', 'publisher' ),
		'news_article'     => array( 'headline', 'author', 'publisher' ),
		'product'          => array( 'name', 'offers' ),
		// Event requires: name, startDate, location (Google Rich Results).
		'event'            => array( 'name', 'startDate', 'location' ),
		'organization'     => array( 'name' ),
		'person'           => array( 'name' ), // ✅ Added for author schema (Person type)
		'local_business'   => array( 'name', 'address' ),
		'corporation'      => array( 'name' ),
		'website'          => array( 'name', 'url' ),
		'faq'              => array( 'mainEntity' ),
		// Align JobPosting with Google's required fields: title, description, datePosted,
		// hiringOrganization, jobLocation.
		'job_posting'      => array( 'title', 'description', 'datePosted', 'hiringOrganization', 'jobLocation' ),
		'course'           => array( 'name', 'provider' ),
		'review'           => array( 'itemReviewed', 'reviewRating', 'author' ),
		'recipe'           => array( 'name' ),
		'medical_condition'=> array( 'name' ),
	);

	/**
	 * Recommended fields for each schema type
	 *
	 * @var array
	 */
	private $recommended_fields = array(
		'article'          => array( 'image', 'datePublished', 'dateModified' ),
		'blog_posting'     => array( 'image', 'datePublished', 'dateModified' ),
		'news_article'     => array( 'image', 'datePublished', 'dateModified' ),
		'product'          => array( 'description', 'image', 'brand', 'sku' ),
		// Event recommended: description, endDate, organizer, performer, offers, eventStatus, image.
		'event'            => array( 'description', 'endDate', 'organizer', 'performer', 'offers', 'eventStatus', 'image' ),
		'organization'     => array( 'url', 'logo', 'sameAs' ),
		'local_business'   => array( 'telephone', 'openingHours', 'priceRange' ),
		'website'          => array( 'description', 'potentialAction' ),
		'faq'              => array(),
		// For JobPosting, treat validThrough, employmentType, and baseSalary as recommended.
		'job_posting'      => array( 'validThrough', 'employmentType', 'baseSalary' ),
		'course'           => array( 'description', 'provider' ),
		'review'           => array( 'reviewBody', 'datePublished' ),
		'recipe'           => array( 'description', 'image', 'recipeIngredient', 'recipeInstructions' ),
	);

	/**
	 * Field type definitions
	 *
	 * @var array
	 */
	private $field_types = array(
		'url'     => array( 'url', 'image', 'logo', 'sameAs', 'author', 'publisher' ),
		'date'    => array( 'datePublished', 'dateModified', 'startDate', 'endDate', 'datePosted', 'validThrough' ),
		'email'   => array( 'email' ),
		'phone'   => array( 'telephone' ),
		'number'  => array( 'ratingValue', 'reviewCount', 'bestRating', 'worstRating', 'price', 'baseSalary' ),
		'text'    => array( 'name', 'headline', 'description', 'title', 'reviewBody' ),
	);

	/**
	 * Validate schema configuration
	 *
	 * @since 2.1.0
	 *
	 * @param string $schema_type Schema type key (e.g., 'article', 'product').
	 * @param array  $meta_map    Field mappings.
	 * @param array  $enabled_fields Enabled fields array.
	 * @param array  $schema_data Optional. Pre-built schema data for validation.
	 * @return bool True if valid, false otherwise.
	 */
	public function validate( $schema_type, $meta_map = array(), $enabled_fields = array(), $schema_data = null ) {
		$this->errors   = array();
		$this->warnings = array();

		if ( empty( $schema_type ) ) {
			$this->errors[] = array(
				'field'   => 'schema_type',
				'message' => __( 'Schema type is required.', 'seo-repair-kit' ),
			);
			return false;
		}

		// Normalize schema type
		$schema_type = strtolower( $schema_type );

		// Validate required fields
		$this->validate_required_fields( $schema_type, $meta_map, $enabled_fields, $schema_data );

		// Validate field types and formats
		$this->validate_field_types( $schema_type, $meta_map, $enabled_fields );

		// Validate schema structure if schema data provided
		if ( ! empty( $schema_data ) && is_array( $schema_data ) ) {
			$this->validate_schema_structure( $schema_type, $schema_data );
		}

		// Check for recommended fields
		$this->check_recommended_fields( $schema_type, $meta_map, $enabled_fields );

		return empty( $this->errors );
	}

	/**
	 * Validate required fields
	 *
	 * @since 2.1.0
	 *
	 * @param string $schema_type    Schema type.
	 * @param array  $meta_map       Field mappings.
	 * @param array  $enabled_fields Enabled fields.
	 * @return void
	 */
	private function validate_required_fields( $schema_type, $meta_map, $enabled_fields, $schema_data = null ) {
		// Special handling for FAQ schema
		if ( 'faq' === $schema_type ) {
			// FAQ validation is handled separately in FAQ manager
			return;
		}

		// Get required fields for this schema type
		$required = isset( $this->required_fields[ $schema_type ] ) ? $this->required_fields[ $schema_type ] : array();

		if ( empty( $required ) ) {
			return;
		}

		// Check each required field
		foreach ( $required as $field ) {
			// ✅ Special handling for address field - check sub-fields
			if ( 'address' === $field ) {
				$address_sub_fields = array( 'streetAddress', 'addressLocality', 'addressRegion', 'postalCode', 'addressCountry' );
				$has_address_data = false;
				
				// First, check if address exists in schema_data (preview/actual output)
				if ( ! empty( $schema_data ) && is_array( $schema_data ) && isset( $schema_data['address'] ) ) {
					$address_obj = $schema_data['address'];
					if ( is_array( $address_obj ) && ! empty( $address_obj ) ) {
						// Check if address has at least one property (not just @type)
						$address_props = array_filter( $address_obj, function( $key ) {
							return '@type' !== $key;
						}, ARRAY_FILTER_USE_KEY );
						if ( ! empty( $address_props ) ) {
							continue; // Address exists in schema output, it's valid
						}
					}
				}
				
				// Check if any address sub-field has a value in meta_map
				foreach ( $address_sub_fields as $sub_field ) {
					if ( isset( $meta_map[ $sub_field ] ) && ! empty( $meta_map[ $sub_field ] ) ) {
						// Remove 'custom:' prefix if present to check actual value
						$sub_value = str_replace( 'custom:', '', $meta_map[ $sub_field ] );
						if ( ! empty( trim( $sub_value ) ) ) {
							$has_address_data = true;
							break;
						}
					}
				}
				
				// Also check if address field itself is enabled and has value (for backward compatibility)
				$is_address_enabled = in_array( 'address', $enabled_fields, true ) || empty( $enabled_fields );
				$has_address_value = isset( $meta_map['address'] ) && ! empty( $meta_map['address'] );
				
				// Address is valid if either sub-fields have data OR address field itself has data
				if ( $has_address_data || ( $is_address_enabled && $has_address_value ) ) {
					continue; // Address is valid, skip error
				}
				
				// Address is missing
				$field_label = $this->get_field_label( $field );
				$suggestion  = $this->get_field_suggestion( $field, $schema_type );
				$example     = $this->get_field_example( $field, $schema_type );
				
				$this->errors[] = array(
					'field'      => $field,
					'field_label' => $field_label,
					'message'    => sprintf(
						/* translators: %s: Field label */
						__( 'Required field "%s" is missing or not enabled.', 'seo-repair-kit' ),
						$field_label
					),
					'explanation' => sprintf(
						/* translators: %s: Field label */
						__( 'The "%s" field is required by Schema.org for this schema type. Without it, Google may not display your content as a rich result.', 'seo-repair-kit' ),
						$field_label
					),
					'suggestion' => $suggestion,
					'example'    => $example,
					'type'       => 'required_missing',
				);
				continue;
			}
			
			// Check if field is enabled and has a value
			$is_enabled = in_array( $field, $enabled_fields, true ) || empty( $enabled_fields );
			$has_value  = isset( $meta_map[ $field ] ) && ! empty( $meta_map[ $field ] );

			if ( ! $is_enabled || ! $has_value ) {
				$field_label = $this->get_field_label( $field );
				$suggestion  = $this->get_field_suggestion( $field, $schema_type );
				$example     = $this->get_field_example( $field, $schema_type );
				
				$this->errors[] = array(
					'field'      => $field,
					'field_label' => $field_label,
					'message'    => sprintf(
						/* translators: %s: Field label */
						__( 'Required field "%s" is missing or not enabled.', 'seo-repair-kit' ),
						$field_label
					),
					'explanation' => sprintf(
						/* translators: %s: Field label */
						__( 'The "%s" field is required by Schema.org for this schema type. Without it, Google may not display your content as a rich result.', 'seo-repair-kit' ),
						$field_label
					),
					'suggestion' => $suggestion,
					'example'    => $example,
					'type'       => 'required_missing',
				);
			}
		}
	}

	/**
	 * Validate field types and formats
	 *
	 * @since 2.1.0
	 *
	 * @param string $schema_type    Schema type.
	 * @param array  $meta_map       Field mappings.
	 * @param array  $enabled_fields Enabled fields.
	 * @return void
	 */
	private function validate_field_types( $schema_type, $meta_map, $enabled_fields ) {
		foreach ( $meta_map as $field => $value ) {
			if ( empty( $value ) ) {
				continue;
			}

			// Skip validation if field is not enabled (unless enabled_fields is empty, meaning all are enabled)
			if ( ! empty( $enabled_fields ) && ! in_array( $field, $enabled_fields, true ) ) {
				continue;
			}

			// Validate URL fields
			if ( $this->is_url_field( $field ) ) {
				// For mapped values, we can't validate the actual URL until it's resolved
				// But we can validate the mapping format
				if ( strpos( $value, 'http://' ) === 0 || strpos( $value, 'https://' ) === 0 ) {
					if ( ! filter_var( $value, FILTER_VALIDATE_URL ) ) {
						$field_label = $this->get_field_label( $field );
						$this->errors[] = array(
							'field'        => $field,
							'field_label' => $field_label,
							'message'      => sprintf(
								/* translators: %s: Field name */
								__( 'Field "%s" must be a valid URL.', 'seo-repair-kit' ),
								$field_label
							),
							'explanation'  => sprintf(
								/* translators: %s: Field name */
								__( 'The value "%s" is not a valid URL format. URLs must start with http:// or https:// and be properly formatted.', 'seo-repair-kit' ),
								esc_html( $value )
							),
							'suggestion'   => __( 'Please enter a valid URL starting with http:// or https://. For example: https://example.com/image.jpg', 'seo-repair-kit' ),
							'example'      => 'https://example.com/image.jpg',
							'type'         => 'invalid_url',
						);
					}
				}
			}

			// Validate date fields
			if ( $this->is_date_field( $field ) ) {
				// Check if it's a valid date format (ISO 8601 or common formats)
				if ( strpos( $value, ':' ) === false ) {
					// Not a mapping, validate as direct date value
					$timestamp = strtotime( $value );
					if ( false === $timestamp ) {
						$field_label = $this->get_field_label( $field );
						$this->errors[] = array(
							'field'        => $field,
							'field_label' => $field_label,
							'message'      => sprintf(
								/* translators: %s: Field name */
								__( 'Field "%s" must be a valid date.', 'seo-repair-kit' ),
								$field_label
							),
							'explanation'  => sprintf(
								/* translators: %1$s: Field name, %2$s: Invalid value */
								__( 'The value "%2$s" for "%1$s" is not recognized as a valid date format. Dates should be in ISO 8601 format (YYYY-MM-DD) or a standard date format.', 'seo-repair-kit' ),
								$field_label,
								esc_html( $value )
							),
							'suggestion'   => __( 'Please use ISO 8601 format (YYYY-MM-DD) or a standard date format like "January 1, 2024". For dates with time, use ISO 8601: 2024-01-01T10:00:00+00:00', 'seo-repair-kit' ),
							'example'      => '2024-01-01',
							'type'         => 'invalid_date',
						);
					}
				}
			}

			// Validate number fields
			if ( $this->is_number_field( $field ) ) {
				if ( strpos( $value, ':' ) === false ) {
					// Not a mapping, validate as direct number value
					if ( ! is_numeric( $value ) ) {
						$field_label = $this->get_field_label( $field );
						$this->errors[] = array(
							'field'        => $field,
							'field_label' => $field_label,
							'message'      => sprintf(
								/* translators: %s: Field name */
								__( 'Field "%s" must be a valid number.', 'seo-repair-kit' ),
								$field_label
							),
							'explanation'  => sprintf(
								/* translators: %1$s: Field name, %2$s: Invalid value */
								__( 'The value "%2$s" for "%1$s" is not a valid number. This field requires a numeric value.', 'seo-repair-kit' ),
								$field_label,
								esc_html( $value )
							),
							'suggestion'   => __( 'Please enter a numeric value. For ratings, use numbers between 0 and 5. For counts, use whole numbers (integers).', 'seo-repair-kit' ),
							'example'      => $this->get_field_example( $field, $schema_type ),
							'type'         => 'invalid_number',
						);
					} else {
						// Validate number ranges for ratings
						if ( 'ratingValue' === $field || 'bestRating' === $field || 'worstRating' === $field ) {
							$num_value = floatval( $value );
							if ( $num_value < 0 || $num_value > 5 ) {
								$this->warnings[] = array(
									'field'   => $field,
									'message' => sprintf(
										/* translators: %s: Field name */
										__( 'Field "%s" is typically between 0 and 5 for ratings.', 'seo-repair-kit' ),
										$this->get_field_label( $field )
									),
								);
							}
						}
					}
				}
			}

			// Validate email fields
			if ( $this->is_email_field( $field ) ) {
				if ( strpos( $value, ':' ) === false ) {
					if ( ! is_email( $value ) ) {
						$this->errors[] = array(
							'field'   => $field,
							'message' => sprintf(
								/* translators: %s: Field name */
								__( 'Field "%s" must be a valid email address.', 'seo-repair-kit' ),
								$this->get_field_label( $field )
							),
						);
					}
				}
			}
		}
	}

	/**
	 * Validate schema structure
	 *
	 * @since 2.1.0
	 *
	 * @param string $schema_type Schema type.
	 * @param array  $schema_data Schema data array.
	 * @return void
	 */
	private function validate_schema_structure( $schema_type, $schema_data ) {
		// Validate @context
		if ( ! isset( $schema_data['@context'] ) || 'https://schema.org' !== $schema_data['@context'] ) {
			$this->errors[] = array(
				'field'   => '@context',
				'message' => __( 'Schema must include @context set to "https://schema.org".', 'seo-repair-kit' ),
			);
		}

		// Validate @type
		if ( ! isset( $schema_data['@type'] ) || empty( $schema_data['@type'] ) ) {
			$this->errors[] = array(
				'field'   => '@type',
				'message' => __( 'Schema must include @type.', 'seo-repair-kit' ),
			);
		}

		// Validate Product offers structure
		if ( 'product' === $schema_type && isset( $schema_data['offers'] ) ) {
			if ( ! is_array( $schema_data['offers'] ) ) {
				$this->errors[] = array(
					'field'   => 'offers',
					'message' => __( 'Product offers must be an object with @type "Offer".', 'seo-repair-kit' ),
				);
			} elseif ( ! isset( $schema_data['offers']['@type'] ) || 'Offer' !== $schema_data['offers']['@type'] ) {
				$this->errors[] = array(
					'field'   => 'offers',
					'message' => __( 'Product offers must include @type "Offer".', 'seo-repair-kit' ),
				);
			}
		}

		// Validate FAQ mainEntity structure
		if ( 'faq' === $schema_type && isset( $schema_data['mainEntity'] ) ) {
			if ( ! is_array( $schema_data['mainEntity'] ) || empty( $schema_data['mainEntity'] ) ) {
				$this->errors[] = array(
					'field'   => 'mainEntity',
					'message' => __( 'FAQ schema must include at least one item in mainEntity.', 'seo-repair-kit' ),
				);
			}
		}
	}

	/**
	 * Check recommended fields
	 *
	 * @since 2.1.0
	 *
	 * @param string $schema_type    Schema type.
	 * @param array  $meta_map       Field mappings.
	 * @param array  $enabled_fields Enabled fields.
	 * @return void
	 */
	private function check_recommended_fields( $schema_type, $meta_map, $enabled_fields ) {
		$recommended = isset( $this->recommended_fields[ $schema_type ] ) ? $this->recommended_fields[ $schema_type ] : array();

		if ( empty( $recommended ) ) {
			return;
		}

		foreach ( $recommended as $field ) {
			$is_enabled = in_array( $field, $enabled_fields, true ) || empty( $enabled_fields );
			$has_value  = isset( $meta_map[ $field ] ) && ! empty( $meta_map[ $field ] );

			if ( ! $is_enabled || ! $has_value ) {
				$field_label = $this->get_field_label( $field );
				$this->warnings[] = array(
					'field'   => $field,
					'message' => sprintf(
						/* translators: %s: Field label */
						__( 'Recommended field "%s" is missing. This may affect rich results appearance.', 'seo-repair-kit' ),
						$field_label
					),
				);
			}
		}
	}

	/**
	 * Check if field is a URL field
	 *
	 * @since 2.1.0
	 *
	 * @param string $field Field name.
	 * @return bool
	 */
	private function is_url_field( $field ) {
		return in_array( $field, $this->field_types['url'], true );
	}

	/**
	 * Check if field is a date field
	 *
	 * @since 2.1.0
	 *
	 * @param string $field Field name.
	 * @return bool
	 */
	private function is_date_field( $field ) {
		return in_array( $field, $this->field_types['date'], true );
	}

	/**
	 * Check if field is a number field
	 *
	 * @since 2.1.0
	 *
	 * @param string $field Field name.
	 * @return bool
	 */
	private function is_number_field( $field ) {
		return in_array( $field, $this->field_types['number'], true );
	}

	/**
	 * Check if field is an email field
	 *
	 * @since 2.1.0
	 *
	 * @param string $field Field name.
	 * @return bool
	 */
	private function is_email_field( $field ) {
		return in_array( $field, $this->field_types['email'], true );
	}

	/**
	 * Get human-readable field label
	 *
	 * @since 2.1.0
	 *
	 * @param string $field Field name.
	 * @return string Field label.
	 */
	private function get_field_label( $field ) {
		$labels = array(
			'name'           => __( 'Name', 'seo-repair-kit' ),
			'headline'       => __( 'Headline', 'seo-repair-kit' ),
			'description'    => __( 'Description', 'seo-repair-kit' ),
			'author'         => __( 'Author', 'seo-repair-kit' ),
			'publisher'      => __( 'Publisher', 'seo-repair-kit' ),
			'image'          => __( 'Image', 'seo-repair-kit' ),
			'datePublished'  => __( 'Date Published', 'seo-repair-kit' ),
			'dateModified'   => __( 'Date Modified', 'seo-repair-kit' ),
			'offers'         => __( 'Offers', 'seo-repair-kit' ),
			'startDate'     => __( 'Start Date', 'seo-repair-kit' ),
			'endDate'       => __( 'End Date', 'seo-repair-kit' ),
			'address'       => __( 'Address', 'seo-repair-kit' ),
			'url'           => __( 'URL', 'seo-repair-kit' ),
			'mainEntity'    => __( 'Main Entity', 'seo-repair-kit' ),
			'title'         => __( 'Title', 'seo-repair-kit' ),
			'datePosted'    => __( 'Date Posted', 'seo-repair-kit' ),
			'validThrough'  => __( 'Valid Through', 'seo-repair-kit' ),
			'provider'       => __( 'Provider', 'seo-repair-kit' ),
			'itemReviewed'  => __( 'Item Reviewed', 'seo-repair-kit' ),
			'reviewRating'  => __( 'Review Rating', 'seo-repair-kit' ),
			'ratingValue'   => __( 'Rating Value', 'seo-repair-kit' ),
			'reviewCount'   => __( 'Review Count', 'seo-repair-kit' ),
			'bestRating'    => __( 'Best Rating', 'seo-repair-kit' ),
			'worstRating'   => __( 'Worst Rating', 'seo-repair-kit' ),
		);

		return isset( $labels[ $field ] ) ? $labels[ $field ] : ucwords( str_replace( '_', ' ', $field ) );
	}

	/**
	 * Get field-specific suggestion for fixing errors
	 *
	 * @since 2.1.0
	 *
	 * @param string $field       Field name.
	 * @param string $schema_type Schema type.
	 * @return string Suggestion text.
	 */
	private function get_field_suggestion( $field, $schema_type ) {
		$suggestions = array(
			'name'           => __( 'Enable this field and map it to your post title or a custom field containing the name.', 'seo-repair-kit' ),
			'headline'       => __( 'Enable this field and map it to your post title. This is the main headline of your article.', 'seo-repair-kit' ),
			'author'         => __( 'Enable this field and map it to post author or a custom field. You can use "Post Default > Post Author" for automatic mapping.', 'seo-repair-kit' ),
			'publisher'      => __( 'Enable this field and map it to your site name or organization. You can use "Site Information > Site Name" for automatic mapping.', 'seo-repair-kit' ),
			'offers'         => __( 'For Product schemas, you need to provide pricing information. Map the price field or use WooCommerce product price if available.', 'seo-repair-kit' ),
			'startDate'      => __( 'Enable this field and map it to a date field. Use "Post Default > Post Date" for publication date or a custom date field.', 'seo-repair-kit' ),
			'address'        => __( 'Enable this field and provide your business address. You can use custom fields for street address, city, postal code, etc.', 'seo-repair-kit' ),
			'url'            => __( 'Enable this field and map it to post URL or a custom URL field. You can use "Post Default > Post URL" for automatic mapping.', 'seo-repair-kit' ),
			'mainEntity'     => __( 'Add at least one FAQ item with both a question and answer. Click "Add FAQ Item" to create FAQ entries.', 'seo-repair-kit' ),
			'ratingValue'    => __( 'Enable this field and provide a numeric rating value (typically between 0 and 5).', 'seo-repair-kit' ),
			'reviewCount'    => __( 'Enable this field and provide the total number of reviews as a whole number.', 'seo-repair-kit' ),
			'title'          => __( 'Enable this field and map it to your post title or job title field.', 'seo-repair-kit' ),
			'datePosted'     => __( 'Enable this field and map it to post date or a custom date field for when the job was posted.', 'seo-repair-kit' ),
			'validThrough'   => __( 'Enable this field and provide the date when the job posting expires.', 'seo-repair-kit' ),
			'provider'       => __( 'Enable this field and provide the name of the course provider or educational institution.', 'seo-repair-kit' ),
		);

		return isset( $suggestions[ $field ] ) ? $suggestions[ $field ] : __( 'Please enable this field and provide a valid value.', 'seo-repair-kit' );
	}

	/**
	 * Get field-specific example value
	 *
	 * @since 2.1.0
	 *
	 * @param string $field       Field name.
	 * @param string $schema_type Schema type.
	 * @return string Example value.
	 */
	private function get_field_example( $field, $schema_type ) {
		$examples = array(
			'name'           => 'Example Product Name',
			'headline'       => 'Example Article Headline',
			'author'         => 'John Doe',
			'publisher'      => 'Your Site Name',
			'offers'         => '29.99',
			'startDate'      => '2024-01-01',
			'address'        => '123 Main St, City, State 12345',
			'url'            => 'https://example.com/page',
			'ratingValue'    => '4.5',
			'reviewCount'    => '150',
			'title'          => 'Software Engineer',
			'datePosted'     => '2024-01-01',
			'validThrough'   => '2024-12-31',
			'provider'       => 'Example University',
			'email'          => 'contact@example.com',
			'telephone'      => '+1-555-123-4567',
		);

		return isset( $examples[ $field ] ) ? $examples[ $field ] : '';
	}

	/**
	 * Get required fields for a schema type
	 *
	 * @since 2.1.0
	 *
	 * @param string $schema_type Schema type.
	 * @return array Required fields.
	 */
	public static function get_required_fields( $schema_type ) {
		$instance = new self();
		$schema_type = strtolower( $schema_type );
		
		// Special handling for FAQ
		if ( 'faq' === $schema_type ) {
			return array( 'mainEntity' );
		}
		
		return isset( $instance->required_fields[ $schema_type ] ) ? $instance->required_fields[ $schema_type ] : array();
	}

	/**
	 * Get validation errors
	 *
	 * @since 2.1.0
	 *
	 * @return array Validation errors.
	 */
	public function get_errors() {
		return $this->errors;
	}

	/**
	 * Get validation warnings
	 *
	 * @since 2.1.0
	 *
	 * @return array Validation warnings.
	 */
	public function get_warnings() {
		return $this->warnings;
	}

	/**
	 * Check if validation has errors
	 *
	 * @since 2.1.0
	 *
	 * @return bool True if has errors.
	 */
	public function has_errors() {
		return ! empty( $this->errors );
	}

	/**
	 * Check if validation has warnings
	 *
	 * @since 2.1.0
	 *
	 * @return bool True if has warnings.
	 */
	public function has_warnings() {
		return ! empty( $this->warnings );
	}

	/**
	 * Get formatted error messages
	 *
	 * @since 2.1.0
	 *
	 * @return string Formatted error messages.
	 */
	public function get_formatted_errors() {
		if ( empty( $this->errors ) ) {
			return '';
		}

		$messages = array();
		foreach ( $this->errors as $error ) {
			$messages[] = $error['message'];
		}

		return implode( "\n", $messages );
	}

	/**
	 * Get formatted warning messages
	 *
	 * @since 2.1.0
	 *
	 * @return string Formatted warning messages.
	 */
	public function get_formatted_warnings() {
		if ( empty( $this->warnings ) ) {
			return '';
		}

		$messages = array();
		foreach ( $this->warnings as $warning ) {
			$messages[] = $warning['message'];
		}

		return implode( "\n", $messages );
	}

	/**
	 * Validate complete schema array before frontend output.
	 *
	 * This method validates the final schema structure that will be output to the frontend,
	 * ensuring all required fields are present and valid. Returns false if schema is invalid
	 * and should not be output.
	 *
	 * @since 2.1.0
	 *
	 * @param array  $schema      Complete schema array (JSON-LD structure).
	 * @param string $schema_type Schema type key (e.g., 'product', 'article').
	 * @return bool True if schema is valid and can be output, false otherwise.
	 */
	public function validate_schema_output( $schema, $schema_type ) {
		$this->errors   = array();
		$this->warnings = array();

		if ( empty( $schema ) || ! is_array( $schema ) ) {
			return false;
		}

		if ( empty( $schema_type ) ) {
			// Try to infer from @type
			if ( isset( $schema['@type'] ) ) {
				$schema_type = strtolower( $schema['@type'] );
			} else {
				return false;
			}
		}

		// Normalize schema type
		$schema_type = strtolower( $schema_type );

		// ✅ Special handling for author schema - use @type from schema instead
		// Author schema can be Person or Organization, so we need to use the actual @type
		if ( 'author' === $schema_type && isset( $schema['@type'] ) ) {
			$schema_type = strtolower( $schema['@type'] );
		}

		// Get required fields for this schema type
		$required = isset( $this->required_fields[ $schema_type ] ) ? $this->required_fields[ $schema_type ] : array();

		// Special handling for FAQ schema
		if ( 'faq' === $schema_type || 'faqpage' === $schema_type ) {
			if ( ! isset( $schema['mainEntity'] ) || empty( $schema['mainEntity'] ) || ! is_array( $schema['mainEntity'] ) ) {
				return false;
			}
			// FAQ is valid if mainEntity exists and has at least one item
			return count( $schema['mainEntity'] ) > 0;
		}

		// Check each required field
		foreach ( $required as $field ) {
			$has_value = false;

			// Check if field exists and has a non-empty value
			if ( isset( $schema[ $field ] ) ) {
				$value = $schema[ $field ];
				// Check for empty strings, null, empty arrays
				if ( is_array( $value ) ) {
					$has_value = ! empty( $value );
				} else {
					$has_value = ! empty( $value ) && '' !== trim( (string) $value );
				}
			}

			// Special handling for nested objects (e.g., offers, address)
			if ( ! $has_value && 'offers' === $field && isset( $schema['offers'] ) ) {
				// For Product schema, offers can be an object or array
				$offers = $schema['offers'];
				if ( is_array( $offers ) && ! empty( $offers ) ) {
					$has_value = true;
				} elseif ( is_object( $offers ) ) {
					$has_value = true;
				}
			}

			// Special handling for address field (LocalBusiness)
			if ( ! $has_value && 'address' === $field && isset( $schema['address'] ) ) {
				// For LocalBusiness schema, address is a PostalAddress object
				$address = $schema['address'];
				if ( is_array( $address ) && ! empty( $address ) ) {
					// Check if address has at least one property (not just @type)
					$address_props = array_filter( $address, function( $key ) {
						return '@type' !== $key;
					}, ARRAY_FILTER_USE_KEY );
					$has_value = ! empty( $address_props );
				} elseif ( is_object( $address ) ) {
					$has_value = true;
				}
			}

			if ( ! $has_value ) {
				// Required field is missing - schema is invalid
				return false;
			}
		}

		// All required fields are present
		return true;
	}

	/**
	 * Check if a schema should be output to frontend.
	 *
	 * Convenience method that validates schema output and returns boolean.
	 *
	 * @since 2.1.0
	 *
	 * @param array  $schema      Complete schema array.
	 * @param string $schema_type Schema type key.
	 * @return bool True if schema should be output, false otherwise.
	 */
	public static function should_output_schema( $schema, $schema_type ) {
		if ( ! class_exists( 'SeoRepairKit_SchemaValidator' ) ) {
			// If validator class doesn't exist, allow output (fallback)
			return true;
		}

		$validator = new self();
		return $validator->validate_schema_output( $schema, $schema_type );
	}
}
