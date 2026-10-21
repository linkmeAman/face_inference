#!/usr/bin/env python3
"""Multi-venue field calibration evaluator.

Evaluates held-out score datasets against candidate acceptance thresholds and margins,
computing slice breakdowns across venues, devices, lighting conditions, and appearance variations.
Strictly verifies that False Acceptances == 0 and P95 latency <= target SLA.
"""

from __future__ import annotations

import json
import math
import sys
from collections import defaultdict
from pathlib import Path
from typing import Any, Dict, List, Mapping, Sequence


def calc_p95(values: Sequence[int]) -> int:
    if not values:
        return 0
    ordered = sorted(values)
    idx = max(0, min(len(ordered) - 1, int(math.ceil(0.95 * len(ordered))) - 1))
    return ordered[idx]


def calc_p50(values: Sequence[int]) -> int:
    if not values:
        return 0
    ordered = sorted(values)
    return ordered[(len(ordered) - 1) // 2]


class CalibrationEvaluator:
    def __init__(self, accept_threshold: float = 0.68, min_margin: float = 0.18, target_p95_ms: int = 100) -> None:
        self.accept_threshold = accept_threshold
        self.min_margin = min_margin
        self.target_p95_ms = target_p95_ms

    def evaluate(self, records: List[Dict[str, Any]]) -> Dict[str, Any]:
        total_samples = len(records)
        genuine_count = 0
        impostor_count = 0
        false_accepts = 0
        false_rejects = 0
        accepted_genuine = 0
        latencies: List[int] = []

        slices: Dict[str, Dict[str, Dict[str, int]]] = {
            "venue": defaultdict(lambda: {"genuine": 0, "impostor": 0, "accept": 0, "false_accept": 0, "review": 0}),
            "device": defaultdict(lambda: {"genuine": 0, "impostor": 0, "accept": 0, "false_accept": 0, "review": 0}),
            "lighting": defaultdict(lambda: {"genuine": 0, "impostor": 0, "accept": 0, "false_accept": 0, "review": 0}),
            "condition": defaultdict(lambda: {"genuine": 0, "impostor": 0, "accept": 0, "false_accept": 0, "review": 0}),
        }

        for r in records:
            label = r["label"]
            score = float(r["score"])
            margin = float(r["margin"])
            lat = int(r["latency_ms"])
            latencies.append(lat)

            venue = r.get("venue", "unknown")
            device = r.get("device", "unknown")
            lighting = r.get("lighting", "unknown")
            condition = r.get("condition", "unknown")

            is_accepted = (score >= self.accept_threshold and margin >= self.min_margin)

            if label == "genuine":
                genuine_count += 1
                if is_accepted:
                    accepted_genuine += 1
                else:
                    false_rejects += 1
            else:
                impostor_count += 1
                if is_accepted:
                    false_accepts += 1

            for slice_key, slice_val in [("venue", venue), ("device", device), ("lighting", lighting), ("condition", condition)]:
                target_slice = slices[slice_key][slice_val]
                target_slice[label] += 1
                if is_accepted:
                    target_slice["accept"] += 1
                    if label == "impostor":
                        target_slice["false_accept"] += 1
                else:
                    target_slice["review"] += 1

        p50 = calc_p50(latencies)
        p95 = calc_p95(latencies)
        far = (false_accepts / impostor_count) if impostor_count > 0 else 0.0
        frr = (false_rejects / genuine_count) if genuine_count > 0 else 0.0
        review_rate = (false_rejects + (impostor_count - false_accepts)) / total_samples if total_samples > 0 else 0.0

        passes = (false_accepts == 0 and p95 <= self.target_p95_ms)

        return {
            "passes": passes,
            "accept_threshold": self.accept_threshold,
            "min_margin": self.min_margin,
            "target_p95_ms": self.target_p95_ms,
            "total_samples": total_samples,
            "genuine_count": genuine_count,
            "impostor_count": impostor_count,
            "false_accepts": false_accepts,
            "false_rejects": false_rejects,
            "far_percent": far * 100.0,
            "frr_percent": frr * 100.0,
            "review_rate": review_rate,
            "p50_latency_ms": p50,
            "p95_latency_ms": p95,
            "slices": {k: dict(v) for k, v in slices.items()},
        }


def format_report(metrics: Dict[str, Any]) -> str:
    lines = [
        "===========================================================",
        "        FIELD CALIBRATION EVALUATION REPORT",
        "===========================================================",
        f"Operating Point: Accept Threshold >= {metrics['accept_threshold']:.2f}, Min Margin >= {metrics['min_margin']:.2f}",
        f"Latency SLA Target: <= {metrics['target_p95_ms']} ms",
        "-----------------------------------------------------------",
        f"Total Samples Evaluated: {metrics['total_samples']} ({metrics['genuine_count']} Genuine, {metrics['impostor_count']} Impostor)",
        f"False Acceptances (FAR): {metrics['false_accepts']} ({metrics['far_percent']:.2f}%)",
        f"False Rejections (FRR):  {metrics['false_rejects']} ({metrics['frr_percent']:.2f}%)",
        f"Teacher Review Rate:     {metrics['review_rate'] * 100.0:.1f}%",
        f"P50 Latency:             {metrics['p50_latency_ms']} ms",
        f"P95 Latency:             {metrics['p95_latency_ms']} ms",
        "-----------------------------------------------------------",
        "SLICE-BY-SLICE ANALYSIS:",
    ]

    for cat, items in metrics["slices"].items():
        lines.append(f"\n  [{cat.upper()}]")
        for name, data in items.items():
            lines.append(
                f"    - {name:25s}: Gen={data['genuine']:2d}, Imp={data['impostor']:2d} | "
                f"Accepts={data['accept']:2d}, FalseAccepts={data['false_accept']:2d}, Reviews={data['review']:2d}"
            )

    lines.append("-----------------------------------------------------------")
    lines.append(f"FINAL RESULT: {'PASSED' if metrics['passes'] else 'FAILED'}")
    lines.append("===========================================================")
    return "\n".join(lines)


def main(argv: List[str]) -> int:
    if len(argv) < 2:
        print("Usage: evaluate_calibration.py <held_out_scores.json> [threshold=0.68] [margin=0.18] [p95_target=100]", file=sys.stderr)
        return 2

    path = Path(argv[1])
    if not path.is_file():
        print(f"Error: file {path} not found", file=sys.stderr)
        return 1

    threshold = float(argv[2]) if len(argv) > 2 else 0.68
    margin = float(argv[3]) if len(argv) > 3 else 0.18
    p95_target = int(argv[4]) if len(argv) > 4 else 100

    try:
        records = json.loads(path.read_text())
        evaluator = CalibrationEvaluator(threshold, margin, p95_target)
        metrics = evaluator.evaluate(records)
        report = format_report(metrics)
        print(report)

        if not metrics["passes"]:
            if metrics["false_accepts"] > 0:
                print(f"ERROR: Held-out evaluation observed {metrics['false_accepts']} false accepts!", file=sys.stderr)
            if metrics["p95_latency_ms"] > p95_target:
                print(f"ERROR: P95 latency {metrics['p95_latency_ms']}ms exceeds target {p95_target}ms!", file=sys.stderr)
            return 1

        return 0
    except Exception as exc:
        print(f"Evaluation failed: {exc}", file=sys.stderr)
        return 1


if __name__ == "__main__":
    raise SystemExit(main(sys.argv))
