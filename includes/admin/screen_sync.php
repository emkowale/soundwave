<?php
defined('ABSPATH') || exit;

function soundwave_render_sync_screen() {
    if (!class_exists('WooCommerce')) {
        echo '<div class="notice notice-error"><p><strong>WooCommerce is not active.</strong></p></div>';
        return;
    }

    // Toggle: Show Debug on order screen (default OFF)
    if (!empty($_POST['sw_debug_toggle'])) {
        check_admin_referer('sw_debug_toggle');
        update_option('soundwave_debug_enabled', isset($_POST['sw_debug_enabled']) ? '1' : '0');
        echo '<div class="notice notice-success is-dismissible"><p>Soundwave Debug is now ' .
            (get_option('soundwave_debug_enabled','0') === '1' ? 'enabled' : 'disabled') . '.</p></div>';
    }

    // Health check: test hub connectivity + show last success + current settings
    $hc_status = 'Disconnected';
    $hc_detail = '';
    $code = 0;

    $ep = rtrim(SW_HUB_ENDPOINT, '/'); // usually .../orders
    $test_url = add_query_arg('per_page', '1', $ep);
    $resp = wp_remote_request($test_url, [
        'method'  => 'GET',
        'headers' => ['Authorization' => 'Basic ' . base64_encode(SW_HUB_KEY . ':' . SW_HUB_SECRET)],
        'timeout' => 10,
    ]);
    if (is_wp_error($resp)) {
        $hc_detail = esc_html($resp->get_error_message());
    } else {
        $code = intval(wp_remote_retrieve_response_code($resp));
        if ($code >= 200 && $code < 300) { $hc_status = 'Connected'; }
        else { $hc_detail = 'HTTP ' . $code; }
    }

    // Last success time across recent orders
    $orders = wc_get_orders(['limit'=>10,'orderby'=>'date','order'=>'DESC']);
    $last_ok = '';
    foreach ($orders as $o) {
        if (get_post_meta($o->get_id(), SW_META_STATUS, true) === 'success') {
            $last_ok = get_post_meta($o->get_id(), SW_META_LAST_AT, true);
            if ($last_ok) break;
        }
    }

    $debug_on = get_option('soundwave_debug_enabled', '0') === '1';

    echo '<div class="wrap"><h1>Soundwave — Manual Order Sync</h1>';
    echo '<div class="notice" style="padding:12px;margin:12px 0;border-left:4px solid '.($hc_status==='Connected'?'#46b450':'#d63638').'">';
    echo '<strong>Health Check:</strong> '.$hc_status;
    if ($hc_detail) echo ' &middot; <span style="color:#646970">'.$hc_detail.'</span>';
    if ($last_ok) echo '<br><span style="color:#646970">Last successful sync: '.esc_html($last_ok).'</span>';
    echo '<br><span style="color:#646970">Debug panel: '.($debug_on?'ON':'OFF').'</span>';
    echo '</div>';

    // Debug checkbox UI
    echo '<form method="post" style="margin:12px 0 20px">';
    wp_nonce_field('sw_debug_toggle');
    echo '<label><input type="checkbox" name="sw_debug_enabled" value="1" ' . checked($debug_on, true, false) . '> Show Debug on order screen</label> ';
    echo '<input type="hidden" name="sw_debug_toggle" value="1">';
    echo '<button class="button">Save</button>';
    echo '</form>';

    if (empty($orders)) { echo '<p>No orders found.</p></div>'; return; }

    echo '<table class="widefat striped"><thead><tr>';
    echo '<th>Order</th><th>Date</th><th>Total</th><th>Status</th><th>Action</th></tr></thead><tbody>';

    foreach ($orders as $order) {
        $order_id  = $order->get_id();
        $is_synced = (get_post_meta($order_id, SW_META_STATUS, true) === 'success');
        $edit_url  = admin_url('post.php?post=' . $order_id . '&action=edit');

        echo '<tr>';
        echo '<td>#' . esc_html($order_id) . '</td>';
        echo '<td>' . esc_html($order->get_date_created()->date('Y-m-d H:i')) . '</td>';
        echo '<td>' . wp_kses_post(wc_price($order->get_total())) . '</td>';
        echo '<td>' . sw_status_label($order_id) . '</td>';
        echo '<td><form method="post" style="display:inline;">';
        wp_nonce_field('sw_manual_sync');
        echo '<input type="hidden" name="sw_sync_order_id" value="' . esc_attr($order_id) . '">';
        if ($is_synced) {
            echo '<button type="button" class="button" disabled>Synced</button> ';
        } else {
            echo '<button type="submit" class="button button-primary" name="sw_manual_sync_submit" value="1">Sync</button> ';
        }
        echo '<a class="button" href="' . esc_url($edit_url) . '">View</a>';
        echo '</form></td></tr>';
    }

    echo '</tbody></table></div>';
}
