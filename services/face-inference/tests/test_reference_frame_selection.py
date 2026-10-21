"""Reference frame provenance with synthetic pixels and mocked model boundaries."""
import sys
import unittest
from pathlib import Path
from types import SimpleNamespace
from unittest.mock import Mock, patch

import numpy as np

sys.path.insert(0, str(Path(__file__).resolve().parents[1] / 'app'))
from matcher import OnnxFaceMatcher, MatcherUnavailable


class ReferenceFrameSelectionTest(unittest.TestCase):
    def encode(self, values, rejected=()):
        matcher = object.__new__(OnnxFaceMatcher)
        matcher.np = np
        matcher.settings = SimpleNamespace(model_version_id=1, model_version='fixture', policy_version='fixture',
                                           pad_live_threshold=0.9, pad_spoof_threshold=0.1)
        matcher._decode_with_geometry = lambda frame, context: (np.full((4, 4, 3), frame[0], dtype=np.uint8), {'source': frame[0]})
        matcher.detector = Mock()
        matcher.detector.detect.return_value = [np.zeros(15)]
        matcher.encoder = Mock()
        matcher.encoder.encode.side_effect = lambda image, face: np.array([float(image[0, 0, 0]), 1.0])
        matcher.pad = Mock()
        matcher.pad.real_probability.return_value = 0.99

        def quality(image, *args, **kwargs):
            value = int(image[0, 0, 0])
            return dict(accepted=value not in rejected, reasons=['BLUR'] if value in rejected else [],
                        sharpness=value, facing_camera=True, profile_ok=True, sharpness_ok=True,
                        exposure_ok=True, size_ok=True, centred_ok=True)

        with patch('matcher.select_face', return_value={'selected_face_index': 0}), patch('matcher.assess_face_quality', side_effect=quality):
            result = matcher.encode_reference([bytes([value]) for value in values])
        chosen = int(matcher.encoder.encode.call_args.args[0][0, 0, 0])
        expected = np.array([chosen, 1.0])
        np.testing.assert_allclose(result['embedding'], expected / np.linalg.norm(expected), rtol=1e-6)
        return result

    def test_sharpest_frame_index_identifies_the_encoded_pixels(self):
        result = self.encode([2, 9, 4])
        self.assertEqual(1, result.get('selected_frame_index'))
        self.assertEqual(9, result['frame_geometry']['source'])

    def test_rejected_frames_do_not_renumber_the_selected_index(self):
        result = self.encode([9, 2, 7], rejected=(9,))
        self.assertEqual(2, result.get('selected_frame_index'))
        self.assertEqual(7, result['frame_geometry']['source'])

    def test_single_frame_returns_zero(self):
        self.assertEqual(0, self.encode([7]).get('selected_frame_index'))

    def test_equal_sharpness_keeps_first_frame(self):
        self.assertEqual(0, self.encode([7, 7, 7]).get('selected_frame_index'))

    def test_no_usable_frame_still_rejects(self):
        with self.assertRaises(MatcherUnavailable):
            self.encode([2, 4], rejected=(2, 4))


if __name__ == '__main__':
    unittest.main()
