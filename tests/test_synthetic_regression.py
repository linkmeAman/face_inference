import hashlib
import importlib.util
import json
from pathlib import Path

import pytest

spec = importlib.util.spec_from_file_location(
    "synthetic", Path(__file__).parents[1] / "tools/evaluate_synthetic.py"
)
synthetic = importlib.util.module_from_spec(spec)
spec.loader.exec_module(synthetic)


def digest(data):
    return hashlib.sha256(data).hexdigest()


@pytest.fixture
def evidence(tmp_path, monkeypatch):
    images = []
    for identity in ("a", "b", "c"):
        vector = [0.0] * 128
        vector[ord(identity) - ord("a")] = 1.0
        for view in range(2):
            name = f"{identity}{view}.png"
            data = name.encode()
            (tmp_path / name).write_bytes(data)
            images.append(
                dict(
                    identity=identity,
                    partition="heldout",
                    view=view,
                    file=name,
                    sha256=digest(data),
                    bytes=len(data),
                    embedding=vector,
                )
            )
    manifest = {"images": [{k: v for k, v in row.items() if k != "embedding"} for row in images]}
    inference = {"images": images}
    counts = dict(
        known=2,
        unknown=2,
        correct=2,
        false_positive=0,
        false_negative=0,
        unknown_accepted=0,
        ambiguous=0,
        duplicate_trials=2,
        duplicate_missed=0,
        distinct_trials=2,
        false_duplicate=0,
        wrong_duplicate_identity=0,
    )
    baseline = {
        "exploratory_public_operating_points": {
            "DUPLICATE_THRESHOLD": 0.5,
            "ATTENDANCE_THRESHOLD": 0.5,
        },
        "decisions": {
            "heldout": {name: {"counts": counts} for name in ("duplicate", "attendance")}
        },
    }
    pins = {}
    for name, value in [
        ("manifest.json", manifest),
        ("inference.json", inference),
        ("baseline.json", baseline),
    ]:
        data = json.dumps(value).encode()
        (tmp_path / name).write_bytes(data)
        pins[name] = digest(data)
    monkeypatch.setattr(synthetic, "PINS", pins)
    return tmp_path


def test_known_result_and_repeat(evidence):
    first = synthetic.check(
        evidence,
        evidence / "manifest.json",
        evidence / "inference.json",
        evidence / "baseline.json",
    )
    assert first["attendance"]["correct"] == 2
    assert first == synthetic.check(
        evidence,
        evidence / "manifest.json",
        evidence / "inference.json",
        evidence / "baseline.json",
    )


@pytest.mark.parametrize(
    "failure", ["changed_image", "missing_image", "corrupt_input", "changed_result"]
)
def test_invalid_evidence_fails(evidence, failure, monkeypatch):
    if failure == "changed_image":
        (evidence / "a0.png").write_bytes(b"changed")
    elif failure == "missing_image":
        (evidence / "a0.png").unlink()
    elif failure == "corrupt_input":
        (evidence / "inference.json").write_text("{")
        monkeypatch.setitem(synthetic.PINS, "inference.json", digest(b"{"))
    else:
        path = evidence / "baseline.json"
        data = json.loads(path.read_text())
        data["decisions"]["heldout"]["attendance"]["counts"]["correct"] = 0
        path.write_text(json.dumps(data))
        monkeypatch.setitem(synthetic.PINS, "baseline.json", digest(path.read_bytes()))
    with pytest.raises((ValueError, OSError)):
        synthetic.check(
            evidence,
            evidence / "manifest.json",
            evidence / "inference.json",
            evidence / "baseline.json",
        )
