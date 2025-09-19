<?php
/**
 * Soundwave Analytics Guard (add-only)
 * Ensures Soundwave-imported orders appear correctly in WooCommerce Analytics.
 * Scope: ONLY orders created via Soundwave or with unmapped items (product_id=0).
 */
if (!defined('ABSPATH')) exit;

add_action('woocommerce_new_order', function ($order_id) {
  if (!function_exists('wc_get_order')) return;
  $order = wc_get_order($order_id);
  if (!$order) return;

  // Limit to Soundwave-created orders OR orders with unmapped items
  $created_via  = method_exists($order,'get_created_via') ? (string)$order->get_created_via() : '';
  $is_soundwave = stripos($created_via, 'soundwave') !== false || (bool) $order->get_meta('_soundwave_import');

  $changed_items = false;
  foreach ($order->get_items('line_item') as $item) {
    $pid = (int) $item->get_product_id();
    $vid = (int) $item->get_variation_id();
    if ($pid === 0 && $vid === 0) {
      $item->set_product_id(40158); // <-- placeholder product ID
      $item->set_variation_id(0);
      if (!$item->get_meta('_sw_origin')) $item->add_meta_data('_sw_origin', 'soundwave');
      $item->save();
      $changed_items = true;
    }
  }
  if (!$is_soundwave && !$changed_items) return; // never touch non-Soundwave orders

  // Ensure a paid date so charts bucket by that day
  if (method_exists($order,'get_date_paid') && ! $order->get_date_paid()) {
    $order->set_date_paid($order->get_date_created());
  }

  // Ensure a counted status for Analytics (processing/completed/on-hold)
  $counted = ['processing','completed','on-hold'];
  if (!in_array($order->get_status(), $counted, true)) {
    $order->update_status('processing', 'Soundwave analytics guard');
  }

  $order->calculate_totals();
  $order->save();

  // Update Analytics lookup rows if available
  if (function_exists('wc_update_order_stats')) {
    wc_update_order_stats($order_id);
  }

  // Invalidate Woo Admin (reports) cache across versions
  if (class_exists(\Automattic\WooCommerce\Admin\API\Reports\Cache::class)) {
    $cls = \Automattic\WooCommerce\Admin\API\Reports\Cache::class;
    if (method_exists($cls,'invalidate'))       { $cls::invalidate(); }
    elseif (method_exists($cls,'invalidate_all')) { $cls::invalidate_all(); }
  }
  if (class_exists('WC_Cache_Helper')) {
    WC_Cache_Helper::incr_cache_prefix('woocommerce_reports');
  }
}, 20);
