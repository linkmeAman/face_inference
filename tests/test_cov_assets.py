import hashlib
import io
import json
from urllib.error import URLError

import pytest

from face_inference import assets


def test_download_checks_and_cleanup(tmp_path, monkeypatch):
    data = b'model'
    manifest = {'files': {'model': {'sha256': hashlib.sha256(data).hexdigest(), 'size': len(data)}}}
    (tmp_path / 'manifest.json').write_text(json.dumps(manifest))
    class Response(io.BytesIO):
        def geturl(self): return 'https://example.com/model'
    monkeypatch.setattr(assets, 'urlopen', lambda *args, **kwargs: Response(data))
    assets.fetch(tmp_path, base_url='https://example.com')
    assert (tmp_path / 'model').read_bytes() == data
    assets.fetch(tmp_path, base_url='https://example.com')
    (tmp_path / 'model').unlink()
    monkeypatch.setattr(assets, 'urlopen', lambda *args, **kwargs: Response(b'wrong'))
    with pytest.raises(RuntimeError, match='checksum mismatch'):
        assets.fetch(tmp_path, base_url='https://example.com')
    assert not list(tmp_path.glob('.model-fetch-*'))
    with pytest.raises(RuntimeError, match='Only HTTPS'):
        assets.fetch(tmp_path, base_url='http://example.com')
    def fail(*args, **kwargs):
        raise URLError('offline')
    monkeypatch.setattr(assets, 'urlopen', fail)
    with pytest.raises(RuntimeError, match='Network failure'):
        assets.fetch(tmp_path, base_url='https://example.com')
    class Redirect(Response):
        def geturl(self): return 'http://example.com/model'
    monkeypatch.setattr(assets, 'urlopen', lambda *args, **kwargs: Redirect(data))
    with pytest.raises(RuntimeError, match='redirect'):
        assets.fetch(tmp_path, base_url='https://example.com')
    assert not list(tmp_path.glob('.model-fetch-*'))


def test_directory_validation_and_missing_manifest(tmp_path, monkeypatch):
    monkeypatch.setattr(assets, 'PACKAGED_VENDOR_DIR', tmp_path / 'absent')
    with pytest.raises(RuntimeError, match='manifest not found'):
        assets.load_manifest()
    assert not assets._is_dir_valid(tmp_path)
    manifest = {'files': {'x': {'sha256': hashlib.sha256(b'x').hexdigest(), 'size': 1}}}
    assert not assets._is_dir_valid(tmp_path, manifest)
    (tmp_path / 'x').write_bytes(b'y')
    assert not assets._is_dir_valid(tmp_path, manifest)
    (tmp_path / 'x').write_bytes(b'x')
    assert assets._is_dir_valid(tmp_path, manifest)
