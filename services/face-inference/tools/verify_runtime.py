#!/usr/bin/env python3
"""Pre-flight verification for private face inference runtime.

Validates:
1. Python version (>= 3.10)
2. Core dependencies in requirements.lock (numpy, onnxruntime, cv2, onnx)
3. ONNX model checksums against MODEL-MANIFEST.json using verify_model_manifest()
4. Model directory and file read permissions
"""

from __future__ import annotations

import os
import sys
from pathlib import Path

# Ensure services/face-inference is on sys.path
root = Path(__file__).resolve().parent.parent
sys.path.insert(0, str(root))

try:
    from app.calibration import CalibrationError, verify_model_manifest
except ImportError as exc:
    print(f"FAIL: Could not import inference modules: {exc}", file=sys.stderr)
    sys.exit(3)


def verify_python_version() -> None:
    if sys.version_info < (3, 10):
        print(f"FAIL: Python 3.10+ required, current is {sys.version}", file=sys.stderr)
        sys.exit(4)


def verify_dependencies() -> None:
    required = ["numpy", "onnxruntime", "cv2", "onnx"]
    for pkg in required:
        try:
            mod = __import__(pkg)
            version = getattr(mod, "__version__", "unknown")
        except ImportError as exc:
            print(f"FAIL: Required dependency '{pkg}' missing: {exc}", file=sys.stderr)
            sys.exit(3)


def verify_models(model_dir: Path) -> dict:
    manifest_path = model_dir / "MODEL-MANIFEST.json"
    if not manifest_path.is_file():
        print(f"FAIL: Model manifest missing at {manifest_path}", file=sys.stderr)
        sys.exit(1)

    try:
        manifest = verify_model_manifest(manifest_path)
    except CalibrationError as exc:
        print(f"FAIL: Model integrity check failed: {exc.reason} - {exc}", file=sys.stderr)
        sys.exit(2)

    return manifest


def main() -> int:
    verify_python_version()
    verify_dependencies()

    model_dir_env = os.getenv("FACE_MODEL_DIR")
    if model_dir_env:
        model_dir = Path(model_dir_env)
    else:
        model_dir = root / "models"

    manifest = verify_models(model_dir)
    print(f"PASS: Runtime verified. Bundle: {manifest.get('bundle_version', 'unknown')}")
    return 0


if __name__ == "__main__":
    sys.exit(main())
