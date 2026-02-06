<?php
/**
 * Event Schema Generator for SEO Repair Kit
 *
 * @package     SEO_Repair_Kit
 * @subpackage  Schema
 */
 
if ( ! defined( 'ABSPATH' ) ) {
    exit;
}
 
/**
 * Generates Event schema markup for event post types.
 *
 * This class handles the generation of structured data for Event schema.org
 * markup, supporting various event properties like dates, locations, performers,
 * and pricing. It's designed to work with standard event post types.
 *
 * @since 2.1.0
 */
class SRK_Event_Schema_Generator {
 
    /**
     * Initialize event schema functionality.
     *
     * @since 2.1.0
     */
    public function __construct() {
        add_action( 'wp_head', array( $this, 'output_schema' ) );
    }
   
    /**
     * Output Event schema markup.
     *
     * Generates JSON-LD structured data for events based on mapped field values.
     * Only outputs on single event pages and when schema mapping is configured.
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

        // Check if we're on an event single page.
        if ( ! is_singular() ) {
            return;
        }
 
        global $post;
 
        $option_key = 'srk_schema_assignment_event';
        $saved_data = get_option( $option_key, array() );
        if ( empty( $saved_data ) ) {
            return;
        }
 
        // Handle JSON-encoded data.
        if ( is_string( $saved_data ) ) {
            $decoded = json_decode( $saved_data, true );
            if ( JSON_ERROR_NONE === json_last_error() ) {
                $saved_data = $decoded;
            }
        }
 
        if ( ! isset( $saved_data['meta_map'] ) || empty( $saved_data['meta_map'] ) ) {
            return;
        }
 
        $field_map = $saved_data['meta_map'];
        $enabled_fields = isset( $saved_data['enabled_fields'] ) ? $saved_data['enabled_fields'] : array();
        $schema    = $this->build_event_schema( $post, $field_map, $enabled_fields );

        // ✅ NEW: Validate required fields before output
        if ( ! class_exists( 'SeoRepairKit_SchemaValidator' ) ) {
            require_once plugin_dir_path( dirname( dirname( __FILE__ ) ) ) . 'includes/class-seo-repair-kit-schema-validator.php';
        }

        // Check if schema has all required fields (Event requires 'name', 'startDate')
        if ( ! SeoRepairKit_SchemaValidator::should_output_schema( $schema, 'event' ) ) {
            // Schema is missing required fields - do not output
            return;
        }

        // ✅ NEW: Check for conflicts before output
        if ( ! class_exists( 'SeoRepairKit_SchemaConflictDetector' ) ) {
            require_once plugin_dir_path( dirname( dirname( __FILE__ ) ) ) . 'includes/class-seo-repair-kit-schema-conflict-detector.php';
        }

        if ( ! SeoRepairKit_SchemaConflictDetector::can_output_schema( $schema, 'event', 'event-schema-generator' ) ) {
            // Schema conflicts with another schema - do not output
            return;
        }

        echo '<script type="application/ld+json">' .
            wp_json_encode( $schema, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT ) .
            '</script>';
    }
 
    /**
     * Build complete event schema array.
     *
     * @since 2.1.0
     *
     * @param WP_Post $post      Event post object.
     * @param array   $field_map Field mapping configuration.
     * @param array   $enabled_fields Enabled fields configuration.
     * @return array Event schema data.
     */
    private function build_event_schema( $post, $field_map, $enabled_fields ) {
        // Define core schema first.
        $event_url = get_permalink( $post );
        
        $schema = array(
            '@context'    => 'https://schema.org',
            '@type'       => 'Event',
        );
        
        // Only include URL if it's a valid absolute URL.
        if ( ! empty( $event_url ) && filter_var( $event_url, FILTER_VALIDATE_URL ) ) {
            $schema['url'] = $event_url;
        }

        // Add basic fields only if they are enabled
        if ( empty( $enabled_fields ) || in_array( 'name', $enabled_fields ) ) {
            $title = get_the_title( $post );
            if ( ! empty( $title ) ) {
                $schema['name'] = $title;
            }
        }

        if ( empty( $enabled_fields ) || in_array( 'description', $enabled_fields ) ) {
            $description = get_the_excerpt( $post );
            if ( empty( $description ) ) {
                $description = wp_trim_words( wp_strip_all_tags( $post->post_content ), 30 );
            }
            if ( ! empty( $description ) ) {
                $schema['description'] = $description;
            }
        }

        if ( empty( $enabled_fields ) || in_array( 'image', $enabled_fields ) ) {
            $image_url = get_the_post_thumbnail_url( $post, 'full' );
            // Only include image if it's a valid absolute URL.
            if ( ! empty( $image_url ) && filter_var( $image_url, FILTER_VALIDATE_URL ) ) {
                $schema['image'] = $image_url;
            }
        }
 
        // Event specific fields (include all supported fields).
        $meta_keys = array( 'startDate', 'endDate', 'location', 'performer', 'organizer', 'offers', 'eventStatus' );

        foreach ( $meta_keys as $schema_key ) {
            // Skip if field is not enabled and enabled_fields array exists
            if ( ! empty( $enabled_fields ) && ! in_array( $schema_key, $enabled_fields ) ) {
                continue;
            }

            if ( ! empty( $field_map[ $schema_key ] ) ) {
                $value = $this->resolve_field_value( $field_map[ $schema_key ], $post, $schema_key );
                if ( ! empty( $value ) ) {
                    $schema[ $schema_key ] = $this->process_event_field( $schema_key, $value, $post );
                }
            }
        }

        // Handle 'cost' field: map it to 'offers' if 'offers' is not already set.
        // This allows users to map 'cost' field, and we'll convert it to proper 'offers' structure.
        if ( empty( $schema['offers'] ) && ! empty( $field_map['cost'] ) ) {
            if ( empty( $enabled_fields ) || in_array( 'cost', $enabled_fields ) ) {
                $cost_value = $this->resolve_field_value( $field_map['cost'], $post, 'offers' );
                if ( ! empty( $cost_value ) ) {
                    $schema['offers'] = $this->process_event_field( 'offers', $cost_value, $post );
                }
            }
        }
 
        return array_filter( $schema );
    }
 
    /**
     * Process event-specific field values.
     *
     * @since 2.1.0
     *
     * @param string  $schema_key Schema field key.
     * @param mixed   $value      Field value.
     * @param WP_Post $post       Optional. Post object for context.
     * @return mixed Processed field value.
     */
    private function process_event_field( $schema_key, $value, $post = null ) {
        switch ( $schema_key ) {
            case 'startDate':
            case 'endDate':
                // Ensure date is in ISO 8601 format. Handle custom: prefix if present.
                $clean_value = is_string( $value ) && strpos( $value, 'custom:' ) === 0 ? str_replace( 'custom:', '', $value ) : $value;
                $timestamp = strtotime( $clean_value );
                if ( false !== $timestamp ) {
                    return gmdate( DATE_W3C, $timestamp );
                }
                // Fallback: try parsing as-is if it's already ISO format.
                if ( is_string( $clean_value ) && preg_match( '/^\d{4}-\d{2}-\d{2}/', $clean_value ) ) {
                    // If it's just a date (YYYY-MM-DD), append time for ISO compliance.
                    if ( strlen( $clean_value ) === 10 ) {
                        return $clean_value . 'T00:00:00Z';
                    }
                    return $clean_value;
                }
                return gmdate( DATE_W3C );

            case 'location':
                // Value can be an array (from resolve_venue_data) or a string.
                if ( is_array( $value ) ) {
                    // Already structured venue data from The Events Calendar.
                    $location = array(
                        '@type' => 'Place',
                    );
                    
                    if ( ! empty( $value['name'] ) ) {
                        $location['name'] = $value['name'];
                    }
                    
                    // Build PostalAddress if we have address components.
                    $address_parts = array();
                    if ( ! empty( $value['streetAddress'] ) ) {
                        $address_parts['streetAddress'] = $value['streetAddress'];
                    }
                    if ( ! empty( $value['addressLocality'] ) ) {
                        $address_parts['addressLocality'] = $value['addressLocality'];
                    }
                    if ( ! empty( $value['addressRegion'] ) ) {
                        $address_parts['addressRegion'] = $value['addressRegion'];
                    }
                    if ( ! empty( $value['postalCode'] ) ) {
                        $address_parts['postalCode'] = $value['postalCode'];
                    }
                    if ( ! empty( $value['addressCountry'] ) ) {
                        if ( is_array( $value['addressCountry'] ) && ! empty( $value['addressCountry']['name'] ) ) {
                            $address_parts['addressCountry'] = $value['addressCountry']['name'];
                        } elseif ( is_string( $value['addressCountry'] ) ) {
                            $address_parts['addressCountry'] = $value['addressCountry'];
                        }
                    }
                    
                    if ( ! empty( $address_parts ) ) {
                        $location['address'] = array_merge(
                            array( '@type' => 'PostalAddress' ),
                            $address_parts
                        );
                    } elseif ( ! empty( $value['address'] ) ) {
                        // Fallback: use full address string as streetAddress.
                        $location['address'] = array(
                            '@type'         => 'PostalAddress',
                            'streetAddress' => $value['address'],
                        );
                    }
                    
                    return $location;
                }
                
                // String value: wrap into Place with PostalAddress.
                $clean_value = trim( (string) $value );
                if ( '' === $clean_value ) {
                    return null;
                }
                
                return array(
                    '@type'   => 'Place',
                    'name'    => $clean_value,
                    'address' => array(
                        '@type'         => 'PostalAddress',
                        'streetAddress' => $clean_value,
                    ),
                );

            case 'performer':
                // Value can be an array (from resolve_organizer_data) or a string.
                if ( is_array( $value ) ) {
                    $performer = array();
                    if ( ! empty( $value['@type'] ) ) {
                        $performer['@type'] = $value['@type'];
                    } elseif ( $this->is_organization( $value['name'] ?? '' ) ) {
                        $performer['@type'] = 'Organization';
                    } else {
                        $performer['@type'] = 'Person';
                    }
                    
                    if ( ! empty( $value['name'] ) ) {
                        $performer['name'] = $value['name'];
                    }
                    if ( ! empty( $value['url'] ) && filter_var( $value['url'], FILTER_VALIDATE_URL ) ) {
                        $performer['url'] = $value['url'];
                    }
                    if ( ! empty( $value['telephone'] ) ) {
                        $performer['telephone'] = $value['telephone'];
                    }
                    if ( ! empty( $value['sameAs'] ) ) {
                        $performer['sameAs'] = is_array( $value['sameAs'] ) ? $value['sameAs'] : array( $value['sameAs'] );
                    }
                    
                    return $performer;
                }
                
                // String value: determine type and wrap.
                $clean_value = trim( (string) $value );
                if ( '' === $clean_value ) {
                    return null;
                }
                
                return array(
                    '@type' => $this->is_organization( $clean_value ) ? 'Organization' : 'Person',
                    'name'  => $clean_value,
                );

            case 'offers':
                $offers = array(
                    '@type'           => 'Offer',
                    'name'            => __( 'Ticket', 'seo-repair-kit' ),
                    'price'           => $this->extract_price( $value ),
                    'priceCurrency'   => $this->extract_currency( $value ),
                    'availability'    => 'https://schema.org/InStock',
                    'validFrom'       => gmdate( DATE_W3C ),
                );
                
                // Ensure URL is valid.
                if ( $post ) {
                    $event_url = get_permalink( $post->ID );
                    if ( filter_var( $event_url, FILTER_VALIDATE_URL ) ) {
                        $offers['url'] = $event_url;
                    }
                }
                
                return $offers;

            case 'organizer':
                // Value can be an array (from resolve_organizer_data) or a string.
                if ( is_array( $value ) ) {
                    $organizer = array(
                        '@type' => 'Organization',
                    );
                    
                    if ( ! empty( $value['name'] ) ) {
                        $organizer['name'] = $value['name'];
                    }
                    if ( ! empty( $value['url'] ) && filter_var( $value['url'], FILTER_VALIDATE_URL ) ) {
                        $organizer['url'] = $value['url'];
                    }
                    if ( ! empty( $value['telephone'] ) ) {
                        $organizer['telephone'] = $value['telephone'];
                    }
                    if ( ! empty( $value['sameAs'] ) ) {
                        $organizer['sameAs'] = is_array( $value['sameAs'] ) ? $value['sameAs'] : array( $value['sameAs'] );
                    }
                    
                    return $organizer;
                }
                
                // String value: wrap into Organization.
                $clean_value = trim( (string) $value );
                if ( '' === $clean_value ) {
                    return null;
                }
                
                return array(
                    '@type' => 'Organization',
                    'name'  => $clean_value,
                );

            case 'eventStatus':
                // Map common status strings to schema.org EventStatusType URLs.
                $status_lower = strtolower( trim( (string) $value ) );
                
                $status_map = array(
                    'scheduled'        => 'https://schema.org/EventScheduled',
                    'cancelled'        => 'https://schema.org/EventCancelled',
                    'postponed'        => 'https://schema.org/EventPostponed',
                    'rescheduled'      => 'https://schema.org/EventRescheduled',
                    'eventcancelled'   => 'https://schema.org/EventCancelled',
                    'eventscheduled'   => 'https://schema.org/EventScheduled',
                    'eventpostponed'   => 'https://schema.org/EventPostponed',
                    'eventrescheduled' => 'https://schema.org/EventRescheduled',
                );
                
                if ( isset( $status_map[ $status_lower ] ) ) {
                    return $status_map[ $status_lower ];
                }
                
                // If it's already a valid URL, return as-is.
                if ( filter_var( $value, FILTER_VALIDATE_URL ) && strpos( $value, 'schema.org' ) !== false ) {
                    return $value;
                }
                
                // Default to EventScheduled if unrecognized.
                return 'https://schema.org/EventScheduled';

            default:
                return $value;
        }
    }
 
    /**
     * Check if value represents an organization.
     *
     * @since 2.1.0
     *
     * @param string $value Value to check.
     * @return bool True if value appears to be an organization.
     */
    private function is_organization( $value ) {
        $organization_indicators = array( 'inc', 'llc', 'ltd', 'corp', 'company', 'association', 'foundation', 'group' );
        $value_lower = strtolower( $value );
 
        foreach ( $organization_indicators as $indicator ) {
            if ( strpos( $value_lower, $indicator ) !== false ) {
                return true;
            }
        }
 
        return false;
    }
 
    /**
     * Extract price from string value.
     *
     * @since 2.1.0
     *
     * @param string $value Price string.
     * @return float Extracted price.
     */
    private function extract_price( $value ) {
        if ( preg_match( '/(\d+\.?\d*)/', $value, $matches ) ) {
            return floatval( $matches[1] );
        }
        return 0;
    }
 
    /**
     * Extract currency from string value.
     *
     * @since 2.1.0
     *
     * @param string $value Currency string.
     * @return string Currency code.
     */
    private function extract_currency( $value ) {
        if ( preg_match( '/\$|USD/', $value ) ) {
            return 'USD';
        }
        if ( preg_match( '/€|EUR/', $value ) ) {
            return 'EUR';
        }
        if ( preg_match( '/£|GBP/', $value ) ) {
            return 'GBP';
        }
        return 'USD'; // Default.
    }
 
    /**
     * Resolve field value based on mapping configuration.
     *
     * @since 2.1.0
     *
     * @param mixed   $mapping Field mapping configuration.
     * @param WP_Post $post    Post object.
     * @param string  $field_key Optional. The schema field key (e.g., 'location', 'performer') for context-aware resolution.
     * @return mixed Resolved field value.
     */
    private function resolve_field_value( $mapping, $post, $field_key = '' ) {
        // Handle literal custom values with "custom:" prefix (e.g., custom:2026-01-15).
        if ( is_string( $mapping ) && strpos( $mapping, 'custom:' ) === 0 ) {
            return str_replace( 'custom:', '', $mapping );
        }

        if (is_string($mapping) && strpos($mapping, 'meta:') === 0) {
            $actual_meta_field = str_replace('meta:', '', $mapping);
            $value = get_post_meta($post->ID, $actual_meta_field, true);
            
            // Special handling for The Events Calendar: resolve venue/organizer IDs to names.
            if ( ! empty( $value ) && is_numeric( $value ) && ! empty( $field_key ) ) {
                if ( $actual_meta_field === '_EventVenueID' && $field_key === 'location' ) {
                    return $this->resolve_venue_data( (int) $value );
                } elseif ( ( $actual_meta_field === '_EventOrganizerID' || $actual_meta_field === '_EventOrganizerIDs' ) && in_array( $field_key, array( 'performer', 'organizer' ), true ) ) {
                    return $this->resolve_organizer_data( (int) $value );
                }
            }
            
            return $value;
        }
       
        if (is_string($mapping) && preg_match('/^\[(post|meta|user|tax):(.+)\]$/', $mapping, $matches)) {
            $source_type = $matches[1];
            $field_name  = $matches[2];

            switch ($source_type) {
                case 'post':
                    return $this->get_post_field_value($field_name, $post);
                case 'meta':
                    $value = get_post_meta($post->ID, $field_name, true);
                    
                    // Special handling for The Events Calendar: resolve venue/organizer IDs to names.
                    if ( ! empty( $value ) && is_numeric( $value ) && ! empty( $field_key ) ) {
                        if ( $field_name === '_EventVenueID' && $field_key === 'location' ) {
                            return $this->resolve_venue_data( (int) $value );
                        } elseif ( ( $field_name === '_EventOrganizerID' || $field_name === '_EventOrganizerIDs' ) && in_array( $field_key, array( 'performer', 'organizer' ), true ) ) {
                            return $this->resolve_organizer_data( (int) $value );
                        }
                    }
                    
                    return $value;
                case 'user':
                    $author_id = get_post_field('post_author', $post->ID);
                    return get_user_meta($author_id, $field_name, true);
                case 'tax':
                    $terms = wp_get_post_terms($post->ID, $field_name, array('fields' => 'names'));
                    return !empty($terms) ? implode(', ', $terms) : '';
            }
        }
        return $mapping;
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
                return get_the_title( $post );
            case 'post_excerpt':
                $excerpt = get_the_excerpt( $post );
                return ! empty( $excerpt ) ? $excerpt : wp_trim_words( $post->post_content, 30 );
            case 'post_content':
                return wp_strip_all_tags( $post->post_content );
            case 'post_date':
                return get_the_date( DATE_W3C, $post );
            case 'post_modified':
                return get_the_modified_date( DATE_W3C, $post );
            case 'post_author':
                $author_id = get_post_field( 'post_author', $post->ID );
                return get_the_author_meta( 'display_name', $author_id );
            case 'post_thumbnail':
                return get_the_post_thumbnail_url( $post, 'full' );
            default:
                return get_post_meta( $post->ID, $field_name, true );
        }
    }

    /**
     * Resolve venue data from The Events Calendar plugin.
     *
     * Attempts to get venue name and address details from The Events Calendar's venue post.
     * Falls back to just the venue name if address details aren't available.
     *
     * @since 2.1.0
     *
     * @param int $venue_id Venue post ID.
     * @return array|string Venue data array (with name, address components) or venue name string, or empty string if not found.
     */
    private function resolve_venue_data( $venue_id ) {
        if ( empty( $venue_id ) || ! is_numeric( $venue_id ) ) {
            return '';
        }

        $venue_post = get_post( (int) $venue_id );
        if ( ! $venue_post ) {
            return '';
        }

        $venue_name = get_the_title( $venue_id );
        if ( empty( $venue_name ) ) {
            return '';
        }

        // Try to get address details from The Events Calendar venue meta.
        // The Events Calendar stores venue address in venue post meta with keys like:
        // _VenueAddress, _VenueCity, _VenueStateProvince, _VenueZip, _VenueCountry.
        $address_meta = array(
            'streetAddress'    => get_post_meta( $venue_id, '_VenueAddress', true ),
            'addressLocality'  => get_post_meta( $venue_id, '_VenueCity', true ),
            'addressRegion'    => get_post_meta( $venue_id, '_VenueStateProvince', true ),
            'postalCode'       => get_post_meta( $venue_id, '_VenueZip', true ),
            'addressCountry'   => get_post_meta( $venue_id, '_VenueCountry', true ),
        );

        // Remove empty values.
        $address_meta = array_filter( $address_meta, function( $v ) {
            return ! empty( $v );
        } );

        // If we have any address components, return structured data.
        if ( ! empty( $address_meta ) ) {
            return array_merge(
                array( 'name' => $venue_name ),
                $address_meta
            );
        }

        // Fallback: return just the venue name as a string (will be processed into Place object).
        return $venue_name;
    }

    /**
     * Resolve organizer data from The Events Calendar plugin.
     *
     * Gets organizer name and optionally URL, phone, and social profiles from The Events Calendar's organizer post.
     *
     * @since 2.1.0
     *
     * @param int $organizer_id Organizer post ID.
     * @return array|string Organizer data array or organizer name string, or empty string if not found.
     */
    private function resolve_organizer_data( $organizer_id ) {
        if ( empty( $organizer_id ) || ! is_numeric( $organizer_id ) ) {
            return '';
        }

        $organizer_post = get_post( (int) $organizer_id );
        if ( ! $organizer_post ) {
            return '';
        }

        $organizer_name = get_the_title( $organizer_id );
        if ( empty( $organizer_name ) ) {
            return '';
        }

        // Try to get additional organizer details from The Events Calendar organizer meta.
        // The Events Calendar stores organizer details in organizer post meta with keys like:
        // _OrganizerPhone, _OrganizerWebsite, _OrganizerEmail.
        $organizer_data = array(
            'name' => $organizer_name,
        );

        $organizer_website = get_post_meta( $organizer_id, '_OrganizerWebsite', true );
        if ( ! empty( $organizer_website ) && filter_var( $organizer_website, FILTER_VALIDATE_URL ) ) {
            $organizer_data['url'] = $organizer_website;
            $organizer_data['sameAs'] = $organizer_website;
        }

        $organizer_phone = get_post_meta( $organizer_id, '_OrganizerPhone', true );
        if ( ! empty( $organizer_phone ) ) {
            $organizer_data['telephone'] = $organizer_phone;
        }

        // If we have additional data beyond name, return array; otherwise return name string.
        if ( count( $organizer_data ) > 1 ) {
            return $organizer_data;
        }

        return $organizer_name;
    }
}
 
/**
 * Initialize the Event Schema Generator.
 *
 * @since 2.1.0
 */
function srk_init_event_schema_generator() {
    new SRK_Event_Schema_Generator();
}
add_action( 'init', 'srk_init_event_schema_generator' );