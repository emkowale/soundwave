<?php
if ( ! defined('ABSPATH') ) exit;
require_once SOUNDWAVE_DIR . 'includes/utils.php';

function soundwave_add_order_sync_column( $columns ){
    $columns['soundwave_sync'] = __('Order Sync','soundwave');
    return $columns;
}
add_filter('manage_edit-shop_order_columns', 'soundwave_add_order_sync_column', 20);
add_filter('woocommerce_shop_order_list_table_columns', 'soundwave_add_order_sync_column', 20);

function soundwave_resolve_id_from_row($row){
    if (is_numeric($row)) return intval($row);
    if (is_object($row) && method_exists($row, 'get_id')) return intval($row->get_id());
    if (is_array($row) && isset($row['id'])) return intval($row['id']);
    return 0;
}

function soundwave_render_sync_cell( $column, $row ){
    if ( $column !== 'soundwave_sync' ) return;
    $order_id = soundwave_resolve_id_from_row($row);
    if ( ! $order_id ) { echo '<em>N/A</em>'; return; }

    $synced = get_post_meta($order_id, '_soundwave_synced', true) === '1';
    if ( $synced ){
        echo '<span class="soundwave-synced-text">Synced</span>';
        return;
    }
    $nonce = wp_create_nonce('soundwave_sync_' . $order_id);
    $order_link = admin_url('post.php?post='.$order_id.'&action=edit');
    echo '<div class="soundwave-actions">';
    echo '<button class="button soundwave-sync-btn" data-order="'.esc_attr($order_id).'" data-nonce="'.esc_attr($nonce).'">Sync</button>';
    echo ' <a class="button button-link-delete soundwave-fix-btn" style="display:none" href="'.esc_url($order_link).'" target="_blank">Fix errors</a>';
    echo '<span class="spinner" style="margin-left:6px;display:none;"></span>';
    echo '</div>';
}
add_action('manage_shop_order_posts_custom_column', 'soundwave_render_sync_cell', 20, 2);
add_action('woocommerce_shop_order_list_table_custom_column', 'soundwave_render_sync_cell', 20, 2);

add_action('wp_ajax_soundwave_sync_order', function(){
    if ( ! current_user_can('manage_woocommerce') ) wp_send_json_error(array('message'=>'forbidden'), 403);
    $order_id = isset($_POST['order_id']) ? intval($_POST['order_id']) : 0;
    $row_nonce = isset($_POST['row_nonce']) ? $_POST['row_nonce'] : (isset($_POST['nonce']) ? $_POST['nonce'] : '');
    $global    = isset($_POST['nonce']) ? $_POST['nonce'] : '';

    if ( ! $order_id ) wp_send_json_error(array('message'=>'invalid order id'), 400);

    $ok = false;
    if ( $row_nonce && wp_verify_nonce($row_nonce, 'soundwave_sync_' . $order_id) ) $ok = true;
    if ( ! $ok && $global && wp_verify_nonce($global, 'soundwave_sync_any') ) $ok = true;
    if ( ! $ok ) wp_send_json_error(array('message'=>'bad nonce'), 400);

    $payload = soundwave_build_payload($order_id);
    $order = wc_get_order($order_id);

    if ( is_array($payload) && isset($payload['__validation_errors']) ){
        $lines = array('Soundwave validation failed — order not synced.');
        foreach ($payload['__validation_errors'] as $row){
            $lines[] = 'Line item #'.$row['index'].' "'.$row['name'].'" missing: '.implode(', ', $row['missing']);
        }
        $note = implode("\n", $lines);
        update_post_meta($order_id, '_soundwave_last_error', $note);
        if ($order) $order->add_order_note($note);
        $fix_url = admin_url('post.php?post='.$order_id.'&action=edit');
        wp_send_json_error(array('status'=>'unsynced','message'=>$note,'fix_url'=>$fix_url), 200);
    }

    $opts = soundwave_get_settings();
    $url = add_query_arg(array(
        'consumer_key' => $opts['consumer_key'],
        'consumer_secret' => $opts['consumer_secret'],
    ), $opts['endpoint']);

    $res = wp_remote_post($url, array(
        'headers' => array('Content-Type'=>'application/json; charset=utf-8'),
        'body'    => wp_json_encode($payload),
        'timeout' => 30,
    ));

    if ( is_wp_error($res) ){
        $msg = $res->get_error_message();
        update_post_meta($order_id, '_soundwave_last_error', $msg);
        if ($order) $order->add_order_note('Soundwave error: '.$msg);
        wp_send_json_error(array('status'=>'unsynced','message'=>$msg,'fix_url'=>admin_url('post.php?post='.$order_id.'&action=edit')), 200);
    }

    $code = wp_remote_retrieve_response_code($res);
    $body = wp_remote_retrieve_body($res);
    update_post_meta($order_id, '_soundwave_last_response_code', $code);
    update_post_meta($order_id, '_soundwave_last_response_body', $body);

    if ($code >= 200 && $code < 300){
        $json = json_decode($body, true);
        update_post_meta($order_id, '_soundwave_synced', '1');
        if (!empty($json['id'])) update_post_meta($order_id, '_soundwave_hub_id', (string)$json['id']);
        if ($order) $order->add_order_note('Soundwave: synced to hub (hub_id '.(!empty($json['id'])?$json['id']:'unknown').')');
        wp_send_json_success(array('status'=>'synced'));
    } else {
        $msg = 'HTTP ' . $code;
        $decoded = json_decode($body, true);
        if (is_array($decoded)){
            if (!empty($decoded['code'])) $msg .= "\nCode: ".$decoded['code'];
            if (!empty($decoded['message'])) $msg .= "\nMessage: ".$decoded['message'];
        } else {
            $msg .= "\n".$body;
        }
        update_post_meta($order_id, '_soundwave_last_error', $msg);
        if ($order) $order->add_order_note('Soundwave error: '.$msg);
        wp_send_json_error(array('status'=>'unsynced','message'=>$msg,'fix_url'=>admin_url('post.php?post='.$order_id.'&action=edit')), 200);
    }
});

add_action('wp_ajax_soundwave_check_status', function(){
    if ( ! current_user_can('manage_woocommerce') ) wp_send_json_error(array('message'=>'forbidden'), 403);
    $ids = isset($_POST['order_ids']) ? (array) $_POST['order_ids'] : array();
    $ids = array_map('intval', $ids);
    $results = array();
    foreach ($ids as $oid){
        if ( ! $oid ) continue;
        $results[$oid] = soundwave_check_hub_status($oid);
    }
    wp_send_json_success(array('results'=>$results));
});

add_action('admin_enqueue_scripts', function(){
    $screen = function_exists('get_current_screen') ? get_current_screen() : null;
    $id = $screen ? $screen->id : '';
    if ( $id !== 'edit-shop_order' && $id !== 'woocommerce_page_wc-orders' ) return;
    wp_enqueue_style('soundwave-admin', SOUNDWAVE_URL.'assets/admin.css', array(), SOUNDWAVE_VERSION);
    wp_enqueue_script('soundwave-admin', SOUNDWAVE_URL.'assets/admin.js', array('jquery'), SOUNDWAVE_VERSION, true);
    wp_localize_script('soundwave-admin', 'SoundwaveAjax', array(
        'ajaxurl' => admin_url('admin-ajax.php'),
        'nonce_global' => wp_create_nonce('soundwave_sync_any'),
    ));
});
