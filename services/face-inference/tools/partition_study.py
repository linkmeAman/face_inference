#!/usr/bin/env python3
"""Assign whole children to disjoint study partitions before policy tuning.

Cross-partition gallery comparisons are discarded; generate final comparisons
inside each partition after assignment. The held-out file must stay untouched.
"""
import hashlib
import json
import sys
from pathlib import Path


def partition(records):
    people = sorted({r["subject_id"] for r in records})
    if len(people) < 3:
        raise ValueError("at least three subjects are needed for disjoint partitions")
    assignments = {subject: assign_subject(subject) for subject in people}
    result = {name: [] for name in ("tuning", "calibration", "held_out")}
    excluded = 0
    for record in records:
        bucket = assignments[record["subject_id"]]
        gallery = record.get("gallery_subject_id")
        if gallery is not None and assignments.get(gallery) != bucket:
            excluded += 1
            continue
        result[bucket].append(record)
    return result, excluded


def assign_subject(subject):
    """Stable pre-collection assignment, independent of later participants."""
    bucket = int(hashlib.sha256(("ccc-face-study-v1:" + subject).encode()).hexdigest()[:8], 16) % 100
    return "tuning" if bucket < 40 else "calibration" if bucket < 70 else "held_out"


def main(argv):
    if len(argv) != 3:
        print("usage: partition_study.py score_records.json output_directory", file=sys.stderr)
        return 2
    records = json.loads(Path(argv[1]).read_text())
    if not isinstance(records, list):
        raise ValueError("input must be a JSON array")
    partitions, excluded = partition(records)
    output = Path(argv[2])
    output.mkdir(parents=True, exist_ok=True)
    for name, rows in partitions.items():
        path = output / (name + ".json")
        if path.exists():
            raise FileExistsError("refusing to overwrite an existing partition")
        path.write_text(json.dumps(rows, separators=(",", ":")))
        path.chmod(0o600)
    print(json.dumps({"counts": {name: len(rows) for name, rows in partitions.items()},
                      "excluded_cross_partition_comparisons": excluded}))
    return 0


if __name__ == "__main__":
    raise SystemExit(main(sys.argv))
