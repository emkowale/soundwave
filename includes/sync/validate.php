<?php
defined('ABSPATH') || exit;

/* Normalize meta keys so we match attributes regardless of how Woo stores them. */
function sw_normalize_key($k) {
    $k = strtolower((string) $k);
    // strip common prefixes Woo uses for variation attributes
    $k = str_replace(['attribute_pa_', 'attribute_', 'pa_'], '', $k);
    // convert hyphens/underscores to spaces and collapse whitespace
    $k = str_replace(['-', '_'], ' ', $k);
    $k = trim(preg_replace('/\s+/', ' ', $k));
    return $k;
}

/* Collect an item’s attributes/meta using normalized keys (handles both formatted and raw). */
function sw_collect_item_attrs($item) {
    $out = [];

    // formatted meta (what Woo shows as “Color: Black”, etc.)
    foreach ($item->get_formatted_meta_data('_', true) as $meta) {
        $out[ sw_normalize_key($meta->key) ] = $meta->value;
    }

    // raw meta as a safety net (sometimes keys appear only here)
    foreach ($item->get_meta_data() as $meta) {
        if (!isset($meta->key)) continue;
        $nk = sw_normalize_key($meta->key);
        if ($nk && !isset($out[$nk])) {
            $out[$nk] = $meta->value;
        }
    }

    return $out;
}

/* Main order validation used before composing/sending payload. */
function sw_validate_order($order) {
    if (!$order instanceof WC_Order) {
        return ['ok' => false, 'reason' => 'Invalid order.'];
    }

    $items = $order->get_items();
    if (empty($items)) {
        return ['ok' => false, 'reason' => 'Order not ready: no line items yet.'];
    }

    // Required attributes per line item (screen-print mode)
    $required = ['quality', 'color', 'size', 'print location'];

    foreach ($items as $item) {
        $attrs   = sw_collect_item_attrs($item);
        $missing = [];

        // Required artwork URL
        $art = function_exists('sw_extract_art_urls') ? sw_extract_art_urls($item) : ['original' => ''];
        if (empty($art['original'])) {
            $missing[] = 'original-art';
        }

        // Required attributes (normalized keys)
        foreach ($required as $want) {
            if (empty($attrs[$want])) {
                $missing[] = $want;
            }
        }

        if (!empty($missing)) {
            $missing = array_values(array_unique($missing));
            return ['ok' => false, 'reason' => 'Missing required field(s): ' . implode(', ', $missing)];
        }
    }

    return ['ok' => true];
}
