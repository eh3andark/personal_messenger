/* Optional browser/UI regression. Run against a disposable test fixture only.
 * NODE_PATH=/home/user/.cache/hamrah-browser/node_modules node tests/browser.cjs
 */
const {chromium} = require('playwright-core');
const assert = require('node:assert/strict');
const site = process.env.HAMRAH_TEST_URL || 'http://127.0.0.1:8788';
const credPath = require('node:path').resolve(process.env.HAMRAH_TEST_CREDENTIALS || '/home/user/.cache/hamrah-test-site/.browser-test-credentials.json');
assert.ok(/^http:\/\/127\.0\.0\.1:\d+$/.test(site) && credPath.includes('/.cache/hamrah-test-site/'),
  'Refusing to use a real site: only a local disposable fixture is allowed');
const creds = JSON.parse(require('node:fs').readFileSync(credPath,'utf8'));
let checks = 0, runningBrowser = null;
function check(cond, label, detail='') { checks++; assert.ok(cond, `${label} ${detail}`); if(checks%15===0)console.log(`OK ${checks}: ${label}`); }
async function noOverflow(page, label) {
  const v = await page.evaluate(() => ({width: innerWidth, docWidth: document.documentElement.scrollWidth, bodyWidth: document.body.scrollWidth, visible: [...document.querySelectorAll('.modal-backdrop:not(.hidden)')].map(x=>x.id)}));
  check(v.docWidth <= v.width+2 && v.bodyWidth <= v.width+2, `no horizontal overflow: ${label}`, JSON.stringify(v));
}
async function login(page, username, pass) {
  await page.locator('#loginUsername').fill(username);
  await page.locator('#loginPassword').fill(pass);
  await page.locator('#loginSubmitBtn').click();
  await page.locator('#appScreen').waitFor({state:'visible',timeout:15000});
  check(await page.locator('#authScreen').isHidden(), `logged in as ${username}`);
  if (await page.locator('#installOfferModal').isVisible()) {
    await page.locator('#installOfferLaterBtn').click();
    check(await page.locator('#installTopBtn').isVisible(), 'install control stays available after offer dismissal');
  }
}
async function test() {
 const browser = await chromium.launch({executablePath:'/usr/bin/chromium', headless:true, args:['--no-sandbox','--disable-dev-shm-usage']});
 runningBrowser = browser;
 const context = await browser.newContext({viewport:{width:1440,height:900}, deviceScaleFactor:1});
 const page = await context.newPage();
 page.setDefaultTimeout(9000);
 const errors = [], httpFailures = [];
 page.on('pageerror', e=>errors.push(e.stack||e.message));
 page.on('console', m=>{if(m.type()==='error' && !m.text().includes('Failed to load resource:'))errors.push('console: '+m.text())});
 page.on('response', resp=> { if(resp.status() >= 400) { const u=new URL(resp.url()); httpFailures.push({code:resp.status(),path:u.pathname,action:u.searchParams.get('action'),id:u.searchParams.get('id')}); }});
 page.on('dialog', async dialog=> {if(dialog.type()==='prompt') await dialog.accept('DELETE');else await dialog.accept()});
 await page.goto(site+'/index.php', {waitUntil:'domcontentloaded'});
 await page.locator('#authScreen').waitFor({state:'visible'});
 check(!/رمز اولیه|admin1234|راه‌اندازی|نصب اولیه/.test(await page.locator('#authScreen').innerText()), 'login has no sample credentials/setup hints');
 await noOverflow(page,'login desktop');
 await login(page,'chief',creds.chief);
 await page.locator('#dialogList .dialog-item').first().waitFor({state:'visible'});
 await noOverflow(page,'app desktop');
 check(await page.locator('#dialogList [data-chat-id="dm_alicia__chief"]').count()===1, 'admin sees managed users by ID');
 await page.locator('#dialogList [data-chat-id="ai_chief"]').click();
 await page.locator('#telegramServiceBar').waitFor({state:'visible'});
 check(await page.locator('#telegramChannelIdInput').isVisible(), 'services area has a public channel-ID input');
 await page.locator('#telegramChannelIdInput').fill('bad/channel');
 await page.locator('#telegramServiceBar button').click();
 check(await page.locator('#telegramChannelIdInput').inputValue()==='bad/channel', 'invalid Telegram ID rejected in browser before network request');
 await page.locator('#telegramChannelIdInput').fill('@samplechannel');
 await page.locator('#telegramServiceBar button').click();
 await page.waitForFunction(()=>document.querySelectorAll('#messagesList .msg-row.ai-msg').length===21, null, {timeout:10000});
 check((await page.locator('#messagesList .msg-row.ai-msg').last().innerText()).includes('https://t.me/samplechannel/20'), 'one click imports 20 separate linked posts into services chat');
 await noOverflow(page,'Telegram service with 20 posts desktop');
 await page.locator('#dialogList [data-chat-id="dm_alicia__chief"]').click();
 check(await page.locator('#telegramServiceBar').isHidden(), 'public-channel form scoped to the services chat');
 await page.locator('#messageInput').fill('Browser sent message & expected RTL');
 await page.locator('#voiceRecordBtn').click();
 await page.locator('#messagesList .msg-text').filter({hasText:'Browser sent message & expected RTL'}).waitFor({state:'visible'});
 check(await page.locator('#messageInput').inputValue()==='', 'send button actually sends text');
 await page.locator('#messageInput').fill('<img src=x onerror=alert(1)>');
 await page.locator('#voiceRecordBtn').click();
 await page.locator('#messagesList .msg-text').filter({hasText:'<img src=x onerror=alert(1)>'}).waitFor({state:'visible'});
 check(await page.locator('#messagesList .msg-text img').count()===0, 'message XSS rendered as text');
 await page.locator('#dialogList [data-dialog-menu="dm_alicia__chief"]').click();
 await page.locator('#dialogActionsModal').waitFor({state:'visible'});
 check(await page.locator('#dialogActionsList [data-dialog-action="clear_me"]').count()===1 && await page.locator('#dialogActionsList [data-dialog-action="clear_both"]').count()===1, 'dialog actions expose both deletion scopes');
 await noOverflow(page,'dialog actions desktop');
 await page.locator('#dialogActionsList [data-dialog-action="muted"]').click();
 await page.locator('#dialogActionsModal').waitFor({state:'hidden'});
 check(await page.locator('#dialogList [data-chat-id="dm_alicia__chief"] .dialog-flags').count()===1, 'mute persisted in inbox');
 await page.locator('#dialogList [data-dialog-menu="dm_alicia__chief"]').click();
 await page.locator('#dialogActionsList [data-dialog-action="archived"]').click();
 await page.locator('#dialogList [data-chat-id="dm_alicia__chief"]').waitFor({state:'hidden'});
 check(await page.locator('#dialogList [data-chat-id="dm_alicia__chief"]').count()===0, 'archive moves conversation out of normal inbox');
 await page.locator('#folderTabs [data-filter="archived"]').click();
 check(await page.locator('#dialogList [data-chat-id="dm_alicia__chief"]').count()===1, 'archive tab reveals archived chat');
 await page.locator('#dialogList [data-dialog-menu="dm_alicia__chief"]').click();
 await page.locator('#dialogActionsList [data-dialog-action="archived"]').click();
 await page.locator('#dialogActionsModal').waitFor({state:'hidden'});
 await page.locator('#folderTabs [data-filter="all"]').click();
 await page.locator('#dialogList [data-chat-id="dm_alicia__chief"]').waitFor({state:'visible'});
 check(await page.locator('#dialogList [data-chat-id="dm_alicia__chief"]').count()===1, 'unarchive returns to inbox');
 await page.locator('#menuBtn').click();
 await page.locator('#menuThemeBtn').click();
 await page.locator('#settingsModal [data-ustab="appearance"]').click();
 await page.locator('#prefThemeSelect').selectOption('rose');
 check(await page.locator('html').getAttribute('data-theme')==='rose', 'feminine rose theme selectable');
 await noOverflow(page,'settings desktop');
 await page.locator('#settingsModal [data-close-modal]').click();
 await page.reload({waitUntil:'domcontentloaded'});
 await page.locator('#appScreen').waitFor({state:'visible'});
 check(await page.locator('html').getAttribute('data-theme')==='rose', 'theme persists per account on reload');
 await page.locator('#menuBtn').click();
 await page.locator('#menuThemeBtn').click();
 await page.locator('#settingsModal [data-ustab="appearance"]').click();
 await page.locator('#prefThemeSelect').selectOption('midnight');
 check(await page.locator('html').getAttribute('data-theme')==='midnight', 'Telegram-like midnight theme selectable');
 await page.locator('#settingsModal [data-ustab="cleanup"]').click();
 check(await page.locator('#resetMyDataBtn').count()===1 && await page.locator('#resetMyConfirmation').count()===1, 'account-wide reset accessible from settings');
 await page.locator('#settingsModal [data-close-modal]').click();
 await page.locator('#menuBtn').click();
 await page.locator('#menuAdminBtn').click();
 await page.locator('#adminUsersTableBody').filter({hasText:'alicia'}).waitFor({state:'visible'});
 check(await page.locator('#adminUsersTableBody [data-admin-reset="alicia"]').count()===1, 'admin reset control visible');
 await noOverflow(page,'admin modal desktop');
 await page.locator('#adminModal [data-close-modal]').click();
 for(const width of [920,810,640,480,390,350,320]) {
   await page.setViewportSize({width,height:800});
   await page.waitForTimeout(35);
   await noOverflow(page,`app ${width}px`);
   const sidebar=await page.locator('#sidebarPanel').boundingBox();
   check(sidebar && sidebar.width > Math.min(width*0.70,200), `sidebar available at ${width}px`,JSON.stringify(sidebar));
   if (width<=920) {
     await page.locator('#dialogList [data-chat-id="dm_alicia__chief"]').click();
     check(await page.evaluate(()=>document.body.classList.contains('mobile-chat-open')),`mobile chat opened at ${width}px`);
     await noOverflow(page,`chat ${width}px`);
     check(await page.locator('#chatStage').isVisible(),`mobile chat visible at ${width}px`);
     if (width<=350) check(await page.locator('#chatSearchToggleBtn').isVisible(),`message search remains available at ${width}px`);
     const more = page.locator('#messagesList .msg-row .msg-more-btn').last();
     await more.click();
     check(await page.locator('#messagesList .msg-row.actions-open .msg-actions-bar').last().isVisible(),`message action controls usable by touch at ${width}px`);
     await page.locator('#mobileBackBtn').click();
     check(!await page.evaluate(()=>document.body.classList.contains('mobile-chat-open')),`mobile back works at ${width}px`);
     if(width===320){
       await page.locator('#dialogList [data-chat-id="ai_chief"]').click();
       await page.locator('#telegramServiceBar').waitFor({state:'visible'});
       await noOverflow(page,'Telegram ID service at 320px');
       check(await page.locator('#telegramChannelIdInput').isVisible(), 'Telegram input usable at 320px');
       await page.locator('#mobileBackBtn').click();
     }
   }
 }
 await page.setViewportSize({width:390,height:844});
 await page.locator('#menuBtn').click();
 await page.locator('#menuDriveBtn').click();
 await page.locator('#driveModal').waitFor({state:'visible'});
 await noOverflow(page,'drive mobile');
 await page.locator('#driveModal [data-close-modal]').click();
 await page.locator('#dialogList [data-chat-id="dm_alicia__chief"]').click();
 await page.locator('#chatHeaderInfo').click();
 await page.locator('#infoDrawer').waitFor({state:'visible'});
 await noOverflow(page,'info drawer mobile');
 check(await page.locator('#drawerClearForBothBtn').isVisible(), 'shared clear via mobile drawer');
 await page.locator('#closeInfoDrawerBtn').click();
 await page.locator('#mobileBackBtn').click();
 await page.locator('#menuBtn').click();
 await page.locator('#menuLogoutBtn').click();
 await page.locator('#authScreen').waitFor({state:'visible'});
 await noOverflow(page,'login mobile');
 await login(page,'alicia',creds.alicia);
 check(await page.locator('html').getAttribute('data-theme') !== 'midnight', 'account theme isolation');
 await noOverflow(page,'other user mobile');
 check(errors.length===0, 'no browser JavaScript errors', errors.slice(0,5).join('\n'));
 console.log('HTTP errors seen by browser (to review):', JSON.stringify(httpFailures.slice(0,10)));
 console.log(`PASS: ${checks} browser/UI checks across desktop, tablet and 320–920px mobile; no uncaught JS errors`);
 await browser.close();
 runningBrowser = null;
}
test().catch(async e=> {console.error(e); await runningBrowser?.close().catch(()=>{}); process.exitCode=1});
