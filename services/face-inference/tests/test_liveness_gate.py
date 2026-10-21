"""FACE-PAD-01 fail-closed liveness decision contract."""

from __future__ import annotations

import sys
import unittest
from pathlib import Path


SERVICE_DIR = Path(__file__).resolve().parents[1]
APP_DIR = SERVICE_DIR / "app"
sys.path.insert(0, str(APP_DIR))

from matcher import MatchResult, QualityResult  # type: ignore  # noqa: E402
from service import InferenceService  # type: ignore  # noqa: E402
from settings import Settings  # type: ignore  # noqa: E402


class FakeMatcher:
    def __init__(self, liveness: str) -> None:
        self.liveness = liveness

    def match(self, frames, candidates):
        return MatchResult(
            decision="ACCEPT",
            candidate_ref="candidate-a",
            top1_score=0.95,
            top2_score=0.20,
            margin=0.75,
            quality=QualityResult(face_count=1, sharpness_ok=True, exposure_ok=True, pose_ok=True),
            liveness=self.liveness,
            model_version="test-model",
        )


class LivenessGateTest(unittest.TestCase):
    def recognize(self, liveness: str) -> MatchResult:
        settings = Settings(accept_threshold=0.68, calibration_approved=True)
        service = InferenceService(FakeMatcher(liveness), settings=settings)
        service.start_scan("scan-pad", [{"candidate_ref": "candidate-a", "embedding": [0.1, 0.2]}])
        return service.recognize("scan-pad", [b"frame-1", b"frame-2", b"frame-3"])

    def test_only_live_liveness_can_remain_accepted(self):
        self.assertEqual("ACCEPT", self.recognize("LIVE").decision)
        self.assertEqual("REVIEW", self.recognize("NOT_RUN").decision)
        self.assertEqual("REVIEW", self.recognize("UNKNOWN").decision)
        self.assertEqual("REVIEW", self.recognize("CHALLENGE").decision)
        self.assertEqual("REVIEW", self.recognize("unexpected").decision)
        self.assertEqual("REJECT", self.recognize("SPOOF").decision)


if __name__ == "__main__":
    unittest.main()
