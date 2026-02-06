<?php
/**
 * Job Posting Schema Generator for SEO Repair Kit
 *
 * @package     SEO_Repair_Kit
 * @subpackage  Schema
 */
 
if ( ! defined( 'ABSPATH' ) ) {
    exit;
}
 
/**
 * Generates JobPosting schema markup with advanced field mapping.
 *
 * This class handles the generation of structured data for JobPosting schema.org
 * markup, supporting salary normalization, location formatting, and comprehensive
 * job posting details with flexible field mapping system.
 *
 * @since 2.1.0
 */
class SRK_JobPosting_Schema_Generator {
 
    /**
     * Initialize job posting schema functionality.
     *
     * @since 2.1.0
     */
    public function __construct() {
        add_action( 'wp_head', array( $this, 'output_schema' ) );
    }
 
    /**
     * Output job posting schema markup.
     *
     * Generates JSON-LD structured data for job postings based on mapped field values.
     * Supports salary ranges, locations, and comprehensive job details.
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
        if ( empty( $post ) ) {
            return;
        }
 
        $option_key = 'srk_schema_assignment_job_posting';
        $saved_data = get_option( $option_key, array() );
 
        // Handle JSON-encoded data.
        if ( is_string( $saved_data ) ) {
            $decoded = json_decode( $saved_data, true );
            if ( JSON_ERROR_NONE === json_last_error() ) {
                $saved_data = $decoded;
            }
        }
 
        if ( empty( $saved_data['meta_map'] ) || ! is_array( $saved_data['meta_map'] ) ) {
            return;
        }
 
        $field_map = $saved_data['meta_map'];
        $enabled_fields = isset( $saved_data['enabled_fields'] ) ? $saved_data['enabled_fields'] : array();
       
        $schema    = $this->build_job_posting_schema( $post, $field_map, $enabled_fields );

        // ✅ NEW: Validate required fields before output
        if ( ! class_exists( 'SeoRepairKit_SchemaValidator' ) ) {
            require_once plugin_dir_path( dirname( dirname( __FILE__ ) ) ) . 'includes/class-seo-repair-kit-schema-validator.php';
        }

        // Check if schema has all required fields (JobPosting requires 'title', 'datePosted', 'validThrough')
        if ( ! SeoRepairKit_SchemaValidator::should_output_schema( $schema, 'job_posting' ) ) {
            // Schema is missing required fields - do not output
            return;
        }

        // ✅ NEW: Check for conflicts before output
        if ( ! class_exists( 'SeoRepairKit_SchemaConflictDetector' ) ) {
            require_once plugin_dir_path( dirname( dirname( __FILE__ ) ) ) . 'includes/class-seo-repair-kit-schema-conflict-detector.php';
        }

        if ( ! SeoRepairKit_SchemaConflictDetector::can_output_schema( $schema, 'job_posting', 'jobposting-schema-generator' ) ) {
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
     * Build complete job posting schema array.
     *
     * @since 2.1.0
     *
     * @param WP_Post $post      Post object.
     * @param array   $field_map Field mapping configuration.
     * @param array   $enabled_fields Enabled fields configuration.
     * @return array Job posting schema data.
     */
    private function build_job_posting_schema( $post, $field_map, $enabled_fields ) {
        // Base schema.
        $schema = array(
            '@context' => 'https://schema.org',
            '@type'    => 'JobPosting',
        );
 
        foreach ( $field_map as $schema_key => $mapping ) {
            // Skip if field is not enabled and enabled_fields array exists
            if ( ! empty( $enabled_fields ) && ! in_array( $schema_key, $enabled_fields ) ) {
                continue;
            }
 
            $value = $this->resolve_field_value( $mapping, $post );
 
            if ( $this->is_empty( $value ) ) {
                continue;
            }
 
            switch ( $schema_key ) {
                case 'hiringOrganization':
                    $schema['hiringOrganization'] = array(
                        '@type' => 'Organization',
                        'name'  => $this->clean_text( $value ),
                    );
                    break;

                case 'baseSalary':
                    $normalized = $this->normalize_salary( $value );
                    if ( $normalized ) {
                        $schema['baseSalary'] = $normalized;
                    }
                    break;

                case 'jobLocation':
                    $location = $this->normalize_location( $value );
                    if ( $location ) {
                        $schema['jobLocation'] = $location;
                    }
                    break;

                case 'educationRequirements':
                    $edu = $this->normalize_education_requirements( $value );
                    if ( ! $this->is_empty( $edu ) ) {
                        $schema['educationRequirements'] = $edu;
                    }
                    break;

                case 'experienceRequirements':
                    $exp = $this->normalize_experience_requirements( $value );
                    if ( ! $this->is_empty( $exp ) ) {
                        $schema['experienceRequirements'] = $exp;
                    }
                    break;

                case 'applicantLocationRequirements':
                    $schema['applicantLocationRequirements'] = array(
                        '@type' => 'Country',
                        'name'  => $this->clean_text( $value ),
                    );
                    break;
 
                case 'taxonomy':
                    if ( is_array( $value ) ) {
                        foreach ( $value as $tax_name => $tax_terms ) {
                            if ( ! empty( $tax_terms ) ) {
                                $schema[ $tax_name ] = $this->clean_text( is_array( $tax_terms ) ? implode( ', ', $tax_terms ) : $tax_terms );
                            }
                        }
                    }
                    break;
 
                default:
                    $schema[ $schema_key ] = $this->clean_text( is_array( $value ) ? implode( ', ', (array) $value ) : $value );
                    break;
            }
        }
 
        return $this->remove_empty( $schema );
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
                if ( ! $this->is_empty( $val ) ) {
                    $values[] = $val;
                }
            }
            return empty( $values ) ? null : ( count( $values ) > 1 ? $values : $values[0] );
        }
 
		if ( ! is_string( $mapping ) ) {
			return null;
		}

		// Handle literal custom values with "custom:" prefix (e.g., custom:2026-01-15).
		// This is especially important for date fields like validThrough so that we
		// don't leak the "custom:" prefix into the final JSON-LD and break ISO format.
		if ( strpos( $mapping, 'custom:' ) === 0 ) {
			return str_replace( 'custom:', '', $mapping );
		}
 
        // Handle post fields with "post:" prefix.
        if ( strpos( $mapping, 'post:' ) === 0 ) {
            $field = str_replace( 'post:', '', $mapping );
            return $this->get_post_field_value( $field, $post );
        }
 
        // Handle meta fields with "meta:" prefix.
        if ( strpos( $mapping, 'meta:' ) === 0 ) {
            $meta_key = str_replace( 'meta:', '', $mapping );
            return get_post_meta( $post->ID, $meta_key, true );
        }
 
        // Handle taxonomy fields with "tax:" prefix.
        if ( strpos( $mapping, 'tax:' ) === 0 ) {
            $tax_name = str_replace( 'tax:', '', $mapping );
            if ( taxonomy_exists( $tax_name ) ) {
                $terms = wp_get_post_terms( $post->ID, $tax_name, array( 'fields' => 'names' ) );
                if ( ! is_wp_error( $terms ) && ! empty( $terms ) ) {
                    return $terms;
                }
            }
        }
 
        // Post fields (without prefix).
        $post_fields = array(
            'post_title',
            'post_excerpt',
            'post_content',
            'featured_image',
            'post_date',
            'post_modified',
        );
        if ( in_array( $mapping, $post_fields, true ) ) {
            return $this->get_post_field_value( $mapping, $post );
        }
 
        // Meta fields (direct slug).
        $meta = get_post_meta( $post->ID, $mapping, true );
        if ( ! empty( $meta ) ) {
            return $meta;
        }
 
        // Taxonomy fields (direct slug).
        if ( taxonomy_exists( $mapping ) ) {
            $terms = wp_get_post_terms( $post->ID, $mapping, array( 'fields' => 'names' ) );
            if ( ! is_wp_error( $terms ) && ! empty( $terms ) ) {
                return $terms;
            }
        }
 
        // If "taxonomy" keyword is used, return all taxonomies.
        if ( 'taxonomy' === $mapping ) {
            $taxonomies = get_object_taxonomies( $post->post_type, 'names' );
            $result     = array();
            foreach ( $taxonomies as $tax ) {
                $terms = wp_get_post_terms( $post->ID, $tax, array( 'fields' => 'names' ) );
                if ( ! is_wp_error( $terms ) && ! empty( $terms ) ) {
                    $result[ $tax ] = $terms;
                }
            }
            return $result;
        }
 
        // Literal fallback.
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
                return get_the_excerpt( $post );
            case 'post_content':
                return wp_strip_all_tags( $post->post_content );
            case 'featured_image':
                return get_the_post_thumbnail_url( $post, 'full' );
            case 'post_date':
                return get_the_date( DATE_W3C, $post );
            case 'post_modified':
                return get_the_modified_date( DATE_W3C, $post );
            default:
                return get_post_field( $field_name, $post->ID );
        }
    }
 
    /**
     * Normalize salary information.
     *
     * @since 2.1.0
     *
     * @param mixed $raw Raw salary data.
     * @return array|null Normalized salary schema.
     */
    private function normalize_salary( $raw ) {
        if ( $this->is_empty( $raw ) ) {
            return null;
        }
 
        // Always flatten array into string.
        if ( is_array( $raw ) ) {
            $raw = implode( ' ', $raw );
        }
 
        $currency = $this->extract_currency( $raw );
        $unit     = $this->extract_salary_unit( $raw );
 
        preg_match_all( '/\d+(?:[.,]\d+)?/', $raw, $matches );
        $numbers = array_map( 'floatval', $matches[0] );
 
        if ( empty( $numbers ) ) {
            return null;
        }
 
        $value_node = array(
            '@type'    => 'QuantitativeValue',
            'unitText' => $unit,
        );
 
        if ( count( $numbers ) === 1 ) {
            $value_node['value'] = $numbers[0];
        } else {
            $value_node['minValue'] = min( $numbers );
            $value_node['maxValue'] = max( $numbers );
        }
 
        return array(
            '@type'    => 'MonetaryAmount',
            'currency' => $currency ?: 'PKR',
            'value'    => $value_node,
        );
    }
 
    /**
     * Extract currency from salary string.
     *
     * @since 2.1.0
     *
     * @param string $value Salary string.
     * @return string Currency code.
     */
    private function extract_currency( $value ) {
        if ( preg_match( '/\bUSD\b|\$/i', $value ) ) {
            return 'USD';
        }
        if ( preg_match( '/\bEUR\b|€/i', $value ) ) {
            return 'EUR';
        }
        if ( preg_match( '/\bGBP\b|£/i', $value ) ) {
            return 'GBP';
        }
        if ( preg_match( '/\bINR\b|₹/i', $value ) ) {
            return 'INR';
        }
        if ( preg_match( '/\bPKR\b|₨|Rs\.?/i', $value ) ) {
            return 'PKR';
        }
        return '';
    }
 
    /**
     * Extract salary unit from string.
     *
     * @since 2.1.0
     *
     * @param string $value Salary string.
     * @return string Salary unit.
     */
    private function extract_salary_unit( $value ) {
        $value_lower = strtolower( $value );
        $unit_map    = array(
            'HOUR'  => array( 'per hour', '/hr', 'hourly' ),
            'DAY'   => array( 'per day', 'daily' ),
            'WEEK'  => array( 'per week', 'weekly' ),
            'MONTH' => array( 'per month', 'monthly' ),
            'YEAR'  => array( 'per year', 'yearly', 'annum', 'annual' ),
        );
 
        foreach ( $unit_map as $unit => $needles ) {
            foreach ( $needles as $needle ) {
                if ( strpos( $value_lower, $needle ) !== false ) {
                    return $unit;
                }
            }
        }
 
        return 'YEAR';
    }
 
    /**
     * Normalize location information.
     *
     * @since 2.1.0
     *
     * @param mixed $value Location data.
     * @return array|null Normalized location schema.
     */
    private function normalize_location( $value ) {
        // Simple string address: wrap into PostalAddress with streetAddress only.
        if ( is_string( $value ) ) {
            $address_text = $this->clean_text( $value );
            if ( '' === $address_text ) {
                return null;
            }

            return array(
                '@type'   => 'Place',
                'address' => array(
                    '@type'         => 'PostalAddress',
                    'streetAddress' => $address_text,
                ),
            );
        }

        // If an associative array is provided, try to map known keys to PostalAddress fields.
        if ( is_array( $value ) ) {
            $address = array(
                '@type' => 'PostalAddress',
            );

            // If the array is associative, respect specific keys when present.
            $is_assoc = count( array_filter( array_keys( $value ), 'is_string' ) ) > 0;
            if ( $is_assoc ) {
                $field_map = array(
                    'streetAddress'    => 'streetAddress',
                    'addressLocality'  => 'addressLocality',
                    'addressRegion'    => 'addressRegion',
                    'postalCode'       => 'postalCode',
                    'addressCountry'   => 'addressCountry',
                );

                foreach ( $field_map as $key => $prop ) {
                    if ( isset( $value[ $key ] ) && ! $this->is_empty( $value[ $key ] ) ) {
                        $address[ $prop ] = $this->clean_text( $value[ $key ] );
                    }
                }

                // If nothing meaningful was added, fall back to a flat string.
                if ( count( $address ) === 1 ) {
                    $flat = implode( ', ', array_filter( array_map( array( $this, 'clean_text' ), $value ) ) );
                    if ( '' === $flat ) {
                        return null;
                    }
                    $address['streetAddress'] = $flat;
                }
            } else {
                // Numeric array: join all parts into a single streetAddress line.
                $flat = implode( ', ', array_filter( array_map( array( $this, 'clean_text' ), $value ) ) );
                if ( '' === $flat ) {
                    return null;
                }
                $address['streetAddress'] = $flat;
            }

            return array(
                '@type'   => 'Place',
                'address' => $address,
            );
        }

        return null;
    }

    /**
     * Normalize education requirements into a structure Google accepts.
     *
     * @since 2.1.0
     *
     * @param mixed $raw Raw education requirements.
     * @return array|string|null Normalized education requirements.
     */
    private function normalize_education_requirements( $raw ) {
        if ( $this->is_empty( $raw ) ) {
            return null;
        }

        if ( is_array( $raw ) ) {
            $raw = implode( ' ', $raw );
        }

        $text = strtolower( wp_strip_all_tags( (string) $raw ) );

        // Map common phrases to Google's documented categories.
        $category = '';
        if ( false !== strpos( $text, 'high school' ) || false !== strpos( $text, 'secondary school' ) ) {
            $category = 'high school';
        } elseif ( false !== strpos( $text, 'associate' ) ) {
            $category = 'associate degree';
        } elseif ( false !== strpos( $text, 'bachelor' ) || false !== strpos( $text, 'bsc' ) || false !== strpos( $text, 'ba ' ) ) {
            $category = 'bachelor degree';
        } elseif ( false !== strpos( $text, 'master' ) || false !== strpos( $text, 'msc' ) || false !== strpos( $text, 'ma ' ) || false !== strpos( $text, 'phd' ) || false !== strpos( $text, 'doctor' ) ) {
            $category = 'postgraduate degree';
        } elseif ( false !== strpos( $text, 'certificate' ) || false !== strpos( $text, 'certification' ) ) {
            $category = 'professional certificate';
        } elseif ( false !== strpos( $text, 'no requirement' ) || false !== strpos( $text, 'no requirements' ) ) {
            $category = 'no requirements';
        }

        if ( $category ) {
            return array(
                '@type'             => 'EducationalOccupationalCredential',
                'credentialCategory'=> $category,
            );
        }

        // Fallback to plain text if we can't confidently map to a known category.
        $clean = $this->clean_text( $raw );
        return $clean !== '' ? $clean : null;
    }

    /**
     * Normalize experience requirements into a structure Google accepts.
     *
     * @since 2.1.0
     *
     * @param mixed $raw Raw experience requirements.
     * @return array|string|null Normalized experience requirements.
     */
    private function normalize_experience_requirements( $raw ) {
        if ( $this->is_empty( $raw ) ) {
            return null;
        }

        if ( is_array( $raw ) ) {
            $raw = implode( ' ', $raw );
        }

        $text = strtolower( wp_strip_all_tags( (string) $raw ) );

        // Try to extract a duration in years or months.
        if ( preg_match( '/(\d+(?:\.\d+)?)\s*(year|yr|years|yrs|month|months|mo)\b/i', $text, $m ) ) {
            $num  = (float) $m[1];
            $unit = strtolower( $m[2] );

            $months = $num;
            if ( 'year' === $unit || 'yr' === $unit || 'years' === $unit || 'yrs' === $unit ) {
                $months = $num * 12;
            }

            return array(
                '@type'             => 'OccupationalExperienceRequirements',
                'monthsOfExperience'=> (int) round( $months ),
            );
        }

        // Handle explicit "no experience" style phrases.
        if ( false !== strpos( $text, 'no experience' ) || false !== strpos( $text, 'no requirement' ) ) {
            return 'no requirements';
        }

        // Fallback to plain text.
        $clean = $this->clean_text( $raw );
        return $clean !== '' ? $clean : null;
    }
 
    /**
     * Clean text by removing HTML tags and trimming.
     *
     * @since 2.1.0
     *
     * @param mixed $value Text to clean.
     * @return mixed Cleaned text.
     */
    private function clean_text( $value ) {
        return is_string( $value ) ? trim( wp_strip_all_tags( $value ) ) : $value;
    }
 
    /**
     * Check if value is empty.
     *
     * @since 2.1.0
     *
     * @param mixed $value Value to check.
     * @return bool True if empty.
     */
    private function is_empty( $value ) {
        if ( null === $value ) {
            return true;
        }
        if ( is_string( $value ) ) {
            return trim( $value ) === '';
        }
        if ( is_array( $value ) ) {
            return empty(
                array_filter(
                    $value,
                    function( $x ) {
                        return ! $this->is_empty( $x );
                    }
                )
            );
        }
        return false;
    }
 
    /**
     * Remove empty values from array.
     *
     * @since 2.1.0
     *
     * @param mixed $array Array to clean.
     * @return mixed Cleaned array.
     */
    private function remove_empty( $array ) {
        if ( ! is_array( $array ) ) {
            return $array;
        }
        return array_filter(
            array_map( array( $this, 'remove_empty' ), $array ),
            function( $v ) {
                return ! $this->is_empty( $v );
            }
        );
    }
}
 
/**
 * Initialize the Job Posting Schema Generator.
 *
 * @since 2.1.0
 */
add_action(
    'init',
    function() {
        new SRK_JobPosting_Schema_Generator();
    }
);