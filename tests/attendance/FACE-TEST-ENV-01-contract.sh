#!/usr/bin/env bash
set -euo pipefail

root_dir="$(cd "$(dirname "${BASH_SOURCE[0]}")/../.." && pwd)"
compose_file="$root_dir/tests/face-test-env/docker-compose.yml"
runner="$root_dir/tests/face-test-env/run.sh"
example="$root_dir/tests/face-test-env/.env.example"

test -f "$compose_file"
test -f "$runner"
test -x "$runner"
test -f "$example"

rg -q 'ccc_database_test' "$root_dir/tests/face-test-env/mysql-init/01-create-databases.sql"
rg -q 'ccc_central_test' "$root_dir/tests/face-test-env/mysql-init/01-create-databases.sql"
rg -q 'MYSQL_ROOT_PASSWORD' "$compose_file"
rg -q 'mysqladmin ping' "$compose_file"
rg -q 'FACE_ATTENDANCE_SHADOW_MODE=1' "$example"
rg -q 'FACE_ATTENDANCE_LIVE_WRITE=0' "$example"
rg -q 'FACE_PUNCH_OUT_ENABLED=0' "$example"
rg -q 'ATTENDANCE_TEST_DB=ccc_database_test' "$example"
rg -q 'ATTENDANCE_TEST_CENTRAL_DB=ccc_central_test' "$example"
rg -q -- '--required' "$runner"
rg -q 'docker compose' "$runner"
rg -q 'FACE-API-01-scan-lifecycle.php' "$runner"

echo 'PASS FACE-TEST-ENV-01 disposable test environment contract'
