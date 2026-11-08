<?php
declare(strict_types=1);

// ============================================================================
// Chat Amn - Core Bootstrap (v1.0)
// Compatible with PHP 7.4 - 8.4+ on cPanel (Apache / LiteSpeed)
// ============================================================================

error_reporting(E_ALL & ~E_DEPRECATED & ~E_NOTICE);
ini_set('display_errors', '0');
date_default_timezone_set('Asia/Tehran');
if (function_exists('mb_internal_encoding')) {
    mb_internal_encoding('UTF-8');
}

define('HAMRAH_VERSION', '1.0'); // legacy constant name retained for upgrade compatibility
define('BASE_DIR', dirname(__DIR__));
define('DATA_DIR', BASE_DIR . '/data');
define('CHATS_DIR', DATA_DIR . '/chats');
define('UPLOADS_DIR', DATA_DIR . '/uploads');
define('CHUNKS_DIR', UPLOADS_DIR . '/chunks');
define('DRIVE_DIR', DATA_DIR . '/personal_drive');
define('LOCKS_DIR', DATA_DIR . '/locks');

function ensure_storage_structure(): void
{
    $dirs = [DATA_DIR, CHATS_DIR, UPLOADS_DIR, CHUNKS_DIR, DRIVE_DIR, LOCKS_DIR];
    foreach ($dirs as $dir) {
        if (!is_dir($dir)) {
            @mkdir($dir, 0755, true);
        }
    }

    $htaccess = DATA_DIR . '/.htaccess';
    if (!file_exists($htaccess)) {
        @file_put_contents($htaccess, "Options -Indexes\n<IfModule mod_authz_core.c>\n    Require all denied\n</IfModule>\n<IfModule !mod_authz_core.c>\n    Order deny,allow\n    Deny from all\n</IfModule>\n");
    }

    $indexGuard = DATA_DIR . '/index.php';
    if (!file_exists($indexGuard)) {
        @file_put_contents($indexGuard, "<?php http_response_code(403); exit('403 Forbidden');\n");
    }
}

ensure_storage_structure();

/**
 * Execute callback while holding an exclusive flock on a named lock file
 *
 * @template T
 * @param string $lockName
 * @param callable():T $callback
 * @return T
 */
function with_file_lock(string $lockName, callable $callback)
{
    $safeName = preg_replace('/[^a-zA-Z0-9_\-\.]/', '_', $lockName);
    $lockPath = LOCKS_DIR . '/' . $safeName . '.lock';
    $fp = @fopen($lockPath, 'c+');
    if ($fp === false) {
        throw new RuntimeException('پوشه data/locks روی هاست قابل نوشتن نیست؛ برای جلوگیری از آسیب به JSON عملیات متوقف شد.');
    }
    if (!@flock($fp, LOCK_EX)) {
        @fclose($fp);
        throw new RuntimeException('گرفتن قفل فایل JSON روی هاست ممکن نیست؛ عملیات متوقف شد.');
    }
    try {
        return $callback();
    } finally {
        @flock($fp, LOCK_UN);
        @fclose($fp);
    }
}

/**
 * Read a JSON file safely, tolerating UTF-8 BOM and accidental trailing commas
 */
function read_json_safe(string $path, $default = [])
{
    if (!file_exists($path)) {
        return $default;
    }
    $raw = @file_get_contents($path);
    if ($raw === false || trim($raw) === '') {
        return $default;
    }
    if (strncmp($raw, "\xEF\xBB\xBF", 3) === 0) {
        $raw = substr($raw, 3);
    }
    $decoded = json_decode($raw, true);
    if (json_last_error() !== JSON_ERROR_NONE) {
        $cleaned = preg_replace('/,\s*([\]}])/m', '$1', $raw);
        if ($cleaned !== null) {
            $decoded = json_decode($cleaned, true);
        }
        if (json_last_error() !== JSON_ERROR_NONE) {
            return $default;
        }
    }
    return $decoded;
}

/**
 * Atomically write JSON file (write temp + rename)
 */
function write_json_atomic(string $path, $data, bool $pretty = true): bool
{
    $dir = dirname($path);
    if (!is_dir($dir)) {
        @mkdir($dir, 0755, true);
    }
    $flags = JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES;
    if ($pretty) {
        $flags |= JSON_PRETTY_PRINT;
    }
    $json = json_encode($data, $flags);
    if ($json === false) {
        return false;
    }
    $tmp = $path . '.tmp.' . uniqid('', true);
    if (@file_put_contents($tmp, $json, LOCK_EX) === false) {
        @unlink($tmp);
        return false;
    }
    if (!@rename($tmp, $path)) {
        @unlink($tmp);
        // Copying over an active JSON file would expose readers to a partial
        // write; fail closed instead (cPanel runs on Linux with atomic rename).
        return false;
    }
    return true;
}

/**
 * Default customizable web & search tools for AssistantBridge
 */
function get_default_web_tools(): array
{
    return [
        [
            'id' => 'status',
            'title' => 'تست اتصال سرور هاست',
            'icon' => '📡',
            'command' => '/status',
            'type' => 'builtin_status',
            'lang' => 'fa',
            'url_template' => '',
            'placeholder' => '',
            'quick_chip' => true,
            'enabled' => true,
        ],
        [
            'id' => 'wiki_fa',
            'title' => 'ویکی‌پدیای فارسی',
            'icon' => '📚',
            'command' => '/wiki',
            'type' => 'wikipedia',
            'lang' => 'fa',
            'url_template' => '',
            'placeholder' => 'عبارت جستجو در ویکی‌پدیا فارسی...',
            'quick_chip' => true,
            'enabled' => true,
        ],
        [
            'id' => 'wiki_en',
            'title' => 'ویکی‌پدیای انگلیسی',
            'icon' => '🌍',
            'command' => '/enwiki',
            'type' => 'wikipedia',
            'lang' => 'en',
            'url_template' => '',
            'placeholder' => 'موضوع به انگلیسی...',
            'quick_chip' => true,
            'enabled' => true,
        ],
        [
            'id' => 'web_search',
            'title' => 'جستجوی وب متنی',
            'icon' => '🌐',
            'command' => '/search',
            'type' => 'duckduckgo',
            'lang' => 'fa',
            'url_template' => '',
            'placeholder' => 'عبارت مورد نظر در وب...',
            'quick_chip' => true,
            'enabled' => true,
        ],
        [
            'id' => 'telegram_public',
            'title' => '۲۰ پست اخیر کانال عمومی تلگرام',
            'icon' => '✈️',
            'command' => '/telegram',
            'type' => 'telegram_public',
            'lang' => 'fa',
            'url_template' => '',
            'placeholder' => '@channel_id (فقط کانال عمومی)',
            'quick_chip' => true,
            'enabled' => true,
        ],
        [
            'id' => 'domestic_rates',
            'title' => 'نرخ دلار، طلا و سکهٔ ایران',
            'icon' => '📈',
            'command' => '/rates',
            'type' => 'domestic_rates',
            'lang' => 'fa',
            'url_template' => '',
            'placeholder' => 'دریافت نرخ عمومی TGJU؛ بدون کلید API',
            'quick_chip' => true,
            'enabled' => true,
        ],
        [
            'id' => 'url_reader',
            'title' => 'متن‌خوان لینک سایت',
            'icon' => '📄',
            'command' => '/read',
            'type' => 'url_reader',
            'lang' => 'fa',
            'url_template' => '',
            'placeholder' => 'https://example.com',
            'quick_chip' => true,
            'enabled' => true,
        ],
    ];
}

function get_app_config(): array
{
    $defaults = [
        'app_title' => 'چت امن',
        'app_subtitle' => 'پیام‌رسان خصوصی و فضای ابری امن',
        'github_url' => '', // only the admin can publish a real repository URL
        'max_shard_bytes' => 204800,
        'max_shard_messages' => 150,
        'max_upload_mb' => 150,
        'chunk_size_bytes' => 1048576,
        'video_compress_threshold_mb' => 8,
        'allow_user_profile_edit' => true,
        'allow_user_username_change' => true,
        'ai_bridge' => [
            'enabled' => true,
            'provider_name' => 'DeepSeek / OpenAI Compatible',
            'api_base_url' => 'https://api.deepseek.com/v1',
            'api_key' => '',
            'model' => 'deepseek-chat',
            'system_prompt' => 'تو یک دستیار هوشمند فارسی‌زبان، دقیق و راهگشا هستی. پاسخ‌هایت شفاف، کاربردی و محترمانه باشد.',
            'timeout_seconds' => 25,
        ],
        'web_tools' => get_default_web_tools(),
        'web_tools_revision' => 2,
    ];

    $loaded = read_json_safe(DATA_DIR . '/config.json', []);
    if (!is_array($loaded)) {
        return $defaults;
    }
    if (isset($loaded['ai_bridge']) && is_array($loaded['ai_bridge'])) {
        $loaded['ai_bridge'] = array_merge($defaults['ai_bridge'], $loaded['ai_bridge']);
    } else {
        $loaded['ai_bridge'] = $defaults['ai_bridge'];
    }
    if (!isset($loaded['web_tools']) || !is_array($loaded['web_tools'])) {
        $loaded['web_tools'] = $defaults['web_tools'];
    } elseif (($loaded['web_tools_revision'] ?? 0) < 2) {
        // One-time opt-in migration for existing installs. After the admin
        // saves their tools, they may disable or remove this optional source.
        $exists = false;
        foreach ($loaded['web_tools'] as $tool) {
            if (is_array($tool) && ($tool['type'] ?? '') === 'domestic_rates') $exists = true;
        }
        if (!$exists) {
            foreach ($defaults['web_tools'] as $tool) {
                if ($tool['type'] === 'domestic_rates') $loaded['web_tools'][] = $tool;
            }
        }
        $loaded['web_tools_revision'] = 2;
    }
    $merged = array_merge($defaults, $loaded);
    // Upgrade an untouched legacy brand, but preserve any admin-chosen name.
    if (trim((string)$merged['app_title']) === 'همراه چت') $merged['app_title'] = 'چت امن';
    return $merged;
}

function save_app_config(array $newConfig): bool
{
    return with_file_lock('config', function () use ($newConfig) {
        $current = get_app_config();
        $merged = array_merge($current, $newConfig);
        return write_json_atomic(DATA_DIR . '/config.json', $merged, true);
    });
}

function json_response(array $payload, int $statusCode = 200): void
{
    http_response_code($statusCode);
    header('Content-Type: application/json; charset=utf-8');
    header('Cache-Control: no-store, no-cache, must-revalidate');
    echo json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}

function get_request_data(): array
{
    $contentType = $_SERVER['CONTENT_TYPE'] ?? $_SERVER['HTTP_CONTENT_TYPE'] ?? '';
    if (stripos($contentType, 'application/json') !== false) {
        $raw = file_get_contents('php://input');
        if ($raw !== false && $raw !== '') {
            $decoded = json_decode($raw, true);
            if (is_array($decoded)) {
                return $decoded;
            }
        }
    }
    return $_POST;
}

function normalize_username(string $username): string
{
    $u = strtolower(trim($username));
    $u = ltrim($u, '@');
    return preg_replace('/[^a-z0-9_\.\-]/', '', $u) ?? '';
}

function format_bytes_fa(int $bytes): string
{
    if ($bytes < 1024) {
        return $bytes . ' B';
    }
    if ($bytes < 1048576) {
        return round($bytes / 1024, 1) . ' KB';
    }
    if ($bytes < 1073741824) {
        return round($bytes / 1048576, 2) . ' MB';
    }
    return round($bytes / 1073741824, 2) . ' GB';
}
