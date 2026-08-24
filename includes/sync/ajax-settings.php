<?php
if ( ! defined('ABSPATH') ) exit;

require_once SOUNDWAVE_DIR . 'includes/sync/settings.php';

add_action('wp_ajax_soundwave_api_test', function () {
    if ( ! current_user_can('manage_woocommerce') ) {
        wp_send_json_error(['ok'=>false,'message'=>'Insufficient permissions']);
    }

    $opt = soundwave_get_settings_compat();
    $missing = [];
    if ($opt['endpoint']==='')        $missing[] = 'Hub Endpoint';
    if ($opt['consumer_key']==='')    $missing[] = 'Consumer Key';
    if ($opt['consumer_secret']==='') $missing[] = 'Consumer Secret';
    if ($missing) {
        wp_send_json_error(['ok'=>false,'message'=>'Missing: '.implode(', ', $missing).'. Save settings, then try again.']);
    }

    $resp = wp_remote_head($opt['endpoint'], ['timeout'=>6]);
    if (is_wp_error($resp) || (int)wp_remote_retrieve_response_code($resp) === 405) {
        $resp = wp_remote_get($opt['endpoint'], ['timeout'=>6,'redirection'=>2]);
    }
    if (is_wp_error($resp)) {
        wp_send_json_error(['ok'=>false,'message'=>'Network: '.$resp->get_error_message()]);
    }

    $code = (int) wp_remote_retrieve_response_code($resp);
    if (in_array($code,[200,201,202,204,301,302,307,308,401,403],true)) {
        wp_send_json_success(['ok'=>true,'message'=>'Hub reachable. HTTP '.$code]);
    } elseif ($code===404) {
        wp_send_json_error(['ok'=>false,'message'=>'Endpoint not found (404). Check Hub Endpoint.']);
    } else {
        wp_send_json_error(['ok'=>false,'message'=>'Unexpected response (HTTP '.$code.').']);
    }
});
