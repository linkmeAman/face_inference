import json
from pathlib import Path
import tempfile
import threading
import unittest
from urllib.error import HTTPError
from urllib.request import Request, urlopen
import uuid

from standalone.config import ROOT, contract, load_config
from standalone.server import FaceHTTPServer
from standalone.service import FaceService
from standalone.storage import SQLiteRepository


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
        for path in ('/.standalone/config.json', '/standalone/schema.sql', '/datasets/controlface10k-subset/000.png', '/classes/dbconnection.class.php', '/pf/js/browser-face/%2e%2e/config.json'):
            with self.subTest(path=path):
                self.assertEqual(self.request(path, authorized=False)[0], 404)

    def test_static_camera_dependencies_and_frontend_have_no_php_request(self):
        for path in ('/', '/app.mjs', '/client.mjs', '/style.css', '/pf/js/browser-face/capture.mjs', '/pf/js/browser-face/worker.mjs', '/pf/vendor/browser-face/manifest.json'):
            with self.subTest(path=path):
                self.assertEqual(self.request(path, authorized=False)[0], 200)
        self.assertNotIn('.php', (ROOT / 'standalone/web/app.mjs').read_text())

    def test_bad_origin_size_json_and_injected_thresholds(self):
        self.assertEqual(self.request('/api/v1/config', headers={'Origin': 'https://another-site.example'})[0], 403)
        self.config['allowed_origins'] = ['https://face-workspace.example']
        self.assertEqual(self.request('/api/v1/config', headers={'Origin': 'https://face-workspace.example'})[0], 200)
        for raw in (b'[]', b'{"x":NaN}', b'{"x":1e999}', b'{"x":1,"x":2}', b'not-json'):
            self.assertEqual(self.request('/api/v1/collections', raw=raw)[0], 400)
        self.assertEqual(self.request('/api/v1/collections', raw=b'x' * (128*1024+1))[0], 413)
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


if __name__ == '__main__':
    unittest.main()
