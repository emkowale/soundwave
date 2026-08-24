<?php
defined('ABSPATH') || exit;

/**
 * Whether the inline debug panel should show.
 * Stored as an option so you can toggle it from the Soundwave screen.
 * '1' = show, '0' = hide (default).
 */

function sw_debug_enabled_option() {
    $v = get_option('soundwave_debug_enabled', '0'); // default OFF so managers aren't confused
    return $v === '1';
}

if (defined('SW_INLINE_DEBUG') && SW_INLINE_DEBUG && sw_debug_enabled_option()) {
/*    add_action('woocommerce_admin_order_data_after_order_details', function($order){
        if (!$order instanceof WC_Order) return;

        $fields = [
            SW_META_STATUS        => 'Status',
            SW_META_LAST_AT       => 'Last Sync At',
            SW_META_HTTP_CODE     => 'HTTP Code',
            SW_META_HTTP_BODY     => 'Response Body',
            SW_META_LAST_ERR      => 'Error',
            SW_META_DEBUG_JSON    => 'Request JSON',
        ];

        echo '<div class="soundwave-debug-panel" style="margin:16px 0;padding:12px;border:1px solid #ccd0d4;background:#f7f7f7;">';
        echo '<h3 style="margin:0 0 10px;">Soundwave Debug</h3>';
        $has = false;

        foreach ($fields as $k => $label) {
            $v = get_post_meta($order->get_id(), $k, true);
            if (!$v) continue;
            $has = true;
            echo '<h4 style="margin:8px 0 4px;">' . esc_html($label) . '</h4>';
            echo '<pre style="white-space:pre-wrap;background:#fff;border:1px solid #ddd;padding:8px;max-height:320px;overflow:auto;">' . esc_html($v) . '</pre>';
        }

        if (!$has) {
            echo '<p style="margin:0;">No Soundwave debug data yet. Try a manual Sync, then refresh.</p>';
        }
        echo '</div>';
    }, 20);
*/
}
