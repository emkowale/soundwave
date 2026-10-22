<?php
/* Honor totals, keep per-line meta, and paint thumbnails per item (HPOS + classic). */
defined('ABSPATH') || exit;

/* Meta to keep per line item */
const SW_ITEM_META = ['Color','Size','Print Location','Quality','product_image_full','company-name','original-art'];

/* 1) Lock taxes if requested */
add_filter('woocommerce_rest_pre_insert_shop_order_object', function($order,$request,$creating){
  if(!$creating) return $order;
  foreach((array)$request->get_param('meta_data') as $m){
    if(($m['key']??'')==='soundwave_tax_locked' && intval($m['value']??0)===1){
      add_filter('woocommerce_disable_automatic_tax','__return_true',99); break;
    }
  }
  return $order;
},10,3);

/* Helper: stable signature so duplicate products map correctly */
if(!function_exists('sw_sig')){ function sw_sig($pid,$vid,$name){ return (int)$pid.'|'.(int)$vid.'|'.sanitize_title((string)$name); } }

/* 2) After create: map request lines → created items; copy allowlisted meta */
add_action('woocommerce_rest_insert_shop_order_object', function($order,$request,$creating){
  if(!$creating) return;
  $req=(array)$request->get_param('line_items'); if(!$req) return;
  $rsig=[]; $rmeta=[];
  foreach($req as $ri){
    $rsig[] = sw_sig($ri['product_id']??0,$ri['variation_id']??0,$ri['name']??'');
    $ml=[]; foreach((array)($ri['meta_data']??[]) as $m){ $k=$m['key']??''; if(in_array($k,SW_ITEM_META,true)) $ml[$k]=$m['value']??''; }
    $rmeta[]=$ml;
  }
  $created=array_values($order->get_items('line_item')); if(!$created) return;
  $cmap=[]; foreach($created as $i=>$it){ $cmap[sw_sig($it->get_product_id(),$it->get_variation_id(),$it->get_name())][]=$i; }
  $seen=[]; $n=min(count($created),count($rmeta));
  for($i=0;$i<$n;$i++){
    $sig=$rsig[$i]; $pos=$seen[$sig]=isset($seen[$sig])?$seen[$sig]+1:0;
    $idx=isset($cmap[$sig][$pos])?$cmap[$sig][$pos]:$i; $item=$created[$idx];
    foreach($rmeta[$i] as $k=>$v){ if($v!=='' && $v!==null) wc_update_order_item_meta($item->get_id(),$k,$v); }
  }
  $order->save();
},10,3);

/* 3) Per item: output a tiny <style> right next to the meta that paints the left thumb. */
add_action('woocommerce_after_order_itemmeta', function($item_id,$item){
  $url = wc_get_order_item_meta($item_id,'product_image_full',true);
  if(!$url) return;
  $u = esc_url_raw($url); $id = (int)$item_id;
  // Covers classic rows and HPOS React rows by targeting the row with this item id
  echo '<style id="sw-thumb-'.$id.'">'
     .'[data-order_item_id="'.$id.'"] .wc-order-item-thumbnail,'
     .'[data-order_item_id="'.$id.'"] .wc-order-item-thumbnail__image,'
     .'tr.item[data-order_item_id="'.$id.'"] td.thumb{'
     .'width:48px;height:48px;background:#fff url("'.$u.'") center/contain no-repeat !important;'
     .'border:1px solid #eee;}</style>';
}, 99, 2);
