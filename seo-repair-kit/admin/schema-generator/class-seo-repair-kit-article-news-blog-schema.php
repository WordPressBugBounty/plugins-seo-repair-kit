<?php
/**
 * Author Schema Handler for SEO Repair Kit
 *
 * @package    SEO_Repair_Kit
 * @subpackage Schema
 * @since      2.1.0
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Handles author schema markup and user profile enhancements.
 *
 * @since 2.1.0
 */
class SeoRepairKit_AuthorSchema {

	/**
	 * Initialize author schema functionality.
	 */
	public function __construct() {
		// Admin profile fields.
		add_action( 'show_user_profile', array( $this, 'add_author_schema_fields' ) );
		add_action( 'edit_user_profile', array( $this, 'add_author_schema_fields' ) );
		add_action( 'personal_options_update', array( $this, 'save_author_schema_fields' ) );
		add_action( 'edit_user_profile_update', array( $this, 'save_author_schema_fields' ) );

		// Frontend schema output.
		add_action( 'wp_head', array( $this, 'output_author_jsonld_schema' ) );
	}

	/**
	 * Add author schema fields to user profile.
	 *
	 * @param WP_User $user User object.
	 */
	public function add_author_schema_fields( $user ) {
		?>
		<h3><?php esc_html_e( 'Author Schema Info', 'seo-repair-kit' ); ?></h3>
		<table class="form-table">
			<tr>
				<th><label for="author_full_name"><?php esc_html_e( 'Author Full Name', 'seo-repair-kit' ); ?></label></th>
				<td>
					<input type="text" name="author_full_name" id="author_full_name"
						   value="<?php echo esc_attr( get_user_meta( $user->ID, 'author_full_name', true ) ); ?>"
						   class="regular-text"/>
					<p class="description"><?php esc_html_e( 'Optional. Defaults to the user\'s display name.', 'seo-repair-kit' ); ?></p>
				</td>
			</tr>
			<tr>
				<th><label for="author_profile_url"><?php esc_html_e( 'Author Profile URL', 'seo-repair-kit' ); ?></label></th>
				<td>
					<input type="url" name="author_profile_url" id="author_profile_url"
						   value="<?php echo esc_url( get_user_meta( $user->ID, 'author_profile_url', true ) ); ?>"
						   class="regular-text"/>
					<p class="description"><?php esc_html_e( 'Optional. Defaults to the author archive URL.', 'seo-repair-kit' ); ?></p>
				</td>
			</tr>
			<tr>
				<th><label for="author_social_profiles"><?php esc_html_e( 'Author Social Profiles (sameAs)', 'seo-repair-kit' ); ?></label></th>
				<td>
					<textarea name="author_social_profiles" id="author_social_profiles" rows="4"
							  class="large-text"><?php echo esc_textarea( get_user_meta( $user->ID, 'author_social_profiles', true ) ); ?></textarea>
					<p class="description"><?php esc_html_e( 'One URL per line (Facebook, LinkedIn, etc.)', 'seo-repair-kit' ); ?></p>
				</td>
			</tr>
		</table>
		<?php
	}

	/**
	 * Save author schema fields from user profile.
	 *
	 * @param int $user_id User ID.
	 */
	public function save_author_schema_fields( $user_id ) {
		if ( ! current_user_can( 'edit_user', $user_id ) ) {
			return;
		}

		if ( isset( $_POST['author_full_name'] ) ) {
			update_user_meta( $user_id, 'author_full_name', sanitize_text_field( wp_unslash( $_POST['author_full_name'] ) ) );
		}
		if ( isset( $_POST['author_profile_url'] ) ) {
			update_user_meta( $user_id, 'author_profile_url', esc_url_raw( wp_unslash( $_POST['author_profile_url'] ) ) );
		}
		if ( isset( $_POST['author_social_profiles'] ) ) {
			update_user_meta( $user_id, 'author_social_profiles', sanitize_textarea_field( wp_unslash( $_POST['author_social_profiles'] ) ) );
		}
	}

	/**
	 * Output author JSON-LD schema markup.
	 */
	public function output_author_jsonld_schema() {
        // ✅ Check if license plan is expired - block schema output if expired
        if ( class_exists( 'SRK_License_Helper' ) && SRK_License_Helper::is_license_expired() ) {
            return;
        }

        static $already_output = false;
        if ( $already_output ) {
            return;
        }
        $already_output = true;
 
        // Use the should_output_schema check
        if ( ! $this->should_output_schema() ) {
            return;
        }
 
        global $post;
        if ( ! $post ) {
            return;
        }
 
        // Get schema type and normalize it.
        $schema_type = get_post_meta( $post->ID, 'selected_schema_type', true );
        if ( empty( $schema_type ) ) {
            $schema_type = 'Article'; // default.
        }
 
        $valid_types = array(
            'article'       => 'Article',
            'blog_posting'  => 'BlogPosting',
            'news_article'  => 'NewsArticle',
        );
 
        // Normalize key.
        $schema_type_key = strtolower( str_replace( array( ' ', '-' ), '_', $schema_type ) );
 
        // Final schema type.
        $schema_type = isset( $valid_types[ $schema_type_key ] ) ? $valid_types[ $schema_type_key ] : 'Article';
 
        // Option key.
        $option_key   = 'srk_schema_assignment_' . $schema_type_key;
        $schema_config = get_option( $option_key, array() );

        // Fallback: for BlogPosting and NewsArticle, reuse Article config if their own config is missing.
        if ( ( empty( $schema_config ) || ! is_array( $schema_config ) ) && in_array( $schema_type_key, array( 'blog_posting', 'news_article' ), true ) ) {
            $article_option_key = 'srk_schema_assignment_article';
            $article_config     = get_option( $article_option_key, array() );
            if ( ! empty( $article_config ) && is_array( $article_config ) ) {
                $schema_config = $article_config;
            }
        }

        $meta_map       = isset( $schema_config['meta_map'] ) ? $schema_config['meta_map'] : array();
        $enabled_fields = isset( $schema_config['enabled_fields'] ) ? $schema_config['enabled_fields'] : array();
 
        // Dynamic mapping rules - current schema type ke hisaab se.
        $mapping_rules   = $schema_config;
        $meta_map        = isset( $mapping_rules['meta_map'] ) ? $mapping_rules['meta_map'] : array();
 
        // Common schema fields.
        $schema = array(
            '@context'        => 'https://schema.org',
            '@type'           => $schema_type,
            'mainEntityOfPage' => array(
                '@type' => 'WebPage',
                '@id'   => get_permalink( $post ),
            ),
            'publisher' => array(
                '@type' => 'Organization',
                'name'  => get_bloginfo( 'name' ),
            ),
        );
 
        // Add logo only if site has one
        $site_icon_url = get_site_icon_url();
        if ( ! empty( $site_icon_url ) ) {
            $schema['publisher']['logo'] = array(
                '@type' => 'ImageObject',
                'url'   => $site_icon_url,
            );
        }
 
        // Define which fields to process based on enabled_fields
        $fields_to_process = array(
             'name' => function() use ($meta_map, $post) {
                return $this->resolve_mapped_value( $meta_map, 'name', $post, get_the_title( $post ) );
            },
            'headline' => function() use ($meta_map, $post) {
                return $this->resolve_mapped_value( $meta_map, 'headline', $post, get_the_title( $post ) );
            },
            'description' => function() use ($meta_map, $post) {
                return $this->resolve_mapped_value( $meta_map, 'description', $post, get_the_excerpt( $post ) );
            },
            'image' => function() use ($meta_map, $post) {
                return $this->resolve_mapped_value( $meta_map, 'image', $post, get_the_post_thumbnail_url( $post, 'full' ) );
            },
            'datePublished' => function() use ($meta_map, $post) {
                return $this->resolve_mapped_value( $meta_map, 'datePublished', $post, get_the_date( DATE_W3C, $post ) );
            },
            'dateModified' => function() use ($meta_map, $post) {
                return $this->resolve_mapped_value( $meta_map, 'dateModified', $post, get_the_modified_date( DATE_W3C, $post ) );
            },
            'articleBody' => function() use ($meta_map, $post) {
                return $this->resolve_mapped_value( $meta_map, 'articleBody', $post, wp_strip_all_tags( get_the_content( null, false, $post ) ) );
            },
            'author' => function() use ($meta_map, $post) {
                return $this->resolve_mapped_value( $meta_map, 'author', $post, get_the_author_meta( 'display_name', $post->post_author ) );
            }
        );
 
        // Process only enabled fields
        foreach ( $fields_to_process as $field => $value_callback ) {
            // Skip if field is not enabled
            if ( ! empty( $enabled_fields ) && ! in_array( $field, $enabled_fields ) ) {
                continue;
            }
           
            $value = $value_callback();
           
            if ( ! empty( $value ) ) {
                switch ( $field ) {
                     case 'name':
                        $schema['name'] = $value;
                        break;
                       
                    case 'headline':
                        $schema['headline'] = $value;
                        break;
                    case 'description':
                        $schema['description'] = $value;
                        break;
                       
                    case 'image':
                        $schema['image'] = array(
                            '@type' => 'ImageObject',
                            'url' => $value
                        );
                        break;
                       
                    case 'datePublished':
                        $schema['datePublished'] = $value;
                        break;
                       
                    case 'dateModified':
                        $schema['dateModified'] = $value;
                        break;
                       
                    case 'articleBody':
                        $schema['articleBody'] = $value;
                        break;
                       
                    case 'author':
                        // Handle author field specially
                        $author_id = $post->post_author;
                        $author_name = $value;
                       
                        // If author mapping returns user ID, get display name
                        if ( is_numeric( $author_name ) ) {
                            $author_name = get_the_author_meta( 'display_name', $author_name );
                        }
                       
                        $schema['author'] = array(
                            '@type' => 'Person',
                            'name'  => $author_name,
                        );
                        break;
                }
            }
        }
 
        // AUTHOR HANDLING - Always include author if it's Article schema type
        if ( 'Article' === $schema_type ) {
            $author_id = $post->post_author;
           
            // Detailed author only for Article.
            $author_name = get_post_meta( $post->ID, 'schema_field_author', true );
            if ( empty( $author_name ) ) {
                $author_name = get_user_meta( $author_id, 'author_full_name', true );
            }
            if ( empty( $author_name ) ) {
                $author_name = get_the_author_meta( 'display_name', $author_id );
            }
 
            $author_url = get_post_meta( $post->ID, 'schema_field_author_url', true );
            if ( empty( $author_url ) ) {
                $author_url = get_user_meta( $author_id, 'author_profile_url', true );
            }
            if ( empty( $author_url ) ) {
                $author_url = get_author_posts_url( $author_id );
            }
 
            $same_as = get_post_meta( $post->ID, 'schema_field_author_social_profiles', true );
            if ( empty( $same_as ) ) {
                $same_as = get_user_meta( $author_id, 'author_social_profiles', true );
            }
            $same_as_array = $same_as ? array_filter( array_map( 'trim', explode( "\n", $same_as ) ) ) : array();
 
            $schema['author'] = array(
                '@type' => 'Person',
                'name'  => $author_name,
                'url'   => $author_url,
            );
 
            if ( ! empty( $same_as_array ) ) {
                $schema['author']['sameAs'] = $same_as_array;
            }
        } else if ( ! isset( $schema['author'] ) ) {
            // Simple author for BlogPosting & NewsArticle if not already set
            $schema['author'] = array(
                '@type' => 'Person',
                'name'  => get_the_author_meta( 'display_name', $post->post_author ),
            );
        }

        // ✅ NEW: Validate required fields before output
        if ( ! class_exists( 'SeoRepairKit_SchemaValidator' ) ) {
            require_once plugin_dir_path( dirname( dirname( __FILE__ ) ) ) . 'includes/class-seo-repair-kit-schema-validator.php';
        }

        // Determine schema type for validation
        $schema_type = strtolower( $schema['@type'] ?? '' );
        if ( 'article' === $schema_type || 'blogposting' === $schema_type || 'newsarticle' === $schema_type ) {
            // Check if schema has all required fields (Article/BlogPosting/NewsArticle require 'headline', 'author', 'publisher')
            if ( ! SeoRepairKit_SchemaValidator::should_output_schema( $schema, $schema_type ) ) {
                // Schema is missing required fields - do not output
                return;
            }
        }

        // ✅ NEW: Check for conflicts before output
        if ( ! class_exists( 'SeoRepairKit_SchemaConflictDetector' ) ) {
            require_once plugin_dir_path( dirname( dirname( __FILE__ ) ) ) . 'includes/class-seo-repair-kit-schema-conflict-detector.php';
        }

        if ( ! empty( $schema_type ) && ! SeoRepairKit_SchemaConflictDetector::can_output_schema( $schema, $schema_type, 'article-news-blog-schema' ) ) {
            // Schema conflicts with another schema - do not output
            return;
        }
 
        echo '<script type="application/ld+json">' . wp_json_encode( $schema, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE ) . '</script>';
    }

	/**
	 * Mapping values resolve karne ke liye.
	 *
	 * @param array   $meta_map Field mapping rules.
	 * @param string  $field Field name.
	 * @param WP_Post $post Post object.
	 * @param mixed   $default_value Default value if mapping fails.
	 * @return mixed Resolved field value.
	 */
	private function resolve_mapped_value( $meta_map, $field, $post, $default_value ) {
		if ( empty( $meta_map ) || ! isset( $meta_map[ $field ] ) ) {
			return $default_value;
		}

		$mapping = $meta_map[ $field ];

		// Mapping patterns resolve karein.
		if ( strpos( $mapping, 'post:' ) !== false ) {
			return $this->resolve_post_mapping( $mapping, $post );
		} elseif ( strpos( $mapping, 'user:' ) !== false ) {
			return $this->resolve_user_mapping( $mapping, $post->post_author );
		} elseif ( strpos( $mapping, 'site:' ) !== false ) {
			return $this->resolve_site_mapping( $mapping );
		} elseif ( strpos( $mapping, 'custom:' ) !== false ) {
			return str_replace( 'custom:', '', $mapping );
		} else {
			return $mapping; // Direct value.
		}
	}

	/**
	 * Post-related mappings resolve karein.
	 *
	 * @param string  $mapping Mapping pattern.
	 * @param WP_Post $post Post object.
	 * @return mixed Resolved value.
	 */
	private function resolve_post_mapping( $mapping, $post ) {
		$mapping_key = str_replace( 'post:', '', $mapping );

		switch ( $mapping_key ) {
			case 'post_title':
				return get_the_title( $post );
			case 'post_content':
				return wp_strip_all_tags( get_the_content( null, false, $post ) );
			case 'post_excerpt':
				return get_the_excerpt( $post );
			case 'post_date':
				return get_the_date( DATE_W3C, $post );
			case 'post_modified':
				return get_the_modified_date( DATE_W3C, $post );
			case 'featured_image':
				return get_the_post_thumbnail_url( $post, 'full' );
			case 'post_author':
				return get_the_author_meta( 'display_name', $post->post_author );
			default:
				return get_post_meta( $post->ID, $mapping_key, true );
		}
	}

	/**
	 * User-related mappings resolve karein.
	 *
	 * @param string $mapping Mapping pattern.
	 * @param int    $user_id User ID.
	 * @return mixed Resolved value.
	 */
	private function resolve_user_mapping( $mapping, $user_id ) {
		$mapping_key = str_replace( 'user:', '', $mapping );
		return get_user_meta( $user_id, $mapping_key, true );
	}

	/**
	 * Site-related mappings resolve karein.
	 *
	 * @param string $mapping Mapping pattern.
	 * @return mixed Resolved value.
	 */
	private function resolve_site_mapping( $mapping ) {
		$mapping_key = str_replace( 'site:', '', $mapping );

		switch ( $mapping_key ) {
			case 'site_name':
				return get_bloginfo( 'name' );
			case 'site_description':
				return get_bloginfo( 'description' );
			case 'site_url':
				return get_site_url();
			case 'logo_url':
				$custom_logo_id = get_theme_mod( 'custom_logo' );
				return $custom_logo_id ? wp_get_attachment_url( $custom_logo_id ) : '';
			default:
				return get_option( "srk_global_{$mapping_key}", '' );
		}
	}
	private function should_output_schema() {
        if ( ! is_singular() ) {
            return false;
        }
 
        global $post;
 
        // Get schema type and normalize it.
        $schema_type = get_post_meta( $post->ID, 'selected_schema_type', true );
        if ( empty( $schema_type ) ) {
            $schema_type = 'Article'; // default.
        }
 
        $valid_types = array(
            'article'       => 'Article',
            'blog_posting'  => 'BlogPosting',
            'news_article'  => 'NewsArticle',
        );
 
        // Normalize key.
        $schema_type_key = strtolower( str_replace( array( ' ', '-' ), '_', $schema_type ) );
 
        // Final schema type.
        $schema_type = isset( $valid_types[ $schema_type_key ] ) ? $valid_types[ $schema_type_key ] : 'Article';
 
        // Option key.
        $option_key = 'srk_schema_assignment_' . $schema_type_key;
        $saved_data = get_option( $option_key, array() );
 
        // Fallback: for BlogPosting and NewsArticle, reuse Article config if their own config is missing.
        if ( ( empty( $saved_data ) || ! isset( $saved_data['meta_map'] ) ) && in_array( $schema_type_key, array( 'blog_posting', 'news_article' ), true ) ) {
            $article_option_key = 'srk_schema_assignment_article';
            $article_config     = get_option( $article_option_key, array() );
            if ( ! empty( $article_config ) && isset( $article_config['meta_map'] ) ) {
                $saved_data = $article_config;
            }
        }
 
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
}

new SeoRepairKit_AuthorSchema();