<?php
defined('ABSPATH') || exit;

add_action('admin_menu', function() {
    add_menu_page(
        'Soundwave Sync',
        'Soundwave',
        'manage_woocommerce',
        'soundwave-sync',
        'soundwave_render_sync_screen',
        'dashicons-update',
        56
    );
    add_submenu_page(
        'soundwave-sync',
        'Soundwave Cheat Sheet',
        'Cheat Sheet',
        'manage_woocommerce',
        'soundwave-cheatsheet',
        'soundwave_render_cheatsheet_screen'
    );
});
