#!/usr/bin/env python3
"""Focused contract for the additive private testing-model API."""

from __future__ import annotations

import base64
import http.client
import json
import sys
import threading
import time
import unittest
from pathlib import Path


SERVICE_DIR = Path(__file__).resolve().parents[1]
APP_DIR = SERVICE_DIR / "app"
sys.path.insert(0, str(APP_DIR))

from main import InferenceHandler, LimitedThreadingHTTPServer  # type: ignore
from service import InferenceService  # type: ignore
from settings import Settings  # type: ignore


class FakeTestingMatcher:
    def detect_evidence(self, frame):
        return {
            "face_count": 1,
            "faces": [{
                "box": {"x": 10.0, "y": 20.0, "width": 80.0, "height": 90.0},
                "landmarks": {
                    "right_eye": [32.0, 45.0], "left_eye": [67.0, 45.0],
                    "nose": [50.0, 62.0], "right_mouth": [37.0, 83.0],
                    "left_mouth": [64.0, 83.0],
                },
                "quality": {"accepted": True, "reasons": []},
            }],
            "model_version": "testing-model",
            "latency_ms": 4,
        }

    def infer_evidence(self, frame, candidates):
        return {
            "face_count": 1,
            "faces": self.detect_evidence(frame)["faces"],
            "embedding": [0.6, 0.8],
            "matches": [{"candidate_ref": "candidate-a", "score": 0.91}],
            "top_match": {"candidate_ref": "candidate-a", "score": 0.91},
            "margin": 0.91,
            "model_version": "testing-model",
            "latency_ms": 7,
        }


class TestingModelHttpContract(unittest.TestCase):
    @classmethod
    def setUpClass(cls):
        settings = Settings(shared_secret="test-secret")
        cls.server = LimitedThreadingHTTPServer(
            ("127.0.0.1", 0),
            InferenceHandler,
            InferenceService(FakeTestingMatcher(), settings=settings),
            settings,
        )
        cls.thread = threading.Thread(target=cls.server.serve_forever, daemon=True)
        cls.thread.start()

    @classmethod
    def tearDownClass(cls):
        cls.server.shutdown()
        cls.server.server_close()
        cls.thread.join(timeout=2)

    def request(self, path, payload, token="test-secret"):
        connection = http.client.HTTPConnection("127.0.0.1", self.server.server_port, timeout=2)
        connection.request("POST", path, body=json.dumps(payload), headers={
            "Content-Type": "application/json",
            "X-Face-Inference-Token": token,
        })
        response = connection.getresponse()
        body = json.loads(response.read().decode("utf-8"))
        connection.close()
        return response.status, body

    def test_detect_returns_box_landmarks_and_quality_without_embedding(self):
        frame = base64.b64encode(b"bounded-test-frame").decode("ascii")
        status, body = self.request("/v1/detect", {"image": frame})
        self.assertEqual(200, status)
        self.assertEqual(1, body["face_count"])
        self.assertIn("landmarks", body["faces"][0])
        self.assertIn("quality", body["faces"][0])
        self.assertNotIn("embedding", json.dumps(body).lower())

    def test_infer_returns_normalized_embedding_and_ranked_opaque_matches(self):
        frame = base64.b64encode(b"bounded-test-frame").decode("ascii")
        status, body = self.request("/v1/infer", {
            "image": frame,
            "candidates": [{"candidate_ref": "candidate-a", "embedding": [0.6, 0.8]}],
        })
        self.assertEqual(200, status)
        self.assertEqual([0.6, 0.8], body["embedding"])
        self.assertEqual("candidate-a", body["top_match"]["candidate_ref"])
        self.assertNotIn("member_id", json.dumps(body))
        self.assertNotIn("attendance", json.dumps(body))

    def test_testing_routes_require_private_token(self):
        frame = base64.b64encode(b"bounded-test-frame").decode("ascii")
        status, body = self.request("/v1/detect", {"image": frame}, token="wrong")
        self.assertEqual(401, status)
        self.assertEqual("UNAUTHORIZED", body["error"])


class SerializedInferenceContract(unittest.TestCase):
    def test_model_execution_is_serialized(self):
        class MeasuringMatcher(FakeTestingMatcher):
            active = 0
            maximum = 0
            guard = threading.Lock()

            def detect_evidence(self, frame):
                with self.guard:
                    self.active += 1
                    self.maximum = max(self.maximum, self.active)
                time.sleep(0.03)
                with self.guard:
                    self.active -= 1
                return super().detect_evidence(frame)

        matcher = MeasuringMatcher()
        service = InferenceService(matcher, settings=Settings())
        workers = [threading.Thread(target=service.detect_testing, args=(b"frame",)) for _ in range(3)]
        for worker in workers:
            worker.start()
        for worker in workers:
            worker.join(timeout=1)
        self.assertEqual(1, matcher.maximum)


if __name__ == "__main__":
    result = unittest.main(exit=False)
    raise SystemExit(0 if result.result.wasSuccessful() else 1)
