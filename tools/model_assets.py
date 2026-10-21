#!/usr/bin/env python3
"""Verify or restore checksum-pinned local face models and browser runtimes.

verify is offline. restore downloads only missing assets from the recorded sources.
Existing files with a wrong hash are never overwritten. onnx is needed only to
rebuild a missing prepared browser SFace graph from its original weights.
"""
import argparse
import base64
import hashlib
import json
import os
from pathlib import Path, PurePosixPath
import tarfile
import tempfile
from urllib.request import Request, urlopen


def digest(path):
    result = hashlib.sha256()
    with path.open('rb') as stream:
        for block in iter(lambda: stream.read(1024 * 1024), b''):
            result.update(block)
    return result.hexdigest()


def records(root):
    native = json.loads((root / 'services/face-inference/models/MODEL-MANIFEST.json').read_text())
    prepared = json.loads((root / 'pf/vendor/browser-face/manifest.json').read_text())
    tracker = json.loads((root / 'pf/vendor/face-landmarker/0.10.34/ASSET-MANIFEST.json').read_text())
    provenance = json.loads((root / 'snapshot/export-manifest.json').read_text())
    exported = {item['path']: item['sha256'] for item in provenance['original_files']}
    result = []
    for role in ('detector', 'recognizer', 'pad'):
        item = native[role]
        if Path(item['file']).name != item['file']:
            raise RuntimeError('Invalid original model filename')
        result.append(dict(path='services/face-inference/models/' + item['file'], sha256=item['sha256'],
            profile='python', role=role, source=item['source_url']))
    for filename, expected in prepared.items():
        if Path(filename).name != filename:
            raise RuntimeError('Invalid prepared model filename')
        result.append(dict(path='pf/vendor/browser-face/' + filename, sha256=expected,
            profile='browser', role='prepared-' + filename))
    for filename in ('ort.wasm.min.mjs', 'ort-wasm-simd-threaded.mjs', 'ort-wasm-simd-threaded.wasm'):
        path = 'pf/vendor/browser-face/' + filename
        result.append(dict(path=path, sha256=exported[path], profile='browser', role='ort'))
    for filename, expected in tracker['files_sha256'].items():
        if '..' in PurePosixPath(filename).parts or PurePosixPath(filename).is_absolute():
            raise RuntimeError('Invalid MediaPipe asset path')
        result.append(dict(path='pf/vendor/face-landmarker/0.10.34/' + filename, sha256=expected,
            profile='mediapipe', role='landmarker-model' if filename == 'face_landmarker.task' else 'landmarker-runtime',
            source=tracker['model_source'] if filename == 'face_landmarker.task' else None))
    return result, tracker


def fetch(url, destination, sha256=None, integrity=None):
    if not url.startswith('https://'):
        raise RuntimeError('Only recorded HTTPS artifact sources are supported')
    sha = hashlib.sha256()
    sri = hashlib.sha512()
    length = 0
    request = Request(url, headers={'User-Agent': 'face-inference-model-restore/1'})
    with urlopen(request, timeout=60) as response, destination.open('xb') as output:
        if not response.geturl().startswith('https://'):
            raise RuntimeError('Refusing a non-HTTPS artifact redirect')
        for chunk in iter(lambda: response.read(1024 * 1024), b''):
            length += len(chunk)
            if length > 512 * 1024 * 1024:
                raise RuntimeError('Artifact exceeds the download size bound')
            sha.update(chunk)
            sri.update(chunk)
            output.write(chunk)
    if sha256 is not None and sha.hexdigest() != sha256:
        raise RuntimeError('Downloaded artifact checksum differs from the recorded copy: ' + url)
    if integrity is not None:
        if not integrity.startswith('sha512-') or base64.b64encode(sri.digest()).decode() != integrity[7:]:
            raise RuntimeError('Package archive integrity differs from its recorded lockfile')


def install(data, target, expected):
    if hashlib.sha256(data).hexdigest() != expected:
        raise RuntimeError('Reconstructed asset checksum differs: ' + str(target))
    if target.exists():
        if digest(target) != expected:
            raise RuntimeError('Existing asset is corrupt; restore it explicitly from Git: ' + str(target))
        return
    target.parent.mkdir(parents=True, exist_ok=True)
    # Publish a completely written file without replacing a concurrent writer.
    with tempfile.NamedTemporaryFile(dir=target.parent, prefix='.model-restore-', delete=False) as stream:
        temporary = Path(stream.name)
        stream.write(data)
    try:
        try:
            os.link(temporary, target)
        except FileExistsError:
            if digest(target) != expected:
                raise RuntimeError('Concurrent asset has a different checksum: ' + str(target))
    finally:
        temporary.unlink()


def restore(root, selected, all_records, tracker):
    for item in selected:
        path = root / item['path']
        if path.exists() and digest(path) != item['sha256']:
            raise RuntimeError('Existing asset has a wrong checksum; no files replaced: ' + item['path'])
    native = {item['role']: item for item in all_records if item['profile'] == 'python'}
    with tempfile.TemporaryDirectory(prefix='face-model-restore-') as staging:
        staging = Path(staging)
        archives = {}

        def original(role):
            item = native[role]
            path = root / item['path']
            if path.exists():
                if digest(path) != item['sha256']:
                    raise RuntimeError('Original model checksum differs: ' + item['path'])
            else:
                downloaded = staging / (role + '.onnx')
                fetch(item['source'], downloaded, sha256=item['sha256'])
                install(downloaded.read_bytes(), path, item['sha256'])
            return path

        def archive(kind):
            if kind not in archives:
                destination = staging / (kind + '.tgz')
                if kind == 'ort':
                    package = json.loads((root / 'package-lock.json').read_text())['packages']['node_modules/onnxruntime-web']
                    fetch(package['resolved'], destination, integrity=package['integrity'])
                else:
                    version = tracker['version']
                    if not all(part.isdigit() for part in version.split('.')):
                        raise RuntimeError('Invalid MediaPipe package version')
                    fetch('https://registry.npmjs.org/@mediapipe/tasks-vision/-/tasks-vision-' + version + '.tgz',
                        destination, sha256=tracker['package_archive_sha256'], integrity=tracker['npm_integrity'])
                archives[kind] = destination
            return archives[kind]

        for item in selected:
            target = root / item['path']
            if target.exists():
                continue
            role = item['role']
            if item['profile'] == 'python':
                original(role)
                continue
            if role == 'prepared-yunet.onnx':
                data = original('detector').read_bytes()
            elif role == 'prepared-sface.onnx':
                try:
                    import onnx
                except ImportError as error:
                    raise RuntimeError('Missing prepared SFace graph: run restore with the documented inference venv containing onnx') from error
                model = onnx.load(str(original('recognizer')))
                inputs = [value for value in model.graph.input if value.name == 'data']
                if len(inputs) != 1:
                    raise RuntimeError('Expected one SFace data input')
                del model.graph.input[:]
                model.graph.input.extend(inputs)
                onnx.checker.check_model(model)
                output = staging / 'sface-prepared.onnx'
                onnx.save(model, str(output))
                data = output.read_bytes()
            elif role == 'landmarker-model':
                output = staging / 'face_landmarker.task'
                fetch(item['source'], output, sha256=item['sha256'])
                data = output.read_bytes()
            else:
                kind = 'ort' if role == 'ort' else 'mediapipe'
                relative = target.name if kind == 'ort' else item['path'].split('/0.10.34/', 1)[1]
                names = ['package/dist/' + relative] if kind == 'ort' else ['package/' + relative]
                if relative == 'vision_bundle.js':
                    names.append('package/vision_bundle.mjs')
                data = None
                with tarfile.open(archive(kind), 'r:gz') as package:
                    for candidate in names:
                        try:
                            member = package.getmember(candidate)
                        except KeyError:
                            continue
                        if not member.isfile() or member.size > 64 * 1024 * 1024:
                            raise RuntimeError('Unexpected package member')
                        with package.extractfile(member) as content:
                            candidate_data = content.read()
                        if hashlib.sha256(candidate_data).hexdigest() == item['sha256']:
                            data = candidate_data
                            break
                if data is None:
                    raise RuntimeError('Pinned package is missing the recorded runtime asset bytes')
            install(data, target, item['sha256'])


def main():
    parser = argparse.ArgumentParser(description=__doc__)
    parser.add_argument('command', choices=('verify', 'restore'))
    parser.add_argument('--profile', choices=('all', 'browser', 'python', 'mediapipe'), default='all')
    parser.add_argument('--root', type=Path, default=Path(__file__).resolve().parents[1], help='Project root; defaults to this script’s parent project')
    args = parser.parse_args()
    try:
        root = args.root.resolve()
        inventory, tracker = records(root)
        selected = [item for item in inventory if args.profile == 'all' or item['profile'] == args.profile]
        if args.command == 'restore':
            restore(root, selected, inventory, tracker)
        failed = []
        for item in selected:
            path = root / item['path']
            if not path.is_file() or path.is_symlink():
                failed.append(dict(path=item['path'], reason='MISSING_OR_SYMLINK'))
            elif digest(path) != item['sha256']:
                failed.append(dict(path=item['path'], reason='CHECKSUM_MISMATCH'))
        print(json.dumps(dict(status='FAIL' if failed else 'PASS', profile=args.profile,
            checked_assets=len(selected), failures=failed), indent=2))
        return 1 if failed else 0
    except Exception as error:
        print('Model setup failed: ' + str(error))
        return 1


if __name__ == '__main__':
    raise SystemExit(main())
