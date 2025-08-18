<?php
defined('ABSPATH') || exit;

/**
 * Coupons are affiliate-site specific and usually don't exist on the hub.
 * To avoid 400 "invalid coupon" errors, we do NOT send coupon_lines.
 * The line item totals and shipping totals we send already reflect discounts.
 *
 * @param WC_Order $order
 * @return array
 */
function sw_extract_coupons($order) {
    return [];
}
