<?php

declare(strict_types=1);

/**
 * FACE-ENR-01 enrollment lifecycle tests.
 *
 * Created before FaceEnrollmentService.php. The pre-change run must fail
 * because the service is absent. All fixtures are connection-scoped temporary
 * tables in the disposable test database.
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

function stopEnrollmentRun(string $message, bool $required): void
{
    $label = $required ? 'BLOCKED' : 'SKIPPED';
    fwrite($required ? STDERR : STDOUT, "{$label}: {$message}\n");
    exit($required ? 2 : 0);
}

function allowedEnrollmentDb(string $name, string $prefix): bool
{
    return (bool) preg_match('/^' . preg_quote($prefix, '/') . '(?:_[a-z0-9_]+)?$/', $name);
}

if (!allowedEnrollmentDb($dbName, 'ccc_database_test') || !allowedEnrollmentDb($centralDbName, 'ccc_central_test')) {
    fwrite(STDERR, "Refusing to run FACE-ENR-01 outside the approved test database namespaces.\n");
    exit(2);
}

if (!extension_loaded('mysqli')) {
    stopEnrollmentRun('the PHP mysqli extension is unavailable', $required);
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
        stopEnrollmentRun("unable to connect to the enrollment test databases: {$fallbackException->getMessage()}", $required);
    }
}

$servicePath = $root . '/classes/services/FaceEnrollmentService.php';
if (!is_file($servicePath)) {
    fwrite(STDERR, "FAIL FACE-ENR-01 enrollment contract: FaceEnrollmentService.php is missing (expected pre-change baseline).\n");
    exit(1);
}
require_once $servicePath;

function enrollmentSchema(mysqli $db): void
{
    $schema = <<<'SQL'
CREATE TEMPORARY TABLE IF NOT EXISTS `member` (
  `id` INT NOT NULL PRIMARY KEY,
  `contact_id` INT NOT NULL,
  `status` INT NOT NULL DEFAULT 1,
  `park` INT NOT NULL DEFAULT 0,
  `bid` INT NOT NULL DEFAULT 1
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
CREATE TEMPORARY TABLE IF NOT EXISTS `face_model_version` (
  `id` INT NOT NULL PRIMARY KEY,
  `model_name` VARCHAR(100) NOT NULL,
  `provider_id` VARCHAR(100) NOT NULL,
  `provider_version` VARCHAR(100) NOT NULL,
  `policy_version` VARCHAR(100) NOT NULL,
  `embedding_dimension` INT NOT NULL,
  `model_checksum` CHAR(64) NOT NULL,
  `status` VARCHAR(20) NOT NULL DEFAULT 'ACTIVE'
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
CREATE TEMPORARY TABLE IF NOT EXISTS `biometric_consent` (
  `id` INT NOT NULL AUTO_INCREMENT PRIMARY KEY,
  `member_id` INT NOT NULL,
  `guardian_contact_id` INT NULL,
  `notice_version` VARCHAR(64) NOT NULL,
  `purpose` VARCHAR(100) NOT NULL,
  `consent_status` VARCHAR(32) NOT NULL,
  `verification_method` VARCHAR(64) NOT NULL,
  `actor_employee_id` INT NOT NULL DEFAULT 0,
  `consented_at` DATETIME NULL,
  `withdrawn_at` DATETIME NULL,
  `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
CREATE TEMPORARY TABLE IF NOT EXISTS `face_template` (
  `id` INT NOT NULL AUTO_INCREMENT PRIMARY KEY,
  `member_id` INT NOT NULL,
  `model_version_id` INT NOT NULL,
  `encrypted_embedding` MEDIUMBLOB NOT NULL,
  `encryption_key_id` VARCHAR(100) NOT NULL,
  `embedding_dimension` INT NOT NULL,
  `quality_summary` JSON NULL,
  `status` VARCHAR(20) NOT NULL DEFAULT 'ACTIVE',
  `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `expires_at` DATETIME NULL,
  `revoked_at` DATETIME NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
SQL;

    foreach (explode(";\n", $schema) as $statement) {
        $statement = trim($statement);
        if ($statement !== '') {
            $db->query($statement);
        }
    }
}

function enrollmentReset(mysqli $db, int $memberStatus = 1): void
{
    foreach (['face_template', 'biometric_consent', 'face_model_version', 'member'] as $table) {
        $db->query("TRUNCATE TABLE `{$table}`");
    }
    $db->query("INSERT INTO member (id, contact_id, status, park, bid) VALUES (101, 1101, {$memberStatus}, 0, 7)");
    $db->query("INSERT INTO face_model_version (id, model_name, provider_id, provider_version, policy_version, embedding_dimension, model_checksum, status) VALUES (9, 'test-model', 'fake-provider', '1', 'test-policy-v1', 3, REPEAT('a', 64), 'ACTIVE')");
}

function enrollmentFrames(int $count = 7): array
{
    return array_map(static function (int $index): string {
        return 'raw-frame-' . $index;
    }, range(1, $count));
}

function enrollmentCommand(array $overrides = []): array
{
    return array_merge([
        'branch_id' => 7,
        'member_id' => 101,
        'actor_employee_id' => 9001,
        'notice_version' => 'program-face-v1',
        'purpose' => 'attendance',
        'teacher_confirmed_member' => true,
        'frames' => enrollmentFrames(),
    ], $overrides);
}

final class EnrollmentFakeInference implements FaceEnrollmentInferenceClient
{
    public $calls = 0;
    public $result;

    public function __construct(array $result)
    {
        $this->result = $result;
    }

    public function encodeEnrollment(array $frames, array $captureContexts = []): array
    {
        $this->calls++;
        return $this->result;
    }
}

function enrollmentScalar(mysqli $db, string $query)
{
    $row = $db->query($query)->fetch_row();
    return $row[0] ?? null;
}

function enrollmentAssert($expected, $actual, string $message): void
{
    if ($expected !== $actual) {
        throw new RuntimeException($message . ' (expected ' . var_export($expected, true) . ', got ' . var_export($actual, true) . ')');
    }
}

function enrollmentResult(array $overrides = []): array
{
    return array_merge([
        'model_version_id' => 9,
        'provider_id' => 'fake-provider',
        'provider_version' => '1',
        'calibration_policy_version' => 'test-policy-v1',
        'embeddings' => [[0.1, 0.2, 0.3], [0.2, 0.3, 0.4], [0.3, 0.4, 0.5]],
        'embedding_dimension' => 3,
        'quality_summary' => ['frames_accepted' => 7, 'face_count' => 1, 'sharpness_ok' => true, 'exposure_ok' => true, 'pose_ok' => true],
        'verification_passed' => true,
        'verification_reason' => 'SAME_DAY_MATCH',
    ], $overrides);
}

function enrollmentCase(string $name, callable $callback, array &$results): void
{
    try {
        $callback();
        $results[] = ['name' => $name, 'status' => 'PASS'];
    } catch (Throwable $exception) {
        $results[] = ['name' => $name, 'status' => 'FAIL', 'message' => $exception->getMessage()];
    }
}

enrollmentSchema($db);
$cipher = new FaceEmbeddingCipher(str_repeat('k', 32), 'test-key-v1');
$results = [];

enrollmentCase('FACE-ENR-01 activates three encrypted templates after verification', function () use ($db, $cipher): void {
    enrollmentReset($db);
    $inference = new EnrollmentFakeInference(enrollmentResult());
    $service = new FaceEnrollmentService($db, $inference, $cipher);
    $result = $service->enroll(enrollmentCommand());
    enrollmentAssert('ACTIVE', $result['state'], 'activation state');
    enrollmentAssert(1, $inference->calls, 'inference call count');
    enrollmentAssert(3, (int) enrollmentScalar($db, "SELECT COUNT(*) FROM face_template WHERE member_id = 101 AND status = 'ACTIVE'"), 'active template count');
    enrollmentAssert(1, (int) enrollmentScalar($db, "SELECT COUNT(*) FROM biometric_consent WHERE member_id = 101 AND consent_status = 'PROGRAM_AUTHORIZED'"), 'program authorization count');
    enrollmentAssert(0, (int) enrollmentScalar($db, "SELECT COUNT(*) FROM face_template WHERE encrypted_embedding LIKE '%raw-frame%'"), 'raw frame persistence');
}, $results);

enrollmentCase('FACE-ENR-01 rejects fewer than seven frames without writes', function () use ($db, $cipher): void {
    enrollmentReset($db);
    $inference = new EnrollmentFakeInference(enrollmentResult());
    $service = new FaceEnrollmentService($db, $inference, $cipher);
    $result = $service->enroll(enrollmentCommand(['frames' => enrollmentFrames(6)]));
    enrollmentAssert('REJECTED', $result['state'], 'short capture state');
    enrollmentAssert('FRAME_COUNT', $result['reason'], 'short capture reason');
    enrollmentAssert(0, $inference->calls, 'short capture inference calls');
    enrollmentAssert(0, (int) enrollmentScalar($db, 'SELECT COUNT(*) FROM face_template'), 'short capture template writes');
}, $results);

enrollmentCase('FACE-ENR-01 rejects oversized frames before inference', function () use ($db, $cipher): void {
    enrollmentReset($db);
    $inference = new EnrollmentFakeInference(enrollmentResult());
    $service = new FaceEnrollmentService($db, $inference, $cipher, null, 10);
    $result = $service->enroll(enrollmentCommand(['frames' => array_fill(0, 7, str_repeat('x', 11))]));
    enrollmentAssert('REJECTED', $result['state'], 'oversized capture state');
    enrollmentAssert('INVALID_FRAME', $result['reason'], 'oversized capture reason');
    enrollmentAssert(0, $inference->calls, 'oversized capture inference calls');
    enrollmentAssert(0, (int) enrollmentScalar($db, 'SELECT COUNT(*) FROM face_template'), 'oversized capture template writes');
}, $results);

enrollmentCase('FACE-ENR-01 rejects failed same-day verification without writes', function () use ($db, $cipher): void {
    enrollmentReset($db);
    $inference = new EnrollmentFakeInference(enrollmentResult(['verification_passed' => false, 'verification_reason' => 'VERIFY_FAILED']));
    $service = new FaceEnrollmentService($db, $inference, $cipher);
    $result = $service->enroll(enrollmentCommand());
    enrollmentAssert('REJECTED', $result['state'], 'verification state');
    enrollmentAssert('VERIFICATION_FAILED', $result['reason'], 'verification reason');
    enrollmentAssert(0, (int) enrollmentScalar($db, 'SELECT COUNT(*) FROM face_template'), 'verification template writes');
    enrollmentAssert(0, (int) enrollmentScalar($db, 'SELECT COUNT(*) FROM biometric_consent'), 'verification authorization writes');
}, $results);

enrollmentCase('FACE-ENR-05 rejects provider or calibration mismatch without writes', function () use ($db, $cipher): void {
    enrollmentReset($db);
    $inference = new EnrollmentFakeInference(enrollmentResult(['provider_id' => 'wrong-provider']));
    $service = new FaceEnrollmentService($db, $inference, $cipher);
    $result = $service->enroll(enrollmentCommand());
    enrollmentAssert('REJECTED', $result['state'], 'provider mismatch state');
    enrollmentAssert('PROVIDER_MODEL_MISMATCH', $result['reason'], 'provider mismatch reason');
    enrollmentAssert(0, (int) enrollmentScalar($db, 'SELECT COUNT(*) FROM face_template'), 'provider mismatch template writes');
    enrollmentAssert(0, (int) enrollmentScalar($db, 'SELECT COUNT(*) FROM biometric_consent'), 'provider mismatch consent writes');
}, $results);

enrollmentCase('FACE-ENR-01 permits explicitly gated uncalibrated Testing enrollment', function () use ($db, $cipher): void {
    enrollmentReset($db);
    $inference = new EnrollmentFakeInference(enrollmentResult([
        'verification_passed' => false,
        'verification_reason' => 'VERIFY_THRESHOLD_NOT_MET',
    ]));
    $service = new FaceEnrollmentService($db, $inference, $cipher);
    $result = $service->enroll(enrollmentCommand(['allow_uncalibrated_testing' => true]));
    enrollmentAssert('VALIDATION_ONLY', $result['state'], 'testing enrollment state');
    enrollmentAssert(true, !empty($result['testing_mode']), 'testing mode response marker');
    enrollmentAssert(3, (int) enrollmentScalar($db, "SELECT COUNT(*) FROM face_template WHERE member_id = 101 AND status = 'VALIDATION_ONLY'"), 'testing validation-only template count');
    enrollmentAssert(3, (int) enrollmentScalar($db, "SELECT COUNT(*) FROM face_template WHERE member_id = 101 AND quality_summary LIKE '%UNCALIBRATED_TEST%'"), 'testing mode template metadata');
}, $results);

enrollmentCase('FACE-ENR-01 prevents duplicate active enrollment', function () use ($db, $cipher): void {
    enrollmentReset($db);
    $inference = new EnrollmentFakeInference(enrollmentResult());
    $service = new FaceEnrollmentService($db, $inference, $cipher);
    $service->enroll(enrollmentCommand());
    $result = $service->enroll(enrollmentCommand());
    enrollmentAssert('ALREADY_ENROLLED', $result['state'], 'duplicate enrollment state');
    enrollmentAssert(1, $inference->calls, 'duplicate inference call count');
    enrollmentAssert(3, (int) enrollmentScalar($db, "SELECT COUNT(*) FROM face_template WHERE status = 'ACTIVE'"), 'duplicate active template count');
}, $results);

enrollmentCase('FACE-ENR-01 revocation disables active matching', function () use ($db, $cipher): void {
    enrollmentReset($db);
    $inference = new EnrollmentFakeInference(enrollmentResult());
    $service = new FaceEnrollmentService($db, $inference, $cipher);
    $service->enroll(enrollmentCommand());
    $wrongBranch = $service->withdraw(101, 9001, 8);
    enrollmentAssert('REJECTED', $wrongBranch['state'], 'wrong-branch revocation state');
    enrollmentAssert('NOT_PROGRAM_MEMBER', $wrongBranch['reason'], 'wrong-branch revocation reason');
    enrollmentAssert(3, (int) enrollmentScalar($db, "SELECT COUNT(*) FROM face_template WHERE status = 'ACTIVE'"), 'active templates after wrong-branch revocation');
    $result = $service->withdraw(101, 9001, 7);
    enrollmentAssert('REVOKED', $result['state'], 'revocation state');
    enrollmentAssert(0, (int) enrollmentScalar($db, "SELECT COUNT(*) FROM face_template WHERE status = 'ACTIVE'"), 'active templates after revocation');
    enrollmentAssert(3, (int) enrollmentScalar($db, "SELECT COUNT(*) FROM face_template WHERE status = 'REVOKED'"), 'revoked template count');
    enrollmentAssert(1, (int) enrollmentScalar($db, "SELECT COUNT(*) FROM biometric_consent WHERE consent_status = 'WITHDRAWN'"), 'withdrawn authorization count');
}, $results);

enrollmentCase('FACE-ENR-01 rejects inactive or unconfirmed child selection', function () use ($db, $cipher): void {
    enrollmentReset($db, 0);
    $inference = new EnrollmentFakeInference(enrollmentResult());
    $service = new FaceEnrollmentService($db, $inference, $cipher);
    $inactive = $service->enroll(enrollmentCommand());
    enrollmentAssert('REJECTED', $inactive['state'], 'inactive member state');
    enrollmentAssert('NOT_PROGRAM_MEMBER', $inactive['reason'], 'inactive member reason');
    enrollmentReset($db, 1);
    $unconfirmed = $service->enroll(enrollmentCommand(['teacher_confirmed_member' => false]));
    enrollmentAssert('REJECTED', $unconfirmed['state'], 'unconfirmed member state');
    enrollmentAssert('MEMBER_CONFIRMATION_REQUIRED', $unconfirmed['reason'], 'unconfirmed member reason');
    enrollmentAssert(0, $inference->calls, 'rejected selection inference calls');
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

fwrite(STDOUT, sprintf("%d FACE-ENR-01 scenarios, %d failed.\n", count($results), $failed));
exit($failed === 0 ? 0 : 1);
