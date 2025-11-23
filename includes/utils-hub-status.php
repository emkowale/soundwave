<?php
if ( ! defined('ABSPATH') ) exit;

if ( ! function_exists('soundwave_check_hub_status') ) {
    function soundwave_check_hub_status( int $order_id ) {
        $synced    = get_post_meta($order_id, '_soundwave_synced', true);
        $synced_at = (int) get_post_meta($order_id, '_soundwave_synced_at', true);

        if ((string)$synced === '1' && (time() - $synced_at) < 90) {
            return ['status' => 'synced', 'code' => 200];
        }

        $hub_id = get_post_meta($order_id, '_soundwave_hub_id', true);
        if ($hub_id === '' || $hub_id === null) $hub_id = get_post_meta($order_id, '_soundwave_dest_order_id', true);
        if ($hub_id === '' || $hub_id === null) $hub_id = get_post_meta($order_id, '_hub_order_id', true);
        if ($hub_id === '' || $hub_id === null) {
            $last = get_post_meta($order_id, '_soundwave_last_response_body', true);
            if (is_string($last) && $last !== '') {
                $json = json_decode($last, true);
                if (json_last_error() === JSON_ERROR_NONE && is_array($json) && isset($json['id'])) {
                    $hub_id = (string) $json['id'];
                }
            }
        }

        if ($hub_id === '' || $hub_id === null) {
            return ['status' => 'unsynced', 'code' => 0];
        }

        $cfg  = soundwave_get_settings();
        $base = trim((string)($cfg['endpoint'] ?? ''));
        $ck   = trim((string)($cfg['consumer_key'] ?? ''));
        $cs   = trim((string)($cfg['consumer_secret'] ?? ''));

        if ($base === '' || $ck === '' || $cs === '') {
            return ['status'=>'unknown','code'=>0];
        }

        $url = soundwave_order_url($base, $hub_id);
        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_HTTPHEADER     => ['User-Agent: Soundwave/verify'],
            CURLOPT_TIMEOUT        => 15,
            CURLOPT_HTTPAUTH       => CURLAUTH_BASIC,
            CURLOPT_USERPWD        => $ck.':'.$cs,
        ]);
        $body   = curl_exec($ch);
        $code   = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $cerr   = curl_error($ch);
        curl_close($ch);

        if ($cerr) return ['status'=>'unknown','code'=>0];

        if ($code === 200) {
            $status_field = '';
            if (is_string($body) && $body !== '') {
                $json = json_decode($body, true);
                if (json_last_error() === JSON_ERROR_NONE && is_array($json) && isset($json['status'])) {
                    $status_field = strtolower((string)$json['status']);
                }
            }
            if ($status_field === 'trash') {
                return ['status'=>'stale','code'=>200];
            }
            return ['status'=>'synced','code'=>200];
        }

        if ($code === 404 || $code === 410) return ['status'=>'stale','code'=>$code];
        if (in_array($code, [401,403], true)) return ['status'=>'unknown','code'=>$code];
        return ['status'=>'unknown','code'=>$code];
    }
}
