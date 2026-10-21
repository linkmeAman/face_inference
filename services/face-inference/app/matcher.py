"""Model adapters and conservative match decision logic."""

from __future__ import annotations

import hashlib
import math
import statistics
import time
from dataclasses import replace
from typing import Any, Dict, Mapping, Optional, Protocol, Sequence

try:
    from .detector import DetectorUnavailable, YuNetDetector
    from .encoder import EncoderUnavailable, SFaceEncoder
    from .face_selection import CaptureContextError, select_face, validate_capture_context
    from .pad import PadUnavailable, PassivePadClassifier, head_turn_passed
    from .quality import assess_face_quality, face_landmarks, pose_yaw_indicator, quality_flags
    from .schemas import MatchResult, QualityResult
    from .settings import Settings
except ImportError:  # direct imports used by the contract runner
    from detector import DetectorUnavailable, YuNetDetector  # type: ignore
    from encoder import EncoderUnavailable, SFaceEncoder  # type: ignore
    from face_selection import CaptureContextError, select_face, validate_capture_context  # type: ignore
    from pad import PadUnavailable, PassivePadClassifier, head_turn_passed  # type: ignore
    from quality import assess_face_quality, face_landmarks, pose_yaw_indicator, quality_flags  # type: ignore
    from schemas import MatchResult, QualityResult  # type: ignore
    from settings import Settings  # type: ignore


# Guided capture targets only; quality, identity and calibration limits stay unchanged.
GUIDED_POSE_PROTOCOL = "guided-five-v1"
GUIDED_SELECTION_PROTOCOL = "guided-five-select-v1"
GUIDED_ADAPTIVE_PROTOCOL = "guided-adaptive-v2"
GUIDED_CENTRE_YAW_MAX = 0.06
GUIDED_CENTRE_PITCH_MIN = 0.35
GUIDED_CENTRE_PITCH_MAX = 0.75
GUIDED_SIDE_YAW_MIN = 0.08
GUIDED_SIDE_YAW_MAX = 0.16
GUIDED_PITCH_TOLERANCE = 0.08
GUIDED_PITCH_DELTA_MIN = 0.05
GUIDED_PITCH_DELTA_MAX = 0.10
GUIDED_STABLE_MS = 300
GUIDED_POSES = ("centre", "left", "right", "up", "down")
GUIDED_BURST_A_COUNT = len(GUIDED_POSES)
GUIDED_BURST_B_COUNT = 3
GUIDED_TEMPLATE_COUNT = GUIDED_BURST_A_COUNT + 1
GUIDED_SELECTION_BURST_A_COUNT = GUIDED_BURST_A_COUNT * 2


class MatcherUnavailable(RuntimeError):
    pass


class Matcher(Protocol):
    def match(self, frames: Sequence[bytes], candidates: Mapping[str, Any], capture_contexts: Optional[Sequence[Mapping[str, Any]]] = None) -> MatchResult:
        ...


class UnavailableMatcher:
    """Safe no-model behavior: never accepts a face."""

    def __init__(self, model_version: str = "unconfigured") -> None:
        self.model_version = model_version

    def match(self, frames: Sequence[bytes], candidates: Mapping[str, Any], capture_contexts=None) -> MatchResult:
        return MatchResult(
            decision="REVIEW",
            quality=QualityResult(face_count=0),
            liveness="NOT_RUN",
            model_version=self.model_version,
        )

    def health(self) -> Dict[str, Any]:
        return {"model_ready": False, "model_version": self.model_version}

    def preview(self, frame: bytes, capture_context=None) -> Dict[str, Any]:
        return {
            "face_count": 0,
            "faces": [],
            "quality": QualityResult().to_dict(),
            "model_version": self.model_version,
        }

    def encode_reference(self, frame: bytes, capture_contexts=None) -> Dict[str, Any]:
        raise MatcherUnavailable("model is not available")

    def encode_enrollment_v2(self, burst_a: Sequence[bytes], burst_b: Sequence[bytes], reference_embedding: Sequence[float], capture_contexts_a=None, capture_contexts_b=None) -> Dict[str, Any]:
        raise MatcherUnavailable("model is not available")


class OnnxFaceMatcher:
    """YuNet + SFace matcher.

    The service starts in review-only mode unless an approved calibration file
    configures thresholds from held-out results. No model README threshold is
    used.
    """

    def __init__(self, settings: Settings) -> None:
        if settings.detector_model is None or settings.recognizer_model is None or settings.pad_model is None:
            raise MatcherUnavailable("model paths are not configured")
        try:
            self.detector = YuNetDetector(settings.detector_model)
            self.encoder = SFaceEncoder(settings.recognizer_model)
            self.pad = PassivePadClassifier(settings.pad_model)
            import cv2  # type: ignore
            import numpy as np  # type: ignore
        except (DetectorUnavailable, EncoderUnavailable, PadUnavailable, ImportError) as exc:
            raise MatcherUnavailable(str(exc)) from exc
        self.cv2 = cv2
        self.np = np
        self.settings = settings

    def _decode(self, frame: bytes):
        image = self.cv2.imdecode(self.np.frombuffer(frame, dtype=self.np.uint8), self.cv2.IMREAD_COLOR)
        if image is not None and max(image.shape[:2]) > 1280:
            scale = 1280.0 / float(max(image.shape[:2]))
            image = self.cv2.resize(
                image,
                (max(1, int(image.shape[1] * scale)), max(1, int(image.shape[0] * scale))),
                interpolation=self.cv2.INTER_AREA,
            )
        return image

    def _decode_with_geometry(self, frame: bytes, capture_context=None):
        original = self.cv2.imdecode(self.np.frombuffer(frame, dtype=self.np.uint8), self.cv2.IMREAD_COLOR)
        if original is None:
            return None, None
        original_height, original_width = original.shape[:2]
        image = original
        if max(original.shape[:2]) > 1280:
            scale = 1280.0 / float(max(original.shape[:2]))
            image = self.cv2.resize(
                original,
                (max(1, int(original_width * scale)), max(1, int(original_height * scale))),
                interpolation=self.cv2.INTER_AREA,
            )
        detector_height, detector_width = image.shape[:2]
        context = validate_capture_context(capture_context)
        geometry = {
            "original": {"width": original_width, "height": original_height},
            "detector": {"width": detector_width, "height": detector_height},
            "scale_x": round(detector_width / float(max(1, original_width)), 6),
            "scale_y": round(detector_height / float(max(1, original_height)), 6),
        }
        if context is not None:
            expected = context["frame"]
            # Browsers occasionally round metadata by one pixel; larger drift
            # means the guide cannot safely be mapped to this frame.
            if abs(expected["width"] - original_width) > 1 or abs(expected["height"] - original_height) > 1:
                raise CaptureContextError("capture_context frame dimensions do not match the decoded frame")
            geometry["display"] = context["display"]
            geometry["guide"] = context["guide"]
            geometry["mirrored_preview"] = context["mirrored_preview"]
            geometry["rotation"] = context["rotation"]
            geometry["capture_id"] = context["capture_id"]
            geometry["source"] = context["source"]
        return image, geometry

    @staticmethod
    def _context_at(capture_contexts, index):
        if capture_contexts is None:
            return None
        if isinstance(capture_contexts, Mapping):
            return capture_contexts
        if not isinstance(capture_contexts, (list, tuple)) or index >= len(capture_contexts):
            raise CaptureContextError("capture_contexts must match submitted frames")
        return capture_contexts[index]

    def _frame_features(self, frames: Sequence[bytes], include_pad: bool = False, strict_quality: bool = False, capture_contexts=None):
        features = []
        quality_values = []
        pad_scores = []
        pose_yaws = []
        evidences = []
        for frame_index, frame in enumerate(frames):
            try:
                context = self._context_at(capture_contexts, frame_index)
                image, geometry = self._decode_with_geometry(frame, context)
            except CaptureContextError as exc:
                quality_values.append(QualityResult())
                evidences.append({"accepted": False, "reasons": ["INVALID_CAPTURE_CONTEXT"], "stage": "GUIDE_SELECTION", "reason_code": "INVALID_CAPTURE_CONTEXT", "detail": str(exc)})
                continue
            if image is None:
                quality_values.append(QualityResult())
                evidences.append({"accepted": False, "reasons": ["IMAGE_DECODE_FAILED"], "stage": "DETECTION", "reason_code": "IMAGE_DECODE_FAILED"})
                continue
            faces = self.detector.detect(image)
            selection = select_face(faces, image.shape[1], image.shape[0], context)
            selected_index = selection["selected_face_index"]
            face = faces[selected_index] if selected_index is not None else None
            selected_count = 1 if face is not None else 0
            sharpness, exposure, pose = quality_flags(
                image, selected_count, face, guide=selection.get("guide"), detected_face_count=len(faces)
            )
            if face is not None:
                evidence = assess_face_quality(
                    image, face, 1,
                    guide=selection.get("guide"),
                    detected_face_count=len(faces),
                )
                evidence["stage"] = "QUALITY" if not evidence.get("accepted") else "MATCHING"
                evidence["reason_code"] = evidence.get("reasons", ["QUALITY_REJECTED"])[0] if not evidence.get("accepted") else "QUALITY_ACCEPTED"
            else:
                reason = selection["reason_code"]
                evidence = {"accepted": False, "reasons": [reason], "stage": "GUIDE_SELECTION" if len(faces) > 0 else "DETECTION", "reason_code": reason}
            evidence["face_count"] = len(faces)
            evidence["detected_face_count"] = len(faces)
            evidence["guide_candidate_count"] = selection["guide_candidate_count"]
            evidence["selected_face_index"] = selected_index
            evidence["selection"] = selection
            evidence["frame_geometry"] = geometry
            evidences.append(evidence)
            quality_values.append(QualityResult(
                len(faces), sharpness, exposure, pose,
                bool(evidence.get("size_ok", False)),
                bool(evidence.get("centred_ok", False)),
            ))
            if strict_quality:
                is_valid = (
                    face is not None
                    and evidence.get("accepted", False)
                    and evidence.get("inside_guide", False)
                    and sharpness
                    and exposure
                    and pose
                )
            else:
                is_valid = (
                    face is not None
                    and sharpness
                    and exposure
                    and pose
                    and bool(evidence.get("size_ok", False))
                    and bool(evidence.get("centred_ok", False))
                )

            if is_valid:
                evidence["encoded_feature_index"] = len(features)
                pose_yaws.append(pose_yaw_indicator(face))
                if include_pad:
                    try:
                        # anti-spoof-mn3 uses the upstream centered square crop
                        # expanded by bbox_inc=1.5. Thresholds remain unchanged.
                        pad_score = self.pad.real_probability(image, face)
                        pad_scores.append(pad_score)
                        evidence["pad_score"] = round(float(pad_score), 4)
                    except PadUnavailable:
                        pass
                features.append(self.encoder.encode(image, face))
        return features, quality_values, pad_scores, pose_yaws, evidences

    @staticmethod
    def _quality_summary(quality_values, frames_received: int, frames_accepted: int, evidences=None):
        evidences = evidences or []
        frames_rejected = max(0, frames_received - frames_accepted)
        rejection_rate = frames_rejected / float(max(1, frames_received))

        sharpness_ok = bool(quality_values) and all(item.sharpness_ok for item in quality_values)
        exposure_ok = bool(quality_values) and all(item.exposure_ok for item in quality_values)
        pose_ok = bool(quality_values) and all(item.pose_ok for item in quality_values)
        size_ok = bool(quality_values) and all(item.size_ok for item in quality_values)
        centred_ok = bool(quality_values) and all(item.centred_ok for item in quality_values)

        all_reasons = set()
        reason_counts = {}
        for ev in evidences:
            for r in ev.get("reasons", []):
                all_reasons.add(r)
                reason_counts[r] = reason_counts.get(r, 0) + 1

        sharpness_vals = [ev.get("sharpness", 0.0) for ev in evidences if "sharpness" in ev]
        exposure_vals = [ev.get("exposure", 0.0) for ev in evidences if "exposure" in ev]

        min_sharpness = min(sharpness_vals) if sharpness_vals else 0.0
        median_sharpness = float(statistics.median(sharpness_vals)) if sharpness_vals else 0.0
        min_exposure = min(exposure_vals) if exposure_vals else 0.0
        median_exposure = float(statistics.median(exposure_vals)) if exposure_vals else 0.0

        quality = QualityResult(
            face_count=max((item.face_count for item in quality_values), default=0),
            sharpness_ok=sharpness_ok,
            exposure_ok=exposure_ok,
            pose_ok=pose_ok,
            size_ok=size_ok,
            centred_ok=centred_ok,
        )
        return quality, {
            "frames_received": frames_received,
            "frames_accepted": frames_accepted,
            "frames_rejected": frames_rejected,
            "rejection_rate": round(rejection_rate, 4),
            "face_count": quality.face_count,
            "sharpness_ok": quality.sharpness_ok,
            "exposure_ok": quality.exposure_ok,
            "pose_ok": quality.pose_ok,
            "size_ok": quality.size_ok,
            "centred_ok": quality.centred_ok,
            "min_sharpness": round(min_sharpness, 2),
            "median_sharpness": round(median_sharpness, 2),
            "min_exposure": round(min_exposure, 2),
            "median_exposure": round(median_exposure, 2),
            "failure_reasons": sorted(list(all_reasons)),
            "reason_counts": reason_counts,
        }

    def _features(self, frames: Sequence[bytes], capture_contexts=None):
        features, quality_values, pad_scores, pose_yaws, evidences = self._frame_features(frames, include_pad=True, capture_contexts=capture_contexts)
        quality, _ = self._quality_summary(
            frames_received=len(frames),
            frames_accepted=len(features),
            quality_values=quality_values,
            evidences=evidences,
        )
        return features, quality, pad_scores, pose_yaws, evidences

    def _select_templates(self, features):
        target_count = getattr(self.settings, "enrollment_templates", 3)
        if target_count > 3:
            target_count = 3
        if len(features) <= target_count:
            return features
        centroid = self.np.mean(self.np.stack(features), axis=0)
        scored = sorted(range(len(features)), key=lambda index: self._cosine(features[index], centroid), reverse=True)
        inlier_indices = scored[: min(len(scored), max(target_count + 1, 6))]
        selected_indices = [inlier_indices[0]]
        while len(selected_indices) < target_count and len(selected_indices) < len(inlier_indices):
            candidate = max(
                (index for index in inlier_indices if index not in selected_indices),
                key=lambda index: min(
                    1.0 - self._cosine(features[index], features[chosen])
                    for chosen in selected_indices
                ),
            )
            selected_indices.append(candidate)
        return [features[index] for index in selected_indices]

    @staticmethod
    def _cosine(first: Any, second: Any) -> float:
        numerator = float((first * second).sum())
        denominator = math.sqrt(float((first * first).sum())) * math.sqrt(float((second * second).sum()))
        return numerator / denominator if denominator else -1.0

    def _candidate_templates(self, value: Any, feature_shape: Any):
        """Return compatible templates while accepting the legacy flat shape."""
        if isinstance(value, self.np.ndarray):
            raw = value.tolist()
        else:
            raw = value
        if not isinstance(raw, (list, tuple)) or not raw:
            return []
        values = raw if isinstance(raw[0], (list, tuple, self.np.ndarray)) else [raw]
        templates = []
        for item in values:
            candidate = self.np.asarray(item, dtype=self.np.float32)
            if candidate.shape == feature_shape and self.np.isfinite(candidate).all():
                templates.append(candidate)
        return templates

    def _score_identities(self, feature: Any, candidates: Mapping[str, Any]):
        scored = []
        support_threshold = float(getattr(self.settings, "template_support_threshold", 0.85))
        for candidate_ref, candidate_value in candidates.items():
            template_scores = sorted(
                (self._cosine(feature, template) for template in self._candidate_templates(candidate_value, feature.shape)),
                reverse=True,
            )
            if not template_scores:
                continue
            identity_score = (float(statistics.median(template_scores[:2]))
                              if len(template_scores) >= 2 else float(template_scores[0]))
            support = sum(1 for score in template_scores if score >= support_threshold)
            scored.append({
                "candidate_ref": candidate_ref,
                "score": identity_score,
                "support": support,
                "template_scores": template_scores,
            })
        return sorted(scored, key=lambda item: item["score"], reverse=True)

    def match(self, frames: Sequence[bytes], candidates: Mapping[str, Sequence[float]], capture_contexts=None) -> MatchResult:
        started = time.monotonic()
        res = self._features(frames, capture_contexts=capture_contexts)
        if len(res) >= 5:
            features, quality, pad_scores, pose_yaws, evidences = res[:5]
        else:
            features, quality, pad_scores, pose_yaws = res[:4]
            evidences = ()
        liveness = self.pad.classify(
            pad_scores,
            self.settings.pad_live_threshold,
            self.settings.pad_spoof_threshold,
        )
        liveness_score = statistics.median(pad_scores) if pad_scores else None
        liveness_reason = {
            "LIVE": "PASSIVE_PASS",
            "SPOOF": "PASSIVE_SPOOF",
            "CHALLENGE": "PASSIVE_BORDERLINE",
            "UNKNOWN": "PAD_CALIBRATION_OR_EVIDENCE_UNAVAILABLE",
        }.get(liveness, "PAD_UNKNOWN")
        for evidence in evidences:
            if "pad_score" not in evidence:
                continue
            score = float(evidence["pad_score"])
            if self.settings.pad_spoof_threshold is not None and score <= self.settings.pad_spoof_threshold:
                evidence["pad_state"] = "SPOOF"
            elif self.settings.pad_live_threshold is not None and score >= self.settings.pad_live_threshold:
                evidence["pad_state"] = "LIVE"
            else:
                evidence["pad_state"] = "CHALLENGE"
        pose_yaw = statistics.median(pose_yaws) if pose_yaws else None
        latency = int((time.monotonic() - started) * 1000)
        latest_evidence = evidences[-1] if evidences else {}
        result_geometry = latest_evidence.get("frame_geometry")
        result_selection = latest_evidence.get("selection")
        result_selected_index = latest_evidence.get("selected_face_index")
        if not features:
            decision = "REVIEW" if quality.face_count else "REJECT"
            reason_code = str(latest_evidence.get("reason_code", "NO_USABLE_FACE"))
            stage = str(latest_evidence.get("stage", "QUALITY" if quality.face_count else "DETECTION"))
            return MatchResult(decision=decision, identity_decision=decision, quality=quality, liveness=liveness, liveness_score=liveness_score,
                               liveness_reason=liveness_reason, pose_yaw=pose_yaw, pose_samples=tuple(pose_yaws),
                               model_version=self.settings.model_version, latency_ms=latency,
                               evidence_summary=tuple(evidences), stage=stage, reason_code=reason_code,
                               frame_geometry=result_geometry, selected_face_index=result_selected_index, selection=result_selection)

        # Removal of pose bypass: similarity can NEVER override failed quality
        quality_ok = (
            quality.sharpness_ok
            and quality.exposure_ok
            and quality.pose_ok
            and quality.size_ok
            and quality.centred_ok
        )
        accept_threshold = self.settings.accept_threshold or 0.90
        min_margin = self.settings.min_margin or 0.20
        min_accepted_frame = getattr(self.settings, "enrollment_holdout_min_threshold", 0.85)
        identity_calibrated = bool(self.settings.calibration_approved)

        if len(frames) != 3:
            return MatchResult(decision="REVIEW", identity_decision="REVIEW", quality=quality,
                               liveness=liveness, liveness_score=liveness_score,
                               liveness_reason=liveness_reason, pose_yaw=pose_yaw,
                               pose_samples=tuple(pose_yaws), model_version=self.settings.model_version,
                               latency_ms=latency, evidence_summary=tuple(evidences),
                               stage="QUALITY", reason_code="THREE_FRAMES_REQUIRED")

        # Attendance recognition accepts only independent, quality-valid frames
        # that unanimously select the same subject. Each subject is scored from
        # its individual templates; averaging templates erases useful identity
        # separation and lets one anomalous reference dominate the result.
        if len(frames) >= 3:
            if len(features) < 3 or len(features) != len(frames):
                return MatchResult(decision="REVIEW", identity_decision="REVIEW", quality=quality, liveness=liveness, liveness_score=liveness_score,
                                   liveness_reason=liveness_reason, pose_yaw=pose_yaw, pose_samples=tuple(pose_yaws),
                                   model_version=self.settings.model_version, latency_ms=latency,
                                   evidence_summary=tuple(evidences), stage="QUALITY", reason_code="INSUFFICIENT_QUALITY_CONSENSUS",
                                   frame_geometry=result_geometry, selected_face_index=result_selected_index, selection=result_selection)

            frame_rankings = [self._score_identities(feature, candidates) for feature in features]
            frame_template_scores = tuple({
                f"{candidate_ref}#{template_index}": float(self._cosine(feature, template))
                for candidate_ref, candidate_value in candidates.items()
                for template_index, template in enumerate(self._candidate_templates(candidate_value, feature.shape))
            } for feature in features)
            if any(not ranking for ranking in frame_rankings):
                return MatchResult(decision="REVIEW", identity_decision="REVIEW", quality=quality, liveness=liveness, liveness_score=liveness_score,
                                   liveness_reason=liveness_reason, pose_yaw=pose_yaw, pose_samples=tuple(pose_yaws),
                                   model_version=self.settings.model_version, latency_ms=latency,
                                   evidence_summary=tuple(evidences), stage="MATCHING", reason_code="NO_COMPATIBLE_CANDIDATES",
                                   frame_geometry=result_geometry, selected_face_index=result_selected_index, selection=result_selection)

            identity_scores = {}
            identity_support = {}
            for ranking in frame_rankings:
                for item in ranking:
                    ref = item["candidate_ref"]
                    identity_scores.setdefault(ref, []).append(item["score"])
                    identity_support.setdefault(ref, []).append(item["support"])
            aggregate = sorted(
                ((float(statistics.median(scores)), ref) for ref, scores in identity_scores.items()
                 if len(scores) == len(features)),
                reverse=True,
            )
            winner_score, winner_ref = aggregate[0]
            second_score = aggregate[1][0] if len(aggregate) > 1 else None
            margin = winner_score - second_score if second_score is not None else None
            unanimous = all(ranking[0]["candidate_ref"] == winner_ref for ranking in frame_rankings)
            vote_count = sum(1 for ranking in frame_rankings if ranking[0]["candidate_ref"] == winner_ref)
            min_top1 = min(identity_scores[winner_ref])
            min_support = min(identity_support[winner_ref])
            required_support = max(2, int(getattr(self.settings, "min_template_support", 1)))
            is_single_candidate = len(aggregate) == 1
            single_threshold = getattr(self.settings, "single_candidate_accept_threshold", None)
            threshold = float(single_threshold) if is_single_candidate and single_threshold is not None else accept_threshold
            single_uncalibrated = is_single_candidate and single_threshold is None
            identity_accepted = (
                unanimous
                and quality_ok
                and identity_calibrated
                and not single_uncalibrated
                and winner_score >= threshold
                and min_top1 >= min_accepted_frame
                and min_support >= required_support
                and (margin is None or margin >= min_margin)
            )

            if not identity_calibrated:
                reason_code = "CALIBRATION_REQUIRED"
            elif not unanimous:
                reason_code = "IDENTITY_CONSENSUS_FAILED"
            elif min_support < required_support:
                reason_code = "INSUFFICIENT_TEMPLATE_SUPPORT"
            elif single_uncalibrated:
                reason_code = "SINGLE_CANDIDATE_UNCALIBRATED"
            elif winner_score < threshold or min_top1 < min_accepted_frame:
                reason_code = "IDENTITY_MISMATCH"
            elif margin is not None and margin < min_margin:
                reason_code = "AMBIGUOUS_IDENTITY"
            else:
                reason_code = "IDENTITY_MATCH"
            stage = "MATCHING"
            if identity_accepted and liveness != "LIVE":
                stage = "PAD"
                reason_code = liveness_reason

            return MatchResult(
                decision="ACCEPT" if identity_accepted else "REVIEW",
                identity_decision="ACCEPT" if identity_accepted else "REVIEW",
                candidate_ref=winner_ref if identity_accepted else None,
                top1_score=winner_score,
                top2_score=second_score,
                margin=margin,
                quality=quality,
                liveness=liveness,
                liveness_score=liveness_score,
                liveness_reason=liveness_reason,
                pose_yaw=pose_yaw,
                pose_samples=tuple(pose_yaws),
                model_version=self.settings.model_version,
                latency_ms=latency,
                consensus_count=vote_count,
                template_support=min_support,
                evidence_summary=tuple(evidences),
                comparison_scores=tuple({"candidate_ref": ref, "score": score} for score, ref in aggregate),
                frame_template_scores=frame_template_scores,
                stage=stage,
                reason_code=reason_code,
                frame_geometry=result_geometry,
                selected_face_index=result_selected_index,
                selection=result_selection,
            )
        else:
            probe = self.np.mean(self.np.stack(features), axis=0)
            scores = self._score_identities(probe, candidates)
            if not scores:
                return MatchResult(decision="REVIEW", identity_decision="REVIEW", quality=quality, liveness=liveness, liveness_score=liveness_score,
                                   liveness_reason=liveness_reason, pose_yaw=pose_yaw, pose_samples=tuple(pose_yaws),
                                   model_version=self.settings.model_version, latency_ms=latency,
                                   evidence_summary=tuple(evidences), stage="MATCHING", reason_code="NO_COMPATIBLE_CANDIDATES",
                                   frame_geometry=result_geometry, selected_face_index=result_selected_index, selection=result_selection)

            top1 = scores[0]["score"]
            candidate_ref = scores[0]["candidate_ref"]
            support = scores[0]["support"]
            top2 = scores[1]["score"] if len(scores) > 1 else None
            margin = top1 - top2 if top2 is not None else None
            required_support = max(2, int(getattr(self.settings, "min_template_support", 1)))
            single_threshold = getattr(self.settings, "single_candidate_accept_threshold", None)
            single_uncalibrated = len(scores) == 1 and single_threshold is None
            threshold = float(single_threshold) if len(scores) == 1 and single_threshold is not None else accept_threshold
            identity_accepted = (
                top1 >= threshold
                and identity_calibrated
                and support >= required_support
                and not single_uncalibrated
                and (margin is None or margin >= min_margin)
                and quality_ok
            )
            stage = "MATCHING"
            if not identity_calibrated:
                reason_code = "CALIBRATION_REQUIRED"
            elif support < required_support:
                reason_code = "INSUFFICIENT_TEMPLATE_SUPPORT"
            elif single_uncalibrated:
                reason_code = "SINGLE_CANDIDATE_UNCALIBRATED"
            elif margin is not None and margin < min_margin:
                reason_code = "AMBIGUOUS_IDENTITY"
            else:
                reason_code = "IDENTITY_MATCH" if identity_accepted else "IDENTITY_MISMATCH"
            if identity_accepted and liveness != "LIVE":
                stage = "PAD"
                reason_code = liveness_reason
            return MatchResult(
                decision="ACCEPT" if identity_accepted else "REVIEW",
                identity_decision="ACCEPT" if identity_accepted else "REVIEW",
                candidate_ref=candidate_ref if identity_accepted else None,
                top1_score=top1,
                top2_score=top2,
                margin=margin,
                quality=quality,
                liveness=liveness,
                liveness_score=liveness_score,
                liveness_reason=liveness_reason,
                pose_yaw=pose_yaw,
                pose_samples=tuple(pose_yaws),
                model_version=self.settings.model_version,
                latency_ms=latency,
                consensus_count=1 if identity_accepted else 0,
                template_support=support,
                evidence_summary=tuple(evidences),
                comparison_scores=tuple({"candidate_ref": item["candidate_ref"], "score": item["score"]} for item in scores),
                stage=stage,
                reason_code=reason_code,
                frame_geometry=result_geometry,
                selected_face_index=result_selected_index,
                selection=result_selection,
            )

    def match_challenge(
        self,
        frames: Sequence[bytes],
        candidates: Mapping[str, Sequence[float]],
        challenge_type: str,
        baseline_yaw: float,
        candidate_ref: str,
        capture_contexts=None,
    ) -> MatchResult:
        result = self.match(frames, candidates, capture_contexts=capture_contexts)
        passed = (
            result.decision == "ACCEPT"
            and result.candidate_ref == candidate_ref
            and result.liveness in {"LIVE", "CHALLENGE"}
            and head_turn_passed(
                challenge_type,
                baseline_yaw,
                result.pose_samples,
                self.settings.challenge_head_turn_delta,
            )
        )
        if passed:
            return replace(result, decision="ACCEPT", identity_decision="ACCEPT", liveness="LIVE",
                           liveness_reason="ACTIVE_CHALLENGE_PASSED", stage="PAD", reason_code="ACTIVE_CHALLENGE_PASSED")
        liveness = "SPOOF" if result.liveness == "SPOOF" else "UNKNOWN"
        return replace(result, decision="REJECT" if liveness == "SPOOF" else "REVIEW",
                       candidate_ref=None if liveness == "SPOOF" else result.candidate_ref,
                       liveness=liveness, liveness_reason="ACTIVE_CHALLENGE_FAILED",
                       stage="PAD", reason_code="ACTIVE_CHALLENGE_FAILED")

    def preview(self, frame: bytes, capture_context=None) -> Dict[str, Any]:
        started = time.monotonic()
        image, geometry = self._decode_with_geometry(frame, capture_context)
        if image is None:
            quality = QualityResult()
            return {
                "face_count": 0,
                "faces": [],
                "quality": quality.to_dict(),
                "model_version": self.settings.model_version,
                "latency_ms": int((time.monotonic() - started) * 1000),
                "stage": "DETECTION",
                "reason_code": "IMAGE_DECODE_FAILED",
                "frame_geometry": None,
            }
        faces = self.detector.detect(image)
        selection = select_face(faces, image.shape[1], image.shape[0], capture_context)
        selected_index = selection["selected_face_index"]
        primary = faces[selected_index] if selected_index is not None else None
        selected_count = 1 if primary is not None else 0
        sharpness, exposure, pose = quality_flags(
            image, selected_count, primary, guide=selection.get("guide"), detected_face_count=len(faces)
        )
        evidence = assess_face_quality(image, primary, 1, guide=selection.get("guide"), detected_face_count=len(faces)) if primary is not None else {}
        boxes = []
        for index, face in enumerate(faces):
            box = {
                "x": float(face[0]), "y": float(face[1]),
                "width": float(face[2]), "height": float(face[3]),
            }
            normalized = selection["faces"][index]["box"] if selection.get("faces") else {
                "x": box["x"] / image.shape[1], "y": box["y"] / image.shape[0],
                "width": box["width"] / image.shape[1], "height": box["height"] / image.shape[0],
            }
            boxes.append(dict(
                box, box=box, normalized_box=normalized, landmarks=face_landmarks(face),
                selected=index == selected_index,
                quality=assess_face_quality(image, face, 1, guide=selection.get("guide"), detected_face_count=len(faces)),
            ))
        reason_code = selection["reason_code"] if primary is None else ("QUALITY_ACCEPTED" if evidence.get("accepted") else (evidence.get("reasons") or ["QUALITY_REJECTED"])[0])
        stage = "GUIDE_SELECTION" if primary is None and len(faces) > 0 else ("DETECTION" if primary is None else "QUALITY")
        return {
            "face_count": len(faces),
            "detected_face_count": len(faces),
            "guide_candidate_count": selection["guide_candidate_count"],
            "faces": boxes,
            "quality": QualityResult(
                len(faces), sharpness, exposure, pose,
                bool(evidence.get("size_ok", False)),
                bool(evidence.get("centred_ok", False)),
            ).to_dict(),
            "model_version": self.settings.model_version,
            "latency_ms": int((time.monotonic() - started) * 1000),
            "frame_geometry": geometry,
            "selected_face_index": selected_index,
            "selection": selection,
            "stage": stage,
            "reason_code": reason_code,
        }

    def detect_evidence(self, frame: bytes, capture_context=None) -> Dict[str, Any]:
        return self.preview(frame, capture_context=capture_context)

    def infer_evidence(self, frame: bytes, candidates: Mapping[str, Sequence[float]], capture_context=None) -> Dict[str, Any]:
        started = time.monotonic()
        image, geometry = self._decode_with_geometry(frame, capture_context)
        if image is None:
            return {"face_count": 0, "faces": [], "embedding": None, "matches": [], "top_match": None,
                    "margin": None, "model_version": self.settings.model_version,
                    "latency_ms": int((time.monotonic() - started) * 1000)}
        faces = self.detector.detect(image)
        selection = select_face(faces, image.shape[1], image.shape[0], capture_context)
        selected_index = selection["selected_face_index"]
        face_items = []
        for index, face in enumerate(faces):
            box = {"x": float(face[0]), "y": float(face[1]), "width": float(face[2]), "height": float(face[3])}
            normalized = selection["faces"][index]["box"] if selection.get("faces") else None
            face_items.append(dict(box=box, normalized_box=normalized, selected=index == selected_index,
                                   landmarks=face_landmarks(face), quality=assess_face_quality(image, face, 1, guide=selection.get("guide"), detected_face_count=len(faces))))
        if selected_index is None or not face_items[selected_index]["quality"]["accepted"]:
            return {"face_count": len(faces), "faces": face_items, "embedding": None, "matches": [],
                    "top_match": None, "margin": None, "model_version": self.settings.model_version,
                    "latency_ms": int((time.monotonic() - started) * 1000), "frame_geometry": geometry,
                    "selected_face_index": selected_index, "selection": selection,
                    "stage": "GUIDE_SELECTION" if selected_index is None else "QUALITY",
                    "reason_code": selection["reason_code"] if selected_index is None else (face_items[selected_index]["quality"].get("reasons") or ["QUALITY_REJECTED"])[0]}
        feature = self.encoder.encode(image, faces[selected_index])
        norm = float(self.np.linalg.norm(feature))
        normalized = feature / norm if norm else feature
        # Use the same subject-level top-two-template median and support
        # measure as attendance.  Preview consumers must never convert a
        # single lucky template score into an identity decision.
        scored_identities = self._score_identities(normalized, candidates)
        # The setup API needs only the winning subject's scalar score and
        # template-support count.  Never return per-template score vectors.
        matches = [{"candidate_ref": item["candidate_ref"], "score": item["score"], "support": item["support"]}
                   for item in scored_identities]
        top1 = matches[0]["score"] if matches else None
        top2 = matches[1]["score"] if len(matches) > 1 else 0.0
        return {
            "face_count": len(faces),
            "detected_face_count": len(faces),
            "guide_candidate_count": selection["guide_candidate_count"],
            "faces": face_items,
            "embedding": normalized.astype(self.np.float32).tolist(),
            "matches": matches,
            "top_match": matches[0] if matches else None,
            "margin": top1 - top2 if top1 is not None else None,
            "model_version": self.settings.model_version,
            "latency_ms": int((time.monotonic() - started) * 1000),
            "frame_geometry": geometry,
            "selected_face_index": selected_index,
            "selection": selection,
            "stage": "MATCHING",
            "reason_code": "IDENTITY_MATCH" if matches else "NO_COMPATIBLE_CANDIDATES",
        }

    def encode_enrollment(self, frames: Sequence[bytes], capture_contexts=None):
        features, quality_values, _, _, evidences = self._frame_features(frames, strict_quality=True, capture_contexts=capture_contexts)
        invalid_context = next((ev for ev in evidences if ev.get("reason_code") == "INVALID_CAPTURE_CONTEXT"), None)
        if invalid_context is not None:
            raise MatcherUnavailable(f"INVALID_CAPTURE_CONTEXT: {invalid_context.get('detail', 'capture geometry is invalid')}")
        quality, summary = self._quality_summary(
            quality_values=quality_values,
            frames_received=len(frames),
            frames_accepted=len(features),
            evidences=evidences,
        )
        if len(features) < 5 or not (quality.sharpness_ok and quality.exposure_ok and quality.pose_ok):
            raise MatcherUnavailable("usable enrollment faces were not acquired")

        # Identity consistency verification: guarantee that all accepted frames
        # represent the exact same person with pairwise similarity >= 0.85
        for i in range(len(features)):
            for j in range(i + 1, len(features)):
                if self._cosine(features[i], features[j]) < 0.85:
                    raise MatcherUnavailable("detected facial data points across frames are inconsistent or from different people")

        training = features[:-1]
        verification = features[-1]
        templates = self._select_templates(training)
        probe = self.np.mean(self.np.stack(templates), axis=0)
        verification_score = self._cosine(probe, verification)
        summary["verification_score"] = verification_score
        threshold = self.settings.enrollment_verify_threshold
        verification_passed = threshold is not None and verification_score >= threshold
        return {
            "model_version_id": self.settings.model_version_id,
            "model_version": self.settings.model_version,
            "embeddings": [item.astype(self.np.float32).tolist() for item in templates],
            "embedding_dimension": int(templates[0].shape[0]),
            "quality_summary": summary,
            "verification_passed": verification_passed,
            "verification_reason": "SAME_DAY_MATCH" if verification_passed else "VERIFY_THRESHOLD_NOT_MET",
            "evidences": list(evidences),
            "stage": "ENROLLMENT_VALIDATION",
            "reason_code": "ENROLLMENT_ACCEPTED" if verification_passed else "VERIFY_THRESHOLD_NOT_MET",
        }

    def encode_reference(self, frame_or_frames: Any, capture_contexts=None) -> Dict[str, Any]:
        frames = list(frame_or_frames) if isinstance(frame_or_frames, (list, tuple)) else [frame_or_frames]
        if not frames:
            raise MatcherUnavailable("IMAGE_DECODE_FAILED: Reference portrait could not be decoded")

        candidates = []
        failure_reasons = []
        face_count_mismatch = 0

        for frame_index, f in enumerate(frames):
            try:
                context = self._context_at(capture_contexts, frame_index)
                image, geometry = self._decode_with_geometry(f, context)
            except CaptureContextError as exc:
                raise MatcherUnavailable(f"INVALID_CAPTURE_CONTEXT: {exc}") from exc
            if image is None:
                failure_reasons.append("IMAGE_DECODE_FAILED")
                continue
            detected_faces = self.detector.detect(image)
            selection = select_face(detected_faces, image.shape[1], image.shape[0], context)
            selected_index = selection["selected_face_index"]
            if selected_index is None:
                face_count_mismatch += 1
                failure_reasons.append(selection["reason_code"])
                continue
            face = detected_faces[selected_index]
            evidence = assess_face_quality(image, face, 1, guide=selection.get("guide"), detected_face_count=len(detected_faces))
            evidence["face_count"] = len(detected_faces)
            evidence["selected_face_index"] = selected_index
            evidence["selection"] = selection
            evidence["frame_geometry"] = geometry
            if not evidence.get("accepted", False):
                reasons = ", ".join(evidence.get("reasons", ["QUALITY_REJECTED"]))
                failure_reasons.append(reasons)
                continue
            pad_score = None
            try:
                pad_score = float(self.pad.real_probability(image, face))
            except PadUnavailable:
                pass
            candidates.append({
                "frame_index": frame_index,
                "image": image,
                "face": face,
                "evidence": evidence,
                "pad_score": pad_score,
                "sharpness": float(evidence.get("sharpness", 0.0)),
            })

        if not candidates:
            if face_count_mismatch > 0 and len(failure_reasons) == face_count_mismatch:
                guide_reason = next((reason for reason in failure_reasons if reason in {
                    "NO_FACE_IN_GUIDE", "MULTIPLE_FACES_IN_GUIDE", "NO_FACE",
                }), None)
                if guide_reason is not None:
                    raise MatcherUnavailable(f"{guide_reason}: Reference portrait has no unambiguous target face in the guide")
                raise MatcherUnavailable("SINGLE_FACE_REQUIRED: Exactly one face required for reference portrait")
            err_details = ", ".join(dict.fromkeys(failure_reasons)) if failure_reasons else "QUALITY_REJECTED"
            raise MatcherUnavailable(f"REFERENCE_QUALITY_FAILED: {err_details}")

        pad_scores = [c["pad_score"] for c in candidates if c["pad_score"] is not None]
        import statistics
        consensus_pad = float(statistics.median(pad_scores)) if pad_scores else None

        best = max(candidates, key=lambda c: c["sharpness"])
        raw_feature = self.encoder.encode(best["image"], best["face"])
        norm = float(self.np.linalg.norm(raw_feature))
        normalized = raw_feature / norm if norm else raw_feature

        evidence = best["evidence"]
        pose_ok = bool(evidence.get("facing_camera", False) and evidence.get("profile_ok", False))
        quality = {
            "sharpness_ok": bool(evidence.get("sharpness_ok", False)),
            "exposure_ok": bool(evidence.get("exposure_ok", False)),
            "pose_ok": pose_ok,
            "size_ok": bool(evidence.get("size_ok", False)),
            "centred_ok": bool(evidence.get("centred_ok", False)),
            "reasons": evidence.get("reasons", []),
        }
        pad_state = "UNKNOWN"
        if consensus_pad is not None and self.settings.pad_spoof_threshold is not None and consensus_pad <= self.settings.pad_spoof_threshold:
            pad_state = "SPOOF"
        elif consensus_pad is not None and self.settings.pad_live_threshold is not None and consensus_pad >= self.settings.pad_live_threshold:
            pad_state = "LIVE"
        elif consensus_pad is not None and self.settings.pad_live_threshold is not None and self.settings.pad_spoof_threshold is not None:
            pad_state = "CHALLENGE"
        pad = {
            "state": pad_state,
            "score": round(consensus_pad, 4) if consensus_pad is not None else None,
            "samples": [round(s, 4) for s in pad_scores],
        }

        return {
            "model_version_id": self.settings.model_version_id,
            "model_version": self.settings.model_version,
            "policy_version": self.settings.policy_version,
            "embedding": normalized.astype(self.np.float32).tolist(),
            "embedding_dimension": int(normalized.shape[0]),
            "quality": quality,
            "quality_evidence": evidence,
            "pad": pad,
            "pad_score": round(consensus_pad, 4) if consensus_pad is not None else None,
            "frame_geometry": best["evidence"].get("frame_geometry"),
            "selected_frame_index": best["frame_index"],
            "selected_face_index": best["evidence"].get("selected_face_index"),
            "selection": best["evidence"].get("selection"),
            "stage": "ENROLLMENT_VALIDATION",
            "reason_code": "REFERENCE_ACCEPTED",
        }

    @staticmethod
    def guided_enrollment_capability() -> Dict[str, Any]:
        return {"pose_protocol": GUIDED_POSE_PROTOCOL, "template_count": GUIDED_TEMPLATE_COUNT,
                "windows": {"centre_yaw_max": GUIDED_CENTRE_YAW_MAX,
                            "centre_pitch_min": GUIDED_CENTRE_PITCH_MIN,
                            "centre_pitch_max": GUIDED_CENTRE_PITCH_MAX,
                            "side_yaw_min": GUIDED_SIDE_YAW_MIN, "side_yaw_max": GUIDED_SIDE_YAW_MAX,
                            "pitch_tolerance": GUIDED_PITCH_TOLERANCE,
                            "pitch_delta_min": GUIDED_PITCH_DELTA_MIN,
                            "pitch_delta_max": GUIDED_PITCH_DELTA_MAX, "stable_ms": GUIDED_STABLE_MS}}

    @staticmethod
    def guided_selection_capability() -> Dict[str, Any]:
        capability = OnnxFaceMatcher.guided_enrollment_capability()
        return {**capability, "pose_protocol": GUIDED_SELECTION_PROTOCOL,
                "burst_a_count": GUIDED_SELECTION_BURST_A_COUNT,
                "burst_b_count": GUIDED_BURST_B_COUNT, "samples_per_pose": 2}

    @staticmethod
    def guided_adaptive_capability() -> Dict[str, Any]:
        capability = OnnxFaceMatcher.guided_enrollment_capability()
        return {**capability, "pose_protocol": GUIDED_ADAPTIVE_PROTOCOL,
                "burst_a_count": 5, "burst_b_count": 3, "stable_ms": 300,
                "departure_ms": 750, "template_count": 6}

    def _encode_guided_adaptive(self, burst_a, burst_b, reference_embedding,
                                capture_contexts_a=None, capture_contexts_b=None):
        if len(burst_a) != 5 or len(burst_b) != 3:
            raise MatcherUnavailable("FRAME_COUNT: Adaptive enrollment requires five plus three frames")
        digests = [hashlib.sha256(frame).digest() for frame in list(burst_a) + list(burst_b)]
        if len(set(digests)) != 8:
            raise MatcherUnavailable("REPLAYED_FRAMES: Every enrollment frame must be fresh")
        features_a, q_a, _, _, ev_a = self._frame_features(burst_a, strict_quality=True,
                                                            capture_contexts=capture_contexts_a)
        features_b, q_b, _, _, ev_b = self._frame_features(burst_b, strict_quality=True,
                                                            capture_contexts=capture_contexts_b)
        if len(features_a) != 5 or len(features_b) != 3:
            failed = next((ev for ev in ev_a + ev_b if not ev.get("accepted") or "encoded_feature_index" not in ev), {})
            reason = failed.get("reason_code", "QUALITY_REJECTED")
            raise MatcherUnavailable(f"ENROLLMENT_QUALITY_REJECTED: {reason}")
        poses = []
        for index, ev in enumerate(ev_a + ev_b):
            yaw, pitch = ev.get("pose_yaw"), ev.get("pose_pitch")
            if any(isinstance(v, bool) or not isinstance(v, (int, float)) or not math.isfinite(v)
                   for v in (yaw, pitch)):
                raise MatcherUnavailable("POSE_MEASUREMENTS_MISSING: Server pose is unavailable")
            centre = index in (0, 1, 5, 6, 7)
            if centre:
                valid = (abs(yaw) <= GUIDED_CENTRE_YAW_MAX
                         and GUIDED_CENTRE_PITCH_MIN <= pitch <= GUIDED_CENTRE_PITCH_MAX)
                pose = "centre"
            else:
                signed = -yaw if index == 2 else yaw if index == 3 else abs(yaw)
                valid = (GUIDED_SIDE_YAW_MIN <= signed <= GUIDED_SIDE_YAW_MAX
                         and GUIDED_CENTRE_PITCH_MIN - GUIDED_PITCH_TOLERANCE <= pitch
                         <= GUIDED_CENTRE_PITCH_MAX + GUIDED_PITCH_TOLERANCE)
                pose = "left" if index == 2 else "right" if index == 3 else ("left" if yaw < 0 else "right")
            if not valid:
                raise MatcherUnavailable(f"POSE_TARGET_MISMATCH: {pose} at observation {index + 1}")
            poses.append({"burst": "BURST_A" if index < 5 else "BURST_B",
                          "index": index if index < 5 else index - 5,
                          "pose": pose, "yaw": yaw, "pitch": pitch})
        reference = self.np.asarray(reference_embedding, dtype=self.np.float32)
        norm = float(self.np.linalg.norm(reference))
        if not norm:
            raise MatcherUnavailable("INVALID_REFERENCE: Reference embedding is empty")
        reference /= norm
        scores_a = [self._cosine(feature, reference) for feature in features_a]
        scores_b = [self._cosine(feature, reference) for feature in features_b]
        ref_limit = getattr(self.settings, "verified_reference_threshold", 0.90)
        if min(scores_a + scores_b) < ref_limit:
            raise MatcherUnavailable("REFERENCE_MISMATCH: Frame differs from verified reference")
        consistency = getattr(self.settings, "cross_frame_consistency_threshold", 0.85)
        for group in (features_a, features_b):
            if any(self._cosine(group[i], group[j]) < consistency
                   for i in range(len(group)) for j in range(i + 1, len(group))):
                raise MatcherUnavailable("CROSS_FRAME_INCONSISTENT: Candidate identities disagree")
        holdout = [self._cosine(fb, fa) for fb in features_b for fa in features_a]
        median_holdout, min_holdout = float(statistics.median(holdout)), min(holdout)
        if (median_holdout < getattr(self.settings, "enrollment_holdout_median_threshold", 0.90)
                or min_holdout < getattr(self.settings, "enrollment_holdout_min_threshold", 0.85)):
            raise MatcherUnavailable("HOLDOUT_FAILED: Verification differs from enrollment frames")
        centroid = self.np.mean(self.np.stack(features_b), axis=0)
        best_b = max(range(3), key=lambda i: self._cosine(features_b[i], centroid))
        selected = list(features_a) + [features_b[best_b]]
        _, summary_a = self._quality_summary(q_a, 5, 5, ev_a)
        _, summary_b = self._quality_summary(q_b, 3, 3, ev_b)
        return {"pose_protocol": GUIDED_ADAPTIVE_PROTOCOL,
                "model_version_id": self.settings.model_version_id,
                "model_version": self.settings.model_version,
                "policy_version": self.settings.policy_version,
                "embeddings": [item.astype(self.np.float32).tolist() for item in selected],
                "template_sources": ["BURST_A"] * 5 + ["BURST_B"],
                "burst_a_embeddings": [item.astype(self.np.float32).tolist() for item in features_a],
                "burst_b_embeddings": [item.astype(self.np.float32).tolist() for item in features_b],
                "burst_b_template_index": best_b,
                "embedding_dimension": int(selected[0].shape[0]),
                "burst_a_count": 5, "burst_b_count": 3,
                "reference_match_scores": {"burst_a": [round(s, 4) for s in scores_a],
                                           "burst_b": [round(s, 4) for s in scores_b]},
                "holdout_metrics": {"median": round(median_holdout, 4), "min": round(min_holdout, 4), "passed": True},
                "quality_summary": {"burst_a": summary_a, "burst_b": summary_b,
                                    "frames_accepted": 8, "selected_templates": 6,
                                    "accepted_poses": poses},
                "evidences": {"burst_a": ev_a, "burst_b": ev_b},
                "verification_passed": True, "verification_reason": "TWO_BURST_VERIFIED",
                "stage": "ENROLLMENT_VALIDATION", "reason_code": "TWO_BURST_VERIFIED"}

    @staticmethod
    def _validate_guided_poses(evidences_a, evidences_b):
        # Evidence is generated by _frame_features from decoded pixels and server
        # landmarks. Client pose labels/measurements are deliberately never read.
        if len(evidences_a) != GUIDED_BURST_A_COUNT or len(evidences_b) != GUIDED_BURST_B_COUNT:
            raise MatcherUnavailable("POSE_MEASUREMENTS_MISSING: All eight observations are required")
        accepted = []
        centre_pitch = None
        for index, evidence in enumerate(evidences_a + evidences_b):
            yaw, pitch = evidence.get("pose_yaw"), evidence.get("pose_pitch")
            if any(isinstance(value, bool) or not isinstance(value, (int, float)) or not math.isfinite(value)
                   for value in (yaw, pitch)):
                raise MatcherUnavailable("POSE_MEASUREMENTS_MISSING: Numeric yaw and pitch are required")
            pose = GUIDED_POSES[index] if index < GUIDED_BURST_A_COUNT else "centre"
            if index == 0:
                centre_pitch = pitch
            # The quality assessor reports three decimal places; subtraction in
            # that same precision avoids rejecting an exact boundary by float error.
            delta = round(pitch - centre_pitch, 3)
            if pose == "centre":
                valid = (abs(yaw) <= GUIDED_CENTRE_YAW_MAX
                         and GUIDED_CENTRE_PITCH_MIN <= pitch <= GUIDED_CENTRE_PITCH_MAX
                         and abs(delta) <= GUIDED_PITCH_TOLERANCE)
            elif pose in ("left", "right"):
                signed_yaw = -yaw if pose == "left" else yaw
                valid = (GUIDED_SIDE_YAW_MIN <= signed_yaw <= GUIDED_SIDE_YAW_MAX
                         and abs(delta) <= GUIDED_PITCH_TOLERANCE)
            else:
                displacement = -delta if pose == "up" else delta
                valid = (abs(yaw) <= GUIDED_CENTRE_YAW_MAX
                         and GUIDED_PITCH_DELTA_MIN <= displacement <= GUIDED_PITCH_DELTA_MAX)
            if not valid:
                raise MatcherUnavailable(f"POSE_TARGET_MISMATCH: {pose} at observation {index + 1}")
            accepted.append({"burst": "BURST_A" if index < GUIDED_BURST_A_COUNT else "BURST_B",
                             "index": index if index < GUIDED_BURST_A_COUNT else index - GUIDED_BURST_A_COUNT,
                             "pose": pose, "yaw": yaw, "pitch": pitch})
        return accepted

    def encode_enrollment_v2(self, burst_a: Sequence[bytes], burst_b: Sequence[bytes], reference_embedding: Sequence[float], capture_contexts_a=None, capture_contexts_b=None, pose_protocol=None) -> Dict[str, Any]:
        if pose_protocol == GUIDED_ADAPTIVE_PROTOCOL:
            return self._encode_guided_adaptive(burst_a, burst_b, reference_embedding,
                                                capture_contexts_a, capture_contexts_b)
        if pose_protocol == GUIDED_SELECTION_PROTOCOL:
            return self._encode_guided_selection(burst_a, burst_b, reference_embedding,
                                                 capture_contexts_a, capture_contexts_b)
        if pose_protocol is not None and pose_protocol != GUIDED_POSE_PROTOCOL:
            raise MatcherUnavailable("UNSUPPORTED_POSE_PROTOCOL: Unknown guided protocol")
        guided = pose_protocol == GUIDED_POSE_PROTOCOL
        if guided and (len(burst_a) != GUIDED_BURST_A_COUNT or len(burst_b) != GUIDED_BURST_B_COUNT):
            raise MatcherUnavailable("FRAME_COUNT: Guided enrollment requires exactly five plus three frames")
        if not burst_a or not burst_b or not reference_embedding:
            raise MatcherUnavailable("INVALID_INPUT: Burst A (5 frames), Burst B (3 frames), and reference embedding are required")
        if len(burst_a) < 5 or len(burst_b) < 3:
            raise MatcherUnavailable("FRAME_COUNT: Two-burst enrollment requires 5 frames in Burst A and 3 frames in Burst B")

        # Frame hash check for replay/duplicate prevention across bursts
        hashes_a = [hashlib.sha256(f).hexdigest() for f in burst_a]
        hashes_b = [hashlib.sha256(f).hexdigest() for f in burst_b]
        if len(set(hashes_a)) != len(hashes_a) or len(set(hashes_b)) != len(hashes_b):
            raise MatcherUnavailable("REPLAYED_FRAMES: Duplicate frame detected within capture burst")
        if set(hashes_a).intersection(set(hashes_b)):
            raise MatcherUnavailable("BURST_REUSE: Duplicate frames detected between Burst A and Burst B")

        # Extract features with strict quality
        features_a, q_a, _, _, ev_a = self._frame_features(burst_a, strict_quality=True, capture_contexts=capture_contexts_a)
        features_b, q_b, _, _, ev_b = self._frame_features(burst_b, strict_quality=True, capture_contexts=capture_contexts_b)

        invalid_context = next((ev for ev in ev_a + ev_b if ev.get("reason_code") == "INVALID_CAPTURE_CONTEXT"), None)
        if invalid_context is not None:
            raise MatcherUnavailable(f"INVALID_CAPTURE_CONTEXT: {invalid_context.get('detail', 'capture geometry is invalid')}")

        if len(features_a) < 5:
            reasons = [r for ev in ev_a for r in ev.get("reasons", [])]
            raise MatcherUnavailable(f"BURST_A_QUALITY_FAILED: 5 quality frames required in Burst A, got {len(features_a)}. Reasons: {', '.join(set(reasons))}")

        if len(features_b) < 3:
            reasons = [r for ev in ev_b for r in ev.get("reasons", [])]
            raise MatcherUnavailable(f"BURST_B_QUALITY_FAILED: 3 quality frames required in Burst B, got {len(features_b)}. Reasons: {', '.join(set(reasons))}")

        accepted_poses = self._validate_guided_poses(ev_a, ev_b) if guided else []

        ref_vec = self.np.asarray(reference_embedding, dtype=self.np.float32)
        ref_norm = float(self.np.linalg.norm(ref_vec))
        if ref_norm:
            ref_vec = ref_vec / ref_norm

        ref_threshold = getattr(self.settings, "verified_reference_threshold", 0.90)

        # Match Burst A against verified reference
        scores_ref_a = [self._cosine(feat, ref_vec) for feat in features_a]
        if any(s < ref_threshold for s in scores_ref_a):
            min_score = min(scores_ref_a)
            raise MatcherUnavailable(f"REFERENCE_MISMATCH: Burst A frame similarity to verified reference ({min_score:.4f}) is below {ref_threshold}")

        # Match Burst B against verified reference
        scores_ref_b = [self._cosine(feat, ref_vec) for feat in features_b]
        if any(s < ref_threshold for s in scores_ref_b):
            min_score = min(scores_ref_b)
            raise MatcherUnavailable(f"REFERENCE_MISMATCH: Burst B frame similarity to verified reference ({min_score:.4f}) is below {ref_threshold}")

        # Cross-frame same-person consistency within Burst A and within Burst B (>= 0.85)
        consistency_threshold = getattr(self.settings, "cross_frame_consistency_threshold", 0.85)
        for i in range(len(features_a)):
            for j in range(i + 1, len(features_a)):
                if self._cosine(features_a[i], features_a[j]) < consistency_threshold:
                    raise MatcherUnavailable("CROSS_FRAME_INCONSISTENT: Inconsistent identity within Burst A")

        for i in range(len(features_b)):
            for j in range(i + 1, len(features_b)):
                if self._cosine(features_b[i], features_b[j]) < consistency_threshold:
                    raise MatcherUnavailable("CROSS_FRAME_INCONSISTENT: Inconsistent identity within Burst B")

        # Holdout verification: Burst B vs Burst A
        holdout_scores = [self._cosine(fb, fa) for fb in features_b for fa in features_a]
        median_holdout = float(statistics.median(holdout_scores))
        min_holdout = min(holdout_scores)

        holdout_median_thresh = getattr(self.settings, "enrollment_holdout_median_threshold", 0.90)
        holdout_min_thresh = getattr(self.settings, "enrollment_holdout_min_threshold", 0.85)

        if median_holdout < holdout_median_thresh or min_holdout < holdout_min_thresh:
            raise MatcherUnavailable(f"HOLDOUT_FAILED: Burst B holdout verification failed against Burst A (median {median_holdout:.4f} < {holdout_median_thresh} or min {min_holdout:.4f} < {holdout_min_thresh})")

        # Persist references from both independently captured bursts. This is
        # stronger than choosing three near-identical centroid neighbours from
        # one burst and makes template-support meaningful at recognition time.
        selected_a = list(features_a) if guided else self._select_templates(features_a)[:2]
        if guided:
            centroid_b = self.np.mean(self.np.stack(features_b), axis=0)
            nearest_b = max(range(len(features_b)), key=lambda index: self._cosine(features_b[index], centroid_b))
            selected_b = [features_b[nearest_b]]
        else:
            selected_b = self._select_templates(features_b)[:1]
        selected_templates = selected_a + selected_b
        if len(selected_templates) < 3:
            raise MatcherUnavailable("TEMPLATE_DIVERSITY_FAILED: Both bursts must contribute enrollment templates")

        quality_a, summary_a = self._quality_summary(q_a, len(burst_a), len(features_a), ev_a)
        quality_b, summary_b = self._quality_summary(q_b, len(burst_b), len(features_b), ev_b)

        return {
            **({"pose_protocol": GUIDED_POSE_PROTOCOL, "burst_b_template_index": nearest_b} if guided else {}),
            "model_version_id": self.settings.model_version_id,
            "model_version": self.settings.model_version,
            "policy_version": self.settings.policy_version,
            "embeddings": [item.astype(self.np.float32).tolist() for item in selected_templates],
            "template_sources": (["BURST_A"] * len(selected_a)) + (["BURST_B"] * len(selected_b)),
            # The loopback-only enrollment service needs the real burst
            # samples for consensus duplicate detection. These are never
            # returned to the browser or persisted as raw biometric evidence.
            "burst_a_embeddings": [item.astype(self.np.float32).tolist() for item in features_a],
            "burst_b_embeddings": [item.astype(self.np.float32).tolist() for item in features_b],
            "embedding_dimension": int(selected_templates[0].shape[0]),
            "burst_a_count": len(features_a),
            "burst_b_count": len(features_b),
            "reference_match_scores": {
                "burst_a": [round(s, 4) for s in scores_ref_a],
                "burst_b": [round(s, 4) for s in scores_ref_b],
            },
            "holdout_metrics": {
                "median": round(median_holdout, 4),
                "min": round(min_holdout, 4),
                "passed": True,
            },
            "quality_summary": {
                "burst_a": summary_a,
                "burst_b": summary_b,
                "frames_accepted": len(features_a) + len(features_b),
                **({"accepted_poses": accepted_poses} if guided else {}),
            },
            "evidences": {
                "burst_a": ev_a,
                "burst_b": ev_b,
            },
            "verification_passed": True,
            "verification_reason": "TWO_BURST_VERIFIED",
            "stage": "ENROLLMENT_VALIDATION",
            "reason_code": "TWO_BURST_VERIFIED",
        }

    def _encode_guided_selection(self, burst_a, burst_b, reference_embedding,
                                 capture_contexts_a=None, capture_contexts_b=None):
        if len(burst_a) != GUIDED_SELECTION_BURST_A_COUNT or len(burst_b) != GUIDED_BURST_B_COUNT:
            raise MatcherUnavailable("FRAME_COUNT: Candidate selection requires ten plus three frames")
        digests_a = [hashlib.sha256(frame).digest() for frame in burst_a]
        digests_b = [hashlib.sha256(frame).digest() for frame in burst_b]
        if len(set(digests_a)) != len(digests_a) or len(set(digests_b)) != len(digests_b):
            raise MatcherUnavailable("REPLAYED_FRAMES: Duplicate frame detected within capture burst")
        if set(digests_a).intersection(digests_b):
            raise MatcherUnavailable("BURST_REUSE: Duplicate frames detected between capture bursts")

        features_a, q_a, _, _, ev_a = self._frame_features(burst_a, strict_quality=True,
                                                            capture_contexts=capture_contexts_a)
        features_b, q_b, _, _, ev_b = self._frame_features(burst_b, strict_quality=True,
                                                            capture_contexts=capture_contexts_b)
        invalid_context = next((ev for ev in ev_a + ev_b if ev.get("reason_code") == "INVALID_CAPTURE_CONTEXT"), None)
        if invalid_context is not None:
            raise MatcherUnavailable("INVALID_CAPTURE_CONTEXT: Capture geometry is invalid")

        by_a = {i: features_a[ev["encoded_feature_index"]] for i, ev in enumerate(ev_a)
                if "encoded_feature_index" in ev}
        by_b = {i: features_b[ev["encoded_feature_index"]] for i, ev in enumerate(ev_b)
                if "encoded_feature_index" in ev}
        if len(by_b) != GUIDED_BURST_B_COUNT:
            raise MatcherUnavailable("BURST_B_QUALITY_FAILED: Three independent quality frames required")

        def valid_pose(ev, pose, centre_pitch):
            yaw, pitch = ev.get("pose_yaw"), ev.get("pose_pitch")
            if any(isinstance(value, bool) or not isinstance(value, (int, float)) or not math.isfinite(value)
                   for value in (yaw, pitch)):
                return False
            delta = round(pitch - centre_pitch, 3) if centre_pitch is not None else 0.0
            if pose == "centre":
                return (abs(yaw) <= GUIDED_CENTRE_YAW_MAX
                        and GUIDED_CENTRE_PITCH_MIN <= pitch <= GUIDED_CENTRE_PITCH_MAX
                        and abs(delta) <= GUIDED_PITCH_TOLERANCE)
            if pose in ("left", "right"):
                signed = -yaw if pose == "left" else yaw
                return GUIDED_SIDE_YAW_MIN <= signed <= GUIDED_SIDE_YAW_MAX and abs(delta) <= GUIDED_PITCH_TOLERANCE
            displacement = -delta if pose == "up" else delta
            return abs(yaw) <= GUIDED_CENTRE_YAW_MAX and GUIDED_PITCH_DELTA_MIN <= displacement <= GUIDED_PITCH_DELTA_MAX

        centre_candidates = [i for i in range(2) if i in by_a and valid_pose(ev_a[i], "centre", None)]
        if not centre_candidates:
            raise MatcherUnavailable("POSE_TARGET_MISMATCH: No valid centre candidate")
        # Centre pitch is anchored to a real, quality-valid candidate. If both
        # are good, the sharper image wins and the earlier frame breaks ties.
        centre_index = max(centre_candidates, key=lambda i: (float(ev_a[i].get("sharpness", 0)), -i))
        centre_pitch = ev_a[centre_index]["pose_pitch"]
        eligible_a = {}
        observations = []
        for pose_index, pose in enumerate(GUIDED_POSES):
            pair = [2 * pose_index, 2 * pose_index + 1]
            valid = [i for i in pair if i in by_a and valid_pose(ev_a[i], pose, centre_pitch)]
            if not valid:
                raise MatcherUnavailable(f"POSE_TARGET_MISMATCH: No valid {pose} candidate")
            for i in valid:
                eligible_a[i] = by_a[i]
                observations.append({"burst": "BURST_A", "index": i, "pose": pose,
                                     "yaw": ev_a[i]["pose_yaw"], "pitch": ev_a[i]["pose_pitch"]})
        for i in range(GUIDED_BURST_B_COUNT):
            if not valid_pose(ev_b[i], "centre", centre_pitch):
                raise MatcherUnavailable(f"POSE_TARGET_MISMATCH: Burst B centre at observation {i + 1}")
            observations.append({"burst": "BURST_B", "index": i, "pose": "centre",
                                 "yaw": ev_b[i]["pose_yaw"], "pitch": ev_b[i]["pose_pitch"]})

        reference = self.np.asarray(reference_embedding, dtype=self.np.float32)
        norm = float(self.np.linalg.norm(reference))
        if not norm:
            raise MatcherUnavailable("INVALID_REFERENCE: Reference embedding is empty")
        reference /= norm
        reference_threshold = getattr(self.settings, "verified_reference_threshold", 0.90)
        all_features = list(eligible_a.values()) + list(by_b.values())
        # Any quality-valid candidate indicating a different identity blocks
        # the entire attempt; it is never silently discarded as a bad option.
        for feature in all_features:
            if self._cosine(feature, reference) < reference_threshold:
                raise MatcherUnavailable("REFERENCE_MISMATCH: Candidate differs from verified reference")
        consistency = getattr(self.settings, "cross_frame_consistency_threshold", 0.85)
        for group in (list(eligible_a.values()), list(by_b.values())):
            for i in range(len(group)):
                for j in range(i + 1, len(group)):
                    if self._cosine(group[i], group[j]) < consistency:
                        raise MatcherUnavailable("CROSS_FRAME_INCONSISTENT: Candidate identities disagree")

        selected_indices = []
        for pose_index in range(GUIDED_BURST_A_COUNT):
            pair = [i for i in (2 * pose_index, 2 * pose_index + 1) if i in eligible_a]
            selected_indices.append(max(pair, key=lambda i: (
                statistics.median(self._cosine(eligible_a[i], other) for other in all_features),
                float(ev_a[i].get("sharpness", 0)), -i)))
        selected_a = [eligible_a[i] for i in selected_indices]
        selected_b = list(by_b.values())
        holdout = [self._cosine(fb, fa) for fb in selected_b for fa in selected_a]
        median_holdout = float(statistics.median(holdout))
        min_holdout = min(holdout)
        median_limit = getattr(self.settings, "enrollment_holdout_median_threshold", 0.90)
        min_limit = getattr(self.settings, "enrollment_holdout_min_threshold", 0.85)
        if median_holdout < median_limit or min_holdout < min_limit:
            raise MatcherUnavailable("HOLDOUT_FAILED: Verification burst differs from selected enrollment frames")
        centroid_b = self.np.mean(self.np.stack(selected_b), axis=0)
        b_index = max(range(GUIDED_BURST_B_COUNT), key=lambda i: self._cosine(selected_b[i], centroid_b))
        selected = selected_a + [selected_b[b_index]]
        for observation in observations:
            observation["selected"] = (observation["index"] in selected_indices
                                       if observation["burst"] == "BURST_A"
                                       else observation["index"] == b_index)
        _, summary_a = self._quality_summary(q_a, len(burst_a), len(features_a), ev_a)
        _, summary_b = self._quality_summary(q_b, len(burst_b), len(features_b), ev_b)
        return {
            "pose_protocol": GUIDED_SELECTION_PROTOCOL,
            "model_version_id": self.settings.model_version_id,
            "model_version": self.settings.model_version,
            "policy_version": self.settings.policy_version,
            "embeddings": [item.astype(self.np.float32).tolist() for item in selected],
            "template_sources": ["BURST_A"] * GUIDED_BURST_A_COUNT + ["BURST_B"],
            "burst_a_embeddings": [by_a[i].astype(self.np.float32).tolist() if i in by_a else None
                                   for i in range(GUIDED_SELECTION_BURST_A_COUNT)],
            "burst_b_embeddings": [by_b[i].astype(self.np.float32).tolist() for i in range(GUIDED_BURST_B_COUNT)],
            "selected_burst_a_indices": selected_indices,
            "burst_b_template_index": b_index,
            "embedding_dimension": int(selected[0].shape[0]),
            "burst_a_count": len(features_a), "burst_b_count": len(features_b),
            "reference_match_scores": {
                "burst_a": [round(self._cosine(by_a[i], reference), 4) if i in by_a else None
                            for i in range(GUIDED_SELECTION_BURST_A_COUNT)],
                "burst_b": [round(self._cosine(by_b[i], reference), 4) for i in range(GUIDED_BURST_B_COUNT)]},
            "holdout_metrics": {"median": round(median_holdout, 4), "min": round(min_holdout, 4), "passed": True},
            "quality_summary": {"burst_a": summary_a, "burst_b": summary_b,
                                "frames_accepted": len(features_a) + len(features_b),
                                "selected_templates": GUIDED_TEMPLATE_COUNT,
                                "accepted_poses": observations},
            "evidences": {"burst_a": ev_a, "burst_b": ev_b},
            "verification_passed": True,
            "verification_reason": "TWO_BURST_VERIFIED",
            "stage": "ENROLLMENT_VALIDATION", "reason_code": "TWO_BURST_VERIFIED",
        }

    def health(self) -> Dict[str, Any]:
        return {
            "model_ready": True,
            "model_version": self.settings.model_version,
            "pad_model_ready": True,
            "pad_calibration_approved": self.settings.pad_calibration_approved,
        }


__all__ = ["MatchResult", "QualityResult", "MatcherUnavailable", "OnnxFaceMatcher", "UnavailableMatcher"]
