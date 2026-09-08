<?php
/*
 * File: /includes/soundwave-rest-import.php
 * Description: REST endpoint to ingest affiliate orders and feed Analytics.
 * Plugin: Soundwave
 * Author: Eric Kowalewski
 * Version: 1.1.31
 * Last Updated: 2025-09-18 15:22 EDT
 */

if ( ! defined('ABSPATH') ) { exit; }

add_action('rest_api_init', function () {
  register_rest_route('soundwave/v1', '/import', [
    'methods'  => 'POST',
    'permission_callback' => function (\WP_REST_Request $req) {
      // Allow if logged-in admin (handy for testing)
      if ( current_user_can('manage_woocommerce') ) { return true; }
      // Or allow if token matches (header or query)
      $token_opt = get_option('soundwave_import_token', '');
      $token = $req->get_header('X-Soundwave-Token') ?: $req->get_param('token');
      return ($token_opt && hash_equals($token_opt, (string) $token));
    },
    'callback' => function (\WP_REST_Request $req) {
      $payload = json_decode($req->get_body(), true);
      if ( ! is_array($payload) || empty($payload['items']) ) {
        return new \WP_REST_Response(['ok' => false, 'error' => 'Invalid payload'], 400);
      }

      if ( ! function_exists('sw_create_order_from_payload') ) {
        return new \WP_REST_Response(['ok' => false, 'error' => 'Factory not loaded'], 500);
      }

      $order_id = sw_create_order_from_payload($payload);
      if ( ! $order_id ) {
        return new \WP_REST_Response(['ok' => false, 'error' => 'Order create failed'], 500);
      }

      $order = wc_get_order($order_id);
      return [
        'ok'       => true,
        'order_id' => $order_id,
        'status'   => $order ? $order->get_status() : null,
        'total'    => $order ? (float) $order->get_total() : null,
      ];
    },
  ]);
});
