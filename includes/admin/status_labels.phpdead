<?php
defined('ABSPATH') || exit;

function sw_status_label($order_id) {
    $status = get_post_meta($order_id, SW_META_STATUS, true);
    if ($status === 'success') return '<span style="color:#1e8e3e;font-weight:600;">✅ Synced</span>';
    if ($status === 'failed')  return '<span style="color:#d63638;font-weight:600;">❌ Failed</span>';
    return '<span style="color:#646970;font-weight:600;">⏳ Not Synced</span>';
}
