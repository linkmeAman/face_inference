import hashlib
import json
import threading
from http.server import SimpleHTTPRequestHandler, ThreadingHTTPServer

import pytest

from face_inference.assets import fetch, models_dir, verify
from face_inference.cli import main as cli_main
from face_inference.config import verify_assets


def test_verify_assets_rejects_tampered_runtime(tmp_path):
    web = tmp_path
    (web / 'browser-face').mkdir()
    (web / 'browser-face/contract.json').write_text('{}')
    assets = web / 'vendor/browser-face'
    assets.mkdir(parents=True)
    names = ('sface.onnx', 'yunet.onnx', 'ort.wasm.min.mjs',
             'ort-wasm-simd-threaded.mjs', 'ort-wasm-simd-threaded.wasm')
    files_map = {}
    for name in names:
        (assets / name).write_bytes(b'good')
        files_map[name] = {
            'sha256': hashlib.sha256(b'good').hexdigest(),
            'size': 4,
        }
    manifest = {
        'version': 1,
        'base_url': 'https://github.com/linkmeAman/face_inference/releases/download/models-v1/',
        'files': files_map,
    }
    (assets / 'manifest.json').write_text(json.dumps(manifest))
    assert verify_assets(web) == {}
    (assets / 'ort-wasm-simd-threaded.wasm').write_bytes(b'bad')
    with pytest.raises(RuntimeError, match='checksum mismatch'):
        verify_assets(web)


def test_models_dir_resolution(tmp_path, monkeypatch):
    custom_dir = tmp_path / 'custom_models'
    monkeypatch.setenv('FACE_INFERENCE_MODELS_DIR', str(custom_dir))
    assert models_dir() == custom_dir

    monkeypatch.delenv('FACE_INFERENCE_MODELS_DIR', raising=False)
    home_dir = tmp_path / 'home'
    monkeypatch.setenv('FACE_INFERENCE_HOME', str(home_dir))

    # In dev checkout, packaged dir has valid assets
    resolved = models_dir()
    assert resolved.exists()
    assert (resolved / 'sface.onnx').exists()


def test_verify_packaged_assets():
    mdir = models_dir()
    res = verify(mdir)
    assert res['status'] == 'PASS'
    assert bool(res) is True
    assert res['failures'] == []
    assert res['checked_assets'] >= 5


def test_verify_detects_failures(tmp_path):
    mdir = tmp_path / 'models'
    mdir.mkdir()
    manifest = {
        'version': 1,
        'base_url': 'https://example.com/',
        'files': {
            'file1.bin': {'sha256': hashlib.sha256(b'hello').hexdigest(), 'size': 5},
            'file2.bin': {'sha256': hashlib.sha256(b'world').hexdigest(), 'size': 5},
            'file3.bin': {'sha256': hashlib.sha256(b'sym').hexdigest(), 'size': 3},
        }
    }
    (mdir / 'manifest.json').write_text(json.dumps(manifest))
    (mdir / 'file1.bin').write_bytes(b'hello')
    (mdir / 'file2.bin').write_bytes(b'corrupt')
    real_sym = tmp_path / 'real_sym.bin'
    real_sym.write_bytes(b'sym')
    (mdir / 'file3.bin').symlink_to(real_sym)

    res = verify(mdir)
    assert res['status'] == 'FAIL'
    assert bool(res) is False
    reasons = {f['path']: f['reason'] for f in res['failures']}
    assert reasons['file2.bin'] == 'CHECKSUM_MISMATCH'
    assert reasons['file3.bin'] == 'MISSING_OR_SYMLINK'


def test_fetch_from_dir(tmp_path):
    source_dir = tmp_path / 'source'
    source_dir.mkdir()
    content = b'model binary content'
    digest = hashlib.sha256(content).hexdigest()
    (source_dir / 'sface.onnx').write_bytes(content)
    (source_dir / 'yunet.onnx').write_bytes(content)
    (source_dir / 'ort.wasm.min.mjs').write_bytes(content)
    (source_dir / 'ort-wasm-simd-threaded.mjs').write_bytes(content)
    (source_dir / 'ort-wasm-simd-threaded.wasm').write_bytes(content)
    (source_dir / 'ORT-LICENSE').write_bytes(b'license')

    manifest = {
        'version': 1,
        'base_url': 'https://example.com/models/',
        'files': {
            'sface.onnx': {'sha256': digest, 'size': len(content)},
            'yunet.onnx': {'sha256': digest, 'size': len(content)},
            'ort.wasm.min.mjs': {'sha256': digest, 'size': len(content)},
            'ort-wasm-simd-threaded.mjs': {'sha256': digest, 'size': len(content)},
            'ort-wasm-simd-threaded.wasm': {'sha256': digest, 'size': len(content)},
            'ORT-LICENSE': {'sha256': hashlib.sha256(b'license').hexdigest(), 'size': 7},
        }
    }
    (source_dir / 'manifest.json').write_text(json.dumps(manifest))

    dest_dir = tmp_path / 'dest'
    # Copy manifest into dest_dir or let fetch load from source
    dest_dir.mkdir()
    (dest_dir / 'manifest.json').write_text(json.dumps(manifest))

    fetch(dest_dir, from_dir=source_dir)
    res = verify(dest_dir)
    assert res['status'] == 'PASS'
    assert (dest_dir / 'sface.onnx').read_bytes() == content

    # Idempotent second run
    fetch(dest_dir, from_dir=source_dir)
    assert (dest_dir / 'sface.onnx').read_bytes() == content


def test_fetch_http_server(tmp_path):
    serve_dir = tmp_path / 'http_source'
    serve_dir.mkdir()
    content = b'downloaded model content'
    digest = hashlib.sha256(content).hexdigest()
    (serve_dir / 'sface.onnx').write_bytes(content)

    manifest = {
        'version': 1,
        'base_url': 'http://example.com/',
        'files': {
            'sface.onnx': {'sha256': digest, 'size': len(content)},
        }
    }
    dest_dir = tmp_path / 'dest_http'
    dest_dir.mkdir()
    (dest_dir / 'manifest.json').write_text(json.dumps(manifest))

    class QuietHandler(SimpleHTTPRequestHandler):
        def log_message(self, *args, **kwargs):
            pass

    import functools
    handler = functools.partial(QuietHandler, directory=str(serve_dir))
    server = ThreadingHTTPServer(('127.0.0.1', 0), handler)
    port = server.server_port
    thread = threading.Thread(target=server.serve_forever, daemon=True)
    thread.start()
    try:
        base_url = f'http://127.0.0.1:{port}/'
        # non-HTTPS rejected when _allow_http=False
        with pytest.raises(RuntimeError, match='HTTPS'):
            fetch(dest_dir, base_url=base_url, _allow_http=False)

        # succeeds with _allow_http=True
        fetch(dest_dir, base_url=base_url, _allow_http=True)
        assert (dest_dir / 'sface.onnx').read_bytes() == content
    finally:
        server.shutdown()
        server.server_close()
        thread.join(timeout=3)


def test_fetch_rejects_corrupt_download_and_cleans_temp(tmp_path):
    serve_dir = tmp_path / 'corrupt_source'
    serve_dir.mkdir()
    (serve_dir / 'sface.onnx').write_bytes(b'corrupted')

    manifest = {
        'version': 1,
        'base_url': 'http://example.com/',
        'files': {
            'sface.onnx': {'sha256': hashlib.sha256(b'expected').hexdigest(), 'size': 8},
        }
    }
    dest_dir = tmp_path / 'dest_corrupt'
    dest_dir.mkdir()
    (dest_dir / 'manifest.json').write_text(json.dumps(manifest))

    class QuietHandler(SimpleHTTPRequestHandler):
        def log_message(self, *args, **kwargs):
            pass

    import functools
    handler = functools.partial(QuietHandler, directory=str(serve_dir))
    server = ThreadingHTTPServer(('127.0.0.1', 0), handler)
    port = server.server_port
    thread = threading.Thread(target=server.serve_forever, daemon=True)
    thread.start()
    try:
        base_url = f'http://127.0.0.1:{port}/'
        with pytest.raises(RuntimeError):
            fetch(dest_dir, base_url=base_url, _allow_http=True)
        assert not (dest_dir / 'sface.onnx').exists()
        # Verify no temp files remain
        temp_files = list(dest_dir.glob('.model-fetch-*'))
        assert temp_files == []
    finally:
        server.shutdown()
        server.server_close()
        thread.join(timeout=3)


def test_fetch_never_overwrites_existing_bad_file(tmp_path):
    source_dir = tmp_path / 'src'
    source_dir.mkdir()
    (source_dir / 'sface.onnx').write_bytes(b'good_content')
    digest = hashlib.sha256(b'good_content').hexdigest()

    manifest = {
        'version': 1,
        'base_url': 'https://example.com/',
        'files': {
            'sface.onnx': {'sha256': digest, 'size': 12},
        }
    }
    dest_dir = tmp_path / 'dest_bad'
    dest_dir.mkdir()
    (dest_dir / 'manifest.json').write_text(json.dumps(manifest))
    existing_bad = dest_dir / 'sface.onnx'
    existing_bad.write_bytes(b'preexisting_corrupt_content')

    with pytest.raises(RuntimeError, match='checksum|corrupt|overwrite'):
        fetch(dest_dir, from_dir=source_dir)

    # Content must NOT be overwritten
    assert existing_bad.read_bytes() == b'preexisting_corrupt_content'


def test_cli_models_commands(tmp_path, monkeypatch, capsys):
    empty_home = tmp_path / 'empty_home'
    empty_home.mkdir()
    monkeypatch.setenv('FACE_INFERENCE_HOME', str(empty_home))
    monkeypatch.setenv('FACE_INFERENCE_MODELS_DIR', str(empty_home / 'models'))

    # serve exits 1 and prints guidance when models missing
    monkeypatch.setattr('sys.argv', ['face-inference', 'serve'])
    ret = cli_main()
    assert ret == 1
    captured = capsys.readouterr()
    assert 'Run: face-inference models fetch' in (captured.out + captured.err)

    # check prints status assets-missing
    monkeypatch.setattr('sys.argv', ['face-inference', 'check'])
    ret = cli_main()
    assert ret == 0
    captured = capsys.readouterr()
    check_out = json.loads(captured.out)
    assert check_out['status'] == 'assets-missing'

    # models path
    monkeypatch.setattr('sys.argv', ['face-inference', 'models', 'path'])
    ret = cli_main()
    assert ret == 0
    captured = capsys.readouterr()
    assert str(empty_home / 'models') in captured.out

    # models verify fails on empty dir
    monkeypatch.setattr('sys.argv', ['face-inference', 'models', 'verify'])
    ret = cli_main()
    assert ret == 1
