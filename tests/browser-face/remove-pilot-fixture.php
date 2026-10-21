<?php
if(PHP_SAPI!=='cli'){http_response_code(404);exit;}
mysqli_report(MYSQLI_REPORT_ERROR|MYSQLI_REPORT_STRICT);
$name='ccc_database_face_pilot_b1002026';$secret=getenv('FACE_BROWSER_TEST_KEY');$path='/tmp/ccc-browser-face-pilot-fixture.json';
if(getenv('FACE_BROWSER_TEST_DB')!=='ccc_database_test_browser_10092026' || $secret!=='public-fixture-test-key')throw new RuntimeException('Exact disposable regression scope required');
$c=json_decode(file_get_contents($path),true,32,JSON_THROW_ON_ERROR);if(($c['database'] ?? '')!==$name || !hash_equals($c['owner_token'],hash('sha256',$secret.':owned-disposable-pilot')))throw new RuntimeException('Disposable pilot configuration mismatch');
$admin=new mysqli('localhost','root','');$row=$admin->query("SELECT token_hash FROM `$name`.face_pilot_owner")->fetch_row();if(!$row || !hash_equals($row[0],hash('sha256',$c['owner_token'])))throw new RuntimeException('Disposable pilot ownership mismatch');
$admin->query("DROP DATABASE `$name`");$admin->query("REVOKE ALL ON `$name`.* FROM 'nahid'@'localhost'");unlink($path);echo "Removed only the owned disposable browser pilot.\n";
if(is_file('/tmp/ccc-browser-face-pilot-demo-fixture.json'))unlink('/tmp/ccc-browser-face-pilot-demo-fixture.json');
