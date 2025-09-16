<?php
defined('ABSPATH') || exit;

function sw_extract_shipping_lines($order) {
    $lines = [];
    foreach ($order->get_shipping_methods() as $shipping) {
        $lines[] = [
            'method_id'    => $shipping->get_method_id(),
            'method_title' => $shipping->get_name(),
            'total'        => $shipping->get_total(),
        ];
    }
    return $lines;
}
