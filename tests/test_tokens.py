import base64
import hashlib
import hmac
import json
from dataclasses import FrozenInstanceError
from unittest.mock import patch

import pytest

from face_inference.tokens import Claims, TokenError, issue, verify

SECRET = b"s" * 32
NOW = 1_800_000_000
PAYLOAD = {"c": "team", "s": "enroll", "iat": NOW, "exp": NOW + 300}


def b64(value):
    return base64.urlsafe_b64encode(value).rstrip(b"=").decode("ascii")


def signed(raw, secret=SECRET):
    message = "v1." + b64(raw)
    return message + "." + b64(hmac.digest(secret, message.encode("ascii"), "sha256"))


def token_for(payload):
    return signed(json.dumps(payload, separators=(",", ":")).encode())


def check(token, **kwargs):
    return verify(SECRET, token, **{"collection": "team", "scope": "enroll", "now": NOW,
                                    **kwargs})


def rejected(token, reason, **kwargs):
    with pytest.raises(TokenError) as error:
        check(token, **kwargs)
    assert error.value.reason == reason
    assert str(error.value) == reason


@pytest.mark.parametrize("scope", ["enroll", "recognize"])
@pytest.mark.parametrize("subject", [None, "person-1", "व्यक्ति"])
@pytest.mark.parametrize("ttl", [1, 300, 3600])
def test_round_trip_and_wire_format(scope, subject, ttl):
    token = issue(SECRET, "team", scope, ttl, subject, now=NOW + 0.75)
    version, body, signature = token.split(".")
    assert version == "v1"
    assert "=" not in token
    payload = json.loads(base64.urlsafe_b64decode(body + "=" * (-len(body) % 4)))
    assert payload == {**PAYLOAD, "s": scope, "exp": NOW + ttl,
                       **({"sub": subject} if subject is not None else {})}
    digest = hmac.new(SECRET, ("v1." + body).encode(), hashlib.sha256).digest()
    assert signature == b64(digest)
    claims = check(token, scope=scope)
    assert isinstance(claims, Claims)
    assert (claims.collection, claims.scope, claims.subject, claims.iat, claims.exp) == (
        "team", scope, subject, NOW, NOW + ttl)
    with pytest.raises(FrozenInstanceError):
        claims.scope = "recognize"


def test_default_clock_and_ttl():
    with patch("face_inference.tokens.time.time", return_value=NOW):
        claims = verify(SECRET, issue(SECRET, "team", "enroll"),
                        collection="team", scope="enroll")
    assert (claims.iat, claims.exp) == (NOW, NOW + 300)


@pytest.mark.parametrize("ttl", [0, -1, 3601, True, 1.5, "300", None])
def test_invalid_ttl(ttl):
    with pytest.raises(ValueError):
        issue(SECRET, "team", "enroll", ttl, now=NOW)


@pytest.mark.parametrize("secret", [b"", b"s" * 31, "s" * 32, None])
def test_invalid_secret(secret):
    with pytest.raises(ValueError):
        issue(secret, "team", "enroll", now=NOW)
    with pytest.raises(ValueError):
        verify(secret, token_for(PAYLOAD), collection="team", scope="enroll", now=NOW)


@pytest.mark.parametrize("changes", [
    {"scope": "admin"}, {"scope": []}, {"collection": None}, {"subject": 1},
    {"now": float("nan")}, {"now": float("inf")},
])
def test_invalid_issue_arguments(changes):
    with pytest.raises(ValueError):
        issue(SECRET, **{"collection": "team", "scope": "enroll", "now": NOW, **changes})


@pytest.mark.parametrize("token", [
    "", "v1", "v1.a", "v1.a.b.c", "v2.a.b", "v1..a", "v1.a.",
    "v1.a=.a", "v1.a.a=", "v1.+.a", "v1.a./", "v1.é.a", "v1.a.é",
    "v1.a.a", "v1.YR.YQ", "v1.YQ.YR", None, b"v1.a.b",
])
def test_malformed_envelope(token):
    rejected(token, "malformed")


def test_tampered_parts_truncation_and_wrong_secret():
    token = token_for(PAYLOAD)
    version, body, signature = token.split(".")
    rejected("v0." + body + "." + signature, "malformed")
    rejected(version + "." + b64(b"tampered") + "." + signature, "signature")
    replacement = b64(b"x" * 32)
    rejected(version + "." + body + "." + replacement, "signature")
    rejected(token[:-3], "signature")
    rejected(token_for(PAYLOAD).rsplit(".", 1)[0] + ".YQ", "signature")
    rejected(signed(json.dumps(PAYLOAD).encode(), b"t" * 32), "signature")


def test_signature_checked_before_json():
    invalid_json = signed(b"not JSON")
    with patch("face_inference.tokens.json.loads", side_effect=AssertionError("parsed")):
        rejected(invalid_json.rsplit(".", 1)[0] + "." + b64(b"x" * 32), "signature")
    rejected(invalid_json, "malformed")


def test_constant_time_comparison():
    with patch("face_inference.tokens.hmac.compare_digest", wraps=hmac.compare_digest) as compare:
        check(token_for(PAYLOAD))
    assert compare.call_count == 1
    assert all(isinstance(value, bytes) for value in compare.call_args.args)


@pytest.mark.parametrize("raw", [
    b"{", b"\xff", b"null", b"[]", b"true", b"1", b'"text"',
    b'{"c":"team","c":"other","s":"enroll","iat":1800000000,"exp":1800000300}',
])
def test_invalid_signed_json(raw):
    rejected(signed(raw), "malformed")


@pytest.mark.parametrize("key", list(PAYLOAD))
def test_missing_keys(key):
    rejected(token_for({k: v for k, v in PAYLOAD.items() if k != key}), "malformed")


@pytest.mark.parametrize("changes", [
    {"extra": True}, {"alg": "none"}, {"c": None}, {"c": 1},
    {"s": None}, {"s": []}, {"s": "admin"}, {"sub": None}, {"sub": 1},
    {"iat": True}, {"iat": float(NOW)}, {"iat": "1800000000"},
    {"exp": False}, {"exp": float(NOW + 300)}, {"exp": float("nan")},
    {"exp": float("inf")}, {"exp": NOW}, {"exp": NOW - 1},
])
def test_invalid_claims(changes):
    rejected(token_for({**PAYLOAD, **changes}), "malformed")


def test_expiry_and_clock_skew_boundaries():
    token = token_for(PAYLOAD)
    assert check(token, now=NOW + 299.999).exp == NOW + 300
    rejected(token, "expired", now=NOW + 300)
    rejected(token, "expired", now=NOW + 301)
    assert check(token, now=NOW - 30).iat == NOW
    rejected(token, "malformed", now=NOW - 30.001)


@pytest.mark.parametrize("now", [float("nan"), float("inf"), -float("inf")])
def test_invalid_verification_clock(now):
    rejected(token_for(PAYLOAD), "malformed", now=now)


def test_collection_and_scope_must_match():
    token = token_for(PAYLOAD)
    rejected(token, "collection", collection="other")
    rejected(token, "scope", scope="recognize")
    rejected(token, "scope", scope="admin")


def test_token_size_cap():
    # Unpadded base64 cannot fill the last character of the 1024-character cap.
    payload = {**PAYLOAD, "sub": "x" * 664}
    token = token_for(payload)
    assert len(token) == 1023
    assert check(token).subject == payload["sub"]
    rejected(token + "x", "signature")
    rejected(token + "xx", "too_large")
    rejected("é" * 1025, "too_large")
    rejected("x" * 100_000, "too_large")
    with pytest.raises(ValueError):
        issue(SECRET, "team", "enroll", subject="x" * 1024, now=NOW)
