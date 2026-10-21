"""FACE-PAD-01 repeated-frame and scan-rate contract."""

from __future__ import annotations

import sys
import unittest
from pathlib import Path


APP_DIR = Path(__file__).resolve().parents[1] / "app"
sys.path.insert(0, str(APP_DIR))

from matcher import MatchResult, QualityResult  # type: ignore  # noqa: E402
from service import InferenceError, InferenceService  # type: ignore  # noqa: E402
from settings import Settings  # type: ignore  # noqa: E402


class FakeMatcher:
    def __init__(self) -> None:
        self.calls = 0

    def match(self, frames, candidates):
        self.calls += 1
        return MatchResult(
            decision="ACCEPT",
            candidate_ref="candidate-a",
            quality=QualityResult(face_count=1),
            liveness="LIVE",
            model_version="test",
        )


class FakeClock:
    def __init__(self) -> None:
        self.value = 1000.0

    def __call__(self) -> float:
        return self.value


def roster():
    return [{"candidate_ref": "candidate-a", "embedding": [0.1, 0.2]}]


class PadReplayTest(unittest.TestCase):
    def test_repeated_frames_and_replayed_batches_are_spoofs(self):
        matcher = FakeMatcher()
        service = InferenceService(matcher, settings=Settings(calibration_approved=True))
        service.start_scan("scan-replay", roster())

        duplicate_frames = service.recognize("scan-replay", [b"same", b"same", b"same"])
        self.assertEqual("REJECT", duplicate_frames.decision)
        self.assertEqual("SPOOF", duplicate_frames.liveness)
        self.assertEqual(0, matcher.calls)

        first = service.recognize("scan-replay", [b"one", b"two", b"three"])
        replay = service.recognize("scan-replay", [b"one", b"two", b"three"])
        self.assertEqual("ACCEPT", first.decision)
        self.assertEqual("REJECT", replay.decision)
        self.assertEqual("SPOOF", replay.liveness)
        self.assertEqual(1, matcher.calls)

        service.start_scan("scan-replay-new", roster())
        cross_scan_replay = service.recognize("scan-replay-new", [b"one", b"two", b"three"])
        self.assertEqual("REJECT", cross_scan_replay.decision)
        self.assertEqual("SPOOF", cross_scan_replay.liveness)
        self.assertEqual(1, matcher.calls)

    def test_scan_attempt_rate_is_bounded(self):
        clock = FakeClock()
        service = InferenceService(
            FakeMatcher(),
            settings=Settings(calibration_approved=True, max_attempts_per_minute=3),
            clock=clock,
        )
        service.start_scan("scan-rate", roster())
        for index in range(3):
            service.recognize("scan-rate", [f"frame-{index}-{part}".encode() for part in range(3)])
        with self.assertRaises(InferenceError) as context:
            service.recognize("scan-rate", [b"frame-over-limit-1", b"frame-over-limit-2", b"frame-over-limit-3"])
        self.assertEqual("ATTEMPT_RATE_LIMIT", context.exception.reason)


if __name__ == "__main__":
    unittest.main()
