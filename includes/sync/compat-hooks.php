<?php
if ( ! defined('ABSPATH') ) exit;

/**
 * Ensure get_option('soundwave_settings') always returns BOTH
 * the new keys (endpoint/consumer_*) and legacy keys (api_*).
 * Any code reading either style sees populated values.
 */
add_filter('option_soundwave_settings', function($opt){
    $opt = is_array($opt) ? $opt : [];

    $endpoint = '';
    if (isset($opt['endpoint']) && $opt['endpoint'] !== '') $endpoint = trim((string)$opt['endpoint']);
    if ($endpoint === '' && isset($opt['api_endpoint']))     $endpoint = trim((string)$opt['api_endpoint']);

    $ck = '';
    if (isset($opt['consumer_key']) && $opt['consumer_key'] !== '') $ck = trim((string)$opt['consumer_key']);
    if ($ck === '' && isset($opt['api_key']))                        $ck = trim((string)$opt['api_key']);

    $cs = '';
    if (isset($opt['consumer_secret']) && $opt['consumer_secret'] !== '') $cs = trim((string)$opt['consumer_secret']);
    if ($cs === '' && isset($opt['api_secret']))                          $cs = trim((string)$opt['api_secret']);

    // Write back both sets so callers of either schema succeed
    $opt['endpoint']        = $endpoint;
    $opt['consumer_key']    = $ck;
    $opt['consumer_secret'] = $cs;
    $opt['api_endpoint']    = $endpoint;
    $opt['api_key']         = $ck;
    $opt['api_secret']      = $cs;

    return $opt;
});
