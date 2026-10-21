"""Structured face logs with biometric and secret redaction."""

from __future__ import annotations

import json
import sys
import time
from typing import Any, Dict, Mapping, Optional, TextIO


class SafeFaceLogger:
    _blocked = {"frame", "frames", "image", "images", "embedding", "embeddings", "template", "templates", "secret", "shared_secret", "token", "password"}

    def __init__(self, stream: Optional[TextIO] = None) -> None:
        self.stream = stream or sys.stderr

    def event(self, name: str, context: Optional[Mapping[str, Any]] = None) -> Dict[str, Any]:
        safe: Dict[str, Any] = {"event": str(name), "timestamp": int(time.time())}
        for key, value in (context or {}).items():
            if str(key).lower() in self._blocked:
                continue
            if isinstance(value, (str, int, float, bool)) or value is None:
                safe[str(key)] = value
            elif isinstance(value, Mapping):
                safe[str(key)] = self.event("context", value)
            else:
                safe[str(key)] = "[REDACTED]"
        return safe

    def log(self, name: str, context: Optional[Mapping[str, Any]] = None) -> None:
        self.stream.write(json.dumps(self.event(name, context), separators=(",", ":")) + "\n")
        self.stream.flush()
