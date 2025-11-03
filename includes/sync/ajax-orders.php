<?php
if ( ! defined('ABSPATH') ) exit;

require_once SOUNDWAVE_DIR . 'includes/sync/sender.php'; // for soundwave_send_to_hub()

/**
 * Small internal helper: GET a Hub Woo REST path with settings auth.
 * Returns array [ 'code' => int, 'body' => mixed|null, 'raw' => string|null ]
 */
if ( ! function_exists('soundwave_hub_api_get') ) {
    function soundwave_hub_api_get( string $path ) : array {
        $settings = get_option('soundwave_settings', []);
        $endpoint = isset($settings['api_endpoint']) ? rtrim((string)$settings['api_endpoint'], "/") : '';
        $ck       = isset($settings['api_key'])      ? (string)$settings['api_key']      : '';
        $cs       = isset($settings['api_secret'])   ? (string)$settings['api_secret']   : '';
        if ($endpoint === '' || $ck === '' || $cs === '') {
            return ['code'=>0,'body'=>null,'raw'=>null];
        }

        // Build wc/v3 absolute URL safely
        // Allow both full wc/v3 endpoints or bare site URL; normalize to /wp-json/wc/v3
        if (preg_match('~/wp-json/wc/v\d+~', $endpoint)) {
            $base = $endpoint;
        } else {
            $base = $endpoint . '/wp-json/wc/v3';
        }
        $url = $base . '/' . ltrim($path, '/');

        $args = [
            'headers' => [ 'Authorization' => 'Basic '. base64_encode($ck.':'.$cs) ],
            'timeout' => 12,
        ];
        $res  = wp_remote_get($url, $args);
        if (is_wp_error($res)) {
            return ['code'=>0,'body'=>null,'raw'=> $res->get_error_message()];
        }
        $code = (int) wp_remote_retrieve_response_code($res);
        $raw  = wp_remote_retrieve_body($res);
        $body = null;
        if ($raw !== '') {
            $json = json_decode($raw, true);
            if (json_last_error() === JSON_ERROR_NONE) $body = $json;
        }
        return ['code'=>$code,'body'=>$body,'raw'=>$raw];
    }
}

/**
 * Derive hub order state for a local order:
 * - If no _soundwave_hub_id, return 'unsynced'
 * - 200 + status == 'trash'   => 'trashed'
 * - 200 + status != 'trash'   => 'synced'
 * - 404                       => 'missing'
 * - else                      => 'error'
 */
if ( ! function_exists('soundwave_hub_order_state') ) {
    function soundwave_hub_order_state( int $order_id ) : array {
        $hub_id = (int) get_post_meta($order_id, '_soundwave_hub_id', true);
        if ( ! $hub_id ) {
            return ['status'=>'unsynced','hub_id'=>0];
        }
        $r = soundwave_hub_api_get('orders/' . $hub_id . '?context=edit');
        if ($r['code'] === 200 && is_array($r['body'])) {
            $status = (string)($r['body']['status'] ?? '');
            if (strtolower($status) === 'trash') {
                return ['status'=>'trashed','hub_id'=>$hub_id];
            }
            return ['status'=>'synced','hub_id'=>$hub_id];
        }
        if ($r['code'] === 404) {
            return ['status'=>'missing','hub_id'=>$hub_id];
        }
        return ['status'=>'error','hub_id'=>$hub_id, 'http'=>$r['code']];
    }
}

/**
 * Manual Sync (unchanged behavior)
 */
add_action('wp_ajax_soundwave_sync_order', function () {
    try {
        if ( ! current_user_can('edit_shop_orders') ) {
            wp_send_json_error(['code'=>'forbidden','message'=>'Insufficient permissions']);
        }

        $order_id = isset($_POST['order_id']) ? absint($_POST['order_id']) : 0;
        if ( ! $order_id ) wp_send_json_error(['code'=>'missing_order_id','message'=>'Missing order_id']);

        $order = wc_get_order($order_id);
        if ( ! $order ) wp_send_json_error(['code'=>'not_found','message'=>'Order not found']);

        // Record that a manual sync was attempted (drives "Fix Order" visibility)
        update_post_meta( $order_id, '_soundwave_last_attempt', time() );

        // Prevent auto-sync from immediately echoing same error
        set_transient('soundwave_sync_manual_' . $order_id, 1, 120);

        // Centralized send path (writes the order note on failure)
        $resp = soundwave_send_to_hub( $order_id );

        if ( is_wp_error( $resp ) ) {
            wp_send_json_error([
                'code'      => $resp->get_error_code(),
                'message'   => $resp->get_error_message(),
                'order_id'  => $order_id,
            ]);
        }

        $out = is_array($resp) ? $resp : ['message' => 'Order sent to hub.'];
        $out['order_id'] = $order_id;
        wp_send_json_success( $out );

    } catch (Throwable $e) {
        $order_id = isset($_POST['order_id']) ? absint($_POST['order_id']) : 0;
        if ( $order_id ) {
            update_post_meta($order_id, '_soundwave_last_error', 'soundwave_ajax_throwable: '.$e->getMessage());
        }
        wp_send_json_error(['code'=>'soundwave_ajax_throwable','message'=>$e->getMessage()]);
    }
});

/**
 * Batch status check for the Orders list.
 * Returns per-order: synced | trashed | missing | unsynced | error
 * Your list JS already treats anything not exactly "synced" as stale and flips UI.
 */
add_action('wp_ajax_soundwave_check_status', function(){
    if ( ! current_user_can('edit_shop_orders') ) wp_send_json_error(['message'=>'forbidden'], 403);
    $ids = isset($_POST['order_ids']) ? (array) $_POST['order_ids'] : [];
    $ids = array_map('intval', $ids);
    $results = [];
    foreach ($ids as $oid){
        if ( ! $oid ) continue;
        $results[$oid] = soundwave_hub_order_state($oid);
    }
    wp_send_json_success(['results'=>$results]);
});
