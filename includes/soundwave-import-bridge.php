<?php
/*
 * File: /includes/soundwave-import-bridge.php
 * Description: Normalizes affiliate payloads and feeds sw_create_order_from_payload().
 * Plugin: Soundwave
 * Author: Eric Kowalewski
 * Version: 1.1.32
 * Last Updated: 2025-09-18 16:20 EDT
 */

if ( ! defined('ABSPATH') ) { exit; }

/**
 * Normalize a variety of affiliate payload shapes into:
 * [
 *   'created_at' => 'Y-m-d H:i:s',
 *   'paid_at'    => 'Y-m-d H:i:s',
 *   'items'      => [ [name, sku, qty, subtotal, total, dest_product_id?, dest_variation_id?, image?], ... ]
 * ]
 */
if ( ! function_exists('soundwave_ingest') ) {
  function soundwave_ingest(array $src): int {
    // Timestamps (try common keys; fall back to now)
    $created = $src['created_at'] ?? $src['order_date'] ?? $src['date_created'] ?? null;
    $paid    = $src['paid_at']    ?? $src['payment_date'] ?? $src['date_paid'] ?? null;
    if ( empty($created) ) { $created = current_time('mysql'); }
    if ( empty($paid) )    { $paid    = $created; }

    // Items can be under several keys: items / lines / products
    $rawItems = [];
    if ( ! empty($src['items']) && is_array($src['items']) )         { $rawItems = $src['items']; }
    elseif ( ! empty($src['lines']) && is_array($src['lines']) )     { $rawItems = $src['lines']; }
    elseif ( ! empty($src['products']) && is_array($src['products'])){ $rawItems = $src['products']; }

    $items = [];
    foreach ($rawItems as $it) {
      $items[] = [
        'name'  => (string)($it['name'] ?? $it['title'] ?? ''),
        'sku'   => (string)($it['sku']  ?? $it['product_sku'] ?? ''),
        'qty'   => (int)  ($it['qty']  ?? $it['quantity'] ?? 1),
        // Prefer totals provided; else derive from price*qty
        'subtotal' => (float)($it['subtotal'] ?? $it['line_subtotal'] ?? ($it['price'] ?? 0) * (int)($it['qty'] ?? 1)),
        'total'    => (float)($it['total']    ?? $it['line_total']    ?? ($it['price'] ?? 0) * (int)($it['qty'] ?? 1)),
        // Destination IDs if your mapping provides them; otherwise guard uses 40158
        'dest_product_id'   => isset($it['dest_product_id'])   ? (int)$it['dest_product_id']   : null,
        'dest_variation_id' => isset($it['dest_variation_id']) ? (int)$it['dest_variation_id'] : 0,
        'image'  => (string)($it['image'] ?? $it['image_url'] ?? ''),
      ];
    }

    $payload = [
      'created_at' => $created,
      'paid_at'    => $paid,
      'items'      => $items,
    ];

    if ( ! function_exists('sw_create_order_from_payload') ) { return 0; }
    return sw_create_order_from_payload($payload);
  }
}
