"""Regression coverage for corrected SFace preprocessing and identity separation."""

import sys
import unittest
from pathlib import Path
from unittest.mock import MagicMock

import numpy as np

APP_DIR = Path(__file__).resolve().parents[1] / "app"
sys.path.insert(0, str(APP_DIR))

from encoder import SFaceEncoder
from matcher import OnnxFaceMatcher
from schemas import QualityResult
from settings import Settings


def unit(axis: int, secondary: float = 0.0):
    value = np.zeros(128, dtype=np.float32)
    value[axis] = 1.0
    if secondary:
        value[(axis + 1) % 128] = secondary
        value /= np.linalg.norm(value)
    return value


class IdentitySeparationV3Test(unittest.TestCase):
    def matcher(self, features):
        matcher = OnnxFaceMatcher.__new__(OnnxFaceMatcher)
        matcher.settings = Settings(
            accept_threshold=0.90,
            single_candidate_accept_threshold=0.95,
            template_support_threshold=0.85,
            min_template_support=2,
            min_margin=0.10,
            enrollment_holdout_min_threshold=0.85,
            calibration_approved=True,
            policy_version="face-policy-v3",
        )
        matcher.np = np
        matcher._features = MagicMock(return_value=(
            features,
            QualityResult(1, True, True, True, True, True),
            [0.99] * len(features),
            [0.0] * len(features),
            (),
        ))
        matcher.pad = MagicMock()
        matcher.pad.classify.return_value = "LIVE"
        return matcher

    def test_sface_input_is_raw_rgb_and_output_is_l2_normalized(self):
        encoder = SFaceEncoder.__new__(SFaceEncoder)
        encoder._np = np
        encoder._cv2 = MagicMock()
        encoder._cv2.COLOR_BGR2RGB = 4
        encoder._cv2.cvtColor.return_value = np.full((112, 112, 3), [10, 20, 30], dtype=np.uint8)
        encoder._align = MagicMock(return_value=np.zeros((112, 112, 3), dtype=np.uint8))
        encoder._input_name = "data"
        encoder._output_name = "feature"
        encoder._session = MagicMock()
        encoder._session.run.return_value = [np.asarray([[3.0, 4.0]], dtype=np.float32)]

        result = encoder.encode(np.zeros((200, 200, 3), dtype=np.uint8), np.zeros(15, dtype=np.float32))

        blob = encoder._session.run.call_args.args[1]["data"]
        self.assertEqual(np.float32, blob.dtype)
        self.assertEqual(10.0, float(blob[0, 0, 0, 0]))
        self.assertEqual(20.0, float(blob[0, 1, 0, 0]))
        self.assertEqual(30.0, float(blob[0, 2, 0, 0]))
        np.testing.assert_allclose([0.6, 0.8], result, atol=1e-6)

    def test_single_candidate_has_no_fabricated_second_score_or_margin(self):
        probe = unit(0)
        result = self.matcher([probe, probe, probe]).match(
            [b"one", b"two", b"three"],
            {"person-a": [probe.tolist(), probe.tolist(), probe.tolist()]},
        )

        self.assertEqual("ACCEPT", result.decision)
        self.assertIsNone(result.top2_score)
        self.assertIsNone(result.margin)
        self.assertGreaterEqual(result.template_support, 2)

    def test_close_distinct_identities_fail_ambiguous_instead_of_selecting_top1(self):
        probe = unit(0)
        close = unit(0, 0.20)
        result = self.matcher([probe, probe, probe]).match(
            [b"one", b"two", b"three"],
            {
                "person-a": [probe.tolist(), probe.tolist(), probe.tolist()],
                "person-b": [close.tolist(), close.tolist(), close.tolist()],
            },
        )

        self.assertEqual("REVIEW", result.decision)
        self.assertIsNone(result.candidate_ref)
        self.assertEqual("AMBIGUOUS_IDENTITY", result.reason_code)

    def test_one_high_reference_is_not_enough_template_support(self):
        probe = unit(0)
        weak = unit(1)
        result = self.matcher([probe, probe, probe]).match(
            [b"one", b"two", b"three"],
            {"person-a": [probe.tolist(), weak.tolist(), weak.tolist()]},
        )

        self.assertEqual("REVIEW", result.decision)
        self.assertIsNone(result.candidate_ref)
        self.assertEqual("INSUFFICIENT_TEMPLATE_SUPPORT", result.reason_code)

    def test_three_frames_must_choose_the_same_identity(self):
        a = unit(0)
        b = unit(1)
        result = self.matcher([a, a, b]).match(
            [b"one", b"two", b"three"],
            {
                "person-a": [a.tolist(), a.tolist(), a.tolist()],
                "person-b": [b.tolist(), b.tolist(), b.tolist()],
            },
        )

        self.assertEqual("REVIEW", result.decision)
        self.assertIsNone(result.candidate_ref)
        self.assertEqual("IDENTITY_CONSENSUS_FAILED", result.reason_code)


if __name__ == "__main__":
    unittest.main()
