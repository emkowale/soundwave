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
        $price_str =_
