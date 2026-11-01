<?php
declare(strict_types=1);

require_once __DIR__ . '/bootstrap.php';

/**
 * Each user's chat visibility and inbox preferences live in a separate,
 * protected JSON file. This prevents a global lock/giant file when inboxes
 * grow. Watermarks hide earlier shared messages without erasing a peer's copy.
 */
class DialogView
{
    private const DIR = DATA_DIR . '/dialog_views';

    private static function path(string $username): string
    {
        $uname = normalize_username($username);
        if ($uname === '') throw new InvalidArgumentException('شناسه حساب نامعتبر است.');
        if (!is_dir(self::DIR) && !@mkdir(self::DIR, 0755, true) && !is_dir(self::DIR)) {
            throw new RuntimeException('پوشه تنظیمات گفتگو قابل ایجاد نیست.');
        }
        return self::DIR . '/' . $uname . '.json';
    }

    public static function all(string $username): array
    {
        $data = read_json_safe(self::path($username), []);
        return is_array($data) ? $data : [];
    }

    public static function state(string $username, string $chatId, ?array $all = null): array
    {
        $cid = preg_replace('/[^a-zA-Z0-9_.-]/', '', $chatId) ?? '';
        $data = $all ?? self::all($username);
        $s = $data[$cid] ?? [];
        return is_array($s) ? $s : [];
    }

    private static function change(string $username, string $chatId, callable $callback): array
    {
        $uname = normalize_username($username);
        $cid = preg_replace('/[^a-zA-Z0-9_.-]/', '', $chatId) ?? '';
        if ($uname === '' || $cid === '') throw new InvalidArgumentException('شناسه گفتگو نامعتبر است.');
        return with_file_lock('dialog_views_' . $uname, function () use ($uname, $cid, $callback): array {
            $path = self::path($uname);
            $all = read_json_safe($path, []);
            if (!is_array($all)) throw new RuntimeException('تنظیمات گفتگو آسیب دیده است.');
            $entry = is_array($all[$cid] ?? null) ? $all[$cid] : [];
            $entry = $callback($entry);
            if (!is_array($entry)) throw new RuntimeException('تنظیمات گفتگو معتبر نیست.');
            $all[$cid] = $entry;
            if (!write_json_atomic($path, $all, false)) {
                throw new RuntimeException('ثبت تنظیمات گفتگو ممکن نشد.');
            }
            return $entry;
        });
    }

    public static function clearForMe(string $username, string $chatId, int $latestSeq, bool $hide = false): array
    {
        return self::change($username, $chatId, function (array $s) use ($latestSeq, $hide): array {
            $s['cleared_seq'] = max((int)($s['cleared_seq'] ?? 0), max(0, $latestSeq));
            if ($hide) {
                $s['hidden'] = true;
                $s['hidden_until_seq'] = max(0, $latestSeq);
            }
            $s['view_rev'] = ((int)($s['view_rev'] ?? 0)) + 1;
            unset($s['last_hidden_seq']);
            return $s;
        });
    }

    public static function hideDialog(string $username, string $chatId, int $latestSeq): array
    {
        return self::change($username, $chatId, function (array $s) use ($latestSeq): array {
            $s['hidden'] = true;
            $s['hidden_until_seq'] = max(0, $latestSeq);
            $s['view_rev'] = ((int)($s['view_rev'] ?? 0)) + 1;
            return $s;
        });
    }

    public static function reopen(string $username, string $chatId): void
    {
        self::change($username, $chatId, function (array $s): array {
            if (!empty($s['hidden'])) {
                unset($s['hidden'], $s['hidden_until_seq']);
                $s['view_rev'] = ((int)($s['view_rev'] ?? 0)) + 1;
            }
            return $s;
        });
    }

    public static function noteHiddenMessage(string $username, string $chatId, int $seq): void
    {
        self::change($username, $chatId, function (array $s) use ($seq): array {
            $s['last_hidden_seq'] = $seq;
            return $s;
        });
    }

    /** One atomic write for account-wide reset; no quadratic rewrite per chat. */
    public static function bulkClearForMe(string $username, array $latestByChat): void
    {
        $uname = normalize_username($username);
        with_file_lock('dialog_views_' . $uname, function () use ($uname, $latestByChat): void {
            $path = self::path($uname);
            $all = read_json_safe($path, []);
            if (!is_array($all)) throw new RuntimeException('تنظیمات گفتگوها آسیب دیده است.');
            foreach ($latestByChat as $cid => $latest) {
                $cleanCid = preg_replace('/[^a-zA-Z0-9_.-]/', '', (string)$cid);
                if ($cleanCid === '' || $cleanCid !== $cid) continue;
                $state = is_array($all[$cid] ?? null) ? $all[$cid] : [];
                $state['cleared_seq'] = max((int)($state['cleared_seq'] ?? 0), (int)$latest);
                $state['hidden'] = true;
                $state['hidden_until_seq'] = (int)$latest;
                $state['view_rev'] = ((int)($state['view_rev'] ?? 0)) + 1;
                unset($state['last_hidden_seq'], $state['pinned'], $state['muted'], $state['archived']);
                $all[$cid] = $state;
            }
            if (!write_json_atomic($path, $all, false)) throw new RuntimeException('پاکسازی وضعیت گفتگوها ممکن نشد.');
        });
    }

    public static function preference(string $username, string $chatId, string $key, bool $value): array
    {
        if (!in_array($key, ['muted', 'pinned', 'archived'], true)) {
            throw new InvalidArgumentException('این تنظیم گفتگو پشتیبانی نمی‌شود.');
        }
        return self::change($username, $chatId, function (array $s) use ($key, $value): array {
            $s[$key] = $value;
            return $s;
        });
    }

    /** Remove every member's archived/muted/pinned state for a deleted room. */
    public static function removeChatForAll(string $chatId): void
    {
        $cid = preg_replace('/[^a-zA-Z0-9_.-]/', '', $chatId) ?? '';
        if ($cid === '') return;
        foreach (glob(self::DIR . '/*.json') ?: [] as $path) {
            $owner = basename($path, '.json');
            with_file_lock('dialog_views_' . $owner, function () use ($path, $cid): void {
                $all = read_json_safe($path, null);
                if (!is_array($all)) throw new RuntimeException('تنظیمات گفتگو آسیب دیده است.');
                if (!array_key_exists($cid, $all)) return;
                unset($all[$cid]);
                if (!write_json_atomic($path, $all, false)) throw new RuntimeException('پاکسازی تنظیمات گروه حذف‌شده ممکن نشد.');
            });
        }
    }

    /** Remove metadata for an account deleted by the administrator. */
    public static function deleteAccount(string $username): void
    {
        @unlink(self::path($username));
    }

    /** Migrate both the file owner and DM keys when an account ID changes. */
    public static function renameAccount(string $oldUsername, string $newUsername): void
    {
        $old = normalize_username($oldUsername);
        $new = normalize_username($newUsername);
        if ($old === '' || $new === '' || $old === $new) return;
        foreach (glob(self::DIR . '/*.json') ?: [] as $path) {
            $owner = basename($path, '.json');
            with_file_lock('dialog_views_' . $owner, function () use ($path, $owner, $old, $new): void {
                $all = read_json_safe($path, []);
                if (!is_array($all)) return;
                $mapped = [];
                foreach ($all as $cid => $state) {
                    if ($cid === 'saved_' . $old) $cid = 'saved_' . $new;
                    elseif ($cid === 'ai_' . $old) $cid = 'ai_' . $new;
                    elseif (strpos($cid, 'dm_') === 0) {
                        $pair = explode('__', substr($cid, 3));
                        if (count($pair) === 2 && in_array($old, $pair, true)) {
                            $pair = array_map(fn($p) => $p === $old ? $new : $p, $pair);
                            sort($pair, SORT_STRING);
                            $cid = 'dm_' . $pair[0] . '__' . $pair[1];
                        }
                    }
                    $mapped[$cid] = $state;
                }
                if ($owner === $old) {
                    $destination = self::path($new);
                    if (file_exists($destination)) throw new RuntimeException('تداخل در پرونده تنظیمات گفتگوهای آیدی جدید.');
                    if (!write_json_atomic($destination, $mapped, false)) throw new RuntimeException('انتقال تنظیمات گفتگو ممکن نیست.');
                    @unlink($path);
                } else {
                    if (!write_json_atomic($path, $mapped, false)) throw new RuntimeException('انتقال تنظیمات مخاطب ممکن نیست.');
                }
            });
        }
    }
}
