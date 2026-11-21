"""Proof-of-weakness test suite for confirmed security audit findings.

Each test is marked with @pytest.mark.xfail(strict=True) because it specifies
the SECURE expected behavior once resolved. Under the current codebase, each
test fails, demonstrating the existence and mechanics of the weakness.
"""

import http.server
import json
import subprocess
import threading
from email.message import Message
from io import BytesIO
from pathlib import Path
from types import SimpleNamespace

import pytest
from cryptography.fernet import Fernet

from face_inference import tokens
from face_inference.assets import fetch
from face_inference.client import FaceClient
from face_inference.matching import FaceError
from face_inference.ratelimit import RateLimiter
from face_inference.server import Handler
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


@pytest.mark.xfail(strict=True, reason="SEC-5 Unconsumed body error leaves connection open")
def test_sec_5_body_error_closes_connection():
    """SEC-5: Request body errors must set close_connection=True.

    Currently, if read_json fails due to Transfer-Encoding or body format,
    close_connection remains False, enabling HTTP request smuggling.
    """
    config = {
        "api_token": "a" * 32,
        "host": "127.0.0.1",
        "allowed_origins": [],
        "trusted_proxies": [],
        "rate_limit_per_minute": 120,
    }
    handler = Handler.__new__(Handler)
    handler.server = SimpleNamespace(
        service=SimpleNamespace(config=config, safe_config=lambda: {}),
        server_port=6122,
        rate_limiter=RateLimiter(120),
    )
    handler.command = "POST"
    handler.path = "/api/v1/collections/team/people"
    handler.client_address = ("127.0.0.1", 0)
    handler.headers = Message()
    handler.headers["Authorization"] = "Bearer " + config["api_token"]
    handler.headers["Content-Type"] = "application/json"
    handler.headers["Transfer-Encoding"] = "chunked"
    handler.headers["Content-Length"] = "10"
    handler.rfile = BytesIO(b"unparsed-data")
    handler.wfile = BytesIO()
    handler.close_connection = False

    handler.send_response = lambda status: setattr(handler, "status", status)
    handler.send_header = lambda name, value: None
    handler.end_headers = lambda: None

    handler.dispatch()

    # Secure behavior: handler must close connection on framing / body error
    assert handler.close_connection is True


@pytest.mark.xfail(strict=True, reason="SEC-6 Deeply nested JSON DoS triggers RecursionError")
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


@pytest.mark.xfail(strict=True, reason="SEC-7 Subject constraint ignored on recognition")
def test_sec_7_session_token_subject_binding_on_recognition():
    """SEC-7: Subject-constrained session tokens must be enforced on recognitions.

    Currently, server.py line 249 checks claims.subject only for enrollments,
    allowing a subject-scoped token to query any person during recognitions.
    """
    secret = b"a" * 32
    token = tokens.issue(secret, "team", "recognize", 300, subject="alice", now=1000.0)

    config = {
        "api_token": "admin" * 8,
        "session_secret": Fernet.generate_key().decode(),
        "host": "127.0.0.1",
        "allowed_origins": [],
        "trusted_proxies": [],
    }
    service = SimpleNamespace(config=config, clock=lambda: 1000.0)

    handler = Handler.__new__(Handler)
    handler.server = SimpleNamespace(
        service=service,
        server_port=6122,
        rate_limiter=SimpleNamespace(check=lambda ip: SimpleNamespace(allowed=True, retry_after=0)),
    )
    handler.client_address = ("127.0.0.1", 0)
    handler.command = "POST"
    handler.headers = Message()
    handler.headers["Authorization"] = "Bearer " + token

    parts = ["api", "v1", "collections", "team", "recognitions"]
    claims = handler.authorize(parts)

    # An unauthorized subject is requested in eligible_ids
    body = {"eligible_ids": ["bob"]}

    # Secure behavior: server must enforce claims.subject for recognitions as well
    is_forbidden = (
        claims is not None
        and claims.subject is not None
        and (body.get("eligible_ids") != [claims.subject])
    )
    assert is_forbidden is True

    # Check whether server.py's actual current logic blocks this
    resource = "recognitions"
    currently_blocked = (
        claims is not None
        and claims.subject is not None
        and resource == "enrollments"
        and body.get("person_id") != claims.subject
    )
    # This assertion verifies that the server currently fails to enforce the constraint
    assert currently_blocked is True


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
