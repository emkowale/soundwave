<?php
/*
 * File: includes/util/sw-payload-helpers.php
 * Plugin: Soundwave
 * Desc: Helpers for payloads; company-name now prefers PRODUCT meta.
 * Version: 1.2.6
 */
defined('ABSPATH') || exit;

function swp_num($n){ return $n!=='' ? wc_format_decimal($n,2) : '0.00'; }

function swp_addr(WC_Order $o,$which){
  $f="get_{$which}_first_name"; if(!method_exists($o,$f)) return [];
  return [
    'first_name'=>(string)$o->{"get_{$which}_first_name"}(),
    'last_name' =>(string)$o->{"get_{$which}_last_name"}(),
    'company'   =>(string)$o->{"get_{$which}_company"}(),
    'address_1' =>(string)$o->{"get_{$which}_address_1"}(),
    'address_2' =>(string)$o->{"get_{$which}_address_2"}(),
    'city'      =>(string)$o->{"get_{$which}_city"}(),
    'state'     =>(string)$o->{"get_{$which}_state"}(),
    'postcode'  =>(string)$o->{"get_{$which}_postcode"}(),
    'country'   =>(string)$o->{"get_{$which}_country"}(),
    'email'     =>$which==='billing'?(string)$o->get_billing_email():'',
    'phone'     =>$which==='billing'?(string)$o->get_billing_phone():'',
  ];
}

/* --- company-name helpers --- */
function swp_get_company_from_product($parent,$variation=null){
  $keys=['company-name','company_name','Company Name','_company-name'];
  $pid=$parent?$parent->get_id():0;
  if($pid){ foreach($keys as $k){ $v=get_post_meta($pid,$k,true); if($v!==''){ return (string)$v; } } }
  if($variation && $variation->is_type('variation')){
    foreach($keys as $k){ $v=get_post_meta($variation->get_id(),$k,true); if($v!==''){ return (string)$v; } }
  }
  return '';
}

/* Order-level: prefer PRODUCT meta from first line item → then order meta → then billing company */
function swp_get_company(WC_Order $o){
  foreach($o->get_items('line_item') as $li){
    $prod=$li->get_product();
    $p=$prod&&$prod->is_type('variation')&&$prod->get_parent_id()?wc_get_product($prod->get_parent_id()):$prod;
    $v=swp_get_company_from_product($p,$prod); if($v!=='') return $v;
  }
  foreach(['company-name','_company-name','Company Name'] as $k){
    $v=$o->get_meta($k,true); if($v!=='') return (string)$v;
  }
  $v=$o->get_billing_company(); return $v?(string)$v:'';
}

/* --- original-art + attrs --- */
function swp_get_oart_from_product($parent,$variation=null){
  $pid=$parent?$parent->get_id():0;
  if($pid){ foreach(['original-art','original_art','_original_art'] as $k){
    $v=get_post_meta($pid,$k,true); if($v!=='') return (string)$v; } }
  if($variation && $variation->is_type('variation')){
    foreach(['original-art','original_art','_original_art'] as $k){
      $v=get_post_meta($variation->get_id(),$k,true); if($v!=='') return (string)$v; }
  }
  return '';
}

function swp_get_oart_from_order(WC_Order $o){
  foreach(['original-art','original_art','_original_art'] as $k){
    $v=$o->get_meta($k,true); if($v!=='') return (string)$v;
  }
  foreach($o->get_items('line_item') as $li){
    $prod=$li->get_product();
    $p=$prod&&$prod->is_type('variation')&&$prod->get_parent_id()?wc_get_product($prod->get_parent_id()):$prod;
    $v=swp_get_oart_from_product($p,$prod); if($v!=='') return $v;
  }
  return '';
}

function swp_attr($product,$keys){
  foreach($keys as $k){
    $v=''; if($product){ $v=$product->get_attribute($k);
      if(!$v && $product->get_id()) $v=get_post_meta($product->get_id(),$k,true); }
    $v=is_string($v)?trim($v):$v; if($v!=='') return $v;
  }
  return '';
}

function swp_attrs($p){
  return [
    'Color'          => swp_attr($p,['pa_color','Color','color','attribute_pa_color','attribute_color']),
    'Size'           => swp_attr($p,['pa_size','Size','size','attribute_pa_size','attribute_size']),
    'Print Location' => swp_attr($p,['pa_print_location','print_location','Print Location','attribute_pa_print_location','attribute_print_location']),
    'Quality'        => swp_attr($p,['pa_quality','quality','Quality','attribute_pa_quality','attribute_quality']),
  ];
}
