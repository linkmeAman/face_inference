<?php
// Actual pilot schema/domain tests; synthetic vectors never enter the live pilot.
if(PHP_SAPI!=='cli'){http_response_code(404);exit;}
require_once dirname(__DIR__,2).'/scripts/browser_face_pilot.php';
mysqli_report(MYSQLI_REPORT_ERROR|MYSQLI_REPORT_STRICT);
$name='ccc_database_face_pilot_aabb1026';
$config=['source_database'=>'ccc_database','database'=>$name,'branch_id'=>2,'batch_id'=>33,'member_ids'=>[623,624,625],'contact_ids'=>[791,792,793],'item_ids'=>[1188,1189,1190],'owner_token'=>bin2hex(random_bytes(32)),'expires_at'=>gmdate('c',time()+3600)];
$db=new mysqli('localhost','root','');$source=(new dbconnection)->connect('ccc_database');
function pilotCheck(bool $yes,string $name):void{if(!$yes)throw new RuntimeException($name);echo 'PASS '.$name."\n";}
function pilotReject(callable $fn,string $name):void{try{$fn();}catch(Throwable $e){pilotCheck(true,$name);return;}throw new RuntimeException('Expected rejection: '.$name);}
function sourceFingerprint(mysqli $db):string{return hash('sha256',json_encode([$db->query('SELECT COUNT(*),MAX(id) FROM attendance')->fetch_row(),$db->query('SELECT COUNT(*),MAX(id) FROM attendance_event')->fetch_row(),$db->query('SELECT id,sessions_used FROM invoice_item WHERE id IN(1188,1189,1190) ORDER BY id')->fetch_all(MYSQLI_ASSOC),$db->query("SELECT id,status FROM face_template ORDER BY id")->fetch_all(MYSQLI_ASSOC)]));}
$before=sourceFingerprint($source);$created=false;
try{
    provisionFacePilot($db,$config);$created=true;BrowserFacePilot::verify($db,$config);pilotCheck(true,'actual source schema cloned only for allowlisted zero-value test records');
    pilotCheck(!BrowserFacePilot::policy($config)['ready'],'isolated pilot without evidence cannot match');
    pilotReject(fn()=>BrowserFacePilot::verify($source,$config),'production connection cannot become pilot');
    $bad=$config;$bad['branch_id']=3;pilotReject(fn()=>BrowserFacePilot::scope($bad,'ccc_database',3),'other branch cannot use pilot');
    pilotReject(fn()=>BrowserFacePilot::request($config,'enroll',['person_id'=>616]),'production identity cannot enroll in pilot');
    pilotReject(fn()=>BrowserFacePilot::request($config,'open',['batch_id'=>1]),'other batch cannot open in pilot');
    $db->query("CREATE OR REPLACE VIEW invoice_invoiceitem_view AS SELECT * FROM ccc_database.invoice_invoiceitem_view");pilotReject(fn()=>BrowserFacePilot::verify($db,$config),'cross-database membership view rejected');
    $seed='invoice_invoiceitem_view_seed';$columns=array_column($db->query("SHOW COLUMNS FROM `$seed`")->fetch_all(MYSQLI_ASSOC),'Field');$fields=array_map(fn($column)=>in_array($column,['sessions_used','sessions'],true)?"i.`$column` AS `$column`":"s.`$column`",$columns);
    $db->query("CREATE OR REPLACE SQL SECURITY INVOKER VIEW invoice_invoiceitem_view AS SELECT ".implode(',',$fields)." FROM `$name`.`$seed` s JOIN `$name`.invoice_item i ON i.id=s.item_id");
    BrowserFacePilot::verify($db,$config);
    $db->query('UPDATE invoice_item SET sessions=2 WHERE id=1188'); // Disposable pilot only; verify finite deductions too.
    $cipher=new FaceEmbeddingCipher('synthetic-pilot-regression-only','fixture-only');$policy=['ready'=>true,'DUPLICATE_THRESHOLD'=>.99,'ATTENDANCE_THRESHOLD'=>.99];$service=new BrowserFaceService($db,$cipher,99,2,$policy);$contract=BrowserFacePolicy::contract();$v=array_fill(0,128,0.0);$v[0]=1.0;$w=$v;$w[0]=0.;$w[1]=1.;
    $capture=fn($id,$vec,$key)=>['person_id'=>$id,'template'=>$vec,'observations'=>array_fill(0,5,$vec),'model_version'=>$contract['model_version'],'preprocessing_version'=>$contract['preprocessing_version'],'idempotency_key'=>$key,'consent'=>true,'capture_session'=>$key,'device'=>'camera:synthetic-test-only','lighting'=>'measured-normal'];
    pilotCheck($service->run('enroll',$capture(623,$v,'pilot_regression_enroll01'))['state']==='ACTIVE','persistent pilot lifecycle activates compatible unique template');
    $reference=$db->query("SELECT ft.id AS template_id,e.id AS enrollment_id,e.calibration_id,e.capture_session,SHA2(ft.encrypted_embedding,256) AS template_sha256,SHA2(e.encrypted_template,256) AS enrollment_sha256 FROM face_template ft JOIN face_web_enrollment e ON e.active_template_id=ft.id WHERE ft.member_id=623 AND ft.status='ACTIVE'")->fetch_assoc();
    foreach(['template_id','enrollment_id','calibration_id'] as $field)$reference[$field]=(int)$reference[$field];$reference['member_id']=623;
    $report=['source'=>'consented-live-cameras','model_version'=>$contract['model_version'],'preprocessing_version'=>$contract['preprocessing_version'],'dataset_databases'=>[$name],'dataset_branches'=>[2],'identity_ids'=>[623],'provisional_pilot'=>['ready'=>true,'production_approved'=>false,'mode'=>'SINGLE_IDENTITY_LIVE_DEMO','functional_demo_only'=>true,'approved_by'=>'authorized regression operator','expires_at'=>$config['expires_at'],'identities'=>1,'genuine_trials'=>1,'impostor_trials'=>0,'DUPLICATE_THRESHOLD'=>.99,'ATTENDANCE_THRESHOLD'=>.99,'reference'=>$reference]];
    $reportFile=tempnam('/tmp','ccc-single-pilot-test-');chmod($reportFile,0600);file_put_contents($reportFile,json_encode($report));$demo=$config+['report_path'=>$reportFile,'report_sha256'=>hash_file('sha256',$reportFile)];
    $demoPolicy=BrowserFacePilot::policy($demo);pilotCheck($demoPolicy['ready'] && $demoPolicy['mode']==='SINGLE_IDENTITY_LIVE_DEMO','explicit one-person demo unblocks only isolated matching');
    BrowserFacePilot::verify($db,$demo);pilotCheck(true,'demo validates existing original reference and exact active population');
    $tampered=$demo;$tampered['report_sha256']=str_repeat('0',64);pilotCheck(!BrowserFacePilot::policy($tampered)['ready'],'tampered demo report fails closed');
    $originalReport=$report;
    foreach(['production','expiry','model','identities'] as $case){$report=$originalReport;
        if($case==='production')$report['provisional_pilot']['production_approved']=true;
        if($case==='expiry')$report['provisional_pilot']['expires_at']=gmdate('c',time()-1);
        if($case==='model')$report['preprocessing_version']='incompatible';
        if($case==='identities')$report['identity_ids']=[623,624];
        file_put_contents($reportFile,json_encode($report));$bad=$demo;$bad['report_sha256']=hash_file('sha256',$reportFile);pilotCheck(!BrowserFacePilot::policy($bad)['ready'],$case.' cannot enable demo');
    }
    file_put_contents($reportFile,json_encode($originalReport));
    $db->query("UPDATE face_web_enrollment SET capture_session='tampered-reference-session' WHERE member_id=623");pilotReject(fn()=>BrowserFacePilot::verify($db,$demo),'reference association tampering stops demo');
    $s=$db->prepare('UPDATE face_web_enrollment SET capture_session=? WHERE member_id=623');$s->bind_param('s',$reference['capture_session']);$s->execute();
    $demoService=new BrowserFaceService($db,$cipher,99,2,$demoPolicy);
    pilotCheck($demoService->run('enroll',$capture(624,$v,'pilot_demo_duplicate_001'))['state']==='REJECTED_DUPLICATE','one-person demo retains genuine duplicate rejection');
    pilotCheck($demoService->run('enroll',$capture(625,$w,'pilot_demo_unapproved_01'))['state']==='PENDING','unapproved distinct candidate cannot activate in demo');
    BrowserFacePilot::verify($db,$demo);pilotCheck((int)$db->query("SELECT COUNT(*) FROM face_template WHERE status='ACTIVE'")->fetch_row()[0]===1,'demo capture cannot expand active identity population');
    $demoService->run('activate_pending',['idempotency_key'=>'pilot_demo_activation_01']);pilotCheck($db->query('SELECT state FROM face_web_enrollment WHERE member_id=625')->fetch_row()[0]==='PENDING','automatic activation also preserves demo restriction');
    $demoRoster=$demoService->roster(['batch_id'=>33,'attendance_date'=>date('Y-m-d')]);$demoScheduled=$demoRoster['sessions'][0];
    $demoScan=$demoService->run('open',['batch_id'=>33,'attendance_date'=>date('Y-m-d'),'attendance_session_id'=>(int)$demoScheduled['id'],'selected_time'=>$demoScheduled['start_time'],'idempotency_key'=>'pilot_demo_open_unknown']);
    $unknown=$demoService->run('attendance',['scan_id'=>$demoScan['scan_id'],'scan_token'=>$demoScan['scan_token'],'observations'=>array_fill(0,5,$w),'model_version'=>$contract['model_version'],'preprocessing_version'=>$contract['preprocessing_version'],'idempotency_key'=>'pilot_demo_unknown_0001','capture_diagnostics'=>['camera_width'=>640,'frame_count'=>5,'inference_ms'=>42,'brightness'=>-1,'embedding'=>$v,'scan_token'=>'private-token']]);
    pilotCheck($unknown['state']==='UNKNOWN' && $unknown['diagnostics']['decision']==='BELOW_CUTOFF' && (int)$db->query('SELECT COUNT(*) FROM attendance')->fetch_row()[0]===0,'below-cutoff demo observation never writes attendance');
    pilotCheck($unknown['diagnostics']['camera_reported']===['camera_width'=>640,'frame_count'=>5,'inference_ms'=>42] && !isset($unknown['diagnostics']['embedding']),'journal camera diagnostics discard biometric fields and invalid scalars');
    $demoService->run('close',['scan_id'=>$demoScan['scan_id'],'scan_token'=>$demoScan['scan_token'],'idempotency_key'=>'pilot_demo_close_000001']);
    $diag=null;pilotCheck(BrowserFaceService::matchObservations(array_fill(0,5,$v),[['member_id'=>623,'vector'=>$v]],.99,$diag)===623 && $diag['decision']==='MATCHED','diagnostics preserve genuine cosine ranking');
    pilotCheck(BrowserFaceService::matchObservations(array_fill(0,5,$v),[['member_id'=>623,'vector'=>$v],['member_id'=>624,'vector'=>$v]],.99,$diag)===null && $diag['decision']==='AMBIGUOUS','ambiguous candidates remain unmarked with exact reason');
    pilotCheck(BrowserFaceService::matchObservations([$v,$w,$v], [['member_id'=>623,'vector'=>$v],['member_id'=>624,'vector'=>$w]],.99,$diag)===null && $diag['decision']==='CONFLICTING_OBSERVATIONS','conflicting observations remain unmarked with exact reason');
    // End this temporary policy exercise; expired fixture evidence must not
    // conflict with the separate ordinary multi-identity policy tests below.
    $db->query('UPDATE face_web_calibration SET deleted_at=NOW() WHERE member_id=625');
    pilotCheck($service->run('enroll',$capture(624,$v,'pilot_regression_duplicate'))['state']==='REJECTED_DUPLICATE','pilot duplicate cannot create second active identity');
    pilotCheck($service->run('enroll',$capture(624,$w,'pilot_regression_distinct'))['state']==='ACTIVE','distinct pilot identity enrolls');
    pilotReject(fn()=>BrowserFacePilot::verify($db,$demo),'single-identity authorization rejects an expanded active population');
    unlink($reportFile);
    $roster=$service->roster(['batch_id'=>33,'attendance_date'=>date('Y-m-d')]);$session=$roster['sessions'][0];
    $scan=$service->run('open',['batch_id'=>33,'attendance_date'=>date('Y-m-d'),'attendance_session_id'=>(int)$session['id'],'selected_time'=>$session['start_time'],'idempotency_key'=>'pilot_regression_open001']);
    $attendance=['scan_id'=>$scan['scan_id'],'scan_token'=>$scan['scan_token'],'observations'=>array_fill(0,5,$v),'model_version'=>$contract['model_version'],'preprocessing_version'=>$contract['preprocessing_version'],'idempotency_key'=>'pilot_regression_arrival1'];
    $one=$service->run('attendance',$attendance);pilotCheck($one['state']==='RECORDED' && $one['contact_id']===791,'actual automatic AttendanceDomainService writes pilot member/session/event');
    pilotCheck(!isset($one['diagnostics']),'ordinary production-style policy does not expose pilot diagnostics');
    $attendance['idempotency_key']='pilot_regression_repeat01';$two=$service->run('attendance',$attendance);pilotCheck($two['state']==='ALREADY_MARKED','pilot repeat arrival cannot deduct twice');
    pilotCheck((int)$db->query('SELECT COUNT(*) FROM attendance')->fetch_row()[0]===1 && (int)$db->query('SELECT COUNT(*) FROM attendance_event')->fetch_row()[0]===1,'pilot domain attendance/event uniqueness');
    pilotCheck((int)$db->query('SELECT sessions_used FROM invoice_item WHERE id=1188')->fetch_row()[0]===1 && (int)$db->query('SELECT sessions_used FROM invoice_invoiceitem_view WHERE item_id=1188')->fetch_row()[0]===1,'pilot membership views reflect only local deductions');
    BrowserFacePilot::verify($db,$config);pilotCheck(hash_equals($before,sourceFingerprint($source)),'source tenant attendance/events/memberships/templates unchanged');
}finally{
    if(isset($reportFile) && is_file($reportFile))unlink($reportFile);
    if($created){$db->select_db($name);BrowserFacePilot::verify($db,$config);$db->select_db('ccc_database');$db->query("DROP DATABASE `$name`");}
}
