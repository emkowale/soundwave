<?php
if ( ! defined('ABSPATH') ) exit;

function soundwave_get_settings(){
    $defaults = array(
        'endpoint' => 'https://thebeartraxs.com/wp-json/wc/v3/orders',
        'consumer_key' => '',
        'consumer_secret' => '',
    );
    $opts = get_option('soundwave_settings', array());
    return wp_parse_args($opts, $defaults);
}
function soundwave_val_first($vals){
    foreach ($vals as $v){ if ($v !== '' && $v !== null) return $v; }
    return '';
}
function soundwave_item_meta($item, $keys){
    foreach ($keys as $k){
        $v = $item->get_meta($k, true);
        if ($v !== '') return $v;
    }
    return '';
}
function soundwave_product_meta_any($product, $keys){
    if ( ! $product ) return '';
    $ids = array($product->get_id());
    if ( method_exists($product,'get_parent_id') ){
        $pid = $product->get_parent_id();
        if ($pid) $ids[] = $pid;
    }
    foreach ($ids as $id){
        foreach ($keys as $k){
            $v = get_post_meta($id, $k, true);
            if ($v !== '') return $v;
        }
    }
    return '';
}
function soundwave_attr_color($item){
    return soundwave_val_first(array(
        soundwave_item_meta($item, array('Color','color','attribute_pa_color','pa_color','attribute_color')),
        wc_get_order_item_meta($item->get_id(), 'Color', true),
    ));
}
function soundwave_attr_size($item){
    return soundwave_val_first(array(
        soundwave_item_meta($item, array('Size','size','attribute_pa_size','pa_size','attribute_size')),
        wc_get_order_item_meta($item->get_id(), 'Size', true),
    ));
}
function soundwave_attr_print_location($item){
    return soundwave_val_first(array(
        soundwave_item_meta($item, array('Print Location','print_location','PrintLocation','print-location','attribute_print_location','attribute_pa_print-location','pa_print-location')),
    ));
}
function soundwave_attr_quality($item){
    return soundwave_item_meta($item, array('Quality','quality','attribute_quality'));
}
function soundwave_product_image_url($item, $product){
    // Accept common meta names from source; we will send as "Product Image"
    $meta = soundwave_item_meta($item, array('Product Image','product_image_full','product-image-full','product_image','product-image'));
    if ($meta !== '') return $meta;
    if ($product){
        $img = $product->get_image_id();
        if ($img){
            $u = wp_get_attachment_url($img);
            if ($u) return $u;
        }
        if ( method_exists($product,'get_parent_id') ){
            $pid = $product->get_parent_id();
            if ($pid){
                $u = wp_get_attachment_url( get_post_thumbnail_id($pid) );
                if ($u) return $u;
            }
        }
    }
    return '';
}
function soundwave_item_original_art($item, $product){
    // Accept legacy/meta variants but we will send as "Original Art"
    $v = soundwave_item_meta($item, array('Original Art','original-art','original_art','original art','art','Artwork URL'));
    if ($v !== '') return $v;
    return soundwave_product_meta_any($product, array('original-art','original_art','original art'));
}
function soundwave_item_company_present($item, $product){
    $v = soundwave_item_meta($item, array('Company Name','company-name','company_name'));
    if ($v !== '') return true;
    $v = soundwave_product_meta_any($product, array('company-name','company_name'));
    return $v !== '';
}
function soundwave_item_production($item, $product){
    $v = soundwave_item_meta($item, array('Production','production'));
    if ($v !== '') return $v;
    return soundwave_product_meta_any($product, array('production'));
}
// Prefer affiliate/source SKU; never the hub placeholder
function soundwave_affiliate_sku( $item, $product ){
    $candidates = array();
    $meta_sku = $item->get_meta('SKU', true);
    if ($meta_sku) { $candidates[] = $meta_sku; }
    if ($product && method_exists($product, 'get_sku')){
        $prod_sku = $product->get_sku();
        if ($prod_sku) { $candidates[] = $prod_sku; }
    }
    foreach ($candidates as $sku){
        if ($sku && $sku !== 'thebeartraxs-40158-0'){
            return (string) $sku;
        }
    }
    return '';
}

function soundwave_build_payload($order_id){
    $order = wc_get_order($order_id);
    if ( ! $order ) return null;

    $billing = array(
        'first_name' => $order->get_billing_first_name(),
        'last_name'  => $order->get_billing_last_name(),
        'email'      => $order->get_billing_email(),
        'address_1'  => $order->get_billing_address_1(),
        'city'       => $order->get_billing_city(),
        'state'      => $order->get_billing_state(),
        'postcode'   => $order->get_billing_postcode(),
        'country'    => $order->get_billing_country(),
    );
    $shipping = array(
        'first_name' => $order->get_shipping_first_name(),
        'last_name'  => $order->get_shipping_last_name(),
        'address_1'  => $order->get_shipping_address_1(),
        'city'       => $order->get_shipping_city(),
        'state'      => $order->get_shipping_state(),
        'postcode'   => $order->get_shipping_postcode(),
        'country'    => $order->get_shipping_country(),
    );

    $company_title = get_bloginfo('name');
    $line_items = array();
    $validation = array();
    $i = 0;

    foreach ($order->get_items() as $item){
        $i++;
        $product = $item->get_product();

        $color = soundwave_attr_color($item);
        $size  = soundwave_attr_size($item);
        $print = soundwave_attr_print_location($item);
        $qual  = soundwave_attr_quality($item);
        $img   = soundwave_product_image_url($item, $product);
        $art   = soundwave_item_original_art($item, $product);
        $has_company = soundwave_item_company_present($item, $product);
        $production = soundwave_item_production($item, $product);
        $affiliate_sku = soundwave_affiliate_sku($item, $product);

        $missing = array();
        if ($color==='') $missing[] = 'Color';
        if ($size==='') $missing[] = 'Size';
        if ($print==='') $missing[] = 'Print Location';
        if ($qual==='') $missing[] = 'Quality';
        if ( ! $has_company ) $missing[] = 'company-name';
        if ($img==='') $missing[] = 'Product Image';
        if ($art==='') $missing[] = 'Original Art';
        if ($production==='') $missing[] = 'production';

        if ( ! empty($missing) ){
            $validation[] = array('index'=>$i, 'name'=>$item->get_name(), 'missing'=>$missing);
        }

        // Save meta in the exact order requested for hub display.
        $meta = array(
            array('key'=>'Company Name', 'value'=>(string)$company_title),
            array('key'=>'SKU',          'value'=>(string)$affiliate_sku),
            array('key'=>'Color',        'value'=>(string)$color),
            array('key'=>'Size',         'value'=>(string)$size),
            array('key'=>'Print Location','value'=>(string)$print),
            array('key'=>'Quality',      'value'=>(string)$qual),
            array('key'=>'Product Image','value'=>(string)$img),
            array('key'=>'Original Art', 'value'=>(string)$art),
            array('key'=>'Production',   'value'=>(string)$production),
        );

        $line_items[] = array(
            'product_id' => 40158,
            'name'       => $item->get_name(),
            'quantity'   => $item->get_quantity(),
            'subtotal'   => wc_format_decimal($item->get_subtotal(), 2),
            'total'      => wc_format_decimal($item->get_total(), 2),
            'meta_data'  => $meta,
        );
    }

    if ( ! empty($validation) ){
        return array('__validation_errors'=>$validation);
    }

    return array(
        'status'        => 'processing',
        'currency'      => $order->get_currency(),
        'customer_note' => $order->get_customer_note(),
        'billing'       => $billing,
        'shipping'      => $shipping,
        'line_items'    => $line_items,
    );
}

function soundwave_check_hub_status($order_id){
    $opts = soundwave_get_settings();
    $hub_id = get_post_meta($order_id, '_soundwave_hub_id', true);
    if ( empty($hub_id) ) return 'unsynced';

    $url = trailingslashit($opts['endpoint']) . intval($hub_id);
    $url = add_query_arg(array(
        'consumer_key' => $opts['consumer_key'],
        'consumer_secret' => $opts['consumer_secret'],
    ), $url);

    $res = wp_remote_get($url, array('timeout'=>15));
    if ( is_wp_error($res) ) return 'unknown';

    $code = wp_remote_retrieve_response_code($res);
    $body = wp_remote_retrieve_body($res);

    if ($code == 404){
        delete_post_meta($order_id, '_soundwave_synced');
        delete_post_meta($order_id, '_soundwave_hub_id');
        return 'unsynced';
    }
    if ($code >= 200 && $code < 300){
        $json = json_decode($body, true);
        if ( is_array($json) && isset($json['status']) && $json['status'] === 'trash' ){
            delete_post_meta($order_id, '_soundwave_synced');
            delete_post_meta($order_id, '_soundwave_hub_id');
            return 'unsynced';
        }
        update_post_meta($order_id, '_soundwave_synced', '1');
        return 'synced';
    }
    return 'unknown';
}
