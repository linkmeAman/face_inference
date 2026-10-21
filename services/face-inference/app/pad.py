"""Passive presentation-attack detection using the approved ONNX adapter."""

from __future__ import annotations

import math
import statistics
from pathlib import Path
from typing import Any, Optional, Sequence


class PadUnavailable(RuntimeError):
    pass


class PassivePadClassifier:
    """Intel Open Model Zoo anti-spoof-mn3 inference adapter.

    The classifier only emits a policy state when approved thresholds are
    supplied separately. Model output alone is evidence, not authorization for
    automatic attendance.
    """

    def __init__(self, model_path: Path) -> None:
        try:
            import cv2  # type: ignore
            import numpy as np  # type: ignore
            import onnxruntime as ort  # type: ignore
        except ImportError as exc:
            raise PadUnavailable("PAD runtime dependencies are not installed") from exc
        if not model_path.is_file():
            raise PadUnavailable("PAD model file is not available")
        self.cv2 = cv2
        self.np = np
        try:
            options = ort.SessionOptions()
            options.log_severity_level = 3
            options.intra_op_num_threads = 1
            options.inter_op_num_threads = 1
            self.session = ort.InferenceSession(
                str(model_path), sess_options=options, providers=["CPUExecutionProvider"]
            )
        except Exception as exc:
            raise PadUnavailable("PAD ONNX model could not be loaded") from exc
        inputs = self.session.get_inputs()
        outputs = self.session.get_outputs()
        if len(inputs) != 1 or inputs[0].shape != [1, 3, 128, 128] or len(outputs) != 1:
            raise PadUnavailable("PAD ONNX model contract is invalid")
        self.input_name = inputs[0].name
        self.output_name = outputs[0].name
        self.mean = self.np.asarray([151.2405, 119.5950, 107.8395], dtype=self.np.float32)
        self.scale = self.np.asarray([63.0105, 56.4570, 55.0035], dtype=self.np.float32)

    PREPROCESSING_VERSION = "anti-spoof-mn3-square-1.5-v1"

    def _square_crop(self, image: Any, face: Any):
        image_height, image_width = image.shape[:2]
        x, y, width, height = (float(value) for value in face[:4])
        side = max(1, int(round(max(width, height) * 1.5)))
        centre_x = x + width / 2.0
        centre_y = y + height / 2.0
        x1 = int(round(centre_x - side / 2.0))
        y1 = int(round(centre_y - side / 2.0))
        x2 = x1 + side
        y2 = y1 + side
        pad_left = max(0, -x1)
        pad_top = max(0, -y1)
        pad_right = max(0, x2 - image_width)
        pad_bottom = max(0, y2 - image_height)
        sx1, sy1 = max(0, x1), max(0, y1)
        sx2, sy2 = min(image_width, x2), min(image_height, y2)
        crop = image[sy1:sy2, sx1:sx2]
        if crop.size == 0:
            raise PadUnavailable("PAD face crop is empty")
        if pad_left or pad_top or pad_right or pad_bottom:
            crop = self.cv2.copyMakeBorder(
                crop, pad_top, pad_bottom, pad_left, pad_right, self.cv2.BORDER_REPLICATE
            )
        return crop

    def _preprocess(self, image: Any, face: Any):
        crop = self._square_crop(image, face)
        resized = self.cv2.resize(crop, (128, 128), interpolation=self.cv2.INTER_AREA)
        rgb = self.cv2.cvtColor(resized, self.cv2.COLOR_BGR2RGB).astype(self.np.float32)
        return ((rgb - self.mean) / self.scale).transpose(2, 0, 1)[self.np.newaxis, ...]

    def real_probability(self, image: Any, face: Any, padding: float = 0.10) -> float:
        # ``padding`` is retained for provider compatibility. The model contract
        # is fixed to a centered square crop expanded by bbox_inc=1.5.
        blob = self._preprocess(image, face)
        output = self.session.run([self.output_name], {self.input_name: blob})[0]
        values = self.np.asarray(output, dtype=self.np.float32).reshape(-1)
        if values.size != 2 or not self.np.isfinite(values).all():
            raise PadUnavailable("PAD model output is invalid")
        score = float(values[0])
        if not math.isfinite(score) or score < 0.0 or score > 1.0:
            raise PadUnavailable("PAD real probability is invalid")
        return score

    @staticmethod
    def classify(
        scores: Sequence[float],
        live_threshold: Optional[float],
        spoof_threshold: Optional[float],
    ) -> str:
        if live_threshold is None or spoof_threshold is None or len(scores) < 3:
            return "UNKNOWN"
        clean = [float(score) for score in scores if math.isfinite(float(score))]
        if len(clean) != len(scores) or not clean:
            return "UNKNOWN"
        # A camera burst is a temporal consensus. A single compressed, blurred,
        # or partially cropped frame must not label a live person as a spoof.
        # Requiring the median to cross either boundary keeps the decision
        # fail-closed while ensuring at least two of three frames agree.
        consensus = statistics.median(clean)
        if consensus <= spoof_threshold:
            return "SPOOF"
        if consensus >= live_threshold:
            return "LIVE"
        return "CHALLENGE"


def head_turn_passed(
    challenge_type: str,
    baseline_yaw: float,
    observed_yaws: Sequence[float],
    minimum_delta: float,
) -> bool:
    if challenge_type not in {"LOOK_LEFT", "LOOK_RIGHT"} or len(observed_yaws) < 3:
        return False
    values = [float(value) for value in observed_yaws if math.isfinite(float(value))]
    if len(values) != len(observed_yaws):
        return False
    delta = statistics.median(values) - float(baseline_yaw)
    if challenge_type == "LOOK_LEFT":
        return delta <= -abs(float(minimum_delta))
    return delta >= abs(float(minimum_delta))


__all__ = ["PadUnavailable", "PassivePadClassifier", "head_turn_passed"]
