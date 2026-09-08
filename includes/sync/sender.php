<?php
if ( ! defined('ABSPATH') ) exit;

require_once __DIR__.'/sender-validation.php';
require_once __DIR__.'/sender-build.php';
require_once __DIR__.'/sender-request.php';

if ( ! function_exists('soundwave_sender_truthy') ) {
function soundwave_sender_truthy($v): bool {
    $s = strtolower(trim((string)$v));
    return in_array($s, ['1','yes','true','on'], true);
}}

if ( ! function_exists('soundwave_sender_existing_hub_id') ) {
function soundwave_sender_existing_hub_id(int $order_id): string {
    foreach (['_soundwave_hub_id', '_soundwave_dest_order_id', '_hub_order_id'] as $k) {
        $v = trim((string) get_post_meta($order_id, $k, true));
        if ($v !== '' && (int)$v > 0) return $v;
    }
    return '';
}}

if ( ! function_exists('soundwave_send_to_hub') ) {
function soundwave_send_to_hub( int $order_id ) {
    $order = wc_get_order( $order_id );
    if ( ! $order ) return new WP_Error('order_not_found', 'Order not found.');

    if (function_exists('soundwave_popup_manifest_defer_open_store_order')
        && soundwave_popup_manifest_defer_open_store_order($order)) {
        return new WP_Error('popup_store_open', 'This popup store is still open. Its orders will be sent together after the closing date.');
    }

    $allow_resend = (bool) apply_filters('soundwave_allow_resend_synced_order', false, $order_id, $order);
    if ( ! $allow_resend ) {
        $synced = soundwave_sender_truthy(get_post_meta($order_id, '_soundwave_synced', true));
        $hub_id = soundwave_sender_existing_hub_id($order_id);
        if ($synced && $hub_id !== '') {
            return ['ok'=>true,'status'=>200,'hub_id'=>$hub_id,'skipped'=>true,'message'=>'Already synced'];
        }
    }

    $validation = soundwave_sender_validate_order($order_id, $order);
    if ( is_wp_error($validation) ) return $validation;

    $payload = soundwave_sender_build_payload($order, $order_id);
    if ( is_wp_error($payload) ) return $payload;

    $cfg = function_exists('soundwave_get_settings') ? soundwave_get_settings() : [];
    return soundwave_sender_request($order_id, $order, $payload, $cfg);
}}
