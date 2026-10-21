<?php
declare(strict_types=1);
require_once __DIR__ . '/../classes/dbconnection.class.php';
require_once __DIR__ . '/../classes/services/FaceAttendanceConfig.php';

$apply = in_array('--apply', $argv, true);
$database = '';
$backup = '';
foreach ($argv as $arg) {
    if (strpos($arg, '--database=') === 0) $database = substr($arg, 11);
    if (strpos($arg, '--backup=') === 0) $backup = substr($arg, 9);
}
if (!in_array($database, ['ccc_database', 'ccc_database_test'], true)
    || !$backup || !is_file($backup) || filesize($backup) < 1024
    || strpos(basename($backup), $database) === false) {
    fwrite(STDERR, "Specify --database=ccc_database|ccc_database_test and a verified, database-named --backup=file.sql (>=1 KB).\n");
    exit(2);
}
$teacherSelection = FaceAttendanceConfig::getEnv('FACE_TEACHER_SELECTION_ENABLED');
$liveWrite = FaceAttendanceConfig::getEnv('FACE_ATTENDANCE_LIVE_WRITE');
if ($teacherSelection !== '0' || $liveWrite !== '0') {
    fwrite(STDERR, "Face selection and live attendance must both be disabled.\n");
    exit(2);
}
$db = (new dbconnection())->connect($database);
$ddl = [
    'face_identity_reference' => ['preprocessing_version' => 'VARCHAR(100) NULL', 'capture_session_id' => 'BIGINT UNSIGNED NULL', 'cleared_at' => 'DATETIME NULL', 'superseded_at' => 'DATETIME NULL'],
    'face_template' => ['preprocessing_version' => 'VARCHAR(100) NULL', 'capture_session_id' => 'BIGINT UNSIGNED NULL', 'superseded_at' => 'DATETIME NULL'],
    'face_enrollment_attempt' => ['capture_session_id' => 'BIGINT UNSIGNED NULL', 'identity_drift_score' => 'DECIMAL(5,4) NULL', 'model_version_id' => 'INT NULL', 'recognition_generation' => 'CHAR(64) NULL', 'admin_override_actor_id' => 'INT NULL', 'admin_override_reason' => 'VARCHAR(255) NULL', 'admin_override_at' => 'DATETIME NULL'],
];
$table = "CREATE TABLE IF NOT EXISTS face_enrollment_capture_session (
 id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY, token_hash CHAR(64) NOT NULL,
 member_id INT NOT NULL, subject_id INT NOT NULL, branch_id INT NOT NULL,
 actor_employee_id INT NOT NULL, browser_session_hash CHAR(64) NOT NULL,
 reference_id INT NULL, mode ENUM('ENROLLMENT','REENROLLMENT') NOT NULL,
 status ENUM('ACTIVE','PENDING_ADMIN_REVIEW','CLEARED','COMPLETED','EXPIRED','CANCELLED') NOT NULL DEFAULT 'ACTIVE',
 created_at DATETIME NOT NULL, expires_at DATETIME NOT NULL,
 cleared_at DATETIME NULL, completed_at DATETIME NULL,
 UNIQUE KEY uq_capture_token_hash(token_hash), KEY idx_capture_member_status(member_id,status),
 KEY idx_capture_expires_status(expires_at,status)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4";
echo ($apply ? 'APPLY ' : 'PLAN ') . $table . "\n";
if ($apply && !$db->query($table)) throw new RuntimeException($db->error);
foreach ($ddl as $name => $columns) {
    foreach ($columns as $column => $type) {
        $row = $db->query("SELECT 1 FROM INFORMATION_SCHEMA.COLUMNS WHERE TABLE_SCHEMA='" . $db->real_escape_string($database) . "' AND TABLE_NAME='{$name}' AND COLUMN_NAME='{$column}' LIMIT 1");
        if ($row && $row->num_rows) continue;
        $sql = "ALTER TABLE `{$name}` ADD COLUMN `{$column}` {$type}";
        echo ($apply ? 'APPLY ' : 'PLAN ') . $sql . "\n";
        if ($apply && !$db->query($sql)) throw new RuntimeException($db->error);
    }
}
$quarantine = "FROM face_identity_reference fir LEFT JOIN biometric_subject bs ON bs.id=fir.subject_id LEFT JOIN member m ON m.contact_id=bs.contact_id WHERE fir.status='PENDING_ENROLLMENT' AND (bs.id IS NULL OR m.id IS NULL)";
$count = $db->query("SELECT COUNT(*) n {$quarantine}")->fetch_assoc()['n'];
echo "Unlinked pending references to clear: {$count}\n";
if ($apply) {
    $sql = "UPDATE face_identity_reference fir LEFT JOIN biometric_subject bs ON bs.id=fir.subject_id LEFT JOIN member m ON m.contact_id=bs.contact_id SET fir.status='CLEARED',fir.encrypted_portrait='',fir.encrypted_embedding='',fir.cleared_at=NOW() WHERE fir.status='PENDING_ENROLLMENT' AND (bs.id IS NULL OR m.id IS NULL)";
    if (!$db->query($sql)) throw new RuntimeException($db->error);
}
$count = $db->query("SELECT COUNT(*) n FROM face_identity_reference WHERE status='ACTIVE' AND (preprocessing_version IS NULL OR preprocessing_version='')")->fetch_assoc()['n'];
echo "Active references with unknown preprocessing to quarantine: {$count}\n";
if ($apply && !$db->query("UPDATE face_identity_reference SET status='QUARANTINED' WHERE status='ACTIVE' AND (preprocessing_version IS NULL OR preprocessing_version='')")) throw new RuntimeException($db->error);
$count = $db->query("SELECT COUNT(*) n FROM face_identity_reference WHERE status='ACTIVE' AND JSON_UNQUOTE(JSON_EXTRACT(quality_evidence,'$.enrollment_mode'))='UNCALIBRATED_TEST'")->fetch_assoc()['n'];
echo "Active uncalibrated references to quarantine: {$count}\n";
if ($apply && !$db->query("UPDATE face_identity_reference SET status='QUARANTINED' WHERE status='ACTIVE' AND JSON_UNQUOTE(JSON_EXTRACT(quality_evidence,'$.enrollment_mode'))='UNCALIBRATED_TEST'")) throw new RuntimeException($db->error);
$db->close();
echo $apply ? "Applied. Keep both attendance gates off.\n" : "Dry run only.\n";
