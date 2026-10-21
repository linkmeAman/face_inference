"""Environment-backed settings for the private inference service."""

from __future__ import annotations

import os
import hashlib
import json
from dataclasses import dataclass
from pathlib import Path
from typing import Optional

try:
    from .calibration import CalibrationError, SFACE_PREPROCESSING_VERSION, PAD_PREPROCESSING_VERSION, load_approved_calibration, load_approved_pad_calibration, verify_model_manifest
except ImportError:  # direct development imports
    from calibration import CalibrationError, SFACE_PREPROCESSING_VERSION, PAD_PREPROCESSING_VERSION, load_approved_calibration, load_approved_pad_calibration, verify_model_manifest  # type: ignore


def _int_env(name: str, default: int) -> int:
    value = os.getenv(name)
    if value is None or value == "":
        return default
    parsed = int(value)
    if parsed < 1:
        raise ValueError(f"{name} must be positive")
    return parsed


def _float_env(name: str) -> Optional[float]:
    value = os.getenv(name)
    if value is None or value == "":
        return None
    parsed = float(value)
    if not 0.0 <= parsed <= 1.0:
        raise ValueError(f"{name} must be between 0 and 1")
    return parsed


def _bool_env(name: str, default: bool = False) -> bool:
    value = os.getenv(name)
    if value is None or value == "":
        return default
    return value.strip().lower() in {"1", "true", "yes", "on"}


@dataclass(frozen=True)
class Settings:
    host: str = "127.0.0.1"
    port: int = 8765
    shared_secret: str = ""
    scan_ttl_seconds: int = 300
    max_frames: int = 3
    max_frame_bytes: int = 1_000_000
    max_enrollment_frames: int = 10
    max_candidates: int = 500
    max_embedding_dimension: int = 4096
    max_attempts_per_minute: int = 12
    replay_window_seconds: int = 600
    challenge_ttl_seconds: int = 15
    challenge_head_turn_delta: float = 0.12
    accept_threshold: Optional[float] = None
    enrollment_verify_threshold: Optional[float] = None
    enrollment_templates: int = 3
    min_margin: float = 0.20
    single_candidate_accept_threshold: Optional[float] = None
    template_support_threshold: float = 0.85
    min_template_support: int = 1
    verified_reference_threshold: float = 0.90
    enrollment_holdout_median_threshold: float = 0.90
    enrollment_holdout_min_threshold: float = 0.85
    cross_frame_consistency_threshold: float = 0.85
    duplicate_review_threshold: float = 0.75
    duplicate_block_threshold: float = 0.90
    policy_version: str = "face-policy-v3"
    model_version: str = "unconfigured"
    model_version_id: int = 0
    provider_id: str = "local-onnx"
    provider_version: str = "1"
    preprocessing_version: str = SFACE_PREPROCESSING_VERSION
    max_body_bytes: int = 15_000_000
    detector_model: Optional[Path] = None
    recognizer_model: Optional[Path] = None
    pad_model: Optional[Path] = None
    pad_live_threshold: Optional[float] = None
    pad_spoof_threshold: Optional[float] = None
    client_planar_z_threshold: float = 0.10
    pad_calibration_approved: bool = False
    pad_calibration_error: Optional[str] = "PAD_CALIBRATION_MISSING"
    calibration_approved: bool = False
    calibration_error: Optional[str] = None
    model_integrity_verified: bool = False
    model_error: Optional[str] = None
    model_manifest: Optional[dict] = None
    pilot_geometry_debug: bool = False
    identity_calibration_hash: str = ""
    pad_calibration_hash: str = ""
    experiment_logging_enabled: bool = False
    experiment_id_secret_file: str = ""
    experiment_opaque_id_version: int = 1

    @property
    def recognition_generation(self) -> str:
        components = self.model_manifest or {}
        payload = {
            "identity_model_hash": components.get("recognizer", {}).get("sha256", ""),
            "model_version": self.model_version,
            "model_version_id": self.model_version_id,
            "provider_version": self.provider_version,
            "identity_preprocessing_version": self.preprocessing_version,
            "identity_calibration_hash": self.identity_calibration_hash,
            "pad_model_hash": components.get("pad", {}).get("sha256", ""),
            "pad_preprocessing_version": PAD_PREPROCESSING_VERSION,
            "pad_calibration_hash": self.pad_calibration_hash,
            "policy_version": self.policy_version,
            "accept_threshold": self.accept_threshold,
            "single_candidate_accept_threshold": self.single_candidate_accept_threshold,
            "min_margin": self.min_margin,
            "min_template_support": self.min_template_support,
        }
        return hashlib.sha256(json.dumps(payload, sort_keys=True).encode("utf-8")).hexdigest()

    @classmethod
    def from_environment(cls) -> "Settings":
        model_dir = Path(os.getenv("FACE_MODEL_DIR", "services/face-inference/models"))
        detector = os.getenv("FACE_YUNET_MODEL")
        recognizer = os.getenv("FACE_SFACE_MODEL")
        pad = os.getenv("FACE_PAD_MODEL")
        calibration_file = os.getenv("FACE_CALIBRATION_FILE", "")
        pad_calibration_file = os.getenv("FACE_PAD_CALIBRATION_FILE", "")
        manifest_path = Path(os.getenv("FACE_MODEL_MANIFEST", str(model_dir / "MODEL-MANIFEST.json")))
        accept_threshold = None
        enrollment_verify_threshold = None
        min_margin = float(os.getenv("FACE_MIN_MARGIN", "0.20"))
        verified_reference_threshold = 0.90
        enrollment_holdout_median_threshold = 0.90
        enrollment_holdout_min_threshold = 0.85
        cross_frame_consistency_threshold = 0.85
        duplicate_review_threshold = 0.75
        duplicate_block_threshold = 0.90
        single_candidate_accept_threshold = None
        template_support_threshold = 0.85
        min_template_support = 1
        policy_version = "face-policy-v3"
        calibration_approved = False
        calibration_error = None
        pad_live_threshold = None
        pad_spoof_threshold = None
        client_planar_z_threshold = 0.10
        pad_calibration_approved = False
        pad_calibration_error = "PAD_CALIBRATION_MISSING"
        model_integrity_verified = False
        model_error = None
        model_manifest = None
        try:
            model_manifest = verify_model_manifest(manifest_path)
        except CalibrationError as exc:
            model_error = exc.reason
        if calibration_file:
            try:
                calibrated = load_approved_calibration(Path(calibration_file))
                accept_threshold = calibrated["accept_threshold"]
                min_margin = calibrated["min_margin"]
                enrollment_verify_threshold = calibrated["enrollment_verify_threshold"]
                verified_reference_threshold = calibrated.get("verified_reference_threshold", 0.90)
                enrollment_holdout_median_threshold = calibrated.get("enrollment_holdout_median_threshold", 0.90)
                enrollment_holdout_min_threshold = calibrated.get("enrollment_holdout_min_threshold", 0.85)
                cross_frame_consistency_threshold = calibrated.get("cross_frame_consistency_threshold", 0.85)
                duplicate_review_threshold = calibrated.get("duplicate_review_threshold", 0.75)
                duplicate_block_threshold = calibrated.get("duplicate_block_threshold", 0.90)
                single_candidate_accept_threshold = calibrated.get("single_candidate_accept_threshold")
                template_support_threshold = calibrated.get("template_support_threshold", 0.85)
                min_template_support = int(calibrated.get("min_template_support", 2))
                policy_version = calibrated.get("policy_version", "face-policy-v2")
                calibration_approved = True
            except CalibrationError as exc:
                calibration_error = exc.reason
        if pad_calibration_file:
            try:
                pad_calibrated = load_approved_pad_calibration(Path(pad_calibration_file))
                pad_live_threshold = pad_calibrated["live_threshold"]
                pad_spoof_threshold = pad_calibrated["spoof_threshold"]
                client_planar_z_threshold = pad_calibrated["client_planar_z_threshold"]
                pad_calibration_approved = True
                pad_calibration_error = None
            except CalibrationError as exc:
                pad_calibration_error = exc.reason
        env_accept_threshold = _float_env("FACE_ACCEPT_THRESHOLD")
        if env_accept_threshold is not None:
            accept_threshold = max(accept_threshold, env_accept_threshold) if accept_threshold is not None else env_accept_threshold
        env_min_margin = _float_env("FACE_MIN_MARGIN")
        if env_min_margin is not None:
            min_margin = max(min_margin, env_min_margin) if min_margin is not None else env_min_margin
        env_enroll_verify = _float_env("FACE_ENROLLMENT_VERIFY_THRESHOLD")
        if env_enroll_verify is not None:
            enrollment_verify_threshold = max(enrollment_verify_threshold or 0.0, env_enroll_verify)
        elif accept_threshold is not None and enrollment_verify_threshold is not None:
            enrollment_verify_threshold = max(enrollment_verify_threshold, accept_threshold)
        return cls(
            host=os.getenv("FACE_INFERENCE_HOST", "127.0.0.1"),
            port=_int_env("FACE_INFERENCE_PORT", 8765),
            shared_secret=os.getenv("FACE_INFERENCE_SHARED_SECRET", ""),
            scan_ttl_seconds=_int_env("FACE_SCAN_TTL_SECONDS", 300),
            max_frames=_int_env("FACE_MAX_FRAMES", 3),
            max_frame_bytes=_int_env("FACE_MAX_FRAME_BYTES", 1_000_000),
            max_enrollment_frames=_int_env("FACE_MAX_ENROLLMENT_FRAMES", 10),
            enrollment_templates=_int_env("FACE_ENROLLMENT_TEMPLATES", 3),
            max_candidates=_int_env("FACE_MAX_CANDIDATES", 500),
            max_embedding_dimension=_int_env("FACE_MAX_EMBEDDING_DIMENSION", 4096),
            max_attempts_per_minute=_int_env("FACE_MAX_ATTEMPTS_PER_MINUTE", 12),
            replay_window_seconds=_int_env("FACE_REPLAY_WINDOW_SECONDS", 600),
            challenge_ttl_seconds=_int_env("FACE_CHALLENGE_TTL_SECONDS", 15),
            challenge_head_turn_delta=float(os.getenv("FACE_CHALLENGE_HEAD_TURN_DELTA", "0.12")),
            accept_threshold=accept_threshold,
            enrollment_verify_threshold=enrollment_verify_threshold,
            min_margin=min_margin,
            single_candidate_accept_threshold=single_candidate_accept_threshold,
            template_support_threshold=template_support_threshold,
            min_template_support=min_template_support,
            verified_reference_threshold=verified_reference_threshold,
            enrollment_holdout_median_threshold=enrollment_holdout_median_threshold,
            enrollment_holdout_min_threshold=enrollment_holdout_min_threshold,
            cross_frame_consistency_threshold=cross_frame_consistency_threshold,
            duplicate_review_threshold=duplicate_review_threshold,
            duplicate_block_threshold=duplicate_block_threshold,
            policy_version=policy_version,
            model_version=os.getenv("FACE_MODEL_VERSION", "unconfigured"),
            model_version_id=int(os.getenv("FACE_MODEL_VERSION_ID", "0")),
            provider_id=os.getenv("FACE_RECOGNITION_PROVIDER_ID", "local-onnx"),
            provider_version=os.getenv("FACE_RECOGNITION_PROVIDER_VERSION", "1"),
            max_body_bytes=_int_env("FACE_MAX_BODY_BYTES", 15_000_000),
            detector_model=Path(detector) if detector else (model_dir / model_manifest["components"]["detector"]["file"] if model_manifest else None),
            recognizer_model=Path(recognizer) if recognizer else (model_dir / model_manifest["components"]["recognizer"]["file"] if model_manifest else None),
            pad_model=Path(pad) if pad else (model_dir / model_manifest["components"]["pad"]["file"] if model_manifest else None),
            pad_live_threshold=pad_live_threshold,
            pad_spoof_threshold=pad_spoof_threshold,
            client_planar_z_threshold=client_planar_z_threshold,
            pad_calibration_approved=pad_calibration_approved,
            pad_calibration_error=pad_calibration_error,
            calibration_approved=calibration_approved,
            calibration_error=calibration_error,
            model_integrity_verified=model_manifest is not None,
            model_error=model_error,
            model_manifest=model_manifest,
            pilot_geometry_debug=_bool_env("FACE_PILOT_GEOMETRY_DEBUG", False),
            identity_calibration_hash=hashlib.sha256(Path(calibration_file).read_bytes()).hexdigest() if calibration_approved and calibration_file else "",
            pad_calibration_hash=hashlib.sha256(Path(pad_calibration_file).read_bytes()).hexdigest() if pad_calibration_approved and pad_calibration_file else "",
            experiment_logging_enabled=_bool_env("FACE_EXPERIMENT_LOGGING_ENABLED", False),
            experiment_id_secret_file=os.getenv("FACE_EXPERIMENT_ID_SECRET_FILE", ""),
            experiment_opaque_id_version=_int_env("FACE_EXPERIMENT_OPAQUE_ID_VERSION", 1),
        )
