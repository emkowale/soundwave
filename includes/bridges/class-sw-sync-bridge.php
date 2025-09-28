<?php
/*
 * File: includes/bridges/class-sw-sync-bridge.php
 * Purpose: Respect earlier filter results; call the canonical sync only if allowed.
 * Version: 1.2.0
 */
if (!defined('ABSPATH')) exit;

class SW_Sync_Bridge {
    public static function init() {
        add_filter('soundwave/run_sync_for_order', [__CLASS__, 'run'], 10, 3);
    }

    public static function run($result, $order_id, $ctx = []) {
        // If a prior filter (like preflight) already returned a value, honor it.
        if ($result instanceof WP_Error || $result === true || is_array($result)) return $result;

        if (function_exists('soundwave_sync_order_to_beartraxs')) {
            return soundwave_sync_order_to_beartraxs((int)$order_id, (array)$ctx);
        }
        if (function_exists('sw_sync_single_order')) {
            return sw_sync_single_order((int)$order_id, (array)$ctx);
        }
        return new WP_Error('soundwave_missing_sync',
            'Sync function not found (expected soundwave_sync_order_to_beartraxs or sw_sync_single_order).');
    }
}
SW_Sync_Bridge::init();
