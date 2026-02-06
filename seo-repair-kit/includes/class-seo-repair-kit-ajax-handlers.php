<?php
/**
 * AJAX Handlers for SEO Repair Kit
 *
 * @package     SEO_Repair_Kit
 * @subpackage  AJAX
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Handles all AJAX operations for schema management.
 */
class SeoRepairKit_AjaxHandlers {

	/**
	 * Register all AJAX hooks.
	 */
	public static function register() {
		$actions = array(
			'srk_get_post_types',
			'srk_get_post_related_fields',
			'srk_save_schema_assignment',
			'srk_get_assigned_schemas',
			'srk_get_global_options',
			'srk_get_schema_configuration',
			'srk_get_schema_assignment',
			'srk_get_faq_configuration',
			'srk_save_schema_assignment_faq',
			'srk_get_preview_data',
			'srk_get_posts_by_type',
			'srk_validate_schema', // ✅ NEW: Schema validation endpoint
			'srk_get_required_fields', // ✅ NEW: Get required fields for schema type
			'srk_get_google_test_url', // ✅ NEW: Get Google Rich Results Test URL
			'srk_test_schema_with_google', // ✅ NEW: Test schema with Google Rich Results
			'srk_delete_schema_assignment', // ✅ NEW: Delete schema assignment
		);

		foreach ( $actions as $action ) {
			add_action( 'wp_ajax_' . $action, array( __CLASS__, $action ) );
		}

		// ✅ PERFORMANCE: Clear cache when posts are saved/updated
		add_action( 'save_post', array( __CLASS__, 'clear_field_data_cache' ), 10, 2 );
		add_action( 'delete_post', array( __CLASS__, 'clear_field_data_cache' ) );
	}

	/**
	 * ✅ PERFORMANCE: Clear cached field data when posts are updated
	 *
	 * @param int     $post_id Post ID.
	 * @param WP_Post $post    Post object (optional).
	 */
	public static function clear_field_data_cache( $post_id, $post = null ) {
		if ( ! $post ) {
			$post = get_post( $post_id );
		}

		if ( ! $post ) {
			return;
		}

		// Clear cache for this post type
		$cache_key = 'srk_fields_' . md5( $post->post_type );
		delete_transient( $cache_key );

		// Clear posts cache for this post type
		$posts_cache_key = 'srk_posts_' . md5( $post->post_type );
		delete_transient( $posts_cache_key );
	}


	/**
	* Get preview data for schema with field enable/disable support
	*/
	public static function srk_get_preview_data() {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_send_json_error( array( 'message' => 'Unauthorized' ) );
		}
	
		$schema_type = isset( $_POST['schema_type'] ) ? sanitize_text_field( wp_unslash( $_POST['schema_type'] ) ) : '';
		$meta_map = isset( $_POST['meta_map'] ) ? wp_unslash( $_POST['meta_map'] ) : array();
		$enabled_fields = isset( $_POST['enabled_fields'] ) ? array_map( 'sanitize_text_field', wp_unslash( $_POST['enabled_fields'] ) ) : array();
		// ✅ NEW: Get post type and post ID for accurate CPT preview
		$post_type = isset( $_POST['post_type'] ) ? sanitize_text_field( wp_unslash( $_POST['post_type'] ) ) : '';
		$post_id = isset( $_POST['post_id'] ) ? intval( $_POST['post_id'] ) : 0;
	
		$preview_data = array(
			'@context' => 'https://schema.org',
			'@type'    => ucfirst( $schema_type ),
		);
	
	// Filter meta_map to only include enabled fields
	// BUT: Always include address sub-fields if 'address' is enabled
	// AND: Always include latitude/longitude if they're enabled
	$filtered_meta_map = array();
	foreach ( $meta_map as $field => $mapping ) {
		// Include field if it's in enabled_fields
		if ( in_array( $field, $enabled_fields ) ) {
			$filtered_meta_map[ $field ] = $mapping;
		}
		// Also include address sub-fields if 'address' is enabled
		elseif ( in_array( 'address', $enabled_fields ) && in_array( $field, array( 'streetAddress', 'addressLocality', 'addressRegion', 'postalCode', 'addressCountry' ) ) ) {
			$filtered_meta_map[ $field ] = $mapping;
		}
	}

	// Handle different schema types - pass FULL meta_map (not filtered) for proper field handling
	// The functions will check enabled_fields internally if needed
	switch ($schema_type) {
			case 'organization':
				// Organization is global, doesn't need post data
				$preview_data = self::get_organization_preview_data($meta_map, $preview_data);
				break;
			case 'local_business':
			case 'LocalBusiness':
				// LocalBusiness is global, doesn't need post data
				$preview_data = self::get_local_business_preview_data($meta_map, $preview_data);
				break;
			default:
				// ✅ FIX: Pass post_type and post_id to get accurate CPT preview data
				// This handles: product, review, recipe, event, job_posting, course, medical_condition, etc.
				$preview_data = self::get_general_preview_data($schema_type, $meta_map, $preview_data, $post_type, $post_id);
		}
	
		wp_send_json_success( $preview_data );
	}
    
	/**
	 * Get organization-specific preview data.
	 */
	private static function get_organization_preview_data($meta_map, $preview_data) {
		// ✅ Address as proper PostalAddress object with all sub-fields
		$streetAddress = isset($meta_map['streetAddress']) ? self::get_mapped_value($meta_map['streetAddress'], null) : '';
		$addressLocality = isset($meta_map['addressLocality']) ? self::get_mapped_value($meta_map['addressLocality'], null) : '';
		$addressRegion = isset($meta_map['addressRegion']) ? self::get_mapped_value($meta_map['addressRegion'], null) : '';
		$postalCode = isset($meta_map['postalCode']) ? self::get_mapped_value($meta_map['postalCode'], null) : '';
		$addressCountry = isset($meta_map['addressCountry']) ? self::get_mapped_value($meta_map['addressCountry'], null) : '';
		
		if (!empty($streetAddress) || !empty($addressLocality) || !empty($addressRegion) || !empty($postalCode) || !empty($addressCountry)) {
			$preview_data['address'] = array(
				'@type' => 'PostalAddress'
			);
			if (!empty($streetAddress)) $preview_data['address']['streetAddress'] = $streetAddress;
			if (!empty($addressLocality)) $preview_data['address']['addressLocality'] = $addressLocality;
			if (!empty($addressRegion)) $preview_data['address']['addressRegion'] = $addressRegion;
			if (!empty($postalCode)) $preview_data['address']['postalCode'] = $postalCode;
			if (!empty($addressCountry)) {
				$preview_data['address']['addressCountry'] = array(
					'@type' => 'Country',
					'name' => $addressCountry
				);
			}
		}
		
		// ✅ Handle image/logo for organization - FIXED with proper ImageObject
		$image_url = '';
		if (isset($meta_map['image'])) {
			$image_mapping = $meta_map['image'];
			
			// ✅ FIX: Handle featured_image and site_logo specifically
			if ($image_mapping === 'featured_image') {
				// Try to get site logo from a recent post with thumbnail
				$recent_posts = get_posts(array(
					'posts_per_page' => 5,
					'meta_key' => '_thumbnail_id',
					'meta_compare' => 'EXISTS'
				));
				
				if (!empty($recent_posts)) {
					$image_url = get_the_post_thumbnail_url($recent_posts[0]->ID, 'full');
				}
				
				// Fallback to site logo if no site logo found
				if (empty($image_url)) {
					$image_url = self::get_site_logo();
				}
			} elseif ($image_mapping === 'site_logo') {
				// Get site logo directly
				$image_url = self::get_site_logo();
			} elseif (strpos($image_mapping, 'featured_image') !== false || strpos($image_mapping, 'site_logo') !== false) {
				// Handle cases where value might be stored as string containing these keywords
				if (strpos($image_mapping, 'featured_image') !== false) {
					$recent_posts = get_posts(array(
						'posts_per_page' => 5,
						'meta_key' => '_thumbnail_id',
						'meta_compare' => 'EXISTS'
					));
					
					if (!empty($recent_posts)) {
						$image_url = get_the_post_thumbnail_url($recent_posts[0]->ID, 'full');
					}
					
					if (empty($image_url)) {
						$image_url = self::get_site_logo();
					}
				} else {
					$image_url = self::get_site_logo();
				}
			} else {
				$image_url = self::get_mapped_value($image_mapping, null);
			}
		}
		
		if (!empty($image_url)) {
			$image_data = array(
				'@type' => 'ImageObject',
				'url' => $image_url
			);
			
			// Try to get image dimensions if it's a WordPress attachment
			if (strpos($image_url, 'wp-content/uploads') !== false) {
				$attachment_id = attachment_url_to_postid($image_url);
				if ($attachment_id) {
					$image_meta = wp_get_attachment_image_src($attachment_id, 'full');
					if ($image_meta && isset($image_meta[1]) && isset($image_meta[2])) {
						$image_data['width'] = (int) $image_meta[1];
						$image_data['height'] = (int) $image_meta[2];
					}
				}
			}
			
			$preview_data['image'] = $image_data;
		} else {
			// ✅ Fallback: Use site logo if no image mapping
			$custom_logo_id = get_theme_mod('custom_logo');
			$fallback_logo = $custom_logo_id ? wp_get_attachment_url($custom_logo_id) : '';
			
			if (!empty($fallback_logo)) {
				$preview_data['image'] = array(
					'@type' => 'ImageObject',
					'url' => $fallback_logo
				);
			}
		}
		
		// ✅ Handle logo field (URL string, not ImageObject)
		if (isset($meta_map['logo'])) {
			$logo_mapping = $meta_map['logo'];
			$logo_url = '';
			
			// ✅ FIX: Handle site_logo specifically (exact match first)
			if ($logo_mapping === 'site_logo') {
				$logo_url = self::get_site_logo();
				// If get_site_logo() returns empty, try alternative methods
				if (empty($logo_url)) {
					$custom_logo_id = get_theme_mod('custom_logo');
					if ($custom_logo_id) {
						$logo_url = wp_get_attachment_url($custom_logo_id);
					}
				}
			} elseif (strpos($logo_mapping, 'site_logo') !== false) {
				// Handle cases where value might be stored as string containing site_logo
				$logo_url = self::get_site_logo();
				if (empty($logo_url)) {
					$custom_logo_id = get_theme_mod('custom_logo');
					if ($custom_logo_id) {
						$logo_url = wp_get_attachment_url($custom_logo_id);
					}
				}
			} else {
				$logo_url = self::get_mapped_value($logo_mapping, null);
			}
			
			// ✅ FIX: More lenient URL validation - accept any non-empty string that looks like a URL
			if (!empty($logo_url) && is_string($logo_url) && trim($logo_url) !== '') {
				// Check if it's a valid absolute URL
				$is_valid_url = filter_var($logo_url, FILTER_VALIDATE_URL);
				// Also accept relative URLs that start with / or contain wp-content
				$is_relative_url = (strpos($logo_url, '/') === 0 || strpos($logo_url, 'wp-content') !== false || strpos($logo_url, 'http') === 0);
				
				// If it's a valid URL or looks like a URL, add it to schema
				if ($is_valid_url || $is_relative_url) {
					// Convert relative URL to absolute if needed
					if ($is_relative_url && !$is_valid_url && strpos($logo_url, 'http') !== 0) {
						$logo_url = site_url($logo_url);
					}
					
					$preview_data['logo'] = esc_url_raw($logo_url); // Simple URL string
				}
			}
		} else if (!empty($preview_data['image'])) {
			// Fallback: use image URL as logo
			$preview_data['logo'] = is_array($preview_data['image']) ? $preview_data['image']['url'] : $preview_data['image'];
		}

		// ✅ Handle other fields
		foreach ($meta_map as $field => $mapping) {
			// Skip address sub-fields and image/logo (already handled)
			if (in_array($field, ['streetAddress', 'addressLocality', 'addressRegion', 'postalCode', 'addressCountry', 'image', 'logo'])) {
				continue;
			}
			if ($field === 'image') continue; // Already handled
			
			$value = self::get_mapped_value($mapping, null);
			
			if (!empty($value)) {
				switch ($field) {
					case 'contactPoint':
						$preview_data[$field] = array(
							'@type' => 'ContactPoint',
							'contactType' => 'customer service',
							'telephone' => $value
						);
						break;
						
					case 'facebook_url':
					case 'twitter_url': 
					case 'instagram_url':
					case 'youtube_url':
						if (!isset($preview_data['sameAs'])) {
							$preview_data['sameAs'] = array();
						}
						// Ensure URL is properly formatted
						$social_url = $value;
						if (!preg_match('/^https?:\/\//', $social_url)) {
							$social_url = 'https://' . ltrim($social_url, '/');
						}
						$preview_data['sameAs'][] = $social_url;
						break;
						
					case 'address':
						$preview_data[$field] = array(
							'@type' => 'PostalAddress',
							'streetAddress' => $value
						);
						break;
						
					default:
						$preview_data[$field] = $value;
				}
			}
		}

		return $preview_data;
	}

	/**
	 * Get Local Business preview data with proper schema formatting
	 */
	private static function get_local_business_preview_data($meta_map, $preview_data) {
		// Basic fields
		$basic_fields = ['name', 'description', 'alternateName', 'telephone', 'priceRange'];
		foreach ($basic_fields as $field) {
			if (isset($meta_map[$field])) {
				$value = self::get_mapped_value($meta_map[$field], null);
				if (!empty($value) && trim($value) !== '') {
					$preview_data[$field] = sanitize_text_field( trim($value) );
				}
			}
		}
		
		// URL field
		if (isset($meta_map['url'])) {
			$url_value = self::get_mapped_value($meta_map['url'], null);
			if (!empty($url_value) && trim($url_value) !== '' && filter_var($url_value, FILTER_VALIDATE_URL)) {
				$preview_data['url'] = esc_url_raw(trim($url_value));
			}
		}
	
		// ✅ FIXED: Address as proper PostalAddress object with all sub-fields
		$streetAddress = isset($meta_map['streetAddress']) ? trim(self::get_mapped_value($meta_map['streetAddress'], null)) : '';
		$addressLocality = isset($meta_map['addressLocality']) ? trim(self::get_mapped_value($meta_map['addressLocality'], null)) : '';
		$addressRegion = isset($meta_map['addressRegion']) ? trim(self::get_mapped_value($meta_map['addressRegion'], null)) : '';
		$postalCode = isset($meta_map['postalCode']) ? trim(self::get_mapped_value($meta_map['postalCode'], null)) : '';
		$addressCountry = isset($meta_map['addressCountry']) ? trim(self::get_mapped_value($meta_map['addressCountry'], null)) : '';
		
		if (!empty($streetAddress) || !empty($addressLocality) || !empty($addressRegion) || !empty($postalCode) || !empty($addressCountry)) {
			$preview_data['address'] = array(
				'@type' => 'PostalAddress'
			);
			if (!empty($streetAddress)) {
				$preview_data['address']['streetAddress'] = sanitize_text_field($streetAddress);
			}
			if (!empty($addressLocality)) {
				$preview_data['address']['addressLocality'] = sanitize_text_field($addressLocality);
			}
			if (!empty($addressRegion)) {
				$preview_data['address']['addressRegion'] = sanitize_text_field($addressRegion);
			}
			if (!empty($postalCode)) {
				$preview_data['address']['postalCode'] = sanitize_text_field($postalCode);
			}
			if (!empty($addressCountry)) {
				$preview_data['address']['addressCountry'] = array(
					'@type' => 'Country',
					'name' => sanitize_text_field($addressCountry)
				);
			}
		}
	
	// ✅ FIXED: Opening Hours - keep as simple string or array
	if (isset($meta_map['openingHours'])) {
		$opening_hours = self::get_mapped_value($meta_map['openingHours'], null);
		if (!empty($opening_hours) && trim($opening_hours) !== '') {
			// Schema.org accepts various formats for openingHours
			// 1. Simple string like "Monday-Friday 9:00-17:00"
			// 2. Array of strings
			// 3. OpeningHoursSpecification object (more structured)
			
			// Clean the value
			$opening_hours = trim($opening_hours);
			
			// If it contains comma, treat as array
			if (strpos($opening_hours, ',') !== false) {
				$hours_array = array_map('trim', explode(',', $opening_hours));
				$hours_array = array_filter($hours_array);
				if (!empty($hours_array)) {
					$preview_data['openingHours'] = array_map('sanitize_text_field', $hours_array);
				}
			} else {
				// Single string value - just add it as-is
				$preview_data['openingHours'] = sanitize_text_field($opening_hours);
			}
		}
	}
	
	// ✅ FIXED: Geo Coordinates as proper GeoCoordinates object with validation
	$latitude = isset($meta_map['latitude']) ? trim((string) self::get_mapped_value($meta_map['latitude'], null)) : '';
	$longitude = isset($meta_map['longitude']) ? trim((string) self::get_mapped_value($meta_map['longitude'], null)) : '';

	if (!empty($latitude) && !empty($longitude)) {
		// Clean the values - remove any invalid characters (keep digits, decimal, minus)
		$latitude = preg_replace('/[^0-9.\-]/', '', $latitude);
		$longitude = preg_replace('/[^0-9.\-]/', '', $longitude);
		
		// Check for multiple decimal points (invalid)
		if (substr_count($latitude, '.') > 1 || substr_count($longitude, '.') > 1) {
			// Invalid format - skip geo coordinates
			// Don't add to preview_data
		} elseif (!empty($latitude) && !empty($longitude)) {
			$lat_float = floatval($latitude);
			$lon_float = floatval($longitude);
			
			// Validate lat/lon ranges: lat between -90 and 90, lon between -180 and 180
			// Note: 0,0 is a valid coordinate (Gulf of Guinea)
			if ($lat_float >= -90 && $lat_float <= 90 && $lon_float >= -180 && $lon_float <= 180) {
				$preview_data['geo'] = array(
					'@type' => 'GeoCoordinates',
					'latitude' => $lat_float,
					'longitude' => $lon_float
				);
			}
		}
	}
	
	// ✅ Aggregate Rating (requires both ratingValue and reviewCount)
	$rating_value = isset($meta_map['ratingValue']) ? trim(self::get_mapped_value($meta_map['ratingValue'], null)) : '';
	$review_count = isset($meta_map['reviewCount']) ? trim(self::get_mapped_value($meta_map['reviewCount'], null)) : '';
	
	if (!empty($rating_value) && !empty($review_count)) {
		$rating = floatval($rating_value);
		$count = intval($review_count);
		
		// Validate: rating between 0-5, count must be positive
		if ($rating > 0 && $rating <= 5 && $count > 0) {
			$preview_data['aggregateRating'] = array(
				'@type' => 'AggregateRating',
				'ratingValue' => (string) $rating,
				'reviewCount' => (string) $count
			);
		}
	}
	
	// ✅ FIXED: Keywords as array with proper sanitization
	if (isset($meta_map['keywords'])) {
		$keywords_value = self::get_mapped_value($meta_map['keywords'], null);
		if (!empty($keywords_value) && trim($keywords_value) !== '') {
			$keywords_array = is_array($keywords_value) ? $keywords_value : explode(',', $keywords_value);
			// Clean up keywords
			$keywords_array = array_map('trim', $keywords_array);
			$keywords_array = array_filter($keywords_array, function($kw) {
				return !empty($kw) && trim($kw) !== '';
			});
			if (!empty($keywords_array)) {
				$preview_data['keywords'] = array_map('sanitize_text_field', $keywords_array);
			}
		}
	}
	
		// ✅ FIXED: Image handling with proper ImageObject and dimensions
		if (isset($meta_map['image'])) {
			$image_mapping = $meta_map['image'];
			$image_url = '';
			
			// ✅ FIX: Handle featured_image and site_logo specifically
			if ($image_mapping === 'featured_image') {
				// Try to get site logo from a recent post with thumbnail
				$recent_posts = get_posts(array(
					'posts_per_page' => 5,
					'meta_key' => '_thumbnail_id',
					'meta_compare' => 'EXISTS'
				));
				
				if (!empty($recent_posts)) {
					$image_url = get_the_post_thumbnail_url($recent_posts[0]->ID, 'full');
				}
				
				// Fallback to site logo if no site logo found
				if (empty($image_url)) {
					$image_url = self::get_site_logo();
				}
			} elseif ($image_mapping === 'site_logo') {
				// Get site logo directly
				$image_url = self::get_site_logo();
			} elseif (strpos($image_mapping, 'featured_image') !== false || strpos($image_mapping, 'site_logo') !== false) {
				// Handle cases where value might be stored as string containing these keywords
				if (strpos($image_mapping, 'featured_image') !== false) {
					$recent_posts = get_posts(array(
						'posts_per_page' => 5,
						'meta_key' => '_thumbnail_id',
						'meta_compare' => 'EXISTS'
					));
					
					if (!empty($recent_posts)) {
						$image_url = get_the_post_thumbnail_url($recent_posts[0]->ID, 'full');
					}
					
					if (empty($image_url)) {
						$image_url = self::get_site_logo();
					}
				} else {
					$image_url = self::get_site_logo();
				}
			} else {
				$image_url = self::get_mapped_value($image_mapping, null);
			}
			
			if (!empty($image_url) && filter_var($image_url, FILTER_VALIDATE_URL)) {
				$image_data = array(
					'@type' => 'ImageObject',
					'url' => esc_url_raw($image_url)
				);
				
				// Try to get image dimensions if it's a WordPress attachment
				if (strpos($image_url, 'wp-content/uploads') !== false) {
					$attachment_id = attachment_url_to_postid($image_url);
					if ($attachment_id) {
						$image_meta = wp_get_attachment_image_src($attachment_id, 'full');
						if ($image_meta && isset($image_meta[1]) && isset($image_meta[2])) {
							$image_data['width'] = (int) $image_meta[1];
							$image_data['height'] = (int) $image_meta[2];
						}
					}
				}
				
				$preview_data['image'] = $image_data;
			}
		}
	
		return $preview_data;
	}

	/**
	 * Get general preview data for other schemas.
	 * 
	 * ✅ FIX: Now accepts post_type and post_id to use actual CPT records for preview
	 *
	 * @param string $schema_type Schema type.
	 * @param array  $meta_map    Meta mapping.
	 * @param array  $preview_data Preview data array.
	 * @param string $post_type   Selected post type (optional).
	 * @param int    $post_id     Selected post ID (optional).
	 * @return array Preview data.
	 */
	private static function get_general_preview_data($schema_type, $meta_map, $preview_data, $post_type = '', $post_id = 0) {
		$post = null;
		
		// ✅ FIX: Use selected post if provided
		if ($post_id > 0) {
			$post = get_post($post_id);
			// Verify post exists and matches the post type
			if (!$post || ($post_type && $post->post_type !== $post_type)) {
				$post = null;
			}
		}
		
		// ✅ FIX: If no specific post selected but post type is provided, get first post of that type
		if (!$post && $post_type && $post_type !== 'global') {
			$posts = get_posts(array(
				'numberposts' => 1,
				'post_type'   => $post_type,
				'post_status' => 'publish',
				'orderby'     => 'ID',
				'order'       => 'DESC',
			));
			
			if (!empty($posts)) {
				$post = $posts[0];
			}
		}
		
		// ✅ FIX: Fallback to sample post from 'post' type only if no post type was specified
		if (!$post && empty($post_type)) {
			$sample_post = get_posts(array(
				'numberposts' => 1,
				'post_type'   => 'post',
				'post_status' => 'publish',
				'orderby'     => 'ID',
				'order'       => 'DESC',
			));

			if (!empty($sample_post)) {
				$post = $sample_post[0];
			}
		}

		if (!empty($post)) {
			
			// ✅ FIX: Handle image with featured_image and site_logo support
			if (isset($meta_map['image'])) {
				$image_mapping = $meta_map['image'];
				$image_url = '';
				
				// Handle featured_image and site_logo specifically
				if ($image_mapping === 'featured_image') {
					// ✅ FIX: Get featured image from the actual selected post (CPT or regular post)
					$image_url = get_the_post_thumbnail_url($post->ID, 'full');
					
					// Fallback to site logo
					if (empty($image_url)) {
						$image_url = self::get_site_logo();
					}
				} elseif ($image_mapping === 'site_logo') {
					// Get site logo directly
					$image_url = self::get_site_logo();
				} elseif (strpos($image_mapping, 'featured_image') !== false || strpos($image_mapping, 'site_logo') !== false) {
					// Handle cases where value might be stored as string containing these keywords
					if (strpos($image_mapping, 'featured_image') !== false) {
						$image_url = get_the_post_thumbnail_url($post->ID, 'full');
						if (empty($image_url)) {
							$image_url = self::get_site_logo();
						}
					} else {
						$image_url = self::get_site_logo();
					}
				} else {
					$image_url = self::get_mapped_value($image_mapping, $post);
				}
				
				if (!empty($image_url) && filter_var($image_url, FILTER_VALIDATE_URL)) {
					$image_data = array(
						'@type' => 'ImageObject',
						'url' => esc_url_raw($image_url)
					);
					
					// Try to get image dimensions if it's a WordPress attachment
					if (strpos($image_url, 'wp-content/uploads') !== false) {
						$attachment_id = attachment_url_to_postid($image_url);
						if ($attachment_id) {
							$image_meta = wp_get_attachment_image_src($attachment_id, 'full');
							if ($image_meta && isset($image_meta[1]) && isset($image_meta[2])) {
								$image_data['width'] = (int) $image_meta[1];
								$image_data['height'] = (int) $image_meta[2];
							}
						}
					}
					
					$preview_data['image'] = $image_data;
				}
			}

			// Handle other fields
			foreach ($meta_map as $field => $mapping) {
				if ($field === 'image') continue;
				
				$value = self::get_mapped_value($mapping, $post);
				if (!empty($value)) {
					$preview_data[$field] = $value;
				}
			}

			$preview_data['mainEntityOfPage'] = array(
				'@type' => 'WebPage',
				'@id'   => get_permalink($post),
			);
		}

		// ✅ FIXED: Only add publisher for schema types that support it
		// LocalBusiness and Corporation do NOT support publisher property
		$schema_types_without_publisher = array('localbusiness', 'corporation', 'organization', 'person', 'product');
		
		if (!in_array(strtolower($schema_type), $schema_types_without_publisher)) {
			$site_icon_url = get_site_icon_url() ?: '';
			$preview_data['publisher'] = array(
				'@type' => 'Organization',
				'name'  => get_bloginfo('name'),
				'logo'  => array(
					'@type' => 'ImageObject',
					'url'   => $site_icon_url,
				),
			);
		}

		return $preview_data;
	}
	/**
	 * Get mapped value for field.
	 *
	 * @param string $mapping Field mapping.
	 * @param object $post    Post object.
	 * @return string Field value.
	 */
	private static function get_mapped_value( $mapping, $post ) {
		if ( empty( $mapping ) ) {
			return '';
		}

		// ✅ Handle special image sources
		if ( $mapping === 'featured_image' ) {
			if ( $post && isset( $post->ID ) ) {
				$image_url = get_the_post_thumbnail_url( $post->ID, 'full' );
				return $image_url ?: '';
			}
			return '';
		}

		if ( $mapping === 'site_logo' ) {
			// ✅ FIX: Use the same method as get_site_logo() for consistency
			return self::get_site_logo();
		}

		if ( strpos( $mapping, 'post:' ) === 0 ) {
			$field = str_replace( 'post:', '', $mapping );

			switch ( $field ) {
				case 'post_title':
					return $post ? get_the_title( $post ) : '';

				case 'post_content':
					if ( ! $post ) return '';
					$content = get_the_content( null, false, $post );
					return wp_strip_all_tags( $content );

				case 'post_excerpt':
					if ( ! $post ) return '';
					$excerpt = get_the_excerpt( $post );
					return $excerpt ?: wp_trim_words( get_the_content( null, false, $post ), 55 );

				case 'post_date':
					return $post ? get_the_date( DATE_W3C, $post ) : '';

				case 'featured_image':
					if ( $post && isset( $post->ID ) ) {
						$image_url = get_the_post_thumbnail_url( $post->ID, 'full' );
						return $image_url ?: '';
					}
					return '';

				case 'author_name':
					if ( $post && isset( $post->post_author ) ) {
						return get_the_author_meta( 'display_name', $post->post_author );
					}
					return '';

				case 'author_url':
					if ( $post && isset( $post->post_author ) ) {
						return get_author_posts_url( $post->post_author );
					}
					return '';

				default:
					if ( $post && isset( $post->ID ) ) {
						return get_post_meta( $post->ID, $field, true ) ?: '';
					}
					return '';
			}
		}

		if ( strpos( $mapping, 'site:' ) === 0 ) {
			$field = str_replace( 'site:', '', $mapping );

			switch ( $field ) {
				case 'site_name':
					return get_bloginfo( 'name' );
				case 'site_description':
					return get_bloginfo( 'description' );
				case 'site_url':
					return home_url();
				case 'logo_url':
					$custom_logo_id = get_theme_mod( 'custom_logo' );
					return $custom_logo_id ? wp_get_attachment_url( $custom_logo_id ) : '';
				case 'admin_email':
					return get_option( 'admin_email' );
				default:
					return '';
			}
		}

		if ( strpos( $mapping, 'custom:' ) === 0 ) {
			return str_replace( 'custom:', '', $mapping );
		}

		return $mapping;
	}

	/**
     * Get FAQ configuration - UPDATED: Read from postmeta instead of options
     */
	public static function srk_get_faq_configuration() {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_send_json_error( array( 'message' => 'Unauthorized' ) );
		}

		// Get FAQ posts from postmeta
		$faq_posts = get_posts(
			array(
				'post_type'      => 'any',
				'meta_key'       => 'srk_selected_schema_type',
				'meta_value'     => 'faq',
				'posts_per_page' => 1,
				'fields'         => 'ids',
			)
		);

		if ( empty( $faq_posts ) ) {
			wp_send_json_success( array( 'configured' => false ) );
		}

		$post_id   = $faq_posts[0];
		$post_type = get_post_type( $post_id );
		$faq_items = get_post_meta( $post_id, 'srk_faq_items', true );

		wp_send_json_success(
			array(
				'configured' => true,
				'post_type'  => $post_type,
				'post_id'    => $post_id,
				'faq_items'  => is_array( $faq_items ) ? $faq_items : array(),
			)
		);
	}

	/**
	 * Get global options.
	 */
	public static function srk_get_global_options() {
		// ✅ SECURITY FIX: Verify user capability
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_send_json_error( array( 'message' => 'Unauthorized' ) );
		}


		try {
			$custom_logo_id = get_theme_mod( 'custom_logo' );
			$logo_url       = $custom_logo_id ? wp_get_attachment_image_url( $custom_logo_id, 'full' ) : '';
			$site_icon_url  = get_site_icon_url( 512 ) ?: '';
			$home_url       = home_url();

			$options = array(
				// Core site info – fully dynamic, no hard-coded example values.
				'site_name'              => get_bloginfo( 'name' ) ?: '',
				'site_url'               => $home_url ?: '',
				'site_description'       => get_bloginfo( 'description' ) ?: '',
				'logo_url'               => $logo_url ?: $site_icon_url ?: '',
				'admin_email'            => get_option( 'admin_email' ) ?: '',

				// Business / contact fields – populated from plugin options only.
				'telephone'              => get_option( 'srk_global_telephone', '' ),
				'opening_hours'          => get_option( 'srk_global_opening_hours', '' ),
				'price_range'            => get_option( 'srk_global_price_range', '' ),
				'address'                => get_option( 'srk_global_address', '' ),
				'contact_point'          => get_option( 'srk_global_contact_point', '' ),
				'social_profiles'        => get_option( 'srk_global_social_profiles', '[]' ),
				'founder'                => get_option( 'srk_global_founder', '' ),
				'founding_date'          => get_option( 'srk_global_founding_date', '' ),
				'under_name'             => get_option( 'srk_global_under_name', '' ),
				'reservation_for'        => get_option( 'srk_global_reservation_for', '' ),
				'start_time'             => get_option( 'srk_global_start_time', '' ),
				'party_size'             => get_option( 'srk_global_party_size', '' ),
				'item_reviewed'          => get_option( 'srk_global_item_reviewed', '' ),
				'author_name'            => get_option( 'srk_global_author_name', '' ),
				'review_body'            => get_option( 'srk_global_review_body', '' ),
				'review_rating'          => get_option( 'srk_global_review_rating', '' ),
				'date_published'         => get_option( 'srk_global_date_published', '' ),
				'price'                  => get_option( 'srk_global_price', '' ),
				'price_currency'         => get_option( 'srk_global_price_currency', 'USD' ),
				'availability'           => get_option( 'srk_global_availability', 'https://schema.org/InStock' ),
				'item_condition'         => get_option( 'srk_global_item_condition', 'https://schema.org/NewCondition' ),
				'valid_from'             => get_option( 'srk_global_valid_from', '' ),
				'rating_value'           => get_option( 'srk_global_rating_value', '' ),
				'review_count'           => get_option( 'srk_global_review_count', '' ),
				'best_rating'            => get_option( 'srk_global_best_rating', '5' ),
				'worst_rating'           => get_option( 'srk_global_worst_rating', '1' ),
				'about'                  => get_option( 'srk_global_about', '' ),
				'medical_specialty'      => get_option( 'srk_global_medical_specialty', '' ),
				'possible_treatment'     => get_option( 'srk_global_possible_treatment', '' ),
				'possible_complication'  => get_option( 'srk_global_possible_complication', '' ),
				'sign_or_symptom'        => get_option( 'srk_global_sign_or_symptom', '' ),
				'website_url'            => $home_url ?: '',
				'search_action'          => $home_url ? $home_url . '/?s={search_term_string}' : '',
				'latitude'               => get_option( 'srk_global_latitude', '' ),
				'longitude'              => get_option( 'srk_global_longitude', '' ),
				'keywords'               => get_option( 'srk_global_keywords', '' ),
				'alternate_name'         => get_option( 'srk_global_alternate_name', '' ),
				'itemListElement'        => wp_json_encode(
					array(
						array(
							'position' => 1,
							'name'     => 'Home',
							'item'     => $home_url ?: '',
						),
					)
				),
			);

			$custom_fields = apply_filters( 'srk_global_custom_fields', array() );

			wp_send_json_success(
				array(
					'options'       => $options,
					'custom_fields' => $custom_fields,
				)
			);

		} catch ( Exception $e ) {
			wp_send_json_success(
				array(
					'options' => array(
						'site_name'        => '',
						'site_description' => '',
						'site_url'         => home_url() ?: '',
						'logo_url'         => get_site_icon_url( 512 ) ?: '',
						'admin_email'      => '',
						'telephone'        => '',
						'address'          => '',
						'opening_hours'    => '',
						'price_range'      => '',
						'contact_point'    => '',
					),
					'custom_fields' => array(),
				)
			);
		}
	}

	/**
	 * Get post types.
	 */
	public static function srk_get_post_types() {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_send_json_error( array( 'message' => 'Unauthorized' ) );
		}

		$post_types = get_post_types( array( 'public' => true ), 'objects' );

		if ( empty( $post_types ) ) {
			wp_send_json_error();
		}

		$options_html = '';
		foreach ( $post_types as $slug => $type ) {
			$options_html .= sprintf(
				'<option value="%s">%s</option>',
				esc_attr( $slug ),
				esc_html( $type->labels->name )
			);
		}

		wp_send_json_success( $options_html );
	}

	/**
	 * Get schema configuration.
	 */
	public static function srk_get_schema_configuration() {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_send_json_error( array( 'message' => 'Unauthorized' ) );
		}

		$schema = isset( $_POST['schema'] ) ? sanitize_text_field( wp_unslash( $_POST['schema'] ) ) : '';

		if ( empty( $schema ) ) {
			wp_send_json_error( array( 'message' => 'Schema is required.' ) );
		}

		$option_key      = "srk_schema_assignment_{$schema}";
		$existing_config = get_option( $option_key );

		// Normalize older JSON-encoded options into arrays for compatibility.
		if ( is_string( $existing_config ) ) {
			$decoded = json_decode( $existing_config, true );
			if ( JSON_ERROR_NONE === json_last_error() && is_array( $decoded ) ) {
				$existing_config = $decoded;
			} else {
				$existing_config = array();
			}
		}

		if ( $existing_config && ! empty( $existing_config ) ) {
			$response = array(
				'configured'    => true,
				'post_type'     => $existing_config['post_type'] ?? 'global',
				'meta_map'      => $existing_config['meta_map'] ?? array(),
				'enabled_fields' => $existing_config['enabled_fields'] ?? array(), // Make sure this is returned
				'selected_post' => $existing_config['selected_post'] ?? '',
			);
			
			// ✅ Add authorType for author schema
			if ( 'author' === $schema && isset( $existing_config['authorType'] ) ) {
				$response['authorType'] = $existing_config['authorType'];
			}
			
			wp_send_json_success( $response );
		} else {
			wp_send_json_success( array( 'configured' => false ) );
		}
	}

	/**
	 * Get schema assignment.
	 */
	public static function srk_get_schema_assignment() {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_send_json_error( array( 'message' => 'Unauthorized' ) );
		}

		$schema = isset( $_POST['schema'] ) ? sanitize_text_field( wp_unslash( $_POST['schema'] ) ) : '';

		if ( empty( $schema ) ) {
			wp_send_json_error( array( 'message' => 'Schema is required.' ) );
		}

		$option_key      = "srk_schema_assignment_{$schema}";
		$existing_config = get_option( $option_key );

		// Normalize older JSON-encoded options into arrays for compatibility.
		if ( is_string( $existing_config ) ) {
			$decoded = json_decode( $existing_config, true );
			if ( JSON_ERROR_NONE === json_last_error() && is_array( $decoded ) ) {
				$existing_config = $decoded;
			} else {
				$existing_config = array();
			}
		}

		wp_send_json_success(
			array(
				'configured'     => ! empty( $existing_config ),
				'post_type'      => $existing_config['post_type'] ?? '',
				'meta_map'       => $existing_config['meta_map'] ?? array(),
				'enabled_fields' => $existing_config['enabled_fields'] ?? array(),
			)
		);
	}

	/**
	 * Get post related fields.
	 */
	public static function srk_get_post_related_fields() {
		global $wpdb;

		if ( ! current_user_can( 'manage_options' ) ) {
			wp_send_json_error( array( 'message' => 'Unauthorized' ) );
		}

		$post_type = isset( $_POST['post_type'] ) ? sanitize_text_field( wp_unslash( $_POST['post_type'] ) ) : '';

		if ( empty( $post_type ) ) {
			wp_send_json_error( array( 'message' => 'Invalid post type' ) );
		}

		// ✅ PERFORMANCE: Cache key for transient
		$cache_key = 'srk_fields_' . md5( $post_type );
		$cached = get_transient( $cache_key );
		
		if ( false !== $cached ) {
			wp_send_json_success( $cached );
		}

		$post_defaults = array(
			'post_title'    => 'Post Title',
			'post_excerpt'  => 'Post Excerpt',
			'post_content'  => 'Post Content',
			'post_date'     => 'Post Date',
			'featured_image' => 'Site Logo',
			'post_author'   => 'Post Author (ID)',
			'author_name'   => 'Author Name',
			'author_url'    => 'Author URL',
		);

		// ✅ PERFORMANCE: Optimized query with LIMIT and better indexing
		// Use EXISTS subquery for better performance
		$public_meta = $wpdb->get_col(
			$wpdb->prepare(
				"SELECT DISTINCT pm.meta_key 
				FROM {$wpdb->postmeta} pm
				WHERE EXISTS (
					SELECT 1 FROM {$wpdb->posts} p 
					WHERE p.ID = pm.post_id 
					AND p.post_type = %s 
					AND p.post_status != 'auto-draft'
				)
				AND pm.meta_key NOT LIKE '\\_%'
				LIMIT 500",
				$post_type
			)
		);

		// ✅ PERFORMANCE: Optimized protected meta query
		$protected_meta = $wpdb->get_col(
			$wpdb->prepare(
				"SELECT DISTINCT pm.meta_key 
				FROM {$wpdb->postmeta} pm
				WHERE EXISTS (
					SELECT 1 FROM {$wpdb->posts} p 
					WHERE p.ID = pm.post_id 
					AND p.post_type = %s 
					AND p.post_status != 'auto-draft'
				)
				AND pm.meta_key LIKE '\\_%'
				LIMIT 500",
				$post_type
			)
		);

		$all_meta  = array_merge( $public_meta ?: array(), $protected_meta ?: array() );
		$post_meta = array_combine( $all_meta, $all_meta );

		// ✅ PERFORMANCE: Limit user meta keys and cache them separately
		$user_meta_cache_key = 'srk_user_meta_keys';
		$user_meta_keys = get_transient( $user_meta_cache_key );
		
		if ( false === $user_meta_keys ) {
			$user_meta_keys = $wpdb->get_col(
				"SELECT DISTINCT meta_key FROM {$wpdb->usermeta}
				WHERE meta_key NOT LIKE '\\_%'
				LIMIT 500"
			);
			// Cache for 1 hour
			set_transient( $user_meta_cache_key, $user_meta_keys, HOUR_IN_SECONDS );
		}

		$user_meta = array();
		foreach ( $user_meta_keys as $key ) {
			$user_meta[ $key ] = $key;
		}

		$taxonomies  = array();
		$tax_objects = get_object_taxonomies( $post_type, 'objects' );
		foreach ( $tax_objects as $tax_obj ) {
			$taxonomies[ $tax_obj->name ] = $tax_obj->label;
		}

		$result = array(
			'post_defaults' => $post_defaults,
			'post_meta'     => $post_meta,
			'user_meta'     => $user_meta,
			'taxonomies'    => $taxonomies,
		);

		// ✅ PERFORMANCE: Cache result for 5 minutes
		set_transient( $cache_key, $result, 5 * MINUTE_IN_SECONDS );

		wp_send_json_success( $result );
	}

	/**
	 * Get assigned schemas.
	 */
	public static function srk_get_assigned_schemas() {
		global $wpdb;

		if ( ! current_user_can( 'manage_options' ) ) {
			wp_send_json_error( array( 'message' => 'Unauthorized' ) );
		}

		$assigned = array();
		$options  = $wpdb->get_results(
			"SELECT option_name, option_value FROM {$wpdb->options}
			WHERE option_name LIKE 'srk_schema_assignment_%'"
		);

		foreach ( $options as $option ) {
			$data = maybe_unserialize( $option->option_value );
			$assigned[] = array(
				'type'      => str_replace( 'srk_schema_assignment_', '', $option->option_name ),
				'post_type' => $data['post_type'] ?? '',
				'meta_map'  => $data['meta_map'] ?? array(),
			);
		}

		wp_send_json_success( $assigned );
	}

	/**
	 * Check if license plan is expired.
	 *
	 * @since 2.1.0
	 * @return bool True if expired, false otherwise.
	 */
	private static function is_license_expired() {
		// Use the centralized helper if available
		if ( class_exists( 'SRK_License_Helper' ) ) {
			return SRK_License_Helper::is_license_expired();
		}

		// Fallback to direct check if helper not loaded
		if ( ! class_exists( 'SeoRepairKit_Admin' ) ) {
			return true;
		}

		$admin = new SeoRepairKit_Admin( '', '' );
		$license_info = $admin->get_license_status( site_url() );

		// Check if license is inactive
		if ( empty( $license_info['status'] ) || 'active' !== $license_info['status'] ) {
			return true;
		}

		// Check if expiration date exists and is expired
		$expiration = $license_info['expires_at'] ?? null;
		if ( $expiration ) {
			$expires_ts = strtotime( $expiration );
			if ( $expires_ts ) {
				$now = current_time( 'timestamp' );
				$days_left = floor( ( $expires_ts - $now ) / DAY_IN_SECONDS );
				return $days_left < 0; // Expired if days_left is negative
			}
		}

		return false;
	}

	/**
	 * Save schema assignment.
	 */
	public static function srk_save_schema_assignment() {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_send_json_error( array( 'message' => 'Unauthorized' ) );
		}

		$schema    = isset( $_POST['schema'] ) ? sanitize_text_field( wp_unslash( $_POST['schema'] ) ) : '';
		$post_type = isset( $_POST['post_type'] ) ? sanitize_text_field( wp_unslash( $_POST['post_type'] ) ) : '';
		$meta_map  = isset( $_POST['meta_map'] ) && is_array( $_POST['meta_map'] ) ? wp_unslash( $_POST['meta_map'] ) : array(); // phpcs:ignore WordPress.Security.ValidatedSanitizedInput
		$enabled_fields = isset( $_POST['enabled_fields'] ) && is_array( $_POST['enabled_fields'] ) ? array_map( 'sanitize_text_field', wp_unslash( $_POST['enabled_fields'] ) ) : array();

		if ( empty( $schema ) ) {
			wp_send_json_error( array( 'message' => 'Schema is required.' ) );
		}

		if ( empty( $post_type ) ) {
			wp_send_json_error( array( 'message' => 'Post type is required.' ) );
		}

		// FAQ schema handling - save to all posts of the post type
		if ( 'faq' === $schema ) {
			$faq_items = isset( $_POST['faq_items'] ) && is_array( $_POST['faq_items'] ) ? wp_unslash( $_POST['faq_items'] ) : array(); // phpcs:ignore WordPress.Security.ValidatedSanitizedInput

			if ( empty( $faq_items ) ) {
				wp_send_json_error( array( 'message' => 'No FAQ items found.' ) );
			}

			// Save FAQ items to all posts of the selected post type
			$posts = get_posts(
				array(
					'post_type'      => $post_type,
					'posts_per_page' => -1,
					'fields'         => 'ids',
				)
			);

			foreach ( $posts as $post_id ) {
				update_post_meta( $post_id, 'faq_items', $faq_items );
				update_post_meta( $post_id, 'srk_selected_schema_type', 'faq' );
			}

			wp_send_json_success(
				array(
					'message' => 'FAQ schema saved successfully!',
				)
			);
		}

		// Basic sanitization for meta_map
		$validated_map = array();
		foreach ( $meta_map as $key => $value ) {
			$validated_map[ sanitize_text_field( $key ) ] = sanitize_text_field( $value );
		}

		// Enforce Google guideline: only ONE article-type schema per post type (Article / BlogPosting / NewsArticle).
		// The most specific type should be used. We always let the "last saved" schema win.
		$article_schemas = array( 'article', 'blog_posting', 'news_article' );
		if ( in_array( $schema, $article_schemas, true ) && 'global' !== $post_type ) {
			foreach ( $article_schemas as $article_key ) {
				if ( $article_key === $schema ) {
					continue;
				}

				$other_option_key = "srk_schema_assignment_{$article_key}";
				$other_config     = get_option( $other_option_key );

				// Normalize potential JSON-encoded config.
				if ( is_string( $other_config ) ) {
					$decoded = json_decode( $other_config, true );
					if ( JSON_ERROR_NONE === json_last_error() && is_array( $decoded ) ) {
						$other_config = $decoded;
					} else {
						$other_config = array();
					}
				}

				// If another article-type schema is mapped to the same post type, clear its post_type
				// so it no longer claims that post type in the UI, but keep its field mappings.
				if ( ! empty( $other_config ) && isset( $other_config['post_type'] ) && $other_config['post_type'] === $post_type ) {
					$other_config['post_type'] = '';
					update_option( $other_option_key, $other_config );
				}
			}
		}

		// ✅ Handle authorType for author schema
		$author_type = 'Person'; // Default
		if ( $schema === 'author' && isset( $_POST['authorType'] ) ) {
			$author_type = sanitize_text_field( wp_unslash( $_POST['authorType'] ) );
			// Validate authorType
			if ( ! in_array( $author_type, array( 'Person', 'Organization' ), true ) ) {
				$author_type = 'Person'; // Fallback to default
			}
		}

		$option_data = array(
			'schema_type' => $schema,
			'post_type'   => $post_type,
			'meta_map'    => $validated_map,
			'enabled_fields' => $enabled_fields,
		);

		// Add authorType for author schema
		if ( $schema === 'author' ) {
			$option_data['authorType'] = $author_type;
		}

		$option_key = "srk_schema_assignment_{$schema}";
		update_option( $option_key, $option_data );
		
		// Clear schema count cache when schema is saved/updated
		delete_transient( 'srk_schema_count' );

		if ( 'global' !== $post_type ) {
			$posts = get_posts(
				array(
					'post_type'      => $post_type,
					'posts_per_page' => -1,
					'fields'         => 'ids',
				)
			);

			foreach ( $posts as $post_id ) {
				update_post_meta( $post_id, 'selected_schema_type', $schema );

				foreach ( $meta_map as $field_key => $mapping_value ) {
					if ( empty( $enabled_fields ) || in_array( $field_key, $enabled_fields ) ) {
						$actual_value = '';

						if ( strpos( $mapping_value, ':' ) !== false ) {
							list($source, $meta_key) = explode( ':', $mapping_value, 2 );
						} else {
							$source    = 'post';
							$meta_key  = $mapping_value;
						}

						switch ( $source ) {
							case 'post':
								switch ( $meta_key ) {
									case 'post_title':
										$actual_value = get_the_title( $post_id );
										break;
									case 'post_excerpt':
										$actual_value = get_the_excerpt( $post_id );
										break;
									case 'featured_image':
										$image_id     = get_post_thumbnail_id( $post_id );
										$actual_value = $image_id ? wp_get_attachment_url( $image_id ) : '';
										break;
									case 'post_author':
										$author_id    = get_post_field( 'post_author', $post_id );
										$actual_value = get_the_author_meta( 'display_name', $author_id );
										break;
									case 'post_author_id':
										$actual_value = get_post_field( 'post_author', $post_id );
										break;
									case 'post_date':
										$actual_value = get_the_date( DATE_W3C, $post_id );
										break;
									case 'post_content':
										$actual_value = apply_filters( 'the_content', get_post_field( 'post_content', $post_id ) );
										break;
									default:
										$actual_value = get_post_meta( $post_id, $meta_key, true );
										break;
								}
								break;

							case 'meta':
								$actual_value = get_post_meta( $post_id, $meta_key, true );
								break;

							case 'user':
								$author_id    = get_post_field( 'post_author', $post_id );
								$actual_value = get_user_meta( $author_id, $meta_key, true );
								break;

							case 'tax':
								$terms        = wp_get_post_terms( $post_id, $meta_key, array( 'fields' => 'names' ) );
								$actual_value = ! empty( $terms ) ? implode( ', ', $terms ) : '';
								break;

							case 'site':
								$site_data    = self::get_site_data();
								$actual_value = isset( $site_data[ $meta_key ] ) ? $site_data[ $meta_key ] : '';
								break;

							case 'custom':
								$actual_value = $meta_key;
								break;
						}

						update_post_meta( $post_id, 'schema_field_' . $field_key, $actual_value );
					}
				}
			}
		}

		wp_send_json_success(
			array(
				'message'        => 'Schema mapping and post meta saved successfully!',
				'formatted_name' => ucwords( str_replace( '_', ' ', $schema ) ),
			)
		);
	}

    /**
	 * Generate schema data from mappings with field enable/disable support - FIXED VERSION
	 */
	public static function generate_schema_data( $schema_type, $meta_map, $post = null ) {
		$schema_config = get_option( "srk_schema_assignment_{$schema_type}" );
		
		// Check if we have enabled_fields in configuration
		$enabled_fields = isset( $schema_config['enabled_fields'] ) ? $schema_config['enabled_fields'] : array();
		
		$schema_data = array(
			'@context' => 'https://schema.org',
			'@type' => ucfirst( $schema_type ),
		);

		$has_data = false;

		foreach ( $meta_map as $field => $mapping ) {
			// Skip if field is not enabled
			if ( ! empty( $enabled_fields ) && ! in_array( $field, $enabled_fields ) ) {
				continue;
			}
			
			$value = self::get_mapped_value( $mapping, $post );
			
			if ( ! empty( $value ) ) {
				$has_data = true;
				
				switch ( $field ) {
					case 'address':
						$schema_data[ $field ] = array(
							'@type' => 'PostalAddress',
							'streetAddress' => $value
						);
						break;

					case 'image':
						$schema_data[ $field ] = array(
							'@type' => 'ImageObject',
							'url' => $value
						);
						break;

					case 'openingHours':
						if ( is_string( $value ) ) {
							// Convert to array for multiple opening hours
							$hours_array = array_map( 'trim', explode( ',', $value ) );
							$hours_array = array_filter( $hours_array, function( $hour ) {
								return ! empty( $hour ) && $hour !== '';
							} );
							$schema_data[ $field ] = ! empty( $hours_array ) ? $hours_array : array( $value );
						} else {
							$schema_data[ $field ] = $value;
						}
						break;

					case 'latitude':
					case 'longitude':
						if ( ! isset( $schema_data['geo'] ) ) {
							$schema_data['geo'] = array( '@type' => 'GeoCoordinates' );
						}
						$schema_data['geo'][ $field ] = floatval( $value );
						break;

					case 'keywords':
						if ( is_string( $value ) ) {
							$keywords_array = array_map( 'trim', explode( ',', $value ) );
							$keywords_array = array_filter( $keywords_array, function( $keyword ) {
								return ! empty( $keyword ) && $keyword !== '';
							} );
							if ( ! empty( $keywords_array ) ) {
								$schema_data[ $field ] = $keywords_array;
							}
						} elseif ( is_array( $value ) ) {
							$schema_data[ $field ] = $value;
						}
						break;

					case 'alternateName':
						$schema_data[ $field ] = $value;
						break;

					default:
						$schema_data[ $field ] = $value;
						break;
				}
			}
		}

		// Remove geo object if incomplete
		if ( isset( $schema_data['geo'] ) && ( empty( $schema_data['geo']['latitude'] ) || empty( $schema_data['geo']['longitude'] ) ) ) {
			unset( $schema_data['geo'] );
		}

		// Remove empty keywords array
		if ( isset( $schema_data['keywords'] ) && empty( $schema_data['keywords'] ) ) {
			unset( $schema_data['keywords'] );
		}

		return $has_data ? $schema_data : false;
	}

	/**
	 * Get site data.
	 *
	 * @return array Site data.
	 */
	public static function get_site_data() {
		$custom_logo_id = get_theme_mod( 'custom_logo' );
		$logo_url       = $custom_logo_id ? wp_get_attachment_url( $custom_logo_id ) : '';

		return array(
			'site_name'        => get_bloginfo( 'name' ),
			'site_description' => get_bloginfo( 'description' ),
			'site_url'         => home_url(),
			'logo_url'         => $logo_url,
			'admin_email'      => get_option( 'admin_email' ),
			'telephone'        => get_option( 'srk_global_telephone', '' ),
			'opening_hours'    => get_option( 'srk_global_opening_hours', '' ),
			'price_range'      => get_option( 'srk_global_price_range', '' ),
			'address'          => get_option( 'srk_global_address', '' ),
			'contact_point'    => get_option( 'srk_global_contact_point', '' ),
			'facebook_url'     => get_option( 'srk_global_facebook_url', '' ),
			'twitter_url'      => get_option( 'srk_global_twitter_url', '' ),
			'instagram_url'    => get_option( 'srk_global_instagram_url', '' ),
			'youtube_url'      => get_option( 'srk_global_youtube_url', '' ),
			'founder'          => get_option( 'srk_global_founder', '' ),
			'founding_date'    => get_option( 'srk_global_founding_date', '' ),
			'rating_value'     => get_option( 'srk_global_rating_value', '' ),
			'review_count'     => get_option( 'srk_global_review_count', '' ),
			'best_rating'      => get_option( 'srk_global_best_rating', '5' ),
			'worst_rating'     => get_option( 'srk_global_worst_rating', '1' ),
		);
	}

	/**
	 * Check plugin dependencies for schema types
	 * 
	 * @since 2.1.0
	 */
	public function check_plugin_dependencies() {
		check_ajax_referer('srk_schema_nonce', 'nonce');
		
		$schema_type = sanitize_text_field($_POST['schema_type']);
		$response = [
			'has_dependencies' => false,
			'message' => '',
			'plugins' => []
		];
		
		$dependencies = [
			'event' => [
				'message' => 'Event schema requires an events plugin. Recommended plugins:',
				'plugins' => [
					[
						'slug' => 'events-manager/events-manager.php',
						'name' => 'Events Manager',
						'installed' => $this->is_plugin_installed('events-manager/events-manager.php'),
						'active' => $this->is_plugin_active('events-manager/events-manager.php')
					],
					[
						'slug' => 'the-events-calendar/the-events-calendar.php',
						'name' => 'The Events Calendar',
						'installed' => $this->is_plugin_installed('the-events-calendar/the-events-calendar.php'),
						'active' => $this->is_plugin_active('the-events-calendar/the-events-calendar.php')
					]
				]
			],
			// Add other schema types...
		];
		
		if (isset($dependencies[$schema_type])) {
			$dependency = $dependencies[$schema_type];
			$response['has_dependencies'] = true;
			$response['message'] = $dependency['message'];
			$response['plugins'] = $dependency['plugins'];
			$response['guide_url'] = '';
		}
		
		wp_send_json_success($response);
	}

	/**
	 * Check if a plugin is installed
	 * 
	 * @since 2.1.0
	 */
	private function is_plugin_installed($plugin_slug) {
		$installed_plugins = get_plugins();
		return isset($installed_plugins[$plugin_slug]);
	}

	/**
	 * Check if a plugin is active
	 * 
	 * @since 2.1.0
	 */
	private function is_plugin_active($plugin_slug) {
		return is_plugin_active($plugin_slug);
	}
	/**
	 * Save FAQ schema assignment - UPDATED: Only save to postmeta, NOT options
	 */
	public static function srk_save_schema_assignment_faq() {
		// ✅ SECURITY FIX: Verify user capability
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_send_json_error( array( 'message' => 'Unauthorized' ) );
		}



		$schema    = isset( $_POST['schema'] ) ? sanitize_text_field( wp_unslash( $_POST['schema'] ) ) : '';
		$post_type = isset( $_POST['post_type'] ) ? sanitize_text_field( wp_unslash( $_POST['post_type'] ) ) : '';
		$post_id   = isset( $_POST['post_id'] ) ? intval( $_POST['post_id'] ) : 0;
		$faq_items = isset( $_POST['faq_items'] ) ? (array) wp_unslash( $_POST['faq_items'] ) : array(); // phpcs:ignore WordPress.Security.ValidatedSanitizedInput

		// ✅ SECURITY FIX: Validate schema type
		if ( 'faq' !== $schema ) {
			wp_send_json_error( array( 'message' => 'Invalid schema type for FAQ handler.' ) );
		}


		// ✅ SECURITY FIX: Validate post ID
		if ( $post_id <= 0 ) {
			wp_send_json_error( array( 'message' => 'Valid post ID is required.' ) );
		}

		// ✅ SECURITY FIX: Verify post exists and belongs to correct post type
		$post = get_post( $post_id );
		if ( ! $post || $post->post_type !== $post_type ) {
			wp_send_json_error( array( 'message' => 'Invalid post ID or post type mismatch.' ) );
		}

		if ( empty( $faq_items ) ) {
			wp_send_json_error( array( 'message' => 'No FAQ items found' ) );
		}

		// ✅ SECURITY FIX: Sanitize FAQ items
		$sanitized_faq_items = array();
		foreach ( $faq_items as $item ) {
			if ( ! empty( $item['question'] ) && ! empty( $item['answer'] ) ) {
				$sanitized_faq_items[] = array(
					'question' => sanitize_text_field( $item['question'] ),
					'answer'   => wp_kses_post( $item['answer'] ), // Allow safe HTML in answers
				);
			}
		}

		if ( empty( $sanitized_faq_items ) ) {
			wp_send_json_error( array( 'message' => 'No valid FAQ items found.' ) );
		}

		// ✅ UPDATED: Only save to postmeta, NOT options
		update_post_meta( $post_id, 'srk_faq_items', $sanitized_faq_items );
		update_post_meta( $post_id, 'faq_items', $sanitized_faq_items ); // Keep for backward compatibility
		update_post_meta( $post_id, 'srk_selected_schema_type', 'faq' );
		
		wp_send_json_success( array( 'message' => 'FAQ Schema saved successfully to postmeta only' ) );
	}

	/**
	 * Get posts by type.
	 */
	public static function srk_get_posts_by_type() {
		// ✅ SECURITY FIX: Verify user capability
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_send_json_error( array( 'message' => 'Unauthorized' ) );
		}

		$post_type = isset( $_POST['post_type'] ) ? sanitize_text_field( wp_unslash( $_POST['post_type'] ) ) : '';

		if ( empty( $post_type ) ) {
			wp_send_json_error( array( 'message' => 'No post type provided' ) );
		}

		// ✅ PERFORMANCE: Cache key for transient
		$cache_key = 'srk_posts_' . md5( $post_type );
		$cached = get_transient( $cache_key );
		
		if ( false !== $cached ) {
			// Check if format parameter is set (for backward compatibility)
			$format = isset( $_POST['format'] ) ? sanitize_text_field( wp_unslash( $_POST['format'] ) ) : 'legacy';
			if ( 'legacy' === $format ) {
				wp_send_json_success( $cached );
			} else {
				wp_send_json_success( $cached );
			}
		}

		// ✅ PERFORMANCE: Limit posts to 500 for better performance
		$posts = get_posts(
			array(
				'post_type'   => $post_type,
				'numberposts' => 500, // Limit to 500 posts
				'post_status' => 'publish',
			)
		);

		if ( empty( $posts ) ) {
			// Cache empty result
			set_transient( $cache_key, array(), 5 * MINUTE_IN_SECONDS );
			wp_send_json_success( array() );
		}

		// Check if format parameter is set (for backward compatibility)
		// Default to 'legacy' for backward compatibility with existing code
		$format = isset( $_POST['format'] ) ? sanitize_text_field( wp_unslash( $_POST['format'] ) ) : 'legacy';

		$data = array();
		foreach ( $posts as $post ) {
			if ( 'array' === $format ) {
				// New format: array of objects (for bulk operations)
				$data[] = array(
					'ID'         => $post->ID,
					'post_title' => $post->post_title,
					'post_type'  => $post->post_type,
				);
			} else {
				// Legacy format: ID => title (default for backward compatibility)
				$data[ $post->ID ] = $post->post_title;
			}
		}

		// ✅ PERFORMANCE: Cache result for 5 minutes
		set_transient( $cache_key, $data, 5 * MINUTE_IN_SECONDS );

		wp_send_json_success( $data );
	}

	/**
	 * Validate schema configuration
	 *
	 * @since 2.1.0
	 */
	public static function srk_validate_schema() {
		// ✅ SECURITY FIX: Verify user capability
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_send_json_error( array( 'message' => 'Unauthorized' ) );
		}


		// ✅ Check if license plan is expired

		$schema         = isset( $_POST['schema'] ) ? sanitize_text_field( wp_unslash( $_POST['schema'] ) ) : '';
		$meta_map       = isset( $_POST['meta_map'] ) && is_array( $_POST['meta_map'] ) ? wp_unslash( $_POST['meta_map'] ) : array(); // phpcs:ignore WordPress.Security.ValidatedSanitizedInput
		$enabled_fields = isset( $_POST['enabled_fields'] ) && is_array( $_POST['enabled_fields'] ) ? array_map( 'sanitize_text_field', wp_unslash( $_POST['enabled_fields'] ) ) : array();
		$schema_data   = isset( $_POST['schema_data'] ) && is_array( $_POST['schema_data'] ) ? wp_unslash( $_POST['schema_data'] ) : null; // phpcs:ignore WordPress.Security.ValidatedSanitizedInput

		if ( empty( $schema ) ) {
			wp_send_json_error( array( 'message' => 'Schema type is required.' ) );
		}


		if ( ! class_exists( 'SeoRepairKit_SchemaValidator' ) ) {
			wp_send_json_error( array( 'message' => 'Schema validator class not found.' ) );
		}

		$validator = new SeoRepairKit_SchemaValidator();
		$is_valid  = $validator->validate( $schema, $meta_map, $enabled_fields, $schema_data );

		$response = array(
			'valid'    => $is_valid,
			'errors'   => $validator->get_errors(),
			'warnings' => $validator->get_warnings(),
		);

		if ( $is_valid ) {
			wp_send_json_success( $response );
		} else {
			wp_send_json_error( $response );
		}
	}

	/**
	 * Get required fields for a schema type
	 *
	 * @since 2.1.0
	 */
	public static function srk_get_required_fields() {
		// ✅ SECURITY FIX: Verify user capability
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_send_json_error( array( 'message' => 'Unauthorized' ) );
		}


		$schema = isset( $_POST['schema'] ) ? sanitize_text_field( wp_unslash( $_POST['schema'] ) ) : '';

		if ( empty( $schema ) ) {
			wp_send_json_error( array( 'message' => 'Schema type is required.' ) );
		}


		if ( ! class_exists( 'SeoRepairKit_SchemaValidator' ) ) {
			wp_send_json_error( array( 'message' => 'Schema validator class not found.' ) );
		}

		$required_fields = SeoRepairKit_SchemaValidator::get_required_fields( $schema );

		wp_send_json_success( array(
			'required_fields' => $required_fields,
		) );
	}

	/**
	 * Get Google Rich Results Test URL for a specific page/post.
	 *
	 * @since 2.1.0
	 */
	public static function srk_get_google_test_url() {
		// ✅ SECURITY FIX: Verify user capability
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_send_json_error( array( 'message' => 'Unauthorized' ) );
		}


		// Check license

		$post_id   = isset( $_POST['post_id'] ) ? intval( $_POST['post_id'] ) : 0;
		$post_type = isset( $_POST['post_type'] ) ? sanitize_text_field( wp_unslash( $_POST['post_type'] ) ) : '';
		$schema    = isset( $_POST['schema'] ) ? sanitize_text_field( wp_unslash( $_POST['schema'] ) ) : '';

		// ✅ SECURITY FIX: Validate schema type if provided


		$test_url = '';
		$page_url = '';
		$posts    = array();

		if ( $post_id > 0 ) {
			// Get permalink for specific post
			$permalink = get_permalink( $post_id );
			if ( $permalink ) {
				// Google Rich Results Test URL format: https://search.google.com/test/rich-results?url=ENCODED_URL
				$test_url = 'https://search.google.com/test/rich-results?url=' . rawurlencode( $permalink );
				$page_url = $permalink;
			}
		} elseif ( ! empty( $post_type ) ) {
			// For post type assignments, get the first published post of that type
			$posts = get_posts(
				array(
					'post_type'      => $post_type,
					'post_status'    => 'publish',
					'posts_per_page' => 1,
					'orderby'        => 'date',
					'order'          => 'DESC',
				)
			);

			if ( ! empty( $posts ) ) {
				$permalink = get_permalink( $posts[0]->ID );
				if ( $permalink ) {
					$test_url = 'https://search.google.com/test/rich-results?url=' . rawurlencode( $permalink );
					$page_url = $permalink;
				}
			} else {
				// No posts found, use homepage
				$home_url = home_url( '/' );
				$test_url = 'https://search.google.com/test/rich-results?url=' . rawurlencode( $home_url );
				$page_url = $home_url;
			}
		} else {
			// Global schema or no specific post, use homepage
			$home_url = home_url( '/' );
			$test_url = 'https://search.google.com/test/rich-results?url=' . rawurlencode( $home_url );
			$page_url = $home_url;
		}

		if ( empty( $test_url ) ) {
			wp_send_json_error( array( 'message' => 'Unable to generate test URL. Please ensure you have published content.' ) );
		}

		wp_send_json_success(
			array(
				'test_url'  => $test_url,
				'page_url'  => $page_url,
				'post_id'   => $post_id,
				'post_type' => $post_type,
			)
		);
	}

	/**
	 * Test schema with Google Rich Results Test (for JSON-LD preview).
	 *
	 * @since 2.1.0
	 */
	public static function srk_test_schema_with_google() {
		// ✅ SECURITY FIX: Verify user capability
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_send_json_error( array( 'message' => 'Unauthorized' ) );
		}


		// Check license

		$schema_type = isset( $_POST['schema_type'] ) ? sanitize_text_field( wp_unslash( $_POST['schema_type'] ) ) : '';
		$json_ld     = isset( $_POST['json_ld'] ) ? wp_unslash( $_POST['json_ld'] ) : '';

		if ( empty( $schema_type ) ) {
			wp_send_json_error( array( 'message' => 'Schema type is required.' ) );
		}

		// ✅ SECURITY FIX: Validate schema type against whitelist

		// Validate JSON-LD
		if ( ! empty( $json_ld ) ) {
			$decoded = json_decode( $json_ld, true );
			if ( json_last_error() !== JSON_ERROR_NONE ) {
				wp_send_json_error( array( 'message' => 'Invalid JSON-LD format: ' . json_last_error_msg() ) );
			}
		}

		// For JSON-LD testing, we'll provide instructions to use Google's Rich Results Test
		// Since Google doesn't have a public API, we'll return a test URL and instructions
		$test_url = 'https://search.google.com/test/rich-results';

		wp_send_json_success(
			array(
				'test_url'     => $test_url,
				'json_ld'      => $json_ld,
				'schema_type'  => $schema_type,
				'message'      => 'To test your JSON-LD, copy it and paste it into Google Rich Results Test, or test it on a published page.',
				'instructions' => '1. Copy the JSON-LD preview above\n2. Go to Google Rich Results Test\n3. Select "Code" tab\n4. Paste your JSON-LD\n5. Click "Test URL"',
			)
		);
	}

	/**
	 * Apply schema to a single post (for bulk operations)
	 *
	 * @since 2.1.0
	 */

	/**
	 * Get site logo URL (helper method)
	 *
	 * @since 2.1.0
	 *
	 * @return string Logo URL.
	 */
	private static function get_site_logo() {
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

	// REMOVED: All security and validation helper methods - Reverted per user request
	// The following methods were removed (lines 1818-2396):
	// - build_schema_for_validation()
	// - get_mapped_value_for_validation()
	// - verify_ajax_nonce()
	// - get_allowed_schema_types()
	// - validate_schema_type()
	// - validate_post_type()
	// - sanitize_schema_field()
	// - sanitize_meta_map()
	
	/**
	 * Delete schema assignment.
	 *
	 * @since 2.1.0
	 * @return void
	 */
	public static function srk_delete_schema_assignment() {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_send_json_error( array( 'message' => 'Unauthorized' ) );
		}

		$schema = isset( $_POST['schema'] ) ? sanitize_text_field( wp_unslash( $_POST['schema'] ) ) : '';

		if ( empty( $schema ) ) {
			wp_send_json_error( array( 'message' => 'Schema is required.' ) );
		}

		// ✅ FIX: FAQ schema uses post meta only, not options - handle it separately
		if ( 'faq' === $schema ) {
			// Check if FAQ schema exists by looking for posts with FAQ meta
			$faq_posts = get_posts(
				array(
					'post_type'      => 'any',
					'meta_key'       => 'srk_selected_schema_type',
					'meta_value'     => 'faq',
					'posts_per_page' => 1,
					'fields'         => 'ids',
				)
			);
			
			if ( empty( $faq_posts ) ) {
				wp_send_json_error( array( 'message' => 'FAQ schema assignment not found.' ) );
			}
			
			// Remove FAQ schema meta from all posts
			global $wpdb;
			$wpdb->delete(
				$wpdb->postmeta,
				array( 'meta_key' => 'srk_selected_schema_type', 'meta_value' => 'faq' ),
				array( '%s', '%s' )
			);
			
			// Also delete FAQ items meta (remove from all posts that have it)
			$posts_with_faq = $wpdb->get_col(
				"SELECT DISTINCT post_id FROM {$wpdb->postmeta} WHERE meta_key IN ('srk_faq_items', 'faq_items')"
			);
			
			if ( ! empty( $posts_with_faq ) ) {
				foreach ( $posts_with_faq as $post_id ) {
					delete_post_meta( $post_id, 'srk_faq_items' );
					delete_post_meta( $post_id, 'faq_items' ); // Backward compatibility
				}
			}
			
			// Clear schema count cache
			delete_transient( 'srk_schema_count' );
			
			wp_send_json_success(
				array(
					'message' => 'FAQ schema assignment deleted successfully.',
				)
			);
			return;
		}

		// For other schemas, check and delete option
		$option_key = "srk_schema_assignment_{$schema}";
		
		// Get existing option to check if it exists
		$existing_option = get_option( $option_key );
		
		if ( empty( $existing_option ) ) {
			wp_send_json_error( array( 'message' => 'Schema assignment not found.' ) );
		}

		// Delete the option
		$deleted = delete_option( $option_key );

		if ( ! $deleted ) {
			wp_send_json_error( array( 'message' => 'Failed to delete schema assignment.' ) );
		}

		// Remove schema meta from posts of the assigned post type
		$existing_data = is_array( $existing_option ) ? $existing_option : ( is_string( $existing_option ) ? json_decode( $existing_option, true ) : array() );
		$post_type = isset( $existing_data['post_type'] ) ? $existing_data['post_type'] : '';
		
		if ( ! empty( $post_type ) && 'global' !== $post_type ) {
			// Remove schema meta from all posts of this post type
			$posts = get_posts(
				array(
					'post_type'      => $post_type,
					'posts_per_page' => -1,
					'fields'         => 'ids',
				)
			);
			
			foreach ( $posts as $post_id ) {
				delete_post_meta( $post_id, 'selected_schema_type' );
			}
		}

		// Clear schema count cache
		delete_transient( 'srk_schema_count' );

		wp_send_json_success(
			array(
				'message' => 'Schema assignment deleted successfully.',
			)
		);
	}

	// Placeholder to maintain class structure
	private static function _removed_helpers_placeholder() {
		return;
	}
}