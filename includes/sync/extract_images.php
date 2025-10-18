<?php
defined('ABSPATH') || exit;

/**
 * Best image URL for the exact variation the buyer chose.
 * Priority:
 *   1) Variation's own image
 *   2) Parent product's featured image
 *   3) First image in parent product gallery
 *   4) First image in variation gallery (rare)
 *   5) ''
 */
function sw_extract_variation_image_url($item) {
    $product = $item->get_product();
    if (!$product) return '';

    // Try the variation image first (WC_Product_Variation or product with its own image)
    $url = sw_image_url_from_id($product->get_image_id());
    if ($url) return $url;

    // If it's a variation, fall back to parent product images
    if ($product->is_type('variation')) {
        $parent = wc_get_product($product->get_parent_id());
        if ($parent) {
            // Parent featured image
            $url = sw_image_url_from_id($parent->get_image_id());
            if ($url) return $url;

            // Parent gallery (first)
            $gallery = $parent->get_gallery_image_ids();
            if (!empty($gallery)) {
                $url = sw_image_url_from_id($gallery[0]);
                if ($url) return $url;
            }
        }
    }

    // As a last resort, check this product's gallery too
    $gallery = $product->get_gallery_image_ids();
    if (!empty($gallery)) {
        $url = sw_image_url_from_id($gallery[0]);
        if ($url) return $url;
    }

    return '';
}

/** Helper: full-size URL from attachment id ('' if none) */
function sw_image_url_from_id($attachment_id) {
    if (!$attachment_id) return '';
    $src = wp_get_attachment_image_src($attachment_id, 'full');
    return (!empty($src[0])) ? $src[0] : '';
}
