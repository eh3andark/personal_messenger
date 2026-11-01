/* UI checks for rooms, fonts, stickers, media inventory, quota warning.
 * Disposable test fixture only, after tests/new_features.py (random credentials).
 */
const {chromium}=require('playwright-core');
const fs=require('node:fs');
const assert=require('node:assert/strict');
const site=process.env.HAMRAH_TEST_URL||'http://127.0.0.1:8790';
const fixture=require('node:path').resolve(process.env.HAMRAH_TEST_FIXTURE||'/home/user/.cache/hamrah-test-site');
assert.ok(fixture.includes('/.cache/hamrah-test-site') && fs.existsSync(fixture+'/.browser-feature-credentials.json') && /^http:\/\/127\.0\.0\.1:\d+$/.test(site),
  'Refusing to use a real site: only a local disposable fixture is allowed');
const creds=JSON.parse(fs.readFileSync(fixture+'/.browser-feature-credentials.json','utf8'));
let n=0; const check=(cond,label,detail='')=>{n++; assert.ok(cond,label+' '+detail);if(n%10===0)console.log(`OK ${n}: ${label}`)};
const noOverflow=async(page,label)=>{let v=await page.evaluate(()=>({w:innerWidth,doc:document.documentElement.scrollWidth,body:document.body.scrollWidth}));check(v.doc<=v.w+2&&v.body<=v.w+2,`no overflow ${label}`,JSON.stringify(v));};
async function login(page,user,pw){await page.locator('#loginUsername').fill(user);await page.locator('#loginPassword').fill(pw);await page.locator('#loginSubmitBtn').click();await page.locator('#appScreen').waitFor({state:'visible',timeout:20000});check(await page.locator('#authScreen').isHidden(),`login ${user}`);if(await page.locator('#installOfferModal').isVisible())await page.locator('#installOfferLaterBtn').click();}
async function test(){
 const browser=await chromium.launch({executablePath:'/usr/bin/chromium',headless:true,args:['--no-sandbox','--disable-dev-shm-usage']});
 const context=await browser.newContext({viewport:{width:1300,height:850}});
 const page=await context.newPage();page.setDefaultTimeout(10000);
 const errors=[];page.on('pageerror',e=>errors.push(e.stack||e.message));page.on('console',e=>{if(e.type()==='error'&&!e.text().includes('Failed to load resource:'))errors.push(e.text())});
 page.on('dialog',d=>d.accept());
 await page.goto(site+'/index.php',{waitUntil:'domcontentloaded'});
 await page.locator('#authScreen').waitFor({state:'visible'});
 check((await page.locator('html').getAttribute('data-theme'))==='light','Telegram-like light is default');
 check((await page.locator('#authScreen').innerText()).includes('چت امن'),'new app name visible on login');
 check(!(await page.locator('#authScreen').innerText()).includes('HTTPS'),'login reassurance has no HTTPS wording');
 check(await page.locator('.auth-source-link').count()===0,'no fake GitHub source URL on login');
 await login(page,'alice',creds.alice);
 await page.locator('#menuBtn').click();await page.locator('#menuThemeBtn').click();
 await page.locator('#settingsModal [data-ustab="appearance"]').click();
 await page.locator('#prefFontFaceSelect').selectOption('estedad');
 await page.locator('#prefThemeSelect').selectOption('lilac');
 check(await page.locator('html').getAttribute('data-font')==='estedad'&&await page.locator('html').getAttribute('data-theme')==='lilac','local font and feminine theme work');
 check((await page.locator('body').evaluate(el=>getComputedStyle(el).fontFamily)).includes('Estedad'),'local Estedad applied to body');
 await page.locator('#prefFontFaceSelect').selectOption('sahel');
 check((await page.locator('body').evaluate(el=>getComputedStyle(el).fontFamily)).includes('Sahel'),'local Sahel applied to body');
 await page.locator('#settingsModal [data-close-modal]').click();await page.reload({waitUntil:'domcontentloaded'});
 await page.locator('#appScreen').waitFor({state:'visible'});
 check(await page.locator('html').getAttribute('data-font')==='sahel'&&await page.locator('html').getAttribute('data-theme')==='lilac','font/theme persist for account after reload');
 const room=creds.private_room;
 await page.locator(`#dialogList [data-chat-id="${room}"]`).click();
 await page.locator('#chatHeaderInfo').click();
 await page.locator('#drawerRoomPanel').waitFor({state:'visible'});
 check(await page.locator('#drawerRoomManager').isVisible()&&await page.locator('#drawerRoomInviteLink').inputValue().then(s=>s.includes('#join=')&&s.includes('token=')),'manager can see private invitation link and controls');
 await page.locator('#drawerRoomMemberId').fill('bob');await page.locator('#drawerAddRoomMemberBtn').click();
 await page.locator('#drawerRoomMembers [data-remove-room-member="bob"]').waitFor({state:'visible'});
 check(await page.locator('#drawerRoomMembers [data-remove-room-member="bob"]').count()===1,'room member added by exact ID in drawer');
 await noOverflow(page,'room info desktop');
 await page.locator('#closeInfoDrawerBtn').click();
 // Create a static sticker using only a local PNG, then send it from the picker.
 await page.locator('#stickerBtn').click();
 await page.locator('#emojiPopover [data-create-sticker]').click();
 await page.locator('#stickerCreateModal').waitFor({state:'visible'});
 const png=Buffer.from('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVQIHWP4z8DwHwAFgAI/ScL/nQAAAABJRU5ErkJggg==','base64');
 await page.locator('#stickerCreateFileInput').setInputFiles({name:'test.png',mimeType:'image/png',buffer:png});
 await page.locator('#stickerCreatePreview').waitFor({state:'visible'});
 await page.locator('#stickerCreateLabel').fill('سلام');
 await page.locator('#saveStickerBtn').click();
 await page.locator('#stickerCreateModal').waitFor({state:'hidden',timeout:15000});
 await page.locator('#emojiPopover [data-send-sticker]').first().waitFor({state:'visible'});
 check(await page.locator('#emojiPopover .sticker-tile').count()>=1,'sticker created and listed in private pack');
 await page.locator('#emojiPopover [data-send-sticker]').first().click();
 await page.locator('#messagesList .msg-sticker img').last().waitFor({state:'visible'});
 check(await page.locator('#messagesList .msg-sticker').count()>=1,'sticker sent and rendered inline');
 await page.locator('#menuBtn').click();await page.locator('#menuAllMediaBtn').click();
 await page.locator('#myMediaModal').waitFor({state:'visible'});
 await page.locator('#myMediaModal .owned-media-item').first().waitFor({state:'visible',timeout:12000});
 check(await page.locator('#myMediaModal .owned-media-item').count()>=1,'cross-chat owned media inventory opens');
 await page.locator('input[name="myMediaScope"][value="both"]').check();
 check(await page.locator('#myMediaPassword').isVisible(),'global bulk scope requests password');
 await page.locator('input[name="myMediaScope"][value="me"]').check();
 check(await page.locator('#myMediaPasswordWrap').isHidden(),'me-only scope does not request password');
 await noOverflow(page,'media inventory desktop');
 await page.locator('#myMediaModal [data-close-modal]').click();
 // Public discovery and join from another account, then leave.
 const bobContext=await browser.newContext({viewport:{width:390,height:844}});
 const bob=await bobContext.newPage();bob.setDefaultTimeout(10000);
 bob.on('pageerror',e=>errors.push('bob '+(e.stack||e.message)));
 bob.on('dialog',d=>d.accept());
 await bob.goto(site+'/index.php',{waitUntil:'domcontentloaded'});await login(bob,'bob',creds.bob);
 await bob.locator('#menuBtn').click();await bob.locator('#menuFindRoomBtn').click();
 await bob.locator('#findRoomIdInput').fill('friendly_room');await bob.locator('#findRoomForm button').click();
 await bob.locator('#joinFoundRoomBtn').waitFor({state:'visible'});
 check((await bob.locator('#findRoomResult').innerText()).includes('دوستان'),'public room found by exact handle (no directory)');
 await bob.locator('#joinFoundRoomBtn').click();
 await bob.locator(`#dialogList [data-chat-id="${creds.public_room}"]`).waitFor({state:'attached'});
 check(await bob.locator(`#dialogList [data-chat-id="${creds.public_room}"]`).count()===1,'public room joined from mobile UI');
 await noOverflow(bob,'joined mobile chat');
 await bob.locator('#chatHeaderInfo').click();await bob.locator('#drawerRoomPanel').waitFor({state:'visible'});
 check(await bob.locator('#drawerLeaveRoomBtn').isVisible(),'ordinary member sees leave control');
 await bob.locator('#drawerLeaveRoomBtn').click();
 await bob.locator(`#dialogList [data-chat-id="${creds.public_room}"]`).waitFor({state:'detached'});
 check(await bob.locator(`#dialogList [data-chat-id="${creds.public_room}"]`).count()===0,'member leaves public room from drawer');
 for(const width of [920,480,390,320]){await page.setViewportSize({width,height:790});await noOverflow(page,`new UI app ${width}`);
   if(await page.evaluate(()=>document.body.classList.contains('mobile-chat-open')))await page.locator('#mobileBackBtn').click();
   await page.locator('#menuBtn').click();await page.locator('#menuAllMediaBtn').click();await noOverflow(page,`media modal ${width}`);await page.locator('#myMediaModal [data-close-modal]').click();}
 // Inject only a disposable fixture quota counter, never production data.
 const usage=fixture+'/data/personal_drive/quotauser_usage.json';
 fs.mkdirSync(fixture+'/data/personal_drive',{recursive:true});
 fs.writeFileSync(usage,JSON.stringify({used_bytes:41*1048576,updated_at:Math.floor(Date.now()/1000)}));
 const quotaCtx=await browser.newContext({viewport:{width:320,height:720}});
 const q=await quotaCtx.newPage();q.setDefaultTimeout(10000);q.on('pageerror',e=>errors.push('quota '+(e.stack||e.message)));
 await q.goto(site+'/index.php',{waitUntil:'domcontentloaded'});await login(q,'quotauser',creds.quotauser);
 await q.locator('#quotaAlertBanner').waitFor({state:'visible'});
 check((await q.locator('#quotaAlertBanner').innerText()).includes('۸۰٪')&&await q.locator('#attachBtn').isDisabled(),
       '80% quota prominent top warning and new-media attach disabled');
 check(await q.locator('#voiceRecordBtn').isDisabled(),'voice recording disabled above cutoff');
 await q.locator('#dialogList [data-chat-id="saved_quotauser"]').click();
 await q.locator('#messageInput').fill('متن مجاز است');
 check(await q.locator('#voiceRecordBtn').isEnabled(),'text send remains available over quota');
 await q.locator('#voiceRecordBtn').click();
 await q.locator('#messagesList .msg-text').filter({hasText:'متن مجاز است'}).waitFor({state:'visible'});
 await noOverflow(q,'quota banner mobile 320px');
 await q.locator('#quotaManageNowBtn').click();await q.locator('#myMediaModal').waitFor({state:'visible'});
 check(await q.locator('#myMediaModal input[name="myMediaScope"]').count()===2,'quota banner opens bulk deletion with both scopes');
 await noOverflow(q,'quota management mobile 320px');
 fs.writeFileSync(usage,JSON.stringify({used_bytes:0,updated_at:Math.floor(Date.now()/1000)}));
 check(errors.length===0,'no uncaught JavaScript errors in new UI',errors.slice(0,4).join('\n'));
 console.log(`PASS: ${n} new browser checks: fonts/themes, stickers, groups/invite/join/leave, owned media, quota cutoff & 320px layout`);
 await quotaCtx.close();await bobContext.close();await context.close();await browser.close();
}
test().catch(e=>{console.error(e);process.exit(1)});
