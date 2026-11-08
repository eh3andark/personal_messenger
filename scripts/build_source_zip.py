#!/usr/bin/env python3
"""Create a GitHub-ready source archive without runtime data or secrets.

The source ZIP has a top-level hamrah-messenger/ folder. The separate cPanel
ZIP contains only deployment files and has no top-level folder.
"""
from hashlib import sha256
from pathlib import Path
import stat
import zipfile

from build_cpanel_zip import FILES as DEPLOY_FILES, ROOT

OUTPUT = ROOT.parent / 'hamrah-messenger-source.zip'
SOURCE_ONLY = [
    '.gitignore', '.github/workflows/ci.yml',
    'router.php', 'data/.gitkeep',
    'scripts/build_cpanel_zip.py', 'scripts/build_source_zip.py',
    'scripts/fetch_open_fonts.py', 'scripts/build_icon_sprite.py',
    'tests/integration.py', 'tests/new_features.py', 'tests/review_features.py',
    'tests/browser.cjs', 'tests/browser_features.cjs', 'tests/browser_review.cjs',
    'tests/telegram_preview.php', 'tests/service_worker.cjs',
    'tests/account_activity.py', 'tests/browser_activity.cjs',
]
FILES = DEPLOY_FILES + SOURCE_ONLY


def main():
    if len(FILES) != len(set(FILES)):
        raise RuntimeError('Duplicate source ZIP entry.')
    extra_docs = [p.relative_to(ROOT).as_posix() for p in ROOT.rglob('*.md') if p != ROOT / 'README.md']
    if extra_docs:
        raise RuntimeError('Unexpected Markdown files: ' + ', '.join(sorted(extra_docs)))
    safe_data = {'data/.htaccess', 'data/index.php', 'data/.gitkeep'}
    if any(p.startswith('data/') and p not in safe_data for p in FILES):
        raise RuntimeError('Runtime data must NEVER be included.')
    with zipfile.ZipFile(OUTPUT, 'w', compression=zipfile.ZIP_DEFLATED, compresslevel=9) as archive:
        for relative in FILES:
            path = ROOT / relative
            if not path.is_file() or path.is_symlink():
                raise RuntimeError('Missing or unsafe source: ' + relative)
            item = zipfile.ZipInfo('hamrah-messenger/' + relative, (2026, 10, 31, 0, 0, 0))
            item.compress_type = zipfile.ZIP_DEFLATED
            item.external_attr = (stat.S_IFREG | 0o644) << 16
            archive.writestr(item, path.read_bytes(), compress_type=zipfile.ZIP_DEFLATED, compresslevel=9)
    with zipfile.ZipFile(OUTPUT) as archive:
        if archive.testzip() or archive.namelist() != ['hamrah-messenger/' + p for p in FILES]:
            raise RuntimeError('The source ZIP failed its integrity/allowlist check.')
    print(f'{OUTPUT}\n{len(FILES)} audited files; {OUTPUT.stat().st_size:,} bytes')
    print('SHA-256:', sha256(OUTPUT.read_bytes()).hexdigest())


if __name__ == '__main__':
    main()
