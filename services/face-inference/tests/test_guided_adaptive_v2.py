"""Adaptive protocol checks on decoded synthetic frames; live pose needs camera trials."""
import io
import json
import sys
import unittest
from pathlib import Path

sys.path.insert(0, str(Path(__file__).resolve().parent))
import test_guided_enrollment as legacy_fixture
from matcher import MatcherUnavailable
from service import InferenceService
from safe_logging import SafeFaceLogger

PROTOCOL = 'guided-adaptive-v2'
POSES = [(0, .55), (.01, .55), (-.12, .55), (.12, .55), (-.11, .55),
         (0, .55), (0, .55), (0, .55)]


class AdaptiveEnrollmentTest(unittest.TestCase):
    def setUp(self):
        fixture = legacy_fixture.GuidedEnrollmentTest('test_named_windows_and_capability')
        fixture.setUp()
        self.addCleanup(fixture.doCleanups)
        fixture.poses = list(POSES)
        self.fixture = fixture

    def encode(self):
        f = self.fixture
        return f.matcher.encode_enrollment_v2(f.frames[:5], f.frames[5:], f.reference,
                                               pose_protocol=PROTOCOL)

    def test_capability_and_six_real_templates(self):
        f = self.fixture
        capability = f.matcher.guided_adaptive_capability()
        self.assertEqual(PROTOCOL, capability['pose_protocol'])
        self.assertEqual((5, 3, 6, 300, 750),
                         (capability['burst_a_count'], capability['burst_b_count'],
                          capability['template_count'], capability['stable_ms'], capability['departure_ms']))
        result = self.encode()
        self.assertEqual(6, len(result['embeddings']))
        self.assertEqual(['BURST_A'] * 5 + ['BURST_B'], result['template_sources'])
        self.assertEqual(['centre','centre','left','right','left','centre','centre','centre'],
                         [p['pose'] for p in result['quality_summary']['accepted_poses']])

    def test_extra_can_use_either_valid_side(self):
        self.fixture.poses[4] = (.11, .55)
        result = self.encode()
        self.assertEqual('right', result['quality_summary']['accepted_poses'][4]['pose'])

    def test_wrong_or_missing_pose_rejects(self):
        for index, pose in [(0, (.08, .55)), (2, (.12, .55)),
                            (3, (.17, .55)), (4, (0, .55)), (6, (0, .78)),
                            (2, (float('nan'), .55))]:
            with self.subTest(index=index, pose=pose):
                self.fixture.poses = list(POSES)
                self.fixture.poses[index] = pose
                with self.assertRaisesRegex(MatcherUnavailable, 'POSE_'):
                    self.encode()

    def test_every_frame_quality_reference_and_freshness_rechecked(self):
        f = self.fixture
        f.rejected.add(4)
        with self.assertRaisesRegex(MatcherUnavailable, 'QUALITY_REJECTED'):
            self.encode()
        f.rejected.clear()
        f.features[4] = -f.features[4]
        with self.assertRaisesRegex(MatcherUnavailable, 'REFERENCE_MISMATCH'):
            self.encode()
        f.features[4] = f.features[0]
        f.frames[7] = f.frames[0]
        with self.assertRaisesRegex(MatcherUnavailable, 'REPLAYED_FRAMES'):
            self.encode()

    def test_service_logs_pose_without_photo_or_embedding(self):
        f = self.fixture
        stream = io.StringIO()
        service = InferenceService(f.matcher, f.matcher.settings, logger=SafeFaceLogger(stream))
        service.encode_v2(f.frames[:5], f.frames[5:], f.reference, pose_protocol=PROTOCOL)
        poses = [json.loads(line) for line in stream.getvalue().splitlines()
                 if 'ENROLLMENT_POSE_ACCEPTED' in line]
        self.assertEqual(8, len(poses))
        self.assertTrue(all('frame' not in item and 'embedding' not in item for item in poses))


if __name__ == '__main__':
    unittest.main()
