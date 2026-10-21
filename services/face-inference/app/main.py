"""Private HTTP entrypoint for the FACE-ML-01 no-write inference spike."""

from __future__ import annotations

import base64
import binascii
import hmac
import json
import os
import resource
import re
import secrets
import time
from http import HTTPStatus
from http.server import BaseHTTPRequestHandler, ThreadingHTTPServer
from typing import Any, Dict, List
from urllib.parse import unquote, urlsplit

try:
    from .face_selection import CaptureContextError
    from .quality import validate_client_planar_z_variance
    from .matcher import MatcherUnavailable, OnnxFaceMatcher, UnavailableMatcher
    from .service import InferenceError, InferenceService
    from .safe_logging import SafeFaceLogger
    from .settings import Settings
    from .experiment_logger import ExperimentLogger, mysql_procedure_writer_from_environment
except ImportError:  # pragma: no cover - direct development launch
    from face_selection import CaptureContextError  # type: ignore
    from quality import validate_client_planar_z_variance  # type: ignore
    from matcher import MatcherUnavailable, OnnxFaceMatcher, UnavailableMatcher  # type: ignore
    from service import InferenceError, InferenceService  # type: ignore
    from safe_logging import SafeFaceLogger  # type: ignore
    from settings import Settings  # type: ignore
    from experiment_logger import ExperimentLogger, mysql_procedure_writer_from_environment  # type: ignore


def _get_memory_info() -> Dict[str, float]:
    rusage = resource.getrusage(resource.RUSAGE_SELF)
    max_rss_mb = round(rusage.ru_maxrss / 1024.0, 2)
    current_rss_mb = max_rss_mb
    try:
        with open("/proc/self/statm", "r") as f:
            fields = f.read().strip().split()
            if len(fields) >= 2:
                page_size = os.sysconf("SC_PAGE_SIZE")
                current_rss_mb = round((int(fields[1]) * page_size) / (1024.0 * 1024.0), 2)
    except Exception:
        pass
    return {"rss_mb": current_rss_mb, "max_rss_mb": max_rss_mb}


class LimitedThreadingHTTPServer(ThreadingHTTPServer):
    daemon_threads = True
    allow_reuse_address = True

    def __init__(self, address, handler, service, settings):
        super().__init__(address, handler)
        self.service = service
        self.settings = settings
        self.request_slots = __import__("threading").BoundedSemaphore(4)
        self.start_time = time.time()

    def _send_busy_response(self, request):
        payload = json.dumps({
            "error": "CONCURRENCY_LIMIT_EXCEEDED",
            "message": "Inference service concurrency limit reached (maximum 4 concurrent requests), please retry",
        }, separators=(",", ":")).encode("utf-8")
        headers = (
            b"HTTP/1.1 503 Service Unavailable\r\n"
            b"Content-Type: application/json\r\n"
            b"Retry-After: 1\r\n"
            b"Connection: close\r\n"
            b"Content-Length: " + str(len(payload)).encode("ascii") + b"\r\n\r\n"
        )
        try:
            if hasattr(request, "sendall"):
                request.sendall(headers + payload)
            elif hasattr(request, "send"):
                request.send(headers + payload)
        except Exception:
            pass
        finally:
            try:
                request.close()
            except Exception:
                pass

    def process_request(self, request, client_address):
        if not self.request_slots.acquire(blocking=True, timeout=0.5):
            self._send_busy_response(request)
            return
        try:
            super().process_request(request, client_address)
        except Exception:
            self.request_slots.release()
            raise

    def process_request_thread(self, request, client_address):
        try:
            super().process_request_thread(request, client_address)
        finally:
            self.request_slots.release()


class InferenceHandler(BaseHTTPRequestHandler):
    server: LimitedThreadingHTTPServer
    protocol_version = "HTTP/1.1"

    def log_message(self, format, *args):
        # Never use the default request logger: request bodies can contain raw frames.
        return

    def do_GET(self):  # noqa: N802
        path = urlsplit(self.path).path
        if path == "/livez":
            uptime = round(time.time() - getattr(self.server, "start_time", time.time()), 2)
            self._send_json(HTTPStatus.OK, {
                "status": "alive",
                "uptime_seconds": uptime,
            })
            return
        if path == "/readyz":
            matcher_health = getattr(self.server.service.matcher, "health", lambda: {"model_ready": False})()
            is_ready = bool(
                self.server.settings.model_integrity_verified
                and matcher_health.get("model_ready", False)
                and self.server.settings.calibration_approved
                and self.server.settings.pad_calibration_approved
                and self.server.settings.single_candidate_accept_threshold is not None
                and self.server.settings.min_template_support >= 2
            )
            status = HTTPStatus.OK if is_ready else HTTPStatus.SERVICE_UNAVAILABLE
            self._send_json(status, {
                "status": "ready" if is_ready else "not_ready",
                "model_ready": matcher_health.get("model_ready", False),
                "model_integrity_verified": self.server.settings.model_integrity_verified,
                "model_error": self.server.settings.model_error,
                "model_version": self.server.settings.model_version,
                "recognition_generation": self.server.settings.recognition_generation,
                "identity_calibration_approved": self.server.settings.calibration_approved,
                "pad_calibration_approved": self.server.settings.pad_calibration_approved,
            })
            return
        if path == "/model-info":
            if not self._authorized():
                return
            self._send_json(HTTPStatus.OK, self._model_info())
            return
        if path != "/health":
            self._send_error(HTTPStatus.NOT_FOUND, "NOT_FOUND", "endpoint not found")
            return
        matcher_health = getattr(self.server.service.matcher, "health", lambda: {"model_ready": False})()
        uptime = round(time.time() - getattr(self.server, "start_time", time.time()), 2)
        active_slots = 4 - getattr(self.server.request_slots, "_value", 4)
        self._send_json(
            HTTPStatus.OK,
            {
                "status": "ok",
                "model": matcher_health,
                "model_integrity_verified": self.server.settings.model_integrity_verified,
                "model_error": self.server.settings.model_error,
                "threshold_configured": self.server.settings.accept_threshold is not None,
                "calibration_approved": self.server.settings.calibration_approved,
                "calibration_error": self.server.settings.calibration_error,
                "pad_calibration_approved": self.server.settings.pad_calibration_approved,
                "pad_calibration_error": self.server.settings.pad_calibration_error,
                "model_version_id": self.server.settings.model_version_id,
                "provider_id": self.server.settings.provider_id,
                "provider_version": self.server.settings.provider_version,
                "model_version": self.server.settings.model_version,
                "recognition_generation": self.server.settings.recognition_generation,
                "calibration_policy_version": self.server.settings.policy_version,
                # PHP uses this complete, version-bound policy for setup
                # duplicate decisions.  Do not make callers reconstruct it
                # from local defaults: a missing or invalid policy must fail
                # closed as an unavailable identity check.
                "identity_policy": {
                    "policy_version": self.server.settings.policy_version,
                    "review_threshold": self.server.settings.duplicate_review_threshold,
                    "duplicate_block_threshold": self.server.settings.duplicate_block_threshold,
                    "template_support_threshold": self.server.settings.template_support_threshold,
                    "min_template_support": self.server.settings.min_template_support,
                    "min_margin": self.server.settings.min_margin,
                    "accept_threshold": self.server.settings.accept_threshold,
                    "calibration_approved": self.server.settings.calibration_approved,
                },
                "preprocessing_version": self.server.settings.preprocessing_version,
                "auth_enabled": bool(self.server.settings.shared_secret),
                "active_scans": len(self.server.service.scan_snapshot()),
                "uptime_seconds": uptime,
                "memory": _get_memory_info(),
                "concurrency": {
                    "active_slots": max(0, active_slots),
                    "max_slots": 4,
                },
            },
        )

    def _model_info(self):
        return {
            "bundle_version": (self.server.settings.model_manifest or {}).get("bundle_version"),
            "retrieved_on": (self.server.settings.model_manifest or {}).get("retrieved_on"),
            "approval": (self.server.settings.model_manifest or {}).get("approval", {}),
            "components": (self.server.settings.model_manifest or {}).get("components", {}),
            "model_version_id": self.server.settings.model_version_id,
            "preprocessing_version": self.server.settings.preprocessing_version,
            "integrity_verified": self.server.settings.model_integrity_verified,
            "model_error": self.server.settings.model_error,
        }

    def do_DELETE(self):  # noqa: N802
        if not self._authorized():
            return
        parts = self._parts()
        if len(parts) == 3 and parts[:2] == ["internal", "scans"]:
            self.server.service.delete_scan(parts[2])
            self._send_json(HTTPStatus.OK, {"deleted": True, "scan_id": parts[2]})
            return
        self._send_error(HTTPStatus.NOT_FOUND, "NOT_FOUND", "endpoint not found")

    def do_POST(self):  # noqa: N802
        if not self._authorized():
            return
        try:
            parts = self._parts()
            body = self._json_body()
            if parts == ["v1", "detect"]:
                frame = self._decode_image(body.get("image"))
                result = (self.server.service.detect_testing(frame, body.get("capture_context"))
                          if body.get("capture_context") is not None else self.server.service.detect_testing(frame))
                self._send_json(HTTPStatus.OK, result)
                return
            if parts == ["v1", "infer"]:
                frame = self._decode_image(body.get("image"))
                result = (self.server.service.infer_testing(frame, body.get("candidates", []), body.get("capture_context"))
                          if body.get("capture_context") is not None
                          else self.server.service.infer_testing(frame, body.get("candidates", [])))
                self._send_json(HTTPStatus.OK, result)
                return
            if len(parts) == 4 and parts[:2] == ["internal", "scans"] and parts[3] == "roster":
                ttl = body.get("ttl_seconds")
                ttl = int(ttl) if ttl is not None else None
                roster = body.get("candidates")
                expires_in = self.server.service.start_scan(parts[2], roster, ttl)
                self._send_json(HTTPStatus.OK, {"scan_id": parts[2], "expires_in": expires_in, "candidate_count": len(roster)})
                return
            if len(parts) == 4 and parts[:2] == ["internal", "scans"] and parts[3] == "attempts":
                frames = self._decode_frames(body.get("frames"))
                shadow_mode = str(body.get("FACE_ATTENDANCE_SHADOW_MODE", "0")) == "1"
                planar = validate_client_planar_z_variance(
                    body.get("client_z_variance"),
                    self.server.settings.client_planar_z_threshold,
                    shadow_mode,
                )
                if planar.hard_reject:
                    raise InferenceError(
                        planar.reason,
                        "Failed Layer 1 3D Depth Check" if planar.reason == "SPOOF_PLANAR_ATTACK"
                        else "Missing required Layer 1 3D Depth Check",
                    )
                if planar.planar_spoof_flag:
                    self.server.service.logger.log("PLANAR_LIVENESS_WARNING", {
                        "planar_spoof_flag": True,
                        "shadow_mode": shadow_mode,
                        "reason": planar.reason,
                    })
                challenge_token = body.get("challenge_token")
                if challenge_token is not None and (
                    not isinstance(challenge_token, str) or not 32 <= len(challenge_token) <= 128
                ):
                    raise InferenceError("CHALLENGE_INVALID", "challenge token is invalid")
                contexts = body.get("capture_contexts")
                if contexts is not None and (not isinstance(contexts, list) or len(contexts) != len(frames)):
                    raise InferenceError("INVALID_CAPTURE_CONTEXT", "capture_contexts must match submitted frames")
                if contexts is not None:
                    result = self.server.service.recognize(parts[2], frames, challenge_token=challenge_token,
                                                           capture_contexts=contexts,
                                                           planar_spoof_flag=planar.planar_spoof_flag,
                                                           edge_gate_status=planar.reason)
                else:
                    result = self.server.service.recognize(parts[2], frames, challenge_token=challenge_token,
                                                           planar_spoof_flag=planar.planar_spoof_flag,
                                                           edge_gate_status=planar.reason)
                self._send_json(HTTPStatus.OK, result.to_dict())
                return
            if parts == ["internal", "reference", "encode"]:
                if "frames" in body and isinstance(body.get("frames"), list):
                    if "pose_protocol" in body:
                        raise InferenceError("FRAME_COUNT", "Guided enrollment requires named bursts")
                    frames = self._decode_frames(body.get("frames"))
                    contexts = body.get("capture_contexts")
                    if contexts is not None and (not isinstance(contexts, list) or len(contexts) != len(frames)):
                        raise InferenceError("INVALID_CAPTURE_CONTEXT", "capture_contexts must match submitted frames")
                    result = (self.server.service.encode_reference(frames, capture_contexts=contexts)
                              if contexts is not None else self.server.service.encode_reference(frames))
                else:
                    frame = self._decode_image(body.get("frame") or body.get("image"))
                    result = (self.server.service.encode_reference(frame, capture_contexts=body.get("capture_context"))
                              if body.get("capture_context") is not None else self.server.service.encode_reference(frame))
                self._send_json(HTTPStatus.OK, self._with_enrollment_metadata(result))
                return
            if "pose_protocol" in body and (not isinstance(body["pose_protocol"], str) or not body["pose_protocol"]):
                raise InferenceError("UNSUPPORTED_POSE_PROTOCOL", "pose_protocol must be a supported string")
            if parts == ["internal", "enrollments", "encode"]:
                if "burst_a" in body:
                    burst_a = self._decode_frames(body.get("burst_a", []))
                    burst_b = self._decode_frames(body.get("burst_b", []))
                    ref_emb = body.get("reference_embedding", [])
                    contexts_a = body.get("capture_contexts_a")
                    contexts_b = body.get("capture_contexts_b")
                    if contexts_a is not None and (not isinstance(contexts_a, list) or len(contexts_a) != len(burst_a)):
                        raise InferenceError("INVALID_CAPTURE_CONTEXT", "capture_contexts_a must match Burst A")
                    if contexts_b is not None and (not isinstance(contexts_b, list) or len(contexts_b) != len(burst_b)):
                        raise InferenceError("INVALID_CAPTURE_CONTEXT", "capture_contexts_b must match Burst B")
                    pose_options = {"pose_protocol": body["pose_protocol"]} if "pose_protocol" in body else {}
                    if pose_options or body.get("capture_contexts_a") is not None or body.get("capture_contexts_b") is not None:
                        result = self.server.service.encode_v2(burst_a, burst_b, ref_emb,
                                                               capture_contexts_a=contexts_a,
                                                               capture_contexts_b=contexts_b, **pose_options)
                    else:
                        result = self.server.service.encode_v2(burst_a, burst_b, ref_emb)
                else:
                    if "pose_protocol" in body:
                        raise InferenceError("FRAME_COUNT", "Guided enrollment requires named bursts")
                    frames = self._decode_frames(body.get("frames"))
                    contexts = body.get("capture_contexts")
                    if contexts is not None and (not isinstance(contexts, list) or len(contexts) != len(frames)):
                        raise InferenceError("INVALID_CAPTURE_CONTEXT", "capture_contexts must match enrollment frames")
                    result = (self.server.service.encode(frames, capture_contexts=contexts)
                              if contexts is not None else self.server.service.encode(frames))
                self._send_json(HTTPStatus.OK, self._with_enrollment_metadata(result))
                return
            if parts == ["internal", "enrollments", "encode-v2"]:
                burst_a = self._decode_frames(body.get("burst_a", []))
                burst_b = self._decode_frames(body.get("burst_b", []))
                ref_emb = body.get("reference_embedding", [])
                contexts_a = body.get("capture_contexts_a")
                contexts_b = body.get("capture_contexts_b")
                if contexts_a is not None and (not isinstance(contexts_a, list) or len(contexts_a) != len(burst_a)):
                    raise InferenceError("INVALID_CAPTURE_CONTEXT", "capture_contexts_a must match Burst A")
                if contexts_b is not None and (not isinstance(contexts_b, list) or len(contexts_b) != len(burst_b)):
                    raise InferenceError("INVALID_CAPTURE_CONTEXT", "capture_contexts_b must match Burst B")
                pose_options = {"pose_protocol": body["pose_protocol"]} if "pose_protocol" in body else {}
                if pose_options or body.get("capture_contexts_a") is not None or body.get("capture_contexts_b") is not None:
                    result = self.server.service.encode_v2(burst_a, burst_b, ref_emb,
                                                           capture_contexts_a=contexts_a,
                                                           capture_contexts_b=contexts_b, **pose_options)
                else:
                    result = self.server.service.encode_v2(burst_a, burst_b, ref_emb)
                self._send_json(HTTPStatus.OK, self._with_enrollment_metadata(result))
                return
            if parts == ["internal", "enrollments", "preview"]:
                frames = self._decode_frames(body.get("frames"))
                result = (self.server.service.preview(frames, capture_context=body.get("capture_context"))
                          if body.get("capture_context") is not None else self.server.service.preview(frames))
                self._send_json(HTTPStatus.OK, result)
                return
            self._send_error(HTTPStatus.NOT_FOUND, "NOT_FOUND", "endpoint not found")
        except InferenceError as exc:
            stage, reason_code = self._error_diagnostics(exc.reason, str(exc))
            self.server.service.logger.log("INFERENCE_REJECTED", {
                "reason": exc.reason, "stage": stage, "reason_code": reason_code,
            })
            status = HTTPStatus.BAD_REQUEST
            if exc.reason in {"SCAN_NOT_FOUND", "SCAN_EXPIRED"}:
                status = HTTPStatus.NOT_FOUND
            elif exc.reason in {"ATTEMPT_RATE_LIMIT"}:
                status = HTTPStatus.TOO_MANY_REQUESTS
            elif exc.reason in {"MODEL_NOT_READY", "GENERATION_MISMATCH"}:
                status = HTTPStatus.SERVICE_UNAVAILABLE
            self._send_error(status, exc.reason, str(exc))
        except CaptureContextError as exc:
            self.server.service.logger.log("INFERENCE_REJECTED", {
                "reason": "INVALID_CAPTURE_CONTEXT", "stage": "GUIDE_SELECTION", "reason_code": "INVALID_CAPTURE_CONTEXT",
            })
            self._send_error(HTTPStatus.BAD_REQUEST, "INVALID_CAPTURE_CONTEXT", str(exc))
        except (TypeError, ValueError, KeyError, binascii.Error, json.JSONDecodeError):
            self._send_error(HTTPStatus.BAD_REQUEST, "INVALID_REQUEST", "request body is invalid")
        except Exception as exc:

            import traceback
            self.server.service.logger.log("INFERENCE_INTERNAL_ERROR", {"error": str(exc), "trace": traceback.format_exc()})
            self._send_error(HTTPStatus.INTERNAL_SERVER_ERROR, "INTERNAL_ERROR", "inference request failed")

    def _with_enrollment_metadata(self, payload: Dict[str, Any]) -> Dict[str, Any]:
        """Bind enrollment output to the exact provider/model policy in use."""
        result = dict(payload)
        result["provider_id"] = self.server.settings.provider_id
        result["provider_version"] = self.server.settings.provider_version
        result["calibration_policy_version"] = self.server.settings.policy_version
        result["preprocessing_version"] = self.server.settings.preprocessing_version
        return result

    def _authorized(self) -> bool:
        secret = self.server.settings.shared_secret
        if not secret:
            return True
        supplied = self.headers.get("X-Face-Inference-Token", "")
        if hmac.compare_digest(supplied, secret):
            return True
        self._send_error(HTTPStatus.UNAUTHORIZED, "UNAUTHORIZED", "invalid internal token")
        return False

    def _parts(self) -> List[str]:
        return [unquote(part) for part in urlsplit(self.path).path.strip("/").split("/") if part]

    def _json_body(self) -> Dict[str, Any]:
        length = int(self.headers.get("Content-Length", "0"))
        if length < 1 or length > self.server.settings.max_body_bytes:
            raise InferenceError("BODY_SIZE_LIMIT", "request body is too large")
        raw = self.rfile.read(length)
        value = json.loads(raw.decode("utf-8"))
        if not isinstance(value, dict):
            raise InferenceError("INVALID_REQUEST", "request body must be an object")
        return value

    @staticmethod
    def _decode_frames(value: Any) -> List[bytes]:
        if not isinstance(value, list):
            raise InferenceError("INVALID_FRAMES", "frames must be an array")
        frames = []
        for item in value:
            if not isinstance(item, str):
                raise InferenceError("INVALID_FRAME", "frame must be base64 text")
            try:
                frames.append(base64.b64decode(item.encode("ascii"), validate=True))
            except (ValueError, UnicodeEncodeError, binascii.Error) as exc:
                raise InferenceError("INVALID_FRAME", "frame encoding is invalid") from exc
        return frames

    @staticmethod
    def _decode_image(value: Any) -> bytes:
        if not isinstance(value, str):
            raise InferenceError("INVALID_FRAME", "image must be base64 text")
        try:
            return base64.b64decode(value.encode("ascii"), validate=True)
        except (ValueError, UnicodeEncodeError, binascii.Error) as exc:
            raise InferenceError("INVALID_FRAME", "image encoding is invalid") from exc

    def _send_json(self, status: HTTPStatus, payload: Dict[str, Any]) -> None:
        data = json.dumps(payload, separators=(",", ":")).encode("utf-8")
        self.send_response(status)
        self.send_header("Content-Type", "application/json")
        self.send_header("Content-Length", str(len(data)))
        self.send_header("Cache-Control", "no-store")
        self.send_header("Connection", "close")
        self.end_headers()
        self.wfile.write(data)
        self.close_connection = True

    def _send_error(self, status: HTTPStatus, reason: str, message: str) -> None:
        stage, reason_code = self._error_diagnostics(reason, message)
        self._send_json(status, {"error": reason, "reason": reason_code, "reason_code": reason_code,
                                 "stage": stage, "message": message})

    @staticmethod
    def _error_diagnostics(reason: str, message: str):
        match = re.search(r"\b([A-Z][A-Z0-9_]{2,63}):", str(message))
        reason_code = match.group(1) if match else reason
        if reason_code in {"INVALID_CAPTURE_CONTEXT", "NO_FACE_IN_GUIDE", "MULTIPLE_FACES_IN_GUIDE"}:
            stage = "GUIDE_SELECTION"
        elif reason.startswith("REFERENCE_") or reason.startswith("ENROLLMENT_") or "BURST" in reason_code:
            stage = "ENROLLMENT_VALIDATION"
        elif reason_code in {"INVALID_FRAME", "IMAGE_DECODE_FAILED", "NO_FACE"}:
            stage = "DETECTION"
        elif reason_code in {"SPOOF_PLANAR_ATTACK", "CLIENT_PLANAR_Z_MISSING"}:
            stage = "QUALITY"
        else:
            stage = "MATCHING"
        return stage, reason_code


def build_service(settings: Settings) -> InferenceService:
    try:
        if not settings.model_integrity_verified:
            raise MatcherUnavailable(settings.model_error or "model manifest is not verified")
        matcher = OnnxFaceMatcher(settings)
    except MatcherUnavailable:
        matcher = UnavailableMatcher(settings.model_version)
    experiment_logger = None
    if settings.experiment_logging_enabled:
        writer = mysql_procedure_writer_from_environment()
        if writer is None or not settings.experiment_id_secret_file:
            raise RuntimeError("experiment logging is enabled without a writer credential or root ID credential")
        experiment_logger = ExperimentLogger.from_credential(settings.experiment_id_secret_file, settings.experiment_opaque_id_version, writer)
    return InferenceService(matcher=matcher, settings=settings, logger=SafeFaceLogger(), experiment_logger=experiment_logger)


def run() -> None:
    settings = Settings.from_environment()
    if settings.host not in {"127.0.0.1", "::1", "localhost"} and not settings.shared_secret:
        raise RuntimeError("FACE_INFERENCE_SHARED_SECRET is required for non-loopback binding")
    server = LimitedThreadingHTTPServer((settings.host, settings.port), InferenceHandler, build_service(settings), settings)
    try:
        server.serve_forever()
    finally:
        server.server_close()


if __name__ == "__main__":
    run()
