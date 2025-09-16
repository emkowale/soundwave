<?php
/* Soundwave: compose payload with tax-lock + prices_include_tax (<=100 lines) */
defined('ABSPATH') || exit;

function sw_compose_payload($order) {
    $billing  = $order->get_address('billing');
    $shipping = $order->get_address('shipping');
    $billing['email']      = $order->get_billing_email();
    $billing['first_name'] = $order->get_billing_first_name();
    $billing['last_name']  = $order->get_billing_last_name();

    $payload = [
        'payment_method'       => $order->get_payment_method(),
        'payment_method_title' => $order->get_payment_method_title(),
        'set_paid'             => true,
        'status'               => 'processing',
        'billing'              => $billing,
        'shipping'             => $shipping,
        // mirror origin site display mode; receiver should honor posted taxes
        'prices_include_tax'   => wc_prices_include_tax(),
        // pass-through lines
        'line_items'           => sw_build_line_items($order),
        'shipping_lines'       => sw_extract_shipping_lines($order),
        // keep existing coupon builder if present
        'coupon_lines'         => function_exists('sw_extract_coupon_lines') ? sw_extract_coupon_lines($order) : [],
        // receiver hint: do NOT recalc tax; honor posted totals
        'meta_data'            => [
            ['key' => 'soundwave_tax_locked',       'value' => 1],
            ['key' => 'soundwave_origin_order',     'value' => $order->get_id()],
            ['key' => 'soundwave_origin_total',     'value' => wc_format_decimal($order->get_total(), 2)],
            ['key' => 'soundwave_origin_total_tax', 'value' => wc_format_decimal($order->get_total_tax(), 2)],
        ],
    ];

    return $payload;
}
