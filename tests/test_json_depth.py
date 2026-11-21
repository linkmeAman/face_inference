from test_recognition_scope import http_fixture  # noqa: F401


def test_deep_json_returns_error_and_server_remains_healthy(http):
    raw = b'{"x":' + b'[' * 20000 + b'0' + b']' * 20000 + b'}'
    status, result = http.request('/api/v1/collections', raw=raw)
    assert (status, result['code'], result['message']) == (
        400, 'INVALID_JSON', 'A valid finite JSON object is required')
    assert http.request('/health', authorized=False)[0] == 200


def test_read_json_rejects_recursion():
    from email.message import Message
    from io import BytesIO

    import pytest

    from face_inference.matching import FaceError
    from face_inference.server import Handler

    raw = b'{"x":' + b'[' * 20000 + b'0' + b']' * 20000 + b'}'
    handler = Handler.__new__(Handler)
    handler.headers = Message()
    handler.headers['Content-Type'] = 'application/json'
    handler.headers['Content-Length'] = str(len(raw))
    handler.rfile = BytesIO(raw)
    with pytest.raises(FaceError) as error:
        handler.read_json()
    assert (error.value.status, error.value.code, str(error.value)) == (
        400, 'INVALID_JSON', 'A valid finite JSON object is required')
