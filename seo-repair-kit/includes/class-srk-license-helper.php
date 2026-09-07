<?php
/**
 * License and module access helpers for SEO Repair Kit.
 *
 * @package SEO_Repair_Kit
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Centralizes CRM-backed license checks so feature gates stay consistent.
 */
class SRK_License_Helper {

	const FEATURE_SCHEMA_MANAGER           = 'schema_manager';
	const FEATURE_INTERNAL_LINKING         = 'internal_linking';
	const FEATURE_SPAM_MONITOR             = 'spam_monitor';
	const FEATURE_LINK_SCANNER             = 'link_scanner';
	const FEATURE_LINK_SCANNER_UNLIMITED   = 'link_scanner_unlimited';
	const FEATURE_AI_CHATBOT               = 'ai_chatbot';

	/**
	 * Get the current site's cached CRM license payload.
	 *
	 * @return array<string,mixed>
	 */
	public static function get_license_info( $force_refresh = false ) {
		static $license_info = null;

		if ( ! $force_refresh && is_array( $license_info ) ) {
			return $license_info;
		}

		if ( ! class_exists( 'SRK_API_Client' ) ) {
			$api_client_file = dirname( __FILE__ ) . '/class-srk-api-client.php';
			if ( file_exists( $api_client_file ) ) {
				require_once $api_client_file;
			}
		}

		if ( ! class_exists( 'SRK_License_Sync' ) ) {
			$license_sync_file = dirname( __FILE__ ) . '/class-license-sync.php';
			if ( file_exists( $license_sync_file ) ) {
				require_once $license_sync_file;
			}
		}

		if ( ! class_exists( 'SRK_License_Sync' ) ) {
			return self::default_license_info( self::translate_after_init( 'License service is not available.' ) );
		}

		$license_info = self::normalize_license_info( SRK_License_Sync::fetch_license_info( site_url(), $force_refresh ) );

		return is_array( $license_info ) ? $license_info : self::default_license_info();
	}

	/**
	 * Clear cached license payloads for the current site URL variants.
	 *
	 * @return void
	 */
	public static function clear_license_cache() {
		if ( ! class_exists( 'SRK_License_Sync' ) ) {
			$license_sync_file = dirname( __FILE__ ) . '/class-license-sync.php';
			if ( file_exists( $license_sync_file ) ) {
				require_once $license_sync_file;
			}
		}

		if ( class_exists( 'SRK_License_Sync' ) ) {
			SRK_License_Sync::clear_license_cache( site_url() );
		}
	}

	/**
	 * Force a live CRM license check and cache the normalized response.
	 *
	 * @return array<string,mixed>
	 */
	public static function refresh_license_info() {
		self::clear_license_cache();
		return self::get_license_info( true );
	}

	/**
	 * Check if license plan is expired.
	 *
	 * @return bool True if expired or inactive, false if active and not expired.
	 */
	public static function is_license_expired() {
		$license_info = self::get_license_info();

		if ( empty( $license_info['status'] ) || 'active' !== $license_info['status'] ) {
			return true;
		}

		$expiration = $license_info['expires_at'] ?? null;
		if ( $expiration ) {
			$expires_ts = strtotime( (string) $expiration );
			if ( $expires_ts ) {
				return $expires_ts < current_time( 'timestamp' );
			}
		}

		return false;
	}

	/**
	 * Check if license is active and not expired.
	 *
	 * @return bool
	 */
	public static function is_license_active() {
		return ! self::is_license_expired();
	}

	/**
	 * Get the CRM feature map from the license payload.
	 *
	 * @return array<string,array<string,mixed>>
	 */
	public static function get_feature_map() {
		$license_info = self::get_license_info();
		$features     = isset( $license_info['features'] ) && is_array( $license_info['features'] ) ? $license_info['features'] : array();

		return wp_parse_args( $features, self::default_feature_map() );
	}

	/**
	 * Get one feature row.
	 *
	 * @param string $feature_code Feature code from CRM.
	 * @return array<string,mixed>
	 */
	public static function get_feature( $feature_code ) {
		$feature_code = sanitize_key( $feature_code );
		$features     = self::get_feature_map();

		return isset( $features[ $feature_code ] ) && is_array( $features[ $feature_code ] )
			? $features[ $feature_code ]
			: array(
				'enabled' => false,
				'source'  => 'not_available',
			);
	}

	/**
	 * Check whether a CRM feature is enabled.
	 *
	 * @param string $feature_code Feature code from CRM.
	 * @return bool
	 */
	public static function is_feature_enabled( $feature_code ) {
		$feature_code = sanitize_key( $feature_code );

		$feature = self::get_feature( $feature_code );

		if ( self::FEATURE_LINK_SCANNER === $feature_code ) {
			return ! empty( $feature['enabled'] );
		}

		return self::is_license_active() && ! empty( $feature['enabled'] );
	}

	/**
	 * Schema Manager requires its paid module.
	 *
	 * @return bool
	 */
	public static function is_schema_manager_enabled() {
		return self::is_feature_enabled( self::FEATURE_SCHEMA_MANAGER );
	}

	/**
	 * Internal Linking requires its paid module.
	 *
	 * @return bool
	 */
	public static function is_internal_linking_enabled() {
		return self::is_feature_enabled( self::FEATURE_INTERNAL_LINKING );
	}

	/**
	 * Spam Monitor requires its paid module.
	 *
	 * @return bool
	 */
	public static function is_spam_monitor_enabled() {
		return self::is_feature_enabled( self::FEATURE_SPAM_MONITOR );
	}

	/**
	 * Link Scanner is always available, but free users are limited.
	 *
	 * @return bool
	 */
	public static function is_link_scanner_enabled() {
		return self::is_feature_enabled( self::FEATURE_LINK_SCANNER );
	}

	/**
	 * Paid $1 add-on unlocks Posts, custom post types, and unlimited scanning.
	 *
	 * @return bool
	 */
	public static function is_link_scanner_unlimited() {
		return self::is_feature_enabled( self::FEATURE_LINK_SCANNER_UNLIMITED );
	}

	/**
	 * Get the free Link Scanner link limit from CRM, with a safe fallback.
	 *
	 * @return int
	 */
	public static function get_link_scanner_limit() {
		$feature = self::get_feature( self::FEATURE_LINK_SCANNER );
		$limit   = isset( $feature['limit'] ) && null !== $feature['limit'] ? absint( $feature['limit'] ) : 100;

		return max( 1, $limit );
	}

	/**
	 * Free users may scan WordPress Pages only.
	 *
	 * @return array<int,string>
	 */
	public static function get_free_link_scanner_post_types() {
		return array_values(
			array_filter(
				array( 'page' ),
				static function ( $post_type ) {
					return post_type_exists( $post_type );
				}
			)
		);
	}

	/**
	 * Get all post types the current license may scan.
	 *
	 * @return array<int,string>
	 */
	public static function get_allowed_link_scanner_post_types() {
		if ( self::is_link_scanner_unlimited() ) {
			return get_post_types( array( 'public' => true ), 'names' );
		}

		return self::get_free_link_scanner_post_types();
	}

	/**
	 * Get public post types that require the paid scanner add-on.
	 *
	 * @return array<string,WP_Post_Type>
	 */
	public static function get_paid_link_scanner_post_type_objects() {
		$public_post_types = get_post_types( array( 'public' => true ), 'objects' );

		foreach ( self::get_free_link_scanner_post_types() as $free_post_type ) {
			unset( $public_post_types[ $free_post_type ] );
		}

		return $public_post_types;
	}

	/**
	 * Normalize requested scanner post types against the current license.
	 *
	 * @param array<int,string>|string|null $post_types Requested post types.
	 * @param bool                         $fallback Whether to fallback to allowed defaults.
	 * @return array<int,string>
	 */
	public static function normalize_link_scanner_post_types( $post_types, $fallback = true ) {
		$allowed    = self::get_allowed_link_scanner_post_types();
		$requested  = array_filter( array_map( 'sanitize_key', (array) $post_types ) );
		$normalized = array_values( array_intersect( $requested, $allowed ) );

		if ( empty( $normalized ) && $fallback ) {
			$normalized = $allowed;
		}

		return $normalized;
	}

	/**
	 * Check whether a post type can be scanned by current license.
	 *
	 * @param string $post_type Post type slug.
	 * @return bool
	 */
	public static function can_scan_post_type( $post_type ) {
		return in_array( sanitize_key( $post_type ), self::get_allowed_link_scanner_post_types(), true );
	}

	/**
	 * Default license shape used when CRM is unavailable.
	 *
	 * @param string $message Optional message.
	 * @return array<string,mixed>
	 */
	private static function default_license_info( $message = '' ) {
		return array(
			'status'              => 'inactive',
			'expires_at'          => null,
			'plan_id'             => null,
			'has_chatbot_feature' => false,
			'license_key'         => null,
			'features'            => self::default_feature_map(),
			'message'             => $message ? $message : self::translate_after_init( 'License is inactive.' ),
			'last_checked_at'     => null,
			'cache_expires_at'    => null,
		);
	}

	/**
	 * Normalize CRM license payloads from the lightweight sync client.
	 *
	 * @param mixed $license_info Raw or normalized license data.
	 * @return array<string,mixed>
	 */
	private static function normalize_license_info( $license_info ) {
		if ( ! is_array( $license_info ) ) {
			return self::default_license_info();
		}

		if ( isset( $license_info['status'] ) && is_string( $license_info['status'] ) ) {
			$license_info['features'] = isset( $license_info['features'] ) && is_array( $license_info['features'] )
				? wp_parse_args( $license_info['features'], self::default_feature_map() )
				: self::default_feature_map();

			return wp_parse_args( $license_info, self::default_license_info() );
		}

		$is_active = ! empty( $license_info['active'] );

		return array(
			'status'              => $is_active ? 'active' : 'inactive',
			'expires_at'          => $license_info['expires_at'] ?? null,
			'plan_id'             => $license_info['plan_id'] ?? null,
			'has_chatbot_feature' => ! empty( $license_info['has_chatbot_feature'] ),
			'license_key'         => $license_info['license_key'] ?? null,
			'features'            => isset( $license_info['features'] ) && is_array( $license_info['features'] )
				? wp_parse_args( $license_info['features'], self::default_feature_map() )
				: self::default_feature_map(),
			'message'             => $is_active ? self::translate_after_init( 'License is active.' ) : self::translate_after_init( 'License is inactive.' ),
		);
	}

	/**
	 * Translate helper messages only after WordPress allows plugin textdomains to load.
	 *
	 * WordPress 6.7+ warns when plugin translations are loaded before init. License
	 * checks can run during plugins_loaded because feature gates are needed while
	 * modules register their hooks, so these fallback strings must stay plain until
	 * init has fired.
	 *
	 * @param string $message English fallback message.
	 * @return string
	 */
	private static function translate_after_init( $message ) {
		$message = (string) $message;

		if ( ! did_action( 'init' ) ) {
			return $message;
		}

		switch ( $message ) {
			case 'License service is not available.':
				return __( 'License service is not available.', 'seo-repair-kit' );
			case 'License is active.':
				return __( 'License is active.', 'seo-repair-kit' );
			case 'License is inactive.':
				return __( 'License is inactive.', 'seo-repair-kit' );
			default:
				return $message;
		}
	}

	/**
	 * Free feature fallback when CRM is unavailable.
	 *
	 * @return array<string,array<string,mixed>>
	 */
	private static function default_feature_map() {
		return array(
			self::FEATURE_SCHEMA_MANAGER         => array( 'enabled' => false, 'source' => 'not_purchased' ),
			self::FEATURE_INTERNAL_LINKING       => array( 'enabled' => false, 'source' => 'not_purchased' ),
			self::FEATURE_SPAM_MONITOR           => array( 'enabled' => false, 'source' => 'not_purchased' ),
			self::FEATURE_LINK_SCANNER_UNLIMITED => array( 'enabled' => false, 'source' => 'not_purchased' ),
			self::FEATURE_AI_CHATBOT             => array( 'enabled' => false, 'source' => 'requires_paid_feature' ),
			self::FEATURE_LINK_SCANNER           => array(
				'enabled'   => true,
				'source'    => 'free',
				'limit'     => 100,
				'unlimited' => false,
			),
		);
	}
}
