<?php
if ( ! defined('ABSPATH') ) exit;

if ( ! defined('SOUNDWAVE_RESYNC_CRON_HOOK') ) {
    define('SOUNDWAVE_RESYNC_CRON_HOOK', 'soundwave_resync_unsynced_batch');
}

if ( ! function_exists('soundwave_resync_is_enabled') ) {
function soundwave_resync_is_enabled() : bool {
    $enabled = true;
    if (defined('SOUNDWAVE_DISABLE_RESYNC') && SOUNDWAVE_DISABLE_RESYNC) {
        $enabled = false;
    }

    $opt = get_option('soundwave_resync_enabled', '1');
    $raw = strtolower(trim((string) $opt));
    if (in_array($raw, ['0', 'false', 'no', 'off'], true)) {
        $enabled = false;
    }

    return (bool) apply_filters('soundwave_resync_enabled', $enabled);
}}

if ( ! function_exists('soundwave_resync_clear_runtime_queue') ) {
function soundwave_resync_clear_runtime_queue() : void {
    delete_option('soundwave_resync_pending_version');
    delete_transient('soundwave_resync_batch_lock');
    if (function_exists('wp_clear_scheduled_hook')) {
        wp_clear_scheduled_hook(SOUNDWAVE_RESYNC_CRON_HOOK);
    }
}}

if ( ! function_exists('soundwave_resync_schedule_batch') ) {
function soundwave_resync_schedule_batch( int $delay_seconds = 30 ) : void {
    $delay = max(5, (int) $delay_seconds);
    if ( ! wp_next_scheduled(SOUNDWAVE_RESYNC_CRON_HOOK) ) {
        wp_schedule_single_event(time() + $delay, SOUNDWAVE_RESYNC_CRON_HOOK);
    }
}}

if ( ! function_exists('soundwave_resync_attempt_meta_key') ) {
function soundwave_resync_attempt_meta_key(string $version): string {
    $token = strtolower(trim($version));
    $token = preg_replace('/[^a-z0-9]+/', '_', $token);
    $token = trim((string)$token, '_');
    if ($token === '') $token = 'unknown';
    return '_soundwave_resync_attempted_' . $token;
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
function soundwave_resync_fetch_unsynced_order_ids( string $attempt_meta_key, int $limit = 15 ) : array {
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
                'key'     => $attempt_meta_key,
                'compare' => 'NOT EXISTS',
            ],
        ],
    ]);

    if ( ! is_array($ids) ) return [];
    $ids = array_map('intval', $ids);
    return array_values(array_filter($ids, function($id){
        return $id > 0;
    }));
}}

if ( ! function_exists('soundwave_resync_meta_values_have_truthy') ) {
function soundwave_resync_meta_values_have_truthy(array $values): bool {
    foreach ($values as $v) {
        $s = strtolower(trim((string)$v));
        if (in_array($s, ['1', 'yes', 'true', 'on'], true)) {
            return true;
        }
    }
    return false;
}}

if ( ! function_exists('soundwave_resync_order_has_truthy_sync_flag') ) {
function soundwave_resync_order_has_truthy_sync_flag(int $order_id): bool {
    $values = get_post_meta($order_id, '_soundwave_synced', false);
    if ( ! is_array($values) ) {
        $values = [$values];
    }
    return soundwave_resync_meta_values_have_truthy($values);
}}

if ( ! function_exists('soundwave_resync_order_has_hub_id') ) {
function soundwave_resync_order_has_hub_id(int $order_id): bool {
    foreach (['_soundwave_hub_id', '_soundwave_dest_order_id', '_hub_order_id'] as $k) {
        $v = trim((string) get_post_meta($order_id, $k, true));
        if ($v !== '' && (int)$v > 0) return true;
    }
    return false;
}}

if ( ! function_exists('soundwave_resync_should_skip_order') ) {
function soundwave_resync_should_skip_order(int $order_id, string $attempt_meta_key, string $pending_version): bool {
    if ($order_id <= 0) return true;

    $already_attempted = trim((string)get_post_meta($order_id, $attempt_meta_key, true));
    if ($already_attempted !== '') return true;

    if (soundwave_resync_order_has_truthy_sync_flag($order_id)) {
        return true;
    }

    $skip_if_hub_id = (bool) apply_filters('soundwave_resync_skip_if_has_hub_id', true, $order_id, $pending_version);
    if ($skip_if_hub_id && soundwave_resync_order_has_hub_id($order_id)) {
        return true;
    }

    return false;
}}

if ( ! function_exists('soundwave_resync_record_stats') ) {
function soundwave_resync_record_stats( string $version, int $processed, int $ok, int $failed, int $skipped = 0 ) : void {
    $stats = get_option('soundwave_resync_stats', []);
    if ( ! is_array($stats) ) $stats = [];

    $row = isset($stats[$version]) && is_array($stats[$version]) ? $stats[$version] : [
        'processed'  => 0,
        'ok'         => 0,
        'failed'     => 0,
        'skipped'    => 0,
        'started_at' => time(),
        'updated_at' => time(),
    ];

    $row['processed'] = (int) $row['processed'] + max(0, $processed);
    $row['ok']        = (int) $row['ok'] + max(0, $ok);
    $row['failed']    = (int) $row['failed'] + max(0, $failed);
    $row['skipped']   = (int) ($row['skipped'] ?? 0) + max(0, $skipped);
    $row['updated_at'] = time();

    $stats[$version] = $row;
    update_option('soundwave_resync_stats', $stats, false);
}}

if ( ! function_exists('soundwave_resync_run_unsynced_batch') ) {
function soundwave_resync_run_unsynced_batch() : void {
    $pending_version = trim((string) get_option('soundwave_resync_pending_version', ''));
    if ($pending_version === '') return;
    if ( ! soundwave_resync_is_enabled() ) {
        if (function_exists('wp_clear_scheduled_hook')) {
            wp_clear_scheduled_hook(SOUNDWAVE_RESYNC_CRON_HOOK);
        }
        return;
    }

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

        $attempt_meta_key = soundwave_resync_attempt_meta_key($pending_version);
        $batch_size = max(1, (int) apply_filters('soundwave_resync_batch_size', 15));
        $order_ids  = soundwave_resync_fetch_unsynced_order_ids($attempt_meta_key, $batch_size);

        if ( empty($order_ids) ) {
            delete_option('soundwave_resync_pending_version');
            update_option('soundwave_resync_last_completed_at', time(), false);
            return;
        }

        $processed = 0;
        $ok = 0;
        $failed = 0;
        $skipped = 0;

        foreach ($order_ids as $order_id) {
            $order_id = (int) $order_id;
            if ($order_id <= 0) continue;

            $skip = soundwave_resync_should_skip_order($order_id, $attempt_meta_key, $pending_version);
            update_post_meta($order_id, $attempt_meta_key, '1');
            if ($skip) {
                $processed++;
                $skipped++;
                continue;
            }

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
                if (!empty($resp['skipped'])) $skipped++;
            } else {
                $failed++;
            }
        }

        soundwave_resync_record_stats($pending_version, $processed, $ok, $failed, $skipped);

        $has_more = soundwave_resync_fetch_unsynced_order_ids($attempt_meta_key, 1);
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

if ( ! function_exists('soundwave_resync_upgrade_cleanup') ) {
function soundwave_resync_upgrade_cleanup() : void {
    $current = defined('SOUNDWAVE_VERSION') ? trim((string) SOUNDWAVE_VERSION) : '';
    if ($current === '') return;

    $stored = trim((string) get_option('soundwave_code_version', ''));
    if ($stored === '' || !version_compare($stored, $current, '<')) return;

    $done_for = trim((string) get_option('soundwave_resync_cleanup_done_for', ''));
    if ($done_for === $current) return;

    // Automatic equivalent of manual containment:
    // disable, clear pending run marker, clear cron queue, then re-enable.
    update_option('soundwave_resync_enabled', '0', false);
    soundwave_resync_clear_runtime_queue();
    update_option('soundwave_resync_enabled', '1', false);
    update_option('soundwave_resync_cleanup_done_for', $current, false);
}}
add_action('init', 'soundwave_resync_upgrade_cleanup', 5);

if ( ! function_exists('soundwave_resync_bootstrap') ) {
function soundwave_resync_bootstrap() : void {
    if ( ! soundwave_resync_is_enabled() ) {
        soundwave_resync_clear_runtime_queue();
        return;
    }

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
