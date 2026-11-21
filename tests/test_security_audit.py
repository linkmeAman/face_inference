"""Proof-of-weakness test suite for confirmed security audit findings.

Each test is marked with @pytest.mark.xfail(strict=True) because it specifies
the SECURE expected behavior once resolved. Under the current codebase, each
test fails, demonstrating the existence and mechanics of the weakness.
"""

import http.server
import json
import subprocess
import threading
import uuid
from email.message import Message
from io import BytesIO
from pathlib import Path
from urllib.error import HTTPError
from urllib.request import Request, urlopen

import pytest
from cryptography.fernet import Fernet

from face_inference.assets import fetch
from face_inference.client import FaceClient
from face_inference.config import ROOT, contract, load_config
from face_inference.matching import FaceError
from face_inference.server import FaceHTTPServer, Handler
from face_inference.service import FaceService
from face_inference.storage import SQLiteRepository


@pytest.mark.xfail(strict=True, reason="SEC-1 Path traversal in asset manifest")
def test_sec_1_manifest_path_traversal(tmp_path: Path):
    """SEC-1: fetch() must reject manifest entries with relative path traversal.

    Currently, fetch() resolves target = dest_path / filename without checking
    if target stays inside dest_path, writing files outside models_dir.
    """
    dest_dir = tmp_path / "models"
    dest_dir.mkdir()
    src_dir = tmp_path / "src"
    src_dir.mkdir()

    escaped_file = tmp_path / "escaped.txt"
    escaped_file.write_bytes(b"content")

    manifest = {
        "files": {
            "../escaped.txt": {
                "sha256": "ed7002b439e9ac84c122f474beff32e31b44537b889ac731915789f7f4d7ba0e",
                "size": 7,
            }
        }
    }
    (src_dir / "manifest.json").write_text(json.dumps(manifest))

    # Secure behavior: fetch must reject path traversal filenames
    with pytest.raises((RuntimeError, ValueError), match=r"(?i)traversal|unsafe|invalid"):
        fetch(dest_dir, from_dir=src_dir)


@pytest.mark.xfail(strict=True, reason="SEC-2 Client leaks Authorization header on redirect")
def test_sec_2_client_redirect_strips_authorization():
    """SEC-2: FaceClient must not forward sensitive bearer tokens across redirects.

    Currently, FaceClient passes Authorization in req.headers, which Python's
    urllib.request forwards to redirected origins.
    """
    received_headers: list[str | None] = []

    class RedirectHandler(http.server.BaseHTTPRequestHandler):
        def do_GET(self):
            if self.path == "/redirect":
                self.send_response(302)
                self.send_header(
                    "Location",
                    f"http://127.0.0.1:{target_server.server_port}/target",
                )
                self.end_headers()
            else:
                self.send_response(404)
                self.end_headers()

        def log_message(self, *args):
            pass

    class TargetHandler(http.server.BaseHTTPRequestHandler):
        def do_GET(self):
            received_headers.append(self.headers.get("Authorization"))
            self.send_response(200)
            self.send_header("Content-Type", "application/json")
            self.end_headers()
            self.wfile.write(b'{"status": "ok"}')

        def log_message(self, *args):
            pass

    try:
        redirect_server = http.server.HTTPServer(("127.0.0.1", 0), RedirectHandler)
        target_server = http.server.HTTPServer(("127.0.0.1", 0), TargetHandler)
    except PermissionError:
        pytest.skip("Socket bind disallowed in current environment")

    t1 = threading.Thread(target=redirect_server.serve_forever, daemon=True)
    t2 = threading.Thread(target=target_server.serve_forever, daemon=True)
    t1.start()
    t2.start()

    try:
        client = FaceClient(
            f"http://127.0.0.1:{redirect_server.server_port}",
            token="SECRET_BEARER_TOKEN",
        )
        client.get("/redirect")
        # Secure behavior: Target server must never receive Authorization header
        assert len(received_headers) == 1
        assert received_headers[0] is None
    finally:
        redirect_server.shutdown()
        target_server.shutdown()


@pytest.mark.xfail(strict=True, reason="SEC-3 Untrusted model-base-url allowed in web component")
def test_sec_3_model_base_url_untrusted_origin_rejected():
    """SEC-3: validateConfig must reject untrusted remote model-base-url origins.

    Currently, validateConfig accepts arbitrary remote origins, which
    face-capture then dynamically imports without validation.
    """
    script = """
    import('./packages/face-capture/src/config.js').then(m => {
      const cfg = m.parseConfig({
        'api-url': 'http://127.0.0.1:6122',
        collection: 'team',
        'model-base-url': 'https://attacker.evil.com/models/'
      });
      const res = m.validateConfig(cfg);
      process.stdout.write(JSON.stringify(res));
    });
    """
    proc = subprocess.run(
        ["node", "--input-type=module", "-e", script],
        capture_output=True,
        text=True,
        check=True,
    )
    res = json.loads(proc.stdout)
    # Secure behavior: validateConfig must reject untrusted external model-base-url
    assert res.get("valid") is False
    assert res.get("code") in ("INVALID_CONFIG", "UNTRUSTED_MODEL_URL")


@pytest.mark.xfail(strict=True, reason="SEC-4 SQLite secure_delete disabled during purge")
def test_sec_4_purge_sqlite_secure_delete_enabled(tmp_path: Path):
    """SEC-4: Storage repository must enable PRAGMA secure_delete.

    Currently, SQLite defaults to secure_delete=0, leaving purged biometrics
    and Fernet ciphertext in unallocated free pages and WAL frames.
    """
    repo = SQLiteRepository(tmp_path / "faces.sqlite3", Fernet.generate_key().decode())
    with repo.connect() as db:
        secure_delete = db.execute("PRAGMA secure_delete").fetchone()[0]

    # Secure behavior: secure_delete must be 1 (ON) or 2 (FAST)
    assert secure_delete in (1, 2)


def test_sec_6_deeply_nested_json_dos():
    """SEC-6: read_json() must reject deeply nested JSON payloads cleanly.

    Currently, payloads with 15,000 nested brackets trigger unhandled
    RecursionError, which is not caught by (ValueError, UnicodeError).
    """
    handler = Handler.__new__(Handler)
    handler.headers = Message()
    handler.headers["Content-Type"] = "application/json"

    nested = ('{"a":' * 15000 + "1" + "}" * 15000).encode("utf-8")
    handler.headers["Content-Length"] = str(len(nested))
    handler.rfile = BytesIO(nested)

    # Secure behavior: Must raise FaceError(400, 'INVALID_JSON'), not unhandled RecursionError
    with pytest.raises(FaceError) as exc_info:
        handler.read_json()
    assert exc_info.value.status == 400
    assert exc_info.value.code == "INVALID_JSON"


def test_sec_7_session_token_subject_binding_on_recognition(tmp_path: Path):
    """SEC-7: Subject-constrained session tokens must be enforced on recognitions.

    Currently, server.py line 249 checks claims.subject only for enrollments,
    allowing a recognition token minted for 'alice' to query 'bob' (200 OK).
    """
    cfg_path = tmp_path / "config.json"
    config = load_config(cfg_path)
    model = contract()
    repo = SQLiteRepository(config["database"], config["encryption_key"])
    service = FaceService(repo, config, model)
    try:
        server = FaceHTTPServer(("127.0.0.1", 0), service, ROOT)
    except PermissionError:
        pytest.skip("Socket bind disallowed in current environment")

    thread = threading.Thread(target=server.serve_forever, daemon=True)
    thread.start()
    base = f"http://127.0.0.1:{server.server_port}"
    admin_token = config["api_token"]

    def req(path: str, data: dict | None = None, token: str = admin_token) -> tuple[int, dict]:
        body = json.dumps(data).encode() if data is not None else None
        headers = {
            "Authorization": f"Bearer {token}",
            "Content-Type": "application/json",
        }
        r = Request(f"{base}{path}", data=body, headers=headers)
        try:
            resp = urlopen(r, timeout=5)
            content = resp.read()
            return resp.status, json.loads(content)
        except HTTPError as err:
            content = err.read()
            return err.code, json.loads(content)

    try:
        # Create collection + two people
        status, _ = req(
            "/api/v1/collections",
            {"id": "team", "name": "Team", "idempotency_key": str(uuid.uuid4())},
        )
        assert status == 200
        status, _ = req(
            "/api/v1/collections/team/people",
            {"id": "alice", "name": "Alice", "idempotency_key": str(uuid.uuid4())},
        )
        assert status == 200
        status, _ = req(
            "/api/v1/collections/team/people",
            {"id": "bob", "name": "Bob", "idempotency_key": str(uuid.uuid4())},
        )
        assert status == 200

        # Mint session token restricted to person_id="alice" with scope="recognize"
        status, token_res = req(
            "/api/v1/session-tokens",
            {
                "collection_id": "team",
                "scope": "recognize",
                "person_id": "alice",
            },
        )
        assert status == 200
        session_token = token_res["data"]["token"]

        # Call POST /recognitions with that token and eligible_ids: ["bob"]
        unit = [1.0] + [0.0] * 127
        rec_body = {
            "capture_session": str(uuid.uuid4()),
            "model_version": model["model_version"],
            "preprocessing_version": model["preprocessing_version"],
            "observations": [unit] * 5,
            "eligible_ids": ["bob"],
            "idempotency_key": str(uuid.uuid4()),
        }
        status, _ = req(
            "/api/v1/collections/team/recognitions",
            rec_body,
            token=session_token,
        )

        # Secure behavior: server must enforce claims.subject and reject recognition for bob (403)
        # Currently, the server accepts this (200), ignoring claims.subject on recognitions.
        assert status == 403
    finally:
        server.shutdown()
        server.server_close()
        thread.join(timeout=3)


@pytest.mark.xfail(strict=True, reason="SEC-8 Model binaries loaded without hash verification")
def test_sec_8_worker_model_hash_verification():
    """SEC-8: Browser worker must verify SHA-256 hashes before loading ONNX models.

    Currently, worker.mjs directly passes URLs to InferenceSession.create
    without verifying hashes against contract.json.
    """
    worker_script = (
        Path(__file__).resolve().parent.parent
        / "src"
        / "face_inference"
        / "web"
        / "browser-face"
        / "worker.mjs"
    ).read_text(encoding="utf-8")

    # Secure behavior: worker.mjs should compute crypto.subtle.digest to verify model hash
    assert "crypto.subtle.digest" in worker_script
    assert "detector_source_sha256" in worker_script
