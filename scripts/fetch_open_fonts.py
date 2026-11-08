#!/usr/bin/env python3
"""Maintenance only: obtain redistributable OFL fonts from official projects.

Release ZIPs contain all binaries, so the app never fetches fonts at runtime.
Review upstream licenses and record hashes before updating bundled assets.
"""
from pathlib import Path
from urllib.parse import quote
import hashlib
import requests

root = Path(__file__).resolve().parents[1] / 'assets/fonts'
root.mkdir(parents=True, exist_ok=True)
s = requests.Session()
s.headers['User-Agent'] = 'ChatAmn-open-font-maintenance/1.0'
assets = []
for name, repo in [('Shabnam','shabnam-font'),('Samim','samim-font'),('Parastoo','parastoo-font'),('Gandom','gandom-font')]:
    base = 'https://raw.githubusercontent.com/rastikerdar/' + repo + '/master/'
    assets.extend([(name+'.woff2', base+'dist/'+name+'.woff2'), (name+'-OFL.txt',base+'LICENSE')])
assets += [
    ('Mikhak.woff2','https://raw.githubusercontent.com/aminabedi68/Mikhak/master/fonts/webfonts/variable/'+quote('Mikhak[DSTY,KSHD,wght].woff2')),
    ('Mikhak-OFL.txt','https://raw.githubusercontent.com/aminabedi68/Mikhak/master/OFL.txt'),
]
for name,path in [('NotoSansArabic','notosansarabic/NotoSansArabic[wdth,wght].ttf'),('NotoNaskhArabic','notonaskharabic/NotoNaskhArabic[wght].ttf')]:
    base='https://raw.githubusercontent.com/google/fonts/main/ofl/'
    assets.extend([(name+'.ttf',base+quote(path,safe='/')), (name+'-OFL.txt',base+path.split('/')[0]+'/OFL.txt')])
for name,url in assets:
    r=s.get(url,timeout=30)
    r.raise_for_status()
    body=r.content
    if name.endswith('.txt'):
        assert b'OPEN FONT LICENSE' in body.upper() and b'Copyright' in body, name
    else:
        assert len(body)>3000 and body[:4] in (b'wOF2',b'\x00\x01\x00\x00',b'OTTO'), (name,body[:50])
    if name.endswith('.ttf'):
        import fontTools.ttLib
        import brotli  # noqa: F401, fontTools uses it when writing woff2
        font=fontTools.ttLib.TTFont(__import__('io').BytesIO(body))
        font.flavor='woff2'
        dest=root/(name.replace('.ttf','.woff2'))
        font.save(str(dest))
        body=dest.read_bytes()
        name=dest.name
    else:
        (root/name).write_bytes(body)
    print(name,len(body),hashlib.sha256(body).hexdigest(),url)
