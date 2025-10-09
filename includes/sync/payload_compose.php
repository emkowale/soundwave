<?php
/*
 * File: includes/sync/payload_compose.php
 * Purpose: Build the Woo REST payload from a WC_Order (safe for ID or object).
 * Notes: Uses the placeholder line-items builder; no fatal calls to get_address().
 */
if (!defined('ABSPATH')) exit;

// Keep your small, modular builders
require_once __DIR__ . '/line_builder_placeholder.php';
// If you have overrides, we guard-call them later (won't fatal if absent)
$__sw_has_overrides = is_readable(__DIR__ . '/payload_overrides_paid.php');
if ($__sw_has_overrides) require_once __DIR__ . '/payload_overrides_paid.php';

if (!function_exists('sw_compose_payload')) {
    function sw_compose_payload($order_ref, $ctx = []) {
        // Accept either an order ID or a WC_Order object
        $order = (is_object($order_ref) && $order_ref instanceof WC_Order)
            ? $order_ref
            : wc_get_order((int)$order_ref);

        if (!$order) {
            return new WP_Error('soundwave_no_order', 'Order not found.');
        }

        // --- Billing & Shipping (explicit getters; no get_address() fatal) ---
        $billing = [
            'first_name' => $order->get_billing_first_name(),
            'last_name'  => $order->get_billing_last_name(),
            'company'    => $order->get_billing_company(),
            'address_1'  => $order->get_billing_address_1(),
            'address_2'  => $order->get_billing_address_2(),
            'city'       => $order->get_billing_city(),
            'state'      => $order->get_billing_state(),
            'postcode'   => $order->get_billing_postcode(),
            'country'    => $order->get_billing_country(),
            'email'      => $order->get_billing_email(),
            'phone'      => $order->get_billing_phone(),
        ];

        $shipping = [
            'first_name' => $order->get_shipping_first_name(),
            'last_name'  => $order->get_shipping_last_name(),
            'company'    => $order->get_shipping_company(),
            'address_1'  => $order->get_shipping_address_1(),
            'address_2'  => $order->get_shipping_address_2(),
            'city'       => $order->get_shipping_city(),
            'state'      => $order->get_shipping_state(),
            'postcode'   => $order->get_shipping_postcode(),
            'country'    => $order->get_shipping_country(),
        ];

        // --- Line items via your placeholder builder ---
        if (!function_exists('sw_build_line_items_placeholder')) {
            return new WP_Error('soundwave_missing_builder', 'Line-items builder not found (line_builder_placeholder).');
        }
        $line_items = sw_build_line_items_placeholder($order);
        if (empty($line_items)) {
            return new WP_Error('soundwave_no_lines', 'No line items composed.');
        }

        // --- Shipping lines (carry totals through) ---
        $shipping_lines = [];
        foreach ($order->get_shipping_methods() as $item) {
            $shipping_lines[] = [
                'method_id' => $item->get_method_id(),
                'total'     => wc_format_decimal($item->get_total(), 2),
            ];
        }

        // --- Base payload ---
        $payload = [
            'billing'        => $billing,
            'shipping'       => $shipping,
            'line_items'     => $line_items,
            'shipping_lines' => $shipping_lines,
            'status'         => 'processing',
            'set_paid'       => $order->is_paid(),
            'meta_data'      => [], // image + other meta injected later
        ];

        // --- Optional: allow your override file to tweak paid dates/props ---
        if (function_exists('sw_payload_overrides_paid')) {
            // Prefer signature ($payload, $order). If your override uses ($payload, $order_id), it still works.
            $payload = sw_payload_overrides_paid($payload, $order);
        }

        return $payload;
    }
}
