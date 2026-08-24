<?php
if ( ! defined('ABSPATH') ) exit;

/*
 * Setup status + first-sale routing.
 * First-sale rule uses WooCommerce total_sales:
 * - Compute total quantity in this order per parent product family.
 * - Variable products are grouped by parent SKU/ID (not by variation SKU/ID).
 * - If total_sales <= order quantity for that same product key, treat as first-time.
 */

if ( ! function_exists('soundwave_register_setup_order_status') ) {
function soundwave_register_setup_order_status() {
    register_post_status('wc-setup', [
        'label'                     => _x('Setup', 'Order status', 'soundwave'),
        'public'                    => true,
        'exclude_from_search'       => false,
        'show_in_admin_all_list'    => true,
        'show_in_admin_status_list' => true,
        'label_count'               => _n_noop(
            'Setup <span class="count">(%s)</span>',
            'Setup <span class="count">(%s)</span>',
            'soundwave'
        ),
    ]);
}}
add_action('init', 'soundwave_register_setup_order_status');

if ( ! function_exists('soundwave_add_setup_to_order_statuses') ) {
function soundwave_add_setup_to_order_statuses( $statuses ) {
    if ( ! is_array($statuses) ) return $statuses;

    $out = [];
    $inserted = false;
    foreach ($statuses as $key => $label) {
        if (!$inserted && $key === 'wc-processing') {
            $out['wc-setup'] = __('Setup', 'soundwave');
            $inserted = true;
        }
        $out[$key] = $label;
    }
    if (!isset($out['wc-setup'])) $out['wc-setup'] = __('Setup', 'soundwave');
    return $out;
}}
add_filter('wc_order_statuses', 'soundwave_add_setup_to_order_statuses');

if ( ! function_exists('soundwave_setup_bulk_actions') ) {
function soundwave_setup_bulk_actions( $actions ) {
    if ( ! is_array($actions) ) return $actions;
    $actions['mark_setup'] = __('Change status to Setup', 'soundwave');
    return $actions;
}}
add_filter('bulk_actions-edit-shop_order', 'soundwave_setup_bulk_actions');
add_filter('bulk_actions-woocommerce_page_wc-orders', 'soundwave_setup_bulk_actions');

if ( ! function_exists('soundwave_setup_handle_bulk_action') ) {
function soundwave_setup_handle_bulk_action( $redirect_to, $action, $order_ids ) {
    if ($action !== 'mark_setup') return $redirect_to;
    if ( ! is_array($order_ids) ) return $redirect_to;

    $changed = 0;
    foreach ($order_ids as $order_id) {
        $order = wc_get_order((int)$order_id);
        if (!$order) continue;
        $order->update_status('setup', __('Marked as Setup by bulk action.', 'soundwave'), true);
        $changed++;
    }

    return add_query_arg('soundwave_setup_changed', (int)$changed, $redirect_to);
}}
add_filter('handle_bulk_actions-edit-shop_order', 'soundwave_setup_handle_bulk_action', 10, 3);
add_filter('handle_bulk_actions-woocommerce_page_wc-orders', 'soundwave_setup_handle_bulk_action', 10, 3);

if ( ! function_exists('soundwave_setup_bulk_notice') ) {
function soundwave_setup_bulk_notice() {
    if (!isset($_REQUEST['soundwave_setup_changed'])) return;
    $count = (int) $_REQUEST['soundwave_setup_changed'];
    if ($count <= 0) return;

    $msg = sprintf(_n('%d order marked Setup.', '%d orders marked Setup.', $count, 'soundwave'), $count);
    echo '<div class="notice notice-success is-dismissible"><p>' . esc_html($msg) . '</p></div>';
}}
add_action('admin_notices', 'soundwave_setup_bulk_notice');

if ( ! function_exists('soundwave_setup_analytics_include_status') ) {
function soundwave_setup_analytics_include_status( $statuses ) {
    if ( ! is_array($statuses) ) return $statuses;
    return array_values(array_filter($statuses, function($s){
        $s = strtolower(trim((string)$s));
        return ($s !== 'setup' && $s !== 'wc-setup');
    }));
}}
add_filter('woocommerce_analytics_excluded_order_statuses', 'soundwave_setup_analytics_include_status');

if ( ! function_exists('soundwave_setup_mark_released_on_status_change') ) {
function soundwave_setup_mark_released_on_status_change( $order_id, $old_status, $new_status ) {
    $old = strtolower((string)$old_status);
    $new = strtolower((string)$new_status);
    if ($old === 'setup' && $new !== 'setup') {
        update_post_meta((int)$order_id, '_soundwave_setup_released', '1');
    }
}}
add_action('woocommerce_order_status_changed', 'soundwave_setup_mark_released_on_status_change', 20, 3);

if ( ! function_exists('soundwave_setup_subject_product') ) {
function soundwave_setup_subject_product( WC_Order_Item_Product $item ) {
    $variation_id = (int) $item->get_variation_id();
    if ($variation_id > 0) {
        $variation = wc_get_product($variation_id);
        if ($variation instanceof WC_Product_Variation) {
            $parent_id = (int) $variation->get_parent_id();
            if ($parent_id > 0) {
                $parent = wc_get_product($parent_id);
                if ($parent instanceof WC_Product) return $parent;
            }
        }
        if ($variation instanceof WC_Product) return $variation;
    }
    $product = $item->get_product();
    if ($product instanceof WC_Product_Variation) {
        $parent_id = (int) $product->get_parent_id();
        if ($parent_id > 0) {
            $parent = wc_get_product($parent_id);
            if ($parent instanceof WC_Product) return $parent;
        }
    }
    return ($product instanceof WC_Product) ? $product : null;
}}

if ( ! function_exists('soundwave_setup_product_total_sales') ) {
function soundwave_setup_product_total_sales( WC_Product $product ) : int {
    $sales = null;
    if (method_exists($product, 'get_total_sales')) {
        $sales = $product->get_total_sales();
    }
    if ($sales === '' || $sales === null) {
        $sales = get_post_meta((int)$product->get_id(), 'total_sales', true);
    }
    return max(0, (int)$sales);
}}

if ( ! function_exists('soundwave_setup_first_sale_products') ) {
function soundwave_setup_first_sale_products( WC_Order $order ) : array {
    $rows = [];
    foreach ($order->get_items('line_item') as $item) {
        if (!($item instanceof WC_Order_Item_Product)) continue;

        $product = soundwave_setup_subject_product($item);
        if (!$product) continue;

        $pid = (int) $product->get_id();
        if ($pid <= 0) continue;

        $key = 'p:' . $pid;
        if (!isset($rows[$key])) {
            $rows[$key] = [
                'product_id'   => $pid,
                'sku'          => (string) (method_exists($product, 'get_sku') ? $product->get_sku() : ''),
                'name'         => (string) (method_exists($product, 'get_name') ? $product->get_name() : $item->get_name()),
                'qty'          => 0,
                'total_sales'  => soundwave_setup_product_total_sales($product),
            ];
        }

        $rows[$key]['qty'] += max(1, (int)$item->get_quantity());
    }

    $first_sale = [];
    foreach ($rows as $row) {
        if ((int)$row['total_sales'] <= (int)$row['qty']) {
            $first_sale[] = $row;
        }
    }

    return array_values($first_sale);
}}

if ( ! function_exists('soundwave_setup_should_apply') ) {
function soundwave_setup_should_apply( WC_Order $order, $first_sale_products = null ) : bool {
    if ($first_sale_products === null) {
        $first_sale_products = soundwave_setup_first_sale_products($order);
    }
    if (empty($first_sale_products) || !is_array($first_sale_products)) return false;

    $order_id = (int) $order->get_id();
    if ($order_id <= 0) return true;

    if ((string)get_post_meta($order_id, '_soundwave_setup_released', true) === '1') {
        return false;
    }

    $status = strtolower((string)$order->get_status());
    $marked = ((string)get_post_meta($order_id, '_soundwave_setup_marked', true) === '1');
    if ($marked && $status !== 'setup') {
        update_post_meta($order_id, '_soundwave_setup_released', '1');
        return false;
    }

    return true;
}}

if ( ! function_exists('soundwave_setup_product_summary') ) {
function soundwave_setup_product_summary( array $first_sale_products ) : string {
    if (empty($first_sale_products)) return '';

    $parts = [];
    foreach ($first_sale_products as $row) {
        $name  = trim((string)($row['name'] ?? 'Product'));
        $sku   = trim((string)($row['sku'] ?? ''));
        $qty   = (int)($row['qty'] ?? 0);
        $sales = (int)($row['total_sales'] ?? 0);

        $chunk = $name;
        if ($sku !== '') $chunk .= " (SKU {$sku})";
        if ($qty > 0) $chunk .= " qty {$qty}";
        $chunk .= " total_sales {$sales}";
        $parts[] = $chunk;
    }

    return implode('; ', $parts);
}}
