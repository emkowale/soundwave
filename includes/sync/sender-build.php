<?php
if ( ! defined('ABSPATH') ) exit;

function soundwave_sender_build_payload(WC_Order $order, int $order_id){
    $payload = null; $builder_err = null;

    if ( function_exists('sw_compose_payload') ) {
        $payload = sw_compose_payload($order);
        if ( is_wp_error($payload) ) $builder_err = $payload;
    } elseif ( function_exists('soundwave_prepare_order_payload') ) {
        $payload = soundwave_prepare_order_payload($order);
        if ( is_wp_error($payload) ) $builder_err = $payload;
    } else {
        $builder_err = new WP_Error('payload_builder_missing','Payload builder not available');
    }

    if ( ! is_wp_error($builder_err) ) return $payload;

    $edata   = (array)$builder_err->get_error_data();
    $missing = [];
    foreach (['missing','missing_fields','invalid_fields','errors'] as $k) {
        if (!empty($edata[$k]) && is_array($edata[$k])) $missing = array_merge($missing, array_map('strval',$edata[$k]));
    }
    $missing = array_values(array_unique(array_filter($missing, fn($s)=>trim($s)!=='')));

    $note = empty($missing)
        ? "Soundwave sync failed — order not synced.\nA required order detail couldn’t be built. Review the product’s Custom Fields and required variation selections (Color, Size), then click Sync again."
        : "Soundwave sync failed — order not synced.\nMissing/invalid (per product):\n• ".implode("\n• ", $missing)."\nFix the fields above, then click Sync again.";

    if ( function_exists('soundwave_note_once') ) soundwave_note_once($order_id,'soundwave_payload_error',$note);
    else $order->add_order_note($note);

    update_post_meta($order_id,'_soundwave_last_error',$note);
    update_post_meta($order_id,'_soundwave_synced','0');
    return $builder_err;
}
