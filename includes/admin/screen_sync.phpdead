<?php
/*
 * File: /includes/admin/screen_sync.php
 * Purpose: Soundwave Dashboard — Missing/Synced + Sync Now (hard-coded creds, strict check).
 */
if (!defined('ABSPATH')) exit;


if (!function_exists('soundwave_render_sync_screen')) {
  function soundwave_render_sync_screen(){ soundwave_screen_sync(); }
}

if (!function_exists('soundwave_screen_sync')) {
function soundwave_screen_sync() {
  if (!current_user_can('manage_woocommerce')) wp_die('Insufficient permissions.');

  // Hard-coded destination creds
  $cfg = dirname(__DIR__).'/util/config.php'; if (file_exists($cfg)) require_once $cfg;
  $base = defined('SW_DEST_BASE')?SW_DEST_BASE:''; $ck = defined('SW_DEST_CK')?SW_DEST_CK:''; $cs = defined('SW_DEST_CS')?SW_DEST_CS:'';
  $cfg_ok = ($base && $ck && $cs);

  echo '<div class="wrap"><h1>Soundwave — Manual Order Sync</h1>';
  echo '<div class="notice '.($cfg_ok?'notice-success':'notice-error').'"><p><strong>'
      .($cfg_ok?'Connected':'Destination credentials missing').'</strong> • Destination: '
      .esc_html($base?:'(unset)').'</p></div>';
  if (!$cfg_ok){ echo '</div>'; return; }

  // Debug banner (modular)
  //require_once __DIR__ . '/debug_banner.php';
  //sw_render_debug_banner($base);

  /*
  if (!class_exists('Soundwave_Dest_Client')) require_once dirname(__DIR__).'/class-soundwave-dest-client.php';
  $client = new Soundwave_Dest_Client($base,$ck,$cs);

  $q = wc_get_orders(['limit'=>20,'paginate'=>true,'paged'=>max(1,intval($_GET['paged']??1)),
    'orderby'=>'date','order'=>'DESC','type'=>'shop_order','return'=>'objects']);
  $orders = $q->orders ?? [];

  echo '<table class="widefat striped"><thead><tr><th>Order</th><th>Date</th><th>Total</th><th>Status</th><th>Action</th></tr></thead><tbody>';

  foreach ($orders as $o) {
    $id=$o->get_id(); $num=$o->get_order_number();
    $when=$o->get_date_created()? $o->get_date_created()->date_i18n(get_option('date_format').' '.get_option('time_format')):'—';
    $tot=$o->get_formatted_order_total();

    // Flash once after redirect
    $just=(bool)get_transient('sw_synced_'.$id); if($just) delete_transient('sw_synced_'.$id);

    // Live existence (exact id; if deleted, drop id; else strict key/number and learn id)
    $dest_id=(int)get_post_meta($id,'_sw_dest_order_id',true);
    $exists_live=false;
    if ($dest_id>0){
      $exists_live = $client->exists_by_id($dest_id);
      if(!$exists_live) delete_post_meta($id,'_sw_dest_order_id');
    }
    if (!$exists_live){
      list($exists_live,$found_id) = $client->exists_by_key_or_number_strict($o->get_order_key(), $num);
      if ($exists_live && $found_id>0) update_post_meta($id,'_sw_dest_order_id',(int)$found_id);
    }

    $exists = $exists_live || $just;

    $status = $exists ? '<span style="color:#2e7d32;">&#10003; Synced</span>'
                      : '<span style="color:#b71c1c;">&#9888; Missing</span>';

    $edit = admin_url("post.php?post={$id}&action=edit");
    $nonce = wp_create_nonce('soundwave_manual_sync_'.$id);
    $post_url = admin_url('admin-post.php');
    $action = $exists
      ? '<button class="button" disabled>Synced</button>'
      : '<form method="post" action="'.esc_url($post_url).'" style="display:inline">'
        .'<input type="hidden" name="action" value="soundwave_manual_sync"/>'
        .'<input type="hidden" name="order_id" value="'.esc_attr($id).'"/>'
        .'<input type="hidden" name="_wpnonce" value="'.esc_attr($nonce).'"/>'
        .'<button class="button button-primary">Sync Now</button></form>';

    echo '<tr><td><a href="'.esc_url($edit).'">#'.esc_html($num).'</a></td>'
       . '<td>'.esc_html($when).'</td>'
       . '<td>'.wp_kses_post($tot).'</td>'
       . '<td>'.$status.'</td>'
       . '<td>'.$action.' <a class="button" href="'.esc_url($edit).'">View</a></td></tr>';
  }
  if (empty($orders)) echo '<tr><td colspan="5">No orders found.</td></tr>';
  echo '</tbody></table></div>';
  */
}}
