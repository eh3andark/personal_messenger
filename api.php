<?php
declare(strict_types=1);

require_once __DIR__ . '/core/bootstrap.php';
require_once __DIR__ . '/core/CryptoEngine.php';
require_once __DIR__ . '/core/Auth.php';
require_once __DIR__ . '/core/JsonShardStore.php';
require_once __DIR__ . '/core/RoomMembership.php';
require_once __DIR__ . '/core/MediaManager.php';
require_once __DIR__ . '/core/StickerStore.php';
require_once __DIR__ . '/core/AssistantBridge.php';

$action = (string)($_GET['action'] ?? $_POST['action'] ?? '');

set_exception_handler(function (Throwable $e): void {
    error_log('Chat Amn API error: ' . $e->getMessage());
    $friendly = ($e instanceof RuntimeException)
        ? $e->getMessage()
        : 'یک خطای داخلی در سرور رخ داد. دسترسی نوشتن پوشه data و لاگ PHP را بررسی کنید.';
    json_response(['ok' => false, 'error' => $friendly], 500);
});

$httpMethod = strtoupper((string)($_SERVER['REQUEST_METHOD'] ?? 'GET'));
if ($action === 'login' && $httpMethod === 'POST' &&
    stripos((string)($_SERVER['CONTENT_TYPE'] ?? ''), 'application/json') === false) {
    json_response(['ok' => false, 'error' => 'ورود تنها از طریق درخواست امن برنامه امکان‌پذیر است.'], 415);
}
$readOnlyActions = ['bootstrap', 'messages', 'search_chat', 'drive_list', 'admin_overview', 'admin_account_activity', 'my_sessions', 'media', 'room_info', 'find_public_room', 'search_public_rooms', 'sticker_list', 'my_media_list'];
if (!in_array($action, $readOnlyActions, true) && $httpMethod !== 'POST') {
    json_response(['ok' => false, 'error' => 'این عملیات فقط با درخواست POST امکان‌پذیر است.'], 405);
}
if ($httpMethod === 'POST' && $action !== 'login' &&
    !preg_match('/^[a-f0-9]{64}$/', (string)($_SERVER['HTTP_X_AUTH_TOKEN'] ?? ''))) {
    // Cookie-only cross-origin form submissions cannot mutate private data (CSRF).
    json_response(['ok' => false, 'error' => 'توکن احراز هویت برای این عملیات ضروری است.'], 403);
}

// Authenticated HTTP 206 Byte-Range Media Streamer
if ($action === 'media') {
    $user = Auth::currentUserForMedia();
    if ($user === null) {
        http_response_code(401);
        exit('401 Unauthorized');
    }
    $mediaId = (string)($_GET['id'] ?? '');
    $dl = !empty($_GET['dl']);
    if (!MediaManager::canUserViewMedia($user, $mediaId)) {
        http_response_code(403);
        exit('403 Forbidden - media access denied');
    }
    MediaManager::streamMedia($mediaId, $dl);
}

$req = get_request_data();
if ($action === '' && isset($req['action'])) {
    $action = (string)$req['action'];
}

/** Keep the account/ID/role/password but clear this owner's content and view. */
function reset_account_content(array $owner): array
{
    $uname = $owner['username'];
    // Shared DMs and rooms remain for other participants; only this user's
    // inbox receives visibility watermarks. Private spaces are purged physically.
    $dialogs = JsonShardStore::resetUserViews($uname);
    foreach (['saved_' . $uname, 'ai_' . $uname] as $cid) {
        $res = JsonShardStore::clearChatHistory($cid, $owner, 'both');
        if (!$res['ok']) throw new RuntimeException($res['error']);
    }
    $drive = MediaManager::clearPersonalDrive($uname);
    if (!$drive['ok']) throw new RuntimeException('پاکسازی فضای شخصی ناموفق بود.');
    StickerStore::clearAccount($uname);
    if (!Auth::clearAllContacts($uname)) throw new RuntimeException('پاکسازی مخاطبان ناموفق بود.');
    Auth::clearBlocks($uname);
    $oldAvatarId = '';
    if (preg_match('/^api\\.php\\?action=media&id=([a-zA-Z0-9_]+)$/', (string)($owner['avatar_url'] ?? ''), $match)) {
        $oldAvatarId = $match[1];
    }
    $profile = Auth::saveUserRecord(['username' => $uname, 'display_name' => $uname,
        'bio' => '', 'avatar_url' => '', 'avatar_color' => '#2AABEE'], false);
    if (!$profile['ok']) throw new RuntimeException($profile['error']);
    if ($oldAvatarId !== '') MediaManager::removeProfileAvatar($oldAvatarId, $uname);
    $orphans = MediaManager::purgeUnusedOwnedMedia($uname);
    return ['ok' => true, 'cleared_dialogs' => $dialogs, 'removed_orphan_files' => $orphans,
        'quota' => MediaManager::getUserQuotaSummary($uname)];
}

switch ($action) {
    // ========================================================================
    // Authentication
    // ========================================================================
    case 'login': {
        $username = (string)($req['username'] ?? '');
        $password = (string)($req['password'] ?? '');
        $res = Auth::login($username, $password, (string)($req['tab_id'] ?? ''));
        if (!$res['ok']) {
            json_response($res, 401);
        }
        JsonShardStore::ensureDefaultRooms();
        json_response($res);
    }

    case 'logout': {
        $user = Auth::requireUser();
        Auth::closePresenceTab($user['username'], (string)($req['tab_id'] ?? ''));
        Auth::logout();
        json_response(['ok' => true]);
    }

    case 'presence_close': {
        $user = Auth::requireUser();
        Auth::closePresenceTab($user['username'], (string)($req['tab_id'] ?? ''));
        json_response(['ok' => true]);
    }

    // ========================================================================
    // Initial Bootstrap (Enforces User Discovery Privacy!)
    // ========================================================================
    case 'bootstrap': {
        $user = Auth::requireUser();
        Auth::touchPresence($user['username'], null, 'typing', (string)($_GET['tab_id'] ?? ''));
        JsonShardStore::ensureDefaultRooms();
        $cfg = get_app_config();

        $knownPeers = [];
        $dialogs = JsonShardStore::getUserDialogList($user['username'], $knownPeers);
        $visibleUsers = Auth::getVisibleUsersFor($user, $knownPeers);
        $presence = Auth::getPresenceForViewer($user['username'], $visibleUsers);
        $quotaInfo = MediaManager::getUserQuotaSummary($user['username']);

        json_response([
            'ok' => true,
            'token' => Auth::getRequestToken(),
            'media_ticket' => Auth::getRequestMediaTicket(),
            'user' => $user,
            'users' => $visibleUsers,
            'presence' => $presence,
            'blocked_users' => Auth::getBlockedUsers($user['username']),
            'primary_admin_id' => Auth::getPrimaryAdminId(),
            'dialogs' => $dialogs,
            'quota' => $quotaInfo,
            'config' => [
                'app_title' => $cfg['app_title'],
                'app_subtitle' => $cfg['app_subtitle'],
                'max_shard_bytes' => (int)$cfg['max_shard_bytes'],
                'max_shard_messages' => (int)$cfg['max_shard_messages'],
                'max_upload_mb' => (int)$cfg['max_upload_mb'],
                'chunk_size_bytes' => (int)$cfg['chunk_size_bytes'],
                'video_compress_threshold_mb' => (int)($cfg['video_compress_threshold_mb'] ?? 8),
                'allow_user_profile_edit' => (bool)$cfg['allow_user_profile_edit'],
                'allow_user_username_change' => (bool)($cfg['allow_user_username_change'] ?? true),
                'ai_enabled' => !empty($cfg['ai_bridge']['enabled']),
                'ai_has_key' => trim((string)($cfg['ai_bridge']['api_key'] ?? '')) !== '',
                'ai_model' => (string)($cfg['ai_bridge']['model'] ?? ''),
                'web_tools' => AssistantBridge::getPublicTools(),
                'encryption_active' => CryptoEngine::isSupported(),
                'encryption_cipher' => 'AES-256-GCM',
            ],
        ]);
    }

    // ========================================================================
    // Real-time Heartbeat & Incremental Sync
    // ========================================================================
    case 'sync': {
        $user = Auth::requireUser();
        $typingChat = isset($req['typing_chat']) ? (string)$req['typing_chat'] : null;
        $activityType = (string)($req['activity_type'] ?? 'typing');
        Auth::touchPresence($user['username'], $typingChat, $activityType, (string)($req['tab_id'] ?? ''));

        $knownPeers = [];
        $dialogs = JsonShardStore::getUserDialogList($user['username'], $knownPeers);
        $visibleUsers = Auth::getVisibleUsersFor($user, $knownPeers);
        $presence = Auth::getPresenceForViewer($user['username'], $visibleUsers);

        $activeChatId = (string)($req['active_chat_id'] ?? '');
        $sinceRev = (int)($req['since_rev'] ?? 0);
        $chatChanges = null;

        if ($activeChatId !== '' && JsonShardStore::canUserAccessChat($user['username'], $activeChatId, false)) {
            $chatChanges = JsonShardStore::getChangesSince($activeChatId, $sinceRev, $user['username'], (int)($req['since_view_rev'] ?? 0));
            // Sync never marks messages read: polling a background/login tab is
            // not equivalent to opening and viewing a conversation.
        }

        json_response([
            'ok' => true,
            'user' => $user,
            'media_ticket' => Auth::getRequestMediaTicket(),
            'dialogs' => $dialogs,
            'presence' => $presence,
            'users' => $visibleUsers,
            'blocked_users' => Auth::getBlockedUsers($user['username']),
            'quota' => MediaManager::getUserQuotaSummary($user['username']),
            'web_tools' => AssistantBridge::getPublicTools(),
            'chat_changes' => $chatChanges,
            'server_time' => time(),
        ]);
    }

    // ========================================================================
    // Find User by Exact ID (@username) & Manage Contacts
    // ========================================================================
    case 'find_user_by_id': {
        $user = Auth::requireUser();
        if ($user['role'] !== 'admin' && !Auth::allowContactLookup($user['username'])) {
            json_response(['ok' => false, 'error' => 'تعداد جستجوی آیدی زیاد است. لطفاً ۱۰ دقیقه بعد دوباره تلاش کنید.'], 429);
        }
        $targetId = (string)($req['id'] ?? '');
        $res = Auth::addContact($user['username'], $targetId);
        if (!$res['ok']) {
            json_response($res, 404);
        }
        $contact = $res['contact'];
        $dmId = JsonShardStore::makeDirectChatId($user['username'], $contact['username']);
        DialogView::reopen($user['username'], $dmId);

        $knownPeers = [];
        $dialogs = JsonShardStore::getUserDialogList($user['username'], $knownPeers);
        $visibleUsers = Auth::getVisibleUsersFor($user, $knownPeers);

        json_response([
            'ok' => true,
            'contact' => $contact,
            'chat_id' => $dmId,
            'dialogs' => $dialogs,
            'users' => $visibleUsers,
        ]);
    }

    case 'remove_contact': {
        $user = Auth::requireUser();
        $peer = normalize_username((string)($req['peer_username'] ?? ''));
        if ($peer === '') {
            json_response(['ok' => false, 'error' => 'کاربر مشخص نشده است.'], 400);
        }
        if ($peer === Auth::getPrimaryAdminId() && $peer !== $user['username']) {
            json_response(['ok' => false, 'error' => 'مدیر اصلیِ نصب مخاطب دائمی همهٔ کاربران است و از فهرست حذف نمی‌شود.'], 403);
        }
        $dmId = JsonShardStore::makeDirectChatId($user['username'], $peer);
        if (!JsonShardStore::canUserAccessChat($user['username'], $dmId, false)) {
            json_response(['ok' => false, 'error' => 'گفتگوی خصوصی معتبر نیست.'], 403);
        }
        if (!Auth::removeContact($user['username'], $peer)) {
            json_response(['ok' => false, 'error' => 'حذف مخاطب ذخیره نشد.'], 500);
        }
        // Removing a contact never secretly erases the peer's history.
        if (!empty($req['also_clear_chat'])) JsonShardStore::clearChatHistory($dmId, $user, 'me');
        JsonShardStore::hideDialogForMe($dmId, $user['username']);
        $knownPeers = [];
        $dialogs = JsonShardStore::getUserDialogList($user['username'], $knownPeers);
        json_response(['ok' => true, 'dialogs' => $dialogs]);
    }

    case 'block_user':
    case 'unblock_user': {
        $user = Auth::requireUser();
        $peer = (string)($req['peer_username'] ?? '');
        $res = Auth::setUserBlocked($user['username'], $peer, $action === 'block_user');
        if (!$res['ok']) json_response($res, 403);
        $knownPeers = [];
        $res['dialogs'] = JsonShardStore::getUserDialogList($user['username'], $knownPeers);
        json_response($res);
    }

    // ========================================================================
    // Load Chat Messages (with Upward Shard Pagination)
    // ========================================================================
    case 'messages': {
        $user = Auth::requireUser();
        $chatId = (string)($_GET['chat_id'] ?? $req['chat_id'] ?? '');
        if (!JsonShardStore::canUserAccessChat($user['username'], $chatId, false)) {
            json_response(['ok' => false, 'error' => 'دسترسی به این گفتگو مجاز نیست.'], 403);
        }
        $beforeSeq = isset($_GET['before_seq']) && $_GET['before_seq'] !== '' ? (int)$_GET['before_seq'] : null;
        $limit = isset($_GET['limit']) ? (int)$_GET['limit'] : 50;

        $data = JsonShardStore::getMessages($chatId, $beforeSeq, $limit, $user['username']);
        $data['ok'] = true;
        json_response($data);
    }

    case 'mark_chat_read': {
        $user = Auth::requireUser();
        $chatId = (string)($req['chat_id'] ?? '');
        if (!JsonShardStore::canUserAccessChat($user['username'], $chatId, false)) {
            json_response(['ok' => false, 'error' => 'دسترسی به گفتگو مجاز نیست.'], 403);
        }
        $lastSeq = max(0, (int)(JsonShardStore::getChatIndex($chatId, false)['next_seq'] ?? 1) - 1);
        $seq = (int)($req['seq'] ?? 0);
        if ($seq < 0 || $seq > $lastSeq) {
            json_response(['ok' => false, 'error' => 'شمارهٔ پیام نمایش‌داده‌شده معتبر نیست.'], 400);
        }
        if ($seq > 0) JsonShardStore::markChatRead($user['username'], $chatId, $seq);
        json_response(['ok' => true, 'read_seq' => $seq]);
    }

    // ========================================================================
    // Send Message (Text / Photo / Video / Voice / File / Reply)
    // ========================================================================
    case 'send_message': {
        $user = Auth::requireUser();
        $chatId = (string)($req['chat_id'] ?? '');
        if (!JsonShardStore::canUserAccessChat($user['username'], $chatId, true)) {
            json_response(['ok' => false, 'error' => 'شما مجاز به ارسال پیام در این گفتگو نیستید.'], 403);
        }

        $text = trim((string)($req['text'] ?? ''));
        if (mb_strlen($text) > 8000) {
            json_response(['ok' => false, 'error' => 'هر پیام متنی حداکثر می‌تواند ۸۰۰۰ نویسه داشته باشد؛ متن بلندتر را به صورت فایل بفرستید.'], 400);
        }
        $mediaId = trim((string)($req['media_id'] ?? ''));
        $replySeq = isset($req['reply_to_seq']) ? (int)$req['reply_to_seq'] : 0;

        $mediaMeta = null;
        if ($mediaId !== '') {
            $m = MediaManager::getMediaMeta($mediaId);
            if ($m === null || ($m['owner'] ?? '') !== $user['username'] || !MediaManager::canUserViewMedia($user, $mediaId)) {
                json_response(['ok' => false, 'error' => 'فایل پیوست یافت نشد یا متعلق به شما نیست.'], 403);
            }
            $quota = MediaManager::getUserQuotaSummary($user['username']);
            // An upload that *crosses* 80% must be allowed to finish its first
            // send; all later media sends/uploads stop until space is freed.
            $justUploaded = ((int)($m['created_at'] ?? 0) >= time() - 120) && empty($m['references']);
            if (!empty($quota['media_blocked']) && !$justUploaded) {
                json_response(['ok' => false, 'code' => 'quota_media_blocked', 'error' => 'سهمیهٔ رسانه به ۸۰٪ رسیده؛ فعلاً فقط متن بفرستید و از مدیریت رسانه‌ها فضا آزاد کنید.'], 403);
            }
            $mediaMeta = MediaManager::publicMediaMeta($m);
        }

        if ($text === '' && $mediaMeta === null) {
            json_response(['ok' => false, 'error' => 'متن پیام یا فایل پیوست الزامی است.'], 400);
        }

        $replyObj = null;
        if ($replySeq > 0) {
            $orig = JsonShardStore::getMessageBySeq($chatId, $replySeq, $user['username']);
            if ($orig !== null) {
                $replyObj = JsonShardStore::summarizeMessage($orig);
            }
        }

        $msg = JsonShardStore::appendMessage($chatId, [
            'sender' => $user['username'],
            'sender_name' => $user['display_name'],
            'type' => ($mediaMeta !== null) ? 'media' : 'text',
            'text' => $text,
            'media' => $mediaMeta,
            'reply_to' => $replyObj,
        ]);

        if (strpos($chatId, 'dm_') === 0) DialogView::reopen($user['username'], $chatId);
        Auth::touchPresence($user['username'], '', 'typing', (string)($req['tab_id'] ?? ''));

        if ($chatId === JsonShardStore::makeSavedChatId($user['username']) && $mediaMeta !== null) {
            $folderMap = [
                'image' => 'gallery',
                'video' => 'gallery',
                'voice' => 'files',
                'audio' => 'files',
                'file'  => 'files',
            ];
            MediaManager::addDriveItem($user['username'], [
                'folder_id' => $folderMap[$mediaMeta['category'] ?? 'file'] ?? 'files',
                'title' => ($text !== '') ? mb_substr($text, 0, 80) : ($mediaMeta['original_name'] ?? 'فایل ذخیره‌شده'),
                'note' => $text,
                'media' => $mediaMeta,
            ]);
        }

        $idx = JsonShardStore::getChatIndex($chatId, false);
        json_response([
            'ok' => true,
            'message' => $msg,
            'rev' => (int)$idx['rev'],
            'shard_info' => [
                'shard_count' => count($idx['shards'] ?? []),
                'active_shard' => !empty($idx['shards']) ? $idx['shards'][count($idx['shards']) - 1]['file'] : 'part_0001.json',
                'active_shard_bytes' => !empty($idx['shards']) ? (int)$idx['shards'][count($idx['shards']) - 1]['size_bytes'] : 0,
                'total_messages' => (int)($idx['total_messages'] ?? 0),
            ],
        ]);
    }

    // ========================================================================
    // Ask AI / Customizable Web Tools
    // ========================================================================
    case 'ask_ai': {
        $user = Auth::requireUser();
        $aiChatId = JsonShardStore::makeAiChatId($user['username']);
        if (empty(get_app_config()['ai_bridge']['enabled'])) {
            json_response(['ok' => false, 'error' => 'این بخش توسط مدیر غیرفعال شده است.'], 403);
        }
        $prompt = trim((string)($req['prompt'] ?? ''));
        if ($prompt === '' || mb_strlen($prompt) > 5000) {
            json_response(['ok' => false, 'error' => 'درخواست دستیار باید بین ۱ تا ۵۰۰۰ نویسه باشد.'], 400);
        }

        $recent = JsonShardStore::getMessages($aiChatId, null, 10, $user['username'])['messages'] ?? [];
        $answer = AssistantBridge::handleUserPrompt($prompt, $recent);
        $replies = AssistantBridge::splitPublicTelegramReply($prompt, $answer);
        $telegramPlan = AssistantBridge::getTelegramImportPlan($prompt, $answer);
        $botMessages = [];
        foreach ($replies as $text) {
            $botMessages[] = JsonShardStore::appendMessage($aiChatId, [
                'sender' => 'ai',
                'sender_name' => 'دستیار هوشمند',
                'type' => 'ai',
                'text' => $text,
            ]);
        }

        $idx = JsonShardStore::getChatIndex($aiChatId, false);
        json_response([
            'ok' => true,
            'message' => $botMessages[count($botMessages) - 1],
            'messages' => $botMessages,
            'telegram_media_pending' => $telegramPlan,
            'rev' => (int)$idx['rev'],
        ]);
    }

    // A single bounded public-preview attachment per call. Client requests
    // only the cached channel and post number: never an arbitrary URL/host.
    case 'telegram_import_post': {
        $user = Auth::requireUser();
        $channel = strtolower((string)($req['channel'] ?? ''));
        $postNo = (int)($req['post_number'] ?? 0);
        $aiChat = JsonShardStore::makeAiChatId($user['username']);
        $enabled = false;
        foreach (AssistantBridge::getPublicTools() as $tool) {
            if (($tool['type'] ?? '') === 'telegram_public') $enabled = true;
        }
        if (!$enabled || empty(get_app_config()['ai_bridge']['enabled'])) {
            json_response(['ok' => false, 'error' => 'خواندن کانال عمومی توسط مدیر غیرفعال شده است.'], 403);
        }
        $post = AssistantBridge::cachedTelegramPost($channel, $postNo);
        if (!$post || empty($post['media_url'])) {
            json_response(['ok' => false, 'error' => 'پیش‌نمایش این رسانه منقضی شده یا فایل قابل‌دریافت ندارد؛ کانال را دوباره بخوانید.'], 404);
        }
        $result = with_file_lock('telegram_import_' . $user['username'] . '_' . $channel . '_' . $postNo,
            function () use ($user, $channel, $postNo, $post, $aiChat): array {
                $receiptDir = DATA_DIR . '/upload_receipts';
                if (!is_dir($receiptDir) && !@mkdir($receiptDir, 0755, true) && !is_dir($receiptDir)) {
                    throw new RuntimeException('فضای رسید رسانه قابل ساخت نیست.');
                }
                $receiptPath = $receiptDir . '/tg_' . $user['username'] . '_' . hash('sha256', $channel) . '_' . $postNo . '.json';
                $receipt = read_json_safe($receiptPath, []);
                if (is_array($receipt) && !empty($receipt['seq']) && !empty($receipt['media_id'])) {
                    $oldMedia = MediaManager::getMediaMeta((string)$receipt['media_id']);
                    $oldMsg = JsonShardStore::getMessageBySeq($aiChat, (int)$receipt['seq'], $user['username']);
                    if (($oldMedia['owner'] ?? '') === $user['username'] &&
                        ($oldMsg['media']['id'] ?? '') === $receipt['media_id']) {
                        return ['ok' => true, 'already_imported' => true, 'message' => $oldMsg];
                    }
                }
                $type = (string)($post['media_type'] ?? '');
                $download = AssistantBridge::downloadTelegramPreviewMedia((string)$post['media_url'], $type);
                $uploaded = !empty($download['ok'])
                    ? MediaManager::importTelegramPreview($user, $download['body'], $type, $channel, $postNo)
                    : $download;
                if (empty($uploaded['ok'])) {
                    $reason = (string)($uploaded['error'] ?? 'دریافت فایل انجام نشد.');
                    $notice = JsonShardStore::appendMessage($aiChat, [
                        'sender' => 'ai', 'sender_name' => 'دستیار هوشمند', 'type' => 'ai',
                        'text' => "⚠️ دریافت رسانهٔ پست @{$channel}/{$postNo} ناموفق بود: {$reason}\n🔗 https://t.me/{$channel}/{$postNo}",
                    ]);
                    return ['ok' => false, 'error' => $reason, 'message' => $notice,
                        'quota' => MediaManager::getUserQuotaSummary($user['username'])];
                }
                $media = $uploaded['media'];
                try {
                    $notice = JsonShardStore::appendMessage($aiChat, [
                        'sender' => 'ai', 'sender_name' => 'دستیار هوشمند', 'type' => 'ai',
                        'text' => "📎 رسانهٔ قابل‌دریافت از پیش‌نمایش عمومی @{$channel}/{$postNo}؛ ممکن است نسخه/کیفیت آن با فایل اصلی تلگرام فرق کند.\n🔗 https://t.me/{$channel}/{$postNo}",
                        'media' => $media,
                    ]);
                    if (!write_json_atomic($receiptPath, ['media_id' => $media['id'], 'seq' => $notice['seq'], 'created_at' => time()], false)) {
                        throw new RuntimeException('رسید رسانهٔ واردشده ذخیره نشد؛ رسانه دریافت شد و برای اطمینان دوباره وارد نکنید.');
                    }
                    return ['ok' => true, 'message' => $notice,
                        'quota' => MediaManager::getUserQuotaSummary($user['username'])];
                } catch (Throwable $e) {
                    // If message creation failed, delete only an unreferenced
                    // encrypted upload; never remove a message already visible.
                    MediaManager::deleteMediaById((string)$media['id'], $user['username']);
                    throw $e;
                }
            });
        json_response($result, $result['ok'] ? 200 : 400);
    }

    // ========================================================================
    // Personal, encrypted sticker collection
    // ========================================================================
    case 'sticker_list': {
        $user = Auth::requireUser();
        json_response(['ok' => true, 'stickers' => StickerStore::listFor($user['username'])]);
    }

    case 'sticker_save': {
        $user = Auth::requireUser();
        $res = StickerStore::add($user['username'], (string)($req['media_id'] ?? ''), (string)($req['label'] ?? ''));
        json_response($res, $res['ok'] ? 200 : 400);
    }

    case 'sticker_remove': {
        $user = Auth::requireUser();
        $res = StickerStore::remove($user['username'], (string)($req['media_id'] ?? ''));
        $res['quota'] = MediaManager::getUserQuotaSummary($user['username']);
        json_response($res, $res['ok'] ? 200 : 400);
    }

    case 'my_media_list': {
        $user = Auth::requireUser();
        $res = MediaManager::listOwnedMedia($user['username'], (string)($_GET['cursor'] ?? ''), (int)($_GET['limit'] ?? 30));
        json_response($res, $res['ok'] ? 200 : 400);
    }

    case 'purge_my_media': {
        $user = Auth::requireUser();
        $scope = (string)($req['scope'] ?? '');
        $ids = $req['media_ids'] ?? null;
        if (!in_array($scope, ['me', 'both'], true) || !is_array($ids) || count($ids) < 1 || count($ids) > 20 ||
            (string)($req['confirmation'] ?? '') !== 'MEDIA') {
            json_response(['ok' => false, 'error' => 'دامنه حذف، حداکثر ۲۰ شناسه و عبارت تأیید MEDIA لازم است.'], 400);
        }
        if ($scope === 'both') {
            $full = Auth::getUser($user['username'], true);
            if (!$full || !Auth::verifyPassword((string)$full['password'], (string)($req['password'] ?? ''))) {
                json_response(['ok' => false, 'error' => 'برای حذف رسانه از نمای دیگران، رمز فعلی حساب را درست وارد کنید.'], 403);
            }
        }
        $results = [];
        foreach (array_unique($ids) as $id) {
            $results[] = MediaManager::removeOwnedMediaEverywhere($user['username'], (string)$id, $scope);
        }
        json_response(['ok' => true, 'results' => $results,
            'quota' => MediaManager::getUserQuotaSummary($user['username'])]);
    }

    // ========================================================================
    // Chunked Media Upload
    // ========================================================================
    case 'upload_chunk': {
        $user = Auth::requireUser();
        $res = MediaManager::handleChunkUpload($user, $_POST, $_FILES);
        if (!$res['ok']) {
            json_response($res, 400);
        }
        $res['quota'] = MediaManager::getUserQuotaSummary($user['username']);
        json_response($res);
    }

    // Clean up an uploaded file that was NOT attached to any message or note
    case 'delete_unused_upload': {
        $user = Auth::requireUser();
        $id = (string)($req['media_id'] ?? '');
        $deleted = MediaManager::deleteMediaById($id, $user['username']);
        json_response(['ok' => $deleted]);
    }

    // ========================================================================
    // Message & Chat Deletion / Edit / React / Pin / Forward
    // ========================================================================
    case 'edit_message': {
        $user = Auth::requireUser();
        $chatId = (string)($req['chat_id'] ?? '');
        $seq = (int)($req['seq'] ?? 0);
        $text = (string)($req['text'] ?? '');
        if (!JsonShardStore::canUserAccessChat($user['username'], $chatId, true)) {
            json_response(['ok' => false, 'error' => 'دسترسی غیرمجاز'], 403);
        }
        $res = JsonShardStore::editMessage($chatId, $seq, $text, $user['username'], ($user['role'] === 'admin'));
        json_response($res, $res['ok'] ? 200 : 400);
    }

    case 'delete_message': {
        $user = Auth::requireUser();
        $chatId = (string)($req['chat_id'] ?? '');
        $seq = (int)($req['seq'] ?? 0);
        if (!JsonShardStore::canUserAccessChat($user['username'], $chatId, false)) {
            json_response(['ok' => false, 'error' => 'دسترسی غیرمجاز'], 403);
        }
        $scope = (string)($req['scope'] ?? 'both');
        if (JsonShardStore::getMessageBySeq($chatId, $seq, $user['username']) === null) {
            json_response(['ok' => false, 'error' => 'این پیام برای شما قابل مشاهده نیست.'], 404);
        }
        $res = JsonShardStore::deleteMessage($chatId, $seq, $user['username'], ($user['role'] === 'admin'), $scope);
        $res['quota'] = MediaManager::getUserQuotaSummary($user['username']);
        json_response($res, $res['ok'] ? 200 : 400);
    }

    case 'clear_chat_history': {
        $user = Auth::requireUser();
        $chatId = (string)($req['chat_id'] ?? '');
        $res = JsonShardStore::clearChatHistory($chatId, $user, (string)($req['scope'] ?? 'me'));
        if (!$res['ok']) {
            json_response($res, 403);
        }
        $knownPeers = [];
        $res['dialogs'] = JsonShardStore::getUserDialogList($user['username'], $knownPeers);
        json_response($res);
    }

    case 'hide_dialog': {
        $user = Auth::requireUser();
        $chatId = (string)($req['chat_id'] ?? '');
        $res = JsonShardStore::hideDialogForMe($chatId, $user['username'], !empty($req['remove_contact']));
        if (!$res['ok']) json_response($res, 403);
        $knownPeers = [];
        $res['dialogs'] = JsonShardStore::getUserDialogList($user['username'], $knownPeers);
        json_response($res);
    }

    case 'dialog_preference': {
        $user = Auth::requireUser();
        $chatId = (string)($req['chat_id'] ?? '');
        if (!JsonShardStore::canUserAccessChat($user['username'], $chatId, false)) {
            json_response(['ok' => false, 'error' => 'دسترسی به گفتگو مجاز نیست.'], 403);
        }
        $key = (string)($req['key'] ?? '');
        if (!in_array($key, ['muted', 'pinned', 'archived'], true)) {
            json_response(['ok' => false, 'error' => 'گزینه گفتگو نامعتبر است.'], 400);
        }
        if (strpos($chatId, 'saved_') === 0 || strpos($chatId, 'ai_') === 0) {
            json_response(['ok' => false, 'error' => 'این گزینه برای فضای شخصی کاربرد ندارد.'], 400);
        }
        DialogView::preference($user['username'], $chatId, $key, !empty($req['value']));
        $knownPeers = [];
        json_response(['ok' => true, 'dialogs' => JsonShardStore::getUserDialogList($user['username'], $knownPeers)]);
    }

    case 'delete_room': {
        $user = Auth::requireUser();
        $roomId = (string)($req['room_id'] ?? '');
        $res = JsonShardStore::deleteRoom($roomId, $user);
        if (!$res['ok']) {
            json_response($res, 403);
        }
        $knownPeers = [];
        $res['dialogs'] = JsonShardStore::getUserDialogList($user['username'], $knownPeers);
        json_response($res);
    }

    case 'react_message': {
        $user = Auth::requireUser();
        $chatId = (string)($req['chat_id'] ?? '');
        $seq = (int)($req['seq'] ?? 0);
        $emoji = (string)($req['emoji'] ?? '❤️');
        if (!JsonShardStore::canUserAccessChat($user['username'], $chatId, false) ||
            (strpos($chatId, 'dm_') === 0 && !JsonShardStore::canUserAccessChat($user['username'], $chatId, true))) {
            json_response(['ok' => false, 'error' => 'ارتباط با این کاربر مسدود شده است.'], 403);
        }
        if (JsonShardStore::getMessageBySeq($chatId, $seq, $user['username']) === null) {
            json_response(['ok' => false, 'error' => 'پیام برای شما قابل مشاهده نیست.'], 404);
        }
        $res = JsonShardStore::toggleReaction($chatId, $seq, $emoji, $user['username']);
        json_response($res, $res['ok'] ? 200 : 400);
    }

    case 'pin_message': {
        $user = Auth::requireUser();
        $chatId = (string)($req['chat_id'] ?? '');
        $seq = isset($req['seq']) && (int)$req['seq'] > 0 ? (int)$req['seq'] : null;
        if (!JsonShardStore::canUserAccessChat($user['username'], $chatId, true)) {
            json_response(['ok' => false, 'error' => 'دسترسی غیرمجاز'], 403);
        }
        if ($seq !== null && JsonShardStore::getMessageBySeq($chatId, $seq, $user['username']) === null) {
            json_response(['ok' => false, 'error' => 'پیام برای شما قابل مشاهده نیست.'], 404);
        }
        $res = JsonShardStore::pinMessage($chatId, $seq);
        json_response($res, $res['ok'] ? 200 : 400);
    }

    case 'forward_message': {
        $user = Auth::requireUser();
        $fromChat = (string)($req['from_chat_id'] ?? '');
        $seq = (int)($req['seq'] ?? 0);
        $toChat = (string)($req['to_chat_id'] ?? JsonShardStore::makeSavedChatId($user['username']));

        if (!JsonShardStore::canUserAccessChat($user['username'], $fromChat, false) ||
            !JsonShardStore::canUserAccessChat($user['username'], $toChat, true)) {
            json_response(['ok' => false, 'error' => 'دسترسی به گفتگو مجاز نیست.'], 403);
        }

        $orig = JsonShardStore::getMessageBySeq($fromChat, $seq, $user['username']);
        if ($orig === null) {
            json_response(['ok' => false, 'error' => 'پیام اصلی یافت نشد.'], 404);
        }
        if (!empty($orig['media']['id']) && !empty(MediaManager::getUserQuotaSummary($user['username'])['media_blocked'])) {
            json_response(['ok' => false, 'code' => 'quota_media_blocked', 'error' => 'تا زمانی که مصرف رسانه زیر ۸۰٪ نیاید، فقط پیام متنی می‌توانید بفرستید.'], 403);
        }

        $isToSaved = ($toChat === JsonShardStore::makeSavedChatId($user['username']));
        $msg = JsonShardStore::appendMessage($toChat, [
            'sender' => $user['username'],
            'sender_name' => $user['display_name'],
            'type' => $orig['type'] ?? 'text',
            'text' => $orig['text'] ?? '',
            'media' => $orig['media'] ?? null,
            'forwarded_from' => [
                'sender_name' => strpos($fromChat, 'channel_') === 0
                    ? (JsonShardStore::ensureDefaultRooms()[$fromChat]['title'] ?? 'کانال')
                    : ($orig['sender_name'] ?? $orig['sender'] ?? 'کاربر'),
                'chat_id' => $fromChat,
                'original_date' => $orig['created_at'] ?? time(),
            ],
        ]);

        if (strpos($toChat, 'dm_') === 0) DialogView::reopen($user['username'], $toChat);
        if ($isToSaved && (!empty($orig['media']) || trim((string)($orig['text'] ?? '')) !== '')) {
            MediaManager::addDriveItem($user['username'], [
                'folder_id' => !empty($orig['media']) ? 'gallery' : 'notes',
                'title' => trim((string)($orig['text'] ?? '')) !== ''
                    ? mb_substr(trim((string)$orig['text']), 0, 80)
                    : ($orig['media']['original_name'] ?? 'مورد ذخیره‌شده'),
                'note' => (string)($orig['text'] ?? ''),
                'media' => $orig['media'] ?? null,
            ]);
        }

        json_response(['ok' => true, 'message' => $msg]);
    }

    case 'search_chat': {
        $user = Auth::requireUser();
        $chatId = (string)($_GET['chat_id'] ?? $req['chat_id'] ?? '');
        $query = mb_substr((string)($_GET['q'] ?? $req['q'] ?? ''), 0, 120);
        $category = (string)($_GET['category'] ?? $req['category'] ?? '');
        if (!JsonShardStore::canUserAccessChat($user['username'], $chatId, false)) {
            json_response(['ok' => false, 'error' => 'دسترسی غیرمجاز'], 403);
        }
        $before = isset($_GET['before_seq']) ? (int)$_GET['before_seq'] : null;
        $page = JsonShardStore::searchMessages($chatId, $query, $category, 80, $user['username'], $before);
        json_response(['ok' => true] + $page);
    }

    // ========================================================================
    // Rooms & Complete User Settings (Profile, Username Change, Password, Sessions)
    // ========================================================================
    case 'create_room': {
        $user = Auth::requireUser();
        $title = (string)($req['title'] ?? '');
        $type = (string)($req['type'] ?? 'group');
        $members = is_array($req['members'] ?? null) ? $req['members'] : [];
        $desc = (string)($req['description'] ?? '');
        $onlyAdmins = !empty($req['only_admins_post']);

        $res = JsonShardStore::createRoom($user['username'], $title, $type, $members, $desc, $onlyAdmins,
            !empty($req['is_public']), (string)($req['public_handle'] ?? ''));
        json_response($res, $res['ok'] ? 200 : (($res['code'] ?? '') === 'duplicate_id' ? 409 : 400));
    }

    case 'find_public_room': {
        $user = Auth::requireUser();
        if ($user['role'] !== 'admin' && !Auth::allowContactLookup($user['username'])) {
            json_response(['ok' => false, 'error' => 'جست‌وجوی آیدی‌ها بیش از حد شده؛ چند دقیقه بعد تلاش کنید.'], 429);
        }
        $result = RoomMembership::findPublicRoom((string)($_GET['id'] ?? ''), $user);
        json_response($result, $result['ok'] ? 200 : 404);
    }

    case 'search_public_rooms': {
        $user = Auth::requireUser();
        if ($user['role'] !== 'admin' && !Auth::allowContactLookup($user['username'])) {
            json_response(['ok' => false, 'error' => 'تعداد جستجوها زیاد است؛ ۱۰ دقیقهٔ دیگر تلاش کنید.'], 429);
        }
        $result = RoomMembership::searchPublicRooms((string)($_GET['q'] ?? ''), $user);
        json_response($result, $result['ok'] ? 200 : 400);
    }

    case 'room_info': {
        $user = Auth::requireUser();
        $result = RoomMembership::getRoomInfo((string)($_GET['room_id'] ?? ''), $user);
        json_response($result, $result['ok'] ? 200 : 403);
    }

    case 'join_room': {
        $user = Auth::requireUser();
        $result = RoomMembership::joinRoom((string)($req['room_id'] ?? ''), (string)($req['invite_token'] ?? ''), $user);
        json_response($result, $result['ok'] ? 200 : 403);
    }

    case 'leave_room': {
        $user = Auth::requireUser();
        $result = RoomMembership::leaveRoom((string)($req['room_id'] ?? ''), $user);
        json_response($result, $result['ok'] ? 200 : 403);
    }

    case 'room_member': {
        $user = Auth::requireUser();
        $result = RoomMembership::manageMember((string)($req['room_id'] ?? ''), (string)($req['member_id'] ?? ''),
            $user, ($req['op'] ?? '') === 'remove');
        json_response($result, $result['ok'] ? 200 : 403);
    }

    case 'update_room': {
        $user = Auth::requireUser();
        $result = RoomMembership::updateRoom((string)($req['room_id'] ?? ''), $user, $req);
        json_response($result, $result['ok'] ? 200 : (($result['code'] ?? '') === 'duplicate_id' ? 409 : 403));
    }

    case 'update_profile': {
        $user = Auth::requireUser();
        $cfg = get_app_config();
        $oldUsername = $user['username'];
        $requestedUsername = isset($req['new_username']) ? normalize_username((string)$req['new_username']) : $oldUsername;

        if ($user['role'] !== 'admin' && empty($cfg['allow_user_profile_edit'])) {
            if (isset($req['display_name']) && (string)$req['display_name'] !== $user['display_name'] ||
                isset($req['bio']) && (string)$req['bio'] !== $user['bio'] ||
                isset($req['avatar_color']) && (string)$req['avatar_color'] !== $user['avatar_color'] ||
                array_key_exists('avatar_media_id', $req)) {
                json_response(['ok' => false, 'error' => 'ویرایش پروفایل توسط مدیر غیرفعال شده است؛ تغییر رمز عبور همچنان امکان‌پذیر است.'], 403);
            }
        }

        $avatarChange = array_key_exists('avatar_media_id', $req);
        $newAvatarId = $avatarChange ? (string)$req['avatar_media_id'] : '';
        if ($avatarChange && $newAvatarId !== '') {
            $m = MediaManager::getMediaMeta($newAvatarId);
            if ($m === null || ($m['owner'] ?? '') !== $oldUsername || ($m['category'] ?? '') !== 'image') {
                json_response(['ok' => false, 'error' => 'تصویر پروفایل معتبر یا متعلق به شما نیست.'], 400);
            }
        }

        // 1. Verify password first if changing password
        $newPass = trim((string)($req['new_password'] ?? ''));
        if ($newPass !== '') {
            $full = Auth::getUser($oldUsername, true);
            $curPass = (string)($req['current_password'] ?? '');
            if ($full === null || !Auth::verifyPassword($full['password'], $curPass)) {
                json_response(['ok' => false, 'error' => 'رمز عبور فعلی وارد شده اشتباه است.'], 400);
            }
            if (strlen($newPass) < 8) {
                json_response(['ok' => false, 'error' => 'رمز عبور جدید باید حداقل ۸ کاراکتر باشد.'], 400);
            }
        }

        // 2. Handle Username (ID) change if requested
        $effectiveUsername = $oldUsername;
        if ($requestedUsername !== '' && $requestedUsername !== $oldUsername) {
            if (empty($cfg['allow_user_username_change']) && $user['role'] !== 'admin') {
                json_response(['ok' => false, 'error' => 'تغییر آیدی کاربری توسط مدیر غیرفعال شده است.'], 403);
            }
            $ren = Auth::renameUsername($oldUsername, $requestedUsername);
            if (!$ren['ok']) {
                json_response($ren, 400);
            }
            $effectiveUsername = $requestedUsername;
        }

        // 3. Save profile fields & optional new password
        $update = [
            'username' => $effectiveUsername,
            'display_name' => $req['display_name'] ?? $user['display_name'],
            'bio' => $req['bio'] ?? $user['bio'],
            'avatar_color' => $req['avatar_color'] ?? $user['avatar_color'],
        ];
        $oldAvatarId = '';
        if (preg_match('/^api\.php\?action=media&id=([a-zA-Z0-9_]+)$/', (string)($user['avatar_url'] ?? ''), $aMatch)) {
            $oldAvatarId = $aMatch[1];
        }
        if ($avatarChange) {
            $update['avatar_url'] = $newAvatarId !== '' ? ('api.php?action=media&id=' . $newAvatarId) : '';
        }
        if ($newPass !== '') {
            $update['password'] = $newPass;
        }

        $res = Auth::saveUserRecord($update, false);
        if (!$res['ok']) {
            json_response($res, 400);
        }
        if ($avatarChange) {
            if ($newAvatarId !== '') MediaManager::setProfileAvatar($newAvatarId, $effectiveUsername);
            if ($oldAvatarId !== '' && $oldAvatarId !== $newAvatarId) {
                MediaManager::removeProfileAvatar($oldAvatarId, $effectiveUsername);
            }
        }
        if ($newPass !== '') {
            Auth::refreshCurrentSessionFingerprint($effectiveUsername);
            Auth::terminateOtherSessions($effectiveUsername);
        }

        $knownPeers = [];
        $dialogs = JsonShardStore::getUserDialogList($effectiveUsername, $knownPeers);
        $visibleUsers = Auth::getVisibleUsersFor($res['user'], $knownPeers);

        json_response([
            'ok' => true,
            'user' => $res['user'],
            'old_username' => $oldUsername,
            'dialogs' => $dialogs,
            'users' => $visibleUsers,
        ]);
    }

    case 'my_sessions': {
        $user = Auth::requireUser();
        json_response([
            'ok' => true,
            'sessions' => Auth::getUserSessions($user['username']),
        ]);
    }

    case 'terminate_other_sessions': {
        $user = Auth::requireUser();
        $count = Auth::terminateOtherSessions($user['username']);
        json_response(['ok' => true, 'terminated_count' => $count]);
    }

    // ========================================================================
    // Personal Cloud Storage Drive
    // ========================================================================
    case 'drive_list': {
        $user = Auth::requireUser();
        $folder = (string)($_GET['folder_id'] ?? $req['folder_id'] ?? 'all');
        $beforeId = (string)($_GET['before_item_id'] ?? $req['before_item_id'] ?? '');
        $limit = max(10, min(150, (int)($_GET['limit'] ?? 80)));
        $drive = MediaManager::getPersonalDrive($user['username'], $folder, $beforeId !== '' ? $beforeId : null, $limit);
        $drive['ok'] = true;
        json_response($drive);
    }

    case 'drive_create_folder': {
        $user = Auth::requireUser();
        $name = (string)($req['name'] ?? '');
        $color = (string)($req['color'] ?? '#2AABEE');
        $res = MediaManager::createDriveFolder($user['username'], $name, $color);
        json_response($res, $res['ok'] ? 200 : 400);
    }

    case 'drive_delete_folder': {
        $user = Auth::requireUser();
        $fid = (string)($req['folder_id'] ?? '');
        $res = MediaManager::deleteDriveFolder($user['username'], $fid);
        json_response($res, $res['ok'] ? 200 : 400);
    }

    case 'drive_add_item': {
        $user = Auth::requireUser();
        $mediaMeta = null;
        if (!empty($req['media_id'])) {
            $m = MediaManager::getMediaMeta((string)$req['media_id']);
            if ($m === null || !MediaManager::canUserViewMedia($user, (string)$req['media_id'])) {
                json_response(['ok' => false, 'error' => 'دسترسی به فایل مورد نظر امکان‌پذیر نیست.'], 403);
            }
            $mediaMeta = MediaManager::publicMediaMeta($m);
        }
        $res = MediaManager::addDriveItem($user['username'], [
            'folder_id' => $req['folder_id'] ?? 'notes',
            'title' => $req['title'] ?? '',
            'note' => $req['note'] ?? '',
            'media' => $mediaMeta,
            'starred' => !empty($req['starred']),
        ]);
        if ($res['ok'] && !empty($req['also_send_saved'])) {
            $savedId = JsonShardStore::makeSavedChatId($user['username']);
            $noteTxt = trim(($res['item']['title'] ?? '') . "\n" . ($res['item']['note'] ?? ''));
            JsonShardStore::appendMessage($savedId, [
                'sender' => $user['username'],
                'sender_name' => $user['display_name'],
                'type' => ($mediaMeta !== null) ? 'media' : 'text',
                'text' => $noteTxt,
                'media' => $mediaMeta,
            ]);
        }
        json_response($res, $res['ok'] ? 200 : 400);
    }

    case 'drive_update_item': {
        $user = Auth::requireUser();
        $itemId = (string)($req['item_id'] ?? '');
        $op = (string)($req['op'] ?? 'toggle_star');
        $deleteFile = !empty($req['delete_binary']);
        $res = MediaManager::updateDriveItem($user['username'], $itemId, $op, $deleteFile);
        $res['quota'] = MediaManager::getUserQuotaSummary($user['username']);
        json_response($res, $res['ok'] ? 200 : 400);
    }

    case 'drive_clear_all': {
        $user = Auth::requireUser();
        $res = MediaManager::clearPersonalDrive($user['username']);
        $res['quota'] = MediaManager::getUserQuotaSummary($user['username']);
        json_response($res);
    }

    case 'reset_my_data': {
        $user = Auth::requireUser();
        $full = Auth::getUser($user['username'], true);
        if (($req['confirmation'] ?? '') !== 'RESET' || $full === null ||
            !Auth::verifyPassword((string)$full['password'], (string)($req['current_password'] ?? ''))) {
            json_response(['ok' => false, 'error' => 'برای ریست کامل، عبارت تأیید و رمز فعلی حساب ضروری است.'], 403);
        }
        $res = reset_account_content($user);
        Auth::terminateOtherSessions($user['username']);
        $res['user'] = Auth::getUser($user['username']);
        json_response($res);
    }

    // ========================================================================
    // Admin Management (Full User Control, Web Tools Manager, JSON Shards, AI)
    // ========================================================================
    case 'admin_overview': {
        $admin = Auth::requireAdmin();
        $usersList = Auth::getAllUsers(false);
        foreach ($usersList as $uname => &$u) {
            $used = MediaManager::getUserUsedBytes($uname);
            $u['used_bytes'] = $used;
            $u['used_human'] = format_bytes_fa($used);
        }
        unset($u);

        $rawUsersJson = @file_get_contents(DATA_DIR . '/users.json') ?: '[]';
        $shardsReport = JsonShardStore::getAllShardsReport();
        $cfg = get_app_config();

        json_response([
            'ok' => true,
            'users' => array_values($usersList),
            'raw_users_json' => $rawUsersJson,
            'shards_report' => $shardsReport,
            'config' => $cfg,
        ]);
    }

    case 'admin_account_activity': {
        $admin = Auth::requireAdmin();
        if ($admin['username'] !== Auth::getPrimaryAdminId()) {
            json_response(['ok' => false, 'error' => 'گزارش استفاده از حساب‌ها فقط برای مدیر اصلی نصب در دسترس است.'], 403);
        }
        json_response(['ok' => true, 'report' => Auth::getAccountActivityReport()]);
    }

    case 'admin_save_user': {
        $admin = Auth::requireAdmin();
        $isNew = !empty($req['is_new']);
        $res = Auth::saveUserRecord($req, $isNew);
        if (!$res['ok']) {
            json_response($res, ($res['code'] ?? '') === 'duplicate_id' ? 409 : 400);
        }
        if (!$isNew && !empty($req['password'])) {
            if (normalize_username((string)($req['original_username'] ?? $req['username'] ?? '')) === $admin['username']) {
                Auth::refreshCurrentSessionFingerprint($res['user']['username']);
            }
            Auth::terminateOtherSessions($res['user']['username']);
        }
        // Refresh admin's own session user object if admin edited themselves
        $freshAdmin = Auth::currentUser();
        json_response(['ok' => true, 'user' => $res['user'], 'current_admin' => $freshAdmin]);
    }

    case 'admin_reset_user_data': {
        $admin = Auth::requireAdmin();
        $target = normalize_username((string)($req['username'] ?? ''));
        if ($target === $admin['username'] || ($req['confirmation'] ?? '') !== $target) {
            json_response(['ok' => false, 'error' => 'برای ریست حساب دیگر، آیدی هدف باید صریحاً تأیید شود.'], 400);
        }
        $owner = Auth::getUser($target);
        if ($owner === null) json_response(['ok' => false, 'error' => 'کاربر یافت نشد.'], 404);
        $res = reset_account_content($owner);
        Auth::terminateOtherSessions($target);
        json_response($res);
    }

    case 'admin_delete_user': {
        $admin = Auth::requireAdmin();
        $target = normalize_username((string)($req['username'] ?? ''));
        $targetObj = Auth::getUser($target);
        $res = Auth::deleteUserRecord($target, $admin['username']);
        if ($res['ok']) {
            JsonShardStore::purgePrivateDataForDeletedUser($target);
            MediaManager::clearPersonalDrive($target);
            StickerStore::clearAccount($target);
            if (preg_match('/^api\.php\?action=media&id=([a-zA-Z0-9_]+)$/', (string)($targetObj['avatar_url'] ?? ''), $match)) {
                MediaManager::removeProfileAvatar($match[1], $target);
            }
            JsonShardStore::anonymizeDeletedSender($target);
            DialogView::deleteAccount($target);
            MediaManager::retireDeletedOwner($target);
            MediaManager::removeDeletedAccountDrive($target);
        }
        json_response($res, $res['ok'] ? 200 : 400);
    }

    case 'admin_save_raw_users_json': {
        $admin = Auth::requireAdmin();
        $raw = (string)($req['raw_json'] ?? '');
        $decoded = json_decode($raw, true);
        if (!is_array($decoded) || json_last_error() !== JSON_ERROR_NONE) {
            json_response(['ok' => false, 'error' => 'ساختار فایل JSON معتبر نیست: ' . json_last_error_msg()], 400);
        }
        // Validation and the final write share the users lock. Otherwise an
        // account can be created/renamed between the uniqueness check and write.
        $result = with_file_lock('users', function () use ($decoded): array {
            $hasAdmin = false;
            $seenUsernames = [];
            $previousUsers = Auth::getAllUsers(false);
            foreach ($decoded as &$item) {
                if (!is_array($item)) {
                    return ['ok' => false, 'error' => 'هر حساب کاربری باید به صورت یک آبجکت JSON تعریف شود.'];
                }
                $uname = normalize_username((string)($item['username'] ?? ''));
                if ($uname === '' || $uname !== strtolower((string)($item['username'] ?? '')) ||
                    strlen($uname) < 3 || strlen($uname) > 32 || strpos($uname, '__') !== false ||
                    !preg_match('/^[a-z0-9][a-z0-9_.-]*$/D', $uname) || empty($item['password'])) {
                    return ['ok' => false, 'error' => 'هر آیدی باید معتبر و دارای رمز عبور باشد.'];
                }
                if (isset($seenUsernames[$uname])) {
                    return ['ok' => false, 'code' => 'duplicate_id', 'error' => 'آیدی @' . $uname . ' در فهرست کاربران تکرار شده است.'];
                }
                if (!isset($previousUsers[$uname]) && Auth::hasLegacyDataForId($uname)) {
                    return ['ok' => false, 'error' => 'آیدی @' . $uname . ' هنوز به داده‌های حساب قبلی متصل است و قابل استفادهٔ دوباره نیست.'];
                }
                $seenUsernames[$uname] = true;
                if (($item['role'] ?? '') === 'admin' && ($item['active'] ?? true)) $hasAdmin = true;
                // Admin JSON editing is not an exemption from password hashing.
                if (!is_string($item['password'])) return ['ok' => false, 'error' => 'رمز باید رشته باشد.'];
                if (!preg_match('/^\$(2y|2a|argon2)/', $item['password'])) {
                    if (strlen($item['password']) < 8) return ['ok' => false, 'error' => 'رمز خام باید دست‌کم ۸ کاراکتر باشد.'];
                    $item['password'] = password_hash($item['password'], PASSWORD_BCRYPT);
                }
            }
            unset($item);
            if (!$hasAdmin) return ['ok' => false, 'error' => 'حداقل یک کاربر فعال با نقش admin باید در فایل users.json وجود داشته باشد.'];
            $primary = Auth::getPrimaryAdminId();
            $primaryRecord = null;
            foreach ($decoded as $candidate) {
                if ($candidate['username'] === $primary) $primaryRecord = $candidate;
            }
            if ($primary !== '' && (!$primaryRecord || ($primaryRecord['role'] ?? '') !== 'admin' || (array_key_exists('active', $primaryRecord) && !$primaryRecord['active']))) {
                return ['ok' => false, 'error' => 'حساب مدیر اصلی نصب باید با همان آیدی، نقش مدیر و وضعیت فعال باقی بماند.'];
            }
            $newIds = array_diff(array_keys($seenUsernames), array_keys($previousUsers));
            $removedIds = array_diff(array_keys($previousUsers), array_keys($seenUsernames));
            Auth::recordCreatedAccounts($newIds);
            if (!write_json_atomic(DATA_DIR . '/users.json', array_values($decoded), true)) {
                throw new RuntimeException('فایل کاربران ذخیره نشد.');
            }
            Auth::removeAccountActivity($removedIds);
            return ['ok' => true];
        });
        json_response($result, !empty($result['ok']) ? 200 : (($result['code'] ?? '') === 'duplicate_id' ? 409 : 400));
    }

    case 'admin_save_config': {
        $admin = Auth::requireAdmin();
        $current = get_app_config();

        if (isset($req['app_title'])) {
            $current['app_title'] = mb_substr(trim((string)$req['app_title']), 0, 50) ?: 'چت امن';
        }
        if (array_key_exists('github_url', $req)) {
            $url = trim((string)$req['github_url']);
            if ($url !== '' && !preg_match('~^https://github\\.com/[a-zA-Z0-9_.-]+/[a-zA-Z0-9_.-]+/?$~D', $url)) {
                json_response(['ok' => false, 'error' => 'لینک مخزن باید مثل https://github.com/owner/repo و بدون پارامتر اضافی باشد.'], 400);
            }
            $current['github_url'] = $url;
        }
        if (isset($req['app_subtitle'])) {
            $current['app_subtitle'] = mb_substr(trim((string)$req['app_subtitle']), 0, 80);
        }
        if (isset($req['max_shard_bytes'])) {
            $current['max_shard_bytes'] = max(16384, min(10485760, (int)$req['max_shard_bytes']));
        }
        if (isset($req['max_shard_messages'])) {
            $current['max_shard_messages'] = max(10, min(5000, (int)$req['max_shard_messages']));
        }
        if (isset($req['max_upload_mb'])) {
            $current['max_upload_mb'] = max(5, min(2048, (int)$req['max_upload_mb']));
        }
        if (isset($req['video_compress_threshold_mb'])) {
            $current['video_compress_threshold_mb'] = max(1, min(500, (int)$req['video_compress_threshold_mb']));
        }
        if (isset($req['allow_user_profile_edit'])) {
            $current['allow_user_profile_edit'] = (bool)$req['allow_user_profile_edit'];
        }
        if (isset($req['allow_user_username_change'])) {
            $current['allow_user_username_change'] = (bool)$req['allow_user_username_change'];
        }
        if (isset($req['ai_bridge']) && is_array($req['ai_bridge'])) {
            $current['ai_bridge'] = array_merge($current['ai_bridge'], $req['ai_bridge']);
        }
        if (isset($req['web_tools']) && is_array($req['web_tools'])) {
            $sanitizedTools = [];
            foreach (array_slice($req['web_tools'], 0, 20) as $wt) {
                if (!is_array($wt)) continue;
                $cmd = trim((string)($wt['command'] ?? ''));
                if ($cmd === '') continue;
                if ($cmd[0] !== '/') $cmd = '/' . $cmd;
                $sanitizedTools[] = [
                    'id' => preg_replace('/[^a-zA-Z0-9_\-]/', '', (string)($wt['id'] ?? '')) ?: ('tool_' . substr(bin2hex(random_bytes(3)), 0, 6)),
                    'title' => mb_substr(trim((string)($wt['title'] ?? 'ابزار جدید')), 0, 50),
                    'icon' => mb_substr(trim((string)($wt['icon'] ?? '🔹')), 0, 6) ?: '🔹',
                    'command' => mb_substr($cmd, 0, 24),
                    'type' => in_array(($wt['type'] ?? ''), ['builtin_status', 'wikipedia', 'duckduckgo', 'url_reader', 'custom_url', 'telegram_public', 'domestic_rates'], true)
                        ? $wt['type']
                        : 'custom_url',
                    'lang' => mb_substr(trim((string)($wt['lang'] ?? 'fa')), 0, 10),
                    'url_template' => mb_substr(trim((string)($wt['url_template'] ?? '')), 0, 1024),
                    'placeholder' => mb_substr(trim((string)($wt['placeholder'] ?? '')), 0, 60),
                    'quick_chip' => !empty($wt['quick_chip']),
                    'enabled' => !empty($wt['enabled']),
                ];
            }
            $current['web_tools'] = $sanitizedTools;
        }

        save_app_config($current);
        json_response(['ok' => true, 'config' => $current]);
    }

    default:
        json_response(['ok' => false, 'error' => 'عملیات درخواستی نامعتبر است.'], 400);
}
