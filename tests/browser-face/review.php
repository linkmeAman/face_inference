<?php
// Reuses the existing connection-local temporary schema; no tenant writes.
require __DIR__.'/rollout.php';
resetLifecycle($db);$db->query('DELETE FROM attendance_event');$db->query('DELETE FROM attendance');$db->query('UPDATE invoice_item SET sessions_used=0');$db->query('UPDATE member SET status=1,park=0');
$policy=['ready'=>false,'DUPLICATE_THRESHOLD'=>null,'ATTENDANCE_THRESHOLD'=>null,'operating_mode'=>'SHADOW'];
$shadow=new BrowserFaceService($db,$cipher,99,2,$policy);
$context=['batch_id'=>11,'attendance_date'=>date('Y-m-d'),'session_id'=>7,'selected_time'=>'10:00:00'];
foreach([1=>$a,2=>$b] as $id=>$v)$shadow->run('enroll',captureCommand($id,$v,'review_enroll_'.$id.'00001')+['evaluation_context'=>$context]);
$scan=$shadow->run('open',['batch_id'=>11,'attendance_date'=>date('Y-m-d'),'attendance_session_id'=>7,'selected_time'=>'10:00:00','idempotency_key'=>'review_shadow_open01']);
$base=['scan_id'=>$scan['scan_id'],'scan_token'=>$scan['scan_token'],'model_version'=>$contract['model_version'],'preprocessing_version'=>$contract['preprocessing_version']];
foreach([1=>$a,2=>$b] as $id=>$v){$out=$shadow->run('attendance',$base+['observations'=>[$v,$v,$v],'idempotency_key'=>'review_recognize_'.$id.'001']);check($out['suggestion']['person_id']===$id && $out['suggestion']['requires_independent_verification']===true,'shadow suggestion is independently verified rather than accepted recognition');}
check((int)$db->query('SELECT COUNT(*) FROM attendance')->fetch_row()[0]===0 && (int)$db->query('SELECT sessions_used FROM invoice_item WHERE id=21')->fetch_row()[0]===0,'multiple recognitions write neither attendance nor deductions');
check((int)$db->query('SELECT COUNT(*) FROM face_template')->fetch_row()[0]===0,'shadow suggestions do not activate pending identities');
$conflict=$shadow->run('attendance',$base+['observations'=>[$a,$b,$a],'idempotency_key'=>'review_conflicting_01']);check($conflict['suggestion']===null,'conflicting unvalidated observations do not guess a review identity');
$confirm=['scan_id'=>$scan['scan_id'],'scan_token'=>$scan['scan_token'],'verified'=>true,'candidates'=>[['person_id'=>1,'receipt_key'=>'review_recognize_1001'],['person_id'=>2,'receipt_key'=>'review_recognize_2001']],'idempotency_key'=>'review_confirm_list01'];
$bad=$confirm;$bad['candidates'][0]['receipt_key']='review_conflicting_01';$bad['idempotency_key']='review_forged_receipt1';rejects(fn()=>$shadow->run('confirm',$bad),'unknown or conflicting receipt cannot be confirmed');
$bad=$confirm;$bad['candidates'][0]['person_id']=2;$bad['idempotency_key']='review_changed_person1';rejects(fn()=>$shadow->run('confirm',$bad),'receipt cannot be reassigned to another child');
$other=new BrowserFaceService($db,$cipher,100,2,$policy);rejects(fn()=>$other->run('confirm',$confirm),'other operator cannot confirm a scan');
$scan2=$shadow->run('open',['batch_id'=>11,'attendance_date'=>date('Y-m-d'),'attendance_session_id'=>8,'selected_time'=>'12:00:00','idempotency_key'=>'review_other_scan001']);
$bad=$confirm;$bad['scan_id']=$scan2['scan_id'];$bad['scan_token']=$scan2['scan_token'];$bad['idempotency_key']='review_other_context1';rejects(fn()=>$shadow->run('confirm',$bad),'suggestions cannot cross attendance sessions');
$bad=$confirm;$bad['verified']=false;$bad['idempotency_key']='review_unverified001';rejects(fn()=>$shadow->run('confirm',$bad),'operator verification required for shadow manual confirmation');
// Eligibility can change after capture. Process only the reviewed list and
// report actual partial outcomes without touching historical/manual records.
$db->query("UPDATE invoice_invoiceitem_module_view SET end_date='2000-01-01' WHERE contact_id=102");
$partial=$shadow->run('confirm',$confirm);check($partial['state']==='PARTIAL_CONFIRMATION' && $partial['results'][0]['state']==='RECORDED' && $partial['results'][1]['state']==='REJECTED','confirmation reports genuine per-member partial persistence');
check($shadow->run('confirm',$confirm)==$partial,'partial confirmation key replays immutable outcomes');
check((int)$db->query('SELECT COUNT(*) FROM attendance')->fetch_row()[0]===1 && (int)$db->query('SELECT COUNT(*) FROM attendance_event')->fetch_row()[0]===1,'only successful reviewed member creates row and event');
check($db->query('SELECT source FROM attendance_event')->fetch_row()[0]==='TEACHER_CONFIRMED','unvalidated suggestions persist as operator-confirmed manual source');
$meta=json_decode($db->query('SELECT metadata FROM attendance_event')->fetch_row()[0],true);check($meta['operator_confirmed'] && !$meta['biometric_acceptance'],'confirmation never approves biometric matching policy');
$db->query("UPDATE invoice_invoiceitem_module_view SET end_date='2100-01-01' WHERE contact_id=102");
$retry=$confirm;$retry['candidates']=[$confirm['candidates'][1]];$retry['idempotency_key']='review_retry_failed01';$success=$shadow->run('confirm',$retry);check($success['state']==='CONFIRMED','failed member can retry after eligibility recovery');
$retry=$confirm;$retry['idempotency_key']='review_repeat_all001';$all=$shadow->run('confirm',$retry);check(array_column($all['results'],'state')===['ALREADY_MARKED','ALREADY_MARKED'],'repeat reviewed confirmation reconciles committed members');
check((int)$db->query('SELECT sessions_used FROM invoice_item WHERE id=21')->fetch_row()[0]===1 && (int)$db->query('SELECT COUNT(*) FROM attendance_event')->fetch_row()[0]===2,'repeat confirmation cannot duplicate deductions or events');
// Reserve/commit crash recovery remains safe after closing the camera lease.
$db->query("UPDATE face_web_request SET response='{\"state\":\"PENDING\"}' WHERE request_key='review_retry_failed01'");
$shadow->run('close',['scan_id'=>$scan['scan_id'],'scan_token'=>$scan['scan_token'],'idempotency_key'=>'review_close_scan001']);
$recovered=$shadow->run('confirm',array_replace($retry,['candidates'=>[$confirm['candidates'][1]],'idempotency_key'=>'review_retry_failed01']));check($recovered['results'][0]['state']==='ALREADY_MARKED','lost committed response reconciles after camera lease closes');
// Existing manual Absent must not be silently rewritten as Present.
$staged=$shadow->run('attendance',array_replace($base,['scan_id'=>$scan2['scan_id'],'scan_token'=>$scan2['scan_token'],'observations'=>[$a,$a,$a],'idempotency_key'=>'review_session2_face1']));
(new AttendanceDomainService($db))->recordMemberAttendance(['branch_id'=>2,'batch_id'=>11,'attendance_date'=>date('Y-m-d'),'session_id'=>8,'member_id'=>101,'source'=>'MANUAL','person_type'=>'M','event_type'=>'PUNCH_IN','negative'=>1,'actor_employee_id'=>99,'client_attempt_id'=>'manual_absent_first01']);
$manual=$shadow->run('confirm',['scan_id'=>$scan2['scan_id'],'scan_token'=>$scan2['scan_token'],'verified'=>true,'candidates'=>[['person_id'=>1,'receipt_key'=>'review_session2_face1']],'idempotency_key'=>'review_preserve_absent']);
check($manual['state']==='PARTIAL_CONFIRMATION' && $manual['results'][0]['reason']==='EXISTING_MANUAL_STATUS','existing manual status is preserved and not falsely reported Present');
check((int)$db->query('SELECT negative FROM attendance WHERE session_id=8')->fetch_row()[0]===1,'review cannot overwrite manual Absent');
echo "All review-confirmation tests passed\n";

resetLifecycle($db);$db->query('UPDATE member SET status=1,park=0');
$ready=new BrowserFaceService($db,$cipher,99,2,['ready'=>true,'DUPLICATE_THRESHOLD'=>.99,'ATTENDANCE_THRESHOLD'=>.99]);
$ready->run('enroll',captureCommand(1,$a,'review_disclose_original'));
$duplicate=$ready->run('enroll',captureCommand(2,$a,'review_disclose_duplicate'));
check($duplicate['duplicate_visible']===true && $duplicate['duplicate_person_name']==='Public A' && ($duplicate['duplicate_branch_name'] ?? '')==='Testing','duplicate child name and branch disclosed');
$otherBranch=new BrowserFaceService($db,$cipher,98,3,['ready'=>true,'DUPLICATE_THRESHOLD'=>.99,'ATTENDANCE_THRESHOLD'=>.99]);
$private=$otherBranch->run('enroll',captureCommand(4,$a,'review_private_duplicate'));
check($private['duplicate_visible']===true && $private['duplicate_person_name']==='Public A' && ($private['duplicate_branch_name'] ?? '')==='Testing','cross-branch duplicate rejected disclosing registered member and branch');
$db->query('UPDATE member SET status=0 WHERE id=1');
$hidden=$ready->run('enroll',captureCommand(2,$a,'review_inactive_duplicate'));
check($hidden['state']==='REJECTED_DUPLICATE' && $hidden['duplicate_visible']===false,'inactive private identity cannot leak via duplicate response or block rejection');
