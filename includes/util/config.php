<?php
defined('ABSPATH') || exit;

/* Feature flags & constants */
if (!defined('SW_INLINE_DEBUG')) define('SW_INLINE_DEBUG', true);
if (!defined('SW_REQUIRE_RENDERED_ART')) define('SW_REQUIRE_RENDERED_ART', false);
if (!defined('SW_SKIP_ON_SUCCESS')) define('SW_SKIP_ON_SUCCESS', true);
if (!defined('SW_STRICT_VALIDATION')) define('SW_STRICT_VALIDATION', true);

/* Mode: 'custom_lines' (no hub IDs) */
if (!defined('SW_MODE')) define('SW_MODE', 'custom_lines');

/* Keys for origin meta */
if (!defined('SW_META_ORIGIN')) define('SW_META_ORIGIN', '_order_origin');
if (!defined('SW_META_ORIGIN_ORDER')) define('SW_META_ORIGIN_ORDER', '_origin_order_id');
if (!defined('SW_META_ORIGIN_CUSTOMER')) define('SW_META_ORIGIN_CUSTOMER', '_origin_customer');

/* Status meta keys */
if (!defined('SW_META_STATUS')) define('SW_META_STATUS', '_soundwave_sync_status');
if (!defined('SW_META_DEBUG_JSON')) define('SW_META_DEBUG_JSON', '_soundwave_debug_json');
if (!defined('SW_META_LAST_AT')) define('SW_META_LAST_AT', '_soundwave_last_sync_at');
if (!defined('SW_META_HTTP_CODE')) define('SW_META_HTTP_CODE', '_soundwave_last_response_code');
if (!defined('SW_META_HTTP_BODY')) define('SW_META_HTTP_BODY', '_soundwave_last_response_body');
if (!defined('SW_META_LAST_ERR')) define('SW_META_LAST_ERR', '_soundwave_last_error');

define('SW_DEST_BASE', 'https://thebeartraxs.com');
define('SW_DEST_CK',   'ck_4a31310d973ac0d46c85e47ce70cdfa22f5ad794'); // ← replace if needed
define('SW_DEST_CS',   'cs_a04ab86297d7b91b0c826c84982bd4119bfb5aeb');  // ← replace