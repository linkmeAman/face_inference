#!/usr/bin/env python3
"""Verify a model manifest and calibrate a strict no-wrong-match policy.

Usage:
  python3 tools/calibrate.py approval-manifest.json held-out-scores.json output.json [threshold] [margin] [target_p95_ms]

The score file is a JSON array containing label, score, margin, and latency_ms.
No images or embeddings are accepted by this tool.
"""

from __future__ import annotations

import json
import sys
from pathlib import Path

APP_DIR = Path(__file__).resolve().parents[1] / "app"
sys.path.insert(0, str(APP_DIR))

from calibration import approve_model, calibrate_scores  # type: ignore
from safe_logging import SafeFaceLogger  # type: ignore


def main(argv):
    if len(argv) < 4:
        print("usage: calibrate.py approval-manifest.json held-out-scores.json output.json [threshold] [margin] [target_p95_ms]", file=sys.stderr)
        return 2
    logger = SafeFaceLogger()
    try:
        manifest = json.loads(Path(argv[1]).read_text())
        raw_records = Path(argv[2]).read_text()
        try:
            records = json.loads(raw_records)
        except json.JSONDecodeError:
            records = [json.loads(line) for line in raw_records.splitlines() if line.strip()]

        target_threshold = float(argv[4]) if len(argv) > 4 else None
        target_margin = float(argv[5]) if len(argv) > 5 else None
        target_p95 = int(argv[6]) if len(argv) > 6 else 100

        approval = approve_model(manifest)
        report = calibrate_scores(
            records,
            target_p95_ms=target_p95,
            target_threshold=target_threshold,
            target_margin=target_margin,
        )
        if report.false_accepts != 0:
            raise RuntimeError("calibration produced false accepts")

        revalidation_triggers = manifest.get("revalidation_triggers", [
            "model_weights_updated",
            "detector_or_alignment_modified",
            "camera_hardware_spec_changed",
            "material_demographics_shift",
        ])

        output = {
            "status": "APPROVED",
            "license_reference": approval.license_reference,
            "approved_by": str(manifest.get("approved_by", "")),
            "approved_at": str(manifest.get("approved_at", "")),
            "policy_version": approval.policy_version,
            "files": approval.files,
            "accept_threshold": round(report.accept_threshold, 4),
            "min_margin": round(report.min_margin, 4),
            "enrollment_verify_threshold": round(report.accept_threshold, 4),
            "false_accepts": report.false_accepts,
            "false_rejects": report.false_rejects,
            "p50_latency_ms": report.p50_latency_ms,
            "p95_latency_ms": report.p95_latency_ms,
            "sample_count": report.sample_count,
            "review_rate": report.review_rate,
            "revalidation_triggers": revalidation_triggers,
        }
        Path(argv[3]).write_text(json.dumps(output, indent=2, sort_keys=True) + "\n")
        logger.log("CALIBRATION_APPROVED", {
            "sample_count": report.sample_count,
            "p95_latency_ms": report.p95_latency_ms,
            "accept_threshold": output["accept_threshold"],
            "min_margin": output["min_margin"],
        })
        print(f"CALIBRATION_APPROVED: threshold={output['accept_threshold']}, margin={output['min_margin']}, p95={output['p95_latency_ms']}ms, false_accepts={output['false_accepts']}")
        return 0
    except Exception as exc:
        reason = getattr(exc, "reason", "CALIBRATION_FAILED")
        logger.log("CALIBRATION_FAILED", {"reason": reason, "detail": str(exc)})
        print(f"CALIBRATION_FAILED: {reason} ({exc})", file=sys.stderr)
        return 1


if __name__ == "__main__":
    raise SystemExit(main(sys.argv))
