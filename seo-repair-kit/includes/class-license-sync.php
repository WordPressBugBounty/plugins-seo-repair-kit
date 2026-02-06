<?php
/**
 * SRK License Sync for SEO Repair Kit
 *
 * @package     SEO_Repair_Kit
 */
class SRK_License_Sync
{
    public static function fetch_license_info($domain)
    {
        $cache_key = 'srk_license_status_' . md5($domain);

        // Serve from transient if available
        $cached = get_transient($cache_key);
        if ($cached !== false) return $cached;

        $url = SRK_API_Client::get_api_url( SRK_API_Client::ENDPOINT_LICENSE_VALIDATE );
        $response = wp_remote_post($url, [
            'body'    => json_encode(['domain' => $domain]),
            'headers' => ['Content-Type' => 'application/json'],
            'timeout' => 15,
        ]);

        if (is_wp_error($response)) {
            return ['status' => 'error', 'message' => $response->get_error_message()];
        }

        $body = wp_remote_retrieve_body($response);
        $data = json_decode($body, true);

        if (!isset($data['data']) || !isset($data['signature'])) {
            return ['status' => 'error', 'message' => 'Invalid CRM response'];
        }

        $payload = json_encode($data['data']);
        $secret = defined('SRK_CRM_SHARED_SECRET') ? SRK_CRM_SHARED_SECRET : '';
        if (str_starts_with($secret, 'base64:')) {
            $secret = base64_decode(substr($secret, 7));
        }

        $local_signature = hash_hmac('sha256', $payload, $secret);

        if (!hash_equals($local_signature, $data['signature'])) {
            return ['status' => 'error', 'message' => 'Signature mismatch'];
        }

        set_transient($cache_key, $data['data'], HOUR_IN_SECONDS);
        return $data['data'];
    }
}
