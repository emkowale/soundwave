<?php
/*
 * File: includes/admin/class-sw-order-sync-column.php
 * Description: Adds "Order Sync" column (classic + wc-orders) with Sync/View or green "Synced".
 * Plugin: Soundwave (WooCommerce Order Sync)
 * Version: 1.2.0
 * Last Updated: 2025-09-27 21:45 EDT
 */
if (!defined('ABSPATH')) exit;

class SW_Order_Sync_Column {
    const META_FLAG = '_soundwave_synced';
    const NONCE_KEY = 'sw_sync_order_nonce';
    const COL_KEY   = 'soundwave_sync';

    public static function init() {
        // Legacy list table
        add_filter('manage_edit-shop_order_columns',        [__CLASS__, 'add_column_classic'], 20);
        add_action('manage_shop_order_posts_custom_column', [__CLASS__, 'render_cell_classic'], 10, 2);
        // New wc-orders screen
        add_filter('manage_woocommerce_page_wc-orders_columns',       [__CLASS__, 'add_column_hpos'], 20);
        add_action('manage_woocommerce_page_wc-orders_custom_column', [__CLASS__, 'render_cell_hpos'], 10, 2);
        // Assets
        add_action('admin_enqueue_scripts', [__CLASS__, 'enqueue']);
    }

    /** Add column on legacy list */
    public static function add_column_classic($cols){
        $new=[]; foreach($cols as $k=>$v){ $new[$k]=$v; if($k==='order_status') $new[self::COL_KEY]=__('Order Sync','soundwave'); }
        if(!isset($new[self::COL_KEY])) $new[self::COL_KEY]=__('Order Sync','soundwave'); return $new;
    }
    /** Add column on wc-orders screen */
    public static function add_column_hpos($cols){
        $new=[]; foreach($cols as $k=>$v){ $new[$k]=$v; if($k==='status') $new[self::COL_KEY]=__('Order Sync','soundwave'); }
        if(!isset($new[self::COL_KEY])) $new[self::COL_KEY]=__('Order Sync','soundwave'); return $new;
    }

    /** Render cell on legacy list */
    public static function render_cell_classic($column,$post_id){ if($column===self::COL_KEY) self::render_cell((int)$post_id); }
    /** Render cell on wc-orders */
    public static function render_cell_hpos($column,$item){
        if($column!==self::COL_KEY) return;
        $order_id = (is_object($item) && method_exists($item,'get_id')) ? (int)$item->get_id() : (int)$item;
        self::render_cell($order_id);
    }

    /** Shared renderer */
    private static function render_cell($order_id){
        if (get_post_meta($order_id,self::META_FLAG,true)==='yes'){
            echo '<span style="color:#1a7f37;font-weight:600;">'.esc_html__('Synced','soundwave').'</span>'; return;
        }
        $view = get_edit_post_link($order_id,''); $nonce=wp_create_nonce(self::NONCE_KEY);
        echo '<div class="sw-sync-actions">';
        echo '<button class="button button-primary sw-sync-btn" data-order="'.esc_attr($order_id).'" data-nonce="'.esc_attr($nonce).'">'.esc_html__('Sync','soundwave').'</button> ';
        echo '<a class="button" href="'.esc_url($view).'">'.esc_html__('View Order','soundwave').'</a>';
        echo '</div><small class="sw-sync-status"></small>';
    }

    /** Enqueue JS on either orders screen */
    public static function enqueue($hook){
        $screen = function_exists('get_current_screen') ? get_current_screen() : null;
        $sid    = $screen ? $screen->id : '';
        $is_orders = ($hook==='edit.php' && isset($_GET['post_type']) && $_GET['post_type']==='shop_order') || ($sid==='woocommerce_page_wc-orders');
        if(!$is_orders) return;

        $base = defined('SOUNDWAVE_URL') ? SOUNDWAVE_URL : plugin_dir_url(dirname(__FILE__,2));
        wp_enqueue_script('sw-admin-sync', $base.'assets/js/sw-admin-sync.js', ['jquery'], '1.2.0', true);
        wp_localize_script('sw-admin-sync','SW_SYNC',[
            'ajax'=>admin_url('admin-ajax.php'),
            'i18n'=>['ok'=>__('Synced','soundwave'),'err'=>__('Sync failed. See order notes/debug.','soundwave'),'busy'=>__('Syncing…','soundwave')],
        ]);
    }
}
SW_Order_Sync_Column::init();
