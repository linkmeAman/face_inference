#!/usr/bin/env python3
"""FACE-ML-01 contract tests.

This test file is intentionally created before the inference service. The
pre-change run must fail because the service module is absent. The tests use a
fake matcher so they never require model downloads or biometric fixtures.
"""

from __future__ import annotations

import sys
import unittest
from pathlib import Path


APP_DIR = Path(__file__).resolve().parents[1] / "app"
sys.path.insert(0, str(APP_DIR))

try:
    from service import InferenceError, InferenceService  # type: ignore
    from matcher import MatchResult, QualityResult  # type: ignore
except ImportError as exc:  # expected pre-change baseline
    print(f"FAIL FACE-ML-01 service contract: {exc}")
    raise SystemExit(1)


class FakeClock:
    def __init__(self) -> float:
        self.value = 1000.0

    def __call__(self) -> float:
        return self.value


class FakeMatcher:
    def __init__(self, result: MatchResult) -> None:
        self.result = result
        self.received_frames = []

    def match(self, frames, candidates):
        self.received_frames = list(frames)
        return self.result


def roster():
    return [
        {"candidate_ref": "candidate-a", "embedding": [0.1, 0.2, 0.3]},
        {"candidate_ref": "candidate-b", "embedding": [0.4, 0.5, 0.6]},
    ]


class FaceInferenceContractTest(unittest.TestCase):
    def test_accept_is_restricted_to_the_frozen_roster(self):
        matcher = FakeMatcher(
            MatchResult(
                decision="ACCEPT",
                candidate_ref="outside-roster",
                top1_score=0.99,
                top2_score=0.10,
                margin=0.89,
                quality=QualityResult(face_count=1),
            )
        )
        service = InferenceService(matcher=matcher, clock=FakeClock())
        service.start_scan("scan-1", roster(), ttl_seconds=300)

        result = service.recognize("scan-1", [b"frame-1", b"frame-2", b"frame-3"])

        self.assertEqual("REVIEW", result.decision)
        self.assertIsNone(result.candidate_ref)

    def test_attempt_is_limited_to_three_bounded_frames(self):
        matcher = FakeMatcher(MatchResult(decision="REJECT"))
        service = InferenceService(matcher=matcher, clock=FakeClock())
        service.start_scan("scan-2", roster(), ttl_seconds=300)

        with self.assertRaises(InferenceError) as context:
            service.recognize("scan-2", [b"1", b"2", b"3", b"4"])

        self.assertEqual("FRAME_COUNT_LIMIT", context.exception.reason)
        self.assertEqual([], matcher.received_frames)

    def test_uncertain_match_returns_review_without_guessing(self):
        matcher = FakeMatcher(
            MatchResult(
                decision="REVIEW",
                candidate_ref="candidate-a",
                top1_score=0.60,
                top2_score=0.58,
                margin=0.02,
                quality=QualityResult(face_count=1),
            )
        )
        service = InferenceService(matcher=matcher, clock=FakeClock())
        service.start_scan("scan-3", roster(), ttl_seconds=300)

        result = service.recognize("scan-3", [b"frame-1", b"frame-2", b"frame-3"])

        self.assertEqual("REVIEW", result.decision)
        self.assertIsNone(result.candidate_ref)

    def test_expired_scan_is_removed_and_frames_are_not_retained(self):
        clock = FakeClock()
        matcher = FakeMatcher(MatchResult(decision="REJECT"))
        service = InferenceService(matcher=matcher, clock=clock)
        service.start_scan("scan-4", roster(), ttl_seconds=10)
        clock.value += 11

        with self.assertRaises(InferenceError) as context:
            service.recognize("scan-4", [b"raw-camera-frame-1", b"raw-camera-frame-2", b"raw-camera-frame-3"])

        self.assertEqual("SCAN_EXPIRED", context.exception.reason)
        self.assertEqual({}, service.scan_snapshot())
        self.assertEqual([], matcher.received_frames)

    def test_completed_scan_clears_candidate_embeddings(self):
        matcher = FakeMatcher(MatchResult(decision="REJECT"))
        service = InferenceService(matcher=matcher, clock=FakeClock())
        service.start_scan("scan-5", roster(), ttl_seconds=300)
        self.assertEqual({"scan-5": 2}, service.scan_snapshot())

        service.delete_scan("scan-5")

        self.assertEqual({}, service.scan_snapshot())

    def test_service_has_no_attendance_writer(self):
        matcher = FakeMatcher(MatchResult(decision="REJECT"))
        service = InferenceService(matcher=matcher, clock=FakeClock())

        self.assertFalse(hasattr(service, "recordMemberAttendance"))
        self.assertFalse(hasattr(service, "attendance_connection"))


class RecognitionFrameCountTest(unittest.TestCase):
    def test_recognition_rejects_non_three_frame_requests_before_matching(self):
        for count in (1, 2, 4, 5):
            with self.subTest(frames=count):
                matcher = FakeMatcher(MatchResult(decision="REJECT"))
                service = InferenceService(matcher=matcher, clock=FakeClock())
                service.start_scan("scan-count", roster(), ttl_seconds=300)
                with self.assertRaises(InferenceError) as context:
                    service.recognize("scan-count", [f"frame-{i}".encode() for i in range(count)])
                self.assertEqual("FRAME_COUNT_LIMIT", context.exception.reason)
                self.assertEqual([], matcher.received_frames)


if __name__ == "__main__":
    result = unittest.main(exit=False)
    raise SystemExit(0 if result.result.wasSuccessful() else 1)
