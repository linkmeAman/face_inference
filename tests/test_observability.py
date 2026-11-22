import json
import logging
import re
from concurrent.futures import ThreadPoolExecutor
from email.message import Message
from io import BytesIO
from types import SimpleNamespace

import pytest

from face_inference.config import contract, load_config
from face_inference.ratelimit import RateLimiter
from face_inference.server import FaceHTTPServer, Handler
from face_inference.service import FaceService
from face_inference.storage import SQLiteRepository


@pytest.fixture
def service(tmp_path):
    config = load_config(tmp_path / 'config.json')
    repo = SQLiteRepository(config['database'], config['encryption_key'])
    return FaceService(repo, config, contract())


def test_redacted_structured_logs_and_concurrent_request_ids(service, caplog, monkeypatch):
    token = service.config['api_token']
    secrets = ['query-secret', 'header-secret', 'body-secret', 'exception-secret',
               'collection-secret', 'person-secret', token]
    server = SimpleNamespace(service=service, rate_limiter=RateLimiter(limit=100, window=60),
                             server_port=6122, assets={})

    def fail():
        raise RuntimeError('exception-secret')

    monkeypatch.setattr(service, 'safe_config', fail)
    caplog.set_level(logging.INFO, logger='face-standalone')

    def request(method, path, body=b'', auth=None):
        handler = Handler.__new__(Handler)
        handler.server, handler.command, handler.path = server, method, path
        handler.request_version, handler.requestline = 'HTTP/1.1', method + ' ' + path
        handler.client_address = ('127.0.0.1', 12345)
        handler.headers = Message()
        handler.headers['X-Request-ID'] = 'header-secret'
        handler.headers['X-Secret'] = 'header-secret'
        if auth:
            handler.headers['Authorization'] = 'Bearer ' + auth
        if body:
            handler.headers['Content-Type'] = 'application/json'
            handler.headers['Content-Length'] = str(len(body))
        handler.rfile, handler.wfile = BytesIO(body), BytesIO()
        handler.dispatch()
        response = handler.wfile.getvalue()
        assert b'X-Request-ID:' not in response
        return int(response.split(b' ', 2)[1])

    assert request('GET', '/health?query-secret') == 200
    path = '/api/v1/collections/collection-secret/people/person-secret?query-secret'
    assert request('GET', path) == 401
    assert request('POST', '/api/v1/collections?query-secret', b'{body-secret', token) == 400
    assert request('GET', '/api/v1/config?query-secret', auth=token) == 500
    with ThreadPoolExecutor(max_workers=6) as pool:
        statuses = list(pool.map(lambda _: request('GET', '/health?query-secret'), range(6)))
        assert statuses == [200] * 6

    messages = [r.getMessage() for r in caplog.records if r.name == 'face-standalone']
    assert len(messages) == 10
    assert all(len(message) <= 256 for message in messages)
    assert all(secret not in '\n'.join(messages) for secret in secrets)
    records = [json.loads(message) for message in messages]
    fields = {'request_id', 'outcome', 'status', 'duration_ms'}
    assert all(set(record) == fields for record in records)
    assert all(re.fullmatch(r'[0-9a-f]{32}', record['request_id']) for record in records)
    assert len({record['request_id'] for record in records}) == len(records)
    assert {(record['outcome'], record['status']) for record in records} == {
        ('success', 200), ('client_error', 401), ('client_error', 400), ('server_error', 500)
    }
    assert all(type(record['duration_ms']) is int and 0 <= record['duration_ms'] <= 3600000
               for record in records)


@pytest.fixture
def cli_logging(monkeypatch):
    import sys
    from unittest.mock import Mock

    from face_inference import cli

    logger = logging.getLogger('face-standalone')
    handlers, level, propagate = logger.handlers[:], logger.level, logger.propagate
    monkeypatch.setattr(cli, 'load_config', lambda *args, **kwargs: {
        'host': '127.0.0.1', 'port': 6122, 'database': ':memory:', 'encryption_key': 'key',
    })
    monkeypatch.setattr(cli, 'models_dir', lambda config: None)
    monkeypatch.setattr(cli, 'verify', lambda path: {'status': 'PASS'})
    monkeypatch.setattr(cli, 'contract', lambda: {})
    monkeypatch.setattr('face_inference.storage.SQLiteRepository', Mock())
    monkeypatch.setattr('face_inference.service.FaceService', Mock())
    monkeypatch.setattr('face_inference.server.FaceHTTPServer', Mock())
    monkeypatch.delenv('FACE_INFERENCE_LOG_LEVEL', raising=False)

    def run(*args):
        monkeypatch.setattr(sys, 'argv', ['face-inference', 'serve', *args])
        return cli.main()

    yield run
    logger.handlers[:] = handlers
    logger.setLevel(level)
    logger.propagate = propagate


@pytest.mark.parametrize(('env', 'args', 'expected'), [
    (None, (), logging.WARNING), ('info', (), logging.INFO),
    ('ERROR', ('--log-level', 'debug'), logging.DEBUG),
])
def test_cli_log_level_precedence(cli_logging, monkeypatch, env, args, expected):
    if env is not None:
        monkeypatch.setenv('FACE_INFERENCE_LOG_LEVEL', env)
    root_handlers = logging.getLogger().handlers[:]
    assert cli_logging(*args) == 0
    assert cli_logging(*args) == 0
    logger = logging.getLogger('face-standalone')
    assert logger.level == expected
    assert len(logger.handlers) == 1
    assert not logger.propagate
    assert logging.getLogger().handlers == root_handlers


@pytest.mark.parametrize(('env', 'args'), [('invalid', ()), (None, ('--log-level', 'bad\nvalue'))])
def test_cli_invalid_log_level(cli_logging, monkeypatch, capsys, env, args):
    if env is not None:
        monkeypatch.setenv('FACE_INFERENCE_LOG_LEVEL', env)
    assert cli_logging(*args) == 1
    output = capsys.readouterr()
    assert output.out == ''
    assert len(output.err.splitlines()) == 1
    assert 'Traceback' not in output.err


@pytest.mark.parametrize(('level', 'count'), [
    (None, 0), ('INFO', 2), ('DEBUG', 2), ('WARNING', 0), ('ERROR', 0),
])
def test_cli_request_logs_are_redacted_once(cli_logging, service, capsys, level, count):
    import threading
    from urllib.error import HTTPError
    from urllib.request import Request, urlopen

    args = ('--log-level', level) if level else ()
    assert cli_logging(*args) == 0
    assert cli_logging(*args) == 0
    capsys.readouterr()
    try:
        server = FaceHTTPServer(('127.0.0.1', 0), service)
    except PermissionError:
        pytest.skip('socket binding is blocked')
    try:
        thread = threading.Thread(target=server.serve_forever, daemon=True)
        thread.start()
        with urlopen(f'http://127.0.0.1:{server.server_port}/health', timeout=5) as response:
            assert response.status == 200
        request = Request(f'http://127.0.0.1:{server.server_port}/api/v1/collections?secret-value',
                          data=b'{secret-value', headers={'Authorization': 'Bearer secret-value',
                                                      'Content-Type': 'application/json'})
        try:
            with urlopen(request, timeout=5) as response:
                response.read()
        except HTTPError as error:
            error.read()
    finally:
        server.shutdown()
        server.server_close()
        thread.join(timeout=3)
    stderr = capsys.readouterr().err
    assert 'secret-value' not in stderr
    records = [json.loads(line) for line in stderr.splitlines()]
    assert len(records) == count
    fields = {'request_id', 'outcome', 'status', 'duration_ms'}
    assert all(set(record) == fields for record in records)
    assert len({record['request_id'] for record in records}) == count
