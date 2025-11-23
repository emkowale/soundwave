<?php
if ( ! defined('ABSPATH') ) exit;

add_action('init', function(){
    if (defined('SOUNDWAVE_UI_DISABLE') && SOUNDWAVE_UI_DISABLE) return;

    $get_meta_ci = function(WC_Order_Item $item, array $keys){
        foreach ($keys as $k){
            $v = $item->get_meta($k, true);
            if ($v !== '' && $v !== null) return $v;
            foreach ($item->get_meta_data() as $md){
                if (strcasecmp($md->key, $k)===0 && $md->value!=='') return $md->value;
            }
        }
        return '';
    };

    $resolve_sku = function(WC_Order_Item $item) use ($get_meta_ci){
        $vsku = $get_meta_ci($item, ['Variation SKU','variation_sku']);
        if ($vsku) return $vsku;
        $slug = $get_meta_ci($item, ['Site Slug','site_slug']);
        $pid  = $get_meta_ci($item, ['Affiliate Product ID','affiliate_product_id']);
        $vid  = $get_meta_ci($item, ['Affiliate Variation ID','affiliate_variation_id']);
        if ($slug && $pid!=='' && $vid!=='') return "{$slug}-{$pid}-{$vid}";
        $fb = $get_meta_ci($item, ['resolved_sku','SKU','sku']);
        return $fb ?: '';
    };

    $resolve_img = function(WC_Order_Item $item) use ($get_meta_ci){
        $u = $get_meta_ci($item, ['Variation Image URL','variation_image_url']);
        if ($u) return $u;
        $u = $get_meta_ci($item, ['Product Image','product_image','Product Image URL','product_image_url']);
        return $u ?: '';
    };

    add_action('woocommerce_before_order_itemmeta', function($item_id, $item) use ($resolve_sku, $resolve_img){
        if (!($item instanceof WC_Order_Item_Product)) return;
        $sku = $resolve_sku($item);
        $img = $resolve_img($item);
        echo '<div class="sw-ui-block" data-sw-item="'.(int)$item_id.'">';
        if ($img){
            $u = esc_url($img);
            echo '<a href="'.$u.'" class="sw-thumb" target="_blank" rel="noopener noreferrer">';
            echo '<img src="'.$u.'" alt="" style="max-width:52px;max-height:52px;border-radius:6px;margin:2px 8px 2px 0;">';
            echo '</a> <a href="'.$u.'" target="_blank" rel="noopener noreferrer" style="font-size:11px;">Open Mockup ↗</a>';
        }
        if ($sku){
            echo '<div class="sw-aff-sku" data-sw-sku="'.esc_attr($sku).'" style="font-size:12px;margin-top:4px;">SKU: <strong>'.esc_html($sku).'</strong></div>';
        }
        echo '</div>';
    }, 9, 3);

    add_filter('woocommerce_order_item_get_formatted_meta_data', function($meta){
        $drop = ['SKU','sku','Variation SKU','variation_sku','Variation Image URL','variation_image_url','Product Image','product_image','Product Image URL','product_image_url'];
        $out = [];
        foreach ($meta as $m){ if (!in_array($m->display_key, $drop, true)) $out[] = $m; }
        return $out;
    }, 10, 1);
});
