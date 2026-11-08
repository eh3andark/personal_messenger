/* Current-release interactions and mobile layout; disposable fixture ONLY.
 * Run after tests/review_features.py (random fixture credentials). */
const {chromium}=require('playwright-core');
const fs=require('node:fs');
const assert=require('node:assert/strict');
const site=process.env.HAMRAH_TEST_URL||'http://127.0.0.1:8790';
const fixture=require('node:path').resolve(process.env.HAMRAH_TEST_FIXTURE||'/home/user/.cache/hamrah-test-site');
assert.ok(/^http:\/\/127\.0\.0\.1:\d+$/.test(site) && fixture.endsWith('/.cache/hamrah-test-site') &&
          fs.existsSync(fixture+'/.browser-review-credentials.json'), 'Disposable fixture only');
const creds=JSON.parse(fs.readFileSync(fixture+'/.browser-review-credentials.json','utf8'));
let n=0;function check(value,label,detail=''){n++;assert.ok(value,label+' '+detail);if(n%10===0)console.log(`OK ${n}: ${label}`)}
const wait=(ms)=>new Promise(resolve=>setTimeout(resolve,ms));
async function login(page,user,password){
 await page.goto(site+'/index.php',{waitUntil:'domcontentloaded'});
 await page.locator('#authScreen').waitFor({state:'visible'});
 check(await page.locator('#bootScreen').isHidden() && await page.locator('#appScreen').isHidden(),'first-visit login follows boot check');
 await page.locator('#loginUsername').fill(user);await page.locator('#loginPassword').fill(password);
 await page.locator('#loginSubmitBtn').click();await page.locator('#appScreen').waitFor({state:'visible',timeout:15000});
 check(await page.locator('#authScreen').isHidden(),user+' logged in');
 const offered=await page.locator('#installOfferModal').isVisible();
 check(offered, 'first authenticated entry offers installation');
 await page.locator('#installOfferLaterBtn').click();
 check(await page.locator('#installOfferModal').isHidden() && await page.locator('#installTopBtn').isVisible(),
       'dismissing install offer keeps top install control');
}
async function api(page,action,data={},method='POST'){
 const token=await page.evaluate(()=>localStorage.getItem('hamrah_token'));
 const url=site+'/api.php?action='+action;
 const response=method==='GET'?await page.request.get(url+'&'+new URLSearchParams(data),{headers:{'X-Auth-Token':token}}):
  await page.request.post(url,{data,headers:{'X-Auth-Token':token,'Content-Type':'application/json'}});
 let body=await response.json();assert.ok(response.ok() && body.ok,`${action}: ${response.status()} ${JSON.stringify(body).slice(0,300)}`);
 return body;
}
async function settings(page){
 await page.locator('#menuBtn').click();await page.locator('#menuThemeBtn').click();
 await page.locator('#settingsModal').waitFor({state:'visible'});
 await page.locator('#settingsModal [data-ustab="appearance"]').click();
}
async function closeSettings(page){await page.locator('#settingsModal [data-close-modal]').click();}
async function main(){
 const browser=await chromium.launch({executablePath:'/usr/bin/chromium',headless:true,args:['--no-sandbox','--disable-dev-shm-usage']});
 try{
 const contextA=await browser.newContext({viewport:{width:1280,height:850}});
 const contextB=await browser.newContext({viewport:{width:1280,height:850},geolocation:{latitude:35.6892,longitude:51.3890},permissions:['geolocation']});
 const alice=await contextA.newPage(),bob=await contextB.newPage();
 alice.setDefaultTimeout(12000);bob.setDefaultTimeout(12000);
 const errors=[];
 for(const page of [alice,bob])page.on('pageerror',e=>errors.push(e.stack||e.message));
 bob.on('dialog',d=>d.accept());
 let aliceTabId='';alice.on('request',req=>{if(req.url().includes('api.php?action=login')){try{aliceTabId=req.postDataJSON().tab_id||''}catch{}}});
 await login(alice,'alice',creds.alice);await login(bob,'bob',creds.bob);
 await alice.locator('#installTopBtn').click();check(await alice.locator('#installOfferModal').isVisible(),
       'top control reopens install guidance after dismissal');
 await alice.locator('#installOfferModal [data-close-modal]').click();
 check((await alice.locator('#prefFontFaceSelect option').count())>=10,'ten bundled Persian fonts selectable');
 check((await alice.locator('svg.ui-symbols symbol').count())>=35 &&
       (await alice.locator('#menuThemeIco .ui-icon use').count())===1,'locally packaged icons render as SVG symbols');
 const early=await api(alice,'send_message',{chat_id:creds.dm,text:'مرورگر: پیش از باز شدن خوانده نشود'});
 const seq=early.message.seq;
 const before=await api(bob,'bootstrap',{},'GET');
 check(before.dialogs.find(d=>d.id===creds.dm)?.unread_count>0,
       'login and background sync do not mark unread DM as read');
 await bob.locator(`#dialogList [data-chat-id="${creds.dm}"]`).click();
 await bob.locator('#messagesList .msg-text').filter({hasText:'مرورگر: پیش از باز شدن خوانده نشود'}).waitFor({state:'visible'});
 await bob.waitForFunction(async(chat)=>{const r=await fetch('api.php?action=bootstrap',{headers:{'X-Auth-Token':localStorage.getItem('hamrah_token')}});const j=await r.json();return j.dialogs.find(d=>d.id===chat)?.unread_count===0},creds.dm);
 check((await api(bob,'bootstrap',{},'GET')).dialogs.find(d=>d.id===creds.dm)?.unread_count===0,
       'opened and rendered chat explicitly acknowledges reads');
 const baseSize=Number.parseFloat(await bob.locator('#messagesList .msg-text').last().evaluate(el=>getComputedStyle(el).fontSize));
 await settings(bob);
 await bob.locator('#prefFontSizeSelect').selectOption('large');
 const enlarged=Number.parseFloat(await bob.locator('.font-size-preview').evaluate(el=>getComputedStyle(el).fontSize));
 await bob.locator('#prefFontFaceSelect').selectOption('mikhak');
 check((await bob.locator('body').evaluate(el=>getComputedStyle(el).fontFamily)).includes('Mikhak'),
       'bundled Mikhak web font applied without external stylesheet');
 await bob.locator('#prefEnterSendCheck').uncheck();
 await closeSettings(bob);
 const currentSize=Number.parseFloat(await bob.locator('#messagesList .msg-text').last().evaluate(el=>getComputedStyle(el).fontSize));
 check(currentSize>baseSize && enlarged===currentSize, 'message text really grows with selected font size');
 await bob.locator('#messageInput').fill('Enter off');await bob.locator('#messageInput').press('Enter');
 check((await bob.locator('#messageInput').inputValue()).includes('Enter off\n'), 'unchecked Enter adds a newline');
 await bob.locator('#voiceRecordBtn').click();
 await bob.locator('#messagesList .msg-text').filter({hasText:'Enter off'}).waitFor({state:'visible'});
 await settings(bob);await bob.locator('#prefEnterSendCheck').check();await closeSettings(bob);
 await bob.locator('#messageInput').fill('Enter on');await bob.locator('#messageInput').press('Enter');
 await bob.locator('#messagesList .msg-text').filter({hasText:'Enter on'}).waitFor({state:'visible'});
 check(await bob.locator('#messageInput').inputValue()==='', 'checked Enter sends message');
 await bob.locator('#emojiBtn').click();
 await bob.locator('#emojiPopover [data-emoji-tab="stickers"]').click();
 check(await bob.locator('#emojiPopover').isVisible() &&
       (await bob.locator('#emojiPopover [data-emoji-tab="stickers"]').getAttribute('class')).includes('active'),
       'switch to stickers remains open');
 await bob.locator('#emojiPopover [data-emoji-tab="emoji"]').click();
 check(await bob.locator('#emojiPopover').isVisible() &&
       await bob.locator('#emojiPopover .emoji-cell').count()>20, 'switch back to emojis remains open');
 await bob.locator('#emojiBtn').click();
 check((await bob.locator('#cameraFileInput').getAttribute('capture'))==='environment',
       'camera input requests phone rear camera');
 await bob.locator('#attachBtn').click();
 await bob.locator('#shareLocationBtn').click();
 await bob.locator('#messagesList .msg-text').filter({hasText:'www.openstreetmap.org/?mlat=35.689200'}).last().waitFor({state:'visible'});
 check((await bob.locator('#messagesList .msg-text').last().innerText()).includes('mlon=51.389000'),
       'consent-based location is sent as a plain map link, not hidden tracking');
 await bob.locator('#folderTabs [data-filter="groups"]').click();
 check(await bob.locator('#roomDiscoveryBtn').isVisible(),'groups-and-channels tab offers public name search');
 await bob.locator('#roomDiscoveryBtn').click();
 await bob.locator('#publicRoomNameInput').fill('نام‌یاب');
 await bob.locator('#searchPublicRoomsForm button[type="submit"]').click();
 await bob.locator('#publicRoomSearchResults [data-join-public]').waitFor({state:'visible'});
 check((await bob.locator('#publicRoomSearchResults').innerText()).includes('گروه نام‌یاب') &&
       !(await bob.locator('#publicRoomSearchResults').innerText()).includes('مخفی'),
       'browser shows only public rooms from name search');
 await bob.locator('#publicRoomSearchResults [data-join-public]').click();
 await bob.locator('#findRoomModal').waitFor({state:'hidden'});
 check((await api(bob,'bootstrap',{},'GET')).dialogs.some(d=>d.id===creds.group),
       'search result join button actually joins public group');
 await api(alice,'send_message',{chat_id:creds.group,text:'نام فرستندهٔ گروه تست'});
 await bob.locator(`#dialogList [data-chat-id="${creds.group}"]`).click();
 const groupMsg=bob.locator('#messagesList .msg-row').filter({hasText:'نام فرستندهٔ گروه تست'});
 await groupMsg.waitFor({state:'visible'});
 check((await groupMsg.locator('.msg-sender-name').innerText()).trim()==='Alice',
       'group message shows actual author');
 await api(alice,'send_message',{chat_id:creds.channel,text:'نام کانال تست'});
 await bob.locator(`#dialogList [data-chat-id="${creds.channel}"]`).click();
 const channelMsg=bob.locator('#messagesList .msg-row').filter({hasText:'نام کانال تست'});
 await channelMsg.waitFor({state:'visible'});
 check((await channelMsg.locator('.msg-sender-name').innerText()).trim()==='کانال تست',
       'channel message displays channel title instead of individual admin');
 // A deliberately delayed bootstrap makes the refresh's intermediate frame observable.
 await bob.route('**/api.php?action=bootstrap*',async route=>{await wait(900);await route.continue();});
 await bob.reload({waitUntil:'domcontentloaded'});
 check(await bob.locator('#bootScreen').isVisible() && await bob.locator('#authScreen').isHidden(),
       'authenticated refresh has branded boot screen, never flashes login form');
 await bob.locator('#appScreen').waitFor({state:'visible'});await bob.unroute('**/api.php?action=bootstrap*');
 check(await bob.locator('#authScreen').isHidden() && await bob.locator('#installOfferModal').isHidden(),
       'refresh resumes app without repeating dismissed install offer');
 await bob.locator(`#dialogList [data-chat-id="${creds.dm}"]`).click();
 await settings(bob);
 check(await bob.locator('#prefEnterSendCheck').isChecked(), 'per-account Enter preference survives reload');
 await closeSettings(bob);
 for(const width of [320,390,400,640,920]){
  await bob.setViewportSize({width,height:780});
  // Select chat again after moving into the mobile sidebar layout.
  if(width<=640 && !await bob.locator('#messageInput').isVisible()){
   await bob.locator(`#dialogList [data-chat-id="${creds.dm}"]`).click();
  }
  const v=await bob.evaluate(()=>{const mi=document.getElementById('voiceRecordBtn').getBoundingClientRect(),
   input=document.getElementById('messageInput').getBoundingClientRect();
   return {doc:document.documentElement.scrollWidth,width:innerWidth,edge:input.left-mi.right,
    micW:mi.width,inputW:input.width,micY:mi.y,inputY:input.y}});
  check(v.doc<=v.width+2 && v.inputW>=75 && Math.abs(v.edge)<=1.5,
        `mobile composer has no dead sliver or horizontal overflow at ${width}px`,JSON.stringify(v));
 }
 await alice.close();await wait(300);
 const info=JSON.parse(fs.readFileSync(fixture+'/data/presence.json','utf8'))['alice'];
 check(/^[a-f0-9]{32}$/.test(aliceTabId) && !!info.closed_tabs[aliceTabId],
       'closing browser page submits authenticated presence_close for its tab');
 check(!errors.length,'no uncaught JS errors',errors.join('\n'));
 console.log(`PASS: ${n} current-release browser assertions (install, read, font/Enter, location, discovery, channels, refresh, 320–920px and close)`);
 } finally{await browser.close();}
}
main().catch(e=>{console.error(e);process.exit(1)});
