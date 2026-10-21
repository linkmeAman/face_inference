"""Small JSON-safe response schemas used by the HTTP boundary."""

from __future__ import annotations

from dataclasses import asdict, dataclass, replace
from typing import Any, Dict, Optional, Tuple


@dataclass(frozen=True)
class QualityResult:
    face_count: int = 0
    sharpness_ok: bool = False
    exposure_ok: bool = False
    pose_ok: bool = False
    size_ok: bool = False
    centred_ok: bool = False

    def to_dict(self) -> Dict[str, Any]:
        return asdict(self)


@dataclass(frozen=True)
class MatchResult:
    decision: str
    identity_decision: Optional[str] = None
    candidate_ref: Optional[str] = None
    top1_score: Optional[float] = None
    top2_score: Optional[float] = None
    margin: Optional[float] = None
    quality: QualityResult = QualityResult()
    liveness: str = "NOT_RUN"
    liveness_score: Optional[float] = None
    liveness_reason: str = "NOT_RUN"
    challenge: Optional[Dict[str, Any]] = None
    pose_yaw: Optional[float] = None
    pose_samples: Tuple[float, ...] = ()
    model_version: str = "unconfigured"
    latency_ms: int = 0
    decision_policy_version: str = "face-policy-v2"
    evidence_summary: Tuple[Dict[str, Any], ...] = ()
    comparison_scores: Tuple[Dict[str, Any], ...] = ()
    # Score-only per-template evidence used by the private experiment logger.
    # Keys are transient candidate/template refs and are HMACed before storage.
    frame_template_scores: Tuple[Dict[str, float], ...] = ()
    consensus_count: int = 0
    template_support: int = 0
    stage: str = "MATCHING"
    reason_code: str = "UNKNOWN"
    frame_geometry: Optional[Dict[str, Any]] = None
    selected_face_index: Optional[int] = None
    selection: Optional[Dict[str, Any]] = None
    planar_spoof_flag: bool = False
    edge_gate_status: str = "NOT_REPORTED"

    @property
    def outcome(self) -> str:
        if self.decision == "ACCEPT" and self.liveness == "LIVE" and self.candidate_ref:
            return "ACCEPT"
        reason = self.reason_code.upper()
        if reason in {"REPEATED_FRAMES", "REPLAYED_API_REQUEST", "REPLAYED_FRAME", "CHALLENGE_INVALID"}:
            return "REJECT_REPLAY"
        if self.liveness == "CHALLENGE" and self.challenge:
            return "PAD_CHALLENGE"
        if self.liveness == "SPOOF":
            return "REJECT_PAD"
        if reason in {"AMBIGUOUS_IDENTITY", "IDENTITY_CONSENSUS_FAILED", "MULTIPLE_FACES_IN_GUIDE"}:
            return "AMBIGUOUS"
        if self.stage in {"DETECTION", "GUIDE_SELECTION", "QUALITY"}:
            return "REJECT_QUALITY"
        if reason in {"CALIBRATION_NOT_APPROVED", "CALIBRATION_REQUIRED", "SINGLE_CANDIDATE_UNCALIBRATED", "MODEL_NOT_READY", "GENERATION_MISMATCH"}:
            return "SYSTEM_NOT_READY"
        return "UNKNOWN"

    def with_latency(self, latency_ms: int) -> "MatchResult":
        return replace(self, latency_ms=latency_ms)

    def to_dict(self) -> Dict[str, Any]:
        return {
            "decision": self.decision,
            "outcome": self.outcome,
            "identity_decision": self.identity_decision if self.outcome == "ACCEPT" else self.decision,
            "candidate_ref": self.candidate_ref if self.outcome == "ACCEPT" else None,
            "top1_score": self.top1_score,
            "top2_score": self.top2_score,
            "margin": self.margin,
            "quality": self.quality.to_dict(),
            "liveness": self.liveness,
            "liveness_score": self.liveness_score,
            "liveness_reason": self.liveness_reason,
            "challenge": self.challenge,
            "model_version": self.model_version,
            "latency_ms": self.latency_ms,
            "decision_policy_version": self.decision_policy_version,
            "evidence_summary": list(self.evidence_summary),
            "comparison_scores": list(self.comparison_scores),
            "consensus_count": self.consensus_count,
            "template_support": self.template_support,
            "stage": self.stage,
            "reason_code": self.reason_code,
            "frame_geometry": self.frame_geometry,
            "selected_face_index": self.selected_face_index,
            "selection": self.selection,
            "planar_spoof_flag": self.planar_spoof_flag,
            "edge_gate_status": self.edge_gate_status,
        }
