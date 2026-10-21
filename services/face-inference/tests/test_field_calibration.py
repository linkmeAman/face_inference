#!/usr/bin/env python3
"""Tests for multi-venue field calibration, dataset partitioning, and high-precision benchmarks."""

from __future__ import annotations

import json
import sys
import unittest
from pathlib import Path

APP_DIR = Path(__file__).resolve().parents[1] / "app"
TOOLS_DIR = Path(__file__).resolve().parents[1] / "tools"
CALIBRATION_DIR = Path(__file__).resolve().parents[1] / "calibration"

sys.path.insert(0, str(APP_DIR))
sys.path.insert(0, str(TOOLS_DIR))

from calibration import CalibrationError, load_approved_calibration  # type: ignore
from dataset_manager import partition_dataset, validate_record  # type: ignore
from evaluate_calibration import CalibrationEvaluator  # type: ignore


class FieldCalibrationTest(unittest.TestCase):
    def test_dataset_record_validation_rejects_forbidden_keys(self):
        valid = {
            "sample_id": "TEST-1",
            "label": "genuine",
            "score": 0.95,
            "margin": 0.35,
            "latency_ms": 12,
            "venue": "Studio-A",
            "device": "Webcam",
            "lighting": "Daylight",
        }
        rec = validate_record(valid, 1)
        self.assertEqual("TEST-1", rec["sample_id"])
        self.assertEqual("genuine", rec["label"])

        forbidden = dict(valid)
        forbidden["raw_image"] = "bytes"
        with self.assertRaises(ValueError) as ctx:
            validate_record(forbidden, 2)
        self.assertIn("forbidden privacy key", str(ctx.exception))

    def test_dataset_partitioning_is_stratified(self):
        records = [
            {"sample_id": f"G-{i}", "label": "genuine", "score": 0.9, "margin": 0.3, "latency_ms": 10, "venue": "A", "device": "D", "lighting": "L"}
            for i in range(10)
        ] + [
            {"sample_id": f"I-{i}", "label": "impostor", "score": 0.2, "margin": 0.05, "latency_ms": 10, "venue": "A", "device": "D", "lighting": "L"}
            for i in range(10)
        ]
        dev, held_out = partition_dataset(records, dev_ratio=0.5)
        self.assertEqual(10, len(dev))
        self.assertEqual(10, len(held_out))
        self.assertEqual(5, sum(1 for r in dev if r["label"] == "genuine"))
        self.assertEqual(5, sum(1 for r in held_out if r["label"] == "genuine"))
        self.assertEqual(5, sum(1 for r in dev if r["label"] == "impostor"))
        self.assertEqual(5, sum(1 for r in held_out if r["label"] == "impostor"))

    def test_field_calibration_evaluator_strict_operating_point(self):
        held_out_path = CALIBRATION_DIR / "held-out-scores.json"
        self.assertTrue(held_out_path.is_file())
        records = json.loads(held_out_path.read_text())

        evaluator = CalibrationEvaluator(accept_threshold=0.68, min_margin=0.18, target_p95_ms=100)
        metrics = evaluator.evaluate(records)

        self.assertTrue(metrics["passes"])
        self.assertEqual(0, metrics["false_accepts"])
        self.assertEqual(0.0, metrics["far_percent"])
        self.assertLessEqual(metrics["p95_latency_ms"], 100)
        self.assertEqual(0.68, metrics["accept_threshold"])
        self.assertEqual(0.18, metrics["min_margin"])

        for category, slices in metrics["slices"].items():
            for name, data in slices.items():
                self.assertEqual(0, data["false_accept"], f"Slice {category}/{name} had false accept!")

    def test_evaluator_rejects_false_accept(self):
        records = [
            {"label": "genuine", "score": 0.95, "margin": 0.35, "latency_ms": 10, "venue": "A", "device": "D", "lighting": "L"},
            {"label": "impostor", "score": 0.72, "margin": 0.25, "latency_ms": 10, "venue": "A", "device": "D", "lighting": "L"},
        ]
        evaluator = CalibrationEvaluator(accept_threshold=0.68, min_margin=0.18, target_p95_ms=100)
        metrics = evaluator.evaluate(records)
        self.assertFalse(metrics["passes"])
        self.assertEqual(1, metrics["false_accepts"])

    def test_evaluator_rejects_latency_exceeded(self):
        records = [
            {"label": "genuine", "score": 0.95, "margin": 0.35, "latency_ms": 150, "venue": "A", "device": "D", "lighting": "L"},
            {"label": "impostor", "score": 0.20, "margin": 0.05, "latency_ms": 10, "venue": "A", "device": "D", "lighting": "L"},
        ]
        evaluator = CalibrationEvaluator(accept_threshold=0.68, min_margin=0.18, target_p95_ms=100)
        metrics = evaluator.evaluate(records)
        self.assertFalse(metrics["passes"])

    def test_legacy_calibration_artifact_is_invalidated_by_preprocessing_change(self):
        artifact_path = CALIBRATION_DIR / "face-policy-v2.json"
        self.assertTrue(artifact_path.is_file())

        with self.assertRaises(CalibrationError) as context:
            load_approved_calibration(artifact_path)
        self.assertEqual("CALIBRATION_PREPROCESSING_MISMATCH", context.exception.reason)

        manifest = json.loads(artifact_path.read_text())
        self.assertEqual("APPROVED", manifest["status"])
        self.assertEqual("ccc-technical-lead, ccc-privacy-officer", manifest["approved_by"])
        self.assertIn("model_weights_updated", manifest["revalidation_triggers"])


if __name__ == "__main__":
    unittest.main()
