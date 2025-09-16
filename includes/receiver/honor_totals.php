<?php
/* Soundwave receiver: honor posted totals for Soundwave orders (<=100 lines) */
defined('ABSPATH') || exit;

add_filter('woocommerce_disable_automatic_tax', function($disabled){
    // default behavior unchanged; we only disable during REST create if our flag is present
    return $disabled;
}, 5);

add_filter('woocommerce_rest_pre_insert_shop_order_object', function($order, $request, $creating){
    if (!$creating) return $order;
    $meta = $request->get_param('meta_data');
    if (!is_array($meta)) return $order;

    foreach ($meta as $m) {
        $k = isset($m['key']) ? $m['key'] : '';
        $v = isset($m['value']) ? $m['value'] : null;
        if ($k === 'soundwave_tax_locked' && intval($v) === 1) {
            // Disable automatic tax so Woo uses posted subtotal/subtotal_tax/total/total_tax
            add_filter('woocommerce_disable_automatic_tax', '__return_true', 99);
            break;
        }
    }
    return $order;
}, 10, 3);
