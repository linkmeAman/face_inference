"""HTTP trust boundary tests against in-process FaceHTTPServer.

Tests request framing and content boundaries (P2 issue 9):
- Transfer-Encoding header rejected with 400
- Non-JSON content type rejected with 415
- Missing Content-Length rejected with 411
- Invalid Content-Length rejected with 411
- Zero Content-Length rejected with 413
- Negative Content-Length rejected with 413
- Out-of-range Content-Length rejected with 413
- Unauthenticated requests rejected before request body parsing
- Symlinked static files rejected with 404 (present at startup & replaced after registration)
"""

from __future__ import annotations

import http.client
import json
import os
import tempfile
import threading
from collections.abc import Generator
from pathlib import Path
from typing import Any

import pytest

from face_inference.config import ROOT, contract, load_config
from face_inference.server import FaceHTTPServer
from face_inference.service import FaceService
from face_inference.storage import SQLiteRepository


class BoundaryServerContext:
    """Encapsulates a running FaceHTTPServer for boundary testing."""

    def __init__(
        self, host: str, port: int, token: str, config: dict[str, Any], server: FaceHTTPServer
    ) -> None:
        self.host = host
        self.port = port
        self.token = token
        self.config = config
        self.server = server

    def raw_request(
        self,
        method: str,
        path: str,
        headers: dict[str, str] | None = None,
        body: bytes | None = None,
    ) -> tuple[int, dict[str, Any], bytes]:
        """Send a raw HTTP request and return (status, parsed_json_or_empty, raw_body)."""
        conn = http.client.HTTPConnection(self.host, self.port, timeout=5)
        try:
            conn.putrequest(method, path)
            for key, val in (headers or {}).items():
                conn.putheader(key, val)
            conn.endheaders()
            if body:
                conn.send(body)
            response = conn.getresponse()
            raw = response.read()
            data: dict[str, Any] = {}
            if response.headers.get_content_type() == "application/json":
                try:
                    data = json.loads(raw)
                except (ValueError, UnicodeDecodeError):
                    data = {}
            return response.status, data, raw
        finally:
            conn.close()


@pytest.fixture
def boundary_server() -> Generator[BoundaryServerContext, None, None]:
    """Start an isolated FaceHTTPServer instance on 127.0.0.1:0."""
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

    ctx = BoundaryServerContext(
        host="127.0.0.1",
        port=server.server_port,
        token=config["api_token"],
        config=config,
        server=server,
    )
    try:
        yield ctx
    finally:
        server.shutdown()
        server.server_close()
        thread.join(timeout=3)
        tmp.cleanup()


class StaticAssetsContext:
    """Encapsulates a running FaceHTTPServer with custom web assets for symlink tests."""

    def __init__(self, host: str, port: int, root: Path, server: FaceHTTPServer) -> None:
        self.host = host
        self.port = port
        self.root = root
        self.server = server

    def get(self, path: str) -> tuple[int, dict[str, Any], bytes]:
        """Perform a GET request against the static assets server."""
        conn = http.client.HTTPConnection(self.host, self.port, timeout=5)
        try:
            conn.request("GET", path)
            response = conn.getresponse()
            raw = response.read()
            data: dict[str, Any] = {}
            if response.headers.get_content_type() == "application/json":
                try:
                    data = json.loads(raw)
                except (ValueError, UnicodeDecodeError):
                    data = {}
            return response.status, data, raw
        finally:
            conn.close()


@pytest.fixture
def static_server() -> Generator[StaticAssetsContext, None, None]:
    """Start an isolated server with custom web directory containing symlinks."""
    tmp = tempfile.TemporaryDirectory()
    tmp_path = Path(tmp.name)
    config = load_config(tmp_path / "config.json")
    repo = SQLiteRepository(config["database"], config["encryption_key"])
    service = FaceService(repo, config, contract())

    web_root = tmp_path / "web"
    web_root.mkdir()
    (web_root / "browser-face").mkdir()

    # Create real files
    (web_root / "index.html").write_text("<!doctype html><title>Test</title>")
    (web_root / "real_file.css").write_text("body { margin: 0; }")
    (web_root / "target.css").write_text("body { color: blue; }")

    # Symlink present at startup in web root
    os.symlink(web_root / "target.css", web_root / "startup_symlink.css")

    # Real file that will be replaced with a symlink after registration
    (web_root / "replaced_later.css").write_text("body { color: red; }")

    try:
        server = FaceHTTPServer(("127.0.0.1", 0), service, web_root)
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

    ctx = StaticAssetsContext(
        host="127.0.0.1",
        port=server.server_port,
        root=web_root,
        server=server,
    )
    try:
        yield ctx
    finally:
        server.shutdown()
        server.server_close()
        thread.join(timeout=3)
        tmp.cleanup()


# ==============================================================================
# Framing & Content Boundary Tests (authenticated to reach parsing)
# ==============================================================================


def test_transfer_encoding_chunked_rejected_with_400(
    boundary_server: BoundaryServerContext,
) -> None:
    """Authenticated request with Transfer-Encoding header is rejected with 400 (P2 issue 9)."""
    status, body, _ = boundary_server.raw_request(
        "POST",
        "/api/v1/session-tokens",
        headers={
            "Authorization": f"Bearer {boundary_server.token}",
            "Content-Type": "application/json",
            "Transfer-Encoding": "chunked",
        },
        body=b"0\r\n\r\n",
    )
    assert status == 400
    assert body.get("error") == 1
    assert body.get("code") == "INVALID_BODY"
    assert "Chunked request bodies are not supported" in body.get("message", "")


@pytest.mark.parametrize(
    "content_type",
    [
        "text/plain",
        "application/x-www-form-urlencoded",
        "multipart/form-data",
        "application/xml",
    ],
)
def test_non_json_content_type_rejected_with_415(
    boundary_server: BoundaryServerContext, content_type: str
) -> None:
    """Authenticated request with non-JSON Content-Type is rejected with 415 (P2 issue 9)."""
    payload = b'{"collection_id": "test"}'
    status, body, _ = boundary_server.raw_request(
        "POST",
        "/api/v1/session-tokens",
        headers={
            "Authorization": f"Bearer {boundary_server.token}",
            "Content-Type": content_type,
            "Content-Length": str(len(payload)),
        },
        body=payload,
    )
    assert status == 415
    assert body.get("error") == 1
    assert body.get("code") == "INVALID_CONTENT_TYPE"
    assert "Use application/json" in body.get("message", "")


def test_missing_content_type_rejected_with_415(boundary_server: BoundaryServerContext) -> None:
    """Authenticated request with missing Content-Type header is rejected with 415."""
    payload = b'{"collection_id": "test"}'
    status, body, _ = boundary_server.raw_request(
        "POST",
        "/api/v1/session-tokens",
        headers={
            "Authorization": f"Bearer {boundary_server.token}",
            "Content-Length": str(len(payload)),
        },
        body=payload,
    )
    assert status == 415
    assert body.get("error") == 1
    assert body.get("code") == "INVALID_CONTENT_TYPE"


def test_missing_content_length_rejected_with_411(
    boundary_server: BoundaryServerContext,
) -> None:
    """Authenticated request with missing Content-Length header is rejected with 411."""
    payload = b'{"collection_id": "test"}'
    status, body, _ = boundary_server.raw_request(
        "POST",
        "/api/v1/session-tokens",
        headers={
            "Authorization": f"Bearer {boundary_server.token}",
            "Content-Type": "application/json",
        },
        body=payload,
    )
    assert status == 411
    assert body.get("error") == 1
    assert body.get("code") == "INVALID_BODY"
    assert "A valid Content-Length is required" in body.get("message", "")


@pytest.mark.parametrize("invalid_length", ["not-a-number", "abc", "12.34", ""])
def test_invalid_content_length_rejected_with_411(
    boundary_server: BoundaryServerContext, invalid_length: str
) -> None:
    """Authenticated request with non-integer Content-Length header is rejected with 411."""
    payload = b'{"collection_id": "test"}'
    status, body, _ = boundary_server.raw_request(
        "POST",
        "/api/v1/session-tokens",
        headers={
            "Authorization": f"Bearer {boundary_server.token}",
            "Content-Type": "application/json",
            "Content-Length": invalid_length,
        },
        body=payload,
    )
    assert status == 411
    assert body.get("error") == 1
    assert body.get("code") == "INVALID_BODY"
    assert "A valid Content-Length is required" in body.get("message", "")


def test_zero_content_length_rejected_with_413(boundary_server: BoundaryServerContext) -> None:
    """Authenticated request with Content-Length 0 is rejected with 413 (below 1 byte)."""
    status, body, _ = boundary_server.raw_request(
        "POST",
        "/api/v1/session-tokens",
        headers={
            "Authorization": f"Bearer {boundary_server.token}",
            "Content-Type": "application/json",
            "Content-Length": "0",
        },
    )
    assert status == 413
    assert body.get("error") == 1
    assert body.get("code") == "BODY_LIMIT"
    assert "between 1 byte and 128 KiB" in body.get("message", "")


def test_negative_content_length_rejected_with_413(boundary_server: BoundaryServerContext) -> None:
    """Authenticated request with negative Content-Length is rejected with 413."""
    status, body, _ = boundary_server.raw_request(
        "POST",
        "/api/v1/session-tokens",
        headers={
            "Authorization": f"Bearer {boundary_server.token}",
            "Content-Type": "application/json",
            "Content-Length": "-1",
        },
    )
    assert status == 413
    assert body.get("error") == 1
    assert body.get("code") == "BODY_LIMIT"


def test_out_of_range_content_length_rejected_with_413(
    boundary_server: BoundaryServerContext,
) -> None:
    """Authenticated request with Content-Length exceeding 128 KiB is rejected with 413."""
    status, body, _ = boundary_server.raw_request(
        "POST",
        "/api/v1/session-tokens",
        headers={
            "Authorization": f"Bearer {boundary_server.token}",
            "Content-Type": "application/json",
            "Content-Length": "131073",
        },
    )
    assert status == 413
    assert body.get("error") == 1
    assert body.get("code") == "BODY_LIMIT"
    assert "between 1 byte and 128 KiB" in body.get("message", "")


def test_unauthenticated_request_rejected_before_parsing(
    boundary_server: BoundaryServerContext,
) -> None:
    """Unauthenticated request is rejected at auth gate (401) before body parsing occurs."""
    status, body, _ = boundary_server.raw_request(
        "POST",
        "/api/v1/session-tokens",
        headers={
            "Content-Type": "application/json",
            "Transfer-Encoding": "chunked",
        },
        body=b"0\r\n\r\n",
    )
    assert status == 401
    assert body.get("code") == "AUTH_REQUIRED"


# ==============================================================================
# Symlinked Static File Tests (P2 issue 9)
# ==============================================================================


def test_regular_static_file_served_200(static_server: StaticAssetsContext) -> None:
    """Regular, non-symlinked static file is served successfully with 200."""
    status, _, raw = static_server.get("/real_file.css")
    assert status == 200
    assert raw == b"body { margin: 0; }"


def test_symlinked_static_file_present_at_startup_rejected_with_404(
    static_server: StaticAssetsContext,
) -> None:
    """Static file that was a symlink at startup is rejected with 404 (P2 issue 9)."""
    status, body, _ = static_server.get("/startup_symlink.css")
    assert status == 404
    assert body.get("error") == 1
    assert body.get("code") == "NOT_FOUND"
    assert "File not found" in body.get("message", "")


def test_symlinked_static_file_replaced_after_registration_rejected_with_404(
    static_server: StaticAssetsContext,
) -> None:
    """Static file replaced with a symlink after registration is rejected with 404."""
    # Verify it serves 200 prior to replacement
    status, _, raw = static_server.get("/replaced_later.css")
    assert status == 200
    assert raw == b"body { color: red; }"

    # Replace regular file with symlink
    target = static_server.root / "target.css"
    replaced_path = static_server.root / "replaced_later.css"
    replaced_path.unlink()
    os.symlink(target, replaced_path)

    # After replacement, live server checks file.is_symlink() and returns 404
    status, body, _ = static_server.get("/replaced_later.css")
    assert status == 404
    assert body.get("error") == 1
    assert body.get("code") == "NOT_FOUND"
    assert "File not found" in body.get("message", "")


def test_symlinked_index_html_at_startup_rejected_with_404() -> None:
    """When index.html is a symlink, root route '/' is rejected with 404."""
    tmp = tempfile.TemporaryDirectory()
    tmp_path = Path(tmp.name)
    config = load_config(tmp_path / "config.json")
    repo = SQLiteRepository(config["database"], config["encryption_key"])
    service = FaceService(repo, config, contract())

    web_root = tmp_path / "web"
    web_root.mkdir()
    (web_root / "browser-face").mkdir()

    target_html = tmp_path / "target.html"
    target_html.write_text("<!doctype html><title>External</title>")
    os.symlink(target_html, web_root / "index.html")

    try:
        server = FaceHTTPServer(("127.0.0.1", 0), service, web_root)
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

    conn = http.client.HTTPConnection("127.0.0.1", server.server_port, timeout=5)
    try:
        conn.request("GET", "/")
        response = conn.getresponse()
        raw = response.read()
        assert response.status == 404
        data = json.loads(raw)
        assert data.get("code") == "NOT_FOUND"
    finally:
        conn.close()
        server.shutdown()
        server.server_close()
        thread.join(timeout=3)
        tmp.cleanup()
