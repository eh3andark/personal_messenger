#!/usr/bin/env python3
"""New-feature API/security/quota tests. Disposable .cache fixture ONLY.

Run after starting php -S ... router.php inside the fixture. This resets only
/home/user/.cache/hamrah-test-site/data and uses randomly generated credentials.
"""
import base64
import hashlib
import json
from pathlib import Path
import re
import secrets
import shutil
import subprocess
import sys
import time

import requests
from integration import BASE, SITE, DATA, Client, check, records, dialogs, upload

assert '.cache/hamrah-test-site' in str(SITE) and (SITE / 'api.php').exists()
PASSWORDS = {name: secrets.token_urlsafe(28) for name in ('chief', 'alice', 'bob', 'ghost', 'quotauser')}
TINY_PNG = base64.b64decode('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVQIHWP4z8DwHwAFgAI/ScL/nQAAAABJRU5ErkJggg==')


def sticker_upload(client, blob=TINY_PNG):
    upload_id = 'sticker_' + secrets.token_hex(6)
    res = client.api('upload_chunk', data={
        'upload_id': upload_id, 'chunk_index': '0', 'total_chunks': '1',
        'total_size': str(len(blob)), 'original_name': 'my_sticker.png',
        'mime_type': 'image/png', 'is_sticker': '1',
    }, files={'chunk': ('my_sticker.png', blob, 'image/png')})[1]
    check(res.get('complete') and res['media']['category'] == 'sticker', 'sticker upload classified/validated', res)
    return res['media']


def standalone_import(client, kind, blob, post_number):
    code = ("require 'core/MediaManager.php'; "
            f"$u=Auth::getUser('alice'); $b=base64_decode('{base64.b64encode(blob).decode()}'); "
            f"echo json_encode(MediaManager::importTelegramPreview($u,$b,'{kind}','testchannel',{post_number}));")
    run = subprocess.run(['php', '-r', code], cwd=SITE, capture_output=True, text=True, check=True, timeout=20)
    return json.loads(run.stdout)


def main():
    for item in DATA.iterdir():
        if item.is_dir(): shutil.rmtree(item)
        elif item.name not in ('.htaccess', 'index.php'): item.unlink()
    (DATA / 'users.json').write_text('[]\n')
    session = requests.Session()
    setup = session.get(BASE + '/setup.php', timeout=10)
    check(setup.status_code == 200, 'setup reachable in disposable fixture')
    token = re.search(r"return '([a-f0-9]{64})'", (DATA / '.setup_token.php').read_text()).group(1)
    form = {'setup_token':token, 'username':'chief', 'display_name':'مدیر آزمایش',
            'password':PASSWORDS['chief'], 'password_confirm':PASSWORDS['chief']}
    created = session.post(BASE + '/setup.php', data=form, allow_redirects=False, timeout=10)
    check(created.status_code == 303, 'random one-time setup succeeds')
    chief, alice, bob, ghost, quota = [Client() for _ in range(5)]
    chief.login('chief', PASSWORDS['chief'])
    for name in ('alice', 'bob', 'ghost', 'quotauser'):
        chief.api('admin_save_user', {'is_new':True, 'username':name, 'password':PASSWORDS[name],
                  'display_name':name.title(), 'role':'user', 'quota_mb':50, 'active':True})
    alice.login('alice', PASSWORDS['alice'])
    bob.login('bob', PASSWORDS['bob'])
    ghost.login('ghost', PASSWORDS['ghost'])
    quota.login('quotauser', PASSWORDS['quotauser'])

    # Unique IDs including the raw JSON admin editor, and real GitHub link only.
    status, duplicate = chief.api('admin_save_user', {'is_new':True, 'username':'alice', 'password':secrets.token_urlsafe(20)}, expect_ok=False)
    check(status == 409 and duplicate.get('code') == 'duplicate_id' and 'آیدی' in duplicate['error'], 'duplicate account ID has relevant 409 error', duplicate)
    raw = json.loads(chief.api('admin_overview', get=True)[1]['raw_users_json'])
    status, dupr = chief.api('admin_save_raw_users_json', {'raw_json': json.dumps(raw + [dict(raw[1])])}, expect_ok=False)
    check(status == 409 and dupr.get('code') == 'duplicate_id', 'raw JSON duplicate ID blocked under users lock', dupr)
    page = session.get(BASE + '/index.php').text
    check('auth-source-link' not in page and 'HTTPS' not in page.split('<div class="auth-footer">')[1].split('</div>')[0], 'login has no fake GitHub URL or HTTPS wording')
    status, bad = chief.api('admin_save_config', {'github_url':'https://github.com.evil.test/owner/repo'}, expect_ok=False)
    check(status == 400 and 'github' in bad['error'].lower(), 'invalid GitHub hostname rejected')
    chief.api('admin_save_config', {'github_url':'https://github.com/owner/real-project'})
    page = session.get(BASE + '/index.php').text
    check('href="https://github.com/owner/real-project"' in page and 'rel="noopener noreferrer"' in page, 'saved repository link appears safely at login')
    chief.api('admin_save_config', {'github_url':''})
    check('auth-source-link' not in session.get(BASE + '/index.php').text, 'cleared GitHub URL hides login link')

    # Public/private group discovery, exact ID, invite token, leave, permission.
    room = alice.api('create_room', {'type':'group', 'title':'دوستان', 'members':[],
          'is_public':True, 'public_handle':'friendly_room'})[1]['room']
    public_id = room['id']
    found = ghost.api('find_public_room', {'id':'friendly_room'}, get=True)[1]['room']
    check(found['id'] == public_id and not found['joined'] and 'invite_token' not in found and 'members' not in found,
          'public exact-ID lookup contains no private member directory/token', found)
    check(ghost.api('messages', {'chat_id':public_id}, get=True, expect_ok=False)[0] == 403,
          'public room contents unavailable before membership')
    ghost.api('join_room', {'room_id':'friendly_room'})
    check(ghost.api('room_info', {'room_id':public_id}, get=True)[1]['room']['invite_token'], 'joined member may share invite link')
    pmsg = ghost.api('send_message', {'chat_id':public_id, 'text':'پیام در گروه عمومی'})[1]['message']
    check(pmsg['sender']=='ghost', 'public joiner can post to group')
    ghost.api('leave_room', {'room_id':public_id})
    check(ghost.api('messages', {'chat_id':public_id}, get=True, expect_ok=False)[0] == 403,
          'former member cannot see messages')
    ghost.api('join_room', {'room_id':public_id})
    status, repeated = alice.api('create_room', {'type':'group','title':'دوباره', 'members':[], 'is_public':True,
                                                'public_handle':'friendly_room'}, expect_ok=False)
    check(status==409 and repeated.get('code')=='duplicate_id', 'duplicate public room handle has 409')
    priv = alice.api('create_room', {'type':'group','title':'حریم خصوصی', 'members':[], 'is_public':False})[1]['room']
    private_id = priv['id']
    check(ghost.api('find_public_room', {'id':private_id}, get=True, expect_ok=False)[0]==404,
          'private room existence not publicly discoverable')
    check(ghost.api('join_room', {'room_id':private_id}, expect_ok=False)[0]==403, 'private room rejects missing invitation')
    check(ghost.api('join_room', {'room_id':private_id, 'invite_token':'a'*36}, expect_ok=False)[0]==403,
          'private room rejects false invitation')
    alice.api('room_member', {'room_id':private_id, 'member_id':'bob', 'op':'add'})
    check(bob.api('messages', {'chat_id':private_id}, get=True)[0]==200, 'manager can add member by exact ID')
    check(bob.api('room_member', {'room_id':private_id, 'member_id':'ghost', 'op':'add'}, expect_ok=False)[0]==403,
          'non-manager cannot invite members')
    alice.api('room_member', {'room_id':private_id, 'member_id':'bob', 'op':'remove'})
    check(bob.api('messages', {'chat_id':private_id}, get=True, expect_ok=False)[0]==403, 'removed member loses history')
    ghost.api('join_room', {'room_id':private_id, 'invite_token':priv['invite_token']})
    ghost.api('send_message', {'chat_id':private_id, 'text':'پیام صاحب قدیمی آیدی'})
    old_token = priv['invite_token']
    alice.api('update_room', {'room_id':private_id, 'rotate_invite':True})
    ghost.api('leave_room', {'room_id':private_id})
    check(ghost.api('join_room', {'room_id':private_id, 'invite_token':old_token}, expect_ok=False)[0]==403,
          'rotated invitation invalidates old private link')
    new_token = alice.api('room_info', {'room_id':private_id}, get=True)[1]['room']['invite_token']
    ghost.api('join_room', {'room_id':private_id, 'invite_token':new_token})
    channel = alice.api('create_room', {'type':'channel','title':'اخبار', 'members':[], 'is_public':True})[1]['room']
    bob.api('join_room', {'room_id':channel['id']})
    check(bob.api('send_message', {'chat_id':channel['id'], 'text':'غیر مدیر'}, expect_ok=False)[0]==403,
          'channel only allows manager to post')
    check(ghost.api('leave_room', {'room_id':private_id})[1]['ok'], 'private member may leave again')
    ghost.api('join_room', {'room_id':private_id, 'invite_token':new_token})

    # Sticker creation, encrypted PNG streaming, sending, pack removal and ACL.
    sticker = sticker_upload(alice)
    sticker_id = sticker['id']
    saved = alice.api('sticker_save', {'media_id':sticker_id,'label':'درود'})[1]['stickers']
    check(saved[0]['media']['id']==sticker_id and saved[0]['label']=='درود', 'sticker personal pack saved')
    check(not bob.api('sticker_list', get=True)[1]['stickers'], 'another user cannot view sticker collection')
    check(bob.api('sticker_save', {'media_id':sticker_id}, expect_ok=False)[0]>=400,
          'cannot steal sticker from another owner')
    stream = alice.media(sticker_id)
    check(stream.status_code==200 and stream.content==TINY_PNG and stream.headers['Content-Type']=='image/png',
          'sticker is authenticated inline PNG decrypted from disk')
    check(bob.media(sticker_id).status_code==403, 'sticker not accessible without shared message')
    sent = alice.api('send_message', {'chat_id':private_id,'media_id':sticker_id,'text':''})[1]['message']
    check(sent['media']['category']=='sticker' and ghost.media(sticker_id).status_code==200,
          'sticker sent as image to current group members')
    alice.api('sticker_remove', {'media_id':sticker_id})
    check(not alice.api('sticker_list', get=True)[1]['stickers'] and ghost.media(sticker_id).status_code==200,
          'removing pack retains sent sticker for peers')
    alice.api('delete_message', {'chat_id':private_id,'seq':sent['seq'],'scope':'both'})
    check(ghost.media(sticker_id).status_code in (403,404), 'last reference removal retires sent sticker')

    # Arbitrary files, cross-chat inventory & both deletion scopes.
    dm = alice.api('find_user_by_id', {'id':'bob'})[1]['chat_id']
    doc = upload(alice,'my_document.bin','application/octet-stream',b'small own file '+secrets.token_bytes(100))
    doc_id = doc['id']
    sent = alice.api('send_message', {'chat_id':dm, 'media_id':doc_id,'text':'پیوست من'})[1]['message']
    inventory = alice.api('my_media_list', {'limit':20}, get=True)[1]['items']
    check(any(it['media']['id']==doc_id and it['refs']['chat']>=1 for it in inventory),
          'owned inventory traverses cross-chat uploads and ref counts')
    check(doc_id not in [it['media']['id'] for it in bob.api('my_media_list', get=True)[1]['items']],
          'peer never receives ownership over incoming media')
    not_mine = bob.api('purge_my_media', {'media_ids':[doc_id], 'scope':'both', 'confirmation':'MEDIA', 'password':PASSWORDS['bob']})[1]
    check(not not_mine['results'][0]['ok'] and alice.media(doc_id).status_code==200,
          'bulk purge cannot target another owners media')
    me = alice.api('purge_my_media', {'media_ids':[doc_id], 'scope':'me', 'confirmation':'MEDIA'})[1]['results'][0]
    check(me['hidden']>=1 and me['still_stored'] and me['freed_bytes']==0 and bob.media(doc_id).status_code==200,
          'only-for-me preserves peer copy and truthfully leaves bytes charged')
    check(alice.media(doc_id).status_code==403 and bob.media(doc_id).status_code==200,
          'owner cannot bypass hidden-for-me via direct media URL')
    check(alice.api('purge_my_media', {'media_ids':[doc_id], 'scope':'both','confirmation':'MEDIA', 'password':'wrong'}, expect_ok=False)[0]==403,
          'for-everyone purge requires current password')
    both = alice.api('purge_my_media', {'media_ids':[doc_id], 'scope':'both', 'confirmation':'MEDIA', 'password':PASSWORDS['alice']})[1]['results'][0]
    check(both['removed']>=1 and not both['still_stored'] and both['freed_bytes']==len(b'small own file ')+100,
          'for-everyone deletes own sent media, reclaims bytes after last ref', both)
    check(bob.media(doc_id).status_code in (403,404), 'peer no longer receives globally deleted file')
    check(alice.api('purge_my_media', {'media_ids':[doc_id], 'scope':'both', 'confirmation':'MEDIA'}, expect_ok=False)[0]==403,
          'for-everyone password cannot be skipped')
    check(alice.api('purge_my_media', {'media_ids':[doc_id]*21, 'scope':'me', 'confirmation':'MEDIA'}, expect_ok=False)[0]==400,
          'bulk deletion cap is server-enforced')

    # 80%-before-next-upload quota, text unaffected, reclamation unblocks media.
    qdm = quota.api('find_user_by_id', {'id':'bob'})[1]['chat_id']
    big = upload(quota,'padding.bin','application/octet-stream',b'P'*(39*1048576))
    check(quota.api('bootstrap', get=True)[1]['quota']['media_blocked'] is False,
          '39/50 MiB remains below cutoff')
    crossing = upload(quota,'crossing.bin','application/octet-stream',b'Q'*(2*1048576))
    quota_state = quota.api('sync', {})[1]['quota']
    check(quota_state['media_blocked'] is True and quota_state['used_bytes']==41*1048576,
          'first crossing upload completes, then 80% cutoff displayed in sync')
    allowed = quota.api('send_message', {'chat_id':qdm, 'media_id':crossing['id'], 'text':''})[1]['message']
    check(allowed['media']['id']==crossing['id'], 'first just-crossed upload may finish its first send')
    check(quota.api('send_message', {'chat_id':qdm, 'media_id':crossing['id']}, expect_ok=False)[0]==403,
          'second media send is blocked at 80%')
    check(quota.api('forward_message', {'from_chat_id':qdm, 'to_chat_id':'saved_quotauser', 'seq':allowed['seq']}, expect_ok=False)[0]==403,
          'forwarding media at cutoff is blocked')
    check(quota.api('upload_chunk', data={'upload_id':'blocked_'+secrets.token_hex(4), 'chunk_index':'0','total_chunks':'1','total_size':'4',
           'original_name':'blocked.bin', 'mime_type':'application/octet-stream'},
           files={'chunk':('blocked.bin',b'data','application/octet-stream')}, expect_ok=False)[0]==400,
          'new upload blocked immediately after crossing')
    check(quota.api('send_message', {'chat_id':qdm, 'text':'متن بعد از پر شدن سهمیه'})[0]==200,
          'text messaging remains available over 80%')
    only_me = quota.api('purge_my_media', {'media_ids':[crossing['id']], 'scope':'me', 'confirmation':'MEDIA'})[1]['results'][0]
    check(only_me['still_stored'] and only_me['freed_bytes']==0, 'me-only bulk deletion does not fake quota reclamation')
    for_all = quota.api('purge_my_media', {'media_ids':[crossing['id']], 'scope':'both', 'confirmation':'MEDIA', 'password':PASSWORDS['quotauser']})[1]
    check(for_all['quota']['used_bytes']==39*1048576 and not for_all['quota']['media_blocked'],
          'last-reference global deletion frees quota below cutoff', for_all)
    check(quota.api('send_message', {'chat_id':qdm, 'text':'متن همچنان آزاد'})[0]==200,
          'text still available after deletion')
    # Remove large unreferenced fixture upload without producing a real dump.
    quota.api('delete_unused_upload', {'media_id':big['id']})
    check(quota.api('bootstrap', get=True)[1]['quota']['used_bytes']==0, 'full quota reclamation after orphan removal')

    # Strict Telegram CDN allowlist, cached public preview failure reporting,
    # and encrypt-at-rest import without trusting remote MIME/file extensions.
    bad_channel = 'testchannel'
    cache = DATA / 'public_feed_cache'; cache.mkdir(exist_ok=True)
    post = {'post_number':7,'media_type':'photo','media_url':'http://127.0.0.1/private',
            'text':'test','url':'https://t.me/testchannel/7','date':''}
    (cache / (hashlib.sha256(bad_channel.encode()).hexdigest()+'.json')).write_text(json.dumps({
        'import_expires':int(time.time())+600,'posts':[post], 'expires':int(time.time())+90, 'answer':'dummy'}))
    status, denied = alice.api('telegram_import_post', {'channel':'testchannel','post_number':7}, expect_ok=False)
    check(status==400 and 'نشانی' in denied['message']['text'] and 'https://t.me/testchannel/7' in denied['message']['text'],
          'invalid CDN URL cannot SSRF, error always appears as bot message')
    raw_png_import = standalone_import(alice, 'photo', TINY_PNG, 8)
    check(raw_png_import['ok'] and raw_png_import['media']['encrypted'].startswith('aes-256-gcm'),
          'public-preview photo bytes imported with encrypted-at-rest registry', raw_png_import)
    imported_id = raw_png_import['media']['id']
    check(alice.media(imported_id).content==TINY_PNG and ghost.media(imported_id).status_code==403,
          'imported public-preview image private to owning account before sharing')
    encrypted_path = next((DATA / 'uploads').rglob(imported_id+'.enc'))
    check(TINY_PNG not in encrypted_path.read_bytes(), 'imported plaintext not stored in encrypted ciphertext')
    alice.api('delete_unused_upload', {'media_id':imported_id})
    raw_file_import = standalone_import(alice, 'file', b'%PDF-1.4\n1 0 obj\n<<>>\nendobj\n%%EOF\n', 9)
    check(raw_file_import['ok'] and raw_file_import['media']['category']=='file', 'available document can be imported as encrypted file')
    alice.api('delete_unused_upload', {'media_id':raw_file_import['media']['id']})

    # Recycled ID must never become the author/owner of past private data.
    ghost.api('send_message', {'chat_id':'saved_ghost','text':'فقط برای صاحب قبلی'})
    old_secret = upload(ghost,'old_secret.bin','application/octet-stream',b'secret bytes belonged to previous ghost')
    old_public = ghost.api('send_message', {'chat_id':public_id,'media_id':old_secret['id'], 'text':'نسخهٔ گروه قبلی'})[1]['message']
    old_private = ghost.api('send_message', {'chat_id':private_id,'text':'خصوصی حساب قبلی'})[1]['message']
    chief.api('admin_delete_user', {'username':'ghost'})
    check(ghost.api('bootstrap', get=True, expect_ok=False)[0]==401, 'old account login session revoked after deletion')
    check(not (DATA / 'chats' / 'saved_ghost').exists() and not (DATA / 'drive' / 'ghost').exists(),
          'deleted account personal history/drive physically cleared')
    check((DATA / 'media_registry').exists() and any(m.get('owner')=='__deleted__' and m.get('id')==old_secret['id']
          for path in (DATA/'media_registry').rglob('*.json') for m in [json.loads(path.read_text())]),
          'surviving group attachment is retired from deleted owner')
    check('ghost' not in json.loads((DATA/'presence.json').read_text()), 'old presence removed before ID can be reused')
    chief.api('admin_save_user', {'is_new':True,'username':'ghost','password':secrets.token_urlsafe(22),
              'display_name':'کاربر جدید','role':'user','quota_mb':50,'active':True})
    new_ghost = Client()
    new_pass = [u for u in json.loads((DATA/'users.json').read_text()) if u['username']=='ghost'][0]['password']
    # The new plaintext password was already hashed; set a known one via admin.
    fresh_password = secrets.token_urlsafe(22)
    chief.api('admin_save_user', {'is_new':False,'username':'ghost','password':fresh_password})
    new_ghost.login('ghost',fresh_password)
    check(not records(new_ghost,'saved_ghost')['messages'] and
          new_ghost.api('bootstrap', get=True)[1]['quota']['used_bytes']==0,
          'reused ID starts with empty Saved Messages and zero old quota')
    check(new_ghost.media(old_secret['id']).status_code==403,
          'reused ID cannot inherit old group media just by owning the same ID')
    check(new_ghost.api('messages', {'chat_id':private_id}, get=True, expect_ok=False)[0]==403,
          'reused ID cannot inherit old private room membership')
    new_ghost.api('join_room', {'room_id':public_id})
    after = next(m for m in records(new_ghost, public_id)['messages'] if m['seq']==old_public['seq'])
    check(after['sender'] != 'ghost' and after['media']['owner'] != 'ghost',
          'old public group history is anonymized, not attributed to recycled ID', after)
    check(new_ghost.api('delete_message', {'chat_id':public_id,'seq':old_public['seq'],'scope':'both'}, expect_ok=False)[0]>=400,
          'new owner cannot globally delete old owners group media')
    check(new_ghost.api('purge_my_media', {'media_ids':[old_secret['id']], 'scope':'both',
          'confirmation':'MEDIA','password':fresh_password})[1]['results'][0]['ok'] is False,
          'new owner cannot bulk-delete retired media')
    check(new_ghost.api('room_info', {'room_id':private_id}, get=True, expect_ok=False)[0]==403,
          'private group details remain unavailable to recycled ID')
    check(all(m.get('sender')!='ghost' for m in records(alice,private_id)['messages'] if m['seq']==old_private['seq']),
          'deleted author anonymized even in private group history')

    (SITE / '.browser-feature-credentials.json').write_text(json.dumps({
        'chief':PASSWORDS['chief'], 'alice':PASSWORDS['alice'], 'bob':PASSWORDS['bob'],
        'quotauser':PASSWORDS['quotauser'], 'private_room':private_id, 'public_room':public_id,
        'channel':channel['id'],
    }))
    print('PASS: new features: unique IDs/reuse, room ACL/invites, sticker, 80% quota, bulk scopes, Telegram SSRF/encryption, GitHub link', flush=True)

if __name__ == '__main__': main()
