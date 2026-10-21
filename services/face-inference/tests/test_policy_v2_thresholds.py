"""Tests for face-policy-v2 calibration thresholds and boundaries."""

import json
import os
import sys
import tempfile
import unittest
from pathlib import Path

ROOT_DIR = Path(__file__).resolve().parents[1]
APP_DIR = ROOT_DIR / "app"
CALIBRATION_DIR = ROOT_DIR / "calibration"

sys.path.insert(0, str(APP_DIR))

from calibration import CalibrationError, load_approved_calibration
from settings import Settings


class PolicyV2ThresholdsTest(unittest.TestCase):
    def setUp(self):
        source = CALIBRATION_DIR / "face-policy-v2.json"
        self.assertTrue(source.is_file())
        data = json.loads(source.read_text())
        data["preprocessing_version"] = "sface-opencv-aligncrop-rgb-raw-v2"
        handle = tempfile.NamedTemporaryFile("w", suffix=".json", delete=False)
        handle.write(json.dumps(data))
        handle.close()
        self.artifact_path = Path(handle.name)

    def tearDown(self):
        self.artifact_path.unlink(missing_ok=True)

    def test_face_policy_v2_manifest_loaded(self):
        loaded = load_approved_calibration(self.artifact_path)
        self.assertEqual("face-policy-v2", loaded["policy_version"])
        self.assertGreaterEqual(loaded["accept_threshold"], 0.90)
        self.assertGreaterEqual(loaded["min_margin"], 0.20)
        self.assertGreaterEqual(loaded["verified_reference_threshold"], 0.90)
        self.assertGreaterEqual(loaded["enrollment_holdout_median_threshold"], 0.90)
        self.assertGreaterEqual(loaded["enrollment_holdout_min_threshold"], 0.85)
        self.assertGreaterEqual(loaded["cross_frame_consistency_threshold"], 0.85)
        self.assertEqual(0.75, loaded["duplicate_review_threshold"])
        self.assertEqual(0.90, loaded["duplicate_block_threshold"])

    def test_face_policy_v2_cannot_lower_acceptance_threshold(self):
        data = json.loads(self.artifact_path.read_text())
        data["accept_threshold"] = 0.89
        with tempfile.NamedTemporaryFile("w", suffix=".json", delete=False) as f:
            f.write(json.dumps(data))
            tmp_path = Path(f.name)
        try:
            with self.assertRaises(CalibrationError) as ctx:
                load_approved_calibration(tmp_path)
            self.assertEqual("CALIBRATION_POLICY_INVALID", ctx.exception.reason)
        finally:
            tmp_path.unlink(missing_ok=True)

    def test_face_policy_v2_cannot_lower_margin(self):
        data = json.loads(self.artifact_path.read_text())
        data["min_margin"] = 0.19
        with tempfile.NamedTemporaryFile("w", suffix=".json", delete=False) as f:
            f.write(json.dumps(data))
            tmp_path = Path(f.name)
        try:
            with self.assertRaises(CalibrationError) as ctx:
                load_approved_calibration(tmp_path)
            self.assertEqual("CALIBRATION_POLICY_INVALID", ctx.exception.reason)
        finally:
            tmp_path.unlink(missing_ok=True)

    def test_environment_override_cannot_lower_thresholds(self):
        os.environ["FACE_CALIBRATION_FILE"] = str(self.artifact_path)
        os.environ["FACE_ACCEPT_THRESHOLD"] = "0.70"
        os.environ["FACE_MIN_MARGIN"] = "0.10"
        os.environ["FACE_ENROLLMENT_VERIFY_THRESHOLD"] = "0.70"
        try:
            settings = Settings.from_environment()
            self.assertGreaterEqual(settings.accept_threshold, 0.90)
            self.assertGreaterEqual(settings.min_margin, 0.20)
            self.assertGreaterEqual(settings.enrollment_verify_threshold, 0.90)
        finally:
            os.environ.pop("FACE_ACCEPT_THRESHOLD", None)
            os.environ.pop("FACE_MIN_MARGIN", None)
            os.environ.pop("FACE_ENROLLMENT_VERIFY_THRESHOLD", None)

    def test_environment_override_can_raise_thresholds(self):
        os.environ["FACE_CALIBRATION_FILE"] = str(self.artifact_path)
        os.environ["FACE_ACCEPT_THRESHOLD"] = "0.93"
        os.environ["FACE_MIN_MARGIN"] = "0.25"
        try:
            settings = Settings.from_environment()
            self.assertEqual(0.93, settings.accept_threshold)
            self.assertEqual(0.25, settings.min_margin)
        finally:
            os.environ.pop("FACE_ACCEPT_THRESHOLD", None)
            os.environ.pop("FACE_MIN_MARGIN", None)

    def test_boundary_edges(self):
        loaded = load_approved_calibration(self.artifact_path)
        review_thresh = loaded["duplicate_review_threshold"]  # 0.75
        block_thresh = loaded["duplicate_block_threshold"]    # 0.90
        accept_thresh = loaded["accept_threshold"]            # 0.90

        # Duplicate boundaries:
        # < 0.75: clear
        self.assertTrue(0.7499 < review_thresh)
        # >= 0.75 and < 0.90: review
        self.assertTrue(0.75 >= review_thresh and 0.75 < block_thresh)
        self.assertTrue(0.8999 >= review_thresh and 0.8999 < block_thresh)
        # >= 0.90: hard block
        self.assertTrue(0.90 >= block_thresh)

        # Recognition auto-accept:
        self.assertFalse(0.8999 >= accept_thresh)
        self.assertTrue(0.90 >= accept_thresh)


if __name__ == "__main__":
    unittest.main()
