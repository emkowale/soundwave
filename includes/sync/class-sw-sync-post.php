<?php
/*
 * File: includes/sync/class-sw-sync-post.php
 * Desc: Priority-1 sync; attrs from ORDER item; product meta for image/art/company.
 * Ver: 1.2.9
 */
defined('ABSPATH') || exit;

class SW_Sync_Post {
  public static function init(){ add_filter('soundwave/run_sync_for_order',[__CLASS__,'run'],1,3); }
  public static function run($res,$oid,$ctx=[]){
    if($res instanceof WP_Error||$res===true||is_array($res)) return $res;
    $o=wc_get_order($oid); if(!$o) return new WP_Error('soundwave_no_order','Order not found.');
    if(!defined('SW_HUB_ENDPOINT')||!defined('SW_HUB_KEY')||!defined('SW_HUB_SECRET'))
      return new WP_Error('soundwave_missing_env','Destination credentials are not defined.');
    $p=self::payload($o,!empty($ctx['debug']));
    $u=add_query_arg(['consumer_key'=>SW_HUB_KEY,'consumer_secret'=>SW_HUB_SECRET],SW_HUB_ENDPOINT);
    $r=wp_remote_post($u,['method'=>'POST','timeout'=>45,'headers'=>['Content-Type'=>'application/json'],'body'=>wp_json_encode($p,JSON_UNESCAPED_SLASHES)]);
    if(is_wp_error($r)) return new WP_Error('soundwave_http_error',$r->get_error_message());
    $c=(int)wp_remote_retrieve_response_code($r); $b=wp_remote_retrieve_body($r);
    if($c!==201&&$c!==200) return new WP_Error('soundwave_remote_error','HTTP '.$c.($b?': '.$b:''));
    $j=json_decode($b,true); if(!is_array($j)) return new WP_Error('soundwave_parse_error','Bad JSON.');
    $id=''; foreach(['id','order_id','number'] as $k){ if(!empty($j[$k])){$id=(string)$j[$k];break;} }
    if(!$id) return new WP_Error('soundwave_no_id','No remote ID.');
    update_post_meta($oid,'_soundwave_dest_order_id',$id);
    return ['remote_id'=>$id];
  }

  protected static function payload(WC_Order $o,$dbg){
    $bill=swp_addr($o,'billing'); $ship=swp_addr($o,'shipping'); if(!$ship&&$bill) $ship=$bill;
    return [
      'status'=>'processing','set_paid'=>false,'payment_method'=>'soundwave',
      'customer_note'=>(string)$o->get_customer_note(),
      'billing'=>$bill,'shipping'=>$ship,
      'line_items'=>self::lines($o),
      'shipping_lines'=>self::ship_lines($o),
      'fee_lines'=>self::fee_lines($o),'coupon_lines'=>self::coupon_lines($o),
      'meta_data'=>self::order_meta($o,$dbg),
      'total'=>swp_num($o->get_total()),'total_tax'=>swp_num($o->get_total_tax()),
      'shipping_total'=>swp_num($o->get_shipping_total()),'shipping_tax'=>swp_num($o->get_shipping_tax()),
      'discount_total'=>swp_num($o->get_discount_total()),'discount_tax'=>swp_num($o->get_discount_tax()),
    ];
  }

  /* Order-level meta: NO company-name, NO original-art (prevents Custom Fields) */
  protected static function order_meta(WC_Order $o,$dbg){
    $m=[]; // tax lock + origin breadcrumbs only
    $ok=defined('SW_META_ORIGIN_ORDER')?SW_META_ORIGIN_ORDER:'_origin_order_id';
    $os=defined('SW_META_ORIGIN')?SW_META_ORIGIN:'_order_origin';
    $oc=defined('SW_META_ORIGIN_CUSTOMER')?SW_META_ORIGIN_CUSTOMER:'_origin_customer';
    $m[]=['key'=>'soundwave_tax_locked','value'=>1];
    $m[]=['key'=>$ok,'value'=>(string)$o->get_id()];
    $m[]=['key'=>$os,'value'=>home_url('/')];
    $m[]=['key'=>$oc,'value'=>(string)$o->get_billing_email()];
    if($dbg) $m[]=['key'=>'soundwave_debug','value'=>1];
    return $m;
  }

  /* --- attrs from ORDER; company/original-art/image from PRODUCT --- */
  protected static function term_name($slug,$tax){ if(!$slug||!$tax||!taxonomy_exists($tax)) return $slug; $t=get_term_by('slug',$slug,$tax); return $t&&$t->name?$t->name:$slug; }
  protected static function pick_one($s){ if(!$s) return ''; foreach(['|',','] as $d){ if(strpos($s,$d)!==false){ $s=trim(explode($d,$s)[0]); break; } } return $s; }
  protected static function item_attr($it,$p,$tax,$alts){
    foreach($alts as $k){ $v=$it->get_meta($k,true); if($v!=='') return self::term_name($v,$tax); }
    if($p && $p->is_type('variation')){ $v=$p->get_attribute($tax); if($v!=='') return self::term_name($v,$tax); }
    if($p){ $def=method_exists($p,'get_default_attributes')?$p->get_default_attributes():[]; if(!empty($def[$tax])) return self::term_name($def[$tax],$tax);
      $v=$p->get_attribute($tax); if($v!=='') return self::pick_one($v); }
    return '';
  }
  protected static function attrs_from_item($it,$p){
    return [
      'Color'          => self::item_attr($it,$p,'pa_color',['pa_color','attribute_pa_color','Color','color','attribute_color']),
      'Size'           => self::item_attr($it,$p,'pa_size',['pa_size','attribute_pa_size','Size','size','attribute_size']),
      'Print Location' => self::item_attr($it,$p,'pa_print_location',['pa_print_location','attribute_pa_print_location','Print Location','print_location','attribute_print_location']),
      'Quality'        => self::item_attr($it,$p,'pa_quality',['pa_quality','attribute_pa_quality','Quality','quality','attribute_quality']),
    ];
  }

  protected static function lines(WC_Order $o){
    $out=[];
    foreach($o->get_items('line_item') as $it){
      $prod=$it->get_product();
      $p=$prod&&$prod->is_type('variation')&&$prod->get_parent_id()?wc_get_product($prod->get_parent_id()):$prod;
      $meta=[]; foreach(self::attrs_from_item($it,$p) as $k=>$v){ if($v!=='') $meta[]=['key'=>$k,'value'=>$v]; }
      $img=''; if($p&&$p->get_id()){ $img=get_post_meta($p->get_id(),'product_image_full',true);
        if(!$img){ $iid=$p->get_image_id(); if(!$iid){ $g=$p->get_gallery_image_ids(); $iid=$g?reset($g):0; } if($iid) $img=wp_get_attachment_image_url($iid,'full'); } }
      if($img!=='') $meta[]=['key'=>'product_image_full','value'=>$img];
      $cn=swp_get_company_from_product($p,$prod); if($cn==='') $cn=swp_get_company($o);
      if($cn!=='') $meta[]=['key'=>'company-name','value'=>$cn];       // after image
      $oa=swp_get_oart_from_product($p,$prod); if($oa!=='') $meta[]=['key'=>'original-art','value'=>$oa];
      $out[]=['name'=>$it->get_name(),'quantity'=>(int)$it->get_quantity(),'sku'=>$prod?(string)$prod->get_sku():'',
              'subtotal'=>swp_num($it->get_subtotal()),'subtotal_tax'=>swp_num($it->get_subtotal_tax()),
              'total'=>swp_num($it->get_total()),'total_tax'=>swp_num($it->get_total_tax()),'meta_data'=>$meta];
    } return $out;
  }

  protected static function ship_lines(WC_Order $o){ $r=[]; foreach($o->get_items('shipping') as $s){ $mid=$s->get_method_id(); $iid=$s->get_instance_id(); if(!$mid) continue; $r[]=['method_id'=>$mid.($iid?":$iid":''),'method_title'=>(string)$s->get_name(),'total'=>swp_num($s->get_total()),'total_tax'=>swp_num($s->get_total_tax())]; } return $r; }
  protected static function fee_lines(WC_Order $o){ $r=[]; foreach($o->get_items('fee') as $f){ $r[]=['name'=>(string)$f->get_name(),'total'=>swp_num($f->get_total()),'total_tax'=>swp_num($f->get_total_tax())]; } return $r; }
  protected static function coupon_lines(WC_Order $o){ $r=[]; foreach($o->get_items('coupon') as $c){ $r[]=['code'=>(string)$c->get_code(),'amount'=>swp_num(abs($c->get_discount()))]; } return $r; }
}
SW_Sync_Post::init();
