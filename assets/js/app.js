/* ===========================================================================
   CHAT AMN v1.0 - Persian RTL / 100% self-hosted vanilla JavaScript
   No third-party libraries, CDN calls, fonts, trackers, or browser APIs beyond
   those built into the user's own browser.
   =========================================================================== */
(() => {
    'use strict';

    const $ = (id) => document.getElementById(id);
    const getStored = (key, fallback = '') => { try { return localStorage.getItem(key) ?? fallback; } catch (_) { return fallback; } };
    const store = (key, value) => { try { localStorage.setItem(key, value); } catch (_) {} };
    const dropStored = (key) => { try { localStorage.removeItem(key); } catch (_) {} };
    const newPageId = () => Array.from(crypto.getRandomValues(new Uint8Array(16)), (b) => b.toString(16).padStart(2, '0')).join('');
    const uiIcon = (name) => `<svg class="ui-icon" aria-hidden="true"><use href="#ui-${name}"></use></svg>`;
    const POPULAR_EMOJIS = ['❤️','👍','😂','🙏','🔥','👏','😍','🥰','😊','😎','🤔','😢','😭','😡','😱','🥳','🌹','🌸','🌺','🌟','✨','⚡','☀️','🌙','🌈','🎉','🎁','🎂','☕','🍵','🍎','🍉','✅','❌','⚠️','📌','📍','🔒','🔑','📱','💻','📷','🎬','🎤','📎','📚','🩺','💊','🚗','🏠','🏥','🇮🇷','👋','🤝','💪','🫶','🙌','👌','✌️','🤞','🫡','🫂','💙','💚'];

    const state = {
        token: getStored('hamrah_token'),
        tabId: newPageId(),
        pageClosing: false,
        installPrompt: null,
        appInstalled: false,
        lastReadSubmitted: 0,
        enterToSend: getStored('hamrah_enter_send', '1') !== '0',
        blockedUsers: [],
        primaryAdminId: '',
        mediaTicket: '',
        user: null,
        users: {},
        presence: {},
        dialogs: [],
        config: { max_upload_mb: 150, chunk_size_bytes: 1048576, video_compress_threshold_mb: 8, web_tools: [] },
        quota: null,
        folderFilter: 'all',
        globalSearch: '',
        activeChatId: '',
        chatGeneration: 0,
        messages: [],
        msgMap: new Map(),
        currentRev: 0,
        viewRev: 0,
        searchCursor: null,
        searchTerm: "",
        dialogActionId: "",
        hasMoreOlder: false,
        oldestSeq: null,
        loadingOlder: false,
        pinnedMessage: null,
        shardInfo: null,
        replyToMsg: null,
        editingMsg: null,
        forwardSource: null,
        syncTimer: null,
        syncInFlight: false,
        typingSentAt: 0,
        soundEnabled: getStored('hamrah_sound', '1') !== '0',
        theme: getStored('hamrah_theme', 'light'),
        fontSize: getStored('hamrah_fontsize', 'normal'),
        fontFace: getStored('hamrah_fontface', 'vazir'),
        emojiTab: 'emoji',
        stickers: [],
        stickerPreviewUrl: '',
        stickerSource: null,
        activeDriveFolder: 'all',
        driveData: null,
        drawerOpen: false,
        drawerMediaCat: 'image',
        drawerMediaCursor: null,
        adminData: null,
        accountActivity: null,
        videoFile: null,
        videoFilePurpose: 'chat',
        videoMeta: { width: 0, height: 0, duration: 0 },
        videoPreviewUrl: '',
        videoProcessController: null,
        avatarPendingId: null,
        avatarRemovalRequested: false,
        mediaRecorder: null,
        voiceChunks: [],
        voiceStartTime: 0,
        voiceTimerInterval: null,
        voiceCancelled: false,
        voiceStream: null,
        pendingInvite: null,
        pendingJoinCandidate: null,
        ownedMediaCursor: null,
        ownedMediaBusy: false,
    };

    const escapeHtml = (s) => String(s ?? '').replace(/&/g, '&amp;').replace(/</g, '&lt;').replace(/>/g, '&gt;').replace(/"/g, '&quot;').replace(/'/g, '&#39;');
    const attr = escapeHtml;
    const formatBytes = (val) => {
        const n = Math.max(0, Number(val) || 0);
        if (n < 1024) return `${Math.round(n)} B`;
        if (n < 1048576) return `${(n / 1024).toFixed(1)} KB`;
        if (n < 1073741824) return `${(n / 1048576).toFixed(1)} MB`;
        return `${(n / 1073741824).toFixed(2)} GB`;
    };
    const formatDuration = (val) => {
        const n = Math.max(0, Math.floor(Number(val) || 0));
        return `${String(Math.floor(n / 60)).padStart(2, '0')}:${String(n % 60).padStart(2, '0')}`;
    };
    const formatTime = (ts) => {
        if (!ts) return '';
        try { return new Intl.DateTimeFormat('fa-IR', { hour: '2-digit', minute: '2-digit', hour12: false }).format(new Date(ts * 1000)); }
        catch (_) { return new Date(ts * 1000).toTimeString().slice(0, 5); }
    };
    const formatShortDate = (ts) => {
        if (!ts) return '';
        const d = new Date(ts * 1000);
        if (d.toDateString() === new Date().toDateString()) return formatTime(ts);
        try { return new Intl.DateTimeFormat('fa-IR', { month: 'short', day: 'numeric' }).format(d); }
        catch (_) { return d.toLocaleDateString(); }
    };
    const formatDatePill = (ts) => {
        const d = new Date(ts * 1000);
        if (d.toDateString() === new Date().toDateString()) return 'امروز';
        const yesterday = new Date(Date.now() - 86400000);
        if (d.toDateString() === yesterday.toDateString()) return 'دیروز';
        try { return new Intl.DateTimeFormat('fa-IR', { weekday: 'long', day: 'numeric', month: 'long' }).format(d); }
        catch (_) { return d.toLocaleDateString(); }
    };
    const formatActivityDate = (ts) => {
        if (!Number.isFinite(Number(ts)) || Number(ts) <= 0) return 'ثبت نشده';
        const date = new Date(Number(ts) * 1000);
        if (!Number.isFinite(date.getTime())) return 'ثبت نشده';
        try {
            return new Intl.DateTimeFormat('fa-IR-u-ca-persian', {
                timeZone: 'Asia/Tehran', year: 'numeric', month: 'long', day: 'numeric',
                hour: '2-digit', minute: '2-digit', hour12: false,
            }).format(date);
        } catch (_) {
            try { return date.toLocaleString('fa-IR', { timeZone: 'Asia/Tehran' }); }
            catch (ignore) { return 'نامشخص (مرورگر بدون پشتیبانی ساعت ایران)'; }
        }
    };
    const isNearBottom = () => {
        const el = $('messagesViewport');
        return el ? el.scrollHeight - el.scrollTop - el.clientHeight < 155 : true;
    };

    function showToast(text, kind = 'info') {
        const root = $('toastContainer');
        if (!root) return;
        const el = document.createElement('div');
        el.className = `toast ${kind}`;
        el.textContent = String(text ?? '');
        root.appendChild(el);
        setTimeout(() => { el.style.opacity = '0'; setTimeout(() => el.remove(), 250); }, 4200);
    }
    function displayError(res, fallback = 'خطا در ارتباط با سرور') { showToast(res?.error || fallback, 'error'); }
    function playBeep() {
        if (!state.soundEnabled) return;
        try {
            const AC = window.AudioContext || window.webkitAudioContext;
            if (!AC) return;
            const ctx = new AC();
            const osc = ctx.createOscillator();
            const gain = ctx.createGain();
            osc.frequency.setValueAtTime(660, ctx.currentTime);
            osc.frequency.exponentialRampToValueAtTime(880, ctx.currentTime + 0.11);
            gain.gain.setValueAtTime(0.065, ctx.currentTime);
            gain.gain.exponentialRampToValueAtTime(0.001, ctx.currentTime + 0.25);
            osc.connect(gain); gain.connect(ctx.destination);
            osc.start(); osc.stop(ctx.currentTime + 0.26);
            setTimeout(() => ctx.close().catch(() => {}), 650);
        } catch (_) {}
    }

    async function apiCall(action, data = null, method = null) {
        const httpMethod = method || (data === null ? 'GET' : 'POST');
        const url = new URL('api.php', location.href);
        url.searchParams.set('action', action);
        if (action === 'bootstrap') url.searchParams.set('tab_id', state.tabId);
        const headers = {};
        if (state.token) headers['X-Auth-Token'] = state.token;
        const opts = { method: httpMethod, headers, credentials: 'same-origin', cache: 'no-store' };
        if (httpMethod === 'GET' && data && !(data instanceof FormData)) {
            for (const [key, value] of Object.entries(data)) {
                if (value !== null && value !== undefined) url.searchParams.set(key, String(value));
            }
        } else if (data instanceof FormData) {
            data.set('tab_id', state.tabId);
            opts.body = data;
        } else if (httpMethod === 'POST') {
            headers['Content-Type'] = 'application/json';
            opts.body = JSON.stringify({ ...(data || {}), tab_id: state.tabId });
        }
        let response;
        try { response = await fetch(url.toString(), opts); }
        catch (_) { throw new Error('اتصال به سرور برقرار نیست. لطفاً اتصال شبکه/هاست خود را بررسی کنید.'); }
        let result;
        try { result = await response.json(); }
        catch (_) { throw new Error(`پاسخ سرور معتبر نیست (HTTP ${response.status}). دسترسی نوشتن و لاگ PHP را بررسی کنید.`); }
        if (response.status === 401 && result.unauthenticated && state.user) {
            handleLoggedOut();
            throw new Error(result.error || 'نشست کاربری منقضی شد.');
        }
        return result;
    }
    function mediaUrl(mediaId, download = false) {
        const u = new URL('api.php', location.href);
        u.searchParams.set('action', 'media');
        u.searchParams.set('id', mediaId);
        if (download) u.searchParams.set('dl', '1');
        // Only a short-lived MEDIA ticket enters <img>/<audio>/<video> URLs;
        // never leak the full 30-day session token to web-server access logs.
        if (state.mediaTicket) u.searchParams.set('media_ticket', state.mediaTicket);
        return u.toString();
    }
    function safeAvatarUrl(avatarUrl) {
        const m = /^api\.php\?action=media&id=([A-Za-z0-9_]+)$/.exec(String(avatarUrl || ''));
        return m ? mediaUrl(m[1]) : '';
    }

    const themes = ['light', 'dark', 'midnight', 'ocean', 'sky', 'paper', 'mint', 'rose', 'lilac', 'peach', 'plum'];
    const themeMetaColors = {dark:'#17212B',light:'#FFFFFF',midnight:'#121A2A',ocean:'#123240',sky:'#F5FBFF',paper:'#FFFFFF',mint:'#FBFFFC',rose:'#FFFBFD',lilac:'#FCFBFF',peach:'#FFFCF8',plum:'#241B32'};
    function applyTheme(theme) {
        state.theme = themes.includes(theme) ? theme : 'light';
        document.documentElement.dataset.theme = state.theme;
        store(state.user ? `hamrah_theme:${state.user.username}` : 'hamrah_theme', state.theme);
        $('metaThemeColor')?.setAttribute('content', themeMetaColors[state.theme]);
        if ($('menuThemeIco')) $('menuThemeIco').innerHTML = uiIcon(['dark','midnight','ocean','plum'].includes(state.theme) ? 'moon' : 'sun');
        if ($('prefThemeSelect')) $('prefThemeSelect').value = state.theme;
    }
    function applyFontSize(size) {
        state.fontSize = ['small', 'normal', 'large'].includes(size) ? size : 'normal';
        document.documentElement.dataset.fontsize = state.fontSize;
        store(state.user ? `hamrah_fontsize:${state.user.username}` : 'hamrah_fontsize', state.fontSize);
        if ($('prefFontSizeSelect')) $('prefFontSizeSelect').value = state.fontSize;
    }
    function applyFontFace(face) {
        state.fontFace = ['vazir', 'estedad', 'sahel', 'shabnam', 'samim', 'parastoo', 'gandom', 'mikhak', 'notosans', 'notonaskh'].includes(face) ? face : 'vazir';
        document.documentElement.dataset.font = state.fontFace;
        store(state.user ? `hamrah_fontface:${state.user.username}` : 'hamrah_fontface', state.fontFace);
        if ($('prefFontFaceSelect')) $('prefFontFaceSelect').value = state.fontFace;
    }
    function getAvatarInnerHtml(person) {
        if (person.type === 'saved') return '<svg viewBox="0 0 24 24" width="22" height="22" fill="none" stroke="white" stroke-width="2"><path d="M19 21l-7-5-7 5V5a2 2 0 0 1 2-2h10a2 2 0 0 1 2 2z"/></svg>';
        if (person.type === 'ai') return '🤖';
        if (person.type === 'channel') return '📢';
        const avatarSrc = safeAvatarUrl(person.avatar_url);
        if (avatarSrc) return `<img src="${attr(avatarSrc)}" alt="">`;
        const title = String(person.title || person.display_name || person.username || '?');
        return escapeHtml(Array.from(title.trim())[0] || '?');
    }
    function textDirection(text) {
        const start = String(text || '').trim().slice(0, 25);
        return /^[\x00-\x7f]/.test(start) && !/[\u0600-\u06ff]/.test(start) ? 'ltr' : 'rtl';
    }
    function formatRichText(raw) {
        let safe = escapeHtml(raw || '');
        const blocks = [];
        safe = safe.replace(/```([\s\S]*?)```/g, (_, code) => {
            const key = `⟦HAMRAH_CODE_${blocks.length}⟧`;
            blocks.push(`<pre><code>${code.trim()}</code></pre>`);
            return key;
        });
        safe = safe.replace(/`([^`\n]+)`/g, '<code>$1</code>');
        safe = safe.replace(/\*\*([^*\n]+)\*\*/g, '<strong>$1</strong>');
        safe = safe.replace(/https?:\/\/[^\s<>"']+/gi, (found) => {
            const clean = found.replace(/(&quot;|&#39;|&lt;|&gt;).*$/, '');
            return `<a href="${attr(clean)}" target="_blank" rel="noopener noreferrer" dir="ltr">${escapeHtml(clean)}</a>${found.slice(clean.length)}`;
        });
        return safe.replace(/⟦HAMRAH_CODE_(\d+)⟧/g, (_match, num) => blocks[Number(num)] || '');
    }

    function installedDisplayMode() {
        return state.appInstalled || window.matchMedia('(display-mode: standalone)').matches || !!navigator.standalone;
    }
    function updateInstallControls() {
        const installed = installedDisplayMode();
        for (const id of ['installTopBtn', 'installChatBtn']) {
            const btn = $(id);
            if (!btn) continue;
            btn.title = installed ? 'برنامه نصب شده است' : 'نصب چت امن روی این دستگاه';
            btn.setAttribute('aria-label', btn.title);
            btn.classList.toggle('installed', installed);
        }
        $('installGuidance').textContent = state.installPrompt
            ? 'مرورگر شما از نصب مستقیم پشتیبانی می‌کند؛ با تأیید آن، میانبر امن برنامه ساخته می‌شود.'
            : 'اگر دکمهٔ نصب مرورگر فعال نیست: در آیفون از Share → Add to Home Screen؛ در اندروید یا رایانه از منوی مرورگر «نصب برنامه/افزودن به صفحهٔ اصلی» را انتخاب کنید. نصب به HTTPS یا localhost نیاز دارد.';
        $('installOfferNowBtn').textContent = state.installPrompt ? 'نصب با مرورگر' : 'راهنمای نصب دستی';
    }
    function offerInstallAfterLogin() {
        if (!state.user || installedDisplayMode()) return;
        const key = `hamrah_install_offer_shown:${state.user.username}`;
        if (getStored(key, '0') === '1') return;
        store(key, '1');
        updateInstallControls();
        openModal('installOfferModal');
    }
    async function requestAppInstall() {
        if (installedDisplayMode()) return showToast('این برنامه از قبل در حالت نصب‌شده اجرا می‌شود.', 'success');
        updateInstallControls();
        if (!state.installPrompt) {
            openModal('installOfferModal');
            return;
        }
        const prompt = state.installPrompt;
        state.installPrompt = null;
        try {
            await prompt.prompt();
            const choice = await prompt.userChoice;
            if (choice?.outcome === 'accepted') {
                closeModal('installOfferModal');
                showToast('نصب توسط مرورگر آغاز شد.', 'success');
            } else {
                closeModal('installOfferModal');
                showToast('هر زمان خواستید، دکمهٔ نصب بالای صفحه در دسترس است.', 'info');
            }
        } catch (_) {
            openModal('installOfferModal');
            showToast('در این مرورگر نصب مستقیم ممکن نشد؛ راهنمای نصب دستی را ببینید.', 'info');
        }
        updateInstallControls();
    }
    function closePagePresence() {
        if (!state.user || !state.token || state.pageClosing) return;
        state.pageClosing = true;
        if (state.syncTimer) { clearInterval(state.syncTimer); state.syncTimer = null; }
        const url = new URL('api.php?action=presence_close', location.href);
        // keepalive lets a short authenticated POST finish after pagehide;
        // a missed request still expires by the bounded heartbeat TTL.
        fetch(url.toString(), {
            method: 'POST', credentials: 'same-origin', keepalive: true,
            headers: { 'Content-Type': 'application/json', 'X-Auth-Token': state.token },
            body: JSON.stringify({ tab_id: state.tabId }),
        }).catch(() => {});
    }
    function initInstallPrompt() {
        window.addEventListener('pagehide', closePagePresence);
        window.addEventListener('pageshow', () => {
            if (!state.pageClosing || !state.user) return;
            state.tabId = newPageId(); // bfcache restore gets a new tab generation
            state.pageClosing = false;
            if (state.syncTimer) clearInterval(state.syncTimer);
            state.syncTimer = setInterval(runSyncCycle, 2500);
            runSyncCycle();
        });
        document.addEventListener('visibilitychange', () => {
            if (document.visibilityState === 'visible') { runSyncCycle(); markReadAfterRender(); }
        });
        window.addEventListener('beforeinstallprompt', (event) => {
            event.preventDefault();
            state.installPrompt = event;
            updateInstallControls();
            if (state.user && !installedDisplayMode()) offerInstallAfterLogin();
        });
        window.addEventListener('appinstalled', () => {
            state.appInstalled = true;
            state.installPrompt = null;
            closeModal('installOfferModal');
            updateInstallControls();
        });
    }

    async function initApp() {
        // Invite credentials stay in the URL fragment, never the HTTP request
        // line or server logs. Remove the fragment once safely held in memory.
        const fragment = new URLSearchParams(location.hash.replace(/^#/, ''));
        const roomId = fragment.get('join') || '';
        const inviteToken = fragment.get('token') || '';
        if (/^(group|channel)_[a-f0-9]{10}$/.test(roomId) && (!inviteToken || /^[a-f0-9]{36}$/.test(inviteToken))) {
            state.pendingInvite = { roomId, token: inviteToken };
            history.replaceState(null, '', location.pathname + location.search);
        }
        applyTheme(state.theme);
        applyFontSize(state.fontSize);
        applyFontFace(state.fontFace);
        bindEvents();
        initInstallPrompt();
        renderEmojiGrid();
        if ('serviceWorker' in navigator && location.protocol === 'https:') {
            navigator.serviceWorker.register('./sw.js').catch(() => {});
        }
        try {
            const boot = await apiCall('bootstrap');
            if (boot?.ok) { enterAuthenticatedApp(boot); return; }
        } catch (_) {}
        showLoginScreen();
    }
    function showLoginScreen() {
        if (state.syncTimer) { clearInterval(state.syncTimer); state.syncTimer = null; }
        $('appScreen').classList.add('hidden');
        $('bootScreen').classList.add('hidden');
        $('authScreen').classList.remove('hidden');
    }
    function handleLoggedOut() {
        state.token = '';
        state.mediaTicket = '';
        state.user = null;
        state.pageClosing = false;
        state.blockedUsers = [];
        state.primaryAdminId = '';
        state.lastReadSubmitted = 0;
        state.quota = null;
        $('appScreen').classList.remove('quota-limited'); $('quotaAlertBanner').classList.add('hidden');
        applyTheme('light'); applyFontSize('normal'); applyFontFace('vazir');
        state.users = {};
        state.dialogs = [];
        state.messages = [];
        state.msgMap.clear();
        state.driveData = null;
        state.stickers = [];
        state.pendingJoinCandidate = null;
        state.ownedMediaCursor = null;
        state.adminData = null;
        state.accountActivity = null;
        state.config = { max_upload_mb: 150, chunk_size_bytes: 1048576, video_compress_threshold_mb: 8, web_tools: [] };
        state.activeChatId = '';
        state.chatGeneration++;
        dropStored('hamrah_token');
        // Modals live outside appScreen. Never leave admin hashes/API keys,
        // previous messages or overlays visible on the sign-in screen.
        document.querySelectorAll('.modal-backdrop').forEach((modal) => modal.classList.add('hidden'));
        $('lightboxOverlay')?.classList.add('hidden');
        $('mainMenuDropdown')?.classList.add('hidden');
        $('messagesList').innerHTML = '';
        $('adminUsersTableBody').innerHTML = '';
        $('activityUsersList').innerHTML = '';
        $('activityKpis').innerHTML = '';
        $('activityUpdatedAt').textContent = '';
        $('activityMatchCount').textContent = '';
        $('activitySearchInput').value = '';
        $('activityFilterSelect').value = 'all';
        document.querySelectorAll('.primary-admin-only').forEach((el) => el.classList.add('hidden'));
        activateAdminPane('users');
        $('rawUsersJsonTextarea').value = '';
        $('aiApiKey').value = ''; $('aiSystemPrompt').value = '';
        $('adminWebToolsList').innerHTML = '';
        $('myMediaList').innerHTML = ''; $('myMediaPassword').value = ''; $('myMediaConfirm').value = '';
        $('emojiPopover').innerHTML = '';
        if (state.stickerPreviewUrl) { URL.revokeObjectURL(state.stickerPreviewUrl); state.stickerPreviewUrl = ''; }
        state.stickerSource = null;
        showLoginScreen();
        document.body.classList.remove('mobile-chat-open');
    }
    function enterAuthenticatedApp(boot) {
        if (boot.token) { state.token = boot.token; store('hamrah_token', boot.token); }
        state.mediaTicket = boot.media_ticket || '';
        state.user = boot.user;
        state.blockedUsers = boot.blocked_users || [];
        state.primaryAdminId = boot.primary_admin_id || '';
        state.enterToSend = getStored(`hamrah_enter_send:${state.user.username}`, getStored('hamrah_enter_send', '1')) !== '0';
        $('messageInput').placeholder = state.enterToSend ? 'پیام... (Enter برای ارسال)' : 'پیام... (Enter برای سطر بعد)';
        applyTheme(getStored(`hamrah_theme:${state.user.username}`, getStored('hamrah_theme', 'light')));
        applyFontSize(getStored(`hamrah_fontsize:${state.user.username}`, getStored('hamrah_fontsize', 'normal')));
        applyFontFace(getStored(`hamrah_fontface:${state.user.username}`, getStored('hamrah_fontface', 'vazir')));
        state.users = boot.users || {};
        state.presence = boot.presence || {};
        state.dialogs = boot.dialogs || [];
        state.quota = boot.quota || null;
        state.config = Object.assign(state.config, boot.config || {});
        $('authScreen').classList.add('hidden');
        $('bootScreen').classList.add('hidden');
        $('appScreen').classList.remove('hidden');
        updateInstallControls();
        offerInstallAfterLogin();
        document.querySelectorAll('.admin-only').forEach((el) => el.classList.toggle('hidden', state.user.role !== 'admin'));
        document.querySelectorAll('.primary-admin-only').forEach((el) =>
            el.classList.toggle('hidden', state.user.role !== 'admin' || state.user.username !== state.primaryAdminId));
        updateMenuUserHeader();
        updateSidebarQuotaUI();
        $('dialogList').classList.add('entering');
        renderDialogList();
        setTimeout(() => $('dialogList').classList.remove('entering'), 300);
        const initial = state.dialogs.find((d) => d.id === state.activeChatId)
            || state.dialogs.find((d) => d.type === 'saved') || state.dialogs[0];
        if (initial) selectChat(initial.id, false);
        if (state.syncTimer) clearInterval(state.syncTimer);
        state.syncTimer = setInterval(runSyncCycle, 2500);
        if (state.pendingInvite) {
            const invitation = state.pendingInvite;
            state.pendingInvite = null;
            setTimeout(() => openFindRoomModal(invitation.roomId, invitation.token), 200);
        }
    }
    function updateMenuUserHeader() {
        if (!state.user) return;
        $('menuUserName').textContent = state.user.display_name || state.user.username;
        $('menuUserUsername').textContent = `@${state.user.username}` + (state.user.role === 'admin' ? ' · مدیر' : '');
        $('menuUserAvatar').style.background = state.user.avatar_color || '#2AABEE';
        $('menuUserAvatar').innerHTML = getAvatarInnerHtml(state.user);
        $('myOwnIdBadge').textContent = '@' + state.user.username;
    }
    function updateSidebarQuotaUI() {
        if (!state.quota) return;
        $('sidebarQuotaText').textContent = `${state.quota.used_human} / ${state.quota.quota_human}`;
        $('sidebarQuotaFill').style.width = `${Math.max(1, Number(state.quota.percent) || 1)}%`;
        $('settingsQuotaSummary').textContent = `${state.quota.used_human} از ${state.quota.quota_human} مصرف شده (${state.quota.percent}% ظرفیت)`;
        $('settingsQuotaBar').style.width = `${Math.max(1, Number(state.quota.percent) || 1)}%`;
        const blocked = 'media_blocked' in state.quota ? !!state.quota.media_blocked : Number(state.quota.percent) >= 80;
        const wasBlocked = $('appScreen').classList.contains('quota-limited');
        $('appScreen').classList.toggle('quota-limited', blocked);
        $('attachBtn').disabled = blocked;
        if (wasBlocked !== blocked && state.emojiTab === 'stickers') renderEmojiGrid();
        updateSendMicButton();
        $('quotaAlertBanner').classList.toggle('hidden', !blocked);
        $('quotaAlertText').textContent = `⚠️ مصرف شما ${state.quota.percent}٪ است و از آستانهٔ ۸۰٪ گذشته؛ ارسال/آپلود رسانهٔ تازه متوقف شده، ولی متن همچنان آزاد است.`;
    }

    function renderDialogList() {
        const root = $('dialogList');
        if (!root) return;
        $('roomDiscoveryBtn').classList.toggle('hidden', state.folderFilter !== 'groups');
        const prevScroll = root.scrollTop;
        const q = state.globalSearch.trim().toLocaleLowerCase('fa');
        const items = state.dialogs.filter((d) => {
            if (state.folderFilter === 'archived' ? !d.archived : !!d.archived) return false;
            if (state.folderFilter === 'personal' && !['saved', 'ai'].includes(d.type)) return false;
            if (state.folderFilter === 'groups' && !['group', 'channel'].includes(d.type)) return false;
            if (state.folderFilter === 'dms' && d.type !== 'dm') return false;
            return !q || `${d.title} ${d.peer_username || ''} ${d.last_message?.preview || ''}`.toLocaleLowerCase('fa').includes(q);
        });
        if (!items.length) {
            root.innerHTML = `<div class="empty-dms-card"><p>${state.folderFilter === 'dms' ? 'برای شروع گفتگوی خصوصی آیدی دقیق شخص مورد نظر را وارد کنید. لیست کاربران به‌صورت عمومی نمایش داده نمی‌شود.' : 'گفتگویی در این بخش یافت نشد.'}</p>${state.folderFilter === 'dms' ? '<button type="button" class="btn-primary btn-sm" data-open-find="1">🔎 افزودن مخاطب با آیدی</button>' : ''}</div>`;
            return;
        }
        root.innerHTML = items.map((d) => {
            const last = d.last_message;
            let preview = d.typing ? d.subtitle : (last?.preview || d.subtitle || 'شروع گفتگو...');
            if (!d.typing && last && d.type === 'group' && last.sender_name) preview = `${last.sender_name}: ${last.preview}`;
            if (!d.typing && last && d.type === 'channel') preview = `${d.title}: ${last.preview}`;
            return `<div class="dialog-item ${d.id === state.activeChatId ? 'active' : ''}" data-chat-id="${attr(d.id)}" role="button" tabindex="0" aria-label="گفتگو با ${attr(d.title)}">
                <div class="avatar avatar-md" style="background:${attr(d.avatar_color || '#2AABEE')}">${getAvatarInnerHtml(d)}${d.type === 'dm' && d.online ? '<span class="online-dot"></span>' : ''}</div>
                <div class="dialog-body"><div class="dialog-top-row"><span class="dialog-title">${d.pinned_rank > 0 && !['saved','ai'].includes(d.type) ? '📌 ' : ''}${escapeHtml(d.title)}</span><span class="dialog-time">${escapeHtml(formatShortDate(last?.created_at))}</span></div>
                <div class="dialog-bottom-row"><span class="dialog-preview ${d.typing ? 'typing' : ''}">${escapeHtml(preview)}</span>${d.muted ? '<span class="dialog-flags" title="بی‌صدا">🔕</span>' : ''}${d.unread_count > 0 ? `<span class="unread-badge">${Number(d.unread_count)}</span>` : ''}</div></div><button type="button" class="dialog-more-btn" data-dialog-menu="${attr(d.id)}" aria-label="حذف و تنظیمات گفتگوی ${attr(d.title)}" title="حذف و تنظیمات">⋮</button></div>`;
        }).join('');
        root.scrollTop = prevScroll;
    }
    const activeDialog = () => state.dialogs.find((d) => d.id === state.activeChatId) || null;
    function renderAiToolbar() {
        const root = $('aiHelperToolbar');
        const d = activeDialog();
        root.classList.toggle('hidden', d?.type !== 'ai');
        const telegram = (state.config.web_tools || []).find((t) => t.enabled && t.type === 'telegram_public');
        $('telegramServiceBar').classList.toggle('hidden', d?.type !== 'ai' || !telegram);
        if (telegram) $('telegramServiceBar').dataset.command = telegram.command;
        if (d?.type !== 'ai') return;
        const tools = (state.config.web_tools || []).filter((t) => t.enabled && t.quick_chip);
        root.innerHTML = `<button type="button" class="ai-cmd-chip" data-ai-command="/help">ℹ️ راهنمای ابزارها</button>`
            + tools.map((t) => `<button type="button" class="ai-cmd-chip" data-ai-tool="${attr(t.command)}" data-ai-type="${attr(t.type)}" data-ai-requires-query="${t.requires_query ? '1' : '0'}" title="${attr(t.title)}">${escapeHtml(t.icon || '🔹')} ${escapeHtml(t.title)}</button>`).join('');
    }
    function updateChatHeaderUI() {
        const d = activeDialog();
        if (!d) return;
        $('activeChatAvatar').style.background = d.avatar_color || '#2AABEE';
        $('activeChatAvatar').innerHTML = getAvatarInnerHtml(d);
        $('activeChatTitle').textContent = d.title;
        $('activeChatSubtitle').textContent = d.subtitle || '';
        $('activeChatSubtitle').classList.toggle('typing', !!d.typing);
        $('openPersonalDriveHeaderBtn').classList.toggle('hidden', d.type !== 'saved');
        $('composerFooter').classList.toggle('hidden', d.can_post === false);
        $('clearChatHeaderBtn').classList.toggle('hidden', d.can_delete_history === false);
        renderAiToolbar();
    }
    function updatePinnedBannerUI() {
        $('pinnedBanner').classList.toggle('hidden', !state.pinnedMessage);
        if (state.pinnedMessage) $('pinnedPreviewText').textContent = state.pinnedMessage.preview || '';
    }
    function updateShardBadgeUI() {
        if (!state.shardInfo) return;
        const shards = Number(state.shardInfo.shard_count) || 1;
        $('shardLiveBadge').textContent = `JSON #${shards} · ${formatBytes(state.shardInfo.active_shard_bytes || 0)}`;
        $('shardLiveBadge').title = `قطعه فعال: ${state.shardInfo.active_shard || 'part_0001.json'}؛ ${state.shardInfo.total_messages || 0} پیام در ${shards} قطعه`;
    }

    function isChatActuallyVisible() {
        return !!state.user && !state.pageClosing && document.visibilityState === 'visible'
            && !$('appScreen').classList.contains('hidden')
            && getComputedStyle($('chatStage')).display !== 'none'
            && !document.querySelector('.modal-backdrop:not(.hidden)');
    }
    function markReadAfterRender() {
        const chatId = state.activeChatId;
        if (!chatId || !isChatActuallyVisible()) return;
        const seq = state.messages.reduce((max, msg) => Math.max(max, Number(msg.seq) || 0), 0);
        if (!seq || seq <= state.lastReadSubmitted) return;
        state.lastReadSubmitted = seq;
        apiCall('mark_chat_read', { chat_id: chatId, seq }).then((res) => {
            if (!res.ok) { if (chatId === state.activeChatId) state.lastReadSubmitted = 0; return; }
            if (chatId !== state.activeChatId) return;
            const dialog = activeDialog();
            if (dialog) dialog.unread_count = Math.max(0, Number(dialog.latest_seq || 0) - seq);
            renderDialogList();
        }).catch(() => { if (chatId === state.activeChatId) state.lastReadSubmitted = 0; });
    }

    async function selectChat(chatId, mobile = true) {
        const d = state.dialogs.find((item) => item.id === chatId);
        if (!d) return;
        const generation = ++state.chatGeneration;
        state.activeChatId = chatId;
        state.lastReadSubmitted = 0;
        state.currentRev = 0;
        state.viewRev = 0;
        state.messages = [];
        state.msgMap.clear();
        state.hasMoreOlder = false;
        state.oldestSeq = null;
        state.shardInfo = null;
        state.pinnedMessage = null;
        clearComposerContext();
        $('chatSearchBar').classList.add('hidden');
        $('chatSearchResults').classList.add('hidden');
        state.searchCursor = null; state.searchTerm = ''; state.drawerMediaCursor = null;
        if (mobile) document.body.classList.add('mobile-chat-open');
        renderDialogList();
        updateChatHeaderUI();
        $('messagesList').innerHTML = '<div class="system-msg-row"><span class="system-msg-pill">در حال دریافت پیام‌ها...</span></div>';
        try {
            const data = await apiCall('messages', { chat_id: chatId, limit: 50 }, 'GET');
            if (generation !== state.chatGeneration || state.activeChatId !== chatId) return;
            if (!data.ok) { displayError(data); return; }
            state.currentRev = data.rev || 1;
            state.viewRev = Number(data.view_rev || 0);
            state.hasMoreOlder = !!data.has_more;
            state.oldestSeq = data.oldest_seq;
            state.pinnedMessage = data.pinned_message || null;
            state.shardInfo = data.shard_info || null;
            state.messages = data.messages || [];
            state.msgMap = new Map(state.messages.map((m) => [Number(m.seq), m]));
            renderDialogList();
            updatePinnedBannerUI();
            updateShardBadgeUI();
            renderAllMessages(true);
            markReadAfterRender();
            if (state.drawerOpen) refreshInfoDrawer();
        } catch (err) {
            if (generation === state.chatGeneration) $('messagesList').innerHTML = `<div class="system-msg-row"><span class="system-msg-pill">${escapeHtml(err.message)}</span></div>`;
        }
    }

    function waveformBars(seed) {
        let hash = 2166136261;
        for (const c of String(seed)) hash = Math.imul(hash ^ c.charCodeAt(0), 16777619);
        let html = '';
        for (let i = 0; i < 28; i++) {
            hash = Math.imul(hash ^ (hash >>> 13), 2246822507);
            html += `<span style="height:${22 + (Math.abs(hash) % 76)}%" data-bar-idx="${i}"></span>`;
        }
        return html;
    }
    function buildMediaInsideBubbleHtml(media) {
        const src = attr(mediaUrl(media.id));
        const dl = attr(mediaUrl(media.id, true));
        const name = attr(media.original_name || 'فایل');
        const size = escapeHtml(media.size_human || formatBytes(media.size_bytes));
        if (media.category === 'sticker') return `<div class="msg-sticker"><img src="${src}" alt="استیکر: ${name}" loading="lazy" data-lightbox-src="${src}" data-lightbox-dl="${dl}" data-lightbox-name="${name}"></div>`;
        if (media.category === 'image') return `<div class="msg-media-wrap"><img src="${src}" alt="${name}" loading="lazy" class="msg-photo" data-lightbox-src="${src}" data-lightbox-dl="${dl}" data-lightbox-name="${name}"><div class="media-dl-sub"><span dir="ltr">${size}</span><a href="${dl}" download="${name}">⬇️ دانلود عکس</a></div></div>`;
        if (media.category === 'video') return `<div class="msg-media-wrap"><video src="${src}" controls preload="metadata" playsinline class="msg-video"></video><div class="media-dl-sub"><span dir="ltr">${size}</span><a href="${dl}" download="${name}">⬇️ دانلود ویدیو</a></div></div>`;
        if (media.category === 'voice') return `<div class="msg-media-wrap"><div class="voice-player" data-voice-src="${src}"><button type="button" class="voice-play-btn" title="پخش / توقف">▶</button><div class="voice-wave-col"><div class="voice-bars">${waveformBars(media.id)}</div><div class="voice-meta-row"><span class="voice-timer ltr-inline">${formatDuration(media.duration)}</span><button type="button" class="voice-speed-btn" data-speed="1">1x</button></div></div></div></div>`;
        if (media.category === 'audio') return `<div class="msg-media-wrap"><div class="file-card-name">🎵 ${escapeHtml(media.original_name || 'صوت')}</div><audio src="${src}" controls preload="metadata" style="width:min(270px,100%);height:38px;"></audio><div class="media-dl-sub"><span dir="ltr">${size}</span><a href="${dl}" download="${name}">⬇️ دانلود</a></div></div>`;
        const ext = String(media.extension || 'FILE').slice(0, 4);
        return `<div class="msg-media-wrap"><a href="${dl}" download="${name}" class="file-card"><div class="file-ext-icon">${escapeHtml(ext)}</div><div class="file-card-info"><div class="file-card-name">${escapeHtml(media.original_name || 'فایل')}</div><div class="file-card-size ltr-inline">${size} · دانلود امن</div></div><span>⬇️</span></a></div>`;
    }
    function buildMessageRowHtml(msg, dlg, otherRead) {
        const seq = Number(msg.seq);
        if (msg.type === 'system') return `<div class="system-msg-row" id="msg-seq-${seq}"><span class="system-msg-pill">${escapeHtml(msg.text)} <button type="button" class="act-btn" data-action="delete_me" data-seq="${seq}" title="حذف این پیام برای من">🗑️</button>${msg.sender === state.user?.username || state.user?.role === 'admin' ? `<button type="button" class="act-btn" data-action="delete_both" data-seq="${seq}" title="حذف برای همه">برای همه</button>` : ''}</span></div>`;
        const isOut = msg.sender === state.user?.username;
        const ai = msg.sender === 'ai' || msg.type === 'ai';
        const isChannel = dlg?.type === 'channel';
        const displaySender = isChannel ? dlg.title : (msg.sender_name || msg.sender || 'کاربر');
        const showSender = isChannel || (!isOut && (ai || dlg?.type === 'group'));
        const color = ai ? '#8B5CF6' : (isChannel ? dlg.avatar_color : (state.users[msg.sender]?.avatar_color || '#2AABEE'));
        const initial = ai ? '🤖' : escapeHtml(Array.from(displaySender || '?')[0]);
        const fwd = msg.forwarded_from ? `<div class="msg-forwarded">↪️ فوروارد از <strong>${escapeHtml(msg.forwarded_from.sender_name || 'کاربر')}</strong></div>` : '';
        const reply = msg.reply_to?.seq ? `<div class="msg-reply-quote" data-jump-seq="${Number(msg.reply_to.seq)}"><strong>${escapeHtml(isChannel ? dlg.title : (msg.reply_to.sender_name || msg.reply_to.sender || 'پیام'))}</strong><span>${escapeHtml(msg.reply_to.preview || '')}</span></div>` : '';
        const media = msg.media?.id ? buildMediaInsideBubbleHtml(msg.media) : '';
        const text = msg.text ? `<div class="msg-text" dir="${textDirection(msg.text)}">${formatRichText(msg.text)}</div>` : '';
        const reactions = Object.entries(msg.reactions || {}).filter(([, users]) => Array.isArray(users) && users.length).map(([emoji, users]) => `<button type="button" class="reaction-pill ${users.includes(state.user?.username) ? 'mine' : ''}" data-react-seq="${seq}" data-emoji="${attr(emoji)}">${escapeHtml(emoji)} <span>${users.length}</span></button>`).join('');
        const reactionRow = reactions ? `<div class="msg-reactions">${reactions}</div>` : '';
        const read = ['saved', 'ai'].includes(dlg?.type) || seq <= Number(otherRead || 0);
        const ticks = isOut ? `<span class="ticks ${read ? 'read' : ''}" title="${read ? 'خوانده‌شده' : 'ارسال‌شده'}">${read ? '✓✓' : '✓'}</span>` : '';
        const canEdit = (isOut || state.user?.role === 'admin') && !ai && !!msg.text;
        const canDeleteEveryone = dlg?.type === 'dm' || isOut || state.user?.role === 'admin' || ['saved', 'ai'].includes(dlg?.type);
        const canPin = dlg?.can_post !== false;
        const actions = `<div class="msg-actions-bar"><button type="button" class="act-btn" data-react-seq="${seq}" data-emoji="❤️" title="قلب">❤️</button><button type="button" class="act-btn" data-react-seq="${seq}" data-emoji="👍" title="تأیید">👍</button><button type="button" class="act-btn" data-action="reply" data-seq="${seq}" title="پاسخ">↩️</button><button type="button" class="act-btn" data-action="save_personal" data-seq="${seq}" title="ذخیره در فضای شخصی">🔖</button><button type="button" class="act-btn" data-action="forward" data-seq="${seq}" title="فوروارد">↪️</button>${canPin ? `<button type="button" class="act-btn" data-action="pin" data-seq="${seq}" title="سنجاق">📌</button>` : ''}${msg.text ? `<button type="button" class="act-btn" data-action="copy" data-seq="${seq}" title="کپی">📋</button>` : ''}${canEdit ? `<button type="button" class="act-btn" data-action="edit" data-seq="${seq}" title="ویرایش">✏️</button>` : ''}<button type="button" class="act-btn" data-action="delete_me" data-seq="${seq}" title="حذف فقط برای من">🗑️ من</button>${canDeleteEveryone && !['saved','ai'].includes(dlg?.type) ? `<button type="button" class="act-btn" data-action="delete_both" data-seq="${seq}" title="حذف برای همه">🗑️ همه</button>` : ''}</div>`;
        return `<div class="msg-row ${isOut ? 'out' : 'in'} ${ai ? 'ai-msg' : ''}" id="msg-seq-${seq}" data-seq="${seq}">${showSender ? `<div class="msg-avatar-mini" style="background:${attr(color)}">${initial}</div>` : ''}<div class="msg-bubble">${actions}${showSender ? `<div class="msg-sender-name" style="color:${attr(color)}">${escapeHtml(displaySender)}</div>` : ''}${fwd}${reply}${media}${text}${reactionRow}<div class="msg-meta">${msg.edited_at ? '<span>ویرایش‌شده</span>' : ''}<span>${escapeHtml(formatTime(msg.created_at))}</span>${ticks}<button type="button" class="msg-more-btn" aria-label="عملیات پیام" title="عملیات پیام">⋯</button></div></div></div>`;
    }

    function renderAllMessages(bottom = false) {
        $('loadOlderWrap').classList.toggle('hidden', !state.hasMoreOlder);
        const root = $('messagesList');
        if (!state.messages.length) {
            const type = activeDialog()?.type;
            const hint = type === 'saved' ? '🔖 فضای شخصی شما. یادداشت یا هر نوع فایلی را با دکمه 📎 ذخیره کنید.' : type === 'ai' ? '🤖 برای مشاهده ابزارهای فعال /help را بفرستید یا از دکمه‌های بالا استفاده کنید.' : 'هنوز پیامی در این گفتگو نیست. اولین پیام را بفرستید! 👋';
            root.innerHTML = `<div class="system-msg-row"><span class="system-msg-pill">${escapeHtml(hint)}</span></div>`;
            return;
        }
        let dateKey = '';
        let html = '';
        const d = activeDialog();
        for (const m of state.messages) {
            const date = new Date((m.created_at || 0) * 1000);
            const key = `${date.getFullYear()}-${date.getMonth()}-${date.getDate()}`;
            if (key !== dateKey) { dateKey = key; html += `<div class="date-pill-row"><span class="date-pill">${escapeHtml(formatDatePill(m.created_at))}</span></div>`; }
            html += buildMessageRowHtml(m, d, d?.max_other_read_seq || 0);
        }
        root.innerHTML = html;
        if (bottom) scrollToBottom(false);
    }
    function scrollToBottom(smooth = true) {
        const vp = $('messagesViewport');
        vp?.scrollTo({ top: vp.scrollHeight, behavior: smooth ? 'smooth' : 'auto' });
    }
    function appendOrUpdateMessage(msg, rev = 0, shard = null) {
        if (!msg || !state.user) return;
        const seq = Number(msg.seq);
        const existing = state.msgMap.has(seq);
        const root = $('messagesList');
        if (!existing && !state.messages.length) root.innerHTML = '';
        const oldRow = document.getElementById(`msg-seq-${seq}`);
        if (oldRow) {
            const playing = [...oldRow.querySelectorAll('video, audio')].some((el) => !el.paused)
                || (!!playingVoice && !playingVoice.paused && !!playingVoiceEl && oldRow.contains(playingVoiceEl));
            if (!playing) oldRow.outerHTML = buildMessageRowHtml(msg, activeDialog(), activeDialog()?.max_other_read_seq || 0);
            else {
                const temp = document.createElement('div');
                temp.innerHTML = buildMessageRowHtml(msg, activeDialog(), activeDialog()?.max_other_read_seq || 0);
                const newReactions = temp.querySelector('.msg-reactions');
                oldRow.querySelector('.msg-reactions')?.remove();
                if (newReactions) oldRow.querySelector('.msg-meta')?.before(newReactions);
                const newText = temp.querySelector('.msg-text');
                if (newText) oldRow.querySelector('.msg-text')?.replaceWith(newText);
            }
            const idx = state.messages.findIndex((m) => Number(m.seq) === seq);
            if (idx >= 0) state.messages[idx] = msg;
        } else if (!existing) {
            // Old shard edits outside the currently loaded page are not appended at the bottom!
            if (state.messages.length && seq < Number(state.messages[0].seq)) return;
            state.messages.push(msg);
            state.messages.sort((a, b) => Number(a.seq) - Number(b.seq));
            const last = state.messages[state.messages.length - 2];
            if (last && new Date(last.created_at * 1000).toDateString() !== new Date(msg.created_at * 1000).toDateString()) {
                root.insertAdjacentHTML('beforeend', `<div class="date-pill-row"><span class="date-pill">${escapeHtml(formatDatePill(msg.created_at))}</span></div>`);
            }
            root.insertAdjacentHTML('beforeend', buildMessageRowHtml(msg, activeDialog(), activeDialog()?.max_other_read_seq || 0));
        }
        state.msgMap.set(seq, msg);
        state.currentRev = Math.max(Number(state.currentRev), Number(rev || 0));
        if (shard) { state.shardInfo = shard; updateShardBadgeUI(); }
    }

    async function runSyncCycle() {
        if (state.syncInFlight || !state.user || state.pageClosing) return;
        state.syncInFlight = true;
        const requestedChat = state.activeChatId;
        const requestedRev = state.currentRev;
        try {
            const beforeUnread = state.dialogs.reduce((sum, d) => sum + (d.muted ? 0 : d.unread_count || 0), 0);
            const res = await apiCall('sync', { active_chat_id: requestedChat, since_rev: requestedRev, since_view_rev: state.viewRev });
            if (!res?.ok || !state.user) return;
            if (res.quota) { state.quota = res.quota; updateSidebarQuotaUI(); }
            if (res.user && (state.user.username !== res.user.username || state.user.role !== res.user.role || state.user.display_name !== res.user.display_name)) {
                state.user = res.user;
                document.querySelectorAll('.admin-only').forEach((el) => el.classList.toggle('hidden', state.user.role !== 'admin'));
                updateMenuUserHeader();
            }
            state.users = res.users || state.users;
            state.blockedUsers = res.blocked_users || state.blockedUsers;
            if (res.media_ticket) state.mediaTicket = res.media_ticket;
            state.presence = res.presence || state.presence;
            state.dialogs = res.dialogs || state.dialogs;
            if (res.web_tools) state.config.web_tools = res.web_tools;
            if (state.activeChatId && !state.dialogs.some((d) => d.id === state.activeChatId)) {
                selectChat(state.dialogs.find((d) => d.type === 'saved')?.id || state.dialogs[0]?.id, false);
            }
            renderDialogList();
            updateChatHeaderUI();
            if (requestedChat !== state.activeChatId || !requestedChat) return;
            const d = activeDialog();
            for (const row of $('messagesList').querySelectorAll('.msg-row.out')) {
                const tick = row.querySelector('.ticks');
                if (tick && Number(row.dataset.seq) <= Number(d?.max_other_read_seq || 0)) {
                    tick.classList.add('read'); tick.textContent = '✓✓';
                }
            }
            const ch = res.chat_changes;
            if (ch?.reset_required) { await selectChat(requestedChat, false); return; }
            if (ch?.changed && Number(ch.rev) > Number(state.currentRev)) {
                const nearBottom = isNearBottom();
                let newIncoming = 0;
                for (const seq of ch.deleted_seqs || []) {
                    state.msgMap.delete(Number(seq));
                    state.messages = state.messages.filter((m) => Number(m.seq) !== Number(seq));
                    document.getElementById(`msg-seq-${seq}`)?.remove();
                }
                for (const m of ch.upserted || []) {
                    if (!state.msgMap.has(Number(m.seq)) && m.sender !== state.user.username && (!state.messages.length || Number(m.seq) > Number(state.messages[state.messages.length - 1].seq))) newIncoming++;
                    appendOrUpdateMessage(m, 0, null);
                }
                state.currentRev = Number(ch.rev);
                state.viewRev = Number(ch.view_rev || 0);
                state.pinnedMessage = ch.pinned_message || null;
                state.shardInfo = ch.shard_info || state.shardInfo;
                updatePinnedBannerUI(); updateShardBadgeUI();
                if (state.messages.length === 0) renderAllMessages(false);
                if (nearBottom) scrollToBottom(true);
                markReadAfterRender();
                if (newIncoming && !d?.muted) playBeep();
            } else if (state.dialogs.reduce((sum, item) => sum + (item.muted ? 0 : item.unread_count || 0), 0) > beforeUnread) {
                playBeep();
            }
        } catch (_) { /* Offline/weak intranet: retry automatically on next cycle. */ }
        finally { state.syncInFlight = false; }
    }

    async function loadOlderMessages() {
        if (state.loadingOlder || !state.hasMoreOlder || !state.oldestSeq) return;
        state.loadingOlder = true;
        const chatId = state.activeChatId;
        const prevHeight = $('messagesViewport').scrollHeight;
        $('loadOlderBtn').disabled = true;
        try {
            const res = await apiCall('messages', { chat_id: chatId, before_seq: state.oldestSeq, limit: 50 });
            if (chatId !== state.activeChatId || !res?.ok) return;
            state.hasMoreOlder = !!res.has_more;
            state.oldestSeq = res.oldest_seq;
            for (const m of res.messages || []) {
                if (!state.msgMap.has(Number(m.seq))) { state.msgMap.set(Number(m.seq), m); state.messages.push(m); }
            }
            state.messages.sort((a, b) => Number(a.seq) - Number(b.seq));
            renderAllMessages(false);
            $('messagesViewport').scrollTop = $('messagesViewport').scrollHeight - prevHeight;
        } catch (err) { showToast(err.message, 'error'); }
        finally { state.loadingOlder = false; $('loadOlderBtn').disabled = false; }
    }

    // ========================================================================
    // Composer, Replies, Editing, Actions, Forwarding, Search & Shared Media
    // ========================================================================
    function clearComposerContext() {
        state.replyToMsg = null;
        state.editingMsg = null;
        $('composerContextBanner').classList.add('hidden');
    }
    function setReplyContext(m) {
        state.editingMsg = null;
        state.replyToMsg = m;
        $('composerContextBanner').classList.remove('hidden');
        $('contextTitle').textContent = `پاسخ به ${m.sender_name || m.sender}`;
        $('contextText').textContent = m.text || m.media?.original_name || 'پیام';
        $('messageInput').focus();
    }
    function setEditContext(m) {
        state.replyToMsg = null;
        state.editingMsg = m;
        $('composerContextBanner').classList.remove('hidden');
        $('contextTitle').textContent = '✏️ ویرایش پیام';
        $('contextText').textContent = m.text || '';
        $('messageInput').value = m.text || '';
        autoResizeInput(); updateSendMicButton();
        $('messageInput').focus();
    }
    function autoResizeInput() {
        const el = $('messageInput');
        el.style.height = 'auto';
        el.style.height = Math.min(140, el.scrollHeight) + 'px';
    }
    function updateSendMicButton() {
        const filled = !!$('messageInput').value.trim() || !!state.editingMsg;
        $('micSvg').classList.toggle('hidden', filled);
        $('sendSvg').classList.toggle('hidden', !filled);
        $('voiceRecordBtn').title = filled ? 'ارسال پیام متنی' : (state.quota?.media_blocked ? 'ضبط رسانه تا آزادسازی فضا غیرفعال است' : 'ضبط پیام صوتی');
        $('voiceRecordBtn').disabled = !filled && !!state.quota?.media_blocked;
    }
    async function submitComposerMessage() {
        const input = $('messageInput');
        const text = input.value.trim();
        const target = state.activeChatId;
        if (!text || !target || !state.user) return;
        if (state.editingMsg) {
            const seq = state.editingMsg.seq;
            try {
                const res = await apiCall('edit_message', { chat_id: target, seq, text });
                if (!res.ok) { displayError(res, 'خطا در ویرایش پیام'); return; }
                if (state.activeChatId === target) appendOrUpdateMessage(res.message);
                clearComposerContext(); input.value = ''; autoResizeInput(); updateSendMicButton();
                await runSyncCycle();
            } catch (err) { showToast(err.message, 'error'); }
            return;
        }
        const replySeq = state.replyToMsg?.seq || 0;
        try {
            const res = await apiCall('send_message', { chat_id: target, text, reply_to_seq: replySeq });
            if (!res.ok) { displayError(res, 'ارسال پیام ناموفق بود'); return; }
            if (input.value.trim() === text) input.value = '';
            clearComposerContext(); autoResizeInput(); updateSendMicButton();
            if (state.activeChatId === target) {
                appendOrUpdateMessage(res.message, res.rev, res.shard_info);
                scrollToBottom(true);
            }
            if (target.startsWith('ai_')) triggerAiResponse(text, target);
        } catch (err) { showToast(err.message, 'error'); }
    }
    async function triggerAiResponse(prompt, chatId) {
        const subtitle = $('activeChatSubtitle');
        if (chatId === state.activeChatId) { subtitle.textContent = '🤖 در حال دریافت پاسخ...'; subtitle.classList.add('typing'); }
        try {
            const res = await apiCall('ask_ai', { prompt });
            if (!res.ok) { displayError(res); return; }
            if (res.message && state.activeChatId === chatId) {
                for (const item of (Array.isArray(res.messages) ? res.messages : [res.message])) {
                    appendOrUpdateMessage(item, res.rev);
                }
                scrollToBottom(true);
            }
            const pending = Array.isArray(res.telegram_media_pending) ? res.telegram_media_pending.slice(0, 20) : [];
            if (pending.length) await importTelegramPreviews(pending, chatId);
        } catch (err) { showToast(err.message, 'error'); }
        finally { if (chatId === state.activeChatId) updateChatHeaderUI(); }
    }
    async function importTelegramPreviews(plan, chatId) {
        const owner = state.user?.username;
        for (let i = 0; i < plan.length; i++) {
            if (state.user?.username !== owner) return; // logout or account switch
            const post = plan[i];
            if (chatId === state.activeChatId) $('activeChatSubtitle').textContent = `دریافت رسانهٔ عمومی ${i + 1} از ${plan.length}...`;
            try {
                const res = await apiCall('telegram_import_post', { channel: post.channel, post_number: post.post_number });
                if (res.message && chatId === state.activeChatId) {
                    appendOrUpdateMessage(res.message); scrollToBottom(true);
                }
                if (res.quota) { state.quota = res.quota; updateSidebarQuotaUI(); }
                if (!res.ok && !res.message) showToast(res.error || 'دریافت رسانهٔ عمومی ممکن نشد.', 'error');
                if (res.quota?.media_blocked) {
                    showToast('سهمیه به ۸۰٪ رسید؛ رسانه‌های بعدی وارد نمی‌شوند و متن پست‌ها باقی می‌ماند.', 'info');
                    break;
                }
            } catch (err) { showToast(`رسانهٔ پست ${post.post_number}: ${err.message}`, 'error'); }
        }
        if (chatId === state.activeChatId) updateChatHeaderUI();
    }
    function jumpToSeq(seq) {
        const row = document.getElementById(`msg-seq-${seq}`);
        if (!row) {
            showToast('پیام در قطعات قدیمی‌تر JSON است. دکمه «نمایش پیام‌های قدیمی‌تر» را بزنید.');
            return;
        }
        row.scrollIntoView({ behavior: 'smooth', block: 'center' });
        const bubble = row.querySelector('.msg-bubble');
        if (bubble) { bubble.classList.add('highlight-flash'); setTimeout(() => bubble.classList.remove('highlight-flash'), 1400); }
    }
    async function handleMessageAction(action, seq) {
        const m = state.msgMap.get(Number(seq));
        if (!m) return;
        const chat = state.activeChatId;
        try {
            if (action === 'reply') return setReplyContext(m);
            if (action === 'edit') return setEditContext(m);
            if (action === 'copy') {
                try { await navigator.clipboard.writeText(m.text || ''); }
                catch (_) {
                    const scratch = document.createElement('textarea');
                    scratch.value = m.text || ''; document.body.appendChild(scratch); scratch.select(); document.execCommand('copy'); scratch.remove();
                }
                return showToast('متن پیام کپی شد.', 'success');
            }
            if (action === 'save_personal' || action === 'forward') {
                if (action === 'forward') return openForwardModal(m);
                const res = await apiCall('forward_message', { from_chat_id: chat, seq: m.seq });
                return res.ok ? showToast('پیام در فضای شخصی ذخیره شد.', 'success') : displayError(res);
            }
            if (action === 'pin') {
                const res = await apiCall('pin_message', { chat_id: chat, seq: m.seq });
                if (!res.ok) return displayError(res);
                if (state.activeChatId === chat) { state.pinnedMessage = res.pinned_message; updatePinnedBannerUI(); }
                return showToast('پیام در بالای گفتگو سنجاق شد.', 'success');
            }
            if (action === 'delete_me' || action === 'delete_both') {
                const scope = action === 'delete_both' ? 'both' : 'me';
                const warning = scope === 'both'
                    ? 'این پیام برای تمام افراد گفتگو حذف می‌شود و بازگشت‌پذیر نیست. آیا مطمئن هستید؟'
                    : 'این پیام فقط از نمای شما حذف می‌شود. نسخهٔ طرف مقابل (و فایل مشترک) ممکن است باقی بماند. ادامه می‌دهید؟';
                if (!confirm(warning)) return;
                const res = await apiCall('delete_message', { chat_id: chat, seq: m.seq, scope });
                if (!res.ok) return displayError(res);
                if (state.activeChatId === chat) {
                    state.msgMap.delete(Number(seq));
                    state.messages = state.messages.filter((v) => Number(v.seq) !== Number(seq));
                    document.getElementById(`msg-seq-${seq}`)?.remove();
                    if (!state.messages.length) renderAllMessages();
                }
                if (res.quota) { state.quota = res.quota; updateSidebarQuotaUI(); }
                runSyncCycle();
                return showToast(scope === 'me' ? 'پیام فقط از نمای شما حذف شد.' : 'پیام برای همه حذف شد.', 'success');
            }
        } catch (err) { showToast(err.message, 'error'); }
    }
    function openForwardModal(m) {
        state.forwardSource = { chat_id: state.activeChatId, seq: m.seq };
        $('forwardTargetsList').innerHTML = state.dialogs.filter((d) => d.can_post !== false && d.type !== 'ai').map((d) => `<div class="dialog-item" data-forward-to="${attr(d.id)}"><div class="avatar avatar-md" style="background:${attr(d.avatar_color || '#2AABEE')}">${getAvatarInnerHtml(d)}</div><div class="dialog-body"><div class="dialog-title">${escapeHtml(d.title)}</div><div class="dialog-preview">${escapeHtml(d.subtitle || '')}</div></div></div>`).join('');
        openModal('forwardModal');
    }
    async function runChatSearch(loadMore = false) {
        const q = $('chatSearchInput').value.trim();
        if (!q || !state.activeChatId) return;
        const chat = state.activeChatId;
        const root = $('chatSearchResults');
        if (!loadMore || state.searchTerm !== q) {
            state.searchCursor = null;
            state.searchTerm = q;
            root.innerHTML = '<div class="muted">در حال جستجو در قطعات JSON...</div>';
        }
        root.classList.remove('hidden');
        try {
            const res = await apiCall('search_chat', { chat_id: chat, q, before_seq: loadMore ? state.searchCursor : undefined });
            if (state.activeChatId !== chat || $('chatSearchInput').value.trim() !== q) return;
            if (!res.ok) return displayError(res);
            const hits = (res.results || []).map((m) => `<div class="search-hit-item" data-jump-seq="${Number(m.seq)}"><strong>${escapeHtml(m.sender_name || m.sender)}:</strong> ${escapeHtml((m.text || m.media?.original_name || '').slice(0, 130))}</div>`).join('');
            if (loadMore) root.querySelector('[data-search-more]')?.remove();
            else root.innerHTML = '';
            if (hits) root.insertAdjacentHTML('beforeend', hits);
            if (res.has_more && res.next_cursor) {
                state.searchCursor = Number(res.next_cursor);
                root.insertAdjacentHTML('beforeend', '<button type="button" class="btn-older" data-search-more="1">⬆️ جستجو در قطعات قدیمی‌تر</button>');
            } else state.searchCursor = null;
            if (!root.querySelector('[data-jump-seq]') && !res.has_more) root.innerHTML = '<div class="muted">نتیجه‌ای یافت نشد.</div>';
        } catch (err) { showToast(err.message, 'error'); }
    }
    async function refreshInfoDrawer(loadMore = false) {
        if (!state.drawerOpen) return;
        const d = activeDialog();
        if (!d) return;
        const chat = state.activeChatId;
        $('drawerAvatar').style.background = d.avatar_color || '#2AABEE';
        $('drawerAvatar').innerHTML = getAvatarInnerHtml(d);
        $('drawerTitle').textContent = d.title;
        $('drawerPeerId').textContent = d.peer_username ? '@' + d.peer_username : '';
        $('drawerSubtitle').textContent = d.subtitle || '';
        $('drawerBio').textContent = d.description || d.bio || '';
        $('drawerMsgCount').textContent = String(state.shardInfo?.total_messages || 0);
        $('drawerShardCount').textContent = String(state.shardInfo?.shard_count || 1);
        $('drawerActiveShard').textContent = state.shardInfo?.active_shard || 'part_0001.json';
        $('drawerActiveSize').textContent = formatBytes(state.shardInfo?.active_shard_bytes || 0);
        $('drawerClearHistoryBtn').classList.toggle('hidden', d.can_delete_history === false);
        $('drawerClearHistoryBtn').textContent = ['saved','ai'].includes(d.type) ? '🗑️ حذف تاریخچه شخصی' : '🗑️ پاک‌کردن برای من';
        $('drawerClearForBothBtn').classList.toggle('hidden', !d.can_clear_for_everyone);
        $('drawerHideDialogBtn').classList.toggle('hidden', ['saved','ai'].includes(d.type) || d.can_hide_dialog === false);
        for (const id of ['drawerMuteBtn','drawerArchiveBtn']) $(id).classList.toggle('hidden', ['saved','ai'].includes(d.type));
        $('drawerMuteBtn').textContent = d.muted ? '🔔 روشن‌کردن اعلان' : '🔕 بی‌صدا کردن';
        $('drawerArchiveBtn').textContent = d.archived ? '📤 خروج از بایگانی' : '📥 بایگانی';
        $('drawerRemoveContactBtn').classList.toggle('hidden', d.type !== 'dm' || !d.is_contact || d.is_primary_admin);
        $('drawerBlockUserBtn').classList.toggle('hidden', d.type !== 'dm' || d.is_primary_admin);
        $('drawerBlockUserBtn').textContent = d.is_blocked ? 'رفع مسدودی مخاطب' : 'مسدود کردن مخاطب';
        $('drawerBlockUserBtn').classList.toggle('is-unblocking', !!d.is_blocked);
        $('drawerDeleteRoomBtn').classList.toggle('hidden', !d.can_delete_room);
        const isRoom = ['group', 'channel'].includes(d.type);
        $('drawerRoomPanel').classList.toggle('hidden', !isRoom);
        if (isRoom) loadRoomInfo(chat).catch((err) => { $('drawerRoomOverview').textContent = err.message; });
        const root = $('drawerMediaList');
        if (!loadMore) { state.drawerMediaCursor = null; root.innerHTML = '<div class="muted">در حال دریافت فایل‌ها...</div>'; }
        try {
            const category = state.drawerMediaCat;
            const res = await apiCall('search_chat', { chat_id: chat, category, before_seq: loadMore ? state.drawerMediaCursor : undefined });
            if (state.activeChatId !== chat || state.drawerMediaCat !== category || !res.ok) return;
            const items = res.results || [];
            if (loadMore) root.querySelector('[data-drawer-more]')?.remove();
            else root.innerHTML = '';
            if (category === 'image') {
                if (!root.querySelector('.media-thumb-grid')) root.innerHTML = '<div class="media-thumb-grid"></div>';
                root.querySelector('.media-thumb-grid').insertAdjacentHTML('beforeend', items.filter((m) => m.media?.id).map((m) => `<img src="${attr(mediaUrl(m.media.id))}" loading="lazy" data-lightbox-src="${attr(mediaUrl(m.media.id))}" data-lightbox-dl="${attr(mediaUrl(m.media.id, true))}" data-lightbox-name="${attr(m.media.original_name)}">`).join(''));
            } else {
                root.insertAdjacentHTML('beforeend', items.map((m) => m.media?.id ? `<a href="${attr(mediaUrl(m.media.id, true))}" download="${attr(m.media.original_name)}" class="file-card" style="margin-bottom:7px"><div class="file-ext-icon">${escapeHtml((m.media.extension || 'FILE').slice(0, 4))}</div><div class="file-card-info"><div class="file-card-name">${escapeHtml(m.media.original_name)}</div><div class="file-card-size">${escapeHtml(m.media.size_human || '')}</div></div>⬇️</a>` : `<div class="search-hit-item" data-jump-seq="${Number(m.seq)}">${formatRichText(m.text)}</div>`).join(''));
            }
            if (res.has_more && res.next_cursor) {
                state.drawerMediaCursor = Number(res.next_cursor);
                root.insertAdjacentHTML('beforeend', '<button type="button" class="btn-older" data-drawer-more="1">⬆️ نمایش موارد قدیمی‌تر</button>');
            } else state.drawerMediaCursor = null;
            if (!root.querySelector('img, a.file-card, .search-hit-item, [data-drawer-more]')) root.innerHTML = '<div class="muted">موردی در این بخش یافت نشد.</div>';
        } catch (_) { root.innerHTML = '<div class="muted">دریافت فایل‌ها ممکن نشد.</div>'; }
    }
    function openLightbox(src, dlSrc, name) {
        $('lightboxImg').src = src;
        $('lightboxCaption').textContent = name || '';
        $('lightboxDownloadBtn').href = dlSrc || src;
        $('lightboxDownloadBtn').setAttribute('download', name || 'image.jpg');
        $('lightboxOverlay').classList.remove('hidden');
    }

    // ========================================================================
    // Actual Client-Side Image & Video Compression + Chunked File Uploads
    // ========================================================================
    function progressMessage(text, percent) {
        $('uploadProgressBarWrap').classList.remove('hidden');
        $('uploadFileName').textContent = text;
        $('uploadPercentText').textContent = `${Math.round(percent)}%`;
        $('uploadProgressFill').style.width = `${Math.max(1, percent)}%`;
    }
    function finishProgress() { setTimeout(() => $('uploadProgressBarWrap').classList.add('hidden'), 600); }

    async function maybeCompressImage(file) {
        const isHeic = /image\/hei[cf]/i.test(file.type) || /\.(heic|heif)$/i.test(file.name);
        if ((!file.type.startsWith('image/') && !isHeic) || file.type === 'image/svg+xml' || file.type === 'image/gif') return file;
        if (!isHeic && (file.size < 350 * 1024 || !$('compressImagesCheck').checked)) return file;
        return new Promise((resolve) => {
            const url = URL.createObjectURL(file);
            const img = new Image();
            img.onload = () => {
                URL.revokeObjectURL(url);
                let { width, height } = img;
                const maxDim = 1600;
                if (!isHeic && width <= maxDim && height <= maxDim && file.size < 900 * 1024) return resolve(file);
                const ratio = Math.min(1, maxDim / Math.max(width, height));
                width = Math.max(1, Math.round(width * ratio));
                height = Math.max(1, Math.round(height * ratio));
                const canvas = document.createElement('canvas');
                canvas.width = width; canvas.height = height;
                const ctx = canvas.getContext('2d');
                if (!ctx) return resolve(file);
                ctx.drawImage(img, 0, 0, width, height);
                const outputMime = /image\/(png|webp)/i.test(file.type) && !isHeic ? 'image/webp' : 'image/jpeg';
                canvas.toBlob((blob) => {
                    if (!blob || (!isHeic && blob.size >= file.size)) return resolve(file);
                    const ext = blob.type === 'image/webp' ? '.webp' : '.jpg';
                    const result = new File([blob], (file.name.replace(/\.[^.]+$/, '') || 'image') + ext, { type: blob.type || outputMime });
                    result._width = width; result._height = height;
                    resolve(result);
                }, outputMime, 0.82);
            };
            img.onerror = () => {
                URL.revokeObjectURL(url);
                if (isHeic) showToast('مرورگر امکان تبدیل عکس HEIC را ندارد؛ فایل به‌صورت سند قابل دانلود ارسال می‌شود.');
                resolve(file);
            };
            img.src = url;
        });
    }
    async function uploadFileChunked(inputFile, options = {}) {
        const file = options.compressImage === false || options.forceAsFile || options.isVoice ? inputFile : await maybeCompressImage(inputFile);
        const maxBytes = (Number(state.config.max_upload_mb) || 150) * 1048576;
        if (file.size > maxBytes) throw new Error(`حجم فایل بیش از سقف ${state.config.max_upload_mb} مگابایت است. برای ویدیوی حجیم، گزینه کم‌حجم‌سازی را انتخاب کنید.`);
        if (file.size < 1) throw new Error('فایل انتخابی خالی است.');
        if (state.quota?.media_blocked) throw new Error('۸۰٪ فضای رسانه پر شده؛ پیش از بارگذاری فایل تازه، از «رسانه‌های من» فضا آزاد کنید. ارسال متن همچنان ممکن است.');
        if (state.quota && file.size + Number(state.quota.used_bytes || 0) > Number(state.quota.quota_bytes || 0)) throw new Error('فضای ذخیره‌سازی شما کافی نیست. موارد اضافی را از فضای شخصی حذف کنید.');
        const chunkSize = Math.min(1048576, Math.max(262144, Number(state.config.chunk_size_bytes) || 1048576));
        const chunks = Math.ceil(file.size / chunkSize);
        const unique = window.crypto?.randomUUID ? crypto.randomUUID().replace(/-/g, '') : Date.now() + '_' + Math.random().toString(36).slice(2);
        const uploadId = 'up_' + unique;
        try {
            let result = null;
            for (let i = 0; i < chunks; i++) {
                const fd = new FormData();
                fd.append('chunk', file.slice(i * chunkSize, (i + 1) * chunkSize), `part_${i}.bin`);
                fd.append('upload_id', uploadId);
                fd.append('chunk_index', String(i));
                fd.append('total_chunks', String(chunks));
                fd.append('total_size', String(file.size));
                fd.append('original_name', options.name || file.name);
                fd.append('mime_type', options.mimeType || file.type || 'application/octet-stream');
                fd.append('is_voice', options.isVoice ? '1' : '0');
                fd.append('is_sticker', options.isSticker ? '1' : '0');
                fd.append('force_as_file', options.forceAsFile ? '1' : '0');
                fd.append('duration', String(options.duration || 0));
                fd.append('width', String(options.width || file._width || 0));
                fd.append('height', String(options.height || file._height || 0));
                progressMessage(`در حال ارسال ${file.name} (${i + 1} از ${chunks} قطعه)`, i / chunks * 100);
                // Weak/intranet networks may drop a response after the server
                // actually saved a chunk; receipt-based retries are idempotent.
                for (let attempt = 0; attempt < 3; attempt++) {
                    try {
                        result = await apiCall('upload_chunk', fd);
                        if (!result?.ok) throw new Error(result?.error || 'ارسال فایل انجام نشد.');
                        break;
                    } catch (err) {
                        if (attempt === 2 || !/اتصال به سرور|پاسخ سرور معتبر نیست/.test(err.message)) throw err;
                        progressMessage(`قطع ارتباط؛ تلاش دوباره برای قطعه ${i + 1}...`, i / chunks * 100);
                        await new Promise((resolve) => setTimeout(resolve, (attempt + 1) * 1200));
                    }
                }
                if (result?.complete) break;
                if (i === chunks - 1) throw new Error('دریافت همه قطعات فایل روی سرور تأیید نشد.');
            }
            progressMessage(`ارسال ${file.name} کامل شد`, 100);
            if (result?.quota) { state.quota = result.quota; updateSidebarQuotaUI(); }
            return result?.media;
        } finally { finishProgress(); }
    }
    async function sendUploadedMedia(media, target, caption = '', replySeq = 0) {
        const res = await apiCall('send_message', { chat_id: target, text: caption, media_id: media.id, reply_to_seq: replySeq });
        if (!res.ok) {
            await apiCall('delete_unused_upload', { media_id: media.id }).catch(() => {});
            throw new Error(res.error || 'ارسال فایل در گفتگو ناموفق بود.');
        }
        if (target === state.activeChatId) { appendOrUpdateMessage(res.message, res.rev, res.shard_info); scrollToBottom(true); }
        return res;
    }
    const isVideoFile = (file) => file.type.startsWith('video/') || /\.(mp4|webm|mov|m4v|mkv)$/i.test(file.name);

    async function shareCurrentLocation() {
        const target = state.activeChatId;
        const dialog = activeDialog();
        $('attachPopover').classList.add('hidden');
        if (!target || dialog?.can_post === false) return showToast('در این گفتگو اجازهٔ ارسال موقعیت ندارید.', 'error');
        if (!navigator.geolocation || !window.isSecureContext) return showToast('مکان‌یابی در این مرورگر فقط روی HTTPS یا localhost قابل استفاده است.', 'error');
        const btn = $('shareLocationBtn');
        btn.disabled = true;
        showToast('در انتظار اجازهٔ موقعیت مکانی مرورگر…', 'info');
        navigator.geolocation.getCurrentPosition(async (position) => {
            btn.disabled = false;
            const lat = Number(position.coords.latitude).toFixed(6);
            const lon = Number(position.coords.longitude).toFixed(6);
            if (!confirm(`مختصات ${lat}, ${lon} برای گفتگوی «${dialog.title}» فرستاده شود؟ هر عضو این گفتگو می‌تواند پیوند نقشه را ببیند.`)) return;
            const link = `https://www.openstreetmap.org/?mlat=${lat}&mlon=${lon}#map=17/${lat}/${lon}`;
            try {
                const res = await apiCall('send_message', { chat_id: target, text: `📍 موقعیت مکانی من\n${link}` });
                if (!res.ok) return displayError(res);
                if (target === state.activeChatId) { appendOrUpdateMessage(res.message, res.rev, res.shard_info); scrollToBottom(true); }
                showToast('موقعیت فقط در گفتگوی انتخاب‌شده فرستاده شد.', 'success');
            } catch (err) { showToast(err.message, 'error'); }
        }, (error) => {
            btn.disabled = false;
            const message = error.code === 1 ? 'مجوز موقعیت مکانی داده نشد؛ از تنظیمات مرورگر اجازه دهید.'
                : error.code === 3 ? 'زمان گرفتن موقعیت تمام شد؛ دوباره تلاش کنید.' : 'موقعیت در این دستگاه پیدا نشد.';
            showToast(message, 'error');
        }, { enableHighAccuracy: false, timeout: 12000, maximumAge: 0 });
    }
    async function handleChatFilesSelected(fileList, forceAsFile = false) {
        if (!fileList?.length || !state.activeChatId || !state.user) return;
        $('attachPopover').classList.add('hidden');
        const target = state.activeChatId;
        const input = $('messageInput');
        const caption = input.value.trim();
        const replySeq = state.replyToMsg?.seq || 0;
        for (let i = 0; i < fileList.length; i++) {
            const file = fileList[i];
            try {
                if (isVideoFile(file) && !forceAsFile && getStored('hamrah_video_prompt', '1') !== '0') {
                    const sent = await openVideoModal(file, 'chat', target, i === 0 ? caption : '', i === 0 ? replySeq : 0);
                    if (sent && i === 0 && input.value.trim() === caption) { input.value = ''; clearComposerContext(); autoResizeInput(); updateSendMicButton(); }
                    continue;
                }
                const media = await uploadFileChunked(file, { forceAsFile });
                if (!media) throw new Error('آپلود فایل تأیید نشد.');
                await sendUploadedMedia(media, target, i === 0 ? caption : '', i === 0 ? replySeq : 0);
                if (i === 0 && input.value.trim() === caption) { input.value = ''; clearComposerContext(); autoResizeInput(); updateSendMicButton(); }
            } catch (err) { showToast(err.message, 'error'); }
        }
    }

    let videoResolve = null;
    function videoProcessUI(label, pct) {
        $('videoProcessBox').classList.remove('hidden');
        $('videoProcessStatus').textContent = label;
        $('videoProcessPct').textContent = `${Math.min(100, Math.floor(pct))}%`;
        $('videoProcessFill').style.width = `${Math.min(100, pct)}%`;
    }
    function openVideoModal(file, purpose, targetChat = '', caption = '', replySeq = 0) {
        return new Promise((resolve) => {
            if (videoResolve) { videoResolve(false); videoResolve = null; }
            state.videoFile = file;
            state.videoFilePurpose = purpose;
            state.videoTargetChat = targetChat;
            state.videoCaption = caption;
            state.videoReplySeq = replySeq;
            videoResolve = resolve;
            $('videoCaptionInput').value = caption;
            const url = URL.createObjectURL(file);
            state.videoPreviewUrl = url;
            const player = $('videoPreviewPlayer');
            player.src = url;
            player.load();
            state.videoMeta = { width: 0, height: 0, duration: 0 };
            player.onloadedmetadata = () => {
                state.videoMeta = { width: player.videoWidth || 0, height: player.videoHeight || 0, duration: Number.isFinite(player.duration) ? player.duration : 0 };
                $('vidResBadge').textContent = `ابعاد: ${state.videoMeta.width} × ${state.videoMeta.height}`;
                $('vidDurBadge').textContent = `مدت: ${formatDuration(state.videoMeta.duration)}`;
            };
            player.onerror = () => showToast('پیش‌نمایش این فرمت توسط مرورگر پشتیبانی نمی‌شود؛ می‌توانید ویدیو را بدون فشرده‌سازی به شکل فایل ارسال کنید.', 'error');
            $('vidOrigSizeBadge').textContent = `حجم اصلی: ${formatBytes(file.size)}`;
            $('vidResBadge').textContent = 'در حال خواندن مشخصات...';
            $('vidDurBadge').textContent = 'مدت: --';
            const isHeavy = file.size >= (Number(state.config.video_compress_threshold_mb) || 8) * 1048576;
            $('videoHeavyAlert').classList.toggle('hidden', !isHeavy);
            document.querySelector(`input[name="vidQuality"][value="${isHeavy ? '480' : 'original'}"]`).checked = true;
            $('videoProcessBox').classList.add('hidden');
            $('confirmVideoSendBtn').disabled = false;
            $('cancelVideoProcessBtn').disabled = false;
            openModal('videoCompressModal');
        });
    }
    function closeVideoModal(sent = false) {
        if (state.videoProcessController) state.videoProcessController.cancel();
        const player = $('videoPreviewPlayer');
        player.pause(); player.removeAttribute('src'); player.load();
        if (state.videoPreviewUrl) URL.revokeObjectURL(state.videoPreviewUrl);
        state.videoPreviewUrl = '';
        state.videoFile = null;
        state.videoProcessController = null;
        $('videoCompressModal').classList.add('hidden');
        $('videoProcessBox').classList.add('hidden');
        $('confirmVideoSendBtn').disabled = false;
        if (videoResolve) { videoResolve(sent); videoResolve = null; }
    }

    async function compressVideoInBrowser(file, quality, onProgress) {
        const video = $('videoPreviewPlayer');
        if (!video.videoWidth || !video.videoHeight || !Number.isFinite(video.duration) || video.duration <= 0) throw new Error('مرورگر مدت و ابعاد ویدیو را تشخیص نداد. برای حفظ داده‌ها نسخه اصلی را ارسال کنید.');
        if (!window.MediaRecorder || !HTMLCanvasElement.prototype.captureStream) throw new Error('مرورگر شما امکان کم‌حجم‌سازی محلی ویدیو را ندارد. حالت «ارسال بدون فشرده‌سازی» را انتخاب کنید.');
        const mimes = ['video/mp4;codecs=avc1.42E01E,mp4a.40.2', 'video/webm;codecs=vp8,opus', 'video/webm;codecs=vp9,opus', 'video/webm'];
        const mimeType = mimes.find((m) => MediaRecorder.isTypeSupported(m));
        if (!mimeType) throw new Error('کُدِک ضبط ویدیوی فشرده در این مرورگر پشتیبانی نمی‌شود. فایل اصلی را ارسال کنید.');
        const maxSide = Number(quality);
        const scale = Math.min(1, maxSide / Math.max(video.videoWidth, video.videoHeight));
        const width = Math.max(2, Math.round(video.videoWidth * scale / 2) * 2);
        const height = Math.max(2, Math.round(video.videoHeight * scale / 2) * 2);
        const canvas = document.createElement('canvas'); canvas.width = width; canvas.height = height;
        const ctx = canvas.getContext('2d', { alpha: false });
        if (!ctx) throw new Error('مرورگر امکان پردازش فریم‌های ویدیو را ندارد.');
        let audioTrack;
        try {
            const AC = window.AudioContext || window.webkitAudioContext;
            if (!AC) throw new Error('AudioContext unavailable');
            if (!state.videoAudioNodes) {
                const audioContext = new AC();
                const source = audioContext.createMediaElementSource(video);
                const destination = audioContext.createMediaStreamDestination();
                source.connect(destination); // No connection to speakers: process silently.
                state.videoAudioNodes = { audioContext, source, destination };
            }
            await state.videoAudioNodes.audioContext.resume();
            audioTrack = state.videoAudioNodes.destination.stream.getAudioTracks()[0];
        } catch (_) {
            // Do not silently strip audio: the user must choose the original file.
            throw new Error('حفظ صدا هنگام فشرده‌سازی در این مرورگر ممکن نیست. فایل اصلی را ارسال کنید.');
        }
        const canvasStream = canvas.captureStream(24);
        const mediaStream = new MediaStream([...canvasStream.getVideoTracks(), audioTrack]);
        let recorder;
        try {
            recorder = new MediaRecorder(mediaStream, { mimeType, videoBitsPerSecond: quality === '480' ? 620000 : 1250000, audioBitsPerSecond: 64000 });
        } catch (_) { canvasStream.getTracks().forEach((t) => t.stop()); throw new Error('مرورگر امکان ساخت ویدیوی فشرده را ندارد. فایل اصلی را ارسال کنید.'); }
        const chunks = [];
        let raf = 0, timer = null, aborted = false, settled = false;
        return new Promise((resolve, reject) => {
            const cleanup = () => {
                if (raf) cancelAnimationFrame(raf);
                if (timer) clearTimeout(timer);
                canvasStream.getTracks().forEach((t) => t.stop());
                video.pause();
                if (state.videoProcessController?.recorder === recorder) state.videoProcessController = null;
            };
            const fail = (error) => {
                if (settled) return; settled = true; cleanup(); reject(error);
            };
            state.videoProcessController = { recorder, cancel: () => { aborted = true; if (recorder.state !== 'inactive') recorder.stop(); else fail(new Error('پردازش ویدیو لغو شد.')); } };
            recorder.ondataavailable = (e) => { if (e.data?.size) chunks.push(e.data); };
            recorder.onerror = () => fail(new Error('فشرده‌سازی ویدیو در مرورگر شکست خورد.'));
            recorder.onstop = () => {
                if (settled) return;
                cleanup();
                if (aborted) return fail(new Error('پردازش ویدیو لغو شد.'));
                const type = recorder.mimeType || mimeType;
                const blob = new Blob(chunks, { type: type.split(';')[0] });
                if (blob.size < 1) return fail(new Error('ویدیوی پردازش‌شده خالی است. فایل اصلی را ارسال کنید.'));
                settled = true;
                const ext = type.startsWith('video/mp4') ? '.mp4' : '.webm';
                const name = (file.name.replace(/\.[^.]+$/, '') || 'video') + '_compressed' + ext;
                resolve({ file: new File([blob], name, { type: blob.type }), width, height, duration: video.duration });
            };
            video.onended = () => { if (recorder.state !== 'inactive') setTimeout(() => { if (recorder.state !== 'inactive') recorder.stop(); }, 150); };
            const draw = () => {
                if (aborted || settled) return;
                try { if (video.readyState >= 2) ctx.drawImage(video, 0, 0, width, height); }
                catch (_) {}
                onProgress(Math.min(99, video.currentTime / Math.max(1, video.duration) * 100));
                raf = requestAnimationFrame(draw);
            };
            try {
                video.pause(); video.currentTime = 0;
                recorder.start(1000);
                draw();
                // Browser processing happens locally, at roughly the video's real runtime.
                video.play().catch(() => { aborted = true; if (recorder.state !== 'inactive') recorder.stop(); else fail(new Error('پخش و پردازش محلی ویدیو در مرورگر مجاز نشد.')); });
                timer = setTimeout(() => { aborted = true; if (recorder.state !== 'inactive') recorder.stop(); else fail(new Error('زمان پردازش ویدیو به پایان رسید.')); }, Math.ceil(video.duration * 2300 + 20000));
            } catch (err) { fail(err); }
        });
    }

    async function confirmVideoSend() {
        if (!state.videoFile) return;
        const file = state.videoFile;
        const purpose = state.videoFilePurpose;
        const target = state.videoTargetChat;
        const replySeq = state.videoReplySeq;
        const caption = $('videoCaptionInput').value.trim();
        const quality = document.querySelector('input[name="vidQuality"]:checked')?.value || 'original';
        $('confirmVideoSendBtn').disabled = true;
        let chosen = file;
        let dim = { ...state.videoMeta };
        try {
            if (quality !== 'original') {
                videoProcessUI('در حال تبدیل ویدیو روی دستگاه شما (بدون ارسال به هیچ سرور خارجی)...', 0);
                try {
                    const result = await compressVideoInBrowser(file, quality, (pct) => videoProcessUI(`کم‌حجم‌سازی ویدیو · ${formatDuration($('videoPreviewPlayer').currentTime)} از ${formatDuration(state.videoMeta.duration)}`, pct));
                    if (result.file.size >= file.size * 0.95) {
                        if (!confirm('کاهش حجم این ویدیو محسوس نبود. آیا نسخه اصلی را با کیفیت اولیه ارسال کنم؟')) { $('confirmVideoSendBtn').disabled = false; return; }
                    } else {
                        chosen = result.file;
                        dim = { width: result.width, height: result.height, duration: result.duration };
                        showToast(`حجم ویدیو از ${formatBytes(file.size)} به ${formatBytes(chosen.size)} رسید!`, 'success');
                    }
                } catch (err) {
                    if (/لغو شد/.test(err.message)) { $('confirmVideoSendBtn').disabled = false; return; }
                    if (!confirm(`${err.message}\n\nآیا فایل اصلی را بدون فشرده‌سازی ارسال کنم؟`)) { $('confirmVideoSendBtn').disabled = false; return; }
                }
            }
            videoProcessUI('در حال آپلود تکه‌ای ویدیو روی هاست...', 100);
            const media = await uploadFileChunked(chosen, { compressImage: false, duration: dim.duration, width: dim.width, height: dim.height });
            if (!media) throw new Error('آپلود ویدیو تأیید نشد.');
            if (purpose === 'chat') await sendUploadedMedia(media, target, caption, replySeq);
            else {
                const folder = ['all', 'starred'].includes(state.activeDriveFolder) ? 'gallery' : state.activeDriveFolder;
                const res = await apiCall('drive_add_item', { folder_id: folder, title: media.original_name, note: caption, media_id: media.id, also_send_saved: true });
                if (!res.ok) { await apiCall('delete_unused_upload', { media_id: media.id }).catch(() => {}); throw new Error(res.error || 'خطا در ثبت ویدیو در فضای شخصی'); }
                loadPersonalDriveData().catch(() => {});
            }
            closeVideoModal(true);
            showToast('ویدیو با موفقیت ارسال شد 🎬', 'success');
        } catch (err) { showToast(err.message, 'error'); $('confirmVideoSendBtn').disabled = false; }
    }

    // ========================================================================
    // Voice notes (microphone), waveform player and speed/seek control
    // ========================================================================
    function getVoiceMime() {
        if (typeof MediaRecorder === 'undefined') return '';
        return ['audio/webm;codecs=opus', 'audio/webm', 'audio/mp4', 'audio/ogg;codecs=opus'].find((m) => MediaRecorder.isTypeSupported(m)) || '';
    }
    async function startVoiceRecording() {
        if (!navigator.mediaDevices?.getUserMedia || typeof MediaRecorder === 'undefined') return showToast('ضبط ویس نیازمند مرورگر سازگار و HTTPS فعال روی هاست است.', 'error');
        const mime = getVoiceMime();
        if (!mime) return showToast('ضبط صدا در این مرورگر پشتیبانی نمی‌شود.', 'error');
        try {
            const stream = await navigator.mediaDevices.getUserMedia({ audio: true });
            const chatAtStart = state.activeChatId;
            state.voiceStream = stream;
            const recorder = new MediaRecorder(stream, { mimeType: mime, audioBitsPerSecond: 56000 });
            state.mediaRecorder = recorder;
            state.voiceChunks = [];
            state.voiceCancelled = false;
            state.voiceStartTime = Date.now();
            recorder.ondataavailable = (e) => { if (e.data?.size) state.voiceChunks.push(e.data); };
            recorder.onstop = async () => {
                stream.getTracks().forEach((t) => t.stop());
                state.voiceStream = null;
                if (state.voiceTimerInterval) clearInterval(state.voiceTimerInterval);
                $('voiceRecordingRow').classList.add('hidden');
                $('composerInputRow').classList.remove('hidden');
                await apiCall('sync', { typing_chat: '' }).catch(() => {});
                if (state.voiceCancelled || !state.voiceChunks.length) return;
                const duration = Math.round((Date.now() - state.voiceStartTime) / 1000);
                const actual = recorder.mimeType || mime;
                const type = actual.split(';')[0];
                const extension = type.includes('mp4') ? 'm4a' : type.includes('ogg') ? 'ogg' : 'webm';
                const file = new File([new Blob(state.voiceChunks, { type })], `voice_${Date.now()}.${extension}`, { type });
                try {
                    const media = await uploadFileChunked(file, { isVoice: true, duration, compressImage: false });
                    if (media) await sendUploadedMedia(media, chatAtStart);
                } catch (err) { showToast(err.message, 'error'); }
            };
            recorder.start(400);
            $('composerInputRow').classList.add('hidden');
            $('voiceRecordingRow').classList.remove('hidden');
            $('voiceRecTimer').textContent = '00:00';
            apiCall('sync', { typing_chat: chatAtStart, activity_type: 'voice' }).catch(() => {});
            state.voiceTimerInterval = setInterval(() => $('voiceRecTimer').textContent = formatDuration((Date.now() - state.voiceStartTime) / 1000), 400);
        } catch (_) { showToast('دسترسی به میکروفون رد شد یا میکروفونی یافت نشد.', 'error'); }
    }
    function stopVoiceRecording(cancel = false) {
        state.voiceCancelled = cancel;
        if (state.mediaRecorder?.state === 'recording') state.mediaRecorder.stop();
        else { state.voiceStream?.getTracks().forEach((t) => t.stop()); $('composerInputRow').classList.remove('hidden'); $('voiceRecordingRow').classList.add('hidden'); }
    }
    let playingVoice = null;
    let playingVoiceEl = null;
    function handleVoicePlayerClick(el, e) {
        const speed = el.querySelector('.voice-speed-btn');
        const btn = el.querySelector('.voice-play-btn');
        const timer = el.querySelector('.voice-timer');
        const bars = [...el.querySelectorAll('.voice-bars span')];
        if (e.target.closest('.voice-speed-btn')) {
            const next = Number(speed.dataset.speed) === 1 ? 1.5 : Number(speed.dataset.speed) === 1.5 ? 2 : 1;
            speed.dataset.speed = String(next); speed.textContent = `${next}x`;
            if (playingVoiceEl === el && playingVoice) playingVoice.playbackRate = next;
            return;
        }
        const bar = e.target.closest('.voice-bars span');
        if (bar && playingVoiceEl === el && playingVoice?.duration) { playingVoice.currentTime = (Number(bar.dataset.barIdx) + 0.5) / bars.length * playingVoice.duration; return; }
        if (playingVoiceEl === el && playingVoice) {
            if (playingVoice.paused) { playingVoice.play().catch(() => {}); btn.textContent = '⏸'; }
            else { playingVoice.pause(); btn.textContent = '▶'; }
            return;
        }
        if (playingVoice) { playingVoice.pause(); const oldBtn = playingVoiceEl?.querySelector('.voice-play-btn'); if (oldBtn) oldBtn.textContent = '▶'; }
        const audio = new Audio(el.dataset.voiceSrc);
        audio.playbackRate = Number(speed.dataset.speed || 1);
        playingVoice = audio; playingVoiceEl = el; btn.textContent = '⏸';
        audio.ontimeupdate = () => {
            const fraction = Math.min(1, audio.currentTime / Math.max(0.001, audio.duration || 1));
            bars.forEach((b, i) => b.classList.toggle('played', i < Math.floor(fraction * bars.length)));
            timer.textContent = formatDuration(audio.currentTime);
        };
        audio.onended = () => { btn.textContent = '▶'; bars.forEach((b) => b.classList.remove('played')); timer.textContent = formatDuration(audio.duration); };
        audio.play().catch(() => { btn.textContent = '▶'; showToast('پخش این فایل صوتی در مرورگر ممکن نیست.', 'error'); });
    }

    // ========================================================================
    // Personal Cloud Drive, Folders, Saved Messages & Secure Notes
    // ========================================================================
    function openModal(id) { $(id)?.classList.remove('hidden'); }
    function closeModal(id) {
        $(id)?.classList.add('hidden');
        if (id === 'stickerCreateModal' && state.stickerPreviewUrl) { URL.revokeObjectURL(state.stickerPreviewUrl); state.stickerPreviewUrl = ''; state.stickerSource = null; }
        if (id === 'settingsModal' && state.avatarPendingId) {
            apiCall('delete_unused_upload', { media_id: state.avatarPendingId }).catch(() => {});
            state.avatarPendingId = null;
        }
        markReadAfterRender();
    }
    async function openPersonalDriveModal(folder = 'all') {
        state.activeDriveFolder = folder;
        openModal('driveModal');
        await loadPersonalDriveData();
    }
    async function loadPersonalDriveData(append = false) {
        const folder = state.activeDriveFolder;
        const cursor = append ? state.driveData?.next_cursor : null;
        if (append && !cursor) return;
        try {
            const res = await apiCall('drive_list', { folder_id: folder, before_item_id: cursor || undefined, limit: 80 });
            if (!res?.ok || folder !== state.activeDriveFolder) { if (res && !res.ok) displayError(res); return; }
            const previous = append ? (state.driveData?.items || []) : [];
            const known = new Set(previous.map((it) => it.id));
            const combined = [...previous, ...(res.items || []).filter((it) => !known.has(it.id))];
            state.driveData = { ...res, items: combined };
            $('driveLoadMoreBtn').classList.toggle('hidden', !res.has_more);
            state.quota = res.quota;
            updateSidebarQuotaUI();
            $('driveQuotaNumbers').textContent = `${res.quota.used_human} / ${res.quota.quota_human}`;
            $('driveQuotaFill').style.width = `${Math.max(1, Number(res.quota.percent) || 1)}%`;
            const system = [{ id: 'all', name: 'همه موارد شخصی', icon: '🗂️', system: true }, { id: 'starred', name: 'موارد ستاره‌دار', icon: '⭐', system: true }];
            const folders = (res.folders || []).map((f) => ({ ...f, icon: ({ important: '🛡️', gallery: '🖼️', notes: '📝' })[f.id] || '📁' }));
            $('driveFolderList').innerHTML = [...system, ...folders].map((f) => `<div class="drive-folder-row"><button type="button" class="drive-folder-btn ${state.activeDriveFolder === f.id ? 'active' : ''}" data-dfolder="${attr(f.id)}"><span>${escapeHtml(f.icon)}</span><span>${escapeHtml(f.name)}</span></button>${!f.system ? `<button type="button" class="act-btn drive-folder-remove" data-remove-folder="${attr(f.id)}" title="حذف پوشه و انتقال محتوا به فایل‌ها">×</button>` : ''}</div>`).join('');
            const items = state.driveData.items;
            $('driveItemsGrid').innerHTML = items.length ? items.map((it) => {
                let preview = '';
                if (it.media?.id) {
                    const src = attr(mediaUrl(it.media.id));
                    const dl = attr(mediaUrl(it.media.id, true));
                    const name = attr(it.media.original_name || 'document');
                    if (it.media.category === 'image') preview = `<img src="${src}" class="drive-card-thumb" loading="lazy" data-lightbox-src="${src}" data-lightbox-dl="${dl}" data-lightbox-name="${name}">`;
                    else preview = `<a href="${dl}" download="${name}" class="file-card"><span class="file-ext-icon">${escapeHtml((it.media.extension || 'FILE').slice(0, 4))}</span><span class="file-card-info"><span class="file-card-name">${escapeHtml(it.media.original_name)}</span><span class="file-card-size">${escapeHtml(it.media.size_human || '')}</span></span>⬇️</a>`;
                }
                return `<div class="drive-card"><div>${preview}<div class="drive-card-title mt-2">${escapeHtml(it.title)}</div>${it.note ? `<div class="drive-card-note mt-2">${escapeHtml(it.note)}</div>` : ''}</div><div class="drive-card-foot"><span>${escapeHtml(formatShortDate(it.created_at))}</span><span><button type="button" class="act-btn" data-drive-op="toggle_star" data-drive-id="${attr(it.id)}" title="ستاره‌دار">${it.starred ? '⭐' : '☆'}</button><button type="button" class="act-btn" data-drive-op="delete" data-drive-id="${attr(it.id)}" title="حذف">🗑️</button></span></div></div>`;
            }).join('') : '<div class="muted" style="grid-column:1/-1;text-align:center;padding:38px">این پوشه خالی است. از دکمه‌های بالا فایل یا یادداشت اضافه کنید.</div>';
        } catch (err) { showToast(err.message, 'error'); }
    }
    async function uploadToPersonalDrive(files) {
        const folder = ['all', 'starred'].includes(state.activeDriveFolder) ? 'files' : state.activeDriveFolder;
        let completed = 0;
        for (const file of files) {
            try {
                if (isVideoFile(file) && getStored('hamrah_video_prompt', '1') !== '0') {
                    const sent = await openVideoModal(file, 'drive');
                    if (sent) completed++;
                    continue;
                }
                const media = await uploadFileChunked(file);
                if (!media) throw new Error('بارگذاری فایل ناموفق بود.');
                const res = await apiCall('drive_add_item', { folder_id: folder, title: media.original_name, media_id: media.id, also_send_saved: true });
                if (!res.ok) { await apiCall('delete_unused_upload', { media_id: media.id }).catch(() => {}); return displayError(res); }
                completed++;
            } catch (err) { showToast(err.message, 'error'); }
        }
        if (completed) showToast(`${completed} فایل در فضای شخصی ذخیره شد.`, 'success');
        await loadPersonalDriveData();
    }
    async function saveDriveNote() {
        const title = $('driveNoteTitle').value.trim();
        const note = $('driveNoteBody').value.trim();
        if (!title && !note) return showToast('عنوان یا متن یادداشت را وارد کنید.');
        const folder = ['all', 'starred'].includes(state.activeDriveFolder) ? 'notes' : state.activeDriveFolder;
        try {
            const res = await apiCall('drive_add_item', { folder_id: folder, title: title || 'یادداشت شخصی', note, also_send_saved: $('driveAlsoSavedCheck').checked });
            if (!res.ok) return displayError(res);
            $('driveNoteTitle').value = ''; $('driveNoteBody').value = '';
            $('driveNoteForm').classList.add('hidden');
            await loadPersonalDriveData();
            showToast('یادداشت به‌صورت رمزنگاری‌شده ذخیره شد.', 'success');
        } catch (err) { showToast(err.message, 'error'); }
    }

    // ========================================================================
    // User Settings: Profile, Change ID, Password, Sessions, Appearance, Wipe
    // ========================================================================
    function renderProfilePreview() {
        if (!state.user) return;
        const av = $('profPreviewAvatar');
        av.style.background = $('profColor').value || '#2AABEE';
        if (state.avatarRemovalRequested) av.textContent = Array.from($('profDisplayName').value || state.user.username)[0] || '؟';
        else if (state.avatarPendingId) av.innerHTML = `<img src="${attr(mediaUrl(state.avatarPendingId))}" alt="">`;
        else av.innerHTML = getAvatarInnerHtml(state.user);
    }
    function openSettingsModal(pane = 'account') {
        if (!state.user) return;
        const u = state.user;
        state.avatarPendingId = null;
        state.avatarRemovalRequested = false;
        $('profDisplayName').value = u.display_name || '';
        $('profUsername').value = u.username;
        $('profBio').value = u.bio || '';
        $('profColor').value = u.avatar_color || '#2AABEE';
        $('profCurPass').value = ''; $('profNewPass').value = ''; $('profNewPassConfirm').value = '';
        $('prefThemeSelect').value = state.theme;
        $('prefFontSizeSelect').value = state.fontSize;
        $('prefFontFaceSelect').value = state.fontFace;
        $('prefSoundCheck').checked = state.soundEnabled;
        $('prefEnterSendCheck').checked = state.enterToSend;
        renderBlockedUsers();
        $('prefAutoImgCompressCheck').checked = getStored('hamrah_image_compress', '1') !== '0';
        $('prefAutoVidPromptCheck').checked = getStored('hamrah_video_prompt', '1') !== '0';
        $('compressImagesCheck').checked = getStored('hamrah_image_compress', '1') !== '0';
        $('profUsername').disabled = u.username === state.primaryAdminId || (!state.config.allow_user_username_change && u.role !== 'admin');
        $('profUsername').title = u.username === state.primaryAdminId ? 'آیدی مدیر اصلی ثابت است تا مخاطب دائمی همه باقی بماند.' : '';
        for (const id of ['profDisplayName','profBio','profColor','uploadAvatarPhotoBtn','removeAvatarPhotoBtn']) {
            $(id).disabled = !state.config.allow_user_profile_edit && u.role !== 'admin';
        }
        $('liveEncryptionBadge').textContent = state.config.encryption_active ? 'AES-256-GCM برای متن و آپلودهای تازه روی سرور فعال است ✅' : '⚠️ موتور OpenSSL روی هاست فعال نیست؛ به مدیر اطلاع دهید.';
        renderProfilePreview();
        updateSidebarQuotaUI();
        activateSettingsPane(pane);
        openModal('settingsModal');
        if (pane === 'security') refreshMySessions();
    }
    function activateSettingsPane(key) {
        document.querySelectorAll('.u-set-tab').forEach((btn) => btn.classList.toggle('active', btn.dataset.ustab === key));
        document.querySelectorAll('.u-set-pane').forEach((pane) => pane.classList.toggle('hidden', pane.dataset.pane !== key));
        if (key === 'security') refreshMySessions();
    }
    async function saveAccountProfile() {
        const display = $('profDisplayName').value.trim();
        const username = $('profUsername').value.trim().replace(/^@/, '').toLowerCase();
        if (!display || !/^[a-z0-9][a-z0-9_.-]{2,31}$/.test(username)) return showToast('نام نمایشی و آیدی معتبر (۳ تا ۳۲ نویسه انگلیسی) وارد کنید.', 'error');
        const payload = { display_name: display, new_username: username, bio: $('profBio').value.trim(), avatar_color: $('profColor').value };
        if (state.avatarPendingId !== null || state.avatarRemovalRequested) payload.avatar_media_id = state.avatarRemovalRequested ? '' : state.avatarPendingId;
        const oldUsername = state.user.username;
        try {
            const res = await apiCall('update_profile', payload);
            if (!res.ok) return displayError(res);
            state.avatarPendingId = null;
            state.user = res.user;
            state.users = res.users || state.users;
            state.dialogs = res.dialogs || state.dialogs;
            updateMenuUserHeader();
            if (username !== oldUsername) {
                // A username migration must also preserve this device's
                // account-specific appearance without leaking it to others.
                store(`hamrah_theme:${username}`, getStored(`hamrah_theme:${oldUsername}`, state.theme));
                store(`hamrah_fontsize:${username}`, getStored(`hamrah_fontsize:${oldUsername}`, state.fontSize));
                store(`hamrah_fontface:${username}`, getStored(`hamrah_fontface:${oldUsername}`, state.fontFace));
                store(`hamrah_enter_send:${username}`, state.enterToSend ? '1' : '0');
                dropStored(`hamrah_theme:${oldUsername}`); dropStored(`hamrah_fontsize:${oldUsername}`); dropStored(`hamrah_fontface:${oldUsername}`); dropStored(`hamrah_enter_send:${oldUsername}`);
                const boot = await apiCall('bootstrap');
                if (boot.ok) enterAuthenticatedApp(boot);
                showToast(`آیدی شما به @${username} تغییر کرد و تاریخچه گفتگو حفظ شد.`, 'success');
            } else {
                renderDialogList(); updateChatHeaderUI();
                showToast('مشخصات حساب ذخیره شد.', 'success');
            }
            closeModal('settingsModal');
        } catch (err) { showToast(err.message, 'error'); }
    }
    async function saveNewPassword() {
        const current = $('profCurPass').value;
        const next = $('profNewPass').value;
        if (next.length < 8) return showToast('رمز جدید باید دست‌کم ۸ کاراکتر باشد.', 'error');
        if (next !== $('profNewPassConfirm').value) return showToast('تکرار رمز با رمز جدید یکسان نیست.', 'error');
        try {
            const res = await apiCall('update_profile', { current_password: current, new_password: next });
            if (!res.ok) return displayError(res);
            $('profCurPass').value = ''; $('profNewPass').value = ''; $('profNewPassConfirm').value = '';
            showToast('رمز عبور تغییر کرد و دستگاه‌های دیگر از حساب خارج شدند.', 'success');
            refreshMySessions();
        } catch (err) { showToast(err.message, 'error'); }
    }
    async function refreshMySessions() {
        if (!$('mySessionsList') || !state.user) return;
        try {
            const res = await apiCall('my_sessions');
            if (!res.ok) return;
            $('mySessionsList').innerHTML = (res.sessions || []).map((s) => `<div class="session-line"><div><strong>${s.is_current ? '📱 این دستگاه (فعال)' : '🌐 دستگاه دیگر'}</strong><small>${escapeHtml(s.ua || 'مرورگر')} · IP: <span dir="ltr">${escapeHtml(s.ip)}</span></small></div><span>${escapeHtml(formatShortDate(s.last_seen))}</span></div>`).join('') || '<span class="muted">نشستی ثبت نشده است.</span>';
        } catch (_) {}
    }
    async function wipePersonalSection(type) {
        const map = {
            saved: ['پیام‌های ذخیره‌شده شما', 'clear_chat_history', { chat_id: 'saved_' + state.user.username }],
            ai: ['تاریخچه دستیار هوشمند شما', 'clear_chat_history', { chat_id: 'ai_' + state.user.username }],
            drive: ['تمام فایل‌ها و یادداشت‌های فضای ابری شخصی شما', 'drive_clear_all', {}],
        };
        const item = map[type];
        if (!item || !confirm(`آیا از حذف برگشت‌ناپذیر ${item[0]} مطمئن هستید؟ نسخه‌های باقی‌مانده در سایر گفتگوها محفوظ خواهند ماند.`)) return;
        try {
            const res = await apiCall(item[1], item[2]);
            if (!res.ok) return displayError(res);
            if (res.quota) { state.quota = res.quota; updateSidebarQuotaUI(); }
            if (state.activeChatId === item[2].chat_id) selectChat(state.activeChatId, false);
            if (type === 'drive' && !$('driveModal').classList.contains('hidden')) loadPersonalDriveData();
            showToast(`${item[0]} پاک شد.`, 'success');
        } catch (err) { showToast(err.message, 'error'); }
    }

    async function resetMyAccountData() {
        const password = $('resetMyPassword').value;
        const confirmation = $('resetMyConfirmation').value.trim();
        if (!password || confirmation !== 'RESET') return showToast('برای ریست، رمز فعلی و عبارت RESET را وارد کنید.', 'error');
        if (!confirm('همهٔ داده‌های شخصی شما حذف می‌شوند و تاریخچه مشترک فقط از نمای شما پنهان می‌شود. دیگر دستگاه‌ها خارج می‌شوند. این عمل بازگشت‌ناپذیر است؛ ادامه می‌دهید؟')) return;
        const userId = state.user?.username;
        $('resetMyDataBtn').disabled = true;
        try {
            const res = await apiCall('reset_my_data', { current_password: password, confirmation });
            if (!res.ok) return displayError(res);
            $('resetMyPassword').value = ''; $('resetMyConfirmation').value = '';
            dropStored(`hamrah_theme:${userId}`); dropStored(`hamrah_fontsize:${userId}`); dropStored(`hamrah_fontface:${userId}`);
            applyTheme('light'); applyFontSize('normal'); applyFontFace('vazir');
            closeModal('settingsModal');
            const boot = await apiCall('bootstrap');
            if (boot.ok) enterAuthenticatedApp(boot);
            showToast('داده‌های شخصی شما ریست شد؛ گفتگوهای دیگران دست‌نخورده است.', 'success');
        } catch (err) { showToast(err.message, 'error'); }
        finally { $('resetMyDataBtn').disabled = false; }
    }

    // ========================================================================
    // Primary-admin-only account audit. No usage metadata in general user APIs.
    // ========================================================================
    function renderAccountActivity() {
        const report = state.accountActivity;
        if (!report) return;
        const summary = report.summary || {};
        const faNumber = (value) => new Intl.NumberFormat('fa-IR').format(Number(value) || 0);
        const kpis = [
            ['total', 'کل حساب‌ها'], ['online', 'اکنون آنلاین'],
            ['never_signed_in', 'هرگز وارد نشده'], ['inactive_7d', '۷ روز بی‌حضور'],
            ['inactive_30d', '۳۰ روز بی‌حضور'], ['unknown', 'تاریخچهٔ نامشخص'],
        ];
        $('activityKpis').innerHTML = kpis.map(([key, title]) =>
            `<div class="activity-kpi"><strong>${faNumber(summary[key])}</strong><span>${title}</span></div>`).join('');
        const query = $('activitySearchInput').value.trim().toLocaleLowerCase('fa-IR');
        const filter = $('activityFilterSelect').value;
        const sorted = [...(report.users || [])].sort((a, b) => {
            const priority = { never_signed_in: 0, used: 1, unknown: 2, online: 3 };
            const rank = (priority[a.status] ?? 4) - (priority[b.status] ?? 4);
            if (rank) return rank;
            if (a.status === 'never_signed_in') return (b.days_since_creation || 0) - (a.days_since_creation || 0);
            if (a.status === 'used') return (b.days_offline || 0) - (a.days_offline || 0);
            return String(a.username).localeCompare(String(b.username));
        });
        const shown = sorted.filter((u) => {
            if (query && !`${u.display_name} ${u.username}`.toLocaleLowerCase('fa-IR').includes(query)) return false;
            if (filter === 'inactive_7d' || filter === 'inactive_30d') {
                return u.status === 'used' && Number.isInteger(u.days_offline) &&
                    u.days_offline >= (filter === 'inactive_30d' ? 30 : 7);
            }
            return filter === 'all' || u.status === filter;
        });
        $('activityMatchCount').textContent = `${faNumber(shown.length)} حساب از ${faNumber(sorted.length)} حساب نمایش داده شده است.`;
        $('activityUsersList').innerHTML = shown.map((u) => {
            const status = ['online', 'used', 'never_signed_in', 'unknown'].includes(u.status) ? u.status : 'unknown';
            const age = Number.isInteger(u.days_since_creation) && u.days_since_creation >= 0 ? u.days_since_creation : null;
            const idle = Number.isInteger(u.days_offline) && u.days_offline >= 0 ? u.days_offline : null;
            const elapsed = (days) => days === 0 ? 'کمتر از یک روز' : `${faNumber(days)} روز`;
            const badge = status === 'online' ? '● اکنون آنلاین' : status === 'never_signed_in'
                ? 'هنوز وارد نشده' : status === 'unknown' ? 'آمار گذشته نامشخص'
                : (idle === null ? 'آخرین حضور نامشخص' : `${elapsed(idle)} بدون حضور`);
            const note = status === 'never_signed_in' && age !== null
                ? `${elapsed(age)} از ساخت حساب می‌گذرد و ورود موفقی ثبت نشده است.`
                : status === 'unknown' ? 'این حساب پیش از ثبت آمار ساخته شده؛ بدون شواهد، آن را بلااستفاده فرض نکنید.'
                : status === 'online' ? 'یک تب باز است؛ شاید برنامه در پس‌زمینه باشد.'
                : 'حضور یا ورود ثبت شده؛ این آمار به‌تنهایی ارسال پیام را ثابت نمی‌کند.';
            const creation = u.created_at ? formatActivityDate(u.created_at) : 'نامشخص (حساب قدیمی)';
            const login = u.last_login_at ? formatActivityDate(u.last_login_at)
                : (status === 'never_signed_in' ? 'هرگز' : 'ثبت نشده در این نسخه');
            const seen = u.last_activity_at ? formatActivityDate(u.last_activity_at) : 'ثبت نشده';
            const seenLabel = u.last_seen_at ? 'آخرین حضور ثبت‌شده' : 'آخرین حضور / ورود ثبت‌شده';
            const role = u.username === state.primaryAdminId ? 'مدیر اصلی'
                : u.role === 'admin' ? 'مدیر' : 'کاربر';
            return `<article class="activity-card" data-activity-user="${attr(u.username)}" data-activity-status="${status}">
                <header class="activity-card-head"><div><strong>${escapeHtml(u.display_name || u.username)}</strong>
                    <code dir="ltr">@${escapeHtml(u.username)}</code></div>
                    <span class="activity-status ${status}">${escapeHtml(badge)}</span></header>
                <div class="activity-meta">${escapeHtml(role)}${!u.active ? ' · حساب مسدود' : ''}</div>
                <dl class="activity-dates"><div><dt>تاریخ و ساعت ساخت</dt><dd>${escapeHtml(creation)}</dd></div>
                    <div><dt>آخرین ورود موفق</dt><dd>${escapeHtml(login)}</dd></div>
                    <div><dt>${escapeHtml(seenLabel)}</dt><dd>${escapeHtml(seen)}</dd></div></dl>
                <p class="activity-note">${escapeHtml(note)}</p>
            </article>`;
        }).join('') || '<p class="muted activity-empty">حسابی با این جستجو یا فیلتر پیدا نشد.</p>';
    }
    async function refreshAccountActivity() {
        if (state.user?.role !== 'admin' || state.user.username !== state.primaryAdminId) return;
        const button = $('activityRefreshBtn');
        if (button.disabled) return;
        button.disabled = true;
        $('activityUpdatedAt').textContent = 'در حال دریافت گزارش...';
        try {
            const res = await apiCall('admin_account_activity');
            if (!res.ok) {
                state.accountActivity = null;
                $('activityUsersList').innerHTML = '';
                $('activityKpis').innerHTML = '';
                $('activityMatchCount').textContent = '';
                $('activityUpdatedAt').textContent = 'دریافت گزارش ممکن نشد.';
                return displayError(res);
            }
            state.accountActivity = res.report;
            renderAccountActivity();
            $('activityUpdatedAt').textContent = `آخرین به‌روزرسانی: ${formatActivityDate(res.report.server_time)} (ساعت ایران)`;
        } catch (err) {
            $('activityUpdatedAt').textContent = 'دریافت آمار ممکن نشد؛ دوباره تلاش کنید.';
            showToast(err.message, 'error');
        } finally { button.disabled = false; }
    }

    // ========================================================================
    // Admin panel: create users, full edit/rename/disable/delete & JSON monitor
    // ========================================================================
    async function openAdminModal() {
        if (state.user?.role !== 'admin') return;
        openModal('adminModal');
        await refreshAdminData();
        if (document.querySelector('.admin-tab.active')?.dataset.atab === 'activity') refreshAccountActivity();
        runBrowserSecurityCheck();
    }
    async function refreshAdminData() {
        try {
            const res = await apiCall('admin_overview');
            if (!res.ok) return displayError(res);
            state.adminData = res;
            const users = res.users || [];
            $('adminUsersTableBody').innerHTML = users.map((u) => `<tr><td><div style="display:flex;gap:7px;align-items:center"><span class="msg-avatar-mini" style="background:${attr(u.avatar_color || '#2AABEE')}">${escapeHtml(Array.from(u.display_name || u.username)[0])}</span><strong>${escapeHtml(u.display_name)}</strong></div></td><td dir="ltr"><code>${escapeHtml(u.username)}</code></td><td>${u.role === 'admin' ? '<span class="shard-badge">مدیر</span>' : 'کاربر عادی'}</td><td dir="ltr">${escapeHtml(u.used_human || '0 B')} / ${Number(u.quota_mb)} MB</td><td>${u.active ? '🟢 فعال' : '🔴 مسدود'}</td><td><button type="button" class="btn-sm" data-admin-edit="${attr(u.username)}">✏️ ویرایش</button> ${u.username !== state.user?.username ? `<button type="button" class="btn-secondary btn-sm" data-admin-reset="${attr(u.username)}">🧹 ریست داده</button> <button type="button" class="btn-danger-ghost btn-sm" data-admin-delete="${attr(u.username)}">حذف حساب</button>` : ''}</td></tr>`).join('');
            $('rawUsersJsonTextarea').value = res.raw_users_json || '[]';
            const report = res.shards_report || {};
            $('adminShardKpis').innerHTML = `<div class="kpi-card"><strong>${report.total_chats || 0}</strong><span>گفتگو</span></div><div class="kpi-card"><strong>${report.total_shards || 0}</strong><span>قطعه JSON</span></div><div class="kpi-card"><strong>${report.total_messages || 0}</strong><span>پیام</span></div><div class="kpi-card"><strong>${escapeHtml(report.total_json_human || '0 B')}</strong><span>حجم متن</span></div>`;
            $('cfgMaxShardKb').value = Math.round(Number(report.max_shard_bytes || 204800) / 1024);
            $('cfgMaxShardMsgs').value = Number(report.max_shard_messages || 150);
            $('adminShardsTableBody').innerHTML = (report.chats || []).map((c) => `<tr><td dir="ltr"><code>${escapeHtml(c.chat_id)}</code></td><td>${Number(c.total_messages)}</td><td>${Number(c.shard_count)}</td><td dir="ltr">${escapeHtml(c.total_size_human)}</td><td>${(c.shards || []).map((sh) => `<span class="shard-badge">${escapeHtml(sh.file)} · ${formatBytes(sh.size_bytes)}</span>`).join(' ') || '–'}</td></tr>`).join('');
            const cfg = res.config || {};
            $('cfgAppTitle').value = cfg.app_title || 'چت امن';
            $('cfgGithubUrl').value = cfg.github_url || '';
            $('cfgAppSubtitle').value = cfg.app_subtitle || '';
            $('cfgMaxUploadMb').value = cfg.max_upload_mb || 150;
            $('cfgVidThresholdMb').value = cfg.video_compress_threshold_mb || 8;
            $('cfgAllowProfileEdit').checked = cfg.allow_user_profile_edit !== false;
            $('cfgAllowUsernameChange').checked = cfg.allow_user_username_change !== false;
            const ai = cfg.ai_bridge || {};
            $('aiBaseUrl').value = ai.api_base_url || '';
            $('aiModel').value = ai.model || '';
            $('aiApiKey').value = ai.api_key || '';
            $('aiSystemPrompt').value = ai.system_prompt || '';
            $('aiEnabledCheck').checked = ai.enabled !== false;
            renderWebToolsEditor(cfg.web_tools || []);
        } catch (err) { showToast(err.message, 'error'); }
    }
    function resetAdminUserForm() {
        $('auIsNew').value = '1'; $('auOriginalUsername').value = '';
        $('auUsername').value = ''; $('auPassword').value = '';
        $('auPassword').required = true;
        $('auDisplayName').value = '';
        $('auRole').value = 'user'; $('auQuota').value = '1024'; $('auActive').value = '1';
        $('userFormTitle').textContent = '➕ تعریف کاربر جدید';
        $('auPasswordLabel').textContent = 'رمز عبور (حداقل ۸ کاراکتر)';
    }
    function openAdminUserForEdit(username) {
        const u = state.adminData?.users?.find((item) => item.username === username);
        if (!u) return;
        $('auIsNew').value = '0'; $('auOriginalUsername').value = u.username;
        $('auUsername').value = u.username;
        $('auPassword').value = ''; $('auPassword').required = false;
        $('auPassword').placeholder = 'خالی بگذارید تا رمز قبلی حفظ شود';
        $('auDisplayName').value = u.display_name;
        $('auRole').value = u.role;
        $('auQuota').value = u.quota_mb;
        $('auActive').value = u.active ? '1' : '0';
        $('userFormTitle').textContent = `✏️ ویرایش ${u.display_name} (@${u.username})`;
        $('auPasswordLabel').textContent = 'رمز عبور جدید (خالی = بدون تغییر)';
        $('auUsername').focus();
    }
    function toolRowHtml(tool) {
        const types = [
            ['wikipedia', '📚 ویکی‌پدیا (زبان fa/en/...)'],
            ['duckduckgo', '🔍 موتور جستجوی وب متنی'],
            ['url_reader', '📄 متن‌خوان لینک وب'],
            ['builtin_status', '📡 تست اتصال هاست'],
            ['custom_url', '📰 نشانی سفارشی / RSS / JSON'],
            ['telegram_public', '✈️ ۲۰ پست کانال عمومی تلگرام'],
        ];
        return `<div class="web-tool-row" data-web-id="${attr(tool.id || '')}">
            <input type="text" class="wt-icon" title="ایموجی ابزار" value="${attr(tool.icon || '🔹')}" maxlength="6">
            <input type="text" class="wt-title" title="نام ابزار" placeholder="عنوان ابزار" value="${attr(tool.title || '')}">
            <input type="text" class="wt-command ltr-input" title="دستور ابزار" placeholder="/news" value="${attr(tool.command || '')}">
            <select class="wt-type" title="نوع ابزار">${types.map(([value, label]) => `<option value="${value}" ${tool.type === value ? 'selected' : ''}>${label}</option>`).join('')}</select>
            <input type="text" class="wt-lang ltr-input" title="زبان ویکی‌پدیا" placeholder="fa / en" value="${attr(tool.lang || 'fa')}">
            <input type="text" class="wt-url ltr-input" dir="ltr" title="آدرس سفارشی؛ {query} برای جستجو" placeholder="https://example.com/rss?q={query}" value="${attr(tool.url_template || '')}">
            <input type="text" class="wt-placeholder" title="راهنمای ورودی" placeholder="راهنمای دستور..." value="${attr(tool.placeholder || '')}">
            <label class="checkbox-inline wt-checks"><input type="checkbox" class="wt-enabled" ${tool.enabled ? 'checked' : ''}> فعال</label>
            <label class="checkbox-inline wt-checks"><input type="checkbox" class="wt-chip" ${tool.quick_chip ? 'checked' : ''}> دکمه سریع</label>
            <button type="button" class="btn-danger-ghost btn-sm wt-remove" title="حذف این ابزار">🗑️ حذف</button>
        </div>`;
    }
    function renderWebToolsEditor(tools) { $('adminWebToolsList').innerHTML = tools.map(toolRowHtml).join('') || '<div class="muted">ابزار وبی تعریف نشده است. دکمه «افزودن ابزار جدید» را بزنید.</div>'; }
    function collectWebTools() {
        const rows = [...document.querySelectorAll('#adminWebToolsList .web-tool-row')];
        const commands = new Set();
        return rows.map((row) => {
            const get = (sel) => row.querySelector(sel)?.value.trim() || '';
            const type = get('.wt-type');
            const command = get('.wt-command');
            if (!/^\/[a-zA-Z0-9_-]{1,23}$/.test(command)) throw new Error('دستور هر ابزار باید با / شروع شود و فقط حروف انگلیسی، عدد و زیرخط داشته باشد.');
            if (commands.has(command) || command === '/help') throw new Error('دستورهای ابزارها باید یکتا باشند و /help رزرو شده است.');
            commands.add(command);
            if (type === 'custom_url') {
                const url = get('.wt-url');
                if (!/^https?:\/\//i.test(url)) throw new Error(`نشانی اینترنتی ابزار ${command} باید با https:// یا http:// شروع شود.`);
            }
            return {
                id: row.dataset.webId || 'tool_' + Math.random().toString(36).slice(2, 9),
                title: get('.wt-title') || 'ابزار جدید', icon: get('.wt-icon') || '🔹',
                command, type, lang: get('.wt-lang') || 'fa',
                url_template: get('.wt-url'), placeholder: get('.wt-placeholder'),
                enabled: row.querySelector('.wt-enabled').checked, quick_chip: row.querySelector('.wt-chip').checked,
            };
        });
    }
    async function runBrowserSecurityCheck() {
        const box = $('securityCheckResult');
        box.textContent = 'در حال آزمون واقعی مسدود بودن فایل‌های محافظت‌شده...';
        const probes = ['data/users.json', 'data/user_activity.json', 'data/presence.json', 'data/.master_secret.php'];
        const statuses = [];
        for (const path of probes) {
            try {
                const res = await fetch(path + '?security_probe=' + Date.now(), { cache: 'no-store' });
                statuses.push({ path, code: res.status, protected: res.status === 403 || res.status === 404 });
            } catch (_) { statuses.push({ path, code: 'خطای شبکه', protected: false }); }
        }
        box.innerHTML = statuses.map((item) => `<div style="color:${item.protected ? 'var(--success)' : 'var(--danger)'}">${item.protected ? '✅' : '⚠️'} <code>${escapeHtml(item.path)}</code> → HTTP ${escapeHtml(item.code)}</div>`).join('') + `<small class="muted">برای استفادهٔ واقعی HTTPS را در سی‌پنل فعال کنید؛ اگر هرکدام از فایل‌های بالا HTTP 200 نمایش می‌دهد، بلافاصله هاست را امن‌سازی کنید. رمزنگاری متن جایگزین HTTPS و پشتیبان‌گیری نیست.</small>`;
    }

    // ========================================================================
    // Event wiring. Every interaction maps to a matching secured API action.
    // ========================================================================
    function renderEmojiGrid() {
        const isStickers = state.emojiTab === 'stickers';
        const tabs = `<div class="emoji-popover-tabs"><button type="button" data-emoji-tab="emoji" class="${isStickers ? '' : 'active'}">😊 ایموجی</button><button type="button" data-emoji-tab="stickers" class="${isStickers ? 'active' : ''}">◈ استیکرها</button></div>`;
        if (!isStickers) {
            $('emojiPopover').innerHTML = tabs + POPULAR_EMOJIS.map((emo) => `<button type="button" class="emoji-cell" data-emo="${attr(emo)}">${escapeHtml(emo)}</button>`).join('');
            return;
        }
        const blocked = !!state.quota?.media_blocked;
        $('emojiPopover').innerHTML = tabs + `<div class="sticker-popover-head"><button type="button" data-create-sticker="1" class="btn-secondary btn-sm" ${blocked ? 'disabled' : ''}>＋ ساخت استیکر</button><small>${blocked ? 'تا آزادسازی فضای رسانه، ساخت/ارسال استیکر متوقف است.' : 'از عکس خودتان استیکر بسازید و بفرستید.'}</small></div>`
            + `<div class="sticker-grid">${state.stickers.length ? state.stickers.map((item) => {
                const id = item.media?.id || '';
                return `<div class="sticker-tile"><button type="button" data-send-sticker="${attr(id)}" title="ارسال ${attr(item.label || 'استیکر')}" ${blocked ? 'disabled' : ''}><img src="${attr(mediaUrl(id))}" loading="lazy" alt="${attr(item.label || 'استیکر')}"></button><button type="button" class="sticker-remove" data-remove-sticker="${attr(id)}" title="حذف از مجموعه">×</button></div>`;
            }).join('') : '<p class="muted">هنوز استیکری ندارید. از تصویر دلخواه شروع کنید!</p>'}</div>`;
    }
    async function loadStickerCollection() {
        try {
            const res = await apiCall('sticker_list');
            if (!res.ok) return displayError(res);
            state.stickers = res.stickers || [];
            if (state.emojiTab === 'stickers') renderEmojiGrid();
        } catch (err) { showToast(err.message, 'error'); }
    }
    function showStickerCreator() {
        if (state.quota?.media_blocked) return showToast('ارسال رسانه تا زیر ۸۰٪ شدن فضا متوقف است؛ استیکرهای قبلی را می‌توانید حذف کنید.', 'error');
        $('emojiPopover').classList.add('hidden');
        $('stickerCreateLabel').value = '';
        $('stickerCreateFileInput').value = '';
        state.stickerSource = null;
        $('stickerCreatePreview').classList.add('hidden');
        $('stickerPreviewHint').classList.remove('hidden');
        openModal('stickerCreateModal');
    }
    async function makeStickerFile(file) {
        if (!file || file.size < 1 || file.size > 8 * 1048576 || !/^image\/(png|jpe?g|webp|gif)$/.test(file.type)) {
            throw new Error('یک تصویر PNG، WebP، JPG یا GIF حداکثر ۸ مگابایتی انتخاب کنید.');
        }
        const src = URL.createObjectURL(file);
        try {
            const image = await new Promise((resolve, reject) => {
                const el = new Image();
                el.onload = () => resolve(el);
                el.onerror = () => reject(new Error('باز کردن تصویر در مرورگر ممکن نشد.'));
                el.src = src;
            });
            if (!image.naturalWidth || !image.naturalHeight || image.naturalWidth > 4096 || image.naturalHeight > 4096) throw new Error('ابعاد تصویر ورودی نباید از ۴۰۹۶ پیکسل بیشتر باشد.');
            const canvas = document.createElement('canvas'); canvas.width = 512; canvas.height = 512;
            const ctx = canvas.getContext('2d'); if (!ctx) throw new Error('مرورگر امکان ساخت تصویر استیکر ندارد.');
            const ratio = Math.min(512 / image.naturalWidth, 512 / image.naturalHeight);
            const w = Math.round(image.naturalWidth * ratio), h = Math.round(image.naturalHeight * ratio);
            ctx.clearRect(0, 0, 512, 512);
            ctx.drawImage(image, (512 - w) / 2, (512 - h) / 2, w, h);
            const blob = await new Promise((resolve) => canvas.toBlob(resolve, 'image/webp', .84));
            if (!blob || blob.type !== 'image/webp' || blob.size > 512 * 1024) throw new Error('خروجی استیکر بیش از ۵۱۲ کیلوبایت است یا مرورگر WebP نمی‌سازد؛ تصویر ساده‌تری انتخاب کنید.');
            return new File([blob], 'sticker_' + Date.now() + '.webp', { type: 'image/webp' });
        } finally { URL.revokeObjectURL(src); }
    }
    async function saveCreatedSticker() {
        const file = state.stickerSource;
        if (!file) return showToast('اول تصویر استیکر را انتخاب کنید.', 'error');
        const button = $('saveStickerBtn'); button.disabled = true;
        let upload;
        try {
            const processed = await makeStickerFile(file);
            upload = await uploadFileChunked(processed, { isSticker: true, compressImage: false });
            if (!upload?.id) throw new Error('بارگذاری تصویر استیکر تأیید نشد.');
            const res = await apiCall('sticker_save', { media_id: upload.id, label: $('stickerCreateLabel').value.trim() });
            if (!res.ok) throw new Error(res.error || 'ذخیره استیکر ممکن نشد.');
            state.stickers = res.stickers || [];
            closeModal('stickerCreateModal'); state.emojiTab = 'stickers'; renderEmojiGrid();
            $('emojiPopover').classList.remove('hidden');
            showToast('استیکر شخصی شما ساخته شد! اکنون می‌توانید آن را بفرستید.', 'success');
        } catch (err) {
            if (upload?.id) await apiCall('delete_unused_upload', { media_id: upload.id }).catch(() => {});
            showToast(err.message, 'error');
        } finally { button.disabled = false; }
    }
    async function sendExistingSticker(id) {
        if (state.quota?.media_blocked) return showToast('برای ارسال استیکر باید مصرف رسانه را به زیر ۸۰٪ برسانید.', 'error');
        if (!state.activeChatId || activeDialog()?.can_post === false) return showToast('در این گفتگو اجازهٔ ارسال ندارید.', 'error');
        $('emojiPopover').classList.add('hidden');
        try {
            const res = await apiCall('send_message', { chat_id: state.activeChatId, text: '', media_id: id });
            if (!res.ok) return displayError(res);
            appendOrUpdateMessage(res.message, res.rev, res.shard_info); scrollToBottom(true);
        } catch (err) { showToast(err.message, 'error'); }
    }
    async function removeSavedSticker(id) {
        if (!confirm('این استیکر از مجموعهٔ شما حذف شود؟ پیام‌هایی که قبلاً فرستاده‌اید دست‌نخورده می‌مانند.')) return;
        try {
            const res = await apiCall('sticker_remove', { media_id: id });
            if (!res.ok) return displayError(res);
            state.stickers = res.stickers || [];
            if (res.quota) { state.quota = res.quota; updateSidebarQuotaUI(); }
            renderEmojiGrid(); showToast('استیکر از مجموعهٔ شخصی حذف شد.', 'success');
        } catch (err) { showToast(err.message, 'error'); }
    }
    function openFindUserModal() {
        $('mainMenuDropdown').classList.add('hidden');
        $('findUserIdInput').value = '';
        $('findUserError').classList.add('hidden');
        $('myOwnIdBadge').textContent = '@' + (state.user?.username || 'user');
        openModal('findUserModal');
        setTimeout(() => $('findUserIdInput').focus(), 50);
    }
    function openDialogActions(chatId) {
        const d = state.dialogs.find((item) => item.id === chatId);
        if (!d) return;
        state.dialogActionId = chatId;
        $('dialogActionTitle').textContent = `گفتگو: ${d.title}`;
        const personal = ['saved', 'ai'].includes(d.type);
        const buttons = [];
        const add = (action, label, danger = false) => buttons.push(`<button type="button" class="${danger ? 'btn-danger-ghost' : 'btn-secondary'}" data-dialog-action="${action}">${label}</button>`);
        if (!personal) {
            add('pinned', d.pinned ? '📌 برداشتن سنجاق فهرست' : '📌 سنجاق گفتگو در فهرست');
            add('muted', d.muted ? '🔔 روشن‌کردن اعلان‌های گفتگو' : '🔕 بی‌صدا کردن گفتگو');
            add('archived', d.archived ? '📤 بازگرداندن از بایگانی' : '📥 انتقال به بایگانی');
        }
        add('clear_me', personal ? '🗑️ حذف کامل تاریخچهٔ فضای شخصی' : '🗑️ پاک‌کردن تاریخچه فقط برای من', true);
        if (d.can_clear_for_everyone && !personal) add('clear_both', '⚠️ پاک‌کردن تاریخچه برای همه', true);
        if (!personal && d.can_hide_dialog !== false) add('hide', '✕ حذف گفتگو از فهرست من', true);
        if (d.type === 'dm' && d.is_contact && !d.is_primary_admin) add('remove_contact', '❌ حذف مخاطب از دفترچهٔ من', true);
        if (d.type === 'dm' && !d.is_primary_admin) add(d.is_blocked ? 'unblock' : 'block', d.is_blocked ? 'رفع مسدودی مخاطب' : '⛔ مسدودکردن مخاطب', !d.is_blocked);
        if (d.can_delete_room) add('delete_room', '🗑️ حذف دائمی گروه/کانال برای همه', true);
        $('dialogActionsList').innerHTML = buttons.join('');
        openModal('dialogActionsModal');
        document.querySelector('#dialogActionsList button')?.focus();
    }
    async function updateDialogPreference(id, key) {
        const d = state.dialogs.find((row) => row.id === id);
        if (!d) return;
        try {
            const res = await apiCall('dialog_preference', { chat_id: id, key, value: !d[key] });
            if (!res.ok) return displayError(res);
            state.dialogs = res.dialogs || state.dialogs;
            renderDialogList(); updateChatHeaderUI();
            if (state.drawerOpen && state.activeChatId === id) refreshInfoDrawer();
            closeModal('dialogActionsModal');
            showToast(`${key === 'muted' ? 'اعلان‌ها' : key === 'archived' ? 'بایگانی' : 'سنجاق گفتگو'} بروزرسانی شد.`, 'success');
        } catch (err) { showToast(err.message, 'error'); }
    }
    function renderBlockedUsers() {
        const root = $('blockedUsersList');
        if (!root) return;
        const list = state.blockedUsers || [];
        root.innerHTML = list.length ? list.map((peer) => `<div class="blocked-user-row"><code dir="ltr">@${escapeHtml(peer)}</code><button type="button" data-unblock-user="${attr(peer)}" class="btn-secondary btn-sm">رفع مسدودی</button></div>`).join('')
            : '<p class="muted">هنوز کسی را مسدود نکرده‌اید.</p>';
    }
    async function changeBlockedUser(peer, block) {
        if (!peer) return;
        if (block && !confirm(`گفتگوی خصوصی با @${peer} مسدود شود؟ هیچ‌کدام از دو طرف نمی‌توانند پیام یا واکنش جدید بفرستند؛ تاریخچه محفوظ می‌ماند.`)) return;
        try {
            const res = await apiCall(block ? 'block_user' : 'unblock_user', { peer_username: peer });
            if (!res.ok) return displayError(res);
            state.blockedUsers = res.blocked_users || [];
            state.dialogs = res.dialogs || state.dialogs;
            renderBlockedUsers(); renderDialogList(); updateChatHeaderUI();
            if (state.drawerOpen) refreshInfoDrawer();
            closeModal('dialogActionsModal');
            showToast(block ? 'گفتگوی خصوصی مسدود شد.' : 'مسدودی برداشته شد.', 'success');
        } catch (err) { showToast(err.message, 'error'); }
    }
    async function hideDialog(id) {
        const d = state.dialogs.find((row) => row.id === id);
        if (!d || ['saved','ai'].includes(d.type) || d.can_hide_dialog === false) return;
        try {
            const res = await apiCall('hide_dialog', { chat_id: id });
            if (!res.ok) return displayError(res);
            state.dialogs = res.dialogs || [];
            closeModal('dialogActionsModal');
            if (id === state.activeChatId) {
                state.drawerOpen = false; $('infoDrawer').classList.add('hidden');
                document.body.classList.remove('mobile-chat-open');
                state.activeChatId = '';
                selectChat(state.dialogs.find((row) => row.type === 'saved')?.id || state.dialogs[0]?.id, false);
            }
            renderDialogList();
            showToast('گفتگو فقط از فهرست شما حذف شد؛ طرف مقابل همچنان آن را دارد.', 'success');
        } catch (err) { showToast(err.message, 'error'); }
    }
    async function removeContactFromApp(id) {
        const d = state.dialogs.find((row) => row.id === id);
        if (d?.is_primary_admin) return showToast('مدیر اصلی مخاطب دائمی همهٔ حساب‌هاست.', 'info');
        if (!d?.peer_username || !confirm(`مخاطب @${d.peer_username} فقط از دفترچه و فهرست شما حذف شود؟ تاریخچهٔ طرف مقابل دست‌نخورده می‌ماند.`)) return;
        try {
            const res = await apiCall('remove_contact', { peer_username: d.peer_username });
            if (!res.ok) return displayError(res);
            state.dialogs = res.dialogs || [];
            closeModal('dialogActionsModal');
            if (id === state.activeChatId) {
                state.drawerOpen = false; $('infoDrawer').classList.add('hidden');
                document.body.classList.remove('mobile-chat-open');
                state.activeChatId = '';
                selectChat(state.dialogs.find((row) => row.type === 'saved')?.id || state.dialogs[0]?.id, false);
            }
            renderDialogList();
            showToast('مخاطب فقط از فهرست شما حذف شد.', 'success');
        } catch (err) { showToast(err.message, 'error'); }
    }
    async function clearCurrentChat(scope = 'me', chatId = state.activeChatId) {
        const d = state.dialogs.find((row) => row.id === chatId);
        if (!d || d.can_delete_history === false) return;
        const personal = ['saved', 'ai'].includes(d.type);
        if (personal) scope = 'both';
        if (scope === 'both' && !personal && !d.can_clear_for_everyone) return showToast('اجازه حذف برای همه را ندارید.', 'error');
        const warning = personal ? 'این تاریخچهٔ شخصی از سرور حذف می‌شود.' : scope === 'both'
            ? (d.type === 'dm' ? 'کل تاریخچهٔ گفتگوی خصوصی برای هر دو نفر پاک می‌شود.' : 'کل تاریخچهٔ گروه/کانال برای تمام اعضا پاک می‌شود.')
            : 'تاریخچه فقط برای شما پنهان می‌شود؛ نسخهٔ طرف مقابل/گروه و فایل‌های مشترک باقی می‌مانند.';
        if (!confirm(`${warning}\nبازگشت‌پذیر نیست. ادامه می‌دهید؟`)) return;
        if (scope === 'both' && !personal && prompt('برای تأیید حذف همگانی، عبارت DELETE را تایپ کنید:') !== 'DELETE') return;
        try {
            const res = await apiCall('clear_chat_history', { chat_id: chatId, scope });
            if (!res.ok) return displayError(res);
            state.dialogs = res.dialogs || state.dialogs;
            closeModal('dialogActionsModal');
            if (state.activeChatId === chatId) {
                if (state.dialogs.some((row) => row.id === chatId)) selectChat(chatId, false);
                else { state.activeChatId = ''; selectChat(state.dialogs.find((row) => row.type === 'saved')?.id || state.dialogs[0]?.id, false); }
            }
            renderDialogList();
            showToast(personal || scope === 'both' ? 'تاریخچه برای همه حذف شد.' : 'تاریخچه فقط برای شما پاک شد.', 'success');
        } catch (err) { showToast(err.message, 'error'); }
    }
    async function deleteActiveRoom(roomId = state.activeChatId) {
        const d = state.dialogs.find((row) => row.id === roomId);
        if (!d?.can_delete_room) return;
        if (!confirm(`گروه/کانال «${d.title}» برای همه اعضا و به‌طور دائمی حذف شود؟`)) return;
        if (prompt('برای تأیید حذف گروه/کانال عبارت DELETE را تایپ کنید:') !== 'DELETE') return;
        try {
            const res = await apiCall('delete_room', { room_id: roomId });
            if (!res.ok) return displayError(res);
            state.dialogs = res.dialogs || [];
            closeModal('dialogActionsModal');
            if (roomId === state.activeChatId) {
                state.drawerOpen = false; $('infoDrawer').classList.add('hidden');
                state.activeChatId = '';
                selectChat(state.dialogs.find((row) => row.type === 'saved')?.id || state.dialogs[0]?.id, false);
            }
            renderDialogList();
            showToast('گروه/کانال برای همه حذف شد.', 'success');
        } catch (err) { showToast(err.message, 'error'); }
    }
    function openNewRoomModal() {
        $('mainMenuDropdown').classList.add('hidden');
        $('nrTitle').value = ''; $('nrDesc').value = ''; $('nrType').value = 'group';
        $('nrVisibility').value = 'private'; $('nrPublicHandle').value = ''; $('nrPublicHandleWrap').classList.add('hidden');
        $('nrAllMembersCheck').checked = false;
        $('nrAllMembersWrap').classList.toggle('hidden', state.user?.role !== 'admin');
        $('nrMembersSelectWrap').classList.remove('hidden');
        $('nrMembersSelectWrap').innerHTML = Object.values(state.users).filter((u) => u.username !== state.user?.username).map((u) => `<label class="checkbox-inline"><input type="checkbox" value="${attr(u.username)}"><span>${escapeHtml(u.display_name)} (<span dir="ltr">@${escapeHtml(u.username)}</span>)</span></label>`).join('') || '<small class="muted">هنوز مخاطبی با آیدی اضافه نکرده‌اید؛ می‌توانید گروه بدون عضو بسازید.</small>';
        openModal('newRoomModal');
    }
    function parseRoomInput(raw) {
        const value = String(raw || '').trim();
        if (/^https?:\/\//i.test(value)) {
            const link = new URL(value);
            if (link.origin !== location.origin || link.pathname !== location.pathname) throw new Error('برای امنیت، فقط لینک دعوت همین برنامه پذیرفته می‌شود.');
            const parts = new URLSearchParams(link.hash.replace(/^#/, ''));
            return { id: parts.get('join') || '', token: parts.get('token') || '' };
        }
        return { id: value.replace(/^@/, '').toLowerCase(), token: '' };
    }
    function openFindRoomModal(roomId = '', token = '') {
        $('mainMenuDropdown').classList.add('hidden');
        $('findRoomIdInput').value = roomId;
        state.pendingJoinCandidate = null;
        $('findRoomResult').classList.add('hidden'); $('joinFoundRoomBtn').classList.add('hidden');
        $('publicRoomNameInput').value = '';
        $('publicRoomSearchResults').innerHTML = '';
        openModal('findRoomModal');
        if (roomId) previewFoundRoom({ id: roomId, token });
        else $('publicRoomNameInput').focus();
    }
    async function searchPublicRoomsByName() {
        const q = $('publicRoomNameInput').value.trim();
        const root = $('publicRoomSearchResults');
        if (q.length < 2 || q.length > 60) { root.textContent = 'دست‌کم ۲ و حداکثر ۶۰ نویسه وارد کنید.'; return; }
        root.textContent = 'در حال جستجوی گروه‌ها و کانال‌های عمومی...';
        const button = $('searchPublicRoomsForm').querySelector('button[type="submit"]');
        button.disabled = true;
        try {
            const res = await apiCall('search_public_rooms', { q }, 'GET');
            if (!res.ok) { root.textContent = res.error || 'جستجو انجام نشد.'; return; }
            root.innerHTML = res.rooms?.length ? res.rooms.map((room) => `<div class="public-room-card">
                <div><strong>${uiIcon(room.type === 'channel' ? 'megaphone' : 'users-round')} ${escapeHtml(room.title)}</strong>
                <small>${room.type === 'channel' ? 'کانال' : 'گروه'} عمومی · ${Number(room.member_count) || 0} عضو${room.public_handle ? ' · @' + escapeHtml(room.public_handle) : ''}</small></div>
                <button type="button" class="btn-secondary btn-sm" data-join-public="${attr(room.id)}">${room.joined ? 'باز کردن' : 'پیوستن'}</button></div>`).join('')
                : '<p class="muted">گروه یا کانال عمومی با این نام پیدا نشد. گروه‌های خصوصی هرگز در این فهرست نیستند.</p>';
        } catch (err) { root.textContent = err.message; }
        finally { button.disabled = false; }
    }
    async function previewFoundRoom(candidate) {
        const box = $('findRoomResult');
        box.classList.remove('hidden'); $('joinFoundRoomBtn').classList.add('hidden');
        box.textContent = 'در حال بررسی گروه/کانال...';
        state.pendingJoinCandidate = null;
        const { id, token } = candidate;
        if (!/^[a-z0-9_\-]{5,64}$/i.test(id) || (token && !/^[a-f0-9]{36}$/.test(token))) {
            box.textContent = 'آیدی یا لینک دعوت معتبر نیست.'; return;
        }
        try {
            // Public discovery does not disclose private room titles.
            const res = await apiCall('find_public_room', { id }, 'GET');
            if (res.ok) {
                const room = res.room;
                state.pendingJoinCandidate = { id: room.id, token };
                box.textContent = `${room.type === 'channel' ? 'کانال' : 'گروه'} «${room.title}» · ${room.member_count} عضو · عمومی${room.joined ? ' · شما عضو هستید' : ''}`;
                $('joinFoundRoomBtn').textContent = room.joined ? 'باز کردن گفتگو' : 'پیوستن به گروه/کانال';
                $('joinFoundRoomBtn').classList.remove('hidden');
            } else if (token) {
                // No title/member directory leaks before a valid private invite
                // has been redeemed by an authenticated participant.
                state.pendingJoinCandidate = { id, token };
                box.textContent = 'این لینک حاوی دعوت خصوصی است؛ در صورت معتبر بودن، با زدن دکمهٔ پیوستن عضو می‌شوید.';
                $('joinFoundRoomBtn').textContent = 'پیوستن با لینک خصوصی';
                $('joinFoundRoomBtn').classList.remove('hidden');
            } else box.textContent = res.error || 'گروه عمومی با این آیدی یافت نشد.';
        } catch (err) { box.textContent = err.message; }
    }
    async function joinFoundRoom() {
        const candidate = state.pendingJoinCandidate;
        if (!candidate) return;
        const btn = $('joinFoundRoomBtn'); btn.disabled = true;
        try {
            const res = await apiCall('join_room', { room_id: candidate.id, invite_token: candidate.token });
            if (!res.ok) { $('findRoomResult').textContent = res.error || 'پیوستن انجام نشد.'; return; }
            closeModal('findRoomModal'); state.pendingJoinCandidate = null;
            const boot = await apiCall('bootstrap');
            if (boot.ok) { enterAuthenticatedApp(boot); selectChat(res.room.id); }
            showToast(res.already_member ? 'گفتگو باز شد.' : 'به گروه/کانال پیوستید.', 'success');
        } catch (err) { showToast(err.message, 'error'); }
        finally { btn.disabled = false; }
    }
    async function loadRoomInfo(roomId) {
        const panel = $('drawerRoomPanel');
        panel.classList.remove('hidden');
        $('drawerRoomOverview').textContent = 'در حال دریافت اطلاعات عضویت...';
        const res = await apiCall('room_info', { room_id: roomId }, 'GET');
        if (!res.ok || state.activeChatId !== roomId || !state.drawerOpen) {
            if (!res.ok) $('drawerRoomOverview').textContent = res.error || 'دسترسی به اطلاعات گروه ممکن نیست.';
            return;
        }
        const room = res.room;
        $('drawerRoomId').textContent = room.public_handle ? '@' + room.public_handle : room.id;
        $('drawerRoomOverview').textContent = `${room.type === 'channel' ? 'کانال' : 'گروه'} ${room.is_public ? 'عمومی' : 'خصوصی'} · ${room.member_count} عضو · ${room.description || 'بدون توضیحات'}`;
        const invite = new URL(location.href.split('#')[0]);
        invite.hash = new URLSearchParams({ join: room.id, ...(!room.is_public && room.invite_token ? { token: room.invite_token } : {}) }).toString();
        $('drawerRoomInviteLink').value = invite.toString();
        $('drawerRoomInviteLink').closest('.field-group').classList.toggle('hidden', !room.is_public && !room.invite_token);
        $('drawerLeaveRoomBtn').classList.toggle('hidden', !room.can_leave || !room.joined);
        $('drawerRoomMembersControl').classList.toggle('hidden', !room.can_manage_members);
        $('drawerRoomManager').classList.toggle('hidden', !room.can_manage);
        if (room.can_manage) {
            $('drawerRoomVisibility').value = room.is_public ? 'public' : 'private';
            $('drawerRoomPublicHandle').value = room.public_handle || '';
            $('drawerRoomPublicHandle').disabled = !room.is_public;
            const members = room.members || [];
            $('drawerRoomMembers').innerHTML = members.length ? members.map((member) =>
                `<div class="room-member"><code dir="ltr">@${escapeHtml(member)}</code>${member === state.user.username ? '<small>شما</small>' : `<button type="button" class="btn-danger-ghost btn-sm" data-remove-room-member="${attr(member)}">حذف عضو</button>`}</div>`
            ).join('') : '<small class="muted">فهرست اعضای مشخص‌شده خالی است؛ اگر همهٔ کاربران عضو باشند، نیازی به فهرست جداگانه نیست.</small>';
        }
    }
    async function changeRoomMember(remove = false, memberId = '') {
        const roomId = state.activeChatId;
        const id = (memberId || $('drawerRoomMemberId').value).trim().replace(/^@/, '');
        if (!id) return showToast('آیدی دقیق فرد را وارد کنید.', 'error');
        if (remove && !confirm(`آیا عضو @${id} از گروه حذف شود؟`)) return;
        try {
            const res = await apiCall('room_member', { room_id: roomId, member_id: id, op: remove ? 'remove' : 'add' });
            if (!res.ok) return displayError(res);
            $('drawerRoomMemberId').value = '';
            await loadRoomInfo(roomId);
            runSyncCycle();
            showToast(remove ? 'عضو حذف شد.' : `@${id} به گروه افزوده شد.`, 'success');
        } catch (err) { showToast(err.message, 'error'); }
    }
    async function saveRoomSettings(rotate = false) {
        const roomId = state.activeChatId;
        if (rotate && !confirm('لینک دعوت خصوصی قبلی برای همه باطل شود؟')) return;
        try {
            const isPublic = $('drawerRoomVisibility').value === 'public';
            const res = await apiCall('update_room', { room_id: roomId, is_public: isPublic, public_handle: isPublic ? $('drawerRoomPublicHandle').value.trim() : '', rotate_invite: rotate });
            if (!res.ok) return displayError(res);
            await loadRoomInfo(roomId);
            runSyncCycle();
            showToast(rotate ? 'لینک قبلی باطل و لینک جدید ساخته شد.' : 'دسترسی گروه ذخیره شد.', 'success');
        } catch (err) { showToast(err.message, 'error'); }
    }
    async function leaveActiveRoom() {
        const roomId = state.activeChatId;
        if (!confirm('از این گروه/کانال خارج شوید؟ برای ورود مجدد به گروه خصوصی، لینک دعوت لازم است.')) return;
        try {
            const res = await apiCall('leave_room', { room_id: roomId });
            if (!res.ok) return displayError(res);
            state.drawerOpen = false; $('infoDrawer').classList.add('hidden');
            document.body.classList.remove('mobile-chat-open');
            state.activeChatId = '';
            const boot = await apiCall('bootstrap');
            if (boot.ok) enterAuthenticatedApp(boot);
            showToast('از گروه/کانال خارج شدید.', 'success');
        } catch (err) { showToast(err.message, 'error'); }
    }

    function updateMyMediaQuota() {
        const q = state.quota;
        $('myMediaQuota').textContent = q ? `فضای استفاده‌شده: ${q.used_human} از ${q.quota_human} (${q.percent}٪)${q.media_blocked ? ' · رسانهٔ تازه تا آزادسازی فضا متوقف است.' : ''}` : '';
    }
    function renderOwnedMedia(item) {
        const m = item.media || {};
        const category = { image:'تصویر', video:'ویدیو', voice:'ویس', audio:'صوت', file:'فایل', sticker:'استیکر' }[m.category] || 'رسانه';
        const refs = item.refs || {};
        const preview = ['image','sticker'].includes(m.category) ? `<img loading="lazy" src="${attr(mediaUrl(m.id))}" alt="">` : `<span>${{video:'▶',voice:'♫',audio:'♫',file:'▧'}[m.category] || '▧'}</span>`;
        return `<label class="owned-media-item"><input type="checkbox" class="owned-media-check" value="${attr(m.id)}">
          <span class="owned-media-thumb">${preview}</span><span class="owned-media-main"><strong title="${attr(m.original_name || '')}">${escapeHtml(m.original_name || 'فایل بدون نام')}</strong>
          <small>${category} · ${escapeHtml(m.size_human || formatBytes(m.size_bytes))} · گفتگو ${Number(refs.chat) || 0} · فضای شخصی ${Number(refs.drive) || 0} · استیکر ${Number(refs.sticker) || 0}${item.avatar ? ' · عکس نمایه' : ''}</small></span>
          <a class="owned-media-download" href="${attr(mediaUrl(m.id, true))}" download="${attr(m.original_name || 'media')}" title="دانلود نسخهٔ من">↓</a></label>`;
    }
    async function loadOwnedMediaPage(more = false) {
        if (state.ownedMediaBusy) return;
        state.ownedMediaBusy = true;
        const root = $('myMediaList');
        if (!more) { root.innerHTML = '<p class="muted">در حال دریافت رسانه‌های شما...</p>'; state.ownedMediaCursor = null; }
        $('myMediaMoreBtn').disabled = true;
        try {
            const res = await apiCall('my_media_list', { limit: 20, cursor: more ? state.ownedMediaCursor || '' : '' }, 'GET');
            if (!res.ok) throw new Error(res.error || 'فهرست رسانه‌ها دریافت نشد.');
            if (res.quota) { state.quota = res.quota; updateSidebarQuotaUI(); updateMyMediaQuota(); }
            if (!more) root.innerHTML = '';
            root.insertAdjacentHTML('beforeend', (res.items || []).map(renderOwnedMedia).join(''));
            if (!root.querySelector('.owned-media-item')) root.innerHTML = '<p class="muted">هنوز فایل آپلودشده‌ای ندارید.</p>';
            state.ownedMediaCursor = res.next_cursor || null;
            $('myMediaMoreBtn').classList.toggle('hidden', !res.has_more);
            $('myMediaSelectPage').checked = false;
        } catch (err) { if (!more) root.innerHTML = ''; showToast(err.message, 'error'); }
        finally { state.ownedMediaBusy = false; $('myMediaMoreBtn').disabled = false; }
    }
    function openMyMediaModal() {
        $('mainMenuDropdown').classList.add('hidden');
        if (!$('settingsModal').classList.contains('hidden')) closeModal('settingsModal');
        $('myMediaPassword').value = ''; $('myMediaConfirm').value = '';
        $('myMediaPasswordWrap').classList.add('hidden');
        document.querySelector('input[name="myMediaScope"][value="me"]').checked = true;
        updateMyMediaQuota();
        openModal('myMediaModal');
        loadOwnedMediaPage();
    }
    async function purgeSelectedOwnedMedia() {
        const ids = [...document.querySelectorAll('#myMediaList .owned-media-check:checked')].map((el) => el.value);
        const scope = document.querySelector('input[name="myMediaScope"]:checked')?.value || 'me';
        if (!ids.length || ids.length > 20) return showToast('از این فهرست ۱ تا ۲۰ رسانه برای هر نوبت انتخاب کنید.', 'error');
        if ($('myMediaConfirm').value.trim() !== 'MEDIA') return showToast('برای تأیید حذف، MEDIA را دقیق وارد کنید.', 'error');
        if (scope === 'both' && !$('myMediaPassword').value) return showToast('برای حذف همگانی، رمز فعلی لازم است.', 'error');
        const warning = scope === 'both'
            ? `حذف ${ids.length} فایل: پیام‌های ارسال‌شدهٔ خودتان در همهٔ گفتگوهای قابل‌دسترسی پاک می‌شود. پیام‌ها/فورواردهای دیگران حذف نمی‌شوند؛ ممکن است حجم کامل آزاد نشود.`
            : `حذف ${ids.length} فایل فقط برای خودتان: کپی دیگران ممکن است باقی بماند و الزاماً سهمیه آزاد نمی‌شود.`;
        if (!confirm(`${warning}\nاین کار بازگشت‌پذیر نیست. ادامه می‌دهید؟`)) return;
        const button = $('purgeSelectedMediaBtn'); button.disabled = true;
        try {
            const res = await apiCall('purge_my_media', { media_ids: ids, scope, password: scope === 'both' ? $('myMediaPassword').value : '', confirmation: 'MEDIA' });
            if (!res.ok) return displayError(res);
            $('myMediaPassword').value = ''; $('myMediaConfirm').value = '';
            if (res.quota) { state.quota = res.quota; updateSidebarQuotaUI(); updateMyMediaQuota(); }
            const results = res.results || [];
            const failed = results.filter((r) => !r.ok);
            const freed = results.reduce((sum, r) => sum + (Number(r.freed_bytes) || 0), 0);
            showToast(`${results.length - failed.length} مورد پردازش شد؛ ${formatBytes(freed)} از سهمیه آزاد شد.${failed.length ? ` ${failed.length} مورد حذف نشد.` : ''}`, failed.length ? 'error' : 'success');
            await loadOwnedMediaPage();
            runSyncCycle();
            if (state.drawerOpen) refreshInfoDrawer();
            if (state.emojiTab === 'stickers') loadStickerCollection();
        } catch (err) { showToast(err.message, 'error'); }
        finally { button.disabled = false; }
    }

    function activateAdminPane(key) {
        if (key === 'activity' && (state.user?.role !== 'admin' || state.user.username !== state.primaryAdminId)) return;
        document.querySelectorAll('.admin-tab').forEach((btn) => btn.classList.toggle('active', btn.dataset.atab === key));
        document.querySelectorAll('.admin-pane').forEach((pane) => pane.classList.toggle('hidden', pane.id !== `adminPane_${key}`));
        if (key === 'activity') refreshAccountActivity();
    }

    function bindEvents() {
        // Login: no sample credentials or setup hints on the sign-in screen.
        $('togglePassBtn').addEventListener('click', () => {
            const input = $('loginPassword'); input.type = input.type === 'password' ? 'text' : 'password';
            $('togglePassBtn').querySelector('use')?.setAttribute('href', input.type === 'password' ? '#ui-eye' : '#ui-eye-off');
        });
        $('loginForm').addEventListener('submit', async (e) => {
            e.preventDefault();
            const btn = $('loginSubmitBtn'); const box = $('loginError');
            btn.disabled = true; box.classList.add('hidden');
            try {
                const res = await apiCall('login', { username: $('loginUsername').value.trim(), password: $('loginPassword').value });
                if (!res.ok) { box.textContent = res.error || 'نام کاربری یا رمز عبور اشتباه است.'; box.classList.remove('hidden'); return; }
                state.token = res.token;
                store('hamrah_token', state.token);
                $('loginPassword').value = '';
                const boot = await apiCall('bootstrap');
                if (!boot.ok) throw new Error(boot.error || 'آغاز نشست کاربری انجام نشد.');
                enterAuthenticatedApp(boot);
            } catch (err) { box.textContent = err.message; box.classList.remove('hidden'); }
            finally { btn.disabled = false; }
        });

        // Sidebar and user menu
        $('menuBtn').addEventListener('click', (e) => { e.stopPropagation(); $('mainMenuDropdown').classList.toggle('hidden'); });
        for (const id of ['installTopBtn', 'installChatBtn', 'installOfferNowBtn']) $(id).addEventListener('click', requestAppInstall);
        $('installOfferLaterBtn').addEventListener('click', () => { closeModal('installOfferModal'); showToast('دکمهٔ نصب بالای صفحه همچنان در دسترس است.', 'info'); });
        document.addEventListener('click', (e) => {
            if (!e.target.closest('#mainMenuDropdown, #menuBtn')) $('mainMenuDropdown').classList.add('hidden');
            if (!e.target.closest('#emojiPopover, #emojiBtn, #stickerBtn')) $('emojiPopover').classList.add('hidden');
            if (!e.target.closest('#attachPopover, #attachBtn')) $('attachPopover').classList.add('hidden');
            const backdrop = e.target.closest('.modal-backdrop');
            if (backdrop && e.target === backdrop) {
                if (backdrop.id === 'videoCompressModal') closeVideoModal(); else closeModal(backdrop.id);
            }
        });
        $('menuUserCard').addEventListener('click', () => openSettingsModal('account'));
        for (const id of ['findByIdTopBtn','fabFindUserBtn','menuFindByIdBtn']) $(id).addEventListener('click', openFindUserModal);
        $('menuSavedBtn').addEventListener('click', () => { $('mainMenuDropdown').classList.add('hidden'); selectChat(`saved_${state.user.username}`); });
        $('menuDriveBtn').addEventListener('click', () => { $('mainMenuDropdown').classList.add('hidden'); openPersonalDriveModal(); });
        $('menuAllMediaBtn').addEventListener('click', openMyMediaModal);
        $('quotaManageNowBtn').addEventListener('click', openMyMediaModal);
        $('settingsAllMediaBtn').addEventListener('click', openMyMediaModal);
        $('myMediaMoreBtn').addEventListener('click', () => loadOwnedMediaPage(true));
        $('myMediaList').addEventListener('click', (e) => { if (e.target.closest('.owned-media-download')) e.stopPropagation(); });
        $('myMediaList').addEventListener('change', (e) => {
            if (!e.target.matches('.owned-media-check')) return;
            if (document.querySelectorAll('#myMediaList .owned-media-check:checked').length > 20) { e.target.checked = false; showToast('هر نوبت حداکثر ۲۰ رسانه قابل انتخاب است.', 'error'); }
            $('myMediaSelectPage').checked = [...$('myMediaList').querySelectorAll('.owned-media-check')].every((el) => el.checked);
        });
        $('myMediaSelectPage').addEventListener('change', (e) => {
            const items = [...$('myMediaList').querySelectorAll('.owned-media-check')];
            if (e.target.checked && items.length > 20) showToast('۲۰ مورد اول انتخاب شد؛ برای بقیه نوبت بعدی را انجام دهید.', 'info');
            items.forEach((el, i) => { el.checked = e.target.checked && i < 20; });
        });
        document.querySelectorAll('input[name="myMediaScope"]').forEach((el) => el.addEventListener('change', () => $('myMediaPasswordWrap').classList.toggle('hidden', document.querySelector('input[name="myMediaScope"]:checked')?.value !== 'both')));
        $('purgeSelectedMediaBtn').addEventListener('click', purgeSelectedOwnedMedia);
        $('sidebarQuotaBtn').addEventListener('click', () => openPersonalDriveModal());
        $('openPersonalDriveHeaderBtn').addEventListener('click', () => openPersonalDriveModal());
        $('menuSettingsBtn').addEventListener('click', () => { $('mainMenuDropdown').classList.add('hidden'); openSettingsModal(); });
        $('sidebarSettingsQuickBtn').addEventListener('click', () => openSettingsModal());
        $('menuNewRoomBtn').addEventListener('click', openNewRoomModal);
        $('menuFindRoomBtn').addEventListener('click', () => openFindRoomModal());
        $('roomDiscoveryBtn').addEventListener('click', () => openFindRoomModal());
        $('nrVisibility').addEventListener('change', (e) => $('nrPublicHandleWrap').classList.toggle('hidden', e.target.value !== 'public'));
        $('searchPublicRoomsForm').addEventListener('submit', (e) => { e.preventDefault(); searchPublicRoomsByName(); });
        $('publicRoomSearchResults').addEventListener('click', (e) => {
            const button = e.target.closest('[data-join-public]');
            if (!button) return;
            state.pendingJoinCandidate = { id: button.dataset.joinPublic, token: '' };
            joinFoundRoom();
        });
        $('findRoomForm').addEventListener('submit', (e) => {
            e.preventDefault();
            try { previewFoundRoom(parseRoomInput($('findRoomIdInput').value)); }
            catch (err) { $('findRoomResult').textContent = err.message; $('findRoomResult').classList.remove('hidden'); $('joinFoundRoomBtn').classList.add('hidden'); }
        });
        $('joinFoundRoomBtn').addEventListener('click', joinFoundRoom);
        $('menuThemeBtn').addEventListener('click', () => { $('mainMenuDropdown').classList.add('hidden'); openSettingsModal('appearance'); });
        $('menuAdminBtn').addEventListener('click', () => { $('mainMenuDropdown').classList.add('hidden'); openAdminModal(); });
        $('adminHeaderQuickBtn').addEventListener('click', openAdminModal);
        $('menuLogoutBtn').addEventListener('click', async () => { $('mainMenuDropdown').classList.add('hidden'); try { await apiCall('logout', {}); } catch (_) {} handleLoggedOut(); });
        $('folderTabs').addEventListener('click', (e) => {
            const tab = e.target.closest('.folder-tab'); if (!tab) return;
            state.folderFilter = tab.dataset.filter || 'all';
            document.querySelectorAll('.folder-tab').forEach((b) => b.classList.toggle('active', b === tab));
            $('dialogList').classList.add('entering');
            renderDialogList();
            setTimeout(() => $('dialogList').classList.remove('entering'), 250);
        });
        $('globalSearchInput').addEventListener('input', (e) => { state.globalSearch = e.target.value; $('clearGlobalSearchBtn').classList.toggle('hidden', !state.globalSearch); renderDialogList(); });
        $('clearGlobalSearchBtn').addEventListener('click', () => { $('globalSearchInput').value = ''; state.globalSearch = ''; $('clearGlobalSearchBtn').classList.add('hidden'); renderDialogList(); });
        $('dialogList').addEventListener('click', (e) => { if (e.target.closest('[data-open-find]')) return openFindUserModal(); const menu = e.target.closest('[data-dialog-menu]'); if (menu) { e.stopPropagation(); return openDialogActions(menu.dataset.dialogMenu); } const row = e.target.closest('[data-chat-id]'); if (row) selectChat(row.dataset.chatId); });
        $('dialogList').addEventListener('keydown', (e) => { if (e.target.matches('[data-chat-id]') && (e.key === 'Enter' || e.key === ' ')) { e.preventDefault(); selectChat(e.target.dataset.chatId); } });
        $('mobileBackBtn').addEventListener('click', () => document.body.classList.remove('mobile-chat-open'));

        // Find/contact user by exact ID (no public list for non-admin)
        $('findUserForm').addEventListener('submit', async (e) => {
            e.preventDefault();
            const input = $('findUserIdInput'); const box = $('findUserError');
            box.classList.add('hidden');
            try {
                const res = await apiCall('find_user_by_id', { id: input.value.trim() });
                if (!res.ok) { box.textContent = res.error || 'کاربر با این آیدی یافت نشد.'; box.classList.remove('hidden'); return; }
                state.dialogs = res.dialogs || [];
                state.users = res.users || state.users;
                closeModal('findUserModal'); renderDialogList();
                selectChat(res.chat_id);
                showToast(`گفتگو با ${res.contact.display_name} آماده است.`, 'success');
            } catch (err) { box.textContent = err.message; box.classList.remove('hidden'); }
        });

        // Chat header / drawer, message deletion and history
        const toggleDrawer = () => { state.drawerOpen = !state.drawerOpen; $('infoDrawer').classList.toggle('hidden', !state.drawerOpen); if (state.drawerOpen) refreshInfoDrawer(); };
        $('toggleInfoDrawerBtn').addEventListener('click', toggleDrawer);
        $('chatHeaderInfo').addEventListener('click', toggleDrawer);
        $('closeInfoDrawerBtn').addEventListener('click', () => { state.drawerOpen = false; $('infoDrawer').classList.add('hidden'); });
        $('clearChatHeaderBtn').addEventListener('click', () => clearCurrentChat('me'));
        $('drawerClearHistoryBtn').addEventListener('click', () => clearCurrentChat('me'));
        $('drawerClearForBothBtn').addEventListener('click', () => clearCurrentChat('both'));
        $('drawerHideDialogBtn').addEventListener('click', () => hideDialog(state.activeChatId));
        $('drawerMuteBtn').addEventListener('click', () => updateDialogPreference(state.activeChatId, 'muted'));
        $('drawerArchiveBtn').addEventListener('click', () => updateDialogPreference(state.activeChatId, 'archived'));
        $('drawerDeleteRoomBtn').addEventListener('click', () => deleteActiveRoom());
        $('drawerLeaveRoomBtn').addEventListener('click', leaveActiveRoom);
        $('drawerAddRoomMemberBtn').addEventListener('click', () => changeRoomMember());
        $('drawerRoomMemberId').addEventListener('keydown', (e) => { if (e.key === 'Enter') { e.preventDefault(); changeRoomMember(); } });
        $('drawerRoomMembers').addEventListener('click', (e) => { const btn = e.target.closest('[data-remove-room-member]'); if (btn) changeRoomMember(true, btn.dataset.removeRoomMember); });
        $('drawerRoomVisibility').addEventListener('change', (e) => { $('drawerRoomPublicHandle').disabled = e.target.value !== 'public'; });
        $('drawerSaveRoomBtn').addEventListener('click', () => saveRoomSettings(false));
        $('drawerRotateInviteBtn').addEventListener('click', () => saveRoomSettings(true));
        $('drawerCopyRoomLinkBtn').addEventListener('click', async () => {
            const input = $('drawerRoomInviteLink');
            try { await navigator.clipboard.writeText(input.value); showToast('لینک دعوت کپی شد؛ فقط با افراد مورد اعتماد به اشتراک بگذارید.', 'success'); }
            catch (_) { input.select(); document.execCommand('copy'); showToast('لینک دعوت کپی شد.', 'success'); }
        });
        $('drawerRemoveContactBtn').addEventListener('click', () => removeContactFromApp(state.activeChatId));
        $('drawerBlockUserBtn').addEventListener('click', () => { const d = activeDialog(); if (d?.peer_username) changeBlockedUser(d.peer_username, !d.is_blocked); });
        $('blockedUsersList').addEventListener('click', (e) => { const btn = e.target.closest('[data-unblock-user]'); if (btn) changeBlockedUser(btn.dataset.unblockUser, false); });
        $('dialogActionsList').addEventListener('click', (e) => {
            const button = e.target.closest('[data-dialog-action]'); if (!button) return;
            const id = state.dialogActionId, action = button.dataset.dialogAction;
            if (['muted','pinned','archived'].includes(action)) return updateDialogPreference(id, action);
            if (action === 'clear_me') return clearCurrentChat('me', id);
            if (action === 'clear_both') return clearCurrentChat('both', id);
            if (action === 'hide') return hideDialog(id);
            if (action === 'remove_contact') return removeContactFromApp(id);
            if (action === 'block' || action === 'unblock') {
                const peer = state.dialogs.find((row) => row.id === id)?.peer_username;
                if (peer) return changeBlockedUser(peer, action === 'block');
            }
            if (action === 'delete_room') return deleteActiveRoom(id);
        });
        $('drawerMediaTabs').addEventListener('click', (e) => {
            const tab = e.target.closest('.m-tab'); if (!tab) return;
            document.querySelectorAll('#drawerMediaTabs .m-tab').forEach((t) => t.classList.toggle('active', t === tab));
            state.drawerMediaCat = tab.dataset.cat; state.drawerMediaCursor = null; refreshInfoDrawer();
        });
        $('pinnedJumpArea').addEventListener('click', () => { if (state.pinnedMessage?.seq) jumpToSeq(state.pinnedMessage.seq); });
        $('unpinBtn').addEventListener('click', async () => {
            try { const res = await apiCall('pin_message', { chat_id: state.activeChatId, seq: 0 }); if (!res.ok) return displayError(res); state.pinnedMessage = null; updatePinnedBannerUI(); }
            catch (err) { showToast(err.message, 'error'); }
        });
        $('telegramServiceBar').addEventListener('submit', (e) => {
            e.preventDefault();
            const id = $('telegramChannelIdInput').value.trim().replace(/^@/, '');
            if (!/^[A-Za-z][A-Za-z0-9_]{4,31}$/.test(id)) return showToast('آیدی عمومی کانال باید ۵ تا ۳۲ نویسهٔ انگلیسی/عدد باشد.', 'error');
            const command = $('telegramServiceBar').dataset.command || '/telegram';
            $('messageInput').value = `${command} @${id}`;
            $('telegramChannelIdInput').value = '';
            submitComposerMessage();
        });
        $('aiHelperToolbar').addEventListener('click', (e) => {
            const chip = e.target.closest('.ai-cmd-chip'); if (!chip) return;
            const command = chip.dataset.aiCommand || chip.dataset.aiTool || '';
            if (chip.dataset.aiCommand || chip.dataset.aiType === 'builtin_status' || chip.dataset.aiType === 'custom_url' && chip.dataset.aiRequiresQuery !== '1') {
                $('messageInput').value = command; submitComposerMessage();
            } else {
                $('messageInput').value = command + ' ';
                $('messageInput').focus(); updateSendMicButton(); autoResizeInput();
            }
        });
        $('chatSearchToggleBtn').addEventListener('click', () => { $('chatSearchBar').classList.toggle('hidden'); if (!$('chatSearchBar').classList.contains('hidden')) $('chatSearchInput').focus(); else $('chatSearchResults').classList.add('hidden'); });
        $('chatSearchCloseBtn').addEventListener('click', () => { $('chatSearchBar').classList.add('hidden'); $('chatSearchResults').classList.add('hidden'); });
        $('chatSearchRunBtn').addEventListener('click', () => runChatSearch());
        $('chatSearchInput').addEventListener('keydown', (e) => { if (e.key === 'Enter') { e.preventDefault(); runChatSearch(); } });
        $('chatSearchResults').addEventListener('click', (e) => { if (e.target.closest('[data-search-more]')) return runChatSearch(true); const hit = e.target.closest('[data-jump-seq]'); if (hit) jumpToSeq(Number(hit.dataset.jumpSeq)); });
        $('loadOlderBtn').addEventListener('click', loadOlderMessages);
        $('messagesViewport').addEventListener('scroll', () => $('scrollBottomBtn').classList.toggle('hidden', isNearBottom()));
        $('scrollBottomBtn').addEventListener('click', () => scrollToBottom());
        $('messagesList').addEventListener('click', async (e) => {
            const lightbox = e.target.closest('[data-lightbox-src]');
            if (lightbox) return openLightbox(lightbox.dataset.lightboxSrc, lightbox.dataset.lightboxDl, lightbox.dataset.lightboxName);
            const voice = e.target.closest('.voice-player'); if (voice) return handleVoicePlayerClick(voice, e);
            const jump = e.target.closest('[data-jump-seq]'); if (jump) return jumpToSeq(Number(jump.dataset.jumpSeq));
            const more = e.target.closest('.msg-more-btn');
            if (more) { const row = more.closest('.msg-row'); row?.classList.toggle('actions-open'); return; }
            const react = e.target.closest('[data-react-seq]');
            if (react) {
                const chat = state.activeChatId;
                try { const res = await apiCall('react_message', { chat_id: chat, seq: Number(react.dataset.reactSeq), emoji: react.dataset.emoji }); if (res.ok && chat === state.activeChatId) appendOrUpdateMessage(res.message); else if (!res.ok) displayError(res); }
                catch (err) { showToast(err.message, 'error'); }
                return;
            }
            const action = e.target.closest('[data-action]');
            if (action) return handleMessageAction(action.dataset.action, Number(action.dataset.seq));
            const row = e.target.closest('.msg-row');
            if (row && window.innerWidth <= 920 && !e.target.closest('a,video,audio,button')) {
                const wasOpen = row.classList.contains('actions-open');
                document.querySelectorAll('.msg-row.actions-open').forEach((r) => r.classList.remove('actions-open'));
                if (!wasOpen) row.classList.add('actions-open');
            }
        });
        document.body.addEventListener('click', (e) => {
            const lb = e.target.closest('[data-lightbox-src]');
            if (lb && !lb.closest('#messagesList')) openLightbox(lb.dataset.lightboxSrc, lb.dataset.lightboxDl, lb.dataset.lightboxName);
            const jump = e.target.closest('#drawerMediaList [data-jump-seq]');
            if (jump) jumpToSeq(Number(jump.dataset.jumpSeq));
        });

        // Composer keyboard, emoji picker, photo/video/file pickers, paste/drag
        $('messageInput').addEventListener('input', () => {
            autoResizeInput(); updateSendMicButton();
            if (Date.now() - state.typingSentAt > 3400 && $('messageInput').value.trim()) {
                state.typingSentAt = Date.now();
                apiCall('sync', { typing_chat: state.activeChatId, activity_type: 'typing' }).catch(() => {});
            }
        });
        $('messageInput').addEventListener('keydown', (e) => {
            if (e.key === 'Enter' && !e.shiftKey && !e.ctrlKey && !e.altKey && !e.isComposing && e.keyCode !== 229 && state.enterToSend) {
                e.preventDefault(); submitComposerMessage();
            }
        });
        $('messageInput').addEventListener('paste', (e) => {
            const files = [...(e.clipboardData?.items || [])].filter((it) => it.kind === 'file').map((it) => it.getAsFile()).filter(Boolean);
            if (files.length) { e.preventDefault(); handleChatFilesSelected(files); }
        });
        $('cancelContextBtn').addEventListener('click', () => { clearComposerContext(); $('messageInput').value = ''; autoResizeInput(); updateSendMicButton(); });
        $('voiceRecordBtn').addEventListener('click', () => $('messageInput').value.trim() || state.editingMsg ? submitComposerMessage() : startVoiceRecording());
        $('cancelVoiceBtn').addEventListener('click', () => stopVoiceRecording(true));
        $('finishVoiceBtn').addEventListener('click', () => stopVoiceRecording(false));
        const openEmojiPane = (tab) => { const close = state.emojiTab === tab && !$('emojiPopover').classList.contains('hidden'); state.emojiTab = tab; renderEmojiGrid(); $('emojiPopover').classList.toggle('hidden', close); $('attachPopover').classList.add('hidden'); if (!close && tab === 'stickers') loadStickerCollection(); };
        $('emojiBtn').addEventListener('click', (e) => { e.stopPropagation(); openEmojiPane('emoji'); });
        $('stickerBtn').addEventListener('click', (e) => { e.stopPropagation(); openEmojiPane('stickers'); });
        $('emojiPopover').addEventListener('click', (e) => {
            // Switching tabs replaces the clicked DOM node. Without stopping
            // bubbling, the document outside-click handler sees it detached
            // and closes the picker before the other tab can be used.
            e.stopPropagation();
            const tab = e.target.closest('[data-emoji-tab]');
            if (tab) { state.emojiTab = tab.dataset.emojiTab; renderEmojiGrid(); if (state.emojiTab === 'stickers') loadStickerCollection(); return; }
            const cell = e.target.closest('.emoji-cell');
            if (cell) { $('messageInput').value += cell.dataset.emo; $('messageInput').focus(); updateSendMicButton(); return; }
            if (e.target.closest('[data-create-sticker]')) return showStickerCreator();
            const send = e.target.closest('[data-send-sticker]'); if (send) return sendExistingSticker(send.dataset.sendSticker);
            const remove = e.target.closest('[data-remove-sticker]'); if (remove) return removeSavedSticker(remove.dataset.removeSticker);
        });
        $('stickerCreateFileInput').addEventListener('change', (e) => {
            const file = e.target.files?.[0]; if (!file) return;
            if (state.stickerPreviewUrl) URL.revokeObjectURL(state.stickerPreviewUrl);
            state.stickerPreviewUrl = URL.createObjectURL(file);
            state.stickerSource = file;
            $('stickerCreatePreview').src = state.stickerPreviewUrl;
            $('stickerCreatePreview').classList.remove('hidden'); $('stickerPreviewHint').classList.add('hidden');
        });
        $('saveStickerBtn').addEventListener('click', saveCreatedSticker);
        $('attachBtn').addEventListener('click', (e) => { e.stopPropagation(); $('emojiPopover').classList.add('hidden'); $('attachPopover').classList.toggle('hidden'); });
        $('pickPhotoBtn').addEventListener('click', () => $('photoFileInput').click());
        $('pickVideoBtn').addEventListener('click', () => $('videoFileInput').click());
        $('pickFileBtn').addEventListener('click', () => $('anyFileInput').click());
        $('pickCameraBtn').addEventListener('click', () => { $('attachPopover').classList.add('hidden'); $('cameraFileInput').click(); });
        $('shareLocationBtn').addEventListener('click', shareCurrentLocation);
        $('cameraFileInput').addEventListener('change', (e) => { const files = [...e.target.files]; e.target.value = ''; handleChatFilesSelected(files); });
        $('photoFileInput').addEventListener('change', (e) => { const files = [...e.target.files]; e.target.value = ''; handleChatFilesSelected(files); });
        $('videoFileInput').addEventListener('change', (e) => { const files = [...e.target.files]; e.target.value = ''; handleChatFilesSelected(files); });
        $('anyFileInput').addEventListener('change', (e) => { const files = [...e.target.files]; e.target.value = ''; handleChatFilesSelected(files, true); });
        $('chatStage').addEventListener('dragover', (e) => { e.preventDefault(); $('dropZoneOverlay').classList.remove('hidden'); });
        $('chatStage').addEventListener('drop', (e) => { e.preventDefault(); $('dropZoneOverlay').classList.add('hidden'); if (e.dataTransfer?.files?.length) handleChatFilesSelected([...e.dataTransfer.files]); });
        $('chatStage').addEventListener('dragleave', (e) => { if (!e.relatedTarget || !$('chatStage').contains(e.relatedTarget)) $('dropZoneOverlay').classList.add('hidden'); });
        $('closeVideoModalBtn').addEventListener('click', () => closeVideoModal());
        $('cancelVideoProcessBtn').addEventListener('click', () => closeVideoModal());
        $('confirmVideoSendBtn').addEventListener('click', confirmVideoSend);

        // Common modals, forwarding & lightbox
        document.querySelectorAll('[data-close-modal]').forEach((btn) => btn.addEventListener('click', () => closeModal(btn.dataset.closeModal)));
        $('forwardTargetsList').addEventListener('click', async (e) => {
            const row = e.target.closest('[data-forward-to]'); if (!row || !state.forwardSource) return;
            try {
                const res = await apiCall('forward_message', { from_chat_id: state.forwardSource.chat_id, seq: state.forwardSource.seq, to_chat_id: row.dataset.forwardTo });
                if (!res.ok) return displayError(res);
                closeModal('forwardModal');
                selectChat(row.dataset.forwardTo);
                showToast('پیام فوروارد شد.', 'success');
            } catch (err) { showToast(err.message, 'error'); }
        });
        $('closeLightboxBtn').addEventListener('click', () => $('lightboxOverlay').classList.add('hidden'));
        $('lightboxStage').addEventListener('click', (e) => { if (e.target === $('lightboxStage')) $('lightboxOverlay').classList.add('hidden'); });
        document.addEventListener('keydown', (e) => {
            if (e.key !== 'Escape') return;
            $('lightboxOverlay').classList.add('hidden');
            const current = [...document.querySelectorAll('.modal-backdrop:not(.hidden)')].pop();
            if (current) current.id === 'videoCompressModal' ? closeVideoModal() : closeModal(current.id);
        });

        // Per-user full settings & password
        document.querySelectorAll('.u-set-tab').forEach((tab) => tab.addEventListener('click', () => activateSettingsPane(tab.dataset.ustab)));
        $('settingsAccountForm').addEventListener('submit', (e) => { e.preventDefault(); saveAccountProfile(); });
        $('settingsPasswordForm').addEventListener('submit', (e) => { e.preventDefault(); saveNewPassword(); });
        $('profDisplayName').addEventListener('input', renderProfilePreview);
        $('profColor').addEventListener('input', renderProfilePreview);
        $('uploadAvatarPhotoBtn').addEventListener('click', () => $('avatarFileInput').click());
        $('avatarFileInput').addEventListener('change', async (e) => {
            const file = e.target.files?.[0]; e.target.value = ''; if (!file) return;
            try {
                if (state.avatarPendingId) await apiCall('delete_unused_upload', { media_id: state.avatarPendingId }).catch(() => {});
                const media = await uploadFileChunked(file);
                if (media.category !== 'image') { await apiCall('delete_unused_upload', { media_id: media.id }).catch(() => {}); throw new Error('فقط تصاویر معتبر JPEG/PNG/WebP/GIF برای پروفایل قابل قبول‌اند.'); }
                state.avatarPendingId = media.id;
                state.avatarRemovalRequested = false;
                renderProfilePreview();
                showToast('تصویر آماده است؛ برای اعمال، «ذخیره مشخصات» را بزنید.', 'success');
            } catch (err) { showToast(err.message, 'error'); }
        });
        $('removeAvatarPhotoBtn').addEventListener('click', () => { state.avatarRemovalRequested = true; if (state.avatarPendingId) { apiCall('delete_unused_upload', { media_id: state.avatarPendingId }).catch(() => {}); state.avatarPendingId = null; } renderProfilePreview(); });
        $('terminateOtherSessionsBtn').addEventListener('click', async () => {
            if (!confirm('تمام دستگاه‌های دیگر از حساب شما خارج شوند؟')) return;
            try { const res = await apiCall('terminate_other_sessions', {}); if (res.ok) { showToast(`${res.terminated_count} نشست بسته شد.`, 'success'); refreshMySessions(); } else displayError(res); }
            catch (err) { showToast(err.message, 'error'); }
        });
        $('prefThemeSelect').addEventListener('change', (e) => applyTheme(e.target.value));
        $('prefFontSizeSelect').addEventListener('change', (e) => applyFontSize(e.target.value));
        $('prefFontFaceSelect').addEventListener('change', (e) => applyFontFace(e.target.value));
        $('prefSoundCheck').addEventListener('change', (e) => { state.soundEnabled = e.target.checked; store('hamrah_sound', state.soundEnabled ? '1' : '0'); });
        $('prefEnterSendCheck').addEventListener('change', (e) => {
            state.enterToSend = e.target.checked;
            if (state.user) store(`hamrah_enter_send:${state.user.username}`, state.enterToSend ? '1' : '0');
            $('messageInput').placeholder = state.enterToSend ? 'پیام... (Enter برای ارسال)' : 'پیام... (Enter برای سطر بعد)';
        });
        $('prefAutoImgCompressCheck').addEventListener('change', (e) => { store('hamrah_image_compress', e.target.checked ? '1' : '0'); $('compressImagesCheck').checked = e.target.checked; });
        $('compressImagesCheck').addEventListener('change', (e) => { store('hamrah_image_compress', e.target.checked ? '1' : '0'); $('prefAutoImgCompressCheck').checked = e.target.checked; });
        $('prefAutoVidPromptCheck').addEventListener('change', (e) => store('hamrah_video_prompt', e.target.checked ? '1' : '0'));
        $('wipeSavedMessagesBtn').addEventListener('click', () => wipePersonalSection('saved'));
        $('wipePersonalDriveBtn').addEventListener('click', () => wipePersonalSection('drive'));
        $('wipeAiHistoryBtn').addEventListener('click', () => wipePersonalSection('ai'));
        $('resetMyDataBtn').addEventListener('click', resetMyAccountData);

        // Group/Channel creation from selected contacts or admin's full user list
        $('nrAllMembersCheck').addEventListener('change', (e) => $('nrMembersSelectWrap').classList.toggle('hidden', e.target.checked));
        $('newRoomForm').addEventListener('submit', async (e) => {
            e.preventDefault();
            const members = $('nrAllMembersCheck').checked && state.user.role === 'admin' ? ['*'] : [...$('nrMembersSelectWrap').querySelectorAll('input:checked')].map((i) => i.value);
            try {
                const isPublic = $('nrVisibility').value === 'public';
                const res = await apiCall('create_room', { type: $('nrType').value, title: $('nrTitle').value.trim(), description: $('nrDesc').value.trim(), members, only_admins_post: $('nrType').value === 'channel', is_public: isPublic, public_handle: isPublic ? $('nrPublicHandle').value.trim() : '' });
                if (!res.ok) return displayError(res);
                closeModal('newRoomModal');
                const boot = await apiCall('bootstrap');
                if (boot.ok) { state.dialogs = boot.dialogs || []; state.users = boot.users || state.users; renderDialogList(); selectChat(res.room.id); }
                showToast('گروه/کانال ساخته شد.', 'success');
            } catch (err) { showToast(err.message, 'error'); }
        });

        // Personal Drive: folders, files, notes, stars & deletion
        $('driveFolderList').addEventListener('click', async (e) => {
            const del = e.target.closest('[data-remove-folder]');
            if (del) {
                if (!confirm('این پوشه حذف شود؟ محتوا به پوشه «فایل‌ها و اسناد من» منتقل می‌شود.')) return;
                try { const res = await apiCall('drive_delete_folder', { folder_id: del.dataset.removeFolder }); if (!res.ok) return displayError(res); state.activeDriveFolder = 'all'; loadPersonalDriveData(); }
                catch (err) { showToast(err.message, 'error'); }
                return;
            }
            const row = e.target.closest('[data-dfolder]');
            if (row) { state.activeDriveFolder = row.dataset.dfolder; loadPersonalDriveData(); }
        });
        $('addDriveFolderBtn').addEventListener('click', async () => {
            const name = $('newDriveFolderInput').value.trim(); if (!name) return;
            try {
                const res = await apiCall('drive_create_folder', { name });
                if (!res.ok) return displayError(res);
                $('newDriveFolderInput').value = '';
                state.activeDriveFolder = res.folder.id;
                loadPersonalDriveData();
                showToast('پوشه ساخته شد.', 'success');
            } catch (err) { showToast(err.message, 'error'); }
        });
        $('driveUploadFileBtn').addEventListener('click', () => $('driveFileInput').click());
        $('driveLoadMoreBtn').addEventListener('click', async () => {
            const btn = $('driveLoadMoreBtn');
            btn.disabled = true;
            try { await loadPersonalDriveData(true); } finally { btn.disabled = false; }
        });
        $('driveFileInput').addEventListener('change', (e) => { const files = [...e.target.files]; e.target.value = ''; uploadToPersonalDrive(files); });
        $('driveNewNoteBtn').addEventListener('click', () => { $('driveNoteForm').classList.toggle('hidden'); $('driveNoteTitle').focus(); });
        $('cancelDriveNoteBtn').addEventListener('click', () => $('driveNoteForm').classList.add('hidden'));
        $('saveDriveNoteBtn').addEventListener('click', saveDriveNote);
        $('driveItemsGrid').addEventListener('click', async (e) => {
            const btn = e.target.closest('[data-drive-op]'); if (!btn) return;
            if (btn.dataset.driveOp === 'delete' && !confirm('این مورد از فضای شخصی پاک شود؟ اگر نسخه دیگری از فایل در گفتگوها نباشد، فایل از هاست هم حذف خواهد شد.')) return;
            try { const res = await apiCall('drive_update_item', { item_id: btn.dataset.driveId, op: btn.dataset.driveOp }); if (!res.ok) return displayError(res); loadPersonalDriveData(); }
            catch (err) { showToast(err.message, 'error'); }
        });

        // Admin panel: users.json, web tools, AI, global config & shard monitor
        document.querySelectorAll('.admin-tab').forEach((tab) => tab.addEventListener('click', () => activateAdminPane(tab.dataset.atab)));
        $('activityRefreshBtn').addEventListener('click', refreshAccountActivity);
        $('activitySearchInput').addEventListener('input', renderAccountActivity);
        $('activityFilterSelect').addEventListener('change', renderAccountActivity);
        $('adminUserForm').addEventListener('submit', async (e) => {
            e.preventDefault();
            const isNew = $('auIsNew').value === '1';
            const pass = $('auPassword').value;
            if (isNew && pass.length < 8) return showToast('برای کاربر جدید رمزی با حداقل ۸ کاراکتر تعیین کنید.', 'error');
            const payload = { is_new: isNew, original_username: $('auOriginalUsername').value, username: $('auUsername').value.trim(), password: pass, display_name: $('auDisplayName').value.trim(), role: $('auRole').value, quota_mb: Number($('auQuota').value || 1024), active: $('auActive').value === '1' };
            try {
                const res = await apiCall('admin_save_user', payload);
                if (!res.ok) return displayError(res);
                resetAdminUserForm(); await refreshAdminData();
                if (res.current_admin?.username !== state.user.username) {
                    const boot = await apiCall('bootstrap'); if (boot.ok) enterAuthenticatedApp(boot);
                } else runSyncCycle();
                showToast(isNew ? 'کاربر جدید تعریف شد.' : 'اطلاعات کاربر بروزرسانی شد.', 'success');
            } catch (err) { showToast(err.message, 'error'); }
        });
        $('auResetBtn').addEventListener('click', resetAdminUserForm);
        $('adminUsersTableBody').addEventListener('click', async (e) => {
            const edit = e.target.closest('[data-admin-edit]');
            if (edit) return openAdminUserForEdit(edit.dataset.adminEdit);
            const reset = e.target.closest('[data-admin-reset]');
            if (reset) {
                const username = reset.dataset.adminReset;
                if (prompt(`برای ریست داده‌های @${username} آیدی او را عیناً وارد کنید. پیام‌های مشترک از نمای وی پنهان می‌شوند ولی نسخهٔ دیگران محفوظ است:`) !== username) return;
                try { const res = await apiCall('admin_reset_user_data', { username, confirmation: username }); if (!res.ok) return displayError(res); await refreshAdminData(); showToast('داده‌های حساب هدف ریست شد؛ آیدی و رمز حفظ شد.', 'success'); }
                catch (err) { showToast(err.message, 'error'); }
                return;
            }
            const del = e.target.closest('[data-admin-delete]');
            if (!del) return;
            if (!confirm(`حساب کاربری @${del.dataset.adminDelete} حذف شود؟\nتاریخچه گفتگوی خصوصی دوطرفه با این کاربر برای هر دو طرف پاک می‌شود؛ پیام‌های گروهیِ دیگران باقی می‌مانند.`)) return;
            try { const res = await apiCall('admin_delete_user', { username: del.dataset.adminDelete }); if (!res.ok) return displayError(res); await refreshAdminData(); runSyncCycle(); showToast('دسترسی حساب کاربر حذف شد.', 'success'); }
            catch (err) { showToast(err.message, 'error'); }
        });
        $('addWebToolRowBtn').addEventListener('click', () => {
            const root = $('adminWebToolsList');
            if (root.querySelectorAll('.web-tool-row').length >= 20) return showToast('حداکثر ۲۰ ابزار قابل تعریف است.', 'error');
            if (!root.querySelector('.web-tool-row')) root.innerHTML = '';
            root.insertAdjacentHTML('beforeend', toolRowHtml({ icon: '📰', title: '', command: '/news', type: 'custom_url', lang: 'fa', url_template: '', placeholder: 'موضوع جستجو...', enabled: true, quick_chip: true }));
            root.lastElementChild.querySelector('.wt-title').focus();
        });
        $('adminWebToolsList').addEventListener('click', (e) => { const del = e.target.closest('.wt-remove'); if (del) del.closest('.web-tool-row').remove(); });
        $('saveWebToolsBtn').addEventListener('click', async () => {
            try {
                const web_tools = collectWebTools();
                const res = await apiCall('admin_save_config', { web_tools });
                if (!res.ok) return displayError(res);
                state.config.web_tools = web_tools;
                renderAiToolbar();
                await refreshAdminData();
                showToast('ابزارهای وب/ویکی‌پدیا بروزرسانی شدند.', 'success');
            } catch (err) { showToast(err.message, 'error'); }
        });
        document.querySelectorAll('.ai-preset').forEach((preset) => preset.addEventListener('click', () => { $('aiBaseUrl').value = preset.dataset.url; $('aiModel').value = preset.dataset.model; }));
        $('saveAiAndGeneralConfigBtn').addEventListener('click', async () => {
            const cfg = {
                app_title: $('cfgAppTitle').value.trim(), app_subtitle: $('cfgAppSubtitle').value.trim(), github_url: $('cfgGithubUrl').value.trim(), max_upload_mb: Number($('cfgMaxUploadMb').value), video_compress_threshold_mb: Number($('cfgVidThresholdMb').value),
                allow_user_profile_edit: $('cfgAllowProfileEdit').checked, allow_user_username_change: $('cfgAllowUsernameChange').checked,
                ai_bridge: { enabled: $('aiEnabledCheck').checked, api_base_url: $('aiBaseUrl').value.trim(), model: $('aiModel').value.trim(), api_key: $('aiApiKey').value.trim(), system_prompt: $('aiSystemPrompt').value.trim() },
            };
            try {
                const res = await apiCall('admin_save_config', cfg);
                if (!res.ok) return displayError(res);
                state.config = Object.assign(state.config, cfg);
                await refreshAdminData();
                await runSyncCycle();
                showToast('تنظیمات کلی و هوش مصنوعی ذخیره شد.', 'success');
            } catch (err) { showToast(err.message, 'error'); }
        });
        $('saveShardConfigBtn').addEventListener('click', async () => {
            try {
                const res = await apiCall('admin_save_config', { max_shard_bytes: Number($('cfgMaxShardKb').value) * 1024, max_shard_messages: Number($('cfgMaxShardMsgs').value) });
                if (!res.ok) return displayError(res);
                await refreshAdminData();
                showToast('آستانهٔ تقسیم فایل‌های JSON بروزرسانی شد.', 'success');
            } catch (err) { showToast(err.message, 'error'); }
        });
        $('saveRawUsersJsonBtn').addEventListener('click', async () => {
            if (!confirm('فایل users.json با نسخه ویرایش‌شده جایگزین شود؟ پیش از ذخیره از آن نسخه پشتیبان تهیه کنید.')) return;
            try { const res = await apiCall('admin_save_raw_users_json', { raw_json: $('rawUsersJsonTextarea').value }); if (!res.ok) return displayError(res); await refreshAdminData(); runSyncCycle(); showToast('فایل users.json اعتبارسنجی و ذخیره شد.', 'success'); }
            catch (err) { showToast(err.message, 'error'); }
        });
        $('downloadUsersJsonBtn').addEventListener('click', () => {
            const blob = new Blob([$('rawUsersJsonTextarea').value], { type: 'application/json;charset=utf-8' });
            const url = URL.createObjectURL(blob); const a = document.createElement('a'); a.href = url; a.download = 'users.json'; a.click(); setTimeout(() => URL.revokeObjectURL(url), 1000);
        });
        $('retestSecurityBtn').addEventListener('click', runBrowserSecurityCheck);
    }

    if (document.readyState === 'loading') document.addEventListener('DOMContentLoaded', initApp);
    else initApp();
})();
