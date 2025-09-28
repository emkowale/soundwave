<?php
/*
 * File: includes/sync/class-sw-preflight.php
 * Purpose: Block sync when REQUIRED PRODUCT data is missing; emit clear WP_Error.
 * Version: 1.2.1
 */
if (!defined('ABSPATH')) exit;

class SW_Preflight {
    public static function init() {
        // Run BEFORE the bridge (priority < 10) so we can block the sync call.
        add_filter('soundwave/run_sync_for_order', [__CLASS__, 'validate'], 5, 3);
    }

    public static function validate($result, $order_id, $ctx = []) {
        if ($result instanceof WP_Error || $result === true || is_array($result)) return $result;

        $order = wc_get_order($order_id);
        if (!$order) return new WP_Error('soundwave_no_order', 'Order not found.');

        // Check EVERY line item’s PRODUCT; fail fast on the first broken product.
        foreach ($order->get_items('line_item') as $li) {
            $prod = $li->get_product();
            if (!$prod) return new WP_Error('soundwave_no_product', 'Order item has no product.');

            // Use parent product as source-of-truth when item is a variation.
            $p = $prod->is_type('variation') && $prod->get_parent_id()
                ? wc_get_product($prod->get_parent_id())
                : $prod;

            $pid   = $p ? $p->get_id() : 0;
            $title = $p ? $p->get_name() : '(unknown product)';
            $plink = $pid ? admin_url('post.php?post='.$pid.'&action=edit') : admin_url('edit.php?post_type=product');

            // Helper: read attribute-like values from product attrs/meta.
            $attr = function($product, $keys) {
                foreach ($keys as $k) {
                    $v = '';
                    if ($product) {
                        $v = $product->get_attribute($k);
                        if (!$v && $product->get_id()) $v = get_post_meta($product->get_id(), $k, true);
                    }
                    $v = is_string($v) ? trim($v) : $v;
                    if (!empty($v)) return $v;
                }
                return '';
            };

            // Required attributes/meta (product-level)
            $color = $attr($p, ['pa_color','Color','color','attribute_pa_color','attribute_color']);
            $size  = $attr($p, ['pa_size','Size','size','attribute_pa_size','attribute_size']);
            $ploc  = $attr($p, ['pa_print_location','print_location','Print Location','attribute_pa_print_location','attribute_print_location']);
            $qual  = $attr($p, ['pa_quality','quality','Quality','attribute_pa_quality','attribute_quality']);

            // original-art is a custom field on the (parent) product
            $oart = '';
            if ($p && $pid) {
                foreach (['original-art','original_art','_original_art'] as $k) {
                    $oart = get_post_meta($pid, $k, true);
                    if (!empty($oart)) break;
                }
                if (!$oart && $prod && $prod->is_type('variation')) {
                    // last resort: check variation meta
                    foreach (['original-art','original_art','_original_art'] as $k) {
                        $oart = get_post_meta($prod->get_id(), $k, true);
                        if (!empty($oart)) break;
                    }
                }
            }
            $oart = is_string($oart) ? trim($oart) : $oart;

            // product_image_full: meta → featured → first gallery
            $pimg = '';
            if ($p && $pid) {
                $pimg = get_post_meta($pid, 'product_image_full', true);
                if (!$pimg) {
                    $img_id = $p->get_image_id();
                    if (!$img_id) {
                        $g = $p->get_gallery_image_ids();
                        $img_id = $g ? reset($g) : 0;
                    }
                    if ($img_id) $pimg = wp_get_attachment_image_url($img_id, 'full');
                }
            }
            $pimg = is_string($pimg) ? trim($pimg) : $pimg;

            $missing = [];
            if (!$color) $missing[] = 'Color';
            if (!$size)  $missing[] = 'Size';
            if (!$ploc)  $missing[] = 'Print Location';
            if (!$qual)  $missing[] = 'Quality';
            if (!$oart)  $missing[] = 'original-art';
            if (!$pimg)  $missing[] = 'product_image_full (image)';

            if ($missing) {
                $msg = sprintf(
                    'Missing required product data for "%s" (ID %d): %s. Edit product: %s',
                    $title, $pid, implode(', ', $missing), $plink
                );
                return new WP_Error('soundwave_preflight_missing', $msg);
            }
        }
        return $result; // OK → let the bridge call the sync function
    }
}
SW_Preflight::init();
