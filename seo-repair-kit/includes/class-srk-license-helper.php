<?php
/**
 * License Helper for SEO Repair Kit
 *
 * @package     SEO_Repair_Kit
 * @subpackage  License
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Helper class for license status checks.
 *
 * @since 2.1.0
 */
class SRK_License_Helper {

	/**
	 * Check if license plan is expired.
	 *
	 * @since 2.1.0
	 * @return bool True if expired or inactive, false if active and not expired.
	 */
	public static function is_license_expired() {
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
	 * Check if license is active and not expired.
	 *
	 * @since 2.1.0
	 * @return bool True if active and not expired, false otherwise.
	 */
	public static function is_license_active() {
		return ! self::is_license_expired();
	}
}