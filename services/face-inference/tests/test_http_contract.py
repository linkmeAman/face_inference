#!/usr/bin/env python3
"""HTTP boundary checks for the private no-write inference spike."""

from __future__ import annotations

import base64
import http.client
import json
import sys
import threading
import unittest
from pathlib import Path


SERVICE_DIR = Path(__file__).resolve().parents[1]
APP_DIR = SERVICE_DIR / "app"
sys.path.insert(0, str(APP_DIR))
sys.path.insert(0, str(SERVICE_DIR))

from main import InferenceHandler, LimitedThreadingHTTPServer  # type: ignore
from matcher import MatchResult, QualityResult  # type: ignore
from service import InferenceService  # type: ignore
from settings import Settings  # type: ignore


class FakeMatcher:
    def match(self, frames, candidates):
        return MatchResult(
            decision="ACCEPT",
            candidate_ref="candidate-a",
            top1_score=0.95,
            top2_score=0.20,
            margin=0.75,
            quality=QualityResult(face_count=1, sharpness_ok=True, exposure_ok=True, pose_ok=True),
            liveness="LIVE",
            model_version="test-model",
        )

    def encode_enrollment(self, frames):
        return {
            "model_version_id": 9,
            "model_version": "test-model",
            "embeddings": [[0.1, 0.2, 0.3]],
            "embedding_dimension": 3,
            "quality_summary": {"frames_received": len(frames), "frames_accepted": len(frames)},
            "verification_passed": True,
            "verification_reason": "SAME_DAY_MATCH",
        }

    def encode_reference(self, frame):
        return {
            "model_version_id": 9,
            "model_version": "test-model",
            "policy_version": "face-policy-v2",
            "embedding": [0.1, 0.2, 0.3],
            "embedding_dimension": 3,
            "quality": {
                "sharpness_ok": True,
                "exposure_ok": True,
                "pose_ok": True,
                "size_ok": True,
                "centred_ok": True,
                "reasons": [],
            },
            "quality_evidence": {"accepted": True},
            "pad": {"state": "LIVE", "score": 0.99},
            "pad_score": 0.99,
        }

    def preview(self, frame):
        return {
            "face_count": 1,
            "faces": [{"x": 10.0, "y": 20.0, "width": 80.0, "height": 80.0}],
            "quality": QualityResult(face_count=1, sharpness_ok=True, exposure_ok=True, pose_ok=True).to_dict(),
            "model_version": "test-model",
            "latency_ms": 1,
        }


class HttpContractTest(unittest.TestCase):
    @classmethod
    def setUpClass(cls):
        settings = Settings(shared_secret="test-secret", accept_threshold=0.90, calibration_approved=True)
        cls.server = LimitedThreadingHTTPServer(
            ("127.0.0.1", 0), InferenceHandler, InferenceService(FakeMatcher(), settings=settings), settings
        )
        cls.thread = threading.Thread(target=cls.server.serve_forever, daemon=True)
        cls.thread.start()

    @classmethod
    def tearDownClass(cls):
        cls.server.shutdown()
        cls.server.server_close()
        cls.thread.join(timeout=2)

    def request(self, method, path, payload=None, token="test-secret"):
        connection = http.client.HTTPConnection("127.0.0.1", self.server.server_port, timeout=2)
        body = json.dumps(payload).encode("utf-8") if payload is not None else None
        headers = {"Content-Type": "application/json", "X-Face-Inference-Token": token}
        connection.request(method, path, body=body, headers=headers)
        response = connection.getresponse()
        result = json.loads(response.read().decode("utf-8"))
        connection.close()
        return response.status, result

    def test_internal_routes_require_token_and_return_decision_only(self):
        status, body = self.request("POST", "/internal/scans/scan-http/roster", {"candidates": [
            {"candidate_ref": "candidate-a", "embedding": [0.1, 0.2]},
        ]}, token="wrong")
        self.assertEqual(401, status)
        self.assertEqual("UNAUTHORIZED", body["error"])

        status, body = self.request("POST", "/internal/scans/scan-http/roster", {"candidates": [
            {"candidate_ref": "candidate-a", "embedding": [0.1, 0.2]},
        ]})
        self.assertEqual(200, status)
        self.assertEqual(1, body["candidate_count"])
        self.assertNotIn("embedding", body)

        frames = [base64.b64encode(f"bounded-frame-{index}".encode()).decode("ascii") for index in range(3)]
        status, body = self.request("POST", "/internal/scans/scan-http/attempts", {
            "frames": frames, "client_z_variance": 0.10,
        })
        self.assertEqual(200, status)
        self.assertEqual("ACCEPT", body["decision"])
        self.assertEqual("candidate-a", body["candidate_ref"])
        self.assertNotIn("bounded-frame", json.dumps(body).lower())

        status, body = self.request("DELETE", "/internal/scans/scan-http")
        self.assertEqual(200, status)
        self.assertTrue(body["deleted"])

        status, body = self.request("GET", "/model-info")
        self.assertEqual(200, status)
        self.assertIn("integrity_verified", body)

    def test_attempt_rejects_mismatched_capture_contexts(self):
        status, body = self.request("POST", "/internal/scans/scan-context/roster", {"candidates": [
            {"candidate_ref": "candidate-a", "embedding": [0.1, 0.2]},
        ]})
        self.assertEqual(200, status)
        frame = base64.b64encode(b"bounded-frame-context").decode("ascii")
        status, body = self.request("POST", "/internal/scans/scan-context/attempts", {
            "frames": [frame],
            "capture_contexts": [],
            "client_z_variance": 0.10,
        })
        self.assertEqual(400, status)
        self.assertEqual("INVALID_CAPTURE_CONTEXT", body["reason_code"])
        self.assertEqual("GUIDE_SELECTION", body["stage"])

    def test_client_planar_gate_is_telemetry_and_flags_low_depth(self):
        candidates = [{"candidate_ref": "candidate-a", "embedding": [0.1, 0.2]}]
        status, _ = self.request("POST", "/internal/scans/scan-planar-live/roster", {"candidates": candidates})
        self.assertEqual(200, status)
        live_frames = [base64.b64encode(f"planar-live-{i}".encode()).decode("ascii") for i in range(3)]
        status, body = self.request("POST", "/internal/scans/scan-planar-live/attempts", {
            "frames": live_frames, "client_z_variance": 0.01,
            "FACE_ATTENDANCE_SHADOW_MODE": "0",
        })
        self.assertEqual(200, status)
        self.assertTrue(body["planar_spoof_flag"])
        self.assertEqual("SPOOF_PLANAR_ATTACK", body["edge_gate_status"])

        status, _ = self.request("POST", "/internal/scans/scan-planar-shadow/roster", {"candidates": candidates})
        self.assertEqual(200, status)
        shadow_frames = [base64.b64encode(f"planar-shadow-{i}".encode()).decode("ascii") for i in range(3)]
        status, body = self.request("POST", "/internal/scans/scan-planar-shadow/attempts", {
            "frames": shadow_frames, "client_z_variance": 0.01,
            "FACE_ATTENDANCE_SHADOW_MODE": "1",
        })
        self.assertEqual(200, status)
        self.assertTrue(body["planar_spoof_flag"])

        status, _ = self.request("POST", "/internal/scans/scan-planar-missing/roster", {"candidates": candidates})
        self.assertEqual(200, status)
        missing_frames = [base64.b64encode(f"planar-missing-{i}".encode()).decode("ascii") for i in range(3)]
        status, body = self.request("POST", "/internal/scans/scan-planar-missing/attempts", {
            "frames": missing_frames,
            "FACE_ATTENDANCE_SHADOW_MODE": "1",
        })
        self.assertEqual(200, status)
        self.assertFalse(body["planar_spoof_flag"])
        self.assertEqual("CLIENT_PLANAR_Z_UNAVAILABLE", body["edge_gate_status"])

    def test_enrollment_route_requires_seven_frames_and_returns_no_raw_frame(self):
        frames = [base64.b64encode(f"enrollment-{index}".encode()).decode("ascii") for index in range(7)]
        status, body = self.request("POST", "/internal/enrollments/encode", {"frames": frames})
        self.assertEqual(200, status)
        self.assertEqual(9, body["model_version_id"])
        self.assertEqual("local-onnx", body["provider_id"])
        self.assertEqual("1", body["provider_version"])
        self.assertEqual("face-policy-v3", body["calibration_policy_version"])
        self.assertTrue(body["verification_passed"])
        self.assertNotIn("enrollment-", json.dumps(body))

        status, body = self.request("POST", "/internal/enrollments/encode", {"frames": frames[:6]})
        self.assertEqual(400, status)
        self.assertEqual("FRAME_COUNT", body["error"])

    def test_model_enrollment_preview_returns_coordinates_without_frame_data(self):
        frame = base64.b64encode(b"preview-frame").decode("ascii")
        status, body = self.request("POST", "/internal/enrollments/preview", {"frames": [frame]})
        self.assertEqual(200, status)
        self.assertEqual(1, body["face_count"])
        self.assertEqual(10.0, body["faces"][0]["x"])
        self.assertNotIn("preview-frame", json.dumps(body))

    def test_reference_encode_endpoint_returns_quality_and_pad(self):
        frame = base64.b64encode(b"reference-frame").decode("ascii")
        status, body = self.request("POST", "/internal/reference/encode", {"image": frame})
        self.assertEqual(200, status)
        self.assertEqual(9, body["model_version_id"])
        self.assertIn("quality", body)
        self.assertTrue(body["quality"]["pose_ok"])
        self.assertTrue(body["quality"]["sharpness_ok"])
        self.assertEqual("local-onnx", body["provider_id"])
        self.assertEqual("1", body["provider_version"])
        self.assertEqual("face-policy-v3", body["calibration_policy_version"])
        self.assertIn("pad", body)
        self.assertEqual("LIVE", body["pad"]["state"])
        self.assertEqual(0.99, body["pad_score"])
        self.assertNotIn("reference-frame", json.dumps(body))

    def test_reference_encode_endpoint_supports_burst_frames(self):
        frames = [base64.b64encode(f"frame-{i}".encode()).decode("ascii") for i in range(3)]
        status, body = self.request("POST", "/internal/reference/encode", {"frames": frames})
        self.assertEqual(200, status)
        self.assertEqual("LIVE", body["pad"]["state"])
        self.assertEqual(0.99, body["pad_score"])


if __name__ == "__main__":
    result = unittest.main(exit=False)
    raise SystemExit(0 if result.result.wasSuccessful() else 1)
