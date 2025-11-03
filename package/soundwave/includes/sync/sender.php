<?php
if ( ! defined('ABSPATH') ) exit;

/**
 * File: includes/sync/sender.php
 * Purpose: Validation gate → payload → POST with proper Woo REST auth → actionable notes/meta
 */

if ( ! function_exists('soundwave_send_to_hub') ) {
function soundwave_send_to_hub( int $order_id ) {
    $order = wc_get_order( $order_id );
    if ( ! $order ) return new WP_Error('order_not_found', 'Order not found.');

    // === 1) VALIDATION (plain, worker-friendly) ===============================
    if ( function_exists('soundwave_validate_order_required_fields') ) {
        $vr   = soundwave_validate_order_required_fields( $order );
        $errs = (array)($vr['errors'] ?? []);
        if ( ! empty($errs) ) {
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
    }

    // === 2) PAYLOAD (supports either builder) =================================
    $payload = null; $builder_err = null;
    $payload = null; $builder_err = null;

    if ( function_exists('sw_compose_payload') ) {
        $payload = sw_compose_payload($order);          // ✅ Woo REST shape: line_items, meta_data, etc.
        if ( is_wp_error($payload) ) $builder_err = $payload;
    } elseif ( function_exists('soundwave_prepare_order_payload') ) {
        $payload = soundwave_prepare_order_payload($order); // legacy/custom shape (fallback only)
        if ( is_wp_error($payload) ) $builder_err = $payload;
    } else {
        $builder_err = new WP_Error('payload_builder_missing','Payload builder not available');
    }
    if ( is_wp_error($builder_err) ) {
        $edata   = (array)$builder_err->get_error_data();
        $missing = [];
        foreach (['missing','missing_fields','invalid_fields','errors'] as $k) {
            if (!empty($edata[$k]) && is_array($edata[$k])) $missing = array_merge($missing, array_map('strval',$edata[$k]));
        }
        $missing = array_values(array_unique(array_filter($missing, fn($s)=>trim($s)!=='')));

        $note = empty($missing)
            ? "Soundwave sync failed — order not synced.\nA required order detail couldn’t be built. Review the product’s Custom Fields and required variation selections (Color, Size), then click Sync again."
            : "Soundwave sync failed — order not synced.\nMissing/invalid (per product):\n• ".implode("\n• ", $missing)."\nFix the fields above, then click Sync again.";

        if ( function_exists('soundwave_note_once') ) soundwave_note_once($order_id,'soundwave_payload_error',$note);
        else $order->add_order_note($note);

        update_post_meta($order_id,'_soundwave_last_error',$note);
        update_post_meta($order_id,'_soundwave_synced','0');
        return $builder_err;
    }

    // === 3) CONFIG + ENDPOINT NORMALIZATION ===================================
    $cfg = function_exists('soundwave_get_settings') ? soundwave_get_settings() : [];
    $endpoint = trim((string)($cfg['endpoint'] ?? ''));
    $ck       = trim((string)($cfg['consumer_key'] ?? ''));
    $cs       = trim((string)($cfg['consumer_secret'] ?? ''));

    if ($endpoint === '') {
        $msg = 'Soundwave is not configured. Ask an administrator to add the Hub API Endpoint in Settings → Soundwave.';
        $order->add_order_note($msg);
        return new WP_Error('no_endpoint','missing endpoint');
    }

    // Ensure we POST to the Orders collection
    $endpoint = rtrim($endpoint, "/");
    if (!preg_match('~/wc/v\d+/orders$~', $endpoint)) {
        // If they supplied the base (…/wc/v3) or trailing slash, append /orders
        if (preg_match('~/wc/v\d+/?$~', $endpoint)) $endpoint .= '/orders';
        // Common mistake: extra path pieces or missing /orders — make no other guesses
    }

    // === 4) SEND with AUTH (Basic ck:cs) ======================================
    $ch = curl_init($endpoint);
    $headers = ['Content-Type: application/json','User-Agent: Soundwave/1.0 (+WooCommerce bridge)'];
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_POST           => true,
        CURLOPT_HTTPHEADER     => $headers,
        CURLOPT_POSTFIELDS     => wp_json_encode($payload),
        CURLOPT_TIMEOUT        => 25,
    ]);

    // Woo REST: HTTPS allows Basic Auth with ck:cs
    if ($ck !== '' && $cs !== '') {
        curl_setopt($ch, CURLOPT_HTTPAUTH, CURLAUTH_BASIC);
        curl_setopt($ch, CURLOPT_USERPWD, $ck . ':' . $cs);
    }

    $response = curl_exec($ch);
    $status   = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $error    = curl_error($ch);
    curl_close($ch);

    if ($error) {
        $msg = 'Soundwave sync failed — network error. Please try again or notify an administrator.';
        $order->add_order_note($msg);
        update_post_meta($order_id,'_soundwave_last_error',$msg);
        update_post_meta($order_id,'_soundwave_last_response_code',$status);
        update_post_meta($order_id,'_soundwave_synced','0');
        return new WP_Error('curl_error',$error);
    }

    $data = json_decode($response, true);
    $is_json = (json_last_error() === JSON_ERROR_NONE);

    // === 5) HUB REJECTION → actionable, not vague =============================
    if ($status < 200 || $status >= 300) {
        $reason = '';
        $field_list = [];
        if ($is_json && is_array($data)) {
            foreach (['error','message','detail','description'] as $k) {
                if (!empty($data[$k]) && is_string($data[$k])) { $reason = (string)$data[$k]; break; }
            }
            foreach (['missing','missing_fields','invalid_fields','errors','details'] as $k) {
                if (!empty($data[$k]) && is_array($data[$k])) {
                    foreach ($data[$k] as $v) {
                        if (is_string($v)) $field_list[] = $v;
                        elseif (is_array($v)) {
                            if (!empty($v['field'])) $field_list[] = (string)$v['field'];
                            elseif (!empty($v['name'])) $field_list[] = (string)$v['name'];
                        }
                    }
                }
            }
        }

        $why = match ($status) {
            400 => 'Some required details are missing or invalid.',
            401,403 => 'Authentication failed or the key lacks permission to create orders.',
            404 => 'Hub Orders endpoint was not found.',
            409 => 'This order already exists on the hub (duplicate).',
            422 => 'Some fields did not pass validation.',
            500,502,503,504 => 'Hub is temporarily unavailable.',
            default => 'Hub rejected the order.',
        };

        // If auth codes, guide the admin with real fixes (not “check your keys” hand-waving)
        $admin_help = ($status === 401 || $status === 403) ? "\nAdmin tips:\n• Make sure the URL ends with /wp-json/wc/v3/orders\n• The Consumer Key must be **Read/Write** and belong to a user with **manage_woocommerce**.\n• If you changed the key owner’s role, regenerate new keys.\n" : '';

        $lines = ["Soundwave sync failed — order not synced.","Reason: {$why} (HTTP {$status})"];
        if ($reason !== '') $lines[] = $reason;
        $field_list = array_values(array_unique(array_filter(array_map('strval',$field_list), fn($s)=>trim($s)!=='')));
        if (!empty($field_list)) {
            $lines[] = 'Please fix:';
            foreach ($field_list as $f) $lines[] = '• '.$f;
        }
        $lines[] = 'After fixing, click Sync again.';
        if ($admin_help) $lines[] = $admin_help;
        $note = implode("\n", $lines);

        $order->add_order_note($note);
        update_post_meta($order_id,'_soundwave_last_error',$note);
        update_post_meta($order_id,'_soundwave_last_response_code',$status);
        update_post_meta($order_id,'_soundwave_synced','0');

        return new WP_Error('hub_reject', $reason !== '' ? $reason : 'hub rejected', ['status'=>$status,'fields'=>$field_list]);
    }

    // === 6) SUCCESS ===========================================================
    $hub_id = ($is_json && isset($data['id'])) ? (string)$data['id'] : '';
    update_post_meta($order_id,'_soundwave_synced','1');
    update_post_meta($order_id,'_soundwave_last_error','');
    update_post_meta($order_id,'_soundwave_last_response_code',$status);
    update_post_meta($order_id,'_soundwave_hub_id',$hub_id);
    update_post_meta($order_id,'_soundwave_synced_at', time());

    $order->add_order_note('Soundwave: synced to hub'.($hub_id!=='' ? " (hub_id {$hub_id})" : ' (hub_id unknown)'));
    return ['ok'=>true,'status'=>$status,'hub_id'=>$hub_id,'data'=>$is_json?$data:$response];
}}
