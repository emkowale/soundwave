<?php
/*
 * File: includes/bridges/class-sw-sync-interceptor.php
 * Plugin: Soundwave
 * Description: Priority 9 interceptor that performs the outbound sync (adds company-name + original-art).
 * Author: Eric Kowalewski
 * Version: 1.2.2
 * Last Updated: 2025-10-06 16:55 EDT
 */
defined('ABSPATH') || exit;

class SW_Sync_Interceptor {
  public static function init(){ add_filter('soundwave/run_sync_for_order',[__CLASS__,'run'],9,3); }

  public static function run($result,$order_id,$ctx=[]){
    if($result instanceof WP_Error || $result===true || is_array($result)) return $result;
    $o=wc_get_order($order_id); if(!$o) return new WP_Error('soundwave_no_order','Order not found.');
    if(defined('SW_SKIP_ON_SUCCESS') && SW_SKIP_ON_SUCCESS && get_post_meta($order_id,'_soundwave_synced',true)==='yes') return true;
    if(!defined('SW_HUB_ENDPOINT')||!defined('SW_HUB_KEY')||!defined('SW_HUB_SECRET'))
      return new WP_Error('soundwave_missing_env','Destination credentials are not defined.');

    $payload=self::build_payload($o,$ctx);
    if(defined('SW_META_DEBUG_JSON')) update_post_meta($order_id,SW_META_DEBUG_JSON,wp_json_encode($payload,JSON_UNESCAPED_SLASHES));
    if(defined('SW_META_LAST_AT')) update_post_meta($order_id,SW_META_LAST_AT,current_time('mysql'));

    $url=add_query_arg(['consumer_key'=>SW_HUB_KEY,'consumer_secret'=>SW_HUB_SECRET],SW_HUB_ENDPOINT);
    $resp=wp_remote_post($url,['method'=>'POST','timeout'=>45,'headers'=>['Content-Type'=>'application/json'],'body'=>wp_json_encode($payload,JSON_UNESCAPED_SLASHES)]);
    if(is_wp_error($resp)){ self::log_http($order_id,0,$resp->get_error_message()); return new WP_Error('soundwave_http_error',$resp->get_error_message()); }

    $code=(int)wp_remote_retrieve_response_code($resp); $body=wp_remote_retrieve_body($resp); self::log_http($order_id,$code,$body);
    if($code!==201 && $code!==200) return new WP_Error('soundwave_remote_error','Remote API error (HTTP '.$code.')'.($body?': '.self::trunc($body):''));
    $j=json_decode($body,true); if(!is_array($j)) return new WP_Error('soundwave_parse_error','Remote response was not valid JSON.');
    $rid=''; foreach(['id','order_id','number'] as $k){ if(!empty($j[$k])){ $rid=(string)$j[$k]; break; } }
    if(!$rid) return new WP_Error('soundwave_no_id','Remote order created but no ID returned.');
    update_post_meta($order_id,'_soundwave_dest_order_id',$rid);
    return ['remote_id'=>$rid];
  }

  protected static function build_payload(WC_Order $o,$ctx){
    $bill=swp_addr($o,'billing'); $ship=swp_addr($o,'shipping'); if(!$ship && $bill) $ship=$bill;
    $meta=self::order_meta($o); $lines=self::lines($o);
    return [
      'status'=>'processing','set_paid'=>false,'payment_method'=>'soundwave',
      'customer_note'=>(string)$o->get_customer_note(),'billing'=>$bill,'shipping'=>$ship,
      'line_items'=>$lines,'shipping_lines'=>self::ship_lines($o),'fee_lines'=>self::fee_lines($o),'coupon_lines'=>self::coupon_lines($o),
      'meta_data'=>$meta,'total'=>swp_num($o->get_total()),'total_tax'=>swp_num($o->get_total_tax()),
      'shipping_total'=>swp_num($o->get_shipping_total()),'shipping_tax'=>swp_num($o->get_shipping_tax()),
      'discount_total'=>swp_num($o->get_discount_total()),'discount_tax'=>swp_num($o->get_discount_tax()),
    ] + (!empty($ctx['debug'])?['meta_data'=>array_merge($meta,[['key'=>'soundwave_debug','value'=>1]])]:[]);
  }

  protected static function order_meta(WC_Order $o){
    $m=[]; $oart=swp_get_oart_from_order($o); if($oart!=='') $m[]=['key'=>'original-art','value'=>$oart];
    $comp=swp_get_company($o); if($comp!=='') $m[]=['key'=>'company-name','value'=>$comp];
    $m[]=['key'=>'soundwave_tax_locked','value'=>1];
    $ok=defined('SW_META_ORIGIN_ORDER')?SW_META_ORIGIN_ORDER:'_origin_order_id';
    $os=defined('SW_META_ORIGIN')?SW_META_ORIGIN:'_order_origin';
    $oc=defined('SW_META_ORIGIN_CUSTOMER')?SW_META_ORIGIN_CUSTOMER:'_origin_customer';
    $m[]=['key'=>$ok,'value'=>(string)$o->get_id()];
    $m[]=['key'=>$os,'value'=>home_url('/')];
    $m[]=['key'=>$oc,'value'=>(string)$o->get_billing_email()];
    return $m;
  }

  protected static function lines(WC_Order $o){
    $out=[]; foreach($o->get_items('line_item') as $it){
      $prod=$it->get_product(); $p=$prod&&$prod->is_type('variation')&&$prod->get_parent_id()?wc_get_product($prod->get_parent_id()):$prod;
      $meta=[]; foreach(swp_attrs($p) as $k=>$v){ if($v!=='') $meta[]=['key'=>$k,'value'=>$v]; }
      $c=swp_get_company($o); if($c!=='') $meta[]=['key'=>'company-name','value'=>$c];
      $oa=swp_get_oart_from_product($p,$prod); if($oa!=='') $meta[]=['key'=>'original-art','value'=>$oa];
      $out[]=['name'=>$it->get_name(),'quantity'=>(int)$it->get_quantity(),'sku'=>$prod?(string)$prod->get_sku():'',
              'subtotal'=>swp_num($it->get_subtotal()),'subtotal_tax'=>swp_num($it->get_subtotal_tax()),
              'total'=>swp_num($it->get_total()),'total_tax'=>swp_num($it->get_total_tax()),'meta_data'=>$meta];
    } return $out;
  }

  protected static function ship_lines(WC_Order $o){
    $out=[]; foreach($o->get_items('shipping') as $s){ $out[]=['method_title'=>(string)$s->get_name(),'total'=>swp_num($s->get_total()),'total_tax'=>swp_num($s->get_total_tax())]; }
    if(!$out && (float)$o->get_shipping_total()>0) $out[]=['method_title'=>'Shipping','total'=>swp_num($o->get_shipping_total()),'total_tax'=>swp_num($o->get_shipping_tax())];
    return $out;
  }

  protected static function fee_lines(WC_Order $o){ $out=[]; foreach($o->get_items('fee') as $f){ $out[]=['name'=>(string)$f->get_name(),'total'=>swp_num($f->get_total()),'total_tax'=>swp_num($f->get_total_tax())]; } return $out; }
  protected static function coupon_lines(WC_Order $o){ $out=[]; foreach($o->get_items('coupon') as $c){ $out[]=['code'=>(string)$c->get_code(),'amount'=>swp_num(abs($c->get_discount()))]; } return $out; }
  protected static function log_http($oid,$code,$body){ if(defined('SW_META_HTTP_CODE')) update_post_meta($oid,SW_META_HTTP_CODE,(string)$code); if(defined('SW_META_HTTP_BODY')) update_post_meta($oid,SW_META_HTTP_BODY,self::trunc($body)); }
  protected static function trunc($s,$n=2000){ $s=is_string($s)?$s:(string)$s; return (strlen($s)>$n)?substr($s,0,$n).'…':$s; }
}
SW_Sync_Interceptor::init();
