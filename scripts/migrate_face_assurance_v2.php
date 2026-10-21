<?php

require_once __DIR__ . '/../classes/dbconnection.class.php';

$db = new dbconnection();

$databases = ['ccc_database', 'ccc_database_test'];

$sqlFile = __DIR__ . '/../database/migrations/20260925_01_high_precision_face_assurance.sql';
$sqlContent = file_get_contents($sqlFile);

foreach ($databases as $dbName) {
    echo "=== Migrating {$dbName} ===\n";
    $conn = $db->connect($dbName);
    if (!$conn || $conn->connect_errno) {
        echo "Failed to connect to {$dbName}\n";
        continue;
    }

    // Run table creations
    $cleanedSql = preg_replace('/--.*$/m', '', $sqlContent);
    $queries = explode(';', $cleanedSql);
    foreach ($queries as $query) {
        $q = trim($query);
        if ($q === '') {
            continue;
        }
        if (!$conn->query($q)) {
            echo "Error executing statement in {$dbName}: " . $conn->error . "\n";
        } else {
            echo "Executed DDL statement successfully in {$dbName}\n";
        }
    }

    // Check and add columns on face_template
    $cols = [
        'subject_id' => "INT NULL AFTER `member_id`",
        'enrollment_attempt_id' => "BIGINT UNSIGNED NULL AFTER `subject_id`",
        'reference_id' => "INT NULL AFTER `enrollment_attempt_id`",
    ];

    foreach ($cols as $colName => $def) {
        $res = $conn->query("SELECT COLUMN_NAME FROM INFORMATION_SCHEMA.COLUMNS WHERE TABLE_SCHEMA = '{$dbName}' AND TABLE_NAME = 'face_template' AND COLUMN_NAME = '{$colName}'");
        if ($res && $res->num_rows === 0) {
            $alterSql = "ALTER TABLE `face_template` ADD COLUMN `{$colName}` {$def}";
            if ($conn->query($alterSql)) {
                echo "Added column face_template.{$colName} to {$dbName}\n";
            } else {
                echo "Error adding column face_template.{$colName}: " . $conn->error . "\n";
            }
        } else {
            echo "Column face_template.{$colName} already exists in {$dbName}\n";
        }
    }

    // Add index if not exists
    $res = $conn->query("SHOW INDEX FROM `face_template` WHERE Key_name = 'idx_face_template_subject'");
    if ($res && $res->num_rows === 0) {
        $conn->query("ALTER TABLE `face_template` ADD KEY `idx_face_template_subject` (`subject_id`, `status`)");
        echo "Added index idx_face_template_subject to {$dbName}\n";
    }

    // Populate biometric_subject from existing member records
    $conn->query("
        INSERT IGNORE INTO `biometric_subject` (contact_id, status)
        SELECT DISTINCT m.contact_id, 'ACTIVE'
        FROM `member` m
        WHERE m.contact_id > 0 AND (
            EXISTS (SELECT 1 FROM `face_template` ft WHERE ft.member_id = m.id)
            OR EXISTS (SELECT 1 FROM `biometric_consent` bc WHERE bc.member_id = m.id)
        )
    ");
    echo "Populated biometric_subject from members in {$dbName}\n";

    // Link subject_id on face_template
    $conn->query("
        UPDATE `face_template` ft
        JOIN `member` m ON m.id = ft.member_id
        JOIN `biometric_subject` bs ON bs.contact_id = m.contact_id
        SET ft.subject_id = bs.id
        WHERE ft.subject_id IS NULL
    ");

    // Quarantine existing legacy templates without v2 evidence
    $res = $conn->query("UPDATE `face_template` SET `status` = 'QUARANTINED_LEGACY' WHERE `status` = 'ACTIVE' AND `enrollment_attempt_id` IS NULL");
    $affected = $conn->affected_rows;
    echo "Quarantined {$affected} legacy templates in {$dbName}\n";

    // Update face_model_version policy_version to face-policy-v2
    $conn->query("UPDATE `face_model_version` SET `policy_version` = 'face-policy-v2' WHERE `id` = 1");
    echo "Updated face_model_version to face-policy-v2 in {$dbName}\n";

    $conn->close();
}

echo "=== Migration Complete ===\n";
