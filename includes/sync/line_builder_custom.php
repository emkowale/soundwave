<?php
/* Soundwave: build line_items with tax + attrs + original-art + full image (<=100 lines) */
defined('ABSPATH') || exit;

function sw_build_line_items($order) {
    $items = [];

    foreach ($order->get_items('line_item') as $item) {
        /** @var WC_Order_Item_Product $item */
        $product      = $item->get_product();
        $src_pid      = (int) $item->get_product_id();
        $src_var_id   = (int) $item->get_variation_id();

        $row = [
            'name'         => $item->get_name(),
            'quantity'     => (int) $item->get_quantity(),
            'subtotal'     => wc_format_decimal($item->get_subtotal(), 2),
            'total'        => wc_format_decimal($item->get_total(), 2),
            'subtotal_tax' => wc_format_decimal($item->get_subtotal_tax(), 2),
            'total_tax'    => wc_format_decimal($item->get_total_tax(), 2),
            'product_id'   => $src_pid,          // do NOT create/link new products
        ];
        if ($src_var_id > 0) $row['variation_id'] = $src_var_id;

        // Optional SKU passthrough
        if ($product instanceof WC_Product) {
            $sku = $product->get_sku();
            if ($sku) $row['sku'] = $sku;
        }

        // Visible meta_data (attributes/custom fields)
        $meta_out = [];
        foreach ($item->get_meta_data() as $m) {
            $d = $m->get_data();
            $k = (string) ($d['key'] ?? '');
            $v = $d['value'] ?? '';
            if ($k === '' || $k[0] === '_') continue;       // skip private keys
            if (strpos($k, 'attribute_') === 0) $k = substr($k, 10); // "attribute_pa_size" -> "pa_size"
            $meta_out[] = ['key' => sanitize_key($k), 'value' => is_scalar($v) ? (string)$v : wp_json_encode($v)];
        }

        // ---- original-art: line → order → product → parent product (for variations) ----
        $art = '';
        foreach (['original-art','original_art','art_url','art-file','art_file'] as $ak) {
            $val = $item->get_meta($ak, true); if ($val !== '') { $art = (string)$val; break; }
        }
        if ($art === '') {
            foreach (['original-art','original_art','art_url','art-file','art_file'] as $ak) {
                $val = $order->get_meta($ak, true); if ($val !== '') { $art = (string)$val; break; }
            }
        }
        if ($art === '' && $product instanceof WC_Product) {
            // Check the line's product…
            $val = get_post_meta($product->get_id(), 'original-art', true);
            if ($val === '' && $product instanceof WC_Product_Variation) {
                // …and if it's a variation, also check the parent product.
                $parent_id = $product->get_parent_id();
                if ($parent_id) $val = get_post_meta($parent_id, 'original-art', true);
            }
            if ($val !== '') $art = (string)$val;
        }
        if ($art !== '') $meta_out[] = ['key' => 'original-art', 'value' => $art];

        // Full-size product image URL for the printer
        if ($product instanceof WC_Product) {
            $img_id = $product->get_image_id();
            if ($img_id) {
                $full = wp_get_attachment_url($img_id);
                if ($full) $meta_out[] = ['key' => 'product_image_full', 'value' => $full];
            }
        }

        if ($meta_out) $row['meta_data'] = $meta_out;
        $items[] = $row;
    }

    return $items;
}
