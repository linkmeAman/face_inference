"""FACE-EXP-01 disposable, score-only experiment logger scenario."""
from __future__ import annotations

import json
import statistics
import sys
import time
import unittest
from pathlib import Path

ROOT = Path(__file__).resolve().parents[1]
sys.path.insert(0, str(ROOT))

from app.experiment_logger import (ExperimentLogger, ExperimentLogRejected, MAX_ROSTER_MEMBERS,
                                   compact_frame_scores, opaque_id)
from app.experiment_shadow import ShadowQueue
from app.service import InferenceService
from app.schemas import MatchResult, QualityResult
from app.settings import Settings


class FaceExperimentLoggerTest(unittest.TestCase):
    def setUp(self):
        self.rows = []
        self.seen = set()
        def writer(payload):
            row = json.loads(payload)
            if row["experiment_id"] not in self.seen:  # stored-procedure unique key behavior
                self.seen.add(row["experiment_id"])
                self.rows.append(row)
        self.logger = ExperimentLogger(b"x" * 32, 2, writer)

    def record(self, roster_size=2, source="PRIMARY", ground_truth=False):
        evidence = json.loads(compact_frame_scores(self.logger.secret, 2, [
            {"template_scores": {"candidate-a#0": .91, "candidate-b#0": .20}, "quality": 1, "pad": .99},
            {"template_scores": {"candidate-a#0": .90, "candidate-b#0": .19}, "quality": 1, "pad": .98},
            {"template_scores": {"candidate-a#0": .92, "candidate-b#0": .21}, "quality": 1, "pad": .97},
        ]))
        return {"experiment_id": "event-%s-%s" % (roster_size, source), "source": source, "roster_size": roster_size,
                "experiment_config_version": "fixture", "model_name": "SFace", "model_version": "fixture",
                "runtime": "test", "precision_mode": "INT8", "preprocessing_version": "fixture", "policy_version": "fixture",
                "decision": "REVIEW", "outcome_code": "FIXTURE", "stage_latency_json": {"primary": 1},
                "frame_scores_json": evidence, "ground_truth_member_id": 42 if ground_truth else None}

    def test_end_to_end_contract(self):
        self.assertNotEqual(opaque_id(self.logger.secret, 1, "candidate", "a"), opaque_id(self.logger.secret, 2, "candidate", "a"))
        outcome = self.logger.record(self.record(ground_truth=True))
        self.assertEqual("LOGGED", outcome)
        self.assertEqual(1, len(self.rows))
        raw = json.dumps(self.rows[0])
        self.assertNotIn("candidate-a", raw)
        self.assertNotIn("ground_truth_member_id", raw)  # writer cannot set teacher truth
        evidence = self.rows[0]["frame_scores_json"]
        self.assertEqual(3, len(evidence["s"]))
        self.assertEqual(2, len(evidence["d"]["t"]))
        self.logger.record(self.record())
        self.assertEqual(1, len(self.rows), "primary writer is idempotent")
        shadow = ShadowQueue(lambda item: self.logger.record(item), limit=1)
        self.assertTrue(shadow.submit(self.record(source="SHADOW")))
        shadow._queue.join()
        self.assertEqual(2, len(self.rows), "shadow failure/queue is isolated from primary")
        self.assertEqual("ROSTER_SIZE_LIMIT", self.logger.record(self.record(MAX_ROSTER_MEMBERS + 1, "SHADOW")))
        self.assertEqual(3, len(self.rows))
        with self.assertRaises(ExperimentLogRejected):
            compact_frame_scores(self.logger.secret, 2, [{"template_scores": {"x#0": .1}}] * 2)

    def test_shadow_unavailable_has_no_primary_latency_or_decision_change(self):
        class Matcher:
            def match(self, frames, candidates):
                return MatchResult(decision="REVIEW", identity_decision="REVIEW", quality=QualityResult(face_count=1), reason_code="FIXTURE")
        def measure(experiment_logger):
            timings, decisions = [], []
            for number in range(20):
                service = InferenceService(Matcher(), Settings(calibration_approved=True), experiment_logger=experiment_logger)
                service.start_scan("scan-%d" % number, [{"candidate_ref": "candidate", "embedding": [.1, .2]}])
                started = time.perf_counter()
                decisions.append(service.recognize("scan-%d" % number, [b"one", b"two", b"three"]).decision)
                timings.append((time.perf_counter() - started) * 1000)
            return statistics.quantiles(timings, n=20)[18], decisions
        baseline_p95, baseline_decisions = measure(None)
        # A down writer is swallowed by the best-effort logger; the shadow
        # worker is not in the primary lock or request path.
        down_logger = ExperimentLogger(b"z" * 32, 1, lambda _payload: (_ for _ in ()).throw(OSError("shadow down")))
        down_p95, down_decisions = measure(down_logger)
        self.assertEqual(baseline_decisions, down_decisions)
        self.assertLessEqual(down_p95, baseline_p95 + 5.0)


if __name__ == "__main__":
    raise SystemExit(unittest.main())
