#!/usr/bin/env python3
"""FACE-ML-02 approval/calibration tests, created before implementation."""

from __future__ import annotations

import hashlib
import json
import sys
import tempfile
import unittest
from pathlib import Path

APP_DIR = Path(__file__).resolve().parents[1] / "app"
sys.path.insert(0, str(APP_DIR))

try:
    from calibration import CalibrationError, approve_model, calibrate_scores, load_approved_calibration, p95, verify_model_manifest  # type: ignore
    from safe_logging import SafeFaceLogger  # type: ignore
except ImportError as exc:
    print(f"FAIL FACE-ML-02 approval/calibration contract: {exc}")
    raise SystemExit(1)


class ApprovalCalibrationTest(unittest.TestCase):
    def test_approved_manifest_requires_license_and_matching_checksums(self):
        with tempfile.TemporaryDirectory() as directory:
            model = Path(directory) / "model.onnx"
            model.write_bytes(b"approved-model")
            checksum = hashlib.sha256(model.read_bytes()).hexdigest()
            manifest = {
                "status": "APPROVED",
                "license_reference": "LIC-2026-001",
                "approved_by": "ml-reviewer",
                "approved_at": "2026-09-11",
                "policy_version": "face-policy-v1",
                "files": [{"path": str(model), "sha256": checksum}],
            }
            result = approve_model(manifest)
            self.assertTrue(result.approved)
            self.assertEqual(checksum, result.files[0]["sha256"])

    def test_checksum_or_license_failure_is_detectable(self):
        with tempfile.TemporaryDirectory() as directory:
            model = Path(directory) / "model.onnx"
            model.write_bytes(b"approved-model")
            manifest = {"status": "APPROVED", "files": [{"path": str(model), "sha256": "0" * 64}]}
            with self.assertRaises(CalibrationError) as context:
                approve_model(manifest)
            self.assertEqual("LICENSE_MISSING", context.exception.reason)

            manifest["license_reference"] = "LIC-2026-001"
            manifest["approved_by"] = "reviewer"
            manifest["approved_at"] = "2026-09-11"
            manifest["policy_version"] = "face-policy-v1"
            with self.assertRaises(CalibrationError) as context:
                approve_model(manifest)
            self.assertEqual("CHECKSUM_MISMATCH", context.exception.reason)

    def test_calibration_selects_strict_operating_point_with_zero_impostor_accepts(self):
        records = [
            {"label": "genuine", "score": 0.95, "margin": 0.42, "latency_ms": 80},
            {"label": "genuine", "score": 0.91, "margin": 0.30, "latency_ms": 120},
            {"label": "impostor", "score": 0.20, "margin": 0.04, "latency_ms": 90},
            {"label": "impostor", "score": 0.31, "margin": 0.08, "latency_ms": 110},
        ]
        result = calibrate_scores(records, target_p95_ms=250)
        self.assertGreater(result.accept_threshold, 0.31)
        self.assertGreater(result.min_margin, 0.08)
        self.assertEqual(0, result.false_accepts)
        self.assertLessEqual(result.p95_latency_ms, 250)
        self.assertEqual("ACCEPT", result.decide(0.95, 0.42))
        self.assertEqual("REVIEW", result.decide(0.50, 0.42))
        self.assertEqual("REVIEW", result.decide(0.95, 0.01))

    def test_latency_target_failure_is_detectable(self):
        records = [
            {"label": "genuine", "score": 0.95, "margin": 0.42, "latency_ms": 100},
            {"label": "impostor", "score": 0.20, "margin": 0.04, "latency_ms": 400},
        ]
        with self.assertRaises(CalibrationError) as context:
            calibrate_scores(records, target_p95_ms=250)
        self.assertEqual("LATENCY_TARGET_FAILED", context.exception.reason)

    def test_runtime_calibration_file_requires_zero_false_accepts(self):
        with tempfile.TemporaryDirectory() as directory:
            model = Path(directory) / "model.onnx"
            model.write_bytes(b"approved-model")
            manifest = {
                "status": "APPROVED", "license_reference": "LIC-2026-001",
                "approved_by": "reviewer", "approved_at": "2026-09-11",
                "policy_version": "face-policy-v1",
                "files": [{"path": str(model), "sha256": hashlib.sha256(model.read_bytes()).hexdigest()}],
                "accept_threshold": 0.80, "min_margin": 0.20,
                "false_accepts": 0, "p95_latency_ms": 180,
            }
            path = Path(directory) / "calibration.json"
            path.write_text(json.dumps(manifest))
            result = load_approved_calibration(path)
            self.assertEqual(0.80, result["accept_threshold"])
            self.assertEqual(0.20, result["min_margin"])

            manifest["false_accepts"] = 1
            path.write_text(json.dumps(manifest))
            with self.assertRaises(CalibrationError) as context:
                load_approved_calibration(path)
            self.assertEqual("CALIBRATION_POLICY_INVALID", context.exception.reason)

    def test_local_model_manifest_checks_both_downloaded_files(self):
        manifest = Path(__file__).resolve().parents[1] / "models" / "MODEL-MANIFEST.json"
        result = verify_model_manifest(manifest)
        self.assertEqual("opencv-zoo-yunet-sface-int8-omz-anti-spoof-mn3", result["bundle_version"])
        self.assertEqual("MIT", result["components"]["detector"]["license"])
        self.assertEqual("Apache-2.0", result["components"]["recognizer"]["license"])
        self.assertEqual("MIT", result["components"]["pad"]["license"])

    def test_safe_error_logger_excludes_frames_embeddings_and_secrets(self):
        logger = SafeFaceLogger()
        payload = logger.event("CALIBRATION_FAILED", {
            "scan_id": "scan-1",
            "frames": ["raw-frame"],
            "embedding": [0.1, 0.2],
            "shared_secret": "secret",
            "reason": "CHECKSUM_MISMATCH",
        })
        encoded = json.dumps(payload)
        self.assertNotIn("raw-frame", encoded)
        self.assertNotIn("0.1", encoded)
        self.assertNotIn("secret", encoded)
        self.assertEqual("CHECKSUM_MISMATCH", payload["reason"])

    def test_p95_is_performance_driven(self):
        self.assertEqual(400, p95([100, 200, 300, 400]))


if __name__ == "__main__":
    result = unittest.main(exit=False)
    raise SystemExit(0 if result.result.wasSuccessful() else 1)
