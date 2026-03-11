<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Class SRK_Meta_Output
 *
 * @since 2.1.3
 * @version 2.1.3
 */
class SRK_Meta_Output {

	public function __construct() {
		add_filter( 'pre_get_document_title', array( $this, 'filter_title' ), 20 );
		add_filter( 'document_title_parts', array( $this, 'modify_title_parts' ), 20 );
		add_filter( 'document_title_separator', array( $this, 'get_title_separator' ), 10 );
		add_filter( 'get_the_archive_title', array( $this, 'filter_archive_title' ), 10, 1 );

		remove_action( 'wp_head', '_wp_render_title_tag', 1 );
		remove_action( 'wp_head', 'wp_robots', 1 );

		add_action( 'wp_head', array( $this, 'render_srk_meta_block' ), 1 );
	}

	public function render_srk_meta_block() {
		if ( is_admin() || is_feed() ) {
			return;
		}

		$version = defined( 'SEO_REPAIR_KIT_VERSION' ) ? SEO_REPAIR_KIT_VERSION : '2.1.3';

		echo '<!-- SEO Repair Kit ' . esc_attr( $version ) . ' - seorepairkit.com -->' . "\n\n";
		echo '<meta name="generator" content="SEO Repair Kit ' . esc_attr( $version ) . '" />' . "\n";

		$doc_title = wp_get_document_title();
		if ( ! empty( $doc_title ) ) {
			echo '<title>' . esc_html( $doc_title ) . '</title>' . "\n";
			echo '<meta name="title" content="' . esc_attr( $doc_title ) . '" />' . "\n";
		}

		$this->render_meta_description();
		$this->render_robots();
		$this->render_canonical();

		echo '<!-- /SEO Repair Kit -->' . "\n\n";
	}

	private function get_archive_title() {
		$srk_meta          = get_option( 'srk_meta', array() );
		$archives_settings = isset( $srk_meta['archives'] ) && is_array( $srk_meta['archives'] )
			? $srk_meta['archives']
			: array();

		$global_settings = isset( $srk_meta['global'] ) && is_array( $srk_meta['global'] )
			? $srk_meta['global']
			: array();
		$separator       = isset( $global_settings['title_separator'] )
			? $global_settings['title_separator']
			: '-';

		$archive_type = '';
		$author_name  = '';
		$author_first = '';
		$author_last  = '';
		$author_bio   = '';
		$date_str     = '';
		$search_str   = '';

		if ( is_author() ) {
			$archive_type = 'author';
			$author       = get_queried_object();
			if ( $author && is_object( $author ) && isset( $author->ID ) ) {
				$author_name  = $author->display_name ?? '';
				$author_first = get_user_meta( $author->ID, 'first_name', true );
				$author_last  = get_user_meta( $author->ID, 'last_name', true );
				$author_bio   = get_user_meta( $author->ID, 'description', true );
			}
		} elseif ( is_date() ) {
			$archive_type = 'date';
			if ( is_day() ) {
				$year     = get_query_var( 'year' );
				$monthnum = get_query_var( 'monthnum' );
				$day      = get_query_var( 'day' );
				$date_str = date_i18n( get_option( 'date_format' ), strtotime( "$year-$monthnum-$day" ) );
			} elseif ( is_month() ) {
				$year     = get_query_var( 'year' );
				$monthnum = get_query_var( 'monthnum' );
				$date_str = date_i18n( 'F Y', strtotime( "$year-$monthnum-01" ) );
			} elseif ( is_year() ) {
				$year     = get_query_var( 'year' );
				$date_str = $year;
			} else {
				$date_str = date_i18n( 'F Y' );
			}
		} elseif ( is_search() ) {
			$archive_type = 'search';
			$search_str   = get_search_query();
		} elseif ( is_post_type_archive() ) {
			$archive_type = 'post_type';
			$post_type    = get_query_var( 'post_type' );
			if ( is_array( $post_type ) ) {
				$post_type = reset( $post_type );
			}
		}

		if ( empty( $archive_type ) ) {
			return '';
		}

		$settings = isset( $archives_settings[ $archive_type ] ) && is_array( $archives_settings[ $archive_type ] )
			? $archives_settings[ $archive_type ]
			: array();

		if ( ! empty( $settings['title'] ) ) {
			$template     = $settings['title'];
			// Get current date info
             $current_date = date_i18n( get_option('date_format') );
             $current_day  = date_i18n( 'j' );
             $current_month = date_i18n( 'F' );
             $current_year  = date_i18n( 'Y' );

            $replacements = array(
				'%sep%'        => $separator,
				'%site_title%'   => get_bloginfo( 'name' ),
				'%sitedesc%'   => get_bloginfo( 'description' ),

				'%current_date%' => $current_date,
				'%day%'          => $current_day,
				'%month%'        => $current_month,
				'%year%'         => $current_year,
            );

			if ( 'author' === $archive_type ) {
				$replacements['%author%']          = $author_name;
				$replacements['%author_first_name%'] = $author_first;
				$replacements['%author_last_name%']  = $author_last;
				$replacements['%author_bio%']        = $author_bio;
				$replacements['%archive_title%']     = $author_name;
                $replacements['%archive_description%'] = $author_bio; 
                $replacements['%date%'] = '';
			} elseif ( 'date' === $archive_type ) {
				$replacements['%date%']            = $date_str;
				$replacements['%archive_title%']   = $date_str;
				$replacements['%current_date%']    = date_i18n( get_option( 'date_format' ) );
				$replacements['%month%']           = date_i18n( 'F' );
				$replacements['%year%']            = date_i18n( 'Y' );
			} elseif ( 'search' === $archive_type ) {
				$replacements['%search%']          = $search_str;
				$replacements['%archive_title%']   = sprintf( __( 'Search Results for: %s', 'seo-repair-kit' ), $search_str );
			} elseif ( 'post_type' === $archive_type ) {
				$post_type_obj                     = get_post_type_object( $post_type );
				$pt_name                           = $post_type_obj ? $post_type_obj->label : $post_type;
				$replacements['%archive_title%']     = $pt_name;
			}

			$title = str_replace( array_keys( $replacements ), array_values( $replacements ), $template );
			$title = preg_replace( '/%[a-z_]+%/i', '', $title );
			$title = preg_replace( '/\s+/', ' ', $title );
			$title = trim( $title );
			$title = wp_strip_all_tags( $title );

			return $title;
		}

		if ( 'author' === $archive_type ) {
			return $author_name . ' ' . $separator . ' ' . get_bloginfo( 'name' );
		} elseif ( 'date' === $archive_type ) {
			return $date_str . ' ' . $separator . ' ' . get_bloginfo( 'name' );
		} elseif ( 'search' === $archive_type ) {
			return sprintf( __( 'Search Results for: %s', 'seo-repair-kit' ), $search_str );
		} elseif ( 'post_type' === $archive_type ) {
			$post_type_obj = get_post_type_object( $post_type );
			$pt_name       = $post_type_obj ? $post_type_obj->label : $post_type;
			return $pt_name . ' ' . $separator . ' ' . get_bloginfo( 'name' );
		}

		return '';
	}

	private function get_archive_description() {
		$srk_meta          = get_option( 'srk_meta', array() );
		$archives_settings = isset( $srk_meta['archives'] ) && is_array( $srk_meta['archives'] )
			? $srk_meta['archives']
			: array();

		$archive_type = '';
		$author_bio   = '';
		$author_name  = '';
		$date_str     = '';
		$search_str   = '';

		if ( is_author() ) {
			$archive_type = 'author';
			$author       = get_queried_object();

			if ( $author && is_object( $author ) && isset( $author->ID ) ) {
				$author_bio  = get_user_meta( $author->ID, 'description', true );
				$author_name = $author->display_name ?? '';
			}
		} elseif ( is_date() ) {
			$archive_type = 'date';
			if ( is_day() ) {
				$year     = get_query_var( 'year' );
				$monthnum = get_query_var( 'monthnum' );
				$day      = get_query_var( 'day' );
				$date_str = date_i18n( get_option( 'date_format' ), strtotime( "$year-$monthnum-$day" ) );
			} elseif ( is_month() ) {
				$year     = get_query_var( 'year' );
				$monthnum = get_query_var( 'monthnum' );
				$date_str = date_i18n( 'F Y', strtotime( "$year-$monthnum-01" ) );
			} elseif ( is_year() ) {
				$date_str = get_query_var( 'year' );
			} else {
				$date_str = date_i18n( 'F Y' );
			}
		} elseif ( is_search() ) {
			$archive_type = 'search';
			$search_str   = get_search_query();
		}

		if ( empty( $archive_type ) ) {
			return '';
		}

		$settings = isset( $archives_settings[ $archive_type ] ) && is_array( $archives_settings[ $archive_type ] )
			? $archives_settings[ $archive_type ]
			: array();

		if ( ! empty( $settings['description'] ) ) {
			$template     = $settings['description'];
			// Get current date info
			$current_date = date_i18n( get_option('date_format') );
			$current_day  = date_i18n( 'j' );
			$current_month = date_i18n( 'F' );
			$current_year  = date_i18n( 'Y' );

			$replacements = array(
				'%sitedesc%' => get_bloginfo( 'description' ),
				'%current_date%' => $current_date,
				'%day%'          => $current_day,
				'%month%'        => $current_month,
				'%year%'         => $current_year,
			);

			if ( 'author' === $archive_type ) {
				$replacements['%author_bio%']        = $author_bio;
				$replacements['%author%']            = $author_name;
				$replacements['%archive_description%'] = $author_bio;
                $replacements['%date%'] = '';
                $replacements['%search%'] = '';
			} elseif ( 'date' === $archive_type ) {
				$replacements['%date%']                = $date_str;
				$replacements['%archive_description%'] = sprintf( __( 'Posts published in %s', 'seo-repair-kit' ), $date_str );
			} elseif ( 'search' === $archive_type ) {
				$replacements['%search%']              = $search_str;
				$replacements['%archive_description%'] = sprintf( __( 'Search results for %s', 'seo-repair-kit' ), $search_str );
			}

			$description = str_replace( array_keys( $replacements ), array_values( $replacements ), $template );
			$description = preg_replace( '/%[a-z_]+%/i', '', $description );
			$description = preg_replace( '/\s+/', ' ', $description );
			$description = trim( $description );
			$description = wp_strip_all_tags( $description );

			return $description;
		}

		if ( 'author' === $archive_type ) {
			return $author_bio;
		} elseif ( 'date' === $archive_type ) {
			return sprintf( __( 'Posts published in %s', 'seo-repair-kit' ), $date_str );
		} elseif ( 'search' === $archive_type ) {
			return sprintf( __( 'Search results for %s', 'seo-repair-kit' ), $search_str );
		}

		return '';
	}

	public function emergency_archive_description_fix() {
		if ( is_admin() || is_feed() ) {
			return;
		}

		if ( ! is_author() && ! is_date() && ! is_search() ) {
			return;
		}

		$description = $this->get_archive_description();

		if ( ! empty( $description ) ) {
			echo '<meta name="description" content="' . esc_attr( wp_strip_all_tags( $description ) ) . '" />' . "\n";
		}
	}

	private function get_archive_robots() {
		$robots_arr = SRK_Meta_Resolver::resolve_robots_for_context( 'archive' );
		return SRK_Meta_Resolver::build_robots_string( $robots_arr );
	}

	public function get_title_separator() {
		$srk_meta        = get_option( 'srk_meta', array() );
		$global_settings = isset( $srk_meta['global'] ) && is_array( $srk_meta['global'] ) ? $srk_meta['global'] : array();

		return isset( $global_settings['title_separator'] )
			? $global_settings['title_separator']
			: get_option( 'srk_title_separator', '-' );
	}

	public function modify_title_parts( $title_parts ) {
		if ( is_admin() ) {
			return $title_parts;
		}

		$srk_meta        = get_option( 'srk_meta', array() );
		$global_settings = isset( $srk_meta['global'] ) && is_array( $srk_meta['global'] ) ? $srk_meta['global'] : array();
		$separator       = isset( $global_settings['title_separator'] ) ? $global_settings['title_separator'] : get_option( 'srk_title_separator', '-' );

		$global_template = isset( $global_settings['title_template'] ) ? $global_settings['title_template'] : get_option( 'srk_title_template', '%title% %sep% %site_title%' );

		$global_template = str_replace( '%sep%', $separator, $global_template );

		return $title_parts;
	}

	public function filter_title( $title ) {
		if ( is_admin() ) {
			return $title;
		}

		if ( is_author() || is_date() || is_search() || is_post_type_archive() ) {
			$archive_title = $this->get_archive_title();
			if ( ! empty( $archive_title ) ) {
				return $archive_title;
			}
		}

		if ( is_front_page() || is_home() ) {
			$home_title = get_option( 'srk_home_title', '' );
			if ( ! empty( $home_title ) ) {
				return $this->replace_variables( $home_title );
			}
		}

		if ( is_category() || is_tag() || is_tax() ) {
			$term = get_queried_object();
			if ( $term && isset( $term->term_id ) ) {
				$taxonomy_title = $this->get_taxonomy_title( $term );
				if ( ! empty( $taxonomy_title ) ) {
					return $taxonomy_title;
				}
			}
		}

		if ( is_singular() ) {
			$post_id = get_the_ID();
			if ( $post_id ) {
				$resolved_title = SRK_Meta_Resolver::get_meta_title( $post_id );
				if ( ! empty( $resolved_title ) ) {
					return $resolved_title;
				}
			}
		}

		$srk_meta        = get_option( 'srk_meta', array() );
		$global_settings = isset( $srk_meta['global'] ) && is_array( $srk_meta['global'] ) ? $srk_meta['global'] : array();

		$global_template = isset( $global_settings['title_template'] ) ? $global_settings['title_template'] : get_option( 'srk_title_template', '%title% %sep% %site_title%' );

		if ( empty( $global_template ) ) {
			return $title;
		}

		$page_title = $this->get_page_title();

		$separator = isset( $global_settings['title_separator'] ) ? $global_settings['title_separator'] : get_option( 'srk_title_separator', '-' );

		$replacements = array(
			'%title%'      => $page_title,
			'%site_title%' => get_bloginfo( 'name', 'display' ),
			'%tagline%'    => get_bloginfo( 'description', 'display' ),
			'%sep%'        => $separator,
			'%sitedesc%'   => get_bloginfo( 'description', 'display' ),
		);

		$new_title = str_replace(
			array_keys( $replacements ),
			array_values( $replacements ),
			$global_template
		);

		$new_title = str_replace( '|', $separator, $new_title );
		$new_title = preg_replace( '/\s+/', ' ', $new_title );
		$new_title = trim( $new_title );

		return $new_title;
	}

	private function get_page_title() {
		$title = '';

		if ( is_singular() ) {
			global $post;
			if ( $post ) {
				$custom_title = get_post_meta( $post->ID, '_srk_meta_title', true );
				if ( ! empty( $custom_title ) ) {
					return $this->replace_variables( $custom_title );
				}
				$title = get_the_title( $post->ID );
			}
		} elseif ( is_front_page() && is_home() ) {
			$title = get_bloginfo( 'name', 'display' );
		} elseif ( is_front_page() ) {
			$title = get_bloginfo( 'name', 'display' );
		} elseif ( is_home() ) {
			$page_for_posts = get_option( 'page_for_posts' );
			if ( $page_for_posts ) {
				$title = get_the_title( $page_for_posts );
			} else {
				$title = get_bloginfo( 'name', 'display' );
			}
		} elseif ( is_archive() ) {
			$title = get_the_archive_title();
			$title = preg_replace( '/^[^:]+:\s*/', '', $title );
		} elseif ( is_search() ) {
			$title = sprintf( __( 'Search Results for: %s', 'seo-repair-kit' ), get_search_query() );
		} elseif ( is_404() ) {
			$title = __( 'Page Not Found', 'seo-repair-kit' );
		} elseif ( is_category() || is_tag() || is_tax() ) {
			$term = get_queried_object();
			if ( $term ) {
				$title = $term->name;
			}
		}

		if ( empty( $title ) ) {
			$title = get_bloginfo( 'name', 'display' );
		}

		return $title;
	}

	private function replace_variables( $text ) {
		if ( empty( $text ) ) {
			return $text;
		}

		$srk_meta        = get_option( 'srk_meta', array() );
		$global_settings = isset( $srk_meta['global'] ) && is_array( $srk_meta['global'] ) ? $srk_meta['global'] : array();
		$separator       = isset( $global_settings['title_separator'] ) ? $global_settings['title_separator'] : get_option( 'srk_title_separator', '-' );

		$site_name        = get_bloginfo( 'name', 'display' );
		$site_description = get_bloginfo( 'description', 'display' );

		$site_author_data = $this->get_site_author_for_replacements();

		$replacements = array(
			'%site_title%'        => $site_name,
			'%tagline%'           => $site_description,
			'%sitedesc%'          => $site_description,
			'%sep%'               => $separator,
			'%date%'              => date_i18n( get_option( 'date_format' ) ),
			'%month%'             => date_i18n( 'F' ),
			'%year%'              => date_i18n( 'Y' ),
			'%current_date%'      => date_i18n( get_option( 'date_format' ) ),
			'%author_first_name%' => $site_author_data['first_name'],
			'%author_last_name%'  => $site_author_data['last_name'],
			'%author_name%'       => $site_author_data['display_name'],
		);

		if ( is_singular() ) {
			global $post;

			if ( $post ) {
				$post_id = $post->ID;

				$title   = get_the_title( $post_id );
				$excerpt = get_the_excerpt( $post_id );
				if ( empty( $excerpt ) ) {
					$excerpt = wp_trim_words(
						wp_strip_all_tags( get_post_field( 'post_content', $post_id ) ),
						30
					);
				}

				$content_snippet = wp_trim_words(
					wp_strip_all_tags( get_post_field( 'post_content', $post_id ) ),
					30
				);

				$replacements['%title%']   = $title;
				$replacements['%excerpt%'] = $excerpt;
				$replacements['%content%'] = $content_snippet;
				$replacements['%permalink%'] = get_permalink( $post_id );

				$replacements['%parent_title%'] = '';
				if ( $post->post_parent ) {
					$replacements['%parent_title%'] = get_the_title( $post->post_parent );
				}

				$replacements['%post_date%']  = get_the_date( 'F j, Y', $post_id );
				$replacements['%post_day%']   = get_the_date( 'd', $post_id );
				$replacements['%post_month%'] = get_the_date( 'F', $post_id );
				$replacements['%post_year%']  = get_the_date( 'Y', $post_id );

				$author_id = $post->post_author;
				if ( $author_id ) {
					$replacements['%author_first_name%'] = get_the_author_meta( 'first_name', $author_id );
					$replacements['%author_last_name%']  = get_the_author_meta( 'last_name', $author_id );
					$replacements['%author_name%']       = get_the_author_meta( 'display_name', $author_id );
				}

				$categories = get_the_category( $post_id );
				if ( ! empty( $categories ) ) {
					$cat_names                       = wp_list_pluck( $categories, 'name' );
					$replacements['%categories%']    = implode( ', ', $cat_names );
					$replacements['%term_title%']      = $categories[0]->name;
					$replacements['%taxonomy_name%'] = $categories[0]->name;
				} else {
					$replacements['%categories%']    = '';
					$replacements['%term_title%']      = '';
					$replacements['%taxonomy_name%'] = '';
				}

				$replacements['%custom_field%'] = '';
			}
		} else {
			$replacements['%title%']        = $site_name;
			$replacements['%excerpt%']      = $site_description;
			$replacements['%content%']      = $site_description;
			$replacements['%permalink%']    = home_url( '/' );
			$replacements['%parent_title%'] = '';

			$replacements['%post_date%']  = $replacements['%date%'];
			$replacements['%post_month%'] = $replacements['%month%'];
			$replacements['%post_year%']  = $replacements['%year%'];

			$replacements['%categories%']    = '';
			$replacements['%term_title%']      = '';
			$replacements['%taxonomy_name%'] = '';

			$replacements['%custom_field%'] = '';
		}

		$text = strtr( $text, $replacements );
		$text = preg_replace( '/%[a-z_]+%/i', '', $text );

		return $text;
	}

	private function get_site_author_for_replacements() {
		$admins = get_users(
			array(
				'role'    => 'administrator',
				'number'  => 1,
				'orderby' => 'ID',
				'order'   => 'ASC',
			)
		);

		$author_id = 0;
		if ( ! empty( $admins ) ) {
			$author_id = $admins[0]->ID;
		} else {
			$author_id = get_current_user_id();
		}

		if ( $author_id ) {
			return array(
				'first_name'   => get_user_meta( $author_id, 'first_name', true ),
				'last_name'    => get_user_meta( $author_id, 'last_name', true ),
				'display_name' => get_the_author_meta( 'display_name', $author_id ),
			);
		}

		return array(
			'first_name'   => '',
			'last_name'    => '',
			'display_name' => '',
		);
	}

	private function replace_taxonomy_variables( $template, $term ) {
		if ( empty( $template ) || ! $term || ! is_object( $term ) ) {
			return '';
		}

		$srk_meta        = get_option( 'srk_meta', array() );
		$global_settings = isset( $srk_meta['global'] ) && is_array( $srk_meta['global'] ) ? $srk_meta['global'] : array();
		$separator       = isset( $global_settings['title_separator'] ) ? $global_settings['title_separator'] : get_option( 'srk_title_separator', '-' );

		$taxonomy_obj = get_taxonomy( $term->taxonomy );

		$term_link = get_term_link( $term );
		if ( is_wp_error( $term_link ) ) {
			$term_link = '';
		}

		$parent_terms = '';
		if ( $term->parent ) {
			$parent_term = get_term( $term->parent, $term->taxonomy );
			if ( $parent_term && ! is_wp_error( $parent_term ) ) {
				$parent_terms = $parent_term->name;
			}
		}

		$page_num = '';
		if ( is_paged() ) {
			$paged = get_query_var( 'paged' );
			if ( $paged ) {
				$page_num = sprintf( __( 'Page %d', 'seo-repair-kit' ), $paged );
			}
		}

		$current_date  = date_i18n( get_option( 'date_format' ) );
		$current_month = date_i18n( 'F' );
		$current_year  = date_i18n( 'Y' );

		$replacements = array(
			'%term%'              => $term->name,
			'%site_title%'        => get_bloginfo( 'name', 'display' ),
			'%sep%'               => $separator,
			'%page%'              => $page_num,
			'%taxonomy%'          => $taxonomy_obj ? $taxonomy_obj->labels->singular_name : ucfirst( str_replace( '_', ' ', $term->taxonomy ) ),
			'%post_count%'        => $term->count,
			'%term_description%'  => ! empty( $term->description ) ? wp_strip_all_tags( $term->description ) : '',
			'%current_date%'      => $current_date,
			'%month%'             => $current_month,
			'%year%'              => $current_year,
			'%permalink%'         => $term_link,
			'%parent_categories%' => $parent_terms,
			'%tagline%'           => get_bloginfo( 'description', 'display' ),
			'%custom_field%'      => '',
		);

		$result = str_replace( array_keys( $replacements ), array_values( $replacements ), $template );
		$result = preg_replace( '/\s*' . preg_quote( $separator, '/' ) . '\s*' . preg_quote( $separator, '/' ) . '\s*/', ' ' . $separator . ' ', $result );
		$result = preg_replace( '/\s+/', ' ', $result );
		$result = trim( $result );
		$result = preg_replace( '/%[a-z_]+%/i', '', $result );
		$result = preg_replace( '/\s+/', ' ', $result );
		$result = trim( $result );

		return $result;
	}

	private function get_taxonomy_title( $term ) {
		if ( ! $term || ! isset( $term->term_id ) ) {
			return '';
		}
		$term_id  = $term->term_id;
		$taxonomy = $term->taxonomy;

		$term_settings = get_term_meta( $term_id, '_srk_term_settings', true );
		if ( is_array( $term_settings ) && ! empty( $term_settings['title'] ) ) {
			$title = $this->replace_taxonomy_variables( $term_settings['title'], $term );
			if ( ! empty( $title ) ) {
				return $title;
			}
		}

		$term_custom_title = get_term_meta( $term_id, '_srk_taxonomy_title', true );
		if ( ! empty( $term_custom_title ) ) {
			$title = $this->replace_taxonomy_variables( $term_custom_title, $term );
			if ( ! empty( $title ) ) {
				return $title;
			}
		}

		$taxonomy_settings = get_option( 'srk_meta_taxonomies_settings', array() );
		if ( isset( $taxonomy_settings[ $taxonomy ] ) && is_array( $taxonomy_settings[ $taxonomy ] ) ) {
			if ( ! empty( $taxonomy_settings[ $taxonomy ]['title_template'] ) ) {
				$title_template = $taxonomy_settings[ $taxonomy ]['title_template'];
				$title          = $this->replace_taxonomy_variables( $title_template, $term );
				if ( ! empty( $title ) ) {
					return $title;
				}
			}
		}

		$term_title = get_term_meta( $term_id, 'srk_meta_title', true );
		if ( ! empty( $term_title ) ) {
			$title = $this->replace_variables( $term_title );
			if ( ! empty( $title ) ) {
				return $title;
			}
		}

		$default_title = get_the_archive_title();
		$default_title = preg_replace( '/<[^>]*>/', '', $default_title );
		$default_title = preg_replace( '/^[^:]+:\s*/', '', $default_title );
		return $default_title;
	}

	public function filter_archive_title( $title ) {
		if ( ! is_category() && ! is_tag() && ! is_tax() ) {
			return $title;
		}
		$term = get_queried_object();
		if ( ! $term || ! isset( $term->term_id ) ) {
			return $title;
		}

		$custom = $this->get_taxonomy_seo_title_raw( $term );
		if ( ! empty( $custom ) ) {
			return esc_html( $custom );
		}

		$title = preg_replace( '/<[^>]*>/', '', $title );
		$title = preg_replace( '/^[^:]+:\s*/', '', $title );
		return $title;
	}

	private function get_taxonomy_seo_title_raw( $term ) {
		if ( ! $term || ! isset( $term->term_id ) ) {
			return '';
		}
		$term_id  = $term->term_id;
		$taxonomy = $term->taxonomy;

		$term_settings = get_term_meta( $term_id, '_srk_term_settings', true );
		if ( is_array( $term_settings ) && ! empty( $term_settings['title'] ) ) {
			return $this->replace_taxonomy_variables( $term_settings['title'], $term );
		}

		$term_custom_title = get_term_meta( $term_id, '_srk_taxonomy_title', true );
		if ( ! empty( $term_custom_title ) ) {
			return $this->replace_taxonomy_variables( $term_custom_title, $term );
		}

		$taxonomy_settings = get_option( 'srk_meta_taxonomies_settings', array() );
		if ( isset( $taxonomy_settings[ $taxonomy ]['title_template'] ) &&
			! empty( $taxonomy_settings[ $taxonomy ]['title_template'] ) ) {
			$title_template = $taxonomy_settings[ $taxonomy ]['title_template'];
			$is_enabled     = ! isset( $taxonomy_settings[ $taxonomy ]['search_visibility'] )
				|| true === $taxonomy_settings[ $taxonomy ]['search_visibility'];
			if ( $is_enabled ) {
				return $this->replace_taxonomy_variables( $title_template, $term );
			}
		}

		$term_title = get_term_meta( $term_id, 'srk_meta_title', true );
		if ( ! empty( $term_title ) ) {
			return $this->replace_variables( $term_title );
		}

		return '';
	}

	public function render_meta_description() {
		if ( is_admin() || is_feed() ) {
			return;
		}

		$description = '';

		$srk_meta          = get_option( 'srk_meta', array() );
		$archives_settings = isset( $srk_meta['archives'] ) && is_array( $srk_meta['archives'] )
			? $srk_meta['archives']
			: array();
		$global            = isset( $srk_meta['global'] ) && is_array( $srk_meta['global'] )
			? $srk_meta['global']
			: array();

		$length = isset( $global['meta_description_length'] )
			? (int) $global['meta_description_length']
			: 160;

		if ( is_author() || is_date() || is_search() || is_post_type_archive() ) {
			$archive_desc = $this->get_archive_description();
			if ( ! empty( $archive_desc ) ) {
				$description = $archive_desc;
			}
		}

		if ( empty( $description ) && is_singular() ) {
			$post_id = get_the_ID();
			if ( $post_id ) {
				$description = SRK_Meta_Resolver::get_meta_description( $post_id );
			}
		}

		if ( empty( $description ) && ( is_front_page() || is_home() ) ) {
			$home_desc = isset( $global['home_desc'] )
				? $global['home_desc']
				: get_option( 'srk_home_desc', '' );

			if ( ! empty( $home_desc ) ) {
				$description = $this->replace_variables( $home_desc );
			}
		}

		if ( empty( $description ) && ( is_category() || is_tag() || is_tax() ) ) {
			$term = get_queried_object();

			if ( $term && isset( $term->term_id ) ) {
				$term_settings = get_term_meta( $term->term_id, '_srk_term_settings', true );
				if ( is_array( $term_settings ) && ! empty( $term_settings['description'] ) ) {
					$description = $this->replace_taxonomy_variables( $term_settings['description'], $term );
				}

				if ( empty( $description ) ) {
					$legacy_desc = get_term_meta( $term->term_id, '_srk_taxonomy_description', true );
					if ( ! empty( $legacy_desc ) ) {
						$description = $this->replace_taxonomy_variables( $legacy_desc, $term );
					}
				}

				if ( empty( $description ) ) {
					$taxonomy_settings = get_option( 'srk_meta_taxonomies_settings', array() );
					if ( isset( $taxonomy_settings[ $term->taxonomy ]['description_template'] ) &&
						! empty( $taxonomy_settings[ $term->taxonomy ]['description_template'] ) ) {
						$description = $this->replace_taxonomy_variables(
							$taxonomy_settings[ $term->taxonomy ]['description_template'],
							$term
						);
					}
				}

				if ( empty( $description ) && ! empty( $term->description ) ) {
					$description = wp_strip_all_tags( $term->description );
				}
			}
		}

		if ( empty( $description ) ) {
			$template = isset( $global['desc_template'] )
				? $global['desc_template']
				: get_option( 'srk_desc_template', '%tagline%' );

			if ( ! empty( $template ) ) {
				$description = $this->replace_variables( $template );
			}
		}

		if ( empty( $description ) ) {
			$description = $this->generate_description_from_content();
		}

		if ( ! empty( $description ) ) {
			$description = wp_strip_all_tags( $description );
			$description = trim( $description );

			if ( strlen( $description ) > $length ) {
				$description = mb_substr( $description, 0, $length );
				$description = rtrim( preg_replace( '/\s+\S*$/u', '', $description ) );
				$description .= '...';
			}

			echo '<meta name="description" content="' . esc_attr( $description ) . '" />' . "\n";
		}
	}

	private function generate_description_from_content() {
		$description = '';

		if ( is_singular() ) {
			global $post;
			if ( $post ) {
				$description = wp_strip_all_tags( $post->post_excerpt );

				if ( empty( $description ) ) {
					$description = wp_strip_all_tags( $post->post_content );
					$description = strip_shortcodes( $description );
					$description = preg_replace( '/\s+/', ' ', $description );
				}
			}
		} elseif ( is_category() || is_tag() || is_tax() ) {
			$term = get_queried_object();
			if ( $term && ! empty( $term->description ) ) {
				$description = wp_strip_all_tags( $term->description );
			}
		} elseif ( is_author() ) {
			$author_id   = get_queried_object_id();
			$description = get_the_author_meta( 'description', $author_id );
			$description = wp_strip_all_tags( $description );
		} elseif ( is_front_page() ) {
			$description = get_bloginfo( 'description', 'display' );
		}

		return $description;
	}

	public function render_robots() {

        if ( is_admin() || is_feed() ) {
            return;
        }

        /**
         * Respect WordPress "Discourage search engines from indexing this site"
         * Settings → Reading → Search engine visibility
         */
        if ( get_option( 'blog_public' ) == '0' ) {

            // Force WordPress behavior
            echo '<meta name="robots" content="noindex, nofollow" />' . "\n";

            // Stop plugin robots from overriding
            return;
        }

        /**
         * Normal SEO Repair Kit robots logic
         */
        if ( is_singular() ) {

            $post_id    = get_the_ID();
            $robots_arr = SRK_Meta_Resolver::resolve_robots_for_context( 'singular', $post_id );

        } elseif ( is_category() || is_tag() || is_tax() ) {

            $term       = get_queried_object();
            $robots_arr = $term
                ? SRK_Meta_Resolver::resolve_robots_for_context( 'taxonomy', null, $term )
                : SRK_Meta_Resolver::get_system_baseline_robots();

        } elseif ( is_search() || is_author() || is_date() || is_post_type_archive() ) {

            $robots_arr = SRK_Meta_Resolver::resolve_robots_for_context( 'archive' );

        } else {

            $robots_arr = SRK_Meta_Resolver::resolve_robots_for_context( 'archive' );

        }

        $robots_content = SRK_Meta_Resolver::build_robots_string( $robots_arr );

        if ( ! empty( $robots_content ) ) {
            echo '<meta name="robots" content="' . esc_attr( $robots_content ) . '" />' . "\n";
        }
    }

	public function render_canonical() {
		if ( is_admin() || is_feed() || is_404() ) {
			return;
		}

		$canonical = '';

		if ( is_singular() ) {
			$post_id          = get_the_ID();
			$custom_canonical = get_post_meta( $post_id, '_srk_canonical_url', true );

			if ( ! empty( $custom_canonical ) ) {
				$canonical = esc_url( $custom_canonical );
			}
		}

		if ( empty( $canonical ) ) {
			if ( is_front_page() ) {
				$canonical = user_trailingslashit( home_url() );
			} elseif ( is_singular() ) {
				global $post;
				if ( $post ) {
					$canonical = get_permalink( $post->ID );
					$canonical = str_replace( '/index.php/', '/', $canonical );
					$canonical = esc_url( $canonical );
				}
			} elseif ( is_archive() ) {
				if ( is_category() || is_tag() || is_tax() ) {
					$term      = get_queried_object();
					$canonical = get_term_link( $term, $term->taxonomy );
				} elseif ( is_author() ) {
					$canonical = get_author_posts_url( get_query_var( 'author' ), get_query_var( 'author_name' ) );
				} elseif ( is_date() ) {
					if ( is_day() ) {
						$canonical = get_day_link( get_query_var( 'year' ), get_query_var( 'monthnum' ), get_query_var( 'day' ) );
					} elseif ( is_month() ) {
						$canonical = get_month_link( get_query_var( 'year' ), get_query_var( 'monthnum' ) );
					} elseif ( is_year() ) {
						$canonical = get_year_link( get_query_var( 'year' ) );
					}
				} elseif ( is_post_type_archive() ) {
					$post_type = get_query_var( 'post_type' );
					if ( is_array( $post_type ) ) {
						$post_type = reset( $post_type );
					}
					$canonical = get_post_type_archive_link( $post_type );
				}
			} elseif ( is_search() ) {
				$canonical = get_search_link();
			}
		}

		if ( ! empty( $canonical ) && ! is_wp_error( $canonical ) ) {
			$canonical = str_replace( '/index.php/', '/', $canonical );
			$canonical = preg_replace( '/(\?|&)paged=\d+/', '', $canonical );
			$canonical = preg_replace( '/page\/\d+\//', '', $canonical );

			echo '<link rel="canonical" href="' . esc_url( $canonical ) . '" />' . "\n";
		}
	}

}

add_action(
	'init',
	function () {
		new SRK_Meta_Output();
	}
);