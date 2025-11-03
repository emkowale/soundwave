<?php
/*
 * File: includes/class-finalizer.php
 * Description: Ensures Soundwave orders meet ShipStation requirements.
 * Plugin: Soundwave — Order Sync
 * Author: Eric Kowalewski
 * Last Updated: 2025-10-28 (EDT)
 */

if ( ! defined('ABSPATH') ) exit;

class Soundwave_Finalizer {

    /**
     * Normalize a string value (fallback if empty).
     */
    protected static function ensure_string($v, $fallback = 'Unknown'){
        $v = (string)$v;
        return (trim($v) === '') ? $fallback : $v;
    }

    /**
     * Main entry: fix order meta, line items, totals, and timestamps.
     *
     * @param int $order_id
     * @param int $placeholder_product_id  Placeholder for missing/invalid product links
     */
    public static function finalize_for_shipstation( $order_id, $placeholder_product_id = 40158 ) {
        global $wpdb;
        $order_id = (int)$order_id;
        if ( ! $order_id ) return;

        // 1) Force status + touch modified times
        $wpdb->query( $wpdb->prepare(
            "UPDATE {$wpdb->posts}
             SET post_status='wc-processing', post_modified=NOW(), post_modified_gmt=UTC_TIMESTAMP()
             WHERE ID=%d", $order_id
        ));

        // 2) Ensure _paid_date exists
        $has_paid = $wpdb->get_var( $wpdb->prepare(
            "SELECT COUNT(*) FROM {$wpdb->postmeta} WHERE post_id=%d AND meta_key='_paid_date'", $order_id
        ));
        if ( ! $has_paid ) {
            $wpdb->insert( $wpdb->postmeta, [
                'post_id' => $order_id,
                'meta_key' => '_paid_date',
                'meta_value' => current_time('mysql', true),
            ]);
        }

        // 3) Validate line items (product_id, qty, line_total)
        $rows = $wpdb->get_results( $wpdb->prepare(
            "SELECT oi.order_item_id,
                    m1.meta_value AS product_id,
                    m2.meta_value AS qty,
                    m3.meta_value AS line_total
             FROM {$wpdb->prefix}woocommerce_order_items oi
             LEFT JOIN {$wpdb->prefix}woocommerce_order_itemmeta m1
               ON m1.order_item_id=oi.order_item_id AND m1.meta_key='_product_id'
             LEFT JOIN {$wpdb->prefix}woocommerce_order_itemmeta m2
               ON m2.order_item_id=oi.order_item_id AND m2.meta_key='_qty'
             LEFT JOIN {$wpdb->prefix}woocommerce_order_itemmeta m3
               ON m3.order_item_id=oi.order_item_id AND m3.meta_key='_line_total'
             WHERE oi.order_id=%d AND oi.order_item_type='line_item'", $order_id
        ));

        $sum = 0.0;
        foreach ( $rows as $r ) {
            $pid  = (int)$r->product_id;
            $qty  = max( 1, (float)$r->qty );
            $line = max( 0.01, (float)$r->line_total );
            $sum += $line;

            if ( ! $pid ) {
                $wpdb->update( $wpdb->prefix.'woocommerce_order_itemmeta',
                    [ 'meta_value' => $placeholder_product_id ],
                    [ 'order_item_id' => $r->order_item_id, 'meta_key' => '_product_id' ],
                    [ '%d' ], [ '%d', '%s' ]
                );
            }
            $wpdb->update( $wpdb->prefix.'woocommerce_order_itemmeta',
                [ 'meta_value' => $qty ],
                [ 'order_item_id' => $r->order_item_id, 'meta_key' => '_qty' ],
                [ '%f' ], [ '%d', '%s' ]
            );
            $wpdb->update( $wpdb->prefix.'woocommerce_order_itemmeta',
                [ 'meta_value' => number_format($line,2,'.','') ],
                [ 'order_item_id' => $r->order_item_id, 'meta_key' => '_line_total' ],
                [ '%s' ], [ '%d', '%s' ]
            );
        }

        // 4) Sync header _order_total with sum of line totals
        // (replace to avoid stale duplicates)
        $wpdb->query( $wpdb->prepare(
            "DELETE FROM {$wpdb->postmeta} WHERE post_id=%d AND meta_key='_order_total'", $order_id
        ));
        $wpdb->insert( $wpdb->postmeta, [
            'post_id' => $order_id,
            'meta_key' => '_order_total',
            'meta_value' => number_format($sum,2,'.',''),
        ]);

        // 5) Ensure required shipping/billing fields are present (fallbacks if empty)
        $required = [
            '_shipping_address_1', '_shipping_city', '_shipping_postcode',
            '_shipping_country', '_billing_email'
        ];
        foreach ( $required as $key ) {
            $val = $wpdb->get_var( $wpdb->prepare(
                "SELECT meta_value FROM {$wpdb->postmeta}
                 WHERE post_id=%d AND meta_key=%s LIMIT 1", $order_id, $key
            ));
            if ( ! $val || trim((string)$val) === '' ) {
                $wpdb->replace( $wpdb->postmeta, [
                    'post_id' => $order_id,
                    'meta_key' => $key,
                    'meta_value' => self::ensure_string($val, 'Unknown'),
                ]);
            }
        }
    }
}
