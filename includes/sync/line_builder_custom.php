<?php
/* Soundwave: build line_items with pass-through tax (<=100 lines) */
defined('ABSPATH') || exit;

function sw_build_line_items($order) {
    $items = [];
    foreach ($order->get_items() as $item) {
        /** @var WC_Order_Item_Product $item */
        $product      = $item->get_product();
        $row = [
            'name'         => $item->get_name(),
            'quantity'     => (int) $item->get_quantity(),
            'subtotal'     => wc_format_decimal($item->get_subtotal(), 2),
            'total'        => wc_format_decimal($item->get_total(), 2),
            'subtotal_tax' => wc_format_decimal($item->get_subtotal_tax(), 2),
            'total_tax'    => wc_format_decimal($item->get_total_tax(), 2),
        ];
        if ($product instanceof WC_Product) {
            $row['product_id'] = $product->get_id();
            $sku = $product->get_sku();
            if ($sku) $row['sku'] = $sku;
        }
        $items[] = array_filter($row);
    }
    return $items;
}
