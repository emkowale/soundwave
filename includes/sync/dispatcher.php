<?php
defined('ABSPATH') || exit;

/* Hook into Woo events */
add_action('woocommerce_new_order', function($order_id){
    soundwave_sync_order_to_beartraxs($order_id, false);
}, 20);
add_action('woocommerce_payment_complete', function($order_id){
    soundwave_sync_order_to_beartraxs($order_id, false);
}, 20);

/* Core sync entry */
function soundwave_sync_order_to_beartraxs($order_id, $force = false) {
    $order = wc_get_order($order_id);
    if (!$order) return;

    if (SW_SKIP_ON_SUCCESS && !$force && get_post_meta($order_id, SW_META_STATUS, true) === 'success') return;

    // Validate
    $validation = sw_validate_order($order);
    if (!empty($validation['ok']) && $validation['ok'] === false) {
        sw_update_order_meta($order_id, [
            SW_META_STATUS   => 'failed',
            SW_META_LAST_AT  => current_time('mysql'),
            SW_META_LAST_ERR => (string) ($validation['reason'] ?? 'invalid'),
        ]);
        return;
    }

    // Compose + debug snapshot
    $payload = sw_compose_payload($order);
    sw_update_order_meta($order_id, [
        SW_META_DEBUG_JSON => sw_json_encode($payload),
        SW_META_LAST_AT    => current_time('mysql'),
    ]);
    update_option('sw_last_payload', $payload);

    // Send
    $resp = sw_http_send($payload);

    // Transport error?
    if (is_wp_error($resp['raw'])) {
        $msg = $resp['raw']->get_error_message();
        update_option('sw_last_response', 'WP_Error: '.$msg);
        sw_update_order_meta($order_id, [
            SW_META_STATUS   => 'failed',
            SW_META_LAST_ERR => $msg,
        ]);
        return;
    }

    // Save HTTP echo for banner/debug
    sw_update_order_meta($order_id, [
        SW_META_HTTP_CODE => (int) $resp['code'],
        SW_META_HTTP_BODY => (string) $resp['body'],
    ]);
    update_option('sw_last_response', $resp['body']);

    // Parse JSON body (to capture destination order id, etc.)
    $body = json_decode((string) $resp['body'], true);
    if (is_array($body) && !empty($body['id'])) {
        update_post_meta($order_id, '_sw_dest_order_id', (int) $body['id']);
    }

    if ($resp['code'] >= 200 && $resp['code'] < 300) {
        sw_update_order_meta($order_id, [
            SW_META_STATUS   => 'success',
            SW_META_LAST_ERR => '',
        ]);
        update_option('sw_last_sync_ok_ts', time());
    } else {
        $msg = $resp['code'].' '.substr($resp['body'], 0, 400);
        sw_update_order_meta($order_id, [
            SW_META_STATUS   => 'failed',
            SW_META_LAST_ERR => $msg,
        ]);
    }
}
