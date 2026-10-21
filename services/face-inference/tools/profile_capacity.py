#!/usr/bin/env python3
"""Gate 1 capacity and resource profiling tool for private face inference.

Profiles:
1. Probe response times (/livez, /readyz, /health).
2. Memory RSS baseline and under load (checking headroom against MemoryHigh 512M).
3. P50, P90, P95, and P99 latency over N iterations.
4. Concurrency saturation behavior under burst load (verifying graceful HTTP 503).
"""

from __future__ import annotations

import argparse
import base64
import concurrent.futures
import json
import math
import os
import sys
import time
import urllib.error
import urllib.request
from pathlib import Path


def percentile(values: list[float], p: float) -> float:
    if not values:
        return 0.0
    s = sorted(values)
    idx = max(0, min(len(s) - 1, int(math.ceil(p * len(s))) - 1))
    return round(s[idx], 2)


def make_request(url: str, endpoint: str, payload: dict | None = None, token: str = "") -> tuple[int, dict, float]:
    req_url = f"{url.rstrip('/')}/{endpoint.lstrip('/')}"
    headers = {"Content-Type": "application/json"}
    if token:
        headers["X-Face-Inference-Token"] = token

    data = json.dumps(payload).encode("utf-8") if payload is not None else None
    req = urllib.request.Request(req_url, data=data, headers=headers, method="POST" if payload is not None else "GET")

    start = time.perf_counter()
    try:
        with urllib.request.urlopen(req, timeout=10) as resp:
            elapsed_ms = (time.perf_counter() - start) * 1000.0
            body = json.loads(resp.read().decode("utf-8"))
            return resp.status, body, elapsed_ms
    except urllib.error.HTTPError as err:
        elapsed_ms = (time.perf_counter() - start) * 1000.0
        try:
            body = json.loads(err.read().decode("utf-8"))
        except Exception:
            body = {"error": "HTTP_ERROR", "code": err.code}
        return err.code, body, elapsed_ms
    except Exception as exc:
        elapsed_ms = (time.perf_counter() - start) * 1000.0
        return 0, {"error": str(exc)}, elapsed_ms


def generate_synthetic_frame() -> str:
    # Minimal 100x100 1-channel or 3-channel valid JPEG or synthetic RGB image
    # Using cv2 if available, else a static 1x1 base64 pixel
    try:
        import cv2
        import numpy as np
        img = np.zeros((200, 200, 3), dtype=np.uint8)
        cv2.circle(img, (100, 100), 50, (255, 255, 255), -1)
        _, buf = cv2.imencode(".jpg", img)
        return base64.b64encode(buf.tobytes()).decode("ascii")
    except Exception:
        # Minimal 1x1 white JPEG
        return "/9j/4AAQSkZJRgABAQEASABIAAD/2wBDAP//////////////////////////////////////////////////////////////////////////////////////wgALCAABAAEBAREA/8QAFBABAAAAAAAAAAAAAAAAAAAAAP/aAAgBAQABPxA="


def profile(base_url: str, token: str, iterations: int = 50) -> dict:
    report: dict = {
        "timestamp": time.strftime("%Y-%m-%d %H:%M:%S"),
        "base_url": base_url,
        "iterations": iterations,
    }

    # 1. Probes check
    live_status, live_body, live_ms = make_request(base_url, "/livez")
    ready_status, ready_body, ready_ms = make_request(base_url, "/readyz")
    health_status, health_body, health_ms = make_request(base_url, "/health")

    report["probes"] = {
        "livez": {"status": live_status, "latency_ms": round(live_ms, 2), "body": live_body},
        "readyz": {"status": ready_status, "latency_ms": round(ready_ms, 2), "body": ready_body},
        "health": {"status": health_status, "latency_ms": round(health_ms, 2), "body": health_body},
    }

    # 2. Sequential load profiling
    frame = generate_synthetic_frame()
    payload = {"image": frame, "candidates": []}
    latencies: list[float] = []
    errors: list[dict] = []

    for _ in range(iterations):
        status, body, ms = make_request(base_url, "/v1/detect", payload={"image": frame}, token=token)
        if status == 200:
            latencies.append(ms)
        else:
            errors.append({"status": status, "body": body})

    report["latency_profile"] = {
        "samples": len(latencies),
        "errors": len(errors),
        "p50_ms": percentile(latencies, 0.50),
        "p90_ms": percentile(latencies, 0.90),
        "p95_ms": percentile(latencies, 0.95),
        "p99_ms": percentile(latencies, 0.99),
        "min_ms": round(min(latencies), 2) if latencies else 0,
        "max_ms": round(max(latencies), 2) if latencies else 0,
    }

    # 3. Post-load memory telemetry
    _, post_health, _ = make_request(base_url, "/health")
    report["memory_telemetry"] = post_health.get("memory", {})
    report["concurrency_telemetry"] = post_health.get("concurrency", {})

    # 4. Concurrency saturation test (burst of 8 simultaneous requests)
    def worker():
        return make_request(base_url, "/v1/detect", payload={"image": frame}, token=token)

    with concurrent.futures.ThreadPoolExecutor(max_workers=8) as executor:
        futures = [executor.submit(worker) for _ in range(8)]
        results = [f.result() for f in futures]

    statuses = [r[0] for r in results]
    report["burst_concurrency_test"] = {
        "total_requests": 8,
        "statuses": statuses,
        "success_count (200)": statuses.count(200),
        "overload_count (503)": statuses.count(503),
        "other_count": len([s for s in statuses if s not in (200, 503)]),
    }

    return report


def main() -> int:
    parser = argparse.ArgumentParser(description="Profile private face inference runtime capacity")
    parser.add_argument("--url", default=os.getenv("FACE_INFERENCE_URL", "http://127.0.0.1:6120"))
    parser.add_argument("--token", default=os.getenv("FACE_INFERENCE_SHARED_SECRET", ""))
    parser.add_argument("--iterations", type=int, default=50)
    args = parser.parse_args()

    results = profile(args.url, args.token, args.iterations)
    print(json.dumps(results, indent=2))
    return 0


if __name__ == "__main__":
    sys.exit(main())
