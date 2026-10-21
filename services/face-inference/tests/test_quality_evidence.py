#!/usr/bin/env python3
"""Focused quality/landmark evidence contract using synthetic pixels only."""

from __future__ import annotations

import sys
import unittest
from pathlib import Path

import numpy as np


APP_DIR = Path(__file__).resolve().parents[1] / "app"
sys.path.insert(0, str(APP_DIR))

from quality import assess_face_quality, face_landmarks  # type: ignore


class QualityEvidenceContract(unittest.TestCase):
    def setUp(self):
        self.face = np.asarray(
            [160, 80, 320, 360, 255, 210, 365, 210, 310, 275, 270, 350, 350, 350, 0.99],
            dtype=np.float32,
        )

    def test_yunet_landmarks_are_named(self):
        landmarks = face_landmarks(self.face)
        self.assertEqual([255.0, 210.0], landmarks["right_eye"])
        self.assertEqual([310.0, 275.0], landmarks["nose"])
        self.assertEqual([350.0, 350.0], landmarks["left_mouth"])

    def test_quality_rejects_small_off_centre_flat_frame(self):
        image = np.full((720, 1280, 3), 127, dtype=np.uint8)
        small = self.face.copy()
        small[:4] = [5, 5, 35, 35]
        result = assess_face_quality(image, small, face_count=1)
        self.assertFalse(result["accepted"])
        self.assertIn("FACE_TOO_SMALL", result["reasons"])
        self.assertIn("FACE_OFF_CENTRE", result["reasons"])
        self.assertIn("BLUR", result["reasons"])

    def test_quality_rejects_multiple_faces(self):
        image = np.full((720, 1280, 3), 127, dtype=np.uint8)
        result = assess_face_quality(image, self.face, face_count=2)
        self.assertFalse(result["accepted"])
        self.assertIn("MULTIPLE_FACES", result["reasons"])

    def test_quality_rejects_face_positioned_too_low(self):
        # Simulates image 3 where the face sits at the bottom of the frame
        image = np.full((480, 640, 3), 127, dtype=np.uint8)
        low_face = np.asarray(
            [200, 280, 240, 180, 260, 330, 380, 330, 320, 380, 270, 430, 370, 430, 0.95],
            dtype=np.float32,
        )
        result = assess_face_quality(image, low_face, face_count=1)
        self.assertFalse(result["accepted"])
        self.assertFalse(result["inside_guide"])
        self.assertIn("FACE_TOO_LOW", result["reasons"])

    def test_quality_rejects_face_clipped_by_frame_edge(self):
        image = np.full((480, 640, 3), 127, dtype=np.uint8)
        clipped_face = np.asarray(
            [5, 20, 240, 240, 65, 90, 185, 90, 125, 140, 75, 190, 175, 190, 0.95],
            dtype=np.float32,
        )
        result = assess_face_quality(image, clipped_face, face_count=1)
        self.assertFalse(result["accepted"])
        self.assertIn("FACE_CLIPPED_BY_FRAME", result["reasons"])

    def test_quality_rejects_landmarks_outside_guide(self):
        image = np.full((480, 640, 3), 127, dtype=np.uint8)
        outside_landmarks = np.asarray(
            [160, 80, 320, 360, 170, 100, 470, 100, 320, 220, 180, 430, 460, 430, 0.95],
            dtype=np.float32,
        )
        result = assess_face_quality(image, outside_landmarks, face_count=1)
        self.assertFalse(result["accepted"])
        self.assertFalse(result["inside_guide"])
        self.assertTrue(any("LANDMARK" in r and "OUTSIDE" in r for r in result["reasons"]))

    def test_quality_accepts_properly_positioned_centered_face(self):
        # 640x480 frame, centered at (320, 220), dimensions 220x220, textured image for sharpness
        np.random.seed(42)
        image = np.random.randint(60, 180, (480, 640, 3), dtype=np.uint8)
        well_centered = np.asarray(
            [210, 110, 220, 220, 265, 180, 375, 180, 320, 225, 275, 275, 365, 275, 0.99],
            dtype=np.float32,
        )
        result = assess_face_quality(image, well_centered, face_count=1)
        self.assertTrue(result["inside_guide"])
        self.assertTrue(result["centred_ok"])
        self.assertTrue(result["size_ok"])
        self.assertTrue(result["landmarks_inside"])


if __name__ == "__main__":
    result = unittest.main(exit=False)
    raise SystemExit(0 if result.result.wasSuccessful() else 1)
