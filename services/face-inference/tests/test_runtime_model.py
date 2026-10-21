"""FACE-SETUP-01 model-load test using synthetic pixels only."""

from pathlib import Path
import sys

import numpy as np

ROOT = Path(__file__).resolve().parents[1]
sys.path.insert(0, str(ROOT))

from app.encoder import SFaceEncoder  # noqa: E402


MODEL = ROOT / "models" / "face_recognition_sface_2021dec_int8bq.onnx"


def main() -> int:
    encoder = SFaceEncoder(MODEL)
    image = np.zeros((112, 112, 3), dtype=np.uint8)
    face = np.array(
        [0, 0, 112, 112, 38, 52, 74, 52, 56, 72, 42, 92, 71, 92],
        dtype=np.float32,
    )
    feature = encoder.encode(image, face)
    assert feature.shape == (128,), feature.shape
    assert np.isfinite(feature).all()
    print("PASS runtime SFace model loads and returns a finite 128D feature")
    return 0


if __name__ == "__main__":
    raise SystemExit(main())
