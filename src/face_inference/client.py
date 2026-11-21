"""Typed Python client for the face_inference REST API.

Provides FaceClient with standard library HTTP requests, automatic retries on
idempotent mutations, token redaction, and typed models.
"""

from __future__ import annotations

import json
import time
import urllib.error
import urllib.parse
import urllib.request
import uuid
from typing import Any, TypedDict


class HealthResponse(TypedDict, total=False):
    status: str
    api_version: str
    storage: str


class ModelContract(TypedDict, total=False):
    model_version: str
    preprocessing_version: str
    dimension: int
    detector_size: int
    input_size: int
    input_order: str
    input_scale: float
    input_mean: float
    detector_score: float
    nms_iou: float
    sample_interval_ms: int
    batch_size: int
    minimum_observations: int
    capture_timeout_ms: int
    minimum_face_pixels: int
    brightness_min: int
    brightness_max: int
    blur_variance_min: int
    clipped_fraction_max: float
    assets_base: str
    recognizer_file: str
    detector_file: str
    recognizer_source_sha256: str
    detector_source_sha256: str


class ConfigResponse(TypedDict, total=False):
    contract: ModelContract | dict[str, Any]
    mode: str
    approved: bool
    duplicate_threshold: float
    recognition_threshold: float
    liveness_validated: bool
    maximum_people: int


class Collection(TypedDict, total=False):
    id: str
    name: str
    created_at: float


class CreateCollectionResult(TypedDict, total=False):
    state: str
    collection_id: str


CollectionResult = CreateCollectionResult


class Person(TypedDict, total=False):
    id: str
    name: str
    metadata: dict[str, Any]
    state: str
    created_at: str
    updated_at: str


class EnrollmentResult(TypedDict, total=False):
    state: str
    person_id: str
    capture_session: str
    collection_id: str


class RecognitionResult(TypedDict, total=False):
    state: str
    person_id: str | None
    verified: bool
    score: float | None
    collection_id: str


class ObservationResult(TypedDict, total=False):
    recorded: bool
    state: str
    capture_session: str


class DeleteResult(TypedDict, total=False):
    state: str
    person_id: str
    collection_id: str


class Event(TypedDict, total=False):
    id: int
    person_id: str | None
    event: str
    state: str
    created_at: float


class FaceError(Exception):
    """Base exception for FaceClient operations."""


class FaceClientError(FaceError):
    """Client-side error, e.g. unresolved pending request or invalid arguments."""


class FaceApiError(FaceError):
    """Server-side API error returned by the HTTP service."""

    def __init__(self, status: int, code: str, message: str) -> None:
        self.status = status
        self.code = code
        self.message = message
        detail = f"[{status}] {code}: {message}" if code else f"[{status}] {message}"
        super().__init__(detail)

    def __repr__(self) -> str:
        return f"FaceApiError(status={self.status!r}, code={self.code!r}, message={self.message!r})"


class FaceConnectionError(FaceError):
    """Network-level error or timeout communicating with the face service."""

    def __init__(self, message: str, original_error: Exception | None = None) -> None:
        self.message = message
        self.original_error = original_error
        super().__init__(message)

    def __repr__(self) -> str:
        return f"FaceConnectionError({self.message!r})"


class _RedirectHandler(urllib.request.HTTPRedirectHandler):
    @staticmethod
    def _origin(url: str) -> tuple[str, str | None, int | None]:
        parsed = urllib.parse.urlsplit(url)
        port = parsed.port
        if port is None:
            port = {"http": 80, "https": 443}.get(parsed.scheme)
        return parsed.scheme, parsed.hostname, port

    def redirect_request(self, req, fp, code, msg, headers, newurl):
        source = self._origin(req.full_url)
        target = self._origin(newurl)
        if source[0] == "https" and target[0] == "http":
            fp.close()
            raise urllib.error.URLError("Redirect from HTTPS to HTTP is not permitted")
        redirected = super().redirect_request(req, fp, code, msg, headers, newurl)
        if redirected is not None and source != target:
            redirected.remove_header("Authorization")
        return redirected


class FaceClient:
    """Typed stdlib HTTP client for the face_inference REST API."""

    def __init__(
        self,
        base_url: str,
        token: str,
        timeout: float = 10.0,
        *,
        retry_backoff: float = 0.25,
    ) -> None:
        parsed = urllib.parse.urlsplit(base_url)
        if parsed.scheme not in ("http", "https") or not parsed.netloc:
            raise ValueError(
                f"Invalid base_url: {base_url!r}. "
                "Base URL must have an http or https scheme and a host."
            )
        self.base_url = base_url.rstrip("/")
        self.token = token
        self.timeout = float(timeout)
        self.retry_backoff = float(retry_backoff)
        self.pending: dict[str, Any] | None = None
        self._opener = urllib.request.build_opener(_RedirectHandler())

    def __repr__(self) -> str:
        return f"FaceClient(base_url={self.base_url!r}, token='***', timeout={self.timeout!r})"

    def __str__(self) -> str:
        return f"FaceClient(base_url={self.base_url!r}, timeout={self.timeout!r})"

    def _redact(self, text: str) -> str:
        if not self.token or not text:
            return text
        return text.replace(self.token, "***")

    def _decode(self, response_bytes: bytes, status: int) -> Any:
        try:
            body = json.loads(response_bytes.decode("utf-8")) if response_bytes else {}
        except (ValueError, UnicodeError):
            body = {}

        if not isinstance(body, dict):
            return body

        if body.get("error"):
            code = self._redact(str(body.get("code") or ""))
            raw_msg = body.get("error_msg") or body.get("message") or "Face service unavailable"
            msg = self._redact(str(raw_msg))
            raise FaceApiError(status, code, msg)

        return body.get("data", body)

    def _execute_once(
        self,
        path: str,
        method: str,
        body: dict[str, Any] | None,
    ) -> tuple[int, bytes]:
        url = f"{self.base_url}{path}" if path.startswith("/") else f"{self.base_url}/{path}"
        data = json.dumps(body, allow_nan=False).encode("utf-8") if body is not None else None
        headers: dict[str, str] = {
            "Accept": "application/json",
            "User-Agent": "FaceClient/1.0",
        }
        if self.token:
            headers["Authorization"] = f"Bearer {self.token}"
        if data is not None:
            headers["Content-Type"] = "application/json"

        req = urllib.request.Request(url, data=data, headers=headers, method=method)
        with self._opener.open(req, timeout=self.timeout) as resp:
            content = resp.read()
            return resp.status, content

    def _perform(self, request: dict[str, Any]) -> Any:
        path = request["path"]
        method = request["method"]
        body = request.get("body")
        last_error: Exception | None = None

        for attempt in range(3):
            try:
                status, content = self._execute_once(path, method, body)
                result = self._decode(content, status)
                if self.pending is request:
                    self.pending = None
                return result
            except FaceApiError as error:
                last_error = error
                # Confirmed client error (status < 500 and not 429): do not retry
                if error.status < 500 and error.status != 429:
                    if self.pending is request:
                        self.pending = None
                    raise
            except urllib.error.HTTPError as error:
                raw = error.read() if hasattr(error, "read") else b""
                try:
                    payload = json.loads(raw.decode("utf-8")) if raw else {}
                except (ValueError, UnicodeError):
                    payload = {}
                code = self._redact(str(payload.get("code") or ""))
                raw_msg = (
                    payload.get("error_msg")
                    or payload.get("message")
                    or error.reason
                    or "HTTP error"
                )
                msg = self._redact(str(raw_msg))
                api_error = FaceApiError(error.code, code, msg)
                last_error = api_error
                if error.code < 500 and error.code != 429:
                    if self.pending is request:
                        self.pending = None
                    raise api_error from error
            except TimeoutError as error:
                last_error = FaceConnectionError(
                    f"Request timed out after {self.timeout}s",
                    original_error=error,
                )
            except urllib.error.URLError as error:
                reason_msg = getattr(error, "reason", error)
                last_error = FaceConnectionError(
                    self._redact(f"Connection failed: {reason_msg}"),
                    original_error=error,
                )
            except Exception as error:
                last_error = FaceConnectionError(
                    self._redact(str(error)),
                    original_error=error,
                )

            if attempt < 2 and self.retry_backoff > 0:
                time.sleep(self.retry_backoff * (attempt + 1))

        if isinstance(last_error, FaceApiError):
            raise last_error
        if isinstance(last_error, FaceConnectionError):
            raise FaceConnectionError(
                f"{last_error.message}. Retry save to recover the same request.",
                original_error=last_error.original_error,
            )
        raise FaceConnectionError(
            f"{self._redact(str(last_error))}. Retry save to recover the same request.",
            original_error=last_error,
        )

    def get(self, path: str) -> Any:
        """Perform an idempotent GET request with automatic retry on transient errors."""
        return self._perform({"path": path, "method": "GET", "body": None})

    def send(self, path: str, body: dict[str, Any] | None = None, method: str = "POST") -> Any:
        """Perform a mutating request with idempotency key generation and recovery tracking."""
        if self.pending is not None:
            raise FaceClientError(
                "Resolve the previous request with retry() before starting another"
            )

        payload = dict(body) if body is not None else {}
        if "idempotency_key" not in payload or not payload["idempotency_key"]:
            payload["idempotency_key"] = str(uuid.uuid4())

        request = {
            "path": path,
            "method": method,
            "body": payload,
        }
        self.pending = request
        return self._perform(request)

    def retry(self) -> Any:
        """Retry the last unresolved mutating request with the same idempotency key."""
        if not self.pending:
            raise FaceError("No unresolved request")
        return self._perform(self.pending)

    # Documented endpoints

    def health(self) -> HealthResponse:
        """Public health check endpoint (GET /health)."""
        return self.get("/health")

    def get_config(self) -> ConfigResponse:
        """Retrieve model and server configuration (GET /api/v1/config)."""
        return self.get("/api/v1/config")

    config = get_config

    def list_collections(self) -> list[Collection]:
        """List all collections (GET /api/v1/collections)."""
        return self.get("/api/v1/collections")

    def create_collection(
        self,
        id: str,
        name: str,
        *,
        idempotency_key: str | None = None,
    ) -> CreateCollectionResult:
        """Create a new collection (POST /api/v1/collections)."""
        payload: dict[str, Any] = {"id": id, "name": name}
        if idempotency_key:
            payload["idempotency_key"] = idempotency_key
        return self.send("/api/v1/collections", payload, method="POST")

    def list_people(self, collection_id: str) -> list[Person]:
        """List people in a collection (GET /api/v1/collections/{id}/people)."""
        return self.get(f"/api/v1/collections/{collection_id}/people")

    def add_person(
        self,
        collection_id: str,
        id: str,
        name: str,
        metadata: dict[str, Any] | None = None,
        *,
        idempotency_key: str | None = None,
    ) -> Person:
        """Create or update a person in a collection (POST /api/v1/collections/{id}/people)."""
        payload: dict[str, Any] = {"id": id, "name": name, "metadata": metadata or {}}
        if idempotency_key:
            payload["idempotency_key"] = idempotency_key
        return self.send(f"/api/v1/collections/{collection_id}/people", payload, method="POST")

    create_person = add_person

    def enroll(
        self,
        collection_id: str,
        person_id: str | dict[str, Any],
        observations: list[list[float]] | None = None,
        template: list[float] | None = None,
        capture_session: str | None = None,
        model_version: str | None = None,
        preprocessing_version: str | None = None,
        consent: bool = True,
        device: str | None = None,
        lighting: float | None = None,
        *,
        idempotency_key: str | None = None,
    ) -> EnrollmentResult:
        """Enroll a face reference for a person (POST /api/v1/collections/{id}/enrollments)."""
        if isinstance(person_id, dict):
            payload = dict(person_id)
        else:
            payload = {
                "person_id": person_id,
                "consent": consent,
                "observations": observations,
                "template": template,
                "capture_session": capture_session,
                "model_version": model_version,
                "preprocessing_version": preprocessing_version,
            }
            if device is not None:
                payload["device"] = device
            if lighting is not None:
                payload["lighting"] = lighting
        if idempotency_key:
            payload["idempotency_key"] = idempotency_key
        return self.send(
            f"/api/v1/collections/{collection_id}/enrollments",
            payload,
            method="POST",
        )

    def recognize(
        self,
        collection_id: str,
        observations: list[list[float]] | dict[str, Any],
        capture_session: str | None = None,
        model_version: str | None = None,
        preprocessing_version: str | None = None,
        eligible_ids: list[str] | None = None,
        *,
        idempotency_key: str | None = None,
    ) -> RecognitionResult:
        """Attempt face recognition in a collection (POST /api/v1/collections/{id}/recognitions)."""
        if isinstance(observations, dict):
            payload = dict(observations)
        else:
            payload = {
                "observations": observations,
                "capture_session": capture_session,
                "model_version": model_version,
                "preprocessing_version": preprocessing_version,
            }
            if eligible_ids is not None:
                payload["eligible_ids"] = eligible_ids
        if idempotency_key:
            payload["idempotency_key"] = idempotency_key
        return self.send(
            f"/api/v1/collections/{collection_id}/recognitions",
            payload,
            method="POST",
        )

    def record_observation(
        self,
        collection_id: str,
        person_id: str | dict[str, Any],
        observations: list[list[float]] | None = None,
        template: list[float] | None = None,
        capture_session: str | None = None,
        model_version: str | None = None,
        preprocessing_version: str | None = None,
        consent: bool = True,
        device: str | None = None,
        lighting: float | None = None,
        *,
        idempotency_key: str | None = None,
    ) -> ObservationResult:
        """Record observation without updating gallery (POST .../observations)."""
        if isinstance(person_id, dict):
            payload = dict(person_id)
        else:
            payload = {
                "person_id": person_id,
                "consent": consent,
                "observations": observations,
                "template": template,
                "capture_session": capture_session,
                "model_version": model_version,
                "preprocessing_version": preprocessing_version,
            }
            if device is not None:
                payload["device"] = device
            if lighting is not None:
                payload["lighting"] = lighting
        if idempotency_key:
            payload["idempotency_key"] = idempotency_key
        return self.send(
            f"/api/v1/collections/{collection_id}/observations",
            payload,
            method="POST",
        )

    observe = record_observation

    def delete_face(
        self,
        collection_id: str,
        person_id: str,
        *,
        idempotency_key: str | None = None,
    ) -> DeleteResult:
        """Erase face template and capture evidence (DELETE .../people/{person}/face)."""
        payload: dict[str, Any] = {}
        if idempotency_key:
            payload["idempotency_key"] = idempotency_key
        return self.send(
            f"/api/v1/collections/{collection_id}/people/{person_id}/face",
            payload,
            method="DELETE",
        )

    delete_subject = delete_face

    def list_events(self, collection_id: str) -> list[Event]:
        """Fetch the last 100 event summaries (GET /api/v1/collections/{id}/events)."""
        return self.get(f"/api/v1/collections/{collection_id}/events")

    events = list_events
