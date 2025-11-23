<?php
/*
 * File: includes/sync/payload_compose.php
 * Purpose: Main payload builder — delegates helpers & enrichment.
 */
if (!defined('ABSPATH')) exit;

require_once __DIR__ . '/line_builder_placeholder.php';
require_once __DIR__ . '/payload_helpers.php';
require_once __DIR__ . '/payload_enrich_lineitem.php';

if (!function_exists('sw_compose_payload')) {
function sw_compose_payload($order_ref, $ctx = []) {

    $order = (is_object($order_ref) && $order_ref instanceof WC_Order)
        ? $order_ref : wc_get_order((int)$order_ref);
    if (!$order) return new WP_Error('soundwave_no_order', 'Order not found.');

    // --- Base billing/shipping ---
    $billing = swp_extract_billing($order);
    $shipping = swp_extract_shipping($order);

    // --- Line items via placeholder ---
    if (!function_exists('sw_build_line_items_placeholder'))
        return new WP_Error('soundwave_missing_builder', 'line_builder_placeholder missing.');
    $line_items = sw_build_line_items_placeholder($order);
    if (empty($line_items)) return new WP_Error('soundwave_no_lines', 'No line items composed.');

    // --- Enrich each line ---
    $i = 0;
    foreach ($order->get_items('line_item') as $item) {
        if (!($item instanceof WC_Order_Item_Product)) { $i++; continue; }
        $line_items[$i] = swp_enrich_line_item($item, $line_items[$i] ?? []);
        $i++;
    }

    // --- Shipping lines ---
    $shipping_lines = [];
    foreach ($order->get_shipping_methods() as $it) {
        $shipping_lines[] = [
            'method_id' => $it->get_method_id(),
            'total'     => wc_format_decimal($it->get_total(), 2),
        ];
    }

    $payload = [
        'billing'        => $billing,
        'shipping'       => $shipping,
        'line_items'     => $line_items,
        'shipping_lines' => $shipping_lines,
        'status'         => 'on-hold',
        // Force unpaid so hub lands in On Hold regardless of local status
        'set_paid'       => false,
        'meta_data'      => [
            [
                'key'   => '_affiliate_meta_id',
                'value' => (string) $order->get_id(),
            ],
        ],
    ];

    if (function_exists('sw_payload_overrides_paid'))
        $payload = sw_payload_overrides_paid($payload, $order);

    return $payload;
}}
