import json
import os
import sys
from pathlib import Path
from unittest.mock import Mock

import pytest

from face_inference import cli
from face_inference import config as config_module
from face_inference.config import load_config

WARNING = 'listening on a non-loopback address: put TLS in front (camera needs HTTPS)'


@pytest.fixture
def home_environment(tmp_path, monkeypatch):
    monkeypatch.chdir(tmp_path)
    monkeypatch.setattr(Path, 'home', classmethod(lambda cls: tmp_path / 'user'))
    monkeypatch.setattr(sys, 'platform', 'linux')
    for name in ('FACE_INFERENCE_HOME', 'FACE_STANDALONE_CONFIG',
                 'FACE_INFERENCE_MODELS_DIR', 'XDG_DATA_HOME', 'APPDATA'):
        monkeypatch.delenv(name, raising=False)
    return tmp_path


@pytest.mark.parametrize('platform, variable, relative', [
    ('darwin', None, 'user/Library/Application Support/face-inference'),
    ('linux', None, 'user/.local/share/face-inference'),
    ('linux', 'XDG_DATA_HOME', 'custom/face-inference'),
    ('linux', 'EMPTY_XDG', 'user/.local/share/face-inference'),
    ('win32', None, 'user/AppData/Roaming/face-inference'),
    ('win32', 'APPDATA', 'custom/face-inference'),
])
def test_default_home_platforms(home_environment, monkeypatch, platform, variable, relative):
    monkeypatch.setattr(sys, 'platform', platform)
    if variable == 'EMPTY_XDG':
        monkeypatch.setenv('XDG_DATA_HOME', '')
    elif variable:
        monkeypatch.setenv(variable, str(home_environment / 'custom'))
    expected = home_environment / relative
    assert config_module.default_home() == expected
    config = load_config()
    assert config['_path'] == str(expected / 'config.json')
    assert config['database'] == str(expected / 'faces.sqlite3')
    assert not (home_environment / '.face-inference').exists()
    # POSIX mode bits do not exist on a real Windows filesystem, whatever sys.platform is patched to
    if platform != 'win32' and os.name != 'nt':
        assert expected.stat().st_mode & 0o777 == 0o700


def test_legacy_home_preserves_config_and_notices(home_environment, capsys):
    legacy = home_environment / '.face-inference'
    original = load_config(legacy / 'config.json')
    before = (legacy / 'config.json').read_bytes()
    config = load_config()
    assert config == original
    assert (legacy / 'config.json').read_bytes() == before
    notice = capsys.readouterr()
    assert notice.out == ''
    assert len(notice.err.splitlines()) == 1
    assert 'legacy' in notice.err.lower()
    assert str(legacy) in notice.err


def test_legacy_file_is_not_a_home(home_environment, capsys):
    (home_environment / '.face-inference').write_text('not a directory')
    assert config_module.default_home() == home_environment / 'user/.local/share/face-inference'
    assert capsys.readouterr().err == ''


def test_home_environment_precedes_legacy(home_environment, monkeypatch, capsys):
    (home_environment / '.face-inference').mkdir()
    monkeypatch.setenv('FACE_INFERENCE_HOME', 'private')
    config = load_config()
    expected = home_environment / 'private'
    assert config_module.default_home() == expected
    assert config['_path'] == str(expected / 'config.json')
    if os.name != 'nt':  # POSIX mode bits do not exist on a real Windows filesystem
        assert expected.stat().st_mode & 0o777 == 0o700
    assert capsys.readouterr().err == ''


@pytest.mark.parametrize('explicit', [False, True])
def test_config_path_overrides_home(home_environment, monkeypatch, capsys, explicit):
    (home_environment / '.face-inference').mkdir()
    monkeypatch.setenv('FACE_INFERENCE_HOME', str(home_environment / 'other'))
    monkeypatch.setenv('FACE_STANDALONE_CONFIG', 'environment/config.json')
    path = 'explicit/config.json' if explicit else None
    expected = home_environment / (path or 'environment/config.json')
    config = load_config(path)
    assert config['_path'] == str(expected)
    assert config['database'] == str(expected.parent / 'faces.sqlite3')
    assert not (home_environment / 'other').exists()
    assert capsys.readouterr().err == ''


@pytest.mark.parametrize('source', ['default', 'legacy', 'home', 'config', 'models'])
def test_models_follow_home(home_environment, monkeypatch, source):
    from face_inference import assets

    monkeypatch.setattr(assets, 'PACKAGED_VENDOR_DIR', home_environment / 'no-assets')
    config = None
    expected = home_environment / 'user/.local/share/face-inference/models'
    if source == 'legacy':
        (home_environment / '.face-inference').mkdir()
        expected = home_environment / '.face-inference/models'
    elif source == 'home':
        monkeypatch.setenv('FACE_INFERENCE_HOME', str(home_environment / 'private'))
        expected = home_environment / 'private/models'
    elif source == 'config':
        config = load_config(home_environment / 'deployment/config.json')
        expected = home_environment / 'deployment/models'
    elif source == 'models':
        monkeypatch.setenv('FACE_INFERENCE_MODELS_DIR', str(home_environment / 'models'))
        expected = home_environment / 'models'
    assert assets.models_dir(config) == expected


@pytest.mark.parametrize('command', ['init', 'check'])
@pytest.mark.parametrize('status', ['PASS', 'FAIL'])
@pytest.mark.parametrize('explicit', [False, True])
def test_cli_reports_resolved_home(
    home_environment, monkeypatch, capsys, command, status, explicit,
):
    arguments = ['face-inference', command]
    expected = home_environment / 'user/.local/share/face-inference'
    if explicit:
        expected = home_environment / 'deployment'
        arguments += ['--config', str(expected / 'config.json')]
    monkeypatch.setattr(sys, 'argv', arguments)
    monkeypatch.setattr(cli, 'verify', Mock(return_value={'status': status}))
    assert cli.main() == 0
    result = json.loads(capsys.readouterr().out)
    assert result['home'] == str(expected)
    assert result['config'] == str(expected / 'config.json')
    assert result['status'] == ('ready' if status == 'PASS' else 'assets-missing')


@pytest.fixture(autouse=True)
def clean_environment(monkeypatch):
    for name in ('HOST', 'PORT', 'ALLOWED_ORIGINS', 'RATE_LIMIT', 'TRUSTED_PROXIES'):
        monkeypatch.delenv('FACE_INFERENCE_' + name, raising=False)


@pytest.fixture
def config_file(tmp_path):
    path = tmp_path / 'config.json'
    load_config(path)
    return path


@pytest.mark.parametrize('file_values', [{}, {'host': 'config.example', 'port': 7000}])
@pytest.mark.parametrize('environment', [{}, {'host': 'env.example', 'port': '8000'}])
@pytest.mark.parametrize('flags', [{}, {'host': 'cli.example'}, {'port': '9000'},
                                    {'host': 'cli.example', 'port': 9000}])
def test_precedence(config_file, monkeypatch, file_values, environment, flags):
    stored = json.loads(config_file.read_text())
    for key in ('host', 'port'):
        stored.pop(key)
    stored.update(file_values)
    config_file.write_text(json.dumps(stored))
    before = config_file.read_bytes()
    for key, value in environment.items():
        monkeypatch.setenv('FACE_INFERENCE_' + key.upper(), value)
    config = load_config(config_file, **flags)
    expected = {'host': '127.0.0.1', 'port': 6122} | file_values | environment | flags
    assert config['host'] == expected['host']
    assert config['port'] == int(expected['port'])
    assert config['api_token'] == stored['api_token']
    assert config['encryption_key'] == stored['encryption_key']
    assert config_file.read_bytes() == before


@pytest.mark.parametrize('origin_text, expected', [
    (' https://one.example, ,https://two.example ,, ',
     ['https://one.example', 'https://two.example']),
    ('', []), (', ,', []),
])
def test_origins_environment_replaces_file(config_file, monkeypatch, origin_text, expected):
    stored = json.loads(config_file.read_text())
    stored['allowed_origins'] = ['https://config.example']
    config_file.write_text(json.dumps(stored))
    before = config_file.read_bytes()
    monkeypatch.setenv('FACE_INFERENCE_ALLOWED_ORIGINS', origin_text)
    assert load_config(config_file)['allowed_origins'] == expected
    assert config_file.read_bytes() == before


@pytest.mark.parametrize('source', ['file', 'env', 'cli'])
@pytest.mark.parametrize('key, value', [
    ('host', ''), ('host', 'bad host'), ('host', '-bad.example'),
    ('host', 'bad-.example'), ('host', 'bad..example'), ('host', 'https://host.example'),
    ('host', '999.999.999.999'), ('host', '[::1]'), ('host', 'bad\nhost'),
    ('host', 'x' * 64 + '.example'), ('host', '.'.join(['x' * 63] * 4)),
    ('port', 0), ('port', -1), ('port', 65536), ('port', 'nope'), ('port', '1.5'),
])
def test_invalid_effective_values(config_file, monkeypatch, source, key, value):
    flags = {}
    if source == 'file':
        stored = json.loads(config_file.read_text())
        stored[key] = value
        config_file.write_text(json.dumps(stored))
    elif source == 'env':
        monkeypatch.setenv('FACE_INFERENCE_' + key.upper(), str(value))
    else:
        flags[key] = value
    with pytest.raises((RuntimeError, ValueError), match=key):
        load_config(config_file, **flags)


@pytest.mark.parametrize('key, value', [
    ('host', None), ('host', 123), ('host', True),
    ('port', True), ('port', 1.5), ('port', '6122'), ('port', None),
])
def test_config_value_types(config_file, key, value):
    stored = json.loads(config_file.read_text())
    stored[key] = value
    config_file.write_text(json.dumps(stored))
    with pytest.raises((RuntimeError, ValueError), match=key):
        load_config(config_file)


@pytest.mark.parametrize('host', [
    '127.0.0.1', '::1', '0.0.0.0', '::', '2001:db8::1', 'localhost',
    'face-server', 'face.example', 'face.example.',
])
@pytest.mark.parametrize('port', [1, 65535])
def test_valid_listener_values(config_file, host, port):
    config = load_config(config_file, host=host, port=port)
    assert (config['host'], config['port']) == (host, port)


@pytest.mark.parametrize('source', ['file', 'env'])
@pytest.mark.parametrize('origin', [
    'http://example.com', 'https://example.com/path', 'https://user@example.com',
    'https://example.com?query', 'https://example.com#fragment', '*',
])
def test_origin_validation(config_file, monkeypatch, source, origin):
    if source == 'file':
        stored = json.loads(config_file.read_text())
        stored['allowed_origins'] = [origin]
        config_file.write_text(json.dumps(stored))
    else:
        monkeypatch.setenv('FACE_INFERENCE_ALLOWED_ORIGINS', origin)
    with pytest.raises(RuntimeError, match='origin'):
        load_config(config_file)


def test_cli_can_replace_invalid_lower_precedence_values(config_file, monkeypatch):
    stored = json.loads(config_file.read_text())
    stored.update(host='bad host', port=0, allowed_origins='invalid')
    config_file.write_text(json.dumps(stored))
    monkeypatch.setenv('FACE_INFERENCE_HOST', 'bad host')
    monkeypatch.setenv('FACE_INFERENCE_PORT', 'bad port')
    monkeypatch.setenv('FACE_INFERENCE_ALLOWED_ORIGINS', 'https://env.example')
    config = load_config(config_file, host='localhost', port='80')
    assert (config['host'], config['port']) == ('localhost', 80)
    assert config['allowed_origins'] == ['https://env.example']


def test_new_config_persists_defaults_only(tmp_path, monkeypatch):
    monkeypatch.setenv('FACE_INFERENCE_HOST', '0.0.0.0')
    monkeypatch.setenv('FACE_INFERENCE_PORT', '8080')
    monkeypatch.setenv('FACE_INFERENCE_ALLOWED_ORIGINS', 'https://env.example')
    path = tmp_path / 'config.json'
    config = load_config(path, host='cli.example', port=9000)
    stored = json.loads(path.read_text())
    assert (config['host'], config['port']) == ('cli.example', 9000)
    assert config['allowed_origins'] == ['https://env.example']
    assert (stored['host'], stored['port'], stored['allowed_origins']) == ('127.0.0.1', 6122, [])
    assert config['api_token'] == stored['api_token']
    assert config['encryption_key'] == stored['encryption_key']


@pytest.mark.parametrize('arguments, environment', [
    (['--host', 'bad host'], {}), (['--port', '0'], {}), (['--port', 'oops'], {}),
    ([], {'HOST': 'bad\nhost'}), ([], {'PORT': '65536'}),
    ([], {'ALLOWED_ORIGINS': 'http://example.com'}),
])
def test_cli_invalid_override_returns_one_line_error(
    config_file, monkeypatch, capsys, arguments, environment,
):
    for name, value in environment.items():
        monkeypatch.setenv('FACE_INFERENCE_' + name, value)
    monkeypatch.setattr(sys, 'argv', ['face-inference', 'serve', '--config', str(config_file),
                                    *arguments])
    assert cli.main() == 1
    output = capsys.readouterr()
    assert output.out == ''
    assert len(output.err.splitlines()) == 1
    assert 'Traceback' not in output.err


@pytest.mark.parametrize('host', ['0.0.0.0', '::', 'face.example',
                                  '127.0.0.1', '::1', 'localhost'])
def test_serve_uses_overrides_and_warns(config_file, monkeypatch, capsys, host):
    monkeypatch.setenv('FACE_INFERENCE_HOST', 'env.example')
    monkeypatch.setenv('FACE_INFERENCE_PORT', '8080')
    monkeypatch.setattr(sys, 'argv', ['face-inference', 'serve', '--config', str(config_file),
                                    '--host', host, '--port', '9000'])
    monkeypatch.setattr('face_inference.config.verify_assets', Mock(return_value={}))
    monkeypatch.setattr('face_inference.storage.SQLiteRepository', Mock())
    monkeypatch.setattr('face_inference.service.FaceService', Mock())
    server = Mock(server_port=9000)
    server_class = Mock(return_value=server)
    monkeypatch.setattr('face_inference.server.FaceHTTPServer', server_class)
    assert cli.main() == 0
    assert server_class.call_args.args[0] == (host, 9000)
    server.serve_forever.assert_called_once_with()
    server.server_close.assert_called_once_with()
    expected = '' if host in ('127.0.0.1', '::1', 'localhost') else WARNING + '\n'
    assert capsys.readouterr().err == expected


def test_concurrent_session_secret_migration(config_file):
    import threading
    from concurrent.futures import ThreadPoolExecutor

    config = json.loads(config_file.read_text())
    config.pop('session_secret', None)
    config_file.write_text(json.dumps(config))
    barrier = threading.Barrier(12)

    def load():
        barrier.wait()
        return load_config(config_file)['session_secret']

    with ThreadPoolExecutor(max_workers=12) as executor:
        secrets = list(executor.map(lambda _: load(), range(12)))
    assert len(set(secrets)) == 1
    assert secrets[0] == json.loads(config_file.read_text())['session_secret']


def test_rate_settings_defaults_and_environment(config_file, monkeypatch):
    config = load_config(config_file)
    assert (config['rate_limit_per_minute'], config['trusted_proxies']) == (120, [])
    monkeypatch.setenv('FACE_INFERENCE_RATE_LIMIT', '7')
    monkeypatch.setenv('FACE_INFERENCE_TRUSTED_PROXIES', ' 127.0.0.1, 10.0.0.0/8, ,::1 ')
    config = load_config(config_file)
    assert config['rate_limit_per_minute'] == 7
    assert config['trusted_proxies'] == ['127.0.0.1', '10.0.0.0/8', '::1']


@pytest.mark.parametrize('key,value', [
    ('rate_limit_per_minute', 0), ('rate_limit_per_minute', 100001),
    ('rate_limit_per_minute', True), ('rate_limit_per_minute', '12'),
    ('trusted_proxies', '127.0.0.1'), ('trusted_proxies', ['bad']),
    ('trusted_proxies', [42]),
])
def test_invalid_rate_settings(config_file, key, value):
    stored = json.loads(config_file.read_text())
    stored[key] = value
    config_file.write_text(json.dumps(stored))
    with pytest.raises(RuntimeError, match=key):
        load_config(config_file)


@pytest.mark.parametrize('name,value', [
    ('RATE_LIMIT', 'nope'), ('RATE_LIMIT', '0'), ('RATE_LIMIT', '100001'),
    ('TRUSTED_PROXIES', '10.0.0.0/99'),
])
def test_invalid_rate_environment(config_file, monkeypatch, name, value):
    monkeypatch.setenv('FACE_INFERENCE_' + name, value)
    with pytest.raises(RuntimeError):
        load_config(config_file)


@pytest.mark.parametrize('name,value', [
    ('RATE_LIMIT', 'bad'), ('TRUSTED_PROXIES', 'bad'),
])
def test_rate_environment_cli_error(config_file, monkeypatch, capsys, name, value):
    monkeypatch.setenv('FACE_INFERENCE_' + name, value)
    monkeypatch.setattr(sys, 'argv', ['face-inference', 'serve', '--config', str(config_file)])
    assert cli.main() == 1
    output = capsys.readouterr()
    assert output.out == ''
    assert len(output.err.splitlines()) == 1
    assert 'Traceback' not in output.err
