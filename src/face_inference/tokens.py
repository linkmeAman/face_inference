"""Issue and verify scoped, short-lived HMAC session tokens."""

import base64
import hmac
import json
import math
import re
import time
from dataclasses import dataclass


class TokenError(ValueError):
    def __init__(self, reason: str):
        super().__init__(reason)
        self.reason = reason


@dataclass(frozen=True)
class Claims:
    collection: str
    scope: str
    subject: str | None
    iat: int
    exp: int


def _secret(secret: bytes) -> None:
    if not isinstance(secret, bytes) or len(secret) < 32:
        raise ValueError("secret must contain at least 32 bytes")


def _encode(value: bytes) -> str:
    return base64.urlsafe_b64encode(value).rstrip(b"=").decode("ascii")


def _decode(value: str) -> bytes:
    if not re.fullmatch(r"[A-Za-z0-9_-]+", value):
        raise ValueError("invalid base64url")
    decoded = base64.b64decode(value + "=" * (-len(value) % 4), altchars=b"-_", validate=True)
    if _encode(decoded) != value:
        raise ValueError("noncanonical base64url")
    return decoded


def _object(pairs: list) -> dict:
    result = dict(pairs)
    if len(result) != len(pairs):
        raise ValueError("duplicate claim")
    return result


def issue(secret: bytes, collection: str, scope: str, ttl: int = 300,
          subject: str | None = None, now: float | None = None) -> str:
    _secret(secret)
    if type(ttl) is not int or not 1 <= ttl <= 3600:
        raise ValueError("ttl must be an integer from 1 to 3600")
    if (not isinstance(collection, str) or scope not in ("enroll", "recognize")
            or (subject is not None and not isinstance(subject, str))):
        raise ValueError("invalid collection, scope or subject")
    current = time.time() if now is None else now
    if not math.isfinite(current):
        raise ValueError("now must be finite")
    issued = int(current)
    payload = {"c": collection, "s": scope, "iat": issued, "exp": issued + ttl}
    if subject is not None:
        payload["sub"] = subject
    message = "v1." + _encode(json.dumps(payload, separators=(",", ":")).encode("utf-8"))
    token = message + "." + _encode(hmac.digest(secret, message.encode("ascii"), "sha256"))
    if len(token) > 1024:
        raise ValueError("token exceeds 1024 characters")
    return token


def verify(secret: bytes, token: str, *, collection: str, scope: str,
           now: float | None = None) -> Claims:
    _secret(secret)
    if not isinstance(token, str):
        raise TokenError("malformed")
    if len(token) > 1024:
        raise TokenError("too_large")
    try:
        version, body, signature = token.split(".")
        if version != "v1":
            raise ValueError("unknown version")
        decoded = _decode(body)
        supplied = _decode(signature)
        message = (version + "." + body).encode("ascii")
        # Authenticate the encoded payload before parsing untrusted claims.
        expected = hmac.digest(secret, message, "sha256")
        if not hmac.compare_digest(expected, supplied):
            raise TokenError("signature")
        payload = json.loads(decoded.decode("utf-8"), object_pairs_hook=_object)
    except TokenError:
        raise
    except ValueError as error:
        raise TokenError("malformed") from error

    if not isinstance(payload, dict) or set(payload) not in (
        {"c", "s", "iat", "exp"}, {"c", "s", "sub", "iat", "exp"},
    ):
        raise TokenError("malformed")
    if (not isinstance(payload["c"], str) or payload["s"] not in ("enroll", "recognize")
            or ("sub" in payload and not isinstance(payload["sub"], str))
            or type(payload["iat"]) is not int or type(payload["exp"]) is not int
            or payload["exp"] <= payload["iat"]):
        raise TokenError("malformed")
    current = time.time() if now is None else now
    if not math.isfinite(current) or payload["iat"] > current + 30:
        raise TokenError("malformed")
    if payload["exp"] <= current:
        raise TokenError("expired")
    if payload["c"] != collection:
        raise TokenError("collection")
    if payload["s"] != scope:
        raise TokenError("scope")
    return Claims(payload["c"], payload["s"], payload.get("sub"), payload["iat"], payload["exp"])
