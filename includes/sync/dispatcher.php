<?php
/*
 * File: includes/sync/dispatcher.php
 * Purpose: Canonical per-order sync (no Woo hooks here).
 *          Compose payload (supports either signature), inject product_image_full,
 *          send, and record success locally if no recorder helper is present.
 */
if (!defined('ABSPATH')) exit;

if (!function_exists('soundwave_sync_order_to_beartraxs')) {
    function soundwave_sync_order_to_beartraxs($order_id, $ctx = []) {
        $order_id = (int) $order_id;
        $ctx      = (array) $ctx;

        // Ensure we have an order object handy
        $order = wc_get_order($order_id);
        if (!$order) return new WP_Error('soundwave_no_order', 'Order not found.');

        // 1) Compose payload (support both old/new function signatures)
        $compose = __DIR__ . '/payload_compose.php';
        if (is_readable($compose)) require_once $compose;
        if (!function_exists('sw_compose_payload')) {
            $err = new WP_Error('soundwave_missing_compose', 'Composer not found (sw_compose_payload).');
            $order->add_order_note('Soundwave sync aborted — ' . $err->get_error_message());
            update_post_meta($order_id, '_soundwave_last_error', $err->get_error_code() . ': ' . $err->get_error_message());
            return $err;
        }

        try {
            $rf = new ReflectionFunction('sw_compose_payload');
            if ($rf->getNumberOfParameters() >= 2) {
                // Newer signature: (int $order_id, array $ctx)
                $payload = sw_compose_payload($order_id, $ctx);
            } else {
                // Older signature: (WC_Order $order)
                $payload = sw_compose_payload($order);
            }
        } catch (Throwable $e) {
            $err = new WP_Error('soundwave_compose_error', $e->getMessage());
            $order->add_order_note('Soundwave sync aborted — ' . $err->get_error_message());
            update_post_meta($order_id, '_soundwave_last_error', $err->get_error_code() . ': ' . $err->get_error_message());
            return $err;
        }

        // Guard: composer may return WP_Error
        if (is_wp_error($payload)) {
            $order->add_order_note('Soundwave sync aborted — ' . $payload->get_error_message());
            update_post_meta($order_id, '_soundwave_last_error', $payload->get_error_code() . ': ' . $payload->get_error_message());
            return $payload;
        }

        if (!is_array($payload)) {
            $err = new WP_Error('soundwave_payload_invalid', 'Payload is not an array. Aborting send.');
            $order->add_order_note('Soundwave sync aborted — ' . $err->get_error_message());
            update_post_meta($order_id, '_soundwave_last_error', $err->get_error_code() . ': ' . $err->get_error_message());
            return $err;
        }

        // 2) Enrich with product_image_full (auto-derived) — never fatal
        $__img_helper = __DIR__ . '/helpers/product-image.php';
        if (is_readable($__img_helper)) {
            require_once $__img_helper;
            if (function_exists('sw_enrich_payload_with_image')) {
                $payload = sw_enrich_payload_with_image($payload, $order_id);
                if (is_wp_error($payload)) {
                    $order->add_order_note('Soundwave sync aborted — ' . $payload->get_error_message());
                    update_post_meta($order_id, '_soundwave_last_error', $payload->get_error_code() . ': ' . $payload->get_error_message());
                    return $payload;
                }
            }
        } else {
            error_log('Soundwave: missing helper ' . $__img_helper);
        }

        // 3) Send to destination
        $http = __DIR__ . '/http_send.php';
        if (is_readable($http)) require_once $http;
        if (!function_exists('sw_http_send')) {
            $err = new WP_Error('soundwave_missing_http', 'HTTP sender not found (sw_http_send).');
            $order->add_order_note('Soundwave sync aborted — ' . $err->get_error_message());
            update_post_meta($order_id, '_soundwave_last_error', $err->get_error_code() . ': ' . $err->get_error_message());
            return $err;
        }

        try {
            // Allow http layer to see order_id in ctx if desired
            $ctx['order_id'] = $order_id;
            $resp = sw_http_send($payload, $ctx);
        } catch (Throwable $e) {
            $err = new WP_Error('soundwave_http_error', $e->getMessage());
            $order->add_order_note('Soundwave sync aborted — ' . $err->get_error_message());
            update_post_meta($order_id, '_soundwave_last_error', $err->get_error_code() . ': ' . $err->get_error_message());
            return $err;
        }

        if (is_wp_error($resp)) {
            $order->add_order_note('Soundwave sync failed — ' . $resp->get_error_message());
            update_post_meta($order_id, '_soundwave_last_error', $resp->get_error_code() . ': ' . $resp->get_error_message());
            return $resp;
        }

        if (!is_array($resp)) {
            $err = new WP_Error('soundwave_http_invalid', 'Sender returned invalid response.');
            $order->add_order_note('Soundwave sync failed — ' . $err->get_error_message());
            update_post_meta($order_id, '_soundwave_last_error', $err->get_error_code() . ': ' . $err->get_error_message());
            return $err;
        }

        // 4) Record status
        $remote_id = '';
        foreach (['remote_id','id','order_id','dest_order_id'] as $k) {
            if (!empty($resp[$k])) { $remote_id = (string) $resp[$k]; break; }
        }
        if (!$remote_id) {
            $maybe = get_post_meta($order_id, '_soundwave_dest_order_id', true);
            if ($maybe) $remote_id = (string)$maybe;
        }

        // Prefer helper if present; otherwise record locally (so we never error out)
        $rec = __DIR__ . '/record_status.php';
        if (is_readable($rec)) require_once $rec;
        if (function_exists('sw_record_status')) {
            $out = sw_record_status($order_id, $resp, $ctx);
            if (is_wp_error($out)) {
                $order->add_order_note('Soundwave sync failed — ' . $out->get_error_message());
                update_post_meta($order_id, '_soundwave_last_error', $out->get_error_code() . ': ' . $out->get_error_message());
                return $out;
            }
        } else {
            if ($remote_id) update_post_meta($order_id, '_soundwave_dest_order_id', $remote_id);
            update_post_meta($order_id, '_soundwave_synced', 'yes');
            delete_post_meta($order_id, '_soundwave_last_error');
            $order->add_order_note('Soundwave: Synced successfully' . ($remote_id ? " (remote #{$remote_id})" : '') . '.');
        }

        return ['ok' => true, 'remote_id' => $remote_id];
    }
}
