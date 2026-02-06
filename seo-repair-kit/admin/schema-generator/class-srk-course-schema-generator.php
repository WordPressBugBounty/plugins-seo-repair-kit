<?php
/**
 * Course Schema Generator for SEO Repair Kit
 *
 * @package     SEO_Repair_Kit
 * @subpackage  Schema
 */
 
if ( ! defined( 'ABSPATH' ) ) {
    exit;
}
 
/**
 * Generates Course schema markup for Tutor LMS courses.
 *
 * This class handles the generation of structured data for Course schema.org
 * markup, specifically designed for Tutor LMS course post types. It supports
 * dynamic field mapping and resolves real values from course content.
 *
 * @since 2.1.0
 */
class SRK_Course_Schema_Generator {
 
    /**
     * Initialize course schema functionality.
     *
     * @since 2.1.0
     */
    public function __construct() {
        add_action( 'wp_head', array( $this, 'output_schema' ) );
    }
 
    /**
     * Output Course schema markup.
     *
     * Generates JSON-LD structured data for courses based on mapped field values.
     * Only outputs on single course pages and when schema mapping is configured.
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

        if ( ! is_singular() ) {
            return;
        }
 
        global $post;
 
        $option_key = 'srk_schema_assignment_course';
        $saved_data = get_option( $option_key, array() );
 
        // Handle JSON-encoded data.
        if ( is_string( $saved_data ) ) {
            $decoded = json_decode( $saved_data, true );
            if ( JSON_ERROR_NONE === json_last_error() ) {
                $saved_data = $decoded;
            }
        }
 
        if ( empty( $saved_data['meta_map'] ) ) {
            return;
        }
 
        $field_map = $saved_data['meta_map'];
        $enabled_fields = isset( $saved_data['enabled_fields'] ) ? $saved_data['enabled_fields'] : array();
        $schema    = $this->build_course_schema( $post, $field_map, $enabled_fields );

        // ✅ NEW: Validate required fields before output
        if ( ! class_exists( 'SeoRepairKit_SchemaValidator' ) ) {
            require_once plugin_dir_path( dirname( dirname( __FILE__ ) ) ) . 'includes/class-seo-repair-kit-schema-validator.php';
        }

        // Check if schema has all required fields (Course requires 'name', 'provider')
        if ( ! SeoRepairKit_SchemaValidator::should_output_schema( $schema, 'course' ) ) {
            // Schema is missing required fields - do not output
            return;
        }

        // ✅ NEW: Check for conflicts before output
        if ( ! class_exists( 'SeoRepairKit_SchemaConflictDetector' ) ) {
            require_once plugin_dir_path( dirname( dirname( __FILE__ ) ) ) . 'includes/class-seo-repair-kit-schema-conflict-detector.php';
        }

        if ( ! SeoRepairKit_SchemaConflictDetector::can_output_schema( $schema, 'course', 'course-schema-generator' ) ) {
            // Schema conflicts with another schema - do not output
            return;
        }

        if ( count( $schema ) > 2 ) {
            echo '<script type="application/ld+json">' .
                wp_json_encode( $schema, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT ) .
                '</script>';
        }
    }
 
    /**
     * Build complete course schema array.
     *
     * @since 2.1.0
     *
     * @param WP_Post $post      Course post object.
     * @param array   $field_map Field mapping configuration.
     * @param array   $enabled_fields Enabled fields configuration.
     * @return array Course schema data.
     */
    private function build_course_schema( $post, $field_map, $enabled_fields ) {
        $schema = array(
            '@context' => 'https://schema.org',
            '@type'    => 'Course',
        );
 
        // Valid schema.org properties for Course.
        $valid_props = array(
            'name',
            'description',
            'provider',
            'offers',
            'hasCourseInstance',
            'courseCode',
            'educationalCredentialAwarded',
            'occupationalCredentialAwarded',
            'inLanguage',
            'about',
            'audience',
            'duration',
        );
 
        foreach ( $field_map as $schema_key => $mapping ) {
            // Skip if field is not enabled and enabled_fields array exists
            if ( ! empty( $enabled_fields ) && ! in_array( $schema_key, $enabled_fields ) ) {
                continue;
            }
 
            $value = $this->resolve_field_value( $mapping, $post );
 
            if ( empty( $value ) ) {
                continue; // Skip if empty.
            }
 
            switch ( $schema_key ) {
                case 'provider':
                    $schema['provider'] = array(
                        '@type' => 'Organization',
                        'name'  => $value,
                    );
                    break;
 
                case 'offers':
                    $schema['offers'] = $this->build_offer_schema( $value, $post );
                    break;
 
                case 'hasCourseInstance':
                    $schema['hasCourseInstance'] = $this->build_course_instance_schema( $value, $post );
                    break;
 
                case 'duration':
                    $schema['duration'] = $this->convert_to_iso_duration( $value );
                    break;
 
                default:
                    if ( in_array( $schema_key, $valid_props, true ) ) {
                        $schema[ $schema_key ] = $value;
                    } else {
                        if ( ! isset( $schema['additionalProperty'] ) ) {
                            $schema['additionalProperty'] = array();
                        }
                        $schema['additionalProperty'][] = array(
                            '@type' => 'PropertyValue',
                            'name'  => $schema_key,
                            'value' => $value,
                        );
                    }
                    break;
            }
        }
 
        return $schema;
    }
 
    /**
     * Build offer schema for course pricing.
     *
     * @since 2.1.0
     *
     * @param mixed   $value Price value.
     * @param WP_Post $post  Course post object.
     * @return array Offer schema data.
     */
    private function build_offer_schema( $value, $post ) {
        $price    = $this->extract_price( $value );
        $currency = $this->extract_currency( $value );
 
        $offer = array(
            '@type'           => 'Offer',
            'name'            => __( 'Course Enrollment', 'seo-repair-kit' ),
            'price'           => $price,
            'priceCurrency'   => $currency,
            'url'             => get_permalink( $post ),
            'availability'    => 'https://schema.org/InStock',
            'validFrom'       => gmdate( DATE_W3C ),
        );
 
        if ( 0 === $price ) {
            $offer['price']          = '0';
            $offer['priceCurrency']  = 'USD';
        }
 
        return $offer;
    }
 
    /**
     * Build course instance schema.
     *
     * @since 2.1.0
     *
     * @param mixed   $value Start date value.
     * @param WP_Post $post  Course post object.
     * @return array Course instance schema data.
     */
    private function build_course_instance_schema( $value, $post ) {
        $timestamp = strtotime( $value );
        $start_date = ( false !== $timestamp ) ? gmdate( DATE_W3C, $timestamp ) : gmdate( DATE_W3C );
 
        return array(
            '@type'     => 'CourseInstance',
            'startDate' => $start_date,
            'url'       => get_permalink( $post ),
        );
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
        if ( stripos( $value, 'free' ) !== false ) {
            return 0;
        }
        return preg_match( '/(\d+\.?\d*)/', $value, $matches ) ? floatval( $matches[1] ) : 0;
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
        if ( preg_match( '/\$|USD/i', $value ) ) {
            return 'USD';
        }
        if ( preg_match( '/€|EUR/i', $value ) ) {
            return 'EUR';
        }
        if ( preg_match( '/£|GBP/i', $value ) ) {
            return 'GBP';
        }
        return 'USD';
    }
 
    /**
     * Convert duration string to ISO 8601 format.
     *
     * @since 2.1.0
     *
     * @param string $value Duration string.
     * @return string ISO 8601 duration.
     */
    private function convert_to_iso_duration( $value ) {
        // Return if already in ISO format.
        if ( preg_match( '/^P(?:\d+[YMWD])*T?(?:\d+[HMS])*$/', $value ) ) {
            return $value;
        }
 
        if ( preg_match( '/(\d+)\s*hours?/i', $value, $matches ) ) {
            return 'PT' . $matches[1] . 'H';
        }
        if ( preg_match( '/(\d+)\s*minutes?/i', $value, $matches ) ) {
            return 'PT' . $matches[1] . 'M';
        }
        if ( preg_match( '/(\d+)\s*days?/i', $value, $matches ) ) {
            return 'P' . $matches[1] . 'D';
        }
        if ( preg_match( '/(\d+)\s*weeks?/i', $value, $matches ) ) {
            return 'P' . $matches[1] . 'W';
        }
        if ( preg_match( '/(\d+)\s*months?/i', $value, $matches ) ) {
            return 'P' . $matches[1] . 'M';
        }
 
        return $value;
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
        if ( is_array( $mapping ) ) {
            $values = array();
            foreach ( $mapping as $map ) {
                $val = $this->resolve_field_value( $map, $post );
                if ( ! empty( $val ) ) {
                    $values[] = $val;
                }
            }
            return ! empty( $values ) ? ( count( $values ) > 1 ? $values : $values[0] ) : null;
        }
 
        if ( ! is_string( $mapping ) ) {
            return null;
        }
 
        // Handle "post:field" pattern.
        if ( preg_match( '/^post:(.+)$/', $mapping, $matches ) ) {
            return $this->get_post_field_value( $matches[1], $post );
        }
 
        // Handle "meta:field" pattern.
        if ( preg_match( '/^meta:(.+)$/', $mapping, $matches ) ) {
            return get_post_meta( $post->ID, $matches[1], true );
        }
 
        // Handle "[tax:taxonomy]" pattern.
        if ( preg_match( '/^\[tax:(.+)\]$/', $mapping, $matches ) ) {
            $taxonomy = $matches[1];
            if ( 'post_tag' === $taxonomy && taxonomy_exists( 'course-tag' ) ) {
                $taxonomy = 'course-tag';
            }
            if ( 'category' === $taxonomy && taxonomy_exists( 'course-category' ) ) {
                $taxonomy = 'course-category';
            }
 
            if ( taxonomy_exists( $taxonomy ) ) {
                $terms = wp_get_post_terms( $post->ID, $taxonomy, array( 'fields' => 'names' ) );
                return ! empty( $terms ) ? implode( ', ', $terms ) : '';
            }
            return '';
        }
 
        // Plain taxonomy slug.
        if ( taxonomy_exists( $mapping ) ) {
            $terms = wp_get_post_terms( $post->ID, $mapping, array( 'fields' => 'names' ) );
            return ! empty( $terms ) ? implode( ', ', $terms ) : '';
        }
 
        // Fallback: return directly.
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
            case 'title':
            case 'post_title':
                return get_the_title( $post );
 
            case 'excerpt':
            case 'post_excerpt':
                return get_the_excerpt( $post );
 
            case 'content':
            case 'post_content':
                return wp_strip_all_tags( $post->post_content );
 
            case 'image':
            case 'featured_image':
            case 'post_thumbnail':
                return get_the_post_thumbnail_url( $post, 'full' );
 
            case 'date':
            case 'post_date':
                return get_the_date( DATE_W3C, $post );
 
            case 'modified':
            case 'post_modified':
                return get_the_modified_date( DATE_W3C, $post );
 
            default:
                return get_post_field( $field_name, $post->ID );
        }
    }
 
    /**
     * Initialize the class.
     *
     * @since 2.1.0
     */
    public static function init() {
        new self();
    }
}
 
// Initialize the class.
add_action( 'init', array( 'SRK_Course_Schema_Generator', 'init' ) );