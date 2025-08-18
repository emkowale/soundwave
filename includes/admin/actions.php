<?php
defined('ABSPATH') || exit;

function sw_handle_manual_sync() {
    if (empty($_POST['sw_manual_sync_submit'])) return;
    check_admin_referer('sw_manual_sync');
    $order_id = isset($_POST['sw_sync_order_id']) ? intval($_POST['sw_sync_order_id']) : 0;
    if ($order_id > 0) {
        soundwave_sync_order_to_beartraxs($order_id, true);
        wp_safe_redirect(add_query_arg(['page'=>'soundwave-sync','sw_synced'=>$order_id], admin_url('admin.php')));
        exit;
    }
}
add_action('admin_init', 'sw_handle_manual_sync');
