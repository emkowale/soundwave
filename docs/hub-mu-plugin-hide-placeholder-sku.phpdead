<?php
/**
 * Hide Affiliate Placeholder product SKU in WooCommerce admin order items (hub only).
 * Place in wp-content/mu-plugins/ on the hub. Keeps affiliate SKU from line item meta visible.
 */
add_filter('woocommerce_product_get_sku', function($sku, $product){
    if ( is_admin() && $product ) {
        $is_placeholder_id  = (int) $product->get_id() === 40158;
        $is_placeholder_sku = (string) $sku === 'thebeartraxs-40158-0';
        if ( $is_placeholder_id || $is_placeholder_sku ) {
            return '';
        }
    }
    return $sku;
}, 10, 2);
