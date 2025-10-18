<?php
/*
 * File: includes/remote/class-sw-remote-status.php
 * Description: Remote verification for per-page status (checks thebeartraxs.com via Woo REST).
 * Plugin: Soundwave
 * Version: 1.2.0
 * Last Updated: 2025-09-27
 */
if (!defined('ABSPATH')) exit;

class SW_Remote_Status {
    public static function init() {
        add_filter('soundwave/check_remote_synced', [__CLASS__, 'check'], 10, 2);
    }

    /**
     * Returns true if the remote order exists on destination, false otherwise.
     * Expects a stored remote order ID on the local order.
     */
    public static function check($default, $order_id) {
        $remote_id = self::get_remote_id($order_id);
        if (!$remote_id) return false;

        $creds = self::get_creds();
        if (!$creds) return false;

        $url = rtrim($creds['base'], '/') . '/wp-json/wc/v3/orders/' . intval($remote_id);
        $url = add_query_arg([
            'consumer_key'    => $creds['ck'],
            'consumer_secret' => $creds['cs'],
        ], $url);

        $res = wp_remote_get($url, [
            'timeout'   => 10,
            'headers'   => ['Accept' => 'application/json'],
            'redirection' => 3,
        ]);
        if (is_wp_error($res)) return false;
        if ((int) wp_remote_retrieve_response_code($res) !== 200) return false;

        $body = json_decode(wp_remote_retrieve_body($res), true);
        return !empty($body['id']);
    }

    private static function get_remote_id($order_id) {
        foreach (['_soundwave_dest_order_id','_soundwave_destination_order_id','_soundwave_remote_id'] as $k) {
            $v = get_post_meta($order_id, $k, true);
            if (!empty($v)) return $v;
        }
        return null;
    }

    private static function get_creds() {
        $base = get_option('soundwave_dest_url') ?: get_option('soundwave_destination_url') ?: get_option('soundwave_api_base');
        $ck   = get_option('soundwave_dest_ck')  ?: get_option('soundwave_ck');
        $cs   = get_option('soundwave_dest_cs')  ?: get_option('soundwave_cs');
        $base = is_string($base) ? trim($base) : '';
        $ck   = is_string($ck)   ? trim($ck)   : '';
        $cs   = is_string($cs)   ? trim($cs)   : '';
        if ($base && $ck && $cs) return ['base'=>$base,'ck'=>$ck,'cs'=>$cs];
        return null;
    }
}
SW_Remote_Status::init();
