<?php
if ( ! defined('ABSPATH') ) exit;

if ( ! function_exists('soundwave_sender_status_normalize') ) {
function soundwave_sender_status_normalize($status): string {
    $s = strtolower(trim((string)$status));
    if ($s === 'on_hold') $s = 'on-hold';
    return $s;
}}

if ( ! function_exists('soundwave_sender_status_is_local_only') ) {
function soundwave_sender_status_is_local_only($status): bool {
    $s = soundwave_sender_status_normalize($status);
    return ($s === 'setup');
}}

if ( ! function_exists('soundwave_sender_request_curl_json') ) {
function soundwave_sender_request_curl_json(string $method, string $url, array $body, string $ck, string $cs): array {
    $ch = curl_init($url);
    $headers = ['Content-Type: application/json','User-Agent: Soundwave/1.0 (+WooCommerce bridge)'];
    $opts = [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_HTTPHEADER     => $headers,
        CURLOPT_POSTFIELDS     => wp_json_encode($body),
        CURLOPT_TIMEOUT        => 25,
    ];
    if ($method === 'POST') {
        $opts[CURLOPT_POST] = true;
    } else {
        $opts[CURLOPT_CUSTOMREQUEST] = $method;
    }
    curl_setopt_array($ch, $opts);

    if ($ck !== '' && $cs !== '') {
        curl_setopt($ch, CURLOPT_HTTPAUTH, CURLAUTH_BASIC);
        curl_setopt($ch, CURLOPT_USERPWD, $ck . ':' . $cs);
    }

    $raw   = curl_exec($ch);
    $code  = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $error = curl_error($ch);
    curl_close($ch);

    $json = json_decode((string)$raw, true);
    $ok_json = (json_last_error() === JSON_ERROR_NONE && is_array($json));
    return [
        'code'  => $code,
        'error' => $error,
        'raw'   => $raw,
        'json'  => $ok_json ? $json : null,
    ];
}}

if ( ! function_exists('soundwave_sender_force_remote_status') ) {
function soundwave_sender_force_remote_status(string $orders_endpoint, string $hub_id, string $target_status, string $ck, string $cs): array {
    $orders_endpoint = rtrim($orders_endpoint, "/");
    $target_url = $orders_endpoint . '/' . rawurlencode((string)$hub_id);
    return soundwave_sender_request_curl_json('PUT', $target_url, ['status' => $target_status], $ck, $cs);
}}

function soundwave_sender_request(int $order_id, WC_Order $order, array $payload, array $cfg){
    $endpoint = trim((string)($cfg['endpoint'] ?? ''));
    $ck       = trim((string)($cfg['consumer_key'] ?? ''));
    $cs       = trim((string)($cfg['consumer_secret'] ?? ''));

    if ($endpoint === '') {
        $msg = 'Soundwave is not configured. Ask an administrator to add the Hub API Endpoint in Settings → Soundwave.';
        $order->add_order_note($msg);
        return new WP_Error('no_endpoint','missing endpoint');
    }

    // Business rule: never send "processing" to hub.
    if (isset($payload['status']) && strtolower((string)$payload['status']) === 'processing') {
        $payload['status'] = 'on-hold';
    }

    $desired_status = soundwave_sender_status_normalize($payload['status'] ?? '');
    $set_paid       = !empty($payload['set_paid']);
    $create_status  = $desired_status;

    // Keep local-only statuses off the Hub API; preserve local setup metadata/notes.
    if (soundwave_sender_status_is_local_only($desired_status)) {
        $create_status = 'on-hold';
    }
    if ($set_paid && $create_status === 'processing') {
        $create_status = 'on-hold';
    }
    if ($create_status !== '') {
        $payload['status'] = $create_status;
    }

    $endpoint = rtrim($endpoint, "/");
    if (!preg_match('~/wc/v\\d+/orders$~', $endpoint) && preg_match('~/wc/v\\d+/?$~', $endpoint)) {
        $endpoint .= '/orders';
    }

    $create = soundwave_sender_request_curl_json('POST', $endpoint, $payload, $ck, $cs);
    $response = $create['raw'];
    $status   = (int) $create['code'];
    $error    = (string) $create['error'];

    if ($error) {
        $msg = 'Soundwave sync failed — network error. Please try again or notify an administrator.';
        $order->add_order_note($msg);
        update_post_meta($order_id,'_soundwave_last_error',$msg);
        update_post_meta($order_id,'_soundwave_last_response_code',$status);
        update_post_meta($order_id,'_soundwave_synced','0');
        return new WP_Error('curl_error',$error);
    }

    $data = is_array($create['json']) ? $create['json'] : json_decode((string)$response, true);
    $is_json = is_array($data);

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

    // Ensure final business status after payment_complete side effects.
    // Example: paid orders may auto-transition to processing/completed.
    if ($hub_id !== '' && $desired_status !== '' && !soundwave_sender_status_is_local_only($desired_status)) {
        $remote_status = '';
        if ($is_json && isset($data['status'])) {
            $remote_status = soundwave_sender_status_normalize($data['status']);
        }
        if ($remote_status !== $desired_status) {
            $force = soundwave_sender_force_remote_status($endpoint, $hub_id, $desired_status, $ck, $cs);
            if (empty($force['error']) && (int)$force['code'] >= 200 && (int)$force['code'] < 300) {
                if (is_array($force['json'])) {
                    $data = $force['json'];
                    $is_json = true;
                }
            } else {
                $warn = 'Soundwave: order synced but could not force final hub status to '
                    . $desired_status . '. Please review hub order #' . $hub_id . '.';
                $order->add_order_note($warn);
            }
        }
    }

    $aff_id = (string) $order->get_id();
    update_post_meta($order_id,'_affiliate_meta_id',$aff_id);
    update_post_meta($order_id,'_soundwave_synced','1');
    update_post_meta($order_id,'_soundwave_last_error','');
    update_post_meta($order_id,'_soundwave_last_response_code',$status);
    update_post_meta($order_id,'_soundwave_hub_id',$hub_id);
    update_post_meta($order_id,'_soundwave_synced_at', time());

    $payload_status = $desired_status !== '' ? $desired_status : strtolower((string)($payload['status'] ?? ''));
    if ($payload_status === 'setup') {
        update_post_meta($order_id, '_soundwave_setup_marked', '1');
        delete_post_meta($order_id, '_soundwave_setup_released');

        if ((string)get_post_meta($order_id, '_soundwave_setup_note_added', true) !== '1') {
            $msg = 'Soundwave: routed to Setup (first-time product based on total_sales).';
            if (function_exists('soundwave_setup_first_sale_products') && function_exists('soundwave_setup_product_summary')) {
                $first_sale = soundwave_setup_first_sale_products($order);
                $summary = soundwave_setup_product_summary($first_sale);
                if ($summary !== '') {
                    $msg .= ' ' . $summary;
                }
            }
            $order->add_order_note($msg);
            update_post_meta($order_id, '_soundwave_setup_note_added', '1');
        }
    } elseif ((string)get_post_meta($order_id, '_soundwave_setup_marked', true) === '1') {
        if (strtolower((string)$order->get_status()) !== 'setup') {
            update_post_meta($order_id, '_soundwave_setup_released', '1');
        }
    }

    $order->add_order_note('Soundwave: synced to hub'.($hub_id!=='' ? " (hub_id {$hub_id})" : ' (hub_id unknown)'));
    $popup_state = function_exists('soundwave_popup_manifest_theme_state') ? soundwave_popup_manifest_theme_state() : [];
    if (empty($popup_state['is_popup']) && $order->get_status() !== 'completed') {
        $order->update_status('completed', 'Soundwave: confirmed by Rumble.');
    }
    return ['ok'=>true,'status'=>$status,'hub_id'=>$hub_id,'data'=>$is_json?$data:$response];
}
