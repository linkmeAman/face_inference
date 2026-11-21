"""Optional presentation attack detection provider contract."""

from enum import Enum
from typing import Protocol


class PADProvider(Protocol):
    def validate(self, evidence: bytes) -> bool:
        """Return True only when the evidence passes the provider's check."""
        ...


class PADResult(Enum):
    UNVALIDATED = 'unvalidated'
    ACCEPTED = 'accepted'
    REJECTED = 'rejected'


def validate_pad(
    provider: PADProvider | None, evidence: bytes, *, required: bool = False
) -> PADResult:
    if provider is None:
        return PADResult.REJECTED if required else PADResult.UNVALIDATED
    try:
        if not isinstance(evidence, (bytes, bytearray, memoryview)) or not evidence:
            return PADResult.REJECTED
        result = provider.validate(evidence)
    except Exception:
        return PADResult.REJECTED
    return PADResult.ACCEPTED if result is True else PADResult.REJECTED
