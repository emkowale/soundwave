<?php
/*
 * File: includes/sync/helpers/product-image.php
 * Purpose: Inject product_image_full + original-art at order and line-item levels.
 */
if (!defined('ABSPATH')) exit;

/** Normalize to parent product for variations. */
function swp_norm_product($p){
    if (!$p) return null;
    if ($p->is_type('variation') && $p->get_parent_id()){
        $parent = wc_get_product($p->get_parent_id());
        if ($parent) return $parent;
    }
    return $p;
}

/** Best full-size image URL: meta → featured → first gallery. */
function sw_product_image_full_from_product($product){
    $p = swp_norm_product($product);
    if (!$p) return '';
    $pid = $p->get_id();

    // explicit meta
    $url = $pid ? get_post_meta($pid, 'product_image_full', true) : '';
    $url = is_string($url) ? trim($url) : '';

    // featured
    if (!$url) {
        $thumb_id = $p->get_image_id();
        if ($thumb_id) $url = wp_get_attachment_image_url($thumb_id, 'full') ?: '';
    }
    // first gallery
    if (!$url) {
        $g = $p->get_gallery_image_ids();
        if (!empty($g)) {
            $first = is_array($g) ? reset($g) : 0;
            if ($first) $url = wp_get_attachment_image_url($first, 'full') ?: '';
        }
    }
    return $url ?: '';
}

/** Robust “original-art” finder from the PARENT product (fallback: variation). */
function sw_original_art_from_product($product){
    $p = swp_norm_product($product);
    if (!$p) return '';
    $pid = $p->get_id();

    // 1) Common, explicit keys
    $keys = ['original-art','original_art','_original_art'];
    foreach ($keys as $k){
        $v = $pid ? get_post_meta($pid, $k, true) : '';
        if (is_string($v)) $v = trim($v);
        if (!empty($v)) return $v;
    }

    // 2) Heuristic scan of product meta (ACF/variants)
    $all = $pid ? get_post_meta($pid) : [];
    if (is_array($all)) {
        foreach ($all as $k => $vals){
            $lk = strtolower($k);
            if (strpos($lk,'original') !== false &&
               (strpos($lk,'art') !== false || strpos($lk,'artwork') !== false || strpos($lk,'design') !== false)) {
                $v = is_array($vals) ? (isset($vals[0]) ? $vals[0] : '') : $vals;
                if (is_string($v)) $v = trim($v);
                if (!empty($v)) return $v;
            }
        }
    }

    // 3) Last resort: variation meta
    if ($product && $product->is_type('variation')) {
        $vkeys = array_merge($keys, ['original','originalart','original_artwork']);
        foreach ($vkeys as $vk){
            $v = get_post_meta($product->get_id(), $vk, true);
            if (is_string($v)) $v = trim($v);
            if (!empty($v)) return $v;
        }
    }
    return '';
}

/** Enrich composed REST payload with product_image_full + original-art. */
function sw_enrich_payload_with_image($payload, $order_id){
    if (!is_array($payload)) return $payload;
    $order = wc_get_order((int)$order_id);
    if (!$order) return $payload;

    // Gather once from any product in the order
    $img_url = ''; $art = '';
    foreach ($order->get_items('line_item') as $li){
        $prod = $li->get_product();
        if (!$img_url) $img_url = sw_product_image_full_from_product($prod);
        if (!$art)     $art     = sw_original_art_from_product($prod);
        if ($img_url && $art) break;
    }

    // Add to order meta
    if (empty($payload['meta_data']) || !is_array($payload['meta_data'])) $payload['meta_data'] = [];
    if ($img_url) $payload['meta_data'][] = ['key' => 'product_image_full', 'value' => $img_url];
    if ($art)     $payload['meta_data'][] = ['key' => 'original-art',       'value' => $art];

    // Add to each line_item
    if (!empty($payload['line_items']) && is_array($payload['line_items'])){
        foreach ($payload['line_items'] as &$li){
            if (empty($li['meta_data']) || !is_array($li['meta_data'])) $li['meta_data'] = [];
            if ($img_url) $li['meta_data'][] = ['key' => 'product_image_full', 'value' => $img_url];
            if ($art)     $li['meta_data'][] = ['key' => 'original-art',       'value' => $art];
        }
        unset($li);
    }
    return $payload;
}
