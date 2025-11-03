<?php
/*
 * File: includes/admin/class-sw-order-sync-ajax.php
 * Purpose: One-click Sync from Orders list with reliable logging for the debug panel.
 * Version: 1.2.1
 */
if (!defined('ABSPATH')) exit;

class SW_Order_Sync_Ajax {
    const NONCE_KEY = 'sw_sync_order_nonce';

    public static function init() {
        add_action('wp_ajax_sw_sync_order', [__CLASS__, 'handle']);
    }

    public static function handle() {
        if (!current_user_can('manage_woocommerce')) wp_send_json_error(['message' => 'no_cap'], 403);

        $order_id = isset($_POST['order_id']) ? absint($_POST['order_id']) : 0;
        $nonce    = isset($_POST['nonce']) ? sanitize_text_field($_POST['nonce']) : '';
        if (!$order_id || !$nonce || !wp_verify_nonce($nonce, self::NONCE_KEY)) {
            wp_send_json_error(['message' => 'bad_request'], 400);
        }

        $order = wc_get_order($order_id);
        if (!$order) wp_send_json_error(['message' => 'order_not_found'], 404);

        $debug = (bool) apply_filters('soundwave/debug_mode_enabled', get_option('soundwave_debug_mode') === 'yes');
        $ctx   = ['debug' => $debug, 'source' => 'manual:orders_list'];

        // Run preflight + bridge + sync function chain.
        $result = apply_filters('soundwave/run_sync_for_order', null, $order_id, $ctx);

        // Always timestamp attempts so the debug panel has something to show.
        update_post_meta($order_id, '_soundwave_last_attempt', current_time('mysql'));

        if (is_wp_error($result)) {
            $msg = $result->get_error_message();
            update_post_meta($order_id, '_soundwave_last_error', $msg);
            // Let the operator see it immediately in the order.
            $order->add_order_note('Soundwave: Sync failed — ' . $msg);
            wp_send_json_error(['message' => $msg]);
        }

        // Success path: ensure the synced flag and remote id are present.
        $remote_id = '';
        if (is_array($result)) {
            foreach (['remote_id','id','order_id','dest_order_id'] as $k) {
                if (!empty($result[$k])) { $remote_id = (string) $result[$k]; break; }
            }
        }
        if (!$remote_id) {
            // Fallback: maybe the recorder already saved it.
            $remote_id = get_post_meta($order_id, '_soundwave_dest_order_id', true);
        }
        if ($remote_id) {
            update_post_meta($order_id, '_soundwave_dest_order_id', $remote_id);
        }
        update_post_meta($order_id, '_soundwave_synced', 'yes');
        delete_post_meta($order_id, '_soundwave_last_error');

        $order->add_order_note('Soundwave: Synced successfully' . ($remote_id ? " (remote #{$remote_id})" : '') . '.');

        wp_send_json_success(['remote_id' => $remote_id]);
    }
}
SW_Order_Sync_Ajax::init();
