<?php
if ( ! defined('ABSPATH') ) exit;

if ( ! function_exists('soundwave_get_settings') ) {
    function soundwave_get_settings() {
        $o = get_option('soundwave_settings', []);
        $endpoint = trim((string)($o['endpoint'] ?? $o['api_endpoint'] ?? ''));
        $ck       = trim((string)($o['consumer_key'] ?? $o['api_key'] ?? ''));
        $cs       = trim((string)($o['consumer_secret'] ?? $o['api_secret'] ?? ''));
        if ($endpoint === '' && defined('SW_HUB_ENDPOINT')) $endpoint = (string)SW_HUB_ENDPOINT;
        if ($ck       === '' && defined('SW_HUB_KEY'))      $ck       = (string)SW_HUB_KEY;
        if ($cs       === '' && defined('SW_HUB_SECRET'))   $cs       = (string)SW_HUB_SECRET;
        return ['endpoint'=>$endpoint,'consumer_key'=>$ck,'consumer_secret'=>$cs];
    }
}

if ( ! function_exists('soundwave_orders_endpoint') ) {
    function soundwave_orders_endpoint(string $base): string {
        $base = rtrim($base, '/');
        if (preg_match('~/wc/v\\d+/?$~', $base)) return $base.'/orders';
        if (!preg_match('~/wc/v\\d+/orders$~', $base)) return $base;
        return $base;
    }
}

if ( ! function_exists('soundwave_order_url') ) {
    function soundwave_order_url(string $base, $hub_id): string {
        $orders = soundwave_orders_endpoint($base);
        return rtrim($orders, '/').'/'.rawurlencode((string)$hub_id);
    }
}
