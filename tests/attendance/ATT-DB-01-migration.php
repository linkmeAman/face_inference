<?php

declare(strict_types=1);

/**
 * ATT-DB-01 migration contract and disposable-database test.
 *
 * This runner is created before the migration files. The pre-change run
 * prints the current duplicate report and fails because the migrations are
 * absent. It applies only when none of the face tables already exists, then
 * drops only the tables created by this run. It never destroys an existing
 * test deployment or its biometric records.
 */

date_default_timezone_set('UTC');

$root = dirname(__DIR__, 2);
$required = in_array('--required', $argv, true)
    || filter_var(getenv('ATTENDANCE_TEST_REQUIRED') ?: '0', FILTER_VALIDATE_BOOLEAN);
$dbName = getenv('ATTENDANCE_TEST_DB') ?: 'ccc_database_test';
$centralDbName = getenv('ATTENDANCE_TEST_CENTRAL_DB') ?: 'ccc_central_test';
$dbHost = getenv('ATTENDANCE_TEST_DB_HOST') ?: '127.0.0.1';
$dbUser = getenv('ATTENDANCE_TEST_DB_USER') ?: 'root';
$dbPassword = getenv('ATTENDANCE_TEST_DB_PASSWORD') ?: '';

function stopDbMigrationRun(string $message, bool $required): void
{
    $label = $required ? 'BLOCKED' : 'SKIPPED';
    fwrite($required ? STDERR : STDOUT, "{$label}: {$message}\n");
    exit($required ? 2 : 0);
}

function allowedDbMigrationDb(string $name, string $prefix): bool
{
    return (bool) preg_match('/^' . preg_quote($prefix, '/') . '(?:_[a-z0-9_]+)?$/', $name);
}

if (!allowedDbMigrationDb($dbName, 'ccc_database_test') || !allowedDbMigrationDb($centralDbName, 'ccc_central_test')) {
    fwrite(STDERR, "Refusing to run ATT-DB-01 outside the approved test database namespaces.\n");
    exit(2);
}

if (!extension_loaded('mysqli')) {
    stopDbMigrationRun('the PHP mysqli extension is unavailable', $required);
}

mysqli_report(MYSQLI_REPORT_ERROR | MYSQLI_REPORT_STRICT);
try {
    $db = new mysqli($dbHost, $dbUser, $dbPassword, $dbName);
    $db->set_charset('utf8mb4');
    $centralDb = new mysqli($dbHost, $dbUser, $dbPassword, $centralDbName);
    $centralDb->set_charset('utf8mb4');
    $centralDb->close();
} catch (Throwable $exception) {
    if (isset($db) && $db instanceof mysqli) {
        $db->close();
    }
    try {
        require_once $root . '/classes/dbconnection.class.php';
        $connector = new dbconnection();
        $db = $connector->connect($dbName);
        $db->set_charset('utf8mb4');
        $centralDb = $connector->connect($centralDbName);
        $centralDb->set_charset('utf8mb4');
        $centralDb->close();
    } catch (Throwable $fallbackException) {
        stopDbMigrationRun("unable to connect to the migration test databases: {$fallbackException->getMessage()}", $required);
    }
}

$duplicateQuery = "SELECT COUNT(*) AS duplicate_groups FROM (SELECT contact_id, batch_id, date, session_id, type FROM attendance WHERE park = 0 GROUP BY contact_id, batch_id, date, session_id, type HAVING COUNT(*) > 1) duplicate_rows";
$duplicateGroups = (int)$db->query($duplicateQuery)->fetch_assoc()['duplicate_groups'];
$sessionZeroRows = (int)$db->query("SELECT COUNT(*) AS rows_with_zero_session FROM attendance WHERE session_id = 0")->fetch_assoc()['rows_with_zero_session'];
fwrite(STDOUT, "PRECHANGE duplicate_groups={$duplicateGroups} session_zero_rows={$sessionZeroRows}\n");

$migrationDir = $root . '/database/migrations';
$preflightPath = $migrationDir . '/20260911_01_attendance_preflight.sql';
$tablesPath = $migrationDir . '/20260911_02_face_attendance_tables.sql';
$uniquePath = $migrationDir . '/20260911_03_attendance_summary_unique_key_after_review.sql';
$providerScanPath = $migrationDir . '/20260921_01_supervised_face_scan_contract.sql';

if (!is_file($preflightPath) || !is_file($tablesPath) || !is_file($uniquePath) || !is_file($providerScanPath)) {
    fwrite(STDERR, "FAIL ATT-DB-01 migration contract: required migration files are missing (expected pre-change baseline).\n");
    exit(1);
}

$preflight = file_get_contents($preflightPath);
$tablesSql = file_get_contents($tablesPath);
$uniqueSql = file_get_contents($uniquePath);
$providerScanSql = file_get_contents($providerScanPath);
foreach (['biometric_consent', 'face_template', 'face_model_version', 'attendance_scan', 'attendance_scan_roster', 'recognition_attempt', 'attendance_event'] as $table) {
    if (strpos($tablesSql, "CREATE TABLE IF NOT EXISTS `{$table}`") === false) {
        throw new RuntimeException("missing table definition: {$table}");
    }
}
foreach (['uq_recognition_attempt_client', 'uq_attendance_event_once'] as $key) {
    if (strpos($tablesSql, $key) === false) {
        throw new RuntimeException("missing required unique key: {$key}");
    }
}
if (strpos($preflight, 'GROUP BY contact_id, batch_id, date, session_id, type') === false || strpos($preflight, 'session_id = 0') === false) {
    throw new RuntimeException('preflight migration does not report duplicate and session-zero data');
}
if (strpos($uniqueSql, 'SIGNAL SQLSTATE') === false || strpos($uniqueSql, 'uq_attendance_summary_business') === false) {
    throw new RuntimeException('attendance summary migration is not explicitly gated');
}

$newTables = ['attendance_event', 'recognition_attempt', 'attendance_scan_roster', 'attendance_scan', 'face_template', 'biometric_consent', 'face_model_version'];
$preexistingTables = [];
foreach ($newTables as $table) {
    $exists = (int)$db->query("SELECT COUNT(*) AS table_count FROM information_schema.tables WHERE table_schema = DATABASE() AND table_name = '{$table}'")->fetch_assoc()['table_count'];
    if ($exists > 0) {
        $preexistingTables[] = $table;
    }
}
if ($preexistingTables) {
    throw new RuntimeException('refusing destructive migration test because face tables already exist: ' . implode(', ', $preexistingTables));
}

foreach (preg_split('/;\s*(?:\r?\n|$)/', $tablesSql) as $statement) {
    $statement = trim($statement);
    if ($statement === '' || strpos(ltrim($statement), '--') === 0) {
        continue;
    }
    $db->query($statement);
}

foreach (preg_split('/;\s*(?:\r?\n|$)/', $providerScanSql) as $statement) {
    $statement = trim($statement);
    if ($statement !== '') {
        $db->query($statement);
    }
}

foreach ($newTables as $table) {
    $exists = (int)$db->query("SELECT COUNT(*) AS table_count FROM information_schema.tables WHERE table_schema = DATABASE() AND table_name = '{$table}'")->fetch_assoc()['table_count'];
    if ($exists !== 1) {
        throw new RuntimeException("migration did not create {$table}");
    }
}

$eventKey = (int)$db->query("SELECT COUNT(*) AS key_count FROM information_schema.statistics WHERE table_schema = DATABASE() AND table_name = 'attendance_event' AND index_name = 'uq_attendance_event_once'")->fetch_assoc()['key_count'];
$attemptKey = (int)$db->query("SELECT COUNT(*) AS key_count FROM information_schema.statistics WHERE table_schema = DATABASE() AND table_name = 'recognition_attempt' AND index_name = 'uq_recognition_attempt_client'")->fetch_assoc()['key_count'];
if ($eventKey < 1 || $attemptKey < 1) {
    throw new RuntimeException('required migration unique keys were not created');
}

foreach (['provider_id', 'provider_version', 'model_version_id', 'calibration_policy_version'] as $column) {
    $columnCount = (int)$db->query("SELECT COUNT(*) AS column_count FROM information_schema.columns WHERE table_schema = DATABASE() AND table_name = 'attendance_scan' AND column_name = '{$column}'")->fetch_assoc()['column_count'];
    if ($columnCount !== 1) {
        throw new RuntimeException("supervised scan migration did not add {$column}");
    }
}
foreach (['provider_id', 'provider_version'] as $column) {
    $columnCount = (int)$db->query("SELECT COUNT(*) AS column_count FROM information_schema.columns WHERE table_schema = DATABASE() AND table_name = 'face_model_version' AND column_name = '{$column}'")->fetch_assoc()['column_count'];
    if ($columnCount !== 1) {
        throw new RuntimeException("provider migration did not add face_model_version.{$column}");
    }
}

$summaryKey = (int)$db->query("SELECT COUNT(*) AS key_count FROM information_schema.statistics WHERE table_schema = DATABASE() AND table_name = 'attendance' AND index_name = 'uq_attendance_summary_business'")->fetch_assoc()['key_count'];
if ($summaryKey !== 0) {
    throw new RuntimeException('attendance summary key must remain gated until legacy duplicates are resolved');
}

foreach ($newTables as $table) {
    $db->query("DROP TABLE IF EXISTS `{$table}`");
}

fwrite(STDOUT, "PASS ATT-DB-01 migration contract and disposable apply\n");
fwrite(STDOUT, "1 ATT-DB-01 scenario, 0 failed.\n");
