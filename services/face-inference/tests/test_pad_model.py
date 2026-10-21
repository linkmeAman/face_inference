"""FACE-PAD-01 passive anti-spoof model contract."""

from __future__ import annotations

import math
import sys
import unittest
from pathlib import Path

import numpy as np


SERVICE_DIR = Path(__file__).resolve().parents[1]
sys.path.insert(0, str(SERVICE_DIR / "app"))

from pad import PassivePadClassifier, head_turn_passed  # type: ignore  # noqa: E402
from quality import pose_yaw_indicator  # type: ignore  # noqa: E402


MODEL = SERVICE_DIR / "models" / "anti-spoof-mn3.onnx"


class PassivePadModelTest(unittest.TestCase):
    def test_official_model_loads_and_returns_bounded_real_probability(self):
        classifier = PassivePadClassifier(MODEL)
        image = np.full((256, 256, 3), 127, dtype=np.uint8)
        face = np.asarray(
            [64, 64, 128, 128, 100, 110, 155, 110, 128, 135, 105, 165, 150, 165],
            dtype=np.float32,
        )
        score = classifier.real_probability(image, face)
        self.assertTrue(math.isfinite(score))
        self.assertGreaterEqual(score, 0.0)
        self.assertLessEqual(score, 1.0)

    def test_policy_is_fail_closed_without_approved_thresholds(self):
        self.assertEqual("UNKNOWN", PassivePadClassifier.classify([0.99], None, None))
        self.assertEqual("LIVE", PassivePadClassifier.classify([0.91, 0.90, 0.92], 0.85, 0.20))
        self.assertEqual("LIVE", PassivePadClassifier.classify([0.91, 0.10, 0.92], 0.85, 0.20))
        self.assertEqual("SPOOF", PassivePadClassifier.classify([0.10, 0.12, 0.92], 0.85, 0.20))
        self.assertEqual("CHALLENGE", PassivePadClassifier.classify([0.70, 0.72, 0.71], 0.85, 0.20))

    def test_preprocessing_uses_square_edge_padding_rgb_and_approved_normalization(self):
        classifier = PassivePadClassifier(MODEL)
        image = np.zeros((80, 120, 3), dtype=np.uint8)
        image[:, :] = [10, 20, 30]  # BGR
        face = np.asarray([-5, 5, 40, 60] + [0] * 10, dtype=np.float32)
        crop = classifier._square_crop(image, face)
        self.assertEqual(crop.shape[0], crop.shape[1])
        self.assertEqual(90, crop.shape[0])  # max(40, 60) * bbox_inc 1.5
        fractional = np.asarray([5.25, 7.75, 41.5, 59.5] + [0] * 10, dtype=np.float32)
        fractional_crop = classifier._square_crop(image, fractional)
        self.assertEqual(fractional_crop.shape[0], fractional_crop.shape[1])
        blob = classifier._preprocess(image, face)
        self.assertEqual((1, 3, 128, 128), blob.shape)
        self.assertAlmostEqual((30.0 - 151.2405) / 63.0105, float(blob[0, 0, 64, 64]), places=4)
        self.assertAlmostEqual((10.0 - 107.8395) / 55.0035, float(blob[0, 2, 64, 64]), places=4)

    def test_head_turn_uses_signed_landmark_motion(self):
        frontal = np.asarray([0, 0, 100, 100, 30, 30, 70, 30, 50, 52, 35, 75, 65, 75], dtype=np.float32)
        left = np.asarray([0, 0, 100, 100, 30, 30, 70, 30, 42, 52, 35, 75, 65, 75], dtype=np.float32)
        right = np.asarray([0, 0, 100, 100, 30, 30, 70, 30, 58, 52, 35, 75, 65, 75], dtype=np.float32)
        baseline = pose_yaw_indicator(frontal)
        self.assertAlmostEqual(0.0, baseline)
        self.assertTrue(head_turn_passed("LOOK_LEFT", baseline, [pose_yaw_indicator(left)] * 3, 0.12))
        self.assertTrue(head_turn_passed("LOOK_RIGHT", baseline, [pose_yaw_indicator(right)] * 3, 0.12))
        self.assertFalse(head_turn_passed("LOOK_LEFT", baseline, [pose_yaw_indicator(right)] * 3, 0.12))


if __name__ == "__main__":
    unittest.main()
