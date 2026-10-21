<?php

declare(strict_types=1);

// Connection-scoped TEMPORARY tables only; no persistent schema or live inference.
require_once dirname(__DIR__, 3) . '/classes/services/FaceAttendanceApiService.php';

final class RecoveryAssertion extends RuntimeException {}
function recoveryAssert(bool $condition, string $message): void
{
    if (!$condition) throw new RecoveryAssertion($message);
}
function recoverySetup(bool $condition, string $message): void
{
    if (!$condition) throw new RuntimeException('FIXTURE_SETUP: ' . $message);
}
function recoveryDb(): mysqli
{
    $name = getenv('ATTENDANCE_TEST_DB') ?: 'ccc_database_test';
    if ($name !== 'ccc_database_test') throw new RuntimeException('FIXTURE_SETUP: exact test database required');
    if (!extension_loaded('mysqli')) throw new RuntimeException('FIXTURE_SETUP: mysqli required');
    mysqli_report(MYSQLI_REPORT_ERROR | MYSQLI_REPORT_STRICT);
    try {
        $db = new mysqli(getenv('ATTENDANCE_TEST_DB_HOST') ?: '127.0.0.1',
            getenv('ATTENDANCE_TEST_DB_USER') ?: 'root', getenv('ATTENDANCE_TEST_DB_PASSWORD') ?: '', $name);
    } catch (Throwable $error) {
        try {
            require_once dirname(__DIR__, 3) . '/classes/dbconnection.class.php';
            $db = (new dbconnection())->connect($name);
        } catch (Throwable $fallback) {
            throw new RuntimeException('FIXTURE_SETUP: test database connection unavailable');
        }
    }
    $db->set_charset('utf8mb4');
    recoverySetup($db->query('SELECT DATABASE()')->fetch_row()[0] === $name, 'database identity mismatch');
    recoverySchema($db);
    return $db;
}

function recoverySchema(mysqli $db): void
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
  `contact_id` INT NOT NULL,
  `batch_id` INT NOT NULL,
  `date` DATE NOT NULL,
  `session_id` INT NOT NULL,
  `type` CHAR(1) NOT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
CREATE TEMPORARY TABLE IF NOT EXISTS `invoice_item` (
  `id` INT NOT NULL PRIMARY KEY,
  `sessions_used` INT NOT NULL DEFAULT 0
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
CREATE TEMPORARY TABLE IF NOT EXISTS `attendance_event` (
  `id` INT NOT NULL AUTO_INCREMENT PRIMARY KEY
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
SQL;

    foreach (explode(";\n", $schema) as $statement) {
        $statement = trim($statement);
        if ($statement !== '') {
            $db->query($statement);
        }
    }
}

function recoveryReset(mysqli $db): void
{
    foreach (['recognition_attempt', 'attendance_scan_roster', 'attendance_scan', 'attendance', 'attendance_event', 'invoice_item', 'face_template', 'face_model_version', 'biometric_consent', 'invoice_invoiceitem_module_view', 'member', 'contact', 'batch_time', 'batch'] as $table) {
        $db->query("TRUNCATE TABLE `{$table}`");
    }
    $today = date('Y-m-d');
    $day = (int)date('w', strtotime($today));
    $db->query("INSERT INTO face_selection_control (id, enabled) VALUES (1, 1) ON DUPLICATE KEY UPDATE enabled = 1");
    $db->query('INSERT INTO invoice_item (id, sessions_used) VALUES (201, 0), (202, 2)');
    $db->query("INSERT INTO batch (id, bid, batch, start_date, end_date) VALUES (11, 7, 'Test Batch', '2020-01-01', '2030-12-31')");
    $db->query("INSERT INTO batch_time (batch_id, day_code, start_time, end_time) VALUES (11, '{$day}', '09:00:00', '11:00:00')");
    $db->query("INSERT INTO contact (id, fname, mname, lname, park) VALUES (1101, 'Mapped', '', 'Child', 0), (1102, 'Manual', '', 'Child', 0)");
    $db->query("INSERT INTO member (id, contact_id, status, park, bid) VALUES (101, 1101, 1, 0, 7), (102, 1102, 1, 0, 7)");
    $db->query("INSERT INTO invoice_invoiceitem_module_view (item_id, contact_id, batch_id, session_id, start_date, end_date, sessions, sessions_used) VALUES (201, 1101, 11, 4, '2020-01-01', '2030-12-31', 0, 0), (202, 1102, 11, 4, '2020-01-01', '2030-12-31', 10, 2)");
    $db->query("INSERT INTO face_model_version (id, model_name, provider_id, provider_version, policy_version, embedding_dimension, status) VALUES (9, 'test-model', 'fake-provider', '1', 'test-policy-v1', 3, 'ACTIVE')");
}

function recoveryInsertTemplate(mysqli $db, FaceEmbeddingCipher $cipher): void
{
    $encrypted = $db->real_escape_string($cipher->encrypt([0.1, 0.2, 0.3]));
    $db->query("INSERT INTO face_template (member_id, encrypted_embedding, status) VALUES (101, '{$encrypted}', 'ACTIVE')");
}

// Intentionally implements ONLY the existing interface. P2 must keep it usable.
class RecoveryLegacyProvider implements FaceRecognitionProvider
{
    public $ready = true;
    public $attemptCalls = 0;
    public $deleteCalls = 0;
    private $candidate;
    public function checkReadiness(): bool { return $this->ready; }
    public function providerMetadata(): array
    {
        return ['provider_id' => 'fake-provider', 'provider_version' => '1', 'model_version_id' => 9,
            'model_version' => 'test-model', 'calibration_policy_version' => 'test-policy-v1',
            'recognition_generation' => str_repeat('a', 64)];
    }
    public function initializeScan(string $scanId, array $candidates, int $ttl): array
    {
        $this->candidate = $candidates[0]['candidate_ref'] ?? null;
        return ['scan_id' => $scanId, 'candidate_count' => count($candidates), 'expires_in' => $ttl];
    }
    public function recognizeAttempt(string $scanId, array $frames, string $challengeToken = '', array $captureContexts = []): array
    {
        $this->attemptCalls++;
        return ['decision' => 'ACCEPT', 'candidate_ref' => $this->candidate,
            'top1_score' => 0.95, 'top2_score' => 0.20, 'margin' => 0.75,
            'quality' => ['face_count' => 1, 'sharpness_ok' => true, 'exposure_ok' => true, 'pose_ok' => true],
            'liveness' => 'LIVE', 'model_version' => 'test-model', 'latency_ms' => 10];
    }
    public function deleteScan(string $scanId): void { $this->deleteCalls++; }
}
final class RecoveryClassifiedProvider extends RecoveryLegacyProvider
{
    public function readinessStatus(): array
    {
        return ['ready' => $this->ready, 'retryable' => !$this->ready,
            'reason' => $this->ready ? 'READY' : 'INFERENCE_OFFLINE'];
    }
}
final class RecoveryFixture
{
    public $db, $provider, $service, $scan, $binding, $frames, $contexts;
    public function __construct(mysqli $db, ?RecoveryLegacyProvider $provider = null)
    {
        $this->db = $db;
        recoveryReset($db);
        putenv('FACE_TEACHER_SELECTION_ENABLED=1');
        putenv('FACE_CAPTURE_NONCE_REQUIRED=1');
        $cipher = new FaceEmbeddingCipher(str_repeat('k', 32), 'test-key-v1');
        recoveryInsertTemplate($db, $cipher);
        $db->query("INSERT INTO biometric_consent (member_id, consent_status) VALUES (101, 'PROGRAM_AUTHORIZED')");
        $this->provider = $provider ?: new RecoveryLegacyProvider();
        $this->service = new FaceAttendanceApiService($db, $this->provider, $cipher,
            new FaceAttendanceFeatureConfig(true, true, false, false));
        $this->binding = hash('sha256', 'p0-test-browser');
        $this->scan = $this->service->startFaceScan(['branch_id' => 7, 'batch_id' => 11,
            'attendance_date' => date('Y-m-d'), 'session_id' => 4, 'selected_time' => '10:00:00',
            'event_type' => 'PUNCH_IN', 'actor_employee_id' => 9001, 'check_month' => false,
            'browser_session_binding' => $this->binding]);
        recoverySetup(($this->scan['state'] ?? '') === 'STARTED', 'scan start: ' . ($this->scan['reason'] ?? 'missing state'));
        $this->frames = ['frame-a', 'frame-b', 'frame-c'];
        $this->contexts = array_map(function ($id) { return ['capture_id' => $id]; }, $this->frames);
    }
    public function attempt(string $id = 'p0-attempt', ?string $nonce = null, ?array $frames = null, int $actor = 9001, ?string $binding = null): array
    {
        $frames = $frames ?? $this->frames;
        return $this->service->recognizeFaceAttempt($this->scan['scan_id'], $this->scan['scan_token'],
            $actor, $id, $frames, '', array_slice($this->contexts, 0, count($frames)),
            $nonce ?? $this->scan['capture_nonce'], $binding ?? $this->binding);
    }
    public function freshNonce(): string
    {
        $result = $this->service->issueCaptureNonce($this->scan['scan_id'], $this->scan['scan_token'], 9001, $this->binding);
        recoverySetup(($result['state'] ?? '') === 'READY', 'nonce issuance');
        return $result['capture_nonce'];
    }
    public function scalar(string $sql) { return $this->db->query($sql)->fetch_row()[0]; }
    public function accepted(): array
    {
        $result = $this->attempt();
        recoverySetup(($result['decision'] ?? '') === 'ACCEPT', 'first attempt must accept: ' . ($result['reason'] ?? 'unknown'));
        return $result;
    }
}
function recoveryCase(string $name, callable $test): bool
{
    try { $test(); $result = ['case' => $name, 'status' => 'PASS']; }
    catch (RecoveryAssertion $error) { $result = ['case' => $name, 'status' => 'FAIL', 'detail' => $error->getMessage()]; }
    catch (Throwable $error) { $result = ['case' => $name, 'status' => 'ERROR', 'detail' => $error->getMessage()]; }
    echo json_encode($result, JSON_UNESCAPED_SLASHES) . PHP_EOL;
    return $result['status'] === 'PASS';
}
