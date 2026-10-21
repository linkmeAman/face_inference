#!/usr/bin/env bash
#
# health_monitor.sh - Operational watchdog for private face inference runtime.
#
# Checks:
# 1. Systemd unit status (active/running)
# 2. Loopback /readyz probe
# 3. Memory RSS against warning threshold (400 MiB = ~80% of MemoryHigh)
#

set -euo pipefail

URL="${FACE_INFERENCE_URL:-http://127.0.0.1:6120}"
SERVICE_NAME="face-inference.service"
MEMORY_WARN_MB=400

timestamp() {
    date -u +"%Y-%m-%dT%H:%M:%SZ"
}

# 1. Check systemd service status
if ! systemctl is-active --quiet "${SERVICE_NAME}"; then
    echo "[$(timestamp)] ALERT: ${SERVICE_NAME} is not active" >&2
    exit 1
fi

# 2. Probe /readyz
if ! READYZ_RESP=$(curl -fsS --max-time 3 "${URL}/readyz"); then
    echo "[$(timestamp)] ALERT: /readyz probe failed" >&2
    exit 1
fi
if ! grep -Eq '"status"[[:space:]]*:[[:space:]]*"ready"' <<<"${READYZ_RESP}"; then
    echo "[$(timestamp)] ALERT: /readyz probe failed" >&2
    exit 1
fi

# 3. Check memory telemetry from /health
if ! HEALTH_RESP=$(curl -fsS --max-time 3 "${URL}/health"); then
    echo "[$(timestamp)] ALERT: /health probe failed" >&2
    exit 1
fi
RSS_MB=$(sed -nE 's/.*"rss_mb"[[:space:]]*:[[:space:]]*([0-9]+([.][0-9]+)?).*/\1/p' <<<"${HEALTH_RESP}")
if [[ -z "${RSS_MB}" ]]; then
    echo "[$(timestamp)] ALERT: /health memory RSS is missing or invalid" >&2
    exit 1
fi
if awk -v rss="${RSS_MB}" -v limit="${MEMORY_WARN_MB}" 'BEGIN { exit !(rss >= limit) }'; then
    echo "[$(timestamp)] ALERT: Inference RSS memory (${RSS_MB} MB) reached or exceeded ${MEMORY_WARN_MB} MB threshold" >&2
    exit 1
fi

echo "[$(timestamp)] OK: Inference service healthy. Memory: ${RSS_MB} MB"
exit 0
