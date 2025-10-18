<?php
if ( ! defined('ABSPATH') ) exit;

function soundwave_get_settings(){
    $defaults = array(
        'endpoint'        => 'https://thebeartraxs.com/wp-json/wc/v3/orders',
        'consumer_key'    => '',
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

/*
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
*/

/**
 * Build the WooCommerce REST API payload for creating an order on the Hub.
 * - Copies billing/shipping from the source order
 * - Sets status to 'processing' by default
 * - Sets set_paid=true if source order is processing/completed
 * - Delegates line item building to soundwave_build_line_items()
 */
function soundwave_build_payload( $order_id ) {
    $order = wc_get_order( $order_id );
    if ( ! $order ) {
        return new WP_Error( 'soundwave_no_order', 'Order not found.' );
    }

    // Source status → set_paid
    $src_status = $order->get_status(); // e.g. 'processing', 'completed', 'pending'
    $set_paid   = in_array( $src_status, array( 'processing', 'completed' ), true );

    // Billing map
    $billing = array(
        'first_name' => (string) $order->get_billing_first_name(),
        'last_name'  => (string) $order->get_billing_last_name(),
        'company'    => (string) $order->get_billing_company(),
        'address_1'  => (string) $order->get_billing_address_1(),
        'address_2'  => (string) $order->get_billing_address_2(),
        'city'       => (string) $order->get_billing_city(),
        'state'      => (string) $order->get_billing_state(),
        'postcode'   => (string) $order->get_billing_postcode(),
        'country'    => (string) $order->get_billing_country(),
        'email'      => (string) $order->get_billing_email(),
        'phone'      => (string) $order->get_billing_phone(),
    );

    // Shipping map
    $shipping = array(
        'first_name' => (string) $order->get_shipping_first_name(),
        'last_name'  => (string) $order->get_shipping_last_name(),
        'company'    => (string) $order->get_shipping_company(),
        'address_1'  => (string) $order->get_shipping_address_1(),
        'address_2'  => (string) $order->get_shipping_address_2(),
        'city'       => (string) $order->get_shipping_city(),
        'state'      => (string) $order->get_shipping_state(),
        'postcode'   => (string) $order->get_shipping_postcode(),
        'country'    => (string) $order->get_shipping_country(),
        'phone'      => '', // Woo shipping phone not standard; leave blank
    );

    // If shipping name is empty, fall back to billing so the Hub shows a name
    if ( $shipping['first_name'] === '' && $billing['first_name'] !== '' ) {
        $shipping['first_name'] = $billing['first_name'];
        $shipping['last_name']  = $billing['last_name'];
        $shipping['company']    = $billing['company'];
    }

    // Payment labels to avoid "pending payment" feel in UI
    $payment_method       = 'soundwave';
    $payment_method_title = 'Soundwave Affiliate Sync';

    // Optional: customer note to link back to source
    $note = sprintf(
        'Synced from %s order #%s',
        parse_url( home_url(), PHP_URL_HOST ),
        $order->get_order_number()
    );

    // --- Line items ---
    $line_items = soundwave_build_line_items( $order );

    // --- Shipping & fees (optional; pass through if present) ---
    $shipping_lines = array();
    foreach ( $order->get_items( 'shipping' ) as $ship ) {
        $shipping_lines[] = array(
            'method_title' => $ship->get_name(),
            'method_id'    => $ship->get_method_id(),
            'total'        => wc_format_decimal( $ship->get_total(), 2 ),
        );
    }

    $fee_lines = array();
    foreach ( $order->get_items( 'fee' ) as $fee ) {
        $fee_lines[] = array(
            'name'  => $fee->get_name(),
            'total' => wc_format_decimal( $fee->get_total(), 2 ),
        );
    }

    // --- Final payload ---
    $payload = array(
        'status'               => 'processing',          // force Processing on the Hub
        'set_paid'             => $set_paid,             // true if source was processing/completed
        'payment_method'       => $payment_method,
        'payment_method_title' => $payment_method_title,
        'customer_note'        => $note,

        'billing'              => $billing,
        'shipping'             => $shipping,

        'line_items'           => $line_items,
        'shipping_lines'       => $shipping_lines,
        'fee_lines'            => $fee_lines,

        // Helpful for de-dupe on the Hub if you add a tiny finder endpoint later:
        'meta_data'            => array(
            array( 'key' => '_soundwave_source_site',  'value' => parse_url( home_url(), PHP_URL_HOST ) ),
            array( 'key' => '_soundwave_source_order', 'value' => (string) $order->get_id() ),
        ),
    );

    // Store for debugging
    update_post_meta( $order_id, '_soundwave_last_payload', wp_json_encode( $payload ) );

    return $payload;
}

function soundwave_check_hub_status($order_id){
    $opts   = soundwave_get_settings();
    $hub_id = get_post_meta($order_id, '_soundwave_hub_id', true);
    if ( empty($hub_id) ) return 'unsynced';

    $url = trailingslashit($opts['endpoint']) . intval($hub_id);
    $url = add_query_arg(array(
        'consumer_key'    => $opts['consumer_key'],
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

/**
 * Build REST line_items with product custom fields appended as meta_data.
 * - Uses VARIATION SKU (as "SKU"), replaces any existing SKU with the variation one.
 * - Adds "Parent SKU" when applicable.
 * - Canonicalizes & de-dupes Color/Size so each appears exactly once.
 * - Adds Company Name, Print Location, Production, Original Art, Site Slug, Vendor Code.
 */
if ( ! function_exists('soundwave_build_line_items') ) :
function soundwave_build_line_items( WC_Order $order ) {
    if ( ! function_exists('soundwave_first_value_from_candidates') ) {
        require_once SOUNDWAVE_DIR . 'includes/validator.php';
    }

    $required_map = [
        'Company Name'   => ['company_name','company-name','Company Name','_company_name'],
        'Print Location' => ['print_location','print-location','Print Location','_print_location','attribute_print_location'],
        'Production'     => ['production','Production','_production'],
        'Original Art'   => ['original_art','original_art_url','Original Art','_original_art','original-art'],
        'Site Slug'      => ['site_slug','site-slug','Site Slug','_site_slug'],
        'Vendor Code'    => ['vendor_code','vendor-code','Vendor Code','_vendor_code','quality','Quality','_quality'],
    ];

    $normalize = function($s){
        $s = is_string($s) ? $s : (string)$s;
        $s = trim($s);
        return strtolower(preg_replace('/[^a-z0-9]+/','', $s)); // "pa_color"/"Color" -> "color"
    };

    $COLOR_KEYS = ['color','pa_color','attribute_color','attribute_pa_color'];
    $SIZE_KEYS  = ['size','pa_size','attribute_size','attribute_pa_size'];

    $items = [];

    foreach ( $order->get_items( 'line_item' ) as $item ) {
        /** @var WC_Order_Item_Product $item */
        $qty = (int) $item->get_quantity();
        if ( $qty <= 0 ) continue;

        $product       = $item->get_product();
        $product_id    = $product ? $product->get_id() : 0;
        $is_variation  = $product instanceof WC_Product_Variation;
        $variation_id  = $is_variation ? $product->get_id() : 0;

        $line = [
            'product_id'   => $variation_id ?: $product_id,
            'quantity'     => $qty,
            'subtotal'     => wc_format_decimal( $item->get_subtotal(), 2 ),
            'total'        => wc_format_decimal( $item->get_total(), 2 ),
            'name'         => $item->get_name(),
            'meta_data'    => [],
        ];

        // De-dup registry
        $seen = []; // [norm_key => [norm_val => true]]
        $push_meta = function(array $pair) use (&$line, &$seen, $normalize){
            $k = isset($pair['key']) ? (string)$pair['key'] : '';
            $v = isset($pair['value']) ? (string)$pair['value'] : '';
            $k_norm = $normalize($k);
            $v_norm = $normalize($v);
            if ($k_norm === '') return;
            if ( isset($seen[$k_norm][$v_norm]) ) return;
            if ( isset($seen[$k_norm]) && $v_norm === '' ) return;
            $line['meta_data'][] = ['key' => $k, 'value' => $v];
            if ( ! isset($seen[$k_norm]) ) $seen[$k_norm] = [];
            $seen[$k_norm][$v_norm] = true;
        };

        // 1) Gather existing item meta, but DO NOT carry through raw color/size keys; we'll canonicalize later
        $color_val = '';
        $size_val  = '';
        foreach ( $item->get_meta_data() as $m ) {
            $k = (string)$m->key;
            $v = (string)$m->value;
            $nk = $normalize($k);

            if ( in_array($nk, $COLOR_KEYS, true) ) { if ($v !== '') $color_val = $v; continue; }
            if ( in_array($nk, $SIZE_KEYS,  true) ) { if ($v !== '') $size_val  = $v; continue; }

            $push_meta(['key'=>$k,'value'=>$v]);
        }

        // If we still don't have Color/Size from item meta, try the variation attributes
        if ( $is_variation && $product ) {
            $atts = $product->get_attributes(); // ['pa_color'=>'Black','pa_size'=>'S', ...]
            foreach ( $atts as $att_key => $att_val ) {
                if ( is_array($att_val) ) continue;
                $nk = $normalize($att_key);
                if ( in_array($nk, $COLOR_KEYS, true) && $color_val === '' ) $color_val = (string)$att_val;
                if ( in_array($nk, $SIZE_KEYS,  true) && $size_val  === '' ) $size_val  = (string)$att_val;
            }
        }

        // Add ONE canonical Color/Size
        if ( $color_val !== '' ) $push_meta(['key'=>'Color','value'=>$color_val]);
        if ( $size_val  !== '' ) $push_meta(['key'=>'Size', 'value'=>$size_val]);

        // 2) SKU handling — Prefer VARIATION SKU; add as a visible meta row "Variation SKU"
        if ( $product ) {
            $variation_sku = '';
            if ( $is_variation ) {
                // Try all places a var SKU could live
                $variation_sku = (string) $product->get_sku();
                if ( $variation_sku === '' ) {
                    $variation_sku = (string) get_post_meta( $variation_id, '_sku', true );
                }
                if ( $variation_sku === '' ) {
                    $variation_sku = (string) $item->get_meta('SKU', true);
                    if ( $variation_sku === '' ) {
                        $variation_sku = (string) $item->get_meta('_sku', true);
                    }
                }
            }

            // Show it explicitly under a non-reserved key so it renders in the Hub UI
            if ( $variation_sku !== '' ) {
                // Remove any previous "SKU" meta rows (cosmetic) and add one clear row
                $filtered = [];
                foreach ( $line['meta_data'] as $md ) {
                    $nk = $normalize($md['key']);
                    if ( $nk === 'sku' ) continue; // drop conflicting label
                    $filtered[] = $md;
                }
                $line['meta_data'] = $filtered;

                // rebuild the de-dupe map after filtering
                $seen = [];
                foreach ($line['meta_data'] as $md) {
                    $seen[$normalize($md['key'])][$normalize($md['value'])] = true;
                }

                // Add a visible label that won't be suppressed by Woo
                $push_meta(['key' => 'Variation SKU', 'value' => $variation_sku]);
            }

            // Parent SKU (if applicable)
            if ( $is_variation ) {
                $parent_id = $product->get_parent_id();
                if ( $parent_id ) {
                    $parent = wc_get_product($parent_id);
                    if ( $parent ) {
                        $parent_sku = (string) $parent->get_sku();
                        if ( $parent_sku === '' ) {
                            $parent_sku = (string) get_post_meta( $parent_id, '_sku', true );
                        }
                        if ( $parent_sku !== '' ) {
                            $push_meta(['key' => 'Parent SKU', 'value' => $parent_sku]);
                        }
                    }
                }
            }
        }

        // -- Product Image: prefer variation image, else parent product image, else any item-provided URL --
        $img_url = '';
        if ( $product ) {
            // 1) If this is a variation, try its image first
            $img_id = $is_variation ? (int) $product->get_image_id() : 0;

            // 2) If no variation image, try the parent product image
            if ( ! $img_id && $is_variation ) {
                $parent_id = $product->get_parent_id();
                if ( $parent_id ) {
                    $parent = wc_get_product( $parent_id );
                    if ( $parent ) $img_id = (int) $parent->get_image_id();
                }
            }

            // 3) Convert to URL if we have an attachment
            if ( $img_id ) {
                $img_url = wp_get_attachment_image_url( $img_id, 'full' );
            }

            // 4) Final fallback: any per-item meta you may already have populated
            if ( ! $img_url ) {
                if ( function_exists('soundwave_product_image_url') ) {
                    $img_url = (string) soundwave_product_image_url( $item, $product );
                } else {
                    $img_url = (string) $item->get_meta('Product Image', true);
                    if ( ! $img_url ) $img_url = (string) $item->get_meta('product_image_full', true);
                }
            }
        }

        if ( $img_url ) {
            // Use a stable key the Hub snippet expects
            $push_meta([ 'key' => 'Product Image', 'value' => $img_url ]);
        }


        // 3) Append the six product custom fields (variation → parent fallback)
        if ( $product ) {
            foreach ( $required_map as $label => $candidates ) {
                $val = soundwave_first_value_from_candidates( $product, $candidates );
                if ( is_string($val) ) $val = trim($val);
                if ( $val !== null && $val !== '' ) {
                    $push_meta([ 'key' => $label, 'value' => $val ]);
                }
            }
        }

        $items[] = $line;
    }

    return $items;
}
endif;
