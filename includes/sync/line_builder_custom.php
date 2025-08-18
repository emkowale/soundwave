<?php
defined('ABSPATH') || exit;

function sw_generate_line_sku($order, $index) {
    $host = parse_url(site_url(), PHP_URL_HOST);
    $safe = str_replace('.', '-', $host);
    return $safe . '-' . $order->get_id() . '-item' . ($index + 1);
}

function sw_build_line_items($order) {
    $lines = [];
    $i = 0;
    foreach ($order->get_items() as $item) {
        $product = $item->get_product();
        $name = $item->get_name();
        $qty  = $item->get_quantity();
        $total = $item->get_total();
        $sku = sw_generate_line_sku($order, $i++);

        $attrs = sw_extract_attributes_map($item);
        $art   = sw_extract_art_urls($item);
        $img   = sw_extract_variation_image_url($item);

        $meta = [];
        foreach ($attrs as $k=>$v) { $meta[] = ['key'=>$k, 'value'=>$v]; }
        if ($img) $meta[] = ['key'=>'variation_image_url','value'=>$img];
        if ($art['original']) $meta[] = ['key'=>'original-art','value'=>$art['original']];
        if ($art['rendered']) $meta[] = ['key'=>'rendered-art','value'=>$art['rendered']];

        $ref = parse_url(site_url(), PHP_URL_HOST) . '–' . $order->get_id() . '–' . $order->get_billing_email();
        $meta[] = ['key'=>'sw_origin_ref','value'=>$ref];

        $lines[] = ['sku'=>$sku,'name'=>$name,'quantity'=>$qty,'total'=>$total,'meta_data'=>$meta];
    }
    return $lines;
}
