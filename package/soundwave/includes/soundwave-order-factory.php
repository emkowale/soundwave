<?php
/*
 * File: /includes/soundwave-order-factory.php
 * Description: Creates Woo orders from affiliate payloads and finalizes for Analytics.
 * Plugin: Soundwave
 * Author: Eric Kowalewski
 * Version: 1.1.30
 * Last Updated: 2025-09-18 14:52 EDT
 */

if ( ! defined('ABSPATH') ) { exit; }

if ( ! function_exists('sw_create_order_from_payload') ) {
  /**
   * Create an order from affiliate payload and make it Analytics-safe.
   * Expected $payload keys:
   *   items[]: [name, sku, qty, subtotal, total, dest_product_id?, dest_variation_id?, image?]
   *   created_at?: 'Y-m-d H:i:s', paid_at?: 'Y-m-d H:i:s'
   * Returns order ID on success.
   */
  function sw_create_order_from_payload(array $payload): int {
    if ( ! function_exists('wc_create_order') ) { return 0; }

    $order = wc_create_order([ 'created_via' => 'soundwave' ]);

    // Add line items (maps unknowns to placeholder 40158).
    $items = $payload['items'] ?? [];
    foreach ($items as $it) {
      sw_add_affiliate_item($order, [
        'name'  => $it['name']  ?? '',
        'sku'   => $it['sku']   ?? '',
        'qty'   => $it['qty']   ?? 1,
        'subtotal' => (float)($it['subtotal'] ?? 0),
        'total'    => (float)($it['total']    ?? 0),
        'dest_product_id'   => $it['dest_product_id']   ?? null,
        'dest_variation_id' => $it['dest_variation_id'] ?? 0,
        'image' => $it['image'] ?? '',
      ]);
    }

    // Finalize with dates so charts bucket correctly (paid_at falls back to created_at).
    sw_finalize_order_for_analytics($order, [
      'created_at' => $payload['created_at'] ?? null,
      'paid_at'    => $payload['paid_at']    ?? ($payload['created_at'] ?? null),
    ]);

    return (int) $order->get_id();
  }
}
