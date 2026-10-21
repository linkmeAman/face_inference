<?php

declare(strict_types=1);

/**
 * Tests the ATT-CORE-01 attendance domain service contract.
 *
 * This file is created and run before the service implementation. The
 * pre-change run must fail because the service is not present; the post-change
 * required run must pass every scenario below.
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

function stopCoreRun(string $message, bool $required): void
{
    $label = $required ? 'BLOCKED' : 'SKIPPED';
    fwrite($required ? STDERR : STDOUT, "{$label}: {$message}\n");
    exit($required ? 2 : 0);
}

function allowedCoreDb(string $name, string $prefix): bool
{
    return (bool) preg_match('/^' . preg_quote($prefix, '/') . '(?:_[a-z0-9_]+)?$/', $name);
}

if (!allowedCoreDb($dbName, 'ccc_database_test') || !allowedCoreDb($centralDbName, 'ccc_central_test')) {
    fwrite(STDERR, "Refusing to run ATT-CORE-01 outside the approved test database namespaces.\n");
    exit(2);
}

if (!extension_loaded('mysqli')) {
    stopCoreRun('the PHP mysqli extension is unavailable', $required);
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
        stopCoreRun("unable to connect to the regression databases: {$fallbackException->getMessage()}", $required);
    }
}

$servicePath = $root . '/classes/services/AttendanceDomainService.php';
if (!is_file($servicePath)) {
    fwrite(STDERR, "FAIL ATT-CORE-01 service contract: AttendanceDomainService.php is missing (expected pre-change baseline).\n");
    exit(1);
}
require_once $servicePath;

function coreSchema(mysqli $db): void
{
    $schema = <<<'SQL'
CREATE TEMPORARY TABLE IF NOT EXISTS `member` (
  `id` INT NOT NULL AUTO_INCREMENT PRIMARY KEY,
  `contact_id` INT NOT NULL,
  `status` INT NOT NULL DEFAULT 1,
  `park` INT NOT NULL DEFAULT 0,
  `bid` INT NOT NULL DEFAULT 1,
  `pending_makeup` INT NOT NULL DEFAULT 0,
  `pending_not_pay_sal_attendance` INT NOT NULL DEFAULT 0,
  UNIQUE KEY `uq_member_contact` (`contact_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
CREATE TEMPORARY TABLE IF NOT EXISTS `batch` (
  `id` INT NOT NULL PRIMARY KEY,
  `bid` INT NOT NULL DEFAULT 1,
  `venue_id` INT NOT NULL DEFAULT 501,
  `completed_sessions` INT NOT NULL DEFAULT 3,
  `batch` VARCHAR(100) NOT NULL DEFAULT 'Core Batch',
  `parent_id` INT NOT NULL DEFAULT 0,
  `park` INT NOT NULL DEFAULT 0
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
CREATE TEMPORARY TABLE IF NOT EXISTS `invoice_item` (
  `id` INT NOT NULL PRIMARY KEY,
  `sessions` INT NOT NULL DEFAULT 10,
  `sessions_used` INT NOT NULL DEFAULT 0,
  `start_date` DATE NOT NULL,
  `end_date` DATE NOT NULL,
  `batch_id` INT NOT NULL DEFAULT 11,
  `modified_by` INT NOT NULL DEFAULT 0
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
CREATE TEMPORARY TABLE IF NOT EXISTS `invoice_invoiceitem_view` (
  `item_id` INT NOT NULL PRIMARY KEY,
  `contact_id` INT NOT NULL,
  `batch_id` INT NOT NULL DEFAULT 11,
  `start_date` DATE NOT NULL,
  `end_date` DATE NOT NULL,
  `sessions` INT NOT NULL DEFAULT 10,
  `sessions_used` INT NOT NULL DEFAULT 0,
  `park` INT NOT NULL DEFAULT 0,
  `item_park` INT NOT NULL DEFAULT 0
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
CREATE TEMPORARY TABLE IF NOT EXISTS `attendance` (
  `id` INT NOT NULL AUTO_INCREMENT PRIMARY KEY,
  `date` DATE NOT NULL,
  `in_time` TIME NOT NULL DEFAULT '00:00:00',
  `out_time` TIME NOT NULL DEFAULT '00:00:00',
  `contact_id` INT NOT NULL,
  `batch_id` INT NOT NULL DEFAULT 0,
  `venue_id` INT NOT NULL DEFAULT 0,
  `code` VARCHAR(50) NOT NULL DEFAULT '',
  `session_id` INT NOT NULL DEFAULT 0,
  `negative` INT NOT NULL DEFAULT 0,
  `session` INT NOT NULL DEFAULT 0,
  `sessions_completed` INT NOT NULL DEFAULT 0,
  `user_id` INT NOT NULL DEFAULT 0,
  `type` VARCHAR(2) NOT NULL DEFAULT 'M',
  `status` INT NOT NULL DEFAULT 1,
  `batch` VARCHAR(200) NOT NULL DEFAULT '',
  `comment` VARCHAR(1000) NOT NULL DEFAULT '',
  `attendance_count` INT NOT NULL DEFAULT 0,
  `class_no` INT NOT NULL DEFAULT 0,
  `park` INT NOT NULL DEFAULT 0,
  `bid` INT NOT NULL DEFAULT 0,
  `source` INT NOT NULL DEFAULT 4,
  `final_source` VARCHAR(50) NOT NULL DEFAULT '',
  `row_color` INT NOT NULL DEFAULT 0,
  `pay_sal` INT NOT NULL DEFAULT 1,
  `makeup` INT NOT NULL DEFAULT 0,
  `created_by` INT NOT NULL DEFAULT 0,
  `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `modified_by` INT NOT NULL DEFAULT 0,
  UNIQUE KEY `uq_core_attendance` (`contact_id`, `batch_id`, `date`, `session_id`, `type`, `park`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
CREATE TEMPORARY TABLE IF NOT EXISTS `attendance_event` (
  `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
  `branch_id` INT NOT NULL,
  `batch_id` INT NOT NULL,
  `session_id` INT NOT NULL,
  `attendance_date` DATE NOT NULL,
  `person_type` CHAR(1) NOT NULL,
  `person_id` INT NOT NULL,
  `event_type` VARCHAR(32) NOT NULL,
  `source` VARCHAR(32) NOT NULL,
  `actor_employee_id` INT NOT NULL,
  `attendance_id` INT NULL,
  `scan_id` BIGINT UNSIGNED NULL,
  `client_attempt_id` VARCHAR(191) NULL,
  `occurred_at_server` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `occurred_at_device` DATETIME NULL,
  `metadata` JSON NULL,
  `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  UNIQUE KEY `uq_attendance_event_once` (`batch_id`, `session_id`, `attendance_date`, `person_type`, `person_id`, `event_type`),
  KEY `idx_attendance_event_attempt` (`client_attempt_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
SQL;

    foreach (explode(";\n", $schema) as $statement) {
        $statement = trim($statement);
        if ($statement !== '') {
            $db->query($statement);
        }
    }
}

function coreReset(mysqli $db, int $sessions = 10, int $sessionsUsed = 0): void
{
    foreach (['attendance_event', 'attendance', 'invoice_invoiceitem_view', 'invoice_item', 'batch', 'member'] as $table) {
        $db->query("TRUNCATE TABLE `{$table}`");
    }
    $db->query("INSERT INTO member (contact_id, status, park, bid) VALUES (101, 1, 0, 1)");
    $db->query("INSERT INTO batch (id, bid, venue_id, completed_sessions, batch) VALUES (11, 1, 501, 3, 'Core Batch')");
    $db->query("INSERT INTO invoice_item (id, sessions, sessions_used, start_date, end_date, batch_id) VALUES (21, {$sessions}, {$sessionsUsed}, '2000-01-01', '2100-01-01', 11)");
    $db->query("INSERT INTO invoice_invoiceitem_view (item_id, contact_id, batch_id, start_date, end_date, sessions, sessions_used) VALUES (21, 101, 11, '2000-01-01', '2100-01-01', {$sessions}, {$sessionsUsed})");
}

function coreCommand(array $overrides = []): array
{
    return array_merge([
        'branch_id' => 1,
        'batch_id' => 11,
        'attendance_date' => '2026-09-11',
        'session_id' => 7,
        'member_id' => 101,
        'person_type' => 'M',
        'negative' => 0,
        'source' => 'MANUAL',
        'event_type' => 'PUNCH_IN',
        'actor_employee_id' => 9001,
        'client_attempt_id' => 'core-attempt-1',
        'code' => 'M-101',
        'attendance_time' => '09:00:00',
        'item_id' => 21,
        'attendance_session' => 1,
        'deduct_session_absentee' => 1,
        'check_month' => false,
        'row_color' => 1,
        'comment' => '',
    ], $overrides);
}

function coreScalar(mysqli $db, string $query)
{
    $row = $db->query($query)->fetch_row();
    return $row[0] ?? null;
}

function coreAssert($expected, $actual, string $message): void
{
    if ($expected !== $actual) {
        throw new RuntimeException($message . ' (expected ' . var_export($expected, true) . ', got ' . var_export($actual, true) . ')');
    }
}

function coreCase(string $name, callable $callback, array &$results): void
{
    try {
        $callback();
        $results[] = ['name' => $name, 'status' => 'PASS'];
    } catch (Throwable $exception) {
        $results[] = ['name' => $name, 'status' => 'FAIL', 'message' => $exception->getMessage()];
    }
}

coreSchema($db);
$results = [];

coreCase('ATT-CORE-01 records a present member and deducts once', function () use ($db): void {
    coreReset($db, 10, 2);
    $result = (new AttendanceDomainService($db))->recordMemberAttendance(coreCommand());
    coreAssert('RECORDED', $result['state'], 'first punch state');
    coreAssert(1, (int) coreScalar($db, 'SELECT COUNT(*) FROM attendance'), 'first punch row count');
    coreAssert(1, (int) coreScalar($db, 'SELECT COUNT(*) FROM attendance_event'), 'first event row count');
    coreAssert('PUNCH_IN', coreScalar($db, 'SELECT event_type FROM attendance_event'), 'first event type');
    coreAssert(3, (int) coreScalar($db, 'SELECT sessions_used FROM invoice_item WHERE id = 21'), 'first punch deduction');
}, $results);

coreCase('ATT-CORE-01 resolves a duplicate without a second deduction', function () use ($db): void {
    coreReset($db, 10, 2);
    $service = new AttendanceDomainService($db);
    $service->recordMemberAttendance(coreCommand());
    $result = $service->recordMemberAttendance(coreCommand(['client_attempt_id' => 'core-attempt-2']));
    coreAssert('ALREADY_MARKED', $result['state'], 'duplicate state');
    coreAssert(1, (int) coreScalar($db, 'SELECT COUNT(*) FROM attendance'), 'duplicate row count');
    coreAssert(1, (int) coreScalar($db, 'SELECT COUNT(*) FROM attendance_event'), 'duplicate event row count');
    coreAssert(3, (int) coreScalar($db, 'SELECT sessions_used FROM invoice_item WHERE id = 21'), 'duplicate deduction');
}, $results);

coreCase('ATT-CORE-01 database event key rejects a duplicate business event', function () use ($db): void {
    coreReset($db, 10, 0);
    (new AttendanceDomainService($db))->recordMemberAttendance(coreCommand());
    $rejected = false;
    try {
        $db->query("INSERT INTO attendance_event (branch_id, batch_id, session_id, attendance_date, person_type, person_id, event_type, source, actor_employee_id, client_attempt_id) VALUES (1, 11, 7, '2026-09-11', 'M', 101, 'PUNCH_IN', 'MANUAL', 9001, 'core-direct-duplicate')");
    } catch (Throwable $exception) {
        $rejected = $exception instanceof mysqli_sql_exception && (int)$exception->getCode() === 1062;
    }
    coreAssert(true, $rejected, 'database unique key rejection');
    coreAssert(1, (int) coreScalar($db, 'SELECT COUNT(*) FROM attendance_event'), 'database duplicate event count');
}, $results);

coreCase('ATT-CORE-01 keeps different sessions independent', function () use ($db): void {
    coreReset($db, 10, 0);
    $service = new AttendanceDomainService($db);
    $first = $service->recordMemberAttendance(coreCommand());
    $second = $service->recordMemberAttendance(coreCommand(['session_id' => 8, 'client_attempt_id' => 'core-attempt-2', 'attendance_time' => '10:00:00']));
    coreAssert('RECORDED', $first['state'], 'first session state');
    coreAssert('RECORDED', $second['state'], 'second session state');
    coreAssert(2, (int) coreScalar($db, 'SELECT COUNT(*) FROM attendance'), 'different-session row count');
    coreAssert(2, (int) coreScalar($db, 'SELECT COUNT(*) FROM attendance_event'), 'different-session event count');
    coreAssert(2, (int) coreScalar($db, 'SELECT sessions_used FROM invoice_item WHERE id = 21'), 'different-session deduction');
}, $results);

coreCase('ATT-CORE-01 records punch-out without deduction', function () use ($db): void {
    coreReset($db, 10, 2);
    $service = new AttendanceDomainService($db);
    $service->recordMemberAttendance(coreCommand());
    $result = $service->recordMemberAttendance(coreCommand([
        'event_type' => 'PUNCH_OUT',
        'client_attempt_id' => 'core-punch-out-1',
        'attendance_time' => '10:00:00',
    ]));
    coreAssert('PUNCH_OUT_RECORDED', $result['state'], 'punch-out state');
    coreAssert('10:00:00', coreScalar($db, 'SELECT out_time FROM attendance WHERE id = 1'), 'punch-out time');
    coreAssert(2, (int) coreScalar($db, 'SELECT COUNT(*) FROM attendance_event'), 'punch-out event count');
    coreAssert(1, (int) coreScalar($db, "SELECT COUNT(*) FROM attendance_event WHERE event_type = 'PUNCH_OUT'"), 'punch-out event type');
    coreAssert(3, (int) coreScalar($db, 'SELECT sessions_used FROM invoice_item WHERE id = 21'), 'punch-out deduction');
}, $results);

coreCase('ATT-CORE-01 rejects unauthorized branch and old date without writes', function () use ($db): void {
    coreReset($db, 10, 0);
    $service = new AttendanceDomainService($db);
    $branch = $service->recordMemberAttendance(coreCommand(['branch_id' => 999]));
    $date = $service->recordMemberAttendance(coreCommand(['check_month' => true, 'attendance_date' => date('Y-m-d', strtotime('first day of last month'))]));
    coreAssert('REJECTED', $branch['state'], 'branch authorization state');
    coreAssert('REJECTED', $date['state'], 'date policy state');
    coreAssert(0, (int) coreScalar($db, 'SELECT COUNT(*) FROM attendance'), 'rejected write count');
    coreAssert(0, (int) coreScalar($db, 'SELECT COUNT(*) FROM attendance_event'), 'rejected event write count');
}, $results);

$failed = 0;
foreach ($results as $result) {
    if ($result['status'] === 'PASS') {
        fwrite(STDOUT, "PASS {$result['name']}\n");
    } else {
        $failed++;
        fwrite(STDERR, "FAIL {$result['name']}: {$result['message']}\n");
    }
}

fwrite(STDOUT, sprintf("%d ATT-CORE-01 scenarios, %d failed.\n", count($results), $failed));
exit($failed === 0 ? 0 : 1);
