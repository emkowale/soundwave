<?php
if ( ! defined('ABSPATH') ) exit;

add_action('wp_ajax_soundwave_api_test', function () {
    check_ajax_referer('soundwave_api_test');
    if ( ! current_user_can('manage_woocommerce') ) wp_send_json_error(['message' => 'Insufficient permissions.']);

    $settings = get_option('soundwave_settings', soundwave_default_settings());
    $endpoint = trim((string)($settings['endpoint'] ?? ''));
    $ck       = trim((string)($settings['consumer_key'] ?? ''));
    $cs       = trim((string)($settings['consumer_secret'] ?? ''));

    if ($endpoint === '' || $ck === '' || $cs === '') {
        $missing = [];
        if ($endpoint === '') $missing[] = 'Hub Endpoint';
        if ($ck === '')       $missing[] = 'Consumer Key';
        if ($cs === '')       $missing[] = 'Consumer Secret';
        wp_send_json_error(['message' => 'Missing settings: ' . implode(', ', $missing) . '. Save settings, then try again.']);
    }

    $probe = add_query_arg([
        'per_page'       => 1,
        'orderby'        => 'date',
        'order'          => 'desc',
        'consumer_key'   => rawurlencode($ck),
        'consumer_secret'=> rawurlencode($cs),
    ], $endpoint);

    $res = wp_remote_get($probe, [
        'timeout'     => 12,
        'redirection' => 3,
        'headers'     => ['Accept' => 'application/json'],
    ]);

    if (is_wp_error($res)) wp_send_json_error(['message' => 'Network error: '.$res->get_error_message()]);

    $code = (int) wp_remote_retrieve_response_code($res);
    $body = wp_remote_retrieve_body($res);
    $clip = $body ? wp_strip_all_tags( mb_substr($body, 0, 300) ) : '(empty)';

    if ($code >= 200 && $code < 300) {
        wp_send_json_success(['message' => 'Hub reachable. HTTP '.$code.' — nonce OK, credentials accepted.', 'code' => $code]);
    } elseif ($code === 401 || $code === 403) {
        wp_send_json_error(['message' => 'Authentication failed (HTTP '.$code.'). Check Consumer Key / Secret.', 'code' => $code]);
    } elseif ($code === 404) {
        wp_send_json_error(['message' => 'Endpoint not found (HTTP 404). Verify the Hub Endpoint URL.', 'code' => $code]);
    } else {
        wp_send_json_error(['message' => 'Unexpected response (HTTP '.$code.'). Body: '.$clip, 'code' => $code]);
    }
});
