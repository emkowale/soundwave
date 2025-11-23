<?php
if ( ! defined('ABSPATH') ) exit;

require_once __DIR__.'/sender-validation.php';
require_once __DIR__.'/sender-build.php';
require_once __DIR__.'/sender-request.php';

if ( ! function_exists('soundwave_send_to_hub') ) {
function soundwave_send_to_hub( int $order_id ) {
    $order = wc_get_order( $order_id );
    if ( ! $order ) return new WP_Error('order_not_found', 'Order not found.');

    $validation = soundwave_sender_validate_order($order_id, $order);
    if ( is_wp_error($validation) ) return $validation;

    $payload = soundwave_sender_build_payload($order, $order_id);
    if ( is_wp_error($payload) ) return $payload;

    $cfg = function_exists('soundwave_get_settings') ? soundwave_get_settings() : [];
    return soundwave_sender_request($order_id, $order, $payload, $cfg);
}}
