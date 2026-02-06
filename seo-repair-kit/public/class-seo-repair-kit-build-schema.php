<?php
/**
 * Schema Builder for SEO Repair Kit
 *
 * @package     SEO_Repair_Kit
 * @subpackage  Schema
 */

if ( ! class_exists( 'Seo_Repair_Kit_Build_Schema' ) ) {

	/**
	 * Builds structured data schema for various content types.
	 */
	class Seo_Repair_Kit_Build_Schema {

	/**
	 * Build schema for a specific post.
	 *
	 * @param string $schema_key Schema type key.
	 * @param object $post       Post object.
	 * @return array|null Schema array or null if invalid.
	 */
	public function build_schema( $schema_key, $post ) {
		// ✅ Check if license plan is expired - block schema building if expired
		if ( class_exists( 'SRK_License_Helper' ) && SRK_License_Helper::is_license_expired() ) {
			return null;
		}

		$option_key   = 'srk_schema_assignment_' . $schema_key;
			$settings_raw = get_option( $option_key );

			if ( empty( $settings_raw ) ) {
				return null;
			}

			$settings = maybe_unserialize( $settings_raw );

			if ( ! is_array( $settings ) || ! isset( $settings['post_type'], $settings['meta_map'] ) ) {
				return null;
			}

			if ( 'global' !== $settings['post_type'] && $settings['post_type'] !== get_post_type( $post ) ) {
				return null;
			}

		$meta_map    = $settings['meta_map'];
		$enabled_fields = isset( $settings['enabled_fields'] ) && is_array( $settings['enabled_fields'] ) ? $settings['enabled_fields'] : array();
		$schema_type = $this->resolve_schema_type( $schema_key );
		$schema      = array(
			'@context' => 'https://schema.org',
			'@type'    => $schema_type,
		);

		$schema = $this->add_common_properties( $schema, $schema_key, $post->ID );

		// ✅ Handle address field FIRST (before other fields) - check sub-fields directly
		// Address is a composite field, so we check sub-fields even if 'address' key doesn't exist
		// ✅ Only process address if it's enabled (or if enabled_fields is empty, process all)
		$process_address = empty( $enabled_fields ) || in_array( 'address', $enabled_fields, true );
		
		$streetAddress = '';
		$addressLocality = '';
		$addressRegion = '';
		$postalCode = '';
		$addressCountry = '';
		
		if ( $process_address ) {
			$streetAddress = isset( $meta_map['streetAddress'] ) ? trim( $this->get_field_value( $meta_map['streetAddress'], $post->ID ) ) : '';
			$addressLocality = isset( $meta_map['addressLocality'] ) ? trim( $this->get_field_value( $meta_map['addressLocality'], $post->ID ) ) : '';
			$addressRegion = isset( $meta_map['addressRegion'] ) ? trim( $this->get_field_value( $meta_map['addressRegion'], $post->ID ) ) : '';
			$postalCode = isset( $meta_map['postalCode'] ) ? trim( $this->get_field_value( $meta_map['postalCode'], $post->ID ) ) : '';
			$addressCountry = isset( $meta_map['addressCountry'] ) ? trim( $this->get_field_value( $meta_map['addressCountry'], $post->ID ) ) : '';
		}
		
		if ( $process_address && ( ! empty( $streetAddress ) || ! empty( $addressLocality ) || ! empty( $addressRegion ) || ! empty( $postalCode ) || ! empty( $addressCountry ) ) ) {
			$schema['address'] = array(
				'@type' => 'PostalAddress',
			);
			if ( ! empty( $streetAddress ) ) {
				$schema['address']['streetAddress'] = sanitize_text_field( $streetAddress );
			}
			if ( ! empty( $addressLocality ) ) {
				$schema['address']['addressLocality'] = sanitize_text_field( $addressLocality );
			}
			if ( ! empty( $addressRegion ) ) {
				$schema['address']['addressRegion'] = sanitize_text_field( $addressRegion );
			}
			if ( ! empty( $postalCode ) ) {
				$schema['address']['postalCode'] = sanitize_text_field( $postalCode );
			}
			if ( ! empty( $addressCountry ) ) {
				$schema['address']['addressCountry'] = array(
					'@type' => 'Country',
					'name'  => sanitize_text_field( $addressCountry ),
				);
			}
		}

		// ✅ Special handling for author schema - get authorType from settings
		$author_type = 'Person'; // Default
		if ( $schema_key === 'author' && isset( $settings['authorType'] ) ) {
			$author_type = $settings['authorType'];
		}

		$social_fields = array( 'facebook_url', 'twitter_url', 'instagram_url', 'youtube_url', 'linkedin_url' );
		$same_as       = array();

		foreach ( $meta_map as $schema_field => $mapped_field ) {
			// ✅ Skip address sub-fields FIRST - they are handled in the address object above
			// These should NEVER be processed in this loop
			$address_sub_fields = array( 'streetAddress', 'addressLocality', 'addressRegion', 'postalCode', 'addressCountry' );
			if ( in_array( $schema_field, $address_sub_fields, true ) ) {
				continue; // Always skip - handled in address object above
			}
			
			// ✅ Respect enabled_fields if set - only process enabled fields
			if ( ! empty( $enabled_fields ) && ! in_array( $schema_field, $enabled_fields, true ) ) {
				// Check if this is the 'address' field itself (not sub-fields, those are already skipped above)
				if ( $schema_field !== 'address' || ! in_array( 'address', $enabled_fields, true ) ) {
					continue;
				}
			}
			
			if ( empty( $mapped_field ) ) {
				continue;
			}

			// Skip address (parent field), image, logo, and social fields (handled separately)
			// Note: address sub-fields are already skipped above
			$skip_fields = array( 'address', 'image', 'logo' );
			if ( in_array( $schema_field, $skip_fields, true ) ) {
				continue;
			}

			// Handle social fields for sameAs
			if ( in_array( $schema_field, $social_fields, true ) ) {
				$raw_value = $mapped_field;
				$url = '';
				
				if ( filter_var( $raw_value, FILTER_VALIDATE_URL ) ) {
					$url = $raw_value;
				} else {
					$url = $this->get_field_value( $raw_value, $post->ID );
				}
				
				if ( ! empty( $url ) && filter_var( $url, FILTER_VALIDATE_URL ) ) {
					$same_as[] = esc_url_raw( $url );
				}
				continue;
			}

			// ✅ Special handling for author schema - skip incompatible fields
			if ( $schema_key === 'author' ) {
				// Skip Person-specific fields for Organization and vice versa
				if ( $author_type === 'Organization' && in_array( $schema_field, array( 'givenName', 'familyName', 'additionalName', 'honorificPrefix', 'honorificSuffix', 'jobTitle', 'worksFor' ), true ) ) {
					continue;
				}
				if ( $author_type === 'Person' && in_array( $schema_field, array( 'contactPoint' ), true ) ) {
					// ContactPoint is handled separately for Organization
					continue;
				}
			}

			$value = $this->get_field_value( $mapped_field, $post->ID );
			if ( empty( $value ) ) {
				continue;
			}

			switch ( $schema_field ) {
				case 'author':
					$schema['author'] = $this->build_author( $mapped_field, $post->ID );
					break;

				case 'publisher':
					$publisher_name = $value;
					if ( ! empty( $publisher_name ) ) {
						$schema['publisher'] = array(
							'@type' => 'Organization',
							'name'  => $publisher_name,
							'logo'  => $this->get_site_logo(),
						);
					}
					break;

				case 'image':
					// Skip - handled separately below
					break;

				case 'logo':
					// Skip - handled separately below
					break;

				case 'url':
					// Handle URL field
					$value = trim( $value );
					if ( filter_var( $value, FILTER_VALIDATE_URL ) ) {
						$schema['url'] = esc_url_raw( $value );
					}
					break;

				case 'contactPoint':
					$schema['contactPoint'] = array(
						'@type'       => 'ContactPoint',
						'telephone'   => sanitize_text_field( $value ),
						'contactType' => 'customer service',
					);
					break;

				case 'telephone':
					$schema['telephone'] = sanitize_text_field( $value );
					break;

				// Author schema specific fields
				case 'givenName':
				case 'familyName':
				case 'additionalName':
				case 'honorificPrefix':
				case 'honorificSuffix':
				case 'jobTitle':
					if ( $schema_key === 'author' && $author_type === 'Person' ) {
						$schema[ $schema_field ] = sanitize_text_field( $value );
					}
					break;

				case 'worksFor':
					if ( $schema_key === 'author' && $author_type === 'Person' ) {
						$schema['worksFor'] = array(
							'@type' => 'Organization',
							'name'  => sanitize_text_field( $value ),
						);
					}
					break;

				default:
					$schema[ $schema_field ] = sanitize_text_field( $value );
			}
		}

		// ✅ Handle sameAs for social fields
		if ( ! empty( $same_as ) ) {
			$schema['sameAs'] = array_values( array_unique( $same_as ) );
		}

		// ✅ Handle image field - different handling for author schema (Person vs Organization)
		// ✅ Only process image if it's enabled (or if enabled_fields is empty, process all)
		$process_image = empty( $enabled_fields ) || in_array( 'image', $enabled_fields, true );
		if ( $process_image && isset( $meta_map['image'] ) && ! empty( $meta_map['image'] ) ) {
			$image_result = $this->build_image( $meta_map['image'], $post->ID );
			if ( ! empty( $image_result ) ) {
				// For author schema with Person type, use simple URL string (per Schema.org example)
				if ( $schema_key === 'author' && $author_type === 'Person' && is_string( $image_result ) ) {
					$schema['image'] = $image_result;
				} elseif ( is_array( $image_result ) ) {
					$schema['image'] = $image_result;
				} else {
					$schema['image'] = esc_url_raw( $image_result );
				}
			}
		}

		// ✅ Handle logo field (URL string, not ImageObject)
		// ✅ Only process logo if it's enabled (or if enabled_fields is empty, process all)
		$process_logo = empty( $enabled_fields ) || in_array( 'logo', $enabled_fields, true );
		if ( $process_logo && isset( $meta_map['logo'] ) && ! empty( $meta_map['logo'] ) ) {
			$logo_mapping = $meta_map['logo'];
			$logo_url = '';
			
			if ( $logo_mapping === 'site_logo' ) {
				$logo_url = $this->get_site_logo();
				if ( empty( $logo_url ) ) {
					$custom_logo_id = get_theme_mod( 'custom_logo' );
					if ( $custom_logo_id ) {
						$logo_url = wp_get_attachment_url( $custom_logo_id );
					}
				}
			} else {
				$logo_url = $this->get_field_value( $logo_mapping, $post->ID );
			}
			
			if ( ! empty( $logo_url ) && filter_var( $logo_url, FILTER_VALIDATE_URL ) ) {
				$schema['logo'] = esc_url_raw( $logo_url );
			}
		}

		// ✅ Special handling for author schema - ensure @type is set correctly BEFORE validation
		if ( $schema_key === 'author' && isset( $settings['authorType'] ) ) {
			$schema['@type'] = $settings['authorType']; // Person or Organization
		}

		// ✅ Validate schema
		$validated_schema = $this->validate_schema( $schema, $schema_key );
		
		// ✅ Clean up any address sub-fields that might have been added directly (safety net)
		if ( $validated_schema && is_array( $validated_schema ) ) {
			$address_sub_fields = array( 'streetAddress', 'addressLocality', 'addressRegion', 'postalCode', 'addressCountry' );
			foreach ( $address_sub_fields as $sub_field ) {
				if ( isset( $validated_schema[ $sub_field ] ) ) {
					unset( $validated_schema[ $sub_field ] );
				}
			}
		}

		return $validated_schema;
		}

	/**
	 * Build global schema.
	 *
	 * @param string $schema_key Schema type key.
	 * @return array|null Schema array or null if invalid.
	 */
	public function build_global_schema( $schema_key ) {
		// ✅ Check if license plan is expired - block schema building if expired
		if ( class_exists( 'SRK_License_Helper' ) && SRK_License_Helper::is_license_expired() ) {
			return null;
		}

		$option_key   = 'srk_schema_assignment_' . $schema_key;
			$settings_raw = get_option( $option_key );

			if ( empty( $settings_raw ) ) {
				return null;
			}

			$settings = maybe_unserialize( $settings_raw );

			if ( ! is_array( $settings ) || ! isset( $settings['meta_map'] ) ) {
				return null;
			}

			$meta_map    = $settings['meta_map'];
			$enabled_fields = isset( $settings['enabled_fields'] ) && is_array( $settings['enabled_fields'] ) ? $settings['enabled_fields'] : array();
			$schema_type = $this->resolve_schema_type( $schema_key );
			$schema      = array(
				'@context' => 'https://schema.org',
				'@type'    => $schema_type,
			);

			$social_fields = array( 'facebook_url', 'twitter_url', 'instagram_url', 'youtube_url', 'linkedin_url' );
			$same_as       = array();

			foreach ( $social_fields as $social_field ) {
				if ( ! empty( $meta_map[ $social_field ] ) ) {
					$raw_value = $meta_map[ $social_field ];

					if ( filter_var( $raw_value, FILTER_VALIDATE_URL ) ) {
						$url = $raw_value;
					} else {
						$url = $this->get_field_value( $raw_value, 0 );
					}

					if ( ! empty( $url ) && filter_var( $url, FILTER_VALIDATE_URL ) ) {
						$same_as[] = esc_url_raw( $url );
					}
				}
			}

		// ✅ Handle address field FIRST (before other fields) - check sub-fields directly
		// Address is a composite field, so we check sub-fields even if 'address' key doesn't exist
		// ✅ Only process address if it's enabled (or if enabled_fields is empty, process all)
		$process_address = empty( $enabled_fields ) || in_array( 'address', $enabled_fields, true );
		
		$streetAddress = '';
		$addressLocality = '';
		$addressRegion = '';
		$postalCode = '';
		$addressCountry = '';
		
		if ( $process_address ) {
			$streetAddress = isset( $meta_map['streetAddress'] ) ? trim( $this->get_field_value( $meta_map['streetAddress'], 0 ) ) : '';
			$addressLocality = isset( $meta_map['addressLocality'] ) ? trim( $this->get_field_value( $meta_map['addressLocality'], 0 ) ) : '';
			$addressRegion = isset( $meta_map['addressRegion'] ) ? trim( $this->get_field_value( $meta_map['addressRegion'], 0 ) ) : '';
			$postalCode = isset( $meta_map['postalCode'] ) ? trim( $this->get_field_value( $meta_map['postalCode'], 0 ) ) : '';
			$addressCountry = isset( $meta_map['addressCountry'] ) ? trim( $this->get_field_value( $meta_map['addressCountry'], 0 ) ) : '';
		}
		
		if ( $process_address && ( ! empty( $streetAddress ) || ! empty( $addressLocality ) || ! empty( $addressRegion ) || ! empty( $postalCode ) || ! empty( $addressCountry ) ) ) {
			$schema['address'] = array(
				'@type' => 'PostalAddress',
			);
			if ( ! empty( $streetAddress ) ) {
				$schema['address']['streetAddress'] = sanitize_text_field( $streetAddress );
			}
			if ( ! empty( $addressLocality ) ) {
				$schema['address']['addressLocality'] = sanitize_text_field( $addressLocality );
			}
			if ( ! empty( $addressRegion ) ) {
				$schema['address']['addressRegion'] = sanitize_text_field( $addressRegion );
			}
			if ( ! empty( $postalCode ) ) {
				$schema['address']['postalCode'] = sanitize_text_field( $postalCode );
			}
			if ( ! empty( $addressCountry ) ) {
				$schema['address']['addressCountry'] = array(
					'@type' => 'Country',
					'name'  => sanitize_text_field( $addressCountry ),
				);
			}
		}

		// ✅ Special handling for author schema - get authorType from settings
		$author_type = 'Person'; // Default
		if ( $schema_key === 'author' && isset( $settings['authorType'] ) ) {
			$author_type = $settings['authorType'];
		}

		foreach ( $meta_map as $schema_field => $mapped_field ) {
			// ✅ Skip address sub-fields FIRST - they are handled in the address object above
			// These should NEVER be processed in this loop
			$address_sub_fields = array( 'streetAddress', 'addressLocality', 'addressRegion', 'postalCode', 'addressCountry' );
			if ( in_array( $schema_field, $address_sub_fields, true ) ) {
				continue; // Always skip - handled in address object above
			}
			
			// ✅ Respect enabled_fields if set - only process enabled fields
			if ( ! empty( $enabled_fields ) && ! in_array( $schema_field, $enabled_fields, true ) ) {
				// Check if this is the 'address' field itself (not sub-fields, those are already skipped above)
				if ( $schema_field !== 'address' || ! in_array( 'address', $enabled_fields, true ) ) {
					continue;
				}
			}
			
			// Skip address (parent field), image, logo, and social fields (handled separately)
			// Note: address sub-fields are already skipped above
			$skip_fields = array( 'address', 'image', 'logo' );
			if ( $schema_key === 'author' ) {
				// Skip Person-specific fields for Organization and vice versa
				if ( $author_type === 'Organization' && in_array( $schema_field, array( 'givenName', 'familyName', 'additionalName', 'honorificPrefix', 'honorificSuffix', 'jobTitle', 'worksFor' ), true ) ) {
					continue;
				}
				if ( $author_type === 'Person' && in_array( $schema_field, array( 'contactPoint' ), true ) ) {
					// ContactPoint is handled separately for Organization
					continue;
				}
			}
			
			if ( empty( $mapped_field ) || 
				 in_array( $schema_field, $social_fields, true ) ||
				 in_array( $schema_field, $skip_fields, true ) ) {
				continue;
			}

			$value = $this->get_field_value( $mapped_field, 0 );
			if ( empty( $value ) ) {
				continue;
			}

			switch ( $schema_field ) {
				case 'contactPoint':
					$schema['contactPoint'] = array(
						'@type'       => 'ContactPoint',
						'telephone'   => sanitize_text_field( $value ),
						'contactType' => 'customer service',
					);
					break;

				case 'telephone':
					// Handle telephone field for LocalBusiness
					$schema['telephone'] = sanitize_text_field( $value );
					break;

				case 'priceRange':
					// Handle priceRange field for LocalBusiness
					$schema['priceRange'] = sanitize_text_field( $value );
					break;

			case 'alternateName':
				// Handle alternateName field for LocalBusiness
				$schema['alternateName'] = sanitize_text_field( $value );
				break;

			case 'url':
				// Handle URL field for LocalBusiness
				$value = trim( $value );
				if ( filter_var( $value, FILTER_VALIDATE_URL ) ) {
					$schema['url'] = esc_url_raw( $value );
				}
				break;

			case 'ratingValue':
				// Handle rating value for aggregateRating (will be combined with reviewCount)
				$rating = floatval( trim( $value ) );
				if ( $rating > 0 && $rating <= 5 ) {
					if ( ! isset( $schema['aggregateRating'] ) ) {
						$schema['aggregateRating'] = array( '@type' => 'AggregateRating' );
					}
					$schema['aggregateRating']['ratingValue'] = (string) $rating;
				}
				break;

			case 'reviewCount':
				// Handle review count for aggregateRating
				$count = intval( trim( $value ) );
				if ( $count > 0 ) {
					if ( ! isset( $schema['aggregateRating'] ) ) {
						$schema['aggregateRating'] = array( '@type' => 'AggregateRating' );
					}
					$schema['aggregateRating']['reviewCount'] = (string) $count;
				}
				break;

			case 'openingHours':
				// Handle openingHours field for LocalBusiness
				// Schema.org accepts: string or array of strings
				$value = trim( $value );
				if ( is_string( $value ) && ! empty( $value ) ) {
					// Check if comma-separated
					if ( strpos( $value, ',' ) !== false ) {
						$hours_array = array_map( 'trim', explode( ',', $value ) );
						$hours_array = array_filter( $hours_array );
						if ( ! empty( $hours_array ) ) {
							$schema['openingHours'] = array_map( 'sanitize_text_field', $hours_array );
						}
					} else {
						// Single string value
						$schema['openingHours'] = sanitize_text_field( $value );
					}
				} elseif ( is_array( $value ) && ! empty( $value ) ) {
					$schema['openingHours'] = array_map( 'sanitize_text_field', $value );
				}
				break;

		case 'latitude':
			// Handle latitude for geo coordinates with validation
			$value = trim( (string) $value );
			// Clean value - remove invalid characters (keep digits, decimal, minus)
			$value = preg_replace( '/[^0-9.\-]/', '', $value );
			
			// Check for multiple decimal points (invalid format)
			if ( ! empty( $value ) && substr_count( $value, '.' ) <= 1 ) {
				$lat_value = floatval( $value );
				// Validate latitude range: -90 to 90 (0 is valid - equator)
				if ( $lat_value >= -90 && $lat_value <= 90 ) {
					if ( ! isset( $schema['geo'] ) ) {
						$schema['geo'] = array( '@type' => 'GeoCoordinates' );
					}
					$schema['geo']['latitude'] = $lat_value;
				}
			}
			break;

		case 'longitude':
			// Handle longitude for geo coordinates with validation
			$value = trim( (string) $value );
			// Clean value - remove invalid characters (keep digits, decimal, minus)
			$value = preg_replace( '/[^0-9.\-]/', '', $value );
			
			// Check for multiple decimal points (invalid format)
			if ( ! empty( $value ) && substr_count( $value, '.' ) <= 1 ) {
				$lon_value = floatval( $value );
				// Validate longitude range: -180 to 180 (0 is valid - prime meridian)
				if ( $lon_value >= -180 && $lon_value <= 180 ) {
					if ( ! isset( $schema['geo'] ) ) {
						$schema['geo'] = array( '@type' => 'GeoCoordinates' );
					}
					$schema['geo']['longitude'] = $lon_value;
				}
			}
			break;

				case 'keywords':
					// Handle keywords field for LocalBusiness
					if ( is_string( $value ) ) {
						$keywords_array = array_map( 'trim', explode( ',', $value ) );
						$keywords_array = array_filter( $keywords_array );
						if ( ! empty( $keywords_array ) ) {
							$schema['keywords'] = array_map( 'sanitize_text_field', $keywords_array );
						}
					} elseif ( is_array( $value ) ) {
						$schema['keywords'] = array_map( 'sanitize_text_field', $value );
					}
					break;

				case 'image':
					// Skip - handled separately below
					break;

				case 'logo':
					// Skip - handled separately below
					break;

				// ✅ Address sub-fields - skip (handled in address object above)
				case 'streetAddress':
				case 'addressLocality':
				case 'addressRegion':
				case 'postalCode':
				case 'addressCountry':
					// Skip - these are handled in the address object above
					break;

				// Author schema specific fields
				case 'givenName':
				case 'familyName':
				case 'additionalName':
				case 'honorificPrefix':
				case 'honorificSuffix':
				case 'jobTitle':
					if ( $schema_key === 'author' && $author_type === 'Person' ) {
						$schema[ $schema_field ] = sanitize_text_field( $value );
					}
					break;

				case 'worksFor':
					if ( $schema_key === 'author' && $author_type === 'Person' ) {
						$schema['worksFor'] = array(
							'@type' => 'Organization',
							'name'  => sanitize_text_field( $value ),
						);
					}
					break;

				default:
					$schema[ $schema_field ] = sanitize_text_field( $value );
			}
		}
		
		// ✅ Handle image field - different handling for author schema (Person vs Organization)
		// ✅ Only process image if it's enabled (or if enabled_fields is empty, process all)
		$process_image = empty( $enabled_fields ) || in_array( 'image', $enabled_fields, true );
		if ( $process_image && isset( $meta_map['image'] ) && ! empty( $meta_map['image'] ) ) {
			$image_mapping = $meta_map['image'];
			$image_url = '';
			
			// ✅ FIX: Handle featured_image and site_logo specifically
			if ( $image_mapping === 'featured_image' ) {
				// Try to get site logo from a recent post with thumbnail
				$recent_posts = get_posts( array(
					'posts_per_page' => 5,
					'meta_key'       => '_thumbnail_id',
					'meta_compare'   => 'EXISTS',
				) );
				
				if ( ! empty( $recent_posts ) ) {
					$image_url = get_the_post_thumbnail_url( $recent_posts[0]->ID, 'full' );
				}
				
				// Fallback to site logo
				if ( empty( $image_url ) ) {
					$image_url = $this->get_site_logo();
				}
			} elseif ( $image_mapping === 'site_logo' ) {
				// ✅ FIX: Get site logo directly - ensure we get the actual URL
				$image_url = $this->get_site_logo();
				// If get_site_logo() returns empty, try alternative method
				if ( empty( $image_url ) ) {
					$custom_logo_id = get_theme_mod( 'custom_logo' );
					if ( $custom_logo_id ) {
						$image_url = wp_get_attachment_url( $custom_logo_id );
					}
				}
			} elseif ( strpos( $image_mapping, 'featured_image' ) !== false || strpos( $image_mapping, 'site_logo' ) !== false ) {
				// Handle cases where value might be stored as string containing these keywords
				if ( strpos( $image_mapping, 'featured_image' ) !== false ) {
					$recent_posts = get_posts( array(
						'posts_per_page' => 5,
						'meta_key'       => '_thumbnail_id',
						'meta_compare'   => 'EXISTS',
					) );
					
					if ( ! empty( $recent_posts ) ) {
						$image_url = get_the_post_thumbnail_url( $recent_posts[0]->ID, 'full' );
					}
					
					if ( empty( $image_url ) ) {
						$image_url = $this->get_site_logo();
					}
				} else {
					// ✅ FIX: Get site logo with fallback
					$image_url = $this->get_site_logo();
					if ( empty( $image_url ) ) {
						$custom_logo_id = get_theme_mod( 'custom_logo' );
						if ( $custom_logo_id ) {
							$image_url = wp_get_attachment_url( $custom_logo_id );
						}
					}
				}
			} else {
				$image_url = $this->get_field_value( $image_mapping, 0 );
			}
			
			// ✅ FIX: More lenient URL validation - accept any non-empty string that looks like a URL
			if ( ! empty( $image_url ) && is_string( $image_url ) && trim( $image_url ) !== '' ) {
				// Check if it's a valid absolute URL
				$is_valid_url = filter_var( $image_url, FILTER_VALIDATE_URL );
				// Also accept relative URLs that start with / or contain wp-content
				$is_relative_url = ( strpos( $image_url, '/' ) === 0 || strpos( $image_url, 'wp-content' ) !== false || strpos( $image_url, 'http' ) === 0 );
				
				// If it's a valid URL or looks like a URL, add it to schema
				if ( $is_valid_url || $is_relative_url ) {
					// Convert relative URL to absolute if needed
					if ( $is_relative_url && ! $is_valid_url && strpos( $image_url, 'http' ) !== 0 ) {
						$image_url = site_url( $image_url );
					}
					
					// For author schema with Person type, use simple URL string (per Schema.org example)
					if ( $schema_key === 'author' && $author_type === 'Person' ) {
						$schema['image'] = esc_url_raw( $image_url );
					} else {
						// For other schemas or Organization, use ImageObject
						$image_data = array(
							'@type' => 'ImageObject',
							'url'    => esc_url_raw( $image_url ),
						);
						
						// Try to get image dimensions if it's a WordPress attachment
						if ( strpos( $image_url, 'wp-content/uploads' ) !== false ) {
							$attachment_id = attachment_url_to_postid( $image_url );
							if ( $attachment_id ) {
								$image_meta = wp_get_attachment_image_src( $attachment_id, 'full' );
								if ( $image_meta && isset( $image_meta[1] ) && isset( $image_meta[2] ) ) {
									$image_data['width']  = (int) $image_meta[1];
									$image_data['height'] = (int) $image_meta[2];
								}
							}
						}
						
						$schema['image'] = $image_data;
					}
				}
			}
		}

		// ✅ Handle logo field (URL string, not ImageObject)
		// ✅ Only process logo if it's enabled (or if enabled_fields is empty, process all)
		$process_logo = empty( $enabled_fields ) || in_array( 'logo', $enabled_fields, true );
		if ( $process_logo && isset( $meta_map['logo'] ) && ! empty( $meta_map['logo'] ) ) {
			$logo_mapping = $meta_map['logo'];
			$logo_url = '';
			
			// ✅ FIX: Handle site_logo specifically (exact match first)
			if ( $logo_mapping === 'site_logo' ) {
				$logo_url = $this->get_site_logo();
				// If get_site_logo() returns empty, try alternative methods
				if ( empty( $logo_url ) ) {
					$custom_logo_id = get_theme_mod( 'custom_logo' );
					if ( $custom_logo_id ) {
						$logo_url = wp_get_attachment_url( $custom_logo_id );
					}
				}
			} elseif ( strpos( $logo_mapping, 'site_logo' ) !== false ) {
				// Handle cases where value might be stored as string containing site_logo
				$logo_url = $this->get_site_logo();
				if ( empty( $logo_url ) ) {
					$custom_logo_id = get_theme_mod( 'custom_logo' );
					if ( $custom_logo_id ) {
						$logo_url = wp_get_attachment_url( $custom_logo_id );
					}
				}
			} else {
				$logo_url = $this->get_field_value( $logo_mapping, 0 );
			}
			
			// ✅ FIX: More lenient URL validation - accept any non-empty string that looks like a URL
			if ( ! empty( $logo_url ) && is_string( $logo_url ) && trim( $logo_url ) !== '' ) {
				// Check if it's a valid absolute URL
				$is_valid_url = filter_var( $logo_url, FILTER_VALIDATE_URL );
				// Also accept relative URLs that start with / or contain wp-content
				$is_relative_url = ( strpos( $logo_url, '/' ) === 0 || strpos( $logo_url, 'wp-content' ) !== false || strpos( $logo_url, 'http' ) === 0 );
				
				// If it's a valid URL or looks like a URL, add it to schema
				if ( $is_valid_url || $is_relative_url ) {
					// Convert relative URL to absolute if needed
					if ( $is_relative_url && ! $is_valid_url && strpos( $logo_url, 'http' ) !== 0 ) {
						$logo_url = site_url( $logo_url );
					}
					
					$schema['logo'] = esc_url_raw( $logo_url );
				}
			}
		}
		
	// ✅ Remove incomplete geo coordinates
	if ( isset( $schema['geo'] ) ) {
		if ( ! isset( $schema['geo']['latitude'] ) || ! isset( $schema['geo']['longitude'] ) ) {
			unset( $schema['geo'] );
		}
	}

	// ✅ Remove incomplete aggregateRating (needs both ratingValue and reviewCount)
	if ( isset( $schema['aggregateRating'] ) ) {
		if ( ! isset( $schema['aggregateRating']['ratingValue'] ) || ! isset( $schema['aggregateRating']['reviewCount'] ) ) {
			unset( $schema['aggregateRating'] );
		}
	}

		if ( ! empty( $same_as ) ) {
			$schema['sameAs'] = array_values( array_unique( $same_as ) );
		}

		// ✅ Special handling for author schema - ensure @type is set correctly BEFORE validation
		// This ensures validators see it as Person/Organization with all configured details
		if ( $schema_key === 'author' && isset( $settings['authorType'] ) ) {
			$schema['@type'] = $settings['authorType']; // Person or Organization
		}

		// ✅ NEW: Validate required fields before output
		if ( ! class_exists( 'SeoRepairKit_SchemaValidator' ) ) {
			require_once plugin_dir_path( dirname( __FILE__ ) ) . 'includes/class-seo-repair-kit-schema-validator.php';
		}

		// Check if schema has all required fields
		// ✅ For author schema, pass the actual @type (Person/Organization) instead of 'author'
		$validation_schema_type = $schema_key;
		if ( $schema_key === 'author' && isset( $schema['@type'] ) ) {
			$validation_schema_type = strtolower( $schema['@type'] );
		}
		
		if ( ! SeoRepairKit_SchemaValidator::should_output_schema( $schema, $validation_schema_type ) ) {
			// Schema is missing required fields - do not output
			return null;
		}

		// ✅ Ensure schema has at least @context and @type before returning
		if ( ! isset( $schema['@context'] ) || ! isset( $schema['@type'] ) ) {
			return null;
		}

		// ✅ Clean up empty values but preserve valid nested structures (like address, geo, etc.)
		// ✅ Also remove any address sub-fields that were incorrectly added directly to schema
		$address_sub_fields = array( 'streetAddress', 'addressLocality', 'addressRegion', 'postalCode', 'addressCountry' );
		$cleaned_schema = array();
		foreach ( $schema as $key => $value ) {
			// Always keep @context and @type
			if ( '@context' === $key || '@type' === $key ) {
				$cleaned_schema[ $key ] = $value;
				continue;
			}
			
			// ✅ Remove address sub-fields if they were added directly (they should only be in address object)
			if ( in_array( $key, $address_sub_fields, true ) ) {
				continue; // Skip - these should only exist inside the address object
			}
			
			// For arrays (like address, geo, aggregateRating), check if they have content beyond @type
			if ( is_array( $value ) ) {
				$non_type_keys = array_filter( array_keys( $value ), function( $k ) {
					return '@type' !== $k;
				} );
				if ( ! empty( $non_type_keys ) ) {
					$cleaned_schema[ $key ] = $value;
				}
			} elseif ( ! empty( $value ) && '' !== trim( (string) $value ) ) {
				$cleaned_schema[ $key ] = $value;
			}
		}

		return $cleaned_schema;
		}

		/**
		 * Resolve schema type from key.
		 *
		 * @param string $key Schema key.
		 * @return string Schema type.
		 */
		private function resolve_schema_type( $key ) {
			// Special handling for author schema - get type from settings
			if ( $key === 'author' ) {
				$option_key = 'srk_schema_assignment_author';
				$settings_raw = get_option( $option_key );
				if ( ! empty( $settings_raw ) ) {
					$settings = maybe_unserialize( $settings_raw );
					if ( is_array( $settings ) && isset( $settings['authorType'] ) ) {
						return $settings['authorType']; // Returns 'Person' or 'Organization'
					}
				}
				return 'Person'; // Default to Person
			}

			$map = array(
				'article'          => 'Article',
				'blog_posting'     => 'BlogPosting',
				'news_article'     => 'NewsArticle',
				'product'          => 'Product',
				'event'            => 'Event',
				'organization'     => 'Organization',
				'website'          => 'WebSite',
				'local_business'   => 'LocalBusiness',
				'corporation'      => 'Corporation',
				'faq'              => 'FAQ Page',
				'howto'            => 'How-To',
				'job_posting'      => 'JobPosting',
			);

			return $map[ $key ] ?? 'Thing';
		}

		/**
		 * Add common properties to schema.
		 *
		 * @param array  $schema    Schema array.
		 * @param string $schema_key Schema type key.
		 * @param int    $post_id   Post ID.
		 * @return array Modified schema.
		 */
		private function add_common_properties( $schema, $schema_key, $post_id ) {
			$schema['url'] = get_permalink( $post_id );

			switch ( $schema_key ) {
				case 'article':
				case 'blog_posting':
				case 'news_article':
					$schema['datePublished']  = get_the_date( 'c', $post_id );
					$schema['dateModified']   = get_the_modified_date( 'c', $post_id );
					$schema['mainEntityOfPage'] = array(
						'@type' => 'WebPage',
						'@id'   => get_permalink( $post_id ),
					);
					break;

				case 'event':
					if ( ! isset( $schema['startDate'] ) ) {
						$schema['startDate'] = get_the_date( 'c', $post_id );
					}
					break;

				case 'organization':
					if ( ! isset( $schema['logo'] ) ) {
						$schema['logo'] = $this->get_site_logo();
					}
					break;
			}

			return $schema;
		}

		/**
		 * Get field value based on field key.
		 *
		 * @param string $field_key Field key.
		 * @param int    $post_id   Post ID.
		 * @return mixed Field value.
		 */
	private function get_field_value( $field_key, $post_id ) {
		if ( strpos( $field_key, 'site:' ) === 0 ) {
			$site_key = str_replace( 'site:', '', $field_key );

			$site_values = array(
				'site_name'        => get_bloginfo( 'name' ),
				'site_description' => get_bloginfo( 'description' ),
				'site_url'         => home_url(),
				'logo_url'         => $this->get_site_logo(),
				'admin_email'      => get_option( 'admin_email' ),
			);

			return isset( $site_values[ $site_key ] ) ? $site_values[ $site_key ] : '';
		} elseif ( strpos( $field_key, 'custom:' ) === 0 ) {
			return str_replace( 'custom:', '', $field_key );
		}

		switch ( $field_key ) {
			case 'post_title':
				return get_the_title( $post_id );

			case 'post_excerpt':
				return get_the_excerpt( $post_id );

			case 'post_content':
				return wp_strip_all_tags( apply_filters( 'the_content', get_post_field( 'post_content', $post_id ) ) );

			case 'post_date':
				return get_the_date( 'c', $post_id );

			case 'featured_image':
				$thumb = wp_get_attachment_image_src( get_post_thumbnail_id( $post_id ), 'full' );
				return $thumb[0] ?? '';

			case 'author_name':
				$author_id = get_post_field( 'post_author', $post_id );
				return get_the_author_meta( 'display_name', $author_id );

			case 'author_url':
				$author_id = get_post_field( 'post_author', $post_id );
				return get_author_posts_url( $author_id );

			case 'site_logo':
				return $this->get_site_logo();

			case 'social_profiles':
				return $this->get_social_profiles();

			default:
				// ✅ FIX: For values without prefix that aren't recognized post fields,
				// treat them as literal custom values (for backward compatibility)
				// This handles cases where values are stored directly without "custom:" prefix
				$meta_value = get_post_meta( $post_id, $field_key, true );
				if ( ! empty( $meta_value ) ) {
					return $meta_value;
				}
				// If not found in post meta and not a recognized field, return the value as-is
				// This allows direct values like "07:08 - 04:30" to pass through
				return $field_key;
		}
	}

		/**
		 * Build author schema.
		 *
		 * @param string $field_key Field key.
		 * @param int    $post_id   Post ID.
		 * @return array Author schema.
		 */
		private function build_author( $field_key, $post_id ) {
			$author_value = $this->get_field_value( $field_key, $post_id );
			$author       = array( '@type' => 'Person' );

			if ( filter_var( $author_value, FILTER_VALIDATE_URL ) ) {
				$author['@id'] = $author_value;
			} else {
				$author['name'] = $author_value;
			}

			return $author;
		}

		/**
		 * Build publisher schema.
		 *
		 * @return array Publisher schema.
		 */
		private function build_publisher() {
			return array(
				'@type' => 'Organization',
				'name'  => get_bloginfo( 'name' ),
				'logo'  => $this->get_site_logo(),
			);
		}

		/**
		 * Build image schema.
		 *
		 * @param string $field_key Field key.
		 * @param int    $post_id   Post ID.
		 * @return array|null Image schema or null.
		 */
		private function build_image( $field_key, $post_id ) {
			$image_url = $this->get_field_value( $field_key, $post_id );

			if ( empty( $image_url ) ) {
				return null;
			}

			return array(
				'@type'  => 'ImageObject',
				'url'    => $image_url,
				'width'  => 1200,
				'height' => 800,
			);
		}

		/**
		 * Get site logo URL.
		 * Checks multiple sources: WordPress customizer logo, site icon, and common theme options.
		 *
		 * @return string Logo URL.
		 */
		private function get_site_logo() {
			// 1. Check WordPress Customizer logo (most common)
			$custom_logo_id = get_theme_mod( 'custom_logo' );
			if ( $custom_logo_id ) {
				$logo = wp_get_attachment_image_src( $custom_logo_id, 'full' );
				if ( ! empty( $logo[0] ) ) {
					return $logo[0];
				}
			}
			
			// 2. Check site icon (favicon can sometimes be used as logo)
			$site_icon_id = get_option( 'site_icon' );
			if ( $site_icon_id ) {
				$icon = wp_get_attachment_image_src( $site_icon_id, 'full' );
				if ( ! empty( $icon[0] ) ) {
					return $icon[0];
				}
			}
			
			// 3. Check for site icon URL directly
			$site_icon_url = get_site_icon_url();
			if ( ! empty( $site_icon_url ) ) {
				return $site_icon_url;
			}
			
			// 4. Check common theme options for logo
			$theme_logo_options = array(
				'logo',           // Common theme option
				'site_logo',      // Another common option
				'header_logo',    // Header logo option
				'main_logo',      // Main logo option
			);
			
			foreach ( $theme_logo_options as $option_key ) {
				$logo_option = get_theme_mod( $option_key );
				if ( ! empty( $logo_option ) ) {
					// If it's a URL string, return it
					if ( filter_var( $logo_option, FILTER_VALIDATE_URL ) ) {
						return $logo_option;
					}
					// If it's an attachment ID, get the URL
					if ( is_numeric( $logo_option ) ) {
						$logo = wp_get_attachment_image_src( (int) $logo_option, 'full' );
						if ( ! empty( $logo[0] ) ) {
							return $logo[0];
						}
					}
				}
			}
			
			// 5. Check WordPress site options
			$site_logo_option = get_option( 'site_logo' );
			if ( ! empty( $site_logo_option ) ) {
				if ( filter_var( $site_logo_option, FILTER_VALIDATE_URL ) ) {
					return $site_logo_option;
				}
				if ( is_numeric( $site_logo_option ) ) {
					$logo = wp_get_attachment_image_src( (int) $site_logo_option, 'full' );
					if ( ! empty( $logo[0] ) ) {
						return $logo[0];
					}
				}
			}
			
			return '';
		}

		/**
		 * Get social profiles.
		 *
		 * @return array Social profile URLs.
		 */
		private function get_social_profiles() {
			$profiles       = array();
			$social_options = array( 'facebook', 'twitter', 'instagram', 'linkedin', 'youtube' );

			foreach ( $social_options as $platform ) {
				$url = get_option( $platform . '_url' );
				if ( $url ) {
					$profiles[] = esc_url_raw( $url );
				}
			}

			return $profiles;
		}

	/**
	 * Validate and clean schema.
	 *
	 * @param array  $schema    Schema array.
	 * @param string $schema_key Schema type key.
	 * @return array|null Validated schema or null if invalid.
	 */
	private function validate_schema( $schema, $schema_key ) {
		// ✅ Remove address sub-fields that were incorrectly added directly to schema
		$address_sub_fields = array( 'streetAddress', 'addressLocality', 'addressRegion', 'postalCode', 'addressCountry' );
		foreach ( $address_sub_fields as $sub_field ) {
			if ( isset( $schema[ $sub_field ] ) ) {
				unset( $schema[ $sub_field ] );
			}
		}

		// ✅ Clean up empty values but preserve valid nested structures
		$cleaned_schema = array();
		foreach ( $schema as $key => $value ) {
			// Always keep @context and @type
			if ( '@context' === $key || '@type' === $key ) {
				$cleaned_schema[ $key ] = $value;
				continue;
			}
			
			// For arrays (like address, geo, aggregateRating), check if they have content beyond @type
			if ( is_array( $value ) ) {
				$non_type_keys = array_filter( array_keys( $value ), function( $k ) {
					return '@type' !== $k;
				} );
				if ( ! empty( $non_type_keys ) ) {
					$cleaned_schema[ $key ] = $value;
				}
			} elseif ( ! empty( $value ) && '' !== trim( (string) $value ) ) {
				$cleaned_schema[ $key ] = $value;
			}
		}
		$schema = $cleaned_schema;

		switch ( $schema_key ) {
			case 'article':
			case 'blog_posting':
			case 'news_article':
				if ( ! isset( $schema['publisher'] ) ) {
					$schema['publisher'] = $this->build_publisher();
				}
				break;

			case 'event':
				if ( ! isset( $schema['location'] ) ) {
					$schema['location'] = array(
						'@type' => 'Place',
						'name'  => 'Online',
					);
				}
				break;

			case 'organization':
				if ( ! isset( $schema['logo'] ) ) {
					$schema['logo'] = $this->get_site_logo();
				}
				if ( ! isset( $schema['sameAs'] ) ) {
					$social_profiles = $this->get_social_profiles();
					if ( ! empty( $social_profiles ) ) {
						$schema['sameAs'] = $social_profiles;
					}
				}
				break;
		}

		// ✅ NEW: Validate required fields before output
		if ( ! class_exists( 'SeoRepairKit_SchemaValidator' ) ) {
			require_once plugin_dir_path( dirname( __FILE__ ) ) . 'includes/class-seo-repair-kit-schema-validator.php';
		}

		// Check if schema has all required fields
		// ✅ For author schema, pass the actual @type (Person/Organization) instead of 'author'
		$validation_schema_type = $schema_key;
		if ( $schema_key === 'author' && isset( $schema['@type'] ) ) {
			$validation_schema_type = strtolower( $schema['@type'] );
		}
		
		if ( ! SeoRepairKit_SchemaValidator::should_output_schema( $schema, $validation_schema_type ) ) {
			// Schema is missing required fields - do not output
			return null;
		}

		// ✅ Ensure schema has at least @context and @type before returning
		if ( ! isset( $schema['@context'] ) || ! isset( $schema['@type'] ) ) {
			return null;
		}

		return $schema;
	}
	}
}