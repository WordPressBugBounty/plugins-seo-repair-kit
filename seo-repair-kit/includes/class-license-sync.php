<?php
/**
 * SRK License Sync for SEO Repair Kit
 *
 * @package     SEO_Repair_Kit
 */
class SRK_License_Sync
{
    public static function get_cache_key($domain)
    {
        return 'srk_license_status_' . md5((string) $domain);
    }

    public static function get_cache_domains($domain = '')
    {
        $domains = array_filter(array_unique(array(
            $domain ? (string) $domain : '',
            function_exists('site_url') ? site_url() : '',
            function_exists('home_url') ? home_url() : '',
        )));

        return array_values($domains);
    }

    public static function clear_license_cache($domain = '')
    {
        foreach (self::get_cache_domains($domain) as $cache_domain) {
            delete_transient(self::get_cache_key($cache_domain));
        }

        delete_transient('srk_pro_license_status');
    }

    public static function refresh_license_info($domain)
    {
        self::clear_license_cache($domain);

        return self::fetch_license_info($domain, true);
    }

    public static function fetch_license_info($domain, $force_refresh = false)
    {
        $cache_key = self::get_cache_key($domain);

        // Serve from transient if available
        $cached = $force_refresh ? false : get_transient($cache_key);
        if (false !== $cached) {
            $normalized = self::normalize_license_info($cached);
            if ($normalized !== $cached) {
                set_transient($cache_key, $normalized, HOUR_IN_SECONDS);
            }

            return $normalized;
        }

        $url = SRK_API_Client::get_api_url( SRK_API_Client::ENDPOINT_LICENSE_VALIDATE );
        $response = wp_remote_post($url, [
            'body'    => json_encode(['domain' => $domain]),
            'headers' => ['Content-Type' => 'application/json'],
            'timeout' => 15,
        ]);

        if (is_wp_error($response)) {
            return self::error_response($response->get_error_message());
        }

        $body = wp_remote_retrieve_body($response);
        $data = json_decode($body, true);

        if (!isset($data['data']) || !isset($data['signature'])) {
            return self::error_response('Invalid CRM response');
        }

        $payload = json_encode($data['data']);
        $secret = defined('SRK_API_APP_KEY') ? SRK_API_APP_KEY : (defined('SRK_SHARED_SECRET') ? SRK_SHARED_SECRET : '');
        if (0 === strpos($secret, 'base64:')) {
            $decoded = base64_decode(substr($secret, 7), true);
            if (false !== $decoded) {
                $secret = $decoded;
            }
        }

        $local_signature = hash_hmac('sha256', $payload, $secret);

        if (!hash_equals($local_signature, $data['signature'])) {
            return self::error_response('Signature mismatch');
        }

        $license_info = self::normalize_license_info($data['data']);

        $license_info['last_checked_at'] = current_time('mysql');
        $license_info['cache_expires_at'] = gmdate('Y-m-d H:i:s', time() + HOUR_IN_SECONDS);

        set_transient($cache_key, $license_info, HOUR_IN_SECONDS);
        return $license_info;
    }

    private static function error_response($message)
    {
        $response = self::default_license_info();
        $response['status'] = 'error';
        $response['message'] = $message;

        return $response;
    }

    private static function normalize_license_info($license_info)
    {
        if (!is_array($license_info)) {
            return self::default_license_info();
        }

        if (isset($license_info['status']) && is_string($license_info['status'])) {
            $license_info['features'] = isset($license_info['features']) && is_array($license_info['features'])
                ? wp_parse_args($license_info['features'], self::default_feature_map())
                : self::default_feature_map();

            return wp_parse_args($license_info, self::default_license_info());
        }

        $active = !empty($license_info['active']);

        return [
            'status' => $active ? 'active' : 'inactive',
            'expires_at' => $license_info['expires_at'] ?? null,
            'plan_id' => $license_info['plan_id'] ?? null,
            'has_chatbot_feature' => !empty($license_info['has_chatbot_feature']),
            'features' => isset($license_info['features']) && is_array($license_info['features'])
                ? wp_parse_args($license_info['features'], self::default_feature_map())
                : self::default_feature_map(),
            'license_key' => $license_info['license_key'] ?? null,
            'message' => $active ? 'License is active.' : 'License is inactive.',
        ];
    }

    private static function default_license_info()
    {
        return [
            'status' => 'inactive',
            'expires_at' => null,
            'plan_id' => null,
            'has_chatbot_feature' => false,
            'features' => self::default_feature_map(),
            'license_key' => null,
            'message' => 'License is inactive.',
            'last_checked_at' => null,
            'cache_expires_at' => null,
        ];
    }

    private static function default_feature_map()
    {
        return [
            'schema_manager' => ['enabled' => false, 'source' => 'not_purchased'],
            'internal_linking' => ['enabled' => false, 'source' => 'not_purchased'],
            'spam_monitor' => ['enabled' => false, 'source' => 'not_purchased'],
            'link_scanner_unlimited' => ['enabled' => false, 'source' => 'not_purchased'],
            'ai_chatbot' => ['enabled' => false, 'source' => 'requires_paid_feature'],
            'link_scanner' => [
                'enabled' => true,
                'source' => 'free',
                'limit' => 100,
                'unlimited' => false,
            ],
        ];
    }
}
