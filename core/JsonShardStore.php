<?php
declare(strict_types=1);

require_once __DIR__ . '/bootstrap.php';
require_once __DIR__ . '/CryptoEngine.php';
require_once __DIR__ . '/DialogView.php';
require_once __DIR__ . '/Auth.php';
require_once __DIR__ . '/MediaManager.php';

class JsonShardStore
{
    private const ROOMS_FILE = DATA_DIR . '/rooms.json';
    private const CURSORS_FILE = DATA_DIR . '/read_cursors.json';

    public static function sanitizeChatId(string $chatId): string
    {
        return preg_replace('/[^a-zA-Z0-9_\-\.]/', '', $chatId) ?? '';
    }

    public static function makeDirectChatId(string $u1, string $u2): string
    {
        $a = normalize_username($u1);
        $b = normalize_username($u2);
        $pair = [$a, $b];
        sort($pair, SORT_STRING);
        return 'dm_' . $pair[0] . '__' . $pair[1];
    }

    public static function makeSavedChatId(string $username): string
    {
        return 'saved_' . normalize_username($username);
    }

    public static function makeAiChatId(string $username): string
    {
        return 'ai_' . normalize_username($username);
    }

    // ========================================================================
    // Transparent AES-256-GCM helpers for message records & summaries
    // ========================================================================

    private static function encryptMessageForDisk(array $msg): array
    {
        $copy = $msg;
        if (isset($copy['text']) && is_string($copy['text']) && $copy['text'] !== '') {
            $copy['text'] = CryptoEngine::encrypt($copy['text']);
        }
        if (isset($copy['reply_to']['preview']) && is_string($copy['reply_to']['preview']) && $copy['reply_to']['preview'] !== '') {
            $copy['reply_to']['preview'] = CryptoEngine::encrypt($copy['reply_to']['preview']);
        }
        return $copy;
    }

    public static function decryptMessageFromDisk(array $msg): array
    {
        if (isset($msg['text']) && is_string($msg['text']) && $msg['text'] !== '') {
            $msg['text'] = CryptoEngine::decrypt($msg['text']);
        }
        if (isset($msg['reply_to']['preview']) && is_string($msg['reply_to']['preview']) && $msg['reply_to']['preview'] !== '') {
            $msg['reply_to']['preview'] = CryptoEngine::decrypt($msg['reply_to']['preview']);
        }
        return $msg;
    }

    private static function publicMessage(array $message): array
    {
        // Local deletion choices must not reveal other members' privacy state.
        unset($message['hidden_for']);
        return $message;
    }

    private static function encryptSummaryForDisk(?array $sum): ?array
    {
        if ($sum === null) {
            return null;
        }
        $copy = $sum;
        if (isset($copy['preview']) && is_string($copy['preview']) && $copy['preview'] !== '') {
            $copy['preview'] = CryptoEngine::encrypt($copy['preview']);
        }
        return $copy;
    }

    public static function decryptSummaryFromDisk(?array $sum): ?array
    {
        if ($sum === null) {
            return null;
        }
        if (isset($sum['preview']) && is_string($sum['preview']) && $sum['preview'] !== '') {
            $sum['preview'] = CryptoEngine::decrypt($sum['preview']);
        }
        return $sum;
    }

    /**
     * Ensure rooms registry exists (clean default announcement channel)
     */
    public static function ensureDefaultRooms(): array
    {
        $rooms = read_json_safe(self::ROOMS_FILE, null);
        if (is_array($rooms)) {
            // Old installations may have allowed members to leave/hide the
            // mandatory announcement channel. Reconcile once, not per poll.
            $system = $rooms['channel_announcements'] ?? null;
            if (is_array($system) && ($system['members'] ?? null) === ['*'] &&
                empty($system['left_users']) && !empty($system['only_admins_post']) &&
                ($system['created_by'] ?? '') === Auth::getPrimaryAdminId()) return $rooms;
        }

        return with_file_lock('rooms', function () {
            $rooms = read_json_safe(self::ROOMS_FILE, null);
            if (is_array($rooms)) {
                $primary = Auth::getPrimaryAdminId();
                $system = is_array($rooms['channel_announcements'] ?? null) ? $rooms['channel_announcements'] : [];
                $system += [
                    'id' => 'channel_announcements', 'type' => 'channel',
                    'title' => 'اطلاعیه‌های عمومی سیستم', 'description' => 'کانال اطلاع‌رسانی مدیریت',
                    'avatar_color' => '#2AABEE', 'created_at' => time(),
                ];
                $system['type'] = 'channel';
                $system['members'] = ['*'];
                $system['left_users'] = [];
                $system['only_admins_post'] = true;
                $system['admins'] = $primary !== '' ? [$primary] : [];
                $system['created_by'] = $primary;
                $rooms['channel_announcements'] = $system;
                if (!write_json_atomic(self::ROOMS_FILE, $rooms, true)) throw new RuntimeException('بازسازی کانال اطلاعیهٔ عمومی ممکن نشد.');
                return $rooms;
            }
            $now = time();
            $rooms = [
                'channel_announcements' => [
                    'id' => 'channel_announcements',
                    'type' => 'channel',
                    'title' => 'اطلاعیه‌های عمومی سیستم',
                    'description' => 'کانال اطلاع‌رسانی مدیریت (فقط مدیر امکان ارسال پیام دارد)',
                    'avatar_color' => '#2AABEE',
                    'members' => ['*'],
                    'admins' => Auth::getPrimaryAdminId() !== '' ? [Auth::getPrimaryAdminId()] : [],
                    'only_admins_post' => true,
                    'left_users' => [],
                    'created_by' => Auth::getPrimaryAdminId(),
                    'created_at' => $now,
                ],
            ];
            write_json_atomic(self::ROOMS_FILE, $rooms, true);

            // No sample messages or onboarding instructions are shown in the app;
            // all installation guidance lives exclusively in README-FA.md.
            return $rooms;
        });
    }

    public static function canUserAccessChat(string $username, string $chatId, bool $forWrite = false): bool
    {
        $uname = normalize_username($username);
        $cid = self::sanitizeChatId($chatId);
        if ($uname === '' || $cid === '') {
            return false;
        }

        if (strpos($cid, 'saved_') === 0) {
            return $cid === self::makeSavedChatId($uname);
        }

        if (strpos($cid, 'ai_') === 0) {
            return $cid === self::makeAiChatId($uname) && !empty(get_app_config()['ai_bridge']['enabled']);
        }

        if (strpos($cid, 'dm_') === 0) {
            $rest = substr($cid, 3);
            $parts = explode('__', $rest);
            if (count($parts) !== 2) {
                return false;
            }
            if ($parts[0] !== $uname && $parts[1] !== $uname) {
                return false;
            }
            $other = ($parts[0] === $uname) ? $parts[1] : $parts[0];
            $peer = Auth::getUser($other);
            return $peer !== null && !empty($peer['active']) &&
                (!$forWrite || !Auth::eitherUserBlocked($uname, $other));
        }

        $rooms = self::ensureDefaultRooms();
        if (!isset($rooms[$cid])) {
            return false;
        }
        $room = $rooms[$cid];
        if ($cid === 'channel_announcements') {
            if (!$forWrite) return true; // every authenticated account is a member
            return (Auth::getUser($uname)['role'] ?? '') === 'admin';
        }
        $members = $room['members'] ?? [];
        $userObj = Auth::getUser($uname);
        $isSysAdmin = (($userObj['role'] ?? '') === 'admin');
        $hasLeft = in_array($uname, $room['left_users'] ?? [], true);
        $isMember = $isSysAdmin || (!$hasLeft && (in_array('*', $members, true) || in_array($uname, $members, true)));

        if (!$isMember) {
            return false;
        }
        if ($forWrite && !empty($room['only_admins_post'])) {
            return $isSysAdmin || in_array($uname, $room['admins'] ?? [], true);
        }
        return true;
    }

    /**
     * Create a new Group or Channel (Only Admin or authorized users)
     */
    public static function createRoom(string $creator, string $title, string $type, array $members, string $description = '', bool $onlyAdminsPost = false, bool $isPublic = false, string $publicHandle = ''): array
    {
        $title = trim($title);
        if ($title === '') {
            return ['ok' => false, 'error' => 'عنوان گفتگو نمی‌تواند خالی باشد.'];
        }
        $creatorObj = Auth::getUser($creator);
        $isAdmin = (($creatorObj['role'] ?? '') === 'admin');
        $publicHandle = strtolower(ltrim(trim($publicHandle), '@'));
        if ($publicHandle !== '' && !preg_match('/^[a-z][a-z0-9_]{4,31}$/D', $publicHandle)) {
            return ['ok' => false, 'error' => 'آیدی عمومی گروه باید ۵ تا ۳۲ حرف انگلیسی، عدد یا زیرخط باشد.'];
        }
        if (!$isPublic && $publicHandle !== '') {
            return ['ok' => false, 'error' => 'آیدی عمومی فقط برای گروه یا کانال عمومی قابل ثبت است.'];
        }
        if ($publicHandle !== '' && preg_match('/^(group|channel)_[a-f0-9]{10}$/D', $publicHandle)) {
            return ['ok' => false, 'error' => 'این آیدی عمومی مشابه شناسهٔ داخلی گروه است و مجاز نیست.'];
        }

        $roomType = ($type === 'channel') ? 'channel' : 'group';
        $prefix = ($roomType === 'channel') ? 'channel_' : 'group_';
        $roomId = $prefix . substr(bin2hex(random_bytes(5)), 0, 10);

        $cleanMembers = [];
        if ($isAdmin && in_array('*', $members, true)) {
            $cleanMembers = ['*'];
        } else {
            $cleanMembers[] = normalize_username($creator);
            foreach ($members as $m) {
                $mu = normalize_username((string)$m);
                $target = $mu !== '' ? Auth::getUser($mu) : null;
                if ($target !== null && !empty($target['active']) && $mu !== '*' &&
                    ($isAdmin || in_array($mu, Auth::getUserContacts($creator), true))) {
                    $cleanMembers[] = $mu;
                }
            }
            $cleanMembers = array_values(array_unique($cleanMembers));
        }

        $colors = ['#0EA5E9', '#10B981', '#8B5CF6', '#F59E0B', '#EC4899', '#6366F1'];
        $color = $colors[abs(crc32($roomId)) % count($colors)];

        $room = [
            'id' => $roomId,
            'type' => $roomType,
            'title' => mb_substr($title, 0, 60),
            'description' => mb_substr(trim($description), 0, 200),
            'avatar_color' => $color,
            'members' => $cleanMembers,
            'admins' => [normalize_username($creator)],
            'only_admins_post' => $roomType === 'channel' ? true : $onlyAdminsPost,
            'is_public' => $isPublic,
            'public_handle' => $isPublic ? $publicHandle : '',
            'invite_token' => bin2hex(random_bytes(18)),
            'left_users' => [],
            'created_by' => normalize_username($creator),
            'created_at' => time(),
        ];

        self::ensureDefaultRooms();
        $created = with_file_lock('rooms', function () use ($roomId, $room, $publicHandle): array {
            $rooms = read_json_safe(self::ROOMS_FILE, null);
            if (!is_array($rooms)) throw new RuntimeException('فهرست گروه‌ها قابل خواندن نیست.');
            if (isset($rooms[$roomId])) return ['ok' => false, 'error' => 'شناسه تصادفی گروه تکراری شد؛ دوباره تلاش کنید.'];
            if ($publicHandle !== '') {
                foreach ($rooms as $existing) {
                    if (($existing['public_handle'] ?? '') === $publicHandle || ($existing['id'] ?? '') === $publicHandle) {
                        return ['ok' => false, 'code' => 'duplicate_id', 'error' => 'آیدی عمومی @' . $publicHandle . ' قبلاً برای گروه دیگری ثبت شده است.'];
                    }
                }
            }
            $rooms[$roomId] = $room;
            if (!write_json_atomic(self::ROOMS_FILE, $rooms, true)) throw new RuntimeException('ثبت گروه روی هاست ناموفق بود.');
            return ['ok' => true];
        });
        if (!$created['ok']) return $created;

        $creatorName = $creatorObj['display_name'] ?? $creator;
        self::appendMessage($roomId, [
            'sender' => $creator,
            'sender_name' => $creatorName,
            'type' => 'system',
            'text' => ($roomType === 'channel' ? 'کانال «' : 'گروه «') . $room['title'] . '» ایجاد شد.',
        ]);

        return ['ok' => true, 'room' => $room];
    }

    /**
     * Delete a Group/Channel completely (Admin or Room Creator)
     */
    public static function deleteRoom(string $roomId, array $actingUser): array
    {
        $cid = self::sanitizeChatId($roomId);
        if ($cid === 'channel_announcements') {
            return ['ok' => false, 'error' => 'کانال اطلاعیهٔ عمومی، بخش دائمی سامانه است و حذف نمی‌شود.'];
        }
        $rooms = self::ensureDefaultRooms();
        if (!isset($rooms[$cid])) {
            return ['ok' => false, 'error' => 'گروه یا کانال یافت نشد.'];
        }
        $room = $rooms[$cid];
        $isAllowed = (($actingUser['role'] ?? '') === 'admin') || (($room['created_by'] ?? '') === $actingUser['username']);
        if (!$isAllowed) {
            return ['ok' => false, 'error' => 'فقط سازنده یا مدیر سیستم مجاز به حذف کامل این گروه است.'];
        }

        // Hold the chat lock while removing history and the room entry. A
        // sender that passed an earlier ACL check must recheck inside append.
        with_file_lock('chat_' . $cid, function () use ($cid): void {
            self::wipeChatDirectory($cid);
            with_file_lock('rooms', function () use ($cid): void {
                $rooms = read_json_safe(self::ROOMS_FILE, []);
                if (!is_array($rooms)) throw new RuntimeException('فهرست گروه‌ها آسیب دیده است.');
                unset($rooms[$cid]);
                if (!write_json_atomic(self::ROOMS_FILE, $rooms, true)) throw new RuntimeException('ثبت حذف گروه ممکن نشد.');
            });
        });
        with_file_lock('cursors', function () use ($cid): void {
            $cursors = read_json_safe(self::CURSORS_FILE, []);
            if (!is_array($cursors)) return;
            unset($cursors[$cid]);
            if (!write_json_atomic(self::CURSORS_FILE, $cursors, false)) throw new RuntimeException('پاکسازی وضعیت خواندن گروه ممکن نشد.');
        });
        DialogView::removeChatForAll($cid);
        return ['ok' => true];
    }

    /**
     * "me" is an O(1) per-user visibility watermark: the other participant's
     * history and media remain intact. "both" physically removes shared data;
     * only a DM participant, a personal-space owner, or a room manager may do so.
     * Sequence numbers and revision counters never roll backwards on a clear.
     */
    public static function clearChatHistory(string $chatId, array $actingUser, string $scope = 'me'): array
    {
        $cid = self::sanitizeChatId($chatId);
        $uname = (string)$actingUser['username'];
        $isAdmin = (($actingUser['role'] ?? '') === 'admin');
        // An owner must be able to delete AI history even after the admin has
        // disabled the assistant for reading and sending new messages.
        if (!self::canUserAccessChat($uname, $cid, false) && $cid !== self::makeAiChatId($uname)) {
            return ['ok' => false, 'error' => 'دسترسی به این گفتگو مجاز نیست.'];
        }
        if (!in_array($scope, ['me', 'both'], true)) {
            return ['ok' => false, 'error' => 'دامنه حذف نامعتبر است.'];
        }
        $personal = strpos($cid, 'saved_') === 0 || strpos($cid, 'ai_') === 0;
        $room = strpos($cid, 'group_') === 0 || strpos($cid, 'channel_') === 0;
        if ($personal) $scope = 'both';
        if ($scope === 'both' && $room) {
            $rooms = self::ensureDefaultRooms();
            if (!$isAdmin && (($rooms[$cid]['created_by'] ?? '') !== $uname)) {
                return ['ok' => false, 'error' => 'پاکسازی تاریخچه برای تمام اعضا فقط توسط مدیر گروه مجاز است.'];
            }
        }

        return with_file_lock('chat_' . $cid, function () use ($cid, $uname, $scope): array {
            $idx = self::getChatIndex($cid, false);
            $lastSeq = max(0, (int)($idx['next_seq'] ?? 1) - 1);
            if ($scope === 'me') {
                DialogView::clearForMe($uname, $cid, $lastSeq);
                self::markChatRead($uname, $cid, $lastSeq);
                return ['ok' => true, 'scope' => 'me'];
            }
            self::wipeChatDirectory($cid);
            $dir = self::getChatDir($cid);
            if (!is_dir($dir) && !@mkdir($dir, 0755, true)) {
                throw new RuntimeException('ساخت شاخص گفتگو پس از حذف ممکن نشد.');
            }
            $rev = (int)($idx['rev'] ?? 1) + 1;
            $empty = [
                'chat_id' => $cid,
                'next_seq' => $lastSeq + 1,
                'rev' => $rev,
                'reset_at_rev' => $rev,
                'total_messages' => 0,
                'updated_at' => time(),
                'pinned_message' => null,
                'last_message' => null,
                'shards' => [],
            ];
            if (!write_json_atomic($dir . '/index.json', $empty, false)) {
                throw new RuntimeException('شاخص گفتگو پس از پاکسازی قابل ثبت نیست.');
            }
            return ['ok' => true, 'scope' => 'both'];
        });
    }

    /** Hide from my inbox without erasing any message; new messages reappear. */
    public static function hideDialogForMe(string $chatId, string $username, bool $removeContact = false): array
    {
        $cid = self::sanitizeChatId($chatId);
        $primary = Auth::getPrimaryAdminId();
        if ($cid === 'channel_announcements' || ($primary !== '' && $cid === self::makeDirectChatId($username, $primary) && $username !== $primary)) {
            return ['ok' => false, 'error' => 'کانال اطلاعیه و مخاطب مدیر اصلی همیشه در فهرست باقی می‌مانند.'];
        }
        if (!self::canUserAccessChat($username, $cid, false)) {
            return ['ok' => false, 'error' => 'دسترسی به این گفتگو مجاز نیست.'];
        }
        if (strpos($cid, 'saved_') === 0 || strpos($cid, 'ai_') === 0) {
            return ['ok' => false, 'error' => 'بخش شخصی را نمی‌توان از فهرست حذف کرد؛ تاریخچه آن را پاک کنید.'];
        }
        return with_file_lock('chat_' . $cid, function () use ($cid, $username, $removeContact): array {
            $idx = self::getChatIndex($cid, false);
            DialogView::hideDialog($username, $cid, max(0, (int)$idx['next_seq'] - 1));
            if ($removeContact && strpos($cid, 'dm_') === 0) {
                $parts = explode('__', substr($cid, 3));
                $peer = ($parts[0] ?? '') === $username ? ($parts[1] ?? '') : ($parts[0] ?? '');
                if ($peer !== '') Auth::removeContact($username, $peer);
            }
            return ['ok' => true];
        });
    }

    private static function wipeChatDirectory(string $cid): void
    {
        $dir = self::getChatDir($cid);
        if (!is_dir($dir)) {
            return;
        }
        // Detach each message's reference without breaking forwarded copies or Drive entries.
        foreach (glob($dir . '/part_*.json') ?: [] as $f) {
            $messages = read_json_safe($f, null);
            if (!is_array($messages)) {
                throw new RuntimeException('یک قطعه گفتگو آسیب‌دیده است؛ پاکسازی برای جلوگیری از حذف ناخواسته فایل‌ها متوقف شد.');
            }
            if (!@unlink($f)) {
                throw new RuntimeException('حذف قطعه گفتگو از دیسک ممکن نشد.');
            }
            foreach ($messages as $m) {
                if (empty($m['deleted']) && !empty($m['media']['id'])) {
                    MediaManager::removeChatReference((string)$m['media']['id'], $cid, (int)($m['seq'] ?? 0));
                }
            }
        }
        foreach (glob($dir . '/*.json') ?: [] as $f) {
            if (!@unlink($f) && file_exists($f)) {
                throw new RuntimeException('حذف یک قطعه JSON از گفتگو ممکن نشد. دسترسی پوشه را بررسی کنید.');
            }
        }
        if (!@rmdir($dir) && is_dir($dir)) {
            throw new RuntimeException('پاکسازی پوشه گفتگو کامل نشد.');
        }
    }

    /**
     * Admin-only account-deletion cleanup: remove the former user's private
     * Saved/AI spaces and private DM histories. Group history remains for the
     * surviving members. Media is removed only after its final reference.
     */
    public static function purgePrivateDataForDeletedUser(string $username): void
    {
        $uname = normalize_username($username);
        if ($uname === '') return;
        $toWipe = ['saved_' . $uname, 'ai_' . $uname];
        foreach (glob(CHATS_DIR . '/dm_*', GLOB_ONLYDIR) ?: [] as $d) {
            $cid = basename($d);
            $pair = explode('__', substr($cid, 3));
            if (count($pair) === 2 && in_array($uname, $pair, true)) $toWipe[] = $cid;
        }
        foreach ($toWipe as $cid) {
            with_file_lock('chat_' . $cid, function () use ($cid) { self::wipeChatDirectory($cid); });
        }
        with_file_lock('cursors', function () use ($uname, $toWipe) {
            $cursors = read_json_safe(self::CURSORS_FILE, []);
            if (!is_array($cursors)) return;
            foreach ($toWipe as $cid) unset($cursors[$cid]);
            foreach ($cursors as &$readByUser) {
                if (is_array($readByUser)) unset($readByUser[$uname]);
            }
            unset($readByUser);
            if (!write_json_atomic(self::CURSORS_FILE, $cursors, false)) throw new RuntimeException('پاکسازی نشانگرهای خواندن حساب حذف‌شده ناموفق بود.');
        });
    }

    /** Keep surviving group history without letting a newly recycled ID inherit
     * old authorship, reactions, hidden flags or uploaded-file ownership.
     * Admin deletion is intentionally slower than ordinary send/poll paths.
     */
    public static function anonymizeDeletedSender(string $username): int
    {
        $uname = normalize_username($username);
        $total = 0;
        foreach (self::ensureDefaultRooms() as $cid => $_room) {
            $total += with_file_lock('chat_' . $cid, function () use ($cid, $uname): int {
                $dir = self::getChatDir($cid);
                if (!file_exists($dir . '/index.json')) return 0;
                $index = self::getChatIndex($cid, false);
                $changedTotal = 0;
                foreach ($index['shards'] ?? [] as $i => $shard) {
                    $path = $dir . '/' . $shard['file'];
                    $messages = read_json_safe($path, null);
                    if (!is_array($messages)) throw new RuntimeException('تاریخچهٔ گروه مخدوش است؛ حذف حساب کامل نشد.');
                    $changed = 0;
                    foreach ($messages as &$msg) {
                        if (!empty($msg['deleted'])) continue;
                        $before = $msg;
                        if (($msg['sender'] ?? '') === $uname) {
                            $msg['sender'] = '';
                            $msg['sender_name'] = 'حساب حذف‌شده';
                        }
                        if (($msg['media']['owner'] ?? '') === $uname) $msg['media']['owner'] = '__deleted__';
                        if (in_array($uname, $msg['hidden_for'] ?? [], true)) {
                            $msg['hidden_for'] = array_values(array_diff($msg['hidden_for'], [$uname]));
                        }
                        foreach ($msg['reactions'] ?? [] as $emoji => $voters) {
                            if (!is_array($voters) || !in_array($uname, $voters, true)) continue;
                            $msg['reactions'][$emoji] = array_values(array_diff($voters, [$uname]));
                        }
                        if ($msg !== $before) {
                            $msg['rev'] = ((int)($index['rev'] ?? 1)) + 1;
                            $changed++;
                        }
                    }
                    unset($msg);
                    if (!$changed) continue;
                    if (!write_json_atomic($path, $messages, false)) throw new RuntimeException('ثبت پاکسازی نام فرستنده ممکن نشد.');
                    $index['rev'] = ((int)($index['rev'] ?? 1)) + 1;
                    $index['shards'][$i]['max_rev'] = $index['rev'];
                    $index['shards'][$i]['size_bytes'] = (int)@filesize($path);
                    $changedTotal += $changed;
                }
                if (!$changedTotal) return 0;
                foreach (['last_message', 'pinned_message'] as $summaryKey) {
                    $summary = self::decryptSummaryFromDisk($index[$summaryKey] ?? null);
                    if (!is_array($summary)) continue;
                    if (($summary['sender'] ?? '') === $uname) {
                        $summary['sender'] = '';
                        $summary['sender_name'] = 'حساب حذف‌شده';
                        $index[$summaryKey] = self::encryptSummaryForDisk($summary);
                    }
                }
                $index['updated_at'] = time();
                if (!write_json_atomic($dir . '/index.json', $index, true)) throw new RuntimeException('ثبت شاخص پاکسازی گروه ممکن نشد.');
                return $changedTotal;
            });
        }
        return $total;
    }

    /** Reset this account's view across DMs and rooms without scanning messages. */
    public static function resetUserViews(string $username): int
    {
        $uname = normalize_username($username);
        $latest = [];
        foreach (self::ensureDefaultRooms() as $cid => $_room) {
            if (self::canUserAccessChat($uname, $cid, false)) {
                $idx = self::getChatIndex($cid, false);
                $latest[$cid] = max(0, (int)($idx['next_seq'] ?? 1) - 1);
            }
        }
        foreach (Auth::getAllUsers(false) as $peer => $_user) {
            if ($peer === $uname) continue;
            $cid = self::makeDirectChatId($uname, $peer);
            $idx = self::getChatIndex($cid, false);
            $latest[$cid] = max(0, (int)($idx['next_seq'] ?? 1) - 1);
        }
        DialogView::bulkClearForMe($uname, $latest);
        DialogView::reopen($uname, 'channel_announcements');
        $primary = Auth::getPrimaryAdminId();
        if ($primary !== '' && $primary !== $uname) DialogView::reopen($uname, self::makeDirectChatId($uname, $primary));
        return count($latest);
    }

    public static function getChatDir(string $chatId): string
    {
        return CHATS_DIR . '/' . self::sanitizeChatId($chatId);
    }

    public static function getChatIndex(string $chatId, bool $decryptPreviews = true): array
    {
        $cid = self::sanitizeChatId($chatId);
        $dir = self::getChatDir($cid);
        $indexPath = $dir . '/index.json';

        if (file_exists($indexPath)) {
            $idx = read_json_safe($indexPath, null);
            if (is_array($idx) && isset($idx['shards']) && is_array($idx['shards'])) {
                if ($decryptPreviews) {
                    $idx['last_message'] = self::decryptSummaryFromDisk($idx['last_message'] ?? null);
                    $idx['pinned_message'] = self::decryptSummaryFromDisk($idx['pinned_message'] ?? null);
                }
                return $idx;
            }
        }

        if (is_dir($dir)) {
            $partFiles = glob($dir . '/part_*.json');
            if (!empty($partFiles)) {
                $rebuilt = self::rebuildIndex($cid);
                if ($decryptPreviews) {
                    $rebuilt['last_message'] = self::decryptSummaryFromDisk($rebuilt['last_message'] ?? null);
                    $rebuilt['pinned_message'] = self::decryptSummaryFromDisk($rebuilt['pinned_message'] ?? null);
                }
                return $rebuilt;
            }
        }

        return [
            'chat_id' => $cid,
            'next_seq' => 1,
            'rev' => 1,
            'total_messages' => 0,
            'updated_at' => 0,
            'pinned_message' => null,
            'last_message' => null,
            'shards' => [],
        ];
    }

    public static function rebuildIndex(string $chatId): array
    {
        $cid = self::sanitizeChatId($chatId);
        $dir = self::getChatDir($cid);
        if (!is_dir($dir)) {
            @mkdir($dir, 0755, true);
        }
        $partFiles = glob($dir . '/part_*.json') ?: [];
        sort($partFiles, SORT_STRING);

        $shards = [];
        $maxSeq = 0;
        $maxRev = 1;
        $total = 0;
        $lastMsgPlain = null;
        $updatedAt = 0;

        foreach ($partFiles as $fp) {
            $msgs = read_json_safe($fp, []);
            if (!is_array($msgs) || empty($msgs)) {
                continue;
            }
            $sMin = PHP_INT_MAX;
            $sMax = 0;
            $sRev = 1;
            $sCreated = time();
            $sUpdated = 0;
            $activeCount = 0;

            foreach ($msgs as $m) {
                $seq = (int)($m['seq'] ?? 0);
                $rev = (int)($m['rev'] ?? 1);
                $ts = (int)($m['created_at'] ?? time());
                if ($seq < $sMin) $sMin = $seq;
                if ($seq > $sMax) $sMax = $seq;
                if ($rev > $sRev) $sRev = $rev;
                if ($ts > $sUpdated) $sUpdated = $ts;
                if (empty($m['deleted'])) {
                    $activeCount++;
                    $dec = self::decryptMessageFromDisk($m);
                    $lastMsgPlain = self::summarizeMessage($dec);
                }
            }
            if ($sMax > $maxSeq) $maxSeq = $sMax;
            if ($sRev > $maxRev) $maxRev = $sRev;
            if ($sUpdated > $updatedAt) $updatedAt = $sUpdated;
            $total += $activeCount;

            $shards[] = [
                'file' => basename($fp),
                'min_seq' => ($sMin === PHP_INT_MAX) ? 0 : $sMin,
                'max_seq' => $sMax,
                'count' => count($msgs),
                'size_bytes' => (int)@filesize($fp),
                'created_at' => $sCreated,
                'updated_at' => $sUpdated,
                'max_rev' => $sRev,
            ];
        }

        $index = [
            'chat_id' => $cid,
            'next_seq' => $maxSeq + 1,
            'rev' => $maxRev + 1,
            'total_messages' => $total,
            'updated_at' => $updatedAt ?: time(),
            'pinned_message' => null,
            'last_message' => self::encryptSummaryForDisk($lastMsgPlain),
            'shards' => $shards,
        ];
        write_json_atomic($dir . '/index.json', $index, true);
        return $index;
    }

    public static function summarizeMessage(array $msg): array
    {
        $text = trim((string)($msg['text'] ?? ''));
        $mediaType = $msg['media']['category'] ?? '';
        $preview = $text;
        if ($preview === '') {
            switch ($mediaType) {
                case 'image': $preview = '📷 تصویر'; break;
                case 'video': $preview = '🎬 ویدیو'; break;
                case 'voice': $preview = '🎤 پیام صوتی'; break;
                case 'audio': $preview = '🎵 فایل صوتی'; break;
                case 'file':  $preview = '📎 ' . ($msg['media']['original_name'] ?? 'فایل پیوست'); break;
                default:      $preview = 'پیام';
            }
        } elseif ($mediaType !== '') {
            $icon = ($mediaType === 'image') ? '📷 ' : (($mediaType === 'video') ? '🎬 ' : (($mediaType === 'voice') ? '🎤 ' : '📎 '));
            $preview = $icon . $preview;
        }
        return [
            'id' => $msg['id'] ?? '',
            'seq' => (int)($msg['seq'] ?? 0),
            'sender' => $msg['sender'] ?? '',
            'sender_name' => $msg['sender_name'] ?? '',
            'type' => $msg['type'] ?? 'text',
            'media_category' => $mediaType,
            'preview' => mb_substr(preg_replace('/\s+/', ' ', $preview) ?? $preview, 0, 90),
            'created_at' => (int)($msg['created_at'] ?? time()),
        ];
    }

    /**
     * Append new message (encrypts payload on disk with AES-256-GCM, returns plaintext in memory)
     */
    public static function appendMessage(string $chatId, array $payload): array
    {
        $cid = self::sanitizeChatId($chatId);
        return with_file_lock('chat_' . $cid, function () use ($cid, $payload) {
            if ((strpos($cid, 'group_') === 0 || strpos($cid, 'channel_') === 0 || strpos($cid, 'dm_') === 0) &&
                !self::canUserAccessChat((string)($payload['sender'] ?? ''), $cid, true)) {
                throw new RuntimeException('گفتگو حذف شده، مخاطب مسدود شده یا اجازهٔ ارسال پیام ندارید.');
            }
            $dir = self::getChatDir($cid);
            if (!is_dir($dir)) {
                @mkdir($dir, 0755, true);
            }

            $cfg = get_app_config();
            $maxShardBytes = max(16384, (int)($cfg['max_shard_bytes'] ?? 204800));
            $maxShardMsgs  = max(10, (int)($cfg['max_shard_messages'] ?? 150));

            $index = self::getChatIndex($cid, false);
            $seq = (int)($index['next_seq'] ?? 1);
            $rev = (int)($index['rev'] ?? 1) + 1;
            $now = time();

            $msgPlain = [
                'id' => 'm_' . $seq . '_' . substr(bin2hex(random_bytes(4)), 0, 6),
                'seq' => $seq,
                'rev' => $rev,
                'chat_id' => $cid,
                'sender' => (string)($payload['sender'] ?? ''),
                'sender_name' => (string)($payload['sender_name'] ?? ''),
                'type' => (string)($payload['type'] ?? 'text'),
                'text' => (string)($payload['text'] ?? ''),
                'media' => $payload['media'] ?? null,
                'reply_to' => $payload['reply_to'] ?? null,
                'forwarded_from' => $payload['forwarded_from'] ?? null,
                'reactions' => [],
                'edited_at' => null,
                'created_at' => $now,
            ];

            $msgDisk = self::encryptMessageForDisk($msgPlain);

            $shards = $index['shards'] ?? [];
            $needNewShard = false;

            if (empty($shards)) {
                $needNewShard = true;
                $nextPartNum = 1;
            } else {
                $lastIdx = count($shards) - 1;
                $lastShard = $shards[$lastIdx];
                if (($lastShard['size_bytes'] ?? 0) >= $maxShardBytes || ($lastShard['count'] ?? 0) >= $maxShardMsgs) {
                    $needNewShard = true;
                    if (preg_match('/part_(\d+)\.json$/', (string)$lastShard['file'], $m)) {
                        $nextPartNum = ((int)$m[1]) + 1;
                    } else {
                        $nextPartNum = count($shards) + 1;
                    }
                } else {
                    $nextPartNum = 0;
                }
            }

            if ($needNewShard) {
                $fileName = sprintf('part_%04d.json', $nextPartNum);
                $shardPath = $dir . '/' . $fileName;
                if (!write_json_atomic($shardPath, [$msgDisk], false)) {
                    throw new RuntimeException('ذخیره پیام در قطعه JSON با خطا مواجه شد.');
                }
                $newSize = (int)@filesize($shardPath);

                $shards[] = [
                    'file' => $fileName,
                    'min_seq' => $seq,
                    'max_seq' => $seq,
                    'count' => 1,
                    'size_bytes' => $newSize,
                    'created_at' => $now,
                    'updated_at' => $now,
                    'max_rev' => $rev,
                ];
            } else {
                $lastIdx = count($shards) - 1;
                $fileName = $shards[$lastIdx]['file'];
                $shardPath = $dir . '/' . $fileName;
                $messagesInShard = read_json_safe($shardPath, null);
                if (!is_array($messagesInShard)) {
                    throw new RuntimeException('قطعهٔ پیام‌های JSON آسیب دیده است؛ برای جلوگیری از حذف پیام‌های قبلی، نوشتن متوقف شد. فایل را از نسخهٔ پشتیبان بازیابی کنید.');
                }
                $messagesInShard[] = $msgDisk;
                if (!write_json_atomic($shardPath, $messagesInShard, false)) {
                    throw new RuntimeException('ذخیره قطعه JSON پیام‌ها با خطا مواجه شد.');
                }
                $newSize = (int)@filesize($shardPath);

                $shards[$lastIdx]['max_seq'] = $seq;
                $shards[$lastIdx]['count'] = count($messagesInShard);
                $shards[$lastIdx]['size_bytes'] = $newSize;
                $shards[$lastIdx]['updated_at'] = $now;
                $shards[$lastIdx]['max_rev'] = $rev;
            }

            $index['next_seq'] = $seq + 1;
            $index['rev'] = $rev;
            $index['total_messages'] = ((int)($index['total_messages'] ?? 0)) + 1;
            $index['updated_at'] = $now;
            $index['last_message'] = self::encryptSummaryForDisk(self::summarizeMessage($msgPlain));
            $index['shards'] = $shards;

            if (!write_json_atomic($dir . '/index.json', $index, true)) {
                throw new RuntimeException('به‌روزرسانی فهرست گفتگو روی هاست ممکن نشد.');
            }
            if (!empty($msgPlain['media']['id'])) {
                MediaManager::addChatReference((string)$msgPlain['media']['id'], $cid, $seq);
            }

            if ($msgPlain['sender'] !== '' && $msgPlain['sender'] !== 'ai') {
                self::markChatRead($msgPlain['sender'], $cid, $seq);
            }

            return $msgPlain;
        });
    }

    public static function getMessages(string $chatId, ?int $beforeSeq = null, int $limit = 50, ?string $viewer = null): array
    {
        $cid = self::sanitizeChatId($chatId);
        $dir = self::getChatDir($cid);
        $index = self::getChatIndex($cid, true);
        $shards = $index['shards'] ?? [];
        $limit = max(1, min(150, $limit));
        $view = $viewer !== null ? DialogView::state($viewer, $cid) : [];
        $cleared = (int)($view['cleared_seq'] ?? 0);

        $collected = [];
        $hasMore = false;
        for ($i = count($shards) - 1; $i >= 0; $i--) {
            $sh = $shards[$i];
            if ((int)($sh['max_seq'] ?? 0) <= $cleared) break;
            if ($beforeSeq !== null && ($sh['min_seq'] ?? 0) >= $beforeSeq) continue;
            $msgs = read_json_safe($dir . '/' . $sh['file'], null);
            if (!is_array($msgs)) throw new RuntimeException('یک قطعه گفتگو قابل خواندن نیست؛ آن را از نسخه پشتیبان بازیابی کنید.');
            for ($j = count($msgs) - 1; $j >= 0; $j--) {
                $m = $msgs[$j];
                $seq = (int)($m['seq'] ?? 0);
                if ($beforeSeq !== null && $seq >= $beforeSeq) continue;
                if ($seq <= $cleared) break 2;
                if (!empty($m['deleted']) || ($viewer !== null && in_array($viewer, $m['hidden_for'] ?? [], true))) continue;
                if (count($collected) >= $limit) { $hasMore = true; break 2; }
                $collected[] = self::publicMessage(self::decryptMessageFromDisk($m));
            }
        }
        $collected = array_reverse($collected);
        $pinned = $index['pinned_message'] ?? null;
        if (($pinned['seq'] ?? 0) <= $cleared ||
            ($viewer !== null && (int)($pinned['seq'] ?? 0) > 0 && self::getMessageBySeq($cid, (int)$pinned['seq'], $viewer) === null)) {
            $pinned = null;
        }
        return [
            'chat_id' => $cid,
            'messages' => $collected,
            'has_more' => $hasMore,
            'oldest_seq' => $collected ? (int)$collected[0]['seq'] : null,
            'latest_seq' => max(0, (int)($index['next_seq'] ?? 1) - 1),
            'rev' => (int)($index['rev'] ?? 1),
            'view_rev' => (int)($view['view_rev'] ?? 0),
            'pinned_message' => $pinned,
            'shard_info' => self::shardInfo($shards, $index),
        ];
    }

    private static function shardInfo(array $shards, array $index): array
    {
        $last = $shards ? $shards[count($shards) - 1] : [];
        return [
            'shard_count' => count($shards),
            'active_shard' => $last['file'] ?? 'part_0001.json',
            'active_shard_bytes' => (int)($last['size_bytes'] ?? 0),
            'total_messages' => (int)($index['total_messages'] ?? 0),
        ];
    }

    public static function getMessageBySeq(string $chatId, int $seq, ?string $viewer = null): ?array
    {
        $cid = self::sanitizeChatId($chatId);
        if ($seq <= 0 || ($viewer !== null && $seq <= (int)(DialogView::state($viewer, $cid)['cleared_seq'] ?? 0))) return null;
        $dir = self::getChatDir($cid);
        $index = self::getChatIndex($cid, false);
        foreach ($index['shards'] ?? [] as $sh) {
            if ($seq < ($sh['min_seq'] ?? 0) || $seq > ($sh['max_seq'] ?? 0)) continue;
            $msgs = read_json_safe($dir . '/' . $sh['file'], null);
            if (!is_array($msgs)) throw new RuntimeException('قطعه گفتگو قابل خواندن نیست.');
            foreach ($msgs as $m) {
                if ((int)($m['seq'] ?? 0) === $seq && empty($m['deleted']) &&
                    ($viewer === null || !in_array($viewer, $m['hidden_for'] ?? [], true))) {
                    return self::publicMessage(self::decryptMessageFromDisk($m));
                }
            }
            return null;
        }
        return null;
    }

    /** Send at most 120 changed messages; a stale client reloads the latest page. */
    public static function getChangesSince(string $chatId, int $sinceRev, ?string $viewer = null, int $sinceViewRev = 0): array
    {
        $cid = self::sanitizeChatId($chatId);
        $index = self::getChatIndex($cid, true);
        $currentRev = (int)($index['rev'] ?? 1);
        $view = $viewer !== null ? DialogView::state($viewer, $cid) : [];
        $viewRev = (int)($view['view_rev'] ?? 0);
        $reset = ($viewer !== null && $viewRev !== $sinceViewRev)
            || ($sinceRev < (int)($index['reset_at_rev'] ?? 0))
            || $sinceRev > $currentRev
            || ($currentRev - $sinceRev > 180);
        if ($reset) return ['changed' => true, 'reset_required' => true, 'rev' => $currentRev, 'view_rev' => $viewRev];
        if ($currentRev <= $sinceRev) {
            return ['changed' => false, 'rev' => $currentRev, 'view_rev' => $viewRev,
                'pinned_message' => $index['pinned_message'] ?? null];
        }

        $dir = self::getChatDir($cid);
        $upserted = [];
        $deletedSeqs = [];
        $cleared = (int)($view['cleared_seq'] ?? 0);
        foreach ($index['shards'] ?? [] as $sh) {
            if ((int)($sh['max_seq'] ?? 0) <= $cleared || (int)($sh['max_rev'] ?? 0) <= $sinceRev) continue;
            $msgs = read_json_safe($dir . '/' . $sh['file'], null);
            if (!is_array($msgs)) throw new RuntimeException('قطعه گفتگو قابل خواندن نیست.');
            foreach ($msgs as $m) {
                if ((int)($m['rev'] ?? 0) <= $sinceRev || (int)($m['seq'] ?? 0) <= $cleared) continue;
                if (!empty($m['deleted']) || ($viewer !== null && in_array($viewer, $m['hidden_for'] ?? [], true))) {
                    $deletedSeqs[] = (int)$m['seq'];
                } else {
                    $upserted[] = self::publicMessage(self::decryptMessageFromDisk($m));
                }
                if (count($upserted) + count($deletedSeqs) > 120) {
                    return ['changed' => true, 'reset_required' => true, 'rev' => $currentRev, 'view_rev' => $viewRev];
                }
            }
        }
        $pinned = $index['pinned_message'] ?? null;
        if (($pinned['seq'] ?? 0) <= $cleared ||
            ($viewer !== null && (int)($pinned['seq'] ?? 0) > 0 && self::getMessageBySeq($cid, (int)$pinned['seq'], $viewer) === null)) {
            $pinned = null;
        }
        return [
            'changed' => true,
            'rev' => $currentRev,
            'view_rev' => $viewRev,
            'upserted' => $upserted,
            'deleted_seqs' => $deletedSeqs,
            'pinned_message' => $pinned,
            'shard_info' => self::shardInfo($index['shards'] ?? [], $index),
        ];
    }

    public static function editMessage(string $chatId, int $seq, string $newText, string $actingUser, bool $isAdmin = false): array
    {
        $cid = self::sanitizeChatId($chatId);
        $newText = trim($newText);
        if ($newText === '') {
            return ['ok' => false, 'error' => 'متن پیام نمی‌تواند خالی باشد.'];
        }
        if (mb_strlen($newText) > 8000) {
            return ['ok' => false, 'error' => 'متن ویرایش‌شده نباید بیش از ۸۰۰۰ نویسه باشد.'];
        }

        return with_file_lock('chat_' . $cid, function () use ($cid, $seq, $newText, $actingUser, $isAdmin) {
            $dir = self::getChatDir($cid);
            $index = self::getChatIndex($cid, false);
            $shards = $index['shards'] ?? [];

            foreach ($shards as $sIdx => $sh) {
                if ($seq < ($sh['min_seq'] ?? 0) || $seq > ($sh['max_seq'] ?? 0)) {
                    continue;
                }
                $shardPath = $dir . '/' . $sh['file'];
                $msgs = read_json_safe($shardPath, []);
                foreach ($msgs as $mIdx => $m) {
                    if ((int)($m['seq'] ?? 0) === $seq && empty($m['deleted'])) {
                        if ($m['sender'] !== $actingUser && !$isAdmin) {
                            return ['ok' => false, 'error' => 'شما فقط می‌توانید پیام‌های خودتان را ویرایش کنید.'];
                        }
                        $rev = ((int)($index['rev'] ?? 1)) + 1;
                        $now = time();

                        $plainMsg = self::decryptMessageFromDisk($m);
                        $plainMsg['text'] = $newText;
                        $plainMsg['edited_at'] = $now;
                        $plainMsg['rev'] = $rev;

                        $msgs[$mIdx] = self::encryptMessageForDisk($plainMsg);

                        write_json_atomic($shardPath, $msgs, false);
                        $shards[$sIdx]['size_bytes'] = (int)@filesize($shardPath);
                        $shards[$sIdx]['updated_at'] = $now;
                        $shards[$sIdx]['max_rev'] = $rev;

                        $index['rev'] = $rev;
                        $index['updated_at'] = $now;
                        $index['shards'] = $shards;
                        if (($index['last_message']['seq'] ?? 0) === $seq) {
                            $index['last_message'] = self::encryptSummaryForDisk(self::summarizeMessage($plainMsg));
                        }
                        if (($index['pinned_message']['seq'] ?? 0) === $seq) {
                            $index['pinned_message'] = self::encryptSummaryForDisk(self::summarizeMessage($plainMsg));
                        }
                        write_json_atomic($dir . '/index.json', $index, true);
                        return ['ok' => true, 'message' => self::publicMessage($plainMsg)];
                    }
                }
            }
            return ['ok' => false, 'error' => 'پیام مورد نظر یافت نشد.'];
        });
    }

    /** A DM participant can remove a message only for themselves, or for both. */
    public static function deleteMessage(string $chatId, int $seq, string $actingUser, bool $isAdmin = false, string $scope = 'both'): array
    {
        $cid = self::sanitizeChatId($chatId);
        if (!in_array($scope, ['me', 'both'], true)) return ['ok' => false, 'error' => 'دامنه حذف نامعتبر است.'];
        return with_file_lock('chat_' . $cid, function () use ($cid, $seq, $actingUser, $isAdmin, $scope): array {
            $dir = self::getChatDir($cid);
            $index = self::getChatIndex($cid, false);
            $shards = $index['shards'] ?? [];
            $personal = (strpos($cid, 'saved_') === 0 || strpos($cid, 'ai_') === 0);
            $dm = strpos($cid, 'dm_') === 0;
            foreach ($shards as $sIdx => $sh) {
                if ($seq < ($sh['min_seq'] ?? 0) || $seq > ($sh['max_seq'] ?? 0)) continue;
                $shardPath = $dir . '/' . $sh['file'];
                $msgs = read_json_safe($shardPath, null);
                if (!is_array($msgs)) throw new RuntimeException('قطعه پیام‌ها مخدوش است؛ حذف متوقف شد.');
                foreach ($msgs as $mIdx => $m) {
                    if ((int)($m['seq'] ?? 0) !== $seq || !empty($m['deleted'])) continue;
                    if ($scope === 'both' && !$personal && !$dm && $m['sender'] !== $actingUser && !$isAdmin) {
                        return ['ok' => false, 'error' => 'در گروه فقط پیام خودتان را برای همه می‌توانید پاک کنید.'];
                    }
                    if ($scope === 'me' && in_array($actingUser, $m['hidden_for'] ?? [], true)) {
                        return ['ok' => true, 'scope' => 'me'];
                    }
                    $rev = ((int)($index['rev'] ?? 1)) + 1;
                    $now = time();
                    $media = $m['media'] ?? null;
                    if ($scope === 'me' && !$personal) {
                        $hidden = is_array($m['hidden_for'] ?? null) ? $m['hidden_for'] : [];
                        $hidden[] = $actingUser;
                        $msgs[$mIdx]['hidden_for'] = array_values(array_unique($hidden));
                        $msgs[$mIdx]['rev'] = $rev;
                    } else {
                        $scope = 'both';
                        $msgs[$mIdx] = ['id' => $m['id'], 'seq' => $seq, 'rev' => $rev,
                            'deleted' => true, 'deleted_at' => $now];
                    }
                    if (!write_json_atomic($shardPath, $msgs, false)) throw new RuntimeException('حذف پیام در JSON قابل ذخیره نیست.');
                    $shards[$sIdx]['size_bytes'] = (int)@filesize($shardPath);
                    $shards[$sIdx]['updated_at'] = $now;
                    $shards[$sIdx]['max_rev'] = $rev;
                    $index['rev'] = $rev;
                    if ($scope === 'both') $index['total_messages'] = max(0, ((int)($index['total_messages'] ?? 1)) - 1);
                    $index['updated_at'] = $now;
                    $index['shards'] = $shards;
                    if ($scope === 'both') {
                        if (($index['pinned_message']['seq'] ?? 0) === $seq) $index['pinned_message'] = null;
                        if (($index['last_message']['seq'] ?? 0) === $seq) {
                            $index['last_message'] = self::encryptSummaryForDisk(self::findLatestActiveMessageSummary($dir, $shards));
                        }
                    }
                    if (!write_json_atomic($dir . '/index.json', $index, true)) throw new RuntimeException('ثبت شاخص حذف پیام ممکن نشد.');
                    if ($scope === 'me') {
                        if (($index['last_message']['seq'] ?? 0) === $seq) DialogView::noteHiddenMessage($actingUser, $cid, $seq);
                    } elseif (!empty($media['id'])) {
                        MediaManager::removeChatReference((string)$media['id'], $cid, $seq);
                    }
                    return ['ok' => true, 'scope' => $scope];
                }
                return ['ok' => false, 'error' => 'پیام یافت نشد.'];
            }
            return ['ok' => false, 'error' => 'پیام یافت نشد.'];
        });
    }

    private static function findLatestActiveMessageSummary(string $dir, array $shards): ?array
    {
        for ($i = count($shards) - 1; $i >= 0; $i--) {
            $msgs = read_json_safe($dir . '/' . $shards[$i]['file'], []);
            for ($j = count($msgs) - 1; $j >= 0; $j--) {
                if (empty($msgs[$j]['deleted'])) {
                    return self::summarizeMessage(self::decryptMessageFromDisk($msgs[$j]));
                }
            }
        }
        return null;
    }

    public static function toggleReaction(string $chatId, int $seq, string $emoji, string $username): array
    {
        $cid = self::sanitizeChatId($chatId);
        $allowedEmojis = ['👍', '❤️', '😂', '🙏', '🔥', '👏', '😮', '😢', '✅'];
        if (!in_array($emoji, $allowedEmojis, true)) {
            $emoji = '❤️';
        }

        return with_file_lock('chat_' . $cid, function () use ($cid, $seq, $emoji, $username) {
            $dir = self::getChatDir($cid);
            $index = self::getChatIndex($cid, false);
            $shards = $index['shards'] ?? [];

            foreach ($shards as $sIdx => $sh) {
                if ($seq < ($sh['min_seq'] ?? 0) || $seq > ($sh['max_seq'] ?? 0)) {
                    continue;
                }
                $shardPath = $dir . '/' . $sh['file'];
                $msgs = read_json_safe($shardPath, []);
                foreach ($msgs as $mIdx => $m) {
                    if ((int)($m['seq'] ?? 0) === $seq && empty($m['deleted'])) {
                        $reactions = $m['reactions'] ?? [];
                        if (!is_array($reactions)) {
                            $reactions = [];
                        }
                        $usersForEmoji = $reactions[$emoji] ?? [];
                        if (in_array($username, $usersForEmoji, true)) {
                            $usersForEmoji = array_values(array_diff($usersForEmoji, [$username]));
                        } else {
                            $usersForEmoji[] = $username;
                        }
                        if (empty($usersForEmoji)) {
                            unset($reactions[$emoji]);
                        } else {
                            $reactions[$emoji] = $usersForEmoji;
                        }

                        $rev = ((int)($index['rev'] ?? 1)) + 1;
                        $now = time();
                        $msgs[$mIdx]['reactions'] = $reactions;
                        $msgs[$mIdx]['rev'] = $rev;

                        write_json_atomic($shardPath, $msgs, false);
                        $shards[$sIdx]['size_bytes'] = (int)@filesize($shardPath);
                        $shards[$sIdx]['updated_at'] = $now;
                        $shards[$sIdx]['max_rev'] = $rev;

                        $index['rev'] = $rev;
                        $index['updated_at'] = $now;
                        $index['shards'] = $shards;
                        write_json_atomic($dir . '/index.json', $index, true);
                        return ['ok' => true, 'message' => self::publicMessage(self::decryptMessageFromDisk($msgs[$mIdx]))];
                    }
                }
            }
            return ['ok' => false, 'error' => 'پیام یافت نشد.'];
        });
    }

    public static function pinMessage(string $chatId, ?int $seq): array
    {
        $cid = self::sanitizeChatId($chatId);
        return with_file_lock('chat_' . $cid, function () use ($cid, $seq) {
            $dir = self::getChatDir($cid);
            $index = self::getChatIndex($cid, false);
            $plainPin = null;
            if ($seq === null || $seq <= 0) {
                $index['pinned_message'] = null;
            } else {
                $target = self::getMessageBySeq($cid, $seq);
                if ($target === null) {
                    return ['ok' => false, 'error' => 'پیام برای سنجاق یافت نشد.'];
                }
                $plainPin = self::summarizeMessage($target);
                $index['pinned_message'] = self::encryptSummaryForDisk($plainPin);
            }
            $index['rev'] = ((int)($index['rev'] ?? 1)) + 1;
            $index['updated_at'] = time();
            write_json_atomic($dir . '/index.json', $index, true);
            return ['ok' => true, 'pinned_message' => $plainPin];
        });
    }

    /** Bounded, cursor-based search; at most 12 shard files per request. */
    public static function searchMessages(string $chatId, string $query = '', string $mediaCategory = '',
        int $limit = 60, ?string $viewer = null, ?int $beforeSeq = null): array
    {
        $cid = self::sanitizeChatId($chatId);
        $dir = self::getChatDir($cid);
        $index = self::getChatIndex($cid, false);
        $shards = $index['shards'] ?? [];
        $cleared = $viewer !== null ? (int)(DialogView::state($viewer, $cid)['cleared_seq'] ?? 0) : 0;
        $q = mb_strtolower(trim($query));
        $results = [];
        $scanned = 0;
        $lastMin = null;
        $hasMore = false;
        $cursor = null;
        $limit = max(1, min(80, $limit));

        for ($i = count($shards) - 1; $i >= 0; $i--) {
            $sh = $shards[$i];
            if ((int)($sh['max_seq'] ?? 0) <= $cleared) break;
            if ($beforeSeq !== null && (int)($sh['min_seq'] ?? 0) >= $beforeSeq) continue;
            if ($scanned++ >= 12) {
                $hasMore = true;
                $cursor = $lastMin;
                break;
            }
            $lastMin = (int)($sh['min_seq'] ?? 0);
            $msgs = read_json_safe($dir . '/' . $sh['file'], null);
            if (!is_array($msgs)) throw new RuntimeException('قطعه گفتگو برای جستجو قابل خواندن نیست.');
            for ($j = count($msgs) - 1; $j >= 0; $j--) {
                $rawM = $msgs[$j];
                $seq = (int)($rawM['seq'] ?? 0);
                if ($beforeSeq !== null && $seq >= $beforeSeq) continue;
                if ($seq <= $cleared) break 2;
                if (!empty($rawM['deleted']) || ($viewer !== null && in_array($viewer, $rawM['hidden_for'] ?? [], true))) continue;
                if ($mediaCategory !== '' && $mediaCategory !== 'link' &&
                    ($rawM['media']['category'] ?? '') !== $mediaCategory) continue;
                $m = self::decryptMessageFromDisk($rawM);
                if ($mediaCategory === 'link' && !preg_match('/https?:\/\/\S+/i', (string)($m['text'] ?? ''))) continue;
                if ($q !== '') {
                    $haystack = mb_strtolower(($m['text'] ?? '') . ' ' . ($m['media']['original_name'] ?? '') . ' ' . ($m['sender_name'] ?? ''));
                    if (mb_strpos($haystack, $q) === false) continue;
                }
                $results[] = self::publicMessage($m);
                if (count($results) >= $limit) {
                    $hasMore = ($j > 0 || $i > 0) && $seq > $cleared + 1;
                    $cursor = $seq;
                    break 2;
                }
            }
        }
        return ['results' => $results, 'has_more' => $hasMore, 'next_cursor' => $hasMore ? $cursor : null];
    }

    public static function markChatRead(string $username, string $chatId, int $seq): void
    {
        $uname = normalize_username($username);
        $cid = self::sanitizeChatId($chatId);
        if ($uname === '' || $cid === '' || $seq <= 0) {
            return;
        }
        with_file_lock('cursors', function () use ($uname, $cid, $seq) {
            $cursors = read_json_safe(self::CURSORS_FILE, []);
            if (!is_array($cursors)) {
                $cursors = [];
            }
            $prev = (int)($cursors[$cid][$uname] ?? 0);
            if ($seq > $prev) {
                $cursors[$cid][$uname] = $seq;
                write_json_atomic(self::CURSORS_FILE, $cursors, false);
            }
        });
    }

    public static function getReadCursors(): array
    {
        $c = read_json_safe(self::CURSORS_FILE, []);
        return is_array($c) ? $c : [];
    }

    /**
     * Build sidebar dialog list enforcing privacy:
     * - Admin sees ALL users automatically!
     * - Regular user ONLY sees contacts added by exact ID + peers with active DM history!
     */
    public static function getUserDialogList(string $username, ?array &$outKnownPeers = null): array
    {
        $uname = normalize_username($username);
        $viewerObj = Auth::getUser($uname, false);
        $isAdmin = (($viewerObj['role'] ?? '') === 'admin');

        $allUsers = Auth::getAllUsers(false);
        $presence = Auth::getPresenceMap();
        $rooms = self::ensureDefaultRooms();
        $cursors = self::getReadCursors();
        $views = DialogView::all($uname);
        $cfg = get_app_config();
        $primaryAdmin = Auth::getPrimaryAdminId();
        $blockedByViewer = Auth::getBlockedUsers($uname);
        $allBlocks = read_json_safe(DATA_DIR . '/blocks.json', []);
        if (!is_array($allBlocks)) $allBlocks = [];

        $knownPeers = [];
        $dialogs = [];

        // 1. Pinned #1: Saved Messages (فضای خصوصی و پیام‌های ذخیره‌شده)
        $savedId = self::makeSavedChatId($uname);
        $savedIdx = self::getChatIndex($savedId, true);
        $dialogs[] = [
            'id' => $savedId,
            'type' => 'saved',
            'title' => 'پیام‌های ذخیره‌شده (فضای شخصی)',
            'subtitle' => 'یادداشت‌ها و فایل‌های رمزنگاری‌شده شما',
            'avatar_color' => '#2AABEE',
            'pinned_rank' => 100,
            'online' => true,
            'can_delete_history' => true,
            'last_message' => $savedIdx['last_message'] ?? [
                'preview' => 'فضای خصوصی شما با رمزنگاری AES-256',
                'created_at' => 0,
            ],
            'updated_at' => (int)($savedIdx['updated_at'] ?? 0),
            'latest_seq' => max(0, ((int)($savedIdx['next_seq'] ?? 1)) - 1),
            'unread_count' => 0,
            'max_other_read_seq' => max(0, ((int)($savedIdx['next_seq'] ?? 1)) - 1),
            'rev' => (int)($savedIdx['rev'] ?? 1),
        ];

        // 2. Pinned #2: AI & Customizable Web Assistant (if enabled)
        if (!empty($cfg['ai_bridge']['enabled'])) {
            $aiId = self::makeAiChatId($uname);
            $aiIdx = self::getChatIndex($aiId, true);
            $dialogs[] = [
                'id' => $aiId,
                'type' => 'ai',
                'title' => 'دستیار هوشمند و ابزارهای وب',
                'subtitle' => 'هوش مصنوعی، ویکی‌پدیا و جستجوگر سرور',
                'avatar_color' => '#8B5CF6',
                'pinned_rank' => 90,
                'online' => true,
                'can_delete_history' => true,
                'last_message' => $aiIdx['last_message'] ?? [
                    'preview' => 'آماده پاسخگویی و جستجو در منابع فعال',
                    'created_at' => 0,
                ],
                'updated_at' => (int)($aiIdx['updated_at'] ?? 0),
                'latest_seq' => max(0, ((int)($aiIdx['next_seq'] ?? 1)) - 1),
                'unread_count' => 0,
                'max_other_read_seq' => max(0, ((int)($aiIdx['next_seq'] ?? 1)) - 1),
                'rev' => (int)($aiIdx['rev'] ?? 1),
            ];
        }

        // 3. Groups & Channels the user belongs to
        foreach ($rooms as $roomId => $room) {
            if (!self::canUserAccessChat($uname, $roomId, false)) {
                continue;
            }
            $idx = self::getChatIndex($roomId, true);
            $view = DialogView::state($uname, $roomId, $views);
            $latestSeq = max(0, ((int)($idx['next_seq'] ?? 1)) - 1);
            if ($roomId !== 'channel_announcements' && !empty($view['hidden']) && $latestSeq <= (int)($view['hidden_until_seq'] ?? 0)) continue;
            $myRead = max((int)($cursors[$roomId][$uname] ?? 0), (int)($view['cleared_seq'] ?? 0));
            $last = $idx['last_message'] ?? null;
            if ($last && ((int)($last['seq'] ?? 0) <= (int)($view['cleared_seq'] ?? 0) ||
                (int)($view['last_hidden_seq'] ?? 0) === (int)($last['seq'] ?? 0))) $last = null;
            $unread = $last ? max(0, $latestSeq - $myRead) : 0;

            $maxOtherRead = 0;
            foreach (($cursors[$roomId] ?? []) as $cu => $rseq) {
                if ($cu !== $uname && (int)$rseq > $maxOtherRead) {
                    $maxOtherRead = (int)$rseq;
                }
            }

            $members = $room['members'] ?? [];
            if (in_array('*', $members, true)) {
                $memberCount = max(0, count($allUsers) - count($room['left_users'] ?? []));
                $onlineCount = 0;
                foreach ($allUsers as $uKey => $_) {
                    if (!empty($presence[$uKey]['online'])) {
                        $onlineCount++;
                    }
                }
            } else {
                $memberCount = count($members);
                $onlineCount = 0;
                foreach ($members as $uKey) {
                    // A group membership must not expose the full user directory.
                    if (!empty($presence[$uKey]['online'])) {
                        $onlineCount++;
                    }
                }
            }

            $typingNames = [];
            foreach ($presence as $pUser => $pData) {
                if ($pUser !== $uname && ($pData['typing_chat'] ?? null) === $roomId && isset($allUsers[$pUser])) {
                    $label = ($pData['activity_type'] ?? 'typing') === 'voice' ? 'در حال ضبط صدا...' : 'در حال نوشتن...';
                    $typingNames[] = $allUsers[$pUser]['display_name'] . ' (' . $label . ')';
                }
            }

            $canPost = self::canUserAccessChat($uname, $roomId, true);
            $canManageRoom = $isAdmin || (($room['created_by'] ?? '') === $uname);

            $dialogs[] = [
                'id' => $roomId,
                'type' => $room['type'] ?? 'group',
                'title' => $room['title'] ?? $roomId,
                'subtitle' => !empty($typingNames)
                    ? implode('، ', $typingNames)
                    : ($memberCount . ' عضو' . ($onlineCount > 0 ? '، ' . $onlineCount . ' نفر آنلاین' : '')),
                'typing' => !empty($typingNames),
                'description' => $room['description'] ?? '',
                'is_public' => !empty($room['is_public']),
                'public_handle' => (string)($room['public_handle'] ?? ''),
                'can_manage_room' => $canManageRoom,
                'can_leave_room' => !$isAdmin && $roomId !== 'channel_announcements',
                'can_hide_dialog' => $roomId !== 'channel_announcements',
                'avatar_color' => $room['avatar_color'] ?? '#2AABEE',
                'pinned_rank' => !empty($view['pinned']) ? 85 : (($roomId === 'channel_announcements') ? 80 : 0),
                'pinned' => !empty($view['pinned']),
                'muted' => !empty($view['muted']),
                'archived' => !empty($view['archived']),
                'can_post' => $canPost,
                'can_delete_history' => true,
                'can_clear_for_everyone' => $canManageRoom,
                'can_delete_room' => $canManageRoom && $roomId !== 'channel_announcements',
                'member_count' => $memberCount,
                'online_count' => $onlineCount,
                'last_message' => $last,
                'updated_at' => (int)($idx['updated_at'] ?? ($room['created_at'] ?? 0)),
                'latest_seq' => $latestSeq,
                'unread_count' => $unread,
                'max_other_read_seq' => $maxOtherRead,
                'rev' => (int)($idx['rev'] ?? 1),
            ];
        }

        // 4. Direct Chats:
        //    - Admin sees ALL active users automatically.
        //    - Regular user ONLY sees contacts they added by ID OR users with existing DM messages!
        $explicitContacts = array_flip(Auth::getUserContacts($uname));

        foreach ($allUsers as $otherUname => $otherUser) {
            if ($otherUname === $uname || !$otherUser['active']) {
                continue;
            }
            $dmId = self::makeDirectChatId($uname, $otherUname);
            $dmDir = self::getChatDir($dmId);
            $hasIndexFile = file_exists($dmDir . '/index.json');
            $idx = $hasIndexFile ? self::getChatIndex($dmId, true) : [
                'next_seq' => 1, 'rev' => 1, 'total_messages' => 0, 'updated_at' => 0, 'last_message' => null,
            ];
            $view = DialogView::state($uname, $dmId, $views);
            $latestSeq = max(0, ((int)($idx['next_seq'] ?? 1)) - 1);
            if ($otherUname !== $primaryAdmin && !empty($view['hidden']) && $latestSeq <= (int)($view['hidden_until_seq'] ?? 0)) continue;
            $last = $idx['last_message'] ?? null;
            $hasActiveMessages = ((int)($last['seq'] ?? 0)) > (int)($view['cleared_seq'] ?? 0);
            if ($last && (int)($view['last_hidden_seq'] ?? 0) === (int)($last['seq'] ?? 0)) $last = null;

            if (!$isAdmin && !isset($explicitContacts[$otherUname]) && !$hasActiveMessages) {
                continue; // Strict privacy: hide unknown users from non-admins!
            }

            $knownPeers[] = $otherUname;
            $myRead = max((int)($cursors[$dmId][$uname] ?? 0), (int)($view['cleared_seq'] ?? 0));
            $unread = $last ? max(0, $latestSeq - $myRead) : 0;
            $otherRead = (int)($cursors[$dmId][$otherUname] ?? 0);

            $pInfo = $presence[$otherUname] ?? ['online' => false, 'last_seen' => 0, 'typing_chat' => null];
            if (in_array($otherUname, $blockedByViewer, true) || in_array($uname, $allBlocks[$otherUname] ?? [], true)) {
                $pInfo = ['online' => false, 'last_seen' => 0, 'typing_chat' => null];
            }
            $isTyping = ($pInfo['typing_chat'] ?? null) === $dmId;
            $typingText = ($pInfo['activity_type'] ?? 'typing') === 'voice' ? 'در حال ضبط صدا...' : 'در حال نوشتن...';

            $dialogs[] = [
                'id' => $dmId,
                'type' => 'dm',
                'peer_username' => $otherUname,
                'title' => $otherUser['display_name'],
                'subtitle' => $isTyping ? $typingText : ($pInfo['online'] ? 'آنلاین' : self::formatLastSeen((int)$pInfo['last_seen'])),
                'typing' => $isTyping,
                'bio' => $otherUser['bio'] ?? '',
                'avatar_color' => $otherUser['avatar_color'],
                'avatar_url' => $otherUser['avatar_url'] ?? '',
                'pinned_rank' => !empty($view['pinned']) ? 70 : 0,
                'pinned' => !empty($view['pinned']),
                'muted' => !empty($view['muted']),
                'archived' => !empty($view['archived']),
                'online' => (bool)$pInfo['online'],
                'last_seen' => (int)$pInfo['last_seen'],
                'is_contact' => isset($explicitContacts[$otherUname]),
                'is_primary_admin' => $otherUname === $primaryAdmin,
                'is_blocked' => in_array($otherUname, $blockedByViewer, true),
                'can_hide_dialog' => $otherUname !== $primaryAdmin,
                'can_post' => !in_array($otherUname, $blockedByViewer, true) && !in_array($uname, $allBlocks[$otherUname] ?? [], true),
                'has_history' => $hasActiveMessages,
                'can_delete_history' => true,
                'can_clear_for_everyone' => true,
                'last_message' => $last ?? [
                    'preview' => '@' . $otherUname,
                    'created_at' => 0,
                ],
                'updated_at' => (int)($idx['updated_at'] ?? 0),
                'latest_seq' => $latestSeq,
                'unread_count' => $unread,
                'max_other_read_seq' => $otherRead,
                'rev' => (int)($idx['rev'] ?? 1),
            ];
        }

        if ($outKnownPeers !== null) {
            $outKnownPeers = array_values(array_unique($knownPeers));
        }

        usort($dialogs, function (array $a, array $b): int {
            if ($a['pinned_rank'] !== $b['pinned_rank']) {
                return $b['pinned_rank'] <=> $a['pinned_rank'];
            }
            if ($a['updated_at'] !== $b['updated_at']) {
                return $b['updated_at'] <=> $a['updated_at'];
            }
            return strcmp((string)$a['title'], (string)$b['title']);
        });

        return $dialogs;
    }

    public static function formatLastSeen(int $ts): string
    {
        if ($ts <= 0) {
            return 'آخرین بازدید به تازگی';
        }
        $diff = time() - $ts;
        if ($diff < 60) {
            return 'چند لحظه پیش آنلاین بود';
        }
        if ($diff < 3600) {
            return floor($diff / 60) . ' دقیقه پیش';
        }
        if ($diff < 86400) {
            return floor($diff / 3600) . ' ساعت پیش';
        }
        return floor($diff / 86400) . ' روز پیش';
    }

    public static function getAllShardsReport(): array
    {
        $dirs = glob(CHATS_DIR . '/*', GLOB_ONLYDIR) ?: [];
        $report = [];
        $totalBytes = 0;
        $totalShards = 0;
        $totalMessages = 0;

        foreach ($dirs as $d) {
            $cid = basename($d);
            $idx = self::getChatIndex($cid, false);
            $shards = $idx['shards'] ?? [];
            $chatBytes = 0;
            foreach ($shards as $s) {
                $chatBytes += (int)($s['size_bytes'] ?? 0);
            }
            $totalBytes += $chatBytes;
            $totalShards += count($shards);
            $totalMessages += (int)($idx['total_messages'] ?? 0);

            $report[] = [
                'chat_id' => $cid,
                'total_messages' => (int)($idx['total_messages'] ?? 0),
                'shard_count' => count($shards),
                'total_bytes' => $chatBytes,
                'total_size_human' => format_bytes_fa($chatBytes),
                // Monitor preview is deliberately bounded even with thousands
                // of shards; the authoritative on-disk index is not truncated.
                'shards' => array_slice($shards, -12),
                'updated_at' => (int)($idx['updated_at'] ?? 0),
            ];
        }

        usort($report, fn($a, $b) => $b['total_bytes'] <=> $a['total_bytes']);

        $cfg = get_app_config();
        return [
            'encryption_active' => CryptoEngine::isSupported(),
            'encryption_cipher' => 'AES-256-GCM',
            'max_shard_bytes' => (int)$cfg['max_shard_bytes'],
            'max_shard_messages' => (int)$cfg['max_shard_messages'],
            'total_chats' => count($report),
            'total_shards' => $totalShards,
            'total_messages' => $totalMessages,
            'total_json_bytes' => $totalBytes,
            'total_json_human' => format_bytes_fa($totalBytes),
            'chats' => array_slice($report, 0, 100),
            'report_limited' => count($report) > 100,
        ];
    }
}
