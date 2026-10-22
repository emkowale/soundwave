<?php
if ( ! defined('ABSPATH') ) exit;

function soundwave_sender_validate_order(int $order_id, WC_Order $order){
    if ($order->get_status() === 'trash') {
        return new WP_Error('order_trashed','Order is in trash and cannot sync.');
    }
    if ( ! function_exists('soundwave_validate_order_required_fields') ) return null;

    $vr   = soundwave_validate_order_required_fields( $order );
    $errs = (array)($vr['errors'] ?? []);
    if ( empty($errs) ) return null;

    $by = [];
    foreach ($errs as $e) {
        if (preg_match('~^Item\s+#(\d+):\s*(.+)$~i', (string)$e, $m)) $by[(int)$m[1]][] = trim($m[2]);
        else $by[0][] = trim((string)$e);
    }

    $skuFor = []; $i=0;
    foreach ($order->get_items('line_item') as $it){ if(!($it instanceof WC_Order_Item_Product)) continue;
        $i++; $p=$it->get_product(); $skuFor[$i]=($p&&method_exists($p,'get_sku'))?(string)$p->get_sku():'';
    }

    $lines = ['Soundwave sync failed — order not synced.'];
    foreach ($by as $n=>$msgs){
        $sku = $skuFor[$n] ?? '';
        $lines[] = "Missing/invalid: Item #{$n}".($sku? " (SKU {$sku})":'');
        foreach ($msgs as $m) $lines[] = $m;
    }
    $lines[] = 'Fix the fields above, then click Sync again.';
    $note = implode("\n", $lines);

    if ( function_exists('soundwave_note_once') ) soundwave_note_once($order_id,'soundwave_validation_failed',$note);
    else $order->add_order_note($note);

    update_post_meta($order_id,'_soundwave_last_error',$note);
    update_post_meta($order_id,'_soundwave_synced','0');
    return new WP_Error('soundwave_validation_failed','validation failed',['fields'=>$errs]);
}
