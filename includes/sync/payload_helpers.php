<?php
/*
 * File: includes/sync/payload_helpers.php
 * Purpose: Shared helpers for payload composition.
 */
if (!defined('ABSPATH')) exit;

require_once __DIR__ . '/../utils.php'; // for soundwave_first_value_from_candidates()

if (!function_exists('swp_extract_billing')) {
function swp_extract_billing(WC_Order $o) {
    return [
        'first_name'=>$o->get_billing_first_name(),
        'last_name' =>$o->get_billing_last_name(),
        'company'   =>$o->get_billing_company(),
        'address_1' =>$o->get_billing_address_1(),
        'address_2' =>$o->get_billing_address_2(),
        'city'      =>$o->get_billing_city(),
        'state'     =>$o->get_billing_state(),
        'postcode'  =>$o->get_billing_postcode(),
        'country'   =>$o->get_billing_country(),
        'email'     =>$o->get_billing_email(),
        'phone'     =>$o->get_billing_phone(),
    ];
}}

if (!function_exists('swp_extract_shipping')) {
function swp_extract_shipping(WC_Order $o) {
    return [
        'first_name'=>$o->get_shipping_first_name(),
        'last_name' =>$o->get_shipping_last_name(),
        'company'   =>$o->get_shipping_company(),
        'address_1' =>$o->get_shipping_address_1(),
        'address_2' =>$o->get_shipping_address_2(),
        'city'      =>$o->get_shipping_city(),
        'state'     =>$o->get_shipping_state(),
        'postcode'  =>$o->get_shipping_postcode(),
        'country'   =>$o->get_shipping_country(),
    ];
}}

if (!function_exists('swp_build_map_fallback')) {
function swp_build_map_fallback(WC_Order_Item_Product $item) {
    $map = [];
    foreach ($item->get_meta_data() as $m) {
        $d = $m->get_data(); $k = (string)$d['key']; $v = $d['value'];
        $map[$k] = is_scalar($v)?$v:(is_array($v)?wp_json_encode($v):(string)$v);
    }
    if ($vid = (int)$item->get_variation_id()) {
        $v = wc_get_product($vid);
        if ($v instanceof WC_Product_Variation)
            foreach ($v->get_attributes() as $t=>$val) {
                $map['attribute_'.$t]=$val; $map[$t]=$val;
            }
    }
    if ($p = $item->get_product())
        foreach ($p->get_meta_data() as $m) {
            $d=$m->get_data(); $k=(string)$d['key'];
            if (!isset($map[$k])) {
                $v=$d['value'];
                $map[$k]=is_scalar($v)?$v:(is_array($v)?wp_json_encode($v):(string)$v);
            }
        }
    return $map;
}}

if (!function_exists('swp_get_first')) {
function swp_get_first(array $map,array $cands) {
    return function_exists('soundwave_first_value_from_candidates')
        ? soundwave_first_value_from_candidates($map,$cands)
        : array_reduce($cands,function($carry,$k)use($map){
            if($carry)return $carry;
            foreach([$k,strtolower($k),strtoupper($k)]as$ck)
                if(array_key_exists($ck,$map)){
                    $v=$map[$ck];return is_array($v)?reset($v):$v;
                }
            return null;
        });
}}
