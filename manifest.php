<?php
declare(strict_types=1);
require_once __DIR__ . '/core/bootstrap.php';

$cfg = get_app_config();
header('Content-Type: application/manifest+json; charset=utf-8');
header('Cache-Control: public, max-age=3600');

$manifest = [
    'name' => $cfg['app_title'] . ' - ' . $cfg['app_subtitle'],
    'short_name' => $cfg['app_title'],
    'description' => 'پیام‌رسان خصوصی و فضای ابری شخصی بدون نیاز به اینترنت خارجی',
    'start_url' => './index.php',
    'scope' => './',
    'display' => 'standalone',
    'orientation' => 'portrait',
    'dir' => 'rtl',
    'lang' => 'fa-IR',
    'background_color' => '#FFFFFF',
    'theme_color' => '#FFFFFF',
    'icons' => [
        ['src' => 'assets/img/icon-192.png', 'sizes' => '192x192', 'type' => 'image/png', 'purpose' => 'any'],
        ['src' => 'assets/img/icon-512.png', 'sizes' => '512x512', 'type' => 'image/png', 'purpose' => 'any maskable'],
        ['src' => 'assets/img/icon.svg', 'sizes' => 'any', 'type' => 'image/svg+xml', 'purpose' => 'any'],
    ],
];

echo json_encode($manifest, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT);
