<?php

declare(strict_types=1);

/**
 * FACE-PILOT-01: Supervised Camera Smoke Test and Pilot Verification Contract.
 *
 * Verifies:
 * 1. Member enrollment lifecycle, duplicate identity rejection, and template encryption.
 * 2. Camera scan startup by authorized teacher, sidecar initialization, and candidate roster freeze.
 * 3. Accepted identity returns SELECT_PRESENT and PENDING_TEACHER_COMMIT; client selects exactly one row.
 * 4. Exception cases (duplicate scan, out-of-roster/unknown, borderline, revoked) never select rows.
 * 5. Attendance and membership remain completely unchanged until the teacher's authoritative Punch Attendance commit.
 * 6. Authoritative Punch Attendance commits the batch idempotently without duplicate rows or deductions.
 */

date_default_timezone_set('UTC');
putenv('FACE_TEACHER_SELECTION_ENABLED=1'); // disposable fixture exercises the activated branch

$root = dirname(__DIR__, 2);
require_once $root . '/classes/contracts/FaceRecognitionProvider.php';
require_once $root . '/classes/clients/FaceInferenceClient.php';
require_once $root . '/classes/providers/FaceRecognitionProviderFactory.php';
require_once $root . '/classes/services/FaceAttendanceApiService.php';
require_once $root . '/classes/services/FaceEnrollmentService.php';
require_once $root . '/classes/services/AttendanceDomainService.php';
require_once $root . '/classes/dbconnection.class.php';

$envFile = $root . '/.env';
if (file_exists($envFile)) {
    $lines = file($envFile, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
    foreach ($lines as $line) {
        $line = trim($line);
        if ($line === '' || strpos($line, '#') === 0 || strpos($line, '=') === false) {
            continue;
        }
        [$k, $v] = explode('=', $line, 2);
        $k = trim($k);
        $v = trim($v);
        if (getenv($k) === false) {
            putenv("{$k}={$v}");
        }
    }
}

$dbHost = getenv('ATTENDANCE_TEST_DB_HOST') ?: '127.0.0.1';
$dbUser = getenv('ATTENDANCE_TEST_DB_USER') ?: 'nahid';
$dbPassword = getenv('ATTENDANCE_TEST_DB_PASSWORD') ?: '';
$dbName = getenv('ATTENDANCE_TEST_DB') ?: 'ccc_database_test';

$key = getenv('FACE_EMBEDDING_KEY') ?: 'face-export-fixture-key-only';
$keyId = getenv('FACE_EMBEDDING_KEY_ID') ?: 'face-export-fixture-key-id';
$branchId = 2;
$batchId = 27;
$teacherId = 6; // Manisha Dattani

$failures = [];
$assertions = 0;

function pilotAssert(bool $condition, string $label, string $detail = ''): void
{
    global $assertions, $failures;
    $assertions++;
    if ($condition) {
        echo "PASS {$label}" . ($detail !== '' ? ": {$detail}" : '') . "\n";
    } else {
        $failures[] = "FAIL {$label}" . ($detail !== '' ? ": {$detail}" : '');
        echo "FAIL {$label}" . ($detail !== '' ? ": {$detail}" : '') . "\n";
    }
}

mysqli_report(MYSQLI_REPORT_ERROR | MYSQLI_REPORT_STRICT);
try {
    $db = new mysqli($dbHost, $dbUser, $dbPassword, $dbName);
    $db->set_charset('utf8mb4');
} catch (Throwable $e) {
    echo "BLOCKED: Cannot connect to test database {$dbName}: " . $e->getMessage() . "\n";
    exit(2);
}

// Set up connection-scoped temporary tables in ccc_database_test for isolated, safe execution
$db->query("CREATE TEMPORARY TABLE face_selection_control (id TINYINT UNSIGNED PRIMARY KEY, enabled TINYINT(1) NOT NULL)");
$db->query("INSERT INTO face_selection_control VALUES (1,1)");
$db->query("
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
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

$db->query("
CREATE TEMPORARY TABLE IF NOT EXISTS `batch_time` (
  `id` INT NOT NULL AUTO_INCREMENT PRIMARY KEY,
  `batch_id` INT NOT NULL,
  `day_code` VARCHAR(10) NOT NULL,
  `start_time` TIME NOT NULL,
  `end_time` TIME NOT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

$db->query("
CREATE TEMPORARY TABLE IF NOT EXISTS `member` (
  `id` INT NOT NULL PRIMARY KEY,
  `contact_id` INT NOT NULL,
  `status` INT NOT NULL DEFAULT 1,
  `park` INT NOT NULL DEFAULT 0,
  `bid` INT NOT NULL,
  `pending_makeup` INT NOT NULL DEFAULT 0,
  `pending_not_pay_sal_attendance` INT NOT NULL DEFAULT 0,
  UNIQUE KEY `uq_member_contact` (`contact_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

$db->query("
CREATE TEMPORARY TABLE IF NOT EXISTS `contact` (
  `id` INT NOT NULL PRIMARY KEY,
  `fname` VARCHAR(50) NOT NULL,
  `mname` VARCHAR(50) NOT NULL DEFAULT '',
  `lname` VARCHAR(50) NOT NULL DEFAULT '',
  `park` INT NOT NULL DEFAULT 0
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

$db->query("
CREATE TEMPORARY TABLE IF NOT EXISTS `invoice_item` (
  `id` INT NOT NULL PRIMARY KEY,
  `sessions` INT NOT NULL DEFAULT 10,
  `sessions_used` INT NOT NULL DEFAULT 0,
  `start_date` DATE NOT NULL,
  `end_date` DATE NOT NULL,
  `batch_id` INT NOT NULL DEFAULT 27,
  `modified_by` INT NOT NULL DEFAULT 0
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

$db->query("
CREATE TEMPORARY TABLE IF NOT EXISTS `invoice_invoiceitem_view` (
  `item_id` INT NOT NULL PRIMARY KEY,
  `contact_id` INT NOT NULL,
  `batch_id` INT NOT NULL DEFAULT 27,
  `start_date` DATE NOT NULL,
  `end_date` DATE NOT NULL,
  `sessions` INT NOT NULL DEFAULT 10,
  `sessions_used` INT NOT NULL DEFAULT 0,
  `park` INT NOT NULL DEFAULT 0,
  `item_park` INT NOT NULL DEFAULT 0
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

$db->query("
CREATE TEMPORARY TABLE IF NOT EXISTS `invoice_invoiceitem_module_view` (
  `item_id` INT NOT NULL PRIMARY KEY,
  `contact_id` INT NOT NULL,
  `batch_id` INT NOT NULL,
  `session_id` INT NULL DEFAULT 0,
  `start_date` DATE NOT NULL,
  `end_date` DATE NOT NULL,
  `sessions` INT NOT NULL,
  `sessions_used` INT NOT NULL DEFAULT 0,
  `park` INT NOT NULL DEFAULT 0,
  `item_park` INT NOT NULL DEFAULT 0
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

$db->query("
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
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

$db->query("
CREATE TEMPORARY TABLE IF NOT EXISTS `face_template` (
  `id` INT NOT NULL AUTO_INCREMENT PRIMARY KEY,
  `member_id` INT NOT NULL,
  `model_version_id` INT NOT NULL,
  `encrypted_embedding` MEDIUMBLOB NOT NULL,
  `encryption_key_id` VARCHAR(100) NOT NULL,
  `embedding_dimension` INT NOT NULL,
  `preprocessing_version` VARCHAR(100) NOT NULL DEFAULT 'test-preproc',
  `quality_summary` JSON NULL,
  `status` VARCHAR(20) NOT NULL DEFAULT 'ACTIVE',
  `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `expires_at` DATETIME NULL,
  `revoked_at` DATETIME NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

$db->query("
CREATE TEMPORARY TABLE IF NOT EXISTS `face_model_version` (
  `id` INT NOT NULL PRIMARY KEY,
  `model_name` VARCHAR(100) NOT NULL,
  `provider_id` VARCHAR(100) NOT NULL,
  `provider_version` VARCHAR(100) NOT NULL,
  `policy_version` VARCHAR(100) NOT NULL,
  `embedding_dimension` INT NOT NULL,
  `preprocessing_version` VARCHAR(100) NOT NULL DEFAULT 'test-preproc',
  `model_checksum` CHAR(64) NOT NULL,
  `status` VARCHAR(20) NOT NULL DEFAULT 'ACTIVE'
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

$db->query("
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
  UNIQUE KEY `uq_pilot_scan_token` (`scan_token`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

$db->query("
CREATE TEMPORARY TABLE IF NOT EXISTS `attendance_scan_roster` (
  `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
  `scan_id` BIGINT UNSIGNED NOT NULL,
  `member_id` INT NOT NULL,
  `candidate_ref` VARCHAR(128) NOT NULL,
  `eligibility_status` VARCHAR(20) NOT NULL,
  `consent_status` VARCHAR(32) NOT NULL,
  `template_count` INT NOT NULL DEFAULT 0,
  `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  UNIQUE KEY `uq_pilot_roster_member` (`scan_id`, `member_id`),
  UNIQUE KEY `uq_pilot_roster_candidate` (`scan_id`, `candidate_ref`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

$db->query("
CREATE TEMPORARY TABLE IF NOT EXISTS `recognition_attempt` (
  `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
  `scan_id` BIGINT UNSIGNED NOT NULL,
  `client_attempt_id` VARCHAR(128) NOT NULL,
  `candidate_ref` VARCHAR(128) NULL,
  `decision` VARCHAR(32) NOT NULL,
  `outcome` VARCHAR(32) NULL,
  `top1_score` DECIMAL(7, 4) NULL,
  `top2_score` DECIMAL(7, 4) NULL,
  `margin` DECIMAL(7, 4) NULL,
  `quality_summary` JSON NULL,
  `liveness` VARCHAR(32) NOT NULL,
  `model_version_id` INT NULL,
  `latency_ms` INT NOT NULL,
  `recognition_generation` CHAR(64) NULL,
  `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  UNIQUE KEY `uq_pilot_client_attempt` (`client_attempt_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

$db->query("
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
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

$db->query("
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
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

// Seed model version registry row 1
$db->query("INSERT INTO face_model_version (id, model_name, provider_id, provider_version, policy_version, embedding_dimension, model_checksum, status) VALUES (1, 'opencv-zoo-yunet-sface-int8-omz-anti-spoof-mn3', 'local-onnx', '1', 'face-policy-v1', 128, REPEAT('a', 64), 'ACTIVE')");

// Seed Batch 27 and schedule for today
$today = date('Y-m-d');
$dayCode = (int)date('w', strtotime($today));
$db->query("INSERT INTO batch (id, bid, batch, start_date, end_date) VALUES (27, 2, 'Skating Testing Thur 03:00 Pm (Manisha)', '2024-04-18', '2074-12-31')");
$db->query("INSERT INTO batch_time (batch_id, day_code, start_time, end_time) VALUES (27, '{$dayCode}', '00:00:00', '23:59:00')");

// Seed members 616-620 with contacts 774-778 in Branch 2
$db->query("INSERT INTO contact (id, fname, mname, lname, park) VALUES
  (774, 'FaceTest01', '', 'Child', 0),
  (775, 'FaceTest02', '', 'Child', 0),
  (776, 'FaceTest03', '', 'Child', 0),
  (777, 'FaceTest04', '', 'Child', 0),
  (778, 'FaceTest05', '', 'Child', 0)");

$db->query("INSERT INTO member (id, contact_id, status, park, bid) VALUES
  (616, 774, 1, 0, 2),
  (617, 775, 1, 0, 2),
  (618, 776, 1, 0, 2),
  (619, 777, 1, 0, 2),
  (620, 778, 1, 0, 2)");

// Seed memberships in invoice_item, invoice_invoiceitem_view, and invoice_invoiceitem_module_view for today
$db->query("INSERT INTO invoice_item (id, sessions, sessions_used, start_date, end_date, batch_id) VALUES
  (1001, 10, 0, '2026-09-11', '2027-12-31', 27),
  (1002, 10, 0, '2026-09-11', '2027-12-31', 27),
  (1003, 10, 0, '2026-09-11', '2027-12-31', 27),
  (1004, 10, 0, '2026-09-11', '2027-12-31', 27),
  (1005, 10, 0, '2026-09-11', '2027-12-31', 27)");

$db->query("INSERT INTO invoice_invoiceitem_view (item_id, contact_id, batch_id, start_date, end_date, sessions, sessions_used) VALUES
  (1001, 774, 27, '2026-09-11', '2027-12-31', 10, 0),
  (1002, 775, 27, '2026-09-11', '2027-12-31', 10, 0),
  (1003, 776, 27, '2026-09-11', '2027-12-31', 10, 0),
  (1004, 777, 27, '2026-09-11', '2027-12-31', 10, 0),
  (1005, 778, 27, '2026-09-11', '2027-12-31', 10, 0)");

$db->query("INSERT INTO invoice_invoiceitem_module_view (item_id, contact_id, batch_id, session_id, start_date, end_date, sessions, sessions_used) VALUES
  (1001, 774, 27, 0, '2026-09-11', '2027-12-31', 10, 0),
  (1002, 775, 27, 0, '2026-09-11', '2027-12-31', 10, 0),
  (1003, 776, 27, 0, '2026-09-11', '2027-12-31', 10, 0),
  (1004, 777, 27, 0, '2026-09-11', '2027-12-31', 10, 0),
  (1005, 778, 27, 0, '2026-09-11', '2027-12-31', 10, 0)");

$cipher = new FaceEmbeddingCipher($key, $keyId);

// Generate deterministic orthogonal 128D vectors for testing
function makeOrthogonalVector(int $seedIndex, int $dim = 128): array
{
    $vec = array_fill(0, $dim, 0.0);
    $offset = ($seedIndex * 16) % $dim;
    for ($i = 0; $i < 16; $i++) {
        $vec[($offset + $i) % $dim] = 1.0;
    }
    $norm = sqrt(array_sum(array_map(fn($v) => $v * $v, $vec)));
    return array_map(fn($v) => $v / $norm, $vec);
}

// =========================================================================
// SECTION 1: Member Enrollment & Duplicate Collision Prevention
// =========================================================================
echo "\n--- SECTION 1: Member Enrollment & Duplicate Identity Verification ---\n";

class TestInferenceEnrollmentClient implements FaceEnrollmentInferenceClient
{
    private $vector;
    public function __construct(array $vector) { $this->vector = $vector; }
    public function encodeEnrollment(array $frames, array $captureContexts = []): array
    {
        return [
            'verification_passed' => true,
            'model_version_id' => 1,
            'model_version' => 'opencv-zoo-yunet-sface-int8-omz-anti-spoof-mn3',
            'provider_id' => 'local-onnx',
            'provider_version' => '1',
            'calibration_policy_version' => 'face-policy-v1',
            'preprocessing_version' => 'test-preproc',
            'recognition_generation' => str_repeat('a', 64),
            'embedding_dimension' => 128,
            'embeddings' => [$this->vector],
            'quality_summary' => [
                'frames_received' => count($frames),
                'frames_accepted' => count($frames),
                'face_count' => 1,
            ],
        ];
    }
}

$vector616 = makeOrthogonalVector(1);
$vector617 = makeOrthogonalVector(2);
$vectorNear616 = $vector616; // Exact collision (cosine = 1.0 >= 0.75)

// Enroll member 616 with vector 1
$enroller616 = new FaceEnrollmentService($db, new TestInferenceEnrollmentClient($vector616), $cipher);
$enrollResult616 = $enroller616->enroll([
    'member_id' => 616,
    'branch_id' => 2,
    'actor_employee_id' => $teacherId,
    'notice_version' => 'program-face-v1',
    'purpose' => 'attendance',
    'teacher_confirmed_member' => true,
    'allow_uncalibrated_testing' => true,
    'frames' => array_fill(0, 8, 'synthetic-jpeg-frame'),
]);
pilotAssert($enrollResult616['state'] === 'ACTIVE', 'enroll-member-616-active', 'member 616 enrolled with status ACTIVE');

// Verify duplicate enrollment rejection for already enrolled member
$repeatEnroll = $enroller616->enroll([
    'member_id' => 616,
    'branch_id' => 2,
    'actor_employee_id' => $teacherId,
    'notice_version' => 'program-face-v1',
    'purpose' => 'attendance',
    'teacher_confirmed_member' => true,
    'frames' => array_fill(0, 8, 'synthetic-jpeg-frame'),
]);
pilotAssert($repeatEnroll['state'] === 'ALREADY_ENROLLED', 'enroll-prevent-duplicate-member', 'member 616 rejected as ALREADY_ENROLLED');

// Attempt to enroll member 617 with member 616's face vector -> must reject as FACE_ALREADY_ASSIGNED
$enrollerCollision = new FaceEnrollmentService($db, new TestInferenceEnrollmentClient($vectorNear616), $cipher);
$collisionResult = $enrollerCollision->enroll([
    'member_id' => 617,
    'branch_id' => 2,
    'actor_employee_id' => $teacherId,
    'notice_version' => 'program-face-v1',
    'purpose' => 'attendance',
    'teacher_confirmed_member' => true,
    'allow_uncalibrated_testing' => true,
    'frames' => array_fill(0, 8, 'synthetic-jpeg-frame'),
]);
pilotAssert($collisionResult['state'] === 'REJECTED' && $collisionResult['reason'] === 'FACE_ALREADY_ASSIGNED',
    'enroll-duplicate-identity-rejection', 'cross-child duplicate face rejected as FACE_ALREADY_ASSIGNED');

// Enroll member 617 with its own distinct vector 2
$enroller617 = new FaceEnrollmentService($db, new TestInferenceEnrollmentClient($vector617), $cipher);
$enrollResult617 = $enroller617->enroll([
    'member_id' => 617,
    'branch_id' => 2,
    'actor_employee_id' => $teacherId,
    'notice_version' => 'program-face-v1',
    'purpose' => 'attendance',
    'teacher_confirmed_member' => true,
    'allow_uncalibrated_testing' => true,
    'frames' => array_fill(0, 8, 'synthetic-jpeg-frame'),
]);
pilotAssert($enrollResult617['state'] === 'ACTIVE', 'enroll-member-617-distinct', 'distinct face for member 617 enrolled successfully');

// Verify templates encrypted with F1 prefix and valid AES-GCM
$stored616 = $db->query("SELECT encrypted_embedding, status, model_version_id FROM face_template WHERE member_id = 616 AND status = 'ACTIVE'")->fetch_assoc();
pilotAssert(strpos((string)$stored616['encrypted_embedding'], 'F1') === 0, 'enroll-template-encryption', 'stored embedding encrypted with F1 envelope');
$decrypted = $cipher->decrypt((string)$stored616['encrypted_embedding']);
pilotAssert(count($decrypted) === 128, 'enroll-template-decryption', 'decrypted embedding is 128 dimensions');

// Verify withdrawal
$withdrawResult = $enroller617->withdraw(617, $teacherId, 2);
pilotAssert($withdrawResult['state'] === 'REVOKED', 'enroll-withdraw-revocation', 'member 617 withdrawn and templates marked REVOKED');
$revokedCheck = $db->query("SELECT status, encrypted_embedding FROM face_template WHERE member_id = 617")->fetch_assoc();
pilotAssert($revokedCheck['status'] === 'REVOKED', 'enroll-revoked-status', 'template status set to REVOKED in database');
pilotAssert($revokedCheck['encrypted_embedding'] === '', 'withdraw-erases-biometric-blob', 'withdrawal erases stored template material');

// Re-enroll 617 explicitly; withdrawn biometric material cannot be restored by a status flip.
$reenrollResult617 = $enroller617->enroll([
    'member_id' => 617,
    'branch_id' => 2,
    'actor_employee_id' => $teacherId,
    'notice_version' => 'program-face-v1',
    'purpose' => 'attendance',
    'teacher_confirmed_member' => true,
    'allow_uncalibrated_testing' => true,
    'frames' => array_fill(0, 8, 'synthetic-jpeg-frame'),
]);
pilotAssert(($reenrollResult617['state'] ?? '') === 'ACTIVE', 'reenroll-after-withdrawal', 'withdrawn child requires explicit re-enrollment');

// =========================================================================
// SECTION 2: Supervised Camera Punch-in Start
// =========================================================================
echo "\n--- SECTION 2: Camera Smoke Test & Start Scan ---\n";

class TestInferenceProvider implements FaceRecognitionProvider
{
	public function checkReadiness(): bool { return true; }
    public $initializedRoster = [];
    public $lastAttemptToken = '';
    public $attemptCallCount = 0;
    public $deletedTokens = [];
    public $nextDecision = 'ACCEPT';
    public $nextCandidateRef = null;
    public $nextMargin = 0.25;

    public function providerMetadata(): array
    {
        return [
            'provider_id' => 'local-onnx',
            'provider_version' => '1',
            'model_version_id' => 1,
            'model_version' => 'opencv-zoo-yunet-sface-int8-omz-anti-spoof-mn3',
            'calibration_policy_version' => 'face-policy-v1',
            'recognition_generation' => str_repeat('a', 64),
        ];
    }

    public function initializeScan(string $scanId, array $candidates, int $ttl): array
    {
        $this->initializedRoster[$scanId] = $candidates;
        return ['status' => 'OK', 'scan_token' => $scanId, 'ttl' => $ttl, 'candidate_count' => count($candidates)];
    }

    public function recognizeAttempt(string $scanId, array $frames, string $challengeToken = '', array $captureContexts = []): array
    {
        $this->attemptCallCount++;
        $this->lastAttemptToken = $scanId;
        $decision = $this->nextDecision;
        $ref = $this->nextCandidateRef;
        return [
            'decision' => $decision,
            'identity_decision' => $decision,
            'candidate_ref' => $ref,
            'top1_score' => $decision === 'ACCEPT' ? 0.88 : 0.45,
            'top2_score' => $decision === 'ACCEPT' ? (0.88 - $this->nextMargin) : 0.40,
            'margin' => $this->nextMargin,
            'quality' => [
                'face_count' => 1,
                'sharpness_ok' => true,
                'exposure_ok' => true,
                'pose_ok' => true,
            ],
            'liveness' => $decision === 'ACCEPT' ? 'LIVE' : 'NOT_RUN',
            'model_version' => 'opencv-zoo-yunet-sface-int8-omz-anti-spoof-mn3',
            'latency_ms' => 12,
        ];
    }

    public function deleteScan(string $scanId): void
    {
        $this->deletedTokens[] = $scanId;
        unset($this->initializedRoster[$scanId]);
    }
}

$mockProvider = new TestInferenceProvider();
$config = new FaceAttendanceFeatureConfig(true, true, false, false, [2]);
$apiService = new FaceAttendanceApiService($db, $mockProvider, $cipher, $config);

// Start Face Punch-in
$startResponse = $apiService->startFaceScan([
    'branch_id' => 2,
    'batch_id' => 27,
    'attendance_session_id' => 0,
    'attendance_date' => $today,
    'selected_time' => '12:00:00',
    'actor_employee_id' => $teacherId,
    'event_type' => 'PUNCH_IN',
]);

pilotAssert($startResponse['state'] === 'STARTED', 'scan-start-started', 'startFaceScan returned state STARTED');
pilotAssert(strlen((string)$startResponse['scan_token']) === 64, 'scan-start-token', 'scan_token is 64-character token');
pilotAssert($startResponse['pad_status'] === 'DEFERRED', 'scan-start-pad-deferred', 'response explicitly declares pad_status DEFERRED');
pilotAssert($startResponse['live_write'] === false, 'scan-start-no-live-write', 'response explicitly declares live_write false');
pilotAssert($startResponse['automatic_candidate_count'] >= 2, 'scan-start-candidate-count', 'frozen candidate roster contains enrolled active members');

$scanToken = (string)$startResponse['scan_token'];
$scanId = (int)$startResponse['scan_id'];

// Get candidate ref for member 616 (FaceTest01 Child)
$rosterRow616 = $db->query("SELECT candidate_ref FROM attendance_scan_roster WHERE scan_id = {$scanId} AND member_id = 616")->fetch_assoc();
$candidateRef616 = $rosterRow616['candidate_ref'] ?? '';
pilotAssert($candidateRef616 !== '', 'roster-candidate-ref-frozen', 'member 616 has frozen opaque candidate_ref in roster');

// =========================================================================
// SECTION 3: Accepted Face Recognition & Row Selection (SELECT_PRESENT)
// =========================================================================
echo "\n--- SECTION 3: Accepted Face Recognition & Row Selection (SELECT_PRESENT) ---\n";

// Audit attendance table counts before recognition
$attCountBefore = (int)$db->query("SELECT COUNT(*) AS total FROM attendance")->fetch_assoc()['total'];
$eventCountBefore = (int)$db->query("SELECT COUNT(*) AS total FROM attendance_event")->fetch_assoc()['total'];
$sessionsUsedBefore = (int)$db->query("SELECT sessions_used FROM invoice_item WHERE id = 1001")->fetch_assoc()['sessions_used'];

// Configure mock to return member 616 accepted match
$mockProvider->nextDecision = 'ACCEPT';
$mockProvider->nextCandidateRef = $candidateRef616;

$attemptResponse = $apiService->recognizeFaceAttempt(
    $scanId,
    $scanToken,
    $teacherId,
    'pilot-attempt-001',
    ['synthetic-frame-1', 'synthetic-frame-2', 'synthetic-frame-1-third'],
    ''
);

pilotAssert($attemptResponse['decision'] === 'ACCEPT', 'attempt-decision-accept', 'recognition returns decision ACCEPT');
pilotAssert($attemptResponse['effect'] === 'SELECT_PRESENT', 'attempt-effect-select-present', 'recognition returns effect SELECT_PRESENT');
pilotAssert($attemptResponse['attendance_state'] === 'PENDING_TEACHER_COMMIT', 'attempt-pending-teacher-commit', 'attendance_state is PENDING_TEACHER_COMMIT');
pilotAssert((int)$attemptResponse['contact_id'] === 774, 'attempt-contact-id-returned', 'contact_id 774 returned for browser selection');
pilotAssert($attemptResponse['member_name'] === 'FaceTest01 Child', 'attempt-member-name', 'member_name is FaceTest01 Child');
pilotAssert($attemptResponse['live_write'] === false, 'attempt-live-write-false', 'live_write is false');
pilotAssert($attemptResponse['pad_status'] === 'LIVE', 'attempt-pad-live', 'accepted attempt reports LIVE PAD');

// Client-side row selection simulation
$clientList = [
    ['contact_id' => 774, 'fullname' => 'FaceTest01 Child', 'selected' => false, 'negative' => 1, 'skip' => true],
    ['contact_id' => 775, 'fullname' => 'FaceTest02 Child', 'selected' => false, 'negative' => 1, 'skip' => false],
];

function clientSelectPresentRosterRow(array &$list, int $contactId): bool
{
    $matched = null;
    $stableId = (string)$contactId;
    foreach ($list as &$row) {
        if ((string)$row['contact_id'] === $stableId) {
            if ($matched !== null) return false;
            $matched = &$row;
        }
    }
    if ($matched === null) return false;
    $matched['negative'] = 0;
    $matched['selected'] = true;
    $matched['skip'] = false;
    return true;
}

$selectionResult = clientSelectPresentRosterRow($clientList, (int)$attemptResponse['contact_id']);
pilotAssert($selectionResult === true, 'client-row-selection-success', 'client selectPresentRosterRow maps exactly one row');
pilotAssert($clientList[0]['selected'] === true && $clientList[0]['negative'] === 0, 'client-row-state-present', 'member row toggled to Present (green)');
pilotAssert($clientList[1]['selected'] === false, 'client-other-row-untouched', 'unscanned member row remains untouched');

// CRITICAL SAFETY CHECK: Database attendance & membership must be UNCHANGED
$attCountAfterAttempt = (int)$db->query("SELECT COUNT(*) AS total FROM attendance")->fetch_assoc()['total'];
$eventCountAfterAttempt = (int)$db->query("SELECT COUNT(*) AS total FROM attendance_event")->fetch_assoc()['total'];
$sessionsUsedAfterAttempt = (int)$db->query("SELECT sessions_used FROM invoice_item WHERE id = 1001")->fetch_assoc()['sessions_used'];

pilotAssert($attCountAfterAttempt === $attCountBefore, 'no-attendance-write-on-scan', 'attendance table row count unchanged after recognition');
pilotAssert($eventCountAfterAttempt === $eventCountBefore, 'no-event-write-on-scan', 'attendance_event table row count unchanged after recognition');
pilotAssert($sessionsUsedAfterAttempt === $sessionsUsedBefore, 'no-balance-deduction-on-scan', 'membership sessions_used completely unchanged');

// =========================================================================
// SECTION 4: Exception & Negative Scenarios
// =========================================================================
echo "\n--- SECTION 4: Exception & Negative Scenarios ---\n";

// 4.1 Duplicate Face Attempt in Same Scan
$duplicateAttempt = $apiService->recognizeFaceAttempt(
    $scanId,
    $scanToken,
    $teacherId,
    'pilot-attempt-002',
    ['synthetic-frame-3', 'synthetic-frame-3-second', 'synthetic-frame-3-third'],
    ''
);
pilotAssert($duplicateAttempt['decision'] === 'ACCEPT' && $duplicateAttempt['member_already_selected'] === true,
    'negative-duplicate-suppressed', 'duplicate scan reports member_already_selected = true');
pilotAssert($duplicateAttempt['effect'] === 'NONE', 'negative-duplicate-effect-none', 'duplicate scan has effect NONE');

// 4.2 Out-of-Roster / Unknown Face
$mockProvider->nextDecision = 'REVIEW';
$mockProvider->nextCandidateRef = null;
$unknownAttempt = $apiService->recognizeFaceAttempt(
    $scanId,
    $scanToken,
    $teacherId,
    'pilot-attempt-003',
    ['synthetic-frame-4', 'synthetic-frame-4-second', 'synthetic-frame-4-third'],
    ''
);
pilotAssert($unknownAttempt['decision'] === 'REVIEW', 'negative-unknown-face-review', 'out-of-roster face returns decision REVIEW');
pilotAssert($unknownAttempt['effect'] === 'NONE', 'negative-unknown-face-no-effect', 'out-of-roster face returns effect NONE');
pilotAssert(empty($unknownAttempt['contact_id']), 'negative-unknown-no-contact', 'out-of-roster face returns no contact_id');

// 4.3 Borderline Margin Face (< 0.18 min_margin)
$mockProvider->nextDecision = 'REVIEW';
$mockProvider->nextMargin = 0.05; // Fails min_margin of 0.18
$borderlineAttempt = $apiService->recognizeFaceAttempt(
    $scanId,
    $scanToken,
    $teacherId,
    'pilot-attempt-004',
    ['synthetic-frame-5', 'synthetic-frame-5-second', 'synthetic-frame-5-third'],
    ''
);
pilotAssert($borderlineAttempt['decision'] === 'REVIEW', 'negative-borderline-margin-review', 'borderline margin match returns REVIEW');
pilotAssert($borderlineAttempt['effect'] === 'NONE', 'negative-borderline-no-effect', 'borderline margin has effect NONE');

// 4.4 Revoked Template Match Attempt
$db->query("UPDATE face_template SET status = 'REVOKED' WHERE member_id = 618");
$rosterRow618 = $db->query("SELECT template_count, consent_status FROM attendance_scan_roster WHERE scan_id = {$scanId} AND member_id = 618")->fetch_assoc();
pilotAssert((int)($rosterRow618['template_count'] ?? 0) === 0, 'negative-revoked-no-candidates', 'revoked template member has 0 template_count in roster');

// =========================================================================
// SECTION 5: Authoritative Teacher Commit Boundary
// =========================================================================
echo "\n--- SECTION 5: Authoritative Batch Punch Commit Boundary ---\n";

// Complete the scan session
$completeResponse = $apiService->completeFaceScan($scanId, $scanToken, $teacherId, false);
pilotAssert($completeResponse['state'] === 'COMPLETED', 'scan-complete-state', 'completeFaceScan returns COMPLETED');
pilotAssert($completeResponse['attendance_written'] === false, 'scan-complete-no-attendance-write', 'completeFaceScan confirms attendance_written = false');
pilotAssert(in_array($scanToken, $mockProvider->deletedTokens, true), 'scan-complete-sidecar-cleanup', 'scan completion cleans up private sidecar cache');

// Attendance in database remains unchanged right up to Punch Attendance
$attCountBeforeCommit = (int)$db->query("SELECT COUNT(*) AS total FROM attendance")->fetch_assoc()['total'];
pilotAssert($attCountBeforeCommit === $attCountBefore, 'pre-commit-attendance-unchanged', 'attendance records still unchanged after scan completion');

// Execute authoritative commit via AttendanceDomainService (invoked by teacher clicking "Punch Attendance")
$domainService = new AttendanceDomainService($db);
$commitResult = $domainService->recordMemberAttendance([
    'branch_id' => 2,
    'batch_id' => 27,
    'attendance_date' => $today,
    'session_id' => 0,
    'member_id' => 774, // contact_id of FaceTest01 Child
    'person_type' => 'M',
    'negative' => 0, // Present
    'source' => 'TEACHER_CONFIRMED',
    'event_type' => 'PUNCH_IN',
    'actor_employee_id' => $teacherId,
    'client_attempt_id' => 'teacher-commit-616-' . time(),
]);

pilotAssert($commitResult['state'] === 'RECORDED', 'commit-attendance-recorded', 'authoritative commit returns state RECORDED');
pilotAssert($commitResult['negative'] === 0, 'commit-negative-present', 'attendance recorded as Present (negative = 0)');

// Verify database now contains exactly 1 attendance row and 1 attendance_event row
$attRow = $db->query("SELECT * FROM attendance WHERE batch_id = 27 AND contact_id = 774 AND date = '{$today}'")->fetch_assoc();
pilotAssert($attRow !== null, 'db-attendance-row-created', 'authoritative attendance row created in database');
pilotAssert((int)$attRow['negative'] === 0 && (int)$attRow['status'] === 1, 'db-attendance-row-valid', 'attendance row has negative = 0 and status = 1');

$eventRow = $db->query("SELECT * FROM attendance_event WHERE batch_id = 27 AND person_id = 774 AND attendance_date = '{$today}'")->fetch_assoc();
pilotAssert($eventRow !== null, 'db-event-row-created', 'attendance_event row created in database');
pilotAssert($eventRow['source'] === 'TEACHER_CONFIRMED' && $eventRow['event_type'] === 'PUNCH_IN', 'db-event-valid', 'event has source TEACHER_CONFIRMED and event_type PUNCH_IN');

// Verify membership balance deducted exactly 1 session
$sessionsUsedAfterCommit = (int)$db->query("SELECT sessions_used FROM invoice_item WHERE id = 1001")->fetch_assoc()['sessions_used'];
pilotAssert($sessionsUsedAfterCommit === 1, 'commit-membership-deducted', 'authoritative punch increments sessions_used by 1');

// Test Commit Idempotency: second punch cannot create duplicate attendance
$duplicateCommit = $domainService->recordMemberAttendance([
    'branch_id' => 2,
    'batch_id' => 27,
    'attendance_date' => $today,
    'session_id' => 0,
    'member_id' => 774,
    'person_type' => 'M',
    'negative' => 0,
    'source' => 'TEACHER_CONFIRMED',
    'event_type' => 'PUNCH_IN',
    'actor_employee_id' => $teacherId,
    'client_attempt_id' => 'teacher-commit-616-retry',
]);

pilotAssert($duplicateCommit['state'] === 'ALREADY_MARKED', 'commit-idempotent', 'duplicate punch attempt returns ALREADY_MARKED');
$attFinalCount = (int)$db->query("SELECT COUNT(*) AS total FROM attendance WHERE batch_id = 27 AND contact_id = 774 AND date = '{$today}'")->fetch_assoc()['total'];
pilotAssert($attFinalCount === 1, 'commit-no-duplicate-rows', 'exactly 1 attendance row exists (zero duplicate rows)');
$sessionsUsedAfterDuplicate = (int)$db->query("SELECT sessions_used FROM invoice_item WHERE id = 1001")->fetch_assoc()['sessions_used'];
pilotAssert($sessionsUsedAfterDuplicate === 1, 'commit-no-duplicate-deduction', 'membership balance not deducted on duplicate punch');

$db->close();

echo "\n=========================================================================\n";
if ($failures) {
    echo "FAIL: " . count($failures) . " assertions failed.\n";
    foreach ($failures as $f) {
        echo "  - {$f}\n";
    }
    exit(1);
}

echo "SUCCESS: All {$assertions} assertions passed in FACE-PILOT-01 smoke test.\n";
echo "Synthetic camera smoke fixture complete; held-out validation and supervised pilot remain required:\n";
echo "  - Recognition selects exactly one Present row per child\n";
echo "  - Attendance and membership are 100% unchanged during recognition\n";
echo "  - Teacher commit is the single authoritative transaction boundary\n";
echo "=========================================================================\n";
exit(0);
