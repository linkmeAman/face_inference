<?php
if(PHP_SAPI!=='cli') {http_response_code(404);exit;}
mysqli_report(MYSQLI_REPORT_ERROR | MYSQLI_REPORT_STRICT);
$name=getenv('FACE_BROWSER_TEST_DB');
if(!preg_match('/^ccc_database_test_browser_[a-f0-9]{8}$/D',(string)$name))throw new RuntimeException('Exact isolated test database name required');
$admin=new mysqli('localhost',getenv('FACE_TEST_ADMIN_USER') ?: 'root',getenv('FACE_TEST_ADMIN_PASSWORD') ?: '');
$owner=$admin->query("SELECT token_hash FROM `$name`.browser_fixture_owner")->fetch_row()[0];
if(!hash_equals($owner,hash('sha256',getenv('FACE_BROWSER_TEST_KEY') ?: 'fixture-only')))throw new RuntimeException('Fixture ownership mismatch');
$admin->query("DROP DATABASE `$name`");
$admin->query("REVOKE ALL ON `$name`.* FROM 'nahid'@'localhost'");
echo 'Removed only owned isolated fixture '.$name.PHP_EOL;
