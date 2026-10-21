"""In-memory scan orchestration for the private inference service."""

from __future__ import annotations

import math
import hashlib
import re
import secrets
import threading
import time
from dataclasses import dataclass, replace
from typing import Any, Callable, Dict, List, Mapping, Optional, Sequence, Tuple

try:
    from .matcher import GUIDED_TEMPLATE_COUNT, MatchResult, Matcher, MatcherUnavailable, QualityResult
    from .safe_logging import SafeFaceLogger
    from .settings import Settings
    from .experiment_logger import ExperimentLogger, compact_frame_scores, opaque_id
except ImportError:  # direct imports used by the contract runner
    from matcher import GUIDED_TEMPLATE_COUNT, MatchResult, Matcher, MatcherUnavailable, QualityResult  # type: ignore
    from safe_logging import SafeFaceLogger  # type: ignore
    from settings import Settings  # type: ignore
    from experiment_logger import ExperimentLogger, compact_frame_scores, opaque_id  # type: ignore


class InferenceError(RuntimeError):
    def __init__(self, reason: str, message: str) -> None:
        self.reason = reason
        super().__init__(message)


@dataclass
class _Scan:
    candidates: Dict[str, Tuple[Tuple[float, ...], ...]]
    expires_at: float
    attempt_times: List[float]
    seen_batches: Dict[str, float]
    generation: str = ""
    challenge: Optional["_Challenge"] = None


@dataclass
class _Challenge:
    token: str
    challenge_type: str
    candidate_ref: str
    baseline_yaw: float
    expires_at: float


class InferenceService:
    def __init__(
        self,
        matcher: Matcher,
        settings: Optional[Settings] = None,
        clock: Callable[[], float] = time.time,
        logger: Optional[SafeFaceLogger] = None,
        experiment_logger: Optional[ExperimentLogger] = None,
    ) -> None:
        self.settings = settings or Settings()
        self.matcher = matcher
        self.clock = clock
        self.logger = logger or SafeFaceLogger()
        self.experiment_logger = experiment_logger
        self._scans: Dict[str, _Scan] = {}
        self._recent_batches: Dict[str, float] = {}
        self._inference_lock = threading.Lock()

    def start_scan(self, scan_id: str, roster: Sequence[Mapping[str, object]], ttl_seconds: Optional[int] = None) -> int:
        self._validate_scan_id(scan_id)
        if not isinstance(roster, (list, tuple)) or not roster:
            raise InferenceError("EMPTY_ROSTER", "scan roster is empty")
        if len(roster) > self.settings.max_candidates:
            raise InferenceError("CANDIDATE_LIMIT", "scan roster is too large")

        candidates: Dict[str, Tuple[Tuple[float, ...], ...]] = {}
        dimension = None
        for candidate in roster:
            if not isinstance(candidate, Mapping):
                raise InferenceError("INVALID_ROSTER", "roster candidate is invalid")
            ref = candidate.get("candidate_ref")
            raw_embeddings = candidate.get("embeddings")
            if raw_embeddings is None:
                legacy = candidate.get("embedding")
                raw_embeddings = [legacy] if isinstance(legacy, (list, tuple)) else None
            if not isinstance(ref, str) or not re.fullmatch(r"[A-Za-z0-9_-]{1,128}", ref):
                raise InferenceError("INVALID_CANDIDATE_REF", "candidate reference is invalid")
            if ref in candidates:
                raise InferenceError("DUPLICATE_CANDIDATE_REF", "candidate reference is duplicated")
            if not isinstance(raw_embeddings, (list, tuple)) or not raw_embeddings or len(raw_embeddings) > GUIDED_TEMPLATE_COUNT:
                raise InferenceError("INVALID_EMBEDDING", "candidate embedding is invalid")
            parsed = []
            for embedding in raw_embeddings:
                if not isinstance(embedding, (list, tuple)) or not embedding:
                    raise InferenceError("INVALID_EMBEDDING", "candidate embedding is invalid")
                if len(embedding) > self.settings.max_embedding_dimension:
                    raise InferenceError("EMBEDDING_LIMIT", "candidate embedding is too large")
                try:
                    values = tuple(float(value) for value in embedding)
                except (TypeError, ValueError) as exc:
                    raise InferenceError("INVALID_EMBEDDING", "candidate embedding is not numeric") from exc
                if not all(math.isfinite(value) for value in values):
                    raise InferenceError("INVALID_EMBEDDING", "candidate embedding contains a non-finite value")
                if dimension is None:
                    dimension = len(values)
                if len(values) != dimension:
                    raise InferenceError("EMBEDDING_DIMENSION", "candidate embeddings have different dimensions")
                parsed.append(values)
            candidates[ref] = tuple(parsed)

        ttl = ttl_seconds if ttl_seconds is not None else self.settings.scan_ttl_seconds
        if ttl < 1 or ttl > 3600:
            raise InferenceError("INVALID_TTL", "scan TTL is outside the allowed range")
        self._scans[scan_id] = _Scan(
            candidates=candidates,
            expires_at=self.clock() + ttl,
            attempt_times=[],
            seen_batches={},
            generation=self.settings.recognition_generation,
            challenge=None,
        )
        self.logger.log("SCAN_ROSTER_READY", {"scan_id": scan_id, "candidate_count": len(candidates), "ttl_seconds": ttl})
        return ttl

    def recognize(self, scan_id: str, frames: Sequence[bytes], challenge_token: Optional[str] = None,
                  capture_contexts=None, planar_spoof_flag: bool = False,
                  edge_gate_status: str = "NOT_REPORTED") -> MatchResult:
        scan = self._active_scan(scan_id)
        if scan.generation != self.settings.recognition_generation:
            self.delete_scan(scan_id)
            raise InferenceError("GENERATION_MISMATCH", "recognition generation changed during the scan")
        self._validate_frames(frames)
        # Recognition requires one independent three-frame burst. Preview and
        # enrollment keep their existing, separate validation contracts.
        if len(frames) != 3:
            raise InferenceError("FRAME_COUNT_LIMIT", "recognition requires exactly three frames")

        with self._inference_lock:
            now = self.clock()
            scan.attempt_times = [item for item in scan.attempt_times if item > now - 60.0]
            if len(scan.attempt_times) >= self.settings.max_attempts_per_minute:
                self.logger.log("RECOGNITION_REJECTED", {"scan_id": scan_id, "reason": "ATTEMPT_RATE_LIMIT"})
                raise InferenceError("ATTEMPT_RATE_LIMIT", "scan attempt rate limit exceeded")
            scan.attempt_times.append(now)

            frame_digests = [hashlib.sha256(frame).digest() for frame in frames]
            if len(frame_digests) != len(set(frame_digests)):
                self.logger.log("RECOGNITION_REJECTED", {"scan_id": scan_id, "reason": "REPEATED_FRAMES"})
                return MatchResult(
                    decision="REJECT",
                    identity_decision="REJECT",
                    quality=QualityResult(),
                    liveness="SPOOF",
                    model_version=getattr(self.matcher, "model_version", "unconfigured"),
                    stage="PAD",
                    reason_code="REPEATED_FRAMES",
                )
            batch_digest = hashlib.sha256(b"".join(frame_digests)).hexdigest()
            replay_cutoff = now - self.settings.replay_window_seconds
            self._recent_batches = {
                digest: seen_at
                for digest, seen_at in self._recent_batches.items()
                if seen_at > replay_cutoff
            }
            if batch_digest in scan.seen_batches or batch_digest in self._recent_batches:
                self.logger.log("RECOGNITION_REJECTED", {"scan_id": scan_id, "reason": "REPLAYED_API_REQUEST"})
                return MatchResult(
                    decision="REJECT",
                    identity_decision="REJECT",
                    quality=QualityResult(),
                    liveness="SPOOF",
                    model_version=getattr(self.matcher, "model_version", "unconfigured"),
                    stage="PAD",
                    reason_code="REPLAYED_API_REQUEST",
                )

            started = time.monotonic()
            if challenge_token:
                challenge = scan.challenge
                if (
                    challenge is None
                    or challenge.expires_at <= now
                    or not secrets.compare_digest(challenge.token, challenge_token)
                ):
                    self.logger.log("RECOGNITION_REJECTED", {"scan_id": scan_id, "reason": "CHALLENGE_INVALID"})
                    raise InferenceError("CHALLENGE_INVALID", "active challenge is invalid or expired")
                scan.challenge = None
                match_challenge = getattr(self.matcher, "match_challenge", None)
                if not callable(match_challenge):
                    raise InferenceError("CHALLENGE_UNAVAILABLE", "active challenge verification is unavailable")
                args = (frames, scan.candidates, challenge.challenge_type, challenge.baseline_yaw, challenge.candidate_ref)
                result = match_challenge(*args, capture_contexts=capture_contexts) if capture_contexts is not None else match_challenge(*args)
            else:
                if scan.challenge is not None and scan.challenge.expires_at <= now:
                    scan.challenge = None
                result = self.matcher.match(frames, scan.candidates, capture_contexts=capture_contexts) if capture_contexts is not None else self.matcher.match(frames, scan.candidates)
                if (
                    result.decision == "ACCEPT"
                    and str(result.liveness).upper() == "CHALLENGE"
                    and result.candidate_ref
                    and result.pose_yaw is not None
                ):
                    challenge = _Challenge(
                        token=secrets.token_urlsafe(32),
                        challenge_type=secrets.choice(("LOOK_LEFT", "LOOK_RIGHT")),
                        candidate_ref=result.candidate_ref,
                        baseline_yaw=float(result.pose_yaw),
                        expires_at=now + self.settings.challenge_ttl_seconds,
                    )
                    scan.challenge = challenge
                    result = replace(
                        result,
                        challenge={
                            "type": challenge.challenge_type,
                            "token": challenge.token,
                            "expires_in": self.settings.challenge_ttl_seconds,
                        },
                        stage="PAD",
                        reason_code="ACTIVE_CHALLENGE_REQUIRED",
                    )
            scan.seen_batches[batch_digest] = now
            self._recent_batches[batch_digest] = now
            if len(scan.seen_batches) > 128:
                oldest = min(scan.seen_batches, key=scan.seen_batches.get)
                scan.seen_batches.pop(oldest, None)
            if len(self._recent_batches) > 4096:
                oldest = min(self._recent_batches, key=self._recent_batches.get)
                self._recent_batches.pop(oldest, None)
        elapsed = max(result.latency_ms, int((time.monotonic() - started) * 1000))
        if scan.generation != self.settings.recognition_generation:
            self.delete_scan(scan_id)
            raise InferenceError("GENERATION_MISMATCH", "recognition generation changed during the scan")
        if result.candidate_ref is not None and result.candidate_ref not in scan.candidates:
            self.logger.log("RECOGNITION_REVIEW", {"scan_id": scan_id, "reason": "OUTSIDE_ROSTER"})
            return replace(result, decision="REVIEW", identity_decision="REVIEW", candidate_ref=None,
                           latency_ms=elapsed, stage="MATCHING", reason_code="OUTSIDE_ROSTER")
        if result.decision == "ACCEPT" and not self.settings.calibration_approved:
            self.logger.log("RECOGNITION_REVIEW", {"scan_id": scan_id, "reason": "CALIBRATION_NOT_APPROVED"})
            return replace(result, decision="REVIEW", candidate_ref=None, latency_ms=elapsed,
                           stage="MATCHING", reason_code="CALIBRATION_NOT_APPROVED")
        if result.decision not in {"ACCEPT", "REVIEW", "REJECT"}:
            raise InferenceError("INVALID_DECISION", "matcher returned an invalid decision")
        result = self._apply_liveness_gate(scan_id, result, elapsed)
        if planar_spoof_flag or edge_gate_status != "NOT_REPORTED":
            result = replace(result, planar_spoof_flag=planar_spoof_flag,
                             edge_gate_status=edge_gate_status)
        if result.decision != "ACCEPT" and result.candidate_ref is not None:
            result = replace(result, candidate_ref=None)
        self._record_primary_experiment(scan_id, scan, result)
        if self.settings.pilot_geometry_debug and isinstance(result.selection, Mapping):
            geometry = result.frame_geometry or {}
            original = geometry.get("original", {}) if isinstance(geometry, Mapping) else {}
            detector = geometry.get("detector", {}) if isinstance(geometry, Mapping) else {}
            display = geometry.get("display", {}) if isinstance(geometry, Mapping) else {}
            content = display.get("content_rect", {}) if isinstance(display, Mapping) else {}
            guide = geometry.get("guide", {}) if isinstance(geometry, Mapping) else {}
            self.logger.log("FACE_GEOMETRY", {
                "scan_id": scan_id,
                "original_width": original.get("width"), "original_height": original.get("height"),
                "detector_width": detector.get("width"), "detector_height": detector.get("height"),
                "display_width": display.get("width"), "display_height": display.get("height"),
                "content_x": content.get("x"), "content_y": content.get("y"),
                "content_width": content.get("width"), "content_height": content.get("height"),
                "object_fit": display.get("object_fit"), "capture_source": geometry.get("source"),
                "guide_cx": guide.get("cx"), "guide_cy": guide.get("cy"),
                "guide_rx": guide.get("rx"), "guide_ry": guide.get("ry"),
                "mirrored_preview": geometry.get("mirrored_preview"), "rotation": geometry.get("rotation"),
                "selected_face_index": result.selected_face_index,
                "selection_reason": result.selection.get("reason_code"),
                "selection_ambiguous": result.selection.get("ambiguous"),
            })
            for item in list(result.selection.get("faces", []))[:8]:
                if not isinstance(item, Mapping):
                    continue
                box = item.get("box", {}) if isinstance(item.get("box"), Mapping) else {}
                self.logger.log("FACE_GEOMETRY_CANDIDATE", {
                    "scan_id": scan_id, "face_index": item.get("index"),
                    "box_x": box.get("x"), "box_y": box.get("y"),
                    "box_width": box.get("width"), "box_height": box.get("height"),
                    "confidence": item.get("confidence"), "center_inside": item.get("center_inside"),
                    "overlap": item.get("overlap"), "iou": item.get("iou"),
                    "guide_valid": item.get("guide_valid"),
                })
            self.logger.log("FACE_DECISION_DEBUG", {
                "scan_id": scan_id,
                "sharpness_ok": result.quality.sharpness_ok,
                "exposure_ok": result.quality.exposure_ok,
                "pose_ok": result.quality.pose_ok,
                "size_ok": result.quality.size_ok,
                "centred_ok": result.quality.centred_ok,
                "pad_state": result.liveness,
                "pad_score": result.liveness_score,
                "top1_score": result.top1_score,
                "top2_score": result.top2_score,
                "margin": result.margin,
                "consensus_count": result.consensus_count,
                "template_support": result.template_support,
                "identity_decision": result.identity_decision,
                "decision": result.decision,
                "stage": result.stage,
                "reason_code": result.reason_code,
            })
        self.logger.log("RECOGNITION_RESULT", {"scan_id": scan_id, "decision": result.decision,
                                                "identity_decision": result.identity_decision,
                                                "candidate_ref": result.candidate_ref,
                                                "top1_score": result.top1_score,
                                                "top2_score": result.top2_score,
                                                "margin": result.margin,
                                                "template_support": result.template_support,
                                                "consensus_count": result.consensus_count,
                                                "liveness": result.liveness,
                                                "stage": result.stage, "reason_code": result.reason_code,
                                                "latency_ms": elapsed})
        return result.with_latency(elapsed)

    def _apply_liveness_gate(self, scan_id: str, result: MatchResult, latency_ms: int) -> MatchResult:
        liveness = str(result.liveness or "UNKNOWN").upper()
        if liveness not in {"LIVE", "SPOOF", "CHALLENGE", "UNKNOWN", "NOT_RUN"}:
            liveness = "UNKNOWN"
        decision = result.decision
        identity_decision = result.identity_decision or result.decision
        candidate_ref = result.candidate_ref
        if decision == "ACCEPT" and liveness != "LIVE":
            decision = "REJECT" if liveness == "SPOOF" else "REVIEW"
            candidate_ref = None
            self.logger.log("RECOGNITION_LIVENESS_GATE", {
                "scan_id": scan_id,
                "decision": decision,
                "liveness": liveness,
            })
        stage = result.stage
        reason_code = result.reason_code
        if identity_decision == "ACCEPT" and liveness != "LIVE":
            stage = "PAD"
            liveness_reason = str(result.liveness_reason or "").upper()
            if liveness_reason in {"", "NOT_RUN", "PAD_UNKNOWN", "UNKNOWN"}:
                liveness_reason = {
                    "SPOOF": "PASSIVE_SPOOF",
                    "CHALLENGE": "PASSIVE_BORDERLINE",
                    "UNKNOWN": "PAD_CALIBRATION_OR_EVIDENCE_UNAVAILABLE",
                    "NOT_RUN": "PAD_NOT_RUN",
                }.get(liveness, "PAD_NOT_LIVE")
            reason_code = liveness_reason
        return replace(result, decision=decision, identity_decision=identity_decision,
                       candidate_ref=candidate_ref, liveness=liveness,
                       latency_ms=latency_ms, stage=stage, reason_code=reason_code)

    def encode(self, frames: Sequence[bytes], capture_contexts=None):
        self._validate_enrollment_frames(frames)
        encoder = getattr(self.matcher, "encode_enrollment", None)
        if not callable(encoder):
            raise InferenceError("MODEL_NOT_READY", "enrollment encoder is not available")
        with self._inference_lock:
            try:
                return encoder(frames, capture_contexts=capture_contexts) if capture_contexts is not None else encoder(frames)
            except MatcherUnavailable as exc:
                raise InferenceError("ENROLLMENT_QUALITY_REJECTED", str(exc))

    def encode_reference(self, frame_or_frames: Any, capture_contexts=None):
        frames = list(frame_or_frames) if isinstance(frame_or_frames, (list, tuple)) else [frame_or_frames]
        self._validate_frames(frames)
        encoder = getattr(self.matcher, "encode_reference", None)
        if not callable(encoder):
            raise InferenceError("MODEL_NOT_READY", "reference encoder is not available")
        with self._inference_lock:
            try:
                return encoder(frame_or_frames, capture_contexts=capture_contexts) if capture_contexts is not None else encoder(frame_or_frames)
            except MatcherUnavailable as exc:
                raise InferenceError("REFERENCE_QUALITY_REJECTED", str(exc))

    def encode_v2(self, burst_a: Sequence[bytes], burst_b: Sequence[bytes], reference_embedding: Sequence[float], capture_contexts_a=None, capture_contexts_b=None, pose_protocol=None):
        if pose_protocol is not None:
            capability_name = ("guided_adaptive_capability" if pose_protocol == "guided-adaptive-v2"
                               else "guided_selection_capability" if pose_protocol == "guided-five-select-v1"
                               else "guided_enrollment_capability")
            capability = getattr(self.matcher, capability_name, None)
            if not callable(capability) or pose_protocol != capability().get("pose_protocol"):
                raise InferenceError("UNSUPPORTED_POSE_PROTOCOL", "guided pose protocol is not supported")
            expected_a = 10 if pose_protocol == "guided-five-select-v1" else 5
            if len(burst_a) != expected_a or len(burst_b) != 3:
                raise InferenceError("FRAME_COUNT", f"Guided enrollment requires exactly {expected_a} plus three frames")
        if not burst_a or len(burst_a) < 5:
            raise InferenceError("FRAME_COUNT", "Burst A requires at least 5 frames")
        if not burst_b or len(burst_b) < 3:
            raise InferenceError("FRAME_COUNT", "Burst B requires at least 3 frames")
        if not reference_embedding or len(reference_embedding) < 128:
            raise InferenceError("INVALID_REFERENCE", "Valid 128D reference embedding required")
        for f in list(burst_a) + list(burst_b):
            if not isinstance(f, bytes) or not f or len(f) > self.settings.max_frame_bytes:
                raise InferenceError("INVALID_FRAME", "frame must be non-empty bytes within size limit")
        encoder = getattr(self.matcher, "encode_enrollment_v2", None)
        if not callable(encoder):
            raise InferenceError("MODEL_NOT_READY", "two-burst encoder is not available")
        with self._inference_lock:
            try:
                if pose_protocol is not None:
                    result = encoder(burst_a, burst_b, reference_embedding,
                                     capture_contexts_a=capture_contexts_a, capture_contexts_b=capture_contexts_b,
                                     pose_protocol=pose_protocol)
                    for observation in result["quality_summary"]["accepted_poses"]:
                        self.logger.log("ENROLLMENT_POSE_ACCEPTED", {"pose_protocol": pose_protocol, **observation})
                    return result
                if capture_contexts_a is not None or capture_contexts_b is not None:
                    return encoder(burst_a, burst_b, reference_embedding,
                                   capture_contexts_a=capture_contexts_a, capture_contexts_b=capture_contexts_b)
                return encoder(burst_a, burst_b, reference_embedding)
            except MatcherUnavailable as exc:
                raise InferenceError("ENROLLMENT_QUALITY_REJECTED", str(exc))

    def _record_primary_experiment(self, scan_id: str, scan: _Scan, result: MatchResult) -> None:
        """Best-effort score-only audit; exceptions must never affect SFace."""
        if self.experiment_logger is None:
            return
        try:
            score_frames = list(getattr(result, "frame_template_scores", ()) or ())
            if len(score_frames) != 3:
                raw_scores = {str(item["candidate_ref"]) + "#0": float(item["score"])
                              for item in result.comparison_scores if isinstance(item, Mapping)}
                score_frames = [raw_scores or {"no-candidate#0": -1.0}] * 3
            evidence = compact_frame_scores(self.experiment_logger.secret, self.experiment_logger.opaque_id_version,
                [{"template_scores": scores, "quality": int(result.quality.face_count > 0), "pad": result.liveness_score or -1.0} for scores in score_frames])
            self.experiment_logger.record({
                "experiment_id": opaque_id(self.experiment_logger.secret, self.experiment_logger.opaque_id_version, "event", scan_id + str(self.clock())),
                "source": "PRIMARY", "scan_opaque_id": opaque_id(self.experiment_logger.secret, self.experiment_logger.opaque_id_version, "scan", scan_id),
                "roster_size": len(scan.candidates), "experiment_config_version": self.settings.recognition_generation,
                "model_name": "SFace", "model_version": result.model_version, "runtime": "onnxruntime", "precision_mode": "INT8",
                "preprocessing_version": self.settings.preprocessing_version, "policy_version": result.decision_policy_version,
                "decision": result.decision, "outcome_code": result.reason_code, "top1_score": result.top1_score,
                "top2_score": result.top2_score, "margin_score": result.margin, "consensus_count": result.consensus_count,
                "template_support": result.template_support, "stage_latency_json": {"primary": result.latency_ms},
                # Quarantine planar-flagged records from primary FAR/FRR; they
                # represent browser/edge disagreement, not baseline evidence.
                "edge_gate_status": result.edge_gate_status,
                "planar_spoof_flag": bool(result.planar_spoof_flag),
                "frame_scores_json": __import__("json").loads(evidence),
            })
        except Exception:
            self.logger.log("EXPERIMENT_LOGGER_FAILURE", {"source": "PRIMARY"})

    def preview(self, frames: Sequence[bytes], capture_context=None):
        self._validate_frames(frames)
        if len(frames) != 1:
            raise InferenceError("FRAME_COUNT", "preview requires exactly one frame")
        preview = getattr(self.matcher, "preview", None)
        if not callable(preview):
            raise InferenceError("MODEL_NOT_READY", "face detector is not available")
        with self._inference_lock:
            result = preview(frames[0], capture_context=capture_context) if capture_context is not None else preview(frames[0])
            capability = getattr(self.matcher, "guided_enrollment_capability", None)
            if callable(capability):
                result["guided_enrollment"] = capability()
            selection = getattr(self.matcher, "guided_selection_capability", None)
            if callable(selection):
                result["guided_selection"] = selection()
            adaptive = getattr(self.matcher, "guided_adaptive_capability", None)
            if callable(adaptive):
                result["guided_adaptive"] = adaptive()
            return result

    def detect_testing(self, frame: bytes, capture_context=None):
        self._validate_frames([frame])
        detector = getattr(self.matcher, "detect_evidence", None)
        if not callable(detector):
            raise InferenceError("MODEL_NOT_READY", "testing detector is not available")
        with self._inference_lock:
            return detector(frame, capture_context=capture_context) if capture_context is not None else detector(frame)

    def infer_testing(self, frame: bytes, roster: Sequence[Mapping[str, object]], capture_context=None):
        self._validate_frames([frame])
        if not isinstance(roster, (list, tuple)) or len(roster) > self.settings.max_candidates:
            raise InferenceError("INVALID_ROSTER", "testing candidate roster is invalid")
        candidates: Dict[str, Tuple[Tuple[float, ...], ...]] = {}
        for candidate in roster:
            if not isinstance(candidate, Mapping):
                raise InferenceError("INVALID_ROSTER", "testing candidate is invalid")
            ref = candidate.get("candidate_ref")
            raw_embeddings = candidate.get("embeddings")
            if raw_embeddings is None:
                legacy = candidate.get("embedding")
                raw_embeddings = [legacy] if isinstance(legacy, (list, tuple)) else None
            if not isinstance(ref, str) or not re.fullmatch(r"[A-Za-z0-9_-]{1,128}", ref):
                raise InferenceError("INVALID_CANDIDATE_REF", "candidate reference is invalid")
            if ref in candidates or not isinstance(raw_embeddings, (list, tuple)) or not raw_embeddings:
                raise InferenceError("INVALID_EMBEDDING", "candidate embedding is invalid")
            parsed = []
            for embedding in raw_embeddings:
                if not isinstance(embedding, (list, tuple)) or not embedding:
                    raise InferenceError("INVALID_EMBEDDING", "candidate embedding is invalid")
                try:
                    values = tuple(float(value) for value in embedding)
                except (TypeError, ValueError) as exc:
                    raise InferenceError("INVALID_EMBEDDING", "candidate embedding is not numeric") from exc
                if len(values) > self.settings.max_embedding_dimension or not all(math.isfinite(value) for value in values):
                    raise InferenceError("INVALID_EMBEDDING", "candidate embedding is invalid")
                parsed.append(values)
            candidates[ref] = tuple(parsed)
        infer = getattr(self.matcher, "infer_evidence", None)
        if not callable(infer):
            raise InferenceError("MODEL_NOT_READY", "testing inference is not available")
        with self._inference_lock:
            return infer(frame, candidates, capture_context=capture_context) if capture_context is not None else infer(frame, candidates)

    def delete_scan(self, scan_id: str) -> None:
        self._scans.pop(scan_id, None)
        self.logger.log("SCAN_DELETED", {"scan_id": scan_id})

    def scan_snapshot(self) -> Dict[str, int]:
        self._remove_expired()
        return {scan_id: len(scan.candidates) for scan_id, scan in self._scans.items()}

    def _active_scan(self, scan_id: str) -> _Scan:
        self._validate_scan_id(scan_id)
        scan = self._scans.get(scan_id)
        if scan is None:
            raise InferenceError("SCAN_NOT_FOUND", "scan is not available")
        if scan.expires_at <= self.clock():
            self._scans.pop(scan_id, None)
            raise InferenceError("SCAN_EXPIRED", "scan has expired")
        return scan

    def _remove_expired(self) -> None:
        now = self.clock()
        for scan_id, scan in list(self._scans.items()):
            if scan.expires_at <= now:
                self._scans.pop(scan_id, None)

    def _validate_frames(self, frames: Sequence[bytes]) -> None:
        if not isinstance(frames, (list, tuple)) or not frames:
            raise InferenceError("EMPTY_FRAMES", "at least one frame is required")
        if len(frames) > self.settings.max_frames:
            raise InferenceError("FRAME_COUNT_LIMIT", "attempt contains too many frames")
        for frame in frames:
            if not isinstance(frame, bytes) or not frame:
                raise InferenceError("INVALID_FRAME", "frame must be non-empty bytes")
            if len(frame) > self.settings.max_frame_bytes:
                raise InferenceError("FRAME_SIZE_LIMIT", "frame exceeds the size limit")

    def _validate_enrollment_frames(self, frames: Sequence[bytes]) -> None:
        if not isinstance(frames, (list, tuple)) or len(frames) < 7:
            raise InferenceError("FRAME_COUNT", "enrollment requires at least seven frames")
        if len(frames) > self.settings.max_enrollment_frames:
            raise InferenceError("FRAME_COUNT", "enrollment contains too many frames")
        for frame in frames:
            if not isinstance(frame, bytes) or not frame:
                raise InferenceError("INVALID_FRAME", "frame must be non-empty bytes")
            if len(frame) > self.settings.max_frame_bytes:
                raise InferenceError("FRAME_SIZE_LIMIT", "frame exceeds the size limit")

    @staticmethod
    def _validate_scan_id(scan_id: str) -> None:
        if not isinstance(scan_id, str) or not re.fullmatch(r"[A-Za-z0-9_-]{1,128}", scan_id):
            raise InferenceError("INVALID_SCAN_ID", "scan identifier is invalid")
