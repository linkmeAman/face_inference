"""Private, score-only experiment logging primitives.

This module deliberately contains no image, embedding, or PHP-facing API.
It is disabled unless a root credential and a writer callback are supplied.
"""
from __future__ import annotations

import hashlib
import hmac
import json
import os
import stat
import time
from dataclasses import dataclass
from typing import Any, Callable, Mapping, Optional, Sequence

MAX_ROSTER_MEMBERS = 300
MAX_TEMPLATES_PER_MEMBER = 5
FRAME_COUNT = 3
MAX_SERIALIZED_EVIDENCE_BYTES = 256 * 1024
WARN_BYTES = 8 * 1024 ** 3
STOP_BYTES = int(9.5 * 1024 ** 3)


class ExperimentLogRejected(ValueError):
    """A shadow record does not meet the score-only storage contract."""


def read_root_credential(path: str) -> bytes:
    info = os.stat(path)
    mode = stat.S_IMODE(info.st_mode)
    # systemd copies a root-owned 0600 source credential to a read-only file
    # owned by the service account beneath /run/credentials. Both forms keep
    # PHP/Nginx out of the secret path.
    source_credential = info.st_uid == 0 and mode == 0o600
    systemd_credential = path.startswith("/run/credentials/") and (mode & 0o222) == 0
    if not source_credential and not systemd_credential:
        raise ExperimentLogRejected("FACE_EXPERIMENT_ID_SECRET_FILE must be root-owned mode 0600")
    value = open(path, "rb").read().strip()
    if len(value) < 32:
        raise ExperimentLogRejected("FACE_EXPERIMENT_ID_SECRET_FILE is too short")
    return value


def opaque_id(secret: bytes, version: int, kind: str, value: str) -> str:
    if version < 1 or not kind or not value:
        raise ExperimentLogRejected("opaque identifier input is invalid")
    digest = hmac.new(secret, ("face-experiment|%d|%s|%s" % (version, kind, value)).encode(), hashlib.sha256).hexdigest()
    return "v%d_%s" % (version, digest)


def compact_frame_scores(secret: bytes, version: int, frames: Sequence[Mapping[str, Any]]) -> str:
    """Encode exactly three score-only frames without repeating identifiers."""
    if len(frames) != FRAME_COUNT:
        raise ExperimentLogRejected("FRAME_COUNT_LIMIT")
    template_refs = []
    candidate_refs = []
    values = []
    quality = []
    pad = []
    for frame in frames:
        scores = frame.get("template_scores")
        if not isinstance(scores, Mapping) or not scores:
            raise ExperimentLogRejected("SCORE_ONLY_VALIDATION")
        row = []
        for ref, score in scores.items():
            if not isinstance(ref, str) or not isinstance(score, (int, float)) or not float("-inf") < float(score) < float("inf"):
                raise ExperimentLogRejected("SCORE_ONLY_VALIDATION")
            if "#" not in ref:
                raise ExperimentLogRejected("SCORE_ONLY_VALIDATION")
            candidate, template = ref.rsplit("#", 1)
            candidate_token = opaque_id(secret, version, "candidate", candidate)
            template_token = opaque_id(secret, version, "template", ref)
            if candidate_token not in candidate_refs:
                candidate_refs.append(candidate_token)
            if template_token not in template_refs:
                template_refs.append(template_token)
            row.append(round(float(score), 7))
        values.append(row)
        quality.append(round(float(frame.get("quality", 0.0)), 5))
        pad.append(round(float(frame.get("pad", -1.0)), 5))
    if len(template_refs) > MAX_ROSTER_MEMBERS * MAX_TEMPLATES_PER_MEMBER:
        raise ExperimentLogRejected("ROSTER_SIZE_LIMIT")
    if len({len(row) for row in values}) != 1 or len(values[0]) != len(template_refs):
        raise ExperimentLogRejected("SCORE_ONLY_VALIDATION")
    payload = json.dumps({"v": 1, "d": {"t": template_refs, "c": candidate_refs}, "s": values, "q": quality, "p": pad}, separators=(",", ":"), allow_nan=False)
    if len(payload.encode("utf-8")) > MAX_SERIALIZED_EVIDENCE_BYTES:
        raise ExperimentLogRejected("EVIDENCE_SIZE_LIMIT")
    return payload


@dataclass(frozen=True)
class ExperimentLogger:
    secret: bytes
    opaque_id_version: int
    writer: Callable[[str], None]
    table_size: Callable[[], int] = lambda: 0

    @classmethod
    def from_credential(cls, path: str, version: int, writer: Callable[[str], None], table_size: Callable[[], int] = lambda: 0) -> "ExperimentLogger":
        return cls(read_root_credential(path), version, writer, table_size)

    def record(self, record: Mapping[str, Any]) -> str:
        roster_size = int(record.get("roster_size", 0))
        if roster_size > MAX_ROSTER_MEMBERS:
            rejected = dict(record)
            rejected["opaque_id_version"] = self.opaque_id_version
            rejected["decision"] = "ERROR"
            rejected["outcome_code"] = "ROSTER_SIZE_LIMIT"
            rejected["expires_at"] = rejected.get("expires_at") or int(time.time()) + 180 * 86400
            rejected.pop("ground_truth_member_id", None)
            self.writer(json.dumps(rejected, separators=(",", ":"), allow_nan=False))
            return "ROSTER_SIZE_LIMIT"
        if self.table_size() >= STOP_BYTES:
            return "TABLE_CAPACITY_LIMIT"
        clean = dict(record)
        clean["opaque_id_version"] = self.opaque_id_version
        clean["expires_at"] = clean.get("expires_at") or int(time.time()) + 180 * 86400
        # Ground truth is never accepted from the inference/shadow writer.
        clean.pop("ground_truth_member_id", None)
        encoded = json.dumps(clean, separators=(",", ":"), allow_nan=False)
        if len(encoded.encode("utf-8")) > MAX_SERIALIZED_EVIDENCE_BYTES + 8192:
            return "EVIDENCE_SIZE_LIMIT"
        self.writer(encoded)
        return "WARN_TABLE_SIZE" if self.table_size() >= WARN_BYTES else "LOGGED"


def mysql_procedure_writer_from_environment() -> Optional[Callable[[str], None]]:
    """Return the dedicated-writer callback, or None when logging is disabled.

    PyMySQL is intentionally imported only here so primary inference starts
    normally on installations that have not provisioned the optional writer.
    """
    dsn = os.getenv("FACE_EXPERIMENT_WRITER_DSN", "")
    if not dsn:
        return None
    try:
        import pymysql  # type: ignore
        from urllib.parse import urlparse
    except ImportError as exc:
        raise ExperimentLogRejected("PyMySQL is required for configured experiment logging") from exc
    parsed = urlparse(dsn)
    if parsed.scheme != "mysql" or not parsed.hostname or not parsed.path:
        raise ExperimentLogRejected("FACE_EXPERIMENT_WRITER_DSN is invalid")
    def write(payload: str) -> None:
        connection = pymysql.connect(host=parsed.hostname, port=parsed.port or 3306, user=parsed.username,
            password=parsed.password, database=parsed.path.lstrip("/"), charset="utf8mb4", autocommit=True,
            connect_timeout=1, read_timeout=1, write_timeout=1)
        try:
            with connection.cursor() as cursor:
                cursor.execute("CALL face_experiment_log_insert(CAST(%s AS JSON))", (payload,))
        finally:
            connection.close()
    return write
