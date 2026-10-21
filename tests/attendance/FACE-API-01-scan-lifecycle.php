<?php

declare(strict_types=1);

/**
 * FACE-API-01 scan lifecycle tests.
 *
 * Created before FaceAttendanceApiService.php. The pre-change run must fail
 * because the lifecycle service is absent. Fixtures use only temporary tables
 * in the disposable application test database and never write attendance.
 */

date_default_timezone_set('UTC');
putenv('FACE_TEACHER_SELECTION_ENABLED=1'); // disposable fixture exercises the activated branch

$root = dirname(__DIR__, 2);
$required = in_array('--required', $argv, true)
    || filter_var(getenv('ATTENDANCE_TEST_REQUIRED') ?: '0', FILTER_VALIDATE_BOOLEAN);
$dbName = getenv('ATTENDANCE_TEST_DB') ?: 'ccc_database_test';
$centralDbName = getenv('ATTENDANCE_TEST_CENTRAL_DB') ?: 'ccc_central_test';
$dbHost = getenv('ATTENDANCE_TEST_DB_HOST') ?: '127.0.0.1';
$dbUser = getenv('ATTENDANCE_TEST_DB_USER') ?: 'root';
$dbPassword = getenv('ATTENDANCE_TEST_DB_PASSWORD') ?: '';

function stopFaceApiRun(string $message, bool $required): void
{
    $label = $required ? 'BLOCKED' : 'SKIPPED';
    fwrite($required ? STDERR : STDOUT, "{$label}: {$message}\n");
    exit($required ? 2 : 0);
}

function allowedFaceApiDb(string $name, string $prefix): bool
{
    return (bool) preg_match('/^' . preg_quote($prefix, '/') . '(?:_[a-z0-9_]+)?$/', $name);
}

if (!allowedFaceApiDb($dbName, 'ccc_database_test') || !allowedFaceApiDb($centralDbName, 'ccc_central_test')) {
    fwrite(STDERR, "Refusing to run FACE-API-01 outside the approved test database namespaces.\n");
    exit(2);
}

if (!extension_loaded('mysqli')) {
    stopFaceApiRun('the PHP mysqli extension is unavailable', $required);
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
        stopFaceApiRun("unable to connect to the scan lifecycle test databases: {$fallbackException->getMessage()}", $required);
    }
}

$servicePath = $root . '/classes/services/FaceAttendanceApiService.php';
if (!is_file($servicePath)) {
    fwrite(STDERR, "FAIL FACE-API-01 scan lifecycle contract: FaceAttendanceApiService.php is missing (expected pre-change baseline).\n");
    exit(1);
}
require_once $servicePath;

function faceApiSchema(mysqli $db): void
{
$schema = <<<'SQL'
CREATE TEMPORARY TABLE IF NOT EXISTS `face_selection_control` (
  `id` TINYINT UNSIGNED NOT NULL PRIMARY KEY,
  `enabled` TINYINT(1) NOT NULL DEFAULT 0
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
CREATE TEMPORARY TABLE IF NOT EXISTS `batch` (
  `id` INT NOT NULL PRIMARY KEY,
  `bid` INT NOT NULL,
  `batch` VARCHAR(100) NOT NULL,
  `venue_id` INT NOT NULL DEFAULT 1,
  `completed_sessions` INT NOT NULL DEFAULT 0,
  `parent_id` INT NOT NULL DEFAULT 0,
  `park` INT NOT NULL DEFAULT 0,
  `inactive` INT NOT NULL DEFAULT 0,
  `start_date` DATE NOT NULL,
  `end_date` DATE NOT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
CREATE TEMPORARY TABLE IF NOT EXISTS `batch_time` (
  `id` INT NOT NULL AUTO_INCREMENT PRIMARY KEY,
  `batch_id` INT NOT NULL,
  `day_code` VARCHAR(10) NOT NULL,
  `start_time` TIME NOT NULL,
  `end_time` TIME NOT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
CREATE TEMPORARY TABLE IF NOT EXISTS `member` (
  `id` INT NOT NULL PRIMARY KEY,
  `contact_id` INT NOT NULL,
  `status` INT NOT NULL DEFAULT 1,
  `park` INT NOT NULL DEFAULT 0,
  `bid` INT NOT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
CREATE TEMPORARY TABLE IF NOT EXISTS `contact` (
  `id` INT NOT NULL PRIMARY KEY,
  `fname` VARCHAR(30) NOT NULL,
  `mname` VARCHAR(50) NOT NULL DEFAULT '',
  `lname` VARCHAR(30) NOT NULL,
  `park` INT NOT NULL DEFAULT 0
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
CREATE TEMPORARY TABLE IF NOT EXISTS `invoice_invoiceitem_module_view` (
  `item_id` INT NOT NULL PRIMARY KEY,
  `contact_id` INT NOT NULL,
  `batch_id` INT NOT NULL,
  `session_id` INT NOT NULL DEFAULT 4,
  `start_date` DATE NOT NULL,
  `end_date` DATE NOT NULL,
  `sessions` INT NOT NULL,
  `sessions_used` INT NOT NULL DEFAULT 0,
  `park` INT NOT NULL DEFAULT 0,
  `item_park` INT NOT NULL DEFAULT 0
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
CREATE TEMPORARY TABLE IF NOT EXISTS `invoice_invoiceitem_view` (
  `item_id` INT NOT NULL PRIMARY KEY,
  `contact_id` INT NOT NULL,
  `batch_id` INT NOT NULL,
  `session_id` INT NOT NULL DEFAULT 4,
  `start_date` DATE NOT NULL,
  `end_date` DATE NOT NULL,
  `sessions` INT NOT NULL,
  `sessions_used` INT NOT NULL DEFAULT 0,
  `park` INT NOT NULL DEFAULT 0,
  `item_park` INT NOT NULL DEFAULT 0
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
CREATE TEMPORARY TABLE IF NOT EXISTS `biometric_consent` (
  `id` INT NOT NULL AUTO_INCREMENT PRIMARY KEY,
  `member_id` INT NOT NULL,
  `consent_status` VARCHAR(32) NOT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
CREATE TEMPORARY TABLE IF NOT EXISTS `face_template` (
  `id` INT NOT NULL AUTO_INCREMENT PRIMARY KEY,
  `member_id` INT NOT NULL,
  `encrypted_embedding` MEDIUMBLOB NOT NULL,
  `status` VARCHAR(20) NOT NULL,
  `model_version_id` INT NOT NULL DEFAULT 9,
  `embedding_dimension` INT NOT NULL DEFAULT 3,
  `preprocessing_version` VARCHAR(100) NOT NULL DEFAULT 'test-preproc'
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
CREATE TEMPORARY TABLE IF NOT EXISTS `face_model_version` (
  `id` INT NOT NULL PRIMARY KEY,
  `model_name` VARCHAR(100) NOT NULL,
  `provider_id` VARCHAR(100) NOT NULL,
  `provider_version` VARCHAR(100) NOT NULL,
  `policy_version` VARCHAR(100) NOT NULL,
  `embedding_dimension` INT NOT NULL,
  `preprocessing_version` VARCHAR(100) NOT NULL DEFAULT 'test-preproc',
  `status` VARCHAR(20) NOT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
CREATE TEMPORARY TABLE IF NOT EXISTS `attendance_scan` (
  `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
  `scan_token` CHAR(64) NOT NULL,
  `branch_id` INT NOT NULL,
  `batch_id` INT NOT NULL,
  `attendance_date` DATE NOT NULL,
  `session_id` INT NOT NULL,
  `event_type` VARCHAR(32) NOT NULL,
  `actor_employee_id` INT NOT NULL,
  `roster_version` CHAR(64) NOT NULL,
  `provider_id` VARCHAR(100) NOT NULL,
  `provider_version` VARCHAR(100) NOT NULL,
  `model_version_id` INT NULL,
  `calibration_policy_version` VARCHAR(100) NOT NULL,
  `recognition_generation` CHAR(64) NULL,
  `browser_session_hash` CHAR(64) NULL,
  `capture_nonce_hash` CHAR(64) NULL,
  `capture_nonce_expires_at` DATETIME NULL,
  `capture_nonce_used_at` DATETIME NULL,
  `status` VARCHAR(20) NOT NULL DEFAULT 'ACTIVE',
  `expires_at` DATETIME NOT NULL,
  `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `completed_at` DATETIME NULL,
  `cancelled_at` DATETIME NULL,
  UNIQUE KEY `uq_face_api_scan_token` (`scan_token`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
CREATE TEMPORARY TABLE IF NOT EXISTS `attendance_scan_roster` (
  `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
  `scan_id` BIGINT UNSIGNED NOT NULL,
  `member_id` INT NOT NULL,
  `candidate_ref` VARCHAR(128) NOT NULL,
  `eligibility_status` VARCHAR(20) NOT NULL,
  `consent_status` VARCHAR(32) NOT NULL,
  `template_count` INT NOT NULL DEFAULT 0,
  `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  UNIQUE KEY `uq_face_api_roster_member` (`scan_id`, `member_id`),
  UNIQUE KEY `uq_face_api_roster_candidate` (`scan_id`, `candidate_ref`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
CREATE TEMPORARY TABLE IF NOT EXISTS `recognition_attempt` (
  `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
  `scan_id` BIGINT UNSIGNED NOT NULL,
  `client_attempt_id` VARCHAR(191) NOT NULL,
  `candidate_ref` VARCHAR(128) NULL,
  `decision` VARCHAR(10) NOT NULL,
  `outcome` VARCHAR(32) NULL,
  `top1_score` DECIMAL(10,7) NULL,
  `top2_score` DECIMAL(10,7) NULL,
  `margin` DECIMAL(10,7) NULL,
  `quality_summary` JSON NULL,
  `liveness` VARCHAR(12) NOT NULL,
  `model_version_id` INT NULL,
  `latency_ms` INT NULL,
  `recognition_generation` CHAR(64) NULL,
  `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  UNIQUE KEY `uq_face_api_attempt_client` (`client_attempt_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
CREATE TEMPORARY TABLE IF NOT EXISTS `attendance` (
  `id` INT NOT NULL AUTO_INCREMENT PRIMARY KEY,
  `date` DATE NOT NULL,
  `in_time` TIME NOT NULL DEFAULT '00:00:00',
  `out_time` TIME NOT NULL DEFAULT '00:00:00',
  `contact_id` INT NOT NULL,
  `batch_id` INT NOT NULL,
  `venue_id` INT NOT NULL DEFAULT 0,
  `code` VARCHAR(100) NOT NULL DEFAULT '',
  `session_id` INT NOT NULL,
  `negative` INT NOT NULL DEFAULT 0,
  `session` INT NOT NULL DEFAULT 1,
  `user_id` INT NOT NULL DEFAULT 0,
  `bid` INT NOT NULL DEFAULT 0,
  `source` INT NOT NULL DEFAULT 0,
  `final_source` VARCHAR(40) NOT NULL DEFAULT '',
  `type` CHAR(1) NOT NULL,
  `status` INT NOT NULL DEFAULT 1,
  `attendance_count` INT NOT NULL DEFAULT 0,
  `class_no` INT NOT NULL DEFAULT 0,
  `batch` VARCHAR(100) NOT NULL DEFAULT '',
  `comment` VARCHAR(255) NOT NULL DEFAULT '',
  `row_color` INT NOT NULL DEFAULT 0,
  `created_by` INT NOT NULL DEFAULT 0,
  `modified_by` INT NOT NULL DEFAULT 0,
  `sessions_completed` INT NOT NULL DEFAULT 0,
  `park` INT NOT NULL DEFAULT 0
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
CREATE TEMPORARY TABLE IF NOT EXISTS `attendance_event` (
  `id` INT NOT NULL AUTO_INCREMENT PRIMARY KEY,
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
  `scan_id` INT NULL,
  `client_attempt_id` VARCHAR(191) NOT NULL,
  `occurred_at_server` DATETIME NOT NULL,
  `occurred_at_device` DATETIME NULL,
  `metadata` JSON NULL,
  UNIQUE KEY `uq_attendance_event_once` (`batch_id`, `session_id`, `attendance_date`, `person_type`, `person_id`, `event_type`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
CREATE TEMPORARY TABLE IF NOT EXISTS `invoice_item` (
  `id` INT NOT NULL PRIMARY KEY,
  `sessions` INT NOT NULL,
  `sessions_used` INT NOT NULL DEFAULT 0,
  `modified_by` INT NOT NULL DEFAULT 0
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
SQL;

    foreach (explode(";\n", $schema) as $statement) {
        $statement = trim($statement);
        if ($statement !== '') {
            $db->query($statement);
        }
    }
}

function faceApiReset(mysqli $db): void
{
    foreach (['recognition_attempt', 'attendance_scan_roster', 'attendance_scan', 'attendance_event', 'attendance', 'invoice_item', 'face_template', 'face_model_version', 'biometric_consent', 'invoice_invoiceitem_module_view', 'invoice_invoiceitem_view', 'member', 'contact', 'batch_time', 'batch'] as $table) {
        $db->query("TRUNCATE TABLE `{$table}`");
    }
    $today = date('Y-m-d');
    $day = (int)date('w', strtotime($today));
    $db->query("INSERT INTO face_selection_control (id, enabled) VALUES (1, 1) ON DUPLICATE KEY UPDATE enabled = 1");
    $db->query("INSERT INTO batch (id, bid, batch, start_date, end_date) VALUES (11, 7, 'Test Batch', '2020-01-01', '2030-12-31')");
    $db->query("INSERT INTO batch_time (batch_id, day_code, start_time, end_time) VALUES (11, '{$day}', '09:00:00', '11:00:00')");
    $db->query("INSERT INTO contact (id, fname, mname, lname, park) VALUES (1101, 'Mapped', '', 'Child', 0), (1102, 'Manual', '', 'Child', 0)");
    $db->query("INSERT INTO member (id, contact_id, status, park, bid) VALUES (101, 1101, 1, 0, 7), (102, 1102, 1, 0, 7)");
    $db->query("INSERT INTO invoice_invoiceitem_module_view (item_id, contact_id, batch_id, session_id, start_date, end_date, sessions, sessions_used) VALUES (201, 1101, 11, 4, '2020-01-01', '2030-12-31', 10, 2), (202, 1102, 11, 4, '2020-01-01', '2030-12-31', 10, 2)");
    $db->query("INSERT INTO invoice_invoiceitem_view (item_id, contact_id, batch_id, session_id, start_date, end_date, sessions, sessions_used) VALUES (201, 1101, 11, 4, '2020-01-01', '2030-12-31', 10, 2), (202, 1102, 11, 4, '2020-01-01', '2030-12-31', 10, 2)");
    $db->query("INSERT INTO invoice_item (id, sessions, sessions_used) VALUES (201, 10, 2), (202, 10, 2)");
    $db->query("INSERT INTO face_model_version (id, model_name, provider_id, provider_version, policy_version, embedding_dimension, status) VALUES (9, 'test-model', 'fake-provider', '1', 'test-policy-v1', 3, 'ACTIVE')");
}

function faceApiInsertMappedTemplate(mysqli $db, FaceEmbeddingCipher $cipher): void
{
    $encrypted = $db->real_escape_string($cipher->encrypt([0.1, 0.2, 0.3]));
    $db->query("INSERT INTO face_template (member_id, encrypted_embedding, status) VALUES (101, '{$encrypted}', 'ACTIVE')");
}

final class FaceApiFakeInference implements FaceRecognitionProvider
{
	public function checkReadiness(): bool { return true; }
    public $startCalls = 0;
    public $attemptCalls = 0;
    public $deleteCalls = 0;
    public $attemptResult;
    public $onAttempt;

    public function providerMetadata(): array
    {
        return [
            'provider_id' => 'fake-provider',
            'provider_version' => '1',
            'model_version_id' => 9,
            'model_version' => 'test-model',
            'calibration_policy_version' => 'test-policy-v1',
            'recognition_generation' => str_repeat('a', 64),
        ];
    }

    public function initializeScan(string $scanId, array $candidates, int $ttl): array
    {
        $this->startCalls++;
        return ['scan_id' => $scanId, 'candidate_count' => count($candidates), 'expires_in' => $ttl];
    }

    public function recognizeAttempt(string $scanId, array $frames, string $challengeToken = '', array $captureContexts = []): array
    {
        $this->attemptCalls++;
        if (is_callable($this->onAttempt)) ($this->onAttempt)();
        return $this->attemptResult ?: [
            'decision' => 'ACCEPT',
            'candidate_ref' => 'candidate-mapped',
            'top1_score' => 0.95,
            'top2_score' => 0.20,
            'margin' => 0.75,
            'quality' => ['face_count' => 1, 'sharpness_ok' => true, 'exposure_ok' => true, 'pose_ok' => true],
            'liveness' => 'LIVE',
            'model_version' => 'test-model',
            'latency_ms' => 10,
        ];
    }

    public function deleteScan(string $scanId): void
    {
        $this->deleteCalls++;
    }
}

final class FaceApiFakeAttendanceDomain
{
    public $calls = 0;
    public $command;
	public $seen = [];

    public function recordMemberAttendance(array $command): array
    {
        $this->calls++;
        $this->command = $command;
		$key = (string)$command['client_attempt_id'];
		if (isset($this->seen[$key])) {
			return [
				'state' => 'ALREADY_MARKED',
				'attendance_id' => 7001,
				'event_id' => 8001,
			];
		}
		$this->seen[$key] = true;
        return [
            'state' => 'RECORDED',
            'attendance_id' => 7001,
            'event_id' => 8001,
        ];
    }
}

function faceApiScalar(mysqli $db, string $query)
{
    $row = $db->query($query)->fetch_row();
    return $row[0] ?? null;
}

function faceApiAssert($expected, $actual, string $message): void
{
    if ($expected !== $actual) {
        throw new RuntimeException($message . ' (expected ' . var_export($expected, true) . ', got ' . var_export($actual, true) . ')');
    }
}

function faceApiRequest(array $overrides = []): array
{
    return array_merge([
        'branch_id' => 7,
        'batch_id' => 11,
        'attendance_date' => date('Y-m-d'),
        'session_id' => 4,
        'selected_time' => '10:00:00',
        'event_type' => 'PUNCH_IN',
        'actor_employee_id' => 9001,
        'check_month' => false,
    ], $overrides);
}

function faceApiCase(string $name, callable $callback, array &$results): void
{
    try {
        $callback();
        $results[] = ['name' => $name, 'status' => 'PASS'];
    } catch (Throwable $exception) {
        $results[] = ['name' => $name, 'status' => 'FAIL', 'message' => $exception->getMessage()];
    }
}

faceApiSchema($db);
$cipher = new FaceEmbeddingCipher(str_repeat('k', 32), 'test-key-v1');
$results = [];

faceApiCase('FACE-API-01 starts a selected-session roster with manual fallback members', function () use ($db, $cipher): void {
    faceApiReset($db);
    $db->query("INSERT INTO biometric_consent (member_id, consent_status) VALUES (101, 'PROGRAM_AUTHORIZED')");
    faceApiInsertMappedTemplate($db, $cipher);
    $fake = new FaceApiFakeInference();
    $service = new FaceAttendanceApiService($db, $fake, $cipher, new FaceAttendanceFeatureConfig(true, true, false, false));
    $result = $service->startFaceScan(faceApiRequest());
    faceApiAssert('STARTED', $result['state'], 'start state');
    faceApiAssert(2, $result['roster_count'], 'frozen roster count');
    faceApiAssert(1, $result['automatic_candidate_count'], 'automatic candidate count');
    faceApiAssert(1, $fake->startCalls, 'inference roster call count');
    faceApiAssert(0, (int)faceApiScalar($db, 'SELECT COUNT(*) FROM attendance'), 'start attendance writes');
}, $results);

faceApiCase('FACE-API-09 excludes templates with an incompatible declared dimension', function () use ($db, $cipher): void {
    faceApiReset($db);
    $db->query("INSERT INTO biometric_consent (member_id, consent_status) VALUES (101, 'PROGRAM_AUTHORIZED')");
    $encrypted = $db->real_escape_string($cipher->encrypt([0.1, 0.2, 0.3, 0.4]));
    $db->query("INSERT INTO face_template (member_id, encrypted_embedding, status, model_version_id, embedding_dimension) VALUES (101, '{$encrypted}', 'ACTIVE', 9, 4)");
    $fake = new FaceApiFakeInference();
    $service = new FaceAttendanceApiService($db, $fake, $cipher, new FaceAttendanceFeatureConfig(true, true, false, false));
    $result = $service->startFaceScan(faceApiRequest());
    faceApiAssert('REJECTED', $result['state'], 'dimension mismatch state');
    faceApiAssert('SYSTEM_NOT_READY', $result['reason'], 'dimension mismatch reason');
    faceApiAssert('QUARANTINED', faceApiScalar($db, 'SELECT status FROM face_template WHERE member_id = 101 LIMIT 1'), 'invalid template quarantine');
    faceApiAssert(0, $fake->startCalls, 'dimension mismatch inference calls');
    faceApiAssert(0, (int)faceApiScalar($db, 'SELECT COUNT(*) FROM attendance'), 'dimension mismatch attendance writes');
}, $results);

faceApiCase('FACE-API-12 consumes a session-bound capture nonce only once', function () use ($db, $cipher): void {
    faceApiReset($db);
    $db->query("INSERT INTO biometric_consent (member_id, consent_status) VALUES (101, 'PROGRAM_AUTHORIZED')");
    faceApiInsertMappedTemplate($db, $cipher);
    $binding = str_repeat('b', 64);
    $fake = new FaceApiFakeInference();
    $service = new FaceAttendanceApiService($db, $fake, $cipher, new FaceAttendanceFeatureConfig(true, true, false, false));
    putenv('FACE_CAPTURE_NONCE_REQUIRED=1');
    try {
        $started = $service->startFaceScan(faceApiRequest(['browser_session_binding' => $binding]));
        faceApiAssert('STARTED', $started['state'], 'nonce scan starts');
        $fresh = $service->issueCaptureNonce($started['scan_id'], $started['scan_token'], 9001, $binding);
        faceApiAssert('READY', $fresh['state'], 'fresh nonce issued before capture');
        $wrongBrowser = $service->issueCaptureNonce($started['scan_id'], $started['scan_token'], 9001, str_repeat('c', 64));
        faceApiAssert('SYSTEM_NOT_READY', $wrongBrowser['outcome'], 'different browser cannot rotate nonce');
        $contexts = [['capture_id' => 'first'], ['capture_id' => 'second'], ['capture_id' => 'third']];
        $frames = ['frame-a', 'frame-b', 'frame-c'];
        $first = $service->recognizeFaceAttempt($started['scan_id'], $started['scan_token'], 9001, 'nonce-attempt-a', $frames, '', $contexts, $fresh['capture_nonce'], $binding);
        faceApiAssert('RECORDED', $first['state'], 'first nonce accepted');
        faceApiAssert(true, !empty($first['next_capture_nonce']), 'next nonce issued');
        $replay = $service->recognizeFaceAttempt($started['scan_id'], $started['scan_token'], 9001, 'nonce-attempt-b', $frames, '', $contexts, $fresh['capture_nonce'], $binding);
        faceApiAssert('REJECT_REPLAY', $replay['outcome'], 'consumed nonce replay rejected');
        $duplicateCaptureIds = [['capture_id' => 'same'], ['capture_id' => 'same'], ['capture_id' => 'third']];
        $repeatedCapture = $service->recognizeFaceAttempt($started['scan_id'], $started['scan_token'], 9001, 'nonce-attempt-c', $frames, '', $duplicateCaptureIds, $first['next_capture_nonce'], $binding);
        faceApiAssert('REJECT_REPLAY', $repeatedCapture['outcome'], 'duplicate capture IDs rejected');
        faceApiAssert(1, $fake->attemptCalls, 'replay never reaches inference');
        faceApiAssert(1, (int)faceApiScalar($db, 'SELECT COUNT(*) FROM recognition_attempt'), 'replay never records another attempt');
    } finally {
        putenv('FACE_CAPTURE_NONCE_REQUIRED=0');
    }
}, $results);

faceApiCase('FACE-API-06 supports multiple manual fallback members with unique roster refs', function () use ($db, $cipher): void {
    faceApiReset($db);
    $db->query("INSERT INTO contact (id, fname, mname, lname, park) VALUES (1103, 'Second', '', 'Manual', 0)");
    $db->query("INSERT INTO member (id, contact_id, status, park, bid) VALUES (103, 1103, 1, 0, 7)");
    $db->query("INSERT INTO invoice_invoiceitem_module_view (item_id, contact_id, batch_id, session_id, start_date, end_date, sessions, sessions_used) VALUES (203, 1103, 11, 4, '2020-01-01', '2030-12-31', 10, 2)");
    $db->query("INSERT INTO biometric_consent (member_id, consent_status) VALUES (101, 'PROGRAM_AUTHORIZED')");
    faceApiInsertMappedTemplate($db, $cipher);
    $fake = new FaceApiFakeInference();
    $service = new FaceAttendanceApiService($db, $fake, $cipher, new FaceAttendanceFeatureConfig(true, true, false, false));
    $result = $service->startFaceScan(faceApiRequest());
    faceApiAssert('STARTED', $result['state'], 'multi-manual start state; reason=' . ($result['reason'] ?? '') . '; message=' . ($result['message'] ?? ''));
    faceApiAssert(3, $result['roster_count'], 'multi-manual roster count');
    faceApiAssert(1, $result['automatic_candidate_count'], 'multi-manual automatic candidate count');
    faceApiAssert(3, (int)faceApiScalar($db, 'SELECT COUNT(DISTINCT candidate_ref) FROM attendance_scan_roster WHERE scan_id = ' . (int)$result['scan_id']), 'unique frozen roster refs');
}, $results);

faceApiCase('FACE-API-05 accepts a valid non-session batch with session_id zero', function () use ($db, $cipher): void {
    faceApiReset($db);
    $db->query("INSERT INTO biometric_consent (member_id, consent_status) VALUES (101, 'PROGRAM_AUTHORIZED')");
    faceApiInsertMappedTemplate($db, $cipher);
    $fake = new FaceApiFakeInference();
    $service = new FaceAttendanceApiService($db, $fake, $cipher, new FaceAttendanceFeatureConfig(true, true, false, false));
    $result = $service->startFaceScan(faceApiRequest(['session_id' => 0, 'selected_time' => '10:00:00']));
    faceApiAssert('STARTED', $result['state'], 'non-session start state; reason=' . ($result['reason'] ?? '') . '; message=' . ($result['message'] ?? ''));
    faceApiAssert(0, (int)faceApiScalar($db, 'SELECT session_id FROM attendance_scan WHERE id = ' . (int)$result['scan_id']), 'non-session scan session');
    faceApiAssert(1, $result['automatic_candidate_count'], 'non-session automatic candidate count');
}, $results);

faceApiCase('FACE-API-01 accepts an in-roster attempt in shadow mode only', function () use ($db, $cipher): void {
    faceApiReset($db);
    $db->query("INSERT INTO biometric_consent (member_id, consent_status) VALUES (101, 'PROGRAM_AUTHORIZED')");
    faceApiInsertMappedTemplate($db, $cipher);
    $fake = new FaceApiFakeInference();
    $service = new FaceAttendanceApiService($db, $fake, $cipher, new FaceAttendanceFeatureConfig(true, true, false, false));
    $started = $service->startFaceScan(faceApiRequest());
    $candidateRef = (string)faceApiScalar($db, 'SELECT candidate_ref FROM attendance_scan_roster WHERE scan_id = ' . (int)$started['scan_id'] . ' AND member_id = 101');
    $fake->attemptResult = ['decision' => 'ACCEPT', 'candidate_ref' => $candidateRef, 'top1_score' => 0.95, 'top2_score' => 0.20, 'margin' => 0.75, 'quality' => ['face_count' => 1], 'liveness' => 'LIVE', 'model_version' => 'test-model', 'latency_ms' => 10];
    $result = $service->recognizeFaceAttempt($started['scan_id'], $started['scan_token'], 9001, 'attempt-1', ['frame-1', 'frame-2', 'frame-3']);
    faceApiAssert('ACCEPT', $result['decision'], 'attempt decision');
    faceApiAssert(101, $result['member_id'], 'matched roster member');
    faceApiAssert('Mapped Child', $result['member_name'], 'matched member name from contact');
    faceApiAssert(1, (int)faceApiScalar($db, 'SELECT COUNT(*) FROM recognition_attempt'), 'attempt persistence');
    faceApiAssert(0, (int)faceApiScalar($db, 'SELECT COUNT(*) FROM attendance'), 'shadow attendance writes');
}, $results);

faceApiCase('FACE-API-09 does not select a roster row while PAD is unresolved', function () use ($db, $cipher): void {
    faceApiReset($db);
    $db->query("INSERT INTO biometric_consent (member_id, consent_status) VALUES (101, 'PROGRAM_AUTHORIZED')");
    faceApiInsertMappedTemplate($db, $cipher);
    $fake = new FaceApiFakeInference();
    $service = new FaceAttendanceApiService($db, $fake, $cipher, new FaceAttendanceFeatureConfig(true, true, false, false));
    $started = $service->startFaceScan(faceApiRequest());
    $candidateRef = (string)faceApiScalar($db, 'SELECT candidate_ref FROM attendance_scan_roster WHERE scan_id = ' . (int)$started['scan_id'] . ' AND member_id = 101');
    $fake->attemptResult = ['identity_decision' => 'ACCEPT', 'decision' => 'REVIEW', 'candidate_ref' => $candidateRef, 'top1_score' => 0.95, 'top2_score' => 0.20, 'margin' => 0.75, 'quality' => ['face_count' => 1], 'liveness' => 'UNKNOWN', 'model_version' => 'test-model', 'latency_ms' => 10];
    $result = $service->recognizeFaceAttempt($started['scan_id'], $started['scan_token'], 9001, 'attempt-deferred-pad', ['frame-1', 'frame-1-second', 'frame-1-third']);
    faceApiAssert('REVIEW', $result['decision'], 'unresolved PAD decision');
    faceApiAssert('NONE', $result['effect'], 'unresolved PAD row effect');
    faceApiAssert('UNKNOWN', $result['pad_status'], 'PAD status');
    faceApiAssert('PENDING_TEACHER_COMMIT', $result['attendance_state'], 'teacher commit boundary');
    faceApiAssert(9, (int)faceApiScalar($db, 'SELECT model_version_id FROM recognition_attempt WHERE client_attempt_id = \'attempt-deferred-pad\''), 'attempt model binding');
    $repeat = $service->recognizeFaceAttempt($started['scan_id'], $started['scan_token'], 9001, 'attempt-deferred-pad-repeat', ['frame-2', 'frame-2-second', 'frame-2-third']);
    faceApiAssert('REVIEW', $repeat['decision'], 'repeat identity decision');
    faceApiAssert('NONE', $repeat['effect'], 'repeat row effect');
    faceApiAssert(false, $repeat['member_already_selected'], 'repeat selected-member marker');
    $status = $service->getFaceScanStatus($started['scan_id'], $started['scan_token'], 9001);
    faceApiAssert('ACTIVE', $status['state'], 'resume status');
    faceApiAssert(2, $status['attempt_count'], 'resume attempt count');
    faceApiAssert(0, count($status['selected']), 'resume selected member count');
    faceApiAssert(false, $status['live_write'], 'resume live-write state');
    $db->query("UPDATE invoice_invoiceitem_module_view SET item_park = 1 WHERE contact_id = 1101 AND batch_id = 11");
    $changedStatus = $service->getFaceScanStatus($started['scan_id'], $started['scan_token'], 9001);
    faceApiAssert(0, count($changedStatus['selected']), 'resume excludes membership that became ineligible');
    $ineligibleReplay = $service->recognizeFaceAttempt($started['scan_id'], $started['scan_token'], 9001, 'attempt-deferred-pad', ['frame-1', 'frame-1-second', 'frame-1-third']);
    faceApiAssert('REVIEW', $ineligibleReplay['decision'], 'ineligible idempotent replay decision');
    faceApiAssert('NONE', $ineligibleReplay['effect'], 'ineligible idempotent replay effect');
    $completed = $service->completeFaceScan($started['scan_id'], $started['scan_token'], 9001);
    faceApiAssert(0, count($completed['matched']), 'completion excludes membership that became ineligible');
    faceApiAssert(0, count($completed['review']), 'unresolved PAD never binds review to an identity');
    faceApiAssert(0, (int)faceApiScalar($db, 'SELECT COUNT(*) FROM attendance'), 'deferred PAD attendance writes');
}, $results);

faceApiCase('FACE-API-09 commits an accepted live punch-in once', function () use ($db, $cipher): void {
    faceApiReset($db);
    $db->query("INSERT INTO biometric_consent (member_id, consent_status) VALUES (101, 'PROGRAM_AUTHORIZED')");
    faceApiInsertMappedTemplate($db, $cipher);
    $fakeInference = new FaceApiFakeInference();
    $attendance = new FaceApiFakeAttendanceDomain();
    $service = new FaceAttendanceApiService($db, $fakeInference, $cipher, new FaceAttendanceFeatureConfig(true, false, true, false), 300, 1000000, $attendance);
    $started = $service->startFaceScan(faceApiRequest());
    $candidateRef = (string)faceApiScalar($db, 'SELECT candidate_ref FROM attendance_scan_roster WHERE scan_id = ' . (int)$started['scan_id'] . ' AND member_id = 101');
    $fakeInference->attemptResult = ['decision' => 'ACCEPT', 'candidate_ref' => $candidateRef, 'top1_score' => 0.95, 'top2_score' => 0.20, 'margin' => 0.75, 'quality' => ['face_count' => 1], 'liveness' => 'LIVE', 'model_version' => 'test-model', 'latency_ms' => 10, 'comparison_scores' => [['candidate_ref' => $candidateRef, 'score' => 0.95]]];
    $result = $service->recognizeFaceAttempt($started['scan_id'], $started['scan_token'], 9001, 'attempt-live-1', ['frame-1', 'frame-1-second', 'frame-1-third']);
    faceApiAssert('ACCEPT', $result['decision'], 'live attempt decision');
    faceApiAssert(false, array_key_exists('comparison_scores', $result), 'private gallery scores stay off teacher response');
    faceApiAssert('RECORDED', $result['attendance_state'], 'automatic attendance state');
    faceApiAssert('ATTENDANCE_RECORDED', $result['effect'], 'automatic attendance effect');
    faceApiAssert(7001, $result['attendance_id'], 'automatic attendance identifier');
    faceApiAssert(true, $result['live_write'], 'live write response flag');
    faceApiAssert('AUTO_PRESENT', $result['commit_mode'], 'automatic commit mode');
    faceApiAssert(1, $attendance->calls, 'attendance domain call count');
    faceApiAssert(1101, $attendance->command['member_id'], 'attendance writes the established contact identifier');
    faceApiAssert('FACE_MATCH', $attendance->command['source'], 'attendance write source');
    faceApiAssert((int)$started['scan_id'], $attendance->command['scan_id'], 'attendance write scan linkage');
    faceApiAssert(0, (int)faceApiScalar($db, 'SELECT COUNT(*) FROM attendance'), 'supervised attendance writes');

    $replay = $service->recognizeFaceAttempt($started['scan_id'], $started['scan_token'], 9001, 'attempt-live-1', ['frame-1', 'frame-1-second', 'frame-1-third']);
    faceApiAssert('ALREADY_PRESENT', $replay['attendance_state'], 'idempotent automatic attendance state');
    faceApiAssert('ALREADY_PRESENT', $replay['effect'], 'idempotent automatic attendance effect');
    faceApiAssert(2, $attendance->calls, 'idempotent attendance domain call count');
}, $results);

faceApiCase('FACE-API-09 records live attendance, event linkage, and one session deduction', function () use ($db, $cipher): void {
    faceApiReset($db);
    $db->query("INSERT INTO biometric_consent (member_id, consent_status) VALUES (101, 'PROGRAM_AUTHORIZED')");
    faceApiInsertMappedTemplate($db, $cipher);
    $fake = new FaceApiFakeInference();
    $service = new FaceAttendanceApiService($db, $fake, $cipher, new FaceAttendanceFeatureConfig(true, false, true, false));
    $started = $service->startFaceScan(faceApiRequest());
    $candidateRef = (string)faceApiScalar($db, 'SELECT candidate_ref FROM attendance_scan_roster WHERE scan_id = ' . (int)$started['scan_id'] . ' AND member_id = 101');
    $fake->attemptResult = ['decision' => 'ACCEPT', 'candidate_ref' => $candidateRef, 'top1_score' => 0.95, 'top2_score' => 0.20, 'margin' => 0.75, 'quality' => ['face_count' => 1], 'liveness' => 'LIVE', 'model_version' => 'test-model', 'latency_ms' => 10];
    $result = $service->recognizeFaceAttempt($started['scan_id'], $started['scan_token'], 9001, 'attempt-live-domain', ['frame-1', 'frame-1-second', 'frame-1-third']);
    faceApiAssert('RECORDED', $result['attendance_state'], 'domain attendance state ' . (string)($result['write_reason'] ?? 'UNKNOWN'));
    faceApiAssert('ATTENDANCE_RECORDED', $result['effect'], 'domain attendance effect');
    faceApiAssert(1, (int)faceApiScalar($db, 'SELECT COUNT(*) FROM attendance'), 'one automatic attendance row');
    faceApiAssert(1, (int)faceApiScalar($db, 'SELECT COUNT(*) FROM attendance_event'), 'one automatic attendance event');
    faceApiAssert('FACE_MATCH', (string)faceApiScalar($db, "SELECT source FROM attendance_event WHERE client_attempt_id = 'attempt-live-domain'"), 'event source');
    faceApiAssert((int)$started['scan_id'], (int)faceApiScalar($db, "SELECT scan_id FROM attendance_event WHERE client_attempt_id = 'attempt-live-domain'"), 'event scan linkage');
    faceApiAssert(3, (int)faceApiScalar($db, 'SELECT sessions_used FROM invoice_item WHERE id = 201'), 'one membership session deducted');

    $replay = $service->recognizeFaceAttempt($started['scan_id'], $started['scan_token'], 9001, 'attempt-live-domain', ['frame-1', 'frame-1-second', 'frame-1-third']);
    faceApiAssert('ALREADY_PRESENT', $replay['attendance_state'], 'domain replay state');
    faceApiAssert('ALREADY_PRESENT', $replay['effect'], 'domain replay effect');
    faceApiAssert(1, (int)faceApiScalar($db, 'SELECT COUNT(*) FROM attendance'), 'replay does not create attendance');
    faceApiAssert(1, (int)faceApiScalar($db, 'SELECT COUNT(*) FROM attendance_event'), 'replay does not create attendance event');
    faceApiAssert(3, (int)faceApiScalar($db, 'SELECT sessions_used FROM invoice_item WHERE id = 201'), 'replay does not deduct another session');
}, $results);

faceApiCase('FACE-API-09 rejects an idempotency key reused by another scan', function () use ($db, $cipher): void {
    faceApiReset($db);
    $db->query("INSERT INTO biometric_consent (member_id, consent_status) VALUES (101, 'PROGRAM_AUTHORIZED')");
    faceApiInsertMappedTemplate($db, $cipher);
    $fake = new FaceApiFakeInference();
    $service = new FaceAttendanceApiService($db, $fake, $cipher, new FaceAttendanceFeatureConfig(true, true, false, false));
    $first = $service->startFaceScan(faceApiRequest());
    $firstCandidate = (string)faceApiScalar($db, 'SELECT candidate_ref FROM attendance_scan_roster WHERE scan_id = ' . (int)$first['scan_id'] . ' AND member_id = 101');
    $fake->attemptResult = ['decision' => 'ACCEPT', 'candidate_ref' => $firstCandidate, 'top1_score' => 0.95, 'top2_score' => 0.20, 'margin' => 0.75, 'quality' => ['face_count' => 1], 'liveness' => 'LIVE', 'model_version' => 'test-model', 'latency_ms' => 10];
    $service->recognizeFaceAttempt($first['scan_id'], $first['scan_token'], 9001, 'attempt-shared-key', ['frame-1', 'frame-1-second', 'frame-1-third']);
    $second = $service->startFaceScan(faceApiRequest());
    $conflict = $service->recognizeFaceAttempt($second['scan_id'], $second['scan_token'], 9001, 'attempt-shared-key', ['frame-2', 'frame-2-second', 'frame-2-third']);
    faceApiAssert('REJECTED', $conflict['state'], 'cross-scan idempotency state');
    faceApiAssert('IDEMPOTENCY_CONFLICT', $conflict['reason'], 'cross-scan idempotency reason');
    faceApiAssert(1, (int)faceApiScalar($db, 'SELECT COUNT(*) FROM recognition_attempt'), 'cross-scan attempt count');
    faceApiAssert(0, (int)faceApiScalar($db, 'SELECT COUNT(*) FROM attendance'), 'cross-scan attendance writes');
}, $results);

faceApiCase('FACE-API-01 downgrades an out-of-roster candidate to review', function () use ($db, $cipher): void {
    faceApiReset($db);
    $db->query("INSERT INTO biometric_consent (member_id, consent_status) VALUES (101, 'PROGRAM_AUTHORIZED')");
    faceApiInsertMappedTemplate($db, $cipher);
    $fake = new FaceApiFakeInference();
    $fake->attemptResult = ['decision' => 'ACCEPT', 'candidate_ref' => 'outside-roster', 'top1_score' => 0.99, 'top2_score' => 0.1, 'margin' => 0.89, 'quality' => [], 'liveness' => 'NOT_RUN', 'model_version' => 'test', 'latency_ms' => 3];
    $service = new FaceAttendanceApiService($db, $fake, $cipher, new FaceAttendanceFeatureConfig(true, true, false, false));
    $started = $service->startFaceScan(faceApiRequest());
    $result = $service->recognizeFaceAttempt($started['scan_id'], $started['scan_token'], 9001, 'attempt-outside', ['frame-1', 'frame-1-second', 'frame-1-third']);
    faceApiAssert('REVIEW', $result['decision'], 'out-of-roster decision');
    faceApiAssert(null, $result['member_id'], 'out-of-roster member');
}, $results);

faceApiCase('FACE-API-09 gives uncertain and unknown faces no row effect', function () use ($db, $cipher): void {
    faceApiReset($db);
    $db->query("INSERT INTO biometric_consent (member_id, consent_status) VALUES (101, 'PROGRAM_AUTHORIZED')");
    faceApiInsertMappedTemplate($db, $cipher);
    $fake = new FaceApiFakeInference();
    $service = new FaceAttendanceApiService($db, $fake, $cipher, new FaceAttendanceFeatureConfig(true, true, false, false));
    $started = $service->startFaceScan(faceApiRequest());
    $candidateRef = (string)faceApiScalar($db, 'SELECT candidate_ref FROM attendance_scan_roster WHERE scan_id = ' . (int)$started['scan_id'] . ' AND member_id = 101');
    $fake->attemptResult = ['decision' => 'REVIEW', 'candidate_ref' => $candidateRef, 'top1_score' => 0.70, 'top2_score' => 0.65, 'margin' => 0.05, 'quality' => ['face_count' => 1], 'liveness' => 'NOT_RUN', 'model_version' => 'test-model', 'latency_ms' => 10];
    $uncertain = $service->recognizeFaceAttempt($started['scan_id'], $started['scan_token'], 9001, 'attempt-uncertain', ['frame-1', 'frame-1-second', 'frame-1-third']);
    faceApiAssert('REVIEW', $uncertain['decision'], 'uncertain decision');
    faceApiAssert('NONE', $uncertain['effect'], 'uncertain row effect');
    $fake->attemptResult = ['decision' => 'REJECT', 'candidate_ref' => null, 'top1_score' => 0.10, 'top2_score' => 0.09, 'margin' => 0.01, 'quality' => ['face_count' => 1], 'liveness' => 'NOT_RUN', 'model_version' => 'test-model', 'latency_ms' => 10];
    $unknown = $service->recognizeFaceAttempt($started['scan_id'], $started['scan_token'], 9001, 'attempt-unknown', ['frame-2', 'frame-2-second', 'frame-2-third']);
    faceApiAssert('REJECT', $unknown['decision'], 'unknown decision');
    faceApiAssert('NONE', $unknown['effect'], 'unknown row effect');
    $completed = $service->completeFaceScan($started['scan_id'], $started['scan_token'], 9001);
    faceApiAssert(0, count($completed['review']), 'ambiguous review is not bound to a guessed member');
    faceApiAssert(0, (int)faceApiScalar($db, 'SELECT COUNT(*) FROM attendance'), 'uncertain and unknown attendance writes');
}, $results);

faceApiCase('FACE-API-09 gives a revoked face no row effect', function () use ($db, $cipher): void {
    faceApiReset($db);
    $db->query("INSERT INTO biometric_consent (member_id, consent_status) VALUES (101, 'PROGRAM_AUTHORIZED')");
    faceApiInsertMappedTemplate($db, $cipher);
    $fake = new FaceApiFakeInference();
    $service = new FaceAttendanceApiService($db, $fake, $cipher, new FaceAttendanceFeatureConfig(true, true, false, false));
    $started = $service->startFaceScan(faceApiRequest());
    $candidateRef = (string)faceApiScalar($db, 'SELECT candidate_ref FROM attendance_scan_roster WHERE scan_id = ' . (int)$started['scan_id'] . ' AND member_id = 101');
    $db->query("UPDATE face_template SET status = 'REVOKED' WHERE member_id = 101");
    $db->query("UPDATE biometric_consent SET consent_status = 'WITHDRAWN' WHERE member_id = 101");
    $fake->attemptResult = ['identity_decision' => 'ACCEPT', 'decision' => 'REVIEW', 'candidate_ref' => $candidateRef, 'top1_score' => 0.95, 'top2_score' => 0.20, 'margin' => 0.75, 'quality' => ['face_count' => 1], 'liveness' => 'NOT_RUN', 'model_version' => 'test-model', 'latency_ms' => 10];
    $result = $service->recognizeFaceAttempt($started['scan_id'], $started['scan_token'], 9001, 'attempt-revoked', ['frame-1', 'frame-1-second', 'frame-1-third']);
    faceApiAssert('REVIEW', $result['decision'], 'revoked decision');
    faceApiAssert('NONE', $result['effect'], 'revoked row effect');
    faceApiAssert(null, $result['member_id'], 'revoked member response');
    faceApiAssert(0, (int)faceApiScalar($db, 'SELECT COUNT(*) FROM attendance'), 'revoked attendance writes');
}, $results);

faceApiCase('FACE-API-09 rechecks membership eligibility before selecting a row', function () use ($db, $cipher): void {
    faceApiReset($db);
    $db->query("INSERT INTO biometric_consent (member_id, consent_status) VALUES (101, 'PROGRAM_AUTHORIZED')");
    faceApiInsertMappedTemplate($db, $cipher);
    $fake = new FaceApiFakeInference();
    $service = new FaceAttendanceApiService($db, $fake, $cipher, new FaceAttendanceFeatureConfig(true, true, false, false));
    $started = $service->startFaceScan(faceApiRequest());
    $candidateRef = (string)faceApiScalar($db, 'SELECT candidate_ref FROM attendance_scan_roster WHERE scan_id = ' . (int)$started['scan_id'] . ' AND member_id = 101');
    $db->query("UPDATE invoice_invoiceitem_module_view SET item_park = 1 WHERE contact_id = 1101 AND batch_id = 11");
    $fake->attemptResult = ['identity_decision' => 'ACCEPT', 'decision' => 'REVIEW', 'candidate_ref' => $candidateRef, 'top1_score' => 0.95, 'top2_score' => 0.20, 'margin' => 0.75, 'quality' => ['face_count' => 1], 'liveness' => 'NOT_RUN', 'model_version' => 'test-model', 'latency_ms' => 10];
    $result = $service->recognizeFaceAttempt($started['scan_id'], $started['scan_token'], 9001, 'attempt-ineligible-after-start', ['frame-1', 'frame-1-second', 'frame-1-third']);
    faceApiAssert('REVIEW', $result['decision'], 'changed membership decision');
    faceApiAssert('NONE', $result['effect'], 'changed membership effect');
    faceApiAssert(null, $result['member_id'], 'changed membership member');
    faceApiAssert(0, (int)faceApiScalar($db, 'SELECT COUNT(*) FROM attendance'), 'changed membership attendance writes');
}, $results);

faceApiCase('FACE-API-01 enforces owner token and cancel clears sidecar without attendance writes', function () use ($db, $cipher): void {
    faceApiReset($db);
    $db->query("INSERT INTO biometric_consent (member_id, consent_status) VALUES (101, 'PROGRAM_AUTHORIZED')");
    faceApiInsertMappedTemplate($db, $cipher);
    $fake = new FaceApiFakeInference();
    $service = new FaceAttendanceApiService($db, $fake, $cipher, new FaceAttendanceFeatureConfig(true, true, false, false));
    $started = $service->startFaceScan(faceApiRequest());
    $forbidden = $service->recognizeFaceAttempt($started['scan_id'], $started['scan_token'], 9002, 'attempt-owner', ['frame-1', 'frame-1-second', 'frame-1-third']);
    faceApiAssert('REJECTED', $forbidden['state'], 'owner rejection state');
    faceApiAssert('SCAN_OWNER', $forbidden['reason'], 'owner rejection reason');
    $cancelled = $service->cancelFaceScan($started['scan_id'], $started['scan_token'], 9001);
    faceApiAssert('CANCELLED', $cancelled['state'], 'cancel state');
    faceApiAssert(1, $fake->deleteCalls, 'sidecar delete call count');
    faceApiAssert(0, (int)faceApiScalar($db, 'SELECT COUNT(*) FROM attendance'), 'cancel attendance writes');
}, $results);

faceApiCase('FACE-API-09 expires a scan without selecting or writing attendance', function () use ($db, $cipher): void {
    faceApiReset($db);
    $db->query("INSERT INTO biometric_consent (member_id, consent_status) VALUES (101, 'PROGRAM_AUTHORIZED')");
    faceApiInsertMappedTemplate($db, $cipher);
    $fake = new FaceApiFakeInference();
    $service = new FaceAttendanceApiService($db, $fake, $cipher, new FaceAttendanceFeatureConfig(true, true, false, false));
    $started = $service->startFaceScan(faceApiRequest());
    $expiredAt = date('Y-m-d H:i:s', time() - 5);
    $db->query("UPDATE attendance_scan SET expires_at = '{$expiredAt}' WHERE id = " . (int)$started['scan_id']);
    $attempt = $service->recognizeFaceAttempt($started['scan_id'], $started['scan_token'], 9001, 'attempt-expired', ['frame-1', 'frame-1-second', 'frame-1-third']);
    faceApiAssert('REJECTED', $attempt['state'], 'expired attempt state');
    faceApiAssert('SCAN_EXPIRED', $attempt['reason'], 'expired attempt reason');
    faceApiAssert(1, $fake->deleteCalls, 'expired sidecar cleanup');
    $status = $service->getFaceScanStatus($started['scan_id'], $started['scan_token'], 9001);
    faceApiAssert('EXPIRED', $status['state'], 'expired status state');
    faceApiAssert(0, (int)faceApiScalar($db, 'SELECT COUNT(*) FROM recognition_attempt'), 'expired recognition attempts');
    faceApiAssert(0, (int)faceApiScalar($db, 'SELECT COUNT(*) FROM attendance'), 'expired attendance writes');
}, $results);

faceApiCase('FACE-API-01 completes reconciliation without writing attendance in shadow mode', function () use ($db, $cipher): void {
    faceApiReset($db);
    $db->query("INSERT INTO biometric_consent (member_id, consent_status) VALUES (101, 'PROGRAM_AUTHORIZED')");
    faceApiInsertMappedTemplate($db, $cipher);
    $fake = new FaceApiFakeInference();
    $service = new FaceAttendanceApiService($db, $fake, $cipher, new FaceAttendanceFeatureConfig(true, true, false, false));
    $started = $service->startFaceScan(faceApiRequest());
    $absenceAttempt = $service->completeFaceScan($started['scan_id'], $started['scan_token'], 9001, true);
    faceApiAssert('REJECTED', $absenceAttempt['state'], 'automatic absence state');
    faceApiAssert('ABSENCE_CONFIRMATION_UNSUPPORTED', $absenceAttempt['reason'], 'automatic absence reason');
    faceApiAssert('ACTIVE', (string)faceApiScalar($db, 'SELECT status FROM attendance_scan WHERE id = ' . (int)$started['scan_id']), 'scan remains active after absence rejection');
    faceApiAssert(0, (int)faceApiScalar($db, 'SELECT COUNT(*) FROM attendance'), 'automatic absence attendance writes');
    $result = $service->completeFaceScan($started['scan_id'], $started['scan_token'], 9001);
    faceApiAssert('COMPLETED', $result['state'], 'complete state');
    faceApiAssert(2, count($result['missing']) + count($result['manual']), 'reconciliation members');
    faceApiAssert('Mapped Child', $result['missing'][0]['fullname'], 'missing mapped member name from contact');
    faceApiAssert('Manual Child', $result['manual'][0]['fullname'], 'manual member name from contact');
    faceApiAssert(true, $result['absence_written'] === false, 'shadow absence writes');
    faceApiAssert(1, $fake->deleteCalls, 'complete sidecar delete call count');
    faceApiAssert(0, (int)faceApiScalar($db, 'SELECT COUNT(*) FROM attendance'), 'complete attendance writes');
}, $results);

faceApiCase('FACE-API-01 rejects unavailable feature flag and invalid date', function () use ($db, $cipher): void {
    faceApiReset($db);
    $fake = new FaceApiFakeInference();
    $disabled = new FaceAttendanceApiService($db, $fake, $cipher, new FaceAttendanceFeatureConfig(false, true, false, false));
    $result = $disabled->startFaceScan(faceApiRequest());
    faceApiAssert('REJECTED', $result['state'], 'disabled state');
    faceApiAssert('FEATURE_DISABLED', $result['reason'], 'disabled reason');
    $enabled = new FaceAttendanceApiService($db, $fake, $cipher, new FaceAttendanceFeatureConfig(true, true, false, false));
    $oldDate = $enabled->startFaceScan(faceApiRequest(['attendance_date' => date('Y-m-d', strtotime('first day of last month')), 'check_month' => true]));
    faceApiAssert('REJECTED', $oldDate['state'], 'old date state');
    faceApiAssert('DATE_POLICY', $oldDate['reason'], 'old date reason');
}, $results);

faceApiCase('FACE-API-13 switch-off cancels scans and database failure denies selection', function () use ($db, $cipher): void {
    faceApiReset($db);
    $db->query("INSERT INTO biometric_consent (member_id, consent_status) VALUES (101, 'PROGRAM_AUTHORIZED')");
    faceApiInsertMappedTemplate($db, $cipher);
    $fake = new FaceApiFakeInference();
    $service = new FaceAttendanceApiService($db, $fake, $cipher, new FaceAttendanceFeatureConfig(true, true, false, false));
    $started = $service->startFaceScan(faceApiRequest());
    faceApiAssert('STARTED', $started['state'], 'start before switch-off');
    faceApiAssert(1, FaceSelectionGate::disable($db, $fake), 'cancelled scan count');
    faceApiAssert('CANCELLED', (string)faceApiScalar($db, 'SELECT status FROM attendance_scan WHERE id = ' . (int)$started['scan_id']), 'cancelled status');
    faceApiAssert(1, $fake->deleteCalls, 'sidecar eviction');
    faceApiAssert('SYSTEM_NOT_READY', $service->startFaceScan(faceApiRequest())['reason'], 'switch-off denies start');
    $db->query('DELETE FROM face_selection_control');
    faceApiAssert('SYSTEM_NOT_READY', $service->startFaceScan(faceApiRequest())['reason'], 'missing control row denies start');
    faceApiAssert(0, (int)faceApiScalar($db, 'SELECT COUNT(*) FROM attendance'), 'switch-off attendance writes');
}, $results);

faceApiCase('FACE-API-13 switch-off during inference never selects a row', function () use ($db, $cipher): void {
    faceApiReset($db);
    $db->query("INSERT INTO biometric_consent (member_id, consent_status) VALUES (101, 'PROGRAM_AUTHORIZED')");
    faceApiInsertMappedTemplate($db, $cipher);
    $fake = new FaceApiFakeInference();
    $service = new FaceAttendanceApiService($db, $fake, $cipher, new FaceAttendanceFeatureConfig(true, true, false, false));
    $started = $service->startFaceScan(faceApiRequest());
    faceApiAssert('STARTED', $started['state'], 'start before race');
    $fake->onAttempt = function () use ($db, $fake): void { FaceSelectionGate::disable($db, $fake); };
    $result = $service->recognizeFaceAttempt($started['scan_id'], $started['scan_token'], 9001, 'race-attempt', ['frame-1', 'frame-1-second', 'frame-1-third']);
    faceApiAssert('REJECTED', $result['state'], 'race result');
    faceApiAssert('SYSTEM_NOT_READY', $result['reason'], 'race reason');
    faceApiAssert(0, (int)faceApiScalar($db, 'SELECT COUNT(*) FROM attendance'), 'race attendance writes');
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

fwrite(STDOUT, sprintf("%d FACE-API-01 scenarios, %d failed.\n", count($results), $failed));
// Additional frame-count regressions; the 19 existing scenarios are unchanged.
foreach ([1, 2, 4, 5] as $frameCount) {
    try {
        faceApiReset($db);
        $db->query("INSERT INTO biometric_consent (member_id, consent_status) VALUES (101, 'PROGRAM_AUTHORIZED')");
        faceApiInsertMappedTemplate($db, $cipher);
        $fake = new FaceApiFakeInference();
        $service = new FaceAttendanceApiService($db, $fake, $cipher, new FaceAttendanceFeatureConfig(true, true, false, false));
        $scan = $service->startFaceScan(faceApiRequest());
        $frames = array_map(function ($index) { return 'count-frame-' . $index; }, range(1, $frameCount));
        $result = $service->recognizeFaceAttempt($scan['scan_id'], $scan['scan_token'], 9001, 'count-' . $frameCount, $frames);
        faceApiAssert('FRAME_COUNT', $result['reason'] ?? '', 'reject non-three frame recognition');
        faceApiAssert(0, $fake->attemptCalls, 'invalid frame count must not reach inference');
        fwrite(STDOUT, "PASS recognition rejects {$frameCount} frames before inference\n");
    } catch (Throwable $exception) {
        $failed++;
        fwrite(STDERR, "FAIL recognition frame-count {$frameCount}: {$exception->getMessage()}\n");
    }
}
exit($failed === 0 ? 0 : 1);
