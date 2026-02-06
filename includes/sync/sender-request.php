<?php
if ( ! defined('ABSPATH') ) exit;

function soundwave_sender_request(int $order_id, WC_Order $order, array $payload, array $cfg){
    $endpoint = trim((string)($cfg['endpoint'] ?? ''));
    $ck       = trim((string)($cfg['consumer_key'] ?? ''));
    $cs       = trim((string)($cfg['consumer_secret'] ?? ''));

    if ($endpoint === '') {
        $msg = 'Soundwave is not configured. Ask an administrator to add the Hub API Endpoint in Settings → Soundwave.';
        $order->add_order_note($msg);
        return new WP_Error('no_endpoint','missing endpoint');
    }

    $endpoint = rtrim($endpoint, "/");
    if (!preg_match('~/wc/v\\d+/orders$~', $endpoint) && preg_match('~/wc/v\\d+/?$~', $endpoint)) {
        $endpoint .= '/orders';
    }

    $ch = curl_init($endpoint);
    $headers = ['Content-Type: application/json','User-Agent: Soundwave/1.0 (+WooCommerce bridge)'];
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_POST           => true,
        CURLOPT_HTTPHEADER     => $headers,
        CURLOPT_POSTFIELDS     => wp_json_encode($payload),
        CURLOPT_TIMEOUT        => 25,
    ]);

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

    $hub_id = ($is_json && isset($data['id'])) ? (string)$data['id'] : '';
    $aff_id = (string) $order->get_id();
    update_post_meta($order_id,'_affiliate_meta_id',$aff_id);
    update_post_meta($order_id,'_soundwave_synced','1');
    update_post_meta($order_id,'_soundwave_last_error','');
    update_post_meta($order_id,'_soundwave_last_response_code',$status);
    update_post_meta($order_id,'_soundwave_hub_id',$hub_id);
    update_post_meta($order_id,'_soundwave_synced_at', time());

    $order->add_order_note('Soundwave: synced to hub'.($hub_id!=='' ? " (hub_id {$hub_id})" : ' (hub_id unknown)'));
    return ['ok'=>true,'status'=>$status,'hub_id'=>$hub_id,'data'=>$is_json?$data:$response];
}
