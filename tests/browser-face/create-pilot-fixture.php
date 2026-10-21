<?php
if(PHP_SAPI!=='cli'){http_response_code(404);exit;}
require_once dirname(__DIR__,2).'/scripts/browser_face_pilot.php';
mysqli_report(MYSQLI_REPORT_ERROR|MYSQLI_REPORT_STRICT);
$name=getenv('FACE_BROWSER_TEST_DB');$secret=getenv('FACE_BROWSER_TEST_KEY');
if($name!=='ccc_database_test_browser_10092026' || $secret!=='public-fixture-test-key')throw new RuntimeException('Exact owned regression fixture required');
$admin=new mysqli('localhost','root','');$owner=$admin->query("SELECT token_hash FROM `$name`.browser_fixture_owner")->fetch_row()[0];if(!hash_equals($owner,hash('sha256',$secret)))throw new RuntimeException('Regression ownership mismatch');
$config=['source_database'=>'ccc_database','database'=>'ccc_database_face_pilot_b1002026','branch_id'=>2,'batch_id'=>33,'member_ids'=>[623,624,625],'contact_ids'=>[791,792,793],'item_ids'=>[1188,1189,1190],'owner_token'=>hash('sha256',$secret.':owned-disposable-pilot'),'expires_at'=>gmdate('c',time()+3600)];
$path='/tmp/ccc-browser-face-pilot-fixture.json';$old=umask(0077);try{$f=fopen($path,'x');if(!$f)throw new RuntimeException('Pilot fixture file exists');fwrite($f,json_encode($config));fclose($f);}finally{umask($old);}
provisionFacePilot($admin,$config);$admin->multi_query(file_get_contents(dirname(__DIR__,2).'/database/migrations/20261012_01_browser_face_test_punch.sql'));while($admin->more_results())$admin->next_result();echo 'Created owned disposable pilot for complete attendance.php regression; no live biometric data copied.'.PHP_EOL;
