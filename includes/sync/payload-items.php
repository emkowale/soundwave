<?php
if ( ! defined('ABSPATH') ) exit;

function soundwave_payload_first(array $map, array $cands){
    if ( function_exists('soundwave_first_value_from_candidates') ) {
        $v = soundwave_first_value_from_candidates($map, $cands);
        return is_scalar($v) ? (string)$v : ( $v === null ? '' : (string)$v );
    }
    foreach ($cands as $k){
        foreach([$k,strtolower($k),strtoupper($k)] as $ck){
            if(array_key_exists($ck,$map)){
                $v=$map[$ck];
                return is_scalar($v)?(string)$v:(is_array($v)?reset($v):'');
            }
        }
    }
    return '';
}

function soundwave_payload_items(WC_Order $order, array $C, array &$missing): array {
    $items = [];
    $i = 0;

    foreach ( $order->get_items('line_item') as $item ) {
        if ( ! ($item instanceof WC_Order_Item_Product) ) continue;
        $i++;

        $product = $item->get_product();
        $name    = $product ? $product->get_name() : $item->get_name();
        $sku     = $product && method_exists($product,'get_sku') ? (string)$product->get_sku() : '';
        $qty     = (int) $item->get_quantity();
        $line_total = (float) $item->get_total();
        $unit_price = $qty > 0 ? round($line_total / max($qty,1), 2) : 0.0;

        if ($qty <= 0)   $missing[] = "Quantity for Item #{$i}";
        if ($sku === '') $missing[] = "SKU for Item #{$i}";

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

        $color = soundwave_payload_first($map, $C['color']);
        $size  = soundwave_payload_first($map, $C['size']);
        if ($color === '') $missing[] = "Color selected for Item #{$i}";
        if ($size  === '') $missing[] = "Size selected for Item #{$i}";

        $attrSuffix = [];
        if ($color !== '') $attrSuffix[] = 'Color: '.$color;
        if ($size  !== '') $attrSuffix[] = 'Size: '.$size;
        $dispName = $name . ( $attrSuffix ? ' — '.implode(', ', $attrSuffix) : '' );

        $items[] = [
            'name'       => $dispName,
            'sku'        => $sku,
            'quantity'   => $qty,
            'unit_price' => $unit_price,
            'meta'       => [
                'Vendor Code'       => soundwave_payload_first($map,$C['vendor_code']),
                'Company Name'      => soundwave_payload_first($map,$C['company_name']),
                'Site Slug'         => soundwave_payload_first($map,$C['site_slug']),
                'Production'        => soundwave_payload_first($map,$C['production']),
                'Print Location'    => soundwave_payload_first($map,$C['print_location']),
                'Product Image URL' => soundwave_payload_first($map,$C['product_image_url']),
            ],
        ];
    }
    return $items;
}
