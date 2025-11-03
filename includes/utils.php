<?php
/*
 * File: includes/utils.php
 * Purpose: Shared helpers for Soundwave (pure helpers; small, self-contained)
 */
if ( ! defined('ABSPATH') ) exit;

/** Return the first non-empty value in $map found by scanning $candidates (case-insensitive). */
if ( ! function_exists('soundwave_first_value_from_candidates') ) {
    function soundwave_first_value_from_candidates( array $map, array $candidates ) {
        foreach ($candidates as $k) {
            foreach ([$k, strtolower($k), strtoupper($k)] as $ck) {
                if (array_key_exists($ck, $map)) {
                    $val = $map[$ck];
                    if (is_array($val)) $val = reset($val);
                    if ($val !== '' && $val !== null) return $val;
                }
            }
        }
        return null;
    }
}

/** Build a flat meta map for a WC line item, merged with variation + parent meta. */
if ( ! function_exists('swv_item_meta_map') ) {
    function swv_item_meta_map( WC_Order_Item_Product $item ) {
        $map = [];

        // 1) Line-item meta
        foreach ($item->get_meta_data() as $m) {
            $d = $m->get_data(); if (empty($d['key'])) continue;
            $k = (string)$d['key']; $v = $d['value'];
            $map[$k] = is_scalar($v) ? $v : (is_array($v) ? wp_json_encode($v) : (string)$v);
        }

        // 2) Variation attributes (e.g., pa_color/pa_size)
        $vid = (int) $item->get_variation_id();
        if ($vid > 0 && ($v = wc_get_product($vid)) instanceof WC_Product_Variation) {
            foreach ((array)$v->get_attributes() as $tax => $val) {
                $map['attribute_'.$tax] = $val;
                $map[$tax] = $val;
            }
            // Variation-level meta (non-private only) as fallback
            foreach ($v->get_meta_data() as $m) {
                $d = $m->get_data(); if (empty($d['key'])) continue;
                $k = (string)$d['key'];
                if (!isset($map[$k])) {
                    $val = $d['value'];
                    $map[$k] = is_scalar($val) ? $val : (is_array($val) ? wp_json_encode($val) : (string)$val);
                }
            }
        }

        // 3) Product meta (the object returned by get_product may be variation or parent)
        if ($p = $item->get_product()) {
            foreach ($p->get_meta_data() as $m) {
                $d = $m->get_data(); if (empty($d['key'])) continue;
                $k = (string)$d['key'];
                if (!isset($map[$k])) {
                    $val = $d['value'];
                    $map[$k] = is_scalar($val) ? $val : (is_array($val) ? wp_json_encode($val) : (string)$val);
                }
            }
            // 4) Parent meta (CRITICAL: pulls keys like "Original Art Front")
            if (method_exists($p,'get_parent_id')) {
                $parent_id = (int) $p->get_parent_id();
                if ($parent_id > 0 && ($parent = wc_get_product($parent_id))) {
                    foreach ($parent->get_meta_data() as $m) {
                        $d = $m->get_data(); if (empty($d['key'])) continue;
                        $k = (string)$d['key'];
                        if (!isset($map[$k])) {
                            $val = $d['value'];
                            $map[$k] = is_scalar($val) ? $val : (is_array($val) ? wp_json_encode($val) : (string)$val);
                        }
                    }
                }
            }
        }

        return $map;
    }
}


/** Basic config (Settings-first; quiet fallback to constants if needed). */
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

/** Normalize a Woo REST base endpoint to the Orders collection and to a specific order URL. */
if ( ! function_exists('soundwave_orders_endpoint') ) {
    function soundwave_orders_endpoint(string $base): string {
        $base = rtrim($base, '/');
        if (preg_match('~/wc/v\d+/?$~', $base)) return $base.'/orders';
        if (!preg_match('~/wc/v\d+/orders$~', $base)) return $base; // don’t guess further
        return $base;
    }
}
if ( ! function_exists('soundwave_order_url') ) {
    function soundwave_order_url(string $base, $hub_id): string {
        $orders = soundwave_orders_endpoint($base);
        return rtrim($orders, '/').'/'.rawurlencode((string)$hub_id);
    }
}

/**
 * Check if the hub still has the record for this order’s stored hub_id.
 * Returns array: ['status'=>'synced'|'stale'|'unknown','code'=>int]
 * Used by the Orders-list JS to flip green rows back to "Sync" when deleted upstream.
 */
if ( ! function_exists('soundwave_check_hub_status') ) {
    function soundwave_check_hub_status( int $order_id ) {
        $synced    = get_post_meta($order_id, '_soundwave_synced', true);
        $synced_at = (int) get_post_meta($order_id, '_soundwave_synced_at', true);

        // Keep it green briefly right after a successful sync
        if ((string)$synced === '1' && (time() - $synced_at) < 90) {
            return ['status' => 'synced', 'code' => 200];
        }

        // Resolve hub order id from any known source (most specific last response wins if needed)
        $hub_id = get_post_meta($order_id, '_soundwave_hub_id', true);
        if ($hub_id === '' || $hub_id === null) {
            $hub_id = get_post_meta($order_id, '_soundwave_dest_order_id', true);
        }
        if ($hub_id === '' || $hub_id === null) {
            $hub_id = get_post_meta($order_id, '_hub_order_id', true);
        }
        if ($hub_id === '' || $hub_id === null) {
            $last = get_post_meta($order_id, '_soundwave_last_response_body', true);
            if (is_string($last) && $last !== '') {
                $json = json_decode($last, true);
                if (json_last_error() === JSON_ERROR_NONE && is_array($json) && isset($json['id'])) {
                    $hub_id = (string) $json['id'];
                }
            }
        }

        // If we still have no hub id, or never marked synced, report unsynced w/o remote call
        if ($hub_id === '' || $hub_id === null) {
            return ['status' => 'unsynced', 'code' => 0];
        }

        // We have a hub id — check the hub regardless of _soundwave_synced flag
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
                return ['status'=>'stale','code'=>200]; // trashed on hub → flip to Sync
            }
            return ['status'=>'synced','code'=>200];
        }

        if ($code === 404 || $code === 410) return ['status'=>'stale','code'=>$code]; // gone
        if (in_array($code, [401,403], true)) return ['status'=>'unknown','code'=>$code]; // auth
        return ['status'=>'unknown','code'=>$code];
    }
}
