<?php
/*
 * File: includes/finalizer-bootstrap.php
 * Description: Hooks into Soundwave’s sync completion and finalizes orders for ShipStation.
 * Plugin: Soundwave — Order Sync
 * Author: Eric Kowalewski
 * Last Updated: 2025-10-28 (EDT)
 */

if ( ! defined('ABSPATH') ) exit;

// Load finalizer class
$__sw_inc_base = defined('SOUNDWAVE_DIR') ? SOUNDWAVE_DIR : plugin_dir_path(__FILE__) . '../';
require_once $__sw_inc_base . 'includes/class-finalizer.php';
unset($__sw_inc_base);

/**
 * Treat a meta value as truthy for our purposes.
 */
function soundwave__truthy($v){
    if (is_bool($v)) return $v;
    $v = strtolower(trim((string)$v));
    return ($v !== '' && $v !== '0' && $v !== 'no' && $v !== 'false' && $v !== 'off');
}

/**
 * When Soundwave sets/updates the synced flag, finalize the order.
 */
function soundwave__finalize_if_synced($meta_id, $object_id, $meta_key, $_meta_value){
    if ($meta_key !== '_soundwave_synced') return;
    if ( ! soundwave__truthy($_meta_value) ) return;

    // Safety: only run for shop_order posts
    $post = get_post($object_id);
    if ( ! $post || $post->post_type !== 'shop_order' ) return;

    // Run the ShipStation finalizer
    if ( class_exists('Soundwave_Finalizer') ) {
        Soundwave_Finalizer::finalize_for_shipstation((int)$object_id, 40158 /* placeholder product id */);
        // Leave a visible breadcrumb for staff
        $order = wc_get_order((int)$object_id);
        if ($order) $order->add_order_note('Soundwave: ShipStation finalizer applied.');
    }
}
add_action('added_post_meta',  'soundwave__finalize_if_synced', 10, 4);
add_action('updated_postmeta', 'soundwave__finalize_if_synced', 10, 4);

/**
 * Optional manual hook: developers can call do_action('soundwave/after_sync', $order_id)
 * if there’s a custom sync flow somewhere else.
 */
add_action('soundwave/after_sync', function($order_id){
    if ( class_exists('Soundwave_Finalizer') ) {
        Soundwave_Finalizer::finalize_for_shipstation((int)$order_id, 40158);
        $order = wc_get_order((int)$order_id);
        if ($order) $order->add_order_note('Soundwave: ShipStation finalizer applied (manual hook).');
    }
}, 10, 1);
