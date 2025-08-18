<?php
defined('ABSPATH') || exit;

/* Return lowercased map of attribute => value (from order item meta) */
function sw_extract_attributes_map($item) {
    $out = [];
    foreach ($item->get_formatted_meta_data('_', true) as $meta) {
        $key = strtolower(trim($meta->key));
        $out[$key] = $meta->value;
    }
    return $out;
}
