<?php
if ( ! defined('ABSPATH') ) exit;

/**
 * File: includes/sync/payload.php
 * Purpose: Build the hub/ShipStation-friendly payload or return a WP_Error
 *          with ONLY the human-readable missing fields list: ['missing'=>[...]].
 *
 * Returns array payload on success, or WP_Error('payload_missing', '...', ['missing'=>[]])
 */
if ( ! function_exists('soundwave_prepare_order_payload') ) {
function soundwave_prepare_order_payload( WC_Order $order ) {
    $missing = [];

    // ---- Order core ----
    $order_number = method_exists($order,'get_order_number') ? $order->get_order_number() : (string)$order->get_id();
    $created      = $order->get_date_created();
    $order_date   = $created ? $created->date('c') : gmdate('c');
    $currency     = $order->get_currency();

    // ---- Customer & Ship-To (ShipStation cares) ----
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

    // Minimal sanity for import
    if ($ship['name']   === '') $missing[] = 'Ship To Name';
    if ($ship['address1']==='') $missing[] = 'Ship To Address';
    if ($ship['city']   === '') $missing[] = 'Ship To City';
    if ($ship['state']  === '') $missing[] = 'Ship To State/Province';
    if ($ship['postal'] === '') $missing[] = 'Ship To Postal Code';
    if ($ship['country']==='') $missing[] = 'Ship To Country';
    if ($bill['email']  === '') $missing[] = 'Customer Email';

    // ---- Items ----
    $items = [];
    $i = 0;

    // Candidate keys to mirror into meta (helps downstream without exposing tech keys)
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

    $first = function(array $map, array $cands){
        if ( function_exists('soundwave_first_value_from_candidates') ) {
            $v = soundwave_first_value_from_candidates($map, $cands);
            return is_scalar($v) ? (string)$v : ( $v === null ? '' : (string)$v );
        }
        foreach ($cands as $k){ foreach([$k,strtolower($k),strtoupper($k)] as $ck){
            if(array_key_exists($ck,$map)){ $v=$map[$ck]; return is_scalar($v)?(string)$v:(is_array($v)?reset($v):''); }
        }} return '';
    };

    foreach ( $order->get_items('line_item') as $item ) {
        if ( ! ($item instanceof WC_Order_Item_Product) ) continue;
        $i++;

        $product = $item->get_product();
        $name    = $product ? $product->get_name() : $item->get_name();
        $sku     = $product && method_exists($product,'get_sku') ? (string)$product->get_sku() : '';
        $qty     = (int) $item->get_quantity();
        $line_total = (float) $item->get_total(); // excl tax
        $unit_price = $qty > 0 ? round($line_total / $qty, 2) : 0.0;

        if ($qty <= 0)     $missing[] = "Quantity for Item #{$i}";
        if ($sku === '')   $missing[] = "SKU for Item #{$i}";

        // Build a flat map of item/variation/product meta to pick color/size & custom fields
        $map = [];
        foreach ($item->get_meta_data() as $m){ $d=$m->get_data(); if(isset($d['key'])) $map[$d['key']]=$d['value']; }
        if ($vid = (int)$item->get_variation_id()){
            if ($v = wc_get_product($vid)){
                foreach ((array)$v->get_attributes() as $tax => $val){ $map['attribute_'.$tax]=$val; $map[$tax]=$val; }
            }
        }
        if ($product){
            foreach ($product->get_meta_data() as $m){ $d=$m->get_data(); if(isset($d['key']) && !isset($map[$d['key']])) $map[$d['key']]=$d['value']; }
            if ($pid = $product->get_parent_id()){
                if ($parent = wc_get_product($pid)){
                    foreach ($parent->get_meta_data() as $m){ $d=$m->get_data(); if(isset($d['key']) && !isset($map[$d['key']])) $map[$d['key']]=$d['value']; }
                }
            }
        }

        $color = $first($map, $C['color']);
        $size  = $first($map, $C['size']);
        if ($color === '') $missing[] = "Color selected for Item #{$i}";
        if ($size  === '') $missing[] = "Size selected for Item #{$i}";

        // Human-facing line name e.g. "Shirt — Color: Black, Size: XL"
        $attrSuffix = [];
        if ($color !== '') $attrSuffix[] = 'Color: '.$color;
        if ($size  !== '') $attrSuffix[] = 'Size: '.$size;
        $dispName = $name . ( $attrSuffix ? ' — '.implode(', ', $attrSuffix) : '' );

        // Optional custom fields (useful downstream)
        $meta = [
            'Vendor Code'       => $first($map,$C['vendor_code']),
            'Company Name'      => $first($map,$C['company_name']),
            'Site Slug'         => $first($map,$C['site_slug']),
            'Production'        => $first($map,$C['production']),
            'Print Location'    => $first($map,$C['print_location']),
            'Product Image URL' => $first($map,$C['product_image_url']),
        ];

        $items[] = [
            'name'       => $dispName,
            'sku'        => $sku,
            'quantity'   => $qty,
            'unit_price' => $unit_price,
            'meta'       => $meta,
        ];
    }

    if (!empty($missing)) {
        return new WP_Error('payload_missing', 'One or more required values are missing.', ['missing'=>$missing]);
    }

    // ---- Totals ----
    $payload = [
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

    return $payload;
}}
