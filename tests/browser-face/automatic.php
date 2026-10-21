<?php
// Existing temporary schema/service/model contract. Never production writes.
require __DIR__.'/field-release.php';
// Capture the private audit log (must live outside the app root) to assert the LIVE
// write path emits explicit operational-attendance events.
$auditDir=sys_get_temp_dir().'/ccc-audit-'.bin2hex(random_bytes(6));@mkdir($auditDir,0700);$auditPrior=getenv('FACE_WEB_EVENT_LOG');putenv('FACE_WEB_EVENT_LOG='.$auditDir.'/events.jsonl');
register_shutdown_function(function()use($auditDir,$auditPrior){putenv($auditPrior===false?'FACE_WEB_EVENT_LOG':'FACE_WEB_EVENT_LOG='.$auditPrior);array_map('unlink',glob($auditDir.'/*')?:[]);@rmdir($auditDir);});
$auditEvents=fn()=>array_values(array_filter(array_map(fn($l)=>json_decode($l,true)['event']??'',explode("\n",@file_get_contents($auditDir.'/events.jsonl')?:''))));
resetLifecycle($db);$db->query('DELETE FROM attendance');$db->query('DELETE FROM attendance_event');$db->query('UPDATE invoice_item SET sessions_used=0');$db->query('UPDATE member SET status=1,park=0');
$db->query("UPDATE invoice_invoiceitem_module_view SET end_date='2100-01-01'");
$db->query('CREATE TEMPORARY TABLE attendance_settings(bid INT PRIMARY KEY,deduct_session_absentee INT)');$db->query('INSERT INTO attendance_settings VALUES(2,1)');
$approved=['ready'=>true,'DUPLICATE_THRESHOLD'=>.99,'ATTENDANCE_THRESHOLD'=>.99,'report_sha256'=>str_repeat('a',64),'operating_mode'=>'LIVE','rollout_authorized'=>true,'rollout_batch_id'=>11,'rollout_scope_sha256'=>str_repeat('b',64),'finalize_absences'=>true];
$live=new BrowserFaceService($db,$cipher,99,2,$approved);$context=['batch_id'=>11,'attendance_date'=>date('Y-m-d'),'session_id'=>7,'selected_time'=>'10:00:00'];
$pendingService=new BrowserFaceService($db,$cipher,99,2,['ready'=>false,'operating_mode'=>'SHADOW']);
$pendingService->run('enroll',captureCommand(1,$a,'automatic_pending_001')+['evaluation_context'=>$context]);
$original=$db->query('SELECT encrypted_template,calibration_id,capture_session FROM face_web_enrollment WHERE member_id=1')->fetch_assoc();
$live->run('activate_pending',['idempotency_key'=>'automatic_activate001']);
check($db->query('SELECT state FROM face_web_enrollment WHERE member_id=1')->fetch_row()[0]==='ACTIVE','approved LIVE activates stored pending capture without recapture');
check($original===$db->query('SELECT encrypted_template,calibration_id,capture_session FROM face_web_enrollment WHERE member_id=1')->fetch_assoc(),'original reference remains byte-for-byte immutable');
$scan=$live->run('open',['batch_id'=>11,'attendance_date'=>date('Y-m-d'),'attendance_session_id'=>8,'selected_time'=>'12:00:00','idempotency_key'=>'automatic_open_00001']);
check($scan['operating_mode']==='LIVE' && $scan['finalization_enabled'],'LIVE advertises scheduled finalization only when approved');
// Keep cutoff in the future for recognition regardless of machine wall time.
$db->query("UPDATE session SET end_time='23:59:59' WHERE id=8");
$base=['scan_id'=>$scan['scan_id'],'scan_token'=>$scan['scan_token'],'model_version'=>$contract['model_version'],'preprocessing_version'=>$contract['preprocessing_version']];
$arrival=$base+['observations'=>[$a,$a,$a],'idempotency_key'=>'automatic_arrival001'];
$one=$live->run('attendance',$arrival);check($one['state']==='RECORDED' && $one['person_id']===1 && !isset($one['suggestion']),'LIVE marks Present without any review receipt');
check($db->query('SELECT source FROM attendance_event')->fetch_row()[0]==='FACE_MATCH','automatic Present is audited FACE_MATCH');
check($live->run('attendance',$arrival)==$one,'lost LIVE response reconciles the identical request');
$repeat=$arrival;$repeat['idempotency_key']='automatic_repeat001';check($live->run('attendance',$repeat)['state']==='ALREADY_MARKED','repeated recognition is idempotent across request keys');
$events=$auditEvents();check(in_array('attendance_marked',$events,true),'LIVE RECORDED write emits an explicit attendance_marked audit event');
check(in_array('attendance_already_marked',$events,true),'idempotent ALREADY_MARKED write emits an explicit attendance_already_marked audit event');
check(!in_array('attendance_failed',$events,true),'no attendance_failed event on a successful LIVE write');
check((int)$db->query('SELECT sessions_used FROM invoice_item WHERE id=21')->fetch_row()[0]===1,'automatic Present deducts membership exactly once');
$unknown=$arrival;$unknown['observations']=[$b,$b,$b];$unknown['idempotency_key']='automatic_unknown001';check($live->run('attendance',$unknown)['state']==='UNKNOWN','unknown face never marks Present');
$conflict=$arrival;$conflict['observations']=[$a,$b,$a];$conflict['idempotency_key']='automatic_conflict01';check($live->run('attendance',$conflict)['state']==='UNKNOWN','inconsistent observations never mark Present');
$changed=new BrowserFaceService($db,$cipher,99,2,array_replace($approved,['report_sha256'=>str_repeat('c',64)]));
check($changed->run('attendance',array_replace($arrival,['idempotency_key'=>'automatic_policy_new1']))['state']==='SCAN_MODE_CHANGED','changed matching report invalidates existing scan');
$final=['scan_id'=>$scan['scan_id'],'scan_token'=>$scan['scan_token'],'idempotency_key'=>'automatic_finalize01'];
rejects(fn()=>$live->run('finalize',$final),'opening scanner does not authorize early absence writes');
$shadow=new BrowserFaceService($db,$cipher,99,2,array_replace($approved,['operating_mode'=>'SHADOW']));rejects(fn()=>$shadow->run('finalize',$final),'SHADOW cannot finalize absences');
$unapproved=new BrowserFaceService($db,$cipher,99,2,array_replace($approved,['finalize_absences'=>false]));rejects(fn()=>$unapproved->run('finalize',$final),'cutoff approval is required independently from LIVE matching');
check((int)$db->query('SELECT COUNT(*) FROM attendance')->fetch_row()[0]===1,'failed recognition and early finalization leave records unchanged');
$live->run('close',['scan_id'=>$scan['scan_id'],'scan_token'=>$scan['scan_token'],'idempotency_key'=>'automatic_close_0001']);
check((int)$db->query('SELECT COUNT(*) FROM attendance')->fetch_row()[0]===1,'camera close never finalizes unmatched roster');
$db->query("UPDATE face_web_request SET response='{\"state\":\"PENDING\"}' WHERE request_key='automatic_arrival001'");
$disabled=new BrowserFaceService($db,$cipher,99,2,array_replace($approved,['operating_mode'=>'DISABLED']));check($disabled->run('attendance',$arrival)['state']==='ALREADY_MARKED','committed LIVE response recovers even after rollback and camera close');
// Scheduled end is now past. A closed camera is irrelevant to cutoff validity.
$db->query("UPDATE session SET start_time='00:00:00',end_time='00:00:01' WHERE id=8");
$out=$live->run('finalize',$final);check($out['state']==='FINALIZED','approved scheduled cutoff finalizes closed scan safely');
check((int)$db->query('SELECT negative FROM attendance WHERE contact_id=101')->fetch_row()[0]===0,'finalization preserves existing Present');
check((int)$db->query('SELECT negative FROM attendance WHERE contact_id=102')->fetch_row()[0]===1,'only unmatched eligible member becomes Absent');
check($db->query("SELECT event_type FROM attendance_event WHERE person_id=102")->fetch_row()[0]==='ATTENDANCE_FINALIZED','absence uses domain audit event');
check((int)$db->query('SELECT sessions_used FROM invoice_item WHERE id=21')->fetch_row()[0]===1,'finalization never deducts Present twice');
check($live->run('finalize',$final)==$out,'finalization is journal-idempotent');
$final['idempotency_key']='automatic_finalize02';check($live->run('finalize',$final)['state']==='FINALIZED','repeated cutoff reconciles prior finalization');
check((int)$db->query('SELECT COUNT(*) FROM attendance_event')->fetch_row()[0]===2,'repeated cutoff creates no duplicate events');
check($original===$db->query('SELECT encrypted_template,calibration_id,capture_session FROM face_web_enrollment WHERE member_id=1')->fetch_assoc(),'attendance and finalization never adapt original reference');
check(!isset($live->options()['persons'][0]['portrait']),'embedding-only enrollment exposes no reconstructed portrait');
// Finite unmatched membership, branch absence-deduction settings, and existing exempt state.
$db->query('DELETE FROM attendance');$db->query('DELETE FROM attendance_event');$db->query('UPDATE invoice_item SET sessions_used=0');$db->query('UPDATE attendance_settings SET deduct_session_absentee=0');
$final['idempotency_key']='automatic_nodeduct01';$live->run('finalize',$final);check((int)$db->query('SELECT sessions_used FROM invoice_item WHERE id=21')->fetch_row()[0]===0,'branch non-deducting absence policy preserved');
$db->query('DELETE FROM attendance');$db->query('DELETE FROM attendance_event');$db->query('UPDATE attendance_settings SET deduct_session_absentee=1');
$final['idempotency_key']='automatic_deduct_abs1';$live->run('finalize',$final);check((int)$db->query('SELECT sessions_used FROM invoice_item WHERE id=21')->fetch_row()[0]===1,'branch deducting absence policy applies exactly once');
$final['idempotency_key']='automatic_deduct_abs2';$live->run('finalize',$final);check((int)$db->query('SELECT sessions_used FROM invoice_item WHERE id=21')->fetch_row()[0]===1,'repeat absence finalization cannot deduct twice');
$db->query('UPDATE attendance SET negative=2 WHERE contact_id=101');$db->query('UPDATE member SET status=0 WHERE id=2');
$final['idempotency_key']='automatic_exempt_001';$live->run('finalize',$final);check((int)$db->query('SELECT negative FROM attendance WHERE contact_id=101')->fetch_row()[0]===2,'recorded exempt state is preserved');
check((int)$db->query('SELECT COUNT(*) FROM attendance')->fetch_row()[0]===2,'inactive members are not rewritten');
echo "Automatic LIVE and scheduled finalization checks passed\n";
// A partial cutoff is restart-safe: retry original key after the eligibility
// inconsistency is repaired, without replaying deductions for saved members.
$db->query('DELETE FROM attendance');$db->query('DELETE FROM attendance_event');$db->query('UPDATE member SET status=1');$db->query('UPDATE invoice_item SET sessions_used=0');
$db->query("UPDATE invoice_invoiceitem_view SET end_date='2000-01-01' WHERE contact_id=101");
$final['idempotency_key']='automatic_partial001';$partial=$live->run('finalize',$final);check($partial['state']==='PARTIAL_FINALIZATION','partial cutoff reports actual rejected and saved members');
$db->query("UPDATE invoice_invoiceitem_view SET end_date='2100-01-01' WHERE contact_id=101");
check($live->run('finalize',$final)['state']==='FINALIZED','same partial cutoff key retries only uncommitted domain writes');
check((int)$db->query('SELECT COUNT(*) FROM attendance_event')->fetch_row()[0]===2 && (int)$db->query('SELECT sessions_used FROM invoice_item WHERE id=21')->fetch_row()[0]===1,'partial recovery creates exactly one event and deduction per member');
// Present and cutoff events share a domain lock across MySQL connections.
$domain=new AttendanceDomainService($db);$method=new ReflectionMethod($domain,'businessLockName');
$cmd=['branch_id'=>2,'batch_id'=>11,'attendance_date'=>date('Y-m-d'),'session_id'=>8,'person_type'=>'M','member_id'=>101,'event_type'=>'PUNCH_IN'];
$presentLock=$method->invoke($domain,$cmd);$cmd['event_type']='ATTENDANCE_FINALIZED';$absenceLock=$method->invoke($domain,$cmd);
check($presentLock===$absenceLock,'Present and cutoff share one business lock for the same member/session');
$peer=(new dbconnection)->connect('ccc_database_test');$s=$db->prepare('SELECT GET_LOCK(?,0)');$s->bind_param('s',$presentLock);$s->execute();$s->get_result()->fetch_row();
$s=$peer->prepare('SELECT GET_LOCK(?,0)');$s->bind_param('s',$absenceLock);$s->execute();check((int)$s->get_result()->fetch_row()[0]===0,'simultaneous Present/cutoff connections cannot acquire independent locks');
$s=$db->prepare('SELECT RELEASE_LOCK(?)');$s->bind_param('s',$presentLock);$s->execute();$s->get_result()->fetch_row();$peer->close();
$beforeCli=(int)$db->query('SELECT COUNT(*) FROM attendance')->fetch_row()[0];
try{adminMain(['admin','finalize-due','--database','ccc_database_test','--branch','2'],$db);throw new RuntimeException('Expected disabled scheduled finalizer');}catch(RuntimeException $e){check($e->getMessage()==='Validated approved release configuration required','scheduled finalizer rejects the unvalidated installed configuration in an isolated fixture');}
check((int)$db->query('SELECT COUNT(*) FROM attendance')->fetch_row()[0]===$beforeCli,'unvalidated scheduled finalizer makes no attendance write');
$db->query("INSERT INTO attendance(contact_id,batch_id,date,session_id,type,negative,bid,park) VALUES(0,11,'".date('Y-m-d')."',8,'B',2,2,0)");
$final['idempotency_key']='automatic_cancelled01';rejects(fn()=>$live->run('finalize',$final),'cancelled batch session never creates automatic absences');
$live->run('close',['scan_id'=>$scan['scan_id'],'scan_token'=>$scan['scan_token'],'idempotency_key'=>'automatic_close_final1']);
check($db->query('SELECT status FROM attendance_scan WHERE id='.(int)$scan['scan_id'])->fetch_row()[0]==='FINALIZED','closing a finalized camera cannot undo its completion status');
