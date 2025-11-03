<?php
if ( ! defined('ABSPATH') ) exit;

require_once SOUNDWAVE_DIR . 'includes/sync/sender.php';

// Ensure payload builder exists if anything still calls it directly
if ( ! function_exists('soundwave_build_payload') ) {
    $pf = SOUNDWAVE_DIR . 'includes/payload_compose.php';
    if ( file_exists($pf) ) require_once $pf;
}

function soundwave_maybe_auto_sync( $order_id, $context = '' ){
    $order_id = intval($order_id);

    // if a manual sync just ran, skip to avoid duplicate notes
    if ( get_transient('soundwave_sync_manual_' . $order_id) ) return;

    if ( ! $order_id ) return;

    if ( get_post_meta($order_id, '_soundwave_synced', true) === '1' ) return;

    $lock_key = 'soundwave_sync_lock_' . $order_id;
    if ( get_transient($lock_key) ) return;
    set_transient($lock_key, 1, 60);

    if ( get_post_meta($order_id, '_soundwave_auto_attempted', true) === '1' ) {
        delete_transient($lock_key);
        return;
    }

    if ( ! function_exists('soundwave_sync_order_to_beartraxs') ) {
        require_once __DIR__ . '/dispatcher.php';
    }

    $resp = function_exists('soundwave_sync_order_to_beartraxs')
        ? soundwave_sync_order_to_beartraxs($order_id, ['source'=>'auto','context'=>$context])
        : new WP_Error('soundwave_dispatcher_missing','Dispatcher not found');

    if ( is_wp_error($resp) ) {
        $msg = 'Soundwave auto-sync failed — ' . $resp->get_error_message();
        update_post_meta($order_id, '_soundwave_last_error', $resp->get_error_code().': '.$resp->get_error_message());
        $order = wc_get_order($order_id);
        if ($order && method_exists($order,'add_order_note')) $order->add_order_note($msg);
    } elseif ( is_array($resp) && empty($resp['ok']) ) {
        $msg = !empty($resp['message']) ? $resp['message'] : 'Auto-sync returned error.';
        update_post_meta($order_id, '_soundwave_last_error', $msg);
        $order = wc_get_order($order_id);
        if ($order && method_exists($order,'add_order_note')) $order->add_order_note('Soundwave: '.$msg);
    }

    update_post_meta($order_id, '_soundwave_auto_attempted', '1');
    delete_transient($lock_key);
    return $resp;
}

add_action('woocommerce_order_status_processing', function($order_id){
    soundwave_maybe_auto_sync($order_id, 'status_processing');
}, 20);
