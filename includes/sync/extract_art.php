<?php
defined('ABSPATH') || exit;

function sw_get_meta_first($post_id, $keys) {
    foreach ($keys as $k) {
        $v = get_post_meta($post_id, $k, true);
        if (!empty($v)) return $v;
    }
    return '';
}

function sw_extract_art_urls($item) {
    $product = $item->get_product();
    if (!$product) return ['original'=>'','rendered'=>''];
    $post_id = $product->is_type('variation') ? $product->get_id() : $product->get_id();
    $parent_id = $product->is_type('variation') ? $product->get_parent_id() : 0;

    $original = sw_get_meta_first($post_id, ['original-art','_original_art']);
    if (!$original && $parent_id) $original = sw_get_meta_first($parent_id, ['original-art','_original_art']);

    $rendered = sw_get_meta_first($post_id, ['rendered-art','_rendered_art']);
    if (!$rendered && $parent_id) $rendered = sw_get_meta_first($parent_id, ['rendered-art','_rendered_art']);

    return ['original'=>$original, 'rendered'=>$rendered];
}
