<?php
if ( ! defined('ABSPATH') ) exit;

/*
 * Soundwave Validator — updated for discrete Original Art fields
 * Returns: ['ok'=>bool, 'errors'=>string[]]
 */
if ( ! function_exists('soundwave_validate_order_required_fields') ) {
function soundwave_validate_order_required_fields( WC_Order $order ): array {
    $errors = [];

    // Helper to pull first non-empty value from candidate keys
    $first = function(array $map, array $cands){
        if ( function_exists('soundwave_first_value_from_candidates') ) {
            $val = soundwave_first_value_from_candidates($map, $cands);
            return is_scalar($val) ? (string)$val : ($val === null ? '' : (string)$val);
        }
        foreach ($cands as $k) {
            foreach ([$k, strtolower($k), strtoupper($k)] as $ck) {
                if (array_key_exists($ck, $map)) {
                    $v = $map[$ck];
                    if (is_array($v)) $v = reset($v);
                    if ($v !== '' && $v !== null) return (string)$v;
                }
            }
        }
        return '';
    };

    // Canonical candidate maps
    $C = [
        'color'   => ['attribute_pa_color','pa_color','attribute_color','color','bb_color','variation_color'],
        'size'    => ['attribute_pa_size','pa_size','attribute_size','size','bb_size','variation_size'],
        'vendor'  => ['_vendor_code','vendor_code','Vendor Code','bb_vendor_code'],
        'company' => ['_company_name','company_name','Company Name'],
        'site'    => ['_site_slug','site_slug','Site Slug'],
        'prod'    => ['_production','production','Production'],
        'loc'     => ['_print_location','print_location','Print Location'],
    ];

    // Map of location substrings → required Original Art field name
    $ART_MAP = [
        'front'        => 'Original Art Front',
        'back'         => 'Original Art Back',
        'left sleeve'  => 'Original Art Left Sleeve',
        'right sleeve' => 'Original Art Right Sleeve',
        'left chest'   => 'Original Art Left Chest',
        'right chest'  => 'Original Art Right Chest',
    ];

    $i = 0;
    foreach ( $order->get_items('line_item') as $item ) {
        if ( ! ($item instanceof WC_Order_Item_Product) ) continue;
        $i++;

        // Build flattened map of all meta + attributes
        $map = [];
        foreach ($item->get_meta_data() as $m){
            $d = $m->get_data();
            $map[(string)$d['key']] = $d['value'];
        }

        if ($vid = (int)$item->get_variation_id()) {
            if ($v = wc_get_product($vid)) {
                foreach ((array)$v->get_attributes() as $tax => $val) {
                    $map['attribute_'.$tax] = $val;
                    $map[$tax] = $val;
                }
            }
        }

        if ($p = $item->get_product()) {
            foreach ($p->get_meta_data() as $m){
                $d = $m->get_data();
                if (!isset($map[$d['key']])) $map[$d['key']] = $d['value'];
            }
            if ($parent = wc_get_product($p->get_parent_id())) {
                foreach ($parent->get_meta_data() as $m){
                    $d = $m->get_data();
                    if (!isset($map[$d['key']])) $map[$d['key']] = $d['value'];
                }
            }
        }

        // Color / Size (required)
        $color = $first($map, $C['color']);
        $size  = $first($map, $C['size']);
        if ($color === '') $errors[] = "Item #{$i}: Color not selected";
        if ($size  === '') $errors[] = "Item #{$i}: Size not selected";

        // Product-level custom fields
        $vendor  = $first($map, $C['vendor']);
        $company = $first($map, $C['company']);
        $site    = $first($map, $C['site']);
        $prod    = $first($map, $C['prod']);
        $loc     = $first($map, $C['loc']);

        if ($vendor  === '') $errors[] = "Item #{$i}: Vendor Code not in Custom Fields";
        if ($company === '') $errors[] = "Item #{$i}: Company Name not in Custom Fields";
        if ($site    === '') $errors[] = "Item #{$i}: Site Slug not in Custom Fields";
        if ($prod    === '') $errors[] = "Item #{$i}: Production not in Custom Fields";
        if ($loc     === '') $errors[] = "Item #{$i}: Print Location not in Custom Fields";

        // ORIGINAL ART VALIDATION — new logic
        if ($loc !== '') {
            $locLower = strtolower($loc);

            // For each defined area, check if location contains substring
            foreach ($ART_MAP as $needle => $label) {
                if (strpos($locLower, $needle) !== false) {
                    // This location requires this Original Art field
                    $art = $first($map, [$label]);
                    if ($art === '') {
                        $errors[] = "Item #{$i}: {$label} not in Custom Fields";
                    }
                }
            }
        }
    }

    return ['ok' => empty($errors), 'errors' => $errors];
}}
