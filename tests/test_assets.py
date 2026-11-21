import hashlib
import json

import pytest

from face_inference.config import verify_assets


def test_verify_assets_rejects_tampered_runtime(tmp_path):
    web = tmp_path
    (web / 'browser-face').mkdir()
    (web / 'browser-face/contract.json').write_text('{}')
    assets = web / 'vendor/browser-face'
    assets.mkdir(parents=True)
    names = ('sface.onnx', 'yunet.onnx', 'ort.wasm.min.mjs',
             'ort-wasm-simd-threaded.mjs', 'ort-wasm-simd-threaded.wasm')
    manifest = {}
    for name in names:
        (assets / name).write_bytes(b'good')
        manifest[name] = hashlib.sha256(b'good').hexdigest()
    (assets / 'manifest.json').write_text(json.dumps(manifest))
    assert verify_assets(web) == {}
    (assets / 'ort-wasm-simd-threaded.wasm').write_bytes(b'bad')
    with pytest.raises(RuntimeError, match='checksum mismatch'):
        verify_assets(web)
