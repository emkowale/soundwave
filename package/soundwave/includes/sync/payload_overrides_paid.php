<?php
/*
 * File: includes/sync/payload_overrides_paid.php
 * Purpose: Finalize payload for hub + ShipStation
 *  - Force placeholder product_id=40158, variation_id=0.
 *  - Add variation and product meta cleanly (no duplicate SKUs or labels).
 *  - Set payment/title, created_via, and customer note for hub UI.
 */
if (!defined('ABSPATH')) exit;

if (!function_exists('sw_payload_overrides_paid')) {
function sw_payload_overrides_paid(array $payload, $order) {
    if (!($order instanceof WC_Order)) return $payload;
    if (empty($payload['line_items']) || !is_array($payload['line_items'])) return $payload;

    $push_meta = function(array &$meta, string $key, $val){
        if ($val === '' || $val === null) return;
        foreach ($meta as $m) {
            if (!empty($m['key']) && (string)$m['key'] === $key) return;
        }
        $meta[] = ['key' => $key, 'value' => is_scalar($val) ? (string)$val : wp_json_encode($val)];
    };

    $lines = &$payload['line_items'];
    $i = 0; $any_nonzero = false; $first_prod = null;

    foreach ($order->get_items('line_item') as $item) {
        if (!($item instanceof WC_Order_Item_Product)) { $i++; continue; }
        if (empty($lines[$i]) || !is_array($lines[$i])) { $i++; continue; }

        if (empty($lines[$i]['meta_data']) || !is_array($lines[$i]['meta_data'])) {
            $lines[$i]['meta_data'] = [];
        }
        $meta =& $lines[$i]['meta_data'];

        // Force hub placeholder IDs
        $lines[$i]['product_id']   = 40158;
        $lines[$i]['variation_id'] = 0;
        $push_meta($meta, '_product_id', 40158);

        // Quantities & price as strings
        $qty = max(1, (int)($lines[$i]['quantity'] ?? $item->get_quantity() ?: 1));
        $raw_price = (float)($lines[$i]['price'] ?? ($item->get_total() / max($qty, 1)));
        if ($raw_price > 0) $any_nonzero = true;
        $total = wc_format_decimal(round($qty * $raw_price, 2), 2);
        $price_str = wc_format_decimal($raw_price, 2);

        $lines[$i]['quantity'] = $qty;
        $lines[$i]['price']    = $price_str;
        $lines[$i]['subtotal'] = $total;
        $lines[$i]['total']    = $total;
        $push_meta($meta, '_line_total', $total);

        if ($first_prod === null) $first_prod = &$lines[$i];

        // Affiliate IDs
        $push_meta($meta, 'Affiliate Product ID', $item->get_product_id());
        $push_meta($meta, 'Affiliate Variation ID', $item->get_variation_id());

        // Images
        $variation_img = ''; $product_img = '';
        if ($vid = (int)$item->get_variation_id()) {
            $v = wc_get_product($vid);
            if ($v && $v->get_image_id()) $variation_img = wp_get_attachment_image_url($v->get_image_id(), 'full');
        }
        $p = $item->get_product();
        if ($p && $p->get_image_id()) $product_img = wp_get_attachment_image_url($p->get_image_id(), 'full');
        $push_meta($meta, 'Product Image', $variation_img ?: $product_img);

        // SKU (single clean entry, no label)
        $vsku = '';
        if ($vid && ($v = wc_get_product($vid)) && method_exists($v, 'get_sku')) $vsku = (string)$v->get_sku();
        if ($vsku === '' && $p && method_exists($p, 'get_sku')) $vsku = (string)$p->get_sku();
        if ($vsku === '') {
            $site = strtolower(trim((string)($item->get_meta('Site Slug', true) ?: $item->get_meta('site_slug', true))));
            $pid  = (int)$item->get_product_id();
            $vid2 = (int)$item->get_variation_id();
            $vsku = ($site ?: 'site').'-'.$pid.'-'.($vid2 ?: 0);
        }
        $push_meta($meta, '_aff_vsku', $vsku); // private; hidden in UI

        $i++;
    }

    // Fallback $0.01 for all-zero orders
    if (!$any_nonzero && $first_prod) {
        $first_prod['price'] = $first_prod['subtotal'] = $first_prod['total'] = '0.01';
        $first_prod['meta_data'][] = ['key' => '_line_total', 'value' => '0.01'];
    }

    // === Hub UI bits ===
    // Payment header: "Payment via Soundwave"
    $payload['payment_method']       = 'soundwave';
    $payload['payment_method_title'] = 'Soundwave';

    // Orders list: "via Soundwave Affiliate Sync"
    if (empty($payload['meta_data']) || !is_array($payload['meta_data'])) $payload['meta_data'] = [];
    $payload['meta_data'][] = ['key' => '_created_via', 'value' => 'soundwave_affiliate_sync'];

    // Customer provided note: "Synced from bct.thebeartraxs.com order #1442"
    $host = (string) parse_url( home_url(), PHP_URL_HOST );
    $payload['customer_note'] = sprintf('Synced from %s order #%d', $host, (int) $order->get_id());

    return $payload;
}}
