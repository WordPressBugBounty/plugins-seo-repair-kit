<?php
/**
 * Product Schema Generator for SEO Repair Kit
 * Enhanced with dynamic field mapping like Review Schema
 *
 * @package     SEO_Repair_Kit
 * @subpackage  Schema
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

// Disable WooCommerce default product structured data.
add_filter( 'woocommerce_structured_data_product', '__return_empty_array' );
add_filter( 'woocommerce_structured_data_review', '__return_empty_array' );
add_filter( 'woocommerce_structured_data_offer', '__return_empty_array' );

/**
 * Generates Product schema markup for WooCommerce products with dynamic field mapping.
 *
 * This class replaces WooCommerce's default structured data with enhanced
 * schema.org markup for products, supporting dynamic field mappings from admin settings.
 *
 * @since 2.1.0
 */
class SRK_WooCommerce_Product_Schema {

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
     * Initialize product schema functionality.
     *
     * @since 2.1.0
     */
    public function __construct() {
        add_action( 'wp_head', array( $this, 'output_schema' ) );
    }

    /**
     * Get stock status schema value.
     *
     * @since 2.1.0
     *
     * @param string $stock_status WooCommerce stock status.
     * @return string Schema.org stock status.
     */
    private function get_stock_schema( $stock_status ) {
        switch ( $stock_status ) {
            case 'instock':
                return 'https://schema.org/InStock';
            case 'outofstock':
                return 'https://schema.org/OutOfStock';
            case 'onbackorder':
                return 'https://schema.org/PreOrder';
            default:
                return 'https://schema.org/InStock';
        }
    }

    /**
     * Output product schema markup.
     *
     * Generates comprehensive JSON-LD structured data for WooCommerce products
     * using dynamic field mappings from admin settings.
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

        global $post, $product;

        // Try to get product object if not already available
        if ( ! $product || ! is_a( $product, 'WC_Product' ) ) {
            if ( function_exists( 'wc_get_product' ) && $post && isset( $post->ID ) ) {
                $product = wc_get_product( $post->ID );
            }
            
            if ( ! $product || ! is_a( $product, 'WC_Product' ) ) {
                return;
            }
        }

        // Get saved schema configuration
        $option_key = 'srk_schema_assignment_product';
        $saved_data = get_option( $option_key, array() );
        
        if ( empty( $saved_data ) ) {
            return;
        }

        $this->field_map = isset( $saved_data['meta_map'] ) ? $saved_data['meta_map'] : array();
        $this->enabled_fields = isset( $saved_data['enabled_fields'] ) ? $saved_data['enabled_fields'] : array();

        if ( empty( $this->field_map ) ) {
            return;
        }

        $schema = $this->build_product_schema( $product, $post );

        // ✅ NEW: Validate required fields before output
        if ( ! class_exists( 'SeoRepairKit_SchemaValidator' ) ) {
            require_once plugin_dir_path( dirname( dirname( __FILE__ ) ) ) . 'includes/class-seo-repair-kit-schema-validator.php';
        }

        // Check if schema has all required fields (Product requires 'name' and 'offers')
        if ( ! SeoRepairKit_SchemaValidator::should_output_schema( $schema, 'product' ) ) {
            // Schema is missing required fields - do not output
            return;
        }

        // ✅ NEW: Check for conflicts before output
        if ( ! class_exists( 'SeoRepairKit_SchemaConflictDetector' ) ) {
            require_once plugin_dir_path( dirname( dirname( __FILE__ ) ) ) . 'includes/class-seo-repair-kit-schema-conflict-detector.php';
        }

        if ( ! SeoRepairKit_SchemaConflictDetector::can_output_schema( $schema, 'product', 'product-schema' ) ) {
            // Schema conflicts with another schema - do not output
            return;
        }

        echo '<script type="application/ld+json">' .
            wp_json_encode( $schema, JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE ) .
            '</script>';
    }

    /**
     * Check if schema should be output
     *
     * @since 2.1.0
     *
     * @return bool
     */
    private function should_output_schema() {
        if ( ! function_exists( 'is_product' ) || ! is_product() ) {
            return false;
        }

        global $post;

        $option_key = 'srk_schema_assignment_product';
        $saved_data = get_option( $option_key, array() );

        // If schema not configured
        if ( empty( $saved_data ) || ! isset( $saved_data['meta_map'] ) ) {
            return false;
        }

        $assigned_post_type = isset( $saved_data['post_type'] ) ? $saved_data['post_type'] : '';
        $current_post_type = get_post_type( $post->ID );

        // Global schema - apply to all
        if ( $assigned_post_type === 'global' ) {
            return true;
        }

        // Specific post type assigned - apply only to that
        if ( $assigned_post_type && $current_post_type !== $assigned_post_type ) {
            return false;
        }

        // Specific post selected - apply only to that post
        if ( isset( $saved_data['selected_post'] ) && $saved_data['selected_post'] > 0 ) {
            return $post->ID == $saved_data['selected_post'];
        }

        return true;
    }

    /**
     * Build complete product schema array with dynamic mappings.
     *
     * @since 2.1.0
     *
     * @param WC_Product $product WooCommerce product object.
     * @param WP_Post    $post WordPress post object.
     * @return array Product schema data.
     */
    private function build_product_schema( $product, $post ) {
        $schema = array(
            '@context' => 'https://schema.org/',
            '@type'    => 'Product',
            'url'      => get_permalink( $post->ID ),
            'mainEntityOfPage' => array(
                '@type' => 'WebPage',
                '@id'   => get_permalink( $post->ID ),
            ),
        );

        // Define which fields to process (support both short_description and product_short_description)
        $fields_to_process = array(
            'name' => function() use ($product, $post) { 
                return $this->resolve_field_value( 'name', $post, $product ); 
            },
            'description' => function() use ($product, $post) { 
                return $this->resolve_field_value( 'description', $post, $product ); 
            },
            'short_description' => function() use ($product, $post) { 
                // Check both 'short_description' and 'product_short_description' mappings
                $value = $this->resolve_field_value( 'short_description', $post, $product );
                if ( empty( $value ) && isset( $this->field_map['product_short_description'] ) ) {
                    $value = $this->resolve_field_value( 'product_short_description', $post, $product );
                }
                return $value;
            },
            'product_short_description' => function() use ($product, $post) { 
                return $this->resolve_field_value( 'product_short_description', $post, $product ); 
            },
            'sku' => function() use ($product, $post) { 
                return $this->resolve_field_value( 'sku', $post, $product ); 
            },
            'brand' => function() use ($product, $post) { 
                return $this->resolve_field_value( 'brand', $post, $product ); 
            },
            'price' => function() use ($product, $post) { 
                return $this->resolve_field_value( 'price', $post, $product ); 
            },
            'regular_price' => function() use ($product, $post) { 
                return $this->resolve_field_value( 'regular_price', $post, $product ); 
            },
            'sale_price' => function() use ($product, $post) { 
                return $this->resolve_field_value( 'sale_price', $post, $product ); 
            },
            'stock_status' => function() use ($product, $post) { 
                return $this->resolve_field_value( 'stock_status', $post, $product ); 
            },
            'category' => function() use ($product, $post) { 
                return $this->resolve_field_value( 'category', $post, $product ); 
            },
            'tags' => function() use ($product, $post) { 
                return $this->resolve_field_value( 'tags', $post, $product ); 
            },
            'image' => function() use ($product, $post) { 
                return $this->resolve_field_value( 'image', $post, $product ); 
            }
        );

        // Process ALL fields regardless of enabled status
        foreach ( $fields_to_process as $field => $value_callback ) {
            // Skip if field mapping doesn't exist (check both the field key and product_short_description for short_description)
            $has_mapping = isset( $this->field_map[ $field ] ) && ! empty( $this->field_map[ $field ] );
            
            // Special case: check product_short_description mapping for short_description field
            if ( $field === 'short_description' && ! $has_mapping && isset( $this->field_map['product_short_description'] ) ) {
                $has_mapping = true;
            }
            
            // Skip product_short_description if it's already handled by short_description
            if ( $field === 'product_short_description' && isset( $this->field_map['short_description'] ) ) {
                continue;
            }
            
            if ( ! $has_mapping ) {
                continue;
            }

            $value = $value_callback();
            
            // Skip price-related fields here - they'll be handled in offers
            if ( in_array( $field, array( 'price', 'regular_price', 'sale_price', 'stock_status' ), true ) ) {
                continue;
            }
            
            // Process field even if value is empty string (might be valid), but skip null/false
            if ( $value !== null && $value !== false ) {
                $schema = $this->process_product_field( $schema, $field, $value, $product, $post );
            }
        }

        // Always build offers structure if any price-related field is mapped or product has price
        $has_price_mapping = ! empty( $this->field_map['price'] ) || 
                             ! empty( $this->field_map['regular_price'] ) || 
                             ! empty( $this->field_map['sale_price'] ) ||
                             ! empty( $this->field_map['offers'] ) ||
                             $this->has_price_data( $product );
                             
        if ( $has_price_mapping ) {
            $schema['offers'] = $this->build_offers_schema( $schema, $product );
        }

        // Add reviews if enabled in WooCommerce
        if ( wc_review_ratings_enabled() ) {
            $schema = $this->add_reviews_schema( $schema, $product );
        }

        // Clean up schema - remove any fields that shouldn't be in Product schema
        // Remove raw field names that were converted to other properties
        unset( $schema['product_short_description'] );
        unset( $schema['price'] );
        unset( $schema['regular_price'] );
        unset( $schema['sale_price'] );
        unset( $schema['stock_status'] );
        unset( $schema['tags'] ); // Tags not a standard Product property

        return array_filter( $schema );
    }

    /**
     * Process product field and add to schema
     *
     * @since 2.1.0
     *
     * @param array      $schema  Schema array.
     * @param string     $field   Field key.
     * @param mixed      $value   Field value.
     * @param WC_Product $product WooCommerce product object.
     * @param WP_Post    $post    WordPress post object.
     * @return array Modified schema
     */
    private function process_product_field( $schema, $field, $value, $product, $post ) {
        switch ( $field ) {
            case 'name':
                $schema['name'] = $value;
                break;

            case 'description':
                $schema['description'] = wp_strip_all_tags( $value );
                break;

            case 'short_description':
            case 'product_short_description':
                // Use disambiguatingDescription for Schema.org compatibility
                // Remove product_short_description key if it exists
                unset( $schema['product_short_description'] );
                if ( ! empty( $value ) ) {
                    $schema['disambiguatingDescription'] = wp_strip_all_tags( $value );
                }
                break;

            case 'sku':
                // SKU can be empty string, but add it if we have a value
                if ( ! empty( $value ) || $value === '0' || $value === 0 ) {
                    $schema['sku'] = is_string( $value ) ? trim( $value ) : $value;
                }
                break;

            case 'brand':
                // Only add brand if we have a valid value
                if ( ! empty( $value ) && is_string( $value ) ) {
                    $schema['brand'] = array(
                        '@type' => 'Brand',
                        'name'  => trim( $value ),
                    );
                }
                break;

            case 'price':
            case 'regular_price':
            case 'sale_price':
            case 'stock_status':
                // These are handled in offers section only - never add to Product level
                break;
            
            case 'tags':
                // Tags can be added as additionalProperty or we can skip them
                // For now, skip as they're not a standard Product property
                // But we can add them if needed in the future
                break;

            case 'category':
                // Category can be a string (comma-separated) or array
                if ( is_array( $value ) ) {
                    $value = implode( ', ', array_filter( $value ) );
                }
                if ( ! empty( $value ) ) {
                    $schema['category'] = is_string( $value ) ? trim( $value ) : $value;
                }
                break;

            case 'image':
                if ( is_array( $value ) ) {
                    $schema['image'] = array();
                    foreach ( $value as $image_url ) {
                        $schema['image'][] = array(
                            '@type' => 'ImageObject',
                            'url'   => $image_url,
                        );
                    }
                } else {
                    $schema['image'] = array(
                        '@type' => 'ImageObject',
                        'url'   => $value,
                    );
                }
                break;

            default:
                // Don't add fields that shouldn't be in Product schema
                if ( ! in_array( $field, array( 'product_short_description', 'price', 'regular_price', 'sale_price', 'stock_status', 'tags' ), true ) ) {
                    $schema[ $field ] = $value;
                }
                break;
        }

        return $schema;
    }

    /**
     * Check if we have price data for offers
     *
     * @since 2.1.0
     *
     * @param WC_Product $product WooCommerce product object.
     * @return bool
     */
    private function has_price_data( $product ) {
        return $product->get_price() > 0;
    }

    /**
     * Build offers schema for product
     *
     * @since 2.1.0
     *
     * @param array      $schema  Current schema data.
     * @param WC_Product $product WooCommerce product object.
     * @return array Offers schema data.
     */
    private function build_offers_schema( $schema, $product ) {
        $offer = array(
            '@type'         => 'Offer',
            'url'           => get_permalink( $product->get_id() ),
            'priceCurrency' => get_woocommerce_currency(),
            'itemCondition' => 'https://schema.org/NewCondition',
            'seller'        => array(
                '@type' => 'Organization',
                'name'  => get_bloginfo( 'name' ),
            ),
        );

        // Get price values from resolved mappings
        $price = $this->resolve_field_value( 'price', get_post( $product->get_id() ), $product );
        $regular_price = $this->resolve_field_value( 'regular_price', get_post( $product->get_id() ), $product );
        $sale_price = $this->resolve_field_value( 'sale_price', get_post( $product->get_id() ), $product );
        $stock_status = $this->resolve_field_value( 'stock_status', get_post( $product->get_id() ), $product );

        // Extract numeric values from strings if needed
        $price = $this->extract_numeric_value( $price );
        $regular_price = $this->extract_numeric_value( $regular_price );
        $sale_price = $this->extract_numeric_value( $sale_price );

        // Set price - use sale price if available, otherwise regular price, otherwise product price
        if ( ! empty( $sale_price ) && is_numeric( $sale_price ) && $sale_price > 0 ) {
            $offer['price'] = floatval( $sale_price );
        } elseif ( ! empty( $price ) && is_numeric( $price ) && $price > 0 ) {
            $offer['price'] = floatval( $price );
        } elseif ( ! empty( $regular_price ) && is_numeric( $regular_price ) && $regular_price > 0 ) {
            $offer['price'] = floatval( $regular_price );
        } else {
            $product_price = $product->get_price();
            $offer['price'] = ! empty( $product_price ) ? floatval( $product_price ) : 0;
        }

        // Set availability from mapping or fallback to product
        if ( ! empty( $stock_status ) ) {
            $offer['availability'] = $this->get_stock_schema( $stock_status );
        } else {
            $offer['availability'] = $this->get_stock_schema( $product->get_stock_status() );
        }

        // Add price specification - use regular price or current offer price
        $base_price = ! empty( $regular_price ) && is_numeric( $regular_price ) && $regular_price > 0 
            ? floatval( $regular_price ) 
            : ( $product->get_regular_price() ? floatval( $product->get_regular_price() ) : $offer['price'] );
            
        $offer['priceSpecification'] = array(
            '@type'         => 'PriceSpecification',
            'priceCurrency' => get_woocommerce_currency(),
            'price'         => $base_price,
        );

        // Add sale price and valid until if on sale or if we have a sale price mapping
        $is_on_sale = $product->is_on_sale() || ( ! empty( $sale_price ) && is_numeric( $sale_price ) && $sale_price > 0 );
        $has_sale_lower = $is_on_sale && ! empty( $regular_price ) && is_numeric( $regular_price ) && $sale_price < $regular_price;
        
        if ( $is_on_sale || $has_sale_lower ) {
            $sale_price_value = ! empty( $sale_price ) && is_numeric( $sale_price ) && $sale_price > 0 
                ? floatval( $sale_price ) 
                : floatval( $product->get_sale_price() );
                
            if ( $sale_price_value > 0 ) {
                $offer['priceSpecification']['price'] = $sale_price_value;
                // Use sale price as the main offer price if it's lower than regular
                if ( ! empty( $regular_price ) && is_numeric( $regular_price ) && $sale_price_value < $regular_price ) {
                    $offer['price'] = $sale_price_value;
                }
            }

            // Add priceValidUntil from product or set to 1 year from now
            if ( $product->get_date_on_sale_to() ) {
                $offer['priceValidUntil'] = $product->get_date_on_sale_to()->date( 'Y-m-d' );
            } else {
                // Default: 1 year from now (recommended by Google)
                $offer['priceValidUntil'] = gmdate( 'Y-m-d', strtotime( '+1 year' ) );
            }
        } else {
            // Even if not on sale, add priceValidUntil (recommended by Google)
            $offer['priceValidUntil'] = gmdate( 'Y-m-d', strtotime( '+1 year' ) );
        }

        return $offer;
    }

    /**
     * Add reviews and ratings schema.
     *
     * @since 2.1.0
     *
     * @param array      $schema  Schema data.
     * @param WC_Product $product WooCommerce product object.
     * @return array Modified schema data.
     */
    private function add_reviews_schema( $schema, $product ) {
        $average = $product->get_average_rating();
        $count   = $product->get_review_count();

        if ( $count > 0 ) {
            $schema['aggregateRating'] = array(
                '@type'       => 'AggregateRating',
                'ratingValue' => $average,
                'reviewCount' => $count,
                'bestRating'  => '5',
                'worstRating' => '1',
            );
        }

        $reviews = get_comments(
            array(
                'post_id' => $product->get_id(),
                'status'  => 'approve',
                'number'  => 5,
            )
        );

        if ( $reviews ) {
            $schema['review'] = array();
            foreach ( $reviews as $review ) {
                $rating = intval( get_comment_meta( $review->comment_ID, 'rating', true ) );
                $schema['review'][] = array(
                    '@type'         => 'Review',
                    'author'        => array(
                        '@type' => 'Person',
                        'name'  => $review->comment_author,
                    ),
                    'datePublished' => $review->comment_date,
                    'reviewBody'    => $review->comment_content,
                    'reviewRating'  => array(
                        '@type'       => 'Rating',
                        'ratingValue' => $rating,
                        'bestRating'  => '5',
                        'worstRating' => '1',
                    ),
                );
            }
        }

        return $schema;
    }

    /**
     * Resolve field value from mapping
     *
     * @since 2.1.0
     *
     * @param string     $field   Field name.
     * @param WP_Post    $post WordPress post object.
     * @param WC_Product $product WooCommerce product object.
     * @return mixed Field value
     */
    private function resolve_field_value( $field, $post, $product ) {
        // Check if field mapping exists (also check product_short_description for short_description)
        $mapping_key = $field;
        if ( $field === 'short_description' && ! isset( $this->field_map[ $field ] ) && isset( $this->field_map['product_short_description'] ) ) {
            $mapping_key = 'product_short_description';
        }
        
        if ( ! isset( $this->field_map[ $mapping_key ] ) || empty( $this->field_map[ $mapping_key ] ) ) {
            return null;
        }

        $mapping = $this->field_map[ $mapping_key ];

        // Handle custom: prefix (direct values)
        if ( is_string( $mapping ) && strpos( $mapping, 'custom:' ) === 0 ) {
            return str_replace( 'custom:', '', $mapping );
        }

        // Handle direct values (without source type)
        if ( strpos( $mapping, ':' ) === false ) {
            return $mapping;
        }

        list($source_type, $field_name) = explode( ':', $mapping, 2 );

        switch ( $source_type ) {
            case 'post':
                return $this->get_post_field_value( $field_name, $post, $product );

            case 'meta':
                return get_post_meta( $post->ID, $field_name, true );

            case 'user':
                $author_id = get_post_field( 'post_author', $post->ID );
                return get_user_meta( $author_id, $field_name, true );

            case 'tax':
                // Get taxonomy term names (not IDs)
                // Try exact taxonomy name first
                $terms = wp_get_post_terms( $post->ID, $field_name, array( 'fields' => 'names', 'hide_empty' => false ) );
                
                // If no terms found, try common WooCommerce taxonomy variations
                if ( empty( $terms ) || is_wp_error( $terms ) ) {
                    // Try product_brand for brand field
                    if ( $field === 'brand' || strpos( $field_name, 'brand' ) !== false ) {
                        $brand_terms = wp_get_post_terms( $post->ID, 'product_brand', array( 'fields' => 'names', 'hide_empty' => false ) );
                        if ( ! empty( $brand_terms ) && ! is_wp_error( $brand_terms ) ) {
                            $terms = $brand_terms;
                        }
                    }
                    
                    // Try pa_brand (attribute) if product_brand doesn't exist
                    if ( ( empty( $terms ) || is_wp_error( $terms ) ) && ( $field === 'brand' || strpos( $field_name, 'brand' ) !== false ) ) {
                        $pa_brand_terms = wp_get_post_terms( $post->ID, 'pa_brand', array( 'fields' => 'names', 'hide_empty' => false ) );
                        if ( ! empty( $pa_brand_terms ) && ! is_wp_error( $pa_brand_terms ) ) {
                            $terms = $pa_brand_terms;
                        }
                    }
                }
                
                if ( ! empty( $terms ) && ! is_wp_error( $terms ) ) {
                    // Return single term name if only one, or comma-separated string if multiple
                    return count( $terms ) === 1 ? $terms[0] : implode( ', ', $terms );
                }
                return '';

            case 'site':
                return $this->get_site_value( $field_name );

            case 'wc':
                return $this->get_wc_product_value( $field_name, $product );

            default:
                return null;
        }
    }

    /**
     * Get post field value
     *
     * @since 2.1.0
     *
     * @param string     $field_name Field name.
     * @param WP_Post    $post WordPress post object.
     * @param WC_Product $product WooCommerce product object.
     * @return mixed Field value
     */
    private function get_post_field_value( $field_name, $post, $product ) {
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
     * Get WooCommerce product value
     *
     * @since 2.1.0
     *
     * @param string     $field_name Field name.
     * @param WC_Product $product WooCommerce product object.
     * @return mixed Field value
     */
    private function get_wc_product_value( $field_name, $product ) {
        switch ( $field_name ) {
            case 'name':
                return $product->get_name();

            case 'description':
                return $product->get_description();

            case 'short_description':
                return $product->get_short_description();

            case 'sku':
                return $product->get_sku();

            case 'price':
                return $product->get_price();

            case 'regular_price':
                return $product->get_regular_price();

            case 'sale_price':
                return $product->get_sale_price();

            case 'stock_status':
                return $product->get_stock_status();

            case 'average_rating':
                return $product->get_average_rating();

            case 'review_count':
                return $product->get_review_count();

            default:
                if ( method_exists( $product, 'get_' . $field_name ) ) {
                    return call_user_func( array( $product, 'get_' . $field_name ) );
                }
                return null;
        }
    }

    /**
     * Extract numeric value from mixed input (string, number, etc.)
     *
     * @since 2.1.0
     *
     * @param mixed $value Input value.
     * @return float|int|string Extracted numeric value or original value.
     */
    private function extract_numeric_value( $value ) {
        if ( empty( $value ) ) {
            return null;
        }

        // If already numeric, return as-is
        if ( is_numeric( $value ) ) {
            return $value;
        }

        // If string, try to extract number
        if ( is_string( $value ) ) {
            // Remove currency symbols and spaces
            $cleaned = preg_replace( '/[^\d.,]/', '', $value );
            // Replace comma with dot for decimal
            $cleaned = str_replace( ',', '.', $cleaned );
            if ( preg_match( '/(\d+(?:\.\d+)?)/', $cleaned, $matches ) ) {
                return floatval( $matches[1] );
            }
        }

        return $value;
    }

    /**
     * Get site value
     *
     * @since 2.1.0
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

            case 'currency':
                return get_woocommerce_currency();

            default:
                return get_option( $field_name, '' );
        }
    }
}

/**
 * Initialize product schema generator
 */
function srk_init_product_schema_generator() {
    new SRK_WooCommerce_Product_Schema();
}
add_action( 'init', 'srk_init_product_schema_generator' );