import ipaddress
import math
import threading
import time
from collections import OrderedDict
from collections.abc import Callable, Iterable
from typing import Any, NamedTuple


class Decision(NamedTuple):
    allowed: bool
    retry_after: int


class RateLimiter:
    """Thread-safe token bucket rate limiter with LRU key eviction.

    Algorithm: Token Bucket.
    Each key begins with a full bucket of `limit` tokens. Tokens refill continuously
    at `limit / window` tokens per second, capped at `limit`. Every `check(key)` call
    attempts to consume 1 token. If tokens >= 1.0, the request is allowed with
    `retry_after = 0`. If tokens < 1.0, the request is denied with `retry_after` set
    to the integer ceiling of seconds required to accumulate at least 1 token.

    Memory is bounded to `max_keys` entries using an LRU eviction policy. When a new
    key arrives and the table is full, the least recently used key is evicted,
    ensuring a new client is never rejected simply because the table is full.
    """

    def __init__(
        self,
        limit: int,
        window: float = 60.0,
        max_keys: int = 10000,
        clock: Callable[[], float] = time.monotonic,
    ) -> None:
        if limit < 0:
            raise ValueError('limit must be non-negative')
        if window <= 0:
            raise ValueError('window must be positive')
        if max_keys < 1:
            raise ValueError('max_keys must be >= 1')
        self.limit = int(limit)
        self.window = float(window)
        self.max_keys = int(max_keys)
        self.clock = clock
        self._lock = threading.Lock()
        self._table: OrderedDict[str, tuple[float, float]] = OrderedDict()

    def check(self, key: str) -> Decision:
        with self._lock:
            now = float(self.clock())

            if key in self._table:
                tokens, last_refill = self._table[key]
                self._table.move_to_end(key)
            else:
                if len(self._table) >= self.max_keys:
                    self._table.popitem(last=False)
                tokens = float(self.limit)
                last_refill = now

            elapsed = max(0.0, now - last_refill)
            refill_rate = self.limit / self.window
            tokens = min(float(self.limit), tokens + elapsed * refill_rate)
            last_refill = now

            if self.limit <= 0:
                retry_after = max(1, int(math.ceil(self.window)))
                self._table[key] = (0.0, last_refill)
                return Decision(allowed=False, retry_after=retry_after)

            if tokens >= 1.0:
                tokens -= 1.0
                self._table[key] = (tokens, last_refill)
                return Decision(allowed=True, retry_after=0)

            needed = 1.0 - tokens
            wait_time = needed / refill_rate
            retry_after = max(1, int(math.ceil(wait_time)))
            self._table[key] = (tokens, last_refill)
            return Decision(allowed=False, retry_after=retry_after)


def _parse_ip(raw: Any) -> ipaddress.IPv4Address | ipaddress.IPv6Address | None:
    if not isinstance(raw, str):
        return None
    try:
        return ipaddress.ip_address(raw.strip())
    except (ValueError, AttributeError):
        return None


def _is_in_networks(
    ip: ipaddress.IPv4Address | ipaddress.IPv6Address,
    networks: list[ipaddress.IPv4Network | ipaddress.IPv6Network],
) -> bool:
    for net in networks:
        if ip in net:
            return True
    return False


def client_ip(
    peer: str,
    forwarded_for: str | None,
    trusted_proxies: Iterable[str] = (),
) -> str:
    """Resolve client IP from peer and X-Forwarded-For header.

    If peer is not in trusted_proxies, returns peer (ignoring headers entirely).
    Otherwise walks X-Forwarded-For from right to left, skipping trusted proxies,
    and returns the first untrusted IP address.
    Invalid tokens anywhere in the header result in falling back to peer.
    IPv6 addresses are normalized. Never raises exceptions.
    """
    try:
        peer_str = str(peer).strip() if peer is not None else ''
        peer_ip = _parse_ip(peer_str)
        peer_norm = str(peer_ip) if peer_ip is not None else peer_str

        if peer_ip is None:
            return peer_norm

        networks: list[ipaddress.IPv4Network | ipaddress.IPv6Network] = []
        if trusted_proxies:
            for item in trusted_proxies:
                if not isinstance(item, str):
                    continue
                try:
                    networks.append(ipaddress.ip_network(item.strip(), strict=False))
                except (ValueError, AttributeError):
                    pass

        if not _is_in_networks(peer_ip, networks):
            return peer_norm

        if not isinstance(forwarded_for, str):
            return peer_norm

        header_val = forwarded_for.strip()
        if not header_val:
            return peer_norm

        raw_tokens = [t.strip() for t in header_val.split(',')]
        if not raw_tokens:
            return peer_norm

        parsed_ips: list[ipaddress.IPv4Address | ipaddress.IPv6Address] = []
        for t in raw_tokens:
            if not t:
                return peer_norm
            ip = _parse_ip(t)
            if ip is None:
                return peer_norm
            parsed_ips.append(ip)

        for ip in reversed(parsed_ips):
            if not _is_in_networks(ip, networks):
                return str(ip)

        return peer_norm

    except Exception:
        try:
            return str(peer) if peer is not None else ''
        except Exception:
            return ''
