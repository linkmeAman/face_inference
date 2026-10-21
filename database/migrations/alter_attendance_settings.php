<?php
$databases = ['ccc_database_test', 'ccc_database'];
$columns = [
    'face_attendance_enabled' => 'TINYINT(1) NOT NULL DEFAULT 1',
    'face_attendance_shadow_mode' => 'TINYINT(1) NOT NULL DEFAULT 0',
    'face_attendance_live_write' => 'TINYINT(1) NOT NULL DEFAULT 1',
    'face_enrollment_enabled' => 'TINYINT(1) NOT NULL DEFAULT 1',
    'face_punch_out_enabled' => 'TINYINT(1) NOT NULL DEFAULT 1',
    'face_enrollment_uncalibrated_test_mode' => 'TINYINT(1) NOT NULL DEFAULT 0',
    'face_attendance_require_https' => 'TINYINT(1) NOT NULL DEFAULT 1',
];

foreach ($databases as $dbName) {
    $mysqli = new mysqli("localhost", "nahid", "", $dbName);
    if ($mysqli->connect_errno) {
        echo "Failed to connect to $dbName: " . $mysqli->connect_error . "\n";
        continue;
    }
    
    // Check existing columns in attendance_settings
    $existingCols = [];
    $res = $mysqli->query("SHOW COLUMNS FROM attendance_settings");
    if ($res) {
        while ($row = $res->fetch_assoc()) {
            $existingCols[] = $row['Field'];
        }
    }
    
    foreach ($columns as $col => $definition) {
        if (!in_array($col, $existingCols, true)) {
            $alterSql = "ALTER TABLE attendance_settings ADD COLUMN `$col` $definition";
            if ($mysqli->query($alterSql)) {
                echo "Added $col to $dbName.attendance_settings\n";
            } else {
                echo "Error adding $col to $dbName: " . $mysqli->error . "\n";
            }
        } else {
            echo "$col already exists in $dbName.attendance_settings\n";
        }
    }
    
    // Set initial values
    $mysqli->query("UPDATE attendance_settings SET
        face_attendance_enabled = 1,
        face_attendance_shadow_mode = 0,
        face_attendance_live_write = 1,
        face_enrollment_enabled = 1,
        face_punch_out_enabled = 1,
        face_enrollment_uncalibrated_test_mode = 0,
        face_attendance_require_https = 1
    ");
    echo "Updated $dbName.attendance_settings rows.\n";
    $mysqli->close();
}
