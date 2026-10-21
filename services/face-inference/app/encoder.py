"""SFace encoder adapter."""

from __future__ import annotations

from pathlib import Path
from typing import Any


class EncoderUnavailable(RuntimeError):
    pass


class SFaceEncoder:
    def __init__(self, model_path: Path) -> None:
        try:
            import cv2  # type: ignore
            import numpy as np  # type: ignore
            import onnx  # type: ignore
            import onnxruntime as ort  # type: ignore
        except ImportError as exc:
            raise EncoderUnavailable("SFace runtime dependencies are not installed") from exc
        if not model_path.is_file():
            raise EncoderUnavailable("SFace model file is not available")
        self._cv2 = cv2
        self._np = np
        self._input_size = (112, 112)
        try:
            graph = onnx.load(str(model_path))
            options = ort.SessionOptions()
            options.log_severity_level = 3
            image_inputs = [item for item in graph.graph.input if item.name == "data"]
            if len(image_inputs) != 1:
                raise EncoderUnavailable("SFace ONNX image input is not available")
            # The INT8BQ export retains pre-quantization weight names as graph
            # inputs even though the quantized initializers are embedded in the
            # model. Remove those stale declarations in memory only; the
            # on-disk model and its checksum remain unchanged.
            del graph.graph.input[:]
            graph.graph.input.extend(image_inputs)
            self._session = ort.InferenceSession(
                graph.SerializeToString(),
                sess_options=options,
                providers=["CPUExecutionProvider"],
            )
        except Exception as exc:
            raise EncoderUnavailable("SFace ONNX model could not be loaded") from exc
        self._input_name = "data"
        self._output_name = self._session.get_outputs()[0].name

    def _align(self, image: Any, face: Any):
        landmarks = self._np.asarray(face[4:14], dtype=self._np.float32).reshape(5, 2)
        target = self._np.asarray(
            [
                [38.2946, 51.6963],
                [73.5318, 51.5014],
                [56.0252, 71.7366],
                [41.5493, 92.3655],
                [70.7299, 92.2041],
            ],
            dtype=self._np.float64,
        )
        source = landmarks.astype(self._np.float64)
        source_mean = source.mean(axis=0)
        target_mean = target.mean(axis=0)
        source_centered = source - source_mean
        target_centered = target - target_mean
        source_variance = float((source_centered * source_centered).sum() / source.shape[0])
        if not self._np.isfinite(source_variance) or source_variance <= 0.0:
            raise EncoderUnavailable("SFace face alignment failed")

        covariance = target_centered.T.dot(source_centered) / source.shape[0]
        try:
            left, singular, right_t = self._np.linalg.svd(covariance)
        except self._np.linalg.LinAlgError as exc:
            raise EncoderUnavailable("SFace face alignment failed") from exc
        direction = self._np.ones(2, dtype=self._np.float64)
        if self._np.linalg.det(covariance) < 0:
            direction[-1] = -1.0
        rotation = left.dot(self._np.diag(direction)).dot(right_t)
        scale = float(singular.dot(direction) / source_variance)
        translation = target_mean - scale * rotation.dot(source_mean)
        transform = self._np.concatenate((scale * rotation, translation.reshape(2, 1)), axis=1)
        if not self._np.isfinite(transform).all():
            raise EncoderUnavailable("SFace face alignment failed")
        return self._cv2.warpAffine(image, transform.astype(self._np.float32), self._input_size)

    def encode(self, image: Any, face: Any):
        aligned = self._align(image, face)
        rgb = self._cv2.cvtColor(aligned, self._cv2.COLOR_BGR2RGB).astype(self._np.float32)
        # OpenCV FaceRecognizerSF uses blobFromImage(scale=1, mean=0,
        # swapRB=true): SFace receives raw RGB float values in the 0..255
        # range. Mean/scale normalization creates an incompatible embedding
        # space and can collapse unrelated identities.
        blob = rgb.transpose(2, 0, 1)[self._np.newaxis, ...]
        feature = self._session.run([self._output_name], {self._input_name: blob})[0]
        vector = self._np.asarray(feature, dtype=self._np.float32).reshape(-1)
        norm = float(self._np.linalg.norm(vector))
        if not self._np.isfinite(vector).all() or not self._np.isfinite(norm) or norm <= 0.0:
            raise EncoderUnavailable("SFace produced an invalid embedding")
        return vector / norm

    def cosine(self, first: Any, second: Any) -> float:
        first = self._np.asarray(first, dtype=self._np.float32)
        second = self._np.asarray(second, dtype=self._np.float32)
        numerator = float((first * second).sum())
        denominator = float(self._np.linalg.norm(first) * self._np.linalg.norm(second))
        return numerator / denominator if denominator else -1.0
