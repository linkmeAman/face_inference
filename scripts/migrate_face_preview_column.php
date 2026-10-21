<?php

require_once __DIR__ . '/../classes/dbconnection.class.php';

$databases = ['ccc_database', 'ccc_database_test'];

foreach ($databases as $dbName) {
    try {
        $conn = (new dbconnection())->connect($dbName);
    } catch (Throwable $e) {
        fwrite(STDERR, "SKIP {$dbName}: {$e->getMessage()}\n");
        continue;
    }

    if (!$conn || $conn->connect_errno) {
        fwrite(STDERR, "SKIP {$dbName}: connection unavailable\n");
        continue;
    }

    // Check if report 2 exists
    $repCheck = $conn->query("SELECT id FROM report WHERE id = 2 LIMIT 1");
    if (!$repCheck || $repCheck->num_rows === 0) {
        echo "SKIP {$dbName}: report 2 not found\n";
        $conn->close();
        continue;
    }

    // Check if face_preview column already exists in report_column
    $colCheck = $conn->query("SELECT id FROM report_column WHERE report_id = 2 AND column_name = 'face_preview' LIMIT 1");
    if ($colCheck && $colCheck->num_rows > 0) {
        echo "EXISTS {$dbName}: face_preview column already registered for report 2\n";
    } else {
        // Shift existing columns at pos >= 2 to make room at pos 2
        $conn->query("UPDATE report_column SET position = position + 1 WHERE report_id = 2 AND position >= 2");
        
        $sql = "INSERT INTO report_column (report_id, column_name, header, position, mpos, url, concat_id, onclick, modal, export, isAdmin, created_by, created_at) " .
               "VALUES (2, 'face_preview', 'Face', 2, 0, '', '', 0, '', 0, 0, 1, CURRENT_TIMESTAMP)";
        if ($conn->query($sql)) {
            echo "INSERTED {$dbName}: face_preview registered at position 2 for report 2\n";
        } else {
            echo "ERROR {$dbName}: failed to insert report_column: {$conn->error}\n";
        }
    }
    $conn->close();
}
