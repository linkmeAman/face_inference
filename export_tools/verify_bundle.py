#!/usr/bin/env python3
"""Verify copied source/assets and the original public dataset without external access."""
import hashlib
import json
from pathlib import Path
import sys

ROOT = Path(__file__).resolve().parents[1]


def digest(path):
    return hashlib.sha256(path.read_bytes()).hexdigest()


def main():
    manifest = json.loads((ROOT / 'snapshot/export-manifest.json').read_text())
    failed = []
    for item in manifest['original_files']:
        path = ROOT / item['path']
        if not path.is_file() or path.is_symlink() or digest(path) != item['sha256']:
            failed.append(item['path'])
    data_root = ROOT / 'datasets/controlface10k-subset'
    dataset = json.loads((data_root / 'manifest.json').read_text())
    for item in dataset['images']:
        path = data_root / item['file']
        if not path.is_file() or digest(path) != item['sha256']:
            failed.append('dataset:' + item['file'])
    assets = ROOT / 'pf/vendor/browser-face'
    for name, expected in json.loads((assets / 'manifest.json').read_text()).items():
        if digest(assets / name) != expected:
            failed.append('browser-model:' + name)
    if (ROOT / 'snapshot/bundle-files.json').exists():
        for item in json.loads((ROOT / 'snapshot/bundle-files.json').read_text()):
            path = ROOT / item['path']
            if not path.is_file() or path.is_symlink() or digest(path) != item['sha256']:
                failed.append('bundle:' + item['path'])
    if failed:
        print(json.dumps({'status': 'FAIL', 'mismatches': failed}, indent=2))
        return 1
    print(json.dumps({'status':'PASS', 'original_files':len(manifest['original_files']),
        'dataset_images':len(dataset['images']), 'dataset_identities':len({i['identity'] for i in dataset['images']}),
        'prepared_browser_model_hashes':'verified'}))
    return 0


if __name__ == '__main__':
    sys.exit(main())
