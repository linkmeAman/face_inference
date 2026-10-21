<?php
// Reuses the existing temporary source fixtures and disposable Practice provisioner.
require __DIR__.'/testing.php';
require_once dirname(__DIR__,2).'/scripts/browser_face_pilot.php';
$name='ccc_database_face_pilot_aabb1226';
$config=['source_database'=>'ccc_database','database'=>$name,'branch_id'=>2,'batch_id'=>33,'member_ids'=>[623,624,625],'contact_ids'=>[791,792,793],'item_ids'=>[1188,1189,1190],'owner_token'=>bin2hex(random_bytes(32)),'expires_at'=>gmdate('c',time()+3600)];
$admin=new mysqli('localhost','root','');provisionFacePilot($admin,$config);
try{
    $admin->multi_query(file_get_contents(dirname(__DIR__,2).'/database/migrations/20261012_01_browser_face_test_punch.sql'));while($admin->more_results())$admin->next_result();
    resetLifecycle($db);$db->query('DELETE FROM attendance');$db->query('DELETE FROM attendance_event');$db->query('UPDATE invoice_item SET sessions_used=0');
    $dir='/tmp/ccc-face-punch-20261012';putenv('FACE_WEB_EVENT_LOG='.$dir.'/backend-events.jsonl');
    $factory=fn($source)=>new BrowserFaceTestAttendance($source,(new dbconnection)->connect($name),$config);
    $test=new BrowserFaceService($db,$cipher,99,2,$policy,$factory);
    $context=['batch_id'=>11,'attendance_date'=>date('Y-m-d'),'session_id'=>7,'selected_time'=>'10:00:00'];
    $payload=['batch_id'=>11,'attendance_date'=>date('Y-m-d'),'attendance_session_id'=>7,'selected_time'=>'10:00:00'];
    foreach([[1,$a],[2,$b],[3,$c]] as [$id,$vector])$test->run('enroll',captureCommand($id,$vector,'punch_enroll_identity_'.$id)+['evaluation_context'=>$context]);
    $original=$db->query('SELECT calibration_id,encrypted_template FROM face_web_enrollment WHERE member_id=1')->fetch_assoc();
    $update=$test->run('enroll',captureCommand(1,$a,'punch_update_identity_1')+['evaluation_context'=>$context,'update_face'=>true]);
    check($update['test_ready'] && $update['capture_calibration_id']!==$original['calibration_id'],'Update Face supplies a new compatible TEST reference');
    check($db->query('SELECT calibration_id,encrypted_template FROM face_web_enrollment WHERE member_id=1')->fetch_assoc()===$original,'update preserves immutable encrypted original');
    $reject=$test->run('enroll',captureCommand(1,$b,'punch_rejected_update1')+['evaluation_context'=>$context,'update_face'=>true]);
    check($reject['state']==='REJECTED_DUPLICATE' && $reject['test_ready'],'rejected update preserves working saved reference');
    $scan=$test->run('open',$payload+['idempotency_key'=>'punch_open_scanner01']);$candidates=[];
    foreach([[1,$a],[2,$b]] as [$id,$vector]){
        $r=$test->run('attendance',['scan_id'=>$scan['scan_id'],'scan_token'=>$scan['scan_token'],'observations'=>[$vector,$vector,$vector],'model_version'=>$contract['model_version'],'preprocessing_version'=>$contract['preprocessing_version'],'idempotency_key'=>'punch_recognize_id_'.$id]);
        check($r['state']==='TEST_RECOGNIZED' && $r['person_id']===$id && $r['test_scan_id']===$scan['scan_id'],'recognized test identity has authenticated session receipt');
        $candidates[]=['person_id'=>$id,'receipt_key'=>$r['test_receipt_key']];
    }
    check((int)$admin->query('SELECT COUNT(*) FROM face_testing_attendance')->fetch_row()[0]===0,'recognition never persists attendance before punch');
    rejects(fn()=>$test->run('test_punch',$payload+['candidates'=>[],'idempotency_key'=>'punch_empty_request01']),'backend rejects empty punch');
    rejects(fn()=>$test->run('test_punch',array_replace($payload,['attendance_session_id'=>8,'selected_time'=>'12:00:00','candidates'=>$candidates,'idempotency_key'=>'punch_wrong_session1'])),'different-session receipt cannot punch');
    rejects(fn()=>$test->run('test_punch',$payload+['candidates'=>[['person_id'=>3,'receipt_key'=>$candidates[0]['receipt_key']]],'idempotency_key'=>'punch_forged_person01']),'recognized receipt cannot be reassigned to another member');
    $otherActor=new BrowserFaceService($db,$cipher,100,2,$policy,$factory);rejects(fn()=>$otherActor->run('test_punch',$payload+['candidates'=>$candidates,'idempotency_key'=>'punch_other_actor001']),'another operator cannot reuse recognition receipts');
    rejects(fn()=>$test->run('test_punch',$payload+['candidates'=>[$candidates[0],$candidates[0]],'idempotency_key'=>'punch_repeat_member1']),'duplicate member IDs cannot enter a punch');
    $test->run('close',['scan_id'=>$scan['scan_id'],'scan_token'=>$scan['scan_token'],'idempotency_key'=>'punch_stop_scanner01']);
    $db->query("UPDATE batch SET batch='FROM attendance / UPDATE attendance_event' WHERE id=11");
    $command=$payload+['candidates'=>$candidates,'idempotency_key'=>'punch_two_members01'];
    $admin->query('ALTER TABLE face_testing_attendance ADD CONSTRAINT punch_partial_fixture CHECK(contact_id<>102)');
    try{$test->run('test_punch',$command);throw new RuntimeException('Expected second-member infrastructure failure');}catch(mysqli_sql_exception $e){check((int)$admin->query('SELECT COUNT(*) FROM face_testing_attendance')->fetch_row()[0]===1,'partial infrastructure failure preserves first committed TEST result without claiming full success');}
    $admin->query('ALTER TABLE face_testing_attendance DROP CHECK punch_partial_fixture');$r=$test->run('test_punch',$command);
    check($admin->query('SELECT batch FROM face_testing_attendance LIMIT 1')->fetch_row()[0]==='FROM attendance / UPDATE attendance_event','isolated table mapping never rewrites quoted batch names');
    check($r['state']==='TEST_PUNCHED' && count($r['results'])===2 && $r['test_attendance_saved'] && !$r['attendance_saved'],'one punch persists both isolated TEST results');
    check((int)$admin->query('SELECT COUNT(*) FROM face_testing_attendance')->fetch_row()[0]===2 && (int)$admin->query('SELECT COUNT(*) FROM face_testing_attendance_event')->fetch_row()[0]===2,'actual isolated attendance rows and domain events exist');
    check($admin->query('SELECT contact_id,batch_id,session_id,negative FROM face_testing_attendance ORDER BY contact_id')->fetch_all(MYSQLI_ASSOC)===[['contact_id'=>'101','batch_id'=>'11','session_id'=>'7','negative'=>'0'],['contact_id'=>'102','batch_id'=>'11','session_id'=>'7','negative'=>'0']],'persisted rows belong to correct source contacts, batch and session');
    check((int)$db->query('SELECT COUNT(*) FROM attendance')->fetch_row()[0]===0 && (int)$db->query('SELECT COUNT(*) FROM attendance_event')->fetch_row()[0]===0 && (int)$db->query('SELECT SUM(sessions_used) FROM invoice_item')->fetch_row()[0]===0,'shared attendance, events and real membership usage unchanged');
    check($test->run('test_punch',$command)==$r,'lost successful response returns exact same committed request');
    $command['idempotency_key']='punch_repeated_members1';$again=$test->run('test_punch',$command);
    check(!array_filter($again['results'],fn($r)=>$r['state']!=='ALREADY_MARKED') && (int)$admin->query('SELECT COUNT(*) FROM face_testing_attendance_event')->fetch_row()[0]===2,'new-key repeat punch cannot duplicate rows or events');
    $restart=new BrowserFaceService($db,$cipher,99,2,$policy,$factory);check(count($restart->testAttendanceStatus($payload)['persons'])===2,'persisted TEST Present survives service restart/new connection');
    $db->query("DELETE FROM face_web_request WHERE request_key='punch_two_members01'");$db->query("INSERT INTO face_web_request(actor_id,request_key,action,payload_hash,response) VALUES(99,'punch_two_members01','test_punch','".hash('sha256',json_encode(['test_punch',2,array_replace($command,['idempotency_key'=>'punch_two_members01'])],JSON_PRESERVE_ZERO_FRACTION))."','{\"state\":\"PENDING\"}')");
    $command['idempotency_key']='punch_two_members01';check($test->run('test_punch',$command)['state']==='TEST_PUNCHED','reserved unknown-outcome request reconciles existing domain events safely');
    $shadow=new BrowserFaceService($db,$cipher,99,2,['ready'=>false,'operating_mode'=>'SHADOW'],$factory);rejects(fn()=>$shadow->run('test_punch',$payload+['candidates'=>$candidates,'idempotency_key'=>'punch_shadow_forbid01']),'SHADOW cannot access TEST persistence');
    rejects(fn()=>new AttendanceDomainService($db,'face_testing_'),'source connection cannot activate isolated table mapping');
    BrowserFacePilot::verify($admin,$config);check(true,'original Practice scope remains intact after multiple source identities');
    $events=array_map(fn($l)=>json_decode($l,true),file($dir.'/backend-events.jsonl',FILE_IGNORE_NEW_LINES));
    foreach(['enrollment_update_started','enrollment_updated','enrollment_update_failed','attendance_test_staged','attendance_test_punch_attempted','attendance_test_punched','attendance_test_punch_rejected_empty','attendance_test_punch_failed'] as $event)check(in_array($event,array_column($events,'event'),true),'inspected actual structured log contains '.$event);
    file_put_contents($dir.'/punch-db-evidence.json',json_encode(['rows'=>$admin->query('SELECT id,contact_id,batch_id,session_id,negative FROM face_testing_attendance')->fetch_all(MYSQLI_ASSOC),'events'=>$admin->query('SELECT person_id,batch_id,session_id,source FROM face_testing_attendance_event')->fetch_all(MYSQLI_ASSOC),'sanitized_logs'=>array_values(array_filter($events,fn($e)=>in_array($e['event'],['enrollment_updated','attendance_test_staged','attendance_test_punched'],true)))],JSON_PRETTY_PRINT));
}finally{
    $admin->select_db($name);$owner=$admin->query('SELECT token_hash FROM face_pilot_owner')->fetch_row()[0];if(hash_equals($owner,hash('sha256',$config['owner_token'])))$admin->query("DROP DATABASE `$name`");
}
