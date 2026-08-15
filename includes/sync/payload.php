<?php
if ( ! defined('ABSPATH') ) exit;

require_once __DIR__.'/payload-items.php';

if ( ! function_exists('soundwave_prepare_order_payload') ) {
function soundwave_prepare_order_payload( WC_Order $order ) {
    $missing = [];

    $order_number = method_exists($order,'get_order_number') ? $order->get_order_number() : (string)$order->get_id();
    $created      = $order->get_date_created();
    $order_date   = $created ? $created->date('c') : gmdate('c');
    $currency     = $order->get_currency();

    $bill = [
        'first_name' => $order->get_billing_first_name(),
        'last_name'  => $order->get_billing_last_name(),
        'email'      => $order->get_billing_email(),
        'phone'      => $order->get_billing_phone(),
    ];
    $ship = [
        'name'      => trim($order->get_shipping_first_name().' '.$order->get_shipping_last_name()),
        'company'   => $order->get_shipping_company(),
        'address1'  => $order->get_shipping_address_1(),
        'address2'  => $order->get_shipping_address_2(),
        'city'      => $order->get_shipping_city(),
        'state'     => $order->get_shipping_state(),
        'postal'    => $order->get_shipping_postcode(),
        'country'   => $order->get_shipping_country(),
    ];

    if ($ship['name']   === '') $missing[] = 'Ship To Name';
    if ($ship['address1']==='') $missing[] = 'Ship To Address';
    if ($ship['city']   === '') $missing[] = 'Ship To City';
    if ($ship['state']  === '') $missing[] = 'Ship To State/Province';
    if ($ship['postal'] === '') $missing[] = 'Ship To Postal Code';
    if ($ship['country']==='') $missing[] = 'Ship To Country';
    if ($bill['email']  === '') $missing[] = 'Customer Email';

    $C = [
        'color' => ['attribute_pa_color','pa_color','attribute_color','color','bb_color','variation_color'],
        'size'  => ['attribute_pa_size','pa_size','attribute_size','size','bb_size','variation_size'],
        'vendor_code' => ['_vendor_code','vendor_code','Vendor Code','bb_vendor_code'],
        'print_location' => ['_print_location','print_location','Print Location'],
        'company_name'   => ['_company_name','company_name','Company Name'],
        'site_slug'      => ['_site_slug','site_slug','Site Slug'],
        'production'     => ['_production','production','Production'],
        'product_image_url' => ['_product_image_url','product_image_url','Product Image URL'],
    ];

    $items = soundwave_payload_items($order, $C, $missing);

    if (!empty($missing)) {
        return new WP_Error('payload_missing', 'One or more required values are missing.', ['missing'=>array_values(array_unique($missing))]);
    }

    return [
        'order_number' => (string)$order_number,
        'order_date'   => $order_date,
        'currency'     => $currency,
        'totals'       => [
            'order_total'    => (float)$order->get_total(),
            'shipping_total' => (float)$order->get_shipping_total(),
            'tax_total'      => (float)$order->get_total_tax(),
            'discount_total' => (float)$order->get_discount_total(),
        ],
        'customer' => [
            'first_name' => $bill['first_name'],
            'last_name'  => $bill['last_name'],
            'email'      => $bill['email'],
            'phone'      => $bill['phone'],
        ],
        'ship_to' => $ship,
        'items'   => $items,
    ];
}}
