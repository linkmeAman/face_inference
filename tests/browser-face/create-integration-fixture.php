<?php
// Creates only a newly named isolated database; never migrates a real tenant.
require_once dirname(__DIR__,2).'/classes/dbconnection.class.php';
require_once dirname(__DIR__,2).'/classes/services/BrowserFacePolicy.php';
if(PHP_SAPI!=='cli') {http_response_code(404);exit;}
mysqli_report(MYSQLI_REPORT_ERROR | MYSQLI_REPORT_STRICT);
$name=getenv('FACE_BROWSER_TEST_DB');
if(!preg_match('/^ccc_database_test_browser_[a-f0-9]{8}$/D',(string)$name))throw new RuntimeException('Exact isolated test database name required');
$connector=new dbconnection;
$admin=new mysqli('localhost',getenv('FACE_TEST_ADMIN_USER') ?: 'root',getenv('FACE_TEST_ADMIN_PASSWORD') ?: '');
$admin->query("CREATE DATABASE `$name`"); // Intentionally fails rather than reuse existing data.
$admin->query("GRANT ALL ON `$name`.* TO 'nahid'@'localhost'"); // Only this new test namespace.
$db=$connector->connect($name);
$db->query('CREATE TABLE browser_fixture_owner(token_hash CHAR(64) NOT NULL)');
$owner=hash('sha256',getenv('FACE_BROWSER_TEST_KEY') ?: 'fixture-only');
$db->query("INSERT INTO browser_fixture_owner VALUES('$owner')");
$base=file_get_contents(__DIR__.'/backend.php');preg_match('/\$schema=<<<\x27SQL\x27\n(.*?)\nSQL;/s',$base,$match);
$schema=str_replace('CREATE TEMPORARY TABLE','CREATE TABLE',$match[1]);
$db->multi_query($schema);while($db->more_results())$db->next_result();
$core=file_get_contents(dirname(__DIR__).'/attendance/ATT-CORE-01-domain-service.php');preg_match('/\$schema = <<<\x27SQL\x27\n(.*?)\nSQL;/s',$core,$match);
foreach(explode(";\n",str_replace('CREATE TEMPORARY TABLE','CREATE TABLE',$match[1])) as $sql)if(trim($sql)!=='')$db->query($sql);
$db->query('ALTER TABLE batch ADD inactive INT DEFAULT 0, ADD start_date DATE, ADD end_date DATE');
$extra=file_get_contents(__DIR__.'/attendance-fixture.php');preg_match('/\$sql=<<<\x27SQL\x27\n(.*?)\nSQL;/s',$extra,$match);
$db->multi_query(str_replace('CREATE TEMPORARY TABLE','CREATE TABLE',$match[1]));while($db->more_results())$db->next_result();
$db->query("INSERT INTO batch(id,bid,venue_id,completed_sessions,batch,start_date,end_date) VALUES(11,2,501,3,'Browser fixture','2000-01-01','2100-01-01')");
$today=date('Y-m-d');$day=date('w');
$db->query("INSERT INTO session VALUES(7,11,'$today','10:00:00','11:00:00',0),(8,11,'$today','12:00:00','13:00:00',0),(9,11,'$today','14:00:00','15:00:00',0),(10,11,'$today','16:00:00','17:00:00',0)");
$db->query("INSERT INTO batch_time VALUES(1,11,$day,'10:00:00','13:00:00',0)");
$db->query("INSERT INTO invoice_item(id,sessions,sessions_used,start_date,end_date,batch_id) VALUES(21,10,0,'2000-01-01','2100-01-01',11),(22,0,0,'2000-01-01','2100-01-01',11)");
$db->query("INSERT INTO invoice_invoiceitem_view(item_id,contact_id,batch_id,start_date,end_date,sessions,sessions_used) VALUES(21,101,11,'2000-01-01','2100-01-01',10,0),(22,102,11,'2000-01-01','2100-01-01',0,0)");
$db->query('INSERT INTO invoice_invoiceitem_module_view SELECT * FROM invoice_invoiceitem_view');
$contract=BrowserFacePolicy::contract();
$stmt=$db->prepare('INSERT INTO face_model_version(model_name,preprocessing_version,model_checksum,embedding_dimension,status) VALUES(?,?,?,128,\'PENDING_CALIBRATION\')');
$stmt->bind_param('sss',$contract['model_version'],$contract['preprocessing_version'],$contract['recognizer_source_sha256']);$stmt->execute();
echo 'Created isolated public-fixture database '.$name.PHP_EOL;

$db->multi_query(file_get_contents(dirname(__DIR__,2).'/database/migrations/20261009_02_browser_face_collection.sql'));while($db->more_results())$db->next_result();

$db->multi_query(file_get_contents(dirname(__DIR__,2).'/database/migrations/20261009_03_browser_face_lifecycle.sql'));while($db->more_results())$db->next_result();
