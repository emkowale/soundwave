<?php
defined('ABSPATH') || exit;

function sw_json_encode($data) {
    return wp_json_encode($data, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);
}
