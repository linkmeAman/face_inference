"""Contract verification tests for FaceClient against the live server contract.

Verifies:
1. lighting parameter contract (string label, e.g. "measured-dark", rejecting numeric float)
2. Person schema (expires_at present, created_at/updated_at absent from server DTO)
3. RecognitionResult schema (state, person_id, person_name, reason, verified, etc.; score absent)
4. EnrollmentResult schema (reference_preserved, verified, etc.; capture_session absent)
5. ObservationResult and DeleteResult schemas
6. Exception hierarchy (public FaceError classes preserved and distinct from matching.FaceError)
7. Reachability and retry behavior of except FaceApiError in FaceClient._perform
"""

import json
import tempfile
import threading
import uuid
from collections.abc import Generator
from http.server import BaseHTTPRequestHandler, ThreadingHTTPServer
from pathlib import Path
from typing import get_type_hints

import pytest

from face_inference import matching
from face_inference.client import (
    DeleteResult,
    EnrollmentResult,
    FaceApiError,
    FaceClient,
    FaceClientError,
    FaceConnectionError,
    FaceError,
    ObservationResult,
    Person,
    RecognitionResult,
    UpsertPersonResult,
)
from face_inference.config import ROOT, contract, load_config
from face_inference.server import FaceHTTPServer
from face_inference.service import FaceService
from face_inference.storage import SQLiteRepository


def unit_vector(index: int, dim: int = 128) -> list[float]:
    """Return standard basis unit vector in R^dim."""
    return [1.0 if i == index else 0.0 for i in range(dim)]


class LiveContext:
    def __init__(self, client: FaceClient, config: dict, server: FaceHTTPServer) -> None:
        self.client = client
        self.config = config
        self.server = server
        self.base_url = client.base_url


@pytest.fixture
def live_server() -> Generator[LiveContext, None, None]:
    """Start in-process FaceHTTPServer on 127.0.0.1:0."""
    tmp = tempfile.TemporaryDirectory()
    config = load_config(Path(tmp.name) / "config.json")
    repo = SQLiteRepository(config["database"], config["encryption_key"])
    service = FaceService(repo, config, contract())

    try:
        server = FaceHTTPServer(("127.0.0.1", 0), service, ROOT)
    except PermissionError as err:
        tmp.cleanup()
        pytest.skip(f"Socket binding forbidden in sandbox: {err}")
    except OSError as err:
        if "Operation not permitted" in str(err) or "Permission denied" in str(err):
            tmp.cleanup()
            pytest.skip(f"Socket binding forbidden in sandbox: {err}")
        tmp.cleanup()
        raise

    thread = threading.Thread(target=server.serve_forever, daemon=True)
    thread.start()

    port = server.server_address[1]
    client = FaceClient(
        f"http://127.0.0.1:{port}",
        config["api_token"],
        timeout=5.0,
        retry_backoff=0.01,
    )
    try:
        yield LiveContext(client, config, server)
    finally:
        server.shutdown()
        server.server_close()
        thread.join(timeout=2.0)
        tmp.cleanup()


def test_contract_lighting_type_annotations():
    """Verify lighting is annotated as str | None on enroll and record_observation."""
    enroll_hints = get_type_hints(FaceClient.enroll)
    assert enroll_hints.get("lighting") == (str | None)

    obs_hints = get_type_hints(FaceClient.record_observation)
    assert obs_hints.get("lighting") == (str | None)


def test_contract_lighting_live_call_measured_dark(live_server: LiveContext):
    """Exercise real lighting='measured-dark' call against live server."""
    client = live_server.client
    client.create_collection("col-lighting", "Lighting Test Collection")
    client.add_person("col-lighting", id="p-dark", name="Person Dark")

    m = contract()
    vec = unit_vector(0)
    session_id = str(uuid.uuid4())

    res = client.enroll(
        "col-lighting",
        "p-dark",
        observations=[vec] * 5,
        template=vec,
        capture_session=session_id,
        model_version=m["model_version"],
        preprocessing_version=m["preprocessing_version"],
        lighting="measured-dark",
    )
    assert res["state"] in ("PENDING", "ACTIVE")
    assert res["person_id"] == "p-dark"


def test_contract_lighting_live_call_float_rejected(live_server: LiveContext):
    """Verify live server rejects float lighting with 400 INVALID_CAPTURE_METADATA."""
    client = live_server.client
    client.create_collection("col-light-float", "Lighting Float Collection")
    client.add_person("col-light-float", id="p-float", name="Person Float")

    m = contract()
    vec = unit_vector(0)
    session_id = str(uuid.uuid4())

    with pytest.raises(FaceApiError) as exc_info:
        client.enroll(
            "col-light-float",
            "p-float",
            observations=[vec] * 5,
            template=vec,
            capture_session=session_id,
            model_version=m["model_version"],
            preprocessing_version=m["preprocessing_version"],
            lighting=0.5,
        )
    assert exc_info.value.status == 400
    assert exc_info.value.code == "INVALID_CAPTURE_METADATA"
    assert "Device and lighting labels must be bounded strings" in exc_info.value.message


def test_contract_person_type_hints():
    """Verify Person TypedDict declares expires_at and excludes created_at/updated_at."""
    hints = get_type_hints(Person)
    assert "id" in hints
    assert "name" in hints
    assert "metadata" in hints
    assert "state" in hints
    assert "expires_at" in hints
    assert hints["expires_at"] == (float | None)

    # Server DTO never returns created_at or updated_at
    assert "created_at" not in hints
    assert "updated_at" not in hints

    upsert_hints = get_type_hints(UpsertPersonResult)
    assert set(upsert_hints.keys()) == {"state", "person_id", "person"}
    assert upsert_hints["person"] == Person


def test_contract_person_live_response_shape(live_server: LiveContext):
    """Verify live server person response shapes match Person / UpsertPersonResult."""
    client = live_server.client
    client.create_collection("col-people", "People Test Collection")

    # Upsert person
    res = client.add_person(
        "col-people",
        id="alice",
        name="Alice Smith",
        metadata={"role": "tester"},
    )
    assert res["state"] == "CREATED"
    assert res["person_id"] == "alice"
    assert "person" in res
    person_obj = res["person"]
    expected_person_keys = {"id", "name", "metadata", "state", "expires_at"}
    assert set(person_obj.keys()) == expected_person_keys
    assert person_obj["id"] == "alice"
    assert person_obj["expires_at"] is None

    # List people
    people = client.list_people("col-people")
    assert len(people) == 1
    assert set(people[0].keys()) == expected_person_keys
    assert "created_at" not in people[0]
    assert "updated_at" not in people[0]


def test_contract_recognition_result_type_hints():
    """Verify RecognitionResult declares real server fields and excludes score/collection_id."""
    hints = get_type_hints(RecognitionResult)
    expected_fields = {
        "state",
        "person_id",
        "person_name",
        "reason",
        "verified",
        "business_action",
        "observation_count",
        "model_version",
        "preprocessing_version",
    }
    assert expected_fields.issubset(set(hints.keys()))
    assert "score" not in hints
    assert "collection_id" not in hints


def test_contract_recognition_result_live_response_shapes(live_server: LiveContext):
    """Verify live recognition response shapes for match and unknown outcomes."""
    client = live_server.client
    client.create_collection("col-rec", "Recognition Test Collection")
    client.add_person("col-rec", id="bob", name="Bob Builder")

    m = contract()
    vec = unit_vector(1)
    session_id = str(uuid.uuid4())

    # Enroll bob
    client.enroll(
        "col-rec",
        "bob",
        observations=[vec] * 5,
        template=vec,
        capture_session=session_id,
        model_version=m["model_version"],
        preprocessing_version=m["preprocessing_version"],
    )

    # 1. Matched / Suggested outcome
    match_res = client.recognize(
        "col-rec",
        observations=[vec] * 5,
        capture_session=str(uuid.uuid4()),
        model_version=m["model_version"],
        preprocessing_version=m["preprocessing_version"],
    )
    assert match_res["state"] in ("SUGGESTED", "MATCHED")
    assert match_res["person_id"] == "bob"
    assert match_res["person_name"] == "Bob Builder"
    assert "score" not in match_res
    assert "collection_id" not in match_res
    assert match_res["business_action"] is False
    assert match_res["observation_count"] == 5

    # 2. Unknown outcome
    other_vec = unit_vector(2)
    unknown_res = client.recognize(
        "col-rec",
        observations=[other_vec] * 5,
        capture_session=str(uuid.uuid4()),
        model_version=m["model_version"],
        preprocessing_version=m["preprocessing_version"],
    )
    assert unknown_res["state"] == "UNKNOWN"
    assert unknown_res["person_id"] is None
    assert unknown_res["person_name"] is None
    assert unknown_res["reason"] == "BELOW_CUTOFF"
    assert "score" not in unknown_res
    assert "collection_id" not in unknown_res


def test_contract_enrollment_result_type_hints():
    """Verify EnrollmentResult declares real server fields and excludes extra keys."""
    hints = get_type_hints(EnrollmentResult)
    expected_fields = {
        "state",
        "person_id",
        "reference_preserved",
        "previous_reference_preserved",
        "duplicate_person_id",
        "ambiguous",
        "verified",
        "evaluation_only",
    }
    assert expected_fields.issubset(set(hints.keys()))
    assert "capture_session" not in hints
    assert "collection_id" not in hints


def test_contract_enrollment_result_live_response_shapes(live_server: LiveContext):
    """Verify live enrollment responses on success and duplicate conflict."""
    client = live_server.client
    client.create_collection("col-enr", "Enrollment Test Collection")
    client.add_person("col-enr", id="carol", name="Carol Danvers")
    client.add_person("col-enr", id="carol2", name="Carol Clone")

    m = contract()
    vec = unit_vector(3)

    # 1. Success enrollment
    res1 = client.enroll(
        "col-enr",
        "carol",
        observations=[vec] * 5,
        template=vec,
        capture_session=str(uuid.uuid4()),
        model_version=m["model_version"],
        preprocessing_version=m["preprocessing_version"],
    )
    assert res1["state"] in ("PENDING", "ACTIVE")
    assert res1["person_id"] == "carol"
    assert res1["reference_preserved"] is False
    assert "capture_session" not in res1
    assert "collection_id" not in res1

    # 2. Duplicate conflict enrollment
    res2 = client.enroll(
        "col-enr",
        "carol2",
        observations=[vec] * 5,
        template=vec,
        capture_session=str(uuid.uuid4()),
        model_version=m["model_version"],
        preprocessing_version=m["preprocessing_version"],
    )
    assert res2["state"] == "REJECTED_DUPLICATE"
    assert res2["person_id"] == "carol2"
    assert res2["duplicate_person_id"] == "carol"
    assert res2["previous_reference_preserved"] is False
    assert "capture_session" not in res2
    assert "collection_id" not in res2


def test_contract_observation_result_type_hints():
    """Verify ObservationResult declares state, person_id, split."""
    hints = get_type_hints(ObservationResult)
    assert set(hints.keys()) == {"state", "person_id", "split"}
    assert "recorded" not in hints
    assert "capture_session" not in hints


def test_contract_observation_result_live_response_shape(live_server: LiveContext):
    """Verify live observation response matches state, person_id, split."""
    client = live_server.client
    client.create_collection("col-obs", "Observation Collection")
    client.add_person("col-obs", id="dave", name="Dave Bowman")

    m = contract()
    vec = unit_vector(4)
    res = client.record_observation(
        "col-obs",
        "dave",
        observations=[vec] * 5,
        template=vec,
        capture_session=str(uuid.uuid4()),
        model_version=m["model_version"],
        preprocessing_version=m["preprocessing_version"],
        lighting="measured-dark",
    )
    assert res == {
        "state": "OBSERVATION_SAVED",
        "person_id": "dave",
        "split": "calibration",
    }


def test_contract_delete_result_type_hints():
    """Verify DeleteResult declares state and person_id (excluding collection_id)."""
    hints = get_type_hints(DeleteResult)
    assert set(hints.keys()) == {"state", "person_id"}
    assert "collection_id" not in hints


def test_contract_delete_result_live_response_shape(live_server: LiveContext):
    """Verify live delete response returns state and person_id."""
    client = live_server.client
    client.create_collection("col-del", "Delete Collection")
    client.add_person("col-del", id="eve", name="Eve Polastri")

    res = client.delete_face("col-del", "eve")
    assert res == {
        "state": "DELETED",
        "person_id": "eve",
    }


def test_contract_public_face_error_hierarchy():
    """Verify public FaceError hierarchy in client.py."""
    assert issubclass(FaceError, Exception)
    assert issubclass(FaceClientError, FaceError)
    assert issubclass(FaceApiError, FaceError)
    assert issubclass(FaceConnectionError, FaceError)

    err = FaceApiError(400, "BAD_INPUT", "Invalid payload")
    assert err.status == 400
    assert err.code == "BAD_INPUT"
    assert err.message == "Invalid payload"
    assert str(err) == "[400] BAD_INPUT: Invalid payload"


def test_contract_face_error_matching_coexistence():
    """Verify matching.FaceError and client.FaceError coexist independently."""
    server_err = matching.FaceError("DIM_MISMATCH", "Dimension mismatch", 400)
    client_err = FaceError("Client error")

    assert type(server_err) is not type(client_err)
    assert not isinstance(server_err, FaceError)
    assert not isinstance(client_err, matching.FaceError)


def test_contract_face_api_error_reachable_on_200_envelope():
    """Verify FaceApiError is reached when HTTP 200 contains an error envelope."""
    class Envelope200Handler(BaseHTTPRequestHandler):
        def log_message(self, *args):
            pass

        def do_GET(self):
            payload = {
                "error": 1,
                "code": "ENVELOPE_ERROR",
                "error_msg": "Application error in 200 envelope",
                "data": {},
            }
            body = json.dumps(payload).encode("utf-8")
            self.send_response(200)
            self.send_header("Content-Type", "application/json")
            self.send_header("Content-Length", str(len(body)))
            self.end_headers()
            self.wfile.write(body)

    server = ThreadingHTTPServer(("127.0.0.1", 0), Envelope200Handler)
    thread = threading.Thread(target=server.serve_forever, daemon=True)
    thread.start()
    try:
        port = server.server_address[1]
        client = FaceClient(f"http://127.0.0.1:{port}", "token", retry_backoff=0.01)
        with pytest.raises(FaceApiError) as exc_info:
            client.get("/test-envelope")
        assert exc_info.value.status == 200
        assert exc_info.value.code == "ENVELOPE_ERROR"
        assert exc_info.value.message == "Application error in 200 envelope"
    finally:
        server.shutdown()
        server.server_close()
        thread.join(timeout=2.0)


def test_contract_face_api_error_retry_behavior():
    """Verify FaceApiError retry behavior: 500/429 retried, 400 not retried."""
    attempts = 0

    class RetryHandler(BaseHTTPRequestHandler):
        def log_message(self, *args):
            pass

        def do_POST(self):
            nonlocal attempts
            attempts += 1
            payload = {"error": 1, "code": "TEMP_DOWN", "error_msg": "Transient"}
            body = json.dumps(payload).encode("utf-8")
            # Return 500 inside urllib HTTPError
            self.send_response(500)
            self.send_header("Content-Type", "application/json")
            self.send_header("Content-Length", str(len(body)))
            self.end_headers()
            self.wfile.write(body)

    server = ThreadingHTTPServer(("127.0.0.1", 0), RetryHandler)
    thread = threading.Thread(target=server.serve_forever, daemon=True)
    thread.start()
    try:
        port = server.server_address[1]
        client = FaceClient(f"http://127.0.0.1:{port}", "token", retry_backoff=0.01)
        with pytest.raises(FaceApiError) as exc_info:
            client.send("/mutate", {"idempotency_key": "test-key-12345"})
        assert exc_info.value.status == 500
        assert attempts == 3  # Initial + 2 retries
    finally:
        server.shutdown()
        server.server_close()
        thread.join(timeout=2.0)
