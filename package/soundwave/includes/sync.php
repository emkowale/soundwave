<?php
if ( ! defined('ABSPATH') ) exit;

/*
 * File: includes/sync.php
 * Purpose: Split loader for Soundwave sync subsystem (≤100 lines)
 * Note: Load modern payload builder BEFORE sender.php so Variation SKU and images are added.
 */

// Core helpers + legacy builder (kept for fallback)
require_once SOUNDWAVE_DIR . 'includes/utils.php';
require_once SOUNDWAVE_DIR . 'includes/validator.php';
require_once SOUNDWAVE_DIR . 'includes/sync/payload.php';

// Modern compose + overrides (ADDED)
require_once SOUNDWAVE_DIR . 'includes/sync/payload_compose.php';
require_once SOUNDWAVE_DIR . 'includes/sync/payload_overrides_paid.php';

// Admin + runtime
require_once SOUNDWAVE_DIR . 'includes/sync/settings.php';
require_once SOUNDWAVE_DIR . 'includes/sync/admin-list.php';

// Sender must load AFTER builders so it prefers sw_compose_payload()
require_once SOUNDWAVE_DIR . 'includes/sync/sender.php';

require_once SOUNDWAVE_DIR . 'includes/sync/ajax-orders.php';
require_once SOUNDWAVE_DIR . 'includes/sync/ajax-settings.php';
require_once SOUNDWAVE_DIR . 'includes/sync/auto.php';
require_once SOUNDWAVE_DIR . 'includes/sync/compat-hooks.php';
