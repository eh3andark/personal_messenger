#!/usr/bin/env python3
"""Primary-admin account-usage/privacy regressions. DESTRUCTIVE DISPOSABLE FIXTURE ONLY.

Run against a COPY at /home/user/.cache/hamrah-test-site, never real user data.
Sets up its own random accounts and leaves browser credentials for browser_activity.cjs.
"""
import json
import re
import secrets
import shutil
import sys
import time
from pathlib import Path

import requests
import integration
from integration import BASE, SITE, DATA, Client, check

assert '.cache/hamrah-test-site' in str(SITE) and (SITE / 'api.php').is_file()
PASSWORDS = {name: secrets.token_urlsafe(26) for name in
             ('chief', 'helper', 'never', 'used', 'old_id', 'legacy', 'raw_new')}
LEDGER = DATA / 'user_activity.json'


def report(client):
    result = client.api('admin_account_activity', get=True)[1]['report']
    return result, {row['username']: row for row in result['users']}


def new_user(admin, username, *, role='user'):
    return admin.api('admin_save_user', {
        'is_new': True, 'username': username, 'display_name': username.title(),
        'password': PASSWORDS[username], 'role': role, 'active': True,
    })[1]['user']


def sign_in(client, username, *, tab='1'*32):
    answer = client.api('login', {
        'username': username, 'password': PASSWORDS[username], 'tab_id': tab,
    })[1]
    client.token, client.ticket = answer['token'], answer['media_ticket']
    return answer


def main():
    # Recreate ONLY the explicitly isolated fixture's data directory.
    for item in DATA.iterdir():
        if item.is_dir(): shutil.rmtree(item)
        elif item.name not in ('.htaccess', 'index.php'): item.unlink()
    (DATA / 'users.json').write_text('[]\n')
    session = requests.Session()
    check(session.get(BASE + '/setup.php', timeout=10).status_code == 200, 'disposable setup opens')
    token = re.search(r"return '([a-f0-9]{64})'", (DATA / '.setup_token.php').read_text()).group(1)
    response = session.post(BASE + '/setup.php', data={
        'setup_token': token, 'username': 'chief', 'display_name': 'مدیر اصلی',
        'password': PASSWORDS['chief'], 'password_confirm': PASSWORDS['chief'],
    }, allow_redirects=False, timeout=10)
    check(response.status_code == 303, 'main admin setup succeeds', response.status_code)
    check(session.get(BASE + '/data/user_activity.json', timeout=10).status_code in (403, 404),
          'private activity ledger cannot be downloaded')
    check('last_login_at' not in (DATA / 'users.json').read_text() and
          json.loads(LEDGER.read_text())['chief']['created_at'] > 0,
          'setup creation timestamp stored privately outside users.json')

    chief, helper, never, used, legacy = (Client() for _ in range(5))
    sign_in(chief, 'chief', tab='a'*32)
    for username in ('helper', 'never', 'used', 'old_id', 'legacy'):
        new_user(chief, username, role='admin' if username == 'helper' else 'user')
    check(session.get(BASE + '/api.php?action=admin_account_activity', timeout=10).status_code == 401,
          'anonymous account report denied')
    sign_in(helper, 'helper', tab='b'*32)
    code, secondary = helper.api('admin_account_activity', get=True, expect_ok=False)
    check(code == 403 and not secondary['ok'], 'secondary administrator denied the report', secondary)
    raw_overview = helper.api('admin_overview', get=True)[1]
    check('last_login_at' not in raw_overview['raw_users_json'] and
          all('last_login_at' not in u and 'created_at' not in u for u in raw_overview['users']),
          'secondary-admin overview and raw users.json do not leak activity timestamps')
    check(never.api('admin_account_activity', get=True, expect_ok=False)[0] == 401,
          'unsigned ordinary user cannot request report')
    sign_in(never, 'never', tab='c'*32)
    check(never.api('admin_account_activity', get=True, expect_ok=False)[0] == 403,
          'signed-in ordinary user cannot request report')

    # The account called never was used just above for the ACL test. Use old_id
    # as the never-signed-in account and assert password edits do not count.
    chief.api('admin_save_user', {'is_new': False, 'username': 'old_id',
                                  'password': secrets.token_urlsafe(26), 'display_name': 'Unused'})
    start, rows = report(chief)
    check(rows['old_id']['status'] == 'never_signed_in' and rows['old_id']['last_login_at'] == 0 and
          rows['old_id']['last_seen_at'] == 0 and rows['old_id']['days_offline'] is None,
          'new unused account is not mistaken for an online or used account', rows['old_id'])
    check(abs(rows['old_id']['created_at'] - time.time()) < 12 and
          start['summary']['never_signed_in'] >= 2,
          'creation date is a real server timestamp and unused accounts counted')
    check(rows['chief']['status'] == 'online' and rows['helper']['status'] == 'online' and
          start['summary']['total'] == 6,
          'primary and secondary admin included in full account report')
    check('password' not in json.dumps(start) and 'media_ticket' not in json.dumps(start),
          'report does not expose passwords or session secrets')

    used_tab = 'd'*32
    sign_in(used, 'used', tab=used_tab)
    _, rows = report(chief)
    check(rows['used']['status'] == 'online' and rows['used']['online'] and
          rows['used']['last_login_at'] >= rows['used']['created_at'] and
          rows['used']['last_seen_at'] >= rows['used']['last_login_at'],
          'successful sign-in records login and live heartbeat with exact timestamps', rows['used'])
    used.api('presence_close', {'tab_id': used_tab})
    _, rows = report(chief)
    check(rows['used']['status'] == 'used' and not rows['used']['online'] and
          rows['used']['days_offline'] == 0 and rows['used']['last_activity_at'] > 0,
          'tab close preserves last activity and switches user offline promptly', rows['used'])
    # A late background heartbeat for a closed tab cannot falsify the report.
    used.api('sync', {'tab_id': used_tab})
    check(not report(chief)[1]['used']['online'], 'closed tab cannot resurrect account online status')

    # Deterministic 31-day inactivity without sleeping 31 days.
    history = json.loads(LEDGER.read_text())
    history['used']['last_login_at'] = int(time.time()) - 32 * 86400
    history['old_id']['created_at'] = int(time.time()) - 40 * 86400
    LEDGER.write_text(json.dumps(history))
    presence = json.loads((DATA / 'presence.json').read_text())
    presence['used']['last_seen'] = int(time.time()) - 31 * 86400 - 60
    presence['used']['tabs'] = {}
    (DATA / 'presence.json').write_text(json.dumps(presence))
    aged, rows = report(chief)
    check(rows['used']['days_offline'] == 31 and rows['used']['status'] == 'used' and
          aged['summary']['inactive_7d'] >= 1 and aged['summary']['inactive_30d'] >= 1,
          '31 complete offline days reported and counted in 7/30-day buckets', rows['used'])
    check(rows['old_id']['days_since_creation'] >= 40 and rows['old_id']['status'] == 'never_signed_in' and
          aged['summary']['never_signed_in'] >= 1,
          'never-signed-in account ages from creation, not from fabricated last-seen time')

    # Rename preserves activity and sessions/presence; deletion and reuse cannot inherit it.
    chief.api('admin_save_user', {'is_new': False, 'original_username': 'used',
                                  'username': 'renamed', 'display_name': 'Renamed'})
    _, renamed = report(chief)
    check('used' not in renamed and renamed['renamed']['created_at'] == rows['used']['created_at'] and
          renamed['renamed']['days_offline'] == 31,
          'rename keeps creation and activity metrics under the new ID', renamed['renamed'])
    check('used' not in json.loads(LEDGER.read_text()), 'rename removes old ID from private audit ledger')
    chief.api('admin_reset_user_data', {'username': 'renamed', 'confirmation': 'renamed'})
    _, after_reset = report(chief)
    check(after_reset['renamed']['created_at'] == renamed['renamed']['created_at'] and
          after_reset['renamed']['last_login_at'] == renamed['renamed']['last_login_at'],
          'clearing account content does not fabricate a new registration or erase true login history')

    chief.api('admin_delete_user', {'username': 'old_id'})
    check('old_id' not in json.loads(LEDGER.read_text()) and 'old_id' not in report(chief)[1],
          'deleting an account removes its private audit entry')
    new_user(chief, 'old_id')
    _, reused = report(chief)
    check(reused['old_id']['status'] == 'never_signed_in' and
          abs(reused['old_id']['created_at'] - time.time()) < 12,
          'safely recycled ID receives a fresh creation date instead of previous owner history')

    # A raw-JSON admin creation also gets a server-generated timestamp.
    raw = json.loads(chief.api('admin_overview', get=True)[1]['raw_users_json'])
    raw.append({'username': 'raw_new', 'password': PASSWORDS['raw_new'], 'role': 'user',
                'display_name': 'Raw New', 'active': True,
                'created_at': 123, 'last_login_at': 456})  # forged fields are not audit source
    chief.api('admin_save_raw_users_json', {'raw_json': json.dumps(raw)})
    _, imported = report(chief)
    check(imported['raw_new']['status'] == 'never_signed_in' and
          imported['raw_new']['last_login_at'] == 0 and
          abs(imported['raw_new']['created_at'] - time.time()) < 12,
          'raw JSON editor creates genuine timestamp without trusting client-submitted fields')
    raw.append({'username': 'raw_temp', 'password': secrets.token_urlsafe(20), 'role': 'user'})
    chief.api('admin_save_raw_users_json', {'raw_json': json.dumps(raw)})
    check('raw_temp' in json.loads(LEDGER.read_text()), 'raw editor tracks its newly added account')
    chief.api('admin_save_raw_users_json', {'raw_json': json.dumps(raw[:-1])})
    check('raw_temp' not in json.loads(LEDGER.read_text()) and 'raw_temp' not in report(chief)[1],
          'raw editor removal cleans its private activity record')

    # A legacy account with no audit history must NOT be labelled never-used.
    history = json.loads(LEDGER.read_text())
    del history['legacy']
    LEDGER.write_text(json.dumps(history))
    _, legacy_rows = report(chief)
    check(legacy_rows['legacy']['status'] == 'unknown' and legacy_rows['legacy']['created_at'] == 0 and
          legacy_rows['legacy']['days_since_creation'] is None and legacy_rows['legacy']['days_offline'] is None,
          'pre-upgrade account with no evidence is unknown, not falsely unused', legacy_rows['legacy'])
    sign_in(legacy, 'legacy', tab='e'*32)
    _, legacy_rows = report(chief)
    check(legacy_rows['legacy']['status'] == 'online' and legacy_rows['legacy']['created_at'] == 0 and
          legacy_rows['legacy']['last_login_at'] > 0,
          'legacy account records future login without inventing its creation date')

    # Corrupt audit JSON must not silently reset and claim everybody never used.
    backup = LEDGER.read_bytes()
    LEDGER.write_text('{broken-json')
    code, failure = chief.api('admin_account_activity', get=True, expect_ok=False)
    check(code == 500 and not failure.get('ok') and LEDGER.read_text() == '{broken-json',
          'corrupt audit ledger fails closed without erasing metadata')
    LEDGER.write_bytes(backup)

    (SITE / '.browser-activity-credentials.json').write_text(json.dumps({
        'chief': PASSWORDS['chief'], 'helper': PASSWORDS['helper'],
        'never_id': 'old_id', 'never_password': PASSWORDS['old_id'],
        'used_id': 'renamed', 'used_password': PASSWORDS['used'], 'legacy_id': 'legacy',
    }))
    print(f'PASS: {integration.count} dedicated account-activity assertions on disposable site', flush=True)


if __name__ == '__main__':
    main()
