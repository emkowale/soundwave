<?php
if ( ! defined('ABSPATH') ) exit;

/**
 * Soundwave Orders column — SIMPLE + AUTO-VERIFY
 * - Synced: plain green "Synced" text (no halo).
 * - Not synced: "Sync" button.
 * - If a sync attempt failed: show both "Sync" and "Fix Order".
 * - On page load, any "Synced" rows are verified with the hub; if stale, flags
 *   are cleared and the UI flips to "Sync" automatically.
 */

/** ---------- Helpers ---------- */
function soundwave_sw_is_synced($order_id){
    $is_synced = get_post_meta($order_id, '_soundwave_synced', true);
    $hub_id    = get_post_meta($order_id, '_soundwave_hub_id', true);
    $last_code = (int) get_post_meta($order_id, '_soundwave_last_response_code', true);
    return (string)$is_synced === '1' && !empty($hub_id) && $last_code >= 200 && $last_code < 300;
}
function soundwave_sw_last_error($order_id){
    return (string) get_post_meta($order_id, '_soundwave_last_error', true);
}
function soundwave_sw_last_attempt($order_id){
    return (int) get_post_meta($order_id, '_soundwave_last_attempt', true);
}
function soundwave_sw_edit_url($order_id){
    return get_edit_post_link($order_id, '');
}

/** ---------- Cell renderer ---------- */
function soundwave_render_simple_cell($order_id){
    $synced    = soundwave_sw_is_synced($order_id);
    $last_err  = soundwave_sw_last_error($order_id);
    $attempt   = soundwave_sw_last_attempt($order_id);
    $nonce     = wp_create_nonce('soundwave_sync_'.$order_id);

    echo '<div class="sw-cell-simple" data-order-id="'.esc_attr($order_id).'" data-synced="'.($synced?'1':'0').'">';
    if ( $synced ) {
        echo '<span class="sw-ok">Synced</span>'; // green text only
    } else {
        echo '<button type="button" class="button sw-sync" data-order-id="'.esc_attr($order_id).'" data-nonce="'.esc_attr($nonce).'">Sync</button>';
        if ( $attempt && ! empty($last_err) ) {
            $url = soundwave_sw_edit_url($order_id);
            echo '<a class="button sw-fix" href="'.esc_url($url).'" aria-label="Fix Order">Fix Order</a>';
        }
    }
    echo '</div>';
}

/** ---------- Classic CPT list ---------- */
add_filter('manage_edit-shop_order_columns', function($cols){
    $cols['soundwave'] = __('Soundwave', 'soundwave');
    return $cols;
}, 20);

add_action('manage_shop_order_posts_custom_column', function($column, $post_id){
    if ( $column !== 'soundwave' ) return;
    soundwave_render_simple_cell($post_id);
}, 10, 2);

/** ---------- HPOS list ---------- */
add_filter('manage_woocommerce_page_wc-orders_columns', function($cols){
    $cols['soundwave'] = __('Soundwave', 'soundwave');
    return $cols;
}, 20);

add_action('manage_woocommerce_page_wc-orders_custom_column', function($column, $order){
    if ( $column !== 'soundwave' ) return;
    $order_id = is_object($order) && method_exists($order, 'get_id') ? $order->get_id() : (int)$order;
    if ( ! $order_id ) return;
    soundwave_render_simple_cell($order_id);
}, 10, 2);

/** ---------- Small AJAX to clear stale local flags (now returns nonce) ---------- */
add_action('wp_ajax_soundwave_mark_unsynced', function(){
    if ( ! current_user_can('edit_shop_orders') ) wp_send_json_error(['message'=>'forbidden'], 403);
    $order_id = isset($_POST['order_id']) ? absint($_POST['order_id']) : 0;
    if ( ! $order_id ) wp_send_json_error(['message'=>'missing order_id'], 400);

    delete_post_meta($order_id, '_soundwave_synced');
    delete_post_meta($order_id, '_soundwave_hub_id');
    delete_post_meta($order_id, '_soundwave_last_response_code');

    // NEW: return a fresh nonce so the rebuilt Sync button carries it immediately
    $nonce = wp_create_nonce('soundwave_sync_'.$order_id);

    wp_send_json_success(['ok'=>true, 'nonce'=>$nonce]);
});

/** ---------- Minimal mobile-first assets + auto-verify ---------- */
add_action('admin_print_footer_scripts', function(){
    $screen = get_current_screen();
    if ( ! $screen ) return;

    // Run on any screen id that contains shop_order or wc-orders
    $sid = (string) $screen->id;
    if ( stripos($sid, 'shop_order') === false && stripos($sid, 'wc-orders') === false ) return;

    // Tiny JS log so we can see the active screen id
    echo "<script>try{console.log('[Soundwave] screen.id =', ".json_encode($sid).");}catch(e){}</script>";

    ?>
    <style>
        .column-soundwave .sw-cell-simple{display:flex;align-items:center;gap:6px;flex-wrap:wrap}
        .column-soundwave .sw-ok{color:#057a55;font-weight:600;font-size:12px;line-height:1.8}
        .column-soundwave .button.sw-fix,
        .column-soundwave .button.sw-sync{padding:2px 8px;font-size:12px;line-height:1.8;border-radius:6px}
        @media (max-width:782px){
            .column-soundwave .sw-ok,
            .column-soundwave .button{font-size:11px;padding:2px 6px}
        }
    </style>
    <script>
    (function($){
        // Click to sync
        $(document).on('click','.sw-sync',function(e){
            e.preventDefault();
            var $btn=$(this), oid=$btn.data('order-id'), nonce=$btn.data('nonce');
            var $cell = $('.sw-cell-simple[data-order-id="'+oid+'"]');

            $btn.prop('disabled', true).text('Syncing…');

            $.post(ajaxurl, {action:'soundwave_sync_order', order_id: oid, _ajax_nonce: nonce})
            .done(function(res){
                // If server says OK, flip UI immediately; polling will maintain it.
                if (res && res.success) {
                    $cell.attr('data-synced','1').empty().append('<span class="sw-ok">Synced</span>');
                } else {
                    // Failed sync — restore button
                    $btn.prop('disabled', false).text('Sync');
                }
            })
            .fail(function(){
                // Network/unknown — restore button
                $btn.prop('disabled', false).text('Sync');
            });
        });


        // Auto-verify "Synced" rows now and every 15s (no page reload)
        $(function(){
            function checkSyncedRows(){
                try { console.log('[Soundwave] polling…'); } catch(e){}
                var rows = $('.column-soundwave .sw-cell-simple[data-synced="1"]');
                if(!rows.length) return; // nothing to do

                var ids = [];
                rows.each(function(){
                    var id = parseInt($(this).data('order-id'),10);
                    if (id) ids.push(id);
                });
                if (!ids.length) return;

                $.post(ajaxurl, {action:'soundwave_check_status', order_ids: ids})
                .done(function(res){
                    try { console.log('[Soundwave] check_status results:', res && res.data && res.data.results); } catch(e){}
                    if(!res || !res.success || !res.data || !res.data.results) return;

                    var results = res.data.results || {};
                    Object.keys(results).forEach(function(oid){
                        // Normalize nested structure (sometimes PHP sends under res.data.results, sometimes directly)
                        var r = (results[oid] && typeof results[oid] === 'object') ? results[oid] : {};
                        var status = (r.status || (r.data && r.data.status)) || '';
                        var code   = (r.code   || (r.data && r.data.code))   || '';
                        try { console.log('[Soundwave] hub check →', oid, 'status:', status, 'code:', code); } catch(e){}
                        if (String(status) === 'stale' || String(status) === 'missing') {
                            var $cell = $('.sw-cell-simple[data-order-id="'+oid+'"]');
                            if(!$cell.length) return;
                            $.post(ajaxurl, {action:'soundwave_mark_unsynced', order_id: oid})
                            .done(function(){
                                $cell.attr('data-synced','0').empty()
                                    .append('<button type="button" class="button sw-sync" data-order-id="'+oid+'" data-nonce="">Sync</button>');
                            });
                        }
                    });

                });
            }

            // Run immediately, then poll every 15 seconds
            checkSyncedRows();
            setInterval(checkSyncedRows, 15000);
        });

    })(jQuery);
    </script>
    <?php
});
