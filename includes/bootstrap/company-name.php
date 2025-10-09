<?php
/*
 * File: includes/bootstrap/company-name.php
 * Plugin: Soundwave
 * Description: Register `company-name` meta and display it in admin order screen.
 * Author: Eric Kowalewski
 * Version: 1.2.1
 * Last Updated: 2025-10-06 16:35 EDT
 */

if (!defined('ABSPATH')) exit;

/* Make `company-name` REST-visible for any downstream consumers. */
add_action('init', function () {
    register_post_meta('shop_order', 'company-name', array(
        'type'         => 'string',
        'single'       => true,
        'show_in_rest' => true,
        'auth_callback'=> '__return_true',
    ));
});

/* Show company-name on the order edit screen (admin). */
add_action('add_meta_boxes', function () {
    add_meta_box(
        'sw_company_name_box',
        __('Company Name (Soundwave)', 'soundwave'),
        function () {
            global $post;
            $order = wc_get_order($post->ID);
            if (!$order) return;
            $val = (string)$order->get_meta('company-name', true);
            echo '<p style="margin:0;"><strong>'.esc_html__('company-name', 'soundwave').':</strong> ';
            echo $val !== '' ? esc_html($val) : '<em>'.esc_html__('(empty)', 'soundwave').'</em>';
            echo '</p>';
        },
        'shop_order',
        'side',
        'default'
    );
});
