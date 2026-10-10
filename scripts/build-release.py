#!/usr/bin/env python3
"""Build a production ZIP with locked dependencies and operator instructions."""
import argparse
import hashlib
import json
from pathlib import Path
import re
import shutil
import subprocess
import tempfile
import zipfile

ROOT = Path(__file__).resolve().parents[1]


def main():
    parser = argparse.ArgumentParser(description=__doc__)
    parser.add_argument('--production-vendor', type=Path, help='Reuse a verified no-dev vendor directory; otherwise install the lock in a temporary copy.')
    args = parser.parse_args()
    version = re.search(r'^ \* Version: (\S+)', (ROOT / 'wc-invoice-printer.php').read_text(), re.M).group(1)
    output = ROOT / 'build' / ('wc-invoice-printer-' + version + '.zip')
    with tempfile.TemporaryDirectory(prefix='wcip-release-') as temp:
        stage = Path(temp) / 'wc-invoice-printer'
        stage.mkdir()
        for directory in ('src', 'templates', 'assets', 'languages'):
            shutil.copytree(ROOT / directory, stage / directory)
        (stage / 'docs').mkdir()
        for filename in ('open-source-printing.md', 'cross-platform-printing.md', 'operations.md'):
            shutil.copy2(ROOT / 'docs' / filename, stage / 'docs' / filename)
        (stage / 'print-agent').mkdir()
        for filename in ('wcip_agent.py', 'config.example.json', 'README.md', 'LICENSE'):
            shutil.copy2(ROOT / 'print-agent' / filename, stage / 'print-agent' / filename)
        for filename in ('wc-invoice-printer.php', 'uninstall.php', 'readme.txt', 'README.md', 'LICENSE', 'LICENSE.md', 'LICENSE.txt'):
            if (ROOT / filename).is_file():
                shutil.copy2(ROOT / filename, stage / filename)
        for filename in ('composer.json', 'composer.lock'):
            shutil.copy2(ROOT / filename, stage / filename)
        if args.production_vendor:
            shutil.copytree(args.production_vendor.resolve(), stage / 'vendor')
        else:
            subprocess.run(['composer', 'install', '--no-dev', '--prefer-dist', '--optimize-autoloader', '--no-interaction', '--no-scripts'], cwd=stage, check=True)
            subprocess.run(['composer', 'check-platform-reqs', '--no-dev'], cwd=stage, check=True)
        # Reused dependencies may contain an optimized map for an older source tree.
        subprocess.run(['composer', 'dump-autoload', '--no-dev', '--optimize', '--no-interaction', '--no-scripts'], cwd=stage, check=True)
        for filename in ('composer.json', 'composer.lock'):
            (stage / filename).unlink()
        installed = json.loads((stage / 'vendor/composer/installed.json').read_text())
        locked = json.loads((ROOT / 'composer.lock').read_text())['packages']
        expected = {p['name']: p['version'] for p in locked}
        actual = {p['name']: p['version'] for p in installed['packages']}
        if installed.get('dev', True) or actual != expected:
            raise ValueError('Vendor must contain exactly the locked production packages, without development dependencies.')
        output.parent.mkdir(exist_ok=True)
        with zipfile.ZipFile(output, 'w', zipfile.ZIP_DEFLATED, compresslevel=9) as archive:
            for path in sorted(stage.rglob('*')):
                if not path.is_file():
                    continue
                relative = path.relative_to(stage)
                if any(part.lower() in ('tests', 'test', '.git', '.github', '__pycache__', 'node_modules') for part in relative.parts):
                    continue
                if path.name in ('phpunit.xml', 'phpunit.xml.dist', '.travis.yml'):
                    continue
                info = zipfile.ZipInfo('wc-invoice-printer/' + relative.as_posix(), date_time=(2026, 10, 10, 0, 0, 0))
                info.external_attr = 0o100644 << 16
                info.compress_type = zipfile.ZIP_DEFLATED
                archive.writestr(info, path.read_bytes())
        with zipfile.ZipFile(output) as archive:
            if archive.testzip():
                raise ValueError('ZIP integrity failed.')
        checksum = hashlib.sha256(output.read_bytes()).hexdigest()
        output.with_suffix('.zip.sha256').write_text(checksum + '  ' + output.name + '\n')
        print(str(output))
        print(checksum)


if __name__ == '__main__':
    main()
