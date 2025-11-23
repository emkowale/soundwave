<?php
if ( ! defined('ABSPATH') ) exit;

add_action('wp_ajax_soundwave_mark_unsynced', function(){
    if ( ! current_user_can('edit_shop_orders') ) wp_send_json_error(['message'=>'forbidden'], 403);
    $order_id = isset($_POST['order_id']) ? absint($_POST['order_id']) : 0;
    if ( ! $order_id ) wp_send_json_error(['message'=>'missing order_id'], 400);

    delete_post_meta($order_id, '_soundwave_synced');
    delete_post_meta($order_id, '_soundwave_hub_id');
    delete_post_meta($order_id, '_soundwave_last_response_code');
    $nonce = wp_create_nonce('soundwave_sync_'.$order_id);

    wp_send_json_success(['ok'=>true, 'nonce'=>$nonce]);
});

add_action('admin_print_footer_scripts', function(){
    $screen = get_current_screen();
    if ( ! $screen ) return;
    $sid = (string) $screen->id;
    if ( stripos($sid, 'shop_order') === false && stripos($sid, 'wc-orders') === false ) return;

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
        $(document).on('click','.sw-sync',function(e){
            e.preventDefault();
            var $btn=$(this), oid=$btn.data('order-id'), nonce=$btn.data('nonce');
            var $cell = $('.sw-cell-simple[data-order-id="'+oid+'"]');
            $btn.prop('disabled', true).text('Syncing…');
            $.post(ajaxurl, {action:'soundwave_sync_order', order_id: oid, _ajax_nonce: nonce})
            .done(function(res){
                if (res && res.success) {
                    $cell.attr('data-synced','1').empty().append('<span class="sw-ok">Synced</span>');
                } else {
                    $btn.prop('disabled', false).text('Sync');
                }
            })
            .fail(function(){ $btn.prop('disabled', false).text('Sync'); });
        });

        $(function(){
            function checkSyncedRows(){
                var rows = $('.column-soundwave .sw-cell-simple[data-synced="1"]');
                if(!rows.length) return;
                var ids = [];
                rows.each(function(){ var id=parseInt($(this).data('order-id'),10); if(id) ids.push(id); });
                if (!ids.length) return;

                $.post(ajaxurl, {action:'soundwave_check_status', order_ids: ids})
                .done(function(res){
                    if(!res || !res.success || !res.data || !res.data.results) return;
                    var results = res.data.results || {};
                    Object.keys(results).forEach(function(oid){
                        var r = (results[oid] && typeof results[oid] === 'object') ? results[oid] : {};
                        var status = (r.status || (r.data && r.data.status)) || '';
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
            checkSyncedRows();
            setInterval(checkSyncedRows, 15000);
        });

    })(jQuery);
    </script>
    <?php
});
