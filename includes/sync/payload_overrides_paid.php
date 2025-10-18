<?php
/* Soundwave: ensure destination gets a proper paid timestamp (<=100 lines) */
defined('ABSPATH') || exit;

/**
 * sw_payload_overrides($order, $payload): array
 * - Forces set_paid=true and supplies date_paid_gmt so Analytics (Date paid) works.
 * - Uses the order's paid time if present; falls back to created time.
 * - Leaves your chosen status (e.g., 'processing') untouched.
 */
function sw_payload_overrides( WC_Order $order, array $payload ): array {
    $payload['set_paid'] = true;

    // Prefer actual paid date; otherwise use created date
    $dt = $order->get_date_paid();
    if ( ! $dt ) $dt = $order->get_date_created();

    if ( $dt ) {
        // Format in GMT for REST (Y-m-d H:i:s)
        $payload['date_paid_gmt'] = $dt->date_i18n('Y-m-d H:i:s', true);
    }
    return $payload;
}
