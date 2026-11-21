import io
import json
from urllib.error import HTTPError, URLError

import pytest

from face_inference.client import (
    FaceApiError,
    FaceClient,
    FaceClientError,
    FaceConnectionError,
    FaceError,
)


def test_requests_and_retry_preserve_idempotency(monkeypatch):
    client = FaceClient('https://example.com/', 'secret', retry_backoff=0)
    calls = []
    def execute(path, method, body):
        calls.append((path, method, body))
        if len(calls) <= 3:
            raise URLError('secret unavailable')
        return 200, b'{"data":{"ok":true}}'
    monkeypatch.setattr(client, '_execute_once', execute)
    with pytest.raises(FaceConnectionError, match=r'\*\*\* unavailable'):
        client.send('/items', {'name': 'A'})
    with pytest.raises(FaceClientError):
        client.send('/other')
    assert client.retry() == {'ok': True}
    assert len({call[2]['idempotency_key'] for call in calls}) == 1
    with pytest.raises(FaceError, match='No unresolved request'):
        client.retry()
    assert 'secret' not in repr(client) + str(client)


def test_http_and_decoding_errors(monkeypatch):
    client = FaceClient('http://example.com', 'secret', retry_backoff=0)
    assert client._decode(b'bad', 200) == {}
    assert client._decode(b'[1]', 200) == [1]
    with pytest.raises(FaceApiError, match=r'\*\*\*') as error:
        client._decode(b'{"error":true,"code":"secret","message":"secret"}', 400)
    assert error.value.status == 400
    def fail(*args):
        raise HTTPError('http://example.com', 400, 'bad', {}, io.BytesIO(b'{"message":"bad"}'))
    monkeypatch.setattr(client, '_execute_once', fail)
    with pytest.raises(FaceApiError, match='bad'):
        client.send('/bad')
    assert client.pending is None
    with pytest.raises(ValueError):
        FaceClient('file:///tmp/x', '')


def test_endpoint_payloads(monkeypatch):
    client = FaceClient('http://example.com', '')
    calls = []
    def perform(request):
        calls.append(request)
        client.pending = None
        return request
    monkeypatch.setattr(client, '_perform', perform)
    assert client.health()['path'] == '/health'
    assert client.get_config()['path'].endswith('/config')
    assert client.list_collections()['path'].endswith('/collections')
    assert client.list_people('c')['path'].endswith('/c/people')
    assert client.list_events('c')['path'].endswith('/c/events')
    assert client.create_collection('c', 'C', idempotency_key='k')['body']['idempotency_key'] == 'k'
    assert client.add_person('c', 'p', 'P', {'x': 1})['body']['metadata'] == {'x': 1}
    assert client.enroll('c', 'p', device='cam', lighting=0.5)['body']['device'] == 'cam'
    assert client.enroll('c', {'person_id': 'p'})['body']['person_id'] == 'p'
    assert client.recognize('c', [], eligible_ids=['p'])['body']['eligible_ids'] == ['p']
    assert client.recognize('c', {'observations': []})['body']['observations'] == []
    observation = client.record_observation('c', 'p', device='cam', lighting=0.5)
    assert observation['body']['lighting'] == 0.5
    assert client.record_observation('c', {'person_id': 'p'})['body']['person_id'] == 'p'
    assert client.delete_face('c', 'p', idempotency_key='k')['method'] == 'DELETE'


def test_execute_builds_request(monkeypatch):
    client = FaceClient('https://example.com', 'secret')
    class Response:
        status = 200
        def __enter__(self): return self
        def __exit__(self, *args): pass
        def read(self): return b'{}'
    def open_request(request, timeout):
        assert request.full_url == 'https://example.com/items'
        assert request.get_header('Authorization') == 'Bearer secret'
        assert json.loads(request.data) == {'a': 1}
        assert timeout == 10
        return Response()
    monkeypatch.setattr('urllib.request.urlopen', open_request)
    assert client._execute_once('items', 'POST', {'a': 1}) == (200, b'{}')
