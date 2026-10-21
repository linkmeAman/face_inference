<?php
declare(strict_types=1);
/* Creates a score-only export before retention deletion; never prints row data. */
require_once __DIR__ . '/../classes/dbconnection.class.php';
$database = ''; $apply = in_array('--apply', $argv, true);
foreach ($argv as $arg) if (strpos($arg, '--database=') === 0) $database = substr($arg, 11);
if (!in_array($database, ['ccc_database', 'ccc_database_test'], true)) { fwrite(STDERR, "Approved database required.\n"); exit(2); }
$db = (new dbconnection())->connect($database); if (!$db) exit(2);
$present = $db->query("SHOW TABLES LIKE 'face_experiment_log'");
if (!$present || !$present->num_rows) { echo "PLAN migration-not-applied; purge inactive\n"; exit(0); }
$count = (int)$db->query("SELECT COUNT(*) n FROM face_experiment_log WHERE expires_at<NOW()")->fetch_assoc()['n'];
if (!$apply) { echo "PLAN export-before-purge rows={$count}\n"; exit(0); }
$dir = '/var/lib/creativeculturalcentre/face-experiment-exports'; if (!is_dir($dir) && !mkdir($dir, 0700, true)) throw new RuntimeException('export directory unavailable');
$path = $dir . '/calibration-' . $database . '-' . gmdate('Ymd-His') . '.csv'; $fh = fopen($path, 'x'); if (!$fh) throw new RuntimeException('export unavailable');
fputcsv($fh, ['scores','decision','outcome','model_name','model_version','runtime','precision','preprocessing','policy','frame_index','pair_type']);
$rows = $db->query("SELECT frame_scores_json,decision,outcome_code,model_name,model_version,runtime,precision_mode,preprocessing_version,policy_version,pair_type FROM face_experiment_log WHERE expires_at<NOW()");
while ($row = $rows->fetch_assoc()) {
    $evidence = json_decode((string)$row['frame_scores_json'], true);
    foreach (($evidence['s'] ?? []) as $index => $scores) {
        // Export arrays only: never the HMAC dictionary or any other identifier.
        fputcsv($fh, [json_encode($scores),$row['decision'],$row['outcome_code'],$row['model_name'],$row['model_version'],$row['runtime'],$row['precision_mode'],$row['preprocessing_version'],$row['policy_version'],$index,$row['pair_type']]);
    }
}
fclose($fh); $hash = hash_file('sha256', $path); $deleted = 0;
do { $db->query("DELETE FROM face_experiment_log WHERE expires_at<NOW() LIMIT 1000"); $batch = $db->affected_rows; $deleted += $batch; } while ($batch === 1000);
$safePath = $db->real_escape_string($path); $db->query("INSERT INTO face_experiment_purge_ledger(export_sha256,exported_rows,deleted_rows,export_path,retain_until) VALUES ('{$hash}','{$count}','{$deleted}','{$safePath}',DATE_ADD(CURDATE(),INTERVAL 24 MONTH))");
echo "PURGED exported_rows={$count} deleted_rows={$deleted} export_sha256={$hash}\n";
