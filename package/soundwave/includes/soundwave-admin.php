<?php
/*
 * File: /includes/soundwave-admin.php
 * Purpose: Soundwave menu with Dashboard
 */
if ( ! defined('ABSPATH') ) exit;

/** Top-level + submenus */
add_action('admin_menu', function () {
  // Top-level
  add_menu_page(
    'Soundwave','Soundwave','manage_woocommerce','soundwave',
    'soundwave_render_sync_screen','dashicons-megaphone',56
  );
  // Dashboard points to the same renderer as top-level
  add_submenu_page(
    'soundwave','Dashboard','Dashboard','manage_woocommerce',
    'soundwave','soundwave_render_sync_screen'
  );

});

// --- Ensure "Print Location" shows under Size in admin item meta ---
add_filter('woocommerce_order_item_get_formatted_meta_data', function ($formatted, $item) {
    if (empty($formatted)) return $formatted;

    // Desired order
    $want = ['Color', 'Size', 'Print Location', 'Quality'];

    // Build lookup by display label
    $by = [];
    foreach ($formatted as $m) {
        $label = isset($m->display_key) ? trim($m->display_key) : '';
        $by[$label][] = $m;
    }

    // Map common key aliases → "Print Location"
    $aliases = [
        'print_location','print-location',
        'pa_print-location','attribute_pa_print-location',
        'pa_print_location','attribute_pa_print_location'
    ];
    foreach ($formatted as $m) {
        $k = strtolower($m->key ?? '');
        if (in_array($k, $aliases, true) && !isset($by['Print Location'])) {
            $clone = clone $m;
            $clone->display_key = 'Print Location';
            $by['Print Location'][] = $clone;
        }
    }

    // Rebuild in preferred order, then append everything else in original order
    $rebuilt = [];
    foreach ($want as $w) {
        if (!empty($by[$w])) {
            foreach ($by[$w] as $m) $rebuilt[] = $m;
            unset($by[$w]);
        }
    }
    foreach ($formatted as $m) {
        $label = isset($m->display_key) ? trim($m->display_key) : '';
        if (isset($by[$label])) $rebuilt[] = $m;
    }
    return $rebuilt;
}, 10, 2);

// --- Show item thumbnail from product_image_full (fallback to product image) ---
add_filter('woocommerce_admin_order_item_thumbnail', function ($thumb_html, $item_id) {

    $item = WC_Order_Factory::get_order_item($item_id);
    if (!$item || !is_a($item, 'WC_Order_Item_Product')) return $thumb_html;

    // Look through formatted meta for product_image_full
    $meta = $item->get_formatted_meta_data('');
    $img_url = '';
    foreach ($meta as $m) {
        $label = strtolower(trim($m->display_key ?? ''));
        $key   = strtolower(trim($m->key ?? ''));
        if ($label === 'product_image_full' || $key === 'product_image_full') {
            $val = trim(wp_strip_all_tags($m->display_value));
            // if it came in as an <a href="..."> extract URL
            if (strpos($val, '<a ') !== false && preg_match('~href=[\'"]([^\'"]+)~i', $val, $mm)) {
                $val = $mm[1];
            }
            $img_url = $val;
            break;
        }
    }

    if ($img_url) {
        return sprintf(
            '<img class="sw-thumb" src="%s" alt="" style="width:60px;height:60px;object-fit:cover;border-radius:6px;" />',
            esc_url($img_url)
        );
    }

    // Fallback: product featured image
    $product = $item->get_product();
    if ($product && $product->get_image_id()) {
        return wp_get_attachment_image($product->get_image_id(), [60,60], false, [
            'style' => 'width:60px;height:60px;object-fit:cover;border-radius:6px;'
        ]);
    }
    return $thumb_html;
}, 10, 2);

