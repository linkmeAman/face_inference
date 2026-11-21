import io
import json
import threading
import urllib.parse
import urllib.request
from email.message import Message
from http.server import BaseHTTPRequestHandler, ThreadingHTTPServer
from urllib.response import addinfourl

import pytest

from face_inference.client import FaceApiError, FaceClient, FaceClientError, FaceConnectionError

TOKEN = "redirect-test-secret"


@pytest.fixture
def endpoints():
    requests = []
    routes = {}

    class Handler(BaseHTTPRequestHandler):
        def log_message(self, *_):
            pass

        def do_GET(self):
            body = self.rfile.read(int(self.headers.get("Content-Length", 0)))
            requests.append((self.command, self.path, self.headers.get("Authorization"), body))
            status, location = routes.get((self.server.server_port, self.path), (200, None))
            self.send_response(status)
            if location is not None:
                self.send_header("Location", location)
            payload = b'{"data": {"ok": true}}'
            self.send_header("Content-Type", "application/json")
            self.send_header("Content-Length", str(len(payload)))
            self.end_headers()
            self.wfile.write(payload)

        do_POST = do_GET

    servers = []
    threads = []
    try:
        for _ in range(2):
            server = ThreadingHTTPServer(("127.0.0.1", 0), Handler)
            servers.append(server)
            thread = threading.Thread(target=server.serve_forever, daemon=True)
            thread.start()
            threads.append(thread)
        urls = [f"http://127.0.0.1:{server.server_port}" for server in servers]
        yield urls, routes, requests
    finally:
        for server in servers:
            server.shutdown()
            server.server_close()
        for thread in threads:
            thread.join(timeout=3)


@pytest.mark.parametrize("status", [301, 302, 303, 307, 308])
@pytest.mark.parametrize("cross_origin", [False, True])
def test_local_get_redirect(endpoints, status, cross_origin):
    urls, routes, requests = endpoints
    port = urllib.parse.urlsplit(urls[0]).port
    target = urls[int(cross_origin)]
    routes[port, "/start"] = (status, f"{target}/end")
    client = FaceClient(urls[0], TOKEN, timeout=2, retry_backoff=0)

    assert client.get("/start") == {"ok": True}
    assert requests == [
        ("GET", "/start", f"Bearer {TOKEN}", b""),
        ("GET", "/end", None if cross_origin else f"Bearer {TOKEN}", b""),
    ]


def test_local_cross_origin_chain_does_not_restore_token(endpoints):
    urls, routes, requests = endpoints
    first, second = [urllib.parse.urlsplit(url).port for url in urls]
    routes[first, "/start"] = (302, "/same")
    routes[first, "/same"] = (302, f"{urls[1]}/away")
    routes[second, "/away"] = (302, "/still-away")
    routes[second, "/still-away"] = (302, f"{urls[0]}/back")
    client = FaceClient(urls[0], TOKEN, timeout=2, retry_backoff=0)

    assert client.get("/start") == {"ok": True}
    assert [request[2] for request in requests] == [
        f"Bearer {TOKEN}",
        f"Bearer {TOKEN}",
        None,
        None,
        None,
    ]


@pytest.mark.parametrize("status", [301, 302, 303, 307, 308])
@pytest.mark.parametrize("cross_origin", [False, True])
def test_local_post_preserves_urllib_redirect_rules(endpoints, status, cross_origin):
    urls, routes, requests = endpoints
    port = urllib.parse.urlsplit(urls[0]).port
    routes[port, "/start"] = (status, f"{urls[int(cross_origin)]}/end")
    client = FaceClient(urls[0], TOKEN, timeout=2, retry_backoff=0)

    if status in (307, 308):
        with pytest.raises(FaceApiError) as error:
            client.send("/start", {"value": 1})
        assert error.value.status == status
        assert len(requests) == 1
    else:
        assert client.send("/start", {"value": 1}) == {"ok": True}
        assert requests[1] == ("GET", "/end", None if cross_origin else f"Bearer {TOKEN}", b"")
        assert len(requests) == 2
    assert requests[0][:3] == ("POST", "/start", f"Bearer {TOKEN}")
    assert json.loads(requests[0][3])["value"] == 1
    assert json.loads(requests[0][3])["idempotency_key"]
    assert client.pending is None


@pytest.fixture
def wire(monkeypatch):
    """Keep urllib's opener/redirect/error machinery; replace only network I/O."""
    responses = []
    requests = []

    def respond(handler, request):
        requests.append(request)
        status, location = responses.pop(0) if responses else (200, None)
        headers = Message()
        if location is not None:
            headers["Location"] = location
        response = addinfourl(
            io.BytesIO(b'{"data": {"ok": true}}'), headers, request.full_url, status
        )
        response.msg = "controlled response"
        return response

    monkeypatch.setattr(urllib.request.HTTPHandler, "http_open", respond)
    monkeypatch.setattr(urllib.request.HTTPSHandler, "https_open", respond)
    return responses, requests


@pytest.mark.parametrize(
    ("source", "target", "authorized"),
    [
        ("http://EXAMPLE.test", "http://example.test:80/end", True),
        ("https://EXAMPLE.test", "https://example.test:443/end", True),
        ("http://example.test:80", "http://example.test/end", True),
        ("https://example.test:443", "https://example.test/end", True),
        ("http://example.test", "http://other.test/end", False),
        ("http://example.test", "http://example.test:81/end", False),
        ("http://example.test", "http://example.test:0/end", False),
        ("http://example.test", "https://example.test/end", False),
    ],
)
def test_normalized_origin(wire, source, target, authorized):
    responses, requests = wire
    responses.append((302, target))
    client = FaceClient(source, TOKEN, timeout=1.25, retry_backoff=0)

    assert client.get("/start") == {"ok": True}
    assert len(requests) == 2
    assert requests[0].get_header("Authorization") == f"Bearer {TOKEN}"
    assert requests[1].get_header("Authorization") == (f"Bearer {TOKEN}" if authorized else None)
    assert all(request.timeout == 1.25 for request in requests)


def test_cross_origin_chain_does_not_restore_token(wire):
    responses, requests = wire
    responses.extend(
        [
            (302, "/same"),
            (302, "http://other.test/away"),
            (302, "/still-away"),
            (302, "http://example.test/back"),
        ]
    )
    client = FaceClient("http://example.test", TOKEN, retry_backoff=0)

    assert client.get("/start") == {"ok": True}
    assert [request.get_header("Authorization") for request in requests] == [
        f"Bearer {TOKEN}",
        f"Bearer {TOKEN}",
        None,
        None,
        None,
    ]


@pytest.mark.parametrize("status", [301, 302, 303, 307, 308])
@pytest.mark.parametrize("method", ["GET", "POST"])
def test_redirect_method_and_body_rules(wire, status, method):
    responses, requests = wire
    responses.append((status, "http://other.test/end"))
    client = FaceClient("http://example.test", TOKEN, retry_backoff=0)

    if method == "POST" and status in (307, 308):
        with pytest.raises(FaceApiError) as error:
            client.send("/start", {"value": 1})
        assert error.value.status == status
        assert len(requests) == 1
    else:
        result = client.send("/start", {"value": 1}) if method == "POST" else client.get("/start")
        assert result == {"ok": True}
        assert len(requests) == 2
        assert requests[1].get_method() == "GET"
        assert requests[1].data is None
        assert requests[1].get_header("Content-type") is None
        assert requests[1].get_header("Content-length") is None
        assert requests[1].get_header("Authorization") is None
    assert client.pending is None


@pytest.mark.parametrize("later_hop", [False, True])
@pytest.mark.parametrize("mutation", [False, True])
def test_https_downgrade_is_blocked_before_followup(wire, later_hop, mutation):
    responses, requests = wire
    for _ in range(3):
        if later_hop:
            responses.append((302, "https://other.test/next"))
        responses.append((302, f"http://example.test/end?secret={TOKEN}"))
    client = FaceClient("https://example.test", TOKEN, retry_backoff=0)

    with pytest.raises(FaceConnectionError) as error:
        client.send("/start", {"value": 1}) if mutation else client.get("/start")

    assert "Redirect from HTTPS to HTTP is not permitted" in str(error.value)
    assert str(error.value).endswith("Retry save to recover the same request.")
    assert TOKEN not in str(error.value)
    assert TOKEN not in repr(error.value)
    assert TOKEN not in str(error.value.original_error)
    assert len(requests) == (6 if later_hop else 3)
    assert all(request.full_url.startswith("https://") for request in requests)
    assert all(
        request.get_header("Authorization") is None
        for request in requests
        if request.host == "other.test"
    )
    assert (client.pending is not None) == mutation
    if mutation:
        key = client.pending["body"]["idempotency_key"]
        assert {
            json.loads(request.data)["idempotency_key"] for request in requests if request.data
        } == {key}
        with pytest.raises(FaceClientError):
            client.send("/another")
        assert client.retry() == {"ok": True}
        assert json.loads(requests[-1].data)["idempotency_key"] == key
        assert client.pending is None


@pytest.mark.parametrize("exhausted", [False, True])
def test_redirect_retry_preserves_mutation_and_idempotency(wire, exhausted):
    responses, requests = wire
    for _ in range(3 if exhausted else 1):
        responses.extend([(302, "http://other.test/end"), (503, None)])
    client = FaceClient("http://example.test", TOKEN, retry_backoff=0)

    if exhausted:
        with pytest.raises(FaceApiError) as error:
            client.send("/start", {"value": 1})
        assert error.value.status == 503
        key = client.pending["body"]["idempotency_key"]
        responses.append((302, "http://other.test/end"))
        assert client.retry() == {"ok": True}
    else:
        responses.append((302, "http://other.test/end"))
        assert client.send("/start", {"value": 1}) == {"ok": True}
        key = json.loads(requests[0].data)["idempotency_key"]

    assert len(requests) == (8 if exhausted else 4)
    for request in requests:
        if request.full_url.endswith("/start"):
            assert request.get_method() == "POST"
            assert request.get_header("Authorization") == f"Bearer {TOKEN}"
            assert json.loads(request.data) == {"value": 1, "idempotency_key": key}
        else:
            assert request.get_method() == "GET"
            assert request.data is None
            assert request.get_header("Authorization") is None
    assert client.pending is None


def test_ordinary_requests_keep_auth_and_global_opener(wire):
    _, requests = wire
    opener = urllib.request._opener
    client = FaceClient("http://example.test", TOKEN, timeout=1.25, retry_backoff=0)

    assert client.get("/plain") == {"ok": True}
    assert client.send("/plain", {"value": 1}) == {"ok": True}
    assert [request.get_method() for request in requests] == ["GET", "POST"]
    assert all(request.get_header("Authorization") == f"Bearer {TOKEN}" for request in requests)
    assert all(request.timeout == 1.25 for request in requests)
    assert json.loads(requests[1].data)["value"] == 1
    assert client.pending is None
    assert urllib.request._opener is opener
