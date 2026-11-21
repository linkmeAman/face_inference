"""Runtime route contract tests verifying OpenAPI documented operations against a live server.

Dynamically parses docs/openapi.yaml, executes each documented operation against an
isolated in-process FaceHTTPServer, verifies response envelope contracts, distinguishes
routed validation/auth errors from 404, and enforces required security behavior.
"""

import json
import tempfile
import threading
import uuid
from collections.abc import Generator
from pathlib import Path
from urllib.error import HTTPError
from urllib.request import Request, urlopen

import pytest
import yaml

from face_inference.config import ROOT, contract, load_config
from face_inference.server import FaceHTTPServer
from face_inference.service import FaceService
from face_inference.storage import SQLiteRepository

OPENAPI_PATH = Path(__file__).resolve().parents[1] / "docs/openapi.yaml"


def load_documented_operations() -> set[tuple[str, str]]:
    """Dynamically enumerate all documented operations from docs/openapi.yaml."""
    spec = yaml.safe_load(OPENAPI_PATH.read_text())
    operations = set()
    for path, item in spec.get("paths", {}).items():
        for method in item:
            if method.lower() in {"get", "post", "put", "patch", "delete"}:
                operations.add((method.upper(), path))
    return operations


class LiveServerContext:
    """Manages the in-process server lifecycle and client requests."""

    def __init__(
        self, server: FaceHTTPServer, config: dict, temp_dir: tempfile.TemporaryDirectory
    ) -> None:
        self.server = server
        self.config = config
        self.temp_dir = temp_dir
        self.base_url = f"http://127.0.0.1:{server.server_port}"

    def request(
        self,
        path: str,
        method: str = "GET",
        data: dict | list | None = None,
        raw: bytes | None = None,
        token: str | None = None,
        headers: dict[str, str] | None = None,
        authorized: bool = True,
    ) -> tuple[int, dict | bytes]:
        hdrs: dict[str, str] = {}
        if authorized:
            bearer = token if token is not None else self.config["api_token"]
            hdrs["Authorization"] = f"Bearer {bearer}"
        if headers:
            hdrs.update(headers)

        body = raw
        if body is None and data is not None:
            body = json.dumps(data).encode("utf-8")
            hdrs.setdefault("Content-Type", "application/json")

        req = Request(self.base_url + path, data=body, method=method, headers=hdrs)
        try:
            with urlopen(req, timeout=5) as resp:
                status = resp.status
                content = resp.read()
                content_type = resp.headers.get_content_type()
        except HTTPError as err:
            status = err.code
            content = err.read()
            content_type = err.headers.get_content_type()

        if content_type == "application/json":
            try:
                parsed = json.loads(content.decode("utf-8"))
                return status, parsed
            except Exception:
                pass
        return status, content


@pytest.fixture
def live_server() -> Generator[LiveServerContext, None, None]:
    """Start an isolated FaceHTTPServer on 127.0.0.1:0."""
    temp_dir = tempfile.TemporaryDirectory()
    config_path = Path(temp_dir.name) / "config.json"
    cfg = load_config(config_path)
    repo = SQLiteRepository(cfg["database"], cfg["encryption_key"])
    service = FaceService(repo, cfg, contract())

    # Do not silently skip on socket errors per S08 instruction: sockets must be tested
    server = FaceHTTPServer(("127.0.0.1", 0), service, ROOT)
    thread = threading.Thread(target=server.serve_forever, daemon=True)
    thread.start()

    ctx = LiveServerContext(server, cfg, temp_dir)
    try:
        yield ctx
    finally:
        server.shutdown()
        server.server_close()
        thread.join(timeout=3)
        temp_dir.cleanup()


def assert_success_envelope(payload: dict) -> None:
    """Verify standard success envelope contract."""
    assert isinstance(payload, dict), f"Expected dict payload, got {type(payload)}"
    assert payload.get("error") == 0, f"Expected error=0, got {payload.get('error')}"
    assert (
        payload.get("error_msg") == ""
    ), f"Expected empty error_msg, got {payload.get('error_msg')}"
    assert payload.get("code") == "", f"Expected empty code, got {payload.get('code')}"
    assert "data" in payload, "Missing 'data' field in response envelope"


def test_documented_operations_have_runtime_cases():
    """Verify that every documented OpenAPI operation is known and testable."""
    documented = load_documented_operations()
    assert len(documented) == 11, f"Expected 11 documented operations, found {len(documented)}"


def test_all_documented_operations_exercised_against_live_server(live_server: LiveServerContext):
    """Dynamically enumerate OpenAPI operations and exercise every one against the live server."""
    documented_operations = load_documented_operations()
    exercised_operations: set[tuple[str, str]] = set()

    col_id = f"col_{uuid.uuid4().hex[:12]}"
    person1_id = f"p1_{uuid.uuid4().hex[:12]}"
    person2_id = f"p2_{uuid.uuid4().hex[:12]}"
    model_info = contract()
    unit_vec = [1.0] + [0.0] * (model_info["dimension"] - 1)

    # 1. GET /api/v1/config
    op = ("GET", "/api/v1/config")
    status, body = live_server.request("/api/v1/config", method="GET")
    assert status == 200, f"{op} failed: {body}"
    assert_success_envelope(body)
    data = body["data"]
    assert data["contract"]["model_version"] == model_info["model_version"]
    assert data["contract"]["preprocessing_version"] == model_info["preprocessing_version"]
    assert data["mode"] in {"COLLECT", "EVALUATION", "APPROVED"}
    assert isinstance(data["approved"], bool)
    assert data["liveness_validated"] is False
    assert isinstance(data["duplicate_threshold"], (int, float))
    assert isinstance(data["recognition_threshold"], (int, float))
    assert isinstance(data["maximum_people"], int)
    exercised_operations.add(op)

    # 2. POST /api/v1/collections
    op = ("POST", "/api/v1/collections")
    col_payload = {
        "id": col_id,
        "name": "Live Contract Test Collection",
        "idempotency_key": str(uuid.uuid4()),
    }
    status, body = live_server.request("/api/v1/collections", method="POST", data=col_payload)
    assert status == 200, f"{op} failed: {body}"
    assert_success_envelope(body)
    assert body["data"]["state"] == "CREATED"
    assert body["data"]["collection_id"] == col_id
    exercised_operations.add(op)

    # 3. GET /api/v1/collections
    op = ("GET", "/api/v1/collections")
    status, body = live_server.request("/api/v1/collections", method="GET")
    assert status == 200, f"{op} failed: {body}"
    assert_success_envelope(body)
    assert isinstance(body["data"], list)
    matching_cols = [c for c in body["data"] if c.get("id") == col_id]
    assert len(matching_cols) == 1
    assert matching_cols[0]["name"] == "Live Contract Test Collection"
    assert "created_at" in matching_cols[0]
    exercised_operations.add(op)

    # 4. POST /api/v1/session-tokens
    op = ("POST", "/api/v1/session-tokens")
    token_payload = {
        "collection_id": col_id,
        "scope": "enroll",
        "ttl": 300,
        "person_id": person1_id,
    }
    status, body = live_server.request("/api/v1/session-tokens", method="POST", data=token_payload)
    assert status == 200, f"{op} failed: {body}"
    assert_success_envelope(body)
    assert isinstance(body["data"]["token"], str)
    assert isinstance(body["data"]["expires_at"], int)
    exercised_operations.add(op)

    # 5. POST /api/v1/collections/{id}/people
    op = ("POST", "/api/v1/collections/{id}/people")
    person1_payload = {
        "id": person1_id,
        "name": "Subject Primary",
        "metadata": {"dept": "QA", "tag": "contract"},
        "idempotency_key": str(uuid.uuid4()),
    }
    status, body = live_server.request(
        f"/api/v1/collections/{col_id}/people", method="POST", data=person1_payload
    )
    assert status == 200, f"{op} failed: {body}"
    assert_success_envelope(body)
    assert body["data"]["state"] == "CREATED"
    assert body["data"]["person_id"] == person1_id
    assert body["data"]["person"]["id"] == person1_id
    assert body["data"]["person"]["name"] == "Subject Primary"
    assert body["data"]["person"]["state"] == "NOT_ENROLLED"
    exercised_operations.add(op)

    # Prerequisite: Create second person for observations test
    person2_payload = {
        "id": person2_id,
        "name": "Subject Secondary",
        "metadata": {"dept": "QA", "tag": "observation"},
        "idempotency_key": str(uuid.uuid4()),
    }
    status, body = live_server.request(
        f"/api/v1/collections/{col_id}/people", method="POST", data=person2_payload
    )
    assert status == 200

    # 6. GET /api/v1/collections/{id}/people
    op = ("GET", "/api/v1/collections/{id}/people")
    status, body = live_server.request(f"/api/v1/collections/{col_id}/people", method="GET")
    assert status == 200, f"{op} failed: {body}"
    assert_success_envelope(body)
    assert isinstance(body["data"], list)
    people_ids = {p.get("id") for p in body["data"]}
    assert person1_id in people_ids
    assert person2_id in people_ids
    exercised_operations.add(op)

    # 7. POST /api/v1/collections/{id}/enrollments
    op = ("POST", "/api/v1/collections/{id}/enrollments")
    enroll_payload = {
        "person_id": person1_id,
        "consent": True,
        "capture_session": str(uuid.uuid4()),
        "observations": [unit_vec] * 5,
        "template": unit_vec,
        "model_version": model_info["model_version"],
        "preprocessing_version": model_info["preprocessing_version"],
        "device": "synthetic-camera-1",
        "lighting": "synthetic-d65",
        "idempotency_key": str(uuid.uuid4()),
    }
    status, body = live_server.request(
        f"/api/v1/collections/{col_id}/enrollments", method="POST", data=enroll_payload
    )
    assert status == 200, f"{op} failed: {body}"
    assert_success_envelope(body)
    assert body["data"]["state"] in {"PENDING", "ACTIVE"}
    assert body["data"]["person_id"] == person1_id
    assert "reference_preserved" in body["data"]
    assert "verified" in body["data"]
    exercised_operations.add(op)

    # 8. POST /api/v1/collections/{id}/recognitions
    op = ("POST", "/api/v1/collections/{id}/recognitions")
    recog_payload = {
        "capture_session": str(uuid.uuid4()),
        "observations": [unit_vec] * 5,
        "model_version": model_info["model_version"],
        "preprocessing_version": model_info["preprocessing_version"],
        "eligible_ids": [person1_id],
        "idempotency_key": str(uuid.uuid4()),
    }
    status, body = live_server.request(
        f"/api/v1/collections/{col_id}/recognitions", method="POST", data=recog_payload
    )
    assert status == 200, f"{op} failed: {body}"
    assert_success_envelope(body)
    assert body["data"]["state"] in {"SUGGESTED", "MATCHED", "UNKNOWN", "COLLECTION_ONLY"}
    assert body["data"]["person_id"] == person1_id
    assert isinstance(body["data"]["verified"], bool)
    assert body["data"]["observation_count"] == 5
    exercised_operations.add(op)

    # 9. POST /api/v1/collections/{id}/observations
    op = ("POST", "/api/v1/collections/{id}/observations")
    observe_payload = {
        "person_id": person2_id,
        "consent": True,
        "capture_session": str(uuid.uuid4()),
        "observations": [unit_vec] * 5,
        "template": unit_vec,
        "model_version": model_info["model_version"],
        "preprocessing_version": model_info["preprocessing_version"],
        "device": "synthetic-camera-2",
        "lighting": "synthetic-fluorescent",
        "idempotency_key": str(uuid.uuid4()),
    }
    status, body = live_server.request(
        f"/api/v1/collections/{col_id}/observations", method="POST", data=observe_payload
    )
    assert status == 200, f"{op} failed: {body}"
    assert_success_envelope(body)
    assert body["data"]["state"] == "OBSERVATION_SAVED"
    assert body["data"]["person_id"] == person2_id
    assert body["data"]["split"] in {"calibration", "validation"}
    exercised_operations.add(op)

    # 10. DELETE /api/v1/collections/{id}/people/{person}/face
    op = ("DELETE", "/api/v1/collections/{id}/people/{person}/face")
    delete_payload = {"idempotency_key": str(uuid.uuid4())}
    status, body = live_server.request(
        f"/api/v1/collections/{col_id}/people/{person1_id}/face",
        method="DELETE",
        data=delete_payload,
    )
    assert status == 200, f"{op} failed: {body}"
    assert_success_envelope(body)
    assert body["data"]["state"] == "DELETED"
    assert body["data"]["person_id"] == person1_id
    exercised_operations.add(op)

    # 11. GET /api/v1/collections/{id}/events
    op = ("GET", "/api/v1/collections/{id}/events")
    status, body = live_server.request(f"/api/v1/collections/{col_id}/events", method="GET")
    assert status == 200, f"{op} failed: {body}"
    assert_success_envelope(body)
    assert isinstance(body["data"], list)
    assert len(body["data"]) > 0
    event_names = {e.get("event") for e in body["data"]}
    assert "collection" in event_names
    assert "person" in event_names
    assert "enroll" in event_names
    assert "delete" in event_names
    for ev in body["data"]:
        assert "id" in ev
        assert "state" in ev
        assert "created_at" in ev
    exercised_operations.add(op)

    # Assert EVERY documented operation in openapi.yaml was exercised
    missing = documented_operations - exercised_operations
    unexpected = exercised_operations - documented_operations
    assert exercised_operations == documented_operations, (
        f"Coverage discrepancy: missing={missing}, unexpected={unexpected}"
    )


def test_routed_operations_distinguish_errors_from_misleading_404(live_server: LiveServerContext):
    """Verify that every route rejects unauthenticated requests with 401 instead of 404."""
    documented_operations = load_documented_operations()

    col_id = "test_col"
    person_id = "test_person"

    for method, path in sorted(documented_operations):
        concrete_path = path.replace("{id}", col_id).replace("{person}", person_id)

        # Unauthenticated request must return 401 AUTH_REQUIRED, NOT 404 NOT_FOUND
        status, body = live_server.request(concrete_path, method=method, authorized=False)
        assert status == 401, (
            f"Operation {method} {path} returned {status} instead of 401 when unauthenticated. "
            f"Body: {body}"
        )
        assert isinstance(body, dict)
        assert body.get("error") == 1
        assert body.get("code") == "AUTH_REQUIRED"

    # In contrast, an actual unrouted endpoint must return 404 NOT_FOUND
    unrouted_status, unrouted_body = live_server.request(
        "/api/v1/unknown_synthetic_endpoint", method="GET", authorized=True
    )
    assert unrouted_status == 404
    assert isinstance(unrouted_body, dict)
    assert unrouted_body.get("code") == "NOT_FOUND"


def test_validation_errors_distinguish_routed_endpoints_from_404(live_server: LiveServerContext):
    """Verify that invalid/missing payloads return 400 validation errors, not 404."""
    col_id = f"col_{uuid.uuid4().hex[:8]}"

    # Prerequisite collection
    live_server.request(
        "/api/v1/collections",
        method="POST",
        data={"id": col_id, "name": "Validation Test", "idempotency_key": str(uuid.uuid4())},
    )

    # Missing required fields on POST /api/v1/collections -> 400 INVALID_ID, not 404
    status, body = live_server.request(
        "/api/v1/collections",
        method="POST",
        data={"name": "Missing ID", "idempotency_key": str(uuid.uuid4())},
    )
    assert status == 400
    assert body.get("code") == "INVALID_ID"

    # Missing fields on POST /api/v1/session-tokens -> 400 INVALID_BODY, not 404
    status, body = live_server.request(
        "/api/v1/session-tokens",
        method="POST",
        data={"collection_id": col_id},
    )
    assert status == 400
    assert body.get("code") == "INVALID_BODY"

    # Missing fields on DELETE face -> 400 INVALID_ID, not 404
    status, body = live_server.request(
        f"/api/v1/collections/{col_id}/people/nonexistent/face",
        method="DELETE",
        data={},
    )
    assert status == 400
    assert body.get("code") == "INVALID_ID"


def test_transport_security_and_payload_limits(live_server: LiveServerContext):
    """Enforce transport-level security: origin checks, content-type checks, and body limits."""
    # 1. Disallowed Origin header must return 403 ORIGIN_REJECTED
    status, body = live_server.request(
        "/api/v1/config",
        method="GET",
        headers={"Origin": "https://untrusted-cross-site.example"},
    )
    assert status == 403
    assert body.get("code") == "ORIGIN_REJECTED"

    # 2. Unsupported Content-Type must return 415 INVALID_CONTENT_TYPE
    status, body = live_server.request(
        "/api/v1/collections",
        method="POST",
        raw=b'{"id":"x"}',
        headers={"Content-Type": "text/plain"},
    )
    assert status == 415
    assert body.get("code") == "INVALID_CONTENT_TYPE"

    # 3. Request exceeding MAX_BODY (128 KiB) must return 413 BODY_LIMIT
    large_payload = b"x" * (128 * 1024 + 1)
    status, body = live_server.request(
        "/api/v1/collections",
        method="POST",
        raw=large_payload,
        headers={"Content-Type": "application/json"},
    )
    assert status == 413
    assert body.get("code") == "BODY_LIMIT"

    # 4. Malformed JSON syntax must return 400 INVALID_JSON
    status, body = live_server.request(
        "/api/v1/collections",
        method="POST",
        raw=b'{"unterminated":',
        headers={"Content-Type": "application/json"},
    )
    assert status == 400
    assert body.get("code") == "INVALID_JSON"


def test_session_token_scoping_and_privilege_boundaries(live_server: LiveServerContext):
    """Enforce token scoping and privilege isolation between admin and session tokens."""
    col1_id = f"col1_{uuid.uuid4().hex[:8]}"
    col2_id = f"col2_{uuid.uuid4().hex[:8]}"
    person1_id = f"p1_{uuid.uuid4().hex[:8]}"
    person2_id = f"p2_{uuid.uuid4().hex[:8]}"

    # Setup collections and persons
    for cid in (col1_id, col2_id):
        live_server.request(
            "/api/v1/collections",
            method="POST",
            data={"id": cid, "name": f"Col {cid}", "idempotency_key": str(uuid.uuid4())},
        )
    live_server.request(
        f"/api/v1/collections/{col1_id}/people",
        method="POST",
        data={"id": person1_id, "name": "P1", "idempotency_key": str(uuid.uuid4())},
    )
    live_server.request(
        f"/api/v1/collections/{col1_id}/people",
        method="POST",
        data={"id": person2_id, "name": "P2", "idempotency_key": str(uuid.uuid4())},
    )

    # Issue a session token scoped to 'recognize' on col1_id
    _, resp = live_server.request(
        "/api/v1/session-tokens",
        method="POST",
        data={"collection_id": col1_id, "scope": "recognize", "ttl": 300},
    )
    recognize_token = resp["data"]["token"]

    # Issue a session token scoped to 'enroll' on col1_id restricted to person1_id
    _, resp = live_server.request(
        "/api/v1/session-tokens",
        method="POST",
        data={"collection_id": col1_id, "scope": "enroll", "ttl": 300, "person_id": person1_id},
    )
    enroll_token_p1 = resp["data"]["token"]

    model_info = contract()
    unit_vec = [1.0] + [0.0] * (model_info["dimension"] - 1)

    # 1. Session token cannot access admin routes (GET /api/v1/config) -> 403 TOKEN_SCOPE
    status, body = live_server.request(
        "/api/v1/config", method="GET", token=recognize_token
    )
    assert status == 403
    assert body.get("code") == "TOKEN_SCOPE"

    # 2. Session token cannot issue new tokens -> 403 TOKEN_SCOPE
    status, body = live_server.request(
        "/api/v1/session-tokens",
        method="POST",
        token=recognize_token,
        data={"collection_id": col1_id, "scope": "enroll"},
    )
    assert status == 403
    assert body.get("code") == "TOKEN_SCOPE"

    # 3. 'recognize' session token cannot be used to enroll -> 403 TOKEN_SCOPE
    status, body = live_server.request(
        f"/api/v1/collections/{col1_id}/enrollments",
        method="POST",
        token=recognize_token,
        data={
            "person_id": person1_id,
            "consent": True,
            "capture_session": str(uuid.uuid4()),
            "observations": [unit_vec] * 5,
            "template": unit_vec,
            "model_version": model_info["model_version"],
            "preprocessing_version": model_info["preprocessing_version"],
            "idempotency_key": str(uuid.uuid4()),
        },
    )
    assert status == 403
    assert body.get("code") == "TOKEN_SCOPE"

    # 4. 'enroll' token restricted to person1_id cannot enroll person2_id -> 403 TOKEN_SCOPE
    status, body = live_server.request(
        f"/api/v1/collections/{col1_id}/enrollments",
        method="POST",
        token=enroll_token_p1,
        data={
            "person_id": person2_id,
            "consent": True,
            "capture_session": str(uuid.uuid4()),
            "observations": [unit_vec] * 5,
            "template": unit_vec,
            "model_version": model_info["model_version"],
            "preprocessing_version": model_info["preprocessing_version"],
            "idempotency_key": str(uuid.uuid4()),
        },
    )
    assert status == 403
    assert body.get("code") == "TOKEN_SCOPE"

    # 5. Session token for col1_id cannot be used on col2_id -> 403 TOKEN_SCOPE
    status, body = live_server.request(
        f"/api/v1/collections/{col2_id}/recognitions",
        method="POST",
        token=recognize_token,
        data={
            "capture_session": str(uuid.uuid4()),
            "observations": [unit_vec] * 5,
            "model_version": model_info["model_version"],
            "preprocessing_version": model_info["preprocessing_version"],
            "idempotency_key": str(uuid.uuid4()),
        },
    )
    assert status == 403
    assert body.get("code") == "TOKEN_SCOPE"

    # 6. Valid enrollment using properly scoped session token succeeds -> 200
    status, body = live_server.request(
        f"/api/v1/collections/{col1_id}/enrollments",
        method="POST",
        token=enroll_token_p1,
        data={
            "person_id": person1_id,
            "consent": True,
            "capture_session": str(uuid.uuid4()),
            "observations": [unit_vec] * 5,
            "template": unit_vec,
            "model_version": model_info["model_version"],
            "preprocessing_version": model_info["preprocessing_version"],
            "idempotency_key": str(uuid.uuid4()),
        },
    )
    assert status == 200
    assert_success_envelope(body)
    assert body["data"]["person_id"] == person1_id

    # 7. Valid recognition using properly scoped session token succeeds -> 200
    status, body = live_server.request(
        f"/api/v1/collections/{col1_id}/recognitions",
        method="POST",
        token=recognize_token,
        data={
            "capture_session": str(uuid.uuid4()),
            "observations": [unit_vec] * 5,
            "model_version": model_info["model_version"],
            "preprocessing_version": model_info["preprocessing_version"],
            "idempotency_key": str(uuid.uuid4()),
        },
    )
    assert status == 200
    assert_success_envelope(body)


def test_idempotency_and_capture_session_security(live_server: LiveServerContext):
    """Verify that idempotency replays cached responses and rejects conflicting reuse."""
    col_id = f"col_{uuid.uuid4().hex[:8]}"
    idem_key = str(uuid.uuid4())

    payload = {
        "id": col_id,
        "name": "Original Name",
        "idempotency_key": idem_key,
    }

    # First execution succeeds
    status1, body1 = live_server.request("/api/v1/collections", method="POST", data=payload)
    assert status1 == 200
    assert body1["data"]["state"] == "CREATED"

    # Exact replay returns the cached result
    status2, body2 = live_server.request("/api/v1/collections", method="POST", data=payload)
    assert status2 == 200
    assert body2 == body1

    # Same idempotency key with conflicting payload returns 409 IDEMPOTENCY_CONFLICT
    conflicting_payload = {
        "id": col_id,
        "name": "Different Name",
        "idempotency_key": idem_key,
    }
    status3, body3 = live_server.request(
        "/api/v1/collections", method="POST", data=conflicting_payload
    )
    assert status3 == 409
    assert body3.get("code") == "IDEMPOTENCY_CONFLICT"
