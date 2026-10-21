"""FaceGuide selection and capture-geometry contract tests."""

from __future__ import annotations

import sys
import unittest
from pathlib import Path

import numpy as np


APP_DIR = Path(__file__).resolve().parents[1] / "app"
sys.path.insert(0, str(APP_DIR))

from face_selection import CaptureContextError, select_face, validate_capture_context  # type: ignore  # noqa: E402


def face(x, y, width, height, confidence=0.99):
    return np.asarray([x, y, width, height] + [0.0] * 10 + [confidence], dtype=np.float32)


def context(cx=0.5, cy=0.5, rx=0.2, ry=0.35, mirrored=False):
    return {
        "version": "face-capture-v1",
        "capture_id": "test-capture-1",
        "frame": {"width": 1000, "height": 1000},
        "display": {
            "width": 500,
            "height": 500,
            "content_rect": {"x": 0, "y": 0, "width": 500, "height": 500},
            "object_fit": "contain",
        },
        "guide": {"cx": cx, "cy": cy, "rx": rx, "ry": ry},
        "mirrored_preview": mirrored,
        "rotation": 0,
        "source": "canvas-video",
    }


class FaceSelectionTest(unittest.TestCase):
    def test_one_face_inside_is_selected(self):
        result = select_face([face(420, 300, 160, 320)], 1000, 1000, context())
        self.assertEqual(0, result["selected_face_index"])
        self.assertEqual(1, result["guide_candidate_count"])

    def test_detector_numpy_array_is_supported(self):
        faces = np.asarray([face(420, 300, 160, 320)], dtype=np.float32)
        guided = select_face(faces, 1000, 1000, context())
        legacy = select_face(faces, 1000, 1000, None)
        self.assertEqual(0, guided["selected_face_index"])
        self.assertEqual("SELECTED", guided["reason_code"])
        self.assertEqual(0, legacy["selected_face_index"])

    def test_significant_partial_face_is_selected_for_quality_evaluation(self):
        result = select_face([face(250, 350, 200, 300)], 1000, 1000, context())
        self.assertEqual(0, result["selected_face_index"])
        self.assertGreaterEqual(result["faces"][0]["overlap"], 0.50)

    def test_normalized_box_is_clipped_to_the_raw_frame(self):
        result = select_face([face(-50, 300, 200, 300)], 1000, 1000, context(cx=0.2))
        box = result["faces"][0]["box"]
        self.assertEqual(0.0, box["x"])
        self.assertEqual(0.15, box["width"])
        self.assertLessEqual(box["x"] + box["width"], 1.0)

    def test_face_outside_guide_is_ignored(self):
        result = select_face([face(20, 300, 140, 240)], 1000, 1000, context())
        self.assertIsNone(result["selected_face_index"])
        self.assertEqual("NO_FACE_IN_GUIDE", result["reason_code"])

    def test_background_face_does_not_block_target(self):
        result = select_face(
            [face(420, 300, 160, 320), face(20, 50, 120, 180, 0.999)],
            1000, 1000, context(),
        )
        self.assertEqual(0, result["selected_face_index"])
        self.assertEqual(2, result["detected_face_count"])
        self.assertEqual(1, result["guide_candidate_count"])

    def test_two_faces_outside_return_no_face_in_guide(self):
        result = select_face([face(10, 10, 100, 150), face(850, 700, 120, 180)], 1000, 1000, context())
        self.assertIsNone(result["selected_face_index"])
        self.assertEqual(0, result["guide_candidate_count"])

    def test_competing_candidates_are_ambiguous_without_confidence_tiebreak(self):
        result = select_face(
            [face(350, 330, 180, 300, 0.86), face(470, 330, 180, 300, 0.999)],
            1000, 1000, context(),
        )
        self.assertIsNone(result["selected_face_index"])
        self.assertTrue(result["ambiguous"])
        self.assertEqual("MULTIPLE_FACES_IN_GUIDE", result["reason_code"])

    def test_legacy_requests_remain_exact_one_face_fail_closed(self):
        one = select_face([face(400, 300, 200, 300)], 1000, 1000, None)
        many = select_face([face(400, 300, 200, 300), face(20, 20, 100, 100)], 1000, 1000, None)
        self.assertEqual(0, one["selected_face_index"])
        self.assertIsNone(many["selected_face_index"])
        self.assertEqual("MULTIPLE_FACES", many["reason_code"])

    def test_mirrored_and_edge_guides_are_valid_raw_coordinates(self):
        for cx, cy in ((0.2, 0.35), (0.8, 0.35), (0.2, 0.65), (0.8, 0.65)):
            validated = validate_capture_context(context(cx=cx, cy=cy, mirrored=True))
            self.assertTrue(validated["mirrored_preview"])
            self.assertEqual(cx, validated["guide"]["cx"])

    def test_malformed_context_is_rejected(self):
        malformed = context()
        malformed["display"]["object_fit"] = "cover"
        with self.assertRaises(CaptureContextError):
            validate_capture_context(malformed)

        malformed_rect = context()
        malformed_rect["display"]["content_rect"]["width"] = 400
        with self.assertRaises(CaptureContextError):
            validate_capture_context(malformed_rect)


if __name__ == "__main__":
    unittest.main()
