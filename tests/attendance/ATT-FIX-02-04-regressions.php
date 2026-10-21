<?php

declare(strict_types=1);

/**
 * Regression tests for ATT-FIX-02, ATT-FIX-03, and ATT-FIX-04.
 *
 * Run this before and after the production changes. The pre-change run is
 * expected to fail; the post-change required run must pass all scenarios.
 */

date_default_timezone_set('UTC');

$root = dirname(__DIR__, 2);
chdir($root . '/controllers');

$required = in_array('--required', $argv, true)
    || filter_var(getenv('ATTENDANCE_TEST_REQUIRED') ?: '0', FILTER_VALIDATE_BOOLEAN);
$dbName = getenv('ATTENDANCE_TEST_DB') ?: 'ccc_database_test';
$centralDbName = getenv('ATTENDANCE_TEST_CENTRAL_DB') ?: 'ccc_central_test';
$dbHost = getenv('ATTENDANCE_TEST_DB_HOST') ?: '127.0.0.1';
$dbUser = getenv('ATTENDANCE_TEST_DB_USER') ?: 'root';
$dbPassword = getenv('ATTENDANCE_TEST_DB_PASSWORD') ?: '';

function stopRegressionRun(string $message, bool $required): void
{
    $label = $required ? 'BLOCKED' : 'SKIPPED';
    fwrite($required ? STDERR : STDOUT, "{$label}: {$message}\n");
    exit($required ? 2 : 0);
}

function allowedRegressionDb(string $name, string $prefix): bool
{
    return (bool) preg_match('/^' . preg_quote($prefix, '/') . '(?:_[a-z0-9_]+)?$/', $name);
}

if (!allowedRegressionDb($dbName, 'ccc_database_test') || !allowedRegressionDb($centralDbName, 'ccc_central_test')) {
    fwrite(STDERR, "Refusing to run regression tests outside the approved test database namespaces.\n");
    exit(2);
}

if (!extension_loaded('mysqli')) {
    stopRegressionRun('the PHP mysqli extension is unavailable', $required);
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
    stopRegressionRun("unable to connect to the regression databases: {$exception->getMessage()}", $required);
}

require_once $root . '/controllers/member.php';
require_once $root . '/controllers/attendance.php';

$_SERVER['HTTP_UID'] = $_SERVER['HTTP_UID'] ?? '9001';

final class RegressionAttendance extends attendance
{
    public function shiftInvoice($conn, $contact_id, $item_id, $date)
    {
        return null;
    }
}

function regressionSchema(mysqli $db): void
{
    $schema = <<<'SQL'
CREATE TEMPORARY TABLE IF NOT EXISTS `member` (
  `contact_id` INT NOT NULL PRIMARY KEY,
  `pending_makeup` INT NOT NULL DEFAULT 0,
  `pending_not_pay_sal_attendance` INT NOT NULL DEFAULT 0
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
CREATE TEMPORARY TABLE IF NOT EXISTS `batch` (
  `id` INT NOT NULL PRIMARY KEY,
  `venue_id` INT NOT NULL DEFAULT 1,
  `completed_sessions` INT NOT NULL DEFAULT 0,
  `batch` VARCHAR(100) NOT NULL DEFAULT 'Regression Batch'
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
CREATE TEMPORARY TABLE IF NOT EXISTS `invoice_item` (
  `id` INT NOT NULL PRIMARY KEY,
  `sessions_used` INT NOT NULL DEFAULT 0,
  `modified_by` INT NOT NULL DEFAULT 0
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
CREATE TEMPORARY TABLE IF NOT EXISTS `invoice_invoiceitem_view` (
  `item_id` INT NOT NULL PRIMARY KEY,
  `contact_id` INT NOT NULL,
  `start_date` DATE NOT NULL,
  `end_date` DATE NOT NULL,
  `sessions` INT NOT NULL DEFAULT 0,
  `sessions_used` INT NOT NULL DEFAULT 0,
  `batch_id` INT NOT NULL DEFAULT 0,
  `park` TINYINT NOT NULL DEFAULT 0,
  `item_park` TINYINT NOT NULL DEFAULT 0
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
  `negative` TINYINT NOT NULL DEFAULT 0,
  `session` TINYINT NOT NULL DEFAULT 0,
  `session_id` INT NOT NULL DEFAULT 0,
  `user_id` INT NOT NULL DEFAULT 0,
  `bid` INT NOT NULL DEFAULT 0,
  `source` VARCHAR(20) NOT NULL DEFAULT '',
  `type` CHAR(1) NOT NULL DEFAULT 'M',
  `status` TINYINT NOT NULL DEFAULT 1,
  `attendance_count` INT NOT NULL DEFAULT 0,
  `class_no` INT NOT NULL DEFAULT 0,
  `batch` VARCHAR(100) NOT NULL DEFAULT '',
  `comment` VARCHAR(255) NOT NULL DEFAULT '',
  `row_color` VARCHAR(20) NOT NULL DEFAULT '',
  `created_at` DATETIME NULL,
  `created_by` INT NOT NULL DEFAULT 0,
  `modified_by` INT NOT NULL DEFAULT 0,
  `makeup` TINYINT NOT NULL DEFAULT 0,
  `pay_sal` TINYINT NOT NULL DEFAULT 1,
  `sessions_completed` INT NOT NULL DEFAULT 0,
  `park` TINYINT NOT NULL DEFAULT 0
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
SQL;

    foreach (explode(";\n", $schema) as $statement) {
        $statement = trim($statement);
        if ($statement !== '') {
            $db->query($statement);
        }
    }
}

function resetRegressionFixture(mysqli $db, int $sessions = 10, int $sessionsUsed = 0): void
{
    foreach (['attendance', 'invoice_invoiceitem_view', 'invoice_item', 'batch', 'member'] as $table) {
        $db->query("TRUNCATE TABLE `{$table}`");
    }

    $db->query("INSERT INTO member (contact_id) VALUES (101)");
    $db->query("INSERT INTO batch (id, venue_id, completed_sessions, batch) VALUES (11, 501, 3, 'Regression Batch')");
    $db->query("INSERT INTO invoice_item (id, sessions_used) VALUES (21, {$sessionsUsed})");
    $db->query("INSERT INTO invoice_invoiceitem_view (item_id, contact_id, start_date, end_date, sessions, sessions_used, batch_id) VALUES (21, 101, '2000-01-01', '2100-01-01', {$sessions}, {$sessionsUsed}, 11)");
}

function regressionPunch(mysqli $db, array $overrides = []): array
{
    $request = array_merge([
        'contact_id' => 101,
        'batch_id' => 11,
        'code' => 'M-101',
        'datetime' => '2026-09-11 09:00:00',
        'session_id' => 7,
        'bid' => 1,
        'source' => '4',
        'type' => 'M',
        'negative' => 0,
        'session' => 1,
        'item_id' => 21,
        'deduct_session_absentee' => 1,
        'check_month' => false,
        'user_id' => 9001,
        'fullname' => 'Regression Member',
        'comment' => '',
        'row_color' => '1',
    ], $overrides);

    return (new RegressionAttendance())->punch_attendance($db, $request, [
        'punch_dif' => '00:05:00',
        'bio_extension' => 0,
        'bio_type' => 1,
        'deduct_session_absentee' => 0,
    ]);
}

function regressionScalar(mysqli $db, string $query)
{
    $row = $db->query($query)->fetch_row();
    return $row[0] ?? null;
}

function regressionAssert($expected, $actual, string $message): void
{
    if ($expected !== $actual) {
        throw new RuntimeException($message . ' (expected ' . var_export($expected, true) . ', got ' . var_export($actual, true) . ')');
    }
}

function regressionCase(string $name, callable $callback, array &$results): void
{
    try {
        $callback();
        $results[] = ['name' => $name, 'status' => 'PASS'];
    } catch (Throwable $exception) {
        $results[] = ['name' => $name, 'status' => 'FAIL', 'message' => $exception->getMessage()];
    }
}

regressionSchema($db);
$results = [];

regressionCase('ATT-FIX-02 unlimited eligibility', function (): void {
    if (!method_exists('member', 'is_membership_active')) {
        throw new RuntimeException('member::is_membership_active() is not implemented');
    }
    regressionAssert(true, member::is_membership_active('2100-01-01', 0, 99, '2026-09-11'), 'sessions = 0 must be unlimited');
    regressionAssert(true, member::is_membership_active('2026-09-30', 10, 9, '2026-09-11'), 'finite membership with balance must be active');
    regressionAssert(false, member::is_membership_active('2026-09-30', 10, 10, '2026-09-11'), 'exhausted membership must be inactive');
    regressionAssert(false, member::is_membership_active('2026-09-10', 0, 0, '2026-09-11'), 'expired unlimited membership must be inactive');
}, $results);

regressionCase('ATT-FIX-03 different sessions are independent', function () use ($db): void {
    resetRegressionFixture($db, 10, 0);
    $first = regressionPunch($db, ['session_id' => 7]);
    $second = regressionPunch($db, ['session_id' => 8, 'datetime' => '2026-09-11 10:00:00']);
    regressionAssert(0, $first['error'], 'first session punch should succeed');
    regressionAssert(0, $second['error'], 'second session punch should succeed');
    regressionAssert(2, (int) regressionScalar($db, 'SELECT COUNT(*) FROM attendance'), 'each session should have one row');
    regressionAssert(2, (int) regressionScalar($db, 'SELECT COUNT(DISTINCT session_id) FROM attendance'), 'session_id must be persisted');
}, $results);

regressionCase('ATT-FIX-04 previous-month punch is rejected server-side', function () use ($db): void {
    resetRegressionFixture($db, 10, 0);
    $previousMonth = date('Y-m-d', strtotime('first day of last month')) . ' 09:00:00';
    $response = regressionPunch($db, ['datetime' => $previousMonth, 'check_month' => true]);
    regressionAssert(3, $response['error'], 'previous-month punch must be rejected');
    regressionAssert(0, (int) regressionScalar($db, 'SELECT COUNT(*) FROM attendance'), 'rejected date must not write attendance');
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

fwrite(STDOUT, sprintf("%d regression scenarios, %d failed.\n", count($results), $failed));
exit($failed === 0 ? 0 : 1);
