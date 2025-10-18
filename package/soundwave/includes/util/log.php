<?php
defined('ABSPATH') || exit;

function sw_update_order_meta($order_id, $map) {
    foreach ($map as $k => $v) {
        if ($v === null || $v === '') {
            delete_post_meta($order_id, $k);
        } else {
            update_post_meta($order_id, $k, $v);
        }
    }
}
