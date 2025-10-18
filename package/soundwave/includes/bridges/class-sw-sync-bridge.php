<?php
/*
 * File: includes/bridges/class-sw-sync-bridge.php
 * Plugin: Soundwave
 * Desc: Minimal bridge; loads helpers + priority-1 sync; falls back to legacy.
 * Author: Eric Kowalewski
 * Version: 1.2.3
 * Updated: 2025-10-06 18:35 EDT
 */
defined('ABSPATH') || exit;

/* Load helpers + hard override sync (prio 1) */
$h=SOUNDWAVE_PATH.'includes/util/sw-payload-helpers.php';
$s=SOUNDWAVE_PATH.'includes/sync/class-sw-sync-post.php';
if(is_readable($h)) require_once $h;
if(is_readable($s)) require_once $s;

class SW_Sync_Bridge {
  public static function init(){ add_filter('soundwave/run_sync_for_order',[__CLASS__,'run'],10,3); }

  public static function run($result,$order_id,$ctx=[]){
    if($result instanceof WP_Error || $result===true || is_array($result)) return $result;
    if(function_exists('soundwave_sync_order_to_beartraxs'))
      return soundwave_sync_order_to_beartraxs((int)$order_id,(array)$ctx);
    if(function_exists('sw_sync_single_order'))
      return sw_sync_single_order((int)$order_id,(array)$ctx);
    return new WP_Error('soundwave_missing_sync','Sync function not found.');
  }
}
SW_Sync_Bridge::init();
