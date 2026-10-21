"""FACE-PAD-01 approved PAD calibration artifact contract."""

from __future__ import annotations

import hashlib
import json
import sys
import tempfile
import unittest
from pathlib import Path


APP_DIR = Path(__file__).resolve().parents[1] / "app"
sys.path.insert(0, str(APP_DIR))

from calibration import (  # type: ignore  # noqa: E402
    CalibrationError,
    calibrate_pad_scores,
    load_approved_pad_calibration,
)


REQUIRED_FIXTURES = [
    "PRINT_PHOTO",
    "PHONE_SCREEN",
    "TABLET_SCREEN",
    "VIDEO_REPLAY",
    "MASK",
    "SYNTHETIC_INJECTION",
    "REPEATED_FRAMES",
    "API_REPLAY",
]


class PadCalibrationTest(unittest.TestCase):
    def records(self) -> list[dict]:
        records = []
        fixtures = REQUIRED_FIXTURES
        for index, fixture in enumerate(fixtures):
            records.append({
                "label": "attack",
                "score": 0.20 + (index * 0.01),
                "latency_ms": 25 + index,
                "fixture": fixture,
                "venue": f"venue-{(index % 2) + 1}",
                "device": f"device-{(index % 3) + 1}",
                "lighting": f"lighting-{(index % 4) + 1}",
            })
        for index in range(12):
            records.append({
                "label": "live",
                "score": 0.82 + (index * 0.005),
                "latency_ms": 22 + index,
                "fixture": "BONA_FIDE",
                "venue": f"venue-{(index % 2) + 1}",
                "device": f"device-{(index % 3) + 1}",
                "lighting": f"lighting-{(index % 4) + 1}",
            })
        return records

    def test_score_calibration_requires_strict_separation_and_coverage(self):
        report = calibrate_pad_scores(self.records(), target_p95_ms=50)
        self.assertEqual(0, report.attack_misses)
        self.assertEqual(0, report.false_accepts)
        self.assertEqual(8, len(report.fixtures_tested))
        self.assertLess(report.spoof_threshold, report.live_threshold)

        incomplete = [item for item in self.records() if item["fixture"] != "MASK"]
        with self.assertRaises(CalibrationError) as context:
            calibrate_pad_scores(incomplete, target_p95_ms=50)
        self.assertEqual("PAD_FIXTURE_COVERAGE_MISSING", context.exception.reason)

        overlapping = self.records()
        overlapping[0]["score"] = 0.90
        with self.assertRaises(CalibrationError) as context:
            calibrate_pad_scores(overlapping, target_p95_ms=50)
        self.assertEqual("PAD_NO_STRICT_OPERATING_POINT", context.exception.reason)

    def artifact(self, model: Path) -> dict:
        return {
            "status": "APPROVED",
            "policy_version": "face-pad-policy-v1",
            "approved_by": "technical-lead,privacy-officer",
            "approved_at": "2026-09-19",
            "license_reference": "MIT-ANTI-SPOOF-MN3",
            "files": [{"path": str(model), "sha256": hashlib.sha256(model.read_bytes()).hexdigest()}],
            "live_threshold": 0.86,
            "spoof_threshold": 0.40,
            "client_planar_z_threshold": 0.10,
            "false_accepts": 0,
            "attack_misses": 0,
            "p95_latency_ms": 30,
            "fixtures_tested": list(REQUIRED_FIXTURES),
            "venue_count": 2,
            "device_count": 3,
            "lighting_count": 4,
            "preprocessing_version": "anti-spoof-mn3-square-1.5-v1",
        }

    def test_complete_zero_attack_miss_artifact_loads(self):
        with tempfile.TemporaryDirectory() as directory:
            model = Path(directory) / "pad.onnx"
            model.write_bytes(b"pad-model")
            artifact = self.artifact(model)
            path = Path(directory) / "approved.json"
            path.write_text(json.dumps(artifact))
            result = load_approved_pad_calibration(path)
            self.assertEqual(0.86, result["live_threshold"])
            self.assertEqual(0.40, result["spoof_threshold"])
            self.assertEqual(0.10, result["client_planar_z_threshold"])

    def test_missing_fixture_or_attack_miss_fails_closed(self):
        with tempfile.TemporaryDirectory() as directory:
            model = Path(directory) / "pad.onnx"
            model.write_bytes(b"pad-model")
            artifact = self.artifact(model)
            artifact["fixtures_tested"].remove("MASK")
            path = Path(directory) / "approved.json"
            path.write_text(json.dumps(artifact))
            with self.assertRaises(CalibrationError) as context:
                load_approved_pad_calibration(path)
            self.assertEqual("PAD_FIXTURE_COVERAGE_MISSING", context.exception.reason)

            artifact = self.artifact(model)
            artifact["attack_misses"] = 1
            path.write_text(json.dumps(artifact))
            with self.assertRaises(CalibrationError) as context:
                load_approved_pad_calibration(path)
            self.assertEqual("PAD_CALIBRATION_POLICY_INVALID", context.exception.reason)

    def test_stale_preprocessing_artifact_fails_closed(self):
        with tempfile.TemporaryDirectory() as directory:
            model = Path(directory) / "pad.onnx"
            model.write_bytes(b"pad-model")
            artifact = self.artifact(model)
            artifact["preprocessing_version"] = "legacy-rectangular-crop"
            path = Path(directory) / "approved.json"
            path.write_text(json.dumps(artifact))
            with self.assertRaises(CalibrationError) as context:
                load_approved_pad_calibration(path)
            self.assertEqual("PAD_PREPROCESSING_VERSION_MISMATCH", context.exception.reason)


if __name__ == "__main__":
    unittest.main()
