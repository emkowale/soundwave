<?php
if ( ! defined('ABSPATH') ) exit;

add_action('admin_enqueue_scripts', function(){
    if (!isset($_GET['page']) || $_GET['page'] !== 'soundwave-settings') return;

    $src = defined('SOUNDWAVE_URL')
        ? trailingslashit(SOUNDWAVE_URL) . 'assets/settings.js'
        : plugins_url('../assets/settings.js', __FILE__);

    wp_enqueue_script(
        'soundwave-settings',
        $src,
        ['jquery'],
        defined('SOUNDWAVE_VERSION') ? SOUNDWAVE_VERSION : '1.4.22',
        true
    );

    wp_localize_script('soundwave-settings', 'SOUNDWAVE_SETTINGS', [
        'ajax_url'      => admin_url('admin-ajax.php'),
        'action'        => 'soundwave_api_test',
        'nonce'         => wp_create_nonce('soundwave_api_test'),
        'buttonSel'     => '#sw-test-conn-btn',
        'statusSel'     => '#sw-test-conn-status',
        'spinSel'       => '#sw-test-conn-status .sw-spin',
        'resultSel'     => '#sw-test-conn-status .sw-result',
    ]);
});
