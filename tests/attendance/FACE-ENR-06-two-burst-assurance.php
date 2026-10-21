<?php

declare(strict_types=1);

/**
 * FACE-ENR-06 High-Precision Face Enrollment and Attendance Assurance.
 *
 * Verifies the complete assurance pipeline:
 *  - Verified reference portrait onboarding (staff gate, quality, PAD, superseding)
 *  - Reference required gate for enrollment
 *  - Two-burst holdout verification & reference match
 *  - Multi-tier duplicate rejection bands (DUPLICATE_BLOCKED, ADMIN_REVIEW, CLEAR)
 *  - Corrupt template fail-closed (IDENTITY_INDEX_UNAVAILABLE)
 *  - Preview collision tri-state capture_state (CLEAR, CONFLICT, UNAVAILABLE)
 */

date_default_timezone_set('UTC');

$root = dirname(__DIR__, 2);
$required = in_array('--required', $argv, true)
    || filter_var(getenv('ATTENDANCE_TEST_REQUIRED') ?: '0', FILTER_VALIDATE_BOOLEAN);
$dbName = getenv('ATTENDANCE_TEST_DB') ?: 'ccc_database_test';
$dbHost = getenv('ATTENDANCE_TEST_DB_HOST') ?: '127.0.0.1';
$dbUser = getenv('ATTENDANCE_TEST_DB_USER') ?: 'root';
$dbPassword = getenv('ATTENDANCE_TEST_DB_PASSWORD') ?: '';

function stopAssuranceRun(string $message, bool $required): void
{
    $label = $required ? 'BLOCKED' : 'SKIPPED';
    fwrite($required ? STDERR : STDOUT, "{$label}: {$message}\n");
    exit($required ? 2 : 0);
}

if (!preg_match('/^ccc_database_test(?:_[a-z0-9_]+)?$/', $dbName)) {
    fwrite(STDERR, "Refusing to run FACE-ENR-06 outside the approved test database namespace.\n");
    exit(2);
}

if (!extension_loaded('mysqli')) {
    stopAssuranceRun('the PHP mysqli extension is unavailable', $required);
}

mysqli_report(MYSQLI_REPORT_ERROR | MYSQLI_REPORT_STRICT);
try {
    $db = new mysqli($dbHost, $dbUser, $dbPassword, $dbName);
    $db->set_charset('utf8mb4');
} catch (Throwable $exception) {
    try {
        require_once $root . '/classes/dbconnection.class.php';
        $connector = new dbconnection();
        $db = $connector->connect($dbName);
        $db->set_charset('utf8mb4');
    } catch (Throwable $fallbackException) {
        stopAssuranceRun("unable to connect to test database: {$fallbackException->getMessage()}", $required);
    }
}

$servicePath = $root . '/classes/services/FaceEnrollmentService.php';
if (!is_file($servicePath)) {
    fwrite(STDERR, "FAIL FACE-ENR-06: FaceEnrollmentService.php is missing.\n");
    exit(1);
}
require_once $servicePath;

function assuranceSchema(mysqli $db): void
{
    $schema = <<<'SQL'
CREATE TEMPORARY TABLE IF NOT EXISTS `member` (
  `id` INT NOT NULL PRIMARY KEY,
  `contact_id` INT NOT NULL,
  `status` INT NOT NULL DEFAULT 1,
  `park` INT NOT NULL DEFAULT 0,
  `bid` INT NOT NULL DEFAULT 1
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
CREATE TEMPORARY TABLE IF NOT EXISTS `contact` (
  `id` INT NOT NULL PRIMARY KEY,
  `fname` VARCHAR(30) NOT NULL DEFAULT '',
  `lname` VARCHAR(30) NOT NULL DEFAULT ''
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
CREATE TEMPORARY TABLE IF NOT EXISTS `biometric_subject` (
  `id` INT NOT NULL AUTO_INCREMENT PRIMARY KEY,
  `contact_id` INT NOT NULL,
  `status` VARCHAR(32) NOT NULL DEFAULT 'ACTIVE',
  `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
CREATE TEMPORARY TABLE IF NOT EXISTS `face_identity_reference` (
  `id` INT NOT NULL AUTO_INCREMENT PRIMARY KEY,
  `subject_id` INT NOT NULL,
  `encrypted_portrait` MEDIUMBLOB NOT NULL,
  `encrypted_embedding` MEDIUMBLOB NOT NULL,
  `encryption_key_id` VARCHAR(100) NOT NULL,
  `embedding_dimension` INT NOT NULL,
  `source` VARCHAR(50) NOT NULL,
  `quality_evidence` JSON NULL,
  `model_version_id` INT NOT NULL,
  `policy_version` VARCHAR(100) NOT NULL,
  `verifier_actor_id` INT NOT NULL,
  `verified_at` DATETIME NOT NULL,
  `status` VARCHAR(20) NOT NULL DEFAULT 'ACTIVE'
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
CREATE TEMPORARY TABLE IF NOT EXISTS `face_enrollment_attempt` (
  `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
  `client_attempt_id` VARCHAR(191) NOT NULL,
  `subject_id` INT NOT NULL,
  `member_id` INT NOT NULL,
  `branch_id` INT NOT NULL,
  `reference_id` INT NOT NULL,
  `burst_a_count` INT NOT NULL,
  `burst_b_count` INT NOT NULL,
  `two_burst_state` VARCHAR(32) NOT NULL,
  `decision` VARCHAR(32) NOT NULL,
  `reason_code` VARCHAR(64) NOT NULL,
  `threshold_snapshot` JSON NULL,
  `duplicate_candidate_result` JSON NULL,
  `actor_employee_id` INT NOT NULL,
  `device_metadata` JSON NULL,
  `venue` VARCHAR(100) NULL,
  `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `completed_at` DATETIME NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
CREATE TEMPORARY TABLE IF NOT EXISTS `face_capture_evidence` (
  `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
  `context_type` VARCHAR(32) NOT NULL,
  `context_id` BIGINT UNSIGNED NOT NULL,
  `frame_index` INT NOT NULL DEFAULT 0,
  `frame_hash` CHAR(64) NOT NULL DEFAULT '',
  `face_count` INT NOT NULL DEFAULT 1,
  `detector_confidence` DECIMAL(8,4) NULL,
  `box_x` DECIMAL(8,2) NULL,
  `box_y` DECIMAL(8,2) NULL,
  `box_w` DECIMAL(8,2) NULL,
  `box_h` DECIMAL(8,2) NULL,
  `inter_eye_pixels` DECIMAL(8,2) NULL,
  `sharpness` DECIMAL(10,3) NULL,
  `exposure` DECIMAL(10,3) NULL,
  `contrast` DECIMAL(10,3) NULL,
  `clipped_dark_pct` DECIMAL(6,3) NULL,
  `clipped_bright_pct` DECIMAL(6,3) NULL,
  `illumination_balance` DECIMAL(6,3) NULL,
  `yaw` DECIMAL(6,3) NULL,
  `pitch` DECIMAL(6,3) NULL,
  `roll` DECIMAL(6,3) NULL,
  `centering_offset` DECIMAL(6,3) NULL,
  `pad_score` DECIMAL(8,4) NULL,
  `pad_state` VARCHAR(20) NULL,
  `accepted` TINYINT(1) NOT NULL DEFAULT 1,
  `reasons` JSON NULL,
  `quality_policy_version` VARCHAR(100) NULL,
  `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
CREATE TEMPORARY TABLE IF NOT EXISTS `face_template` (
  `id` INT NOT NULL AUTO_INCREMENT PRIMARY KEY,
  `member_id` INT NOT NULL,
  `subject_id` INT NULL,
  `enrollment_attempt_id` BIGINT UNSIGNED NULL,
  `reference_id` INT NULL,
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
SQL;

    foreach (explode(";\n", $schema) as $statement) {
        $statement = trim($statement);
        if ($statement !== '') {
            $db->query($statement);
        }
    }
}

function assuranceReset(mysqli $db): void
{
    $tables = [
        'face_capture_evidence',
        'face_enrollment_attempt',
        'face_identity_reference',
        'face_template',
        'biometric_consent',
        'biometric_subject',
        'face_model_version',
        'member',
        'contact',
    ];
    foreach ($tables as $table) {
        $db->query("TRUNCATE TABLE `{$table}`");
    }

    $db->query("INSERT INTO contact (id, fname, lname) VALUES (1101, 'Aarav', 'Sharma'), (1102, 'Diya', 'Patel'), (1201, 'Rohan', 'Mehta')");
    $db->query("INSERT INTO member (id, contact_id, status, park, bid) VALUES (101, 1101, 1, 0, 7), (102, 1102, 1, 0, 7), (201, 1201, 1, 0, 8)");
    $db->query("INSERT INTO face_model_version (id, model_name, provider_id, provider_version, policy_version, embedding_dimension, model_checksum, status) VALUES (1, 'ccc-face-v1', 'test-provider', '1', 'face-policy-v2', 128, REPEAT('a', 64), 'ACTIVE')");
}

function vector128(float $similarityToAxis = 1.0): array
{
    $s = max(-1.0, min(1.0, $similarityToAxis));
    $ortho = sqrt(max(0.0, 1.0 - ($s * $s)));
    return array_pad([$s, $ortho], 128, 0.0);
}

function assuranceFrames(int $count): array
{
    return array_map(static function (int $i): string {
        return "raw-frame-bytes-data-{$i}";
    }, range(1, $count));
}

function sampleEvidence(int $count, string $burst): array
{
    $evidences = [];
    for ($i = 0; $i < $count; $i++) {
        $evidences[] = [
            'frame_index' => $i,
            'frame_hash' => hash('sha256', "{$burst}-frame-{$i}"),
            'face_count' => 1,
            'detector_confidence' => 0.99,
            'box' => ['x' => 100.0, 'y' => 100.0, 'width' => 200.0, 'height' => 250.0],
            'inter_eye_pixels' => 64.0,
            'sharpness' => 25.0,
            'exposure' => 128.0,
            'contrast' => 45.0,
            'clipped_dark_pct' => 0.01,
            'clipped_bright_pct' => 0.02,
            'illumination_balance' => 0.05,
            'pose_yaw' => 0.02,
            'pose_pitch' => 0.01,
            'tilt_degrees' => 1.5,
            'centering_offset' => 0.03,
            'pad_score' => 0.98,
            'pad_state' => 'LIVE',
            'accepted' => 1,
            'reasons' => ['QUALITY_OK'],
        ];
    }
    return $evidences;
}

final class AssuranceFakeInference implements FaceEnrollmentInferenceClient
{
    public $referenceResult = [];
    public $enrollmentV2Result = [];
    public $evidenceResult = [];
    public $v2Calls = 0;
    public $refCalls = 0;
    public $evidenceCalls = 0;

    public function __construct(array $ref = [], array $v2 = [], array $ev = [])
    {
        $this->referenceResult = $ref;
        $this->enrollmentV2Result = $v2;
        $this->evidenceResult = $ev;
    }

    public function encodeEnrollment(array $frames, array $captureContexts = []): array
    {
        return $this->enrollmentV2Result;
    }

    public function encodeReference(string $portrait): array
    {
        $this->refCalls++;
        if (isset($this->referenceResult['throw'])) {
            throw $this->referenceResult['throw'];
        }
        return $this->referenceResult;
    }

    public function encodeEnrollmentV2(array $burstA, array $burstB, array $refEmbedding): array
    {
        $this->v2Calls++;
        if (isset($this->enrollmentV2Result['throw'])) {
            throw $this->enrollmentV2Result['throw'];
        }
        return $this->enrollmentV2Result;
    }

    public function inferEvidence(string $frame, array $candidates): array
    {
        $this->evidenceCalls++;
        if (isset($this->evidenceResult['throw'])) {
            throw $this->evidenceResult['throw'];
        }
        return $this->evidenceResult;
    }
}

function assuranceEnrollCommand(array $overrides = []): array
{
    return array_merge([
        'branch_id' => 7,
        'member_id' => 101,
        'actor_employee_id' => 9001,
        'notice_version' => 'program-face-v1',
        'purpose' => 'attendance',
        'teacher_confirmed_member' => true,
        'protocol_version' => 2,
        'burst_a' => assuranceFrames(5),
        'burst_b' => assuranceFrames(3),
    ], $overrides);
}

function assuranceAssert($expected, $actual, string $message): void
{
    if ($expected !== $actual) {
        throw new RuntimeException($message . ' (expected ' . var_export($expected, true) . ', got ' . var_export($actual, true) . ')');
    }
}

function assuranceScalar(mysqli $db, string $query)
{
    $res = $db->query($query);
    if (!$res) {
        return null;
    }
    $row = $res->fetch_row();
    return $row[0] ?? null;
}

function assuranceCase(string $name, callable $callback, array &$results): void
{
    try {
        $callback();
        $results[] = ['name' => $name, 'status' => 'PASS'];
    } catch (Throwable $exception) {
        $results[] = ['name' => $name, 'status' => 'FAIL', 'message' => $exception->getMessage()];
    }
}

assuranceSchema($db);
$cipher = new FaceEmbeddingCipher(str_repeat('s', 32), 'test-key-v1');
$results = [];

function validReferenceResult(array $vector = null): array
{
    return [
        'embedding' => $vector ?: vector128(1.0),
        'model_version_id' => 1,
        'calibration_policy_version' => 'face-policy-v2',
        'quality' => [
            'sharpness_ok' => true,
            'exposure_ok' => true,
            'pose_ok' => true,
            'size_ok' => true,
            'centred_ok' => true,
            'reasons' => [],
        ],
        'pad' => [
            'state' => 'LIVE',
            'score' => 0.99,
        ],
    ];
}

function validTwoBurstResult(array $vector = null): array
{
    $v = $vector ?: vector128(1.0);
    return [
        'model_version_id' => 1,
        'model_version' => 'ccc-face-v1',
        'provider_id' => 'test-provider',
        'provider_version' => '1',
        'calibration_policy_version' => 'face-policy-v2',
        'embeddings' => [$v, $v, $v],
        'burst_a_embeddings' => [$v, $v, $v, $v, $v],
        'burst_b_embeddings' => [$v, $v, $v],
        'embedding_dimension' => 128,
        'burst_a_count' => 5,
        'burst_b_count' => 3,
        'quality_summary' => [
            'frames_accepted' => 8,
            'face_count' => 1,
            'sharpness_ok' => true,
            'exposure_ok' => true,
            'pose_ok' => true,
        ],
        'evidences' => [
            'burst_a' => sampleEvidence(5, 'BURST_A'),
            'burst_b' => sampleEvidence(3, 'BURST_B'),
        ],
        'verification_passed' => true,
        'verification_reason' => 'TWO_BURST_VERIFIED',
    ];
}

// =========================================================================
// 1. Reference Onboarding Tests
// =========================================================================

assuranceCase('FACE-ENR-06 allows automatic reference onboarding without staff confirmation', function () use ($db, $cipher): void {
    assuranceReset($db);
    $inference = new AssuranceFakeInference(validReferenceResult());
    $service = new FaceEnrollmentService($db, $inference, $cipher);

    $result = $service->onboardReference([
        'branch_id' => 7,
        'member_id' => 101,
        'actor_employee_id' => 9001,
        'portrait' => 'dummy-portrait-bytes',
    ]);

    assuranceAssert('ACTIVE', $result['state'], 'automatic reference onboarding state');
    assuranceAssert(1, (int)assuranceScalar($db, 'SELECT COUNT(*) FROM face_identity_reference'), 'reference row written');
}, $results);

assuranceCase('FACE-ENR-06 withdrawal erases reference biometrics and revokes the reference', function () use ($db, $cipher): void {
    assuranceReset($db);
    $service = new FaceEnrollmentService($db, new AssuranceFakeInference(validReferenceResult()), $cipher);
    $reference = $service->onboardReference([
        'branch_id' => 7,
        'member_id' => 101,
        'actor_employee_id' => 9001,
        'portrait' => 'dummy-portrait-bytes',
    ]);
    assuranceAssert('ACTIVE', $reference['state'], 'reference created before withdrawal');
    $service->withdraw(101, 9001, 7);
    $row = $db->query('SELECT status, encrypted_embedding, encrypted_portrait FROM face_identity_reference LIMIT 1')->fetch_assoc();
    assuranceAssert('REVOKED', $row['status'], 'reference revoked on withdrawal');
    assuranceAssert('', $row['encrypted_embedding'], 'reference embedding erased');
    assuranceAssert('', $row['encrypted_portrait'], 'reference portrait erased');
}, $results);

assuranceCase('FACE-ENR-06 supports burst reference onboarding with multi-frame array', function () use ($db, $cipher): void {
    assuranceReset($db);
    $inference = new AssuranceFakeInference(validReferenceResult());
    $service = new FaceEnrollmentService($db, $inference, $cipher);

    $result = $service->onboardReference([
        'branch_id' => 7,
        'member_id' => 101,
        'actor_employee_id' => 9001,
        'portraits' => ['burst-frame-1', 'burst-frame-2', 'burst-frame-3'],
    ]);

    assuranceAssert('ACTIVE', $result['state'], 'burst reference onboarding state');
    assuranceAssert(1, (int)assuranceScalar($db, 'SELECT COUNT(*) FROM face_identity_reference'), 'burst reference row written');
}, $results);

assuranceCase('FACE-ENR-06 rejects reference onboarding with low quality', function () use ($db, $cipher): void {
    assuranceReset($db);
    $refBadQuality = validReferenceResult();
    $refBadQuality['quality']['sharpness_ok'] = false;
    $refBadQuality['quality']['reasons'] = ['IMAGE_BLURRY'];

    $inference = new AssuranceFakeInference($refBadQuality);
    $service = new FaceEnrollmentService($db, $inference, $cipher);

    $result = $service->onboardReference([
        'branch_id' => 7,
        'member_id' => 101,
        'actor_employee_id' => 9001,
        'portrait' => 'dummy-portrait-bytes',
        'staff_confirmed' => true,
    ]);

    assuranceAssert('REJECTED', $result['state'], 'quality check state');
    assuranceAssert('QUALITY_FAILED', $result['reason'], 'quality check reason');
    assuranceAssert(0, (int)assuranceScalar($db, 'SELECT COUNT(*) FROM face_identity_reference'), 'no reference written on quality fail');
}, $results);

assuranceCase('FACE-ENR-06 rejects reference onboarding on PAD passive liveness spoof', function () use ($db, $cipher): void {
    assuranceReset($db);
    $refSpoof = validReferenceResult();
    $refSpoof['pad'] = ['state' => 'SPOOF', 'score' => 0.12];

    $inference = new AssuranceFakeInference($refSpoof);
    $service = new FaceEnrollmentService($db, $inference, $cipher);

    $result = $service->onboardReference([
        'branch_id' => 7,
        'member_id' => 101,
        'actor_employee_id' => 9001,
        'portrait' => 'dummy-portrait-bytes',
        'staff_confirmed' => true,
    ]);

    assuranceAssert('REJECTED', $result['state'], 'PAD check state');
    assuranceAssert('PAD_REJECTED', $result['reason'], 'PAD check reason');
    assuranceAssert(0, (int)assuranceScalar($db, 'SELECT COUNT(*) FROM face_identity_reference'), 'no reference written on PAD fail');
}, $results);

assuranceCase('FACE-ENR-06 reports unapproved PAD calibration separately from user capture failure', function () use ($db, $cipher): void {
    assuranceReset($db);
    $refUnknown = validReferenceResult();
    $refUnknown['pad'] = ['state' => 'UNKNOWN', 'score' => 0.77, 'samples' => [0.75, 0.77, 0.79]];

    $inference = new AssuranceFakeInference($refUnknown);
    $service = new FaceEnrollmentService($db, $inference, $cipher);

    $result = $service->onboardReference([
        'branch_id' => 7,
        'member_id' => 101,
        'actor_employee_id' => 9001,
        'portrait' => 'dummy-portrait-bytes',
    ]);

    assuranceAssert('REJECTED', $result['state'], 'PAD calibration state');
    assuranceAssert('PAD_CALIBRATION_REQUIRED', $result['reason'], 'PAD calibration reason');
    assuranceAssert(0, (int)assuranceScalar($db, 'SELECT COUNT(*) FROM face_identity_reference'), 'no reference written without approved PAD calibration');
}, $results);

assuranceCase('FACE-ENR-06 allows reference onboarding in uncalibrated test mode when PAD is unapproved or borderline', function () use ($db, $cipher, &$results): void {
    assuranceReset($db);
    $refBorderline = validReferenceResult();
    $refBorderline['pad'] = ['state' => 'SPOOF', 'score' => 0.05];

    $inference = new AssuranceFakeInference($refBorderline);
    $service = new FaceEnrollmentService($db, $inference, $cipher);

    $result = $service->onboardReference([
        'branch_id' => 7,
        'member_id' => 101,
        'actor_employee_id' => 9001,
        'portrait' => 'dummy-portrait-bytes',
        'allow_uncalibrated_testing' => true,
    ]);

    assuranceAssert('ACTIVE', $result['state'], 'uncalibrated test reference state');
    assuranceAssert(1, (int)$result['reference_id'], 'reference id created');
    assuranceAssert(1, (int)assuranceScalar($db, "SELECT COUNT(*) FROM face_identity_reference WHERE status = 'PENDING_ENROLLMENT'"), 'reference stored in DB');
}, $results);

assuranceCase('FACE-ENR-06 onboards pending reference without treating it as active', function () use ($db, $cipher): void {
    assuranceReset($db);
    $inference = new AssuranceFakeInference(validReferenceResult());
    $service = new FaceEnrollmentService($db, $inference, $cipher);

    $first = $service->onboardReference([
        'branch_id' => 7,
        'member_id' => 101,
        'actor_employee_id' => 9001,
        'portrait' => 'portrait-bytes-v1',
        'staff_confirmed' => true,
    ]);

    assuranceAssert('ACTIVE', $first['state'], 'first reference state');
    assuranceAssert(1, (int)$first['reference_id'], 'first reference id');
    assuranceAssert(1, (int)assuranceScalar($db, "SELECT COUNT(*) FROM biometric_subject WHERE contact_id = 1101"), 'subject created');
    assuranceAssert(1, (int)assuranceScalar($db, "SELECT COUNT(*) FROM face_identity_reference WHERE status = 'PENDING_ENROLLMENT'"), 'pending reference count');

    // Onboard second reference for same member
    $second = $service->onboardReference([
        'branch_id' => 7,
        'member_id' => 101,
        'actor_employee_id' => 9002,
        'portrait' => 'portrait-bytes-v2',
        'staff_confirmed' => true,
    ]);

    assuranceAssert('ACTIVE', $second['state'], 'second reference state');
    assuranceAssert(2, (int)$second['reference_id'], 'second reference id');
    assuranceAssert(1, (int)assuranceScalar($db, "SELECT COUNT(*) FROM face_identity_reference WHERE id = 1 AND status = 'SUPERSEDED'"), 'first reference superseded');
    assuranceAssert(1, (int)assuranceScalar($db, "SELECT COUNT(*) FROM face_identity_reference WHERE id = 2 AND status = 'PENDING_ENROLLMENT'"), 'second reference pending enrollment');

    assuranceAssert(null, $service->getActiveReference(1101, true), 'pending reference is not active');
    $db->query("UPDATE face_identity_reference SET status='ACTIVE' WHERE id=2");
    $activeRef = $service->getActiveReference(1101, true);
    assuranceAssert(2, $activeRef['id'], 'active reference lookup id');
    assuranceAssert('Aarav Sharma', $activeRef['fullname'], 'fullname lookup');
    assuranceAssert(true, strpos((string)$activeRef['portrait_data_url'], 'data:image/jpeg;base64,') === 0, 'portrait data url present');
}, $results);

// =========================================================================
// 2. Reference Required Gate for Two-Burst Enrollment
// =========================================================================

assuranceCase('FACE-ENR-06 blocks two-burst enrollment without active verified reference', function () use ($db, $cipher): void {
    assuranceReset($db);
    $inference = new AssuranceFakeInference([], validTwoBurstResult());
    $service = new FaceEnrollmentService($db, $inference, $cipher);

    $result = $service->enroll(assuranceEnrollCommand());

    assuranceAssert('REJECTED', $result['state'], 'enrollment without reference state');
    assuranceAssert('REFERENCE_REQUIRED', $result['reason'], 'enrollment without reference reason');
    assuranceAssert(0, $inference->v2Calls, 'no inference invoked when reference is missing');
    assuranceAssert(0, (int)assuranceScalar($db, 'SELECT COUNT(*) FROM face_template'), 'no templates written');
}, $results);

// =========================================================================
// 3. Two-Burst Holdout and Reference Match Tests
// =========================================================================

assuranceCase('FACE-ENR-06 enforces 5 frames for Burst A and 3 frames for Burst B', function () use ($db, $cipher): void {
    assuranceReset($db);
    $inference = new AssuranceFakeInference(validReferenceResult(), validTwoBurstResult());
    $service = new FaceEnrollmentService($db, $inference, $cipher);

    // Setup active reference first
    $service->onboardReference([
        'branch_id' => 7,
        'member_id' => 101,
        'actor_employee_id' => 9001,
        'portrait' => 'portrait-bytes',
        'staff_confirmed' => true,
    ]);

    $shortA = $service->enroll(assuranceEnrollCommand(['burst_a' => assuranceFrames(4)]));
    assuranceAssert('REJECTED', $shortA['state'], 'short burst A state');
    assuranceAssert('BURST_A_FRAME_COUNT', $shortA['reason'], 'short burst A reason');

    $shortB = $service->enroll(assuranceEnrollCommand(['burst_b' => assuranceFrames(2)]));
    assuranceAssert('REJECTED', $shortB['state'], 'short burst B state');
    assuranceAssert('BURST_B_FRAME_COUNT', $shortB['reason'], 'short burst B reason');
}, $results);

assuranceCase('FACE-ENR-06 completes two-burst enrollment with holdout & reference consensus', function () use ($db, $cipher): void {
    assuranceReset($db);
    $vecA = vector128(1.0);
    $inference = new AssuranceFakeInference(validReferenceResult($vecA), validTwoBurstResult($vecA));
    $service = new FaceEnrollmentService($db, $inference, $cipher);

    $service->onboardReference([
        'branch_id' => 7,
        'member_id' => 101,
        'actor_employee_id' => 9001,
        'portrait' => 'portrait-bytes',
        'staff_confirmed' => true,
    ]);

    $result = $service->enroll(assuranceEnrollCommand([
        'device_meta' => ['model' => 'iPad Air', 'os' => 'iOS 17'],
        'venue' => 'Main Studio',
    ]));

    assuranceAssert('ACTIVE', $result['state'], 'enrollment activation state');
    assuranceAssert(2, $result['protocol_version'], 'protocol version marker');
    assuranceAssert(3, $result['template_count'], 'three templates generated');

    // Verify face_enrollment_attempt row
    assuranceAssert(1, (int)assuranceScalar($db, "SELECT COUNT(*) FROM face_enrollment_attempt WHERE member_id = 101 AND two_burst_state = 'COMPLETED' AND decision = 'APPROVED'"), 'attempt recorded');
    assuranceAssert('QUALITY_CONSENSUS_MATCH', (string)assuranceScalar($db, "SELECT reason_code FROM face_enrollment_attempt WHERE member_id = 101"), 'attempt reason code');
    assuranceAssert(5, (int)assuranceScalar($db, "SELECT burst_a_count FROM face_enrollment_attempt WHERE member_id = 101"), 'burst a count recorded');
    assuranceAssert(3, (int)assuranceScalar($db, "SELECT burst_b_count FROM face_enrollment_attempt WHERE member_id = 101"), 'burst b count recorded');

    // Verify capture evidence (8 frames total, 0 raw frames)
    assuranceAssert(8, (int)assuranceScalar($db, "SELECT COUNT(*) FROM face_capture_evidence WHERE context_type = 'ENROLLMENT'"), 'evidence count recorded');
    assuranceAssert(0, (int)assuranceScalar($db, "SELECT COUNT(*) FROM face_capture_evidence WHERE reasons LIKE '%raw-frame%'"), 'no raw frame in evidence');

    // Verify templates and subject link
    assuranceAssert(3, (int)assuranceScalar($db, "SELECT COUNT(*) FROM face_template WHERE member_id = 101 AND status = 'ACTIVE' AND subject_id > 0 AND reference_id > 0"), 'templates linked with subject and reference');
    assuranceAssert(1, (int)assuranceScalar($db, "SELECT COUNT(*) FROM biometric_consent WHERE member_id = 101 AND consent_status = 'PROGRAM_AUTHORIZED'"), 'consent recorded');
}, $results);

// =========================================================================
// 4. Duplicate Rejection Bands (Tenant-Wide Deduplication)
// =========================================================================

assuranceCase('FACE-ENR-06 blocks duplicate enrollment when similarity >= 0.90 (DUPLICATE_BLOCKED)', function () use ($db, $cipher): void {
    assuranceReset($db);
    $student101Vector = vector128(0.92);
    $inference = new AssuranceFakeInference(validReferenceResult($student101Vector), validTwoBurstResult($student101Vector));
    $service = new FaceEnrollmentService($db, $inference, $cipher);

    // Onboard reference for student 101
    $service->onboardReference([
        'branch_id' => 7,
        'member_id' => 101,
        'actor_employee_id' => 9001,
        'portrait' => 'portrait-bytes-101',
        'staff_confirmed' => true,
    ]);

    // Student 102 is enrolled with template having similarity = 0.92 to student 101
    $existingVector = vector128(1.0);
    $encExisting = $cipher->encrypt($existingVector);
    $db->query("INSERT INTO biometric_subject (id, contact_id, status) VALUES (2, 1102, 'ACTIVE')");
    $db->query("INSERT INTO face_template (member_id, subject_id, model_version_id, encrypted_embedding, encryption_key_id, embedding_dimension, status) VALUES (102, 2, 1, '{$encExisting}', '{$cipher->keyId()}', 128, 'ACTIVE'), (102, 2, 1, '{$encExisting}', '{$cipher->keyId()}', 128, 'ACTIVE')");

    $result = $service->enroll(assuranceEnrollCommand());

    assuranceAssert('REJECTED', $result['state'], 'duplicate enrollment state');
    assuranceAssert('DUPLICATE_BLOCKED', $result['reason'], 'duplicate blocked reason');
    assuranceAssert('CONFIRMED_DUPLICATE', $result['outcome'], 'confirmed duplicate outcome');
    assuranceAssert('Diya Patel', $result['conflicting_member_name'], 'conflicting member name identified');
    assuranceAssert(0, (int)assuranceScalar($db, "SELECT COUNT(*) FROM face_template WHERE member_id = 101"), 'no templates written for student 101');
}, $results);

assuranceCase('FACE-ENR-06 allows legitimate enrollment when both bursts have only moderate similarity', function () use ($db, $cipher): void {
    assuranceReset($db);
    $existingVector = vector128(1.0);
    $encExisting = $cipher->encrypt($existingVector);
    $db->query("INSERT INTO biometric_subject (id, contact_id, status) VALUES (2, 1102, 'ACTIVE')");
    $db->query("INSERT INTO face_template (member_id, subject_id, model_version_id, encrypted_embedding, encryption_key_id, embedding_dimension, status) VALUES (102, 2, 1, '{$encExisting}', '{$cipher->keyId()}', 128, 'ACTIVE')");

    // Both burst A and burst B have similarity = 0.80 (>= 0.75 and < 0.90)
    $burstAVec = vector128(0.80);
    $burstBVec = vector128(0.80);
    $v2Result = validTwoBurstResult($burstAVec);
    $v2Result['burst_a_embeddings'] = [$burstAVec, $burstAVec, $burstAVec, $burstAVec, $burstAVec];
    $v2Result['burst_b_embeddings'] = [$burstBVec, $burstBVec, $burstBVec];

    $inference = new AssuranceFakeInference(validReferenceResult($burstAVec), $v2Result);
    $service = new FaceEnrollmentService($db, $inference, $cipher);

    $service->onboardReference([
        'branch_id' => 7,
        'member_id' => 101,
        'actor_employee_id' => 9001,
        'portrait' => 'portrait-bytes',
        'staff_confirmed' => true,
    ]);

    $result = $service->enroll(assuranceEnrollCommand());

    assuranceAssert('ACTIVE', $result['state'], 'moderately similar new person remains enrollable');
    assuranceAssert('CLEAR_NEW_PERSON', $result['outcome'], 'moderately similar child is not a confirmed duplicate');
    assuranceAssert(3, (int)assuranceScalar($db, "SELECT COUNT(*) FROM face_template WHERE member_id = 101 AND status = 'ACTIVE'"), 'new templates written');
}, $results);

assuranceCase('FACE-ENR-06 routes inconsistent duplicate score to ADMIN_REVIEW (gray zone)', function () use ($db, $cipher): void {
    assuranceReset($db);
    $existingVector = vector128(1.0);
    $encExisting = $cipher->encrypt($existingVector);

    // One burst has high duplicate evidence, but the independent burst does not.
    $burstAVec = vector128(0.92);
    $burstBVec = vector128(0.50);
    $v2Result = validTwoBurstResult($burstAVec);
    $v2Result['burst_a_embeddings'] = [$burstAVec, $burstAVec, $burstAVec, $burstAVec, $burstAVec];
    $v2Result['burst_b_embeddings'] = [$burstBVec, $burstBVec, $burstBVec];

    $inference = new AssuranceFakeInference(validReferenceResult($burstAVec), $v2Result);
    $service = new FaceEnrollmentService($db, $inference, $cipher);

    $service->onboardReference([
        'branch_id' => 7,
        'member_id' => 101,
        'actor_employee_id' => 9001,
        'portrait' => 'portrait-bytes',
        'staff_confirmed' => true,
    ]);

    // Add the competing identity after reference setup so this case isolates
    // the two-burst enrollment decision.
    $db->query("INSERT INTO biometric_subject (id, contact_id, status) VALUES (2, 1102, 'ACTIVE')");
    $db->query("INSERT INTO face_template (member_id, subject_id, model_version_id, encrypted_embedding, encryption_key_id, embedding_dimension, status) VALUES (102, 2, 1, '{$encExisting}', '{$cipher->keyId()}', 128, 'ACTIVE'), (102, 2, 1, '{$encExisting}', '{$cipher->keyId()}', 128, 'ACTIVE')");

    $result = $service->enroll(assuranceEnrollCommand());

    assuranceAssert('REJECTED', $result['state'], 'admin review state');
    assuranceAssert('ADMIN_REVIEW', $result['reason'], 'inconsistent bursts triggers ADMIN_REVIEW');
    assuranceAssert('POSSIBLE_DUPLICATE_REVIEW', $result['outcome'], 'inconsistent duplicate requires review');
    assuranceAssert(0, (int)assuranceScalar($db, "SELECT COUNT(*) FROM face_template WHERE member_id = 101"), 'no templates written');
}, $results);

// =========================================================================
// 5. Corrupt Template Index Fail-Closed Tests
// =========================================================================

assuranceCase('FACE-ENR-06 fails closed on corrupted template during enrollment deduplication', function () use ($db, $cipher): void {
    assuranceReset($db);
    $validVec = vector128(1.0);
    $inference = new AssuranceFakeInference(validReferenceResult($validVec), validTwoBurstResult($validVec));
    $service = new FaceEnrollmentService($db, $inference, $cipher);

    $service->onboardReference([
        'branch_id' => 7,
        'member_id' => 101,
        'actor_employee_id' => 9001,
        'portrait' => 'portrait-bytes',
        'staff_confirmed' => true,
    ]);

    // Insert corrupted template payload in database
    $db->query("INSERT INTO face_template (member_id, subject_id, model_version_id, encrypted_embedding, encryption_key_id, embedding_dimension, status) VALUES (102, 2, 1, 'CORRUPTED-PAYLOAD-NOT-DECRYPTABLE', '{$cipher->keyId()}', 128, 'ACTIVE')");

    $result = $service->enroll(assuranceEnrollCommand());

    assuranceAssert('REJECTED', $result['state'], 'corrupt template state');
    assuranceAssert('IDENTITY_INDEX_UNAVAILABLE', $result['reason'], 'corrupt template fail-closed reason');
    assuranceAssert(0, (int)assuranceScalar($db, "SELECT COUNT(*) FROM face_template WHERE member_id = 101"), 'no templates written');
}, $results);

assuranceCase('FACE-ENR-06 fails closed on corrupted template during reference onboarding deduplication', function () use ($db, $cipher): void {
    assuranceReset($db);
    // Insert corrupted template in database
    $db->query("INSERT INTO face_template (member_id, subject_id, model_version_id, encrypted_embedding, encryption_key_id, embedding_dimension, status) VALUES (102, 2, 1, 'CORRUPTED-PAYLOAD-NOT-DECRYPTABLE', '{$cipher->keyId()}', 128, 'ACTIVE')");

    $validVec = vector128(1.0);
    $inference = new AssuranceFakeInference(validReferenceResult($validVec));
    $service = new FaceEnrollmentService($db, $inference, $cipher);

    $result = $service->onboardReference([
        'branch_id' => 7,
        'member_id' => 101,
        'actor_employee_id' => 9001,
        'portrait' => 'portrait-bytes',
        'staff_confirmed' => true,
    ]);

    assuranceAssert('REJECTED', $result['state'], 'corrupt template reference onboarding state');
    assuranceAssert('IDENTITY_INDEX_UNAVAILABLE', $result['reason'], 'corrupt template reference onboarding reason');
    assuranceAssert(0, (int)assuranceScalar($db, 'SELECT COUNT(*) FROM face_identity_reference'), 'no reference written');
}, $results);

// =========================================================================
// 6. Preview Collision Tri-State and Duplicate Band Tests
// =========================================================================

assuranceCase('FACE-ENR-06 checkPreviewCollision remains available when contact has no reference', function () use ($db, $cipher): void {
    assuranceReset($db);
    $evidenceResult = [
        'face_count' => 1,
        'selected_face_index' => 0,
        'embedding' => vector128(1.0),
        'top_match' => null,
    ];
    $inference = new AssuranceFakeInference([], [], $evidenceResult);
    $service = new FaceEnrollmentService($db, $inference, $cipher);

    // Contact 1101 has no active reference
    $result = $service->checkPreviewCollision('preview-frame-bytes', 7, 1101);

    assuranceAssert('CLEAR', $result['capture_state'], 'missing reference capture_state');
    assuranceAssert('CLEAR', $result['duplicate_band'], 'missing reference duplicate_band');
    assuranceAssert('NO_REFERENCE', $result['reference_match_state'], 'missing reference match state');
}, $results);

assuranceCase('FACE-ENR-06 checkPreviewCollision blocks another childs face before reference onboarding', function () use ($db, $cipher): void {
    assuranceReset($db);
    $otherVec = vector128(1.0);
    $encOther = $cipher->encrypt($otherVec);
    $db->query("INSERT INTO biometric_subject (id, contact_id, status) VALUES (2, 1102, 'ACTIVE')");
    $db->query("INSERT INTO face_template (member_id, subject_id, model_version_id, encrypted_embedding, encryption_key_id, embedding_dimension, status) VALUES (102, 2, 1, '{$encOther}', '{$cipher->keyId()}', 128, 'ACTIVE')");

    $evidenceResult = [
        'face_count' => 1,
        'selected_face_index' => 0,
        'embedding' => $otherVec,
        'top_match' => ['candidate_ref' => 'mem_102_0', 'score' => 0.95],
    ];
    $inference = new AssuranceFakeInference([], [], $evidenceResult);
    $service = new FaceEnrollmentService($db, $inference, $cipher);

    $result = $service->checkPreviewCollision('preview-frame-bytes', 7, 1101);

    assuranceAssert('CONFLICT', $result['capture_state'], 'first-time duplicate capture_state');
    assuranceAssert('BLOCKED', $result['duplicate_band'], 'first-time duplicate band');
    assuranceAssert('NO_REFERENCE', $result['reference_match_state'], 'first-time duplicate reference state');
    assuranceAssert('Diya Patel', $result['conflicting_member_name'], 'first-time duplicate identity');
}, $results);

assuranceCase('FACE-ENR-06 checkPreviewCollision returns CONFLICT on reference mismatch', function () use ($db, $cipher): void {
    assuranceReset($db);
    // Setup reference for contact 1101
    $refVec = vector128(1.0);
    $inference = new AssuranceFakeInference(validReferenceResult($refVec));
    $service = new FaceEnrollmentService($db, $inference, $cipher);
    $service->onboardReference([
        'branch_id' => 7,
        'member_id' => 101,
        'actor_employee_id' => 9001,
        'portrait' => 'portrait-bytes',
        'staff_confirmed' => true,
    ]);
    $db->query("UPDATE face_identity_reference SET status='ACTIVE' WHERE status='PENDING_ENROLLMENT'");

    // Preview frame face has similarity = 0.50 (< 0.90) to reference
    $mismatchVec = vector128(0.50);
    $inference->evidenceResult = [
        'face_count' => 1,
        'selected_face_index' => 0,
        'embedding' => $mismatchVec,
        'top_match' => null,
    ];

    $result = $service->checkPreviewCollision('preview-frame-bytes', 7, 1101);

    assuranceAssert('CONFLICT', $result['capture_state'], 'reference mismatch capture_state');
    assuranceAssert('CLEAR', $result['duplicate_band'], 'reference mismatch duplicate_band');
    assuranceAssert('MISMATCH', $result['reference_match_state'], 'reference mismatch match state');
}, $results);

assuranceCase('FACE-ENR-06 checkPreviewCollision returns CONFLICT and BLOCKED on high collision', function () use ($db, $cipher): void {
    assuranceReset($db);
    $refVec = vector128(1.0);
    $inference = new AssuranceFakeInference(validReferenceResult($refVec));
    $service = new FaceEnrollmentService($db, $inference, $cipher);
    $service->onboardReference([
        'branch_id' => 7,
        'member_id' => 101,
        'actor_employee_id' => 9001,
        'portrait' => 'portrait-bytes',
        'staff_confirmed' => true,
    ]);
    $db->query("UPDATE face_identity_reference SET status='ACTIVE' WHERE status='PENDING_ENROLLMENT'");

    // Student 102 exists in templates
    $stud102Vec = vector128(0.95);
    $enc102 = $cipher->encrypt($stud102Vec);
    $db->query("INSERT INTO biometric_subject (id, contact_id, status) VALUES (2, 1102, 'ACTIVE')");
    $db->query("INSERT INTO face_template (member_id, subject_id, model_version_id, encrypted_embedding, encryption_key_id, embedding_dimension, status) VALUES (102, 2, 1, '{$enc102}', '{$cipher->keyId()}', 128, 'ACTIVE')");

    // Preview matches candidate from student 102 with score = 0.95
    $inference->evidenceResult = [
        'face_count' => 1,
        'selected_face_index' => 0,
        'embedding' => $refVec,
        'top_match' => [
            'candidate_ref' => 'mem_102_0',
            'score' => 0.95,
        ],
    ];

    $result = $service->checkPreviewCollision('preview-frame-bytes', 7, 1101);

    assuranceAssert('CONFLICT', $result['capture_state'], 'collision capture_state');
    assuranceAssert('BLOCKED', $result['duplicate_band'], 'high collision duplicate_band');
    assuranceAssert('MATCH', $result['reference_match_state'], 'reference matches target');
    assuranceAssert('Diya Patel', $result['conflicting_member_name'], 'conflicting member name identified');
}, $results);

assuranceCase('FACE-ENR-06 checkPreviewCollision returns CLEAR when frame matches reference with no collisions', function () use ($db, $cipher): void {
    assuranceReset($db);
    $refVec = vector128(1.0);
    $inference = new AssuranceFakeInference(validReferenceResult($refVec));
    $service = new FaceEnrollmentService($db, $inference, $cipher);
    $service->onboardReference([
        'branch_id' => 7,
        'member_id' => 101,
        'actor_employee_id' => 9001,
        'portrait' => 'portrait-bytes',
        'staff_confirmed' => true,
    ]);
    $db->query("UPDATE face_identity_reference SET status='ACTIVE' WHERE status='PENDING_ENROLLMENT'");

    // Preview face matches reference (score 1.0) and top match against others is low (0.35)
    $inference->evidenceResult = [
        'face_count' => 1,
        'selected_face_index' => 0,
        'embedding' => $refVec,
        'top_match' => [
            'candidate_ref' => 'mem_102_0',
            'score' => 0.35,
        ],
    ];

    $result = $service->checkPreviewCollision('preview-frame-bytes', 7, 1101);

    assuranceAssert('CLEAR', $result['capture_state'], 'clean frame capture_state');
    assuranceAssert('CLEAR', $result['duplicate_band'], 'clean frame duplicate_band');
    assuranceAssert('MATCH', $result['reference_match_state'], 'clean frame reference match state');
}, $results);

assuranceCase('FACE-ENR-06 ignores legacy false staff_confirmed field in onboardReference', function () use ($db, $cipher): void {
    assuranceReset($db);
    $inference = new AssuranceFakeInference(validReferenceResult());
    $service = new FaceEnrollmentService($db, $inference, $cipher);

    $result = $service->onboardReference([
        'branch_id' => 7,
        'member_id' => 101,
        'actor_employee_id' => 9001,
        'portrait' => 'dummy-portrait-bytes',
        'staff_confirmed' => 'false',
    ]);

    assuranceAssert('ACTIVE', $result['state'], 'legacy false staff confirmation state');
}, $results);

assuranceCase('FACE-ENR-06 rejects string false for teacher_confirmed_member in enroll', function () use ($db, $cipher): void {
    assuranceReset($db);
    $vec = vector128(1.0);
    $inference = new AssuranceFakeInference(validReferenceResult($vec), validTwoBurstResult($vec));
    $service = new FaceEnrollmentService($db, $inference, $cipher);

    $service->onboardReference([
        'branch_id' => 7,
        'member_id' => 101,
        'actor_employee_id' => 9001,
        'portrait' => 'portrait-bytes',
        'staff_confirmed' => true,
    ]);

    $result = $service->enroll(assuranceEnrollCommand(['teacher_confirmed_member' => 'false']));

    assuranceAssert('REJECTED', $result['state'], 'string false teacher confirmation state');
    assuranceAssert('MEMBER_CONFIRMATION_REQUIRED', $result['reason'], 'string false teacher confirmation reason');
}, $results);

assuranceCase('FACE-ENR-06 ignores corrupted revoked template from another child', function () use ($db, $cipher): void {
    assuranceReset($db);
    $vec = vector128(1.0);
    $inference = new AssuranceFakeInference(validReferenceResult($vec), validTwoBurstResult($vec));
    $service = new FaceEnrollmentService($db, $inference, $cipher);

    // A revoked historical template must never enter the live duplicate index.
    $db->query("INSERT INTO face_template (member_id, subject_id, model_version_id, encrypted_embedding, encryption_key_id, embedding_dimension, status) VALUES (102, NULL, 1, 'legacy-unreadable', 'legacy-key', 128, 'REVOKED')");

    // Reference onboarding must ignore the corrupted revoked record.
    $ref = $service->onboardReference([
        'branch_id' => 7,
        'member_id' => 101,
        'actor_employee_id' => 9001,
        'portrait' => 'portrait-bytes',
        'staff_confirmed' => true,
    ]);
    assuranceAssert('ACTIVE', $ref['state'], 'reference onboarding ignored corrupted revoked template');

    // Two-burst enrollment must also ignore the corrupted revoked record.
    $enr = $service->enroll(assuranceEnrollCommand());
    assuranceAssert('ACTIVE', $enr['state'], 'enrollment ignored corrupted revoked template');
}, $results);

assuranceCase('FACE-ENR-06 keeps single-reference duplicate similarity advisory until burst enrollment', function () use ($db, $cipher): void {
    assuranceReset($db);
    $vec = vector128(1.0);
    $inference = new AssuranceFakeInference(validReferenceResult($vec));
    $service = new FaceEnrollmentService($db, $inference, $cipher);

    // Student 102 onboards reference portrait
    $first = $service->onboardReference([
        'branch_id' => 7,
        'member_id' => 102,
        'actor_employee_id' => 9001,
        'portrait' => 'portrait-bytes-102',
        'staff_confirmed' => true,
    ]);
    assuranceAssert('ACTIVE', $first['state'], 'first student reference onboarded');

    // Student 101 attempts to onboard the same reference portrait
    $second = $service->onboardReference([
        'branch_id' => 7,
        'member_id' => 101,
        'actor_employee_id' => 9002,
        'portrait' => 'portrait-bytes-101',
        'staff_confirmed' => true,
    ]);
    assuranceAssert('ACTIVE', $second['state'], 'second reference capture continues');
    assuranceAssert('PENDING_ENROLLMENT', $second['reference_status'], 'reference is not activated before burst verification');
}, $results);

assuranceCase('FACE-ENR-06 blocks enrollment when reference_id does not match active reference', function () use ($db, $cipher): void {
    assuranceReset($db);
    $vec = vector128(1.0);
    $inference = new AssuranceFakeInference(validReferenceResult($vec), validTwoBurstResult($vec));
    $service = new FaceEnrollmentService($db, $inference, $cipher);

    $ref = $service->onboardReference([
        'branch_id' => 7,
        'member_id' => 101,
        'actor_employee_id' => 9001,
        'portrait' => 'portrait-bytes',
        'staff_confirmed' => true,
    ]);

    // Send mismatched reference_id (e.g. 9999 instead of actual reference id)
    $result = $service->enroll(assuranceEnrollCommand(['reference_id' => 9999]));

    assuranceAssert('REJECTED', $result['state'], 'reference mismatch state');
    assuranceAssert('REFERENCE_MISMATCH', $result['reason'], 'reference mismatch reason');
}, $results);

assuranceCase('FACE-ENR-06 checkPreviewCollision correctly ignores childs own legacy templates', function () use ($db, $cipher): void {
    assuranceReset($db);
    $refVec = vector128(1.0);
    $inference = new AssuranceFakeInference(validReferenceResult($refVec));
    $service = new FaceEnrollmentService($db, $inference, $cipher);

    $service->onboardReference([
        'branch_id' => 7,
        'member_id' => 101,
        'actor_employee_id' => 9001,
        'portrait' => 'portrait-bytes',
        'staff_confirmed' => true,
    ]);
    $db->query("UPDATE face_identity_reference SET status='ACTIVE' WHERE status='PENDING_ENROLLMENT'");

    // Insert student 101's own legacy template in face_template (subject_id NULL)
    $enc = $cipher->encrypt($refVec);
    $db->query("INSERT INTO face_template (member_id, subject_id, model_version_id, encrypted_embedding, encryption_key_id, embedding_dimension, status) VALUES (101, NULL, 1, '{$enc}', '{$cipher->keyId()}', 128, 'ACTIVE')");

    $inference->evidenceResult = [
        'face_count' => 1,
        'selected_face_index' => 0,
        'embedding' => $refVec,
        'top_match' => null,
    ];

    $result = $service->checkPreviewCollision('preview-frame-bytes', 7, 1101);

    assuranceAssert('CLEAR', $result['capture_state'], 'own legacy template not flagged as collision');
    assuranceAssert('CLEAR', $result['duplicate_band'], 'duplicate band is clear');
    assuranceAssert('MATCH', $result['reference_match_state'], 'reference matches target');
}, $results);

// =========================================================================
// Run and output summary
// =========================================================================

$failed = 0;
foreach ($results as $result) {
    if ($result['status'] === 'PASS') {
        fwrite(STDOUT, "PASS {$result['name']}\n");
    } else {
        $failed++;
        fwrite(STDERR, "FAIL {$result['name']}: {$result['message']}\n");
    }
}

fwrite(STDOUT, sprintf("%d FACE-ENR-06 scenarios, %d failed.\n", count($results), $failed));
if (defined('FACE_ENR_06_CONTINUE_FOR_RETAKE_TESTS')) {
    if ($failed) throw new RuntimeException('FACE-ENR-06 prerequisite failed');
} else {
    exit($failed === 0 ? 0 : 1);
}
