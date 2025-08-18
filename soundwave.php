<?php
/*
 * Plugin Name: Soundwave
 * Description: Pushes WooCommerce orders from affiliate sites to thebeartraxs.com for production.
 * Author: Eric Kowalewski
 * Version: 1.1.10
 * Requires Plugins: woocommerce
 * Last Updated: 2025-08-17 00:25 
 */

defined('ABSPATH') || exit;

define('SOUNDWAVE_VERSION', '1.1.10');
define('SOUNDWAVE_PATH', plugin_dir_path(__FILE__));
define('SOUNDWAVE_URL', plugin_dir_url(__FILE__));

require_once SOUNDWAVE_PATH . 'includes/bootstrap.php';

/* Add "Cheat Sheet" link on Plugins screen */
add_filter('plugin_action_links_' . plugin_basename(__FILE__), function($links) {
    $url = admin_url('admin.php?page=soundwave-cheatsheet');
    array_unshift($links, '<a href="' . esc_url($url) . '">Cheat Sheet</a>');
    return $links;
});
