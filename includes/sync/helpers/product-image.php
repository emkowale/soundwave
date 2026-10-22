<?php
/*
 * File: includes/sync/helpers/product-image.php
 * Purpose: Enrich payload line items with product image URL(s) for the Hub.
 * Notes: Pure function with input guards; no globals; never fatal.
 */
defined('ABSPATH') || exit;

if (!function_exists('sw_enrich_payload_with_image')) {
function sw_enrich_payload_with_image($payload, $order_id) {
    // ---------- Safety nets ----------
    if (is_wp_error($payload)) {
        return $payload;
    }
    if (!is_array($payload)) {
        return new WP_Error('soundwave_payload_invalid', 'Image enricher received non-array payload.');
    }
    if (!isset($payload['line_items']) || !is_array($payload['line_items'])) {
        return new WP_Error('soundwave_payload_missing_lines', 'Image enricher missing line_items array.');
    }

    $order = wc_get_order((int)$order_id);
    if (!$order) {
        // Not fatal—just return the payload unchanged
        return $payload;
    }

    // Build a mapping of product_id/variation_id to a best-guess image URL
    $image_cache = []; // ['product:123' => 'https://...', 'variation:456' => 'https://...']

    $get_image_for_product = function($product_id) use (&$image_cache) {
        $key = 'product:' . (int)$product_id;
        if (isset($image_cache[$key])) return $image_cache[$key];

        $product = wc_get_product($product_id);
        if (!$product) { $image_cache[$key] = ''; return ''; }

        $img_id = $product->get_image_id();
        $url = $img_id ? wp_get_attachment_url($img_id) : '';
        $image_cache[$key] = (string)$url;
        return $image_cache[$key];
    };

    $get_image_for_variation = function($variation_id, $parent_id) use (&$image_cache, $get_image_for_product) {
        $key = 'variation:' . (int)$variation_id;
        if (isset($image_cache[$key])) return $image_cache[$key];

        $url = '';
        $variation = $variation_id ? wc_get_product($variation_id) : null;
        if ($variation instanceof WC_Product_Variation) {
            $img_id = $variation->get_image_id();
            if ($img_id) $url = (string) wp_get_attachment_url($img_id);
        }
        if ($url === '' && $parent_id) {
            $url = $get_image_for_product($parent_id);
        }
        $image_cache[$key] = $url;
        return $url;
    };

    // Walk over payload line items and attach Product Image URL if not already present
    $idx = 0;
    foreach ($order->get_items('line_item') as $item) {
        if (!isset($payload['line_items'][$idx]) || !is_array($payload['line_items'][$idx])) {
            // Create a minimal stub so we can attach meta safely
            $payload['line_items'][$idx] = [
                'product_id'   => (int) $item->get_product_id(),
                'variation_id' => (int) $item->get_variation_id(),
                'quantity'     => (int) $item->get_quantity(),
                'meta_data'    => [],
            ];
        }
        if (!isset($payload['line_items'][$idx]['meta_data']) || !is_array($payload['line_items'][$idx]['meta_data'])) {
            $payload['line_items'][$idx]['meta_data'] = [];
        }

        $product_id   = (int) $item->get_product_id();
        $variation_id = (int) $item->get_variation_id();

        // Decide best image
        $img_url = $variation_id
            ? $get_image_for_variation($variation_id, $product_id)
            : $get_image_for_product($product_id);

        // Only add if non-empty and not already present in meta
        if ($img_url !== '') {
            $already = false;
            foreach ($payload['line_items'][$idx]['meta_data'] as $m) {
                if (isset($m['key']) && strtolower($m['key']) === 'product image url') { $already = true; break; }
                if (isset($m['key']) && strtolower($m['key']) === 'product_image_url') { $already = true; break; }
            }
            if (!$already) {
                $payload['line_items'][$idx]['meta_data'][] = [
                    'key'   => 'Product Image URL',
                    'value' => $img_url,
                ];
            }
        }

        $idx++;
    }

    // Leave top-level payload untouched otherwise (we don't alter thumbs/lightbox behavior)
    return $payload;
}}
