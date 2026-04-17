<?php

function nc_debug($msg) {
    if (!function_exists('wp_upload_dir')) return;
    $upload = wp_upload_dir();
    if (empty($upload['basedir'])) return;
    $file = trailingslashit($upload['basedir']) . 'nc-debug.log';
    $line = '[' . date('Y-m-d H:i:s') . '] ' . $msg . PHP_EOL;
    @file_put_contents($file, $line, FILE_APPEND | LOCK_EX);
}

function nc_expiry_debug($msg) {
    if (!function_exists('wp_upload_dir')) return;
    $upload = wp_upload_dir();
    if (empty($upload['basedir'])) return;
    $file = trailingslashit($upload['basedir']) . 'nc-expiry-debug.log';
    $line = '[' . date('Y-m-d H:i:s') . '] ' . $msg . PHP_EOL;
    @file_put_contents($file, $line, FILE_APPEND | LOCK_EX);
}

add_shortcode( 'wp_now', function () {
    return '<pre>Simulated Time: ' . esc_html(
        wp_date( 'Y-m-d H:i:s' )
    ) . '</pre>';
});