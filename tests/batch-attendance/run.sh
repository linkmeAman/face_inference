#!/bin/bash
# Batch / membership / attendance end-to-end suite.
#
# Everything runs against a throw-away copy of the code and throw-away
# databases; nothing in the live tree or in any live database is touched:
#   1. the working tree is copied to a temporary directory (without .git,
#      node_modules, media and model assets);
#   2. two disposable databases ccc_database_test_bflow_<hex> and
#      ccc_central_test_bflow_<hex> are created from a SCHEMA-ONLY dump of the
#      approved test databases (no rows are copied);
#   3. a temporary MySQL account that can only see those two databases is
#      created, and the copy's classes/dbconnection.class.php is replaced so
#      every connect() goes through that account to the disposable databases;
#   4. PHP's built-in server serves the copy inside a private network
#      namespace (unshare -n): e-mail, SMS, FCM, Zoom and Google calls cannot
#      leave the machine;
#   5. tests/batch-attendance/flow.php drives the real route files over HTTP,
#      exactly like the admin pages do;
#   6. the databases, the MySQL account and the copy are removed on exit
#      (pass --keep to keep them for debugging).
#
# Usage (as root on the server): tests/batch-attendance/run.sh [--keep] [filter]
set -euo pipefail

KEEP=0; FILTER=""
for arg in "$@"; do
  case "$arg" in
    --keep) KEEP=1 ;;
    *) FILTER="$arg" ;;
  esac
done

SRC="$(cd "$(dirname "${BASH_SOURCE[0]}")/../.." && pwd)"
TENANT_SCHEMA_DB="${BFLOW_TENANT_SCHEMA_DB:-ccc_database_test}"
CENTRAL_SCHEMA_DB="${BFLOW_CENTRAL_SCHEMA_DB:-ccc_central_test}"
case "$TENANT_SCHEMA_DB" in ccc_database_test*) ;; *) echo "Refusing: schema source must be a ccc_database_test* database" >&2; exit 2 ;; esac
case "$CENTRAL_SCHEMA_DB" in ccc_central_test*) ;; *) echo "Refusing: schema source must be a ccc_central_test* database" >&2; exit 2 ;; esac

HEX="$(head -c4 /dev/urandom | od -An -tx1 | tr -d ' \n')"
TENANT_DB="ccc_database_test_bflow_${HEX}"
CENTRAL_DB="ccc_central_test_bflow_${HEX}"
DB_USER="bflow_${HEX}"
DB_PASS="$(head -c18 /dev/urandom | base64 | tr -dc 'A-Za-z0-9' | head -c24)Aa9_"
WORK="$(mktemp -d /tmp/ccc-bflow-XXXXXX)"
PORT="${BFLOW_PORT:-28$((RANDOM % 900 + 100))}"

cleanup() {
  if [ "$KEEP" = 1 ]; then
    echo "KEPT: copy=$WORK tenant=$TENANT_DB central=$CENTRAL_DB user=$DB_USER"
    return
  fi
  mysql -e "DROP DATABASE IF EXISTS \`$TENANT_DB\`; DROP DATABASE IF EXISTS \`$CENTRAL_DB\`; DROP USER IF EXISTS '$DB_USER'@'localhost';" || true
  rm -rf "$WORK"
}
trap cleanup EXIT

echo "copy: $WORK"
rsync -a --exclude .git --exclude .module-flow-private --exclude node_modules --exclude media --exclude dbs --exclude 'pf/vendor/browser-face' \
  --exclude test-results --exclude content --exclude 'phpmyadmin*' "$SRC/" "$WORK/"

echo "databases: $TENANT_DB $CENTRAL_DB"
mysql -e "CREATE DATABASE \`$TENANT_DB\` CHARACTER SET utf8mb4; CREATE DATABASE \`$CENTRAL_DB\` CHARACTER SET utf8mb4;"
# Schema only. View/routine definers are rewritten to the invoker and the
# cross-database references (views join ccc_central.user) are re-pointed to
# the disposable central database.
schema() {
  mysqldump --no-data --routines --single-transaction --skip-comments "$1" 2>/dev/null \
    | sed -E 's/DEFINER=`[^`]+`@`[^`]+`/DEFINER=CURRENT_USER/g' \
    | sed -E "s/\`ccc_central\`\./\`$CENTRAL_DB\`./g; s/\`ccc_database\`\./\`$TENANT_DB\`./g"
}
schema "$CENTRAL_SCHEMA_DB" | mysql "$CENTRAL_DB"
schema "$TENANT_SCHEMA_DB" | mysql "$TENANT_DB"
# Reference data only: the module and action catalogues (no people, no tenants).
mysql -e "INSERT INTO \`$CENTRAL_DB\`.module SELECT * FROM \`$CENTRAL_SCHEMA_DB\`.module; INSERT INTO \`$CENTRAL_DB\`.action SELECT * FROM \`$CENTRAL_SCHEMA_DB\`.action;"

mysql -e "CREATE USER '$DB_USER'@'localhost' IDENTIFIED BY '$DB_PASS';
  GRANT ALL PRIVILEGES ON \`$TENANT_DB\`.* TO '$DB_USER'@'localhost';
  GRANT ALL PRIVILEGES ON \`$CENTRAL_DB\`.* TO '$DB_USER'@'localhost';"

# Replace the copy's connection class: same methods, but connect() can only
# reach the two disposable databases through the restricted account.
php "$SRC/tests/batch-attendance/make-test-dbconnection.php" "$SRC/classes/dbconnection.class.php" > "$WORK/classes/dbconnection.class.php"
cp "$SRC/tests/batch-attendance/router.php" "$WORK/bflow-router.php"

export BFLOW_TENANT_DB="$TENANT_DB" BFLOW_CENTRAL_DB="$CENTRAL_DB" BFLOW_DB_USER="$DB_USER" BFLOW_DB_PASS="$DB_PASS"
export BFLOW_WORK="$WORK" BFLOW_PORT="$PORT" BFLOW_FILTER="$FILTER"
export BFLOW_KEY="$(head -c16 /dev/urandom | od -An -tx1 | tr -d ' \n')"

php "$SRC/tests/batch-attendance/seed.php"

set +e
unshare -n bash -c '
  ip link set lo up
  cd "$BFLOW_WORK"
  php -d sendmail_path=/bin/true -d display_errors=0 -d log_errors=1 -d error_log="$BFLOW_WORK/php-errors.log" \
      -S 127.0.0.1:$BFLOW_PORT -t "$BFLOW_WORK" "$BFLOW_WORK/bflow-router.php" > "$BFLOW_WORK/server.log" 2>&1 &
  SERVER=$!
  for i in $(seq 1 50); do (exec 3<>/dev/tcp/127.0.0.1/$BFLOW_PORT) 2>/dev/null && break; sleep 0.1; done
  php "'"$SRC"'/tests/batch-attendance/flow.php"
  RC=$?
  kill $SERVER 2>/dev/null
  exit $RC
'
RC=$?
set -e
if [ -s "$WORK/php-errors.log" ]; then
  echo "---- PHP errors/warnings logged by the application during the run (deduplicated) ----"
  sed -E 's/^\[[^]]+\] //' "$WORK/php-errors.log" | sort | uniq -c | sort -rn | head -40
fi
exit $RC
