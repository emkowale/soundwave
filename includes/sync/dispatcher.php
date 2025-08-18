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

    if (SW_SKIP_ON_SUCCESS && !$force && get_post_meta($order_id, SW_META_STATUS, true) === 'success') {
        return;
    }

    // Validate
    $validation = sw_validate_order($order);
    if ($validation['ok'] === false) {
        sw_update_order_meta($order_id, [
            SW_META_STATUS   => 'failed',
            SW_META_LAST_AT  => current_time('mysql'),
            SW_META_LAST_ERR => $validation['reason'],
        ]);
        return;
    }

    // Compose
    $payload = sw_compose_payload($order);

    // Save debug
    sw_update_order_meta($order_id, [
        SW_META_DEBUG_JSON => sw_json_encode($payload),
        SW_META_LAST_AT    => current_time('mysql'),
    ]);

    // Send
    $resp = sw_http_send($payload);

    if (is_wp_error($resp['raw'])) {
        sw_update_order_meta($order_id, [
            SW_META_STATUS   => 'failed',
            SW_META_LAST_ERR => $resp['raw']->get_error_message(),
        ]);
        return;
    }

    sw_update_order_meta($order_id, [
        SW_META_HTTP_CODE => $resp['code'],
        SW_META_HTTP_BODY => $resp['body'],
    ]);

    if ($resp['code'] >= 200 && $resp['code'] < 300) {
        sw_update_order_meta($order_id, [
            SW_META_STATUS   => 'success',
            SW_META_LAST_ERR => '',
        ]);
    } else {
        $msg = $resp['code'] . ' ' . substr($resp['body'], 0, 400);
        sw_update_order_meta($order_id, [
            SW_META_STATUS   => 'failed',
            SW_META_LAST_ERR => $msg,
        ]);
    }
}
