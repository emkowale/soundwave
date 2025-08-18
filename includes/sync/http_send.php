<?php
defined('ABSPATH') || exit;

function sw_http_send($payload) {
    $response = wp_remote_post(SW_HUB_ENDPOINT, [
        'method'  => 'POST',
        'body'    => wp_json_encode($payload),
        'headers' => [
            'Authorization' => 'Basic ' . base64_encode(SW_HUB_KEY . ':' . SW_HUB_SECRET),
            'Content-Type'  => 'application/json',
        ],
        'timeout' => 30,
    ]);
    $code = is_wp_error($response) ? 0 : wp_remote_retrieve_response_code($response);
    $body = is_wp_error($response) ? '' : wp_remote_retrieve_body($response);
    return ['raw'=>$response,'code'=>$code,'body'=>$body];
}
