<?php
declare(strict_types=1);

require_once __DIR__ . '/MediaManager.php';

/** Private, file-backed sticker collection. The image binary uses the same
 * AES-256-GCM media blocks and quota as every other completed upload.
 */
class StickerStore
{
    private const DIR = DATA_DIR . '/stickers';

    private static function path(string $username): string
    {
        $uname = normalize_username($username);
        if (!is_dir(self::DIR) && !@mkdir(self::DIR, 0755, true) && !is_dir(self::DIR)) {
            throw new RuntimeException('پوشهٔ استیکرها قابل ساخت نیست.');
        }
        return self::DIR . '/' . $uname . '.json';
    }

    public static function listFor(string $username): array
    {
        $uname = normalize_username($username);
        $list = read_json_safe(self::path($uname), []);
        if (!is_array($list)) throw new RuntimeException('فهرست استیکرهای شما قابل خواندن نیست.');
        $out = [];
        foreach ($list as $item) {
            if (!is_array($item)) continue;
            $media = MediaManager::getMediaMeta((string)($item['media_id'] ?? ''));
            if ($media === null || ($media['owner'] ?? '') !== $uname || ($media['category'] ?? '') !== 'sticker') continue;
            $out[] = [
                'media' => MediaManager::publicMediaMeta($media),
                'label' => (string)($item['label'] ?? ''),
                'created_at' => (int)($item['created_at'] ?? 0),
            ];
        }
        return $out;
    }

    public static function add(string $username, string $mediaId, string $label = ''): array
    {
        $uname = normalize_username($username);
        $meta = MediaManager::getMediaMeta($mediaId);
        if ($meta === null || ($meta['owner'] ?? '') !== $uname || ($meta['category'] ?? '') !== 'sticker') {
            return ['ok' => false, 'error' => 'استیکر معتبر، رمز‌شده و متعلق به شما پیدا نشد.'];
        }
        return with_file_lock('stickers_' . $uname, function () use ($uname, $mediaId, $label): array {
            $path = self::path($uname);
            $list = read_json_safe($path, []);
            if (!is_array($list)) throw new RuntimeException('فهرست استیکرها مخدوش است.');
            foreach ($list as $entry) {
                if (($entry['media_id'] ?? '') === $mediaId) return ['ok' => true, 'stickers' => self::listFor($uname)];
            }
            if (count($list) >= 200) return ['ok' => false, 'error' => 'حداکثر ۲۰۰ استیکر شخصی می‌توانید بسازید.'];
            // Persist the protective media reference *before* making the pack
            // visible. A failed pack write can roll the reference back; the
            // opposite ordering could orphan a visible sticker permanently.
            MediaManager::addStickerReference($mediaId, $uname);
            $list[] = ['media_id' => $mediaId, 'label' => mb_substr(trim($label), 0, 24), 'created_at' => time()];
            if (!write_json_atomic($path, $list, false)) {
                MediaManager::removeStickerReference($mediaId, $uname);
                throw new RuntimeException('ثبت استیکر ناموفق بود.');
            }
            return ['ok' => true, 'stickers' => self::listFor($uname)];
        });
    }

    public static function remove(string $username, string $mediaId): array
    {
        $uname = normalize_username($username);
        return with_file_lock('stickers_' . $uname, function () use ($uname, $mediaId): array {
            $path = self::path($uname);
            $list = read_json_safe($path, []);
            if (!is_array($list)) throw new RuntimeException('فهرست استیکرها مخدوش است.');
            $next = array_values(array_filter($list, fn($it) => ($it['media_id'] ?? '') !== $mediaId));
            if (count($next) === count($list)) return ['ok' => false, 'error' => 'این استیکر در مجموعهٔ شما نیست.'];
            if (!write_json_atomic($path, $next, false)) throw new RuntimeException('حذف استیکر ثبت نشد.');
            try { MediaManager::removeStickerReference($mediaId, $uname); }
            catch (Throwable $e) {
                // Keep the pack and its durable reference in agreement if
                // persisting the reference update failed.
                if (!write_json_atomic($path, $list, false)) throw new RuntimeException('بازگردانی مجموعهٔ استیکر ناموفق بود.', 0, $e);
                throw $e;
            }
            return ['ok' => true, 'stickers' => self::listFor($uname)];
        });
    }

    public static function clearAccount(string $username): void
    {
        $uname = normalize_username($username);
        with_file_lock('stickers_' . $uname, function () use ($uname): void {
            $path = self::path($uname);
            $list = read_json_safe($path, []);
            if (!is_array($list)) throw new RuntimeException('فهرست استیکرها مخدوش است.');
            foreach ($list as $item) {
                if (!empty($item['media_id'])) MediaManager::removeStickerReference((string)$item['media_id'], $uname);
            }
            if (is_file($path) && !@unlink($path)) throw new RuntimeException('حذف مجموعهٔ استیکرها ناموفق بود.');
        });
    }

    public static function renameAccount(string $old, string $new): void
    {
        $oldPath = self::path($old);
        $newPath = self::path($new);
        if (!is_file($oldPath)) return;
        if (is_file($newPath) || !@rename($oldPath, $newPath)) throw new RuntimeException('انتقال استیکرها به آیدی جدید ناموفق بود.');
    }
}
