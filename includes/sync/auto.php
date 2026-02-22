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

    $is_synced = strtolower(trim((string) get_post_meta($order_id, '_soundwave_synced', true)));
    if ( in_array($is_synced, ['1', 'yes', 'true', 'on'], true) ) return;

    $lock_key = 'soundwave_sync_lock_' . $order_id;
    if ( get_transient($lock_key) ) return;
    set_transient($lock_key, 1, 60);

    $already_attempted = (get_post_meta($order_id, '_soundwave_auto_attempted', true) === '1');
    $is_admin_retry    = ($context === 'admin_order_update');
    if ( $already_attempted && ! $is_admin_retry ) {
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

// Gateways like Authorize.Net can hold authorized orders in "on-hold" instead of
// moving them to "processing"; still auto-sync these orders to the hub.
add_action('woocommerce_order_status_on-hold', function($order_id){
    soundwave_maybe_auto_sync($order_id, 'status_on_hold');
}, 20);

add_action('woocommerce_order_status_setup', function($order_id){
    soundwave_maybe_auto_sync($order_id, 'status_setup');
}, 20);

// Some payment flows skip status hooks or fire them out-of-order; these hooks
// provide a safe backstop (soundwave_maybe_auto_sync is lock/idempotency guarded).
add_action('woocommerce_payment_complete', function($order_id){
    soundwave_maybe_auto_sync($order_id, 'payment_complete');
}, 20);

add_action('woocommerce_order_status_completed', function($order_id){
    soundwave_maybe_auto_sync($order_id, 'status_completed');
}, 20);

// Recovery path from the order edit screen: when staff clicks Update on an
// unsynced paid/on-hold order, attempt sync without requiring a status change.
add_action('woocommerce_process_shop_order_meta', function($order_id, $post){
    $order = wc_get_order((int)$order_id);
    if (!$order) return;

    $status = (string) $order->get_status();
    if (!in_array($status, ['on-hold', 'processing', 'completed'], true)) return;

    soundwave_maybe_auto_sync((int)$order_id, 'admin_order_update');
}, 20, 2);
