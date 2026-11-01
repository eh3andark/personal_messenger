#!/usr/bin/env python3
"""Isolated HTTP integration smoke/stress test; never run against a real site.

Run a local PHP server with the app copied into /home/user/.cache/hamrah-test-site,
then: python3 tests/integration.py http://127.0.0.1:8788 /home/user/.cache/hamrah-test-site
The test recreates the fixture's data directory. Requests requires: pip install requests.
"""
import base64
from concurrent.futures import ThreadPoolExecutor
import hashlib
import json
import os
from pathlib import Path
import re
import secrets
import sys
import time
from urllib.parse import urlsplit

import requests

BASE = sys.argv[1].rstrip('/') if len(sys.argv) > 1 else 'http://127.0.0.1:8788'
parsed_url = urlsplit(BASE)
assert parsed_url.scheme == 'http' and parsed_url.hostname == '127.0.0.1' and parsed_url.port and parsed_url.path in ('', '/'), 'Refusing to test a remote/live URL'
SITE = Path(sys.argv[2] if len(sys.argv) > 2 else '/home/user/.cache/hamrah-test-site').resolve()
assert '.cache/hamrah-test-site' in str(SITE) and (SITE / 'api.php').is_file(), 'Refusing to touch an actual installation'
DATA = SITE / 'data'
PASSWORDS = {name: secrets.token_urlsafe(24) for name in ['chief','alice','bob','ghost','editoronly']}
STRESS_COUNT = max(172, min(3000, int(os.environ.get('HAMRAH_STRESS_MESSAGES', '172'))))
count = 0


def check(condition, label, detail=None):
    global count
    count += 1
    if not condition:
        raise AssertionError(f'#{count}: {label}; response={str(detail)[:900]}')
    if count % 20 == 0:
        print(f'OK {count}: {label}', flush=True)


class Client:
    def __init__(self):
        self.session = requests.Session()
        self.token = ''
        self.ticket = ''

    def api(self, action, data=None, *, get=False, files=None, expect_ok=True):
        headers = {'X-Auth-Token': self.token} if self.token else {}
        kw = dict(headers=headers, timeout=35)
        if get:
            response = self.session.get(BASE + '/api.php', params={'action': action, **(data or {})}, **kw)
        elif files is not None:
            response = self.session.post(BASE + '/api.php?action=' + action, data=data, files=files, **kw)
        else:
            response = self.session.post(BASE + '/api.php?action=' + action, json=data or {}, **kw)
        try:
            payload = response.json()
        except Exception as exc:
            raise AssertionError(f'{action}: bad JSON HTTP {response.status_code}: {response.text[:700]}') from exc
        if expect_ok:
            check(response.status_code == 200 and payload.get('ok') is True, f'API {action}', payload)
        return response.status_code, payload

    def login(self, username, password):
        _, ans = self.api('login', {'username': username, 'password': password})
        self.token = ans['token']
        self.ticket = ans['media_ticket']
        return ans

    def media(self, media_id, range_header=None, *, ticket=False):
        headers = {'Range': range_header} if range_header else {}
        if not ticket:
            headers['X-Auth-Token'] = self.token
        return self.session.get(BASE + '/api.php', params={'action': 'media', 'id': media_id, **({'media_ticket': self.ticket} if ticket else {})}, headers=headers, timeout=30)


def dialogs(client):
    return client.api('bootstrap', get=True)[1]['dialogs']


def records(client, chat, limit=50, before_seq=None):
    return client.api('messages', {'chat_id': chat, 'limit': limit, **({'before_seq': before_seq} if before_seq is not None else {})}, get=True)[1]


def upload(client, filename, mime, blob, *, upload_id=None):
    upload_id = upload_id or 'test_' + secrets.token_hex(7)
    n = (len(blob) + 1048575) // 1048576
    response = None
    for i in range(n):
        part = blob[i * 1048576:(i + 1) * 1048576]
        response = client.api('upload_chunk', data={'upload_id': upload_id, 'chunk_index': str(i),
            'total_chunks': str(n), 'total_size': str(len(blob)), 'original_name': filename,
            'mime_type': mime}, files={'chunk': (filename, part, mime)})[1]
    check(response['complete'] is True, 'chunk upload assembled', response)
    return response['media']


def main():
    for item in DATA.iterdir():
        if item.is_dir():
            import shutil
            shutil.rmtree(item)
        elif item.name not in ('.htaccess', 'index.php'):
            item.unlink()
    (DATA / 'users.json').write_text('[]\n')
    h = requests.Session()
    page = h.get(BASE + '/setup.php', timeout=10)
    check(page.status_code == 200 and 'راه‌اندازی' in page.text, 'first-run setup page')
    token_data = (DATA / '.setup_token.php').read_text()
    token = re.search(r"return '([a-f0-9]{64})'", token_data).group(1)
    check(bool(token), 'random protected setup token generated')
    for path in ['/data/users.json', '/data/.setup_token.php', '/core/bootstrap.php', '/.git/config', '/README-FA.md']:
        r = h.get(BASE + path, timeout=10)
        check(r.status_code in (403, 404), 'HTTP denies ' + path, r.status_code)
    install = {'setup_token': '0' * 64, 'username': 'chief', 'display_name': 'مدیر تست', 'password': PASSWORDS['chief'], 'password_confirm': PASSWORDS['chief']}
    bad = h.post(BASE + '/setup.php', data=install, timeout=10)
    check('کد نصب صحیح نیست' in bad.text and json.loads((DATA / 'users.json').read_text()) == [], 'bad install token rejected')
    install['setup_token'] = token
    good = h.post(BASE + '/setup.php', data=install, allow_redirects=False, timeout=10)
    users = json.loads((DATA / 'users.json').read_text())
    check(good.status_code == 303 and good.headers['Location'] == 'index.php', 'first admin created via form', good.status_code)
    check(users[0]['username'] == 'chief' and users[0]['password'].startswith('$2y$') and install['password'] not in str(users), 'admin password Bcrypt hashed')
    check(h.get(BASE + '/setup.php', timeout=10).status_code == 404, 'setup locked after installation')
    check(not (DATA / '.setup_token.php').exists(), 'setup token removed')
    check(PASSWORDS['chief'] not in h.get(BASE + '/index.php').text, 'login page no example credentials')

    chief, alice, bob, ghost = [Client() for _ in range(4)]
    chief.login('chief', install['password'])
    for name in ['alice', 'bob', 'ghost']:
        chief.api('admin_save_user', {'is_new': True, 'username': name, 'password': PASSWORDS[name], 'display_name': name.title(), 'role': 'user', 'quota_mb': 64, 'active': True})
    alice.login('alice', PASSWORDS['alice'])
    bob.login('bob', PASSWORDS['bob'])
    ghost.login('ghost', PASSWORDS['ghost'])
    aboot = alice.api('bootstrap', get=True)[1]
    check(set(aboot['users']) == {'alice', 'chief'}, 'ordinary user sees only self and permanent setup admin on first login', aboot['users'])
    check(len(chief.api('bootstrap', get=True)[1]['users']) >= 4, 'admin sees accounts')
    check(any(x.get('command') == '/telegram' for x in aboot['config']['web_tools']), 'public Telegram tool enabled without API key')
    _, lookup = alice.api('find_user_by_id', {'id': 'bob'})
    dm = lookup['chat_id']
    check(dm == 'dm_alice__bob', 'exact ID contact created')
    check('ghost' not in lookup['users'], 'contact lookup not public list')
    _, first = alice.api('send_message', {'chat_id': dm, 'text': 'hello-first-message'})
    seq_first = first['message']['seq']
    check(any(x['id'] == dm for x in dialogs(bob)), 'recipient discovers incoming conversation')
    check(all(x['id'] != dm for x in dialogs(ghost)), 'third party does not discover conversation')
    check(ghost.api('messages', {'chat_id': dm}, get=True, expect_ok=False)[0] == 403, 'third-party private chat rejected')
    check(bob.api('delete_message', {'chat_id': dm, 'seq': seq_first, 'scope': 'me'})[1]['scope'] == 'me', 'recipient hides message locally')
    check(not records(bob, dm)['messages'], 'hidden message excluded for recipient')
    check(records(alice, dm)['messages'][0]['text'] == 'hello-first-message', 'local deletion does not affect sender')
    check('hidden_for' not in records(alice, dm)['messages'][0], 'hidden-by metadata not exposed to peer')
    check(bob.api('edit_message', {'chat_id': dm, 'seq': seq_first, 'text': 'hack', }, expect_ok=False)[0] >= 400, 'recipient cannot edit peer message')
    alice.api('edit_message', {'chat_id': dm, 'seq': seq_first, 'text': 'updated-first-message'})
    check(not records(bob, dm)['messages'], 'editing does not undo local hide')
    bob.api('hide_dialog', {'chat_id': dm})
    check(dm not in [x['id'] for x in dialogs(bob)], 'recipient removes past peer from chats list')
    check(dm in [x['id'] for x in dialogs(alice)], 'hiding does not remove other side chat')
    bob.api('find_user_by_id', {'id': 'alice'})
    check(dm in [x['id'] for x in dialogs(bob)], 'exact ID lookup reopens hidden chat')
    bob.api('remove_contact', {'peer_username': 'alice'})
    check(dm not in [x['id'] for x in dialogs(bob)], 'remove contact hides chat even with past messages')
    alice.api('send_message', {'chat_id': dm, 'text': 'newly delivered'})
    check(dm in [x['id'] for x in dialogs(bob)], 'new incoming message revives recipient chat')
    bob.api('dialog_preference', {'chat_id': dm, 'key': 'muted', 'value': True})
    bob.api('dialog_preference', {'chat_id': dm, 'key': 'pinned', 'value': True})
    bob.api('dialog_preference', {'chat_id': dm, 'key': 'archived', 'value': True})
    d = [x for x in dialogs(bob) if x['id'] == dm][0]
    check(d['muted'] and d['archived'] and d['pinned_rank'] > 0, 'Telegram-like per-dialog preferences')
    check(not [x for x in dialogs(alice) if x['id'] == dm][0].get('archived'), 'preferences private to owner')

    chief.api('admin_save_config', {'max_shard_messages': 10, 'max_shard_bytes': 16384})
    for i in range(STRESS_COUNT):
        alice.api('send_message', {'chat_id': dm, 'text': f'stress item {i:04d} ' + ('deepneedle' if i == 3 else 'filler')})
    def concurrently_send(i):
        response = requests.post(BASE + '/api.php?action=send_message', json={'chat_id': dm, 'text': f'parallel item {i}'},
            headers={'X-Auth-Token': alice.token}, timeout=30)
        return response.status_code, response.json()
    with ThreadPoolExecutor(max_workers=8) as pool:
        simultaneous = list(pool.map(concurrently_send, range(36)))
    sent_seqs = [result['message']['seq'] for status, result in simultaneous if status == 200 and result.get('ok')]
    check(len(sent_seqs) == 36 and len(set(sent_seqs)) == 36, '36 concurrent writes yield distinct seq under file locks', simultaneous[:3])
    parts = sorted((DATA / 'chats' / dm).glob('part_*.json'))
    check(len(parts) >= STRESS_COUNT // 10, f'{STRESS_COUNT} messages auto-roll across many JSON shards', len(parts))
    raw = parts[0].read_text()
    check('hello-first-message' not in raw and 'updated-first-message' not in raw and 'enc:v1:' in raw, 'disk message text AES-GCM encrypted')
    last = records(alice, dm, limit=30)
    check(len(last['messages']) == 30 and last['has_more'], 'latest-page bounded pagination', last.get('shard_info'))
    seen, before, pagen = set(), None, 0
    while True:
        page = records(alice, dm, limit=40, before_seq=before)
        seen.update(m['seq'] for m in page['messages'])
        pagen += 1
        if not page['has_more']:
            break
        before = page['oldest_seq']
        check(pagen < STRESS_COUNT // 35 + 10 and before is not None, 'pagination cursor progresses')
    check(seq_first in seen and len(seen) == STRESS_COUNT + 38, 'older shards read with no missing seq', len(seen))
    before, matches, search_pages = None, [], 0
    while True:
        q = alice.api('search_chat', {'chat_id': dm, 'q': 'deepneedle', **({'before_seq': before} if before else {})}, get=True)[1]
        matches += q['results']
        search_pages += 1
        if not q['has_more']:
            break
        before = q['next_cursor']
        check(before and search_pages < STRESS_COUNT // 100 + 10, 'bounded shard search cursor moves')
    check(search_pages >= 2 and len(matches) == 1 and 'deepneedle' in matches[0]['text'], 'search finds very old shard')
    check(ghost.api('search_chat', {'chat_id': dm, 'q': 'deepneedle'}, get=True, expect_ok=False)[0] == 403, 'search ACL')
    before_sync = records(bob, dm)
    old_rev, old_view = before_sync['rev'], before_sync['view_rev']
    seq_new = alice.api('send_message', {'chat_id': dm, 'text': 'sync target'})[1]['message']['seq']
    ch = bob.api('sync', {'active_chat_id': dm, 'since_rev': old_rev, 'since_view_rev': old_view})[1]['chat_changes']
    check(seq_new in [m['seq'] for m in ch['upserted']] and not ch.get('reset_required'), 'incremental sync sees new messages')
    bob.api('clear_chat_history', {'chat_id': dm, 'scope': 'me'})
    check(records(bob, dm)['messages'] == [], 'clear for me hides all earlier messages')
    check(records(alice, dm)['messages'] != [], 'clear for me retains peer history')
    ch = bob.api('sync', {'active_chat_id': dm, 'since_rev': old_rev, 'since_view_rev': old_view})[1]['chat_changes']
    check(ch['reset_required'] and ch['view_rev'] > old_view, 'per-user clear requests client reset')
    after = alice.api('send_message', {'chat_id': dm, 'text': 'after own clear'})[1]['message']['seq']
    check([m['seq'] for m in records(bob, dm)['messages']] == [after], 'new peer messages visible after per-user clear')
    alice.api('clear_chat_history', {'chat_id': dm, 'scope': 'both'})
    check(records(alice, dm)['messages'] == [] and records(bob, dm)['messages'] == [], 'clear for both physically wipes all shards')
    ch = bob.api('sync', {'active_chat_id': dm, 'since_rev': old_rev, 'since_view_rev': records(bob, dm)['view_rev']})[1]['chat_changes']
    check(ch['reset_required'], 'physical shared clear forces all stale clients to reset')
    newer = alice.api('send_message', {'chat_id': dm, 'text': 'monotonic seq'})[1]['message']['seq']
    check(newer > after and records(bob, dm)['messages'][-1]['seq'] == newer, 'seq monotonic after clear')

    png = base64.b64decode('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mP8/x8AAwMCAO+/Gf0AAAAASUVORK5CYII=')
    photo = upload(alice, 'photo.png', 'image/png', png)
    check(photo['category'] == 'image' and photo['encrypted'] == 'aes-256-gcm-chunks-v1', 'photo category and encrypted metadata', photo)
    photo_id = photo['id']
    image_message = alice.api('send_message', {'chat_id': dm, 'media_id': photo_id, 'text': 'portrait'})[1]['message']
    check(bob.media(photo_id).status_code == 200 and bob.media(photo_id).content == png, 'peer sees decrypted photo only via authorized API')
    check(ghost.media(photo_id).status_code == 403, 'unrelated user cannot download photo')
    alice.api('delete_message', {'chat_id': dm, 'seq': image_message['seq'], 'scope': 'me'})
    check(alice.media(photo_id).status_code == 403 and bob.media(photo_id).status_code == 200, 'delete for me revokes photo owner, not peer')
    check(image_message['seq'] not in [m['seq'] for m in records(alice, dm)['messages']], 'photo message hidden only for owner')
    image_path = next((DATA / 'uploads').glob('*/' + photo_id + '.enc'))
    check(png not in image_path.read_bytes(), 'PNG bytes not stored plaintext')

    big = os.urandom(1048576 + 463)  # varied data > 1 MiB
    med = upload(bob, 'archive.dat', 'application/octet-stream', big)
    binary_id = med['id']
    diskfile = next((DATA / 'uploads').glob('*/' + binary_id + '.enc'))
    check(diskfile.stat().st_size == len(big) + 2 * 28 and big[:80] not in diskfile.read_bytes(), 'encrypted two-block file has precise length and no plaintext')
    check(alice.media(binary_id).status_code == 403, 'unattached encrypted upload not shared')
    blobmsg = bob.api('send_message', {'chat_id': dm, 'media_id': binary_id, 'text': ''})[1]['message']
    for value, expectation in [('bytes=0-18', big[:19]), ('bytes=1048573-1048582', big[1048573:1048583]), ('bytes=-100', big[-100:])]:
        r = alice.media(binary_id, value, ticket=True)
        check(r.status_code == 206 and r.content == expectation and r.headers.get('Content-Range'), f'range decryption {value}', (r.status_code, r.text[:100]))
    r = alice.media(binary_id, 'bytes=999999999-')
    check(r.status_code == 416, 'out-of-bounds range returns 416')
    original = diskfile.read_bytes()
    corrupted = bytearray(original); corrupted[30] ^= 1
    diskfile.write_bytes(corrupted)
    check(bob.media(binary_id).status_code == 500, 'tampered encrypted media first block fails authentication')
    diskfile.write_bytes(original)
    check(alice.media(binary_id, 'bytes=1048576-').content == big[1048576:], 'media restored after tamper test')
    alice.api('delete_message', {'chat_id': dm, 'seq': blobmsg['seq'], 'scope': 'both'})
    check(not diskfile.exists(), 'last message reference deletion purges encrypted binary')

    room = chief.api('create_room', {'title': 'Test room', 'type': 'group', 'members': ['alice', 'bob']})[1]['room']['id']
    rmsg = alice.api('send_message', {'chat_id': room, 'text': 'room-private-original'})[1]['message']
    check(ghost.api('messages', {'chat_id': room}, get=True, expect_ok=False)[0] == 403, 'room ACL denies non-members')
    check(bob.api('clear_chat_history', {'chat_id': room, 'scope': 'both'}, expect_ok=False)[0] == 403, 'member cannot wipe entire room')
    bob.api('delete_message', {'chat_id': room, 'seq': rmsg['seq'], 'scope': 'me'})
    check(rmsg['seq'] not in [m['seq'] for m in records(bob, room)['messages']], 'member can locally delete group message')
    check(rmsg['seq'] in [m['seq'] for m in records(chief, room)['messages']], 'others retain group message')
    check(bob.api('delete_message', {'chat_id': room, 'seq': rmsg['seq'], 'scope': 'both'}, expect_ok=False)[0] == 404, 'locally hidden message cannot globally delete from non-owner')
    alice.api('delete_message', {'chat_id': room, 'seq': rmsg['seq'], 'scope': 'both'})
    check(rmsg['seq'] not in [m['seq'] for m in records(chief, room)['messages']], 'sender may delete own group message for everyone')
    note = alice.api('send_message', {'chat_id': 'saved_alice', 'text': 'private saved note secret-1'})[1]['message']
    raw_saved = (DATA / 'chats' / 'saved_alice' / 'part_0001.json').read_text()
    check('secret-1' not in raw_saved and 'enc:v1:' in raw_saved, 'saved note text encrypted')
    alice.api('clear_chat_history', {'chat_id': 'saved_alice', 'scope': 'me'})
    check(not records(alice, 'saved_alice')['messages'], 'saved notes physically cleared')
    check(ghost.api('messages', {'chat_id': 'saved_alice'}, get=True, expect_ok=False)[0] == 403, 'saved notes owner-only')
    folder = alice.api('drive_create_folder', {'name': 'Private folder'})[1]['folder']
    check(folder['name'] == 'Private folder', 'private folder created')
    check(ghost.api('drive_list', get=True)[1].get('items') == [], 'another account cannot view my Drive')

    cache = DATA / 'public_feed_cache'
    cache.mkdir(exist_ok=True)
    sample = 'samplechannel'
    preview = '✈️ **کانال آزمایشی** — 20 پست اخیرِ قابل نمایش از کانال عمومی @samplechannel\n🔎 منبع: https://t.me/s/samplechannel (پیش‌نمایش عمومی تلگرام؛ بدون ورود)'
    preview += ''.join(f'\n\n{i}) متن نمایشی {i}\n🔗 https://t.me/samplechannel/{i}' for i in range(1, 21))
    (cache / (hashlib.sha256(sample.encode()).hexdigest() + '.json')).write_text(json.dumps({'expires': int(time.time()) + 3600, 'answer': preview}))
    feed = alice.api('ask_ai', {'prompt': '/telegram samplechannel'})[1]
    check(len(feed['messages']) == 21 and len({m['seq'] for m in feed['messages']}) == 21, '20 public posts become 20 separate chat messages plus source header')
    check(all(f'https://t.me/samplechannel/{i}' in feed['messages'][i]['text'] for i in range(1, 21)), 'individual cached preview posts retain ordered source links')
    check(len(records(alice, 'ai_alice', limit=50)['messages']) == 21 and 'کانال آزمایشی' not in (DATA / 'chats' / 'ai_alice' / 'part_0001.json').read_text(), 'feed history stored and encrypted in personal services chat')
    ai_invalid = alice.api('ask_ai', {'prompt': '/telegram not-a-channel'})[1]['message']['text']
    check('آیدی عمومی' in ai_invalid and 'https://t.me' not in ai_invalid, 'Telegram channel ID is validated; no SSRF')
    tools = chief.api('admin_overview', get=True)[1]['config'].get('web_tools') or chief.api('bootstrap', get=True)[1]['config']['web_tools']
    tools = [dict(item, enabled=False) if item.get('command') == '/telegram' else item for item in tools]
    chief.api('admin_save_config', {'web_tools': tools})
    ai_off = alice.api('ask_ai', {'prompt': '/telegram telegram'})[1]['message']['text']
    check('فعال نشده' in ai_off, 'admin disabling public Telegram tool prevents execution')
    private_source = 'https://example.com/?private=' + secrets.token_hex(12) + '&q={query}'
    tools.append({'id':'private_source','title':'Private source','icon':'🔒','command':'/private','type':'custom_url',
        'lang':'fa','url_template':private_source,'quick_chip':True,'enabled':True})
    chief.api('admin_save_config', {'web_tools': tools})
    public_tools = alice.api('bootstrap', get=True)[1]['config']['web_tools']
    public_sync = alice.api('sync', {})[1]['web_tools']
    check(private_source not in json.dumps(public_tools) + json.dumps(public_sync) and
        any(t['command']=='/private' and t['requires_query'] for t in public_tools),
        'configured web-source URL/secrets remain admin-only')
    check(private_source in json.dumps(chief.api('admin_overview', get=True)[1]['config']['web_tools']),
        'admin can still edit full private web-source template')

    check(alice.api('reset_my_data', {'confirmation': 'RESET', 'current_password': 'wrong'}, expect_ok=False)[0] == 403, 'self reset requires password')
    alice.api('reset_my_data', {'confirmation': 'RESET', 'current_password': PASSWORDS['alice']})
    check(all(x['type'] != 'dm' or x['id'] == 'dm_alice__chief' for x in dialogs(alice)) and
          any(x['id'] == 'dm_alice__chief' for x in dialogs(alice)),
          'self reset clears ordinary contacts but retains permanent setup-admin contact')
    check(not records(alice, 'saved_alice')['messages'], 'self reset clears personal saved messages')
    check(not records(alice, 'ai_alice')['messages'], 'self reset clears imported public preview messages from services chat')
    check(records(alice, dm)['messages'] == [] and len(records(bob, dm)['messages']) > 0, 'self reset preserves peer copy of DM, hides own')
    check(len(alice.api('drive_list', get=True)[1]['items']) == 0, 'self reset empties Drive')
    check(alice.media(photo_id).status_code == 403 and bob.media(photo_id).status_code == 200, 'self reset cannot erase shared peer photo')
    check([u['username'] for u in json.loads((DATA / 'users.json').read_text()) if u['username'] == 'alice'], 'self reset retains account ID/password')
    check(alice.api('find_user_by_id', {'id': 'bob'})[1]['chat_id'] == dm, 'self reset permits re-adding exact-ID peer')
    check(records(alice, dm)['messages'] == [], 're-add does not resurrect pre-reset DM history')
    late = bob.api('send_message', {'chat_id': dm, 'text': 'late after account reset'})[1]['message']
    check(records(alice, dm)['messages'][-1]['seq'] == late['seq'], 'new messages after self reset are visible')
    chief.api('admin_reset_user_data', {'username': 'alice', 'confirmation': 'alice'})
    check(alice.api('bootstrap', get=True, expect_ok=False)[0] == 401, 'admin reset revokes target sessions')
    alice.login('alice', PASSWORDS['alice'])
    check(len(records(alice, dm)['messages']) == 0, 'admin reset hides only target own history')
    check(len(records(bob, dm)['messages']) > 0, 'admin reset preserves peer history')
    alice.api('update_profile', {'display_name': 'Renamed', 'new_username': 'alicia', 'bio': 'test'})
    check(alice.api('bootstrap', get=True)[1]['user']['username'] == 'alicia', 'username migration keeps active session')
    check(ghost.api('messages', {'chat_id': 'saved_alicia'}, get=True, expect_ok=False)[0] == 403, 'renamed personal storage isolated')
    check(alice.api('messages', {'chat_id': 'dm_alicia__bob'}, get=True)[1]['messages'] == [], 'renamed view watermark preserved')
    chief.api('admin_delete_user', {'username': 'ghost'})
    check(ghost.api('bootstrap', get=True, expect_ok=False)[0] == 401, 'admin deletion revokes target sessions')
    chief.api('delete_room', {'room_id': room})
    check(room not in [x['id'] for x in dialogs(chief)] and not (DATA / 'chats' / room).exists(), 'room deletion removes history for everyone')
    check(alice.api('send_message', {'chat_id': room, 'text': 'cannot revive deleted room'}, expect_ok=False)[0] == 403, 'deleted room cannot be written to')
    view_files = list((DATA / 'dialog_views').glob('*.json'))
    check(all(room not in json.loads(path.read_text()) for path in view_files), 'deleted room preferences/watermarks removed for all users')
    check(room not in json.loads((DATA / 'read_cursors.json').read_text()), 'deleted room read cursors removed')

    overview = chief.api('admin_overview', get=True)[1]
    check(overview['shards_report']['total_shards'] >= 1, 'JSON shard admin monitor returns totals')
    accounts = json.loads(overview['raw_users_json'])
    accounts.append({'username': 'editoronly', 'display_name': 'Raw edited', 'role': 'user', 'active': True, 'quota_mb': 40, 'password': PASSWORDS['editoronly']})
    chief.api('admin_save_raw_users_json', {'raw_json': json.dumps(accounts)})
    raw_pw = [x['password'] for x in json.loads((DATA / 'users.json').read_text()) if x['username'] == 'editoronly'][0]
    check(raw_pw.startswith('$2y$') and PASSWORDS['editoronly'] not in (DATA / 'users.json').read_text(), 'admin raw JSON editor hashes plaintext passwords')
    Client().login('editoronly', PASSWORDS['editoronly'])
    legacy = json.loads((DATA / 'users.json').read_text())
    next(x for x in legacy if x['username'] == 'editoronly')['password'] = PASSWORDS['editoronly']
    (DATA / 'users.json').write_text(json.dumps(legacy))  # emulate a pre-existing manual edit
    Client().login('editoronly', PASSWORDS['editoronly'])
    check(PASSWORDS['editoronly'] not in (DATA / 'users.json').read_text(), 'legacy plaintext password auto-upgraded on successful login')
    final_tools = chief.api('admin_overview', get=True)[1]['config']['web_tools']
    chief.api('admin_save_config', {'web_tools': [dict(t, enabled=True) if t.get('type') == 'telegram_public' else t for t in final_tools]})
    check(any(t['type']=='telegram_public' for t in alice.api('bootstrap', get=True)[1]['config']['web_tools']), 'Telegram public-ID service re-enabled for UI test')
    (SITE / '.browser-test-credentials.json').write_text(json.dumps({'chief': PASSWORDS['chief'], 'alicia': PASSWORDS['alice']}))
    print(f'PASS: {count} assertions; {STRESS_COUNT} sequential + 36 concurrent messages; {len(parts)} shards, {search_pages} search pages, encrypted uploads + ACL + reset + GitHub-safe setup', flush=True)


if __name__ == '__main__':
    main()
