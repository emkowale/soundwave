<?php
if ( ! defined('ABSPATH') ) exit;

/** Normalize settings across old/new keys */
if ( ! function_exists('soundwave_get_settings_compat') ) {
    function soundwave_get_settings_compat() {
        $o = get_option('soundwave_settings', []);
        $endpoint = isset($o['endpoint'])        ? trim((string)$o['endpoint'])        : '';
        $ck       = isset($o['consumer_key'])    ? trim((string)$o['consumer_key'])    : '';
        $cs       = isset($o['consumer_secret']) ? trim((string)$o['consumer_secret']) : '';

        if ($endpoint === '' && isset($o['api_endpoint'])) $endpoint = trim((string)$o['api_endpoint']);
        if ($ck       === '' && isset($o['api_key']))      $ck       = trim((string)$o['api_key']);
        if ($cs       === '' && isset($o['api_secret']))   $cs       = trim((string)$o['api_secret']);

        return ['endpoint'=>$endpoint,'consumer_key'=>$ck,'consumer_secret'=>$cs];
    }
}

/** Friendly mapping for common HTTP auth errors */
if ( ! function_exists('soundwave_human_auth_error') ) {
    function soundwave_human_auth_error( $code ) {
        if ( $code === 401 || $code === 403 ) return 'API authentication failed — check Hub Consumer Key & Secret in Soundwave → Settings.';
        if ( $code === 404 ) return 'Hub Orders endpoint not found — verify the Hub Orders endpoint URL in Soundwave → Settings.';
        return '';
    }
}

// Legacy shim so ANY code calling soundwave_get_settings() gets the compat values
if ( ! function_exists('soundwave_get_settings') ) {
    function soundwave_get_settings() {
        return soundwave_get_settings_compat();
    }
}

// Legacy shim: any code calling soundwave_get_settings() gets compat values
if ( ! function_exists('soundwave_get_settings') ) {
    function soundwave_get_settings() {
        return soundwave_get_settings_compat();
    }
}

// Ensure any direct get_option('soundwave_settings') callers see BOTH key styles filled
add_filter('option_soundwave_settings', function($opt){
    $opt = is_array($opt) ? $opt : [];

    $endpoint = '';
    if (!empty($opt['endpoint']))     $endpoint = trim((string)$opt['endpoint']);
    if ($endpoint === '' && !empty($opt['api_endpoint'])) $endpoint = trim((string)$opt['api_endpoint']);

    $ck = '';
    if (!empty($opt['consumer_key'])) $ck = trim((string)$opt['consumer_key']);
    if ($ck === '' && !empty($opt['api_key']))           $ck = trim((string)$opt['api_key']);

    $cs = '';
    if (!empty($opt['consumer_secret'])) $cs = trim((string)$opt['consumer_secret']);
    if ($cs === '' && !empty($opt['api_secret']))        $cs = trim((string)$opt['api_secret']);

    // Populate both schemas so any caller succeeds
    $opt['endpoint']        = $endpoint;
    $opt['consumer_key']    = $ck;
    $opt['consumer_secret'] = $cs;
    $opt['api_endpoint']    = $endpoint;
    $opt['api_key']         = $ck;
    $opt['api_secret']      = $cs;

    return $opt;
});
