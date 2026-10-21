"""FACE-PAD-01 randomized active challenge contract."""

from __future__ import annotations

import sys
import unittest
from pathlib import Path


APP_DIR = Path(__file__).resolve().parents[1] / "app"
sys.path.insert(0, str(APP_DIR))

from matcher import MatchResult, QualityResult  # type: ignore  # noqa: E402
from service import InferenceError, InferenceService  # type: ignore  # noqa: E402
from settings import Settings  # type: ignore  # noqa: E402


class ChallengeMatcher:
    def __init__(self) -> None:
        self.challenge_calls = 0

    def match(self, frames, candidates):
        return MatchResult(
            decision="ACCEPT",
            candidate_ref="candidate-a",
            quality=QualityResult(face_count=1),
            liveness="CHALLENGE",
            liveness_score=0.55,
            liveness_reason="PASSIVE_BORDERLINE",
            pose_yaw=0.0,
            model_version="test",
        )

    def match_challenge(self, frames, candidates, challenge_type, baseline_yaw, candidate_ref):
        self.challenge_calls += 1
        if challenge_type not in {"LOOK_LEFT", "LOOK_RIGHT"}:
            raise AssertionError(challenge_type)
        if baseline_yaw != 0.0 or candidate_ref != "candidate-a":
            raise AssertionError("challenge binding changed")
        return MatchResult(
            decision="ACCEPT",
            candidate_ref="candidate-a",
            quality=QualityResult(face_count=1),
            liveness="LIVE",
            liveness_score=0.55,
            liveness_reason="ACTIVE_CHALLENGE_PASSED",
            model_version="test",
        )


class ActiveChallengeTest(unittest.TestCase):
    def setUp(self):
        self.matcher = ChallengeMatcher()
        self.service = InferenceService(
            self.matcher,
            settings=Settings(calibration_approved=True, challenge_ttl_seconds=15),
        )
        self.service.start_scan(
            "scan-challenge", [{"candidate_ref": "candidate-a", "embedding": [0.1, 0.2]}]
        )

    def test_borderline_passive_result_issues_single_use_random_challenge(self):
        first = self.service.recognize("scan-challenge", [b"base-1", b"base-2", b"base-3"])
        self.assertEqual("REVIEW", first.decision)
        self.assertEqual("CHALLENGE", first.liveness)
        self.assertIn(first.challenge["type"], {"LOOK_LEFT", "LOOK_RIGHT"})
        self.assertGreaterEqual(len(first.challenge["token"]), 32)
        self.assertEqual(15, first.challenge["expires_in"])

        completed = self.service.recognize(
            "scan-challenge",
            [b"turn-1", b"turn-2", b"turn-3"],
            challenge_token=first.challenge["token"],
        )
        self.assertEqual("ACCEPT", completed.decision)
        self.assertEqual("LIVE", completed.liveness)
        self.assertEqual(1, self.matcher.challenge_calls)

        with self.assertRaises(InferenceError) as context:
            self.service.recognize(
                "scan-challenge", [b"again-1", b"again-2", b"again-3"], challenge_token=first.challenge["token"]
            )
        self.assertEqual("CHALLENGE_INVALID", context.exception.reason)

    def test_wrong_challenge_token_is_rejected_without_inference(self):
        self.service.recognize("scan-challenge", [b"base-a", b"base-b", b"base-c"])
        with self.assertRaises(InferenceError) as context:
            self.service.recognize(
                "scan-challenge", [b"turn-a", b"turn-b", b"turn-c"], challenge_token="wrong"
            )
        self.assertEqual("CHALLENGE_INVALID", context.exception.reason)
        self.assertEqual(0, self.matcher.challenge_calls)


if __name__ == "__main__":
    unittest.main()
