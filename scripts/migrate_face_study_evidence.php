<?php
declare(strict_types=1);
require_once __DIR__ . '/../classes/dbconnection.class.php';
$dbName = '';
foreach ($argv as $arg) if (strpos($arg, '--database=') === 0) $dbName = substr($arg, 11);
if (!in_array($dbName, ['ccc_database', 'ccc_database_test'], true)) exit(2);
$ddl = [
    "CREATE TABLE IF NOT EXISTS face_study_consent (id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY, member_id INT NOT NULL UNIQUE, subject_id CHAR(32) NOT NULL UNIQUE, document_sha256 CHAR(64) NULL, consent_actor_id INT NOT NULL, consented_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP, withdrawn_at TIMESTAMP NULL, KEY(member_id, withdrawn_at)) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4",
    "CREATE TABLE IF NOT EXISTS face_study_evidence (id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY, member_id INT NOT NULL, session_id CHAR(32) NOT NULL, gallery_subject_id CHAR(32) NULL, selected_subject_id CHAR(32) NULL, encrypted_record MEDIUMTEXT NOT NULL, created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP, finalized_at TIMESTAMP NULL, KEY(member_id,session_id), KEY(gallery_subject_id), KEY(selected_subject_id), KEY(created_at)) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4",
];
if (!in_array('--apply', $argv, true)) { foreach ($ddl as $sql) echo "PLAN {$sql}\n"; exit(0); }
$db = (new dbconnection())->connect($dbName);
foreach ($ddl as $sql) if (!$db->query($sql)) throw new RuntimeException($db->error);
$index = $db->query("SHOW INDEX FROM face_study_consent WHERE Column_name='member_id' AND Non_unique=0");
if ($index && !$index->num_rows && !$db->query('ALTER TABLE face_study_consent ADD UNIQUE KEY uq_study_member (member_id)')) throw new RuntimeException($db->error);
foreach (['gallery_subject_id', 'selected_subject_id'] as $column) {
    $exists = $db->query("SHOW COLUMNS FROM face_study_evidence LIKE '{$column}'");
    if ($exists && !$exists->num_rows && !$db->query("ALTER TABLE face_study_evidence ADD COLUMN {$column} CHAR(32) NULL, ADD KEY ({$column})")) throw new RuntimeException($db->error);
}
foreach (['session_id' => 'CHAR(32) NOT NULL DEFAULT \'\'', 'finalized_at' => 'TIMESTAMP NULL'] as $column => $definition) {
    $exists = $db->query("SHOW COLUMNS FROM face_study_evidence LIKE '{$column}'");
    if ($exists && !$exists->num_rows && !$db->query("ALTER TABLE face_study_evidence ADD COLUMN {$column} {$definition}")) throw new RuntimeException($db->error);
}
echo "Study evidence tables installed in {$dbName}. No consent or evidence created.\n";
