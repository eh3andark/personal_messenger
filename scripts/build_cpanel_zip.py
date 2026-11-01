#!/usr/bin/env python3
"""Make an audited fresh-install ZIP (never overwrite a live data/ directory)."""
from hashlib import sha256
from pathlib import Path
import stat
import zipfile

ROOT = Path(__file__).resolve().parents[1]
OUTPUT = ROOT.parent / 'hamrah-messenger-cpanel.zip'
FILES = [
    '.htaccess', '.user.ini', 'index.php', 'setup.php', 'api.php', 'manifest.php', 'sw.js',
    'README.md', 'README-FA.md', 'README-EN.md',
    'GITHUB-PUBLISH-FA.md', 'AUTHORS.md', 'CHANGELOG.md', 'CONTRIBUTING.md',
    'SECURITY.md', 'LICENSE', 'NOTICE.md',
    'assets/css/app.css', 'assets/js/app.js', 'assets/img/icon.svg',
    'assets/img/icon-192.png', 'assets/img/icon-512.png',
    'assets/img/ui-icons.svg', 'assets/img/LUCIDE-LICENSE.txt',
    'assets/fonts/Vazirmatn-Variable.woff2', 'assets/fonts/OFL.txt',
    'assets/fonts/Estedad-Variable.woff2', 'assets/fonts/Estedad-OFL.txt',
    'assets/fonts/Sahel-Variable.woff2', 'assets/fonts/Sahel-OFL.txt',
    'assets/fonts/Shabnam.woff2', 'assets/fonts/Shabnam-OFL.txt',
    'assets/fonts/Samim.woff2', 'assets/fonts/Samim-OFL.txt',
    'assets/fonts/Parastoo.woff2', 'assets/fonts/Parastoo-OFL.txt',
    'assets/fonts/Gandom.woff2', 'assets/fonts/Gandom-OFL.txt',
    'assets/fonts/Mikhak.woff2', 'assets/fonts/Mikhak-OFL.txt',
    'assets/fonts/NotoSansArabic.woff2', 'assets/fonts/NotoSansArabic-OFL.txt',
    'assets/fonts/NotoNaskhArabic.woff2', 'assets/fonts/NotoNaskhArabic-OFL.txt',
    'core/.htaccess', 'core/index.php', 'core/bootstrap.php', 'core/Auth.php',
    'core/AssistantBridge.php', 'core/DomesticRates.php', 'core/CryptoEngine.php', 'core/DialogView.php',
    'core/JsonShardStore.php', 'core/MediaManager.php',
    'core/RoomMembership.php', 'core/StickerStore.php',
    'data/.htaccess', 'data/index.php',
]


def main():
    if len(FILES) != len(set(FILES)):
        raise RuntimeError('Duplicate ZIP entry.')
    if any(name.startswith('data/') and name not in ('data/.htaccess', 'data/index.php') for name in FILES):
        raise RuntimeError('Runtime data must NEVER be bundled.')
    for relative in FILES:
        path = ROOT / relative
        if not path.is_file() or path.is_symlink():
            raise RuntimeError('Missing or unsafe source file: ' + relative)
    docs = (ROOT / 'README-FA.md').read_text()
    if 'رمز اولیهٔ مدیر:' in docs or 'initial admin password:' in docs.lower():
        raise RuntimeError('Pre-shipped credentials found in installation guide.')
    with zipfile.ZipFile(OUTPUT, 'w', compression=zipfile.ZIP_DEFLATED, compresslevel=9) as archive:
        for relative in FILES:
            item = zipfile.ZipInfo(relative, (2026, 10, 31, 0, 0, 0))
            item.compress_type = zipfile.ZIP_DEFLATED
            item.external_attr = (stat.S_IFREG | 0o644) << 16
            archive.writestr(item, (ROOT / relative).read_bytes(), compress_type=zipfile.ZIP_DEFLATED, compresslevel=9)
    with zipfile.ZipFile(OUTPUT) as archive:
        bad = archive.testzip()
        if bad or archive.namelist() != FILES:
            raise RuntimeError(f'Invalid archive entry: {bad}')
    print(f'{OUTPUT}\n{len(FILES)} audited source files; {OUTPUT.stat().st_size:,} bytes')
    print('SHA-256:', sha256(OUTPUT.read_bytes()).hexdigest())


if __name__ == '__main__':
    main()
