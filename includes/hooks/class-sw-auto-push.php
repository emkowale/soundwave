<?php
/*
 * File: includes/hooks/class-sw-auto-push.php
 * Purpose: Auto-push orders to thebeartraxs.com on create/payment/status/save.
 * Notes: Runs for every order; skips if already _soundwave_synced = yes.
 */
if (!defined('ABSPATH')) exit;

class SW_Auto_Push {
    public static function init() {
        // Frontend checkout create
        add_action('woocommerce_checkout_order_processed', [__CLASS__, 'on_checkout'], 100, 3);
        // Payment completed
        add_action('woocommerce_payment_complete',        [__CLASS__, 'on_payment'],  100, 1);
        // Status change into processing/completed
        add_action('woocommerce_order_status_changed',    [__CLASS__, 'on_status'],   100, 4);
        // Fallback: any save in admin (classic)
        add_action('save_post_shop_order',                [__CLASS__, 'on_save'],      99, 3);
    }

    /* --- Gate --- */
    protected static function can_sync($order_id) {
        if (!$order_id) return false;
        // Don’t double-push if we’ve already marked it synced.
        if (get_post_meta($order_id, '_soundwave_synced', true) === 'yes') return false;
        return true;
    }

    /* --- Triggers --- */
    public static function on_checkout($order_id /*, $posted, $order */) {
        $order_id = (int)$order_id;
        if (!self::can_sync($order_id)) return;
        self::push($order_id, 'checkout');
    }

    public static function on_payment($order_id) {
        $order_id = (int)$order_id;
        if (!self::can_sync($order_id)) return;
        self::push($order_id, 'payment_complete');
    }

    public static function on_status($order_id, $old, $new, $order) {
        if (!self::can_sync($order_id)) return;
        if (in_array($new, ['processing','completed'], true)) {
            self::push((int)$order_id, 'status:' . $old . '->' . $new);
        }
    }

    public static function on_save($post_id, $post, $update) {
        if (($post->post_type ?? '') !== 'shop_order') return;
        if (defined('DOING_AUTOSAVE') && DOING_AUTOSAVE) return;
        if (!self::can_sync($post_id)) return;
        self::push((int)$post_id, 'save_post');
    }

    /* --- Core push + logging --- */
    protected static function push($order_id, $reason) {
        $debug = (get_option('soundwave_debug_mode') === 'yes');
        $ctx   = ['debug' => $debug, 'source' => 'auto:' . $reason];

        update_post_meta($order_id, '_soundwave_last_attempt', current_time('mysql'));

        $result = apply_filters('soundwave/run_sync_for_order', null, $order_id, $ctx);
        $order  = wc_get_order($order_id);

        if (is_wp_error($result)) {
            update_post_meta($order_id, '_soundwave_last_error', $result->get_error_message());
            if ($order) $order->add_order_note('Soundwave auto-sync failed — ' . $result->get_error_message());
            return;
        }

        $remote_id = '';
        if (is_array($result)) {
            foreach (['remote_id','id','order_id','dest_order_id'] as $k) {
                if (!empty($result[$k])) { $remote_id = (string)$result[$k]; break; }
            }
        }
        if (!$remote_id) $remote_id = get_post_meta($order_id, '_soundwave_dest_order_id', true);
        if ($remote_id) update_post_meta($order_id, '_soundwave_dest_order_id', $remote_id);

        update_post_meta($order_id, '_soundwave_synced', 'yes');
        delete_post_meta($order_id, '_soundwave_last_error');

        if ($order) $order->add_order_note('Soundwave auto-synced' . ($remote_id ? " (remote #{$remote_id})" : '') . '.');
    }
}
SW_Auto_Push::init();
