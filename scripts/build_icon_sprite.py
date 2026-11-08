#!/usr/bin/env python3
"""Maintenance only: bundle a curated local Lucide ISC icon sprite.

npm install --prefix /home/user/.cache/hamrah-icons lucide-static@0.548.0
python3 scripts/build_icon_sprite.py
Release archives include the resulting SVG and its ISC/MIT notice, never npm.
"""
from pathlib import Path
from xml.etree import ElementTree as ET
import os

ROOT = Path(__file__).resolve().parents[1]
ICON_DIR = Path(os.environ.get('LUCIDE_ICONS', '/home/user/.cache/hamrah-icons/node_modules/lucide-static/icons'))
NAMES = ('menu','search','settings','x','paperclip','image','video','file','camera','map-pin',
         'smile','sticker','mic','send','plus','users-round','megaphone','contact','shield-check',
         'log-out','chevron-right','chevron-left','download','monitor-down','ellipsis','ban',
         'check-check','trash-2','pin','bookmark','bell','bell-off','folder','circle-help',
         'external-link','arrow-right','arrow-left','refresh-cw','eye','eye-off','moon','sun')
ns = '{http://www.w3.org/2000/svg}'
ET.register_namespace('', 'http://www.w3.org/2000/svg')
root = ET.Element(ns+'svg', {'aria-hidden':'true','focusable':'false','class':'ui-symbols'})
for name in NAMES:
    path = ICON_DIR / (name + '.svg')
    icon = ET.parse(path).getroot()
    symbol = ET.SubElement(root, ns+'symbol', {'id':'ui-'+name,'viewBox':'0 0 24 24'})
    for child in icon:
        symbol.append(child)
result = ET.tostring(root, encoding='unicode').replace('ns0:', '').replace(':ns0', '')
(ROOT/'assets/img/ui-icons.svg').write_text(result+'\n', encoding='utf-8')
print(len(NAMES),'icons bundled locally; bytes',len(result))
