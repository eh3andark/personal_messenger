<?php
declare(strict_types=1);

require_once __DIR__ . '/bootstrap.php';
require_once __DIR__ . '/DomesticRates.php';

class AssistantBridge
{
    public static function getEnabledTools(): array
    {
        $cfg = get_app_config();
        $tools = $cfg['web_tools'] ?? [];
        if (!is_array($tools)) {
            return [];
        }
        $out = [];
        foreach ($tools as $t) {
            if (is_array($t) && !empty($t['enabled'])) {
                $out[] = $t;
            }
        }
        return $out;
    }

    /** Only expose UI labels/commands, not private source URL templates. */
    public static function getPublicTools(): array
    {
        $out = [];
        foreach (self::getEnabledTools() as $t) {
            $out[] = [
                'id' => (string)($t['id'] ?? ''),
                'title' => (string)($t['title'] ?? ''),
                'icon' => (string)($t['icon'] ?? ''),
                'command' => (string)($t['command'] ?? ''),
                'type' => (string)($t['type'] ?? ''),
                'lang' => (string)($t['lang'] ?? 'fa'),
                'placeholder' => (string)($t['placeholder'] ?? ''),
                'quick_chip' => !empty($t['quick_chip']),
                'enabled' => true,
                'requires_query' => strpos((string)($t['url_template'] ?? ''), '{query}') !== false,
            ];
        }
        return $out;
    }

    /** Turn a successful public preview into individual chat posts (plus a source header).
     * Failures, disabled tools and all other assistant replies stay a single message.
     */
    public static function splitPublicTelegramReply(string $prompt, string $answer): array
    {
        $text = trim($prompt);
        $isTelegram = false;
        foreach (self::getEnabledTools() as $tool) {
            $cmd = trim((string)($tool['command'] ?? ''));
            if ($cmd !== '' && ($text === $cmd || strpos($text, $cmd . ' ') === 0)) {
                $isTelegram = ($tool['type'] ?? '') === 'telegram_public';
                break;
            }
        }
        if (!$isTelegram || strpos($answer, '✈️ **') !== 0 || strpos($answer, "\n🔎 منبع: https://t.me/s/") === false) {
            return [$answer];
        }
        // The fetched post text is whitespace-normalized; only the service's
        // own numbered delimiters can start a post. Cache hits use the same format.
        $posts = preg_split('/\n\n(?=[1-9][0-9]?\) )/u', $answer);
        return is_array($posts) && count($posts) >= 2 && count($posts) <= 21 ? $posts : [$answer];
    }

    public static function handleUserPrompt(string $prompt, array $recentHistory = []): string
    {
        $text = trim($prompt);
        if ($text === '') {
            return 'لطفاً پیام یا دستور خود را بنویسید. برای مشاهده ابزارهای فعال عبارت `/help` را بفرستید.';
        }

        $cfg = get_app_config();
        $aiCfg = $cfg['ai_bridge'] ?? [];
        $enabledTools = self::getEnabledTools();

        if ($text === '/help' || $text === 'راهنما' || $text === 'کمک') {
            return self::getHelpText($aiCfg, $enabledTools);
        }

        // Match against Admin-configured enabled web_tools!
        foreach ($enabledTools as $tool) {
            $cmd = trim((string)($tool['command'] ?? ''));
            if ($cmd === '') {
                continue;
            }
            if ($text === $cmd || strpos($text, $cmd . ' ') === 0) {
                $arg = trim(substr($text, strlen($cmd)));
                return self::executeTool($tool, $arg);
            }
        }

        // Disabled/removed commands must NEVER run via a fallback AI/Wikipedia query.
        if (preg_match('#^/[a-zA-Z0-9_\-]+(?:\s|$)#', $text)) {
            return '⛔ این دستور توسط مدیر فعال نشده یا حذف شده است. برای دیدن ابزارهای موجود، /help را بفرستید.';
        }

        // Direct URL pasted -> if any url_reader tool is enabled, read it automatically
        if (preg_match('/^https?:\/\/\S+$/i', $text)) {
            foreach ($enabledTools as $tool) {
                if (($tool['type'] ?? '') === 'url_reader') {
                    return self::fetchReadablePage($text);
                }
            }
        }

        // Conversational AI via OpenAI-compatible API
        $apiKey = trim((string)($aiCfg['api_key'] ?? ''));
        if ($apiKey !== '') {
            return self::callChatCompletionApi($text, $recentHistory, $aiCfg);
        }

        // Fallback when AI API key isn't set: if a Wikipedia tool is enabled, try searching it automatically!
        $wikiTry = '';
        foreach ($enabledTools as $tool) {
            if (($tool['type'] ?? '') === 'wikipedia') {
                $wikiTry = self::searchWikipedia($text, (string)($tool['lang'] ?? 'fa'), true);
                if ($wikiTry !== '') {
                    break;
                }
            }
        }

        $helpFooter = self::getHelpText($aiCfg, $enabledTools);
        if ($wikiTry !== '') {
            return $wikiTry . "\n\n---\n💡 *پاسخ بالا به صورت خودکار از ویکی‌پدیا استخراج شد. برای مشاهده همه ابزارها `/help` را بزنید.*";
        }
        return $helpFooter;
    }

    private static function executeTool(array $tool, string $arg): string
    {
        $type = (string)($tool['type'] ?? '');
        $title = (string)($tool['title'] ?? 'ابزار');

        switch ($type) {
            case 'builtin_status':
                return self::runServerConnectivityCheck();

            case 'domestic_rates':
                return self::fetchDomesticRates();

            case 'wikipedia':
                $lang = preg_replace('/[^a-z\-]/', '', strtolower((string)($tool['lang'] ?? 'fa'))) ?: 'fa';
                if ($arg === '') {
                    return "🔎 لطفاً موضوع مورد نظر را بعد از دستور `{$tool['command']}` بنویسید.\nمثال: `{$tool['command']} ایران`";
                }
                return self::searchWikipedia($arg, $lang, false);

            case 'duckduckgo':
                if ($arg === '') {
                    return "🌐 لطفاً عبارت جستجو را بعد از دستور `{$tool['command']}` بنویسید.\nمثال: `{$tool['command']} اخبار علمی`";
                }
                return self::webSearchLite($arg);

            case 'telegram_public':
                return self::fetchPublicTelegramChannel($arg);

            case 'url_reader':
                if ($arg === '' || !preg_match('/^https?:\/\/\S+$/i', $arg)) {
                    return "📄 لطفاً آدرس کامل صفحه وب را بعد از دستور `{$tool['command']}` وارد کنید.\nمثال: `{$tool['command']} https://example.com`";
                }
                return self::fetchReadablePage($arg);

            case 'custom_url':
                $tpl = trim((string)($tool['url_template'] ?? ''));
                if ($tpl === '') {
                    return "⚠️ آدرس منبع برای ابزار «{$title}» در پنل مدیریت تنظیم نشده است.";
                }
                if (strpos($tpl, '{query}') !== false) {
                    if ($arg === '') {
                        return "🔎 لطفاً عبارت مورد نظر را بعد از دستور `{$tool['command']}` بنویسید.";
                    }
                    $finalUrl = str_replace('{query}', rawurlencode($arg), $tpl);
                } else {
                    $finalUrl = $tpl;
                }
                return self::fetchCustomSource($finalUrl, $title);

            default:
                return '⚠️ نوع این ابزار پشتیبانی نمی‌شود.';
        }
    }

    private static function getHelpText(array $aiCfg, array $enabledTools): string
    {
        $hasKey = trim((string)($aiCfg['api_key'] ?? '')) !== '';
        $statusLine = $hasKey
            ? '🟢 هوش مصنوعی گفتگو: **فعال (`' . ($aiCfg['model'] ?? 'deepseek-chat') . '`)**'
            : '🟡 هوش مصنوعی گفتگو: **کلید API ثبت نشده** (ابزارهای جستجو و وب فعال هستند)';

        $lines = [
            "🤖 **راهنمای دستیار هوشمند و ابزارهای وب سرور**",
            $statusLine,
            "",
        ];

        if (!empty($enabledTools)) {
            $lines[] = "🛠️ **ابزارهای فعال (قابل ویرایش و کم/زیاد شدن توسط مدیر):**";
            foreach ($enabledTools as $t) {
                $ico = $t['icon'] ?? '🔹';
                $cmd = $t['command'] ?? '';
                $ph  = !empty($t['placeholder']) ? (' `' . $t['placeholder'] . '`') : '';
                $lines[] = "• {$ico} **`{$cmd}`**{$ph} — {$t['title']}";
            }
        } else {
            $lines[] = "ℹ️ در حال حاضر ابزار وب جانبی توسط مدیر فعال نشده است.";
        }

        return implode("\n", $lines);
    }

    /**
     * Read only Telegram's public, account-free HTML preview. No phone number,
     * Bot API, client credentials or private/group access. Cache for 90 seconds
     * to protect small shared hosts and respect the upstream service.
     */
    public static function fetchPublicTelegramChannel(string $input): string
    {
        $candidate = ltrim(trim($input), '@');
        if (!preg_match('/^[A-Za-z][A-Za-z0-9_]{4,31}$/D', $candidate)) {
            return '✈️ آیدی عمومی کانال را بعد از دستور وارد کنید؛ نمونه: `/telegram telegram` یا `/telegram @telegram`. لینک دعوت/گروه خصوصی پشتیبانی نمی‌شود.';
        }
        $channel = strtolower($candidate);
        $dir = DATA_DIR . '/public_feed_cache';
        if (!is_dir($dir) && !@mkdir($dir, 0755, true) && !is_dir($dir)) {
            return '❌ پوشه حافظه موقت خواندن کانال روی هاست قابل نوشتن نیست.';
        }
        $path = $dir . '/' . hash('sha256', $channel) . '.json';
        return with_file_lock('public_feed_' . $channel, function () use ($channel, $path): string {
            $cached = read_json_safe($path, []);
            if (is_array($cached) && ($cached['expires'] ?? 0) > time() && is_string($cached['answer'] ?? null)) {
                return $cached['answer'];
            }
            $res = self::httpGet('https://t.me/s/' . rawurlencode($channel), 14, 1600000);
            if (empty($res['ok']) || parse_url((string)($res['final_url'] ?? ''), PHP_URL_HOST) !== 't.me') {
                return '❌ پیش‌نمایش کانال از هاست در دسترس نیست؛ اتصال خروجی به t.me یا تنظیمات کانال را بررسی کنید.';
            }
            $parsed = self::parseTelegramPublicPreview((string)$res['body'], $channel);
            if (!$parsed['ok']) {
                return '⚠️ این آیدی کانال عمومی با پیش‌نمایش قابل مشاهده نیست، یا تلگرام قالب صفحه را تغییر داده است. گروه/کانال خصوصی قابل خواندن نیست.';
            }
            $lines = ["✈️ **{$parsed['title']}** — " . count($parsed['posts']) . " پست اخیرِ قابل نمایش از کانال عمومی @{$channel}",
                '🔎 منبع: https://t.me/s/' . $channel . ' (پیش‌نمایش عمومی؛ فقط موارد واقعاً قابل نمایش، نه فایل/نسخهٔ اصلی یا تضمین ترتیب/تعداد دقیق)'];
            foreach ($parsed['posts'] as $i => $post) {
                $n = $i + 1;
                $date = $post['date'] !== '' ? ' · ' . $post['date'] : '';
                $kind = $post['media_type'] !== '' ? ' · ' . self::telegramKindLabel($post['media_type']) : '';
                $availability = $kind !== '' ? ($post['media_url'] !== '' ? ' · نسخهٔ قابل‌دریافت در پیش‌نمایش (با محدودیت حجم)' : ' · فایل اصلی در پیش‌نمایش موجود نیست') : '';
                $lines[] = "{$n}) {$post['text']}{$date}{$kind}{$availability}\n🔗 {$post['url']}";
            }
            $answer = implode("\n\n", $lines);
            if (!write_json_atomic($path, ['expires' => time() + 90, 'import_expires' => time() + 600, 'answer' => $answer, 'posts' => $parsed['posts']], false)) {
                throw new RuntimeException('ذخیره حافظه موقت کانال ممکن نیست.');
            }
            return $answer;
        });
    }

    /** Pure parser: safe to regression-test with a local public-preview fixture. */
    public static function parseTelegramPublicPreview(string $html, string $channel): array
    {
        if (!class_exists('DOMDocument')) return ['ok' => false, 'posts' => []];
        if (!preg_match('/^[a-zA-Z][a-zA-Z0-9_]{4,31}$/D', $channel) || strlen($html) > 1600000) {
            return ['ok' => false, 'posts' => []];
        }
        $doc = new DOMDocument();
        $wasInternal = libxml_use_internal_errors(true);
        try {
            if (!@$doc->loadHTML('<?xml encoding="utf-8"?>' . $html,
                LIBXML_NONET | LIBXML_NOERROR | LIBXML_NOWARNING | LIBXML_COMPACT)) {
                return ['ok' => false, 'posts' => []];
            }
            $xp = new DOMXPath($doc);
            $titleNode = $xp->query("//*[contains(concat(' ', normalize-space(@class), ' '), ' tgme_channel_info_header_title ')]")->item(0);
            $title = $titleNode ? trim($titleNode->textContent) : '@' . $channel;
            $title = mb_substr(preg_replace('/\s+/u', ' ', $title) ?? $title, 0, 80);
            $nodes = $xp->query("//*[contains(concat(' ', normalize-space(@class), ' '), ' tgme_widget_message ')][@data-post]");
            $posts = [];
            foreach ($nodes as $node) {
                $id = (string)$node->getAttribute('data-post');
                if (!preg_match('#^' . preg_quote($channel, '#') . '/([1-9][0-9]{0,12})$#iD', $id, $m)) continue;
                $textNode = $xp->query(".//*[contains(concat(' ', normalize-space(@class), ' '), ' tgme_widget_message_text ')]", $node)->item(0);
                $text = $textNode ? self::telegramPlainText($textNode) : '';
                $text = trim(preg_replace('/\s+/u', ' ', $text) ?? $text);
                if ($text === '') {
                    $mediaNode = $xp->query(".//*[contains(@class, 'message_photo') or contains(@class, 'message_video') or contains(@class, 'message_document') or contains(@class, 'message_audio')]", $node)->item(0);
                    $text = $mediaNode ? '[پست رسانه‌ای؛ برای دیدن عکس/ویدیو لینک را باز کنید]' : '[پست بدون متنِ قابل نمایش]';
                }
                $timeNode = $xp->query('.//time[@datetime]', $node)->item(0);
                $date = $timeNode ? substr((string)$timeNode->getAttribute('datetime'), 0, 16) : '';
                $media = self::telegramMediaFromNode($xp, $node);
                $posts[(int)$m[1]] = [
                    'post_number' => (int)$m[1],
                    'media_type' => $media['type'], 'media_url' => $media['url'],
                    'text' => mb_substr($text, 0, 235) . (mb_strlen($text) > 235 ? '…' : ''),
                    'date' => $date,
                    'url' => 'https://t.me/' . strtolower($channel) . '/' . $m[1],
                ];
            }
            if (!$posts) return ['ok' => false, 'posts' => []];
            ksort($posts, SORT_NUMERIC);
            return ['ok' => true, 'title' => $title ?: '@' . $channel,
                'posts' => array_values(array_slice($posts, -20, null, true))];
        } finally {
            libxml_clear_errors();
            libxml_use_internal_errors($wasInternal);
        }
    }

    private static function telegramKindLabel(string $kind): string
    {
        return ['photo' => 'عکس', 'video' => 'ویدیو', 'file' => 'فایل', 'audio' => 'صوت', 'voice' => 'ویس'][$kind] ?? 'رسانه';
    }

    /** Never accept an arbitrary URL from Telegram HTML or from the browser.
     * Every CDN redirect is checked against the same HTTPS-only allowlist.
     */
    public static function isAllowedTelegramCdn(string $url): bool
    {
        if (strlen($url) > 5000) return false;
        $p = @parse_url($url);
        if (!is_array($p) || strtolower((string)($p['scheme'] ?? '')) !== 'https' ||
            !preg_match('/^cdn[0-9]{1,3}\\.telesco\\.pe$/D', strtolower((string)($p['host'] ?? ''))) ||
            isset($p['port']) || isset($p['user']) || isset($p['pass']) || isset($p['fragment']) ||
            !preg_match('~^/file/[A-Za-z0-9_./%+\\-]+$~D', (string)($p['path'] ?? ''))) return false;
        return !isset($p['query']) || preg_match('/^token=[A-Za-z0-9_~.\\-]+$/D', $p['query']) === 1;
    }

    private static function telegramMediaFromNode(DOMXPath $xp, DOMNode $node): array
    {
        $cases = [
            ['photo', ".//a[contains(@class,'tgme_widget_message_photo_wrap')]", 'style'],
            ['video', ".//video[@src]", 'src'],
            ['video', ".//*[contains(@class,'tgme_widget_message_video_player')]", 'href'],
            ['file', ".//*[contains(@class,'tgme_widget_message_document')]", 'href'],
            ['audio', ".//audio[@src]", 'src'],
            ['audio', ".//*[contains(@class,'tgme_widget_message_audio')]", 'href'],
        ];
        foreach ($cases as [$kind, $query, $attribute]) {
            $found = $xp->query($query, $node)->item(0);
            if (!($found instanceof DOMElement)) continue;
            $value = html_entity_decode((string)$found->getAttribute($attribute), ENT_QUOTES | ENT_HTML5, 'UTF-8');
            if ($attribute === 'style') {
                $value = preg_match('~background-image\\s*:\\s*url\\(\\s*[\'\"]?([^\'\")]+)~i', $value, $mm) ? $mm[1] : '';
            }
            return ['type' => $kind, 'url' => self::isAllowedTelegramCdn($value) ? $value : ''];
        }
        return ['type' => '', 'url' => ''];
    }

    private static function telegramCachePath(string $channel): string
    {
        return DATA_DIR . '/public_feed_cache/' . hash('sha256', strtolower($channel)) . '.json';
    }

    /** Import plan is returned only for the EXACT preview just shown. The
     * browser receives post numbers, never a user-controlled remote URL.
     */
    public static function getTelegramImportPlan(string $prompt, string $answer): array
    {
        if (strpos($answer, '✈️ **') !== 0) return [];
        foreach (self::getEnabledTools() as $tool) {
            if (($tool['type'] ?? '') !== 'telegram_public') continue;
            $cmd = (string)($tool['command'] ?? '');
            if ($cmd === '' || strpos(trim($prompt), $cmd . ' ') !== 0) continue;
            $channel = strtolower(ltrim(trim(substr(trim($prompt), strlen($cmd))), '@'));
            if (!preg_match('/^[a-z][a-z0-9_]{4,31}$/D', $channel)) return [];
            $cache = read_json_safe(self::telegramCachePath($channel), []);
            if (!is_array($cache) || ($cache['import_expires'] ?? 0) < time() || ($cache['answer'] ?? '') !== $answer) return [];
            $plan = [];
            foreach ($cache['posts'] ?? [] as $post) {
                if (!is_array($post) || empty($post['media_url'])) continue;
                $plan[] = ['channel' => $channel, 'post_number' => (int)$post['post_number'], 'media_type' => $post['media_type']];
            }
            return array_slice($plan, 0, 20);
        }
        return [];
    }

    public static function cachedTelegramPost(string $channel, int $postNumber): ?array
    {
        $channel = strtolower($channel);
        if (!preg_match('/^[a-z][a-z0-9_]{4,31}$/D', $channel) || $postNumber < 1) return null;
        $cache = read_json_safe(self::telegramCachePath($channel), []);
        if (!is_array($cache) || ($cache['import_expires'] ?? 0) < time()) return null;
        foreach ($cache['posts'] ?? [] as $post) {
            if (is_array($post) && ($post['post_number'] ?? 0) === $postNumber) return $post;
        }
        return null;
    }

    public static function downloadTelegramPreviewMedia(string $url, string $kind): array
    {
        if (!self::isAllowedTelegramCdn($url)) return ['ok' => false, 'error' => 'نشانی رسانهٔ عمومی مجاز نیست.'];
        $caps = ['photo' => 2097152, 'video' => 6291456, 'audio' => 3145728, 'voice' => 3145728, 'file' => 5242880];
        $maxBytes = $caps[$kind] ?? 0;
        if (!$maxBytes) return ['ok' => false, 'error' => 'نوع این رسانه در پیش‌نمایش پشتیبانی نمی‌شود.'];
        $res = self::httpGet($url, 9, $maxBytes, true);
        if (empty($res['ok']) || ($res['body'] ?? '') === '') {
            return ['ok' => false, 'error' => 'دریافت ' . self::telegramKindLabel($kind) . ' از CDN ممکن نشد یا حجم آن از سقف ' . format_bytes_fa($maxBytes) . ' گذشت. لینک پست اصلی را باز کنید.'];
        }
        return ['ok' => true, 'body' => $res['body']];
    }

    private static function telegramPlainText(DOMNode $node): string
    {
        if ($node->nodeType === XML_TEXT_NODE) return (string)$node->nodeValue;
        if ($node->nodeType === XML_ELEMENT_NODE && in_array(strtolower($node->nodeName), ['script', 'style'], true)) return '';
        if (strtolower($node->nodeName) === 'br') return "\n";
        $text = '';
        foreach ($node->childNodes as $child) $text .= self::telegramPlainText($child);
        return $text;
    }

    public static function fetchDomesticRates(): string
    {
        return DomesticRates::fetch(function (string $url): array {
            return self::httpGet($url, 6, 1800000);
        });
    }

    public static function runServerConnectivityCheck(): string
    {
        if (!function_exists('curl_init')) {
            return '❌ افزونه PHP cURL روی این هاست فعال نیست.';
        }

        $targets = [
            ['name' => 'ویکی‌پدیا فارسی (بین‌الملل)', 'url' => 'https://fa.wikipedia.org/w/api.php?action=query&meta=siteinfo&format=json'],
            ['name' => 'سرویس هوش مصنوعی DeepSeek',   'url' => 'https://api.deepseek.com'],
            ['name' => 'گوگل جهانی (Google)',         'url' => 'https://www.google.com/generate_204'],
            ['name' => 'کلودفلر (Cloudflare)',        'url' => 'https://1.1.1.1/cdn-cgi/trace'],
            ['name' => 'شبکه داخلی ایران (آپارات)',   'url' => 'https://www.aparat.com'],
        ];

        $lines = ["📡 **نتیجه بررسی زنده اتصال سرور هاست شما:**\n"];
        $intlUp = 0;

        foreach ($targets as $idx => $t) {
            $t0 = microtime(true);
            $ch = curl_init($t['url']);
            curl_setopt_array($ch, [
                CURLOPT_NOBODY => false,
                CURLOPT_RETURNTRANSFER => true,
                CURLOPT_FOLLOWLOCATION => true,
                CURLOPT_MAXREDIRS => 2,
                CURLOPT_CONNECTTIMEOUT => 5,
                CURLOPT_TIMEOUT => 7,
                CURLOPT_USERAGENT => 'HamrahHealthCheck/3.0',
                CURLOPT_RANGE => '0-512',
            ]);
            @curl_exec($ch);
            $code = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
            $err  = curl_errno($ch);
            curl_close($ch);
            $ms = (int)round((microtime(true) - $t0) * 1000);

            if ($err === 0 && $code >= 200 && $code < 500) {
                $lines[] = "✅ **{$t['name']}:** برقرار ({$ms} ms)";
                if ($idx < 4) $intlUp++;
            } else {
                $lines[] = "🔴 **{$t['name']}:** بدون دسترسی";
            }
        }

        $lines[] = "";
        if ($intlUp >= 2) {
            $lines[] = "🟢 **وضعیت:** سرور هاست شما در این لحظه به اینترنت بین‌الملل متصل است.";
        } elseif ($intlUp === 1) {
            $lines[] = "🟡 **وضعیت:** دسترسی بین‌الملل سرور محدود است.";
        } else {
            $lines[] = "🟠 **وضعیت:** خروجی بین‌الملل دیتاسنتر قطع است، اما پیام‌رسان داخلی و فضای ابری شخصی ۱۰۰٪ فعال است.";
        }

        return implode("\n", $lines);
    }

    public static function searchWikipedia(string $query, string $primaryLang = 'fa', bool $quietIfEmpty = false): string
    {
        $q = trim($query);
        if ($q === '') {
            return $quietIfEmpty ? '' : 'لطفاً عبارت مورد نظر را وارد کنید.';
        }

        $langs = [$primaryLang];

        foreach ($langs as $lang) {
            $searchUrl = "https://{$lang}.wikipedia.org/w/api.php?action=query&list=search&srsearch="
                . rawurlencode($q) . "&srlimit=3&format=json&utf8=1";
            $res = self::httpGet($searchUrl, 10);
            if ($res['ok']) {
                $json = json_decode($res['body'], true);
                $hits = $json['query']['search'] ?? [];
                if (!empty($hits)) {
                    $topTitle = $hits[0]['title'];
                    $extractUrl = "https://{$lang}.wikipedia.org/w/api.php?action=query&prop=extracts&exintro=1&explaintext=1&titles="
                        . rawurlencode($topTitle) . "&format=json&utf8=1";
                    $extRes = self::httpGet($extractUrl, 10);
                    if ($extRes['ok']) {
                        $extJson = json_decode($extRes['body'], true);
                        $pages = $extJson['query']['pages'] ?? [];
                        $firstPage = reset($pages);
                        $extract = trim((string)($firstPage['extract'] ?? ''));
                        if ($extract !== '') {
                            // The intro is not the whole encyclopedia entry.
                            // Never cut silently: include a direct source link
                            // even for short results, and label long excerpts.
                            $sourceUrl = "https://{$lang}.wikipedia.org/wiki/" . str_replace('%2F', '/', rawurlencode(str_replace(' ', '_', (string)$topTitle)));
                            $wasShortened = mb_strlen($extract) > 2800;
                            if ($wasShortened) $extract = mb_substr($extract, 0, 2800) . '…';
                            $otherTitles = [];
                            for ($k = 1; $k < count($hits); $k++) {
                                $otherTitles[] = '`' . $hits[$k]['title'] . '`';
                            }
                            $footer = !empty($otherTitles)
                                ? "\n\n🔎 مقالات مرتبط: " . implode(' ، ', $otherTitles)
                                : '';
                            $notice = $wasShortened ? "\n\n⚠️ این پاسخ فقط بخشی از مقدمه است؛ ادامه و متن کامل را در منبع بخوانید." : "\n\nℹ️ این متن مقدمهٔ مقاله است؛ متن کامل در منبع قرار دارد.";
                            return "📚 **{$topTitle}** (Wikipedia `{$lang}`)\n\n{$extract}{$footer}{$notice}\n🔗 منبع: {$sourceUrl}";
                        }
                    }
                }
            }
        }

        return $quietIfEmpty ? '' : '❌ نتیجه‌ای در ویکی‌پدیا یافت نشد یا دسترسی بین‌الملل سرور برقرار نیست.';
    }

    public static function webSearchLite(string $query): string
    {
        $url = 'https://html.duckduckgo.com/html/?q=' . rawurlencode($query);
        $res = self::httpGet($url, 12);
        if (!$res['ok'] || $res['body'] === '') {
            $wiki = self::searchWikipedia($query, 'fa', true);
            if ($wiki !== '') return $wiki;
            return '❌ امکان اتصال به موتور جستجو از سرور هاست وجود ندارد.';
        }

        $html = $res['body'];
        $results = [];
        if (preg_match_all('/<a[^>]+class="[^"]*result__a[^"]*"[^>]+href="([^"]+)"[^>]*>(.*?)<\/a>.*?(?:<a[^>]+class="[^"]*result__snippet[^"]*"[^>]*>(.*?)<\/a>|<div[^>]+class="[^"]*result__snippet[^"]*"[^>]*>(.*?)<\/div>)/si', $html, $matches, PREG_SET_ORDER)) {
            foreach (array_slice($matches, 0, 6) as $idx => $m) {
                $rawHref = html_entity_decode($m[1], ENT_QUOTES | ENT_HTML5, 'UTF-8');
                if (preg_match('/uddg=([^&]+)/', $rawHref, $um)) {
                    $rawHref = rawurldecode($um[1]);
                }
                $title = trim(strip_tags(html_entity_decode($m[2], ENT_QUOTES | ENT_HTML5, 'UTF-8')));
                $snip  = trim(strip_tags(html_entity_decode(($m[3] !== '' ? $m[3] : ($m[4] ?? '')), ENT_QUOTES | ENT_HTML5, 'UTF-8')));
                $num = $idx + 1;
                $results[] = "{$num}. **{$title}**\n{$snip}\n🔗 `{$rawHref}`";
            }
        }

        if (empty($results)) {
            $wiki = self::searchWikipedia($query, 'fa', true);
            return $wiki !== '' ? $wiki : 'نتیجه‌ای برای این جستجو دریافت نشد.';
        }

        return "🌐 **نتایج جستجوی وب برای «{$query}»:**\n\n" . implode("\n\n", $results);
    }

    /**
     * Fetch custom admin-defined URL (supports RSS/Atom XML feeds, JSON APIs, and HTML pages!)
     */
    public static function fetchCustomSource(string $url, string $sourceTitle): string
    {
        if (!self::isSafeExternalUrl($url)) {
            return '⛔ آدرس اینترنتی نامعتبر یا غیرمجاز است.';
        }

        $res = self::httpGet($url, 15, 524288);
        if (!$res['ok'] || $res['body'] === '') {
            return "❌ دریافت اطلاعات از منبع «{$sourceTitle}» ممکن نشد.";
        }

        $body = trim($res['body']);

        // 1. Check if JSON response
        if (($body[0] ?? '') === '{' || ($body[0] ?? '') === '[') {
            $json = json_decode($body, true);
            if (is_array($json)) {
                $pretty = json_encode($json, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT);
                $jsonText = (string)$pretty;
                if (mb_strlen($jsonText) > 3000) {
                    return "📡 **{$sourceTitle}**\n⚠️ خروجی JSON طولانی است؛ فقط بخش آغازین نمایش داده می‌شود، ممکن است ساختار در اینجا کامل نباشد. کل داده در لینک منبع است:\n"
                        . mb_substr($jsonText, 0, 3000) . "…\n🔗 منبع کامل: {$url}";
                }
                return "📡 **{$sourceTitle}**\n```json\n{$jsonText}\n```\n🔗 منبع: {$url}";
            }
        }

        // 2. Check if RSS / Atom XML feed
        if (stripos($body, '<rss') !== false || stripos($body, '<feed') !== false) {
            if (preg_match_all('/<item\b[^>]*>(.*?)<\/item>/si', $body, $items)) {
                $out = [];
                foreach (array_slice($items[1], 0, 8) as $idx => $itemXml) {
                    $t = '';
                    $d = '';
                    $l = '';
                    if (preg_match('/<title[^>]*><!\[CDATA\[(.*?)\]\]><\/title>|<title[^>]*>(.*?)<\/title>/si', $itemXml, $tm)) {
                        $t = trim(strip_tags(html_entity_decode($tm[1] !== '' ? $tm[1] : $tm[2], ENT_QUOTES | ENT_HTML5, 'UTF-8')));
                    }
                    if (preg_match('/<description[^>]*><!\[CDATA\[(.*?)\]\]><\/description>|<description[^>]*>(.*?)<\/description>/si', $itemXml, $dm)) {
                        $d = mb_substr(trim(strip_tags(html_entity_decode($dm[1] !== '' ? $dm[1] : $dm[2], ENT_QUOTES | ENT_HTML5, 'UTF-8'))), 0, 180);
                    }
                    if (preg_match('/<link[^>]*>(.*?)<\/link>/si', $itemXml, $lm)) {
                        $l = trim(strip_tags($lm[1]));
                    }
                    if ($t !== '') {
                        $num = $idx + 1;
                        $out[] = "{$num}. **{$t}**" . ($d !== '' ? "\n{$d}" : '') . ($l !== '' ? "\n🔗 `{$l}`" : '');
                    }
                }
                if (!empty($out)) {
                    $limited = count($items[1]) > 8 ? "⚠️ فقط ۸ مورد نخست این خوراک نمایش داده شد؛ بقیه در منبع است.\n" : '';
                    return "📰 **{$sourceTitle}:**\n{$limited}\n" . implode("\n\n", $out) . "\n🔗 کل خوراک: {$url}";
                }
            }
        }

        // 3. Fallback: clean HTML extraction
        return self::extractReadableHtml($body, $url, $sourceTitle);
    }

    public static function fetchReadablePage(string $url): string
    {
        if (!self::isSafeExternalUrl($url)) {
            return '⛔ آدرس اینترنتی نامعتبر یا مربوط به شبکه داخلی سرور است.';
        }

        $res = self::httpGet($url, 15, 524288);
        if (!$res['ok'] || $res['body'] === '') {
            return '❌ دریافت این صفحه از طریق سرور هاست ممکن نشد.';
        }

        return self::extractReadableHtml($res['body'], $url, '');
    }

    private static function extractReadableHtml(string $html, string $url, string $overrideTitle = ''): string
    {
        $title = $overrideTitle;
        if ($title === '' && preg_match('/<title[^>]*>(.*?)<\/title>/si', $html, $tm)) {
            $title = trim(html_entity_decode(strip_tags($tm[1]), ENT_QUOTES | ENT_HTML5, 'UTF-8'));
        }
        if ($title === '') {
            $title = parse_url($url, PHP_URL_HOST) ?: 'صفحه وب';
        }

        $clean = preg_replace('/<(script|style|noscript|svg|header|footer|nav|aside|form|iframe)\b[^>]*>.*?<\/\1>/si', ' ', $html) ?? $html;
        $clean = preg_replace('/<(br|p|div|li|h[1-6]|tr|article|section)\b[^>]*>/i', "\n", $clean) ?? $clean;
        $text  = strip_tags($clean);
        $text  = html_entity_decode($text, ENT_QUOTES | ENT_HTML5, 'UTF-8');
        $text  = preg_replace('/[ \t]+/', ' ', $text) ?? $text;
        $text  = preg_replace('/\n\s*\n+/', "\n\n", $text) ?? $text;
        $text  = trim($text);

        if ($text === '') {
            return "📄 **{$title}**\nمتن قابل استخراجی در این صفحه یافت نشد.";
        }
        if (mb_strlen($text) > 3500) {
            $text = mb_substr($text, 0, 3500) . "\n\n⚠️ فقط آغاز متن نمایش داده شد؛ ادامه و متن کامل را در لینک منبع بالا بخوانید.";
        }

        return "📄 **{$title}**\n🔗 منبع: `{$url}`\n\n{$text}";
    }

    private static function isSafeExternalUrl(string $url): bool
    {
        $parts = @parse_url($url);
        if (!is_array($parts) || !in_array(strtolower($parts['scheme'] ?? ''), ['http', 'https'], true) ||
            empty($parts['host']) || isset($parts['user']) || isset($parts['pass'])) {
            return false;
        }
        $port = (int)($parts['port'] ?? (($parts['scheme'] === 'https') ? 443 : 80));
        if (!in_array($port, [80, 443], true)) return false;
        $host = strtolower(trim($parts['host'], '[]'));
        if ($host === 'localhost' || strpos($host, '.local') !== false || strpos($host, '.internal') !== false) return false;

        $ips = filter_var($host, FILTER_VALIDATE_IP) ? [$host] : (@gethostbynamel($host) ?: []);
        if (empty($ips)) return false;
        foreach ($ips as $ip) {
            if (!filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE)) return false;
        }
        return true;
    }

    private static function callChatCompletionApi(string $prompt, array $recentHistory, array $aiCfg): string
    {
        if (!function_exists('curl_init')) {
            return '❌ افزونه PHP cURL روی این هاست فعال نیست.';
        }

        $baseUrl = rtrim(trim((string)($aiCfg['api_base_url'] ?? 'https://api.deepseek.com/v1')), '/');
        $endpoint = (substr($baseUrl, -17) === '/chat/completions') ? $baseUrl : ($baseUrl . '/chat/completions');
        if (strpos($endpoint, 'https://') !== 0 || !self::isSafeExternalUrl($endpoint)) {
            return '⛔ آدرس API باید یک نشانی HTTPS عمومی و ایمن باشد.';
        }
        $apiKey = trim((string)($aiCfg['api_key'] ?? ''));
        $model  = trim((string)($aiCfg['model'] ?? 'deepseek-chat'));
        $timeout = max(8, min(60, (int)($aiCfg['timeout_seconds'] ?? 25)));

        $messages = [];
        $sysPrompt = trim((string)($aiCfg['system_prompt'] ?? ''));
        if ($sysPrompt !== '') {
            $messages[] = ['role' => 'system', 'content' => $sysPrompt];
        }

        // The frontend already persisted this very prompt before calling ask_ai.
        // Remove its duplicate from history to avoid sending the question twice.
        if (!empty($recentHistory)) {
            $last = end($recentHistory);
            if (($last['sender'] ?? '') !== 'ai' && trim((string)($last['text'] ?? '')) === $prompt) {
                array_pop($recentHistory);
            }
        }
        foreach (array_slice($recentHistory, -8) as $hm) {
            $txt = trim((string)($hm['text'] ?? ''));
            if ($txt === '' || strpos($txt, '/') === 0) {
                continue;
            }
            $role = (($hm['sender'] ?? '') === 'ai') ? 'assistant' : 'user';
            $messages[] = ['role' => $role, 'content' => mb_substr($txt, 0, 1500)];
        }
        $messages[] = ['role' => 'user', 'content' => $prompt];

        $payload = json_encode([
            'model' => $model,
            'messages' => $messages,
            'temperature' => 0.6,
            'max_tokens' => 1500,
        ], JSON_UNESCAPED_UNICODE);

        $host = (string)parse_url($endpoint, PHP_URL_HOST);
        $port = (int)(parse_url($endpoint, PHP_URL_PORT) ?: 443);
        $ips = @gethostbynamel($host) ?: [];
        if (empty($ips)) return '❌ آدرس سرویس هوش مصنوعی قابل یافتن نیست.';
        $ch = curl_init($endpoint);
        curl_setopt_array($ch, [
            CURLOPT_POST => true,
            CURLOPT_POSTFIELDS => $payload,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_CONNECTTIMEOUT => 10,
            CURLOPT_TIMEOUT => $timeout,
            CURLOPT_FOLLOWLOCATION => false,
            CURLOPT_IPRESOLVE => CURL_IPRESOLVE_V4,
            CURLOPT_RESOLVE => ["{$host}:{$port}:{$ips[0]}"],
            CURLOPT_HTTPHEADER => [
                'Content-Type: application/json',
                'Authorization: Bearer ' . $apiKey,
                'Accept: application/json',
            ],
        ]);

        $raw = curl_exec($ch);
        $httpCode = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $err = curl_error($ch);
        curl_close($ch);

        if ($raw === false || $raw === '') {
            return '❌ خطا در ارتباط سرور با سرویس هوش مصنوعی: ' . ($err ?: 'پاسخی دریافت نشد');
        }

        $decoded = json_decode((string)$raw, true);
        if ($httpCode >= 400) {
            $apiErr = $decoded['error']['message'] ?? mb_substr((string)$raw, 0, 220);
            return "⚠️ خطای سرویس هوش مصنوعی (کد {$httpCode}):\n`{$apiErr}`";
        }

        $reply = trim((string)($decoded['choices'][0]['message']['content'] ?? ''));
        if ($reply !== '' && ($decoded['choices'][0]['finish_reason'] ?? '') === 'length') {
            $reply .= "\n\n⚠️ پاسخ به سقف طول سرویس هوش مصنوعی رسید و کامل نیست؛ برای ادامه از دستیار بخواهید «ادامه بده».";
        }
        return $reply !== '' ? $reply : '⚠️ پاسخ معتبری دریافت نشد.';
    }

    /**
     * SSRF-safe bounded HTTP GET. Pin a validated public IPv4 in CURLOPT_RESOLVE,
     * check EVERY redirect target, and never stream private-network responses.
     */
    private static function httpGet(string $url, int $timeout = 10, int $maxBytes = 262144, bool $telegramCdnOnly = false): array
    {
        if (!function_exists('curl_init')) return ['ok' => false, 'body' => '', 'error' => 'cURL disabled'];

        $currentUrl = $url;
        for ($hop = 0; $hop < 4; $hop++) {
            if (($telegramCdnOnly && !self::isAllowedTelegramCdn($currentUrl)) || !self::isSafeExternalUrl($currentUrl)) {
                return ['ok' => false, 'body' => '', 'error' => 'Blocked private or invalid URL'];
            }
            $parts = parse_url($currentUrl);
            $host = trim((string)($parts['host'] ?? ''), '[]');
            $scheme = strtolower((string)($parts['scheme'] ?? 'https'));
            $port = (int)($parts['port'] ?? ($scheme === 'https' ? 443 : 80));
            $ips = filter_var($host, FILTER_VALIDATE_IP) ? [$host] : (@gethostbynamel($host) ?: []);
            $chosenIp = $ips[0] ?? '';
            if ($chosenIp === '') return ['ok' => false, 'body' => '', 'error' => 'Public DNS lookup failed'];

            $body = '';
            $location = '';
            $ch = curl_init($currentUrl);
            $opts = [
                CURLOPT_RETURNTRANSFER => false,
                CURLOPT_FOLLOWLOCATION => false,
                CURLOPT_CONNECTTIMEOUT => 6,
                CURLOPT_TIMEOUT => $timeout,
                CURLOPT_ENCODING => '',
                CURLOPT_IPRESOLVE => CURL_IPRESOLVE_V4,
                CURLOPT_USERAGENT => 'Mozilla/5.0 (compatible; HamrahReader/3.0)',
                CURLOPT_HEADERFUNCTION => function ($ch, $header) use (&$location) {
                    if (stripos($header, 'Location:') === 0) $location = trim(substr($header, 9));
                    return strlen($header);
                },
                CURLOPT_WRITEFUNCTION => function ($ch, $chunk) use (&$body, $maxBytes) {
                    $len = strlen($chunk);
                    if (strlen($body) + $len > $maxBytes) {
                        $body .= substr($chunk, 0, max(0, $maxBytes - strlen($body)));
                        return 0;
                    }
                    $body .= $chunk;
                    return $len;
                },
            ];
            if (!filter_var($host, FILTER_VALIDATE_IP)) {
                $opts[CURLOPT_RESOLVE] = ["{$host}:{$port}:{$chosenIp}"];
            }
            curl_setopt_array($ch, $opts);
            @curl_exec($ch);
            $code = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
            $err = curl_error($ch);
            curl_close($ch);

            if ($code >= 300 && $code < 400 && $location !== '') {
                if (preg_match('#^https?://#i', $location)) {
                    $currentUrl = $location;
                } elseif (strpos($location, '//') === 0) {
                    $currentUrl = $scheme . ':' . $location;
                } else {
                    $base = $scheme . '://' . $host . (($port === 80 || $port === 443) ? '' : ':' . $port);
                    $path = (string)($parts['path'] ?? '/');
                    $currentUrl = $base . (($location[0] === '/') ? $location :
                        (substr($path, 0, (int)strrpos($path, '/') + 1) . $location));
                }
                continue;
            }
            return ['ok' => $code >= 200 && $code < 300 && $err === '',
                'code' => $code, 'body' => $body, 'error' => $err, 'final_url' => $currentUrl];
        }
        return ['ok' => false, 'body' => '', 'error' => 'Too many redirects'];
    }
}
