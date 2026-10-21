from concurrent.futures import ThreadPoolExecutor
import copy
import hashlib
import json
from pathlib import Path
import subprocess
import tempfile
import unittest
import uuid

from cryptography.fernet import Fernet

from standalone.config import ROOT, contract, load_config
from standalone.math import FaceError, aggregate, cosine, identify, normalize, vector
from standalone.service import FaceService
from standalone.storage import SQLiteRepository


def unit(index):
    return [1.0 if i == index else 0.0 for i in range(128)]


class ServiceTests(unittest.TestCase):
    def setUp(self):
        self.folder = tempfile.TemporaryDirectory()
        self.config = load_config(Path(self.folder.name) / 'config.json')
        self.model = contract()
        self.repo = SQLiteRepository(self.config['database'], self.config['encryption_key'])
        self.now = 1800000000.0
        self.service = FaceService(self.repo, self.config, self.model, clock=lambda: self.now)
        self.collection('team')
        for person in ('a', 'b', 'c'):
            self.person(person)

    def tearDown(self):
        self.folder.cleanup()

    def mutate(self, action, payload, collection='team', person=None):
        return self.service.mutate(collection, action, {'idempotency_key': str(uuid.uuid4()), **payload}, person)

    def collection(self, collection):
        return self.mutate('collection', {'id': collection, 'name': collection}, collection)

    def person(self, person, collection='team'):
        return self.mutate('person', {'id': person, 'name': 'Person ' + person}, collection)

    def capture(self, person='a', value=None):
        values = [value or unit(0)] * 5
        return dict(person_id=person, observations=values, template=aggregate(values), consent=True,
            capture_session=str(uuid.uuid4()), model_version=self.model['model_version'],
            preprocessing_version=self.model['preprocessing_version'])

    def recognize(self, values=None, **extra):
        payload = self.capture(value=unit(0))
        for key in ('person_id', 'template', 'consent'):
            payload.pop(key)
        payload['observations'] = values or [unit(0)] * 5
        return self.mutate('recognize', {**payload, **extra})

    def test_pending_default_and_evaluation_recognition(self):
        result = self.mutate('enroll', self.capture())
        self.assertEqual(result['state'], 'PENDING')
        self.assertFalse(result['verified'])
        result = self.recognize()
        self.assertEqual((result['state'], result['person_id']), ('SUGGESTED', 'a'))
        self.assertFalse(result['verified'])
        self.assertFalse(result['business_action'])

    def test_duplicate_rejection_and_collection_isolation(self):
        self.mutate('enroll', self.capture())
        result = self.mutate('enroll', self.capture('b'))
        self.assertEqual((result['state'], result['duplicate_person_id']), ('REJECTED_DUPLICATE', 'a'))
        self.collection('other')
        self.person('a', 'other')
        self.assertEqual(self.mutate('enroll', self.capture(), 'other')['state'], 'PENDING')
        self.assertEqual(len(self.service.list_people('other')), 1)

    def test_unknown_conflicting_ambiguous_and_eligibility(self):
        self.mutate('enroll', self.capture())
        self.mutate('enroll', self.capture('b', unit(1)))
        self.assertEqual(self.recognize([unit(2)] * 5)['state'], 'UNKNOWN')
        self.assertEqual(self.recognize([unit(0), unit(1), unit(0)])['reason'], 'CONFLICTING_OBSERVATIONS')
        ambiguous = normalize([1, 1] + [0] * 126)
        self.assertEqual(self.recognize([ambiguous] * 5)['reason'], 'AMBIGUOUS')
        self.assertEqual(self.recognize(eligible_ids=[])['reason'], 'NO_ELIGIBLE_REFERENCE')
        self.assertEqual(self.recognize(eligible_ids=['b'])['state'], 'UNKNOWN')
        with self.assertRaises(FaceError):
            self.recognize(eligible_ids=['not-in-this-collection'])

    def test_validation_and_versions(self):
        changes = [dict(consent=False), dict(model_version='another-model'), dict(preprocessing_version='other'),
            dict(template=unit(1)), dict(observations=[unit(0)]), dict(observations=[[0.0] * 128] * 5)]
        for change in changes:
            with self.subTest(change=tuple(change)):
                with self.assertRaises(FaceError):
                    self.mutate('enroll', {**self.capture(), **change})
        with self.assertRaises(FaceError):
            self.mutate('enroll', {**self.capture(), 'recognition_threshold': 0})
        self.assertEqual(self.service.list_people('team')[0]['state'], 'NOT_ENROLLED')

    def test_exact_retry_changed_payload_and_capture_reuse(self):
        payload = {**self.capture(), 'idempotency_key': str(uuid.uuid4())}
        first = self.service.mutate('team', 'enroll', payload)
        self.assertEqual(self.service.mutate('team', 'enroll', payload), first)
        with self.assertRaises(FaceError) as error:
            self.service.mutate('team', 'enroll', {**payload, 'person_id': 'b'})
        self.assertEqual(error.exception.code, 'IDEMPOTENCY_CONFLICT')
        with self.assertRaises(FaceError) as error:
            self.mutate('enroll', {**payload, 'idempotency_key': str(uuid.uuid4())})
        self.assertEqual(error.exception.code, 'CAPTURE_REUSED')
        with self.repo.transaction() as db:
            self.assertEqual(db.execute('SELECT COUNT(*) FROM captures').fetchone()[0], 1)

    def test_concurrent_duplicate_saves_serialize(self):
        with ThreadPoolExecutor(max_workers=2) as pool:
            results = list(pool.map(lambda person: self.mutate('enroll', self.capture(person)), ('a', 'b')))
        self.assertEqual(sorted(row['state'] for row in results), ['PENDING', 'REJECTED_DUPLICATE'])

    def test_original_reference_is_immutable_and_conflicting_recapture_preserves_it(self):
        self.mutate('enroll', self.capture())
        self.mutate('enroll', self.capture('b', unit(1)))
        with self.repo.transaction() as db:
            original = bytes(self.service.person(db, 'team', 'a')['encrypted_template'])
        result = self.mutate('enroll', self.capture('a', unit(1)))
        self.assertTrue(result['previous_reference_preserved'])
        with self.repo.transaction() as db:
            self.assertEqual(bytes(self.service.person(db, 'team', 'a')['encrypted_template']), original)
        self.assertEqual(self.recognize()['person_id'], 'a')

    def test_persistence_encryption_wrong_key_and_deletion(self):
        self.mutate('enroll', self.capture())
        with self.repo.transaction() as db:
            ciphertext = self.service.person(db, 'team', 'a')['encrypted_template']
            self.assertNotIn(json.dumps(unit(0)).encode(), ciphertext)
            self.assertEqual(self.repo.decrypt(ciphertext), unit(0))
        restarted = FaceService(SQLiteRepository(self.config['database'], self.config['encryption_key']), self.config, self.model, clock=lambda: self.now)
        self.assertEqual(restarted.list_people('team')[0]['state'], 'PENDING')
        with self.assertRaises(RuntimeError):
            SQLiteRepository(self.config['database'], Fernet.generate_key().decode())
        self.mutate('delete', {}, person='a')
        with self.repo.transaction() as db:
            self.assertIsNone(self.service.person(db, 'team', 'a')['encrypted_template'])
            self.assertEqual(db.execute('SELECT COUNT(*) FROM captures').fetchone()[0], 0)
        self.assertEqual(self.recognize()['state'], 'UNKNOWN')

    def test_expiry_erases_ciphertext_and_prevents_recognition(self):
        self.mutate('enroll', self.capture())
        self.now += 31 * 86400
        self.assertEqual(self.recognize()['state'], 'UNKNOWN')
        with self.repo.transaction() as db:
            self.assertIsNone(self.service.person(db, 'team', 'a')['encrypted_template'])
            self.assertEqual(db.execute('SELECT COUNT(*) FROM captures').fetchone()[0], 0)

    def test_collect_mode_does_not_recognize(self):
        self.config['mode'] = 'COLLECT'
        self.mutate('enroll', self.capture())
        self.assertEqual(self.recognize()['state'], 'COLLECTION_ONLY')

    def test_observations_use_independent_dates_and_do_not_adapt_gallery(self):
        self.mutate('enroll', self.capture())
        with self.assertRaises(FaceError):
            self.mutate('observe', self.capture(value=unit(1)))
        self.now += 86400
        self.assertEqual(self.mutate('observe', self.capture(value=unit(1)))['split'], 'calibration')
        self.now += 86400
        self.assertEqual(self.mutate('observe', self.capture(value=unit(1)))['split'], 'validation')
        self.assertEqual(self.recognize()['person_id'], 'a')

    def approve_fixture(self):
        # Structural approval-loader fixture, never real biometric evidence.
        report = dict(source='consented-live-cameras', blockers=[], identities=20, samples=100,
            model_version=self.model['model_version'], preprocessing_version=self.model['preprocessing_version'],
            decision_evaluation=dict(version='browser-face-decisions-v1', complete=True,
                full_validation_batches=2, validation={'counts': {'known': 1, 'unknown': 1}}),
            recommendations={'DUPLICATE_THRESHOLD': {'threshold': .85}, 'ATTENDANCE_THRESHOLD': {'threshold': .65}})
        path = Path(self.folder.name) / 'report.json'
        path.write_text(json.dumps(report))
        evidence = {k: report[k] for k in ('source', 'identities', 'samples', 'model_version', 'preprocessing_version')}
        evidence.update(approved=True, approved_by='unit-test-only', approved_at='2026-10-21',
            independent_validation=True, independent_workflow_validation=True,
            DUPLICATE_THRESHOLD=.85, ATTENDANCE_THRESHOLD=.65, report_path=str(path),
            report_sha256=hashlib.sha256(path.read_bytes()).hexdigest())
        policy = path.with_name('policy.json')
        policy.write_text(json.dumps(evidence))
        self.config.update(mode='APPROVED', policy_file=str(policy), approved_collection_ids=['team'])
        return path

    def test_approved_activation_and_revoked_report(self):
        self.mutate('enroll', self.capture())
        report = self.approve_fixture()
        self.assertEqual(self.recognize()['state'], 'MATCHED')
        self.assertEqual(self.service.list_people('team')[0]['state'], 'ACTIVE')
        report.write_text('{}')
        with self.assertRaises(RuntimeError):
            self.recognize()

    def test_pending_conflicts_never_activate_arbitrary_winner(self):
        self.mutate('enroll', self.capture())
        with self.repo.transaction() as db:
            db.execute("UPDATE people SET encrypted_template=?,state='PENDING',model_version=?,preprocessing_version=?,consented_at=?,expires_at=? WHERE collection_id='team' AND id='b'",
                (self.repo.encrypt(unit(0)), self.model['model_version'], self.model['preprocessing_version'], self.now, self.now + 86400))
        self.approve_fixture()
        self.assertEqual(self.recognize()['state'], 'UNKNOWN')
        states = {p['id']: p['state'] for p in self.service.list_people('team')}
        self.assertEqual((states['a'], states['b']), ('RECAPTURE_REQUIRED', 'RECAPTURE_REQUIRED'))

    def test_approved_mode_fails_without_report(self):
        self.config['mode'] = 'APPROVED'
        with self.assertRaises(RuntimeError):
            FaceService(self.repo, self.config, self.model)

    def test_approved_policy_is_restricted_to_explicit_collections(self):
        self.collection('other')
        self.person('a', 'other')
        self.approve_fixture()
        with self.assertRaises(FaceError) as error:
            self.mutate('enroll', self.capture(), 'other')
        self.assertEqual(error.exception.code, 'SCOPE_NOT_APPROVED')

    def test_evidence_export_preserves_real_labels_source_and_reference(self):
        self.mutate('enroll', {**self.capture(), 'device':'camera-a', 'lighting':'measured-normal'})
        self.now += 86400
        self.mutate('observe', {**self.capture(value=unit(1)), 'device':'camera-b', 'lighting':'measured-bright'})
        rows = self.service.export_records('team')
        self.assertEqual(len(rows), 2)
        self.assertEqual(sum(row['enrollment_candidate'] for row in rows), 1)
        self.assertEqual([row['identity'] for row in rows], ['a', 'a'])
        self.assertEqual(rows[1]['observations'], [unit(1)] * 5)
        self.assertEqual(rows[0]['source'], 'unvalidated-camera')
        self.assertEqual(rows[0]['evaluation_context']['eligible_identities'], ['a', 'b', 'c'])
        self.assertEqual(rows[0]['model_version'], self.model['model_version'])
        self.mutate('delete', {}, person='a')
        self.assertEqual(self.service.export_records('team'), [])


class MathTests(unittest.TestCase):
    def test_original_javascript_parity_on_exported_synthetic_vectors(self):
        records = json.loads((ROOT / 'datasets/reports/ccc-controlface-inference-20261009.json').read_text())['images']
        real = [row['embedding'] for row in records if row.get('embedding')]
        batches = [[real[0]] * 5, real[:5], [unit(0)] * 4 + [unit(1)]]
        script = "import {aggregate} from './pf/js/browser-face/core.mjs';let s='';for await(const c of process.stdin)s+=c;process.stdout.write(JSON.stringify(JSON.parse(s).map(v=>aggregate(v).template)));"
        result = subprocess.run(['node', '--input-type=module', '-e', script], input=json.dumps(batches), text=True, capture_output=True, cwd=ROOT, check=True)
        for batch, expected in zip(batches, json.loads(result.stdout)):
            self.assertLess(max(abs(a-b) for a, b in zip(aggregate(batch), expected)), 1e-12)

    def test_vector_and_aggregation_rejection(self):
        for value in ([0] * 128, [True] + [0] * 127, [float('nan')] + [0] * 127, [1] * 127):
            with self.subTest(value_type=type(value[0]).__name__):
                with self.assertRaises(FaceError):
                    vector(value)
        with self.assertRaises(FaceError):
            aggregate([unit(0)] * 2)


if __name__ == '__main__':
    unittest.main()
