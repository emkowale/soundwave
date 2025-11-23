<?php
if ( ! defined('ABSPATH') ) exit;

function soundwave_sw_is_synced($order_id){
    $is_synced = get_post_meta($order_id, '_soundwave_synced', true);
    $hub_id    = get_post_meta($order_id, '_soundwave_hub_id', true);
    $last_code = (int) get_post_meta($order_id, '_soundwave_last_response_code', true);
    return (string)$is_synced === '1' && !empty($hub_id) && $last_code >= 200 && $last_code < 300;
}
function soundwave_sw_last_error($order_id){
    return (string) get_post_meta($order_id, '_soundwave_last_error', true);
}
function soundwave_sw_last_attempt($order_id){
    return (int) get_post_meta($order_id, '_soundwave_last_attempt', true);
}
function soundwave_sw_edit_url($order_id){
    return get_edit_post_link($order_id, '');
}

function soundwave_render_simple_cell($order_id){
    $synced    = soundwave_sw_is_synced($order_id);
    $last_err  = soundwave_sw_last_error($order_id);
    $attempt   = soundwave_sw_last_attempt($order_id);
    $nonce     = wp_create_nonce('soundwave_sync_'.$order_id);

    echo '<div class="sw-cell-simple" data-order-id="'.esc_attr($order_id).'" data-synced="'.($synced?'1':'0').'">';
    if ( $synced ) {
        echo '<span class="sw-ok">Synced</span>';
    } else {
        echo '<button type="button" class="button sw-sync" data-order-id="'.esc_attr($order_id).'" data-nonce="'.esc_attr($nonce).'">Sync</button>';
        if ( $attempt && ! empty($last_err) ) {
            $url = soundwave_sw_edit_url($order_id);
            echo '<a class="button sw-fix" href="'.esc_url($url).'" aria-label="Fix Order">Fix Order</a>';
        }
    }
    echo '</div>';
}

add_filter('manage_edit-shop_order_columns', function($cols){
    $cols['soundwave'] = __('Soundwave', 'soundwave');
    return $cols;
}, 20);

add_action('manage_shop_order_posts_custom_column', function($column, $post_id){
    if ( $column !== 'soundwave' ) return;
    soundwave_render_simple_cell($post_id);
}, 10, 2);

add_filter('manage_woocommerce_page_wc-orders_columns', function($cols){
    $cols['soundwave'] = __('Soundwave', 'soundwave');
    return $cols;
}, 20);

add_action('manage_woocommerce_page_wc-orders_custom_column', function($column, $order){
    if ( $column !== 'soundwave' ) return;
    $order_id = is_object($order) && method_exists($order, 'get_id') ? $order->get_id() : (int)$order;
    if ( ! $order_id ) return;
    soundwave_render_simple_cell($order_id);
}, 10, 2);
