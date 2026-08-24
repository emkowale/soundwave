<?php
if (!defined('ABSPATH')) exit;

/* Popup finalization stays on the affiliate site, where the order list is
 * authoritative.  The hub receives a manifest and will not produce a master
 * until every listed source order and every piece is present. */
function soundwave_popup_manifest_site_slug(): string {
  $slug = sanitize_key((string) apply_filters('soundwave_popup_manifest_site_slug', ''));
  if ($slug !== '') return $slug;
  $host = (string) wp_parse_url(home_url('/'), PHP_URL_HOST);
  $parts = explode('.', strtolower($host));
  return sanitize_key($parts[0] ?? '');
}

function soundwave_popup_manifest_end_of_day(string $date): int {
  try {
    $tz = function_exists('wp_timezone') ? wp_timezone() : new DateTimeZone('UTC');
    return (new DateTimeImmutable($date . ' 23:59:59', $tz))->getTimestamp();
  } catch (Throwable $e) { return 0; }
}

function soundwave_popup_manifest_read_live_state(): array {
  $response = wp_remote_get(home_url('/'), ['timeout' => 15, 'redirection' => 3, 'user-agent' => 'Soundwave popup finalizer']);
  if (is_wp_error($response) || (int) wp_remote_retrieve_response_code($response) < 200 || (int) wp_remote_retrieve_response_code($response) >= 300) return [];
  $html = (string) wp_remote_retrieve_body($response);
  if (strpos(strtolower($html), 'data-popup-store') === false && strpos(strtolower($html), 'btx-popup-store') === false) return [];
  $timestamp = 0;
  if (preg_match_all('/data-popup-store-close=["\'](\d{10,16})["\']/i', $html, $matches)) {
    foreach ((array) ($matches[1] ?? []) as $raw) {
      $candidate = (int) $raw;
      if ($candidate > 9999999999) $candidate = (int) floor($candidate / 1000);
      if ($candidate > 0 && (!$timestamp || $candidate < $timestamp)) $timestamp = $candidate;
    }
  }
  if (!$timestamp) return [];
  $date = function_exists('wp_date') ? wp_date('Y-m-d', $timestamp, wp_timezone()) : gmdate('Y-m-d', $timestamp);
  // A midnight-only page value means the store remains open through that day.
  $local_time = function_exists('wp_date') ? wp_date('H:i:s', $timestamp, wp_timezone()) : gmdate('H:i:s', $timestamp);
  if ($local_time === '00:00:00') $timestamp = soundwave_popup_manifest_end_of_day($date);
  return ['site_slug' => soundwave_popup_manifest_site_slug(), 'closing_date' => $date, 'closing_timestamp' => $timestamp];
}

function soundwave_popup_manifest_state(): array {
  $live = soundwave_popup_manifest_read_live_state();
  if ($live) {
    update_option('soundwave_popup_manifest_state', $live, false);
    return $live;
  }
  $saved = get_option('soundwave_popup_manifest_state', []);
  return is_array($saved) ? $saved : [];
}

function soundwave_popup_manifest_orders(array $state): array {
  $close = absint($state['closing_timestamp'] ?? 0);
  if (!$close) return [];
  $orders = wc_get_orders(['limit' => -1, 'status' => array_keys(wc_get_order_statuses()), 'orderby' => 'date', 'order' => 'ASC']);
  $eligible = [];
  foreach ($orders as $order) {
    if (!$order instanceof WC_Order) continue;
    if (in_array($order->get_status(), ['cancelled', 'failed', 'refunded', 'checkout-draft'], true)) continue;
    $created = $order->get_date_created();
    if (!$created || $created->getTimestamp() > $close) continue;
    $eligible[] = $order;
  }
  return $eligible;
}

function soundwave_popup_manifest_payload(array $state, array $orders): array {
  $source_ids = []; $pieces = 0;
  foreach ($orders as $order) {
    $source_ids[] = (string) $order->get_id();
    foreach ($order->get_items('line_item') as $item) $pieces += max(0, (int) $item->get_quantity());
  }
  $source_ids = array_values(array_unique($source_ids)); sort($source_ids, SORT_NATURAL);
  $site_slug = sanitize_key((string) $state['site_slug']);
  $close = absint($state['closing_timestamp']);
  $payload = ['site_slug' => $site_slug, 'closing_date' => (string) $state['closing_date'], 'closing_timestamp' => $close, 'source_order_ids' => $source_ids, 'pieces' => $pieces];
  $payload['checksum'] = hash('sha256', wp_json_encode(['site_slug' => $site_slug, 'closing_timestamp' => $close, 'source_order_ids' => $source_ids, 'pieces' => $pieces]));
  return $payload;
}

function soundwave_popup_manifest_endpoint(array $settings): string {
  $endpoint = rtrim((string) ($settings['endpoint'] ?? ''), '/');
  return preg_replace('~/wp-json/wc/v[0-9]+/orders$~', '/wp-json/rumble/v1/popup-manifest', $endpoint);
}

function soundwave_popup_manifest_sync_order(WC_Order $order, bool $force = false): void {
  if (!$force && (string) get_post_meta($order->get_id(), '_soundwave_synced', true) === '1' && get_post_meta($order->get_id(), '_soundwave_hub_id', true)) return;
  if ($force) update_post_meta($order->get_id(), '_soundwave_synced', '0');
  if (function_exists('soundwave_send_to_hub')) soundwave_send_to_hub((int) $order->get_id());
}

function soundwave_popup_manifest_send(array $payload, array $settings): array {
  $endpoint = soundwave_popup_manifest_endpoint($settings);
  if ($endpoint === '' || strpos($endpoint, '/wp-json/') === false) return ['ok' => false, 'message' => 'Hub endpoint is not configured.'];
  $body_json = wp_json_encode($payload);
  $secret = (string) ($settings['popup_manifest_secret'] ?? '');
  if ($secret === '') return ['ok' => false, 'message' => 'Popup Manifest Secret is not configured.'];
  $response = wp_remote_post($endpoint, ['timeout' => 30, 'headers' => ['Content-Type' => 'application/json', 'Authorization' => 'Basic ' . base64_encode((string) $settings['consumer_key'] . ':' . (string) $settings['consumer_secret']), 'X-Soundwave-Manifest-Signature' => hash_hmac('sha256', $body_json, $secret)], 'body' => $body_json]);
  if (is_wp_error($response)) return ['ok' => false, 'message' => $response->get_error_message()];
  $code = (int) wp_remote_retrieve_response_code($response);
  $body = json_decode((string) wp_remote_retrieve_body($response), true);
  return ['ok' => $code >= 200 && $code < 300, 'code' => $code, 'body' => is_array($body) ? $body : [], 'message' => is_array($body) ? (string) ($body['message'] ?? '') : ''];
}

function soundwave_popup_manifest_run(): void {
  $state = soundwave_popup_manifest_state();
  if (empty($state['site_slug']) || empty($state['closing_timestamp']) || (int) $state['closing_timestamp'] > time()) return;
  $orders = soundwave_popup_manifest_orders($state);
  if (!$orders) return;
  foreach ($orders as $order) soundwave_popup_manifest_sync_order($order);
  $settings = function_exists('soundwave_get_settings') ? soundwave_get_settings() : [];
  $payload = soundwave_popup_manifest_payload($state, $orders);
  $result = soundwave_popup_manifest_send($payload, $settings);
  $missing = (array) (($result['body']['missing_source_order_ids'] ?? []));
  if ($result['ok'] && $missing) {
    $by_id = []; foreach ($orders as $order) $by_id[(string) $order->get_id()] = $order;
    foreach ($missing as $source_id) if (isset($by_id[(string) $source_id])) soundwave_popup_manifest_sync_order($by_id[(string) $source_id], true);
    $result = soundwave_popup_manifest_send($payload, $settings);
  }
  $key = 'soundwave_popup_manifest_result_' . md5($payload['site_slug'] . '|' . $payload['closing_timestamp']);
  update_option($key, ['checked_at' => current_time('mysql'), 'payload' => $payload, 'result' => $result], false);
  if (empty($result['ok'])) error_log('Soundwave popup manifest: ' . ($result['message'] ?: 'Hub did not accept the manifest.'));
}

function soundwave_popup_manifest_schedule(): void {
  if (!wp_next_scheduled('soundwave_popup_manifest_check')) wp_schedule_event(time() + 300, 'hourly', 'soundwave_popup_manifest_check');
}
add_action('init', 'soundwave_popup_manifest_schedule');
add_action('soundwave_popup_manifest_check', 'soundwave_popup_manifest_run');
