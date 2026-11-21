"""Recheck the recorded synthetic held-out identification experiment offline."""

import argparse
import hashlib
import json
import math
import sys
from collections import defaultdict
from pathlib import Path

ROOT = Path(__file__).resolve().parents[1]
sys.path.insert(0, str(ROOT / "src"))
from face_inference.matching import cosine  # noqa: E402

PINS = {
    "manifest.json": "4aa0f8e1cf4993dfc73adf353e92d409bc4d84c2578ee8ca056b77e1643ca353",
    "ccc-controlface-inference-20261009.json": (
        "26b3f973a8fc56b1780a64ba32ac0b8db2f6e219eb048ad12ff7b188f67ec14b"
    ),
    "ccc-controlface-evaluation-20261009.json": (
        "403778eb41b503c1d7aa4643f0f7fd243e27cd4f7afe3985686fb4d248895c18"
    ),
}


def load(path):
    raw = path.read_bytes()
    if hashlib.sha256(raw).hexdigest() != PINS[path.name]:
        raise ValueError(f"Evidence hash mismatch: {path}")
    return json.loads(raw)


def check(directory, manifest_path, inference_path, baseline_path):
    manifest, inference, baseline = map(load, (manifest_path, inference_path, baseline_path))
    rows = inference["images"]
    images = manifest["images"]
    if not rows or len(rows) != len(images):
        raise ValueError("Image inventory mismatch")
    grouped = defaultdict(list)
    hashes, identities = set(), {}
    for original, row in zip(images, rows, strict=True):
        if any(row.get(key) != value for key, value in original.items()):
            raise ValueError("Manifest and inference disagree")
        name = row["file"]
        if Path(name).name != name or row["sha256"] in hashes:
            raise ValueError("Invalid or repeated image")
        hashes.add(row["sha256"])
        data = (directory / name).read_bytes()
        if len(data) != row["bytes"] or hashlib.sha256(data).hexdigest() != row["sha256"]:
            raise ValueError(f"Image hash mismatch: {name}")
        identity, partition = row["identity"], row["partition"]
        if (
            partition not in ("development", "heldout")
            or identities.setdefault(identity, partition) != partition
        ):
            raise ValueError("Invalid partition or identity leakage")
        embedding = row["embedding"]
        if embedding is None:
            continue
        if (
            not isinstance(embedding, list)
            or len(embedding) != 128
            or any(
                isinstance(x, bool) or not isinstance(x, (int, float)) or not math.isfinite(x)
                for x in embedding
            )
            or abs(math.sqrt(sum(x * x for x in embedding)) - 1) > 1e-6
        ):
            raise ValueError("Invalid normalized embedding")
        if partition == "heldout":
            grouped[identity].append(row)
    labels = sorted(grouped, key=lambda label: hashlib.sha256(label.encode()).hexdigest())
    gallery = {
        label: min(grouped[label], key=lambda row: row["view"])
        for label in labels[: len(labels) * 2 // 3]
    }
    if len(gallery) < 2 or len(labels) == len(gallery):
        raise ValueError("Insufficient held-out identities")
    cases = []
    for identity, group in sorted(grouped.items()):
        for row in sorted(group, key=lambda item: item["view"]):
            if identity in gallery and row["sha256"] == gallery[identity]["sha256"]:
                continue
            cases.append(
                (
                    identity,
                    {
                        key: cosine(row["embedding"], ref["embedding"])
                        for key, ref in gallery.items()
                    },
                )
            )
    results = {}
    for name in ("duplicate", "attendance"):
        threshold = baseline["exploratory_public_operating_points"][name.upper() + "_THRESHOLD"]
        counts = dict(
            known=0,
            unknown=0,
            correct=0,
            false_positive=0,
            false_negative=0,
            unknown_accepted=0,
            ambiguous=0,
            duplicate_trials=0,
            duplicate_missed=0,
            distinct_trials=0,
            false_duplicate=0,
            wrong_duplicate_identity=0,
        )
        for identity, scores in cases:
            accepted = [key for key, score in scores.items() if score >= threshold]
            winner = accepted[0] if len(accepted) == 1 else None
            counts["ambiguous"] += len(accepted) > 1
            if identity in gallery:
                counts["known"] += 1
                counts["duplicate_trials"] += 1
                counts["correct"] += winner == identity
                counts["false_positive"] += winner is not None and winner != identity
                counts["false_negative"] += winner is None
                counts["duplicate_missed"] += max(scores.values()) < threshold
                counts["wrong_duplicate_identity"] += (
                    max(scores.values()) >= threshold and max(scores, key=scores.get) != identity
                )
            else:
                counts["unknown"] += 1
                counts["distinct_trials"] += 1
                counts["unknown_accepted"] += winner is not None
                counts["false_duplicate"] += max(scores.values()) >= threshold
        if counts != baseline["decisions"]["heldout"][name]["counts"]:
            raise ValueError(f"Held-out {name} result differs from baseline")
        results[name] = counts
    return results


def main():
    parser = argparse.ArgumentParser(description=__doc__)
    parser.add_argument("--check", action="store_true", required=True)
    parser.parse_args()
    dataset = ROOT / "datasets/controlface10k-subset"
    reports = ROOT / "datasets/reports"
    try:
        results = check(
            dataset,
            dataset / "manifest.json",
            reports / "ccc-controlface-inference-20261009.json",
            reports / "ccc-controlface-evaluation-20261009.json",
        )
    except (OSError, ValueError, KeyError, TypeError) as error:
        print(f"Synthetic regression check failed: {error}", file=sys.stderr)
        return 1
    print(json.dumps(results, sort_keys=True))
    return 0


if __name__ == "__main__":
    raise SystemExit(main())
