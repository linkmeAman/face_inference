#!/usr/bin/env python3
"""Contract tests for strict face-presence, positioning, and cross-frame identity consistency during enrollment."""

from __future__ import annotations

import sys
import unittest
from pathlib import Path
import numpy as np

APP_DIR = Path(__file__).resolve().parents[1] / "app"
sys.path.insert(0, str(APP_DIR))

from matcher import OnnxFaceMatcher, MatcherUnavailable  # type: ignore
from settings import Settings  # type: ignore


class FakeStrictDetector:
    def __init__(self, faces):
        self.faces = faces

    def detect(self, image):
        return self.faces


class FakeStrictEncoder:
    def __init__(self, embedding_func):
        self.embedding_func = embedding_func

    def encode(self, image, face):
        return self.embedding_func(face)


class FakeStrictPad:
    def real_probability(self, image, face):
        return 0.99


class EnrollmentStrictValidationTest(unittest.TestCase):
    def setUp(self):
        self.settings = Settings(
            model_version="test-strict-v1",
            model_version_id=1,
            enrollment_verify_threshold=0.80,
            enrollment_templates=3,
        )

    def test_encode_enrollment_rejects_mixed_identity_frames(self):
        # 8 frames: first 4 frames belong to Person A, remaining 4 frames belong to Person B (dissimilar embeddings)
        import cv2
        matcher = object.__new__(OnnxFaceMatcher)
        matcher.settings = self.settings
        matcher.cv2 = cv2
        matcher.np = np
        # Well-centered face geometry
        valid_face = np.asarray([210, 110, 220, 220, 265, 180, 375, 180, 320, 225, 275, 275, 365, 275, 0.99], dtype=np.float32)
        matcher.detector = FakeStrictDetector([valid_face])

        call_idx = [0]
        def mixed_encoder(face):
            idx = call_idx[0]
            call_idx[0] += 1
            # First 5 frames: Person A embedding (pointing in direction [1, 0, 0...])
            # Last 3 frames: Person B embedding (orthogonal, pointing in direction [0, 1, 0...])
            vec = np.zeros(128, dtype=np.float32)
            if idx < 5:
                vec[0] = 1.0
            else:
                vec[1] = 1.0
            return vec

        matcher.encoder = FakeStrictEncoder(mixed_encoder)
        matcher.pad = FakeStrictPad()

        # 8 dummy frames of textured bytes
        np.random.seed(123)
        dummy_frames = []
        import cv2
        for _ in range(8):
            img = np.random.randint(60, 180, (480, 640, 3), dtype=np.uint8)
            _, buf = cv2.imencode(".jpg", img)
            dummy_frames.append(buf.tobytes())

        with self.assertRaises(MatcherUnavailable) as ctx:
            matcher.encode_enrollment(dummy_frames)
        self.assertIn("inconsistent or from different people", str(ctx.exception))

    def test_encode_enrollment_rejects_frames_with_face_outside_guide(self):
        import cv2
        matcher = object.__new__(OnnxFaceMatcher)
        matcher.settings = self.settings
        matcher.cv2 = cv2
        matcher.np = np
        # Face at bottom (like Image 3, outside guide)
        low_face = np.asarray([200, 320, 240, 150, 260, 360, 380, 360, 320, 410, 270, 450, 370, 450, 0.95], dtype=np.float32)
        matcher.detector = FakeStrictDetector([low_face])
        matcher.encoder = FakeStrictEncoder(lambda f: np.ones(128, dtype=np.float32))
        matcher.pad = FakeStrictPad()

        import cv2
        dummy_frames = []
        for _ in range(8):
            img = np.random.randint(60, 180, (480, 640, 3), dtype=np.uint8)
            _, buf = cv2.imencode(".jpg", img)
            dummy_frames.append(buf.tobytes())

        with self.assertRaises(MatcherUnavailable) as ctx:
            matcher.encode_enrollment(dummy_frames)
        self.assertIn("usable enrollment faces were not acquired", str(ctx.exception))

    def test_encode_enrollment_rejects_mismatched_capture_context_count(self):
        import cv2
        matcher = object.__new__(OnnxFaceMatcher)
        matcher.settings = self.settings
        matcher.cv2 = cv2
        matcher.np = np
        matcher.detector = FakeStrictDetector([])
        matcher.encoder = FakeStrictEncoder(lambda f: np.ones(128, dtype=np.float32))
        matcher.pad = FakeStrictPad()
        image = np.full((480, 640, 3), 127, dtype=np.uint8)
        _, encoded = cv2.imencode(".jpg", image)

        with self.assertRaises(MatcherUnavailable) as ctx:
            matcher.encode_enrollment([encoded.tobytes()], capture_contexts=[])
        self.assertIn("INVALID_CAPTURE_CONTEXT", str(ctx.exception))

    def test_encode_enrollment_accepts_consistent_properly_positioned_frames(self):
        import cv2
        matcher = object.__new__(OnnxFaceMatcher)
        matcher.settings = self.settings
        matcher.cv2 = cv2
        matcher.np = np
        valid_face = np.asarray([210, 110, 220, 220, 265, 180, 375, 180, 320, 225, 275, 275, 365, 275, 0.99], dtype=np.float32)
        matcher.detector = FakeStrictDetector([valid_face])

        # Same person with slight noise (high cosine similarity >= 0.95)
        np.random.seed(42)
        base_vec = np.random.randn(128).astype(np.float32)
        base_vec /= np.linalg.norm(base_vec)

        def consistent_encoder(face):
            noise = np.random.randn(128).astype(np.float32) * 0.01
            v = base_vec + noise
            return v / np.linalg.norm(v)

        matcher.encoder = FakeStrictEncoder(consistent_encoder)
        matcher.pad = FakeStrictPad()

        import cv2
        dummy_frames = []
        for _ in range(8):
            img = np.random.randint(60, 180, (480, 640, 3), dtype=np.uint8)
            _, buf = cv2.imencode(".jpg", img)
            dummy_frames.append(buf.tobytes())

        result = matcher.encode_enrollment(dummy_frames)
        self.assertEqual(3, len(result["embeddings"]))
        self.assertEqual(8, result["quality_summary"]["frames_received"])
        self.assertEqual(8, result["quality_summary"]["frames_accepted"])
        self.assertTrue(result["verification_passed"])


if __name__ == "__main__":
    result = unittest.main(exit=False)
    raise SystemExit(0 if result.result.wasSuccessful() else 1)
