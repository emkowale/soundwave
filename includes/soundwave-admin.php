<?php
/*
 * File: /includes/soundwave-admin.php
 * Purpose: Restore the "Soundwave" item in the left WP Admin menu.
 */

if ( ! defined('ABSPATH') ) { exit; }

/** Register a top-level "Soundwave" menu. */
add_action('admin_menu', function () {
  add_menu_page(
    'Soundwave',                     // Page title
    'Soundwave',                     // Menu title
    'manage_woocommerce',            // Capability
    'soundwave',                     // Slug
    'soundwave_admin_screen',        // Callback
    'dashicons-megaphone',           // Icon
    56                               // Position (near WooCommerce)
  );
});

/** Very simple admin screen (status only, no new features). */
function soundwave_admin_screen() {
  if ( ! current_user_can('manage_woocommerce') ) { wp_die('Insufficient permissions.'); }
  $health = rest_url('soundwave/v1/health');
  ?>
  <div class="wrap">
    <h1>Soundwave</h1>
    <p><strong>Status:</strong> Plugin active.</p>
    <p><strong>Version:</strong> <?php echo esc_html( defined('SOUNDWAVE_VERSION') ? SOUNDWAVE_VERSION : 'n/a' ); ?></p>
    <p><strong>Health endpoint:</strong> <a href="<?php echo esc_url($health); ?>" target="_blank" rel="noopener"><?php echo esc_html($health); ?></a></p>
    <hr/>
    <p>If you can see this page, the admin menu is restored. No settings are changed here.</p>
  </div>
  <?php
}
