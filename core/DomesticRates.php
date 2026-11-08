<?php
declare(strict_types=1);

require_once __DIR__ . '/bootstrap.php';

/** Optional no-key reader for public Iranian-market boards on tgju.org.
 * Never invent rates, silently reuse expired values or claim the exchange is
 * open just because a page was fetched recently. Nothing runs on a daemon.
 */
class DomesticRates
{
    private const CACHE_FILE = DATA_DIR . '/domestic_rates_cache.json';
    private const SOURCE = 'https://www.tgju.org';
    private const BOARDS = [
        '/currency' => ['price_dollar_rl' => 'دلار آزاد (هر ۱ دلار)'],
        '/gold-chart' => ['geram18' => 'طلای ۱۸ عیار (هر گرم)'],
        '/coin' => ['sekee' => 'سکهٔ امامی (هر عدد)'],
    ];

    private static function asciiDigits(string $value): string
    {
        return strtr($value, [
            '۰'=>'0','۱'=>'1','۲'=>'2','۳'=>'3','۴'=>'4','۵'=>'5','۶'=>'6','۷'=>'7','۸'=>'8','۹'=>'9',
            '٠'=>'0','١'=>'1','٢'=>'2','٣'=>'3','٤'=>'4','٥'=>'5','٦'=>'6','٧'=>'7','٨'=>'8','٩'=>'9',
        ]);
    }

    /** Pure parser: only exact TGJU instrument slugs; other tables ignored. */
    public static function parseRows(string $html, array $wanted): array
    {
        if (!class_exists('DOMDocument')) return [];
        $doc = new DOMDocument();
        $previous = libxml_use_internal_errors(true);
        try {
            if (!@$doc->loadHTML('<?xml encoding="UTF-8">' . $html, LIBXML_NONET | LIBXML_NOERROR | LIBXML_NOWARNING)) return [];
            $xpath = new DOMXPath($doc);
            $rows = [];
            foreach ($xpath->query('//tr[@data-market-nameslug]') as $tr) {
                $slug = $tr->getAttribute('data-market-nameslug');
                if (!array_key_exists($slug, $wanted) || isset($rows[$slug])) continue;
                $cells = $xpath->query('./td', $tr);
                $raw = self::asciiDigits($tr->getAttribute('data-price'));
                if ($raw === '' && $cells->length) $raw = self::asciiDigits((string)$cells->item(0)->textContent);
                $clean = preg_replace('/[^0-9]/', '', $raw) ?? '';
                $amount = $clean !== '' && strlen($clean) <= 13 ? (int)$clean : 0;
                // A changed page, captcha or swap of units must fail visibly.
                if ($amount < 100000 || $amount > 1000000000000) continue;
                $time = $cells->length > 4
                    ? trim(preg_replace('/\s+/u', ' ', self::asciiDigits((string)$cells->item(4)->textContent)) ?? '') : '';
                $rows[$slug] = [
                    'label' => $wanted[$slug], 'rial' => $amount,
                    'time' => mb_substr($time, 0, 40),
                    'url' => self::SOURCE . '/profile/' . $slug,
                ];
            }
            return $rows;
        } finally {
            libxml_clear_errors();
            libxml_use_internal_errors($previous);
        }
    }

    /** $fetch is the existing SSRF-safe, size/time-bounded HTTP reader. */
    public static function fetch(callable $fetch): string
    {
        return with_file_lock('domestic_rates', function () use ($fetch): string {
            $cached = read_json_safe(self::CACHE_FILE, []);
            if (!is_array($cached)) $cached = [];
            $now = time();
            if (($cached['checked_at'] ?? 0) > $now - 300 && !empty($cached['rates'])) {
                return self::format($cached['rates'], (int)$cached['checked_at'], false,
                    $cached['missing'] ?? []);
            }
            if (($cached['last_failure_at'] ?? 0) > $now - 60) {
                return self::fallback($cached, 'سایت نرخ یا اتصال سرور موقتاً در دسترس نیست.');
            }

            $rates = [];
            $missing = [];
            foreach (self::BOARDS as $path => $wanted) {
                $url = self::SOURCE . $path;
                $response = $fetch($url);
                $parsed = is_array($response) && !empty($response['ok']) && is_string($response['body'] ?? null)
                    ? self::parseRows($response['body'], $wanted) : [];
                foreach ($wanted as $slug => $_label) {
                    if (isset($parsed[$slug])) $rates[$slug] = $parsed[$slug];
                    else $missing[] = $slug;
                }
            }
            if ($rates) {
                $updated = ['checked_at' => $now, 'rates' => $rates, 'missing' => $missing];
                if (!write_json_atomic(self::CACHE_FILE, $updated, false)) {
                    return '❌ نرخ‌ها دریافت شدند اما ذخیرهٔ امن وضعیت منبع ممکن نشد؛ برای جلوگیری از نمایش دادهٔ نامطمئن، نتیجه نشان داده نمی‌شود.';
                }
                return self::format($rates, $now, false, $missing);
            }
            $cached['last_failure_at'] = $now;
            write_json_atomic(self::CACHE_FILE, $cached, false);
            return self::fallback($cached, 'دریافت یا خواندن تابلوهای عمومی TGJU ناموفق بود.');
        });
    }

    private static function fallback(array $cached, string $reason): string
    {
        $stamp = (int)($cached['checked_at'] ?? 0);
        if ($stamp > 0 && time() - $stamp <= 86400 && !empty($cached['rates'])) {
            return "⚠️ {$reason} داده‌های زیر **ذخیره‌شده و قدیمی** هستند؛ به‌عنوان نرخ لحظه‌ای استفاده نکنید.\n\n"
                . self::format($cached['rates'], $stamp, true, $cached['missing'] ?? []);
        }
        return "❌ {$reason} نرخ قابل اتکا موجود نیست و عددی به‌جای دادهٔ واقعی نمایش داده نمی‌شود.\n"
            . '🔗 منبع عمومی (بدون کلید API): ' . self::SOURCE . '/currency ، ' . self::SOURCE . '/gold-chart ، ' . self::SOURCE . '/coin';
    }

    private static function format(array $rates, int $stamp, bool $staleCache, array $missing): string
    {
        $lines = ['📈 **نرخ‌های عمومی دلار، طلای ۱۸ عیار و سکه**',
            'منبع: شبکه اطلاع‌رسانی طلا و ارز (TGJU)؛ واحد اصلی منبع **ریال** است (هر ۱۰ ریال = ۱ تومان).'];
        foreach (self::BOARDS as $_path => $wanted) {
            foreach ($wanted as $slug => $label) {
                if (!isset($rates[$slug])) {
                    $lines[] = "• {$label}: ⚠️ از منبع دریافت نشد.";
                    continue;
                }
                $item = $rates[$slug];
                $rial = (int)($item['rial'] ?? 0);
                if ($rial <= 0) continue;
                $time = trim((string)($item['time'] ?? ''));
                $timeNote = $time !== '' ? "؛ زمان ثبت روی تابلو: {$time}" : '؛ زمان ثبت روی تابلو: نامشخص';
                $lines[] = "• {$label}: **" . number_format($rial) . ' ریال** (' . number_format($rial / 10, 1) . " تومان){$timeNote}";
                $lines[] = '  🔗 ' . $item['url'];
            }
        }
        $checked = $stamp > 0 ? date('Y-m-d H:i:s', $stamp) . ' (تهران)' : 'نامشخص';
        $lines[] = "🕒 آخرین دریافت از سایت: {$checked}.";
        if ($staleCache) $lines[] = '🔴 وضعیت: منبع فعلاً در دسترس نیست؛ همهٔ اعداد بالا مربوط به دریافت قدیمی‌اند.';
        elseif ($missing) $lines[] = '🟠 وضعیت: دریافت ناقص؛ بعضی تابلوها قابل خواندن نبودند.';
        else $lines[] = '🟡 وضعیت: تازه از سایت دریافت شده؛ زمان معامله ممکن است قدیمی باشد.';
        $lines[] = '⚠️ زمان‌های فقط-ساعتی تاریخ معامله را تأیید نمی‌کنند؛ روزهای تعطیل/بدون معامله را با نرخ لحظه‌ای اشتباه نگیرید.';
        return implode("\n", $lines);
    }
}
