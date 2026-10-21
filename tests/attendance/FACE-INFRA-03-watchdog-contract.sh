#!/usr/bin/env bash

set -euo pipefail

ROOT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")/../.." && pwd)"
MONITOR="${ROOT_DIR}/services/face-inference/tools/health_monitor.sh"
FIXTURE_DIR="$(mktemp -d)"
trap 'rm -rf "${FIXTURE_DIR}"' EXIT

cat >"${FIXTURE_DIR}/systemctl" <<'EOF'
#!/usr/bin/env bash
if [[ "${MOCK_CASE:-healthy}" == "service_inactive" ]]; then
    exit 3
fi
exit 0
EOF

cat >"${FIXTURE_DIR}/curl" <<'EOF'
#!/usr/bin/env bash
url="${*: -1}"
case "${MOCK_CASE:-healthy}:${url}" in
    unready:*\/readyz)
        printf '%s' '{"status":"not_ready"}'
        exit 22
        ;;
    health_unreachable:*\/health)
        exit 7
        ;;
    health_malformed:*\/health)
        printf '%s' '{"status":"ok"}'
        ;;
    memory_limit:*\/health)
        printf '%s' '{"status":"ok","memory":{"rss_mb":400.0}}'
        ;;
    memory_over:*\/health)
        printf '%s' '{"status":"ok","memory":{"rss_mb":401.2}}'
        ;;
    *:*\/readyz)
        printf '%s' '{"status":"ready"}'
        ;;
    *:*\/health)
        printf '%s' '{"status":"ok","memory":{"rss_mb":150.4}}'
        ;;
    *)
        exit 2
        ;;
esac
EOF

chmod +x "${FIXTURE_DIR}/systemctl" "${FIXTURE_DIR}/curl"

run_case() {
    local name="$1"
    local expected_exit="$2"
    local expected_text="$3"
    local output
    local actual_exit=0

    set +e
    output="$(PATH="${FIXTURE_DIR}:${PATH}" MOCK_CASE="${name}" FACE_INFERENCE_URL="http://127.0.0.1:6120" "${MONITOR}" 2>&1)"
    actual_exit=$?
    set -e

    if [[ "${actual_exit}" -ne "${expected_exit}" ]]; then
        printf 'FAIL %s: expected exit %s, got %s\n%s\n' "${name}" "${expected_exit}" "${actual_exit}" "${output}" >&2
        return 1
    fi
    if [[ "${output}" != *"${expected_text}"* ]]; then
        printf 'FAIL %s: expected output containing %q\n%s\n' "${name}" "${expected_text}" "${output}" >&2
        return 1
    fi
    printf 'PASS %s\n' "${name}"
}

run_case healthy 0 'OK: Inference service healthy. Memory: 150.4 MB'
run_case service_inactive 1 'face-inference.service is not active'
run_case unready 1 '/readyz probe failed'
run_case health_unreachable 1 '/health probe failed'
run_case health_malformed 1 'memory RSS is missing or invalid'
run_case memory_limit 1 'reached or exceeded 400 MB threshold'
run_case memory_over 1 'reached or exceeded 400 MB threshold'

printf 'PASS FACE-INFRA-03 watchdog contract (7 cases)\n'
