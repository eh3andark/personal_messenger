<?php
declare(strict_types=1);

require_once __DIR__ . '/bootstrap.php';
require_once __DIR__ . '/CryptoEngine.php';
require_once __DIR__ . '/Auth.php';

class MediaManager
{
    private const REGISTRY_DIR = DATA_DIR . '/media_registry';

    private static array $safeExtensions = [
        'jpg', 'jpeg', 'png', 'gif', 'webp', 'bmp',
        'mp4', 'webm', 'mov', 'mkv', 'm4v',
        'mp3', 'ogg', 'wav', 'm4a', 'aac', 'opus',
        'pdf', 'txt', 'zip', 'rar', '7z', 'apk',
        'doc', 'docx', 'xls', 'xlsx', 'ppt', 'pptx', 'csv', 'json'
    ];

    private static function ensureRegistry(): void
    {
        if (!is_dir(self::REGISTRY_DIR)) {
            @mkdir(self::REGISTRY_DIR, 0755, true);
        }
    }

    private static function getMediaMetaPath(string $mediaId): string
    {
        self::ensureRegistry();
        $clean = preg_replace('/[^a-zA-Z0-9_]/', '', $mediaId) ?? 'x';
        $bucket = substr(md5($clean), 0, 2);
        $dir = self::REGISTRY_DIR . '/' . $bucket;
        if (!is_dir($dir)) {
            @mkdir($dir, 0755, true);
        }
        return $dir . '/' . $clean . '.json';
    }

    public static function getMediaMeta(string $mediaId): ?array
    {
        if (!preg_match('/^med_[0-9]{14}_[0-9a-f]{12}$/', $mediaId)) return null;
        $path = self::getMediaMetaPath($mediaId);
        $meta = read_json_safe($path, null);
        return is_array($meta) ? $meta : null;
    }

    /**
     * Only public fields are allowed in chat messages and JSON API responses.
     */
    public static function publicMediaMeta(array $meta): array
    {
        unset($meta['rel_path'], $meta['references'], $meta['public_avatar'], $meta['avatar_for']);
        return $meta;
    }

    private static function removePhysicalMedia(array $meta): void
    {
        if (!empty($meta['rel_path'])) {
            $diskPath = UPLOADS_DIR . '/' . $meta['rel_path'];
            if (is_file($diskPath) && !@unlink($diskPath)) throw new RuntimeException('حذف فایل رسانه از دیسک ناموفق بود.');
        }
        $metaPath = self::getMediaMetaPath((string)($meta['id'] ?? ''));
        if (is_file($metaPath) && !@unlink($metaPath)) throw new RuntimeException('حذف فرادادهٔ رسانه ناموفق بود.');
        $bytes = (int)($meta['size_bytes'] ?? 0);
        $owner = (string)($meta['owner'] ?? '');
        if ($bytes > 0 && $owner !== '' && $owner !== '__deleted__') self::adjustUserUsedBytes($owner, -$bytes);
    }

    /**
     * Attach a media file to a message or a Personal Drive item. References
     * are durable, so deleting one forwarded copy cannot break other copies.
     */
    public static function addChatReference(string $mediaId, string $chatId, int $seq): void
    {
        if ($seq <= 0) return;
        $cid = JsonShardStore::sanitizeChatId($chatId);
        with_file_lock('media_' . $mediaId, function () use ($mediaId, $cid, $seq) {
            $path = self::getMediaMetaPath($mediaId);
            $meta = read_json_safe($path, null);
            if (!is_array($meta)) return;
            if (!isset($meta['references']) || !is_array($meta['references'])) $meta['references'] = [];
            $meta['references']['chat:' . $cid . ':' . $seq] = ['type' => 'chat', 'chat_id' => $cid];
            write_json_atomic($path, $meta, true);
        });
    }

    public static function addDriveReference(string $mediaId, string $username, string $itemId): void
    {
        $owner = normalize_username($username);
        with_file_lock('media_' . $mediaId, function () use ($mediaId, $owner, $itemId) {
            $path = self::getMediaMetaPath($mediaId);
            $meta = read_json_safe($path, null);
            if (!is_array($meta)) return;
            if (!isset($meta['references']) || !is_array($meta['references'])) $meta['references'] = [];
            $meta['references']['drive:' . $owner . ':' . $itemId] = ['type' => 'drive', 'username' => $owner];
            write_json_atomic($path, $meta, true);
        });
    }

    public static function addStickerReference(string $mediaId, string $username): void
    {
        $uname = normalize_username($username);
        with_file_lock('media_' . $mediaId, function () use ($mediaId, $uname): void {
            $path = self::getMediaMetaPath($mediaId);
            $meta = read_json_safe($path, null);
            if (!is_array($meta) || ($meta['owner'] ?? '') !== $uname || ($meta['category'] ?? '') !== 'sticker') {
                throw new RuntimeException('استیکر متعلق به این حساب نیست.');
            }
            if (!is_array($meta['references'] ?? null)) $meta['references'] = [];
            $meta['references']['sticker:' . $uname . ':' . $mediaId] = ['type' => 'sticker', 'username' => $uname];
            if (!write_json_atomic($path, $meta, true)) throw new RuntimeException('ثبت ارجاع استیکر ممکن نشد.');
        });
    }

    public static function removeStickerReference(string $mediaId, string $username): void
    {
        self::removeReference($mediaId, 'sticker:' . normalize_username($username) . ':' . $mediaId);
    }

    private static function removeReference(string $mediaId, string $refKey): void
    {
        with_file_lock('media_' . $mediaId, function () use ($mediaId, $refKey) {
            $path = self::getMediaMetaPath($mediaId);
            $meta = read_json_safe($path, null);
            if (!is_array($meta)) return;
            $refs = is_array($meta['references'] ?? null) ? $meta['references'] : [];
            if (!isset($refs[$refKey])) return;
            unset($refs[$refKey]);
            $meta['references'] = $refs;
            if (empty($refs) && empty($meta['public_avatar'])) {
                self::removePhysicalMedia($meta);
            } else {
                if (!write_json_atomic($path, $meta, true)) throw new RuntimeException('حذف ارجاع رسانه قابل ثبت نیست.');
            }
        });
    }

    public static function removeChatReference(string $mediaId, string $chatId, int $seq): void
    {
        self::removeReference($mediaId, 'chat:' . JsonShardStore::sanitizeChatId($chatId) . ':' . $seq);
    }

    public static function removeDriveReference(string $mediaId, string $username, string $itemId): void
    {
        self::removeReference($mediaId, 'drive:' . normalize_username($username) . ':' . $itemId);
    }

    /**
     * Permanently delete only an UNUSED upload; do not break existing messages.
     */
    public static function deleteMediaById(string $mediaId, string $actingUser, bool $isAdmin = false): bool
    {
        return with_file_lock('media_' . $mediaId, function () use ($mediaId, $actingUser, $isAdmin): bool {
            $meta = self::getMediaMeta($mediaId);
            if ($meta === null) return false;
            if (($meta['owner'] ?? '') !== $actingUser && !$isAdmin) return false;
            if (!empty($meta['references']) || !empty($meta['public_avatar'])) return false;
            self::removePhysicalMedia($meta);
            return true;
        });
    }

    public static function setProfileAvatar(string $mediaId, string $username): bool
    {
        return with_file_lock('media_' . $mediaId, function () use ($mediaId, $username): bool {
            $path = self::getMediaMetaPath($mediaId);
            $meta = read_json_safe($path, null);
            if (!is_array($meta) || ($meta['owner'] ?? '') !== $username || ($meta['category'] ?? '') !== 'image') return false;
            $meta['public_avatar'] = true;
            $meta['avatar_for'] = $username;
            return write_json_atomic($path, $meta, true);
        });
    }

    public static function removeProfileAvatar(string $mediaId, string $username): void
    {
        with_file_lock('media_' . $mediaId, function () use ($mediaId, $username) {
            $path = self::getMediaMetaPath($mediaId);
            $meta = read_json_safe($path, null);
            if (!is_array($meta) || ($meta['avatar_for'] ?? '') !== $username) return;
            $meta['public_avatar'] = false;
            unset($meta['avatar_for']);
            if (empty($meta['references'])) self::removePhysicalMedia($meta);
            else write_json_atomic($path, $meta, true);
        });
    }

    /** Delete orphan uploads belonging to this account; keep shared copies. */
    public static function purgeUnusedOwnedMedia(string $username): int
    {
        $uname = normalize_username($username);
        $removed = 0;
        foreach (glob(self::REGISTRY_DIR . '/*/*.json') ?: [] as $path) {
            $meta = read_json_safe($path, null);
            if (!is_array($meta) || ($meta['owner'] ?? '') !== $uname ||
                !empty($meta['references']) || !empty($meta['public_avatar'])) continue;
            if (self::deleteMediaById((string)($meta['id'] ?? ''), $uname)) $removed++;
        }
        return $removed;
    }

    /** Recycle a deleted account ID safely: keep group-linked uploads for
     * existing viewers, but never transfer ownership/quota to a new account.
     */
    public static function retireDeletedOwner(string $username): int
    {
        $uname = normalize_username($username);
        $retired = 0;
        foreach (glob(self::REGISTRY_DIR . '/*/*.json') ?: [] as $path) {
            $peek = read_json_safe($path, null);
            if (!is_array($peek) || ($peek['owner'] ?? '') !== $uname) continue;
            $mediaId = (string)($peek['id'] ?? '');
            if (self::getMediaMeta($mediaId) === null) throw new RuntimeException('فرادادهٔ رسانهٔ حساب حذف‌شده مخدوش است.');
            with_file_lock('media_' . $mediaId, function () use ($path, $uname, &$retired): void {
                $meta = read_json_safe($path, null);
                if (!is_array($meta) || ($meta['owner'] ?? '') !== $uname) return;
                if (empty($meta['references'])) {
                    self::removePhysicalMedia($meta);
                } else {
                    $meta['owner'] = '__deleted__';
                    $meta['public_avatar'] = false;
                    unset($meta['avatar_for']);
                    if (!write_json_atomic($path, $meta, true)) throw new RuntimeException('انتقال مالکیت رسانهٔ حساب حذف‌شده ناموفق بود.');
                }
                $retired++;
            });
        }
        // Surviving group media belongs to no new account; old quota must
        // not make a recycled ID start out already full.
        @unlink(DRIVE_DIR . '/' . $uname . '_usage.json');
        return $retired;
    }

    /** Remove the deleted account's personal directory after refs were cleared. */
    public static function removeDeletedAccountDrive(string $username): void
    {
        $uname = normalize_username($username);
        $dir = DRIVE_DIR . '/' . $uname;
        if (!is_dir($dir)) return;
        foreach (glob($dir . '/drive_part_*.json') ?: [] as $file) {
            if (!@unlink($file)) throw new RuntimeException('پاکسازی فضای شخصی کاربر حذف‌شده کامل نشد.');
        }
        $index = $dir . '/drive_index.json';
        if (is_file($index) && !@unlink($index)) throw new RuntimeException('پاکسازی شاخص فضای شخصی ناموفق بود.');
        if (!@rmdir($dir) && is_dir($dir)) throw new RuntimeException('پوشهٔ فضای شخصی کاربر حذف‌شده خالی نیست.');
    }

    /**
     * A logged-in user can stream a file ONLY if they uploaded it, have
     * access to a linked message, own a Drive copy, or can see its avatar.
     */
    public static function canUserViewMedia(array $viewer, string $mediaId): bool
    {
        $meta = self::getMediaMeta($mediaId);
        if ($meta === null) return false;
        $username = $viewer['username'];
        $refs = is_array($meta['references'] ?? null) ? $meta['references'] : [];
        // An upload not yet attached to a message remains accessible to its
        // uploader. Once attached, ownership alone MUST NOT bypass a later
        // per-user delete/clear, even if the peer keeps their own copy.
        if (($meta['owner'] ?? '') === $username && !$refs && empty($meta['public_avatar'])) return true;

        if (!empty($meta['public_avatar'])) {
            $avatarFor = (string)($meta['avatar_for'] ?? '');
            if ($avatarFor === $username || ($viewer['role'] ?? '') === 'admin' ||
                in_array($avatarFor, Auth::getUserContacts($username), true)) return true;
            $dmId = JsonShardStore::makeDirectChatId($username, $avatarFor);
            $idx = JsonShardStore::getChatIndex($dmId, false);
            $view = DialogView::state($username, $dmId);
            if ((int)($idx['last_message']['seq'] ?? 0) > (int)($view['cleared_seq'] ?? 0) &&
                (empty($view['hidden']) || (int)($idx['next_seq'] ?? 1) - 1 > (int)($view['hidden_until_seq'] ?? 0))) return true;
        }

        foreach ($refs as $refKey => $r) {
            if (!is_array($r)) continue;
            if (in_array(($r['type'] ?? ''), ['drive', 'sticker'], true) && ($r['username'] ?? '') === $username) return true;
            if (($r['type'] ?? '') !== 'chat') continue;
            $cid = (string)($r['chat_id'] ?? '');
            if (!JsonShardStore::canUserAccessChat($username, $cid, false)) continue;
            $pos = strrpos((string)$refKey, ':');
            $seq = $pos === false ? 0 : (int)substr((string)$refKey, $pos + 1);
            $msg = $seq > 0 ? JsonShardStore::getMessageBySeq($cid, $seq, $username) : null;
            if ($msg !== null && ($msg['media']['id'] ?? '') === $mediaId) return true;
        }
        return false;
    }

    public static function getUserUsedBytes(string $username): int
    {
        $uname = normalize_username($username);
        $usageFile = DRIVE_DIR . '/' . $uname . '_usage.json';
        $data = read_json_safe($usageFile, null);
        if (is_array($data) && isset($data['used_bytes'])) {
            return max(0, (int)$data['used_bytes']);
        }
        return 0;
    }

    public static function adjustUserUsedBytes(string $username, int $deltaBytes): int
    {
        $uname = normalize_username($username);
        return with_file_lock('usage_' . $uname, function () use ($uname, $deltaBytes) {
            $usageFile = DRIVE_DIR . '/' . $uname . '_usage.json';
            $data = read_json_safe($usageFile, ['used_bytes' => 0]);
            $next = max(0, ((int)($data['used_bytes'] ?? 0)) + $deltaBytes);
            $data['used_bytes'] = $next;
            $data['updated_at'] = time();
            if (!write_json_atomic($usageFile, $data, false)) throw new RuntimeException('بروزرسانی سهمیهٔ ذخیره‌سازی ناموفق بود.');
            return $next;
        });
    }

    /**
     * Charge an upload only when it still fits the limit, under a single
     * per-user flock (protects quota from simultaneous browser sessions).
     */
    private static function reserveUserQuota(string $username, int $bytes, int $quotaBytes): bool
    {
        $uname = normalize_username($username);
        return with_file_lock('usage_' . $uname, function () use ($uname, $bytes, $quotaBytes): bool {
            $path = DRIVE_DIR . '/' . $uname . '_usage.json';
            $data = read_json_safe($path, ['used_bytes' => 0]);
            $used = max(0, (int)($data['used_bytes'] ?? 0));
            // The agreed cut-off is 80% *before starting another upload*.
            // The upload that first crosses 80% completes; subsequent uploads
            // stop until the owner removes media. Text remains unaffected.
            if ($used >= (int)ceil($quotaBytes * 0.8) || $used + $bytes > $quotaBytes) return false;
            return write_json_atomic($path, ['used_bytes' => $used + $bytes, 'updated_at' => time()], false);
        });
    }

    public static function detectCategory(string $mime, string $ext, bool $isVoice = false, bool $forceAsFile = false): string
    {
        if ($forceAsFile) return 'file';
        $mime = strtolower(trim(explode(';', $mime)[0]));
        // Do NOT trust a filename extension or client MIME for inline rendering:
        // a disguised HTML/SVG file must always be a downloadable attachment.
        if (in_array($mime, ['image/jpeg', 'image/png', 'image/gif', 'image/webp', 'image/bmp'], true)) return 'image';
        if ($isVoice && in_array($mime, ['audio/webm', 'audio/ogg', 'audio/mp4', 'audio/mpeg', 'audio/aac'], true)) return 'voice';
        if (in_array($mime, ['video/mp4', 'video/webm', 'video/quicktime', 'video/x-matroska'], true)) return 'video';
        if (in_array($mime, ['audio/mpeg', 'audio/mp3', 'audio/ogg', 'audio/x-wav', 'audio/wav', 'audio/mp4', 'audio/aac', 'audio/webm', 'audio/x-m4a'], true)) return 'audio';
        return 'file';
    }

    private static function cleanupStaleChunks(): void
    {
        if (mt_rand(1, 20) !== 1) {
            return;
        }
        $dirs = glob(CHUNKS_DIR . '/*', GLOB_ONLYDIR) ?: [];
        $cutoff = time() - 21600;
        foreach ($dirs as $d) {
            if (@filemtime($d) < $cutoff) {
                foreach (glob($d . '/*') ?: [] as $f) @unlink($f);
                @rmdir($d);
            }
        }
        foreach (glob(DATA_DIR . '/upload_receipts/*.json') ?: [] as $receipt) {
            if (@filemtime($receipt) < time() - 86400) @unlink($receipt);
        }
    }

    public static function handleChunkUpload(array $user, array $post, array $files): array
    {
        self::cleanupStaleChunks();

        if (!isset($files['chunk']) || ($files['chunk']['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK) {
            $errCode = $files['chunk']['error'] ?? -1;
            return ['ok' => false, 'error' => 'خطا در دریافت قطعه فایل (کد: ' . $errCode . ').'];
        }

        $cfg = get_app_config();
        $maxUploadBytes = ((int)($cfg['max_upload_mb'] ?? 150)) * 1048576;

        $uploadId = preg_replace('/[^a-zA-Z0-9_\-]/', '', (string)($post['upload_id'] ?? '')) ?? '';
        if ($uploadId === '') {
            $uploadId = 'up_' . bin2hex(random_bytes(8));
        }

        $chunkIndex  = max(0, (int)($post['chunk_index'] ?? 0));
        $totalChunks = max(1, (int)($post['total_chunks'] ?? 1));
        $totalSize   = max(0, (int)($post['total_size'] ?? 0));
        if ($totalChunks > 4096 || $chunkIndex >= $totalChunks ||
            $totalSize < 1 || $totalSize > $maxUploadBytes ||
            (int)($files['chunk']['size'] ?? 0) > 2097152 ||
            (int)($files['chunk']['size'] ?? 0) < 1) {
            return ['ok' => false, 'error' => 'اطلاعات قطعه فایل یا حجم آن معتبر نیست.'];
        }
        $origName    = mb_substr(trim((string)($post['original_name'] ?? 'file.bin')), 0, 200);
        $clientMime  = substr(trim((string)($post['mime_type'] ?? 'application/octet-stream')), 0, 100);
        $isVoice     = !empty($post['is_voice']) && $post['is_voice'] !== '0' && $post['is_voice'] !== 'false';
        $forceFile   = !empty($post['force_as_file']) && $post['force_as_file'] !== '0' && $post['force_as_file'] !== 'false';
        $isSticker   = !empty($post['is_sticker']) && $post['is_sticker'] !== '0' && $post['is_sticker'] !== 'false';
        if ($isSticker && $totalSize > 524288) return ['ok' => false, 'error' => 'استیکر باید PNG/WebP و حداکثر ۵۱۲ کیلوبایت باشد.'];
        $duration    = round((float)($post['duration'] ?? 0), 1);
        $width       = max(0, (int)($post['width'] ?? 0));
        $height      = max(0, (int)($post['height'] ?? 0));

        if ($totalSize > $maxUploadBytes) {
            return ['ok' => false, 'error' => 'حجم فایل بیشتر از سقف مجاز (' . ($cfg['max_upload_mb'] ?? 150) . ' مگابایت) است.'];
        }

        $receiptDir = DATA_DIR . '/upload_receipts';
        if (!is_dir($receiptDir)) @mkdir($receiptDir, 0755, true);
        $receiptPath = $receiptDir . '/' . $user['username'] . '_' . $uploadId . '.json';
        $receipt = read_json_safe($receiptPath, null);
        if (is_array($receipt) && (int)($receipt['total_size'] ?? -1) === $totalSize) {
            $previous = self::getMediaMeta((string)($receipt['media_id'] ?? ''));
            if ($previous !== null && ($previous['owner'] ?? '') === $user['username']) {
                return ['ok' => true, 'complete' => true, 'media' => self::publicMediaMeta($previous)];
            }
        }
        $usedBytes = self::getUserUsedBytes($user['username']);
        $quotaBytes = ((int)($user['quota_mb'] ?? 1024)) * 1048576;
        if ($usedBytes >= (int)ceil($quotaBytes * 0.8)) {
            return ['ok' => false, 'code' => 'quota_media_blocked', 'error' => 'مصرف رسانهٔ شما به ۸۰٪ سهمیه رسیده؛ فعلاً فقط پیام متنی مجاز است. از «مدیریت همهٔ رسانه‌ها» برای آزادسازی فضا استفاده کنید.'];
        }
        if (($usedBytes + $totalSize) > $quotaBytes) {
            return ['ok' => false, 'code' => 'quota_media_blocked', 'error' => 'فضای ذخیره‌سازی شخصی شما پر شده است. رسانه‌های قدیمی را پاک کنید؛ ارسال متن آزاد است.'];
        }

        $chunkDir = CHUNKS_DIR . '/' . $user['username'] . '_' . $uploadId;

        return with_file_lock('upload_' . $user['username'] . '_' . $uploadId, function () use (
            $chunkDir, $chunkIndex, $totalChunks, $totalSize, $origName, $clientMime,
            $isVoice, $forceFile, $isSticker, $duration, $width, $height, $user, $maxUploadBytes, $quotaBytes, $files, $receiptPath
        ) {
            $receipt = read_json_safe($receiptPath, null);
            if (is_array($receipt) && (int)($receipt['total_size'] ?? -1) === $totalSize) {
                $previous = self::getMediaMeta((string)($receipt['media_id'] ?? ''));
                if ($previous !== null && ($previous['owner'] ?? '') === $user['username']) {
                    return ['ok' => true, 'complete' => true, 'media' => self::publicMediaMeta($previous)];
                }
            }
            if (!is_dir($chunkDir)) @mkdir($chunkDir, 0755, true);
            $manifestPath = $chunkDir . '/manifest.json';
            $manifest = read_json_safe($manifestPath, null);
            if ($manifest === null) {
                write_json_atomic($manifestPath, [
                    'total_chunks' => $totalChunks, 'total_size' => $totalSize,
                    'name' => $origName, 'mime' => $clientMime,
                ], false);
            } elseif (($manifest['total_chunks'] ?? -1) !== $totalChunks ||
                ($manifest['total_size'] ?? -1) !== $totalSize ||
                ($manifest['name'] ?? '') !== $origName || ($manifest['mime'] ?? '') !== $clientMime) {
                return ['ok' => false, 'error' => 'اطلاعات قطعات یک فایل با هم یکسان نیست. بارگذاری را دوباره آغاز کنید.'];
            }

            $partFile = $chunkDir . '/' . sprintf('part_%05d', $chunkIndex);
            if (!@move_uploaded_file($files['chunk']['tmp_name'], $partFile)) {
                // Do not copy arbitrary local paths: PHP must have verified HTTP upload.
                return ['ok' => false, 'error' => 'امکان ذخیره این قطعه روی هاست وجود ندارد.'];
            }
            @touch($chunkDir);

            // Refuse to assemble unless EVERY numbered part is present.
            for ($i = 0; $i < $totalChunks; $i++) {
                if (!is_file($chunkDir . '/' . sprintf('part_%05d', $i))) {
                    return ['ok' => true, 'complete' => false, 'received_chunks' => count(glob($chunkDir . '/part_*') ?: []), 'total_chunks' => $totalChunks];
                }
            }

            $subFolder = date('Y-m');
            $destDir = UPLOADS_DIR . '/' . $subFolder;
            if (!is_dir($destDir)) @mkdir($destDir, 0755, true);

            $origBase = basename(str_replace('\\', '/', $origName));
            $origBase = preg_replace('/[\x00-\x1f\x7f]/u', '', $origBase) ?: 'document';
            $ext = strtolower((string)pathinfo($origBase, PATHINFO_EXTENSION));
            $diskExt = in_array($ext, self::$safeExtensions, true) ? $ext : 'bin';

            $mediaId = 'med_' . date('YmdHis') . '_' . bin2hex(random_bytes(6));
            // Keep the transient plaintext only in the protected upload scratch
            // directory. No unencrypted final media file is ever published.
            $assemblyPath = $chunkDir . '/assembled.tmp';
            $finalPath = $destDir . '/' . $mediaId . '.enc';
            $outFp = @fopen($assemblyPath, 'wb');
            if ($outFp === false) {
                return ['ok' => false, 'error' => 'خطا در ایجاد فایل موقت بارگذاری.'];
            }
            $assembledBytes = 0;
            for ($i = 0; $i < $totalChunks; $i++) {
                $p = $chunkDir . '/' . sprintf('part_%05d', $i);
                $inFp = @fopen($p, 'rb');
                if ($inFp === false) {
                    fclose($outFp);
                    @unlink($assemblyPath);
                    return ['ok' => false, 'error' => 'قطعه‌ای از فایل روی هاست پیدا نشد.'];
                }
                $copied = stream_copy_to_stream($inFp, $outFp);
                fclose($inFp);
                if ($copied === false) {
                    fclose($outFp);
                    @unlink($assemblyPath);
                    return ['ok' => false, 'error' => 'خطا هنگام ادغام قطعات فایل.'];
                }
                $assembledBytes += $copied;
            }
            fclose($outFp);

            if ($assembledBytes !== $totalSize || $assembledBytes > $maxUploadBytes ||
                (self::getUserUsedBytes($user['username']) + $assembledBytes) > $quotaBytes) {
                @unlink($assemblyPath);
                return ['ok' => false, 'error' => 'حجم نهایی فایل با حجم اعلام‌شده متفاوت است یا سهمیه فضای شما کافی نیست.'];
            }

            $detectedMime = 'application/octet-stream';
            if (function_exists('finfo_open')) {
                $finfo = @finfo_open(FILEINFO_MIME_TYPE);
                if ($finfo) {
                    $realMime = @finfo_file($finfo, $assemblyPath);
                    if (is_string($realMime) && $realMime !== '') $detectedMime = $realMime;
                    @finfo_close($finfo);
                }
            }
            // finfo detects some Opus voice recordings as 'video/webm'.
            if ($isVoice && $detectedMime === 'video/webm' && strpos($clientMime, 'audio/') === 0) {
                $detectedMime = 'audio/webm';
            }
            if ($detectedMime === 'application/octet-stream' &&
                in_array($clientMime, ['video/mp4', 'video/webm', 'audio/webm', 'audio/mp4', 'audio/ogg'], true) &&
                in_array($ext, ['mp4', 'webm', 'm4a', 'ogg'], true)) {
                $detectedMime = $clientMime;
            }
            $category = self::detectCategory($detectedMime, $ext, $isVoice, $forceFile);
            if ($isSticker) {
                $img = in_array($detectedMime, ['image/png', 'image/webp'], true) ? @getimagesize($assemblyPath) : false;
                if (!is_array($img) || $img[0] < 1 || $img[1] < 1 || $img[0] > 512 || $img[1] > 512) {
                    @unlink($assemblyPath);
                    return ['ok' => false, 'error' => 'استیکر باید تصویر واقعی PNG/WebP با ابعاد حداکثر ۵۱۲×۵۱۲ باشد.'];
                }
                $category = 'sticker';
                $width = (int)$img[0]; $height = (int)$img[1];
            }

            $w = $width;
            $h = $height;
            if ($category === 'image' && ($w === 0 || $h === 0) && function_exists('getimagesize')) {
                $imgInfo = @getimagesize($assemblyPath);
                if (is_array($imgInfo)) {
                    $w = (int)($imgInfo[0] ?? 0);
                    $h = (int)($imgInfo[1] ?? 0);
                }
            }

            // Encrypt authenticated independent blocks for low-memory HTTP 206
            // streaming. Any failure leaves the upload unregistered (fail closed).
            try {
                CryptoEngine::encryptMediaFile($assemblyPath, $finalPath, $mediaId);
            } finally {
                @unlink($assemblyPath);
            }

            $meta = [
                'id' => $mediaId,
                'owner' => $user['username'],
                'original_name' => mb_substr($origBase, 0, 180),
                'extension' => $ext,
                'mime_type' => $detectedMime,
                'category' => $category,
                'size_bytes' => $assembledBytes,
                'size_human' => format_bytes_fa($assembledBytes),
                'duration' => $duration,
                'width' => $w,
                'height' => $h,
                'rel_path' => $subFolder . '/' . $mediaId . '.enc',
                'encrypted' => CryptoEngine::MEDIA_FORMAT,
                'references' => [],
                'created_at' => time(),
            ];

            if (!self::reserveUserQuota($user['username'], $assembledBytes, $quotaBytes)) {
                @unlink($finalPath);
                return ['ok' => false, 'code' => 'quota_media_blocked', 'error' => 'سهمیهٔ شما هنگام آپلود به ۸۰٪ رسیده یا جا کافی نیست؛ فعلاً فقط متن بفرستید و رسانه‌های قدیمی را پاک کنید.'];
            }
            if (!write_json_atomic(self::getMediaMetaPath($mediaId), $meta, true)) {
                @unlink($finalPath);
                self::adjustUserUsedBytes($user['username'], -$assembledBytes);
                return ['ok' => false, 'error' => 'خطا در ثبت مشخصات فایل.'];
            }
            write_json_atomic($receiptPath, ['media_id' => $mediaId, 'total_size' => $totalSize, 'created_at' => time()], false);

            for ($i = 0; $i < $totalChunks; $i++) @unlink($chunkDir . '/' . sprintf('part_%05d', $i));
            @unlink($manifestPath);
            @rmdir($chunkDir);

            return ['ok' => true, 'complete' => true, 'media' => self::publicMediaMeta($meta)];
        });
    }

    /** Small, account-free public-preview file from Telegram CDN. The bytes
     * have already passed an allowlisted, size-bounded HTTP fetch. MIME is
     * verified locally, then encrypted-at-rest before any registry entry.
     */
    public static function importTelegramPreview(array $user, string $bytes, string $kind, string $channel, int $postNo): array
    {
        $size = strlen($bytes);
        if ($size < 1 || $size > 6291456 || $size > (int)(get_app_config()['max_upload_mb'] ?? 150) * 1048576) {
            return ['ok' => false, 'error' => 'حجم رسانهٔ پیش‌نمایش از سقف مجاز هاست بیشتر است.'];
        }
        if (!in_array($kind, ['photo', 'video', 'audio', 'voice', 'file'], true) ||
            !preg_match('/^[a-z][a-z0-9_]{4,31}$/D', $channel) || $postNo < 1) {
            return ['ok' => false, 'error' => 'نوع رسانه یا شناسهٔ پست نامعتبر است.'];
        }
        $mime = 'application/octet-stream';
        if (function_exists('finfo_open')) {
            $finfo = @finfo_open(FILEINFO_MIME_TYPE);
            if ($finfo) {
                $detected = @finfo_buffer($finfo, $bytes);
                if (is_string($detected) && $detected !== '') $mime = $detected;
                @finfo_close($finfo);
            }
        }
        $mime = strtolower($mime);
        $extensions = [
            'image/jpeg' => 'jpg', 'image/png' => 'png', 'image/webp' => 'webp',
            'video/mp4' => 'mp4', 'video/webm' => 'webm',
            'audio/mpeg' => 'mp3', 'audio/ogg' => 'ogg', 'audio/webm' => 'webm',
            'application/pdf' => 'pdf', 'application/zip' => 'zip', 'text/plain' => 'txt',
        ];
        if ($kind === 'photo' && !in_array($mime, ['image/jpeg', 'image/png', 'image/webp'], true)) {
            return ['ok' => false, 'error' => 'CDN فایل قابل‌تأیید به‌عنوان عکس برنگرداند.'];
        }
        if ($kind === 'photo' && !@getimagesizefromstring($bytes)) return ['ok' => false, 'error' => 'تصویر دریافتی معتبر نیست.'];
        if ($kind === 'video' && !in_array($mime, ['video/mp4', 'video/webm'], true)) {
            return ['ok' => false, 'error' => 'نسخهٔ ویدیویی قابل‌پخش در پیش‌نمایش موجود نبود.'];
        }
        if (in_array($kind, ['audio', 'voice'], true) && !in_array($mime, ['audio/mpeg', 'audio/ogg', 'audio/webm'], true)) {
            return ['ok' => false, 'error' => 'فایل صوتی قابل‌تأیید از پیش‌نمایش دریافت نشد.'];
        }
        if ($kind === 'file' && ($mime === 'text/html' || $mime === 'image/svg+xml' || strpos($mime, 'application/x-php') === 0)) {
            return ['ok' => false, 'error' => 'پاسخ پیش‌نمایش به‌جای فایل، صفحهٔ وب/کد برگرداند.'];
        }
        $ext = $extensions[$mime] ?? 'bin';
        $categoryMap = ['photo' => 'image', 'video' => 'video', 'audio' => 'audio', 'voice' => 'voice'];
        $category = $categoryMap[$kind] ?? 'file';
        $owner = $user['username'];
        $quotaBytes = max(1, (int)($user['quota_mb'] ?? 1024)) * 1048576;
        if (self::getUserUsedBytes($owner) >= (int)ceil($quotaBytes * 0.8)) {
            return ['ok' => false, 'code' => 'quota_media_blocked', 'error' => 'سهمیهٔ رسانه به ۸۰٪ رسیده و ورود فایل تازه متوقف است؛ متن پست‌ها همچنان نمایش داده می‌شود.'];
        }
        $subFolder = date('Y-m');
        $destDir = UPLOADS_DIR . '/' . $subFolder;
        if (!is_dir($destDir) && !@mkdir($destDir, 0755, true) && !is_dir($destDir)) {
            return ['ok' => false, 'error' => 'پوشهٔ رمزنگاری رسانه روی هاست قابل ساخت نیست.'];
        }
        if (!is_dir(CHUNKS_DIR) && !@mkdir(CHUNKS_DIR, 0755, true) && !is_dir(CHUNKS_DIR)) {
            return ['ok' => false, 'error' => 'فضای موقت آپلود روی هاست قابل ساخت نیست.'];
        }
        $id = 'med_' . date('YmdHis') . '_' . bin2hex(random_bytes(6));
        $temp = CHUNKS_DIR . '/tg_' . bin2hex(random_bytes(12)) . '.tmp';
        $cipher = $destDir . '/' . $id . '.enc';
        $reserved = false;
        try {
            if (@file_put_contents($temp, $bytes, LOCK_EX) !== $size) throw new RuntimeException('فایل موقت رسانهٔ تلگرام قابل نوشتن نیست.');
            @chmod($temp, 0600);
            CryptoEngine::encryptMediaFile($temp, $cipher, $id);
            if (!self::reserveUserQuota($owner, $size, $quotaBytes)) {
                return ['ok' => false, 'code' => 'quota_media_blocked', 'error' => 'فضای رسانه در زمان دریافت فایل به ۸۰٪ رسید یا سهمیه کافی نیست.'];
            }
            $reserved = true;
            $name = 'telegram_' . $channel . '_' . $postNo . '.' . $ext;
            $meta = [
                'id' => $id, 'owner' => $owner, 'original_name' => $name,
                'extension' => $ext, 'mime_type' => $mime, 'category' => $category,
                'size_bytes' => $size, 'size_human' => format_bytes_fa($size),
                'duration' => 0, 'width' => 0, 'height' => 0,
                'rel_path' => $subFolder . '/' . $id . '.enc', 'encrypted' => CryptoEngine::MEDIA_FORMAT,
                'references' => [], 'created_at' => time(),
            ];
            if (!write_json_atomic(self::getMediaMetaPath($id), $meta, true)) throw new RuntimeException('ثبت فایل دریافتی ممکن نشد.');
            return ['ok' => true, 'media' => self::publicMediaMeta($meta)];
        } finally {
            @unlink($temp);
            // Once registered, references are attached by the API action;
            // otherwise roll back bytes, ciphertext and reserved quota.
            if (self::getMediaMeta($id) === null) {
                @unlink($cipher);
                if ($reserved) self::adjustUserUsedBytes($owner, -$size);
            }
        }
    }

    public static function streamMedia(string $mediaId, bool $forceDownload = false): void
    {
        $meta = self::getMediaMeta($mediaId);
        if ($meta === null || empty($meta['rel_path'])) {
            http_response_code(404);
            exit('Media not found');
        }
        $filePath = UPLOADS_DIR . '/' . $meta['rel_path'];
        if (!is_file($filePath) || !is_readable($filePath)) {
            http_response_code(404);
            exit('File missing on disk');
        }

        $encrypted = ($meta['encrypted'] ?? '') === CryptoEngine::MEDIA_FORMAT;
        if (!empty($meta['encrypted']) && !$encrypted) {
            throw new RuntimeException('قالب رمزنگاری این فایل پشتیبانی نمی‌شود.');
        }
        // Old installs may still have unencrypted media. They stay readable for
        // compatibility but must be explicitly migrated; never silently claim
        // their protection. Fresh uploads always use the authenticated format.
        $size = $encrypted ? (int)($meta['size_bytes'] ?? 0) : (int)filesize($filePath);
        if ($size < 1 || ($encrypted && filesize($filePath) !== CryptoEngine::mediaCipherSize($size))) {
            throw new RuntimeException('فایل روی دیسک ناقص یا مخدوش است؛ نسخه پشتیبان را بررسی کنید.');
        }
        $mime = (string)($meta['mime_type'] ?? 'application/octet-stream');
        $cat  = (string)($meta['category'] ?? 'file');
        $origName = str_replace(['"', "\r", "\n"], '', (string)($meta['original_name'] ?? 'file'));
        $safeInline = in_array($cat, ['image', 'video', 'voice', 'audio'], true) ||
            ($cat === 'sticker' && in_array($mime, ['image/png', 'image/webp'], true)) || $mime === 'application/pdf';
        if (!$safeInline) $mime = 'application/octet-stream';
        $disposition = ($forceDownload || !$safeInline) ? 'attachment' : 'inline';

        $start = 0;
        $end = $size - 1;
        if (isset($_SERVER['HTTP_RANGE'])) {
            $rawRange = (string)$_SERVER['HTTP_RANGE'];
            if (!preg_match('/^bytes=(\d*)-(\d*)$/i', $rawRange, $matches) ||
                ($matches[1] === '' && $matches[2] === '')) {
                http_response_code(416);
                header("Content-Range: bytes */{$size}");
                exit;
            }
            if ($matches[1] === '') {
                $suffix = (int)$matches[2];
                if ($suffix < 1) {
                    http_response_code(416);
                    header("Content-Range: bytes */{$size}");
                    exit;
                }
                $start = max(0, $size - $suffix);
            } else {
                $start = (int)$matches[1];
                if ($matches[2] !== '') $end = min($end, (int)$matches[2]);
            }
            if ($start > $end || $start >= $size) {
                http_response_code(416);
                header("Content-Range: bytes */{$size}");
                exit;
            }
        }
        $length = $end - $start + 1;
        $fp = @fopen($filePath, 'rb');
        if (!$fp) throw new RuntimeException('باز کردن فایل ممکن نیست.');
        $firstIndex = (int)floor($start / CryptoEngine::MEDIA_BLOCK_BYTES);
        try {
            // Authenticate the first requested block before sending any 200/206
            // or plaintext; tampering/wrong key must fail closed with HTTP 500.
            $firstBlock = $encrypted ? CryptoEngine::decryptMediaBlock($fp, $mediaId, $firstIndex, $size) : '';
        } catch (Throwable $e) {
            fclose($fp);
            throw $e;
        }

        header('X-Content-Type-Options: nosniff');
        header("Content-Security-Policy: default-src 'none'; sandbox");
        header('Content-Type: ' . $mime);
        header('Accept-Ranges: bytes');
        header('Cache-Control: private, no-store');
        header("Content-Disposition: {$disposition}; filename*=UTF-8''" . rawurlencode($origName));
        if (isset($_SERVER['HTTP_RANGE'])) {
            http_response_code(206);
            header("Content-Range: bytes {$start}-{$end}/{$size}");
        } else {
            http_response_code(200);
        }
        header('Content-Length: ' . $length);
        if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'HEAD') {
            fclose($fp);
            exit;
        }
        while (ob_get_level() > 0) @ob_end_clean();

        if ($encrypted) {
            $lastIndex = (int)floor($end / CryptoEngine::MEDIA_BLOCK_BYTES);
            for ($i = $firstIndex; $i <= $lastIndex && !connection_aborted(); $i++) {
                // At most 1 MiB plaintext is resident in memory at any time.
                $block = $i === $firstIndex ? $firstBlock : CryptoEngine::decryptMediaBlock($fp, $mediaId, $i, $size);
                $offset = $i === $firstIndex ? $start % CryptoEngine::MEDIA_BLOCK_BYTES : 0;
                $want = min(strlen($block) - $offset, $end - $i * CryptoEngine::MEDIA_BLOCK_BYTES - $offset + 1);
                echo substr($block, $offset, $want);
                flush();
            }
        } else {
            if ($start > 0) fseek($fp, $start);
            $remaining = $length;
            while ($remaining > 0 && !feof($fp) && !connection_aborted()) {
                $buf = fread($fp, min(65536, $remaining));
                if ($buf === false || $buf === '') break;
                echo $buf;
                flush();
                $remaining -= strlen($buf);
            }
        }
        fclose($fp);
        exit;
    }

    // ========================================================================
    // Personal Cloud Storage Drive (با رمزنگاری AES-256-GCM برای یادداشت‌ها)
    // ========================================================================

    private static function getUserDriveDir(string $username): string
    {
        $dir = DRIVE_DIR . '/' . normalize_username($username);
        if (!is_dir($dir)) {
            @mkdir($dir, 0755, true);
        }
        return $dir;
    }

    /**
     * O(1) quota lookup: realtime sync and every 1 MB upload chunk MUST NOT
     * scan/decrypt the owner's entire personal cloud history.
     */
    /** Inventory of *owned uploads*, not the complete cross-user chat history.
     * Newest-first cursor pages, with bounded JSON returned to the browser.
     */
    public static function listOwnedMedia(string $username, string $cursor = '', int $limit = 30): array
    {
        $uname = normalize_username($username);
        $limit = max(1, min(50, $limit));
        if ($cursor !== '' && !preg_match('/^med_[0-9]{14}_[0-9a-f]{12}$/D', $cursor)) {
            return ['ok' => false, 'error' => 'نشانگر ادامهٔ فهرست معتبر نیست.'];
        }
        $items = [];
        foreach (glob(self::REGISTRY_DIR . '/*/*.json') ?: [] as $path) {
            $meta = read_json_safe($path, null);
            if (!is_array($meta) || ($meta['owner'] ?? '') !== $uname) continue;
            $id = (string)($meta['id'] ?? '');
            if ($cursor !== '' && strcmp($id, $cursor) >= 0) continue;
            $refs = is_array($meta['references'] ?? null) ? $meta['references'] : [];
            $types = ['chat' => 0, 'drive' => 0, 'sticker' => 0];
            foreach ($refs as $ref) {
                if (is_array($ref) && isset($types[$ref['type'] ?? ''])) $types[$ref['type']]++;
            }
            $items[] = ['media' => self::publicMediaMeta($meta), 'refs' => $types,
                'avatar' => !empty($meta['public_avatar'])];
        }
        usort($items, fn($a, $b) => strcmp($b['media']['id'], $a['media']['id']));
        $page = array_slice($items, 0, $limit);
        return ['ok' => true, 'items' => $page, 'has_more' => count($items) > $limit,
            'next_cursor' => count($items) > $limit ? $page[count($page) - 1]['media']['id'] : null,
            'quota' => self::getUserQuotaSummary($uname)];
    }

    /** Delete owned media references in authorized conversations. A peer's
     * forwarded message is never destroyed without that peer's permission.
     * "Only me" hides my view; bytes may remain charged for shared copies.
     */
    public static function removeOwnedMediaEverywhere(string $username, string $mediaId, string $scope): array
    {
        $uname = normalize_username($username);
        $meta = self::getMediaMeta($mediaId);
        if (!is_array($meta) || ($meta['owner'] ?? '') !== $uname) {
            return ['ok' => false, 'error' => 'رسانه متعلق به این حساب نیست یا قبلاً پاک شده است.'];
        }
        $before = self::getUserUsedBytes($uname);
        $refs = is_array($meta['references'] ?? null) ? $meta['references'] : [];
        $hidden = 0; $removed = 0; $skipped = 0;
        foreach ($refs as $refKey => $ref) {
            if (!is_array($ref) || ($ref['type'] ?? '') !== 'chat') continue;
            $chatId = (string)($ref['chat_id'] ?? '');
            $pos = strrpos((string)$refKey, ':');
            $seq = $pos === false ? 0 : (int)substr((string)$refKey, $pos + 1);
            if ($seq < 1 || !JsonShardStore::canUserAccessChat($uname, $chatId, false)) { $skipped++; continue; }
            $msg = JsonShardStore::getMessageBySeq($chatId, $seq, $scope === 'both' ? null : $uname);
            if (!is_array($msg) || ($msg['media']['id'] ?? '') !== $mediaId) { $skipped++; continue; }
            if ($scope === 'both' && ($msg['sender'] ?? '') !== $uname) { $skipped++; continue; }
            $r = JsonShardStore::deleteMessage($chatId, $seq, $uname, false, $scope);
            if (!empty($r['ok'])) { if ($scope === 'me' && !in_array($chatId, ['saved_' . $uname, 'ai_' . $uname], true)) $hidden++; else $removed++; }
            else $skipped++;
        }
        foreach ($refs as $refKey => $ref) {
            if (!is_array($ref) || ($ref['type'] ?? '') !== 'drive' || ($ref['username'] ?? '') !== $uname) continue;
            $pos = strrpos((string)$refKey, ':');
            $itemId = $pos === false ? '' : substr((string)$refKey, $pos + 1);
            if ($itemId !== '' && !empty(self::updateDriveItem($uname, $itemId, 'delete')['ok'])) $removed++;
            else $skipped++;
        }
        foreach ($refs as $ref) {
            if (!is_array($ref) || ($ref['type'] ?? '') !== 'sticker' || ($ref['username'] ?? '') !== $uname) continue;
            $result = StickerStore::remove($uname, $mediaId);
            if (!empty($result['ok'])) $removed++; else $skipped++;
        }
        if (!empty($meta['public_avatar']) && ($meta['avatar_for'] ?? '') === $uname) {
            $profile = Auth::saveUserRecord(['username' => $uname, 'avatar_url' => ''], false);
            if (empty($profile['ok'])) throw new RuntimeException('حذف عکس نمایه ذخیره نشد.');
            self::removeProfileAvatar($mediaId, $uname);
            $removed++;
        }
        self::deleteMediaById($mediaId, $uname);
        $afterMeta = self::getMediaMeta($mediaId);
        return ['ok' => true, 'media_id' => $mediaId, 'hidden' => $hidden, 'removed' => $removed,
            'skipped' => $skipped, 'still_stored' => $afterMeta !== null,
            'freed_bytes' => max(0, $before - self::getUserUsedBytes($uname))];
    }

    public static function getUserQuotaSummary(string $username): array
    {
        $uname = normalize_username($username);
        $userObj = Auth::getUser($uname);
        $quotaMb = (int)($userObj['quota_mb'] ?? 1024);
        $usedBytes = self::getUserUsedBytes($uname);
        $quotaBytes = max(1, $quotaMb * 1048576);
        return [
            'used_bytes' => $usedBytes,
            'used_human' => format_bytes_fa($usedBytes),
            'quota_bytes' => $quotaBytes,
            'quota_human' => format_bytes_fa($quotaBytes),
            'percent' => min(100, round(($usedBytes / $quotaBytes) * 100, 1)),
            'media_blocked' => $usedBytes >= (int)ceil($quotaBytes * 0.8),
            'media_cutoff_percent' => 80,
        ];
    }

    private static function ensureDriveIndex(string $username): array
    {
        $dir = self::getUserDriveDir($username);
        $indexPath = $dir . '/drive_index.json';
        $existing = read_json_safe($indexPath, null);
        if (is_array($existing) && isset($existing['shards'])) return $existing;
        $initial = [
            'folders' => [
                ['id' => 'important', 'name' => 'مدارک و اطلاعات ضروری', 'icon' => 'shield', 'color' => '#EF4444', 'system' => true],
                ['id' => 'gallery',   'name' => 'آلبوم عکس و ویدیو شخصی', 'icon' => 'image',  'color' => '#2AABEE', 'system' => true],
                ['id' => 'notes',     'name' => 'یادداشت‌ها و رمزها',      'icon' => 'note',   'color' => '#F59E0B', 'system' => true],
                ['id' => 'files',     'name' => 'فایل‌ها و اسناد من',      'icon' => 'folder', 'color' => '#10B981', 'system' => true],
            ],
            'shards' => [],
        ];
        write_json_atomic($indexPath, $initial, true);
        return $initial;
    }

    /**
     * Cursor-based Drive pagination: read only as many JSON shards as required
     * to return up to $limit matching items; older items load on demand.
     */
    public static function getPersonalDrive(string $username, string $folderId = 'all', ?string $beforeItemId = null, int $limit = 80): array
    {
        $uname = normalize_username($username);
        $dir = self::getUserDriveDir($uname);
        $driveMeta = self::ensureDriveIndex($uname);
        $limit = max(10, min(150, $limit));
        $items = [];
        $cursorPassed = ($beforeItemId === null || $beforeItemId === '');
        $hasMore = false;
        $shards = $driveMeta['shards'] ?? [];

        for ($i = count($shards) - 1; $i >= 0; $i--) {
            $list = read_json_safe($dir . '/' . $shards[$i]['file'], []);
            if (!is_array($list)) continue;
            for ($j = count($list) - 1; $j >= 0; $j--) {
                $it = $list[$j];
                if (!$cursorPassed) {
                    if (($it['id'] ?? '') === $beforeItemId) $cursorPassed = true;
                    continue;
                }
                if (!empty($it['deleted'])) continue;
                if ($folderId === 'starred') {
                    if (empty($it['starred'])) continue;
                } elseif ($folderId !== 'all' && $folderId !== '' && ($it['folder_id'] ?? '') !== $folderId) {
                    continue;
                }
                if (count($items) >= $limit) {
                    $hasMore = true;
                    break 2;
                }
                if (isset($it['title']) && is_string($it['title'])) $it['title'] = CryptoEngine::decrypt($it['title']);
                if (isset($it['note']) && is_string($it['note'])) $it['note'] = CryptoEngine::decrypt($it['note']);
                $items[] = $it;
            }
        }

        return [
            'folders' => $driveMeta['folders'] ?? [],
            'items' => $items,
            'has_more' => $hasMore,
            'next_cursor' => $hasMore && count($items) ? $items[count($items) - 1]['id'] : null,
            'shard_count' => count($shards),
            'quota' => self::getUserQuotaSummary($uname),
        ];
    }

    public static function createDriveFolder(string $username, string $name, string $color = '#2AABEE'): array
    {
        $uname = normalize_username($username);
        $name = trim($name);
        if ($name === '') {
            return ['ok' => false, 'error' => 'نام پوشه نمی‌تواند خالی باشد.'];
        }
        return with_file_lock('drive_' . $uname, function () use ($uname, $name, $color) {
            $dir = self::getUserDriveDir($uname);
            $metaPath = $dir . '/drive_index.json';
            $driveMeta = self::ensureDriveIndex($uname);
            $newFolder = [
                'id' => 'fld_' . substr(bin2hex(random_bytes(4)), 0, 8),
                'name' => mb_substr($name, 0, 40),
                'icon' => 'folder',
                'color' => preg_match('/^#[0-9a-fA-F]{6}$/', $color) ? $color : '#2AABEE',
                'system' => false,
            ];
            $driveMeta['folders'][] = $newFolder;
            write_json_atomic($metaPath, $driveMeta, true);
            return ['ok' => true, 'folder' => $newFolder, 'folders' => $driveMeta['folders']];
        });
    }

    public static function deleteDriveFolder(string $username, string $folderId): array
    {
        $uname = normalize_username($username);
        return with_file_lock('drive_' . $uname, function () use ($uname, $folderId) {
            $dir = self::getUserDriveDir($uname);
            $metaPath = $dir . '/drive_index.json';
            $driveMeta = read_json_safe($metaPath, ['folders' => [], 'shards' => []]);
            $folders = $driveMeta['folders'] ?? [];
            $newFolders = [];
            foreach ($folders as $f) {
                if (($f['id'] ?? '') === $folderId) {
                    if (!empty($f['system'])) {
                        return ['ok' => false, 'error' => 'پوشه‌های پیش‌فرض سیستم قابل حذف نیستند (می‌توانید محتوای آن‌ها را پاک کنید).'];
                    }
                    continue;
                }
                $newFolders[] = $f;
            }
            if (count($newFolders) === count($folders)) return ['ok' => false, 'error' => 'این پوشه یافت نشد.'];
            foreach (($driveMeta['shards'] ?? []) as &$sh) {
                $path = $dir . '/' . $sh['file'];
                $items = read_json_safe($path, []);
                if (!is_array($items)) continue;
                $changed = false;
                foreach ($items as &$it) {
                    if (($it['folder_id'] ?? '') === $folderId && empty($it['deleted'])) {
                        $it['folder_id'] = 'files';
                        $changed = true;
                    }
                }
                unset($it);
                if ($changed) {
                    write_json_atomic($path, $items, false);
                    $sh['size_bytes'] = (int)@filesize($path);
                }
            }
            unset($sh);
            $driveMeta['folders'] = $newFolders;
            write_json_atomic($metaPath, $driveMeta, true);
            return ['ok' => true, 'folders' => $newFolders];
        });
    }

    public static function addDriveItem(string $username, array $itemData): array
    {
        $uname = normalize_username($username);
        $mediaId = (string)($itemData['media']['id'] ?? '');
        if ($mediaId !== '') {
            $viewer = Auth::getUser($uname);
            if ($viewer === null || !self::canUserViewMedia($viewer, $mediaId)) {
                return ['ok' => false, 'error' => 'دسترسی به این فایل برای ذخیره در فضای شخصی مجاز نیست.'];
            }
            $itemData['media'] = self::publicMediaMeta(self::getMediaMeta($mediaId) ?? []);
        }
        return with_file_lock('drive_' . $uname, function () use ($uname, $itemData) {
            $cfg = get_app_config();
            $maxBytes = max(16384, (int)($cfg['max_shard_bytes'] ?? 204800));
            $maxItems = max(20, (int)($cfg['max_shard_messages'] ?? 150));

            $dir = self::getUserDriveDir($uname);
            $metaPath = $dir . '/drive_index.json';
            $driveMeta = self::ensureDriveIndex($uname);
            $shards = $driveMeta['shards'] ?? [];

            $plainTitle = mb_substr(trim((string)($itemData['title'] ?? '')), 0, 120);
            if ($plainTitle === '') {
                $plainTitle = $itemData['media']['original_name'] ?? 'یادداشت شخصی';
            }
            $plainNote = (string)($itemData['note'] ?? '');
            if (mb_strlen($plainNote) > 20000) {
                return ['ok' => false, 'error' => 'هر یادداشت حداکثر ۲۰ هزار نویسه دارد؛ متن بلندتر را به شکل فایل ذخیره کنید.'];
            }

            $diskItem = [
                'id' => 'drv_' . bin2hex(random_bytes(6)),
                'folder_id' => (string)($itemData['folder_id'] ?? 'files'),
                'title' => CryptoEngine::encrypt($plainTitle),
                'note' => CryptoEngine::encrypt($plainNote),
                'media' => $itemData['media'] ?? null,
                'starred' => !empty($itemData['starred']),
                'created_at' => time(),
            ];

            $needNew = empty($shards);
            $nextNum = 1;
            if (!$needNew) {
                $last = $shards[count($shards) - 1];
                if (($last['size_bytes'] ?? 0) >= $maxBytes || ($last['count'] ?? 0) >= $maxItems) {
                    $needNew = true;
                    $nextNum = count($shards) + 1;
                }
            }

            if ($needNew) {
                $file = sprintf('drive_part_%04d.json', $nextNum);
                $shPath = $dir . '/' . $file;
                write_json_atomic($shPath, [$diskItem], false);
                $shards[] = [
                    'file' => $file,
                    'count' => 1,
                    'size_bytes' => (int)@filesize($shPath),
                    'updated_at' => time(),
                ];
            } else {
                $idx = count($shards) - 1;
                $file = $shards[$idx]['file'];
                $shPath = $dir . '/' . $file;
                $list = read_json_safe($shPath, []);
                if (!is_array($list)) $list = [];
                $list[] = $diskItem;
                write_json_atomic($shPath, $list, false);
                $shards[$idx]['count'] = count($list);
                $shards[$idx]['size_bytes'] = (int)@filesize($shPath);
                $shards[$idx]['updated_at'] = time();
            }

            $driveMeta['shards'] = $shards;
            write_json_atomic($metaPath, $driveMeta, true);
            if (!empty($diskItem['media']['id'])) {
                self::addDriveReference((string)$diskItem['media']['id'], $uname, (string)$diskItem['id']);
            }

            $plainItem = $diskItem;
            $plainItem['title'] = $plainTitle;
            $plainItem['note'] = $plainNote;
            return ['ok' => true, 'item' => $plainItem];
        });
    }

    public static function updateDriveItem(string $username, string $itemId, string $op, bool $deleteBinaryToo = false): array
    {
        $uname = normalize_username($username);
        return with_file_lock('drive_' . $uname, function () use ($uname, $itemId, $op, $deleteBinaryToo) {
            $dir = self::getUserDriveDir($uname);
            $metaPath = $dir . '/drive_index.json';
            $driveMeta = read_json_safe($metaPath, ['folders' => [], 'shards' => []]);
            $shards = $driveMeta['shards'] ?? [];

            foreach ($shards as $sIdx => $sh) {
                $shPath = $dir . '/' . $sh['file'];
                $list = read_json_safe($shPath, []);
                if (!is_array($list)) continue;

                foreach ($list as $iIdx => $it) {
                    if (($it['id'] ?? '') === $itemId && empty($it['deleted'])) {
                        if ($op === 'toggle_star') {
                            $list[$iIdx]['starred'] = empty($it['starred']);
                        } elseif ($op === 'delete') {
                            $list[$iIdx] = ['id' => $itemId, 'deleted' => true];
                            if (!empty($it['media']['id'])) {
                                self::removeDriveReference((string)$it['media']['id'], $uname, $itemId);
                            }
                        }
                        write_json_atomic($shPath, $list, false);
                        $shards[$sIdx]['size_bytes'] = (int)@filesize($shPath);
                        $driveMeta['shards'] = $shards;
                        write_json_atomic($metaPath, $driveMeta, true);
                        return ['ok' => true];
                    }
                }
            }
            return ['ok' => false, 'error' => 'مورد یافت نشد.'];
        });
    }

    public static function clearPersonalDrive(string $username): array
    {
        $uname = normalize_username($username);
        return with_file_lock('drive_' . $uname, function () use ($uname) {
            $dir = self::getUserDriveDir($uname);
            foreach (glob($dir . '/drive_part_*.json') ?: [] as $f) {
                $list = read_json_safe($f, []);
                if (is_array($list)) {
                    foreach ($list as $it) {
                        if (!empty($it['deleted'])) continue;
                        if (!empty($it['media']['id'])) {
                            self::removeDriveReference((string)$it['media']['id'], $uname, (string)($it['id'] ?? ''));
                        }
                    }
                }
                @unlink($f);
            }
            $metaPath = $dir . '/drive_index.json';
            $driveMeta = read_json_safe($metaPath, null);
            if (is_array($driveMeta)) {
                $driveMeta['shards'] = [];
                write_json_atomic($metaPath, $driveMeta, true);
            }
            return ['ok' => true];
        });
    }
}
