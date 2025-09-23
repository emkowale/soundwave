<?php
/*
 * File: includes/class-soundwave-admin-manual-sync.php
 * Plugin: Soundwave
 * Desc: Lists local orders missing on destination with “Sync Now”.
 */
if(!defined('ABSPATH'))exit;
class Soundwave_Admin_Manual_Sync{
  public static function init(){
    add_action('admin_menu',[__CLASS__,'menu']);
    add_action('admin_post_soundwave_manual_sync',[__CLASS__,'handle_sync']);
  }
  public static function menu(){
    add_submenu_page('soundwave','Manual Order Sync','Manual Order Sync','manage_woocommerce','soundwave-manual-sync',[__CLASS__,'render']);
  }
  private static function client(){
    $base=get_option('soundwave_dest_base'); $ck=get_option('soundwave_dest_ck'); $cs=get_option('soundwave_dest_cs');
    if(!$base||!$ck||!$cs) return new WP_Error('cfg','Destination API not configured (base/ck/cs).');
    if(!class_exists('Soundwave_Dest_Client')) require_once plugin_dir_path(__FILE__).'class-soundwave-dest-client.php';
    return new Soundwave_Dest_Client($base,$ck,$cs);
  }
  public static function render(){
    if(!current_user_can('manage_woocommerce')) wp_die('Insufficient permissions.');
    $client=self::client(); echo '<div class="wrap"><h1>Soundwave — Manual Order Sync</h1>';
    if(is_wp_error($client)){ echo '<div class="notice notice-error"><p>'.esc_html($client->get_error_message()).'</p></div></div>'; return; }
    $paged=max(1,intval($_GET['paged']??1)); $per=20;
    $q=wc_get_orders(['limit'=>$per,'paginate'=>true,'paged'=>$paged,'orderby'=>'date','order'=>'DESC','type'=>'shop_order','return'=>'objects']);
    $orders=$q->orders??[]; $missing=[];
    foreach($orders as $o){ $ok=$o->get_order_key(); $on=$o->get_order_number(); $exists=$client->order_exists($ok,$on); if(!$exists)$missing[]=$o; }
    echo '<p>Orders below were <strong>NOT found</strong> on thebeartraxs.com. Click <em>Sync Now</em> to push.</p>';
    if(empty($missing)){ echo '<div class="notice notice-success"><p>Nothing to sync — all recent orders exist on destination.</p></div></div>'; return; }
    echo '<table class="widefat striped"><thead><tr><th>Order</th><th>Date</th><th>Customer</th><th>Total</th><th>Action</th></tr></thead><tbody>';
    foreach($missing as $o){
      $id=$o->get_id(); $n=$o->get_order_number(); $link=admin_url("post.php?post=$id&action=edit");
      $when=$o->get_date_created()? $o->get_date_created()->date_i18n(get_option('date_format').' '.get_option('time_format')):'—';
      $cust=trim($o->get_formatted_billing_full_name())?:$o->get_billing_email(); $tot=$o->get_formatted_order_total();
      $nonce=wp_create_nonce('soundwave_manual_sync_'.$id); $action=admin_url('admin-post.php');
      echo '<tr><td><a href="'.esc_url($link).'">#'.esc_html($n).'</a></td><td>'.esc_html($when).'</td><td>'.esc_html($cust).'</td><td>'.wp_kses_post($tot).'</td><td>';
      echo '<form method="post" action="'.esc_url($action).'" style="display:inline"><input type="hidden" name="action" value="soundwave_manual_sync"/><input type="hidden" name="order_id" value="'.esc_attr($id).'"/><input type="hidden" name="_wpnonce" value="'.esc_attr($nonce).'"/><button class="button button-primary">Sync Now</button></form>';
      echo '</td></tr>';
    }
    echo '</tbody></table></div>';
  }
  public static function handle_sync(){
    if(!current_user_can('manage_woocommerce')) wp_die('Insufficient permissions.');
    $id=intval($_POST['order_id']??0); if(!$id||!wp_verify_nonce($_POST['_wpnonce']??'','soundwave_manual_sync_'.$id)) wp_die('Bad request.');
    if(!function_exists('soundwave_sync_order_to_beartraxs')){ $f=plugin_dir_path(__FILE__).'sync/dispatcher.php'; if(file_exists($f)) require_once $f; }
    if(function_exists('soundwave_sync_order_to_beartraxs')) soundwave_sync_order_to_beartraxs($id,true); else do_action('soundwave_manual_sync_order',$id);
    wp_safe_redirect(admin_url('admin.php?page=soundwave-manual-sync&synced='.$id)); exit;
  }
}
Soundwave_Admin_Manual_Sync::init();
