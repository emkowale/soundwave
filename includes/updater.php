<?php
if ( ! defined('ABSPATH') ) exit;

/**
 * Soundwave lightweight GitHub updater
 * - Uses the Release Asset zip (soundwave-vX.Y.Z.zip), not zipball_url.
 * - Picks the highest semantic tag vX.Y.Z.
 * - Optional token via SOUNDWAVE_GITHUB_TOKEN to avoid rate limits.
 */

add_filter('pre_set_site_transient_update_plugins', function ($t) {
    if ( empty($t->checked) ) return $t;

    $plugin_file = plugin_basename( dirname(__FILE__, 2) . '/soundwave.php' );
    $current_ver = defined('SOUNDWAVE_VERSION') ? SOUNDWAVE_VERSION : '0.0.0';

    $headers = array(
        'Accept'     => 'application/vnd.github+json',
        // GitHub 403s requests without UA.
        'User-Agent' => 'soundwave-updater'
    );
    if ( defined('SOUNDWAVE_GITHUB_TOKEN') && SOUNDWAVE_GITHUB_TOKEN ) {
        $headers['Authorization'] = 'Bearer ' . SOUNDWAVE_GITHUB_TOKEN;
    }

    $resp = wp_remote_get(
        'https://api.github.com/repos/emkowale/soundwave/releases',
        array('timeout' => 15, 'headers' => $headers)
    );
    if ( is_wp_error($resp) || (int) wp_remote_retrieve_response_code($resp) !== 200 ) {
        return $t;
    }

    $releases = json_decode( wp_remote_retrieve_body($resp), true );
    if ( ! is_array($releases) ) return $t;

    // Pick latest vX.Y.Z tag
    $latest = null;
    foreach ($releases as $rel) {
        if ( empty($rel['tag_name']) ) continue;
        if ( !preg_match('/^v?\d+\.\d+\.\d+$/', $rel['tag_name']) ) continue;
        if ( !$latest || version_compare(ltrim($rel['tag_name'],'v'), ltrim($latest['tag_name'],'v'), '>') ) {
            $latest = $rel;
        }
    }
    if ( ! $latest ) return $t;

    $new_version = ltrim($latest['tag_name'], 'v');
    if ( version_compare($new_version, $current_ver, '<=') ) return $t;

    // Find the release asset zip we ship (soundwave-vX.Y.Z.zip)
    $asset_url = '';
    if ( !empty($latest['assets']) && is_array($latest['assets']) ) {
        foreach ($latest['assets'] as $asset) {
            $name = isset($asset['name']) ? $asset['name'] : '';
            if ( preg_match('/^soundwave-v\d+\.\d+\.\d+\.zip$/', $name) ) {
                $asset_url = $asset['browser_download_url'];
                break;
            }
        }
    }

    // Fallback (not recommended): if no asset found, do not offer update.
    if ( ! $asset_url ) return $t;

    $obj               = new stdClass();
    $obj->slug         = 'soundwave';                  // plugin dir name
    $obj->plugin       = $plugin_file;                 // full plugin file
    $obj->new_version  = $new_version;
    $obj->url          = 'https://github.com/emkowale/soundwave';
    $obj->package      = $asset_url;                   // <-- proper release asset zip
    // Optional cosmetics:
    $obj->tested       = get_bloginfo('version');
    $obj->requires_php = '7.4';

    $t->response[$plugin_file] = $obj;
    return $t;
});

add_filter('plugins_api', function ($res, $action, $args) {
    if ( $action !== 'plugin_information' || empty($args->slug) || $args->slug !== 'soundwave' ) {
        return $res;
    }
    $i = new stdClass();
    $i->name     = 'Soundwave';
    $i->slug     = 'soundwave';
    $i->version  = defined('SOUNDWAVE_VERSION') ? SOUNDWAVE_VERSION : '';
    $i->author   = 'Eric Kowalewski';
    $i->homepage = 'https://github.com/emkowale/soundwave';
    $i->sections = array(
        'description' => 'Push WooCommerce orders from affiliate/source sites to thebeartraxs.com hub.',
        'changelog'   => 'See GitHub Releases.',
    );
    return $i;
}, 10, 3);
