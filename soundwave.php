<?php
/**
 * Plugin Name: Soundwave
 * Plugin URI: https://github.com/emkowale/soundwave
 * Description: Pushes WooCommerce orders from affiliate sites to thebeartraxs.com for production.
 * Version: 1.1.15
 * Requires at least: 6.0
 * Requires PHP: 7.4
 * Author: Eric Kowalewski
 * Author URI: https://erickowalewski.com/
 * License: GPL-2.0-or-later
 * License URI: https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain: soundwave
 * Domain Path: /languages
 * Update URI: https://github.com/emkowale/soundwave
 */
if (!defined('ABSPATH')) exit;

/* Updater — load at file scope */
$_sw_puc = __DIR__ . '/includes/vendor/plugin-update-checker/plugin-update-checker.php';
if (is_readable($_sw_puc)) {
    require_once $_sw_puc;
    $_sw_uc = \YahnisElsts\PluginUpdateChecker\v5\PucFactory::buildUpdateChecker(
        'https://github.com/emkowale/soundwave', // public repo (no .git)
        __FILE__,                                 // main plugin file
        'soundwave'                               // plugin folder slug
    );
    $_sw_uc->setBranch('main');
    if ($api = $_sw_uc->getVcsApi()) $api->enableReleaseAssets();
}

define('SOUNDWAVE_VERSION', '1.1.15');
define('SOUNDWAVE_PATH', plugin_dir_path(__FILE__));
define('SOUNDWAVE_URL', plugin_dir_url(__FILE__));

/* CSS with version cache-buster */
/*add_action('wp_enqueue_scripts', function () {
    wp_enqueue_style('soundwave', plugin_dir_url(__FILE__).'style.css', [], SOUNDWAVE_VERSION);
});
*/

require_once SOUNDWAVE_PATH . 'includes/bootstrap.php';

/* Add "Cheat Sheet" link on Plugins screen */
add_filter('plugin_action_links_' . plugin_basename(__FILE__), function($links) {
    $url = admin_url('admin.php?page=soundwave-cheatsheet');
    array_unshift($links, '<a href="' . esc_url($url) . '">Cheat Sheet</a>');
    return $links;
});






require_once SOUNDWAVE_PATH . 'includes/receiver/honor_totals.php';
