<?php
/*
 * File: includes/admin/class-sw-sync-status-ajax.php
 * Description: Batch-check sync status for orders visible on the page.
 * Plugin: Soundwave (WooCommerce Order Sync)
 * Version: 1.2.0
 * Last Updated: 2025-09-27 22:05 EDT
 */
if (!defined('ABSPATH')) exit;

class SW_Sync_Status_Ajax {
    const NONCE_KEY = 'sw_sync_order_nonce';

    public static function init() {
        add_action('wp_ajax_sw_check_order_sync_status', [__CLASS__, 'handle']);
    }

    public static function handle() {
        if (!current_user_can('manage_woocommerce')) wp_send_json_error(['message' => 'no_cap'], 403);

        $nonce = isset($_POST['nonce']) ? sanitize_text_field($_POST['nonce']) : '';
        if (!$nonce || !wp_verify_nonce($nonce, self::NONCE_KEY)) wp_send_json_error(['message' => 'bad_nonce'], 400);

        $ids = isset($_POST['order_ids']) ? (array) $_POST['order_ids'] : [];
        $ids = array_values(array_filter(array_map('absint', $ids)));
        $out = [];

        foreach ($ids as $id) $out[$id] = self::is_synced($id);

        wp_send_json_success(['statuses' => $out]);
    }

    private static function is_synced($order_id) {
        // Fast local flag
        if (get_post_meta($order_id, '_soundwave_synced', true) === 'yes') return true;

        // Best-effort back-compat meta keys from earlier builds
        foreach (['_soundwave_remote_id','_soundwave_dest_order_id','_soundwave_destination_order_id'] as $k) {
            if (get_post_meta($order_id, $k, true)) return true;
        }

        // Allow site code to do a remote check and return true/false
        $remote = apply_filters('soundwave/check_remote_synced', null, $order_id);
        if (is_bool($remote)) return $remote;

        return false;
    }
}
SW_Sync_Status_Ajax::init();
