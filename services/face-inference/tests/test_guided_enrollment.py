"""Guided enrollment: synthetic pixels, mocked detector/encoder, real orchestration.
These tests do not establish pose accuracy on live people.
"""
import base64
import http.client
import io
import json
import sys
import threading
import unittest
from pathlib import Path
from unittest.mock import Mock, patch
import numpy as np
sys.path.insert(0, str(Path(__file__).resolve().parents[1] / 'app'))
import matcher as module
from matcher import OnnxFaceMatcher, MatcherUnavailable
from settings import Settings
from service import InferenceService, InferenceError
from safe_logging import SafeFaceLogger
from main import InferenceHandler, LimitedThreadingHTTPServer

PROTOCOL = 'guided-five-v1'
POSES = [(0, .55), (-.12, .55), (.12, .55), (0, .48), (0, .62), (0, .55), (0, .55), (0, .55)]

class GuidedEnrollmentTest(unittest.TestCase):
    def setUp(self):
        self.matcher = object.__new__(OnnxFaceMatcher)
        self.matcher.np = np
        self.matcher.settings = Settings(shared_secret='guided-test', calibration_approved=True)
        self.frames = [bytes([i + 1]) for i in range(8)]
        self.poses = list(POSES)
        self.rejected = set()
        self.features = []
        for angle in [.01, .02, .03, .04, .05, .12, .07, .065]:
            v = np.zeros(128, dtype=np.float32)
            v[:2] = [np.cos(angle), np.sin(angle)]
            self.features.append(v)
        self.reference = self.features[0].tolist()
        self.matcher._decode_with_geometry = lambda f, c: (np.full((8, 8, 3), f[0], dtype=np.uint8), {})
        self.matcher.detector = Mock()
        self.matcher.detector.detect.return_value = [np.ones(15)]
        self.matcher.encoder = Mock()
        self.matcher.encoder.encode.side_effect = lambda image, face: self.features[int(image[0, 0, 0]) - 1]
        def evidence(image, *args, **kwargs):
            i = int(image[0, 0, 0]) - 1
            yaw, pitch = self.poses[i]
            return dict(accepted=i not in self.rejected, inside_guide=True, pose_yaw=yaw, pose_pitch=pitch,
                        size_ok=True, centred_ok=True, reasons=['BLUR'] if i in self.rejected else [])
        for p in [patch('matcher.select_face', return_value={'selected_face_index': 0, 'guide_candidate_count': 1}),
                  patch('matcher.quality_flags', return_value=(True, True, True)),
                  patch('matcher.assess_face_quality', side_effect=evidence)]:
            p.start()
            self.addCleanup(p.stop)

    def encode(self, **kwargs):
        self.assertIn('pose_protocol', __import__('inspect').signature(self.matcher.encode_enrollment_v2).parameters,
                      'matcher must explicitly support guided enrollment')
        return self.matcher.encode_enrollment_v2(self.frames[:5], self.frames[5:], self.reference,
                                                pose_protocol=PROTOCOL, **kwargs)

    def test_named_windows_and_capability(self):
        capability = getattr(self.matcher, 'guided_enrollment_capability', None)
        self.assertTrue(callable(capability), 'matcher must advertise named guided windows')
        c = capability()
        self.assertEqual(PROTOCOL, c['pose_protocol'])
        self.assertEqual(6, c['template_count'])
        self.assertEqual(dict(centre_yaw_max=.06, centre_pitch_min=.35, centre_pitch_max=.75,
                              side_yaw_min=.08, side_yaw_max=.16, pitch_tolerance=.08,
                              pitch_delta_min=.05, pitch_delta_max=.10, stable_ms=300), c['windows'])

    def test_six_actual_templates_and_nearest_centroid_b(self):
        result = self.encode()
        self.assertEqual(PROTOCOL, result['pose_protocol'])
        self.assertEqual(['BURST_A'] * 5 + ['BURST_B'], result['template_sources'])
        np.testing.assert_allclose(result['embeddings'][:5], self.features[:5])
        centroid = np.mean(self.features[5:], axis=0)
        nearest = max(range(3), key=lambda i: self.matcher._cosine(self.features[i+5], centroid))
        self.assertNotEqual(0, nearest, 'fixture must expose first-frame selection defect')
        self.assertEqual(nearest, result['burst_b_template_index'])
        np.testing.assert_allclose(result['embeddings'][5], self.features[nearest+5])
        self.assertFalse(np.allclose(result['embeddings'][5], centroid))
        self.assertEqual(8, len(result['quality_summary']['accepted_poses']))

    def test_legacy_stays_three_templates(self):
        result = self.matcher.encode_enrollment_v2(self.frames[:5], self.frames[5:], self.reference)
        self.assertEqual(3, len(result['embeddings']))
        self.assertNotIn('pose_protocol', result)

    def test_wrong_pixels_cannot_be_overridden_by_client_pose_labels(self):
        self.poses[1] = (.12, .55)
        with self.assertRaisesRegex(MatcherUnavailable, 'POSE_TARGET_MISMATCH'):
            self.encode(capture_contexts_a=[{'pose': name, 'pose_yaw': -.12} for name in ['centre','left','right','up','down']])

    def test_missing_nonfinite_and_outside_pose_reject(self):
        for value in [None, float('nan'), float('inf'), .2]:
            with self.subTest(value=value):
                self.poses[1] = (value, .55)
                with self.assertRaisesRegex(MatcherUnavailable, 'POSE_'):
                    self.encode()

    def test_each_pose_and_b_holdout_must_fit_its_window(self):
        for index, wrong in enumerate([(.07,.55), (-.07,.55), (.17,.55), (0,.54), (0,.66), (.08,.55), (0,.70), (0,.80)]):
            with self.subTest(index=index):
                self.poses = list(POSES)
                self.poses[index] = wrong
                with self.assertRaisesRegex(MatcherUnavailable, 'POSE_TARGET_MISMATCH'):
                    self.encode()

    def test_quality_reference_and_replay_checks_still_apply(self):
        self.rejected.add(1)
        with self.assertRaisesRegex(MatcherUnavailable, 'QUALITY_FAILED'):
            self.encode()
        self.rejected.clear()
        self.reference = (-self.features[0]).tolist()
        with self.assertRaisesRegex(MatcherUnavailable, 'REFERENCE_MISMATCH'):
            self.encode()
        self.reference = self.features[0].tolist()
        self.frames[5] = self.frames[0]
        with self.assertRaisesRegex(MatcherUnavailable, 'BURST_REUSE'):
            self.encode()

    def test_service_logs_each_accepted_pose_without_pixels_or_embeddings(self):
        stream = io.StringIO()
        service = InferenceService(self.matcher, self.matcher.settings, logger=SafeFaceLogger(stream))
        self.assertIn('pose_protocol', __import__('inspect').signature(service.encode_v2).parameters)
        service.encode_v2(self.frames[:5], self.frames[5:], self.reference, pose_protocol=PROTOCOL)
        records = [json.loads(line) for line in stream.getvalue().splitlines()]
        poses = [r for r in records if r['event'] == 'ENROLLMENT_POSE_ACCEPTED']
        self.assertEqual(8, len(poses))
        self.assertEqual(['centre','left','right','up','down','centre','centre','centre'], [r['pose'] for r in poses])
        self.assertEqual([p[0] for p in POSES], [r['yaw'] for r in poses])
        self.assertEqual([p[1] for p in POSES], [r['pitch'] for r in poses])
        self.assertTrue(all('embedding' not in r and 'frame' not in r for r in poses))

    def test_http_both_encode_routes_forward_protocol_and_reject_unknown(self):
        service = InferenceService(self.matcher, self.matcher.settings, logger=SafeFaceLogger(io.StringIO()))
        server = LimitedThreadingHTTPServer(('127.0.0.1', 0), InferenceHandler, service, self.matcher.settings)
        thread = threading.Thread(target=server.serve_forever, daemon=True)
        thread.start()
        try:
            for route in ['encode', 'encode-v2']:
                for protocol, expected in [(PROTOCOL, 200), ('unknown', 400)]:
                    payload = dict(burst_a=[base64.b64encode(f).decode() for f in self.frames[:5]],
                                   burst_b=[base64.b64encode(f).decode() for f in self.frames[5:]],
                                   reference_embedding=self.reference, pose_protocol=protocol)
                    conn = http.client.HTTPConnection('127.0.0.1', server.server_port, timeout=2)
                    conn.request('POST', '/internal/enrollments/'+route, json.dumps(payload),
                                 {'Content-Type':'application/json', 'X-Face-Inference-Token':'guided-test'})
                    response = conn.getresponse()
                    body = json.loads(response.read())
                    conn.close()
                    self.assertEqual(expected, response.status, body)
                    if expected == 200:
                        self.assertEqual(6, len(body['embeddings']))
                        self.assertEqual(PROTOCOL, body.get('pose_protocol'))
        finally:
            server.shutdown()
            server.server_close()
            thread.join(2)

    def test_guided_exact_counts_and_unsupported_matcher(self):
        service = InferenceService(self.matcher, self.matcher.settings)
        self.assertIn('pose_protocol', __import__('inspect').signature(service.encode_v2).parameters)
        for a, b in [(self.frames[:4],self.frames[5:]), (self.frames[:6],self.frames[5:]), (self.frames[:5],self.frames[4:])]:
            with self.assertRaises(InferenceError):
                service.encode_v2(a, b, self.reference, pose_protocol=PROTOCOL)
        legacy = InferenceService(object(), self.matcher.settings)
        with self.assertRaisesRegex(InferenceError, 'guided|protocol'):
            legacy.encode_v2(self.frames[:5], self.frames[5:], self.reference, pose_protocol=PROTOCOL)

if __name__ == '__main__':
    unittest.main()
