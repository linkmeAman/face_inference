"""End-to-end integration tests: Python FaceClient against in-process FaceHTTPServer.

Tests all documented client endpoints against the live server using synthetic
embeddings and verifies contract parity and error mappings.
"""

import os
import tempfile
import threading
import urllib.error
import urllib.request
import uuid
from collections.abc import Generator
from pathlib import Path

import pytest

from face_inference.client import FaceApiError, FaceClient
from face_inference.config import ROOT, contract, load_config
from face_inference.server import FaceHTTPServer
from face_inference.service import FaceService
from face_inference.storage import SQLiteRepository


def unit_vector(index: int, dim: int = 128) -> list[float]:
    """Return a standard basis unit vector in R^dim."""
    return [1.0 if i == index else 0.0 for i in range(dim)]


class ServerContext:
    """Encapsulates the running server and client instance."""

    def __init__(self, client: FaceClient, config: dict, server: FaceHTTPServer) -> None:
        self.client = client
        self.config = config
        self.server = server
        self.base_url = client.base_url


@pytest.fixture
def real_server() -> Generator[ServerContext, None, None]:
    """Start an in-process FaceHTTPServer on 127.0.0.1:0 and yield a ServerContext."""
    tmp = tempfile.TemporaryDirectory()
    config = load_config(Path(tmp.name) / "config.json")
    repo = SQLiteRepository(config["database"], config["encryption_key"])
    service = FaceService(repo, config, contract())

    try:
        server = FaceHTTPServer(("127.0.0.1", 0), service, ROOT)
    except PermissionError as err:
        tmp.cleanup()
        if os.getenv("FACE_TEST_ALLOW_SKIP_BIND") == "1":
            pytest.skip(f"Socket binding forbidden in sandbox: {err}")
        raise
    except OSError as err:
        if "Operation not permitted" in str(err) or "Permission denied" in str(err):
            tmp.cleanup()
            if os.getenv("FACE_TEST_ALLOW_SKIP_BIND") == "1":
                pytest.skip(f"Socket binding forbidden in sandbox: {err}")
            raise
        tmp.cleanup()
        raise

    thread = threading.Thread(target=server.serve_forever, daemon=True)
    thread.start()

    base_url = f"http://127.0.0.1:{server.server_port}"
    client = FaceClient(base_url, config["api_token"], timeout=5.0, retry_backoff=0.01)

    ctx = ServerContext(client=client, config=config, server=server)
    try:
        yield ctx
    finally:
        server.shutdown()
        server.server_close()
        thread.join(timeout=3)
        tmp.cleanup()


def test_e2e_health(real_server: ServerContext) -> None:
    """Verify health endpoint via FaceClient."""
    health = real_server.client.health()
    assert health["status"] == "ok"
    assert health["api_version"] == "1"
    assert health["storage"] == "sqlite"


def test_e2e_config_and_token_redaction(real_server: ServerContext) -> None:
    """Verify config endpoint and ensure private secrets are not leaked."""
    cfg = real_server.client.config()
    assert cfg["mode"] == "EVALUATION"
    assert "contract" in cfg
    assert cfg["contract"]["dimension"] == 128
    assert cfg["contract"]["model_version"] == "sface-int8bq-fb143eea"

    # Secrets must never be exposed
    cfg_dump = str(cfg)
    assert real_server.config["api_token"] not in cfg_dump
    assert real_server.config["encryption_key"] not in cfg_dump


def test_e2e_collection_crud(real_server: ServerContext) -> None:
    """Verify creating and listing collections."""
    client = real_server.client
    created = client.create_collection("team-eng", "Engineering Team")
    assert created["state"] == "CREATED"
    assert created["collection_id"] == "team-eng"

    collections = client.list_collections()
    assert any(c["id"] == "team-eng" for c in collections)
    matching = next(c for c in collections if c["id"] == "team-eng")
    assert matching["name"] == "Engineering Team"


def test_e2e_person_crud(real_server: ServerContext) -> None:
    """Verify adding person and listing people in a collection."""
    client = real_server.client
    client.create_collection("team-prod", "Product Team")

    person = client.add_person(
        "team-prod",
        id="alice",
        name="Alice Smith",
        metadata={"department": "Security"},
    )
    assert person["state"] == "CREATED"
    assert person["person_id"] == "alice"

    people = client.list_people("team-prod")
    assert len(people) == 1
    assert people[0]["id"] == "alice"
    assert people[0]["name"] == "Alice Smith"
    assert people[0]["state"] == "NOT_ENROLLED"
    assert people[0]["metadata"] == {"department": "Security"}


def test_e2e_enroll_and_recognize_with_synthetic_embeddings(
    real_server: ServerContext,
) -> None:
    """Verify enrollment and recognition lifecycle using synthetic embeddings."""
    client = real_server.client
    client.create_collection("team-ops", "Ops Team")
    client.add_person("team-ops", id="alice", name="Alice")

    model = contract()
    vec = unit_vector(0)
    capture_session = str(uuid.uuid4())

    enrolled = client.enroll(
        collection_id="team-ops",
        person_id="alice",
        observations=[vec] * 5,
        template=vec,
        capture_session=capture_session,
        model_version=model["model_version"],
        preprocessing_version=model["preprocessing_version"],
        consent=True,
    )
    assert enrolled["state"] == "PENDING"
    assert enrolled["person_id"] == "alice"

    # Gallery person state is now PENDING
    people = client.list_people("team-ops")
    alice_row = next(p for p in people if p["id"] == "alice")
    assert alice_row["state"] == "PENDING"

    # Recognize using same vector matches alice
    rec_session = str(uuid.uuid4())
    recognize_res = client.recognize(
        collection_id="team-ops",
        observations=[vec] * 5,
        capture_session=rec_session,
        model_version=model["model_version"],
        preprocessing_version=model["preprocessing_version"],
    )
    assert recognize_res["state"] == "SUGGESTED"
    assert recognize_res["person_id"] == "alice"
    assert recognize_res["person_name"] == "Alice"
    assert recognize_res["reason"] == "MATCHED"


def test_e2e_observation_recording(real_server: ServerContext) -> None:
    """Verify recording an observation without altering gallery enrollment state."""
    client = real_server.client
    client.create_collection("team-qa", "QA Team")
    client.add_person("team-qa", id="bob", name="Bob")

    model = contract()
    vec = unit_vector(1)
    obs_session = str(uuid.uuid4())

    observed = client.record_observation(
        collection_id="team-qa",
        person_id="bob",
        observations=[vec] * 5,
        template=vec,
        capture_session=obs_session,
        model_version=model["model_version"],
        preprocessing_version=model["preprocessing_version"],
        consent=True,
    )
    assert observed["state"] == "OBSERVATION_SAVED"
    assert observed["person_id"] == "bob"
    assert observed["split"] == "calibration"

    # Gallery person state remains NOT_ENROLLED
    people = client.list_people("team-qa")
    bob_row = next(p for p in people if p["id"] == "bob")
    assert bob_row["state"] == "NOT_ENROLLED"


def test_e2e_events(real_server: ServerContext) -> None:
    """Verify audit events retrieval via FaceClient."""
    client = real_server.client
    client.create_collection("team-events", "Events Team")
    client.add_person("team-events", id="charlie", name="Charlie")

    events = client.list_events("team-events")
    assert isinstance(events, list)
    assert len(events) >= 2

    # Events are recorded newest first
    event_actions = [e["event"] for e in events]
    assert "person" in event_actions
    assert "collection" in event_actions


def test_e2e_delete_person_face(real_server: ServerContext) -> None:
    """Verify face template deletion clears gallery reference."""
    client = real_server.client
    client.create_collection("team-del", "Deletion Team")
    client.add_person("team-del", id="david", name="David")

    model = contract()
    vec = unit_vector(2)
    session = str(uuid.uuid4())

    client.enroll(
        collection_id="team-del",
        person_id="david",
        observations=[vec] * 5,
        template=vec,
        capture_session=session,
        model_version=model["model_version"],
        preprocessing_version=model["preprocessing_version"],
        consent=True,
    )

    del_res = client.delete_face("team-del", "david")
    assert del_res["state"] == "DELETED"
    assert del_res["person_id"] == "david"

    # Person state is reset to NOT_ENROLLED
    people = client.list_people("team-del")
    david_row = next(p for p in people if p["id"] == "david")
    assert david_row["state"] == "NOT_ENROLLED"

    # Recognition now returns UNKNOWN
    rec_session = str(uuid.uuid4())
    rec = client.recognize(
        collection_id="team-del",
        observations=[vec] * 5,
        capture_session=rec_session,
        model_version=model["model_version"],
        preprocessing_version=model["preprocessing_version"],
    )
    assert rec["state"] == "UNKNOWN"


def test_e2e_error_mapping_unauthorized(real_server: ServerContext) -> None:
    """Verify 401 FaceApiError mapping on invalid authentication token."""
    bad_client = FaceClient(
        real_server.base_url,
        token="invalid-bearer-token",
        timeout=5.0,
        retry_backoff=0.01,
    )
    with pytest.raises(FaceApiError) as exc_info:
        bad_client.get_config()
    assert exc_info.value.status == 401
    assert exc_info.value.code == "AUTH_REQUIRED"


def test_e2e_error_mapping_unknown_collection(real_server: ServerContext) -> None:
    """Verify 404 FaceApiError mapping when accessing non-existent collection."""
    client = real_server.client
    with pytest.raises(FaceApiError) as exc_info:
        client.list_people("nonexistent-collection-id")
    assert exc_info.value.status == 404
    assert exc_info.value.code == "COLLECTION_NOT_FOUND"

    with pytest.raises(FaceApiError) as exc_info:
        client.add_person("nonexistent-collection-id", id="x", name="X")
    assert exc_info.value.status == 404
    assert exc_info.value.code == "COLLECTION_NOT_FOUND"


def test_e2e_cors_preflight_is_not_clients_job(real_server: ServerContext) -> None:
    """Verify FaceClient communicates directly via standard HTTP without browser CORS preflight.

    FaceClient is a backend/CLI HTTP client and does not issue browser OPTIONS
    preflight checks. The server only answers OPTIONS requests for browser origins.
    """
    client = real_server.client
    # Direct GET/POST calls succeed without preflight or Origin headers
    health = client.health()
    assert health["status"] == "ok"

    # Confirm that raw OPTIONS requests without an allowed browser Origin are rejected (403)
    # confirming OPTIONS is exclusively reserved for browser preflight dispatch.
    req = urllib.request.Request(
        f"{real_server.base_url}/api/v1/config",
        method="OPTIONS",
    )
    with pytest.raises(urllib.error.HTTPError) as exc_info:
        urllib.request.urlopen(req, timeout=5)
    assert exc_info.value.code == 403


# Contract parity tests.
# Verifies client.py TypedDict expectations align with service.py responses and OpenAPI schema.


def test_contract_parity_event_type_field(real_server: ServerContext) -> None:
    """Verify Event schema parity on event field in Event model."""
    client = real_server.client
    client.create_collection("parity-events", "Parity Events")
    events = client.list_events("parity-events")
    assert len(events) > 0
    # Service and schema return 'event', not 'event_type'
    assert "event" in events[0]
    assert events[0]["event"] == "collection"
    assert "state" in events[0]
    assert "id" in events[0]
    assert "created_at" in events[0]
    assert "event_type" not in events[0]


def test_contract_parity_config_top_level_model_version(
    real_server: ServerContext,
) -> None:
    """Verify ConfigResponse nests model_version under contract matching server safe_config."""
    client = real_server.client
    cfg = client.config()
    assert "contract" in cfg
    assert isinstance(cfg["contract"], dict)
    assert "model_version" in cfg["contract"]
    assert cfg["contract"]["model_version"] == "sface-int8bq-fb143eea"
    assert "mode" in cfg
    assert "approved" in cfg
    assert "model_version" not in cfg


def test_contract_parity_create_collection_response_schema(
    real_server: ServerContext,
) -> None:
    """Verify create_collection returns state and collection_id matching server response."""
    client = real_server.client
    created = client.create_collection("parity-col", "Parity Collection")
    assert "state" in created
    assert created["state"] == "CREATED"
    assert "collection_id" in created
    assert created["collection_id"] == "parity-col"
    assert "id" not in created


@pytest.mark.parametrize(
    "error",
    [PermissionError("denied"), OSError("Operation not permitted"), OSError("Permission denied")],
)
@pytest.mark.parametrize("allow_skip", [False, True])
def test_bind_permission_skip_is_opt_in(
    monkeypatch: pytest.MonkeyPatch, error: Exception, allow_skip: bool
) -> None:
    if allow_skip:
        monkeypatch.setenv("FACE_TEST_ALLOW_SKIP_BIND", "1")
    else:
        monkeypatch.delenv("FACE_TEST_ALLOW_SKIP_BIND", raising=False)

    def fail_bind(*args: object, **kwargs: object) -> None:
        raise error

    monkeypatch.setattr("test_e2e_client.FaceHTTPServer", fail_bind)
    expected = pytest.skip.Exception if allow_skip else type(error)
    with pytest.raises(expected):
        next(real_server.__wrapped__())


def test_bind_other_error_is_not_skipped(monkeypatch: pytest.MonkeyPatch) -> None:
    monkeypatch.setenv("FACE_TEST_ALLOW_SKIP_BIND", "1")

    def fail_bind(*args: object, **kwargs: object) -> None:
        raise OSError("address already in use")

    monkeypatch.setattr("test_e2e_client.FaceHTTPServer", fail_bind)
    with pytest.raises(OSError, match="address already in use"):
        next(real_server.__wrapped__())
