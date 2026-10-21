#!/usr/bin/env bash
set -euo pipefail

root_dir="$(cd "$(dirname "${BASH_SOURCE[0]}")/../.." && pwd)"
env_file="${FACE_TEST_ENV_FILE:-$root_dir/tests/face-test-env/.env}"
compose_file="$root_dir/tests/face-test-env/docker-compose.yml"

if [[ ! -f "$env_file" ]]; then
  echo "Missing $env_file. Copy .env.example to .env and review it." >&2
  exit 2
fi

set -a
. "$env_file"
set +a

compose=(docker compose --env-file "$env_file" -f "$compose_file")

case "${1:-help}" in
  up)
    "${compose[@]}" up -d mysql-face-test
    "${compose[@]}" exec -T mysql-face-test mysqladmin ping -h 127.0.0.1 -uroot -p"$FACE_TEST_MYSQL_ROOT_PASSWORD" --wait
    ;;
  down)
    "${compose[@]}" down
    ;;
  destroy)
    test "${FACE_TEST_ENV_ALLOW_DESTROY:-0}" = 1
    "${compose[@]}" down --volumes
    ;;
  test)
    "${compose[@]}" exec -T mysql-face-test mysqladmin ping -h 127.0.0.1 -uroot -p"$FACE_TEST_MYSQL_ROOT_PASSWORD" --wait
    "${compose[@]}" exec -T mysql-face-test mysql -uroot -p"$FACE_TEST_MYSQL_ROOT_PASSWORD" < "$root_dir/tests/face-test-env/mysql-init/02-minimal-app-schema.sql"
    export PYTHONPATH="$root_dir/services/face-inference"
    /var/lib/creativeculturalcentre/face-inference/venv/bin/python -m unittest discover -s "$root_dir/services/face-inference/tests"
    php "$root_dir/tests/attendance/ATT-DB-01-migration.php" --required
    php "$root_dir/tests/attendance/ATT-FIX-02-04-regressions.php" --required
    php "$root_dir/tests/attendance/ATT-CORE-01-domain-service.php" --required
    php "$root_dir/tests/attendance/FACE-API-09-supervised-provider-contract.php"
    php "$root_dir/tests/attendance/FACE-API-10-openapi-contract.php"
    php "$root_dir/tests/attendance/FACE-TEST-DB-02-setup-contract.php"
    php "$root_dir/tests/attendance/FACE-API-01-scan-lifecycle.php" --required
    php "$root_dir/tests/attendance/FACE-ENR-05-provider-binding.php"
    php "$root_dir/tests/attendance/FACE-ENR-01-enrollment.php" --required
    php "$root_dir/tests/attendance/FACE-ENR-02-cross-member-duplicate.php" --required
    ;;
  help|*)
    echo "Usage: $0 {up|test|down|destroy}"
    echo "Set FACE_TEST_ENV_ALLOW_DESTROY=1 only for explicit disposable-volume removal."
    exit 0
    ;;
esac
