#!/usr/bin/env python3
"""Strict, score-only release report. Never tune policy on the held-out input.

Inputs are JSON arrays for tuning, calibration, and untouched held-out sets.
Each record has opaque subject_id/session_id, kind (genuine, impostor, pad),
device, venue, lighting, latency_ms, model_generation, and score data.
"""
from __future__ import annotations

import json
import math
import random
import sys
import hashlib
from collections import Counter, defaultdict
from pathlib import Path

PAD_CLASSES = frozenset({"PRINT_PHOTO", "PHONE_SCREEN", "TABLET_SCREEN", "VIDEO_REPLAY",
                         "MASK", "SYNTHETIC_INJECTION", "REPEATED_FRAMES", "API_REPLAY"})
FORBIDDEN = {"frame", "image", "jpeg", "png", "base64", "embedding", "name", "contact_id", "member_id"}


def exact_upper(errors: int, trials: int, alpha: float = .05) -> float:
    """One-sided Clopper-Pearson upper bound; zero errors is 1-alpha**(1/n)."""
    if trials <= 0:
        raise ValueError("trials must be positive")
    if errors == trials:
        return 1.0
    if errors == 0:
        return 1 - alpha ** (1 / trials)
    lo, hi = 0.0, 1.0
    for _ in range(70):
        mid = (lo + hi) / 2
        cdf = sum(math.comb(trials, k) * mid ** k * (1 - mid) ** (trials - k)
                  for k in range(errors + 1))
        if cdf > alpha:
            lo = mid
        else:
            hi = mid
    return (lo + hi) / 2


def p95(values):
    ordered = sorted(values)
    return ordered[math.ceil(.95 * len(ordered)) - 1]


def validate(partitions):
    people, sessions = {}, {}
    generation = set()
    policies = set()
    observations = set()
    for partition, records in partitions.items():
        if not isinstance(records, list):
            raise ValueError("each partition must be an array")
        for record in records:
            if not isinstance(record, dict) or any(k.lower() in FORBIDDEN for k in record):
                raise ValueError("raw images, embeddings, or identifiers are forbidden")
            subject = record.get("subject_id")
            session = record.get("session_id")
            if not isinstance(subject, str) or len(subject) < 16 or not isinstance(session, str) or len(session) < 16:
                raise ValueError("opaque subject_id and session_id are required")
            for key, value, index in (("subject", subject, people), ("session", session, sessions)):
                if value in index and index[value] != partition:
                    raise ValueError(f"{key} leaked across partitions")
                index[value] = partition
            gallery = record.get("gallery_subject_id")
            if gallery is not None and gallery != subject and gallery in people and people[gallery] != partition:
                raise ValueError("gallery identity leaked across partitions")
            kind = record.get("kind")
            if kind not in {"genuine", "impostor", "pad"}:
                raise ValueError("invalid kind")
            observation = (subject, session, kind, record.get("gallery_subject_id") if kind == "impostor" else record.get("attack_class") if kind == "pad" else None)
            if observation in observations:
                raise ValueError("duplicate study observation")
            observations.add(observation)
            for key in ("device", "venue", "lighting", "model_generation"):
                if not isinstance(record.get(key), str) or not record[key]:
                    raise ValueError(f"{key} is required")
            generation.add(record["model_generation"])
            policy = record.get("policy_sha256")
            if not isinstance(policy, str) or len(policy) != 64 or any(c not in "0123456789abcdef" for c in policy):
                raise ValueError("frozen policy_sha256 is required")
            policies.add(policy)
            if not isinstance(record.get("latency_ms"), (int, float)) or record["latency_ms"] < 0:
                raise ValueError("latency_ms is required")
            for key in ("camera_latency_ms", "memory_mb"):
                if not isinstance(record.get(key), (int, float)) or record[key] < 0:
                    raise ValueError(f"{key} is required")
            if kind == "pad":
                if record.get("attack_class") not in PAD_CLASSES or record.get("pad_missed") not in (True, False):
                    raise ValueError("PAD class and result are required")
            else:
                if record.get("accepted") not in (True, False):
                    raise ValueError("acceptance result is required")
                if record.get("gallery_kind") not in {"study", "class"}:
                    raise ValueError("gallery kind is required")
                if kind == "genuine" and record.get("within_retry") not in (True, False):
                    raise ValueError("guided retry result is required")
                if kind == "genuine" and record.get("comparison_complete") not in (True, False):
                    raise ValueError("comparison completeness is required")
                if kind == "impostor" and gallery is None:
                    raise ValueError("impostor gallery identity is required")
                if record["accepted"] and not isinstance(record.get("selected_subject_id"), str):
                    raise ValueError("accepted result requires selected_subject_id")
                if not isinstance(record.get("gallery_size"), int) or record["gallery_size"] < 1:
                    raise ValueError("gallery_size is required")
                if not isinstance(record.get("gallery_session_id"), str) or len(record["gallery_session_id"]) < 16 or record["gallery_session_id"] == session:
                    raise ValueError("enrollment gallery and probe must use separate sessions")
    if len(generation) != 1:
        raise ValueError("partitions must use one frozen model generation")
    if len(policies) != 1:
        raise ValueError("policy changed across partitions")
    for record in sum(partitions.values(), []):
        gallery = record.get("gallery_subject_id")
        if gallery and gallery not in people:
            raise ValueError("gallery identity must be in the study")
        if gallery and people[gallery] != people[record["subject_id"]]:
            raise ValueError("gallery identity leaked across partitions")
    comparisons = Counter((r["subject_id"], r["session_id"]) for rows in partitions.values() for r in rows if r["kind"] == "impostor")
    for record in sum(partitions.values(), []):
        if record["kind"] == "genuine" and record["comparison_complete"]:
            if comparisons[(record["subject_id"], record["session_id"])] != record["gallery_size"] - 1:
                raise ValueError("gallery comparison count does not match its size")


def cluster_bootstrap(records, field, iterations=2000, seed=92026):
    clusters = defaultdict(list)
    for record in records:
        clusters[record["subject_id"]].append(record)
    subjects = list(clusters)
    rng = random.Random(seed)
    estimates = []
    for _ in range(iterations):
        sample = [r for _ in subjects for r in clusters[rng.choice(subjects)]]
        estimates.append(p95([r[field] for r in sample]) if field == "latency_ms"
                         else sum(bool(r[field]) for r in sample) / len(sample))
    estimates.sort()
    return [estimates[int(.025 * iterations)], estimates[int(.975 * iterations) - 1]]


def evaluate(partitions, required_devices, expected_policy_sha256=None):
    validate(partitions)
    if expected_policy_sha256 is not None and any(r["policy_sha256"] != expected_policy_sha256 for rows in partitions.values() for r in rows):
        raise ValueError("score records do not match the frozen policy artifact")
    if len(set(required_devices)) < 3 or any(not device for device in required_devices):
        raise ValueError("List every production device class (at least three) explicitly")
    rows = partitions["held_out"]
    genuine = [r for r in rows if r["kind"] == "genuine"]
    impostor = [r for r in rows if r["kind"] == "impostor"]
    pad = [r for r in rows if r["kind"] == "pad"]
    subjects = {r["subject_id"] for r in genuine}
    sessions = defaultdict(set)
    for r in genuine:
        sessions[r["subject_id"]].add(r["session_id"])
    counts = Counter(r["attack_class"] for r in pad)
    device_success = {d: sum(bool(r["within_retry"]) for r in genuine if r["device"] == d) /
                      max(1, sum(r["device"] == d for r in genuine)) for d in required_devices}
    false_accepts = sum(bool(r["accepted"]) for r in impostor)
    genuine_wrong = sum(bool(r["accepted"]) and r.get("selected_subject_id") != r["subject_id"] for r in genuine)
    wrong = false_accepts + genuine_wrong
    misses = sum(bool(r["pad_missed"]) for r in pad)
    subject_wrong = sum(any(r["accepted"] for r in impostor if r["subject_id"] == s) or
                        any(r["accepted"] and r.get("selected_subject_id") != s for r in genuine if r["subject_id"] == s)
                        for s in subjects)
    # Cross-identity comparisons can repeat one camera attempt 49 times.
    # Count physical scan attempts once for latency and memory summaries.
    latency_rows = genuine + pad
    latencies = [r["latency_ms"] for r in latency_rows]
    if not genuine or not impostor or not pad:
        return {"passes": False, "coverage_passes": False, "reason": "All genuine, impostor, and PAD samples are required"}
    coverage = len(subjects) >= 50 and all(len(sessions[s]) >= 20 for s in subjects) and len(genuine) >= 1000 \
        and len(impostor) >= 49000 and all(counts[c] >= 300 for c in PAD_CLASSES) \
        and len({r["venue"] for r in rows}) >= 2 and len({r["lighting"] for r in rows}) >= 4 \
        and set(required_devices) <= {r["device"] for r in rows} \
        and all(r.get("gallery_size", 0) >= 15 for r in genuine) \
        and sum(r.get("gallery_kind") == "class" for r in genuine) >= 50 \
        and all(sum(r.get("gallery_kind") == "class" and r["device"] == d for r in genuine) >= 10 for d in required_devices)
    passes = coverage and wrong == 0 and misses == 0 and all(v >= .95 for v in device_success.values()) and p95(latencies) <= 100
    return {"passes": passes, "release_authorized": False,
            "data_provenance": "unverified score records; signed real-device evidence must be reviewed separately",
            "coverage_passes": coverage, "subjects": len(subjects),
            "genuine_attempts": len(genuine), "cross_identity_comparisons": len(impostor),
            "wrong_person_accepts": wrong, "pad_misses": misses, "pad_class_counts": dict(counts),
            "device_guided_retry_success": device_success, "p95_inference_latency_ms": p95(latencies),
            "p95_camera_flow_latency_ms": p95([r["camera_latency_ms"] for r in latency_rows]),
            "peak_memory_mb": max(r["memory_mb"] for r in latency_rows),
            "comparison_error_upper_95": exact_upper(false_accepts, len(impostor)),
            "subject_error_upper_95": exact_upper(subject_wrong, len(subjects)),
            "guided_retry_success_bootstrap_95": cluster_bootstrap(genuine, "within_retry"),
            "latency_p95_bootstrap_95": cluster_bootstrap(latency_rows, "latency_ms"),
            "camera_flow_p95_bootstrap_95": cluster_bootstrap(latency_rows, "camera_latency_ms"),
            "caveat": "Comparison bound assumes independent trials; repeated comparisons are clustered. Bootstrap with zero errors cannot estimate unseen false accepts."}


def main(argv):
    if len(argv) == 5 and argv[1] == "--combined" and argv[2] == "-":
        from partition_study import partition
        try:
            partitions, excluded = partition(json.load(sys.stdin))
            policy_hash = hashlib.sha256(Path(argv[4]).read_bytes()).hexdigest()
            result = evaluate(partitions, argv[3].split(","), policy_hash)
            result["excluded_cross_partition_comparisons"] = excluded
        except ValueError as exc:
            print(f"INVALID: {exc}", file=sys.stderr)
            return 2
        print(json.dumps(result, indent=2, sort_keys=True))
        return 0 if result["passes"] else 1
    if len(argv) < 6:
        print("usage: release_evaluation.py tuning.json calibration.json held_out.json device1,device2,device3 frozen-policy.json", file=sys.stderr)
        return 2
    partitions = {name: json.loads(Path(path).read_text()) for name, path in zip(("tuning", "calibration", "held_out"), argv[1:4])}
    try:
        policy_hash = hashlib.sha256(Path(argv[5]).read_bytes()).hexdigest()
        result = evaluate(partitions, argv[4].split(","), policy_hash)
    except ValueError as exc:
        print(f"INVALID: {exc}", file=sys.stderr)
        return 2
    print(json.dumps(result, indent=2, sort_keys=True))
    return 0 if result["passes"] else 1


if __name__ == "__main__":
    raise SystemExit(main(sys.argv))
