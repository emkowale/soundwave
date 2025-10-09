<?php
/*
 * File: includes/util/order-meta.php
 * Plugin: Soundwave
 * Description: Helpers for extracting/forwarding order meta (mirrors original-art) including company-name.
 * Author: Eric Kowalewski
 * Version: 1.2.1
 * Last Updated: 2025-10-06 16:35 EDT
 */

if (!defined('ABSPATH')) exit;

/**
 * Allowlist of meta keys that Soundwave forwards/ingests.
 * Mirror original-art and include company-name.
 */
if (!function_exists('sw_meta_allowlist')) {
    function sw_meta_allowlist() {
        return array(
            'original-art',
            'company-name',
        );
    }
}

/**
 * Append a key/value into Woo REST-compatible meta_data array.
 *
 * @param array  $meta_data Reference to array of ['key'=>..., 'value'=>...]
 * @param string $key
 * @param mixed  $value
 */
if (!function_exists('sw_push_order_meta')) {
    function sw_push_order_meta(array &$meta_data, $key, $value) {
        if ($value === '' || $value === null) return;
        $meta_data[] = array('key' => (string)$key, 'value' => $value);
    }
}

/**
 * Extract company-name from the WC_Order.
 * Priority:
 *  1) order meta: company-name / _company-name / Company Name
 *  2) fallback: billing company
 *
 * @param WC_Order $order
 * @return string
 */
if (!function_exists('sw_get_company_name_from_order')) {
    function sw_get_company_name_from_order(WC_Order $order) {
        foreach (array('company-name','_company-name','Company Name') as $k) {
            $v = $order->get_meta($k, true);
            if (!empty($v)) return (string)$v;
        }
        if (method_exists($order, 'get_billing_company')) {
            $v = $order->get_billing_company();
            if (!empty($v)) return (string)$v;
        }
        return '';
    }
}

/**
 * Append company-name (and keep parity with original-art flow) to an outbound payload.
 * Call this right after you add original-art to $payload['meta_data'].
 *
 * @param array    $payload Reference to outbound payload array
 * @param WC_Order $order
 */
if (!function_exists('sw_append_company_name_to_payload')) {
    function sw_append_company_name_to_payload(array &$payload, WC_Order $order) {
        $company = sw_get_company_name_from_order($order);

        if (!isset($payload['meta_data']) || !is_array($payload['meta_data'])) {
            $payload['meta_data'] = array();
        }
        sw_push_order_meta($payload['meta_data'], 'company-name', $company);

        // If you already mirror original-art onto each line item, do the same here:
        if (!empty($payload['line_items']) && is_array($payload['line_items'])) {
            foreach ($payload['line_items'] as &$li) {
                if (!isset($li['meta_data']) || !is_array($li['meta_data'])) $li['meta_data'] = array();
                sw_push_order_meta($li['meta_data'], 'company-name', $company);
            }
            unset($li);
        }
    }
}

/**
 * Ingest allowlisted order meta from an incoming payload onto a WC_Order (destination).
 *
 * @param array    $incoming_payload Decoded payload that includes meta_data
 * @param WC_Order $order            Destination order instance
 */
if (!function_exists('sw_ingest_order_meta_allowlisted')) {
    function sw_ingest_order_meta_allowlisted(array $incoming_payload, WC_Order $order) {
        if (empty($incoming_payload['meta_data']) || !is_array($incoming_payload['meta_data'])) {
            return;
        }
        $allowed = sw_meta_allowlist();

        foreach ($incoming_payload['meta_data'] as $m) {
            if (!is_array($m) || !isset($m['key'])) continue;
            $key = (string)$m['key'];
            if (!in_array($key, $allowed, true)) continue;

            $val = isset($m['value']) ? $m['value'] : '';
            $order->update_meta_data($key, $val);
        }
        $order->save();
    }
}
