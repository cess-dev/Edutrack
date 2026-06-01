<?php
/**
 * EduTrack — PHP built-in server router
 *
 * Usage:
 *   php -S 0.0.0.0:8080 serve.php
 *
 * Then open:
 *   http://localhost:8080          (same machine)
 *   http://192.168.150.218:8080    (other devices on the network)
 */

$uri = urldecode(parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH));

// Serve real files (CSS, JS, images, fonts) directly without going through index.php
if ($uri !== '/' && file_exists(__DIR__ . $uri) && !is_dir(__DIR__ . $uri)) {
    return false;
}

// Everything else goes through the front controller
require __DIR__ . '/index.php';
