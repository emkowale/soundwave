<?php
if ( ! defined('ABSPATH') ) exit;

if ( ! defined('SOUNDWAVE_RESYNC_CRON_HOOK') ) {
    define('SOUNDWAVE_RESYNC_CRON_HOOK', 'soundwave_resync_unsynced_batch');
}

if ( ! function_exists('soundwave_resync_schedule_batch') ) {
function soundwave_resync_schedule_batch( int $delay_seconds = 30 ) : void {
    $delay = max(5, (int) $delay_seconds);
    if ( ! wp_next_scheduled(SOUNDWAVE_RESYNC_CRON_HOOK) ) {
        wp_schedule_single_event(time() + $delay, SOUNDWAVE_RESYNC_CRON_HOOK);
    }
}}

if ( ! function_exists('soundwave_resync_candidate_statuses') ) {
function soundwave_resync_candidate_statuses() : array {
    $statuses = [];

    if ( function_exists('wc_get_order_statuses') ) {
        foreach ( array_keys((array) wc_get_order_statuses()) as $status ) {
            $s = strtolower((string) $status);
            if (strpos($s, 'wc-') === 0) $s = substr($s, 3);
            if ($s === '' || in_array($s, ['trash', 'auto-draft', 'checkout-draft'], true)) continue;
            $statuses[] = $s;
        }
    }

    if ( empty($statuses) ) {
        $statuses = ['pending', 'on-hold', 'processing', 'completed'];
    }

    return array_values(array_unique($statuses));
}}

if ( ! function_exists('soundwave_resync_fetch_unsynced_order_ids') ) {
function soundwave_resync_fetch_unsynced_order_ids( string $run_token, int $limit = 15 ) : array {
    if ( ! function_exists('wc_get_orders') ) return [];

    $limit = max(1, (int) $limit);
    $synced_values = ['1', 'yes', 'true', 'on'];

    $ids = wc_get_orders([
        'type'       => 'shop_order',
        'status'     => soundwave_resync_candidate_statuses(),
        'limit'      => $limit,
        'return'     => 'ids',
        'orderby'    => 'date',
        'order'      => 'ASC',
        'meta_query' => [
            'relation' => 'AND',
            [
                'relation' => 'OR',
                ['key' => '_soundwave_synced', 'compare' => 'NOT EXISTS'],
                ['key' => '_soundwave_synced', 'value' => $synced_values, 'compare' => 'NOT IN'],
            ],
            [
                'relation' => 'OR',
                ['key' => '_soundwave_resync_attempted_version', 'compare' => 'NOT EXISTS'],
                ['key' => '_soundwave_resync_attempted_version', 'value' => $run_token, 'compare' => '!='],
            ],
        ],
    ]);

    if ( ! is_array($ids) ) return [];
    $ids = array_map('intval', $ids);
    return array_values(array_filter($ids, function($id){
        return $id > 0;
    }));
}}

if ( ! function_exists('soundwave_resync_record_stats') ) {
function soundwave_resync_record_stats( string $version, int $processed, int $ok, int $failed ) : void {
    $stats = get_option('soundwave_resync_stats', []);
    if ( ! is_array($stats) ) $stats = [];

    $row = isset($stats[$version]) && is_array($stats[$version]) ? $stats[$version] : [
        'processed'  => 0,
        'ok'         => 0,
        'failed'     => 0,
        'started_at' => time(),
        'updated_at' => time(),
    ];

    $row['processed'] = (int) $row['processed'] + max(0, $processed);
    $row['ok']        = (int) $row['ok'] + max(0, $ok);
    $row['failed']    = (int) $row['failed'] + max(0, $failed);
    $row['updated_at'] = time();

    $stats[$version] = $row;
    update_option('soundwave_resync_stats', $stats, false);
}}

if ( ! function_exists('soundwave_resync_run_unsynced_batch') ) {
function soundwave_resync_run_unsynced_batch() : void {
    $pending_version = trim((string) get_option('soundwave_resync_pending_version', ''));
    if ($pending_version === '') return;

    $lock_key = 'soundwave_resync_batch_lock';
    if ( get_transient($lock_key) ) return;
    set_transient($lock_key, 1, 55);

    try {
        if ( ! function_exists('soundwave_send_to_hub') ) {
            $sender = __DIR__ . '/sender.php';
            if (is_readable($sender)) require_once $sender;
        }
        if ( ! function_exists('soundwave_send_to_hub') ) return;

        $cfg = function_exists('soundwave_get_settings') ? (array) soundwave_get_settings() : [];
        $endpoint = trim((string)($cfg['endpoint'] ?? ''));
        $ck       = trim((string)($cfg['consumer_key'] ?? ''));
        $cs       = trim((string)($cfg['consumer_secret'] ?? ''));

        if ($endpoint === '' || $ck === '' || $cs === '') {
            $retry = (int) apply_filters('soundwave_resync_missing_config_delay', 6 * HOUR_IN_SECONDS);
            soundwave_resync_schedule_batch(max(300, $retry));
            return;
        }

        $run_token  = 'v:' . $pending_version;
        $batch_size = max(1, (int) apply_filters('soundwave_resync_batch_size', 15));
        $order_ids  = soundwave_resync_fetch_unsynced_order_ids($run_token, $batch_size);

        if ( empty($order_ids) ) {
            delete_option('soundwave_resync_pending_version');
            update_option('soundwave_resync_last_completed_at', time(), false);
            return;
        }

        $processed = 0;
        $ok = 0;
        $failed = 0;

        foreach ($order_ids as $order_id) {
            $order_id = (int) $order_id;
            if ($order_id <= 0) continue;

            update_post_meta($order_id, '_soundwave_resync_attempted_version', $run_token);
            update_post_meta($order_id, '_soundwave_last_attempt', time());

            try {
                $resp = soundwave_send_to_hub($order_id);
            } catch (Throwable $e) {
                $resp = new WP_Error('soundwave_resync_throwable', $e->getMessage());
            }

            $processed++;
            if (is_wp_error($resp)) {
                $failed++;
            } elseif (is_array($resp) && !empty($resp['ok'])) {
                $ok++;
            } else {
                $failed++;
            }
        }

        soundwave_resync_record_stats($pending_version, $processed, $ok, $failed);

        $has_more = soundwave_resync_fetch_unsynced_order_ids($run_token, 1);
        if ( ! empty($has_more) ) {
            $delay = max(20, (int) apply_filters('soundwave_resync_batch_delay_seconds', 60));
            soundwave_resync_schedule_batch($delay);
            return;
        }

        delete_option('soundwave_resync_pending_version');
        update_option('soundwave_resync_last_completed_at', time(), false);
    } finally {
        delete_transient($lock_key);
    }
}}
add_action(SOUNDWAVE_RESYNC_CRON_HOOK, 'soundwave_resync_run_unsynced_batch');

if ( ! function_exists('soundwave_resync_bootstrap') ) {
function soundwave_resync_bootstrap() : void {
    $current = defined('SOUNDWAVE_VERSION') ? trim((string) SOUNDWAVE_VERSION) : '';
    if ($current === '') return;

    $stored = trim((string) get_option('soundwave_code_version', ''));
    $pending = trim((string) get_option('soundwave_resync_pending_version', ''));

    if ($stored === '' || version_compare($stored, $current, '<')) {
        update_option('soundwave_code_version', $current, false);
        update_option('soundwave_resync_pending_version', $current, false);
        $pending = $current;
    } elseif (version_compare($stored, $current, '>')) {
        update_option('soundwave_code_version', $current, false);
    }

    if ($pending !== '' && ! wp_next_scheduled(SOUNDWAVE_RESYNC_CRON_HOOK)) {
        $delay = max(10, (int) apply_filters('soundwave_resync_start_delay_seconds', 30));
        soundwave_resync_schedule_batch($delay);
    }
}}
add_action('init', 'soundwave_resync_bootstrap', 25);
