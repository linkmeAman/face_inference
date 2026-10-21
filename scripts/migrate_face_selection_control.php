<?php
declare(strict_types=1);
require_once __DIR__ . '/../classes/dbconnection.class.php';

$dbName = '';
foreach ($argv as $arg) {
    if (strpos($arg, '--database=') === 0) $dbName = substr($arg, 11);
}
if (!in_array($dbName, ['ccc_database', 'ccc_database_test'], true)) {
    fwrite(STDERR, "Specify --database=ccc_database|ccc_database_test.\n");
    exit(2);
}
$sql = 'CREATE TABLE IF NOT EXISTS face_selection_control (
 id TINYINT UNSIGNED NOT NULL PRIMARY KEY,
 enabled TINYINT(1) NOT NULL DEFAULT 0,
 updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4';
if (!in_array('--apply', $argv, true)) {
    echo "PLAN {$dbName}: {$sql}\n";
    exit(0);
}
$db = (new dbconnection())->connect($dbName);
if (!$db->query($sql) || !$db->query('INSERT IGNORE INTO face_selection_control (id, enabled) VALUES (1, 0)')) {
    throw new RuntimeException($db->error);
}
echo "Selection switch installed for {$dbName}; state remains " . ($db->query('SELECT enabled FROM face_selection_control WHERE id=1')->fetch_assoc()['enabled']) . ".\n";
