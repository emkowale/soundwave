<?php
/* Soundwave: map all items to "Affiliate Import (Placeholder)" (<=100 lines) */
defined('ABSPATH') || exit;

/** Destination placeholder product ID on thebeartraxs.com */
if (!defined('SW_PLACEHOLDER_PRODUCT_ID')) {
    define('SW_PLACEHOLDER_PRODUCT_ID', 40158);
}

/**
 * Build line_items for receiver. Every order line becomes a row for the
 * placeholder product, carrying attributes + printer hints in meta.
 */
function sw_build_line_items_placeholder(WC_Order $order): array {
    $want = [
        'color','pa_color','attribute_pa_color',
        'size','pa_size','attribute_pa_size',
        'print-location','print_location','location',
        'quality','print_quality',
    ];

    $items = [];
    // Use default get_items() to avoid edge cases with custom types.
    foreach ($order->get_items() as $li) {
        if (!($li instanceof WC_Order_Item_Product)) continue;

        // Readable attribute meta
        $meta = [];
        foreach ($li->get_meta_data() as $m) {
            $k = sanitize_key($m->key);
            if (in_array($k, $want, true)) {
                $meta[] = ['key' => normalize_label($m->key), 'value' => (string) $m->value];
            }
        }

        // original-art + image URL from source product
        $p = $li->get_product();
        if ($p instanceof WC_Product) {
            $orig = (string) $p->get_meta('original-art', true);
            if ($orig === '') $orig = (string) get_post_meta($p->get_id(), 'original-art', true);
            if ($orig !== '') $meta[] = ['key' => 'original_art', 'value' => $orig];

            $img_id = $p->get_image_id();
            if ($img_id) {
                $full = wp_get_attachment_url($img_id);
                if ($full) $meta[] = ['key' => 'product_image_full', 'value' => $full];
            }
        }

        // Pass-through taxes
        $taxes = [];
        $totals = (array) $li->get_taxes()['total'];
        foreach ($totals as $rate_id => $amount) {
            $taxes[] = ['id' => (int) $rate_id, 'total' => wc_format_decimal($amount, 2)];
        }

        $items[] = [
            'product_id'   => (int) SW_PLACEHOLDER_PRODUCT_ID,
            'name'         => $li->get_name(),
            'quantity'     => max(1, (int) $li->get_quantity()),
            'subtotal'     => wc_format_decimal($li->get_subtotal(), 2),
            'total'        => wc_format_decimal($li->get_total(), 2),
            'subtotal_tax' => wc_format_decimal($li->get_subtotal_tax(), 2),
            'total_tax'    => wc_format_decimal($li->get_total_tax(), 2),
            'taxes'        => $taxes,
            'meta_data'    => $meta,
        ];
    }

    return $items;
}

/** Pretty label for attribute keys */
function normalize_label(string $key): string {
    $key = str_replace(['attribute_pa_', 'pa_'], '', $key);
    $key = str_replace(['_', '-'], ' ', $key);
    return ucwords(trim($key));
}
