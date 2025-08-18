<?php
defined('ABSPATH') || exit;

function sw_compose_payload($order) {
    $billing = $order->get_address('billing');
    $billing['email'] = $order->get_billing_email();
    $billing['first_name'] = $order->get_billing_first_name();
    $billing['last_name']  = $order->get_billing_last_name();

    $payload = [
        'payment_method'       => $order->get_payment_method(),
        'payment_method_title' => $order->get_payment_method_title(),
        'set_paid'             => true,
        'status'               => 'processing',
        'billing'              => $billing,
        'shipping'             => $order->get_address('shipping'),
        'customer_note'        => $order->get_customer_note(),
        'customer_id'          => 0,
        'line_items'           => sw_build_line_items($order),
        'shipping_lines'       => sw_extract_shipping_lines($order),
        'coupon_lines'         => sw_extract_coupons($order),
        'meta_data'            => [
            ['key'=>SW_META_ORIGIN,         'value'=>parse_url(site_url(), PHP_URL_HOST)],
            ['key'=>SW_META_ORIGIN_ORDER,   'value'=>$order->get_id()],
            ['key'=>SW_META_ORIGIN_CUSTOMER,'value'=>$order->get_billing_email()],
        ],
    ];
    return $payload;
}
