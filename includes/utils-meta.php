<?php
if ( ! defined('ABSPATH') ) exit;

if ( ! function_exists('soundwave_first_value_from_candidates') ) {
    function soundwave_first_value_from_candidates( array $map, array $candidates ) {
        foreach ($candidates as $k) {
            foreach ([$k, strtolower($k), strtoupper($k)] as $ck) {
                if (array_key_exists($ck, $map)) {
                    $val = $map[$ck];
                    if (is_array($val)) $val = reset($val);
                    if ($val !== '' && $val !== null) return $val;
                }
            }
        }
        return null;
    }
}

if ( ! function_exists('swv_item_meta_map') ) {
    function swv_item_meta_map( WC_Order_Item_Product $item ) {
        $map = [];

        foreach ($item->get_meta_data() as $m) {
            $d = $m->get_data(); if (empty($d['key'])) continue;
            $k = (string)$d['key']; $v = $d['value'];
            $map[$k] = is_scalar($v) ? $v : (is_array($v) ? wp_json_encode($v) : (string)$v);
        }

        $vid = (int) $item->get_variation_id();
        if ($vid > 0 && ($v = wc_get_product($vid)) instanceof WC_Product_Variation) {
            foreach ((array)$v->get_attributes() as $tax => $val) {
                $map['attribute_'.$tax] = $val;
                $map[$tax] = $val;
            }
            foreach ($v->get_meta_data() as $m) {
                $d = $m->get_data(); if (empty($d['key'])) continue;
                $k = (string)$d['key'];
                if (!isset($map[$k])) {
                    $val = $d['value'];
                    $map[$k] = is_scalar($val) ? $val : (is_array($val) ? wp_json_encode($val) : (string)$val);
                }
            }
        }

        if ($p = $item->get_product()) {
            foreach ($p->get_meta_data() as $m) {
                $d = $m->get_data(); if (empty($d['key'])) continue;
                $k = (string)$d['key'];
                if (!isset($map[$k])) {
                    $val = $d['value'];
                    $map[$k] = is_scalar($val) ? $val : (is_array($val) ? wp_json_encode($val) : (string)$val);
                }
            }
            if (method_exists($p,'get_parent_id')) {
                $parent_id = (int) $p->get_parent_id();
                if ($parent_id > 0 && ($parent = wc_get_product($parent_id))) {
                    foreach ($parent->get_meta_data() as $m) {
                        $d = $m->get_data(); if (empty($d['key'])) continue;
                        $k = (string)$d['key'];
                        if (!isset($map[$k])) {
                            $val = $d['value'];
                            $map[$k] = is_scalar($val) ? $val : (is_array($val) ? wp_json_encode($val) : (string)$val);
                        }
                    }
                }
            }
        }

        return $map;
    }
}
