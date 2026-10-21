<?php
if(PHP_SAPI!=='cli'){http_response_code(404);exit;}
require_once dirname(__DIR__,2).'/classes/dbconnection.class.php';
require_once dirname(__DIR__,2).'/classes/services/BrowserFacePolicy.php';
mysqli_report(MYSQLI_REPORT_ERROR | MYSQLI_REPORT_STRICT);
$root=dirname(__DIR__,2);$db=(new dbconnection())->connect('ccc_database_test');
if($db->query('SELECT DATABASE()')->fetch_row()[0]!=='ccc_database_test')throw new RuntimeException('Test database required');
$base=file_get_contents($root.'/database/migrations/20260911_02_face_attendance_tables.sql');
$modelSql=substr($base,0,strpos($base,';')+1);
$db->query(str_replace('CREATE TABLE IF NOT EXISTS','CREATE TEMPORARY TABLE IF NOT EXISTS',$modelSql));
$provider=file_get_contents($root.'/database/migrations/20260921_01_supervised_face_scan_contract.sql');
$db->query(substr($provider,0,strpos($provider,';')+1));
$migration=file_get_contents($root.'/database/migrations/20261009_01_browser_face.sql');
$migration=str_replace('CREATE TABLE IF NOT EXISTS','CREATE TEMPORARY TABLE IF NOT EXISTS',$migration);
for($run=0;$run<2;$run++){
    $db->multi_query($migration);while($db->more_results()){$db->next_result();}
}
$contract=BrowserFacePolicy::contract();
$row=$db->query('SELECT * FROM face_model_version')->fetch_assoc();
if((int)$db->query('SELECT COUNT(*) FROM face_model_version')->fetch_row()[0]!==1)throw new RuntimeException('Migration duplicated model registry');
if($row['model_name']!==$contract['model_version'] || $row['preprocessing_version']!==$contract['preprocessing_version'] || $row['model_checksum']!==$contract['recognizer_source_sha256'])throw new RuntimeException('Model contract mismatch');
$indexes=$db->query('SHOW INDEX FROM face_web_request WHERE Key_name=\'PRIMARY\'')->fetch_all(MYSQLI_ASSOC);
if(array_column($indexes,'Column_name')!==['actor_id','request_key'])throw new RuntimeException('Idempotency primary key missing');
if(!BrowserFacePolicy::installed($db))throw new RuntimeException('Browser registry installation not detected');
$db->query("UPDATE face_model_version SET status='RETIRED'");
if(BrowserFacePolicy::installed($db))throw new RuntimeException('Retired browser registry treated as installed');
echo 'PASS additive migration against real face schema; repeat safe; registry contract and request uniqueness verified on temporary tables'.PHP_EOL;

$extension=str_replace('CREATE TABLE IF NOT EXISTS','CREATE TEMPORARY TABLE IF NOT EXISTS',file_get_contents($root.'/database/migrations/20261009_02_browser_face_collection.sql'));
for($run=0;$run<2;$run++){$db->multi_query($extension);while($db->more_results())$db->next_result();}
foreach(['face_web_calibration','face_web_migration'] as $table)if(!$db->query("SHOW COLUMNS FROM `$table`")->num_rows)throw new RuntimeException('Extension table missing');
echo "PASS calibration/migration schema repeatability on temporary tables\n";
$lifecycle=str_replace('CREATE TABLE IF NOT EXISTS','CREATE TEMPORARY TABLE IF NOT EXISTS',file_get_contents($root.'/database/migrations/20261009_03_browser_face_lifecycle.sql'));
for($run=0;$run<2;$run++){$db->multi_query($lifecycle);while($db->more_results())$db->next_result();}
foreach(['face_web_enrollment','face_web_evidence'] as $table)if(!$db->query("SHOW COLUMNS FROM `$table`")->num_rows)throw new RuntimeException('Lifecycle table missing');
echo "PASS lifecycle/evidence schema repeatability on temporary tables\n";
