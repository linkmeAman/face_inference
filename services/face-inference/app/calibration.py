"""Model approval and strict operating-point calibration helpers."""

from __future__ import annotations

import hashlib
import json
import math
from dataclasses import dataclass
from pathlib import Path
from typing import Any, Dict, List, Mapping, Optional, Sequence


class CalibrationError(ValueError):
    def __init__(self, reason: str, message: str) -> None:
        self.reason = reason
        super().__init__(message)


SFACE_PREPROCESSING_VERSION = "sface-opencv-aligncrop-rgb-raw-v2"


@dataclass(frozen=True)
class ModelApproval:
    approved: bool
    files: List[Dict[str, str]]
    license_reference: str
    policy_version: str


@dataclass(frozen=True)
class CalibrationReport:
    accept_threshold: float
    min_margin: float
    false_accepts: int
    false_rejects: int
    p50_latency_ms: int
    p95_latency_ms: int
    sample_count: int
    review_rate: float

    def decide(self, score: float, margin: float) -> str:
        if score >= self.accept_threshold and margin >= self.min_margin:
            return "ACCEPT"
        return "REVIEW"


@dataclass(frozen=True)
class PadCalibrationReport:
    live_threshold: float
    spoof_threshold: float
    false_accepts: int
    attack_misses: int
    live_reviews: int
    p50_latency_ms: int
    p95_latency_ms: int
    sample_count: int
    venue_count: int
    device_count: int
    lighting_count: int
    fixtures_tested: List[str]


def p95(values: Sequence[int]) -> int:
    if not values:
        raise CalibrationError("LATENCY_SAMPLES_MISSING", "latency samples are required")
    ordered = sorted(int(value) for value in values)
    index = max(0, min(len(ordered) - 1, int(math.ceil(0.95 * len(ordered))) - 1))
    return ordered[index]


def _p50(values: Sequence[int]) -> int:
    ordered = sorted(int(value) for value in values)
    return ordered[(len(ordered) - 1) // 2]


def approve_model(manifest: Mapping[str, Any]) -> ModelApproval:
    if str(manifest.get("status", "")).upper() != "APPROVED":
        raise CalibrationError("MODEL_NOT_APPROVED", "model approval status is not APPROVED")
    license_reference = str(manifest.get("license_reference", ""))
    if not license_reference:
        raise CalibrationError("LICENSE_MISSING", "an approved license reference is required")
    if not manifest.get("approved_by") or not manifest.get("approved_at"):
        raise CalibrationError("APPROVAL_METADATA_MISSING", "approver and approval date are required")
    policy_version = str(manifest.get("policy_version", ""))
    if not policy_version:
        raise CalibrationError("POLICY_VERSION_MISSING", "face approval policy version is required")
    files = manifest.get("files")
    if not isinstance(files, list) or not files:
        raise CalibrationError("MODEL_FILES_MISSING", "at least one model file is required")
    verified: List[Dict[str, str]] = []
    for item in files:
        if not isinstance(item, Mapping):
            raise CalibrationError("MODEL_FILE_INVALID", "model file manifest entry is invalid")
        path = Path(str(item.get("path", "")))
        expected = str(item.get("sha256", "")).lower()
        if not path.is_file() or len(expected) != 64:
            raise CalibrationError("MODEL_FILE_INVALID", "model file path or checksum is invalid")
        actual = hashlib.sha256(path.read_bytes()).hexdigest()
        if actual != expected:
            raise CalibrationError("CHECKSUM_MISMATCH", "model file checksum does not match the approval manifest")
        verified.append({"path": str(path), "sha256": actual})
    return ModelApproval(True, verified, license_reference, policy_version)


def verify_model_manifest(path: Path) -> Dict[str, Any]:
    try:
        manifest = json.loads(path.read_text())
    except (OSError, ValueError) as exc:
        raise CalibrationError("MODEL_MANIFEST_INVALID", "model manifest could not be read") from exc
    if not isinstance(manifest, Mapping):
        raise CalibrationError("MODEL_MANIFEST_INVALID", "model manifest must be an object")
    verified = {}
    for component in ("detector", "recognizer", "pad"):
        item = manifest.get(component)
        if not isinstance(item, Mapping):
            raise CalibrationError("MODEL_MANIFEST_INVALID", f"{component} manifest entry is missing")
        filename = str(item.get("file", ""))
        expected = str(item.get("sha256", "")).lower()
        if not filename or Path(filename).name != filename or len(expected) != 64:
            raise CalibrationError("MODEL_MANIFEST_INVALID", f"{component} filename or checksum is invalid")
        model_path = path.parent / filename
        if not model_path.is_file():
            raise CalibrationError("MODEL_FILE_MISSING", f"{component} model file is missing")
        actual = hashlib.sha256(model_path.read_bytes()).hexdigest()
        if actual != expected:
            raise CalibrationError("MODEL_CHECKSUM_MISMATCH", f"{component} model checksum does not match")
        verified[component] = {"file": filename, "sha256": actual, "license": str(item.get("license", "")), "source": str(item.get("source", ""))}
    return {
        "bundle_version": str(manifest.get("bundle_version", "")),
        "retrieved_on": str(manifest.get("retrieved_on", "")),
        "approval": dict(manifest.get("approval", {})) if isinstance(manifest.get("approval"), Mapping) else {},
        "components": verified,
    }


def calibrate_scores(
    records: Sequence[Mapping[str, Any]],
    target_p95_ms: int = 250,
    target_threshold: Optional[float] = None,
    target_margin: Optional[float] = None,
) -> CalibrationReport:
    if not records:
        raise CalibrationError("SAMPLES_MISSING", "held-out score samples are required")
    genuine = []
    impostor = []
    latencies = []
    for record in records:
        label = str(record.get("label", "")).lower()
        if label not in {"genuine", "impostor"}:
            raise CalibrationError("SAMPLE_LABEL_INVALID", "each score sample must be genuine or impostor")
        try:
            score = float(record["score"])
            margin = float(record["margin"])
            latency = int(record["latency_ms"])
        except (KeyError, TypeError, ValueError) as exc:
            raise CalibrationError("SAMPLE_INVALID", "score, margin, and latency are required") from exc
        if not 0.0 <= score <= 1.0 or not 0.0 <= margin <= 1.0 or latency < 0:
            raise CalibrationError("SAMPLE_INVALID", "score and margin must be 0..1 and latency non-negative")
        latencies.append(latency)
        (genuine if label == "genuine" else impostor).append((score, margin))
    if not genuine or not impostor:
        raise CalibrationError("SAMPLE_CLASSES_MISSING", "both genuine and impostor samples are required")
    p95_latency = p95(latencies)
    if p95_latency > int(target_p95_ms):
        raise CalibrationError("LATENCY_TARGET_FAILED", "P95 latency exceeds the approved target")
    max_impostor_score = max(item[0] for item in impostor)
    min_genuine_score = min(item[0] for item in genuine)
    max_impostor_margin = max(item[1] for item in impostor)
    min_genuine_margin = min(item[1] for item in genuine)
    if max_impostor_score >= min_genuine_score or max_impostor_margin >= min_genuine_margin:
        raise CalibrationError("NO_STRICT_OPERATING_POINT", "held-out scores do not separate genuine and impostor samples")
    if target_threshold is not None:
        threshold = float(target_threshold)
        if max_impostor_score >= threshold or min_genuine_score < threshold:
            raise CalibrationError("NO_STRICT_OPERATING_POINT", f"target threshold {threshold} does not strictly separate impostors ({max_impostor_score}) and genuine ({min_genuine_score})")
    else:
        threshold = (max_impostor_score + min_genuine_score) / 2.0

    if target_margin is not None:
        margin_threshold = float(target_margin)
        if max_impostor_margin >= margin_threshold or min_genuine_margin < margin_threshold:
            raise CalibrationError("NO_STRICT_OPERATING_POINT", f"target margin {margin_threshold} does not strictly separate impostors ({max_impostor_margin}) and genuine ({min_genuine_margin})")
    else:
        margin_threshold = (max_impostor_margin + min_genuine_margin) / 2.0

    false_accepts = sum(1 for score, margin in impostor if score >= threshold and margin >= margin_threshold)
    false_rejects = sum(1 for score, margin in genuine if score < threshold or margin < margin_threshold)
    decisions = ["ACCEPT" if score >= threshold and margin >= margin_threshold else "REVIEW" for score, margin in genuine + impostor]
    review_rate = decisions.count("REVIEW") / float(len(decisions))
    return CalibrationReport(threshold, margin_threshold, false_accepts, false_rejects, _p50(latencies), p95_latency, len(records), review_rate)


def load_approved_calibration(path: Path) -> Dict[str, Any]:
    try:
        payload = json.loads(path.read_text())
    except (OSError, ValueError) as exc:
        raise CalibrationError("CALIBRATION_FILE_INVALID", "calibration file could not be read") from exc
    approval = approve_model(payload)
    if approval.policy_version in {"face-policy-v2", "face-policy-v3"} and str(payload.get("preprocessing_version", "")) != SFACE_PREPROCESSING_VERSION:
        raise CalibrationError("CALIBRATION_PREPROCESSING_MISMATCH", "identity calibration was produced for incompatible SFace preprocessing")
    try:
        threshold = float(payload["accept_threshold"])
        margin = float(payload["min_margin"])
        false_accepts = int(payload["false_accepts"])
        p95_latency = int(payload["p95_latency_ms"])
    except (KeyError, TypeError, ValueError) as exc:
        raise CalibrationError("CALIBRATION_METADATA_MISSING", "calibration thresholds and metrics are required") from exc
    if not 0.0 <= threshold <= 1.0 or not 0.0 <= margin <= 1.0 or false_accepts != 0 or p95_latency < 0:
        raise CalibrationError("CALIBRATION_POLICY_INVALID", "calibration policy is not a strict approved operating point")
    if approval.policy_version == "face-policy-v2" and (threshold < 0.90 or margin < 0.20):
        raise CalibrationError("CALIBRATION_POLICY_INVALID", "face-policy-v2 requires accept_threshold >= 0.90 and min_margin >= 0.20")
    if approval.policy_version == "face-policy-v3":
        try:
            single_threshold = float(payload["single_candidate_accept_threshold"])
            support_threshold = float(payload["template_support_threshold"])
            minimum_support = int(payload["min_template_support"])
        except (KeyError, TypeError, ValueError) as exc:
            raise CalibrationError("CALIBRATION_METADATA_MISSING", "face-policy-v3 identity-support thresholds are required") from exc
        if not 0.0 <= single_threshold <= 1.0 or not 0.0 <= support_threshold <= 1.0 or minimum_support < 2:
            raise CalibrationError("CALIBRATION_POLICY_INVALID", "face-policy-v3 identity-support thresholds are invalid")
    try:
        duplicate_review_threshold = float(payload.get("duplicate_review_threshold", 0.75))
        duplicate_block_threshold = float(payload.get("duplicate_block_threshold", 0.90))
        duplicate_min_support = int(payload.get("min_template_support", 2))
    except (TypeError, ValueError) as exc:
        raise CalibrationError("CALIBRATION_METADATA_MISSING", "duplicate identity policy is invalid") from exc
    if (not 0.0 <= duplicate_review_threshold <= duplicate_block_threshold <= 1.0
            or duplicate_min_support < 2):
        raise CalibrationError("CALIBRATION_POLICY_INVALID", "duplicate identity policy must have ordered thresholds and two-template support")
    return {
        "approval": approval,
        "policy_version": approval.policy_version,
        "preprocessing_version": SFACE_PREPROCESSING_VERSION,
        "accept_threshold": threshold,
        "min_margin": margin,
        "verified_reference_threshold": float(payload.get("verified_reference_threshold", 0.90)),
        "enrollment_holdout_median_threshold": float(payload.get("enrollment_holdout_median_threshold", 0.90)),
        "enrollment_holdout_min_threshold": float(payload.get("enrollment_holdout_min_threshold", 0.85)),
        "cross_frame_consistency_threshold": float(payload.get("cross_frame_consistency_threshold", 0.85)),
        "duplicate_review_threshold": duplicate_review_threshold,
        "duplicate_block_threshold": duplicate_block_threshold,
        "single_candidate_accept_threshold": (float(payload["single_candidate_accept_threshold"])
                                                if payload.get("single_candidate_accept_threshold") is not None else None),
        "template_support_threshold": float(payload.get("template_support_threshold", 0.85)),
        "min_template_support": int(payload.get("min_template_support", 2)),
        "enrollment_verify_threshold": float(payload.get("enrollment_verify_threshold", threshold)),
        "p95_latency_ms": p95_latency,
    }


PAD_REQUIRED_FIXTURES = {
    "PRINT_PHOTO",
    "PHONE_SCREEN",
    "TABLET_SCREEN",
    "VIDEO_REPLAY",
    "MASK",
    "SYNTHETIC_INJECTION",
    "REPEATED_FRAMES",
    "API_REPLAY",
}
PAD_PREPROCESSING_VERSION = "anti-spoof-mn3-square-1.5-v1"


def calibrate_pad_scores(
    records: Sequence[Mapping[str, Any]],
    target_p95_ms: int = 100,
    target_live_threshold: Optional[float] = None,
    target_spoof_threshold: Optional[float] = None,
) -> PadCalibrationReport:
    """Derive a strict three-way PAD operating point from score-only evidence.

    Raw frames are deliberately outside this interface. Every attack fixture
    and every required representative slice must be present before an artifact
    can be generated.
    """
    if not records:
        raise CalibrationError("PAD_SAMPLES_MISSING", "PAD score samples are required")
    live_scores: List[float] = []
    attack_scores: List[float] = []
    latencies: List[int] = []
    venues = set()
    devices = set()
    lighting = set()
    fixtures = set()
    for record in records:
        label = str(record.get("label", "")).lower()
        if label not in {"live", "attack"}:
            raise CalibrationError("PAD_SAMPLE_LABEL_INVALID", "each PAD sample must be live or attack")
        try:
            score = float(record["score"])
            latency = int(record["latency_ms"])
        except (KeyError, TypeError, ValueError) as exc:
            raise CalibrationError("PAD_SAMPLE_INVALID", "score and latency_ms are required") from exc
        venue = str(record.get("venue", "")).strip()
        device = str(record.get("device", "")).strip()
        light = str(record.get("lighting", "")).strip()
        fixture = str(record.get("fixture", "")).strip().upper()
        if not 0.0 <= score <= 1.0 or latency < 0 or not venue or not device or not light or not fixture:
            raise CalibrationError("PAD_SAMPLE_INVALID", "PAD score, latency, fixture, venue, device, and lighting are required")
        (live_scores if label == "live" else attack_scores).append(score)
        if label == "attack":
            fixtures.add(fixture)
        latencies.append(latency)
        venues.add(venue)
        devices.add(device)
        lighting.add(light)
    if not live_scores or not attack_scores:
        raise CalibrationError("PAD_SAMPLE_CLASSES_MISSING", "both live and attack PAD samples are required")
    if not PAD_REQUIRED_FIXTURES.issubset(fixtures):
        raise CalibrationError("PAD_FIXTURE_COVERAGE_MISSING", "required PAD attack fixtures are missing")
    if len(venues) < 2 or len(devices) < 3 or len(lighting) < 4:
        raise CalibrationError("PAD_REPRESENTATIVE_COVERAGE_MISSING", "PAD samples require 2 venues, 3 devices, and 4 lighting conditions")
    p95_latency = p95(latencies)
    if p95_latency > int(target_p95_ms):
        raise CalibrationError("PAD_LATENCY_TARGET_FAILED", "PAD P95 latency exceeds the approved target")

    max_attack = max(attack_scores)
    min_live = min(live_scores)
    if max_attack >= min_live:
        raise CalibrationError("PAD_NO_STRICT_OPERATING_POINT", "PAD live and attack scores do not strictly separate")
    spoof_threshold = max_attack if target_spoof_threshold is None else float(target_spoof_threshold)
    live_threshold = min_live if target_live_threshold is None else float(target_live_threshold)
    if not 0.0 <= spoof_threshold < live_threshold <= 1.0:
        raise CalibrationError("PAD_NO_STRICT_OPERATING_POINT", "PAD thresholds must define reject, challenge, and live regions")
    if any(score > spoof_threshold for score in attack_scores) or any(score < live_threshold for score in live_scores):
        raise CalibrationError("PAD_NO_STRICT_OPERATING_POINT", "requested PAD thresholds do not separate the held-out samples")

    attack_misses = sum(score >= live_threshold for score in attack_scores)
    live_reviews = sum(score < live_threshold for score in live_scores)
    return PadCalibrationReport(
        live_threshold=live_threshold,
        spoof_threshold=spoof_threshold,
        false_accepts=attack_misses,
        attack_misses=attack_misses,
        live_reviews=live_reviews,
        p50_latency_ms=_p50(latencies),
        p95_latency_ms=p95_latency,
        sample_count=len(records),
        venue_count=len(venues),
        device_count=len(devices),
        lighting_count=len(lighting),
        fixtures_tested=sorted(fixtures),
    )


def load_approved_pad_calibration(path: Path) -> Dict[str, Any]:
    try:
        payload = json.loads(path.read_text())
    except (OSError, ValueError) as exc:
        raise CalibrationError("PAD_CALIBRATION_FILE_INVALID", "PAD calibration file could not be read") from exc
    approval = approve_model(payload)
    if str(payload.get("preprocessing_version", "")) != PAD_PREPROCESSING_VERSION:
        raise CalibrationError(
            "PAD_PREPROCESSING_VERSION_MISMATCH",
            "PAD calibration was not produced with the active preprocessing contract",
        )
    try:
        live_threshold = float(payload["live_threshold"])
        spoof_threshold = float(payload["spoof_threshold"])
        client_planar_z_threshold = float(payload["client_planar_z_threshold"])
        false_accepts = int(payload["false_accepts"])
        attack_misses = int(payload["attack_misses"])
        p95_latency = int(payload["p95_latency_ms"])
        venue_count = int(payload["venue_count"])
        device_count = int(payload["device_count"])
        lighting_count = int(payload["lighting_count"])
    except (KeyError, TypeError, ValueError) as exc:
        raise CalibrationError("PAD_CALIBRATION_METADATA_MISSING", "PAD thresholds and metrics are required") from exc
    if (
        not 0.0 <= spoof_threshold < live_threshold <= 1.0
        or not 0.0 < client_planar_z_threshold <= 1.0
        or false_accepts != 0
        or attack_misses != 0
        or p95_latency < 0
        or venue_count < 2
        or device_count < 3
        or lighting_count < 4
    ):
        raise CalibrationError("PAD_CALIBRATION_POLICY_INVALID", "PAD calibration does not meet the strict policy")
    fixtures = {str(item).upper() for item in payload.get("fixtures_tested", [])}
    if not PAD_REQUIRED_FIXTURES.issubset(fixtures):
        raise CalibrationError("PAD_FIXTURE_COVERAGE_MISSING", "required PAD attack fixtures are missing")
    return {
        "approval": approval,
        "live_threshold": live_threshold,
        "spoof_threshold": spoof_threshold,
        "client_planar_z_threshold": client_planar_z_threshold,
        "p95_latency_ms": p95_latency,
        "fixtures_tested": sorted(fixtures),
        "preprocessing_version": PAD_PREPROCESSING_VERSION,
    }
