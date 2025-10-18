<?php
/* Soundwave: build shipping_lines with pass-through tax (<=100 lines) */
defined('ABSPATH') || exit;

function sw_extract_shipping_lines($order) {
    $lines = [];
    foreach ($order->get_items('shipping') as $ship) {
        /** @var WC_Order_Item_Shipping $ship */
        $lines[] = array_filter([
            'method_id'    => $ship->get_method_id(),
            'method_title' => $ship->get_name(),
            'total'        => wc_format_decimal($ship->get_total(), 2),
            'total_tax'    => wc_format_decimal($ship->get_total_tax(), 2),
        ]);
    }
    return $lines;
}
