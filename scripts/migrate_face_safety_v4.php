<?php
/** Dry-run by default. Apply only after a reviewed SQL dump and disabled selection. */
declare(strict_types=1);

require_once __DIR__ . '/../classes/dbconnection.class.php';
require_once __DIR__ . '/../classes/services/FaceAttendanceConfig.php';

$database = 'ccc_database';
$apply = in_array('--apply', $argv, true);
$backup = '';
foreach ($argv as $argument) {
    if (str_starts_with($argument, '--database=')) $database = substr($argument, 11);
    if (str_starts_with($argument, '--backup=')) $backup = substr($argument, 9);
}
if (!in_array($database, ['ccc_database', 'ccc_database_test'], true)) {
    fwrite(STDERR, "Unsupported database\n");
    exit(2);
}
$teacherSelection = FaceAttendanceConfig::getEnv('FACE_TEACHER_SELECTION_ENABLED');
$liveWrite = FaceAttendanceConfig::getEnv('FACE_ATTENDANCE_LIVE_WRITE');
if ($apply && (!$backup || !is_file($backup) || filesize($backup) < 1024
    || strpos(basename($backup), $database) === false
    || $teacherSelection !== '0'
    || $liveWrite !== '0')) {
    fwrite(STDERR, "Apply requires a nonempty database-named backup and disabled face selection/live write\n");
    exit(2);
}
$db = (new dbconnection())->connect($database);
if (!$db) exit(2);

$migration = [
    'face_template' => [
        'status' => 'VARCHAR(32) NOT NULL DEFAULT \'ACTIVE\'',
        'source' => 'VARCHAR(16) NULL',
        'enrollment_session_id' => 'BIGINT UNSIGNED NULL',
        'created_by_actor_id' => 'INT NULL',
        'preprocessing_version' => 'VARCHAR(100) NULL',
        'revalidated_at' => 'DATETIME NULL',
    ],
    'face_identity_reference' => [
        'status' => 'VARCHAR(32) NOT NULL DEFAULT \'ACTIVE\'',
        'enrollment_session_id' => 'BIGINT UNSIGNED NULL',
        'preprocessing_version' => 'VARCHAR(100) NULL',
        'revalidated_at' => 'DATETIME NULL',
    ],
    'attendance_scan' => [
        'recognition_generation' => 'CHAR(64) NULL',
        'browser_session_hash' => 'CHAR(64) NULL',
        'capture_nonce_hash' => 'CHAR(64) NULL',
        'capture_nonce_expires_at' => 'DATETIME NULL',
        'capture_nonce_used_at' => 'DATETIME NULL',
    ],
    'recognition_attempt' => [
        'recognition_generation' => 'CHAR(64) NULL',
        'outcome' => 'VARCHAR(32) NULL',
    ],
];
foreach ($migration as $table => $columns) {
    foreach ($columns as $column => $definition) {
        $lookup = $db->query("SELECT COLUMN_TYPE FROM INFORMATION_SCHEMA.COLUMNS WHERE TABLE_SCHEMA = '" . $db->real_escape_string($database) . "' AND TABLE_NAME = '{$table}' AND COLUMN_NAME = '{$column}'");
        $existing = $lookup ? $lookup->fetch_assoc() : null;
        if ($column === 'status' && $existing && strtolower((string)$existing['COLUMN_TYPE']) === 'varchar(32)') continue;
        if ($column !== 'status' && $existing) continue;
        $verb = $existing ? 'MODIFY COLUMN' : 'ADD COLUMN';
        $sql = "ALTER TABLE `{$table}` {$verb} `{$column}` {$definition}";
        echo ($apply ? 'APPLY ' : 'PLAN ') . $sql . "\n";
        if ($apply && !$db->query($sql)) throw new RuntimeException($db->error);
    }
}

$counts = [
    'face_template' => "quality_summary",
    'face_identity_reference' => "quality_evidence",
];
foreach ($counts as $table => $jsonColumn) {
    $predicate = "status = 'ACTIVE' AND JSON_UNQUOTE(JSON_EXTRACT(`{$jsonColumn}`, '$.enrollment_mode')) = 'UNCALIBRATED_TEST'";
    $count = $db->query("SELECT COUNT(*) AS n FROM `{$table}` WHERE {$predicate}")->fetch_assoc()['n'];
    echo "{$table}: {$count} active uncalibrated records to quarantine\n";
    if ($apply && !$db->query("UPDATE `{$table}` SET status = 'QUARANTINED' WHERE {$predicate}")) {
        throw new RuntimeException($db->error);
    }
}
$unknownPreprocessing = $db->query("SELECT COUNT(*) AS n FROM face_template WHERE status = 'ACTIVE' AND (preprocessing_version IS NULL OR preprocessing_version = '')")->fetch_assoc()['n'];
echo "face_template: {$unknownPreprocessing} active records with unknown preprocessing to quarantine\n";
if ($apply && !$db->query("UPDATE face_template SET status = 'QUARANTINED' WHERE status = 'ACTIVE' AND (preprocessing_version IS NULL OR preprocessing_version = '')")) {
    throw new RuntimeException($db->error);
}
$db->close();
echo $apply ? "Migration applied; keep teacher selection disabled pending acceptance tests\n" : "Dry run only; no data changed\n";
