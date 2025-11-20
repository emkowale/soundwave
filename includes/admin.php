<?php
if ( ! defined('ABSPATH') ) exit;

/*
 * File: includes/admin.php
 * Plugin: Soundwave
 */

//
// MENU + PAGE
//
add_action('admin_menu', function(){
    add_menu_page(
        'Soundwave',
        'Soundwave',
        'manage_woocommerce',
        'soundwave-settings',
        'soundwave_settings_page',
        'dashicons-megaphone',
        56
    );
});

function soundwave_default_settings(){
    return array(
        'endpoint'        => 'https://thebeartraxs.com/wp-json/wc/v3/orders',
        'consumer_key'    => '',
        'consumer_secret' => '',
    );
}

function soundwave_settings_page(){
    if ( ! current_user_can('manage_woocommerce') ) return;

    $opts = get_option('soundwave_settings', soundwave_default_settings());

    if ( isset($_POST['soundwave_save']) && check_admin_referer('soundwave_save') ){
        $opts['endpoint']        = esc_url_raw( $_POST['endpoint'] ?? '' );
        $opts['consumer_key']    = sanitize_text_field( $_POST['consumer_key'] ?? '' );
        $opts['consumer_secret'] = sanitize_text_field( $_POST['consumer_secret'] ?? '' );
        update_option('soundwave_settings', $opts);
        echo '<div class="updated"><p>Settings saved.</p></div>';
    }

    $version = defined('SOUNDWAVE_VERSION') ? SOUNDWAVE_VERSION : 'dev';

    echo '<div class="wrap"><h1>Soundwave Settings <span style="color:#777;font-weight:normal;">v' . esc_html($version) . '</span></h1><form method="post">';
    wp_nonce_field('soundwave_save');
    echo '<table class="form-table">';
    echo '<tr><th><label for="endpoint">Hub Endpoint</label></th><td><input type="url" class="regular-text code" id="endpoint" name="endpoint" value="'.esc_attr($opts['endpoint']).'"></td></tr>';
    echo '<tr><th><label for="consumer_key">Consumer Key</label></th><td><input type="password" class="regular-text" id="consumer_key" name="consumer_key" value="'.esc_attr($opts['consumer_key']).'" autocomplete="new-password"></td></tr>';
    echo '<tr><th><label for="consumer_secret">Consumer Secret</label></th><td><input type="password" class="regular-text" id="consumer_secret" name="consumer_secret" value="'.esc_attr($opts['consumer_secret']).'" autocomplete="new-password"></td></tr>';
    echo '</table>';
    submit_button('Save Settings', 'primary', 'soundwave_save');

    // --- Test Connection UI (spinner + status) ---
    echo '<p style="margin-top:12px;">';
    echo '<button type="button" class="button" id="sw-test-conn-btn">Test API Connection</button> ';
    echo '<span id="sw-test-conn-status" style="display:inline-flex;align-items:center;gap:6px;margin-left:8px;">';
    echo '  <span class="sw-spin" style="display:none;width:16px;height:16px;border:2px solid #ccd0d4;border-top-color:#2271b1;border-radius:50%;animation:swspin 0.7s linear infinite;"></span>';
    echo '  <span class="sw-result" style="font-weight:600;"></span>';
    echo '</span>';
    echo '</p>';

    // Tiny inline CSS for spinner
    echo '<style>@keyframes swspin{to{transform:rotate(360deg);}}</style>';

    echo '<p>Manual sync from Orders list; real-time reconciliation keeps status accurate.</p>';
    echo '</form></div>';
}

//
// AJAX: Settings “Test API Connection”
// - Uses your saved endpoint + consumer key/secret
// - Nonce: soundwave_api_test (must arrive as _ajax_nonce)
//
add_action('wp_ajax_soundwave_api_test', function () {
    // Verify nonce (expects _ajax_nonce from JS)
    check_ajax_referer('soundwave_api_test');

    if ( ! current_user_can('manage_woocommerce') ) {
        wp_send_json_error(['message' => 'Insufficient permissions.']);
    }

    $settings = get_option('soundwave_settings', soundwave_default_settings());
    $endpoint = trim((string)($settings['endpoint'] ?? ''));
    $ck       = trim((string)($settings['consumer_key'] ?? ''));
    $cs       = trim((string)($settings['consumer_secret'] ?? ''));

    if ($endpoint === '' || $ck === '' || $cs === '') {
        $missing = [];
        if ($endpoint === '') $missing[] = 'Hub Endpoint';
        if ($ck === '')       $missing[] = 'Consumer Key';
        if ($cs === '')       $missing[] = 'Consumer Secret';
        wp_send_json_error(['message' => 'Missing settings: ' . implode(', ', $missing) . '. Save settings, then try again.']);
    }

    // Build a safe probe to Woo REST (GET 1 order, auth via query params)
    $probe = add_query_arg([
        'per_page'       => 1,
        'orderby'        => 'date',
        'order'          => 'desc',
        'consumer_key'   => rawurlencode($ck),
        'consumer_secret'=> rawurlencode($cs),
    ], $endpoint);

    $res = wp_remote_get($probe, [
        'timeout'     => 12,
        'redirection' => 3,
        'headers'     => ['Accept' => 'application/json'],
    ]);

    if (is_wp_error($res)) {
        wp_send_json_error(['message' => 'Network error: '.$res->get_error_message()]);
    }

    $code = (int) wp_remote_retrieve_response_code($res);
    $body = wp_remote_retrieve_body($res);
    $clip = $body ? wp_strip_all_tags( mb_substr($body, 0, 300) ) : '(empty)';

    if ($code >= 200 && $code < 300) {
        wp_send_json_success(['message' => 'Hub reachable. HTTP '.$code.' — nonce OK, credentials accepted.', 'code' => $code]);
    } elseif ($code === 401 || $code === 403) {
        wp_send_json_error(['message' => 'Authentication failed (HTTP '.$code.'). Check Consumer Key / Secret.', 'code' => $code]);
    } elseif ($code === 404) {
        wp_send_json_error(['message' => 'Endpoint not found (HTTP 404). Verify the Hub Endpoint URL.', 'code' => $code]);
    } else {
        wp_send_json_error(['message' => 'Unexpected response (HTTP '.$code.'). Body: '.$clip, 'code' => $code]);
    }
});

//
// ENQUEUE settings JS only on our page; pass action + nonce + selectors
//
add_action('admin_enqueue_scripts', function($hook){
    if (!isset($_GET['page']) || $_GET['page'] !== 'soundwave-settings') return;

    // Use your plugin constants if present; otherwise fallback
    $src = defined('SOUNDWAVE_URL')
        ? trailingslashit(SOUNDWAVE_URL) . 'assets/settings.js'
        : plugins_url('../assets/settings.js', __FILE__);

    wp_enqueue_script(
        'soundwave-settings',
        $src,
        ['jquery'],
        defined('SOUNDWAVE_VERSION') ? SOUNDWAVE_VERSION : '1.4.22',
        true
    );

    wp_localize_script('soundwave-settings', 'SOUNDWAVE_SETTINGS', [
        'ajax_url'      => admin_url('admin-ajax.php'),
        'action'        => 'soundwave_api_test',
        'nonce'         => wp_create_nonce('soundwave_api_test'),
        // Match your existing markup IDs:
        'buttonSel'     => '#sw-test-conn-btn',
        'statusSel'     => '#sw-test-conn-status',
        'spinSel'       => '#sw-test-conn-status .sw-spin',
        'resultSel'     => '#sw-test-conn-status .sw-result',
    ]);
});


// ===== Soundwave Hub — Order Items UI (thumb + affiliate VARIATION SKU) =====
add_action('init', function(){
    if (defined('SOUNDWAVE_UI_DISABLE') && SOUNDWAVE_UI_DISABLE) return;

    // Helper: case-insensitive first meta match
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

    // Resolve affiliate SKU: Variation SKU -> synthesized site-pid-vid -> fallback resolved/SKU
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

    // Resolve image URL
    $resolve_img = function(WC_Order_Item $item) use ($get_meta_ci){
        $u = $get_meta_ci($item, ['Variation Image URL','variation_image_url']);
        if ($u) return $u;
        $u = $get_meta_ci($item, ['Product Image','product_image','Product Image URL','product_image_url']);
        return $u ?: '';
    };

    // 1) Render thumb + visible affiliate SKU carrier near the top of the item meta area
    add_action('woocommerce_before_order_itemmeta', function($item_id, $item, $product) use ($resolve_sku, $resolve_img){
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
            // error_log('[SW-UI] item_id='.$item_id.' resolved_sku='.$sku);
            echo '<div class="sw-aff-sku" data-sw-sku="'.esc_attr($sku).'" style="font-size:12px;margin-top:4px;">SKU: <strong>'.esc_html($sku).'</strong></div>';
        }
        echo '</div>';
    }, 9, 3);

    // 2) Hide duplicate visible meta rows (default SKU/image keys)
    add_filter('woocommerce_order_item_get_formatted_meta_data', function($meta){
        $drop = ['SKU','sku','Variation SKU','variation_sku','Variation Image URL','variation_image_url','Product Image','product_image','Product Image URL','product_image_url'];
        $out = [];
        foreach ($meta as $m){ if (!in_array($m->display_key, $drop, true)) $out[] = $m; }
        return $out;
    }, 10, 1);
});
