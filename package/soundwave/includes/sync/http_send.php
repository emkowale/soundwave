<?php
defined('ABSPATH') || exit;

/**
 * Send payload to Hub.
 * - Reads settings directly from Settings → Soundwave (api_endpoint, api_key, api_secret).
 * - Quietly falls back to constants if a setting is blank.
 * - Returns array on success, WP_Error on failure.
 */
if (!function_exists('sw_http_send')) {
function sw_http_send($payload, $ctx = []) {
    // Guards
    if (is_wp_error($payload)) return $payload;
    if (!is_array($payload)) {
        return new WP_Error('soundwave_payload_invalid', 'Payload is not an array. Aborting send.');
    }

    // Read Settings first
    $settings = get_option('soundwave_settings', []);
    $endpoint = isset($settings['api_endpoint']) ? trim((string)$settings['api_endpoint']) : '';
    $key      = isset($settings['api_key'])      ? trim((string)$settings['api_key'])      : '';
    $secret   = isset($settings['api_secret'])   ? trim((string)$settings['api_secret'])   : '';

    // Quiet fallback to constants if a field is blank
    if ($endpoint === '' && defined('SW_HUB_ENDPOINT')) $endpoint = trim((string) SW_HUB_ENDPOINT);
    if ($key      === '' && defined('SW_HUB_KEY'))      $key      = trim((string) SW_HUB_KEY);
    if ($secret   === '' && defined('SW_HUB_SECRET'))   $secret   = trim((string) SW_HUB_SECRET);

    // Human-friendly missing list (settings-field names only)
    $missing = [];
    if ($endpoint === '') $missing[] = 'API Endpoint';
    if ($key      === '') $missing[] = 'API Key';
    if ($secret   === '') $missing[] = 'API Secret';

    if (!empty($missing)) {
        $list = '– ' . implode("\n– ", $missing);
        return new WP_Error(
            'soundwave_config_missing',
            "Soundwave isn’t fully configured:\n{$list}\n\nOpen **Settings → Soundwave** and fill these fields. Then try syncing again."
        );
    }

    // JSON encode
    $json = wp_json_encode($payload);
    if ($json === false || $json === null) {
        return new WP_Error('soundwave_json_encode_failed', 'Failed to encode order payload.');
    }

    // HTTP request
    $args = [
        'method'      => 'POST',
        'body'        => $json,
        'headers'     => [
            'Authorization' => 'Basic ' . base64_encode($key . ':' . $secret),
            'Content-Type'  => 'application/json',
        ],
        'timeout'     => 30,
        'redirection' => 2,
    ];
    $response = wp_remote_post($endpoint, $args);

    // Network-level error
    if (is_wp_error($response)) {
        return new WP_Error(
            'soundwave_http_request_failed',
            'Couldn’t reach the Hub — ' . $response->get_error_message() . ".\nCheck your **API Endpoint** in Settings → Soundwave."
        );
    }

    $code = (int) wp_remote_retrieve_response_code($response);
    $body = (string) wp_remote_retrieve_body($response);

    // Friendly mappings
    if ($code === 401 || $code === 403) {
        return new WP_Error(
            'soundwave_auth_failed',
            "Authentication failed.\nOpen **Settings → Soundwave** and verify **API Key** and **API Secret**."
        );
    }
    if ($code === 404) {
        return new WP_Error(
            'soundwave_endpoint_not_found',
            "The **API Endpoint** URL wasn’t found (404).\nOpen **Settings → Soundwave** and correct the endpoint."
        );
    }

    // Accept 2xx as success
    if ($code >= 200 && $code < 300) {
        $decoded = json_decode($body, true);
        $out = is_array($decoded) ? $decoded : [];
        $out['code'] = $code;
        $out['body'] = $body;
        return $out;
    }

    // Unexpected codes → compact error
    $snippet = trim(mb_substr($body, 0, 400));
    return new WP_Error(
        'soundwave_http_unexpected',
        "Unexpected Hub response ({$code}).\n{$snippet}"
    );
}}
