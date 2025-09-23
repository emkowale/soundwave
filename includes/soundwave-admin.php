<?php
/*
 * File: /includes/soundwave-admin.php
 * Purpose: Soundwave menu with Dashboard + Cheat Sheet.
 */
if ( ! defined('ABSPATH') ) exit;

/** Top-level + submenus */
add_action('admin_menu', function () {
  // Top-level
  add_menu_page(
    'Soundwave','Soundwave','manage_woocommerce','soundwave',
    'soundwave_render_sync_screen','dashicons-megaphone',56
  );
  // Dashboard points to the same renderer as top-level
  add_submenu_page(
    'soundwave','Dashboard','Dashboard','manage_woocommerce',
    'soundwave','soundwave_render_sync_screen'
  );
  // Existing Cheat Sheet screen (keep your current callback slug)
  add_submenu_page(
    'soundwave','Cheat Sheet','Cheat Sheet','manage_woocommerce',
    'soundwave-cheatsheet','soundwave_render_cheatsheet_screen'
  );
});

/**
 * Minimal fallback for Cheat Sheet callback if not defined elsewhere.
 * Remove if you already have the real screen_cheatsheet.php loaded.
 */
if ( ! function_exists('soundwave_render_cheatsheet_screen') ) {
  function soundwave_render_cheatsheet_screen() {
    echo '<div class="wrap"><h1>Soundwave — Cheat Sheet</h1><p>Coming soon.</p></div>';
  }
}
