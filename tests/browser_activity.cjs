'use strict';
/* Primary-admin dashboard in a real browser; run only after account_activity.py
 * against a disposable /home/user/.cache/hamrah-test-site fixture. */
const assert = require('node:assert/strict');
const fs = require('node:fs');
const path = require('node:path');
const { chromium } = require('playwright-core');

const site = process.env.HAMRAH_TEST_URL || 'http://127.0.0.1:8788';
const fixture = path.resolve(process.env.HAMRAH_TEST_FIXTURE || '/home/user/.cache/hamrah-test-site');
assert.ok(/^http:\/\/127\.0\.0\.1:\d+$/.test(site) &&
  fixture.endsWith('/.cache/hamrah-test-site') &&
  fs.existsSync(path.join(fixture, '.browser-activity-credentials.json')),
  'Refusing to run against a real site');
const creds = JSON.parse(fs.readFileSync(path.join(fixture, '.browser-activity-credentials.json'), 'utf8'));
let checks = 0;
function check(value, label, detail = '') {
  checks++;
  assert.ok(value, `${label} ${detail}`);
  if (checks % 10 === 0) console.log(`OK ${checks}: ${label}`);
}
async function login(page, username, password) {
  await page.goto(site + '/index.php', { waitUntil: 'domcontentloaded' });
  await page.locator('#authScreen').waitFor({ state: 'visible' });
  await page.locator('#loginUsername').fill(username);
  await page.locator('#loginPassword').fill(password);
  await page.locator('#loginSubmitBtn').click();
  await page.locator('#appScreen').waitFor({ state: 'visible', timeout: 15000 });
  if (await page.locator('#installOfferModal').isVisible()) await page.locator('#installOfferLaterBtn').click();
}
async function report(page) {
  const token = await page.evaluate(() => localStorage.getItem('hamrah_token'));
  const response = await page.request.get(site + '/api.php?action=admin_account_activity',
    { headers: { 'X-Auth-Token': token } });
  return { response, body: await response.json() };
}
async function main() {
  const browser = await chromium.launch({ executablePath: '/usr/bin/chromium',
    headless: true, args: ['--no-sandbox', '--disable-dev-shm-usage'] });
  try {
    const context = await browser.newContext({ viewport: { width: 1280, height: 850 } });
    const page = await context.newPage();
    page.setDefaultTimeout(12000);
    const errors = [];
    page.on('pageerror', error => errors.push(error.message));
    page.on('dialog', dialog => { errors.push(`unexpected dialog: ${dialog.message()}`); dialog.dismiss(); });
    await login(page, 'chief', creds.chief);
    await page.locator('#menuBtn').click();
    await page.locator('#menuAdminBtn').click();
    await page.locator('#adminUsersTableBody tr').first().waitFor({ state: 'visible' });
    check(await page.locator('#adminModal [data-atab="activity"]').isVisible(),
      'usage report tab visible to first setup admin');
    await page.locator('#adminModal [data-atab="activity"]').click();
    await page.locator('#activityUsersList [data-activity-user="old_id"]').waitFor({ state: 'visible' });
    const summary = (await report(page)).body.report;
    check(summary.users.length >= 7 && summary.summary.never_signed_in >= 2,
      'dashboard reflects all accounts and never-signed-in summary');
    check(await page.locator('#activityKpis .activity-kpi').count() === 6,
      'six overview counters render');
    check((await page.locator('[data-activity-user="old_id"]').innerText()).includes('هنوز وارد نشده'),
      'never-used account rendered with clear Persian label');
    check(await page.locator('[data-activity-user="renamed"]').getAttribute('data-activity-status') === 'used' &&
      (await page.locator('[data-activity-user="renamed"]').innerText()).includes('بدون حضور'),
      'offline account shows inactivity after rename');
    check(await page.locator('[data-activity-user="legacy"]').getAttribute('data-activity-status') !== 'never_signed_in',
      'legacy account never falsely labelled unused');
    const created = summary.users.find(u => u.username === 'old_id').created_at;
    const expected = await page.evaluate(ts => new Intl.DateTimeFormat('fa-IR-u-ca-persian', {
      timeZone: 'Asia/Tehran', year: 'numeric', month: 'long', day: 'numeric',
      hour: '2-digit', minute: '2-digit', hour12: false,
    }).format(new Date(ts * 1000)), created);
    check((await page.locator('[data-activity-user="old_id"]').innerText()).includes(expected),
      'full Persian date and minute shown in Tehran time, not browser time');
    check((await page.locator('#activityUpdatedAt').innerText()).includes('ساعت ایران'),
      'report explicitly shows refresh time and time zone');

    await page.locator('#activityFilterSelect').selectOption('inactive_30d');
    check(await page.locator('#activityUsersList .activity-card').count() === 1 &&
      await page.locator('[data-activity-user="renamed"]').isVisible(),
      '30-day filter shows genuinely absent account but excludes never-signed-in account');
    await page.locator('#activityFilterSelect').selectOption('never_signed_in');
    check(await page.locator('[data-activity-user="old_id"]').isVisible() &&
      await page.locator('[data-activity-user="renamed"]').count() === 0,
      'never-signed-in filter distinguishes unclaimed account from inactive used account');
    await page.locator('#activitySearchInput').fill('raw_new');
    check(await page.locator('#activityUsersList .activity-card').count() === 1 &&
      await page.locator('[data-activity-user="raw_new"]').isVisible(),
      'search filters names/IDs inside selected status');
    await page.locator('#activitySearchInput').fill('');
    await page.locator('#activityFilterSelect').selectOption('all');

    // Sign the previously unused account in from a second browser and refresh.
    const visitorContext = await browser.newContext({ viewport: { width: 390, height: 760 } });
    const visitor = await visitorContext.newPage();
    await login(visitor, creds.never_id, creds.never_password);
    await page.locator('#activityRefreshBtn').click();
    await page.waitForFunction(() => document.querySelector(
      '[data-activity-user="old_id"]')?.dataset.activityStatus === 'online');
    check(await page.locator('[data-activity-user="old_id"]').getAttribute('data-activity-status') === 'online' &&
      (await page.locator('[data-activity-user="old_id"]').innerText()).includes('آخرین ورود موفق'),
      'manual refresh tracks newly authenticated and online account');
    check(await visitor.locator('#menuAdminBtn').isHidden(),
      'ordinary user cannot open administration panel');
    await visitor.close();
    await page.locator('#activityRefreshBtn').click();
    await page.waitForFunction(() => document.querySelector(
      '[data-activity-user="old_id"]')?.dataset.activityStatus === 'used');
    check(await page.locator('[data-activity-user="old_id"]').getAttribute('data-activity-status') === 'used',
      'closed last browser tab transitions promptly to offline without losing usage history');
    await visitorContext.close();

    // Malicious display name must remain escaped even in the audit cards.
    const token = await page.evaluate(() => localStorage.getItem('hamrah_token'));
    const attackName = '<img src=x onerror=alert(1)>';
    const creation = await page.request.post(site + '/api.php?action=admin_save_user', {
      headers: { 'X-Auth-Token': token }, data: {
        is_new: true, username: 'xss_test', display_name: attackName,
        password: 'test-' + Math.random().toString(36).slice(2) + '-Strong!', active: true,
      },
    });
    check(creation.ok() && (await creation.json()).ok, 'fixture account with HTML name created');
    await page.locator('#activityRefreshBtn').click();
    await page.locator('[data-activity-user="xss_test"]').waitFor({ state: 'visible' });
    check(await page.locator('[data-activity-user="xss_test"] img').count() === 0 &&
      (await page.locator('[data-activity-user="xss_test"]').innerText()).includes(attackName),
      'untrusted account display name is escaped in admin report');

    for (const width of [1280, 768, 390, 320]) {
      await page.setViewportSize({ width, height: 760 });
      const widthInfo = await page.evaluate(() => ({
        viewport: innerWidth, doc: document.documentElement.scrollWidth,
        card: document.querySelector('.activity-card').getBoundingClientRect().width,
        modal: document.querySelector('#adminModal .modal-window').getBoundingClientRect().width,
      }));
      check(widthInfo.doc <= width + 2 && widthInfo.card > 185 && widthInfo.card <= widthInfo.modal,
        `usage dashboard fits ${width}px without horizontal page overflow`, JSON.stringify(widthInfo));
    }
    await page.locator('#adminModal [data-close-modal]').click();
    await page.locator('#menuBtn').click();
    await page.locator('#menuLogoutBtn').click();
    await page.locator('#authScreen').waitFor({ state: 'visible' });
    check(await page.locator('#activityUsersList').innerHTML() === '' &&
      await page.locator('#adminModal [data-atab="activity"]').isHidden(),
      'logout clears sensitive report DOM and hides primary-only tab');
    await page.setViewportSize({ width: 1280, height: 850 });
    await login(page, 'helper', creds.helper);
    await page.locator('#menuBtn').click();
    await page.locator('#menuAdminBtn').click();
    check(await page.locator('#adminModal [data-atab="activity"]').isHidden() &&
      await page.locator('#activityUsersList').innerHTML() === '',
      'secondary admin cannot see or retain prior primary-admin report');
    const forbidden = await report(page);
    check(forbidden.response.status() === 403 && !forbidden.body.ok,
      'secondary admin cannot bypass UI to read private report API');
    check(errors.length === 0, 'no JavaScript errors or injected alert dialogs', errors.join('; '));
    console.log(`PASS: ${checks} real-browser account-activity assertions (access, dates, filters, update, XSS, 320px)`);
  } finally { await browser.close(); }
}
main().catch(error => { console.error(error); process.exitCode = 1; });
