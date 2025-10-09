<?php
/* Admin thumbs: enqueue one JS that paints the order-item thumbnail from product_image_full. */
defined('ABSPATH') || exit;

class SW_Admin_Thumbs {
  public static function init() {
    if (!is_admin()) return;
    add_action('admin_enqueue_scripts', [__CLASS__, 'enqueue'], 20);
  }
  public static function enqueue($hook) {
    // Load on all Woo admin screens; the JS exits fast if not an order page.
    wp_enqueue_script(
      'soundwave-admin-thumbs',
      SOUNDWAVE_URL . 'assets/sw-admin-thumbs.js',
      [],
      defined('SOUNDWAVE_VERSION') ? SOUNDWAVE_VERSION : time(),
      true
    );
  }
}
SW_Admin_Thumbs::init();
