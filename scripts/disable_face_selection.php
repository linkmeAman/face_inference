<?php
declare(strict_types=1);
require_once __DIR__ . '/../classes/dbconnection.class.php';
require_once __DIR__ . '/../classes/contracts/FaceRecognitionProvider.php';
require_once __DIR__ . '/../classes/services/FaceSelectionGate.php';
require_once __DIR__ . '/../classes/clients/FaceInferenceClient.php';
require_once __DIR__ . '/../classes/services/FaceAttendanceConfig.php';

$dbName = '';
foreach ($argv as $arg) if (strpos($arg, '--database=') === 0) $dbName = substr($arg, 11);
if (!in_array($dbName, ['ccc_database', 'ccc_database_test'], true)) {
    fwrite(STDERR, "Specify --database=ccc_database|ccc_database_test.\n");
    exit(2);
}
$db = (new dbconnection())->connect($dbName);
$provider = new FaceInferenceClient(FaceAttendanceConfig::getEnv('FACE_INFERENCE_URL'), FaceAttendanceConfig::getEnv('FACE_INFERENCE_SHARED_SECRET'));
$cancelled = FaceSelectionGate::disable($db, $provider);
echo "Selection off for {$dbName}; {$cancelled} active scans cancelled; sidecar eviction attempted.\n";
