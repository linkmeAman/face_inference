import http.client
import os
import json
from email.message import Message
from io import BytesIO
from types import SimpleNamespace
from pathlib import Path
import tempfile
import threading
import unittest
from urllib.error import HTTPError
from urllib.request import Request, urlopen
import uuid

from face_inference.config import ROOT, contract, load_config
from face_inference.server import FaceHTTPServer, Handler
from face_inference.ratelimit import RateLimiter
from face_inference.service import FaceService
from face_inference.storage import SQLiteRepository


class HttpTests(unittest.TestCase):
    def setUp(self):
        self.folder = tempfile.TemporaryDirectory()
        self.config = load_config(Path(self.folder.name) / 'config.json')
        self.service = FaceService(SQLiteRepository(self.config['database'], self.config['encryption_key']), self.config, contract())
        self.server = FaceHTTPServer(('127.0.0.1', 0), self.service, ROOT)
        self.thread = threading.Thread(target=self.server.serve_forever, daemon=True)
        self.thread.start()
        self.base = 'http://127.0.0.1:' + str(self.server.server_port)

    def tearDown(self):
        self.server.shutdown()
        self.server.server_close()
        self.thread.join(timeout=3)
        self.folder.cleanup()

    def request(self, path, data=None, method=None, authorized=True, headers=None, raw=None):
        body = json.dumps(data).encode() if data is not None else raw
        auth = {'Authorization': 'Bearer ' + self.config['api_token']} if authorized else {}
        auth.update(headers or {})
        if body is not None:
            auth.setdefault('Content-Type', 'application/json')
        request = Request(self.base + path, data=body, method=method, headers=auth)
        try:
            response = urlopen(request, timeout=5)
        except HTTPError as error:
            response = error
        with response:
            content = response.read()
            return response.status, json.loads(content) if response.headers.get_content_type() == 'application/json' else content

    def test_public_health_authentication_and_no_private_static_files(self):
        self.assertEqual(self.request('/health', authorized=False)[0], 200)
        self.assertEqual(self.request('/api/v1/config', authorized=False)[0], 401)
        status, data = self.request('/api/v1/config')
        self.assertEqual(status, 200)
        self.assertNotIn(self.config['api_token'], json.dumps(data))
        self.assertNotIn(self.config['encryption_key'], json.dumps(data))
        for path in ('/.standalone/config.json', '/standalone/schema.sql', '/datasets/controlface10k-subset/000.png', '/classes/dbconnection.class.php', '/pf/js/browser-face/%2e%2e/config.json', '/browser-face/%2e%2e/config.json'):
            with self.subTest(path=path):
                self.assertEqual(self.request(path, authorized=False)[0], 404)

    def test_static_camera_dependencies_and_frontend_have_no_php_request(self):
        for path in ('/', '/app.mjs', '/client.mjs', '/style.css', '/browser-face/capture.mjs', '/browser-face/worker.mjs', '/vendor/browser-face/manifest.json'):
            with self.subTest(path=path):
                self.assertEqual(self.request(path, authorized=False)[0], 200)
        self.assertNotIn('.php', (ROOT / 'app.mjs').read_text())

    def test_bad_origin_size_json_and_injected_thresholds(self):
        self.assertEqual(self.request('/api/v1/config', headers={'Origin': 'https://another-site.example'})[0], 403)
        self.config['allowed_origins'] = ['https://face-workspace.example']
        self.assertEqual(self.request('/api/v1/config', headers={'Origin': 'https://face-workspace.example'})[0], 200)
        for raw in (b'[]', b'{"x":NaN}', b'{"x":1e999}', b'{"x":1,"x":2}', b'not-json'):
            self.assertEqual(self.request('/api/v1/collections', raw=raw)[0], 400)
        # The limit is decided from Content-Length alone; sending the body would race the server closing the socket (reset on macOS).
        oversize = http.client.HTTPConnection('127.0.0.1', self.server.server_port, timeout=5)
        oversize.putrequest('POST', '/api/v1/collections')
        for name, value in (('Authorization', 'Bearer ' + self.config['api_token']), ('Content-Type', 'application/json'), ('Content-Length', str(128 * 1024 + 1))):
            oversize.putheader(name, value)
        oversize.endheaders()
        self.assertEqual(oversize.getresponse().status, 413)
        oversize.close()
        self.assertEqual(self.request('/api/v1/collections', {'id': 'team', 'name': 'Team', 'idempotency_key': str(uuid.uuid4()), 'recognition_threshold': 0})[0], 400)

    def test_complete_php_free_enrollment_recognition_retry_delete(self):
        def post(path, data):
            status, result = self.request(path, {**data, 'idempotency_key': str(uuid.uuid4())})
            self.assertEqual(status, 200, result)
            return result['data']
        post('/api/v1/collections', dict(id='team', name='Team'))
        post('/api/v1/collections/team/people', dict(id='a', name='Person A'))
        model = contract()
        vector = [1.0] + [0.0] * 127
        payload = dict(person_id='a', consent=True, observations=[vector]*5, template=vector,
            capture_session=str(uuid.uuid4()), model_version=model['model_version'],
            preprocessing_version=model['preprocessing_version'], idempotency_key=str(uuid.uuid4()))
        status, saved = self.request('/api/v1/collections/team/enrollments', payload)
        self.assertEqual(status, 200)
        self.assertEqual(saved['data']['state'], 'PENDING')
        self.assertEqual(self.request('/api/v1/collections/team/enrollments', payload)[1], saved)
        recognize = {k: v for k, v in payload.items() if k not in ('person_id', 'consent', 'template', 'idempotency_key')}
        recognize['capture_session'] = str(uuid.uuid4())
        result = post('/api/v1/collections/team/recognitions', recognize)
        self.assertEqual((result['state'], result['person_id'], result['verified']), ('SUGGESTED', 'a', False))
        status, deleted = self.request('/api/v1/collections/team/people/a/face', {'idempotency_key': str(uuid.uuid4())}, 'DELETE')
        self.assertEqual(status, 200)
        self.assertEqual(deleted['data']['state'], 'DELETED')
        self.assertEqual(self.request('/api/v1/collections/team/people')[1]['data'][0]['state'], 'NOT_ENROLLED')


class CrossOriginTests(unittest.TestCase):
    def setUp(self):
        self.config = {'api_token': 'a' * 32, 'host': '127.0.0.1', 'allowed_origins': [], 'trusted_proxies': []}

    def response(self, origin=None, method='GET', path='/api/v1/config', authorized=True):
        handler = Handler.__new__(Handler)
        handler.server = SimpleNamespace(service=SimpleNamespace(config=self.config, safe_config=lambda: {}),
                                         server_port=6122, rate_limiter=RateLimiter(120))
        handler.command, handler.path, handler.client_address = method, path, ('127.0.0.1', 0)
        handler.headers, handler.wfile = Message(), BytesIO()
        if authorized:
            handler.headers['Authorization'] = 'Bearer ' + self.config['api_token']
        if origin is not None:
            handler.headers['Origin'] = origin
        headers = Message()
        handler.send_response = lambda status: setattr(handler, 'status', status)
        handler.send_header = lambda name, value: headers.__setitem__(name, value)
        handler.end_headers = lambda: None
        handler.dispatch()
        return handler.status, headers, handler.wfile.getvalue()

    def test_cors_and_csp(self):
        origins = ['https://face-workspace.example', 'http://localhost:3000']
        self.config['allowed_origins'] = origins
        for origin, allowed in [(origins[0], True), ('https://other.example', False)]:
            with self.subTest(origin=origin):
                status, headers, _ = self.response(origin)
                self.assertEqual(status, 200 if allowed else 403)
                self.assertEqual(headers.get('Vary'), 'Origin')
                self.assertEqual(headers.get('Access-Control-Allow-Origin'), origin if allowed else None)
                self.assertEqual(headers.get('Access-Control-Expose-Headers'), 'Retry-After' if allowed else None)
                self.assertIn("frame-ancestors 'self' " + ' '.join(origins), headers['Content-Security-Policy'])
                self.assertEqual(headers['Permissions-Policy'], 'camera=(self), microphone=()')
        self.config['allowed_origins'] = []
        self.assertIn("frame-ancestors 'self';", self.response()[1]['Content-Security-Policy'])

    def test_preflight(self):
        self.config['allowed_origins'] = ['https://face-workspace.example']
        for origin, status in [('https://face-workspace.example', 204), ('https://other.example', 403), (None, 403)]:
            with self.subTest(origin=origin):
                actual, headers, body = self.response(origin, 'OPTIONS', authorized=False)
                self.assertEqual(actual, status)
                self.assertEqual(headers.get('Vary'), 'Origin' if origin else None)
                self.assertEqual(headers.get('Access-Control-Allow-Origin'), origin if status == 204 else None)
                self.assertEqual(headers.get('Access-Control-Allow-Methods'), 'GET, POST, DELETE, OPTIONS' if status == 204 else None)
                self.assertEqual(headers.get('Access-Control-Allow-Headers'), 'Authorization, Content-Type' if status == 204 else None)
                self.assertEqual(headers.get('Access-Control-Max-Age'), '600' if status == 204 else None)
                if status == 204:
                    self.assertEqual(body, b'')


class OriginConfigTests(unittest.TestCase):
    def test_allowed_origins(self):
        with tempfile.TemporaryDirectory() as folder:
            path = Path(folder) / 'config.json'
            load_config(path)
            config = json.loads(path.read_text())
            valid = ['https://example.com', 'https://example.com:8443', 'https://[::1]:443',
                     'http://localhost', 'http://127.0.0.1:3000', 'http://[::1]:3000']
            invalid = ['*', 'null', 'http://example.com', 'https://example.com/path',
                       'https://example.com?x=1', 'https://user@example.com', 'https://*.example.com',
                       'https://example.com:bad', 'https://example.com:99999', 'https://example.com:',
                       'https://[::1%bad;injection]', 'https://[::1]extra',
                       'https://example.com\nX-Injected: yes', 'https://', 'https://[::1', 42]
            for origin in valid + invalid:
                with self.subTest(origin=origin):
                    config['allowed_origins'] = [origin]
                    path.write_text(json.dumps(config))
                    if origin in valid:
                        self.assertEqual(load_config(path)['allowed_origins'], [origin])
                    else:
                        with self.assertRaises(RuntimeError):
                            load_config(path)


class SessionTokenTests(unittest.TestCase):
    def setUp(self):
        self.folder = tempfile.TemporaryDirectory()
        self.addCleanup(self.folder.cleanup)
        self.path = Path(self.folder.name) / 'config.json'
        self.config = load_config(self.path)
        self.service = FaceService(SQLiteRepository(self.config['database'], self.config['encryption_key']), self.config, contract(), clock=lambda: 1000)
        self.service.mutate('team', 'collection', {'id': 'team', 'name': 'Team', 'idempotency_key': str(uuid.uuid4())})
        self.service.mutate = lambda collection, action, body: {'collection': collection, 'action': action}

    def request(self, path, body=None, token=None, method=None):
        handler = Handler.__new__(Handler)
        handler.server = SimpleNamespace(service=self.service, server_port=6122, rate_limiter=RateLimiter(120))
        handler.command, handler.path, handler.client_address = method or ('POST' if body is not None else 'GET'), path, ('127.0.0.1', 0)
        handler.headers, handler.wfile = Message(), BytesIO()
        handler.headers['Authorization'] = 'Bearer ' + (self.config['api_token'] if token is None else token)
        raw = json.dumps(body).encode() if body is not None else b''
        handler.headers['Content-Type'] = 'application/json'
        handler.headers['Content-Length'] = str(len(raw))
        handler.rfile = BytesIO(raw)
        handler.send_response = lambda status: setattr(handler, 'status', status)
        handler.send_header = lambda *_: None
        handler.end_headers = lambda: None
        handler.dispatch()
        return handler.status, json.loads(handler.wfile.getvalue())

    def test_mint_and_restrictions(self):
        mint = '/api/v1/session-tokens'
        body = {'collection_id': 'team', 'scope': 'enroll', 'person_id': 'a'}
        status, result = self.request(mint, body)
        self.assertEqual(status, 200, result)
        token = result['data']['token']
        self.assertEqual(result['data']['expires_at'], 1300)
        self.assertEqual(self.request('/api/v1/collections/team/enrollments', {'person_id': 'a'}, token)[0], 200)
        for path, payload, method in [('/api/v1/collections/team/enrollments', {'person_id': 'b'}, None),
                                      ('/api/v1/collections/other/enrollments', {'person_id': 'a'}, None),
                                      ('/api/v1/collections/team/recognitions', {}, None),
                                      ('/api/v1/config', None, None), (mint, body, None),
                                      ('/api/v1/collections/team/people/a/face', {}, 'DELETE'),
                                      ('/api/v1/collections/team/export', None, None)]:
            with self.subTest(path=path, payload=payload):
                self.assertEqual(self.request(path, payload, token, method)[0], 403)
        for ttl in (0, 3601, True, 1.5):
            self.assertEqual(self.request(mint, {**body, 'ttl': ttl})[0], 400)
        self.assertEqual(self.request(mint, {**body, 'collection_id': 'missing'})[0], 404)
        self.assertEqual(self.request('/api/v1/config')[0], 200)
        status, result = self.request('/api/v1/collections/other/enrollments', {'person_id': 'a'}, token)
        self.assertEqual((status, result['code']), (403, 'TOKEN_SCOPE'))
        self.service.clock = lambda: 1300
        status, result = self.request('/api/v1/collections/team/enrollments', {'person_id': 'a'}, token)
        self.assertEqual((status, result['code']), (401, 'TOKEN_EXPIRED'))
        self.service.clock = lambda: 1000
        status, result = self.request('/api/v1/collections/team/enrollments', {'person_id': 'a'}, token[:-1] + ('A' if token[-1] != 'A' else 'B'))
        self.assertEqual((status, result['code']), (401, 'TOKEN_INVALID'))
        status, result = self.request(mint, {'collection_id': 'team', 'scope': 'recognize', 'ttl': 1})
        self.assertEqual(status, 200)
        self.assertEqual(self.request('/api/v1/collections/team/recognitions', {}, result['data']['token'])[0], 200)

    def test_secret_persisted_and_validated(self):
        config = json.loads(self.path.read_text())
        self.assertEqual(load_config(self.path)['session_secret'], config['session_secret'])
        config.pop('session_secret')
        self.path.write_text(json.dumps(config))
        secret = load_config(self.path)['session_secret']
        self.assertEqual(json.loads(self.path.read_text())['session_secret'], secret)
        if os.name != 'nt':  # POSIX mode bits do not exist on a real Windows filesystem
            self.assertEqual(self.path.stat().st_mode & 0o777, 0o600)
        config['session_secret'] = 'short'
        self.path.write_text(json.dumps(config))
        with self.assertRaises(RuntimeError):
            load_config(self.path)


class RateLimitTests(unittest.TestCase):
    def setUp(self):
        from unittest.mock import patch

        self.config = {'api_token': 'a' * 32, 'host': '127.0.0.1',
                       'rate_limit_per_minute': 1, 'trusted_proxies': ['127.0.0.1']}
        service = SimpleNamespace(config=self.config, safe_config=lambda: {})
        with patch('face_inference.server.ThreadingHTTPServer.__init__', return_value=None):
            self.server = FaceHTTPServer(('127.0.0.1', 0), service, ROOT)
        self.server.server_port = 6122

    def request(self, peer='127.0.0.1', forwarded=None, token=None, method='GET'):
        handler = Handler.__new__(Handler)
        handler.server = self.server
        handler.command, handler.path, handler.client_address = method, '/api/v1/config', (peer, 0)
        handler.headers, handler.wfile = Message(), BytesIO()
        handler.headers['Authorization'] = 'Bearer ' + (token or self.config['api_token'])
        if forwarded:
            handler.headers['X-Forwarded-For'] = forwarded
        headers = Message()
        handler.send_response = lambda status: setattr(handler, 'status', status)
        handler.send_header = lambda name, value: headers.__setitem__(name, value)
        handler.end_headers = lambda: None
        handler.dispatch()
        return handler.status, headers

    def test_trusted_clients_are_independent_and_retry_after_is_sent(self):
        self.assertEqual(self.request(forwarded='192.0.2.1')[0], 200)
        self.assertEqual(self.request(forwarded='192.0.2.2')[0], 200)
        status, headers = self.request(forwarded='192.0.2.1')
        self.assertEqual(status, 429)
        self.assertGreater(int(headers['Retry-After']), 0)

    def test_untrusted_forwarded_header_is_ignored(self):
        self.assertEqual(self.request(peer='192.0.2.3', forwarded='192.0.2.1')[0], 200)
        self.assertEqual(self.request(peer='192.0.2.3', forwarded='192.0.2.2')[0], 429)

    def test_wrong_tokens_are_limited(self):
        self.assertEqual(self.request(token='wrong')[0], 401)
        self.assertEqual(self.request(token='wrong')[0], 429)

    def test_10001st_client_is_allowed(self):
        for i in range(10001):
            status, _ = self.request(peer=f'10.{i // 65536}.{(i // 256) % 256}.{i % 256}')
            self.assertEqual(status, 200)

    def test_preflight_does_not_consume_limit(self):
        self.config['allowed_origins'] = ['https://example.com']
        handler = Handler.__new__(Handler)
        handler.server = self.server
        handler.command, handler.path = 'OPTIONS', '/api/v1/config'
        handler.headers = Message()
        handler.headers['Origin'] = 'https://example.com'
        handler.wfile = BytesIO()
        handler.send_response = lambda status: setattr(handler, 'status', status)
        handler.send_header = lambda *_: None
        handler.end_headers = lambda: None
        handler.dispatch()
        self.assertEqual(handler.status, 204)
        self.assertEqual(self.request()[0], 200)


if __name__ == '__main__':
    unittest.main()
