<?php
declare(strict_types=1);
require_once __DIR__ . '/core/bootstrap.php';

header('Cache-Control: no-store, no-cache, must-revalidate');
header('X-Content-Type-Options: nosniff');
header('Referrer-Policy: same-origin');
header("Content-Security-Policy: default-src 'self'; script-src 'self'; style-src 'self' 'unsafe-inline'; font-src 'self'; img-src 'self' data: blob:; media-src 'self' blob:; connect-src 'self'; worker-src 'self' blob:; object-src 'none'; base-uri 'self'; form-action 'self'");

$cfg = get_app_config();
$appTitle = htmlspecialchars((string)$cfg['app_title'], ENT_QUOTES, 'UTF-8');
$appSubtitle = htmlspecialchars((string)$cfg['app_subtitle'], ENT_QUOTES, 'UTF-8');
$githubUrl = (string)($cfg['github_url'] ?? '');
$githubUrl = preg_match('~^https://github\\.com/[a-zA-Z0-9_.-]+/[a-zA-Z0-9_.-]+/?$~D', $githubUrl)
    ? htmlspecialchars($githubUrl, ENT_QUOTES, 'UTF-8') : '';
$assetVer = '4.2.' . (@filemtime(__DIR__ . '/assets/js/app.js') ?: time());
?>
<!DOCTYPE html>
<html lang="fa" dir="rtl" data-theme="light" data-fontsize="normal" data-font="vazir">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0, viewport-fit=cover">
    <meta name="theme-color" content="#FFFFFF" id="metaThemeColor">
    <meta name="apple-mobile-web-app-capable" content="yes">
    <meta name="apple-mobile-web-app-status-bar-style" content="black-translucent">
    <meta name="apple-mobile-web-app-title" content="<?= $appTitle ?>">
    <title><?= $appTitle ?> — <?= $appSubtitle ?></title>
    <link rel="icon" type="image/svg+xml" href="assets/img/icon.svg">
    <link rel="apple-touch-icon" href="assets/img/icon-192.png">
    <link rel="manifest" href="manifest.php">
    <link rel="stylesheet" href="assets/css/app.css?v=<?= $assetVer ?>">
</head>
<body>
<?php include __DIR__ . '/assets/img/ui-icons.svg'; // trusted, locally bundled sprite ?>
<div id="bootScreen" class="boot-screen" role="status" aria-live="polite">
    <img src="assets/img/icon.svg" alt="" width="68" height="68">
    <strong><?= $appTitle ?></strong><span>در حال بررسی نشست شما…</span>
</div>
<noscript><p class="no-script">برای استفاده از پیام‌رسان، جاوااسکریپت مرورگر را فعال کنید.</p></noscript>

<!-- ====================================================================== -->
<!-- CLEAN AUTHENTICATION / LOGIN SCREEN (NO HINTS EXPOSED)                 -->
<!-- ====================================================================== -->
<div id="authScreen" class="auth-screen hidden">
    <div class="auth-card">
        <div class="auth-logo-wrap">
            <img src="assets/img/icon.svg" alt="<?= $appTitle ?>" class="auth-logo">
        </div>
        <h1 class="auth-title"><?= $appTitle ?></h1>
        <p class="auth-subtitle"><?= $appSubtitle ?></p>

        <form id="loginForm" class="auth-form" autocomplete="on">
            <div class="field-group">
                <label for="loginUsername">نام کاربری (ID)</label>
                <div class="input-with-icon">
                    <input type="text" id="loginUsername" name="username" class="ltr-input" dir="ltr" placeholder="Username" autocomplete="username" required>
                </div>
            </div>

            <div class="field-group">
                <label for="loginPassword">رمز عبور</label>
                <div class="input-with-icon password-wrap">
                    <input type="password" id="loginPassword" name="password" class="ltr-input" dir="ltr" placeholder="••••••••" autocomplete="current-password" required>
                    <button type="button" id="togglePassBtn" class="icon-btn-inline" title="نمایش / مخفی کردن رمز عبور"><svg class="ui-icon" aria-hidden="true"><use href="#ui-eye"></use></svg></button>
                </div>
            </div>

            <div id="loginError" class="auth-error hidden"></div>

            <button type="submit" id="loginSubmitBtn" class="btn-primary btn-block">
                <span>ورود به حساب کاربری</span>
            </button>
        </form>

        <div class="auth-footer">
            <span>🔒 پیام‌ها و فایل‌های تازه روی سرور با رمزنگاری محافظت می‌شوند؛ ورودتان هم با رمز شخصی شماست.</span>
            <?php if ($githubUrl !== ''): ?><a class="auth-source-link" href="<?= $githubUrl ?>" target="_blank" rel="noopener noreferrer">کد منبع «چت امن» در گیت‌هاب ↗</a><?php endif; ?>
        </div>
    </div>
</div>

<!-- ====================================================================== -->
<!-- MAIN TELEGRAM-STYLE RTL MESSENGER APPLICATION                          -->
<!-- ====================================================================== -->
<div id="appScreen" class="app-layout hidden">
    <div id="quotaAlertBanner" class="quota-alert-banner hidden" role="status" aria-live="polite">
        <span id="quotaAlertText">فضای رسانهٔ شما به ۸۰٪ رسیده؛ تا آزادسازی فضا فقط متن می‌توانید بفرستید.</span>
        <button type="button" id="quotaManageNowBtn" class="btn-secondary btn-sm">مدیریت رسانه‌ها</button>
    </div>

    <!-- RIGHT SIDEBAR (Dialogs, Folders, Add by ID, Menu) -->
    <aside id="sidebarPanel" class="sidebar">
        <div class="sidebar-header">
            <button type="button" id="menuBtn" class="icon-btn" title="منو و تنظیمات"><svg class="ui-icon" aria-hidden="true"><use href="#ui-menu"></use></svg></button>
            <div class="search-box">
                <svg class="search-icon" viewBox="0 0 24 24" width="17" height="17" fill="none" stroke="currentColor" stroke-width="2"><circle cx="11" cy="11" r="8"/><line x1="21" y1="21" x2="16.65" y2="16.65"/></svg>
                <input type="text" id="globalSearchInput" placeholder="جستجو در گفتگوهای من...">
                <button type="button" id="clearGlobalSearchBtn" class="search-clear hidden">×</button>
            </div>
            <button type="button" id="installTopBtn" class="icon-btn install-top-btn" title="نصب چت امن روی گوشی یا کامپیوتر" aria-label="نصب برنامه"><svg class="ui-icon"><use href="#ui-monitor-down"></use></svg></button>
            <button type="button" id="findByIdTopBtn" class="icon-btn accent-icon-btn" title="یافتن کاربر و شروع چت با آیدی (@ID)"><svg class="ui-icon" aria-hidden="true"><use href="#ui-contact"></use></svg></button>
        </div>

        <!-- Dropdown Main Menu -->
        <div id="mainMenuDropdown" class="dropdown-menu hidden">
            <div class="menu-user-card" id="menuUserCard">
                <div class="avatar avatar-md" id="menuUserAvatar">م</div>
                <div class="menu-user-meta">
                    <strong id="menuUserName">کاربر</strong>
                    <small id="menuUserUsername" class="ltr-inline">@username</small>
                </div>
            </div>
            <div class="menu-divider"></div>
            <button type="button" class="menu-item" id="menuFindByIdBtn">
                <svg class="ui-icon menu-ico" aria-hidden="true"><use href="#ui-search"></use></svg>
                <span>افزودن مخاطب با آیدی (ID)</span>
            </button>
            <button type="button" class="menu-item" id="menuSavedBtn">
                <svg class="ui-icon menu-ico" aria-hidden="true"><use href="#ui-bookmark"></use></svg>
                <span>پیام‌های ذخیره‌شده (Saved)</span>
            </button>
            <button type="button" class="menu-item" id="menuDriveBtn">
                <svg class="ui-icon menu-ico" aria-hidden="true"><use href="#ui-folder"></use></svg>
                <span>فضای ابری و پوشه‌های شخصی</span>
            </button>
            <button type="button" class="menu-item" id="menuAllMediaBtn">
                <svg class="ui-icon menu-ico" aria-hidden="true"><use href="#ui-image"></use></svg>
                <span>تمام رسانه‌های من و آزادسازی فضا</span>
            </button>
            <button type="button" class="menu-item" id="menuNewRoomBtn">
                <svg class="ui-icon menu-ico" aria-hidden="true"><use href="#ui-users-round"></use></svg>
                <span>ساخت گروه یا کانال جدید</span>
            </button>
            <button type="button" class="menu-item" id="menuFindRoomBtn">
                <svg class="ui-icon menu-ico" aria-hidden="true"><use href="#ui-search"></use></svg>
                <span>یافتن و پیوستن به گروه/کانال</span>
            </button>
            <button type="button" class="menu-item" id="menuSettingsBtn">
                <svg class="ui-icon menu-ico" aria-hidden="true"><use href="#ui-settings"></use></svg>
                <span>تنظیمات کامل حساب، رمز و امنیت</span>
            </button>
            <button type="button" class="menu-item" id="menuThemeBtn">
                <span class="menu-ico" id="menuThemeIco"><svg class="ui-icon" aria-hidden="true"><use href="#ui-sun"></use></svg></span>
                <span id="menuThemeLabel">انتخاب پوسته و رنگ گفتگو</span>
            </button>
            <button type="button" class="menu-item admin-only hidden" id="menuAdminBtn">
                <svg class="ui-icon menu-ico" aria-hidden="true"><use href="#ui-shield-check"></use></svg>
                <span>پنل مدیریت کل سامانه (Admin)</span>
            </button>
            <div class="menu-divider"></div>
            <button type="button" class="menu-item danger" id="menuLogoutBtn">
                <svg class="ui-icon menu-ico" aria-hidden="true"><use href="#ui-log-out"></use></svg>
                <span>خروج از حساب کاربری</span>
            </button>
        </div>

        <!-- Telegram-style Folder Tabs -->
        <div class="folder-tabs" id="folderTabs">
            <button type="button" class="folder-tab active" data-filter="all">همه</button>
            <button type="button" class="folder-tab" data-filter="personal">شخصی و دستیار</button>
            <button type="button" class="folder-tab" data-filter="groups">گروه‌ها و کانال‌ها</button>
            <button type="button" class="folder-tab" data-filter="dms">خصوصی</button>
            <button type="button" class="folder-tab" data-filter="archived">بایگانی</button>
        </div>

        <button type="button" id="roomDiscoveryBtn" class="room-discovery-btn hidden"><svg class="ui-icon"><use href="#ui-search"></use></svg> جستجوی گروه‌ها و کانال‌های عمومی</button>
        <!-- Dialogs List -->
        <div id="dialogList" class="dialog-list"></div>

        <!-- Sidebar Footer: Personal Cloud Quota & Settings Quick Buttons -->
        <div class="sidebar-footer">
            <div class="quota-mini" id="sidebarQuotaBtn" title="مدیریت فضای ابری شخصی">
                <div class="quota-mini-top">
                    <span>☁️ فضای ابری من</span>
                    <span id="sidebarQuotaText" class="ltr-inline">0 KB / 1 GB</span>
                </div>
                <div class="quota-bar"><div id="sidebarQuotaFill" class="quota-fill" style="width: 1%"></div></div>
            </div>
            <button type="button" id="sidebarSettingsQuickBtn" class="icon-btn" title="تنظیمات حساب و امنیت">⚙️</button>
            <button type="button" id="fabFindUserBtn" class="fab-mini" title="پیام جدید با وارد کردن آیدی کاربر">@+</button>
        </div>
    </aside>

    <!-- CENTER CHAT STAGE -->
    <main id="chatStage" class="chat-stage">
        <!-- Chat Top Header -->
        <header id="chatHeader" class="chat-header">
            <button type="button" id="mobileBackBtn" class="icon-btn mobile-only" title="بازگشت به لیست گفتگوها"><svg class="ui-icon" aria-hidden="true"><use href="#ui-arrow-right"></use></svg></button>

            <div class="chat-header-info" id="chatHeaderInfo">
                <div class="avatar avatar-md" id="activeChatAvatar">گ</div>
                <div class="chat-header-titles">
                    <div class="chat-title-row">
                        <h2 id="activeChatTitle">انتخاب گفتگو</h2>
                        <span id="shardLiveBadge" class="shard-badge" title="وضعیت قطعه JSON فعال">JSON #1</span>
                    </div>
                    <p id="activeChatSubtitle" class="chat-subtitle">آنلاین</p>
                </div>
            </div>

            <div class="chat-header-actions">
                <button type="button" id="installChatBtn" class="icon-btn install-top-btn" title="نصب چت امن؛ این دکمه همیشه در دسترس است" aria-label="نصب برنامه"><svg class="ui-icon"><use href="#ui-monitor-down"></use></svg></button>
                <button type="button" id="openPersonalDriveHeaderBtn" class="btn-chip hidden" title="مدیریت پوشه‌ها و فایل‌های شخصی">
                    ☁️ پوشه‌های شخصی
                </button>
                <button type="button" id="adminHeaderQuickBtn" class="icon-btn admin-only hidden" title="پنل مدیریت کل سامانه"><svg class="ui-icon" aria-hidden="true"><use href="#ui-shield-check"></use></svg></button>
                <button type="button" id="chatSearchToggleBtn" class="icon-btn" title="جستجو در پیام‌های این گفتگو"><svg class="ui-icon" aria-hidden="true"><use href="#ui-search"></use></svg></button>
                <button type="button" id="clearChatHeaderBtn" class="icon-btn" title="پاکسازی تاریخچه یا حذف گفتگو"><svg class="ui-icon" aria-hidden="true"><use href="#ui-trash-2"></use></svg></button>
                <button type="button" id="toggleInfoDrawerBtn" class="icon-btn" title="اطلاعات گفتگو و فایل‌های مشترک"><svg class="ui-icon" aria-hidden="true"><use href="#ui-circle-help"></use></svg></button>
            </div>
        </header>

        <!-- Pinned Message Banner -->
        <div id="pinnedBanner" class="pinned-banner hidden">
            <div class="pinned-bar-accent"></div>
            <div class="pinned-content" id="pinnedJumpArea">
                <span class="pinned-label">📌 پیام سنجاق‌شده</span>
                <p id="pinnedPreviewText"></p>
            </div>
            <button type="button" id="unpinBtn" class="icon-btn-sm" title="برداشتن سنجاق" aria-label="بستن"><svg class="ui-icon" aria-hidden="true"><use href="#ui-x"></use></svg></button>
        </div>

        <!-- Dynamic AI & Admin-Configurable Web Tools Toolbar -->
        <div id="aiHelperToolbar" class="ai-helper-bar hidden"></div>
        <form id="telegramServiceBar" class="telegram-service-bar hidden" autocomplete="off">
            <label for="telegramChannelIdInput">✈️ کانال عمومی تلگرام</label>
            <input id="telegramChannelIdInput" class="ltr-input" dir="ltr" spellcheck="false" placeholder="@channel_id" maxlength="33" aria-label="آیدی دقیق کانال عمومی تلگرام">
            <button type="submit" class="btn-primary btn-sm">دریافت تا ۲۰ پست</button>
            <small>تا ۲۰ پست عمومی؛ اگر پیش‌نمایش فایل کوچک داشته باشد، دریافتش آزمایش می‌شود.</small>
        </form>

        <!-- In-Chat Search Bar -->
        <div id="chatSearchBar" class="chat-search-bar hidden">
            <input type="text" id="chatSearchInput" placeholder="جستجوی عبارت در تمام فایل‌های این گفتگو...">
            <button type="button" id="chatSearchRunBtn" class="btn-primary btn-sm">جستجو</button>
            <button type="button" id="chatSearchCloseBtn" class="icon-btn-sm" aria-label="بستن"><svg class="ui-icon" aria-hidden="true"><use href="#ui-x"></use></svg></button>
        </div>
        <div id="chatSearchResults" class="chat-search-results hidden"></div>

        <!-- Drag & Drop Overlay -->
        <div id="dropZoneOverlay" class="drop-overlay hidden">
            <div class="drop-box">
                <div class="drop-icon">📥</div>
                <h3>فایل، عکس یا ویدیو را اینجا رها کنید</h3>
                <p>کم‌حجم‌سازی واقعی ویدیو در مرورگر (در صورت پشتیبانی) و ارسال هر نوع فایل</p>
            </div>
        </div>

        <!-- Messages Scroll Viewport -->
        <div id="messagesViewport" class="messages-viewport">
            <div id="loadOlderWrap" class="load-older-wrap hidden">
                <button type="button" id="loadOlderBtn" class="btn-older">⬆️ نمایش پیام‌های قدیمی‌تر</button>
                <button type="button" id="jumpLatestBtn" class="btn-older hidden">⬇️ بازگشت به جدیدترین پیام‌ها</button>
            </div>
            <div id="messagesList" class="messages-list"></div>
        </div>

        <!-- Floating Scroll-to-Bottom Button -->
        <button type="button" id="scrollBottomBtn" class="scroll-bottom-btn hidden" title="رفتن به آخرین پیام">↓</button>

        <!-- Composer Area -->
        <footer id="composerFooter" class="composer-footer">
            <!-- Reply / Edit Banner -->
            <div id="composerContextBanner" class="composer-context hidden">
                <div class="context-accent"></div>
                <div class="context-body">
                    <strong id="contextTitle">پاسخ به پیام</strong>
                    <span id="contextText">...</span>
                </div>
                <button type="button" id="cancelContextBtn" class="icon-btn-sm" aria-label="بستن"><svg class="ui-icon" aria-hidden="true"><use href="#ui-x"></use></svg></button>
            </div>

            <!-- Upload / Compression Progress Queue -->
            <div id="uploadProgressBarWrap" class="upload-progress-wrap hidden">
                <div class="upload-progress-info">
                    <span id="uploadFileName">در حال پردازش...</span>
                    <span id="uploadPercentText" class="ltr-inline">0%</span>
                </div>
                <div class="upload-progress-track">
                    <div id="uploadProgressFill" class="upload-progress-fill" style="width: 0%"></div>
                </div>
            </div>

            <!-- Emoji Popover -->
            <div id="emojiPopover" class="emoji-popover hidden"></div>

            <!-- Attachment Popover -->
            <div id="attachPopover" class="attach-popover hidden">
                <button type="button" class="attach-option" id="pickPhotoBtn">
                    <span>🖼️</span>
                    <div>
                        <strong>ارسال عکس</strong>
                        <small>با قابلیت بهینه‌سازی هوشمند حجم</small>
                    </div>
                </button>
                <button type="button" class="attach-option" id="pickVideoBtn">
                    <span>🎬</span>
                    <div>
                        <strong>ارسال ویدیو (کم‌حجم‌سازی داخل مرورگر)</strong>
                        <small>پیش‌نمایش و کاهش حجم خودکار ویدیوهای سنگین</small>
                    </div>
                </button>
                <button type="button" class="attach-option" id="pickFileBtn">
                    <span>📎</span>
                    <div>
                        <strong>ارسال هر نوع فایل یا سند</strong>
                        <small>ZIP, PDF, APK, RAR, DOCX, MP3 و سایر فرمت‌ها</small>
                    </div>
                </button>
                <button type="button" class="attach-option" id="pickCameraBtn">
                    <svg class="ui-icon"><use href="#ui-camera"></use></svg>
                    <div><strong>عکس با دوربین گوشی</strong><small>باز کردن دوربین پشتی، پیش‌نمایش و ارسال امن تصویر</small></div>
                </button>
                <button type="button" class="attach-option" id="shareLocationBtn">
                    <svg class="ui-icon"><use href="#ui-map-pin"></use></svg>
                    <div><strong>اشتراک موقعیت مکانی</strong><small>فقط با اجازهٔ شما؛ پیوند نقشه در همین گفتگو</small></div>
                </button>
                <label class="compress-toggle">
                    <input type="checkbox" id="compressImagesCheck" checked>
                    <span>بهینه‌سازی خودکار حجم عکس‌ها قبل از ارسال</span>
                </label>
            </div>

            <!-- Hidden File Inputs -->
            <input type="file" id="photoFileInput" accept="image/*" multiple class="hidden">
            <input type="file" id="cameraFileInput" accept="image/*" capture="environment" class="hidden">
            <input type="file" id="videoFileInput" accept="video/*" class="hidden">
            <input type="file" id="anyFileInput" multiple class="hidden">

            <!-- Normal Input Row -->
            <div id="composerInputRow" class="composer-row">
                <button type="button" id="emojiBtn" class="icon-btn" title="ایموجی">😊</button>
                <button type="button" id="stickerBtn" class="icon-btn" title="استیکرهای من و ساخت استیکر" aria-label="استیکرهای من"><svg class="ui-icon" aria-hidden="true"><use href="#ui-sticker"></use></svg></button>
                <button type="button" id="attachBtn" class="icon-btn" title="ارسال عکس، ویدیو یا هر نوع فایل"><svg class="ui-icon" aria-hidden="true"><use href="#ui-paperclip"></use></svg></button>

                <textarea id="messageInput" rows="1" placeholder="پیام خود را بنویسید..."></textarea>

                <button type="button" id="voiceRecordBtn" class="send-circle-btn mic-mode" title="ضبط پیام صوتی (کلیک کنید)">
                    <svg id="micSvg" viewBox="0 0 24 24" width="21" height="21" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round"><path d="M12 1a3 3 0 0 0-3 3v8a3 3 0 0 0 6 0V4a3 3 0 0 0-3-3z"/><path d="M19 10v2a7 7 0 0 1-14 0v-2"/><line x1="12" y1="19" x2="12" y2="23"/><line x1="8" y1="23" x2="16" y2="23"/></svg>
                    <svg id="sendSvg" class="hidden" viewBox="0 0 24 24" width="20" height="20" fill="currentColor"><path d="M3.4 20.4l17.45-7.48a1 1 0 0 0 0-1.84L3.4 3.6a.993.993 0 0 0-1.39.91L2 9.12c0 .5.37.93.87.99L17 12 2.87 13.88c-.5.07-.87.5-.87 1l.01 4.61c0 .71.73 1.2 1.39.91z"/></svg>
                </button>
            </div>

            <!-- Active Voice Recording Row -->
            <div id="voiceRecordingRow" class="voice-rec-row hidden">
                <button type="button" id="cancelVoiceBtn" class="btn-danger-ghost" title="لغو ضبط">🗑️ لغو</button>
                <div class="voice-rec-status">
                    <span class="rec-dot"></span>
                    <span id="voiceRecTimer" class="ltr-inline">00:00</span>
                    <div class="rec-bars">
                        <span></span><span></span><span></span><span></span><span></span><span></span><span></span><span></span>
                    </div>
                </div>
                <button type="button" id="finishVoiceBtn" class="send-circle-btn" title="ارسال پیام صوتی">
                    <svg viewBox="0 0 24 24" width="20" height="20" fill="currentColor"><path d="M3.4 20.4l17.45-7.48a1 1 0 0 0 0-1.84L3.4 3.6a.993.993 0 0 0-1.39.91L2 9.12c0 .5.37.93.87.99L17 12 2.87 13.88c-.5.07-.87.5-.87 1l.01 4.61c0 .71.73 1.2 1.39.91z"/></svg>
                </button>
            </div>
        </footer>
    </main>

    <!-- LEFT DRAWER: Chat Info, Cleanup Controls & Shared Media -->
    <aside id="infoDrawer" class="info-drawer hidden">
        <div class="drawer-header">
            <h3>اطلاعات و رسانه‌های گفتگو</h3>
            <button type="button" id="closeInfoDrawerBtn" class="icon-btn-sm" aria-label="بستن"><svg class="ui-icon" aria-hidden="true"><use href="#ui-x"></use></svg></button>
        </div>
        <div class="drawer-profile">
            <div class="avatar avatar-lg" id="drawerAvatar">گ</div>
            <h4 id="drawerTitle">گفتگو</h4>
            <p id="drawerPeerId" class="ltr-inline muted"></p>
            <p id="drawerSubtitle" class="muted"></p>
            <p id="drawerBio" class="drawer-bio"></p>

            <div class="drawer-quick-actions" id="drawerQuickActions">
                <button type="button" id="drawerClearHistoryBtn" class="btn-secondary btn-sm">🗑️ پاک‌کردن برای من</button>
                <button type="button" id="drawerClearForBothBtn" class="btn-danger-ghost btn-sm hidden">🗑️ پاک‌کردن برای همه</button>
                <button type="button" id="drawerHideDialogBtn" class="btn-danger-ghost btn-sm hidden">✕ حذف از فهرست من</button>
                <button type="button" id="drawerMuteBtn" class="btn-secondary btn-sm hidden">🔕 بی‌صدا</button>
                <button type="button" id="drawerArchiveBtn" class="btn-secondary btn-sm hidden">📥 بایگانی</button>
                <button type="button" id="drawerRemoveContactBtn" class="btn-danger-ghost btn-sm hidden">❌ حذف مخاطب</button>
                <button type="button" id="drawerBlockUserBtn" class="btn-danger-ghost btn-sm hidden">مسدود کردن کاربر</button>
                <button type="button" id="drawerDeleteRoomBtn" class="btn-danger-ghost btn-sm hidden">🗑️ حذف کامل گروه</button>
            </div>
        </div>

        <div id="drawerRoomPanel" class="room-panel hidden">
            <h4>گروه یا کانال · عضویت و دعوت</h4>
            <p id="drawerRoomOverview" class="muted"></p>
            <div class="room-id-box"><span>شناسهٔ دقیق:</span> <code dir="ltr" id="drawerRoomId"></code></div>
            <div class="field-group mt-2">
                <label for="drawerRoomInviteLink">لینک دعوت (دسترسیِ دارندهٔ لینک به گروه خصوصی ممکن است)</label>
                <div class="room-invite-row"><input type="text" readonly dir="ltr" id="drawerRoomInviteLink" class="ltr-input"><button type="button" id="drawerCopyRoomLinkBtn" class="btn-secondary btn-sm">کپی</button></div>
            </div>
            <button type="button" id="drawerLeaveRoomBtn" class="btn-danger-ghost btn-sm mt-2">ترک گروه/کانال</button>
            <div id="drawerRoomManager" class="hidden mt-2">
                <h4>مدیریت اعضا و دسترسی</h4>
                <div id="drawerRoomMembersControl">
                    <label for="drawerRoomMemberId">افزودن فرد با آیدی دقیق (بدون نمایش فهرست کل کاربران)</label>
                    <div class="room-invite-row"><input type="text" id="drawerRoomMemberId" class="ltr-input" dir="ltr" placeholder="@username"><button type="button" id="drawerAddRoomMemberBtn" class="btn-primary btn-sm">افزودن</button></div>
                    <div id="drawerRoomMembers" class="room-member-list"></div>
                </div>
                <label for="drawerRoomVisibility" class="mt-2">دسترسی گروه/کانال</label>
                <select id="drawerRoomVisibility"><option value="private">خصوصی؛ فقط لینک دعوت</option><option value="public">عمومی؛ جستجو با آیدی</option></select>
                <label for="drawerRoomPublicHandle" class="mt-2">آیدی عمومی دلخواه</label>
                <input id="drawerRoomPublicHandle" class="ltr-input" dir="ltr" placeholder="اختیاری؛ friendly_group" maxlength="32">
                <div class="room-manage-btns"><button type="button" id="drawerSaveRoomBtn" class="btn-secondary btn-sm">ذخیرهٔ دسترسی</button><button type="button" id="drawerRotateInviteBtn" class="btn-danger-ghost btn-sm">باطل‌کردن لینک قبلی</button></div>
            </div>
        </div>

        <div class="drawer-shard-card">
            <div class="shard-card-title">🔒 ذخیره‌سازی هوشمند و رمزنگاری‌شده</div>
            <div class="shard-stat-grid">
                <div><span>تعداد پیام‌ها:</span> <strong id="drawerMsgCount">0</strong></div>
                <div><span>قطعات JSON:</span> <strong id="drawerShardCount">1</strong></div>
                <div><span>قطعه فعال:</span> <strong id="drawerActiveShard" class="ltr-inline">part_0001.json</strong></div>
                <div><span>حجم قطعه:</span> <strong id="drawerActiveSize" class="ltr-inline">0 KB</strong></div>
            </div>
        </div>

        <div class="media-filter-tabs" id="drawerMediaTabs">
            <button type="button" class="m-tab active" data-cat="image">تصاویر</button>
            <button type="button" class="m-tab" data-cat="video">ویدیو</button>
            <button type="button" class="m-tab" data-cat="voice">صوت</button>
            <button type="button" class="m-tab" data-cat="file">فایل‌ها</button>
            <button type="button" class="m-tab" data-cat="link">لینک</button>
        </div>
        <div id="drawerMediaList" class="drawer-media-list"></div>
    </aside>
</div>

<div id="installOfferModal" class="modal-backdrop hidden" role="dialog" aria-modal="true" aria-labelledby="installOfferTitle">
    <div class="modal-window modal-sm install-offer-window">
        <div class="modal-header"><h3 id="installOfferTitle"><svg class="ui-icon"><use href="#ui-monitor-down"></use></svg> نصب «چت امن»</h3><button type="button" class="icon-btn-sm" data-close-modal="installOfferModal" aria-label="بستن پیشنهاد نصب"><svg class="ui-icon"><use href="#ui-x"></use></svg></button></div>
        <div class="modal-body"><p>برای دسترسی سریع، برنامه را روی صفحهٔ اصلی گوشی یا دسکتاپ نصب کنید. گفتگوها همچنان برای همگام‌سازی به سرور خودتان نیاز دارند.</p><p id="installGuidance" class="muted mt-2">اگر مرورگر گزینهٔ نصب را نشان نداد، از منوی مرورگر «افزودن به صفحهٔ اصلی» را انتخاب کنید.</p>
            <div class="modal-footer-btns mt-2"><button type="button" id="installOfferNowBtn" class="btn-primary">نصب برنامه</button><button type="button" id="installOfferLaterBtn" class="btn-secondary">بعداً؛ دکمهٔ نصب بالای صفحه می‌ماند</button></div>
        </div>
    </div>
</div>

<!-- ====================================================================== -->
<!-- Personal inbox actions: mutually distinct delete scopes -->
<div id="dialogActionsModal" class="modal-backdrop hidden" role="dialog" aria-modal="true" aria-labelledby="dialogActionTitle">
    <div class="modal-window modal-sm"><div class="modal-header"><h3 id="dialogActionTitle">تنظیمات گفتگو</h3><button type="button" class="icon-btn-sm" data-close-modal="dialogActionsModal" aria-label="بستن"><svg class="ui-icon" aria-hidden="true"><use href="#ui-x"></use></svg></button></div><div id="dialogActionsList" class="modal-body dialog-actions-list"></div></div>
</div>

<!-- MODAL A: FIND USER BY EXACT ID (@username)                             -->
<!-- ====================================================================== -->
<div id="findUserModal" class="modal-backdrop hidden">
    <div class="modal-window modal-sm">
        <div class="modal-header">
            <h3>🔍 یافتن کاربر با آیدی (ID)</h3>
            <button type="button" class="icon-btn-sm" data-close-modal="findUserModal" aria-label="بستن"><svg class="ui-icon" aria-hidden="true"><use href="#ui-x"></use></svg></button>
        </div>
        <form id="findUserForm" class="modal-body">
            <p class="muted mb-2">
                برای حفظ حریم خصوصی اعضا، لیست کاربران به صورت عمومی نمایش داده نمی‌شود. کافی است آیدی دقیق شخص مورد نظر را وارد کنید تا بتوانید به او پیام بدهید:
            </p>
            <div class="field-group">
                <label for="findUserIdInput">آیدی (نام کاربری) شخص مورد نظر</label>
                <input type="text" id="findUserIdInput" class="ltr-input" dir="ltr" placeholder="@username" required>
            </div>
            <div class="my-id-tip">
                <span>آیدی اختصاصی شما برای دادن به دیگران:</span>
                <code id="myOwnIdBadge" class="ltr-inline">@user</code>
            </div>
            <div id="findUserError" class="auth-error hidden mt-2"></div>
            <div class="modal-footer-btns">
                <button type="button" class="btn-secondary" data-close-modal="findUserModal">انصراف</button>
                <button type="submit" class="btn-primary">جستجو و شروع گفتگو</button>
            </div>
        </form>
    </div>
</div>

<!-- ====================================================================== -->
<!-- JOIN PUBLIC ROOM BY EXACT ID OR PRIVATE ROOM WITH AN INVITE FRAGMENT -->
<div id="findRoomModal" class="modal-backdrop hidden" role="dialog" aria-modal="true" aria-labelledby="findRoomTitle">
    <div class="modal-window modal-sm">
        <div class="modal-header"><h3 id="findRoomTitle">پیوستن به گروه یا کانال</h3><button type="button" class="icon-btn-sm" data-close-modal="findRoomModal" aria-label="بستن"><svg class="ui-icon" aria-hidden="true"><use href="#ui-x"></use></svg></button></div>
        <div class="modal-body">
            <p class="muted">گروه یا کانال عمومی را با نام پیدا کنید؛ گروه‌های خصوصی در نتایج نشان داده نمی‌شوند.</p>
            <form id="searchPublicRoomsForm" class="field-group mt-2">
                <label for="publicRoomNameInput">جستجو با نام گروه/کانال عمومی</label>
                <div class="room-search-row"><input id="publicRoomNameInput" type="search" minlength="2" maxlength="60" placeholder="مثلاً کتاب‌خوان‌ها" autocomplete="off"><button type="submit" class="btn-primary btn-sm">جستجو</button></div>
            </form>
            <div id="publicRoomSearchResults" class="public-room-results" aria-live="polite"></div>
            <p class="muted mt-2">برای گروه خصوصی، لینک دعوت یا شناسهٔ دقیقِ مجاز لازم است.</p>
            <form id="findRoomForm" class="field-group mt-2">
                <label for="findRoomIdInput">آیدی گروه/کانال یا لینک کامل دعوت</label>
                <input id="findRoomIdInput" type="text" class="ltr-input" dir="ltr" autocomplete="off" required placeholder="@friendly_group یا لینک دعوت">
                <button type="submit" class="btn-secondary btn-sm mt-2">بررسی شناسه</button>
            </form>
            <div id="findRoomResult" class="room-result hidden" aria-live="polite"></div>
            <button type="button" id="joinFoundRoomBtn" class="btn-primary mt-2 hidden">پیوستن به گروه/کانال</button>
        </div>
    </div>
</div>

<!-- LOCAL STATIC STICKER CREATOR: no external service or prebuilt pack. -->
<div id="stickerCreateModal" class="modal-backdrop hidden" role="dialog" aria-modal="true" aria-labelledby="stickerCreateTitle">
    <div class="modal-window modal-sm">
        <div class="modal-header"><h3 id="stickerCreateTitle">ساخت استیکر شخصی</h3><button type="button" class="icon-btn-sm" data-close-modal="stickerCreateModal" aria-label="بستن"><svg class="ui-icon" aria-hidden="true"><use href="#ui-x"></use></svg></button></div>
        <div class="modal-body">
            <p class="muted">از تصویر دلخواهتان یک استیکر ثابت با پس‌زمینهٔ شفاف و حداکثر ۵۱۲×۵۱۲ بسازید؛ فقط در همین مرورگر پردازش و بعد رمزنگاری می‌شود. عکس متحرک ثابت خواهد شد.</p>
            <div class="sticker-preview"><img id="stickerCreatePreview" class="hidden" alt="پیش‌نمایش تصویر استیکر"><span id="stickerPreviewHint">یک عکس انتخاب کنید ✨</span></div>
            <label for="stickerCreateFileInput" class="btn-secondary btn-sm sticker-choose">انتخاب تصویر PNG / WebP / JPG / GIF</label>
            <input type="file" id="stickerCreateFileInput" accept="image/png,image/webp,image/jpeg,image/gif" class="hidden">
            <div class="field-group mt-2"><label for="stickerCreateLabel">نام کوتاه استیکر (اختیاری)</label><input type="text" id="stickerCreateLabel" maxlength="24" placeholder="مثلاً سلام! 👋"></div>
            <button type="button" id="saveStickerBtn" class="btn-primary mt-2">بساز و به مجموعهٔ من اضافه کن</button>
        </div>
    </div>
</div>

<!-- ALL MY MEDIA: encrypted owned uploads, selectable across every chat. -->
<div id="myMediaModal" class="modal-backdrop hidden" role="dialog" aria-modal="true" aria-labelledby="myMediaTitle">
    <div class="modal-window modal-lg">
        <div class="modal-header"><div><h3 id="myMediaTitle">رسانه‌های من در تمام گفتگوها</h3><small>فقط فایل‌هایی که خودتان آپلود کرده‌اید؛ از جدید به قدیم</small></div><button type="button" class="icon-btn-sm" data-close-modal="myMediaModal" aria-label="بستن"><svg class="ui-icon" aria-hidden="true"><use href="#ui-x"></use></svg></button></div>
        <div class="modal-body">
            <p id="myMediaQuota" class="muted"></p>
            <p class="muted">فایل‌های ارسال‌شده، فضای ابری، استیکرها و عکس نمایه در یک فهرست‌اند. برای آزادسازی فضا، نسخه‌های مشترک یا فورواردشدهٔ دیگران ممکن است باقی بمانند.</p>
            <label class="checkbox-inline mt-2"><input type="checkbox" id="myMediaSelectPage"><span>انتخاب همهٔ موارد همین صفحه (حداکثر ۲۰ مورد برای هر نوبت حذف)</span></label>
            <div id="myMediaList" class="owned-media-list" aria-live="polite"></div>
            <button type="button" id="myMediaMoreBtn" class="btn-secondary btn-sm hidden">نمایش ۲۰ مورد بعدی</button>
            <div class="owned-media-actions">
                <h4>دامنهٔ حذف رسانه‌های انتخابی</h4>
                <label class="checkbox-inline"><input type="radio" name="myMediaScope" value="me" checked><span>فقط برای من: از نمای من، فضای شخصی و استیکرها حذف شود؛ نسخهٔ دیگران و ممکن است حجم اشغال‌شده باقی بماند.</span></label>
                <label class="checkbox-inline"><input type="radio" name="myMediaScope" value="both"><span>برای همه: پیام‌های ارسال‌شدهٔ خودم در تمام گفتگوهای قابل دسترسی نیز پاک شود؛ پیام فورواردشدهٔ دیگران یا فایل‌های دارای ارجاع دیگر محفوظ می‌ماند.</span></label>
                <div id="myMediaPasswordWrap" class="field-group hidden mt-2"><label for="myMediaPassword">رمز فعلی برای تأیید حذف برای همه</label><input id="myMediaPassword" type="password" class="ltr-input" dir="ltr" autocomplete="current-password"></div>
                <div class="field-group mt-2"><label for="myMediaConfirm">برای تأیید، MEDIA را بنویسید</label><input id="myMediaConfirm" type="text" class="ltr-input" dir="ltr" placeholder="MEDIA" autocomplete="off"></div>
                <button type="button" id="purgeSelectedMediaBtn" class="btn-danger-ghost mt-2">حذف رسانه‌های انتخابی</button>
            </div>
        </div>
    </div>
</div>

<!-- MODAL B: TELEGRAM-STYLE VIDEO COMPRESSOR & PREVIEW                     -->
<!-- ====================================================================== -->
<div id="videoCompressModal" class="modal-backdrop hidden">
    <div class="modal-window modal-md">
        <div class="modal-header">
            <h3>🎬 پیش‌نمایش و بهینه‌سازی ویدیو</h3>
            <button type="button" id="closeVideoModalBtn" class="icon-btn-sm" aria-label="بستن"><svg class="ui-icon" aria-hidden="true"><use href="#ui-x"></use></svg></button>
        </div>
        <div class="modal-body">
            <video id="videoPreviewPlayer" controls playsinline class="video-preview-box"></video>

            <div class="video-meta-pills mt-2">
                <span id="vidOrigSizeBadge" class="shard-badge">حجم اصلی: 0 MB</span>
                <span id="vidResBadge" class="shard-badge">ابعاد: --</span>
                <span id="vidDurBadge" class="shard-badge">مدت: 00:00</span>
            </div>

            <div id="videoHeavyAlert" class="video-heavy-alert mt-2 hidden">
                <strong>⚡ تشخیص ویدیوی حجیم:</strong>
                <span>حجم این ویدیو بالاست. در صورت پشتیبانی مرورگر و حفظ صدا می‌توانید ویدیو را روی دستگاه خود کم‌حجم کنید. زمان تبدیل تقریباً به اندازهٔ طول ویدیو است؛ کاهش حجم تضمینی نیست.</span>
            </div>

            <div class="field-group mt-2">
                <label>انتخاب حالت پردازش و کیفیت ارسال:</label>
                <div class="quality-options">
                    <label class="quality-radio">
                        <input type="radio" name="vidQuality" value="480" checked>
                        <div>
                            <strong>⚡ کم‌حجم‌سازی در مرورگر (480p — پیشنهادی)</strong>
                            <small>کاهش چشمگیر حجم ویدیو با حفظ وضوح مناسب برای موبایل و اینترنت ضعیف</small>
                        </div>
                    </label>
                    <label class="quality-radio">
                        <input type="radio" name="vidQuality" value="720">
                        <div>
                            <strong>📺 کیفیت بالا HD (720p — حجم متعادل)</strong>
                            <small>تعادل عالی بین وضوح تصویر و حجم فایل</small>
                        </div>
                    </label>
                    <label class="quality-radio">
                        <input type="radio" name="vidQuality" value="original">
                        <div>
                            <strong>📄 ارسال بدون فشرده‌سازی (حجم اصلی)</strong>
                            <small>ارسال مستقیم ویدیو دقیقاً با کیفیت و حجم اولیه به صورت تکه‌ای</small>
                        </div>
                    </label>
                </div>
            </div>

            <div class="field-group">
                <label>توضیحات زیر ویدیو (اختیاری)</label>
                <input type="text" id="videoCaptionInput" placeholder="متن یا توضیح همراه ویدیو...">
            </div>

            <!-- Live Video Compression Progress -->
            <div id="videoProcessBox" class="upload-progress-wrap hidden">
                <div class="upload-progress-info">
                    <span id="videoProcessStatus">در حال کم‌حجم‌سازی ویدیو...</span>
                    <span id="videoProcessPct" class="ltr-inline">0%</span>
                </div>
                <div class="upload-progress-track">
                    <div id="videoProcessFill" class="upload-progress-fill" style="width: 0%"></div>
                </div>
            </div>

            <div class="modal-footer-btns">
                <button type="button" id="cancelVideoProcessBtn" class="btn-secondary">انصراف</button>
                <button type="button" id="confirmVideoSendBtn" class="btn-primary">🚀 پردازش و ارسال ویدیو</button>
            </div>
        </div>
    </div>
</div>

<!-- ====================================================================== -->
<!-- MODAL C: COMPREHENSIVE USER SETTINGS (پروفایل، آیدی، رمز، ظاهر، امنیت) -->
<!-- ====================================================================== -->
<div id="settingsModal" class="modal-backdrop hidden">
    <div class="modal-window modal-xl">
        <div class="modal-header">
            <div>
                <h3>⚙️ تنظیمات کامل حساب کاربری و حریم خصوصی</h3>
                <small class="muted">مدیریت مشخصات، نام کاربری (ID)، رمز عبور، ظاهر برنامه، پاکسازی داده‌ها و اطلاعات رمزنگاری</small>
            </div>
            <button type="button" class="icon-btn-sm" data-close-modal="settingsModal" aria-label="بستن"><svg class="ui-icon" aria-hidden="true"><use href="#ui-x"></use></svg></button>
        </div>

        <div class="admin-tabs" id="userSettingsTabs">
            <button type="button" class="u-set-tab active" data-ustab="account">👤 پروفایل و تغییر آیدی</button>
            <button type="button" class="u-set-tab" data-ustab="security">🔑 رمز عبور و نشست‌ها</button>
            <button type="button" class="u-set-tab" data-ustab="appearance">🎨 ظاهر و رسانه</button>
            <button type="button" class="u-set-tab" data-ustab="cleanup">🗑️ مدیریت داده و حذف</button>
            <button type="button" class="u-set-tab" data-ustab="encryption">🔒 امنیت و رمزنگاری داده‌ها</button>
        </div>

        <div class="modal-body">
            <!-- Tab 1: Profile & Username Change -->
            <form id="settingsAccountForm" class="u-set-pane" data-pane="account">
                <div class="profile-avatar-editor">
                    <div class="avatar avatar-lg" id="profPreviewAvatar">م</div>
                    <div class="profile-avatar-btns">
                        <button type="button" id="uploadAvatarPhotoBtn" class="btn-secondary btn-sm">📷 انتخاب عکس پروفایل</button>
                        <button type="button" id="removeAvatarPhotoBtn" class="btn-danger-ghost btn-sm">حذف عکس</button>
                        <input type="file" id="avatarFileInput" accept="image/*" class="hidden">
                    </div>
                </div>

                <div class="admin-grid-form mt-2">
                    <div>
                        <label>نام نمایشی شما (فارسی یا دلخواه)</label>
                        <input type="text" id="profDisplayName" required>
                    </div>
                    <div>
                        <label>نام کاربری / آیدی اختصاصی (ID انگلیسی)</label>
                        <input type="text" id="profUsername" class="ltr-input" dir="ltr" required>
                        <small class="muted">سایر کاربران تنها با دانستن این آیدی می‌توانند به شما پیام بدهند.</small>
                    </div>
                    <div class="full-span">
                        <label>بیوگرافی / وضعیت کوتاه</label>
                        <input type="text" id="profBio" placeholder="مثلاً: در دسترس">
                    </div>
                    <div>
                        <label>رنگ پس‌زمینه آواتار (در صورت نداشتن عکس)</label>
                        <input type="color" id="profColor" value="#2AABEE" class="color-picker-input">
                    </div>
                </div>

                <div class="modal-footer-btns">
                    <button type="submit" class="btn-primary">💾 ذخیره مشخصات و آیدی</button>
                </div>
            </form>

            <!-- Tab 2: Password Change & Active Devices -->
            <div class="u-set-pane hidden" data-pane="security">
                <form id="settingsPasswordForm" class="admin-card">
                    <h4>🔑 تغییر رمز عبور حساب کاربری</h4>
                    <div class="admin-grid-form mt-2">
                        <div>
                            <label>رمز عبور فعلی</label>
                            <input type="password" id="profCurPass" class="ltr-input" dir="ltr" required>
                        </div>
                        <div>
                            <label>رمز عبور جدید</label>
                            <input type="password" id="profNewPass" class="ltr-input" dir="ltr" minlength="8" required>
                        </div>
                        <div>
                            <label>تکرار رمز عبور جدید</label>
                            <input type="password" id="profNewPassConfirm" class="ltr-input" dir="ltr" minlength="8" required>
                        </div>
                    </div>
                    <div class="mt-2">
                        <button type="submit" class="btn-primary btn-sm">بروزرسانی رمز عبور</button>
                    </div>
                </form>

                <div class="admin-card">
                    <div class="flex-between">
                        <h4>📱 نشست‌ها و دستگاه‌های فعال حساب شما</h4>
                        <button type="button" id="terminateOtherSessionsBtn" class="btn-danger-ghost btn-sm">خروج از سایر دستگاه‌ها</button>
                    </div>
                    <div id="mySessionsList" class="mt-2"></div>
                </div>
                <div class="admin-card">
                    <h4><svg class="ui-icon"><use href="#ui-ban"></use></svg> کاربران مسدودشده</h4>
                    <p class="muted">کاربران مسدودشده نمی‌توانند در گفتگوی خصوصی به شما پیام یا واکنش بفرستند. از صفحهٔ هر گفتگو می‌توانید مسدودسازی یا رفع آن را انجام دهید.</p>
                    <div id="blockedUsersList" class="blocked-users-list mt-2" aria-live="polite"></div>
                </div>
            </div>

            <!-- Tab 3: Appearance, Font Size & Media Compression Preferences -->
            <div class="u-set-pane hidden" data-pane="appearance">
                <div class="admin-card">
                    <h4>🎨 تنظیمات ظاهری و خوانایی</h4>
                    <div class="admin-grid-form mt-2">
                        <div>
                            <label>پوسته رنگی برنامه</label>
                            <select id="prefThemeSelect">
                                <option value="light">روشن کلاسیک ــ پیش‌فرض تلگرام‌گونه</option>
                                <option value="dark">🌙 حالت شب (تاریک تلگرامی)</option>
                                <option value="midnight">🌌 تلگرام نیمه‌شب</option>
                                <option value="ocean">🌊 آبی اقیانوسی</option>
                                <option value="sky">🩵 آسمانی روشن</option>
                                <option value="paper">📄 سفید کاغذی مینیمال</option>
                                <option value="mint">🍃 سبز نعنایی</option>
                                <option value="rose">🌸 صورتی رز</option>
                                <option value="lilac">💜 یاسی و بنفش</option>
                                <option value="peach">🍑 هلویی ملایم</option>
                                <option value="plum">🌺 آلوئی شب (دخترانه و تیره)</option>
                            </select>
                        </div>
                        <div>
                            <label>فونت فارسی برنامه (همه داخل خود پروژه)</label>
                            <select id="prefFontFaceSelect">
                                <option value="vazir">وزیرمتن ــ پیش‌فرض و خوانا</option>
                                <option value="estedad">استعداد ــ مدرن و نرم</option>
                                <option value="sahel">ساحل ــ ساده و صمیمی</option>
                                <option value="shabnam">شبنم ــ نرم و خوش‌خوان</option>
                                <option value="samim">صمیم ــ دوستانه و گرد</option>
                                <option value="parastoo">پرستو ــ خوش‌حالت</option>
                                <option value="gandom">گندم ــ صمیمی و متفاوت</option>
                                <option value="mikhak">میخک ــ معاصر و منعطف</option>
                                <option value="notosans">نوتو سنس عربی ــ خوانایی جهانی</option>
                                <option value="notonaskh">نوتو نسخ عربی ــ رسم‌الخط کلاسیک</option>
                            </select>
                        </div>
                        <div>
                            <label>اندازه فونت پیام‌ها</label>
                            <select id="prefFontSizeSelect">
                                <option value="small">کوچک و فشرده</option>
                                <option value="normal">استاندارد (پیشنهادی)</option>
                                <option value="large">بزرگ و خوانا</option>
                            </select>
                            <div class="font-size-preview">نمونهٔ متن پیام با اندازهٔ انتخابی</div>
                        </div>
                    </div>
                    <div class="mt-2 settings-toggles-stack">
                        <label class="checkbox-inline">
                            <input type="checkbox" id="prefSoundCheck" checked>
                            <span>پخش صدای ملایم هنگام دریافت پیام جدید</span>
                        </label>
                        <label class="checkbox-inline">
                            <input type="checkbox" id="prefEnterSendCheck" checked>
                            <span>ارسال پیام با کلید Enter در کامپیوتر (Shift+Enter برای خط بعد)</span>
                        </label>
                        <label class="checkbox-inline">
                            <input type="checkbox" id="prefAutoImgCompressCheck" checked>
                            <span>بهینه‌سازی خودکار حجم تصاویر قبل از ارسال</span>
                        </label>
                        <label class="checkbox-inline">
                            <input type="checkbox" id="prefAutoVidPromptCheck" checked>
                            <span>نمایش پنجره هوشمند کم‌حجم‌سازی ویدیو قبل از ارسال</span>
                        </label>
                    </div>
                </div>
            </div>

            <!-- Tab 4: Storage & Data Cleanup -->
            <div class="u-set-pane hidden" data-pane="cleanup">
                <div class="admin-card">
                    <h4>📊 وضعیت فضای ذخیره‌سازی شخصی شما</h4>
                    <p id="settingsQuotaSummary" class="mt-2"></p>
                    <div class="quota-bar mt-2"><div id="settingsQuotaBar" class="quota-fill" style="width:1%"></div></div>
                </div>

                <div class="admin-card">
                    <h4>🗑️ ابزارهای حذف و پاکسازی فوری</h4>
                    <p class="muted mb-2">داده‌های شخصی خود را پاک کنید. در گفتگوی مشترک، «حذف برای من» دسترسی شما را قطع می‌کند ولی نسخهٔ طرف مقابل باقی می‌ماند؛ «حذف برای همه» جداگانه و با تأیید صریح است:</p>
                    <div class="cleanup-btns-grid">
                        <button type="button" id="wipeSavedMessagesBtn" class="btn-danger-ghost">🔖 پاکسازی کامل «پیام‌های ذخیره‌شده»</button>
                        <button type="button" id="wipePersonalDriveBtn" class="btn-danger-ghost">☁️ حذف تمام فایل‌ها و یادداشت‌های فضای ابری</button>
                        <button type="button" id="wipeAiHistoryBtn" class="btn-danger-ghost">🤖 پاکسازی کامل تاریخچه دستیار هوشمند</button>
                        <button type="button" id="settingsAllMediaBtn" class="btn-secondary">▧ دیدن تمام عکس‌ها، ویدیوها و فایل‌های خودم</button>
                    </div>
                    <div class="reset-card mt-2">
                        <h4>⚠️ ریست کامل داده‌های این حساب</h4>
                        <p class="muted">پیام‌های ذخیره‌شده، فضای ابری، تاریخچه دستیار، مخاطبان، نمایه و نمای گفتگوها برای شما پاک می‌شود. پیام‌های مشترکِ طرف مقابل/گروه حذف نمی‌شوند؛ فایل‌های دارای ارجاع دیگر باقی می‌مانند. آیدی، نقش و رمز ورود شما حفظ می‌شود.</p>
                        <label for="resetMyPassword">رمز فعلی برای تأیید مجدد</label>
                        <input type="password" id="resetMyPassword" class="ltr-input" dir="ltr" autocomplete="current-password" placeholder="رمز فعلی">
                        <label for="resetMyConfirmation" class="mt-2">عبارت RESET را تایپ کنید</label>
                        <input type="text" id="resetMyConfirmation" class="ltr-input" dir="ltr" autocomplete="off" placeholder="RESET">
                        <button type="button" id="resetMyDataBtn" class="btn-danger-ghost mt-2">🧹 ریست کامل داده‌های من (غیرقابل بازگشت)</button>
                    </div>
                </div>
            </div>

            <!-- Tab 5: Comprehensive Data Security & Encryption Assurance -->
            <div class="u-set-pane hidden" data-pane="encryption">
                <div class="security-assurance-card">
                    <div class="sec-hero"><div class="sec-shield-icon">🛡️</div><div><h4>امنیت چگونه کار می‌کند؟ توضیح قابل بررسی، نه تضمین مطلق</h4><span id="liveEncryptionBadge" class="shard-badge">در حال بررسی رمزنگاری...</span></div></div>
                    <div class="sec-points">
                        <div class="sec-point"><strong>متن پیام و یادداشت:</strong><p>قبل از نوشتن در JSON، متن پیام‌ها، پیش‌نمایش‌ها و یادداشت‌ها با <code>AES-256-GCM</code> و IV تصادفی رمز می‌شوند؛ هر پیام برچسب احراز اصالت دارد. رمز ورودِ تعیین‌شده از برنامه با <code>Bcrypt</code> هش می‌شود.</p></div>
                        <div class="sec-point"><strong>عکس، فیلم، صدا و سایر فایل‌ها:</strong><p>فایل‌های تازه آپلودشده در بلوک‌های مستقل ۱ مگابایتی با <code>AES-256-GCM</code>، nonce مستقل و کلید مشتق از کلید اصلی رمز می‌شوند؛ پخش ویدیو با درخواست Range پس از بررسی برچسب هر بلوک انجام می‌شود. قطعه‌های موقتِ هنگام آپلود کوتاه‌مدت در پوشهٔ محافظت‌شده و بدون رمز هستند و سپس حذف می‌شوند. فایل‌های نسخه‌های قدیمی تا مهاجرت، رمز نشده‌اند.</p></div>
                        <div class="sec-point"><strong>دسترسی و حذف:</strong><p>دسترسی مستقیم وب به <code>data/</code> بسته است. هر تصویر/فایل فقط با نشست معتبر یا بلیت کوتاه‌مدت رسانه، پس از کنترل مالکیت مجاز گفتگو/فضای شخصی ارائه می‌شود. حذف «برای من» نسخهٔ شخص دیگر را پاک نمی‌کند؛ فایل تا حذف آخرین ارجاع نزد سرور می‌ماند. بکاپ‌ها، دستگاه دریافت‌کننده و لینک‌های خروجی خارج از کنترل این حذف هستند.</p></div>
                        <div class="sec-point"><strong>حدود حریم خصوصی:</strong><p>این برنامه <strong>رمزنگاری سرتاسری (E2EE) نیست</strong>: سرور برای نمایش پیام/عکس آن را باز می‌کند؛ مدیر هاست با دسترسی به فایل‌ها و کلید می‌تواند محتوا را بازیابی کند. شناسه‌ها، زمان‌ها، نام فایل‌ها و عضویت‌ها جزو فرادادهٔ بدون رمز هستند. HTTPS، محافظت از هاست و پشتیبان‌گیری امن از <code>data/.master_secret.php</code> ضروری‌اند.</p></div>
                        <div class="sec-point"><strong>سرویس‌های اختیاری:</strong><p>چت و آپلود نیازی به سرویس خارجی ندارند. تنها هنگام استفاده از ویکی‌پدیا/وب/کانال عمومی تلگرام یا AI، درخواست از هاست به بیرون ارسال می‌شود؛ AI ممکن است بخشی از تاریخچه اخیر همان چت دستیار را هم دریافت کند. جزئیات قابل بازبینی در مستند <code>SECURITY.md</code> و کد منبع است.</p></div>
                    </div>
                </div>
            </div>
        </div>
    </div>
</div>

<!-- ====================================================================== -->
<!-- MODAL D: PERSONAL CLOUD DRIVE (فضای ذخیره‌سازی شخصی هر کاربر)          -->
<!-- ====================================================================== -->
<div id="driveModal" class="modal-backdrop hidden">
    <div class="modal-window modal-xl">
        <div class="modal-header">
            <div>
                <h3>☁️ فضای ابری و پوشه‌های شخصی من</h3>
                <small class="muted">فضای اختصاصی و رمزنگاری‌شده حساب شما برای نگهداری فایل‌ها، عکس‌ها و یادداشت‌های ضروری</small>
            </div>
            <button type="button" class="icon-btn-sm" data-close-modal="driveModal" aria-label="بستن"><svg class="ui-icon" aria-hidden="true"><use href="#ui-x"></use></svg></button>
        </div>
        <div class="modal-body drive-layout">
            <div class="drive-sidebar">
                <div class="drive-quota-box">
                    <div class="dq-row">
                        <span>فضای مصرف‌شده:</span>
                        <strong id="driveQuotaNumbers" class="ltr-inline">0 MB / 1024 MB</strong>
                    </div>
                    <div class="quota-bar"><div id="driveQuotaFill" class="quota-fill" style="width:1%"></div></div>
                </div>
                <div class="drive-folders" id="driveFolderList"></div>
                <div class="new-folder-box">
                    <input type="text" id="newDriveFolderInput" placeholder="نام پوشه جدید...">
                    <button type="button" id="addDriveFolderBtn" class="btn-primary btn-sm">+ ساخت</button>
                </div>
            </div>
            <div class="drive-main">
                <div class="drive-toolbar">
                    <button type="button" id="driveUploadFileBtn" class="btn-primary btn-sm">📤 آپلود فایل در این پوشه</button>
                    <button type="button" id="driveNewNoteBtn" class="btn-secondary btn-sm">📝 ثبت یادداشت رمزنگاری‌شده</button>
                    <input type="file" id="driveFileInput" multiple class="hidden">
                </div>

                <div id="driveNoteForm" class="drive-note-form hidden">
                    <input type="text" id="driveNoteTitle" placeholder="عنوان یادداشت...">
                    <textarea id="driveNoteBody" rows="4" placeholder="متن یادداشت خصوصی خود را بنویسید..."></textarea>
                    <div class="drive-note-actions">
                        <label class="checkbox-inline"><input type="checkbox" id="driveAlsoSavedCheck" checked> <span>نمایش یک نسخه در «پیام‌های ذخیره‌شده»</span></label>
                        <div>
                            <button type="button" id="cancelDriveNoteBtn" class="btn-secondary btn-sm">انصراف</button>
                            <button type="button" id="saveDriveNoteBtn" class="btn-primary btn-sm">ذخیره امن</button>
                        </div>
                    </div>
                </div>

                <div id="driveItemsGrid" class="drive-items-grid"></div>
                <div class="load-older-wrap mt-2">
                    <button type="button" id="driveLoadMoreBtn" class="btn-older hidden">⬆️ نمایش فایل‌ها و یادداشت‌های قدیمی‌تر</button>
                </div>
            </div>
        </div>
    </div>
</div>

<!-- ====================================================================== -->
<!-- MODAL E: COMPLETE ADMIN PANEL (کاربران، ابزارهای وب، هوش مصنوعی، JSON) -->
<!-- ====================================================================== -->
<div id="adminModal" class="modal-backdrop hidden">
    <div class="modal-window modal-xl">
        <div class="modal-header">
            <div>
                <h3>🛡️ پنل مدیریت کل سامانه</h3>
                <small class="muted">تعریف و مدیریت کامل کاربران، کم و زیاد کردن ابزارهای جستجو/ویکی‌پدیا، هوش مصنوعی و پایش JSON</small>
            </div>
            <button type="button" class="icon-btn-sm" data-close-modal="adminModal" aria-label="بستن"><svg class="ui-icon" aria-hidden="true"><use href="#ui-x"></use></svg></button>
        </div>

        <div class="admin-tabs">
            <button type="button" class="admin-tab active" data-atab="users">👥 مدیریت کاربران</button>
            <button type="button" class="admin-tab primary-admin-only hidden" data-atab="activity">📊 آمار استفادهٔ حساب‌ها</button>
            <button type="button" class="admin-tab" data-atab="webtools">🌐 ابزارهای جستجو و ویکی‌پدیا</button>
            <button type="button" class="admin-tab" data-atab="ai">🤖 هوش مصنوعی و تنظیمات کل</button>
            <button type="button" class="admin-tab" data-atab="shards">⚡ موتور قطعات JSON</button>
            <button type="button" class="admin-tab" data-atab="rawjson">🔒 امنیت و فایل users.json</button>
        </div>

        <div class="modal-body admin-body">
            <!-- Admin Tab 1: Complete User Management -->
            <div class="admin-pane" id="adminPane_users">
                <div class="admin-user-form-card">
                    <h4 id="userFormTitle">➕ تعریف کاربر جدید</h4>
                    <form id="adminUserForm" class="admin-grid-form">
                        <input type="hidden" id="auIsNew" value="1">
                        <input type="hidden" id="auOriginalUsername" value="">
                        <div>
                            <label>نام کاربری / آیدی (انگلیسی)</label>
                            <input type="text" id="auUsername" class="ltr-input" dir="ltr" placeholder="مثلاً: ali" required>
                        </div>
                        <div>
                            <label id="auPasswordLabel">رمز عبور</label>
                            <input type="password" id="auPassword" class="ltr-input" dir="ltr" autocomplete="new-password" placeholder="رمز عبور کاربر">
                        </div>
                        <div>
                            <label>نام نمایشی (فارسی)</label>
                            <input type="text" id="auDisplayName" placeholder="مثلاً: علی رضایی" required>
                        </div>
                        <div>
                            <label>سطح دسترسی</label>
                            <select id="auRole">
                                <option value="user">کاربر عادی (فقط مخاطبین با ID)</option>
                                <option value="admin">مدیر کل سیستم (دسترسی کامل به همه)</option>
                            </select>
                        </div>
                        <div>
                            <label>سهمیه فضای شخصی (MB)</label>
                            <input type="number" id="auQuota" class="ltr-input" dir="ltr" value="1024" min="50" max="102400">
                        </div>
                        <div>
                            <label>وضعیت حساب</label>
                            <select id="auActive">
                                <option value="1">🟢 فعال</option>
                                <option value="0">🔴 مسدود / غیرفعال</option>
                            </select>
                        </div>
                        <div class="form-actions-cell full-span">
                            <button type="submit" class="btn-primary btn-sm">💾 ذخیره اطلاعات کاربر</button>
                            <button type="button" id="auResetBtn" class="btn-secondary btn-sm">انصراف / فرم جدید</button>
                        </div>
                    </form>
                </div>

                <div class="admin-table-wrap">
                    <table class="admin-table">
                        <thead>
                            <tr>
                                <th>نام نمایشی</th>
                                <th>آیدی (Username)</th>
                                <th>سطح دسترسی</th>
                                <th>فضای مصرفی / کل</th>
                                <th>وضعیت</th>
                                <th>عملیات مدیریت</th>
                            </tr>
                        </thead>
                        <tbody id="adminUsersTableBody"></tbody>
                    </table>
                </div>
            </div>

            <!-- Primary setup administrator only: protected account-usage audit -->
            <div class="admin-pane hidden" id="adminPane_activity">
                <div class="admin-card activity-panel">
                    <div class="flex-between activity-heading">
                        <div>
                            <h4>📊 آمار استفاده از حساب‌ها</h4>
                            <p class="muted">تاریخ ایجاد، آخرین ورود و آخرین حضور ثبت‌شدهٔ هر حساب؛ ویژهٔ مدیر اصلی نصب.</p>
                        </div>
                        <button type="button" class="btn-secondary btn-sm" id="activityRefreshBtn">🔄 به‌روزرسانی وضعیت</button>
                    </div>
                    <div id="activityKpis" class="activity-kpis" aria-label="خلاصه آمار حساب‌ها"></div>
                    <div class="activity-toolbar">
                        <label for="activitySearchInput">جستجوی نام یا آیدی
                            <input type="search" id="activitySearchInput" placeholder="مثلاً: ali" autocomplete="off">
                        </label>
                        <label for="activityFilterSelect">وضعیت حساب
                            <select id="activityFilterSelect">
                                <option value="all">همهٔ حساب‌ها</option>
                                <option value="never_signed_in">هرگز وارد نشده</option>
                                <option value="inactive_7d">بدون حضور در ۷ روز گذشته</option>
                                <option value="inactive_30d">بدون حضور در ۳۰ روز گذشته</option>
                                <option value="online">الان آنلاین</option>
                                <option value="unknown">آمار نامشخص</option>
                            </select>
                        </label>
                    </div>
                    <p class="muted activity-explainer">زمان‌ها بر اساس ساعت ایران و تقویم شمسی نمایش داده می‌شوند؛ «روز» یعنی هر ۲۴ ساعت کامل از آخرین حضور یا ساخت. بازماندن تب در پس‌زمینه هم می‌تواند آنلاین محسوب شود؛ ورود/حضور به‌تنهایی نشانهٔ ارسال پیام نیست. برای حساب‌های ساخته‌شده پیش از این نسخه، زمان ساختِ ثبت‌نشده را حدس نمی‌زنیم.</p>
                    <small id="activityUpdatedAt" class="muted" role="status"></small>
                    <small id="activityMatchCount" class="muted"></small>
                    <div id="activityUsersList" class="activity-grid" aria-label="جزئیات وضعیت حساب‌ها"></div>
                </div>
            </div>

            <!-- Admin Tab 3: Customizable Search & Wikipedia Tools Manager -->
            <div class="admin-pane hidden" id="adminPane_webtools">
                <div class="admin-card">
                    <div class="flex-between">
                        <div>
                            <h4>🌐 مدیریت منابع جستجو، ویکی‌پدیا و ابزارهای وب</h4>
                            <p class="muted">ابزار /telegram تا ۲۰ پستِ قابل‌مشاهده از پیش‌نمایش عمومی را نشان می‌دهد و اگر نشانی مستقیم فایل کوچک در HTML باشد، برای دریافت نسخهٔ پیش‌نمایش تلاش می‌کند؛ کیفیت فایل اصلی و تعداد پست‌ها تضمین ندارد و خطاها در چت نمایش داده می‌شوند. حساب تلگرام نمی‌خواهد، اما به اتصال خروجی t.me و CDN آن وابسته است. در این بخش می‌توانید ابزارهای جستجوی دستیار (مثل ویکی‌پدیا فارسی، انگلیسی، جستجوی وب، سایت‌های خبری/پزشکی یا فیدهای RSS) را کم و زیاد، فعال/غیرفعال یا ویرایش کنید:</p>
                        </div>
                        <button type="button" id="addWebToolRowBtn" class="btn-primary btn-sm">➕ افزودن ابزار جستجوی جدید</button>
                    </div>

                    <div id="adminWebToolsList" class="web-tools-editor-list mt-2"></div>

                    <div class="mt-2 flex-between">
                        <small class="muted">نکته: برای ابزارهای «آدرس سفارشی»، در صورت نیاز به ارسال کلمه جستجو از <code>{query}</code> در آدرس استفاده کنید (مثال: <code>https://example.com/search?q={query}</code>).</small>
                        <button type="button" id="saveWebToolsBtn" class="btn-primary btn-sm">💾 ذخیره لیست ابزارهای وب</button>
                    </div>
                </div>
            </div>

            <!-- Admin Tab 3: AI & General System Permissions -->
            <div class="admin-pane hidden" id="adminPane_ai">
                <div class="admin-card">
                    <h4>⚙️ تنظیمات عمومی سامانه و دسترسی کاربران</h4>
                    <div class="admin-grid-form mt-2">
                        <div>
                            <label>عنوان برنامه</label>
                            <input type="text" id="cfgAppTitle" value="چت امن">
                        </div>
                        <div>
                            <label>توضیح کوتاه برنامه</label>
                            <input type="text" id="cfgAppSubtitle" value="پیام‌رسان خصوصی و فضای ابری شخصی">
                        </div>
                        <div class="full-span">
                            <label for="cfgGithubUrl">آدرس مخزن GitHub برای صفحهٔ ورود (اختیاری)</label>
                            <input type="url" id="cfgGithubUrl" class="ltr-input" dir="ltr" placeholder="https://github.com/owner/repo" autocomplete="off">
                            <small class="muted">تا وقتی آدرس واقعی را اینجا ذخیره نکنید، هیچ لینک ساختگی در ورود نشان داده نمی‌شود. با خالی‌کردن فیلد، لینک پنهان می‌شود.</small>
                        </div>
                        <div>
                            <label>حداکثر حجم آپلود هر فایل (MB)</label>
                            <input type="number" id="cfgMaxUploadMb" class="ltr-input" dir="ltr" min="5" max="2048" value="150">
                        </div>
                        <div>
                            <label>مرز هشدار ویدیوی حجیم (MB)</label>
                            <input type="number" id="cfgVidThresholdMb" class="ltr-input" dir="ltr" min="1" max="500" value="8">
                        </div>
                    </div>
                    <div class="mt-2 settings-toggles-stack">
                        <label class="checkbox-inline">
                            <input type="checkbox" id="cfgAllowProfileEdit" checked>
                            <span>اجازه ویرایش نام نمایشی و عکس پروفایل توسط خود کاربران</span>
                        </label>
                        <label class="checkbox-inline">
                            <input type="checkbox" id="cfgAllowUsernameChange" checked>
                            <span>اجازه تغییر نام کاربری (ID) توسط خود کاربران از پنل تنظیمات</span>
                        </label>
                    </div>
                </div>

                <div class="admin-card">
                    <h4>🤖 تنظیمات اتصال هوش مصنوعی (AI API)</h4>
                    <div class="preset-chips mt-2">
                        <span>پیش‌فرض سریع:</span>
                        <button type="button" class="btn-chip ai-preset" data-url="https://api.deepseek.com/v1" data-model="deepseek-chat">DeepSeek</button>
                        <button type="button" class="btn-chip ai-preset" data-url="https://api.avalai.ir/v1" data-model="gpt-4o-mini">AvalAI</button>
                        <button type="button" class="btn-chip ai-preset" data-url="https://api.gapgpt.app/v1" data-model="gpt-4o-mini">GapGPT</button>
                        <button type="button" class="btn-chip ai-preset" data-url="https://openrouter.ai/api/v1" data-model="google/gemini-2.5-flash">OpenRouter</button>
                    </div>

                    <div class="admin-grid-form mt-2">
                        <div>
                            <label>آدرس پایه API (Base URL)</label>
                            <input type="text" id="aiBaseUrl" class="ltr-input" dir="ltr" placeholder="https://api.deepseek.com/v1">
                        </div>
                        <div>
                            <label>نام مدل (Model)</label>
                            <input type="text" id="aiModel" class="ltr-input" dir="ltr" placeholder="deepseek-chat">
                        </div>
                        <div class="full-span">
                            <label>کلید API (API Key)</label>
                            <input type="password" id="aiApiKey" class="ltr-input" dir="ltr" placeholder="sk-...">
                        </div>
                        <div class="full-span">
                            <label>دستور سیستمی دستیار (System Prompt)</label>
                            <textarea id="aiSystemPrompt" rows="2"></textarea>
                        </div>
                        <div class="full-span">
                            <label class="checkbox-inline">
                                <input type="checkbox" id="aiEnabledCheck" checked>
                                <span>فعال بودن چت «دستیار هوشمند و ابزارهای وب» برای کاربران</span>
                            </label>
                        </div>
                    </div>
                    <div class="mt-2">
                        <button type="button" id="saveAiAndGeneralConfigBtn" class="btn-primary btn-sm">💾 ذخیره تنظیمات کلی و هوش مصنوعی</button>
                    </div>
                </div>
            </div>

            <!-- Admin Tab 4: Smart JSON Sharding Engine -->
            <div class="admin-pane hidden" id="adminPane_shards">
                <div class="shard-kpi-row" id="adminShardKpis"></div>

                <div class="admin-card mt-2">
                    <h4>⚡ تنظیمات تقسیم خودکار فایل‌های JSON</h4>
                    <div class="admin-grid-form mt-2">
                        <div>
                            <label>حداکثر حجم هر قطعه JSON (KB)</label>
                            <input type="number" id="cfgMaxShardKb" class="ltr-input" dir="ltr" min="16" max="4096" value="200">
                        </div>
                        <div>
                            <label>حداکثر تعداد پیام در هر قطعه</label>
                            <input type="number" id="cfgMaxShardMsgs" class="ltr-input" dir="ltr" min="10" max="2000" value="150">
                        </div>
                        <div class="form-actions-cell">
                            <button type="button" id="saveShardConfigBtn" class="btn-primary btn-sm">بروزرسانی موتور JSON</button>
                        </div>
                    </div>
                </div>

                <div class="admin-table-wrap mt-2">
                    <table class="admin-table">
                        <thead>
                            <tr>
                                <th>شناسه گفتگو</th>
                                <th>کل پیام‌ها</th>
                                <th>تعداد قطعات</th>
                                <th>مجموع حجم</th>
                                <th>۱۲ قطعه آخر هر گفتگو</th>
                            </tr>
                        </thead>
                        <tbody id="adminShardsTableBody"></tbody>
                    </table>
                </div>
            </div>

            <!-- Admin Tab 5: Security Check & Raw users.json -->
            <div class="admin-pane hidden" id="adminPane_rawjson">
                <div class="admin-card">
                    <h4>🔒 تست خودکار امنیت فایل‌های JSON روی هاست سی‌پنل</h4>
                    <div id="securityCheckResult" class="security-result-box mt-2">در حال بررسی...</div>
                    <button type="button" id="retestSecurityBtn" class="btn-secondary btn-sm mt-2">🔄 اجرای مجدد تست امنیت</button>
                </div>

                <div class="admin-card mt-2">
                    <h4>📄 مشاهده و ویرایش مستقیم فایل <code>data/users.json</code></h4>
                    <textarea id="rawUsersJsonTextarea" class="code-textarea mt-2" dir="ltr" rows="12" spellcheck="false"></textarea>
                    <div class="mt-2 flex-between">
                        <button type="button" id="downloadUsersJsonBtn" class="btn-secondary btn-sm">⬇️ دانلود نسخه پشتیبان users.json</button>
                        <button type="button" id="saveRawUsersJsonBtn" class="btn-primary btn-sm">💾 اعتبارسنجی و ذخیره JSON</button>
                    </div>
                </div>
            </div>
        </div>
    </div>
</div>

<!-- ====================================================================== -->
<!-- MODAL F: CREATE NEW GROUP OR CHANNEL                                   -->
<!-- ====================================================================== -->
<div id="newRoomModal" class="modal-backdrop hidden">
    <div class="modal-window modal-md">
        <div class="modal-header">
            <h3>👥 ساخت گروه یا کانال جدید</h3>
            <button type="button" class="icon-btn-sm" data-close-modal="newRoomModal" aria-label="بستن"><svg class="ui-icon" aria-hidden="true"><use href="#ui-x"></use></svg></button>
        </div>
        <form id="newRoomForm" class="modal-body">
            <div class="field-group">
                <label>نوع گفتگو</label>
                <select id="nrType">
                    <option value="group">گروه گفتگو</option>
                    <option value="channel">کانال اطلاع‌رسانی</option>
                </select>
            </div>
            <div class="field-group">
                <label>عنوان گروه یا کانال</label>
                <input type="text" id="nrTitle" placeholder="نام گروه یا کانال..." required>
            </div>
            <div class="field-group">
                <label>توضیحات (اختیاری)</label>
                <input type="text" id="nrDesc" placeholder="توضیح کوتاه...">
            </div>
            <div class="field-group">
                <label for="nrVisibility">نوع دسترسی و ورود</label>
                <select id="nrVisibility">
                    <option value="private">خصوصی؛ ورود فقط با لینک دعوت یا افزودن مدیر</option>
                    <option value="public">عمومی؛ هر کاربر با آیدی دقیق یا لینک وارد می‌شود</option>
                </select>
            </div>
            <div class="field-group hidden" id="nrPublicHandleWrap">
                <label for="nrPublicHandle">آیدی عمومی دلخواه (اختیاری؛ ۵ تا ۳۲ حرف/عدد انگلیسی)</label>
                <input id="nrPublicHandle" class="ltr-input" dir="ltr" placeholder="مثلاً: friendly_group" maxlength="32">
                <small class="muted">اگر خالی باشد، شناسهٔ یکتای ساخته‌شده برای جستجو در دسترس است.</small>
            </div>
            <div class="field-group admin-only hidden" id="nrAllMembersWrap">
                <label class="checkbox-inline">
                    <input type="checkbox" id="nrAllMembersCheck">
                    <span>عضویت عمومی همه کاربران سامانه (ویژه مدیر)</span>
                </label>
            </div>
            <div class="field-group">
                <label>انتخاب اعضا از مخاطبین شناخته شده:</label>
                <div id="nrMembersSelectWrap" class="members-check-list"></div>
            </div>
            <div class="modal-footer-btns">
                <button type="button" class="btn-secondary" data-close-modal="newRoomModal">انصراف</button>
                <button type="submit" class="btn-primary">ایجاد گفتگو</button>
            </div>
        </form>
    </div>
</div>

<!-- ====================================================================== -->
<!-- MODAL G: FORWARD MESSAGE PICKER                                        -->
<!-- ====================================================================== -->
<div id="forwardModal" class="modal-backdrop hidden">
    <div class="modal-window modal-sm">
        <div class="modal-header">
            <h3>↪️ فوروارد پیام به...</h3>
            <button type="button" class="icon-btn-sm" data-close-modal="forwardModal" aria-label="بستن"><svg class="ui-icon" aria-hidden="true"><use href="#ui-x"></use></svg></button>
        </div>
        <div class="modal-body">
            <div id="forwardTargetsList" class="forward-list"></div>
        </div>
    </div>
</div>

<!-- ====================================================================== -->
<!-- FULLSCREEN IMAGE LIGHTBOX                                              -->
<!-- ====================================================================== -->
<div id="lightboxOverlay" class="lightbox hidden">
    <div class="lightbox-top">
        <span id="lightboxCaption"></span>
        <div class="lightbox-top-btns">
            <a id="lightboxDownloadBtn" href="#" download class="btn-chip">⬇️ دانلود تصویر</a>
            <button type="button" id="closeLightboxBtn" class="icon-btn">×</button>
        </div>
    </div>
    <div class="lightbox-stage" id="lightboxStage">
        <img id="lightboxImg" src="" alt="Preview">
    </div>
</div>

<!-- Toast Notification Container -->
<div id="toastContainer" class="toast-container"></div>

<script src="assets/js/app.js?v=<?= $assetVer ?>"></script>
</body>
</html>
