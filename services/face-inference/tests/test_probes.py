"""Tests for discrete /livez and /readyz probes and health telemetry."""

from __future__ import annotations

import json
import unittest
from http import HTTPStatus
from unittest.mock import MagicMock

from app.main import InferenceHandler, LimitedThreadingHTTPServer
from app.settings import Settings


class MockSocket:
    def __init__(self, request_bytes: bytes):
        self.request_bytes = request_bytes
        self.response_bytes = bytearray()

    def makefile(self, mode="r", buffering=-1):
        import io
        if "b" in mode:
            if "r" in mode:
                return io.BytesIO(self.request_bytes)
            return io.BytesIO()
        if "r" in mode:
            return io.TextIOWrapper(io.BytesIO(self.request_bytes))
        return io.TextIOWrapper(io.BytesIO())

    def sendall(self, data):
        self.response_bytes.extend(data)

    def close(self):
        pass


def make_request(handler_cls, method: str, path: str, service, settings, headers=None) -> tuple[int, dict, dict]:
    raw_req = f"{method} {path} HTTP/1.1\r\nHost: 127.0.0.1\r\n"
    if headers:
        for k, v in headers.items():
            raw_req += f"{k}: {v}\r\n"
    raw_req += "\r\n"

    sock = MockSocket(raw_req.encode("utf-8"))
    server = LimitedThreadingHTTPServer(("127.0.0.1", 0), handler_cls, service, settings)

    handler = handler_cls(sock, ("127.0.0.1", 54321), server)

    # Parse response
    output = bytes(sock.response_bytes).decode("utf-8", errors="replace")
    parts = output.split("\r\n\r\n", 1)
    header_part = parts[0]
    body_part = parts[1] if len(parts) > 1 else ""

    status_line = header_part.splitlines()[0]
    status_code = int(status_line.split(" ")[1])

    resp_headers = {}
    for line in header_part.splitlines()[1:]:
        if ": " in line:
            k, v = line.split(": ", 1)
            resp_headers[k.lower()] = v

    body = {}
    if body_part and "application/json" in resp_headers.get("content-type", ""):
        try:
            body = json.loads(body_part)
        except json.JSONDecodeError:
            pass

    return status_code, resp_headers, body


class TestProbes(unittest.TestCase):
    def setUp(self):
        self.settings = Settings(
            model_integrity_verified=True,
            model_error=None,
            model_version="test-v1",
            accept_threshold=0.61,
            min_margin=0.15,
            calibration_approved=True,
            pad_calibration_approved=True,
            single_candidate_accept_threshold=0.90,
            min_template_support=2,
        )
        self.service = MagicMock()
        self.service.matcher.health.return_value = {"model_ready": True}
        self.service.scan_snapshot.return_value = {}

    def test_livez_probe_returns_200_when_alive(self):
        status, headers, body = make_request(InferenceHandler, "GET", "/livez", self.service, self.settings)
        self.assertEqual(status, HTTPStatus.OK)
        self.assertEqual(body.get("status"), "alive")
        self.assertIn("uptime_seconds", body)

    def test_readyz_probe_returns_200_when_ready(self):
        status, headers, body = make_request(InferenceHandler, "GET", "/readyz", self.service, self.settings)
        self.assertEqual(status, HTTPStatus.OK)
        self.assertEqual(body.get("status"), "ready")
        self.assertTrue(body.get("model_ready"))

    def test_readyz_probe_returns_503_when_model_not_ready(self):
        self.service.matcher.health.return_value = {"model_ready": False}
        status, headers, body = make_request(InferenceHandler, "GET", "/readyz", self.service, self.settings)
        self.assertEqual(status, HTTPStatus.SERVICE_UNAVAILABLE)
        self.assertEqual(body.get("status"), "not_ready")

    def test_health_telemetry_includes_memory_and_concurrency(self):
        status, headers, body = make_request(InferenceHandler, "GET", "/health", self.service, self.settings)
        self.assertEqual(status, HTTPStatus.OK)
        self.assertIn("memory", body)
        self.assertIn("rss_mb", body["memory"])
        self.assertIn("concurrency", body)
        self.assertEqual(body["concurrency"]["max_slots"], 4)


if __name__ == "__main__":
    unittest.main()
