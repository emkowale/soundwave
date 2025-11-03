<?php
/*
 * File: includes/sync/payload_enrich_lineitem.php
 * Purpose: Enrich each line item with variation image + parent CF, without duplicate SKU rows.
 */
if (!defined('ABSPATH')) exit;
require_once __DIR__ . '/payload_helpers.php';

if (!function_exists('swp_enrich_line_item')) {
function swp_enrich_line_item(WC_Order_Item_Product $item, array $base) {

    $metaMap = function_exists('swv_item_meta_map')
        ? swv_item_meta_map($item)
        : swp_build_map_fallback($item);

    // Pull selected fields from the PARENT product custom fields (verbatim keys)
    $parent_id = 0;
    if ($p = $item->get_product()) {
        $parent_id = ($p->is_type('variation') && method_exists($p,'get_parent_id'))
            ? (int)$p->get_parent_id()
            : (int)$p->get_id();
    }
    $parent_fields = [];
    if ($parent_id) {
        foreach (['Company Name','Vendor Code','Production','Site Slug','Print Location'] as $k) {
            $v = get_post_meta($parent_id, $k, true);
            if ($v !== '' && $v !== null) $parent_fields[$k] = (string)$v;
        }
    }

    // Variation Image URL (mockup)
    $variation_image_url = '';
    if ($vid = (int)$item->get_variation_id()) {
        $v = wc_get_product($vid);
        if ($v instanceof WC_Product_Variation) {
            $img = (int)$v->get_image_id();
            if ($img) $variation_image_url = wp_get_attachment_url($img);
        }
    }

    // Original Art by location (from any meta that starts with "Original Art ")
    $original_art_pairs = [];
    foreach ($metaMap as $k=>$v) {
        if (preg_match('/^Original Art\s+/i',$k) && $v!=='') {
            $label = trim(preg_replace('/^Original Art\s+/i','',$k));
            $original_art_pairs[] = ['key'=>"Original Art — {$label}", 'value'=>(string)$v];
        }
    }

    // WC line meta (skip Original Art* and ANY SKU-like key)
    $wc_all = [];
    foreach ($item->get_meta_data() as $m) {
        $d = $m->get_data(); $k = (string)($d['key'] ?? ''); $v = $d['value'] ?? '';
        if ($k === '') continue;
        if (preg_match('/^Original Art/i',$k)) continue;

        // Normalize key to letters only and check for "sku" substring (kills: SKU, _sku, Variation SKU, resolved_sku, etc.)
        $norm = strtolower(preg_replace('/[^a-z]/', '', $k));
        if (strpos($norm, 'sku') !== false) continue;

        $wc_all[] = ['key'=>$k, 'value'=> is_scalar($v) ? (string)$v : wp_json_encode($v)];
    }

    // Explicit minimal fields for downstream UI + ops
    $get_first = fn($map,$c)=>swp_get_first($map,$c);
    $exp = [
        ['key'=>'Color',               'value'=>$get_first($metaMap,['Color','color','pa_color','attribute_pa_color'])],
        ['key'=>'Size',                'value'=>$get_first($metaMap,['Size','size','pa_size','attribute_pa_size'])],
        ['key'=>'Variation Image URL', 'value'=>$variation_image_url],
    ];
    foreach ($parent_fields as $k=>$v) { $exp[] = ['key'=>$k, 'value'=>$v]; }
    $exp = array_values(array_filter($exp, fn($p)=>($p['value']??'')!=='' ));

    $base['meta_data'] = array_merge($base['meta_data']??[], $exp, $original_art_pairs, $wc_all);
    return $base;
}}
