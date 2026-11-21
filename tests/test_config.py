import json
import sys
from unittest.mock import Mock

import pytest

from face_inference import cli
from face_inference.config import load_config

WARNING = 'listening on a non-loopback address: put TLS in front (camera needs HTTPS)'


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
