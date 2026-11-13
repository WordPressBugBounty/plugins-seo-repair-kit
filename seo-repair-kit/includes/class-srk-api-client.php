<?php
if ( ! defined( 'ABSPATH' ) ) { exit; }

/**
 * SRK_API_Client
 *
 * Minimal, production-friendly API client.
 * - The public base URL comes from the packaged configuration.
 * - Each connected site receives a unique CRM signing credential.
 * - Filter `srk/api_base_url` for last-mile overrides.
 * - NO probing, NO local hardcodes.
 * - NO sensitive logging.
 */
class SRK_API_Client {

    // Endpoints
    const ENDPOINT_LICENSE_VALIDATE  = '/api/v1/licenses/validate';
    const ENDPOINT_INSTALLATION_ACTIVATE = '/api/v1/plugin/installations/activate';
    const ENDPOINT_INSTALLATION_RECOVER_ACCOUNT = '/api/v1/plugin/installations/recover-account';
    const ENDPOINT_TRIGGER_SUBSCRIBE = '/api/trigger-subscribe';
    const ENDPOINT_SUBSCRIBE         = '/subscribe';
    const ENDPOINT_PLUGIN_DATA       = '/api/plugindata';
    const ENDPOINT_PLUGIN_VERSION    = '/api/plugin-status/version';
    const ENDPOINT_PLUGIN_STATUS     = '/api/plugin-status';
    const ENDPOINT_CHATBOT_CONFIG    = '/api/v1/chatbot/config';
    const ENDPOINT_HEALTH            = '/api/v1/health';

    /**
     * Resolve the API base URL with NO hardcoded/local candidates.
     */
    public static function resolve_base_url() : string {
        $base = '';

        if ( defined('SRK_API_BASE_URL') && SRK_API_BASE_URL ) {
            $base = SRK_API_BASE_URL;
        }

        // Normalize & allow override via filter
        $base = rtrim( (string) $base, '/' );
        $base = (string) apply_filters('srk/api_base_url', $base);

        return esc_url_raw( $base );
    }

    /** Resolve this site's installation-specific signing secret. */
    public static function resolve_shared_secret() : string {
        $secret = get_option( 'srk_installation_secret', '' );
        return is_string( $secret ) ? trim( $secret ) : '';
    }

    /** Resolve the stable UUID created for this WordPress installation. */
    public static function resolve_installation_id() : string {
        $installation_id = get_option( 'srk_plugin_instance_id', '' );
        if ( ! is_string( $installation_id ) || '' === trim( $installation_id ) ) {
            $installation_id = wp_generate_uuid4();
            update_option( 'srk_plugin_instance_id', $installation_id, false );
        }

        return trim( $installation_id );
    }

    /** Resolve the public integration identifier used by CRM. */
    public static function resolve_key_id() : string {
        return defined( 'SRK_INTEGRATION_KEY_ID' )
            ? trim( (string) SRK_INTEGRATION_KEY_ID )
            : 'seo-repair-kit-wordpress-v1';
    }

    /**
     * Construct a full API URL from base + path + query args.
     */
    public static function get_api_url( $path, $query_args = [] ) {
        $base_url = self::resolve_base_url();
        $url      = rtrim($base_url, '/') . $path;

        if ( ! empty( $query_args ) ) {
            $url = add_query_arg( $query_args, $url );
        }
        return esc_url_raw( $url );
    }

    /**
     * Send a replay-safe signed JSON request to CRM.
     *
     * @return array|WP_Error
     */
    public static function signed_json_request( $path, $data = array(), $timeout = 15, $method = 'POST' ) {
        $method          = strtoupper( (string) $method );
        $url             = self::get_api_url( $path );
        $data            = is_array( $data ) ? $data : array();
        $installation_id = self::resolve_installation_id();
        $secret          = self::resolve_shared_secret();
        $key_id          = self::resolve_key_id();

        if ( '' === $key_id || '' === $secret || '' === $installation_id ) {
            return new WP_Error(
                'srk_integration_not_configured',
                __( 'Reconnect this website to your SEO Repair Kit license before refreshing premium access.', 'seo-repair-kit' )
            );
        }

        $data['_srk_site_url']           = site_url();
        $data['_srk_plugin_instance_id'] = $installation_id;
        $body                            = wp_json_encode( $data );
        $timestamp                       = (string) time();
        $nonce                           = str_replace( '-', '', wp_generate_uuid4() );
        $url_path                        = wp_parse_url( $url, PHP_URL_PATH );
        $url_path                        = '/' . ltrim( is_string( $url_path ) ? $url_path : (string) $path, '/' );
        $canonical                       = implode(
            "\n",
            array(
                $method,
                $url_path,
                $timestamp,
                $nonce,
                hash( 'sha256', $body ),
            )
        );

        return wp_remote_request(
            $url,
            array(
                'method'    => $method,
                'body'      => $body,
                'headers'   => array(
                    'Accept'                => 'application/json',
                    'Content-Type'          => 'application/json',
                    'X-SRK-Key-Id'          => $key_id,
                    'X-SRK-Installation-Id' => $installation_id,
                    'X-SRK-Timestamp'       => $timestamp,
                    'X-SRK-Nonce'           => $nonce,
                    'X-SRK-Signature'       => hash_hmac( 'sha256', $canonical, $secret ),
                ),
                'timeout'   => max( 1, (int) $timeout ),
                'sslverify' => true,
            )
        );
    }

    /** @return array|WP_Error */
    public static function signed_post_json( $path, $data = array(), $timeout = 15 ) {
        return self::signed_json_request( $path, $data, $timeout, 'POST' );
    }

    /**
     * Exchange a paid license key once for this site's unique credential.
     *
     * @return array|WP_Error
     */
    public static function activate_installation( $license_key ) {
        $license_key = trim( sanitize_text_field( (string) $license_key ) );
        if ( '' === $license_key ) {
            return new WP_Error( 'srk_license_key_required', __( 'Enter a license key.', 'seo-repair-kit' ) );
        }

        $body = wp_json_encode(
            array(
                'license_key'        => $license_key,
                'domain'             => site_url(),
                'plugin_instance_id' => self::resolve_installation_id(),
            )
        );
        $response = wp_remote_post(
            self::get_api_url( self::ENDPOINT_INSTALLATION_ACTIVATE ),
            array(
                'body'      => $body,
                'headers'   => array(
                    'Accept'       => 'application/json',
                    'Content-Type' => 'application/json',
                ),
                'timeout'   => 15,
                'sslverify' => true,
            )
        );

        if ( is_wp_error( $response ) ) {
            return $response;
        }

        $status  = (int) wp_remote_retrieve_response_code( $response );
        $decoded = json_decode( wp_remote_retrieve_body( $response ), true );
        if ( $status < 200 || $status >= 300 || ! is_array( $decoded ) ) {
            $message = is_array( $decoded ) && ! empty( $decoded['error'] )
                ? sanitize_text_field( (string) $decoded['error'] )
                : __( 'SEO Repair Kit could not connect this website. Please try again or contact support.', 'seo-repair-kit' );
            return new WP_Error( 'srk_installation_activation_failed', $message, array( 'status' => $status ) );
        }

        $installation_id = sanitize_text_field( (string) ( $decoded['installation_id'] ?? '' ) );
        $secret          = trim( (string) ( $decoded['installation_secret'] ?? '' ) );
        if ( ! wp_is_uuid( $installation_id ) || strlen( $secret ) < 32 ) {
            return new WP_Error( 'srk_invalid_installation_response', __( 'SEO Repair Kit could not verify the secure website connection. Please try again or contact support.', 'seo-repair-kit' ) );
        }

        update_option( 'srk_plugin_instance_id', $installation_id, false );
        update_option( 'srk_installation_secret', $secret, false );
        update_option( 'srk_pro_license_key', $license_key, false );

        return array(
            'installation_id' => $installation_id,
            'domain'          => sanitize_text_field( (string) ( $decoded['domain'] ?? '' ) ),
        );
    }

    /** Request My Account instructions for the verified billing email. */
    public static function request_account_access_email() {
        $body = wp_json_encode( array( 'domain' => site_url() ) );
        $response = wp_remote_post(
            self::get_api_url( self::ENDPOINT_INSTALLATION_RECOVER_ACCOUNT ),
            array(
                'body'      => $body,
                'headers'   => array(
                    'Accept'       => 'application/json',
                    'Content-Type' => 'application/json',
                ),
                'timeout'   => 15,
                'sslverify' => true,
            )
        );

        if ( is_wp_error( $response ) ) {
            return $response;
        }

        $status = (int) wp_remote_retrieve_response_code( $response );
        if ( 202 !== $status ) {
            return new WP_Error(
                'srk_account_access_request_failed',
                __( 'We could not request account access right now. Please try again or contact SEO Repair Kit support.', 'seo-repair-kit' ),
                array( 'status' => $status )
            );
        }

        return true;
    }

    /**
     * Simple GET JSON helper with sane defaults.
     */
    public static function get_json( $path, $query_args = [], $headers = [], $timeout = 12 ) {
        $url = self::get_api_url( $path, $query_args );

        $default_headers = [
            'Accept' => 'application/json',
        ];

        $resp = wp_remote_get( $url, [
            'headers'   => array_merge( $default_headers, $headers ),
            'timeout'   => $timeout,
            'sslverify' => true, // Enable SSL verification for production security
        ]);

        if ( is_wp_error( $resp ) ) {
            return [ 'ok' => false, 'error' => $resp->get_error_message() ];
        }

        $code = wp_remote_retrieve_response_code( $resp );
        $body = wp_remote_retrieve_body( $resp );
        $json = json_decode( $body, true );

        if ( $code >= 200 && $code < 300 && is_array($json) ) {
            return $json;
        }

        return [ 'ok' => false, 'status' => $code, 'body' => $body ];
    }

    /**
     * Mask a URL for safe logging (keeps host, masks path/ids).
     */
    private static function mask_url_for_log( string $url ): string {
        if ($url === '') return '';
        $parts = wp_parse_url($url);
        if (!is_array($parts)) return '***';
        $host = isset($parts['host']) ? $parts['host'] : '***';
        // show host + a short hash of the whole url
        $hash = substr(md5($url), 0, 6);
        return $host . ' (hash:' . $hash . ')';
    }

    /**
     * Fetch chatbot config (webhook URL) from Laravel
     * - DOES NOT log secrets.
     */
    public static function fetch_chatbot_config( $domain = '' ) {
        $query = [];
        if ( ! empty( $domain ) ) { $query['domain'] = $domain; }

        $json = self::get_json( self::ENDPOINT_CHATBOT_CONFIG, $query );

        if ( is_array($json) && !empty($json['ok']) && !empty($json['webhook_url']) ) {
            return [
                'ok'          => true,
                'webhook_url' => esc_url_raw( $json['webhook_url'] ),
            ];
        }

        $reason = '';
        if ( is_array($json) ) {
            if (isset($json['error']))       $reason = $json['error'];
            elseif (isset($json['body']))    $reason = substr( (string)$json['body'], 0, 300 );
            elseif (isset($json['status']))  $reason = 'HTTP ' . $json['status'];
            elseif (isset($json['reason']))  $reason = $json['reason'];
        }

        return [ 'ok' => false, 'webhook_url' => '', 'reason' => $reason ];
    }
}
