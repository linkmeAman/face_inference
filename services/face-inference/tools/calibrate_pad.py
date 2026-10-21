#!/usr/bin/env python3
"""Generate a PAD policy from approved model metadata and score-only evidence.

Usage:
  python3 tools/calibrate_pad.py approval-manifest.json pad-scores.json output.json [live_threshold] [spoof_threshold] [target_p95_ms] [client_planar_z_threshold]

The score input is JSON or JSONL. It must contain only label, score,
latency_ms, fixture, venue, device, and lighting metadata; raw images and
embeddings are neither needed nor accepted.
"""

from __future__ import annotations

import json
import sys
from pathlib import Path

APP_DIR = Path(__file__).resolve().parents[1] / "app"
sys.path.insert(0, str(APP_DIR))

from calibration import PAD_PREPROCESSING_VERSION, approve_model, calibrate_pad_scores  # type: ignore
from safe_logging import SafeFaceLogger  # type: ignore


def main(argv):
    if len(argv) < 4:
        print(
            "usage: calibrate_pad.py approval-manifest.json pad-scores.json output.json "
            "[live_threshold] [spoof_threshold] [target_p95_ms] [client_planar_z_threshold]",
            file=sys.stderr,
        )
        return 2
    logger = SafeFaceLogger()
    try:
        manifest = json.loads(Path(argv[1]).read_text())
        raw_records = Path(argv[2]).read_text()
        try:
            records = json.loads(raw_records)
        except json.JSONDecodeError:
            records = [json.loads(line) for line in raw_records.splitlines() if line.strip()]
        if not isinstance(records, list):
            raise ValueError("PAD records must be a JSON array or JSONL records")
        live_threshold = float(argv[4]) if len(argv) > 4 else None
        spoof_threshold = float(argv[5]) if len(argv) > 5 else None
        target_p95 = int(argv[6]) if len(argv) > 6 else 100
        client_planar_z_threshold = float(argv[7]) if len(argv) > 7 else 0.10
        if not 0.0 < client_planar_z_threshold <= 1.0:
            raise ValueError("client_planar_z_threshold must be between 0 and 1")

        approval = approve_model(manifest)
        report = calibrate_pad_scores(
            records,
            target_p95_ms=target_p95,
            target_live_threshold=live_threshold,
            target_spoof_threshold=spoof_threshold,
        )
        output = {
            "status": "APPROVED",
            "license_reference": approval.license_reference,
            "approved_by": str(manifest.get("approved_by", "")),
            "approved_at": str(manifest.get("approved_at", "")),
            "policy_version": approval.policy_version,
            "files": approval.files,
            "live_threshold": round(report.live_threshold, 6),
            "spoof_threshold": round(report.spoof_threshold, 6),
            "client_planar_z_threshold": client_planar_z_threshold,
            "false_accepts": report.false_accepts,
            "attack_misses": report.attack_misses,
            "live_reviews": report.live_reviews,
            "p50_latency_ms": report.p50_latency_ms,
            "p95_latency_ms": report.p95_latency_ms,
            "sample_count": report.sample_count,
            "venue_count": report.venue_count,
            "device_count": report.device_count,
            "lighting_count": report.lighting_count,
            "fixtures_tested": report.fixtures_tested,
            "preprocessing_version": PAD_PREPROCESSING_VERSION,
            "revalidation_triggers": manifest.get("revalidation_triggers", [
                "pad_model_weights_updated",
                "detector_or_face_crop_modified",
                "camera_hardware_spec_changed",
                "material_venue_or_lighting_shift",
                "new_presentation_attack_observed",
            ]),
        }
        Path(argv[3]).write_text(json.dumps(output, indent=2, sort_keys=True) + "\n")
        logger.log("PAD_CALIBRATION_APPROVED", {
            "sample_count": report.sample_count,
            "p95_latency_ms": report.p95_latency_ms,
            "live_threshold": output["live_threshold"],
            "spoof_threshold": output["spoof_threshold"],
        })
        print(
            "PAD_CALIBRATION_APPROVED: "
            f"live={output['live_threshold']}, spoof={output['spoof_threshold']}, "
            f"p95={output['p95_latency_ms']}ms, attack_misses={output['attack_misses']}"
        )
        return 0
    except Exception as exc:
        reason = getattr(exc, "reason", "PAD_CALIBRATION_FAILED")
        logger.log("PAD_CALIBRATION_FAILED", {"reason": reason, "detail": str(exc)})
        print(f"PAD_CALIBRATION_FAILED: {reason} ({exc})", file=sys.stderr)
        return 1


if __name__ == "__main__":
    raise SystemExit(main(sys.argv))
