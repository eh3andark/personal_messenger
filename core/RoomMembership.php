<?php
declare(strict_types=1);

require_once __DIR__ . '/JsonShardStore.php';

/** Public rooms join by exact handle/ID; private rooms use a secret invite in
 * the URL fragment (never sent in the initial web-server request/log). All
 * membership mutations are serialized under the rooms.json file lock.
 */
class RoomMembership
{
    private const FILE = DATA_DIR . '/rooms.json';

    private static function resolve(array $rooms, string $identifier): ?array
    {
        $id = strtolower(ltrim(trim($identifier), '@'));
        if ($id === '' || strlen($id) > 64 || !preg_match('/^[a-z0-9_\-]+$/D', $id)) return null;
        if (isset($rooms[$id]) && is_array($rooms[$id])) return $rooms[$id];
        foreach ($rooms as $room) {
            if (is_array($room) && ($room['public_handle'] ?? '') !== '' && ($room['public_handle'] ?? '') === $id) return $room;
        }
        return null;
    }

    public static function handleIsAvailable(array $rooms, string $handle, string $exceptId = ''): bool
    {
        if ($handle === '') return true;
        if (preg_match('/^(group|channel)_[a-f0-9]{10}$/D', $handle)) return false;
        foreach ($rooms as $id => $room) {
            if ($id !== $exceptId && ($id === $handle || ($room['public_handle'] ?? '') === $handle)) return false;
        }
        return true;
    }

    private static function memberCount(array $room): int
    {
        if (in_array('*', $room['members'] ?? [], true)) {
            $all = Auth::getAllUsers(false);
            return max(0, count(array_filter($all, fn($u) => !empty($u['active']))) - count($room['left_users'] ?? []));
        }
        return count($room['members'] ?? []);
    }

    private static function summary(array $room, array $viewer, bool $manager = false, bool $includeInvite = false): array
    {
        $uname = $viewer['username'];
        $members = $room['members'] ?? [];
        $joined = in_array('*', $members, true)
            ? !in_array($uname, $room['left_users'] ?? [], true)
            : in_array($uname, $members, true);
        $info = [
            'id' => $room['id'], 'type' => $room['type'], 'title' => $room['title'],
            'description' => (string)($room['description'] ?? ''),
            'member_count' => self::memberCount($room),
            'is_public' => !empty($room['is_public']),
            'public_handle' => (string)($room['public_handle'] ?? ''),
            'only_admins_post' => !empty($room['only_admins_post']),
            'joined' => $room['id'] === 'channel_announcements' ? true : $joined,
            'mandatory' => $room['id'] === 'channel_announcements',
            'can_leave' => $room['id'] !== 'channel_announcements' && ($viewer['role'] ?? '') !== 'admin',
            'can_manage' => $manager,
            'can_manage_members' => $manager && $room['id'] !== 'channel_announcements',
            'is_creator' => ($room['created_by'] ?? '') === $uname,
        ];
        if ($manager) $info['members'] = in_array('*', $members, true) ? [] : array_values($members);
        // A current member may pass along a private invite without learning
        // the whole membership list. Public discovery never exposes tokens.
        if ($includeInvite && ($joined || $manager)) $info['invite_token'] = (string)($room['invite_token'] ?? '');
        return $info;
    }

    public static function findPublicRoom(string $identifier, array $viewer): array
    {
        $room = self::resolve(JsonShardStore::ensureDefaultRooms(), $identifier);
        if ($room === null || empty($room['is_public'])) {
            return ['ok' => false, 'error' => 'گروه/کانال عمومی با این آیدی پیدا نشد؛ برای فضای خصوصی لینک دعوت لازم است.'];
        }
        return ['ok' => true, 'room' => self::summary($room, $viewer)];
    }

    /** Search public rooms by visible name or @handle; never inspect private rooms. */
    public static function searchPublicRooms(string $query, array $viewer): array
    {
        $query = mb_strtolower(trim($query));
        $handleQuery = ltrim($query, '@');
        if (mb_strlen($query) < 2 || mb_strlen($query) > 60 || mb_strlen($handleQuery) < 2) {
            return ['ok' => false, 'error' => 'برای جستجوی عمومی دست‌کم دو و حداکثر ۶۰ نویسه وارد کنید.'];
        }
        $found = [];
        foreach (JsonShardStore::ensureDefaultRooms() as $room) {
            if (!is_array($room) || empty($room['is_public']) || ($room['id'] ?? '') === 'channel_announcements') continue;
            $title = mb_strtolower((string)($room['title'] ?? ''));
            $handle = mb_strtolower((string)($room['public_handle'] ?? ''));
            if (mb_strpos($title, $query) === false && mb_strpos($handle, $handleQuery) === false) continue;
            $found[] = self::summary($room, $viewer, false, false);
            if (count($found) >= 20) break; // bounded public result page
        }
        return ['ok' => true, 'rooms' => $found, 'limit' => 20];
    }

    public static function getRoomInfo(string $roomId, array $viewer): array
    {
        $uname = $viewer['username'];
        $rooms = JsonShardStore::ensureDefaultRooms();
        $room = $rooms[$roomId] ?? null;
        if (!is_array($room) || !JsonShardStore::canUserAccessChat($uname, $roomId, false)) {
            return ['ok' => false, 'error' => 'به اطلاعات این گروه دسترسی ندارید.'];
        }
        $manager = ($viewer['role'] ?? '') === 'admin' || ($room['created_by'] ?? '') === $uname;
        if (empty($room['invite_token'])) {
            $room = with_file_lock('rooms', function () use ($roomId): array {
                $all = read_json_safe(self::FILE, null);
                if (!is_array($all) || !isset($all[$roomId])) throw new RuntimeException('گروه دیگر وجود ندارد.');
                if (empty($all[$roomId]['invite_token'])) {
                    $all[$roomId]['invite_token'] = bin2hex(random_bytes(18));
                    if (!write_json_atomic(self::FILE, $all, true)) throw new RuntimeException('ذخیره لینک دعوت ممکن نشد.');
                }
                return $all[$roomId];
            });
        }
        return ['ok' => true, 'room' => self::summary($room, $viewer, $manager, true)];
    }

    public static function joinRoom(string $identifier, string $token, array $viewer): array
    {
        JsonShardStore::ensureDefaultRooms();
        return with_file_lock('rooms', function () use ($identifier, $token, $viewer): array {
            $all = read_json_safe(self::FILE, null);
            if (!is_array($all)) throw new RuntimeException('فهرست گروه‌ها قابل خواندن نیست.');
            $room = self::resolve($all, $identifier);
            if ($room === null) return ['ok' => false, 'error' => 'گروه/کانال پیدا نشد یا لینک دعوت معتبر نیست.'];
            $id = $room['id'];
            $uname = $viewer['username'];
            $already = in_array('*', $room['members'] ?? [], true)
                ? !in_array($uname, $room['left_users'] ?? [], true)
                : in_array($uname, $room['members'] ?? [], true);
            if (!$already && empty($room['is_public']) &&
                (!preg_match('/^[a-f0-9]{36}$/D', $token) ||
                 empty($room['invite_token']) || !hash_equals((string)$room['invite_token'], $token))) {
                return ['ok' => false, 'error' => 'این گروه خصوصی است؛ لینک دعوت معتبر لازم است یا لینک قبلی لغو شده است.'];
            }
            if (!$already) {
                $room['left_users'] = array_values(array_diff($room['left_users'] ?? [], [$uname]));
                if (!in_array('*', $room['members'] ?? [], true)) {
                    $room['members'][] = $uname;
                    $room['members'] = array_values(array_unique($room['members']));
                }
                $all[$id] = $room;
                if (!write_json_atomic(self::FILE, $all, true)) throw new RuntimeException('ثبت عضویت گروه ممکن نشد.');
            }
            return ['ok' => true, 'already_member' => $already, 'room' => self::summary($room, $viewer)];
        });
    }

    public static function leaveRoom(string $roomId, array $viewer): array
    {
        JsonShardStore::ensureDefaultRooms();
        return with_file_lock('rooms', function () use ($roomId, $viewer): array {
            $all = read_json_safe(self::FILE, null);
            $room = is_array($all) ? ($all[$roomId] ?? null) : null;
            if (!is_array($room)) return ['ok' => false, 'error' => 'گروه پیدا نشد.'];
            if ($roomId === 'channel_announcements') {
                return ['ok' => false, 'error' => 'عضویت در کانال اطلاعیهٔ عمومی برای همهٔ حساب‌ها الزامی است.'];
            }
            $uname = $viewer['username'];
            if (($viewer['role'] ?? '') === 'admin') {
                return ['ok' => false, 'error' => 'مدیر کل همچنان به گروه‌ها دسترسی مدیریتی دارد؛ برای خروج واقعی، نقش مدیریتی را واگذار کنید.'];
            }
            $joined = in_array('*', $room['members'] ?? [], true)
                ? !in_array($uname, $room['left_users'] ?? [], true)
                : in_array($uname, $room['members'] ?? [], true);
            if (!$joined) return ['ok' => false, 'error' => 'عضو این گروه نیستید.'];
            if (in_array('*', $room['members'] ?? [], true)) {
                $room['left_users'][] = $uname;
                $room['left_users'] = array_values(array_unique($room['left_users']));
            } else {
                $room['members'] = array_values(array_diff($room['members'], [$uname]));
            }
            $room['admins'] = array_values(array_diff($room['admins'] ?? [], [$uname]));
            if (($room['created_by'] ?? '') === $uname) $room['created_by'] = '';
            $all[$roomId] = $room;
            if (!write_json_atomic(self::FILE, $all, true)) throw new RuntimeException('خروج از گروه ثبت نشد.');
            return ['ok' => true, 'room_id' => $roomId];
        });
    }

    /** Invite or remove one exact account ID; never expose the entire directory. */
    public static function manageMember(string $roomId, string $targetId, array $viewer, bool $remove = false): array
    {
        if ($roomId === 'channel_announcements') {
            return ['ok' => false, 'error' => 'اعضای کانال عمومی سامانه قابل افزودن یا حذف‌کردن نیستند.'];
        }
        $target = normalize_username($targetId);
        JsonShardStore::ensureDefaultRooms();
        return with_file_lock('rooms', function () use ($roomId, $target, $viewer, $remove): array {
            $all = read_json_safe(self::FILE, null);
            $room = is_array($all) ? ($all[$roomId] ?? null) : null;
            if (!is_array($room)) return ['ok' => false, 'error' => 'گروه پیدا نشد.'];
            if (($viewer['role'] ?? '') !== 'admin' && ($room['created_by'] ?? '') !== $viewer['username']) {
                return ['ok' => false, 'error' => 'فقط سازنده یا مدیر سامانه می‌تواند اعضا را مدیریت کند.'];
            }
            if ($target === '' || $target === $viewer['username']) return ['ok' => false, 'error' => 'آیدی عضو معتبر نیست؛ برای خروج خودتان از «ترک گروه» استفاده کنید.'];
            $user = Auth::getUser($target);
            if ($user === null || empty($user['active'])) return ['ok' => false, 'error' => 'کاربر فعالی با این آیدی دقیق پیدا نشد.'];
            if (in_array('*', $room['members'] ?? [], true)) {
                $room['left_users'] = $remove
                    ? array_values(array_unique(array_merge($room['left_users'] ?? [], [$target])))
                    : array_values(array_diff($room['left_users'] ?? [], [$target]));
            } else {
                $room['members'] = $remove
                    ? array_values(array_diff($room['members'], [$target]))
                    : array_values(array_unique(array_merge($room['members'], [$target])));
            }
            if ($remove) {
                $room['admins'] = array_values(array_diff($room['admins'] ?? [], [$target]));
                if (($room['created_by'] ?? '') === $target) $room['created_by'] = '';
            }
            $all[$roomId] = $room;
            if (!write_json_atomic(self::FILE, $all, true)) throw new RuntimeException('تغییر عضویت ثبت نشد.');
            return ['ok' => true, 'room' => self::summary($room, $viewer, true)];
        });
    }

    public static function updateRoom(string $roomId, array $viewer, array $changes): array
    {
        if ($roomId === 'channel_announcements' && ($viewer['role'] ?? '') !== 'admin') {
            return ['ok' => false, 'error' => 'فقط مدیر سامانه می‌تواند اطلاعیه‌های عمومی را تغییر دهد.'];
        }
        JsonShardStore::ensureDefaultRooms();
        return with_file_lock('rooms', function () use ($roomId, $viewer, $changes): array {
            $all = read_json_safe(self::FILE, null);
            $room = is_array($all) ? ($all[$roomId] ?? null) : null;
            if (!is_array($room)) return ['ok' => false, 'error' => 'گروه پیدا نشد.'];
            if (($viewer['role'] ?? '') !== 'admin' && ($room['created_by'] ?? '') !== $viewer['username']) {
                return ['ok' => false, 'error' => 'فقط سازنده یا مدیر سامانه می‌تواند گروه را ویرایش کند.'];
            }
            if (isset($changes['title'])) {
                $title = mb_substr(trim((string)$changes['title']), 0, 60);
                if ($title === '') return ['ok' => false, 'error' => 'عنوان گروه نباید خالی باشد.'];
                $room['title'] = $title;
            }
            if (isset($changes['description'])) $room['description'] = mb_substr(trim((string)$changes['description']), 0, 200);
            $public = isset($changes['is_public']) ? !empty($changes['is_public']) : !empty($room['is_public']);
            $handle = strtolower(ltrim(trim((string)($changes['public_handle'] ?? ($room['public_handle'] ?? ''))), '@'));
            if ($public && $handle !== '' && !preg_match('/^[a-z][a-z0-9_]{4,31}$/D', $handle)) {
                return ['ok' => false, 'error' => 'آیدی عمومی گروه معتبر نیست (۵ تا ۳۲ نویسه انگلیسی).'];
            }
            if ($public && !self::handleIsAvailable($all, $handle, $roomId)) {
                return ['ok' => false, 'code' => 'duplicate_id', 'error' => 'این آیدی عمومی قبلاً برای گروه دیگری ثبت شده است.'];
            }
            $room['is_public'] = $public;
            $room['public_handle'] = $public ? $handle : '';
            if (($room['type'] ?? '') === 'channel') $room['only_admins_post'] = true;
            elseif (isset($changes['only_admins_post'])) $room['only_admins_post'] = !empty($changes['only_admins_post']);
            if (!empty($changes['rotate_invite'])) $room['invite_token'] = bin2hex(random_bytes(18));
            $all[$roomId] = $room;
            if (!write_json_atomic(self::FILE, $all, true)) throw new RuntimeException('ویرایش گروه ثبت نشد.');
            return ['ok' => true, 'room' => self::summary($room, $viewer, true)];
        });
    }
}
