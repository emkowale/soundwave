<?php
defined('ABSPATH') || exit;

/* Utilities */
require_once __DIR__ . '/util/config.php';
require_once __DIR__ . '/util/env.php';
require_once __DIR__ . '/util/json.php';
require_once __DIR__ . '/util/log.php';

/* UI */
require_once __DIR__ . '/ui/debug_panel_inline.php';

/* Admin */
require_once __DIR__ . '/admin/menu.php';
require_once __DIR__ . '/admin/screen_sync.php';
require_once __DIR__ . '/admin/actions.php';
require_once __DIR__ . '/admin/status_labels.php';

/* Sync */
require_once __DIR__ . '/sync/dispatcher.php';
require_once __DIR__ . '/sync/validate.php';
require_once __DIR__ . '/sync/extract_attributes.php';
require_once __DIR__ . '/sync/extract_shipping.php';
require_once __DIR__ . '/sync/extract_coupons.php';
require_once __DIR__ . '/sync/extract_art.php';
require_once __DIR__ . '/sync/extract_images.php';
require_once __DIR__ . '/sync/line_builder_custom.php';
require_once __DIR__ . '/sync/payload_compose.php';
require_once __DIR__ . '/sync/http_send.php';
require_once __DIR__ . '/sync/record_status.php';
// Load Manual Order Sync admin screen
if ( is_admin() ) {
    require_once plugin_dir_path(__FILE__) . 'class-soundwave-admin-manual-sync.php';
}

require_once SOUNDWAVE_PATH . 'includes/util/order-meta.php';
require_once SOUNDWAVE_PATH . 'includes/bootstrap/company-name.php';
