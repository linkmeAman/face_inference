"""YuNet detector adapter."""

from __future__ import annotations

from pathlib import Path
from typing import Any


class DetectorUnavailable(RuntimeError):
    pass


class YuNetDetector:
    def __init__(self, model_path: Path) -> None:
        try:
            import cv2  # type: ignore
        except ImportError as exc:
            raise DetectorUnavailable("OpenCV is not installed") from exc
        if not model_path.is_file():
            raise DetectorUnavailable("YuNet model file is not available")
        if not hasattr(cv2, "FaceDetectorYN_create"):
            raise DetectorUnavailable("OpenCV YuNet support is not available")
        self._cv2 = cv2
        self._detector = cv2.FaceDetectorYN_create(
            str(model_path), "", (320, 320), 0.85, 0.3, 5000
        )

    def detect(self, image: Any):
        self._detector.setInputSize((image.shape[1], image.shape[0]))
        _, faces = self._detector.detect(image)
        return [] if faces is None else faces
