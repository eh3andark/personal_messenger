#!/usr/bin/env python3
"""Current-release privacy, presence, read, system-room and search regressions.

Destructively resets ONLY /home/user/.cache/hamrah-test-site/data. Never run
against a live installation; run after starting PHP in this disposable fixture.
"""
import json
from pathlib import Path
import re
import secrets
import shutil
import subprocess
import sys
import time

import requests
from integration import BASE, SITE, DATA, Client, check, records, dialogs

assert '.cache/hamrah-test-site' in str(SITE) and (SITE / 'api.php').is_file()
PASSWORDS = {name: secrets.token_urlsafe(28) for name in ('chief', 'alice', 'bob', 'eve')}
ANN = 'channel_announcements'


def status(client, action, data):
    code, result = client.api(action, data, expect_ok=False)
    check(code >= 400 and not result.get('ok'), f'{action} denied', result)
    return code


def main():
    for item in DATA.iterdir():
        if item.is_dir(): shutil.rmtree(item)
        elif item.name not in ('.htaccess', 'index.php'): item.unlink()
    (DATA / 'users.json').write_text('[]\n')
    session = requests.Session()
    page = session.get(BASE + '/setup.php', timeout=10)
    check(page.status_code == 200, 'isolated setup reachable')
    token = re.search(r"return '([a-f0-9]{64})'", (DATA / '.setup_token.php').read_text()).group(1)
    form = dict(setup_token=token, username='chief', display_name='مدیر اصلی',
                password=PASSWORDS['chief'], password_confirm=PASSWORDS['chief'])
    check(session.post(BASE + '/setup.php', data=form, allow_redirects=False, timeout=10).status_code == 303,
          'one-time main admin created')

    chief, alice, bob, eve = [Client() for _ in range(4)]
    chief.login('chief', PASSWORDS['chief'])
    for name in ('alice', 'bob', 'eve'):
        chief.api('admin_save_user', {'is_new':True, 'username':name, 'password':PASSWORDS[name],
                                    'display_name':name.title(), 'quota_mb':50, 'active':True})
    for client, name in ((alice,'alice'),(bob,'bob'),(eve,'eve')): client.login(name, PASSWORDS[name])
    for client in (alice,bob,eve):
        boot=client.api('bootstrap',get=True)[1]
        check(boot['primary_admin_id']=='chief' and 'chief' in boot['users'] and
              any(d['id']=='dm_chief__'+boot['user']['username'] or d['id']=='dm_'+boot['user']['username']+'__chief'
                  for d in boot['dialogs']), 'setup admin visible as permanent private contact')
        check('eve' not in boot['users'] or boot['user']['username']=='eve', 'ordinary users do not see strangers') if client is not eve else None
        check(any(d['id']==ANN and d['type']=='channel' and not d['can_post'] and not d['can_leave_room']
                  and not d['can_hide_dialog'] for d in boot['dialogs']),
              'everyone sees required announcement channel without ability to post/leave/hide')
    check((DATA/'primary_admin.json').is_file() and
          json.loads((DATA/'primary_admin.json').read_text())['username']=='chief',
          'one-time primary identity persisted')
    check(chief.api('send_message', {'chat_id':ANN,'text':'تأیید اطلاعیه'})[1]['message']['sender']=='chief',
          'admin posts to system announcements')
    check(any(m['text']=='تأیید اطلاعیه' for m in records(eve, ANN)['messages']),
          'all current accounts receive announcement history')
    status(alice,'send_message',{'chat_id':ANN,'text':'unauthorized'})
    for client in (alice,chief):
        status(client,'leave_room',{'room_id':ANN})
        status(client,'delete_room',{'room_id':ANN})
        status(client,'room_member',{'room_id':ANN,'member_id':'bob','op':'remove'})
        status(client,'hide_dialog',{'chat_id':ANN})

    # Simulate an older installation where announcements were optional. The
    # next authenticated bootstrap must reconcile every existing account.
    rooms=json.loads((DATA/'rooms.json').read_text())
    rooms[ANN]['members']=['chief']
    rooms[ANN]['left_users']=['bob']
    rooms[ANN]['only_admins_post']=False
    rooms[ANN]['created_by']='alice'
    (DATA/'rooms.json').write_text(json.dumps(rooms))
    bob.api('bootstrap',get=True)
    fixed=json.loads((DATA/'rooms.json').read_text())[ANN]
    check(fixed['members']==['*'] and fixed['left_users']==[] and
          fixed['only_admins_post'] and fixed['created_by']=='chief' and
          any(d['id']==ANN for d in dialogs(bob)),
          'legacy existing users are re-enrolled in mandatory admin-only announcements')

    status(alice,'block_user',{'peer_username':'chief'})
    status(alice,'remove_contact',{'peer_username':'chief'})
    status(alice,'hide_dialog',{'chat_id':'dm_alice__chief', 'remove_contact':True})
    status(chief,'admin_save_user',{'is_new':False,'username':'chief','role':'user'})
    status(chief,'admin_save_user',{'is_new':False,'username':'chief','active':False})
    status(chief,'update_profile',{'new_username':'newchief'})
    raw = json.loads(chief.api('admin_overview',get=True)[1]['raw_users_json'])
    status(chief,'admin_save_raw_users_json',{'raw_json':json.dumps([x for x in raw if x['username']!='chief'])})
    status(chief,'admin_delete_user',{'username':'chief'})

    dm='dm_alice__bob'
    alice.api('find_user_by_id', {'id':'bob'})
    sent=alice.api('send_message',{'chat_id':dm,'text':'خوانده نشود تا گفتگو باز شود'})[1]['message']
    seq=sent['seq']
    cursors=lambda: json.loads((DATA/'read_cursors.json').read_text()) if (DATA/'read_cursors.json').exists() else {}
    check(next(d for d in dialogs(bob) if d['id']==dm)['unread_count']>=1, 'new DM message is unread')
    bob.api('bootstrap',get=True)
    bob.api('sync',{'active_chat_id':dm,'since_rev':0})
    records(bob,dm)
    check((cursors().get(dm) or {}).get('bob',0)<seq, 'login/bootstrap/sync/messages never auto-mark a DM read')
    check(next(d for d in dialogs(bob) if d['id']==dm)['unread_count']>=1, 'unopened chat still has unread badge')
    status(bob,'mark_chat_read',{'chat_id':dm,'seq':seq+1000})
    bob.api('mark_chat_read',{'chat_id':dm,'seq':seq})
    check(cursors()[dm]['bob']==seq and next(d for d in dialogs(bob) if d['id']==dm)['unread_count']==0,
          'explicit validated chat-open acknowledgement clears unread count')
    next_seq=alice.api('send_message',{'chat_id':dm,'text':'بعد از خواندن قبلی'})[1]['message']['seq']
    check(cursors()[dm]['bob']==seq and next_seq>seq, 'sending new message never advances recipient cursor')
    status(eve,'mark_chat_read',{'chat_id':dm,'seq':seq})

    alice.api('block_user',{'peer_username':'bob'})
    check('bob' in alice.api('bootstrap',get=True)[1]['blocked_users'] and
          'alice' not in bob.api('bootstrap',get=True)[1]['blocked_users'],
          'private block list belongs to blocker only')
    status(alice,'send_message',{'chat_id':dm,'text':'blocked outgoing'})
    status(bob,'send_message',{'chat_id':dm,'text':'blocked incoming'})
    status(bob,'react_message',{'chat_id':dm,'seq':seq,'emoji':'❤️'})
    check(records(bob,dm)['messages'], 'blocking preserves existing DM history')
    alice.api('unblock_user',{'peer_username':'bob'})
    check(bob.api('send_message',{'chat_id':dm,'text':'بعد از رفع مسدودی'})[1]['message']['sender']=='bob',
          'peer can send again after unblocking')
    status(eve,'block_user',{'peer_username':'bob'})

    public=alice.api('create_room', {'type':'group','title':'گروه نام‌یاب آزمایش','members':[],
                                     'is_public':True,'public_handle':'public_test_room'})[1]['room']
    private=alice.api('create_room', {'type':'channel','title':'کانال مخفی نام‌یاب آزمایش','members':[],
                                      'is_public':False})[1]['room']
    found=eve.api('search_public_rooms', {'q':'نام‌یاب'},get=True)[1]['rooms']
    check([item['id'] for item in found]==[public['id']] and
          all('invite_token' not in item and 'members' not in item for item in found),
          'search by visible name returns public rooms only without private tokens/member list')
    check(not eve.api('search_public_rooms',{'q':'مخفی'},get=True)[1]['rooms'],
          'private room title never leaks in public search')
    check(eve.api('search_public_rooms',{'q':'@@'},get=True,expect_ok=False)[0]==400,
          'empty handle alias is not a wildcard search')
    eve.api('join_room',{'room_id':public['id']})
    check(eve.api('messages',{'chat_id':public['id']},get=True)[0]==200,
          'name-discovered public room can be joined and read')
    status(eve,'join_room',{'room_id':private['id']})
    channel=alice.api('create_room',{'type':'channel','title':'کانال تست','members':['bob'],
                                     'is_public':False})[1]['room']
    message=alice.api('send_message', {'chat_id':channel['id'],'text':'پیام کانال'})[1]['message']
    check(any(m['seq']==message['seq'] for m in records(bob,channel['id'])['messages']),
          'channel member receives manager post; frontend labels it by channel')

    # New account signs in with a page ID from the start (no legacy tab).
    # Closing one of two pages must not disconnect the other; closing both
    # should switch offline without waiting for its login session to expire.
    chief.api('admin_save_user',{'is_new':True,'username':'tina','password':secrets.token_urlsafe(28),
                                 'display_name':'Tina'})
    fresh_password=secrets.token_urlsafe(28)
    chief.api('admin_save_user',{'is_new':False,'username':'tina','password':fresh_password})
    bob.api('find_user_by_id',{'id':'tina'})
    tina=Client()
    tab1,tab2='1'*32,'2'*32
    code, start=tina.api('login',{'username':'tina','password':fresh_password,'tab_id':tab1})
    tina.token=start['token']
    tina.api('sync',{'tab_id':tab2})
    check(bob.api('bootstrap',get=True)[1]['presence']['tina']['online'], 'two live tabs show online')
    tina.api('presence_close',{'tab_id':tab1})
    check(bob.api('bootstrap',get=True)[1]['presence']['tina']['online'],
          'closing first tab leaves second tab online')
    tina.api('presence_close',{'tab_id':tab2})
    check(not bob.api('bootstrap',get=True)[1]['presence']['tina']['online'],
          'last closed page becomes offline immediately without session expiry')
    tina.api('sync',{'tab_id':tab1})
    check(not bob.api('bootstrap',get=True)[1]['presence']['tina']['online'],
          'late heartbeat from closed tab cannot resurrect presence')
    tina.api('sync',{'tab_id':'3'*32})
    check(bob.api('bootstrap',get=True)[1]['presence']['tina']['online'],
          'new page generation restores online state')

    # Offline parser test: these are mock DOM rows, NEVER live quotes. The
    # source itself is fetched only on a user-initiated /rates request.
    rates_php = r'''
require 'core/DomesticRates.php';
$boards = [
 '/currency' => '<table><tr data-market-nameslug="price_dollar_rl" data-price="1,864,000"><th>دلار</th><td>1,864,000</td><td>-</td><td>-</td><td>-</td><td>۱۱:۴۹:۴۵</td></tr></table>',
 '/gold-chart' => '<table><tr data-market-nameslug="geram18" data-price="190,542,000"><th>طلا</th><td>190,542,000</td><td>-</td><td>-</td><td>-</td><td>۱۱:۴۹:۵۵</td></tr></table>',
 '/coin' => '<table><tr data-market-nameslug="sekee" data-price="1,889,900,000"><th>سکه</th><td>1,889,900,000</td><td>-</td><td>-</td><td>-</td><td>۱۱:۳۴:۴۹</td></tr></table>',
];
$fetch = function (string $url) use ($boards): array {
 $path = parse_url($url, PHP_URL_PATH);
 return ['ok'=>isset($boards[$path]), 'body'=>$boards[$path] ?? ''];
};
$valid=DomesticRates::parseRows($boards['/currency'], ['price_dollar_rl'=>'دلار']);
$forged=DomesticRates::parseRows('<table><tr data-market-nameslug="other" data-price="999,999,999"><td>fake</td></tr></table>', ['price_dollar_rl'=>'دلار']);
$fresh=DomesticRates::fetch($fetch);
$path=DATA_DIR.'/domestic_rates_cache.json';
$data=read_json_safe($path, []);
$data['checked_at']=time()-600;write_json_atomic($path,$data,false);
$stale=DomesticRates::fetch(fn($url)=>['ok'=>false,'body'=>'']);
$data=read_json_safe($path, []);
$data['checked_at']=time()-90000;
$data['last_failure_at']=time()-120;
write_json_atomic($path,$data,false);
$unavailable=DomesticRates::fetch(fn($url)=>['ok'=>false,'body'=>'']);
echo json_encode(['valid'=>$valid,'forged'=>$forged,'fresh'=>$fresh,
                  'stale'=>$stale,'unavailable'=>$unavailable],JSON_UNESCAPED_UNICODE);
'''
    run=subprocess.run(['php','-r',rates_php],cwd=SITE,capture_output=True,text=True,
                       check=True,timeout=25)
    rates=json.loads(run.stdout)
    check(rates['valid']['price_dollar_rl']['rial']==1864000 and
          rates['valid']['price_dollar_rl']['time']=='11:49:45' and not rates['forged'],
          'real-board DOM shape, Persian clock and exact-rate slug parsed; other rows ignored')
    check(all(v in rates['fresh'] for v in ('1,864,000','190,542,000','1,889,900,000','ریال','TGJU',
                                          'https://www.tgju.org/profile/price_dollar_rl')),
          'injected sample TGJU rows retain source, units and record time (not live quotes)')
    check('قدیمی' in rates['stale'] and '1,864,000' in rates['stale'],
          'provider failure displays explicitly stale, bounded cached quotes')
    check('نرخ قابل اتکا موجود نیست' in rates['unavailable'] and '1,864,000' not in rates['unavailable'],
          'expired cache/provider failure returns unavailable rather than invented current rates')

    (SITE/'.browser-review-credentials.json').write_text(json.dumps({
        'chief':PASSWORDS['chief'],'alice':PASSWORDS['alice'],'bob':PASSWORDS['bob'],
        'channel':channel['id'],'group':public['id'],'dm':dm}))
    print('PASS: reviewed permanent admin, mandatory announcement, explicit read, blocking, '
          'name-search privacy, concurrent tabs, channel ACL and honest TGJU fallback',flush=True)

if __name__ == '__main__': main()
