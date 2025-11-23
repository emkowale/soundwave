<?php
if ( ! defined('ABSPATH') ) exit;

function soundwave_default_settings(){
    return [
        'endpoint'        => 'https://thebeartraxs.com/wp-json/wc/v3/orders',
        'consumer_key'    => '',
        'consumer_secret' => '',
    ];
}

function soundwave_settings_page(){
    if ( ! current_user_can('manage_woocommerce') ) return;

    $opts = get_option('soundwave_settings', soundwave_default_settings());

    if ( isset($_POST['soundwave_save']) && check_admin_referer('soundwave_save') ){
        $opts['endpoint']        = esc_url_raw( $_POST['endpoint'] ?? '' );
        $opts['consumer_key']    = sanitize_text_field( $_POST['consumer_key'] ?? '' );
        $opts['consumer_secret'] = sanitize_text_field( $_POST['consumer_secret'] ?? '' );
        update_option('soundwave_settings', $opts);
        echo '<div class="updated"><p>Settings saved.</p></div>';
    }

    $version = defined('SOUNDWAVE_VERSION') ? SOUNDWAVE_VERSION : 'dev';

    echo '<div class="wrap"><h1>Soundwave Settings <span style="color:#777;font-weight:normal;">v' . esc_html($version) . '</span></h1><form method="post">';
    wp_nonce_field('soundwave_save');
    echo '<table class="form-table">';
    echo '<tr><th><label for="endpoint">Hub Endpoint</label></th><td><input type="url" class="regular-text code" id="endpoint" name="endpoint" value="'.esc_attr($opts['endpoint']).'"></td></tr>';
    echo '<tr><th><label for="consumer_key">Consumer Key</label></th><td><input type="password" class="regular-text" id="consumer_key" name="consumer_key" value="'.esc_attr($opts['consumer_key']).'" autocomplete="new-password"></td></tr>';
    echo '<tr><th><label for="consumer_secret">Consumer Secret</label></th><td><input type="password" class="regular-text" id="consumer_secret" name="consumer_secret" value="'.esc_attr($opts['consumer_secret']).'" autocomplete="new-password"></td></tr>';
    echo '</table>';
    submit_button('Save Settings', 'primary', 'soundwave_save');

    echo '<p style="margin-top:12px;">';
    echo '<button type="button" class="button" id="sw-test-conn-btn">Test API Connection</button> ';
    echo '<span id="sw-test-conn-status" style="display:inline-flex;align-items:center;gap:6px;margin-left:8px;">';
    echo '  <span class="sw-spin" style="display:none;width:16px;height:16px;border:2px solid #ccd0d4;border-top-color:#2271b1;border-radius:50%;animation:swspin 0.7s linear infinite;"></span>';
    echo '  <span class="sw-result" style="font-weight:600;"></span>';
    echo '</span>';
    echo '</p>';
    echo '<style>@keyframes swspin{to{transform:rotate(360deg);}}</style>';
    echo '<p>Manual sync from Orders list; real-time reconciliation keeps status accurate.</p>';
    echo '</form></div>';
}

add_action('admin_menu', function(){
    add_menu_page(
        'Soundwave',
        'Soundwave',
        'manage_woocommerce',
        'soundwave-settings',
        'soundwave_settings_page',
        'dashicons-megaphone',
        56
    );
});
