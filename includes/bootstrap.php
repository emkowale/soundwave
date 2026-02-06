<?php
/*
 * File: includes/bootstrap.php
 * Purpose: Load Soundwave modules in a safe, deterministic order.
 */
if (!defined('ABSPATH')) exit;

// 1) Core helpers FIRST (settings + meta utilities)
$__utils = __DIR__ . '/utils.php';
if (is_readable($__utils)) {
    require_once $__utils;
} else {
    // Fail early with a clear message (shown in logs)
    error_log('Soundwave: missing ' . $__utils);
}

// 2) Validator (correct path: includes/validator.php)
$__validator = __DIR__ . '/validator.php';
if (is_readable($__validator)) {
    require_once $__validator;
} else {
    // Non-fatal: validator adds checks at sync time, but we can still load plugin
    error_log('Soundwave: validator not found at ' . $__validator . ' (will skip field validation).');
}

// 3) Sync internals (all under includes/sync/)
$__sync_dir = __DIR__ . '/sync';

$__files = [
    $__sync_dir . '/dispatcher.php',
    $__sync_dir . '/extract_art.php',
    $__sync_dir . '/payload_compose.php',
    $__sync_dir . '/http_send.php',
    $__sync_dir . '/helpers/product-image.php',     // optional helper
    $__sync_dir . '/line_builder_placeholder.php',  // builder placeholder
];

foreach ($__files as $f) {
    if (is_readable($f)) {
        require_once $f;
    } else {
        // Log but do not fatal; hardened callers guard against missing pieces
        error_log('Soundwave: missing ' . $f);
    }
}
