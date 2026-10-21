"""Tests for graceful concurrency overload rejection (HTTP 503 vs connection drop)."""

from __future__ import annotations

import json
import sys
from pathlib import Path
import unittest
from http import HTTPStatus
from unittest.mock import MagicMock

SERVICE_DIR = Path(__file__).resolve().parents[1]
if str(SERVICE_DIR) not in sys.path:
    sys.path.insert(0, str(SERVICE_DIR))

from app.main import InferenceHandler, LimitedThreadingHTTPServer
from app.settings import Settings


class MockSocket:
    def __init__(self, request_bytes: bytes):
        self.request_bytes = request_bytes
        self.response_bytes = bytearray()
        self.closed = False

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
        self.closed = True


class TestConcurrencyErrorDetection(unittest.TestCase):
    def setUp(self):
        self.settings = Settings()
        self.service = MagicMock()

    def test_overloaded_server_returns_503_and_retry_after(self):
        server = LimitedThreadingHTTPServer(("127.0.0.1", 0), InferenceHandler, self.service, self.settings)

        # Exhaust all 4 slots manually
        acquired = []
        for _ in range(4):
            self.assertTrue(server.request_slots.acquire(blocking=False))
            acquired.append(True)

        # Now simulate a 5th request arriving at process_request
        raw_req = b"GET /health HTTP/1.1\r\nHost: 127.0.0.1\r\n\r\n"
        sock = MockSocket(raw_req)

        server.process_request(sock, ("127.0.0.1", 55555))

        # Release acquired slots
        for _ in acquired:
            server.request_slots.release()

        # Check response sent to sock
        output = bytes(sock.response_bytes).decode("utf-8", errors="replace")
        self.assertTrue(len(output) > 0, "Server must not drop connection with 0 bytes")

        parts = output.split("\r\n\r\n", 1)
        header_part = parts[0]
        body_part = parts[1] if len(parts) > 1 else ""

        self.assertIn("503", header_part, f"Expected HTTP 503, got: {header_part}")
        self.assertIn("Retry-After", header_part)

        body = json.loads(body_part)
        self.assertEqual(body.get("error"), "CONCURRENCY_LIMIT_EXCEEDED")
        self.assertIn("limit", body.get("message", "").lower())


if __name__ == "__main__":
    unittest.main()
