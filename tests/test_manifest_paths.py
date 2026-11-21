import hashlib
import io
import json

import pytest

from face_inference import assets

DATA = b'asset'
INFO = {'sha256': hashlib.sha256(DATA).hexdigest(), 'size': len(DATA)}
INVALID = ['', '.', '..', '../marker', '/tmp/marker', 'a/b', r'a\b', 'C:marker', 'a\x00b', 'é']


def write_manifest(directory, files):
    (directory / 'manifest.json').write_text(json.dumps({'files': files}))


@pytest.mark.parametrize('key', INVALID + ['duplicate'])
@pytest.mark.parametrize('operation', ['verify', 'fetch-local', 'fetch-download'])
def test_invalid_names_before_asset_io(tmp_path, monkeypatch, key, operation):
    source = tmp_path / 'source'
    source.mkdir()
    dest = tmp_path / 'dest'
    dest.mkdir()
    marker = tmp_path / 'marker'
    marker.write_bytes(DATA)
    files = {'good': INFO, key: INFO}
    directory = source if operation == 'fetch-local' else dest
    write_manifest(directory, files)
    if key == 'duplicate':
        info = json.dumps(INFO)
        (directory / 'manifest.json').write_text(f'{{"files":{{"good":{info},"good":{info}}}}}')
    (source / 'good').write_bytes(DATA)
    monkeypatch.setattr(assets, 'digest', lambda path: pytest.fail(f'asset read: {path}'))
    monkeypatch.setattr(assets, 'urlopen', lambda *a, **kw: pytest.fail('network read'))
    with pytest.raises(RuntimeError, match='^Invalid asset manifest$'):
        if operation == 'verify':
            assets.verify(dest)
        else:
            assets.fetch(dest, from_dir=source if operation == 'fetch-local' else None)
    assert marker.read_bytes() == DATA
    assert not (dest / 'good').exists()
    assert not list(dest.glob('.model-fetch-*'))
    if key != 'duplicate':
        assert not assets._is_dir_valid(directory, {'files': files})


@pytest.mark.parametrize('where', ['source-leaf', 'dest-leaf'])
def test_fetch_rejects_symlinks(tmp_path, where):
    source, dest = tmp_path / 'source', tmp_path / 'dest'
    source.mkdir()
    dest.mkdir()
    write_manifest(source, {'model': INFO})
    (source / 'model').write_bytes(DATA)
    marker = tmp_path / 'marker'
    marker.write_bytes(DATA)
    if where == 'source-leaf':
        (source / 'model').unlink()
        (source / 'model').symlink_to(marker)
    else:
        (dest / 'model').symlink_to(marker)
    with pytest.raises(RuntimeError):
        assets.fetch(dest, from_dir=source)
    assert marker.read_bytes() == DATA
    assert not list(dest.glob('.model-fetch-*'))


def test_verify_rejects_symlink_leaf(tmp_path):
    directory = tmp_path / 'models'
    directory.mkdir()
    write_manifest(directory, {'model': INFO})
    marker = tmp_path / 'marker'
    marker.write_bytes(DATA)
    (directory / 'model').symlink_to(marker)
    assert not assets.verify(directory)
    assert not assets._is_dir_valid(directory)
    link = tmp_path / 'link'
    link.symlink_to(directory, target_is_directory=True)
    assert not assets.verify(link)
    assert not assets._is_dir_valid(link)
    assert marker.read_bytes() == DATA


@pytest.mark.parametrize('download', [False, True])
def test_valid_fetch_checksum_and_no_overwrite(tmp_path, monkeypatch, download):
    source, dest = tmp_path / 'source', tmp_path / 'dest'
    source.mkdir()
    dest.mkdir()
    write_manifest(source, {'model': INFO})
    write_manifest(dest, {'model': INFO})
    (source / 'model').write_bytes(DATA)

    class Response(io.BytesIO):
        def geturl(self):
            return 'https://example.com/model'

    monkeypatch.setattr(assets, 'urlopen', lambda *a, **kw: Response(DATA))
    kwargs = {'base_url': 'https://example.com'} if download else {'from_dir': source}
    assets.fetch(dest, **kwargs)
    assert assets.verify(dest)
    (dest / 'model').write_bytes(b'wrong')
    assert not assets.verify(dest)
    with pytest.raises(RuntimeError, match='wrong checksum'):
        assets.fetch(dest, **kwargs)
    assert (dest / 'model').read_bytes() == b'wrong'
    (dest / 'model').unlink()
    (source / 'model').write_bytes(b'wrong')
    monkeypatch.setattr(assets, 'urlopen', lambda *a, **kw: Response(b'wrong'))
    with pytest.raises(RuntimeError, match='checksum mismatch'):
        assets.fetch(dest, **kwargs)
    assert not (dest / 'model').exists()


@pytest.mark.parametrize('operation', ['verify', 'fetch-local', 'fetch-download'])
@pytest.mark.parametrize('contents', [
    '{', '{"files": []}', '{"files": {"model": {"sha256": "a", "sha256": "b"}}}',
])
def test_malformed_manifest(tmp_path, operation, contents):
    source, dest = tmp_path / 'source', tmp_path / 'dest'
    source.mkdir()
    dest.mkdir()
    directory = source if operation == 'fetch-local' else dest
    (directory / 'manifest.json').write_text(contents)
    with pytest.raises(RuntimeError, match='^Invalid asset manifest$'):
        if operation == 'verify':
            assets.verify(dest)
        else:
            assets.fetch(dest, from_dir=source if operation == 'fetch-local' else None)
    assert not (dest / 'model').exists()


@pytest.mark.parametrize('operation', ['verify', 'fetch-local', 'fetch-download'])
def test_rejects_symlink_manifest(tmp_path, operation):
    source, dest = tmp_path / 'source', tmp_path / 'dest'
    source.mkdir()
    dest.mkdir()
    marker = tmp_path / 'marker'
    marker.write_text(json.dumps({'files': {'model': INFO}}))
    directory = source if operation == 'fetch-local' else dest
    (directory / 'manifest.json').symlink_to(marker)
    with pytest.raises(RuntimeError):
        if operation == 'verify':
            assets.verify(dest)
        else:
            assets.fetch(dest, from_dir=source if operation == 'fetch-local' else None)
    assert marker.read_text() == json.dumps({'files': {'model': INFO}})


@pytest.mark.parametrize('info', [
    None, {}, {'sha256': 'bad'}, {'sha256': INFO['sha256'], 'size': -1},
])
def test_invalid_metadata_before_any_asset_write(tmp_path, info):
    source, dest = tmp_path / 'source', tmp_path / 'dest'
    source.mkdir()
    write_manifest(source, {'good': INFO, 'bad': info})
    (source / 'good').write_bytes(DATA)
    with pytest.raises(RuntimeError, match='^Invalid asset manifest$'):
        assets.fetch(dest, from_dir=source)
    assert not (dest / 'good').exists()


def test_local_fetch_rejects_destination_manifest_symlink_before_copy(tmp_path):
    source, dest = tmp_path / 'source', tmp_path / 'dest'
    source.mkdir()
    dest.mkdir()
    write_manifest(source, {'model': INFO})
    (source / 'model').write_bytes(DATA)
    marker = tmp_path / 'marker'
    marker.write_text('outside')
    (dest / 'manifest.json').symlink_to(marker)
    with pytest.raises(RuntimeError):
        assets.fetch(dest, from_dir=source)
    assert not (dest / 'model').exists()
    assert marker.read_text() == 'outside'


def test_symlinked_ancestor_allows_verify_and_fetch(tmp_path):
    real = tmp_path / 'real'
    real.mkdir()
    link = tmp_path / 'link'
    link.symlink_to(real, target_is_directory=True)
    source = link / 'source'
    source.mkdir()
    write_manifest(source, {'model': INFO})
    (source / 'model').write_bytes(DATA)
    models = link / 'models'
    assets.fetch(models, from_dir=source)
    assert assets.verify(models)['status'] == 'PASS'


def test_cli_invalid_manifest_is_one_error_line(tmp_path, monkeypatch, capsys):
    from face_inference import cli

    (tmp_path / 'manifest.json').write_text('{')
    monkeypatch.setenv('FACE_INFERENCE_MODELS_DIR', str(tmp_path))
    monkeypatch.setattr(cli, 'load_config', lambda *args, **kwargs: {})
    monkeypatch.setattr('sys.argv', ['face-inference', 'models', 'verify'])
    assert cli.main() == 1
    captured = capsys.readouterr()
    assert captured.out == ''
    assert captured.err == 'Error: Invalid asset manifest\n'
    assert 'Traceback' not in captured.err
