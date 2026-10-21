"""Tests for ISO quality aggregation, pose bypass removal, and multi-frame recognition consensus."""

import math
import sys
import unittest
from dataclasses import replace
from pathlib import Path
from unittest.mock import MagicMock

ROOT_DIR = Path(__file__).resolve().parents[1]
APP_DIR = ROOT_DIR / "app"
sys.path.insert(0, str(APP_DIR))

from matcher import OnnxFaceMatcher, MatchResult, QualityResult, MatcherUnavailable
from settings import Settings


class QualityAndMajorityTest(unittest.TestCase):
    def setUp(self):
        self.settings = Settings(
            accept_threshold=0.90,
            min_margin=0.20,
            verified_reference_threshold=0.90,
            enrollment_holdout_median_threshold=0.90,
            enrollment_holdout_min_threshold=0.85,
            cross_frame_consistency_threshold=0.85,
            duplicate_review_threshold=0.75,
            duplicate_block_threshold=0.90,
            policy_version="face-policy-v2",
        )
        import numpy as np
        self.np = np

    def test_quality_aggregation_cannot_succeed_from_single_good_frame(self):
        # 3 quality items: 1 good, 2 blurred
        items = [
            QualityResult(face_count=1, sharpness_ok=True, exposure_ok=True, pose_ok=True, size_ok=True, centred_ok=True),
            QualityResult(face_count=1, sharpness_ok=False, exposure_ok=True, pose_ok=True, size_ok=True, centred_ok=True),
            QualityResult(face_count=1, sharpness_ok=False, exposure_ok=True, pose_ok=True, size_ok=True, centred_ok=True),
        ]
        evidences = [
            {"accepted": True, "sharpness": 55.0, "exposure": 120.0, "reasons": []},
            {"accepted": False, "sharpness": 12.0, "exposure": 115.0, "reasons": ["BLUR"]},
            {"accepted": False, "sharpness": 10.0, "exposure": 110.0, "reasons": ["BLUR"]},
        ]
        quality, summary = OnnxFaceMatcher._quality_summary(items, frames_received=3, frames_accepted=1, evidences=evidences)

        # Crucial invariant: one good frame does NOT make the sequence sharpness_ok!
        self.assertFalse(quality.sharpness_ok)
        self.assertEqual(3, summary["frames_received"])
        self.assertEqual(1, summary["frames_accepted"])
        self.assertEqual(2, summary["frames_rejected"])
        self.assertAlmostEqual(0.6667, summary["rejection_rate"], places=3)
        self.assertIn("BLUR", summary["failure_reasons"])
        self.assertEqual(2, summary["reason_counts"]["BLUR"])
        self.assertEqual(10.0, summary["min_sharpness"])

    def test_pose_bypass_removal(self):
        # Ensure that similarity >= 0.90 CANNOT override failed pose/sharpness
        matcher = OnnxFaceMatcher.__new__(OnnxFaceMatcher)
        matcher.settings = self.settings
        matcher.np = self.np
        matcher._features = MagicMock()
        matcher.pad = MagicMock()
        matcher.pad.classify.return_value = "LIVE"

        # Mock frame features returning feature with poor pose
        bad_quality = QualityResult(face_count=1, sharpness_ok=True, exposure_ok=True, pose_ok=False, size_ok=True, centred_ok=True)
        vec = self.np.zeros(128, dtype=self.np.float32)
        vec[0] = 1.0

        matcher._features.return_value = ([vec], bad_quality, [0.95], [0.0])

        candidates = {"candidate-1": vec.tolist()}
        result = matcher.match([b"mock_frame"], candidates)

        # Must be REVIEW because quality is not ok, even though similarity is 1.0!
        self.assertEqual("REVIEW", result.decision)
        self.assertIsNone(result.candidate_ref)

    def test_multi_frame_recognition_requires_at_least_three_good_frames(self):
        matcher = OnnxFaceMatcher.__new__(OnnxFaceMatcher)
        matcher.settings = self.settings
        matcher.np = self.np
        matcher._features = MagicMock()
        matcher.pad = MagicMock()
        matcher.pad.classify.return_value = "LIVE"

        vec = self.np.zeros(128, dtype=self.np.float32)
        vec[0] = 1.0
        good_quality = QualityResult(face_count=1, sharpness_ok=True, exposure_ok=True, pose_ok=True, size_ok=True, centred_ok=True)

        # 4 frames sent, but only 2 accepted frames
        matcher._features.return_value = ([vec, vec], good_quality, [0.95, 0.95], [0.0, 0.0])

        candidates = {"candidate-1": vec.tolist()}
        # 4 frames in input
        result = matcher.match([b"f1", b"f2", b"f3", b"f4"], candidates)

        # Insufficient accepted frames (2 < 3) -> must be REVIEW
        self.assertEqual("REVIEW", result.decision)

    def test_multi_frame_recognition_auto_accepts_when_all_gates_pass(self):
        matcher = OnnxFaceMatcher.__new__(OnnxFaceMatcher)
        matcher.settings = replace(self.settings, calibration_approved=True, min_template_support=2)
        matcher.np = self.np
        matcher._features = MagicMock()
        matcher.pad = MagicMock()
        matcher.pad.classify.return_value = "LIVE"

        vec1 = self.np.zeros(128, dtype=self.np.float32)
        vec1[0] = 1.0
        good_quality = QualityResult(face_count=1, sharpness_ok=True, exposure_ok=True, pose_ok=True, size_ok=True, centred_ok=True)

        # Three independent frames agree, with two references supporting the winner.
        matcher._features.return_value = ([vec1, vec1, vec1], good_quality, [0.95, 0.95, 0.95], [0.0, 0.0, 0.0])
        candidates = {
            "candidate-1": [vec1.tolist(), vec1.tolist()],
            "candidate-2": [(-vec1).tolist(), (-vec1).tolist()],
        }

        result = matcher.match([b"f1", b"f2", b"f3"], candidates)

        self.assertEqual("ACCEPT", result.decision)
        self.assertEqual("candidate-1", result.candidate_ref)
        self.assertGreaterEqual(result.top1_score, 0.90)
        self.assertGreaterEqual(result.margin, 0.20)
        self.assertEqual("LIVE", result.liveness)
        comparisons = result.to_dict()["comparison_scores"]
        self.assertEqual({"candidate-1", "candidate-2"}, {item["candidate_ref"] for item in comparisons})
        self.assertGreater(comparisons[0]["score"], comparisons[1]["score"])

    def test_two_burst_rejects_duplicate_frame_across_bursts(self):
        matcher = OnnxFaceMatcher.__new__(OnnxFaceMatcher)
        matcher.settings = self.settings
        matcher.np = self.np
        f1 = b"unique_frame_1"
        f2 = b"unique_frame_2"
        f3 = b"unique_frame_3"
        f4 = b"unique_frame_4"
        f5 = b"unique_frame_5"

        burst_a = [f1, f2, f3, f4, f5]
        burst_b = [f1, b"b2", b"b3"]  # f1 reused in burst_b!
        ref_vec = [1.0] + [0.0] * 127

        with self.assertRaises(MatcherUnavailable) as ctx:
            matcher.encode_enrollment_v2(burst_a, burst_b, ref_vec)
        self.assertIn("BURST_REUSE", str(ctx.exception))

    def test_two_burst_rejects_reference_mismatch(self):
        matcher = OnnxFaceMatcher.__new__(OnnxFaceMatcher)
        matcher.settings = self.settings
        matcher.np = self.np
        matcher._frame_features = MagicMock()

        vec_child = self.np.zeros(128, dtype=self.np.float32)
        vec_child[0] = 1.0

        vec_adult = self.np.zeros(128, dtype=self.np.float32)
        vec_adult[1] = 1.0  # orthogonal, similarity 0.0

        # Burst A has adult face
        features_a = [vec_adult] * 5
        features_b = [vec_child] * 3

        matcher._frame_features.side_effect = [
            (features_a, [], [], [], []),
            (features_b, [], [], [], []),
        ]

        burst_a = [f"a_{i}".encode() for i in range(5)]
        burst_b = [f"b_{i}".encode() for i in range(3)]
        ref_vec = vec_child.tolist()

        with self.assertRaises(MatcherUnavailable) as ctx:
            matcher.encode_enrollment_v2(burst_a, burst_b, ref_vec)
        self.assertIn("REFERENCE_MISMATCH", str(ctx.exception))


if __name__ == "__main__":
    unittest.main()
