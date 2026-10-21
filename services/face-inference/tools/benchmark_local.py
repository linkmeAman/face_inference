#!/usr/bin/env python3
"""Measure private /v1/detect or /v1/infer latency with one local image."""

from __future__ import annotations

import argparse
import base64
import http.client
import json
import math
import os
import time
from pathlib import Path
from urllib.parse import urlsplit


def percentile(values, fraction):
    ordered = sorted(values)
    return ordered[max(0, min(len(ordered) - 1, math.ceil(fraction * len(ordered)) - 1))]


def main() -> int:
    parser = argparse.ArgumentParser(description="Benchmark the private local face inference service")
    parser.add_argument("image", type=Path)
    parser.add_argument("--endpoint", choices=("detect", "infer"), default="detect")
    parser.add_argument("--iterations", type=int, default=25)
    parser.add_argument("--url", default=os.getenv("FACE_INFERENCE_URL", "http://127.0.0.1:6120"))
    args = parser.parse_args()
    if args.iterations < 1:
        parser.error("--iterations must be positive")
    raw = args.image.read_bytes()
    if not raw or len(raw) > 1_000_000:
        parser.error("image must be non-empty and no larger than 1 MB")
    parsed = urlsplit(args.url)
    payload = {"image": base64.b64encode(raw).decode("ascii")}
    if args.endpoint == "infer":
        payload["candidates"] = []
    encoded = json.dumps(payload, separators=(",", ":")).encode("utf-8")
    headers = {"Content-Type": "application/json"}
    token = os.getenv("FACE_INFERENCE_SHARED_SECRET", "")
    if token:
        headers["X-Face-Inference-Token"] = token
    timings = []
    for _ in range(args.iterations):
        connection = http.client.HTTPConnection(parsed.hostname, parsed.port or 80, timeout=30)
        started = time.perf_counter()
        connection.request("POST", "/v1/" + args.endpoint, body=encoded, headers=headers)
        response = connection.getresponse()
        body = response.read()
        timings.append((time.perf_counter() - started) * 1000.0)
        connection.close()
        if response.status != 200:
            raise RuntimeError("benchmark request failed with HTTP %d: %s" % (response.status, body[:200].decode("utf-8", "replace")))
    print(json.dumps({
        "endpoint": args.endpoint,
        "iterations": args.iterations,
        "p50_ms": round(percentile(timings, 0.50), 3),
        "p95_ms": round(percentile(timings, 0.95), 3),
        "min_ms": round(min(timings), 3),
        "max_ms": round(max(timings), 3),
    }, sort_keys=True))
    return 0


if __name__ == "__main__":
    raise SystemExit(main())
