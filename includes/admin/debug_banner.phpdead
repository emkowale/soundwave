<?php
/*
 * File: /includes/admin/debug_banner.php
 * Purpose: Health/Debug banner + "Show Debug on order screen" toggle (render-only).
 */
if (!defined('ABSPATH')) exit;

/** Pick the pref key currently in use (back-compat) */
if (!function_exists('sw_get_debug_pref_key')) {
  function sw_get_debug_pref_key() {
    foreach (['sw_debug_on_order_screen','sw_show_order_debug'] as $k) {
      $v = get_option($k, null);
      if ($v !== null) return $k;
    }
    return 'sw_debug_on_order_screen';
  }
}

/** Render the compact banner */
if (!function_exists('sw_render_debug_banner')) {
  function sw_render_debug_banner($dest_base) {
    $pref_key   = sw_get_debug_pref_key();
    $show_debug = get_option($pref_key, '0') === '1';
    $last_ts    = (int) get_option('sw_last_sync_ok_ts', 0);
    $last_txt   = $last_ts ? date_i18n(get_option('date_format').' '.get_option('time_format'), $last_ts) : 'never';
    $post       = esc_url(admin_url('admin-post.php'));

    echo '<div class="notice notice-info" style="margin-top:10px;">'
       . '<form method="post" action="'.$post.'">';
    wp_nonce_field('sw_debug_pref');
    echo '<input type="hidden" name="action" value="sw_debug_pref"/>'
       . '<label><input type="checkbox" name="show" value="1" '.checked($show_debug, true, false).'> '
       . 'Show Debug on order screen</label> '
       . '<button class="button">Save</button>'
       . '</form></div>';
  }
}
