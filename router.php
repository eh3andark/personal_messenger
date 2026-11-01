<?php
declare(strict_types=1);

// Optional PHP CLI development preview router. Apache/LiteSpeed/cPanel use
// .htaccess; this router mirrors its deny rules when running `php -S` locally.
$rawPath = parse_url((string)($_SERVER['REQUEST_URI'] ?? '/'), PHP_URL_PATH) ?: '/';
$path = rawurldecode($rawPath);
$segments = explode('/', $path);
$privateSegment = false;
foreach ($segments as $segment) {
    if ($segment === 'data' || $segment === 'core' || $segment === 'tests' ||
        ($segment !== '' && $segment[0] === '.' && $segment !== '.well-known')) {
        $privateSegment = true;
        break;
    }
}
if ($privateSegment || preg_match('#^/(?:README-FA\.md|router\.php)$#i', $path) || preg_match('/\.(?:zip|bak|sql|log)$/i', $path)) {
    http_response_code(403);
    header('Content-Type: text/plain; charset=utf-8');
    exit('403 Forbidden');
}
$root = realpath(__DIR__);
$file = realpath($root . $path);
if ($path !== '/' && $file !== false && strncmp($file, $root . DIRECTORY_SEPARATOR, strlen($root) + 1) === 0 && is_file($file)) {
    if (strtolower(pathinfo($file, PATHINFO_EXTENSION)) === 'woff2') {
        header('Content-Type: font/woff2');
        readfile($file);
        exit;
    }
    return false;
}
if ($path !== '/' && $file === false && preg_match('/\.(?:php|json|js|css)$/i', $path)) {
    http_response_code(404);
    exit('404 Not Found');
}
require $root . '/index.php';
