<?php

declare(strict_types=1);

/**
 * ATT-FIX-01: characterization coverage for the legacy manual attendance path.
 *
 * This runner is intentionally standalone because the repository does not have
 * a root PHPUnit configuration. It defaults to ccc_database_test and
 * ccc_central_test and refuses production database names.
 */

date_default_timezone_set('UTC');

$root = dirname(__DIR__, 2);
// The legacy controller uses ../ relative includes from controllers/.
chdir($root . '/controllers');

$required = in_array('--required', $argv, true)
    || filter_var(getenv('ATTENDANCE_TEST_REQUIRED') ?: '0', FILTER_VALIDATE_BOOLEAN);

$dbName = getenv('ATTENDANCE_TEST_DB') ?: 'ccc_database_test';
$centralDbName = getenv('ATTENDANCE_TEST_CENTRAL_DB') ?: 'ccc_central_test';

function stopWithoutDatabase(string $message, bool $required): void
{
    $label = $required ? 'BLOCKED' : 'SKIPPED';
    $stream = $required ? STDERR : STDOUT;
    fwrite($stream, "{$label}: {$message}\n");
    exit($required ? 2 : 0);
}

function isAllowedTestDatabase(string $name, string $prefix): bool
{
    return (bool) preg_match('/^' . preg_quote($prefix, '/') . '(?:_[a-z0-9_]+)?$/', $name);
}

if (!isAllowedTestDatabase($dbName, 'ccc_database_test')) {
    fwrite(STDERR, "Refusing to run: application database must be ccc_database_test or a ccc_database_test_* name.\n");
    exit(2);
}

if (!isAllowedTestDatabase($centralDbName, 'ccc_central_test')) {
    fwrite(STDERR, "Refusing to run: central database must be ccc_central_test or a ccc_central_test_* name.\n");
    exit(2);
}

$dbHost = getenv('ATTENDANCE_TEST_DB_HOST') ?: '127.0.0.1';
$dbUser = getenv('ATTENDANCE_TEST_DB_USER') ?: 'root';
$dbPassword = getenv('ATTENDANCE_TEST_DB_PASSWORD') ?: '';

if (!extension_loaded('mysqli')) {
    stopWithoutDatabase('the PHP mysqli extension is unavailable', $required);
}

mysqli_report(MYSQLI_REPORT_ERROR | MYSQLI_REPORT_STRICT);

try {
    $db = new mysqli($dbHost, $dbUser, $dbPassword, $dbName);
    $db->set_charset('utf8mb4');
} catch (Throwable $exception) {
    stopWithoutDatabase("unable to connect to {$dbName}: {$exception->getMessage()}", $required);
}

try {
    $centralDb = new mysqli($dbHost, $dbUser, $dbPassword, $centralDbName);
    $centralDb->set_charset('utf8mb4');
    $centralDb->close();
} catch (Throwable $exception) {
    $db->close();
    stopWithoutDatabase("unable to connect to {$centralDbName}: {$exception->getMessage()}", $required);
}

require_once $root . '/controllers/attendance.php';

if (!isset($_SERVER['HTTP_UID'])) {
    $_SERVER['HTTP_UID'] = '9001';
}

/** Avoid the unrelated invoice-shifting side effect in this characterization. */
final class CharacterizationAttendance extends attendance
{
    public function shiftInvoice($conn, $contact_id, $item_id, $date)
    {
        return null;
    }
}

function executeSchema(mysqli $db): void
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
  `batch` VARCHAR(100) NOT NULL DEFAULT 'Characterization Batch'
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

function resetFixture(mysqli $db, int $sessions, int $sessionsUsed = 0): void
{
    foreach (['attendance', 'invoice_invoiceitem_view', 'invoice_item', 'batch', 'member'] as $table) {
        $db->query("TRUNCATE TABLE `{$table}`");
    }

    $db->query("INSERT INTO `member` (`contact_id`) VALUES (101), (202)");
    $db->query("INSERT INTO `batch` (`id`, `venue_id`, `completed_sessions`, `batch`) VALUES (11, 501, 3, 'Characterization Batch')");
    $db->query("INSERT INTO `invoice_item` (`id`, `sessions_used`) VALUES (21, {$sessionsUsed})");
    $db->query("INSERT INTO `invoice_invoiceitem_view` (`item_id`, `contact_id`, `start_date`, `end_date`, `sessions`, `sessions_used`, `batch_id`) VALUES (21, 101, '2000-01-01', '2100-01-01', {$sessions}, {$sessionsUsed}, 11)");
}

function baseRequest(array $overrides = []): array
{
    return array_merge([
        'contact_id' => 101,
        'batch_id' => 11,
        'code' => 'M-101',
        'datetime' => '2026-09-10 09:00:00',
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
        'fullname' => 'Characterization Member',
        'comment' => '',
        'row_color' => '1',
    ], $overrides);
}

function punchSettings(): array
{
    return ['punch_dif' => '00:05:00', 'bio_extension' => 0, 'bio_type' => 1, 'deduct_session_absentee' => 0];
}

function punch(mysqli $db, array $overrides = []): array
{
    $controller = new CharacterizationAttendance();
    return $controller->punch_attendance($db, baseRequest($overrides), punchSettings());
}

function scalar(mysqli $db, string $query)
{
    $row = $db->query($query)->fetch_row();
    return $row[0] ?? null;
}

function assertSameValue($expected, $actual, string $message): void
{
    if ($expected !== $actual) {
        throw new RuntimeException($message . ' (expected ' . var_export($expected, true) . ', got ' . var_export($actual, true) . ')');
    }
}

function scenario(string $name, callable $test, array &$results): void
{
    try {
        $test();
        $results[] = ['name' => $name, 'status' => 'PASS'];
    } catch (Throwable $exception) {
        $results[] = ['name' => $name, 'status' => 'FAIL', 'message' => $exception->getMessage()];
    }
}

executeSchema($db);
$results = [];

scenario('employee automatic presence', function () use ($db): void {
    resetFixture($db, 10);
    $response = punch($db, ['contact_id' => 202, 'code' => 'E-202', 'type' => 'E', 'session' => 1, 'item_id' => 0]);
    assertSameValue(0, $response['error'], 'employee punch should succeed');
    assertSameValue(1, (int) scalar($db, "SELECT COUNT(*) FROM attendance WHERE type = 'E'"), 'employee attendance row count');
    assertSameValue(0, (int) scalar($db, 'SELECT sessions_used FROM invoice_item WHERE id = 21'), 'employee punch must not change membership balance');
}, $results);

scenario('limited membership present deducts one session', function () use ($db): void {
    resetFixture($db, 10, 2);
    $response = punch($db, ['negative' => 0, 'session' => 1, 'deduct_session_absentee' => 1]);
    assertSameValue(0, $response['error'], 'present punch should succeed');
    assertSameValue(0, (int) scalar($db, 'SELECT negative FROM attendance LIMIT 1'), 'present negative value');
    assertSameValue(3, (int) scalar($db, 'SELECT sessions_used FROM invoice_item WHERE id = 21'), 'limited membership deduction');
}, $results);

scenario('unlimited membership does not consume a finite balance', function () use ($db): void {
    resetFixture($db, 0, 0);
    $response = punch($db, ['negative' => 0, 'session' => 0, 'deduct_session_absentee' => 0]);
    assertSameValue(0, $response['error'], 'unlimited present punch should succeed');
    assertSameValue(0, (int) scalar($db, 'SELECT sessions_used FROM invoice_item WHERE id = 21'), 'unlimited membership balance');
}, $results);

scenario('absence with current setting does not deduct', function () use ($db): void {
    resetFixture($db, 10, 2);
    $response = punch($db, ['negative' => 1, 'session' => 0, 'deduct_session_absentee' => 0]);
    assertSameValue(0, $response['error'], 'absence should be recorded');
    assertSameValue(1, (int) scalar($db, 'SELECT negative FROM attendance LIMIT 1'), 'absence negative value');
    assertSameValue(2, (int) scalar($db, 'SELECT sessions_used FROM invoice_item WHERE id = 21'), 'absence deduction setting 0');
}, $results);

scenario('absence with deduction enabled consumes one session', function () use ($db): void {
    resetFixture($db, 10, 2);
    $response = punch($db, ['negative' => 1, 'session' => 1, 'deduct_session_absentee' => 1]);
    assertSameValue(0, $response['error'], 'absence should be recorded');
    assertSameValue(3, (int) scalar($db, 'SELECT sessions_used FROM invoice_item WHERE id = 21'), 'absence deduction setting 1');
}, $results);

scenario('inactive or expired member is recorded as negative 2 without deduction', function () use ($db): void {
    resetFixture($db, 10, 2);
    $response = punch($db, ['negative' => 2, 'session' => 0, 'deduct_session_absentee' => 0]);
    assertSameValue(0, $response['error'], 'inactive/expired attendance should be recorded');
    assertSameValue(2, (int) scalar($db, 'SELECT negative FROM attendance LIMIT 1'), 'inactive/expired negative value');
    assertSameValue(2, (int) scalar($db, 'SELECT sessions_used FROM invoice_item WHERE id = 21'), 'inactive/expired deduction');
}, $results);

scenario('repeated submission in the same session is rejected', function () use ($db): void {
    resetFixture($db, 10, 0);
    $first = punch($db, ['session_id' => 7]);
    $second = punch($db, ['session_id' => 7, 'datetime' => '2026-09-10 09:10:00']);
    assertSameValue(0, $first['error'], 'first punch should succeed');
    assertSameValue(1, $second['error'], 'repeat punch should be rejected');
    assertSameValue(1, (int) scalar($db, 'SELECT COUNT(*) FROM attendance'), 'repeat attendance row count');
    assertSameValue(1, (int) scalar($db, 'SELECT sessions_used FROM invoice_item WHERE id = 21'), 'repeat membership deduction');
}, $results);

scenario('different sessions on one date are independent', function () use ($db): void {
    resetFixture($db, 10, 0);
    $first = punch($db, ['session_id' => 7]);
    $second = punch($db, ['session_id' => 8, 'datetime' => '2026-09-10 10:00:00']);
    assertSameValue(0, $first['error'], 'first session punch should succeed');
    assertSameValue(0, $second['error'], 'second session punch should succeed');
    assertSameValue(2, (int) scalar($db, 'SELECT COUNT(*) FROM attendance'), 'session-aware row count');
}, $results);

scenario('previous-month date is rejected when check_month is enabled', function () use ($db): void {
    resetFixture($db, 10, 0);
    $previousMonth = date('Y-m-d', strtotime('first day of last month')) . ' 09:00:00';
    $response = punch($db, ['datetime' => $previousMonth, 'check_month' => true]);
    assertSameValue(3, $response['error'], 'previous-month punch must be rejected');
    assertSameValue(0, (int) scalar($db, 'SELECT COUNT(*) FROM attendance'), 'previous-month row count');
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

fwrite(STDOUT, sprintf("%d scenarios, %d failed.\n", count($results), $failed));
exit($failed === 0 ? 0 : 1);
