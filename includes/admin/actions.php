<?php
defined('ABSPATH') || exit;

function sw_handle_manual_sync() {
    if (empty($_POST['sw_manual_sync_submit'])) return;
    check_admin_referer('sw_manual_sync');
    $order_id = isset($_POST['sw_sync_order_id']) ? intval($_POST['sw_sync_order_id']) : 0;
    if ($order_id > 0) {
        soundwave_sync_order_to_beartraxs($order_id, true);
        wp_safe_redirect(add_query_arg(['page'=>'soundwave-sync','sw_synced'=>$order_id], admin_url('admin.php')));
        exit;
    }
}
add_action('admin_init', 'sw_handle_manual_sync');
// Bridge: manual sync action -> dispatcher
add_action('soundwave_manual_sync_order', function($order_id){
    if (!function_exists('soundwave_sync_order_to_beartraxs')) {
        $file = dirname(__DIR__).'/sync/dispatcher.php'; // includes/sync/dispatcher.php
        if (file_exists($file)) require_once $file;
    }
    if (function_exists('soundwave_sync_order_to_beartraxs')) {
        soundwave_sync_order_to_beartraxs((int)$order_id, true); // force resend
    }
}, 10, 1);

// Admin-post handler for Sync Now on Dashboard
add_action('admin_post_soundwave_manual_sync', function () {
  if ( ! current_user_can('manage_woocommerce') ) wp_die('Insufficient permissions.');
  $id = intval($_POST['order_id'] ?? 0);
  if ( ! $id || ! wp_verify_nonce($_POST['_wpnonce'] ?? '', 'soundwave_manual_sync_'.$id) ) wp_die('Bad request.');

  // Push via bridge/dispatcher
  do_action('soundwave_manual_sync_order', $id);

  // Mark as "just synced" for immediate UI feedback (10 minutes)
  set_transient('sw_synced_'.$id, 1, 10 * MINUTE_IN_SECONDS);

  wp_safe_redirect( admin_url('admin.php?page=soundwave-sync&synced='.$id) );
  exit;
});


// Save Destination settings from Dashboard (no separate Settings page)
add_action('admin_post_soundwave_save_dest', function () {
  if ( ! current_user_can('manage_woocommerce') ) wp_die('Insufficient permissions.');
  check_admin_referer('soundwave_save_dest');

  $base = isset($_POST['soundwave_dest_base']) ? esc_url_raw($_POST['soundwave_dest_base']) : '';
  $ck   = isset($_POST['soundwave_dest_ck'])   ? sanitize_text_field($_POST['soundwave_dest_ck'])   : '';
  $cs   = isset($_POST['soundwave_dest_cs'])   ? sanitize_text_field($_POST['soundwave_dest_cs'])   : '';

  update_option('soundwave_dest_base', $base);
  update_option('soundwave_dest_ck', $ck);
  update_option('soundwave_dest_cs', $cs);

  wp_safe_redirect( admin_url('admin.php?page=soundwave-sync&saved=1') );
  exit;
});

// AJAX: live recheck a single order's existence on destination
add_action('wp_ajax_sw_recheck_order', function(){
  if (!current_user_can('manage_woocommerce')) wp_send_json_error(['msg'=>'perm']);
  $id = (int)($_POST['order_id'] ?? 0);
  check_ajax_referer('sw_recheck_'.$id, 'nonce');
  if ($id<=0) wp_send_json_error(['msg'=>'bad id']);

  // Load creds + client
  $cfg = dirname(__DIR__) . '/util/config.php';
  if (file_exists($cfg)) require_once $cfg;
  $base = defined('SW_DEST_BASE') ? SW_DEST_BASE : '';
  $ck   = defined('SW_DEST_CK')   ? SW_DEST_CK   : '';
  $cs   = defined('SW_DEST_CS')   ? SW_DEST_CS   : '';
  if (!$base||!$ck||!$cs) wp_send_json_error(['msg'=>'cfg']);

  if (!class_exists('Soundwave_Dest_Client')) {
    require_once dirname(__DIR__) . '/class-soundwave-dest-client.php';
  }
  $c = new Soundwave_Dest_Client($base,$ck,$cs);

  $dest_id = (int)get_post_meta($id,'_sw_dest_order_id',true);
  $exists = $dest_id
    ? $c->exists_by_id($dest_id)
    : $c->exists_by_key_or_number(
        get_post_meta($id,'_order_key',true),
        wc_get_order($id)->get_order_number()
      );

  // Clear flash if recheck says missing
  if (!$exists) delete_transient('sw_synced_'.$id);

  wp_send_json_success(['exists'=>$exists]);
});

// Save "Show Debug on order screen" from the Dashboard banner
add_action('admin_post_sw_debug_pref', function () {
  if ( ! current_user_can('manage_woocommerce') ) wp_die('Insufficient permissions.');
  check_admin_referer('sw_debug_pref');

  $val = isset($_POST['show']) ? '1' : '0';
  // Keep both keys in sync for legacy code
  update_option('sw_debug_on_order_screen', $val);
  update_option('sw_show_order_debug', $val);

  wp_safe_redirect( admin_url('admin.php?page=soundwave-sync&saved=1') );
  exit;
});
