"""Public inference responses must abstain without exposing an identity."""

import sys
import unittest
from dataclasses import replace
from pathlib import Path

sys.path.insert(0, str(Path(__file__).resolve().parents[1] / "app"))

from schemas import MatchResult  # type: ignore
from service import InferenceError, InferenceService  # type: ignore
from settings import Settings  # type: ignore


class SafetyOutcomeTest(unittest.TestCase):
    def test_generation_binds_identity_and_pad_model_hashes(self):
        base = Settings(model_manifest={"recognizer": {"sha256": "identity-a"},
                                        "pad": {"sha256": "pad-a"}})
        self.assertNotEqual(base.recognition_generation,
                            replace(base, model_manifest={"recognizer": {"sha256": "identity-b"},
                                                          "pad": {"sha256": "pad-a"}}).recognition_generation)
        self.assertNotEqual(base.recognition_generation,
                            replace(base, model_manifest={"recognizer": {"sha256": "identity-a"},
                                                          "pad": {"sha256": "pad-b"}}).recognition_generation)

    def test_scan_generation_change_discards_scan(self):
        service = InferenceService(object(), settings=Settings(model_version="G17"))
        service.start_scan("scan-g17", [{"candidate_ref": "a", "embeddings": [[0.1, 0.2], [0.1, 0.2]]}])
        service.settings = replace(service.settings, model_version="G18", accept_threshold=0.99)
        with self.assertRaises(InferenceError) as failure:
            service.recognize("scan-g17", [b"one", b"two", b"three"])
        self.assertEqual("GENERATION_MISMATCH", failure.exception.reason)
        self.assertNotIn("scan-g17", service.scan_snapshot())

    def test_non_accept_outcomes_clear_candidate(self):
        cases = [
            ("REVIEW", "LIVE", "AMBIGUOUS_IDENTITY", "AMBIGUOUS"),
            ("REVIEW", "LIVE", "IDENTITY_MISMATCH", "UNKNOWN"),
            ("REVIEW", "SPOOF", "PASSIVE_SPOOF", "REJECT_PAD"),
            ("REJECT", "SPOOF", "REPLAYED_API_REQUEST", "REJECT_REPLAY"),
            ("REVIEW", "UNKNOWN", "CALIBRATION_REQUIRED", "SYSTEM_NOT_READY"),
        ]
        for decision, liveness, reason, outcome in cases:
            with self.subTest(outcome=outcome):
                result = MatchResult(decision=decision, candidate_ref="private-candidate",
                                     identity_decision="ACCEPT", liveness=liveness,
                                     reason_code=reason)
                body = result.to_dict()
                self.assertEqual(outcome, body["outcome"])
                self.assertIsNone(body["candidate_ref"])
                self.assertNotEqual("ACCEPT", body["identity_decision"])

    def test_accept_requires_live_pad_and_candidate(self):
        result = MatchResult(decision="ACCEPT", candidate_ref="candidate-a", liveness="LIVE")
        self.assertEqual("ACCEPT", result.to_dict()["outcome"])
        self.assertEqual("candidate-a", result.to_dict()["candidate_ref"])
        result = MatchResult(decision="ACCEPT", candidate_ref="candidate-a", liveness="UNKNOWN")
        self.assertNotEqual("ACCEPT", result.to_dict()["outcome"])
        self.assertIsNone(result.to_dict()["candidate_ref"])


if __name__ == "__main__":
    unittest.main()
