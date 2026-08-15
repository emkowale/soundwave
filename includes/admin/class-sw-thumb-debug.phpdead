<?php
/*
 * Soundwave: thumbnail debug helper (logs & banner). v0.1
 */
defined('ABSPATH') || exit;

class SW_Thumb_Debug {
  public static function init() {
    if (!is_admin()) return;
    add_action('current_screen', [__CLASS__, 'on_screen']);
  }

  public static function on_screen($screen) {
    // Only on order edit screens
    $id = isset($screen->id) ? (string)$screen->id : '';
    if ($id !== 'shop_order' && strpos($id, 'woocommerce_page_wc-orders') === false) return;

    // Confirm our hooks are attached
    add_action('admin_notices', function() {
      $a = has_filter('woocommerce_admin_order_item_thumbnail');
      $b = has_filter('woocommerce_admin_html_order_item_thumbnail');
      echo '<div class="notice notice-info"><p>Soundwave thumb debug ACTIVE — hooks: thumbnail='
           . intval($a) . ' html_thumbnail=' . intval($b) . '</p></div>';
    });

    // Hook both variants (very late)
    add_filter('woocommerce_admin_order_item_thumbnail', [__CLASS__, 'thumb'], 9999, 3);
    add_filter('woocommerce_admin_html_order_item_thumbnail', [__CLASS__, 'thumb'], 9999, 3);
  }

  public static function thumb($html, $item_id = null, $item = null) {
    $url = wc_get_order_item_meta($item_id, 'product_image_full', true);
    error_log('[SW-THUMB] item_id=' . $item_id . ' url=' . ($url ?: '(none)'));
    if (!$url) return $html; // Nothing to show

    $src = esc_url($url);
    $alt = $item && method_exists($item, 'get_name') ? esc_attr($item->get_name()) : 'Image';

    // Very explicit HTML to avoid theme/admin overrides
    return '<img src="'.$src.'" alt="'.$alt.'" width="48" height="48" '.
           'style="display:block;width:48px;height:48px;object-fit:contain;border:1px solid #eee;background:#fff;" />';
  }
}
SW_Thumb_Debug::init();
