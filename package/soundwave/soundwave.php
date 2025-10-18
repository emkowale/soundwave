<?php
/*
Plugin Name: Soundwave
Plugin URI: https://github.com/emkowale/soundwave
Description: Pushes WooCommerce orders from affiliate/source sites to thebeartraxs.com (“hub”) via WooCommerce REST API.
	.4.21
Requires at least: 6.0
Requires PHP: 7.2
Author: Eric Kowalewski
License: GPL2
*/

if ( ! defined('ABSPATH') ) exit;

	.4.21');
define('SOUNDWAVE_DIR', plugin_dir_path(__FILE__));
define('SOUNDWAVE_URL', plugin_dir_url(__FILE__));

add_action('plugins_loaded', function(){
    if ( ! class_exists('WooCommerce') ) {
        deactivate_plugins(plugin_basename(__FILE__));
        add_action('admin_notices', function(){
            echo '<div class="notice notice-error"><p><strong>Soundwave requires WooCommerce.</strong> The plugin was deactivated.</p></div>';
        });
        return;
    }
    require_once SOUNDWAVE_DIR . 'includes/admin.php';
    require_once SOUNDWAVE_DIR . 'includes/utils.php';
    require_once SOUNDWAVE_DIR . 'includes/sync.php';
    require_once SOUNDWAVE_DIR . 'includes/updater.php';
});
