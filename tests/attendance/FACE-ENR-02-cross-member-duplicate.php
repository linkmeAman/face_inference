<?php

declare(strict_types=1);

/**
 * FACE-ENR-02 cross-member duplicate identity contract.
 *
 * Created before the enrollment collision guard. Fixtures use temporary tables
 * in the disposable test database and never persist frames or embeddings.
 */

date_default_timezone_set('UTC');

$root = dirname(__DIR__, 2);
$dbName = getenv('ATTENDANCE_TEST_DB') ?: 'ccc_database_test';
$dbHost = getenv('ATTENDANCE_TEST_DB_HOST') ?: '127.0.0.1';
$dbUser = getenv('ATTENDANCE_TEST_DB_USER') ?: 'root';
$dbPassword = getenv('ATTENDANCE_TEST_DB_PASSWORD') ?: '';

if (!preg_match('/^ccc_database_test(?:_[a-z0-9_]+)?$/', $dbName)) {
    fwrite(STDERR, "Refusing to run FACE-ENR-02 outside the disposable test database namespace.\n");
    exit(2);
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
        fwrite(STDERR, "BLOCKED FACE-ENR-02: {$fallbackException->getMessage()}\n");
        exit(2);
    }
}

require_once $root . '/classes/services/FaceEnrollmentService.php';

$schema = <<<'SQL'
CREATE TEMPORARY TABLE member (
  id INT NOT NULL PRIMARY KEY,
  contact_id INT NOT NULL,
  status INT NOT NULL DEFAULT 1,
  park INT NOT NULL DEFAULT 0,
  bid INT NOT NULL
) ENGINE=InnoDB;
CREATE TEMPORARY TABLE face_model_version (
  id INT NOT NULL PRIMARY KEY,
  model_name VARCHAR(100) NOT NULL,
  provider_id VARCHAR(100) NOT NULL,
  provider_version VARCHAR(100) NOT NULL,
  policy_version VARCHAR(100) NOT NULL,
  embedding_dimension INT NOT NULL,
  model_checksum CHAR(64) NOT NULL,
  status VARCHAR(20) NOT NULL DEFAULT 'ACTIVE'
) ENGINE=InnoDB;
CREATE TEMPORARY TABLE biometric_consent (
  id INT NOT NULL AUTO_INCREMENT PRIMARY KEY,
  member_id INT NOT NULL,
  guardian_contact_id INT NULL,
  notice_version VARCHAR(64) NOT NULL,
  purpose VARCHAR(100) NOT NULL,
  consent_status VARCHAR(32) NOT NULL,
  verification_method VARCHAR(64) NOT NULL,
  actor_employee_id INT NOT NULL,
  consented_at DATETIME NULL,
  withdrawn_at DATETIME NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB;
CREATE TEMPORARY TABLE face_template (
  id INT NOT NULL AUTO_INCREMENT PRIMARY KEY,
  member_id INT NOT NULL,
  model_version_id INT NOT NULL,
  encrypted_embedding MEDIUMBLOB NOT NULL,
  encryption_key_id VARCHAR(100) NOT NULL,
  embedding_dimension INT NOT NULL,
  quality_summary JSON NULL,
  status VARCHAR(20) NOT NULL DEFAULT 'ACTIVE',
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  expires_at DATETIME NULL,
  revoked_at DATETIME NULL
) ENGINE=InnoDB;
SQL;

foreach (explode(";\n", $schema) as $statement) {
    $statement = trim($statement);
    if ($statement !== '') {
        $db->query($statement);
    }
}

final class DuplicateIdentityInference implements FaceEnrollmentInferenceClient
{
    private $embeddings;

    public function __construct(array $embeddings)
    {
        $this->embeddings = $embeddings;
    }

    public function encodeEnrollment(array $frames, array $captureContexts = []): array
    {
        return [
            'model_version_id' => 9,
            'provider_id' => 'fake-provider',
            'provider_version' => '1',
            'calibration_policy_version' => 'test-policy-v1',
            'embeddings' => $this->embeddings,
            'embedding_dimension' => 3,
            'quality_summary' => [
                'frames_received' => 8,
                'frames_accepted' => 8,
                'face_count' => 1,
                'sharpness_ok' => true,
                'exposure_ok' => true,
                'pose_ok' => true,
                'verification_score' => 0.99,
            ],
            'verification_passed' => true,
            'verification_reason' => 'SAME_DAY_MATCH',
        ];
    }
}

function resetDuplicateIdentityFixtures(mysqli $db): void
{
    foreach (['face_template', 'biometric_consent', 'face_model_version', 'member'] as $table) {
        $db->query("TRUNCATE TABLE `{$table}`");
    }
    $db->query("INSERT INTO member (id, contact_id, bid) VALUES (101, 1101, 7), (102, 1102, 7), (201, 1201, 8)");
    $db->query("INSERT INTO face_model_version (id, model_name, provider_id, provider_version, policy_version, embedding_dimension, model_checksum) VALUES (9, 'test-model', 'fake-provider', '1', 'test-policy-v1', 3, REPEAT('a', 64))");
}

function duplicateCommand(int $memberId): array
{
    return [
        'branch_id' => 7,
        'member_id' => $memberId,
        'actor_employee_id' => 9001,
        'notice_version' => 'program-face-v1',
        'purpose' => 'attendance',
        'teacher_confirmed_member' => true,
        'frames' => array_fill(0, 8, 'temporary-frame'),
    ];
}

function insertExistingTemplate(mysqli $db, FaceEmbeddingCipher $cipher, int $memberId, array $embedding): void
{
    $encrypted = $db->real_escape_string($cipher->encrypt($embedding));
    $db->query("INSERT INTO face_template (member_id, model_version_id, encrypted_embedding, encryption_key_id, embedding_dimension, quality_summary, status) VALUES ({$memberId}, 9, '{$encrypted}', 'test-key', 3, '{}', 'ACTIVE')");
}

function assertEnrollment($expected, $actual, string $message): void
{
    if ($expected !== $actual) {
        throw new RuntimeException($message . ' (expected ' . var_export($expected, true) . ', got ' . var_export($actual, true) . ')');
    }
}

$cipher = new FaceEmbeddingCipher(str_repeat('d', 32), 'test-key');
$failures = [];

try {
    resetDuplicateIdentityFixtures($db);
    insertExistingTemplate($db, $cipher, 102, [1.0, 0.0, 0.0]);
    $service = new FaceEnrollmentService($db, new DuplicateIdentityInference([
        [0.999, 0.030, 0.0],
        [0.998, 0.040, 0.0],
        [0.997, 0.050, 0.0],
    ]), $cipher, 0.90);
    $result = $service->enroll(duplicateCommand(101));
    assertEnrollment('REJECTED', $result['state'] ?? null, 'cross-member duplicate state');
    assertEnrollment('FACE_ALREADY_ASSIGNED', $result['reason'] ?? null, 'cross-member duplicate reason');
    assertEnrollment(0, (int)$db->query("SELECT COUNT(*) count FROM face_template WHERE member_id = 101")->fetch_assoc()['count'], 'duplicate target template writes');
    assertEnrollment(0, (int)$db->query("SELECT COUNT(*) count FROM biometric_consent WHERE member_id = 101")->fetch_assoc()['count'], 'duplicate target authorization writes');
    echo "PASS FACE-ENR-02 rejects a face already assigned to another child\n";
} catch (Throwable $exception) {
    $failures[] = $exception->getMessage();
    fwrite(STDERR, "FAIL FACE-ENR-02 duplicate collision: {$exception->getMessage()}\n");
}

try {
    resetDuplicateIdentityFixtures($db);
    insertExistingTemplate($db, $cipher, 102, [1.0, 0.0, 0.0]);
    insertExistingTemplate($db, $cipher, 102, [-1.0, 0.0, 0.0]);
    $service = new FaceEnrollmentService($db, new DuplicateIdentityInference([
        [1.0, 0.0, 0.0],
        [0.995, 0.100, 0.0],
        [0.995, -0.100, 0.0],
    ]), $cipher, 0.90);
    $result = $service->enroll(duplicateCommand(101));
    assertEnrollment('REJECTED', $result['state'] ?? null, 'template-level duplicate state');
    assertEnrollment('FACE_ALREADY_ASSIGNED', $result['reason'] ?? null, 'template-level duplicate reason');
    echo "PASS FACE-ENR-02 rejects a template-level collision despite a cancelled centroid\n";
} catch (Throwable $exception) {
    $failures[] = $exception->getMessage();
    fwrite(STDERR, "FAIL FACE-ENR-02 template collision: {$exception->getMessage()}\n");
}

try {
    resetDuplicateIdentityFixtures($db);
    insertExistingTemplate($db, $cipher, 102, [1.0, 0.0, 0.0]);
    insertExistingTemplate($db, $cipher, 201, [0.0, 1.0, 0.0]);
    $service = new FaceEnrollmentService($db, new DuplicateIdentityInference([
        [0.0, 0.0, 1.0],
        [0.0, 0.010, 0.999],
        [0.010, 0.0, 0.999],
    ]), $cipher, 0.90);
    $result = $service->enroll(duplicateCommand(101));
    assertEnrollment('ACTIVE', $result['state'] ?? null, 'distinct face enrollment state');
    assertEnrollment(3, (int)$db->query("SELECT COUNT(*) count FROM face_template WHERE member_id = 101 AND status = 'ACTIVE'")->fetch_assoc()['count'], 'distinct face template writes');
    echo "PASS FACE-ENR-02 permits a distinct face and ignores another branch\n";
} catch (Throwable $exception) {
    $failures[] = $exception->getMessage();
    fwrite(STDERR, "FAIL FACE-ENR-02 distinct enrollment: {$exception->getMessage()}\n");
}

$db->close();
if ($failures) {
    fwrite(STDERR, count($failures) . " FACE-ENR-02 scenarios failed.\n");
    exit(1);
}
echo "3 FACE-ENR-02 scenarios, 0 failed.\n";
