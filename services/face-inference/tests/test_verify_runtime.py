"""Tests for pre-flight verify_runtime.py script."""

from __future__ import annotations

import json
import shutil
import tempfile
import unittest
from pathlib import Path

from tools.verify_runtime import verify_models


class TestVerifyRuntime(unittest.TestCase):
    def setUp(self):
        self.temp_dir = Path(tempfile.mkdtemp())

    def tearDown(self):
        shutil.rmtree(self.temp_dir, ignore_errors=True)

    def test_missing_manifest_exits_with_code_1(self):
        with self.assertRaises(SystemExit) as ctx:
            verify_models(self.temp_dir)
        self.assertEqual(ctx.exception.code, 1)

    def test_corrupted_model_file_exits_with_code_2(self):
        # Create manifest pointing to a file with fake sha256
        manifest = {
            "bundle_version": "test",
            "detector": {
                "file": "detector.onnx",
                "sha256": "0" * 64,
            },
            "recognizer": {
                "file": "recognizer.onnx",
                "sha256": "1" * 64,
            },
        }
        (self.temp_dir / "MODEL-MANIFEST.json").write_text(json.dumps(manifest))
        (self.temp_dir / "detector.onnx").write_bytes(b"corrupted detector data")
        (self.temp_dir / "recognizer.onnx").write_bytes(b"corrupted recognizer data")

        with self.assertRaises(SystemExit) as ctx:
            verify_models(self.temp_dir)
        self.assertEqual(ctx.exception.code, 2)


if __name__ == "__main__":
    unittest.main()
