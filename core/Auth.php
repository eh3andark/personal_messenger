<?php
declare(strict_types=1);

require_once __DIR__ . '/bootstrap.php';
require_once __DIR__ . '/DialogView.php';

class Auth
{
    private const USERS_FILE = DATA_DIR . '/users.json';
    private const SESSIONS_FILE = DATA_DIR . '/sessions.json';
    private const PRESENCE_FILE = DATA_DIR . '/presence.json';
    private const CONTACTS_FILE = DATA_DIR . '/contacts.json';
    private const BLOCKS_FILE = DATA_DIR . '/blocks.json';
    private const PRIMARY_ADMIN_FILE = DATA_DIR . '/primary_admin.json';
    // Private audit metadata. Never return it in bootstrap, public user lists or raw users.json.
    private const ACTIVITY_FILE = DATA_DIR . '/user_activity.json';
    private const ATTEMPTS_FILE = DATA_DIR . '/login_attempts.json';
    private const LOOKUPS_FILE = DATA_DIR . '/contact_lookup_limits.json';
    private const COOKIE_NAME = 'hamrah_token';
    private const SESSION_TTL = 2592000; // 30 days

    private static array $palette = [
        '#2AABEE', '#10B981', '#EC4899', '#8B5CF6',
        '#F59E0B', '#EF4444', '#06B6D4', '#6366F1'
    ];

    /**
     * Load all users from data/users.json and apply smart defaults
     */
    public static function getAllUsers(bool $includeSecrets = false): array
    {
        $rawList = read_json_safe(self::USERS_FILE, []);
        if (!is_array($rawList)) {
            $rawList = [];
        }

        $normalized = [];
        $idx = 0;
        foreach ($rawList as $key => $item) {
            if (!is_array($item)) {
                continue;
            }
            $uname = isset($item['username']) ? normalize_username((string)$item['username']) : normalize_username((string)$key);
            if ($uname === '') {
                continue;
            }
            if (isset($normalized[$uname])) {
                throw new RuntimeException('آیدی تکراری در users.json پیدا شد؛ فایل را از نسخهٔ پشتیبان اصلاح کنید: ' . $uname);
            }
            $color = $item['avatar_color'] ?? self::$palette[$idx % count(self::$palette)];
            $idx++;

            $storedPass = (string)($item['password'] ?? '');
            $isHashed = strpos($storedPass, '$2y$') === 0 || strpos($storedPass, '$2a$') === 0 || strpos($storedPass, '$argon2') === 0;

            $user = [
                'username' => $uname,
                'display_name' => trim((string)($item['display_name'] ?? $uname)),
                'role' => (($item['role'] ?? 'user') === 'admin') ? 'admin' : 'user',
                'avatar_color' => (string)$color,
                'avatar_url' => (string)($item['avatar_url'] ?? ''),
                'bio' => (string)($item['bio'] ?? ''),
                'quota_mb' => max(50, (int)($item['quota_mb'] ?? 1024)),
                'active' => array_key_exists('active', $item) ? (bool)$item['active'] : true,
                'password_hashed' => $isHashed,
            ];
            if ($includeSecrets) {
                $user['password'] = $storedPass;
            }
            $normalized[$uname] = $user;
        }
        return $normalized;
    }

    public static function getUser(string $username, bool $includeSecrets = false): ?array
    {
        $uname = normalize_username($username);
        if ($uname === '') {
            return null;
        }
        $all = self::getAllUsers($includeSecrets);
        return $all[$uname] ?? null;
    }

    /**
     * The first setup administrator is a stable, protected system contact.
     * On installations predating this marker, preserve the first active admin
     * in the existing ordered registry (the best available migration signal).
     */
    public static function getPrimaryAdminId(): string
    {
        $stored = read_json_safe(self::PRIMARY_ADMIN_FILE, []);
        if (is_array($stored) && isset($stored['username'])) {
            $id = normalize_username((string)$stored['username']);
            if ($id !== '') return $id;
        }
        return with_file_lock('primary_admin', function (): string {
            $saved = read_json_safe(self::PRIMARY_ADMIN_FILE, []);
            if (is_array($saved) && !empty($saved['username'])) return normalize_username((string)$saved['username']);
            $users = self::getAllUsers(false);
            foreach ($users as $id => $user) {
                if (($user['role'] ?? '') === 'admin' && !empty($user['active'])) {
                    if (!write_json_atomic(self::PRIMARY_ADMIN_FILE, ['username' => $id], true)) {
                        throw new RuntimeException('ثبت مدیر اصلی سامانه ممکن نشد.');
                    }
                    return $id;
                }
            }
            return '';
        });
    }

    /** Fail closed rather than silently erasing account history on corrupt JSON. */
    private static function readActivityLedger(): array
    {
        if (!file_exists(self::ACTIVITY_FILE)) return [];
        $raw = @file_get_contents(self::ACTIVITY_FILE);
        $entries = $raw === false ? null : json_decode($raw, true);
        if (!is_array($entries) || json_last_error() !== JSON_ERROR_NONE) {
            throw new RuntimeException('فایل آمار حساب‌ها نامعتبر است؛ از data/ نسخهٔ پشتیبان را بررسی کنید.');
        }
        return $entries;
    }

    private static function writeActivityLedger(array $entries): void
    {
        if (!write_json_atomic(self::ACTIVITY_FILE, $entries, false)) {
            throw new RuntimeException('ثبت آمار حساب‌ها در data/ ممکن نشد؛ دسترسی نوشتن و فضای دیسک را بررسی کنید.');
        }
    }

    /** Register new IDs before committing users.json; a failed user write leaves only an ignored orphan. */
    public static function recordCreatedAccounts(array $ids): void
    {
        if (!$ids) return;
        with_file_lock('user_activity', function () use ($ids): void {
            $entries = self::readActivityLedger();
            $now = time();
            foreach ($ids as $id) {
                $id = normalize_username((string)$id);
                if ($id !== '') {
                    // A safely recycled ID must never inherit the prior owner's history.
                    $entries[$id] = ['created_at' => $now, 'last_login_at' => 0];
                }
            }
            self::writeActivityLedger($entries);
        });
    }

    /** A successful authentication, not password creation/reset, proves the account was used. */
    private static function recordSuccessfulLogin(string $username, int $at): void
    {
        with_file_lock('user_activity', function () use ($username, $at): void {
            $entries = self::readActivityLedger();
            $entry = is_array($entries[$username] ?? null) ? $entries[$username] : [];
            $entry['last_login_at'] = max((int)($entry['last_login_at'] ?? 0), $at);
            $entries[$username] = $entry; // legacy accounts have no recoverable creation date
            self::writeActivityLedger($entries);
        });
    }

    /** Rename keeps the account history; a target's orphaned history is never inherited. */
    private static function renameAccountActivity(string $old, string $new): void
    {
        with_file_lock('user_activity', function () use ($old, $new): void {
            $entries = self::readActivityLedger();
            $original = $entries[$old] ?? null;
            unset($entries[$old], $entries[$new]);
            if (is_array($original)) $entries[$new] = $original;
            self::writeActivityLedger($entries);
        });
    }

    /** Remove deleted IDs from the private audit ledger; raw JSON edits can remove several. */
    public static function removeAccountActivity(array $ids): void
    {
        if (!$ids) return;
        with_file_lock('user_activity', function () use ($ids): void {
            $entries = self::readActivityLedger();
            $changed = false;
            foreach ($ids as $id) {
                $id = normalize_username((string)$id);
                if (isset($entries[$id])) { unset($entries[$id]); $changed = true; }
            }
            if ($changed) self::writeActivityLedger($entries);
        });
    }

    /** Full activity report is consumed ONLY by the primary-admin API route. */
    public static function getAccountActivityReport(): array
    {
        $users = self::getAllUsers(false);
        $entries = self::readActivityLedger();
        $presence = self::getPresenceMap(array_keys($users));
        $now = time();
        $safeTime = static function ($value) use ($now): int {
            $ts = is_int($value) || (is_string($value) && ctype_digit($value)) ? (int)$value : 0;
            return $ts > 0 && $ts <= $now + 300 ? $ts : 0;
        };
        $summary = ['total' => count($users), 'online' => 0, 'never_signed_in' => 0,
            'inactive_7d' => 0, 'inactive_30d' => 0, 'unknown' => 0, 'disabled' => 0];
        $report = [];
        foreach ($users as $id => $user) {
            $entry = is_array($entries[$id] ?? null) ? $entries[$id] : [];
            $created = $safeTime($entry['created_at'] ?? 0);
            $lastLogin = $safeTime($entry['last_login_at'] ?? 0);
            $lastSeen = $safeTime($presence[$id]['last_seen'] ?? 0);
            $lastActivity = max($lastLogin, $lastSeen);
            $online = !empty($user['active']) && !empty($presence[$id]['online']) && $lastSeen > 0;
            $status = $online ? 'online' : ($lastActivity > 0 ? 'used' : ($created > 0 ? 'never_signed_in' : 'unknown'));
            $daysSinceCreation = $created > 0 ? intdiv(max(0, $now - $created), 86400) : null;
            $daysOffline = !$online && $lastActivity > 0 ? intdiv(max(0, $now - $lastActivity), 86400) : null;
            if ($online) $summary['online']++;
            if ($status === 'never_signed_in') $summary['never_signed_in']++;
            if ($status === 'unknown') $summary['unknown']++;
            if ($status === 'used' && $daysOffline !== null && $daysOffline >= 7) $summary['inactive_7d']++;
            if ($status === 'used' && $daysOffline !== null && $daysOffline >= 30) $summary['inactive_30d']++;
            if (empty($user['active'])) $summary['disabled']++;
            $report[] = [
                'username' => $id, 'display_name' => $user['display_name'], 'role' => $user['role'],
                'active' => $user['active'], 'created_at' => $created,
                'last_login_at' => $lastLogin, 'last_seen_at' => $lastSeen,
                'last_activity_at' => $lastActivity, 'online' => $online, 'status' => $status,
                'days_since_creation' => $daysSinceCreation, 'days_offline' => $daysOffline,
            ];
        }
        return ['users' => $report, 'summary' => $summary, 'server_time' => $now];
    }

    /**
     * Verify hashes and legacy manual plaintext; legacy entries are upgraded
     * to Bcrypt under the users lock on their first successful login.
     */
    public static function verifyPassword(string $stored, string $input): bool
    {
        if ($stored === '' || $input === '') {
            return false;
        }
        if (strpos($stored, '$2y$') === 0 || strpos($stored, '$2a$') === 0 || strpos($stored, '$argon2') === 0) {
            return password_verify($input, $stored);
        }
        return hash_equals($stored, $input);
    }

    public static function hashPasswordIfPlain(string $pass): string
    {
        if (strpos($pass, '$2y$') === 0 || strpos($pass, '$2a$') === 0 || strpos($pass, '$argon2') === 0) {
            return $pass;
        }
        return password_hash($pass, PASSWORD_BCRYPT);
    }

    // ========================================================================
    // Contacts & User Discovery Privacy (Only Admin sees all users by default!)
    // ========================================================================

    public static function getUserContacts(string $username): array
    {
        $uname = normalize_username($username);
        $allContacts = read_json_safe(self::CONTACTS_FILE, []);
        $list = is_array($allContacts) && is_array($allContacts[$uname] ?? null) ? $allContacts[$uname] : [];
        $primary = self::getPrimaryAdminId();
        if ($primary !== '' && $primary !== $uname) $list[] = $primary;
        return array_values(array_unique(array_filter(array_map('normalize_username', $list))));
    }

    /** Prevent scripted enumeration of guessed user IDs (30 lookups / 10min). */
    public static function allowContactLookup(string $username): bool
    {
        $uname = normalize_username($username);
        return with_file_lock('contact_lookups', function () use ($uname): bool {
            $data = read_json_safe(self::LOOKUPS_FILE, []);
            if (!is_array($data)) $data = [];
            $now = time();
            foreach ($data as $id => $entry) {
                if (($entry['expires'] ?? 0) < $now) unset($data[$id]);
            }
            $entry = $data[$uname] ?? ['count' => 0, 'expires' => $now + 600];
            if ($entry['count'] >= 30) return false;
            $entry['count']++;
            $data[$uname] = $entry;
            write_json_atomic(self::LOOKUPS_FILE, $data, false);
            return true;
        });
    }

    public static function addContact(string $ownerUsername, string $targetId): array
    {
        $owner = normalize_username($ownerUsername);
        $target = normalize_username($targetId);
        if ($target === '') {
            return ['ok' => false, 'error' => 'لطفاً آیدی (نام کاربری) مورد نظر را وارد کنید.'];
        }
        if ($target === $owner) {
            return ['ok' => false, 'error' => 'این آیدی متعلق به خود شماست! برای یادداشت شخصی از «پیام‌های ذخیره‌شده» استفاده کنید.'];
        }
        if (self::eitherUserBlocked($owner, $target)) {
            return ['ok' => false, 'error' => 'ارتباط با این کاربر مسدود شده است.'];
        }

        $targetUser = self::getUser($target, false);
        if ($targetUser === null || !$targetUser['active']) {
            return ['ok' => false, 'error' => 'کاربری با این آیدی یافت نشد. از صحت املای نام کاربری اطمینان حاصل کنید.'];
        }

        with_file_lock('contacts', function () use ($owner, $target) {
            $all = read_json_safe(self::CONTACTS_FILE, []);
            if (!is_array($all)) {
                $all = [];
            }
            $list = isset($all[$owner]) && is_array($all[$owner]) ? $all[$owner] : [];
            if (!in_array($target, $list, true)) {
                $list[] = $target;
                $all[$owner] = array_values($list);
                write_json_atomic(self::CONTACTS_FILE, $all, true);
            }
        });

        return ['ok' => true, 'contact' => $targetUser];
    }

    public static function removeContact(string $ownerUsername, string $targetUsername): bool
    {
        $owner = normalize_username($ownerUsername);
        $target = normalize_username($targetUsername);
        if ($target !== '' && $target === self::getPrimaryAdminId() && $owner !== $target) return false;
        return with_file_lock('contacts', function () use ($owner, $target) {
            $all = read_json_safe(self::CONTACTS_FILE, []);
            if (!is_array($all) || !isset($all[$owner]) || !is_array($all[$owner])) {
                return true;
            }
            $all[$owner] = array_values(array_filter($all[$owner], fn($u) => normalize_username((string)$u) !== $target));
            return write_json_atomic(self::CONTACTS_FILE, $all, true);
        });
    }

    public static function clearAllContacts(string $username): bool
    {
        $uname = normalize_username($username);
        return with_file_lock('contacts', function () use ($uname): bool {
            $all = read_json_safe(self::CONTACTS_FILE, []);
            if (!is_array($all)) throw new RuntimeException('فایل مخاطبان قابل خواندن نیست.');
            unset($all[$uname]);
            return write_json_atomic(self::CONTACTS_FILE, $all, true);
        });
    }

    /** Block lists are private to their owner and never returned to peers. */
    public static function getBlockedUsers(string $username): array
    {
        $all = read_json_safe(self::BLOCKS_FILE, []);
        $list = is_array($all) ? ($all[normalize_username($username)] ?? []) : [];
        return is_array($list) ? array_values(array_unique(array_filter(array_map('normalize_username', $list)))) : [];
    }

    public static function eitherUserBlocked(string $first, string $second): bool
    {
        $a = normalize_username($first);
        $b = normalize_username($second);
        if ($a === '' || $b === '') return false;
        $all = read_json_safe(self::BLOCKS_FILE, []);
        return is_array($all) && (in_array($b, $all[$a] ?? [], true) || in_array($a, $all[$b] ?? [], true));
    }

    public static function setUserBlocked(string $owner, string $target, bool $blocked): array
    {
        $owner = normalize_username($owner);
        $target = normalize_username($target);
        if ($owner === '' || $target === '' || $owner === $target) {
            return ['ok' => false, 'error' => 'آیدی کاربر برای مسدودسازی معتبر نیست.'];
        }
        if ($target === self::getPrimaryAdminId()) {
            return ['ok' => false, 'error' => 'مدیر اصلی سامانه مخاطب دائمی است و قابل مسدودسازی نیست.'];
        }
        if ($blocked) {
            $peer = self::getUser($target);
            $known = in_array($target, self::getUserContacts($owner), true);
            $dm = DATA_DIR . '/chats/' . self::directChatId($owner, $target) . '/index.json';
            if ($peer === null || !$peer['active'] || (!$known && !is_file($dm))) {
                return ['ok' => false, 'error' => 'برای مسدودسازی، کاربر باید مخاطب شما یا هم‌گفتگوی قبلی شما باشد.'];
            }
        }
        return with_file_lock('blocks', function () use ($owner, $target, $blocked): array {
            $all = read_json_safe(self::BLOCKS_FILE, []);
            if (!is_array($all)) throw new RuntimeException('فهرست مسدودی‌ها آسیب دیده است.');
            $list = is_array($all[$owner] ?? null) ? $all[$owner] : [];
            $all[$owner] = $blocked
                ? array_values(array_unique(array_merge($list, [$target])))
                : array_values(array_filter($list, fn($item) => $item !== $target));
            if (!write_json_atomic(self::BLOCKS_FILE, $all, false)) throw new RuntimeException('ذخیره مسدودی کاربر ممکن نشد.');
            return ['ok' => true, 'blocked_users' => self::getBlockedUsers($owner)];
        });
    }

    private static function directChatId(string $first, string $second): string
    {
        $pair = [$first, $second];
        sort($pair, SORT_STRING);
        return 'dm_' . $pair[0] . '__' . $pair[1];
    }

    /**
     * Wipe the owner's restrictions on self-service reset; peer-owned blocks
     * remain theirs. Deleted accounts must also be removed from peer lists.
     */
    public static function clearBlocks(string $username, bool $removeFromOthers = false): void
    {
        $uname = normalize_username($username);
        with_file_lock('blocks', function () use ($uname, $removeFromOthers): void {
            $all = read_json_safe(self::BLOCKS_FILE, []);
            if (!is_array($all)) throw new RuntimeException('فهرست مسدودی‌ها آسیب دیده است.');
            unset($all[$uname]);
            if ($removeFromOthers) {
                foreach ($all as &$list) {
                    if (is_array($list)) $list = array_values(array_filter($list, fn($item) => $item !== $uname));
                }
                unset($list);
            }
            if (!write_json_atomic(self::BLOCKS_FILE, $all, false)) throw new RuntimeException('پاکسازی مسدودی‌ها ممکن نشد.');
        });
    }

    /**
     * Return ONLY users that $viewer is permitted to know about:
     * - Admin sees everyone.
     * - Regular user sees: themselves + explicit contacts + peers with active DM history + members of shared groups.
     */
    public static function getVisibleUsersFor(array $viewer, array $extraPeerUsernames = []): array
    {
        $all = self::getAllUsers(false);
        if (($viewer['role'] ?? '') === 'admin') {
            return $all;
        }

        $uname = $viewer['username'];
        $allowed = [$uname => true];
        foreach (self::getUserContacts($uname) as $c) {
            $allowed[$c] = true;
        }
        foreach ($extraPeerUsernames as $ep) {
            $allowed[normalize_username((string)$ep)] = true;
        }

        $filtered = [];
        foreach ($allowed as $k => $_) {
            if (isset($all[$k]) && $all[$k]['active']) {
                $filtered[$k] = $all[$k];
            }
        }
        return $filtered;
    }

    // ========================================================================
    // Brute-force Protection & Login Sessions
    // ========================================================================

    private static function checkRateLimit(string $ip): bool
    {
        $attempts = read_json_safe(self::ATTEMPTS_FILE, []);
        $now = time();
        $entry = $attempts[$ip] ?? ['count' => 0, 'reset_at' => $now + 300];
        if ($now > ($entry['reset_at'] ?? 0)) {
            return true;
        }
        return ($entry['count'] ?? 0) < 12;
    }

    private static function recordFailedAttempt(string $ip): void
    {
        with_file_lock('login_attempts', function () use ($ip) {
            $attempts = read_json_safe(self::ATTEMPTS_FILE, []);
            $now = time();
            foreach ($attempts as $k => $v) {
                if (($v['reset_at'] ?? 0) < $now) {
                    unset($attempts[$k]);
                }
            }
            $entry = $attempts[$ip] ?? ['count' => 0, 'reset_at' => $now + 300];
            if ($now > ($entry['reset_at'] ?? 0)) {
                $entry = ['count' => 0, 'reset_at' => $now + 300];
            }
            $entry['count'] = ($entry['count'] ?? 0) + 1;
            $attempts[$ip] = $entry;
            write_json_atomic(self::ATTEMPTS_FILE, $attempts, false);
        });
    }

    private static function clearFailedAttempts(string $ip): void
    {
        with_file_lock('login_attempts', function () use ($ip) {
            $attempts = read_json_safe(self::ATTEMPTS_FILE, []);
            if (isset($attempts[$ip])) {
                unset($attempts[$ip]);
                write_json_atomic(self::ATTEMPTS_FILE, $attempts, false);
            }
        });
    }

    public static function login(string $username, string $password, string $tabId = ''): array
    {
        $ip = $_SERVER['REMOTE_ADDR'] ?? '0.0.0.0';
        if (!self::checkRateLimit($ip)) {
            return ['ok' => false, 'error' => 'تعداد تلاش‌های ناموفق بیش از حد مجاز است. لطفاً ۵ دقیقه صبر کنید.'];
        }

        $user = self::getUser($username, true);
        if ($user === null || !$user['active']) {
            self::recordFailedAttempt($ip);
            return ['ok' => false, 'error' => 'نام کاربری یا رمز عبور اشتباه است.'];
        }

        if (!self::verifyPassword($user['password'], $password)) {
            self::recordFailedAttempt($ip);
            return ['ok' => false, 'error' => 'نام کاربری یا رمز عبور اشتباه است.'];
        }

        if (empty($user['password_hashed'])) {
            // Retain support for old hand-edited registries without keeping an
            // authenticated plaintext password on disk after the first login.
            with_file_lock('users', function () use ($user, $password): void {
                $rows = read_json_safe(self::USERS_FILE, []);
                if (!is_array($rows)) throw new RuntimeException('فایل کاربران قابل خواندن نیست.');
                foreach ($rows as &$row) {
                    if (!is_array($row) || normalize_username((string)($row['username'] ?? '')) !== $user['username']) continue;
                    $stored = (string)($row['password'] ?? '');
                    if (!self::verifyPassword($stored, $password)) break; // concurrent password change
                    if (strpos($stored, '$2y$') !== 0 && strpos($stored, '$2a$') !== 0 && strpos($stored, '$argon2') !== 0) {
                        $row['password'] = password_hash($password, PASSWORD_BCRYPT);
                        if (!write_json_atomic(self::USERS_FILE, $rows, true)) {
                            throw new RuntimeException('بروزرسانی امن رمز عبور قدیمی روی هاست ممکن نشد.');
                        }
                    }
                    break;
                }
                unset($row);
            });
            $user = self::getUser($user['username'], true);
            if ($user === null || !self::verifyPassword((string)$user['password'], $password)) {
                self::recordFailedAttempt($ip);
                return ['ok' => false, 'error' => 'نام کاربری یا رمز عبور اشتباه است.'];
            }
        }

        self::clearFailedAttempts($ip);
        $token = bin2hex(random_bytes(32));
        $mediaTicket = bin2hex(random_bytes(16)); // media-only, short-lived, never use main session token in URLs
        $now = time();

        with_file_lock('sessions', function () use ($token, $mediaTicket, $user, $now, $ip) {
            $sessions = read_json_safe(self::SESSIONS_FILE, []);
            if (!is_array($sessions)) {
                $sessions = [];
            }
            foreach ($sessions as $t => $info) {
                if (($info['expires_at'] ?? 0) < $now) {
                    unset($sessions[$t]);
                }
            }
            $sessions[$token] = [
                'username' => $user['username'],
                'created_at' => $now,
                'last_seen' => $now,
                'expires_at' => $now + self::SESSION_TTL,
                'ip' => $ip,
                'ua' => substr((string)($_SERVER['HTTP_USER_AGENT'] ?? ''), 0, 140),
                'password_fp' => hash('sha256', (string)$user['password']),
                'media_ticket' => $mediaTicket,
                'media_ticket_expires_at' => $now + 86400,
            ];
            if (!write_json_atomic(self::SESSIONS_FILE, $sessions, false)) {
                throw new RuntimeException('ثبت نشست ورود در data/ ممکن نشد.');
            }
        });

        self::recordSuccessfulLogin($user['username'], $now);
        self::setSessionCookie($token, $now + self::SESSION_TTL);
        self::touchPresence($user['username'], null, 'typing', $tabId);

        unset($user['password']);
        return [
            'ok' => true,
            'token' => $token,
            'media_ticket' => $mediaTicket,
            'user' => $user,
        ];
    }

    private static function setSessionCookie(string $token, int $expiresAt): void
    {
        $isHttps = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off')
            || (($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? '') === 'https')
            || (($_SERVER['SERVER_PORT'] ?? '') == 443);

        $options = [
            'expires' => $expiresAt,
            'path' => '/',
            'httponly' => true,
            'samesite' => 'Lax',
        ];
        if ($isHttps) {
            $options['secure'] = true;
        }
        @setcookie(self::COOKIE_NAME, $token, $options);
    }

    public static function getRequestToken(): string
    {
        $headerToken = $_SERVER['HTTP_X_AUTH_TOKEN'] ?? '';
        if (is_string($headerToken) && preg_match('/^[a-f0-9]{64}$/', $headerToken)) {
            return $headerToken;
        }
        $cookieToken = $_COOKIE[self::COOKIE_NAME] ?? '';
        if (is_string($cookieToken) && preg_match('/^[a-f0-9]{64}$/', $cookieToken)) {
            return $cookieToken;
        }
        // Never accept full 30-day authentication tokens in URL query strings:
        // web-server access logs would otherwise capture and expose them.
        return '';
    }

    public static function currentUser(): ?array
    {
        $token = self::getRequestToken();
        if ($token === '') {
            return null;
        }
        $sessions = read_json_safe(self::SESSIONS_FILE, []);
        if (!is_array($sessions) || !isset($sessions[$token])) {
            return null;
        }
        $sess = $sessions[$token];
        if (($sess['expires_at'] ?? 0) < time()) {
            return null;
        }
        $user = self::getUser((string)$sess['username'], true);
        if ($user === null || !$user['active'] ||
            !hash_equals((string)($sess['password_fp'] ?? ''), hash('sha256', (string)$user['password']))) {
            return null;
        }
        unset($user['password']);
        return $user;
    }

    /** Media-only token refreshed once per day; separate from login token. */
    public static function getRequestMediaTicket(): string
    {
        $token = self::getRequestToken();
        if ($token === '') return '';
        $sessions = read_json_safe(self::SESSIONS_FILE, []);
        $s = $sessions[$token] ?? null;
        if (!is_array($s) || ($s['expires_at'] ?? 0) < time()) return '';
        if (($s['media_ticket_expires_at'] ?? 0) > time() + 3600 && !empty($s['media_ticket'])) {
            return (string)$s['media_ticket'];
        }
        return with_file_lock('sessions', function () use ($token): string {
            $all = read_json_safe(self::SESSIONS_FILE, []);
            if (!isset($all[$token]) || ($all[$token]['expires_at'] ?? 0) < time()) return '';
            $all[$token]['media_ticket'] = bin2hex(random_bytes(16));
            $all[$token]['media_ticket_expires_at'] = time() + 86400;
            write_json_atomic(self::SESSIONS_FILE, $all, false);
            return $all[$token]['media_ticket'];
        });
    }

    /** A media ticket can only fetch authorized media; NEVER call this on mutating routes. */
    public static function currentUserForMedia(): ?array
    {
        $normal = self::currentUser();
        if ($normal !== null) return $normal;
        $ticket = $_GET['media_ticket'] ?? '';
        if (!is_string($ticket) || !preg_match('/^[a-f0-9]{32}$/', $ticket)) return null;
        $sessions = read_json_safe(self::SESSIONS_FILE, []);
        $now = time();
        foreach ($sessions as $sess) {
            if (!is_array($sess) || empty($sess['media_ticket']) ||
                !hash_equals((string)$sess['media_ticket'], $ticket) ||
                ($sess['media_ticket_expires_at'] ?? 0) < $now ||
                ($sess['expires_at'] ?? 0) < $now) continue;
            $user = self::getUser((string)($sess['username'] ?? ''), true);
            if ($user !== null && $user['active'] &&
                hash_equals((string)($sess['password_fp'] ?? ''), hash('sha256', (string)$user['password']))) {
                unset($user['password']);
                return $user;
            }
        }
        return null;
    }

    public static function requireUser(): array
    {
        $user = self::currentUser();
        if ($user === null) {
            json_response(['ok' => false, 'error' => 'نشست کاربری منقضی شده است. لطفاً مجدداً وارد شوید.', 'unauthenticated' => true], 401);
        }
        return $user;
    }

    public static function requireAdmin(): array
    {
        $user = self::requireUser();
        if (($user['role'] ?? '') !== 'admin') {
            json_response(['ok' => false, 'error' => 'فقط مدیر سیستم به این بخش دسترسی دارد.'], 403);
        }
        return $user;
    }

    public static function logout(): void
    {
        $token = self::getRequestToken();
        if ($token !== '') {
            with_file_lock('sessions', function () use ($token) {
                $sessions = read_json_safe(self::SESSIONS_FILE, []);
                if (isset($sessions[$token])) {
                    unset($sessions[$token]);
                    write_json_atomic(self::SESSIONS_FILE, $sessions, false);
                }
            });
        }
        self::setSessionCookie('', time() - 3600);
    }

    /**
     * Preserve only the CURRENT device after a password change. Other devices
     * are invalidated automatically by the password fingerprint check.
     */
    public static function refreshCurrentSessionFingerprint(string $username): void
    {
        $token = self::getRequestToken();
        $user = self::getUser($username, true);
        if ($token === '' || $user === null) return;
        with_file_lock('sessions', function () use ($token, $user) {
            $sessions = read_json_safe(self::SESSIONS_FILE, []);
            if (!is_array($sessions) || !isset($sessions[$token])) return;
            $sessions[$token]['username'] = $user['username'];
            $sessions[$token]['password_fp'] = hash('sha256', (string)$user['password']);
            $sessions[$token]['last_seen'] = time();
            write_json_atomic(self::SESSIONS_FILE, $sessions, false);
        });
    }

    /**
     * List active login sessions for a user
     */
    public static function getUserSessions(string $username): array
    {
        $uname = normalize_username($username);
        $curToken = self::getRequestToken();
        $sessions = read_json_safe(self::SESSIONS_FILE, []);
        $now = time();
        $list = [];
        if (is_array($sessions)) {
            foreach ($sessions as $tok => $s) {
                if (($s['username'] ?? '') === $uname && ($s['expires_at'] ?? 0) >= $now) {
                    $list[] = [
                        'id' => substr((string)$tok, 0, 12),
                        'is_current' => ($tok === $curToken),
                        'ip' => $s['ip'] ?? '',
                        'ua' => $s['ua'] ?? 'مرورگر وب',
                        'created_at' => (int)($s['created_at'] ?? 0),
                        'last_seen' => (int)($s['last_seen'] ?? 0),
                    ];
                }
            }
        }
        return $list;
    }

    /**
     * Terminate all sessions of a user except the current one
     */
    public static function terminateOtherSessions(string $username): int
    {
        $uname = normalize_username($username);
        $curToken = self::getRequestToken();
        return with_file_lock('sessions', function () use ($uname, $curToken): int {
            $sessions = read_json_safe(self::SESSIONS_FILE, []);
            if (!is_array($sessions)) {
                return 0;
            }
            $removed = 0;
            foreach ($sessions as $tok => $s) {
                if (($s['username'] ?? '') === $uname && $tok !== $curToken) {
                    unset($sessions[$tok]);
                    $removed++;
                }
            }
            write_json_atomic(self::SESSIONS_FILE, $sessions, false);
            return $removed;
        });
    }

    /** A fresh heartbeat per browser page; old clients fall back to a session key. */
    public static function touchPresence(string $username, ?string $typingChatId = null, string $activityType = 'typing', string $tabId = ''): void
    {
        $uname = normalize_username($username);
        if ($uname === '') return;
        $tab = preg_match('/^[a-f0-9]{32}$/D', $tabId)
            ? $tabId : 'legacy_' . substr(hash('sha256', self::getRequestToken() ?: $uname), 0, 32);
        with_file_lock('presence', function () use ($uname, $typingChatId, $activityType, $tab): void {
            $presence = read_json_safe(self::PRESENCE_FILE, []);
            if (!is_array($presence)) $presence = [];
            $now = time();
            $entry = is_array($presence[$uname] ?? null) ? $presence[$uname] : [];
            $tabs = is_array($entry['tabs'] ?? null) ? $entry['tabs'] : [];
            $closed = is_array($entry['closed_tabs'] ?? null) ? $entry['closed_tabs'] : [];
            foreach ($tabs as $key => $seen) if ($now - (int)$seen > 35) unset($tabs[$key]);
            foreach ($closed as $key => $seen) if ($now - (int)$seen > 90) unset($closed[$key]);
            // A late sync response must not resurrect a tab that just closed.
            if (isset($closed[$tab])) return;
            $tabs[$tab] = $now;
            $entry['tabs'] = $tabs;
            $entry['closed_tabs'] = $closed;
            $entry['last_seen'] = $now;
            if ($typingChatId !== null) {
                if ($typingChatId === '') {
                    unset($entry['typing_chat'], $entry['typing_until'], $entry['activity_type']);
                } else {
                    $entry['typing_chat'] = $typingChatId;
                    $entry['typing_until'] = $now + 6;
                    $entry['activity_type'] = $activityType;
                }
            }
            $presence[$uname] = $entry;
            if (!write_json_atomic(self::PRESENCE_FILE, $presence, false)) throw new RuntimeException('ثبت وضعیت حضور ممکن نشد.');
        });
    }

    /** Closing one tab never signs out or disconnects another open tab. */
    public static function closePresenceTab(string $username, string $tabId): void
    {
        if (!preg_match('/^[a-f0-9]{32}$/D', $tabId)) return;
        $uname = normalize_username($username);
        with_file_lock('presence', function () use ($uname, $tabId): void {
            $presence = read_json_safe(self::PRESENCE_FILE, []);
            if (!is_array($presence)) return;
            $entry = is_array($presence[$uname] ?? null) ? $presence[$uname] : [];
            $tabs = is_array($entry['tabs'] ?? null) ? $entry['tabs'] : [];
            $hadOpenTab = isset($tabs[$tabId]);
            unset($tabs[$tabId]);
            $entry['tabs'] = $tabs;
            $now = time();
            if ($hadOpenTab) $entry['last_seen'] = max((int)($entry['last_seen'] ?? 0), $now);
            $closed = is_array($entry['closed_tabs'] ?? null) ? $entry['closed_tabs'] : [];
            $closed[$tabId] = $now;
            $entry['closed_tabs'] = $closed;
            unset($entry['typing_chat'], $entry['typing_until'], $entry['activity_type']);
            $presence[$uname] = $entry;
            if (!write_json_atomic(self::PRESENCE_FILE, $presence, false)) throw new RuntimeException('ثبت خروج از صفحه ممکن نشد.');
        });
    }

    public static function getPresenceMap(array $filterUsernames = []): array
    {
        $presence = read_json_safe(self::PRESENCE_FILE, []);
        if (!is_array($presence)) return [];
        $allowSet = !empty($filterUsernames) ? array_flip($filterUsernames) : null;
        $now = time();
        $out = [];
        foreach ($presence as $uname => $info) {
            if ($allowSet !== null && !isset($allowSet[$uname])) continue;
            if (!is_array($info)) continue;
            $lastSeen = (int)($info['last_seen'] ?? 0);
            $isOnline = false;
            if (is_array($info['tabs'] ?? null)) {
                foreach ($info['tabs'] as $seen) {
                    if ($now - (int)$seen <= 35) { $isOnline = true; break; }
                }
            } else { // Pre-upgrade presence data, kept only until it expires.
                $isOnline = $lastSeen > 0 && ($now - $lastSeen) <= 35;
            }
            $typingChat = null;
            $activityType = 'typing';
            if ($isOnline && ($info['typing_until'] ?? 0) >= $now && !empty($info['typing_chat'])) {
                $typingChat = (string)$info['typing_chat'];
                $activityType = (string)($info['activity_type'] ?? 'typing');
            }
            $out[$uname] = [
                'online' => $isOnline,
                'last_seen' => $lastSeen,
                'typing_chat' => $typingChat,
                'activity_type' => $activityType,
            ];
        }
        return $out;
    }

    public static function getPresenceForViewer(string $viewer, array $visibleUsers): array
    {
        $presence = self::getPresenceMap(array_keys($visibleUsers));
        $all = read_json_safe(self::BLOCKS_FILE, []);
        if (!is_array($all)) return $presence;
        foreach ($presence as $peer => $_) {
            if ($peer !== $viewer && (in_array($peer, $all[$viewer] ?? [], true) ||
                in_array($viewer, $all[$peer] ?? [], true))) unset($presence[$peer]);
        }
        return $presence;
    }

    public static function hasLegacyDataForId(string $uname): bool
    {
        if (file_exists(DATA_DIR . '/stickers/' . $uname . '.json') ||
            isset((read_json_safe(self::PRESENCE_FILE, []) ?: [])[$uname]) ||
            file_exists(DRIVE_DIR . '/' . $uname) || file_exists(DRIVE_DIR . '/' . $uname . '_usage.json') ||
            file_exists(DATA_DIR . '/dialog_views/' . $uname . '.json') ||
            file_exists(CHATS_DIR . '/saved_' . $uname) || file_exists(CHATS_DIR . '/ai_' . $uname)) return true;
        foreach (glob(CHATS_DIR . '/dm_*', GLOB_ONLYDIR) ?: [] as $d) {
            $pair = explode('__', substr(basename($d), 3));
            if (count($pair) === 2 && in_array($uname, $pair, true)) return true;
        }
        foreach (glob(DATA_DIR . '/media_registry/*/*.json') ?: [] as $f) {
            $m = read_json_safe($f, []);
            if (($m['owner'] ?? '') === $uname || ($m['avatar_for'] ?? '') === $uname) return true;
        }
        $cursors = read_json_safe(DATA_DIR . '/read_cursors.json', []);
        if (is_array($cursors)) {
            foreach ($cursors as $readers) if (is_array($readers) && isset($readers[$uname])) return true;
        }
        $rooms = read_json_safe(DATA_DIR . '/rooms.json', []);
        if (is_array($rooms)) {
            foreach ($rooms as $r) {
                if (is_array($r) && (in_array($uname, $r['members'] ?? [], true) ||
                    in_array($uname, $r['admins'] ?? [], true) || ($r['created_by'] ?? '') === $uname)) return true;
            }
        }
        foreach (glob(CHATS_DIR . '/*/part_*.json') ?: [] as $f) {
            $list = read_json_safe($f, []);
            if (!is_array($list)) continue;
            foreach ($list as $m) if (($m['sender'] ?? '') === $uname) return true;
        }
        return false;
    }

    /**
     * Save or update a user in data/users.json (hashes new passwords cleanly with Bcrypt)
     */
    public static function saveUserRecord(array $record, bool $isNew = false): array
    {
        // Handle atomic username rename first if requested!
        $origUname = isset($record['original_username']) ? normalize_username((string)$record['original_username']) : '';
        $targetUname = normalize_username((string)($record['username'] ?? ''));

        if ($targetUname === '' || strlen($targetUname) < 3 || strlen($targetUname) > 32 ||
            strpos($targetUname, '__') !== false ||
            !preg_match('/^[a-z0-9][a-z0-9_\.\-]*$/', $targetUname)) {
            return ['ok' => false, 'error' => 'آیدی باید ۳ تا ۳۲ نویسه انگلیسی باشد و با حرف یا عدد شروع شود.'];
        }
        if (!empty($record['password']) && !preg_match('/^\$(2y|2a|argon2)/', (string)$record['password']) &&
            strlen((string)$record['password']) < 8) {
            return ['ok' => false, 'error' => 'رمز عبور باید دست‌کم ۸ کاراکتر باشد.'];
        }
        if (!$isNew && $origUname !== '' && $targetUname !== '' && $origUname !== $targetUname) {
            $ren = self::renameUsername($origUname, $targetUname);
            if (!$ren['ok']) {
                return $ren;
            }
        }

        return with_file_lock('users', function () use ($record, $isNew, $targetUname) {
            $users = self::getAllUsers(true);
            $uname = $targetUname;
            if ($uname === '' || strlen($uname) < 3 || strlen($uname) > 32) {
                return ['ok' => false, 'error' => 'نام کاربری (ID) باید بین ۳ تا ۳۲ کاراکتر انگلیسی/عدد باشد.'];
            }
            if (in_array($uname, ['ai', 'system', 'all', 'root'], true)) {
                return ['ok' => false, 'error' => 'این نام کاربری رزرو شده است.'];
            }
            if ($isNew && isset($users[$uname])) {
                return ['ok' => false, 'code' => 'duplicate_id', 'error' => 'آیدی @' . $uname . ' قبلاً ثبت شده است؛ یک آیدی یکتای دیگر انتخاب کنید.'];
            }
            if (!$isNew && !isset($users[$uname])) {
                return ['ok' => false, 'error' => 'برای ویرایش، کاربر موجودی با این آیدی یافت نشد.'];
            }
            // Deleted IDs are not silently recycled: otherwise a new person
            // could inherit an earlier account's private messages and media.
            if ($isNew && self::hasLegacyDataForId($uname)) {
                return ['ok' => false, 'error' => 'این آیدی پیش از این استفاده شده و دادهٔ قدیمی دارد؛ برای جلوگیری از دسترسی به تاریخچهٔ قبلی، آیدی تازه‌ای انتخاب کنید.'];
            }

            $existing = $users[$uname] ?? [
                'username' => $uname,
                'password' => '',
                'display_name' => $uname,
                'role' => 'user',
                'avatar_color' => self::$palette[count($users) % count(self::$palette)],
                'avatar_url' => '',
                'bio' => '',
                'quota_mb' => 1024,
                'active' => true,
            ];

            if (isset($record['display_name']) && trim((string)$record['display_name']) !== '') {
                $existing['display_name'] = mb_substr(trim((string)$record['display_name']), 0, 50);
            }
            if (isset($record['bio'])) {
                $existing['bio'] = mb_substr(trim((string)$record['bio']), 0, 160);
            }
            if (isset($record['avatar_color']) && preg_match('/^#[0-9a-fA-F]{6}$/', (string)$record['avatar_color'])) {
                $existing['avatar_color'] = (string)$record['avatar_color'];
            }
            if (isset($record['avatar_url'])) {
                $existing['avatar_url'] = (string)$record['avatar_url'];
            }
            if (isset($record['role']) && in_array($record['role'], ['admin', 'user'], true)) {
                $existing['role'] = $record['role'];
            }
            if (isset($record['quota_mb'])) {
                $existing['quota_mb'] = max(50, min(102400, (int)$record['quota_mb']));
            }
            if (isset($record['active'])) {
                $existing['active'] = (bool)$record['active'];
            }
            if (isset($record['password']) && trim((string)$record['password']) !== '') {
                $rawPass = trim((string)$record['password']);
                if (strlen($rawPass) < 8 && strpos($rawPass, '$2y$') !== 0 && strpos($rawPass, '$argon2') !== 0) {
                    return ['ok' => false, 'error' => 'رمز عبور باید حداقل ۸ کاراکتر باشد.'];
                }
                $existing['password'] = self::hashPasswordIfPlain($rawPass);
            } elseif ($isNew && $existing['password'] === '') {
                return ['ok' => false, 'error' => 'رمز عبور برای کاربر جدید الزامی است.'];
            }

            if (!$isNew && $uname === self::getPrimaryAdminId() &&
                ($existing['role'] !== 'admin' || !$existing['active'])) {
                return ['ok' => false, 'error' => 'مدیر اصلیِ ساخته‌شده هنگام نصب باید فعال و مدیر بماند؛ مخاطب دائمی کاربران است.'];
            }
            if (!$isNew && (($users[$uname]['role'] ?? '') === 'admin') &&
                ($existing['role'] !== 'admin' || !$existing['active'])) {
                $otherAdmins = 0;
                foreach ($users as $otherName => $otherRecord) {
                    if ($otherName !== $uname && ($otherRecord['role'] ?? '') === 'admin' && !empty($otherRecord['active'])) $otherAdmins++;
                }
                if ($otherAdmins === 0) {
                    return ['ok' => false, 'error' => 'آخرین مدیر فعال سیستم را نمی‌توان حذف، مسدود یا به کاربر عادی تبدیل کرد. ابتدا مدیر جدید بسازید.'];
                }
            }
            // Reserve a server-generated creation timestamp before persisting the new user.
            // An orphaned marker after a failed user write is ignored and reset on retry.
            if ($isNew) self::recordCreatedAccounts([$uname]);
            unset($existing['password_hashed']);
            $users[$uname] = $existing;

            // Clean internal flags before writing users.json
            $outputList = [];
            foreach ($users as $uItem) {
                unset($uItem['password_hashed']);
                $outputList[] = $uItem;
            }
            if (!write_json_atomic(self::USERS_FILE, $outputList, true)) {
                return ['ok' => false, 'error' => 'خطا در ذخیره‌سازی فایل users.json.'];
            }

            return ['ok' => true, 'user' => self::getUser($uname, false)];
        });
    }

    /**
     * Resolve a direct/saved/AI chat folder after a user ID change.
     */
    private static function renamedChatId(string $cid, string $old, string $new): string
    {
        if ($cid === 'saved_' . $old) return 'saved_' . $new;
        if ($cid === 'ai_' . $old) return 'ai_' . $new;
        if (strpos($cid, 'dm_') === 0) {
            $parts = explode('__', substr($cid, 3));
            if (count($parts) === 2 && in_array($old, $parts, true)) {
                $other = ($parts[0] === $old) ? $parts[1] : $parts[0];
                $pair = [$new, $other];
                sort($pair, SORT_STRING);
                return 'dm_' . $pair[0] . '__' . $pair[1];
            }
        }
        return $cid;
    }

    /**
     * Rename an ID across sessions, contact maps, conversation shards, media
     * references and Personal Drive. Preflight checks prevent data collisions.
     */
    public static function renameUsername(string $oldUsername, string $newUsername): array
    {
        $old = normalize_username($oldUsername);
        $new = normalize_username($newUsername);

        if ($old === '' || $new === '') {
            return ['ok' => false, 'error' => 'نام کاربری نامعتبر است.'];
        }
        if ($old === $new) {
            return ['ok' => true];
        }
        if ($old === self::getPrimaryAdminId()) {
            return ['ok' => false, 'error' => 'آیدی مدیر اصلی پس از نصب ثابت است تا مخاطب دائمی همهٔ کاربران باقی بماند.'];
        }
        if (strlen($new) < 3 || strlen($new) > 32 || strpos($new, '__') !== false || !preg_match('/^[a-z0-9][a-z0-9_\.\-]*$/', $new)) {
            return ['ok' => false, 'error' => 'آیدی جدید باید بین ۳ تا ۳۲ کاراکتر انگلیسی (حروف، اعداد، نقطه یا زیرخط) باشد و با حرف یا عدد شروع شود.'];
        }
        if (in_array($new, ['ai', 'system', 'all', 'root'], true)) {
            return ['ok' => false, 'error' => 'این آیدی رزرو شده و قابل انتخاب نیست.'];
        }

        return with_file_lock('users', function () use ($old, $new) {
            $users = self::getAllUsers(true);
            if (!isset($users[$old])) {
                return ['ok' => false, 'error' => 'کاربر فعلی یافت نشد.'];
            }
            if (isset($users[$new])) {
                return ['ok' => false, 'code' => 'duplicate_id', 'error' => 'آیدی @' . $new . ' قبلاً توسط شخص دیگری انتخاب شده است.'];
            }
            if (self::hasLegacyDataForId($new)) {
                return ['ok' => false, 'error' => 'این آیدی به داده‌های حساب قبلی متصل است؛ ابتدا پاکسازی امن حساب قبلی را کامل کنید.'];
            }

            // Preflight all renames before modifying the user registry.
            $existingDirs = glob(CHATS_DIR . '/*', GLOB_ONLYDIR) ?: [];
            foreach ($existingDirs as $src) {
                $oldCid = basename($src);
                $newCid = self::renamedChatId($oldCid, $old, $new);
                if ($newCid !== $oldCid && file_exists(CHATS_DIR . '/' . $newCid)) {
                    return ['ok' => false, 'error' => 'آیدی جدید با تاریخچه گفتگوی قبلی تداخل دارد. آیدی دیگری را انتخاب کنید.'];
                }
            }
            if (file_exists(DRIVE_DIR . '/' . $new) || file_exists(DRIVE_DIR . '/' . $new . '_usage.json') ||
                file_exists(DATA_DIR . '/dialog_views/' . $new . '.json')) {
                return ['ok' => false, 'error' => 'آیدی جدید با داده‌های شخصی قبلی تداخل دارد. آیدی دیگری را انتخاب کنید.'];
            }

            // 1. Update users.json preserving order
            $newUsersList = [];
            foreach ($users as $uKey => $uVal) {
                unset($uVal['password_hashed']);
                if ($uKey === $old) {
                    $uVal['username'] = $new;
                }
                $newUsersList[] = $uVal;
            }
            if (!write_json_atomic(self::USERS_FILE, $newUsersList, true)) {
                return ['ok' => false, 'error' => 'خطا در بروزرسانی فایل کاربران.'];
            }
            self::renameAccountActivity($old, $new);

            // 2. Update active sessions so user remains logged in
            with_file_lock('sessions', function () use ($old, $new) {
                $sessions = read_json_safe(self::SESSIONS_FILE, []);
                if (is_array($sessions)) {
                    $changed = false;
                    foreach ($sessions as &$s) {
                        if (($s['username'] ?? '') === $old) {
                            $s['username'] = $new;
                            $changed = true;
                        }
                    }
                    unset($s);
                    if ($changed) {
                        write_json_atomic(self::SESSIONS_FILE, $sessions, false);
                    }
                }
            });

            // 3. Update contacts.json
            with_file_lock('contacts', function () use ($old, $new) {
                $contacts = read_json_safe(self::CONTACTS_FILE, []);
                if (is_array($contacts)) {
                    $rebuilt = [];
                    foreach ($contacts as $owner => $list) {
                        $newOwner = ($owner === $old) ? $new : $owner;
                        $newList = [];
                        if (is_array($list)) {
                            foreach ($list as $peer) {
                                $newList[] = ($peer === $old) ? $new : $peer;
                            }
                        }
                        $rebuilt[$newOwner] = array_values(array_unique($newList));
                    }
                    write_json_atomic(self::CONTACTS_FILE, $rebuilt, true);
                }
            });

            // 4. Keep blocks attached to the renamed account, not the old ID.
            with_file_lock('blocks', function () use ($old, $new): void {
                $blocks = read_json_safe(self::BLOCKS_FILE, []);
                if (!is_array($blocks)) throw new RuntimeException('فهرست مسدودی‌ها آسیب دیده است.');
                $updated = [];
                foreach ($blocks as $owner => $list) {
                    $targetOwner = $owner === $old ? $new : $owner;
                    $updated[$targetOwner] = is_array($list)
                        ? array_values(array_unique(array_map(fn($item) => $item === $old ? $new : $item, $list))) : [];
                }
                if ($blocks && !write_json_atomic(self::BLOCKS_FILE, $updated, false)) {
                    throw new RuntimeException('انتقال مسدودی‌ها هنگام تغییر آیدی ناموفق بود.');
                }
            });

            // 5. Update presence.json
            with_file_lock('presence', function () use ($old, $new) {
                $presence = read_json_safe(self::PRESENCE_FILE, []);
                if (is_array($presence) && isset($presence[$old])) {
                    $presence[$new] = $presence[$old];
                    unset($presence[$old]);
                    write_json_atomic(self::PRESENCE_FILE, $presence, false);
                }
            });

            // 5. Update Personal Drive directory & usage file
            $oldDrive = DRIVE_DIR . '/' . $old;
            $newDrive = DRIVE_DIR . '/' . $new;
            if (is_dir($oldDrive) && !@rename($oldDrive, $newDrive)) {
                throw new RuntimeException('جابه‌جایی فضای ابری شخصی هنگام تغییر آیدی ناموفق بود.');
            }
            $oldUsage = DRIVE_DIR . '/' . $old . '_usage.json';
            $newUsage = DRIVE_DIR . '/' . $new . '_usage.json';
            if (file_exists($oldUsage) && !@rename($oldUsage, $newUsage)) {
                throw new RuntimeException('بروزرسانی سهمیه کاربری هنگام تغییر آیدی ناموفق بود.');
            }

            // 6. Update rooms.json (members, admins, created_by)
            $roomsPath = DATA_DIR . '/rooms.json';
            if (file_exists($roomsPath)) {
                with_file_lock('rooms', function () use ($roomsPath, $old, $new) {
                    $rooms = read_json_safe($roomsPath, []);
                    if (is_array($rooms)) {
                        foreach ($rooms as &$r) {
                            if (isset($r['members']) && is_array($r['members'])) {
                                $r['members'] = array_values(array_unique(array_map(fn($m) => $m === $old ? $new : $m, $r['members'])));
                            }
                            if (isset($r['admins']) && is_array($r['admins'])) {
                                $r['admins'] = array_values(array_unique(array_map(fn($m) => $m === $old ? $new : $m, $r['admins'])));
                            }
                            if (($r['created_by'] ?? '') === $old) {
                                $r['created_by'] = $new;
                            }
                        }
                        unset($r);
                        write_json_atomic($roomsPath, $rooms, true);
                    }
                });
            }

            // 7. Rename chat folders (saved_, ai_, dm_) and update sender inside shards!
            $chatDirs = glob(CHATS_DIR . '/*', GLOB_ONLYDIR) ?: [];
            $chatIdRenameMap = [];

            foreach ($chatDirs as $cDir) {
                $cid = basename($cDir);
                $newCid = self::renamedChatId($cid, $old, $new);

                $targetDir = $cDir;
                if ($newCid !== $cid) {
                    $destDir = CHATS_DIR . '/' . $newCid;
                    if (!@rename($cDir, $destDir)) {
                        throw new RuntimeException('جابه‌جایی تاریخچه گفتگو هنگام تغییر آیدی ناموفق بود. دسترسی نوشتن هاست را بررسی و از پشتیبان بازیابی کنید.');
                    }
                    $targetDir = $destDir;
                    $chatIdRenameMap[$cid] = $newCid;
                }

                // Update index.json & shards where sender === $old
                $idxPath = $targetDir . '/index.json';
                $idxData = read_json_safe($idxPath, null);
                if (is_array($idxData)) {
                    $idxChanged = false;
                    if (($idxData['chat_id'] ?? '') !== basename($targetDir)) {
                        $idxData['chat_id'] = basename($targetDir);
                        $idxChanged = true;
                    }
                    if (($idxData['last_message']['sender'] ?? '') === $old) {
                        $idxData['last_message']['sender'] = $new;
                        $idxChanged = true;
                    }
                    if (($idxData['pinned_message']['sender'] ?? '') === $old) {
                        $idxData['pinned_message']['sender'] = $new;
                        $idxChanged = true;
                    }
                    if ($idxChanged) {
                        write_json_atomic($idxPath, $idxData, true);
                    }
                }

                foreach (glob($targetDir . '/part_*.json') ?: [] as $partPath) {
                    $msgs = read_json_safe($partPath, []);
                    if (is_array($msgs)) {
                        $mChanged = false;
                        foreach ($msgs as &$m) {
                            if (($m['sender'] ?? '') === $old) {
                                $m['sender'] = $new;
                                $mChanged = true;
                            }
                            if (in_array($old, $m['hidden_for'] ?? [], true)) {
                                $m['hidden_for'] = array_values(array_map(fn($name) => $name === $old ? $new : $name, $m['hidden_for']));
                                $mChanged = true;
                            }
                            if (($m['media']['owner'] ?? '') === $old) {
                                $m['media']['owner'] = $new;
                                $mChanged = true;
                            }
                            if (($m['reply_to']['sender'] ?? '') === $old) {
                                $m['reply_to']['sender'] = $new;
                                $mChanged = true;
                            }
                            if (($m['chat_id'] ?? '') !== basename($targetDir) && isset($m['chat_id'])) {
                                $m['chat_id'] = basename($targetDir);
                                $mChanged = true;
                            }
                            if (!empty($m['reactions']) && is_array($m['reactions'])) {
                                foreach ($m['reactions'] as $emo => $uArr) {
                                    if (is_array($uArr) && in_array($old, $uArr, true)) {
                                        $m['reactions'][$emo] = array_values(array_unique(array_map(fn($x) => $x === $old ? $new : $x, $uArr)));
                                        $mChanged = true;
                                    }
                                }
                            }
                        }
                        unset($m);
                        if ($mChanged) {
                            write_json_atomic($partPath, $msgs, false);
                        }
                    }
                }
            }

            // 8. Migrate MEDIA metadata (owner, avatars, shared chat & Drive refs).
            // Otherwise renamed users would lose access to all previously uploaded files!
            foreach (glob(DATA_DIR . '/media_registry/*/*.json') ?: [] as $metaPath) {
                $meta = read_json_safe($metaPath, null);
                if (!is_array($meta)) continue;
                $changed = false;
                if (($meta['owner'] ?? '') === $old) {
                    $meta['owner'] = $new;
                    $changed = true;
                }
                if (($meta['avatar_for'] ?? '') === $old) {
                    $meta['avatar_for'] = $new;
                    $changed = true;
                }
                $refs = $meta['references'] ?? [];
                if (is_array($refs)) {
                    $rebuiltRefs = [];
                    foreach ($refs as $refKey => $ref) {
                        if (!is_array($ref)) continue;
                        if (($ref['type'] ?? '') === 'chat') {
                            $oldRefCid = (string)($ref['chat_id'] ?? '');
                            $mappedCid = $chatIdRenameMap[$oldRefCid] ?? $oldRefCid;
                            if ($mappedCid !== $oldRefCid) $changed = true;
                            $ref['chat_id'] = $mappedCid;
                            $seqRef = strrpos((string)$refKey, ':');
                            $seqStr = ($seqRef !== false) ? substr((string)$refKey, $seqRef + 1) : '0';
                            $rebuiltRefs['chat:' . $mappedCid . ':' . $seqStr] = $ref;
                        } elseif (($ref['type'] ?? '') === 'drive') {
                            $driveOwner = (string)($ref['username'] ?? '');
                            if ($driveOwner === $old) {
                                $driveOwner = $new;
                                $changed = true;
                            }
                            $ref['username'] = $driveOwner;
                            $idRef = strrpos((string)$refKey, ':');
                            $itemId = ($idRef !== false) ? substr((string)$refKey, $idRef + 1) : '';
                            $rebuiltRefs['drive:' . $driveOwner . ':' . $itemId] = $ref;
                        } elseif (($ref['type'] ?? '') === 'sticker') {
                            $stickerOwner = (string)($ref['username'] ?? '');
                            if ($stickerOwner === $old) {
                                $stickerOwner = $new;
                                $changed = true;
                            }
                            $ref['username'] = $stickerOwner;
                            $rebuiltRefs['sticker:' . $stickerOwner . ':' . ($meta['id'] ?? '')] = $ref;
                        } else {
                            $rebuiltRefs[$refKey] = $ref;
                        }
                    }
                    if ($changed) $meta['references'] = $rebuiltRefs;
                }
                if ($changed) write_json_atomic($metaPath, $meta, true);
            }

            // Reusable sticker packs and their media references follow an ID rename.
            require_once __DIR__ . '/StickerStore.php';
            StickerStore::renameAccount($old, $new);

            // Update cached owner label in Drive items (metadata is duplicated there).
            foreach (glob(DRIVE_DIR . '/*/drive_part_*.json') ?: [] as $drivePart) {
                $items = read_json_safe($drivePart, []);
                if (!is_array($items)) continue;
                $changed = false;
                foreach ($items as &$it) {
                    if (($it['media']['owner'] ?? '') === $old) {
                        $it['media']['owner'] = $new;
                        $changed = true;
                    }
                }
                unset($it);
                if ($changed) write_json_atomic($drivePart, $items, false);
            }

            // 9. Update read cursors and per-user dialog watermarks/preferences.
            // The latter also remaps hidden DM keys in OTHER users' inboxes.
            DialogView::renameAccount($old, $new);
            $cursorsPath = DATA_DIR . '/read_cursors.json';
            if (file_exists($cursorsPath)) {
                with_file_lock('cursors', function () use ($cursorsPath, $old, $new, $chatIdRenameMap) {
                    $cursors = read_json_safe($cursorsPath, []);
                    if (is_array($cursors)) {
                        $rebuilt = [];
                        foreach ($cursors as $cid => $userSeqs) {
                            $mappedCid = $chatIdRenameMap[$cid] ?? $cid;
                            $rebuilt[$mappedCid] = [];
                            if (is_array($userSeqs)) {
                                foreach ($userSeqs as $u => $sq) {
                                    $rebuilt[$mappedCid][$u === $old ? $new : $u] = $sq;
                                }
                            }
                        }
                        write_json_atomic($cursorsPath, $rebuilt, false);
                    }
                });
            }

            return ['ok' => true, 'new_username' => $new];
        });
    }

    /**
     * Delete user from data/users.json and clean up their sessions
     */
    public static function deleteUserRecord(string $username, string $actingAdmin): array
    {
        return with_file_lock('users', function () use ($username, $actingAdmin) {
            $uname = normalize_username($username);
            if ($uname === $actingAdmin) {
                return ['ok' => false, 'error' => 'نمی‌توانید حساب کاربری خودتان را حذف کنید.'];
            }
            if ($uname === self::getPrimaryAdminId()) {
                return ['ok' => false, 'error' => 'مدیر اصلیِ نصب، مخاطب دائمی کاربران است و حذف نمی‌شود.'];
            }
            $users = self::getAllUsers(true);
            if (!isset($users[$uname])) {
                return ['ok' => false, 'error' => 'کاربر یافت نشد.'];
            }
            unset($users[$uname]);
            $outputList = [];
            foreach ($users as $uItem) {
                unset($uItem['password_hashed']);
                $outputList[] = $uItem;
            }
            if (!write_json_atomic(self::USERS_FILE, $outputList, true)) {
                return ['ok' => false, 'error' => 'حذف حساب در فایل کاربران ثبت نشد.'];
            }
            self::removeAccountActivity([$uname]);

            // Terminate deleted user's sessions
            with_file_lock('sessions', function () use ($uname) {
                $sessions = read_json_safe(self::SESSIONS_FILE, []);
                if (is_array($sessions)) {
                    foreach ($sessions as $t => $s) {
                        if (($s['username'] ?? '') === $uname) unset($sessions[$t]);
                    }
                    write_json_atomic(self::SESSIONS_FILE, $sessions, false);
                }
            });
            with_file_lock('contacts', function () use ($uname) {
                $contacts = read_json_safe(self::CONTACTS_FILE, []);
                if (!is_array($contacts)) return;
                unset($contacts[$uname]);
                foreach ($contacts as &$list) {
                    if (is_array($list)) $list = array_values(array_filter($list, fn($c) => $c !== $uname));
                }
                unset($list);
                write_json_atomic(self::CONTACTS_FILE, $contacts, true);
            });
            self::clearBlocks($uname, true);
            with_file_lock('presence', function () use ($uname) {
                $presence = read_json_safe(self::PRESENCE_FILE, []);
                if (is_array($presence) && isset($presence[$uname])) {
                    unset($presence[$uname]);
                    if (!write_json_atomic(self::PRESENCE_FILE, $presence, false)) throw new RuntimeException('پاکسازی وضعیت حضور حساب حذف‌شده ناموفق بود.');
                }
            });
            with_file_lock('contact_lookups', function () use ($uname) {
                $lookups = read_json_safe(self::LOOKUPS_FILE, []);
                if (is_array($lookups) && isset($lookups[$uname])) {
                    unset($lookups[$uname]);
                    if (!write_json_atomic(self::LOOKUPS_FILE, $lookups, false)) throw new RuntimeException('پاکسازی وضعیت جستجو ناموفق بود.');
                }
            });
            with_file_lock('rooms', function () use ($uname) {
                $rooms = read_json_safe(DATA_DIR . '/rooms.json', []);
                if (!is_array($rooms)) return;
                foreach ($rooms as &$room) {
                    if (!is_array($room)) continue;
                    if (is_array($room['members'] ?? null)) $room['members'] = array_values(array_filter($room['members'], fn($m) => $m !== $uname));
                    if (is_array($room['admins'] ?? null)) $room['admins'] = array_values(array_filter($room['admins'], fn($m) => $m !== $uname));
                    if (($room['created_by'] ?? '') === $uname) $room['created_by'] = ''; // never grant old ownership to a recycled ID
                    if (is_array($room['left_users'] ?? null)) $room['left_users'] = array_values(array_filter($room['left_users'], fn($m) => $m !== $uname));
                }
                unset($room);
                write_json_atomic(DATA_DIR . '/rooms.json', $rooms, true);
            });

            return ['ok' => true];
        });
    }
}
