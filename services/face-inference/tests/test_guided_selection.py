"""Versioned candidate selection; synthetic pixels do not prove live-face accuracy."""
import io
import json
import sys
import unittest
from pathlib import Path
import numpy as np

sys.path.insert(0, str(Path(__file__).resolve().parent))
import test_guided_enrollment as old_guided
from matcher import MatcherUnavailable
from service import InferenceService, InferenceError
from safe_logging import SafeFaceLogger

PROTOCOL = 'guided-five-select-v1'
POSES = [(0, .55), (0, .55), (-.12, .55), (-.12, .55),
         (.12, .55), (.12, .55), (0, .48), (0, .48),
         (0, .62), (0, .62), (0, .55), (0, .55), (0, .55)]


class GuidedSelectionTest(unittest.TestCase):
    def setUp(self):
        fixture = old_guided.GuidedEnrollmentTest('test_named_windows_and_capability')
        fixture.setUp()
        self.addCleanup(fixture.doCleanups)
        self.fixture = fixture
        fixture.frames = [bytes([i + 1]) for i in range(13)]
        fixture.poses = list(POSES)
        fixture.features = []
        for angle in [.01, .025, .03, .045, .05, .065, .07, .085, .09, .105, .12, .07, .065]:
            v = np.zeros(128, dtype=np.float32)
            v[:2] = [np.cos(angle), np.sin(angle)]
            fixture.features.append(v)
        fixture.reference = fixture.features[0].tolist()

    def encode(self):
        f = self.fixture
        return f.matcher.encode_enrollment_v2(f.frames[:10], f.frames[10:], f.reference,
                                               pose_protocol=PROTOCOL)

    def test_capability_advertises_thirteen_inputs_and_six_outputs(self):
        capability = self.fixture.matcher.guided_selection_capability()
        self.assertEqual(PROTOCOL, capability['pose_protocol'])
        self.assertEqual(10, capability['burst_a_count'])
        self.assertEqual(3, capability['burst_b_count'])
        self.assertEqual(2, capability['samples_per_pose'])
        self.assertEqual(6, capability['template_count'])

    def test_one_real_frame_per_pose_and_one_holdout(self):
        f = self.fixture
        result = self.encode()
        indices = result['selected_burst_a_indices']
        self.assertEqual(5, len(indices))
        self.assertEqual([i // 2 for i in indices], list(range(5)))
        self.assertEqual(6, len(result['embeddings']))
        for pose, index in enumerate(indices):
            np.testing.assert_allclose(result['embeddings'][pose], f.features[index])
        b = result['burst_b_template_index']
        np.testing.assert_allclose(result['embeddings'][5], f.features[10 + b])
        self.assertEqual(13, len(result['quality_summary']['accepted_poses']))
        self.assertEqual(6, sum(p['selected'] for p in result['quality_summary']['accepted_poses']))

    def test_poor_quality_partner_can_be_excluded(self):
        self.fixture.rejected.add(1)
        result = self.encode()
        self.assertEqual(0, result['selected_burst_a_indices'][0])
        self.fixture.rejected.add(0)
        with self.assertRaisesRegex(MatcherUnavailable, 'QUALITY_FAILED|POSE_'):
            self.encode()

    def test_wrong_identity_or_reused_pixel_blocks_entire_attempt(self):
        self.fixture.features[1] = -self.fixture.features[1]
        with self.assertRaisesRegex(MatcherUnavailable, 'REFERENCE_MISMATCH'):
            self.encode()
        self.fixture.features[1] = self.fixture.features[0]
        self.fixture.frames[1] = self.fixture.frames[0]
        with self.assertRaisesRegex(MatcherUnavailable, 'REPLAYED_FRAMES'):
            self.encode()

    def test_service_rejects_wrong_count_without_changing_legacy(self):
        f = self.fixture
        service = InferenceService(f.matcher, f.matcher.settings)
        with self.assertRaises(InferenceError):
            service.encode_v2(f.frames[:9], f.frames[10:], f.reference, pose_protocol=PROTOCOL)
        old = service.encode_v2(f.frames[::2][:5], f.frames[10:], f.reference, pose_protocol='guided-five-v1')
        self.assertEqual(6, len(old['embeddings']))

    def test_pose_logs_mark_selected_frames_without_biometrics(self):
        f = self.fixture
        output = io.StringIO()
        service = InferenceService(f.matcher, f.matcher.settings, logger=SafeFaceLogger(output))
        service.encode_v2(f.frames[:10], f.frames[10:], f.reference, pose_protocol=PROTOCOL)
        poses = [json.loads(line) for line in output.getvalue().splitlines()
                 if 'ENROLLMENT_POSE_ACCEPTED' in line]
        self.assertEqual(13, len(poses))
        self.assertEqual(6, sum(p['selected'] for p in poses))
        self.assertTrue(all(isinstance(p['yaw'], (int, float)) and isinstance(p['pitch'], (int, float))
                            and 'embedding' not in p and 'frame' not in p for p in poses))


if __name__ == '__main__':
    unittest.main()
