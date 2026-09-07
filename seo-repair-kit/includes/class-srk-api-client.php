<?php
if ( ! defined( 'ABSPATH' ) ) { exit; }

/**
 * SRK_API_Client
 *
 * Minimal, production-friendly API client.
 * - Base URL from option `srk_api_base_url_override` or SRK_API_BASE_URL.
 * - Filter `srk/api_base_url` for last-mile overrides.
 * - NO probing, NO local hardcodes.
 * - NO sensitive logging.
 */
class SRK_API_Client {

    // Endpoints
    const ENDPOINT_LICENSE_VALIDATE  = '/api/v1/licenses/validate';
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

        // 1) Optional DB override (set via update_option if needed)
        $opt = get_option('srk_api_base_url_override');
        if ( is_string($opt) && $opt !== '' ) {
            $base = $opt;
        }
        // 2) Fallback to constant
        elseif ( defined('SRK_API_BASE_URL') && SRK_API_BASE_URL ) {
            $base = SRK_API_BASE_URL;
        }

        // Normalize & allow override via filter
        $base = rtrim( (string) $base, '/' );
        $base = (string) apply_filters('srk/api_base_url', $base);

        return esc_url_raw( $base );
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
