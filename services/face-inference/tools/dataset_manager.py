#!/usr/bin/env python3
"""Dataset manager for face calibration and validation.

Validates multi-venue field score records, ensures privacy rules (no raw frames or PII),
and deterministically partitions records into development and held-out evaluation sets.
"""

from __future__ import annotations

import json
import sys
from pathlib import Path
from typing import Any, Dict, List, Mapping, Tuple

FORBIDDEN_KEYS = {"frame", "image", "raw_image", "jpeg", "png", "base64", "embedding", "name", "fullname"}


def validate_record(record: Mapping[str, Any], idx: int) -> Dict[str, Any]:
    for key in record.keys():
        if key.lower() in FORBIDDEN_KEYS:
            raise ValueError(f"Record {idx} contains forbidden privacy key '{key}'")

    label = str(record.get("label", "")).lower()
    if label not in {"genuine", "impostor"}:
        raise ValueError(f"Record {idx}: label must be 'genuine' or 'impostor', got '{label}'")

    try:
        score = float(record["score"])
        margin = float(record["margin"])
        latency_ms = int(record["latency_ms"])
    except (KeyError, TypeError, ValueError) as exc:
        raise ValueError(f"Record {idx}: score, margin, and latency_ms are required and must be numeric") from exc

    if not (0.0 <= score <= 1.0):
        raise ValueError(f"Record {idx}: score {score} must be between 0.0 and 1.0")
    if not (0.0 <= margin <= 1.0):
        raise ValueError(f"Record {idx}: margin {margin} must be between 0.0 and 1.0")
    if latency_ms < 0:
        raise ValueError(f"Record {idx}: latency_ms must be non-negative")

    venue = str(record.get("venue", "default"))
    device = str(record.get("device", "default"))
    lighting = str(record.get("lighting", "default"))
    condition = str(record.get("condition", "default"))

    return {
        "sample_id": str(record.get("sample_id", f"SMP-{idx:04d}")),
        "label": label,
        "score": score,
        "margin": margin,
        "latency_ms": latency_ms,
        "venue": venue,
        "device": device,
        "lighting": lighting,
        "condition": condition,
    }


def partition_dataset(records: List[Dict[str, Any]], dev_ratio: float = 0.5) -> Tuple[List[Dict[str, Any]], List[Dict[str, Any]]]:
    """Stratified deterministic split by label into development and held-out sets."""
    genuine = [r for r in records if r["label"] == "genuine"]
    impostor = [r for r in records if r["label"] == "impostor"]

    dev_records: List[Dict[str, Any]] = []
    held_out_records: List[Dict[str, Any]] = []

    for group in (genuine, impostor):
        split_idx = max(1, int(round(len(group) * dev_ratio)))
        dev_records.extend(group[:split_idx])
        held_out_records.extend(group[split_idx:])

    return dev_records, held_out_records


def main(argv: List[str]) -> int:
    if len(argv) < 2:
        print("Usage: dataset_manager.py validate <input.json> | partition <input.json> <dev_out.json> <held_out.json>", file=sys.stderr)
        return 2

    action = argv[1]
    input_path = Path(argv[2])

    if not input_path.is_file():
        print(f"Error: input file {input_path} not found", file=sys.stderr)
        return 1

    try:
        raw = json.loads(input_path.read_text())
        if not isinstance(raw, list):
            print("Error: dataset must be a JSON array of records", file=sys.stderr)
            return 1

        validated = [validate_record(r, i) for i, r in enumerate(raw)]
        print(f"Validated {len(validated)} records successfully ({sum(1 for r in validated if r['label'] == 'genuine')} genuine, {sum(1 for r in validated if r['label'] == 'impostor')} impostor).")

        if action == "validate":
            return 0

        if action == "partition":
            if len(argv) != 5:
                print("Usage: dataset_manager.py partition <input.json> <dev_out.json> <held_out.json>", file=sys.stderr)
                return 2

            dev_out = Path(argv[3])
            held_out = Path(argv[4])

            dev_records, held_out_records = partition_dataset(validated)
            dev_out.write_text(json.dumps(dev_records, indent=2) + "\n")
            held_out.write_text(json.dumps(held_out_records, indent=2) + "\n")
            print(f"Partitioned into development set ({len(dev_records)} records -> {dev_out}) and held-out evaluation set ({len(held_out_records)} records -> {held_out}).")
            return 0

        print(f"Unknown action: {action}", file=sys.stderr)
        return 2
    except Exception as e:
        print(f"Dataset validation failed: {e}", file=sys.stderr)
        return 1


if __name__ == "__main__":
    raise SystemExit(main(sys.argv))
