# Soundwave (Affiliate → Hub Woo Sync) — Blueprint README

> **Blueprint you can build from**: This README is a complete, GitHub‑ready spec that lets you create the **Soundwave** WooCommerce plugin from scratch, package it, and deploy it. It includes folder layout, minimal production code scaffolds, build/package steps, auto‑sync hooks, the manual Sync UI, and Hub admin snippets (thumbnail + lightbox).

---

## TL;DR

- **Purpose**: Push WooCommerce orders from an **Affiliate** site to a **Hub** WooCommerce site through the Hub’s REST API (v3).  
- **Manual**: “Sync” button on the admin orders table (with “Fix errors” link if validation fails).  
- **Automatic**: Sync on `thankyou`, `payment_complete`, and `order_status_processing`.  
- **Validation**: Each line item must resolve product custom fields:  
  `Company Name`, `Print Location`, `Production`, `Original Art`, `Site Slug`, `Vendor Code`.  
- **Mapping**: Sends **billing/shipping**, **line items** (with variation **SKU**, **Parent SKU**, **attributes** (no dupes)), shipping/fees, and order meta. Forces Hub status to **`processing`** and sets `set_paid` when appropriate.  
- **Resync awareness**: If the item is deleted/trashed on the Hub, the Affiliate detects unsynced state and the Sync button returns.

---

## Features

- Admin orders list column showing **Sync** state:
  - ✅ **Synced** text for orders known to exist on the Hub.
  - 🔁 **Sync** button with spinner and **Fix errors** link when validation fails.
- **Auto‑Sync** hooks (single guarded attempt):
  - `woocommerce_thankyou`
  - `woocommerce_payment_complete`
  - `woocommerce_order_status_processing`
- **Manual AJAX** sync: `wp_ajax_soundwave_sync_order`
- **Hub status checker**: `wp_ajax_soundwave_check_status` (batch)
- **Robust line‑item meta**:
  - Adds **Variation SKU** (and **Parent SKU**), deduplicates **Color/Size**.
  - Appends six product custom fields (variation → parent fallback).
  - Sends `Product Image` URL per line for Hub UI.
- **Error notes** written to the order + `_soundwave_last_error` meta.
- **Debug meta**: `_soundwave_last_payload`, `_soundwave_last_response_code`, `_soundwave_last_response_body`.

---

## Requirements

- WordPress 6.x
- WooCommerce 7.x+ (HPOS compatible admin tested)
- PHP 7.4+ (8.0+ recommended)
- Hub (target) must expose WooCommerce REST API v3 endpoints and keys with **write** access to **orders**.

---

## Folder Structure (GitHub repo)

> **IMPORTANT**: The plugin must install into a folder **named exactly** `soundwave/`. The ZIP you upload should contain a root folder named `soundwave` (not `soundwave-vX.Y.Z`).

```
soundwave/                    ← plugin root folder (this exact name matters)
├── soundwave.php             ← main plugin file (headers, constants, boot)
├── includes/
│   ├── utils.php             ← payload builder, helpers, hub status, etc.
│   └── validator.php         ← field lookups & per-order validation
├── sync.php                  ← admin column, AJAX handlers, auto-sync hooks
├── assets/
│   ├── admin.css             ← minimal styling for button/spinner
│   └── admin.js              ← Sync button JS + row updates
├── readme.md                 ← this file (kept in repo for devs)
└── README.md                 ← GitHub facing (this file; you can keep one)
```

> You can keep both `readme.md` (lowercase, WP.org style if you ever publish there) and `README.md` (GitHub style). This blueprint uses **README.md**.

---

## Installation (Developer)

1. Clone into `wp-content/plugins/` as **`soundwave`**:
   ```bash
   cd wp-content/plugins
   git clone https://github.com/your-org/soundwave.git soundwave
   ```
2. Activate **Soundwave** in WP Admin → Plugins.
3. Set options (see **Configuration**).

### Packaging a Release ZIP

> Ensure the **ZIP root** folder is **`soundwave/`**.

```bash
# from the parent directory containing the `soundwave/` folder
zip -r soundwave-v1.4.21.zip soundwave -x "soundwave/.git/*" "soundwave/.github/*" "soundwave/node_modules/*"
```

Upload `soundwave-v1.4.21.zip` in WP Admin → Plugins → Add New → Upload Plugin.

---

## Configuration

The plugin reads settings from the single option `soundwave_settings`:

```php
[
  'endpoint'        => 'https://hubsite.com/wp-json/wc/v3/orders',
  'consumer_key'    => 'ck_xxxxx',
  'consumer_secret' => 'cs_xxxxx',
]
```

You can set these via WP‑CLI:

```bash
wp option update soundwave_settings \
'{"endpoint":"https://thebeartraxs.com/wp-json/wc/v3/orders","consumer_key":"ck_XXX","consumer_secret":"cs_XXX"}' \
--format=json
```

Or drop a one‑time snippet in a must‑use plugin to initialize them.

---

## Minimal Production Code (copy/paste scaffolds)

> These are minimal, working baselines distilled from our working implementation. Paste these files to get a fully functional plugin and adjust as desired.

### `soundwave.php`

```php
<?php
/**
 * Plugin Name: Soundwave
 * Description: Affiliate → Hub WooCommerce order sync (manual + auto).
 * Version: 1.4.21
 * Author: Your Company
 */

if (!defined('ABSPATH')) exit;

define('SOUNDWAVE_VERSION', '1.4.21');
define('SOUNDWAVE_DIR', plugin_dir_path(__FILE__));
define('SOUNDWAVE_URL', plugin_dir_url(__FILE__));

// Hard fail notice if the folder name is wrong (optional safety)
add_action('admin_init', function () {
    $expected = WP_PLUGIN_DIR . '/soundwave/soundwave.php';
    if (!file_exists($expected)) {
        add_action('admin_notices', function () {
            echo '<div class="notice notice-error"><p><strong>Soundwave:</strong> Plugin folder must be <code>soundwave/</code>. Repackage your ZIP with that root folder name.</p></div>';
        });
    }
});

require_once SOUNDWAVE_DIR . 'includes/utils.php';
require_once SOUNDWAVE_DIR . 'includes/validator.php';
require_once SOUNDWAVE_DIR . 'sync.php';
```

### `includes/utils.php`

```php
<?php
if (!defined('ABSPATH')) exit;

function soundwave_get_settings(){
    $defaults = [
        'endpoint'        => 'https://thebeartraxs.com/wp-json/wc/v3/orders',
        'consumer_key'    => '',
        'consumer_secret' => '',
    ];
    $opts = get_option('soundwave_settings', []);
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
    if (!$product) return '';
    $ids = [$product->get_id()];
    if (method_exists($product, 'get_parent_id')){
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

// Prefer affiliate/source SKU; never the hub placeholder
function soundwave_affiliate_sku( $item, $product ){
    $candidates = [];
    $meta_sku = $item->get_meta('SKU', true);
    if ($meta_sku) $candidates[] = $meta_sku;
    if ($product && method_exists($product, 'get_sku')){
        $prod_sku = $product->get_sku();
        if ($prod_sku) $candidates[] = $prod_sku;
    }
    foreach ($candidates as $sku){
        if ($sku && $sku !== 'thebeartraxs-40158-0') return (string) $sku;
    }
    return '';
}

/** Build payload for Hub create-order (WC REST v3). */
function soundwave_build_payload( $order_id ) {
    $order = wc_get_order( $order_id );
    if (!$order) return new WP_Error('soundwave_no_order', 'Order not found.');

    $src_status = $order->get_status();
    $set_paid   = in_array($src_status, ['processing','completed'], true);

    $billing = [
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
    ];

    $shipping = [
        'first_name' => (string) $order->get_shipping_first_name(),
        'last_name'  => (string) $order->get_shipping_last_name(),
        'company'    => (string) $order->get_shipping_company(),
        'address_1'  => (string) $order->get_shipping_address_1(),
        'address_2'  => (string) $order->get_shipping_address_2(),
        'city'       => (string) $order->get_shipping_city(),
        'state'      => (string) $order->get_shipping_state(),
        'postcode'   => (string) $order->get_shipping_postcode(),
        'country'    => (string) $order->get_shipping_country(),
        'phone'      => '',
    ];
    if ($shipping['first_name'] === '' && $billing['first_name'] !== ''){
        $shipping['first_name'] = $billing['first_name'];
        $shipping['last_name']  = $billing['last_name'];
        $shipping['company']    = $billing['company'];
    }

    $note = sprintf('Synced from %s order #%s',
        parse_url(home_url(), PHP_URL_HOST),
        $order->get_order_number()
    );

    $line_items = soundwave_build_line_items($order);

    $shipping_lines = [];
    foreach ($order->get_items('shipping') as $ship) {
        $shipping_lines[] = [
            'method_title' => $ship->get_name(),
            'method_id'    => $ship->get_method_id(),
            'total'        => wc_format_decimal($ship->get_total(), 2 ),
        ];
    }
    $fee_lines = [];
    foreach ($order->get_items('fee') as $fee) {
        $fee_lines[] = [
            'name'  => $fee->get_name(),
            'total' => wc_format_decimal($fee->get_total(), 2 ),
        ];
    }

    $payload = [
        'status'               => 'processing',
        'set_paid'             => $set_paid,
        'payment_method'       => 'soundwave',
        'payment_method_title' => 'Soundwave Affiliate Sync',
        'customer_note'        => $note,
        'billing'              => $billing,
        'shipping'             => $shipping,
        'line_items'           => $line_items,
        'shipping_lines'       => $shipping_lines,
        'fee_lines'            => $fee_lines,
        'meta_data'            => [
            ['key' => '_soundwave_source_site',  'value' => parse_url(home_url(), PHP_URL_HOST) ],
            ['key' => '_soundwave_source_order', 'value' => (string) $order->get_id()         ],
        ],
    ];

    update_post_meta($order_id, '_soundwave_last_payload', wp_json_encode($payload));
    return $payload;
}

function soundwave_check_hub_status($order_id){
    $opts = soundwave_get_settings();
    $hub_id = get_post_meta($order_id, '_soundwave_hub_id', true);
    if (empty($hub_id)) return 'unsynced';

    $url = trailingslashit($opts['endpoint']) . intval($hub_id);
    $url = add_query_arg([
        'consumer_key'    => $opts['consumer_key'],
        'consumer_secret' => $opts['consumer_secret'],
    ], $url);

    $res = wp_remote_get($url, ['timeout'=>15]);
    if (is_wp_error($res)) return 'unknown';

    $code = wp_remote_retrieve_response_code($res);
    $body = wp_remote_retrieve_body($res);

    if ($code == 404){
        delete_post_meta($order_id, '_soundwave_synced');
        delete_post_meta($order_id, '_soundwave_hub_id');
        return 'unsynced';
    }
    if ($code >= 200 && $code < 300){
        $json = json_decode($body, true);
        if (is_array($json) && isset($json['status']) && $json['status'] === 'trash'){
            delete_post_meta($order_id, '_soundwave_synced');
            delete_post_meta($order_id, '_soundwave_hub_id');
            return 'unsynced';
        }
        update_post_meta($order_id, '_soundwave_synced', '1');
        return 'synced';
    }
    return 'unknown';
}

/** Build REST line_items with meta: Variation/Parent SKU, attributes (deduped), and required product fields. */
if (!function_exists('soundwave_build_line_items')) :
function soundwave_build_line_items( WC_Order $order ) {
    if (!function_exists('soundwave_first_value_from_candidates')) {
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
        return strtolower(preg_replace('/[^a-z0-9]+/','', trim($s)));
    };
    $COLOR_KEYS = ['color','pacolor','attributecolor','attributepacolor'];
    $SIZE_KEYS  = ['size','pasize','attributesize','attributepasize'];

    $items = [];

    foreach ($order->get_items('line_item') as $item) {
        /** @var WC_Order_Item_Product $item */
        $qty = (int) $item->get_quantity();
        if ($qty <= 0) continue;

        $product       = $item->get_product();
        $product_id    = $product ? $product->get_id() : 0;
        $is_variation  = $product instanceof WC_Product_Variation;
        $variation_id  = $is_variation ? $product->get_id() : 0;

        $line = [
            'product_id'   => $variation_id ?: $product_id,
            'quantity'     => $qty,
            'subtotal'     => wc_format_decimal($item->get_subtotal(), 2),
            'total'        => wc_format_decimal($item->get_total(), 2),
            'name'         => $item->get_name(),
            'meta_data'    => [],
        ];

        // De-dupe registry
        $seen = [];
        $push_meta = function(array $pair) use (&$line, &$seen, $normalize){
            $k = isset($pair['key']) ? (string)$pair['key'] : '';
            $v = isset($pair['value']) ? (string)$pair['value'] : '';
            $k_norm = $normalize($k);
            $v_norm = $normalize($v);
            if ($k_norm === '') return;
            if (isset($seen[$k_norm][$v_norm])) return;
            if (isset($seen[$k_norm]) && $v_norm === '') return;
            $line['meta_data'][] = ['key' => $k, 'value' => $v];
            if (!isset($seen[$k_norm])) $seen[$k_norm] = [];
            $seen[$k_norm][$v_norm] = true;
        };

        // Start with existing item meta (skip raw size/color; we canonicalize later)
        $color_val = ''; $size_val = '';
        foreach ($item->get_meta_data() as $m) {
            $k = (string) $m->key;
            $v = (string) $m->value;
            $nk = $normalize($k);
            if (in_array($nk, $COLOR_KEYS, true)) { if ($v !== '') $color_val = $v; continue; }
            if (in_array($nk, $SIZE_KEYS,  true)) { if ($v !== '') $size_val  = $v; continue; }
            $push_meta(['key'=>$k,'value'=>$v]);
        }

        // Variation attributes (fallback for Color/Size if missing)
        if ($is_variation && $product) {
            $atts = $product->get_attributes();
            foreach ($atts as $att_key => $att_val) {
                if (is_array($att_val)) continue;
                $nk = $normalize($att_key);
                if (in_array($nk, $COLOR_KEYS, true) && $color_val === '') $color_val = (string) $att_val;
                if (in_array($nk, $SIZE_KEYS,  true) && $size_val  === '') $size_val  = (string) $att_val;
            }
        }
        if ($color_val !== '') $push_meta(['key'=>'Color','value'=>$color_val]);
        if ($size_val  !== '') $push_meta(['key'=>'Size', 'value'=>$size_val]);

        // Variation SKU (explicit; replaces any ambiguous SKU entries)
        if ($product) {
            $variation_sku = '';
            if ($is_variation) {
                $variation_sku = (string) $product->get_sku();
                if ($variation_sku === '') $variation_sku = (string) get_post_meta($variation_id, '_sku', true);
                if ($variation_sku === '') $variation_sku = (string) $item->get_meta('SKU', true);
                if ($variation_sku === '') $variation_sku = (string) $item->get_meta('_sku', true);
            }
            if ($variation_sku !== '') {
                // drop existing SKU keys and add "Variation SKU"
                $line['meta_data'] = array_values(array_filter($line['meta_data'], function($md) use ($normalize){
                    return $normalize($md['key']) !== 'sku';
                }));
                $seen = [];
                foreach ($line['meta_data'] as $md) {
                    $seen[$normalize($md['key'])][$normalize($md['value'])] = true;
                }
                $push_meta(['key'=>'Variation SKU','value'=>$variation_sku]);
            }
            // Parent SKU
            if ($is_variation) {
                $parent_id = $product->get_parent_id();
                if ($parent_id) {
                    $parent = wc_get_product($parent_id);
                    if ($parent) {
                        $parent_sku = (string) $parent->get_sku();
                        if ($parent_sku === '') $parent_sku = (string) get_post_meta($parent_id, '_sku', true);
                        if ($parent_sku !== '') $push_meta(['key'=>'Parent SKU','value'=>$parent_sku]);
                    }
                }
            }
        }

        // Product Image: variation image → parent image → pre-supplied meta
        $img_url = '';
        if ($product) {
            $img_id = $is_variation ? (int) $product->get_image_id() : 0;
            if (!$img_id && $is_variation) {
                $parent_id = $product->get_parent_id();
                if ($parent_id) {
                    $parent = wc_get_product($parent_id);
                    if ($parent) $img_id = (int) $parent->get_image_id();
                }
            }
            if ($img_id) $img_url = wp_get_attachment_image_url($img_id, 'full');
        }
        if (!$img_url) {
            $img_url = (string) $item->get_meta('Product Image', true);
            if (!$img_url) $img_url = (string) $item->get_meta('product_image_full', true);
        }
        if ($img_url) $push_meta([ 'key' => 'Product Image', 'value' => $img_url ]);

        // Append six product custom fields
        if ($product) {
            foreach ($required_map as $label => $candidates) {
                $val = soundwave_first_value_from_candidates($product, $candidates);
                if (is_string($val)) $val = trim($val);
                if ($val !== null && $val !== '') $push_meta([ 'key' => $label, 'value' => $val ]);
            }
        }

        $items[] = $line;
    }

    return $items;
}
endif;
```

### `includes/validator.php`

```php
<?php
if (!defined('ABSPATH')) exit;

/** Read a meta value from variation → parent using candidate keys. */
function soundwave_first_value_from_candidates( WC_Product $product, array $candidates ){
    $ids = [$product->get_id()];
    if (method_exists($product,'get_parent_id')){
        $pid = $product->get_parent_id();
        if ($pid) $ids[] = $pid;
    }
    foreach ($ids as $id){
        foreach ($candidates as $key){
            $v = get_post_meta($id, $key, true);
            if ($v !== '' && $v !== null) return $v;
        }
    }
    return null;
}

/** Validate required item fields; returns ['ok'=>bool, 'errors'=>[]]. */
function soundwave_validate_order_required_fields( WC_Order $order ){
    $errors = [];

    $required = [
        'Company Name'   => ['company_name','company-name','Company Name','_company_name'],
        'Print Location' => ['print_location','print-location','Print Location','_print_location','attribute_print_location'],
        'Production'     => ['production','Production','_production'],
        'Original Art'   => ['original_art','original_art_url','Original Art','_original_art','original-art'],
        'Site Slug'      => ['site_slug','site-slug','Site Slug','_site_slug'],
        'Vendor Code'    => ['vendor_code','vendor-code','Vendor Code','_vendor_code','quality','Quality','_quality'],
    ];

    $index = 0;
    foreach ($order->get_items('line_item') as $item) {
        $index++;
        $name = $item->get_name();
        $product = $item->get_product();
        if (!$product){ $errors[] = "Line item #{$index} \"{$name}\" missing: Product"; continue; }

        $missing = [];
        foreach ($required as $label => $candidates){
            $val = soundwave_first_value_from_candidates($product, $candidates);
            if ($val === null || $val === '') $missing[] = $label;
        }
        if ($missing){
            $errors[] = 'Line item #'.$index.' "'.$name.'" missing: '.implode(', ', $missing);
        }
    }

    return ['ok' => empty($errors), 'errors' => $errors];
}
```

### `sync.php`

```php
<?php
if (!defined('ABSPATH')) exit;
require_once SOUNDWAVE_DIR . 'includes/utils.php';
require_once SOUNDWAVE_DIR . 'includes/validator.php';

/** Column on orders table */
function soundwave_add_order_sync_column( $columns ){
    $columns['soundwave_sync'] = __('Order Sync','soundwave');
    return $columns;
}
add_filter('manage_edit-shop_order_columns', 'soundwave_add_order_sync_column', 20);
add_filter('woocommerce_shop_order_list_table_columns', 'soundwave_add_order_sync_column', 20);

function soundwave_resolve_id_from_row($row){
    if (is_numeric($row)) return intval($row);
    if (is_object($row) && method_exists($row, 'get_id')) return intval($row->get_id());
    if (is_array($row) && isset($row['id'])) return intval($row['id']);
    return 0;
}

function soundwave_render_sync_cell( $column, $row ){
    if ($column !== 'soundwave_sync') return;
    $order_id = soundwave_resolve_id_from_row($row);
    if (!$order_id) { echo '<em>N/A</em>'; return; }

    $synced = get_post_meta($order_id, '_soundwave_synced', true) === '1';
    if ($synced){
        echo '<span class="soundwave-synced-text">Synced</span>';
        return;
    }
    $nonce = wp_create_nonce('soundwave_sync_' . $order_id);
    $order_link = admin_url('post.php?post='.$order_id.'&action=edit');
    echo '<div class="soundwave-actions">';
    echo '<button class="button soundwave-sync-btn" data-order="'.esc_attr($order_id).'" data-nonce="'.esc_attr($nonce).'">Sync</button>';
    echo ' <a class="button button-link-delete soundwave-fix-btn" style="display:none" href="'.esc_url($order_link).'" target="_blank">Fix errors</a>';
    echo '<span class="spinner" style="margin-left:6px;display:none;"></span>';
    echo '</div>';
}
add_action('manage_shop_order_posts_custom_column', 'soundwave_render_sync_cell', 20, 2);
add_action('woocommerce_shop_order_list_table_custom_column', 'soundwave_render_sync_cell', 20, 2);

/** Core sender reused by AJAX + auto-sync hooks */
function soundwave_send_to_hub( $order_id, $payload = null ) {
    $order = wc_get_order($order_id);
    if (!$order) return ['ok'=>false,'status'=>'unsynced','message'=>'Order not found'];

    if ($payload === null) $payload = soundwave_build_payload($order_id);

    // Run validator (authoritative) before posting
    $validation = soundwave_validate_order_required_fields($order);
    if (!$validation['ok']) {
        $lines = array_merge(['Soundwave validation failed — order not synced.'], $validation['errors']);
        $note  = implode("\n", $lines);
        update_post_meta($order_id, '_soundwave_last_error', $note);
        $order->add_order_note($note);
        return ['ok'=>false,'status'=>'unsynced','message'=>$note];
    }

    $opts = soundwave_get_settings();
    $url = add_query_arg([
        'consumer_key'    => $opts['consumer_key'],
        'consumer_secret' => $opts['consumer_secret'],
    ], $opts['endpoint']);

    $res = wp_remote_post($url, [
        'headers' => ['Content-Type'=>'application/json; charset=utf-8'],
        'body'    => wp_json_encode($payload),
        'timeout' => 30,
    ]);

    if (is_wp_error($res)){
        $msg = $res->get_error_message();
        update_post_meta($order_id, '_soundwave_last_error', $msg);
        $order->add_order_note('Soundwave error: '.$msg);
        return ['ok'=>false,'status'=>'unsynced','message'=>$msg];
    }

    $code = wp_remote_retrieve_response_code($res);
    $body = wp_remote_retrieve_body($res);
    update_post_meta($order_id, '_soundwave_last_response_code', $code);
    update_post_meta($order_id, '_soundwave_last_response_body', $body);

    if ($code >= 200 && $code < 300){
        $json = json_decode($body, true);
        update_post_meta($order_id, '_soundwave_synced', '1');
        if (!empty($json['id'])) update_post_meta($order_id, '_soundwave_hub_id', (string)$json['id']);
        $order->add_order_note('Soundwave: synced to hub (hub_id '.(!empty($json['id'])?$json['id']:'unknown').')');
        return ['ok'=>true,'status'=>'synced','message'=>'OK'];
    }

    $msg = 'HTTP ' . $code;
    $decoded = json_decode($body, true);
    if (is_array($decoded)){
        if (!empty($decoded['code']))    $msg .= "\nCode: ".$decoded['code'];
        if (!empty($decoded['message'])) $msg .= "\nMessage: ".$decoded['message'];
    } else {
        $msg .= "\n".$body;
    }
    update_post_meta($order_id, '_soundwave_last_error', $msg);
    $order->add_order_note('Soundwave error: '.$msg);
    return ['ok'=>false,'status'=>'unsynced','message'=>$msg];
}

/** AUTO-SYNC hooks (guarded by transient + one-shot marker) */
function soundwave_maybe_auto_sync( $order_id, $context = '' ){
    $order_id = intval($order_id);
    if (!$order_id) return;

    if (get_post_meta($order_id, '_soundwave_synced', true) === '1') return;

    $lock_key = 'soundwave_sync_lock_' . $order_id;
    if (get_transient($lock_key)) return;
    set_transient($lock_key, 1, 60);

    if (get_post_meta($order_id, '_soundwave_auto_attempted', true) === '1'){
        delete_transient($lock_key);
        return;
    }

    $payload = soundwave_build_payload($order_id);
    $res = soundwave_send_to_hub($order_id, $payload);
    update_post_meta($order_id, '_soundwave_auto_attempted', '1');
    delete_transient($lock_key);
    return $res;
}

add_action('woocommerce_thankyou', function($order_id){ soundwave_maybe_auto_sync($order_id,'thankyou'); }, 20);
add_action('woocommerce_payment_complete', function($order_id){ soundwave_maybe_auto_sync($order_id,'payment_complete'); }, 20);
add_action('woocommerce_order_status_processing', function($order_id){ soundwave_maybe_auto_sync($order_id,'status_processing'); }, 20);

/** AJAX: Manual sync */
add_action('wp_ajax_soundwave_sync_order', function(){
    if (!current_user_can('manage_woocommerce')) wp_send_json_error(['message'=>'forbidden'], 403);
    $order_id = isset($_POST['order_id']) ? intval($_POST['order_id']) : 0;
    $row_nonce = isset($_POST['row_nonce']) ? $_POST['row_nonce'] : (isset($_POST['nonce']) ? $_POST['nonce'] : '');
    $global    = isset($_POST['nonce']) ? $_POST['nonce'] : '';
    if (!$order_id) wp_send_json_error(['message'=>'invalid order id'], 400);

    $ok = false;
    if ($row_nonce && wp_verify_nonce($row_nonce, 'soundwave_sync_' . $order_id)) $ok = true;
    if (!$ok && $global && wp_verify_nonce($global, 'soundwave_sync_any')) $ok = true;
    if (!$ok) wp_send_json_error(['message'=>'bad nonce'], 400);

    $payload = soundwave_build_payload($order_id);
    $res = soundwave_send_to_hub($order_id, $payload);

    if ($res['ok']){
        wp_send_json_success(['status'=>'synced']);
    } else {
        $fix_url = admin_url('post.php?post='.$order_id.'&action=edit');
        wp_send_json_error(['status'=>'unsynced','message'=>$res['message'],'fix_url'=>$fix_url], 200);
    }
});

/** AJAX: Check hub status (batch) */
add_action('wp_ajax_soundwave_check_status', function(){
    if (!current_user_can('manage_woocommerce')) wp_send_json_error(['message'=>'forbidden'], 403);
    $ids = isset($_POST['order_ids']) ? (array) $_POST['order_ids'] : [];
    $ids = array_map('intval', $ids);
    $results = [];
    foreach ($ids as $oid){
        if (!$oid) continue;
        $results[$oid] = soundwave_check_hub_status($oid);
    }
    wp_send_json_success(['results'=>$results]);
});

/** Assets */
add_action('admin_enqueue_scripts', function(){
    $screen = function_exists('get_current_screen') ? get_current_screen() : null;
    $id = $screen ? $screen->id : '';
    if ($id !== 'edit-shop_order' && $id !== 'woocommerce_page_wc-orders') return;
    wp_enqueue_style('soundwave-admin', SOUNDWAVE_URL.'assets/admin.css', [], SOUNDWAVE_VERSION);
    wp_enqueue_script('soundwave-admin', SOUNDWAVE_URL.'assets/admin.js', ['jquery'], SOUNDWAVE_VERSION, true);
    wp_localize_script('soundwave-admin', 'SoundwaveAjax', [
        'ajaxurl'       => admin_url('admin-ajax.php'),
        'nonce_global'  => wp_create_nonce('soundwave_sync_any'),
    ]);
});
```

### `assets/admin.js`

```js
(function($){
  $(document).on('click', '.soundwave-sync-btn', function(e){
    e.preventDefault();
    var $btn = $(this);
    var orderId = $btn.data('order');
    var nonce = $btn.data('nonce') || (window.SoundwaveAjax ? SoundwaveAjax.nonce_global : '');
    var $row = $btn.closest('tr');
    var $spinner = $row.find('.soundwave-actions .spinner');
    var $fix = $row.find('.soundwave-fix-btn');

    $spinner.show();
    $fix.hide();

    $.post(SoundwaveAjax.ajaxurl, {
      action: 'soundwave_sync_order',
      order_id: orderId,
      row_nonce: nonce,
      nonce: SoundwaveAjax.nonce_global
    }).done(function(resp){
      if (resp && resp.success){
        $btn.replaceWith('<span class="soundwave-synced-text">Synced</span>');
      } else if (resp && resp.data){
        alert(resp.data.message || 'Sync failed');
        $fix.attr('href', resp.data.fix_url || $fix.attr('href')).show();
      } else {
        alert('Sync failed');
      }
    }).fail(function(xhr){
      alert('Sync failed: ' + (xhr.responseText || xhr.status));
    }).always(function(){
      $spinner.hide();
    });
  });
})(jQuery);
```

### `assets/admin.css`

```css
.soundwave-synced-text{ color:#2e7d32; font-weight:600; }
.soundwave-actions .spinner{ vertical-align:middle; }
```

---

## Hub (Target) Admin: Thumbnail + Lightbox (optional)

Place in the **Hub** child‑theme `functions.php`:

```php
<?php
// 200x200 thumb painted from "Product Image" with fullscreen lightbox.
add_action('woocommerce_before_order_itemmeta', function ($item_id, $item) {
    if (!is_admin() || !($item instanceof WC_Order_Item_Product)) return;
    $url = wc_get_order_item_meta($item_id, 'Product Image', true);
    if (!$url) $url = wc_get_order_item_meta($item_id, 'product_image_full', true);
    if (!$url) return;
    $esc_url = esc_url($url);
    $esc_id  = esc_attr($item_id);

    echo '<style id="sw-thumb-' . $esc_id . '">
      .woocommerce_order_items_wrapper tr.item[data-order_item_id="' . $esc_id . '"] .wc-order-item-thumbnail,
      .woocommerce_order_items_wrapper tr.item[data-order_item_id="' . $esc_id . '"] .wc-order-item-thumbnail::before{
        width:200px !important; height:200px !important; border-radius:6px !important;
        background-image:url("' . $esc_url . '") !important;
        background-size:cover !important; background-position:center !important; background-repeat:no-repeat !important;
        content:"" !important; display:block !important; cursor:zoom-in !important;
      }
    </style>
    <script id="sw-thumb-attr-' . $esc_id . '">
      (function(){
        var el=document.querySelector(\'.woocommerce_order_items_wrapper tr.item[data-order_item_id="' . $esc_id . '"] .wc-order-item-thumbnail\');
        if(el){ el.setAttribute("data-sw-full","'. esc_js($esc_url) .'"); }
      })();
    </script>';
}, 10, 2);

add_action('admin_head', function () {
    static $printed=false; if($printed) return; $printed=true;
    echo '<style id="sw-lightbox-css">
      .sw-lightbox{position:fixed;inset:0;background:rgba(0,0,0,.88);display:none;align-items:center;justify-content:center;z-index:999999}
      .sw-lightbox.is-open{display:flex}
      .sw-lightbox__img{max-width:92vw;max-height:92vh;border-radius:10px;box-shadow:0 12px 48px rgba(0,0,0,.55)}
      .sw-lightbox__close{position:fixed;top:14px;right:18px;color:#fff;font-size:32px;line-height:1;cursor:pointer;user-select:none;opacity:.95}
      .sw-lightbox__close:hover{opacity:1}
      .sw-lightbox__backdrop{position:absolute;inset:0}
    </style>';
}, 9);

add_action('admin_footer', function () {
    static $printed=false; if($printed) return; $printed=true; ?>
    <script id="sw-lightbox-js">
      (function(){
        var lb=document.createElement('div');
        lb.className='sw-lightbox';
        lb.innerHTML='<div class="sw-lightbox__backdrop" aria-hidden="true"></div><img class="sw-lightbox__img" alt=""/><div class="sw-lightbox__close" aria-label="Close">×</div>';
        document.body.appendChild(lb);
        var img=lb.querySelector('.sw-lightbox__img'), closeBtn=lb.querySelector('.sw-lightbox__close'), back=lb.querySelector('.sw-lightbox__backdrop');
        function openLB(src){ if(src){ img.src=src; lb.classList.add('is-open'); } }
        function closeLB(){ lb.classList.remove('is-open'); img.removeAttribute('src'); }
        closeBtn.addEventListener('click', closeLB, {passive:true});
        back.addEventListener('click', closeLB, {passive:true});
        document.addEventListener('keydown', function(e){ if(e.key==='Escape') closeLB(); });
        document.addEventListener('click', function(e){
          var t=e.target.closest('.wc-order-item-thumbnail'); if(!t) return;
          var src=t.getAttribute('data-sw-full');
          if(!src){
            var bg=window.getComputedStyle(t).getPropertyValue('background-image');
            if(bg&&bg.startsWith('url(')){ src=bg.slice(4,-1).replace(/^["\']|["\']$/g,''); }
          }
          if(src){ e.preventDefault(); e.stopPropagation(); openLB(src); }
        }, true);
      })();
    </script>
<?php }, 9);
```

---

## Troubleshooting

- **Plugin installs into `soundwave-vX.Y.Z/`** → Repackage ZIP so the root folder is **`soundwave/`**.
- **“Validation failed — order not synced.”** → Open order notes; the message lists missing fields per line item.
- **Auto‑sync didn’t fire** → Confirm order reached `thankyou`, `payment_complete`, or `processing`. Check `_soundwave_auto_attempted` meta (delete it to re‑attempt), and review `_soundwave_last_error`.
- **No thumbnail on Hub** → Ensure each line item includes `Product Image` meta (Affiliate); apply Hub CSS override above.
- **SKU not showing** → We output **Variation SKU** as a meta row (`Variation SKU`) to avoid Woo UI filtering. Confirm variation `_sku` is set on the Affiliate.

---

## Versioning & Changelog (template)

Use **SemVer**: `MAJOR.MINOR.PATCH`

```
## [1.4.21] — 2025-10-18
### Added
- Auto-sync hooks and guarded sender
- Variation SKU + Parent SKU in line meta
- Product Image meta; Hub admin lightbox

### Fixed
- De-dup Color/Size attributes
- Accurate validation messages

### Changed
- Force Hub order status to `processing`; set_paid for processing/completed
```

---

## License

Proprietary — © Your Company. All rights reserved. (Or choose a license and state it here.)

---

## Maintainers

- Your Name <dev@yourcompany.com>
- Team Name / Slack channel
