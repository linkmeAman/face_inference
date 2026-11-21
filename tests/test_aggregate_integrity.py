import base64
import json
import tempfile
import threading
import unittest
import uuid
from pathlib import Path
from urllib.error import HTTPError
from urllib.request import Request, urlopen

from face_inference import tokens
from face_inference.config import ROOT, contract, load_config
from face_inference.matching import FaceError, aggregate
from face_inference.server import FaceHTTPServer
from face_inference.service import FaceService
from face_inference.storage import SQLiteRepository


class AggregateIntegrityTests(unittest.TestCase):
    def setUp(self):
        self.folder = tempfile.TemporaryDirectory()
        self.config = load_config(Path(self.folder.name) / 'config.json')
        self.model = contract()
        self.repo = SQLiteRepository(self.config['database'], self.config['encryption_key'])
        self.now = 1800000000.0
        self.service = FaceService(self.repo, self.config, self.model, clock=lambda: self.now)
        self.server = FaceHTTPServer(('127.0.0.1', 0), self.service, ROOT)
        self.thread = threading.Thread(target=self.server.serve_forever, daemon=True)
        self.thread.start()
        self.base = f'http://127.0.0.1:{self.server.server_port}'

    def tearDown(self):
        self.server.shutdown()
        self.server.server_close()
        self.thread.join(timeout=3)
        self.folder.cleanup()

    def request(self, path, data=None, method=None, authorized=True, token=None):
        body = json.dumps(data).encode() if data is not None else None
        auth_token = token or self.config['api_token']
        headers = {'Authorization': f'Bearer {auth_token}'} if authorized else {}
        if body is not None:
            headers['Content-Type'] = 'application/json'
        req = Request(self.base + path, data=body, method=method, headers=headers)
        try:
            resp = urlopen(req, timeout=5)
        except HTTPError as err:
            resp = err
        with resp:
            content = resp.read()
            is_json = resp.headers.get_content_type() == 'application/json'
            payload = json.loads(content) if is_json else content
            return resp.status, payload

    def test_http_mismatched_aggregate_rejected_and_matching_succeeds(self):
        """HTTP regression: mismatched template yields 400 AGGREGATE_MISMATCH; matching succeeds."""
        # 1. Setup collection and person
        status, col_res = self.request(
            '/api/v1/collections',
            {'id': 'team', 'name': 'Team', 'idempotency_key': str(uuid.uuid4())},
        )
        self.assertEqual(status, 200, col_res)
        status, person_res = self.request(
            '/api/v1/collections/team/people',
            {'id': 'p1', 'name': 'Person 1', 'idempotency_key': str(uuid.uuid4())},
        )
        self.assertEqual(status, 200, person_res)

        # Baseline: 0 captures, person state is NOT_ENROLLED, no encrypted template
        with self.repo.transaction() as db:
            count = db.execute(
                "SELECT count(*) FROM captures WHERE collection_id='team'"
            ).fetchone()[0]
            self.assertEqual(count, 0)
            person_row = db.execute(
                "SELECT state, encrypted_template FROM people "
                "WHERE collection_id='team' AND id='p1'"
            ).fetchone()
            self.assertEqual(person_row['state'], 'NOT_ENROLLED')
            self.assertIsNone(person_row['encrypted_template'])

        # Observations: 5 identical unit vectors along dim 0
        obs_vec = [1.0] + [0.0] * 127
        observations = [obs_vec] * 5
        true_aggregate = aggregate(observations)

        # Forged template: valid unit vector along dim 1 (different from true aggregate)
        forged_template = [0.0, 1.0] + [0.0] * 126

        mismatched_payload = {
            'person_id': 'p1',
            'consent': True,
            'observations': observations,
            'template': forged_template,
            'capture_session': str(uuid.uuid4()),
            'model_version': self.model['model_version'],
            'preprocessing_version': self.model['preprocessing_version'],
            'idempotency_key': str(uuid.uuid4()),
        }

        # 2. Submit mismatched template via HTTP
        status, result = self.request('/api/v1/collections/team/enrollments', mismatched_payload)
        self.assertEqual(status, 400)
        self.assertEqual(result.get('error'), 1)
        self.assertEqual(result.get('code'), 'AGGREGATE_MISMATCH')
        err_msg = result.get('error_msg', '')
        self.assertIn('Submitted template does not match the observations', err_msg)

        # 3. Assert no enrollment/capture was persisted
        with self.repo.transaction() as db:
            count = db.execute(
                "SELECT count(*) FROM captures WHERE collection_id='team'"
            ).fetchone()[0]
            self.assertEqual(count, 0)
            person_row = db.execute(
                "SELECT state, encrypted_template FROM people "
                "WHERE collection_id='team' AND id='p1'"
            ).fetchone()
            self.assertEqual(person_row['state'], 'NOT_ENROLLED')
            self.assertIsNone(person_row['encrypted_template'])

        # Check API view: person remains NOT_ENROLLED
        status, people_res = self.request('/api/v1/collections/team/people')
        self.assertEqual(status, 200)
        self.assertEqual(people_res['data'][0]['state'], 'NOT_ENROLLED')

        # 4. Submit matching aggregate: must succeed
        matching_payload = dict(mismatched_payload)
        matching_payload['template'] = true_aggregate
        matching_payload['capture_session'] = str(uuid.uuid4())
        matching_payload['idempotency_key'] = str(uuid.uuid4())

        status, match_res = self.request('/api/v1/collections/team/enrollments', matching_payload)
        self.assertEqual(status, 200, match_res)
        self.assertEqual(match_res.get('error'), 0)
        self.assertEqual(match_res['data']['person_id'], 'p1')
        self.assertEqual(match_res['data']['state'], 'PENDING')

        # 5. Assert persistence after successful matching enrollment
        with self.repo.transaction() as db:
            count = db.execute(
                "SELECT count(*) FROM captures WHERE collection_id='team'"
            ).fetchone()[0]
            self.assertEqual(count, 1)
            person_row = db.execute(
                "SELECT state, encrypted_template FROM people "
                "WHERE collection_id='team' AND id='p1'"
            ).fetchone()
            self.assertEqual(person_row['state'], 'PENDING')
            self.assertIsNotNone(person_row['encrypted_template'])

    def test_session_token_mismatched_aggregate_rejected(self):
        """SEC-9 regression: session token cannot bypass server-side aggregate verification."""
        # Setup collection and person
        self.service.mutate(
            'team', 'collection',
            {'id': 'team', 'name': 'Team', 'idempotency_key': str(uuid.uuid4())},
        )
        self.service.mutate(
            'team', 'person',
            {'id': 'p1', 'name': 'Person 1', 'idempotency_key': str(uuid.uuid4())},
        )

        # Issue scoped session token for person p1
        secret = base64.urlsafe_b64decode(self.config['session_secret'])
        token = tokens.issue(secret, 'team', 'enroll', 300, 'p1', now=self.now)

        obs_vec = [1.0] + [0.0] * 127
        observations = [obs_vec] * 5
        forged_template = [0.0, 1.0] + [0.0] * 126

        payload = {
            'person_id': 'p1',
            'consent': True,
            'observations': observations,
            'template': forged_template,
            'capture_session': str(uuid.uuid4()),
            'model_version': self.model['model_version'],
            'preprocessing_version': self.model['preprocessing_version'],
            'idempotency_key': str(uuid.uuid4()),
        }

        # Attempt forged enrollment via session token
        status, result = self.request('/api/v1/collections/team/enrollments', payload, token=token)
        self.assertEqual(status, 400)
        self.assertEqual(result.get('code'), 'AGGREGATE_MISMATCH')

        # Verify nothing persisted
        with self.repo.transaction() as db:
            count = db.execute(
                "SELECT count(*) FROM captures WHERE collection_id='team'"
            ).fetchone()[0]
            self.assertEqual(count, 0)

    def test_service_level_aggregate_mismatch_and_success(self):
        """Service regression: direct service calls verify AGGREGATE_MISMATCH rollback."""
        self.service.mutate(
            'team', 'collection',
            {'id': 'team', 'name': 'Team', 'idempotency_key': str(uuid.uuid4())},
        )
        self.service.mutate(
            'team', 'person',
            {'id': 'p2', 'name': 'Person 2', 'idempotency_key': str(uuid.uuid4())},
        )

        obs_vec = [1.0] + [0.0] * 127
        observations = [obs_vec] * 5
        true_aggregate = aggregate(observations)
        forged_template = [0.0, 1.0] + [0.0] * 126

        payload = {
            'person_id': 'p2',
            'consent': True,
            'observations': observations,
            'template': forged_template,
            'capture_session': str(uuid.uuid4()),
            'model_version': self.model['model_version'],
            'preprocessing_version': self.model['preprocessing_version'],
            'idempotency_key': str(uuid.uuid4()),
        }

        # 1. Mismatched template raises FaceError AGGREGATE_MISMATCH with status 400
        with self.assertRaises(FaceError) as ctx:
            self.service.mutate('team', 'enroll', payload)

        self.assertEqual(ctx.exception.code, 'AGGREGATE_MISMATCH')
        self.assertEqual(ctx.exception.status, 400)
        self.assertEqual(str(ctx.exception), 'Submitted template does not match the observations')

        # Verify database has no captures for p2
        with self.repo.transaction() as db:
            count = db.execute(
                "SELECT count(*) FROM captures WHERE collection_id='team' AND person_id='p2'"
            ).fetchone()[0]
            self.assertEqual(count, 0)
            person = self.service.person(db, 'team', 'p2')
            self.assertEqual(person['state'], 'NOT_ENROLLED')
            self.assertIsNone(person['encrypted_template'])

        # 2. Matching template succeeds
        payload['template'] = true_aggregate
        payload['capture_session'] = str(uuid.uuid4())
        payload['idempotency_key'] = str(uuid.uuid4())

        result = self.service.mutate('team', 'enroll', payload)
        self.assertEqual(result['person_id'], 'p2')
        self.assertEqual(result['state'], 'PENDING')

        with self.repo.transaction() as db:
            count = db.execute(
                "SELECT count(*) FROM captures WHERE collection_id='team' AND person_id='p2'"
            ).fetchone()[0]
            self.assertEqual(count, 1)
            person = self.service.person(db, 'team', 'p2')
            self.assertEqual(person['state'], 'PENDING')
            self.assertIsNotNone(person['encrypted_template'])
