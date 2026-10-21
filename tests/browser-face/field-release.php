<?php
// Existing connection-scoped temporary schema: never writes production records.
require __DIR__.'/review.php';
$approval=['database'=>'ccc_database_test','branch_id'=>2,'batch_id'=>11,'mode'=>'SHADOW','operator_ids'=>[99],'approved_by'=>'temporary-regression','approved_at'=>gmdate('c'),'expires_at'=>gmdate('c',time()+3600)];
$configuration=['version'=>'browser-face-rollout-v1','default_mode'=>'DISABLED','enabled'=>true,'scopes'=>[$approval]];
$p=BrowserFacePolicy::rollout('ccc_database_test',2,11,['ready'=>false],$configuration,99);
check($p['operating_mode']==='SHADOW','approved field cohort uses guarded shadow');
check(BrowserFacePolicy::rollout('ccc_database_test',2,11,['ready'=>false],$configuration,98)['operating_mode']==='SHADOW','legacy operator allowlist cannot create a second face permission');
foreach([['another_tenant',2,11,99],['ccc_database_test',3,11,99],['ccc_database_test',2,12,99]] as [$tenant,$branch,$batch,$actor])check(BrowserFacePolicy::rollout($tenant,$branch,$batch,['ready'=>false],$configuration,$actor)['operating_mode']==='DISABLED','outside tenant/branch/batch release scope is disabled');
$all=$configuration;$all['default_mode']='SHADOW';$all['scopes']=[];foreach([1,2,4,5] as $branch)check(BrowserFacePolicy::rollout('ccc_database_test',$branch,11,['ready'=>false],$all,123)['operating_mode']==='SHADOW','attendance-authorized branches need no extra face allowlist');
$all['default_mode']='LIVE';check(BrowserFacePolicy::rollout('ccc_database_test',4,11,['ready'=>true],$all,123)['operating_mode']==='DISABLED','global LIVE default cannot bypass scoped validation approval');
$rollback=$configuration;$rollback['enabled']=false;check(BrowserFacePolicy::rollout('ccc_database_test',2,11,[], $rollback,99)['operating_mode']==='DISABLED','global emergency stop enforced');
$expired=$configuration;$expired['scopes'][0]['expires_at']=gmdate('c',time()-1);check(BrowserFacePolicy::rollout('ccc_database_test',2,11,[],$expired,99)['operating_mode']==='DISABLED','expired cohort fails closed');
$live=$configuration;$live['scopes'][0]['mode']='LIVE';check(BrowserFacePolicy::rollout('ccc_database_test',2,11,['ready'=>false],$live,99)['operating_mode']==='DISABLED','field approval cannot enable unvalidated LIVE');
check(BrowserFacePolicy::rollout('ccc_database_test',2,11,[],['version'=>'broken'],99)['operating_mode']==='DISABLED','invalid configuration fails closed');
$live['scopes'][0]['matching_report_sha256']=str_repeat('a',64);
foreach([98,99] as $actor)check(BrowserFacePolicy::rollout('ccc_database_test',2,11,['ready'=>true,'report_sha256'=>str_repeat('a',64)],$live,$actor)['operating_mode']==='LIVE','approved report-bound LIVE has no additional operator allowlist');
check(BrowserFacePolicy::rollout('ccc_database_test',2,11,['ready'=>true,'report_sha256'=>str_repeat('b',64)],$live,98)['operating_mode']==='DISABLED','removing the face allowlist does not bypass report-bound LIVE approval');
$scopedStop=$configuration;$scopedStop['default_mode']='SHADOW';$scopedStop['scopes'][0]['mode']='DISABLED';
check(BrowserFacePolicy::rollout('ccc_database_test',2,11,[],$scopedStop,98)['operating_mode']==='DISABLED','batch emergency stop applies to every Attendance operator');
$prior=getenv('FACE_WEB_ROLLOUT_FILE');putenv('FACE_WEB_ROLLOUT_FILE=/tmp/ccc-field-missing-file');try{check(BrowserFacePolicy::rollout('ccc_database_test',2,11,[])['operating_mode']==='DISABLED','missing configured file does not restore broad access');}finally{putenv($prior===false?'FACE_WEB_ROLLOUT_FILE':'FACE_WEB_ROLLOUT_FILE='.$prior);}
resetLifecycle($db);$db->query('DELETE FROM attendance_event');$db->query('DELETE FROM attendance');$db->query('UPDATE invoice_item SET sessions_used=0');$db->query('UPDATE member SET status=1,park=0');
$guarded=new BrowserFaceService($db,$cipher,99,2,$p+['ready'=>false,'DUPLICATE_THRESHOLD'=>null,'ATTENDANCE_THRESHOLD'=>null]);
$capture=captureCommand(1,$a,'field_guard_capture01')+['evaluation_context'=>['batch_id'=>11,'attendance_date'=>date('Y-m-d'),'session_id'=>7,'selected_time'=>'10:00:00']];
$noConsent=$capture;$noConsent['consent']=false;rejects(fn()=>$guarded->run('enroll',$noConsent),'existing capture consent requirement remains enforced');
check($guarded->run('enroll',$capture)['state']==='PENDING','invoice-authorized field capture remains pending without new consent gate');
$scan=$guarded->run('open',['batch_id'=>11,'attendance_date'=>date('Y-m-d'),'attendance_session_id'=>7,'selected_time'=>'10:00:00','idempotency_key'=>'field_guard_scan001']);
$request=['scan_id'=>$scan['scan_id'],'scan_token'=>$scan['scan_token'],'model_version'=>$contract['model_version'],'preprocessing_version'=>$contract['preprocessing_version'],'observations'=>[$a,$a,$a],'idempotency_key'=>'field_guard_observe1'];
$out=$guarded->run('attendance',$request);check($out['suggestion']['person_id']===1 && (int)$db->query('SELECT COUNT(*) FROM attendance')->fetch_row()[0]===0,'field shadow stages only consenting roster identities without writes');
$disabled=new BrowserFaceService($db,$cipher,99,2,array_replace($p,['operating_mode'=>'DISABLED','capture_available'=>false,'ready'=>false]));
$request['idempotency_key']='field_rollback_block';rejects(fn()=>$disabled->run('attendance',$request),'disabled cohort blocks direct recognition API');
$confirmation=['scan_id'=>$scan['scan_id'],'scan_token'=>$scan['scan_token'],'verified'=>true,'candidates'=>[['person_id'=>1,'receipt_key'=>'field_guard_observe1']],'idempotency_key'=>'field_guard_confirm1'];
check($disabled->run('confirm',$confirmation)['results'][0]['reason']==='SCAN_MODE_CHANGED','rollback blocks new confirmation writes');
check((int)$db->query('SELECT COUNT(*) FROM attendance')->fetch_row()[0]===0,'disabled review cannot deduct/write');
$confirmation['idempotency_key']='field_valid_confirm01';check($guarded->run('confirm',$confirmation)['results'][0]['state']==='RECORDED','approved verified manual confirmation uses domain service');
check($disabled->run('confirm',$confirmation)['results'][0]['state']==='RECORDED','rollback preserves successful confirmation idempotent response');
$db->query("INSERT INTO biometric_consent(member_id,guardian_contact_id,notice_version,purpose,consent_status,verification_method,consented_at,withdrawn_at) VALUES(1,NULL,'browser-face-v1','ATTENDANCE','WITHDRAWN','PROGRAM_PARTICIPATION',NOW(),NOW())");
$request['idempotency_key']='field_withdraw_match1';$out=$guarded->run('attendance',$request);check($out['suggestion']===null && $out['diagnostics']['candidate_count']===0,'withdrawal excludes compatible pending reference from field shadow');
echo "Field release regression complete\n";
$private=tempnam('/tmp','ccc-field-rollback-');file_put_contents($private,json_encode(['version'=>'browser-face-rollout-v1','enabled'=>true,'default_mode'=>'SHADOW','scopes'=>[]]));chmod($private,0600);$prior=getenv('FACE_WEB_ROLLOUT_FILE');putenv('FACE_WEB_ROLLOUT_FILE='.$private);
try{
    adminMain(['admin','disable-field','--database','ccc_database_test','--branch','2','--batch','11','--apply','--authorized-by','temporary-test'],$db);
    check(BrowserFacePolicy::rollout('ccc_database_test',2,11,[])['operating_mode']==='DISABLED','atomic cohort rollback enforces exact batch');
    check(BrowserFacePolicy::rollout('ccc_database_test',2,12,[])['operating_mode']==='SHADOW','cohort rollback preserves other authorized batches');
    rejects(fn()=>adminMain(['admin','disable-field','--database','ccc_database_test','--branch','2'],$db),'CLI disable requires explicit authorization');
    adminMain(['admin','disable-field','--database','ccc_database_test','--branch','2','--apply','--authorized-by','temporary-test'],$db);
    check(BrowserFacePolicy::rollout('ccc_database_test',3,12,[])['operating_mode']==='DISABLED','atomic global rollback blocks new biometric processing');
}finally{putenv($prior===false?'FACE_WEB_ROLLOUT_FILE':'FACE_WEB_ROLLOUT_FILE='.$prior);unlink($private);}

// Exercise the actual controller's existing central Attendance checks. Central
// permissions are read only; serviceFactory stops before any tenant operation.
require_once dirname(__DIR__,2).'/controllers/browserFace.php';
$central=(new dbconnection)->connect('ccc_central');
$allowed=$central->query("SELECT u.id, u.auth_epoch, cd.db_name FROM user u JOIN client_module cm ON cm.client_id=u.client_id JOIN module m ON m.id=cm.module_id JOIN user_permission up ON up.cm_id=cm.id AND up.user_id=u.id JOIN client_db cd ON cd.id=u.client_id WHERE m.module='Attendance' AND m.park=0 AND cm.active=1 AND up.bid=2 AND up.permission>=1 AND u.park=0 AND u.inactive=0 LIMIT 1")->fetch_assoc();
$denied=$central->query("SELECT u.id, u.auth_epoch, cd.db_name FROM user u JOIN client_db cd ON cd.id=u.client_id WHERE NOT EXISTS (SELECT 1 FROM client_module cm JOIN module m ON m.id=cm.module_id JOIN user_permission up ON up.cm_id=cm.id AND up.user_id=u.id WHERE cm.client_id=u.client_id AND m.module='Attendance' AND m.park=0 AND cm.active=1 AND up.bid=2 AND up.permission>=1) AND u.park=0 AND u.inactive=0 LIMIT 1")->fetch_assoc();
if(!$allowed || !$denied)throw new RuntimeException('Read-only central authorization fixtures unavailable');
$central->close();$sessionBefore=$_SESSION ?? [];$serverBefore=$_SERVER;
$envBefore=[getenv('FACE_EMBEDDING_KEY'),getenv('FACE_EMBEDDING_KEY_ID'),getenv('FACE_WEB_ROLLOUT_FILE')];
putenv('FACE_EMBEDDING_KEY=public-authorization-test-only');putenv('FACE_EMBEDDING_KEY_ID=authorization-test-only');putenv('FACE_WEB_ROLLOUT_FILE');
$_SERVER=['HTTPS'=>'on','HTTP_HOST'=>'ccc.example'];
try{
    foreach([$allowed,$denied] as $actorRow){
        unset($GLOBALS['ccc_authenticated_context']);
        $actor=(int)$actorRow['id'];
        $_SESSION=['db_name'=>$actorRow['db_name'],'user_id'=>$actor,'bid'=>2,'auth_epoch'=>(int)$actorRow['auth_epoch']];$reached=false;
        $controller=new browserFace(function($connection,$cipher,$actualActor,$actualBranch,$policy)use(&$reached,$actor,$actorRow){
            check($connection->query('SELECT DATABASE()')->fetch_row()[0]===$actorRow['db_name'] && $actualActor===$actor && $actualBranch===2,'face authorization context comes from the existing authenticated session');
            $reached=true;throw new RuntimeException('AUTHORIZED_TEST_BOUNDARY');
        });
        try{$controller->handle('config',['db_name'=>'another_tenant','branch_id'=>4,'user_id'=>999999],'GET');throw new RuntimeException('Expected controller test boundary');}
        catch(InvalidArgumentException $error){check($actor===(int)$denied['id'] && $error->getMessage()==='ATTENDANCE_ACCESS' && !$reached,'operator without existing Attendance permission is denied before face services');}
        catch(RuntimeException $error){check($actor===(int)$allowed['id'] && $error->getMessage()==='AUTHORIZED_TEST_BOUNDARY' && $reached,'existing Attendance permission alone grants face service access');}
    }
    unset($GLOBALS['ccc_authenticated_context']);
    $_SESSION=['db_name'=>$allowed['db_name'],'user_id'=>(int)$allowed['id'],'bid'=>2147483647,'auth_epoch'=>(int)$allowed['auth_epoch']];$reached=false;
    $controller=new browserFace(function()use(&$reached){$reached=true;throw new RuntimeException('Unexpected authorization');});
    try{$controller->handle('config',['branch_id'=>2],'GET');throw new RuntimeException('Unexpected branch access');}
    catch(InvalidArgumentException $error){check($error->getMessage()==='ATTENDANCE_ACCESS' && !$reached,'Attendance permission in one branch cannot authorize another branch');}
}finally{
    $_SESSION=$sessionBefore;$_SERVER=$serverBefore;
    foreach(['FACE_EMBEDDING_KEY','FACE_EMBEDDING_KEY_ID','FACE_WEB_ROLLOUT_FILE'] as $i=>$name)putenv($envBefore[$i]===false?$name:$name.'='.$envBefore[$i]);
}
