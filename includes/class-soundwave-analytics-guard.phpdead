<?php
/*
 * File: /includes/class-soundwave-analytics-guard.php
 * Description: Helpers to make Soundwave imports Analytics-safe (HPOS-ready).
 * Plugin: Soundwave
 * Author: Eric Kowalewski
 * Version: 1.1.30
 * Last Updated: 2025-11-20 15:30 EDT
 */

if ( ! defined('ABSPATH') ) { exit; }

/** Fixed placeholder product for unmapped affiliate items. */
if ( ! function_exists('sw_placeholder_product_id') ) {
    function sw_placeholder_product_id(): int {
        return 40158;
    }
}

/**
 * Add a single affiliate item to an order.
 * $src keys: name, sku, qty, subtotal, total, dest_product_id, dest_variation_id, image
 */
if ( ! function_exists('sw_add_affiliate_item') ) {
    function sw_add_affiliate_item( WC_Order $order, array $src ): void {
        $pid = ! empty( $src['dest_product_id'] )   ? (int) $src['dest_product_id']   : sw_placeholder_product_id();
        $vid = ! empty( $src['dest_variation_id'] ) ? (int) $src['dest_variation_id'] : 0;
        $qty = max( 1, (int) ( $src['qty'] ?? 1 ) );

        $item = new WC_Order_Item_Product();
        $item->set_product_id( $pid );
        $item->set_variation_id( $vid );
        $item->set_quantity( $qty );
        $item->set_subtotal( (float) ( $src['subtotal'] ?? 0 ) );
        $item->set_total( (float) ( $src['total'] ?? 0 ) );

        if ( ! empty( $src['name'] ) )  {
            $item->add_meta_data( '_sw_source_name', (string) $src['name'] );
        }
        if ( ! empty( $src['sku'] ) )   {
            $item->add_meta_data( '_sw_source_sku', (string) $src['sku'] );
        }
        if ( ! empty( $src['image'] ) ) {
            $item->add_meta_data( '_sw_source_image', (string) $src['image'] );
        }

        $item->add_meta_data( '_sw_origin', 'soundwave' );
        $order->add_item( $item );
    }
}

/**
 * Finalize an order so it appears in Woo Analytics immediately.
 * $ctx keys: created_at, paid_at (e.g., '2025-09-10 16:20:29')
 */
if ( ! function_exists('sw_finalize_order_for_analytics') ) {
    function sw_finalize_order_for_analytics( WC_Order $order, array $ctx = [] ): void {
        // Dates
        if ( ! empty( $ctx['created_at'] ) && function_exists( 'wc_string_to_datetime' ) ) {
            $order->set_date_created( wc_string_to_datetime( $ctx['created_at'] ) );
        }
        if ( ! empty( $ctx['paid_at'] ) && function_exists( 'wc_string_to_datetime' ) ) {
            $order->set_date_paid( wc_string_to_datetime( $ctx['paid_at'] ) );
        }

        $order->set_created_via( 'soundwave' );
        $order->calculate_totals();
        $order->save();

        // IMPORTANT: default hub status for Soundwave imports
        $order->update_status( 'on-hold', 'Created by Soundwave import (On hold).' );

        // Ensure lookup tables are updated (HPOS/classic safe).
        if ( function_exists( 'wc_update_order_stats' ) ) {
            wc_update_order_stats( $order->get_id() );
        }

        // Invalidate Woo Admin (reports) cache across versions.
        if ( class_exists( \Automattic\WooCommerce\Admin\API\Reports\Cache::class ) ) {
            $cls = \Automattic\WooCommerce\Admin\API\Reports\Cache::class;
            if ( method_exists( $cls, 'invalidate' ) ) {
                $cls::invalidate();
            } elseif ( method_exists( $cls, 'invalidate_all' ) ) {
                $cls::invalidate_all();
            }
        }

        if ( class_exists( 'WC_Cache_Helper' ) ) {
            WC_Cache_Helper::incr_cache_prefix( 'woocommerce_reports' );
        }
    }
}
